<?php
/**
 * Tests for the Print Library Cards admin page (Build 06).
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.VariableComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.FunctionComment.WrongStyle

use ConnectLibrary\Admin\PrintLibraryCardsPage;
use ConnectLibrary\Borrowers\BorrowerCardService;
use ConnectLibrary\Borrowers\BorrowerService;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Build 06 print-cards page: permissions, privacy, layout, and payload safety.
 */
final class PrintLibraryCardsPageTest extends TestCase {
	private string $borrowers_table;
	private string $cards_table;
	private string $audit_events_table;

	protected function setUp(): void {
		$tables                   = Schema::table_names();
		$this->borrowers_table    = $tables['borrowers'] . ':rows';
		$this->cards_table        = $tables['borrower_cards'] . ':rows';
		$this->audit_events_table = $tables['audit_events'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables']        = array(
			$this->borrowers_table    => array(),
			$this->cards_table        => array(),
			$this->audit_events_table => array(),
		);
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => true,
			Capabilities::MANAGE_OPTIONS   => false,
		);
		$GLOBALS['connectlibrary_test_current_user_id']  = 10;
		$GLOBALS['connectlibrary_test_safe_redirect']    = null;
		$GLOBALS['connectlibrary_test_wp_die']           = null;
		$GLOBALS['connectlibrary_test_admin_pages']      = array();
		$_POST = array();
		$_GET  = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
	}

	// -------------------------------------------------------------------------
	// Permission gate
	// -------------------------------------------------------------------------

	public function test_handle_print_blocks_non_librarian(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => false,
			Capabilities::MANAGE_OPTIONS   => false,
		);
		$_POST = array(
			'_wpnonce'     => 'valid-test-nonce',
			'borrower_ids' => array( '1' ),
			'print_layout' => 'sheet',
		);

		( new PrintLibraryCardsPage() )->handle_print();

		$wp_die = $GLOBALS['connectlibrary_test_wp_die'] ?? array();
		self::assertStringContainsString( 'do not have permission', (string) ( $wp_die['message'] ?? '' ) );
	}

	public function test_add_menu_page_registers_submenu_for_librarian(): void {
		( new PrintLibraryCardsPage() )->add_menu_page();
		$pages = $GLOBALS['connectlibrary_test_admin_pages'] ?? array();
		self::assertArrayHasKey( 'connectlibrary-print-cards', $pages, 'Print Cards submenu must be registered.' );
	}

	// -------------------------------------------------------------------------
	// Privacy: no personal data in print HTML
	// -------------------------------------------------------------------------

	public function test_print_html_excludes_email_phone_and_notes(): void {
		$svc      = new BorrowerService();
		$borrower = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Privacy Reader',
				'email'         => 'private@example.test',
				'phone'         => '555-0100',
				'private_notes' => 'Secret pastoral note',
			)
		);
		self::assertIsArray( $borrower );

		$card_svc = new BorrowerCardService();
		$gen      = $card_svc->generate_first_card( (int) $borrower['id'] );
		self::assertIsArray( $gen );

		$html = $this->capture_print_for_ids( array( (int) $borrower['id'] ), 'sheet' );

		self::assertStringContainsString( 'Privacy Reader', $html );
		self::assertStringNotContainsString( 'private@example.test', $html );
		self::assertStringNotContainsString( '555-0100', $html );
		self::assertStringNotContainsString( 'Secret pastoral note', $html );
		self::assertStringNotContainsString( 'checked_out_at', $html );
	}

	// -------------------------------------------------------------------------
	// Payload safety: raw token must not appear in HTML
	// -------------------------------------------------------------------------

	public function test_print_html_does_not_expose_raw_token(): void {
		$svc      = new BorrowerService();
		$borrower = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Token Reader',
			)
		);
		self::assertIsArray( $borrower );

		$card_svc = new BorrowerCardService();
		$gen      = $card_svc->generate_first_card( (int) $borrower['id'] );
		self::assertIsArray( $gen );

		$html    = $this->capture_print_for_ids( array( (int) $borrower['id'] ), 'sheet' );
		$token   = $gen['token'];
		$payload = $gen['row']['payload'];

		self::assertStringNotContainsString( $token, $html, 'Raw plaintext token must not appear in print HTML.' );
		self::assertStringNotContainsString( $payload, $html, 'Card payload string must not appear as visible text in print HTML.' );
	}

	// -------------------------------------------------------------------------
	// Disabled card is skipped
	// -------------------------------------------------------------------------

	public function test_disabled_card_is_skipped_not_printed(): void {
		$svc = new BorrowerService();
		$b1  = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Active Card Reader',
			)
		);
		$b2  = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Disabled Card Reader',
			)
		);
		self::assertIsArray( $b1 );
		self::assertIsArray( $b2 );

		$card_svc = new BorrowerCardService();
		$card_svc->generate_first_card( (int) $b1['id'] );
		$card_svc->generate_first_card( (int) $b2['id'] );
		$card_svc->disable_active_card( (int) $b2['id'] );

		$html = $this->capture_print_for_ids( array( (int) $b1['id'], (int) $b2['id'] ), 'sheet' );

		self::assertStringContainsString( 'Active Card Reader', $html );
		self::assertStringNotContainsString( 'Disabled Card Reader', $html );
		self::assertStringContainsString( 'cl-skipped-notice', $html );
	}

	// -------------------------------------------------------------------------
	// Borrower without active card is skipped
	// -------------------------------------------------------------------------

	public function test_borrower_without_card_is_blocked(): void {
		$svc      = new BorrowerService();
		$borrower = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Cardless Reader',
			)
		);
		self::assertIsArray( $borrower );

		$html = $this->capture_print_for_ids( array( (int) $borrower['id'] ), 'sheet' );

		$wp_die = $GLOBALS['connectlibrary_test_wp_die'] ?? array();
		self::assertSame( '', $html, 'No preview should render when every selected borrower is blocked.' );
		self::assertStringContainsString( 'None of the selected borrowers have active library cards', (string) ( $wp_die['message'] ?? '' ) );
	}

	// -------------------------------------------------------------------------
	// Child card includes only minimal guardian label (no contact data)
	// -------------------------------------------------------------------------

	public function test_child_card_shows_guardian_name_only_no_contact_data(): void {
		$svc = new BorrowerService();

		$guardian = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Parent Guardian',
				'email'         => 'parent@example.test',
				'phone'         => '555-0200',
			)
		);
		self::assertIsArray( $guardian );

		$child = $svc->create(
			array(
				'borrower_type'        => 'child',
				'display_name'         => 'Child Borrower',
				'guardian_borrower_id' => (int) $guardian['id'],
			)
		);
		self::assertIsArray( $child );

		$card_svc = new BorrowerCardService();
		$card_svc->generate_first_card( (int) $guardian['id'] );
		$card_svc->generate_first_card( (int) $child['id'] );

		$html = $this->capture_print_for_ids(
			array( (int) $guardian['id'], (int) $child['id'] ),
			'family'
		);

		self::assertStringContainsString( 'Child Borrower', $html );
		self::assertStringContainsString( 'Parent Guardian', $html );
		self::assertStringNotContainsString( 'parent@example.test', $html );
		self::assertStringNotContainsString( '555-0200', $html );
	}

	// -------------------------------------------------------------------------
	// QR and barcode codes appear in output
	// -------------------------------------------------------------------------

	public function test_print_output_contains_qr_and_barcode_svgs(): void {
		$svc      = new BorrowerService();
		$borrower = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'SVG Test Reader',
			)
		);
		self::assertIsArray( $borrower );

		( new BorrowerCardService() )->generate_first_card( (int) $borrower['id'] );

		$html = $this->capture_print_for_ids( array( (int) $borrower['id'] ), 'sheet' );

		self::assertStringContainsString( 'connectlibrary-card-qr', $html );
		self::assertStringContainsString( 'connectlibrary-card-barcode', $html );
	}

	// -------------------------------------------------------------------------
	// Selection table renders disabled checkboxes for cardless borrowers
	// -------------------------------------------------------------------------

	public function test_selection_ui_disables_checkbox_for_cardless_borrower(): void {
		$svc      = new BorrowerService();
		$borrower = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'No Card Reader',
			)
		);
		self::assertIsArray( $borrower );

		$html = $this->capture_render();

		self::assertStringContainsString( 'No Card Reader', $html );
		self::assertStringContainsString( 'disabled', $html );
		self::assertStringContainsString( 'No active card', $html );
	}

	// -------------------------------------------------------------------------
	// Search/filter workflow and demo/alignment mode
	// -------------------------------------------------------------------------

	public function test_selection_ui_filters_active_borrowers_for_sheet_selection(): void {
		$svc = new BorrowerService();
		$ann = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Ann Searchable',
				'email'         => 'ann.search@example.test',
			)
		);
		$bob = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Bob Hidden',
				'email'         => 'bob.hidden@example.test',
			)
		);
		self::assertIsArray( $ann );
		self::assertIsArray( $bob );

		$card_svc = new BorrowerCardService();
		$card_svc->generate_first_card( (int) $ann['id'] );
		$card_svc->generate_first_card( (int) $bob['id'] );

		$_GET = array( 's' => 'Ann' );
		$html = $this->capture_render();

		self::assertStringContainsString( 'id="cl-borrower-search"', $html );
		self::assertStringContainsString( 'value="Ann"', $html );
		self::assertStringContainsString( 'Ann Searchable', $html );
		self::assertStringNotContainsString( 'Bob Hidden', $html );
		self::assertStringContainsString( 'id="cl-borrower-' . (int) $ann['id'] . '"', $html, 'Filtered borrower rows must remain selectable for sheet printing.' );
		self::assertStringNotContainsString( 'id="cl-borrower-' . (int) $bob['id'] . '"', $html );
	}

	public function test_demo_alignment_preview_uses_only_fake_placeholders_without_selection(): void {
		$svc      = new BorrowerService();
		$borrower = $svc->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => 'Real Private Reader',
				'email'         => 'private-reader@example.test',
			)
		);
		self::assertIsArray( $borrower );

		$html = $this->capture_print_request(
			array(
				'_wpnonce'     => 'valid-test-nonce',
				'print_layout' => 'demo',
				'card_size'    => 'standard',
				'orientation'  => 'portrait',
				'cut_guides'   => '1',
			)
		);

		self::assertStringContainsString( 'Demo alignment preview', $html );
		self::assertStringContainsString( 'Demo Placeholder 1', $html );
		self::assertStringContainsString( 'DEMO-008', $html );
		self::assertStringNotContainsString( 'Real Private Reader', $html );
		self::assertStringNotContainsString( 'private-reader@example.test', $html );
		self::assertSame( 8, substr_count( $html, 'class="connectlibrary-card-print"' ) );
		self::assertNull( $GLOBALS['connectlibrary_test_wp_die'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function capture_print_for_ids( array $borrower_ids, string $layout ): string {
		return $this->capture_print_request(
			array(
				'_wpnonce'     => 'valid-test-nonce',
				'borrower_ids' => array_map( 'strval', $borrower_ids ),
				'print_layout' => $layout,
				'card_size'    => 'standard',
				'orientation'  => 'portrait',
				'cut_guides'   => '1',
			)
		);
	}

	/** @param array<string,mixed> $post_data */
	private function capture_print_request( array $post_data ): string {
		$GLOBALS['connectlibrary_test_safe_redirect'] = null;
		$_POST                                        = $post_data;
		$page                                         = ( new PrintLibraryCardsPage() )->suppress_exit();
		ob_start();
		try {
			$page->handle_print();
		} catch ( \RuntimeException $e ) {
			ob_end_clean();
			throw $e;
		} finally {
			$_POST = array();
		}
		return (string) ob_get_clean();
	}

	private function capture_render(): string {
		ob_start();
		( new PrintLibraryCardsPage() )->render();
		return (string) ob_get_clean();
	}
}
