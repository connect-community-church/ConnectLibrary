<?php
/**
 * Tests for borrower admin screen rendering.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Admin\BorrowersPage;
use ConnectLibrary\Borrowers\BorrowerService;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Phase 2 librarian borrower admin controls.
 */
final class BorrowersAdminTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_admin_pages']      = array();
		$GLOBALS['connectlibrary_test_current_user_id']  = 42;
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => true,
			Capabilities::MANAGE_OPTIONS   => false,
		);
		$GLOBALS['connectlibrary_test_db_tables']        = array();
		$GLOBALS['connectlibrary_test_safe_redirect']    = null;
		$GLOBALS['connectlibrary_test_users']            = array(
			77 => (object) array(
				'ID'         => 77,
				'user_login' => 'linked-reader',
				'user_email' => 'linked@example.test',
			),
		);
		$_GET  = array();
		$_POST = array();
	}

	public function test_borrowers_page_registers_under_library_admin_with_manage_borrowers_capability(): void {
		$page = new BorrowersPage();

		$page->add_menu_page();

		self::assertArrayHasKey( 'connectlibrary-borrowers', $GLOBALS['connectlibrary_test_admin_pages'] );
		self::assertSame( 'edit.php?post_type=' . BookPostType::POST_TYPE, $GLOBALS['connectlibrary_test_admin_pages']['connectlibrary-borrowers']['parent_slug'] );
		self::assertSame( Capabilities::MANAGE_BORROWERS, $GLOBALS['connectlibrary_test_admin_pages']['connectlibrary-borrowers']['capability'] );
	}

	public function test_render_lists_borrower_summary_columns_and_active_adult_guardian_options(): void {
		$service = new BorrowerService();
		$adult   = $service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Adult Guardian',
				'email'         => 'adult@example.test',
				'phone'         => '555-1111',
			)
		);
		$service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Disabled Adult',
				'status'        => 'disabled',
			)
		);
		$child = $service->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'Child Reader',
				'guardian_borrower_id' => $adult['id'],
				'private_notes'        => 'Private pastoral context',
			)
		);
		$service->create(
			array(
				'borrower_type' => 'wp_user',
				'wp_user_id'    => 77,
				'display_name'  => 'Linked Reader',
			)
		);
		$_GET['borrower_id'] = (string) $child['id'];

		ob_start();
		( new BorrowersPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Borrowers', $html );
		self::assertStringContainsString( 'Adult Guardian', $html );
		self::assertStringContainsString( 'Child Reader', $html );
		self::assertStringContainsString( 'WordPress-linked account', $html );
		self::assertStringNotContainsString( 'Linked WP user #77', $html );
		self::assertStringContainsString( 'adult@example.test', $html );
		self::assertStringContainsString( 'Updated', $html );
		self::assertStringContainsString( 'value="' . $adult['id'] . '" selected="selected"', $html );
		self::assertStringNotContainsString( 'value="2"', $html, 'Disabled adults must not be offered as active guardian choices.' );
		self::assertStringNotContainsString( 'Private pastoral context</td>', $html, 'Private notes must not appear in the borrower list.' );
	}

	public function test_render_shows_filter_controls_for_search_status_and_type(): void {
		$service = new BorrowerService();
		$service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Filter Control Adult',
			)
		);

		ob_start();
		( new BorrowersPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="search"', $html );
		self::assertStringContainsString( 'name="status"', $html );
		self::assertStringContainsString( 'name="borrower_type"', $html );
		self::assertStringContainsString( 'Filter Control Adult', $html );
	}

	public function test_render_filter_by_borrower_type_limits_table_and_reflects_selected_value(): void {
		$service = new BorrowerService();
		$adult   = $service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Type Filter Adult',
			)
		);
		$service->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'Type Filter Child',
				'guardian_borrower_id' => $adult['id'],
			)
		);
		$_GET['borrower_type'] = 'child';

		ob_start();
		( new BorrowersPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Type Filter Child', $html );
		self::assertStringContainsString( 'value="child" selected="selected"', $html );
	}

	public function test_render_filter_by_status_excludes_non_matching_borrowers(): void {
		$service = new BorrowerService();
		$service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Active Member',
			)
		);
		$service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Disabled Member',
				'status'        => 'disabled',
			)
		);
		$_GET['status'] = 'active';

		ob_start();
		( new BorrowersPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Active Member', $html );
		self::assertStringNotContainsString( 'Disabled Member', $html );
	}

	public function test_render_linked_children_shown_for_adult_borrower_without_private_notes(): void {
		$service = new BorrowerService();
		$adult   = $service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Guardian Adult',
			)
		);
		$service->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'Linked Child A',
				'guardian_borrower_id' => $adult['id'],
				'private_notes'        => 'Confidential child note',
			)
		);
		$service->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'Linked Child B',
				'guardian_borrower_id' => $adult['id'],
			)
		);
		$_GET['borrower_id'] = (string) $adult['id'];

		ob_start();
		( new BorrowersPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Linked children', $html );
		self::assertStringContainsString( 'Linked Child A', $html );
		self::assertStringContainsString( 'Linked Child B', $html );
		self::assertStringNotContainsString( 'Confidential child note', $html );
	}

	public function test_linked_children_section_not_shown_when_viewing_child_borrower(): void {
		$service             = new BorrowerService();
		$adult               = $service->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Parent Adult',
			)
		);
		$child               = $service->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'View Child',
				'guardian_borrower_id' => $adult['id'],
			)
		);
		$_GET['borrower_id'] = (string) $child['id'];

		ob_start();
		( new BorrowersPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Linked children', $html );
	}

	public function test_admin_post_uses_service_validation_for_active_child_guardians(): void {
		$page  = new BorrowersPage();
		$_POST = array(
			'_wpnonce'      => 'valid',
			'borrower_type' => 'child',
			'status'        => 'active',
			'display_name'  => 'Child Without Guardian',
		);

		$page->handle_post();

		self::assertStringContainsString( 'borrower_error=connectlibrary_child_guardian_required', $GLOBALS['connectlibrary_test_safe_redirect']['location'] );
		self::assertSame( array(), ( new BorrowerService() )->search() );
	}
}
