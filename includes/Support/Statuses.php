<?php
/**
 * Central catalog status values for ConnectLibrary.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Support;

/**
 * Catalog-safe status constants used by the Phase 1 schema.
 */
final class Statuses {
	public const ITEM_ACTIVE  = 'active';
	public const ITEM_DAMAGED = 'damaged';
	public const ITEM_LOST    = 'lost';
	public const ITEM_RETIRED = 'retired';

	public const CONDITION_NEW  = 'new';
	public const CONDITION_GOOD = 'good';
	public const CONDITION_FAIR = 'fair';
	public const CONDITION_POOR = 'poor';

	public const METADATA_MANUAL       = 'manual';
	public const METADATA_GOOGLE_BOOKS = 'google_books';
	public const METADATA_OPEN_LIBRARY = 'open_library';
	public const METADATA_UNKNOWN      = 'unknown';

	public const AVAILABILITY_AVAILABLE          = 'available';
	public const AVAILABILITY_RESERVED           = 'reserved';
	public const AVAILABILITY_CHECKED_OUT        = 'checked_out';
	public const AVAILABILITY_WAITLIST_AVAILABLE = 'waitlist_available';
	public const AVAILABILITY_UNAVAILABLE        = 'unavailable';
	public const AVAILABILITY_HIDDEN             = 'hidden';

	public const VISIBILITY_PUBLIC = 'public';
	public const VISIBILITY_HIDDEN = 'hidden';

	// Phase 2 copy circulation statuses.
	public const COPY_AVAILABLE   = 'available';
	public const COPY_ON_HOLD     = 'on_hold';
	public const COPY_CHECKED_OUT = 'checked_out';
	public const COPY_DAMAGED     = 'damaged';
	public const COPY_LOST        = 'lost';
	public const COPY_RETIRED     = 'retired';

	// Phase 2 loan statuses.
	public const LOAN_ACTIVE   = 'active';
	public const LOAN_RETURNED = 'returned';
	public const LOAN_OVERDUE  = 'overdue';
	public const LOAN_LOST     = 'lost';
	public const LOAN_VOIDED   = 'voided';

	/**
	 * Get item/copy lifecycle statuses.
	 *
	 * @return string[]
	 */
	public static function item_statuses(): array {
		return array(
			self::ITEM_ACTIVE,
			self::ITEM_DAMAGED,
			self::ITEM_LOST,
			self::ITEM_RETIRED,
		);
	}

	/**
	 * Get physical condition statuses.
	 *
	 * @return string[]
	 */
	public static function condition_statuses(): array {
		return array(
			self::CONDITION_NEW,
			self::CONDITION_GOOD,
			self::CONDITION_FAIR,
			self::CONDITION_POOR,
		);
	}

	/**
	 * Get supported metadata source values.
	 *
	 * @return string[]
	 */
	public static function metadata_sources(): array {
		return array(
			self::METADATA_MANUAL,
			self::METADATA_GOOGLE_BOOKS,
			self::METADATA_OPEN_LIBRARY,
			self::METADATA_UNKNOWN,
		);
	}

	/**
	 * Get public availability statuses that may be shown in the public catalog.
	 *
	 * Hidden is intentionally modeled as visibility, not a saved availability
	 * value, so public responses can safely exclude hidden titles by default.
	 *
	 * @return string[]
	 */
	public static function availability_statuses(): array {
		return array(
			self::AVAILABILITY_AVAILABLE,
			self::AVAILABILITY_RESERVED,
			self::AVAILABILITY_CHECKED_OUT,
			self::AVAILABILITY_WAITLIST_AVAILABLE,
			self::AVAILABILITY_UNAVAILABLE,
		);
	}

	/**
	 * Get public visibility values for title records.
	 *
	 * @return string[]
	 */
	public static function visibility_statuses(): array {
		return array(
			self::VISIBILITY_PUBLIC,
			self::VISIBILITY_HIDDEN,
		);
	}

	/**
	 * All valid copy circulation statuses.
	 *
	 * @return string[]
	 */
	public static function copy_circulation_statuses(): array {
		return array(
			self::COPY_AVAILABLE,
			self::COPY_ON_HOLD,
			self::COPY_CHECKED_OUT,
			self::COPY_DAMAGED,
			self::COPY_LOST,
			self::COPY_RETIRED,
		);
	}

	/**
	 * All valid loan statuses.
	 *
	 * @return string[]
	 */
	public static function loan_statuses(): array {
		return array(
			self::LOAN_ACTIVE,
			self::LOAN_RETURNED,
			self::LOAN_OVERDUE,
			self::LOAN_LOST,
			self::LOAN_VOIDED,
		);
	}

	/**
	 * Loan statuses that can be closed (returned/voided).
	 *
	 * @return string[]
	 */
	public static function loan_closeable_statuses(): array {
		return array(
			self::LOAN_ACTIVE,
			self::LOAN_OVERDUE,
			self::LOAN_LOST,
		);
	}

	/**
	 * Whether a string is a valid copy circulation status.
	 *
	 * @param string $status Status string to validate.
	 */
	public static function is_valid_copy_status( string $status ): bool {
		return in_array( $status, self::copy_circulation_statuses(), true );
	}

	/**
	 * Whether a string is a valid loan status.
	 *
	 * @param string $status Status string to validate.
	 */
	public static function is_valid_loan_status( string $status ): bool {
		return in_array( $status, self::loan_statuses(), true );
	}

	/**
	 * Whether a string is a valid physical condition status.
	 *
	 * @param string $status Status string to validate.
	 */
	public static function is_valid_condition_status( string $status ): bool {
		return in_array( $status, self::condition_statuses(), true );
	}

	/**
	 * Translatable admin labels for condition statuses.
	 *
	 * @return array<string,string>
	 */
	public static function condition_labels(): array {
		return array(
			self::CONDITION_NEW  => __( 'New', 'connectlibrary' ),
			self::CONDITION_GOOD => __( 'Good', 'connectlibrary' ),
			self::CONDITION_FAIR => __( 'Fair', 'connectlibrary' ),
			self::CONDITION_POOR => __( 'Poor', 'connectlibrary' ),
		);
	}

	/**
	 * Translatable admin labels for item lifecycle statuses.
	 *
	 * @return array<string,string>
	 */
	public static function item_labels(): array {
		return array(
			self::ITEM_ACTIVE  => __( 'Active', 'connectlibrary' ),
			self::ITEM_DAMAGED => __( 'Damaged', 'connectlibrary' ),
			self::ITEM_LOST    => __( 'Lost', 'connectlibrary' ),
			self::ITEM_RETIRED => __( 'Retired', 'connectlibrary' ),
		);
	}

	/**
	 * Translatable admin labels for metadata source values.
	 *
	 * @return array<string,string>
	 */
	public static function metadata_source_labels(): array {
		return array(
			self::METADATA_MANUAL       => __( 'Manual', 'connectlibrary' ),
			self::METADATA_GOOGLE_BOOKS => __( 'Google Books', 'connectlibrary' ),
			self::METADATA_OPEN_LIBRARY => __( 'Open Library', 'connectlibrary' ),
			self::METADATA_UNKNOWN      => __( 'Unknown', 'connectlibrary' ),
		);
	}
}
