<?php

namespace Groundhogg\DotFlow\Events;

use InvalidArgumentException;

/**
 * Dispatches domain events through stable WordPress action hooks.
 */
final class Dispatcher {

	/**
	 * Dispatch an event to generic and event-specific listeners.
	 *
	 * @param Event $event Domain event.
	 * @return Event
	 */
	public static function dispatch( Event $event ) {

		if ( ! $event instanceof Event ) {
			throw new InvalidArgumentException( 'Only DotFlow Event instances can be dispatched.' );
		}

		do_action( 'dotflow/event', $event );
		do_action( 'dotflow/event/' . $event->get_name(), $event );

		return $event;
	}
}
