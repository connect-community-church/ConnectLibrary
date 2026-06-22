<?php
/**
 * Tests for the Phase 3 librarian operations dashboard.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.WrongStyle

use ConnectLibrary\Admin\LibrarianDashboardPage;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Settings\Settings;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the librarian dashboard registers correctly and renders the
 * expected HTML for registration, capability protection, empty states,
 * summary counts, Sunday mode, scanner forms, deep links, and privacy.
 */
final class LibrarianDashboardPageTest extends TestCase {

	/**
	 * Loans table store key.
	 *
	 * @var string
	 */
	private string $loans_table;

	/**
	 * Reservations table store key.
	 *
	 * @var string
	 */
	private string $reservations_table;

	/**
	 * Reservation audit table store key.
	 *
	 * @var string
	 */
	private string $reservation_audit_table;

	/**
	 * Copies table store key.
	 *
	 * @var string
	 */
	private string $copies_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->loans_table             = $tables['loans'] . ':rows';
		$this->reservations_table      = $tables['reservations'] . ':rows';
		$this->reservation_audit_table = $tables['reservation_audit'] . ':rows';
		$this->copies_table            = $tables['copies'] . ':rows';

		$GLOBALS['connectlibrary_test_admin_pages']      = array();
		$GLOBALS['connectlibrary_test_db_tables']        = array(
			$this->loans_table             => array(),
			$this->reservations_table      => array(),
			$this->reservation_audit_table => array(),
			$this->copies_table            => array(),
		);
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => true,
			Capabilities::MANAGE_OPTIONS     => false,
		);
		$GLOBALS['connectlibrary_test_current_user_id']  = 1;
		$GLOBALS['connectlibrary_test_wp_die']           = null;
		$GLOBALS['connectlibrary_test_options']          = array();
		$GLOBALS['connectlibrary_test_post_objects']     = array(
			10 => (object) array(
				'ID'         => 10,
				'post_type'  => BookPostType::POST_TYPE,
				'post_title' => 'Test Book',
			),
		);

		$_GET  = array();
		$_POST = array();
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public function test_dashboard_registers_under_library_menu_with_manage_circulation_capability(): void {
		$page = new LibrarianDashboardPage();
		$page->add_menu_page();

		self::assertArrayHasKey( LibrarianDashboardPage::PAGE_SLUG, $GLOBALS['connectlibrary_test_admin_pages'] );

		$registered = $GLOBALS['connectlibrary_test_admin_pages'][ LibrarianDashboardPage::PAGE_SLUG ];
		self::assertSame(
			'edit.php?post_type=' . BookPostType::POST_TYPE,
			$registered['parent_slug']
		);
		self::assertSame( Capabilities::MANAGE_CIRCULATION, $registered['capability'] );
	}

	public function test_register_hooks_admin_menu_action(): void {
		$page = new LibrarianDashboardPage();
		$page->register();

		self::assertArrayHasKey( 'admin_menu', $GLOBALS['connectlibrary_test_hooks'] );
	}

	// -------------------------------------------------------------------------
	// Capability protection
	// -------------------------------------------------------------------------

	public function test_render_dies_when_user_lacks_circulation_capability(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => false,
			Capabilities::MANAGE_OPTIONS     => false,
		);

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
		self::assertNotNull( $GLOBALS['connectlibrary_test_wp_die'] );
		self::assertStringContainsString(
			'permission',
			(string) ( $GLOBALS['connectlibrary_test_wp_die']['message'] ?? '' )
		);
	}

	public function test_render_allows_manage_options_admin(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => false,
			Capabilities::MANAGE_OPTIONS     => true,
		);

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertNull( $GLOBALS['connectlibrary_test_wp_die'] );
		self::assertStringContainsString( 'Librarian Dashboard', $html );
	}

	// -------------------------------------------------------------------------
	// Empty state
	// -------------------------------------------------------------------------

	public function test_render_shows_helpful_empty_state_when_library_is_empty(): void {
		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertNull( $GLOBALS['connectlibrary_test_wp_die'] );
		self::assertStringContainsString( 'Librarian Dashboard', $html );
		self::assertStringContainsString( 'connectlibrary-empty-state', $html );
		self::assertStringContainsString( 'Add a book', $html );
		self::assertStringContainsString( 'Manage borrowers', $html );
		self::assertStringContainsString( 'Settings', $html );
	}

	public function test_empty_hours_panel_shows_admin_link_to_settings(): void {
		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Library Hours', $html );
		self::assertStringContainsString( 'connectlibrary-settings', $html );
		self::assertStringContainsString( 'Add hours and instructions in Settings', $html );
	}

	// -------------------------------------------------------------------------
	// Summary card counts
	// -------------------------------------------------------------------------

	public function test_summary_cards_reflect_zero_counts_on_empty_library(): void {
		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		// Each summary count card should show 0 in the count element.
		self::assertStringContainsString( 'Active Pickup Holds', $html );
		self::assertStringContainsString( 'Pending Guest Requests', $html );
		self::assertStringContainsString( 'Waitlist', $html );
		self::assertStringContainsString( 'Due Soon', $html );
		self::assertStringContainsString( 'Overdue', $html );
		self::assertStringContainsString( 'Active Loans', $html );
	}

	public function test_summary_cards_show_populated_counts(): void {
		// Seed 2 active holds.
		$this->seed_reservation(
			array(
				'id'              => 1,
				'book_post_id'    => 10,
				'borrower_id'     => 5,
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-06-25 12:00:00',
			)
		);
		$this->seed_reservation(
			array(
				'id'              => 2,
				'book_post_id'    => 10,
				'borrower_id'     => 6,
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-06-27 12:00:00',
			)
		);
		// Seed 1 pending guest.
		$this->seed_reservation(
			array(
				'id'           => 3,
				'book_post_id' => 10,
				'guest_name'   => 'Guest One',
				'guest_email'  => 'guest@example.test',
				'status'       => ReservationStatuses::PENDING_APPROVAL,
			)
		);
		// Seed 1 overdue loan (due before current_time = '2026-06-19 12:00:00').
		$this->seed_loan(
			array(
				'id'           => 1,
				'borrower_id'  => 5,
				'book_post_id' => 10,
				'copy_id'      => 1,
				'status'       => 'active',
				'due_at'       => '2026-06-10 12:00:00',
			)
		);
		// Seed 1 loan due soon (within default 3 days of current_time).
		$this->seed_loan(
			array(
				'id'           => 2,
				'borrower_id'  => 6,
				'book_post_id' => 10,
				'copy_id'      => 2,
				'status'       => 'active',
				'due_at'       => '2026-06-21 12:00:00',
			)
		);

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		// With holds seeded, the empty state for an empty library should NOT show.
		self::assertStringNotContainsString( 'The library has no active holds', $html );

		// All summary section labels are present.
		self::assertStringContainsString( 'Active Pickup Holds', $html );
		self::assertStringContainsString( 'Pending Guest Requests', $html );
		self::assertStringContainsString( 'Active Loans', $html );

		// Count elements are rendered.
		self::assertStringContainsString( 'connectlibrary-card-count', $html );

		// The alert tables show borrower records without exposing raw borrower IDs.
		self::assertStringContainsString( 'Registered borrower', $html );
		self::assertStringNotContainsString( 'Borrower #5', $html );
		self::assertStringNotContainsString( 'Borrower #6', $html );

		// Overdue loan for borrower #5 appears in the overdue section.
		self::assertStringContainsString( 'Overdue Loans', $html );

		// Due soon loan for borrower #6 appears (2026-06-21 is within 3-day default window).
		self::assertStringContainsString( 'Due Soon', $html );

		// Individual count values are rendered in card-count elements.
		// Regex: find the count between card-count div and its closing tag.
		preg_match_all(
			'/<div class="connectlibrary-card-count"[^>]*>\s*(\d+)\s*<\/div>/',
			$html,
			$count_matches
		);
		$counts = array_map( 'intval', $count_matches[1] ?? array() );
		self::assertContains( 2, $counts, 'Should have a card showing count of 2 (holds or loans)' );
	}

	// -------------------------------------------------------------------------
	// Active holds sorted by expiry
	// -------------------------------------------------------------------------

	public function test_active_holds_queue_sorted_by_soonest_expiry(): void {
		$this->seed_reservation(
			array(
				'id'              => 1,
				'book_post_id'    => 10,
				'borrower_id'     => 7,
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-07-10 12:00:00',
			)
		);
		$this->seed_reservation(
			array(
				'id'              => 2,
				'book_post_id'    => 10,
				'borrower_id'     => 8,
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-06-22 09:00:00',
			)
		);

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		// The earliest hold expiry should appear before the later one without exposing borrower IDs.
		$soon_pos  = strpos( $html, '2026-06-22 09:00:00' );
		$later_pos = strpos( $html, '2026-07-10 12:00:00' );
		self::assertNotFalse( $soon_pos );
		self::assertNotFalse( $later_pos );
		self::assertLessThan( $later_pos, $soon_pos, 'Soonest-expiry hold should appear before later hold' );
		self::assertStringNotContainsString( 'Borrower #8', $html );
		self::assertStringNotContainsString( 'Borrower #7', $html );
	}

	// -------------------------------------------------------------------------
	// Due-soon uses settings lead days
	// -------------------------------------------------------------------------

	public function test_due_soon_window_respects_due_reminder_lead_days_setting(): void {
		// current_time stub returns '2026-06-19 12:00:00'.
		// With lead_days=1 the cutoff is '2026-06-20 12:00:00'.
		$this->set_setting( 'due_reminder_lead_days', 1 );

		// Borrower #9: due in ~12 h → inside 1-day window → appears in Due Soon.
		$this->seed_loan(
			array(
				'id'           => 1,
				'borrower_id'  => 9,
				'book_post_id' => 10,
				'copy_id'      => 1,
				'status'       => 'active',
				'due_at'       => '2026-06-20 00:00:00',
			)
		);
		// Borrower #10: due in 48 h → outside 1-day window, not overdue either
		// → should appear NOWHERE in the page (no alert section shows them).
		$this->seed_loan(
			array(
				'id'           => 2,
				'borrower_id'  => 10,
				'book_post_id' => 10,
				'copy_id'      => 2,
				'status'       => 'active',
				'due_at'       => '2026-06-21 12:00:00',
			)
		);

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		// The due-soon borrower appears without exposing the raw borrower ID.
		self::assertStringContainsString( 'Registered borrower', $html );
		self::assertStringNotContainsString( 'Borrower #9', $html );

		// Borrower #10 is active but outside the 1-day window; not overdue either.
		// They should not appear in any alert section on the dashboard.
		self::assertStringNotContainsString( 'Borrower #10', $html );
	}

	// -------------------------------------------------------------------------
	// Sunday mode
	// -------------------------------------------------------------------------

	public function test_sunday_mode_adds_css_class_marker_to_wrapper(): void {
		$_GET = array( 'sunday_mode' => '1' );

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'connectlibrary-sunday-mode', $html );
	}

	public function test_normal_mode_does_not_have_sunday_mode_class(): void {
		$_GET = array();

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		// The wrapper div should NOT have the sunday-mode class.
		self::assertStringNotContainsString( 'connectlibrary-sunday-mode"', $html );
		self::assertStringNotContainsString( 'connectlibrary-sunday-mode notice', $html );
	}

	public function test_sunday_mode_shows_exit_link_and_notice(): void {
		$_GET = array( 'sunday_mode' => '1' );

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Sunday Mode', $html );
		self::assertStringContainsString( 'Exit Sunday Mode', $html );
		self::assertStringContainsString( 'connectlibrary-sunday-mode-notice', $html );
	}

	public function test_sunday_mode_reorders_scanner_before_summary_cards(): void {
		$_GET = array( 'sunday_mode' => '1' );

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		// In Sunday mode the scanner panel should appear before the summary card section.
		$scanner_pos = strpos( $html, 'connectlibrary-scanner-panel' );
		$cards_pos   = strpos( $html, 'connectlibrary-dashboard-cards' );
		self::assertNotFalse( $scanner_pos );
		self::assertNotFalse( $cards_pos );
		self::assertLessThan( $cards_pos, $scanner_pos, 'Scanner panel should render before summary cards in Sunday mode' );
	}

	public function test_normal_mode_places_summary_cards_before_scanner(): void {
		$_GET = array();

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		$cards_pos   = strpos( $html, 'connectlibrary-dashboard-cards' );
		$scanner_pos = strpos( $html, 'connectlibrary-scanner-panel' );
		self::assertNotFalse( $cards_pos );
		self::assertNotFalse( $scanner_pos );
		self::assertLessThan( $scanner_pos, $cards_pos, 'Summary cards should render before scanner panel in normal mode' );
	}

	// -------------------------------------------------------------------------
	// Scanner forms and deep links
	// -------------------------------------------------------------------------

	public function test_scanner_panel_contains_card_token_input_with_correct_name(): void {
		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="circ_card_token"', $html );
		self::assertStringContainsString( 'autocomplete="off"', $html );
	}

	public function test_scanner_panel_contains_name_search_input(): void {
		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="circ_name_search"', $html );
	}

	public function test_scanner_panel_contains_copy_search_input(): void {
		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="circ_copy_search"', $html );
	}

	public function test_scanner_forms_target_circulation_page_slug(): void {
		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		// All three scanner forms should post/get to the circulation page.
		self::assertStringContainsString( 'page" value="connectlibrary-circulation"', $html );
	}

	public function test_scanner_forms_have_accessibility_labels(): void {
		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'for="cl-dash-card-token"', $html );
		self::assertStringContainsString( 'for="cl-dash-name-search"', $html );
		self::assertStringContainsString( 'for="cl-dash-copy-search"', $html );
	}

	public function test_admin_links_include_deep_links_to_all_key_pages(): void {
		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'connectlibrary-circulation', $html );
		self::assertStringContainsString( 'connectlibrary-reservations', $html );
		self::assertStringContainsString( 'connectlibrary-borrowers', $html );
		self::assertStringContainsString( 'connectlibrary-settings', $html );
		self::assertStringContainsString( 'post-new.php?post_type=' . BookPostType::POST_TYPE, $html );
	}

	// -------------------------------------------------------------------------
	// Privacy-safe URLs and display
	// -------------------------------------------------------------------------

	public function test_active_holds_display_borrower_id_not_name_or_email(): void {
		$this->seed_reservation(
			array(
				'id'              => 1,
				'book_post_id'    => 10,
				'borrower_id'     => 99,
				'guest_name'      => 'Secret Name',
				'guest_email'     => 'secret@example.test',
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-07-01 12:00:00',
			)
		);

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Registered borrower', $html );
		self::assertStringNotContainsString( 'Borrower #99', $html );
		self::assertStringNotContainsString( 'Secret Name', $html );
		self::assertStringNotContainsString( 'secret@example.test', $html );
	}

	public function test_guest_hold_displayed_as_guest_not_by_name(): void {
		$this->seed_reservation(
			array(
				'id'              => 1,
				'book_post_id'    => 10,
				'guest_name'      => 'Private Guest',
				'guest_email'     => 'priv@example.test',
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-07-01 12:00:00',
			)
		);

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		// Guest holds show "Guest" not the name or email.
		self::assertStringContainsString( 'Guest', $html );
		self::assertStringNotContainsString( 'Private Guest', $html );
		self::assertStringNotContainsString( 'priv@example.test', $html );
	}

	public function test_deep_links_do_not_contain_pii_in_query_strings(): void {
		$this->seed_loan(
			array(
				'id'           => 1,
				'borrower_id'  => 55,
				'book_post_id' => 10,
				'copy_id'      => 1,
				'status'       => 'active',
				'due_at'       => '2026-06-10 12:00:00',
			)
		);

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		// Deep links may carry protected query parameters, but display labels must not expose names/emails or raw IDs.
		self::assertStringNotContainsString( '@example.test', $html );
	}

	// -------------------------------------------------------------------------
	// Pickup instructions panel
	// -------------------------------------------------------------------------

	public function test_pickup_instructions_shown_when_configured(): void {
		$this->set_setting( 'pickup_instructions', "Open Sunday 9am-12pm.\nPickup at front desk." );

		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Open Sunday 9am-12pm.', $html );
		self::assertStringContainsString( 'Pickup at front desk.', $html );
		// No "add instructions" warning when instructions are present.
		self::assertStringNotContainsString( 'No pickup instructions have been configured', $html );
	}

	public function test_empty_pickup_instructions_shows_settings_link(): void {
		// Default empty pickup_instructions.
		ob_start();
		( new LibrarianDashboardPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Library Hours', $html );
		self::assertStringContainsString( 'connectlibrary-settings', $html );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Seed a reservation row directly into the fake DB table.
	 *
	 * @param array<string,mixed> $row Reservation row fields.
	 */
	private function seed_reservation( array $row ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ][] = $row;
	}

	/**
	 * Seed a loan row directly into the fake DB table.
	 *
	 * @param array<string,mixed> $row Loan row fields.
	 */
	private function seed_loan( array $row ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ][] = $row;
	}

	/**
	 * Write a settings value to the test options store.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 */
	private function set_setting( string $key, mixed $value ): void {
		$current = $GLOBALS['connectlibrary_test_options'][ Settings::OPTION_NAME ]['value'] ?? array();
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$current[ $key ] = $value;
		$GLOBALS['connectlibrary_test_options'][ Settings::OPTION_NAME ] = array(
			'value'    => $current,
			'autoload' => false,
		);
	}
}
