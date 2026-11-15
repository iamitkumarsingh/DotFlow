<?php

namespace Groundhogg\DotFlow\Analytics;

use Groundhogg\DotFlow\Events\Event;

/**
 * Consent-gated, privacy-minimised GA4 Measurement Protocol and GTM bridge.
 *
 * Nothing is sent until the site's consent manager explicitly grants analytics
 * consent through dotflow/analytics/consent_granted. No PII is accepted.
 */
final class Analytics_Forwarder {

	private static $gtm_events = [];

	const GA4_ENDPOINT = 'https://www.google-analytics.com/mp/collect';

	/** Register event and frontend integration hooks. */
	public static function register() {
		add_action( 'dotflow/event/recorded', [ __CLASS__, 'handle_event' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_gtm' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_gtm_events' ], 99 );
	}

	/**
	 * Process a newly persisted event. Duplicate events never reach this hook.
	 *
	 * @param int   $record_id Durable DotFlow event row ID.
	 * @param Event $event     Domain event.
	 */
	public static function handle_event( $record_id, $event ) {
		if ( ! $event instanceof Event || ! self::has_consent( $event ) ) {
			return;
		}

		$mapped = self::map_event( $event );
		if ( ! $mapped ) {
			return;
		}

		self::$gtm_events[] = $mapped;
		self::send_ga4( $event, $mapped );
		do_action( 'dotflow/analytics/event_ready', $mapped['event'], $mapped['event_id'] );
	}

	/**
	 * Explicit allowlist prevents arbitrary event payloads from reaching analytics.
	 *
	 * @param Event $event Domain event.
	 * @return array|false
	 */
	public static function map_event( Event $event ) {
		$names = [
			'form.submitted' => 'generate_lead',
			'page.viewed' => 'page_view',
			'cta.clicked' => 'select_content',
			'link.clicked' => 'click',
		];

		$name = $event->get_name();
		if ( ! isset( $names[ $name ] ) ) {
			return false;
		}

		$source = sanitize_key( $event->get_source() );
		$payload = $event->get_payload();
		$event_id = substr( hash( 'sha256', $source . '|' . $event->get_id() ), 0, 32 );
		$params = [
			'event_id' => $event_id,
			'dotflow_source' => $source,
		];

		if ( isset( $payload['page_path'] ) && is_string( $payload['page_path'] ) ) {
			$params['page_path'] = self::safe_path( $payload['page_path'] );
		} elseif ( isset( $payload['link_path'] ) && is_string( $payload['link_path'] ) ) {
			$params['link_url'] = self::safe_path( $payload['link_path'] );
		}
		if ( isset( $payload['form_id'] ) ) {
			$params['form_id'] = absint( $payload['form_id'] );
		}
		if ( isset( $payload['element_id'] ) && is_string( $payload['element_id'] ) ) {
			$params['element_id'] = sanitize_key( $payload['element_id'] );
		}
		if ( isset( $payload['channel'] ) && is_string( $payload['channel'] ) ) {
			$params['channel'] = sanitize_key( $payload['channel'] );
		}

		return [ 'event' => $names[ $name ], 'event_id' => $event_id, 'params' => $params ];
	}

	/** Report configuration state without returning identifiers or secrets. */
	public static function get_status() {
		$gtm = self::setting( 'DOTFLOW_GTM_CONTAINER_ID', 'dotflow/analytics/gtm_container_id' );
		return [
			'ga4_configured' => '' !== self::setting( 'DOTFLOW_GA4_MEASUREMENT_ID', 'dotflow/analytics/ga4_measurement_id' ) && '' !== self::setting( 'DOTFLOW_GA4_API_SECRET', 'dotflow/analytics/ga4_api_secret' ),
			'gtm_configured' => (bool) preg_match( '/^GTM-[A-Z0-9]+$/', $gtm ),
			'consent_granted' => self::has_consent(),
		];
	}

	/** Return whether analytics consent has explicitly been granted. */
	public static function has_consent( $event = null ) {
		return (bool) apply_filters( 'dotflow/analytics/consent_granted', false, $event );
	}

	/**
	 * Send a GA4 Measurement Protocol event when all server-side settings exist.
	 *
	 * The client ID is supplied by the consent manager/integration; DotFlow does
	 * not create or infer browser identifiers.
	 *
	 * @param Event $event Domain event.
	 * @param array $mapped Allowlisted event.
	 * @return bool
	 */
	public static function send_ga4( Event $event, array $mapped ) {
		if ( ! self::has_consent( $event ) ) {
			return false;
		}
		$measurement_id = self::setting( 'DOTFLOW_GA4_MEASUREMENT_ID', 'dotflow/analytics/ga4_measurement_id' );
		$api_secret = self::setting( 'DOTFLOW_GA4_API_SECRET', 'dotflow/analytics/ga4_api_secret' );
		$client_id = apply_filters( 'dotflow/analytics/client_id', '', $event );

		if ( '' === $measurement_id || '' === $api_secret || ! is_string( $client_id ) || ! preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $client_id ) ) {
			return false;
		}

		$url = add_query_arg(
			[ 'measurement_id' => $measurement_id, 'api_secret' => $api_secret ],
			self::GA4_ENDPOINT
		);
		$response = wp_remote_post( $url, [
			'timeout' => 2,
			'blocking' => false,
			'redirection' => 0,
			'reject_unsafe_urls' => true,
			'sslverify' => true,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body' => wp_json_encode( [
				'client_id' => $client_id,
				'events' => [ [
					'name' => $mapped['event'],
					'params' => $mapped['params'],
				] ],
			] ),
		] );

		return ! is_wp_error( $response );
	}

	/** Enqueue GTM only when analytics consent and a container ID are present. */
	public static function enqueue_gtm() {
		if ( ! self::has_consent() ) {
			return;
		}
		$container = self::setting( 'DOTFLOW_GTM_CONTAINER_ID', 'dotflow/analytics/gtm_container_id' );
		if ( ! preg_match( '/^GTM-[A-Z0-9]+$/', $container ) ) {
			return;
		}

		wp_enqueue_script( 'dotflow-google-tag-manager', 'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode( $container ), [], null, false );
		wp_add_inline_script(
			'dotflow-google-tag-manager',
			'window.dataLayer=window.dataLayer||[];window.dataLayer.push({"event":"dotflow_analytics_consent_granted"});',
			'before'
		);
	}

	/** Print only the event fields created by the allowlist mapper. */
	public static function render_gtm_events() {
		if ( empty( self::$gtm_events ) || ! self::has_consent() ) {
			return;
		}
		$json = wp_json_encode( self::$gtm_events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $json ) ) {
			return;
		}
		echo '<script>window.dataLayer=window.dataLayer||[];(' . 'function(events){events.forEach(function(item){window.dataLayer.push({event:item.event,event_id:item.event_id,dotflow_source:item.params.dotflow_source,page_path:item.params.page_path||item.params.link_url||"",form_id:item.params.form_id||0,element_id:item.params.element_id||"",channel:item.params.channel||""});});}' . ')(' . $json . ');</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is encoded with JSON_HEX flags and contains only allowlisted values.
	}

	/** Resolve configuration from wp-config constants or server-side filters. */
	private static function setting( $constant, $filter ) {
		$value = defined( $constant ) ? (string) constant( $constant ) : '';
		$value = apply_filters( $filter, $value );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/** Reduce URL-like values to a local path, stripping query strings and fragments. */
	private static function safe_path( $value ) {
		$path = wp_parse_url( (string) $value, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}
		return substr( '/' . ltrim( sanitize_text_field( $path ), '/' ), 0, 500 );
	}
}
