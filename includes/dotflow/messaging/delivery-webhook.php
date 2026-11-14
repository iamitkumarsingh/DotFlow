<?php

namespace Groundhogg\DotFlow\Messaging;

use Groundhogg\DotFlow\Events\Dispatcher;
use Groundhogg\DotFlow\Events\Event;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Authenticated provider-neutral endpoint for outbound delivery status events.
 *
 * Provider-specific adapters own signature verification and payload mapping.
 * DotFlow persists only status metadata and a one-way Message-ID fingerprint.
 */
final class Delivery_Webhook {

	const MAX_BODY_BYTES = 1048576;

	/**
	 * Register the provider-scoped delivery webhook route.
	 *
	 * @return void
	 */
	public static function register_route() {

		register_rest_route( 'dotflow/v1', '/email/delivery/(?P<provider>[a-z0-9_-]{1,48})', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'receive' ],
				'permission_callback' => [ __CLASS__, 'authorize' ],
			],
		] );
	}

	/**
	 * Resolve and invoke the provider adapter's authentication contract.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ) {

		if ( ! is_ssl() ) {
			return new WP_Error(
				'dotflow_delivery_https_required',
				__( 'Delivery webhooks require HTTPS.', 'groundhogg' ),
				[ 'status' => 403 ]
			);
		}

		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return new WP_Error(
				'dotflow_delivery_payload_too_large',
				__( 'The delivery webhook exceeds the maximum accepted payload size.', 'groundhogg' ),
				[ 'status' => 413 ]
			);
		}

		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$adapter  = Provider_Adapter_Registry::get( $provider );

		if ( ! $adapter ) {
			return new WP_Error(
				'dotflow_delivery_provider_unknown',
				__( 'The delivery provider adapter is not registered.', 'groundhogg' ),
				[ 'status' => 404 ]
			);
		}

		$authorized = $adapter->authorize( $request );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		if ( true !== $authorized ) {
			return new WP_Error(
				'dotflow_delivery_unauthorized',
				__( 'The delivery webhook signature is invalid.', 'groundhogg' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Normalize and persist one delivery status notification.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive( WP_REST_Request $request ) {

		$input = $request->get_json_params();

		if ( ! is_array( $input ) ) {
			return new WP_Error(
				'dotflow_delivery_invalid_json',
				__( 'The delivery webhook body must be a JSON object.', 'groundhogg' ),
				[ 'status' => 400 ]
			);
		}

		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$adapter  = Provider_Adapter_Registry::get( $provider );

		if ( ! $adapter ) {
			return new WP_Error(
				'dotflow_delivery_provider_unknown',
				__( 'The delivery provider adapter is not registered.', 'groundhogg' ),
				[ 'status' => 404 ]
			);
		}

		$normalized = $adapter->normalize( $input );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$validated = self::validate_envelope( $normalized );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$message_fingerprint = hash( 'sha256', $validated['message_id'] );
		$source              = 'delivery-' . $provider;
		$event_name          = 'email.delivery.' . $validated['status'];

		$event = new Event(
			$event_name,
			[
				'provider'            => $provider,
				'channel'             => $validated['channel'],
				'status'              => $validated['status'],
				'message_fingerprint' => $message_fingerprint,
				'error_code'          => $validated['error_code'],
			],
			$source,
			$validated['event_id']
		);

		// Dispatcher persists with source-scoped idempotency and suppresses duplicate listeners.
		Dispatcher::dispatch( $event );

		return new WP_REST_Response( [
			'accepted' => true,
			'status'   => $validated['status'],
		], 'postmark' === $provider ? 200 : 202 );
	}

	/**
	 * Validate the adapter output, not the provider's untrusted raw payload.
	 *
	 * @param mixed $data Normalized adapter output.
	 * @return array|WP_Error
	 */
	private static function validate_envelope( $data ) {

		if ( ! is_array( $data ) ) {
			return self::invalid_envelope();
		}

		$event_id  = isset( $data['event_id'] ) && is_scalar( $data['event_id'] ) ? trim( (string) $data['event_id'] ) : '';
		$message_id = isset( $data['message_id'] ) && is_string( $data['message_id'] ) ? trim( $data['message_id'] ) : '';
		$status    = isset( $data['status'] ) && is_string( $data['status'] ) ? sanitize_key( $data['status'] ) : '';
		$channel   = isset( $data['channel'] ) && is_string( $data['channel'] ) ? sanitize_key( $data['channel'] ) : '';
		$error_code = isset( $data['error_code'] ) && is_scalar( $data['error_code'] ) ? sanitize_key( (string) $data['error_code'] ) : '';

		if ( '' === $event_id || strlen( $event_id ) > 120 || ! preg_match( '/^[A-Za-z0-9._:-]{1,120}$/', $event_id ) ) {
			return self::invalid_envelope();
		}

		if ( strlen( $message_id ) > 998 || ! preg_match( '/^<[^<>\s@]+@[^<>\s@]+>$/', $message_id ) ) {
			return self::invalid_envelope();
		}

		if ( ! in_array( $status, [ 'delivered', 'bounced', 'complained', 'deferred' ], true ) ) {
			return self::invalid_envelope();
		}

		if ( ! in_array( $channel, [ 'wordpress', 'transactional', 'marketing' ], true ) ) {
			return self::invalid_envelope();
		}

		return [
			'event_id'   => $event_id,
			'message_id' => $message_id,
			'status'     => $status,
			'channel'    => $channel,
			'error_code' => substr( $error_code, 0, 100 ),
		];
	}

	/**
	 * Return a generic validation error without echoing provider data.
	 *
	 * @return WP_Error
	 */
	private static function invalid_envelope() {
		return new WP_Error(
			'dotflow_delivery_invalid_envelope',
			__( 'The provider adapter returned an invalid delivery status envelope.', 'groundhogg' ),
			[ 'status' => 400 ]
		);
	}
}
