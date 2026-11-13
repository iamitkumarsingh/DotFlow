<?php

namespace Groundhogg\DotFlow\Events;

use InvalidArgumentException;

/**
 * Dispatches domain events through stable WordPress action hooks.
 */
final class Dispatcher {

	/**
	 * Persist and dispatch an event to generic and event-specific listeners.
	 *
	 * Persistence failure is observable but does not interrupt the originating
	 * WordPress request or prevent in-process listeners from receiving the event.
	 *
	 * @param Event $event Domain event.
	 * @return Event
	 */
	public static function dispatch( Event $event ) {

		if ( ! $event instanceof Event ) {
			throw new InvalidArgumentException( 'Only DotFlow Event instances can be dispatched.' );
		}

		$stored = Store::record_with_status( $event );

		if ( ! $stored ) {
			do_action( 'dotflow/event/persistence_failed', $event );

			return $event;
		}

		$record_id = $stored['id'];

		if ( ! $stored['inserted'] ) {
			do_action( 'dotflow/event/duplicate', $record_id, $event );

			return $event;
		}

		do_action( 'dotflow/event/recorded', $record_id, $event );
		do_action( 'dotflow/event', $event );
		do_action( 'dotflow/event/' . $event->get_name(), $event );

		return $event;
	}
}
