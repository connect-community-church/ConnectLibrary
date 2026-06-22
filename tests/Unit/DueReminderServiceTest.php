<?php
/**
 * Tests for due-date reminder service and cron foundation.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing

use ConnectLibrary\Activator;
use ConnectLibrary\Circulation\DueReminderCron;
use ConnectLibrary\Circulation\DueReminderService;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Deactivator;
use ConnectLibrary\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Covers due reminder eligibility, delivery, idempotency, retry, and cron wiring.
 */
final class DueReminderServiceTest extends TestCase {
	private LoanRepository $loans;

	private BorrowerRepository $borrowers;

	private DueReminderService $service;

	private string $loans_table;

	private string $audit_table;

	private string $borrowers_table;

	protected function setUp(): void {
		$tables = Schema::table_names();

		$this->loans_table     = $tables['loans'] . ':rows';
		$this->audit_table     = $tables['loan_audit'] . ':rows';
		$this->borrowers_table = $tables['borrowers'] . ':rows';

		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ]     = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->audit_table ]     = array();
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ] = array();
		$GLOBALS['connectlibrary_test_mail']                                = array();
		$GLOBALS['connectlibrary_test_mail_should_fail']                    = false;
		$GLOBALS['connectlibrary_test_cron_events']                         = array();
		foreach ( array(
			'wp_mail_content_type',
			'connectlibrary_due_reminder_batch_size',
			'connectlibrary_due_reminder_recipient',
			'connectlibrary_due_reminder_subject',
			'connectlibrary_due_reminder_body_text',
			'connectlibrary_due_reminder_body_html',
		) as $hook ) {
			$GLOBALS['connectlibrary_test_hooks'][ $hook ] = array();
		}
		$GLOBALS['connectlibrary_test_options'][ Settings::OPTION_NAME ]    = array(
			'value'    => array_merge(
				Settings::defaults(),
				array(
					'due_reminder_lead_days' => 3,
					'librarian_email'        => 'library@example.test',
					'pickup_instructions'    => 'Return items at the church library desk.',
				)
			),
			'autoload' => false,
		);
		$GLOBALS['connectlibrary_test_post_objects'][101]                   = (object) array( 'post_title' => 'The Test Book' );

		$this->loans     = new LoanRepository();
		$this->borrowers = new BorrowerRepository();
		$this->service   = new DueReminderService( $this->loans, $this->borrowers );
	}

	public function test_processes_active_loans_due_exactly_default_lead_days_ahead(): void {
		$this->seed_borrower( 7, 'borrower@example.test' );
		$this->seed_loan( 1, borrower_id: 7, due_at: '2026-06-22 09:00:00' );
		$this->seed_loan( 2, borrower_id: 7, due_at: '2026-06-23 09:00:00' );

		$summary = $this->service->process_due_reminders( null, '2026-06-19 12:00:00' );

		self::assertSame( '2026-06-22', $summary['target_date'] );
		self::assertSame( 1, $summary['processed'] );
		self::assertSame( 1, $summary['sent'] );
		self::assertCount( 1, $GLOBALS['connectlibrary_test_mail'] );
		self::assertSame( 'borrower@example.test', $GLOBALS['connectlibrary_test_mail'][0]['to'] );
	}

	public function test_due_batch_selects_active_loans_without_closed_row_starvation(): void {
		$this->seed_borrower( 7, 'borrower@example.test' );
		$this->seed_borrower( 8, 'active@example.test' );
		$this->seed_loan( 1, borrower_id: 7, due_at: '2026-06-22 09:00:00', status: 'returned' );
		$this->seed_loan( 2, borrower_id: 7, due_at: '2026-06-22 10:00:00', status: 'lost' );
		$this->seed_loan( 3, borrower_id: 8, due_at: '2026-06-22 11:00:00' );

		add_filter( 'connectlibrary_due_reminder_batch_size', static fn(): int => 1 );

		$summary = $this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		self::assertSame( 1, $summary['processed'] );
		self::assertSame( 1, $summary['sent'] );
		self::assertCount( 1, $GLOBALS['connectlibrary_test_mail'] );
		self::assertSame( 'active@example.test', $GLOBALS['connectlibrary_test_mail'][0]['to'] );
		self::assertSame( array(), $this->loans->audit_events( 1 ) );
		self::assertSame( array(), $this->loans->audit_events( 2 ) );
	}

	public function test_active_due_loan_without_recipient_is_safely_audited_as_skipped(): void {
		$this->seed_borrower( 8, '' );
		$this->seed_loan( 2, borrower_id: 8, due_at: '2026-06-22 09:00:00' );

		$summary = $this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		self::assertSame( 1, $summary['processed'] );
		self::assertSame( 1, $summary['skipped'] );
		self::assertCount( 0, $GLOBALS['connectlibrary_test_mail'] );
		self::assertStringContainsString( 'reason:missing_recipient', $this->loans->audit_events( 2 )[0]['reason'] );
	}

	public function test_duplicate_success_is_prevented_per_loan_and_due_date(): void {
		$this->seed_borrower( 7, 'borrower@example.test' );
		$this->seed_loan( 1, borrower_id: 7, due_at: '2026-06-22 09:00:00' );

		$first  = $this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );
		$second = $this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		self::assertSame( 1, $first['sent'] );
		self::assertSame( 0, $second['sent'] );
		self::assertSame( 1, $second['skipped'] );
		self::assertCount( 1, $GLOBALS['connectlibrary_test_mail'] );
	}

	public function test_due_date_change_recalculates_duplicate_eligibility(): void {
		$this->seed_borrower( 7, 'borrower@example.test' );
		$this->seed_loan( 1, borrower_id: 7, due_at: '2026-06-22 09:00:00' );
		$this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		$this->loans->update( 1, array( 'due_at' => '2026-06-23 09:00:00' ) );
		$summary = $this->service->process_due_reminders( 4, '2026-06-19 12:00:00' );

		self::assertSame( 1, $summary['sent'] );
		self::assertCount( 2, $GLOBALS['connectlibrary_test_mail'] );
	}

	public function test_child_borrower_routes_to_guardian_email_only(): void {
		$this->seed_borrower( 7, 'child@example.test', borrower_type: 'child', guardian_email: 'guardian@example.test' );
		$this->seed_loan( 1, borrower_id: 7, due_at: '2026-06-22 09:00:00' );

		$this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		self::assertCount( 1, $GLOBALS['connectlibrary_test_mail'] );
		self::assertSame( 'guardian@example.test', $GLOBALS['connectlibrary_test_mail'][0]['to'] );
	}

	public function test_child_borrower_can_resolve_guardian_borrower_id(): void {
		$this->seed_borrower( 6, 'guardian-row@example.test' );
		$this->seed_borrower( 7, 'child@example.test', borrower_type: 'child', guardian_borrower_id: 6 );
		$this->seed_loan( 1, borrower_id: 7, due_at: '2026-06-22 09:00:00' );

		$this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		self::assertSame( 'guardian-row@example.test', $GLOBALS['connectlibrary_test_mail'][0]['to'] );
	}

	public function test_child_guardian_borrower_id_does_not_send_to_anonymized_guardian(): void {
		$this->seed_borrower( 6, 'guardian-row@example.test', anonymized_at: '2026-06-20 12:00:00' );
		$this->seed_borrower( 7, 'child@example.test', borrower_type: 'child', guardian_borrower_id: 6 );
		$this->seed_loan( 1, borrower_id: 7, due_at: '2026-06-22 09:00:00' );

		$summary = $this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		self::assertSame( 0, $summary['sent'] );
		self::assertSame( 1, $summary['skipped'] );
		self::assertCount( 0, $GLOBALS['connectlibrary_test_mail'] );
	}

	public function test_child_guardian_borrower_id_requires_active_notice_allowed_guardian(): void {
		$this->seed_borrower( 6, 'inactive-guardian@example.test', status: 'inactive' );
		$this->seed_borrower( 7, 'notices-disabled@example.test', email_notices_allowed: 0 );
		$this->seed_borrower( 8, 'child-one@example.test', borrower_type: 'child', guardian_borrower_id: 6 );
		$this->seed_borrower( 9, 'child-two@example.test', borrower_type: 'child', guardian_borrower_id: 7 );
		$this->seed_loan( 1, borrower_id: 8, due_at: '2026-06-22 09:00:00' );
		$this->seed_loan( 2, borrower_id: 9, due_at: '2026-06-22 10:00:00' );

		$summary = $this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		self::assertSame( 0, $summary['sent'] );
		self::assertSame( 2, $summary['skipped'] );
		self::assertCount( 0, $GLOBALS['connectlibrary_test_mail'] );
	}

	public function test_wp_mail_failure_logs_retryable_failure_and_later_success_retries(): void {
		$this->seed_borrower( 7, 'borrower@example.test' );
		$this->seed_loan( 1, borrower_id: 7, due_at: '2026-06-22 09:00:00' );

		$GLOBALS['connectlibrary_test_mail_should_fail'] = true;
		$failed = $this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		$GLOBALS['connectlibrary_test_mail_should_fail'] = false;
		$retry = $this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		self::assertSame( 1, $failed['failed'] );
		self::assertStringContainsString( 'reason:mail_failed', $this->loans->audit_events( 1 )[0]['reason'] );
		self::assertSame( 1, $retry['sent'] );
		self::assertSame( 1, $retry['retried'] );
	}

	public function test_email_uses_multipart_body_and_cleans_content_type_filter(): void {
		$this->seed_borrower( 7, 'borrower@example.test' );
		$this->seed_loan( 1, borrower_id: 7, due_at: '2026-06-22 09:00:00' );

		$this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		$mail = $GLOBALS['connectlibrary_test_mail'][0];
		self::assertStringContainsString( 'multipart/alternative', $mail['headers'][0] );
		self::assertStringContainsString( 'Content-Type: text/plain', $mail['message'] );
		self::assertStringContainsString( 'Content-Type: text/html', $mail['message'] );
		self::assertSame( array(), $GLOBALS['connectlibrary_test_hooks']['wp_mail_content_type'] );
	}

	public function test_filters_can_adjust_recipient_subject_bodies_and_batch_size(): void {
		$this->seed_borrower( 7, 'borrower@example.test' );
		$this->seed_borrower( 8, 'second@example.test' );
		$this->seed_loan( 1, borrower_id: 7, due_at: '2026-06-22 09:00:00' );
		$this->seed_loan( 2, borrower_id: 8, due_at: '2026-06-22 09:00:00' );

		add_filter( 'connectlibrary_due_reminder_batch_size', static fn(): int => 1 );
		add_filter( 'connectlibrary_due_reminder_recipient', static fn(): string => 'filtered@example.test' );
		add_filter( 'connectlibrary_due_reminder_subject', static fn(): string => 'Filtered subject' );
		add_filter( 'connectlibrary_due_reminder_body_text', static fn(): string => 'Filtered plain' );
		add_filter( 'connectlibrary_due_reminder_body_html', static fn(): string => '<p>Filtered html</p>' );

		$summary = $this->service->process_due_reminders( 3, '2026-06-19 12:00:00' );

		self::assertSame( 1, $summary['processed'] );
		self::assertSame( 'filtered@example.test', $GLOBALS['connectlibrary_test_mail'][0]['to'] );
		self::assertSame( 'Filtered subject', $GLOBALS['connectlibrary_test_mail'][0]['subject'] );
		self::assertStringContainsString( 'Filtered plain', $GLOBALS['connectlibrary_test_mail'][0]['message'] );
		self::assertStringContainsString( 'Filtered html', $GLOBALS['connectlibrary_test_mail'][0]['message'] );
	}

	public function test_cron_registration_activation_settings_reschedule_and_deactivation_cleanup(): void {
		DueReminderCron::register();
		self::assertArrayHasKey( DueReminderCron::HOOK, $GLOBALS['connectlibrary_test_hooks'] );

		DueReminderCron::schedule();
		self::assertCount( 1, $GLOBALS['connectlibrary_test_cron_events'] );
		self::assertSame( DueReminderCron::HOOK, $GLOBALS['connectlibrary_test_cron_events'][0]['hook'] );

		Settings::save( array( 'due_reminder_lead_days' => 5 ) );
		self::assertCount( 1, $GLOBALS['connectlibrary_test_cron_events'] );

		Deactivator::deactivate();
		self::assertSame( array(), $GLOBALS['connectlibrary_test_cron_events'] );
	}

	private function seed_borrower(
		int $id,
		string $email,
		string $status = 'active',
		string $borrower_type = 'manual',
		string $guardian_email = '',
		int $guardian_borrower_id = 0,
		int $email_notices_allowed = 1,
		string $anonymized_at = ''
	): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->borrowers_table ][] = array(
			'id'                    => $id,
			'borrower_type'         => $borrower_type,
			'status'                => $status,
			'display_name'          => 'Borrower ' . $id,
			'email'                 => $email,
			'guardian_email'        => $guardian_email,
			'guardian_borrower_id'  => $guardian_borrower_id,
			'email_notices_allowed' => $email_notices_allowed,
			'anonymized_at'         => $anonymized_at,
			'created_at'            => '2026-06-19 12:00:00',
			'updated_at'            => '2026-06-19 12:00:00',
		);
	}

	private function seed_loan(
		int $id,
		int $borrower_id,
		string $due_at,
		string $status = 'active',
		int $book_post_id = 101
	): void {
		$GLOBALS['connectlibrary_test_db_tables'][ $this->loans_table ][] = array(
			'id'              => $id,
			'book_post_id'    => $book_post_id,
			'copy_id'         => $id,
			'borrower_id'     => $borrower_id,
			'status'          => $status,
			'checked_out_at'  => '2026-06-19 12:00:00',
			'due_at'          => $due_at,
			'renewal_count'   => 0,
			'renewal_limit'   => 1,
			'due_period_days' => 14,
			'source'          => 'test',
			'created_at'      => '2026-06-19 12:00:00',
			'updated_at'      => '2026-06-19 12:00:00',
		);
	}
}
