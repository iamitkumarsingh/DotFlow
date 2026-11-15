<?php

use Groundhogg\DotFlow\Admin\Platform_Dashboard;
use Groundhogg\DotFlow\Events\Event;
use Groundhogg\DotFlow\Events\Store;
use function Groundhogg\get_db;

class DotFlow_Platform_Dashboard_Tests extends GH_UnitTestCase {

	protected $event_ids = [];

	public function tearDown(): void {
		global $wpdb;
		$db = get_db( 'dotflow_events' );
		if ( $db ) {
			foreach ( $this->event_ids as $event_id ) {
				$wpdb->delete( $db->get_table_name(), [ 'event_id' => $event_id ], [ '%s' ] );
			}
		}
		parent::tearDown();
	}

	public function test_snapshot_exposes_operational_metadata_but_never_provider_secrets_or_event_payloads() {
		Store::maybe_install();
		$event_id = 'platform-dashboard-' . wp_generate_uuid4();
		$this->event_ids[] = $event_id;
		Store::record( new Event(
			'ai.mutation.completed',
			[
				'action' => 'contact.updated',
				'actor_user_id' => get_current_user_id(),
				'contact_id' => 0,
				'changes' => [ 'api_key' => 'must-not-render-this-secret' ],
			],
			'mcp',
			$event_id
		) );

		$snapshot = Platform_Dashboard::get_snapshot();
		$this->assertArrayHasKey( 'events', $snapshot );
		$this->assertArrayHasKey( 'providers', $snapshot );
		$this->assertArrayHasKey( 'wordpress', $snapshot );
		$this->assertNotEmpty( $snapshot['events']['recent'] );
		$this->assertArrayHasKey( 'audit', $snapshot['events'] );
		$this->assertArrayHasKey( 'postmark_outbox', $snapshot['providers'] );

		$json = wp_json_encode( $snapshot );
		$this->assertStringNotContainsString( 'must-not-render-this-secret', $json );
		$this->assertArrayNotHasKey( 'server_token', $snapshot['providers']['postmark'] );
		$this->assertArrayNotHasKey( 'webhook_secret', $snapshot['providers']['postmark'] );
	}

	public function test_admin_menu_registration_is_restricted_to_manage_options() {
		$previous_user_id = get_current_user_id();
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		try {
			Platform_Dashboard::register();
			$reflection = new ReflectionClass( Platform_Dashboard::class );
			$this->assertSame( 'manage_options', $reflection->getConstant( 'CAPABILITY' ) );
			$this->assertTrue( current_user_can( Platform_Dashboard::CAPABILITY ) );
		} finally {
			wp_set_current_user( $previous_user_id );
		}
	}}
