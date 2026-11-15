<?php

namespace Groundhogg\DotFlow\Events;

use Groundhogg\Classes\Activity;
use Groundhogg\Classes\Page_Visit;
use Groundhogg\Contact;

/**
 * Bridges native Groundhogg web and engagement tracking into DotFlow events.
 *
 * No new visitor fingerprinting or client-side tracking is introduced. Page
 * visits and email clicks are emitted only when Groundhogg has already
 * associated the activity with a contact.
 */
final class Tracking_Events {

	/**
	 * Register native tracking hooks and the CTA integration point.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'groundhogg/tracking/page_visit', [ __CLASS__, 'record_page_visit' ], 10, 2 );
		add_action( 'groundhogg/track_activity/email_link_click', [ __CLASS__, 'record_email_link_click' ], 10, 2 );
		add_action( 'dotflow/track/cta_click', [ __CLASS__, 'record_cta_click' ], 10, 4 );
	}

	/**
	 * Record a Groundhogg page visit as a contact-linked DotFlow event.
	 *
	 * @param Page_Visit $visit   Persisted page visit.
	 * @param Contact    $contact Associated contact.
	 * @return void
	 */
	public static function record_page_visit( $visit, $contact ) {

		if ( ! $visit instanceof Page_Visit || ! $visit->exists() || ! self::valid_contact( $contact ) ) {
			return;
		}

		$visit_id = absint( $visit->get_id() );
		$path     = self::safe_path( $visit->get_path() );

		if ( ! $visit_id ) {
			return;
		}

		Dispatcher::dispatch( new Event(
			'page.viewed',
			[
				'contact_id'    => absint( $contact->get_id() ),
				'object_type'   => 'page_visit',
				'object_id'     => $visit_id,
				'page_path'     => $path,
			],
			'groundhogg',
			'page-visit-' . $visit_id
		) );
	}

	/**
	 * Record a tracked email link click without persisting its query string.
	 *
	 * @param Activity $activity Persisted activity.
	 * @param Contact  $contact  Associated contact.
	 * @return void
	 */
	public static function record_email_link_click( $activity, $contact ) {

		if ( ! $activity instanceof Activity || ! $activity->exists() || ! self::valid_contact( $contact ) ) {
			return;
		}

		$activity_id = absint( $activity->get_id() );
		if ( ! $activity_id ) {
			return;
		}

		$target = (string) $activity->get_meta( 'referer' );

		Dispatcher::dispatch( new Event(
			'link.clicked',
			[
				'contact_id'  => absint( $contact->get_id() ),
				'object_type' => 'activity',
				'object_id'   => $activity_id,
				'link_path'   => self::safe_path( $target ),
				'channel'     => 'email',
			],
			'groundhogg',
			'activity-link-click-' . $activity_id
		) );
	}

	/**
	 * Public integration point for contact-aware website CTA click tracking.
	 *
	 * Integrations may call do_action( 'dotflow/track/cta_click', $contact,
	 * $element_id, $url, $event_id ) after their own consent and click checks.
	 * No raw URL query, label text, form values or personal data is stored.
	 *
	 * @param Contact    $contact    Associated contact.
	 * @param string     $element_id Stable CTA identifier.
	 * @param string     $url        Destination URL.
	 * @param string     $event_id   Optional stable ID for idempotency.
	 * @return void
	 */
	public static function record_cta_click( $contact, $element_id, $url = '', $event_id = '' ) {

		if ( ! self::valid_contact( $contact ) ) {
			return;
		}

		$element_id = sanitize_key( (string) $element_id );
		if ( '' === $element_id || strlen( $element_id ) > 64 ) {
			return;
		}

		$event_id = sanitize_text_field( (string) $event_id );
		if ( '' === $event_id ) {
			$event_id = 'cta-' . wp_generate_uuid4();
		}

		if ( strlen( $event_id ) > 120 ) {
			return;
		}

		Dispatcher::dispatch( new Event(
			'cta.clicked',
			[
				'contact_id'  => absint( $contact->get_id() ),
				'object_type' => 'contact',
				'object_id'   => absint( $contact->get_id() ),
				'element_id'  => $element_id,
				'page_path'   => self::safe_path( $url ),
			],
			'groundhogg',
			$event_id
		) );
	}

	/**
	 * Check that an existing Groundhogg contact is available.
	 *
	 * @param mixed $contact Contact candidate.
	 * @return bool
	 */
	private static function valid_contact( $contact ) {
		return $contact instanceof Contact && $contact->exists() && absint( $contact->get_id() ) > 0;
	}

	/**
	 * Keep only a safe local path; discard query strings, fragments and host.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	private static function safe_path( $url ) {

		$url  = is_string( $url ) ? $url : '';
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		$path = '/' . ltrim( sanitize_text_field( $path ), '/' );

		return substr( $path, 0, 500 );
	}
}
