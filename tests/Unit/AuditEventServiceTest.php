<?php
/**
 * Tests for the shared audit event service: schema, creation, append-only
 * correctness, privacy redaction, capability gating, and workflow coverage.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable

use ConnectLibrary\Audit\AuditEventRepository;
use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Circulation\DueReminderService;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Circulation\LoanService;
use ConnectLibrary\Circulation\CopyRepository;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Rest\AuditEventsController;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Support\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * Shared audit event service and integration tests.
 */
final class AuditEventServiceTest extends TestCase {

	private AuditEventService $service;

	private string $audit_events_table;
	private string $loans_table;
	private string $loan_audit_table;
	private string $reservations_table;
	private string $reservation_audit_table;
	private string $copies_table;
	private string $borrowers_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->audit_events_table      = $tables['audit_events'] . ':rows';
		$this->loans_table             = $tables['loans'] . ':rows';
		$this->loan_audit_table        = $tables['loan_audit'] . ':rows';
		$this->reservations_table      = $tables['reservations'] . ':rows';
		$this->reservation_audit_table = $tables['reservation_audit'] . ':rows';
		$this->copies_table            = $tables['copies'] . ':rows';
		$this->borrowers_table         = $tables['borrowers'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ]      = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ]             = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loan_audit_table ]        = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ]      = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservation_audit_table ] = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ]            = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ]         = array();
		$GLOBALS['connectlibrary_test_db_insert_failures']                          = array();
		$GLOBALS['connectlibrary_test_db_query_results']                            = array();
		$GLOBALS['connectlibrary_test_current_user_id']                             = 1;
		$GLOBALS['connectlibrary_test_current_user_can']                            = array();
		$GLOBALS['connectlibrary_test_mail']                                        = array();
		$GLOBALS['connectlibrary_test_mail_should_fail']                            = false;

		$this->service = new AuditEventService();
	}

	// -------------------------------------------------------------------------
	// Schema — table registered
	// -------------------------------------------------------------------------

	public function test_schema_version_reflects_build_09_addition(): void {
		self::assertSame( '1.6.1', Schema::VERSION );
	}

	public function test_schema_table_names_includes_audit_events(): void {
		$tables = Schema::table_names( 'wp_' );
		self::assertArrayHasKey( 'audit_events', $tables );
		self::assertSame( 'wp_connectlibrary_audit_events', $tables['audit_events'] );
	}

	public function test_schema_sql_definitions_includes_audit_events_table(): void {
		$defs = Schema::sql_definitions(
			Schema::table_names( 'wp_' ),
			'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);

		self::assertArrayHasKey( 'audit_events', $defs );
		$sql = $defs['audit_events'];
		self::assertStringContainsString( 'CREATE TABLE', $sql );
		self::assertStringContainsString( 'correlation_id', $sql );
		self::assertStringContainsString( 'action', $sql );
		self::assertStringContainsString( 'actor_type', $sql );
		self::assertStringContainsString( 'entity_type', $sql );
		self::assertStringContainsString( 'entity_id', $sql );
		self::assertStringContainsString( 'context_json', $sql );
		self::assertStringContainsString( 'before_json', $sql );
		self::assertStringContainsString( 'after_json', $sql );
		self::assertStringContainsString( 'status', $sql );
		self::assertStringContainsString( 'summary', $sql );
		self::assertStringContainsString( 'created_at_utc', $sql );
	}

	public function test_schema_sql_includes_indexes(): void {
		$sql = Schema::sql_definitions(
			Schema::table_names( 'wp_' ),
			'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		)['audit_events'];

		self::assertStringContainsString( 'KEY action', $sql );
		self::assertStringContainsString( 'KEY entity_lookup', $sql );
		self::assertStringContainsString( 'KEY actor_id', $sql );
		self::assertStringContainsString( 'KEY correlation_id', $sql );
		self::assertStringContainsString( 'KEY created_at_utc', $sql );
	}

	// -------------------------------------------------------------------------
	// Event creation — basic
	// -------------------------------------------------------------------------

	public function test_log_returns_positive_event_id(): void {
		$id = $this->service->log(
			'checkout',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 1,
			)
		);

		self::assertGreaterThan( 0, $id );
	}

	public function test_log_stores_action_and_entity(): void {
		$this->service->log(
			'return',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 42,
				'summary'     => 'Loan 42 returned',
			)
		);

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		self::assertCount( 1, $rows );
		self::assertSame( 'return', $rows[0]['action'] );
		self::assertSame( 'loan', $rows[0]['entity_type'] );
		self::assertSame( 42, (int) $rows[0]['entity_id'] );
		self::assertSame( 'Loan 42 returned', $rows[0]['summary'] );
	}

	public function test_log_stores_correlation_id(): void {
		$corr = 'test-corr-001';
		$this->service->log(
			'checkout',
			array(
				'entity_type'    => 'loan',
				'entity_id'      => 1,
				'correlation_id' => $corr,
			)
		);

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		self::assertSame( $corr, $rows[0]['correlation_id'] );
	}

	public function test_log_stores_source_channel_and_actor(): void {
		$GLOBALS['connectlibrary_test_current_user_id'] = 5;
		$this->service->log(
			'renew',
			array(
				'entity_type'    => 'loan',
				'entity_id'      => 7,
				'source_channel' => 'admin',
			)
		);

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		self::assertSame( 'admin', $rows[0]['source_channel'] );
		self::assertSame( 5, (int) $rows[0]['actor_id'] );
		self::assertSame( 'user', $rows[0]['actor_type'] );
	}

	public function test_log_infers_system_actor_type_when_no_user(): void {
		$GLOBALS['connectlibrary_test_current_user_id'] = 0;
		$this->service->log(
			'due_reminder_sent',
			array(
				'actor_type'  => 'cron',
				'entity_type' => 'loan',
				'entity_id'   => 3,
			)
		);

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		self::assertSame( 'cron', $rows[0]['actor_type'] );
		self::assertNull( $rows[0]['actor_id'] );
	}

	public function test_log_stores_before_and_after_json(): void {
		$this->service->log(
			'due_date_change',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 10,
				'before'      => array( 'due_at' => '2026-06-20 00:00:00' ),
				'after'       => array( 'due_at' => '2026-07-04 00:00:00' ),
			)
		);

		$rows   = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		$before = json_decode( $rows[0]['before_json'], true );
		$after  = json_decode( $rows[0]['after_json'], true );
		self::assertSame( '2026-06-20 00:00:00', $before['due_at'] );
		self::assertSame( '2026-07-04 00:00:00', $after['due_at'] );
	}

	public function test_log_stores_secondary_entity(): void {
		$this->service->log(
			'waitlist_promoted',
			array(
				'entity_type'           => 'reservation',
				'entity_id'             => 5,
				'secondary_entity_type' => 'loan',
				'secondary_entity_id'   => 2,
			)
		);

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		self::assertSame( 'reservation', $rows[0]['entity_type'] );
		self::assertSame( 5, (int) $rows[0]['entity_id'] );
		self::assertSame( 'loan', $rows[0]['secondary_entity_type'] );
		self::assertSame( 2, (int) $rows[0]['secondary_entity_id'] );
	}

	// -------------------------------------------------------------------------
	// Append-only / correction linkage
	// -------------------------------------------------------------------------

	public function test_append_only_correction_creates_new_linked_event(): void {
		$corr_id = 'workflow-abc-123';

		$original_id = $this->service->log(
			'checkout',
			array(
				'entity_type'    => 'loan',
				'entity_id'      => 1,
				'correlation_id' => $corr_id,
			)
		);

		$correction_id = $this->service->log(
			'void',
			array(
				'entity_type'    => 'loan',
				'entity_id'      => 1,
				'correlation_id' => $corr_id,
				'reason'         => 'Checkout entered against wrong borrower',
			)
		);

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		self::assertCount( 2, $rows, 'Both original and correction row must exist (append-only)' );
		self::assertGreaterThan( $original_id, $correction_id, 'Correction gets a new higher ID' );
		self::assertSame( $corr_id, $rows[0]['correlation_id'] );
		self::assertSame( $corr_id, $rows[1]['correlation_id'] );
		self::assertSame( 'checkout', $rows[0]['action'] );
		self::assertSame( 'void', $rows[1]['action'] );
	}

	public function test_original_event_unchanged_after_correction(): void {
		$corr_id = 'corr-99';

		$this->service->log(
			'checkout',
			array(
				'entity_type'    => 'loan',
				'entity_id'      => 55,
				'correlation_id' => $corr_id,
				'summary'        => 'Original checkout',
			)
		);

		$this->service->log(
			'void',
			array(
				'entity_type'    => 'loan',
				'entity_id'      => 55,
				'correlation_id' => $corr_id,
				'reason'         => 'Error correction',
			)
		);

		$rows = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		self::assertSame( 'Original checkout', $rows[0]['summary'], 'Original summary must be immutable' );
		self::assertSame( 'checkout', $rows[0]['action'], 'Original action must be immutable' );
	}

	// -------------------------------------------------------------------------
	// Privacy redaction
	// -------------------------------------------------------------------------

	public function test_redact_removes_token_key(): void {
		$result = $this->service->redact(
			array(
				'token'  => 'abc123',
				'action' => 'click',
			)
		);

		self::assertSame( '[redacted]', $result['token'] );
		self::assertSame( 'click', $result['action'] );
	}

	public function test_redact_removes_token_hash(): void {
		$result = $this->service->redact(
			array(
				'token_hash' => 'sha256abc',
				'id'         => 1,
			)
		);

		self::assertSame( '[redacted]', $result['token_hash'] );
		self::assertSame( 1, $result['id'] );
	}

	public function test_redact_removes_nonce(): void {
		$result = $this->service->redact(
			array(
				'_wpnonce' => 'abc',
				'book_id'  => 5,
			)
		);

		self::assertSame( '[redacted]', $result['_wpnonce'] );
		self::assertSame( 5, $result['book_id'] );
	}

	public function test_redact_removes_password(): void {
		$result = $this->service->redact(
			array(
				'password' => 'secret',
				'username' => 'admin',
			)
		);

		self::assertSame( '[redacted]', $result['password'] );
		self::assertSame( 'admin', $result['username'] );
	}

	public function test_redact_removes_api_key(): void {
		$result = $this->service->redact(
			array(
				'api_key'  => 'key123',
				'provider' => 'isbndb',
			)
		);

		self::assertSame( '[redacted]', $result['api_key'] );
		self::assertSame( 'isbndb', $result['provider'] );
	}

	public function test_redact_removes_access_token(): void {
		$result = $this->service->redact(
			array(
				'access_token' => 'bearer-xyz',
				'user_id'      => 7,
			)
		);

		self::assertSame( '[redacted]', $result['access_token'] );
		self::assertSame( 7, $result['user_id'] );
	}

	public function test_redact_is_recursive(): void {
		$data = array(
			'user'    => array(
				'name'  => 'Alice',
				'token' => 'secret-token',
			),
			'book_id' => 42,
		);

		$result = $this->service->redact( $data );

		self::assertSame( 'Alice', $result['user']['name'] );
		self::assertSame( '[redacted]', $result['user']['token'] );
		self::assertSame( 42, $result['book_id'] );
	}

	public function test_log_redacts_context_before_storage(): void {
		$this->service->log(
			'checkout',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 1,
				'context'     => array(
					'borrower_id' => 7,
					'token_hash'  => 'should-be-redacted',
				),
			)
		);

		$rows    = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		$context = json_decode( $rows[0]['context_json'], true );
		self::assertSame( 7, $context['borrower_id'] );
		self::assertSame( '[redacted]', $context['token_hash'] );
	}

	public function test_log_redacts_before_and_after_snapshots(): void {
		$this->service->log(
			'renewal',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 2,
				'before'      => array(
					'status'   => 'active',
					'password' => 'should-not-be-here',
				),
				'after'       => array(
					'status'  => 'active',
					'api_key' => 'should-not-be-here',
				),
			)
		);

		$rows   = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ];
		$before = json_decode( $rows[0]['before_json'], true );
		$after  = json_decode( $rows[0]['after_json'], true );
		self::assertSame( '[redacted]', $before['password'] );
		self::assertSame( '[redacted]', $after['api_key'] );
	}

	// -------------------------------------------------------------------------
	// Query
	// -------------------------------------------------------------------------

	public function test_query_returns_all_when_no_filters(): void {
		$this->service->log(
			'checkout',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 1,
			)
		);
		$this->service->log(
			'return',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 1,
			)
		);

		$results = $this->service->query();
		self::assertCount( 2, $results );
	}

	public function test_query_filters_by_action(): void {
		$this->service->log(
			'checkout',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 1,
			)
		);
		$this->service->log(
			'return',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 1,
			)
		);

		$results = $this->service->query( array( 'action' => 'checkout' ) );
		self::assertCount( 1, $results );
		self::assertSame( 'checkout', $results[0]['action'] );
	}

	public function test_query_filters_by_entity(): void {
		$this->service->log(
			'checkout',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 10,
			)
		);
		$this->service->log(
			'return',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 20,
			)
		);

		$results = $this->service->query(
			array(
				'entity_type' => 'loan',
				'entity_id'   => 10,
			)
		);
		self::assertCount( 1, $results );
		self::assertSame( 10, (int) $results[0]['entity_id'] );
	}

	public function test_query_filters_by_correlation_id(): void {
		$corr = 'workflow-xyz';
		$this->service->log(
			'return',
			array(
				'entity_type'    => 'loan',
				'entity_id'      => 1,
				'correlation_id' => $corr,
			)
		);
		$this->service->log(
			'waitlist_promoted',
			array(
				'entity_type'    => 'reservation',
				'entity_id'      => 5,
				'correlation_id' => $corr,
			)
		);
		$this->service->log(
			'checkout',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 2,
			)
		);

		$results = $this->service->query( array( 'correlation_id' => $corr ) );
		self::assertCount( 2, $results );
	}

	public function test_query_respects_limit_and_offset(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->service->log(
				'checkout',
				array(
					'entity_type' => 'loan',
					'entity_id'   => $i,
				)
			);
		}

		$page1 = $this->service->query( array(), 2, 0 );
		$page2 = $this->service->query( array(), 2, 2 );

		self::assertCount( 2, $page1 );
		self::assertCount( 2, $page2 );
	}

	public function test_log_normalizes_action_group_and_safe_label(): void {
		$this->service->log(
			'checkout_override',
			array(
				'entity_type'  => 'loan',
				'entity_id'    => 77,
				'action_group' => 'Circulation',
				'safe_label'   => 'Loan #77 for Borrower #12',
				'context'      => array( 'card_token' => 'secret-token' ),
			)
		);

		$row       = $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ][0];
		$formatted = $this->service->format_safe_event( $row );

		self::assertSame( 'circulation', $formatted['action_group'] );
		self::assertSame( 'Loan #77 for Borrower #12', $formatted['safe_label'] );
		self::assertSame( '[redacted]', $formatted['context']['card_token'] );
		self::assertTrue( $formatted['has_override'] );
	}

	public function test_find_returns_single_event(): void {
		$this->service->log(
			'checkout',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 1,
			)
		);
		$this->service->log(
			'return',
			array(
				'entity_type' => 'loan',
				'entity_id'   => 1,
			)
		);

		$row = $this->service->find( 2 );

		self::assertIsArray( $row );
		self::assertSame( 'return', $row['action'] );
	}

	public function test_format_safe_event_marks_deleted_and_child_privacy_state(): void {
		$this->service->log(
			'borrower_anonymized',
			array(
				'entity_type'  => 'borrower',
				'entity_id'    => 12,
				'action_group' => 'borrowers',
				'context'      => array(
					'privacy_state'       => 'anonymized',
					'child_privacy_scope' => 'guardian_limited',
				),
				'before'       => array(
					'status'        => 'active',
					'private_notes' => 'internal',
				),
				'after'        => array( 'status' => 'anonymized' ),
			)
		);

		$formatted = $this->service->format_safe_event( $GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ][0] );

		self::assertSame( 'anonymized', $formatted['privacy_state'] );
		self::assertSame( 'guardian_limited', $formatted['context']['child_privacy_scope'] );
		self::assertSame( 'borrowers', $formatted['action_group'] );
	}

	// -------------------------------------------------------------------------
	// Correlation ID generation
	// -------------------------------------------------------------------------

	public function test_new_correlation_id_returns_valid_uuid_v4(): void {
		$id = $this->service->new_correlation_id();

		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$id,
			'Expected UUID v4 format'
		);
	}

	public function test_new_correlation_id_generates_unique_values(): void {
		$a = $this->service->new_correlation_id();
		$b = $this->service->new_correlation_id();

		self::assertNotSame( $a, $b );
	}

	// -------------------------------------------------------------------------
	// REST controller — capability check
	// -------------------------------------------------------------------------

	public function test_audit_events_controller_denies_non_admin(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			'manage_connectlibrary_borrowers' => false,
			'manage_options'                  => false,
		);

		$controller = new AuditEventsController( $this->service );
		self::assertFalse( $controller->permission_check(), 'Non-admin/librarian must be denied' );
	}

	public function test_audit_events_controller_allows_manage_options(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			'manage_options' => true,
		);

		$controller = new AuditEventsController( $this->service );
		self::assertTrue( $controller->permission_check() );
	}

	public function test_audit_events_controller_allows_manage_borrowers(): void {
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			'manage_connectlibrary_borrowers' => true,
			'manage_options'                  => false,
		);

		$controller = new AuditEventsController( $this->service );
		self::assertTrue( $controller->permission_check() );
	}

	public function test_audit_events_controller_registers_route(): void {
		$GLOBALS['connectlibrary_test_rest_routes'] = array();

		$controller = new AuditEventsController( $this->service );
		$controller->register_routes();

		$route_key = 'connectlibrary/v1/audit-events';
		self::assertArrayHasKey( $route_key, $GLOBALS['connectlibrary_test_rest_routes'] );
		self::assertSame( 'GET', $GLOBALS['connectlibrary_test_rest_routes'][ $route_key ]['methods'] );
		self::assertArrayHasKey( 'action_group', $GLOBALS['connectlibrary_test_rest_routes'][ $route_key ]['args'] );
		self::assertArrayHasKey( 'actor_type', $GLOBALS['connectlibrary_test_rest_routes'][ $route_key ]['args'] );
		self::assertArrayHasKey( 'search', $GLOBALS['connectlibrary_test_rest_routes'][ $route_key ]['args'] );
	}

	public function test_audit_events_controller_returns_safe_formatted_response(): void {
		$this->service->log(
			'report_export',
			array(
				'entity_type'    => 'report',
				'entity_id'      => 9,
				'action_group'   => 'reports',
				'safe_label'     => 'Inventory report export',
				'source_channel' => 'admin',
				'context'        => array(
					'raw_payload' => 'summary-only',
					'api_key'     => 'must-not-leak',
				),
			)
		);

		$response = ( new AuditEventsController( $this->service ) )->list_events(
			new \WP_REST_Request( array( 'action_group' => 'reports' ) )
		);
		$data     = $response->get_data();

		self::assertArrayHasKey( 'events', $data );
		self::assertCount( 1, $data['events'] );
		self::assertSame( 'reports', $data['events'][0]['action_group'] );
		self::assertSame( 'Inventory report export', $data['events'][0]['safe_label'] );
		self::assertTrue( $data['events'][0]['report_export'] );
		self::assertSame( '[redacted]', $data['events'][0]['context']['api_key'] );
	}

	// -------------------------------------------------------------------------
	// Workflow: checkout
	// -------------------------------------------------------------------------

	public function test_checkout_workflow_creates_shared_audit_event(): void {
		$loan_repo = new LoanRepository();
		$copy_repo = new CopyRepository();
		$service   = new LoanService( $loan_repo, null, $copy_repo, null, null, $this->service );

		$this->seed_copy( 1, 101, 'available', 'active' );
		$result = $service->checkout( 1, 101, 7 );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'checkout' );
		self::assertCount( 1, $events, 'Exactly one shared checkout audit event expected' );
		self::assertSame( 'loan', $events[0]['entity_type'] );
		self::assertSame( 'copy', $events[0]['secondary_entity_type'] );
		self::assertSame( 1, (int) $events[0]['secondary_entity_id'] );
	}

	// -------------------------------------------------------------------------
	// Workflow: return + waitlist promotion correlation
	// -------------------------------------------------------------------------

	public function test_return_and_waitlist_promotion_share_correlation_id(): void {
		$loan_repo    = new LoanRepository();
		$copy_repo    = new CopyRepository();
		$res_repo     = new ReservationRepository();
		$res_service  = new ReservationService( $res_repo, new BorrowerRepository(), $this->service );
		$loan_service = new LoanService( $loan_repo, $res_repo, $copy_repo, null, $res_service, $this->service );

		$this->seed_loan( 1, 101, 7, 'active' );
		$this->seed_copy( 1, 101, 'checked_out', 'active' );
		$this->seed_reservation( 1, 8, 101, ReservationStatuses::WAITLISTED );
		$this->seed_copy( 2, 101, 'available', 'active' );

		$result = $loan_service->return_copy( 1, 'staff', 1 );

		self::assertIsArray( $result );
		$return_events    = $this->audit_events_for_action( 'return' );
		$promotion_events = $this->audit_events_for_action( 'waitlist_promoted' );

		self::assertCount( 1, $return_events, 'Return event must be logged' );
		self::assertCount( 1, $promotion_events, 'Waitlist promotion event must be logged' );

		$corr_return    = $return_events[0]['correlation_id'];
		$corr_promotion = $promotion_events[0]['correlation_id'];
		self::assertNotEmpty( $corr_return );
		self::assertSame( $corr_return, $corr_promotion, 'Return and waitlist promotion must share correlation ID' );
	}

	// -------------------------------------------------------------------------
	// Workflow: renewal
	// -------------------------------------------------------------------------

	public function test_renew_workflow_creates_shared_audit_event(): void {
		$loan_repo = new LoanRepository();
		$copy_repo = new CopyRepository();
		$service   = new LoanService( $loan_repo, null, $copy_repo, null, null, $this->service );

		$this->seed_loan( 1, 101, 7, 'active', renewal_count: 0, renewal_limit: 2 );
		$result = $service->renew( 1, 7, 'self' );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'renew' );
		self::assertCount( 1, $events );
		self::assertSame( 'loan', $events[0]['entity_type'] );
		self::assertSame( 1, (int) $events[0]['entity_id'] );
	}

	// -------------------------------------------------------------------------
	// Workflow: hold / reservation
	// -------------------------------------------------------------------------

	public function test_request_hold_creates_shared_audit_event(): void {
		$res_repo = new ReservationRepository();
		$service  = new ReservationService( $res_repo, new BorrowerRepository(), $this->service );

		$this->seed_copy( 1, 101, 'available', 'active' );
		$result = $service->request_hold( 7, 101 );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'hold_requested' );
		self::assertCount( 1, $events );
		self::assertSame( 'reservation', $events[0]['entity_type'] );
	}

	public function test_guest_request_creates_shared_audit_event(): void {
		$res_repo = new ReservationRepository();
		$service  = new ReservationService( $res_repo, new BorrowerRepository(), $this->service );

		$result = $service->request_guest( 'guest@example.test', 'Jane Guest', 101 );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'guest_request' );
		self::assertCount( 1, $events );
		self::assertSame( 'guest', $events[0]['actor_type'] );
	}

	public function test_guest_request_does_not_store_email_in_context(): void {
		$res_repo = new ReservationRepository();
		$service  = new ReservationService( $res_repo, new BorrowerRepository(), $this->service );

		$service->request_guest( 'guest@example.test', 'Jane Guest', 101 );

		$events = $this->audit_events_for_action( 'guest_request' );
		self::assertCount( 1, $events );
		$context_json = $events[0]['context_json'] ?? '';
		self::assertStringNotContainsString( 'guest@example.test', (string) $context_json );
	}

	public function test_waitlist_join_creates_shared_audit_event(): void {
		$res_repo = new ReservationRepository();
		$service  = new ReservationService( $res_repo, new BorrowerRepository(), $this->service );

		$result = $service->join_waitlist( 7, 101 );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'waitlist_joined' );
		self::assertCount( 1, $events );
	}

	public function test_reservation_approve_creates_shared_audit_event(): void {
		$res_repo = new ReservationRepository();
		$service  = new ReservationService( $res_repo, new BorrowerRepository(), $this->service );

		$this->seed_reservation( 1, 7, 101, ReservationStatuses::PENDING_APPROVAL );
		$this->seed_copy( 1, 101, 'available', 'active' );
		$result = $service->approve( 1, 'Library approved' );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'reservation_approved' );
		self::assertCount( 1, $events );
		self::assertSame( 'Library approved', $events[0]['reason'] );
	}

	public function test_reservation_deny_creates_shared_audit_event(): void {
		$res_repo = new ReservationRepository();
		$service  = new ReservationService( $res_repo, new BorrowerRepository(), $this->service );

		$this->seed_reservation( 1, null, 101, ReservationStatuses::PENDING_APPROVAL );
		$result = $service->deny( 1, 'Request denied' );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'reservation_deny' );
		self::assertCount( 1, $events );
	}

	public function test_reservation_cancel_creates_shared_audit_event(): void {
		$res_repo = new ReservationRepository();
		$service  = new ReservationService( $res_repo, new BorrowerRepository(), $this->service );

		$this->seed_reservation( 1, 7, 101, ReservationStatuses::ACTIVE_HOLD );
		$result = $service->cancel( 1 );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'reservation_cancelled' );
		self::assertCount( 1, $events );
	}

	public function test_hold_expire_creates_shared_audit_event(): void {
		$res_repo = new ReservationRepository();
		$service  = new ReservationService( $res_repo, new BorrowerRepository(), $this->service );

		$this->seed_reservation( 1, 7, 101, ReservationStatuses::ACTIVE_HOLD );
		$result = $service->expire( 1 );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'hold_expired' );
		self::assertCount( 1, $events );
	}

	public function test_hold_extend_creates_shared_audit_event(): void {
		$res_repo = new ReservationRepository();
		$service  = new ReservationService( $res_repo, new BorrowerRepository(), $this->service );

		$this->seed_reservation( 1, 7, 101, ReservationStatuses::ACTIVE_HOLD );
		$result = $service->extend( 1, null, 'Patron extension request' );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'hold_extended' );
		self::assertCount( 1, $events );
		self::assertSame( 'Patron extension request', $events[0]['reason'] );
	}

	// -------------------------------------------------------------------------
	// Workflow: due reminder — sent / failed / skipped
	// -------------------------------------------------------------------------

	public function test_due_reminder_sent_creates_shared_audit_event(): void {
		$loan_repo     = new LoanRepository();
		$borrower_repo = new BorrowerRepository();
		$service       = new DueReminderService( $loan_repo, $borrower_repo, $this->service );

		$this->seed_borrower( 7, 'borrower@example.test', email_notices_allowed: 1 );
		$this->seed_loan( 1, 101, 7, 'active', due_at: '2026-06-22 09:00:00' );
		$GLOBALS['connectlibrary_test_post_objects'][101] = (object) array( 'post_title' => 'Test Book' );

		$service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		$events = $this->audit_events_for_action( 'due_reminder_sent' );
		self::assertCount( 1, $events );
		self::assertSame( 'loan', $events[0]['entity_type'] );
		self::assertSame( 1, (int) $events[0]['entity_id'] );
		self::assertSame( 'ok', $events[0]['status'] );
		self::assertSame( 'cron', $events[0]['actor_type'] );
	}

	public function test_due_reminder_failed_creates_shared_audit_event(): void {
		$GLOBALS['connectlibrary_test_mail_should_fail'] = true;
		$loan_repo                                       = new LoanRepository();
		$borrower_repo                                   = new BorrowerRepository();
		$service = new DueReminderService( $loan_repo, $borrower_repo, $this->service );

		$this->seed_borrower( 7, 'borrower@example.test', email_notices_allowed: 1 );
		$this->seed_loan( 1, 101, 7, 'active', due_at: '2026-06-22 09:00:00' );
		$GLOBALS['connectlibrary_test_post_objects'][101] = (object) array( 'post_title' => 'Test Book' );

		$service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		$events = $this->audit_events_for_action( 'due_reminder_failed' );
		self::assertCount( 1, $events );
		self::assertSame( 'failed', $events[0]['status'] );
		self::assertSame( 'mail_failed', $events[0]['error_code'] );
	}

	public function test_due_reminder_skipped_creates_shared_audit_event(): void {
		$loan_repo     = new LoanRepository();
		$borrower_repo = new BorrowerRepository();
		$service       = new DueReminderService( $loan_repo, $borrower_repo, $this->service );

		$this->seed_loan( 1, 101, 7, 'active', due_at: '2026-06-22 09:00:00' );

		$service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		$events = $this->audit_events_for_action( 'due_reminder_skipped' );
		self::assertCount( 1, $events );
		self::assertSame( 'skipped', $events[0]['status'] );
	}

	public function test_due_reminder_batch_events_share_correlation_id(): void {
		$loan_repo     = new LoanRepository();
		$borrower_repo = new BorrowerRepository();
		$service       = new DueReminderService( $loan_repo, $borrower_repo, $this->service );

		$this->seed_borrower( 7, 'b7@example.test', email_notices_allowed: 1 );
		$this->seed_borrower( 8, 'b8@example.test', email_notices_allowed: 1 );
		$this->seed_loan( 1, 101, 7, 'active', due_at: '2026-06-22 09:00:00' );
		$this->seed_loan( 2, 101, 8, 'active', due_at: '2026-06-22 10:00:00' );
		$GLOBALS['connectlibrary_test_post_objects'][101] = (object) array( 'post_title' => 'Test Book' );

		$service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		$events = $this->audit_events_for_action( 'due_reminder_sent' );
		self::assertCount( 2, $events );

		$corr1 = $events[0]['correlation_id'];
		$corr2 = $events[1]['correlation_id'];
		self::assertNotEmpty( $corr1 );
		self::assertSame( $corr1, $corr2, 'All events in the same batch must share a correlation ID' );
	}

	// -------------------------------------------------------------------------
	// Default constructors create AuditEventService
	// -------------------------------------------------------------------------

	public function test_loan_service_default_constructor_writes_audit_events(): void {
		$loan_repo = new LoanRepository();
		$copy_repo = new CopyRepository();
		// No audit_events argument — LoanService must instantiate AuditEventService internally.
		$service = new LoanService( $loan_repo, null, $copy_repo );

		$this->seed_copy( 1, 101, 'available', 'active' );
		$result = $service->checkout( 1, 101, 7 );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'checkout' );
		self::assertCount( 1, $events, 'LoanService default constructor must write audit events without explicit injection' );
	}

	public function test_reservation_service_default_constructor_writes_audit_events(): void {
		$res_repo = new ReservationRepository();
		// No audit_events argument — ReservationService must instantiate AuditEventService internally.
		$service = new ReservationService( $res_repo, new BorrowerRepository() );

		$this->seed_copy( 1, 101, 'available', 'active' );
		$result = $service->request_hold( 7, 101 );

		self::assertIsArray( $result );
		$events = $this->audit_events_for_action( 'hold_requested' );
		self::assertCount( 1, $events, 'ReservationService default constructor must write audit events without explicit injection' );
	}

	public function test_due_reminder_service_default_constructor_writes_audit_events(): void {
		$loan_repo     = new LoanRepository();
		$borrower_repo = new BorrowerRepository();
		// No audit_events argument — DueReminderService must instantiate AuditEventService internally.
		$service = new DueReminderService( $loan_repo, $borrower_repo );

		$this->seed_borrower( 7, 'borrower@example.test', email_notices_allowed: 1 );
		$this->seed_loan( 1, 101, 7, 'active', due_at: '2026-06-22 09:00:00' );
		$GLOBALS['connectlibrary_test_post_objects'][101] = (object) array( 'post_title' => 'Test Book' );

		$service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		$events = $this->audit_events_for_action( 'due_reminder_sent' );
		self::assertCount( 1, $events, 'DueReminderService default constructor must write audit events without explicit injection' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function audit_events_for_action( string $action ): array {
		return array_values(
			array_filter(
				$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_events_table ],
				static fn( array $row ): bool => (string) ( $row['action'] ?? '' ) === $action
			)
		);
	}

	private function seed_loan(
		int $id,
		int $book_post_id = 101,
		int $borrower_id = 7,
		string $status = 'active',
		string $due_at = '2026-06-22 09:00:00',
		int $renewal_count = 0,
		int $renewal_limit = 2,
		?int $copy_id = null
	): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ][] = array(
			'id'             => $id,
			'book_post_id'   => $book_post_id,
			'copy_id'        => $copy_id ?? $id,
			'borrower_id'    => $borrower_id,
			'status'         => $status,
			'due_at'         => $due_at,
			'renewal_count'  => $renewal_count,
			'renewal_limit'  => $renewal_limit,
			'checked_out_at' => '2026-06-01 10:00:00',
			'created_at'     => '2026-06-01 10:00:00',
			'updated_at'     => '2026-06-01 10:00:00',
		);
	}

	private function seed_copy(
		int $id,
		int $book_post_id,
		string $circulation_status = 'available',
		string $item_status = 'active',
		string $visibility = 'public'
	): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->copies_table ][] = array(
			'id'                 => $id,
			'book_post_id'       => $book_post_id,
			'circulation_status' => $circulation_status,
			'item_status'        => $item_status,
			'visibility'         => $visibility,
			'current_loan_id'    => null,
			'created_at'         => '2026-06-01 10:00:00',
			'updated_at'         => '2026-06-01 10:00:00',
		);
	}

	private function seed_reservation(
		int $id,
		?int $borrower_id,
		int $book_post_id,
		string $status,
		?int $copy_id = null
	): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->reservations_table ][] = array(
			'id'              => $id,
			'book_post_id'    => $book_post_id,
			'copy_id'         => $copy_id,
			'borrower_id'     => $borrower_id,
			'guest_name'      => null,
			'guest_email'     => null,
			'status'          => $status,
			'hold_expires_at' => '2026-07-05 00:00:00',
			'requested_at'    => '2026-06-01 10:00:00',
			'created_at'      => '2026-06-01 10:00:00',
			'updated_at'      => '2026-06-01 10:00:00',
		);
	}

	private function seed_borrower(
		int $id,
		string $email,
		string $status = 'active',
		int $email_notices_allowed = 1
	): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ][] = array(
			'id'                    => $id,
			'borrower_type'         => 'manual',
			'wp_user_id'            => null,
			'status'                => $status,
			'display_name'          => 'Test Borrower ' . $id,
			'preferred_name'        => null,
			'email'                 => $email,
			'phone'                 => null,
			'guardian_borrower_id'  => null,
			'guardian_name'         => null,
			'guardian_email'        => null,
			'guardian_phone'        => null,
			'guardian_relationship' => null,
			'email_notices_allowed' => $email_notices_allowed,
			'private_notes'         => null,
			'created_at'            => '2026-06-01 10:00:00',
			'updated_at'            => '2026-06-01 10:00:00',
			'created_by'            => null,
			'updated_by'            => null,
			'anonymized_at'         => null,
			'anonymized_by'         => null,
		);
	}
}
