<?php

use Groundhogg\\DotFlow\\Abilities\\List_Events;
use Groundhogg\\DotFlow\\Events\\Event;
use Groundhogg\\DotFlow\\Events\\Store;
use Groundhogg\\Pii;
use function Groundhogg\\get_db;

class DotFlow_List_Events_Tests extends GH_UnitTestCase {

	protected $event_ids = [];

	protected function ability() {
		$reflection = new ReflectionClass( List_Events::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	protected function admin_user( $restricted = false ) {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( $restricted ) {
			get_userdata( $user_id )->add_cap( Pii::CAP, false );
		}
		wp_set_current_user( $user_id );
		return $user_id;
	}

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

	public function test_invalid_event_dates_and_reversed_ranges_are_rejected() {
		$this->admin_user();
		Store::maybe_install();

		foreach ( [
			[ 'after' => 'not-a-date' ],
			[ 'before' => 'not-a-date' ],
			[ 'after' => '2026-11-15T12:00:00Z', 'before' => '2026-11-14T12:00:00Z' ],
		] as $input ) {
			$result = $this->ability()->__invoke( $input );
			$this->assertWPError( $result );
			$this->assertContains( $result->get_error_code(), [ 'dotflow_invalid_event_date', 'dotflow_invalid_event_date_range' ] );
		}
	}

	public function test_valid_date_filters_return_events_and_restricted_users_cannot_read_payloads() {
		Store::maybe_install();
		$event_id = 'list-events-' . wp_generate_uuid4();
		$this->event_ids[] = $event_id;
		Store::record( new Event( 'test.event', [ 'contact_id' => 0, 'safe' => 'metadata' ], 'unit-test', $event_id, gmdate( 'c' ) ) );

		$this->admin_user();
		$result = $this->ability()->__invoke( [
			'after' => gmdate( 'c', time() - HOUR_IN_SECONDS ),
			'before' => gmdate( 'c', time() + HOUR_IN_SECONDS ),
			'limit' => 10,
		] );
		$this->assertNotWPError( $result );
		$this->assertNotEmpty( $result['events'] );

		$this->admin_user( true );
		$restricted = $this->ability()->__invoke( [] );
		$this->assertWPError( $restricted );
		$this->assertSame( 'groundhogg_pii_restricted', $restricted->get_error_code() );
	}
}
