<?php
/**
 * Tests for ConnectLibrary settings defaults and sanitization.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use ConnectLibrary\Database\Schema;
use ConnectLibrary\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Phase 1 settings helper is safe for later cards to reuse.
 */
final class SettingsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['connectlibrary_test_options']         = array(
            'admin_email' => array(
                'value'    => 'admin@example.test',
                'autoload' => null,
            ),
        );
        $GLOBALS['connectlibrary_test_posts']           = array();
        $GLOBALS['connectlibrary_test_settings_errors'] = array();
    }

    public function test_defaults_are_safe_when_option_is_missing(): void {
        $settings = Settings::all();

        self::assertSame( 1, $settings['settings_version'] );
        self::assertSame( 'Connect Community Church', $settings['library_name'] );
        self::assertSame( 'admin@example.test', $settings['librarian_email'] );
        self::assertSame( 0, $settings['catalog_page_id'] );
        self::assertSame( 'grid', $settings['catalog_layout'] );
        self::assertSame( 'available', $settings['default_availability_status'] );
        self::assertSame( 'google_books_open_library', $settings['metadata_provider_order'] );
        self::assertSame( 1, $settings['import_covers_locally'] );
        self::assertSame( 'en', $settings['metadata_language'] );
        self::assertSame( 14, $settings['default_loan_period_days'] );
        self::assertSame( 14, $settings['default_hold_period_days'] );
        self::assertSame( 3, $settings['due_reminder_lead_days'] );
        self::assertSame( 1, $settings['preserve_data_on_uninstall'] );
    }

    public function test_sanitize_accepts_valid_values(): void {
        $GLOBALS['connectlibrary_test_posts'][123] = 'page';

        $settings = Settings::sanitize(
            array(
                'library_name'                => ' Kids Library ',
                'librarian_email'             => ' librarian@example.test ',
                'pickup_instructions'         => "Pickup <strong>Sunday</strong>\nDesk",
                'catalog_page_id'             => '123',
                'catalog_layout'              => 'list',
                'default_availability_status' => 'checked_out',
                'metadata_provider_order'     => 'open_library_google_books',
                'import_covers_locally'       => '1',
                'metadata_language'           => 'en_CA',
                'default_loan_period_days'    => '21',
                'default_hold_period_days'    => '7',
                'due_reminder_lead_days'      => '2',
                'preserve_data_on_uninstall'  => '1',
            )
        );

        self::assertSame( 'Kids Library', $settings['library_name'] );
        self::assertSame( 'librarian@example.test', $settings['librarian_email'] );
        self::assertSame( "Pickup Sunday\nDesk", $settings['pickup_instructions'] );
        self::assertSame( 123, $settings['catalog_page_id'] );
        self::assertSame( 'list', $settings['catalog_layout'] );
        self::assertSame( 'checked_out', $settings['default_availability_status'] );
        self::assertSame( 'open_library_google_books', $settings['metadata_provider_order'] );
        self::assertSame( 1, $settings['import_covers_locally'] );
        self::assertSame( 'en_ca', $settings['metadata_language'] );
        self::assertSame( 21, $settings['default_loan_period_days'] );
        self::assertSame( 7, $settings['default_hold_period_days'] );
        self::assertSame( 2, $settings['due_reminder_lead_days'] );
        self::assertSame( 1, $settings['preserve_data_on_uninstall'] );
        self::assertSame( array(), $GLOBALS['connectlibrary_test_settings_errors'] );
    }

    public function test_save_writes_audit_event_for_changed_circulation_setting(): void {
        // Establish a known previous saved state (default loan period = 14).
        $previous = Settings::defaults();
        $GLOBALS['connectlibrary_test_options'][ Settings::OPTION_NAME ] = array(
            'value'    => $previous,
            'autoload' => false,
        );
        $GLOBALS['connectlibrary_test_current_user_id']    = 7;
        $GLOBALS['connectlibrary_test_cron_events']        = array();
        $GLOBALS['connectlibrary_test_db_insert_failures'] = array();

        $audit_key = Schema::table_names()['audit_events'] . ':rows';
        $GLOBALS['connectlibrary_test_db_tables'][ $audit_key ] = array();

        Settings::save( array( 'default_loan_period_days' => 21 ) );

        $events = $GLOBALS['connectlibrary_test_db_tables'][ $audit_key ] ?? array();
        self::assertCount( 1, $events );

        $event = $events[0];
        self::assertSame( 'settings_updated', $event['action'] );
        self::assertSame( 'settings', $event['entity_type'] );
        self::assertSame( 'user', $event['actor_type'] );
        self::assertSame( 7, (int) $event['actor_id'] );

        $context = json_decode( (string) $event['context_json'], true );
        self::assertSame( 'default_loan_period_days', $context['setting_key'] );

        $before = json_decode( (string) $event['before_json'], true );
        self::assertSame( 14, (int) $before['value'] );

        $after = json_decode( (string) $event['after_json'], true );
        self::assertSame( 21, (int) $after['value'] );
    }

    public function test_save_does_not_audit_non_circulation_only_change(): void {
        // catalog_layout is not a circulation key — no audit event should be written.
        $previous = Settings::defaults();
        $GLOBALS['connectlibrary_test_options'][ Settings::OPTION_NAME ] = array(
            'value'    => $previous,
            'autoload' => false,
        );
        $GLOBALS['connectlibrary_test_cron_events']        = array();
        $GLOBALS['connectlibrary_test_db_insert_failures'] = array();

        $audit_key = Schema::table_names()['audit_events'] . ':rows';
        $GLOBALS['connectlibrary_test_db_tables'][ $audit_key ] = array();

        Settings::save( array( 'catalog_layout' => 'list' ) );

        $events = $GLOBALS['connectlibrary_test_db_tables'][ $audit_key ] ?? array();
        self::assertCount( 0, $events );
    }

    public function test_invalid_values_fall_back_and_add_settings_errors(): void {
        $previous = Settings::defaults();
        $previous['librarian_email']             = 'saved@example.test';
        $previous['catalog_page_id']             = 42;
        $previous['catalog_layout']              = 'grid';
        $previous['default_availability_status'] = 'reserved';
        $previous['default_loan_period_days']    = 30;
        $GLOBALS['connectlibrary_test_options'][ Settings::OPTION_NAME ] = array(
            'value'    => $previous,
            'autoload' => false,
        );

        $settings = Settings::sanitize(
            array(
                'library_name'                => 'ConnectLibrary',
                'librarian_email'             => 'not an email',
                'catalog_page_id'             => 'abc',
                'catalog_layout'              => 'tiles',
                'default_availability_status' => 'lost',
                'metadata_provider_order'     => 'unknown',
                'metadata_language'           => 'this-is-far-too-long',
                'default_loan_period_days'    => '999',
                'default_hold_period_days'    => '0',
                'due_reminder_lead_days'      => '99',
            )
        );

        self::assertSame( 'saved@example.test', $settings['librarian_email'] );
        self::assertSame( 42, $settings['catalog_page_id'] );
        self::assertSame( 'grid', $settings['catalog_layout'] );
        self::assertSame( 'reserved', $settings['default_availability_status'] );
        self::assertSame( 'google_books_open_library', $settings['metadata_provider_order'] );
        self::assertSame( 'en', $settings['metadata_language'] );
        self::assertSame( 30, $settings['default_loan_period_days'] );
        self::assertSame( 14, $settings['default_hold_period_days'] );
        self::assertSame( 3, $settings['due_reminder_lead_days'] );
        self::assertGreaterThanOrEqual( 7, count( $GLOBALS['connectlibrary_test_settings_errors'] ) );
    }
}
