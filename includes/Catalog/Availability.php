<?php
/**
 * Public availability resolver for catalog titles.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use ConnectLibrary\Database\Schema;
use ConnectLibrary\Support\Statuses;

/**
 * Converts internal/manual title state into privacy-safe public availability.
 */
final class Availability {
	public const META_STATUS     = '_connectlibrary_public_availability';
	public const META_VISIBILITY = '_connectlibrary_public_visibility';

	/**
	 * Public labels keyed by availability status.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			Statuses::AVAILABILITY_AVAILABLE          => __( 'Available', 'connectlibrary' ),
			Statuses::AVAILABILITY_RESERVED           => __( 'Reserved', 'connectlibrary' ),
			Statuses::AVAILABILITY_CHECKED_OUT        => __( 'Checked Out', 'connectlibrary' ),
			Statuses::AVAILABILITY_WAITLIST_AVAILABLE => __( 'Waitlist Available', 'connectlibrary' ),
			Statuses::AVAILABILITY_UNAVAILABLE        => __( 'Unavailable', 'connectlibrary' ),
			Statuses::AVAILABILITY_HIDDEN             => __( 'Hidden', 'connectlibrary' ),
		);
	}

	/**
	 * Request behavior keyed by availability status.
	 *
	 * @return array<string,string>
	 */
	public static function request_actions(): array {
		return array(
			Statuses::AVAILABILITY_AVAILABLE          => 'reserve',
			Statuses::AVAILABILITY_RESERVED           => 'waitlist',
			Statuses::AVAILABILITY_CHECKED_OUT        => 'waitlist',
			Statuses::AVAILABILITY_WAITLIST_AVAILABLE => 'waitlist',
			Statuses::AVAILABILITY_UNAVAILABLE        => 'contact_librarian',
			Statuses::AVAILABILITY_HIDDEN             => 'none',
		);
	}

	/**
	 * Sort rank for public availability results. Lower ranks sort first.
	 *
	 * @return array<string,int>
	 */
	public static function sort_ranks(): array {
		return array(
			Statuses::AVAILABILITY_AVAILABLE          => 10,
			Statuses::AVAILABILITY_WAITLIST_AVAILABLE => 20,
			Statuses::AVAILABILITY_RESERVED           => 30,
			Statuses::AVAILABILITY_CHECKED_OUT        => 40,
			Statuses::AVAILABILITY_UNAVAILABLE        => 50,
			Statuses::AVAILABILITY_HIDDEN             => 999,
		);
	}

	/**
	 * Normalize a manual Phase 1 availability value.
	 *
	 * @param mixed $status Stored or requested status value.
	 */
	public static function normalize_status( mixed $status ): string {
		$status = sanitize_key( (string) $status );

		if ( in_array( $status, Statuses::availability_statuses(), true ) ) {
			return $status;
		}

		return Statuses::AVAILABILITY_UNAVAILABLE;
	}

	/**
	 * Normalize public visibility.
	 *
	 * @param mixed $visibility Stored or requested visibility value.
	 */
	public static function normalize_visibility( mixed $visibility ): string {
		$visibility = sanitize_key( (string) $visibility );

		if ( in_array( $visibility, Statuses::visibility_statuses(), true ) ) {
			return $visibility;
		}

		return Statuses::VISIBILITY_PUBLIC;
	}

	/**
	 * Build the safe public availability object for a title.
	 *
	 * When Phase 2 copy rows exist for the book, derives availability from their
	 * circulation_status. Falls back to Phase 1 manual meta when no copies exist.
	 *
	 * @param int $post_id Book post ID.
	 * @return array{status:string,label:string,request_action:string}
	 */
	public static function for_book( int $post_id ): array {
		$raw_visibility = get_post_meta( $post_id, self::META_VISIBILITY, true );
		$visibility     = '' === $raw_visibility ? Statuses::VISIBILITY_PUBLIC : self::normalize_visibility( $raw_visibility );

		if ( Statuses::VISIBILITY_HIDDEN === $visibility ) {
			return self::response_for_status( Statuses::AVAILABILITY_HIDDEN );
		}

		// Phase 2: derive status from copy circulation data when copies exist.
		$copy_driven = self::compute_from_copies( $post_id );
		if ( null !== $copy_driven ) {
			return self::response_for_status( $copy_driven );
		}

		// Phase 1 meta fallback.
		$raw_status    = get_post_meta( $post_id, self::META_STATUS, true );
		$status        = '' === $raw_status ? Statuses::AVAILABILITY_AVAILABLE : self::normalize_status( $raw_status );
		$hold_override = self::compute_hold_override( $post_id );
		if ( null !== $hold_override ) {
			$status = $hold_override;
		}

		return self::response_for_status( $status );
	}

	/**
	 * Compute public availability from Phase 2 copy circulation data.
	 *
	 * Returns null when no public copies exist, allowing the caller to fall
	 * through to the Phase 1 meta fallback. Never leaks borrower IDs or private
	 * loan details.
	 *
	 * @param int $post_id Book post ID.
	 */
	private static function compute_from_copies( int $post_id ): ?string {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return null;
		}

		try {
			$tables = Schema::table_names();
			$copies = $wpdb->get_results(
				"SELECT circulation_status, item_status, visibility FROM {$tables['copies']} WHERE book_post_id = {$post_id}",
				ARRAY_A
			);

			if ( ! is_array( $copies ) || empty( $copies ) ) {
				return null;
			}

			$public = array_filter(
				$copies,
				static fn( array $c ): bool => 'public' === ( $c['visibility'] ?? 'public' )
			);

			if ( empty( $public ) ) {
				return null;
			}

			$statuses  = array_map( array( self::class, 'copy_rollup_status' ), array_values( $public ) );
			$by_status = array_count_values( $statuses );

			if ( ! empty( $by_status[ Statuses::COPY_AVAILABLE ] ) ) {
				// All available slots may be consumed by active holds.
				$hold_override = self::compute_hold_override( $post_id );
				if ( null !== $hold_override ) {
					return $hold_override;
				}
				return Statuses::AVAILABILITY_AVAILABLE;
			}

			if ( ! empty( $by_status[ Statuses::COPY_ON_HOLD ] ) ) {
				return Statuses::AVAILABILITY_WAITLIST_AVAILABLE;
			}

			if ( ! empty( $by_status[ Statuses::COPY_CHECKED_OUT ] ) ) {
				return Statuses::AVAILABILITY_CHECKED_OUT;
			}

			return Statuses::AVAILABILITY_UNAVAILABLE;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Normalize a copy row into the Phase 2 circulation status used for rollups.
	 *
	 * Legacy metadata-created copies may not have circulation_status yet; treat
	 * item_status=active as available so Phase 1 catalog behavior is preserved.
	 *
	 * @param array<string,mixed> $copy Copy row.
	 */
	private static function copy_rollup_status( array $copy ): string {
		$status = sanitize_key( (string) ( $copy['circulation_status'] ?? '' ) );
		if ( Statuses::is_valid_copy_status( $status ) ) {
			return $status;
		}

		$item_status = sanitize_key( (string) ( $copy['item_status'] ?? Statuses::ITEM_ACTIVE ) );
		return match ( $item_status ) {
			Statuses::ITEM_DAMAGED => Statuses::COPY_DAMAGED,
			Statuses::ITEM_LOST => Statuses::COPY_LOST,
			Statuses::ITEM_RETIRED => Statuses::COPY_RETIRED,
			default => Statuses::COPY_AVAILABLE,
		};
	}

	/**
	 * Determine if active holds have consumed all available copies for a book.
	 *
	 * Returns 'reserved' when every active/public copy is under an active_hold;
	 * returns null to fall through to manual meta behavior. Safe to call even
	 * when no copies or reservations exist.
	 *
	 * @param int $post_id Book post ID.
	 */
	private static function compute_hold_override( int $post_id ): ?string {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return null;
		}

		try {
			$tables = Schema::table_names();

			$holds = $wpdb->get_results( "SELECT status FROM {$tables['reservations']} WHERE book_post_id = {$post_id}", ARRAY_A );
			if ( ! is_array( $holds ) ) {
				return null;
			}
			$active_hold_count = count(
				array_filter( $holds, static fn( array $r ): bool => 'active_hold' === ( $r['status'] ?? '' ) )
			);
			if ( 0 === $active_hold_count ) {
				return null;
			}

			$copies = $wpdb->get_results( "SELECT item_status, visibility FROM {$tables['copies']} WHERE book_post_id = {$post_id}", ARRAY_A );
			if ( ! is_array( $copies ) ) {
				return null;
			}
			$public_copy_count = count(
				array_filter( $copies, static fn( array $c ): bool => 'active' === ( $c['item_status'] ?? '' ) && 'public' === ( $c['visibility'] ?? '' ) )
			);
			if ( 0 === $public_copy_count ) {
				return null;
			}

			return $active_hold_count >= $public_copy_count ? Statuses::AVAILABILITY_RESERVED : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Build a safe public availability response for a known status.
	 *
	 * @param string $status Public status key.
	 * @return array{status:string,label:string,request_action:string}
	 */
	public static function response_for_status( string $status ): array {
		$status  = Statuses::AVAILABILITY_HIDDEN === $status ? $status : self::normalize_status( $status );
		$labels  = self::labels();
		$actions = self::request_actions();

		return array(
			'status'         => $status,
			'label'          => $labels[ $status ],
			'request_action' => $actions[ $status ],
		);
	}

	/**
	 * Return whether a title should be shown in public catalog contexts.
	 *
	 * @param int $post_id Book post ID.
	 */
	public static function is_public( int $post_id ): bool {
		return Statuses::VISIBILITY_HIDDEN !== self::normalize_visibility( get_post_meta( $post_id, self::META_VISIBILITY, true ) );
	}

	/**
	 * Sanitize an availability filter from a request.
	 *
	 * @param mixed $filter Raw filter value.
	 * @return string[]
	 */
	public static function sanitize_filter( mixed $filter ): array {
		$values = is_array( $filter ) ? $filter : explode( ',', (string) $filter );
		$valid  = array();

		foreach ( $values as $value ) {
			$value = sanitize_key( (string) $value );
			if ( in_array( $value, Statuses::availability_statuses(), true ) ) {
				$valid[] = $value;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Get a comparable sort rank for a public status.
	 *
	 * @param string $status Public status key.
	 */
	public static function sort_rank( string $status ): int {
		$ranks = self::sort_ranks();

		return $ranks[ $status ] ?? $ranks[ Statuses::AVAILABILITY_UNAVAILABLE ];
	}
}
