<?php

namespace Groundhogg\DotFlow\Elementor;

/**
 * Resolves published CRM form steps for public Elementor rendering.
 *
 * Elementor document settings are untrusted input: every render must resolve
 * the ID against the live CRM step table and re-check its type and status.
 */
final class Form_Resolver {

	/**
	 * Return the selected published form step or false.
	 *
	 * @param int $step_id Stored Elementor form step ID.
	 * @return \Groundhogg\Step|false
	 */
	public static function get_published_form( $step_id ) {

		$step_id = absint( $step_id );
		if ( ! $step_id ) {
			return false;
		}

		$step = new \Groundhogg\Step( $step_id );
		if ( ! $step->exists() ) {
			return false;
		}

		if ( ! in_array( $step->get_type(), [ 'web_form', 'form_fill' ], true ) || 'active' !== $step->step_status ) {
			return false;
		}

		return $step;
	}

	/**
	 * Build a bounded list of active form choices for the editor.
	 *
	 * @return array
	 */
	public static function get_form_options() {

		global $wpdb;

		$db = \Groundhogg\get_db( 'steps' );
		if ( ! $db ) {
			return [ '' => __( 'Form list is temporarily unavailable', 'groundhogg' ) ];
		}

		$steps_table = $db->get_table_name();
		$steps = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, step_title FROM {$steps_table} WHERE step_type IN (%s, %s) AND step_status = %s ORDER BY step_title ASC LIMIT %d",
				'web_form',
				'form_fill',
				'active',
				99
			)
		);

		$options = [ '' => __( 'Select a published form', 'groundhogg' ) ];
		foreach ( (array) $steps as $step ) {
			$options[ absint( $step->ID ) ] = sprintf(
				'%1$s (ID: %2$d)',
				sanitize_text_field( $step->step_title ),
				absint( $step->ID )
			);
		}

		if ( empty( $steps ) ) {
			$options = [ '' => __( 'No active web form steps found', 'groundhogg' ) ];
		}

		return $options;
	}
}
