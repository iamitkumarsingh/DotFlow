<?php

use Groundhogg\Abilities\Schemas\License_Schema;
use Groundhogg\Abilities\Settings\List_Settings;
use Groundhogg\Abilities\Settings\Update_Settings;
use Groundhogg\License;
use Groundhogg\Plugin;
use Groundhogg\Settings;

class DotFlow_Secret_Readback_Tests extends GH_UnitTestCase {

	protected function ability_args( $class ): array {

		$reflection = new ReflectionClass( $class );
		$ability    = $reflection->newInstanceWithoutConstructor();
		$method     = $reflection->getMethod( 'get_args' );
		$method->setAccessible( true );

		return $method->invoke( $ability );
	}


	protected function with_sensitive_test_setting( callable $callback ) {

		$settings       = Plugin::$instance->settings;
		$registry       = new ReflectionProperty( Settings::class, 'registered_settings' );
		$registry->setAccessible( true );
		$before         = $registry->getValue( $settings );
		$old_value      = get_option( 'gh_dotflow_test_secret', null );

		$settings->add_setting( 'dotflow_test_secret', [
			'type'        => 'string',
			'description' => 'Test secret setting.',
			'default'     => 'default-secret-value',
			'sensitive'   => true,
		] );
		update_option( 'gh_dotflow_test_secret', 'stored-secret-value' );

		try {
			$callback();
		} finally {
			$registry->setValue( $settings, $before );

			if ( null === $old_value ) {
				delete_option( 'gh_dotflow_test_secret' );
			} else {
				update_option( 'gh_dotflow_test_secret', $old_value );
			}
		}
	}

	public function test_list_settings_redacts_sensitive_current_and_default_values() {

		$this->with_sensitive_test_setting( function () {
			$reflection = new ReflectionClass( List_Settings::class );
			$ability    = $reflection->newInstanceWithoutConstructor();
			$result     = $ability->__invoke( [] );

			$matches = array_values( array_filter( $result['settings'], function ( $setting ) {
				return 'dotflow_test_secret' === $setting['id'];
			} ) );

			$this->assertCount( 1, $matches );
			$this->assertTrue( $matches[0]['sensitive'] );
			$this->assertNotSame( 'stored-secret-value', $matches[0]['value'] );
			$this->assertNotSame( 'default-secret-value', $matches[0]['default'] );
			$this->assertStringNotContainsString( 'reveal', strtolower( $matches[0]['description'] ) );
			$this->assertStringNotContainsString( 'stored-secret-value', wp_json_encode( $matches[0] ) );
			$this->assertStringNotContainsString( 'default-secret-value', wp_json_encode( $matches[0] ) );
		} );
	}

	public function test_update_settings_redacts_sensitive_values_in_its_response() {

		$this->with_sensitive_test_setting( function () {
			$reflection = new ReflectionClass( Update_Settings::class );
			$ability    = $reflection->newInstanceWithoutConstructor();
			$result     = $ability->__invoke( [
				'settings' => [ 'gh_dotflow_test_secret' => 'new-secret-value' ],
			] );

			$this->assertNotWPError( $result );
			$this->assertCount( 1, $result['updated'] );
			$this->assertNotSame( 'new-secret-value', $result['updated'][0]['value'] );
			$this->assertStringNotContainsString( 'new-secret-value', wp_json_encode( $result ) );
		} );
	}

	public function test_license_schema_never_returns_the_raw_license_key() {

		$license = new License( [
			'license' => 'valid',
			'expires' => 'lifetime',
			'item_id' => 123,
			'items'   => [ 123 ],
		] );

		$result = License_Schema::transform( 'license-secret-value', $license );

		$this->assertSame( License_Schema::REDACTED, $result['license_key'] );
		$this->assertStringNotContainsString( 'license-secret-value', wp_json_encode( $result ) );
	}

	public function test_settings_abilities_do_not_offer_secret_reveal_inputs() {

		$list_args   = $this->ability_args( List_Settings::class );
		$update_args = $this->ability_args( Update_Settings::class );

		$this->assertArrayNotHasKey( 'reveal', $list_args['input_schema']['properties'] );
		$this->assertArrayNotHasKey( 'reveal', $update_args['input_schema']['properties'] );
		$this->assertStringContainsString( 'always redacted', strtolower( $list_args['output_schema']['properties']['settings']['items']['properties']['sensitive']['description'] ) );
		$this->assertStringContainsString( 'always redacted', strtolower( $update_args['output_schema']['properties']['updated']['items']['properties']['value']['description'] ) );
	}
}
