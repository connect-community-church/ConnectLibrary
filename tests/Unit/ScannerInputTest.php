<?php
/**
 * Tests for scanner/manual field normalization.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Catalog\Isbn;
use ConnectLibrary\Support\ScannerInput;
use PHPUnit\Framework\TestCase;

/** Covers explicit field-scoped scanner normalization. */
final class ScannerInputTest extends TestCase {
	public function test_normalize_trims_manual_space_and_scanner_suffixes(): void {
		self::assertSame( 'ABC-123', ScannerInput::normalize( " \tABC-123\r\n\t " ) );
		self::assertSame( 'ABC 123', ScannerInput::normalize( "ABC 123\t" ) );
	}

	public function test_sanitize_text_rejects_non_scalar_values(): void {
		self::assertSame( '', ScannerInput::sanitize_text( array( 'bad' ) ) );
		self::assertSame( '', ScannerInput::sanitize_text( (object) array( 'bad' => true ) ) );
	}

	public function test_isbn_normalize_tolerates_scanner_suffixes(): void {
		self::assertSame( '9780310337508', Isbn::normalize( " 978-0-310-33750-8\r\n\t" ) );
	}
}
