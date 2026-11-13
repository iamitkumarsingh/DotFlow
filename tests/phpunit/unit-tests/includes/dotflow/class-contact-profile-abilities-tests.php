<?php

use Groundhogg\DotFlow\Abilities\Get_Contact_Profile;
use Groundhogg\DotFlow\Events\Audit;
use function Groundhogg\get_contactdata;

class DotFlow_Contact_Profile_Abilities_Tests extends GH_UnitTestCase {

	protected function ability() {
		$reflection = new ReflectionClass( Get_Contact_Profile::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	protected function admin_user(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	public function test_profile_returns_contact_and_only_requested_activity() {

		$this->admin_user();

		$contact_id = self::factory()->contacts->create( [
			'email'      => 'profile@example.test',
			'first_name' => 'Profile',
		] );

		Audit::record( 'contact.updated', 'contact', $contact_id, [
			'fields' => [ 'first_name' ],
		] );

		$basic = $this->ability()->__invoke( [ 'contact_id' => $contact_id ] );

		$this->assertNotWPError( $basic );
		$this->assertSame( $contact_id, $basic['contact']['id'] );
		$this->assertArrayNotHasKey( 'notes', $basic );
		$this->assertArrayNotHasKey( 'activity', $basic );

		$expanded = $this->ability()->__invoke( [
			'contact_id'       => $contact_id,
			'include_activity' => true,
			'activity_limit'  => 5,
		] );

		$this->assertNotWPError( $expanded );
		$this->assertCount( 1, $expanded['activity'] );
		$this->assertSame( 'ai.mutation.completed', $expanded['activity'][0]['event_name'] );
		$this->assertArrayNotHasKey( 'payload', $expanded['activity'][0] );
	}

	public function test_profile_requires_contact_permission_and_separate_note_permission() {

		$owner_id = self::factory()->user->create( [ 'role' => 'sales_rep' ] );
		$other_id = self::factory()->user->create( [ 'role' => 'sales_rep' ] );
		$contact_id = self::factory()->contacts->create( [ 'owner_id' => $owner_id ] );

		wp_set_current_user( $other_id );
		$forbidden = $this->ability()->__invoke( [ 'contact_id' => $contact_id ] );

		$this->assertWPError( $forbidden );
		$this->assertSame( 'dotflow_contact_profile_forbidden', $forbidden->get_error_code() );

		$user = get_userdata( $owner_id );
		$user->remove_cap( 'view_notes' );
		wp_set_current_user( $owner_id );

		$this->assertTrue( current_user_can( 'view_contact', get_contactdata( $contact_id ) ) );

		$notes_forbidden = $this->ability()->__invoke( [
			'contact_id'    => $contact_id,
			'include_notes' => true,
		] );

		$this->assertWPError( $notes_forbidden );
		$this->assertSame( 'dotflow_contact_notes_forbidden', $notes_forbidden->get_error_code() );
	}

	public function test_profile_rejects_a_missing_contact() {

		$this->admin_user();

		$result = $this->ability()->__invoke( [ 'contact_id' => 999999999 ] );

		$this->assertWPError( $result );
		$this->assertSame( 'dotflow_contact_not_found', $result->get_error_code() );
	}
}
