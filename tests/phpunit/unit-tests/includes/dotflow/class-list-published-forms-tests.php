<?php

use Groundhogg\DotFlow\Abilities\List_Published_Forms;
use function Groundhogg\get_db;

class DotFlow_List_Published_Forms_Tests extends GH_UnitTestCase {

	public function test_only_active_web_form_steps_are_returned() {

		$previous_user_id = get_current_user_id();
		$user_id          = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user             = get_userdata( $user_id );
		$user->add_cap( 'view_funnels' );
		wp_set_current_user( $user_id );

		if ( ! current_user_can( 'view_funnels' ) ) {
			$this->markTestSkipped( 'The test user cannot view flows.' );
		}

		$db = get_db( 'steps' );

		$active_id = $db->add( [
			'funnel_id'   => 0,
			'step_title'  => 'DotFlow MCP Test Form',
			'step_type'   => 'web_form',
			'step_status' => 'active',
		] );

		$inactive_id = $db->add( [
			'funnel_id'   => 0,
			'step_title'  => 'DotFlow MCP Inactive Form',
			'step_type'   => 'web_form',
			'step_status' => 'inactive',
		] );

		try {
			$reflection = new ReflectionClass( List_Published_Forms::class );
			$ability    = $reflection->newInstanceWithoutConstructor();
			$result     = $ability( [ 'search' => 'DotFlow MCP' ] );

			$this->assertSame( 1, $result['total_items'] );
			$this->assertCount( 1, $result['forms'] );
			$this->assertSame( $active_id, $result['forms'][0]['id'] );
			$this->assertSame( 'dotflow-form', $result['forms'][0]['elementor_widget'] );
			$this->assertSame( 'groundhogg/edit-flow', $result['forms'][0]['edit_ability'] );
		} finally {
			wp_set_current_user( $previous_user_id );
			if ( $active_id ) {
				$db->delete( $active_id );
			}
			if ( $inactive_id ) {
				$db->delete( $inactive_id );
			}
		}
	}
}
