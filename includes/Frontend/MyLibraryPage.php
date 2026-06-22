<?php
/**
 * Borrower-facing My Library access shell.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Frontend;

use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Borrowers\GuestAccessTokenService;
use ConnectLibrary\Circulation\LoanService;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.NonceVerification.Missing

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the privacy-safe My Library shortcode.
 */
final class MyLibraryPage {
	public const SHORTCODE    = 'connectlibrary_my_library';
	public const STYLE_HANDLE = 'connectlibrary-my-library';
	public const TOKEN_PARAM  = 'cl_guest_token';
	public const TOKEN_ATT    = 'guest_token';

	/**
	 * Borrower persistence dependency.
	 *
	 * @var BorrowerRepository
	 */
	private BorrowerRepository $repository;

	/**
	 * Guest access token dependency.
	 *
	 * @var GuestAccessTokenService
	 */
	private GuestAccessTokenService $guest_tokens;

	/**
	 * Loan service dependency.
	 *
	 * @var LoanService
	 */
	private LoanService $loan_service;

	/**
	 * Reservation repository dependency.
	 *
	 * @var ReservationRepository
	 */
	private ReservationRepository $reservation_repo;

	/**
	 * Reservation service dependency.
	 *
	 * @var ReservationService
	 */
	private ReservationService $reservation_service;

	/**
	 * Create renderer dependencies.
	 *
	 * @param BorrowerRepository|null      $repository          Optional repository override.
	 * @param GuestAccessTokenService|null $guest_tokens        Optional guest-token service override.
	 * @param LoanService|null             $loan_service        Optional loan service override.
	 * @param ReservationRepository|null   $reservation_repo    Optional reservation repository override.
	 * @param ReservationService|null      $reservation_service Optional reservation service override.
	 */
	public function __construct(
		?BorrowerRepository $repository = null,
		?GuestAccessTokenService $guest_tokens = null,
		?LoanService $loan_service = null,
		?ReservationRepository $reservation_repo = null,
		?ReservationService $reservation_service = null
	) {
		$this->repository          = $repository ?? new BorrowerRepository();
		$this->guest_tokens        = $guest_tokens ?? new GuestAccessTokenService( null, $this->repository );
		$this->loan_service        = $loan_service ?? new LoanService();
		$this->reservation_repo    = $reservation_repo ?? new ReservationRepository();
		$this->reservation_service = $reservation_service ?? new ReservationService( $this->reservation_repo );
	}

	/** Register shortcode and assets. */
	public function register(): void {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	/**
	 * Register the guest-token query variable for normal WordPress requests.
	 *
	 * @param array<int,string> $vars Public query vars.
	 * @return array<int,string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::TOKEN_PARAM;

		return array_values( array_unique( $vars ) );
	}

	/** Register My Library stylesheet. */
	public function register_assets(): void {
		wp_register_style(
			self::STYLE_HANDLE,
			plugin_dir_url( CONNECTLIBRARY_PLUGIN_FILE ) . 'assets/css/my-library.css',
			array(),
			CONNECTLIBRARY_VERSION
		);
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes, reserved for future slices.
	 */
	public function render_shortcode( array|string $atts = array() ): string {
		wp_enqueue_style( self::STYLE_HANDLE );

		$guest_token = $this->guest_token_from_request( $atts );
		if ( '' !== $guest_token ) {
			$borrower = $this->guest_tokens->resolve_borrower( $guest_token );
			if ( null === $borrower ) {
				return $this->render_guest_token_error();
			}

			$this->send_guest_no_store_headers();
			return $this->render_library_shell( $borrower, array(), true );
		}

		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return $this->render_logged_out_prompt();
		}

		$borrower = $this->active_borrower_for_wp_user( $user_id );
		if ( null === $borrower ) {
			return $this->render_no_record_state();
		}

		return $this->render_library_shell( $borrower, $this->active_children_for_guardian( (int) $borrower['id'] ) );
	}

	/** Render a login/contact prompt without borrower data. */
	private function render_logged_out_prompt(): string {
		$out  = '<section class="connectlibrary-my-library connectlibrary-my-library--login" aria-labelledby="connectlibrary-my-library-heading">';
		$out .= '<div class="connectlibrary-my-library__card">';
		$out .= '<h2 id="connectlibrary-my-library-heading" class="connectlibrary-my-library__heading">' . esc_html__( 'My Library', 'connectlibrary' ) . '</h2>';
		$out .= '<p class="connectlibrary-my-library__message">' . esc_html__( 'Please log in to view your library account, or contact the church librarian for help accessing your record.', 'connectlibrary' ) . '</p>';
		$out .= '</div></section>';

		return $out;
	}

	/** Render the privacy-safe no-record state for logged-in users. */
	private function render_no_record_state(): string {
		$out  = '<section class="connectlibrary-my-library connectlibrary-my-library--empty" aria-labelledby="connectlibrary-my-library-heading">';
		$out .= '<div class="connectlibrary-my-library__card">';
		$out .= '<h2 id="connectlibrary-my-library-heading" class="connectlibrary-my-library__heading">' . esc_html__( 'My Library', 'connectlibrary' ) . '</h2>';
		$out .= '<p class="connectlibrary-my-library__message">' . esc_html__( 'We do not have an active library record for this account yet. Please contact the librarian or make a reservation first.', 'connectlibrary' ) . '</p>';
		$out .= '</div></section>';

		return $out;
	}

	/** Render the generic privacy-safe guest-token error state. */
	private function render_guest_token_error(): string {
		$out  = '<section class="connectlibrary-my-library connectlibrary-my-library--guest-error" aria-labelledby="connectlibrary-my-library-heading">';
		$out .= '<div class="connectlibrary-my-library__card">';
		$out .= '<h2 id="connectlibrary-my-library-heading" class="connectlibrary-my-library__heading">' . esc_html__( 'My Library', 'connectlibrary' ) . '</h2>';
		$out .= '<p class="connectlibrary-my-library__message">' . esc_html__( 'This library link is no longer available. Please contact the librarian for a new link.', 'connectlibrary' ) . '</p>';
		$out .= '</div></section>';

		return $out;
	}

	/**
	 * Render own account and linked-child shells with loans and reservations.
	 *
	 * @param array<string,mixed>            $borrower     Active borrower row.
	 * @param array<int,array<string,mixed>> $children     Active linked child rows.
	 * @param bool                           $guest_access Whether this is secure guest-token access.
	 */
	private function render_library_shell( array $borrower, array $children, bool $guest_access = false ): string {
		// Ordered list: self (or guest) first, then active children (not for guest access).
		$authorized_borrowers = array( $borrower );
		if ( ! $guest_access ) {
			foreach ( $children as $child ) {
				$authorized_borrowers[] = $child;
			}
		}

		$renewal_notice      = $this->handle_renewal_request( $authorized_borrowers );
		$cancellation_notice = $this->handle_cancellation_request( $authorized_borrowers );

		$classes = 'connectlibrary-my-library';
		if ( $guest_access ) {
			$classes .= ' connectlibrary-my-library--guest-access';
		}

		$out  = '<section class="' . esc_attr( $classes ) . '" aria-labelledby="connectlibrary-my-library-heading">';
		$out .= '<header class="connectlibrary-my-library__header">';
		$out .= '<h2 id="connectlibrary-my-library-heading" class="connectlibrary-my-library__heading">' . esc_html__( 'My Library', 'connectlibrary' ) . '</h2>';
		if ( $guest_access ) {
			$out .= '<p class="connectlibrary-my-library__access-label">' . esc_html__( 'Secure guest access', 'connectlibrary' ) . '</p>';
		}
		$out .= '</header>';

		if ( null !== $renewal_notice ) {
			$out .= $renewal_notice;
		}

		if ( null !== $cancellation_notice ) {
			$out .= $cancellation_notice;
		}

		$out .= '<div class="connectlibrary-my-library__sections">';

		$self_label = $guest_access ? __( 'Guest library account', 'connectlibrary' ) : __( 'Your library account', 'connectlibrary' );
		$out       .= $this->render_account_section( $borrower, $self_label, $guest_access ? 'guest' : 'self', 0 );

		if ( ! $guest_access ) {
			foreach ( $children as $idx => $child ) {
				$out .= $this->render_account_section( $child, __( 'Linked child account', 'connectlibrary' ), 'child', $idx + 1 );
			}
		}

		$out .= '</div></section>';

		return $out;
	}

	/**
	 * Process a renewal POST when the action marker and nonce are present.
	 *
	 * Returns an HTML notice string on success or error, null when no POST was detected.
	 *
	 * @param array<int,array<string,mixed>> $authorized_borrowers Ordered borrower rows for this view.
	 * @return string|null
	 */
	private function handle_renewal_request( array $authorized_borrowers ): ?string {
		$action = isset( $_POST['connectlibrary_action'] )
			? sanitize_key( wp_unslash( $_POST['connectlibrary_action'] ) )
			: '';

		if ( 'renew' !== $action ) {
			return null;
		}

		$nonce = isset( $_POST['_cl_renew_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_cl_renew_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'connectlibrary-renew' ) ) {
			return $this->render_renewal_notice( false, __( 'Security check failed. Please try again.', 'connectlibrary' ) );
		}

		$renewal_token = isset( $_POST['renewal_token'] )
			? sanitize_text_field( wp_unslash( $_POST['renewal_token'] ) )
			: '';

		foreach ( $authorized_borrowers as $borrower_idx => $borrower ) {
			$loans_result = $this->loan_service->active_loans_for_borrower( (int) ( $borrower['id'] ?? 0 ) );
			$loans        = is_wp_error( $loans_result ) ? array() : $loans_result;
			foreach ( $loans as $loan ) {
				$loan_id = (int) ( $loan['id'] ?? 0 );
				if ( $loan_id > 0 && hash_equals( $this->renewal_token( $loan_id, $borrower_idx ), $renewal_token ) ) {
					$result = $this->loan_service->renew( $loan_id, (int) ( $borrower['id'] ?? 0 ), 'self' );
					if ( is_wp_error( $result ) ) {
						return $this->render_renewal_notice( false, __( 'This renewal could not be completed. Please contact the librarian.', 'connectlibrary' ) );
					}
					return $this->render_renewal_notice( true, __( 'Your loan has been renewed successfully.', 'connectlibrary' ) );
				}
			}
		}

		return $this->render_renewal_notice( false, __( 'Invalid renewal request. Please try again.', 'connectlibrary' ) );
	}

	/**
	 * Process a reservation cancellation POST when the action marker and nonce are present.
	 *
	 * Verifies ownership via an HMAC cancel token computed against the
	 * authorized-borrowers list. No internal IDs are accepted from POST.
	 * Returns an HTML notice string on success or error, null when no POST detected.
	 *
	 * @param array<int,array<string,mixed>> $authorized_borrowers Ordered borrower rows for this view.
	 * @return string|null
	 */
	private function handle_cancellation_request( array $authorized_borrowers ): ?string {
		$action = isset( $_POST['connectlibrary_action'] )
			? sanitize_key( wp_unslash( $_POST['connectlibrary_action'] ) )
			: '';

		if ( 'cancel_reservation' !== $action ) {
			return null;
		}

		$nonce = isset( $_POST['_cl_cancel_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_cl_cancel_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'connectlibrary-cancel-reservation' ) ) {
			return $this->render_cancellation_notice( false, __( 'Security check failed. Please try again.', 'connectlibrary' ) );
		}

		$posted_token = isset( $_POST['cancel_token'] )
			? sanitize_text_field( wp_unslash( $_POST['cancel_token'] ) )
			: '';

		foreach ( $authorized_borrowers as $borrower_idx => $borrower ) {
			$borrower_id  = (int) ( $borrower['id'] ?? 0 );
			$reservations = $this->active_reservations_for_borrower( $borrower_id );
			foreach ( $reservations as $reservation ) {
				$reservation_id = (int) ( $reservation['id'] ?? 0 );
				if ( $reservation_id > 0 && hash_equals( $this->cancel_token( $reservation_id, $borrower_idx ), $posted_token ) ) {
					$result = $this->reservation_service->cancel( $reservation_id, 'self' );
					if ( is_wp_error( $result ) ) {
						return $this->render_cancellation_notice( false, __( 'This cancellation could not be completed. Please contact the librarian.', 'connectlibrary' ) );
					}
					return $this->render_cancellation_notice( true, __( 'Your reservation has been cancelled.', 'connectlibrary' ) );
				}
			}
		}

		return $this->render_cancellation_notice( false, __( 'Invalid cancellation request. Please try again.', 'connectlibrary' ) );
	}

	/**
	 * Render an accessible cancellation success or error notice.
	 *
	 * @param bool   $success True for success, false for error.
	 * @param string $message Human-readable message.
	 */
	private function render_cancellation_notice( bool $success, string $message ): string {
		$class = $success
			? 'connectlibrary-my-library__cancel-success'
			: 'connectlibrary-my-library__cancel-error';
		$role  = $success ? 'status' : 'alert';

		return '<div class="' . esc_attr( $class ) . '" role="' . esc_attr( $role ) . '">'
			. '<p>' . esc_html( $message ) . '</p>'
			. '</div>';
	}

	/**
	 * Render an accessible renewal success or error notice.
	 *
	 * @param bool   $success True for success, false for error.
	 * @param string $message Human-readable message.
	 */
	private function render_renewal_notice( bool $success, string $message ): string {
		$class = $success
			? 'connectlibrary-my-library__renewal-success'
			: 'connectlibrary-my-library__renewal-error';
		$role  = $success ? 'status' : 'alert';

		return '<div class="' . esc_attr( $class ) . '" role="' . esc_attr( $role ) . '">'
			. '<p>' . esc_html( $message ) . '</p>'
			. '</div>';
	}

	/**
	 * Render one borrower-safe account section with loans and reservations.
	 *
	 * @param array<string,mixed> $borrower     Borrower row.
	 * @param string              $label        Section label.
	 * @param string              $context      self|child|guest.
	 * @param int                 $borrower_idx Zero-based index in the authorized-borrowers list.
	 */
	private function render_account_section( array $borrower, string $label, string $context, int $borrower_idx = 0 ): string {
		$name        = $this->borrower_display_name( $borrower );
		$borrower_id = (int) ( $borrower['id'] ?? 0 );
		$classes     = 'connectlibrary-my-library__account connectlibrary-my-library__account--' . sanitize_key( $context );

		$loans_result = $this->loan_service->active_loans_for_borrower( $borrower_id );
		$loans        = is_wp_error( $loans_result ) ? array() : $loans_result;
		$reservations = $this->active_reservations_for_borrower( $borrower_id );

		$out  = '<article class="' . esc_attr( $classes ) . '">';
		$out .= '<div class="connectlibrary-my-library__account-header">';
		$out .= '<p class="connectlibrary-my-library__account-label">' . esc_html( $label ) . '</p>';
		$out .= '<h3 class="connectlibrary-my-library__account-name">' . esc_html( $name ) . '</h3>';
		$out .= '</div>';

		// Active checkouts section.
		$out .= '<section class="connectlibrary-my-library__loans" aria-label="' . esc_attr__( 'Current checkouts', 'connectlibrary' ) . '">';
		if ( empty( $loans ) ) {
			$out .= '<p class="connectlibrary-my-library__loans-empty">' . esc_html__( 'No active checkouts.', 'connectlibrary' ) . '</p>';
		} else {
			$out .= '<ul class="connectlibrary-my-library__loan-list">';
			foreach ( $loans as $loan ) {
				$out .= $this->render_loan_item( $loan, $borrower_idx );
			}
			$out .= '</ul>';
		}
		$out .= '</section>';

		// Reservations, holds, and waitlist section.
		if ( ! empty( $reservations ) ) {
			$out .= '<section class="connectlibrary-my-library__reservations" aria-label="' . esc_attr__( 'Reservations and holds', 'connectlibrary' ) . '">';
			$out .= '<ul class="connectlibrary-my-library__reservation-list">';
			foreach ( $reservations as $reservation ) {
				$out .= $this->render_reservation_item( $reservation, $borrower_idx );
			}
			$out .= '</ul>';
			$out .= '</section>';
		}

		$out .= '</article>';

		return $out;
	}

	/**
	 * Render a single loan card with due state and optional renewal form.
	 *
	 * @param array<string,mixed> $loan         Loan row.
	 * @param int                 $borrower_idx Borrower index for the renewal form.
	 */
	private function render_loan_item( array $loan, int $borrower_idx ): string {
		$loan_id    = (int) ( $loan['id'] ?? 0 );
		$book_id    = (int) ( $loan['book_post_id'] ?? 0 );
		$due_at     = (string) ( $loan['due_at'] ?? '' );
		$title      = $this->book_title( $book_id );
		$now        = current_time( 'mysql' );
		$is_overdue = '' !== $due_at && $due_at < $now;
		$can_renew  = $this->loan_service->is_eligible_for_renewal( $loan );

		$due_label = $is_overdue ? __( 'Overdue', 'connectlibrary' ) : __( 'Due', 'connectlibrary' );
		$due_class = $is_overdue
			? 'connectlibrary-my-library__loan-due connectlibrary-my-library__loan-due--overdue'
			: 'connectlibrary-my-library__loan-due connectlibrary-my-library__loan-due--current';
		$due_date  = '' !== $due_at ? $this->format_date( $due_at ) : '';

		$out  = '<li class="connectlibrary-my-library__loan-item">';
		$out .= '<div class="connectlibrary-my-library__loan-title">' . esc_html( $title ) . '</div>';
		$out .= '<div class="' . esc_attr( $due_class ) . '" aria-label="' . esc_attr( $due_label ) . '">';
		$out .= esc_html( $due_label ) . ': ' . esc_html( $due_date );
		$out .= '</div>';

		if ( $can_renew ) {
			$nonce_value = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'connectlibrary-renew' ) : 'connectlibrary-renew';
			$out        .= '<form class="connectlibrary-my-library__renew-form" method="post">';
			$out        .= '<input type="hidden" name="connectlibrary_action" value="renew">';
			$out        .= '<input type="hidden" name="renewal_token" value="' . esc_attr( $this->renewal_token( $loan_id, $borrower_idx ) ) . '">';
			$out        .= '<input type="hidden" name="_cl_renew_nonce" value="' . esc_attr( $nonce_value ) . '">';
			$out        .= '<button type="submit" class="connectlibrary-my-library__renew-btn">' . esc_html__( 'Renew', 'connectlibrary' ) . '</button>';
			$out        .= '</form>';
		}

		$out .= '</li>';

		return $out;
	}

	/**
	 * Render a single reservation/hold/waitlist item without exposing internal identifiers.
	 *
	 * Includes a nonce-protected cancel form for cancellable non-terminal statuses
	 * (pending_approval, waitlisted, active_hold). No internal reservation IDs or
	 * borrower identifiers are exposed in public output; the cancel token is an
	 * HMAC that the server verifies against authorized-borrower reservations.
	 *
	 * @param array<string,mixed> $reservation  Reservation row.
	 * @param int                 $borrower_idx Zero-based index in the authorized-borrowers list.
	 */
	private function render_reservation_item( array $reservation, int $borrower_idx = 0 ): string {
		$book_id        = (int) ( $reservation['book_post_id'] ?? 0 );
		$reservation_id = (int) ( $reservation['id'] ?? 0 );
		$status         = (string) ( $reservation['status'] ?? '' );
		$expires_at     = (string) ( $reservation['hold_expires_at'] ?? '' );
		$title          = $this->book_title( $book_id );
		$status_label   = $this->reservation_status_label( $status );

		$out  = '<li class="connectlibrary-my-library__reservation-item">';
		$out .= '<div class="connectlibrary-my-library__reservation-title">' . esc_html( $title ) . '</div>';
		$out .= '<div class="connectlibrary-my-library__reservation-status">' . esc_html( $status_label ) . '</div>';

		if ( '' !== $expires_at && ReservationStatuses::ACTIVE_HOLD === $status ) {
			$out .= '<div class="connectlibrary-my-library__reservation-expiry">'
				. esc_html__( 'Hold expires', 'connectlibrary' ) . ': '
				. esc_html( $this->format_date( $expires_at ) )
				. '</div>';
		}

		$cancellable = array(
			ReservationStatuses::PENDING_APPROVAL,
			ReservationStatuses::WAITLISTED,
			ReservationStatuses::ACTIVE_HOLD,
		);

		if ( $reservation_id > 0 && in_array( $status, $cancellable, true ) ) {
			$nonce_value  = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'connectlibrary-cancel-reservation' ) : 'connectlibrary-cancel-reservation';
			$cancel_token = $this->cancel_token( $reservation_id, $borrower_idx );

			$out .= '<form class="connectlibrary-my-library__cancel-form" method="post">';
			$out .= '<input type="hidden" name="connectlibrary_action" value="cancel_reservation">';
			$out .= '<input type="hidden" name="cancel_token" value="' . esc_attr( $cancel_token ) . '">';
			$out .= '<input type="hidden" name="_cl_cancel_nonce" value="' . esc_attr( $nonce_value ) . '">';
			$out .= '<button type="submit" class="connectlibrary-my-library__cancel-btn">' . esc_html__( 'Cancel', 'connectlibrary' ) . '</button>';
			$out .= '</form>';
		}

		$out .= '</li>';

		return $out;
	}

	/**
	 * Resolve a public-safe book title from a post ID.
	 *
	 * @param int $book_post_id Book post ID.
	 */
	private function book_title( int $book_post_id ): string {
		if ( $book_post_id > 0 && function_exists( 'get_the_title' ) ) {
			$title = get_the_title( $book_post_id );
			if ( '' !== $title && 'Library item' !== $title ) {
				return $title;
			}
		}

		return __( 'Library item', 'connectlibrary' );
	}

	/**
	 * Return a short public-facing label for a reservation status.
	 *
	 * @param string $status Raw status key.
	 */
	private function reservation_status_label( string $status ): string {
		$labels = array(
			ReservationStatuses::PENDING_APPROVAL => __( 'Pending Approval', 'connectlibrary' ),
			ReservationStatuses::ACTIVE_HOLD      => __( 'Ready for Pickup', 'connectlibrary' ),
			ReservationStatuses::WAITLISTED       => __( 'Waitlisted', 'connectlibrary' ),
			ReservationStatuses::PICKED_UP        => __( 'Picked Up', 'connectlibrary' ),
		);

		return $labels[ $status ] ?? __( 'On Reserve', 'connectlibrary' );
	}

	/**
	 * Return all non-terminal reservations for a borrower.
	 *
	 * @param int $borrower_id Borrower ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function active_reservations_for_borrower( int $borrower_id ): array {
		if ( $borrower_id <= 0 ) {
			return array();
		}

		return array_values(
			array_filter(
				$this->reservation_repo->all(),
				static fn( array $r ): bool =>
					(int) ( $r['borrower_id'] ?? 0 ) === $borrower_id
					&& ! ReservationStatuses::is_terminal( (string) ( $r['status'] ?? '' ) )
			)
		);
	}

	/**
	 * Format a MySQL datetime string to a human-readable date.
	 *
	 * @param string $mysql_date MySQL datetime string.
	 */
	private function format_date( string $mysql_date ): string {
		$ts = strtotime( $mysql_date );

		return false !== $ts
			? date( 'F j, Y', $ts ) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			: $mysql_date;
	}

	/**
	 * Find the current user's active borrower without exposing admin-only fields.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string,mixed>|null
	 */
	private function active_borrower_for_wp_user( int $user_id ): ?array {
		foreach ( $this->repository->all() as $row ) {
			if ( 'active' !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			if ( 'wp_user' !== (string) ( $row['borrower_type'] ?? '' ) ) {
				continue;
			}
			if ( (int) ( $row['wp_user_id'] ?? 0 ) === $user_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Return only active child borrowers linked to the resolved guardian borrower.
	 *
	 * @param int $guardian_borrower_id Guardian borrower ID resolved server-side.
	 * @return array<int,array<string,mixed>>
	 */
	private function active_children_for_guardian( int $guardian_borrower_id ): array {
		$children = array();
		foreach ( $this->repository->all() as $row ) {
			if ( 'active' !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			if ( 'child' !== (string) ( $row['borrower_type'] ?? '' ) ) {
				continue;
			}
			if ( (int) ( $row['guardian_borrower_id'] ?? 0 ) !== $guardian_borrower_id ) {
				continue;
			}
			$children[] = $row;
		}

		return $children;
	}

	/**
	 * Extract a guest token from shortcode attributes or the public query var.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	private function guest_token_from_request( array|string $atts ): string {
		$atts = is_array( $atts ) ? shortcode_atts(
			array(
				self::TOKEN_ATT   => '',
				'token'           => '',
				'access_token'    => '',
				self::TOKEN_PARAM => '',
			),
			$atts,
			self::SHORTCODE
		) : array();

		foreach ( array( self::TOKEN_ATT, 'token', 'access_token', self::TOKEN_PARAM ) as $key ) {
			$value = trim( sanitize_text_field( wp_unslash( $atts[ $key ] ?? '' ) ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		$query_value = '';
		if ( function_exists( 'get_query_var' ) ) {
			$query_value = (string) get_query_var( self::TOKEN_PARAM, '' );
		}
		if ( '' === $query_value && isset( $_GET[ self::TOKEN_PARAM ] ) ) {
			$query_value = (string) wp_unslash( $_GET[ self::TOKEN_PARAM ] );
		}

		return trim( sanitize_text_field( $query_value ) );
	}

	/** Send conservative cache headers for guest-token views where available. */
	private function send_guest_no_store_headers(): void {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
	}

	/**
	 * Compute an opaque HMAC-based renewal token for a loan and borrower slot.
	 *
	 * @param int $loan_id      Loan ID.
	 * @param int $borrower_idx Zero-based index in the authorized-borrowers list.
	 */
	private function renewal_token( int $loan_id, int $borrower_idx ): string {
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : CONNECTLIBRARY_VERSION;
		return 'clrenew_' . hash_hmac( 'sha256', $loan_id . ':' . $borrower_idx, $secret );
	}

	/**
	 * Compute an opaque HMAC-based cancel token for a reservation and borrower slot.
	 *
	 * @param int $reservation_id Reservation ID.
	 * @param int $borrower_idx   Zero-based index in the authorized-borrowers list.
	 */
	private function cancel_token( int $reservation_id, int $borrower_idx ): string {
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : CONNECTLIBRARY_VERSION;
		return 'clcancel_' . hash_hmac( 'sha256', $reservation_id . ':' . $borrower_idx, $secret );
	}

	/**
	 * Choose a public-safe borrower label.
	 *
	 * @param array<string,mixed> $borrower Borrower row.
	 */
	private function borrower_display_name( array $borrower ): string {
		$preferred = trim( (string) ( $borrower['preferred_name'] ?? '' ) );
		if ( '' !== $preferred ) {
			return $preferred;
		}

		$name = trim( (string) ( $borrower['display_name'] ?? '' ) );
		return '' !== $name ? $name : __( 'Library borrower', 'connectlibrary' );
	}
}
