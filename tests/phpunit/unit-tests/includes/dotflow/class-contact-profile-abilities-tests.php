<?php

use Groundhogg\DotFlow\Abilities\Get_Contact_Profile;
use Groundhogg\DotFlow\Events\Audit;
use Groundhogg\Pii;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

class DotFlow_Contact_Profile_Abilities_Tests extends GH_UnitTestCase {

	protected $event_contact_ids = [];

	public function tearDown(): void {

		global $wpdb;
		$db = get_db( 'dotflow_events' );

		if ( $db ) {
			foreach ( $this->event_contact_ids as $contact_id ) {
				$wpdb->delete( $db->get_table_name(), [ 'contact_id' => $contact_id ], [ '%d' ] );
			}
		}

		parent::tearDown();
	}

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
		$this->event_contact_ids[] = $contact_id;

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

	public function test_profile_can_include_notes_for_an_authorised_notes_user() {

		$this->admin_user();

		$contact_id = self::factory()->contacts->create();
		$this->event_contact_ids[] = $contact_id;
		$note_ability_reflection = new ReflectionClass( \Groundhogg\Abilities\Contacts\Add_Contact_Note::class );
		$note_ability = $note_ability_reflection->newInstanceWithoutConstructor();
		$created = $note_ability->__invoke( [
			'contact_id' => $contact_id,
			'content'    => 'A private CRM note for the profile test.',
		] );

		$this->assertNotWPError( $created );

		$profile = $this->ability()->__invoke( [
			'contact_id'    => $contact_id,
			'include_notes' => true,
			'notes_limit'   => 1,
		] );

		$this->assertNotWPError( $profile );
		$this->assertCount( 1, $profile['notes'] );
		$this->assertSame( 'A private CRM note for the profile test.', $profile['notes'][0]['content'] );
	}

	public function test_profile_requires_contact_permission_and_separate_note_permission() {

		$owner_id = self::factory()->user->create( [ 'role' => 'sales_rep' ] );
		$other_id = self::factory()->user->create( [ 'role' => 'sales_rep' ] );
		$contact_id = self::factory()->contacts->create( [ 'owner_id' => $owner_id ] );
		$this->event_contact_ids[] = $contact_id;

		wp_set_current_user( $other_id );
		$forbidden = $this->ability()->__invoke( [ 'contact_id' => $contact_id ] );

		$this->assertWPError( $forbidden );
		$this->assertSame( 'dotflow_contact_profile_forbidden', $forbidden->get_error_code() );

		$user = get_userdata( $owner_id );
		$user->add_cap( 'view_notes', false );
		wp_set_current_user( $owner_id );

		$this->assertFalse( current_user_can( 'view_notes' ) );
		$this->assertTrue( current_user_can( 'view_contact', get_contactdata( $contact_id ) ) );

		$notes_forbidden = $this->ability()->__invoke( [
			'contact_id'    => $contact_id,
			'include_notes' => true,
		] );

		$this->assertWPError( $notes_forbidden );
		$this->assertSame( 'dotflow_contact_notes_forbidden', $notes_forbidden->get_error_code() );
	}

	public function test_profile_redacts_contact_pii_for_restricted_users() {

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_userdata( $user_id );
		$user->add_cap( Pii::CAP, false );
		wp_set_current_user( $user_id );

		$contact_id = self::factory()->contacts->create( [
			'email'      => 'private-profile@example.test',
			'first_name' => 'Private',
			'last_name'  => 'Patient',
		] );
		$this->event_contact_ids[] = $contact_id;

		$result = $this->ability()->__invoke( [
			'contact_id' => $contact_id,
			'expand'     => [ 'meta' ],
		] );

		$this->assertNotWPError( $result );
		$this->assertTrue( Pii::is_restricted() );
		$this->assertSame( Pii::REDACTED, $result['contact']['email'] );
		$this->assertSame( Pii::REDACTED, $result['contact']['first_name'] );
		$this->assertSame( Pii::REDACTED, $result['contact']['last_name'] );
		$this->assertStringNotContainsString( 'private-profile@example.test', wp_json_encode( $result ) );
	}

	public function test_pii_restricted_users_cannot_read_free_form_contact_notes() {

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$contact_id = self::factory()->contacts->create();
		$note_reflection = new ReflectionClass( \\Groundhogg\\Abilities\\Contacts\\Add_Contact_Note::class );
		$note_ability = $note_reflection->newInstanceWithoutConstructor();
		$created = $note_ability->__invoke( [
			'contact_id' => $contact_id,
			'content'    => 'Confidential patient details in a free-form note.',
		] );
		$this->assertNotWPError( $created );

		$user = get_userdata( $user_id );
		$user->add_cap( Pii::CAP, false );
		wp_set_current_user( $user_id );
		$this->assertTrue( Pii::is_restricted() );

		$profile = $this->ability()->__invoke( [
			'contact_id'    => $contact_id,
			'include_notes' => true,
		] );

		$this->assertWPError( $profile );
		$this->assertSame( 'groundhogg_pii_restricted', $profile->get_error_code() );

		$list_reflection = new ReflectionClass( \\Groundhogg\\Abilities\\Contacts\\List_Contact_Notes::class );
		$list_ability = $list_reflection->newInstanceWithoutConstructor();
		$notes = $list_ability->__invoke( [ 'contact_id' => $contact_id ] );

		$this->assertWPError( $notes );
		$this->assertSame( 'groundhogg_pii_restricted', $notes->get_error_code() );
	}

	public function test_profile_rejects_a_missing_contact() {

		$this->admin_user();

		$result = $this->ability()->__invoke( [ 'contact_id' => 999999999 ] );

		$this->assertWPError( $result );
		$this->assertSame( 'dotflow_contact_not_found', $result->get_error_code() );
	}
}
