<?php
/**
 * Tests for public reservation and guest request entry points.
 *
 * Covers POST handler logic (hold, guest, nonce, honeypot, rate-limit,
 * duplicates) and BookDetailRenderer action-panel output selection.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Frontend\BookDetailRenderer;
use ConnectLibrary\Frontend\PublicReservationRequests;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Build 04A2 public reservation entry points.
 */
final class PublicReservationRequestsTest extends TestCase {

	/**
	 * Reservation table fake-DB key.
	 *
	 * @var string
	 */
	private string $res_table;

	/**
	 * Reservation audit table fake-DB key.
	 *
	 * @var string
	 */
	private string $audit_table;

	/**
	 * Copies table fake-DB key.
	 *
	 * @var string
	 */
	private string $copies_table;

	/**
	 * Borrowers table fake-DB key.
	 *
	 * @var string
	 */
	private string $borrowers_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->res_table       = $tables['reservations'] . ':rows';
		$this->audit_table     = $tables['reservation_audit'] . ':rows';
		$this->copies_table    = $tables['copies'] . ':rows';
		$this->borrowers_table = $tables['borrowers'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ]       = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_table ]     = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ]    = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ] = array();
		$GLOBALS['connectlibrary_test_post_meta']                           = array();
		$GLOBALS['connectlibrary_test_post_objects']                        = array();
		$GLOBALS['connectlibrary_test_posts']                               = array();
		$GLOBALS['connectlibrary_test_transients']                          = array();
		$GLOBALS['connectlibrary_test_current_user_id']                     = 0;

		PublicReservationRequests::clear_notice();
		$_POST = array();
	}

	protected function tearDown(): void {
		PublicReservationRequests::clear_notice();
		$_POST = array();
	}

	/** Borrower-hold POST handler tests. */
	public function test_process_hold_succeeds_for_active_borrower(): void {
		$this->seed_copy( 1, 42 );
		$this->seed_borrower( 7, 5 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 5;

		$_POST = array(
			'connectlibrary_action'                     => 'reserve_hold',
			'connectlibrary_book_id'                    => '42',
			PublicReservationRequests::NONCE_FIELD_HOLD => 'connectlibrary_reserve_42',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'success', $notice['type'] );

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ];
		self::assertCount( 1, $rows );
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $rows[0]['status'] );
		self::assertSame( 7, (int) $rows[0]['borrower_id'] );
	}

	public function test_process_hold_fails_with_empty_nonce(): void {
		$this->seed_copy( 1, 42 );
		$this->seed_borrower( 7, 5 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 5;

		$_POST = array(
			'connectlibrary_action'                     => 'reserve_hold',
			'connectlibrary_book_id'                    => '42',
			PublicReservationRequests::NONCE_FIELD_HOLD => '',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertStringContainsString( 'Security', $notice['message'] );
		// No reservation should have been created.
		self::assertCount( 0, $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ] );
	}

	public function test_process_hold_fails_when_not_logged_in(): void {
		$this->seed_copy( 1, 42 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 0;

		$_POST = array(
			'connectlibrary_action'                     => 'reserve_hold',
			'connectlibrary_book_id'                    => '42',
			PublicReservationRequests::NONCE_FIELD_HOLD => 'connectlibrary_reserve_42',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertStringContainsString( 'logged in', $notice['message'] );
	}

	public function test_process_hold_fails_when_no_borrower_account(): void {
		$this->seed_copy( 1, 42 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 5; // Logged in, but no borrower row.

		$_POST = array(
			'connectlibrary_action'                     => 'reserve_hold',
			'connectlibrary_book_id'                    => '42',
			PublicReservationRequests::NONCE_FIELD_HOLD => 'connectlibrary_reserve_42',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertStringContainsString( 'not registered', $notice['message'] );
	}

	public function test_process_hold_fails_when_no_copy_available(): void {
		// No copy seeded — service returns no_copy error.
		$this->seed_borrower( 7, 5 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 5;

		$_POST = array(
			'connectlibrary_action'                     => 'reserve_hold',
			'connectlibrary_book_id'                    => '42',
			PublicReservationRequests::NONCE_FIELD_HOLD => 'connectlibrary_reserve_42',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertStringContainsString( 'not currently available', $notice['message'] );
	}

	public function test_process_hold_rejects_unavailable_public_state(): void {
		$this->seed_copy( 1, 42, 'checked_out' );
		$this->seed_borrower( 7, 5 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 5;

		$_POST = array(
			'connectlibrary_action'                     => 'reserve_hold',
			'connectlibrary_book_id'                    => '42',
			PublicReservationRequests::NONCE_FIELD_HOLD => 'connectlibrary_reserve_42',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertCount( 0, $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ] );
	}

	public function test_process_hold_fails_on_duplicate(): void {
		$this->seed_copy( 1, 42 );
		$this->seed_borrower( 7, 5 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 5;

		$post_data = array(
			'connectlibrary_action'                     => 'reserve_hold',
			'connectlibrary_book_id'                    => '42',
			PublicReservationRequests::NONCE_FIELD_HOLD => 'connectlibrary_reserve_42',
		);

		// First hold succeeds.
		$_POST = $post_data;
		$this->make_handler()->handle_post();
		PublicReservationRequests::clear_notice();

		// Second hold for same borrower/book is a duplicate.
		$_POST = $post_data;
		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertStringContainsString( 'already exists', $notice['message'] );
	}

	public function test_handle_post_does_nothing_when_action_absent(): void {
		$_POST = array( 'some_other_field' => 'value' );

		$this->make_handler()->handle_post();

		self::assertNull( PublicReservationRequests::get_notice() );
	}

	/** Guest-request POST handler tests. */
	public function test_process_guest_succeeds_with_valid_data(): void {
		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => 'connectlibrary_guest_request_42',
			PublicReservationRequests::HONEYPOT_FIELD    => '',
			'cl_guest_name'                              => 'Jane Smith',
			'cl_guest_email'                             => 'jane@example.com',
			'cl_guest_phone'                             => '555-1234',
			'cl_guest_note'                              => 'Would like it for next week',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'success', $notice['type'] );

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ];
		self::assertCount( 1, $rows );
		self::assertSame( ReservationStatuses::PENDING_APPROVAL, $rows[0]['status'] );

		// Rate-limit transient must now be set.
		$rate_key = 'cl_guest_rate_' . md5( 'jane@example.com' );
		self::assertNotFalse( get_transient( $rate_key ) );
	}

	public function test_process_guest_fails_with_empty_nonce(): void {
		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => '',
			PublicReservationRequests::HONEYPOT_FIELD    => '',
			'cl_guest_name'                              => 'Jane',
			'cl_guest_email'                             => 'jane@example.com',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertStringContainsString( 'Security', $notice['message'] );
		self::assertCount( 0, $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ] );
	}

	public function test_process_guest_honeypot_blocks_submission(): void {
		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => 'connectlibrary_guest_request_42',
			PublicReservationRequests::HONEYPOT_FIELD    => 'bot@spam.example',
			'cl_guest_name'                              => 'Spammer',
			'cl_guest_email'                             => 'bot@spam.example',
		);

		$this->make_handler()->handle_post();

		// Notice looks like success (silent discard), but no reservation is created.
		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'success', $notice['type'] );
		self::assertCount( 0, $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ] );
	}

	public function test_process_guest_rate_limit_blocks_second_submission(): void {
		$email    = 'jane@example.com';
		$rate_key = 'cl_guest_rate_' . md5( $email );
		set_transient( $rate_key, 1, PublicReservationRequests::RATE_LIMIT_SECONDS );

		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => 'connectlibrary_guest_request_42',
			PublicReservationRequests::HONEYPOT_FIELD    => '',
			'cl_guest_name'                              => 'Jane',
			'cl_guest_email'                             => $email,
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertStringContainsString( 'wait', $notice['message'] );
		self::assertCount( 0, $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ] );
	}

	public function test_process_guest_fails_with_invalid_email(): void {
		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => 'connectlibrary_guest_request_42',
			PublicReservationRequests::HONEYPOT_FIELD    => '',
			'cl_guest_name'                              => 'Jane',
			'cl_guest_email'                             => 'not-an-email',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertStringContainsString( 'email', $notice['message'] );
	}

	public function test_process_guest_fails_with_empty_name(): void {
		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => 'connectlibrary_guest_request_42',
			PublicReservationRequests::HONEYPOT_FIELD    => '',
			'cl_guest_name'                              => '',
			'cl_guest_email'                             => 'jane@example.com',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertStringContainsString( 'name', $notice['message'] );
	}

	public function test_process_guest_succeeds_for_reserved_waitlist_state(): void {
		$GLOBALS['connectlibrary_test_post_meta'][42][ Availability::META_STATUS ] = 'reserved';

		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => 'connectlibrary_guest_request_42',
			PublicReservationRequests::HONEYPOT_FIELD    => '',
			'cl_guest_name'                              => 'Jane',
			'cl_guest_email'                             => 'jane@example.com',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'success', $notice['type'] );

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ];
		self::assertCount( 1, $rows );
		self::assertSame( ReservationStatuses::PENDING_APPROVAL, $rows[0]['status'] );
		self::assertSame( 'jane@example.com', $rows[0]['guest_email'] );
	}

	public function test_process_guest_succeeds_for_checked_out_waitlist_state(): void {
		$GLOBALS['connectlibrary_test_post_meta'][42][ Availability::META_STATUS ] = 'checked_out';

		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => 'connectlibrary_guest_request_42',
			PublicReservationRequests::HONEYPOT_FIELD    => '',
			'cl_guest_name'                              => 'Jane',
			'cl_guest_email'                             => 'jane@example.com',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'success', $notice['type'] );

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ];
		self::assertCount( 1, $rows );
		self::assertSame( ReservationStatuses::PENDING_APPROVAL, $rows[0]['status'] );
	}

	public function test_process_guest_rejects_hidden_public_state(): void {
		$GLOBALS['connectlibrary_test_post_meta'][42][ Availability::META_STATUS ] = 'hidden';

		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => 'connectlibrary_guest_request_42',
			PublicReservationRequests::HONEYPOT_FIELD    => '',
			'cl_guest_name'                              => 'Jane',
			'cl_guest_email'                             => 'jane@example.com',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertCount( 0, $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ] );
	}

	public function test_process_guest_rejects_contact_librarian_state(): void {
		$GLOBALS['connectlibrary_test_post_meta'][42][ Availability::META_STATUS ] = 'unavailable';

		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => 'connectlibrary_guest_request_42',
			PublicReservationRequests::HONEYPOT_FIELD    => '',
			'cl_guest_name'                              => 'Jane',
			'cl_guest_email'                             => 'jane@example.com',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertCount( 0, $GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ] );
	}

	public function test_process_guest_fails_on_duplicate_email_book_in_waitlist_state(): void {
		$GLOBALS['connectlibrary_test_post_meta'][42][ Availability::META_STATUS ] = 'reserved';
		// Seed an existing pending_approval request.
		$GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ][] = array(
			'id'           => 1,
			'book_post_id' => 42,
			'guest_email'  => 'jane@example.com',
			'status'       => ReservationStatuses::PENDING_APPROVAL,
		);

		$_POST = array(
			'connectlibrary_action'                      => 'guest_request',
			'connectlibrary_book_id'                     => '42',
			PublicReservationRequests::NONCE_FIELD_GUEST => 'connectlibrary_guest_request_42',
			PublicReservationRequests::HONEYPOT_FIELD    => '',
			'cl_guest_name'                              => 'Jane',
			'cl_guest_email'                             => 'jane@example.com',
		);

		$this->make_handler()->handle_post();

		$notice = PublicReservationRequests::get_notice();
		self::assertNotNull( $notice );
		self::assertSame( 'error', $notice['type'] );
		self::assertStringContainsString( 'already exists', $notice['message'] );
		// Notice must not reveal the email address.
		self::assertStringNotContainsString( 'jane@example.com', $notice['message'] );
	}

	/** BookDetailRenderer action panel rendering tests. */
	public function test_renderer_shows_hold_button_for_active_borrower_on_available_book(): void {
		$book_id = $this->create_book();
		$this->seed_borrower( 7, 5 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 5;

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'reserve_hold', $html );
		self::assertStringContainsString( 'Reserve this book', $html );
		self::assertStringNotContainsString( 'guest_request', $html );
		self::assertStringNotContainsString( 'cl_guest_email', $html );
	}

	public function test_renderer_shows_guest_form_when_not_logged_in_on_available_book(): void {
		$book_id                                        = $this->create_book();
		$GLOBALS['connectlibrary_test_current_user_id'] = 0;

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'guest_request', $html );
		self::assertStringContainsString( 'cl_guest_email', $html );
		self::assertStringContainsString( 'cl_guest_name', $html );
		self::assertStringNotContainsString( 'reserve_hold', $html );
	}

	public function test_renderer_shows_guest_form_for_logged_in_user_without_borrower_record(): void {
		$book_id                                        = $this->create_book();
		$GLOBALS['connectlibrary_test_current_user_id'] = 9; // Logged in but no borrower row.

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'guest_request', $html );
		self::assertStringNotContainsString( 'reserve_hold', $html );
	}

	public function test_renderer_shows_waitlist_guest_form_for_reserved_book(): void {
		$book_id = $this->create_book();
		$GLOBALS['connectlibrary_test_post_meta'][ $book_id ][ Availability::META_STATUS ] = 'reserved';

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		// 'reserved' maps to the 'waitlist' action; unauthenticated users see the guest request form.
		self::assertStringContainsString( 'guest_request', $html );
		self::assertStringNotContainsString( 'reserve_hold', $html );
	}

	public function test_renderer_shows_waitlist_guest_form_for_checked_out_book(): void {
		$book_id = $this->create_book();
		$GLOBALS['connectlibrary_test_post_meta'][ $book_id ][ Availability::META_STATUS ] = 'checked_out';

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		// 'checked_out' maps to the 'waitlist' action; unauthenticated users see the guest request form.
		self::assertStringContainsString( 'cl_guest_email', $html );
		self::assertStringNotContainsString( 'reserve_hold', $html );
	}

	public function test_renderer_shows_success_notice_after_hold_placed(): void {
		PublicReservationRequests::clear_notice();
		// Manually inject a success notice as the handler would.
		// Reflection into private static is avoided; use the real handler.
		$book_id = $this->create_book();
		$this->seed_copy( 1, $book_id );
		$this->seed_borrower( 7, 5 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 5;

		$_POST = array(
			'connectlibrary_action'                     => 'reserve_hold',
			'connectlibrary_book_id'                    => (string) $book_id,
			PublicReservationRequests::NONCE_FIELD_HOLD => 'connectlibrary_reserve_' . $book_id,
		);
		$this->make_handler()->handle_post();

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'connectlibrary-book__notice--success', $html );
		self::assertStringContainsString( 'reservation has been placed', $html );
	}

	public function test_renderer_shows_error_notice_on_failure(): void {
		$book_id = $this->create_book();
		// Process with invalid nonce to trigger error notice.
		$_POST = array(
			'connectlibrary_action'                     => 'reserve_hold',
			'connectlibrary_book_id'                    => (string) $book_id,
			PublicReservationRequests::NONCE_FIELD_HOLD => '',
		);
		$this->make_handler()->handle_post();

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'connectlibrary-book__notice--error', $html );
	}

	public function test_renderer_guest_form_contains_honeypot_field(): void {
		$book_id                                        = $this->create_book();
		$GLOBALS['connectlibrary_test_current_user_id'] = 0;

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( PublicReservationRequests::HONEYPOT_FIELD, $html );
	}

	public function test_pending_guest_request_does_not_change_availability_label(): void {
		$book_id = $this->create_book();

		// Seed a pending_approval guest request (no copy blocked).
		$GLOBALS['connectlibrary_test_db_tables'][ $this->res_table ][] = array(
			'id'           => 1,
			'book_post_id' => $book_id,
			'guest_email'  => 'pending@example.com',
			'status'       => ReservationStatuses::PENDING_APPROVAL,
		);

		$availability = Availability::for_book( $book_id );

		self::assertSame( 'available', $availability['status'] );
		self::assertSame( 'Available', $availability['label'] );
	}

	/** BorrowerRepository::find_by_wp_user_id tests. */
	public function test_find_by_wp_user_id_returns_active_borrower(): void {
		$this->seed_borrower( 3, 10 );

		$repo   = new BorrowerRepository();
		$result = $repo->find_by_wp_user_id( 10 );

		self::assertNotNull( $result );
		self::assertSame( 3, (int) $result['id'] );
	}

	public function test_find_by_wp_user_id_ignores_inactive_borrower(): void {
		$this->seed_borrower( 3, 10, 'disabled' );

		$repo   = new BorrowerRepository();
		$result = $repo->find_by_wp_user_id( 10 );

		self::assertNull( $result );
	}

	public function test_find_by_wp_user_id_returns_null_for_zero(): void {
		$repo = new BorrowerRepository();
		self::assertNull( $repo->find_by_wp_user_id( 0 ) );
	}

	public function test_find_by_wp_user_id_returns_null_when_not_found(): void {
		$this->seed_borrower( 3, 10 );
		$repo = new BorrowerRepository();
		self::assertNull( $repo->find_by_wp_user_id( 99 ) );
	}

	/** PublicServiceProvider hook registration tests. */
	public function test_public_service_provider_registers_init_hook_for_post_handler(): void {
		$GLOBALS['connectlibrary_test_hooks'] = array();

		( new \ConnectLibrary\Frontend\PublicServiceProvider() )->register();

		self::assertArrayHasKey( 'init', $GLOBALS['connectlibrary_test_hooks'] );
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Build a handler with in-memory repositories.
	 */
	private function make_handler(): PublicReservationRequests {
		$res_repo      = new ReservationRepository();
		$borrower_repo = new BorrowerRepository();
		$service       = new ReservationService( $res_repo, $borrower_repo );

		return new PublicReservationRequests( $service, $borrower_repo );
	}

	/**
	 * Insert an active/public copy row into the fake DB.
	 *
	 * @param int    $copy_id            Copy ID.
	 * @param int    $book_id            Book post ID.
	 * @param string $circulation_status Copy circulation status.
	 */
	private function seed_copy( int $copy_id, int $book_id, string $circulation_status = 'available' ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ][] = array(
			'id'                 => $copy_id,
			'book_post_id'       => $book_id,
			'circulation_status' => $circulation_status,
			'item_status'        => 'active',
			'visibility'         => 'public',
		);
	}

	/**
	 * Insert a borrower row into the fake DB.
	 *
	 * @param int    $id         Borrower ID.
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $status     Borrower status (default 'active').
	 */
	private function seed_borrower( int $id, int $wp_user_id, string $status = 'active' ): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ][] = array(
			'id'         => $id,
			'wp_user_id' => $wp_user_id,
			'status'     => $status,
		);
	}

	/**
	 * Create a minimal published book post in the fake store.
	 *
	 * @return int Book post ID.
	 */
	private function create_book(): int {
		$post_id = count( $GLOBALS['connectlibrary_test_post_objects'] ) + 100;
		$GLOBALS['connectlibrary_test_post_objects'][ $post_id ] = (object) array(
			'ID'           => $post_id,
			'post_type'    => 'connectlibrary_book',
			'post_status'  => 'publish',
			'post_title'   => 'Test Book',
			'post_name'    => 'test-book',
			'post_content' => '',
		);
		$GLOBALS['connectlibrary_test_posts'][ $post_id ]        = 'connectlibrary_book';

		return $post_id;
	}
}
