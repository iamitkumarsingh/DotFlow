<?php

use Groundhogg\\DotFlow\\Events\\Event;
use Groundhogg\\DotFlow\\Events\\Store;
use function Groundhogg\\get_db;

/**
 * Stage 4 event-delivery ordering and idempotency regression coverage.
 */
class DotFlow_Event_Ordering_Tests extends GH_UnitTestCase {

	public function test_out_of_order_delivery_preserves_occurrence_time_and_source_scoped_idempotency() {

		global $wpdb;

		Store::maybe_install();

		$db = get_db( 'dotflow_events' );
		$this->assertNotFalse( $db );

		$shared_id = 'dotflow-order-' . wp_generate_uuid4();
		$older_id  = $shared_id . '-older';
		$newer     = new Event(
			'contact.activity.recorded',
			[ 'contact_id' => 701, 'object_type' => 'contact', 'object_id' => 701 ],
			'provider_a',
			$shared_id,
			'2026-11-14T12:00:00+00:00'
		);
		$older     = new Event(
			'contact.activity.recorded',
			[ 'contact_id' => 701, 'object_type' => 'contact', 'object_id' => 701 ],
			'provider_a',
			$older_id,
			'2026-11-14T11:00:00+00:00'
		);
		$same_id_other_source = new Event(
			'contact.activity.recorded',
			[ 'contact_id' => 701, 'object_type' => 'contact', 'object_id' => 701 ],
			'provider_b',
			$shared_id,
			'2026-11-14T12:00:00+00:00'
		);

		$recorded_ids = [];

		try {
			// Deliberately deliver the newer occurrence before the older occurrence.
			$newer_result = Store::record_with_status( $newer );
			$older_result = Store::record_with_status( $older );
			$duplicate    = Store::record_with_status( $newer );
			$other_source = Store::record_with_status( $same_id_other_source );

			$this->assertIsArray( $newer_result );
			$this->assertIsArray( $older_result );
			$this->assertIsArray( $duplicate );
			$this->assertIsArray( $other_source );

			$recorded_ids = [
				$newer_result['id'],
				$older_result['id'],
				$other_source['id'],
			];

			$this->assertTrue( $newer_result['inserted'] );
			$this->assertTrue( $older_result['inserted'] );
			$this->assertFalse( $duplicate['inserted'] );
			$this->assertSame( $newer_result['id'], $duplicate['id'] );
			$this->assertTrue( $other_source['inserted'] );
			$this->assertNotSame( $newer_result['id'], $other_source['id'] );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT source, event_id, occurred_at FROM {$db->get_table_name()} WHERE ID IN (%d, %d, %d)",
					$recorded_ids[0],
					$recorded_ids[1],
					$recorded_ids[2]
				),
				ARRAY_A
			);

			$this->assertCount( 3, $rows );

			$occurrences = [];
			foreach ( $rows as $row ) {
				$occurrences[ $row['source'] . ':' . $row['event_id'] ] = $row['occurred_at'];
			}

			$this->assertSame( '2026-11-14 12:00:00', $occurrences[ 'provider_a:' . $shared_id ] );
			$this->assertSame( '2026-11-14 11:00:00', $occurrences[ 'provider_a:' . $older_id ] );
			$this->assertSame( '2026-11-14 12:00:00', $occurrences[ 'provider_b:' . $shared_id ] );
		} finally {
			foreach ( $recorded_ids as $record_id ) {
				$db->delete( $record_id );
			}
		}
	}
}
