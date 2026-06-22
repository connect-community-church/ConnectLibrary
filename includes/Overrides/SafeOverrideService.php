<?php
/**
 * Safe librarian override service.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Overrides;

// phpcs:disable Squiz.Commenting.VariableComment.Missing,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment.ParamNameNoMatch,Squiz.Commenting.FunctionComment.IncorrectTypeHint

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Circulation\CopyRepository;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Circulation\LoanService;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Support\Statuses;
use WP_Error;

/**
 * Normalizes protected librarian overrides across circulation families.
 */
final class SafeOverrideService {
	private LoanService $loan_service;
	private LoanRepository $loan_repo;
	private CopyRepository $copy_repo;
	private ReservationService $reservation_service;
	private ReservationRepository $reservation_repo;
	private AuditEventService $audit;

	public function __construct(
		?LoanService $loan_service = null,
		?LoanRepository $loan_repo = null,
		?CopyRepository $copy_repo = null,
		?ReservationService $reservation_service = null,
		?ReservationRepository $reservation_repo = null,
		?AuditEventService $audit = null
	) {
		$this->loan_repo           = $loan_repo ?? new LoanRepository();
		$this->copy_repo           = $copy_repo ?? new CopyRepository();
		$this->audit               = $audit ?? new AuditEventService();
		$this->reservation_repo    = $reservation_repo ?? new ReservationRepository();
		$this->reservation_service = $reservation_service ?? new ReservationService( $this->reservation_repo, null, $this->audit );
		$this->loan_service        = $loan_service ?? new LoanService( $this->loan_repo, $this->reservation_repo, $this->copy_repo, null, $this->reservation_service, $this->audit );
	}

	/**
	 * Change an active loan due date through an explicit override.
	 *
	 * @param int                 $loan_id Loan ID.
	 * @param array<string,mixed> $data    Request data.
	 * @return array<string,mixed>|WP_Error
	 */
	public function override_due_date( int $loan_id, array $data ): array|WP_Error {
		$confirmed = $this->confirmation_required( $data, 'due_date' );
		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}

		$new_due_at = $this->mysql_datetime_from_request( (string) ( $data['new_due_at'] ?? $data['due_at'] ?? '' ) );
		if ( '' === $new_due_at ) {
			return $this->invalid( __( 'new_due_at is required.', 'connectlibrary' ) );
		}

		$before = $this->loan_repo->get( $loan_id );
		if ( null === $before ) {
			return $this->not_found( 'loan' );
		}

		$reason      = $this->reason( $data );
		$source      = $this->source( $data );
		$correlation = $this->correlation( $data );
		$result      = $this->loan_service->change_due_date( $loan_id, $new_due_at, $reason, $source );
		if ( is_wp_error( $result ) ) {
			$this->log_failure( 'override_due_date', 'due_date', 'loan', $loan_id, $result, $data, $source, $correlation );
			return $result;
		}

		$this->log_success(
			'override_due_date',
			'due_date',
			'loan',
			$loan_id,
			array( 'due_at' => (string) ( $before['due_at'] ?? '' ) ),
			array( 'due_at' => (string) ( $result['due_at'] ?? '' ) ),
			$data,
			$source,
			$correlation,
			$reason,
			__( 'Due date override applied.', 'connectlibrary' )
		);

		return $this->response( 'due_date', $result, $correlation );
	}

	/**
	 * Change a copy lifecycle status through a safe override.
	 *
	 * @param int                 $copy_id Copy ID.
	 * @param array<string,mixed> $data    Request data.
	 * @return array<string,mixed>|WP_Error
	 */
	public function override_copy_status( int $copy_id, array $data ): array|WP_Error {
		$confirmed = $this->confirmation_required( $data, 'copy_status' );
		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}

		$status = sanitize_key( (string) ( $data['status'] ?? '' ) );
		if ( ! in_array( $status, array( Statuses::ITEM_LOST, Statuses::ITEM_DAMAGED, Statuses::ITEM_ACTIVE, Statuses::ITEM_RETIRED ), true ) ) {
			return $this->invalid( __( 'Copy status must be lost, damaged, active, or retired.', 'connectlibrary' ) );
		}

		$before = $this->copy_repo->get( $copy_id );
		if ( null === $before ) {
			return $this->not_found( 'copy' );
		}

		$active_loan = $this->loan_repo->active_for_copy( $copy_id );
		if ( null !== $active_loan && in_array( $status, array( Statuses::ITEM_ACTIVE, Statuses::ITEM_RETIRED ), true ) ) {
			return new WP_Error( 'connectlibrary_override_copy_active_loan', __( 'Return or correct the active loan before restoring or retiring this copy.', 'connectlibrary' ), array( 'status' => 409 ) );
		}

		$reason      = $this->reason( $data );
		$source      = $this->source( $data );
		$correlation = $this->correlation( $data );
		$result      = match ( $status ) {
			Statuses::ITEM_LOST    => $this->loan_service->mark_copy_lost( $copy_id, $source ),
			Statuses::ITEM_DAMAGED => $this->loan_service->mark_copy_damaged( $copy_id, $source ),
			Statuses::ITEM_RETIRED => $this->loan_service->mark_copy_retired( $copy_id, $source ),
			default                => $this->restore_copy( $copy_id ),
		};

		if ( is_wp_error( $result ) ) {
			$this->log_failure( 'override_copy_status', 'copy_status', 'copy', $copy_id, $result, $data, $source, $correlation );
			return $result;
		}

		$this->log_success(
			'override_copy_status',
			'copy_status',
			'copy',
			$copy_id,
			$this->copy_snapshot( $before ),
			$this->copy_snapshot( $result ),
			$data,
			$source,
			$correlation,
			$reason,
			__( 'Copy status override applied.', 'connectlibrary' )
		);

		return $this->response( 'copy_status', $result, $correlation );
	}

	/**
	 * Change hold expiry/status through a safe override.
	 *
	 * @param int                 $reservation_id Reservation ID.
	 * @param array<string,mixed> $data           Request data.
	 * @return array<string,mixed>|WP_Error
	 */
	public function override_hold( int $reservation_id, array $data ): array|WP_Error {
		$confirmed = $this->confirmation_required( $data, 'hold_expiry' );
		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}

		$operation = sanitize_key( (string) ( $data['operation'] ?? '' ) );
		if ( ! in_array( $operation, array( 'extend', 'shorten', 'expire', 'reinstate' ), true ) ) {
			return $this->invalid( __( 'Hold operation must be extend, shorten, expire, or reinstate.', 'connectlibrary' ) );
		}

		$before = $this->reservation_repo->get( $reservation_id );
		if ( null === $before ) {
			return $this->not_found( 'reservation' );
		}

		$reason      = $this->reason( $data );
		$source      = $this->source( $data );
		$correlation = $this->correlation( $data );
		$expires_at  = $this->mysql_datetime_from_request( (string) ( $data['hold_expires_at'] ?? $data['expires_at'] ?? '' ) );
		$direction   = $this->validate_hold_expiry_direction( $operation, $before, $expires_at );
		if ( is_wp_error( $direction ) ) {
			$this->log_failure( 'override_hold_expiry', 'hold_expiry', 'reservation', $reservation_id, $direction, $data, $source, $correlation );
			return $direction;
		}
		$result = match ( $operation ) {
			'extend'   => $this->reservation_service->extend( $reservation_id, '' !== $expires_at ? $expires_at : null, $reason ),
			'shorten'  => $this->reservation_service->extend( $reservation_id, $expires_at, $reason ),
			'expire'   => $this->reservation_service->expire( $reservation_id, $reason ),
			default    => $this->reinstate_hold( $reservation_id, $expires_at, $reason ),
		};

		if ( is_wp_error( $result ) ) {
			$this->log_failure( 'override_hold_expiry', 'hold_expiry', 'reservation', $reservation_id, $result, $data, $source, $correlation );
			return $result;
		}

		$after = $result['reservation'] ?? $this->reservation_repo->get( $reservation_id );
		$this->log_success(
			'override_hold_expiry',
			'hold_expiry',
			'reservation',
			$reservation_id,
			$this->reservation_snapshot( $before ),
			is_array( $after ) ? $this->reservation_snapshot( $after ) : array(),
			$data,
			$source,
			$correlation,
			$reason,
			__( 'Hold override applied.', 'connectlibrary' )
		);

		return $this->response( 'hold_expiry', $result, $correlation );
	}

	/**
	 * Apply a manual circulation correction without deleting history.
	 *
	 * @param int                 $loan_id Loan ID.
	 * @param array<string,mixed> $data    Request data.
	 * @return array<string,mixed>|WP_Error
	 */
	public function override_loan_correction( int $loan_id, array $data ): array|WP_Error {
		$confirmed = $this->confirmation_required( $data, 'loan_correction' );
		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}

		$operation = sanitize_key( (string) ( $data['operation'] ?? '' ) );
		if ( ! in_array( $operation, array( 'void', 'return' ), true ) ) {
			return $this->invalid( __( 'Correction operation must be void or return.', 'connectlibrary' ) );
		}

		$before = $this->loan_repo->get( $loan_id );
		if ( null === $before ) {
			return $this->not_found( 'loan' );
		}

		$reason = $this->reason( $data );
		if ( '' === $reason ) {
			return $this->invalid( __( 'A reason is required for loan corrections.', 'connectlibrary' ) );
		}

		$source      = $this->source( $data );
		$correlation = $this->correlation( $data );
		$result      = 'void' === $operation
			? $this->loan_service->void_loan( $loan_id, $reason, $source )
			: $this->loan_service->return_copy( $loan_id, $source, $this->actor_id() );

		if ( is_wp_error( $result ) ) {
			$this->log_failure( 'override_loan_correction', 'loan_correction', 'loan', $loan_id, $result, $data, $source, $correlation );
			return $result;
		}

		$this->log_success(
			'override_loan_correction',
			'loan_correction',
			'loan',
			$loan_id,
			$this->loan_snapshot( $before ),
			$this->loan_snapshot( $result ),
			$data,
			$source,
			$correlation,
			$reason,
			__( 'Loan correction override applied.', 'connectlibrary' )
		);

		return $this->response( 'loan_correction', $result, $correlation );
	}

	/** @param array<string,mixed> $data */
	private function confirmation_required( array $data, string $family ): bool|WP_Error {
		$confirmed = (string) ( $data['confirm_override'] ?? $data['confirmed'] ?? '' );
		if ( '1' === $confirmed || 'true' === strtolower( $confirmed ) || 'CONFIRM' === $confirmed ) {
			return true;
		}

		return new WP_Error(
			'connectlibrary_override_confirmation_required',
			sprintf(
				/* translators: %s: override family. */
				__( 'Explicit confirmation is required for %s overrides.', 'connectlibrary' ),
				$family
			),
			array(
				'status'      => 400,
				'family'      => $family,
				'consequence' => $this->consequence_text( $family ),
			)
		);
	}

	private function restore_copy( int $copy_id ): array|WP_Error {
		$copy = $this->copy_repo->get( $copy_id );
		if ( null === $copy ) {
			return $this->not_found( 'copy' );
		}

		$this->copy_repo->update(
			$copy_id,
			array(
				'item_status'        => Statuses::ITEM_ACTIVE,
				'circulation_status' => Statuses::COPY_AVAILABLE,
				'updated_at'         => current_time( 'mysql' ),
			)
		);

		return $this->copy_repo->get( $copy_id ) ?? $this->not_found( 'copy' );
	}

	private function validate_hold_expiry_direction( string $operation, array $reservation, string $expires_at ): bool|WP_Error {
		if ( ! in_array( $operation, array( 'extend', 'shorten' ), true ) ) {
			return true;
		}

		$current_expires_at = $this->mysql_datetime_from_request( (string) ( $reservation['hold_expires_at'] ?? '' ) );
		if ( '' === $current_expires_at ) {
			return $this->invalid( __( 'The current hold_expires_at is required before changing hold expiry.', 'connectlibrary' ) );
		}

		if ( 'shorten' === $operation && '' === $expires_at ) {
			return $this->invalid( __( 'hold_expires_at is required when shortening a hold.', 'connectlibrary' ) );
		}

		if ( '' === $expires_at ) {
			return true;
		}

		if ( 'shorten' === $operation && $expires_at >= $current_expires_at ) {
			return new WP_Error( 'connectlibrary_hold_shorten_not_earlier', __( 'Shortening a hold requires a hold_expires_at earlier than the current expiry.', 'connectlibrary' ), array( 'status' => 422 ) );
		}

		if ( 'extend' === $operation && $expires_at <= $current_expires_at ) {
			return new WP_Error( 'connectlibrary_hold_extend_not_later', __( 'Extending a hold requires a hold_expires_at later than the current expiry.', 'connectlibrary' ), array( 'status' => 422 ) );
		}

		return true;
	}

	private function reinstate_hold( int $reservation_id, string $expires_at, string $reason ): array|WP_Error {
		$reservation = $this->reservation_repo->get( $reservation_id );
		if ( null === $reservation ) {
			return $this->not_found( 'reservation' );
		}
		if ( ReservationStatuses::EXPIRED !== (string) ( $reservation['status'] ?? '' ) ) {
			return new WP_Error( 'connectlibrary_hold_reinstate_invalid_status', __( 'Only expired holds can be reinstated.', 'connectlibrary' ), array( 'status' => 422 ) );
		}
		$copy_id = (int) ( $reservation['copy_id'] ?? 0 );
		$copy    = $copy_id > 0 ? $this->copy_repo->get( $copy_id ) : null;
		if (
			null === $copy
			|| Statuses::ITEM_ACTIVE !== (string) ( $copy['item_status'] ?? '' )
			|| Statuses::COPY_AVAILABLE !== (string) ( $copy['circulation_status'] ?? '' )
			|| null !== $this->loan_repo->active_for_copy( $copy_id )
		) {
			return new WP_Error( 'connectlibrary_hold_reinstate_conflict', __( 'This hold cannot be reinstated because its copy is unavailable.', 'connectlibrary' ), array( 'status' => 409 ) );
		}

		$expires_at = '' !== $expires_at ? $expires_at : gmdate( 'Y-m-d H:i:s', time() + 14 * 86400 );
		$this->reservation_repo->update(
			$reservation_id,
			array(
				'status'          => ReservationStatuses::ACTIVE_HOLD,
				'hold_expires_at' => $expires_at,
				'updated_at'      => current_time( 'mysql' ),
				'acted_by'        => $this->actor_id(),
			)
		);
		$this->reservation_repo->audit( $reservation_id, 'reinstate', ReservationStatuses::EXPIRED, ReservationStatuses::ACTIVE_HOLD, $reason );

		return array(
			'reservation'  => $this->reservation_repo->get( $reservation_id ),
			'notification' => null,
		);
	}

	/** @param array<string,mixed> $data */
	private function reason( array $data ): string {
		return sanitize_text_field( substr( (string) ( $data['reason'] ?? '' ), 0, 500 ) );
	}

	/** @param array<string,mixed> $data */
	private function source( array $data ): string {
		$source = sanitize_key( (string) ( $data['source_surface'] ?? $data['source'] ?? 'admin-override' ) );
		return '' !== $source ? $source : 'admin-override';
	}

	/** @param array<string,mixed> $data */
	private function correlation( array $data ): string {
		$provided = sanitize_text_field( substr( (string) ( $data['correlation_id'] ?? $data['idempotency_key'] ?? '' ), 0, 100 ) );
		return '' !== $provided ? $provided : $this->audit->new_correlation_id();
	}

	private function actor_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	private function mysql_datetime_from_request( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value . ' 00:00:00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/** @return array<string,mixed> */
	private function loan_snapshot( array $loan ): array {
		return array(
			'status'      => (string) ( $loan['status'] ?? '' ),
			'due_at'      => (string) ( $loan['due_at'] ?? '' ),
			'copy_id'     => (int) ( $loan['copy_id'] ?? 0 ),
			'borrower_id' => (int) ( $loan['borrower_id'] ?? 0 ),
		);
	}

	/** @return array<string,mixed> */
	private function copy_snapshot( array $copy ): array {
		return array(
			'item_status'        => (string) ( $copy['item_status'] ?? '' ),
			'circulation_status' => (string) ( $copy['circulation_status'] ?? '' ),
			'book_post_id'       => (int) ( $copy['book_post_id'] ?? 0 ),
		);
	}

	/** @return array<string,mixed> */
	private function reservation_snapshot( array $reservation ): array {
		return array(
			'status'          => (string) ( $reservation['status'] ?? '' ),
			'hold_expires_at' => (string) ( $reservation['hold_expires_at'] ?? '' ),
			'copy_id'         => (int) ( $reservation['copy_id'] ?? 0 ),
			'book_post_id'    => (int) ( $reservation['book_post_id'] ?? 0 ),
			'borrower_id'     => (int) ( $reservation['borrower_id'] ?? 0 ),
		);
	}

	/** @param array<string,mixed> $data */
	private function log_success( string $action, string $family, string $entity_type, int $entity_id, array $before, array $after, array $data, string $source, string $correlation, string $reason, string $summary ): void {
		$this->audit->log(
			$action,
			array(
				'actor_id'       => $this->actor_id(),
				'source_channel' => $source,
				'entity_type'    => $entity_type,
				'entity_id'      => $entity_id,
				'action_group'   => 'override',
				'safe_label'     => $entity_type . ' #' . $entity_id,
				'context'        => array(
					'override'         => true,
					'family'           => $family,
					'action_group'     => 'override',
					'confirmation'     => 'explicit',
					'consequence_text' => $this->consequence_text( $family ),
					'idempotency_key'  => sanitize_text_field( (string) ( $data['idempotency_key'] ?? '' ) ),
					'barcode_token'    => (string) ( $data['barcode_token'] ?? '' ),
				),
				'before'         => $before,
				'after'          => $after,
				'reason'         => $reason,
				'summary'        => $summary,
				'correlation_id' => $correlation,
			)
		);
	}

	/** @param array<string,mixed> $data */
	private function log_failure( string $action, string $family, string $entity_type, int $entity_id, WP_Error $error, array $data, string $source, string $correlation ): void {
		$this->audit->log(
			$action,
			array(
				'actor_id'       => $this->actor_id(),
				'source_channel' => $source,
				'entity_type'    => $entity_type,
				'entity_id'      => $entity_id,
				'action_group'   => 'override',
				'safe_label'     => $entity_type . ' #' . $entity_id,
				'context'        => array(
					'override'     => true,
					'family'       => $family,
					'barcode_hash' => (string) ( $data['barcode_hash'] ?? '' ),
				),
				'status'         => 'failed',
				'error_code'     => $error->get_error_code(),
				'error_message'  => $error->get_error_message(),
				'correlation_id' => $correlation,
			)
		);
	}

	/** @return array<string,mixed> */
	private function response( string $family, array $result, string $correlation ): array {
		return array(
			'ok'             => true,
			'family'         => $family,
			'correlation_id' => $correlation,
			'consequence'    => $this->consequence_text( $family ),
			'result'         => $result,
			'privacy_notice' => __( 'No raw scan/card/barcode tokens are included in audit output.', 'connectlibrary' ),
		);
	}

	private function consequence_text( string $family ): string {
		return match ( $family ) {
			'due_date'       => __( 'This changes the borrower-facing due date without consuming a renewal.', 'connectlibrary' ),
			'copy_status'    => __( 'This changes shelf availability and may remove the copy from circulation.', 'connectlibrary' ),
			'hold_expiry'    => __( 'This changes pickup hold timing or lifecycle and may affect the waitlist.', 'connectlibrary' ),
			'loan_correction'=> __( 'This appends a correction record; original checkout/return history is preserved.', 'connectlibrary' ),
			default          => __( 'This protected override writes an audit entry.', 'connectlibrary' ),
		};
	}

	private function invalid( string $message ): WP_Error {
		return new WP_Error( 'connectlibrary_override_invalid', $message, array( 'status' => 400 ) );
	}

	private function not_found( string $entity ): WP_Error {
		return new WP_Error(
			'connectlibrary_override_not_found',
			sprintf(
				/* translators: %s: entity type. */
				__( '%s not found.', 'connectlibrary' ),
				ucfirst( $entity )
			),
			array( 'status' => 404 )
		);
	}
}
