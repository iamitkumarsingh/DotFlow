<?php

namespace Groundhogg\DotFlow\Steps\Actions;

use Groundhogg\Event;
use Groundhogg\Step;
use Groundhogg\Steps\Actions\Action;
use function Groundhogg\html;

/**
 * Send a minimal, privacy-conscious webhook from a workflow.
 *
 * The destination is configured by a flow editor with permission to edit the
 * flow. HTTPS only; WordPress safe HTTP validation blocks private/reserved
 * destinations at request time, and redirects are disabled.
 */
class DotFlow_Webhook extends Action {

	public function get_name() {
		return esc_html_x( 'DotFlow Webhook', 'step_name', 'groundhogg' );
	}

	public function get_type() {
		return 'dotflow_webhook';
	}

	public function get_sub_group() {
		return 'developer';
	}

	public function get_description() {
		return esc_html_x( 'Send a minimal JSON event to an HTTPS endpoint. Contact message content and event arguments are never included.', 'step_description', 'groundhogg' );
	}

	public function get_icon() {
		return GROUNDHOGG_ASSETS_URL . 'images/funnel-icons/developer/webhook.svg';
	}

	public function settings( $step ) {
		html( 'p', [], esc_html__( 'Send a minimal event payload to an HTTPS endpoint. Private/local destinations and redirects are blocked. No contact email, message body, or workflow event arguments are sent.', 'groundhogg' ) );
		html( 'label', [ 'for' => $this->setting_id_prefix( 'url' ) ], esc_html__( 'HTTPS endpoint URL', 'groundhogg' ) );
		html( 'input', [
			'type'        => 'url',
			'id'          => $this->setting_id_prefix( 'url' ),
			'name'        => $this->setting_name_prefix( 'url' ),
			'value'       => $this->get_setting( 'url' ),
			'placeholder' => 'https://example.com/dotflow-webhook',
			'class'       => 'input',
		] );
		html( 'p' );
	}

	public function get_settings_schema() {
		return [
			'url' => [
				'default'  => '',
				'sanitize' => function ( $value ) {
					return esc_url_raw( trim( (string) $value ), [ 'https' ] );
				},
			],
		];
	}

	public function validate_settings( Step $step ) {
		$url = (string) $this->get_setting( 'url' );
		if ( ! self::is_safe_url( $url ) ) {
			$step->add_error( 'dotflow_webhook_url_invalid', __( 'Enter a valid HTTPS webhook URL using the standard HTTPS port. Local/private IP addresses and embedded credentials are not allowed.', 'groundhogg' ) );
		}
	}

	/**
	 * Validate the URL's structure. wp_safe_remote_post performs the additional
	 * DNS/IP safety validation immediately before the request.
	 */
	public static function is_safe_url( $url ): bool {
		if ( ! is_string( $url ) || '' === trim( $url ) || strlen( $url ) > 2048 ) {
			return false;
		}

		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		if ( isset( $parts['port'] ) && 443 !== absint( $parts['port'] ) ) {
			return false;
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) || '.local' === substr( $host, -6 ) || '.internal' === substr( $host, -9 ) ) {
			return false;
		}

		$ip_host = trim( $host, '[]' );
		if ( filter_var( $ip_host, FILTER_VALIDATE_IP ) ) {
			$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
			if ( ! filter_var( $ip_host, FILTER_VALIDATE_IP, $flags ) ) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Shared signing key is configured outside the database (wp-config.php or
	 * a server-side filter) and is never exposed through the flow editor.
	 */
	public static function get_secret(): string {
		$secret = defined( 'DOTFLOW_WEBHOOK_SECRET' ) ? DOTFLOW_WEBHOOK_SECRET : '';
		$secret = apply_filters( 'dotflow/webhook/secret', $secret );
		return is_string( $secret ) ? trim( $secret ) : '';
	}

	public function run( $contact, $event ) {
		if ( ! $contact || ! $contact->exists() || ! $event instanceof Event ) {
			return new \WP_Error( 'dotflow_webhook_context_invalid', __( 'The webhook step requires a valid contact and workflow event.', 'groundhogg' ) );
		}

		$url = (string) $this->get_setting( 'url' );
		if ( ! self::is_safe_url( $url ) ) {
			return new \WP_Error( 'dotflow_webhook_url_invalid', __( 'The configured webhook URL is invalid.', 'groundhogg' ) );
		}


		$secret = self::get_secret();
		if ( strlen( $secret ) < 32 ) {
			return new \WP_Error( 'dotflow_webhook_secret_missing', __( 'Configure DOTFLOW_WEBHOOK_SECRET with at least 32 random characters before enabling webhook steps.', 'groundhogg' ) );
		}

		$payload = [
			'schema_version' => 1,
			'event'           => 'automation.step.completed',
			'contact_id'      => absint( $contact->get_id() ),
			'flow_id'         => absint( $event->get_funnel_id() ),
			'step_id'         => absint( $event->get_step_id() ),
			'event_id'        => absint( $event->get_id() ),
			'occurred_at'     => gmdate( 'c' ),
		];

		$body = wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $body, $secret );
		$response = wp_safe_remote_post( $url, [
			'timeout'             => 10,
			'redirection'         => 0,
			'blocking'            => true,
			'httpversion'         => '1.1',
			'headers'             => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json; charset=utf-8',
				'User-Agent'          => 'DotFlow/' . ( defined( 'DOTFLOW_VERSION' ) ? DOTFLOW_VERSION : 'dev' ),
				'X-DotFlow-Event'     => 'automation.step.completed',
				'X-DotFlow-Delivery'  => (string) absint( $event->get_id() ),
				'X-DotFlow-Signature' => 'sha256=' . $signature,
			],
			'body'                => $body,
			'data_format'         => 'body',
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => 1024,
		] );

		if ( is_wp_error( $response ) ) {
			do_action( 'dotflow/automation/webhook/failed', $contact->get_id(), $event->get_funnel_id(), $event->get_step_id(), 'transport_error' );
			return new \WP_Error( 'dotflow_webhook_transport_error', __( 'The webhook request could not be completed safely.', 'groundhogg' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			do_action( 'dotflow/automation/webhook/failed', $contact->get_id(), $event->get_funnel_id(), $event->get_step_id(), 'http_' . $status );
			return new \WP_Error( 'dotflow_webhook_http_error', sprintf( __( 'The webhook endpoint returned HTTP %d.', 'groundhogg' ), $status ) );
		}

		do_action( 'dotflow/automation/webhook/sent', $contact->get_id(), $event->get_funnel_id(), $event->get_step_id(), $status );
		return true;
	}

	public function generate_step_title( $step ) {
		$parts = wp_parse_url( (string) $this->get_setting( 'url' ) );
		$host = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
		return $host
			? sprintf( esc_html__( 'Send webhook to %s', 'groundhogg' ), '<b>' . esc_html( $host ) . '</b>' )
			: esc_html__( 'Send DotFlow webhook', 'groundhogg' );
	}
}
