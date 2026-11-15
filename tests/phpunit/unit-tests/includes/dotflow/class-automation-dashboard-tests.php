<?php

use Groundhogg\DotFlow\Admin\Automation_Dashboard;
use Groundhogg\Funnel;
use Groundhogg\Steps\Actions\Delay_Timer;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;

class DotFlow_Automation_Dashboard_Tests extends GH_UnitTestCase {

	public function test_reporting_rejects_invalid_or_excessive_date_ranges() {
		$invalid_start = Automation_Dashboard::get_metrics( 0, time() );
		$this->assertWPError( $invalid_start );
		$this->assertSame( 'dotflow_automation_report_range_invalid', $invalid_start->get_error_code() );

		$reversed = Automation_Dashboard::get_metrics( time(), time() - DAY_IN_SECONDS );
		$this->assertWPError( $reversed );
		$this->assertSame( 'dotflow_automation_report_range_invalid', $reversed->get_error_code() );

		$excessive = Automation_Dashboard::get_metrics( time() - ( 3 * YEAR_IN_SECONDS ), time() );
		$this->assertWPError( $excessive );
		$this->assertSame( 'dotflow_automation_report_range_invalid', $excessive->get_error_code() );
	}
	public function test_dashboard_aggregates_queue_and_step_outcomes_without_contact_data() {
		global $wpdb;

		$contact_id = self::factory()->contacts->create( [ 'email' => 'automation-dashboard@example.test' ] );
		$contact = get_contactdata( $contact_id );
		$funnel = new Funnel( [ 'title' => 'DotFlow dashboard test', 'status' => 'active' ] );
		$step = $funnel->add_step( [
			'step_type'   => Delay_Timer::TYPE,
			'step_group'  => Delay_Timer::GROUP,
			'step_title'  => 'Dashboard wait',
			'step_status' => 'active',
			'meta'        => [ 'delay_amount' => 1, 'delay_type' => 'days', 'run_when' => 'now' ],
		] );

		try {
			$this->assertNotFalse( $step->enqueue( $contact ) );
			$metrics = Automation_Dashboard::get_metrics( time() - HOUR_IN_SECONDS, time() + HOUR_IN_SECONDS );
			$this->assertNotWPError( $metrics );
			$this->assertGreaterThanOrEqual( 1, $metrics['summary']['waiting'] );
			$this->assertGreaterThanOrEqual( 1, $metrics['summary']['contacts'] );

			$flow_ids = array_column( $metrics['flows'], 'flow_id' );
			$this->assertContains( $funnel->get_id(), $flow_ids );

			$step_ids = array_column( $metrics['steps'], 'step_id' );
			$this->assertContains( $step->get_id(), $step_ids );
			$this->assertArrayNotHasKey( 'contact_id', $metrics['steps'][0] );
		} finally {
			foreach ( [ 'event_queue', 'events' ] as $db_name ) {
				$db = get_db( $db_name );
				if ( $db ) {
					$wpdb->delete( $db->get_table_name(), [ 'contact_id' => $contact_id ], [ '%d' ] );
				}
			}
			if ( $funnel->exists() ) {
				$funnel->delete();
			}
		}
	}

}
