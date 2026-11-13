<?php

namespace Groundhogg\DotFlow\Events;

/**
 * Records security-conscious audit events for DotFlow-managed mutations.
 *
 * Audit payloads describe the operation and changed fields; they must not
 * contain message bodies, credentials, or unnecessary personal information.
 */
final class Audit {

	/**
	 * Record a successful mutation performed through a DotFlow management surface.
	 *
	 * @param string $action      Stable action identifier.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param array  $changes     Changed field names and before/after values.
	 * @param string $source      Originating management surface.
	 * @return Event
	 */
	public static function record( $action, $object_type, $object_id, array $changes = [], $source = 'mcp' ) {

		$event = new Event( 'ai.mutation.completed', [
			'action'      => preg_replace( '/[^a-z0-9._-]/', '', strtolower( sanitize_text_field( $action ) ) ),
			'object_type' => sanitize_key( $object_type ),
			'object_id'   => absint( $object_id ),
			'changes'     => $changes,
		], sanitize_key( $source ) );

		return Dispatcher::dispatch( $event );
	}
}
