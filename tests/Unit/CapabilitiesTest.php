<?php
/**
 * Tests for ConnectLibrary borrower capability helpers.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Capabilities helper enforces the manage_options fallback rule.
 */
final class CapabilitiesTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array();
	}

	public function test_can_manage_borrowers_with_explicit_capability(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => true,
			Capabilities::MANAGE_OPTIONS   => false,
		);

		self::assertTrue( Capabilities::can_manage_borrowers() );
	}

	public function test_can_manage_borrowers_via_manage_options_fallback(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => false,
			Capabilities::MANAGE_OPTIONS   => true,
		);

		self::assertTrue( Capabilities::can_manage_borrowers() );
	}

	public function test_cannot_manage_borrowers_without_either_capability(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => false,
			Capabilities::MANAGE_OPTIONS   => false,
		);

		self::assertFalse( Capabilities::can_manage_borrowers() );
	}

	public function test_borrower_capabilities_returns_expected_list(): void {
		self::assertSame(
			array(
				Capabilities::MANAGE_BORROWERS,
				Capabilities::MANAGE_OPTIONS,
			),
			Capabilities::borrower_capabilities()
		);
	}

	public function test_manage_borrowers_constant_value(): void {
		self::assertSame( 'manage_connectlibrary_borrowers', Capabilities::MANAGE_BORROWERS );
	}
}
