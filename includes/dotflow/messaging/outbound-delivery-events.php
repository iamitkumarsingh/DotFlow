<?php

namespace Groundhogg\DotFlow\Messaging;

use Groundhogg\DotFlow\Events\Dispatcher;
use Groundhogg\DotFlow\Events\Event;
use WP_Error;

/**
 * Converts provider callback outcomes into privacy-minimised DotFlow events.
 *
 * A successful callback means the configured transport accepted the message,
 * not that the recipient's mailbox delivered it. Delivery, bounce and complaint
 * signals require provider-specific webhooks and are deliberately separate.
 */
final class Outbound_Delivery_Events {

	/**
	 * Attach to the existing Groundhogg transport lifecycle.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'groundhogg/email_services/transport_result', [ __CLASS__, 'record_result' ], 10, 4 );
		add_action( 'groundhogg/email_services/transport_exception', [ __CLASS__, 'record_exception' ], 10, 3 );
	}

	/**
	 * Record a provider callback result without storing message content or addresses.
	 *
	 * @param mixed  $result    Provider callback result.
	 * @param string $service   Registered service ID.
	 * @param string $channel   WordPress, transactional or marketing.
	 * @param string $message_id Outbound Message-ID, when supplied by the mailer.
	 * @return void
	 */
	public static function record_result( $result, $service, $channel, $message_id = '' ) {

		$accepted = true === $result;
		$error_code = '';

		if ( 'dotflow_postmark' === $service && class_exists( Postmark_Retry_Queue::class ) && Postmark_Retry_Queue::is_queued( (string) $message_id ) ) {
			self::dispatch( 'email.transport.queued', $service, $channel, $message_id, 'postmark_rate_limited' );
			return;
		}

		if ( is_wp_error( $result ) ) {
			$accepted   = false;
			$error_code = sanitize_key( (string) $result->get_error_code() );
		} elseif ( ! $accepted ) {
			$error_code = 'provider_rejected';
		}

		self::dispatch( $accepted ? 'email.transport.accepted' : 'email.transport.failed', $service, $channel, $message_id, $error_code );
	}

	/**
	 * Record an exception from a provider callback.
	 *
	 * Exception messages and traces are intentionally not persisted because
	 * third-party transports can include credentials or personal data in them.
	 *
	 * @param string $service    Provider ID.
	 * @param string $channel    Message channel.
	 * @param string $message_id Outbound Message-ID.
	 * @return void
	 */
	public static function record_exception( $service, $channel, $message_id = '' ) {
		self::dispatch( 'email.transport.failed', $service, $channel, $message_id, 'provider_exception' );
	}

	/**
	 * Persist one transport outcome. Observation failures must never change
	 * whether the original provider send succeeded or failed.
	 *
	 * @param string $event_name
	 * @param string $service
	 * @param string $channel
	 * @param string $message_id
	 * @param string $error_code
	 * @return void
	 */
	private static function dispatch( $event_name, $service, $channel, $message_id, $error_code = '' ) {

		$service = sanitize_key( (string) $service );
		$channel = sanitize_key( (string) $channel );

		if ( '' === $service || ! in_array( $channel, [ 'wordpress', 'transactional', 'marketing' ], true ) ) {
			return;
		}

		$fingerprint = $message_id !== '' ? hash( 'sha256', (string) $message_id ) : '';
		$event_id    = $fingerprint
			? substr( hash( 'sha256', $service . '|' . $channel . '|' . $event_name . '|' . $fingerprint ), 0, 64 )
			: wp_generate_uuid4();

		$payload = [
			'provider'           => $service,
			'channel'            => $channel,
			'outcome'            => 'email.transport.accepted' === $event_name ? 'accepted_by_transport' : ( 'email.transport.queued' === $event_name ? 'queued_for_retry' : 'failed' ),
			'message_fingerprint' => $fingerprint,
		];

		if ( $error_code !== '' ) {
			$payload['error_code'] = substr( sanitize_key( $error_code ), 0, 100 );
		}

		try {
			Dispatcher::dispatch( new Event( $event_name, $payload, 'email-transport', $event_id ) );
		} catch ( \Throwable $exception ) {
			// Telemetry is best-effort and must not break delivery or mask its result.
			do_action( 'dotflow/email/transport_observation_failed' );
		}
	}
}
