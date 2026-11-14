<?php

use Groundhogg\Abilities\Emails\Send_Composed_Email;
use function Groundhogg\is_sending;

/**
 * The composed-email ability must not leave the process-wide sending flag enabled
 * when validation or email preparation exits before a message is sent.
 */
class DotFlow_Composed_Email_Sending_Flag_Tests extends GH_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		is_sending( false );
	}

	public function tearDown(): void {
		is_sending( false );
		parent::tearDown();
	}

	protected function ability(): Send_Composed_Email {
		$reflection = new ReflectionClass( Send_Composed_Email::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	public function test_no_recipient_error_restores_the_sending_flag() {

		$result = $this->ability()->__invoke( [
			'subject' => 'Test',
			'content' => '<p>Test</p>',
		] );

		$this->assertWPError( $result );
		$this->assertSame( 'groundhogg_no_recipients', $result->get_error_code() );
		$this->assertFalse( is_sending() );
	}

	public function test_existing_sending_state_is_restored_for_nested_sends() {

		is_sending( true );

		$result = $this->ability()->__invoke( [
			'subject' => 'Test',
			'content' => '<p>Test</p>',
		] );

		$this->assertWPError( $result );
		$this->assertTrue( is_sending() );
	}

	public function test_invalid_reply_headers_restore_the_sending_flag() {

		$result = $this->ability()->__invoke( [
			'to'      => [ 'outside@example.test' ],
			'subject' => 'Test',
			'content' => '<p>Test</p>',
			'headers' => [ 'Bcc' => 'other@example.test' ],
		] );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_header', $result->get_error_code() );
		$this->assertFalse( is_sending() );
	}

	public function test_exception_during_preparation_restores_the_sending_flag() {

		$throw = static function () {
			throw new RuntimeException( 'preparation failed' );
		};

		add_filter( 'groundhogg/add_custom_footer_text_to_personal_emails', $throw );

		try {
			$this->ability()->__invoke( [
				'to'      => [ 'outside@example.test' ],
				'subject' => 'Test',
				'content' => '<p>Test</p>',
			] );
			$this->fail( 'The preparation exception should be propagated.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'preparation failed', $e->getMessage() );
		} finally {
			remove_filter( 'groundhogg/add_custom_footer_text_to_personal_emails', $throw );
		}

		$this->assertFalse( is_sending() );
	}
}
