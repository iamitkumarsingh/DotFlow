<?php

use Groundhogg\DotFlow\Events\Dispatcher;
use Groundhogg\DotFlow\Events\Event;
use Groundhogg\DotFlow\Events\Store;
use function Groundhogg\get_db;

class DotFlow_Event_Dispatcher_Tests extends GH_UnitTestCase {

	protected $event_ids = [];

	public function tearDown(): void {

		global $wpdb;
		$db = get_db( 'dotflow_events' );

		if ( $db ) {
			foreach ( $this->event_ids as $event_id ) {
				$wpdb->delete( $db->get_table_name(), [ 'event_id' => $event_id ], [ '%s' ] );
			}
		}

		parent::tearDown();
	}

	public function test_duplicate_event_delivery_does_not_repeat_downstream_hooks() {

		Store::maybe_install();

		$event_id = 'dispatcher-idempotency-test-001';
		$this->event_ids[] = $event_id;
		$event = new Event( 'contact.form_submitted', [
			'contact_id' => 123,
			'object_type' => 'contact',
			'object_id' => 123,
		], 'dispatcher-test', $event_id );

		$recorded = 0;
		$handled  = 0;
		$duplicate = 0;

		$recorded_callback = function ( $record_id, $dispatched_event ) use ( &$recorded, $event_id ) {
			if ( $dispatched_event instanceof Event && $dispatched_event->get_id() === $event_id ) {
				$recorded++;
			}
		};
		$event_callback = function ( $dispatched_event ) use ( &$handled, $event_id ) {
			if ( $dispatched_event instanceof Event && $dispatched_event->get_id() === $event_id ) {
				$handled++;
			}
		};
		$duplicate_callback = function ( $record_id, $dispatched_event ) use ( &$duplicate, $event_id ) {
			if ( $dispatched_event instanceof Event && $dispatched_event->get_id() === $event_id ) {
				$duplicate++;
			}
		};

		add_action( 'dotflow/event/recorded', $recorded_callback, 10, 2 );
		add_action( 'dotflow/event/contact.form_submitted', $event_callback, 10, 1 );
		add_action( 'dotflow/event/duplicate', $duplicate_callback, 10, 2 );

		try {
			Dispatcher::dispatch( $event );
			Dispatcher::dispatch( $event );

			$this->assertSame( 1, $recorded );
			$this->assertSame( 1, $handled );
			$this->assertSame( 1, $duplicate );
		} finally {
			remove_action( 'dotflow/event/recorded', $recorded_callback, 10 );
			remove_action( 'dotflow/event/contact.form_submitted', $event_callback, 10 );
			remove_action( 'dotflow/event/duplicate', $duplicate_callback, 10 );
		}
	}
}
