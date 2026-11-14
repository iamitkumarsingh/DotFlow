<?php

namespace Groundhogg\DotFlow\Messaging;

use InvalidArgumentException;

/**
 * Registry for provider-specific delivery webhook adapters.
 */
final class Provider_Adapter_Registry {

	/** @var Delivery_Webhook_Adapter[] */
	private static $adapters = [];

	/** @var bool */
	private static $booted = false;

	/**
	 * Let integrations register adapters once the DotFlow module is ready.
	 *
	 * @return void
	 */
	public static function register_adapters() {

		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		do_action( 'dotflow/email/delivery_adapters/register' );
	}

	/**
	 * Register one adapter.
	 *
	 * @param Delivery_Webhook_Adapter $adapter Adapter.
	 * @return bool
	 */
	public static function register( Delivery_Webhook_Adapter $adapter ) {

		$raw_id = (string) $adapter->get_id();
		$id     = sanitize_key( $raw_id );

		if ( '' === $id || strlen( $id ) > 48 || $raw_id !== $id || ! preg_match( '/^[a-z0-9_-]{1,48}$/', $raw_id ) ) {
			throw new InvalidArgumentException( 'Delivery adapter IDs must contain 1 to 48 lowercase safe characters.' );
		}

		if ( isset( self::$adapters[ $id ] ) ) {
			return false;
		}

		self::$adapters[ $id ] = $adapter;

		return true;
	}

	/**
	 * Get one registered adapter.
	 *
	 * @param string $id Provider ID.
	 * @return Delivery_Webhook_Adapter|null
	 */
	public static function get( $id ) {
		$id = sanitize_key( (string) $id );

		return self::$adapters[ $id ] ?? null;
	}

	/**
	 * Return registered adapter IDs only; never expose adapter configuration.
	 *
	 * @return string[]
	 */
	public static function ids() {
		return array_keys( self::$adapters );
	}
}
