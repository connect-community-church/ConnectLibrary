<?php
/**
 * Privacy-safe SVG/HTML rendering for borrower library cards.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Cards;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.FunctionCommentThrowTag.Missing,Squiz.Commenting.FunctionComment.ParamCommentFullStop,Generic.Commenting.DocComment.MissingShort,Generic.Metrics.CyclomaticComplexity.TooHigh,Generic.Metrics.NestingLevel.MaxExceeded,Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.PHP.YodaConditions.NotYoda,WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine,NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine

/**
 * Renders local, standards-compliant QR Model 2 and Code 128-B SVGs without network calls.
 */
final class BorrowerCardRenderer {
	private const QR_VERSION       = 4;
	private const QR_SIZE          = 33;
	private const QR_DATA_BYTES    = 80;
	private const QR_ECC_BYTES     = 20;
	private const QR_MAX_PAYLOAD   = 78;
	private const QR_MASK_PATTERN  = 0;
	private const CODE128_PATTERNS = array(
		'212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112'
	);

	/** Render a printable single-card HTML fragment. @param array<string,mixed> $borrower Borrower row. @param array<string,mixed> $card Card row. */
	public function render_card_html( array $borrower, array $card ): string {
		$payload = (string) ( $card['payload'] ?? '' );
		$name    = (string) ( $borrower['display_name'] ?? '' );
		$label   = (string) ( $card['card_label'] ?? '' );

		return '<section class="connectlibrary-card-print"><h1>' . esc_html__( 'Connect Community Church Library', 'connectlibrary' ) . '</h1>'
			. '<p class="borrower-name">' . esc_html( $name ) . '</p>'
			. '<p class="card-label">' . esc_html( $label ) . '</p>'
			. '<div class="codes">' . $this->qr_svg( $payload ) . $this->barcode_svg( $payload ) . '</div>'
			. '<p class="privacy-note">' . esc_html__( 'This card contains an opaque library token only; no contact details, guardian details, notes, or loan history are printed.', 'connectlibrary' ) . '</p></section>';
	}

	/** Render a print sheet containing several cards. @param array<int,array{borrower:array<string,mixed>,card:array<string,mixed>}> $items Items. */
	public function render_sheet_html( array $items ): string {
		$html = '<div class="connectlibrary-card-sheet">';
		foreach ( $items as $item ) {
			$html .= $this->render_card_html( $item['borrower'], $item['card'] );
		}
		return $html . '</div>';
	}

	/** Build a standards-compliant QR Code Model 2, Version 4-L SVG from the opaque payload. */
	public function qr_svg( string $payload ): string {
		$matrix = $this->qr_matrix( $payload );
		$cell   = 4;
		$quiet  = 4;
		$width  = (string) ( ( self::QR_SIZE + ( $quiet * 2 ) ) * $cell );
		$svg    = '<svg class="connectlibrary-card-qr" role="img" aria-label="' . esc_attr__( 'Library card QR code', 'connectlibrary' ) . '" width="' . esc_attr( $width ) . '" height="' . esc_attr( $width ) . '" viewBox="0 0 ' . esc_attr( $width ) . ' ' . esc_attr( $width ) . '" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#fff"/>';
		for ( $y = 0; $y < self::QR_SIZE; ++$y ) {
			for ( $x = 0; $x < self::QR_SIZE; ++$x ) {
				if ( $matrix[ $y ][ $x ] ) {
					$svg .= '<rect x="' . esc_attr( (string) ( ( $x + $quiet ) * $cell ) ) . '" y="' . esc_attr( (string) ( ( $y + $quiet ) * $cell ) ) . '" width="4" height="4" fill="#111"/>';
				}
			}
		}
		return $svg . '</svg>';
	}

	/** Build a standards-compliant Code 128-B barcode SVG from the opaque payload. */
	public function barcode_svg( string $payload ): string {
		$values = $this->code128_code_values( $payload );
		$x      = 20;
		$scale  = 2;
		$height = 72;
		$width  = (string) ( ( ( ( count( $values ) - 1 ) * 11 ) + 13 ) * $scale + ( $x * 2 ) );
		$svg    = '<svg class="connectlibrary-card-barcode" role="img" aria-label="' . esc_attr__( 'Library card barcode', 'connectlibrary' ) . '" width="' . esc_attr( $width ) . '" height="72" viewBox="0 0 ' . esc_attr( $width ) . ' 72" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="72" fill="#fff"/>';
		foreach ( $values as $value ) {
			$pattern = self::CODE128_PATTERNS[ $value ];
			for ( $i = 0; $i < strlen( $pattern ); ++$i ) {
				$w = (int) $pattern[ $i ] * $scale;
				if ( 0 === $i % 2 ) {
					$svg .= '<rect x="' . esc_attr( (string) $x ) . '" y="8" width="' . esc_attr( (string) $w ) . '" height="48" fill="#111"/>';
				}
				$x += $w;
			}
		}
		return $svg . '</svg>';
	}

	/** Return Code 128-B symbol values including start, check, and stop. @return array<int,int> */
	public function code128_code_values( string $payload ): array {
		$values = array( 104 );
		$sum    = 104;
		$length = strlen( $payload );
		for ( $i = 0; $i < $length; ++$i ) {
			$ascii = ord( $payload[ $i ] );
			if ( $ascii < 32 || $ascii > 126 ) {
				throw new \InvalidArgumentException( 'Borrower card payload contains a character that Code 128-B cannot encode.' );
			}
			$value    = $ascii - 32;
			$values[] = $value;
			$sum     += $value * ( $i + 1 );
		}
		$values[] = $sum % 103;
		$values[] = 106;
		return $values;
	}

	/** Return a QR module matrix. @return array<int,array<int,bool>> */
	public function qr_matrix( string $payload ): array {
		if ( strlen( $payload ) > self::QR_MAX_PAYLOAD ) {
			throw new \InvalidArgumentException( 'Borrower card payload is too long for the local QR encoder.' );
		}

		$modules  = array_fill( 0, self::QR_SIZE, array_fill( 0, self::QR_SIZE, false ) );
		$reserved = array_fill( 0, self::QR_SIZE, array_fill( 0, self::QR_SIZE, false ) );
		$this->qr_draw_function_patterns( $modules, $reserved );

		$data_bytes = $this->qr_data_codewords( $payload );
		$ecc_bytes  = $this->reed_solomon_remainder( $data_bytes, self::QR_ECC_BYTES );
		$bits       = $this->bytes_to_bits( array_merge( $data_bytes, $ecc_bytes ) );
		$this->qr_draw_codewords( $modules, $reserved, $bits );
		$this->qr_apply_mask( $modules, $reserved );
		$this->qr_draw_format_bits( $modules, $reserved );
		return $modules;
	}

	/** @param array<int,array<int,bool>> $modules @param array<int,array<int,bool>> $reserved */
	private function qr_draw_function_patterns( array &$modules, array &$reserved ): void {
		$this->qr_draw_finder( $modules, $reserved, 3, 3 );
		$this->qr_draw_finder( $modules, $reserved, self::QR_SIZE - 4, 3 );
		$this->qr_draw_finder( $modules, $reserved, 3, self::QR_SIZE - 4 );
		for ( $i = 0; $i < self::QR_SIZE; ++$i ) {
			$this->qr_set_function( $modules, $reserved, 6, $i, 0 === $i % 2 );
			$this->qr_set_function( $modules, $reserved, $i, 6, 0 === $i % 2 );
		}
		$this->qr_draw_alignment( $modules, $reserved, 26, 26 );
		$this->qr_set_function( $modules, $reserved, 8, 25, true );
		for ( $i = 0; $i < 9; ++$i ) {
			$this->qr_set_reserved( $reserved, 8, $i );
			$this->qr_set_reserved( $reserved, $i, 8 );
		}
		for ( $i = self::QR_SIZE - 8; $i < self::QR_SIZE; ++$i ) {
			$this->qr_set_reserved( $reserved, 8, $i );
			$this->qr_set_reserved( $reserved, $i, 8 );
		}
	}

	/** @param array<int,array<int,bool>> $modules @param array<int,array<int,bool>> $reserved */
	private function qr_draw_finder( array &$modules, array &$reserved, int $cx, int $cy ): void {
		for ( $dy = -4; $dy <= 4; ++$dy ) {
			for ( $dx = -4; $dx <= 4; ++$dx ) {
				$x = $cx + $dx;
				$y = $cy + $dy;
				if ( $x < 0 || $x >= self::QR_SIZE || $y < 0 || $y >= self::QR_SIZE ) {
					continue;
				}
				$on = max( abs( $dx ), abs( $dy ) ) !== 4 && ( max( abs( $dx ), abs( $dy ) ) === 3 || max( abs( $dx ), abs( $dy ) ) <= 1 );
				$this->qr_set_function( $modules, $reserved, $x, $y, $on );
			}
		}
	}

	/** @param array<int,array<int,bool>> $modules @param array<int,array<int,bool>> $reserved */
	private function qr_draw_alignment( array &$modules, array &$reserved, int $cx, int $cy ): void {
		for ( $dy = -2; $dy <= 2; ++$dy ) {
			for ( $dx = -2; $dx <= 2; ++$dx ) {
				$this->qr_set_function( $modules, $reserved, $cx + $dx, $cy + $dy, max( abs( $dx ), abs( $dy ) ) !== 1 );
			}
		}
	}

	/** @param array<int,array<int,bool>> $modules @param array<int,array<int,bool>> $reserved */
	private function qr_set_function( array &$modules, array &$reserved, int $x, int $y, bool $on ): void {
		if ( $x < 0 || $x >= self::QR_SIZE || $y < 0 || $y >= self::QR_SIZE ) {
			return;
		}
		$modules[ $y ][ $x ]  = $on;
		$reserved[ $y ][ $x ] = true;
	}

	/** @param array<int,array<int,bool>> $reserved */
	private function qr_set_reserved( array &$reserved, int $x, int $y ): void {
		if ( $x >= 0 && $x < self::QR_SIZE && $y >= 0 && $y < self::QR_SIZE ) {
			$reserved[ $y ][ $x ] = true;
		}
	}

	/** @return array<int,int> */
	private function qr_data_codewords( string $payload ): array {
		$bits = array( 0, 1, 0, 0 );
		foreach ( $this->int_to_bits( strlen( $payload ), 8 ) as $bit ) {
			$bits[] = $bit;
		}
		foreach ( str_split( $payload ) as $char ) {
			foreach ( $this->int_to_bits( ord( $char ), 8 ) as $bit ) {
				$bits[] = $bit;
			}
		}
		$remaining = ( self::QR_DATA_BYTES * 8 ) - count( $bits );
		for ( $i = 0; $i < min( 4, $remaining ); ++$i ) {
			$bits[] = 0;
		}
		while ( 0 !== count( $bits ) % 8 ) {
			$bits[] = 0;
		}
		$bytes = $this->bits_to_bytes( $bits );
		$pad   = true;
		while ( count( $bytes ) < self::QR_DATA_BYTES ) {
			$bytes[] = $pad ? 0xec : 0x11;
			$pad     = ! $pad;
		}
		return $bytes;
	}

	/** @return array<int,int> */
	private function int_to_bits( int $value, int $width ): array {
		$bits = array();
		for ( $i = $width - 1; $i >= 0; --$i ) {
			$bits[] = ( $value >> $i ) & 1;
		}
		return $bits;
	}

	/** @param array<int,int> $bits @return array<int,int> */
	private function bits_to_bytes( array $bits ): array {
		$bytes = array();
		for ( $i = 0; $i < count( $bits ); $i += 8 ) {
			$value = 0;
			for ( $j = 0; $j < 8; ++$j ) {
				$value = ( $value << 1 ) | ( $bits[ $i + $j ] ?? 0 );
			}
			$bytes[] = $value;
		}
		return $bytes;
	}

	/** @param array<int,int> $bytes @return array<int,int> */
	private function bytes_to_bits( array $bytes ): array {
		$bits = array();
		foreach ( $bytes as $byte ) {
			foreach ( $this->int_to_bits( $byte, 8 ) as $bit ) {
				$bits[] = $bit;
			}
		}
		return $bits;
	}

	/** @param array<int,int> $data @return array<int,int> */
	private function reed_solomon_remainder( array $data, int $degree ): array {
		$generator = $this->reed_solomon_generator( $degree );
		$result    = array_fill( 0, $degree, 0 );
		foreach ( $data as $byte ) {
			$factor = $byte ^ $result[0];
			array_shift( $result );
			$result[] = 0;
			for ( $i = 0; $i < $degree; ++$i ) {
				$result[ $i ] ^= $this->gf_multiply( $generator[ $i ], $factor );
			}
		}
		return $result;
	}

	/** @return array<int,int> */
	private function reed_solomon_generator( int $degree ): array {
		$result = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root = 1;
		for ( $i = 0; $i < $degree; ++$i ) {
			for ( $j = 0; $j < $degree; ++$j ) {
				$result[ $j ] = $this->gf_multiply( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = $this->gf_multiply( $root, 2 );
		}
		return $result;
	}

	private function gf_multiply( int $x, int $y ): int {
		$product = 0;
		for ( $i = 7; $i >= 0; --$i ) {
			$product = ( $product << 1 ) ^ ( ( $product >> 7 ) * 0x11d );
			if ( 0 !== ( ( $y >> $i ) & 1 ) ) {
				$product ^= $x;
			}
		}
		return $product & 0xff;
	}

	/** @param array<int,array<int,bool>> $modules @param array<int,array<int,bool>> $reserved @param array<int,int> $bits */
	private function qr_draw_codewords( array &$modules, array $reserved, array $bits ): void {
		$bit_index = 0;
		$upward    = true;
		for ( $right = self::QR_SIZE - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				--$right;
			}
			for ( $vert = 0; $vert < self::QR_SIZE; ++$vert ) {
				$y = $upward ? self::QR_SIZE - 1 - $vert : $vert;
				for ( $j = 0; $j < 2; ++$j ) {
					$x = $right - $j;
					if ( ! $reserved[ $y ][ $x ] ) {
						$modules[ $y ][ $x ] = 1 === ( $bits[ $bit_index ] ?? 0 );
						++$bit_index;
					}
				}
			}
			$upward = ! $upward;
		}
	}

	/** @param array<int,array<int,bool>> $modules @param array<int,array<int,bool>> $reserved */
	private function qr_apply_mask( array &$modules, array $reserved ): void {
		for ( $y = 0; $y < self::QR_SIZE; ++$y ) {
			for ( $x = 0; $x < self::QR_SIZE; ++$x ) {
				if ( ! $reserved[ $y ][ $x ] && 0 === ( ( $x + $y ) % 2 ) ) {
					$modules[ $y ][ $x ] = ! $modules[ $y ][ $x ];
				}
			}
		}
	}

	/** @param array<int,array<int,bool>> $modules @param array<int,array<int,bool>> $reserved */
	private function qr_draw_format_bits( array &$modules, array &$reserved ): void {
		$format = $this->qr_format_bits();
		for ( $i = 0; $i <= 5; ++$i ) {
			$this->qr_set_function( $modules, $reserved, 8, $i, 0 !== ( ( $format >> $i ) & 1 ) );
		}
		$this->qr_set_function( $modules, $reserved, 8, 7, 0 !== ( ( $format >> 6 ) & 1 ) );
		$this->qr_set_function( $modules, $reserved, 8, 8, 0 !== ( ( $format >> 7 ) & 1 ) );
		$this->qr_set_function( $modules, $reserved, 7, 8, 0 !== ( ( $format >> 8 ) & 1 ) );
		for ( $i = 9; $i < 15; ++$i ) {
			$this->qr_set_function( $modules, $reserved, 14 - $i, 8, 0 !== ( ( $format >> $i ) & 1 ) );
		}
		for ( $i = 0; $i < 8; ++$i ) {
			$this->qr_set_function( $modules, $reserved, self::QR_SIZE - 1 - $i, 8, 0 !== ( ( $format >> $i ) & 1 ) );
		}
		for ( $i = 8; $i < 15; ++$i ) {
			$this->qr_set_function( $modules, $reserved, 8, self::QR_SIZE - 15 + $i, 0 !== ( ( $format >> $i ) & 1 ) );
		}
		$this->qr_set_function( $modules, $reserved, 8, 25, true );
	}

	private function qr_format_bits(): int {
		$data = ( 1 << 3 ) | self::QR_MASK_PATTERN;
		$bits = $data << 10;
		for ( $i = 14; $i >= 10; --$i ) {
			if ( 0 !== ( ( $bits >> $i ) & 1 ) ) {
				$bits ^= 0x537 << ( $i - 10 );
			}
		}
		return ( ( $data << 10 ) | $bits ) ^ 0x5412;
	}
}
