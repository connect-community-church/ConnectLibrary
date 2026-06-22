<?php
/**
 * Repository for shared audit event records.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Audit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,Squiz.Commenting.FunctionComment.MissingParamTag

use ConnectLibrary\Database\Schema;

/**
 * Low-level persistence for the audit_events table.
 */
final class AuditEventRepository {

	/**
	 * Insert an audit event row.
	 *
	 * @param array<string,mixed> $row Audit event row.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$tables   = Schema::table_names();
		$inserted = $wpdb->insert( $tables['audit_events'], $row );

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Query audit events with optional filters.
	 *
	 * Supported filter keys: action, action_group, entity_type/object_type,
	 * entity_id/object_id, actor_id, actor_type, source_channel,
	 * correlation_id, status/outcome, from/to and search.
	 *
	 * @param array<string,mixed> $filters  Column-value pairs to filter rows.
	 * @param int                 $limit    Maximum rows to return.
	 * @param int                 $offset   Pagination offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function query( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$tables  = Schema::table_names();
		$where   = array( '1=1' );
		$values  = array();
		$filters = $this->normalize_filters( $filters );
		$allowed = array( 'action', 'entity_type', 'entity_id', 'actor_id', 'actor_type', 'source_channel', 'correlation_id', 'status' );

		foreach ( $allowed as $key ) {
			$value = $filters[ $key ] ?? null;
			if ( '' === (string) $value || null === $value ) {
				continue;
			}
			$where[]  = "{$key} = %s";
			$values[] = (string) $value;
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[]  = 'created_at_utc >= %s';
			$values[] = (string) $filters['from'];
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[]  = 'created_at_utc <= %s';
			$values[] = (string) $filters['to'] . ' 23:59:59';
		}
		if ( ! empty( $filters['action_group'] ) ) {
			$where[]  = 'context_json LIKE %s';
			$values[] = '%"action_group":"' . $wpdb->esc_like( (string) $filters['action_group'] ) . '"%';
		}
		if ( ! empty( $filters['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( strtolower( (string) $filters['search'] ) ) . '%';
			$where[]  = '(LOWER(summary) LIKE %s OR LOWER(action) LIKE %s OR LOWER(entity_type) LIKE %s OR LOWER(context_json) LIKE %s OR CAST(entity_id AS CHAR) LIKE %s OR CAST(id AS CHAR) LIKE %s)';
			$values[] = $search;
			$values[] = $search;
			$values[] = $search;
			$values[] = $search;
			$values[] = $search;
			$values[] = $search;
		}

		$limit    = max( 1, min( 200, $limit ) );
		$offset   = max( 0, $offset );
		$values[] = $limit;
		$values[] = $offset;
		$sql      = "SELECT * FROM {$tables['audit_events']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and prepared values above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find one audit event by ID.
	 *
	 * @param int $id Event ID.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['audit_events']} WHERE id = %d", max( 0, $id ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Return all audit event rows ordered by id descending.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			"SELECT * FROM {$tables['audit_events']} ORDER BY id DESC",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/** Normalize legacy/UI aliases into repository column filters. */
	private function normalize_filters( array $filters ): array {
		if ( ! empty( $filters['object_type'] ) && empty( $filters['entity_type'] ) ) {
			$filters['entity_type'] = $filters['object_type'];
		}
		if ( ! empty( $filters['object_id'] ) && empty( $filters['entity_id'] ) ) {
			$filters['entity_id'] = $filters['object_id'];
		}
		if ( ! empty( $filters['outcome'] ) && empty( $filters['status'] ) ) {
			$filters['status'] = $filters['outcome'];
		}

		foreach ( array( 'action', 'entity_type', 'actor_type', 'source_channel', 'correlation_id', 'status', 'action_group' ) as $key ) {
			if ( isset( $filters[ $key ] ) ) {
				$filters[ $key ] = sanitize_key( (string) $filters[ $key ] );
			}
		}
		foreach ( array( 'entity_id', 'actor_id' ) as $key ) {
			if ( isset( $filters[ $key ] ) ) {
				$filters[ $key ] = absint( $filters[ $key ] );
			}
		}
		if ( isset( $filters['search'] ) ) {
			$filters['search'] = sanitize_text_field( (string) $filters['search'] );
		}

		return $filters;
	}
}
