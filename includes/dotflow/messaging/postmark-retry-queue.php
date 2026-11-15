<?php

namespace Groundhogg\DotFlow\Messaging;

use WP_Error;

/**
 * Durable, encrypted retry queue for explicit Postmark HTTP 429 responses.
 *
 * Network errors and 5xx responses are marked uncertain, never retried: the
 * remote server may have accepted a message before the connection failed.
 */
final class Postmark_Retry_Queue {

	const HOOK = 'dotflow/postmark/process_retry_queue';
	const MAX_ATTEMPTS = 5;
	const BATCH_SIZE = 10;

	public static function register() {
		add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
		add_action( 'init', [ __CLASS__, 'maybe_install' ], 3 );
		add_action( 'init', [ __CLASS__, 'schedule' ], 4 );
		add_action( self::HOOK, [ __CLASS__, 'process_batch' ] );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['dotflow_minute'] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (DotFlow)', 'groundhogg' ),
		];
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'dotflow_minute', self::HOOK );
		}
	}

	public static function maybe_install() {
		$db = \Groundhogg\get_db( 'dotflow_postmark_outbox' );
		if ( ! $db ) {
			return;
		}
		$version = get_option( $db->get_table_name() . '_db_version' );
		if ( (string) $version !== (string) $db->version ) {
			$db->create_table();
		}
	}

	/**
	 * Reserve a stable Message-ID before calling Postmark, preventing concurrent
	 * duplicate requests from sending the same logical email twice.
	 *
	 * @return array|false Reservation or existing status.
	 */
	public static function reserve( array $payload, string $message_id ) {
		global $wpdb;
		$db = \Groundhogg\get_db( 'dotflow_postmark_outbox' );
		if ( ! $db || ! preg_match( '/^<[^<>\s@]+@[^<>\s@]+>$/', $message_id ) ) {
			return false;
		}

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) ) {
			return false;
		}
		$encrypted = self::encrypt( $encoded );
		if ( false === $encrypted ) {
			return false;
		}

		$key = hash( 'sha256', $message_id );
		$claim = wp_generate_uuid4();
		$now = gmdate( 'Y-m-d H:i:s' );
		// INSERT IGNORE makes the unique idempotency constraint an expected,
		// race-safe outcome without emitting a database error for duplicate sends.
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$db->get_table_name()} (idempotency_key,payload,status,attempts,available_at,claim_token,claimed_at,provider_message_id,last_error_code,created_at,updated_at)
			VALUES (%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%s)",
			$key,
			$encrypted,
			'processing',
			0,
			$now,
			$claim,
			$now,
			'',
			'',
			$now,
			$now
		) );

		if ( false === $inserted ) {
			return false;
		}

		if ( 1 === (int) $inserted ) {
			return [ 'id' => absint( $wpdb->insert_id ), 'claim_token' => $claim, 'status' => 'reserved', 'message_id' => $message_id ];
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT ID, status FROM {$db->get_table_name()} WHERE idempotency_key = %s LIMIT 1",
			$key
		), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return false;
		}

		return [
			'id' => absint( $row['ID'] ),
			'claim_token' => '',
			'status' => sanitize_key( $row['status'] ),
			'message_id' => $message_id,
			'duplicate' => true,
		];
	}

	public static function complete( array $reservation, string $status, string $error_code = '', string $provider_id = '' ) {
		global $wpdb;
		$db = \Groundhogg\get_db( 'dotflow_postmark_outbox' );
		if ( ! $db || empty( $reservation['id'] ) || empty( $reservation['claim_token'] ) ) {
			return false;
		}

		return $wpdb->update(
			$db->get_table_name(),
			[
				'status' => $status,
				'payload' => '',
				'claim_token' => '',
				'claimed_at' => '1970-01-01 00:00:00',
				'provider_message_id' => substr( sanitize_text_field( $provider_id ), 0, 64 ),
				'last_error_code' => substr( sanitize_key( $error_code ), 0, 100 ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[
				'ID' => absint( $reservation['id'] ),
				'claim_token' => (string) $reservation['claim_token'],
				'status' => 'processing',
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d', '%s', '%s' ]
		);
	}

	public static function schedule_retry( array $reservation, int $attempts, string $error_code = 'postmark_rate_limited' ) {
		global $wpdb;
		$db = \Groundhogg\get_db( 'dotflow_postmark_outbox' );
		if ( ! $db || empty( $reservation['id'] ) || empty( $reservation['claim_token'] ) ) {
			return false;
		}

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return self::complete( $reservation, 'failed', 'postmark_retry_limit_reached' );
		}

		$delay = min( HOUR_IN_SECONDS, 60 * ( 2 ** max( 0, $attempts ) ) );
		$available = gmdate( 'Y-m-d H:i:s', time() + $delay );

		$updated = $wpdb->update(
			$db->get_table_name(),
			[
				'status' => 'pending',
				'attempts' => $attempts + 1,
				'available_at' => $available,
				'claim_token' => '',
				'claimed_at' => '1970-01-01 00:00:00',
				'last_error_code' => sanitize_key( $error_code ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'ID' => absint( $reservation['id'] ), 'claim_token' => (string) $reservation['claim_token'], 'status' => 'processing' ],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d', '%s', '%s' ]
		);
		return false !== $updated && $updated > 0;
	}

	public static function is_queued( string $message_id ): bool {
		global $wpdb;
		$db = \Groundhogg\get_db( 'dotflow_postmark_outbox' );
		if ( ! $db || '' === $message_id ) {
			return false;
		}
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$db->get_table_name()} WHERE idempotency_key = %s AND status IN ('pending','processing')",
			hash( 'sha256', $message_id )
		) );
		return absint( $count ) > 0;
	}


	public static function counts(): array {
		global $wpdb;
		$counts = [
			'pending' => 0,
			'processing' => 0,
			'accepted' => 0,
			'failed' => 0,
			'uncertain' => 0,
		];
		$db = \Groundhogg\get_db( 'dotflow_postmark_outbox' );
		if ( ! $db ) {
			return $counts;
		}
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS total FROM {$db->get_table_name()} GROUP BY status",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
			if ( array_key_exists( $status, $counts ) ) {
				$counts[ $status ] = absint( $row['total'] ?? 0 );
			}
		}
		return $counts;
	}

	public static function encrypt( string $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) { return false; }
		try {
			$iv = random_bytes( 12 );
			$tag = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $cipher || 16 !== strlen( $tag ) ? false : 'v1:' . base64_encode( $iv . $tag . $cipher );
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	public static function decrypt( string $stored ): string {
		if ( ! function_exists( 'openssl_decrypt' ) || 0 !== strpos( $stored, 'v1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return is_string( $plain ) ? $plain : '';
	}

	private static function key(): string {
		return hash( 'sha256', (string) wp_salt( 'auth' ) . '|' . site_url() . '|dotflow-postmark-outbox-v1', true );
	}

	/**
	 * Claim and process a bounded batch. A stale in-flight request is uncertain,
	 * not automatically retried, because Postmark may have accepted it.
	 */
	public static function process_batch() {
		global $wpdb;
		$db = \Groundhogg\get_db( 'dotflow_postmark_outbox' );
		if ( ! $db ) {
			return;
		}
		$table = $db->get_table_name();
		$now = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'uncertain', payload = '', claim_token = '', last_error_code = 'worker_interrupted', updated_at = %s WHERE status = 'processing' AND claimed_at < %s",
			$now,
			gmdate( 'Y-m-d H:i:s', time() - 600 )
		) );

		$claim = wp_generate_uuid4();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'processing', claim_token = %s, claimed_at = %s, updated_at = %s WHERE status = 'pending' AND available_at <= %s ORDER BY ID ASC LIMIT " . self::BATCH_SIZE,
			$claim,
			$now,
			$now,
			$now
		) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE claim_token = %s AND status = 'processing' ORDER BY ID ASC",
			$claim
		), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$reservation = [
				'id' => absint( $row['ID'] ),
				'claim_token' => $claim,
				'message_id' => '',
			];
			$json = self::decrypt( (string) $row['payload'] );
			$payload = '' !== $json ? json_decode( $json, true ) : null;
			if ( ! is_array( $payload ) ) {
				self::complete( $reservation, 'failed', 'outbox_payload_unreadable' );
				continue;
			}

			$result = Postmark_Provider::request_payload( $payload );
			if ( ! empty( $result['accepted'] ) ) {
				self::complete( $reservation, 'accepted', '', (string) $result['provider_id'] );
				$metadata = isset( $payload['Metadata'] ) && is_array( $payload['Metadata'] ) ? $payload['Metadata'] : [];
				$message_id = isset( $metadata['dotflow_message_id'] ) && is_string( $metadata['dotflow_message_id'] ) ? $metadata['dotflow_message_id'] : '';
				$channel = isset( $metadata['dotflow_channel'] ) ? sanitize_key( $metadata['dotflow_channel'] ) : 'transactional';
				do_action( 'groundhogg/email_services/transport_result', true, Postmark_Provider::SERVICE, $channel, $message_id );
				continue;
			}

			$attempts = absint( $row['attempts'] );
			if ( ! empty( $result['retryable'] ) ) {
				self::schedule_retry( $reservation, $attempts, (string) $result['error_code'] );
			} elseif ( ! empty( $result['uncertain'] ) ) {
				self::complete( $reservation, 'uncertain', (string) $result['error_code'] );
			} else {
				self::complete( $reservation, 'failed', (string) $result['error_code'] );
			}
		}
	}
}
