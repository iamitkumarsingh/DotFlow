<?php

use Groundhogg\DotFlow\Events\Event;
use Groundhogg\DotFlow\Events\Dispatcher;

class DotFlow_Event_Tests extends GH_UnitTestCase {

	public function test_event_exposes_normalised_envelope() {

		$event = new Event(
			'contact.created',
			[ 'contact_id' => 42 ],
			'unit-test',
			'event-test-42',
			'2026-01-01T12:00:00+00:00'
		);

		$this->assertSame( 'event-test-42', $event->get_id() );
		$this->assertSame( 'contact.created', $event->get_name() );
		$this->assertSame( [ 'contact_id' => 42 ], $event->get_payload() );
		$this->assertSame( 'unit-test', $event->get_source() );
		$this->assertSame( '2026-01-01T12:00:00+00:00', $event->get_occurred_at() );
		$this->assertSame( 'contact.created', $event->to_array()['name'] );
	}

	public function test_event_rejects_invalid_name() {

		$this->expectException( InvalidArgumentException::class );
		new Event( 'Contact Created' );
	}


	public function test_event_rejects_oversized_identifiers() {

		$this->expectException( InvalidArgumentException::class );
		new Event( 'contact.created', [], 'unit-test', str_repeat( 'x', 121 ) );
	}

	public function test_event_rejects_oversized_sources() {

		$this->expectException( InvalidArgumentException::class );
		new Event( 'contact.created', [], str_repeat( 'x', 65 ) );
	}

	public function test_event_rejects_oversized_names() {

		$this->expectException( InvalidArgumentException::class );
		new Event( str_repeat( 'a', 192 ) );
	}

	public function test_dispatcher_fires_generic_and_specific_hooks() {

		$event = new Event( 'test.dispatched', [], 'unit-test', 'event-dispatch-1' );
		$generic = null;
		$specific = null;

		$generic_callback = function ( $received ) use ( &$generic ) {
			$generic = $received;
		};
		$specific_callback = function ( $received ) use ( &$specific ) {
			$specific = $received;
		};

		add_action( 'dotflow/event', $generic_callback, 10, 1 );
		add_action( 'dotflow/event/test.dispatched', $specific_callback, 10, 1 );

		Dispatcher::dispatch( $event );

		remove_action( 'dotflow/event', $generic_callback, 10 );
		remove_action( 'dotflow/event/test.dispatched', $specific_callback, 10 );

		$this->assertSame( $event, $generic );
		$this->assertSame( $event, $specific );
	}
}
