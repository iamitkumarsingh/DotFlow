<?php

namespace Groundhogg\DotFlow\Abilities;

use Groundhogg\Abilities\Ability;
use WP_Error;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

/**
 * Lists privacy-minimised DotFlow event metadata for one authorised contact.
 *
 * Unlike the administrator-wide event history, this ability never returns event
 * payloads. Contact access is checked using Groundhogg's per-object capability.
 */
class List_Contact_Events extends Ability {

	protected const NAME       = 'dotflow/list-contact-events';
	protected const CATEGORY   = 'dotflow-platform';
	protected const CAPABILITY = 'read';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List Contact Activity Events', 'groundhogg' ),
			'description' => __( 'List privacy-minimised DotFlow event metadata for a contact you are allowed to view. Event payloads are never returned by this ability.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'contact_id' ],
				'properties'           => [
					'contact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					'event_name' => [ 'type' => 'string', 'maxLength' => 191 ],
					'after'      => [ 'type' => 'string', 'format' => 'date-time' ],
					'before'     => [ 'type' => 'string', 'format' => 'date-time' ],
					'limit'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
					'offset'     => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
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
							'actor_user_id' => [ 'type' => 'integer' ],
							'occurred_at'   => [ 'type' => 'string', 'format' => 'date-time' ],
						],
						],
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		global $wpdb;

		$contact_id = isset( $input['contact_id'] ) ? absint( $input['contact_id'] ) : 0;
		$contact    = $contact_id ? get_contactdata( $contact_id ) : false;

		if ( ! $contact || ! $contact->exists() ) {
			return new WP_Error( 'dotflow_contact_not_found', __( 'Contact not found.', 'groundhogg' ) );
		}

		if ( ! current_user_can( 'view_contact', $contact ) ) {
			return new WP_Error(
				'dotflow_contact_events_forbidden',
				__( 'You do not have permission to view activity for this contact.', 'groundhogg' ),
				[ 'status' => 403 ]
			);
		}

		$db = get_db( 'dotflow_events' );

		if ( ! $db ) {
			return new WP_Error( 'dotflow_events_unavailable', __( 'The DotFlow event store is not available.', 'groundhogg' ) );
		}

		$where  = [ 'contact_id = %d' ];
		$params = [ $contact_id ];

		if ( ! empty( $input['event_name'] ) ) {
			$where[]  = 'event_name = %s';
			$params[] = sanitize_text_field( $input['event_name'] );
		}

		foreach ( [ 'after' => '>=', 'before' => '<=' ] as $key => $operator ) {
			if ( empty( $input[ $key ] ) ) {
				continue;
			}

			$timestamp = strtotime( sanitize_text_field( $input[ $key ] ) );

			if ( false === $timestamp ) {
				return new WP_Error( 'dotflow_invalid_event_date', __( 'The event date filter must be a valid ISO 8601 date-time.', 'groundhogg' ) );
			}

			$where[]  = "occurred_at {$operator} %s";
			$params[] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		$where_sql = implode( ' AND ', $where );
		$table     = $db->get_table_name();
		$limit     = isset( $input['limit'] ) ? min( 100, max( 1, absint( $input['limit'] ) ) ) : 50;
		$offset    = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$query_sql = "SELECT ID, event_id, event_name, source, object_type, object_id, actor_user_id, occurred_at FROM {$table} WHERE {$where_sql} ORDER BY occurred_at DESC, ID DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, [ $limit, $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_params ) );

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'dotflow_contact_events_query_failed', __( 'Contact activity events could not be retrieved.', 'groundhogg' ) );
		}

		$events = [];

		foreach ( $rows as $row ) {
			$timestamp = strtotime( $row->occurred_at );

			$events[] = [
				'id'            => absint( $row->ID ),
				'event_id'      => (string) $row->event_id,
				'event_name'    => (string) $row->event_name,
				'source'        => (string) $row->source,
				'object_type'   => (string) $row->object_type,
				'object_id'     => absint( $row->object_id ),
				'actor_user_id' => absint( $row->actor_user_id ),
				'occurred_at'   => false === $timestamp ? '' : gmdate( 'c', $timestamp ),
			];
		}

		return [
			'total_items' => $total,
			'events'      => $events,
		];
	}
}
