<?php

use Groundhogg\DotFlow\Abilities\List_Contact_Events;
use Groundhogg\DotFlow\Events\Audit;
use Groundhogg\DotFlow\Events\Store;
use function Groundhogg\get_db;

class DotFlow_Contact_Event_Abilities_Tests extends GH_UnitTestCase {

	protected function ability() {
		$reflection = new ReflectionClass( List_Contact_Events::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	protected function admin_user(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	public function test_contact_activity_returns_metadata_without_payload_and_supports_filters() {

		$this->admin_user();
		Store::maybe_install();

		$contact_id = self::factory()->contacts->create( [
			'email' => 'dotflow.timeline@example.test',
		] );

		Audit::record( 'contact.updated', 'contact', $contact_id, [
			'fields' => [ 'first_name' ],
		] );

		Audit::record( 'contact_note.created', 'contact', $contact_id, [
			'note_id'     => 4321,
			'has_summary' => true,
		] );

		$result = $this->ability()->__invoke( [
			'contact_id' => $contact_id,
			'event_name' => 'ai.mutation.completed',
			'limit'      => 10,
			'offset'     => 0,
		] );

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['total_items'] );
		$this->assertCount( 2, $result['events'] );

		foreach ( $result['events'] as $event ) {
			$this->assertSame( 'ai.mutation.completed', $event['event_name'] );
			$this->assertSame( $contact_id, $event['object_id'] );
			$this->assertArrayNotHasKey( 'payload', $event );
			$this->assertArrayNotHasKey( 'email', $event );
		}

		$first_page = $this->ability()->__invoke( [
			'contact_id' => $contact_id,
			'limit'      => 1,
			'offset'     => 0,
		] );

		$this->assertNotWPError( $first_page );
		$this->assertSame( 2, $first_page['total_items'] );
		$this->assertCount( 1, $first_page['events'] );
	}

	public function test_contact_activity_is_scoped_to_the_contacts_owner() {

		$owner_id = self::factory()->user->create( [ 'role' => 'sales_rep' ] );
		$other_id = self::factory()->user->create( [ 'role' => 'sales_rep' ] );
		$contact_id = self::factory()->contacts->create( [ 'owner_id' => $owner_id ] );

		wp_set_current_user( $owner_id );
		Audit::record( 'contact.updated', 'contact', $contact_id, [ 'fields' => [ 'first_name' ] ] );

		wp_set_current_user( $other_id );
		$result = $this->ability()->__invoke( [ 'contact_id' => $contact_id ] );

		$this->assertWPError( $result );
		$this->assertSame( 'dotflow_contact_events_forbidden', $result->get_error_code() );
	}

	public function test_contact_activity_rejects_missing_contacts_and_invalid_dates() {

		$this->admin_user();

		$missing = $this->ability()->__invoke( [ 'contact_id' => 999999999 ] );
		$this->assertWPError( $missing );
		$this->assertSame( 'dotflow_contact_not_found', $missing->get_error_code() );

		$contact_id = self::factory()->contacts->create();
		$invalid = $this->ability()->__invoke( [
			'contact_id' => $contact_id,
			'after'      => 'not-a-date',
		] );

		$this->assertWPError( $invalid );
		$this->assertSame( 'dotflow_invalid_event_date', $invalid->get_error_code() );
	}

	public function test_contact_audit_event_is_indexed_for_its_contact() {

		global $wpdb;

		$this->admin_user();
		Store::maybe_install();

		$contact_id = self::factory()->contacts->create();
		Audit::record( 'contact.updated', 'contact', $contact_id, [ 'fields' => [ 'last_name' ] ] );

		$db = get_db( 'dotflow_events' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT contact_id, actor_user_id FROM {$db->get_table_name()} WHERE contact_id = %d ORDER BY ID DESC LIMIT 1",
				$contact_id
			),
			ARRAY_A
		);

		$this->assertNotEmpty( $row );
		$this->assertSame( $contact_id, absint( $row['contact_id'] ) );
		$this->assertSame( get_current_user_id(), absint( $row['actor_user_id'] ) );
	}
}
