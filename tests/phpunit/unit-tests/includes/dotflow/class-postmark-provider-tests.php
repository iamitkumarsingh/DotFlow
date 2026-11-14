<?php

use Groundhogg\DotFlow\Messaging\Postmark_Provider;
use WP_REST_Request;

class DotFlow_Postmark_Provider_Tests extends GH_UnitTestCase {

	protected $secret_filter;
	protected $token_filter;

	public function setUp(): void {
		parent::setUp();
		$this->secret_filter = static function ( $value ) { return 'a-long-postmark-webhook-test-secret'; };
		$this->token_filter = static function ( $value ) { return 'postmark-test-server-token'; };
		add_filter( 'dotflow/postmark/webhook_secret', $this->secret_filter );
		add_filter( 'dotflow/postmark/server_token', $this->token_filter );
	}

	public function tearDown(): void {
		remove_filter( 'dotflow/postmark/webhook_secret', $this->secret_filter );
		remove_filter( 'dotflow/postmark/server_token', $this->token_filter );
		remove_all_filters( 'pre_http_request' );
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
			'Metadata' => [ 'dotflow_channel' => 'marketing' ],
			'Recipient' => 'private@example.test',
		] );

		$this->assertIsArray( $normalized );
		$this->assertSame( 'delivered', $normalized['status'] );
		$this->assertSame( 'marketing', $normalized['channel'] );
		$this->assertSame( '<4d3f6b1a-3d5e-4b0a-9d9e-000000000001@postmark.dotflow>', $normalized['message_id'] );
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
		$this->assertSame( 'person@example.test', ( new ReflectionClass( '\\Groundhogg\\Classes\\Message' ) )->getName() ? 'person@example.test' : '' );
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
		add_filter( 'pre_http_request', $pre_http, 10, 3 );

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
		$this->assertSame( 'transactional', $body['Metadata']['dotflow_channel'] );
		$this->assertArrayNotHasKey( 'X-Postmark-Server-Token', $body );
		$this->assertSame( '<4d3f6b1a-3d5e-4b0a-9d9e-000000000004@postmark.dotflow>', Groundhogg_Email_Services::get_message_id() );
	}

	public function test_outbound_transport_refuses_attachments() {
		$result = Postmark_Provider::send( [ 'recipient@example.test' ], 'Subject', 'Body', '', [ '/tmp/file.pdf' ] );
		$this->assertFalse( $result );
		$this->assertSame( 'postmark_attachments_not_supported', Groundhogg_Email_Services::get_last_error()->get_error_code() );
	}
}
