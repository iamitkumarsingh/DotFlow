<?php

use Groundhogg\Classes\Inbox;
use Groundhogg\DotFlow\Events\Store;
use Groundhogg\DotFlow\Inbound\Inbound_Email_Provider_Adapter;
use Groundhogg\DotFlow\Inbound\Inbound_Provider_Registry;
use Groundhogg\DotFlow\Inbound\Inbound_Provider_Webhook;
use Groundhogg\DotFlow\Inbound\Inbox_Addresses;
use function Groundhogg\get_db;

class DotFlow_Test_Inbound_Email_Adapter implements Inbound_Email_Provider_Adapter {

	public function get_id(): string {
		return 'dotflow_inbound_test';
	}

	public function authorize( WP_REST_Request $request ) {
		return 'valid-inbound-test-signature' === $request->get_header( 'x-dotflow-inbound-test-signature' );
	}

	public function normalize( array $payload ) {
		$payload['source'] = 'dotflow-inbound-test';
		return $payload;
	}
}

class DotFlow_Inbound_Provider_Webhook_Tests extends GH_UnitTestCase {

	protected $saved_options = [];
	protected $inbox_filter;
	protected $reply_filter;
	protected $token_filter;
	protected $https_value;
	protected $server_port_value;

	public function setUp(): void {
		parent::setUp();

		foreach ( [ Inbox::ID_OPTION, Inbox::ADDRESS_OPTION, Inbox::REPLY_ADDRESS_OPTION, Inbox::SITE_URL_OPTION, Inbox::TOKEN_KEY_OPTION ] as $option ) {
			$this->saved_options[ $option ] = get_option( $option, null );
			delete_option( $option );
		}

		$this->inbox_filter = function ( $value ) { return 'private-inbox@example.com'; };
		$this->reply_filter = function ( $value ) { return 'reply@example.com'; };
		$this->token_filter = function ( $value ) { return 'test-webhook-token-0123456789-abcdef'; };
		add_filter( 'dotflow/inbound/inbox_address', $this->inbox_filter );
		add_filter( 'dotflow/inbound/reply_address', $this->reply_filter );
		add_filter( 'dotflow/inbound/webhook_token', $this->token_filter );
		Inbox_Addresses::register();

		$this->https_value       = $_SERVER['HTTPS'] ?? null;
		$this->server_port_value = $_SERVER['SERVER_PORT'] ?? null;
		$_SERVER['HTTPS']        = 'on';
		$_SERVER['SERVER_PORT']  = '443';

		Store::maybe_install();
		if ( ! Inbound_Provider_Registry::get( 'dotflow_inbound_test' ) ) {
			Inbound_Provider_Registry::register( new DotFlow_Test_Inbound_Email_Adapter() );
		}
	}

	public function tearDown(): void {
		remove_filter( 'dotflow/inbound/inbox_address', $this->inbox_filter );
		remove_filter( 'dotflow/inbound/reply_address', $this->reply_filter );
		remove_filter( 'dotflow/inbound/webhook_token', $this->token_filter );

		foreach ( $this->saved_options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value, false );
			}
		}

		if ( null === $this->https_value ) { unset( $_SERVER['HTTPS'] ); } else { $_SERVER['HTTPS'] = $this->https_value; }
		if ( null === $this->server_port_value ) { unset( $_SERVER['SERVER_PORT'] ); } else { $_SERVER['SERVER_PORT'] = $this->server_port_value; }
		parent::tearDown();
	}

	protected function request( array $payload ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/dotflow/v1/inbound/email/dotflow_inbound_test' );
		$request->set_param( 'provider', 'dotflow_inbound_test' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-dotflow-inbound-test-signature', 'valid-inbound-test-signature' );
		$request->set_body( wp_json_encode( $payload ) );
		return $request;
	}

	public function test_provider_adapter_authenticates_and_uses_the_canonical_ingest_pipeline() {

		$contact_id = self::factory()->contacts->create( [
			'email'      => 'provider-sender@example.com',
			'first_name' => 'Provider',
		] );

		$request = $this->request( [
			'provider_event_id' => 'provider-inbound-001',
			'from'              => 'Provider <provider-sender@example.com>',
			'to'                => 'private-inbox@example.com',
			'envelope_to'       => 'private-inbox@example.com',
			'subject'           => 'Inbound provider test',
			'text'              => 'Shared ingest pipeline test.',
		] );

		$this->assertTrue( Inbound_Provider_Webhook::authorize( $request ) );
		$response = Inbound_Provider_Webhook::receive( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['accepted'] );

		$message_id = '<dotflow-' . hash( 'sha256', 'dotflow-inbound-test:provider-inbound-001' ) . '@dotflow.local>';
		$message_db = get_db( 'messages' );
		$row        = $message_db->get_by( 'message_id', $message_id );
		$this->assertNotEmpty( $row );
		$this->assertSame( $contact_id, absint( $row->object_id ) );
		$this->assertSame( 'inbound', $row->direction );
		$message_db->delete( absint( $row->ID ) );
	}

	public function test_provider_adapter_requires_independent_inbox_configuration() {

		remove_filter( 'dotflow/inbound/inbox_address', $this->inbox_filter );
		$request = $this->request( [
			'provider_event_id' => 'provider-inbound-disabled',
			'from'              => 'sender@example.com',
			'envelope_to'       => 'private-inbox@example.com',
			'text'              => 'Must not be accepted while disabled.',
		] );

		$result = Inbound_Provider_Webhook::authorize( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'dotflow_inbound_provider_disabled', $result->get_error_code() );
		add_filter( 'dotflow/inbound/inbox_address', $this->inbox_filter );
	}

	public function test_provider_adapter_rejects_invalid_signatures_and_unknown_providers() {

		$request = $this->request( [
			'provider_event_id' => 'provider-inbound-unauthorized',
			'from'              => 'sender@example.com',
			'envelope_to'       => 'private-inbox@example.com',
			'text'              => 'No unauthenticated ingest.',
		] );
		$request->set_header( 'x-dotflow-inbound-test-signature', 'invalid' );
		$result = Inbound_Provider_Webhook::authorize( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'dotflow_inbound_provider_unauthorized', $result->get_error_code() );

		$unknown = new WP_REST_Request( 'POST', '/dotflow/v1/inbound/email/unknown_provider' );
		$unknown->set_param( 'provider', 'unknown_provider' );
		$unknown->set_body( '{}' );
		$result = Inbound_Provider_Webhook::authorize( $unknown );
		$this->assertWPError( $result );
		$this->assertSame( 'dotflow_inbound_provider_unknown', $result->get_error_code() );
	}
}
