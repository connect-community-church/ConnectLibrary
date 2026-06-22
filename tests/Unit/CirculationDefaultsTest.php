<?php
/**
 * Tests for CirculationDefaults adapter and workflow integration.
 *
 * Covers: adapter fallback/typing, checkout/renewal period integration,
 * reservation hold period integration, waitlist promotion, and due-reminder
 * lead-day integration (including 0).
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Circulation\DueReminderService;
use ConnectLibrary\Circulation\CopyRepository;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Circulation\LoanService;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Settings\CirculationDefaults;
use ConnectLibrary\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Validates the typed defaults adapter and that Phase 2 services consume it.
 */
final class CirculationDefaultsTest extends TestCase {

	/** @var string */
	private string $loans_table;
	/** @var string */
	private string $loan_audit_table;
	/** @var string */
	private string $copies_table;
	/** @var string */
	private string $reservations_table;
	/** @var string */
	private string $reservation_audit_table;
	/** @var string */
	private string $borrowers_table;

	protected function setUp(): void {
		parent::setUp();

		$tables = Schema::table_names();

		$this->loans_table             = $tables['loans'] . ':rows';
		$this->loan_audit_table        = $tables['loan_audit'] . ':rows';
		$this->copies_table            = $tables['copies'] . ':rows';
		$this->reservations_table      = $tables['reservations'] . ':rows';
		$this->reservation_audit_table = $tables['reservation_audit'] . ':rows';
		$this->borrowers_table         = $tables['borrowers'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ]             = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loan_audit_table ]        = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ]            = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ]      = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservation_audit_table ] = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ]         = array();
		$GLOBALS['connectlibrary_test_db_insert_failures']                           = array();
		$GLOBALS['connectlibrary_test_db_query_results']                             = array();
		$GLOBALS['connectlibrary_test_current_user_id']                              = 1;
		$GLOBALS['connectlibrary_test_mail']                                         = array();
		$GLOBALS['connectlibrary_test_mail_should_fail']                             = false;

		// Start each test with no stored settings — adapter must return safe defaults.
		unset( $GLOBALS['connectlibrary_test_options'][ Settings::OPTION_NAME ] );
		// admin_email used by Settings::defaults() for librarian_email fallback.
		$GLOBALS['connectlibrary_test_options']['admin_email'] = array(
			'value'    => 'admin@example.test',
			'autoload' => null,
		);
	}

	// =========================================================================
	// CirculationDefaults adapter — fallback and type safety
	// =========================================================================

	public function test_loan_period_days_returns_14_when_option_missing(): void {
		self::assertSame( 14, CirculationDefaults::loan_period_days() );
	}

	public function test_hold_period_days_returns_14_when_option_missing(): void {
		self::assertSame( 14, CirculationDefaults::hold_period_days() );
	}

	public function test_due_reminder_lead_days_returns_3_when_option_missing(): void {
		self::assertSame( 3, CirculationDefaults::due_reminder_lead_days() );
	}

	public function test_librarian_email_returns_admin_email_when_option_missing(): void {
		self::assertSame( 'admin@example.test', CirculationDefaults::librarian_email() );
	}

	public function test_default_availability_status_returns_available_when_option_missing(): void {
		self::assertSame( 'available', CirculationDefaults::default_availability_status() );
	}

	public function test_loan_period_days_reflects_configured_21(): void {
		$this->set_settings( array( 'default_loan_period_days' => 21 ) );

		self::assertSame( 21, CirculationDefaults::loan_period_days() );
	}

	public function test_hold_period_days_reflects_configured_7(): void {
		$this->set_settings( array( 'default_hold_period_days' => 7 ) );

		self::assertSame( 7, CirculationDefaults::hold_period_days() );
	}

	public function test_due_reminder_lead_days_zero_is_valid(): void {
		$this->set_settings( array( 'due_reminder_lead_days' => 0 ) );

		self::assertSame( 0, CirculationDefaults::due_reminder_lead_days() );
	}

	public function test_due_reminder_lead_days_three_is_preserved(): void {
		$this->set_settings( array( 'due_reminder_lead_days' => 3 ) );

		self::assertSame( 3, CirculationDefaults::due_reminder_lead_days() );
	}

	public function test_due_reminder_lead_days_out_of_range_falls_back_to_3(): void {
		$this->set_settings( array( 'due_reminder_lead_days' => 99 ) );

		self::assertSame( 3, CirculationDefaults::due_reminder_lead_days() );
	}

	public function test_loan_period_days_out_of_range_falls_back_to_14(): void {
		$this->set_settings( array( 'default_loan_period_days' => 999 ) );

		self::assertSame( 14, CirculationDefaults::loan_period_days() );
	}

	public function test_librarian_email_returns_configured_email(): void {
		$this->set_settings( array( 'librarian_email' => 'librarian@church.test' ) );

		self::assertSame( 'librarian@church.test', CirculationDefaults::librarian_email() );
	}

	public function test_librarian_email_returns_empty_when_invalid(): void {
		$this->set_settings( array( 'librarian_email' => 'not-an-email' ) );

		self::assertSame( '', CirculationDefaults::librarian_email() );
	}

	public function test_default_availability_status_reflects_configured_value(): void {
		$this->set_settings( array( 'default_availability_status' => 'checked_out' ) );

		self::assertSame( 'checked_out', CirculationDefaults::default_availability_status() );
	}

	public function test_default_availability_status_falls_back_on_bad_value(): void {
		$this->set_settings( array( 'default_availability_status' => 'invalid_status' ) );

		self::assertSame( 'available', CirculationDefaults::default_availability_status() );
	}

	// =========================================================================
	// LoanService — checkout uses loan period from settings
	// =========================================================================

	public function test_checkout_default_14_day_due_date(): void {
		// No override settings — defaults to 14 days.
		// current_time stub = '2026-06-19 12:00:00'; +14 days = '2026-07-03 12:00:00'
		$this->seed_copy( 1 );
		$service = $this->make_loan_service();

		$result = $service->checkout( 1, 101, 7 );

		self::assertIsArray( $result );
		self::assertSame( '2026-07-03 12:00:00', $result['due_at'] );
		self::assertSame( 14, (int) $result['due_period_days'] );
	}

	public function test_checkout_uses_changed_loan_period_21_days(): void {
		$this->set_settings( array( 'default_loan_period_days' => 21 ) );
		// current_time stub = '2026-06-19 12:00:00'; +21 days = '2026-07-10 12:00:00'
		$this->seed_copy( 1 );
		$service = $this->make_loan_service();

		$result = $service->checkout( 1, 101, 7 );

		self::assertIsArray( $result );
		self::assertSame( '2026-07-10 12:00:00', $result['due_at'] );
		self::assertSame( 21, (int) $result['due_period_days'] );
	}

	public function test_checkout_explicit_override_due_date_ignores_setting(): void {
		$this->set_settings( array( 'default_loan_period_days' => 21 ) );
		$this->seed_copy( 1 );
		$service = $this->make_loan_service();

		$result = $service->checkout( 1, 101, 7, '2027-01-15 00:00:00', 'admin', 0, 'Holiday extension' );

		self::assertIsArray( $result );
		self::assertSame( '2027-01-15 00:00:00', $result['due_at'] );
	}

	public function test_checkout_stores_concrete_due_date_on_record(): void {
		$this->set_settings( array( 'default_loan_period_days' => 21 ) );
		$this->seed_copy( 1 );
		$service = $this->make_loan_service();
		$repo    = new LoanRepository();

		$result  = $service->checkout( 1, 101, 7 );
		$stored  = $repo->get( (int) ( $result['id'] ?? 0 ) );

		self::assertNotNull( $stored );
		self::assertSame( '2026-07-10 12:00:00', $stored['due_at'] );
	}

	// =========================================================================
	// ReservationService — hold period from settings
	// =========================================================================

	public function test_request_hold_default_14_day_expiry(): void {
		// current_time stub = '2026-06-19 12:00:00'; +14 days = '2026-07-03 12:00:00'
		$this->seed_copy_for_reservation( 1, 101 );
		$service = $this->make_reservation_service();

		$result = $service->request_hold( 7, 101 );

		self::assertIsArray( $result );
		$res = $result['reservation'];
		self::assertSame( '2026-07-03 12:00:00', $res['hold_expires_at'] );
	}

	public function test_request_hold_changed_hold_period_7_days(): void {
		$this->set_settings( array( 'default_hold_period_days' => 7 ) );
		// current_time stub = '2026-06-19 12:00:00'; +7 days = '2026-06-26 12:00:00'
		$this->seed_copy_for_reservation( 1, 101 );
		$service = $this->make_reservation_service();

		$result = $service->request_hold( 7, 101 );

		self::assertIsArray( $result );
		$res = $result['reservation'];
		self::assertSame( '2026-06-26 12:00:00', $res['hold_expires_at'] );
	}

	public function test_hold_stores_concrete_expiry_not_live_setting(): void {
		$this->seed_copy_for_reservation( 1, 101 );
		$service = $this->make_reservation_service();
		$repo    = new ReservationRepository();

		$service->request_hold( 7, 101 );
		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ];
		$stored_expiry = $rows[0]['hold_expires_at'] ?? null;

		self::assertSame( '2026-07-03 12:00:00', $stored_expiry );
	}

	// =========================================================================
	// Waitlist promotion — uses current hold period at promotion time
	// =========================================================================

	public function test_waitlist_promotion_uses_configured_hold_period(): void {
		$this->set_settings( array( 'default_hold_period_days' => 7 ) );
		// Seed a waitlisted reservation and a free copy.
		$this->seed_copy_for_reservation( 1, 101 );
		$this->seed_waitlisted_reservation( 10, 101, borrower_id: 5 );

		$service = $this->make_reservation_service();
		$result  = $service->handle_copy_available( 101 );

		self::assertNotNull( $result );
		$promoted = $result['reservation'];
		self::assertSame( ReservationStatuses::ACTIVE_HOLD, $promoted['status'] );
		self::assertSame( '2026-06-26 12:00:00', $promoted['hold_expires_at'] );
	}

	public function test_waitlist_promotion_default_14_day_hold(): void {
		$this->seed_copy_for_reservation( 1, 101 );
		$this->seed_waitlisted_reservation( 10, 101, borrower_id: 5 );

		$service = $this->make_reservation_service();
		$result  = $service->handle_copy_available( 101 );

		self::assertNotNull( $result );
		self::assertSame( '2026-07-03 12:00:00', $result['reservation']['hold_expires_at'] );
	}

	// =========================================================================
	// Notification seam — librarian_to is populated for relevant types
	// =========================================================================

	public function test_guest_request_notification_includes_librarian_to(): void {
		$this->set_settings( array( 'librarian_email' => 'librarian@church.test' ) );
		$service = $this->make_reservation_service();

		$result = $service->request_guest( 'patron@example.test', 'Jane Doe', 101 );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'notification', $result );
		$notif = $result['notification'];
		self::assertNotNull( $notif );
		self::assertSame( 'librarian@church.test', $notif['librarian_to'] );
	}

	public function test_guest_request_notification_librarian_to_empty_when_unconfigured(): void {
		$this->set_settings( array( 'librarian_email' => '' ) );
		$service = $this->make_reservation_service();

		$result = $service->request_guest( 'patron@example.test', 'Jane Doe', 101 );

		self::assertIsArray( $result );
		$notif = $result['notification'];
		self::assertNotNull( $notif );
		self::assertSame( '', $notif['librarian_to'] );
	}

	// =========================================================================
	// DueReminderService — lead days from settings, 0 is valid
	// =========================================================================

	public function test_due_reminder_lead_3_days_targets_correct_date(): void {
		$this->set_settings( array(
			'due_reminder_lead_days' => 3,
			'librarian_email'        => 'lib@example.test',
		) );

		$service = $this->make_due_reminder_service();

		// current_time = '2026-06-19 12:00:00'; +3 days = '2026-06-22'
		$summary = $service->process_due_reminders( null, '2026-06-19 12:00:00' );

		self::assertSame( '2026-06-22', $summary['target_date'] );
	}

	public function test_due_reminder_lead_0_days_targets_today(): void {
		$this->set_settings( array( 'due_reminder_lead_days' => 0 ) );

		$service = $this->make_due_reminder_service();

		// current_time override = '2026-06-19 12:00:00'; +0 days = '2026-06-19'
		$summary = $service->process_due_reminders( null, '2026-06-19 12:00:00' );

		self::assertSame( '2026-06-19', $summary['target_date'] );
	}

	public function test_due_reminder_lead_0_selects_loans_due_today(): void {
		$this->set_settings( array(
			'due_reminder_lead_days' => 0,
			'librarian_email'        => 'lib@example.test',
		) );

		$this->seed_loan_for_reminder( 1, borrower_id: 7, due_date: '2026-06-19' );
		$this->seed_borrower( 7, email: 'borrower@example.test' );

		$service = $this->make_due_reminder_service();
		$summary = $service->process_due_reminders( null, '2026-06-19 12:00:00' );

		self::assertSame( 1, (int) $summary['processed'] );
		self::assertSame( 1, (int) $summary['sent'] );
	}

	public function test_due_reminder_lead_3_selects_loans_due_in_3_days(): void {
		$this->set_settings( array(
			'due_reminder_lead_days' => 3,
			'librarian_email'        => 'lib@example.test',
		) );

		$this->seed_loan_for_reminder( 1, borrower_id: 7, due_date: '2026-06-22' );
		$this->seed_borrower( 7, email: 'borrower@example.test' );

		$service = $this->make_due_reminder_service();
		$summary = $service->process_due_reminders( null, '2026-06-19 12:00:00' );

		self::assertSame( 1, (int) $summary['processed'] );
		self::assertSame( 1, (int) $summary['sent'] );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Store a settings array in the test options. */
	private function set_settings( array $overrides ): void {
		$GLOBALS['connectlibrary_test_options'][ Settings::OPTION_NAME ] = array(
			'value'    => array_merge( Settings::defaults(), $overrides ),
			'autoload' => false,
		);
	}

	private function make_loan_service(): LoanService {
		return new LoanService( new LoanRepository(), new ReservationRepository(), new CopyRepository() );
	}

	private function make_reservation_service(): ReservationService {
		return new ReservationService( new ReservationRepository(), new BorrowerRepository() );
	}

	private function make_due_reminder_service(): DueReminderService {
		return new DueReminderService( new LoanRepository(), new BorrowerRepository() );
	}

	/** Seed a copy that looks available for hold/checkout. */
	private function seed_copy( int $id, int $book_post_id = 101 ): void {
		$now = '2026-06-19 12:00:00';
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ][] = array(
			'id'                 => $id,
			'book_post_id'       => $book_post_id,
			'copy_number'        => 1,
			'item_status'        => 'active',
			'circulation_status' => 'available',
			'visibility'         => 'public',
			'current_loan_id'    => null,
			'created_at'         => $now,
			'updated_at'         => $now,
		);
	}

	/** Seed a copy visible to the reservation service (needs visibility=public). */
	private function seed_copy_for_reservation( int $id, int $book_post_id ): void {
		$this->seed_copy( $id, $book_post_id );
	}

	/** Seed a waitlisted reservation row. */
	private function seed_waitlisted_reservation( int $id, int $book_post_id, int $borrower_id = 1 ): void {
		$now = '2026-06-19 12:00:00';
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ][] = array(
			'id'              => $id,
			'book_post_id'    => $book_post_id,
			'borrower_id'     => $borrower_id,
			'copy_id'         => null,
			'guest_email'     => null,
			'guest_name'      => null,
			'status'          => ReservationStatuses::WAITLISTED,
			'hold_expires_at' => null,
			'requested_at'    => $now,
			'created_at'      => $now,
			'updated_at'      => $now,
			'acted_by'        => null,
			'notes'           => null,
			'context'         => null,
		);
	}

	/** Seed an active loan row for due-reminder tests. */
	private function seed_loan_for_reminder( int $id, int $borrower_id, string $due_date ): void {
		$now = '2026-06-19 12:00:00';
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ][] = array(
			'id'              => $id,
			'book_post_id'    => 101,
			'copy_id'         => 1,
			'borrower_id'     => $borrower_id,
			'status'          => 'active',
			'checked_out_at'  => '2026-06-01 12:00:00',
			'due_at'          => $due_date . ' 12:00:00',
			'returned_at'     => null,
			'renewal_count'   => 0,
			'renewal_limit'   => 1,
			'last_renewed_at' => null,
			'due_period_days' => 14,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
	}

	/** Seed a borrower row for due-reminder tests. */
	private function seed_borrower( int $id, string $email, ?string $guardian_email = null ): void {
		$now = '2026-06-19 12:00:00';
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ][] = array(
			'id'                     => $id,
			'display_name'           => 'Test Borrower',
			'email'                  => $email,
			'guardian_email'         => $guardian_email,
			'guardian_borrower_id'   => null,
			'borrower_type'          => 'adult',
			'status'                 => 'active',
			'email_notices_allowed'  => 1,
			'anonymized_at'          => null,
			'created_at'             => $now,
			'updated_at'             => $now,
		);
	}
}
