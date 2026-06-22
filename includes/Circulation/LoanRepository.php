<?php
/**
 * Repository for loan and loan audit records.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Circulation;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use ConnectLibrary\Database\Schema;
use WP_Error;

/**
 * Low-level persistence for loan tables.
 */
final class LoanRepository {

	/**
	 * Insert a loan row.
	 *
	 * @param array<string,mixed> $row Loan row.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->insert( $tables['loans'], $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a loan row.
	 *
	 * @param int                 $id  Loan ID.
	 * @param array<string,mixed> $row Row changes.
	 */
	public function update( int $id, array $row ): bool {
		global $wpdb;

		$tables = Schema::table_names();

		return (bool) $wpdb->update( $tables['loans'], $row, array( 'id' => $id ) );
	}

	/**
	 * Atomically renew an active loan for a specific borrower and audit the change.
	 *
	 * The guarded UPDATE enforces loan_id, borrower_id, active status, and
	 * renewal_count < renewal_limit at write time so stale reads/concurrent
	 * renewals cannot be reported as success. The renewal update and audit insert
	 * are committed or rolled back together.
	 *
	 * @param int    $loan_id       Loan ID.
	 * @param int    $borrower_id   Borrower ID that owns the loan.
	 * @param string $new_due_at    New due_at value.
	 * @param string $renewed_at    Renewal timestamp.
	 * @param string $actor_context Audit reason/context.
	 * @return array<string,mixed>|WP_Error Updated loan row on success.
	 */
	public function renew_for_borrower_atomic( int $loan_id, int $borrower_id, string $new_due_at, string $renewed_at, string $actor_context ): array|WP_Error {
		global $wpdb;

		$tables = Schema::table_names();

		$wpdb->query( 'START TRANSACTION' );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['loans']}
				SET due_at = %s,
					renewal_count = renewal_count + 1,
					last_renewed_at = %s,
					updated_at = %s
				WHERE id = %d
					AND borrower_id = %d
					AND status = %s
					AND renewal_count < renewal_limit",
				$new_due_at,
				$renewed_at,
				$renewed_at,
				$loan_id,
				$borrower_id,
				'active'
			)
		);

		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error(
				'connectlibrary_loan_renewal_conflict',
				__( 'This loan could not be renewed because it is no longer eligible.', 'connectlibrary' ),
				array( 'status' => 409 )
			);
		}

		$audit_id = $this->audit(
			$loan_id,
			'renew',
			array( 'due_at', 'renewal_count', 'last_renewed_at' ),
			sanitize_text_field( $actor_context )
		);

		if ( $audit_id <= 0 ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error(
				'connectlibrary_loan_renewal_audit_failed',
				__( 'This loan could not be renewed because the audit record could not be written.', 'connectlibrary' ),
				array( 'status' => 500 )
			);
		}

		$wpdb->query( 'COMMIT' );

		$loan = $this->get( $loan_id );
		if ( null === $loan ) {
			return new WP_Error(
				'connectlibrary_loan_not_found',
				__( 'Loan not found.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		return $loan;
	}

	/**
	 * Get one loan by ID.
	 *
	 * @param int $id Loan ID.
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
	 * Return all loan rows ordered by ID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['loans']} ORDER BY id ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return active loans for operational reports with constrained paging.
	 *
	 * Filtering/sorting is kept behind the repository seam so report screens do
	 * not perform unbounded scans directly. The in-memory fallback keeps unit
	 * tests aligned with the same public contract.
	 *
	 * @param array<string,mixed> $filters Supported: from, to, status, overdue_only.
	 * @param int                 $limit   Maximum rows.
	 * @param int                 $offset  Pagination offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function report_active_loans( array $filters, int $limit, int $offset ): array {
		global $wpdb;

		$tables       = Schema::table_names();
		$where        = array( '1=1' );
		$values       = array();
		$from         = (string) ( $filters['from'] ?? '' );
		$to           = (string) ( $filters['to'] ?? '' );
		$status       = sanitize_key( (string) ( $filters['status'] ?? 'active' ) );
		$overdue_only = ! empty( $filters['overdue_only'] );

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		if ( $overdue_only ) {
			$where[]  = 'due_at < %s';
			$values[] = current_time( 'mysql' );
		}
		if ( '' !== $from ) {
			$where[]  = 'due_at >= %s';
			$values[] = $from;
		}
		if ( '' !== $to ) {
			$where[]  = 'due_at <= %s';
			$values[] = $to . ' 23:59:59';
		}

		$limit    = max( 1, $limit );
		$offset   = max( 0, $offset );
		$values[] = $limit;
		$values[] = $offset;
		$sql      = "SELECT * FROM {$tables['loans']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY due_at ASC, id ASC LIMIT %d OFFSET %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and prepared values above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return the first active loan for a specific copy, or null when none exists.
	 *
	 * @param int $copy_id Copy ID.
	 * @return array<string,mixed>|null
	 */
	public function active_for_copy( int $copy_id ): ?array {
		foreach ( $this->all() as $row ) {
			if ( (int) ( $row['copy_id'] ?? 0 ) === $copy_id && 'active' === (string) ( $row['status'] ?? '' ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Atomically change due_at on an active loan and write an audit entry.
	 *
	 * The update is guarded by status='active' so stale-read changes to an
	 * already-returned loan are rejected before any audit row is written.
	 * The old due date, new due date, and optional reason are stored in the
	 * audit row. Renewal count is NOT changed.
	 *
	 * @param int    $loan_id       Loan ID.
	 * @param string $new_due_at    New due_at value (MySQL datetime).
	 * @param string $old_due_at    Previous due_at (for audit trail).
	 * @param string $now           Current timestamp (MySQL datetime).
	 * @param string $actor_context Audit context label.
	 * @param string $reason        Optional librarian reason/note.
	 * @return array<string,mixed>|WP_Error Updated loan row on success.
	 */
	public function change_due_at_atomic(
		int $loan_id,
		string $new_due_at,
		string $old_due_at,
		string $now,
		string $actor_context,
		string $reason
	): array|WP_Error {
		global $wpdb;

		$tables = Schema::table_names();

		$wpdb->query( 'START TRANSACTION' );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['loans']}
				SET due_at = %s,
					updated_at = %s
				WHERE id = %d
					AND status = %s",
				$new_due_at,
				$now,
				$loan_id,
				'active'
			)
		);

		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'connectlibrary_due_change_conflict',
				__( 'This loan could not be updated because it is no longer active.', 'connectlibrary' ),
				array( 'status' => 409 )
			);
		}

		$audit_reason = "old_due:{$old_due_at}|new_due:{$new_due_at}";
		if ( '' !== $reason ) {
			$audit_reason .= '|reason:' . $reason;
		}

		$audit_id = $this->audit(
			$loan_id,
			'due_date_change',
			array( 'due_at' ),
			$audit_reason
		);

		if ( $audit_id <= 0 ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'connectlibrary_due_change_audit_failed',
				__( 'Failed to write due-date change audit record.', 'connectlibrary' ),
				array( 'status' => 500 )
			);
		}

		$wpdb->query( 'COMMIT' );

		$loan = $this->get( $loan_id );
		if ( null === $loan ) {
			return new WP_Error(
				'connectlibrary_loan_not_found',
				__( 'Loan not found after due-date change.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		return $loan;
	}

	/**
	 * All active loans for a specific borrower.
	 *
	 * @param int $borrower_id Borrower ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function active_for_borrower( int $borrower_id ): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( array $r ): bool =>
					(int) ( $r['borrower_id'] ?? 0 ) === $borrower_id
					&& 'active' === ( $r['status'] ?? '' )
			)
		);
	}

	/**
	 * Atomically check out a copy to a borrower.
	 *
	 * Acquires the copy by guarded UPDATE (only succeeds when circulation_status =
	 * 'available'), then inserts the loan and audit rows in the same transaction.
	 *
	 * @param int    $copy_id         Copy ID.
	 * @param int    $book_post_id    Book post ID.
	 * @param int    $borrower_id     Borrower ID.
	 * @param string $due_at          Due date.
	 * @param string $checked_out_at  Checkout timestamp.
	 * @param int    $due_period_days Number of days used for the due date.
	 * @param string $source          Source context (e.g. 'admin', 'self').
	 * @param int    $created_by      Actor user ID (0 = anonymous/system).
	 * @param string $override_note   Librarian note when due date was overridden.
	 * @param string $claim_status    Copy circulation_status required for the guarded claim.
	 * @return array<string,mixed>|WP_Error New loan row on success.
	 */
	public function checkout_atomic(
		int $copy_id,
		int $book_post_id,
		int $borrower_id,
		string $due_at,
		string $checked_out_at,
		int $due_period_days = 14,
		string $source = 'admin',
		int $created_by = 0,
		string $override_note = '',
		string $claim_status = 'available'
	): array|WP_Error {
		global $wpdb;

		$tables = Schema::table_names();

		$wpdb->query( 'START TRANSACTION' );

		$claim_status = sanitize_key( $claim_status );
		if ( '' === $claim_status ) {
			$claim_status = 'available';
		}

		// Atomic claim: only succeed when the copy is still in the expected status and belongs to the requested book.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['copies']}
				SET circulation_status = 'checked_out', updated_at = %s
				WHERE id = %d AND book_post_id = %d AND circulation_status = %s",
				$checked_out_at,
				$copy_id,
				$book_post_id,
				$claim_status
			)
		);

		if ( 1 !== (int) $claimed ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'connectlibrary_checkout_conflict',
				__( 'This copy is no longer available for checkout.', 'connectlibrary' ),
				array( 'status' => 409 )
			);
		}

		$loan_row = array(
			'book_post_id'    => $book_post_id,
			'copy_id'         => $copy_id,
			'borrower_id'     => $borrower_id,
			'status'          => 'active',
			'checked_out_at'  => $checked_out_at,
			'due_at'          => $due_at,
			'renewal_count'   => 0,
			'renewal_limit'   => 1,
			'due_period_days' => $due_period_days,
			'source'          => '' !== $source ? $source : null,
			'created_by'      => $created_by > 0 ? $created_by : null,
			'created_at'      => $checked_out_at,
			'updated_at'      => $checked_out_at,
		);

		if ( '' !== $override_note ) {
			$loan_row['override_note'] = sanitize_text_field( $override_note );
		}

		$inserted = $wpdb->insert( $tables['loans'], $loan_row );
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'connectlibrary_checkout_insert_failed',
				__( 'Failed to create loan record.', 'connectlibrary' ),
				array( 'status' => 500 )
			);
		}

		$loan_id = (int) $wpdb->insert_id;

		// Back-fill the loan ID onto the copy row.
		$wpdb->update( $tables['copies'], array( 'current_loan_id' => $loan_id ), array( 'id' => $copy_id ) );

		$audit_id = $this->audit(
			$loan_id,
			'checkout',
			array( 'copy_id', 'borrower_id', 'due_at', 'status' ),
			$source
		);

		if ( $audit_id <= 0 ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'connectlibrary_checkout_audit_failed',
				__( 'Failed to write checkout audit record.', 'connectlibrary' ),
				array( 'status' => 500 )
			);
		}

		$wpdb->query( 'COMMIT' );

		$loan = $this->get( $loan_id );
		if ( null === $loan ) {
			return new WP_Error(
				'connectlibrary_loan_not_found',
				__( 'Loan not found after checkout.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		return $loan;
	}

	/**
	 * Close an active/overdue/lost loan and update the copy's circulation status.
	 *
	 * Validates the loan status before writing to avoid closing already-closed
	 * loans. The loan update, copy update, and audit insert are committed or
	 * rolled back together.
	 *
	 * @param int    $loan_id         Loan ID.
	 * @param string $new_copy_status New circulation_status for the copy.
	 * @param string $returned_at     Return/close timestamp.
	 * @param string $actor_context   Audit reason.
	 * @param int    $returned_by     Actor user ID (0 = anonymous/system).
	 * @return array<string,mixed>|WP_Error Updated loan row on success.
	 */
	public function close_loan_atomic(
		int $loan_id,
		string $new_copy_status,
		string $returned_at,
		string $actor_context,
		int $returned_by = 0
	): array|WP_Error {
		global $wpdb;

		$tables = Schema::table_names();

		$loan = $this->get( $loan_id );
		if ( null === $loan ) {
			return new WP_Error(
				'connectlibrary_loan_not_found',
				__( 'Loan not found.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		$closeable = array( 'active', 'overdue', 'lost' );
		if ( ! in_array( (string) ( $loan['status'] ?? '' ), $closeable, true ) ) {
			return new WP_Error(
				'connectlibrary_loan_not_closeable',
				__( 'Only active, overdue, or lost loans can be returned.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$wpdb->query( 'START TRANSACTION' );

		$wpdb->update(
			$tables['loans'],
			array(
				'status'      => 'returned',
				'returned_at' => $returned_at,
				'returned_by' => $returned_by > 0 ? $returned_by : null,
				'updated_at'  => $returned_at,
			),
			array( 'id' => $loan_id )
		);

		$copy_id = (int) ( $loan['copy_id'] ?? 0 );
		if ( $copy_id > 0 ) {
			$wpdb->update(
				$tables['copies'],
				array(
					'circulation_status' => $new_copy_status,
					'current_loan_id'    => null,
					'updated_at'         => $returned_at,
				),
				array( 'id' => $copy_id )
			);
		}

		$audit_id = $this->audit(
			$loan_id,
			'return',
			array( 'status', 'returned_at' ),
			$actor_context
		);

		if ( $audit_id <= 0 ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'connectlibrary_return_audit_failed',
				__( 'Failed to write return audit record.', 'connectlibrary' ),
				array( 'status' => 500 )
			);
		}

		$wpdb->query( 'COMMIT' );

		$updated = $this->get( $loan_id );
		if ( null === $updated ) {
			return new WP_Error(
				'connectlibrary_loan_not_found',
				__( 'Loan not found after return.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		return $updated;
	}

	/**
	 * Mark a loan as voided/corrected and record the correction note.
	 *
	 * Voidable from any non-voided status. History is preserved; the row is
	 * never deleted.
	 *
	 * @param int    $loan_id         Loan ID.
	 * @param string $correction_note Reason for the correction.
	 * @param string $actor_context   Audit reason context.
	 * @return array<string,mixed>|WP_Error Updated loan row on success.
	 */
	public function void_loan(
		int $loan_id,
		string $correction_note,
		string $actor_context
	): array|WP_Error {
		global $wpdb;

		$tables = Schema::table_names();

		$loan = $this->get( $loan_id );
		if ( null === $loan ) {
			return new WP_Error(
				'connectlibrary_loan_not_found',
				__( 'Loan not found.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		if ( 'voided' === (string) ( $loan['status'] ?? '' ) ) {
			return new WP_Error(
				'connectlibrary_loan_already_voided',
				__( 'This loan has already been voided.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$now = current_time( 'mysql' );

		$wpdb->query( 'START TRANSACTION' );

		$wpdb->update(
			$tables['loans'],
			array(
				'status'          => 'voided',
				'correction_note' => '' !== $correction_note ? sanitize_text_field( $correction_note ) : null,
				'updated_by'      => function_exists( 'get_current_user_id' ) ? get_current_user_id() : null,
				'updated_at'      => $now,
			),
			array( 'id' => $loan_id )
		);

		$audit_id = $this->audit(
			$loan_id,
			'void',
			array( 'status', 'correction_note' ),
			$actor_context
		);

		if ( $audit_id <= 0 ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'connectlibrary_void_audit_failed',
				__( 'Failed to write void audit record.', 'connectlibrary' ),
				array( 'status' => 500 )
			);
		}

		$wpdb->query( 'COMMIT' );

		$updated = $this->get( $loan_id );
		if ( null === $updated ) {
			return new WP_Error(
				'connectlibrary_loan_not_found',
				__( 'Loan not found after void.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		return $updated;
	}

	/**
	 * Insert a loan audit row.
	 *
	 * @param int                  $loan_id        Loan ID.
	 * @param string               $action         Action key.
	 * @param array<string>|string $changed_fields Fields that changed.
	 * @param string               $reason         Optional reason text.
	 */
	public function audit( int $loan_id, string $action, array|string $changed_fields = array(), string $reason = '' ): int {
		global $wpdb;

		$tables = Schema::table_names();

		if ( is_array( $changed_fields ) ) {
			$fields = ! empty( $changed_fields ) ? wp_json_encode( $changed_fields ) : null;
		} else {
			$fields = '' !== $changed_fields ? $changed_fields : null;
		}

		$inserted = $wpdb->insert(
			$tables['loan_audit'],
			array(
				'loan_id'        => $loan_id,
				'actor_user_id'  => function_exists( 'get_current_user_id' ) ? get_current_user_id() : null,
				'action'         => sanitize_key( $action ),
				'changed_fields' => $fields,
				'reason'         => '' !== $reason ? sanitize_text_field( $reason ) : null,
				'created_at'     => current_time( 'mysql' ),
			)
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Return all audit events for a loan.
	 *
	 * @param int $loan_id Loan ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function audit_events( int $loan_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['loan_audit']} ORDER BY id ASC", ARRAY_A );
		$rows   = is_array( $rows ) ? $rows : array();

		return array_values(
			array_filter(
				$rows,
				static fn( array $r ): bool => (int) ( $r['loan_id'] ?? 0 ) === $loan_id
			)
		);
	}
}
