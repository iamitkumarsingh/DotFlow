<?php

namespace Groundhogg\DotFlow\Admin;

use Groundhogg\DotFlow\Messaging\Postmark_Provider;
use Groundhogg\DotFlow\Messaging\Postmark_Retry_Queue;
use Groundhogg\DotFlow\Messaging\Provider_Adapter_Registry;
use Groundhogg\DotFlow\Inbound\Inbound_Provider_Registry;
use Groundhogg\Pii;
use function Groundhogg\get_db;

/**
 * Read-only DotFlow operations console.
 *
 * Provider credentials and event payloads are deliberately never rendered.
 */
final class Platform_Dashboard {

	const PAGE = 'dotflow-platform';
	const CAPABILITY = 'manage_options';

	public static function register() {
		add_options_page(
			__( 'DotFlow', 'groundhogg' ),
			__( 'DotFlow', 'groundhogg' ),
			self::CAPABILITY,
			self::PAGE,
			[ __CLASS__, 'render' ]
		);
	}

	/**
	 * Build a privacy-minimised platform health snapshot.
	 *
	 * @return array
	 */
	public static function get_snapshot() {
		global $wpdb;

		$events = [
			'available' => false,
			'total_24h' => 0,
			'last_event' => null,
			'recent' => [],
			'audit' => [],
		];

		$event_db = get_db( 'dotflow_events' );
		if ( $event_db ) {
			$table = $event_db->get_table_name();
			$events['available'] = true;
			$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			$events['total_24h'] = absint( $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
				$since
			) ) );
			$rows = $wpdb->get_results(
				"SELECT ID, event_name, source, object_type, object_id, contact_id, occurred_at, created_at FROM {$table} ORDER BY ID DESC LIMIT 20",
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$events['recent'][] = [
					'id' => absint( $row['ID'] ),
					'event_name' => sanitize_text_field( $row['event_name'] ),
					'source' => sanitize_key( $row['source'] ),
					'object_type' => sanitize_key( $row['object_type'] ),
					'object_id' => ( Pii::is_restricted() && 'contact' === $row['object_type'] ) ? 0 : absint( $row['object_id'] ),
					'occurred_at' => (string) $row['occurred_at'],
				];
				if ( null === $events['last_event'] ) {
					$events['last_event'] = (string) $row['occurred_at'];
				}

			}

			$audit_rows = $wpdb->get_results(
				"SELECT ID, object_type, object_id, occurred_at, payload FROM {$table} WHERE event_name = 'ai.mutation.completed' ORDER BY ID DESC LIMIT 20",
				ARRAY_A
			);
			foreach ( (array) $audit_rows as $audit_row ) {
				$payload = json_decode( (string) $audit_row['payload'], true );
				$events['audit'][] = [
					'id' => absint( $audit_row['ID'] ),
					'action' => is_array( $payload ) && isset( $payload['action'] ) ? sanitize_text_field( $payload['action'] ) : 'mutation',
					'object_type' => sanitize_key( $audit_row['object_type'] ),
					'object_id' => ( Pii::is_restricted() && 'contact' === $audit_row['object_type'] ) ? 0 : absint( $audit_row['object_id'] ),
					'actor_user_id' => is_array( $payload ) ? absint( $payload['actor_user_id'] ?? 0 ) : 0,
					'occurred_at' => (string) $audit_row['occurred_at'],
				];
			}
		}

		$outbox = Postmark_Retry_Queue::counts();
		$server_configured = '' !== Postmark_Provider::secret( 'server_token' );
		$webhook_configured = '' !== Postmark_Provider::secret( 'webhook_secret' );

		return [
			'events' => $events,
			'providers' => [
				'postmark' => [
					'server_token_configured' => $server_configured,
					'webhook_secret_configured' => $webhook_configured,
					'outbound_adapter_registered' => null !== Provider_Adapter_Registry::get( 'postmark' ),
					'inbound_adapter_registered' => null !== Inbound_Provider_Registry::get( 'postmark' ),
				],
				'outbound_adapters' => Provider_Adapter_Registry::ids(),
				'inbound_adapters' => Inbound_Provider_Registry::ids(),
				'postmark_outbox' => $outbox,\n\t\t\t\t'analytics' => Analytics_Forwarder::get_status(),\n\t\t\t\t'dotform_hook_registered' => false !== has_action( 'dotform/submission/after' ),
			],
			'wordpress' => [
				'elementor_active' => class_exists( 'Elementor\Plugin' ),
				'php_version' => PHP_VERSION,
				'wordpress_version' => get_bloginfo( 'version' ),
			],
		];
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view DotFlow operations.', 'groundhogg' ), '', [ 'response' => 403 ] );
		}

		$snapshot = self::get_snapshot();
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		if ( ! in_array( $tab, [ 'overview', 'connections', 'events', 'audit' ], true ) ) {
			$tab = 'overview';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'DotFlow Operations', 'groundhogg' ) . '</h1>';
		echo '<p>' . esc_html__( 'Operational visibility for DotFlow integrations, event delivery and AI mutation audit history. This screen never displays provider credentials or raw event payloads.', 'groundhogg' ) . '</p>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( [ 'overview' => __( 'Overview', 'groundhogg' ), 'connections' => __( 'Connections', 'groundhogg' ), 'events' => __( 'Event health', 'groundhogg' ), 'audit' => __( 'Audit history', 'groundhogg' ) ] as $key => $label ) {
			$url = add_query_arg( [ 'page' => self::PAGE, 'tab' => $key ], admin_url( 'options-general.php' ) );
			printf( '<a class="nav-tab %1$s" href="%2$s">%3$s</a>', $tab === $key ? 'nav-tab-active' : '', esc_url( $url ), esc_html( $label ) );
		}
		echo '</nav>';

		if ( 'overview' === $tab ) {
			echo '<h2>' . esc_html__( 'Platform health', 'groundhogg' ) . '</h2>';
			echo '<table class="widefat striped"><tbody>';
			self::row( __( 'WordPress', 'groundhogg' ), $snapshot['wordpress']['wordpress_version'] );
			self::row( __( 'PHP', 'groundhogg' ), $snapshot['wordpress']['php_version'] );
			self::row( __( 'Elementor', 'groundhogg' ), $snapshot['wordpress']['elementor_active'] ? __( 'Active', 'groundhogg' ) : __( 'Not detected', 'groundhogg' ) );
			self::row( __( 'Event store', 'groundhogg' ), $snapshot['events']['available'] ? __( 'Available', 'groundhogg' ) : __( 'Unavailable', 'groundhogg' ) );
			self::row( __( 'Events in last 24 hours', 'groundhogg' ), number_format_i18n( $snapshot['events']['total_24h'] ) );
			self::row( __( 'Last event (UTC)', 'groundhogg' ), $snapshot['events']['last_event'] ?: __( 'No events recorded', 'groundhogg' ) );
			echo '</tbody></table>';
		} elseif ( 'connections' === $tab ) {
			echo '<h2>' . esc_html__( 'Provider connections', 'groundhogg' ) . '</h2>';
			echo '<p>' . esc_html__( 'Connection status only. Credentials are write-only and are never returned or displayed here.', 'groundhogg' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Provider', 'groundhogg' ) . '</th><th>' . esc_html__( 'Outbound', 'groundhogg' ) . '</th><th>' . esc_html__( 'Inbound', 'groundhogg' ) . '</th><th>' . esc_html__( 'Credentials', 'groundhogg' ) . '</th></tr></thead><tbody>';
			$p = $snapshot['providers']['postmark'];
			printf( '<tr><td>Postmark</td><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( $p['outbound_adapter_registered'] ? __( 'Registered', 'groundhogg' ) : __( 'Not registered', 'groundhogg' ) ),
				esc_html( $p['inbound_adapter_registered'] ? __( 'Registered', 'groundhogg' ) : __( 'Not registered', 'groundhogg' ) ),
				esc_html( $p['server_token_configured'] && $p['webhook_secret_configured'] ? __( 'Configured', 'groundhogg' ) : __( 'Incomplete', 'groundhogg' ) )
			);
			echo '</tbody></table>';
			echo '<h3>' . esc_html__( 'Registered adapters', 'groundhogg' ) . '</h3>';
			self::list_items( array_merge( $snapshot['providers']['outbound_adapters'], $snapshot['providers']['inbound_adapters'] ) );
			echo '<h3>' . esc_html__( 'Integration availability', 'groundhogg' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Integration', 'groundhogg' ) . '</th><th>' . esc_html__( 'Status', 'groundhogg' ) . '</th></tr></thead><tbody>';
			self::row( 'Elementor', $snapshot['wordpress']['elementor_active'] ? __( 'Detected', 'groundhogg' ) : __( 'Not detected', 'groundhogg' ) );
			foreach ( [ 'DotForm', 'Google Analytics 4', 'Google Tag Manager', 'HubSpot', 'WooCommerce' ] as $integration ) {
				self::row( $integration, __( 'Not integrated yet — planned integration phase', 'groundhogg' ) );
			}
			echo '</tbody></table>';
			echo '<p>' . esc_html__( 'Configure Postmark under Groundhogg → Settings → Email. This screen reports status only; it does not reveal or modify provider credentials.', 'groundhogg' ) . '</p>';
		} elseif ( 'events' === $tab ) {
			echo '<h2>' . esc_html__( 'Recent event activity', 'groundhogg' ) . '</h2>';
			echo '<p>' . esc_html( sprintf( __( '%s events recorded in the last 24 hours.', 'groundhogg' ), number_format_i18n( $snapshot['events']['total_24h'] ) ) ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Event', 'groundhogg' ) . '</th><th>' . esc_html__( 'Source', 'groundhogg' ) . '</th><th>' . esc_html__( 'Object', 'groundhogg' ) . '</th><th>' . esc_html__( 'Occurred (UTC)', 'groundhogg' ) . '</th></tr></thead><tbody>';
			if ( empty( $snapshot['events']['recent'] ) ) {
				echo '<tr><td colspan="5">' . esc_html__( 'No events recorded.', 'groundhogg' ) . '</td></tr>';
			}
			foreach ( $snapshot['events']['recent'] as $event ) {
				printf( '<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s #%5$d</td><td>%6$s</td></tr>',
					$event['id'], esc_html( $event['event_name'] ), esc_html( $event['source'] ), esc_html( $event['object_type'] ), $event['object_id'], esc_html( $event['occurred_at'] )
				);
			}
			echo '</tbody></table>';
			echo '<h3>' . esc_html__( 'Postmark outbox', 'groundhogg' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Pending', 'groundhogg' ) . '</th><th>' . esc_html__( 'Processing', 'groundhogg' ) . '</th><th>' . esc_html__( 'Accepted', 'groundhogg' ) . '</th><th>' . esc_html__( 'Failed', 'groundhogg' ) . '</th><th>' . esc_html__( 'Uncertain', 'groundhogg' ) . '</th></tr></thead><tbody><tr>';
			foreach ( $snapshot['providers']['postmark_outbox'] as $count ) {
				echo '<td>' . esc_html( number_format_i18n( $count ) ) . '</td>';
			}
			echo '</tr></tbody></table>';
		} else {
			echo '<h2>' . esc_html__( 'Recent AI mutation audit history', 'groundhogg' ) . '</h2>';
			echo '<p>' . esc_html__( 'Only action metadata is shown. Changed values, message content, credentials and raw payloads are excluded.', 'groundhogg' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Action', 'groundhogg' ) . '</th><th>' . esc_html__( 'Object', 'groundhogg' ) . '</th><th>' . esc_html__( 'Actor user ID', 'groundhogg' ) . '</th><th>' . esc_html__( 'Occurred (UTC)', 'groundhogg' ) . '</th></tr></thead><tbody>';
			if ( empty( $snapshot['events']['audit'] ) ) {
				echo '<tr><td colspan="5">' . esc_html__( 'No AI mutation audit events recorded.', 'groundhogg' ) . '</td></tr>';
			}
			foreach ( $snapshot['events']['audit'] as $audit ) {
				printf( '<tr><td>%1$d</td><td>%2$s</td><td>%3$s #%4$d</td><td>%5$d</td><td>%6$s</td></tr>',
					$audit['id'], esc_html( $audit['action'] ), esc_html( $audit['object_type'] ), $audit['object_id'], $audit['actor_user_id'], esc_html( $audit['occurred_at'] )
				);
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private static function row( $label, $value ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
	}

	private static function list_items( array $items ) {
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No provider adapters registered.', 'groundhogg' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( array_unique( $items ) as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul>';
	}
}
