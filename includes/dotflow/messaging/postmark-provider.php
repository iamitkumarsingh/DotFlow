<?php

namespace Groundhogg\DotFlow\Messaging;

use Groundhogg\DotFlow\Inbound\Inbound_Email_Provider_Adapter;
use Groundhogg\DotFlow\Inbound\Inbound_Provider_Registry;
use WP_Error;
use WP_REST_Request;

/**
 * Postmark integration. Secrets are supplied through wp-config.php constants
 * or server-side filters and are never stored in the WordPress options table.
 */
final class Postmark_Provider {

	const SERVICE = 'dotflow_postmark';
	const OPTION = 'dotflow_postmark_settings';
	const API = 'https://api.postmarkapp.com/email';

	public static function boot() {
		add_action( 'Groundhogg/email_services/init', [ __CLASS__, 'register_service' ] );
		add_action( 'dotflow/email/delivery_adapters/register', [ __CLASS__, 'register_delivery' ] );
		add_action( 'dotflow/email/inbound_adapters/register', [ __CLASS__, 'register_inbound' ] );
		Postmark_Retry_Queue::register();
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ], 30 );
		add_action( 'admin_post_dotflow_postmark_test', [ __CLASS__, 'test_connection' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
	}

	public static function register_service() {
		if ( class_exists( '\\Groundhogg_Email_Services' ) ) {
			\Groundhogg_Email_Services::register( self::SERVICE, __( 'Postmark (DotFlow)', 'groundhogg' ), [ __CLASS__, 'send' ] );
		}
	}
	public static function register_delivery() { Provider_Adapter_Registry::register( new Postmark_Delivery_Adapter() ); }
	public static function register_inbound() { Inbound_Provider_Registry::register( new Postmark_Inbound_Adapter() ); }

	public static function secret( string $key ): string {
		$constants = [
			'server_token' => 'DOTFLOW_POSTMARK_SERVER_TOKEN',
			'webhook_secret' => 'DOTFLOW_POSTMARK_WEBHOOK_SECRET',
		];
		$value = isset( $constants[ $key ] ) && defined( $constants[ $key ] ) ? (string) constant( $constants[ $key ] ) : '';
		$value = apply_filters( 'dotflow/postmark/' . $key, $value );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			$options = get_option( self::OPTION, [] );
			$stored = is_array( $options ) ? ( $options[ $key ] ?? '' ) : '';
			$value = is_string( $stored ) ? self::decrypt_secret( $stored ) : '';
		}
		return is_string( $value ) ? trim( $value ) : '';
	}

	public static function authorize( WP_REST_Request $request ) {
		$secret = self::secret( 'webhook_secret' );
		$header = trim( (string) $request->get_header( 'authorization' ) );
		if ( '' === $secret || 0 !== stripos( $header, 'Basic ' ) ) {
			return new WP_Error( 'dotflow_postmark_unauthorized', __( 'Postmark webhook authentication failed.', 'groundhogg' ), [ 'status' => 401 ] );
		}
		$decoded = base64_decode( trim( substr( $header, 6 ) ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return new WP_Error( 'dotflow_postmark_unauthorized', __( 'Postmark webhook authentication failed.', 'groundhogg' ), [ 'status' => 401 ] );
		}
		[ $user, $pass ] = explode( ':', $decoded, 2 );
		return hash_equals( 'dotflow', $user ) && hash_equals( $secret, $pass )
			? true
			: new WP_Error( 'dotflow_postmark_unauthorized', __( 'Postmark webhook authentication failed.', 'groundhogg' ), [ 'status' => 401 ] );
	}

	public static function normalize_delivery( array $data ) {
		$id = isset( $data['MessageID'] ) && is_string( $data['MessageID'] ) ? strtolower( preg_replace( '/[^a-f0-9-]/i', '', $data['MessageID'] ) ) : '';
		if ( '' === $id ) { return new WP_Error( 'dotflow_postmark_message_id_missing', __( 'Postmark webhook is missing its message identifier.', 'groundhogg' ), [ 'status' => 400 ] ); }
		$type = strtolower( (string) ( $data['Type'] ?? '' ) );
		if ( ! empty( $data['DeliveredAt'] ) ) {
			$status = 'delivered'; $suffix = (string) $data['DeliveredAt'];
		} elseif ( false !== strpos( $type, 'spam' ) || false !== strpos( strtolower( (string) ( $data['Name'] ?? '' ) ), 'spam' ) ) {
			$status = 'complained'; $suffix = (string) ( $data['ID'] ?? $data['BouncedAt'] ?? $type );
		} elseif ( isset( $data['BouncedAt'] ) || isset( $data['TypeCode'] ) || isset( $data['Type'] ) ) {
			$status = 'bounced'; $suffix = (string) ( $data['ID'] ?? $data['BouncedAt'] ?? $type );
		} else { return new WP_Error( 'dotflow_postmark_event_unknown', __( 'Unsupported Postmark event.', 'groundhogg' ), [ 'status' => 400 ] ); }
		$meta = isset( $data['Metadata'] ) && is_array( $data['Metadata'] ) ? $data['Metadata'] : [];
		$channel = sanitize_key( (string) ( $meta['dotflow_channel'] ?? '' ) );
		if ( ! in_array( $channel, [ 'wordpress', 'transactional', 'marketing' ], true ) ) {
			$channel = 'broadcasts' === sanitize_key( (string) ( $data['MessageStream'] ?? '' ) ) ? 'marketing' : 'transactional';
		}
		$event = isset( $data['ID'] ) && is_scalar( $data['ID'] )
			? 'postmark-' . sanitize_key( (string) $data['ID'] )
			: 'postmark-' . substr( hash( 'sha256', $id . '|' . $status . '|' . $suffix ), 0, 48 );
		return [ 'event_id' => $event, 'message_id' => ( isset( $meta['dotflow_message_id'] ) && is_string( $meta['dotflow_message_id'] ) && preg_match( '/^<[^<>\\s@]+@[^<>\\s@]+>$/', $meta['dotflow_message_id'] ) ) ? $meta['dotflow_message_id'] : '<' . $id . '@postmark.dotflow>', 'status' => $status, 'channel' => $channel, 'error_code' => isset( $data['TypeCode'] ) && is_numeric( $data['TypeCode'] ) ? 'postmark_' . absint( $data['TypeCode'] ) : '' ];
	}

	public static function normalize_inbound( array $data ) {
		if ( ! empty( $data['Attachments'] ) ) {
			return new WP_Error( 'dotflow_postmark_attachments_unsupported', __( 'Inbound attachments are not supported until secure scanning and retention are implemented.', 'groundhogg' ), [ 'status' => 415 ] );
		}
		$from = isset( $data['From'] ) && is_string( $data['From'] ) ? $data['From'] : '';
		$to = isset( $data['To'] ) && is_string( $data['To'] ) ? $data['To'] : '';
		$id = isset( $data['MessageID'] ) && is_string( $data['MessageID'] ) ? trim( $data['MessageID'] ) : '';
		if ( '' === $from || '' === $to || '' === $id || strlen( $id ) > 120 ) {
			return new WP_Error( 'dotflow_postmark_inbound_invalid', __( 'Postmark inbound message is missing required routing information.', 'groundhogg' ), [ 'status' => 400 ] );
		}
		return [ 'provider_event_id' => 'postmark-' . sanitize_key( $id ), 'from' => $from, 'to' => $to, 'envelope_to' => $to, 'cc' => (string) ( $data['Cc'] ?? '' ), 'subject' => (string) ( $data['Subject'] ?? '' ), 'text' => (string) ( $data['TextBody'] ?? '' ), 'html' => (string) ( $data['HtmlBody'] ?? '' ), 'source' => 'postmark-inbound' ];
	}

	public static function send( $to, $subject, $message, $headers = '', $attachments = [] ) {
		if ( '' === self::secret( 'server_token' ) ) { return self::fail( 'postmark_not_configured' ); }
		if ( ! empty( $attachments ) ) { return self::fail( 'postmark_attachments_not_supported' ); }
		$headers = self::parse_headers( $headers );
		$to = self::emails( $to ); $cc = self::emails( $headers['cc'] ?? [] ); $bcc = self::emails( $headers['bcc'] ?? [] );
		if ( empty( $to ) || count( $to ) + count( $cc ) + count( $bcc ) > 50 ) { return self::fail( 'postmark_invalid_recipients' ); }
		$from = $headers['from'] ?? ( get_bloginfo( 'name' ) . ' <' . sanitize_email( get_option( 'admin_email' ) ) . '>' );
		if ( ! is_email( self::extract_email( $from ) ) || strlen( $from ) > 255 ) { return self::fail( 'postmark_invalid_sender' ); }
		$channel = class_exists( '\Groundhogg_Email_Services' ) ? (string) \Groundhogg_Email_Services::get_current_message_type() : 'transactional';
		if ( ! in_array( $channel, [ 'wordpress', 'transactional', 'marketing' ], true ) ) { $channel = 'transactional'; }
		$stream = 'marketing' === $channel ? 'broadcasts' : 'outbound';
		$stream = sanitize_key( apply_filters( 'dotflow/postmark/message_stream/' . $channel, $stream ) ) ?: $stream;
		$body = is_string( $message ) ? $message : '';
		$html = false !== strpos( strtolower( (string) ( $headers['content-type'] ?? '' ) ), 'text/html' ) || (bool) preg_match( '/<[^>]+>/', $body );
		$message_id = class_exists( '\Groundhogg_Email_Services' ) ? (string) \Groundhogg_Email_Services::get_message_id() : '';
		if ( ! preg_match( '/^<[^<>\s@]+@[^<>\s@]+>$/', $message_id ) ) {
			$message_id = '<' . wp_generate_uuid4() . '@dotflow.local>';
			if ( class_exists( '\Groundhogg_Email_Services' ) ) { \Groundhogg_Email_Services::set_message_id( $message_id ); }
		}
		$payload = [ 'From' => $from, 'To' => implode( ',', $to ), 'Subject' => substr( (string) $subject, 0, 2000 ), 'MessageStream' => $stream, 'Metadata' => [ 'dotflow_channel' => $channel, 'dotflow_message_id' => $message_id ], 'Tag' => 'dotflow' ];
		if ( $cc ) { $payload['Cc'] = implode( ',', $cc ); } if ( $bcc ) { $payload['Bcc'] = implode( ',', $bcc ); }
		if ( ! empty( $headers['reply-to'] ) ) { $reply = self::emails( $headers['reply-to'] ); if ( 1 !== count( $reply ) ) { return self::fail( 'postmark_invalid_reply_to' ); } $payload['ReplyTo'] = $reply[0]; }
		if ( $html ) { $payload['HtmlBody'] = $body; $payload['TextBody'] = trim( wp_strip_all_tags( $body ) ); } else { $payload['TextBody'] = $body; }

		$reservation = Postmark_Retry_Queue::reserve( $payload, $message_id );
		if ( ! is_array( $reservation ) ) { return self::fail( 'postmark_outbox_unavailable' ); }
		if ( ! empty( $reservation['duplicate'] ) ) {
			return in_array( $reservation['status'], [ 'pending', 'processing', 'accepted' ], true );
		}

		$result = self::request_payload( $payload );
		if ( ! empty( $result['accepted'] ) ) {
			Postmark_Retry_Queue::complete( $reservation, 'accepted', '', (string) $result['provider_id'] );
			return true;
		}
		if ( ! empty( $result['retryable'] ) ) {
			if ( Postmark_Retry_Queue::schedule_retry( $reservation, 0, (string) $result['error_code'] ) ) { return true; }
			Postmark_Retry_Queue::complete( $reservation, 'uncertain', 'postmark_retry_schedule_failed' );
			return self::fail( 'postmark_retry_schedule_failed' );
		}
		Postmark_Retry_Queue::complete( $reservation, ! empty( $result['uncertain'] ) ? 'uncertain' : 'failed', (string) $result['error_code'] );
		return self::fail( (string) $result['error_code'] );
	}

	/**
	 * Submit a previously validated payload to Postmark. Only HTTP 429 is
	 * considered safely retryable; transport errors and 5xx are ambiguous.
	 */
	public static function request_payload( array $payload ): array {
		$token = self::secret( 'server_token' );
		if ( '' === $token ) {
			return [ 'accepted' => false, 'retryable' => false, 'uncertain' => false, 'error_code' => 'postmark_not_configured' ];
		}
		$response = wp_remote_post( self::API, [
			'timeout' => 20,
			'redirection' => 0,
			'reject_unsafe_urls' => true,
			'sslverify' => true,
			'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Postmark-Server-Token' => $token ],
			'body' => wp_json_encode( $payload ),
		] );
		if ( is_wp_error( $response ) ) {
			return [ 'accepted' => false, 'retryable' => false, 'uncertain' => true, 'error_code' => 'postmark_network_error' ];
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 429 === $status ) {
			return [ 'accepted' => false, 'retryable' => true, 'uncertain' => false, 'error_code' => 'postmark_rate_limited' ];
		}
		if ( $status >= 500 ) {
			return [ 'accepted' => false, 'retryable' => false, 'uncertain' => true, 'error_code' => 'postmark_server_error' ];
		}
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || 0 !== (int) ( $data['ErrorCode'] ?? -1 ) || empty( $data['MessageID'] ) ) {
			return [ 'accepted' => false, 'retryable' => false, 'uncertain' => false, 'error_code' => 'postmark_http_' . max( 0, $status ) ];
		}
		$id = strtolower( preg_replace( '/[^a-f0-9-]/i', '', (string) $data['MessageID'] ) );
		if ( '' === $id ) {
			return [ 'accepted' => false, 'retryable' => false, 'uncertain' => true, 'error_code' => 'postmark_missing_message_id' ];
		}
		return [ 'accepted' => true, 'retryable' => false, 'uncertain' => false, 'provider_id' => $id, 'error_code' => '' ];
	}


	public static function register_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		register_setting( 'gh_email', self::OPTION, [
			'type' => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
			'default' => [],
		] );
		add_settings_section( 'gh_dotflow_postmark', __( 'DotFlow · Postmark', 'groundhogg' ), [ __CLASS__, 'render_settings_intro' ], 'gh_email' );
		add_settings_field( 'dotflow_postmark_server_token', __( 'Postmark server token', 'groundhogg' ), [ __CLASS__, 'render_server_token' ], 'gh_email', 'gh_dotflow_postmark' );
		add_settings_field( 'dotflow_postmark_webhook_secret', __( 'Webhook Basic Auth password', 'groundhogg' ), [ __CLASS__, 'render_webhook_secret' ], 'gh_email', 'dotflow_postmark' );
		add_settings_field( 'dotflow_postmark_transactional_stream', __( 'Transactional Message Stream', 'groundhogg' ), [ __CLASS__, 'render_transactional_stream' ], 'gh_email', 'dotflow_postmark' );
		add_settings_field( 'dotflow_postmark_marketing_stream', __( 'Marketing Message Stream', 'groundhogg' ), [ __CLASS__, 'render_marketing_stream' ], 'gh_email', 'dotflow_postmark' );
	}

	public static function sanitize_settings( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$old = get_option( self::OPTION, [] );
		$old = is_array( $old ) ? $old : [];
		$out = [
			'server_token' => $old['server_token'] ?? '',
			'webhook_secret' => $old['webhook_secret'] ?? '',
			'transactional_stream' => sanitize_key( $input['transactional_stream'] ?? ( $old['transactional_stream'] ?? 'outbound' ) ) ?: 'outbound',
			'marketing_stream' => sanitize_key( $input['marketing_stream'] ?? ( $old['marketing_stream'] ?? 'broadcasts' ) ) ?: 'broadcasts',
		];
		foreach ( [ 'server_token', 'webhook_secret' ] as $key ) {
			if ( ! empty( $input[ 'clear_' . $key ] ) ) { $out[ $key ] = ''; continue; }
			$value = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? trim( wp_unslash( $input[ $key ] ) ) : '';
			if ( '' !== $value ) {
				$encrypted = self::encrypt_secret( $value );
				if ( false !== $encrypted ) { $out[ $key ] = $encrypted; }
			}
		}
		return $out;
	}

	public static function render_settings_intro() {
		echo '<p>' . esc_html__( 'Credentials are write-only and encrypted at rest with AES-256-GCM. Select Postmark under Groundhogg Transactional or Marketing email service after saving.', 'groundhogg' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Delivery webhook URL:', 'groundhogg' ) . '</strong> <code>' . esc_html( rest_url( 'dotflow/v1/email/delivery/postmark' ) ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'Inbound webhook URL:', 'groundhogg' ) . '</strong> <code>' . esc_html( rest_url( 'dotflow/v1/inbound/email/postmark' ) ) . '</code></p>';
		echo '<p>' . esc_html__( 'Configure HTTP Basic Authentication with username dotflow and the password below. Postmark does not provide HMAC webhook signatures; use HTTPS, Basic Authentication and IP allowlisting at your edge/WAF where possible. Inbound also requires independent inbox routing to be enabled.', 'groundhogg' ) . '</p>';
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=dotflow_postmark_test' ), 'dotflow_postmark_test' );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Test Postmark connection', 'groundhogg' ) . '</a></p>';
	}

	public static function render_server_token() { self::render_secret_field( 'server_token', __( 'Saved — enter a new token to replace', 'groundhogg' ) ); }
	public static function render_webhook_secret() { self::render_secret_field( 'webhook_secret', __( 'Saved — enter a new password to replace', 'groundhogg' ) ); }

	private static function render_secret_field( string $key, string $placeholder ) {
		$saved = '' !== self::secret( $key );
		printf( '<input type="password" autocomplete="new-password" class="regular-text" name="%1$s[%2$s]" value="" placeholder="%3$s" />', esc_attr( self::OPTION ), esc_attr( $key ), esc_attr( $saved ? $placeholder : __( 'Enter value', 'groundhogg' ) ) );
		if ( $saved ) {
			echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[clear_' . esc_attr( $key ) . ']" value="1" /> ' . esc_html__( 'Remove saved value', 'groundhogg' ) . '</label>';
		}
	}

	public static function render_transactional_stream() { self::render_stream_field( 'transactional_stream', 'outbound' ); }
	public static function render_marketing_stream() { self::render_stream_field( 'marketing_stream', 'broadcasts' ); }
	private static function render_stream_field( string $key, string $default ) {
		$options = get_option( self::OPTION, [] );
		$value = is_array( $options ) ? ( $options[ $key ] ?? $default ) : $default;
		printf( '<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" />', esc_attr( self::OPTION ), esc_attr( $key ), esc_attr( $value ) );
	}

	public static function test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to test email providers.', 'groundhogg' ), '', [ 'response' => 403 ] ); }
		check_admin_referer( 'dotflow_postmark_test' );
		$token = self::secret( 'server_token' );
		$result = 'failed';
		if ( '' !== $token ) {
			$response = wp_remote_get( 'https://api.postmarkapp.com/server', [
				'timeout' => 10,
				'redirection' => 0,
				'reject_unsafe_urls' => true,
				'sslverify' => true,
				'headers' => [ 'Accept' => 'application/json', 'X-Postmark-Server-Token' => $token ],
			] );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) { $result = 'success'; }
		}
		set_transient( 'dotflow_postmark_test_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=gh_settings&tab=email' ) );
		exit;
	}

	public static function admin_notice() {
		$key = 'dotflow_postmark_test_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! $result || ! current_user_can( 'manage_options' ) ) { return; }
		delete_transient( $key );
		$class = 'success' === $result ? 'notice notice-success' : 'notice notice-error';
		$message = 'success' === $result
			? __( 'Postmark connection succeeded. No email was sent.', 'groundhogg' )
			: __( 'Postmark connection failed. Check the server token and outbound HTTPS access.', 'groundhogg' );
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function encrypt_secret( string $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) { return false; }
		try {
			$iv = random_bytes( 12 );
			$tag = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::settings_key(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $cipher || 16 !== strlen( $tag ) ? false : 'v1:' . base64_encode( $iv . $tag . $cipher );
		} catch ( \Throwable $exception ) { return false; }
	}

	private static function decrypt_secret( string $stored ): string {
		if ( ! function_exists( 'openssl_decrypt' ) || 0 !== strpos( $stored, 'v1:' ) ) { return ''; }
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) { return ''; }
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::settings_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return is_string( $plain ) ? $plain : '';
	}

	private static function settings_key(): string {
		return hash( 'sha256', (string) wp_salt( 'auth' ) . '|' . site_url() . '|dotflow-postmark-settings-v1', true );
	}

	private static function fail( string $code ): bool {
		do_action( 'wp_mail_failed', new WP_Error( sanitize_key( $code ), __( 'Postmark could not accept this email. Check DotFlow email diagnostics.', 'groundhogg' ) ) );
		return false;
	}
	private static function parse_headers( $headers ): array {
		if ( is_string( $headers ) ) { $headers = preg_split( '/\r\n|\r|\n/', $headers ); }
		if ( ! is_array( $headers ) ) { return []; } $out = [];
		foreach ( $headers as $key => $header ) {
			if ( is_string( $key ) && is_scalar( $header ) ) { $name = strtolower( trim( $key ) ); $value = trim( (string) $header ); }
			elseif ( is_string( $header ) && false !== strpos( $header, ':' ) ) { [ $name, $value ] = array_map( 'trim', explode( ':', $header, 2 ) ); $name = strtolower( $name ); }
			else { continue; }
			if ( in_array( $name, [ 'from', 'cc', 'bcc', 'reply-to', 'content-type' ], true ) && ! preg_match( '/[\r\n]/', $value ) ) { $out[ $name ] = $value; }
		} return $out;
	}
	private static function emails( $value ): array {
		$values = is_array( $value ) ? $value : preg_split( '/[,;]/', (string) $value ); $out = [];
		foreach ( $values as $v ) { if ( ! is_string( $v ) ) { return []; } $email = self::extract_email( $v ); if ( ! is_email( $email ) ) { return []; } $out[] = strtolower( $email ); }
		return array_values( array_unique( $out ) );
	}
	private static function extract_email( string $value ): string {
		if ( preg_match( '/<\s*([^<>]+)\s*>/', $value, $m ) ) { $value = $m[1]; }
		return sanitize_email( trim( $value ) );
	}
}

final class Postmark_Delivery_Adapter implements Delivery_Webhook_Adapter {
	public function get_id(): string { return 'postmark'; }
	public function authorize( WP_REST_Request $request ) { return Postmark_Provider::authorize( $request ); }
	public function normalize( array $payload ) { return Postmark_Provider::normalize_delivery( $payload ); }
}
final class Postmark_Inbound_Adapter implements Inbound_Email_Provider_Adapter {
	public function get_id(): string { return 'postmark'; }
	public function authorize( WP_REST_Request $request ) { return Postmark_Provider::authorize( $request ); }
	public function normalize( array $payload ) { return Postmark_Provider::normalize_inbound( $payload ); }
}
