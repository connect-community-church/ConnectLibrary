<?php
/**
 * Author and series relationship persistence for books.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use ConnectLibrary\Database\Schema;

/**
 * Uses Build 04 custom author/series relationship tables.
 */
final class BookRelationshipsRepository {
	/**
	 * List authors linked to any of the given public book IDs.
	 *
	 * Only authors attached to at least one of $book_ids appear; private-only
	 * authors do not leak into the public filter list.
	 *
	 * @param int[] $book_ids Public visible book post IDs.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_public_authors( array $book_ids ): array {
		if ( empty( $book_ids ) ) {
			return array();
		}

		$linked = array();
		foreach ( $book_ids as $book_id ) {
			foreach ( $this->get_author_ids( $book_id ) as $author_id ) {
				$linked[ $author_id ] = true;
			}
		}

		return array_values(
			array_filter(
				$this->list_authors(),
				static fn( array $a ): bool => isset( $linked[ absint( $a['id'] ?? 0 ) ] )
			)
		);
	}

	/**
	 * List series linked to any of the given public book IDs.
	 *
	 * @param int[] $book_ids Public visible book post IDs.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_public_series( array $book_ids ): array {
		if ( empty( $book_ids ) ) {
			return array();
		}

		$linked = array();
		foreach ( $book_ids as $book_id ) {
			$sel = $this->get_series_selection( $book_id );
			$id  = absint( $sel['series_id'] ?? 0 );
			if ( $id > 0 ) {
				$linked[ $id ] = true;
			}
		}

		return array_values(
			array_filter(
				$this->list_series(),
				static fn( array $s ): bool => isset( $linked[ absint( $s['id'] ?? 0 ) ] )
			)
		);
	}

	/**
	 * List available authors.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_authors(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT id, display_name, slug FROM {$tables['authors']} ORDER BY display_name ASC LIMIT 200", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List available series records.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_series(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT id, name, slug FROM {$tables['series']} ORDER BY name ASC LIMIT 200", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get author IDs attached to a book.
	 *
	 * @param int $book_id Book post ID.
	 * @return int[]
	 */
	public function get_author_ids( int $book_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT author_id FROM {$tables['book_authors']} WHERE book_post_id = %d ORDER BY position ASC, author_id ASC", $book_id ) );

		return array_map( 'absint', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Get the primary series relationship for a book.
	 *
	 * @param int $book_id Book post ID.
	 * @return array{series_id:int,series_position:string}
	 */
	public function get_series_selection( int $book_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT series_id, series_position FROM {$tables['book_series']} WHERE book_post_id = %d ORDER BY series_id ASC LIMIT 1", $book_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return array(
				'series_id'       => 0,
				'series_position' => '',
			);
		}

		return array(
			'series_id'       => absint( $row['series_id'] ?? 0 ),
			'series_position' => (string) ( $row['series_position'] ?? '' ),
		);
	}

	/**
	 * Save author and series relationships.
	 *
	 * @param int                 $book_id Book post ID.
	 * @param array<string,mixed> $fields Sanitized fields.
	 */
	public function save( int $book_id, array $fields ): void {
		$author_ids = $fields['author_ids'] ?? array();
		if ( ! is_array( $author_ids ) ) {
			$author_ids = array();
		}

		if ( ! empty( $fields['new_author_display_name'] ) ) {
			$author_ids[] = $this->create_author( (string) $fields['new_author_display_name'] );
		}

		$this->save_author_ids( $book_id, $author_ids );

		$series_id = absint( $fields['series_id'] ?? 0 );
		if ( 0 === $series_id && ! empty( $fields['new_series_name'] ) ) {
			$series_id = $this->create_series( (string) $fields['new_series_name'] );
		}

		$this->save_series( $book_id, $series_id, (string) ( $fields['series_position'] ?? '' ) );
	}

	/**
	 * Create a basic author record.
	 */
	public function create_author( string $display_name ): int {
		global $wpdb;

		$tables       = Schema::table_names();
		$display_name = sanitize_text_field( $display_name );
		if ( '' === $display_name ) {
			return 0;
		}

		$slug     = $this->unique_slug( $tables['authors'], 'slug', sanitize_title( $display_name ) );
		$sort     = $this->sort_name( $display_name );
		$now      = current_time( 'mysql' );
		$inserted = $wpdb->insert(
			$tables['authors'],
			array(
				'display_name' => $display_name,
				'sort_name'    => $sort,
				'slug'         => $slug,
				'bio'          => null,
				'external_ids' => null,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);

		return false === $inserted ? 0 : absint( $wpdb->insert_id );
	}

	/**
	 * Create a basic series record.
	 */
	public function create_series( string $name ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$name   = sanitize_text_field( $name );
		if ( '' === $name ) {
			return 0;
		}

		$slug     = $this->unique_slug( $tables['series'], 'slug', sanitize_title( $name ) );
		$now      = current_time( 'mysql' );
		$inserted = $wpdb->insert(
			$tables['series'],
			array(
				'name'         => $name,
				'sort_name'    => $name,
				'slug'         => $slug,
				'description'  => null,
				'external_ids' => null,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);

		return false === $inserted ? 0 : absint( $wpdb->insert_id );
	}

	/**
	 * Replace author links for a book.
	 *
	 * @param int   $book_id Book post ID.
	 * @param int[] $author_ids Author IDs.
	 */
	private function save_author_ids( int $book_id, array $author_ids ): void {
		global $wpdb;

		$tables     = Schema::table_names();
		$author_ids = array_values( array_unique( array_filter( array_map( 'absint', $author_ids ) ) ) );
		$wpdb->delete( $tables['book_authors'], array( 'book_post_id' => $book_id ) );

		$position = 0;
		foreach ( $author_ids as $author_id ) {
			$wpdb->insert(
				$tables['book_authors'],
				array(
					'book_post_id' => $book_id,
					'author_id'    => $author_id,
					'role'         => 'author',
					'position'     => $position,
					'created_at'   => current_time( 'mysql' ),
				)
			);
			++$position;
		}
	}

	/**
	 * Replace primary series link for a book.
	 */
	private function save_series( int $book_id, int $series_id, string $series_position ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->delete( $tables['book_series'], array( 'book_post_id' => $book_id ) );

		if ( $series_id <= 0 ) {
			return;
		}

		$wpdb->insert(
			$tables['book_series'],
			array(
				'book_post_id'    => $book_id,
				'series_id'       => $series_id,
				'series_position' => $series_position,
				'position_sort'   => is_numeric( $series_position ) ? (float) $series_position : null,
				'created_at'      => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Create a unique slug for a custom table.
	 */
	private function unique_slug( string $table, string $column, string $base_slug ): string {
		global $wpdb;

		$base_slug = '' !== $base_slug ? $base_slug : 'item';
		$slug      = $base_slug;
		$suffix    = 2;

		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} = %s", $slug ) ) > 0 ) {
			$slug = $base_slug . '-' . $suffix;
			++$suffix;
		}

		return $slug;
	}

	/**
	 * Build a simple sort name.
	 */
	private function sort_name( string $display_name ): string {
		$parts = preg_split( '/\s+/', trim( $display_name ) );
		if ( is_array( $parts ) && count( $parts ) > 1 ) {
			$last = array_pop( $parts );
			return $last . ', ' . implode( ' ', $parts );
		}

		return $display_name;
	}
}
