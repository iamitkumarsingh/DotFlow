<?php

use Groundhogg\Form\Form_Fields;

class DotFlow_Settings_Sanitization_Tests extends GH_UnitTestCase {

	public function test_missing_custom_form_setting_returns_an_empty_form_and_map() {

		$this->assertSame( [ [], [] ], Form_Fields::sanitize_form_and_map( false ) );
		$this->assertSame( [ [], [] ], Form_Fields::sanitize_form_and_map( null ) );
		$this->assertSame( [ [], [] ], Form_Fields::sanitize_form_and_map( [] ) );
		$this->assertSame( [ [], [] ], Form_Fields::sanitize_form_and_map( [ 'invalid', 'invalid' ] ) );
	}
}
