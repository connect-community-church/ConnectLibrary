<?php
/**
 * Tests for the public single-book detail renderer.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Catalog\BookMetadata;
use ConnectLibrary\Catalog\BookMetadataRepository;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\BookRelationshipsRepository;
use ConnectLibrary\Catalog\BookTaxonomies;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Frontend\BookDetailRenderer;
use ConnectLibrary\Frontend\PublicServiceProvider;
use ConnectLibrary\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Build 10 public book detail rendering behaviour.
 */
final class BookDetailRendererTest extends TestCase {

	/** Reset fake WordPress state between tests. */
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_hooks']            = array();
		$GLOBALS['connectlibrary_test_post_meta']        = array();
		$GLOBALS['connectlibrary_test_post_objects']     = array();
		$GLOBALS['connectlibrary_test_posts']            = array();
		$GLOBALS['connectlibrary_test_db_tables']        = array();
		$GLOBALS['connectlibrary_test_object_terms']     = array();
		$GLOBALS['connectlibrary_test_options']          = array();
		$GLOBALS['connectlibrary_test_attachments']      = array();
		$GLOBALS['connectlibrary_test_enqueued_styles']  = array();
		$GLOBALS['connectlibrary_test_is_singular']      = false;
		$GLOBALS['connectlibrary_test_current_post_id']  = 0;
		$GLOBALS['connectlibrary_test_current_user_can'] = array();
		$GLOBALS['connectlibrary_test_wp_die']           = null;
	}

	// ── Populated book ───────────────────────────────────────────────────

	public function test_populated_book_renders_core_metadata_in_html(): void {
		$book_id = $this->create_book( 'The Screwtape Letters', 'screwtape-letters', 'C. S. Lewis' );
		$this->seed_available_copy( $book_id );
		$this->save_metadata(
			$book_id,
			array(
				'isbn_13'        => '9780060652951',
				'publisher'      => 'HarperOne',
				'published_date' => '2001',
				'page_count'     => 209,
				'church_notes'   => 'Great for discipleship groups.',
				'recommended'    => true,
				'room'           => 'Main Hall',
				'shelf'          => 'B3',
				'section'        => 'Christian Life',
			)
		);

		$html = ( new BookDetailRenderer() )->render( $book_id, '<p>A classic apologetic work.</p>' );

		// Core structure
		self::assertStringContainsString( 'connectlibrary-book', $html );
		self::assertStringContainsString( 'connectlibrary-book__sidebar', $html );
		self::assertStringContainsString( 'connectlibrary-book__main', $html );

		// Status label — defaults to 'available'
		self::assertStringContainsString( 'connectlibrary-book__status--available', $html );
		self::assertStringContainsString( 'Available', $html );

		// Author
		self::assertStringContainsString( 'C. S. Lewis', $html );

		// Description
		self::assertStringContainsString( 'connectlibrary-book__description', $html );
		self::assertStringContainsString( '<p>A classic apologetic work.</p>', $html );

		// ISBN
		self::assertStringContainsString( '9780060652951', $html );

		// Publisher
		self::assertStringContainsString( 'HarperOne', $html );

		// Published date
		self::assertStringContainsString( '2001', $html );

		// Pages
		self::assertStringContainsString( '209', $html );

		// Recommended badge
		self::assertStringContainsString( 'Recommended by the librarian', $html );

		// Church notes
		self::assertStringContainsString( 'Great for discipleship groups.', $html );

		// Location
		self::assertStringContainsString( 'Location', $html );
		self::assertStringContainsString( 'Main Hall', $html );
		self::assertStringContainsString( 'B3', $html );
		self::assertStringContainsString( 'Christian Life', $html );
	}

	public function test_populated_book_renders_cover_image_when_thumbnail_set(): void {
		$book_id       = $this->create_book( 'Mere Christianity', 'mere-christianity' );
		$attachment_id = 501;

		$GLOBALS['connectlibrary_test_post_meta'][ $book_id ]['_thumbnail_id'] = $attachment_id;
		$GLOBALS['connectlibrary_test_attachments'][ $attachment_id ]           = array(
			'url'   => 'https://example.test/wp-content/uploads/mere-christianity.jpg',
			'alt'   => '',
			'sizes' => array(),
		);

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'connectlibrary-book__cover-image', $html );
		self::assertStringContainsString( 'mere-christianity.jpg', $html );
		self::assertStringContainsString( 'Cover of Mere Christianity', $html );
		self::assertStringNotContainsString( 'connectlibrary-book__cover--no-image', $html );
	}

	public function test_populated_book_renders_series_with_position(): void {
		$book_id       = $this->create_book( 'The Lion, the Witch and the Wardrobe', 'lion-witch-wardrobe' );
		$relationships = new BookRelationshipsRepository();
		$series_id     = $relationships->create_series( 'The Chronicles of Narnia' );
		$relationships->save(
			$book_id,
			array(
				'author_ids'      => array(),
				'series_id'       => $series_id,
				'series_position' => '1',
			)
		);

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'connectlibrary-book__series', $html );
		self::assertStringContainsString( 'The Chronicles of Narnia', $html );
		self::assertStringContainsString( '#1', $html );
	}

	public function test_populated_book_renders_taxonomy_terms_with_links(): void {
		$book_id = $this->create_book( 'The Pursuit of God', 'pursuit-of-god' );

		$this->assign_term( $book_id, BookTaxonomies::TAXONOMY_CATEGORY, 10, 'spirituality', 'Spirituality' );
		$this->assign_term( $book_id, BookTaxonomies::TAXONOMY_TAG, 20, 'tozer', 'Tozer' );

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'Spirituality', $html );
		self::assertStringContainsString( 'Tozer', $html );
		self::assertStringContainsString( 'connectlibrary-book__term-link', $html );
		// term link URLs are built from taxonomy slug + term slug
		self::assertStringContainsString( 'spirituality', $html );
		self::assertStringContainsString( 'tozer', $html );
	}

	public function test_availability_status_class_and_label_match(): void {
		$book_id = $this->create_book( 'Checked-out Book', 'checked-out-book' );
		$GLOBALS['connectlibrary_test_post_meta'][ $book_id ][ Availability::META_STATUS ] = 'checked_out';

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'connectlibrary-book__status--checked-out', $html );
		self::assertStringContainsString( 'Checked Out', $html );
	}

	public function test_catalog_back_link_appears_when_page_id_configured(): void {
		$book_id = $this->create_book( 'Knowing God', 'knowing-god' );
		// Register post 99 as a 'page' so Settings::sanitize_page_id accepts it.
		$GLOBALS['connectlibrary_test_posts'][99] = 'page';
		Settings::save( array( 'catalog_page_id' => 99 ) );

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'connectlibrary-book__back-link', $html );
		self::assertStringContainsString( 'Back to catalog', $html );
		// get_permalink stub returns https://example.test/?p={id}
		self::assertStringContainsString( '?p=99', $html );
	}

	// ── Sparse / missing-field book ──────────────────────────────────────

	public function test_sparse_book_renders_without_empty_metadata_sections(): void {
		$book_id = $this->create_book( 'Untitled Book', 'untitled-book' );

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		// Should still produce the wrapper structure
		self::assertStringContainsString( 'connectlibrary-book', $html );

		// Status badge should appear (default: available)
		self::assertStringContainsString( 'Available', $html );

		// No cover image — fallback placeholder
		self::assertStringContainsString( 'connectlibrary-book__cover--no-image', $html );
		self::assertStringNotContainsString( 'connectlibrary-book__cover-image', $html );

		// No authors — no author line
		self::assertStringNotContainsString( 'connectlibrary-book__authors', $html );

		// No series — no series line
		self::assertStringNotContainsString( 'connectlibrary-book__series', $html );

		// No metadata — no DL rendered
		self::assertStringNotContainsString( 'connectlibrary-book__details', $html );

		// No terms — no term groups
		self::assertStringNotContainsString( 'connectlibrary-book__terms', $html );

		// No location fields — no location section
		self::assertStringNotContainsString( 'connectlibrary-book__location', $html );

		// No catalog page configured — no back link
		self::assertStringNotContainsString( 'connectlibrary-book__back-link', $html );
	}

	public function test_sparse_book_with_empty_description_omits_description_div(): void {
		$book_id = $this->create_book( 'No Description', 'no-description' );

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringNotContainsString( 'connectlibrary-book__description', $html );
	}

	public function test_catalog_back_link_absent_when_no_page_id_configured(): void {
		$book_id = $this->create_book( 'Another Book', 'another-book' );
		// Ensure catalog_page_id is 0 (default)
		Settings::save( array( 'catalog_page_id' => 0 ) );

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringNotContainsString( 'connectlibrary-book__back-link', $html );
		self::assertStringNotContainsString( 'Back to catalog', $html );
	}

	// ── Privacy and data safety ──────────────────────────────────────────

	public function test_hidden_book_renders_empty_string(): void {
		$book_id = $this->create_book( 'Hidden Book', 'hidden-book' );
		$GLOBALS['connectlibrary_test_post_meta'][ $book_id ][ Availability::META_VISIBILITY ] = 'hidden';

		$html = ( new BookDetailRenderer() )->render( $book_id, 'secret content' );

		self::assertSame( '', $html );
	}

	public function test_private_notes_never_appear_in_rendered_output(): void {
		$book_id = $this->create_book( 'Private Notes Book', 'private-notes-book' );
		$this->save_metadata(
			$book_id,
			array(
				'private_notes' => 'Librarian-only confidential note',
				'church_notes'  => 'Public church note',
			)
		);

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringNotContainsString( 'Librarian-only confidential note', $html );
		self::assertStringContainsString( 'Public church note', $html );
	}

	public function test_missing_post_renders_empty_string(): void {
		$html = ( new BookDetailRenderer() )->render( 9999, 'orphaned content' );

		self::assertSame( '', $html );
	}

	// ── Subtitle / age level / reading level ────────────────────────────

	public function test_renderer_outputs_subtitle_age_level_and_reading_level_when_present(): void {
		$book_id = $this->create_book( 'Mere Christianity', 'mere-christianity' );
		$this->save_metadata(
			$book_id,
			array(
				'subtitle'      => 'An Extended Subtitle',
				'age_level'     => 'Young Adult',
				'reading_level' => 'Grade 8',
			)
		);

		$html = ( new BookDetailRenderer() )->render( $book_id, '' );

		self::assertStringContainsString( 'An Extended Subtitle', $html );
		self::assertStringContainsString( 'Young Adult', $html );
		self::assertStringContainsString( 'Grade 8', $html );
	}

	// ── Hidden book permalink redirect ───────────────────────────────────

	public function test_maybe_redirect_hidden_book_sends_404_to_anonymous_visitor(): void {
		$book_id = $this->create_book( 'Secret Book', 'secret-book' );
		$GLOBALS['connectlibrary_test_post_meta'][ $book_id ][ Availability::META_VISIBILITY ] = 'hidden';
		$GLOBALS['connectlibrary_test_is_singular']                                             = true;
		$GLOBALS['connectlibrary_test_current_post_id']                                         = $book_id;
		$GLOBALS['connectlibrary_test_current_user_can'][ 'edit_post:' . $book_id ]             = false;

		( new PublicServiceProvider() )->maybe_redirect_hidden_book();

		self::assertNotNull( $GLOBALS['connectlibrary_test_wp_die'] );
		self::assertSame( 404, $GLOBALS['connectlibrary_test_wp_die']['response'] );
	}

	public function test_maybe_redirect_hidden_book_allows_editor_through(): void {
		$book_id = $this->create_book( 'Secret Book', 'secret-book' );
		$GLOBALS['connectlibrary_test_post_meta'][ $book_id ][ Availability::META_VISIBILITY ] = 'hidden';
		$GLOBALS['connectlibrary_test_is_singular']                                             = true;
		$GLOBALS['connectlibrary_test_current_post_id']                                         = $book_id;
		$GLOBALS['connectlibrary_test_current_user_can'][ 'edit_post:' . $book_id ]             = true;

		( new PublicServiceProvider() )->maybe_redirect_hidden_book();

		self::assertNull( $GLOBALS['connectlibrary_test_wp_die'] );
	}

	public function test_maybe_redirect_hidden_book_ignores_non_hidden_books(): void {
		$book_id = $this->create_book( 'Normal Book', 'normal-book' );
		$GLOBALS['connectlibrary_test_is_singular']                              = true;
		$GLOBALS['connectlibrary_test_current_post_id']                          = $book_id;
		$GLOBALS['connectlibrary_test_current_user_can'][ 'edit_post:' . $book_id ] = false;

		( new PublicServiceProvider() )->maybe_redirect_hidden_book();

		self::assertNull( $GLOBALS['connectlibrary_test_wp_die'] );
	}

	// ── PublicServiceProvider hook registration ──────────────────────────

	public function test_public_service_provider_registers_content_filter_and_enqueue_action(): void {
		( new PublicServiceProvider() )->register();

		self::assertArrayHasKey( 'the_content', $GLOBALS['connectlibrary_test_hooks'] );
		self::assertArrayHasKey( 'wp_enqueue_scripts', $GLOBALS['connectlibrary_test_hooks'] );
		self::assertArrayHasKey( 'template_redirect', $GLOBALS['connectlibrary_test_hooks'] );
	}

	public function test_render_book_detail_passes_through_non_book_content(): void {
		$GLOBALS['connectlibrary_test_is_singular'] = false;
		$provider                                   = new PublicServiceProvider();

		$result = $provider->render_book_detail( '<p>Not a book.</p>' );

		self::assertSame( '<p>Not a book.</p>', $result );
	}

	public function test_enqueue_styles_stores_style_handle_on_singular_book_page(): void {
		$GLOBALS['connectlibrary_test_is_singular'] = true;
		$provider                                   = new PublicServiceProvider();
		$provider->enqueue_styles();

		self::assertArrayHasKey( 'connectlibrary-book-detail', $GLOBALS['connectlibrary_test_enqueued_styles'] );
		$style = $GLOBALS['connectlibrary_test_enqueued_styles']['connectlibrary-book-detail'];
		self::assertStringContainsString( 'public-book-detail.css', $style['src'] );
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Create a fake published book post, optionally with one author.
	 *
	 * @param string $title  Post title.
	 * @param string $slug   Post slug.
	 * @param string $author Optional author display name.
	 * @return int Post ID.
	 */
	private function create_book( string $title, string $slug, string $author = '' ): int {
		$book_id = wp_insert_post(
			array(
				'post_type'    => BookPostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => '',
			)
		);

		if ( '' !== $author ) {
			$relationships = new BookRelationshipsRepository();
			$author_id     = $relationships->create_author( $author );
			$relationships->save(
				$book_id,
				array(
					'author_ids'      => array( $author_id ),
					'series_id'       => 0,
					'series_position' => '',
				)
			);
		}

		return $book_id;
	}

	/**
	 * Seed an active/public available copy for availability assertions.
	 *
	 * @param int $book_id Book post ID.
	 */
	private function seed_available_copy( int $book_id ): void {
		$tables        = Schema::table_names();
		$copies_table  = $tables['copies'] . ':rows';
		$existing_rows = $GLOBALS['connectlibrary_test_db_tables'][ $copies_table ] ?? array();
		$next_id       = is_array( $existing_rows ) ? count( $existing_rows ) + 1 : 1;

		$GLOBALS['connectlibrary_test_db_tables'][ $copies_table ][] = array(
			'id'                 => $next_id,
			'book_post_id'       => $book_id,
			'circulation_status' => 'available',
			'item_status'        => 'active',
			'visibility'         => 'public',
		);
	}

	/**
	 * Save metadata through the real repository to test the full data path.
	 *
	 * @param int                 $book_id   Book post ID.
	 * @param array<string,mixed> $overrides Field overrides.
	 */
	private function save_metadata( int $book_id, array $overrides ): void {
		( new BookMetadataRepository() )->save( $book_id, BookMetadata::sanitize( $overrides ) );
	}

	/**
	 * Assign a fake taxonomy term to a book.
	 *
	 * @param int    $book_id   Book post ID.
	 * @param string $taxonomy  Taxonomy slug.
	 * @param int    $term_id   Term ID.
	 * @param string $slug      Term slug.
	 * @param string $label     Term display name.
	 */
	private function assign_term( int $book_id, string $taxonomy, int $term_id, string $slug, string $label ): void {
		$GLOBALS['connectlibrary_test_object_terms'][ $book_id ][ $taxonomy ][] = (object) array(
			'term_id' => $term_id,
			'slug'    => $slug,
			'name'    => $label,
		);
	}
}
