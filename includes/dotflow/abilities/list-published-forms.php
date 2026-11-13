<?php

namespace Groundhogg\DotFlow\Abilities;

use Groundhogg\Abilities\Ability;
use WP_Error;

/**
 * Lists active web-form steps so AI clients can configure DotFlow Elementor widgets.
 */
class List_Published_Forms extends Ability {

	protected const NAME       = 'dotflow/list-published-forms';
	protected const CATEGORY   = 'dotflow-platform';
	protected const CAPABILITY = 'view_funnels';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List Published DotFlow Forms', 'groundhogg' ),
			'description' => __( 'List active CRM web-form steps with their IDs, titles and embed shortcode. Use the returned ID to configure a DotFlow Form Elementor widget. Does not return submissions or contact data.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'search' => [
						'type'        => 'string',
						'maxLength'   => 100,
						'description' => __( 'Optional title search.', 'groundhogg' ),
					],
					'limit' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 50,
					],
					'offset' => [
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					],
				],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'total_items' => [ 'type' => 'integer' ],
					'forms'       => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'        => [ 'type' => 'integer' ],
								'title'     => [ 'type' => 'string' ],
								'funnel_id' => [ 'type' => 'integer' ],
								'status'    => [ 'type' => 'string' ],
								'shortcode' => [ 'type' => 'string' ],
								'elementor_widget' => [ 'type' => 'string' ],
							],
						],
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		global $wpdb;

		if ( ! current_user_can( 'view_funnels' ) ) {
			return new WP_Error(
				'dotflow_forbidden',
				__( 'You do not have permission to view CRM forms.', 'groundhogg' ),
				[ 'status' => 403 ]
			);
		}

		$steps_table = \Groundhogg\get_db( 'steps' )->get_table_name();
		$limit       = isset( $input['limit'] ) ? min( 100, max( 1, absint( $input['limit'] ) ) ) : 50;
		$offset      = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;
		$where       = 'step_type IN (%s, %s) AND step_status = %s';
		$params      = [ 'web_form', 'form_fill', 'active' ];

		if ( ! empty( $input['search'] ) ) {
			$where    .= ' AND step_title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $input['search'] ) ) . '%';
		}

		$count_sql  = $wpdb->prepare( "SELECT COUNT(*) FROM {$steps_table} WHERE {$where}", $params );
		$total      = (int) $wpdb->get_var( $count_sql );
		$query_args = array_merge( $params, [ $limit, $offset ] );
		$query_sql  = $wpdb->prepare(
			"SELECT ID, funnel_id, step_title, step_status FROM {$steps_table} WHERE {$where} ORDER BY step_title ASC LIMIT %d OFFSET %d",
			$query_args
		);
		$rows       = $wpdb->get_results( $query_sql );

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error(
				'dotflow_form_query_failed',
				__( 'Published forms could not be retrieved.', 'groundhogg' )
			);
		}

		$forms = [];

		foreach ( $rows as $row ) {
			$id = absint( $row->ID );

			$forms[] = [
				'id'               => $id,
				'title'            => sanitize_text_field( $row->step_title ),
				'funnel_id'        => absint( $row->funnel_id ),
				'status'           => sanitize_key( $row->step_status ),
				'shortcode'        => sprintf( '[gh_form id="%d"]', $id ),
				'elementor_widget' => 'dotflow-form',
			];
		}

		return [
			'total_items' => $total,
			'forms'       => $forms,
		];
	}
}
