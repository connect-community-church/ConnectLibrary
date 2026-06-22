<?php
/**
 * Tests for LoanService business logic.
 *
 * Covers: active loans query, checkout, return, renewal, mark damaged/lost/retired,
 * void/correction, and status enum helpers.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Circulation\CopyRepository;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Circulation\LoanService;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Support\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * Service-layer tests; fakes all WPDB calls via the in-memory stub.
 */
final class LoanServiceTest extends TestCase {

	private LoanRepository $repo;
	private CopyRepository $copy_repo;
	private LoanService    $service;

	private string $loans_table;
	private string $audit_table;
	private string $reservation_audit_table;
	private string $copies_table;
	private string $reservations_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->loans_table        = $tables['loans'] . ':rows';
		$this->audit_table        = $tables['loan_audit'] . ':rows';
		$this->reservation_audit_table = $tables['reservation_audit'] . ':rows';
		$this->copies_table       = $tables['copies'] . ':rows';
		$this->reservations_table = $tables['reservations'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ]        = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_table ]         = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservation_audit_table ] = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ]        = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ]  = array();
		$GLOBALS['connectlibrary_test_db_insert_failures']                       = array();
		$GLOBALS['connectlibrary_test_db_query_results']                         = array();
		$GLOBALS['connectlibrary_test_current_user_id']                          = 1;

		$this->copy_repo = new CopyRepository();
		$this->repo      = new LoanRepository();
		$this->service   = new LoanService( $this->repo, null, $this->copy_repo );
	}

	// -------------------------------------------------------------------------
	// active_loans_for_borrower
	// -------------------------------------------------------------------------

	public function test_active_loans_for_borrower_returns_only_active_rows(): void {
		$this->seed_loan( 1, borrower_id: 5, status: 'active' );
		$this->seed_loan( 2, borrower_id: 5, status: 'returned' );
		$this->seed_loan( 3, borrower_id: 9, status: 'active' );

		$result = $this->service->active_loans_for_borrower( 5 );

		self::assertIsArray( $result );
		self::assertCount( 1, $result );
		self::assertSame( 1, (int) $result[0]['id'] );
	}

	public function test_active_loans_for_borrower_returns_empty_when_none(): void {
		$result = $this->service->active_loans_for_borrower( 99 );

		self::assertIsArray( $result );
		self::assertCount( 0, $result );
	}

	public function test_active_loans_for_borrower_rejects_invalid_id(): void {
		$result = $this->service->active_loans_for_borrower( 0 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_invalid', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------------
	// checkout — success (acceptance #1)
	// -------------------------------------------------------------------------

	public function test_checkout_success_creates_active_loan_checked_out_copy_due_at_and_audit_row(): void {
		$this->seed_copy( 1, book_post_id: 101, circulation_status: 'available', item_status: 'active' );

		// current_time stub returns '2026-06-19 12:00:00'; +14 days = '2026-07-03 12:00:00'
		$result = $this->service->checkout( 1, 101, 7 );

		self::assertIsArray( $result );
		self::assertSame( 'active', $result['status'] );
		self::assertSame( 7, (int) $result['borrower_id'] );
		self::assertSame( '2026-07-03 12:00:00', $result['due_at'] );
		self::assertSame( 1, (int) $result['renewal_limit'] );

		// Copy should now be checked_out and carry the loan ID.
		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'checked_out', $copy['circulation_status'] );
		self::assertSame( (int) $result['id'], (int) $copy['current_loan_id'] );

		// Audit row must record 'checkout' action.
		$events = $this->repo->audit_events( (int) $result['id'] );
		self::assertCount( 1, $events );
		self::assertSame( 'checkout', $events[0]['action'] );
	}

	// -------------------------------------------------------------------------
	// checkout — due override (acceptance #2)
	// -------------------------------------------------------------------------

	public function test_checkout_due_override_uses_supplied_date(): void {
		$this->seed_copy( 1 );

		$result = $this->service->checkout( 1, 101, 7, '2026-12-31 00:00:00', 'admin', 0, 'Extended for holiday' );

		self::assertIsArray( $result );
		self::assertSame( '2026-12-31 00:00:00', $result['due_at'] );
		self::assertSame( 'Extended for holiday', $result['override_note'] );
	}

	// -------------------------------------------------------------------------
	// checkout — invalid input rejection (acceptance #2)
	// -------------------------------------------------------------------------

	public function test_checkout_rejects_zero_copy_id(): void {
		$result = $this->service->checkout( 0, 101, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_invalid', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_checkout_rejects_zero_book_post_id(): void {
		$result = $this->service->checkout( 1, 0, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_invalid', $result->get_error_code() );
	}

	public function test_checkout_rejects_missing_copy(): void {
		$result = $this->service->checkout( 999, 101, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_copy_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_checkout_rejects_copy_book_mismatch(): void {
		$this->seed_copy( 1, book_post_id: 101, circulation_status: 'available', item_status: 'active' );

		$result = $this->service->checkout( 1, 999, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_copy_book_mismatch', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
		self::assertSame( array(), $this->repo->all() );

		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'available', $copy['circulation_status'] );
	}

	public function test_checkout_rejects_non_active_item_status(): void {
		$this->seed_copy( 1, item_status: 'retired' );

		$result = $this->service->checkout( 1, 101, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_copy_not_active', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_checkout_rejects_non_available_circulation_status(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out' );

		$result = $this->service->checkout( 1, 101, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_copy_not_available', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_checkout_from_matching_active_hold_marks_reservation_picked_up(): void {
		$this->seed_copy( 1, book_post_id: 101, circulation_status: Statuses::COPY_ON_HOLD );
		$this->seed_reservation( 1, book_post_id: 101, status: ReservationStatuses::ACTIVE_HOLD, borrower_id: 7, copy_id: 1 );

		$result = $this->service->checkout( 1, 101, 7 );

		self::assertIsArray( $result );
		self::assertSame( 'active', $result['status'] );
		self::assertArrayHasKey( 'reservation_pickup', $result );

		$reservation = $GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ][0];
		self::assertSame( ReservationStatuses::PICKED_UP, $reservation['status'] );

		$audit = $GLOBALS['connectlibrary_test_db_tables'][ $this->reservation_audit_table ];
		self::assertCount( 1, $audit );
		self::assertSame( 'pickup', $audit[0]['action'] );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $audit[0]['from_status'] );
		self::assertSame( ReservationStatuses::PICKED_UP, $audit[0]['to_status'] );
	}

	public function test_checkout_rejects_copy_held_for_another_borrower(): void {
		$this->seed_copy( 1, book_post_id: 101, circulation_status: Statuses::COPY_ON_HOLD );
		$this->seed_reservation( 1, book_post_id: 101, status: ReservationStatuses::ACTIVE_HOLD, borrower_id: 8, copy_id: 1 );

		$result = $this->service->checkout( 1, 101, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_copy_held_for_other', $result->get_error_code() );
		self::assertSame( array(), $this->repo->all() );
	}

	public function test_checkout_rejects_waitlisted_borrower_before_pickup_hold(): void {
		$this->seed_copy( 1, book_post_id: 101, circulation_status: Statuses::COPY_AVAILABLE );
		$this->seed_reservation( 1, book_post_id: 101, status: ReservationStatuses::WAITLISTED, borrower_id: 7 );

		$result = $this->service->checkout( 1, 101, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_not_ready_for_pickup', $result->get_error_code() );
		self::assertSame( array(), $this->repo->all() );
	}

	// -------------------------------------------------------------------------
	// checkout — conflict from guarded update (acceptance #3)
	// -------------------------------------------------------------------------

	public function test_checkout_conflict_when_guarded_update_affects_no_rows(): void {
		$this->seed_copy( 1 );
		$GLOBALS['connectlibrary_test_db_query_results']['guarded_copy_checkout_update'] = 0;

		$result = $this->service->checkout( 1, 101, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_checkout_conflict', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );

		// Copy must still be available — rollback worked.
		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'available', $copy['circulation_status'] );
	}

	// -------------------------------------------------------------------------
	// return_copy — success paths (acceptance #4)
	// -------------------------------------------------------------------------

	public function test_return_closes_loan_clears_current_loan_id_and_sets_available_when_no_waitlist(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out' );
		$this->seed_loan( 1, borrower_id: 7, status: 'active', copy_id: 1 );

		$result = $this->service->return_copy( 1 );

		self::assertIsArray( $result );
		self::assertSame( 'returned', $result['status'] );
		self::assertNotNull( $result['returned_at'] );

		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'available', $copy['circulation_status'] );
		self::assertNull( $copy['current_loan_id'] );
	}

	public function test_return_sets_on_hold_when_waitlisted_reservation_exists(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out' );
		$this->seed_loan( 1, borrower_id: 7, status: 'active', copy_id: 1, book_post_id: 101 );
		$this->seed_reservation( 1, book_post_id: 101, status: 'waitlisted', borrower_id: 8 );

		$result = $this->service->return_copy( 1 );

		self::assertIsArray( $result );
		self::assertSame( 'returned', $result['status'] );
		self::assertArrayHasKey( 'reservation_promotion', $result );
		self::assertSame( 'waitlist_offer', $result['reservation_promotion']['notification']['type'] );

		$reservation = $GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ][0];
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $reservation['status'] );
		self::assertSame( 1, (int) $reservation['copy_id'] );
		self::assertNotEmpty( $reservation['hold_expires_at'] );

		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'on_hold', $copy['circulation_status'] );

		$audit = $GLOBALS['connectlibrary_test_db_tables'][ $this->reservation_audit_table ];
		self::assertCount( 1, $audit );
		self::assertSame( 'promote_waitlist', $audit[0]['action'] );
		self::assertSame( ReservationStatuses::WAITLISTED, $audit[0]['from_status'] );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $audit[0]['to_status'] );
	}

	public function test_return_sets_on_hold_when_promotion_hook_returns_a_hold(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out' );
		$this->seed_loan( 1, borrower_id: 7, status: 'active', copy_id: 1, book_post_id: 101 );

		$hook_called = false;
		$promotion   = static function ( int $book_id ) use ( &$hook_called ): array {
			$hook_called = true;
			return array( 'id' => 1, 'status' => 'active_hold', 'book_post_id' => $book_id );
		};

		$service = new LoanService( $this->repo, null, $this->copy_repo, $promotion );
		$result  = $service->return_copy( 1 );

		self::assertIsArray( $result );
		self::assertTrue( $hook_called );
		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'on_hold', $copy['circulation_status'] );
	}

	public function test_return_of_checked_out_damaged_copy_keeps_copy_damaged_not_available(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out', item_status: 'active', current_loan_id: 1 );
		$this->seed_loan( 1, borrower_id: 7, status: 'active', copy_id: 1, book_post_id: 101 );

		$damaged = $this->service->mark_copy_damaged( 1, 'staff-damaged' );
		self::assertIsArray( $damaged );
		self::assertSame( 'checked_out', $damaged['circulation_status'] );
		self::assertSame( 'damaged', $damaged['item_status'] );

		$result = $this->service->return_copy( 1 );

		self::assertIsArray( $result );
		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'damaged', $copy['item_status'] );
		self::assertSame( 'damaged', $copy['circulation_status'] );
		self::assertNull( $copy['current_loan_id'] );
	}

	public function test_return_of_checked_out_lost_copy_keeps_copy_lost_not_on_hold(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out', item_status: 'active', current_loan_id: 1 );
		$this->seed_loan( 1, borrower_id: 7, status: 'active', copy_id: 1, book_post_id: 101 );
		$this->seed_reservation( 1, book_post_id: 101, status: 'waitlisted' );

		$lost = $this->service->mark_copy_lost( 1, 'staff-lost' );
		self::assertIsArray( $lost );

		$result = $this->service->return_copy( 1 );

		self::assertIsArray( $result );
		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'lost', $copy['item_status'] );
		self::assertSame( 'lost', $copy['circulation_status'] );
		self::assertNull( $copy['current_loan_id'] );
	}

	public function test_return_of_retired_state_copy_keeps_copy_retired_not_available(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out', item_status: 'retired', current_loan_id: 1 );
		$this->seed_loan( 1, borrower_id: 7, status: 'active', copy_id: 1, book_post_id: 101 );

		$result = $this->service->return_copy( 1 );

		self::assertIsArray( $result );
		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'retired', $copy['circulation_status'] );
		self::assertNull( $copy['current_loan_id'] );
	}

	public function test_return_fails_for_already_returned_loan(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'returned' );

		$result = $this->service->return_copy( 1 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_not_closeable', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------------
	// renew — success
	// -------------------------------------------------------------------------

	public function test_renew_extends_future_due_at_by_14_days(): void {
		// current_time stub returns '2026-06-19 12:00:00'
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-07-01 12:00:00', renewal_count: 0, renewal_limit: 2 );

		$result = $this->service->renew( 1, 7 );

		self::assertIsArray( $result );
		// 2026-07-01 + 14 days = 2026-07-15
		self::assertSame( '2026-07-15 12:00:00', $result['due_at'] );
		self::assertSame( 1, (int) $result['renewal_count'] );
		self::assertNotNull( $result['last_renewed_at'] );
	}

	public function test_renew_extends_from_now_when_due_at_is_past(): void {
		// due_at already passed; base should be current_time = '2026-06-19 12:00:00'
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-01-01 00:00:00', renewal_count: 0, renewal_limit: 1 );

		$result = $this->service->renew( 1, 7 );

		self::assertIsArray( $result );
		// '2026-06-19 12:00:00' + 14 days = '2026-07-03 12:00:00'
		self::assertSame( '2026-07-03 12:00:00', $result['due_at'] );
	}

	public function test_renew_increments_renewal_count(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 1, renewal_limit: 3 );

		$result = $this->service->renew( 1, 7 );

		self::assertIsArray( $result );
		self::assertSame( 2, (int) $result['renewal_count'] );
	}

	// -------------------------------------------------------------------------
	// renew — renewal limit = one by default + waitlist block (acceptance #5)
	// -------------------------------------------------------------------------

	public function test_checkout_creates_loan_with_renewal_limit_one(): void {
		$this->seed_copy( 1 );

		$result = $this->service->checkout( 1, 101, 7 );

		self::assertIsArray( $result );
		self::assertSame( 1, (int) $result['renewal_limit'] );
	}

	public function test_renew_blocked_when_waitlisted_reservation_exists_for_book(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2, book_post_id: 101 );
		$this->seed_reservation( 1, book_post_id: 101, status: 'waitlisted' );

		$result = $this->service->renew( 1, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_renewal_waitlisted', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------------
	// renew — wrong-borrower denial
	// -------------------------------------------------------------------------

	public function test_renew_fails_when_loan_belongs_to_different_borrower(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2 );

		$result = $this->service->renew( 1, borrower_id: 99 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_wrong_borrower', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------------
	// renew — renewal limit denial
	// -------------------------------------------------------------------------

	public function test_renew_fails_when_renewal_count_equals_limit(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 2, renewal_limit: 2 );

		$result = $this->service->renew( 1, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_renewal_limit', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_renew_fails_when_loan_is_not_active(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'returned', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2 );

		$result = $this->service->renew( 1, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_not_active', $result->get_error_code() );
	}

	public function test_renew_fails_when_loan_not_found(): void {
		$result = $this->service->renew( 999, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------------
	// renew — audit row
	// -------------------------------------------------------------------------

	public function test_renew_writes_audit_row_with_action_renew(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2 );

		$this->service->renew( 1, 7 );

		$events = $this->repo->audit_events( 1 );
		self::assertCount( 1, $events );
		self::assertSame( 'renew', $events[0]['action'] );
	}

	public function test_renew_audit_row_stores_actor_context_as_reason(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2 );

		$this->service->renew( 1, 7, 'staff' );

		$events = $this->repo->audit_events( 1 );
		self::assertSame( 'staff', $events[0]['reason'] );
	}

	public function test_renew_returns_conflict_and_skips_audit_when_guarded_update_affects_no_rows(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2 );
		$GLOBALS['connectlibrary_test_db_query_results']['guarded_loan_renewal_update'] = 0;

		$result = $this->service->renew( 1, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_renewal_conflict', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( array(), $this->repo->audit_events( 1 ) );

		$loan = $this->repo->get( 1 );
		self::assertSame( '2026-09-01 00:00:00', $loan['due_at'] );
		self::assertSame( 0, (int) $loan['renewal_count'] );
		self::assertNull( $loan['last_renewed_at'] );
	}

	public function test_renew_returns_error_and_rolls_back_when_audit_insert_fails(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2 );
		$GLOBALS['connectlibrary_test_db_insert_failures'][ $this->audit_table ] = true;

		$result = $this->service->renew( 1, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_renewal_audit_failed', $result->get_error_code() );
		self::assertSame( 500, $result->get_error_data()['status'] );
		self::assertSame( array(), $this->repo->audit_events( 1 ) );

		$loan = $this->repo->get( 1 );
		self::assertSame( '2026-09-01 00:00:00', $loan['due_at'] );
		self::assertSame( 0, (int) $loan['renewal_count'] );
		self::assertNull( $loan['last_renewed_at'] );
	}

	// -------------------------------------------------------------------------
	// change_due_date — stale/non-active guard
	// -------------------------------------------------------------------------

	public function test_change_due_date_returns_conflict_and_skips_audit_when_guarded_update_affects_no_rows(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2 );
		$GLOBALS['connectlibrary_test_db_query_results']['guarded_loan_due_change_update'] = 0;

		$result = $this->service->change_due_date( 1, '2026-10-01 00:00:00', 'stale submit', 'admin' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_due_change_conflict', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( array(), $this->repo->audit_events( 1 ) );

		$loan = $this->repo->get( 1 );
		self::assertSame( '2026-09-01 00:00:00', $loan['due_at'] );
		self::assertSame( 0, (int) $loan['renewal_count'] );
	}

	public function test_change_due_date_rejects_non_active_loan_without_audit(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'returned', due_at: '2026-09-01 00:00:00', renewal_count: 0, renewal_limit: 2 );

		$result = $this->service->change_due_date( 1, '2026-10-01 00:00:00', 'late edit', 'admin' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_not_active', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
		self::assertSame( array(), $this->repo->audit_events( 1 ) );

		$loan = $this->repo->get( 1 );
		self::assertSame( '2026-09-01 00:00:00', $loan['due_at'] );
	}

	// -------------------------------------------------------------------------
	// mark_copy_damaged / lost / retired (acceptance #6)
	// -------------------------------------------------------------------------

	public function test_mark_copy_damaged_removes_from_availability(): void {
		$this->seed_copy( 1, circulation_status: 'available', item_status: 'active' );

		$result = $this->service->mark_copy_damaged( 1 );

		self::assertIsArray( $result );
		self::assertSame( 'damaged', $result['item_status'] );
		self::assertSame( 'damaged', $result['circulation_status'] );
	}

	public function test_mark_copy_lost_removes_from_availability(): void {
		$this->seed_copy( 1, circulation_status: 'available', item_status: 'active' );

		$result = $this->service->mark_copy_lost( 1 );

		self::assertIsArray( $result );
		self::assertSame( 'lost', $result['item_status'] );
		self::assertSame( 'lost', $result['circulation_status'] );
	}

	public function test_mark_copy_damaged_when_checked_out_updates_item_status_only(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out', item_status: 'active' );

		$result = $this->service->mark_copy_damaged( 1 );

		self::assertIsArray( $result );
		self::assertSame( 'damaged', $result['item_status'] );
		// circulation_status must remain checked_out so the active loan is intact
		self::assertSame( 'checked_out', $result['circulation_status'] );
	}

	public function test_mark_copy_retired_removes_from_availability(): void {
		$this->seed_copy( 1, circulation_status: 'available', item_status: 'active' );

		$result = $this->service->mark_copy_retired( 1 );

		self::assertIsArray( $result );
		self::assertSame( 'retired', $result['item_status'] );
		self::assertSame( 'retired', $result['circulation_status'] );
	}

	public function test_mark_copy_retired_rejects_checked_out_copy(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out', item_status: 'active' );

		$result = $this->service->mark_copy_retired( 1 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_copy_checked_out', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );

		// Copy must be unchanged.
		$copy = $this->copy_repo->get( 1 );
		self::assertSame( 'checked_out', $copy['circulation_status'] );
	}

	public function test_mark_copy_damaged_rejects_already_retired_copy(): void {
		$this->seed_copy( 1, circulation_status: 'retired', item_status: 'retired' );

		$result = $this->service->mark_copy_damaged( 1 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_copy_retired', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_mark_copy_damaged_writes_audit_with_actor_context(): void {
		$this->seed_copy( 1, circulation_status: 'available', item_status: 'active' );

		$result = $this->service->mark_copy_damaged( 1, 'staff-damaged-note' );

		self::assertIsArray( $result );
		$events = $this->repo->audit_events( 0 );
		self::assertCount( 1, $events );
		self::assertSame( 'copy_damaged', $events[0]['action'] );
		self::assertSame( 'staff-damaged-note', $events[0]['reason'] );
		self::assertStringContainsString( 'copy_id:1', (string) $events[0]['changed_fields'] );
	}

	public function test_mark_copy_lost_when_checked_out_writes_audit_against_current_loan(): void {
		$this->seed_copy( 1, circulation_status: 'checked_out', item_status: 'active', current_loan_id: 5 );
		$this->seed_loan( 5, borrower_id: 7, status: 'active', copy_id: 1, book_post_id: 101 );

		$result = $this->service->mark_copy_lost( 1, 'staff-lost-note' );

		self::assertIsArray( $result );
		$events = $this->repo->audit_events( 5 );
		self::assertCount( 1, $events );
		self::assertSame( 'copy_lost', $events[0]['action'] );
		self::assertSame( 'staff-lost-note', $events[0]['reason'] );
		self::assertStringContainsString( 'item_status', (string) $events[0]['changed_fields'] );
		self::assertStringNotContainsString( 'circulation_status', (string) $events[0]['changed_fields'] );
	}

	public function test_mark_copy_retired_writes_audit_with_actor_context(): void {
		$this->seed_copy( 1, circulation_status: 'available', item_status: 'active' );

		$result = $this->service->mark_copy_retired( 1, 'staff-retired-note' );

		self::assertIsArray( $result );
		$events = $this->repo->audit_events( 0 );
		self::assertCount( 1, $events );
		self::assertSame( 'copy_retired', $events[0]['action'] );
		self::assertSame( 'staff-retired-note', $events[0]['reason'] );
	}

	// -------------------------------------------------------------------------
	// void_loan (acceptance #7)
	// -------------------------------------------------------------------------

	public function test_void_loan_sets_voided_status_and_stores_note(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active' );

		$result = $this->service->void_loan( 1, 'Data entry error', 'admin' );

		self::assertIsArray( $result );
		self::assertSame( 'voided', $result['status'] );
		self::assertSame( 'Data entry error', $result['correction_note'] );
	}

	public function test_void_loan_preserves_row_not_deleted(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active' );

		$this->service->void_loan( 1, 'Corrected', 'admin' );

		// Row must still exist, just with voided status.
		$loan = $this->repo->get( 1 );
		self::assertNotNull( $loan );
		self::assertSame( 'voided', $loan['status'] );
	}

	public function test_void_loan_requires_non_empty_correction_note(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active' );

		$result = $this->service->void_loan( 1, '' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_invalid', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_void_loan_fails_when_already_voided(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'voided' );

		$result = $this->service->void_loan( 1, 'Second void attempt' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_already_voided', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_void_loan_writes_audit_row(): void {
		$this->seed_loan( 1, borrower_id: 7, status: 'active' );

		$this->service->void_loan( 1, 'Corrected' );

		$events = $this->repo->audit_events( 1 );
		self::assertCount( 1, $events );
		self::assertSame( 'void', $events[0]['action'] );
	}

	// -------------------------------------------------------------------------
	// is_eligible_for_renewal
	// -------------------------------------------------------------------------

	public function test_is_eligible_for_renewal_returns_true_when_under_limit(): void {
		$loan = array( 'renewal_count' => 1, 'renewal_limit' => 2 );

		self::assertTrue( $this->service->is_eligible_for_renewal( $loan ) );
	}

	public function test_is_eligible_for_renewal_returns_false_when_at_limit(): void {
		$loan = array( 'renewal_count' => 2, 'renewal_limit' => 2 );

		self::assertFalse( $this->service->is_eligible_for_renewal( $loan ) );
	}

	// -------------------------------------------------------------------------
	// Fixed enum helpers (acceptance #9)
	// -------------------------------------------------------------------------

	public function test_valid_copy_circulation_statuses_are_accepted(): void {
		foreach ( array( 'available', 'on_hold', 'checked_out', 'damaged', 'lost', 'retired' ) as $status ) {
			self::assertTrue( Statuses::is_valid_copy_status( $status ), "Expected '{$status}' to be valid" );
		}
	}

	public function test_invalid_copy_circulation_status_is_rejected(): void {
		self::assertFalse( Statuses::is_valid_copy_status( 'borrower_id' ) );
		self::assertFalse( Statuses::is_valid_copy_status( '' ) );
		self::assertFalse( Statuses::is_valid_copy_status( 'active' ) ); // item_status, not circulation
	}

	public function test_valid_loan_statuses_are_accepted(): void {
		foreach ( array( 'active', 'returned', 'overdue', 'lost', 'voided' ) as $status ) {
			self::assertTrue( Statuses::is_valid_loan_status( $status ), "Expected '{$status}' to be valid" );
		}
	}

	public function test_invalid_loan_status_is_rejected(): void {
		self::assertFalse( Statuses::is_valid_loan_status( 'available' ) );
		self::assertFalse( Statuses::is_valid_loan_status( '' ) );
		self::assertFalse( Statuses::is_valid_loan_status( 'cancelled' ) );
	}

	public function test_valid_condition_statuses_are_accepted(): void {
		foreach ( array( 'new', 'good', 'fair', 'poor' ) as $cond ) {
			self::assertTrue( Statuses::is_valid_condition_status( $cond ), "Expected '{$cond}' to be valid" );
		}
	}

	public function test_invalid_condition_status_is_rejected(): void {
		self::assertFalse( Statuses::is_valid_condition_status( 'excellent' ) );
		self::assertFalse( Statuses::is_valid_condition_status( '' ) );
		self::assertFalse( Statuses::is_valid_condition_status( 'active' ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Seed a loan row into the fake DB.
	 */
	private function seed_loan(
		int $id,
		int $borrower_id = 1,
		string $status = 'active',
		string $due_at = '2026-09-01 00:00:00',
		int $renewal_count = 0,
		int $renewal_limit = 2,
		int $book_post_id = 101,
		?int $copy_id = null,
		?string $correction_note = null
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
			'correction_note' => $correction_note,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
	}

	/**
	 * Seed a copy row into the fake DB.
	 */
	private function seed_copy(
		int $id,
		int $book_post_id = 101,
		string $circulation_status = 'available',
		string $item_status = 'active',
		string $visibility = 'public',
		?int $current_loan_id = null
	): void {
		$now = '2026-06-19 12:00:00';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ][] = array(
			'id'                 => $id,
			'book_post_id'       => $book_post_id,
			'copy_number'        => 1,
			'item_status'        => $item_status,
			'circulation_status' => $circulation_status,
			'visibility'         => $visibility,
			'current_loan_id'    => $current_loan_id,
			'created_at'         => $now,
			'updated_at'         => $now,
		);
	}

	/**
	 * Seed a reservation row into the fake DB.
	 */
	private function seed_reservation(
		int $id,
		int $book_post_id = 101,
		string $status = 'waitlisted',
		?int $borrower_id = null,
		?int $copy_id = null
	): void {
		$now = '2026-06-19 12:00:00';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ][] = array(
			'id'              => $id,
			'book_post_id'    => $book_post_id,
			'borrower_id'     => $borrower_id,
			'copy_id'         => $copy_id,
			'status'          => $status,
			'hold_expires_at' => null,
			'requested_at'    => $now,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
	}
}
