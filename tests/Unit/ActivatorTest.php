<?php
/**
 * Tests for ConnectLibrary activation behavior.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use ConnectLibrary\Activator;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies activation bootstraps required non-destructive plugin state.
 */
final class ActivatorTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_options']     = array();
		$GLOBALS['connectlibrary_test_dbdelta']     = array();
		$GLOBALS['connectlibrary_test_post_types']  = array();
		$GLOBALS['connectlibrary_test_taxonomies']  = array();
		$GLOBALS['connectlibrary_test_flush_count'] = 0;
		$GLOBALS['connectlibrary_test_roles']       = array(
			'administrator' => new \ConnectLibrary_Test_Role(
				array(
					Capabilities::MANAGE_OPTIONS => true,
				)
			),
		);
	}

	public function test_activation_grants_borrower_menu_capability_to_administrators(): void {
		Activator::activate();

		self::assertTrue(
			$GLOBALS['connectlibrary_test_roles']['administrator']->has_cap( Capabilities::MANAGE_BORROWERS ),
			'Administrators that satisfy can_manage_borrowers() via manage_options must receive the dedicated menu capability on activation.'
		);
	}
}
