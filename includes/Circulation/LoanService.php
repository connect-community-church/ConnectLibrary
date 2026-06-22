<?php
/**
 * Circulation loan service layer.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Circulation;

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Settings\CirculationDefaults;
use ConnectLibrary\Support\Statuses;
use WP_Error;

/**
 * Business logic for the circulation loan lifecycle.
 *
 * All modifying methods return array<string,mixed>|WP_Error.
 * No external side effects (no mail sent, no hooks fired).
 */
final class LoanService {

	/**
	 * Loan repository.
	 *
	 * @var LoanRepository
	 */
	private LoanRepository $repository;

	/**
	 * Reservation repository for waitlist checks.
	 *
	 * @var ReservationRepository
	 */
	private ReservationRepository $reservation_repo;

	/**
	 * Reservation service for hold/waitlist lifecycle transitions.
	 *
	 * @var ReservationService
	 */
	private ReservationService $reservation_service;

	/**
	 * Copy repository.
	 *
	 * @var CopyRepository
	 */
	private CopyRepository $copy_repo;

	/**
	 * Optional callable invoked on return when a waitlist exists.
	 * Signature: (int $book_post_id): ?array
	 * Returns the promoted reservation row, or null if no promotion occurred.
	 *
	 * @var callable|null
	 */
	private mixed $promotion_hook;

	/**
	 * Shared audit event service.
	 *
	 * @var AuditEventService|null
	 */
	private ?AuditEventService $audit_events;

	/**
	 * Constructor.
	 *
	 * @param LoanRepository|null        $repository          Optional override for testing.
	 * @param ReservationRepository|null $reservation_repo    Optional override for testing.
	 * @param CopyRepository|null        $copy_repo           Optional override for testing.
	 * @param callable|null              $promotion_hook      Legacy waitlist promotion callback.
	 * @param ReservationService|null    $reservation_service Optional reservation service override.
	 * @param AuditEventService|null     $audit_events        Optional shared audit service.
	 */
	public function __construct(
		?LoanRepository $repository = null,
		?ReservationRepository $reservation_repo = null,
		?CopyRepository $copy_repo = null,
		?callable $promotion_hook = null,
		?ReservationService $reservation_service = null,
		?AuditEventService $audit_events = null
	) {
		$this->repository          = $repository ?? new LoanRepository();
		$this->reservation_repo    = $reservation_repo ?? new ReservationRepository();
		$this->copy_repo           = $copy_repo ?? new CopyRepository();
		$this->promotion_hook      = $promotion_hook;
		$this->audit_events        = $audit_events ?? new AuditEventService();
		$this->reservation_service = $reservation_service ?? new ReservationService( $this->reservation_repo, null, $this->audit_events );
	}

	/**
	 * Return a single loan by ID, or null when not found.
	 *
	 * @param int $loan_id Loan ID.
	 */
	public function get( int $loan_id ): ?array {
		if ( $loan_id <= 0 ) {
			return null;
		}

		return $this->repository->get( $loan_id );
	}

	/**
	 * Return all active loans for a borrower.
	 *
	 * @param int $borrower_id Borrower ID.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function active_loans_for_borrower( int $borrower_id ): array|WP_Error {
		if ( $borrower_id <= 0 ) {
			return $this->invalid_input( __( 'borrower_id is required.', 'connectlibrary' ) );
		}

		return $this->repository->active_for_borrower( $borrower_id );
	}

	/**
	 * Renew an active loan for a specific borrower.
	 *
	 * Enforces that the loan belongs to $borrower_id and that renewal_count is
	 * still below renewal_limit. Extends due_at by 14 days from the current
	 * due_at when it is still in the future; otherwise extends from now.
	 * Writes an audit row with action 'renew'.
	 *
	 * @param int    $loan_id       Loan ID.
	 * @param int    $borrower_id   Borrower ID performing the renewal.
	 * @param string $actor_context Contextual label stored as audit reason (e.g. 'self', 'staff').
	 * @return array<string,mixed>|WP_Error Updated loan row on success.
	 */
	public function renew( int $loan_id, int $borrower_id, string $actor_context = 'self' ): array|WP_Error {
		if ( $loan_id <= 0 || $borrower_id <= 0 ) {
			return $this->invalid_input( __( 'loan_id and borrower_id are required.', 'connectlibrary' ) );
		}

		$loan = $this->repository->get( $loan_id );
		if ( null === $loan ) {
			return $this->not_found();
		}

		if ( (int) ( $loan['borrower_id'] ?? 0 ) !== $borrower_id ) {
			return new WP_Error(
				'connectlibrary_loan_wrong_borrower',
				__( 'This loan does not belong to the specified borrower.', 'connectlibrary' ),
				array( 'status' => 403 )
			);
		}

		if ( 'active' !== (string) ( $loan['status'] ?? '' ) ) {
			return new WP_Error(
				'connectlibrary_loan_not_active',
				__( 'Only active loans can be renewed.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		// Block renewals when an active waitlist exists for this book.
		$book_post_id = (int) ( $loan['book_post_id'] ?? 0 );
		if ( $book_post_id > 0 && ! empty( $this->reservation_repo->waitlisted_for_book( $book_post_id ) ) ) {
			return new WP_Error(
				'connectlibrary_loan_renewal_waitlisted',
				__( 'This loan cannot be renewed because another patron is waiting for this book.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		if ( ! $this->is_eligible_for_renewal( $loan ) ) {
			return new WP_Error(
				'connectlibrary_loan_renewal_limit',
				__( 'This loan has reached its renewal limit.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$now     = current_time( 'mysql' );
		$new_due = $this->compute_new_due_at( (string) ( $loan['due_at'] ?? '' ), $now );

		$result = $this->repository->renew_for_borrower_atomic(
			$loan_id,
			$borrower_id,
			$new_due,
			$now,
			$actor_context
		);

		if ( ! is_wp_error( $result ) && null !== $this->audit_events ) {
			$this->audit_events->log(
				'renew',
				array(
					'entity_type'    => 'loan',
					'entity_id'      => $loan_id,
					'source_channel' => $actor_context,
					'context'        => array(
						'book_post_id' => $book_post_id,
						'borrower_id'  => $borrower_id,
					),
					'after'          => array(
						'due_at'        => $new_due,
						'renewal_count' => (int) ( $result['renewal_count'] ?? 0 ),
					),
					'summary'        => 'Loan ' . $loan_id . ' renewed by borrower ' . $borrower_id,
				)
			);
		}

		return $result;
	}

	/**
	 * Check out a copy to a borrower.
	 *
	 * Pre-validates item and circulation status, computes the default due date
	 * when no override is supplied, then delegates to the atomic repo method.
	 *
	 * @param int         $copy_id       Copy ID.
	 * @param int         $book_post_id  Book post ID.
	 * @param int         $borrower_id   Borrower ID.
	 * @param string|null $due_at        Override due date (MySQL datetime). Null = +14 days.
	 * @param string      $source        Source context ('admin', 'self', etc.).
	 * @param int         $created_by    Actor user ID (0 = anonymous/system).
	 * @param string      $override_note Librarian note when due date was overridden.
	 * @return array<string,mixed>|WP_Error New loan row on success.
	 */
	public function checkout(
		int $copy_id,
		int $book_post_id,
		int $borrower_id,
		?string $due_at = null,
		string $source = 'admin',
		int $created_by = 0,
		string $override_note = ''
	): array|WP_Error {
		if ( $copy_id <= 0 || $book_post_id <= 0 || $borrower_id <= 0 ) {
			return $this->invalid_input( __( 'copy_id, book_post_id, and borrower_id are required.', 'connectlibrary' ) );
		}

		$copy = $this->copy_repo->get( $copy_id );
		if ( null === $copy ) {
			return new WP_Error(
				'connectlibrary_copy_not_found',
				__( 'Copy not found.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		if ( (int) ( $copy['book_post_id'] ?? 0 ) !== $book_post_id ) {
			return new WP_Error(
				'connectlibrary_copy_book_mismatch',
				__( 'This copy does not belong to the requested book.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		if ( Statuses::ITEM_ACTIVE !== (string) ( $copy['item_status'] ?? '' ) ) {
			return new WP_Error(
				'connectlibrary_copy_not_active',
				__( 'This copy is not active and cannot be checked out.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$checkout_reservation = $this->reservation_service->reservation_for_checkout( $copy_id, $book_post_id, $borrower_id );
		if ( is_wp_error( $checkout_reservation ) ) {
			return $checkout_reservation;
		}

		$expected_copy_status = null !== $checkout_reservation ? Statuses::COPY_ON_HOLD : Statuses::COPY_AVAILABLE;
		if ( $expected_copy_status !== (string) ( $copy['circulation_status'] ?? '' ) ) {
			return new WP_Error(
				'connectlibrary_copy_not_available',
				null !== $checkout_reservation
					? __( 'This held copy is not ready for pickup checkout.', 'connectlibrary' )
					: __( 'This copy is not available for checkout.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$now          = current_time( 'mysql' );
		$has_override = null !== $due_at && '' !== $due_at;
		$loan_days    = CirculationDefaults::loan_period_days();

		if ( ! $has_override ) {
			$due_at = $this->compute_due_at_from_now( $now, $loan_days );
		}

		$loan = $this->repository->checkout_atomic(
			$copy_id,
			$book_post_id,
			$borrower_id,
			(string) $due_at,
			$now,
			$loan_days,
			$source,
			$created_by,
			$has_override ? $override_note : '',
			$expected_copy_status
		);

		if ( is_wp_error( $loan ) ) {
			return $loan;
		}

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'checkout',
				array(
					'entity_type'           => 'loan',
					'entity_id'             => (int) ( $loan['id'] ?? 0 ),
					'secondary_entity_type' => 'copy',
					'secondary_entity_id'   => $copy_id,
					'source_channel'        => $source,
					'context'               => array(
						'book_post_id'    => $book_post_id,
						'borrower_id'     => $borrower_id,
						'due_period_days' => $loan_days,
					),
					'after'                 => array(
						'status' => 'active',
						'due_at' => $loan['due_at'] ?? '',
					),
					'summary'               => 'Checkout: copy ' . $copy_id . ' to borrower ' . $borrower_id,
				)
			);
		}

		if ( null !== $checkout_reservation ) {
			$pickup = $this->reservation_service->mark_picked_up( (int) ( $checkout_reservation['id'] ?? 0 ), $source );
			if ( is_wp_error( $pickup ) ) {
				return $pickup;
			}
			$loan['reservation_pickup'] = $pickup;
		}

		return $loan;
	}

	/**
	 * Return an active loan.
	 *
	 * Closes the loan and sets the copy's circulation status back to 'available',
	 * or to 'on_hold' when a waitlist exists for the book. The promotion_hook
	 * is called first when set; otherwise falls back to the reservation repo.
	 *
	 * @param int    $loan_id       Loan ID.
	 * @param string $actor_context Audit reason context.
	 * @param int    $returned_by   Actor user ID (0 = anonymous/system).
	 * @return array<string,mixed>|WP_Error Updated loan row on success.
	 */
	public function return_copy(
		int $loan_id,
		string $actor_context = 'staff',
		int $returned_by = 0
	): array|WP_Error {
		if ( $loan_id <= 0 ) {
			return $this->invalid_input( __( 'loan_id is required.', 'connectlibrary' ) );
		}

		$loan = $this->repository->get( $loan_id );
		if ( null === $loan ) {
			return $this->not_found();
		}

		if ( ! in_array( (string) ( $loan['status'] ?? '' ), Statuses::loan_closeable_statuses(), true ) ) {
			return new WP_Error(
				'connectlibrary_loan_not_closeable',
				__( 'Only active, overdue, or lost loans can be returned.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$book_post_id    = (int) ( $loan['book_post_id'] ?? 0 );
		$new_copy_status = Statuses::COPY_AVAILABLE;
		$copy            = $this->copy_repo->get( (int) ( $loan['copy_id'] ?? 0 ) );

		if ( null !== $copy ) {
			$blocked_status = $this->copy_unavailable_status_from_lifecycle( $copy );
			if ( null !== $blocked_status ) {
				$new_copy_status = $blocked_status;
			}
		}

		$promotion = null;

		if ( Statuses::COPY_AVAILABLE !== $new_copy_status ) {
			// Damaged/lost/retired copies must not be put on hold or made publicly available by return.
		} elseif ( null !== $this->promotion_hook && $book_post_id > 0 ) {
			$promoted = ( $this->promotion_hook )( $book_post_id );
			if ( null !== $promoted ) {
				$new_copy_status = Statuses::COPY_ON_HOLD;
				$promotion       = $promoted;
			}
		}

		$correlation_id = null !== $this->audit_events ? $this->audit_events->new_correlation_id() : '';

		$returned = $this->repository->close_loan_atomic(
			$loan_id,
			$new_copy_status,
			current_time( 'mysql' ),
			$actor_context,
			$returned_by
		);

		if ( is_wp_error( $returned ) ) {
			return $returned;
		}

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'return',
				array(
					'entity_type'    => 'loan',
					'entity_id'      => $loan_id,
					'source_channel' => $actor_context,
					'context'        => array( 'book_post_id' => $book_post_id ),
					'after'          => array( 'status' => 'returned' ),
					'summary'        => 'Loan ' . $loan_id . ' returned',
					'correlation_id' => $correlation_id,
				)
			);
		}

		if ( Statuses::COPY_AVAILABLE === $new_copy_status && $book_post_id > 0 ) {
			$promotion = $this->reservation_service->handle_copy_available( $book_post_id, $correlation_id );
			if ( null !== $promotion ) {
				$this->copy_repo->update(
					(int) ( $loan['copy_id'] ?? 0 ),
					array(
						'circulation_status' => Statuses::COPY_ON_HOLD,
						'updated_at'         => current_time( 'mysql' ),
					)
				);
			}
		}

		if ( null !== $promotion ) {
			$returned['reservation_promotion'] = $promotion;
		}

		return $returned;
	}

	/**
	 * Mark a copy as damaged.
	 *
	 * Updates item_status and, when the copy is not currently checked out,
	 * circulation_status to 'damaged'. Rejected for already-retired copies.
	 *
	 * @param int    $copy_id       Copy ID.
	 * @param string $actor_context Audit context.
	 * @return array<string,mixed>|WP_Error Updated copy row on success.
	 */
	public function mark_copy_damaged( int $copy_id, string $actor_context = 'staff' ): array|WP_Error {
		return $this->update_copy_lifecycle_status( $copy_id, Statuses::COPY_DAMAGED, Statuses::ITEM_DAMAGED, 'copy_damaged', $actor_context );
	}

	/**
	 * Mark a copy as lost.
	 *
	 * Updates item_status and, when the copy is not currently checked out,
	 * circulation_status to 'lost'. Rejected for already-retired copies.
	 *
	 * @param int    $copy_id       Copy ID.
	 * @param string $actor_context Audit context.
	 * @return array<string,mixed>|WP_Error Updated copy row on success.
	 */
	public function mark_copy_lost( int $copy_id, string $actor_context = 'staff' ): array|WP_Error {
		return $this->update_copy_lifecycle_status( $copy_id, Statuses::COPY_LOST, Statuses::ITEM_LOST, 'copy_lost', $actor_context );
	}

	/**
	 * Permanently retire a copy.
	 *
	 * Sets item_status and circulation_status to 'retired'. Rejected when the
	 * copy is currently checked out (must be returned first).
	 *
	 * @param int    $copy_id       Copy ID.
	 * @param string $actor_context Audit context.
	 * @return array<string,mixed>|WP_Error Updated copy row on success.
	 */
	public function mark_copy_retired( int $copy_id, string $actor_context = 'staff' ): array|WP_Error {
		$copy = $this->copy_repo->get( $copy_id );
		if ( null === $copy ) {
			return new WP_Error(
				'connectlibrary_copy_not_found',
				__( 'Copy not found.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		if ( Statuses::COPY_CHECKED_OUT === (string) ( $copy['circulation_status'] ?? '' ) ) {
			return new WP_Error(
				'connectlibrary_copy_checked_out',
				__( 'A checked-out copy cannot be retired. Return it first.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$this->copy_repo->update(
			$copy_id,
			array(
				'item_status'        => Statuses::ITEM_RETIRED,
				'circulation_status' => Statuses::COPY_RETIRED,
				'updated_at'         => current_time( 'mysql' ),
			)
		);

		$updated = $this->copy_repo->get( $copy_id );
		if ( null === $updated ) {
			return $this->not_found();
		}

		$this->audit_copy_lifecycle_change( $updated, 'copy_retired', array( 'item_status', 'circulation_status' ), $actor_context );

		return $updated;
	}

	/**
	 * Void a loan record and store the correction note.
	 *
	 * Voidable from any non-voided status. History is preserved; the loan row
	 * is never deleted. A non-empty correction_note is required.
	 *
	 * @param int    $loan_id         Loan ID.
	 * @param string $correction_note Reason for the correction (required).
	 * @param string $actor_context   Audit reason context.
	 * @return array<string,mixed>|WP_Error Updated loan row on success.
	 */
	public function void_loan( int $loan_id, string $correction_note, string $actor_context = 'staff' ): array|WP_Error {
		if ( $loan_id <= 0 ) {
			return $this->invalid_input( __( 'loan_id is required.', 'connectlibrary' ) );
		}

		if ( '' === trim( $correction_note ) ) {
			return $this->invalid_input( __( 'A correction note is required to void a loan.', 'connectlibrary' ) );
		}

		$result = $this->repository->void_loan( $loan_id, $correction_note, $actor_context );

		if ( ! is_wp_error( $result ) && null !== $this->audit_events ) {
			$this->audit_events->log(
				'void',
				array(
					'entity_type'    => 'loan',
					'entity_id'      => $loan_id,
					'source_channel' => $actor_context,
					'after'          => array( 'status' => 'voided' ),
					'reason'         => $correction_note,
					'summary'        => 'Loan ' . $loan_id . ' voided',
				)
			);
		}

		return $result;
	}

	/**
	 * Change the due date of an active loan without consuming a renewal.
	 *
	 * Stores the old and new due dates, the actor, and an optional reason in
	 * the loan audit log. Renewal count is not incremented.
	 *
	 * @param int    $loan_id       Loan ID.
	 * @param string $new_due_at    New due date (MySQL datetime).
	 * @param string $reason        Optional librarian reason.
	 * @param string $actor_context Audit context label (e.g. 'admin').
	 * @return array<string,mixed>|WP_Error Updated loan row on success.
	 */
	public function change_due_date(
		int $loan_id,
		string $new_due_at,
		string $reason = '',
		string $actor_context = 'staff'
	): array|WP_Error {
		if ( $loan_id <= 0 ) {
			return $this->invalid_input( __( 'loan_id is required.', 'connectlibrary' ) );
		}

		if ( '' === trim( $new_due_at ) ) {
			return $this->invalid_input( __( 'new_due_at is required.', 'connectlibrary' ) );
		}

		$loan = $this->repository->get( $loan_id );
		if ( null === $loan ) {
			return $this->not_found();
		}

		if ( 'active' !== (string) ( $loan['status'] ?? '' ) ) {
			return new WP_Error(
				'connectlibrary_loan_not_active',
				__( 'Only active loans can have their due date changed.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$old_due_at = (string) ( $loan['due_at'] ?? '' );
		$now        = current_time( 'mysql' );

		$result = $this->repository->change_due_at_atomic(
			$loan_id,
			$new_due_at,
			$old_due_at,
			$now,
			$actor_context,
			sanitize_text_field( $reason )
		);

		if ( ! is_wp_error( $result ) && null !== $this->audit_events ) {
			$this->audit_events->log(
				'due_date_change',
				array(
					'entity_type'    => 'loan',
					'entity_id'      => $loan_id,
					'source_channel' => $actor_context,
					'before'         => array( 'due_at' => $old_due_at ),
					'after'          => array( 'due_at' => $new_due_at ),
					'reason'         => $reason,
					'summary'        => 'Due date changed on loan ' . $loan_id,
				)
			);
		}

		return $result;
	}

	/**
	 * Whether a loan is eligible for another renewal.
	 *
	 * Returns false when the renewal count has reached the limit, or when an
	 * active waitlist exists for the loan's book.
	 *
	 * @param array<string,mixed> $loan Loan row.
	 */
	public function is_eligible_for_renewal( array $loan ): bool {
		if ( (int) ( $loan['renewal_count'] ?? 0 ) >= (int) ( $loan['renewal_limit'] ?? 0 ) ) {
			return false;
		}

		$book_post_id = (int) ( $loan['book_post_id'] ?? 0 );
		if ( $book_post_id > 0 && ! empty( $this->reservation_repo->waitlisted_for_book( $book_post_id ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Compute the new due_at for a renewal.
	 *
	 * Extends from current due_at when it is still in the future; otherwise
	 * extends from $now so an already-overdue loan gets a fresh loan-period window.
	 * Period is read from CirculationDefaults at renewal time so library setting
	 * changes apply to the next renewal without rewriting existing loan records.
	 *
	 * @param string $current_due_at Current due_at (MySQL datetime).
	 * @param string $now            Current time (MySQL datetime).
	 */
	private function compute_new_due_at( string $current_due_at, string $now ): string {
		$base = ( '' !== $current_due_at && $current_due_at > $now ) ? $current_due_at : $now;
		$ts   = strtotime( $base );

		return date( 'Y-m-d H:i:s', (int) $ts + CirculationDefaults::loan_period_days() * 86400 ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	/**
	 * Compute a due date loan_period_days from a given timestamp.
	 *
	 * @param string $now      Base MySQL datetime.
	 * @param int    $days     Loan period days (pre-read by caller to keep a single read per checkout).
	 */
	private function compute_due_at_from_now( string $now, int $days = 0 ): string {
		$ts = strtotime( $now );

		if ( $days <= 0 ) {
			$days = CirculationDefaults::loan_period_days();
		}

		return date( 'Y-m-d H:i:s', (int) $ts + $days * 86400 ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	/**
	 * Update copy item_status and conditionally circulation_status.
	 *
	 * When the copy is currently checked out, only item_status is updated so
	 * the active loan remains intact. Rejected for already-retired copies.
	 *
	 * @param int    $copy_id             Copy ID.
	 * @param string $circulation_status  New circulation_status (used when not checked-out).
	 * @param string $item_status         New item_status.
	 * @param string $audit_action        Audit action key.
	 * @param string $actor_context       Audit context/reason.
	 * @return array<string,mixed>|WP_Error Updated copy row on success.
	 */
	private function update_copy_lifecycle_status( int $copy_id, string $circulation_status, string $item_status, string $audit_action, string $actor_context ): array|WP_Error {
		$copy = $this->copy_repo->get( $copy_id );
		if ( null === $copy ) {
			return new WP_Error(
				'connectlibrary_copy_not_found',
				__( 'Copy not found.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		if ( Statuses::ITEM_RETIRED === (string) ( $copy['item_status'] ?? '' ) ) {
			return new WP_Error(
				'connectlibrary_copy_retired',
				__( 'A retired copy cannot be modified.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$updates = array(
			'item_status' => $item_status,
			'updated_at'  => current_time( 'mysql' ),
		);

		if ( Statuses::COPY_CHECKED_OUT !== (string) ( $copy['circulation_status'] ?? '' ) ) {
			$updates['circulation_status'] = $circulation_status;
		}

		$this->copy_repo->update( $copy_id, $updates );

		$updated = $this->copy_repo->get( $copy_id );
		if ( null === $updated ) {
			return $this->not_found();
		}

		$changed_fields = array( 'item_status' );
		if ( array_key_exists( 'circulation_status', $updates ) ) {
			$changed_fields[] = 'circulation_status';
		}

		$this->audit_copy_lifecycle_change( $updated, $audit_action, $changed_fields, $actor_context );

		return $updated;
	}

	/**
	 * Map item/copy lifecycle state to a non-public circulation status.
	 *
	 * @param array<string,mixed> $copy Copy row.
	 */
	private function copy_unavailable_status_from_lifecycle( array $copy ): ?string {
		$item_status        = (string) ( $copy['item_status'] ?? '' );
		$circulation_status = (string) ( $copy['circulation_status'] ?? '' );

		foreach ( array( Statuses::COPY_DAMAGED, Statuses::COPY_LOST, Statuses::COPY_RETIRED ) as $blocked_status ) {
			if ( $blocked_status === $item_status || $blocked_status === $circulation_status ) {
				return $blocked_status;
			}
		}

		return null;
	}

	/**
	 * Write a durable audit row for a copy lifecycle mutation.
	 *
	 * The existing Build 06 schema has a loan audit table rather than a separate
	 * copy-audit table. Copy lifecycle events are stored there, linked to the
	 * current loan when one exists or loan_id 0 for shelf copy mutations; the
	 * changed fields payload includes copy_id so the record remains traceable.
	 *
	 * @param array<string,mixed> $copy           Updated copy row.
	 * @param string              $action         Audit action key.
	 * @param array<string>       $changed_fields Changed copy fields.
	 * @param string              $actor_context  Audit context/reason.
	 */
	private function audit_copy_lifecycle_change( array $copy, string $action, array $changed_fields, string $actor_context ): void {
		$loan_id = (int) ( $copy['current_loan_id'] ?? 0 );
		$fields  = array_merge( array( 'copy_id:' . (int) ( $copy['id'] ?? 0 ) ), $changed_fields );

		$this->repository->audit( $loan_id, $action, $fields, $actor_context );

		if ( null !== $this->audit_events ) {
			$copy_id = (int) ( $copy['id'] ?? 0 );
			$context = array( 'book_post_id' => (int) ( $copy['book_post_id'] ?? 0 ) );
			$after   = array();
			foreach ( $changed_fields as $field ) {
				if ( array_key_exists( $field, $copy ) ) {
					$after[ $field ] = $copy[ $field ];
				}
			}
			$params = array(
				'entity_type'    => 'copy',
				'entity_id'      => $copy_id,
				'source_channel' => $actor_context,
				'context'        => $context,
				'after'          => $after,
				'summary'        => $action . ': copy ' . $copy_id,
			);
			if ( $loan_id > 0 ) {
				$params['secondary_entity_type'] = 'loan';
				$params['secondary_entity_id']   = $loan_id;
			}
			$this->audit_events->log( $action, $params );
		}
	}

	/** Loan not found error. */
	private function not_found(): WP_Error {
		return new WP_Error(
			'connectlibrary_loan_not_found',
			__( 'Loan not found.', 'connectlibrary' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Invalid input error.
	 *
	 * @param string $message Error message.
	 */
	private function invalid_input( string $message ): WP_Error {
		return new WP_Error(
			'connectlibrary_loan_invalid',
			$message,
			array( 'status' => 400 )
		);
	}
}
