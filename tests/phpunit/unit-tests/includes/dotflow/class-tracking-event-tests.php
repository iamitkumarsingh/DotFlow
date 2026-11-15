<?php

use Groundhogg\Contact;
use Groundhogg\DotFlow\Events\Store;
use function Groundhogg\get_db;
use function Groundhogg\track_page_visit;

/**
 * Native Groundhogg page, email-link and CTA tracking event coverage.
 */
class DotFlow_Tracking_Event_Tests extends GH_UnitTestCase {

	protected $contact_ids = [];
	protected $event_ids   = [];

	public function setUp(): void {
		parent::setUp();
		Store::maybe_install();
		\Groundhogg\DotFlow\Events\Tracking_Events::register();
	}

	public function tearDown(): void {
		global $wpdb;

		$events = get_db( 'dotflow_events' );
		if ( $events ) {
			foreach ( $this->event_ids as $event_id ) {
				$wpdb->delete( $events->get_table_name(), [ 'event_id' => $event_id ], [ '%s' ] );
			}
		}

		foreach ( $this->contact_ids as $contact_id ) {
			$contact = new Contact( $contact_id );
			if ( $contact->exists() ) {
				$contact->delete();
			}
		}

		parent::tearDown();
	}

	protected function contact(): Contact {
		$id = self::factory()->contacts->create();
		$this->contact_ids[] = $id;

		return new Contact( $id );
	}

	public function test_native_page_visit_emits_minimal_page_view_event() {
		global $wpdb;

		$contact = $this->contact();
		$visit   = track_page_visit( home_url( '/pricing/?email=private@example.com#plans' ), $contact );

		$this->assertNotFalse( $visit );
		$event_id = 'page-visit-' . $visit->get_id();
		$this->event_ids[] = $event_id;

		$db  = get_db( 'dotflow_events' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT event_name, contact_id, object_type, object_id, payload FROM {$db->get_table_name()} WHERE source = %s AND event_id = %s",
				'groundhogg',
				$event_id
			),
			ARRAY_A
		);

		$this->assertIsArray( $row );
		$this->assertSame( 'page.viewed', $row['event_name'] );
		$this->assertSame( $contact->get_id(), absint( $row['contact_id'] ) );
		$payload = json_decode( $row['payload'], true );
		$this->assertSame( '/pricing/', $payload['page_path'] );
		$this->assertStringNotContainsString( 'private@example.com', $row['payload'] );
	}

	public function test_cta_click_hook_records_safe_metadata_and_stable_idempotency() {
		global $wpdb;

		$contact  = $this->contact();
		$event_id = 'cta-test-' . wp_generate_uuid4();
		$this->event_ids[] = $event_id;

		do_action( 'dotflow/track/cta_click', $contact, 'header_book_appointment', home_url( '/book/?email=private@example.com' ), $event_id );
		do_action( 'dotflow/track/cta_click', $contact, 'header_book_appointment', home_url( '/book/?email=private@example.com' ), $event_id );

		$db = get_db( 'dotflow_events' );
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$db->get_table_name()} WHERE source = %s AND event_id = %s",
				'groundhogg',
				$event_id
			)
		);

		$this->assertSame( 1, absint( $count ) );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT event_name, payload FROM {$db->get_table_name()} WHERE source = %s AND event_id = %s",
				'groundhogg',
				$event_id
			),
			ARRAY_A
		);
		$this->assertSame( 'cta.clicked', $row['event_name'] );
		$payload = json_decode( $row['payload'], true );
		$this->assertSame( 'header_book_appointment', $payload['element_id'] );
		$this->assertSame( '/book/', $payload['page_path'] );
		$this->assertStringNotContainsString( 'private@example.com', $row['payload'] );
	}
}
