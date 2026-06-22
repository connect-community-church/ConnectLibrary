<?php
/**
 * ISBN normalization and validation helpers.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

use ConnectLibrary\Support\ScannerInput;

/**
 * Normalizes and validates ISBN-10 and ISBN-13 values.
 */
final class Isbn {
	/**
	 * Normalize scanner/manual input to canonical ISBN characters.
	 *
	 * @param mixed $value Raw ISBN value.
	 */
	public static function normalize( mixed $value ): string {
		return strtoupper( preg_replace( '/[^0-9X]/i', '', ScannerInput::normalize( $value ) ) ?? '' );
	}

	/**
	 * Determine whether a value is a valid ISBN-10 or ISBN-13.
	 *
	 * @param mixed $value Raw ISBN value.
	 */
	public static function is_valid( mixed $value ): bool {
		$isbn = self::normalize( $value );

		return self::is_valid_isbn10( $isbn ) || self::is_valid_isbn13( $isbn );
	}

	/**
	 * Return a normalized ISBN-13, converting ISBN-10 via 978 prefix, or '' if invalid.
	 *
	 * @param mixed $value Raw ISBN value.
	 */
	public static function to_isbn13( mixed $value ): string {
		$isbn = self::normalize( $value );
		if ( self::is_valid_isbn13( $isbn ) ) {
			return $isbn;
		}
		if ( self::is_valid_isbn10( $isbn ) ) {
			$base = '978' . substr( $isbn, 0, 9 );
			$sum  = 0;
			for ( $i = 0; $i < 12; $i++ ) {
				$sum += (int) $base[ $i ] * ( 0 === $i % 2 ? 1 : 3 );
			}
			$check = ( 10 - ( $sum % 10 ) ) % 10;
			return $base . $check;
		}
		return '';
	}

	/**
	 * Return a normalized ISBN-10, converting 978-prefixed ISBN-13, or '' if invalid/non-978.
	 *
	 * @param mixed $value Raw ISBN value.
	 */
	public static function to_isbn10( mixed $value ): string {
		$isbn = self::normalize( $value );
		if ( self::is_valid_isbn10( $isbn ) ) {
			return $isbn;
		}
		if ( self::is_valid_isbn13( $isbn ) && str_starts_with( $isbn, '978' ) ) {
			$payload = substr( $isbn, 3, 9 );
			$sum     = 0;
			for ( $i = 0; $i < 9; $i++ ) {
				$sum += (int) $payload[ $i ] * ( 10 - $i );
			}
			$check = ( 11 - ( $sum % 11 ) ) % 11;
			return $payload . ( 10 === $check ? 'X' : (string) $check );
		}
		return '';
	}

	/**
	 * Return unique normalized valid ISBNs including converted equivalents, or [] if invalid.
	 *
	 * @param mixed $value Raw ISBN value.
	 */
	public static function equivalents( mixed $value ): array {
		$isbn = self::normalize( $value );
		if ( ! self::is_valid( $isbn ) ) {
			return array();
		}
		$result = array( $isbn );
		if ( self::is_valid_isbn10( $isbn ) ) {
			$result[] = self::to_isbn13( $isbn );
		} elseif ( self::is_valid_isbn13( $isbn ) ) {
			$isbn10 = self::to_isbn10( $isbn );
			if ( '' !== $isbn10 ) {
				$result[] = $isbn10;
			}
		}
		return array_values( array_unique( $result ) );
	}

	/**
	 * Return the ISBN type, or an empty string for invalid input.
	 *
	 * @param mixed $value Raw ISBN value.
	 */
	public static function type( mixed $value ): string {
		$isbn = self::normalize( $value );
		if ( self::is_valid_isbn10( $isbn ) ) {
			return 'isbn_10';
		}
		if ( self::is_valid_isbn13( $isbn ) ) {
			return 'isbn_13';
		}

		return '';
	}

	/**
	 * Validate an ISBN-10 checksum.
	 *
	 * @param string $isbn Normalized ISBN value.
	 */
	private static function is_valid_isbn10( string $isbn ): bool {
		if ( 1 !== preg_match( '/^[0-9]{9}[0-9X]$/', $isbn ) ) {
			return false;
		}

		$sum = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$digit = 'X' === $isbn[ $i ] ? 10 : (int) $isbn[ $i ];
			$sum  += ( 10 - $i ) * $digit;
		}

		return 0 === $sum % 11;
	}

	/**
	 * Validate an ISBN-13 checksum.
	 *
	 * @param string $isbn Normalized ISBN value.
	 */
	private static function is_valid_isbn13( string $isbn ): bool {
		if ( 1 !== preg_match( '/^[0-9]{13}$/', $isbn ) ) {
			return false;
		}

		$sum = 0;
		for ( $i = 0; $i < 12; $i++ ) {
			$sum += (int) $isbn[ $i ] * ( 0 === $i % 2 ? 1 : 3 );
		}

		$check = ( 10 - ( $sum % 10 ) ) % 10;

		return $check === (int) $isbn[12];
	}
}
