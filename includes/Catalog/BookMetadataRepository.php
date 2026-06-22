<?php
/**
 * Persistence for Book admin metadata.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use ConnectLibrary\Database\Schema;

/**
 * Saves and loads scalar book metadata backed by Phase 1 catalog tables.
 */
final class BookMetadataRepository {
	private const META_SOURCE_RECORD_LINK    = '_connectlibrary_source_record_link';
	private const META_LAST_METADATA_REFRESH = '_connectlibrary_last_metadata_refresh';
	private const META_CATALOG_IDENTIFIERS   = '_connectlibrary_catalog_identifiers';
	private const META_CLASSIFICATIONS       = '_connectlibrary_library_classifications';
	private const META_PHYSICAL_DESCRIPTION  = '_connectlibrary_physical_description';
	private const META_PROVIDER_NOTES        = '_connectlibrary_provider_notes';

	/**
	 * Load all fields for a book.
	 *
	 * @param int $book_id Book post ID.
	 * @return array<string,mixed>
	 */
	public function get( int $book_id ): array {
		global $wpdb;

		$fields = BookMetadata::defaults();
		$tables = Schema::table_names();

		$metadata = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['book_metadata']} WHERE book_post_id = %d", $book_id ),
			ARRAY_A
		);
		if ( is_array( $metadata ) ) {
			$fields                = array_merge(
				$fields,
				array_intersect_key(
					$metadata,
					array_flip(
						array(
							'isbn_10',
							'isbn_13',
							'subtitle',
							'publisher',
							'published_date',
							'language',
							'page_count',
							'age_level',
							'reading_level',
							'content_notes',
							'church_notes',
							'metadata_source',
						)
					)
				)
			);
			$fields['recommended'] = ! empty( $metadata['recommended'] );
		}

		$copy = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['copies']} WHERE book_post_id = %d ORDER BY copy_number ASC, id ASC LIMIT 1", $book_id ),
			ARRAY_A
		);
		if ( is_array( $copy ) ) {
			$fields = array_merge(
				$fields,
				array_intersect_key(
					$copy,
					array_flip(
						array(
							'visibility',
							'room',
							'shelf',
							'section',
							'condition_status',
							'item_status',
							'private_notes',
						)
					)
				)
			);
		}

		$source = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['import_sources']} WHERE book_post_id = %d ORDER BY id DESC LIMIT 1", $book_id ),
			ARRAY_A
		);
		if ( is_array( $source ) ) {
			$fields['source_provider']  = (string) ( $source['provider'] ?? '' );
			$fields['source_record_id'] = (string) ( $source['provider_record_id'] ?? '' );
		}

		$fields['source_record_link']      = (string) get_post_meta( $book_id, self::META_SOURCE_RECORD_LINK, true );
		$fields['last_metadata_refresh']   = (string) get_post_meta( $book_id, self::META_LAST_METADATA_REFRESH, true );
		$fields['catalog_identifiers']     = (string) get_post_meta( $book_id, self::META_CATALOG_IDENTIFIERS, true );
		$fields['library_classifications'] = (string) get_post_meta( $book_id, self::META_CLASSIFICATIONS, true );
		$fields['physical_description']    = (string) get_post_meta( $book_id, self::META_PHYSICAL_DESCRIPTION, true );
		$fields['provider_notes']          = (string) get_post_meta( $book_id, self::META_PROVIDER_NOTES, true );

		return $fields;
	}

	/**
	 * Save scalar fields.
	 *
	 * @param int                 $book_id Book post ID.
	 * @param array<string,mixed> $fields Sanitized fields.
	 */
	public function save( int $book_id, array $fields ): void {
		global $wpdb;

		$fields = array_merge( BookMetadata::defaults(), $fields );
		$tables = Schema::table_names();
		$now    = current_time( 'mysql' );

		$existing_metadata = $wpdb->get_var( $wpdb->prepare( "SELECT book_post_id FROM {$tables['book_metadata']} WHERE book_post_id = %d", $book_id ) );
		$metadata_row      = array(
			'book_post_id'        => $book_id,
			'subtitle'            => $fields['subtitle'],
			'isbn_10'             => $fields['isbn_10'],
			'isbn_13'             => $fields['isbn_13'],
			'publisher'           => $fields['publisher'],
			'published_date'      => $fields['published_date'],
			'language'            => $fields['language'],
			'page_count'          => (int) $fields['page_count'],
			'age_level'           => $fields['age_level'],
			'reading_level'       => $fields['reading_level'],
			'content_notes'       => $fields['content_notes'],
			'church_notes'        => $fields['church_notes'],
			'recommended'         => (bool) $fields['recommended'] ? 1 : 0,
			'cover_attachment_id' => get_post_thumbnail_id( $book_id ) ? get_post_thumbnail_id( $book_id ) : null,
			'metadata_source'     => $fields['metadata_source'],
			'updated_at'          => $now,
		);
		if ( empty( $existing_metadata ) ) {
			$metadata_row['created_at'] = $now;
			$wpdb->insert( $tables['book_metadata'], $metadata_row );
		} else {
			$wpdb->update( $tables['book_metadata'], $metadata_row, array( 'book_post_id' => $book_id ) );
		}

		$copy_id  = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['copies']} WHERE book_post_id = %d ORDER BY copy_number ASC, id ASC LIMIT 1", $book_id ) ) );
		$copy_row = array(
			'book_post_id'     => $book_id,
			'copy_number'      => 1,
			'isbn_10'          => $fields['isbn_10'],
			'isbn_13'          => $fields['isbn_13'],
			'condition_status' => $fields['condition_status'],
			'item_status'      => $fields['item_status'],
			'visibility'       => $fields['visibility'],
			'room'             => $fields['room'],
			'shelf'            => $fields['shelf'],
			'section'          => $fields['section'],
			'private_notes'    => $fields['private_notes'],
			'updated_at'       => $now,
		);
		if ( $copy_id > 0 ) {
			$wpdb->update( $tables['copies'], $copy_row, array( 'id' => $copy_id ) );
		} else {
			$copy_row['circulation_status'] = 'available';
			$copy_row['created_at']         = $now;
			$wpdb->insert( $tables['copies'], $copy_row );
		}

		$this->save_import_source( $book_id, $fields, $tables['import_sources'], $now );
		update_post_meta( $book_id, self::META_SOURCE_RECORD_LINK, $fields['source_record_link'] );
		update_post_meta( $book_id, self::META_LAST_METADATA_REFRESH, $fields['last_metadata_refresh'] );
		update_post_meta( $book_id, self::META_CATALOG_IDENTIFIERS, $fields['catalog_identifiers'] );
		update_post_meta( $book_id, self::META_CLASSIFICATIONS, $fields['library_classifications'] );
		update_post_meta( $book_id, self::META_PHYSICAL_DESCRIPTION, $fields['physical_description'] );
		update_post_meta( $book_id, self::META_PROVIDER_NOTES, $fields['provider_notes'] );
	}

	/**
	 * Save the latest source metadata reference.
	 *
	 * @param int                 $book_id Book post ID.
	 * @param array<string,mixed> $fields Sanitized fields.
	 * @param string              $table Import source table name.
	 * @param string              $now Current timestamp.
	 */
	private function save_import_source( int $book_id, array $fields, string $table, string $now ): void {
		global $wpdb;

		if ( '' === $fields['source_provider'] && '' === $fields['source_record_id'] ) {
			$wpdb->delete( $table, array( 'book_post_id' => $book_id ) );

			return;
		}

		$source_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE book_post_id = %d ORDER BY id DESC LIMIT 1", $book_id ) ) );
		$row       = array(
			'book_post_id'       => $book_id,
			'provider'           => '' !== $fields['source_provider'] ? $fields['source_provider'] : $fields['metadata_source'],
			'provider_record_id' => $fields['source_record_id'],
			'isbn_10'            => $fields['isbn_10'],
			'isbn_13'            => $fields['isbn_13'],
			'status'             => $fields['metadata_source'],
			'raw_payload'        => null,
			'created_at'         => $now,
		);

		if ( $source_id > 0 ) {
			$wpdb->update( $table, $row, array( 'id' => $source_id ) );
		} else {
			$wpdb->insert( $table, $row );
		}
	}
}
