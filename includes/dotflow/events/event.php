<?php

namespace Groundhogg\DotFlow\Events;

use InvalidArgumentException;

/**
 * Immutable envelope for a DotFlow domain event.
 */
final class Event {

	/** @var string */
	private $id;

	/** @var string */
	private $name;

	/** @var array */
	private $payload;

	/** @var string */
	private $source;

	/** @var string */
	private $occurred_at;

	/**
	 * Create a domain event.
	 *
	 * @param string      $name        Lowercase event name, e.g. contact.created.
	 * @param array       $payload     Event data. Do not include secrets or unnecessary PII.
	 * @param string      $source      Originating subsystem or integration.
	 * @param string|null $id          Stable event identifier, when supplied by a provider.
	 * @param string|null $occurred_at UTC timestamp in ISO 8601 format.
	 */
	public function __construct( $name, array $payload = [], $source = 'internal', $id = null, $occurred_at = null ) {

		$name = (string) $name;

		if ( strlen( $name ) > 191 || ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $name ) ) {
			throw new InvalidArgumentException( 'DotFlow event names must be lowercase and use only letters, numbers, dots, underscores or hyphens.' );
		}

		$id     = $id ? sanitize_text_field( (string) $id ) : wp_generate_uuid4();
		$source = sanitize_key( (string) $source );

		if ( '' === $id || strlen( $id ) > 120 ) {
			throw new InvalidArgumentException( 'DotFlow event IDs must contain between 1 and 120 characters.' );
		}

		if ( '' === $source || strlen( $source ) > 64 ) {
			throw new InvalidArgumentException( 'DotFlow event sources must contain between 1 and 64 safe characters.' );
		}

		$this->id          = $id;
		$this->name        = $name;
		$this->payload     = $payload;
		$this->source      = $source;
		$this->occurred_at = $occurred_at ? (string) $occurred_at : gmdate( 'c' );
	}

	public function get_id() {
		return $this->id;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_payload() {
		return $this->payload;
	}

	public function get_source() {
		return $this->source;
	}

	public function get_occurred_at() {
		return $this->occurred_at;
	}

	/**
	 * Return a serialisable representation suitable for internal logging/storage.
	 *
	 * @return array
	 */
	public function to_array() {
		return [
			'id'          => $this->id,
			'name'        => $this->name,
			'payload'     => $this->payload,
			'source'      => $this->source,
			'occurred_at' => $this->occurred_at,
		];
	}
}
