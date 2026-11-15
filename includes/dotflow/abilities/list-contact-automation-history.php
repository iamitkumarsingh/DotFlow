<?php

namespace Groundhogg\DotFlow\Abilities;

use Groundhogg\Abilities\Ability;
use Groundhogg\Event;
use WP_Error;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

/**
 * Lists a contact's queued and completed Groundhogg automation events.
 * Email bodies, recipient addresses and event args are never returned.
 */
class List_Contact_Automation_History extends Ability {

	protected const NAME       = 'dotflow/list-contact-automation-history';
	protected const CATEGORY   = 'dotflow-platform';
	protected const CAPABILITY = 'read';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {
		return [
			'label'       => __( 'List Contact Automation History', 'groundhogg' ),
			'description' => __( 'List a contact’s queued, paused, completed, failed and cancelled automation steps. Returns operational metadata only; email content, event args and personal message data are excluded.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'contact_id' ],
				'properties'           => [
					'contact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					'flow_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
					'status'     => [
						'type' => 'string',
						'enum' => [ 'waiting', 'paused', 'complete', 'failed', 'cancelled', 'skipped', 'in_progress' ],
					],
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
								'id'             => [ 'type' => 'integer' ],
								'storage'        => [ 'type' => 'string', 'enum' => [ 'queue', 'history' ] ],
								'flow_id'        => [ 'type' => 'integer' ],
								'step_id'        => [ 'type' => 'integer' ],
								'email_id'       => [ 'type' => 'integer' ],
								'status'         => [ 'type' => 'string' ],
								'error_code'     => [ 'type' => 'string' ],
								'scheduled_at'   => [ 'type' => 'string', 'format' => 'date-time' ],
								'created_at'     => [ 'type' => 'string', 'format' => 'date-time' ],
							],
						],
					],
				],
			],
		];
	}

	public function __invoke( $input ) {
		global $wpdb;

		$contact_id = absint( $input['contact_id'] ?? 0 );
		$contact = $contact_id ? get_contactdata( $contact_id ) : false;
		if ( ! $contact || ! $contact->exists() ) {
			return new WP_Error( 'dotflow_contact_not_found', __( 'Contact not found.', 'groundhogg' ) );
		}
		if ( ! current_user_can( 'view_contact', $contact ) ) {
			return new WP_Error( 'dotflow_automation_history_forbidden', __( 'You cannot view automation history for this contact.', 'groundhogg' ), [ 'status' => 403 ] );
		}

		$queue = get_db( 'event_queue' );
		$history = get_db( 'events' );
		if ( ! $queue || ! $history ) {
			return new WP_Error( 'dotflow_automation_history_unavailable', __( 'Automation history is unavailable.', 'groundhogg' ) );
		}

		$conditions = [
			'contact_id = %d',
			'event_type = %d',
		];
		$params = [ $contact_id, Event::FUNNEL ];

		if ( ! empty( $input['flow_id'] ) ) {
			$conditions[] = 'funnel_id = %d';
			$params[] = absint( $input['flow_id'] );
		}
		if ( ! empty( $input['status'] ) ) {
			$conditions[] = 'status = %s';
			$params[] = sanitize_key( $input['status'] );
		}

		$where = implode( ' AND ', $conditions );
		$queue_table = $queue->get_table_name();
		$history_table = $history->get_table_name();
		$limit = isset( $input['limit'] ) ? min( 100, max( 1, absint( $input['limit'] ) ) ) : 50;
		$offset = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		$queue_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE {$where}", $params ) );
		$history_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history_table} WHERE {$where}", $params ) );
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'dotflow_automation_history_query_failed', __( 'Automation history could not be retrieved.', 'groundhogg' ) );
		}

		$sql = "SELECT ID AS event_id, 'queue' AS storage, funnel_id, step_id, email_id, status, error_code, time AS event_time, time_scheduled
			FROM {$queue_table} WHERE {$where}
			UNION ALL
			SELECT ID AS event_id, 'history' AS storage, funnel_id, step_id, email_id, status, error_code, time AS event_time, time_scheduled
			FROM {$history_table} WHERE {$where}
			ORDER BY event_time DESC, event_id DESC
			LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, $params, [ $limit, $offset ] ) ) );

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'dotflow_automation_history_query_failed', __( 'Automation history could not be retrieved.', 'groundhogg' ) );
		}

		$events = [];
		foreach ( $rows as $row ) {
			$events[] = [
				'id'           => absint( $row->event_id ),
				'storage'      => (string) $row->storage,
				'flow_id'      => absint( $row->funnel_id ),
				'step_id'      => absint( $row->step_id ),
				'email_id'     => absint( $row->email_id ),
				'status'       => sanitize_key( (string) $row->status ),
				'error_code'   => sanitize_key( (string) $row->error_code ),
				'scheduled_at' => gmdate( 'c', absint( $row->time_scheduled ) ),
				'created_at'   => gmdate( 'c', absint( $row->event_time ) ),
			];
		}

		return [
			'total_items' => $queue_count + $history_count,
			'events'      => $events,
		];
	}
}
