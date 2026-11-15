<?php

namespace Groundhogg\DotFlow\Abilities;

use Groundhogg\Abilities\Ability;
use Groundhogg\DotFlow\Events\Audit;
use Groundhogg\Event;
use Groundhogg\Funnel;
use Groundhogg\Step;
use WP_Error;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

/**
 * Pause, resume, cancel or restart one contact's journey in one flow.
 *
 * This deliberately operates on Groundhogg's durable event queue rather than
 * introducing a second scheduler or a competing workflow runtime.
 */
class Manage_Contact_Automation extends Ability {

	protected const NAME       = 'dotflow/manage-contact-automation';
	protected const CATEGORY   = 'dotflow-platform';
	protected const CAPABILITY = 'edit_contacts';

	protected const READONLY    = false;
	protected const DESTRUCTIVE = true;
	protected const IDEMPOTENT  = false;

	protected function get_args(): array {
		return [
			'label'       => __( 'Manage Contact Automation', 'groundhogg' ),
			'description' => __( 'Pause, resume, cancel or restart one contact in one flow. Contact and flow permissions are checked. Restart queues a fresh entry before cancelling the previous pending journey. This does not send a test or preview email; normal workflow steps may run when the queue processes.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'contact_id', 'flow_id', 'action' ],
				'properties'           => [
					'contact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					'flow_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
					'action'     => [
						'type' => 'string',
						'enum' => [ 'pause', 'resume', 'cancel', 'restart' ],
					],
				],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'contact_id'       => [ 'type' => 'integer' ],
					'flow_id'          => [ 'type' => 'integer' ],
					'action'           => [ 'type' => 'string' ],
					'changed_events'   => [ 'type' => 'integer' ],
					'new_event_id'     => [ 'type' => 'integer' ],
					'queue_status'     => [ 'type' => 'string' ],
				],
			],
		];
	}

	public function __invoke( $input ) {
		global $wpdb;

		$contact_id = absint( $input['contact_id'] ?? 0 );
		$flow_id    = absint( $input['flow_id'] ?? 0 );
		$action     = sanitize_key( (string) ( $input['action'] ?? '' ) );

		if ( ! in_array( $action, [ 'pause', 'resume', 'cancel', 'restart' ], true ) ) {
			return new WP_Error( 'dotflow_automation_action_invalid', __( 'Choose pause, resume, cancel or restart.', 'groundhogg' ) );
		}

		$contact = $contact_id ? get_contactdata( $contact_id ) : false;
		if ( ! $contact || ! $contact->exists() ) {
			return new WP_Error( 'dotflow_contact_not_found', __( 'Contact not found.', 'groundhogg' ) );
		}
		if ( ! current_user_can( 'edit_contact', $contact ) ) {
			return new WP_Error( 'dotflow_automation_contact_forbidden', __( 'You cannot manage automation for this contact.', 'groundhogg' ), [ 'status' => 403 ] );
		}

		$funnel = new Funnel( $flow_id );
		if ( ! $funnel->exists() ) {
			return new WP_Error( 'dotflow_automation_flow_not_found', __( 'Flow not found.', 'groundhogg' ) );
		}
		if ( ! current_user_can( 'edit_funnel', $flow_id ) && ! current_user_can( 'edit_funnels' ) ) {
			return new WP_Error( 'dotflow_automation_flow_forbidden', __( 'You cannot manage this flow.', 'groundhogg' ), [ 'status' => 403 ] );
		}

		$queue = get_db( 'event_queue' );
		$history = get_db( 'events' );
		if ( ! $queue || ! $history ) {
			return new WP_Error( 'dotflow_automation_queue_unavailable', __( 'The automation queue is unavailable.', 'groundhogg' ) );
		}

		$where = [
			'contact_id' => $contact_id,
			'funnel_id'  => $flow_id,
			'event_type' => Event::FUNNEL,
		];
		$changed = 0;
		$new_event_id = 0;
		$status = '';

		if ( 'pause' === $action ) {
			$changed = $queue->count( array_merge( $where, [ 'status' => Event::WAITING ] ) );
			if ( $changed ) {
				$queue->update( array_merge( $where, [ 'status' => Event::WAITING ] ), [
					'status'         => Event::PAUSED,
					'time_scheduled' => time(),
				] );
			}
			$status = Event::PAUSED;
		} elseif ( 'resume' === $action ) {
			if ( ! $funnel->is_active() ) {
				return new WP_Error( 'dotflow_automation_flow_inactive', __( 'Activate the flow before resuming a contact journey.', 'groundhogg' ) );
			}
			$changed = $queue->count( array_merge( $where, [ 'status' => Event::PAUSED ] ) );
			if ( $changed ) {
				$queue->update( array_merge( $where, [ 'status' => Event::PAUSED ] ), [
					'status'         => Event::WAITING,
					'time_scheduled' => time(),
				] );
			}
			$status = Event::WAITING;
		} elseif ( 'cancel' === $action ) {
			$waiting = $queue->count( array_merge( $where, [ 'status' => Event::WAITING ] ) );
			$paused  = $queue->count( array_merge( $where, [ 'status' => Event::PAUSED ] ) );
			$changed = absint( $waiting ) + absint( $paused );

			foreach ( [ Event::WAITING, Event::PAUSED ] as $current_status ) {
				$queue->update( array_merge( $where, [ 'status' => $current_status ] ), [
					'status'         => Event::CANCELLED,
					'error_code'     => 'dotflow_contact_cancelled',
					'error_message'  => 'Cancelled by an authorised DotFlow automation control.',
					'time_scheduled' => time(),
				] );
			}

			if ( $changed ) {
				$queue->move_events_to_history( array_merge( $where, [ 'status' => Event::CANCELLED ] ), 'AND' );
			}
			$status = Event::CANCELLED;
		} else {
			if ( ! $funnel->is_active() ) {
				return new WP_Error( 'dotflow_automation_flow_inactive', __( 'Activate the flow before restarting a contact journey.', 'groundhogg' ) );
			}

			$entry_step_id = absint( $funnel->get_first_action_id() );
			$entry_step = $entry_step_id ? new Step( $entry_step_id ) : false;
			if ( ! $entry_step || ! $entry_step->exists() || $entry_step->get_funnel_id() !== $flow_id || ! $entry_step->is_action() ) {
				return new WP_Error( 'dotflow_automation_entry_step_missing', __( 'This flow has no valid entry action and cannot be restarted.', 'groundhogg' ) );
			}

			// Keep the new entry and cancellation of the old journey atomic.
			$wpdb->query( 'START TRANSACTION' );
			$new_event_id = $entry_step->enqueue( $contact, false, [ 'dotflow_restart' => true ] );
			if ( ! $new_event_id ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'dotflow_automation_restart_failed', __( 'A new journey could not be queued. The existing journey was left unchanged.', 'groundhogg' ) );
			}

			$queue_table = $queue->get_table_name();
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$queue_table} SET status = %s, error_code = %s, error_message = %s, time_scheduled = %d WHERE contact_id = %d AND funnel_id = %d AND event_type = %d AND status IN (%s, %s) AND ID <> %d",
				Event::CANCELLED,
				'dotflow_contact_restarted',
				'Cancelled because a new contact journey was started.',
				time(),
				$contact_id,
				$flow_id,
				Event::FUNNEL,
				Event::WAITING,
				Event::PAUSED,
				absint( $new_event_id )
			) );

			if ( false === $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'dotflow_automation_restart_failed', __( 'The previous journey could not be safely replaced.', 'groundhogg' ) );
			}

			$changed = (int) $updated;
			$history_table = $history->get_table_name();
			$inserted = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$history_table} (queued_id,time,micro_time,time_scheduled,contact_id,funnel_id,step_id,email_id,event_type,error_code,error_message,priority,status,args)
				SELECT ID,time,micro_time,time_scheduled,contact_id,funnel_id,step_id,email_id,event_type,error_code,error_message,priority,status,args
				FROM {$queue_table}
				WHERE contact_id = %d AND funnel_id = %d AND event_type = %d AND status = %s AND error_code = %s",
				$contact_id,
				$flow_id,
				Event::FUNNEL,
				Event::CANCELLED,
				'dotflow_contact_restarted'
			) );
			if ( false === $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'dotflow_automation_restart_failed', __( 'The previous journey history could not be recorded.', 'groundhogg' ) );
			}

			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$queue_table} WHERE contact_id = %d AND funnel_id = %d AND event_type = %d AND status = %s AND error_code = %s",
				$contact_id,
				$flow_id,
				Event::FUNNEL,
				Event::CANCELLED,
				'dotflow_contact_restarted'
			) );
			if ( false === $deleted ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'dotflow_automation_restart_failed', __( 'The previous journey queue could not be cleared.', 'groundhogg' ) );
			}

			$wpdb->query( 'COMMIT' );
			$queue->cache_set_last_changed();
			$history->cache_set_last_changed();
			$changed = max( 0, $changed );
			$status = Event::WAITING;
		}

		if ( $changed || $new_event_id ) {
			Audit::record( 'automation.contact_' . $action, 'contact', $contact_id, [
				'flow_id'        => $flow_id,
				'action'         => $action,
				'changed_events' => absint( $changed ),
				'new_event_id'   => absint( $new_event_id ),
			], 'automation' );
		}

		if ( 'restart' !== $action && $changed ) {
			$queue->cache_set_last_changed();
			$history->cache_set_last_changed();
		}

		return [
			'contact_id'     => $contact_id,
			'flow_id'        => $flow_id,
			'action'         => $action,
			'changed_events' => absint( $changed ),
			'new_event_id'   => absint( $new_event_id ),
			'queue_status'   => $status,
		];
	}
}
