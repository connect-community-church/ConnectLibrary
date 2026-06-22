<?php
/**
 * Tests for ISBN metadata lookup services.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use ConnectLibrary\Admin\BookMetadataMetaboxes;
use ConnectLibrary\Catalog\BookMetadataRepository;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\BookRelationshipsRepository;
use ConnectLibrary\Catalog\Isbn;
use ConnectLibrary\Catalog\IsbnDuplicateDetector;
use ConnectLibrary\Catalog\IsbnMetadata;
use ConnectLibrary\Catalog\IsbnMetadataLookupService;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;

/**
 * Verifies Build 06 ISBN metadata lookup behavior.
 */
final class IsbnMetadataLookupTest extends TestCase {
	/** Reset fake provider/cache/storage state. */
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_http_responses'] = array();
		$GLOBALS['connectlibrary_test_http_requests']  = array();
		$GLOBALS['connectlibrary_test_transients']     = array();
		$GLOBALS['connectlibrary_test_db_tables']      = array();
		$GLOBALS['connectlibrary_test_post_meta']      = array();
		$GLOBALS['connectlibrary_test_post_objects']   = array();
		$GLOBALS['connectlibrary_test_json_error']      = null;
		$GLOBALS['connectlibrary_test_json_success']    = null;
		$GLOBALS['connectlibrary_test_current_user_id'] = 7;
		$GLOBALS['connectlibrary_test_current_user_can'] = array();
		$_POST                                        = array();
	}

	public function test_isbn_normalization_and_validation(): void {
		self::assertSame( '9780310337508', Isbn::normalize( ' 978-0-310-33750-8 ' ) );
		self::assertSame( 'isbn_13', Isbn::type( '978 0 310 33750 8' ) );
		self::assertSame( 'isbn_10', Isbn::type( '0-306-40615-2' ) );
		self::assertFalse( Isbn::is_valid( '978-0-310-33750-9' ) );
		self::assertSame( '9780306406157', Isbn::to_isbn13( '0306406152' ) );
		self::assertSame( '0306406152', Isbn::to_isbn10( '9780306406157' ) );
		$equivalents = Isbn::equivalents( '0306406152' );
		self::assertContains( '0306406152', $equivalents );
		self::assertContains( '9780306406157', $equivalents );
	}

	public function test_google_books_success_maps_expected_fields_and_cache(): void {
		$GLOBALS['connectlibrary_test_http_responses'] = array(
			'googleapis.com'   => self::http_response(
				array(
					'items' => array(
						array(
							'id'         => 'google-1',
							'volumeInfo' => array(
								'title'         => 'Mere Christianity',
								'subtitle'      => 'A Revised Edition',
								'authors'       => array( 'C. S. Lewis' ),
								'publisher'     => 'HarperOne',
								'publishedDate' => '2001-03-06',
								'description'   => 'Classic apologetics.',
								'pageCount'     => 227,
								'language'      => 'en',
								'categories'    => array( 'Religion' ),
								'averageRating' => 4.5,
								'ratingsCount'  => 100,
								'infoLink'      => 'https://books.google.example/google-1',
								'imageLinks'    => array( 'thumbnail' => 'https://covers.example/google.jpg' ),
							),
						),
					),
				)
			),
			'openlibrary.org' => self::http_response( array( 'title' => 'Mere Christianity' ) ),
		);

		$result = ( new IsbnMetadataLookupService() )->lookup( '978-0-310-33750-8' );

		self::assertSame( 'found', $result['status'] );
		self::assertSame( '9780310337508', $result['isbn'] );
		self::assertSame( 'Mere Christianity', $result['metadata']['title'] );
		self::assertSame( array( 'C. S. Lewis' ), $result['metadata']['authors'] );
		self::assertSame( 'HarperOne', $result['metadata']['publisher'] );
		self::assertSame( 227, $result['metadata']['page_count'] );
		self::assertSame( 'en', $result['metadata']['language'] );
		self::assertSame( array( 'Religion' ), $result['metadata']['categories'] );
		self::assertSame( 4.5, $result['metadata']['average_rating'] );
		self::assertSame( 100, $result['metadata']['ratings_count'] );
		self::assertSame( array( 'https://covers.example/google.jpg' ), $result['metadata']['cover_url_candidates'] );
		self::assertNotEmpty( $GLOBALS['connectlibrary_test_transients'] );
	}

	public function test_google_empty_uses_open_library_fallback(): void {
		$GLOBALS['connectlibrary_test_http_responses'] = array(
			'googleapis.com'   => self::http_response( array( 'items' => array() ) ),
			'openlibrary.org' => self::http_response(
				array(
					'key'             => '/books/OL7353617M',
					'title'           => 'The Lion, the Witch and the Wardrobe',
					'authors'         => array( array( 'name' => 'C. S. Lewis' ) ),
					'publishers'      => array( 'Geoffrey Bles' ),
					'publish_date'    => '1950',
					'number_of_pages' => 172,
					'languages'       => array( array( 'key' => '/languages/eng' ) ),
					'subjects'        => array( 'Fantasy' ),
					'covers'          => array( 12345 ),
				)
			),
		);

		$result = ( new IsbnMetadataLookupService() )->lookup( '9780064471046' );

		self::assertSame( 'found', $result['status'] );
		self::assertSame( 'The Lion, the Witch and the Wardrobe', $result['metadata']['title'] );
		self::assertSame( 'Open Library', $result['metadata']['source_provider'] );
		self::assertSame( 'eng', $result['metadata']['language'] );
		self::assertSame( array( 'https://covers.openlibrary.org/b/id/12345-L.jpg' ), $result['metadata']['cover_url_candidates'] );
	}

	public function test_fallback_message_when_google_no_metadata_open_library_succeeds(): void {
		$open_response = self::http_response(
			array(
				'key'     => '/books/OL7353617M',
				'title'   => 'The Lion, the Witch and the Wardrobe',
				'authors' => array( array( 'name' => 'C. S. Lewis' ) ),
			)
		);
		$GLOBALS['connectlibrary_test_http_responses'] = array(
			'googleapis.com'  => self::http_response( array( 'items' => array() ) ),
			'openlibrary.org' => $open_response,
		);

		$result = ( new IsbnMetadataLookupService() )->lookup( '9780064471046' );

		self::assertSame( 'found', $result['status'] );
		self::assertStringContainsString( 'did not return metadata', $result['message'] );
		self::assertStringContainsString( 'Open Library', $result['message'] );
		self::assertEmpty( $result['errors'] );
	}

	public function test_fallback_message_when_google_error_open_library_succeeds(): void {
		$open_response = self::http_response(
			array(
				'key'     => '/books/OL7353617M',
				'title'   => 'The Lion, the Witch and the Wardrobe',
				'authors' => array( array( 'name' => 'C. S. Lewis' ) ),
			)
		);
		$GLOBALS['connectlibrary_test_http_responses'] = array(
			'googleapis.com'  => new WP_Error( 'timeout', 'Request timed out.' ),
			'openlibrary.org' => $open_response,
		);

		$result = ( new IsbnMetadataLookupService() )->lookup( '9780064471046' );

		self::assertSame( 'found', $result['status'] );
		self::assertStringContainsString( 'unavailable', $result['message'] );
		self::assertStringContainsString( 'Open Library', $result['message'] );
		self::assertCount( 1, $result['errors'] );
	}

	public function test_invalid_isbn_does_not_call_external_provider(): void {
		$result = ( new IsbnMetadataLookupService() )->lookup( '9780310337509' );

		self::assertSame( 'invalid', $result['status'] );
		self::assertEmpty( $GLOBALS['connectlibrary_test_transients'] );
	}

	public function test_provider_errors_and_invalid_json_are_graceful(): void {
		$GLOBALS['connectlibrary_test_http_responses'] = array(
			'googleapis.com'   => new WP_Error( 'timeout', 'Request timed out.' ),
			'openlibrary.org' => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{bad json',
			),
		);

		$result = ( new IsbnMetadataLookupService() )->lookup( '9780310337508' );

		self::assertSame( 'provider_error', $result['status'] );
		self::assertStringContainsString( 'temporarily unavailable or rate limited', $result['message'] );
		self::assertCount( 2, $result['errors'] );
	}

	public function test_rate_limit_error_is_actionable_and_open_library_has_longer_timeout(): void {
		$GLOBALS['connectlibrary_test_http_responses'] = array(
			'googleapis.com'   => self::http_response( array( 'error' => 'too many requests' ), 429 ),
			'openlibrary.org' => new WP_Error( 'timeout', 'cURL error 28: Operation timed out.' ),
		);

		$result = ( new IsbnMetadataLookupService() )->lookup( '9780310337508' );

		self::assertSame( 'provider_error', $result['status'] );
		self::assertStringContainsString( 'HTTP 429 (rate limited)', $result['errors'][0] );
		self::assertCount( 3, $GLOBALS['connectlibrary_test_http_requests'] );
		self::assertSame( 10, $GLOBALS['connectlibrary_test_http_requests'][0]['args']['timeout'] );
		self::assertSame( 30, $GLOBALS['connectlibrary_test_http_requests'][1]['args']['timeout'] );
		self::assertSame( 30, $GLOBALS['connectlibrary_test_http_requests'][2]['args']['timeout'] );
		self::assertSame( 'application/json', $GLOBALS['connectlibrary_test_http_requests'][0]['args']['headers']['Accept'] );
	}

	public function test_explicit_admin_apply_does_not_overwrite_unselected_fields(): void {
		$book_id = wp_insert_post(
			array(
				'post_type'  => BookPostType::POST_TYPE,
				'post_title' => 'Manual title',
			)
		);
		$tables  = Schema::table_names( 'wp_test_' );
		$GLOBALS['connectlibrary_test_db_tables'][ $tables['book_metadata'] . ':rows' ] = array();

		update_post_meta(
			$book_id,
			'_connectlibrary_pending_isbn_lookup',
			array(
				'status'   => 'found',
				'metadata' => IsbnMetadata::from_google_books(
					array(
						'items' => array(
							array(
								'id'         => 'google-1',
								'volumeInfo' => array(
									'title'     => 'Fetched title',
									'subtitle'  => 'Fetched subtitle',
									'authors'   => array( 'Fetched Author' ),
									'publisher' => 'Fetched Publisher',
								),
							),
						),
					)
				),
			)
		);

		$_POST = array(
			'connectlibrary_book_metadata_nonce' => 'valid',
			'connectlibrary_apply_isbn_lookup'   => '1',
			'connectlibrary_apply_lookup_fields' => array( 'subtitle', 'authors' ),
			'connectlibrary_book_metadata'       => array(
				'subtitle'  => 'Manual subtitle',
				'publisher' => 'Manual Publisher',
			),
		);

		( new BookMetadataMetaboxes() )->save( $book_id, new WP_Post( $book_id, BookPostType::POST_TYPE ) );
		$loaded = ( new BookMetadataRepository() )->get( $book_id );
		$events = $GLOBALS['connectlibrary_test_db_tables'][ $tables['audit_events'] . ':rows' ] ?? array();

		self::assertSame( 'Manual title', get_post( $book_id )->post_title );
		self::assertSame( 'Fetched subtitle', $loaded['subtitle'] );
		self::assertSame( 'Manual Publisher', $loaded['publisher'] );
		self::assertSame( array( 1 ), ( new BookRelationshipsRepository() )->get_author_ids( $book_id ) );
		self::assertNotEmpty( $events );
		self::assertContains( 'isbn_apply_corrections', array_column( $events, 'action' ) );
	}

	public function test_duplicate_detector_finds_exact_isbn13_match(): void {
		$tables = Schema::table_names( 'wp_test_' );
		wp_insert_post( array( 'post_type' => BookPostType::POST_TYPE, 'post_title' => 'Existing Book' ) );
		$book_id = count( $GLOBALS['connectlibrary_test_post_objects'] );
		$GLOBALS['connectlibrary_test_db_tables'][ $tables['book_metadata'] . ':rows' ] = array(
			array(
				'book_post_id' => $book_id,
				'isbn_10'      => '0306406152',
				'isbn_13'      => '9780306406157',
			),
		);
		$GLOBALS['connectlibrary_test_db_tables'][ $tables['copies'] . ':rows' ] = array();

		$duplicates = ( new IsbnDuplicateDetector() )->detect( '9780306406157' );

		self::assertCount( 1, $duplicates );
		self::assertSame( $book_id, $duplicates[0]['book_id'] );
		self::assertSame( 'Existing Book', $duplicates[0]['title'] );
		self::assertStringContainsString( 'post.php?action=edit', $duplicates[0]['edit_link'] );
		self::assertSame( '9780306406157', $duplicates[0]['isbn_13'] );
		self::assertSame( '0306406152', $duplicates[0]['isbn_10'] );
		self::assertIsArray( $duplicates[0]['authors'] );
	}

	public function test_duplicate_detector_finds_equivalent_isbn_via_cross_form(): void {
		// Input is ISBN-10; book stored only with the ISBN-13 equivalent.
		$tables = Schema::table_names( 'wp_test_' );
		wp_insert_post( array( 'post_type' => BookPostType::POST_TYPE, 'post_title' => 'Equivalent Book' ) );
		$book_id = count( $GLOBALS['connectlibrary_test_post_objects'] );
		$GLOBALS['connectlibrary_test_db_tables'][ $tables['book_metadata'] . ':rows' ] = array(
			array(
				'book_post_id' => $book_id,
				'isbn_10'      => '',
				'isbn_13'      => '9780306406157',
			),
		);
		$GLOBALS['connectlibrary_test_db_tables'][ $tables['copies'] . ':rows' ] = array();

		$duplicates = ( new IsbnDuplicateDetector() )->detect( '0306406152' );

		self::assertCount( 1, $duplicates );
		self::assertSame( $book_id, $duplicates[0]['book_id'] );
	}

	public function test_duplicate_detector_excludes_current_post(): void {
		$tables = Schema::table_names( 'wp_test_' );
		wp_insert_post( array( 'post_type' => BookPostType::POST_TYPE, 'post_title' => 'Same Book Being Edited' ) );
		$book_id = count( $GLOBALS['connectlibrary_test_post_objects'] );
		$GLOBALS['connectlibrary_test_db_tables'][ $tables['book_metadata'] . ':rows' ] = array(
			array(
				'book_post_id' => $book_id,
				'isbn_10'      => '',
				'isbn_13'      => '9780306406157',
			),
		);
		$GLOBALS['connectlibrary_test_db_tables'][ $tables['copies'] . ':rows' ] = array();

		$duplicates = ( new IsbnDuplicateDetector() )->detect( '9780306406157', $book_id );

		self::assertCount( 0, $duplicates );
	}

	public function test_duplicate_detector_returns_empty_for_invalid_isbn(): void {
		$duplicates = ( new IsbnDuplicateDetector() )->detect( '0000000000' );

		self::assertSame( array(), $duplicates );
	}

	public function test_ajax_lookup_returns_duplicate_status_without_provider_call(): void {
		$tables = Schema::table_names( 'wp_test_' );
		wp_insert_post( array( 'post_type' => BookPostType::POST_TYPE, 'post_title' => 'Pre-existing Book' ) );
		$book_id = count( $GLOBALS['connectlibrary_test_post_objects'] );
		$GLOBALS['connectlibrary_test_db_tables'][ $tables['book_metadata'] . ':rows' ] = array(
			array(
				'book_post_id' => $book_id,
				'isbn_10'      => '0306406152',
				'isbn_13'      => '9780306406157',
			),
		);
		$GLOBALS['connectlibrary_test_db_tables'][ $tables['copies'] . ':rows' ]        = array();
		$GLOBALS['connectlibrary_test_json_success']                                    = null;

		$_POST = array(
			'nonce'   => 'connectlibrary_save_book_metadata',
			'isbn'    => '9780306406157',
			'post_id' => '0',
		);

		( new BookMetadataMetaboxes() )->ajax_lookup_isbn_metadata();

		$result = $GLOBALS['connectlibrary_test_json_success'];
		self::assertSame( 'duplicate', $result['status'] );
		self::assertCount( 1, $result['duplicates'] );
		self::assertSame( 'isbn_13', $result['isbn_type'] );
		self::assertStringContainsString( 'already in the catalog', $result['message'] );
		self::assertEmpty( $GLOBALS['connectlibrary_test_http_requests'], 'Provider must not be called when a duplicate is detected.' );

		$events = $GLOBALS['connectlibrary_test_db_tables'][ $tables['audit_events'] . ':rows' ] ?? array();
		self::assertCount( 1, $events );
		self::assertSame( 'isbn_duplicate_warning', $events[0]['action'] );
		self::assertSame( 'skipped', $events[0]['status'] );
		self::assertStringContainsString( '9780306406157', (string) $events[0]['context_json'] );
		self::assertStringContainsString( 'duplicate_count', (string) $events[0]['context_json'] );
		self::assertStringNotContainsString( 'borrower', (string) $events[0]['context_json'] );
	}

	public function test_ajax_lookup_invalid_isbn_returns_invalid_without_provider_call(): void {
		$_POST = array(
			'nonce'   => 'connectlibrary_save_book_metadata',
			'isbn'    => '9780310337509', // bad checksum
			'post_id' => '0',
		);
		$GLOBALS['connectlibrary_test_json_success'] = null;

		( new BookMetadataMetaboxes() )->ajax_lookup_isbn_metadata();

		$result = $GLOBALS['connectlibrary_test_json_success'];
		self::assertSame( 'invalid', $result['status'] );
		self::assertEmpty( $GLOBALS['connectlibrary_test_http_requests'], 'Provider must not be called for an invalid ISBN.' );

		$tables = Schema::table_names( 'wp_test_' );
		$events = $GLOBALS['connectlibrary_test_db_tables'][ $tables['audit_events'] . ':rows' ] ?? array();
		self::assertCount( 1, $events );
		self::assertSame( 'isbn_lookup', $events[0]['action'] );
		self::assertSame( 'failed', $events[0]['status'] );
	}

	public function test_ajax_lookup_denies_non_librarian_even_if_user_can_edit_posts(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => false,
			Capabilities::MANAGE_OPTIONS     => false,
			'edit_posts'                     => true,
		);
		$_POST = array(
			'nonce'   => 'connectlibrary_save_book_metadata',
			'isbn'    => '9780310337508',
			'post_id' => '0',
		);

		( new BookMetadataMetaboxes() )->ajax_lookup_isbn_metadata();

		self::assertNull( $GLOBALS['connectlibrary_test_json_success'] );
		self::assertSame( 'You do not have permission to look up ISBN metadata.', $GLOBALS['connectlibrary_test_json_error']['message'] );
		self::assertEmpty( $GLOBALS['connectlibrary_test_http_requests'], 'Provider must not be called for a denied non-librarian.' );
	}

	public function test_ajax_lookup_requires_post_specific_edit_authorization(): void {
		$book_id = wp_insert_post(
			array(
				'post_type'  => BookPostType::POST_TYPE,
				'post_title' => 'Locked Book',
			)
		);
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_CIRCULATION => true,
			Capabilities::MANAGE_OPTIONS     => false,
			'edit_post:' . $book_id          => false,
		);
		$_POST = array(
			'nonce'   => 'connectlibrary_save_book_metadata',
			'isbn'    => '9780310337508',
			'post_id' => (string) $book_id,
		);

		( new BookMetadataMetaboxes() )->ajax_lookup_isbn_metadata();

		self::assertNull( $GLOBALS['connectlibrary_test_json_success'] );
		self::assertSame( 'You do not have permission to look up ISBN metadata.', $GLOBALS['connectlibrary_test_json_error']['message'] );
		self::assertEmpty( $GLOBALS['connectlibrary_test_http_requests'], 'Provider must not be called without post edit permission.' );
	}

	public function test_ajax_lookup_writes_provider_result_audit_event(): void {
		$GLOBALS['connectlibrary_test_http_responses'] = array(
			'googleapis.com' => self::http_response(
				array(
					'items' => array(
						array(
							'id'         => 'google-1',
							'volumeInfo' => array(
								'title'   => 'Mere Christianity',
								'authors' => array( 'C. S. Lewis' ),
							),
						),
					),
				)
			),
		);
		$_POST = array(
			'nonce'   => 'connectlibrary_save_book_metadata',
			'isbn'    => '9780310337508',
			'post_id' => '0',
		);

		( new BookMetadataMetaboxes() )->ajax_lookup_isbn_metadata();

		$result = $GLOBALS['connectlibrary_test_json_success'];
		self::assertSame( 'found', $result['status'] );

		$tables = Schema::table_names( 'wp_test_' );
		$events = $GLOBALS['connectlibrary_test_db_tables'][ $tables['audit_events'] . ':rows' ] ?? array();
		self::assertCount( 1, $events );
		self::assertSame( 'isbn_lookup', $events[0]['action'] );
		self::assertSame( 'ok', $events[0]['status'] );
		self::assertStringContainsString( 'Google Books', (string) $events[0]['context_json'] );
		self::assertStringNotContainsString( 'nonce', (string) $events[0]['context_json'] );
	}

	/** Build a fake WordPress HTTP response. */
	private static function http_response( array $body, int $code = 200 ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $body ),
		);
	}
}
