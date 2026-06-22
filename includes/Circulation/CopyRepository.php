<?php
/**
 * Repository for physical copy records.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Circulation;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use ConnectLibrary\Database\Schema;

/**
 * Low-level persistence for the copies table (Phase 2 circulation fields).
 */
final class CopyRepository {

	/**
	 * Insert a copy row.
	 *
	 * @param array<string,mixed> $row Copy row.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->insert( $tables['copies'], $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a copy row.
	 *
	 * @param int                 $id  Copy ID.
	 * @param array<string,mixed> $row Row changes.
	 */
	public function update( int $id, array $row ): bool {
		global $wpdb;

		$tables = Schema::table_names();

		return (bool) $wpdb->update( $tables['copies'], $row, array( 'id' => $id ) );
	}

	/**
	 * Get a single copy by ID.
	 *
	 * @param int $id Copy ID.
	 */
	public function get( int $id ): ?array {
		foreach ( $this->all() as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * All copies (full table scan).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['copies']} ORDER BY id ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return inventory rows for operational reports with constrained paging.
	 *
	 * @param array<string,mixed> $filters Supported: from, to, status, condition, call_number, search.
	 * @param int                 $limit   Maximum rows.
	 * @param int                 $offset  Pagination offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function report_inventory( array $filters, int $limit, int $offset ): array {
		global $wpdb;

		$tables      = Schema::table_names();
		$where       = array( '1=1' );
		$values      = array();
		$from        = (string) ( $filters['from'] ?? '' );
		$to          = (string) ( $filters['to'] ?? '' );
		$status      = sanitize_key( (string) ( $filters['status'] ?? '' ) );
		$condition   = sanitize_key( (string) ( $filters['condition'] ?? '' ) );
		$call_number = (string) ( $filters['call_number'] ?? '' );
		$search      = (string) ( $filters['search'] ?? '' );

		if ( '' !== $from ) {
			$where[]  = 'created_at >= %s';
			$values[] = $from;
		}
		if ( '' !== $to ) {
			$where[]  = 'created_at <= %s';
			$values[] = $to . ' 23:59:59';
		}
		if ( '' !== $status ) {
			$where[]  = '(status = %s OR circulation_status = %s)';
			$values[] = $status;
			$values[] = $status;
		}
		if ( '' !== $condition ) {
			$where[]  = '`condition` = %s';
			$values[] = $condition;
		}
		if ( '' !== $call_number ) {
			$where[]  = 'LOWER(call_number) LIKE %s';
			$values[] = '%' . $this->like_fragment( $call_number ) . '%';
		}
		if ( '' !== $search ) {
			$where[]  = '(CAST(id AS CHAR) LIKE %s OR CAST(book_post_id AS CHAR) LIKE %s OR LOWER(barcode) LIKE %s OR LOWER(call_number) LIKE %s)';
			$like     = '%' . $this->like_fragment( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$limit    = max( 1, $limit );
		$offset   = max( 0, $offset );
		$values[] = $limit;
		$values[] = $offset;
		$sql      = "SELECT * FROM {$tables['copies']} WHERE " . implode( ' AND ', $where ) . " ORDER BY COALESCE(status, circulation_status, '') ASC, call_number ASC, id ASC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and prepared values above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Escape a LIKE fragment for portable repository queries.
	 *
	 * @param string $value Raw fragment.
	 */
	private function like_fragment( string $value ): string {
		global $wpdb;

		$value = strtolower( $value );
		return method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $value ) : addcslashes( $value, '_%\\' );
	}

	/**
	 * Find copies matching an ISBN-10, ISBN-13, or barcode string.
	 *
	 * Returns an empty array when the query is blank. Comparison is exact;
	 * normalisation (dashes, spaces) is the caller's responsibility.
	 *
	 * @param string $query Raw scan/typed string.
	 * @return array<int,array<string,mixed>>
	 */
	public function find_by_isbn_or_barcode( string $query ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		return array_values(
			array_filter(
				$this->all(),
				static fn( array $row ): bool =>
					(string) ( $row['isbn_13'] ?? '' ) === $query
					|| (string) ( $row['isbn_10'] ?? '' ) === $query
					|| (string) ( $row['barcode'] ?? '' ) === $query
			)
		);
	}

	/**
	 * All copies for a specific book.
	 *
	 * @param int $book_post_id Book post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function all_for_book( int $book_post_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['copies']} WHERE book_post_id = {$book_post_id} ORDER BY id ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
