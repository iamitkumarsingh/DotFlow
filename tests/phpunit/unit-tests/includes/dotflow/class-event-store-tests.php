<?php

use Groundhogg\DotFlow\Abilities\List_Events;
use Groundhogg\DotFlow\Events\Audit;
use Groundhogg\DotFlow\Events\Event;
use Groundhogg\DotFlow\Events\Store;
use Groundhogg\Pii;
use function Groundhogg\get_db;

class DotFlow_Event_Store_Tests extends GH_UnitTestCase {

	public function test_store_is_idempotent_and_redacts_secret_fields() {

		global $wpdb;

		Store::maybe_install();

		$db = get_db( 'dotflow_events' );

		$event = new Event(
			'contact.created',
			[
				'contact_id' => 123,
				'email'      => 'person@example.test',
				'api_key'     => 'do-not-store-this',
				'license_key' => 'license-secret-value',
				'provider'   => [
					'access_token' => 'nested-secret',
					'campaign_id'  => 88,
				],
			],
			'unit-test',
			'dotflow-store-test-001'
		);

		$first_id  = Store::record( $event );
		$second_id = Store::record( $event );

		try {
			$this->assertGreaterThan( 0, $first_id );
			$this->assertSame( $first_id, $second_id );

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT payload FROM {$db->get_table_name()} WHERE ID = %d",
					$first_id
				)
			);

			$payload = json_decode( $row->payload, true );

			$this->assertSame( '[REDACTED]', $payload['api_key'] );
			$this->assertSame( '[REDACTED]', $payload['license_key'] );
			$this->assertSame( '[REDACTED]', $payload['provider']['access_token'] );
			$this->assertSame( 88, $payload['provider']['campaign_id'] );
		} finally {
			if ( $first_id ) {
				$db->delete( $first_id );
			}
		}
	}

	public function test_ai_mutation_audit_records_changed_fields_without_message_content() {

		Store::maybe_install();

		$db = get_db( 'dotflow_events' );

		$event = Audit::record(
			'message.read_state.updated',
			'message',
			987,
			[
				'is_read' => [
					'before' => false,
					'after'  => true,
				],
			]
		);

		$record_id = Store::record( $event );

		try {
			$this->assertGreaterThan( 0, $record_id );

			$reflection = new ReflectionClass( List_Events::class );
			$ability    = $reflection->newInstanceWithoutConstructor();
			$result     = $ability( [
				'event_name' => 'ai.mutation.completed',
				'source'     => 'mcp',
				'limit'      => 10,
			] );

			$this->assertGreaterThanOrEqual( 1, $result['total_items'] );
			$matching = array_filter( $result['events'], function ( $row ) use ( $record_id ) {
				return $record_id === $row['id'];
			} );
			$this->assertNotEmpty( $matching );
			$logged = reset( $matching );
			$this->assertSame( 'message.read_state.updated', $logged['payload']['action'] );
			$this->assertSame( get_current_user_id(), $logged['payload']['actor_user_id'] );
			$this->assertSame( false, $logged['payload']['changes']['is_read']['before'] );
			$this->assertSame( true, $logged['payload']['changes']['is_read']['after'] );
			$this->assertArrayNotHasKey( 'content', $logged['payload'] );
		} finally {
			if ( $record_id ) {
				$db->delete( $record_id );
			}
		}
	}

	public function test_contact_audit_payload_contains_field_names_not_personal_values() {

		global $wpdb;

		Store::maybe_install();

		$db = get_db( 'dotflow_events' );
		$event = Audit::record(
			'contact.updated',
			'contact',
			456,
			[
				'fields'            => [ 'email', 'first_name', 'owner_id' ],
				'custom_field_keys' => [ 'telephone' ],
			]
		);
		$record_id = Store::record( $event );

		try {
			$this->assertGreaterThan( 0, $record_id );

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT payload FROM {$db->get_table_name()} WHERE ID = %d",
					$record_id
				)
			);
			$payload = json_decode( $row->payload, true );

			$this->assertSame( [ 'email', 'first_name', 'owner_id' ], $payload['changes']['fields'] );
			$this->assertSame( [ 'telephone' ], $payload['changes']['custom_field_keys'] );
			$this->assertSame( 456, $payload['contact_id'] );
			$this->assertStringNotContainsString( 'person@example.test', $row->payload );
		} finally {
			if ( $record_id ) {
				$db->delete( $record_id );
			}
		}
	}

	public function test_restricted_admin_cannot_read_raw_event_payloads() {

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_userdata( $user_id );
		$user->add_cap( Pii::CAP, false );
		wp_set_current_user( $user_id );

		$reflection = new ReflectionClass( List_Events::class );
		$ability    = $reflection->newInstanceWithoutConstructor();
		$result     = $ability( [ 'limit' => 5 ] );

		$this->assertWPError( $result );
		$this->assertSame( 'groundhogg_pii_restricted', $result->get_error_code() );
	}


}
