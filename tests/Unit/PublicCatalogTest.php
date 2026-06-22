<?php
/**
 * Tests for PublicCatalog shortcode, block, and rendering.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.FunctionComment.WrongStyle

use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\CatalogQueryParams;
use ConnectLibrary\Catalog\PublicCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Build 08 public catalog shortcode and block.
 */
final class PublicCatalogTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_hooks']              = array();
		$GLOBALS['connectlibrary_test_shortcodes']         = array();
		$GLOBALS['connectlibrary_test_blocks']             = array();
		$GLOBALS['connectlibrary_test_block_variations']   = array();
		$GLOBALS['connectlibrary_test_registered_scripts'] = array();
		$GLOBALS['connectlibrary_test_registered_styles']  = array();
		$GLOBALS['connectlibrary_test_enqueued_scripts']   = array();
		$GLOBALS['connectlibrary_test_enqueued_styles']    = array();
		$GLOBALS['connectlibrary_test_script_data']        = array();
		$GLOBALS['connectlibrary_test_post_objects']       = array();
		$GLOBALS['connectlibrary_test_posts']              = array();
		$GLOBALS['connectlibrary_test_post_meta']          = array();
		$GLOBALS['connectlibrary_test_object_terms']       = array();
		$GLOBALS['connectlibrary_test_db_tables']          = array();
	}

	// ── Registration ────────────────────────────────────────────────────────────

	public function test_register_adds_shortcode(): void {
		( new PublicCatalog() )->register();
		self::assertArrayHasKey( PublicCatalog::SHORTCODE, $GLOBALS['connectlibrary_test_shortcodes'] );
	}

	public function test_register_queues_init_hooks_for_assets_and_block(): void {
		( new PublicCatalog() )->register();
		$hooks = $GLOBALS['connectlibrary_test_hooks']['init'] ?? array();
		self::assertGreaterThanOrEqual( 2, count( $hooks ) );
	}

	public function test_register_assets_registers_style_handle(): void {
		( new PublicCatalog() )->register_assets();
		self::assertArrayHasKey( PublicCatalog::STYLE_HANDLE, $GLOBALS['connectlibrary_test_registered_styles'] );
		$style = $GLOBALS['connectlibrary_test_registered_styles'][ PublicCatalog::STYLE_HANDLE ];
		self::assertStringContainsString( 'catalog.css', $style['src'] );
	}

	public function test_register_assets_registers_script_handle(): void {
		( new PublicCatalog() )->register_assets();
		self::assertArrayHasKey( PublicCatalog::SCRIPT_HANDLE, $GLOBALS['connectlibrary_test_registered_scripts'] );
		$script = $GLOBALS['connectlibrary_test_registered_scripts'][ PublicCatalog::SCRIPT_HANDLE ];
		self::assertStringContainsString( 'catalog.js', $script['src'] );
	}

	public function test_register_block_stores_block_with_render_callback(): void {
		( new PublicCatalog() )->register_block();
		self::assertArrayHasKey( PublicCatalog::BLOCK, $GLOBALS['connectlibrary_test_blocks'] );
		$block = $GLOBALS['connectlibrary_test_blocks'][ PublicCatalog::BLOCK ];
		self::assertIsCallable( $block['render_callback'] );
	}

	// ── Attribute sanitisation ───────────────────────────────────────────────

	public function test_sanitize_attrs_returns_defaults_for_empty_input(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array() );
		self::assertSame( 'grid', $attrs['view'] );
		self::assertSame( 12, $attrs['per_page'] );
		self::assertSame( 'title', $attrs['sort'] );
		self::assertTrue( $attrs['show_view_toggle'] );
		self::assertSame( 1, $attrs['page'] );
	}

	public function test_sanitize_attrs_invalid_view_falls_back_to_grid(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'view' => 'table' ) );
		self::assertSame( 'grid', $attrs['view'] );
	}

	public function test_sanitize_attrs_list_view_accepted(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'view' => 'list' ) );
		self::assertSame( 'list', $attrs['view'] );
	}

	public function test_sanitize_attrs_invalid_sort_falls_back_to_title(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'sort' => 'private_notes' ) );
		self::assertSame( 'title', $attrs['sort'] );
	}

	public function test_sanitize_attrs_accepts_build09_public_filter_attrs(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs(
			array(
				'search' => '<b>Narnia</b>',
				'author' => 'c-s-lewis',
				'series' => 'narnia',
				'sort'   => 'author',
			)
		);

		self::assertSame( 'Narnia', $attrs['search'] );
		self::assertSame( 'c-s-lewis', $attrs['author'] );
		self::assertSame( 'narnia', $attrs['series'] );
		self::assertSame( 'author', $attrs['sort'] );
	}

	public function test_sanitize_attrs_per_page_above_max_falls_back_to_default(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'per_page' => 999 ) );
		self::assertSame( 12, $attrs['per_page'] );
	}

	public function test_sanitize_attrs_per_page_zero_falls_back_to_default(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'per_page' => 0 ) );
		self::assertSame( 12, $attrs['per_page'] );
	}

	public function test_sanitize_attrs_per_page_valid_accepted(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'per_page' => 24 ) );
		self::assertSame( 24, $attrs['per_page'] );
	}

	public function test_sanitize_attrs_show_view_toggle_string_false(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'show_view_toggle' => 'false' ) );
		self::assertFalse( $attrs['show_view_toggle'] );
	}

	public function test_sanitize_attrs_show_view_toggle_zero_string(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'show_view_toggle' => '0' ) );
		self::assertFalse( $attrs['show_view_toggle'] );
	}

	public function test_sanitize_attrs_show_view_toggle_truthy_string(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'show_view_toggle' => '1' ) );
		self::assertTrue( $attrs['show_view_toggle'] );
	}

	// ── Grid markup ─────────────────────────────────────────────────────────

	public function test_render_shortcode_outputs_wrapper_with_data_view_grid(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( 'class="connectlibrary-catalog"', $html );
		self::assertStringContainsString( 'data-view="grid"', $html );
	}

	public function test_render_shortcode_grid_items_class(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array( 'view' => 'grid' ) );
		self::assertStringContainsString( 'is-grid', $html );
		self::assertStringNotContainsString( 'is-list', $html );
	}

	// ── List markup ─────────────────────────────────────────────────────────

	public function test_render_shortcode_list_items_class(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array( 'view' => 'list' ) );
		self::assertStringContainsString( 'is-list', $html );
		self::assertStringContainsString( 'data-view="list"', $html );
	}

	// ── Toggle ──────────────────────────────────────────────────────────────

	public function test_render_shortcode_includes_toggle_by_default(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( 'connectlibrary-catalog__toggle', $html );
		self::assertStringContainsString( 'data-view="grid"', $html );
		self::assertStringContainsString( 'data-view="list"', $html );
	}

	public function test_render_shortcode_omits_toggle_when_disabled(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array( 'show_view_toggle' => 'false' ) );
		self::assertStringNotContainsString( 'connectlibrary-catalog__toggle', $html );
	}

	public function test_toggle_aria_pressed_matches_active_view(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array( 'view' => 'list' ) );
		self::assertStringContainsString( 'data-view="list" aria-pressed="true"', $html );
		self::assertStringContainsString( 'data-view="grid" aria-pressed="false"', $html );
	}

	// ── Empty state ─────────────────────────────────────────────────────────

	public function test_render_shortcode_shows_empty_state_when_no_books(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( 'connectlibrary-catalog__empty', $html );
		self::assertStringContainsString( 'No books found.', $html );
	}

	// ── Asset enqueue ───────────────────────────────────────────────────────

	public function test_render_shortcode_enqueues_style_and_script(): void {
		( new PublicCatalog() )->render_shortcode( array() );
		self::assertArrayHasKey( PublicCatalog::STYLE_HANDLE, $GLOBALS['connectlibrary_test_enqueued_styles'] );
		self::assertArrayHasKey( PublicCatalog::SCRIPT_HANDLE, $GLOBALS['connectlibrary_test_enqueued_scripts'] );
	}

	public function test_render_block_enqueues_style_and_script(): void {
		( new PublicCatalog() )->render_block( array() );
		self::assertArrayHasKey( PublicCatalog::STYLE_HANDLE, $GLOBALS['connectlibrary_test_enqueued_styles'] );
		self::assertArrayHasKey( PublicCatalog::SCRIPT_HANDLE, $GLOBALS['connectlibrary_test_enqueued_scripts'] );
	}

	// ── Public books rendered ───────────────────────────────────────────────

	public function test_render_shortcode_shows_public_book_title(): void {
		$id = $this->create_book( 'The Narnia Chronicles', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( 'The Narnia Chronicles', $html );
	}

	public function test_render_shortcode_book_card_contains_article_element(): void {
		$id = $this->create_book( 'A Good Book', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( '<article class="connectlibrary-catalog__book">', $html );
	}

	public function test_render_shortcode_book_card_contains_permalink(): void {
		$id = $this->create_book( 'Linked Book', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( 'https://example.test/?p=' . $id, $html );
	}

	// ── Hidden exclusion via Availability ────────────────────────────────────

	public function test_render_shortcode_excludes_hidden_books(): void {
		$public_id = $this->create_book( 'Visible Story', 'publish' );
		$hidden_id = $this->create_book( 'Hidden Story', 'publish' );

		$GLOBALS['connectlibrary_test_post_meta'][ $public_id ][ Availability::META_VISIBILITY ] = 'public';
		$GLOBALS['connectlibrary_test_post_meta'][ $hidden_id ][ Availability::META_VISIBILITY ] = 'hidden';

		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( 'Visible Story', $html );
		self::assertStringNotContainsString( 'Hidden Story', $html );
	}

	public function test_render_shortcode_excludes_draft_books(): void {
		$published_id = $this->create_book( 'Published Book', 'publish' );
		$draft_id     = $this->create_book( 'Draft Book', 'draft' );

		$GLOBALS['connectlibrary_test_post_meta'][ $published_id ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( 'Published Book', $html );
		self::assertStringNotContainsString( 'Draft Book', $html );
	}

	// ── Pagination ───────────────────────────────────────────────────────────

	public function test_pagination_absent_when_only_one_page(): void {
		$id = $this->create_book( 'Only Book', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode( array( 'per_page' => 12 ) );
		self::assertStringNotContainsString( 'connectlibrary-catalog__pagination', $html );
	}

	public function test_pagination_present_when_multiple_pages(): void {
		$id1 = $this->create_book( 'Alpha Book', 'publish' );
		$id2 = $this->create_book( 'Beta Book', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id1 ][ Availability::META_VISIBILITY ] = 'public';
		$GLOBALS['connectlibrary_test_post_meta'][ $id2 ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode( array( 'per_page' => 1 ) );
		self::assertStringContainsString( 'connectlibrary-catalog__pagination', $html );
	}

	public function test_pagination_has_aria_label(): void {
		$id1 = $this->create_book( 'Alpha Book', 'publish' );
		$id2 = $this->create_book( 'Beta Book', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id1 ][ Availability::META_VISIBILITY ] = 'public';
		$GLOBALS['connectlibrary_test_post_meta'][ $id2 ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode( array( 'per_page' => 1 ) );
		self::assertStringContainsString( 'aria-label=', $html );
	}

	public function test_pagination_current_page_has_aria_current(): void {
		$id1 = $this->create_book( 'Alpha Book', 'publish' );
		$id2 = $this->create_book( 'Beta Book', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id1 ][ Availability::META_VISIBILITY ] = 'public';
		$GLOBALS['connectlibrary_test_post_meta'][ $id2 ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode(
			array(
				'per_page' => 1,
				'page'     => 1,
			)
		);
		self::assertStringContainsString( 'aria-current="page"', $html );
	}

	public function test_pagination_links_contain_cl_page_param(): void {
		$id1 = $this->create_book( 'Alpha Book', 'publish' );
		$id2 = $this->create_book( 'Beta Book', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id1 ][ Availability::META_VISIBILITY ] = 'public';
		$GLOBALS['connectlibrary_test_post_meta'][ $id2 ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode(
			array(
				'per_page' => 1,
				'page'     => 1,
			)
		);
		self::assertStringContainsString( 'cl_page=', $html );
	}

	public function test_pagination_page2_marks_page2_as_current(): void {
		$id1 = $this->create_book( 'Alpha Book', 'publish' );
		$id2 = $this->create_book( 'Beta Book', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id1 ][ Availability::META_VISIBILITY ] = 'public';
		$GLOBALS['connectlibrary_test_post_meta'][ $id2 ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode(
			array(
				'per_page' => 1,
				'page'     => 2,
			)
		);
		// Page 2 renders as current (span with aria-current) and page 1 renders as a link.
		self::assertStringContainsString( 'aria-current="page"', $html );
		self::assertStringContainsString( 'cl_page=1', $html );
	}

	public function test_pagination_nav_uses_ol_element(): void {
		$id1 = $this->create_book( 'Alpha Book', 'publish' );
		$id2 = $this->create_book( 'Beta Book', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id1 ][ Availability::META_VISIBILITY ] = 'public';
		$GLOBALS['connectlibrary_test_post_meta'][ $id2 ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode( array( 'per_page' => 1 ) );
		self::assertStringContainsString( '<ol>', $html );
		self::assertStringContainsString( '</ol>', $html );
	}

	// ── Escaping ─────────────────────────────────────────────────────────────

	public function test_book_title_is_html_escaped(): void {
		$id = $this->create_book( '<script>alert("xss")</script>Evil', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id ][ Availability::META_VISIBILITY ] = 'public';

		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}

	// ── Block render ─────────────────────────────────────────────────────────

	public function test_render_block_returns_catalog_html(): void {
		$html = ( new PublicCatalog() )->render_block( array() );
		self::assertStringContainsString( 'connectlibrary-catalog', $html );
	}

	public function test_render_block_respects_list_view_attr(): void {
		$html = ( new PublicCatalog() )->render_block( array( 'view' => 'list' ) );
		self::assertStringContainsString( 'is-list', $html );
	}

	// ── Alias shortcode registration ─────────────────────────────────────────

	public function test_register_adds_new_arrivals_shortcode(): void {
		( new PublicCatalog() )->register();
		self::assertArrayHasKey( PublicCatalog::SHORTCODE_NEW_ARRIVALS, $GLOBALS['connectlibrary_test_shortcodes'] );
	}

	public function test_register_adds_featured_books_shortcode(): void {
		( new PublicCatalog() )->register();
		self::assertArrayHasKey( PublicCatalog::SHORTCODE_FEATURED, $GLOBALS['connectlibrary_test_shortcodes'] );
	}

	public function test_register_adds_category_books_shortcode(): void {
		( new PublicCatalog() )->register();
		self::assertArrayHasKey( PublicCatalog::SHORTCODE_CATEGORY, $GLOBALS['connectlibrary_test_shortcodes'] );
	}

	public function test_register_adds_author_books_shortcode(): void {
		( new PublicCatalog() )->register();
		self::assertArrayHasKey( PublicCatalog::SHORTCODE_AUTHOR, $GLOBALS['connectlibrary_test_shortcodes'] );
	}

	public function test_register_adds_series_books_shortcode(): void {
		( new PublicCatalog() )->register();
		self::assertArrayHasKey( PublicCatalog::SHORTCODE_SERIES, $GLOBALS['connectlibrary_test_shortcodes'] );
	}

	// ── Alias render callbacks share the same render path ────────────────────

	public function test_new_arrivals_render_outputs_catalog_wrapper(): void {
		$html = ( new PublicCatalog() )->render_new_arrivals( array() );
		self::assertStringContainsString( 'class="connectlibrary-catalog"', $html );
	}

	public function test_featured_books_render_outputs_catalog_wrapper(): void {
		$html = ( new PublicCatalog() )->render_featured_books( array() );
		self::assertStringContainsString( 'class="connectlibrary-catalog"', $html );
	}

	public function test_category_books_render_outputs_catalog_wrapper(): void {
		$html = ( new PublicCatalog() )->render_category_books( array() );
		self::assertStringContainsString( 'class="connectlibrary-catalog"', $html );
	}

	public function test_author_books_render_outputs_catalog_wrapper(): void {
		$html = ( new PublicCatalog() )->render_author_books( array() );
		self::assertStringContainsString( 'class="connectlibrary-catalog"', $html );
	}

	public function test_series_books_render_outputs_catalog_wrapper(): void {
		$html = ( new PublicCatalog() )->render_series_books( array() );
		self::assertStringContainsString( 'class="connectlibrary-catalog"', $html );
	}

	// ── Preset defaults ───────────────────────────────────────────────────────

	public function test_new_arrivals_hides_view_toggle_by_default(): void {
		$html = ( new PublicCatalog() )->render_new_arrivals( array() );
		self::assertStringNotContainsString( 'connectlibrary-catalog__toggle', $html );
	}

	public function test_new_arrivals_empty_state_shows_list_message(): void {
		$html = ( new PublicCatalog() )->render_new_arrivals( array() );
		self::assertStringContainsString( 'No books found for this list.', $html );
	}

	public function test_featured_books_empty_state_shows_list_message(): void {
		$html = ( new PublicCatalog() )->render_featured_books( array() );
		self::assertStringContainsString( 'No books found for this list.', $html );
	}

	public function test_category_books_empty_state_shows_list_message(): void {
		$html = ( new PublicCatalog() )->render_category_books( array() );
		self::assertStringContainsString( 'No books found for this list.', $html );
	}

	public function test_new_arrivals_user_can_override_toggle(): void {
		$html = ( new PublicCatalog() )->render_new_arrivals( array( 'show_view_toggle' => 'true' ) );
		self::assertStringContainsString( 'connectlibrary-catalog__toggle', $html );
	}

	// ── Attribute aliases ─────────────────────────────────────────────────────

	public function test_layout_alias_maps_to_list_view(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'layout' => 'list' ) );
		self::assertSame( 'list', $attrs['view'] );
	}

	public function test_layout_alias_maps_to_grid_view(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'layout' => 'grid' ) );
		self::assertSame( 'grid', $attrs['view'] );
	}

	public function test_layout_alias_invalid_value_falls_back_to_grid(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'layout' => 'masonry' ) );
		self::assertSame( 'grid', $attrs['view'] );
	}

	public function test_layout_alias_overrides_view(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs(
			array(
				'view'   => 'grid',
				'layout' => 'list',
			)
		);
		self::assertSame( 'list', $attrs['view'] );
	}

	public function test_limit_alias_maps_to_per_page(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'limit' => 8 ) );
		self::assertSame( 8, $attrs['per_page'] );
	}

	public function test_limit_alias_above_max_falls_back_to_default(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'limit' => 999 ) );
		self::assertSame( 12, $attrs['per_page'] );
	}

	public function test_limit_alias_zero_falls_back_to_default(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'limit' => 0 ) );
		self::assertSame( 12, $attrs['per_page'] );
	}

	public function test_limit_alias_overrides_per_page(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs(
			array(
				'per_page' => 10,
				'limit'    => 8,
			)
		);
		self::assertSame( 8, $attrs['per_page'] );
	}

	// ── show_filters / show_search accepted without PHP warnings ─────────────

	public function test_show_filters_truthy_string_sanitized(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'show_filters' => '1' ) );
		self::assertTrue( $attrs['show_filters'] );
	}

	public function test_show_search_yes_string_sanitized(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'show_search' => 'yes' ) );
		self::assertTrue( $attrs['show_search'] );
	}

	public function test_show_filters_false_string_sanitized(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'show_filters' => 'false' ) );
		self::assertFalse( $attrs['show_filters'] );
	}

	public function test_show_search_default_is_false(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array() );
		self::assertFalse( $attrs['show_search'] );
	}

	public function test_show_filters_default_is_true(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array() );
		self::assertTrue( $attrs['show_filters'] );
	}

	// ── title attr ────────────────────────────────────────────────────────────

	public function test_title_attr_renders_heading(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array( 'title' => 'New Arrivals' ) );
		self::assertStringContainsString( '<h2', $html );
		self::assertStringContainsString( 'New Arrivals', $html );
	}

	public function test_title_attr_is_html_escaped(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array( 'title' => '<script>alert(1)</script>Books' ) );
		self::assertStringNotContainsString( '<script>', $html );
		// sanitize_text_field strips tags rather than encoding them; esc_html safely encodes the remainder.
		self::assertStringContainsString( 'Books', $html );
	}

	public function test_no_heading_rendered_when_title_empty(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringNotContainsString( '<h2', $html );
	}

	public function test_title_attr_sanitized_in_attrs(): void {
		$attrs = ( new PublicCatalog() )->sanitize_attrs( array( 'title' => '<b>Books</b>' ) );
		self::assertSame( 'Books', $attrs['title'] );
	}

	// ── empty_message override ────────────────────────────────────────────────

	public function test_custom_empty_message_renders(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array( 'empty_message' => 'Nothing here yet.' ) );
		self::assertStringContainsString( 'Nothing here yet.', $html );
	}

	public function test_default_empty_message_for_base_catalog(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( 'No books found.', $html );
	}

	// ── Block variation registration ──────────────────────────────────────────

	public function test_register_queues_block_variations_init_hook(): void {
		( new PublicCatalog() )->register();
		$init_hooks          = $GLOBALS['connectlibrary_test_hooks']['init'] ?? array();
		$has_variations_hook = false;
		foreach ( $init_hooks as $hook ) {
			if ( is_array( $hook ) && isset( $hook[1] ) && 'register_block_variations' === $hook[1] ) {
				$has_variations_hook = true;
				break;
			}
		}
		self::assertTrue( $has_variations_hook, 'Expected register_block_variations to be hooked to init.' );
	}

	public function test_register_block_variations_adds_variations(): void {
		( new PublicCatalog() )->register_block_variations();
		$variations = $GLOBALS['connectlibrary_test_block_variations'][ PublicCatalog::BLOCK ] ?? array();
		self::assertNotEmpty( $variations );
	}

	public function test_register_block_variations_includes_new_arrivals(): void {
		( new PublicCatalog() )->register_block_variations();
		$variations = $GLOBALS['connectlibrary_test_block_variations'][ PublicCatalog::BLOCK ] ?? array();
		$names      = array_column( $variations, 'name' );
		self::assertContains( 'new-arrivals', $names );
	}

	public function test_register_block_variations_includes_featured_books(): void {
		( new PublicCatalog() )->register_block_variations();
		$variations = $GLOBALS['connectlibrary_test_block_variations'][ PublicCatalog::BLOCK ] ?? array();
		$names      = array_column( $variations, 'name' );
		self::assertContains( 'featured-books', $names );
	}

	public function test_block_render_callback_shares_output_path_with_shortcode(): void {
		$catalog = new PublicCatalog();
		$catalog->register_block();
		$block          = $GLOBALS['connectlibrary_test_blocks'][ PublicCatalog::BLOCK ];
		$block_html     = ( $block['render_callback'] )( array() );
		$shortcode_html = $catalog->render_shortcode( array() );
		self::assertEquals( $block_html, $shortcode_html );
	}

	// ── Hidden books remain excluded via shared path ──────────────────────────

	public function test_new_arrivals_excludes_hidden_books(): void {
		$public_id = $this->create_book( 'New Public Book', 'publish' );
		$hidden_id = $this->create_book( 'New Hidden Book', 'publish' );

		$GLOBALS['connectlibrary_test_post_meta'][ $public_id ][ Availability::META_VISIBILITY ] = 'public';
		$GLOBALS['connectlibrary_test_post_meta'][ $hidden_id ][ Availability::META_VISIBILITY ] = 'hidden';

		$html = ( new PublicCatalog() )->render_new_arrivals( array() );
		self::assertStringContainsString( 'New Public Book', $html );
		self::assertStringNotContainsString( 'New Hidden Book', $html );
	}

	// ── Review 09 regression – Filter form structure ────────────────────────

	public function test_filter_form_has_filters_class_by_default(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( 'class="connectlibrary-catalog__filters"', $html );
	}

	public function test_filter_form_has_all_required_labels(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( '>Search<', $html );
		self::assertStringContainsString( '>Category<', $html );
		self::assertStringContainsString( '>Tag<', $html );
		self::assertStringContainsString( '>Age Level<', $html );
		self::assertStringContainsString( '>Availability<', $html );
		self::assertStringContainsString( '>Author<', $html );
		self::assertStringContainsString( '>Series<', $html );
		self::assertStringContainsString( '>Sort By<', $html );
	}

	public function test_filter_form_has_all_required_field_names(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringContainsString( 'name="cl_search"', $html );
		self::assertStringContainsString( 'name="cl_category"', $html );
		self::assertStringContainsString( 'name="cl_tag"', $html );
		self::assertStringContainsString( 'name="cl_age"', $html );
		self::assertStringContainsString( 'name="cl_availability"', $html );
		self::assertStringContainsString( 'name="cl_author"', $html );
		self::assertStringContainsString( 'name="cl_series"', $html );
		self::assertStringContainsString( 'name="cl_sort"', $html );
	}

	public function test_filter_form_omits_cl_page_input(): void {
		$html = ( new PublicCatalog() )->render_shortcode( array() );
		self::assertStringNotContainsString( 'name="cl_page"', $html );
	}

	// ── Review 09 regression – GET overrides ────────────────────────────────

	public function test_get_cl_search_overrides_search_input_value(): void {
		$_GET['cl_search'] = 'Narnia';
		$html              = ( new PublicCatalog() )->render_shortcode( array() );
		unset( $_GET['cl_search'] );
		self::assertStringContainsString( 'value="Narnia"', $html );
	}

	public function test_get_cl_sort_overrides_sort_select_selected_option(): void {
		$_GET['cl_sort'] = 'author';
		$html            = ( new PublicCatalog() )->render_shortcode( array() );
		unset( $_GET['cl_sort'] );
		self::assertStringContainsString( 'value="author" selected="selected"', $html );
	}

	public function test_get_cl_availability_overrides_availability_select_selected_option(): void {
		$_GET['cl_availability'] = 'available';
		$html                    = ( new PublicCatalog() )->render_shortcode( array() );
		unset( $_GET['cl_availability'] );
		self::assertStringContainsString( 'value="available" selected="selected"', $html );
	}

	// ── Review 09 regression – GET search filters books ─────────────────────

	public function test_get_cl_search_filters_rendered_books(): void {
		$id1 = $this->create_book( 'Alpha Quest', 'publish' );
		$id2 = $this->create_book( 'Beta Quest', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id1 ][ Availability::META_VISIBILITY ] = 'public';
		$GLOBALS['connectlibrary_test_post_meta'][ $id2 ][ Availability::META_VISIBILITY ] = 'public';

		$_GET['cl_search'] = 'Alpha';
		$html              = ( new PublicCatalog() )->render_shortcode( array() );
		unset( $_GET['cl_search'] );

		self::assertStringContainsString( 'Alpha Quest', $html );
		self::assertStringNotContainsString( 'Beta Quest', $html );
	}

	// ── Review 09 regression – Pagination preserves filter params ───────────

	public function test_pagination_links_preserve_cl_search_and_cl_sort_with_cl_page(): void {
		$id1 = $this->create_book( 'Story Alpha', 'publish' );
		$id2 = $this->create_book( 'Story Beta', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $id1 ][ Availability::META_VISIBILITY ] = 'public';
		$GLOBALS['connectlibrary_test_post_meta'][ $id2 ][ Availability::META_VISIBILITY ] = 'public';

		$_GET['cl_search'] = 'Story';
		$_GET['cl_sort']   = 'newest';
		$html              = ( new PublicCatalog() )->render_shortcode( array( 'per_page' => 1 ) );
		unset( $_GET['cl_search'], $_GET['cl_sort'] );

		self::assertStringContainsString( 'cl_search=Story', $html );
		self::assertStringContainsString( 'cl_sort=newest', $html );
		self::assertStringContainsString( 'cl_page=', $html );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private function create_book( string $title, string $status ): int {
		return wp_insert_post(
			array(
				'post_type'    => BookPostType::POST_TYPE,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_name'    => sanitize_title( $title ),
				'post_content' => '',
			)
		);
	}
}
