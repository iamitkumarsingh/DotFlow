<?php

namespace Groundhogg\DotFlow\Integrations;

use Groundhogg\Contact;
use Groundhogg\DotFlow\Events\Dispatcher;
use Groundhogg\DotFlow\Events\Event;

/**
 * Optional DotForm-to-DotFlow event bridge.
 *
 * DotForm emits dotform/submission/after with a contact ID, form ID,
 * submission ID and optional stable event ID. Only identifiers are retained.
 */
final class DotForm_Integration {

	public static function register() {
		add_action( 'dotform/submission/after', [ __CLASS__, 'record_submission' ], 10, 4 );
	}

	/**
	 * @param int    $contact_id    Existing Groundhogg contact ID.
	 * @param int    $form_id       DotForm form ID.
	 * @param int    $submission_id DotForm submission ID.
	 * @param string $event_id      Optional stable source event ID.
	 */
	public static function record_submission( $contact_id, $form_id, $submission_id, $event_id = '' ) {
		$contact_id = absint( $contact_id );
		$form_id = absint( $form_id );
		$submission_id = absint( $submission_id );
		if ( ! $contact_id || ! $form_id || ! $submission_id ) {
			return;
		}

		$contact = new Contact( $contact_id );
		if ( ! $contact->exists() ) {
			return;
		}

		$event_id = sanitize_text_field( (string) $event_id );
		if ( '' === $event_id ) {
			$event_id = 'dotform-submission-' . $submission_id;
		}
		if ( strlen( $event_id ) > 120 ) {
			return;
		}

		$payload = [
			'contact_id' => $contact_id,
			'object_type' => 'submission',
			'object_id' => $submission_id,
			'form_id' => $form_id,
			'form_provider' => 'dotform',
		];

		Dispatcher::dispatch( new Event( 'form.submitted', $payload, 'dotform', $event_id ) );
		if ( $contact->is_marketable() ) {
			Dispatcher::dispatch( new Event( 'form.marketing_eligible', $payload, 'dotform', 'dotform-marketing-' . substr( hash( 'sha256', $event_id ), 0, 48 ) ) );
		}
	}
}
