<?php

use Groundhogg\DotFlow\REST\Routes;

class DotFlow_REST_Routes_Tests extends GH_UnitTestCase {

	public function test_schema_documents_the_authenticated_inbound_webhook_contract() {

		$schema = Routes::get_schema();

		$this->assertCount( 3, $schema['webhooks'] );
		$this->assertSame( 'POST', $schema['webhooks'][0]['method'] );
		$this->assertSame( '/wp-json/dotflow/v1/inbound/email', $schema['webhooks'][0]['path'] );
		$this->assertFalse( $schema['webhooks'][0]['attachments'] );
		$this->assertStringContainsString( 'Message::ingest', $schema['webhooks'][0]['groundhogg_ingest'] );
		$this->assertSame( '/wp-json/dotflow/v1/inbound/email/{provider}', $schema['webhooks'][1]['path'] );
		$this->assertSame( '/wp-json/dotflow/v1/email/delivery/{provider}', $schema['webhooks'][2]['path'] );
		$this->assertContains( 'bounced', $schema['webhooks'][2]['statuses'] );
	}

	public function test_read_only_discovery_routes_are_registered() {

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/dotflow/v1/manifest', $routes );
		$this->assertArrayHasKey( '/dotflow/v1/schema', $routes );
		$this->assertArrayHasKey( '/dotflow/v1/events', $routes );
		$this->assertArrayHasKey( '/dotflow/v1/email/delivery/(?P<provider>[a-z0-9_-]{1,48})', $routes );
		$this->assertArrayHasKey( '/dotflow/v1/inbound/email/(?P<provider>[a-z0-9_-]{1,48})', $routes );
	}

	public function test_schema_discloses_abilities_without_exposing_generic_mutation_api() {

		$schema = Routes::get_schema();

		$this->assertSame( '1.0', $schema['schema_version'] );
		$this->assertSame( 'v1', $schema['api_version'] );
		$this->assertFalse( $schema['write_policy']['arbitrary_sql'] );
		$this->assertFalse( $schema['write_policy']['credential_readback'] );
		$this->assertSame( 'No generic REST CRUD. Inbound email uses either its server-side token or a registered provider adapter. Outbound delivery callbacks require a registered provider adapter to authenticate and normalize each webhook.', $schema['write_policy']['rest_mutations'] );
		$this->assertContains( 'groundhogg/update-contact', $schema['objects']['contact']['write_abilities'] );
	}
}
