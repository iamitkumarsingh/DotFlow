<?php

namespace Groundhogg\DotFlow\Events;

use Groundhogg\Contact;
use Groundhogg\Submission;

/**
 * Bridges successful CRM form submissions and marketing-consent changes into
 * privacy-minimised DotFlow domain events.
 */
final class Form_Submission {

	/**
	 * Register native Groundhogg lifecycle hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'groundhogg/form/submission_handler/after', [ __CLASS__, 'record_submission' ], 10, 3 );
		add_action( 'groundhogg/contact/added_marketing_consent', [ __CLASS__, 'record_marketing_consent_granted' ] );
		add_action( 'groundhogg/contact/revoked_marketing_consent', [ __CLASS__, 'record_marketing_consent_revoked' ] );
	}

	/**
	 * Record a successfully completed CRM form submission.
	 *
	 * The event contains identifiers only. Submitted field values, email
	 * addresses, message content and uploaded file URLs are never copied.
	 *
	 * @param Submission $submission Saved submission.
	 * @param Contact    $contact    Associated contact.
	 * @param object     $handler    Submission handler.
	 * @return void
	 */
	public static function record_submission( $submission, $contact, $handler = null ) {

		if ( ! $submission instanceof Submission || ! $contact instanceof Contact || ! $submission->exists() || ! $contact->exists() ) {
			return;
		}

		$submission_id = absint( $submission->get_id() );
		$contact_id    = absint( $contact->get_id() );
		$form_id       = absint( $submission->get_form_id() );

		if ( ! $submission_id || ! $contact_id || ! $form_id ) {
			return;
		}

		$payload = [
			'contact_id'  => $contact_id,
			'object_type' => 'submission',
			'object_id'   => $submission_id,
			'form_id'     => $form_id,
		];

		Dispatcher::dispatch(
			new Event(
				'form.submitted',
				$payload,
				'groundhogg',
				'form-submission-' . $submission_id
			)
		);

		// A separate trigger makes marketing eligibility explicit. The native
		// Groundhogg marketability check includes opt-in and suppression rules.
		if ( $contact->is_marketable() ) {
			Dispatcher::dispatch(
				new Event(
					'form.marketing_eligible',
					$payload,
					'groundhogg',
					'form-marketing-eligible-' . $submission_id
				)
			);
		}
	}

	/**
	 * Record an explicit marketing-consent grant without storing personal data.
	 *
	 * @param Contact $contact Contact whose consent changed.
	 * @return void
	 */
	public static function record_marketing_consent_granted( $contact ) {
		self::record_consent_change( $contact, 'contact.marketing_consent.granted' );
	}

	/**
	 * Record an explicit marketing-consent revocation without storing personal data.
	 *
	 * @param Contact $contact Contact whose consent changed.
	 * @return void
	 */
	public static function record_marketing_consent_revoked( $contact ) {
		self::record_consent_change( $contact, 'contact.marketing_consent.revoked' );
	}

	/**
	 * Persist a consent state transition.
	 *
	 * @param Contact $contact   Contact whose consent changed.
	 * @param string  $event_name Event name.
	 * @return void
	 */
	private static function record_consent_change( $contact, $event_name ) {

		if ( ! $contact instanceof Contact || ! $contact->exists() ) {
			return;
		}

		$contact_id = absint( $contact->get_id() );
		if ( ! $contact_id ) {
			return;
		}

		Dispatcher::dispatch(
			new Event(
				$event_name,
				[
					'contact_id'  => $contact_id,
					'object_type' => 'contact',
					'object_id'   => $contact_id,
				],
				'groundhogg',
				sanitize_key( str_replace( '.', '-', $event_name ) ) . '-' . $contact_id . '-' . wp_generate_uuid4()
			)
		);
	}
}
