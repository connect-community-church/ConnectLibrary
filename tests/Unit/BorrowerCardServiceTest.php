<?php
/**
 * Tests for generated borrower library-card lifecycle.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.VariableComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag

use ConnectLibrary\Admin\BorrowersPage;
use ConnectLibrary\Borrowers\BorrowerCardService;
use ConnectLibrary\Borrowers\BorrowerService;
use ConnectLibrary\Cards\BorrowerCardRenderer;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Spec 05 card generation, replacement, lookup, audit, and print privacy.
 */
final class BorrowerCardServiceTest extends TestCase {
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
		$GLOBALS['connectlibrary_test_current_user_id']  = 91;
	}

	public function test_schema_defines_dedicated_borrower_cards_table(): void {
		$tables = Schema::table_names( 'wp_' );
		$sql    = Schema::sql_definitions( $tables, 'DEFAULT CHARSET=utf8mb4' );

		self::assertArrayHasKey( 'borrower_cards', $tables );
		self::assertArrayHasKey( 'borrower_cards', $sql );
		self::assertStringContainsString( 'token_hash varchar(128) NOT NULL', $sql['borrower_cards'] );
		self::assertStringContainsString( 'replaces_card_id bigint(20) unsigned DEFAULT NULL', $sql['borrower_cards'] );
		self::assertStringContainsString( 'superseded_by_card_id bigint(20) unsigned DEFAULT NULL', $sql['borrower_cards'] );
		self::assertStringContainsString( 'replacement_reason varchar(100) DEFAULT NULL', $sql['borrower_cards'] );
		self::assertStringContainsString( 'replacement_note text DEFAULT NULL', $sql['borrower_cards'] );
		self::assertStringContainsString( 'audit_correlation_id varchar(36) DEFAULT NULL', $sql['borrower_cards'] );
	}

	public function test_generate_reprint_replace_and_disable_card_lifecycle(): void {
		$borrower = $this->create_borrower( 'Jane Cardholder' );
		$service  = new BorrowerCardService();

		$generated = $service->generate_first_card( (int) $borrower['id'] );
		self::assertIsArray( $generated );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{48}$/', $generated['token'] );
		self::assertSame( 'active', $generated['row']['status'] );
		self::assertStringStartsWith( 'CLCARD-', $generated['row']['payload'] );
		self::assertArrayNotHasKey( 'email', $generated['row'] );

		$reprinted = $service->reprint_active_card( (int) $borrower['id'] );
		self::assertSame( $generated['row']['id'], $reprinted['id'] );
		self::assertSame( $generated['row']['token_hash'], $reprinted['token_hash'], 'Reprint must not rotate the active token.' );

		$replacement = $service->replace_lost_card( (int) $borrower['id'] );
		self::assertIsArray( $replacement );
		self::assertNotSame( $generated['token'], $replacement['token'] );
		self::assertSame( $generated['row']['id'], $replacement['row']['replaces_card_id'] );
		self::assertSame( 'replaced_lost', $GLOBALS['connectlibrary_test_db_tables'][ $this->cards_table ][0]['status'] );
		self::assertSame( 'Lost card', $GLOBALS['connectlibrary_test_db_tables'][ $this->cards_table ][0]['replacement_reason'] );
		self::assertSame( $GLOBALS['connectlibrary_test_db_tables'][ $this->cards_table ][1]['id'], $GLOBALS['connectlibrary_test_db_tables'][ $this->cards_table ][0]['superseded_by_card_id'] );

		$old_lookup = $service->resolve_card_token( $generated['row']['payload'] );
		self::assertSame( 'connectlibrary_card_inactive', $old_lookup->get_error_code() );

		$new_lookup = $service->resolve_card_token( $replacement['row']['payload'] );
		self::assertIsArray( $new_lookup );
		self::assertSame( $borrower['id'], $new_lookup['borrower_id'] );

		$disabled = $service->disable_active_card( (int) $borrower['id'] );
		self::assertIsArray( $disabled );
		self::assertSame( 'disabled', $disabled['status'] );
		self::assertSame( 'connectlibrary_card_inactive', $service->resolve_card_token( $replacement['row']['payload'] )->get_error_code() );
	}

	public function test_card_events_are_audited_without_raw_tokens(): void {
		$borrower  = $this->create_borrower( 'Audit Reader' );
		$generated = ( new BorrowerCardService() )->generate_first_card( (int) $borrower['id'] );
		self::assertIsArray( $generated );

		$audit_json = wp_json_encode( $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ] );
		self::assertStringContainsString( 'card_generated', $audit_json );
		self::assertStringNotContainsString( $generated['token'], $audit_json );
		self::assertStringNotContainsString( $generated['row']['payload'], $audit_json );
	}

	public function test_print_output_contains_codes_but_excludes_private_contact_and_loan_data(): void {
		$borrower  = $this->create_borrower( 'Print Reader', 'secret@example.test', 'Private pastoral note' );
		$generated = ( new BorrowerCardService() )->generate_first_card( (int) $borrower['id'] );
		self::assertIsArray( $generated );

		$html = ( new BorrowerCardService() )->render_single_card( $generated['row'] );
		self::assertIsString( $html );
		self::assertStringContainsString( 'connectlibrary-card-qr', $html );
		self::assertStringContainsString( 'connectlibrary-card-barcode', $html );
		self::assertStringContainsString( 'Print Reader', $html );
		self::assertStringNotContainsString( 'secret@example.test', $html );
		self::assertStringNotContainsString( 'Private pastoral note', $html );
		self::assertStringNotContainsString( 'checked_out_at', $html );
		self::assertStringNotContainsString( $generated['token'], $html );
		self::assertStringNotContainsString( $generated['row']['payload'], $html );
	}

	public function test_code128_encoder_values_decode_to_exact_card_payload(): void {
		$renderer = new BorrowerCardRenderer();
		$payload  = 'CLCARD-ABC123';
		$values   = $renderer->code128_code_values( $payload );

		self::assertSame( array( 104, 35, 44, 35, 33, 50, 36, 13, 33, 34, 35, 17, 18, 19, 16, 106 ), $values );
		self::assertSame( $payload, implode( '', array_map( static fn ( int $value ): string => chr( $value + 32 ), array_slice( $values, 1, strlen( $payload ) ) ) ) );
	}

	public function test_qr_encoder_generates_stable_version4_matrix_for_known_payload(): void {
		$renderer = new BorrowerCardRenderer();
		$matrix   = $renderer->qr_matrix( 'CLCARD-ABC123' );
		$rows     = array_map( static fn ( array $row ): string => implode( '', array_map( static fn ( bool $on ): string => $on ? '1' : '0', $row ) ), $matrix );

		self::assertCount( 33, $matrix );
		self::assertSame( 'cbe43e6c97b4e04e6d037c29e6a5ad3d7933d16e78f3b4e1e3837897979c839c', hash( 'sha256', implode( "\n", $rows ) ) );
	}

	public function test_generate_and_replace_actions_render_print_page_without_raw_token_notice(): void {
		$borrower = $this->create_borrower( 'Print Lifecycle Reader' );
		$page     = new BorrowersPage();

		$generate_html = $this->run_card_action_and_capture_html( $page, (int) $borrower['id'], 'generate' );
		$generated_row = $GLOBALS['connectlibrary_test_db_tables'][ $this->cards_table ][0];
		self::assertStringContainsString( 'connectlibrary-card-print', $generate_html );
		self::assertStringContainsString( 'Print Lifecycle Reader', $generate_html );
		self::assertStringNotContainsString( (string) $generated_row['payload'], $generate_html );
		self::assertNull( $GLOBALS['connectlibrary_test_safe_redirect'] );

		$replace_html    = $this->run_card_action_and_capture_html( $page, (int) $borrower['id'], 'replace' );
		$replacement_row = $GLOBALS['connectlibrary_test_db_tables'][ $this->cards_table ][1];
		self::assertStringContainsString( 'connectlibrary-card-print', $replace_html );
		self::assertStringNotContainsString( (string) $replacement_row['payload'], $replace_html );
		self::assertSame( 'replaced_lost', $GLOBALS['connectlibrary_test_db_tables'][ $this->cards_table ][0]['status'] );
		self::assertNull( $GLOBALS['connectlibrary_test_safe_redirect'] );
	}

	public function test_replace_lost_card_rolls_back_when_new_insert_fails(): void {
		$borrower  = $this->create_borrower( 'Rollback Reader' );
		$service   = new BorrowerCardService();
		$generated = $service->generate_first_card( (int) $borrower['id'] );
		self::assertIsArray( $generated );

		$GLOBALS['connectlibrary_test_db_insert_failures'][ $this->cards_table ] = true;
		$result = $service->replace_lost_card( (int) $borrower['id'], 'Lost card', 'Insert failed test' );
		unset( $GLOBALS['connectlibrary_test_db_insert_failures'][ $this->cards_table ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$rows   = $GLOBALS['connectlibrary_test_db_tables'][ $this->cards_table ];
		$active = array_values( array_filter( $rows, static fn ( array $row ): bool => 'active' === (string) ( $row['status'] ?? '' ) ) );
		self::assertCount( 1, $rows );
		self::assertCount( 1, $active );
		self::assertSame( $generated['row']['id'], $active[0]['id'] );
	}

	public function test_inactive_old_card_scan_is_private_and_audited_without_raw_token_or_borrower_details(): void {
		$borrower    = $this->create_borrower( 'Private Scan Reader', 'private-scan@example.test', 'Do not leak' );
		$service     = new BorrowerCardService();
		$generated   = $service->generate_first_card( (int) $borrower['id'] );
		$replacement = $service->replace_lost_card( (int) $borrower['id'] );
		self::assertIsArray( $generated );
		self::assertIsArray( $replacement );

		$result = $service->resolve_card_token( $generated['row']['payload'] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'connectlibrary_card_inactive', $result->get_error_code() );
		$message = $result->get_error_message();
		self::assertStringNotContainsString( 'Private Scan Reader', $message );
		self::assertStringNotContainsString( 'private-scan@example.test', $message );

		$audit_json = wp_json_encode( $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ] );
		self::assertStringContainsString( 'card_inactive_scan', $audit_json );
		self::assertStringNotContainsString( $generated['token'], $audit_json );
		self::assertStringNotContainsString( $generated['row']['payload'], $audit_json );
		self::assertStringNotContainsString( 'Private Scan Reader', $audit_json );
		self::assertStringNotContainsString( 'private-scan@example.test', $audit_json );
	}

	public function test_replace_action_requires_confirmation_and_panel_has_accessible_warning(): void {
		$borrower = $this->create_borrower( 'Confirm Reader' );
		$service  = new BorrowerCardService();
		self::assertIsArray( $service->generate_first_card( (int) $borrower['id'] ) );
		$page = new BorrowersPage();

		$GLOBALS['connectlibrary_test_safe_redirect'] = null;
		$_POST                                        = array(
			'_wpnonce'    => 'valid-test-nonce',
			'borrower_id' => (string) $borrower['id'],
			'card_action' => 'replace',
		);
		ob_start();
		try {
			$page->handle_card_action();
		} finally {
			ob_end_clean();
			$_POST = array();
		}
		self::assertStringContainsString( 'borrower_error=connectlibrary_card_replace_unconfirmed', (string) ( $GLOBALS['connectlibrary_test_safe_redirect']['location'] ?? '' ) );
		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->cards_table ];
		self::assertCount( 1, $rows );
		self::assertSame( 'active', $rows[0]['status'] );

		$reflection = new \ReflectionMethod( BorrowersPage::class, 'render_card_panel' );
		$reflection->setAccessible( true );
		ob_start();
		try {
			$reflection->invoke( $page, (int) $borrower['id'] );
		} finally {
			$html = (string) ob_get_clean();
		}
		self::assertStringContainsString( 'connectlibrary-lost-card-replacement', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );
		self::assertStringContainsString( 'required value="Lost card"', $html );
		self::assertStringContainsString( 'old card stops immediately', $html );
	}

	private function run_card_action_and_capture_html( BorrowersPage $page, int $borrower_id, string $card_action ): string {
		$GLOBALS['connectlibrary_test_safe_redirect'] = null;
		$_POST                                        = array(
			'_wpnonce'           => 'valid-test-nonce',
			'borrower_id'        => (string) $borrower_id,
			'card_action'        => $card_action,
			'lost_card_confirm'  => 'replace' === $card_action ? '1' : '',
			'replacement_reason' => 'Lost card',
			'replacement_note'   => 'Desk note',
		);
		ob_start();
		try {
			$page->handle_card_action();
		} finally {
			$html  = (string) ob_get_clean();
			$_POST = array();
		}
		return $html;
	}

	/** @return array<string,mixed> */
	private function create_borrower( string $name, string $email = '', string $private_notes = '' ): array {
		$created = ( new BorrowerService() )->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => $name,
				'email'         => $email,
				'private_notes' => $private_notes,
			)
		);
		self::assertIsArray( $created );

		return $created;
	}
}
