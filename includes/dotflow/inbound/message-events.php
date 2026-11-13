<?php

namespace Groundhogg\DotFlow\Inbound;

use Groundhogg\Classes\Message;
use Groundhogg\DotFlow\Events\Dispatcher;
use Groundhogg\DotFlow\Events\Event;

/**
 * Bridges Groundhogg's transport-neutral inbound message hook into DotFlow events.
 */
final class Message_Events {

	public static function register() {
		add_action( 'groundhogg/message/received', [ __CLASS__, 'record_received' ], 10, 3 );
	}

	/**
	 * Record metadata only. Message bodies, addresses and headers stay in the
	 * permission-scoped conversation store and are never copied into event payloads.
	 *
	 * @param Message $message Ingested message.
	 * @param object  $object  Associated Groundhogg object.
	 * @param array   $payload Normalized transport payload.
	 * @return void
	 */
	public static function record_received( $message, $object, $payload = [] ) {

		if ( ! $message instanceof Message ) {
			return;
		}

		$object_type = sanitize_key( (string) $message->object_type );
		$object_id   = absint( $message->object_id );
		$contact_id  = 'contact' === $object_type ? $object_id : 0;
		$provider    = isset( $payload['dotflow_source'] ) ? sanitize_key( $payload['dotflow_source'] ) : 'groundhogg-inbox';
		$message_id  = absint( $message->get_id() );

		if ( ! $message_id ) {
			return;
		}

		$payload = is_array( $payload ) ? $payload : [];

		$event = new Event( 'email.inbound.received', [
			'object_type'       => $object_type,
			'object_id'         => $object_id,
			'contact_id'        => $contact_id,
			'message_record_id' => $message_id,
			'thread_id'         => sanitize_text_field( (string) $message->thread_id ),
			'provider'          => $provider ?: 'groundhogg-inbox',
		], 'email', 'message-' . $message_id );

		Dispatcher::dispatch( $event );
	}
}
