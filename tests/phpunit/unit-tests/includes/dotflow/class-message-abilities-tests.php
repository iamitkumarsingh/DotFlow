<?php

use Groundhogg\DotFlow\Abilities\List_Messages;
use Groundhogg\DotFlow\Abilities\Set_Message_Read_State;
use Groundhogg\Pii;
use function Groundhogg\get_db;

class DotFlow_Message_Abilities_Tests extends GH_UnitTestCase {

	public function test_message_content_is_opt_in_and_read_state_is_editable() {

		$previous_user_id = get_current_user_id();
		$user_id          = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( 'manage_options' ), 'The administrator fixture must be able to manage options.' );

		$db = get_db( 'messages' );

		$message_id = $db->insert( [
			'object_type'  => 'contact',
			'object_id'    => 1,
			'direction'    => 'inbound',
			'from_address' => 'patient@example.test',
			'to_address'   => 'practice@example.test',
			'subject'      => 'DotFlow MCP test',
			'summary'      => 'A test conversation summary.',
			'content'      => 'Private test message body.',
			'thread_id'    => 'dotflow-mcp-thread-test',
			'message_id'   => '<dotflow-mcp-test@example.test>',
			'is_read'      => 0,
		] );

		try {
			$list_reflection = new ReflectionClass( List_Messages::class );
			$list_ability    = $list_reflection->newInstanceWithoutConstructor();

			$summary_result = $list_ability( [ 'thread_id' => 'dotflow-mcp-thread-test' ] );

			$this->assertSame( 1, $summary_result['total_items'] );
			$this->assertArrayNotHasKey( 'content', $summary_result['messages'][0] );

			$content_result = $list_ability( [
				'thread_id'        => 'dotflow-mcp-thread-test',
				'include_content'  => true,
			] );

			$this->assertSame( 'Private test message body.', $content_result['messages'][0]['content'] );

			$state_reflection = new ReflectionClass( Set_Message_Read_State::class );
			$state_ability    = $state_reflection->newInstanceWithoutConstructor();
			$state_result     = $state_ability( [
				'message_id' => $message_id,
				'is_read'    => true,
			] );

			$this->assertTrue( $state_result['updated'] );
			$this->assertTrue( $state_result['is_read'] );
		} finally {
			wp_set_current_user( $previous_user_id );
			if ( $message_id ) {
				$db->delete( $message_id );
			}
		}
	}
	public function test_restricted_admin_cannot_read_conversation_messages() {

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_userdata( $user_id );
		$user->add_cap( Pii::CAP, false );
		wp_set_current_user( $user_id );

		$reflection = new ReflectionClass( List_Messages::class );
		$ability    = $reflection->newInstanceWithoutConstructor();
		$result     = $ability( [ 'contact_id' => 1 ] );

		$this->assertWPError( $result );
		$this->assertSame( 'groundhogg_pii_restricted', $result->get_error_code() );
	}


}
