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
			$this->assertSame( [ 'id', 'name' ], array_keys( $service ) );
		}

		foreach ( $result['channels'] as $channel ) {
			$this->assertSame( [ 'service_id', 'service_name', 'available' ], array_keys( $channel ) );
			$this->assertIsBool( $channel['available'] );
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
