<?php
/**
 * Tests for the ConnectLibrary setup wizard helpers.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use ConnectLibrary\Admin\SetupWizard;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Settings\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies setup wizard idempotency and safe settings writes.
 */
final class SetupWizardTest extends TestCase {
	/**
	 * Reset mutable WordPress stubs between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['connectlibrary_test_options']         = array(
			'admin_email' => array(
				'value'    => 'admin@example.test',
				'autoload' => null,
			),
		);
		$GLOBALS['connectlibrary_test_posts']           = array();
		$GLOBALS['connectlibrary_test_post_objects']    = array();
		$GLOBALS['connectlibrary_test_post_meta']       = array();
		$GLOBALS['connectlibrary_test_settings_errors'] = array();
	}

	/**
	 * Setup state defaults are present on fresh installs.
	 */
	public function test_settings_include_setup_state_defaults(): void {
		$settings = Settings::all();

		self::assertSame( '', $settings['setup_completed_at'] );
		self::assertSame( '', $settings['setup_dismissed_until'] );
		self::assertSame( '', $settings['demo_content_created_at'] );
	}

	/**
	 * Partial wizard saves preserve unrelated defaults.
	 */
	public function test_settings_save_persists_partial_setup_updates(): void {
		$settings = Settings::save(
			array(
				'setup_completed_at'       => '2026-06-19 12:00:00',
				'default_loan_period_days' => '21',
			)
		);

		self::assertSame( '2026-06-19 12:00:00', $settings['setup_completed_at'] );
		self::assertSame( 21, $settings['default_loan_period_days'] );
		self::assertSame( 14, $settings['default_hold_period_days'] );
		self::assertSame( $settings, $GLOBALS['connectlibrary_test_options'][ Settings::OPTION_NAME ]['value'] );
	}

	/**
	 * Catalog page creation reuses the existing page on rerun.
	 */
	public function test_catalog_page_creation_is_idempotent(): void {
		$wizard = new SetupWizard();

		$first_page_id  = $this->invokePrivate( $wizard, 'create_or_get_catalog_page' );
		$second_page_id = $this->invokePrivate( $wizard, 'create_or_get_catalog_page' );

		self::assertSame( 1, $first_page_id );
		self::assertSame( $first_page_id, $second_page_id );
		self::assertCount( 1, $GLOBALS['connectlibrary_test_post_objects'] );
		self::assertSame( 'page', $GLOBALS['connectlibrary_test_posts'][ $first_page_id ] );
		self::assertStringContainsString( '[connectlibrary_catalog]', $GLOBALS['connectlibrary_test_post_objects'][ $first_page_id ]->post_content );
	}

	/**
	 * Demo books are marked and not duplicated by repeated creation.
	 */
	public function test_demo_books_are_marked_and_not_duplicated(): void {
		$wizard = new SetupWizard();

		$this->invokePrivate( $wizard, 'create_demo_books' );
		$this->invokePrivate( $wizard, 'create_demo_books' );
		$demo_book_ids = $this->invokePrivate( $wizard, 'find_demo_books' );

		self::assertCount( 3, $demo_book_ids );
		foreach ( $demo_book_ids as $post_id ) {
			self::assertSame( BookPostType::POST_TYPE, $GLOBALS['connectlibrary_test_posts'][ $post_id ] );
			self::assertSame( '1', $GLOBALS['connectlibrary_test_post_meta'][ $post_id ]['_connectlibrary_demo_book'] );
		}
	}

	/**
	 * Invoke a private wizard helper.
	 *
	 * @param SetupWizard $wizard Setup wizard instance.
	 * @param string      $method Private method name.
	 * @return mixed
	 */
	private function invokePrivate( SetupWizard $wizard, string $method ): mixed {
		$reflection = new ReflectionClass( $wizard );
		$helper     = $reflection->getMethod( $method );

		return $helper->invoke( $wizard );
	}
}
