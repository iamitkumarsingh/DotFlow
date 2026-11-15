<?php

use Groundhogg\DotFlow\Steps\Actions\Stop_Contact_Flow;
use Groundhogg\Event;
use Groundhogg\Funnel;
use Groundhogg\Step;
use Groundhogg\Steps\Actions\Delay_Timer;
use function Groundhogg\event_queue_db;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

class DotFlow_Stop_Contact_Flow_Step_Tests extends GH_UnitTestCase {

	public function test_reply_flow_action_stops_a_contact_in_another_flow() {
		$contact_id = self::factory()->contacts->create();
		$contact = get_contactdata( $contact_id );

		$target = new Funnel( [ 'title' => 'Original nurture', 'status' => 'active' ] );
		$target_step = $target->add_step( [
			'step_type' => Delay_Timer::TYPE,
			'step_group' => Step::ACTION,
			'step_title' => 'Wait',
			'step_status' => 'active',
			'meta' => [ 'delay_amount' => 3, 'delay_type' => 'days', 'run_when' => 'now' ],
		] );
		$target_step->enqueue( $contact );

		$reply_flow = new Funnel( [ 'title' => 'Reply handler', 'status' => 'active' ] );
		$stop_step = $reply_flow->add_step( [
			'step_type' => 'dotflow_stop_flow',
			'step_group' => Step::ACTION,
			'step_title' => 'Stop nurture',
			'step_status' => 'active',
			'meta' => [ 'flow_id' => $target->get_id() ],
		] );
		$stop_step->update_meta( 'flow_id', $target->get_id() );
		$element = $stop_step->get_step_element();
		$event = new Event( [
			'ID' => 9002,
			'funnel_id' => $reply_flow->get_id(),
			'step_id' => $stop_step->get_id(),
		] );

		$this->assertTrue( $element->run( $contact, $event ) );
		$this->assertContains( 'dotflow_stop_flow', \Groundhogg\Abilities\Schemas\Step_Type_Schema::supported_types() );
		$this->assertCount( 0, event_queue_db()->query( [
			'contact_id' => $contact_id,
			'funnel_id' => $target->get_id(),
			'event_type' => Event::FUNNEL,
		] ) );

		$history = get_db( 'events' )->query( [
			'contact_id' => $contact_id,
			'funnel_id' => $target->get_id(),
			'status' => Event::CANCELLED,
		] );
		$this->assertCount( 1, $history );
		$this->assertSame( 'dotflow_reply_or_rule_stopped', $history[0]->error_code );

		$target->delete();
		$reply_flow->delete();
	}
}
