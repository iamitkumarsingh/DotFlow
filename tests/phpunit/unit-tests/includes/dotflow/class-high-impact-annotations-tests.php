<?php

use Groundhogg\Abilities\Broadcasts\Send_Email_Broadcast;
use Groundhogg\Abilities\Extensions\Activate_License;
use Groundhogg\Abilities\Extensions\Install_Extension;
use Groundhogg\Abilities\Funnels\Activate_Flow;
use Groundhogg\Abilities\Funnels\Add_To_Flow;
use Groundhogg\Abilities\Funnels\Deactivate_Flow;
use Groundhogg\Abilities\Funnels\Publish_Flow_Changes;
use Groundhogg\Abilities\Settings\Update_Settings;

class DotFlow_High_Impact_Annotations_Tests extends GH_UnitTestCase {

	public function test_high_impact_mutations_are_marked_destructive() {

		$classes = [
			Activate_Flow::class,
			Deactivate_Flow::class,
			Add_To_Flow::class,
			Publish_Flow_Changes::class,
			Send_Email_Broadcast::class,
			Install_Extension::class,
			Activate_License::class,
			Update_Settings::class,
		];

		foreach ( $classes as $class ) {
			$reflection = new ReflectionClass( $class );
			$constant   = $reflection->getReflectionConstant( 'DESTRUCTIVE' );

			$this->assertNotFalse( $constant, $class . ' must declare a destructive annotation.' );
			$this->assertTrue( $constant->getValue(), $class . ' must require a confirmation-capable host.' );
		}
	}
}
