<?php

use Groundhogg\DotFlow\Admin\Automation_Dashboard;

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
}
