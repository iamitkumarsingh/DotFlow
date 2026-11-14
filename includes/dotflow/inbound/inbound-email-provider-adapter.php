<?php

namespace Groundhogg\DotFlow\Inbound;

use WP_Error;
use WP_REST_Request;

/**
 * Contract for a provider-specific inbound email webhook adapter.
 *
 * Adapters verify the provider's native signature and normalize its payload.
 */
interface Inbound_Email_Provider_Adapter {

	public function get_id(): string;

	/**
	 * Verify provider signature, timestamp and replay protection.
	 *
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request );

	/**
	 * Convert provider payload to the normalized inbound email envelope.
	 *
	 * @return array|WP_Error
	 */
	public function normalize( array $payload );
}
