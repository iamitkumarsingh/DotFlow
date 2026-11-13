<?php

use Groundhogg\Contact;
use Groundhogg\DotFlow\Bootstrap;
use Groundhogg\DotFlow\Events\Dispatcher;
use Groundhogg\DotFlow\Events\Event;
use Groundhogg\Funnel;
use Groundhogg\Plugin;
use function Groundhogg\get_db;
use Groundhogg\DotFlow\Steps\Benchmarks\DotFlow_Event;
use Groundhogg\Abilities\Schemas\Step_Type_Schema;

class DotFlow_Event_Benchmark_Tests extends GH_UnitTestCase {

	public function test_dotflow_event_benchmark_is_registered_with_the_step_manager() {

		$this->assertNotFalse( has_action( 'groundhogg/steps/init', [ Bootstrap::class, 'register_steps' ] ) );

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

	public function test_dotflow_event_step_is_discoverable_and_buildable_by_typed_ai_abilities() {

		$this->assertNotFalse( has_action( 'groundhogg/abilities/register_step_types', [ Bootstrap::class, 'register_step_type_schema' ] ) );

		Bootstrap::register_step_type_schema();

		$this->assertContains( 'dotflow_event', Step_Type_Schema::supported_types() );
		$this->assertTrue( Step_Type_Schema::get_type_info( 'dotflow_event' )['buildable'] );

		$schema = Step_Type_Schema::settings_schema( 'dotflow_event' );

		$this->assertSame( [ 'event_name' ], $schema['required'] );
		$this->assertSame( 'string', $schema['properties']['event_name']['type'] );
		$this->assertSame( 'string', $schema['properties']['source']['type'] );
		$this->assertSame( 64, $schema['properties']['source']['maxLength'] );

		$resolved = Step_Type_Schema::resolve_settings( 'dotflow_event', [
			'event_name' => 'email.inbound.received',
			'source'     => 'Email',
		], [] );
		$this->assertNotWPError( $resolved );
		$this->assertSame( [ 'event_name' => 'email.inbound.received', 'source' => 'email' ], $resolved['settings'] );

		$reserved = Step_Type_Schema::resolve_settings( 'dotflow_event', [ 'event_name' => 'ai.mutation.completed' ], [] );
		$this->assertWPError( $reserved );
		$this->assertSame( 'dotflow_event_name_invalid', $reserved->get_error_code() );

		$unknown = Step_Type_Schema::resolve_settings( 'dotflow_event', [ 'event_name' => 'email.inbound.received', 'payload' => [ 'secret' => 'value' ] ], [] );
		$this->assertWPError( $unknown );
		$this->assertSame( 'dotflow_event_unknown_setting', $unknown->get_error_code() );

		$long_source = Step_Type_Schema::resolve_settings( 'dotflow_event', [ 'event_name' => 'email.inbound.received', 'source' => str_repeat( 'a', 80 ) ], [] );
		$this->assertWPError( $long_source );
		$this->assertSame( 'dotflow_event_source_too_long', $long_source->get_error_code() );
	}

	public function test_matching_contact_event_enqueues_once_without_forwarding_payload() {

		global $wpdb;

		$this->assertTrue( Plugin::instance()->step_manager->type_is_registered( 'dotflow_event' ) );

		$funnel = new Funnel( [
			'title'  => 'DotFlow Event Benchmark Test',
			'status' => 'active',
		] );

		$step = $funnel->add_step( [
			'step_title'  => 'Inbound email received',
			'step_type'   => 'dotflow_event',
			'step_group'  => 'benchmark',
			'step_status' => 'active',
			'meta'        => [
				'event_name' => 'email.inbound.received',
				'source'     => 'email',
			],
		] );
		$step->commit();

		$contact_id = self::factory()->contacts->create( [
			'email' => 'event-trigger@example.test',
		] );
		$event_id = 'dotflow-event-benchmark-integration-001';

		try {
			$event = new Event( 'email.inbound.received', [
				'contact_id' => $contact_id,
				'object_type' => 'contact',
				'object_id' => $contact_id,
				'content' => 'Private message body must not be forwarded.',
				'api_key' => 'never-forward-this-secret',
			], 'email', $event_id );

			Dispatcher::dispatch( $event );
			Dispatcher::dispatch( $event );

			$waiting = $step->get_waiting_events();

			$this->assertCount( 1, $waiting );
			$this->assertSame( $contact_id, $waiting[0]->get_contact_id() );
			$this->assertSame( 'email.inbound.received', $waiting[0]->args['event_name'] );
			$this->assertSame( 'email', $waiting[0]->args['event_source'] );
			$this->assertSame( $event_id, $waiting[0]->args['event_id'] );
			$this->assertArrayNotHasKey( 'content', $waiting[0]->args );
			$this->assertArrayNotHasKey( 'api_key', $waiting[0]->args );
		} finally {
			$funnel->delete();
			$contact = new Contact( $contact_id );
			if ( $contact->exists() ) {
				$contact->delete();
			}
			$events_db = get_db( 'dotflow_events' );
			if ( $events_db ) {
				$wpdb->delete( $events_db->get_table_name(), [ 'event_id' => $event_id ], [ '%s' ] );
			}
		}
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
		$this->assertSame( 64, strlen( $schema['source']['sanitize']( str_repeat( 'a', 80 ) ) ) );
	}
}
