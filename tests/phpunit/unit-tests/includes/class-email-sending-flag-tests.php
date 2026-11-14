<?php

use Groundhogg\Email;
use function Groundhogg\get_contactdata;
use function Groundhogg\is_sending;

/**
 * is_sending() gates things that must only happen while an email is really going out, like minting
 * an auto-login key. Email::send() used to turn it on and only turn it off after a successful send,
 * so every early return left it on for the rest of the process.
 *
 * @see Email::send()
 */
class Email_Sending_Flag_Tests extends GH_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		is_sending( false );
	}

	public function tearDown(): void {
		is_sending( false );
		parent::tearDown();
	}

	protected function email( array $args = [] ) {
		return new Email( array_merge( [
			'subject' => 'Hello',
			'content' => '<p>Hello</p>',
			'status'  => 'ready',
		], $args ) );
	}

	protected function contact() {
		return get_contactdata( self::factory()->contacts->create() );
	}

	public function test_the_flag_is_on_while_the_email_is_being_sent() {

		$seen = null;

		add_action( 'phpmailer_init', function () use ( &$seen ) {
			$seen = is_sending();
		} );

		$this->email()->send( $this->contact() );

		$this->assertTrue( $seen, 'is_sending() should be on while PHPMailer is handed the email' );
	}

	public function test_the_flag_is_off_after_a_successful_send() {

		$this->assertTrue( (bool) $this->email()->send( $this->contact() ) );

		$this->assertFalse( is_sending() );
	}

	public function test_the_flag_is_off_when_the_email_is_not_ready() {

		$result = $this->email( [ 'status' => 'draft' ] )->send( $this->contact() );

		$this->assertWPError( $result );
		$this->assertSame( 'email_not_ready', $result->get_error_code() );
		$this->assertFalse( is_sending() );
	}

	public function test_the_flag_is_off_when_the_recipient_is_not_valid() {

		$result = $this->email()->send( 999999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'no_recipient', $result->get_error_code() );
		$this->assertFalse( is_sending() );
	}

	public function test_the_flag_is_off_when_the_contact_is_undeliverable() {

		$contact = $this->contact();
		$contact->update( [ 'optin_status' => \Groundhogg\Preferences::BLOCKED ] );

		$result = $this->email()->send( $contact );

		$this->assertWPError( $result );
		$this->assertFalse( is_sending() );
	}

	public function test_transport_state_and_failure_hook_are_restored_when_a_provider_throws() {

		Groundhogg_Email_Services::set_current_message_type( 'transactional' );
		Groundhogg_Email_Services::set_current_email_service( 'wp_mail' );
		$original_callback = Groundhogg_Email_Services::get_service_callback( 'wp_mail' );
		$throw = static function () {
			throw new RuntimeException( 'transport failed' );
		};
		Groundhogg_Email_Services::register( 'wp_mail', 'WordPress Default', $throw );

		try {
			Groundhogg_Email_Services::send_with_service(
				'wp_mail',
				[ 'person@example.test' ],
				'Test',
				'Test body'
			);
			$this->fail( 'The provider exception should be propagated.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'transport failed', $e->getMessage() );
		} finally {
			Groundhogg_Email_Services::register( 'wp_mail', 'WordPress Default', $original_callback );
		}

		$this->assertSame( 'transactional', Groundhogg_Email_Services::get_current_message_type() );
		$this->assertSame( 'wp_mail', Groundhogg_Email_Services::get_current_email_service() );
		$this->assertFalse( has_action( 'wp_mail_failed', [ Groundhogg_Email_Services::class, 'catch_wp_mail_failed' ] ) );
	}

	public function test_before_send_exception_also_restores_transport_state_and_hook() {

		Groundhogg_Email_Services::set_current_message_type( 'marketing' );
		Groundhogg_Email_Services::set_current_email_service( 'wp_mail' );
		$throw = static function () {
			throw new RuntimeException( 'before send failed' );
		};
		add_action( 'groundhogg/email_services/before_send', $throw );

		try {
			Groundhogg_Email_Services::send_with_service(
				'wp_mail',
				[ 'person@example.test' ],
				'Test',
				'Test body'
			);
			$this->fail( 'The before_send exception should be propagated.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'before send failed', $e->getMessage() );
		} finally {
			remove_action( 'groundhogg/email_services/before_send', $throw );
		}

		$this->assertSame( 'marketing', Groundhogg_Email_Services::get_current_message_type() );
		$this->assertSame( 'wp_mail', Groundhogg_Email_Services::get_current_email_service() );
		$this->assertFalse( has_action( 'wp_mail_failed', [ Groundhogg_Email_Services::class, 'catch_wp_mail_failed' ] ) );
	}

	public function test_the_flag_is_off_when_something_throws_while_sending() {

		add_action( 'groundhogg/email/before_send', function () {
			throw new RuntimeException( 'boom' );
		} );

		try {
			$this->email()->send( $this->contact() );
			$this->fail( 'the exception should have got out of send()' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertFalse( is_sending() );
	}
}
