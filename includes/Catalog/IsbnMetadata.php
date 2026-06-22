<?php
/**
 * Provider-neutral ISBN metadata mapping helpers.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

use ConnectLibrary\Support\Statuses;

/**
 * Builds normalized metadata suggestions from provider payloads.
 */
final class IsbnMetadata {
	/**
	 * Empty provider-neutral metadata suggestion.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'title'                 => '',
			'subtitle'              => '',
			'authors'               => array(),
			'isbn_10'               => '',
			'isbn_13'               => '',
			'publisher'             => '',
			'published_date'        => '',
			'description'           => '',
			'page_count'            => 0,
			'language'              => '',
			'categories'            => array(),
			'subjects'              => array(),
			'catalog_identifiers'   => '',
			'classifications'       => '',
			'physical_description'  => '',
			'provider_notes'        => '',
			'maturity_rating'       => '',
			'average_rating'        => null,
			'ratings_count'         => null,
			'source_provider'       => '',
			'source_record_id'      => '',
			'source_record_link'    => '',
			'cover_url_candidates'  => array(),
			'metadata_source'       => Statuses::METADATA_UNKNOWN,
			'last_metadata_refresh' => '',
		);
	}

	/**
	 * Map a Google Books API response into the neutral structure.
	 *
	 * @param array<string,mixed> $payload Decoded provider response.
	 * @return array<string,mixed>
	 */
	public static function from_google_books( array $payload ): array {
		$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
		if ( empty( $items ) || ! is_array( $items[0] ?? null ) ) {
			return self::defaults();
		}

		$item        = $items[0];
		$volume      = isset( $item['volumeInfo'] ) && is_array( $item['volumeInfo'] ) ? $item['volumeInfo'] : array();
		$image       = isset( $volume['imageLinks'] ) && is_array( $volume['imageLinks'] ) ? $volume['imageLinks'] : array();
		$identifiers = self::industry_identifiers( $volume['industryIdentifiers'] ?? array() );
		$links       = array();
		foreach ( array( 'extraLarge', 'large', 'medium', 'small', 'thumbnail', 'smallThumbnail' ) as $key ) {
			if ( ! empty( $image[ $key ] ) ) {
				$links[] = esc_url_raw( (string) $image[ $key ] );
			}
		}

		return array_merge(
			self::defaults(),
			array(
				'title'                => sanitize_text_field( $volume['title'] ?? '' ),
				'subtitle'             => sanitize_text_field( $volume['subtitle'] ?? '' ),
				'authors'              => self::string_list( $volume['authors'] ?? array() ),
				'isbn_10'              => $identifiers['isbn_10'],
				'isbn_13'              => $identifiers['isbn_13'],
				'publisher'            => sanitize_text_field( $volume['publisher'] ?? '' ),
				'published_date'       => sanitize_text_field( $volume['publishedDate'] ?? '' ),
				'description'          => sanitize_textarea_field( $volume['description'] ?? '' ),
				'page_count'           => max( 0, absint( $volume['pageCount'] ?? 0 ) ),
				'language'             => sanitize_text_field( $volume['language'] ?? '' ),
				'categories'           => self::string_list( $volume['categories'] ?? array() ),
				'maturity_rating'      => self::maturity_rating_label( $volume['maturityRating'] ?? '' ),
				'average_rating'       => isset( $volume['averageRating'] ) ? (float) $volume['averageRating'] : null,
				'ratings_count'        => isset( $volume['ratingsCount'] ) ? absint( $volume['ratingsCount'] ) : null,
				'source_provider'      => 'Google Books',
				'source_record_id'     => sanitize_text_field( $item['id'] ?? '' ),
				'source_record_link'   => esc_url_raw( $volume['infoLink'] ?? '' ),
				'cover_url_candidates' => array_values( array_unique( array_filter( $links ) ) ),
				'metadata_source'      => Statuses::METADATA_GOOGLE_BOOKS,
			)
		);
	}

	/**
	 * Map an Open Library ISBN response into the neutral structure.
	 *
	 * @param array<string,mixed> $payload Decoded provider response.
	 * @param string              $isbn Normalized ISBN used for the lookup.
	 * @return array<string,mixed>
	 */
	public static function from_open_library( array $payload, string $isbn ): array {
		$authors = array();
		foreach ( isset( $payload['authors'] ) && is_array( $payload['authors'] ) ? $payload['authors'] : array() as $author ) {
			if ( is_array( $author ) && ! empty( $author['name'] ) ) {
				$authors[] = (string) $author['name'];
			}
		}

		$covers = array();
		foreach ( isset( $payload['covers'] ) && is_array( $payload['covers'] ) ? $payload['covers'] : array() as $cover_id ) {
			$cover_id = absint( $cover_id );
			if ( $cover_id > 0 ) {
				$covers[] = 'https://covers.openlibrary.org/b/id/' . $cover_id . '-L.jpg';
			}
		}

		$key         = sanitize_text_field( $payload['key'] ?? '' );
		$identifiers = isset( $payload['identifiers'] ) && is_array( $payload['identifiers'] ) ? $payload['identifiers'] : array();
		$subjects    = self::string_list( $payload['subjects'] ?? array() );

		return array_merge(
			self::defaults(),
			array(
				'title'                => sanitize_text_field( $payload['title'] ?? '' ),
				'subtitle'             => sanitize_text_field( $payload['subtitle'] ?? '' ),
				'authors'              => self::string_list( $authors ),
				'isbn_10'              => self::first_string( $identifiers['isbn_10'] ?? array() ),
				'isbn_13'              => self::first_string( $identifiers['isbn_13'] ?? array( $isbn ) ),
				'publisher'            => self::first_string( $payload['publishers'] ?? array() ),
				'published_date'       => sanitize_text_field( $payload['publish_date'] ?? '' ),
				'description'          => self::description( $payload['description'] ?? '' ),
				'page_count'           => max( 0, absint( $payload['number_of_pages'] ?? 0 ) ),
				'language'             => self::first_language( $payload['languages'] ?? array() ),
				'subjects'             => $subjects,
				'catalog_identifiers'  => self::identifier_summary( $identifiers ),
				'classifications'      => self::classification_summary( $payload['classifications'] ?? array() ),
				'physical_description' => sanitize_text_field( $payload['pagination'] ?? '' ),
				'provider_notes'       => self::description( $payload['notes'] ?? '' ),
				'source_provider'      => 'Open Library',
				'source_record_id'     => $key,
				'source_record_link'   => '' !== $key ? 'https://openlibrary.org' . $key : 'https://openlibrary.org/isbn/' . rawurlencode( $isbn ),
				'cover_url_candidates' => array_values( array_unique( $covers ) ),
				'metadata_source'      => Statuses::METADATA_OPEN_LIBRARY,
			)
		);
	}

	/**
	 * Map an Open Library api/books response entry into the neutral structure.
	 *
	 * @param array<string,mixed> $payload Decoded api/books entry.
	 * @param string              $isbn Normalized ISBN used for the lookup.
	 * @return array<string,mixed>
	 */
	public static function from_open_library_api_book( array $payload, string $isbn ): array {
		$authors = array();
		foreach ( isset( $payload['authors'] ) && is_array( $payload['authors'] ) ? $payload['authors'] : array() as $author ) {
			if ( is_array( $author ) && ! empty( $author['name'] ) ) {
				$authors[] = (string) $author['name'];
			}
		}

		$publishers = array();
		foreach ( isset( $payload['publishers'] ) && is_array( $payload['publishers'] ) ? $payload['publishers'] : array() as $publisher ) {
			if ( is_array( $publisher ) && ! empty( $publisher['name'] ) ) {
				$publishers[] = (string) $publisher['name'];
			} elseif ( is_string( $publisher ) ) {
				$publishers[] = $publisher;
			}
		}

		$subjects = array();
		foreach ( isset( $payload['subjects'] ) && is_array( $payload['subjects'] ) ? $payload['subjects'] : array() as $subject ) {
			if ( is_array( $subject ) && ! empty( $subject['name'] ) ) {
				$subjects[] = (string) $subject['name'];
			} elseif ( is_string( $subject ) ) {
				$subjects[] = $subject;
			}
		}

		$covers = array();
		if ( ! empty( $payload['cover']['large'] ) ) {
			$covers[] = esc_url_raw( (string) $payload['cover']['large'] );
		}
		if ( ! empty( $payload['cover']['medium'] ) ) {
			$covers[] = esc_url_raw( (string) $payload['cover']['medium'] );
		}

		$key = sanitize_text_field( $payload['key'] ?? '' );
		$url = ! empty( $payload['url'] ) ? esc_url_raw( (string) $payload['url'] ) : ( '' !== $key ? 'https://openlibrary.org' . $key : 'https://openlibrary.org/isbn/' . rawurlencode( $isbn ) );

		return array_merge(
			self::defaults(),
			array(
				'title'                => sanitize_text_field( $payload['title'] ?? '' ),
				'subtitle'             => sanitize_text_field( $payload['subtitle'] ?? '' ),
				'authors'              => self::string_list( $authors ),
				'isbn_10'              => self::first_string( $payload['identifiers']['isbn_10'] ?? array() ),
				'isbn_13'              => self::first_string( $payload['identifiers']['isbn_13'] ?? array( $isbn ) ),
				'publisher'            => self::first_string( $publishers ),
				'published_date'       => sanitize_text_field( $payload['publish_date'] ?? '' ),
				'description'          => '',
				'page_count'           => max( 0, absint( $payload['number_of_pages'] ?? 0 ) ),
				'language'             => '',
				'subjects'             => self::string_list( $subjects ),
				'catalog_identifiers'  => self::identifier_summary( $payload['identifiers'] ?? array() ),
				'classifications'      => self::classification_summary( $payload['classifications'] ?? array() ),
				'physical_description' => sanitize_text_field( $payload['pagination'] ?? '' ),
				'provider_notes'       => self::description( $payload['notes'] ?? '' ),
				'source_provider'      => 'Open Library',
				'source_record_id'     => $key,
				'source_record_link'   => $url,
				'cover_url_candidates' => array_values( array_unique( array_filter( $covers ) ) ),
				'metadata_source'      => Statuses::METADATA_OPEN_LIBRARY,
			)
		);
	}

	/**
	 * Merge fallback fields into a primary result without overwriting conflicts.
	 *
	 * @param array<string,mixed> $primary Primary provider metadata.
	 * @param array<string,mixed> $fallback Fallback provider metadata.
	 * @return array<string,mixed>
	 */
	public static function fill_missing( array $primary, array $fallback ): array {
		$result = array_merge( self::defaults(), $primary );
		foreach ( self::defaults() as $key => $default ) {
			if ( in_array( $key, array( 'source_provider', 'source_record_id', 'source_record_link', 'metadata_source' ), true ) ) {
				continue;
			}
			$fallback_value = $fallback[ $key ] ?? $default;
			if ( self::is_empty_value( $result[ $key ] ?? $default ) && ! self::is_empty_value( $fallback_value ) ) {
				$result[ $key ] = $fallback_value;
			}
		}

		return $result;
	}

	/**
	 * Does this suggestion have the minimum useful fields for librarian review?
	 *
	 * @param array<string,mixed> $metadata Metadata suggestion.
	 */
	public static function is_useful( array $metadata ): bool {
		return '' !== (string) ( $metadata['title'] ?? '' ) || ! empty( $metadata['authors'] ) || '' !== (string) ( $metadata['publisher'] ?? '' );
	}

	/**
	 * Prepare metadata values that can be explicitly applied to the book editor.
	 *
	 * @param array<string,mixed> $metadata Metadata suggestion.
	 * @return array<string,mixed>
	 */
	public static function apply_fields( array $metadata ): array {
		$metadata = array_merge( self::defaults(), $metadata );

		return array(
			'title'                   => $metadata['title'],
			'subtitle'                => $metadata['subtitle'],
			'isbn_10'                 => $metadata['isbn_10'],
			'isbn_13'                 => $metadata['isbn_13'],
			'new_author_display_name' => implode( ', ', (array) $metadata['authors'] ),
			'publisher'               => $metadata['publisher'],
			'published_date'          => $metadata['published_date'],
			'content_notes'           => $metadata['description'],
			'page_count'              => $metadata['page_count'],
			'language'                => $metadata['language'],
			'catalog_identifiers'     => $metadata['catalog_identifiers'],
			'library_classifications' => $metadata['classifications'],
			'physical_description'    => $metadata['physical_description'],
			'provider_notes'          => $metadata['provider_notes'],
			'age_level'               => '',
			'metadata_source'         => $metadata['metadata_source'],
			'source_provider'         => $metadata['source_provider'],
			'source_record_id'        => $metadata['source_record_id'],
			'source_record_link'      => $metadata['source_record_link'],
			'last_metadata_refresh'   => current_time( 'mysql' ),
		);
	}

	/**
	 * Extract ISBN identifiers from Google Books industryIdentifiers.
	 *
	 * @param mixed $raw Raw identifier list.
	 * @return array{isbn_10:string,isbn_13:string}
	 */
	private static function industry_identifiers( mixed $raw ): array {
		$identifiers = array(
			'isbn_10' => '',
			'isbn_13' => '',
		);
		if ( ! is_array( $raw ) ) {
			return $identifiers;
		}

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type       = strtoupper( sanitize_text_field( $item['type'] ?? '' ) );
			$identifier = sanitize_text_field( $item['identifier'] ?? '' );
			if ( 'ISBN_10' === $type ) {
				$identifiers['isbn_10'] = $identifier;
			} elseif ( 'ISBN_13' === $type ) {
				$identifiers['isbn_13'] = $identifier;
			}
		}

		return $identifiers;
	}

	/**
	 * Convert a provider maturity code into a librarian-readable label.
	 *
	 * @param mixed $value Provider value.
	 */
	private static function maturity_rating_label( mixed $value ): string {
		$value = strtoupper( sanitize_text_field( (string) $value ) );
		if ( 'NOT_MATURE' === $value ) {
			return 'Not mature';
		}
		if ( 'MATURE' === $value ) {
			return 'Mature';
		}

		return '';
	}

	/**
	 * Summarize provider identifiers for librarian review.
	 *
	 * @param mixed $raw Raw identifiers map.
	 */
	private static function identifier_summary( mixed $raw ): string {
		if ( ! is_array( $raw ) ) {
			return '';
		}

		$labels = array(
			'isbn_10'      => 'ISBN-10',
			'isbn_13'      => 'ISBN-13',
			'oclc'         => 'OCLC',
			'lccn'         => 'LCCN',
			'openlibrary'  => 'Open Library',
			'goodreads'    => 'Goodreads',
			'librarything' => 'LibraryThing',
		);
		$lines  = array();
		foreach ( $labels as $key => $label ) {
			$values = self::string_list( $raw[ $key ] ?? array() );
			if ( ! empty( $values ) ) {
				$lines[] = $label . ': ' . implode( ', ', $values );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Summarize library classification data for librarian review.
	 *
	 * @param mixed $raw Raw classifications map.
	 */
	private static function classification_summary( mixed $raw ): string {
		if ( ! is_array( $raw ) ) {
			return '';
		}

		$labels = array(
			'lc_classifications'  => 'Library of Congress',
			'dewey_decimal_class' => 'Dewey',
		);
		$lines  = array();
		foreach ( $labels as $key => $label ) {
			$values = self::string_list( $raw[ $key ] ?? array() );
			if ( ! empty( $values ) ) {
				$lines[] = $label . ': ' . implode( ', ', $values );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Convert a list of strings to sanitized values.
	 *
	 * @param mixed $value Raw scalar or list.
	 */
	private static function string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		$strings = array();
		foreach ( $value as $item ) {
			$item = sanitize_text_field( $item );
			if ( '' !== $item ) {
				$strings[] = $item;
			}
		}

		return array_values( array_unique( $strings ) );
	}

	/**
	 * Get the first sanitized string from a scalar/list.
	 *
	 * @param mixed $value Raw scalar or list.
	 */
	private static function first_string( mixed $value ): string {
		$list = self::string_list( $value );

		return $list[0] ?? '';
	}

	/**
	 * Extract Open Library language code.
	 *
	 * @param mixed $languages Raw provider language list.
	 */
	private static function first_language( mixed $languages ): string {
		if ( ! is_array( $languages ) || empty( $languages[0] ) || ! is_array( $languages[0] ) ) {
			return '';
		}

		return sanitize_text_field( basename( (string) ( $languages[0]['key'] ?? '' ) ) );
	}

	/**
	 * Extract provider description field.
	 *
	 * @param mixed $description Raw provider description.
	 */
	private static function description( mixed $description ): string {
		if ( is_array( $description ) ) {
			$description = $description['value'] ?? '';
		}

		return sanitize_textarea_field( $description );
	}

	/**
	 * Determine empty mapped values.
	 *
	 * @param mixed $value Mapped value.
	 */
	private static function is_empty_value( mixed $value ): bool {
		return null === $value || '' === $value || array() === $value || 0 === $value;
	}
}
