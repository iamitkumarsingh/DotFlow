<?php

namespace Groundhogg\Abilities\Emails;

use Groundhogg\Abilities\Ability;

/**
 * Reports the registered email transports and the service selected for each
 * message channel. This is intentionally a capability/status view only: provider
 * credentials, API keys, SMTP hosts and connection secrets are never returned.
 */
class List_Email_Services extends Ability {

	protected const NAME       = 'groundhogg/list-email-services';
	protected const CATEGORY   = 'groundhogg-email';
	protected const CAPABILITY = 'send_emails';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List Email Services', 'groundhogg' ),
			'description' => __( 'List registered email delivery services and the selected service for WordPress, transactional and marketing email. Reports availability and public service names only; never returns credentials or provider secrets.', 'groundhogg' ),
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
								'name' => [ 'type' => 'string' ],
							],
						],
					],
					'channels' => [
						'type'       => 'object',
						'properties' => [
							'wordpress'     => [ '$ref' => '#/$defs/channel' ],
							'transactional' => [ '$ref' => '#/$defs/channel' ],
							'marketing'     => [ '$ref' => '#/$defs/channel' ],
						],
					],
				],
				'$defs' => [
					'channel' => [
						'type'       => 'object',
						'properties' => [
							'service_id'   => [ 'type' => 'string' ],
							'service_name' => [ 'type' => 'string' ],
						'available'    => [ 'type' => 'boolean' ],
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
				'id'   => sanitize_key( (string) $id ),
				'name' => sanitize_text_field( (string) $name ),
			];
		}

		$channels = [];

		foreach ( [ 'wordpress', 'transactional', 'marketing' ] as $channel ) {

			$service_id = (string) \Groundhogg_Email_Services::get_saved_service( $channel );
			$available  = array_key_exists( $service_id, $registered );

			$channels[ $channel ] = [
				'service_id'   => sanitize_key( $service_id ),
				'service_name' => $available ? sanitize_text_field( (string) $registered[ $service_id ] ) : '',
				'available'    => $available,
			];
		}

		return [
			'services' => $services,
			'channels' => $channels,
		];
	}
}
