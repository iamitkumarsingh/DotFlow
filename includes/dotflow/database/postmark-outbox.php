<?php

namespace Groundhogg\DotFlow\Database;

use Groundhogg\DB\DB;

/**
 * Encrypted retry ledger for Postmark rate-limit responses.
 */
class Postmark_Outbox extends DB {

	public function get_db_suffix() { return 'gh_dotflow_postmark_outbox'; }
	public function get_primary_key() { return 'ID'; }
	public function get_db_version() { return '1.0'; }
	public function get_object_type() { return 'dotflow_postmark_outbox'; }
	public function get_date_key() { return 'created_at'; }

	public function get_columns() {
		return [
			'ID'                  => '%d',
			'idempotency_key'     => '%s',
			'payload'             => '%s',
			'status'              => '%s',
			'attempts'            => '%d',
			'available_at'        => '%s',
			'claim_token'         => '%s',
			'claimed_at'          => '%s',
			'provider_message_id' => '%s',
			'last_error_code'     => '%s',
			'created_at'          => '%s',
			'updated_at'          => '%s',
		];
	}

	public function get_column_defaults() {
		$now = gmdate( 'Y-m-d H:i:s' );
		return [
			'ID' => 0,
			'idempotency_key' => '',
			'payload' => '',
			'status' => 'processing',
			'attempts' => 0,
			'available_at' => $now,
			'claim_token' => '',
			'claimed_at' => '1970-01-01 00:00:00',
			'provider_message_id' => '',
			'last_error_code' => '',
			'created_at' => $now,
			'updated_at' => $now,
		];
	}

	public function create_table_sql_command() {
		return "CREATE TABLE {$this->table_name} (
			ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			idempotency_key char(64) NOT NULL,
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'processing',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			available_at datetime NOT NULL,
			claim_token char(36) NOT NULL DEFAULT '',
			claimed_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			provider_message_id varchar(64) NOT NULL DEFAULT '',
			last_error_code varchar(100) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (ID),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY due_jobs (status,available_at),
			KEY claim_lookup (claim_token),
			KEY claimed_at (status,claimed_at)
		) {$this->get_charset_collate()};";
	}
}
