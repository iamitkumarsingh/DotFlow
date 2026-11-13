<?php

use Groundhogg\DotFlow\Events\Event;
use Groundhogg\DotFlow\Events\Store;
use function Groundhogg\get_db;

class DotFlow_Event_Store_Tests extends GH_UnitTestCase {

	public function test_store_is_idempotent_and_redacts_secret_fields() {

		global $wpdb;

		Store::maybe_install();

		$db = get_db( 'dotflow_events' );

		$event = new Event(
			'contact.created',
			[
				'contact_id' => 123,
				'email'      => 'person@example.test',
				'api_key'    => 'do-not-store-this',
				'provider'   => [
					'access_token' => 'nested-secret',
					'campaign_id'  => 88,
				],
			],
			'unit-test',
			'dotflow-store-test-001'
		);

		$first_id  = Store::record( $event );
		$second_id = Store::record( $event );

		try {
			$this->assertGreaterThan( 0, $first_id );
			$this->assertSame( $first_id, $second_id );

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT payload FROM {$db->get_table_name()} WHERE ID = %d",
					$first_id
				)
			);

			$payload = json_decode( $row->payload, true );

			$this->assertSame( '[REDACTED]', $payload['api_key'] );
			$this->assertSame( '[REDACTED]', $payload['provider']['access_token'] );
			$this->assertSame( 88, $payload['provider']['campaign_id'] );
		} finally {
			if ( $first_id ) {
				$db->delete( $first_id );
			}
		}
	}
}
