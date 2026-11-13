<?php

use Groundhogg\Abilities\Db\Query_Table;
use Groundhogg\Pii;

class DotFlow_Read_Only_Table_Query_Tests extends GH_UnitTestCase {

	protected function ability() {
		$reflection = new ReflectionClass( Query_Table::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	public function test_raw_table_query_is_read_only_and_restricted_to_crm_tables() {

		$reflection = new ReflectionClass( Query_Table::class );
		$this->assertTrue( $reflection->getReflectionConstant( 'READONLY' )->getValue() );
		$this->assertContains( 'contacts', Query_Table::ALLOWED_TABLES );
		$this->assertContains( 'contactmeta', Query_Table::ALLOWED_TABLES );
		$this->assertNotContains( 'options', Query_Table::ALLOWED_TABLES );
		$this->assertNotContains( 'users', Query_Table::ALLOWED_TABLES );
		$this->assertNotContains( 'permissions_keys', Query_Table::ALLOWED_TABLES );
	}

	public function test_raw_table_query_rejects_non_crm_tables() {

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$result = $this->ability()->__invoke( [ 'table' => 'options' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'groundhogg_table_not_allowed', $result->get_error_code() );
	}

	public function test_table_query_errors_do_not_disclose_database_exception_details() {

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$result = $this->ability()->__invoke( [
			'table' => 'tags',
			'where' => [
				[
					'column'  => 'tag_name',
					'compare' => 'DOTFLOW_INVALID_OPERATOR',
					'value'   => 'test',
				],
			],
		] );

		$this->assertWPError( $result );
		$this->assertSame( 'groundhogg_invalid_query', $result->get_error_code() );
		$this->assertStringNotContainsString( 'DOTFLOW_INVALID_OPERATOR', $result->get_error_message() );
		$this->assertSame(
			'The requested table query could not be completed. Check the table, filters and column definitions.',
			$result->get_error_message()
		);
	}

	public function test_raw_table_query_is_blocked_for_users_restricted_from_pii() {

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_userdata( $user_id );
		$user->add_cap( Pii::CAP, false );
		wp_set_current_user( $user_id );

		$result = $this->ability()->__invoke( [ 'table' => 'tags' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'groundhogg_pii_restricted', $result->get_error_code() );
	}
}
