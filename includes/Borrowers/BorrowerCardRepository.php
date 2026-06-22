<?php
/**
 * Repository for generated borrower library-card records.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Borrowers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment.MissingParamTag

use ConnectLibrary\Database\Schema;

/**
 * Low-level persistence for opaque borrower card lifecycle rows.
 */
final class BorrowerCardRepository {
	public function begin_transaction(): bool {
		global $wpdb;
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	public function commit(): bool {
		global $wpdb;
		return false !== $wpdb->query( 'COMMIT' );
	}

	public function rollback(): bool {
		global $wpdb;
		return false !== $wpdb->query( 'ROLLBACK' );
	}

	/** Insert a card row. @param array<string,mixed> $row Card row. */
	public function insert( array $row ): int {
		global $wpdb;
		$tables = Schema::table_names();
		$wpdb->insert( $tables['borrower_cards'], $row );
		return (int) $wpdb->insert_id;
	}

	/** Update a card row. @param array<string,mixed> $row Row changes. */
	public function update( int $id, array $row ): bool {
		global $wpdb;
		$tables = Schema::table_names();
		return (bool) $wpdb->update( $tables['borrower_cards'], $row, array( 'id' => $id ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function all(): array {
		global $wpdb;
		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['borrower_cards']} ORDER BY id ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array {
		foreach ( $this->all() as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				return $row;
			}
		}
		return null;
	}

	/** @return array<int,array<string,mixed>> */
	public function for_borrower( int $borrower_id ): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( array $row ): bool => (int) ( $row['borrower_id'] ?? 0 ) === $borrower_id
			)
		);
	}

	/** @return array<string,mixed>|null */
	public function active_for_borrower( int $borrower_id ): ?array {
		foreach ( $this->for_borrower( $borrower_id ) as $row ) {
			if ( BorrowerCardService::STATUS_ACTIVE === (string) ( $row['status'] ?? '' ) ) {
				return $row;
			}
		}
		return null;
	}

	/** @return array<int,array<string,mixed>> */
	public function find_all_by_hash( string $token_hash ): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( array $row ): bool => hash_equals( (string) ( $row['token_hash'] ?? '' ), $token_hash )
			)
		);
	}
}
