<?php
/**
 * Scanner/manual input normalization helpers.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes field-scoped input from keyboard-wedge scanners and manual typing.
 *
 * USB/Bluetooth scanners often append Enter, CR/LF, or Tab after typing into the
 * focused field. These helpers intentionally operate only on explicit field
 * values passed by callers; they do not install any global key listener and do
 * not submit or confirm destructive actions on their own.
 */
final class ScannerInput {
	/**
	 * Normalize scanner/manual text by removing scanner suffixes and outer space.
	 *
	 * @param mixed $value Raw field value.
	 */
	public static function normalize( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$text = (string) $value;
		$text = preg_replace( '/[\r\n\t]+$/', '', $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Normalize and sanitize a single-line scanner/manual field.
	 *
	 * @param mixed $value Raw field value.
	 */
	public static function sanitize_text( mixed $value ): string {
		return sanitize_text_field( self::normalize( $value ) );
	}

	/**
	 * Normalize and sanitize a textarea field that may receive scanner suffixes.
	 *
	 * @param mixed $value Raw field value.
	 */
	public static function sanitize_textarea( mixed $value ): string {
		return sanitize_textarea_field( self::normalize( $value ) );
	}
}
