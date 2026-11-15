<?php

use Groundhogg\DotFlow\Abilities\List_Contact_Automation_History;
use Groundhogg\DotFlow\Abilities\Manage_Contact_Automation;
use Groundhogg\Event;
use Groundhogg\Funnel;
use Groundhogg\Steps\Actions\Delay_Timer;
use function Groundhogg\event_queue_db;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

class DotFlow_Contact_Automation_Abilities_Tests extends GH_UnitTestCase {

	protected $contact_ids = [];
	protected $flow_ids = [];

	protected function admin_user() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	protected function ability( $class ) {
		return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
	}

	protected function make_journey() {
		$contact_id = self::factory()->contacts->create( [ 'email' => 'automation@example.test' ] );
		$contact = get_contactdata( $contact_id );
		$funnel = new Funnel( [ 'title' => 'DotFlow test journey', 'status' => 'active' ] );
		$step = $funnel->add_step( [
			'step_type'   => Delay_Timer::TYPE,
			'step_group'  => Delay_Timer::GROUP,
			'step_title'  => 'Wait',
			'step_status' => 'active',
			'meta'        => [ 'delay_amount' => 3, 'delay_type' => 'days', 'run_when' => 'now' ],
		] );
		$this->contact_ids[] = $contact_id;
		$this->flow_ids[] = $funnel->get_id();
		return [ $contact, $funnel, $step ];
	}

	public function tearDown(): void {
		global $wpdb;
		foreach ( $this->contact_ids as $contact_id ) {
			foreach ( [ 'event_queue', 'events' ] as $db_name ) {
				$db = get_db( $db_name );
				if ( $db ) {
					$wpdb->delete( $db->get_table_name(), [ 'contact_id' => $contact_id ], [ '%d' ] );
				}
			}
		}
		foreach ( $this->flow_ids as $flow_id ) {
			$funnel = new Funnel( $flow_id );
			if ( $funnel->exists() ) {
				$funnel->delete();
			}
		}
		parent::tearDown();
	}

	public function test_contact_journey_can_pause_resume_cancel_and_appear_in_history() {
		$this->admin_user();
		[ $contact, $funnel, $step ] = $this->make_journey();
		$this->assertNotFalse( $step->enqueue( $contact ) );

		$manage = $this->ability( Manage_Contact_Automation::class );
		$base = [ 'contact_id' => $contact->get_id(), 'flow_id' => $funnel->get_id() ];

		$paused = $manage->__invoke( $base + [ 'action' => 'pause' ] );
		$this->assertNotWPError( $paused );
		$this->assertSame( Event::PAUSED, $paused['queue_status'] );
		$this->assertSame( 1, $paused['changed_events'] );

		$resumed = $manage->__invoke( $base + [ 'action' => 'resume' ] );
		$this->assertNotWPError( $resumed );
		$this->assertSame( Event::WAITING, $resumed['queue_status'] );

		$cancelled = $manage->__invoke( $base + [ 'action' => 'cancel' ] );
		$this->assertNotWPError( $cancelled );
		$this->assertSame( Event::CANCELLED, $cancelled['queue_status'] );
		$this->assertCount( 0, event_queue_db()->query( [
			'contact_id' => $contact->get_id(),
			'funnel_id'  => $funnel->get_id(),
			'event_type' => Event::FUNNEL,
		] ) );

		$history = $this->ability( List_Contact_Automation_History::class )->__invoke( [
			'contact_id' => $contact->get_id(),
			'flow_id'    => $funnel->get_id(),
		] );
		$this->assertNotWPError( $history );
		$this->assertSame( 1, $history['total_items'] );
		$this->assertSame( Event::CANCELLED, $history['events'][0]['status'] );
		$this->assertArrayNotHasKey( 'args', $history['events'][0] );
		$this->assertArrayNotHasKey( 'error_message', $history['events'][0] );
	}

	public function test_restart_replaces_existing_waiting_events_atomically() {
		$this->admin_user();
		[ $contact, $funnel, $step ] = $this->make_journey();
		$old_id = $step->enqueue( $contact );
		$this->assertNotFalse( $old_id );

		$result = $this->ability( Manage_Contact_Automation::class )->__invoke( [
			'contact_id' => $contact->get_id(),
			'flow_id'    => $funnel->get_id(),
			'action'     => 'restart',
		] );

		$this->assertNotWPError( $result );
		$this->assertGreaterThan( 0, $result['new_event_id'] );
		$this->assertNotSame( $old_id, $result['new_event_id'] );
		$queued = event_queue_db()->query( [
			'contact_id' => $contact->get_id(),
			'funnel_id'  => $funnel->get_id(),
			'event_type' => Event::FUNNEL,
		] );
		$this->assertCount( 1, $queued );
		$this->assertSame( $result['new_event_id'], absint( $queued[0]->ID ) );

		$history = get_db( 'events' )->query( [
			'contact_id' => $contact->get_id(),
			'funnel_id'  => $funnel->get_id(),
			'status'     => Event::CANCELLED,
		] );
		$this->assertCount( 1, $history );
		$this->assertSame( 'dotflow_contact_restarted', $history[0]->error_code );
	}

	public function test_contact_automation_management_rejects_another_users_contact() {
		[ $contact, $funnel ] = $this->make_journey();
		$owner = self::factory()->user->create( [ 'role' => 'sales_rep' ] );
		$other = self::factory()->user->create( [ 'role' => 'sales_rep' ] );
		$contact->update( [ 'owner_id' => $owner ] );
		wp_set_current_user( $other );

		$result = $this->ability( Manage_Contact_Automation::class )->__invoke( [
			'contact_id' => $contact->get_id(),
			'flow_id'    => $funnel->get_id(),
			'action'     => 'pause',
		] );

		$this->assertWPError( $result );
		$this->assertSame( 'dotflow_automation_contact_forbidden', $result->get_error_code() );
	}

	public function test_history_does_not_expose_event_args_or_error_message() {
		$this->admin_user();
		[ $contact, $funnel, $step ] = $this->make_journey();
		event_queue_db()->add( [
			'contact_id' => $contact->get_id(),
			'funnel_id'  => $funnel->get_id(),
			'step_id'    => $step->get_id(),
			'event_type' => Event::FUNNEL,
			'status'     => Event::WAITING,
			'args'       => [ 'private' => 'should-not-be-returned' ],
		] );

		$result = $this->ability( List_Contact_Automation_History::class )->__invoke( [
			'contact_id' => $contact->get_id(),
			'flow_id'    => $funnel->get_id(),
		] );
		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['events'] );
		$this->assertArrayNotHasKey( 'args', $result['events'][0] );
		$this->assertArrayNotHasKey( 'error_message', $result['events'][0] );
	}
}
