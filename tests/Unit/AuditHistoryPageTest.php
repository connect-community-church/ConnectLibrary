<?php
/**
 * Tests for the librarian Audit & History admin page.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable

use ConnectLibrary\Admin\AuditHistoryPage;
use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Audit history page tests.
 */
final class AuditHistoryPageTest extends TestCase {

	private string $audit_table;
	private AuditEventService $audit;
	private AuditHistoryPage $page;

	protected function setUp(): void {
		$tables            = Schema::table_names();
		$this->audit_table = $tables['audit_events'] . ':rows';

		$GLOBALS['connectlibrary_test_admin_pages']      = array();
		$GLOBALS['connectlibrary_test_db_tables']        = array( $this->audit_table => array() );
		$GLOBALS['connectlibrary_test_current_user_can'] = array();
		$GLOBALS['connectlibrary_test_wp_die']           = null;
		$_GET = array();

		$this->audit = new AuditEventService();
		$this->page  = new AuditHistoryPage( $this->audit );
	}

	public function test_add_menu_page_registers_audit_history_submenu(): void {
		$this->page->add_menu_page();

		self::assertArrayHasKey( AuditHistoryPage::PAGE_SLUG, $GLOBALS['connectlibrary_test_admin_pages'] );
		$registered = $GLOBALS['connectlibrary_test_admin_pages'][ AuditHistoryPage::PAGE_SLUG ];
		self::assertSame( 'edit.php?post_type=' . BookPostType::POST_TYPE, $registered['parent_slug'] );
		self::assertSame( Capabilities::MANAGE_CIRCULATION, $registered['capability'] );
	}

	public function test_render_denies_users_without_librarian_capability(): void {
			$GLOBALS['connectlibrary_test_current_user_can'] = array(
				'manage_options'                  => false,
				'manage_connectlibrary_borrowers' => false,
				Capabilities::MANAGE_CIRCULATION  => false,
			);

			ob_start();
			$this->page->render();
			ob_end_clean();

			self::assertIsArray( $GLOBALS['connectlibrary_test_wp_die'] );
			self::assertStringContainsString( 'permission', (string) $GLOBALS['connectlibrary_test_wp_die']['message'] );
	}

	public function test_render_empty_state_and_filter_form(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array( Capabilities::MANAGE_CIRCULATION => true );

		ob_start();
		$this->page->render();
		$html = ob_get_clean();

		self::assertStringContainsString( 'Audit &amp; History', $html );
		self::assertStringContainsString( 'Filter audit events', $html );
		self::assertStringContainsString( 'No audit events match', $html );
		self::assertStringContainsString( 'name="action_group"', $html );
		self::assertStringContainsString( 'name="actor_type"', $html );
	}

	public function test_render_scoped_heading_and_safe_detail(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array( Capabilities::MANAGE_CIRCULATION => true );
		$this->audit->log(
			'card_replaced',
			array(
				'entity_type'    => 'borrower',
				'entity_id'      => 123,
				'action_group'   => 'borrowers',
				'safe_label'     => 'Borrower #123 (child account)',
				'correlation_id' => 'corr-test-1',
				'context'        => array(
					'privacy_state' => 'anonymized',
					'card_token'    => 'must-not-render',
					'guardian_view' => 'limited',
				),
				'before'         => array( 'status' => 'active' ),
				'after'          => array( 'status' => 'anonymized' ),
				'summary'        => 'Card replaced for borrower',
			)
		);

		$_GET = array(
			'entity_type' => 'borrower',
			'entity_id'   => '123',
			'cl_event_id' => '1',
		);

		ob_start();
		$this->page->render();
		$html = ob_get_clean();

		self::assertStringContainsString( 'Scoped history: borrower record', $html );
		self::assertStringNotContainsString( 'Scoped history: borrower #123', $html );
		self::assertStringContainsString( 'Borrower record (child account)', $html );
		self::assertStringNotContainsString( 'Borrower #123', $html );
		self::assertStringNotContainsString( 'user #1', $html );
		self::assertStringContainsString( '[redacted]', $html );
		self::assertStringNotContainsString( 'must-not-render', $html );
		self::assertStringContainsString( 'anonymized', $html );
	}

	public function test_scoped_url_uses_safe_ids_only(): void {
		$url = AuditHistoryPage::scoped_url( 'borrower', 99 );

		self::assertStringContainsString( 'page=connectlibrary-audit-history', $url );
		self::assertStringContainsString( 'entity_type=borrower', $url );
		self::assertStringContainsString( 'entity_id=99', $url );
	}
}
