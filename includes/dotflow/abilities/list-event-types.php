<?php

namespace Groundhogg\DotFlow\Abilities;

use Groundhogg\Abilities\Ability;
use WP_Error;
use function Groundhogg\get_db;

/**
 * Lists observed DotFlow event names and sources without exposing event payloads
 * or contact identifiers. AI clients can use this to configure event benchmarks.
 */
class List_Event_Types extends Ability {

	protected const NAME       = 'dotflow/list-event-types';
	protected const CATEGORY   = 'dotflow-platform';
	protected const CAPABILITY = 'view_funnels';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args() {

		return [
			'label'       => __( 'List DotFlow Event Types', 'groundhogg' ),
			'description' => __( 'List observed DotFlow event names and sources that can be used to configure the dotflow_event workflow benchmark. Returns counts and last-seen timestamps only; never returns event payloads, contact IDs or personal data. AI mutation audit events are excluded.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'search' => [
						'type'        => 'string',
						'maxLength'   => 100,
						'description' => __( 'Optional search text matched against event names and sources.', 'groundhogg' ),
					],
					'limit' => [
						'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 50,
					'description' => __( 'Maximum event types to return (1–100).', 'groundhogg' ),
				],
				],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'event_types' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'event_name'  => [ 'type' => 'string' ],
								'source'      => [ 'type' => 'string' ],
								'occurrences' => [ 'type' => 'integer' ],
								'last_seen'   => [ 'type' => 'string' ],
							],
						],
					],
					'total' => [ 'type' => 'integer' ],
				],
			],
		];
	}

	public function __invoke( $input ) {

		global $wpdb;

		$db = get_db( 'dotflow_events' );

		if ( ! $db ) {
			return new WP_Error( 'dotflow_event_store_unavailable', __( 'The DotFlow event store is not available.', 'groundhogg' ) );
		}

		$search = isset( $input['search'] ) ? substr( sanitize_text_field( (string) $input['search'] ), 0, 100 ) : '';
		$limit  = isset( $input['limit'] ) ? absint( $input['limit'] ) : 50;
		$limit  = max( 1, min( 100, $limit ) );

		$table = $db->get_table_name();
		$like  = '%' . $wpdb->esc_like( $search ) . '%';

		$sql = $wpdb->prepare(
			"SELECT event_name, source, COUNT(*) AS occurrences, MAX( occurred_at ) AS last_seen
			FROM {$table}
			WHERE event_name NOT LIKE %s
			AND ( event_name LIKE %s OR source LIKE %s )
			GROUP BY event_name, source
			ORDER BY last_seen DESC, event_name ASC, source ASC
			LIMIT %d",
			'ai.mutation.%',
			$like,
			$like,
			$limit
		);

		$count_sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT event_name, source FROM {$table}
				WHERE event_name NOT LIKE %s
				AND ( event_name LIKE %s OR source LIKE %s )
				GROUP BY event_name, source
			) AS observed_event_types",
			'ai.mutation.%',
			$like,
			$like
		);

		$total = absint( $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'dotflow_event_types_query_failed', __( 'Observed DotFlow event types could not be loaded.', 'groundhogg' ) );
		}

		$event_types = array_map( function ( $row ) {
			return [
				'event_name'  => sanitize_text_field( $row['event_name'] ),
				'source'      => sanitize_key( $row['source'] ),
				'occurrences' => absint( $row['occurrences'] ),
				'last_seen'   => sanitize_text_field( $row['last_seen'] ),
			];
		}, $rows );

		return [
			'event_types' => $event_types,
			'total'       => $total,
		];
	}
}
