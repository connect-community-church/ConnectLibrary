<?php
/**
 * Tests for Book metadata admin storage.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Admin\BookMetadataMetaboxes;
use ConnectLibrary\Catalog\BookMetadata;
use ConnectLibrary\Catalog\BookMetadataRepository;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\BookRelationshipsRepository;
use ConnectLibrary\Database\Schema;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Verifies Build 05 librarian metadata behavior.
 */
final class BookMetadataAdminTest extends TestCase {
	/** Reset fake database tables and post data. */
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_db_tables']  = array();
		$GLOBALS['connectlibrary_test_post_meta']  = array();
		$GLOBALS['connectlibrary_test_meta_boxes'] = array();
		$_POST                                     = array();
	}

	public function test_sanitize_cleans_text_and_enforces_controlled_values(): void {
		$fields = BookMetadata::sanitize(
			array(
				'isbn_13'          => '978-1-234-56789-7',
				'subtitle'         => '<b>Helpful</b> Book',
				'condition_status' => 'broken',
				'item_status'      => 'missing',
				'visibility'       => 'secret',
				'private_notes'    => '<script>alert(1)</script>Pastoral follow-up',
			)
		);

		self::assertSame( '9781234567897', $fields['isbn_13'] );
		self::assertSame( 'Helpful Book', $fields['subtitle'] );
		self::assertSame( 'good', $fields['condition_status'] );
		self::assertSame( 'active', $fields['item_status'] );
		self::assertSame( 'public', $fields['visibility'] );
		self::assertSame( 'alert(1)Pastoral follow-up', $fields['private_notes'] );
	}

	public function test_public_payload_excludes_private_librarian_notes(): void {
		$payload = BookMetadata::public_payload(
			array(
				'isbn_13'       => '9781234567897',
				'subtitle'      => 'Public subtitle',
				'church_notes'  => 'Shown later',
				'private_notes' => 'Do not show this to members',
			)
		);

		self::assertSame( '9781234567897', $payload['isbn_13'] );
		self::assertArrayNotHasKey( 'private_notes', $payload );
	}

	public function test_repository_saves_and_reloads_metadata_and_relationships(): void {
		$metadata      = new BookMetadataRepository();
		$relationships = new BookRelationshipsRepository();
		$book_id       = 101;

		$fields = BookMetadata::sanitize(
			array(
				'isbn_10'                 => '0-123456-47-9',
				'isbn_13'                 => '978-0-123456-47-2',
				'subtitle'                => 'Foundations <em>Guide</em>',
				'publisher'               => 'Church Press',
				'published_date'          => '2024',
				'language'                => 'English',
				'page_count'              => '250',
				'church_notes'            => 'Good for small groups.',
				'private_notes'           => 'Keep on high shelf.',
				'visibility'              => 'hidden',
				'condition_status'        => 'fair',
				'item_status'             => 'damaged',
				'room'                    => 'Library',
				'shelf'                   => 'A3',
				'section'                 => 'Discipleship',
				'recommended'             => '1',
				'metadata_source'         => 'open_library',
				'source_provider'         => 'Open Library',
				'source_record_id'        => 'OL123M',
				'source_record_link'      => 'https://openlibrary.org/books/OL123M',
				'last_metadata_refresh'   => '2026-06-19',
				'new_author_display_name' => 'C. S. Lewis',
				'new_series_name'         => 'Library Basics',
				'series_position'         => '1',
			)
		);

		$metadata->save( $book_id, $fields );
		$relationships->save( $book_id, $fields );

		$loaded = $metadata->get( $book_id );
		$series = $relationships->get_series_selection( $book_id );
		$tables = Schema::table_names( 'wp_test_' );

		self::assertSame( '0123456479', $loaded['isbn_10'] );
		self::assertSame( 'Foundations Guide', $loaded['subtitle'] );
		self::assertSame( 'hidden', $loaded['visibility'] );
		self::assertSame( 'fair', $loaded['condition_status'] );
		self::assertSame( 'damaged', $loaded['item_status'] );
		self::assertSame( 'Keep on high shelf.', $loaded['private_notes'] );
		self::assertSame( 'https://openlibrary.org/books/OL123M', $loaded['source_record_link'] );
		self::assertSame( array( 1 ), $relationships->get_author_ids( $book_id ) );
		self::assertSame( 1, $series['series_id'] );
		self::assertSame( '1', $series['series_position'] );
		self::assertNotEmpty( $GLOBALS['connectlibrary_test_db_tables'][ $tables['book_authors'] . ':rows' ] );
		self::assertNotEmpty( $GLOBALS['connectlibrary_test_db_tables'][ $tables['book_series'] . ':rows' ] );
	}

	public function test_repository_clears_import_source_when_source_fields_are_blank(): void {
		$metadata = new BookMetadataRepository();
		$book_id  = 303;

		$metadata->save(
			$book_id,
			BookMetadata::sanitize(
				array(
					'isbn_13'               => '978-0-123456-47-2',
					'private_notes'         => 'Keep provenance decision private.',
					'metadata_source'       => 'open_library',
					'source_provider'       => 'Open Library',
					'source_record_id'      => 'OL123M',
					'source_record_link'    => 'https://openlibrary.org/books/OL123M',
					'last_metadata_refresh' => '2026-06-19',
				)
			)
		);

		$initial = $metadata->get( $book_id );

		self::assertSame( 'Open Library', $initial['source_provider'] );
		self::assertSame( 'OL123M', $initial['source_record_id'] );

		$metadata->save(
			$book_id,
			BookMetadata::sanitize(
				array(
					'isbn_13'               => '978-0-123456-47-2',
					'private_notes'         => 'Keep provenance decision private.',
					'metadata_source'       => 'manual',
					'source_provider'       => '',
					'source_record_id'      => '',
					'source_record_link'    => '',
					'last_metadata_refresh' => '',
				)
			)
		);

		$loaded = $metadata->get( $book_id );
		$tables = Schema::table_names( 'wp_test_' );

		self::assertSame( '', $loaded['source_provider'] );
		self::assertSame( '', $loaded['source_record_id'] );
		self::assertSame( '', $loaded['source_record_link'] );
		self::assertSame( '', $loaded['last_metadata_refresh'] );
		self::assertSame( 'Keep provenance decision private.', $loaded['private_notes'] );
		self::assertSame( array(), $GLOBALS['connectlibrary_test_db_tables'][ $tables['import_sources'] . ':rows' ] ?? array() );
	}

	public function test_metaboxes_register_and_save_with_nonce_and_capability_path(): void {
		$metaboxes = new BookMetadataMetaboxes();
		$metaboxes->add_metaboxes();

		self::assertArrayHasKey( 'connectlibrary_book_catalog_details', $GLOBALS['connectlibrary_test_meta_boxes'] );
		self::assertArrayHasKey( 'connectlibrary_book_authors_series', $GLOBALS['connectlibrary_test_meta_boxes'] );
		self::assertArrayHasKey( 'connectlibrary_book_private_notes', $GLOBALS['connectlibrary_test_meta_boxes'] );

		$_POST = array(
			'connectlibrary_book_metadata_nonce' => 'valid',
			'connectlibrary_book_metadata'       => array(
				'isbn_13'          => '978-1-111111-11-1',
				'subtitle'         => 'Saved from admin',
				'condition_status' => 'new',
				'item_status'      => 'active',
				'visibility'       => 'public',
				'private_notes'    => 'Private reload check',
			),
		);

		$metaboxes->save( 202, new WP_Post( 202, BookPostType::POST_TYPE ) );
		$loaded = ( new BookMetadataRepository() )->get( 202 );

		self::assertSame( '9781111111111', $loaded['isbn_13'] );
		self::assertSame( 'Saved from admin', $loaded['subtitle'] );
		self::assertSame( 'Private reload check', $loaded['private_notes'] );
	}
}
