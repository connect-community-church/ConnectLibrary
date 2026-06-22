<?php
/**
 * Shared public catalog query parameter constants and normalization.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes and validates public catalog request parameters shared by the
 * shortcode/block UI and the public REST catalog endpoint.
 *
 * Rating sort is intentionally deferred: no rating metadata exists in Phase 1.
 * Add Statuses::AVAILABILITY_RATING and a sort_payloads() case when data lands.
 */
final class CatalogQueryParams {
	public const SORT_TITLE        = 'title';
	public const SORT_AUTHOR       = 'author';
	public const SORT_NEWEST       = 'newest';
	public const SORT_AVAILABILITY = 'availability';

	/** Sort keys available in the public UI. Rating is deferred. */
	public const ALLOWED_SORTS = array(
		self::SORT_TITLE,
		self::SORT_AUTHOR,
		self::SORT_NEWEST,
		self::SORT_AVAILABILITY,
	);

	public const DEFAULT_SORT     = self::SORT_TITLE;
	public const DEFAULT_PER_PAGE = 12;
	public const MAX_PER_PAGE     = 50;

	/** URL parameter names used by the public catalog. */
	public const PARAM_SEARCH       = 'cl_search';
	public const PARAM_CATEGORY     = 'cl_category';
	public const PARAM_TAG          = 'cl_tag';
	public const PARAM_AGE          = 'cl_age';
	public const PARAM_AVAILABILITY = 'cl_availability';
	public const PARAM_AUTHOR       = 'cl_author';
	public const PARAM_SERIES       = 'cl_series';
	public const PARAM_SORT         = 'cl_sort';
	public const PARAM_PAGE         = 'cl_page';

	/**
	 * Human-readable labels for each public sort option.
	 *
	 * @return array<string,string>
	 */
	public static function sort_labels(): array {
		return array(
			self::SORT_TITLE        => __( 'Title A–Z', 'connectlibrary' ),
			self::SORT_AUTHOR       => __( 'Author A–Z', 'connectlibrary' ),
			self::SORT_NEWEST       => __( 'Newest', 'connectlibrary' ),
			self::SORT_AVAILABILITY => __( 'Availability', 'connectlibrary' ),
		);
	}

	/**
	 * Normalize a raw sort value to an allowed key; falls back to the default.
	 *
	 * @param mixed $sort Raw sort value.
	 */
	public static function normalize_sort( mixed $sort ): string {
		$sort = sanitize_key( (string) $sort );
		return in_array( $sort, self::ALLOWED_SORTS, true ) ? $sort : self::DEFAULT_SORT;
	}

	/**
	 * Normalize a per_page value within allowed bounds.
	 *
	 * @param mixed $per_page Raw per_page value.
	 */
	public static function normalize_per_page( mixed $per_page ): int {
		$per_page = absint( $per_page );
		if ( $per_page < 1 || $per_page > self::MAX_PER_PAGE ) {
			return self::DEFAULT_PER_PAGE;
		}
		return $per_page;
	}

	/**
	 * All URL param keys that should be stripped when clearing active filters.
	 *
	 * @return string[]
	 */
	public static function all_param_keys(): array {
		return array(
			self::PARAM_SEARCH,
			self::PARAM_CATEGORY,
			self::PARAM_TAG,
			self::PARAM_AGE,
			self::PARAM_AVAILABILITY,
			self::PARAM_AUTHOR,
			self::PARAM_SERIES,
			self::PARAM_SORT,
			self::PARAM_PAGE,
		);
	}
}
