<?php

use Groundhogg\Contact;
use Groundhogg\DotFlow\Events\CRM_Events;
use Groundhogg\DotFlow\Events\Store;
use function Groundhogg\get_db;
use function Groundhogg\parse_tag_list;

/**
 * Groundhogg CRM lifecycle event bridge coverage.
 */
class DotFlow_CRM_Event_Tests extends GH_UnitTestCase {

	protected $contact_ids = [];
	protected $event_ids   = [];

	public function setUp(): void {
		parent::setUp();
		Store::maybe_install();
		CRM_Events::register();
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

	public function test_contact_creation_emits_a_stable_contact_created_event() {
		global $wpdb;

		$contact_id = self::factory()->contacts->create();
		$this->contact_ids[] = $contact_id;
		$event_id = 'contact-created-' . $contact_id;
		$this->event_ids[] = $event_id;

		$db  = get_db( 'dotflow_events' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT event_name, contact_id, payload FROM {$db->get_table_name()} WHERE source = %s AND event_id = %s",
				'groundhogg',
				$event_id
			),
			ARRAY_A
		);

		$this->assertIsArray( $row );
		$this->assertSame( 'contact.created', $row['event_name'] );
		$this->assertSame( $contact_id, absint( $row['contact_id'] ) );
		$this->assertSame(
			[ 'contact_id', 'object_type', 'object_id' ],
			array_keys( json_decode( $row['payload'], true ) )
		);
	}

	public function test_unsubscribe_preference_change_emits_a_contact_event() {
		global $wpdb;

		$contact_id = self::factory()->contacts->create();
		$contact    = new Contact( $contact_id );
		$this->contact_ids[] = $contact_id;

		$contact->update( [ 'optin_status' => \Groundhogg\Preferences::UNSUBSCRIBED ] );

		$db  = get_db( 'dotflow_events' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT event_id, event_name, payload FROM {$db->get_table_name()} WHERE source = %s AND contact_id = %d AND event_name = %s ORDER BY ID DESC LIMIT 1",
				'groundhogg',
				$contact_id,
				'contact.unsubscribed'
			),
			ARRAY_A
		);

		$this->assertIsArray( $row );
		$this->event_ids[] = $row['event_id'];
		$this->assertSame( [ 'contact_id', 'object_type', 'object_id' ], array_keys( json_decode( $row['payload'], true ) ) );
	}

	public function test_tag_applied_and_removed_events_include_only_relationship_identifiers() {
		global $wpdb;

		$contact_id = self::factory()->contacts->create();
		$contact    = new Contact( $contact_id );
		$this->contact_ids[] = $contact_id;

		$tag_id = absint( parse_tag_list( [ 'dotflow-event-test-' . wp_generate_uuid4() ], 'ID', true )[0] );
		$contact->add_tag( $tag_id );
		$contact->remove_tag( $tag_id );

		$db = get_db( 'dotflow_events' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_id, event_name, payload FROM {$db->get_table_name()} WHERE source = %s AND contact_id = %d AND event_name IN (%s, %s)",
				'groundhogg',
				$contact_id,
				'tag.applied',
				'tag.removed'
			),
			ARRAY_A
		);

		$this->assertSame( [ 'tag.applied', 'tag.removed' ], wp_list_pluck( $rows, 'event_name' ) );

		foreach ( $rows as $row ) {
			$this->event_ids[] = $row['event_id'];
			$payload = json_decode( $row['payload'], true );
			$this->assertSame( $contact_id, absint( $payload['contact_id'] ) );
			$this->assertSame( $tag_id, absint( $payload['tag_id'] ) );
			$this->assertSame( [ 'contact_id', 'object_type', 'object_id', 'tag_id' ], array_keys( $payload ) );
		}
	}
}
