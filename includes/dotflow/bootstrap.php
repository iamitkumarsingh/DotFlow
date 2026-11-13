<?php

namespace Groundhogg\DotFlow;

use Groundhogg\Abilities\Abilities;
use Groundhogg\DotFlow\Abilities\Get_System_Manifest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps DotFlow-specific functionality without replacing Groundhogg's
 * existing CRM, database, queue or automation infrastructure.
 */
final class Bootstrap {

	/**
	 * Register DotFlow hooks on the existing Groundhogg lifecycle.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'groundhogg/init/v2', [ __CLASS__, 'init' ], 10 );
	}

	/**
	 * Initialise the DotFlow domain layer and MCP abilities.
	 *
	 * @return void
	 */
	public static function init() {

		if ( ! defined( 'DOTFLOW_VERSION' ) ) {
			define( 'DOTFLOW_VERSION', '0.1.0-dev' );
		}

		if ( ! defined( 'DOTFLOW_API_VERSION' ) ) {
			define( 'DOTFLOW_API_VERSION', 'v1' );
		}

		// Groundhogg's Abilities registry fires these hooks at the correct
		// WordPress registration phase. This keeps MCP discovery deterministic.
		add_action( 'groundhogg/abilities/register_categories', [ __CLASS__, 'register_ability_category' ] );
		add_action( 'groundhogg/abilities/register_abilities', [ __CLASS__, 'register_abilities' ] );

		/**
		 * Fires when the DotFlow domain layer is ready.
		 *
		 * @since 0.1.0
		 */
		do_action( 'dotflow/loaded' );
	}

	/**
	 * Register the DotFlow category for WordPress Abilities / MCP clients.
	 *
	 * @return void
	 */
	public static function register_ability_category() {
		Abilities::add_category( 'dotflow-platform', [
			'label'       => __( 'DotFlow Platform', 'groundhogg' ),
			'description' => __( 'Discover DotFlow capabilities, integrations and platform-level management tools.', 'groundhogg' ),
		] );
	}

	/**
	 * Register DotFlow-specific abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		Abilities::add_ability( Get_System_Manifest::class );
	}
}

Bootstrap::register();
