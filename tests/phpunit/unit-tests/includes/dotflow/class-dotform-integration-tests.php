<?php

use Groundhogg\Contact;
use Groundhogg\Preferences;
use Groundhogg\DotFlow\Events\Store;
use Groundhogg\DotFlow\Integrations\DotForm_Integration;
use function Groundhogg\get_db;

class DotFlow_DotForm_Integration_Tests extends GH_UnitTestCase {

	protected $contact_ids = [];
	protected $event_ids = [];

	public function tearDown(): void {
		global $wpdb;
		$db = get_db( 'dotflow_events' );
		if ( $db ) {
			foreach ( $this->event_ids as $event_id ) {
				$wpdb->delete( $db->get_table_name(), [ 'event_id' => $event_id ], [ '%s' ] );
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

	public function test_dotform_submission_emits_identifier_only_events_and_respects_marketing_eligibility() {
		global $wpdb;
		Store::maybe_install();
		$contact_id = self::factory()->contacts->create( [ 'optin_status' => Preferences::CONFIRMED ] );
		$this->contact_ids[] = $contact_id;
		$contact = new Contact( $contact_id );
		$event_id = 'dotform-test-' . wp_generate_uuid4();
		$this->event_ids = [ $event_id, 'dotform-marketing-' . substr( hash( 'sha256', $event_id ), 0, 48 ) ];

		DotForm_Integration::record_submission( $contact_id, 22, 33, $event_id );

		$db = get_db( 'dotflow_events' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_name, payload FROM {$db->get_table_name()} WHERE source = %s AND event_id IN (%s,%s)",
			'dotform',
			$event_id,
			'marketing-' . $event_id
		), ARRAY_A );

		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$payload = json_decode( $row['payload'], true );
			$this->assertSame( $contact_id, $payload['contact_id'] );
			$this->assertSame( 22, $payload['form_id'] );
			$this->assertSame( 33, $payload['object_id'] );
			$this->assertArrayNotHasKey( 'email', $payload );
			$this->assertArrayNotHasKey( 'fields', $payload );
		}
		$this->assertTrue( $contact->exists() );
	}

	public function test_dotform_bridge_ignores_unknown_contacts_and_invalid_identifiers() {
		Store::maybe_install();
		DotForm_Integration::record_submission( 999999999, 22, 33, 'missing-contact-test' );
		DotForm_Integration::record_submission( 0, 22, 33, 'invalid-contact-test' );
		global $wpdb;
		$db = get_db( 'dotflow_events' );
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$db->get_table_name()} WHERE source = %s AND event_id IN (%s,%s)",
			'dotform',
			'missing-contact-test',
			'invalid-contact-test'
		) );
		$this->assertSame( 0, absint( $count ) );
	}
}
