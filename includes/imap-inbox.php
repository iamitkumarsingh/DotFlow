<?php

namespace Groundhogg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads messages from the site's configured IMAP inbox.
 *
 * Uses PHP's IMAP extension directly. This avoids loading an obsolete third-party
 * IMAP client and always validates the remote TLS certificate.
 */
class Imap_Inbox {

	/**
	 * The inbox username.
	 *
	 * @var string|false
	 */
	protected $inbox;

	/**
	 * The inbox password.
	 *
	 * @var string|false
	 */
	protected $password;

	public function __construct() {

		if ( is_admin() && get_request_var( 'test_imap_connection' ) ) {
			add_action( 'init', array( $this, 'do_test_connection' ) );
		}
	}

	public function test_connection_ui() {
		$this->setup();

		if ( $this->inbox && $this->password ) {
			?>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'test_imap_connection', '1', get_request_uri() ) ) ); ?>"
			   class="button-secondary"><?php echo esc_html_x( 'Test IMAP Connection', 'action', 'groundhogg' ) ?></a>
			<?php
		}
	}

	/**
	 * @return string|false
	 */
	public function get_inbox_pw() {
		return get_option( 'gh_imap_inbox_password' );
	}

	/**
	 * @return string|false
	 */
	public function get_username() {
		return get_option( 'gh_imap_inbox_address' );
	}

	/**
	 * @return string
	 */
	public function get_mail_server() {
		return get_option( 'gh_imap_inbox_host', wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * @return int
	 */
	public function get_port() {
		return get_option( 'gh_imap_inbox_port', 993 );
	}

	/**
	 * Load the configured inbox credentials.
	 *
	 * @return void
	 */
	private function setup() {
		$this->inbox    = $this->get_username();
		$this->password = $this->get_inbox_pw();
	}

	/**
	 * Build a validated SSL mailbox path.
	 *
	 * The host and port are configuration values, not trusted IMAP flags. Validate
	 * them before interpolating either into the mailbox path.
	 *
	 * @return string|\\WP_Error
	 */
	protected function mailbox_path() {

		$host = trim( (string) $this->get_mail_server() );
		$port = absint( $this->get_port() );

		$is_ipv4 = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		$is_ipv6 = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );

		if ( $is_ipv6 ) {
			$host = '[' . $host . ']';
		} elseif ( ! $is_ipv4 && ! preg_match( '/\\A(?=.{1,253}\\z)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\\z/i', $host ) ) {
			return new \\WP_Error( 'imap_invalid_host', __( 'The configured IMAP server host is invalid.', 'groundhogg' ) );
		}

		if ( $port < 1 || $port > 65535 ) {
			return new \\WP_Error( 'imap_invalid_port', __( 'The configured IMAP server port is invalid.', 'groundhogg' ) );
		}

		// Deliberately omit /novalidate-cert: certificate and hostname verification
		// must remain enabled for the TLS connection.
		return sprintf( '{%s:%d/imap/ssl}INBOX', $host, $port );
	}

	/**
	 * Test the bounce inbox connection.
	 *
	 * @return bool|\\WP_Error
	 */
	public function do_test_connection() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( get_request_var( '_wpnonce' ) ) ) {
			return;
		}

		$test = $this->test_connection();

		if ( is_wp_error( $test ) ) {
			Plugin::$instance->notices->add( $test );

			return;
		}

		Plugin::$instance->notices->add( 'imap_success', esc_html_x( 'Successful IMAP connection established.', 'notice', 'groundhogg' ) );
	}

	/**
	 * Test the bounce inbox connection using validated TLS.
	 *
	 * @return bool|\\WP_Error
	 */
	public function test_connection() {

		$this->setup();

		if ( empty( $this->password ) || empty( $this->inbox ) ) {
			return false;
		}

		if ( ! function_exists( 'imap_open' ) ) {
			return new \\WP_Error( 'imap_extension_missing', __( 'The PHP IMAP extension is required to test this connection.', 'groundhogg' ) );
		}

		$mailbox = $this->mailbox_path();

		if ( is_wp_error( $mailbox ) ) {
			return $mailbox;
		}

		try {
			$connection = @imap_open( $mailbox, (string) $this->inbox, (string) $this->password, OP_READONLY );

			if ( ! $connection ) {
				return new \\WP_Error( 'imap_connection_failed', __( 'A secure connection to the configured IMAP server could not be established. Check the host, port, credentials and TLS certificate.', 'groundhogg' ) );
			}

			imap_close( $connection );

			return true;
		} catch ( \\Throwable $error ) {
			return new \\WP_Error( 'imap_connection_failed', __( 'A secure connection to the configured IMAP server could not be established.', 'groundhogg' ) );
		}
	}

	/**
	 * Get messages associated with a contact using the native IMAP extension.
	 *
	 * Only message metadata is returned. Message bodies and attachments are not
	 * downloaded. Failures return an empty list, preserving the existing caller
	 * contract and avoiding disclosure of mailbox/server details.
	 *
	 * @param Contact $contact Contact to search for.
	 * @return array
	 */
	public function use_php_imap( $contact ) {

		$this->setup();

		if ( ! function_exists( 'imap_open' ) || empty( $this->password ) || empty( $this->inbox ) ) {
			return [];
		}

		if ( ! is_object( $contact ) || ! method_exists( $contact, 'get_email' ) ) {
			return [];
		}

		$email = sanitize_email( $contact->get_email() );

		if ( ! is_email( $email ) ) {
			return [];
		}

		$mailbox = $this->mailbox_path();

		if ( is_wp_error( $mailbox ) ) {
			return [];
		}

		$connection = false;

		try {
			$connection = @imap_open( $mailbox, (string) $this->inbox, (string) $this->password, OP_READONLY );

			if ( ! $connection ) {
				return [];
			}

			// Contact email is validated first; additionally escape IMAP quoted-string
			// delimiters before placing it into the search expression.
			$search_email = str_replace( [ '\\\\', '"' ], [ '\\\\\\\\', '\\\\"' ], $email );
			$criteria     = sprintf( 'OR FROM "%s" TO "%s"', $search_email, $search_email );
			$uids         = @imap_search( $connection, $criteria, SE_UID );

			if ( ! is_array( $uids ) || empty( $uids ) ) {
				return [];
			}

			$uid_list = implode( ',', array_map( 'absint', $uids ) );
			$overview = @imap_fetch_overview( $connection, $uid_list, FT_UID );

			if ( ! is_array( $overview ) ) {
				return [];
			}

			$messages = [];

			foreach ( $overview as $message ) {
				$messages[] = [
					'uid'        => isset( $message->uid ) ? absint( $message->uid ) : 0,
					'subject'    => isset( $message->subject ) ? sanitize_text_field( imap_utf8( (string) $message->subject ) ) : '',
					'from'       => isset( $message->from ) ? sanitize_text_field( (string) $message->from ) : '',
					'to'         => isset( $message->to ) ? sanitize_text_field( (string) $message->to ) : '',
					'date'       => isset( $message->date ) ? sanitize_text_field( (string) $message->date ) : '',
					'message_id' => isset( $message->message_id ) ? sanitize_text_field( (string) $message->message_id ) : '',
					'seen'       => ! empty( $message->seen ),
				];
			}

			return $messages;
		} catch ( \\Throwable $error ) {
			return [];
		} finally {
			if ( $connection ) {
				@imap_close( $connection );
			}
		}
	}

	/**
	 * Get emails associated with the contact record.
	 *
	 * @param Contact $contact Contact.
	 * @return array
	 */
	public function fetch( $contact ) {
		return $this->use_php_imap( $contact );
	}
}
