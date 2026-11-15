<?php

namespace Groundhogg\DotFlow\Steps\Actions;

use Groundhogg\Event;
use Groundhogg\Funnel;
use Groundhogg\Step;
use Groundhogg\Steps\Actions\Action;
use WP_Error;
use function Groundhogg\get_db;
use function Groundhogg\html;

/**
 * Stop one contact's pending journey in a selected flow.
 *
 * Useful in an inbound-reply event flow: trigger on email.inbound.received,
 * then stop the original nurture flow for that contact.
 */
class Stop_Contact_Flow extends Action {

	public function get_name() {
		return esc_html_x( 'Stop Contact in Flow', 'step_name', 'groundhogg' );
	}

	public function get_type() {
		return 'dotflow_stop_flow';
	}

	public function get_sub_group() {
		return 'developer';
	}

	public function get_description() {
		return esc_html_x( 'Cancel this contact’s waiting or paused steps in a selected flow. Can be used in an inbound-reply flow to stop the original nurture journey.', 'step_description', 'groundhogg' );
	}

	public function get_icon() {
		return GROUNDHOGG_ASSETS_URL . 'images/funnel-icons/developer/stop.svg';
	}

	public function settings( $step ) {
		html( 'p', [], esc_html__( 'Choose the flow whose pending steps should be cancelled for this contact. The current running step is not interrupted; waiting and paused steps are moved into history.', 'groundhogg' ) );
		html( 'label', [ 'for' => $this->setting_id_prefix( 'flow_id' ) ], esc_html__( 'Target flow ID', 'groundhogg' ) );
		html( 'input', [
			'type'  => 'number',
			'min'   => 1,
			'id'    => $this->setting_id_prefix( 'flow_id' ),
			'name'  => $this->setting_name_prefix( 'flow_id' ),
			'value' => $this->get_setting( 'flow_id' ),
			'class' => 'input',
		] );
		html( 'p' );
	}

	public function get_settings_schema() {
		return [
			'flow_id' => [
				'default'  => 0,
				'sanitize' => 'absint',
			],
		];
	}

	public function validate_settings( Step $step ) {
		$flow_id = absint( $this->get_setting( 'flow_id' ) );
		if ( ! $flow_id || ! ( new Funnel( $flow_id ) )->exists() ) {
			$step->add_error( 'dotflow_stop_flow_target_invalid', __( 'Select an existing target flow.', 'groundhogg' ) );
		}
	}

	public function run( $contact, $event ) {
		if ( ! $contact || ! $contact->exists() || ! $event instanceof Event ) {
			return new WP_Error( 'dotflow_stop_flow_context_invalid', __( 'A valid contact and workflow event are required.', 'groundhogg' ) );
		}

		$flow_id = absint( $this->get_setting( 'flow_id' ) );
		$funnel = new Funnel( $flow_id );
		if ( ! $flow_id || ! $funnel->exists() ) {
			return new WP_Error( 'dotflow_stop_flow_target_invalid', __( 'The target flow no longer exists.', 'groundhogg' ) );
		}

		$queue = get_db( 'event_queue' );
		$history = get_db( 'events' );
		if ( ! $queue || ! $history ) {
			return new WP_Error( 'dotflow_stop_flow_queue_unavailable', __( 'The automation queue is unavailable.', 'groundhogg' ) );
		}

		$where = [
			'contact_id' => $contact->get_id(),
			'funnel_id'  => $flow_id,
			'event_type' => Event::FUNNEL,
		];
		$waiting = $queue->count( array_merge( $where, [ 'status' => Event::WAITING ] ) );
		$paused  = $queue->count( array_merge( $where, [ 'status' => Event::PAUSED ] ) );
		$changed = absint( $waiting ) + absint( $paused );

		foreach ( [ Event::WAITING, Event::PAUSED ] as $status ) {
			$queue->update( array_merge( $where, [ 'status' => $status ] ), [
				'status'         => Event::CANCELLED,
				'error_code'     => 'dotflow_reply_or_rule_stopped',
				'error_message'  => 'Cancelled by a configured DotFlow workflow action.',
				'time_scheduled' => time(),
			] );
		}

		if ( $changed ) {
			$queue->move_events_to_history( array_merge( $where, [
				'status'     => Event::CANCELLED,
				'error_code' => 'dotflow_reply_or_rule_stopped',
			] ), 'AND' );
		}

		do_action( 'dotflow/automation/contact_flow_stopped', $contact->get_id(), $flow_id, $event->get_funnel_id(), $event->get_step_id(), $changed );
		$queue->cache_set_last_changed();
		$history->cache_set_last_changed();

		return true;
	}

	public function generate_step_title( $step ) {
		$flow_id = absint( $this->get_setting( 'flow_id' ) );
		$funnel = $flow_id ? new Funnel( $flow_id ) : false;
		return $funnel && $funnel->exists()
			? sprintf( esc_html__( 'Stop contact in %s', 'groundhogg' ), '<b>' . esc_html( $funnel->get_title() ) . '</b>' )
			: esc_html__( 'Stop contact in selected flow', 'groundhogg' );
	}
}
