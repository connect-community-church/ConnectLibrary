<?php
/**
 * Reservation status constants and transition helpers for ConnectLibrary.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Reservations;

/**
 * Immutable status vocabulary and allowed transition map for reservation/hold records.
 */
final class ReservationStatuses {

	/** Waiting for librarian approval. */
	public const PENDING_APPROVAL = 'pending_approval';

	/** Approved and a physical copy is being held for pickup. */
	public const ACTIVE_HOLD = 'active_hold';

	/** Borrower has physically picked up the item. */
	public const PICKED_UP = 'picked_up';

	/** Item returned; lifecycle complete. Terminal. */
	public const FULFILLED = 'fulfilled';

	/** Hold expired before borrower picked up. Terminal. */
	public const EXPIRED = 'expired';

	/** Cancelled by borrower or librarian. Terminal. */
	public const CANCELLED = 'cancelled';

	/** Request denied by librarian. Terminal. */
	public const DENIED = 'denied';

	/** Queued behind existing holds; no copy currently available. */
	public const WAITLISTED = 'waitlisted';

	/**
	 * All valid status values.
	 *
	 * @return string[]
	 */
	public static function all_statuses(): array {
		return array(
			self::PENDING_APPROVAL,
			self::ACTIVE_HOLD,
			self::PICKED_UP,
			self::FULFILLED,
			self::EXPIRED,
			self::CANCELLED,
			self::DENIED,
			self::WAITLISTED,
		);
	}

	/**
	 * Statuses from which no further transitions are permitted.
	 *
	 * @return string[]
	 */
	public static function terminal_statuses(): array {
		return array(
			self::FULFILLED,
			self::EXPIRED,
			self::CANCELLED,
			self::DENIED,
		);
	}

	/**
	 * Statuses that are still in-flight (not terminal).
	 *
	 * @return string[]
	 */
	public static function non_terminal_statuses(): array {
		return array_values(
			array_diff( self::all_statuses(), self::terminal_statuses() )
		);
	}

	/**
	 * Whether the given status allows no further transitions.
	 *
	 * @param string $status Status to check.
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, self::terminal_statuses(), true );
	}

	/**
	 * Allowed status transitions keyed by the current (from) status.
	 *
	 * Only transitions present in this map are valid. Terminal statuses have
	 * no entry because they cannot transition at all.
	 *
	 * @return array<string,string[]>
	 */
	public static function valid_transitions(): array {
		return array(
			self::PENDING_APPROVAL => array(
				self::ACTIVE_HOLD,
				self::WAITLISTED,
				self::DENIED,
				self::CANCELLED,
			),
			self::WAITLISTED       => array(
				self::ACTIVE_HOLD,
				self::CANCELLED,
			),
			self::ACTIVE_HOLD      => array(
				self::PICKED_UP,
				self::EXPIRED,
				self::CANCELLED,
			),
			self::PICKED_UP        => array(
				self::FULFILLED,
				self::CANCELLED,
			),
		);
	}

	/**
	 * Whether transitioning from $from to $to is permitted.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 */
	public static function can_transition( string $from, string $to ): bool {
		$map = self::valid_transitions();

		return isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true );
	}

	/**
	 * Translatable admin labels for all statuses.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::PENDING_APPROVAL => __( 'Pending Approval', 'connectlibrary' ),
			self::ACTIVE_HOLD      => __( 'Active Hold', 'connectlibrary' ),
			self::PICKED_UP        => __( 'Picked Up', 'connectlibrary' ),
			self::FULFILLED        => __( 'Fulfilled', 'connectlibrary' ),
			self::EXPIRED          => __( 'Expired', 'connectlibrary' ),
			self::CANCELLED        => __( 'Cancelled', 'connectlibrary' ),
			self::DENIED           => __( 'Denied', 'connectlibrary' ),
			self::WAITLISTED       => __( 'Waitlisted', 'connectlibrary' ),
		);
	}
}
