<?php
/**
 * Repository for borrower guest-access token records.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Borrowers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use ConnectLibrary\Database\Schema;

/**
 * Low-level persistence for hashed My Library guest-access tokens.
 */
final class GuestAccessTokenRepository {
	/**
	 * Insert a guest-access token row.
	 *
	 * @param array<string,mixed> $row Token row.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->insert( $tables['guest_access_tokens'], $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Return one token row by hash.
	 *
	 * @param string $token_hash Stored token hash.
	 * @return array<string,mixed>|null
	 */
	public function find_by_hash( string $token_hash ): ?array {
		$matches = $this->find_all_by_hash( $token_hash );

		return $matches[0] ?? null;
	}

	/**
	 * Return every token row matching a hash.
	 *
	 * Multiple matches should not happen with the schema unique key, but callers
	 * need to detect integrity failures instead of silently selecting the first.
	 *
	 * @param string $token_hash Stored token hash.
	 * @return array<int,array<string,mixed>>
	 */
	public function find_all_by_hash( string $token_hash ): array {
		$matches = array();
		foreach ( $this->all() as $row ) {
			if ( hash_equals( (string) ( $row['token_hash'] ?? '' ), $token_hash ) ) {
				$matches[] = $row;
			}
		}

		return $matches;
	}

	/**
	 * Return all guest-access token rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['guest_access_tokens']} ORDER BY id ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
