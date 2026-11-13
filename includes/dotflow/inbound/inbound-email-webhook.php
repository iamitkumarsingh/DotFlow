<?php

namespace Groundhogg\DotFlow\Inbound;

use Groundhogg\Classes\Message;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Authenticated, provider-neutral inbound email webhook.
 *
 * The provider supplies a normalized envelope. Groundhogg's Message::ingest()
 * remains responsible for address routing, reply-token verification, contact
 * matching, automatic-reply filtering, duplicate detection, threading and
 * message persistence. This endpoint does not provision or call the paid relay.
 */
final class Inbound_Email_Webhook {

	const MAX_BODY_BYTES = 1048576;

	/**
	 * Register the public-but-token-authenticated webhook route.
	 */
	public static function register_route() {

		register_rest_route( 'dotflow/v1', '/inbound/email', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'receive' ],
				'permission_callback' => [ __CLASS__, 'authorize' ],
			],
		] );
	}

	/**
	 * Authenticate with a server-side bearer token over HTTPS.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ) {

		if ( ! Inbox_Addresses::is_enabled() ) {
			return new WP_Error(
				'dotflow_inbound_disabled',
				__( 'Independent inbound email is not configured.', 'groundhogg' ),
				[ 'status' => 503 ]
			);
		}

		if ( ! is_ssl() ) {
			return new WP_Error(
				'dotflow_inbound_https_required',
				__( 'Inbound email webhooks require HTTPS.', 'groundhogg' ),
				[ 'status' => 403 ]
			);
		}

		$provided = trim( (string) $request->get_header( 'x-dotflow-token' ) );

		if ( $provided === '' ) {
			$authorization = trim( (string) $request->get_header( 'authorization' ) );

			if ( preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
				$provided = trim( $matches[1] );
			}
		}

		$expected = Inbox_Addresses::webhook_token();

		if ( $provided === '' || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error(
				'dotflow_inbound_unauthorized',
				__( 'The inbound webhook token is invalid.', 'groundhogg' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Normalize and ingest one provider message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive( WP_REST_Request $request ) {

		$body = (string) $request->get_body();

		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new WP_Error(
				'dotflow_inbound_payload_too_large',
				__( 'The inbound message exceeds the maximum accepted payload size.', 'groundhogg' ),
				[ 'status' => 413 ]
			);
		}

		$input = $request->get_json_params();

		if ( ! is_array( $input ) ) {
			return new WP_Error(
				'dotflow_inbound_invalid_json',
				__( 'The inbound webhook body must be a JSON object.', 'groundhogg' ),
				[ 'status' => 400 ]
			);
		}

		$payload = self::normalize_payload( $input );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$message = Message::ingest( $payload );

		if ( is_wp_error( $message ) ) {
			$code = $message->get_error_code();

			if ( 'duplicate' === $code ) {
				return new WP_REST_Response( [
					'accepted' => true,
					'duplicate' => true,
				], 200 );
			}

			if ( 'ignored' === $code ) {
				return new WP_REST_Response( [
					'accepted' => true,
					'ignored'  => true,
				], 202 );
			}

			if ( 'failed' === $code ) {
				return new WP_Error(
					'dotflow_inbound_storage_failed',
					__( 'The inbound message could not be stored. The provider may retry delivery.', 'groundhogg' ),
					[ 'status' => 503 ]
				);
			}

			return new WP_REST_Response( [
				'accepted' => false,
				'reason'   => sanitize_key( $code ),
			], 202 );
		}

		if ( ! $message instanceof Message ) {
			return new WP_Error(
				'dotflow_inbound_storage_failed',
				__( 'The inbound message could not be stored. The provider may retry delivery.', 'groundhogg' ),
				[ 'status' => 503 ]
			);
		}

		return new WP_REST_Response( [
			'accepted'           => true,
			'message_record_id'  => absint( $message->get_id() ),
		], 201 );
	}

	/**
	 * Validate the normalized provider envelope.
	 *
	 * Only the headers Groundhogg needs to suppress automated replies are
	 * retained. Authentication headers and arbitrary provider headers are not
	 * forwarded to the message store or event stream.
	 *
	 * @param array $input Raw JSON payload.
	 * @return array|WP_Error
	 */
	private static function normalize_payload( array $input ) {

		$from = $input['from'] ?? '';

		if ( ! is_string( $from ) && ! is_array( $from ) ) {
			return self::invalid_payload( 'A sender address is required.' );
		}

		[ $sender ] = Message::parse_address( $from );

		if ( ! is_email( $sender ) ) {
			return self::invalid_payload( 'A valid sender address is required.' );
		}

		$envelope_to = $input['envelope_to'] ?? '';

		if ( ! is_string( $envelope_to ) && ! is_array( $envelope_to ) ) {
			return self::invalid_payload( 'The SMTP envelope recipient is required.' );
		}

		$envelope_addresses = Message::parse_addresses( $envelope_to );

		if ( empty( $envelope_addresses ) ) {
			return self::invalid_payload( 'A valid SMTP envelope recipient is required.' );
		}

		foreach ( [ 'to', 'cc' ] as $field ) {
			$value = $input[ $field ] ?? '';

			if ( ! is_string( $value ) && ! is_array( $value ) ) {
				return self::invalid_payload( 'Recipient fields must be strings or address lists.' );
			}
		}

		$html = $input['html'] ?? '';
		$text = $input['text'] ?? '';

		if ( ! is_string( $html ) || ! is_string( $text ) || strlen( $html ) > self::MAX_BODY_BYTES || strlen( $text ) > self::MAX_BODY_BYTES ) {
			return self::invalid_payload( 'Message content must be text or HTML within the accepted size limit.' );
		}

		if ( ! empty( $input['attachments'] ) ) {
			return self::invalid_payload( 'Attachments are not supported by this inbound webhook version.' );
		}

		$provider = sanitize_key( $input['source'] ?? 'provider' );
		$provider = substr( $provider ?: 'provider', 0, 64 );

		$provider_event_id = isset( $input['provider_event_id'] ) ? sanitize_text_field( (string) $input['provider_event_id'] ) : '';
		$message_id        = isset( $input['message_id'] ) ? trim( (string) $input['message_id'] ) : '';

		// A stable provider event ID supplies a deterministic Message-ID when the
		// transport does not preserve the original RFC Message-ID header.
		if ( $message_id === '' && $provider_event_id !== '' ) {
			$message_id = '<dotflow-' . hash( 'sha256', $provider . ':' . $provider_event_id ) . '@dotflow.local>';
		}

		$message_ids = Message::parse_message_ids( $message_id );

		if ( count( $message_ids ) !== 1 || ! preg_match( '/^<[^<>\s@]+@[^<>\s@]+>$/', $message_ids[0] ) ) {
			return self::invalid_payload( 'A valid Message-ID or provider event ID is required for duplicate protection.' );
		}

		$headers = self::normalize_headers( $input['headers'] ?? [] );

		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$date = $input['date'] ?? '';

		if ( ! is_string( $date ) && ! is_numeric( $date ) ) {
			return self::invalid_payload( 'The message date must be a string or timestamp.' );
		}

		return [
			'from'          => $from,
			'to'            => $input['to'] ?? '',
			'cc'            => $input['cc'] ?? '',
			'envelope_to'   => $envelope_addresses,
			'subject'       => sanitize_text_field( (string) ( $input['subject'] ?? '' ) ),
			'html'          => $html,
			'text'          => $text,
			'message_id'    => $message_ids[0],
			'in_reply_to'   => $input['in_reply_to'] ?? '',
			'references'    => $input['references'] ?? '',
			'date'          => (string) $date,
			'headers'       => $headers,
			'dotflow_source' => $provider,
		];
	}

	/**
	 * Keep only bounded auto-reply headers.
	 *
	 * @param mixed $headers Headers object.
	 * @return array|WP_Error
	 */
	private static function normalize_headers( $headers ) {

		if ( ! is_array( $headers ) ) {
			return self::invalid_payload( 'Message headers must be an object.' );
		}

		$allowed = [ 'auto-submitted', 'precedence', 'x-autoreply', 'x-autorespond' ];
		$clean   = [];

		foreach ( $headers as $name => $value ) {
			$name = strtolower( trim( (string) $name ) );

			if ( ! in_array( $name, $allowed, true ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) );
			} elseif ( is_scalar( $value ) ) {
				$value = sanitize_text_field( (string) $value );
			} else {
				continue;
			}

			$clean[ $name ] = substr( $value, 0, 500 );
		}

		return $clean;
	}

	/**
	 * Create a generic validation error without echoing submitted message data.
	 *
	 * @param string $message Safe validation message.
	 * @return WP_Error
	 */
	private static function invalid_payload( string $message ): WP_Error {
		return new WP_Error( 'dotflow_inbound_invalid_payload', __( $message, 'groundhogg' ), [ 'status' => 400 ] );
	}
}
