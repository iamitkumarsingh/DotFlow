<?php

namespace Groundhogg\DotFlow\Inbound;

use Groundhogg\Classes\Inbox;

/**
 * Supplies optional provider-owned inbox addresses without provisioning the
 * Groundhogg paid relay or changing its stored license/inbox state.
 *
 * Configure DOTFLOW_INBOUND_EMAIL_ADDRESS, DOTFLOW_REPLY_EMAIL_ADDRESS and
 * DOTFLOW_INBOUND_WEBHOOK_TOKEN in wp-config.php. The custom mode is disabled
 * whenever a Groundhogg relay inbox is provisioned.
 */
final class Inbox_Addresses {

	public static function register() {
		add_filter( 'groundhogg/inbox/address', [ __CLASS__, 'inbox_address' ] );
		add_filter( 'groundhogg/inbox/reply_address', [ __CLASS__, 'reply_address' ] );
	}

	/**
	 * Whether the independent provider webhook is configured and safe to use.
	 */
	public static function is_enabled(): bool {

		if ( self::relay_has_state() || ! Inbox::site_matches() ) {
			return false;
		}

		$inbound = self::configured_address( 'DOTFLOW_INBOUND_EMAIL_ADDRESS', 'inbox_address' );
		$reply   = self::configured_address( 'DOTFLOW_REPLY_EMAIL_ADDRESS', 'reply_address' );
		$token   = self::webhook_token();

		return $inbound && $reply && strtolower( $inbound ) !== strtolower( $reply ) && strlen( $token ) >= 32;
	}

	/**
	 * Return the provider-owned secret inbound address when independent mode is active.
	 *
	 * @param string $existing Existing Groundhogg address.
	 * @return string
	 */
	public static function inbox_address( $existing ) {

		if ( ! self::is_enabled() ) {
			return $existing;
		}

		return self::configured_address( 'DOTFLOW_INBOUND_EMAIL_ADDRESS', 'inbox_address' );
	}

	/**
	 * Return the provider-owned public reply address when independent mode is active.
	 *
	 * @param string $existing Existing Groundhogg reply address.
	 * @return string
	 */
	public static function reply_address( $existing ) {

		if ( ! self::is_enabled() ) {
			return $existing;
		}

		return self::configured_address( 'DOTFLOW_REPLY_EMAIL_ADDRESS', 'reply_address' );
	}

	/**
	 * Get the shared webhook token. It is read from wp-config.php or a trusted
	 * server-side filter and is never stored in the WordPress options table.
	 */
	public static function webhook_token(): string {

		$token = defined( 'DOTFLOW_INBOUND_WEBHOOK_TOKEN' ) ? (string) DOTFLOW_INBOUND_WEBHOOK_TOKEN : '';

		$token = apply_filters( 'dotflow/inbound/webhook_token', $token );

		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Resolve and validate a configured address.
	 */
	private static function configured_address( string $constant, string $filter ): string {

		$value = defined( $constant ) ? (string) constant( $constant ) : '';
		$value = apply_filters( 'dotflow/inbound/' . $filter, $value );
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtolower( trim( $value ) );

		return is_email( $value ) ? $value : '';
	}

	/**
	 * Do not override addresses belonging to a provisioned or partially
	 * configured Groundhogg relay inbox.
	 */
	private static function relay_has_state(): bool {

		return (string) get_option( Inbox::ID_OPTION, '' ) !== ''
		       || (string) get_option( Inbox::ADDRESS_OPTION, '' ) !== ''
		       || (string) get_option( Inbox::REPLY_ADDRESS_OPTION, '' ) !== '';
	}
}
