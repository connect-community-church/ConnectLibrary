<?php
/**
 * Reservation/hold service layer.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Reservations;

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Settings\CirculationDefaults;
use ConnectLibrary\Support\Statuses;
use WP_Error;

/**
 * Business logic for the reservation and hold lifecycle.
 *
 * All modifying methods return an array{reservation:array,notification:?array}
 * where `notification` is a safe seam payload (no mail is sent here).
 */
final class ReservationService {

	/**
	 * Reservation repository.
	 *
	 * @var ReservationRepository
	 */
	private ReservationRepository $repository;

	/**
	 * Borrower repository.
	 *
	 * @var BorrowerRepository
	 */
	private BorrowerRepository $borrower_repo;

	/**
	 * Shared audit event service.
	 *
	 * @var AuditEventService|null
	 */
	private ?AuditEventService $audit_events;

	/**
	 * Constructor.
	 *
	 * @param ReservationRepository|null $repository    Optional override for testing.
	 * @param BorrowerRepository|null    $borrower_repo Optional override for testing.
	 * @param AuditEventService|null     $audit_events  Optional shared audit service.
	 */
	public function __construct(
		?ReservationRepository $repository = null,
		?BorrowerRepository $borrower_repo = null,
		?AuditEventService $audit_events = null
	) {
		$this->repository    = $repository ?? new ReservationRepository();
		$this->borrower_repo = $borrower_repo ?? new BorrowerRepository();
		$this->audit_events  = $audit_events ?? new AuditEventService();
	}

	/**
	 * Create an active hold for a logged-in borrower.
	 *
	 * Fails with 409 if a non-terminal reservation already exists for this
	 * borrower/book, or if no active/public copy is available.
	 *
	 * @param int                 $borrower_id  Borrower ID.
	 * @param int                 $book_post_id Book post ID.
	 * @param array<string,mixed> $opts         Optional: notes, context.
	 * @return array<string,mixed>|WP_Error
	 */
	public function request_hold( int $borrower_id, int $book_post_id, array $opts = array() ): array|WP_Error {
		if ( $borrower_id <= 0 || $book_post_id <= 0 ) {
			return $this->invalid_input( __( 'borrower_id and book_post_id are required.', 'connectlibrary' ) );
		}

		if ( ! empty( $this->repository->non_terminal_for_borrower_book( $borrower_id, $book_post_id ) ) ) {
			return new WP_Error(
				'connectlibrary_reservation_duplicate',
				__( 'An active reservation already exists for this borrower and book.', 'connectlibrary' ),
				array( 'status' => 409 )
			);
		}

		$copy = $this->first_free_copy( $book_post_id );
		if ( null === $copy ) {
			return new WP_Error(
				'connectlibrary_reservation_no_copy',
				__( 'No available copy for this book.', 'connectlibrary' ),
				array( 'status' => 409 )
			);
		}

		$now = current_time( 'mysql' );
		$row = array(
			'book_post_id'    => $book_post_id,
			'copy_id'         => (int) $copy['id'],
			'borrower_id'     => $borrower_id,
			'status'          => ReservationStatuses::ACTIVE_HOLD,
			'hold_expires_at' => $this->hold_expires_at(),
			'requested_at'    => $now,
			'created_at'      => $now,
			'updated_at'      => $now,
			'acted_by'        => $this->current_user_id_or_null(),
			'notes'           => $this->nullable_text( $opts['notes'] ?? null ),
			'context'         => $this->nullable_text( $opts['context'] ?? null ),
		);

		$id = $this->repository->insert( $row );
		$this->repository->audit( $id, 'request_hold', '', ReservationStatuses::ACTIVE_HOLD );

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'hold_requested',
				array(
					'entity_type'    => 'reservation',
					'entity_id'      => $id,
					'source_channel' => $this->nullable_text( $opts['context'] ?? null ) ?? 'frontend',
					'context'        => array(
						'book_post_id' => $book_post_id,
						'borrower_id'  => $borrower_id,
					),
					'after'          => array( 'status' => ReservationStatuses::ACTIVE_HOLD ),
					'summary'        => 'Hold requested by borrower ' . $borrower_id . ' for book ' . $book_post_id,
				)
			);
		}

		$reservation = $this->repository->get( $id );
		$borrower    = $this->borrower_repo->get( $borrower_id );

		return array(
			'reservation'  => $reservation,
			'notification' => $this->resolve_notification( $reservation, $borrower, 'hold_placed' ),
		);
	}

	/**
	 * Create a pending approval request for a guest (no copy blocked).
	 *
	 * Fails with 409 if a non-terminal reservation already exists for this
	 * guest email/book combination.
	 *
	 * @param string              $guest_email  Guest email address.
	 * @param string              $guest_name   Guest display name.
	 * @param int                 $book_post_id Book post ID.
	 * @param array<string,mixed> $opts         Optional: notes, context.
	 * @return array<string,mixed>|WP_Error
	 */
	public function request_guest( string $guest_email, string $guest_name, int $book_post_id, array $opts = array() ): array|WP_Error {
		$email = sanitize_email( $guest_email );
		if ( '' === $email || ! is_email( $email ) ) {
			return $this->invalid_input( __( 'A valid guest email is required.', 'connectlibrary' ) );
		}

		$name = sanitize_text_field( $guest_name );
		if ( '' === $name ) {
			return $this->invalid_input( __( 'Guest name is required.', 'connectlibrary' ) );
		}

		if ( $book_post_id <= 0 ) {
			return $this->invalid_input( __( 'book_post_id is required.', 'connectlibrary' ) );
		}

		if ( ! empty( $this->repository->non_terminal_for_guest_book( $email, $book_post_id ) ) ) {
			return new WP_Error(
				'connectlibrary_reservation_duplicate',
				__( 'An active reservation already exists for this email and book.', 'connectlibrary' ),
				array( 'status' => 409 )
			);
		}

		$now = current_time( 'mysql' );
		$row = array(
			'book_post_id' => $book_post_id,
			'guest_name'   => $name,
			'guest_email'  => $email,
			'status'       => ReservationStatuses::PENDING_APPROVAL,
			'requested_at' => $now,
			'created_at'   => $now,
			'updated_at'   => $now,
			'notes'        => $this->nullable_text( $opts['notes'] ?? null ),
			'context'      => $this->nullable_text( $opts['context'] ?? null ),
		);

		$id = $this->repository->insert( $row );
		$this->repository->audit( $id, 'request_guest', '', ReservationStatuses::PENDING_APPROVAL );

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'guest_request',
				array(
					'entity_type'    => 'reservation',
					'entity_id'      => $id,
					'actor_type'     => 'guest',
					'source_channel' => 'frontend',
					'context'        => array( 'book_post_id' => $book_post_id ),
					'after'          => array( 'status' => ReservationStatuses::PENDING_APPROVAL ),
					'summary'        => 'Guest reservation request for book ' . $book_post_id,
				)
			);
		}

		$reservation = $this->repository->get( $id );

		return array(
			'reservation'  => $reservation,
			'notification' => $this->resolve_notification( $reservation, null, 'guest_request_received' ),
		);
	}

	/**
	 * Create a waitlist entry for a logged-in borrower when no copy is available.
	 *
	 * Fails with 409 if a non-terminal reservation already exists for this
	 * borrower/book combination.
	 *
	 * @param int                 $borrower_id  Borrower ID.
	 * @param int                 $book_post_id Book post ID.
	 * @param array<string,mixed> $opts         Optional: notes, context.
	 * @return array<string,mixed>|WP_Error
	 */
	public function join_waitlist( int $borrower_id, int $book_post_id, array $opts = array() ): array|WP_Error {
		if ( $borrower_id <= 0 || $book_post_id <= 0 ) {
			return $this->invalid_input( __( 'borrower_id and book_post_id are required.', 'connectlibrary' ) );
		}

		if ( ! empty( $this->repository->non_terminal_for_borrower_book( $borrower_id, $book_post_id ) ) ) {
			return new WP_Error(
				'connectlibrary_reservation_duplicate',
				__( 'An active reservation already exists for this borrower and book.', 'connectlibrary' ),
				array( 'status' => 409 )
			);
		}

		$now = current_time( 'mysql' );
		$row = array(
			'book_post_id' => $book_post_id,
			'borrower_id'  => $borrower_id,
			'status'       => ReservationStatuses::WAITLISTED,
			'requested_at' => $now,
			'created_at'   => $now,
			'updated_at'   => $now,
			'acted_by'     => $this->current_user_id_or_null(),
			'notes'        => $this->nullable_text( $opts['notes'] ?? null ),
			'context'      => $this->nullable_text( $opts['context'] ?? null ),
		);

		$id = $this->repository->insert( $row );
		$this->repository->audit( $id, 'join_waitlist', '', ReservationStatuses::WAITLISTED );

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'waitlist_joined',
				array(
					'entity_type'    => 'reservation',
					'entity_id'      => $id,
					'source_channel' => $this->nullable_text( $opts['context'] ?? null ) ?? 'frontend',
					'context'        => array(
						'book_post_id' => $book_post_id,
						'borrower_id'  => $borrower_id,
					),
					'after'          => array( 'status' => ReservationStatuses::WAITLISTED ),
					'summary'        => 'Borrower ' . $borrower_id . ' joined waitlist for book ' . $book_post_id,
				)
			);
		}

		$reservation = $this->repository->get( $id );
		$borrower    = $this->borrower_repo->get( $borrower_id );

		return array(
			'reservation'  => $reservation,
			'notification' => $this->resolve_notification( $reservation, $borrower, 'waitlist_joined' ),
		);
	}

	/**
	 * Approve a pending_approval reservation.
	 *
	 * If a copy is available, transitions to active_hold. If no copy is
	 * available, transitions to waitlisted so the request enters the queue
	 * rather than failing. The approval timestamp becomes the queue position.
	 *
	 * @param int    $id     Reservation ID.
	 * @param string $reason Optional reason text.
	 * @return array<string,mixed>|WP_Error
	 */
	public function approve( int $id, string $reason = '' ): array|WP_Error {
		$reservation = $this->repository->get( $id );
		if ( null === $reservation ) {
			return $this->not_found();
		}

		$from = (string) ( $reservation['status'] ?? '' );
		if ( ReservationStatuses::PENDING_APPROVAL !== $from ) {
			return new WP_Error(
				'connectlibrary_reservation_invalid_transition',
				__( 'Only pending_approval reservations can be approved.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$copy = $this->first_free_copy( (int) $reservation['book_post_id'] );

		if ( null !== $copy ) {
			$to = ReservationStatuses::ACTIVE_HOLD;
			$this->repository->update(
				$id,
				array(
					'status'          => $to,
					'copy_id'         => (int) $copy['id'],
					'hold_expires_at' => $this->hold_expires_at(),
					'updated_at'      => current_time( 'mysql' ),
					'acted_by'        => $this->current_user_id_or_null(),
				)
			);
			$notify_type = 'hold_approved';
		} else {
			// No copy available: place in waitlist. Approval time becomes queue position.
			$to = ReservationStatuses::WAITLISTED;
			$this->repository->update(
				$id,
				array(
					'status'       => $to,
					'requested_at' => current_time( 'mysql' ),
					'updated_at'   => current_time( 'mysql' ),
					'acted_by'     => $this->current_user_id_or_null(),
				)
			);
			$notify_type = 'waitlist_approved';
		}

		$this->repository->audit( $id, 'approve', $from, $to, $reason );

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'reservation_approved',
				array(
					'entity_type'    => 'reservation',
					'entity_id'      => $id,
					'source_channel' => 'admin',
					'before'         => array( 'status' => $from ),
					'after'          => array( 'status' => $to ),
					'reason'         => $reason,
					'summary'        => 'Reservation ' . $id . ' approved (→ ' . $to . ')',
				)
			);
		}

		$reservation = $this->repository->get( $id );
		$borrower    = ! empty( $reservation['borrower_id'] ) ? $this->borrower_repo->get( (int) $reservation['borrower_id'] ) : null;

		return array(
			'reservation'  => $reservation,
			'notification' => $this->resolve_notification( $reservation, $borrower, $notify_type ),
		);
	}

	/**
	 * Deny a pending_approval reservation.
	 *
	 * @param int    $id     Reservation ID.
	 * @param string $reason Optional reason text.
	 * @return array<string,mixed>|WP_Error
	 */
	public function deny( int $id, string $reason = '' ): array|WP_Error {
		return $this->terminal_transition( $id, ReservationStatuses::DENIED, 'deny', $reason );
	}

	/**
	 * Cancel a reservation from any non-terminal state that permits cancellation.
	 *
	 * When cancelling an active_hold, promotes the next waitlisted entry for
	 * the same book if a copy becomes available.
	 *
	 * @param int    $id     Reservation ID.
	 * @param string $reason Optional reason text.
	 * @return array<string,mixed>|WP_Error
	 */
	public function cancel( int $id, string $reason = '' ): array|WP_Error {
		$reservation = $this->repository->get( $id );
		if ( null === $reservation ) {
			return $this->not_found();
		}

		$from = (string) ( $reservation['status'] ?? '' );
		$to   = ReservationStatuses::CANCELLED;

		if ( ! ReservationStatuses::can_transition( $from, $to ) ) {
			return new WP_Error(
				'connectlibrary_reservation_invalid_transition',
				sprintf(
					/* translators: 1: current status, 2: target status */
					__( 'Cannot transition reservation from %1$s to %2$s.', 'connectlibrary' ),
					$from,
					$to
				),
				array( 'status' => 422 )
			);
		}

		$this->repository->update(
			$id,
			array(
				'status'     => $to,
				'updated_at' => current_time( 'mysql' ),
				'acted_by'   => $this->current_user_id_or_null(),
			)
		);
		$this->repository->audit( $id, 'cancel', $from, $to, $reason );

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'reservation_cancelled',
				array(
					'entity_type'    => 'reservation',
					'entity_id'      => $id,
					'source_channel' => 'admin',
					'before'         => array( 'status' => $from ),
					'after'          => array( 'status' => $to ),
					'reason'         => $reason,
					'summary'        => 'Reservation ' . $id . ' cancelled',
				)
			);
		}

		$promotion = null;
		if ( ReservationStatuses::ACTIVE_HOLD === $from ) {
			$book_post_id = (int) ( $reservation['book_post_id'] ?? 0 );
			if ( $book_post_id > 0 ) {
				$promotion = $this->promote_next_waitlisted( $book_post_id, '' );
			}
		}

		return array(
			'reservation'  => $this->repository->get( $id ),
			'notification' => null,
			'promotion'    => $promotion,
		);
	}

	/**
	 * Extend the hold expiry for an active_hold reservation.
	 *
	 * @param int         $id         Reservation ID.
	 * @param string|null $expires_at Explicit expiry (Y-m-d H:i:s). Defaults to +14 days from now.
	 * @param string      $reason     Optional reason text.
	 * @return array<string,mixed>|WP_Error
	 */
	public function extend( int $id, ?string $expires_at = null, string $reason = '' ): array|WP_Error {
		$reservation = $this->repository->get( $id );
		if ( null === $reservation ) {
			return $this->not_found();
		}

		if ( ReservationStatuses::ACTIVE_HOLD !== (string) ( $reservation['status'] ?? '' ) ) {
			return new WP_Error(
				'connectlibrary_reservation_invalid_transition',
				__( 'Only active_hold reservations can be extended.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$new_expiry = null !== $expires_at ? $expires_at : $this->hold_expires_at();
		$this->repository->update(
			$id,
			array(
				'hold_expires_at' => $new_expiry,
				'updated_at'      => current_time( 'mysql' ),
				'acted_by'        => $this->current_user_id_or_null(),
			)
		);
		$this->repository->audit( $id, 'extend', ReservationStatuses::ACTIVE_HOLD, ReservationStatuses::ACTIVE_HOLD, $reason );

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'hold_extended',
				array(
					'entity_type'    => 'reservation',
					'entity_id'      => $id,
					'source_channel' => 'admin',
					'after'          => array( 'hold_expires_at' => $new_expiry ),
					'reason'         => $reason,
					'summary'        => 'Hold extended for reservation ' . $id,
				)
			);
		}

		return array(
			'reservation'  => $this->repository->get( $id ),
			'notification' => null,
		);
	}

	/**
	 * Expire a single active_hold reservation.
	 *
	 * After expiry, promotes the next waitlisted entry for the same book when
	 * a copy becomes available.
	 *
	 * @param int    $id     Reservation ID.
	 * @param string $reason Optional reason text.
	 * @return array<string,mixed>|WP_Error
	 */
	public function expire( int $id, string $reason = '' ): array|WP_Error {
		$reservation = $this->repository->get( $id );
		if ( null === $reservation ) {
			return $this->not_found();
		}

		if ( ReservationStatuses::ACTIVE_HOLD !== (string) ( $reservation['status'] ?? '' ) ) {
			return new WP_Error(
				'connectlibrary_reservation_invalid_transition',
				__( 'Only active_hold reservations can be expired.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$book_post_id = (int) ( $reservation['book_post_id'] ?? 0 );

		$this->repository->update(
			$id,
			array(
				'status'     => ReservationStatuses::EXPIRED,
				'updated_at' => current_time( 'mysql' ),
				'acted_by'   => $this->current_user_id_or_null(),
			)
		);
		$this->repository->audit( $id, 'expire', ReservationStatuses::ACTIVE_HOLD, ReservationStatuses::EXPIRED, $reason );

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'hold_expired',
				array(
					'entity_type'    => 'reservation',
					'entity_id'      => $id,
					'source_channel' => 'system',
					'actor_type'     => 'system',
					'before'         => array( 'status' => ReservationStatuses::ACTIVE_HOLD ),
					'after'          => array( 'status' => ReservationStatuses::EXPIRED ),
					'reason'         => $reason,
					'summary'        => 'Hold expired for reservation ' . $id,
				)
			);
		}

		$promotion = $book_post_id > 0 ? $this->promote_next_waitlisted( $book_post_id, '' ) : null;

		return array(
			'reservation'  => $this->repository->get( $id ),
			'notification' => null,
			'promotion'    => $promotion,
		);
	}

	/**
	 * Expire all active_hold reservations where hold_expires_at has passed.
	 *
	 * Seam for a scheduled task or admin action. Returns the count of expired holds.
	 */
	public function expire_due_holds(): int {
		$now   = current_time( 'mysql' );
		$count = 0;

		foreach ( $this->repository->all() as $row ) {
			if ( ReservationStatuses::ACTIVE_HOLD !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			$expires_at = (string) ( $row['hold_expires_at'] ?? '' );
			if ( '' === $expires_at || $expires_at > $now ) {
				continue;
			}
			$this->expire( (int) $row['id'] );
			++$count;
		}

		return $count;
	}

	/**
	 * Promote the next waitlisted entry for a book when a copy is available.
	 *
	 * Idempotent: returns null when there is nothing to promote or no free copy.
	 * FIFO ordering is enforced by requested_at in waitlisted_for_book().
	 *
	 * @param int    $book_post_id   Book post ID.
	 * @param string $correlation_id Optional correlation ID to tag the shared audit event.
	 * @return array<string,mixed>|null Promoted reservation and notification, or null.
	 */
	public function promote_next_waitlisted( int $book_post_id, string $correlation_id = '' ): ?array {
		if ( $book_post_id <= 0 ) {
			return null;
		}

		$copy = $this->first_free_copy( $book_post_id );
		if ( null === $copy ) {
			return null;
		}

		$queue = $this->repository->waitlisted_for_book( $book_post_id );
		if ( empty( $queue ) ) {
			return null;
		}

		$next = $queue[0];
		$id   = (int) ( $next['id'] ?? 0 );
		if ( $id <= 0 ) {
			return null;
		}

		// Idempotency guard: verify still WAITLISTED before promoting.
		$current = $this->repository->get( $id );
		if ( null === $current || ReservationStatuses::WAITLISTED !== (string) ( $current['status'] ?? '' ) ) {
			return null;
		}

		$this->repository->update(
			$id,
			array(
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'copy_id'         => (int) $copy['id'],
				'hold_expires_at' => $this->hold_expires_at(),
				'updated_at'      => current_time( 'mysql' ),
				'acted_by'        => $this->current_user_id_or_null(),
			)
		);
		$this->repository->audit( $id, 'promote_waitlist', ReservationStatuses::WAITLISTED, ReservationStatuses::ACTIVE_HOLD );

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'waitlist_promoted',
				array(
					'entity_type'    => 'reservation',
					'entity_id'      => $id,
					'source_channel' => 'system',
					'actor_type'     => 'system',
					'context'        => array( 'book_post_id' => $book_post_id ),
					'before'         => array( 'status' => ReservationStatuses::WAITLISTED ),
					'after'          => array( 'status' => ReservationStatuses::ACTIVE_HOLD ),
					'summary'        => 'Reservation ' . $id . ' promoted from waitlist',
					'correlation_id' => $correlation_id,
				)
			);
		}

		$promoted = $this->repository->get( $id );
		$borrower = ! empty( $promoted['borrower_id'] ) ? $this->borrower_repo->get( (int) $promoted['borrower_id'] ) : null;

		return array(
			'reservation'  => $promoted,
			'notification' => $this->resolve_notification( $promoted, $borrower, 'waitlist_offer' ),
		);
	}

	/**
	 * Promote the next waitlisted entry after a copy becomes available.
	 *
	 * This is the integration seam for the future circulation check-in/return
	 * workflow: once that workflow records the returned copy as active/public and
	 * available for its book, it must call this method to create the automatic
	 * offer hold. Until Item 07 adds check-in, tests cover this seam directly so
	 * the waitlist promotion contract is explicit and reusable.
	 *
	 * @param int    $book_post_id   Book post ID whose copy availability changed.
	 * @param string $correlation_id Optional correlation ID to link the promotion to a parent workflow.
	 * @return array<string,mixed>|null Promoted reservation and notification, or null.
	 */
	public function handle_copy_available( int $book_post_id, string $correlation_id = '' ): ?array {
		return $this->promote_next_waitlisted( $book_post_id, $correlation_id );
	}

	/**
	 * Validate whether a circulation checkout may proceed for a borrower/copy.
	 *
	 * Returns the matching active_hold row when checkout should fulfill a hold,
	 * null when there is no reservation involvement, or WP_Error when the copy is
	 * held for someone else or the borrower is still waiting for this book.
	 *
	 * @param int $copy_id      Copy ID being checked out.
	 * @param int $book_post_id Book post ID.
	 * @param int $borrower_id  Borrower ID.
	 * @return array<string,mixed>|WP_Error|null
	 */
	public function reservation_for_checkout( int $copy_id, int $book_post_id, int $borrower_id ): array|WP_Error|null {
		if ( $copy_id <= 0 || $book_post_id <= 0 || $borrower_id <= 0 ) {
			return null;
		}

		foreach ( $this->repository->active_holds_for_book( $book_post_id ) as $hold ) {
			if ( (int) ( $hold['copy_id'] ?? 0 ) !== $copy_id ) {
				continue;
			}

			if ( (int) ( $hold['borrower_id'] ?? 0 ) !== $borrower_id ) {
				return new WP_Error(
					'connectlibrary_reservation_copy_held_for_other',
					__( 'This copy is reserved for another borrower.', 'connectlibrary' ),
					array( 'status' => 409 )
				);
			}

			return $hold;
		}

		foreach ( $this->repository->non_terminal_for_borrower_book( $borrower_id, $book_post_id ) as $reservation ) {
			$status = (string) ( $reservation['status'] ?? '' );
			if ( ReservationStatuses::WAITLISTED === $status || ReservationStatuses::PENDING_APPROVAL === $status ) {
				return new WP_Error(
					'connectlibrary_reservation_not_ready_for_pickup',
					__( 'This borrower has a reservation for this book, but it is not ready for pickup.', 'connectlibrary' ),
					array( 'status' => 409 )
				);
			}

			if ( ReservationStatuses::ACTIVE_HOLD === $status ) {
				return new WP_Error(
					'connectlibrary_reservation_different_copy_held',
					__( 'This borrower has a different copy held for pickup.', 'connectlibrary' ),
					array( 'status' => 409 )
				);
			}
		}

		return null;
	}

	/**
	 * Mark the matching active_hold as picked up after checkout succeeds.
	 *
	 * @param int    $reservation_id Reservation ID.
	 * @param string $reason         Optional audit reason.
	 * @return array<string,mixed>|WP_Error
	 */
	public function mark_picked_up( int $reservation_id, string $reason = '' ): array|WP_Error {
		$reservation = $this->repository->get( $reservation_id );
		if ( null === $reservation ) {
			return $this->not_found();
		}

		$from = (string) ( $reservation['status'] ?? '' );
		$to   = ReservationStatuses::PICKED_UP;
		if ( ! ReservationStatuses::can_transition( $from, $to ) ) {
			return new WP_Error(
				'connectlibrary_reservation_invalid_transition',
				__( 'Only active holds can be picked up.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$this->repository->update(
			$reservation_id,
			array(
				'status'     => $to,
				'updated_at' => current_time( 'mysql' ),
				'acted_by'   => $this->current_user_id_or_null(),
			)
		);
		$this->repository->audit( $reservation_id, 'pickup', $from, $to, $reason );

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'reservation_picked_up',
				array(
					'entity_type'    => 'reservation',
					'entity_id'      => $reservation_id,
					'source_channel' => '' !== $reason ? $reason : 'admin',
					'before'         => array( 'status' => $from ),
					'after'          => array( 'status' => $to ),
					'summary'        => 'Reservation ' . $reservation_id . ' picked up',
				)
			);
		}

		return array(
			'reservation'  => $this->repository->get( $reservation_id ),
			'notification' => null,
		);
	}

	/**
	 * Return pending guest requests for protected librarian admin screens.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function pending_guest_requests(): array {
		return array_values(
			array_filter(
				$this->repository->by_status( ReservationStatuses::PENDING_APPROVAL ),
				static fn( array $row ): bool => '' !== (string) ( $row['guest_email'] ?? '' )
			)
		);
	}

	/**
	 * Return active waitlist entries for protected librarian admin screens.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function active_waitlist_entries(): array {
		return $this->repository->by_status( ReservationStatuses::WAITLISTED );
	}

	/**
	 * Return active pickup holds for protected librarian admin screens.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function active_pickup_holds(): array {
		return $this->repository->by_status( ReservationStatuses::ACTIVE_HOLD );
	}

	/**
	 * Return active holds for operational reports with constrained paging.
	 *
	 * @param array<string,mixed> $filters Supported: from, to, status.
	 * @param int                 $limit   Maximum rows.
	 * @param int                 $offset  Pagination offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function report_pickup_holds( array $filters, int $limit, int $offset ): array {
		return $this->report_reservations_by_status( ReservationStatuses::ACTIVE_HOLD, $filters, $limit, $offset, 'hold_expires_at' );
	}

	/**
	 * Return waitlist entries for operational reports with constrained paging.
	 *
	 * @param array<string,mixed> $filters Supported: from, to, status.
	 * @param int                 $limit   Maximum rows.
	 * @param int                 $offset  Pagination offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function report_waitlist_entries( array $filters, int $limit, int $offset ): array {
		return $this->report_reservations_by_status( ReservationStatuses::WAITLISTED, $filters, $limit, $offset, 'requested_at' );
	}

	/**
	 * Shared report reservation filter/sort/page helper.
	 *
	 * @param string              $default_status Default status.
	 * @param array<string,mixed> $filters        Filters.
	 * @param int                 $limit          Maximum rows.
	 * @param int                 $offset         Pagination offset.
	 * @param string              $date_key       Date field to filter/sort.
	 * @return array<int,array<string,mixed>>
	 */
	private function report_reservations_by_status( string $default_status, array $filters, int $limit, int $offset, string $date_key ): array {
		$status = sanitize_key( (string) ( $filters['status'] ?? $default_status ) );
		if ( '' === $status ) {
			$status = $default_status;
		}

		return $this->repository->report_by_status( $status, $filters, $limit, $offset, $date_key );
	}

	/**
	 * Shared helper for deny and cancel transitions.
	 *
	 * @param int    $id     Reservation ID.
	 * @param string $to     Target terminal status.
	 * @param string $action Audit action key.
	 * @param string $reason Optional reason text.
	 * @return array<string,mixed>|WP_Error
	 */
	private function terminal_transition( int $id, string $to, string $action, string $reason = '' ): array|WP_Error {
		$reservation = $this->repository->get( $id );
		if ( null === $reservation ) {
			return $this->not_found();
		}

		$from = (string) ( $reservation['status'] ?? '' );
		if ( ! ReservationStatuses::can_transition( $from, $to ) ) {
			return new WP_Error(
				'connectlibrary_reservation_invalid_transition',
				sprintf(
					/* translators: 1: current status, 2: target status */
					__( 'Cannot transition reservation from %1$s to %2$s.', 'connectlibrary' ),
					$from,
					$to
				),
				array( 'status' => 422 )
			);
		}

		$this->repository->update(
			$id,
			array(
				'status'     => $to,
				'updated_at' => current_time( 'mysql' ),
				'acted_by'   => $this->current_user_id_or_null(),
			)
		);
		$this->repository->audit( $id, $action, $from, $to, $reason );

		if ( null !== $this->audit_events ) {
			$this->audit_events->log(
				'reservation_' . $action,
				array(
					'entity_type'    => 'reservation',
					'entity_id'      => $id,
					'source_channel' => 'admin',
					'before'         => array( 'status' => $from ),
					'after'          => array( 'status' => $to ),
					'reason'         => $reason,
					'summary'        => 'Reservation ' . $id . ' ' . $action . 'd (→ ' . $to . ')',
				)
			);
		}

		return array(
			'reservation'  => $this->repository->get( $id ),
			'notification' => null,
		);
	}

	/**
	 * Find the first unheld active/public/available copy for a book.
	 *
	 * Returns null if all copies are currently under active_hold, unavailable for
	 * circulation, or no copies exist.
	 *
	 * @param int $book_post_id Book post ID.
	 */
	private function first_free_copy( int $book_post_id ): ?array {
		$copies        = $this->repository->active_public_copies_for_book( $book_post_id );
		$held_copy_ids = array_map(
			'intval',
			array_column( $this->repository->active_holds_for_book( $book_post_id ), 'copy_id' )
		);

		foreach ( $copies as $copy ) {
			if (
				Statuses::COPY_AVAILABLE === (string) ( $copy['circulation_status'] ?? '' )
				&& ! in_array( (int) ( $copy['id'] ?? 0 ), $held_copy_ids, true )
			) {
				return $copy;
			}
		}

		return null;
	}

	/**
	 * Compute hold_expires_at using the configured hold period from settings.
	 *
	 * Period is read at hold-creation time; later setting changes do not
	 * retroactively alter already-saved hold_expires_at values.
	 */
	private function hold_expires_at(): string {
		$ts   = strtotime( current_time( 'mysql' ) );
		$days = CirculationDefaults::hold_period_days();

		return date( 'Y-m-d H:i:s', (int) $ts + $days * 86400 ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	/**
	 * Build the notification seam payload.
	 *
	 * Routes child borrower notifications to their guardian email when available.
	 * For guest reservations (no borrower), routes to guest_email.
	 * No mail is sent; this payload is returned for callers to act on.
	 *
	 * @param array<string,mixed>|null $reservation Reservation row.
	 * @param array<string,mixed>|null $borrower    Borrower row, or null for guests.
	 * @param string                   $type        Notification type key.
	 * @return array<string,mixed>|null
	 */
	private function resolve_notification( ?array $reservation, ?array $borrower, string $type ): ?array {
		if ( null === $reservation ) {
			return null;
		}

		$to = null;
		if ( null !== $borrower ) {
			$guardian_email = (string) ( $borrower['guardian_email'] ?? '' );
			if ( '' !== $guardian_email ) {
				$to = $guardian_email;
			} elseif ( '' !== (string) ( $borrower['email'] ?? '' ) ) {
				$to = (string) $borrower['email'];
			}
		} elseif ( '' !== (string) ( $reservation['guest_email'] ?? '' ) ) {
			$to = (string) $reservation['guest_email'];
		}

		// Add librarian_to for guest requests and approval-needed notices so callers
		// can route the librarian copy. Never expose in public/borrower-facing output.
		$librarian_types = array( 'guest_request_received', 'hold_approved', 'waitlist_offer' );
		$librarian_to    = in_array( $type, $librarian_types, true ) ? CirculationDefaults::librarian_email() : '';

		return array(
			'to'           => $to,
			'librarian_to' => $librarian_to,
			'type'         => $type,
			'payload'      => array(
				'reservation_id'  => (int) ( $reservation['id'] ?? 0 ),
				'book_post_id'    => (int) ( $reservation['book_post_id'] ?? 0 ),
				'hold_expires_at' => $reservation['hold_expires_at'] ?? null,
			),
		);
	}

	/** Current WordPress user ID, or null when not logged in. */
	private function current_user_id_or_null(): ?int {
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;

		return $user_id > 0 ? $user_id : null;
	}

	/** Reservation not found error. */
	private function not_found(): WP_Error {
		return new WP_Error(
			'connectlibrary_reservation_not_found',
			__( 'Reservation not found.', 'connectlibrary' ),
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
			'connectlibrary_reservation_invalid',
			$message,
			array( 'status' => 400 )
		);
	}

	/**
	 * Nullable sanitized text field.
	 *
	 * @param mixed $value Raw value.
	 */
	private function nullable_text( mixed $value ): ?string {
		$text = sanitize_text_field( (string) ( $value ?? '' ) );

		return '' === $text ? null : $text;
	}
}
