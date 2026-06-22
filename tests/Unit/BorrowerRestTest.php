<?php
/**
 * Tests for borrower REST endpoints.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Rest\BorrowersController;
use ConnectLibrary\Rest\Routes;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Verifies Phase 2 protected borrower REST foundations.
 */
final class BorrowerRestTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_db_tables']        = array();
		$GLOBALS['connectlibrary_test_rest_routes']      = array();
		$GLOBALS['connectlibrary_test_current_user_id']  = 42;
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => true,
			Capabilities::MANAGE_OPTIONS   => false,
		);
		$GLOBALS['connectlibrary_test_users']            = array(
			77 => (object) array(
				'ID'         => 77,
				'user_login' => 'existing-reader',
				'user_email' => 'existing-reader@example.test',
			),
			88 => (object) array(
				'ID'         => 88,
				'user_login' => 'second-reader',
				'user_email' => 'second-reader@example.test',
			),
		);
	}

	public function test_routes_registers_protected_borrower_routes(): void {
		( new Routes() )->register_routes();

		self::assertArrayHasKey( 'connectlibrary/v1/borrowers', $GLOBALS['connectlibrary_test_rest_routes'] );
		self::assertArrayHasKey( 'connectlibrary/v1/borrowers/(?P<id>\d+)', $GLOBALS['connectlibrary_test_rest_routes'] );
		self::assertArrayHasKey( 'connectlibrary/v1/borrowers/(?P<id>\d+)/export', $GLOBALS['connectlibrary_test_rest_routes'] );
		self::assertArrayHasKey( 'connectlibrary/v1/borrowers/(?P<id>\d+)/anonymize', $GLOBALS['connectlibrary_test_rest_routes'] );
		self::assertArrayHasKey( 'connectlibrary/v1/borrowers/(?P<id>\d+)/status', $GLOBALS['connectlibrary_test_rest_routes'] );
	}

	public function test_permission_callback_denies_without_borrower_capability(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => false,
			Capabilities::MANAGE_OPTIONS   => false,
		);

		$route = ( new BorrowersController() )->permission_check();

		self::assertSame( 403, $route->get_error_data()['status'] );
	}

	public function test_create_list_get_and_export_are_admin_only_and_token_free(): void {
		$controller = new BorrowersController();
		$created    = $controller->create_item(
			new WP_REST_Request(
				array(
					'borrower_type' => 'manual',
					'display_name'  => 'REST Reader',
					'email'         => 'rest@example.test',
				)
			)
		)->get_data();
		$list       = $controller->get_items( new WP_REST_Request( array() ) )->get_data();
		$get        = $controller->get_item( new WP_REST_Request( array( 'id' => $created['id'] ) ) )->get_data();
		$export     = $controller->export_item( new WP_REST_Request( array( 'id' => $created['id'] ) ) )->get_data();

		self::assertSame( 'REST Reader', $created['display_name'] );
		self::assertCount( 1, $list );
		self::assertSame( 'rest@example.test', $get['email'] );
		self::assertSame( 'REST Reader', $export['borrower']['display_name'] );
		self::assertArrayNotHasKey( 'auth_token', $created );
		self::assertArrayNotHasKey( 'card_token', $created );
		self::assertArrayNotHasKey( 'guest_token', $created );
	}

	public function test_update_status_and_anonymize_endpoints(): void {
		$controller = new BorrowersController();
		$created    = $controller->create_item( new WP_REST_Request( array( 'display_name' => 'Mutable Reader' ) ) )->get_data();
		$updated    = $controller->update_item(
			new WP_REST_Request(
				array(
					'id'             => $created['id'],
					'preferred_name' => 'MR',
				)
			)
		)->get_data();
		$status     = $controller->status_item(
			new WP_REST_Request(
				array(
					'id'     => $created['id'],
					'status' => 'disabled',
					'reason' => 'Testing',
				)
			)
		)->get_data();
		$anon       = $controller->anonymize_item(
			new WP_REST_Request(
				array(
					'id'     => $created['id'],
					'reason' => 'Privacy',
				)
			)
		)->get_data();

		self::assertSame( 'MR', $updated['preferred_name'] );
		self::assertSame( 'disabled', $status['status'] );
		self::assertSame( 'anonymized', $anon['status'] );
		self::assertSame( '', $anon['display_name'] );
	}

	public function test_combined_patch_route_updates_when_any_editable_field_is_present(): void {
		$cases = array(
			'borrower_type'         => array(
				'value'    => 'guest',
				'expected' => 'guest',
			),
			'wp_user_id'            => array(
				'value'    => 88,
				'expected' => 88,
				'base'     => array(
					'borrower_type' => 'wp_user',
					'wp_user_id'    => 77,
				),
			),
			'status'                => array(
				'value'    => 'disabled',
				'expected' => 'disabled',
			),
			'display_name'          => array(
				'value'    => 'Updated Name',
				'expected' => 'Updated Name',
			),
			'preferred_name'        => array(
				'value'    => 'UN',
				'expected' => 'UN',
			),
			'email'                 => array(
				'value'    => 'updated@example.test',
				'expected' => 'updated@example.test',
			),
			'phone'                 => array(
				'value'    => '555-9876',
				'expected' => '555-9876',
			),
			'guardian_borrower_id'  => array(
				'value'    => 321,
				'expected' => 321,
			),
			'guardian_name'         => array(
				'value'    => 'Guardian Name',
				'expected' => 'Guardian Name',
			),
			'guardian_email'        => array(
				'value'    => 'guardian@example.test',
				'expected' => 'guardian@example.test',
			),
			'guardian_phone'        => array(
				'value'    => '555-0000',
				'expected' => '555-0000',
			),
			'guardian_relationship' => array(
				'value'    => 'Parent',
				'expected' => 'Parent',
			),
			'email_notices_allowed' => array(
				'value'    => true,
				'expected' => true,
			),
			'private_notes'         => array(
				'value'    => 'Route detection note',
				'expected' => 'Route detection note',
			),
		);

		foreach ( $cases as $field => $case ) {
			$GLOBALS['connectlibrary_test_db_tables'] = array();
			$controller                               = new BorrowersController();
			$base                                     = array_merge(
				array(
					'borrower_type' => 'manual',
					'display_name'  => 'Route Reader',
				),
				$case['base'] ?? array()
			);
			$created                                  = $controller->create_item( new WP_REST_Request( $base ) )->get_data();
			$request                                  = new WP_REST_Request(
				array(
					'id'   => $created['id'],
					$field => $case['value'],
				)
			);
			$request->set_method( 'PATCH' );

			$updated = $controller->get_or_update_item( $request )->get_data();

			self::assertSame( $case['expected'], $updated[ $field ], $field . ' should route to update_item().' );
		}
	}

	public function test_combined_get_route_with_editable_query_params_is_read_only(): void {
		$controller = new BorrowersController();
		$created    = $controller->create_item(
			new WP_REST_Request(
				array(
					'display_name'  => 'Original Reader',
					'private_notes' => 'Original private note',
				)
			)
		)->get_data();
		$request    = new WP_REST_Request(
			array(
				'id'            => $created['id'],
				'display_name'  => 'Query Param Should Not Mutate',
				'private_notes' => 'Query private note should not mutate',
			)
		);
		$request->set_method( 'GET' );

		$response = $controller->get_or_update_item( $request )->get_data();
		$stored   = $controller->get_item( new WP_REST_Request( array( 'id' => $created['id'] ) ) )->get_data();

		self::assertSame( 'Original Reader', $response['display_name'] );
		self::assertSame( 'Original private note', $response['private_notes'] );
		self::assertSame( 'Original Reader', $stored['display_name'] );
		self::assertSame( 'Original private note', $stored['private_notes'] );
	}

	public function test_combined_post_route_updates_guardian_contact_and_private_notes_fields(): void {
		$controller = new BorrowersController();
		$created    = $controller->create_item(
			new WP_REST_Request(
				array(
					'borrower_type' => 'manual',
					'display_name'  => 'POST Route Reader',
				)
			)
		)->get_data();
		$request    = new WP_REST_Request(
			array(
				'id'                    => $created['id'],
				'guardian_name'         => 'POST Guardian',
				'guardian_email'        => 'post-guardian@example.test',
				'guardian_phone'        => '555-1234',
				'guardian_relationship' => 'Parent',
				'private_notes'         => 'POST private note',
			)
		);
		$request->set_method( 'POST' );

		$updated = $controller->get_or_update_item( $request )->get_data();

		self::assertSame( 'POST Guardian', $updated['guardian_name'] );
		self::assertSame( 'post-guardian@example.test', $updated['guardian_email'] );
		self::assertSame( '555-1234', $updated['guardian_phone'] );
		self::assertSame( 'Parent', $updated['guardian_relationship'] );
		self::assertSame( 'POST private note', $updated['private_notes'] );
	}

	public function test_rest_child_guardian_validation_and_contact_snapshot_updates(): void {
		$controller = new BorrowersController();
		$adult      = $controller->create_item(
			new WP_REST_Request(
				array(
					'borrower_type' => 'manual',
					'display_name'  => 'REST Adult Guardian',
				)
			)
		)->get_data();
		$child      = $controller->create_item(
			new WP_REST_Request(
				array(
					'borrower_type'        => 'child',
					'display_name'         => 'REST Child',
					'guardian_borrower_id' => $adult['id'],
				)
			)
		)->get_data();
		$self       = $controller->update_item(
			new WP_REST_Request(
				array(
					'id'                   => $child['id'],
					'guardian_borrower_id' => $child['id'],
				)
			)
		);
		$request    = new WP_REST_Request(
			array(
				'id'                    => $child['id'],
				'guardian_borrower_id'  => null,
				'guardian_name'         => 'Snapshot Guardian',
				'guardian_email'        => 'snapshot@example.test',
				'email_notices_allowed' => true,
				'guardian_relationship' => 'Parent',
			)
		);
		$request->set_method( 'PATCH' );
		$updated = $controller->get_or_update_item( $request )->get_data();

		self::assertSame( 'connectlibrary_child_guardian_self', $self->get_error_code() );
		self::assertNull( $updated['guardian_borrower_id'] );
		self::assertSame( 'Snapshot Guardian', $updated['guardian_name'] );
		self::assertSame( 'snapshot@example.test', $updated['guardian_email'] );
		self::assertTrue( $updated['email_notices_allowed'] );
	}

	public function test_unauthorized_cannot_read_or_mutate(): void {
		$controller = new BorrowersController();
		$created    = $controller->create_item( new WP_REST_Request( array( 'display_name' => 'Private Reader' ) ) )->get_data();

		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => false,
			Capabilities::MANAGE_OPTIONS   => false,
		);

		self::assertSame( 403, $controller->get_items( new WP_REST_Request( array() ) )->get_error_data()['status'] );
		self::assertSame( 403, $controller->get_item( new WP_REST_Request( array( 'id' => $created['id'] ) ) )->get_error_data()['status'] );
		self::assertSame(
			403,
			$controller->update_item(
				new WP_REST_Request(
					array(
						'id'           => $created['id'],
						'display_name' => 'Nope',
					)
				)
			)->get_error_data()['status']
		);
	}
}
