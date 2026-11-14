<?php

namespace Groundhogg\DotFlow\Inbound;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Provider-scoped inbound email endpoint.
 *
 * Every provider adapter must authenticate and normalize its request, after
 * which the canonical inbound validator and Message::ingest() handle the rest.
 */
final class Inbound_Provider_Webhook {

	const MAX_BODY_BYTES = 1048576;

	public static function register_route() {
		register_rest_route( 'dotflow/v1', '/inbound/email/(?P<provider>[a-z0-9_-]{1,48})', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'receive' ],
				'permission_callback' => [ __CLASS__, 'authorize' ],
			],
		] );
	}

	public static function authorize( WP_REST_Request $request ) {

		if ( ! Inbox_Addresses::is_enabled() ) {
			return new WP_Error( 'dotflow_inbound_provider_disabled', __( 'Independent inbound email is not configured.', 'groundhogg' ), [ 'status' => 503 ] );
		}

		if ( ! is_ssl() ) {
			return new WP_Error( 'dotflow_inbound_provider_https_required', __( 'Provider inbound webhooks require HTTPS.', 'groundhogg' ), [ 'status' => 403 ] );
		}

		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'dotflow_inbound_provider_payload_too_large', __( 'The provider inbound message exceeds the maximum accepted payload size.', 'groundhogg' ), [ 'status' => 413 ] );
		}

		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$adapter  = Inbound_Provider_Registry::get( $provider );

		if ( ! $adapter ) {
			return new WP_Error( 'dotflow_inbound_provider_unknown', __( 'The inbound email provider adapter is not registered.', 'groundhogg' ), [ 'status' => 404 ] );
		}

		$authorized = $adapter->authorize( $request );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		if ( true !== $authorized ) {
			return new WP_Error( 'dotflow_inbound_provider_unauthorized', __( 'The provider inbound webhook signature is invalid.', 'groundhogg' ), [ 'status' => 401 ] );
		}

		return true;
	}

	public static function receive( WP_REST_Request $request ) {

		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'dotflow_inbound_provider_invalid_json', __( 'The provider inbound webhook body must be a JSON object.', 'groundhogg' ), [ 'status' => 400 ] );
		}

		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$adapter  = Inbound_Provider_Registry::get( $provider );
		if ( ! $adapter ) {
			return new WP_Error( 'dotflow_inbound_provider_unknown', __( 'The inbound email provider adapter is not registered.', 'groundhogg' ), [ 'status' => 404 ] );
		}

		$normalized = $adapter->normalize( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		if ( ! is_array( $normalized ) ) {
			return new WP_Error( 'dotflow_inbound_provider_invalid_envelope', __( 'The inbound provider adapter returned an invalid message envelope.', 'groundhogg' ), [ 'status' => 400 ] );
		}

		return Inbound_Email_Webhook::ingest_normalized( $normalized );
	}
}
