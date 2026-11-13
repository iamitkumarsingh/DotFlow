<?php

namespace Groundhogg\DotFlow\Abilities;

use Groundhogg\Abilities\Ability;
use WP_Error;

/**
 * Lists durable DotFlow event records for authorised administrators.
 */
class List_Events extends Ability {

	protected const NAME       = 'dotflow/list-events';
	protected const CATEGORY   = 'dotflow-platform';
	protected const CAPABILITY = 'manage_options';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List DotFlow Events', 'groundhogg' ),
			'description' => __( 'Search the DotFlow event history by event name, source, contact, object or date window. Event payloads may contain business or contact data, so this ability is restricted to administrators.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'event_name' => [
						'type'        => 'string',
						'maxLength'   => 191,
						'description' => __( 'Exact event name, e.g. contact.created or form.submitted.', 'groundhogg' ),
					],
					'source' => [
						'type'        => 'string',
						'maxLength'   => 64,
						'description' => __( 'Event source, such as internal, elementor or a provider key.', 'groundhogg' ),
					],
					'contact_id' => [
						'type'        => 'integer',
						'minimum'     => 1,
					],
					'object_type' => [
						'type'      => 'string',
						'maxLength' => 50,
					],
					'object_id' => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'after' => [
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Return events at or after this ISO 8601 timestamp.', 'groundhogg' ),
					],
					'before' => [
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Return events at or before this ISO 8601 timestamp.', 'groundhogg' ),
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
					'events'      => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'            => [ 'type' => 'integer' ],
								'event_id'      => [ 'type' => 'string' ],
								'event_name'    => [ 'type' => 'string' ],
								'source'        => [ 'type' => 'string' ],
								'object_type'   => [ 'type' => 'string' ],
								'object_id'     => [ 'type' => 'integer' ],
								'contact_id'    => [ 'type' => 'integer' ],
								'payload'       => [ 'type' => 'object' ],
								'occurred_at'   => [ 'type' => 'string' ],
								'created_at'    => [ 'type' => 'string' ],
								'actor_user_id' => [ 'type' => 'integer' ],
							],
						],
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		global $wpdb;

		$db = \Groundhogg\get_db( 'dotflow_events' );

		if ( ! $db ) {
			return new WP_Error(
				'dotflow_events_unavailable',
				__( 'The DotFlow event store is not available.', 'groundhogg' )
			);
		}

		$table  = $db->get_table_name();
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $input['event_name'] ) ) {
			$where[]  = 'event_name = %s';
			$params[] = sanitize_text_field( $input['event_name'] );
		}

		if ( ! empty( $input['source'] ) ) {
			$where[]  = 'source = %s';
			$params[] = sanitize_key( $input['source'] );
		}

		if ( ! empty( $input['contact_id'] ) ) {
			$where[]  = 'contact_id = %d';
			$params[] = absint( $input['contact_id'] );
		}

		if ( ! empty( $input['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = sanitize_key( $input['object_type'] );
		}

		if ( ! empty( $input['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = absint( $input['object_id'] );
		}

		if ( ! empty( $input['after'] ) ) {
			$timestamp = strtotime( sanitize_text_field( $input['after'] ) );
			if ( false !== $timestamp ) {
				$where[]  = 'occurred_at >= %s';
				$params[] = gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		if ( ! empty( $input['before'] ) ) {
			$timestamp = strtotime( sanitize_text_field( $input['before'] ) );
			if ( false !== $timestamp ) {
				$where[]  = 'occurred_at <= %s';
				$params[] = gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		$where_sql = implode( ' AND ', $where );
		$limit     = isset( $input['limit'] ) ? min( 100, max( 1, absint( $input['limit'] ) ) ) : 50;
		$offset    = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$query_sql = "SELECT ID, event_id, event_name, source, object_type, object_id, contact_id, payload, occurred_at, created_at, actor_user_id FROM {$table} WHERE {$where_sql} ORDER BY occurred_at DESC, ID DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, [ $limit, $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_params ) );

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error(
				'dotflow_event_query_failed',
				__( 'DotFlow events could not be retrieved.', 'groundhogg' )
			);
		}

		$events = [];

		foreach ( $rows as $row ) {
			$payload = json_decode( $row->payload, true );

			$events[] = [
				'id'            => absint( $row->ID ),
				'event_id'      => (string) $row->event_id,
				'event_name'    => (string) $row->event_name,
				'source'        => (string) $row->source,
				'object_type'   => (string) $row->object_type,
				'object_id'     => absint( $row->object_id ),
				'contact_id'    => absint( $row->contact_id ),
				'payload'       => is_array( $payload ) ? $payload : [],
				'occurred_at'   => get_date_from_gmt( $row->occurred_at, 'c' ),
				'created_at'    => get_date_from_gmt( $row->created_at, 'c' ),
				'actor_user_id' => absint( $row->actor_user_id ),
			];
		}

		return [
			'total_items' => $total,
			'events'      => $events,
		];
	}
}
