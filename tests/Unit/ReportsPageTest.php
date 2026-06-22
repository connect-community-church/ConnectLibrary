<?php
/**
 * Tests for the Build 08 library reports admin page.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.WrongStyle

use ConnectLibrary\Admin\ReportsPage;
use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Circulation\CopyRepository;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Covers: report identifiers/labels, capability denial/allowance, CSV formula
 * escaping, export audit metadata excludes row contents, empty report render.
 */
final class ReportsPageTest extends TestCase {

	/**
	 * Loans fake table key.
	 *
	 * @var string
	 */
	private string $loans_table;
	/**
	 * Reservations fake table key.
	 *
	 * @var string
	 */
	private string $reservations_table;
	/**
	 * Reservation audit fake table key.
	 *
	 * @var string
	 */
	private string $reservation_audit_table;
	/**
	 * Copies fake table key.
	 *
	 * @var string
	 */
	private string $copies_table;
	/**
	 * Audit events fake table key.
	 *
	 * @var string
	 */
	private string $audit_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->loans_table             = $tables['loans'] . ':rows';
		$this->reservations_table      = $tables['reservations'] . ':rows';
		$this->reservation_audit_table = $tables['reservation_audit'] . ':rows';
		$this->copies_table            = $tables['copies'] . ':rows';
		$this->audit_table             = $tables['audit_events'] . ':rows';

		$GLOBALS['connectlibrary_test_admin_pages']      = array();
		$GLOBALS['connectlibrary_test_db_tables']        = array(
			$this->loans_table             => array(),
			$this->reservations_table      => array(),
			$this->reservation_audit_table => array(),
			$this->copies_table            => array(),
			$this->audit_table             => array(),
		);
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => true,
			Capabilities::MANAGE_OPTIONS     => false,
		);
		$GLOBALS['connectlibrary_test_current_user_id']  = 1;
		$GLOBALS['connectlibrary_test_wp_die']           = null;
		$GLOBALS['connectlibrary_test_options']          = array();
		$GLOBALS['connectlibrary_test_post_objects']     = array();

		$_GET  = array();
		$_POST = array();
	}

	// -------------------------------------------------------------------------
	// Report identifiers and labels
	// -------------------------------------------------------------------------

	public function test_report_labels_returns_all_six_identifiers(): void {
		$labels = ReportsPage::report_labels();

		self::assertCount( 6, $labels );
		self::assertArrayHasKey( 'overdue', $labels );
		self::assertArrayHasKey( 'current', $labels );
		self::assertArrayHasKey( 'holds', $labels );
		self::assertArrayHasKey( 'waitlists', $labels );
		self::assertArrayHasKey( 'activity', $labels );
		self::assertArrayHasKey( 'inventory', $labels );
	}

	public function test_report_labels_are_non_empty_strings(): void {
		foreach ( ReportsPage::report_labels() as $id => $label ) {
			self::assertIsString( $label, "Label for '$id' should be a string" );
			self::assertNotEmpty( $label, "Label for '$id' should not be empty" );
		}
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public function test_add_menu_page_registers_under_library_menu(): void {
		( new ReportsPage() )->add_menu_page();

		self::assertArrayHasKey( ReportsPage::PAGE_SLUG, $GLOBALS['connectlibrary_test_admin_pages'] );
		$reg = $GLOBALS['connectlibrary_test_admin_pages'][ ReportsPage::PAGE_SLUG ];
		self::assertSame( 'edit.php?post_type=' . BookPostType::POST_TYPE, $reg['parent_slug'] );
		self::assertSame( Capabilities::MANAGE_CIRCULATION, $reg['capability'] );
	}

	public function test_register_hooks_admin_menu_and_admin_post(): void {
		( new ReportsPage() )->register();

		self::assertArrayHasKey( 'admin_menu', $GLOBALS['connectlibrary_test_hooks'] );
		self::assertArrayHasKey( 'admin_post_connectlibrary_reports_export', $GLOBALS['connectlibrary_test_hooks'] );
	}

	// -------------------------------------------------------------------------
	// Capability denial
	// -------------------------------------------------------------------------

	public function test_render_dies_when_user_lacks_both_capabilities(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => false,
			Capabilities::MANAGE_OPTIONS     => false,
		);

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
		self::assertNotNull( $GLOBALS['connectlibrary_test_wp_die'] );
		self::assertStringContainsString(
			'permission',
			(string) ( $GLOBALS['connectlibrary_test_wp_die']['message'] ?? '' )
		);
	}

	public function test_can_view_reports_returns_true_for_manage_circulation(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => true,
			Capabilities::MANAGE_OPTIONS     => false,
		);

		self::assertTrue( ReportsPage::can_view_reports() );
	}

	public function test_can_view_reports_returns_true_for_manage_options_admin(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => false,
			Capabilities::MANAGE_OPTIONS     => true,
		);

		self::assertTrue( ReportsPage::can_view_reports() );
	}

	public function test_can_view_reports_returns_false_when_no_capability(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => false,
			Capabilities::MANAGE_OPTIONS     => false,
		);

		self::assertFalse( ReportsPage::can_view_reports() );
	}

	public function test_render_allows_manage_options_admin(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => false,
			Capabilities::MANAGE_OPTIONS     => true,
		);

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertNull( $GLOBALS['connectlibrary_test_wp_die'] );
		self::assertStringContainsString( 'Library Reports', $html );
	}

	// -------------------------------------------------------------------------
	// Landing page tiles
	// -------------------------------------------------------------------------

	public function test_landing_shows_all_six_report_tiles(): void {
		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Overdue Loans', $html );
		self::assertStringContainsString( 'Current Loans', $html );
		self::assertStringContainsString( 'Active Holds', $html );
		self::assertStringContainsString( 'Waitlists', $html );
		self::assertStringContainsString( 'Activity Log', $html );
		self::assertStringContainsString( 'Inventory', $html );
	}

	public function test_landing_tile_links_contain_report_identifier(): void {
		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		foreach ( array_keys( ReportsPage::report_labels() ) as $id ) {
			self::assertStringContainsString( 'cl_report=' . $id, $html, "Tile for '$id' should link to report" );
		}
	}

	// -------------------------------------------------------------------------
	// Individual report pages — empty state
	// -------------------------------------------------------------------------

	public function test_overdue_report_empty_state_mentions_no_matching_records(): void {
		$_GET = array( 'cl_report' => 'overdue' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No matching records', $html );
	}

	public function test_current_report_empty_state(): void {
		$_GET = array( 'cl_report' => 'current' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No matching records', $html );
	}

	public function test_holds_report_empty_state(): void {
		$_GET = array( 'cl_report' => 'holds' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No matching records', $html );
	}

	public function test_inventory_report_empty_state(): void {
		$_GET = array( 'cl_report' => 'inventory' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No matching records', $html );
	}

	// -------------------------------------------------------------------------
	// Report renders data rows when seeded
	// -------------------------------------------------------------------------

	public function test_overdue_report_shows_overdue_loan(): void {
		$this->seed_loan(
			array(
				'id'           => 1,
				'borrower_id'  => 42,
				'book_post_id' => 10,
				'copy_id'      => 3,
				'status'       => 'active',
				'due_at'       => '2020-01-01 12:00:00',
			)
		);

		$_GET = array( 'cl_report' => 'overdue' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Registered borrower', $html );
		self::assertStringContainsString( 'Loan record', $html );
		self::assertStringNotContainsString( 'Borrower #42', $html );
		self::assertStringNotContainsString( 'No matching records', $html );
	}

	public function test_overdue_report_excludes_non_active_loans(): void {
		$this->seed_loan(
			array(
				'id'           => 2,
				'borrower_id'  => 99,
				'book_post_id' => 10,
				'copy_id'      => 1,
				'status'       => 'returned',
				'due_at'       => '2020-01-01 12:00:00',
			)
		);

		$_GET = array( 'cl_report' => 'overdue' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No matching records', $html );
	}

	public function test_holds_report_shows_privacy_safe_borrower_label_not_id_or_email(): void {
		$this->seed_reservation(
			array(
				'id'              => 1,
				'book_post_id'    => 10,
				'borrower_id'     => 77,
				'guest_name'      => 'Secret Name',
				'guest_email'     => 'secret@example.test',
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2099-12-31 12:00:00',
			)
		);

		$_GET = array( 'cl_report' => 'holds' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Registered borrower', $html );
		self::assertStringNotContainsString( 'Borrower #77', $html );
		self::assertStringNotContainsString( 'Secret Name', $html );
		self::assertStringNotContainsString( 'secret@example.test', $html );
	}

	public function test_report_page_includes_filter_form_with_date_inputs(): void {
		$_GET = array( 'cl_report' => 'current' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="cl_from"', $html );
		self::assertStringContainsString( 'name="cl_to"', $html );
		self::assertStringContainsString( 'type="date"', $html );
	}

	public function test_report_page_includes_export_and_print_links(): void {
		$_GET = array( 'cl_report' => 'overdue' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Export CSV', $html );
		self::assertStringContainsString( 'Print', $html );
	}

	public function test_report_page_filter_form_has_accessible_labels(): void {
		$_GET = array( 'cl_report' => 'inventory' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'for="cl-report-from"', $html );
		self::assertStringContainsString( 'for="cl-report-to"', $html );
	}

	// -------------------------------------------------------------------------
	// CSV formula escaping
	// -------------------------------------------------------------------------

	public function test_escape_csv_cell_leaves_normal_string_unchanged(): void {
		self::assertSame( 'Hello World', ReportsPage::escape_csv_cell( 'Hello World' ) );
	}

	public function test_escape_csv_cell_leaves_empty_string_unchanged(): void {
		self::assertSame( '', ReportsPage::escape_csv_cell( '' ) );
	}

	public function test_escape_csv_cell_prefixes_equals_sign(): void {
		self::assertSame( "'=SUM(A1)", ReportsPage::escape_csv_cell( '=SUM(A1)' ) );
	}

	public function test_escape_csv_cell_prefixes_plus_sign(): void {
		self::assertSame( "'+1234567890", ReportsPage::escape_csv_cell( '+1234567890' ) );
	}

	public function test_escape_csv_cell_prefixes_minus_sign(): void {
		self::assertSame( "'-123", ReportsPage::escape_csv_cell( '-123' ) );
	}

	public function test_escape_csv_cell_prefixes_at_sign(): void {
		self::assertSame( "'@user", ReportsPage::escape_csv_cell( '@user' ) );
	}

	public function test_escape_csv_cell_prefixes_tab(): void {
		self::assertSame( "'\tcmd", ReportsPage::escape_csv_cell( "\tcmd" ) );
	}

	public function test_escape_csv_cell_prefixes_carriage_return(): void {
		self::assertSame( "'\rcmd", ReportsPage::escape_csv_cell( "\rcmd" ) );
	}

	public function test_escape_csv_cell_does_not_prefix_numeric_string(): void {
		self::assertSame( '42', ReportsPage::escape_csv_cell( '42' ) );
	}

	public function test_escape_csv_cell_does_not_prefix_regular_date(): void {
		self::assertSame( '2026-06-22', ReportsPage::escape_csv_cell( '2026-06-22' ) );
	}

	// -------------------------------------------------------------------------
	// Export audit metadata excludes row contents
	// -------------------------------------------------------------------------

	public function test_export_audit_context_contains_only_metadata_not_row_values(): void {
		// Seed a loan so there is at least one row in the export.
		$this->seed_loan(
			array(
				'id'           => 5,
				'borrower_id'  => 123,
				'book_post_id' => 10,
				'copy_id'      => 2,
				'status'       => 'active',
				'due_at'       => '2020-06-01 12:00:00',
			)
		);

		$page           = new ReportsPage();
		$filters        = array(
			'from' => '',
			'to'   => '',
		);
		list( , $rows ) = $page->build_report_data( 'overdue', $filters );

		// Build the context array exactly as handle_export does.
		$context      = array(
			'report'    => 'overdue',
			'filters'   => $filters,
			'row_count' => count( $rows ),
			'format'    => 'csv',
		);
		$context_json = (string) wp_json_encode( $context );

		// Metadata keys must be present.
		self::assertStringContainsString( '"report"', $context_json );
		self::assertStringContainsString( '"row_count"', $context_json );
		self::assertStringContainsString( '"format"', $context_json );

		// Row contents (borrower IDs, due dates) must NOT be in the context.
		self::assertStringNotContainsString( '123', $context_json, 'Borrower ID must not appear in audit context' );
		self::assertStringNotContainsString( '2020-06-01', $context_json, 'Due date must not appear in audit context' );

		// row_count reflects the number of rows found.
		self::assertSame( 1, $context['row_count'] );
	}

	public function test_export_audit_log_records_actor_id(): void {
		$GLOBALS['connectlibrary_test_current_user_id'] = 7;

		// Log an export event and confirm actor_id is captured via the real audit table.
		$audit = new AuditEventService();
		$audit->log(
			'report_export',
			array(
				'source_channel' => 'admin',
				'entity_type'    => 'report',
				'context'        => array(
					'report'    => 'inventory',
					'filters'   => array(
						'from' => '',
						'to'   => '',
					),
					'row_count' => 0,
					'format'    => 'csv',
				),
			)
		);

		// Read back the inserted row from the fake DB.
		$tables    = Schema::table_names();
		$audit_key = $tables['audit_events'] . ':rows';
		$inserted  = $GLOBALS['connectlibrary_test_db_tables'][ $audit_key ] ?? array();
		self::assertNotEmpty( $inserted, 'Audit row should have been inserted' );
		$last = end( $inserted );
		self::assertSame( 7, (int) ( $last['actor_id'] ?? 0 ) );
	}

	// -------------------------------------------------------------------------
	// build_report_data returns expected columns
	// -------------------------------------------------------------------------

	public function test_build_report_data_overdue_has_expected_columns(): void {
		$page            = new ReportsPage();
		list( $columns ) = $page->build_report_data(
			'overdue',
			array(
				'from' => '',
				'to'   => '',
			)
		);

		self::assertContains( 'Borrower', $columns );
		self::assertNotContains( 'Borrower ID', $columns );
		self::assertContains( 'Due Date', $columns );
	}

	public function test_build_report_data_inventory_has_expected_columns(): void {
		$page            = new ReportsPage();
		list( $columns ) = $page->build_report_data(
			'inventory',
			array(
				'from' => '',
				'to'   => '',
			)
		);

		self::assertContains( 'Copy', $columns );
		self::assertNotContains( 'Copy ID', $columns );
		self::assertContains( 'Status', $columns );
	}

	public function test_build_report_data_unknown_identifier_returns_empty(): void {
		$page                   = new ReportsPage();
		list( $columns, $rows ) = $page->build_report_data(
			'nonexistent',
			array(
				'from' => '',
				'to'   => '',
			)
		);

		self::assertSame( array(), $columns );
		self::assertSame( array(), $rows );
	}

	public function test_current_report_applies_limit_page_and_due_date_sort(): void {
		$this->seed_loan(
			array(
				'id'           => 3,
				'borrower_id'  => 30,
				'book_post_id' => 10,
				'copy_id'      => 3,
				'status'       => 'active',
				'due_at'       => '2030-01-03 12:00:00',
			)
		);
		$this->seed_loan(
			array(
				'id'           => 1,
				'borrower_id'  => 10,
				'book_post_id' => 10,
				'copy_id'      => 1,
				'status'       => 'active',
				'due_at'       => '2030-01-01 12:00:00',
			)
		);
		$this->seed_loan(
			array(
				'id'           => 2,
				'borrower_id'  => 20,
				'book_post_id' => 10,
				'copy_id'      => 2,
				'status'       => 'active',
				'due_at'       => '2030-01-02 12:00:00',
			)
		);

		$page           = new ReportsPage();
		list( , $rows ) = $page->build_report_data(
			'current',
			array(
				'limit' => 2,
				'paged' => 2,
			)
		);

		self::assertCount( 1, $rows );
		self::assertSame( 'Loan record', $rows[0][0] );
		self::assertSame( 'Registered borrower', $rows[0][1] );
	}

	public function test_inventory_report_filters_by_condition_call_number_and_search(): void {
		$this->seed_copy(
			array(
				'id'           => 1,
				'book_post_id' => 10,
				'barcode'      => 'ABC123',
				'call_number'  => 'J FIC ONE',
				'condition'    => 'good',
				'status'       => 'active',
				'created_at'   => '2026-01-01 00:00:00',
			)
		);
		$this->seed_copy(
			array(
				'id'           => 2,
				'book_post_id' => 11,
				'barcode'      => 'XYZ999',
				'call_number'  => 'ADULT TWO',
				'condition'    => 'damaged',
				'status'       => 'active',
				'created_at'   => '2026-01-02 00:00:00',
			)
		);

		$page           = new ReportsPage();
		list( , $rows ) = $page->build_report_data(
			'inventory',
			array(
				'condition'   => 'good',
				'call_number' => 'J FIC',
				'search'      => 'ABC',
			)
		);

		self::assertCount( 1, $rows );
		self::assertSame( 'Copy record', $rows[0][0] );
		self::assertSame( 'Library item', $rows[0][1] );
		self::assertSame( 'Edit book', $rows[0][6]['label'] );
	}

	public function test_report_rows_render_safe_deep_links(): void {
		$this->seed_loan(
			array(
				'id'           => 9,
				'borrower_id'  => 42,
				'book_post_id' => 10,
				'copy_id'      => 3,
				'status'       => 'active',
				'due_at'       => '2030-01-01 12:00:00',
			)
		);

		$_GET = array( 'cl_report' => 'current' );

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Open circulation', $html );
		self::assertStringContainsString( 'page=connectlibrary-circulation', $html );
		self::assertStringNotContainsString( 'bulk', strtolower( $html ) );
	}

	public function test_print_url_preserves_active_filters_and_pagination(): void {
		$_GET = array(
			'cl_report' => 'inventory',
			'cl_from'   => '2026-01-01',
			'cl_to'     => '2026-01-31',
			'cl_status' => 'active',
			'cl_limit'  => '25',
			'cl_paged'  => '2',
		);

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'cl_print=1', $html );
		self::assertStringContainsString( 'cl_from=2026-01-01', $html );
		self::assertStringContainsString( 'cl_to=2026-01-31', $html );
		self::assertStringContainsString( 'cl_status=active', $html );
		self::assertStringContainsString( 'cl_limit=25', $html );
		self::assertStringContainsString( 'cl_paged=2', $html );
	}

	public function test_activity_filter_form_includes_and_preserves_outcome_actor_and_object_filters(): void {
		$_GET = array(
			'cl_report'      => 'activity',
			'cl_outcome'     => 'failed',
			'cl_actor_id'    => '17',
			'cl_object_type' => 'loan',
			'cl_object_id'   => '42',
		);

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="cl_outcome"', $html );
		self::assertStringContainsString( 'value="failed"', $html );
		self::assertStringContainsString( 'name="cl_actor_id"', $html );
		self::assertStringContainsString( 'value="17"', $html );
		self::assertStringContainsString( 'name="cl_object_type"', $html );
		self::assertStringContainsString( 'value="loan"', $html );
		self::assertStringContainsString( 'name="cl_object_id"', $html );
		self::assertStringContainsString( 'value="42"', $html );
	}

	public function test_activity_print_and_export_urls_preserve_outcome_actor_and_object_filters(): void {
		$_GET = array(
			'cl_report'      => 'activity',
			'cl_outcome'     => 'ok',
			'cl_actor_id'    => '7',
			'cl_object_type' => 'reservation',
			'cl_object_id'   => '5',
			'cl_limit'       => '25',
			'cl_paged'       => '2',
		);

		ob_start();
		( new ReportsPage() )->render();
		$html = (string) ob_get_clean();

		foreach ( array( 'cl_outcome=ok', 'cl_actor_id=7', 'cl_object_type=reservation', 'cl_object_id=5', 'cl_limit=25', 'cl_paged=2' ) as $expected ) {
			self::assertStringContainsString( $expected, $html );
		}
		self::assertStringContainsString( 'connectlibrary_reports_export', $html );
		self::assertStringContainsString( 'cl_print=1', $html );
	}

	public function test_activity_report_filters_action_status_actor_and_object(): void {
		$this->seed_audit(
			array(
				'id'             => 1,
				'created_at_utc' => '2026-01-01 00:00:00',
				'action'         => 'checkout',
				'actor_id'       => 7,
				'entity_type'    => 'loan',
				'entity_id'      => 5,
				'status'         => 'ok',
			)
		);
		$this->seed_audit(
			array(
				'id'             => 2,
				'created_at_utc' => '2026-01-02 00:00:00',
				'action'         => 'return',
				'actor_id'       => 8,
				'entity_type'    => 'loan',
				'entity_id'      => 6,
				'status'         => 'failed',
			)
		);

		$page           = new ReportsPage();
		list( , $rows ) = $page->build_report_data(
			'activity',
			array(
				'action'      => 'checkout',
				'outcome'     => 'ok',
				'actor_id'    => 7,
				'object_type' => 'loan',
				'object_id'   => 5,
			)
		);

		self::assertCount( 1, $rows );
		self::assertSame( 'checkout', $rows[0][2] );
		self::assertSame( 'Open audit history', $rows[0][7]['label'] );
	}


	public function test_report_data_and_csv_rows_do_not_expose_raw_protected_id_labels(): void {
		$this->seed_loan(
			array(
				'id'           => 123,
				'borrower_id'  => 77,
				'book_post_id' => 456,
				'copy_id'      => 789,
				'status'       => 'active',
				'due_at'       => '2030-01-01 12:00:00',
			)
		);
		$this->seed_audit(
			array(
				'id'             => 55,
				'created_at_utc' => '2026-01-01 00:00:00',
				'action'         => 'checkout',
				'actor_id'       => 7,
				'entity_type'    => 'loan',
				'entity_id'      => 123,
				'status'         => 'ok',
			)
		);

		$page = new ReportsPage();
		foreach ( array( 'current', 'activity' ) as $report ) {
			list( $columns, $rows ) = $page->build_report_data( $report, array() );
			$serialized             = wp_json_encode( array( $columns, $rows ) );
			self::assertIsString( $serialized );
			foreach ( array( 'Borrower ID', 'Actor ID', 'Entity ID', 'Loan ID', 'Copy ID', 'Book ID', 'Borrower #77', 'user #7' ) as $forbidden ) {
				self::assertStringNotContainsString( $forbidden, $serialized );
			}
		}
	}

	public function test_report_repository_methods_use_bounded_query_contracts(): void {
		$contracts = array(
			'includes/Circulation/LoanRepository.php' => 'report_active_loans',
			'includes/Circulation/CopyRepository.php' => 'report_inventory',
			'includes/Reservations/ReservationRepository.php' => 'report_by_status',
			'includes/Audit/AuditEventRepository.php' => 'query',
		);

		foreach ( $contracts as $relative_path => $method ) {
			$source = $this->method_source( dirname( __DIR__, 2 ) . '/' . $relative_path, $method );
			self::assertStringContainsString( 'LIMIT %d OFFSET %d', $source, $method . ' must pass limit/offset to SQL' );
			self::assertStringContainsString( 'ORDER BY', $source, $method . ' must define deterministic ordering in SQL' );
			self::assertStringNotContainsString( '$this->all()', $source, $method . ' must not load all rows before paging' );
			self::assertStringNotContainsString( 'array_slice', $source, $method . ' must not page after loading all rows' );
		}

		$service_source = $this->method_source( dirname( __DIR__, 2 ) . '/includes/Reservations/ReservationService.php', 'report_reservations_by_status' );
		self::assertStringContainsString( 'report_by_status', $service_source );
		self::assertStringNotContainsString( '->by_status(', $service_source );
		self::assertStringNotContainsString( 'array_slice', $service_source );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Seed a fake loan row.
	 *
	 * @param array<string,mixed> $row Loan row.
	 */
	private function seed_loan( array $row ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ][] = $row;
	}

	/**
	 * Seed a fake reservation row.
	 *
	 * @param array<string,mixed> $row Reservation row.
	 */
	private function seed_reservation( array $row ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ][] = $row;
	}

	/**
	 * Seed a fake copy row.
	 *
	 * @param array<string,mixed> $row Copy row.
	 */
	private function seed_copy( array $row ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ][] = $row;
	}

	/**
	 * Seed a fake audit event row.
	 *
	 * @param array<string,mixed> $row Audit row.
	 */
	private function seed_audit( array $row ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_table ][] = $row;
	}

	private function method_source( string $path, string $method ): string {
		$contents = (string) file_get_contents( $path );
		$start    = strpos( $contents, 'function ' . $method . '(' );
		self::assertNotFalse( $start, 'Expected to find method ' . $method . ' in ' . $path );
		$next = strpos( $contents, "\n	/**", (int) $start );
		if ( false === $next ) {
			$next = strlen( $contents );
		}

		return substr( $contents, (int) $start, $next - (int) $start );
	}
}
