<?php
/**
 * Repository for borrower records and audit entries.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Borrowers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use ConnectLibrary\Database\Schema;

/**
 * Low-level persistence for borrower tables.
 */
final class BorrowerRepository {
	/**
	 * Insert a borrower record.
	 *
	 * @param array<string,mixed> $row Borrower row.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->insert( $tables['borrowers'], $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a borrower record.
	 *
	 * @param int                 $id Borrower ID.
	 * @param array<string,mixed> $row Row changes.
	 */
	public function update( int $id, array $row ): bool {
		global $wpdb;

		$tables = Schema::table_names();

		return (bool) $wpdb->update( $tables['borrowers'], $row, array( 'id' => $id ) );
	}

	/**
	 * Get one borrower by ID.
	 *
	 * @param int $id Borrower ID.
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
	 * Full-text search borrowers by display name, preferred name, or email.
	 *
	 * Supports optional 'status' and 'borrower_type' filter keys.
	 *
	 * @param array<string,string> $args Search/filter arguments.
	 * @return array<int,array<string,mixed>>
	 */
	public function search( array $args = array() ): array {
		$rows   = $this->all();
		$needle = strtolower( trim( (string) ( $args['search'] ?? '' ) ) );

		if ( '' !== $needle ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( array $row ): bool =>
						str_contains( strtolower( (string) ( $row['display_name'] ?? '' ) ), $needle )
						|| str_contains( strtolower( (string) ( $row['preferred_name'] ?? '' ) ), $needle )
						|| str_contains( strtolower( (string) ( $row['email'] ?? '' ) ), $needle )
				)
			);
		}

		if ( '' !== (string) ( $args['status'] ?? '' ) ) {
			$status = (string) $args['status'];
			$rows   = array_values(
				array_filter( $rows, static fn( array $r ): bool => (string) ( $r['status'] ?? '' ) === $status )
			);
		}

		if ( '' !== (string) ( $args['borrower_type'] ?? '' ) ) {
			$type = (string) $args['borrower_type'];
			$rows = array_values(
				array_filter( $rows, static fn( array $r ): bool => (string) ( $r['borrower_type'] ?? '' ) === $type )
			);
		}

		return $rows;
	}

	/**
	 * Find the first active borrower linked to a WordPress user ID.
	 *
	 * Returns null when no active borrower record exists for the given WP user.
	 * Does not create borrower records; purely a lookup.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return array<string,mixed>|null Borrower row, or null if not found.
	 */
	public function find_by_wp_user_id( int $wp_user_id ): ?array {
		if ( $wp_user_id <= 0 ) {
			return null;
		}

		foreach ( $this->all() as $row ) {
			if ( (int) ( $row['wp_user_id'] ?? 0 ) === $wp_user_id && 'active' === ( $row['status'] ?? '' ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Return all borrower rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['borrowers']} ORDER BY id ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Insert an audit row.
	 *
	 * @param int          $borrower_id Borrower ID.
	 * @param string       $action Action key.
	 * @param array|string $changed_fields Changed fields list/summary.
	 * @param string       $reason Optional reason.
	 */
	public function audit( int $borrower_id, string $action, array|string $changed_fields = array(), string $reason = '' ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->insert(
			$tables['borrower_audit'],
			array(
				'borrower_id'    => $borrower_id,
				'actor_user_id'  => function_exists( 'get_current_user_id' ) ? get_current_user_id() : null,
				'action'         => sanitize_key( $action ),
				'changed_fields' => is_array( $changed_fields ) ? wp_json_encode( array_values( $changed_fields ) ) : sanitize_text_field( $changed_fields ),
				'reason'         => '' !== $reason ? sanitize_text_field( $reason ) : null,
				'created_at'     => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Return audit events for a borrower.
	 *
	 * @param int $borrower_id Borrower ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function audit_events( int $borrower_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['borrower_audit']} ORDER BY id ASC", ARRAY_A );
		$rows   = is_array( $rows ) ? $rows : array();

		return array_values(
			array_filter(
				$rows,
				static fn ( array $row ): bool => (int) ( $row['borrower_id'] ?? 0 ) === $borrower_id
			)
		);
	}
}
