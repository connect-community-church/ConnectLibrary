<?php
/**
 * Due-date reminder email service.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Circulation;

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Settings\CirculationDefaults;
use ConnectLibrary\Settings\Settings;

/**
 * Finds eligible due-soon loans, sends borrower reminders, and audits outcomes.
 */
final class DueReminderService {
	/**
	 * Loan repository.
	 *
	 * @var LoanRepository
	 */
	private LoanRepository $loans;

	/**
	 * Borrower repository.
	 *
	 * @var BorrowerRepository
	 */
	private BorrowerRepository $borrowers;

	/**
	 * Shared audit event service.
	 *
	 * @var AuditEventService|null
	 */
	private ?AuditEventService $audit_events;

	/**
	 * Create the service.
	 *
	 * @param LoanRepository|null     $loans        Loan repository override for tests.
	 * @param BorrowerRepository|null $borrowers    Borrower repository override for tests.
	 * @param AuditEventService|null  $audit_events Optional shared audit service.
	 */
	public function __construct( ?LoanRepository $loans = null, ?BorrowerRepository $borrowers = null, ?AuditEventService $audit_events = null ) {
		$this->loans        = $loans ?? new LoanRepository();
		$this->borrowers    = $borrowers ?? new BorrowerRepository();
		$this->audit_events = $audit_events ?? new AuditEventService();
	}

	/**
	 * Process a bounded batch of due reminders.
	 *
	 * @param int|null    $lead_days Reminder lead days; defaults to settings.
	 * @param string|null $now       Current MySQL datetime override for tests.
	 * @return array<string,int|string> Summary counts and target date.
	 */
	public function process_due_reminders( ?int $lead_days = null, ?string $now = null ): array {
		$lead_days   = null === $lead_days ? CirculationDefaults::due_reminder_lead_days() : max( 0, $lead_days );
		$now         = null !== $now && '' !== $now ? $now : current_time( 'mysql' );
		$target_date = $this->target_due_date( $lead_days, $now );
		$batch_size  = max(
			1,
			(int) apply_filters( 'connectlibrary_due_reminder_batch_size', 50, $lead_days, $target_date )
		);

		$summary = array(
			'target_date' => $target_date,
			'processed'   => 0,
			'sent'        => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'retried'     => 0,
		);

		$batch_correlation_id = null !== $this->audit_events ? $this->audit_events->new_correlation_id() : '';

		foreach ( $this->loans_due_on( $target_date ) as $loan ) {
			if ( (int) $summary['processed'] >= $batch_size ) {
				break;
			}

			++$summary['processed'];
			$result = $this->process_loan( $loan, $target_date, $batch_correlation_id );
			$status = (string) ( $result['status'] ?? '' );

			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
			if ( ! empty( $result['retried'] ) ) {
				++$summary['retried'];
			}
		}

		/**
		 * Fires after a due-reminder batch completes.
		 *
		 * @param array<string,int|string> $summary Reminder batch summary.
		 */
		do_action( 'connectlibrary_due_reminder_batch_processed', $summary );

		return $summary;
	}

	/**
	 * Process one loan and return status metadata.
	 *
	 * @param array<string,mixed> $loan           Loan row.
	 * @param string              $target_date    Due date in Y-m-d format.
	 * @param string              $correlation_id Batch correlation ID for shared audit events.
	 * @return array<string,mixed>
	 */
	public function process_loan( array $loan, string $target_date, string $correlation_id = '' ): array {
		$loan_id = (int) ( $loan['id'] ?? 0 );
		$due_at  = (string) ( $loan['due_at'] ?? '' );

		if ( $loan_id <= 0 || '' === $due_at ) {
			return array( 'status' => 'skipped' );
		}

		if ( 'active' !== (string) ( $loan['status'] ?? '' ) ) {
			$this->audit_skip( $loan_id, $due_at, 'loan_not_active', $correlation_id );
			return array( 'status' => 'skipped' );
		}

		if ( $this->has_successful_reminder( $loan_id, $due_at ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'duplicate',
			);
		}

		$borrower = $this->borrowers->get( (int) ( $loan['borrower_id'] ?? 0 ) );
		if ( null === $borrower ) {
			$this->audit_skip( $loan_id, $due_at, 'missing_borrower', $correlation_id );
			return array( 'status' => 'skipped' );
		}

		$recipient = $this->resolve_recipient( $borrower );
		if ( '' === $recipient ) {
			$this->audit_skip( $loan_id, $due_at, 'missing_recipient', $correlation_id );
			return array( 'status' => 'skipped' );
		}

		$previous_failures = $this->failure_count( $loan_id, $due_at );
		$email             = $this->build_email( $loan, $borrower, $recipient, $target_date );
		$sent              = $this->send_email( $email );

		if ( $sent ) {
			$this->loans->audit( $loan_id, 'due_reminder_sent', array( 'due_at', 'email' ), 'due_at:' . $due_at . '|channel:email' );
			if ( null !== $this->audit_events ) {
				$this->audit_events->log(
					'due_reminder_sent',
					array(
						'entity_type'    => 'loan',
						'entity_id'      => $loan_id,
						'source_channel' => 'cron',
						'actor_type'     => 'cron',
						'context'        => array(
							'due_at'      => $due_at,
							'borrower_id' => (int) ( $loan['borrower_id'] ?? 0 ),
						),
						'status'         => 'ok',
						'summary'        => 'Due reminder sent for loan ' . $loan_id,
						'correlation_id' => $correlation_id,
					)
				);
			}
			return array(
				'status'  => 'sent',
				'retried' => $previous_failures > 0,
			);
		}

		$this->loans->audit( $loan_id, 'due_reminder_failed', array( 'due_at', 'email' ), 'due_at:' . $due_at . '|reason:mail_failed|retryable:1' );
		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'due_reminder_failed',
				array(
					'entity_type'    => 'loan',
					'entity_id'      => $loan_id,
					'source_channel' => 'cron',
					'actor_type'     => 'cron',
					'context'        => array(
						'due_at'      => $due_at,
						'borrower_id' => (int) ( $loan['borrower_id'] ?? 0 ),
					),
					'status'         => 'failed',
					'error_code'     => 'mail_failed',
					'summary'        => 'Due reminder failed for loan ' . $loan_id,
					'correlation_id' => $correlation_id,
				)
			);
		}

		return array(
			'status'  => 'failed',
			'retried' => $previous_failures > 0,
		);
	}

	/**
	 * Return loan rows whose due date falls exactly on the target date.
	 *
	 * @param string $target_date Date in Y-m-d format.
	 * @return array<int,array<string,mixed>>
	 */
	public function loans_due_on( string $target_date ): array {
		return array_values(
			array_filter(
				$this->loans->all(),
				static fn ( array $loan ): bool => 'active' === (string) ( $loan['status'] ?? '' )
					&& substr( (string) ( $loan['due_at'] ?? '' ), 0, 10 ) === $target_date
			)
		);
	}

	/**
	 * Resolve the safe reminder recipient for a borrower.
	 *
	 * @param array<string,mixed> $borrower Borrower row.
	 */
	public function resolve_recipient( array $borrower ): string {
		if ( ! $this->is_borrower_reminder_allowed( $borrower ) ) {
			return '';
		}

		$guardian_email = sanitize_email( (string) ( $borrower['guardian_email'] ?? '' ) );
		if ( '' !== $guardian_email && is_email( $guardian_email ) ) {
			return $this->filter_recipient( $guardian_email, $borrower );
		}

		$guardian_id = (int) ( $borrower['guardian_borrower_id'] ?? 0 );
		if ( $guardian_id > 0 ) {
			$guardian = $this->borrowers->get( $guardian_id );
			if ( null !== $guardian ) {
				$email = sanitize_email( (string) ( $guardian['email'] ?? '' ) );
				if ( $this->is_borrower_reminder_allowed( $guardian ) && '' !== $email && is_email( $email ) ) {
					return $this->filter_recipient( $email, $borrower );
				}
			}
		}

		if ( $this->is_child_borrower( $borrower ) ) {
			return '';
		}

		$email = sanitize_email( (string) ( $borrower['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}

		return $this->filter_recipient( $email, $borrower );
	}

	/**
	 * Determine whether a borrower row may receive reminder email directly or as guardian.
	 *
	 * @param array<string,mixed> $borrower Borrower row.
	 */
	private function is_borrower_reminder_allowed( array $borrower ): bool {
		if ( 'active' !== (string) ( $borrower['status'] ?? '' ) || '' !== (string) ( $borrower['anonymized_at'] ?? '' ) ) {
			return false;
		}

		if ( array_key_exists( 'email_notices_allowed', $borrower ) && 1 !== (int) $borrower['email_notices_allowed'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Determine whether a successful reminder was already sent for the exact due date.
	 *
	 * @param int    $loan_id Loan ID.
	 * @param string $due_at  Due datetime.
	 */
	public function has_successful_reminder( int $loan_id, string $due_at ): bool {
		foreach ( $this->loans->audit_events( $loan_id ) as $event ) {
			if ( 'due_reminder_sent' !== (string) ( $event['action'] ?? '' ) ) {
				continue;
			}
			if ( str_contains( (string) ( $event['reason'] ?? '' ), 'due_at:' . $due_at ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count failed reminder attempts for an exact due date.
	 *
	 * @param int    $loan_id Loan ID.
	 * @param string $due_at  Due datetime.
	 */
	private function failure_count( int $loan_id, string $due_at ): int {
		$count = 0;
		foreach ( $this->loans->audit_events( $loan_id ) as $event ) {
			if ( 'due_reminder_failed' === (string) ( $event['action'] ?? '' ) && str_contains( (string) ( $event['reason'] ?? '' ), 'due_at:' . $due_at ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Build the email payload.
	 *
	 * @param array<string,mixed> $loan        Loan row.
	 * @param array<string,mixed> $borrower    Borrower row.
	 * @param string              $recipient   Recipient email.
	 * @param string              $target_date Due date in Y-m-d format.
	 * @return array<string,string>
	 */
	private function build_email( array $loan, array $borrower, string $recipient, string $target_date ): array {
		$book_title    = get_the_title( (int) ( $loan['book_post_id'] ?? 0 ) );
		$library_name  = (string) Settings::get( 'library_name' );
		$instructions  = (string) Settings::get( 'pickup_instructions' );
		$contact_email = CirculationDefaults::librarian_email();
		$subject       = sprintf(
			/* translators: %s: book title. */
			__( 'Library reminder: %s is due soon', 'connectlibrary' ),
			$book_title
		);
		$plain = sprintf(
			/* translators: 1: borrower name, 2: book title, 3: due date. */
			__( "Hello %1\$s,\n\nThis is a reminder that %2\$s is due on %3\$s. Please return or renew it with the library team.\n", 'connectlibrary' ),
			(string) ( $borrower['display_name'] ?? __( 'there', 'connectlibrary' ) ),
			$book_title,
			$target_date
		);
		if ( '' !== $instructions ) {
			$plain .= "\n" . $instructions . "\n";
		}
		if ( '' !== $contact_email ) {
			$plain .= "\n" . sprintf(
				/* translators: %s: librarian email address. */
				__( 'Questions? Contact %s.', 'connectlibrary' ),
				$contact_email
			) . "\n";
		}

		/* translators: %s: borrower display name. */
		$html = '<p>' . esc_html( sprintf( __( 'Hello %s,', 'connectlibrary' ), (string) ( $borrower['display_name'] ?? __( 'there', 'connectlibrary' ) ) ) ) . '</p>';
		/* translators: 1: book title, 2: due date. */
		$html .= '<p>' . esc_html( sprintf( __( 'This is a reminder that %1$s is due on %2$s.', 'connectlibrary' ), $book_title, $target_date ) ) . '</p>';
		$html .= '<p>' . esc_html__( 'Please return or renew it with the library team.', 'connectlibrary' ) . '</p>';
		if ( '' !== $instructions ) {
			$html .= '<p>' . nl2br( esc_html( $instructions ) ) . '</p>';
		}
		if ( '' !== $contact_email ) {
			/* translators: %s: librarian email address. */
			$html .= '<p>' . esc_html( sprintf( __( 'Questions? Contact %s.', 'connectlibrary' ), $contact_email ) ) . '</p>';
		}
		$html .= '<p>' . esc_html( $library_name ) . '</p>';

		$subject = (string) apply_filters( 'connectlibrary_due_reminder_subject', $subject, $loan, $borrower );
		$plain   = (string) apply_filters( 'connectlibrary_due_reminder_body_text', $plain, $loan, $borrower );
		$html    = (string) apply_filters( 'connectlibrary_due_reminder_body_html', $html, $loan, $borrower );

		return array(
			'to'      => $recipient,
			'subject' => $subject,
			'plain'   => $plain,
			'html'    => $html,
		);
	}

	/**
	 * Send a multipart reminder through WordPress mail APIs.
	 *
	 * @param array<string,string> $email Email payload.
	 */
	private function send_email( array $email ): bool {
		$boundary = 'connectlibrary_due_' . wp_generate_password( 12, false, false );
		$body     = "--{$boundary}\r\n";
		$body    .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n" . $email['plain'] . "\r\n";
		$body    .= "--{$boundary}\r\n";
		$body    .= "Content-Type: text/html; charset=UTF-8\r\n\r\n" . $email['html'] . "\r\n";
		$body    .= "--{$boundary}--";
		$headers  = array( 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' );

		add_filter( 'wp_mail_content_type', array( self::class, 'mail_content_type' ) );
		try {
			return (bool) wp_mail( $email['to'], $email['subject'], $body, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', array( self::class, 'mail_content_type' ) );
		}
	}

	/**
	 * Temporary mail content-type filter.
	 */
	public static function mail_content_type(): string {
		return 'multipart/alternative';
	}

	/**
	 * Build target due date.
	 *
	 * @param int    $lead_days Lead days.
	 * @param string $now       Current MySQL datetime.
	 */
	private function target_due_date( int $lead_days, string $now ): string {
		$timestamp = strtotime( $now . ' +' . $lead_days . ' days' );
		if ( false === $timestamp ) {
			$timestamp = time();
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Audit a sanitized skip reason.
	 *
	 * @param int    $loan_id        Loan ID.
	 * @param string $due_at         Due datetime.
	 * @param string $reason         Sanitized reason key.
	 * @param string $correlation_id Batch correlation ID.
	 */
	private function audit_skip( int $loan_id, string $due_at, string $reason, string $correlation_id = '' ): void {
		$this->loans->audit( $loan_id, 'due_reminder_skipped', array( 'due_at', 'email' ), 'due_at:' . $due_at . '|reason:' . sanitize_key( $reason ) );
		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'due_reminder_skipped',
				array(
					'entity_type'    => 'loan',
					'entity_id'      => $loan_id,
					'source_channel' => 'cron',
					'actor_type'     => 'cron',
					'context'        => array( 'due_at' => $due_at ),
					'status'         => 'skipped',
					'reason'         => sanitize_key( $reason ),
					'summary'        => 'Due reminder skipped for loan ' . $loan_id . ': ' . sanitize_key( $reason ),
					'correlation_id' => $correlation_id,
				)
			);
		}
	}

	/**
	 * Apply recipient filter and validate the result.
	 *
	 * @param string              $email    Candidate email.
	 * @param array<string,mixed> $borrower Borrower row.
	 */
	private function filter_recipient( string $email, array $borrower ): string {
		$filtered = sanitize_email( (string) apply_filters( 'connectlibrary_due_reminder_recipient', $email, $borrower ) );

		return is_email( $filtered ) ? $filtered : '';
	}

	/**
	 * Whether the borrower should only be contacted via guardian.
	 *
	 * @param array<string,mixed> $borrower Borrower row.
	 */
	private function is_child_borrower( array $borrower ): bool {
		$type = sanitize_key( (string) ( $borrower['borrower_type'] ?? '' ) );

		return in_array( $type, array( 'child', 'youth', 'minor' ), true );
	}
}
