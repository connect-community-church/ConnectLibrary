<?php
/**
 * Tests for Phase 1 public availability model.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Catalog\BookAvailability;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\CatalogServiceProvider;
use ConnectLibrary\Support\Statuses;
use PHPUnit\Framework\TestCase;
use WP_Query;

/**
 * Verifies privacy-safe public catalog availability behavior.
 */
final class AvailabilityTest extends TestCase {
	/**
	 * Reset mutable WordPress stubs between tests.
	 */
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_hooks']           = array();
		$GLOBALS['connectlibrary_test_post_meta']       = array();
		$GLOBALS['connectlibrary_test_registered_meta'] = array();
		$GLOBALS['connectlibrary_test_rest_fields']     = array();
		$GLOBALS['connectlibrary_test_current_user_can'] = array();
		$GLOBALS['connectlibrary_test_db_tables']       = array();
	}

	public function test_fixed_public_availability_values_are_centralized(): void {
		self::assertSame(
			array( 'available', 'reserved', 'checked_out', 'waitlist_available', 'unavailable' ),
			Statuses::availability_statuses()
		);
		self::assertSame( array( 'public', 'hidden' ), Statuses::visibility_statuses() );
		self::assertSame( 'Unavailable', Availability::response_for_status( 'bogus' )['label'] );
	}

	public function test_default_visible_book_returns_available_response(): void {
		$response = Availability::for_book( 101 );

		self::assertSame( 'available', $response['status'] );
		self::assertSame( 'Available', $response['label'] );
		self::assertSame( 'reserve', $response['request_action'] );
	}

	public function test_checked_out_book_response_is_privacy_safe(): void {
		$GLOBALS['connectlibrary_test_post_meta'][102][ Availability::META_STATUS ] = 'checked_out';

		$response = Availability::for_book( 102 );

		self::assertSame(
			array(
				'status'         => 'checked_out',
				'label'          => 'Checked Out',
				'request_action' => 'waitlist',
			),
			$response
		);
		self::assertArrayNotHasKey( 'borrower', $response );
		self::assertArrayNotHasKey( 'due_date', $response );
		self::assertArrayNotHasKey( 'waitlist_position', $response );
	}

	public function test_hidden_book_resolves_to_hidden_and_public_clause_excludes_hidden_meta(): void {
		$GLOBALS['connectlibrary_test_post_meta'][103][ Availability::META_VISIBILITY ] = 'hidden';

		self::assertFalse( Availability::is_public( 103 ) );
		self::assertSame( 'hidden', Availability::for_book( 103 )['status'] );

		$clause = ( new BookAvailability() )->public_visibility_clause();
		self::assertSame( 'OR', $clause['relation'] );
		self::assertSame( Availability::META_VISIBILITY, $clause[1]['key'] );
		self::assertSame( 'hidden', $clause[1]['value'] );
		self::assertSame( '!=', $clause[1]['compare'] );
	}

	public function test_invalid_stored_status_is_normalized_to_unavailable(): void {
		$GLOBALS['connectlibrary_test_post_meta'][104][ Availability::META_STATUS ] = 'borrower_private_note';

		$response = Availability::for_book( 104 );

		self::assertSame( 'unavailable', $response['status'] );
		self::assertSame( 'Unavailable', $response['label'] );
		self::assertSame( 'contact_librarian', $response['request_action'] );
	}

	public function test_admin_meta_box_uses_available_for_missing_status_meta(): void {
		$post = (object) array( 'ID' => 108 );

		ob_start();
		( new BookAvailability() )->render_meta_box( $post );
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'value="available" selected="selected"', $output );
		self::assertStringNotContainsString( 'value="unavailable" selected="selected"', $output );
	}

	public function test_admin_meta_box_normalizes_invalid_non_empty_status_to_unavailable(): void {
		$GLOBALS['connectlibrary_test_post_meta'][109][ Availability::META_STATUS ] = 'borrower_private_note';
		$post = (object) array( 'ID' => 109 );

		ob_start();
		( new BookAvailability() )->render_meta_box( $post );
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'value="unavailable" selected="selected"', $output );
	}

	public function test_registers_private_storage_and_safe_public_rest_field(): void {
		$availability = new BookAvailability();
		$availability->register_meta();
		$availability->register_rest_fields();

		$registered_meta = $GLOBALS['connectlibrary_test_registered_meta'][ BookPostType::POST_TYPE ];
		self::assertFalse( $registered_meta[ Availability::META_STATUS ]['show_in_rest'] );
		self::assertSame( 'available', $registered_meta[ Availability::META_STATUS ]['default'] );
		self::assertFalse( $registered_meta[ Availability::META_VISIBILITY ]['show_in_rest'] );

		$field = $GLOBALS['connectlibrary_test_rest_fields'][ BookPostType::POST_TYPE ]['availability'];
		$GLOBALS['connectlibrary_test_post_meta'][105][ Availability::META_STATUS ] = 'reserved';
		$response = $field['get_callback']( array( 'id' => 105 ) );

		self::assertSame( 'reserved', $response['status'] );
		self::assertSame( 'Reserved', $response['label'] );
		self::assertSame( 'waitlist', $response['request_action'] );
		self::assertSame( array( 'view', 'embed' ), $field['schema']['context'] );
	}

	public function test_public_query_filters_by_availability_and_excludes_hidden_titles(): void {
		$query = new WP_Query(
			array(
				'post_type'                   => BookPostType::POST_TYPE,
				'connectlibrary_availability' => 'available,checked_out,bad_value',
			)
		);

		( new BookAvailability() )->filter_public_queries( $query );

		$meta_query = $query->get( 'meta_query' );
		self::assertCount( 2, $meta_query );
		self::assertSame( Availability::META_VISIBILITY, $meta_query[0][1]['key'] );
		self::assertSame( 'OR', $meta_query[1]['relation'] );
		self::assertSame( Availability::META_STATUS, $meta_query[1][0]['key'] );
		self::assertSame( 'NOT EXISTS', $meta_query[1][0]['compare'] );
		self::assertSame( Availability::META_STATUS, $meta_query[1][1]['key'] );
		self::assertSame( array( 'available', 'checked_out' ), $meta_query[1][1]['value'] );
		self::assertSame( 'IN', $meta_query[1][1]['compare'] );
	}

	public function test_non_available_public_query_filter_requires_saved_status_meta(): void {
		$query = new WP_Query(
			array(
				'post_type'                   => BookPostType::POST_TYPE,
				'connectlibrary_availability' => 'checked_out',
			)
		);

		( new BookAvailability() )->filter_public_queries( $query );

		$meta_query = $query->get( 'meta_query' );
		self::assertSame( Availability::META_STATUS, $meta_query[1]['key'] );
		self::assertSame( array( 'checked_out' ), $meta_query[1]['value'] );
		self::assertSame( 'IN', $meta_query[1]['compare'] );
	}

	public function test_hidden_book_is_blocked_from_public_core_rest_item_and_collection_queries(): void {
		$availability = new BookAvailability();
		$GLOBALS['connectlibrary_test_post_meta'][106][ Availability::META_VISIBILITY ] = 'hidden';
		$GLOBALS['connectlibrary_test_current_user_can']['edit_posts']                  = false;
		$GLOBALS['connectlibrary_test_current_user_can']['edit_post:106']               = false;

		$response = $availability->block_hidden_rest_item_request( null, array(), new \WP_REST_Request( array(), '/wp/v2/connectlibrary-books/106' ) );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'connectlibrary_book_not_found', $response->get_error_code() );
		self::assertSame( array( 'status' => 404 ), $response->get_error_data() );

		$args = $availability->filter_public_rest_query( array(), new \WP_REST_Request() );
		self::assertSame( Availability::META_VISIBILITY, $args['meta_query'][0][1]['key'] );
		self::assertSame( 'hidden', $args['meta_query'][0][1]['value'] );
	}

	public function test_editors_can_access_hidden_books_in_core_rest(): void {
		$availability = new BookAvailability();
		$GLOBALS['connectlibrary_test_post_meta'][107][ Availability::META_VISIBILITY ] = 'hidden';

		self::assertNull( $availability->block_hidden_rest_item_request( null, array(), new \WP_REST_Request( array(), '/wp/v2/connectlibrary-books/107' ) ) );
	}

	public function test_availability_sort_uses_specified_public_rank_order(): void {
		self::assertLessThan( Availability::sort_rank( 'checked_out' ), Availability::sort_rank( 'available' ) );
		self::assertLessThan( Availability::sort_rank( 'reserved' ), Availability::sort_rank( 'waitlist_available' ) );

		$query = new WP_Query(
			array(
				'post_type' => BookPostType::POST_TYPE,
				'orderby'   => 'availability',
			)
		);
		$book_availability = new BookAvailability();
		$book_availability->filter_public_queries( $query );
		$clauses = $book_availability->sort_public_queries_by_availability(
			array(
				'join'    => '',
				'orderby' => 'wp_test_posts.post_title ASC',
			),
			$query
		);

		self::assertTrue( $query->get( 'connectlibrary_availability_sort' ) );
		self::assertStringContainsString( 'WHEN \'available\' THEN 10', $clauses['orderby'] );
		self::assertStringContainsString( 'WHEN \'waitlist_available\' THEN 20', $clauses['orderby'] );
		self::assertStringContainsString( 'WHEN \'checked_out\' THEN 40', $clauses['orderby'] );
		self::assertStringContainsString( 'connectlibrary_availability_meta', $clauses['join'] );
	}

	public function test_catalog_service_provider_registers_availability_hooks(): void {
		( new CatalogServiceProvider() )->register();

		self::assertArrayHasKey( 'rest_api_init', $GLOBALS['connectlibrary_test_hooks'] );
		self::assertArrayHasKey( 'pre_get_posts', $GLOBALS['connectlibrary_test_hooks'] );
		self::assertArrayHasKey( 'add_meta_boxes_' . BookPostType::POST_TYPE, $GLOBALS['connectlibrary_test_hooks'] );
		self::assertArrayHasKey( 'save_post_' . BookPostType::POST_TYPE, $GLOBALS['connectlibrary_test_hooks'] );
	}

	// -------------------------------------------------------------------------
	// Phase 2 availability rollup from copy circulation_status (acceptance #8)
	// -------------------------------------------------------------------------

	public function test_rollup_empty_copies_falls_back_to_phase1_meta(): void {
		// No copies in DB → meta fallback.
		$GLOBALS['connectlibrary_test_post_meta'][200][ Availability::META_STATUS ] = 'checked_out';

		$response = Availability::for_book( 200 );

		self::assertSame( 'checked_out', $response['status'] );
		self::assertSame( 'Checked Out', $response['label'] );
	}

	public function test_rollup_single_available_copy_returns_available(): void {
		$this->seed_copy( 201, 'available' );

		$response = Availability::for_book( 201 );

		self::assertSame( 'available', $response['status'] );
		self::assertSame( 'reserve', $response['request_action'] );
	}

	public function test_rollup_checked_out_copy_returns_checked_out(): void {
		$this->seed_copy( 202, 'checked_out' );

		$response = Availability::for_book( 202 );

		self::assertSame( 'checked_out', $response['status'] );
		self::assertSame( 'waitlist', $response['request_action'] );
	}

	public function test_rollup_on_hold_copy_returns_waitlist_available(): void {
		$this->seed_copy( 203, 'on_hold' );

		$response = Availability::for_book( 203 );

		self::assertSame( 'waitlist_available', $response['status'] );
		self::assertSame( 'waitlist', $response['request_action'] );
	}

	public function test_rollup_all_damaged_copies_returns_unavailable(): void {
		$this->seed_copy( 204, 'damaged' );

		$response = Availability::for_book( 204 );

		self::assertSame( 'unavailable', $response['status'] );
		self::assertSame( 'contact_librarian', $response['request_action'] );
	}

	public function test_rollup_all_retired_copies_returns_unavailable(): void {
		$this->seed_copy( 205, 'retired' );

		$response = Availability::for_book( 205 );

		self::assertSame( 'unavailable', $response['status'] );
	}

	public function test_rollup_multiple_copies_any_available_wins(): void {
		// One available, one checked_out → overall available.
		$this->seed_copy( 206, 'available' );
		$this->seed_copy( 206, 'checked_out' );

		$response = Availability::for_book( 206 );

		self::assertSame( 'available', $response['status'] );
	}

	public function test_rollup_reserved_when_active_holds_cover_all_available_copies(): void {
		$tables     = \ConnectLibrary\Database\Schema::table_names();
		$copies_key = $tables['copies'] . ':rows';
		$res_key    = $tables['reservations'] . ':rows';
		$now        = '2026-06-19 12:00:00';

		$GLOBALS['connectlibrary_test_db_tables'][ $copies_key ] = array(
			array( 'id' => 1, 'book_post_id' => 207, 'circulation_status' => 'available', 'item_status' => 'active', 'visibility' => 'public', 'created_at' => $now, 'updated_at' => $now ),
		);
		$GLOBALS['connectlibrary_test_db_tables'][ $res_key ] = array(
			array( 'id' => 1, 'book_post_id' => 207, 'status' => 'active_hold', 'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now ),
		);

		$response = Availability::for_book( 207 );

		self::assertSame( 'reserved', $response['status'] );
		self::assertSame( 'waitlist', $response['request_action'] );
	}

	public function test_rollup_hidden_visibility_overrides_copies(): void {
		$this->seed_copy( 208, 'available' );
		$GLOBALS['connectlibrary_test_post_meta'][208][ Availability::META_VISIBILITY ] = 'hidden';

		$response = Availability::for_book( 208 );

		self::assertSame( 'hidden', $response['status'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Seed a copy row into the fake DB for the given post_id / circulation_status.
	 */
	private function seed_copy( int $post_id, string $circulation_status ): void {
		$tables     = \ConnectLibrary\Database\Schema::table_names();
		$copies_key = $tables['copies'] . ':rows';
		$now        = '2026-06-19 12:00:00';

		$GLOBALS['connectlibrary_test_db_tables'][ $copies_key ][] = array(
			'id'                 => count( $GLOBALS['connectlibrary_test_db_tables'][ $copies_key ] ?? array() ) + 1,
			'book_post_id'       => $post_id,
			'circulation_status' => $circulation_status,
			'item_status'        => 'active',
			'visibility'         => 'public',
			'created_at'         => $now,
			'updated_at'         => $now,
		);
	}
}
