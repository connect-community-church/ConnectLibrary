<?php
/**
 * Public lookup endpoints for catalog filters.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Rest;

use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\BookRelationshipsRepository;
use ConnectLibrary\Catalog\BookTaxonomies;
use WP_REST_Request;

/**
 * Provides public-safe lookup data for filters.
 */
final class LookupsController {
	/** Register lookup routes. */
	public function register_routes(): void {
		foreach ( array( 'authors', 'series', 'categories' ) as $route ) {
			register_rest_route(
				Routes::NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_' . $route ),
					'permission_callback' => static fn(): bool => true,
				)
			);
		}
	}

	/**
	 * List public author records.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_authors(): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'    => absint( $row['id'] ?? 0 ),
				'slug'  => (string) ( $row['slug'] ?? '' ),
				'label' => (string) ( $row['display_name'] ?? '' ),
			),
			( new BookRelationshipsRepository() )->list_public_authors( $this->public_book_ids() )
		);
	}

	/**
	 * List public series records.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_series(): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'    => absint( $row['id'] ?? 0 ),
				'slug'  => (string) ( $row['slug'] ?? '' ),
				'label' => (string) ( $row['name'] ?? '' ),
			),
			( new BookRelationshipsRepository() )->list_public_series( $this->public_book_ids() )
		);
	}

	/**
	 * Return IDs of all published books that pass the public-visibility check.
	 *
	 * @return int[]
	 */
	private function public_book_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => BookPostType::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		return array_values(
			array_filter(
				array_map( 'absint', is_array( $ids ) ? $ids : array() ),
				static fn( int $id ): bool => Availability::is_public( $id )
			)
		);
	}

	/**
	 * List public book categories.
	 *
	 * @param WP_REST_Request|null $request REST request.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_categories( ?WP_REST_Request $request = null ): array {
		unset( $request );

		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => BookTaxonomies::TAXONOMY_CATEGORY,
				'hide_empty' => true,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_map(
				static fn( object $term ): array => array(
					'id'    => absint( $term->term_id ?? 0 ),
					'slug'  => (string) ( $term->slug ?? '' ),
					'label' => (string) ( $term->name ?? '' ),
					'count' => absint( $term->count ?? 0 ),
				),
				$terms
			)
		);
	}
}
