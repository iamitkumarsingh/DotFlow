<?php

namespace Groundhogg\Abilities\Emails;

use Groundhogg\Abilities\Ability;
use Groundhogg\DotFlow\Messaging\Provider_Adapter_Registry;
use Groundhogg\DotFlow\Inbound\Inbound_Provider_Registry;
use Groundhogg\DotFlow\Messaging\Postmark_Retry_Queue;

/**
 * Reports registered email transports and the service selected for each
 * message channel. This is a capability/status view only: provider credentials,
 * API keys, SMTP hosts and connection secrets are never returned.
 */
class List_Email_Services extends Ability {

	protected const NAME       = 'groundhogg/list-email-services';
	protected const CATEGORY   = 'groundhogg-email';
	protected const CAPABILITY = 'send_emails';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		$channel_schema = [
			'type'       => 'object',
			'properties' => [
				'service_id'   => [ 'type' => 'string' ],
				'service_name' => [ 'type' => 'string' ],
				'registered'   => [ 'type' => 'boolean' ],
				'callable'     => [ 'type' => 'boolean' ],
				'available'    => [ 'type' => 'boolean' ],
			],
		];

		return [
			'label'       => __( 'List Email Services', 'groundhogg' ),
			'description' => __( 'List registered email delivery services, provider webhook adapters and the selected service for WordPress, transactional and marketing email. Reports callable transport status without returning credentials or provider secrets.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'services' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'   => [ 'type' => 'string' ],
								'name'     => [ 'type' => 'string' ],
								'callable' => [ 'type' => 'boolean' ],
							],
						],
					],
					'delivery_webhook_adapters' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'inbound_email_adapters'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'postmark_outbox' => [
						'type' => 'object',
						'properties' => [
							'pending' => [ 'type' => 'integer' ],
							'processing' => [ 'type' => 'integer' ],
							'accepted' => [ 'type' => 'integer' ],
							'failed' => [ 'type' => 'integer' ],
							'uncertain' => [ 'type' => 'integer' ],
						],
					],
					'channels' => [
						'type'       => 'object',
						'properties' => [
							'wordpress'     => $channel_schema,
							'transactional' => $channel_schema,
							'marketing'     => $channel_schema,
						],
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$registered = \Groundhogg_Email_Services::dropdown();
		$services   = [];

		foreach ( $registered as $id => $name ) {
			$services[] = [
				'id'       => sanitize_key( (string) $id ),
				'name'     => sanitize_text_field( (string) $name ),
				'callable' => is_callable( \Groundhogg_Email_Services::get_service_callback( $id ) ),
			];
		}

		$channels = [];

		foreach ( [ 'wordpress', 'transactional', 'marketing' ] as $channel ) {

			$service_id = (string) \Groundhogg_Email_Services::get_saved_service( $channel );
			$is_registered = array_key_exists( $service_id, $registered );
			$is_callable   = $is_registered && is_callable( \Groundhogg_Email_Services::get_service_callback( $service_id ) );

			$channels[ $channel ] = [
				'service_id'   => sanitize_key( $service_id ),
				'service_name' => $is_registered ? sanitize_text_field( (string) $registered[ $service_id ] ) : '',
				'registered'   => $is_registered,
				'callable'     => $is_callable,
				'available'    => $is_registered && $is_callable,
			];
		}

		return [
			'services'                  => $services,
			'delivery_webhook_adapters' => Provider_Adapter_Registry::ids(),
			'inbound_email_adapters'    => Inbound_Provider_Registry::ids(),
			'postmark_outbox'           => Postmark_Retry_Queue::counts(),
			'channels'                  => $channels,
		];
	}
}
