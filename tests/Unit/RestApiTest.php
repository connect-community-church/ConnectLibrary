<?php
/**
 * Tests for public catalog REST foundation.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.FunctionComment.ParamNameNoMatch,Squiz.Commenting.FunctionComment.IncorrectTypeHint

use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Catalog\BookMetadata;
use ConnectLibrary\Catalog\BookMetadataRepository;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\BookRelationshipsRepository;
use ConnectLibrary\Catalog\BookTaxonomies;
use ConnectLibrary\Catalog\CatalogServiceProvider;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Rest\BooksController;
use ConnectLibrary\Rest\Routes;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Verifies Build 14 public REST catalog behavior.
 */
final class RestApiTest extends TestCase {
	/** Reset mutable WordPress stubs between tests. */
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_hooks']        = array();
		$GLOBALS['connectlibrary_test_rest_routes']  = array();
		$GLOBALS['connectlibrary_test_post_objects'] = array();
		$GLOBALS['connectlibrary_test_posts']        = array();
		$GLOBALS['connectlibrary_test_post_meta']    = array();
		$GLOBALS['connectlibrary_test_object_terms'] = array();
		$GLOBALS['connectlibrary_test_db_tables']    = array();
	}

	public function test_catalog_service_provider_registers_public_rest_routes(): void {
		( new CatalogServiceProvider() )->register();

		self::assertArrayHasKey( 'rest_api_init', $GLOBALS['connectlibrary_test_hooks'] );
		( new Routes() )->register_routes();

		self::assertArrayHasKey( 'connectlibrary/v1/books', $GLOBALS['connectlibrary_test_rest_routes'] );
		self::assertArrayHasKey( 'connectlibrary/v1/books/(?P<id>\d+)', $GLOBALS['connectlibrary_test_rest_routes'] );
		self::assertArrayHasKey( 'connectlibrary/v1/authors', $GLOBALS['connectlibrary_test_rest_routes'] );
		self::assertTrue( $GLOBALS['connectlibrary_test_rest_routes']['connectlibrary/v1/books']['permission_callback']() );
	}

	public function test_book_list_returns_visible_books_and_excludes_hidden_and_private_fields(): void {
		$visible = $this->create_book( 'Public Story', 'public-story', 'publish' );
		$this->seed_available_copy( $visible );
		$hidden  = $this->create_book( 'Hidden Story', 'hidden-story', 'publish' );
		$draft   = $this->create_book( 'Draft Story', 'draft-story', 'draft' );

		$GLOBALS['connectlibrary_test_post_meta'][ $hidden ][ Availability::META_VISIBILITY ] = 'hidden';
		$this->save_book_metadata(
			$visible,
			array(
				'isbn_13'       => '978-1-234567-89-7',
				'private_notes' => 'Do not expose',
			)
		);
		$this->save_book_metadata( $hidden, array( 'isbn_13' => '978-1-000000-00-0' ) );
		$this->save_book_metadata( $draft, array( 'isbn_13' => '978-1-999999-99-9' ) );

		$response = ( new BooksController() )->get_items( new WP_REST_Request( array() ) );
		$data     = $response->get_data();

		self::assertCount( 1, $data );
		self::assertSame( $visible, $data[0]['id'] );
		self::assertSame( 'available', $data[0]['availability_status'] );
		self::assertSame( 'Available', $data[0]['availability_label'] );
		self::assertArrayNotHasKey( 'private_notes', $data[0] );
		self::assertArrayNotHasKey( 'borrower', $data[0] );
	}

	public function test_book_detail_returns_visible_book_and_404_for_hidden_or_missing(): void {
		$visible = $this->create_book( 'Visible Detail', 'visible-detail', 'publish' );
		$hidden  = $this->create_book( 'Hidden Detail', 'hidden-detail', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $hidden ][ Availability::META_VISIBILITY ] = 'hidden';

		$response = ( new BooksController() )->get_item( new WP_REST_Request( array( 'id' => $visible ) ) );
		$error    = ( new BooksController() )->get_item( new WP_REST_Request( array( 'id' => $hidden ) ) );
		$missing  = ( new BooksController() )->get_item( new WP_REST_Request( array( 'id' => 999 ) ) );

		self::assertSame( $visible, $response->get_data()['id'] );
		self::assertSame( 404, $error->get_error_data()['status'] );
		self::assertSame( 404, $missing->get_error_data()['status'] );
	}

	public function test_search_by_title_and_isbn_and_validation(): void {
		$alpha = $this->create_book( 'Alpha Title', 'alpha-title', 'publish' );
		$beta  = $this->create_book( 'Beta Title', 'beta-title', 'publish' );
		$this->save_book_metadata( $alpha, array( 'isbn_13' => '978-0-000000-00-1' ) );
		$this->save_book_metadata( $beta, array( 'isbn_13' => '978-1-111111-11-1' ) );

		$title_response = ( new BooksController() )->get_items( new WP_REST_Request( array( 'search' => 'Alpha' ) ) );
		$isbn_response  = ( new BooksController() )->get_items( new WP_REST_Request( array( 'search' => '9781111111111' ) ) );
		$error          = ( new BooksController() )->get_items( new WP_REST_Request( array( 'sort' => 'private_notes' ) ) );

		self::assertSame( $alpha, $title_response->get_data()[0]['id'] );
		self::assertSame( $beta, $isbn_response->get_data()[0]['id'] );
		self::assertSame( 400, $error->get_error_data()['status'] );
	}

	public function test_book_list_filters_by_category_slug_id_and_label(): void {
		$alpha = $this->create_book( 'Alpha Category', 'alpha-category', 'publish' );
		$beta  = $this->create_book( 'Beta Category', 'beta-category', 'publish' );

		$this->assign_term( $alpha, BookTaxonomies::TAXONOMY_CATEGORY, 10, 'alpha-category', 'Alpha Category' );
		$this->assign_term( $beta, BookTaxonomies::TAXONOMY_CATEGORY, 20, 'beta-category', 'Beta Category' );

		$slug_response  = ( new BooksController() )->get_items( new WP_REST_Request( array( 'category' => 'alpha-category' ) ) );
		$id_response    = ( new BooksController() )->get_items( new WP_REST_Request( array( 'category' => '10' ) ) );
		$label_response = ( new BooksController() )->get_items( new WP_REST_Request( array( 'category' => 'Alpha Category' ) ) );
		$miss_response  = ( new BooksController() )->get_items( new WP_REST_Request( array( 'category' => 'gamma-category' ) ) );

		self::assertSame( array( $alpha ), array_column( $slug_response->get_data(), 'id' ) );
		self::assertSame( array( $alpha ), array_column( $id_response->get_data(), 'id' ) );
		self::assertSame( array( $alpha ), array_column( $label_response->get_data(), 'id' ) );
		self::assertSame( array(), array_column( $miss_response->get_data(), 'id' ) );
	}

	public function test_book_list_filters_by_tag_slug_id_and_label(): void {
		$alpha = $this->create_book( 'Alpha Tag', 'alpha-tag', 'publish' );
		$beta  = $this->create_book( 'Beta Tag', 'beta-tag', 'publish' );

		$this->assign_term( $alpha, BookTaxonomies::TAXONOMY_TAG, 30, 'alpha-tag', 'Alpha Tag' );
		$this->assign_term( $beta, BookTaxonomies::TAXONOMY_TAG, 40, 'beta-tag', 'Beta Tag' );

		$slug_response  = ( new BooksController() )->get_items( new WP_REST_Request( array( 'tag' => 'alpha-tag' ) ) );
		$id_response    = ( new BooksController() )->get_items( new WP_REST_Request( array( 'tag' => '30' ) ) );
		$label_response = ( new BooksController() )->get_items( new WP_REST_Request( array( 'tag' => 'Alpha Tag' ) ) );
		$miss_response  = ( new BooksController() )->get_items( new WP_REST_Request( array( 'tag' => 'gamma-tag' ) ) );

		self::assertSame( array( $alpha ), array_column( $slug_response->get_data(), 'id' ) );
		self::assertSame( array( $alpha ), array_column( $id_response->get_data(), 'id' ) );
		self::assertSame( array( $alpha ), array_column( $label_response->get_data(), 'id' ) );
		self::assertSame( array(), array_column( $miss_response->get_data(), 'id' ) );
	}

	public function test_public_author_and_series_lookup_payloads_use_custom_tables(): void {
		$relationships = new BookRelationshipsRepository();
		$author_id     = $relationships->create_author( 'C. S. Lewis' );
		$series_id     = $relationships->create_series( 'Narnia' );

		$book_id = $this->create_book( 'The Lion, the Witch and the Wardrobe', 'lion-witch-wardrobe', 'publish' );
		$relationships->save(
			$book_id,
			array(
				'author_ids'      => array( $author_id ),
				'series_id'       => $series_id,
				'series_position' => '1',
			)
		);

		$lookups = new \ConnectLibrary\Rest\LookupsController();

		$authors = $lookups->get_authors();
		$series  = $lookups->get_series();

		self::assertSame( $author_id, $authors[0]['id'] );
		self::assertSame( 'c-s-lewis', $authors[0]['slug'] );
		self::assertSame( 'C. S. Lewis', $authors[0]['label'] );
		self::assertSame( $series_id, $series[0]['id'] );
		self::assertSame( 'narnia', $series[0]['slug'] );
		self::assertSame( 'Narnia', $series[0]['label'] );
	}

	public function test_lookup_authors_and_series_exclude_hidden_and_draft_book_relationships(): void {
		$relationships = new BookRelationshipsRepository();

		// Public published book — relationships must appear in public lookups.
		$public_author_id = $relationships->create_author( 'J. R. R. Tolkien' );
		$public_series_id = $relationships->create_series( 'The Lord of the Rings' );
		$public_book      = $this->create_book( 'The Fellowship of the Ring', 'fellowship-of-the-ring', 'publish' );
		$relationships->save(
			$public_book,
			array(
				'author_ids'      => array( $public_author_id ),
				'series_id'       => $public_series_id,
				'series_position' => '1',
			)
		);

		// Hidden published book — relationships must NOT leak into public lookups.
		$hidden_author_id = $relationships->create_author( 'George Orwell' );
		$hidden_series_id = $relationships->create_series( 'Dystopia Collection' );
		$hidden_book      = $this->create_book( 'Nineteen Eighty-Four', 'nineteen-eighty-four', 'publish' );
		$GLOBALS['connectlibrary_test_post_meta'][ $hidden_book ][ Availability::META_VISIBILITY ] = 'hidden';
		$relationships->save(
			$hidden_book,
			array(
				'author_ids'      => array( $hidden_author_id ),
				'series_id'       => $hidden_series_id,
				'series_position' => '1',
			)
		);

		// Draft (non-published) book — relationships must NOT appear in public lookups.
		$draft_author_id = $relationships->create_author( 'Aldous Huxley' );
		$draft_series_id = $relationships->create_series( 'Speculative Classics' );
		$draft_book      = $this->create_book( 'Brave New World', 'brave-new-world', 'draft' );
		$relationships->save(
			$draft_book,
			array(
				'author_ids'      => array( $draft_author_id ),
				'series_id'       => $draft_series_id,
				'series_position' => '1',
			)
		);

		$lookups    = new \ConnectLibrary\Rest\LookupsController();
		$author_ids = array_column( $lookups->get_authors(), 'id' );
		$series_ids = array_column( $lookups->get_series(), 'id' );

		self::assertContains( $public_author_id, $author_ids );
		self::assertNotContains( $hidden_author_id, $author_ids );
		self::assertNotContains( $draft_author_id, $author_ids );

		self::assertContains( $public_series_id, $series_ids );
		self::assertNotContains( $hidden_series_id, $series_ids );
		self::assertNotContains( $draft_series_id, $series_ids );
	}

	/** Create a fake WordPress book post. */
	private function create_book( string $title, string $slug, string $status ): int {
		return wp_insert_post(
			array(
				'post_type'    => BookPostType::POST_TYPE,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => 'Public description',
			)
		);
	}

	/** Assign a fake taxonomy term to a book. */
	private function assign_term( int $book_id, string $taxonomy, int $term_id, string $slug, string $label ): void {
		$GLOBALS['connectlibrary_test_object_terms'][ $book_id ][ $taxonomy ][] = (object) array(
			'term_id' => $term_id,
			'slug'    => $slug,
			'name'    => $label,
		);
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
	 * Save metadata through the repository.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 */
	private function save_book_metadata( int $book_id, array $overrides ): void {
		( new BookMetadataRepository() )->save( $book_id, BookMetadata::sanitize( $overrides ) );
	}
}
