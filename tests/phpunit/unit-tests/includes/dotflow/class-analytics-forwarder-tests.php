<?php

use Groundhogg\DotFlow\Analytics\Analytics_Forwarder;
use Groundhogg\DotFlow\Events\Event;

class DotFlow_Analytics_Forwarder_Tests extends GH_UnitTestCase {

	protected $filters = [];
	protected $http_filter;

	public function tearDown(): void {
		foreach ( $this->filters as $filter => $callback ) {
			remove_filter( $filter, $callback );
		}
		if ( $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
		}
		parent::tearDown();
	}

	protected function add_test_filter( $name, $callback ) {
		$this->filters[ $name ] = $callback;
		add_filter( $name, $callback, 10, 2 );
	}

	public function test_analytics_is_disabled_without_explicit_consent() {
		$event = new Event( 'form.submitted', [ 'contact_id' => 99, 'form_id' => 7 ], 'groundhogg', 'consent-test-1' );
		$this->assertFalse( Analytics_Forwarder::has_consent( $event ) );
		$this->assertFalse( Analytics_Forwarder::map_event( new Event( 'ai.mutation.completed', [], 'mcp' ) ) );
	}

	public function test_allowlisted_event_mapping_excludes_contact_identifiers_and_query_strings() {
		$event = new Event( 'form.submitted', [
			'contact_id' => 12345,
			'form_id' => 9,
			'email' => 'private@example.test',
			'page_path' => 'https://example.test/book/?email=private@example.test',
		], 'dotform', 'dotform-123' );

		$mapped = Analytics_Forwarder::map_event( $event );
		$this->assertSame( 'generate_lead', $mapped['event'] );
		$this->assertSame( 9, $mapped['params']['form_id'] );
		$this->assertSame( 'dotform', $mapped['params']['dotflow_source'] );
		$json = wp_json_encode( $mapped );
		$this->assertStringNotContainsString( 'private@example.test', $json );
		$this->assertStringNotContainsString( 'contact_id', $json );
	}

	public function test_ga4_forwarding_requires_consent_configuration_and_existing_client_id() {
		$event = new Event( 'form.submitted', [ 'form_id' => 7 ], 'groundhogg', 'ga4-consent-test' );
		$mapped = Analytics_Forwarder::map_event( $event );

		$this->add_test_filter( 'dotflow/analytics/ga4_measurement_id', static function () { return 'G-TEST123456'; } );
		$this->add_test_filter( 'dotflow/analytics/ga4_api_secret', static function () { return 'server-secret-not-for-browser'; } );
		$this->add_test_filter( 'dotflow/analytics/client_id', static function () { return '12345.67890'; } );

		$captured = [];
		$this->http_filter = static function ( $pre, $args, $url ) use ( &$captured ) {
			if ( 0 !== strpos( $url, Analytics_Forwarder::GA4_ENDPOINT ) ) {
				return $pre;
			}
			$captured = [ 'args' => $args, 'url' => $url ];
			return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 204, 'message' => 'No Content' ], 'cookies' => [], 'filename' => null ];
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

		$this->assertFalse( Analytics_Forwarder::send_ga4( $event, $mapped ) );
		$this->add_test_filter( 'dotflow/analytics/consent_granted', static function () { return true; } );
		$this->assertTrue( Analytics_Forwarder::send_ga4( $event, $mapped ) );
		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( '12345.67890', $body['client_id'] );
		$this->assertSame( 'generate_lead', $body['events'][0]['name'] );
		$this->assertFalse( $captured['args']['blocking'] );
		$this->assertStringNotContainsString( 'server-secret-not-for-browser', $captured['args']['body'] );
	}
	public function test_gtm_is_not_enqueued_without_consent_and_bootstraps_after_grant() {
		$this->add_test_filter( 'dotflow/analytics/gtm_container_id', static function () { return 'GTM-ABC123'; } );
		$this->add_test_filter( 'dotflow/analytics/consent_granted', static function () { return false; } );

		Analytics_Forwarder::enqueue_gtm();
		$this->assertNotContains( 'dotflow-google-tag-manager', wp_scripts()->queue );

		$this->add_test_filter( 'dotflow/analytics/consent_granted', static function () { return true; } );
		Analytics_Forwarder::enqueue_gtm();
		$this->assertContains( 'dotflow-google-tag-manager', wp_scripts()->queue );
		$before = wp_scripts()->get_data( 'dotflow-google-tag-manager', 'before' );
		$this->assertIsArray( $before );
		$this->assertStringContainsString( 'gtm.start', implode( "\\n", $before ) );
		$this->assertStringContainsString( 'dotflow_analytics_consent_granted', implode( "\\n", $before ) );
	}

}
