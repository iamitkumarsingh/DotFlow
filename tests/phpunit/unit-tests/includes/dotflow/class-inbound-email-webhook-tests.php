<?php

use Groundhogg\Classes\Inbox;
use Groundhogg\Classes\Message;
use Groundhogg\DotFlow\Inbound\Inbound_Email_Webhook;
use Groundhogg\DotFlow\Inbound\Inbox_Addresses;
use function Groundhogg\get_db;

class DotFlow_Inbound_Email_Webhook_Tests extends GH_UnitTestCase {

	protected $saved_options = [];
	protected $inbox_filter;
	protected $reply_filter;
	protected $token_filter;
	protected $https_value;
	protected $server_port_value;

	public function setUp(): void {
		parent::setUp();

		foreach ( [
			Inbox::ID_OPTION,
			Inbox::ADDRESS_OPTION,
			Inbox::REPLY_ADDRESS_OPTION,
			Inbox::SITE_URL_OPTION,
			Inbox::TOKEN_KEY_OPTION,
		] as $option ) {
			$this->saved_options[ $option ] = get_option( $option, null );
			delete_option( $option );
		}

		update_option( Inbox::SITE_URL_OPTION, Inbox::normalize_url( home_url() ), false );

		$this->inbox_filter = function ( $value ) {
			return 'private-inbox@example.com';
		};
		$this->reply_filter = function ( $value ) {
			return 'reply@example.com';
		};
		$this->token_filter = function ( $value ) {
			return 'test-webhook-token-0123456789-abcdef';
		};

		add_filter( 'dotflow/inbound/inbox_address', $this->inbox_filter );
		add_filter( 'dotflow/inbound/reply_address', $this->reply_filter );
		add_filter( 'dotflow/inbound/webhook_token', $this->token_filter );

		Inbox_Addresses::register();
		$this->https_value      = $_SERVER['HTTPS'] ?? null;
		$this->server_port_value = $_SERVER['SERVER_PORT'] ?? null;
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['SERVER_PORT'] = '443';

		\Groundhogg\DotFlow\Events\Store::maybe_install();
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

	protected function request( array $payload ): WP_REST_Request {

		$request = new WP_REST_Request( 'POST', '/dotflow/v1/inbound/email' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-dotflow-token', 'test-webhook-token-0123456789-abcdef' );
		$request->set_body( wp_json_encode( $payload ) );

		return $request;
	}

	public function test_webhook_requires_https_and_a_valid_server_token() {

		$request = $this->request( [] );

		$this->assertTrue( Inbox_Addresses::is_enabled() );
		$this->assertTrue( Inbound_Email_Webhook::authorize( $request ) );

		$request->set_header( 'x-dotflow-token', 'incorrect-token' );
		$denied = Inbound_Email_Webhook::authorize( $request );

		$this->assertWPError( $denied );
		$this->assertSame( 'dotflow_inbound_unauthorized', $denied->get_error_code() );

		$_SERVER['HTTPS'] = 'off';
		$_SERVER['SERVER_PORT'] = '80';
		$request->set_header( 'x-dotflow-token', 'test-webhook-token-0123456789-abcdef' );
		$insecure = Inbound_Email_Webhook::authorize( $request );

		$this->assertWPError( $insecure );
		$this->assertSame( 'dotflow_inbound_https_required', $insecure->get_error_code() );
	}

	public function test_webhook_ingests_messages_idempotently_and_records_metadata_only() {

		global $wpdb;

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$contact_id = self::factory()->contacts->create( [
			'email'      => 'sender@example.com',
			'first_name' => 'Sender',
		] );

		$payload = [
			'source'          => 'test-provider',
			'provider_event_id' => 'provider-event-001',
			'from'            => 'Sender <sender@example.com>',
			'to'              => 'private-inbox@example.com',
			'envelope_to'     => 'private-inbox@example.com',
			'subject'         => 'Question about my appointment',
			'text'            => 'Please call me about my appointment.',
			'date'            => '2026-11-13T12:00:00Z',
		];

		$request = $this->request( $payload );
		$this->assertTrue( Inbound_Email_Webhook::authorize( $request ) );

		$response = Inbound_Email_Webhook::receive( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['accepted'] );

		$message_db = get_db( 'messages' );
		$row        = $message_db->get_by( 'message_id', '<dotflow-' . hash( 'sha256', 'test-provider:provider-event-001' ) . '@dotflow.local>' );

		$this->assertNotEmpty( $row );
		$this->assertSame( $contact_id, absint( $row->object_id ) );
		$this->assertSame( 'inbound', $row->direction );
		$this->assertSame( 'received', $row->status );
		$this->assertFalse( (bool) $row->is_read );

		$event_db = get_db( 'dotflow_events' );
		$event    = $wpdb->get_row( $wpdb->prepare(
			"SELECT payload FROM {$event_db->get_table_name()} WHERE event_name = %s AND contact_id = %d ORDER BY ID DESC LIMIT 1",
			'email.inbound.received',
			$contact_id
		) );
		$event_payload = $event ? json_decode( $event->payload, true ) : [];

		$this->assertSame( 'test-provider', $event_payload['provider'] );
		$this->assertArrayNotHasKey( 'content', $event_payload );
		$this->assertArrayNotHasKey( 'from', $event_payload );
		$this->assertStringNotContainsString( 'sender@example.com', wp_json_encode( $event_payload ) );
		$this->assertStringNotContainsString( 'Please call me', wp_json_encode( $event_payload ) );

		$duplicate_request = $this->request( $payload );
		$duplicate = Inbound_Email_Webhook::receive( $duplicate_request );

		$this->assertSame( 200, $duplicate->get_status() );
		$this->assertTrue( $duplicate->get_data()['duplicate'] );
		$event_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$event_db->get_table_name()} WHERE event_name = %s AND contact_id = %d",
			'email.inbound.received',
			$contact_id
		) );
		$this->assertSame( 1, $event_count );

		$message_db->delete( absint( $row->ID ) );
		$wpdb->delete( $event_db->get_table_name(), [
			'event_name' => 'email.inbound.received',
			'contact_id' => $contact_id,
		], [ '%s', '%d' ] );
	}

	public function test_custom_reply_address_uses_local_signed_tokens_without_relay_provisioning() {

		$message_id = Message::generate_message_id();
		$reply_to   = Inbox::reply_to( $message_id );

		$this->assertNotSame( '', $reply_to );
		$this->assertSame( substr( $message_id, 1, 16 ), Inbox::verify_reply( [ $reply_to ] ) );
	}

	public function test_independent_mode_never_overrides_a_provisioned_groundhogg_relay() {

		update_option( Inbox::ID_OPTION, 'existing-relay-inbox-id', false );

		$this->assertFalse( Inbox_Addresses::is_enabled() );
		$this->assertSame( 'existing@example.com', Inbox_Addresses::inbox_address( 'existing@example.com' ) );
		$this->assertSame( 'reply-existing@example.com', Inbox_Addresses::reply_address( 'reply-existing@example.com' ) );
	}
}
