<?php

use Groundhogg\DotFlow\Messaging\Postmark_Provider;
use Groundhogg\DotFlow\Messaging\Postmark_Retry_Queue;
use function Groundhogg\get_db;

class DotFlow_Postmark_Provider_Tests extends GH_UnitTestCase {

	protected $secret_filter;
	protected $token_filter;
	protected $http_filter;
	protected $previous_channel;

	public function setUp(): void {
		parent::setUp();
		$this->previous_channel = Groundhogg_Email_Services::get_current_message_type();
		Groundhogg_Email_Services::set_current_message_type( Groundhogg_Email_Services::WORDPRESS );
		$this->secret_filter = static function ( $value ) { return 'a-long-postmark-webhook-test-secret'; };
		$this->token_filter = static function ( $value ) { return 'postmark-test-server-token'; };
		add_filter( 'dotflow/postmark/webhook_secret', $this->secret_filter );
		add_filter( 'dotflow/postmark/server_token', $this->token_filter );
	}

	public function tearDown(): void {
		remove_filter( 'dotflow/postmark/webhook_secret', $this->secret_filter );
		remove_filter( 'dotflow/postmark/server_token', $this->token_filter );
		if ( $this->http_filter ) { remove_filter( 'pre_http_request', $this->http_filter, 10 ); }
		Groundhogg_Email_Services::set_current_message_type( $this->previous_channel );
		parent::tearDown();
	}

	protected function request( string $authorization = '' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/dotflow/v1/email/delivery/postmark' );
		if ( '' !== $authorization ) {
			$request->set_header( 'authorization', $authorization );
		}
		return $request;
	}

	public function test_postmark_webhook_uses_constant_time_basic_auth_contract() {
		$valid = 'Basic ' . base64_encode( 'dotflow:a-long-postmark-webhook-test-secret' );
		$this->assertTrue( Postmark_Provider::authorize( $this->request( $valid ) ) );

		$invalid = Postmark_Provider::authorize( $this->request( 'Basic ' . base64_encode( 'dotflow:wrong-secret' ) ) );
		$this->assertWPError( $invalid );
		$this->assertSame( 'dotflow_postmark_unauthorized', $invalid->get_error_code() );
	}

	public function test_delivery_event_is_normalized_to_dotflow_message_id_and_channel() {
		$normalized = Postmark_Provider::normalize_delivery( [
			'MessageID' => '4d3f6b1a-3d5e-4b0a-9d9e-000000000001',
			'DeliveredAt' => '2026-11-14T12:00:00Z',
			'MessageStream' => 'broadcasts',
			'Metadata' => [ 'dotflow_channel' => 'marketing', 'dotflow_message_id' => '<stable-message-001@dotflow.local>' ],
			'Recipient' => 'private@example.test',
		] );

		$this->assertIsArray( $normalized );
		$this->assertSame( 'delivered', $normalized['status'] );
		$this->assertSame( 'marketing', $normalized['channel'] );
		$this->assertSame( '<stable-message-001@dotflow.local>', $normalized['message_id'] );
		$this->assertArrayNotHasKey( 'Recipient', $normalized );
	}

	public function test_bounce_and_spam_complaint_are_classified_separately() {
		$bounce = Postmark_Provider::normalize_delivery( [
			'MessageID' => '4d3f6b1a-3d5e-4b0a-9d9e-000000000002',
			'ID' => 456,
			'Type' => 'HardBounce',
			'TypeCode' => 1,
		] );
		$complaint = Postmark_Provider::normalize_delivery( [
			'MessageID' => '4d3f6b1a-3d5e-4b0a-9d9e-000000000003',
			'ID' => 457,
			'Type' => 'SpamComplaint',
		] );

		$this->assertSame( 'bounced', $bounce['status'] );
		$transient = Postmark_Provider::normalize_delivery( [
			'MessageID' => '4d3f6b1a-3d5e-4b0a-9d9e-000000000007',
			'ID' => 458,
			'Type' => 'Transient',
			'TypeCode' => 2,
		] );
		$this->assertSame( 'deferred', $transient['status'] );
		$this->assertSame( 'complained', $complaint['status'] );
	}

	public function test_inbound_payload_uses_canonical_pipeline_and_rejects_attachments() {
		$normalized = Postmark_Provider::normalize_inbound( [
			'MessageID' => 'postmark-inbound-001',
			'From' => 'Person <person@example.test>',
			'To' => 'inbox@example.test',
			'Subject' => 'Hello',
			'TextBody' => 'Message body',
			'HtmlBody' => '<p>Message body</p>',
		] );

		$this->assertIsArray( $normalized );
		$this->assertSame( 'Person <person@example.test>', $normalized['from'] );
		$this->assertSame( 'inbox@example.test', $normalized['envelope_to'] );
		$this->assertSame( 'Message body', $normalized['text'] );

		$rejected = Postmark_Provider::normalize_inbound( [
			'MessageID' => 'postmark-inbound-002',
			'From' => 'Person <person@example.test>',
			'To' => 'inbox@example.test',
			'Attachments' => [ [ 'Name' => 'unsafe.exe' ] ],
		] );
		$this->assertWPError( $rejected );
		$this->assertSame( 'dotflow_postmark_attachments_unsupported', $rejected->get_error_code() );
	}

	public function test_outbound_transport_calls_postmark_api_without_leaking_token_in_body() {
		$captured = [];
		$pre_http = static function ( $pre, $args, $url ) use ( &$captured ) {
			if ( Postmark_Provider::API !== $url ) {
				return $pre;
			}
			$captured = $args;
			return [
				'headers' => [],
				'body' => wp_json_encode( [
					'ErrorCode' => 0,
					'MessageID' => '4d3f6b1a-3d5e-4b0a-9d9e-000000000004',
				] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies' => [],
				'filename' => null,
			];
		};
		$this->http_filter = $pre_http;
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

		$result = Postmark_Provider::send(
			[ 'recipient@example.test' ],
			'Test subject',
			'<p>Test body</p>',
			[ 'From' => 'DotFlow <sender@example.test>' ]
		);

		$this->assertTrue( $result );
		$this->assertSame( 'postmark-test-server-token', $captured['headers']['X-Postmark-Server-Token'] );
		$body = json_decode( $captured['body'], true );
		$this->assertSame( 'recipient@example.test', $body['To'] );
		$this->assertSame( 'dotflow', $body['Tag'] );
		$this->assertSame( 'wordpress', $body['Metadata']['dotflow_channel'] );
		$this->assertArrayNotHasKey( 'X-Postmark-Server-Token', $body );
		$this->assertMatchesRegularExpression( '/^<[^<>]+@dotflow\\.local>$/', Groundhogg_Email_Services::get_message_id() );
		$this->assertSame( Groundhogg_Email_Services::get_message_id(), $body['Metadata']['dotflow_message_id'] );
	}

	public function test_outbound_transport_refuses_attachments() {
		$result = Postmark_Provider::send( [ 'recipient@example.test' ], 'Subject', 'Body', '', [ '/tmp/file.pdf' ] );
		$this->assertFalse( $result );
	}

	public function test_retry_payload_is_encrypted_and_reservation_is_idempotent() {
		Postmark_Retry_Queue::maybe_install();
		$message_id = '<retry-test-001@dotflow.local>';
		$payload = [
			'To' => 'private-recipient@example.test',
			'Subject' => 'Sensitive subject',
			'TextBody' => 'Sensitive email body',
			'Metadata' => [ 'dotflow_channel' => 'transactional', 'dotflow_message_id' => $message_id ],
		];
		$reservation = Postmark_Retry_Queue::reserve( $payload, $message_id );
		$this->assertIsArray( $reservation );
		$this->assertSame( 'reserved', $reservation['status'] );
		$this->assertTrue( Postmark_Retry_Queue::is_queued( $message_id ) );

		global $wpdb;
		$wpdb->last_error = '';
		$duplicate = Postmark_Retry_Queue::reserve( $payload, $message_id );
		$this->assertSame( '', $wpdb->last_error, 'A duplicate idempotency reservation must not produce a database error.' );
		$this->assertIsArray( $duplicate );
		$this->assertTrue( $duplicate['duplicate'] );
		$this->assertSame( 'processing', $duplicate['status'] );

		global $wpdb;
		$db = get_db( 'dotflow_postmark_outbox' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT payload FROM {$db->get_table_name()} WHERE idempotency_key = %s",
			hash( 'sha256', $message_id )
		) );
		$this->assertNotEmpty( $row );
		$this->assertStringNotContainsString( 'private-recipient@example.test', $row->payload );
		$this->assertStringNotContainsString( 'Sensitive email body', $row->payload );

		$wpdb->delete( $db->get_table_name(), [ 'idempotency_key' => hash( 'sha256', $message_id ) ] );
	}

	public function test_retry_worker_processes_429_queue_and_records_acceptance() {
		Postmark_Retry_Queue::maybe_install();
		$message_id = '<retry-test-002@dotflow.local>';
		$payload = [
			'From' => 'sender@example.test',
			'To' => 'recipient@example.test',
			'Subject' => 'Retry test',
			'TextBody' => 'Retry body',
			'MessageStream' => 'outbound',
			'Metadata' => [ 'dotflow_channel' => 'transactional', 'dotflow_message_id' => $message_id ],
		];
		$reservation = Postmark_Retry_Queue::reserve( $payload, $message_id );
		$this->assertIsArray( $reservation );
		$this->assertTrue( Postmark_Retry_Queue::schedule_retry( $reservation, 0 ) );
		global $wpdb;
		$db = get_db( 'dotflow_postmark_outbox' );
		$wpdb->update( $db->get_table_name(), [ 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ], [ 'idempotency_key' => hash( 'sha256', $message_id ) ] );

		$this->http_filter = static function ( $pre, $args, $url ) {
			if ( Postmark_Provider::API !== $url ) {
				return $pre;
			}
			return [
				'headers' => [],
				'body' => wp_json_encode( [ 'ErrorCode' => 0, 'MessageID' => '4d3f6b1a-3d5e-4b0a-9d9e-000000000005' ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies' => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

		Postmark_Retry_Queue::process_batch();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT status, provider_message_id, payload FROM {$db->get_table_name()} WHERE idempotency_key = %s",
			hash( 'sha256', $message_id )
		) );
		$this->assertNotEmpty( $row );
		$this->assertSame( 'accepted', $row->status );
		$this->assertSame( '4d3f6b1a-3d5e-4b0a-9d9e-000000000005', $row->provider_message_id );
		$this->assertSame( '', $row->payload ?? '' );
		$this->assertFalse( Postmark_Retry_Queue::is_queued( $message_id ) );
		$wpdb->delete( $db->get_table_name(), [ 'idempotency_key' => hash( 'sha256', $message_id ) ] );
	}


	public function test_postmark_settings_encrypt_secrets_at_rest() {
		$missing = '__dotflow_missing_option__';
		$before = get_option( Postmark_Provider::OPTION, $missing );
		remove_filter( 'dotflow/postmark/server_token', $this->token_filter );
		remove_filter( 'dotflow/postmark/webhook_secret', $this->secret_filter );

		try {
			$stored = Postmark_Provider::sanitize_settings( [
				'server_token' => 'test-server-secret-value',
				'webhook_secret' => 'test-webhook-secret-value',
				'transactional_stream' => 'outbound',
				'marketing_stream' => 'broadcasts',
			] );
			$this->assertStringNotContainsString( 'test-server-secret-value', $stored['server_token'] );
			$this->assertStringNotContainsString( 'test-webhook-secret-value', $stored['webhook_secret'] );
			update_option( Postmark_Provider::OPTION, $stored );
			$this->assertSame( 'test-server-secret-value', Postmark_Provider::secret( 'server_token' ) );
			$this->assertSame( 'test-webhook-secret-value', Postmark_Provider::secret( 'webhook_secret' ) );
		} finally {
			if ( $before === $missing ) { delete_option( Postmark_Provider::OPTION ); }
			else { update_option( Postmark_Provider::OPTION, $before ); }
			add_filter( 'dotflow/postmark/server_token', $this->token_filter );
			add_filter( 'dotflow/postmark/webhook_secret', $this->secret_filter );
		}
	}


	public function test_postmark_only_retries_explicit_rate_limit_responses() {
		$this->http_filter = static function ( $pre, $args, $url ) {
			if ( Postmark_Provider::API !== $url ) { return $pre; }
			return [
				'headers' => [],
				'body' => wp_json_encode( [ 'ErrorCode' => 11, 'Message' => 'Rate limit exceeded' ] ),
				'response' => [ 'code' => 429, 'message' => 'Too Many Requests' ],
				'cookies' => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
		$result = Postmark_Provider::request_payload( [ 'To' => 'recipient@example.test' ] );
		$this->assertTrue( $result['retryable'] );
		$this->assertFalse( $result['uncertain'] );
	}

	public function test_ambiguous_network_failure_becomes_uncertain_and_payload_is_erased() {
		Postmark_Retry_Queue::maybe_install();
		$message_id = '<retry-test-003@dotflow.local>';
		$payload = [
			'From' => 'sender@example.test',
			'To' => 'recipient@example.test',
			'Subject' => 'Uncertain test',
			'TextBody' => 'Private body',
			'MessageStream' => 'outbound',
			'Metadata' => [ 'dotflow_channel' => 'transactional', 'dotflow_message_id' => $message_id ],
		];
		$reservation = Postmark_Retry_Queue::reserve( $payload, $message_id );
		$this->assertIsArray( $reservation );
		$this->assertTrue( Postmark_Retry_Queue::schedule_retry( $reservation, 0 ) );
		global $wpdb;
		$db = get_db( 'dotflow_postmark_outbox' );
		$wpdb->update( $db->get_table_name(), [ 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ], [ 'idempotency_key' => hash( 'sha256', $message_id ) ] );

		$this->http_filter = static function ( $pre, $args, $url ) {
			return Postmark_Provider::API === $url ? new WP_Error( 'http_request_failed', 'Connection timed out' ) : $pre;
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
		Postmark_Retry_Queue::process_batch();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT status, payload FROM {$db->get_table_name()} WHERE idempotency_key = %s",
			hash( 'sha256', $message_id )
		) );
		$this->assertSame( 'uncertain', $row->status );
		$this->assertSame( '', $row->payload );
		$this->assertFalse( Postmark_Retry_Queue::is_queued( $message_id ) );
		$wpdb->delete( $db->get_table_name(), [ 'idempotency_key' => hash( 'sha256', $message_id ) ] );
	}


	public function test_postmark_rate_limit_is_durably_queued_by_the_transport() {
		Postmark_Retry_Queue::maybe_install();
		$message_id = '<retry-test-004@dotflow.local>';
		Groundhogg_Email_Services::set_message_id( $message_id );
		$this->http_filter = static function ( $pre, $args, $url ) {
			if ( Postmark_Provider::API !== $url ) { return $pre; }
			return [
				'headers' => [],
				'body' => wp_json_encode( [ 'ErrorCode' => 11, 'Message' => 'Rate limit exceeded' ] ),
				'response' => [ 'code' => 429, 'message' => 'Too Many Requests' ],
				'cookies' => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

		$result = Postmark_Provider::send( [ 'recipient@example.test' ], 'Rate limit test', 'Sensitive body' );
		$this->assertTrue( $result );
		$this->assertTrue( Postmark_Retry_Queue::is_queued( $message_id ) );

		global $wpdb;
		$db = get_db( 'dotflow_postmark_outbox' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT status, payload FROM {$db->get_table_name()} WHERE idempotency_key = %s",
			hash( 'sha256', $message_id )
		) );
		$this->assertSame( 'pending', $row->status );
		$this->assertStringNotContainsString( 'Sensitive body', $row->payload );
		$wpdb->delete( $db->get_table_name(), [ 'idempotency_key' => hash( 'sha256', $message_id ) ] );
	}


	public function test_delivery_event_deduplication_is_recipient_scoped_without_storing_addresses() {
		$base = [
			'MessageID' => '4d3f6b1a-3d5e-4b0a-9d9e-000000000006',
			'DeliveredAt' => '2026-11-14T12:00:00Z',
			'Metadata' => [ 'dotflow_channel' => 'transactional' ],
		];
		$first = Postmark_Provider::normalize_delivery( $base + [ 'Recipient' => 'one@example.test' ] );
		$duplicate = Postmark_Provider::normalize_delivery( $base + [ 'Recipient' => 'one@example.test' ] );
		$second = Postmark_Provider::normalize_delivery( $base + [ 'Recipient' => 'two@example.test' ] );

		$this->assertSame( $first['event_id'], $duplicate['event_id'] );
		$this->assertNotSame( $first['event_id'], $second['event_id'] );
		$this->assertArrayNotHasKey( 'Recipient', $first );
		$this->assertStringNotContainsString( 'one@example.test', $first['event_id'] );
	}

}
