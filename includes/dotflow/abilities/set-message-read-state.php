<?php

namespace Groundhogg\DotFlow\Abilities;

use Groundhogg\Abilities\Ability;
use WP_Error;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

/**
 * Marks a conversation message read or unread after checking contact access.
 */
class Set_Message_Read_State extends Ability {

	protected const NAME       = 'dotflow/set-message-read-state';
	protected const CATEGORY   = 'dotflow-platform';
	protected const CAPABILITY = 'read';

	protected const READONLY   = false;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Set DotFlow Message Read State', 'groundhogg' ),
			'description' => __( 'Mark a conversation message as read or unread. Requires permission to view the associated contact, or manage_options.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'message_id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The conversation message record ID.', 'groundhogg' ),
					],
					'is_read' => [
						'type'        => 'boolean',
						'description' => __( 'True to mark read; false to mark unread.', 'groundhogg' ),
					],
				],
				'required' => [ 'message_id', 'is_read' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'message_id' => [ 'type' => 'integer' ],
					'is_read'    => [ 'type' => 'boolean' ],
					'updated'    => [ 'type' => 'boolean' ],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$message_id = isset( $input['message_id'] ) ? absint( $input['message_id'] ) : 0;
		$is_read    = ! empty( $input['is_read'] );

		if ( ! $message_id ) {
			return new WP_Error( 'dotflow_invalid_message_id', __( 'A valid message ID is required.', 'groundhogg' ) );
		}

		$db      = get_db( 'messages' );
		$message = $db->get_by( 'ID', $message_id );

		if ( ! $message || 'contact' !== $message->object_type ) {
			return new WP_Error( 'dotflow_message_not_found', __( 'Conversation message not found.', 'groundhogg' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$contact = get_contactdata( absint( $message->object_id ) );

			if ( ! $contact || ! $contact->exists() || ! current_user_can( 'view_contact', $contact ) ) {
				return new WP_Error( 'dotflow_message_forbidden', __( 'You do not have permission to update this conversation.', 'groundhogg' ), [ 'status' => 403 ] );
			}
		}

		$updated = $db->update( $message_id, [ 'is_read' => $is_read ? 1 : 0 ] );

		if ( false === $updated ) {
			return new WP_Error( 'dotflow_message_update_failed', __( 'The message read state could not be updated.', 'groundhogg' ) );
		}

		return [
			'message_id' => $message_id,
			'is_read'    => $is_read,
			'updated'    => true,
		];
	}
}
