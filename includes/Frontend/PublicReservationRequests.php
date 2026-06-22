<?php
/**
 * Public reservation and guest request POST handler.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Frontend;

use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;

// phpcs:disable WordPress.Security.NonceVerification.Missing
// phpcs:disable WordPress.Security.NonceVerification.Recommended

defined( 'ABSPATH' ) || exit;

/**
 * Handles public reservation (borrower hold) and guest request form submissions.
 *
 * POST processing runs on init/template_redirect; notices are stored in a
 * static property so BookDetailRenderer can read them via get_notice().
 * No borrower PII, internal IDs, or raw tokens are ever surfaced in output.
 */
final class PublicReservationRequests {

	/** Nonce action for borrower hold forms (append book ID). */
	public const NONCE_ACTION_HOLD = 'connectlibrary_reserve';

	/** Nonce action for guest request forms (append book ID). */
	public const NONCE_ACTION_GUEST = 'connectlibrary_guest_request';

	/** Nonce action for logged-in borrower waitlist join forms (append book ID). */
	public const NONCE_ACTION_WAITLIST = 'connectlibrary_join_waitlist';

	/** Hidden input name for waitlist join nonce. */
	public const NONCE_FIELD_WAITLIST = '_cl_waitlist_nonce';

	/** Hidden input name for borrower hold nonce. */
	public const NONCE_FIELD_HOLD = '_cl_hold_nonce';

	/** Hidden input name for guest request nonce. */
	public const NONCE_FIELD_GUEST = '_cl_guest_nonce';

	/** Honeypot field name: must remain empty on genuine submissions. */
	public const HONEYPOT_FIELD = 'cl_confirm_email';

	/** Rate-limit window in seconds (one hour). */
	public const RATE_LIMIT_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Pending notice to render on the next page output.
	 *
	 * @var array{type:string,message:string}|null
	 */
	private static ?array $notice = null;

	/**
	 * Reservation service dependency.
	 *
	 * @var ReservationService
	 */
	private ReservationService $service;

	/**
	 * Borrower repository dependency.
	 *
	 * @var BorrowerRepository
	 */
	private BorrowerRepository $borrower_repo;

	/**
	 * Reservation repository dependency.
	 *
	 * @var ReservationRepository
	 */
	private ReservationRepository $reservation_repo;

	/**
	 * Constructor.
	 *
	 * @param ReservationService|null    $service          Optional override for testing.
	 * @param BorrowerRepository|null    $borrower_repo    Optional override for testing.
	 * @param ReservationRepository|null $reservation_repo Optional override for testing.
	 */
	public function __construct(
		?ReservationService $service = null,
		?BorrowerRepository $borrower_repo = null,
		?ReservationRepository $reservation_repo = null
	) {
		$this->reservation_repo = $reservation_repo ?? new ReservationRepository();
		$this->borrower_repo    = $borrower_repo ?? new BorrowerRepository();
		$this->service          = $service ?? new ReservationService( $this->reservation_repo, $this->borrower_repo );
	}

	/**
	 * Inspect $_POST and dispatch to the appropriate handler.
	 *
	 * Safe to hook on init or template_redirect; does nothing when the
	 * connectlibrary_action field is absent.
	 */
	public function handle_post(): void {
		if ( empty( $_POST['connectlibrary_action'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}

		$action  = sanitize_key( wp_unslash( (string) ( $_POST['connectlibrary_action'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$book_id = absint( $_POST['connectlibrary_book_id'] ?? 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( 'reserve_hold' === $action ) {
			$this->process_hold( $book_id );
		} elseif ( 'guest_request' === $action ) {
			$this->process_guest( $book_id );
		} elseif ( 'join_waitlist' === $action ) {
			$this->process_waitlist( $book_id );
		}
	}

	/**
	 * Process a borrower hold request.
	 *
	 * @param int $book_id Book post ID from POST data.
	 */
	private function process_hold( int $book_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD_HOLD ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD_HOLD ] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';

		if ( ! wp_verify_nonce( $nonce, self::hold_nonce_action( $book_id ) ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'Security check failed. Please refresh the page and try again.', 'connectlibrary' ),
			);
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'You must be logged in to reserve a book.', 'connectlibrary' ),
			);
			return;
		}

		$borrower = $this->borrower_repo->find_by_wp_user_id( $user_id );
		if ( null === $borrower ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'Your account is not registered in the library system. Please contact the librarian.', 'connectlibrary' ),
			);
			return;
		}

		$borrower_id = absint( $borrower['id'] ?? 0 );
		if ( ! empty( $this->reservation_repo->non_terminal_for_borrower_book( $borrower_id, $book_id ) ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'A reservation for this book already exists on your account.', 'connectlibrary' ),
			);
			return;
		}

		if ( ! $this->book_is_publicly_reservable( $book_id ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'This book is not currently available for reservation.', 'connectlibrary' ),
			);
			return;
		}

		$result = $this->service->request_hold( $borrower_id, $book_id );

		if ( is_wp_error( $result ) ) {
			if ( 'connectlibrary_reservation_duplicate' === $result->get_error_code() ) {
				self::$notice = array(
					'type'    => 'error',
					'message' => __( 'A reservation for this book already exists on your account.', 'connectlibrary' ),
				);
			} else {
				self::$notice = array(
					'type'    => 'error',
					'message' => __( 'This book is not currently available for reservation.', 'connectlibrary' ),
				);
			}
			return;
		}

		self::$notice = array(
			'type'    => 'success',
			'message' => __( 'Your reservation has been placed. The librarian will hold a copy for you.', 'connectlibrary' ),
		);
	}

	/**
	 * Process a logged-in borrower waitlist join request.
	 *
	 * Used when the book's request_action is 'waitlist' (no free copy available).
	 * Creates a WAITLISTED reservation directly for the borrower.
	 *
	 * @param int $book_id Book post ID from POST data.
	 */
	private function process_waitlist( int $book_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD_WAITLIST ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD_WAITLIST ] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';

		if ( ! wp_verify_nonce( $nonce, self::waitlist_nonce_action( $book_id ) ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'Security check failed. Please refresh the page and try again.', 'connectlibrary' ),
			);
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'You must be logged in to join the waitlist.', 'connectlibrary' ),
			);
			return;
		}

		$borrower = $this->borrower_repo->find_by_wp_user_id( $user_id );
		if ( null === $borrower ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'Your account is not registered in the library system. Please contact the librarian.', 'connectlibrary' ),
			);
			return;
		}

		$borrower_id = absint( $borrower['id'] ?? 0 );
		if ( ! empty( $this->reservation_repo->non_terminal_for_borrower_book( $borrower_id, $book_id ) ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'You are already on the waitlist or have a reservation for this book.', 'connectlibrary' ),
			);
			return;
		}

		// Confirm the book is in a waitlist-eligible state (not available for direct reserve).
		if ( $book_id <= 0 ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'This book is not currently accepting waitlist requests.', 'connectlibrary' ),
			);
			return;
		}

		$availability = Availability::for_book( $book_id );
		if ( 'waitlist' !== ( $availability['request_action'] ?? '' ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'This book is not currently accepting waitlist requests.', 'connectlibrary' ),
			);
			return;
		}

		$result = $this->service->join_waitlist( $borrower_id, $book_id );

		if ( is_wp_error( $result ) ) {
			if ( 'connectlibrary_reservation_duplicate' === $result->get_error_code() ) {
				self::$notice = array(
					'type'    => 'error',
					'message' => __( 'You are already on the waitlist or have a reservation for this book.', 'connectlibrary' ),
				);
			} else {
				self::$notice = array(
					'type'    => 'error',
					'message' => __( 'Unable to join the waitlist. Please try again or contact the librarian.', 'connectlibrary' ),
				);
			}
			return;
		}

		self::$notice = array(
			'type'    => 'success',
			'message' => __( 'You have been added to the waitlist. The librarian will contact you when a copy becomes available.', 'connectlibrary' ),
		);
	}

	/**
	 * Process a guest reservation request.
	 *
	 * Applies honeypot and transient-based rate limiting before calling the
	 * service. Guest PII is never stored in the notice.
	 *
	 * @param int $book_id Book post ID from POST data.
	 */
	private function process_guest( int $book_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD_GUEST ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD_GUEST ] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';

		if ( ! wp_verify_nonce( $nonce, self::guest_nonce_action( $book_id ) ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'Security check failed. Please refresh the page and try again.', 'connectlibrary' ),
			);
			return;
		}

		// Honeypot: bots that fill this hidden field are silently discarded.
		$honeypot = isset( $_POST[ self::HONEYPOT_FIELD ] ) ? (string) $_POST[ self::HONEYPOT_FIELD ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $honeypot ) {
			self::$notice = array(
				'type'    => 'success',
				'message' => __( 'Your request has been received.', 'connectlibrary' ),
			);
			return;
		}

		$email = isset( $_POST['cl_guest_email'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_email( wp_unslash( (string) $_POST['cl_guest_email'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';
		$name  = isset( $_POST['cl_guest_name'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_text_field( wp_unslash( (string) $_POST['cl_guest_name'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';
		$phone = isset( $_POST['cl_guest_phone'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_text_field( wp_unslash( (string) $_POST['cl_guest_phone'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';
		$note  = isset( $_POST['cl_guest_note'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_textarea_field( wp_unslash( (string) $_POST['cl_guest_note'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';

		if ( '' === $email || ! is_email( $email ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'Please enter a valid email address.', 'connectlibrary' ),
			);
			return;
		}

		if ( '' === $name ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'Please enter your name.', 'connectlibrary' ),
			);
			return;
		}

		if ( ! empty( $this->reservation_repo->non_terminal_for_guest_book( $email, $book_id ) ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'A request for this book already exists. Please contact the librarian if you need help.', 'connectlibrary' ),
			);
			return;
		}

		if ( ! $this->book_accepts_public_guest_request( $book_id ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'This book is not currently available for reservation requests.', 'connectlibrary' ),
			);
			return;
		}

		// Rate limit: one request per email per hour.
		$rate_key = 'cl_guest_rate_' . md5( $email );
		if ( false !== get_transient( $rate_key ) ) {
			self::$notice = array(
				'type'    => 'error',
				'message' => __( 'Please wait before submitting another request.', 'connectlibrary' ),
			);
			return;
		}

		$note_parts = array();
		if ( '' !== $phone ) {
			/* translators: %s: guest-supplied phone number. */
			$note_parts[] = sprintf( __( 'Phone: %s', 'connectlibrary' ), $phone );
		}
		if ( '' !== $note ) {
			$note_parts[] = $note;
		}

		$opts   = array( 'notes' => implode( "\n", $note_parts ) );
		$result = $this->service->request_guest( $email, $name, $book_id, $opts );

		if ( is_wp_error( $result ) ) {
			if ( 'connectlibrary_reservation_duplicate' === $result->get_error_code() ) {
				self::$notice = array(
					'type'    => 'error',
					'message' => __( 'A request for this book already exists. Please contact the librarian if you need help.', 'connectlibrary' ),
				);
			} else {
				self::$notice = array(
					'type'    => 'error',
					'message' => __( 'Unable to submit your request. Please check your details and try again.', 'connectlibrary' ),
				);
			}
			return;
		}

		set_transient( $rate_key, 1, self::RATE_LIMIT_SECONDS );

		self::$notice = array(
			'type'    => 'success',
			'message' => __( 'Your request has been received. A librarian will be in touch.', 'connectlibrary' ),
		);
	}

	/**
	 * Return the pending notice for the current request, or null if none.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public static function get_notice(): ?array {
		return self::$notice;
	}

	/**
	 * Clear the static notice — called between tests.
	 */
	public static function clear_notice(): void {
		self::$notice = null;
	}

	/**
	 * Build the nonce action string for a borrower hold form.
	 *
	 * @param int $book_id Book post ID.
	 * @return string Nonce action.
	 */
	public static function hold_nonce_action( int $book_id ): string {
		return self::NONCE_ACTION_HOLD . '_' . $book_id;
	}

	/**
	 * Build the nonce action string for a guest request form.
	 *
	 * @param int $book_id Book post ID.
	 * @return string Nonce action.
	 */
	public static function guest_nonce_action( int $book_id ): string {
		return self::NONCE_ACTION_GUEST . '_' . $book_id;
	}

	/**
	 * Build the nonce action string for a waitlist join form.
	 *
	 * @param int $book_id Book post ID.
	 * @return string Nonce action.
	 */
	public static function waitlist_nonce_action( int $book_id ): string {
		return self::NONCE_ACTION_WAITLIST . '_' . $book_id;
	}

	/**
	 * Check whether the public catalog state currently offers direct reservation.
	 *
	 * Hidden, unavailable, checked-out, and waitlist-only states are rejected
	 * here so POST requests cannot bypass the rendered form/action selection.
	 *
	 * @param int $book_id Book post ID.
	 */
	private function book_is_publicly_reservable( int $book_id ): bool {
		if ( $book_id <= 0 ) {
			return false;
		}

		$availability = Availability::for_book( $book_id );

		return 'reserve' === ( $availability['request_action'] ?? '' );
	}

	/**
	 * Check whether public guests may submit a librarian-reviewed request.
	 *
	 * Guest requests do not place holds directly. They create pending_approval
	 * rows for librarian review, so they are valid for both direct-reserve and
	 * waitlist-eligible public states. Hidden/contact-librarian states still
	 * reject POSTs so the handler mirrors the rendered action panel.
	 *
	 * @param int $book_id Book post ID.
	 */
	private function book_accepts_public_guest_request( int $book_id ): bool {
		if ( $book_id <= 0 ) {
			return false;
		}

		$availability = Availability::for_book( $book_id );
		$action       = (string) ( $availability['request_action'] ?? '' );

		return in_array( $action, array( 'reserve', 'waitlist' ), true );
	}
}
