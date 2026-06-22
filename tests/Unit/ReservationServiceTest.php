<?php
/**
 * Tests for ReservationService business logic.
 *
 * Covers: duplicate prevention, approval conflict, expiration/cancellation
 * releasing availability, audit rows, and notification routing seam.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Support\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * Service-layer tests; fakes all WPDB calls via the in-memory stub.
 */
final class ReservationServiceTest extends TestCase {

	private ReservationRepository $repo;
	private ReservationService    $service;

	/** Table row keys. */
	private string $res_table;
	private string $audit_table;
	private string $copies_table;
	private string $borrowers_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->res_table      = $tables['reservations'] . ':rows';
		$this->audit_table    = $tables['reservation_audit'] . ':rows';
		$this->copies_table   = $tables['copies'] . ':rows';
		$this->borrowers_table = $tables['borrowers'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ]       = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_table ]     = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ]    = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ] = array();
		$GLOBALS['connectlibrary_test_post_meta']                           = array();
		$GLOBALS['connectlibrary_test_current_user_id']                     = 1;

		$this->repo    = new ReservationRepository();
		$this->service = new ReservationService( $this->repo, new BorrowerRepository() );
	}

	// -------------------------------------------------------------------------
	// request_hold — logged-in borrower
	// -------------------------------------------------------------------------

	public function test_request_hold_creates_active_hold_with_copy(): void {
		$this->seed_copy( 1, 101 );
		$result = $this->service->request_hold( 7, 101 );

		self::assertIsArray( $result );
		$res = $result['reservation'];
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $res['status'] );
		self::assertSame( 1, (int) $res['copy_id'] );
		self::assertSame( 7, (int) $res['borrower_id'] );
		self::assertNotEmpty( $res['hold_expires_at'] );
	}

	public function test_request_hold_fails_on_duplicate_borrower_book(): void {
		$this->seed_copy( 1, 101 );
		$this->service->request_hold( 7, 101 );
		$result = $this->service->request_hold( 7, 101 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_duplicate', $result->get_error_code() );
	}

	public function test_request_hold_fails_when_no_copy_available(): void {
		$result = $this->service->request_hold( 7, 101 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_no_copy', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_request_hold_requires_available_copy_status(): void {
		$book_id = 101;
		foreach ( array( Statuses::COPY_CHECKED_OUT, Statuses::COPY_ON_HOLD, Statuses::COPY_DAMAGED ) as $status ) {
			$this->seed_copy( $book_id, $book_id, $status );

			$result = $this->service->request_hold( $book_id, $book_id );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'connectlibrary_reservation_no_copy', $result->get_error_code() );
			++$book_id;
		}
	}

	public function test_request_hold_fails_when_all_copies_held(): void {
		$this->seed_copy( 1, 101 );
		$this->service->request_hold( 7, 101 ); // holds copy 1

		$result = $this->service->request_hold( 8, 101 ); // no free copy left
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_no_copy', $result->get_error_code() );
	}

	public function test_request_hold_duplicate_check_ignores_terminal_reservations(): void {
		$this->seed_copy( 1, 101 );
		$this->seed_copy( 2, 101 );

		$result = $this->service->request_hold( 7, 101 );
		self::assertIsArray( $result );
		$this->service->cancel( (int) $result['reservation']['id'] );

		// After cancellation (terminal), a new request should succeed.
		$this->seed_copy( 3, 101 );
		$result2 = $this->service->request_hold( 7, 101 );
		self::assertIsArray( $result2 );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $result2['reservation']['status'] );
	}

	// -------------------------------------------------------------------------
	// request_guest — guest pending request
	// -------------------------------------------------------------------------

	public function test_request_guest_creates_pending_approval_without_copy(): void {
		$result = $this->service->request_guest( 'guest@example.com', 'Jane Guest', 101 );

		self::assertIsArray( $result );
		$res = $result['reservation'];
		self::assertSame( ReservationStatuses::PENDING_APPROVAL, $res['status'] );
		self::assertNull( $res['copy_id'] ?? null );
		self::assertSame( 'guest@example.com', $res['guest_email'] );
	}

	public function test_request_guest_fails_on_duplicate_email_book(): void {
		$this->service->request_guest( 'guest@example.com', 'Jane', 101 );
		$result = $this->service->request_guest( 'guest@example.com', 'Jane Again', 101 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_duplicate', $result->get_error_code() );
	}

	public function test_request_guest_duplicate_ignores_terminal(): void {
		$r = $this->service->request_guest( 'guest@example.com', 'Jane', 101 );
		$this->service->deny( (int) $r['reservation']['id'] );

		$result = $this->service->request_guest( 'guest@example.com', 'Jane', 101 );
		self::assertIsArray( $result );
	}

	public function test_request_guest_rejects_invalid_email(): void {
		$result = $this->service->request_guest( 'not-an-email', 'Jane', 101 );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_invalid', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// approve
	// -------------------------------------------------------------------------

	public function test_approve_assigns_copy_and_activates_hold(): void {
		$this->seed_copy( 10, 101 );
		$guest = $this->service->request_guest( 'guest@example.com', 'Jane', 101 );
		$id    = (int) $guest['reservation']['id'];

		$result = $this->service->approve( $id );

		self::assertIsArray( $result );
		$res = $result['reservation'];
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $res['status'] );
		self::assertSame( 10, (int) $res['copy_id'] );
		self::assertNotEmpty( $res['hold_expires_at'] );
	}

	public function test_approve_without_copy_places_to_waitlisted(): void {
		$guest = $this->service->request_guest( 'guest@example.com', 'Jane', 101 );
		$id    = (int) $guest['reservation']['id'];

		$result = $this->service->approve( $id );
		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::WAITLISTED, $result['reservation']['status'] );
		self::assertSame( 'waitlist_approved', $result['notification']['type'] );
		self::assertSame( 'guest@example.com', $result['notification']['to'] );
	}

	public function test_approve_with_non_available_copy_places_to_waitlisted(): void {
		$this->seed_copy( 10, 101, Statuses::COPY_CHECKED_OUT );
		$guest = $this->service->request_guest( 'guest@example.com', 'Jane', 101 );

		$result = $this->service->approve( (int) $guest['reservation']['id'] );

		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::WAITLISTED, $result['reservation']['status'] );
		self::assertNull( $result['reservation']['copy_id'] ?? null );
		self::assertSame( 'waitlist_approved', $result['notification']['type'] );
	}

	public function test_approve_when_all_copies_held_places_to_waitlisted(): void {
		$this->seed_copy( 10, 101 );

		// Consume the only copy with a borrower hold.
		$this->service->request_hold( 7, 101 );

		$guest  = $this->service->request_guest( 'guest@example.com', 'Jane', 101 );
		$result = $this->service->approve( (int) $guest['reservation']['id'] );

		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::WAITLISTED, $result['reservation']['status'] );
	}

	public function test_approve_fails_on_non_pending_reservation(): void {
		$this->seed_copy( 10, 101 );
		$hold = $this->service->request_hold( 7, 101 );
		$id   = (int) $hold['reservation']['id'];

		$result = $this->service->approve( $id );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_invalid_transition', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// deny / cancel
	// -------------------------------------------------------------------------

	public function test_deny_transitions_pending_to_denied(): void {
		$r    = $this->service->request_guest( 'g@example.com', 'G', 101 );
		$id   = (int) $r['reservation']['id'];
		$result = $this->service->deny( $id, 'out of scope' );

		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::DENIED, $result['reservation']['status'] );
	}

	public function test_cancel_transitions_active_hold(): void {
		$this->seed_copy( 1, 101 );
		$r  = $this->service->request_hold( 7, 101 );
		$id = (int) $r['reservation']['id'];

		$result = $this->service->cancel( $id );
		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::CANCELLED, $result['reservation']['status'] );
	}

	public function test_cancel_fails_on_already_terminal_reservation(): void {
		$this->seed_copy( 1, 101 );
		$r  = $this->service->request_hold( 7, 101 );
		$id = (int) $r['reservation']['id'];
		$this->service->expire( $id );

		$result = $this->service->cancel( $id );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_reservation_invalid_transition', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// extend
	// -------------------------------------------------------------------------

	public function test_extend_updates_hold_expires_at(): void {
		$this->seed_copy( 1, 101 );
		$r  = $this->service->request_hold( 7, 101 );
		$id = (int) $r['reservation']['id'];

		$new_expiry = '2027-01-01 00:00:00';
		$result     = $this->service->extend( $id, $new_expiry );

		self::assertIsArray( $result );
		self::assertSame( $new_expiry, $result['reservation']['hold_expires_at'] );
	}

	public function test_extend_fails_on_non_active_hold(): void {
		$r      = $this->service->request_guest( 'g@example.com', 'G', 101 );
		$result = $this->service->extend( (int) $r['reservation']['id'] );

		self::assertInstanceOf( \WP_Error::class, $result );
	}

	// -------------------------------------------------------------------------
	// expire / expire_due_holds
	// -------------------------------------------------------------------------

	public function test_expire_transitions_active_hold_to_expired(): void {
		$this->seed_copy( 1, 101 );
		$r  = $this->service->request_hold( 7, 101 );
		$id = (int) $r['reservation']['id'];

		$result = $this->service->expire( $id );
		self::assertIsArray( $result );
		self::assertSame( ReservationStatuses::EXPIRED, $result['reservation']['status'] );
	}

	public function test_expire_due_holds_expires_overdue_only(): void {
		$this->seed_copy( 1, 101 );
		$this->seed_copy( 2, 202 );

		$r1 = $this->service->request_hold( 7, 101 );
		$r2 = $this->service->request_hold( 8, 202 );

		// Set one as overdue, leave the other in the future.
		$this->repo->update( (int) $r1['reservation']['id'], array( 'hold_expires_at' => '2020-01-01 00:00:00' ) );

		$count = $this->service->expire_due_holds();
		self::assertSame( 1, $count );
		self::assertSame( ReservationStatuses::EXPIRED, $this->repo->get( (int) $r1['reservation']['id'] )['status'] );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $this->repo->get( (int) $r2['reservation']['id'] )['status'] );
	}

	// -------------------------------------------------------------------------
	// Availability integration — expiry/cancellation releases copy
	// -------------------------------------------------------------------------

	public function test_active_hold_marks_book_reserved_when_all_copies_held(): void {
		$this->seed_copy( 1, 101 );
		$GLOBALS['connectlibrary_test_post_meta'][101][ Availability::META_VISIBILITY ] = 'public';

		$this->service->request_hold( 7, 101 );

		$response = Availability::for_book( 101 );
		self::assertSame( 'reserved', $response['status'] );
	}

	public function test_expiry_releases_copy_and_restores_available_status(): void {
		$this->seed_copy( 1, 101 );
		$GLOBALS['connectlibrary_test_post_meta'][101][ Availability::META_VISIBILITY ] = 'public';

		$r  = $this->service->request_hold( 7, 101 );
		$id = (int) $r['reservation']['id'];

		self::assertSame( 'reserved', Availability::for_book( 101 )['status'] );

		$this->service->expire( $id );

		self::assertSame( 'available', Availability::for_book( 101 )['status'] );
	}

	public function test_cancellation_releases_copy_and_restores_available_status(): void {
		$this->seed_copy( 1, 101 );

		$r  = $this->service->request_hold( 7, 101 );
		$id = (int) $r['reservation']['id'];
		$this->service->cancel( $id );

		$response = Availability::for_book( 101 );
		self::assertSame( 'available', $response['status'] );
	}

	public function test_pending_guest_request_does_not_block_availability(): void {
		$this->seed_copy( 1, 101 );

		$this->service->request_guest( 'g@example.com', 'G', 101 );

		self::assertSame( 'available', Availability::for_book( 101 )['status'] );
	}

	// -------------------------------------------------------------------------
	// Audit rows
	// -------------------------------------------------------------------------

	public function test_request_hold_writes_audit_row(): void {
		$this->seed_copy( 1, 101 );
		$r  = $this->service->request_hold( 7, 101 );
		$id = (int) $r['reservation']['id'];

		$events = $this->repo->audit_events( $id );
		self::assertCount( 1, $events );
		self::assertSame( 'request_hold', $events[0]['action'] );
	}

	public function test_approve_writes_audit_row_with_correct_statuses(): void {
		$this->seed_copy( 1, 101 );
		$r  = $this->service->request_guest( 'g@example.com', 'G', 101 );
		$id = (int) $r['reservation']['id'];
		$this->service->approve( $id );

		$events = $this->repo->audit_events( $id );
		$approve = array_values( array_filter( $events, fn( $e ) => 'approve' === $e['action'] ) );
		self::assertCount( 1, $approve );
		self::assertSame( ReservationStatuses::PENDING_APPROVAL, $approve[0]['from_status'] );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $approve[0]['to_status'] );
	}

	public function test_expire_writes_audit_row(): void {
		$this->seed_copy( 1, 101 );
		$r  = $this->service->request_hold( 7, 101 );
		$id = (int) $r['reservation']['id'];
		$this->service->expire( $id );

		$events = $this->repo->audit_events( $id );
		$expire = array_values( array_filter( $events, fn( $e ) => 'expire' === $e['action'] ) );
		self::assertCount( 1, $expire );
		self::assertSame( ReservationStatuses::EXPIRED, $expire[0]['to_status'] );
	}

	// -------------------------------------------------------------------------
	// Notification routing seam
	// -------------------------------------------------------------------------

	public function test_notification_routes_to_borrower_email(): void {
		$this->seed_copy( 1, 101 );
		$this->seed_borrower( 7, 'borrower@example.com' );

		$result = $this->service->request_hold( 7, 101 );
		self::assertSame( 'borrower@example.com', $result['notification']['to'] );
		self::assertSame( 'hold_placed', $result['notification']['type'] );
	}

	public function test_notification_routes_child_to_guardian_email(): void {
		$this->seed_copy( 1, 101 );
		$this->seed_borrower( 7, 'child@example.com', 'guardian@example.com' );

		$result = $this->service->request_hold( 7, 101 );
		self::assertSame( 'guardian@example.com', $result['notification']['to'] );
	}

	public function test_guest_notification_routes_to_guest_email(): void {
		$result = $this->service->request_guest( 'patron@example.com', 'Patron', 101 );
		self::assertSame( 'patron@example.com', $result['notification']['to'] );
		self::assertSame( 'guest_request_received', $result['notification']['type'] );
	}

	public function test_approve_guest_reservation_routes_notification_to_guest_email(): void {
		$this->seed_copy( 1, 101 );
		$r  = $this->service->request_guest( 'patron@example.com', 'Patron', 101 );
		$result = $this->service->approve( (int) $r['reservation']['id'] );

		self::assertSame( 'patron@example.com', $result['notification']['to'] );
		self::assertSame( 'hold_approved', $result['notification']['type'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Seed an active/public copy row into the fake DB.
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
	 * Seed a borrower row into the fake DB.
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
