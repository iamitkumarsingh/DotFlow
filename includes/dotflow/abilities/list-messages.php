<?php

namespace Groundhogg\DotFlow\Abilities;

use Groundhogg\Abilities\Ability;
use WP_Error;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

/**
 * Lists message records with contact-level privacy checks.
 */
class List_Messages extends Ability {

	protected const NAME       = 'dotflow/list-messages';
	protected const CATEGORY   = 'dotflow-platform';
	protected const CAPABILITY = 'read';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List DotFlow Messages', 'groundhogg' ),
			'description' => __( 'List inbound and outbound conversation messages. A contact ID is required unless the caller has manage_options. Message bodies are omitted unless include_content is true.', 'groundhogg' ),
			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'contact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					'thread_id'  => [ 'type' => 'string', 'maxLength' => 191 ],
					'direction'  => [ 'type' => 'string', 'enum' => [ 'inbound', 'outbound' ] ],
					'status'     => [ 'type' => 'string', 'maxLength' => 20 ],
					'is_read'    => [ 'type' => 'boolean' ],
					'after'      => [ 'type' => 'string', 'format' => 'date-time' ],
					'before'     => [ 'type' => 'string', 'format' => 'date-time' ],
					'include_content' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Set true to include full message bodies. Otherwise only the stored summary is returned.', 'groundhogg' ),
					],
					'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
					'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
				],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'total_items' => [ 'type' => 'integer' ],
					'messages'    => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'         => [ 'type' => 'integer' ],
								'contact_id' => [ 'type' => 'integer' ],
								'direction'  => [ 'type' => 'string' ],
								'from'       => [ 'type' => 'string' ],
								'to'         => [ 'type' => 'string' ],
								'subject'    => [ 'type' => 'string' ],
								'summary'    => [ 'type' => 'string' ],
								'content'    => [ 'type' => 'string' ],
								'thread_id'  => [ 'type' => 'string' ],
								'message_id' => [ 'type' => 'string' ],
								'in_reply_to' => [ 'type' => 'string' ],
								'status'     => [ 'type' => 'string' ],
								'is_read'    => [ 'type' => 'boolean' ],
								'date_created' => [ 'type' => 'string' ],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * Format a stored UTC datetime as an ISO 8601 UTC timestamp.
	 *
	 * @param string $value Stored MySQL datetime.
	 * @return string
	 */
	private static function format_utc( $value ) {
		$timestamp = strtotime( $value );

		return false === $timestamp ? '' : gmdate( 'c', $timestamp );
	}

	public function __invoke( $input ) {

		global $wpdb;

		// Conversation headers and bodies can contain personal data even when the
		// caller can view the contact record itself.
		if ( Pii::is_restricted() ) {
			return Pii::restricted_error( __( 'Conversation messages are not available to accounts restricted from personal information.', 'groundhogg' ) );
		}

		$contact_id = ! empty( $input['contact_id'] ) ? absint( $input['contact_id'] ) : 0;

		if ( $contact_id ) {
			$contact = get_contactdata( $contact_id );

			if ( ! $contact || ! $contact->exists() || ! current_user_can( 'view_contact', $contact ) ) {
				return new WP_Error( 'dotflow_message_forbidden', __( 'You do not have permission to view this contact conversation.', 'groundhogg' ), [ 'status' => 403 ] );
			}
		} elseif ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'dotflow_message_contact_required', __( 'Provide a contact ID you are allowed to view, or use an administrator account to list all conversations.', 'groundhogg' ), [ 'status' => 403 ] );
		}

		$db     = get_db( 'messages' );
		$table  = $db->get_table_name();
		$where  = [ 'object_type = %s' ];
		$params = [ 'contact' ];

		if ( $contact_id ) {
			$where[]  = 'object_id = %d';
			$params[] = $contact_id;
		}

		if ( ! empty( $input['thread_id'] ) ) {
			$where[]  = 'thread_id = %s';
			$params[] = sanitize_text_field( $input['thread_id'] );
		}

		if ( ! empty( $input['direction'] ) ) {
			$where[]  = 'direction = %s';
			$params[] = sanitize_key( $input['direction'] );
		}

		if ( ! empty( $input['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $input['status'] );
		}

		if ( isset( $input['is_read'] ) ) {
			$where[]  = 'is_read = %d';
			$params[] = ! empty( $input['is_read'] ) ? 1 : 0;
		}

		foreach ( [ 'after' => '>=', 'before' => '<=' ] as $key => $operator ) {
			if ( empty( $input[ $key ] ) ) {
				continue;
			}

			$timestamp = strtotime( sanitize_text_field( $input[ $key ] ) );

			if ( false !== $timestamp ) {
				$where[]  = "date_created {$operator} %s";
				$params[] = gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		$where_sql = implode( ' AND ', $where );
		$limit     = isset( $input['limit'] ) ? min( 100, max( 1, absint( $input['limit'] ) ) ) : 50;
		$offset    = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$select = 'ID, object_id, direction, from_address, to_address, subject, summary, thread_id, message_id, in_reply_to, status, is_read, date_created';
		if ( ! empty( $input['include_content'] ) ) {
			$select .= ', content';
		}

		$query_sql = "SELECT {$select} FROM {$table} WHERE {$where_sql} ORDER BY date_created DESC, ID DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, [ $limit, $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_params ) );

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'dotflow_message_query_failed', __( 'Conversation messages could not be retrieved.', 'groundhogg' ) );
		}

		$messages = [];

		foreach ( $rows as $row ) {
			$message = [
				'id'           => absint( $row->ID ),
				'contact_id'   => absint( $row->object_id ),
				'direction'    => (string) $row->direction,
				'from'         => (string) $row->from_address,
				'to'           => (string) $row->to_address,
				'subject'      => (string) $row->subject,
				'summary'      => (string) $row->summary,
				'thread_id'    => (string) $row->thread_id,
				'message_id'   => (string) $row->message_id,
				'in_reply_to'  => (string) $row->in_reply_to,
				'status'       => (string) $row->status,
				'is_read'      => (bool) $row->is_read,
				'date_created' => self::format_utc( $row->date_created ),
			];

			if ( isset( $row->content ) ) {
				$message['content'] = (string) $row->content;
			}

			$messages[] = $message;
		}

		return [
			'total_items' => $total,
			'messages'    => $messages,
		];
	}
}
