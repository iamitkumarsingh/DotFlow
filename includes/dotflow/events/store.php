<?php

namespace Groundhogg\DotFlow\Events;

/**
 * Persists DotFlow events with source-scoped idempotency and secret redaction.
 */
final class Store {

	/**
	 * Ensure the DotFlow event table is installed/up to date.
	 *
	 * @return void
	 */
	public static function maybe_install() {

		$db = \Groundhogg\get_db( 'dotflow_events' );

		if ( ! $db ) {
			return;
		}

		$version = get_option( $db->get_table_name() . '_db_version' );

		if ( (string) $version !== (string) $db->version ) {
			$db->create_table();
		}
	}

	/**
	 * Persist an event. Repeated source/event IDs return the original row ID.
	 *
	 * @param Event $event Event envelope.
	 * @return int|false
	 */
	public static function record( Event $event ) {

		global $wpdb;

		$db = \Groundhogg\get_db( 'dotflow_events' );

		if ( ! $db ) {
			return false;
		}

		$source   = sanitize_key( $event->get_source() );
		$event_id = sanitize_text_field( $event->get_id() );

		if ( '' === $source || '' === $event_id || strlen( $source ) > 64 || strlen( $event_id ) > 120 ) {
			return false;
		}

		$table    = $db->get_table_name();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$table} WHERE source = %s AND event_id = %s LIMIT 1",
				$source,
				$event_id
			)
		);

		if ( $existing_id ) {
			return absint( $existing_id );
		}

		$payload = self::redact_sensitive_values( $event->get_payload() );
		$encoded = wp_json_encode( $payload );

		if ( false === $encoded || strlen( $encoded ) > 65535 ) {
			do_action( 'dotflow/event/store_failed', $event, 'invalid_or_oversized_payload' );

			return false;
		}

		$occurred_timestamp = strtotime( $event->get_occurred_at() );

		if ( false === $occurred_timestamp ) {
			$occurred_timestamp = time();
		}

		$contact_id = isset( $payload['contact_id'] ) ? absint( $payload['contact_id'] ) : 0;
		$object_id  = isset( $payload['object_id'] ) ? absint( $payload['object_id'] ) : 0;
		$object_type = isset( $payload['object_type'] ) ? sanitize_key( $payload['object_type'] ) : '';

		$inserted_id = $db->insert( [
			'event_id'      => $event_id,
			'source'        => $source,
			'event_name'    => sanitize_text_field( $event->get_name() ),
			'object_type'   => $object_type,
			'object_id'     => $object_id,
			'contact_id'    => $contact_id,
			'payload'       => $encoded,
			'occurred_at'   => gmdate( 'Y-m-d H:i:s', $occurred_timestamp ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'actor_user_id' => get_current_user_id(),
		] );

		if ( $inserted_id ) {
			return absint( $inserted_id );
		}

		// A concurrent request may have inserted the same event after our first lookup.
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$table} WHERE source = %s AND event_id = %s LIMIT 1",
				$source,
				$event_id
			)
		);

		return $existing_id ? absint( $existing_id ) : false;
	}

	/**
	 * Recursively redact common credential fields before persistence.
	 *
	 * @param array $data Event payload.
	 * @return array
	 */
	private static function redact_sensitive_values( array $data ) {

		$redacted = [];

		foreach ( $data as $key => $value ) {
			$key_string = (string) $key;

			if ( preg_match( '/password|secret|token|api[_-]?key|authorization|cookie/i', $key_string ) ) {
				$redacted[ $key ] = '[REDACTED]';
				continue;
			}

			if ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact_sensitive_values( $value );
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$redacted[ $key ] = $value;
			}
		}

		return $redacted;
	}
}
