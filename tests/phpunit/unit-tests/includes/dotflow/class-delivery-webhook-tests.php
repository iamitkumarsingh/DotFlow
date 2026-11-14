<?php

use Groundhogg\DotFlow\Events\Store;
use Groundhogg\DotFlow\Messaging\Delivery_Webhook;
use Groundhogg\DotFlow\Messaging\Delivery_Webhook_Adapter;
use Groundhogg\DotFlow\Messaging\Provider_Adapter_Registry;
use function Groundhogg\get_db;

class DotFlow_Test_Delivery_Webhook_Adapter implements Delivery_Webhook_Adapter {

	public function get_id(): string {
		return 'dotflow_test';
	}

	public function authorize( WP_REST_Request $request ) {
		return 'valid-test-signature' === $request->get_header( 'x-dotflow-test-signature' );
	}

	public function normalize( array $payload ) {
		return $payload;
	}
}

class DotFlow_Delivery_Webhook_Tests extends GH_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Store::maybe_install();

		if ( ! Provider_Adapter_Registry::get( 'dotflow_test' ) ) {
			Provider_Adapter_Registry::register( new DotFlow_Test_Delivery_Webhook_Adapter() );
		}

		$this->https_value       = $_SERVER['HTTPS'] ?? null;
		$this->server_port_value = $_SERVER['SERVER_PORT'] ?? null;
		$_SERVER['HTTPS']        = 'on';
		$_SERVER['SERVER_PORT']  = '443';
	}

	public function tearDown(): void {
		if ( null === $this->https_value ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $this->https_value;
		}
		if ( null === $this->server_port_value ) {
			unset( $_SERVER['SERVER_PORT'] );
		} else {
			$_SERVER['SERVER_PORT'] = $this->server_port_value;
		}
		parent::tearDown();
	}

	protected $https_value;
	protected $server_port_value;

	protected function request( array $payload ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/dotflow/v1/email/delivery/dotflow_test' );
		$request->set_param( 'provider', 'dotflow_test' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-dotflow-test-signature', 'valid-test-signature' );
		$request->set_body( wp_json_encode( $payload ) );

		return $request;
	}

	protected function find_event( $event_id ) {
		global $wpdb;
		$db = get_db( 'dotflow_events' );

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT ID, event_id, source, event_name, payload FROM {$db->get_table_name()} WHERE source = %s AND event_id = %s LIMIT 1",
			'delivery-dotflow_test',
			$event_id
		) );
	}

	public function test_authenticated_delivery_event_is_normalized_and_stored_without_pii() {

		$event_id = 'provider-event-0001';
		$request  = $this->request( [
			'event_id'   => $event_id,
			'message_id' => '<delivery-test-1@example.test>',
			'status'     => 'delivered',
			'channel'    => 'marketing',
			'to'         => 'private-recipient@example.test',
			'body'       => 'Private email content must not be persisted.',
		] );

		$this->assertTrue( Delivery_Webhook::authorize( $request ) );
		$response = Delivery_Webhook::receive( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 202, $response->get_status() );
		$this->assertTrue( $response->get_data()['accepted'] );

		$row = $this->find_event( $event_id );
		$this->assertNotEmpty( $row );
		$this->assertSame( 'email.delivery.delivered', $row->event_name );

		$payload = json_decode( $row->payload, true );
		$this->assertSame( 'dotflow_test', $payload['provider'] );
		$this->assertSame( 'marketing', $payload['channel'] );
		$this->assertSame( 'delivered', $payload['status'] );
		$this->assertSame( hash( 'sha256', '<delivery-test-1@example.test>' ), $payload['message_fingerprint'] );
		$this->assertArrayNotHasKey( 'message_id', $payload );
		$this->assertArrayNotHasKey( 'to', $payload );
		$this->assertArrayNotHasKey( 'body', $payload );
		$this->assertStringNotContainsString( 'private-recipient@example.test', $row->payload );
		$this->assertStringNotContainsString( 'Private email content', $row->payload );

		get_db( 'dotflow_events' )->delete( absint( $row->ID ) );
	}

	public function test_duplicate_provider_event_is_idempotent() {

		$payload = [
			'event_id'   => 'provider-event-duplicate',
			'message_id' => '<delivery-test-duplicate@example.test>',
			'status'     => 'bounced',
			'channel'    => 'transactional',
		];

		$dispatch_count = 0;
		$listener = function ( $event ) use ( &$dispatch_count ) {
			++$dispatch_count;
		};
		add_action( 'dotflow/event/email.delivery.bounced', $listener, 10, 1 );

		try {
			$first  = Delivery_Webhook::receive( $this->request( $payload ) );
			$second = Delivery_Webhook::receive( $this->request( $payload ) );

			$this->assertSame( 202, $first->get_status() );
			$this->assertSame( 202, $second->get_status() );
			$this->assertSame( 1, $dispatch_count, 'A duplicate provider event must not trigger listeners twice.' );

			$row = $this->find_event( 'provider-event-duplicate' );
			$this->assertNotEmpty( $row );
			get_db( 'dotflow_events' )->delete( absint( $row->ID ) );
		} finally {
			remove_action( 'dotflow/event/email.delivery.bounced', $listener, 10 );
		}
	}

	public function test_invalid_signature_is_rejected() {

		$request = $this->request( [
			'event_id'   => 'provider-event-unauthorized',
			'message_id' => '<delivery-test-unauthorized@example.test>',
			'status'     => 'delivered',
			'channel'    => 'marketing',
		] );
		$request->set_header( 'x-dotflow-test-signature', 'wrong-signature' );

		$result = Delivery_Webhook::authorize( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'dotflow_delivery_unauthorized', $result->get_error_code() );
	}

	public function test_invalid_status_and_message_identifiers_are_rejected() {

		$invalid_status = Delivery_Webhook::receive( $this->request( [
			'event_id'   => 'provider-event-invalid-status',
			'message_id' => '<delivery-test-invalid@example.test>',
			'status'     => 'opened',
			'channel'    => 'marketing',
		] ) );

		$this->assertWPError( $invalid_status );
		$this->assertSame( 'dotflow_delivery_invalid_envelope', $invalid_status->get_error_code() );

		$invalid_message_id = Delivery_Webhook::receive( $this->request( [
			'event_id'   => 'provider-event-invalid-id',
			'message_id' => 'not-an-rfc-message-id',
			'status'     => 'delivered',
			'channel'    => 'marketing',
		] ) );

		$this->assertWPError( $invalid_message_id );
		$this->assertSame( 'dotflow_delivery_invalid_envelope', $invalid_message_id->get_error_code() );
	}

	public function test_unknown_provider_and_oversized_payload_are_rejected() {

		$unknown = new WP_REST_Request( 'POST', '/dotflow/v1/email/delivery/not_registered' );
		$unknown->set_param( 'provider', 'not_registered' );
		$unknown->set_body( '{}' );
		$result = Delivery_Webhook::authorize( $unknown );

		$this->assertWPError( $result );
		$this->assertSame( 'dotflow_delivery_provider_unknown', $result->get_error_code() );

		$large = $this->request( [] );
		$large->set_body( str_repeat( 'x', Delivery_Webhook::MAX_BODY_BYTES + 1 ) );
		$result = Delivery_Webhook::authorize( $large );

		$this->assertWPError( $result );
		$this->assertSame( 'dotflow_delivery_payload_too_large', $result->get_error_code() );
	}
}
