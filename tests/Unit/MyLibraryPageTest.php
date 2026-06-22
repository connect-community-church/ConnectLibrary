<?php
/**
 * Tests for the borrower-facing My Library access shell.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Borrowers\BorrowerService;
use ConnectLibrary\Borrowers\GuestAccessTokenService;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Frontend\MyLibraryPage;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies My Library borrower/guardian access states, loans, renewals, and reservations.
 */
final class MyLibraryPageTest extends TestCase {

	/** @var array<string,string> */
	private array $tables;

	protected function setUp(): void {
		$this->tables = Schema::table_names();

		$GLOBALS['connectlibrary_test_db_tables']         = array();
		$GLOBALS['connectlibrary_test_hooks']             = array();
		$GLOBALS['connectlibrary_test_shortcodes']        = array();
		$GLOBALS['connectlibrary_test_registered_styles'] = array();
		$GLOBALS['connectlibrary_test_enqueued_styles']   = array();
		$GLOBALS['connectlibrary_test_current_user_id']   = 0;
		$GLOBALS['connectlibrary_test_query_vars']        = array();
		$GLOBALS['connectlibrary_test_nocache_headers']   = 0;
		$GLOBALS['connectlibrary_test_post_objects']      = array();
		$_GET  = array();
		$_POST = array();

		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => true,
			Capabilities::MANAGE_OPTIONS   => false,
		);
		$GLOBALS['connectlibrary_test_users'] = array(
			77 => (object) array(
				'ID'         => 77,
				'user_login' => 'adult-reader',
				'user_email' => 'adult@example.test',
			),
			88 => (object) array(
				'ID'         => 88,
				'user_login' => 'other-reader',
				'user_email' => 'other@example.test',
			),
		);
	}

	// -------------------------------------------------------------------------
	// Existing foundation tests (preserved behaviour)
	// -------------------------------------------------------------------------

	public function test_register_adds_my_library_shortcode_and_asset_hook(): void {
		( new MyLibraryPage() )->register();

		self::assertArrayHasKey( MyLibraryPage::SHORTCODE, $GLOBALS['connectlibrary_test_shortcodes'] );
		self::assertArrayHasKey( 'init', $GLOBALS['connectlibrary_test_hooks'] );
	}

	public function test_logged_out_visitor_sees_login_prompt_without_borrower_data(): void {
		$this->create_wp_user_borrower( 77, 'Adult Reader', 'Sensitive note' );
		$GLOBALS['connectlibrary_test_current_user_id'] = 0;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'connectlibrary-my-library--login', $html );
		self::assertStringContainsString( 'Please log in to view your library account', $html );
		self::assertStringNotContainsString( 'Adult Reader', $html );
		self::assertStringNotContainsString( 'Sensitive note', $html );
	}

	public function test_logged_in_user_without_active_borrower_sees_privacy_safe_setup_state(): void {
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'connectlibrary-my-library--empty', $html );
		self::assertStringContainsString( 'We do not have an active library record for this account yet.', $html );
		self::assertStringNotContainsString( 'borrower_id', $html );
		self::assertStringNotContainsString( 'wp_user_id', $html );
	}

	public function test_logged_in_borrower_sees_own_account_with_empty_loans(): void {
		$this->create_wp_user_borrower( 77, 'Adult Reader', 'Do not show this note' );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'Your library account', $html );
		self::assertStringContainsString( 'Adult Reader', $html );
		self::assertStringContainsString( 'No active checkouts.', $html );
		self::assertStringNotContainsString( 'Do not show this note', $html );
		self::assertStringNotContainsString( 'adult@example.test', $html );
	}

	public function test_guardian_sees_only_linked_active_child_section_shells(): void {
		$guardian = $this->create_wp_user_borrower( 77, 'Guardian Reader', 'Guardian private note' );
		$other    = ( new BorrowerService() )->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Other Guardian',
			)
		);
		self::assertIsArray( $other );
		$this->create_child_borrower( 'Linked Child', (int) $guardian['id'], 'Linked child private note' );
		$this->create_child_borrower( 'Unrelated Child', (int) $other['id'], 'Unrelated private note' );
		$this->create_child_borrower( 'Disabled Child', (int) $guardian['id'], 'Disabled note', 'disabled' );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'Linked child account', $html );
		self::assertStringContainsString( 'Linked Child', $html );
		self::assertStringNotContainsString( 'Unrelated Child', $html );
		self::assertStringNotContainsString( 'Disabled Child', $html );
		self::assertStringNotContainsString( 'Linked child private note', $html );
		self::assertStringNotContainsString( 'Unrelated private note', $html );
	}

	public function test_escaping_prevents_markup_and_private_note_leakage(): void {
		$guardian = $this->create_wp_user_borrower( 77, 'Guardian & Family', 'private_notes' );
		$this->create_child_borrower( 'Child & Youth', (int) $guardian['id'], 'child-secret' );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'Guardian &amp; Family', $html );
		self::assertStringContainsString( 'Child &amp; Youth', $html );
		self::assertStringNotContainsString( 'Guardian & Family', $html );
		self::assertStringNotContainsString( 'Child & Youth', $html );
		self::assertStringNotContainsString( 'private_notes', $html );
		self::assertStringNotContainsString( 'child-secret', $html );
	}

	public function test_valid_guest_token_attribute_renders_secure_guest_shell_without_private_data(): void {
		$borrower = $this->create_manual_borrower( 'Guest Reader', 'guest@example.test', 'guest private note' );
		$token    = 'guest-token-abcdefghijklmnopqrstuvwxyz123456';
		$this->create_guest_access_row( (int) $borrower['id'], $token, 'active', '2026-06-20 12:00:00' );

		$html = ( new MyLibraryPage() )->render_shortcode( array( 'guest_token' => $token ) );

		self::assertStringContainsString( 'connectlibrary-my-library--guest-access', $html );
		self::assertStringContainsString( 'Secure guest access', $html );
		self::assertStringContainsString( 'Guest library account', $html );
		self::assertStringContainsString( 'Guest Reader', $html );
		self::assertStringContainsString( 'No active checkouts.', $html );
		self::assertSame( 1, $GLOBALS['connectlibrary_test_nocache_headers'] );
		self::assertStringNotContainsString( 'guest@example.test', $html );
		self::assertStringNotContainsString( 'guest private note', $html );
		self::assertStringNotContainsString( $token, $html );
		self::assertStringNotContainsString( GuestAccessTokenService::hash_token( $token ), $html );
		self::assertStringNotContainsString( 'borrower_id', $html );
	}

	public function test_valid_guest_token_query_var_works_for_logged_out_request(): void {
		$borrower = $this->create_manual_borrower( 'Query Token Reader', 'query@example.test', 'query private note' );
		$token    = 'query-token-abcdefghijklmnopqrstuvwxyz123456';
		$this->create_guest_access_row( (int) $borrower['id'], $token, 'active', '2026-06-20 12:00:00' );
		$GLOBALS['connectlibrary_test_query_vars'][ MyLibraryPage::TOKEN_PARAM ] = $token;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'Secure guest access', $html );
		self::assertStringContainsString( 'Query Token Reader', $html );
		self::assertStringNotContainsString( 'Please log in to view your library account', $html );
	}

	/**
	 * Invalid guest-token states all render the same privacy-safe error.
	 *
	 * @param string $state Token failure state.
	 *
	 * @dataProvider privacy_safe_guest_token_failure_provider
	 */
	public function test_guest_token_failures_render_same_privacy_safe_error( string $state ): void {
		$borrower = $this->create_manual_borrower( 'Hidden Guest', 'hidden@example.test', 'hidden private note' );
		$token    = 'failure-token-abcdefghijklmnopqrstuvwxyz123456';

		if ( 'invalid' !== $state ) {
			$status      = 'revoked' === $state ? 'revoked' : 'active';
			$expires_at  = 'expired' === $state ? '2026-06-18 12:00:00' : '2026-06-20 12:00:00';
			$borrower_id = 'missing_borrower' === $state ? 9999 : (int) $borrower['id'];
			$this->create_guest_access_row( $borrower_id, $token, $status, $expires_at );
		}
		if ( 'disabled_borrower' === $state ) {
			$result = ( new BorrowerService() )->set_status( (int) $borrower['id'], 'disabled', 'test disabled borrower token path' );
			self::assertIsArray( $result );
		}

		$html = ( new MyLibraryPage() )->render_shortcode( array( 'guest_token' => $token ) );

		self::assertStringContainsString( 'connectlibrary-my-library--guest-error', $html );
		self::assertStringContainsString( 'This library link is no longer available. Please contact the librarian for a new link.', $html );
		self::assertStringNotContainsString( 'Hidden Guest', $html );
		self::assertStringNotContainsString( 'hidden@example.test', $html );
		self::assertStringNotContainsString( 'hidden private note', $html );
		self::assertStringNotContainsString( $token, $html );
		self::assertStringNotContainsString( 'expired', strtolower( $html ) );
		self::assertStringNotContainsString( 'revoked', strtolower( $html ) );
		self::assertSame( 0, $GLOBALS['connectlibrary_test_nocache_headers'] );
	}

	/**
	 * Return invalid guest-token states.
	 *
	 * @return array<string,array{state:string}>
	 */
	public static function privacy_safe_guest_token_failure_provider(): array {
		return array(
			'invalid'           => array( 'state' => 'invalid' ),
			'expired'           => array( 'state' => 'expired' ),
			'revoked'           => array( 'state' => 'revoked' ),
			'missing_borrower'  => array( 'state' => 'missing_borrower' ),
			'disabled_borrower' => array( 'state' => 'disabled_borrower' ),
		);
	}

	// -------------------------------------------------------------------------
	// Loan display — title, due date, overdue/current state
	// -------------------------------------------------------------------------

	public function test_active_loan_shows_book_title_and_due_date(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'The Great Adventure' );
		$this->seed_loan( (int) $borrower['id'], $book_id, '2026-07-15 12:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'The Great Adventure', $html );
		self::assertStringContainsString( 'July 15, 2026', $html );
		self::assertStringContainsString( 'Due', $html );
	}

	public function test_future_due_date_renders_current_state_not_overdue(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Future Book' );
		$this->seed_loan( (int) $borrower['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'loan-due--current', $html );
		self::assertStringNotContainsString( 'loan-due--overdue', $html );
	}

	public function test_past_due_date_renders_overdue_state(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Overdue Book' );
		// current_time stub returns '2026-06-19 12:00:00'; use a past date.
		$this->seed_loan( (int) $borrower['id'], $book_id, '2026-01-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'loan-due--overdue', $html );
		self::assertStringContainsString( 'aria-label="Overdue"', $html );
		self::assertStringNotContainsString( 'loan-due--current', $html );
	}

	public function test_loan_without_book_post_shows_fallback_library_item_title(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		// Use book_post_id 9999 which has no post object seeded.
		$this->seed_loan( (int) $borrower['id'], 9999, '2026-09-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'Library item', $html );
	}

	// -------------------------------------------------------------------------
	// Renewal form and eligibility
	// -------------------------------------------------------------------------

	public function test_eligible_loan_shows_renewal_form_with_nonce_fields(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Renewable Book' );
		$this->seed_loan( (int) $borrower['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'renew-form', $html );
		self::assertStringContainsString( 'connectlibrary_action', $html );
		self::assertStringContainsString( '_cl_renew_nonce', $html );
		self::assertStringContainsString( 'name="renewal_token"', $html );
		self::assertStringContainsString( 'type="submit"', $html );
		self::assertStringContainsString( 'Renew', $html );
		// Must not expose raw internal field names.
		self::assertStringNotContainsString( 'borrower_id', $html );
		self::assertStringNotContainsString( 'name="loan_id"', $html );
		self::assertStringNotContainsString( 'name="borrower_ref"', $html );
	}

	public function test_loan_at_renewal_limit_hides_renewal_form(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Maxed Book' );
		$this->seed_loan( (int) $borrower['id'], $book_id, '2026-09-01 00:00:00', renewal_count: 2, renewal_limit: 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringNotContainsString( 'renew-form', $html );
		self::assertStringNotContainsString( 'type="submit"', $html );
	}

	public function test_renewal_post_with_valid_nonce_shows_success_notice(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Renewable Book' );
		$this->seed_loan( (int) $borrower['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		// Render once to obtain the opaque renewal_token from the form HTML.
		$form_html     = ( new MyLibraryPage() )->render_shortcode();
		$renewal_token = $this->extract_renewal_token( $form_html );

		$_POST = array(
			'connectlibrary_action' => 'renew',
			'_cl_renew_nonce'       => 'connectlibrary-renew',
			'renewal_token'         => $renewal_token,
		);

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'renewal-success', $html );
		self::assertStringContainsString( 'renewed successfully', $html );
		// Raw internal field names must not appear anywhere in the output.
		self::assertStringNotContainsString( 'name="loan_id"', $html );
		self::assertStringNotContainsString( 'name="borrower_ref"', $html );
	}

	public function test_renewal_post_with_invalid_nonce_shows_error_not_success(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Book' );
		$this->seed_loan( (int) $borrower['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$_POST = array(
			'connectlibrary_action' => 'renew',
			'_cl_renew_nonce'       => '', // empty → wp_verify_nonce stub returns false
			'renewal_token'         => 'clrenew_not_a_valid_token',
		);

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'renewal-error', $html );
		self::assertStringNotContainsString( 'renewed successfully', $html );
	}

	public function test_renewal_denied_when_loan_belongs_to_different_borrower(): void {
		// Borrower A has a loan; user 77 (borrower B) tries to renew it.
		$borrower_a = $this->create_wp_user_borrower( 88, 'Other Reader' );
		$borrower_b = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id    = $this->create_book_post( 'Contested Book' );
		// Seed loan for borrower A.
		$this->seed_loan( (int) $borrower_a['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$_POST = array(
			'connectlibrary_action' => 'renew',
			'_cl_renew_nonce'       => 'connectlibrary-renew',
			'renewal_token'         => 'clrenew_not_a_valid_token', // token does not match any of borrower B's loans
		);

		$html = ( new MyLibraryPage() )->render_shortcode();

		// Must show error without leaking the other borrower's name or internal field names.
		self::assertStringContainsString( 'renewal-error', $html );
		self::assertStringNotContainsString( 'renewed successfully', $html );
		self::assertStringNotContainsString( 'Other Reader', $html );
		self::assertStringNotContainsString( 'name="loan_id"', $html );
		self::assertStringNotContainsString( 'name="borrower_ref"', $html );
	}

	public function test_renewal_denied_when_borrower_ref_out_of_range(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Book' );
		$this->seed_loan( (int) $borrower['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$_POST = array(
			'connectlibrary_action' => 'renew',
			'_cl_renew_nonce'       => 'connectlibrary-renew',
			'renewal_token'         => 'clrenew_no_such_slot', // token does not match any authorized loan
		);

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'renewal-error', $html );
		self::assertStringNotContainsString( 'renewed successfully', $html );
	}

	public function test_guardian_can_renew_linked_child_loan(): void {
		$guardian = $this->create_wp_user_borrower( 77, 'Guardian Reader' );
		$child    = $this->create_child_borrower( 'Linked Child', (int) $guardian['id'], '' );
		$book_id  = $this->create_book_post( 'Child Book' );
		// Seed loan for child (which is at authorized_borrowers index 1).
		$this->seed_loan( (int) $child['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		// Render once to obtain the child's opaque renewal_token.
		$form_html     = ( new MyLibraryPage() )->render_shortcode();
		$renewal_token = $this->extract_renewal_token( $form_html );

		$_POST = array(
			'connectlibrary_action' => 'renew',
			'_cl_renew_nonce'       => 'connectlibrary-renew',
			'renewal_token'         => $renewal_token,
		);

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'renewal-success', $html );
		self::assertStringContainsString( 'renewed successfully', $html );
	}

	// -------------------------------------------------------------------------
	// Reservations, holds, and waitlist display
	// -------------------------------------------------------------------------

	public function test_non_terminal_reservation_shows_book_title_and_status(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Reserved Book' );
		$this->seed_reservation( (int) $borrower['id'], $book_id, 'pending_approval' );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'Reserved Book', $html );
		self::assertStringContainsString( 'Pending Approval', $html );
		self::assertStringContainsString( 'reservation-item', $html );
	}

	public function test_active_hold_shows_expiry_date(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Hold Book' );
		$this->seed_reservation( (int) $borrower['id'], $book_id, 'active_hold', '2026-07-04 12:00:00' );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'Ready for Pickup', $html );
		self::assertStringContainsString( 'Hold expires', $html );
		self::assertStringContainsString( 'July 4, 2026', $html );
	}

	public function test_waitlisted_reservation_shows_without_expiry(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Waitlisted Book' );
		$this->seed_reservation( (int) $borrower['id'], $book_id, 'waitlisted' );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'Waitlisted', $html );
		self::assertStringNotContainsString( 'Hold expires', $html );
	}

	public function test_terminal_reservations_are_not_shown(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'Fulfilled Book' );
		$this->seed_reservation( (int) $borrower['id'], $book_id, 'fulfilled' );
		$this->seed_reservation( (int) $borrower['id'], $book_id, 'cancelled' );
		$this->seed_reservation( (int) $borrower['id'], $book_id, 'denied' );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringNotContainsString( 'reservation-item', $html );
	}

	public function test_reservations_do_not_expose_internal_ids_or_private_notes(): void {
		$borrower = $this->create_wp_user_borrower( 77, 'Adult Reader' );
		$book_id  = $this->create_book_post( 'My Book' );
		$this->seed_reservation(
			(int) $borrower['id'],
			$book_id,
			'pending_approval',
			null,
			'internal-notes-must-not-appear',
			'guest-email@example.test',
			'Guest Person Name'
		);
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		// Borrower-visible data.
		self::assertStringContainsString( 'My Book', $html );
		self::assertStringContainsString( 'Pending Approval', $html );
		// Internal data must be hidden.
		self::assertStringNotContainsString( 'internal-notes-must-not-appear', $html );
		self::assertStringNotContainsString( 'guest-email@example.test', $html );
		self::assertStringNotContainsString( 'Guest Person Name', $html );
		self::assertStringNotContainsString( 'reservation_id', $html );
		self::assertStringNotContainsString( 'borrower_id', $html );
		self::assertStringNotContainsString( 'guest_email', $html );
	}

	public function test_reservations_from_other_borrowers_not_shown(): void {
		$borrower_a = $this->create_wp_user_borrower( 77, 'Reader A' );
		$borrower_b = $this->create_manual_borrower( 'Reader B', 'b@example.test' );
		$book_id    = $this->create_book_post( 'Shared Interest Book' );
		$this->seed_reservation( (int) $borrower_b['id'], $book_id, 'pending_approval' );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringNotContainsString( 'reservation-item', $html );
	}

	// -------------------------------------------------------------------------
	// Guardian — child loans and reservations
	// -------------------------------------------------------------------------

	public function test_guardian_sees_child_loan_in_child_section(): void {
		$guardian = $this->create_wp_user_borrower( 77, 'Guardian Reader' );
		$child    = $this->create_child_borrower( 'Linked Child', (int) $guardian['id'], '' );
		$book_id  = $this->create_book_post( 'Child Book' );
		$this->seed_loan( (int) $child['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringContainsString( 'Linked child account', $html );
		self::assertStringContainsString( 'Child Book', $html );
	}

	public function test_guardian_cannot_see_other_guardian_child_loans(): void {
		$guardian_a = $this->create_wp_user_borrower( 77, 'Guardian A' );
		$guardian_b = $this->create_manual_borrower( 'Guardian B', 'gb@example.test' );
		$other_child = $this->create_child_borrower( 'Other Child', (int) $guardian_b['id'], '' );
		$book_id     = $this->create_book_post( 'Other Child Book' );
		$this->seed_loan( (int) $other_child['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );
		$GLOBALS['connectlibrary_test_current_user_id'] = 77;

		$html = ( new MyLibraryPage() )->render_shortcode();

		self::assertStringNotContainsString( 'Other Child', $html );
		self::assertStringNotContainsString( 'Other Child Book', $html );
	}

	// -------------------------------------------------------------------------
	// Guest token — loan visibility and renewal boundary
	// -------------------------------------------------------------------------

	public function test_guest_token_sees_own_active_loan(): void {
		$borrower = $this->create_manual_borrower( 'Guest Reader', 'guest@example.test' );
		$token    = 'guest-token-abcdefghijklmnopqrstuvwxyz123456';
		$this->create_guest_access_row( (int) $borrower['id'], $token, 'active', '2026-06-20 12:00:00' );
		$book_id = $this->create_book_post( 'Guest Book' );
		$this->seed_loan( (int) $borrower['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );

		$html = ( new MyLibraryPage() )->render_shortcode( array( 'guest_token' => $token ) );

		self::assertStringContainsString( 'Guest Book', $html );
		self::assertStringContainsString( 'Due', $html );
	}

	public function test_guest_token_renewal_succeeds_for_own_loan(): void {
		$borrower = $this->create_manual_borrower( 'Guest Reader', 'guest@example.test' );
		$token    = 'guest-token-abcdefghijklmnopqrstuvwxyz123456';
		$this->create_guest_access_row( (int) $borrower['id'], $token, 'active', '2026-06-20 12:00:00' );
		$book_id = $this->create_book_post( 'Guest Book' );
		$this->seed_loan( (int) $borrower['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );

		// Render once to obtain the guest's opaque renewal_token.
		$form_html     = ( new MyLibraryPage() )->render_shortcode( array( 'guest_token' => $token ) );
		$renewal_token = $this->extract_renewal_token( $form_html );

		$_POST = array(
			'connectlibrary_action' => 'renew',
			'_cl_renew_nonce'       => 'connectlibrary-renew',
			'renewal_token'         => $renewal_token,
		);

		$html = ( new MyLibraryPage() )->render_shortcode( array( 'guest_token' => $token ) );

		self::assertStringContainsString( 'renewal-success', $html );
	}

	public function test_guest_token_cannot_renew_another_borrowers_loan(): void {
		$guest_borrower = $this->create_manual_borrower( 'Guest Reader', 'guest@example.test' );
		$other_borrower = $this->create_manual_borrower( 'Other Reader', 'other@example.test' );
		$token          = 'guest-token-abcdefghijklmnopqrstuvwxyz123456';
		$this->create_guest_access_row( (int) $guest_borrower['id'], $token, 'active', '2026-06-20 12:00:00' );
		$book_id = $this->create_book_post( 'Other Book' );
		$this->seed_loan( (int) $other_borrower['id'], $book_id, '2026-09-01 00:00:00', 0, 2 );

		$_POST = array(
			'connectlibrary_action' => 'renew',
			'_cl_renew_nonce'       => 'connectlibrary-renew',
			'renewal_token'         => 'clrenew_not_a_valid_token', // token does not match guest borrower's (empty) loan list
		);

		$html = ( new MyLibraryPage() )->render_shortcode( array( 'guest_token' => $token ) );

		self::assertStringContainsString( 'renewal-error', $html );
		self::assertStringNotContainsString( 'renewed successfully', $html );
		// Must not expose the other borrower's name or internal IDs.
		self::assertStringNotContainsString( 'Other Reader', $html );
	}

	public function test_guest_token_does_not_show_children_sections(): void {
		$borrower = $this->create_manual_borrower( 'Guest Reader', 'guest@example.test' );
		$token    = 'guest-token-abcdefghijklmnopqrstuvwxyz123456';
		$this->create_guest_access_row( (int) $borrower['id'], $token, 'active', '2026-06-20 12:00:00' );

		$html = ( new MyLibraryPage() )->render_shortcode( array( 'guest_token' => $token ) );

		self::assertStringNotContainsString( 'Linked child account', $html );
	}

	// -------------------------------------------------------------------------
	// Seeding helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a WordPress-linked borrower.
	 *
	 * @param int    $wp_user_id    WordPress user ID.
	 * @param string $name          Display name.
	 * @param string $private_notes Private notes that must not render.
	 * @return array<string,mixed>
	 */
	private function create_wp_user_borrower( int $wp_user_id, string $name, string $private_notes = '' ): array {
		$borrower = ( new BorrowerService() )->create(
			array(
				'borrower_type' => 'wp_user',
				'wp_user_id'    => $wp_user_id,
				'display_name'  => $name,
				'email'         => 'adult@example.test',
				'private_notes' => $private_notes,
			)
		);

		self::assertIsArray( $borrower );
		return $borrower;
	}

	/**
	 * Create a manual borrower.
	 *
	 * @param string $name          Display name.
	 * @param string $email         Email address.
	 * @param string $private_notes Private notes.
	 * @return array<string,mixed>
	 */
	private function create_manual_borrower( string $name, string $email, string $private_notes = '' ): array {
		$borrower = ( new BorrowerService() )->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => $name,
				'email'         => $email,
				'private_notes' => $private_notes,
			)
		);

		self::assertIsArray( $borrower );
		return $borrower;
	}

	/**
	 * Create a guest access token row.
	 *
	 * @param int    $borrower_id Borrower ID.
	 * @param string $token       Raw token value.
	 * @param string $status      Token status.
	 * @param string $expires_at  Expiry datetime.
	 */
	private function create_guest_access_row( int $borrower_id, string $token, string $status, string $expires_at ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->insert(
			$tables['guest_access_tokens'],
			array(
				'borrower_id' => $borrower_id,
				'token_hash'  => GuestAccessTokenService::hash_token( $token ),
				'status'      => $status,
				'expires_at'  => $expires_at,
				'created_at'  => '2026-06-19 12:00:00',
				'created_by'  => null,
				'revoked_at'  => 'revoked' === $status ? '2026-06-19 12:30:00' : null,
			)
		);
	}

	/**
	 * Create a child borrower linked to a guardian.
	 *
	 * @param string $name         Display name.
	 * @param int    $guardian_id  Guardian borrower ID.
	 * @param string $private_notes Private notes.
	 * @param string $status       Borrower status (default active).
	 * @return array<string,mixed>
	 */
	private function create_child_borrower( string $name, int $guardian_id, string $private_notes, string $status = 'active' ): array {
		$borrower = ( new BorrowerService() )->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => $name,
				'guardian_borrower_id' => $guardian_id,
				'status'               => $status,
				'private_notes'        => $private_notes,
			)
		);

		self::assertIsArray( $borrower );
		return $borrower;
	}

	/**
	 * Create a fake book post and return its ID.
	 *
	 * @param string $title Post title.
	 */
	private function create_book_post( string $title ): int {
		$post_id = count( $GLOBALS['connectlibrary_test_post_objects'] ) + 1;
		$GLOBALS['connectlibrary_test_post_objects'][ $post_id ] = (object) array(
			'ID'          => $post_id,
			'post_type'   => 'cl_book',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => sanitize_title( $title ),
		);

		return $post_id;
	}

	/**
	 * Seed a loan row directly into the fake DB and return the loan ID.
	 *
	 * @param int    $borrower_id   Borrower ID.
	 * @param int    $book_post_id  Book post ID.
	 * @param string $due_at        Due datetime.
	 * @param int    $renewal_count Current renewal count.
	 * @param int    $renewal_limit Maximum renewals allowed.
	 */
	private function seed_loan(
		int $borrower_id,
		int $book_post_id,
		string $due_at = '2026-09-01 00:00:00',
		int $renewal_count = 0,
		int $renewal_limit = 2
	): int {
		$rows_key = $this->tables['loans'] . ':rows';
		if ( ! isset( $GLOBALS['connectlibrary_test_db_tables'][ $rows_key ] ) ) {
			$GLOBALS['connectlibrary_test_db_tables'][ $rows_key ] = array();
		}

		$now    = '2026-06-19 12:00:00';
		$new_id = count( $GLOBALS['connectlibrary_test_db_tables'][ $rows_key ] ) + 1;

		$GLOBALS['connectlibrary_test_db_tables'][ $rows_key ][] = array(
			'id'              => $new_id,
			'book_post_id'    => $book_post_id,
			'copy_id'         => null,
			'borrower_id'     => $borrower_id,
			'status'          => 'active',
			'checked_out_at'  => $now,
			'due_at'          => $due_at,
			'returned_at'     => null,
			'renewal_count'   => $renewal_count,
			'renewal_limit'   => $renewal_limit,
			'last_renewed_at' => null,
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		return $new_id;
	}

	/**
	 * Extract the first renewal_token value from rendered form HTML.
	 *
	 * @param string $html Rendered page HTML.
	 */
	private function extract_renewal_token( string $html ): string {
		if ( preg_match( '/name="renewal_token" value="([^"]+)"/', $html, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Seed a reservation row directly into the fake DB.
	 *
	 * @param int         $borrower_id   Borrower ID.
	 * @param int         $book_post_id  Book post ID.
	 * @param string      $status        Reservation status.
	 * @param string|null $hold_expires  Hold expiry datetime or null.
	 * @param string      $notes         Internal notes (must not render).
	 * @param string      $guest_email   Guest email (must not render).
	 * @param string      $guest_name    Guest name (must not render).
	 */
	private function seed_reservation(
		int $borrower_id,
		int $book_post_id,
		string $status,
		?string $hold_expires = null,
		string $notes = '',
		string $guest_email = '',
		string $guest_name = ''
	): void {
		$rows_key = $this->tables['reservations'] . ':rows';
		if ( ! isset( $GLOBALS['connectlibrary_test_db_tables'][ $rows_key ] ) ) {
			$GLOBALS['connectlibrary_test_db_tables'][ $rows_key ] = array();
		}

		$now    = '2026-06-19 12:00:00';
		$new_id = count( $GLOBALS['connectlibrary_test_db_tables'][ $rows_key ] ) + 1;

		$GLOBALS['connectlibrary_test_db_tables'][ $rows_key ][] = array(
			'id'             => $new_id,
			'book_post_id'   => $book_post_id,
			'copy_id'        => null,
			'borrower_id'    => $borrower_id,
			'guest_name'     => '' !== $guest_name ? $guest_name : null,
			'guest_email'    => '' !== $guest_email ? $guest_email : null,
			'status'         => $status,
			'hold_expires_at' => $hold_expires,
			'requested_at'   => $now,
			'created_at'     => $now,
			'updated_at'     => $now,
			'acted_by'       => null,
			'notes'          => '' !== $notes ? $notes : null,
			'context'        => null,
		);
	}
}
