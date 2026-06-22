<?php
/**
 * Tests for the current ConnectLibrary plugin bootstrap skeleton.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use ConnectLibrary\Activator;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Deactivator;
use ConnectLibrary\Plugin;
use ConnectLibrary\Support\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the plugin skeleton can bootstrap in a test process.
 */
final class PluginBootstrapTest extends TestCase {
	/**
	 * Reset mutable WordPress stubs between tests.
	 */
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_options']   = array();
		$GLOBALS['connectlibrary_test_dbdelta']   = array();
		$GLOBALS['connectlibrary_test_db_tables'] = array();
	}

	public function test_plugin_constants_are_defined(): void {
		self::assertSame( '0.1.0', CONNECTLIBRARY_VERSION );
		self::assertSame( 'connectlibrary', CONNECTLIBRARY_TEXT_DOMAIN );
		self::assertFileExists( CONNECTLIBRARY_PLUGIN_FILE );
		self::assertDirectoryExists( CONNECTLIBRARY_PLUGIN_DIR );
	}

	public function test_plugin_singleton_registers_init_hook(): void {
		Plugin::instance()->register();

		self::assertArrayHasKey( 'init', $GLOBALS['connectlibrary_test_hooks'] );
		self::assertNotEmpty( $GLOBALS['connectlibrary_test_hooks']['init'] );
	}

	public function test_activation_stores_current_plugin_and_schema_versions(): void {
		Activator::activate();

		self::assertSame(
			array(
				'value'    => CONNECTLIBRARY_VERSION,
				'autoload' => false,
			),
			$GLOBALS['connectlibrary_test_options']['connectlibrary_version']
		);
		self::assertSame(
			array(
				'value'    => Schema::VERSION,
				'autoload' => false,
			),
			$GLOBALS['connectlibrary_test_options'][ Schema::OPTION_NAME ]
		);
	}

	public function test_schema_creates_required_catalog_and_borrower_tables(): void {
		Schema::migrate();

		$expected_tables = array_values( Schema::table_names( 'wp_test_' ) );

		self::assertSameSize( $expected_tables, $GLOBALS['connectlibrary_test_dbdelta'] );
		foreach ( $expected_tables as $table_name ) {
			self::assertArrayHasKey( $table_name, $GLOBALS['connectlibrary_test_db_tables'] );
		}
	}

	public function test_schema_sql_contains_expected_catalog_columns_and_indexes(): void {
		$sql = Schema::sql_definitions( Schema::table_names( 'wp_test_' ), 'DEFAULT CHARSET=utf8mb4' );

		self::assertStringContainsString( 'display_name varchar(255) NOT NULL', $sql['authors'] );
		self::assertStringContainsString( 'PRIMARY KEY  (book_post_id,author_id,role)', $sql['book_authors'] );
		self::assertStringContainsString( 'series_position varchar(64)', $sql['book_series'] );
		self::assertStringContainsString( 'barcode varchar(100)', $sql['copies'] );
		self::assertStringContainsString( 'UNIQUE KEY barcode (barcode)', $sql['copies'] );
		self::assertStringContainsString( 'private_notes longtext', $sql['copies'] );
		self::assertStringContainsString( 'raw_provider_payload longtext', $sql['book_metadata'] );
		self::assertStringContainsString( 'provider_record_id varchar(191)', $sql['import_sources'] );

		self::assertStringContainsString( 'borrower_type varchar(20) NOT NULL', $sql['borrowers'] );
		self::assertStringContainsString( 'wp_user_id bigint(20) unsigned DEFAULT NULL', $sql['borrowers'] );
		self::assertStringContainsString( 'guardian_borrower_id bigint(20) unsigned DEFAULT NULL', $sql['borrowers'] );
		self::assertStringContainsString( 'email_notices_allowed tinyint(1) NOT NULL', $sql['borrowers'] );
		self::assertStringContainsString( 'email varchar(191) DEFAULT NULL', $sql['borrowers'] );
		self::assertStringContainsString( 'KEY status (status)', $sql['borrowers'] );
		self::assertStringContainsString( 'anonymized_at datetime DEFAULT NULL', $sql['borrowers'] );

		self::assertStringContainsString( 'borrower_id bigint(20) unsigned NOT NULL', $sql['borrower_audit'] );
		self::assertStringContainsString( 'action varchar(50) NOT NULL', $sql['borrower_audit'] );
		self::assertStringContainsString( 'changed_fields longtext DEFAULT NULL', $sql['borrower_audit'] );
		self::assertStringContainsString( 'KEY borrower_id (borrower_id)', $sql['borrower_audit'] );

		self::assertStringContainsString( 'borrower_id bigint(20) unsigned NOT NULL', $sql['guest_access_tokens'] );
		self::assertStringContainsString( 'token_hash varchar(128) NOT NULL', $sql['guest_access_tokens'] );
		self::assertStringContainsString( 'UNIQUE KEY token_hash (token_hash)', $sql['guest_access_tokens'] );
		self::assertStringContainsString( 'expires_at datetime NOT NULL', $sql['guest_access_tokens'] );
	}

	public function test_schema_migration_is_idempotent_and_preserves_existing_test_rows(): void {
		$GLOBALS['connectlibrary_test_db_tables']['wp_test_connectlibrary_authors:row:1'] = array(
			'display_name' => 'Test Author',
		);

		Schema::migrate();
		Schema::migrate();

		self::assertSame(
			array( 'display_name' => 'Test Author' ),
			$GLOBALS['connectlibrary_test_db_tables']['wp_test_connectlibrary_authors:row:1']
		);
		self::assertCount( count( Schema::table_names( 'wp_test_' ) ) * 2, $GLOBALS['connectlibrary_test_dbdelta'] );
	}

	public function test_deactivation_does_not_drop_schema_tables_or_options(): void {
		Schema::migrate();
		$tables_before  = $GLOBALS['connectlibrary_test_db_tables'];
		$options_before = $GLOBALS['connectlibrary_test_options'];

		Deactivator::deactivate();

		self::assertSame( $tables_before, $GLOBALS['connectlibrary_test_db_tables'] );
		self::assertSame( $options_before, $GLOBALS['connectlibrary_test_options'] );
	}

	public function test_status_values_are_centralized(): void {
		self::assertSame( array( 'active', 'damaged', 'lost', 'retired' ), Statuses::item_statuses() );
		self::assertSame( array( 'new', 'good', 'fair', 'poor' ), Statuses::condition_statuses() );
		self::assertSame( array( 'manual', 'google_books', 'open_library', 'unknown' ), Statuses::metadata_sources() );
	}
}
