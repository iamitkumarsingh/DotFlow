<?php

use Groundhogg\DotFlow\Abilities\List_Event_Types;
use Groundhogg\DotFlow\Events\Event;
use Groundhogg\DotFlow\Events\Store;
use function Groundhogg\get_db;

class DotFlow_List_Event_Types_Tests extends GH_UnitTestCase {

	protected $event_ids = [];

	public function tearDown(): void {

		global $wpdb;
		$db = get_db( 'dotflow_events' );

		if ( $db ) {
			foreach ( $this->event_ids as $event_id ) {
				$wpdb->delete( $db->get_table_name(), [ 'event_id' => $event_id ], [ '%s' ] );
			}
		}

		parent::tearDown();
	}

	protected function record_event( $name, $source, $id ) {

		$this->event_ids[] = $id;

		return Store::record( new Event( $name, [
			'contact_id' => 0,
			'object_type' => '',
			'object_id' => 0,
			'private_body' => 'This must never be returned by event-type discovery.',
		], $source, $id ) );
	}

	public function test_event_type_discovery_returns_aggregates_without_payload_or_audit_events() {

		Store::maybe_install();

		$this->record_event( 'email.inbound.received', 'email', 'event-type-test-001' );
		$this->record_event( 'email.inbound.received', 'email', 'event-type-test-002' );
		$this->record_event( 'contact.form_submitted', 'dotform', 'event-type-test-003' );
		$this->record_event( 'ai.mutation.completed', 'mcp', 'event-type-test-004' );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$reflection = new ReflectionClass( List_Event_Types::class );
		$ability    = $reflection->newInstanceWithoutConstructor();
		$result     = $ability( [] );

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['total'] );

		$by_name = [];
		foreach ( $result['event_types'] as $type ) {
			$by_name[ $type['event_name'] ] = $type;
		}

		$this->assertArrayHasKey( 'email.inbound.received', $by_name );
		$this->assertArrayHasKey( 'contact.form_submitted', $by_name );
		$this->assertSame( 2, $by_name['email.inbound.received']['occurrences'] );
		$this->assertSame( 'email', $by_name['email.inbound.received']['source'] );
		$this->assertNotEmpty( $by_name['email.inbound.received']['last_seen'] );
		$this->assertArrayNotHasKey( 'ai.mutation.completed', $by_name );
		$this->assertArrayNotHasKey( 'payload', $by_name['email.inbound.received'] );
		$this->assertArrayNotHasKey( 'contact_id', $by_name['email.inbound.received'] );
		$this->assertStringNotContainsString( 'This must never be returned', wp_json_encode( $result ) );
	}

	public function test_event_type_discovery_supports_search_and_bounded_limits() {

		Store::maybe_install();
		$this->record_event( 'email.inbound.received', 'email', 'event-type-search-001' );
		$this->record_event( 'contact.form_submitted', 'dotform', 'event-type-search-002' );

		$reflection = new ReflectionClass( List_Event_Types::class );
		$ability    = $reflection->newInstanceWithoutConstructor();

		$result = $ability( [ 'search' => 'inbound', 'limit' => 500 ] );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'email.inbound.received', $result['event_types'][0]['event_name'] );
	}
}
