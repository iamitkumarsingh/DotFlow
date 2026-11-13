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

		$record_id = Store::record( $event );

		if ( $record_id ) {
			do_action( 'dotflow/event/recorded', $record_id, $event );
		} else {
			do_action( 'dotflow/event/persistence_failed', $event );
		}

		do_action( 'dotflow/event', $event );
		do_action( 'dotflow/event/' . $event->get_name(), $event );

		return $event;
	}
}
