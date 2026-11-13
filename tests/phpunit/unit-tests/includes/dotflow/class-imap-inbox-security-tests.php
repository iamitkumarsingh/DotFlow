<?php

class DotFlow_Imap_Inbox_Security_Tests extends GH_UnitTestCase {

	protected function with_mailbox_options( $host, $port, callable $callback ) {

		$old_host = get_option( 'gh_imap_inbox_host', null );
		$old_port = get_option( 'gh_imap_inbox_port', null );

		update_option( 'gh_imap_inbox_host', $host );
		update_option( 'gh_imap_inbox_port', $port );

		try {
			$inbox = new \Groundhogg\\Imap_Inbox();
			$method = new ReflectionMethod( \Groundhogg\\Imap_Inbox::class, 'mailbox_path' );
			$method->setAccessible( true );

			return $callback( $method->invoke( $inbox ) );
		} finally {
			if ( null === $old_host ) {
				delete_option( 'gh_imap_inbox_host' );
			} else {
				update_option( 'gh_imap_inbox_host', $old_host );
			}

			if ( null === $old_port ) {
				delete_option( 'gh_imap_inbox_port' );
			} else {
				update_option( 'gh_imap_inbox_port', $old_port );
			}
		}
	}

	public function test_mailbox_path_requires_tls_certificate_validation() {

		$this->with_mailbox_options( 'imap.example.test', 993, function ( $path ) {
			$this->assertSame( '{imap.example.test:993/imap/ssl}INBOX', $path );
			$this->assertStringNotContainsString( 'novalidate-cert', $path );
		} );
	}

	public function test_mailbox_host_cannot_inject_imap_flags_or_paths() {

		$this->with_mailbox_options( 'imap.example.test/imap/novalidate-cert', 993, function ( $path ) {
			$this->assertWPError( $path );
			$this->assertSame( 'imap_invalid_host', $path->get_error_code() );
		} );
	}

	public function test_mailbox_port_is_strictly_bounded() {

		$this->with_mailbox_options( 'imap.example.test', '993/imap/novalidate-cert', function ( $path ) {
			$this->assertWPError( $path );
			$this->assertSame( 'imap_invalid_port', $path->get_error_code() );
		} );
	}
}
