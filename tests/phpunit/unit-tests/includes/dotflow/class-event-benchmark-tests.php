<?php

use Groundhogg\DotFlow\Bootstrap;
use Groundhogg\DotFlow\Steps\Benchmarks\DotFlow_Event;

class DotFlow_Event_Benchmark_Tests extends GH_UnitTestCase {

	public function test_dotflow_event_benchmark_is_registered_with_the_step_manager() {

		$manager = new class {
			public $steps = [];

			public function add_step( $step ) {
				$this->steps[ $step->get_type() ] = $step;
			}
		};

		Bootstrap::register_steps( $manager );

		$this->assertArrayHasKey( 'dotflow_event', $manager->steps );
		$this->assertInstanceOf( DotFlow_Event::class, $manager->steps['dotflow_event'] );

		$benchmark = $manager->steps['dotflow_event'];
		remove_action( 'dotflow/event', [ $benchmark, 'reset' ], 97 );
		remove_action( 'dotflow/event', [ $benchmark, 'setup' ], 98 );
		remove_action( 'dotflow/event', [ $benchmark, 'complete' ], 99 );
	}

	public function test_event_benchmark_schema_accepts_only_valid_event_names_and_sources() {

		$reflection = new ReflectionClass( DotFlow_Event::class );
		$benchmark  = $reflection->newInstanceWithoutConstructor();
		$schema     = $benchmark->get_settings_schema();

		$this->assertSame( 'email.inbound.received', $schema['event_name']['sanitize']( 'email.inbound.received' ) );
		$this->assertSame( '', $schema['event_name']['sanitize']( 'Email Inbound' ) );
		$this->assertSame( 'provider_1', $schema['source']['sanitize']( 'Provider_1' ) );
	}
}
