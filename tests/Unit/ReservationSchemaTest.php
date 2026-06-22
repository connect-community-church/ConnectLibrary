<?php
/**
 * Tests for reservation table schema definitions.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that Schema adds reservation and reservation_audit tables
 * with the expected columns and that the schema version is bumped.
 */
final class ReservationSchemaTest extends TestCase {

	private string $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

	public function test_schema_version_is_current_waitlist_schema(): void {
		self::assertSame( '1.6.1', Schema::VERSION );
	}

	public function test_table_names_includes_reservations_and_audit(): void {
		$tables = Schema::table_names( 'wp_' );

		self::assertArrayHasKey( 'reservations', $tables );
		self::assertArrayHasKey( 'reservation_audit', $tables );
		self::assertSame( 'wp_connectlibrary_reservations', $tables['reservations'] );
		self::assertSame( 'wp_connectlibrary_reservation_audit', $tables['reservation_audit'] );
	}

	public function test_table_names_still_includes_all_prior_tables(): void {
		$tables = Schema::table_names( 'wp_' );

		$expected_keys = array(
			'authors',
			'book_authors',
			'series',
			'book_series',
			'copies',
			'book_metadata',
			'import_sources',
			'borrowers',
			'borrower_audit',
			'guest_access_tokens',
		);

		foreach ( $expected_keys as $key ) {
			self::assertArrayHasKey( $key, $tables, "table_names() must still include '{$key}'." );
		}
	}

	public function test_sql_definitions_includes_reservations_and_audit_keys(): void {
		$tables = Schema::table_names( 'wp_' );
		$defs   = Schema::sql_definitions( $tables, $this->charset );

		self::assertArrayHasKey( 'reservations', $defs );
		self::assertArrayHasKey( 'reservation_audit', $defs );
	}

	public function test_reservations_sql_contains_required_columns(): void {
		$tables = Schema::table_names( 'wp_' );
		$sql    = Schema::sql_definitions( $tables, $this->charset )['reservations'];

		$required_columns = array(
			'id',
			'book_post_id',
			'copy_id',
			'borrower_id',
			'guest_name',
			'guest_email',
			'status',
			'hold_expires_at',
			'requested_at',
			'created_at',
			'updated_at',
			'acted_by',
			'notes',
			'context',
		);

		foreach ( $required_columns as $col ) {
			self::assertStringContainsString( $col, $sql, "reservations SQL must define column '{$col}'." );
		}
	}

	public function test_reservations_sql_creates_correct_table(): void {
		$tables = Schema::table_names( 'wp_' );
		$sql    = Schema::sql_definitions( $tables, $this->charset )['reservations'];

		self::assertStringContainsString( 'CREATE TABLE wp_connectlibrary_reservations', $sql );
		self::assertStringContainsString( 'PRIMARY KEY', $sql );
		self::assertStringContainsString( 'book_post_id', $sql );
		self::assertStringContainsString( "DEFAULT 'pending_approval'", $sql );
	}

	public function test_reservations_sql_indexes_status_and_expiry(): void {
		$tables = Schema::table_names( 'wp_' );
		$sql    = Schema::sql_definitions( $tables, $this->charset )['reservations'];

		self::assertStringContainsString( 'KEY status', $sql );
		self::assertStringContainsString( 'KEY hold_expires_at', $sql );
		self::assertStringContainsString( 'KEY borrower_id', $sql );
		self::assertStringContainsString( 'KEY guest_email', $sql );
	}

	public function test_reservation_audit_sql_contains_required_columns(): void {
		$tables = Schema::table_names( 'wp_' );
		$sql    = Schema::sql_definitions( $tables, $this->charset )['reservation_audit'];

		$required_columns = array(
			'id',
			'reservation_id',
			'actor_user_id',
			'action',
			'from_status',
			'to_status',
			'changed_fields',
			'reason',
			'created_at',
		);

		foreach ( $required_columns as $col ) {
			self::assertStringContainsString( $col, $sql, "reservation_audit SQL must define column '{$col}'." );
		}
	}

	public function test_reservation_audit_sql_creates_correct_table(): void {
		$tables = Schema::table_names( 'wp_' );
		$sql    = Schema::sql_definitions( $tables, $this->charset )['reservation_audit'];

		self::assertStringContainsString( 'CREATE TABLE wp_connectlibrary_reservation_audit', $sql );
		self::assertStringContainsString( 'PRIMARY KEY', $sql );
		self::assertStringContainsString( 'KEY reservation_id', $sql );
	}

	public function test_migrate_calls_dbdelta_for_reservation_tables(): void {
		$GLOBALS['connectlibrary_test_options']  = array();
		$GLOBALS['connectlibrary_test_dbdelta']  = array();
		$GLOBALS['connectlibrary_test_db_tables'] = array();

		Schema::migrate();

		$called_sql = implode( ' ', $GLOBALS['connectlibrary_test_dbdelta'] );

		self::assertStringContainsString( 'connectlibrary_reservations', $called_sql );
		self::assertStringContainsString( 'connectlibrary_reservation_audit', $called_sql );
	}

	public function test_migrate_saves_bumped_schema_version(): void {
		$GLOBALS['connectlibrary_test_options']  = array();
		$GLOBALS['connectlibrary_test_dbdelta']  = array();
		$GLOBALS['connectlibrary_test_db_tables'] = array();

		Schema::migrate();

		self::assertSame( '1.6.1', $GLOBALS['connectlibrary_test_options'][ Schema::OPTION_NAME ]['value'] );
	}
}
