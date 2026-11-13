<?php

namespace Groundhogg\DotFlow;

use Groundhogg\Abilities\Abilities;
use Groundhogg\DotFlow\Abilities\Get_System_Manifest;
use Groundhogg\DotFlow\Elementor\Widgets\Form_Widget;

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

		// Elementor is optional. Register native widgets only after its API is available.
		if ( did_action( 'elementor/loaded' ) || class_exists( '\\Elementor\\Plugin' ) ) {
			self::register_elementor_hooks();
		} else {
			add_action( 'elementor/loaded', [ __CLASS__, 'register_elementor_hooks' ] );
		}

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
		Abilities::add_ability( Get_System_Manifest::class );\n\t\tAbilities::add_ability( List_Published_Forms::class );
	}

	/**
	 * Register Elementor hooks once Elementor's public API is available.
	 *
	 * @return void
	 */
	public static function register_elementor_hooks() {
		add_action( 'elementor/elements/categories_registered', [ __CLASS__, 'register_elementor_category' ] );
		add_action( 'elementor/widgets/register', [ __CLASS__, 'register_elementor_widgets' ] );
	}

	/**
	 * Add the DotFlow category to Elementor's widget panel.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 * @return void
	 */
	public static function register_elementor_category( $elements_manager ) {
		$elements_manager->add_category( 'dotflow', [
			'title' => __( 'DotFlow', 'groundhogg' ),
			'icon'  => 'fa fa-plug',
		] );
	}

	/**
	 * Register DotFlow's native Elementor widgets.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public static function register_elementor_widgets( $widgets_manager ) {
		if ( class_exists( Form_Widget::class ) ) {
			$widgets_manager->register( new Form_Widget() );
		}
	}
}

Bootstrap::register();
