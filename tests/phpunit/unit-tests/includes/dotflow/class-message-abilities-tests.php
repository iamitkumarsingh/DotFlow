<?php

use Groundhogg\DotFlow\Abilities\List_Messages;
use Groundhogg\DotFlow\Abilities\Set_Message_Read_State;
use function Groundhogg\get_db;

class DotFlow_Message_Abilities_Tests extends GH_UnitTestCase {

	public function test_message_content_is_opt_in_and_read_state_is_editable() {

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->markTestSkipped( 'The test user cannot list all conversation records.' );
		}

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
			if ( $message_id ) {
				$db->delete( $message_id );
			}
		}
	}
}
