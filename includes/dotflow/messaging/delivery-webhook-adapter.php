<?php

namespace Groundhogg\DotFlow\Messaging;

use WP_Error;
use WP_REST_Request;

/**
 * Contract for a provider-specific outbound delivery webhook adapter.
 *
 * Adapters authenticate the provider's native signature and translate its
 * payload into the small, provider-neutral envelope consumed by DotFlow.
 * Implementations must not return raw webhook bodies, credentials or addresses.
 */
interface Delivery_Webhook_Adapter {

	/**
	 * Stable lowercase provider identifier used in the route.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Verify the provider's signature, timestamp and replay protection.
	 *
	 * @param WP_REST_Request $request Request with its original body intact.
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request );

	/**
	 * Convert the provider payload to DotFlow's normalized status envelope.
	 *
	 * Required keys: event_id, message_id, status, channel.
	 * Optional key: error_code.
	 *
	 * @param array $payload Provider payload.
	 * @return array|WP_Error
	 */
	public function normalize( array $payload );
}
