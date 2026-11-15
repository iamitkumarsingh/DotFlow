<?php

namespace Groundhogg\DotFlow\Events;

use Groundhogg\Contact;

/**
 * Bridges native Groundhogg CRM lifecycle changes into DotFlow events.
 */
final class CRM_Events {

	/**
	 * Register contact, tag and unsubscribe lifecycle hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'groundhogg/contact/created', [ __CLASS__, 'record_contact_created' ] );
		add_action( 'groundhogg/contact/tag_applied', [ __CLASS__, 'record_tag_applied' ], 10, 2 );
		add_action( 'groundhogg/contact/tag_removed', [ __CLASS__, 'record_tag_removed' ], 10, 2 );
		add_action( 'groundhogg/contact/preferences/unsubscribed', [ __CLASS__, 'record_unsubscribe' ], 10, 4 );
	}

	/**
	 * Record a newly created contact.
	 *
	 * @param Contact $contact Contact.
	 * @return void
	 */
	public static function record_contact_created( $contact ) {

		if ( ! self::valid_contact( $contact ) ) {
			return;
		}

		$contact_id = absint( $contact->get_id() );

		Dispatcher::dispatch( new Event(
			'contact.created',
			[
				'contact_id'  => $contact_id,
				'object_type' => 'contact',
				'object_id'   => $contact_id,
			],
			'groundhogg',
			'contact-created-' . $contact_id
		) );
	}

	/**
	 * Record a tag being applied to a contact.
	 *
	 * @param Contact $contact Contact.
	 * @param int     $tag_id  Tag ID.
	 * @return void
	 */
	public static function record_tag_applied( $contact, $tag_id ) {
		self::record_tag_change( $contact, $tag_id, 'tag.applied' );
	}

	/**
	 * Record a tag being removed from a contact.
	 *
	 * @param Contact $contact Contact.
	 * @param int     $tag_id  Tag ID.
	 * @return void
	 */
	public static function record_tag_removed( $contact, $tag_id ) {
		self::record_tag_change( $contact, $tag_id, 'tag.removed' );
	}

	/**
	 * Record an unsubscribe transition.
	 *
	 * @param int         $contact_id Contact ID.
	 * @param string      $new_value  New preference.
	 * @param string      $old_value  Previous preference.
	 * @param Contact|null $contact   Contact instance.
	 * @return void
	 */
	public static function record_unsubscribe( $contact_id, $new_value, $old_value, $contact = null ) {

		if ( ! self::valid_contact( $contact ) || absint( $contact->get_id() ) !== absint( $contact_id ) ) {
			return;
		}

		$contact_id = absint( $contact->get_id() );

		Dispatcher::dispatch( new Event(
			'contact.unsubscribed',
			[
				'contact_id'  => $contact_id,
				'object_type' => 'contact',
				'object_id'   => $contact_id,
			],
			'groundhogg',
			'contact-unsubscribed-' . $contact_id . '-' . wp_generate_uuid4()
		) );
	}

	/**
	 * Persist a contact/tag relationship change.
	 *
	 * @param Contact $contact   Contact.
	 * @param int     $tag_id    Tag ID.
	 * @param string  $event_name Event name.
	 * @return void
	 */
	private static function record_tag_change( $contact, $tag_id, $event_name ) {

		if ( ! self::valid_contact( $contact ) || ! absint( $tag_id ) ) {
			return;
		}

		$contact_id = absint( $contact->get_id() );
		$tag_id     = absint( $tag_id );

		Dispatcher::dispatch( new Event(
			$event_name,
			[
				'contact_id'  => $contact_id,
				'object_type' => 'contact_tag',
				'object_id'   => $tag_id,
				'tag_id'      => $tag_id,
			],
			'groundhogg',
			sanitize_key( str_replace( '.', '-', $event_name ) ) . '-' . $contact_id . '-' . $tag_id . '-' . wp_generate_uuid4()
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
}
