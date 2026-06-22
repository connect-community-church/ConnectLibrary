<?php
/**
 * Tests for safe librarian overrides.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Circulation\CopyRepository;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Overrides\SafeOverrideService;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Rest\OverridesController;
use ConnectLibrary\Support\Capabilities;
use ConnectLibrary\Support\Statuses;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/** Safe override service and REST controller tests. */
final class SafeOverrideServiceTest extends TestCase {
	private SafeOverrideService $service;
	private LoanRepository $loan_repo;
	private CopyRepository $copy_repo;
	private ReservationRepository $reservation_repo;
	private string $loans_table;
	private string $loan_audit_table;
	private string $copies_table;
	private string $reservations_table;
	private string $reservation_audit_table;
	private string $audit_events_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->loans_table             = $tables['loans'] . ':rows';
		$this->loan_audit_table        = $tables['loan_audit'] . ':rows';
		$this->copies_table            = $tables['copies'] . ':rows';
		$this->reservations_table      = $tables['reservations'] . ':rows';
		$this->reservation_audit_table = $tables['reservation_audit'] . ':rows';
		$this->audit_events_table      = $tables['audit_events'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ]             = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loan_audit_table ]        = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ]            = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ]      = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservation_audit_table ] = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ]      = array();
		$GLOBALS['connectlibrary_test_current_user_id']                             = 55;
		$GLOBALS['connectlibrary_test_current_user_can']                            = array();
		$GLOBALS['connectlibrary_test_db_query_results']                            = array();
		$GLOBALS['connectlibrary_test_db_insert_failures']                          = array();

		$this->loan_repo        = new LoanRepository();
		$this->copy_repo        = new CopyRepository();
		$this->reservation_repo = new ReservationRepository();
		$this->service          = new SafeOverrideService( null, $this->loan_repo, $this->copy_repo, null, $this->reservation_repo, new AuditEventService() );
	}

	public function test_due_date_override_requires_explicit_confirmation(): void {
		$this->seed_loan( 1, copy_id: 9 );

		$result = $this->service->override_due_date( 1, array( 'new_due_at' => '2026-10-01' ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'connectlibrary_override_confirmation_required', $result->get_error_code() );
		self::assertSame( '2026-09-01 00:00:00', $this->loan_repo->get( 1 )['due_at'] );
	}

	public function test_due_date_override_logs_actor_metadata_and_redacts_tokens(): void {
		$this->seed_loan( 1, copy_id: 9 );

		$result = $this->service->override_due_date(
			1,
			array(
				'new_due_at'       => '2026-10-01',
				'confirm_override' => '1',
				'reason'           => 'Pastoral care exception',
				'source_surface'   => 'quick-circulation',
				'idempotency_key'  => 'override-1',
				'barcode_token'    => 'RAW-SCAN-TOKEN',
			)
		);

		self::assertIsArray( $result );
		self::assertSame( '2026-10-01 00:00:00', $this->loan_repo->get( 1 )['due_at'] );

		$events = $this->audit_events_for_action( 'override_due_date' );
		self::assertCount( 1, $events );
		self::assertSame( 55, (int) $events[0]['actor_id'] );
		self::assertSame( 'quick-circulation', $events[0]['source_channel'] );
		self::assertSame( 'override-1', $events[0]['correlation_id'] );
		self::assertStringContainsString( '2026-09-01', (string) $events[0]['before_json'] );
		self::assertStringContainsString( '2026-10-01', (string) $events[0]['after_json'] );
		self::assertStringNotContainsString( 'RAW-SCAN-TOKEN', (string) $events[0]['context_json'] );
		self::assertStringContainsString( '[redacted]', (string) $events[0]['context_json'] );
	}

	public function test_copy_status_override_supports_restore_and_blocks_active_loan_restore(): void {
		$this->seed_copy( 2, circulation_status: Statuses::COPY_DAMAGED, item_status: Statuses::ITEM_DAMAGED );

		$result = $this->service->override_copy_status(
			2,
			array(
				'status'           => 'active',
				'confirm_override' => '1',
			)
		);

		self::assertIsArray( $result );
		$copy = $this->copy_repo->get( 2 );
		self::assertSame( Statuses::ITEM_ACTIVE, $copy['item_status'] );
		self::assertSame( Statuses::COPY_AVAILABLE, $copy['circulation_status'] );

		$this->seed_copy( 3, circulation_status: Statuses::COPY_CHECKED_OUT, item_status: Statuses::ITEM_DAMAGED, current_loan_id: 5 );
		$this->seed_loan( 5, copy_id: 3 );
		$blocked = $this->service->override_copy_status(
			3,
			array(
				'status'           => 'active',
				'confirm_override' => '1',
			)
		);

		self::assertInstanceOf( WP_Error::class, $blocked );
		self::assertSame( 'connectlibrary_override_copy_active_loan', $blocked->get_error_code() );
	}

	public function test_hold_override_can_shorten_expire_and_reinstate_safely(): void {
		$this->seed_copy( 7 );
		$this->seed_reservation( 10, status: ReservationStatuses::ACTIVE_HOLD, copy_id: 7, hold_expires_at: '2026-07-01 00:00:00' );

		$shortened = $this->service->override_hold(
			10,
			array(
				'operation'        => 'shorten',
				'hold_expires_at'  => '2026-06-25',
				'confirm_override' => '1',
			)
		);
		self::assertIsArray( $shortened );
		self::assertSame( '2026-06-25 00:00:00', $this->reservation_repo->get( 10 )['hold_expires_at'] );

		$expired = $this->service->override_hold(
			10,
			array(
				'operation'        => 'expire',
				'confirm_override' => '1',
			)
		);
		self::assertIsArray( $expired );
		self::assertSame( ReservationStatuses::EXPIRED, $this->reservation_repo->get( 10 )['status'] );

		$reinstated = $this->service->override_hold(
			10,
			array(
				'operation'        => 'reinstate',
				'confirm_override' => '1',
			)
		);
		self::assertIsArray( $reinstated );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $this->reservation_repo->get( 10 )['status'] );
	}

	public function test_hold_shorten_rejects_missing_same_or_later_expiry(): void {
		$this->seed_copy( 17 );
		$this->seed_reservation( 20, status: ReservationStatuses::ACTIVE_HOLD, copy_id: 17, hold_expires_at: '2026-07-01 00:00:00' );

		$missing = $this->service->override_hold(
			20,
			array(
				'operation'        => 'shorten',
				'confirm_override' => '1',
			)
		);
		self::assertInstanceOf( WP_Error::class, $missing );

		$same = $this->service->override_hold(
			20,
			array(
				'operation'        => 'shorten',
				'hold_expires_at'  => '2026-07-01',
				'confirm_override' => '1',
			)
		);
		self::assertInstanceOf( WP_Error::class, $same );
		self::assertSame( 'connectlibrary_hold_shorten_not_earlier', $same->get_error_code() );

		$later = $this->service->override_hold(
			20,
			array(
				'operation'        => 'shorten',
				'hold_expires_at'  => '2026-08-01',
				'confirm_override' => '1',
			)
		);
		self::assertInstanceOf( WP_Error::class, $later );
		self::assertSame( 'connectlibrary_hold_shorten_not_earlier', $later->get_error_code() );
		self::assertSame( '2026-07-01 00:00:00', $this->reservation_repo->get( 20 )['hold_expires_at'] );
	}

	public function test_hold_extend_rejects_same_or_earlier_expiry_and_allows_later(): void {
		$this->seed_copy( 27 );
		$this->seed_reservation( 30, status: ReservationStatuses::ACTIVE_HOLD, copy_id: 27, hold_expires_at: '2026-07-01 00:00:00' );

		$earlier = $this->service->override_hold(
			30,
			array(
				'operation'        => 'extend',
				'hold_expires_at'  => '2026-06-30',
				'confirm_override' => '1',
			)
		);
		self::assertInstanceOf( WP_Error::class, $earlier );
		self::assertSame( 'connectlibrary_hold_extend_not_later', $earlier->get_error_code() );

		$same = $this->service->override_hold(
			30,
			array(
				'operation'        => 'extend',
				'hold_expires_at'  => '2026-07-01',
				'confirm_override' => '1',
			)
		);
		self::assertInstanceOf( WP_Error::class, $same );
		self::assertSame( 'connectlibrary_hold_extend_not_later', $same->get_error_code() );

		$later = $this->service->override_hold(
			30,
			array(
				'operation'        => 'extend',
				'hold_expires_at'  => '2026-08-01',
				'confirm_override' => '1',
			)
		);
		self::assertIsArray( $later );
		self::assertSame( '2026-08-01 00:00:00', $this->reservation_repo->get( 30 )['hold_expires_at'] );
	}

	public function test_hold_reinstate_rejects_unholdable_copy(): void {
		$this->seed_copy( 37, circulation_status: Statuses::COPY_LOST, item_status: Statuses::ITEM_LOST );
		$this->seed_reservation( 40, status: ReservationStatuses::EXPIRED, copy_id: 37, hold_expires_at: '2026-07-01 00:00:00' );

		$result = $this->service->override_hold(
			40,
			array(
				'operation'        => 'reinstate',
				'confirm_override' => '1',
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'connectlibrary_hold_reinstate_conflict', $result->get_error_code() );
		self::assertSame( ReservationStatuses::EXPIRED, $this->reservation_repo->get( 40 )['status'] );
		self::assertSame( Statuses::COPY_LOST, $this->copy_repo->get( 37 )['circulation_status'] );
	}

	public function test_loan_correction_void_preserves_original_row_and_requires_reason(): void {
		$this->seed_loan( 4, copy_id: 8 );

		$missing_reason = $this->service->override_loan_correction(
			4,
			array(
				'operation'        => 'void',
				'confirm_override' => '1',
			)
		);
		self::assertInstanceOf( WP_Error::class, $missing_reason );

		$result = $this->service->override_loan_correction(
			4,
			array(
				'operation'        => 'void',
				'reason'           => 'Duplicate checkout correction',
				'confirm_override' => '1',
			)
		);

		self::assertIsArray( $result );
		$loan = $this->loan_repo->get( 4 );
		self::assertNotNull( $loan );
		self::assertSame( Statuses::LOAN_VOIDED, $loan['status'] );
		self::assertSame( 'Duplicate checkout correction', $loan['correction_note'] );
	}

	public function test_override_rest_controller_is_librarian_only(): void {
		$controller = new OverridesController( $this->service );

		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => false,
			Capabilities::MANAGE_OPTIONS     => false,
		);
		$forbidden                                       = $controller->permission_check();
		self::assertInstanceOf( WP_Error::class, $forbidden );

		$GLOBALS['connectlibrary_test_current_user_can'] = array( Capabilities::MANAGE_CIRCULATION => true );
		self::assertTrue( $controller->permission_check() );
	}

	public function test_override_rest_controller_returns_privacy_safe_response(): void {
		$this->seed_loan( 8, copy_id: 9 );
		$GLOBALS['connectlibrary_test_current_user_can'] = array( Capabilities::MANAGE_CIRCULATION => true );

		$response = ( new OverridesController( $this->service ) )->due_date(
			new WP_REST_Request(
				array(
					'id'               => 8,
					'new_due_at'       => '2026-11-02',
					'confirm_override' => '1',
					'barcode_token'    => 'RAW-SCAN',
				)
			)
		);

		self::assertNotInstanceOf( WP_Error::class, $response );
		$data = $response->get_data();
		self::assertSame( 'due_date', $data['family'] );
		self::assertStringContainsString( 'No raw scan', $data['privacy_notice'] );
	}

	private function seed_loan( int $id, int $borrower_id = 1, string $status = 'active', string $due_at = '2026-09-01 00:00:00', int $book_post_id = 101, ?int $copy_id = null ): void {
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
			'renewal_count'   => 0,
			'renewal_limit'   => 2,
			'last_renewed_at' => null,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
	}

	private function seed_copy( int $id, int $book_post_id = 101, string $circulation_status = 'available', string $item_status = 'active', string $visibility = 'public', ?int $current_loan_id = null ): void {
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

	private function seed_reservation( int $id, int $book_post_id = 101, string $status = 'active_hold', ?int $borrower_id = 7, ?int $copy_id = null, ?string $hold_expires_at = '2026-07-01 00:00:00' ): void {
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

	/** @return array<int,array<string,mixed>> */
	private function audit_events_for_action( string $action ): array {
		return array_values(
			array_filter(
				$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ],
				static fn( array $row ): bool => $action === (string) ( $row['action'] ?? '' )
			)
		);
	}
}
