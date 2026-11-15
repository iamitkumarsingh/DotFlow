<?php

use Groundhogg\\DotFlow\\Elementor\\Form_Resolver;

class DotFlow_Elementor_Form_Resolver_Tests extends GH_UnitTestCase {

	public function test_invalid_and_missing_form_ids_never_resolve() {
		$this->assertFalse( Form_Resolver::get_published_form( 0 ) );
		$this->assertFalse( Form_Resolver::get_published_form( -10 ) );
		$this->assertFalse( Form_Resolver::get_published_form( 999999999 ) );
	}

	public function test_form_options_are_bounded_and_always_include_a_safe_placeholder() {
		$options = Form_Resolver::get_form_options();
		$this->assertIsArray( $options );
		$this->assertNotEmpty( $options );
		$this->assertArrayHasKey( '', $options );
		$this->assertLessThanOrEqual( 100, count( $options ) );
		foreach ( $options as $id => $label ) {
			$this->assertTrue( is_int( $id ) || '' === $id );
			$this->assertIsString( $label );
		}
	}
}
