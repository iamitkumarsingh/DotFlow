<?php

use Groundhogg\DotFlow\Events\Store;
use Groundhogg\DotFlow\Messaging\Outbound_Delivery_Events;
use function Groundhogg\get_db;

class DotFlow_Outbound_Delivery_Events_Tests extends GH_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Store::maybe_install();
		Outbound_Delivery_Events::register();
	}

	protected function event_for( $message_id ) {
		global $wpdb;
		$db = get_db( 'dotflow_events' );
		$fingerprint = hash( 'sha256', $message_id );

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT ID, event_name, payload FROM {$db->get_table_name()} WHERE source = %s AND JSON_EXTRACT(payload, '$.message_fingerprint') = %s ORDER BY ID DESC LIMIT 1",
			'email-transport',
			$fingerprint
		) );
	}

	public function test_transport_success_records_acceptance_without_message_content_or_addresses() {

		$message_id = '<dotflow-transport-success@example.test>';
		$callback = static function () use ( $message_id ) {
			Groundhogg_Email_Services::set_message_id( $message_id );
			return true;
		};
		Groundhogg_Email_Services::register( 'dotflow_transport_success', 'DotFlow Test Transport', $callback );

		try {
			$result = Groundhogg_Email_Services::send_with_service(
				'dotflow_transport_success',
				[ 'private-recipient@example.test' ],
				'Private subject',
				'Private body'
			);

			$this->assertTrue( $result );
			$row = $this->event_for( $message_id );
			$this->assertNotEmpty( $row );
			$this->assertSame( 'email.transport.accepted', $row->event_name );
			$payload = json_decode( $row->payload, true );
			$this->assertSame( 'dotflow_transport_success', $payload['provider'] );
			$this->assertSame( 'wordpress', $payload['channel'] );
			$this->assertSame( 'accepted_by_transport', $payload['outcome'] );
			$this->assertArrayNotHasKey( 'content', $payload );
			$this->assertArrayNotHasKey( 'subject', $payload );
			$this->assertArrayNotHasKey( 'to', $payload );
			$this->assertStringNotContainsString( 'private-recipient@example.test', $row->payload );
			$this->assertStringNotContainsString( 'Private body', $row->payload );
		} finally {
			Groundhogg_Email_Services::register( 'dotflow_transport_success', 'DotFlow Test Transport', '__return_true' );
		}
	}

	public function test_transport_rejection_records_failure_code() {

		$message_id = '<dotflow-transport-failed@example.test>';
		$callback = static function () use ( $message_id ) {
			Groundhogg_Email_Services::set_message_id( $message_id );
			return new WP_Error( 'provider_rate_limited', 'Sensitive provider response must not be stored.' );
		};
		Groundhogg_Email_Services::register( 'dotflow_transport_failure', 'DotFlow Test Failure', $callback );

		try {
			Groundhogg_Email_Services::send_with_service(
				'dotflow_transport_failure',
				[ 'recipient@example.test' ],
				'Subject',
				'Body'
			);

			$row = $this->event_for( $message_id );
			$this->assertNotEmpty( $row );
			$this->assertSame( 'email.transport.failed', $row->event_name );
			$payload = json_decode( $row->payload, true );
			$this->assertSame( 'provider_rate_limited', $payload['error_code'] );
			$this->assertStringNotContainsString( 'Sensitive provider response', $row->payload );
			$this->assertStringNotContainsString( 'recipient@example.test', $row->payload );
		} finally {
			Groundhogg_Email_Services::register( 'dotflow_transport_failure', 'DotFlow Test Failure', '__return_true' );
		}
	}

	public function test_provider_exception_is_observed_but_still_propagated() {

		$message_id = '<dotflow-transport-exception@example.test>';
		$callback = static function () use ( $message_id ) {
			Groundhogg_Email_Services::set_message_id( $message_id );
			throw new RuntimeException( 'provider secret and recipient@example.test' );
		};
		Groundhogg_Email_Services::register( 'dotflow_transport_exception', 'DotFlow Test Exception', $callback );

		try {
			Groundhogg_Email_Services::send_with_service(
				'dotflow_transport_exception',
				[ 'recipient@example.test' ],
				'Subject',
				'Body'
			);
			$this->fail( 'The original provider exception must still propagate.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'provider secret and recipient@example.test', $exception->getMessage() );
		} finally {
			Groundhogg_Email_Services::register( 'dotflow_transport_exception', 'DotFlow Test Exception', '__return_true' );
		}

		$row = $this->event_for( $message_id );
		$this->assertNotEmpty( $row );
		$this->assertSame( 'email.transport.failed', $row->event_name );
		$payload = json_decode( $row->payload, true );
		$this->assertSame( 'provider_exception', $payload['error_code'] );
		$this->assertStringNotContainsString( 'recipient@example.test', $row->payload );
		$this->assertStringNotContainsString( 'provider secret', $row->payload );
	}
}
