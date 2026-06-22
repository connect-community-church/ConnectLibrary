<?php
/**
 * Core book taxonomy registration.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Phase 1 catalog taxonomies attached to books.
 */
final class BookTaxonomies {
	public const TAXONOMY_CATEGORY   = 'connectlibrary_book_category';
	public const TAXONOMY_TAG        = 'connectlibrary_book_tag';
	public const TAXONOMY_AGE_LEVEL  = 'connectlibrary_age_level';
	public const TAXONOMY_AUDIENCE   = 'connectlibrary_audience';
	public const TAXONOMY_COLLECTION = 'connectlibrary_collection';

	/**
	 * Register all core book taxonomies.
	 */
	public function register(): void {
		foreach ( $this->get_taxonomies() as $taxonomy => $args ) {
			register_taxonomy( $taxonomy, array( BookPostType::POST_TYPE ), $args );
		}
	}

	/**
	 * Build taxonomy registration args.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_taxonomies(): array {
		return array(
			self::TAXONOMY_CATEGORY   => $this->taxonomy_args(
				$this->hierarchical_labels( __( 'Book Categories', 'connectlibrary' ), __( 'Book Category', 'connectlibrary' ) ),
				true,
				'library/category',
				'connectlibrary-book-categories'
			),
			self::TAXONOMY_TAG        => $this->taxonomy_args(
				$this->tag_labels( __( 'Book Tags', 'connectlibrary' ), __( 'Book Tag', 'connectlibrary' ) ),
				false,
				'library/tag',
				'connectlibrary-book-tags'
			),
			self::TAXONOMY_AGE_LEVEL  => $this->taxonomy_args(
				$this->hierarchical_labels( __( 'Age / Reading Levels', 'connectlibrary' ), __( 'Age / Reading Level', 'connectlibrary' ) ),
				true,
				'library/age-level',
				'connectlibrary-age-levels'
			),
			self::TAXONOMY_AUDIENCE   => $this->taxonomy_args(
				$this->hierarchical_labels( __( 'Audiences', 'connectlibrary' ), __( 'Audience', 'connectlibrary' ) ),
				true,
				'library/audience',
				'connectlibrary-audiences'
			),
			self::TAXONOMY_COLLECTION => $this->taxonomy_args(
				$this->tag_labels( __( 'Collections', 'connectlibrary' ), __( 'Collection', 'connectlibrary' ) ),
				false,
				'library/collection',
				'connectlibrary-collections'
			),
		);
	}

	/**
	 * Build common taxonomy args.
	 *
	 * @param array<string,string> $labels Taxonomy labels.
	 * @param bool                 $hierarchical Whether the taxonomy is hierarchical.
	 * @param string               $slug Public rewrite slug.
	 * @param string               $rest_base REST base.
	 * @return array<string,mixed>
	 */
	private function taxonomy_args( array $labels, bool $hierarchical, string $slug, string $rest_base ): array {
		return array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'hierarchical'       => $hierarchical,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'show_in_nav_menus'  => true,
			'show_tagcloud'      => ! $hierarchical,
			'show_in_rest'       => true,
			'rest_base'          => $rest_base,
			'rewrite'            => array(
				'slug'         => $slug,
				'with_front'   => false,
				'hierarchical' => $hierarchical,
			),
		);
	}

	/**
	 * Build translated labels for hierarchical taxonomies.
	 *
	 * @param string $plural Plural taxonomy name.
	 * @param string $singular Singular taxonomy name.
	 * @return array<string,string>
	 */
	private function hierarchical_labels( string $plural, string $singular ): array {
		return array(
			'name'              => $plural,
			'singular_name'     => $singular,
			'search_items'      => sprintf(
				/* translators: %s: plural taxonomy label. */
				__( 'Search %s', 'connectlibrary' ),
				$plural
			),
			'all_items'         => sprintf(
				/* translators: %s: plural taxonomy label. */
				__( 'All %s', 'connectlibrary' ),
				$plural
			),
			'parent_item'       => sprintf(
				/* translators: %s: singular taxonomy label. */
				__( 'Parent %s', 'connectlibrary' ),
				$singular
			),
			'parent_item_colon' => sprintf(
				/* translators: %s: singular taxonomy label. */
				__( 'Parent %s:', 'connectlibrary' ),
				$singular
			),
			'edit_item'         => sprintf(
				/* translators: %s: singular taxonomy label. */
				__( 'Edit %s', 'connectlibrary' ),
				$singular
			),
			'update_item'       => sprintf(
				/* translators: %s: singular taxonomy label. */
				__( 'Update %s', 'connectlibrary' ),
				$singular
			),
			'add_new_item'      => sprintf(
				/* translators: %s: singular taxonomy label. */
				__( 'Add New %s', 'connectlibrary' ),
				$singular
			),
			'new_item_name'     => sprintf(
				/* translators: %s: singular taxonomy label. */
				__( 'New %s Name', 'connectlibrary' ),
				$singular
			),
			'menu_name'         => $plural,
		);
	}

	/**
	 * Build translated labels for non-hierarchical taxonomies.
	 *
	 * @param string $plural Plural taxonomy name.
	 * @param string $singular Singular taxonomy name.
	 * @return array<string,string>
	 */
	private function tag_labels( string $plural, string $singular ): array {
		return array(
			'name'                       => $plural,
			'singular_name'              => $singular,
			'search_items'               => sprintf(
				/* translators: %s: plural taxonomy label. */
				__( 'Search %s', 'connectlibrary' ),
				$plural
			),
			'popular_items'              => sprintf(
				/* translators: %s: plural taxonomy label. */
				__( 'Popular %s', 'connectlibrary' ),
				$plural
			),
			'all_items'                  => sprintf(
				/* translators: %s: plural taxonomy label. */
				__( 'All %s', 'connectlibrary' ),
				$plural
			),
			'edit_item'                  => sprintf(
				/* translators: %s: singular taxonomy label. */
				__( 'Edit %s', 'connectlibrary' ),
				$singular
			),
			'update_item'                => sprintf(
				/* translators: %s: singular taxonomy label. */
				__( 'Update %s', 'connectlibrary' ),
				$singular
			),
			'add_new_item'               => sprintf(
				/* translators: %s: singular taxonomy label. */
				__( 'Add New %s', 'connectlibrary' ),
				$singular
			),
			'new_item_name'              => sprintf(
				/* translators: %s: singular taxonomy label. */
				__( 'New %s Name', 'connectlibrary' ),
				$singular
			),
			'separate_items_with_commas' => sprintf(
				/* translators: %s: plural taxonomy label. */
				__( 'Separate %s with commas', 'connectlibrary' ),
				strtolower( $plural )
			),
			'add_or_remove_items'        => sprintf(
				/* translators: %s: plural taxonomy label. */
				__( 'Add or remove %s', 'connectlibrary' ),
				strtolower( $plural )
			),
			'choose_from_most_used'      => sprintf(
				/* translators: %s: plural taxonomy label. */
				__( 'Choose from the most used %s', 'connectlibrary' ),
				strtolower( $plural )
			),
			'not_found'                  => sprintf(
				/* translators: %s: plural taxonomy label. */
				__( 'No %s found.', 'connectlibrary' ),
				strtolower( $plural )
			),
			'menu_name'                  => $plural,
		);
	}
}
