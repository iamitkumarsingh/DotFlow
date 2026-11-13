<?php

namespace Groundhogg\DotFlow\Steps\Benchmarks;

use Groundhogg\DotFlow\Events\Event;
use Groundhogg\Steps\Benchmarks\Benchmark;
use Groundhogg\Step;
use function Groundhogg\get_contactdata;
use function Groundhogg\html;

/**
 * Starts a Groundhogg workflow when a durable DotFlow event is recorded.
 *
 * Only the event name, source and stable ID are passed into the workflow queue;
 * the arbitrary event payload is deliberately not copied into workflow args.
 */
class DotFlow_Event extends Benchmark {

	public function get_name() {
		return esc_html_x( 'DotFlow Event', 'step_name', 'groundhogg' );
	}

	public function get_type() {
		return 'dotflow_event';
	}

	public function get_sub_group() {
		return 'other';
	}

	public function get_description() {
		return esc_html_x( 'Run when a matching DotFlow event is recorded for a contact.', 'step_description', 'groundhogg' );
	}

	public function get_icon() {
		return GROUNDHOGG_ASSETS_URL . 'images/funnel-icons/developer/custom-activity.svg';
	}

	protected function get_complete_hooks() {
		return [
			[ 'dotflow/event', 1 ],
		];
	}

	/**
	 * Capture only contact-linked event metadata.
	 *
	 * @param Event $event DotFlow event.
	 * @return void
	 */
	public function setup( $event ) {

		if ( ! $event instanceof Event ) {
			return;
		}

		$payload    = $event->get_payload();
		$contact_id = isset( $payload['contact_id'] ) ? absint( $payload['contact_id'] ) : 0;
		$contact    = $contact_id ? get_contactdata( $contact_id ) : false;

		if ( ! $contact || ! $contact->exists() ) {
			return;
		}

		$this->set_current_contact( $contact );
		$this->add_data( 'event_name', $event->get_name() );
		$this->add_data( 'event_source', $event->get_source() );
		$this->add_data( 'event_id', $event->get_id() );
	}

	protected function get_the_contact() {
		return $this->get_current_contact();
	}

	protected function can_complete_step() {

		$event_name = (string) $this->get_data( 'event_name' );
		$event_source = (string) $this->get_data( 'event_source' );

		$expected_name   = (string) $this->get_setting( 'event_name' );
		$expected_source = (string) $this->get_setting( 'source' );

		return self::matches_event( $event_name, $event_source, $expected_name, $expected_source );
	}

	/**
	 * Match a recorded event against a configured workflow trigger.
	 *
	 * @param string $event_name     Recorded event name.
	 * @param string $event_source   Recorded source.
	 * @param string $expected_name  Configured event name.
	 * @param string $expected_source Optional configured source.
	 * @return bool
	 */
	public static function matches_event( $event_name, $event_source, $expected_name, $expected_source = '' ): bool {

		// AI mutation audit records are for governance, not customer journey triggers.
		if ( $event_name === 'ai.mutation.completed' || $event_name === '' || $expected_name === '' ) {
			return false;
		}

		return $event_name === $expected_name && ( $expected_source === '' || $event_source === $expected_source );
	}

	public function settings( $step ) {

		html( 'p', [], esc_html__( 'Run when this DotFlow event is recorded for a contact. Only the event name and source are passed to the workflow; event payload data is not copied.', 'groundhogg' ) );

		html( 'label', [
			'for' => $this->setting_id_prefix( 'event_name' ),
		], esc_html__( 'Event name', 'groundhogg' ) );

		html( 'input', [
			'type'        => 'text',
			'id'          => $this->setting_id_prefix( 'event_name' ),
			'name'        => $this->setting_name_prefix( 'event_name' ),
			'value'       => $this->get_setting( 'event_name' ),
			'placeholder' => 'email.inbound.received',
			'class'       => 'input',
		] );

		html( 'label', [
			'for' => $this->setting_id_prefix( 'source' ),
		], esc_html__( 'Source (optional)', 'groundhogg' ) );

		html( 'input', [
			'type'        => 'text',
			'id'          => $this->setting_id_prefix( 'source' ),
			'name'        => $this->setting_name_prefix( 'source' ),
			'value'       => $this->get_setting( 'source' ),
			'placeholder' => 'email',
			'class'       => 'input',
		] );

		html( 'p' );
	}

	public function get_settings_schema() {
		return [
			'event_name' => [
				'default'  => '',
				'sanitize' => function ( $value ) {
					$value = sanitize_text_field( (string) $value );

					return preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/', $value ) ? $value : '';
				},
			],
			'source' => [
				'default'  => '',
				'sanitize' => function ( $value ) {
					return substr( sanitize_key( (string) $value ), 0, 64 );
				},
			],
		];
	}

	public function validate_settings( Step $step ) {

		$event_name = (string) $this->get_setting( 'event_name' );

		if ( $event_name === '' ) {
			$step->add_error( 'dotflow_event_name_required', __( 'Select a valid DotFlow event name.', 'groundhogg' ) );
			return;
		}

		if ( $event_name === 'ai.mutation.completed' ) {
			$step->add_error( 'dotflow_event_audit_not_triggerable', __( 'AI mutation audit events cannot be used as customer journey triggers.', 'groundhogg' ) );
		}
	}

	public function generate_step_title( $step ) {

		$event_name = (string) $this->get_setting( 'event_name' );

		if ( $event_name === '' ) {
			return esc_html__( 'When a DotFlow event occurs', 'groundhogg' );
		}

		return sprintf(
			/* translators: %s: DotFlow event name */
			esc_html__( 'When %s occurs', 'groundhogg' ),
			'<b>' . esc_html( $event_name ) . '</b>'
		);
	}
}
