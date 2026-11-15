<?php

use Groundhogg\DotFlow\Steps\Actions\DotFlow_Webhook;

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
}
