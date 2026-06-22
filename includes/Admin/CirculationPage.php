<?php
/**
 * Librarian circulation admin dashboard.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Borrowers\BorrowerCardService;
use ConnectLibrary\Borrowers\GuestAccessTokenRepository;
use ConnectLibrary\Borrowers\GuestAccessTokenService;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Circulation\CopyRepository;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Circulation\LoanService;
use ConnectLibrary\Reservations\ReservationRepository;
use ConnectLibrary\Support\Capabilities;
use ConnectLibrary\Support\ScannerInput;
use ConnectLibrary\Support\Statuses;
use WP_Error;

/**
 * Registers and renders the Phase 2 librarian circulation dashboard.
 *
 * Supports borrower/card lookup, item/ISBN lookup, and audited actions for
 * checkout, return, renewal, due-date change, lost, and damaged handling.
 * All modifying actions are protected by capability checks and nonces.
 */
final class CirculationPage {

	private const PAGE_SLUG    = 'connectlibrary-circulation';
	private const ACTION_NAME  = 'connectlibrary_circ_action';
	private const NONCE_ACTION = 'connectlibrary_circ_action';

	/**
	 * Loan service.
	 *
	 * @var LoanService
	 */
	private LoanService $loan_service;

	/**
	 * Borrower repository (read-only lookups; capability already checked at page level).
	 *
	 * @var BorrowerRepository
	 */
	private BorrowerRepository $borrower_repo;

	/**
	 * Copy repository.
	 *
	 * @var CopyRepository
	 */
	private CopyRepository $copy_repo;

	/**
	 * Loan repository (for active-for-copy lookup).
	 *
	 * @var LoanRepository
	 */
	private LoanRepository $loan_repo;

	/**
	 * Guest-access token repository (used for card-token borrower lookup).
	 *
	 * @var GuestAccessTokenRepository
	 */
	private GuestAccessTokenRepository $token_repo;

	/**
	 * Dedicated borrower-card lifecycle service.
	 *
	 * @var BorrowerCardService
	 */
	private BorrowerCardService $card_service;

	/**
	 * Audit service.
	 *
	 * @var AuditEventService
	 */
	private AuditEventService $audit_events;

	/**
	 * Reservation repository (for active-hold holder check in render).
	 *
	 * @var ReservationRepository
	 */
	private ReservationRepository $reservation_repo;

	/**
	 * Create page dependencies.
	 *
	 * @param LoanService|null                $loan_service      Optional service override.
	 * @param BorrowerRepository|null         $borrower_repo     Optional repo override.
	 * @param CopyRepository|null             $copy_repo         Optional repo override.
	 * @param LoanRepository|null             $loan_repo         Optional repo override.
	 * @param GuestAccessTokenRepository|null $token_repo        Optional repo override.
	 * @param ReservationRepository|null      $reservation_repo  Optional repo override.
	 */
	public function __construct(
		?LoanService $loan_service = null,
		?BorrowerRepository $borrower_repo = null,
		?CopyRepository $copy_repo = null,
		?LoanRepository $loan_repo = null,
		?GuestAccessTokenRepository $token_repo = null,
		?ReservationRepository $reservation_repo = null
	) {
		$this->loan_service     = $loan_service ?? new LoanService();
		$this->borrower_repo    = $borrower_repo ?? new BorrowerRepository();
		$this->copy_repo        = $copy_repo ?? new CopyRepository();
		$this->loan_repo        = $loan_repo ?? new LoanRepository();
		$this->token_repo       = $token_repo ?? new GuestAccessTokenRepository();
		$this->card_service     = new BorrowerCardService();
		$this->audit_events     = new AuditEventService();
		$this->reservation_repo = $reservation_repo ?? new ReservationRepository();
	}

	/** Register admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_' . self::ACTION_NAME, array( $this, 'handle_action' ) );
	}

	/** Add the circulation screen under the Library admin menu. */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . BookPostType::POST_TYPE,
			esc_html__( 'Quick Circulation', 'connectlibrary' ),
			esc_html__( 'Quick Circulation', 'connectlibrary' ),
			Capabilities::MANAGE_CIRCULATION,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/** Render the circulation dashboard. */
	public function render(): void {
		if ( ! Capabilities::can_manage_circulation() ) {
			wp_die( esc_html__( 'You do not have permission to use the circulation dashboard.', 'connectlibrary' ) );
		}

		$borrower_id = absint( wp_unslash( $_GET['circ_borrower_id'] ?? 0 ) );
		$copy_id     = absint( wp_unslash( $_GET['circ_copy_id'] ?? 0 ) );
		$card_token  = ScannerInput::sanitize_text( wp_unslash( $_GET['circ_card_token'] ?? '' ) );
		$copy_search = ScannerInput::sanitize_text( wp_unslash( $_GET['circ_copy_search'] ?? '' ) );
		$name_search = ScannerInput::sanitize_text( wp_unslash( $_GET['circ_name_search'] ?? '' ) );
		$circ_scan   = ScannerInput::sanitize_text( wp_unslash( $_GET['circ_scan'] ?? '' ) );

		// Resolve explicit card token to borrower.
		$card_error = '';
		if ( '' !== $card_token && 0 === $borrower_id ) {
			$token_result = $this->resolve_card_token( $card_token, 'explicit' );
			if ( is_wp_error( $token_result ) ) {
				$card_error = $token_result->get_error_message();
			} elseif ( is_array( $token_result ) && isset( $token_result['borrower_id'] ) ) {
				$borrower_id = (int) $token_result['borrower_id'];
			}
		}

		// Resolve circ_scan (unified scanner) without changing state:
		// 1. active card token → borrower; 2. card-shaped failure → card error;
		// 3. otherwise → copy/barcode search.
		if ( '' !== $circ_scan && '' === $card_error ) {
			if ( 0 === $borrower_id && '' === $card_token ) {
				$scan_token_result = $this->resolve_card_token( $circ_scan, 'unified' );
				if ( ! is_wp_error( $scan_token_result ) && is_array( $scan_token_result ) && isset( $scan_token_result['borrower_id'] ) ) {
					$borrower_id = (int) $scan_token_result['borrower_id'];
				} elseif ( is_wp_error( $scan_token_result ) && $this->is_card_scan_candidate( $circ_scan ) ) {
					$card_error = $scan_token_result->get_error_message();
				} elseif ( 0 === $copy_id && '' === $copy_search ) {
					// Not card-shaped — treat as copy barcode/ISBN search.
					$copy_search = $circ_scan;
				}
			} elseif ( 0 === $copy_id && '' === $copy_search ) {
				// Borrower already selected: treat scan as copy search for next item.
				$copy_search = $circ_scan;
			}
		}

		$borrower     = $borrower_id > 0 ? $this->borrower_repo->get( $borrower_id ) : null;
		$copy         = $copy_id > 0 ? $this->copy_repo->get( $copy_id ) : null;
		$copy_results = $copy_id > 0 ? array() : ( '' !== $copy_search ? $this->copy_repo->find_by_isbn_or_barcode( $copy_search ) : array() );
		$name_results = ( '' !== $name_search && 0 === $borrower_id ) ? $this->borrower_repo->search( array( 'search' => $name_search ) ) : array();

		$active_loan = null;
		if ( $copy && $copy_id > 0 ) {
			$active_loan = $this->loan_repo->active_for_copy( $copy_id );
		}

		$borrower_loans = array();
		if ( $borrower && $borrower_id > 0 ) {
			$result = $this->loan_service->active_loans_for_borrower( $borrower_id );
			if ( ! is_wp_error( $result ) ) {
				$borrower_loans = $result;
			}
		}

		// Guardian lookup for child borrowers.
		$guardian = null;
		if ( $borrower && 'child' === (string) ( $borrower['borrower_type'] ?? '' ) ) {
			$guardian_id = (int) ( $borrower['guardian_borrower_id'] ?? 0 );
			if ( $guardian_id > 0 ) {
				$guardian = $this->borrower_repo->get( $guardian_id );
			}
		}
		?>
		<div class="wrap connectlibrary-circulation-admin">
			<h1><?php echo esc_html__( 'Quick Circulation', 'connectlibrary' ); ?></h1>

			<div class="circ-scanner-form" style="margin-bottom:1em;padding:0.75em;background:#f6f7f7;border:1px solid #c3c4c7;max-width:600px;">
				<label for="circ-scan-input" style="font-weight:600;display:block;margin-bottom:0.25em;"><?php echo esc_html__( 'Scan card or item', 'connectlibrary' ); ?></label>
				<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" style="display:flex;gap:0.5em;align-items:center;">
					<input type="hidden" name="post_type" value="<?php echo esc_attr( BookPostType::POST_TYPE ); ?>" />
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
					<?php if ( $borrower_id > 0 ) : ?>
						<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
					<?php endif; ?>
					<?php if ( $copy_id > 0 ) : ?>
						<input type="hidden" name="circ_copy_id" value="<?php echo esc_attr( (string) $copy_id ); ?>" />
					<?php endif; ?>
					<input
						id="circ-scan-input"
						type="search"
						name="circ_scan"
						value=""
						placeholder="<?php echo esc_attr__( 'Scan or type card token, ISBN, or barcode...', 'connectlibrary' ); ?>"
						class="regular-text"
						autocomplete="off"
						autofocus
					/>
					<button type="submit" class="button button-primary"><?php echo esc_html__( 'Scan', 'connectlibrary' ); ?></button>
				</form>
			</div>

			<div id="circ-live-status" role="status" aria-live="polite" aria-atomic="false">
				<?php $this->render_notices(); ?>
				<?php if ( '' !== $card_error ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $card_error ); ?></p></div>
				<?php endif; ?>
			</div>

			<div class="circ-panels" style="display:flex;gap:2em;flex-wrap:wrap;align-items:flex-start;">

				<!-- ─── Left: Lookups ─────────────────────────────────── -->
				<div class="circ-lookup-panel" style="min-width:320px;flex:1;">

					<h2><?php echo esc_html__( 'Borrower Lookup', 'connectlibrary' ); ?></h2>
					<?php $this->render_borrower_lookup_forms( $borrower_id, $card_token, $name_search ); ?>

					<?php if ( null !== $borrower ) : ?>
						<?php $this->render_borrower_summary( $borrower, $guardian ); ?>
					<?php elseif ( array() !== $name_results ) : ?>
						<?php $this->render_borrower_search_results( $name_results, $copy_id ); ?>
					<?php endif; ?>

					<h2 style="margin-top:1.5em;"><?php echo esc_html__( 'Item / Book Lookup', 'connectlibrary' ); ?></h2>
					<?php $this->render_copy_lookup_form( $copy_id, $copy_search ); ?>

					<?php if ( null !== $copy ) : ?>
						<?php $this->render_copy_summary( $copy, $active_loan ); ?>
					<?php elseif ( array() !== $copy_results ) : ?>
						<?php $this->render_copy_search_results( $copy_results, $borrower_id ); ?>
					<?php endif; ?>

				</div>

				<!-- ─── Right: Actions ───────────────────────────────── -->
				<div class="circ-action-panel" style="min-width:300px;flex:1;">

					<h2><?php echo esc_html__( 'Actions', 'connectlibrary' ); ?></h2>
					<?php $this->render_action_panel( $borrower, $copy, $active_loan, $borrower_id, $copy_id ); ?>

					<?php if ( null !== $borrower && array() !== $borrower_loans ) : ?>
						<h2 style="margin-top:1.5em;"><?php echo esc_html__( 'Active Loans', 'connectlibrary' ); ?></h2>
						<?php $this->render_borrower_loans( $borrower_loans, $borrower_id, $copy_id ); ?>
					<?php endif; ?>

				</div>
			</div><!-- .circ-panels -->

			<?php $this->render_clear_links( $borrower_id, $copy_id ); ?>
		</div><!-- .wrap -->
		<script>
		/* phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript */
		(function(){
			var s = document.getElementById('circ-scan-input');
			if ( s ) { s.focus(); }
			document.querySelectorAll('.circ-action-forms form').forEach(function(f){
				f.addEventListener('submit',function(){
					f.querySelectorAll('button[type="submit"]').forEach(function(b){
						b.disabled = true;
					});
				},{once:true});
			});
		})();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Action handler
	// -------------------------------------------------------------------------

	/** Dispatch nonce-protected POST actions. */
	public function handle_action(): void {
		if ( ! Capabilities::can_manage_circulation() ) {
			wp_die( esc_html__( 'You do not have permission to perform circulation actions.', 'connectlibrary' ) );
		}

		if ( false === check_admin_referer( self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Circulation form security check failed.', 'connectlibrary' ) );
		}

		$circ_action = sanitize_key( wp_unslash( $_POST['circ_action'] ?? '' ) );
		$borrower_id = absint( wp_unslash( $_POST['circ_borrower_id'] ?? 0 ) );
		$copy_id     = absint( wp_unslash( $_POST['circ_copy_id'] ?? 0 ) );
		$loan_id     = absint( wp_unslash( $_POST['circ_loan_id'] ?? 0 ) );
		$actor_id    = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;

		$result = match ( $circ_action ) {
			'checkout'    => $this->do_checkout( $borrower_id, $copy_id, $actor_id ),
			'return'      => $this->do_return( $copy_id, $loan_id, $actor_id ),
			'renew'       => $this->do_renew( $loan_id, $borrower_id ),
			'change_due'  => $this->do_change_due( $loan_id ),
			'mark_lost'   => $this->do_mark_lost( $copy_id ),
			'mark_damaged' => $this->do_mark_damaged( $copy_id ),
			default       => new WP_Error( 'connectlibrary_circ_unknown_action', __( 'Unknown circulation action.', 'connectlibrary' ), array( 'status' => 400 ) ),
		};

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array_filter(
						array(
							'circ_error'       => rawurlencode( $result->get_error_message() ),
							'circ_borrower_id' => $borrower_id > 0 ? $borrower_id : null,
							'circ_copy_id'     => $copy_id > 0 ? $copy_id : null,
						)
					),
					$this->page_url()
				)
			);
			return;
		}

		$notice_key = match ( $circ_action ) {
			'checkout'    => 'checkout_ok',
			'return'      => 'return_ok',
			'renew'       => 'renew_ok',
			'change_due'  => 'due_change_ok',
			'mark_lost'   => 'lost_ok',
			'mark_damaged' => 'damaged_ok',
			default       => 'ok',
		};

		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'circ_notice'      => $notice_key,
						'circ_borrower_id' => $borrower_id > 0 ? $borrower_id : null,
						'circ_copy_id'     => $circ_action === 'return' ? null : ( $copy_id > 0 ? $copy_id : null ),
					)
				),
				$this->page_url()
			)
		);
	}

	// -------------------------------------------------------------------------
	// Private action methods
	// -------------------------------------------------------------------------

	/**
	 * Perform checkout.
	 *
	 * @param int $borrower_id Borrower ID.
	 * @param int $copy_id     Copy ID.
	 * @param int $actor_id    Actor WP user ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function do_checkout( int $borrower_id, int $copy_id, int $actor_id ): array|WP_Error {
		if ( $borrower_id <= 0 || $copy_id <= 0 ) {
			return new WP_Error(
				'connectlibrary_circ_missing_selection',
				__( 'Please select a borrower and an item before checking out.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$copy = $this->copy_repo->get( $copy_id );
		if ( null === $copy ) {
			return new WP_Error( 'connectlibrary_copy_not_found', __( 'Item not found.', 'connectlibrary' ), array( 'status' => 404 ) );
		}

		$book_post_id  = (int) ( $copy['book_post_id'] ?? 0 );
		$due_override  = ScannerInput::sanitize_text( wp_unslash( $_POST['due_at_override'] ?? '' ) );
		$override_note = ScannerInput::sanitize_text( wp_unslash( $_POST['due_override_note'] ?? '' ) );

		if ( '' !== $due_override ) {
			if ( empty( $_POST['confirm_due_override'] ) ) {
				return new WP_Error( 'connectlibrary_circ_confirm_required', __( 'Confirm the checkout due-date override before continuing.', 'connectlibrary' ), array( 'status' => 422 ) );
			}
			$due_at = date( 'Y-m-d H:i:s', (int) strtotime( $due_override ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			if ( false === strtotime( $due_override ) ) {
				return new WP_Error( 'connectlibrary_circ_invalid_due', __( 'Invalid due date format.', 'connectlibrary' ), array( 'status' => 422 ) );
			}
		} else {
			$due_at = null;
		}

		return $this->loan_service->checkout( $copy_id, $book_post_id, $borrower_id, $due_at, 'quick-circulation', $actor_id, $override_note );
	}

	/**
	 * Perform return.
	 *
	 * @param int $copy_id  Copy ID (for lookup when no explicit loan_id).
	 * @param int $loan_id  Explicit loan ID (takes priority when set).
	 * @param int $actor_id Actor WP user ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function do_return( int $copy_id, int $loan_id, int $actor_id ): array|WP_Error {
		if ( $loan_id <= 0 && $copy_id > 0 ) {
			$active = $this->loan_repo->active_for_copy( $copy_id );
			if ( null === $active ) {
				return new WP_Error(
					'connectlibrary_circ_no_active_loan',
					__( 'This item is already returned and currently available — safe to scan the next item.', 'connectlibrary' ),
					array( 'status' => 422 )
				);
			}
			$loan_id = (int) ( $active['id'] ?? 0 );
		}

		if ( $loan_id <= 0 ) {
			return new WP_Error(
				'connectlibrary_circ_missing_loan',
				__( 'Please select an item or loan before returning.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		return $this->loan_service->return_copy( $loan_id, 'quick-circulation', $actor_id );
	}

	/**
	 * Perform renewal.
	 *
	 * @param int $loan_id     Loan ID.
	 * @param int $borrower_id Borrower ID (ownership check).
	 * @return array<string,mixed>|WP_Error
	 */
	private function do_renew( int $loan_id, int $borrower_id ): array|WP_Error {
		if ( $loan_id <= 0 || $borrower_id <= 0 ) {
			return new WP_Error(
				'connectlibrary_circ_missing_selection',
				__( 'Please select a borrower and an active loan before renewing.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		return $this->loan_service->renew( $loan_id, $borrower_id, 'quick-circulation' );
	}

	/**
	 * Perform due-date change.
	 *
	 * @param int $loan_id Loan ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function do_change_due( int $loan_id ): array|WP_Error {
		if ( $loan_id <= 0 ) {
			return new WP_Error(
				'connectlibrary_circ_missing_loan',
				__( 'Please select an active loan before changing the due date.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$new_due_raw = ScannerInput::sanitize_text( wp_unslash( $_POST['new_due_at'] ?? '' ) );
		if ( '' === $new_due_raw || false === strtotime( $new_due_raw ) ) {
			return new WP_Error( 'connectlibrary_circ_invalid_due', __( 'Invalid due date.', 'connectlibrary' ), array( 'status' => 422 ) );
		}

		$new_due_at = date( 'Y-m-d H:i:s', (int) strtotime( $new_due_raw ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$reason     = ScannerInput::sanitize_text( wp_unslash( $_POST['due_change_reason'] ?? '' ) );
		$confirmed  = ! empty( $_POST['confirm_due_change'] );
		if ( ! $confirmed ) {
			return new WP_Error(
				'connectlibrary_circ_confirm_required',
				__( 'Confirm the due-date override before changing the loan.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$now = current_time( 'mysql' );
		if ( $new_due_at < $now ) {
			// Past due dates are allowed as corrections but only when a reason is given.
			if ( '' === $reason ) {
				return new WP_Error(
					'connectlibrary_circ_past_due_no_reason',
					__( 'A reason is required when setting a past due date.', 'connectlibrary' ),
					array( 'status' => 422 )
				);
			}
		}

		return $this->loan_service->change_due_date( $loan_id, $new_due_at, $reason, 'quick-circulation' );
	}

	/**
	 * Mark a copy as lost.
	 *
	 * @param int $copy_id Copy ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function do_mark_lost( int $copy_id ): array|WP_Error {
		if ( $copy_id <= 0 ) {
			return new WP_Error(
				'connectlibrary_circ_missing_copy',
				__( 'Please select an item before marking it lost.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$confirmed = ! empty( $_POST['confirm_lost'] );
		if ( ! $confirmed ) {
			return new WP_Error(
				'connectlibrary_circ_confirm_required',
				__( 'Please check the confirmation box to mark an item as lost.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$actor_context = 'admin-lost';
		$result        = $this->loan_service->mark_copy_lost( $copy_id, $actor_context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Also close any active loan to preserve history.
		$active_loan = $this->loan_repo->active_for_copy( $copy_id );
		if ( null !== $active_loan ) {
			$this->loan_service->return_copy( (int) ( $active_loan['id'] ?? 0 ), 'admin-lost', function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		}

		return $result;
	}

	/**
	 * Mark a copy as damaged.
	 *
	 * @param int $copy_id Copy ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function do_mark_damaged( int $copy_id ): array|WP_Error {
		if ( $copy_id <= 0 ) {
			return new WP_Error(
				'connectlibrary_circ_missing_copy',
				__( 'Please select an item before marking it damaged.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$confirmed = ! empty( $_POST['confirm_damaged'] );
		if ( ! $confirmed ) {
			return new WP_Error(
				'connectlibrary_circ_confirm_required',
				__( 'Please check the confirmation box to mark an item as damaged.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		}

		$note   = ScannerInput::sanitize_textarea( wp_unslash( $_POST['damage_note'] ?? '' ) );
		$result = $this->loan_service->mark_copy_damaged( $copy_id, '' !== $note ? 'admin-damaged|note:' . $note : 'admin-damaged' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Persist the damage note to the copy's private_notes field.
		if ( '' !== $note ) {
			$this->copy_repo->update(
				$copy_id,
				array(
					'private_notes' => $note,
					'updated_at'    => current_time( 'mysql' ),
				)
			);
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Render helpers
	// -------------------------------------------------------------------------

	/** Render session notices. */
	private function render_notices(): void {
		$notice = sanitize_key( wp_unslash( $_GET['circ_notice'] ?? '' ) );
		$error  = sanitize_text_field( wp_unslash( $_GET['circ_error'] ?? '' ) );

		if ( '' !== $notice ) {
			$message = match ( $notice ) {
				'checkout_ok'  => __( 'Checkout completed successfully.', 'connectlibrary' ),
				'return_ok'    => __( 'Item returned successfully.', 'connectlibrary' ),
				'renew_ok'     => __( 'Loan renewed successfully.', 'connectlibrary' ),
				'due_change_ok' => __( 'Due date updated successfully.', 'connectlibrary' ),
				'lost_ok'      => __( 'Item marked as lost.', 'connectlibrary' ),
				'damaged_ok'   => __( 'Item marked as damaged.', 'connectlibrary' ),
				default        => __( 'Action completed.', 'connectlibrary' ),
			};
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}

		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	/**
	 * Render the borrower lookup forms.
	 *
	 * @param int    $borrower_id  Currently selected borrower ID.
	 * @param string $card_token   Last card-token search value.
	 * @param string $name_search  Last name/email search value.
	 */
	private function render_borrower_lookup_forms( int $borrower_id, string $card_token, string $name_search ): void {
		$base_url = $this->page_url();
		if ( $borrower_id > 0 ) {
			$base_url = add_query_arg( array( 'circ_borrower_id' => $borrower_id ), $base_url );
		}
		?>
		<div class="circ-borrower-lookup" style="margin-bottom:1em;">
			<h3 style="margin-bottom:0.25em;"><?php echo esc_html__( 'Scan / enter card token', 'connectlibrary' ); ?></h3>
			<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" style="display:flex;gap:0.5em;align-items:center;">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( BookPostType::POST_TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php if ( $borrower_id > 0 ) : ?>
					<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
				<?php endif; ?>
				<label class="screen-reader-text" for="circ-card-token"><?php echo esc_html__( 'Card token', 'connectlibrary' ); ?></label>
				<input id="circ-card-token" type="text" name="circ_card_token" value="<?php echo esc_attr( $card_token ); ?>" placeholder="<?php echo esc_attr__( 'Scan or paste card token…', 'connectlibrary' ); ?>" class="regular-text" autocomplete="off" />
				<button type="submit" class="button"><?php echo esc_html__( 'Look up card', 'connectlibrary' ); ?></button>
			</form>

			<h3 style="margin:0.75em 0 0.25em;"><?php echo esc_html__( 'Search by name / email', 'connectlibrary' ); ?></h3>
			<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" style="display:flex;gap:0.5em;align-items:center;">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( BookPostType::POST_TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php if ( $borrower_id > 0 ) : ?>
					<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
				<?php endif; ?>
				<label class="screen-reader-text" for="circ-name-search"><?php echo esc_html__( 'Search borrowers', 'connectlibrary' ); ?></label>
				<input id="circ-name-search" type="search" name="circ_name_search" value="<?php echo esc_attr( $name_search ); ?>" placeholder="<?php echo esc_attr__( 'Name or email…', 'connectlibrary' ); ?>" class="regular-text" />
				<button type="submit" class="button"><?php echo esc_html__( 'Search', 'connectlibrary' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a compact borrower summary card for the selected borrower.
	 *
	 * Child borrowers show guardian info; private_notes indicator only.
	 *
	 * @param array<string,mixed>      $borrower Borrower row.
	 * @param array<string,mixed>|null $guardian Guardian borrower row (child borrowers).
	 */
	private function render_borrower_summary( array $borrower, ?array $guardian ): void {
		$is_child  = 'child' === (string) ( $borrower['borrower_type'] ?? '' );
		$is_active = 'active' === (string) ( $borrower['status'] ?? '' );
		$has_notes = '' !== trim( (string) ( $borrower['private_notes'] ?? '' ) );
		?>
		<div class="circ-borrower-card card" style="padding:1em;margin:0.5em 0;border-left:4px solid <?php echo $is_active ? '#00a32a' : '#d63638'; ?>;">
			<strong><?php echo esc_html( (string) ( $borrower['display_name'] ?? '' ) ); ?></strong>
			<?php if ( ! $is_active ) : ?>
				<span class="circ-status-badge" style="color:#d63638;font-weight:bold;margin-left:0.5em;"><?php echo esc_html( (string) ( $borrower['status'] ?? 'inactive' ) ); ?></span>
			<?php endif; ?>
			<?php if ( $has_notes ) : ?>
				<span title="<?php echo esc_attr__( 'Private note on file – see Borrowers screen', 'connectlibrary' ); ?>" style="margin-left:0.5em;color:#996633;">&#9888;</span>
			<?php endif; ?>
			<br />
			<small><?php echo esc_html( $this->borrower_type_label( (string) ( $borrower['borrower_type'] ?? '' ) ) ); ?></small>
			<?php if ( $is_child ) : ?>
				<div style="margin-top:0.5em;padding:0.5em;background:#fff8e1;border:1px solid #ffe082;">
					<strong><?php echo esc_html__( 'Child borrower — all notices route to guardian:', 'connectlibrary' ); ?></strong><br />
					<?php if ( null !== $guardian ) : ?>
						<?php echo esc_html( (string) ( $guardian['display_name'] ?? '' ) ); ?>
						<?php if ( '' !== (string) ( $guardian['email'] ?? '' ) ) : ?>
							(<?php echo esc_html( (string) ( $guardian['email'] ?? '' ) ); ?>)
						<?php endif; ?>
					<?php else : ?>
						<?php echo esc_html( (string) ( $borrower['guardian_name'] ?? '' ) ); ?>
						<?php if ( '' !== (string) ( $borrower['guardian_email'] ?? '' ) ) : ?>
							(<?php echo esc_html( (string) ( $borrower['guardian_email'] ?? '' ) ); ?>)
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render search result rows for borrower name/email lookup.
	 *
	 * Does not show raw IDs, card tokens, private notes, or full history.
	 *
	 * @param array<int,array<string,mixed>> $results   Search result borrower rows.
	 * @param int                            $copy_id   Currently selected copy ID (preserved in links).
	 */
	private function render_borrower_search_results( array $results, int $copy_id ): void {
		if ( array() === $results ) {
			echo '<p>' . esc_html__( 'No borrowers matched that search.', 'connectlibrary' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped circ-borrower-results" style="margin-top:0.5em;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Name', 'connectlibrary' ); ?></th>
					<th><?php echo esc_html__( 'Type', 'connectlibrary' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'connectlibrary' ); ?></th>
					<th><?php echo esc_html__( 'Contact', 'connectlibrary' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $borrower ) : ?>
					<?php
					$select_url = add_query_arg(
						array_filter(
							array(
								'post_type'        => BookPostType::POST_TYPE,
								'page'             => self::PAGE_SLUG,
								'circ_borrower_id' => (int) ( $borrower['id'] ?? 0 ),
								'circ_copy_id'     => $copy_id > 0 ? $copy_id : null,
							)
						),
						admin_url( 'edit.php' )
					);
					$type       = (string) ( $borrower['borrower_type'] ?? '' );
					?>
					<tr>
						<td><?php echo esc_html( (string) ( $borrower['display_name'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $this->borrower_type_label( $type ) ); ?></td>
						<td><?php echo esc_html( (string) ( $borrower['status'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $this->masked_email( (string) ( $borrower['email'] ?? '' ) ) ); ?></td>
						<td><a href="<?php echo esc_url( $select_url ); ?>" class="button button-small"><?php echo esc_html__( 'Select', 'connectlibrary' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the copy/item lookup form.
	 *
	 * @param int    $copy_id    Currently selected copy ID.
	 * @param string $copy_search Last search query.
	 */
	private function render_copy_lookup_form( int $copy_id, string $copy_search ): void {
		?>
		<div class="circ-copy-lookup" style="margin-bottom:1em;">
			<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" style="display:flex;gap:0.5em;align-items:center;">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( BookPostType::POST_TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php
				$borrower_id_get = absint( wp_unslash( $_GET['circ_borrower_id'] ?? 0 ) );
				if ( $borrower_id_get > 0 ) :
					?>
					<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id_get ); ?>" />
				<?php endif; ?>
				<label class="screen-reader-text" for="circ-copy-search"><?php echo esc_html__( 'Search by ISBN or barcode', 'connectlibrary' ); ?></label>
				<input id="circ-copy-search" type="search" name="circ_copy_search" value="<?php echo esc_attr( $copy_search ); ?>" placeholder="<?php echo esc_attr__( 'ISBN or barcode…', 'connectlibrary' ); ?>" class="regular-text" autocomplete="off" />
				<button type="submit" class="button"><?php echo esc_html__( 'Find item', 'connectlibrary' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a copy summary card for the selected copy.
	 *
	 * @param array<string,mixed>      $copy        Copy row.
	 * @param array<string,mixed>|null $active_loan Active loan for the copy, if any.
	 */
	private function render_copy_summary( array $copy, ?array $active_loan ): void {
		$circ_status  = (string) ( $copy['circulation_status'] ?? '' );
		$item_status  = (string) ( $copy['item_status'] ?? '' );
		$is_available = Statuses::COPY_AVAILABLE === $circ_status;
		$book_title   = function_exists( 'get_the_title' ) ? get_the_title( (int) ( $copy['book_post_id'] ?? 0 ) ) : '';
		$color        = $is_available ? '#00a32a' : '#d63638';
		?>
		<div class="circ-copy-card card" style="padding:1em;margin:0.5em 0;border-left:4px solid <?php echo esc_attr( $color ); ?>;">
			<?php if ( '' !== $book_title ) : ?>
				<strong><?php echo esc_html( $book_title ); ?></strong><br />
			<?php endif; ?>
			<small>
				<?php echo esc_html__( 'Status:', 'connectlibrary' ); ?> <?php echo esc_html( $circ_status ); ?>
				&nbsp;|&nbsp;
				<?php echo esc_html__( 'Condition:', 'connectlibrary' ); ?> <?php echo esc_html( $item_status ); ?>
				<?php if ( '' !== (string) ( $copy['barcode'] ?? '' ) ) : ?>
					&nbsp;|&nbsp;
					<?php echo esc_html__( 'Barcode:', 'connectlibrary' ); ?> <?php echo esc_html( (string) ( $copy['barcode'] ?? '' ) ); ?>
				<?php endif; ?>
				<?php if ( '' !== (string) ( $copy['room'] ?? '' ) || '' !== (string) ( $copy['shelf'] ?? '' ) ) : ?>
					<br /><?php echo esc_html__( 'Location:', 'connectlibrary' ); ?> <?php echo esc_html( implode( '/', array_filter( array( (string) ( $copy['room'] ?? '' ), (string) ( $copy['shelf'] ?? '' ), (string) ( $copy['section'] ?? '' ) ) ) ) ); ?>
				<?php endif; ?>
			</small>
			<?php if ( null !== $active_loan ) : ?>
				<div style="margin-top:0.5em;color:#d63638;">
					<?php
					$due_at      = (string) ( $active_loan['due_at'] ?? '' );
					$borrower_id = (int) ( $active_loan['borrower_id'] ?? 0 );
					/* translators: 1: due date, 2: borrower ID. */
					printf( esc_html__( 'Checked out (due %1$s · borrower #%2$d)', 'connectlibrary' ), esc_html( $due_at ), esc_html( (string) $borrower_id ) );
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render copy search results table.
	 *
	 * @param array<int,array<string,mixed>> $results   Copy rows.
	 * @param int                            $borrower_id Currently selected borrower (preserved in links).
	 */
	private function render_copy_search_results( array $results, int $borrower_id ): void {
		if ( array() === $results ) {
			echo '<p>' . esc_html__( 'No items found for that ISBN or barcode.', 'connectlibrary' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped circ-copy-results" style="margin-top:0.5em;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Title', 'connectlibrary' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'connectlibrary' ); ?></th>
					<th><?php echo esc_html__( 'Location', 'connectlibrary' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $copy ) : ?>
					<?php
					$select_url = add_query_arg(
						array_filter(
							array(
								'post_type'        => BookPostType::POST_TYPE,
								'page'             => self::PAGE_SLUG,
								'circ_copy_id'     => (int) ( $copy['id'] ?? 0 ),
								'circ_borrower_id' => $borrower_id > 0 ? $borrower_id : null,
							)
						),
						admin_url( 'edit.php' )
					);
					$title      = function_exists( 'get_the_title' ) ? get_the_title( (int) ( $copy['book_post_id'] ?? 0 ) ) : (string) ( $copy['isbn_13'] ?? '' );
					?>
					<tr>
						<td><?php echo esc_html( $title ); ?></td>
						<td><?php echo esc_html( (string) ( $copy['circulation_status'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( implode( '/', array_filter( array( (string) ( $copy['room'] ?? '' ), (string) ( $copy['shelf'] ?? '' ) ) ) ) ); ?></td>
						<td><a href="<?php echo esc_url( $select_url ); ?>" class="button button-small"><?php echo esc_html__( 'Select', 'connectlibrary' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the context-sensitive action panel.
	 *
	 * @param array<string,mixed>|null $borrower    Selected borrower or null.
	 * @param array<string,mixed>|null $copy        Selected copy or null.
	 * @param array<string,mixed>|null $active_loan Active loan for selected copy or null.
	 * @param int                      $borrower_id Borrower ID.
	 * @param int                      $copy_id     Copy ID.
	 */
	private function render_action_panel( ?array $borrower, ?array $copy, ?array $active_loan, int $borrower_id, int $copy_id ): void {
		$circ_status            = null !== $copy ? (string) ( $copy['circulation_status'] ?? '' ) : '';
		$can_checkout           = null !== $borrower && null !== $copy && Statuses::COPY_AVAILABLE === $circ_status;
		$can_reservation_pickup = null !== $borrower
			&& null !== $copy
			&& Statuses::COPY_ON_HOLD === $circ_status
			&& $this->is_borrower_hold_holder( $borrower_id, $copy_id, (int) ( $copy['book_post_id'] ?? 0 ) );
		$can_return             = null !== $copy && null !== $active_loan;
		$can_mark               = null !== $copy;

		if ( null === $borrower && null === $copy ) {
			echo '<p class="description">' . esc_html__( 'Select a borrower and/or item above to see available actions.', 'connectlibrary' ) . '</p>';
			return;
		}
		?>
		<div class="circ-action-forms">

			<?php // ── Checkout ─────────────────────────────────────────── ?>
			<?php if ( $can_checkout ) : ?>
				<div class="circ-action-checkout" style="margin-bottom:1em;padding:1em;background:#f0f6fc;border:1px solid #c3c4c7;">
					<strong><?php echo esc_html__( 'Checkout', 'connectlibrary' ); ?></strong>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:0.5em;">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
						<input type="hidden" name="circ_action" value="checkout" />
						<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
						<input type="hidden" name="circ_copy_id" value="<?php echo esc_attr( (string) $copy_id ); ?>" />
						<label for="circ-due-override"><?php echo esc_html__( 'Due date (leave blank for 14-day default)', 'connectlibrary' ); ?></label><br />
						<input id="circ-due-override" type="date" name="due_at_override" style="margin:0.25em 0;" />
						<label for="circ-due-override-note"><?php echo esc_html__( 'Override reason', 'connectlibrary' ); ?></label><br />
						<input id="circ-due-override-note" type="text" name="due_override_note" placeholder="<?php echo esc_attr__( 'Reason (optional)', 'connectlibrary' ); ?>" class="regular-text" style="display:block;margin:0.25em 0;" />
						<p class="description" role="status" aria-live="polite"><?php echo esc_html__( 'If you enter a due-date override, confirm deliberately. Scanner Enter should stay in the reason field; use Tab to reach Confirm.', 'connectlibrary' ); ?></p>
						<label><input type="checkbox" name="confirm_due_override" value="1" /> <?php echo esc_html__( 'Confirm: I understand this checkout due date differs from the library default.', 'connectlibrary' ); ?></label><br />
						<a href="<?php echo esc_url( $this->page_url( $borrower_id, $copy_id ) ); ?>" class="button"><?php echo esc_html__( 'Cancel', 'connectlibrary' ); ?></a>
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Checkout', 'connectlibrary' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<?php // ── Reservation pickup checkout ───────────────────────── ?>
			<?php if ( $can_reservation_pickup ) : ?>
				<div class="circ-action-reservation-pickup" style="margin-bottom:1em;padding:1em;background:#f0f6fc;border:1px solid #c3c4c7;">
					<strong><?php echo esc_html__( 'Check out held reservation', 'connectlibrary' ); ?></strong>
					<p class="description" style="margin-bottom:0.25em;"><?php echo esc_html__( 'Complete reservation pickup for this borrower.', 'connectlibrary' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:0.5em;">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
						<input type="hidden" name="circ_action" value="checkout" />
						<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
						<input type="hidden" name="circ_copy_id" value="<?php echo esc_attr( (string) $copy_id ); ?>" />
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Check out held reservation', 'connectlibrary' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<?php // ── Return ────────────────────────────────────────────── ?>
			<?php if ( $can_return ) : ?>
				<div class="circ-action-return" style="margin-bottom:1em;padding:1em;background:#f0f6fc;border:1px solid #c3c4c7;">
					<strong><?php echo esc_html__( 'Return', 'connectlibrary' ); ?></strong>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:0.5em;">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
						<input type="hidden" name="circ_action" value="return" />
						<input type="hidden" name="circ_copy_id" value="<?php echo esc_attr( (string) $copy_id ); ?>" />
						<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
						<input type="hidden" name="circ_loan_id" value="<?php echo esc_attr( (string) ( $active_loan['id'] ?? 0 ) ); ?>" />
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Return item', 'connectlibrary' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<?php // ── Change due date ────────────────────────────────────── ?>
			<?php if ( $can_return ) : ?>
				<div class="circ-action-due-change" style="margin-bottom:1em;padding:1em;background:#f0f6fc;border:1px solid #c3c4c7;">
					<strong><?php echo esc_html__( 'Change due date', 'connectlibrary' ); ?></strong>
					<p class="description" style="margin-bottom:0.25em;"><?php echo esc_html__( 'Does not consume a renewal.', 'connectlibrary' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
						<input type="hidden" name="circ_action" value="change_due" />
						<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
						<input type="hidden" name="circ_copy_id" value="<?php echo esc_attr( (string) $copy_id ); ?>" />
						<input type="hidden" name="circ_loan_id" value="<?php echo esc_attr( (string) ( $active_loan['id'] ?? 0 ) ); ?>" />
						<label for="circ-new-due"><?php echo esc_html__( 'New due date', 'connectlibrary' ); ?></label><br />
						<input id="circ-new-due" type="date" name="new_due_at" required style="margin:0.25em 0;" />
						<label for="circ-due-change-reason"><?php echo esc_html__( 'Reason', 'connectlibrary' ); ?></label><br />
						<input id="circ-due-change-reason" type="text" name="due_change_reason" placeholder="<?php echo esc_attr__( 'Reason (required for past dates)', 'connectlibrary' ); ?>" class="regular-text" style="display:block;margin:0.25em 0;" />
						<p class="description" role="status" aria-live="polite"><?php echo esc_html__( 'Changing a due date does not consume a renewal. Focus starts on the reason field, not Confirm, to avoid scanner Enter submitting this action.', 'connectlibrary' ); ?></p>
						<label><input type="checkbox" name="confirm_due_change" value="1" required /> <?php echo esc_html__( 'Confirm: update the borrower-facing due date and write an audit record.', 'connectlibrary' ); ?></label><br />
						<a href="<?php echo esc_url( $this->page_url( $borrower_id, $copy_id ) ); ?>" class="button"><?php echo esc_html__( 'Cancel', 'connectlibrary' ); ?></a>
						<button type="submit" class="button"><?php echo esc_html__( 'Update due date', 'connectlibrary' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<?php // ── Mark lost ─────────────────────────────────────────── ?>
			<?php if ( $can_mark ) : ?>
				<div class="circ-action-lost" style="margin-bottom:1em;padding:1em;background:#fff8f8;border:1px solid #c3c4c7;">
					<strong style="color:#d63638;"><?php echo esc_html__( 'Mark as lost', 'connectlibrary' ); ?></strong>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:0.5em;">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
						<input type="hidden" name="circ_action" value="mark_lost" />
						<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
						<input type="hidden" name="circ_copy_id" value="<?php echo esc_attr( (string) $copy_id ); ?>" />
						<label>
							<input type="checkbox" name="confirm_lost" value="1" required />
							<?php echo esc_html__( 'Confirm: mark this item as lost and remove from normal availability', 'connectlibrary' ); ?>
						</label><br />
						<button type="submit" class="button" style="margin-top:0.5em;"><?php echo esc_html__( 'Mark lost', 'connectlibrary' ); ?></button>
					</form>
				</div>

				<div class="circ-action-damaged" style="margin-bottom:1em;padding:1em;background:#fff8f8;border:1px solid #c3c4c7;">
					<strong style="color:#996633;"><?php echo esc_html__( 'Mark as damaged', 'connectlibrary' ); ?></strong>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:0.5em;">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
						<input type="hidden" name="circ_action" value="mark_damaged" />
						<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
						<input type="hidden" name="circ_copy_id" value="<?php echo esc_attr( (string) $copy_id ); ?>" />
						<input type="text" name="damage_note" placeholder="<?php echo esc_attr__( 'Damage description (optional)', 'connectlibrary' ); ?>" class="regular-text" style="display:block;margin:0.25em 0;" />
						<label>
							<input type="checkbox" name="confirm_damaged" value="1" required />
							<?php echo esc_html__( 'Confirm: mark this item as damaged', 'connectlibrary' ); ?>
						</label><br />
						<button type="submit" class="button" style="margin-top:0.5em;"><?php echo esc_html__( 'Mark damaged', 'connectlibrary' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( ! $can_checkout && ! $can_reservation_pickup && ! $can_return && ! $can_mark ) : ?>
				<p class="description"><?php echo esc_html__( 'No actions available. Select a borrower and/or item above.', 'connectlibrary' ); ?></p>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Render the active loans table for the selected borrower with Renew action forms.
	 *
	 * @param array<int,array<string,mixed>> $loans      Active loan rows.
	 * @param int                            $borrower_id Borrower ID.
	 * @param int                            $copy_id     Currently selected copy ID (preserved).
	 */
	private function render_borrower_loans( array $loans, int $borrower_id, int $copy_id ): void {
		?>
		<table class="widefat striped circ-loans-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Title / copy', 'connectlibrary' ); ?></th>
					<th><?php echo esc_html__( 'Due', 'connectlibrary' ); ?></th>
					<th><?php echo esc_html__( 'Renewals', 'connectlibrary' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'connectlibrary' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $loans as $loan ) : ?>
					<?php
					$loan_id   = (int) ( $loan['id'] ?? 0 );
					$loan_copy = $this->copy_repo->get( (int) ( $loan['copy_id'] ?? 0 ) );
					$book_id   = (int) ( $loan['book_post_id'] ?? 0 );
					$title     = function_exists( 'get_the_title' ) ? get_the_title( $book_id ) : "Book #{$book_id}";
					$due_at    = (string) ( $loan['due_at'] ?? '' );
					$renewals  = (int) ( $loan['renewal_count'] ?? 0 );
					$limit     = (int) ( $loan['renewal_limit'] ?? 0 );
					$eligible  = $this->loan_service->is_eligible_for_renewal( $loan );
					?>
					<tr>
						<td><?php echo esc_html( $title ); ?></td>
						<td><?php echo esc_html( $due_at ); ?></td>
						<td><?php echo esc_html( "{$renewals}/{$limit}" ); ?></td>
						<td>
							<?php if ( $eligible ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
									<input type="hidden" name="circ_action" value="renew" />
									<input type="hidden" name="circ_borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
									<input type="hidden" name="circ_loan_id" value="<?php echo esc_attr( (string) $loan_id ); ?>" />
									<input type="hidden" name="circ_copy_id" value="<?php echo esc_attr( (string) $copy_id ); ?>" />
									<button type="submit" class="button button-small"><?php echo esc_html__( 'Renew', 'connectlibrary' ); ?></button>
								</form>
							<?php else : ?>
								<span class="description"><?php echo esc_html__( 'Not renewable', 'connectlibrary' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Clear-selection links at the bottom of the page.
	 *
	 * @param int $borrower_id Currently selected borrower ID.
	 * @param int $copy_id     Currently selected copy ID.
	 */
	private function render_clear_links( int $borrower_id, int $copy_id ): void {
		if ( 0 === $borrower_id && 0 === $copy_id ) {
			return;
		}
		echo '<p class="circ-quick-links" style="margin-top:2em;">';
		if ( $borrower_id > 0 ) {
			$next_url = remove_query_arg(
				array( 'circ_copy_id', 'circ_scan', 'circ_copy_search', 'circ_notice', 'circ_error' ),
				$this->page_url_with_borrower( $borrower_id )
			);
			echo '<a href="' . esc_url( $next_url ) . '" class="button">' . esc_html__( 'Scan next item (same borrower)', 'connectlibrary' ) . '</a>&ensp;';
		}
		if ( $copy_id > 0 ) {
			$clear_item_url = $borrower_id > 0
				? remove_query_arg( array( 'circ_copy_id', 'circ_scan', 'circ_copy_search' ), $this->page_url_with_borrower( $borrower_id ) )
				: remove_query_arg( array( 'circ_copy_id', 'circ_scan', 'circ_copy_search' ), $this->page_url() );
			echo '<a href="' . esc_url( $clear_item_url ) . '" class="button">' . esc_html__( 'Clear item', 'connectlibrary' ) . '</a>&ensp;';
		}
		echo '<a href="' . esc_url( $this->page_url() ) . '" class="button">' . esc_html__( 'New borrower', 'connectlibrary' ) . '</a>';
		echo '</p>';
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve a card token value to an active borrower row.
	 *
	 * The token value is HMAC-SHA256 hashed using the canonical guest-token
	 * storage model before lookup so the raw token never hits the database.
	 * Failed and successful lookups are audit logged without raw card codes.
	 *
	 * @param string $token  Raw card token value.
	 * @param string $source Lookup source: explicit|unified.
	 * @return array<string,mixed>|WP_Error Token row with borrower_id or error.
	 */
	private function resolve_card_token( string $token, string $source = 'explicit' ): array|WP_Error {
		$token = trim( $token );
		if ( '' === $token ) {
			return $this->card_lookup_error( 'connectlibrary_card_empty', __( 'Card token is empty.', 'connectlibrary' ), $token, 'malformed', $source );
		}
		if ( $this->is_malformed_card_scan( $token ) ) {
			return $this->card_lookup_error( 'connectlibrary_card_malformed', __( 'That library card code is not valid. Please scan the card again or look up the borrower by name.', 'connectlibrary' ), $token, 'malformed', $source );
		}
		if ( $this->is_card_lookup_rate_limited() ) {
			$this->audit_card_lookup( $token, 'rate_limited', 'failed', $source, 0, 0 );
			return new WP_Error( 'connectlibrary_card_rate_limited', __( 'Too many failed card lookups. Please wait a few minutes or look up the borrower by name.', 'connectlibrary' ) );
		}

		$dedicated_card = $this->card_service->resolve_card_token( $token );
		if ( ! is_wp_error( $dedicated_card ) ) {
			$this->clear_failed_card_lookup_count();
			$this->audit_card_lookup( $token, 'opened', 'ok', $source, (int) ( $dedicated_card['id'] ?? 0 ), (int) ( $dedicated_card['borrower_id'] ?? 0 ) );
			return $dedicated_card;
		}
		if ( 'connectlibrary_card_malformed' === $dedicated_card->get_error_code() && $this->is_malformed_card_scan( $token ) ) {
			return $this->card_lookup_error( 'connectlibrary_card_malformed', $dedicated_card->get_error_message(), $token, 'malformed', $source );
		}
		if ( ! in_array( $dedicated_card->get_error_code(), array( 'connectlibrary_card_not_found', 'connectlibrary_card_malformed' ), true ) ) {
			$this->record_failed_card_lookup();
			return $this->card_lookup_error( $dedicated_card->get_error_code(), $dedicated_card->get_error_message(), $token, 'disabled', $source );
		}

		$hash = GuestAccessTokenService::hash_token( $token );
		$rows = $this->token_repo->find_all_by_hash( $hash );

		if ( count( $rows ) > 1 ) {
			$this->record_failed_card_lookup();
			return $this->card_lookup_error( 'connectlibrary_card_duplicate', __( 'This card record needs librarian review before it can be used.', 'connectlibrary' ), $token, 'duplicate', $source, 0, 0 );
		}

		$row = $rows[0] ?? null;
		if ( null === $row ) {
			$this->record_failed_card_lookup();
			return $this->card_lookup_error( 'connectlibrary_card_not_found', __( 'Library card not found. Verify the card or look up the borrower by name.', 'connectlibrary' ), $token, 'not_found', $source );
		}

		$token_id    = (int) ( $row['id'] ?? 0 );
		$borrower_id = (int) ( $row['borrower_id'] ?? 0 );
		if ( 'active' !== (string) ( $row['status'] ?? '' ) || $this->is_token_expired( (string) ( $row['expires_at'] ?? '' ) ) ) {
			$this->record_failed_card_lookup();
			return $this->card_lookup_error( 'connectlibrary_card_disabled', __( 'This library card is disabled or has been replaced. Please update the patron\'s card.', 'connectlibrary' ), $token, 'disabled', $source, $token_id, $borrower_id );
		}

		$borrower = $this->borrower_repo->get( $borrower_id );
		if ( null === $borrower || 'active' !== (string) ( $borrower['status'] ?? '' ) ) {
			$this->record_failed_card_lookup();
			return $this->card_lookup_error( 'connectlibrary_card_borrower_inactive', __( 'This borrower account is not active. Please look up the borrower by name and review the account before circulation.', 'connectlibrary' ), $token, 'inactive_borrower', $source, $token_id, $borrower_id );
		}

		$this->clear_failed_card_lookup_count();
		$this->audit_card_lookup( $token, 'opened', 'ok', $source, $token_id, $borrower_id );

		return $row;
	}

	/**
	 * Build and audit a privacy-safe card lookup error.
	 *
	 * @param string $code        WP_Error code.
	 * @param string $message     Error message.
	 * @param string $token       Raw token, used only for a keyed fingerprint.
	 * @param string $category    Failure category.
	 * @param string $source      Lookup source.
	 * @param int    $token_id    Token row ID when known.
	 * @param int    $borrower_id Borrower row ID when known.
	 */
	private function card_lookup_error( string $code, string $message, string $token, string $category, string $source, int $token_id = 0, int $borrower_id = 0 ): WP_Error {
		$this->audit_card_lookup( $token, $category, 'failed', $source, $token_id, $borrower_id );

		return new WP_Error( $code, $message );
	}

	/**
	 * Whether a unified scan looks like a borrower card token/code.
	 *
	 * @param string $value Scanner input value.
	 */
	private function is_card_scan_candidate( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value ) {
			return true;
		}

		return 1 === preg_match( '/^[a-f0-9]{64}$/i', $value ) || 1 === preg_match( '/^(CLCARD|CARD)[-:]/i', $value );
	}

	/**
	 * Whether a card-shaped scan is malformed and should not fall through to item lookup.
	 *
	 * @param string $value Scanner input value.
	 */
	private function is_malformed_card_scan( string $value ): bool {
		$value = trim( $value );

		return 1 === preg_match( '/^(CLCARD|CARD)[-:]/i', $value ) && 1 !== preg_match( '/^(CLCARD|CARD)[-:][A-Za-z0-9]{16,}$/', $value );
	}

	/**
	 * Whether a stored token row has expired.
	 *
	 * @param string $expires_at MySQL datetime expiry.
	 */
	private function is_token_expired( string $expires_at ): bool {
		if ( '' === trim( $expires_at ) ) {
			return true;
		}

		return strtotime( $expires_at ) <= strtotime( current_time( 'mysql' ) );
	}

	/** Return whether the current actor/source has too many failed card lookups. */
	private function is_card_lookup_rate_limited(): bool {
		$count = (int) get_transient( $this->card_lookup_rate_key() );

		return $count >= 5;
	}

	/** Increment failed card lookup count for the current actor/source. */
	private function record_failed_card_lookup(): void {
		$key   = $this->card_lookup_rate_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 15 * 60 );
	}

	/** Clear failed card lookup count after successful open. */
	private function clear_failed_card_lookup_count(): void {
		delete_transient( $this->card_lookup_rate_key() );
	}

	/** Privacy-safe rate-limit bucket key for card lookup failures. */
	private function card_lookup_rate_key(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$ip      = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

		return 'connectlibrary_card_lookup_fail_' . md5( $user_id . '|' . $ip );
	}

	/**
	 * Log a structured, privacy-safe card lookup audit event.
	 *
	 * @param string $token       Raw token, used only for a keyed fingerprint.
	 * @param string $category    Lookup category.
	 * @param string $status      Audit event status.
	 * @param string $source      Lookup source.
	 * @param int    $token_id    Token row ID when known.
	 * @param int    $borrower_id Borrower row ID when known.
	 */
	private function audit_card_lookup( string $token, string $category, string $status, string $source, int $token_id = 0, int $borrower_id = 0 ): void {
		$context = array(
			'category'    => $category,
			'source'      => $source,
			'fingerprint' => substr( GuestAccessTokenService::hash_token( trim( $token ) ), 0, 12 ),
		);
		if ( $token_id > 0 ) {
			$context['card_id'] = $token_id;
		}

		$this->audit_events->log(
			'card_lookup',
			array(
				'source_channel' => 'admin',
				'entity_type'    => 'borrower',
				'entity_id'      => $borrower_id > 0 ? $borrower_id : 0,
				'context'        => $context,
				'status'         => $status,
				'summary'        => 'ok' === $status ? 'Library card opened circulation context.' : 'Library card lookup failed.',
			)
		);
	}

	/**
	 * Return a partially-masked email address for display in search results.
	 *
	 * Shows the first character and domain only; never exposes the full address
	 * in publicly-visible search results tables.
	 *
	 * @param string $email Email address.
	 */
	private function masked_email( string $email ): string {
		if ( '' === $email ) {
			return '';
		}

		$at = strpos( $email, '@' );
		if ( false === $at ) {
			return substr( $email, 0, 1 ) . '…';
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at );
		$masked = substr( $local, 0, 1 ) . str_repeat( '*', max( 1, strlen( $local ) - 1 ) );

		return $masked . $domain;
	}

	/**
	 * Translatable label for a borrower type key.
	 *
	 * @param string $type Type key.
	 */
	private function borrower_type_label( string $type ): string {
		$labels = array(
			'manual'  => __( 'Adult/manual', 'connectlibrary' ),
			'wp_user' => __( 'WordPress-linked', 'connectlibrary' ),
			'child'   => __( 'Child/youth', 'connectlibrary' ),
			'guest'   => __( 'Guest', 'connectlibrary' ),
		);

		return $labels[ $type ] ?? $type;
	}

	/** Base page URL. */
	private function page_url(): string {
		return admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=' . self::PAGE_SLUG );
	}

	/**
	 * Page URL with the given borrower pre-selected.
	 *
	 * @param int $borrower_id Borrower ID.
	 */
	private function page_url_with_borrower( int $borrower_id ): string {
		return add_query_arg( array( 'circ_borrower_id' => $borrower_id ), $this->page_url() );
	}

	/**
	 * Whether the given borrower is the active hold holder for exactly the given copy.
	 *
	 * Returns true only when an ACTIVE_HOLD reservation exists whose borrower_id
	 * matches the selected borrower AND whose copy_id exactly equals the selected
	 * copy_id. A hold with a missing or zero copy_id is not treated as a wildcard.
	 *
	 * @param int $borrower_id  Borrower to check.
	 * @param int $copy_id      Copy being presented for pickup.
	 * @param int $book_post_id Book the copy belongs to.
	 */
	private function is_borrower_hold_holder( int $borrower_id, int $copy_id, int $book_post_id ): bool {
		foreach ( $this->reservation_repo->active_holds_for_book( $book_post_id ) as $hold ) {
			if ( (int) ( $hold['borrower_id'] ?? 0 ) !== $borrower_id ) {
				continue;
			}
			if ( (int) ( $hold['copy_id'] ?? 0 ) === $copy_id ) {
				return true;
			}
		}
		return false;
	}
}
