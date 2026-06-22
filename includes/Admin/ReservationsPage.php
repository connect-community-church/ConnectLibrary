<?php
/**
 * Librarian reservation admin screen.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Reservations\ReservationStatuses;
use ConnectLibrary\Support\Capabilities;

/**
 * Registers and renders protected reservation management seams.
 */
final class ReservationsPage {
	private const PAGE_SLUG    = 'connectlibrary-reservations';
	private const ACTION_NAME  = 'connectlibrary_reservation_action';
	private const NONCE_ACTION = 'connectlibrary_reservation_action';

	/**
	 * Reservation service dependency.
	 *
	 * @var ReservationService
	 */
	private ReservationService $service;

	/**
	 * Create page dependencies.
	 *
	 * @param ReservationService|null $service Optional service override.
	 */
	public function __construct( ?ReservationService $service = null ) {
		$this->service = $service ?? new ReservationService();
	}

	/** Register admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_' . self::ACTION_NAME, array( $this, 'handle_post' ) );
	}

	/** Add the reservations screen under the Library admin menu. */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . BookPostType::POST_TYPE,
			esc_html__( 'ConnectLibrary Reservations', 'connectlibrary' ),
			esc_html__( 'Reservations', 'connectlibrary' ),
			Capabilities::MANAGE_BORROWERS,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/** Render protected pending request, active waitlist, and active hold lists. */
	public function render(): void {
		if ( ! Capabilities::can_manage_borrowers() ) {
			wp_die( esc_html__( 'You do not have permission to manage reservations.', 'connectlibrary' ) );
			return;
		}

		$pending_requests = $this->service->pending_guest_requests();
		$waitlist_entries = $this->service->active_waitlist_entries();
		$active_holds     = $this->service->active_pickup_holds();
		?>
		<div class="wrap connectlibrary-reservations-admin">
			<h1><?php echo esc_html__( 'Reservations', 'connectlibrary' ); ?></h1>
			<?php $this->render_notice(); ?>
			<p><?php echo esc_html__( 'Protected librarian-only view for pending guest reservation requests, waitlisted entries, and active pickup holds.', 'connectlibrary' ); ?></p>
			<?php $this->render_pending_requests( $pending_requests ); ?>
			<?php $this->render_waitlist_entries( $waitlist_entries ); ?>
			<?php $this->render_active_holds( $active_holds ); ?>
		</div>
		<?php
	}

	/** Handle nonce-protected reservation action submissions. */
	public function handle_post(): void {
		if ( ! Capabilities::can_manage_borrowers() ) {
			wp_die( esc_html__( 'You do not have permission to manage reservations.', 'connectlibrary' ) );
			return;
		}
		if ( false === check_admin_referer( self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Reservation action security check failed.', 'connectlibrary' ) );
			return;
		}

		$reservation_id = absint( wp_unslash( $_POST['reservation_id'] ?? 0 ) );
		$action         = sanitize_key( wp_unslash( $_POST['reservation_task'] ?? '' ) );
		$expires_at     = sanitize_text_field( wp_unslash( $_POST['hold_expires_at'] ?? '' ) );
		$reason         = $this->admin_action_reason( $action );

		$book_id = absint( wp_unslash( $_POST['connectlibrary_book_id'] ?? 0 ) );
		if ( in_array( $action, array( 'extend', 'expire', 'cancel' ), true ) && empty( $_POST['confirm_reservation_override'] ) ) {
			$result = new \WP_Error(
				'connectlibrary_reservation_confirm_required',
				__( 'Confirm the hold override before continuing.', 'connectlibrary' ),
				array( 'status' => 422 )
			);
		} else {

			$result = match ( $action ) {
				'approve' => $this->service->approve( $reservation_id, $reason ),
				'deny'    => $this->service->deny( $reservation_id, $reason ),
				'cancel'  => $this->service->cancel( $reservation_id, $reason ),
				'expire'  => $this->service->expire( $reservation_id, $reason ),
				'extend'  => $this->service->extend( $reservation_id, '' !== $expires_at ? $expires_at : null, $reason ),
				'promote' => $this->handle_promote( $book_id ),
				default   => new \WP_Error(
					'connectlibrary_reservation_action_invalid',
					__( 'Unknown reservation action.', 'connectlibrary' ),
					array( 'status' => 400 )
				),
			};
		}

		$args = is_wp_error( $result )
			? array(
				'reservation_action' => $action,
				'reservation_error'  => $result->get_error_code(),
			)
			: array(
				'reservation_action' => $action,
				'reservation_status' => 'ok',
			);

		wp_safe_redirect( add_query_arg( $args, $this->page_url() ) );
	}

	/**
	 * Promote the next waitlisted entry for a book, or return WP_Error.
	 *
	 * @param int $book_id Book post ID from the admin form.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function handle_promote( int $book_id ): array|\WP_Error {
		if ( $book_id <= 0 ) {
			return new \WP_Error(
				'connectlibrary_reservation_invalid',
				__( 'Book ID is required to promote from the waitlist.', 'connectlibrary' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->service->promote_next_waitlisted( $book_id );

		if ( null === $result ) {
			return new \WP_Error(
				'connectlibrary_reservation_no_copy',
				__( 'No available copy to assign right now.', 'connectlibrary' ),
				array( 'status' => 409 )
			);
		}

		return $result;
	}

	/**
	 * Render pending guest reservation requests.
	 *
	 * @param array<int,array<string,mixed>> $requests Pending request rows.
	 */
	private function render_pending_requests( array $requests ): void {
		?>
		<h2><?php echo esc_html__( 'Pending guest requests', 'connectlibrary' ); ?></h2>
		<table class="widefat striped connectlibrary-pending-reservations">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Book', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Guest', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Contact', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Requested', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Actions', 'connectlibrary' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $requests ) : ?>
					<tr><td colspan="5"><?php echo esc_html__( 'No pending guest requests.', 'connectlibrary' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $requests as $request ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $this->book_title( $request ) ); ?></th>
						<td><?php echo esc_html( (string) ( $request['guest_name'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $request['guest_email'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $request['requested_at'] ?? '' ) ); ?></td>
						<td><?php $this->render_action_buttons( (int) ( $request['id'] ?? 0 ), array( 'approve', 'deny' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the active waitlist queue.
	 *
	 * Shows all WAITLISTED entries with a Promote and Cancel button per row.
	 * The Promote button passes the book_post_id to promote the FIFO-next
	 * entry in queue for that title.
	 *
	 * @param array<int,array<string,mixed>> $entries Waitlisted reservation rows.
	 */
	private function render_waitlist_entries( array $entries ): void {
		?>
		<h2><?php echo esc_html__( 'Waitlist queue', 'connectlibrary' ); ?></h2>
		<table class="widefat striped connectlibrary-waitlist-entries">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Book', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Patron', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Queued', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Actions', 'connectlibrary' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $entries ) : ?>
					<tr><td colspan="4"><?php echo esc_html__( 'No active waitlist entries.', 'connectlibrary' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $this->book_title( $entry ) ); ?></th>
						<td><?php echo esc_html( $this->holder_summary( $entry ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['requested_at'] ?? '' ) ); ?></td>
						<td>
							<?php $this->render_action_buttons( (int) ( $entry['id'] ?? 0 ), array( 'cancel' ) ); ?>
							<?php $this->render_promote_button( (int) ( $entry['id'] ?? 0 ), (int) ( $entry['book_post_id'] ?? 0 ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a promote-next button that passes the book_post_id.
	 *
	 * @param int $reservation_id Reservation ID (for form identity only).
	 * @param int $book_post_id   Book post ID to promote next from.
	 */
	private function render_promote_button( int $reservation_id, int $book_post_id ): void {
		if ( $book_post_id <= 0 ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:4px;">
			<?php wp_nonce_field( self::NONCE_ACTION, '_wpnonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
			<input type="hidden" name="reservation_id" value="<?php echo esc_attr( (string) $reservation_id ); ?>" />
			<input type="hidden" name="connectlibrary_book_id" value="<?php echo esc_attr( (string) $book_post_id ); ?>" />
			<input type="hidden" name="reservation_task" value="promote" />
			<button type="submit" class="button button-primary"><?php echo esc_html__( 'Promote Next', 'connectlibrary' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render active pickup holds.
	 *
	 * @param array<int,array<string,mixed>> $holds Active hold rows.
	 */
	private function render_active_holds( array $holds ): void {
		?>
		<h2><?php echo esc_html__( 'Active pickup holds', 'connectlibrary' ); ?></h2>
		<table class="widefat striped connectlibrary-active-holds">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Book', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Holder', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Hold expires', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Actions', 'connectlibrary' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $holds ) : ?>
					<tr><td colspan="4"><?php echo esc_html__( 'No active pickup holds.', 'connectlibrary' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $holds as $hold ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $this->book_title( $hold ) ); ?></th>
						<td><?php echo esc_html( $this->holder_summary( $hold ) ); ?></td>
						<td><?php echo esc_html( (string) ( $hold['hold_expires_at'] ?? '' ) ); ?></td>
						<td><?php $this->render_action_buttons( (int) ( $hold['id'] ?? 0 ), array( 'extend', 'expire', 'cancel' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render compact POST buttons for allowed actions.
	 *
	 * @param int      $reservation_id Reservation ID.
	 * @param string[] $actions        Action keys.
	 */
	private function render_action_buttons( int $reservation_id, array $actions ): void {
		foreach ( $actions as $action ) {
			$needs_confirm = in_array( $action, array( 'extend', 'expire', 'cancel' ), true );
			$reason_id     = 'connectlibrary-reservation-reason-' . $reservation_id . '-' . $action;
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:4px;vertical-align:top;" aria-describedby="connectlibrary-reservation-warning-<?php echo esc_attr( (string) $reservation_id . '-' . $action ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, '_wpnonce' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
				<input type="hidden" name="reservation_id" value="<?php echo esc_attr( (string) $reservation_id ); ?>" />
				<input type="hidden" name="reservation_task" value="<?php echo esc_attr( $action ); ?>" />
				<?php if ( $needs_confirm ) : ?>
					<fieldset style="border:0;padding:0;margin:0 0 0.25em 0;">
						<legend class="screen-reader-text"><?php echo esc_html( $this->action_label( $action ) ); ?></legend>
						<p id="connectlibrary-reservation-warning-<?php echo esc_attr( (string) $reservation_id . '-' . $action ); ?>" class="description" role="status" aria-live="polite"><?php echo esc_html__( 'Protected hold override. Enter may come from a scanner, so review the reason and checkbox before confirming.', 'connectlibrary' ); ?></p>
						<label for="<?php echo esc_attr( $reason_id ); ?>"><?php echo esc_html__( 'Reason', 'connectlibrary' ); ?></label><br />
						<input id="<?php echo esc_attr( $reason_id ); ?>" type="text" name="override_reason" class="regular-text" style="max-width:12em;" />
						<label style="display:block;"><input type="checkbox" name="confirm_reservation_override" value="1" required /> <?php echo esc_html__( 'Confirm override', 'connectlibrary' ); ?></label>
						<a href="<?php echo esc_url( $this->page_url() ); ?>" class="button"><?php echo esc_html__( 'Cancel', 'connectlibrary' ); ?></a>
					</fieldset>
				<?php endif; ?>
				<button type="submit" class="button"><?php echo esc_html( $this->action_label( $action ) ); ?></button>
			</form>
			<?php
		}
	}

	/** Render notice from query string without exposing row identifiers. */
	private function render_notice(): void {
		$status = sanitize_key( wp_unslash( $_GET['reservation_status'] ?? '' ) );
		$error  = sanitize_key( wp_unslash( $_GET['reservation_error'] ?? '' ) );
		if ( 'ok' === $status ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Reservation action completed.', 'connectlibrary' ) );
		}
		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $this->error_message( $error ) ) );
		}
	}

	/**
	 * Human message for service/action errors.
	 *
	 * @param string $code Error code.
	 */
	private function error_message( string $code ): string {
		return match ( $code ) {
			'connectlibrary_reservation_not_found'          => __( 'Reservation could not be found.', 'connectlibrary' ),
			'connectlibrary_reservation_invalid_transition' => __( 'Reservation is not in a status that allows that action.', 'connectlibrary' ),
			'connectlibrary_reservation_no_copy'            => __( 'No available copy can be assigned right now.', 'connectlibrary' ),
			'connectlibrary_reservation_action_invalid'     => __( 'Unknown reservation action.', 'connectlibrary' ),
			'connectlibrary_reservation_confirm_required'   => __( 'Confirm the hold override before continuing.', 'connectlibrary' ),
			'connectlibrary_reservation_invalid'            => __( 'Invalid reservation action data.', 'connectlibrary' ),
			default                                         => __( 'Reservation action could not be completed.', 'connectlibrary' ),
		};
	}

	/**
	 * Build a protected admin book title summary.
	 *
	 * @param array<string,mixed> $row Reservation row.
	 */
	private function book_title( array $row ): string {
		$book_post_id = (int) ( $row['book_post_id'] ?? 0 );
		$title        = $book_post_id > 0 ? get_the_title( $book_post_id ) : '';

		return '' !== $title ? $title : __( 'Library item', 'connectlibrary' );
	}

	/**
	 * Build a protected admin holder summary.
	 *
	 * @param array<string,mixed> $row Reservation row.
	 */
	private function holder_summary( array $row ): string {
		if ( ! empty( $row['guest_name'] ) ) {
			return (string) $row['guest_name'];
		}

		$borrower_id = (int) ( $row['borrower_id'] ?? 0 );
		if ( $borrower_id > 0 ) {
			return __( 'Registered borrower', 'connectlibrary' );
		}

		return __( 'Unknown holder', 'connectlibrary' );
	}

	/**
	 * Button label for action key.
	 *
	 * @param string $action Action key.
	 */
	private function action_label( string $action ): string {
		return match ( $action ) {
			'approve' => __( 'Approve', 'connectlibrary' ),
			'deny'    => __( 'Deny', 'connectlibrary' ),
			'cancel'  => __( 'Cancel', 'connectlibrary' ),
			'expire'  => __( 'Expire', 'connectlibrary' ),
			'extend'  => __( 'Extend', 'connectlibrary' ),
			'promote' => __( 'Promote Next', 'connectlibrary' ),
			default   => __( 'Submit', 'connectlibrary' ),
		};
	}

	/**
	 * Build a fixed, privacy-safe audit reason for protected admin transitions.
	 *
	 * Arbitrary POSTed reason text is intentionally ignored for this micro-slice
	 * so redirect/audit handling cannot leak guest names, contact data, notes, or
	 * internal row identifiers through the librarian action seam.
	 *
	 * @param string $action Action key.
	 */
	private function admin_action_reason( string $action ): string {
		return match ( $action ) {
			'approve', 'deny', 'cancel', 'expire', 'extend', 'promote' => 'admin_' . $action,
			default => 'admin_action_invalid',
		};
	}

	/** Admin page URL. */
	private function page_url(): string {
		return admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=' . self::PAGE_SLUG );
	}
}
