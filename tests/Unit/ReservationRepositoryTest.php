<?php
/**
 * Tests for ReservationRepository persistence and audit.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Database\Schema;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationStatuses;
use PHPUnit\Framework\TestCase;

/**
 * Verifies insert/update/get/audit round-trips and filtering helpers.
 */
final class ReservationRepositoryTest extends TestCase {

	private ReservationRepository $repo;

	/** Table key used by the fake WPDB for reservations rows. */
	private string $res_table;

	/** Table key used by the fake WPDB for reservation_audit rows. */
	private string $audit_table;

	/** Table key used by the fake WPDB for copies rows. */
	private string $copies_table;

	protected function setUp(): void {
		$tables             = Schema::table_names();
		$this->res_table    = $tables['reservations'] . ':rows';
		$this->audit_table  = $tables['reservation_audit'] . ':rows';
		$this->copies_table = $tables['copies'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ]    = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_table ]  = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ] = array();

		$this->repo = new ReservationRepository();
	}

	public function test_insert_and_get_round_trip(): void {
		$id = $this->repo->insert( $this->sample_row() );

		self::assertGreaterThan( 0, $id );
		$row = $this->repo->get( $id );
		self::assertIsArray( $row );
		self::assertSame( 101, (int) $row['book_post_id'] );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $row['status'] );
	}

	public function test_update_changes_status(): void {
		$id = $this->repo->insert( $this->sample_row() );
		$this->repo->update( $id, array( 'status' => ReservationStatuses::EXPIRED ) );

		self::assertSame( ReservationStatuses::EXPIRED, $this->repo->get( $id )['status'] );
	}

	public function test_get_returns_null_for_missing_id(): void {
		self::assertNull( $this->repo->get( 999 ) );
	}

	public function test_all_returns_inserted_rows(): void {
		$this->repo->insert( $this->sample_row() );
		$this->repo->insert( $this->sample_row( array( 'book_post_id' => 202 ) ) );

		self::assertCount( 2, $this->repo->all() );
	}

	public function test_non_terminal_for_borrower_book_excludes_terminal(): void {
		$this->repo->insert( $this->sample_row( array( 'borrower_id' => 5, 'book_post_id' => 101, 'status' => ReservationStatuses::ACTIVE_HOLD ) ) );
		$this->repo->insert( $this->sample_row( array( 'borrower_id' => 5, 'book_post_id' => 101, 'status' => ReservationStatuses::EXPIRED ) ) );
		$this->repo->insert( $this->sample_row( array( 'borrower_id' => 5, 'book_post_id' => 202, 'status' => ReservationStatuses::ACTIVE_HOLD ) ) );

		$results = $this->repo->non_terminal_for_borrower_book( 5, 101 );
		self::assertCount( 1, $results );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $results[0]['status'] );
	}

	public function test_non_terminal_for_guest_book_excludes_terminal_and_other_books(): void {
		$email = 'guest@example.com';
		$this->repo->insert( $this->sample_row( array( 'guest_email' => $email, 'book_post_id' => 101, 'status' => ReservationStatuses::PENDING_APPROVAL ) ) );
		$this->repo->insert( $this->sample_row( array( 'guest_email' => $email, 'book_post_id' => 101, 'status' => ReservationStatuses::DENIED ) ) );
		$this->repo->insert( $this->sample_row( array( 'guest_email' => $email, 'book_post_id' => 202, 'status' => ReservationStatuses::PENDING_APPROVAL ) ) );

		$results = $this->repo->non_terminal_for_guest_book( $email, 101 );
		self::assertCount( 1, $results );
		self::assertSame( ReservationStatuses::PENDING_APPROVAL, $results[0]['status'] );
	}

	public function test_active_holds_for_book_returns_only_active_hold_rows(): void {
		$this->repo->insert( $this->sample_row( array( 'book_post_id' => 101, 'status' => ReservationStatuses::ACTIVE_HOLD ) ) );
		$this->repo->insert( $this->sample_row( array( 'book_post_id' => 101, 'status' => ReservationStatuses::PENDING_APPROVAL ) ) );
		$this->repo->insert( $this->sample_row( array( 'book_post_id' => 101, 'status' => ReservationStatuses::EXPIRED ) ) );

		$holds = $this->repo->active_holds_for_book( 101 );
		self::assertCount( 1, $holds );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $holds[0]['status'] );
	}

	public function test_active_public_copies_for_book_filters_inactive_and_private(): void {
		$tables = Schema::table_names();
		$key    = $tables['copies'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $key ] = array(
			array( 'id' => 1, 'book_post_id' => 101, 'item_status' => 'active', 'visibility' => 'public' ),
			array( 'id' => 2, 'book_post_id' => 101, 'item_status' => 'inactive', 'visibility' => 'public' ),
			array( 'id' => 3, 'book_post_id' => 101, 'item_status' => 'active', 'visibility' => 'private' ),
			array( 'id' => 4, 'book_post_id' => 202, 'item_status' => 'active', 'visibility' => 'public' ),
		);

		$copies = $this->repo->active_public_copies_for_book( 101 );
		self::assertCount( 1, $copies );
		self::assertSame( 1, (int) $copies[0]['id'] );
	}

	public function test_audit_inserts_row_with_action_and_statuses(): void {
		$id       = $this->repo->insert( $this->sample_row() );
		$audit_id = $this->repo->audit( $id, 'approve', ReservationStatuses::PENDING_APPROVAL, ReservationStatuses::ACTIVE_HOLD, 'test reason' );

		self::assertGreaterThan( 0, $audit_id );
		$events = $this->repo->audit_events( $id );
		self::assertCount( 1, $events );
		self::assertSame( 'approve', $events[0]['action'] );
		self::assertSame( ReservationStatuses::PENDING_APPROVAL, $events[0]['from_status'] );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $events[0]['to_status'] );
		self::assertSame( 'test reason', $events[0]['reason'] );
	}

	public function test_audit_events_isolates_by_reservation(): void {
		$id1 = $this->repo->insert( $this->sample_row() );
		$id2 = $this->repo->insert( $this->sample_row() );

		$this->repo->audit( $id1, 'create' );
		$this->repo->audit( $id2, 'create' );
		$this->repo->audit( $id1, 'approve' );

		self::assertCount( 2, $this->repo->audit_events( $id1 ) );
		self::assertCount( 1, $this->repo->audit_events( $id2 ) );
	}

	/**
	 * Build a minimal reservation row with optional overrides.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private function sample_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'book_post_id' => 101,
				'borrower_id'  => 1,
				'status'       => ReservationStatuses::ACTIVE_HOLD,
				'requested_at' => '2026-06-19 12:00:00',
				'created_at'   => '2026-06-19 12:00:00',
				'updated_at'   => '2026-06-19 12:00:00',
			),
			$overrides
		);
	}
}
