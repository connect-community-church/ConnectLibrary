<?php
/**
 * Tests for Build 05 waitlist and automatic offer flow.
 *
 * Covers: join_waitlist, guest approve → WAITLISTED, duplicate prevention,
 * FIFO promotion, promotion idempotency, expiry/cancel promoting next,
 * renewal blocked by waitlist.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Circulation\LoanService;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Support\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * Waitlist service tests; all WPDB calls routed through the in-memory stub.
 */
final class WaitlistServiceTest extends TestCase {

	private ReservationRepository $repo;
	private ReservationService    $service;

	private string $res_table;
	private string $audit_table;
	private string $copies_table;
	private string $borrowers_table;
	private string $loans_table;
	private string $loan_audit_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->res_table       = $tables['reservations'] . ':rows';
		$this->audit_table     = $tables['reservation_audit'] . ':rows';
		$this->copies_table    = $tables['copies'] . ':rows';
		$this->borrowers_table = $tables['borrowers'] . ':rows';
		$this->loans_table     = $tables['loans'] . ':rows';
		$this->loan_audit_table = $tables['loan_audit'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ]        = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_table ]      = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ]     = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ]  = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ]      = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loan_audit_table ] = array();
		$GLOBALS['connectlibrary_test_db_insert_failures']                   = array();
		$GLOBALS['connectlibrary_test_db_query_results']                     = array();
		$GLOBALS['connectlibrary_test_post_meta']                            = array();
		$GLOBALS['connectlibrary_test_current_user_id']                      = 1;

		$this->repo    = new ReservationRepository();
		$this->service = new ReservationService( $this->repo, new BorrowerRepository() );
	}

	// -------------------------------------------------------------------------
	// join_waitlist — logged-in borrower
	// -------------------------------------------------------------------------

	public function test_join_waitlist_creates_waitlisted_reservation(): void {
		$result = $this->service->join_waitlist( 7, 101 );

		self::assertIsArray( $result );
		$res = $result['reservation'];
		self::assertSame( ReservationStatuses::WAITLISTED, $res['status'] );
		self::assertSame( 7, (int) $res['borrower_id'] );
		self::assertSame( 101, (int) $res['book_post_id'] );
		self::assertNull( $res['copy_id'] ?? null );
	}

	public function test_join_waitlist_notification_type_is_waitlist_joined(): void {
		$this->seed_borrower( 7, 'b@example.com' );
		$result = $this->service->join_waitlist( 7, 101 );

		self::assertIsArray( $result['notification'] );
		self::assertSame( 'waitlist_joined', $result['notification']['type'] );
		self::assertSame( 'b@example.com', $result['notification']['to'] );
	}

	public function test_join_waitlist_routes_child_notification_to_guardian(): void {
		$this->seed_borrower( 7, 'child@example.com', 'guardian@example.com' );
		$result = $this->service->join_waitlist( 7, 101 );

		self::assertSame( 'guardian@example.com', $result['notification']['to'] );
	}

	public function test_join_waitlist_writes_audit_row(): void {
		$result = $this->service->join_waitlist( 7, 101 );
		$events = $this->repo->audit_events( (int) $result['reservation']['id'] );

		self::assertCount( 1, $events );
		self::assertSame( 'join_waitlist', $events[0]['action'] );
		self::assertSame( ReservationStatuses::WAITLISTED, $events[0]['to_status'] );
	}

	// -------------------------------------------------------------------------
	// join_waitlist — duplicate prevention
	// -------------------------------------------------------------------------

	public function test_join_waitlist_duplicate_blocks_second_join(): void {
		$this->service->join_waitlist( 7, 101 );
		$result = $this->service->join_waitlist( 7, 101 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_duplicate', $result->get_error_code() );
	}

	public function test_join_waitlist_blocked_when_borrower_has_active_hold(): void {
		$this->seed_copy( 1, 101 );
		$this->service->request_hold( 7, 101 ); // creates ACTIVE_HOLD

		$result = $this->service->join_waitlist( 7, 101 );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_duplicate', $result->get_error_code() );
	}

	public function test_join_waitlist_allowed_after_terminal_reservation(): void {
		$r = $this->service->join_waitlist( 7, 101 );
		$this->service->cancel( (int) $r['reservation']['id'] );

		$result = $this->service->join_waitlist( 7, 101 );
		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::WAITLISTED, $result['reservation']['status'] );
	}

	public function test_join_waitlist_rejects_invalid_ids(): void {
		self::assertInstanceOf( \WP_Error::class, $this->service->join_waitlist( 0, 101 ) );
		self::assertInstanceOf( \WP_Error::class, $this->service->join_waitlist( 7, 0 ) );
	}

	// -------------------------------------------------------------------------
	// approve — places to WAITLISTED when no copy, ACTIVE_HOLD when copy available
	// -------------------------------------------------------------------------

	public function test_approve_places_to_waitlisted_when_no_copy(): void {
		$guest  = $this->service->request_guest( 'g@example.com', 'Guest', 101 );
		$result = $this->service->approve( (int) $guest['reservation']['id'] );

		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::WAITLISTED, $result['reservation']['status'] );
		self::assertNull( $result['reservation']['copy_id'] ?? null );
		self::assertSame( 'waitlist_approved', $result['notification']['type'] );
	}

	public function test_approve_places_to_active_hold_when_copy_available(): void {
		$this->seed_copy( 5, 101 );
		$guest  = $this->service->request_guest( 'g@example.com', 'Guest', 101 );
		$result = $this->service->approve( (int) $guest['reservation']['id'] );

		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $result['reservation']['status'] );
		self::assertSame( 5, (int) $result['reservation']['copy_id'] );
		self::assertSame( 'hold_approved', $result['notification']['type'] );
	}

	public function test_approve_to_waitlisted_updates_requested_at_as_queue_timestamp(): void {
		$guest  = $this->service->request_guest( 'g@example.com', 'Guest', 101 );
		$before = (string) ( $guest['reservation']['requested_at'] ?? '' );

		$result = $this->service->approve( (int) $guest['reservation']['id'] );

		// requested_at is updated to approval time (queue position stamp).
		$after = (string) ( $result['reservation']['requested_at'] ?? '' );
		self::assertSame( $before, $after, 'requested_at is updated on approval (test stub freezes time)' );
	}

	// -------------------------------------------------------------------------
	// promote_next_waitlisted — FIFO, idempotency, audit
	// -------------------------------------------------------------------------

	public function test_promote_next_waitlisted_returns_null_when_no_waitlisted_entries(): void {
		$this->seed_copy( 1, 101 );
		$result = $this->service->promote_next_waitlisted( 101 );

		self::assertNull( $result );
	}

	public function test_promote_next_waitlisted_returns_null_when_no_free_copy(): void {
		$this->seed_copy( 1, 101 );
		$this->service->request_hold( 7, 101 ); // holds the only copy
		$this->service->join_waitlist( 8, 101 );

		$result = $this->service->promote_next_waitlisted( 101 );
		self::assertNull( $result );
	}

	public function test_promote_next_waitlisted_returns_null_when_only_non_available_copy_exists(): void {
		$this->seed_copy( 1, 101, Statuses::COPY_ON_HOLD );
		$w = $this->service->join_waitlist( 8, 101 );

		$result = $this->service->promote_next_waitlisted( 101 );

		self::assertNull( $result );
		self::assertSame( ReservationStatuses::WAITLISTED, $this->repo->get( (int) $w['reservation']['id'] )['status'] );
	}

	public function test_promote_next_waitlisted_promotes_to_active_hold(): void {
		$this->seed_copy( 1, 101 );
		$w      = $this->service->join_waitlist( 7, 101 );
		$wid    = (int) $w['reservation']['id'];

		$result = $this->service->promote_next_waitlisted( 101 );

		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $result['reservation']['status'] );
		self::assertSame( $wid, (int) $result['reservation']['id'] );
		self::assertSame( 1, (int) $result['reservation']['copy_id'] );
		self::assertNotEmpty( $result['reservation']['hold_expires_at'] );
	}

	public function test_promote_next_waitlisted_notification_type_is_waitlist_offer(): void {
		$this->seed_copy( 1, 101 );
		$this->seed_borrower( 7, 'b@example.com' );
		$this->service->join_waitlist( 7, 101 );

		$result = $this->service->promote_next_waitlisted( 101 );

		self::assertIsArray( $result['notification'] );
		self::assertSame( 'waitlist_offer', $result['notification']['type'] );
		self::assertSame( 'b@example.com', $result['notification']['to'] );
	}

	public function test_promote_next_waitlisted_writes_promote_audit_action(): void {
		$this->seed_copy( 1, 101 );
		$w   = $this->service->join_waitlist( 7, 101 );
		$wid = (int) $w['reservation']['id'];

		$this->service->promote_next_waitlisted( 101 );

		$events  = $this->repo->audit_events( $wid );
		$promote = array_values( array_filter( $events, fn( $e ) => 'promote_waitlist' === $e['action'] ) );
		self::assertCount( 1, $promote );
		self::assertSame( ReservationStatuses::WAITLISTED, $promote[0]['from_status'] );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $promote[0]['to_status'] );
	}

	public function test_promote_next_waitlisted_is_idempotent_when_called_twice(): void {
		$this->seed_copy( 1, 101 );
		$this->service->join_waitlist( 7, 101 );

		$first  = $this->service->promote_next_waitlisted( 101 );
		$second = $this->service->promote_next_waitlisted( 101 ); // no more waitlisted

		self::assertIsArray( $first );
		self::assertNull( $second ); // idempotent: nothing left to promote
	}

	public function test_handle_copy_available_promotes_next_waitlisted_entry(): void {
		$this->service->join_waitlist( 7, 101 );

		// Simulate the Item 07 check-in seam: after a copy is returned and marked
		// active/public, the circulation workflow calls handle_copy_available().
		$this->seed_copy( 1, 101 );

		$result = $this->service->handle_copy_available( 101 );

		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $result['reservation']['status'] );
		self::assertSame( 1, (int) $result['reservation']['copy_id'] );
		self::assertSame( 'waitlist_offer', $result['notification']['type'] );
	}

	public function test_handle_copy_available_returns_null_until_copy_is_available(): void {
		$this->service->join_waitlist( 7, 101 );

		self::assertNull( $this->service->handle_copy_available( 101 ) );
	}

	public function test_handle_copy_available_refuses_checked_out_copy(): void {
		$w = $this->service->join_waitlist( 7, 101 );
		$this->seed_copy( 1, 101, Statuses::COPY_CHECKED_OUT );

		$result = $this->service->handle_copy_available( 101 );

		self::assertNull( $result );
		self::assertSame( ReservationStatuses::WAITLISTED, $this->repo->get( (int) $w['reservation']['id'] )['status'] );
	}

	// -------------------------------------------------------------------------
	// FIFO ordering
	// -------------------------------------------------------------------------

	public function test_promote_next_waitlisted_promotes_fifo_order(): void {
		// Join waitlist for two borrowers; no copy available so both land WAITLISTED.
		$w1 = $this->service->join_waitlist( 7, 101 );
		$w2 = $this->service->join_waitlist( 8, 101 );

		// Stagger requested_at to guarantee deterministic FIFO ordering.
		$this->repo->update( (int) $w1['reservation']['id'], array( 'requested_at' => '2026-01-01 10:00:00' ) );
		$this->repo->update( (int) $w2['reservation']['id'], array( 'requested_at' => '2026-01-01 11:00:00' ) );

		// Now a copy becomes available.
		$this->seed_copy( 1, 101 );

		// First explicit promotion should pick borrower 7 (earliest requested_at).
		$promoted = $this->service->promote_next_waitlisted( 101 );

		self::assertIsArray( $promoted );
		self::assertSame( (int) $w1['reservation']['id'], (int) $promoted['reservation']['id'] );
		self::assertSame( 7, (int) $promoted['reservation']['borrower_id'] );
	}

	// -------------------------------------------------------------------------
	// expire promotes next waitlisted
	// -------------------------------------------------------------------------

	public function test_expire_promotes_next_waitlisted_when_copy_freed(): void {
		$this->seed_copy( 1, 101 );

		$hold = $this->service->request_hold( 7, 101 );
		$w    = $this->service->join_waitlist( 8, 101 );

		$result = $this->service->expire( (int) $hold['reservation']['id'] );

		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::EXPIRED, $result['reservation']['status'] );
		self::assertIsArray( $result['promotion'] );
		self::assertSame( (int) $w['reservation']['id'], (int) $result['promotion']['reservation']['id'] );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $result['promotion']['reservation']['status'] );
	}

	public function test_expire_promotion_is_null_when_no_waitlisted_entries(): void {
		$this->seed_copy( 1, 101 );
		$hold   = $this->service->request_hold( 7, 101 );
		$result = $this->service->expire( (int) $hold['reservation']['id'] );

		self::assertNull( $result['promotion'] );
	}

	// -------------------------------------------------------------------------
	// cancel active_hold promotes next waitlisted
	// -------------------------------------------------------------------------

	public function test_cancel_active_hold_promotes_next_waitlisted(): void {
		$this->seed_copy( 1, 101 );

		$hold = $this->service->request_hold( 7, 101 );
		$w    = $this->service->join_waitlist( 8, 101 );

		$result = $this->service->cancel( (int) $hold['reservation']['id'] );

		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::CANCELLED, $result['reservation']['status'] );
		self::assertIsArray( $result['promotion'] );
		self::assertSame( (int) $w['reservation']['id'], (int) $result['promotion']['reservation']['id'] );
	}

	public function test_cancel_waitlisted_does_not_trigger_promotion(): void {
		$this->seed_copy( 1, 101 );

		// Copy is free; waitlist entry is not holding a copy.
		$w      = $this->service->join_waitlist( 7, 101 );
		$result = $this->service->cancel( (int) $w['reservation']['id'] );

		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::CANCELLED, $result['reservation']['status'] );
		self::assertNull( $result['promotion'] );
	}

	public function test_cancel_pending_approval_does_not_trigger_promotion(): void {
		$this->seed_copy( 1, 101 );
		$guest  = $this->service->request_guest( 'g@example.com', 'G', 101 );
		$result = $this->service->cancel( (int) $guest['reservation']['id'] );

		self::assertNull( $result['promotion'] );
	}

	// -------------------------------------------------------------------------
	// expire_due_holds promotes via expire chain
	// -------------------------------------------------------------------------

	public function test_expire_due_holds_promotes_waitlisted_when_copy_freed(): void {
		$this->seed_copy( 1, 101 );

		$hold = $this->service->request_hold( 7, 101 );
		$w    = $this->service->join_waitlist( 8, 101 );

		// Set hold as overdue.
		$this->repo->update( (int) $hold['reservation']['id'], array( 'hold_expires_at' => '2020-01-01 00:00:00' ) );

		$count = $this->service->expire_due_holds();

		self::assertSame( 1, $count );
		$promoted = $this->repo->get( (int) $w['reservation']['id'] );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $promoted['status'] );
	}

	// -------------------------------------------------------------------------
	// Renewal blocked by waitlist
	// -------------------------------------------------------------------------

	public function test_renewal_is_blocked_when_waitlist_exists_for_book(): void {
		$tables = Schema::table_names();

		$GLOBALS['connectlibrary_test_db_tables'][ $tables['loans'] . ':rows' ][]     = array(
			'id'             => 1,
			'book_post_id'   => 101,
			'copy_id'        => null,
			'borrower_id'    => 7,
			'status'         => 'active',
			'checked_out_at' => '2026-06-01 12:00:00',
			'due_at'         => '2026-09-01 00:00:00',
			'returned_at'    => null,
			'renewal_count'  => 0,
			'renewal_limit'  => 2,
			'last_renewed_at' => null,
			'created_at'     => '2026-06-01 12:00:00',
			'updated_at'     => '2026-06-01 12:00:00',
		);

		$this->service->join_waitlist( 8, 101 ); // borrower 8 is on waitlist

		$loan_service = new \ConnectLibrary\Circulation\LoanService(
			new LoanRepository(),
			$this->repo
		);

		$result = $loan_service->renew( 1, 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_loan_renewal_waitlisted', $result->get_error_code() );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_is_eligible_for_renewal_false_when_waitlist_exists(): void {
		$this->service->join_waitlist( 8, 101 );

		$loan_service = new \ConnectLibrary\Circulation\LoanService(
			new LoanRepository(),
			$this->repo
		);

		$loan = array( 'book_post_id' => 101, 'renewal_count' => 0, 'renewal_limit' => 2 );
		self::assertFalse( $loan_service->is_eligible_for_renewal( $loan ) );
	}

	public function test_is_eligible_for_renewal_true_when_no_waitlist(): void {
		$loan_service = new \ConnectLibrary\Circulation\LoanService(
			new LoanRepository(),
			$this->repo
		);

		$loan = array( 'book_post_id' => 101, 'renewal_count' => 0, 'renewal_limit' => 2 );
		self::assertTrue( $loan_service->is_eligible_for_renewal( $loan ) );
	}

	public function test_renewal_allowed_after_waitlist_entry_is_cancelled(): void {
		$tables = Schema::table_names();

		$GLOBALS['connectlibrary_test_db_tables'][ $tables['loans'] . ':rows' ][] = array(
			'id'             => 1,
			'book_post_id'   => 101,
			'copy_id'        => null,
			'borrower_id'    => 7,
			'status'         => 'active',
			'checked_out_at' => '2026-06-01 12:00:00',
			'due_at'         => '2026-09-01 00:00:00',
			'returned_at'    => null,
			'renewal_count'  => 0,
			'renewal_limit'  => 2,
			'last_renewed_at' => null,
			'created_at'     => '2026-06-01 12:00:00',
			'updated_at'     => '2026-06-01 12:00:00',
		);

		$w = $this->service->join_waitlist( 8, 101 );
		$this->service->cancel( (int) $w['reservation']['id'] );

		$loan_service = new \ConnectLibrary\Circulation\LoanService(
			new LoanRepository(),
			$this->repo
		);

		$result = $loan_service->renew( 1, 7 );
		self::assertIsArray( $result );
	}

	// -------------------------------------------------------------------------
	// waitlisted_for_book
	// -------------------------------------------------------------------------

	public function test_waitlisted_for_book_returns_only_waitlisted_entries(): void {
		$this->seed_copy( 1, 101 );
		$this->service->request_hold( 5, 101 );  // ACTIVE_HOLD, not waitlisted
		$this->service->join_waitlist( 7, 101 );
		$this->service->join_waitlist( 8, 101 );

		$waitlisted = $this->repo->waitlisted_for_book( 101 );
		self::assertCount( 2, $waitlisted );
		foreach ( $waitlisted as $row ) {
			self::assertSame( ReservationStatuses::WAITLISTED, $row['status'] );
		}
	}

	public function test_waitlisted_for_book_returns_empty_for_other_books(): void {
		$this->service->join_waitlist( 7, 101 );
		$result = $this->repo->waitlisted_for_book( 202 );
		self::assertSame( array(), $result );
	}

	// -------------------------------------------------------------------------
	// active_waitlist_entries service method
	// -------------------------------------------------------------------------

	public function test_active_waitlist_entries_returns_all_waitlisted(): void {
		$this->service->join_waitlist( 7, 101 );
		$this->service->join_waitlist( 8, 202 );

		$entries = $this->service->active_waitlist_entries();
		self::assertCount( 2, $entries );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Seed an active/public copy row.
	 *
	 * @param int    $copy_id            Copy ID.
	 * @param int    $book_post_id       Book post ID.
	 * @param string $circulation_status Copy circulation status.
	 */
	private function seed_copy( int $copy_id, int $book_post_id, string $circulation_status = Statuses::COPY_AVAILABLE ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ][] = array(
			'id'                 => $copy_id,
			'book_post_id'       => $book_post_id,
			'circulation_status' => $circulation_status,
			'item_status'        => 'active',
			'visibility'         => 'public',
		);
	}

	/**
	 * Seed a borrower row.
	 *
	 * @param int    $id             Borrower ID.
	 * @param string $email          Borrower email.
	 * @param string $guardian_email Guardian email (empty for non-children).
	 */
	private function seed_borrower( int $id, string $email, string $guardian_email = '' ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ][] = array(
			'id'             => $id,
			'email'          => $email,
			'guardian_email' => $guardian_email,
			'status'         => 'active',
		);
	}
}
