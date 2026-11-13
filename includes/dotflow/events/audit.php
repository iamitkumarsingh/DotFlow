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

		$object_type = sanitize_key( $object_type );
		$object_id   = absint( $object_id );
		$payload     = [
			'action'      => preg_replace( '/[^a-z0-9._-]/', '', strtolower( sanitize_text_field( $action ) ) ),
			'object_type'    => $object_type,
			'object_id'      => $object_id,
			'actor_user_id'  => get_current_user_id(),
			'changes'        => $changes,
		];

		// The event store indexes contact activity by contact_id. Keep contact
		// mutations directly discoverable without exposing their changed values.
		if ( 'contact' === $object_type && $object_id ) {
			$payload['contact_id'] = $object_id;
		}

		$event = new Event( 'ai.mutation.completed', $payload, sanitize_key( $source ) );

		return Dispatcher::dispatch( $event );
	}
}
