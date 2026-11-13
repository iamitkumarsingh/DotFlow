<?php

class DotFlow_Abilities_Registration_Tests extends GH_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The WordPress Abilities API is not available.' );
		}
	}

	public function test_dotflow_category_and_ai_abilities_are_discoverable() {

		$this->assertTrue( wp_has_ability_category( 'dotflow-platform' ) );

		foreach ( [
			'dotflow/get-system-manifest',
			'dotflow/list-published-forms',
			'dotflow/list-events',
			'dotflow/list-messages',
			'dotflow/set-message-read-state',
		] as $ability_name ) {
			$this->assertTrue( wp_has_ability( $ability_name ), $ability_name . ' should be registered for AI/MCP discovery.' );
		}
	}
}
