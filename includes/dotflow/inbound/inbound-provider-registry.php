<?php

namespace Groundhogg\DotFlow\Inbound;

use InvalidArgumentException;

/**
 * Registry for native inbound email provider adapters.
 */
final class Inbound_Provider_Registry {

	private static $adapters = [];
	private static $booted = false;

	public static function register_adapters() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		do_action( 'dotflow/email/inbound_adapters/register' );
	}

	public static function register( Inbound_Email_Provider_Adapter $adapter ) {
		$raw_id = (string) $adapter->get_id();
		$id     = sanitize_key( $raw_id );

		if ( '' === $id || strlen( $id ) > 48 || $raw_id !== $id || ! preg_match( '/^[a-z0-9_-]{1,48}$/', $raw_id ) ) {
			throw new InvalidArgumentException( 'Inbound provider IDs must contain 1 to 48 lowercase safe characters.' );
		}

		if ( isset( self::$adapters[ $id ] ) ) {
			return false;
		}

		self::$adapters[ $id ] = $adapter;
		return true;
	}

	public static function get( $id ) {
		$id = sanitize_key( (string) $id );
		return self::$adapters[ $id ] ?? null;
	}

	public static function ids() {
		return array_keys( self::$adapters );
	}
}
