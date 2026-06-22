<?php
/**
 * Tests for reservation admin screen rendering and actions.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Admin\ReservationsPage;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Support\Capabilities;
use ConnectLibrary\Support\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * Verifies protected librarian reservation admin seams.
 */
final class ReservationsAdminTest extends TestCase {
	/**
	 * Reservation table row store key.
	 *
	 * @var string
	 */
	private string $reservations_table;

	/**
	 * Reservation audit table row store key.
	 *
	 * @var string
	 */
	private string $audit_table;

	/**
	 * Copies table row store key.
	 *
	 * @var string
	 */
	private string $copies_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->reservations_table = $tables['reservations'] . ':rows';
		$this->audit_table        = $tables['reservation_audit'] . ':rows';
		$this->copies_table       = $tables['copies'] . ':rows';

		$GLOBALS['connectlibrary_test_admin_pages']      = array();
		$GLOBALS['connectlibrary_test_db_tables']        = array(
			$this->reservations_table => array(),
			$this->audit_table        => array(),
			$this->copies_table       => array(),
		);
		$GLOBALS['connectlibrary_test_current_user_id']  = 42;
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => true,
			Capabilities::MANAGE_OPTIONS   => false,
		);
		$GLOBALS['connectlibrary_test_safe_redirect']    = null;
		$GLOBALS['connectlibrary_test_wp_die']           = null;
		$GLOBALS['connectlibrary_test_post_objects']     = array(
			101 => (object) array(
				'ID'         => 101,
				'post_type'  => BookPostType::POST_TYPE,
				'post_title' => 'Protected Reservation Book',
			),
		);
		$_GET  = array();
		$_POST = array();
	}

	public function test_reservations_page_registers_under_library_admin_with_manage_borrowers_capability(): void {
		$page = new ReservationsPage();

		$page->add_menu_page();

		self::assertArrayHasKey( 'connectlibrary-reservations', $GLOBALS['connectlibrary_test_admin_pages'] );
		self::assertSame( 'edit.php?post_type=' . BookPostType::POST_TYPE, $GLOBALS['connectlibrary_test_admin_pages']['connectlibrary-reservations']['parent_slug'] );
		self::assertSame( Capabilities::MANAGE_BORROWERS, $GLOBALS['connectlibrary_test_admin_pages']['connectlibrary-reservations']['capability'] );
	}

	public function test_render_lists_pending_guest_requests_and_active_holds_only_in_protected_context(): void {
		$this->seed_reservation(
			array(
				'id'           => 1,
				'book_post_id' => 101,
				'guest_name'   => 'Pending Guest',
				'guest_email'  => 'pending@example.test',
				'status'       => ReservationStatuses::PENDING_APPROVAL,
				'requested_at' => '2026-06-19 12:00:00',
				'notes'        => 'Private guest note',
			)
		);
		$this->seed_reservation(
			array(
				'id'              => 2,
				'book_post_id'    => 101,
				'borrower_id'     => 77,
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-07-03 12:00:00',
				'notes'           => 'Private hold note',
			)
		);
		$this->seed_reservation(
			array(
				'id'           => 3,
				'book_post_id' => 101,
				'guest_name'   => 'Denied Guest',
				'guest_email'  => 'denied@example.test',
				'status'       => ReservationStatuses::DENIED,
			)
		);

		ob_start();
		( new ReservationsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Pending guest requests', $html );
		self::assertStringContainsString( 'Active pickup holds', $html );
		self::assertStringContainsString( 'Protected Reservation Book', $html );
		self::assertStringContainsString( 'Pending Guest', $html );
		self::assertStringContainsString( 'pending@example.test', $html );
		self::assertStringContainsString( 'Registered borrower', $html );
		self::assertStringNotContainsString( 'Borrower #77', $html );
		self::assertStringContainsString( 'name="reservation_task" value="approve"', $html );
		self::assertStringContainsString( 'name="reservation_task" value="cancel"', $html );
		self::assertStringNotContainsString( 'Denied Guest', $html );
		self::assertStringNotContainsString( 'Private guest note', $html );
		self::assertStringNotContainsString( 'Private hold note', $html );
	}

	public function test_render_rejects_users_without_librarian_capability(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => false,
			Capabilities::MANAGE_OPTIONS   => false,
		);

		ob_start();
		( new ReservationsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
		self::assertStringContainsString( 'permission', (string) $GLOBALS['connectlibrary_test_wp_die']['message'] );
	}

	public function test_action_rejects_user_without_librarian_capability(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => false,
			Capabilities::MANAGE_OPTIONS   => false,
		);
		$_POST = array(
			'_wpnonce'         => 'valid',
			'reservation_id'   => '1',
			'reservation_task' => 'deny',
		);

		( new ReservationsPage() )->handle_post();

		self::assertStringContainsString( 'permission', (string) $GLOBALS['connectlibrary_test_wp_die']['message'] );
		self::assertNull( $GLOBALS['connectlibrary_test_safe_redirect'] );
	}

	public function test_action_rejects_missing_nonce(): void {
		$_POST = array(
			'reservation_id'   => '1',
			'reservation_task' => 'deny',
		);

		( new ReservationsPage() )->handle_post();

		self::assertStringContainsString( 'security check failed', (string) $GLOBALS['connectlibrary_test_wp_die']['message'] );
		self::assertNull( $GLOBALS['connectlibrary_test_safe_redirect'] );
	}

	public function test_approve_action_routes_to_service_and_redirects_with_privacy_safe_status(): void {
		$this->seed_copy( 5, 101 );
		$this->seed_reservation(
			array(
				'id'           => 1,
				'book_post_id' => 101,
				'guest_name'   => 'Private Guest',
				'guest_email'  => 'private@example.test',
				'status'       => ReservationStatuses::PENDING_APPROVAL,
			)
		);
		$_POST = array(
			'_wpnonce'         => 'valid',
			'reservation_id'   => '1',
			'reservation_task' => 'approve',
		);

		( new ReservationsPage() )->handle_post();

		$row = ( new ReservationRepository() )->get( 1 );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $row['status'] );
		self::assertSame( 5, (int) $row['copy_id'] );
		self::assertStringContainsString( 'reservation_action=approve', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertStringContainsString( 'reservation_status=ok', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertStringNotContainsString( 'reservation_id', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertStringNotContainsString( 'private@example.test', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertStringNotContainsString( 'Private+Guest', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
	}

	public function test_approve_action_places_to_waitlisted_when_all_copies_held(): void {
		$this->seed_copy( 5, 101 );
		$this->seed_reservation(
			array(
				'id'           => 1,
				'book_post_id' => 101,
				'guest_name'   => 'Stale Guest',
				'guest_email'  => 'stale@example.test',
				'status'       => ReservationStatuses::PENDING_APPROVAL,
			)
		);
		$this->seed_reservation(
			array(
				'id'              => 2,
				'book_post_id'    => 101,
				'copy_id'         => 5,
				'borrower_id'     => 77,
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-07-03 12:00:00',
			)
		);
		$_POST = array(
			'_wpnonce'         => 'valid',
			'reservation_id'   => '1',
			'reservation_task' => 'approve',
			'reason'           => 'stale@example.test private note should not persist',
		);

		( new ReservationsPage() )->handle_post();

		// When copy is taken, approve now queues the request as WAITLISTED (no error).
		$row = ( new ReservationRepository() )->get( 1 );
		self::assertSame( ReservationStatuses::WAITLISTED, $row['status'] );
		self::assertEmpty( $row['copy_id'] ?? null );
		self::assertStringContainsString( 'reservation_action=approve', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertStringContainsString( 'reservation_status=ok', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertStringNotContainsString( 'reservation_error', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertStringNotContainsString( 'reservation_id', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertStringNotContainsString( 'stale@example.test', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertStringNotContainsString( 'Stale+Guest', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertSame( 1, count( ( new ReservationRepository() )->active_holds_for_book( 101 ) ) );
	}

	public function test_deny_action_transitions_pending_request_and_writes_privacy_safe_audit(): void {
		$this->seed_reservation(
			array(
				'id'           => 1,
				'book_post_id' => 101,
				'guest_name'   => 'Deny Guest',
				'guest_email'  => 'deny@example.test',
				'status'       => ReservationStatuses::PENDING_APPROVAL,
			)
		);

		$this->post_reservation_action( 1, 'deny', 'deny@example.test private denial note' );

		$repo   = new ReservationRepository();
		$row    = $repo->get( 1 );
		$events = $repo->audit_events( 1 );
		$deny   = end( $events );
		self::assertSame( ReservationStatuses::DENIED, $row['status'] );
		self::assertSame( 'deny', $deny['action'] );
		self::assertSame( 'admin_deny', $deny['reason'] );
		$this->assert_privacy_safe_success_redirect( 'deny', array( 'deny@example.test', 'Deny+Guest' ) );
	}

	public function test_cancel_expire_and_extend_actions_transition_active_holds(): void {
		$this->seed_reservation(
			array(
				'id'              => 1,
				'book_post_id'    => 101,
				'copy_id'         => 5,
				'borrower_id'     => 77,
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-07-03 12:00:00',
			)
		);
		$this->seed_reservation(
			array(
				'id'              => 2,
				'book_post_id'    => 101,
				'copy_id'         => 6,
				'borrower_id'     => 78,
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-07-03 12:00:00',
			)
		);
		$this->seed_reservation(
			array(
				'id'              => 3,
				'book_post_id'    => 101,
				'copy_id'         => 7,
				'borrower_id'     => 79,
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => '2026-07-03 12:00:00',
			)
		);

		$this->post_reservation_action( 1, 'cancel', 'Borrower #77 private note' );
		$this->post_reservation_action( 2, 'expire', 'Borrower #78 private note' );
		$this->post_reservation_action( 3, 'extend', 'Borrower #79 private note', '2026-08-01 09:00:00' );

		$repo = new ReservationRepository();
		self::assertSame( ReservationStatuses::CANCELLED, $repo->get( 1 )['status'] );
		self::assertSame( ReservationStatuses::EXPIRED, $repo->get( 2 )['status'] );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $repo->get( 3 )['status'] );
		self::assertSame( '2026-08-01 09:00:00', $repo->get( 3 )['hold_expires_at'] );
		self::assertSame( 'admin_cancel', end( $repo->audit_events( 1 ) )['reason'] );
		self::assertSame( 'admin_expire', end( $repo->audit_events( 2 ) )['reason'] );
		self::assertSame( 'admin_extend', end( $repo->audit_events( 3 ) )['reason'] );
		$this->assert_privacy_safe_success_redirect( 'extend', array( 'Borrower+%2379', '2026-08-01' ) );
	}

	public function test_service_list_helpers_return_only_pending_guests_and_active_holds(): void {
		$service = new ReservationService();
		$this->seed_reservation(
			array(
				'id'           => 1,
				'book_post_id' => 101,
				'guest_email'  => 'guest@example.test',
				'status'       => ReservationStatuses::PENDING_APPROVAL,
			)
		);
		$this->seed_reservation(
			array(
				'id'           => 2,
				'book_post_id' => 101,
				'borrower_id'  => 8,
				'status'       => ReservationStatuses::PENDING_APPROVAL,
			)
		);
		$this->seed_reservation(
			array(
				'id'           => 3,
				'book_post_id' => 101,
				'borrower_id'  => 9,
				'status'       => ReservationStatuses::ACTIVE_HOLD,
			)
		);

		self::assertSame( array( 1 ), array_map( 'intval', array_column( $service->pending_guest_requests(), 'id' ) ) );
		self::assertSame( array( 3 ), array_map( 'intval', array_column( $service->active_pickup_holds(), 'id' ) ) );
	}

	/**
	 * Seed a reservation row.
	 *
	 * @param array<string,mixed> $row Reservation row.
	 */
	private function seed_reservation( array $row ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ][] = $row;
	}

	/**
	 * Seed an active public copy row.
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

	private function post_reservation_action( int $reservation_id, string $action, string $reason = '', string $hold_expires_at = '' ): void {
		$_POST = array(
			'_wpnonce'         => 'valid',
			'reservation_id'   => (string) $reservation_id,
			'reservation_task' => $action,
			'reason'           => $reason,
			'hold_expires_at'  => $hold_expires_at,
		);
		if ( in_array( $action, array( 'cancel', 'expire', 'extend' ), true ) ) {
			$_POST['confirm_reservation_override'] = '1';
		}

		( new ReservationsPage() )->handle_post();
	}

	/**
	 * Assert a reservation action redirect contains no private identifiers.
	 *
	 * @param string   $action    Reservation action key.
	 * @param string[] $forbidden Fragments that must not be present in redirect URL.
	 */
	private function assert_privacy_safe_success_redirect( string $action, array $forbidden = array() ): void {
		$location = $GLOBALS['connectlibrary_test_safe_redirect']['location'];
		self::assertStringContainsString( 'reservation_action=' . $action, $location );
		self::assertStringContainsString( 'reservation_status=ok', $location );
		self::assertStringNotContainsString( 'reservation_id', $location );
		self::assertStringNotContainsString( 'reservation_error', $location );
		foreach ( $forbidden as $fragment ) {
			self::assertStringNotContainsString( $fragment, $location );
		}
	}
}
