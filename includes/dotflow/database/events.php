<?php

namespace Groundhogg\DotFlow\Database;

use Groundhogg\DB\DB;

/**
 * Durable DotFlow domain-event store.
 *
 * Registered with Groundhogg's DB manager so install, multisite and uninstall
 * lifecycles include this table.
 */
class Events extends DB {

	public function get_db_suffix() {
		return 'gh_dotflow_events';
	}

	public function get_primary_key() {
		return 'ID';
	}

	public function get_db_version() {
		return '1.0';
	}

	public function get_object_type() {
		return 'dotflow_event';
	}

	public function get_date_key() {
		return 'occurred_at';
	}

	public function get_columns() {
		return [
			'ID'            => '%d',
			'event_id'      => '%s',
			'source'        => '%s',
			'event_name'    => '%s',
			'object_type'   => '%s',
			'object_id'     => '%d',
			'contact_id'    => '%d',
			'payload'       => '%s',
			'occurred_at'   => '%s',
			'created_at'    => '%s',
			'actor_user_id' => '%d',
		];
	}

	public function get_column_defaults() {
		return [
			'ID'            => 0,
			'event_id'      => '',
			'source'        => 'internal',
			'event_name'    => '',
			'object_type'   => '',
			'object_id'     => 0,
			'contact_id'    => 0,
			'payload'       => '{}',
			'occurred_at'   => gmdate( 'Y-m-d H:i:s' ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'actor_user_id' => 0,
		];
	}

	/**
	 * SQL schema used by Groundhogg's dbDelta installer.
	 *
	 * @return string
	 */
	public function create_table_sql_command() {

		return "CREATE TABLE {$this->table_name} (
			ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(120) NOT NULL,
			source varchar(64) NOT NULL,
			event_name varchar(191) NOT NULL,
			object_type varchar(50) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
			payload longtext NOT NULL,
			occurred_at datetime NOT NULL,
			created_at datetime NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (ID),
			UNIQUE KEY source_event (source,event_id),
			KEY event_name (event_name),
			KEY contact_time (contact_id,occurred_at),
			KEY object_lookup (object_type,object_id),
			KEY occurred_at (occurred_at)
		) {$this->get_charset_collate()};";
	}
}
