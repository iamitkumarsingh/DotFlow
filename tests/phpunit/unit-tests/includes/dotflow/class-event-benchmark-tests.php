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
		remove_filter( 'groundhogg/steps/benchmarks', [ $benchmark, 'register' ] );
		remove_action( 'wp_enqueue_scripts', [ $benchmark, 'frontend_scripts' ] );
		remove_action( 'admin_enqueue_scripts', [ $benchmark, 'admin_scripts' ] );
	}

	public function test_event_matching_is_exact_source_filterable_and_excludes_audit_events() {

		$this->assertTrue( DotFlow_Event::matches_event( 'email.inbound.received', 'email', 'email.inbound.received' ) );
		$this->assertTrue( DotFlow_Event::matches_event( 'email.inbound.received', 'postmark', 'email.inbound.received', 'postmark' ) );
		$this->assertFalse( DotFlow_Event::matches_event( 'email.inbound.received', 'mailgun', 'email.inbound.received', 'postmark' ) );
		$this->assertFalse( DotFlow_Event::matches_event( 'email.inbound.received', 'email', 'email.inbound.failed' ) );
		$this->assertFalse( DotFlow_Event::matches_event( 'ai.mutation.completed', 'mcp', 'ai.mutation.completed' ) );
		$this->assertFalse( DotFlow_Event::matches_event( '', 'email', 'email.inbound.received' ) );
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
