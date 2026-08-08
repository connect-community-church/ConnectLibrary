<?php
/**
 * Tests for public account registration, automatic borrower creation, and saved events.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment,Generic.Formatting.MultipleStatementAlignment.NotSameWarning

use ConnectLibrary\Account\AccountRegistrationService;
use ConnectLibrary\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the open-registration account foundation decided for the church website.
 */
final class AccountRegistrationServiceTest extends TestCase {
	/** @var array<string,string> */
	private array $tables;

	protected function setUp(): void {
		$this->tables = Schema::table_names();
		$GLOBALS['connectlibrary_test_db_tables']       = array();
		$GLOBALS['connectlibrary_test_hooks']           = array();
		$GLOBALS['connectlibrary_test_shortcodes']      = array();
		$GLOBALS['connectlibrary_test_users']           = array();
		$GLOBALS['connectlibrary_test_created_users']   = array();
		$GLOBALS['connectlibrary_test_user_meta']       = array();
		$GLOBALS['connectlibrary_test_options']         = array();
		$GLOBALS['connectlibrary_test_current_user_id'] = 0;
		$_POST = array();
		$_GET  = array();
	}

	public function test_register_hooks_user_registration_form_calendar_and_ical_endpoint(): void {
		( new AccountRegistrationService() )->register();

		self::assertArrayHasKey( 'user_register', $GLOBALS['connectlibrary_test_hooks'] );
		self::assertArrayHasKey( 'init', $GLOBALS['connectlibrary_test_hooks'] );
		self::assertArrayHasKey( 'template_redirect', $GLOBALS['connectlibrary_test_hooks'] );
		self::assertArrayHasKey( AccountRegistrationService::REGISTRATION_SHORTCODE, $GLOBALS['connectlibrary_test_shortcodes'] );
		self::assertArrayHasKey( AccountRegistrationService::CALENDAR_SHORTCODE, $GLOBALS['connectlibrary_test_shortcodes'] );
	}

	public function test_user_registration_automatically_creates_wp_user_borrower(): void {
		$GLOBALS['connectlibrary_test_users'][77] = (object) array(
			'ID'           => 77,
			'user_login'   => 'mike',
			'user_email'   => 'mike@example.test',
			'display_name' => 'Mike Fair',
		);

		$borrower = ( new AccountRegistrationService() )->ensure_borrower_for_user( 77 );

		self::assertIsArray( $borrower );
		self::assertSame( 'wp_user', $borrower['borrower_type'] );
		self::assertSame( 77, (int) $borrower['wp_user_id'] );
		self::assertSame( 'Mike Fair', $borrower['display_name'] );
		self::assertSame( 'mike@example.test', $borrower['email'] );
	}

	public function test_registration_form_creates_user_borrower_and_sends_verification_reminder_without_blocking(): void {
		$_POST = array(
			'connectlibrary_account_action' => 'register',
			'_cl_account_nonce'             => 'valid-nonce',
			'cl_account_hp'                 => '',
			'display_name'                  => 'New Reader',
			'email'                         => 'reader@example.test',
			'password'                      => 'reader-pass-123',
		);

		$service = new AccountRegistrationService();
		$service->handle_post();

		self::assertCount( 1, $GLOBALS['connectlibrary_test_created_users'] );
		self::assertSame( 'reader@example.test', $GLOBALS['connectlibrary_test_created_users'][0]['email'] );
		self::assertCount( 1, $GLOBALS['connectlibrary_test_db_tables'][ $this->tables['borrowers'] . ':rows' ] ?? array() );
		self::assertStringContainsString( 'verify', strtolower( $GLOBALS['connectlibrary_test_mail'][0]['subject'] ?? '' ) );
	}

	public function test_saved_events_round_trip_and_ical_feed_token_setting(): void {
		$GLOBALS['connectlibrary_test_users'][77] = (object) array(
			'ID'         => 77,
			'user_login' => 'reader',
			'user_email' => 'reader@example.test',
		);

		$service = new AccountRegistrationService();
		$service->save_event_for_user( 77, 123 );
		$service->save_event_for_user( 77, 456 );
		$service->set_ical_range_for_user( 77, 'all' );

		self::assertSame( array( 123, 456 ), $service->saved_events_for_user( 77 ) );
		self::assertSame( 'all', $service->ical_range_for_user( 77 ) );
		self::assertNotSame( '', $service->feed_token_for_user( 77 ) );
	}

	public function test_guest_event_prompt_links_to_registration_and_login(): void {
		$GLOBALS['connectlibrary_test_current_user_id'] = 0;
		$GLOBALS['connectlibrary_test_current_post_id'] = 321;
		$GLOBALS['connectlibrary_test_posts'][321]      = 'tribe_events';

		$html = ( new AccountRegistrationService() )->append_event_save_prompt( '<p>Event details</p>' );

		self::assertStringContainsString( 'Want to save this event to My Church Calendar?', $html );
		self::assertStringContainsString( 'Create an account', $html );
		self::assertStringContainsString( '/register/', $html );
		self::assertStringContainsString( 'Log in', $html );
	}
}
