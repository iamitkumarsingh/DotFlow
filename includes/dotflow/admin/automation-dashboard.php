<?php

namespace Groundhogg\DotFlow\Admin;

use Groundhogg\Event;
use Groundhogg\Funnel;
use WP_Error;
use function Groundhogg\get_db;

/**
 * Read-only operational dashboard for workflow execution.
 *
 * Uses the existing Groundhogg queue and event-history tables; no parallel
 * scheduler, telemetry table or contact-level data export is introduced.
 */
final class Automation_Dashboard {

	/**
	 * Attach the dashboard as a native tab in Groundhogg Reports.
	 *
	 * @param object $admin_menu Groundhogg admin menu manager.
	 * @return void
	 */
	public static function register( $admin_menu ) {
		if ( ! is_object( $admin_menu ) || ! method_exists( $admin_menu, 'get_page' ) ) {
			return;
		}

		$reports = $admin_menu->get_page( 'reporting' );
		if ( $reports && method_exists( $reports, 'add_custom_report_tab' ) ) {
			$reports->add_custom_report_tab( [
				'name'     => esc_html__( 'Automation', 'groundhogg' ),
				'slug'     => 'automation',
				'reports'  => [],
				'callback' => [ __CLASS__, 'render' ],
			] );
		}
	}

	/**
	 * Retrieve aggregate workflow outcomes for a bounded UTC timestamp range.
	 *
	 * @param int $start Inclusive Unix timestamp.
	 * @param int $end   Inclusive Unix timestamp.
	 * @return array|WP_Error
	 */
	public static function get_metrics( $start, $end ) {
		global $wpdb;

		$start = absint( $start );
		$end   = absint( $end );
		if ( ! $start || ! $end || $end < $start || ( $end - $start ) > YEAR_IN_SECONDS * 2 ) {
			return new WP_Error( 'dotflow_automation_report_range_invalid', __( 'Choose a valid reporting period of no more than two years.', 'groundhogg' ) );
		}

		$queue   = get_db( 'event_queue' );
		$history = get_db( 'events' );
		if ( ! $queue || ! $history ) {
			return new WP_Error( 'dotflow_automation_report_unavailable', __( 'Automation reporting is unavailable.', 'groundhogg' ) );
		}

		$queue_table   = $queue->get_table_name();
		$history_table = $history->get_table_name();

		$union = "SELECT ID, contact_id, funnel_id, step_id, status, error_code, time_scheduled AS time
			FROM {$queue_table}
			WHERE event_type = %d AND time_scheduled >= %d AND time_scheduled <= %d
			UNION ALL
			SELECT ID, contact_id, funnel_id, step_id, status, error_code, time_scheduled AS time
			FROM {$history_table}
			WHERE event_type = %d AND time_scheduled >= %d AND time_scheduled <= %d";

		$base_args = [ Event::FUNNEL, $start, $end, Event::FUNNEL, $start, $end ];
		$sql = "SELECT status, COUNT(*) AS total, COUNT(DISTINCT contact_id) AS contacts
			FROM ({$union}) AS automation_events
			GROUP BY status";
		$status_rows = $wpdb->get_results( $wpdb->prepare( $sql, $base_args ) );

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'dotflow_automation_report_query_failed', __( 'Automation metrics could not be retrieved.', 'groundhogg' ) );
		}

		$summary = [
			'waiting'     => 0,
			'paused'      => 0,
			'in_progress' => 0,
			'complete'    => 0,
			'failed'      => 0,
			'cancelled'   => 0,
			'skipped'     => 0,
			'total'       => 0,
			'contacts'    => 0,
		];

		foreach ( $status_rows as $row ) {
			$status = sanitize_key( $row->status );
			$count  = absint( $row->total );
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ] = $count;
			}
			$summary['total'] += $count;
		}

		$contact_sql = "SELECT COUNT(DISTINCT contact_id) FROM ({$union}) AS automation_events";
		$summary['contacts'] = absint( $wpdb->get_var( $wpdb->prepare( $contact_sql, $base_args ) ) );

		$flow_sql = "SELECT funnel_id, status, COUNT(*) AS total
			FROM ({$union}) AS automation_events
			GROUP BY funnel_id, status
			ORDER BY funnel_id ASC";
		$flow_rows = $wpdb->get_results( $wpdb->prepare( $flow_sql, $base_args ) );

		$flows = [];
		foreach ( $flow_rows as $row ) {
			$flow_id = absint( $row->funnel_id );
			if ( ! isset( $flows[ $flow_id ] ) ) {
				$flows[ $flow_id ] = [
					'flow_id' => $flow_id,
					'title'   => '',
					'total'   => 0,
					'waiting' => 0,
					'paused'  => 0,
					'complete'=> 0,
					'failed'  => 0,
					'cancelled' => 0,
					'skipped' => 0,
				];
			}
			$status = sanitize_key( $row->status );
			$count  = absint( $row->total );
			$flows[ $flow_id ]['total'] += $count;
			if ( isset( $flows[ $flow_id ][ $status ] ) ) {
				$flows[ $flow_id ][ $status ] += $count;
			}
		}

		foreach ( $flows as $flow_id => $flow ) {
			$funnel = new Funnel( $flow_id );
			$flows[ $flow_id ]['title'] = $funnel->exists() ? $funnel->get_title() : sprintf( __( 'Deleted flow #%d', 'groundhogg' ), $flow_id );
		}

		$step_sql = "SELECT funnel_id, step_id, status, COUNT(*) AS total
			FROM ({$union}) AS automation_events
			GROUP BY funnel_id, step_id, status
			ORDER BY funnel_id ASC, step_id ASC";
		$step_rows = $wpdb->get_results( $wpdb->prepare( $step_sql, $base_args ) );
		$steps = [];
		foreach ( $step_rows as $row ) {
			$key = absint( $row->funnel_id ) . ':' . absint( $row->step_id );
			if ( ! isset( $steps[ $key ] ) ) {
				$steps[ $key ] = [
					'flow_id' => absint( $row->funnel_id ),
					'step_id' => absint( $row->step_id ),
					'total' => 0,
					'waiting' => 0,
					'paused' => 0,
					'complete' => 0,
					'failed' => 0,
					'cancelled' => 0,
					'skipped' => 0,
					'in_progress' => 0,
				];
			}
			$status = sanitize_key( $row->status );
			$count = absint( $row->total );
			$steps[ $key ]['total'] += $count;
			if ( isset( $steps[ $key ][ $status ] ) ) {
				$steps[ $key ][ $status ] += $count;
			}
		}

		$daily_sql = "SELECT FLOOR(time / 86400) AS utc_day, status, COUNT(*) AS total
			FROM ({$union}) AS automation_events
			GROUP BY utc_day, status
			ORDER BY utc_day ASC";
		$daily_rows = $wpdb->get_results( $wpdb->prepare( $daily_sql, $base_args ) );
		$daily = [];
		foreach ( $daily_rows as $row ) {
			$day = absint( $row->utc_day );
			if ( ! isset( $daily[ $day ] ) ) {
				$daily[ $day ] = [ 'date' => gmdate( 'Y-m-d', $day * 86400 ), 'total' => 0, 'failed' => 0 ];
			}
			$count = absint( $row->total );
			$daily[ $day ]['total'] += $count;
			if ( Event::FAILED === $row->status ) {
				$daily[ $day ]['failed'] += $count;
			}
		}

		$failed_sql = "SELECT funnel_id, step_id, error_code, COUNT(*) AS total
			FROM ({$union}) AS automation_events
			WHERE status = %s
			GROUP BY funnel_id, step_id, error_code
			ORDER BY total DESC
			LIMIT 25";
		$failed_rows = $wpdb->get_results( $wpdb->prepare( $failed_sql, array_merge( $base_args, [ Event::FAILED ] ) ) );

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'dotflow_automation_report_query_failed', __( 'Automation failure metrics could not be retrieved.', 'groundhogg' ) );
		}

		$failures = [];
		foreach ( $failed_rows as $row ) {
			$failures[] = [
				'flow_id'    => absint( $row->funnel_id ),
				'step_id'    => absint( $row->step_id ),
				'error_code' => sanitize_key( $row->error_code ),
				'total'      => absint( $row->total ),
			];
		}

		return [
			'start'    => $start,
			'end'      => $end,
			'summary'  => $summary,
			'flows'    => array_values( $flows ),
			'steps'    => array_values( $steps ),
			'daily'    => array_values( $daily ),
			'failures' => $failures,
		];
	}

	/**
	 * Render the Reports > Automation tab.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'view_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to view automation reports.', 'groundhogg' ) );
		}

		$end   = current_time( 'timestamp', true );
		$start = $end - ( 30 * DAY_IN_SECONDS );
		$metrics = self::get_metrics( $start, $end );

		echo '<div class="wrap">';
		echo '<h2>' . esc_html__( 'Automation performance', 'groundhogg' ) . '</h2>';
		echo '<p>' . esc_html__( 'Operational outcomes from the existing workflow queue and history. Counts represent workflow-step events, not unique journey conversions.', 'groundhogg' ) . '</p>';

		if ( is_wp_error( $metrics ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $metrics->get_error_message() ) . '</p></div></div>';
			return;
		}

		echo '<p>' . esc_html( sprintf( __( 'Last 30 days · %s to %s UTC', 'groundhogg' ), gmdate( 'Y-m-d', $start ), gmdate( 'Y-m-d', $end ) ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ 'waiting', 'paused', 'in_progress', 'complete', 'failed', 'cancelled', 'skipped', 'total', 'contacts' ] as $key ) {
			echo '<th>' . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . '</th>';
		}
		echo '</tr></thead><tbody><tr>';
		foreach ( $metrics['summary'] as $value ) {
			echo '<td>' . esc_html( number_format_i18n( $value ) ) . '</td>';
		}
		echo '</tr></tbody></table>';

		echo '<h3>' . esc_html__( 'Flow outcomes', 'groundhogg' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Flow', 'groundhogg' ) . '</th><th>' . esc_html__( 'Total steps', 'groundhogg' ) . '</th><th>' . esc_html__( 'Waiting', 'groundhogg' ) . '</th><th>' . esc_html__( 'Paused', 'groundhogg' ) . '</th><th>' . esc_html__( 'Completed', 'groundhogg' ) . '</th><th>' . esc_html__( 'Failed', 'groundhogg' ) . '</th><th>' . esc_html__( 'Cancelled', 'groundhogg' ) . '</th></tr></thead><tbody>';
		if ( empty( $metrics['flows'] ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No automation activity in this period.', 'groundhogg' ) . '</td></tr>';
		}
		foreach ( $metrics['flows'] as $flow ) {
			echo '<tr><td>' . esc_html( $flow['title'] ) . '</td><td>' . esc_html( number_format_i18n( $flow['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $flow['waiting'] ) ) . '</td><td>' . esc_html( number_format_i18n( $flow['paused'] ) ) . '</td><td>' . esc_html( number_format_i18n( $flow['complete'] ) ) . '</td><td>' . esc_html( number_format_i18n( $flow['failed'] ) ) . '</td><td>' . esc_html( number_format_i18n( $flow['cancelled'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Daily activity and failure trend', 'groundhogg' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'UTC date', 'groundhogg' ) . '</th><th>' . esc_html__( 'Workflow-step events', 'groundhogg' ) . '</th><th>' . esc_html__( 'Failed events', 'groundhogg' ) . '</th></tr></thead><tbody>';
		if ( empty( $metrics['daily'] ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No activity in this period.', 'groundhogg' ) . '</td></tr>';
		}
		foreach ( $metrics['daily'] as $day ) {
			echo '<tr><td>' . esc_html( $day['date'] ) . '</td><td>' . esc_html( number_format_i18n( $day['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $day['failed'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Step outcomes and drop-off signals', 'groundhogg' ) . '</h3>';
		echo '<p>' . esc_html__( 'Compare how many queued step events complete, fail, remain pending or are cancelled. These are event counts, not unique-contact conversion rates.', 'groundhogg' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Flow ID', 'groundhogg' ) . '</th><th>' . esc_html__( 'Step ID', 'groundhogg' ) . '</th><th>' . esc_html__( 'Total', 'groundhogg' ) . '</th><th>' . esc_html__( 'Completed', 'groundhogg' ) . '</th><th>' . esc_html__( 'Failed', 'groundhogg' ) . '</th><th>' . esc_html__( 'Waiting', 'groundhogg' ) . '</th><th>' . esc_html__( 'Paused', 'groundhogg' ) . '</th><th>' . esc_html__( 'Cancelled', 'groundhogg' ) . '</th></tr></thead><tbody>';
		if ( empty( $metrics['steps'] ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No step activity in this period.', 'groundhogg' ) . '</td></tr>';
		}
		foreach ( $metrics['steps'] as $step ) {
			echo '<tr><td>' . esc_html( $step['flow_id'] ) . '</td><td>' . esc_html( $step['step_id'] ) . '</td><td>' . esc_html( number_format_i18n( $step['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $step['complete'] ) ) . '</td><td>' . esc_html( number_format_i18n( $step['failed'] ) ) . '</td><td>' . esc_html( number_format_i18n( $step['waiting'] ) ) . '</td><td>' . esc_html( number_format_i18n( $step['paused'] ) ) . '</td><td>' . esc_html( number_format_i18n( $step['cancelled'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Failed steps', 'groundhogg' ) . '</h3>';
		echo '<p>' . esc_html__( 'Failures are grouped by flow, step and safe error code. Contact identifiers, event arguments and raw error messages are intentionally excluded.', 'groundhogg' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Flow ID', 'groundhogg' ) . '</th><th>' . esc_html__( 'Step ID', 'groundhogg' ) . '</th><th>' . esc_html__( 'Error code', 'groundhogg' ) . '</th><th>' . esc_html__( 'Occurrences', 'groundhogg' ) . '</th></tr></thead><tbody>';
		if ( empty( $metrics['failures'] ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No failed steps in this period.', 'groundhogg' ) . '</td></tr>';
		}
		foreach ( $metrics['failures'] as $failure ) {
			echo '<tr><td>' . esc_html( $failure['flow_id'] ) . '</td><td>' . esc_html( $failure['step_id'] ) . '</td><td>' . esc_html( $failure['error_code'] ) . '</td><td>' . esc_html( number_format_i18n( $failure['total'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Webhook retries are not offered here: a timeout or remote side effect can make delivery ambiguous. Retry remains disabled until the receiver and sender can guarantee a shared idempotency contract.', 'groundhogg' ) . '</p>';
		echo '</div>';
	}
}
