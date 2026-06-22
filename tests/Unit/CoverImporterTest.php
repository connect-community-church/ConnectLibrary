<?php
/**
 * Tests for importing ISBN metadata cover candidates into Media Library.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use ConnectLibrary\Admin\BookMetadataMetaboxes;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\CoverImporter;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Rest\PublicBookSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Build 07 cover import behavior.
 */
final class CoverImporterTest extends TestCase {
	/** Reset fake WordPress state. */
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_http_responses'] = array();
		$GLOBALS['connectlibrary_test_post_meta']      = array();
		$GLOBALS['connectlibrary_test_post_objects']   = array();
		$GLOBALS['connectlibrary_test_posts']          = array();
		$GLOBALS['connectlibrary_test_attachments']    = array();
		$GLOBALS['connectlibrary_test_uploads']        = array();
		$GLOBALS['connectlibrary_test_db_tables']      = array();
		$_POST                                       = array();
	}

	public function test_valid_cover_candidate_imports_attachment_and_sets_featured_image(): void {
		$book_id = $this->create_book( 'Mere Christianity' );
		$GLOBALS['connectlibrary_test_http_responses']['covers.example/mere.jpg'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'image/jpeg' ),
			'body'     => 'fake-jpeg-bytes',
		);

		$result = ( new CoverImporter() )->import_for_book(
			$book_id,
			array(
				'title'                => 'Mere Christianity',
				'isbn'                 => '9780310337508',
				'source_provider'      => 'Google Books',
				'cover_url_candidates' => array( 'https://covers.example/mere.jpg' ),
			)
		);

		self::assertSame( 'imported', $result['status'] );
		self::assertSame( 1001, $result['attachment_id'] );
		self::assertSame( 1001, get_post_thumbnail_id( $book_id ) );
		self::assertSame( 'Google Books', get_post_meta( $book_id, '_connectlibrary_cover_source_provider', true ) );
		self::assertSame( 'https://covers.example/mere.jpg', get_post_meta( $book_id, '_connectlibrary_cover_source_url', true ) );
		self::assertSame( 'imported', get_post_meta( $book_id, '_connectlibrary_cover_import_status', true ) );
		self::assertSame( 'Cover of Mere Christianity', get_post_meta( 1001, '_wp_attachment_image_alt', true ) );
		self::assertStringContainsString( 'connectlibrary-cover-9780310337508-mere-christianity.jpg', $GLOBALS['connectlibrary_test_attachments'][1001]['file']['name'] );
	}

	public function test_no_cover_candidate_records_not_found_without_creating_attachment(): void {
		$book_id = $this->create_book( 'No Cover Book' );

		$result = ( new CoverImporter() )->import_for_book( $book_id, array( 'cover_url_candidates' => array() ) );

		self::assertSame( 'not_found', $result['status'] );
		self::assertSame( 0, $result['attachment_id'] );
		self::assertSame( 'not_found', get_post_meta( $book_id, '_connectlibrary_cover_import_status', true ) );
		self::assertEmpty( $GLOBALS['connectlibrary_test_attachments'] );
	}

	public function test_failed_download_records_safe_error_and_keeps_book_without_cover(): void {
		$book_id = $this->create_book( 'Broken Cover' );
		$GLOBALS['connectlibrary_test_http_responses']['covers.example/broken.jpg'] = array(
			'response' => array( 'code' => 500 ),
			'headers'  => array( 'content-type' => 'image/jpeg' ),
			'body'     => 'server error body that should not be stored as the admin error',
		);

		$result = ( new CoverImporter() )->import_for_book( $book_id, array( 'cover_url_candidates' => array( 'https://covers.example/broken.jpg' ) ) );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'Cover download returned HTTP 500.', $result['error'] );
		self::assertSame( 'failed', get_post_meta( $book_id, '_connectlibrary_cover_import_status', true ) );
		self::assertSame( 'Cover download returned HTTP 500.', get_post_meta( $book_id, '_connectlibrary_cover_import_error', true ) );
		self::assertFalse( get_post_thumbnail_id( $book_id ) );
	}

	public function test_unsafe_url_is_rejected_before_http_request(): void {
		$book_id = $this->create_book( 'Unsafe Cover' );

		$result = ( new CoverImporter() )->import_for_book( $book_id, array( 'cover_url_candidates' => array( 'http://127.0.0.1/cover.jpg' ) ) );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'Cover URL host is not allowed.', $result['error'] );
		self::assertEmpty( $GLOBALS['connectlibrary_test_uploads'] );
	}

	public function test_existing_cover_is_preserved_unless_replace_is_requested(): void {
		$book_id = $this->create_book( 'Existing Cover' );
		set_post_thumbnail( $book_id, 777 );
		$GLOBALS['connectlibrary_test_http_responses']['covers.example/new.jpg'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'image/png' ),
			'body'     => 'fake-png-bytes',
		);

		$skipped = ( new CoverImporter() )->import_for_book( $book_id, array( 'cover_url_candidates' => array( 'https://covers.example/new.jpg' ) ) );
		$replaced = ( new CoverImporter() )->import_for_book( $book_id, array( 'title' => 'Existing Cover', 'cover_url_candidates' => array( 'https://covers.example/new.jpg' ) ), true );

		self::assertSame( 'skipped_existing', $skipped['status'] );
		self::assertSame( 777, $skipped['attachment_id'] );
		self::assertSame( 'imported', $replaced['status'] );
		self::assertSame( 1001, get_post_thumbnail_id( $book_id ) );
	}

	public function test_admin_apply_selected_cover_imports_and_replace_flag_overwrites_existing_cover(): void {
		$book_id = $this->create_book( 'Manual Cover Title' );
		$tables  = Schema::table_names( 'wp_test_' );
		$GLOBALS['connectlibrary_test_db_tables'][ $tables['book_metadata'] . ':rows' ] = array();
		$GLOBALS['connectlibrary_test_http_responses']['covers.example/admin.jpg'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'image/jpeg' ),
			'body'     => 'fake-jpeg-bytes',
		);
		update_post_meta(
			$book_id,
			'_connectlibrary_pending_isbn_lookup',
			array(
				'status'   => 'found',
				'metadata' => array(
					'title'                => 'Fetched Cover Title',
					'isbn_13'              => '9780310337508',
					'source_provider'      => 'Google Books',
					'cover_url_candidates' => array( 'https://covers.example/admin.jpg' ),
				),
			)
		);

		$_POST = array(
			'connectlibrary_book_metadata_nonce' => 'valid',
			'connectlibrary_apply_isbn_lookup'   => '1',
			'connectlibrary_apply_lookup_fields' => array( 'cover' ),
			'connectlibrary_book_metadata'       => array( 'isbn_13' => '978-0-310-33750-8' ),
		);

		( new BookMetadataMetaboxes() )->save( $book_id, new \WP_Post( $book_id, BookPostType::POST_TYPE ) );

		self::assertSame( 1001, get_post_thumbnail_id( $book_id ) );
		self::assertSame( 'imported', get_post_meta( $book_id, '_connectlibrary_cover_import_status', true ) );
	}

	public function test_rest_payload_uses_local_attachment_cover_url(): void {
		$book_id = $this->create_book( 'REST Cover' );
		set_post_thumbnail( $book_id, 1001 );
		$GLOBALS['connectlibrary_test_attachments'][1001] = array(
			'url'   => 'https://example.test/wp-content/uploads/connectlibrary-cover-rest-cover.jpg',
			'alt'   => 'Cover of REST Cover',
			'sizes' => array(
				'thumbnail' => 'https://example.test/wp-content/uploads/connectlibrary-cover-rest-cover-150x150.jpg',
			),
		);

		$payload = ( new PublicBookSerializer() )->serialize( get_post( $book_id ) );

		self::assertSame( 1001, $payload['cover']['id'] );
		self::assertSame( 'https://example.test/wp-content/uploads/connectlibrary-cover-rest-cover.jpg', $payload['cover']['url'] );
		self::assertSame( array( 'thumbnail' => 'https://example.test/wp-content/uploads/connectlibrary-cover-rest-cover-150x150.jpg' ), $payload['cover']['sizes'] );
		self::assertStringNotContainsString( 'google', $payload['cover']['url'] );
	}

	/** Create a fake Book post. */
	private function create_book( string $title ): int {
		return wp_insert_post(
			array(
				'post_type'  => BookPostType::POST_TYPE,
				'post_title' => $title,
			)
		);
	}
}
