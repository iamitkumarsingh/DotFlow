<?php

use Groundhogg\DotFlow\Abilities\Get_System_Manifest;

class DotFlow_System_Manifest_Tests extends GH_UnitTestCase {

	public function test_manifest_exposes_capabilities_without_private_values() {

		$reflection = new ReflectionClass( Get_System_Manifest::class );
		$ability    = $reflection->newInstanceWithoutConstructor();
		$manifest   = $ability( [] );

		$this->assertSame( 'DotFlow', $manifest['product'] );
		$this->assertSame( 'v1', $manifest['api_version'] );
		$this->assertArrayHasKey( 'modules', $manifest );
		$this->assertFalse( $manifest['data_policy']['credentials_exposed_to_ai'] );
		$this->assertTrue( $manifest['data_policy']['sensitive_values_always_redacted'] );
		$this->assertTrue( $manifest['data_policy']['pii_restrictions_enforced'] );
		$this->assertTrue( $manifest['data_policy']['high_impact_mutations_marked_destructive'] );
		$this->assertTrue( $manifest['data_policy']['private_data_requires_capability'] );

		$module_ids = array_column( $manifest['modules'], 'id' );

		$this->assertContains( 'crm', $module_ids );
		$this->assertContains( 'automation', $module_ids );
		$this->assertContains( 'elementor', $module_ids );
	}

	public function test_manifest_does_not_claim_unimplemented_modules_are_complete() {

		$reflection = new ReflectionClass( Get_System_Manifest::class );
		$ability    = $reflection->newInstanceWithoutConstructor();
		$manifest   = $ability( [] );

		$modules = [];

		foreach ( $manifest['modules'] as $module ) {
			$modules[ $module['id'] ] = $module;
		}

		$this->assertSame( 'planned', $modules['messaging']['status'] );
		$this->assertSame( 'planned', $modules['integrations']['status'] );
	}
}
