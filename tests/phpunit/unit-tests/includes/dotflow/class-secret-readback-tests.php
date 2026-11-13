<?php

use Groundhogg\Abilities\Schemas\License_Schema;
use Groundhogg\Abilities\Settings\List_Settings;
use Groundhogg\Abilities\Settings\Update_Settings;
use Groundhogg\License;

class DotFlow_Secret_Readback_Tests extends GH_UnitTestCase {

	protected function ability_args( $class ): array {

		$reflection = new ReflectionClass( $class );
		$ability    = $reflection->newInstanceWithoutConstructor();
		$method     = $reflection->getMethod( 'get_args' );
		$method->setAccessible( true );

		return $method->invoke( $ability );
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
