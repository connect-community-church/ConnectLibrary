<?php
/**
 * Book metadata field registry and sanitization.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

use ConnectLibrary\Support\Statuses;

/**
 * Defines scoped admin metadata fields for librarian book editing.
 */
final class BookMetadata {
	public const VISIBILITY_PUBLIC = 'public';
	public const VISIBILITY_HIDDEN = 'hidden';

	/**
	 * Get public visibility choices.
	 *
	 * @return string[]
	 */
	public static function visibility_values(): array {
		return array( self::VISIBILITY_PUBLIC, self::VISIBILITY_HIDDEN );
	}

	/**
	 * Translatable admin labels for public visibility choices.
	 *
	 * @return array<string,string>
	 */
	public static function visibility_labels(): array {
		return array(
			self::VISIBILITY_PUBLIC => __( 'Public', 'connectlibrary' ),
			self::VISIBILITY_HIDDEN => __( 'Hidden', 'connectlibrary' ),
		);
	}

	/**
	 * Get default field values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'isbn_10'                 => '',
			'isbn_13'                 => '',
			'subtitle'                => '',
			'publisher'               => '',
			'published_date'          => '',
			'language'                => '',
			'page_count'              => 0,
			'age_level'               => '',
			'reading_level'           => '',
			'church_notes'            => '',
			'content_notes'           => '',
			'catalog_identifiers'     => '',
			'library_classifications' => '',
			'physical_description'    => '',
			'provider_notes'          => '',
			'recommended'             => false,
			'visibility'              => self::VISIBILITY_PUBLIC,
			'room'                    => '',
			'shelf'                   => '',
			'section'                 => '',
			'condition_status'        => Statuses::CONDITION_GOOD,
			'item_status'             => Statuses::ITEM_ACTIVE,
			'private_notes'           => '',
			'metadata_source'         => Statuses::METADATA_MANUAL,
			'source_provider'         => '',
			'source_record_id'        => '',
			'source_record_link'      => '',
			'last_metadata_refresh'   => '',
			'author_ids'              => array(),
			'new_author_display_name' => '',
			'series_id'               => 0,
			'new_series_name'         => '',
			'series_position'         => '',
		);
	}

	/**
	 * Sanitize submitted fields.
	 *
	 * @param array<string,mixed> $input Raw field input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();

		$output = array(
			'isbn_10'                 => self::clean_isbn( $input['isbn_10'] ?? '' ),
			'isbn_13'                 => self::clean_isbn( $input['isbn_13'] ?? '' ),
			'subtitle'                => sanitize_text_field( $input['subtitle'] ?? '' ),
			'publisher'               => sanitize_text_field( $input['publisher'] ?? '' ),
			'published_date'          => sanitize_text_field( $input['published_date'] ?? '' ),
			'language'                => sanitize_text_field( $input['language'] ?? '' ),
			'page_count'              => max( 0, absint( $input['page_count'] ?? 0 ) ),
			'age_level'               => sanitize_text_field( $input['age_level'] ?? '' ),
			'reading_level'           => sanitize_text_field( $input['reading_level'] ?? '' ),
			'church_notes'            => sanitize_textarea_field( $input['church_notes'] ?? '' ),
			'content_notes'           => sanitize_textarea_field( $input['content_notes'] ?? '' ),
			'catalog_identifiers'     => sanitize_textarea_field( $input['catalog_identifiers'] ?? '' ),
			'library_classifications' => sanitize_textarea_field( $input['library_classifications'] ?? '' ),
			'physical_description'    => sanitize_text_field( $input['physical_description'] ?? '' ),
			'provider_notes'          => sanitize_textarea_field( $input['provider_notes'] ?? '' ),
			'recommended'             => ! empty( $input['recommended'] ),
			'visibility'              => self::allowed_or_default( sanitize_key( $input['visibility'] ?? '' ), self::visibility_values(), $defaults['visibility'] ),
			'room'                    => sanitize_text_field( $input['room'] ?? '' ),
			'shelf'                   => sanitize_text_field( $input['shelf'] ?? '' ),
			'section'                 => sanitize_text_field( $input['section'] ?? '' ),
			'condition_status'        => self::allowed_or_default( sanitize_key( $input['condition_status'] ?? '' ), Statuses::condition_statuses(), $defaults['condition_status'] ),
			'item_status'             => self::allowed_or_default( sanitize_key( $input['item_status'] ?? '' ), Statuses::item_statuses(), $defaults['item_status'] ),
			'private_notes'           => sanitize_textarea_field( $input['private_notes'] ?? '' ),
			'metadata_source'         => self::allowed_or_default( sanitize_key( $input['metadata_source'] ?? '' ), Statuses::metadata_sources(), $defaults['metadata_source'] ),
			'source_provider'         => sanitize_text_field( $input['source_provider'] ?? '' ),
			'source_record_id'        => sanitize_text_field( $input['source_record_id'] ?? '' ),
			'source_record_link'      => esc_url_raw( $input['source_record_link'] ?? '' ),
			'last_metadata_refresh'   => sanitize_text_field( $input['last_metadata_refresh'] ?? '' ),
			'author_ids'              => self::sanitize_ids( $input['author_ids'] ?? array() ),
			'new_author_display_name' => sanitize_text_field( $input['new_author_display_name'] ?? '' ),
			'series_id'               => absint( $input['series_id'] ?? 0 ),
			'new_series_name'         => sanitize_text_field( $input['new_series_name'] ?? '' ),
			'series_position'         => sanitize_text_field( $input['series_position'] ?? '' ),
		);

		return $output;
	}

	/**
	 * Build a public-safe payload for future catalog serialization.
	 *
	 * @param array<string,mixed> $fields Full metadata fields.
	 * @return array<string,mixed>
	 */
	public static function public_payload( array $fields ): array {
		$fields = array_merge( self::defaults(), $fields );

		return array(
			'isbn_10'        => $fields['isbn_10'],
			'isbn_13'        => $fields['isbn_13'],
			'subtitle'       => $fields['subtitle'],
			'publisher'      => $fields['publisher'],
			'published_date' => $fields['published_date'],
			'language'       => $fields['language'],
			'page_count'     => $fields['page_count'],
			'age_level'      => $fields['age_level'],
			'reading_level'  => $fields['reading_level'],
			'church_notes'   => $fields['church_notes'],
			'content_notes'  => $fields['content_notes'],
			'recommended'    => (bool) $fields['recommended'],
			'visibility'     => $fields['visibility'],
		);
	}

	/**
	 * Strip ISBN down to safe characters.
	 *
	 * @param mixed $value Raw ISBN value.
	 */
	private static function clean_isbn( mixed $value ): string {
		return Isbn::normalize( $value );
	}

	/**
	 * Keep a value only if it is one of the allowed fixed options.
	 *
	 * @param string   $value Submitted value.
	 * @param string[] $allowed Allowed values.
	 * @param string   $default_value Default fallback.
	 */
	private static function allowed_or_default( string $value, array $allowed, string $default_value ): string {
		return in_array( $value, $allowed, true ) ? $value : $default_value;
	}

	/**
	 * Sanitize relationship IDs.
	 *
	 * @param mixed $ids Raw IDs.
	 * @return int[]
	 */
	private static function sanitize_ids( mixed $ids ): array {
		if ( ! is_array( $ids ) ) {
			$ids = array( $ids );
		}

		$clean = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
