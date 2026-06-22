<?php
/**
 * Tests for the Build-07 librarian circulation admin dashboard.
 *
 * Covers: menu registration, permission/nonce guards, action dispatch
 * (checkout, return, renew, change_due, mark_lost, mark_damaged), disabled-card
 * token lookup, duplicate-submit idempotency, and waitlist-renewal block.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Admin\CirculationPage;
use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Borrowers\GuestAccessTokenService;
use ConnectLibrary\Borrowers\GuestAccessTokenRepository;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Circulation\CopyRepository;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Circulation\LoanService;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Support\Capabilities;
use ConnectLibrary\Support\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * Admin dashboard test suite — all actions exercised against in-memory fakes.
 */
final class CirculationAdminTest extends TestCase {

	private BorrowerRepository $borrower_repo;
	private GuestAccessTokenRepository $token_repo;
	private CopyRepository $copy_repo;
	private LoanRepository $loan_repo;
	private LoanService $loan_service;
	private ReservationRepository $reservation_repo;
	private CirculationPage $page;

	private string $loans_table;
	private string $audit_table;
	private string $audit_events_table;
	private string $reservation_audit_table;
	private string $copies_table;
	private string $reservations_table;
	private string $borrowers_table;
	private string $tokens_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->loans_table             = $tables['loans'] . ':rows';
		$this->audit_table             = $tables['loan_audit'] . ':rows';
		$this->audit_events_table      = $tables['audit_events'] . ':rows';
		$this->reservation_audit_table = $tables['reservation_audit'] . ':rows';
		$this->copies_table            = $tables['copies'] . ':rows';
		$this->reservations_table      = $tables['reservations'] . ':rows';
		$this->borrowers_table         = $tables['borrowers'] . ':rows';
		$this->tokens_table            = $tables['guest_access_tokens'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ]             = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_table ]             = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ]      = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservation_audit_table ] = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ]            = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ]      = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ]         = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->tokens_table ]            = array();
		$GLOBALS['connectlibrary_test_db_insert_failures']                          = array();
		$GLOBALS['connectlibrary_test_db_query_results']                            = array();
		$GLOBALS['connectlibrary_test_current_user_id']                             = 10;
		$GLOBALS['connectlibrary_test_current_user_can']                            = array();
		$GLOBALS['connectlibrary_test_safe_redirect']                               = null;
		$GLOBALS['connectlibrary_test_wp_die']                                      = null;
		$GLOBALS['connectlibrary_test_transients']                                  = array();
		$_SERVER['REMOTE_ADDR'] = '192.0.2.55';
		$_POST                  = array();
		$_GET                   = array();

		$this->borrower_repo    = new BorrowerRepository();
		$this->token_repo       = new GuestAccessTokenRepository();
		$this->copy_repo        = new CopyRepository();
		$this->loan_repo        = new LoanRepository();
		$this->loan_service     = new LoanService( $this->loan_repo, null, $this->copy_repo );
		$this->reservation_repo = new ReservationRepository();
		$this->page             = new CirculationPage(
			$this->loan_service,
			$this->borrower_repo,
			$this->copy_repo,
			$this->loan_repo,
			$this->token_repo,
			$this->reservation_repo
		);
	}

	// -------------------------------------------------------------------------
	// Admin menu registration — Quick Circulation label
	// -------------------------------------------------------------------------

	public function test_menu_label_is_quick_circulation(): void {
		$this->page->add_menu_page();
		$pages = $GLOBALS['connectlibrary_test_admin_pages'] ?? array();
		$entry = $pages['connectlibrary-circulation'] ?? array();
		self::assertSame( 'Quick Circulation', $entry['menu_title'] ?? '' );
		self::assertSame( 'Quick Circulation', $entry['page_title'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// Render: autofocus scanner input and live region
	// -------------------------------------------------------------------------

	public function test_render_includes_autofocus_scanner_input(): void {
		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		self::assertStringContainsString( 'id="circ-scan-input"', $html );
		self::assertStringContainsString( 'name="circ_scan"', $html );
		self::assertStringContainsString( 'autofocus', $html );
		self::assertStringContainsString( 'autocomplete="off"', $html );
	}

	public function test_render_includes_live_region(): void {
		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );
		self::assertStringContainsString( 'id="circ-live-status"', $html );
	}

	// -------------------------------------------------------------------------
	// circ_scan unified scanner
	// -------------------------------------------------------------------------

	public function test_circ_scan_card_token_selects_borrower(): void {
		$this->seed_borrower( 5 );
		$token = 'scan-card-token-active-99';
		$this->seed_token( $token, borrower_id: 5, status: 'active' );

		$_GET = array(
			'circ_scan' => $token,
			'post_type' => BookPostType::POST_TYPE,
			'page'      => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'Test Borrower 5', $html );
	}

	public function test_circ_scan_disabled_token_shows_error(): void {
		$this->seed_borrower( 5 );
		$token = 'scan-card-token-revoked-99';
		$this->seed_token( $token, borrower_id: 5, status: 'revoked' );

		$_GET = array(
			'circ_scan' => $token,
			'post_type' => BookPostType::POST_TYPE,
			'page'      => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'disabled', $html );
		self::assertStringNotContainsString( 'Test Borrower 5', $html );
	}

	// -------------------------------------------------------------------------
	// Checkout source stored as quick-circulation
	// -------------------------------------------------------------------------

	public function test_checkout_source_stored_as_quick_circulation(): void {
		$this->seed_copy( 1, book_post_id: 101, circulation_status: 'available' );

		$_POST = array(
			'circ_action'      => 'checkout',
			'circ_borrower_id' => '5',
			'circ_copy_id'     => '1',
			'_wpnonce'         => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$loan = $this->loan_repo->all()[0] ?? null;
		self::assertNotNull( $loan );
		self::assertSame( 'quick-circulation', (string) ( $loan['source'] ?? '' ) );
	}

	// -------------------------------------------------------------------------
	// Duplicate return — plain-language, non-destructive
	// -------------------------------------------------------------------------

	public function test_duplicate_return_shows_already_returned_message(): void {
		// Copy is available (no active loan) — simulates scanning an already-returned item.
		$this->seed_copy( 1, circulation_status: 'available' );

		$_POST = array(
			'circ_action'  => 'return',
			'circ_copy_id' => '1',
			'_wpnonce'     => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertNotNull( $redirect );
		self::assertStringContainsString( 'circ_error=', $redirect['location'] );

		// Message must be plain-language and non-alarming.
		$decoded = rawurldecode( rawurldecode( $redirect['location'] ) );
		self::assertStringContainsString( 'already returned', $decoded );
		self::assertStringContainsString( 'available', $decoded );

		// Must not have touched any loan row.
		self::assertSame( array(), $this->loan_repo->all() );
	}

	// -------------------------------------------------------------------------
	// Admin menu registration
	// -------------------------------------------------------------------------

	public function test_menu_registered_with_correct_parent_and_capability(): void {
		$this->page->add_menu_page();

		$pages = $GLOBALS['connectlibrary_test_admin_pages'] ?? array();
		self::assertArrayHasKey( 'connectlibrary-circulation', $pages );

		$entry = $pages['connectlibrary-circulation'];
		self::assertSame( 'edit.php?post_type=' . BookPostType::POST_TYPE, $entry['parent_slug'] );
		self::assertSame( Capabilities::MANAGE_CIRCULATION, $entry['capability'] );
	}

	public function test_register_adds_admin_post_hook(): void {
		$GLOBALS['connectlibrary_test_hooks'] = array();
		$this->page->register();

		self::assertArrayHasKey( 'admin_post_connectlibrary_circ_action', $GLOBALS['connectlibrary_test_hooks'] );
	}

	// -------------------------------------------------------------------------
	// Capability and nonce guards on handle_action
	// -------------------------------------------------------------------------

	public function test_handle_action_dies_when_user_lacks_capability(): void {
		$GLOBALS['connectlibrary_test_current_user_can']['manage_connectlibrary_circulation'] = false;
		$GLOBALS['connectlibrary_test_current_user_can']['manage_options']                    = false;

		$_POST = array(
			'circ_action' => 'checkout',
			'_wpnonce'    => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		self::assertNotNull( $GLOBALS['connectlibrary_test_wp_die'] );
	}

	public function test_handle_action_dies_when_nonce_missing(): void {
		$_POST = array( 'circ_action' => 'checkout' );

		$this->page->handle_action();

		self::assertNotNull( $GLOBALS['connectlibrary_test_wp_die'] );
	}

	// -------------------------------------------------------------------------
	// Checkout action (acceptance #1 + #2)
	// -------------------------------------------------------------------------

	public function test_checkout_creates_active_loan_and_redirects_with_notice(): void {
		$this->seed_copy( 1, book_post_id: 101, circulation_status: 'available' );

		$_POST = array(
			'circ_action'      => 'checkout',
			'circ_borrower_id' => '5',
			'circ_copy_id'     => '1',
			'_wpnonce'         => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$loan = $this->loan_repo->all()[0] ?? null;
		self::assertNotNull( $loan );
		self::assertSame( 'active', (string) $loan['status'] );
		self::assertSame( 5, (int) $loan['borrower_id'] );

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertNotNull( $redirect );
		self::assertStringContainsString( 'circ_notice=checkout_ok', $redirect['location'] );
	}

	public function test_checkout_with_due_override_uses_supplied_date_and_note(): void {
		$this->seed_copy( 1 );

		$_POST = array(
			'circ_action'          => 'checkout',
			'circ_borrower_id'     => '5',
			'circ_copy_id'         => '1',
			'due_at_override'      => '2026-12-31',
			'due_override_note'    => 'Extended for holiday',
			'confirm_due_override' => '1',
			'_wpnonce'             => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$loan = $this->loan_repo->all()[0] ?? null;
		self::assertNotNull( $loan );
		self::assertSame( '2026-12-31 00:00:00', $loan['due_at'] );
		self::assertSame( 'Extended for holiday', $loan['override_note'] );

		$audit = $this->loan_repo->audit_events( (int) $loan['id'] );
		self::assertCount( 1, $audit );
		self::assertSame( 'checkout', $audit[0]['action'] );
	}

	public function test_checkout_missing_borrower_redirects_with_error(): void {
		$this->seed_copy( 1 );

		$_POST = array(
			'circ_action'  => 'checkout',
			'circ_copy_id' => '1',
			'_wpnonce'     => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertNotNull( $redirect );
		self::assertStringContainsString( 'circ_error=', $redirect['location'] );
		self::assertSame( array(), $this->loan_repo->all() );
	}

	public function test_checkout_unavailable_copy_redirects_with_error(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out' );

		$_POST = array(
			'circ_action'      => 'checkout',
			'circ_borrower_id' => '5',
			'circ_copy_id'     => '1',
			'_wpnonce'         => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_error=', $redirect['location'] );
		self::assertSame( array(), $this->loan_repo->all() );
	}

	// -------------------------------------------------------------------------
	// Reservation pickup checkout (on-hold copy)
	// -------------------------------------------------------------------------

	public function test_reservation_pickup_creates_active_loan_marks_picked_up_and_redirects(): void {
		$this->seed_copy( 1, book_post_id: 101, circulation_status: Statuses::COPY_ON_HOLD );
		$this->seed_reservation( 1, book_post_id: 101, status: ReservationStatuses::ACTIVE_HOLD, borrower_id: 5, copy_id: 1 );

		$_POST = array(
			'circ_action'      => 'checkout',
			'circ_borrower_id' => '5',
			'circ_copy_id'     => '1',
			'_wpnonce'         => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$loan = $this->loan_repo->all()[0] ?? null;
		self::assertNotNull( $loan );
		self::assertSame( 'active', (string) $loan['status'] );
		self::assertSame( 5, (int) $loan['borrower_id'] );
		self::assertSame( 'quick-circulation', (string) ( $loan['source'] ?? '' ) );

		$reservation = $GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ][0];
		self::assertSame( ReservationStatuses::PICKED_UP, $reservation['status'] );

		$res_audit = $GLOBALS['connectlibrary_test_db_tables'][ $this->reservation_audit_table ];
		self::assertNotEmpty( $res_audit );
		self::assertSame( 'pickup', $res_audit[0]['action'] );

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertNotNull( $redirect );
		self::assertStringContainsString( 'circ_notice=checkout_ok', $redirect['location'] );
	}

	public function test_reservation_pickup_wrong_borrower_is_blocked_and_creates_no_loan(): void {
		$this->seed_copy( 1, book_post_id: 101, circulation_status: Statuses::COPY_ON_HOLD );
		$this->seed_reservation( 1, book_post_id: 101, status: ReservationStatuses::ACTIVE_HOLD, borrower_id: 5, copy_id: 1 );

		$_POST = array(
			'circ_action'      => 'checkout',
			'circ_borrower_id' => '9',
			'circ_copy_id'     => '1',
			'_wpnonce'         => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertNotNull( $redirect );
		self::assertStringContainsString( 'circ_error=', $redirect['location'] );
		self::assertSame( array(), $this->loan_repo->all() );
	}

	public function test_render_shows_reservation_pickup_label_for_on_hold_copy_with_borrower(): void {
		$this->seed_borrower( 5 );
		$this->seed_copy( 1, book_post_id: 101, circulation_status: Statuses::COPY_ON_HOLD );
		$this->seed_reservation( 1, book_post_id: 101, status: ReservationStatuses::ACTIVE_HOLD, borrower_id: 5, copy_id: 1 );

		$_GET = array(
			'circ_borrower_id' => '5',
			'circ_copy_id'     => '1',
			'post_type'        => BookPostType::POST_TYPE,
			'page'             => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'Check out held reservation', $html );
		self::assertStringContainsString( 'Complete reservation pickup for this borrower.', $html );
		self::assertStringNotContainsString( 'circ-action-checkout"', $html );
	}

	public function test_render_shows_no_reservation_pickup_for_wrong_borrower(): void {
		$this->seed_borrower( 9 );
		$this->seed_copy( 1, book_post_id: 101, circulation_status: Statuses::COPY_ON_HOLD );
		$this->seed_reservation( 1, book_post_id: 101, status: ReservationStatuses::ACTIVE_HOLD, borrower_id: 5, copy_id: 1 );

		$_GET = array(
			'circ_borrower_id' => '9',
			'circ_copy_id'     => '1',
			'post_type'        => BookPostType::POST_TYPE,
			'page'             => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringNotContainsString( 'Check out held reservation', $html );
	}

	// -------------------------------------------------------------------------
	// Return action (acceptance #4 + no-active-loan guard)
	// -------------------------------------------------------------------------

	public function test_return_closes_loan_and_redirects_with_notice(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out', current_loan_id: 1 );
		$this->seed_loan( 1, borrower_id: 7, copy_id: 1 );

		$_POST = array(
			'circ_action'      => 'return',
			'circ_copy_id'     => '1',
			'circ_loan_id'     => '1',
			'circ_borrower_id' => '7',
			'_wpnonce'         => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$loan = $this->loan_repo->get( 1 );
		self::assertSame( 'returned', (string) $loan['status'] );

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_notice=return_ok', $redirect['location'] );
	}

	public function test_return_without_active_loan_redirects_with_error(): void {
		$this->seed_copy( 1, circulation_status: 'available' );

		$_POST = array(
			'circ_action'  => 'return',
			'circ_copy_id' => '1',
			'_wpnonce'     => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_error=', $redirect['location'] );
	}

	// -------------------------------------------------------------------------
	// Renewal action (acceptance #5 + waitlist block)
	// -------------------------------------------------------------------------

	public function test_renew_eligible_loan_redirects_with_notice(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2 );

		$_POST = array(
			'circ_action'      => 'renew',
			'circ_borrower_id' => '7',
			'circ_loan_id'     => '1',
			'_wpnonce'         => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$loan = $this->loan_repo->get( 1 );
		self::assertSame( 1, (int) $loan['renewal_count'] );

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_notice=renew_ok', $redirect['location'] );
	}

	public function test_renew_blocked_by_waitlist_redirects_with_error(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2, book_post_id: 101 );
		$this->seed_reservation( 1, book_post_id: 101, status: 'waitlisted' );

		$_POST = array(
			'circ_action'      => 'renew',
			'circ_borrower_id' => '7',
			'circ_loan_id'     => '1',
			'_wpnonce'         => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		// Renewal count must be unchanged.
		$loan = $this->loan_repo->get( 1 );
		self::assertSame( 0, (int) $loan['renewal_count'] );

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_error=', $redirect['location'] );
	}

	public function test_duplicate_renew_does_not_double_increment(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 1, renewal_limit: 1 );

		$post = array(
			'circ_action'      => 'renew',
			'circ_borrower_id' => '7',
			'circ_loan_id'     => '1',
			'_wpnonce'         => 'connectlibrary_circ_action',
		);

		$_POST = $post;
		$this->page->handle_action();
		$GLOBALS['connectlibrary_test_safe_redirect'] = null;

		$_POST = $post;
		$this->page->handle_action();

		$loan = $this->loan_repo->get( 1 );
		self::assertSame( 1, (int) $loan['renewal_count'], 'Second submit should not increment again' );
	}

	// -------------------------------------------------------------------------
	// Change due date (acceptance #6 — no renewal consumed)
	// -------------------------------------------------------------------------

	public function test_change_due_updates_due_date_and_redirects(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-07-01 12:00:00', renewal_count: 0, renewal_limit: 1 );

		$_POST = array(
			'circ_action'        => 'change_due',
			'circ_loan_id'       => '1',
			'circ_borrower_id'   => '7',
			'new_due_at'         => '2026-08-15',
			'due_change_reason'  => 'Holiday extension',
			'confirm_due_change' => '1',
			'_wpnonce'           => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$loan = $this->loan_repo->get( 1 );
		self::assertSame( '2026-08-15 00:00:00', $loan['due_at'] );
		self::assertSame( 0, (int) $loan['renewal_count'], 'Renewal count must not change on due-date change' );

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_notice=due_change_ok', $redirect['location'] );
	}

	public function test_change_due_writes_audit_with_old_and_new_dates(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-07-01 12:00:00', renewal_count: 0, renewal_limit: 1 );

		$_POST = array(
			'circ_action'        => 'change_due',
			'circ_loan_id'       => '1',
			'circ_borrower_id'   => '7',
			'new_due_at'         => '2026-08-15',
			'due_change_reason'  => '',
			'confirm_due_change' => '1',
			'_wpnonce'           => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$events = $this->loan_repo->audit_events( 1 );
		self::assertCount( 1, $events );
		self::assertSame( 'due_date_change', $events[0]['action'] );
		self::assertStringContainsString( 'old_due:', (string) $events[0]['reason'] );
		self::assertStringContainsString( 'new_due:', (string) $events[0]['reason'] );
	}

	public function test_change_due_past_date_without_reason_redirects_with_error(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 12:00:00', renewal_count: 0, renewal_limit: 1 );

		$_POST = array(
			'circ_action'       => 'change_due',
			'circ_loan_id'      => '1',
			'circ_borrower_id'  => '7',
			'new_due_at'        => '2024-01-01',
			'due_change_reason' => '',
			'_wpnonce'          => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_error=', $redirect['location'] );

		// Due date must be unchanged.
		$loan = $this->loan_repo->get( 1 );
		self::assertSame( '2026-09-01 12:00:00', $loan['due_at'] );
	}

	public function test_change_due_stale_guard_redirects_with_error_without_audit(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 12:00:00', renewal_count: 0, renewal_limit: 1 );
		$GLOBALS['connectlibrary_test_db_query_results']['guarded_loan_due_change_update'] = 0;

		$_POST = array(
			'circ_action'       => 'change_due',
			'circ_loan_id'      => '1',
			'circ_borrower_id'  => '7',
			'new_due_at'        => '2026-10-01',
			'due_change_reason' => 'stale submit',
			'_wpnonce'          => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_error=', $redirect['location'] );
		self::assertSame( array(), $this->loan_repo->audit_events( 1 ) );

		$loan = $this->loan_repo->get( 1 );
		self::assertSame( '2026-09-01 12:00:00', $loan['due_at'] );
	}

	// -------------------------------------------------------------------------
	// Mark lost (acceptance #8)
	// -------------------------------------------------------------------------

	public function test_mark_lost_without_confirm_redirects_with_error(): void {
		$this->seed_copy( 1 );

		$_POST = array(
			'circ_action'  => 'mark_lost',
			'circ_copy_id' => '1',
			'_wpnonce'     => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_error=', $redirect['location'] );

		$copy = $this->copy_repo->get( 1 );
		self::assertNotSame( 'lost', $copy['circulation_status'] );
	}

	public function test_mark_lost_with_confirm_sets_lost_status_and_writes_audit(): void {
		$this->seed_copy( 1, circulation_status: 'available' );

		$_POST = array(
			'circ_action'  => 'mark_lost',
			'circ_copy_id' => '1',
			'confirm_lost' => '1',
			'_wpnonce'     => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'lost', $copy['circulation_status'] );
		self::assertSame( 'lost', $copy['item_status'] );

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_notice=lost_ok', $redirect['location'] );

		$audit = $this->loan_repo->audit_events( 0 );
		self::assertNotEmpty( $audit );
		self::assertSame( 'copy_lost', $audit[0]['action'] );
	}

	public function test_mark_lost_preserves_loan_history(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out', item_status: 'active', current_loan_id: 1 );
		$this->seed_loan( 1, borrower_id: 7, copy_id: 1 );

		$_POST = array(
			'circ_action'  => 'mark_lost',
			'circ_copy_id' => '1',
			'confirm_lost' => '1',
			'_wpnonce'     => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		// Loan row must still exist (history preserved).
		$loan = $this->loan_repo->get( 1 );
		self::assertNotNull( $loan );
		// Loan is closed by the return step inside do_mark_lost.
		self::assertSame( 'returned', (string) $loan['status'] );
	}

	// -------------------------------------------------------------------------
	// Mark damaged (acceptance #8)
	// -------------------------------------------------------------------------

	public function test_mark_damaged_without_confirm_redirects_with_error(): void {
		$this->seed_copy( 1 );

		$_POST = array(
			'circ_action'  => 'mark_damaged',
			'circ_copy_id' => '1',
			'_wpnonce'     => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_error=', $redirect['location'] );

		$copy = $this->copy_repo->get( 1 );
		self::assertNotSame( 'damaged', $copy['circulation_status'] );
	}

	public function test_mark_damaged_with_confirm_sets_damaged_status(): void {
		$this->seed_copy( 1, circulation_status: 'available' );

		$_POST = array(
			'circ_action'     => 'mark_damaged',
			'circ_copy_id'    => '1',
			'confirm_damaged' => '1',
			'damage_note'     => 'Torn cover page',
			'_wpnonce'        => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'damaged', $copy['circulation_status'] );
		self::assertSame( 'damaged', $copy['item_status'] );

		$redirect = $GLOBALS['connectlibrary_test_safe_redirect'];
		self::assertStringContainsString( 'circ_notice=damaged_ok', $redirect['location'] );
	}

	public function test_mark_damaged_stores_note_in_copy_private_notes(): void {
		$this->seed_copy( 1, circulation_status: 'available' );

		$_POST = array(
			'circ_action'     => 'mark_damaged',
			'circ_copy_id'    => '1',
			'confirm_damaged' => '1',
			'damage_note'     => 'Water damage, page 23',
			'_wpnonce'        => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'Water damage, page 23', (string) ( $copy['private_notes'] ?? '' ) );
	}

	// -------------------------------------------------------------------------
	// Disabled card token (acceptance #3)
	// -------------------------------------------------------------------------

	public function test_disabled_card_token_is_not_resolved_to_borrower(): void {
		$this->seed_borrower( 5 );
		$token = 'test-card-token-12345';
		$this->seed_token( $token, borrower_id: 5, status: 'revoked' );

		$_GET = array(
			'circ_card_token' => $token,
			'post_type'       => BookPostType::POST_TYPE,
			'page'            => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		// Page must display the disabled-card warning.
		self::assertStringContainsString( 'disabled', $html );
		// Borrower summary should NOT appear (borrower not selected through disabled card).
		self::assertStringNotContainsString( 'Test Borrower 5', $html );
	}

	public function test_active_card_token_resolves_to_borrower(): void {
		$this->seed_borrower( 5 );
		$token = 'valid-active-token-xyz';
		$this->seed_token( $token, borrower_id: 5, status: 'active' );

		$_GET = array(
			'circ_card_token' => $token,
			'post_type'       => BookPostType::POST_TYPE,
			'page'            => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		// The borrower summary section should appear when the card resolves.
		self::assertStringContainsString( 'Test Borrower 5', $html );
	}

	public function test_card_token_lookup_uses_canonical_hmac_hash(): void {
		$this->seed_borrower( 5 );
		$token = str_repeat( 'a', 64 );
		$this->seed_token( $token, borrower_id: 5, status: 'active' );

		$_GET = array(
			'circ_card_token' => $token,
			'post_type'       => BookPostType::POST_TYPE,
			'page'            => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'Test Borrower 5', $html );
		self::assertSame( GuestAccessTokenService::hash_token( $token ), $GLOBALS['connectlibrary_test_db_tables'][ $this->tokens_table ][0]['token_hash'] );
	}

	public function test_inactive_card_token_borrower_is_refused(): void {
		$this->seed_borrower( 5, status: 'inactive' );
		$token = str_repeat( 'b', 64 );
		$this->seed_token( $token, borrower_id: 5, status: 'active' );

		$_GET = array(
			'circ_card_token' => $token,
			'post_type'       => BookPostType::POST_TYPE,
			'page'            => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'borrower account is not active', $html );
		self::assertStringNotContainsString( 'Test Borrower 5', $html );
	}

	public function test_anonymized_card_token_borrower_is_refused(): void {
		$this->seed_borrower( 5, status: 'anonymized' );
		$token = str_repeat( 'c', 64 );
		$this->seed_token( $token, borrower_id: 5, status: 'active' );

		$_GET = array(
			'circ_card_token' => $token,
			'post_type'       => BookPostType::POST_TYPE,
			'page'            => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'borrower account is not active', $html );
		self::assertStringNotContainsString( 'Test Borrower 5', $html );
	}

	public function test_deleted_card_token_borrower_is_refused(): void {
		$token = str_repeat( 'd', 64 );
		$this->seed_token( $token, borrower_id: 999, status: 'active' );

		$_GET = array(
			'circ_card_token' => $token,
			'post_type'       => BookPostType::POST_TYPE,
			'page'            => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'borrower account is not active', $html );
	}

	public function test_circ_scan_unknown_card_shaped_token_shows_card_error_not_item_lookup(): void {
		$_GET = array(
			'circ_scan' => str_repeat( 'e', 64 ),
			'post_type' => BookPostType::POST_TYPE,
			'page'      => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'Library card not found', $html );
		self::assertStringNotContainsString( 'No matching items found', $html );
	}

	public function test_circ_scan_malformed_card_shaped_token_shows_card_error(): void {
		$_GET = array(
			'circ_scan' => 'CLCARD-short',
			'post_type' => BookPostType::POST_TYPE,
			'page'      => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'card code is not valid', $html );
	}

	public function test_duplicate_card_token_hash_is_refused_as_integrity_error(): void {
		$this->seed_borrower( 5 );
		$this->seed_borrower( 6 );
		$token = str_repeat( 'f', 64 );
		$this->seed_token( $token, borrower_id: 5, status: 'active' );
		$this->seed_token( $token, borrower_id: 6, status: 'active' );

		$_GET = array(
			'circ_card_token' => $token,
			'post_type'       => BookPostType::POST_TYPE,
			'page'            => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'card record needs librarian review', $html );
		self::assertStringNotContainsString( 'Test Borrower 5', $html );
		self::assertStringNotContainsString( 'Test Borrower 6', $html );
	}

	public function test_card_lookup_audits_success_without_raw_card_code(): void {
		$this->seed_borrower( 5 );
		$token = str_repeat( '1', 64 );
		$this->seed_token( $token, borrower_id: 5, status: 'active' );

		$_GET = array(
			'circ_card_token' => $token,
			'post_type'       => BookPostType::POST_TYPE,
			'page'            => 'connectlibrary-circulation',
		);

		ob_start();
		try {
			$this->page->render();
		} finally {
			ob_get_clean();
		}

		$events = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		self::assertNotEmpty( $events );
		self::assertSame( 'card_lookup', $events[0]['action'] );
		self::assertSame( 'ok', $events[0]['status'] );
		self::assertStringNotContainsString( $token, (string) $events[0]['context_json'] );
		self::assertStringContainsString( 'fingerprint', (string) $events[0]['context_json'] );
	}

	public function test_failed_card_lookup_audits_failure_and_rate_limits(): void {
		$token = str_repeat( '2', 64 );
		$html  = '';

		for ( $i = 0; $i < 6; $i++ ) {
			$_GET = array(
				'circ_card_token' => $token,
				'post_type'       => BookPostType::POST_TYPE,
				'page'            => 'connectlibrary-circulation',
			);

			ob_start();
			try {
				$this->page->render();
			} finally {
				$html = (string) ob_get_clean();
			}
		}

		self::assertStringContainsString( 'Too many failed card lookups', $html );
		$events = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		self::assertNotEmpty( $events );
		self::assertSame( 'failed', $events[0]['status'] );
		self::assertStringNotContainsString( $token, (string) $events[0]['context_json'] );
		self::assertStringContainsString( 'not_found', (string) $events[0]['context_json'] );
	}

	// -------------------------------------------------------------------------
	// Non-librarian cannot access action endpoint (acceptance #11)
	// -------------------------------------------------------------------------

	public function test_non_librarian_cannot_submit_action(): void {
		$GLOBALS['connectlibrary_test_current_user_can']['manage_connectlibrary_circulation'] = false;
		$GLOBALS['connectlibrary_test_current_user_can']['manage_options']                    = false;

		$_POST = array(
			'circ_action'      => 'checkout',
			'circ_borrower_id' => '5',
			'circ_copy_id'     => '1',
			'_wpnonce'         => 'connectlibrary_circ_action',
		);

		$this->page->handle_action();

		// Must die and NOT create any loan.
		self::assertNotNull( $GLOBALS['connectlibrary_test_wp_die'] );
		self::assertSame( array(), $this->loan_repo->all() );
	}

	// -------------------------------------------------------------------------
	// Capabilities helper
	// -------------------------------------------------------------------------

	public function test_can_manage_circulation_returns_true_for_manage_options(): void {
		$GLOBALS['connectlibrary_test_current_user_can']['manage_connectlibrary_circulation'] = false;
		$GLOBALS['connectlibrary_test_current_user_can']['manage_options']                    = true;

		self::assertTrue( Capabilities::can_manage_circulation() );
	}

	public function test_can_manage_circulation_returns_true_for_circulation_cap(): void {
		$GLOBALS['connectlibrary_test_current_user_can']['manage_connectlibrary_circulation'] = true;
		$GLOBALS['connectlibrary_test_current_user_can']['manage_options']                    = false;

		self::assertTrue( Capabilities::can_manage_circulation() );
	}

	public function test_can_manage_circulation_returns_false_when_no_cap(): void {
		$GLOBALS['connectlibrary_test_current_user_can']['manage_connectlibrary_circulation'] = false;
		$GLOBALS['connectlibrary_test_current_user_can']['manage_options']                    = false;

		self::assertFalse( Capabilities::can_manage_circulation() );
	}

	// -------------------------------------------------------------------------
	// BorrowerRepository::search
	// -------------------------------------------------------------------------

	public function test_borrower_search_by_display_name(): void {
		$this->seed_borrower( 1, display_name: 'Alice Smith' );
		$this->seed_borrower( 2, display_name: 'Bob Jones' );

		$results = $this->borrower_repo->search( array( 'search' => 'alice' ) );
		self::assertCount( 1, $results );
		self::assertSame( 'Alice Smith', $results[0]['display_name'] );
	}

	public function test_borrower_search_by_email(): void {
		$this->seed_borrower( 1, display_name: 'Alice', email: 'alice@example.com' );
		$this->seed_borrower( 2, display_name: 'Bob', email: 'bob@example.com' );

		$results = $this->borrower_repo->search( array( 'search' => 'bob@' ) );
		self::assertCount( 1, $results );
		self::assertSame( 'Bob', $results[0]['display_name'] );
	}

	public function test_borrower_search_returns_all_when_no_filter(): void {
		$this->seed_borrower( 1 );
		$this->seed_borrower( 2 );

		$results = $this->borrower_repo->search();
		self::assertCount( 2, $results );
	}

	// -------------------------------------------------------------------------
	// CopyRepository::find_by_isbn_or_barcode
	// -------------------------------------------------------------------------

	public function test_copy_lookup_by_isbn13(): void {
		$this->seed_copy( 1, isbn_13: '9780123456789' );
		$this->seed_copy( 2, isbn_13: '9780987654321' );

		$results = $this->copy_repo->find_by_isbn_or_barcode( '9780123456789' );
		self::assertCount( 1, $results );
		self::assertSame( 1, (int) $results[0]['id'] );
	}

	public function test_copy_lookup_by_barcode(): void {
		$this->seed_copy( 1, barcode: 'BAR-001' );

		$results = $this->copy_repo->find_by_isbn_or_barcode( 'BAR-001' );
		self::assertCount( 1, $results );
	}

	public function test_copy_lookup_returns_empty_for_blank_query(): void {
		$this->seed_copy( 1 );

		$results = $this->copy_repo->find_by_isbn_or_barcode( '' );
		self::assertSame( array(), $results );
	}

	// -------------------------------------------------------------------------
	// LoanRepository::active_for_copy
	// -------------------------------------------------------------------------

	public function test_active_for_copy_returns_active_loan(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out' );
		$this->seed_loan( 1, borrower_id: 7, copy_id: 1 );

		$loan = $this->loan_repo->active_for_copy( 1 );
		self::assertNotNull( $loan );
		self::assertSame( 1, (int) $loan['id'] );
	}

	public function test_active_for_copy_returns_null_when_no_active_loan(): void {
		$this->seed_copy( 1, circulation_status: 'available' );

		$loan = $this->loan_repo->active_for_copy( 1 );
		self::assertNull( $loan );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function seed_copy(
		int $id,
		int $book_post_id = 101,
		string $circulation_status = 'available',
		string $item_status = 'active',
		?string $isbn_13 = null,
		?string $isbn_10 = null,
		?string $barcode = null,
		?int $current_loan_id = null
	): void {
		$now = '2026-06-19 12:00:00';
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ][] = array(
			'id'                 => $id,
			'book_post_id'       => $book_post_id,
			'copy_number'        => 1,
			'item_status'        => $item_status,
			'circulation_status' => $circulation_status,
			'visibility'         => 'public',
			'current_loan_id'    => $current_loan_id,
			'isbn_13'            => $isbn_13,
			'isbn_10'            => $isbn_10,
			'barcode'            => $barcode,
			'room'               => null,
			'shelf'              => null,
			'section'            => null,
			'private_notes'      => null,
			'created_at'         => $now,
			'updated_at'         => $now,
		);
	}

	private function seed_loan(
		int $id,
		int $borrower_id = 1,
		string $status = 'active',
		string $due_at = '2026-09-01 00:00:00',
		int $renewal_count = 0,
		int $renewal_limit = 2,
		int $book_post_id = 101,
		?int $copy_id = null
	): void {
		$now = '2026-06-19 12:00:00';
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ][] = array(
			'id'              => $id,
			'book_post_id'    => $book_post_id,
			'copy_id'         => $copy_id,
			'borrower_id'     => $borrower_id,
			'status'          => $status,
			'checked_out_at'  => $now,
			'due_at'          => $due_at,
			'returned_at'     => null,
			'renewal_count'   => $renewal_count,
			'renewal_limit'   => $renewal_limit,
			'last_renewed_at' => null,
			'correction_note' => null,
			'override_note'   => null,
			'source'          => null,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
	}

	private function seed_reservation(
		int $id,
		int $book_post_id = 101,
		string $status = 'waitlisted',
		?int $borrower_id = null,
		?int $copy_id = null,
		?string $hold_expires_at = null
	): void {
		$now = '2026-06-19 12:00:00';
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ][] = array(
			'id'              => $id,
			'book_post_id'    => $book_post_id,
			'borrower_id'     => $borrower_id,
			'copy_id'         => $copy_id,
			'status'          => $status,
			'hold_expires_at' => $hold_expires_at,
			'requested_at'    => $now,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
	}

	private function seed_borrower( int $id, string $display_name = '', string $email = '', string $status = 'active', string $borrower_type = 'manual' ): void {
		$now = '2026-06-19 12:00:00';
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ][] = array(
			'id'                    => $id,
			'borrower_type'         => $borrower_type,
			'wp_user_id'            => null,
			'status'                => $status,
			'display_name'          => '' !== $display_name ? $display_name : "Test Borrower {$id}",
			'preferred_name'        => null,
			'email'                 => '' !== $email ? $email : null,
			'phone'                 => null,
			'guardian_borrower_id'  => null,
			'guardian_name'         => null,
			'guardian_email'        => null,
			'guardian_phone'        => null,
			'guardian_relationship' => null,
			'email_notices_allowed' => 0,
			'private_notes'         => null,
			'created_at'            => $now,
			'updated_at'            => $now,
			'created_by'            => null,
			'updated_by'            => null,
		);
	}

	private function seed_token( string $plain_token, int $borrower_id, string $status = 'active' ): void {
		$now = '2026-06-19 12:00:00';
		$GLOBALS['connectlibrary_test_db_tables'][ $this->tokens_table ][] = array(
			'id'          => count( $GLOBALS['connectlibrary_test_db_tables'][ $this->tokens_table ] ) + 1,
			'borrower_id' => $borrower_id,
			'token_hash'  => GuestAccessTokenService::hash_token( $plain_token ),
			'status'      => $status,
			'expires_at'  => '2030-01-01 00:00:00',
			'created_at'  => $now,
			'created_by'  => null,
			'revoked_at'  => 'revoked' === $status ? $now : null,
		);
	}
}
