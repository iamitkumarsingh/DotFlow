<?php

namespace Groundhogg\DotFlow\Abilities;

use Groundhogg\Abilities\Ability;

/**
 * Provides a machine-readable map of DotFlow capabilities to authorised AI/MCP clients.
 *
 * This manifest contains product metadata and capability states only. Private records
 * are exposed through separate abilities with their own permission checks.
 */
class Get_System_Manifest extends Ability {

	protected const NAME       = 'dotflow/get-system-manifest';
	protected const CATEGORY   = 'dotflow-platform';
	protected const CAPABILITY = 'read';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Get DotFlow System Manifest', 'groundhogg' ),
			'description' => __( 'Read DotFlow product metadata, module availability, API entry points and the supported AI management model. Does not return contact data, message content, credentials or other private records.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => new \stdClass(),
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'product'        => [ 'type' => 'string' ],
					'plugin_version' => [ 'type' => 'string' ],
					'api_version'    => [ 'type' => 'string' ],
					'foundation'     => [ 'type' => 'string' ],
					'api_base'       => [ 'type' => 'string' ],
					'rest_namespace' => [ 'type' => 'string' ],
					'elementor'      => [
						'type'       => 'object',
						'properties' => [
							'available' => [ 'type' => 'boolean' ],
							'version'   => [ 'type' => 'string' ],
							'editing'   => [ 'type' => 'string' ],
							'form_widget' => [ 'type' => 'boolean' ],
						],
					],
					'modules'        => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'          => [ 'type' => 'string' ],
								'label'       => [ 'type' => 'string' ],
								'status'      => [ 'type' => 'string' ],
								'description' => [ 'type' => 'string' ],
								'abilities'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
							],
						],
					],
					'data_policy'    => [
						'type'       => 'object',
						'properties' => [
							'private_data_requires_capability' => [ 'type' => 'boolean' ],
							'credentials_exposed_to_ai'        => [ 'type' => 'boolean' ],
							'mutations_audited'                => [ 'type' => 'boolean' ],
							'audit_coverage'                   => [ 'type' => 'string' ],
						],
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$elementor_available = did_action( 'elementor/loaded' ) > 0 || class_exists( '\Elementor\Plugin' );
		$elementor_version   = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '';

		return [
			'product'        => 'DotFlow',
			'plugin_version' => defined( 'DOTFLOW_VERSION' ) ? DOTFLOW_VERSION : '0.1.0-dev',
			'api_version'    => defined( 'DOTFLOW_API_VERSION' ) ? DOTFLOW_API_VERSION : 'v1',
			'foundation'     => 'Groundhogg GPLv3-derived WordPress plugin',
			'api_base'       => untrailingslashit( rest_url() ),
			'rest_namespace' => 'dotflow/v1',
			'elementor'      => [
				'available' => (bool) $elementor_available,
				'version'   => (string) $elementor_version,
				'editing'   => 'Elementor documents are edited through Elementor document APIs; DotFlow records are managed through scoped WordPress Abilities and REST endpoints.',
				'form_widget' => $elementor_available && class_exists( \Groundhogg\DotFlow\Elementor\Widgets\Form_Widget::class ),
			],
			'modules'        => [
				[
					'id'          => 'crm',
					'label'       => 'Contacts and CRM',
					'status'      => 'inherited',
					'description' => 'Groundhogg contact records, custom fields, tags, notes, search and contact permissions.',
					'abilities'   => [ 'groundhogg/search-contacts', 'groundhogg/get-contact', 'groundhogg/create-contact', 'groundhogg/update-contact', 'groundhogg/list-custom-fields', 'groundhogg/add-custom-field', 'groundhogg/update-custom-field', 'groundhogg/add-contact-note', 'groundhogg/list-contact-notes', 'groundhogg/list-tags', 'groundhogg/create-tag', 'groundhogg/describe-table', 'groundhogg/query-table' ],
				],
				[
					'id'          => 'campaigns',
					'label'       => 'Campaigns and broadcasts',
					'status'      => 'inherited',
					'description' => 'Groundhogg campaigns, email templates, broadcasts and their supported management abilities.',
					'abilities'   => [ 'groundhogg/list-campaigns', 'groundhogg/create-campaign', 'groundhogg/list-email-templates', 'groundhogg/create-email-template', 'groundhogg/update-email-template', 'groundhogg/list-broadcasts', 'groundhogg/get-broadcast', 'groundhogg/send-email-broadcast', 'groundhogg/cancel-broadcast' ],
				],
				[
					'id'          => 'automation',
					'label'       => 'Visual automation',
					'status'      => 'inherited',
					'description' => 'Groundhogg flows, step types, draft editing, simulation, publishing and activation.',
					'abilities'   => [ 'groundhogg/list-flows', 'groundhogg/get-flow', 'groundhogg/create-flow', 'groundhogg/edit-flow', 'groundhogg/simulate-flow', 'groundhogg/publish-flow-changes', 'groundhogg/activate-flow', 'groundhogg/deactivate-flow' ],
				],
				[
					'id'          => 'reporting',
					'label'       => 'Reporting',
					'status'      => 'inherited',
					'description' => 'Groundhogg report types and date-bounded report data.',
					'abilities'   => [ 'groundhogg/list-report-types', 'groundhogg/get-reports' ],
				],
				[
					'id'          => 'settings',
					'label'       => 'Platform settings',
					'status'      => 'inherited',
					'description' => 'Read and update only settings registered for authorised Abilities API access.',
					'abilities'   => [ 'groundhogg/list-settings', 'groundhogg/update-settings' ],
				],
				[
					'id'          => 'domain-events',
					'label'       => 'DotFlow domain events',
					'status'      => 'implemented',
					'description' => 'Typed in-process event envelopes, durable event storage, source-scoped idempotency, sensitive-field redaction and administrator-only event history discovery.',
					'abilities'   => [ 'dotflow/list-events' ],
				],
				[
					'id'          => 'conversations',
					'label'       => 'Conversation history',
					'status'      => 'implemented',
					'description' => 'AI-readable inbound/outbound message history with contact-level permission checks and an authorised read/unread mutation.',
					'abilities'   => [ 'dotflow/list-messages', 'dotflow/set-message-read-state' ],
				],
				[
					'id'          => 'messaging',
					'label'       => 'Provider-independent messaging and conversations',
					'status'      => 'planned',
					'description' => 'Independent outbound provider adapters, inbound webhook/IMAP ingestion and conversation management.',
					'abilities'   => [],
				],
				[
					'id'          => 'elementor',
					'label'       => 'Elementor editing',
					'status'      => 'foundation',
					'description' => 'Native DotFlow Form widget and Elementor document editing through Elementor-compatible MCP save APIs.',
					'abilities'   => [ 'dotflow/list-published-forms' ],
				],
				[
					'id'          => 'integrations',
					'label'       => 'External integrations',
					'status'      => 'planned',
					'description' => 'Elementor, DotForm, HubSpot, WooCommerce, GA4 and generic webhook adapters.',
					'abilities'   => [],
				],
			],
			'data_policy'    => [
				'private_data_requires_capability' => true,
				'credentials_exposed_to_ai'        => false,
				'mutations_audited'                => false,
				'audit_coverage'                   => 'Partial: DotFlow message read-state; contact create/upsert/update; tag create/update; custom-field-definition create/update; campaign create/upsert and association; and flow create/edit/activate/deactivate/publish/discard Abilities emit privacy-minimised audit events. Broadcast schedule/cancel, email-template create/update, and changed settings also emit privacy-minimised audit events. Deletion and other remaining mutation paths still need coverage.',
			],
		];
	}
}
