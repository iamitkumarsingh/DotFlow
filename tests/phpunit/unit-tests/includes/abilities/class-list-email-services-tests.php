<?php

use Groundhogg\Abilities\Emails\List_Email_Services;

class List_Email_Services_Ability_Tests extends GH_UnitTestCase {

	protected function ability(): List_Email_Services {
		$reflection = new ReflectionClass( List_Email_Services::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	public function test_service_diagnostics_returns_only_public_service_metadata() {

		$result = $this->ability()->__invoke( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'services', $result );
		$this->assertArrayHasKey( 'channels', $result );
		$this->assertIsArray( $result['services'] );
		$this->assertSame( [ 'wordpress', 'transactional', 'marketing' ], array_keys( $result['channels'] ) );

		foreach ( $result['services'] as $service ) {
			$this->assertSame( [ 'id', 'name', 'callable' ], array_keys( $service ) );
			$this->assertIsBool( $service['callable'] );
		}

		foreach ( $result['channels'] as $channel ) {
			$this->assertSame( [ 'service_id', 'service_name', 'registered', 'callable', 'available' ], array_keys( $channel ) );
			$this->assertIsBool( $channel['registered'] );
			$this->assertIsBool( $channel['callable'] );
			$this->assertIsBool( $channel['available'] );
		}
	}


	public function test_registered_service_with_non_callable_transport_is_unavailable() {

		$service_id = 'dotflow-invalid-callback';
		$option     = 'gh_marketing_email_service';
		$missing    = '__dotflow_missing_option__';
		$before     = get_option( $option, $missing );

		\\Groundhogg_Email_Services::register( $service_id, 'DotFlow Invalid Callback', 'dotflow_function_that_does_not_exist' );
		update_option( $option, $service_id );

		try {
			$result = $this->ability()->__invoke( [] );
			$channel = $result['channels']['marketing'];

			$this->assertTrue( $channel['registered'] );
			$this->assertFalse( $channel['callable'] );
			$this->assertFalse( $channel['available'] );
			$this->assertSame( 'DotFlow Invalid Callback', $channel['service_name'] );
		} finally {
			if ( $before === $missing ) {
				delete_option( $option );
			} else {
				update_option( $option, $before );
			}
		}
	}

	public function test_unregistered_selected_service_is_reported_as_unavailable() {

		$option  = 'gh_marketing_email_service';
		$missing = '__dotflow_missing_option__';
		$before  = get_option( $option, $missing );

		update_option( $option, 'dotflow-unregistered-service' );

		try {
			$result = $this->ability()->__invoke( [] );

			$this->assertSame( 'dotflow-unregistered-service', $result['channels']['marketing']['service_id'] );
			$this->assertSame( '', $result['channels']['marketing']['service_name'] );
			$this->assertFalse( $result['channels']['marketing']['registered'] );
			$this->assertFalse( $result['channels']['marketing']['callable'] );
			$this->assertFalse( $result['channels']['marketing']['available'] );
		} finally {
			if ( $before === $missing ) {
				delete_option( $option );
			} else {
				update_option( $option, $before );
			}
		}
	}
}
