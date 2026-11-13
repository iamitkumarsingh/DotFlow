<?php

namespace Groundhogg\DotFlow\REST;

use Groundhogg\DotFlow\Abilities\Get_System_Manifest;
use Groundhogg\DotFlow\Abilities\List_Events;
use Groundhogg\DotFlow\Inbound\Inbound_Email_Webhook;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Versioned REST discovery and narrowly scoped provider ingress for DotFlow.
 *
 * Mutations remain on purpose-built WordPress Abilities until their REST
 * contracts, audit guarantees and approval requirements are implemented.
 */
final class Routes {

	/**
	 * Register DotFlow REST routes.
	 *
	 * @return void
	 */
	public static function register() {

		Inbound_Email_Webhook::register_route();

		register_rest_route( 'dotflow/v1', '/manifest', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_manifest' ],
				'permission_callback' => [ __CLASS__, 'can_read' ],
			],
		] );

		register_rest_route( 'dotflow/v1', '/schema', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_schema' ],
				'permission_callback' => [ __CLASS__, 'can_read' ],
			],
		] );

		register_rest_route( 'dotflow/v1', '/events', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_events' ],
				'permission_callback' => [ __CLASS__, 'can_manage_events' ],
				'args'                => [
					'event_name'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'source'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
					'contact_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
					'object_type' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
					'object_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
					'after'       => [ 'type' => 'string' ],
					'before'      => [ 'type' => 'string' ],
					'limit'       => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
					'offset'      => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0 ],
				],
			],
		] );
	}

	/**
	 * Require an authenticated user with basic WordPress read access.
	 *
	 * @return bool
	 */
	public static function can_read() {
		return current_user_can( 'read' );
	}

	/**
	 * Event payloads are administrator-only.
	 *
	 * @return bool
	 */
	public static function can_manage_events() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return the machine-readable DotFlow manifest.
	 *
	 * @return array
	 */
	public static function get_manifest() {
		$ability = ( new \ReflectionClass( Get_System_Manifest::class ) )->newInstanceWithoutConstructor();

		return $ability( [] );
	}

	/**
	 * Describe supported object families and safe management surfaces.
	 *
	 * @return array
	 */
	public static function get_schema() {
		return [
			'schema_version' => '1.0',
			'api_version'    => 'v1',
			'objects'        => [
				'contact' => [
					'storage'         => 'Groundhogg contacts and contact meta',
					'read_abilities'  => [ 'groundhogg/search-contacts', 'groundhogg/get-contact' ],
					'write_abilities' => [ 'groundhogg/create-contact', 'groundhogg/update-contact' ],
					'permissions'     => [ 'view_contacts', 'add_contacts', 'edit_contact' ],
					'pii'             => 'Field-level PII restrictions apply; private fields require the relevant capability.',
				],
				'tag' => [
					'storage'         => 'Groundhogg tags and contact-tag relationships',
					'read_abilities'  => [ 'groundhogg/list-tags' ],
					'write_abilities' => [ 'groundhogg/create-tag' ],
					'permissions'     => [ 'view_contacts', 'manage_options' ],
				],
				'custom_field' => [
					'storage'         => 'Groundhogg contact property definitions and contact meta values',
					'read_abilities'  => [ 'groundhogg/list-custom-fields' ],
					'write_abilities' => [ 'groundhogg/add-custom-field', 'groundhogg/update-custom-field', 'groundhogg/update-contact' ],
					'permissions'     => [ 'manage_options', 'edit_contact' ],
				],
				'campaign' => [
					'storage'         => 'Groundhogg campaigns and broadcasts',
					'read_abilities'  => [ 'groundhogg/list-campaigns', 'groundhogg/list-broadcasts', 'groundhogg/get-broadcast' ],
					'write_abilities' => [ 'groundhogg/create-campaign', 'groundhogg/create-email-template', 'groundhogg/update-email-template' ],
					'permissions'     => [ 'manage_options' ],
				],
				'workflow' => [
					'storage'         => 'Groundhogg funnels, steps and flow metadata',
					'read_abilities'  => [ 'groundhogg/list-flows', 'groundhogg/get-flow' ],
					'write_abilities' => [ 'groundhogg/create-flow', 'groundhogg/edit-flow', 'groundhogg/publish-flow-changes', 'groundhogg/activate-flow', 'groundhogg/deactivate-flow' ],
					'permissions'     => [ 'manage_funnels' ],
				],
				'event' => [
					'storage'         => 'DotFlow durable domain event store',
					'read_abilities'  => [ 'dotflow/list-events' ],
					'write_abilities' => [],
					'permissions'     => [ 'manage_options' ],
				],
				'conversation_message' => [
					'storage'         => 'Groundhogg conversation message store',
					'read_abilities'  => [ 'dotflow/list-messages' ],
					'write_abilities' => [ 'dotflow/set-message-read-state' ],
					'permissions'     => [ 'view_contact', 'manage_options' ],
					'ingest_endpoint' => '/wp-json/dotflow/v1/inbound/email',
				],
			],
			'write_policy' => [
				'rest_mutations'       => 'No generic REST CRUD. Only the token-authenticated provider-neutral inbound email endpoint is writable.',
				'preferred_interface' => 'Purpose-built WordPress Abilities with strict schemas and capability checks.',
				'arbitrary_sql'        => false,
				'credential_readback'  => false,
				'high_impact_approval' => 'Campaign sends, workflow activation/deactivation, platform settings and other destructive operations are marked for confirmation by compatible AI hosts.',
			],
		];
	}

	/**
	 * Return paginated event history through the same administrator-only ability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function get_events( WP_REST_Request $request ) {
		$ability = ( new \ReflectionClass( List_Events::class ) )->newInstanceWithoutConstructor();

		return $ability( $request->get_params() );
	}
}
