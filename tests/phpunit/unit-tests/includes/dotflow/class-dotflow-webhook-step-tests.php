<?php

use Groundhogg\DotFlow\Steps\Actions\DotFlow_Webhook;
use Groundhogg\Event;
use function Groundhogg\get_contactdata;

class DotFlow_Webhook_Step_Tests extends GH_UnitTestCase {

	public function test_webhook_url_validation_requires_https_and_rejects_private_targets() {
		$this->assertTrue( DotFlow_Webhook::is_safe_url( 'https://example.com/hooks/dotflow' ) );
		$this->assertFalse( DotFlow_Webhook::is_safe_url( 'http://example.com/hooks/dotflow' ) );
		$this->assertFalse( DotFlow_Webhook::is_safe_url( 'https://localhost/hooks' ) );
		$this->assertFalse( DotFlow_Webhook::is_safe_url( 'https://service.localhost/hooks' ) );
		$this->assertFalse( DotFlow_Webhook::is_safe_url( 'https://service.local/hooks' ) );
		$this->assertFalse( DotFlow_Webhook::is_safe_url( 'https://127.0.0.1/hooks' ) );
		$this->assertFalse( DotFlow_Webhook::is_safe_url( 'https://192.168.1.20/hooks' ) );
		$this->assertFalse( DotFlow_Webhook::is_safe_url( 'https://[::1]/hooks' ) );
		$this->assertFalse( DotFlow_Webhook::is_safe_url( 'https://user:password@example.com/hooks' ) );
		$this->assertFalse( DotFlow_Webhook::is_safe_url( 'https://example.com:8443/hooks' ) );
		$this->assertFalse( DotFlow_Webhook::is_safe_url( 'https://example.com/hooks#fragment' ) );
	}

	public function test_webhook_step_is_registered_with_the_dotflow_step_manager() {
		$this->assertTrue( \Groundhogg\Plugin::$instance->step_manager->type_is_registered( 'dotflow_webhook' ) );
	}

	public function test_webhook_requires_shared_secret_and_sends_a_minimal_hmac_signed_payload() {
		$contact_id = self::factory()->contacts->create( [ 'email' => 'webhook-contact@example.test' ] );
		$contact = get_contactdata( $contact_id );
		$funnel = new \Groundhogg\Funnel( [ 'title' => 'Webhook test', 'status' => 'active' ] );
		$step = $funnel->add_step( [
			'step_type'   => 'dotflow_webhook',
			'step_group'  => \Groundhogg\Step::ACTION,
			'step_title'  => 'Webhook',
			'step_status' => 'active',
			'meta'        => [ 'url' => 'https://example.com/dotflow-hook' ],
		] );
		$step->commit();
		$step->update_meta( 'url', 'https://example.com/dotflow-hook' );
		$element = $step->get_step_element();
		$event = new Event( [
			'ID' => 9001,
			'funnel_id' => $funnel->get_id(),
			'step_id' => $step->get_id(),
		] );

		$secret = str_repeat( 'test-secret-', 4 );
		$seen = [];
		$secret_filter = static function () use ( $secret ) { return $secret; };
		$http_filter = static function ( $pre, $args, $url ) use ( &$seen ) {
			if ( 'https://example.com/dotflow-hook' !== $url ) { return $pre; }
			$seen = $args;
			return [
				'headers' => [],
				'body' => '{}',
				'response' => [ 'code' => 202, 'message' => 'Accepted' ],
				'cookies' => [],
				'filename' => null,
			];
		};
		add_filter( 'dotflow/webhook/secret', $secret_filter );
		add_filter( 'pre_http_request', $http_filter, 10, 3 );

		try {
			$this->assertTrue( $element->run( $contact, $event ) );
			$payload = json_decode( $seen['body'], true );
			$this->assertSame( $contact_id, $payload['contact_id'] );
			$this->assertSame( $funnel->get_id(), $payload['flow_id'] );
			$this->assertArrayNotHasKey( 'email', $payload );
			$this->assertArrayNotHasKey( 'event_args', $payload );
			$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $seen['body'], $secret ), $seen['headers']['X-DotFlow-Signature'] );
			$this->assertSame( '0', (string) $seen['redirection'] );
		} finally {
			remove_filter( 'dotflow/webhook/secret', $secret_filter );
			remove_filter( 'pre_http_request', $http_filter, 10 );
			$funnel->delete();
		}
	}

	public function test_webhook_fails_closed_when_shared_secret_is_not_configured() {
		$filter = static function () { return ''; };
		add_filter( 'dotflow/webhook/secret', $filter );
		try {
			$this->assertSame( '', DotFlow_Webhook::get_secret() );
		} finally {
			remove_filter( 'dotflow/webhook/secret', $filter );
		}
	}

}
