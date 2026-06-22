<?php
/**
 * Tests for borrower service privacy and validation rules.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Borrowers\BorrowerService;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Phase 2 borrower/member service foundations.
 */
final class BorrowerServiceTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_db_tables']        = array();
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => true,
			Capabilities::MANAGE_OPTIONS   => false,
		);
		$GLOBALS['connectlibrary_test_current_user_id']  = 42;
		$GLOBALS['connectlibrary_test_created_users']    = array();
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

	public function test_unauthorized_create_search_and_get_return_forbidden_errors(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => false,
			Capabilities::MANAGE_OPTIONS   => false,
		);

		$service = new BorrowerService();

		self::assertSame( 403, $service->create( array( 'display_name' => 'No Access' ) )->get_error_data()['status'] );
		self::assertSame( 403, $service->search()->get_error_data()['status'] );
		self::assertSame( 403, $service->get( 1 )->get_error_data()['status'] );
	}

	public function test_manual_borrower_create_sanitizes_and_does_not_create_wordpress_user(): void {
		$created = ( new BorrowerService() )->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => ' <b>Jane Reader</b> ',
				'email'         => ' jane@example.test ',
				'private_notes' => '<script>hide</script>Pastoral note',
			)
		);

		self::assertIsArray( $created );
		self::assertSame( 1, $created['id'] );
		self::assertSame( 'manual', $created['borrower_type'] );
		self::assertSame( 'Jane Reader', $created['display_name'] );
		self::assertSame( 'jane@example.test', $created['email'] );
		self::assertSame( array(), $GLOBALS['connectlibrary_test_created_users'] );
		self::assertArrayNotHasKey( 'auth_token', $created );
		self::assertArrayNotHasKey( 'card_token', $created );
		self::assertArrayNotHasKey( 'guest_token', $created );
	}

	public function test_active_wp_user_borrower_must_be_unique(): void {
		$service = new BorrowerService();
		$first   = $service->create(
			array(
				'borrower_type' => 'wp_user',
				'wp_user_id'    => 77,
				'display_name'  => 'First User',
			)
		);
		$second  = $service->create(
			array(
				'borrower_type' => 'wp_user',
				'wp_user_id'    => 77,
				'display_name'  => 'Duplicate User',
			)
		);

		self::assertIsArray( $first );
		self::assertSame( 'connectlibrary_borrower_wp_user_exists', $second->get_error_code() );
	}

	public function test_wp_user_borrower_must_link_to_existing_wordpress_user(): void {
		$error = ( new BorrowerService() )->create(
			array(
				'borrower_type' => 'wp_user',
				'wp_user_id'    => 404,
				'display_name'  => 'Missing User',
			)
		);

		self::assertSame( 'connectlibrary_borrower_wp_user_missing', $error->get_error_code() );
		self::assertSame( 400, $error->get_error_data()['status'] );
	}

	public function test_update_rejects_missing_or_duplicate_wp_user_link(): void {
		$service = new BorrowerService();
		$first   = $service->create(
			array(
				'borrower_type' => 'wp_user',
				'wp_user_id'    => 77,
				'display_name'  => 'First User',
			)
		);
		$second  = $service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Manual Reader',
			)
		);

		$missing   = $service->update(
			$second['id'],
			array(
				'borrower_type' => 'wp_user',
				'wp_user_id'    => 404,
			)
		);
		$duplicate = $service->update(
			$second['id'],
			array(
				'borrower_type' => 'wp_user',
				'wp_user_id'    => 77,
			)
		);

		self::assertIsArray( $first );
		self::assertSame( 'connectlibrary_borrower_wp_user_missing', $missing->get_error_code() );
		self::assertSame( 'connectlibrary_borrower_wp_user_exists', $duplicate->get_error_code() );
	}

	public function test_child_borrower_requires_guardian_link_or_contact_snapshot(): void {
		$service = new BorrowerService();
		$error   = $service->create(
			array(
				'borrower_type' => 'child',
				'display_name'  => 'Child Reader',
			)
		);
		$valid   = $service->create(
			array(
				'borrower_type'         => 'child',
				'display_name'          => 'Child Reader',
				'guardian_name'         => 'Guardian Reader',
				'guardian_email'        => 'guardian@example.test',
				'guardian_relationship' => 'Parent',
				'email_notices_allowed' => true,
			)
		);

		self::assertSame( 'connectlibrary_child_guardian_required', $error->get_error_code() );
		self::assertIsArray( $valid );
		self::assertSame( 'Guardian Reader', $valid['guardian_name'] );
	}

	public function test_active_child_borrower_requires_active_adult_guardian_link(): void {
		$service  = new BorrowerService();
		$adult    = $service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Adult Guardian',
			)
		);
		$disabled = $service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Disabled Guardian',
				'status'        => 'disabled',
			)
		);
		$valid    = $service->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'Linked Child',
				'guardian_borrower_id' => $adult['id'],
			)
		);
		$error    = $service->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'Child With Disabled Guardian',
				'guardian_borrower_id' => $disabled['id'],
			)
		);

		self::assertIsArray( $valid );
		self::assertSame( 'connectlibrary_child_guardian_invalid', $error->get_error_code() );
		self::assertSame( 400, $error->get_error_data()['status'] );
	}

	public function test_child_guardian_validation_rejects_self_child_guardian_and_circular_links(): void {
		$service = new BorrowerService();
		$adult   = $service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Adult Guardian',
			)
		);
		$child   = $service->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'Child One',
				'guardian_borrower_id' => $adult['id'],
			)
		);

		$self     = $service->update( $child['id'], array( 'guardian_borrower_id' => $child['id'] ) );
		$as_child = $service->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'Child Two',
				'guardian_borrower_id' => $child['id'],
			)
		);
		$circular = $service->update(
			$adult['id'],
			array(
				'borrower_type'        => 'child',
				'guardian_borrower_id' => $child['id'],
			)
		);

		self::assertSame( 'connectlibrary_child_guardian_self', $self->get_error_code() );
		self::assertSame( 'connectlibrary_child_guardian_invalid', $as_child->get_error_code() );
		self::assertSame( 'connectlibrary_child_guardian_circular', $circular->get_error_code() );
	}

	public function test_update_status_export_and_anonymize_create_audit_and_remove_personal_fields(): void {
		$service  = new BorrowerService();
		$borrower = $service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Privacy Test',
				'email'         => 'privacy@example.test',
				'phone'         => '555-1234',
				'private_notes' => 'Sensitive note',
			)
		);

		$updated    = $service->update( $borrower['id'], array( 'preferred_name' => 'PT' ) );
		$status     = $service->set_status( $borrower['id'], 'disabled', 'Paused' );
		$export     = $service->export( $borrower['id'] );
		$anonymized = $service->anonymize( $borrower['id'], 'Privacy request' );
		$audit      = $service->audit_events( $borrower['id'] );

		self::assertSame( 'PT', $updated['preferred_name'] );
		self::assertSame( 'disabled', $status['status'] );
		self::assertSame( 'privacy@example.test', $export['borrower']['email'] );
		self::assertSame( 'anonymized', $anonymized['status'] );
		self::assertSame( '', $anonymized['display_name'] );
		self::assertNull( $anonymized['email'] );
		self::assertNull( $anonymized['phone'] );
		self::assertNull( $anonymized['private_notes'] );
		self::assertContains( 'create', array_column( $audit, 'action' ) );
		self::assertContains( 'update', array_column( $audit, 'action' ) );
		self::assertContains( 'status', array_column( $audit, 'action' ) );
		self::assertContains( 'export', array_column( $audit, 'action' ) );
		self::assertContains( 'anonymize', array_column( $audit, 'action' ) );
		self::assertStringNotContainsString( 'Sensitive note', wp_json_encode( $audit ) );
	}

	public function test_search_matches_guardian_snapshot_fields(): void {
		$service = new BorrowerService();
		$service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Regular Adult',
				'email'         => 'adult@example.test',
			)
		);
		$service->create(
			array(
				'borrower_type'         => 'child',
				'display_name'          => 'Child With Snapshot',
				'guardian_name'         => 'Josephine Smith',
				'guardian_email'        => 'jsmith@example.test',
				'guardian_phone'        => '555-9999',
				'guardian_relationship' => 'Grandmother',
			)
		);

		$by_guardian_name         = $service->search( array( 'search' => 'Josephine' ) );
		$by_guardian_email        = $service->search( array( 'search' => 'jsmith@example.test' ) );
		$by_guardian_phone        = $service->search( array( 'search' => '555-9999' ) );
		$by_guardian_relationship = $service->search( array( 'search' => 'Grandmother' ) );
		$no_match                 = $service->search( array( 'search' => 'ZZZnoMatch' ) );

		self::assertCount( 1, $by_guardian_name );
		self::assertSame( 'Child With Snapshot', $by_guardian_name[0]['display_name'] );
		self::assertCount( 1, $by_guardian_email );
		self::assertSame( 'Child With Snapshot', $by_guardian_email[0]['display_name'] );
		self::assertCount( 1, $by_guardian_phone );
		self::assertCount( 1, $by_guardian_relationship );
		self::assertCount( 0, $no_match );
	}

	public function test_guardian_link_unlink_and_status_changes_are_audited_without_private_note_text(): void {
		$service  = new BorrowerService();
		$adult    = $service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Adult Guardian',
			)
		);
		$borrower = $service->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'Audited Child',
				'guardian_borrower_id' => $adult['id'],
				'private_notes'        => 'Do not store this private note in audit rows',
			)
		);

		$service->update(
			$borrower['id'],
			array(
				'guardian_borrower_id' => null,
				'guardian_name'        => 'Snapshot Guardian',
				'guardian_email'       => 'snapshot@example.test',
			)
		);
		$service->set_status( $borrower['id'], 'disabled', 'Parent requested pause' );
		$audit = $service->audit_events( $borrower['id'] );

		self::assertContains( 'create', array_column( $audit, 'action' ) );
		self::assertContains( 'guardian_link', array_column( $audit, 'action' ) );
		self::assertContains( 'guardian_unlink', array_column( $audit, 'action' ) );
		self::assertContains( 'status', array_column( $audit, 'action' ) );
		self::assertStringNotContainsString( 'Do not store this private note in audit rows', wp_json_encode( $audit ) );
	}
}
