<?php
/**
 * Repository for reservation and audit records.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Reservations;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use ConnectLibrary\Database\Schema;

/**
 * Low-level persistence for reservation tables.
 */
final class ReservationRepository {

	/**
	 * Insert a reservation row.
	 *
	 * @param array<string,mixed> $row Reservation row.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->insert( $tables['reservations'], $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a reservation row.
	 *
	 * @param int                 $id  Reservation ID.
	 * @param array<string,mixed> $row Row changes.
	 */
	public function update( int $id, array $row ): bool {
		global $wpdb;

		$tables = Schema::table_names();

		return (bool) $wpdb->update( $tables['reservations'], $row, array( 'id' => $id ) );
	}

	/**
	 * Get one reservation by ID.
	 *
	 * @param int $id Reservation ID.
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
	 * Return all reservation rows ordered by ID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['reservations']} ORDER BY id ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return all reservation rows with a specific status.
	 *
	 * @param string $status Reservation status.
	 * @return array<int,array<string,mixed>>
	 */
	public function by_status( string $status ): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( array $row ): bool => (string) ( $row['status'] ?? '' ) === $status
			)
		);
	}

	/**
	 * Return reservations for reports using bounded SQL filtering and paging.
	 *
	 * @param string              $status   Reservation status.
	 * @param array<string,mixed> $filters  Supported: from, to.
	 * @param int                 $limit    Maximum rows.
	 * @param int                 $offset   Pagination offset.
	 * @param string              $date_key Whitelisted date column for filtering/sorting.
	 * @return array<int,array<string,mixed>>
	 */
	public function report_by_status( string $status, array $filters, int $limit, int $offset, string $date_key ): array {
		global $wpdb;

		$date_key = in_array( $date_key, array( 'hold_expires_at', 'requested_at', 'created_at' ), true ) ? $date_key : 'created_at';
		$tables   = Schema::table_names();
		$where    = array( 'status = %s' );
		$values   = array( $status );
		$from     = (string) ( $filters['from'] ?? '' );
		$to       = (string) ( $filters['to'] ?? '' );

		if ( '' !== $from ) {
			$where[]  = "{$date_key} >= %s";
			$values[] = $from;
		}
		if ( '' !== $to ) {
			$where[]  = "{$date_key} <= %s";
			$values[] = $to . ' 23:59:59';
		}

		$limit    = max( 1, $limit );
		$offset   = max( 0, $offset );
		$values[] = $limit;
		$values[] = $offset;
		$sql      = "SELECT * FROM {$tables['reservations']} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$date_key} ASC, id ASC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and prepared values above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All non-terminal reservations for a specific borrower/book combination.
	 *
	 * Used for duplicate prevention; terminal statuses release the block.
	 *
	 * @param int $borrower_id  Borrower ID.
	 * @param int $book_post_id Book post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function non_terminal_for_borrower_book( int $borrower_id, int $book_post_id ): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( array $r ): bool =>
					(int) ( $r['borrower_id'] ?? 0 ) === $borrower_id
					&& (int) ( $r['book_post_id'] ?? 0 ) === $book_post_id
					&& ! ReservationStatuses::is_terminal( (string) ( $r['status'] ?? '' ) )
			)
		);
	}

	/**
	 * All non-terminal reservations for a guest email/book combination.
	 *
	 * Used for duplicate prevention on guest requests.
	 *
	 * @param string $guest_email  Guest email address.
	 * @param int    $book_post_id Book post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function non_terminal_for_guest_book( string $guest_email, int $book_post_id ): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( array $r ): bool =>
					(string) ( $r['guest_email'] ?? '' ) === $guest_email
					&& (int) ( $r['book_post_id'] ?? 0 ) === $book_post_id
					&& ! ReservationStatuses::is_terminal( (string) ( $r['status'] ?? '' ) )
			)
		);
	}

	/**
	 * All waitlisted reservations for a book, ordered FIFO by requested_at.
	 *
	 * @param int $book_post_id Book post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function waitlisted_for_book( int $book_post_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['reservations']} WHERE book_post_id = {$book_post_id}", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$waitlisted = array_values(
			array_filter(
				$rows,
				static fn( array $r ): bool => ReservationStatuses::WAITLISTED === ( $r['status'] ?? '' )
			)
		);

		usort( $waitlisted, static fn( array $a, array $b ): int => strcmp( (string) ( $a['requested_at'] ?? '' ), (string) ( $b['requested_at'] ?? '' ) ) );

		return $waitlisted;
	}

	/**
	 * All active_hold reservations for a book (each blocks one copy).
	 *
	 * @param int $book_post_id Book post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function active_holds_for_book( int $book_post_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['reservations']} WHERE book_post_id = {$book_post_id}", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$rows,
				static fn( array $r ): bool => ReservationStatuses::ACTIVE_HOLD === ( $r['status'] ?? '' )
			)
		);
	}

	/**
	 * All active/public copies for a book.
	 *
	 * Falls back to an empty array safely when the copies table has no rows.
	 *
	 * @param int $book_post_id Book post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function active_public_copies_for_book( int $book_post_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['copies']} WHERE book_post_id = {$book_post_id}", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$rows,
				static fn( array $c ): bool => 'active' === ( $c['item_status'] ?? '' ) && 'public' === ( $c['visibility'] ?? '' )
			)
		);
	}

	/**
	 * Insert a reservation audit row.
	 *
	 * @param int    $reservation_id Reservation ID.
	 * @param string $action         Action key.
	 * @param string $from_status    Previous status (empty string if not a status change).
	 * @param string $to_status      New status (empty string if not a status change).
	 * @param string $reason         Optional reason text.
	 */
	public function audit( int $reservation_id, string $action, string $from_status = '', string $to_status = '', string $reason = '' ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->insert(
			$tables['reservation_audit'],
			array(
				'reservation_id' => $reservation_id,
				'actor_user_id'  => function_exists( 'get_current_user_id' ) ? get_current_user_id() : null,
				'action'         => sanitize_key( $action ),
				'from_status'    => '' !== $from_status ? $from_status : null,
				'to_status'      => '' !== $to_status ? $to_status : null,
				'reason'         => '' !== $reason ? sanitize_text_field( $reason ) : null,
				'created_at'     => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Return all audit events for a reservation.
	 *
	 * @param int $reservation_id Reservation ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function audit_events( int $reservation_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['reservation_audit']} ORDER BY id ASC", ARRAY_A );
		$rows   = is_array( $rows ) ? $rows : array();

		return array_values(
			array_filter(
				$rows,
				static fn( array $r ): bool => (int) ( $r['reservation_id'] ?? 0 ) === $reservation_id
			)
		);
	}
}
