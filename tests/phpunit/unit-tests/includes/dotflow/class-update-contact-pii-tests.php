<?php

use Groundhogg\Abilities\Contacts\Update_Contact;
use Groundhogg\Pii;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

class DotFlow_Update_Contact_Pii_Tests extends GH_UnitTestCase {

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

	public function test_restricted_admin_cannot_change_identity_or_pii_meta_but_can_change_safe_meta() {

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_userdata( $user_id );
		$user->add_cap( Pii::CAP, false );
		wp_set_current_user( $user_id );

		$this->assertTrue( Pii::is_restricted() );

		$contact_id = self::factory()->contacts->create( [
			'email'      => 'pii-guard@example.test',
			'first_name' => 'Original',
		] );
		$this->event_contact_ids[] = $contact_id;

		$reflection = new ReflectionClass( Update_Contact::class );
		$ability    = $reflection->newInstanceWithoutConstructor();

		$identity_result = $ability->__invoke( [
			'id'    => $contact_id,
			'email' => 'changed@example.test',
		] );

		$this->assertWPError( $identity_result );
		$this->assertSame( 'groundhogg_pii_restricted', $identity_result->get_error_code() );
		$this->assertSame( 'pii-guard@example.test', get_contactdata( $contact_id )->get_email() );

		$meta_result = $ability->__invoke( [
			'id'   => $contact_id,
			'meta' => [ 'mobile_phone' => '07123456789' ],
		] );

		$this->assertWPError( $meta_result );
		$this->assertSame( 'groundhogg_pii_restricted', $meta_result->get_error_code() );

		$safe_result = $ability->__invoke( [
			'id'   => $contact_id,
			'meta' => [ 'lead_source' => 'Website' ],
		] );

		$this->assertNotWPError( $safe_result );
		$this->assertSame( 'Website', get_contactdata( $contact_id )->get_meta( 'lead_source' ) );
	}
}
