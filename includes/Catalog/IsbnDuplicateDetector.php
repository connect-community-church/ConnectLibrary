<?php
/**
 * ISBN duplicate detection for the Book catalog.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use ConnectLibrary\Database\Schema;

/**
 * Searches book_metadata and copies tables for books sharing equivalent ISBNs.
 */
final class IsbnDuplicateDetector {
	/**
	 * Repository used to resolve book relationships.
	 *
	 * @var BookRelationshipsRepository
	 */
	private BookRelationshipsRepository $relationships;

	/**
	 * Constructor.
	 *
	 * @param BookRelationshipsRepository|null $relationships Optional repository; defaults to a new instance.
	 */
	public function __construct( ?BookRelationshipsRepository $relationships = null ) {
		$this->relationships = $relationships ?? new BookRelationshipsRepository();
	}

	/**
	 * Return duplicate summaries for a raw ISBN, excluding an optional current post.
	 *
	 * @param mixed $isbn             Raw ISBN input (any format).
	 * @param int   $exclude_post_id  Post ID to exclude; 0 to skip exclusion.
	 * @return array<int,array<string,mixed>> Safe per-book summaries, deduplicated by book_post_id.
	 */
	public function detect( mixed $isbn, int $exclude_post_id = 0 ): array {
		$candidates = Isbn::equivalents( $isbn );
		if ( empty( $candidates ) ) {
			return array();
		}

		global $wpdb;
		$tables = Schema::table_names();
		$found  = array(); // Keyed by book_post_id to deduplicate across tables and ISBN forms.

		foreach ( $candidates as $candidate ) {
			$col = 'isbn_10' === Isbn::type( $candidate ) ? 'isbn_10' : 'isbn_13';

			foreach ( array( $tables['book_metadata'], $tables['copies'] ) as $table ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT book_post_id, isbn_10, isbn_13 FROM {$table} WHERE {$col} = %s", $candidate ),
					ARRAY_A
				);
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$id = absint( $row['book_post_id'] ?? 0 );
					if ( $id > 0 && $id !== $exclude_post_id && ! isset( $found[ $id ] ) ) {
						$found[ $id ] = $row;
					}
				}
			}
		}

		return array_values( array_map( array( $this, 'build_summary' ), $found ) );
	}

	/**
	 * Build a safe public summary for a matched book row.
	 *
	 * @param array<string,mixed> $isbn_row Row with book_post_id, isbn_10, isbn_13.
	 * @return array<string,mixed>
	 */
	private function build_summary( array $isbn_row ): array {
		$book_id = absint( $isbn_row['book_post_id'] ?? 0 );

		global $wpdb;
		$tables = Schema::table_names();
		$copy   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT visibility, item_status FROM {$tables['copies']} WHERE book_post_id = %d ORDER BY copy_number ASC, id ASC LIMIT 1",
				$book_id
			),
			ARRAY_A
		);

		$author_ids  = $this->relationships->get_author_ids( $book_id );
		$all_authors = $this->relationships->list_authors();
		$author_map  = array();
		foreach ( $all_authors as $a ) {
			$author_map[ absint( $a['id'] ?? 0 ) ] = (string) ( $a['display_name'] ?? '' );
		}
		$authors = array_values(
			array_filter(
				array_map(
					static fn ( int $id ): string => $author_map[ $id ] ?? '',
					$author_ids
				)
			)
		);

		return array(
			'book_id'     => $book_id,
			'title'       => get_the_title( $book_id ),
			'edit_link'   => admin_url( 'post.php?action=edit&post=' . $book_id ),
			'isbn_10'     => (string) ( $isbn_row['isbn_10'] ?? '' ),
			'isbn_13'     => (string) ( $isbn_row['isbn_13'] ?? '' ),
			'visibility'  => is_array( $copy ) ? (string) ( $copy['visibility'] ?? '' ) : '',
			'item_status' => is_array( $copy ) ? (string) ( $copy['item_status'] ?? '' ) : '',
			'authors'     => $authors,
		);
	}
}
