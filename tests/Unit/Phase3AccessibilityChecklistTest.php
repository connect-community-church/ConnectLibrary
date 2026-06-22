<?php
/**
 * Tests for Phase 3 accessibility/keyboard/scanner checklist documentation.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing

use PHPUnit\Framework\TestCase;

/** Ensures the reviewer-facing checklist covers required Phase 3 surfaces. */
final class Phase3AccessibilityChecklistTest extends TestCase {
	private string $document;

	protected function setUp(): void {
		$path = dirname( __DIR__, 2 ) . '/docs/phase-3-librarian-accessibility-keyboard-scanner-checklist.md';
		self::assertFileExists( $path );
		$this->document = (string) file_get_contents( $path );
	}

	public function test_checklist_covers_required_librarian_surfaces(): void {
		foreach ( array( 'Dashboard', 'Quick circulation', 'ISBN add/lookup', 'Borrower/card scan', 'Printable cards/sheets', 'Reports/exports', 'Audit/history', 'Safe overrides' ) as $phrase ) {
			self::assertStringContainsString( $phrase, $this->document );
		}
	}

	public function test_checklist_documents_scanner_suffixes_and_no_global_listener(): void {
		self::assertStringContainsString( 'CR/LF/TAB', $this->document );
		self::assertStringContainsString( 'no hidden global key listener', $this->document );
		self::assertStringContainsString( 'visible field', $this->document );
	}

	public function test_checklist_preserves_privacy_safety_and_offline_scope(): void {
		self::assertStringContainsString( 'must not confirm dangerous actions', $this->document );
		self::assertStringContainsString( 'raw borrower/card tokens', $this->document );
		self::assertStringContainsString( 'Offline/PWA remains Phase 5', $this->document );
	}
}
