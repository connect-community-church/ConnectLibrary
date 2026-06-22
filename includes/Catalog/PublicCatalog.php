<?php
/**
 * Public catalog shortcode and Gutenberg block.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

use ConnectLibrary\Rest\BooksController;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the [connectlibrary_catalog] shortcode, alias preset shortcodes,
 * and connectlibrary/catalog dynamic block with optional block variations.
 */
final class PublicCatalog {
	public const SHORTCODE              = 'connectlibrary_catalog';
	public const SHORTCODE_NEW_ARRIVALS = 'connectlibrary_new_arrivals';
	public const SHORTCODE_FEATURED     = 'connectlibrary_featured_books';
	public const SHORTCODE_CATEGORY     = 'connectlibrary_category_books';
	public const SHORTCODE_AUTHOR       = 'connectlibrary_author_books';
	public const SHORTCODE_SERIES       = 'connectlibrary_series_books';
	public const BLOCK                  = 'connectlibrary/catalog';
	public const SCRIPT_HANDLE          = 'connectlibrary-catalog';
	public const STYLE_HANDLE           = 'connectlibrary-catalog';

	private const DEFAULT_VIEW           = 'grid';
	private const DEFAULT_PER_PAGE       = 12;
	private const DEFAULT_EMBED_PER_PAGE = 6;
	private const ALLOWED_VIEWS          = array( 'grid', 'list' );
	private const ALLOWED_SORTS          = CatalogQueryParams::ALLOWED_SORTS;
	private const MAX_PER_PAGE           = 50;

	/** Register shortcode, block, and asset hooks. */
	public function register(): void {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'init', array( $this, 'register_block_variations' ), 11 );

		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_shortcode( self::SHORTCODE_NEW_ARRIVALS, array( $this, 'render_new_arrivals' ) );
		add_shortcode( self::SHORTCODE_FEATURED, array( $this, 'render_featured_books' ) );
		add_shortcode( self::SHORTCODE_CATEGORY, array( $this, 'render_category_books' ) );
		add_shortcode( self::SHORTCODE_AUTHOR, array( $this, 'render_author_books' ) );
		add_shortcode( self::SHORTCODE_SERIES, array( $this, 'render_series_books' ) );
	}

	/** Register plugin script and style handles. */
	public function register_assets(): void {
		$base_url = plugins_url( 'assets/', dirname( __DIR__, 2 ) . '/connectlibrary.php' );

		wp_register_style(
			self::STYLE_HANDLE,
			$base_url . 'css/catalog.css',
			array(),
			'1.0.0'
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			$base_url . 'js/catalog.js',
			array(),
			'1.0.0',
			true
		);

		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( self::SCRIPT_HANDLE, 'defer', true );
		}

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::SCRIPT_HANDLE, 'connectlibrary', CONNECTLIBRARY_PLUGIN_DIR . '/languages' );
		}
	}

	/** Register the server-rendered Gutenberg block. */
	public function register_block(): void {
		if ( function_exists( 'register_block_type' ) ) {
			register_block_type(
				self::BLOCK,
				array(
					'render_callback' => array( $this, 'render_block' ),
					'attributes'      => $this->block_attributes(),
				)
			);
		}
	}

	/**
	 * Register Gutenberg block variations for common catalog lists.
	 *
	 * Requires WordPress 6.7+ register_block_variation(); silently skips on older installs.
	 * Shortcodes remain independent of this and always work.
	 */
	public function register_block_variations(): void {
		if ( ! function_exists( 'register_block_variation' ) ) {
			return;
		}

		register_block_variation(
			self::BLOCK,
			array(
				'name'        => 'new-arrivals',
				'title'       => __( 'New Arrivals', 'connectlibrary' ),
				'description' => __( 'Recently added books, sorted by newest first.', 'connectlibrary' ),
				'attributes'  => array(
					'sort'             => 'newest',
					'per_page'         => self::DEFAULT_EMBED_PER_PAGE,
					'show_view_toggle' => false,
					'empty_message'    => '',
				),
				'scope'       => array( 'inserter' ),
			)
		);

		register_block_variation(
			self::BLOCK,
			array(
				'name'        => 'featured-books',
				'title'       => __( 'Featured Books', 'connectlibrary' ),
				'description' => __( 'Books in the "featured" category. Assign the featured category slug to highlight titles; empty when no books match.', 'connectlibrary' ),
				'attributes'  => array(
					'category'         => 'featured',
					'per_page'         => self::DEFAULT_EMBED_PER_PAGE,
					'show_view_toggle' => false,
					'empty_message'    => '',
				),
				'scope'       => array( 'inserter' ),
			)
		);

		register_block_variation(
			self::BLOCK,
			array(
				'name'        => 'category-list',
				'title'       => __( 'Category List', 'connectlibrary' ),
				'description' => __( 'Books filtered by category. Set the category attribute to the desired category slug.', 'connectlibrary' ),
				'attributes'  => array(
					'per_page'         => self::DEFAULT_EMBED_PER_PAGE,
					'show_view_toggle' => false,
					'empty_message'    => '',
				),
				'scope'       => array( 'inserter' ),
			)
		);

		register_block_variation(
			self::BLOCK,
			array(
				'name'        => 'kids-youth',
				'title'       => __( 'Kids / Youth', 'connectlibrary' ),
				'description' => __( 'Books for children and youth filtered by age_level. Requires the age_level metadata from catalog setup.', 'connectlibrary' ),
				'attributes'  => array(
					'age_level'        => 'children',
					'per_page'         => self::DEFAULT_EMBED_PER_PAGE,
					'show_view_toggle' => false,
					'empty_message'    => '',
				),
				'scope'       => array( 'inserter' ),
			)
		);

		register_block_variation(
			self::BLOCK,
			array(
				'name'        => 'bible-studies',
				'title'       => __( 'Bible Studies', 'connectlibrary' ),
				'description' => __( 'Books in the Bible Studies category. Uses the category slug "bible-studies".', 'connectlibrary' ),
				'attributes'  => array(
					'category'         => 'bible-studies',
					'per_page'         => self::DEFAULT_EMBED_PER_PAGE,
					'show_view_toggle' => false,
					'empty_message'    => '',
				),
				'scope'       => array( 'inserter' ),
			)
		);
	}

	/**
	 * Shortcode render callback.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_shortcode( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			$this->default_attrs(),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$this->apply_get_overrides( $atts );

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		return $this->render( $this->sanitize_attrs( $atts ) );
	}

	/**
	 * [connectlibrary_new_arrivals] — newest books first, no view toggle.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_new_arrivals( array|string $atts = array() ): string {
		return $this->render_preset_shortcode(
			array(
				'sort'             => CatalogQueryParams::SORT_NEWEST,
				'per_page'         => self::DEFAULT_EMBED_PER_PAGE,
				'show_view_toggle' => false,
				'empty_message'    => 'No books found for this list.',
			),
			$atts,
			self::SHORTCODE_NEW_ARRIVALS
		);
	}

	/**
	 * [connectlibrary_featured_books] — books in category/tag 'featured'; empty when no match.
	 *
	 * Uses the existing category filter (slug: featured). Librarians add books to the
	 * "featured" category in WordPress to surface them here. No new data model required.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render_featured_books( array|string $atts = array() ): string {
		return $this->render_preset_shortcode(
			array(
				'category'         => 'featured',
				'per_page'         => self::DEFAULT_EMBED_PER_PAGE,
				'show_view_toggle' => false,
				'sort'             => CatalogQueryParams::SORT_TITLE,
				'empty_message'    => 'No books found for this list.',
			),
			$atts,
			self::SHORTCODE_FEATURED
		);
	}

	/**
	 * [connectlibrary_category_books category="slug"] — books filtered by category slug.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes. `category` is the primary param.
	 * @return string Rendered HTML.
	 */
	public function render_category_books( array|string $atts = array() ): string {
		return $this->render_preset_shortcode(
			array(
				'per_page'         => self::DEFAULT_EMBED_PER_PAGE,
				'show_view_toggle' => false,
				'sort'             => CatalogQueryParams::SORT_TITLE,
				'empty_message'    => 'No books found for this list.',
			),
			$atts,
			self::SHORTCODE_CATEGORY
		);
	}

	/**
	 * [connectlibrary_author_books author="slug"] — books by a specific author.
	 *
	 * Uses the existing author filter backed by the custom authors table from Build 04.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes. `author` is the primary param.
	 * @return string Rendered HTML.
	 */
	public function render_author_books( array|string $atts = array() ): string {
		return $this->render_preset_shortcode(
			array(
				'per_page'         => self::DEFAULT_EMBED_PER_PAGE,
				'show_view_toggle' => false,
				'sort'             => CatalogQueryParams::SORT_TITLE,
				'empty_message'    => 'No books found for this list.',
			),
			$atts,
			self::SHORTCODE_AUTHOR
		);
	}

	/**
	 * [connectlibrary_series_books series="slug"] — books in a specific series.
	 *
	 * Uses the existing series filter backed by the custom series table from Build 04.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes. `series` is the primary param.
	 * @return string Rendered HTML.
	 */
	public function render_series_books( array|string $atts = array() ): string {
		return $this->render_preset_shortcode(
			array(
				'per_page'         => self::DEFAULT_EMBED_PER_PAGE,
				'show_view_toggle' => false,
				'sort'             => CatalogQueryParams::SORT_TITLE,
				'empty_message'    => 'No books found for this list.',
			),
			$atts,
			self::SHORTCODE_SERIES
		);
	}

	/**
	 * Block server-render callback.
	 *
	 * @param array<string,mixed> $attrs Block attributes.
	 * @return string Rendered HTML.
	 */
	public function render_block( array $attrs = array() ): string {
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$this->apply_get_overrides( $attrs );

		return $this->render( $this->sanitize_attrs( $attrs ) );
	}

	/**
	 * Sanitize and normalise raw attribute values.
	 *
	 * Supports the following public attrs and aliases:
	 *   view / layout  — 'grid' or 'list' (layout is an alias for view)
	 *   per_page / limit — positive int capped at 50 (limit is an alias for per_page)
	 *   sort            — title | author | newest | availability
	 *   search, category, tag, age_level, availability, author, series — text filter slugs
	 *   show_view_toggle — bool (default true for base catalog, false for presets)
	 *   show_filters     — bool (default true); renders the GET filter form above the book list
	 *   show_search      — bool, accepted and stored; search field is included in the filter form
	 *   title            — optional heading text above the catalog
	 *   empty_message    — override the empty-state paragraph
	 *
	 * @param array<string,mixed> $raw Raw attribute values.
	 * @return array<string,mixed>
	 */
	public function sanitize_attrs( array $raw ): array {
		// layout is an alias for view; when non-empty it overrides view.
		$layout = sanitize_key( (string) ( $raw['layout'] ?? '' ) );
		$view   = sanitize_key( (string) ( $raw['view'] ?? self::DEFAULT_VIEW ) );
		if ( '' !== $layout ) {
			$view = $layout;
		}

		// limit is an alias for per_page; when positive it overrides per_page.
		$limit    = absint( $raw['limit'] ?? 0 );
		$per_page = absint( $raw['per_page'] ?? self::DEFAULT_PER_PAGE );
		if ( $limit > 0 ) {
			$per_page = $limit;
		}

		$sort = sanitize_key( (string) ( $raw['sort'] ?? 'title' ) );
		$page = max( 1, absint( $raw['page'] ?? 1 ) );

		if ( ! in_array( $view, self::ALLOWED_VIEWS, true ) ) {
			$view = self::DEFAULT_VIEW;
		}
		if ( ! in_array( $sort, self::ALLOWED_SORTS, true ) ) {
			$sort = 'title';
		}
		if ( $per_page < 1 || $per_page > self::MAX_PER_PAGE ) {
			$per_page = self::DEFAULT_PER_PAGE;
		}

		return array(
			'view'             => $view,
			'show_view_toggle' => $this->parse_bool( $raw['show_view_toggle'] ?? true ),
			'per_page'         => $per_page,
			'search'           => sanitize_text_field( (string) ( $raw['search'] ?? '' ) ),
			'category'         => sanitize_text_field( (string) ( $raw['category'] ?? '' ) ),
			'tag'              => sanitize_text_field( (string) ( $raw['tag'] ?? '' ) ),
			'age_level'        => sanitize_text_field( (string) ( $raw['age_level'] ?? '' ) ),
			'availability'     => sanitize_text_field( (string) ( $raw['availability'] ?? '' ) ),
			'author'           => sanitize_text_field( (string) ( $raw['author'] ?? '' ) ),
			'series'           => sanitize_text_field( (string) ( $raw['series'] ?? '' ) ),
			'sort'             => $sort,
			'page'             => $page,
			'show_filters'     => $this->parse_bool( $raw['show_filters'] ?? true ),
			'show_search'      => $this->parse_bool( $raw['show_search'] ?? false ),
			'title'            => sanitize_text_field( (string) ( $raw['title'] ?? '' ) ),
			'empty_message'    => sanitize_text_field( (string) ( $raw['empty_message'] ?? '' ) ),
		);
	}

	/**
	 * Apply preset defaults for an alias shortcode then delegate to the shared render path.
	 *
	 * Preset attrs are the defaults; user-supplied attrs override them. Both are then
	 * passed through sanitize_attrs() so the same validation rules apply as for the
	 * base catalog shortcode.
	 *
	 * @param array<string,mixed>        $preset       Preset default attributes.
	 * @param array<string,mixed>|string $raw_atts     Raw user attributes from the shortcode.
	 * @param string                     $shortcode_tag Tag name for shortcode_atts filter.
	 * @return string Rendered HTML.
	 */
	private function render_preset_shortcode( array $preset, array|string $raw_atts, string $shortcode_tag ): string {
		$defaults = array_merge( $this->default_attrs(), $preset );
		$atts     = shortcode_atts( $defaults, is_array( $raw_atts ) ? $raw_atts : array(), $shortcode_tag );

		$this->apply_get_overrides( $atts );

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		return $this->render( $this->sanitize_attrs( $atts ) );
	}

	/**
	 * Render catalog HTML from sanitised attrs.
	 *
	 * @param array<string,mixed> $attrs Sanitised attributes.
	 * @return string Rendered HTML.
	 */
	private function render( array $attrs ): string {
		$result       = $this->fetch_books( $attrs );
		$books        = $result['items'];
		$total_pages  = $result['total_pages'];
		$current_page = (int) ( $attrs['page'] ?? 1 );
		$view         = esc_attr( $attrs['view'] );

		$out = '';

		// Optional accessible heading for embedded lists.
		$heading = (string) ( $attrs['title'] ?? '' );
		if ( '' !== $heading ) {
			$out .= '<h2 class="connectlibrary-catalog__heading">' . esc_html( $heading ) . '</h2>';
		}

		$region_label = '' !== $heading ? esc_attr( $heading ) : esc_attr( __( 'Library catalog', 'connectlibrary' ) );
		$out         .= '<div class="connectlibrary-catalog" data-view="' . $view . '" role="region" aria-label="' . $region_label . '">';

		if ( $attrs['show_filters'] ) {
			$out .= $this->render_filter_form( $attrs );
		}

		$out .= $attrs['show_view_toggle'] ? $this->render_toggle( $attrs['view'] ) : '';
		$out .= '<div class="connectlibrary-catalog__items is-' . $view . '">';

		if ( empty( $books ) ) {
			$empty_msg = '' !== ( $attrs['empty_message'] ?? '' )
				? esc_html( $attrs['empty_message'] )
				: esc_html( __( 'No books found.', 'connectlibrary' ) );
			$out      .= '<p class="connectlibrary-catalog__empty">' . $empty_msg . '</p>';
		} else {
			foreach ( $books as $book ) {
				$out .= $this->render_book_card( $book );
			}
		}

		$out .= '</div>';
		$out .= $this->render_pagination( $current_page, $total_pages, $attrs );
		$out .= '</div>';

		return $out;
	}

	/**
	 * Render no-JS page navigation when there is more than one page.
	 *
	 * @param int   $current_page Current page number (1-based).
	 * @param int   $total_pages  Total number of pages.
	 * @param array $attrs        Sanitised catalog attributes.
	 * @return string Rendered HTML, or empty string when only one page.
	 */
	private function render_pagination( int $current_page, int $total_pages, array $attrs ): string {
		if ( $total_pages <= 1 ) {
			return '';
		}

		// Collect active cl_* filter/sort params to preserve across page links.
		$filter_args = array();
		$param_map   = array(
			'search'       => CatalogQueryParams::PARAM_SEARCH,
			'category'     => CatalogQueryParams::PARAM_CATEGORY,
			'tag'          => CatalogQueryParams::PARAM_TAG,
			'age_level'    => CatalogQueryParams::PARAM_AGE,
			'availability' => CatalogQueryParams::PARAM_AVAILABILITY,
			'author'       => CatalogQueryParams::PARAM_AUTHOR,
			'series'       => CatalogQueryParams::PARAM_SERIES,
			'sort'         => CatalogQueryParams::PARAM_SORT,
		);
		foreach ( $param_map as $attr_key => $param_key ) {
			$val = (string) ( $attrs[ $attr_key ] ?? '' );
			if ( '' !== $val ) {
				$filter_args[ $param_key ] = $val;
			}
		}

		// Strip all cl_* from the current URL to get a clean base, then re-add active filters per page.
		$base = function_exists( 'remove_query_arg' ) ? remove_query_arg( CatalogQueryParams::all_param_keys() ) : '';

		$out  = '<nav class="connectlibrary-catalog__pagination" aria-label="' . esc_attr( __( 'Catalog pages', 'connectlibrary' ) ) . '">';
		$out .= '<ol>';

		for ( $i = 1; $i <= $total_pages; $i++ ) {
			$page_args = array_merge( $filter_args, array( CatalogQueryParams::PARAM_PAGE => $i ) );
			$url       = esc_url( add_query_arg( $page_args, $base ) );
			if ( $i === $current_page ) {
				$out .= '<li><span aria-current="page">' . esc_html( (string) $i ) . '</span></li>';
			} else {
				$out .= '<li><a href="' . $url . '">' . esc_html( (string) $i ) . '</a></li>';
			}
		}

		$out .= '</ol>';
		$out .= '</nav>';

		return $out;
	}

	/**
	 * Fetch paginated public books via BooksController, inheriting hidden/private filtering.
	 *
	 * @param array<string,mixed> $attrs Sanitised attributes.
	 * @return array{items: array<int,array<string,mixed>>, total_pages: int}
	 */
	private function fetch_books( array $attrs ): array {
		$request = new WP_REST_Request();

		$request['per_page']     = $attrs['per_page'];
		$request['page']         = $attrs['page'];
		$request['search']       = $attrs['search'];
		$request['sort']         = $attrs['sort'];
		$request['order']        = 'asc';
		$request['category']     = $attrs['category'];
		$request['tag']          = $attrs['tag'];
		$request['age_level']    = $attrs['age_level'];
		$request['availability'] = $attrs['availability'];
		$request['author']       = $attrs['author'];
		$request['series']       = $attrs['series'];

		$response = ( new BooksController() )->get_items( $request );

		if ( is_wp_error( $response ) ) {
			return array(
				'items'       => array(),
				'total_pages' => 1,
			);
		}

		$data  = method_exists( $response, 'get_data' ) ? $response->get_data() : array();
		$items = is_array( $data ) ? $data : array();

		$total_pages = 1;
		if ( method_exists( $response, 'get_headers' ) ) {
			$headers     = $response->get_headers();
			$total_pages = max( 1, (int) ( $headers['X-WP-TotalPages'] ?? 1 ) );
		}

		return array(
			'items'       => $items,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Render accessible grid/list view-toggle buttons.
	 *
	 * @param string $active Current active view.
	 * @return string Rendered HTML.
	 */
	private function render_toggle( string $active ): string {
		$out  = '<div class="connectlibrary-catalog__toggle" role="group" aria-label="' . esc_attr( __( 'View style', 'connectlibrary' ) ) . '">';
		$out .= '<button type="button" class="connectlibrary-catalog__toggle-btn" data-view="grid" aria-pressed="' . ( 'grid' === $active ? 'true' : 'false' ) . '">' . esc_html( __( 'Grid', 'connectlibrary' ) ) . '</button>';
		$out .= '<button type="button" class="connectlibrary-catalog__toggle-btn" data-view="list" aria-pressed="' . ( 'list' === $active ? 'true' : 'false' ) . '">' . esc_html( __( 'List', 'connectlibrary' ) ) . '</button>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * Render a single public-safe book card article.
	 *
	 * @param array<string,mixed> $book Serialized public book payload.
	 * @return string Rendered HTML.
	 */
	private function render_book_card( array $book ): string {
		$title        = esc_html( (string) ( $book['title'] ?? '' ) );
		$permalink    = esc_url( (string) ( $book['links']['detail'] ?? '' ) );
		$avail_status = esc_attr( (string) ( $book['availability_status'] ?? '' ) );
		$avail_label  = esc_html( (string) ( $book['availability_label'] ?? '' ) );

		$out  = '<article class="connectlibrary-catalog__book">';
		$out .= $this->render_book_cover( $book, $permalink );
		$out .= '<div class="connectlibrary-catalog__info">';
		$out .= '<h3 class="connectlibrary-catalog__title"><a href="' . $permalink . '">' . $title . '</a></h3>';
		$out .= $this->render_author_list( $book['authors'] ?? array() );
		$out .= $this->render_series_label( $book['series'] ?? null );
		$out .= '<span class="connectlibrary-catalog__availability connectlibrary-catalog__availability--' . $avail_status . '">' . $avail_label . '</span>';
		$out .= $this->render_category_list( $book['categories'] ?? array() );
		$age  = (string) ( $book['age_level'] ?? '' );
		if ( '' !== $age ) {
			$out .= '<p class="connectlibrary-catalog__age-level">' . esc_html( $age ) . '</p>';
		}
		$out .= '</div>';
		$out .= '</article>';

		return $out;
	}

	/**
	 * Render cover image or placeholder.
	 *
	 * @param array<string,mixed> $book      Book payload.
	 * @param string              $permalink Book detail URL.
	 * @return string Rendered HTML.
	 */
	private function render_book_cover( array $book, string $permalink ): string {
		$cover = $book['cover'] ?? null;

		if ( is_array( $cover ) && ! empty( $cover['url'] ) ) {
			$src = esc_url( (string) ( $cover['sizes']['medium'] ?? $cover['url'] ) );
			$alt = esc_attr( (string) ( $cover['alt'] ?? ( $book['title'] ?? '' ) ) );
			return '<div class="connectlibrary-catalog__cover"><a href="' . $permalink . '"><img src="' . $src . '" alt="' . $alt . '" loading="lazy"></a></div>';
		}

		return '<div class="connectlibrary-catalog__cover connectlibrary-catalog__cover--placeholder" aria-hidden="true"></div>';
	}

	/**
	 * Render comma-separated author byline.
	 *
	 * @param array<int,array<string,mixed>> $authors Author lookup objects.
	 * @return string Rendered HTML.
	 */
	private function render_author_list( array $authors ): string {
		if ( empty( $authors ) ) {
			return '';
		}

		$labels = array_map(
			static fn( array $a ): string => esc_html( (string) ( $a['label'] ?? '' ) ),
			$authors
		);

		return '<p class="connectlibrary-catalog__authors">' . implode( ', ', $labels ) . '</p>';
	}

	/**
	 * Render series label with optional position.
	 *
	 * @param array<string,mixed>|null $series Series lookup object or null.
	 * @return string Rendered HTML.
	 */
	private function render_series_label( mixed $series ): string {
		if ( ! is_array( $series ) || empty( $series['label'] ) ) {
			return '';
		}

		$label = esc_html( (string) $series['label'] );
		$pos   = isset( $series['position'] ) && '' !== (string) $series['position']
			? ' #' . esc_html( (string) $series['position'] )
			: '';

		return '<p class="connectlibrary-catalog__series">' . $label . $pos . '</p>';
	}

	/**
	 * Render comma-separated category list.
	 *
	 * @param array<int,array<string,mixed>> $categories Category lookup objects.
	 * @return string Rendered HTML.
	 */
	private function render_category_list( array $categories ): string {
		if ( empty( $categories ) ) {
			return '';
		}

		$labels = array_map(
			static fn( array $c ): string => esc_html( (string) ( $c['label'] ?? '' ) ),
			$categories
		);

		return '<p class="connectlibrary-catalog__categories">' . implode( ', ', $labels ) . '</p>';
	}

	/**
	 * Render an accessible GET filter form before the item list.
	 *
	 * Outputs all eight public cl_* controls with associated labels and preserves
	 * currently active values. cl_page is intentionally omitted so submitting the
	 * form always resets to page 1.
	 *
	 * @param array<string,mixed> $attrs Sanitised catalog attributes.
	 * @return string Rendered HTML.
	 */
	private function render_filter_form( array $attrs ): string {
		$sort_options = CatalogQueryParams::sort_labels();
		$avail_labels = Availability::labels();
		unset( $avail_labels['hidden'] );
		$avail_options = array_merge( array( '' => __( 'All', 'connectlibrary' ) ), $avail_labels );

		$out = '<form class="connectlibrary-catalog__filters" method="get" action="">';

		$out .= '<div class="connectlibrary-catalog__filter-field">';
		$out .= '<label for="cl_search">' . esc_html__( 'Search', 'connectlibrary' ) . '</label>';
		$out .= '<input type="search" id="cl_search" name="cl_search" value="' . esc_attr( $attrs['search'] ) . '">';
		$out .= '</div>';

		$out .= '<div class="connectlibrary-catalog__filter-field">';
		$out .= '<label for="cl_category">' . esc_html__( 'Category', 'connectlibrary' ) . '</label>';
		$out .= '<input type="text" id="cl_category" name="cl_category" value="' . esc_attr( $attrs['category'] ) . '">';
		$out .= '</div>';

		$out .= '<div class="connectlibrary-catalog__filter-field">';
		$out .= '<label for="cl_tag">' . esc_html__( 'Tag', 'connectlibrary' ) . '</label>';
		$out .= '<input type="text" id="cl_tag" name="cl_tag" value="' . esc_attr( $attrs['tag'] ) . '">';
		$out .= '</div>';

		$out .= '<div class="connectlibrary-catalog__filter-field">';
		$out .= '<label for="cl_age">' . esc_html__( 'Age Level', 'connectlibrary' ) . '</label>';
		$out .= '<input type="text" id="cl_age" name="cl_age" value="' . esc_attr( $attrs['age_level'] ) . '">';
		$out .= '</div>';

		$out .= '<div class="connectlibrary-catalog__filter-field">';
		$out .= '<label for="cl_availability">' . esc_html__( 'Availability', 'connectlibrary' ) . '</label>';
		$out .= '<select id="cl_availability" name="cl_availability">';
		foreach ( $avail_options as $value => $label ) {
			$out .= '<option value="' . esc_attr( (string) $value ) . '"' . ( $attrs['availability'] === (string) $value ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		$out .= '</select>';
		$out .= '</div>';

		$out .= '<div class="connectlibrary-catalog__filter-field">';
		$out .= '<label for="cl_author">' . esc_html__( 'Author', 'connectlibrary' ) . '</label>';
		$out .= '<input type="text" id="cl_author" name="cl_author" value="' . esc_attr( $attrs['author'] ) . '">';
		$out .= '</div>';

		$out .= '<div class="connectlibrary-catalog__filter-field">';
		$out .= '<label for="cl_series">' . esc_html__( 'Series', 'connectlibrary' ) . '</label>';
		$out .= '<input type="text" id="cl_series" name="cl_series" value="' . esc_attr( $attrs['series'] ) . '">';
		$out .= '</div>';

		$out .= '<div class="connectlibrary-catalog__filter-field">';
		$out .= '<label for="cl_sort">' . esc_html__( 'Sort By', 'connectlibrary' ) . '</label>';
		$out .= '<select id="cl_sort" name="cl_sort">';
		foreach ( $sort_options as $value => $label ) {
			$out .= '<option value="' . esc_attr( $value ) . '"' . ( $attrs['sort'] === $value ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		$out .= '</select>';
		$out .= '</div>';

		$out .= '<button type="submit">' . esc_html__( 'Apply', 'connectlibrary' ) . '</button>';
		$out .= '</form>';

		return $out;
	}

	/**
	 * Apply GET query string overrides to attrs before sanitisation.
	 *
	 * All cl_* params from $_GET override the shortcode/block attribute values so
	 * the visible filter form and pagination stay in sync with the submitted URL.
	 * cl_page is included so page navigation also works via GET.
	 *
	 * @param array<string,mixed> $atts Attrs array passed by reference.
	 */
	private function apply_get_overrides( array &$atts ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ CatalogQueryParams::PARAM_PAGE ] ) ) {
			$atts['page'] = max( 1, absint( $_GET[ CatalogQueryParams::PARAM_PAGE ] ) );
		}
		if ( isset( $_GET[ CatalogQueryParams::PARAM_SEARCH ] ) ) {
			$atts['search'] = sanitize_text_field( wp_unslash( (string) $_GET[ CatalogQueryParams::PARAM_SEARCH ] ) );
		}
		if ( isset( $_GET[ CatalogQueryParams::PARAM_CATEGORY ] ) ) {
			$atts['category'] = sanitize_text_field( wp_unslash( (string) $_GET[ CatalogQueryParams::PARAM_CATEGORY ] ) );
		}
		if ( isset( $_GET[ CatalogQueryParams::PARAM_TAG ] ) ) {
			$atts['tag'] = sanitize_text_field( wp_unslash( (string) $_GET[ CatalogQueryParams::PARAM_TAG ] ) );
		}
		if ( isset( $_GET[ CatalogQueryParams::PARAM_AGE ] ) ) {
			$atts['age_level'] = sanitize_text_field( wp_unslash( (string) $_GET[ CatalogQueryParams::PARAM_AGE ] ) );
		}
		if ( isset( $_GET[ CatalogQueryParams::PARAM_AVAILABILITY ] ) ) {
			$atts['availability'] = sanitize_text_field( wp_unslash( (string) $_GET[ CatalogQueryParams::PARAM_AVAILABILITY ] ) );
		}
		if ( isset( $_GET[ CatalogQueryParams::PARAM_AUTHOR ] ) ) {
			$atts['author'] = sanitize_text_field( wp_unslash( (string) $_GET[ CatalogQueryParams::PARAM_AUTHOR ] ) );
		}
		if ( isset( $_GET[ CatalogQueryParams::PARAM_SERIES ] ) ) {
			$atts['series'] = sanitize_text_field( wp_unslash( (string) $_GET[ CatalogQueryParams::PARAM_SERIES ] ) );
		}
		if ( isset( $_GET[ CatalogQueryParams::PARAM_SORT ] ) ) {
			$atts['sort'] = sanitize_key( (string) $_GET[ CatalogQueryParams::PARAM_SORT ] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Default attribute values shared by shortcode and block.
	 *
	 * Includes alias keys (layout, limit) so shortcode_atts passes them through.
	 *
	 * @return array<string,mixed>
	 */
	private function default_attrs(): array {
		return array(
			'view'             => self::DEFAULT_VIEW,
			'layout'           => '',
			'show_view_toggle' => true,
			'per_page'         => self::DEFAULT_PER_PAGE,
			'limit'            => 0,
			'search'           => '',
			'category'         => '',
			'tag'              => '',
			'age_level'        => '',
			'availability'     => '',
			'author'           => '',
			'series'           => '',
			'sort'             => 'title',
			'page'             => 1,
			'show_filters'     => true,
			'show_search'      => false,
			'title'            => '',
			'empty_message'    => '',
		);
	}

	/**
	 * Block attribute schema for the block editor.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function block_attributes(): array {
		return array(
			'view'             => array(
				'type'    => 'string',
				'default' => self::DEFAULT_VIEW,
			),
			'layout'           => array(
				'type'    => 'string',
				'default' => '',
			),
			'show_view_toggle' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'per_page'         => array(
				'type'    => 'integer',
				'default' => self::DEFAULT_PER_PAGE,
			),
			'limit'            => array(
				'type'    => 'integer',
				'default' => 0,
			),
			'search'           => array(
				'type'    => 'string',
				'default' => '',
			),
			'category'         => array(
				'type'    => 'string',
				'default' => '',
			),
			'tag'              => array(
				'type'    => 'string',
				'default' => '',
			),
			'age_level'        => array(
				'type'    => 'string',
				'default' => '',
			),
			'availability'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'author'           => array(
				'type'    => 'string',
				'default' => '',
			),
			'series'           => array(
				'type'    => 'string',
				'default' => '',
			),
			'sort'             => array(
				'type'    => 'string',
				'default' => 'title',
			),
			'show_filters'     => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'show_search'      => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'title'            => array(
				'type'    => 'string',
				'default' => '',
			),
			'empty_message'    => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Parse a boolean-ish shortcode or block attribute value.
	 *
	 * @param mixed $value Raw value.
	 */
	private function parse_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return ! in_array( strtolower( trim( (string) $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
	}
}
