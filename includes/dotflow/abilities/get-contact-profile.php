<?php

namespace Groundhogg\DotFlow\Abilities;

use Groundhogg\Abilities\Ability;
use Groundhogg\Abilities\Schemas\Contact_Schema;
use Groundhogg\Abilities\Schemas\Note_Schema;
use Groundhogg\Pii;
use WP_Error;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

/**
 * Returns a permission-scoped CRM profile with explicitly requested context.
 *
 * Notes and activity are opt-in expansions. Event payloads are never included.
 */
class Get_Contact_Profile extends Ability {

	protected const NAME       = 'dotflow/get-contact-profile';
	protected const CATEGORY   = 'dotflow-platform';
	protected const CAPABILITY = 'view_contacts';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Get DotFlow Contact Profile', 'groundhogg' ),
			'description' => __( 'Retrieve a contact profile with optional bounded notes and privacy-minimised activity. Contact ownership permissions apply; notes require view_notes. Activity payloads and message bodies are never included.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'contact_id' ],
				'properties'           => [
					'contact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					'expand' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => Contact_Schema::expand_options() ],
						'default'     => [ 'tags' ],
						'description' => __( 'Optional contact expansions such as tags and custom-field meta.', 'groundhogg' ),
					],
					'include_notes'    => [ 'type' => 'boolean', 'default' => false ],
					'include_activity' => [ 'type' => 'boolean', 'default' => false ],
					'notes_limit'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10 ],
					'activity_limit'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10 ],
				],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'contact' => Contact_Schema::get_schema(),
					'notes'   => [
						'type'  => 'array',
						'items' => Note_Schema::get_schema(),
					],
					'activity' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'          => [ 'type' => 'integer' ],
							'event_name'  => [ 'type' => 'string' ],
							'source'      => [ 'type' => 'string' ],
							'object_type' => [ 'type' => 'string' ],
							'object_id'   => [ 'type' => 'integer' ],
							'occurred_at' => [ 'type' => 'string', 'format' => 'date-time' ],
							],
						],
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$contact_id = isset( $input['contact_id'] ) ? absint( $input['contact_id'] ) : 0;
		$contact    = $contact_id ? get_contactdata( $contact_id ) : false;

		if ( ! $contact || ! $contact->exists() ) {
			return new WP_Error( 'dotflow_contact_not_found', __( 'Contact not found.', 'groundhogg' ) );
		}

		if ( ! current_user_can( 'view_contact', $contact ) ) {
			return new WP_Error(
				'dotflow_contact_profile_forbidden',
				__( 'You do not have permission to view this contact.', 'groundhogg' ),
				[ 'status' => 403 ]
			);
		}

		$result = [
			'contact' => Contact_Schema::transform( $contact, $input['expand'] ?? [ 'tags' ] ),
		];

		if ( ! empty( $input['include_notes'] ) ) {

			if ( Pii::is_restricted() ) {
				return Pii::restricted_error( __( 'Contact notes are not available to accounts restricted from personal information.', 'groundhogg' ) );
			}

			if ( ! current_user_can( 'view_notes' ) ) {
				return new WP_Error(
					'dotflow_contact_notes_forbidden',
					__( 'You do not have permission to view contact notes.', 'groundhogg' ),
					[ 'status' => 403 ]
				);
			}

			$notes_db = get_db( 'notes' );
			$notes    = $notes_db->query( [
				'object_type' => 'contact',
				'object_id'   => $contact_id,
				'limit'       => isset( $input['notes_limit'] ) ? min( 25, max( 1, absint( $input['notes_limit'] ) ) ) : 10,
				'orderby'     => 'date_created',
				'order'       => 'DESC',
			] );

			$result['notes'] = array_map( [ Note_Schema::class, 'transform' ], $notes );
		}

		if ( ! empty( $input['include_activity'] ) ) {

			global $wpdb;

			$events_db = get_db( 'dotflow_events' );

			if ( ! $events_db ) {
				return new WP_Error( 'dotflow_events_unavailable', __( 'The DotFlow event store is not available.', 'groundhogg' ) );
			}

			$limit = isset( $input['activity_limit'] ) ? min( 25, max( 1, absint( $input['activity_limit'] ) ) ) : 10;
			$table = $events_db->get_table_name();
			$sql   = "SELECT ID, event_name, source, object_type, object_id, occurred_at FROM {$table} WHERE contact_id = %d ORDER BY occurred_at DESC, ID DESC LIMIT %d";
			$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $contact_id, $limit ) );

			if ( ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'dotflow_contact_activity_query_failed', __( 'Contact activity could not be retrieved.', 'groundhogg' ) );
			}

			$result['activity'] = [];

			foreach ( $rows as $row ) {
				$timestamp = strtotime( $row->occurred_at );

				$result['activity'][] = [
					'id'          => absint( $row->ID ),
					'event_name'  => (string) $row->event_name,
					'source'      => (string) $row->source,
					'object_type' => (string) $row->object_type,
					'object_id'   => absint( $row->object_id ),
					'occurred_at' => false === $timestamp ? '' : gmdate( 'c', $timestamp ),
				];
			}
		}

		return $result;
	}
}
