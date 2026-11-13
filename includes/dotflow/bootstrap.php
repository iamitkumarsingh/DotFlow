<?php

namespace Groundhogg\DotFlow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps DotFlow-specific functionality without replacing Groundhogg's
 * existing CRM, database, queue or automation infrastructure.
 */
final class Bootstrap {

	/**
	 * Register the DotFlow bootstrap on the existing Groundhogg lifecycle.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'groundhogg/init/v2', [ __CLASS__, 'init' ], 10 );
	}

	/**
	 * Initialise the DotFlow domain layer.
	 *
	 * @return void
	 */
	public static function init() {

		if ( ! defined( 'DOTFLOW_VERSION' ) ) {
			define( 'DOTFLOW_VERSION', '0.1.0-dev' );
		}

		/**
		 * Fires when the DotFlow domain layer is ready.
		 *
		 * @since 0.1.0
		 */
		do_action( 'dotflow/loaded' );
	}
}

Bootstrap::register();
