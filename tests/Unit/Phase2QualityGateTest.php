<?php
/**
 * Tests for the Phase 2 quality gate command.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Phase 2 QA/security/accessibility/i18n quality gate stays wired.
 */
final class Phase2QualityGateTest extends TestCase {
	public function test_quality_gate_command_passes_and_prints_required_lines(): void {
		$root    = dirname( __DIR__, 2 );
		$command = 'php ' . escapeshellarg( $root . '/bin/check-phase2-quality-gate.php' );
		$output  = array();
		$status  = 1;

		exec( $command, $output, $status );

		$text = implode( "\n", $output );
		self::assertSame( 0, $status, $text );
		self::assertStringContainsString( 'PASS: Phase 2 QA plan exists', $text );
		self::assertStringContainsString( 'PASS: Composer exposes phase2:quality-gate and composer check includes it', $text );
		self::assertStringNotContainsString( 'FAIL:', $text );
	}

	public function test_quality_gate_script_uses_repository_relative_paths(): void {
		$root   = dirname( __DIR__, 2 );
		$script = (string) file_get_contents( $root . '/bin/check-phase2-quality-gate.php' );

		self::assertStringContainsString( 'dirname( __DIR__ )', $script );
		self::assertStringContainsString( 'docs/phase-2-privacy-security-accessibility-i18n-test-plan.md', $script );
		self::assertStringContainsString( 'composer.json', $script );
	}
}
