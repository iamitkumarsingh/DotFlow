<?php

use Groundhogg\Contact;
use Groundhogg\DotFlow\Events\Form_Submission;
use Groundhogg\DotFlow\Events\Store;
use Groundhogg\Preferences;
use Groundhogg\Submission;
use function Groundhogg\get_db;

/**
 * Consent-aware CRM form event integration coverage.
 */
class DotFlow_Form_Submission_Event_Tests extends GH_UnitTestCase {

	protected $contact_ids = [];
	protected $submission_ids = [];
	protected $event_ids = [];

	public function setUp(): void {
		parent::setUp();
		Store::maybe_install();
		Form_Submission::register();
	}

	public function tearDown(): void {
		global $wpdb;

		$events = get_db( 'dotflow_events' );
		if ( $events ) {
			foreach ( $this->event_ids as $event_id ) {
				$wpdb->delete( $events->get_table_name(), [ 'event_id' => $event_id ], [ '%s' ] );
			}
		}

		$submissions = get_db( 'submissions' );
		if ( $submissions ) {
			foreach ( $this->submission_ids as $submission_id ) {
				$submissions->delete( $submission_id );
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

	protected function make_submission( $optin_status ) {
		$contact_id = self::factory()->contacts->create( [ 'optin_status' => $optin_status ] );
		$contact    = new Contact( $contact_id );
		$submission = new Submission( [
			'step_id'    => 77,
			'contact_id' => $contact_id,
		] );

		$this->contact_ids[]    = $contact_id;
		$this->submission_ids[] = $submission->get_id();
		$this->event_ids[]      = 'form-submission-' . $submission->get_id();
		$this->event_ids[]      = 'form-marketing-eligible-' . $submission->get_id();

		return [ $submission, $contact ];
	}

	public function test_successful_submission_emits_minimal_form_and_marketing_eligible_events() {
		global $wpdb;

		Store::maybe_install();
		[ $submission, $contact ] = $this->make_submission( Preferences::CONFIRMED );

		$this->assertTrue( $submission->exists() );
		$this->assertTrue( $contact->is_marketable() );

		do_action( 'groundhogg/form/submission_handler/after', $submission, $contact, null );

		$db = get_db( 'dotflow_events' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_name, payload FROM {$db->get_table_name()} WHERE source = %s AND event_id IN (%s, %s)",
				'groundhogg',
				'form-submission-' . $submission->get_id(),
				'form-marketing-eligible-' . $submission->get_id()
			),
			ARRAY_A
		);

		$this->assertCount( 2, $rows );
		$events = [];
		foreach ( $rows as $row ) {
			$events[ $row['event_name'] ] = json_decode( $row['payload'], true );
		}

		$this->assertArrayHasKey( 'form.submitted', $events );
		$this->assertArrayHasKey( 'form.marketing_eligible', $events );
		$this->assertSame( $contact->get_id(), $events['form.submitted']['contact_id'] );
		$this->assertSame( absint( $submission->get_id() ), absint( $events['form.submitted']['object_id'] ) );
		$this->assertSame( 77, $events['form.submitted']['form_id'] );
		$this->assertSame( [ 'contact_id', 'object_type', 'object_id', 'form_id' ], array_keys( $events['form.submitted'] ) );
	}

	public function test_marketing_consent_grant_and_revocation_emit_contact_events() {
		global $wpdb;

		Store::maybe_install();

		$contact_id = self::factory()->contacts->create();
		$contact    = new Contact( $contact_id );
		$this->contact_ids[] = $contact_id;

		$contact->set_marketing_consent();
		$contact->revoke_gdpr_consent( 'marketing' );

		$db = get_db( 'dotflow_events' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_name, payload FROM {$db->get_table_name()} WHERE source = %s AND contact_id = %d AND event_name IN (%s, %s)",
				'groundhogg',
				$contact_id,
				'contact.marketing_consent.granted',
				'contact.marketing_consent.revoked'
			),
			ARRAY_A
		);

		$this->assertSame(
			[ 'contact.marketing_consent.granted', 'contact.marketing_consent.revoked' ],
			wp_list_pluck( $rows, 'event_name' )
		);

		foreach ( $rows as $row ) {
			$payload = json_decode( $row['payload'], true );
			$this->assertSame( $contact_id, $payload['contact_id'] );
			$this->assertSame( [ 'contact_id', 'object_type', 'object_id' ], array_keys( $payload ) );
		}

		$event_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT event_id FROM {$db->get_table_name()} WHERE source = %s AND contact_id = %d AND event_name IN (%s, %s)",
				'groundhogg',
				$contact_id,
				'contact.marketing_consent.granted',
				'contact.marketing_consent.revoked'
			)
		);
		$this->event_ids = array_merge( $this->event_ids, $event_rows );
	}

	public function test_unsubscribed_contact_gets_service_event_but_not_marketing_trigger() {
		global $wpdb;

		Store::maybe_install();
		[ $submission, $contact ] = $this->make_submission( Preferences::UNSUBSCRIBED );

		$this->assertFalse( $contact->is_marketable() );

		Form_Submission::record_submission( $submission, $contact );

		$db = get_db( 'dotflow_events' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_name FROM {$db->get_table_name()} WHERE source = %s AND event_id IN (%s, %s)",
				'groundhogg',
				'form-submission-' . $submission->get_id(),
				'form-marketing-eligible-' . $submission->get_id()
			),
			ARRAY_A
		);

		$this->assertSame( [ 'form.submitted' ], wp_list_pluck( $rows, 'event_name' ) );
	}
}
