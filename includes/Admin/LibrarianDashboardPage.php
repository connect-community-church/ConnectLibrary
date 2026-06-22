<?php
/**
 * Librarian operations dashboard shell.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda

use ConnectLibrary\Admin\AuditHistoryPage;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Settings\Settings;
use ConnectLibrary\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only organizing shell for the Phase 3 librarian dashboard.
 *
 * Surfaces summary counts, quick scanner-friendly lookup forms, and deep
 * links into the existing Phase 2 admin pages.  No state-changing circulation
 * or reservation operations are performed here.
 */
final class LibrarianDashboardPage {

	public const PAGE_SLUG = 'connectlibrary-librarian-dashboard';

	/**
	 * Reservation service (read-only list helpers only).
	 *
	 * @var ReservationService
	 */
	private ReservationService $reservation_service;

	/**
	 * Loan repository (read-only; used for due-soon / overdue counts).
	 *
	 * @var LoanRepository
	 */
	private LoanRepository $loan_repo;

	/**
	 * Create page with optional dependency overrides for testing.
	 *
	 * @param ReservationService|null $reservation_service Optional override.
	 * @param LoanRepository|null     $loan_repo           Optional override.
	 */
	public function __construct(
		?ReservationService $reservation_service = null,
		?LoanRepository $loan_repo = null
	) {
		$this->reservation_service = $reservation_service ?? new ReservationService();
		$this->loan_repo           = $loan_repo ?? new LoanRepository();
	}

	/** Register admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/** Add the dashboard as the first Library submenu item. */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . BookPostType::POST_TYPE,
			esc_html__( 'Librarian Dashboard', 'connectlibrary' ),
			esc_html__( 'Dashboard', 'connectlibrary' ),
			Capabilities::MANAGE_CIRCULATION,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/** Render the librarian dashboard. */
	public function render(): void {
		if ( ! Capabilities::can_manage_circulation() ) {
			wp_die( esc_html__( 'You do not have permission to view the librarian dashboard.', 'connectlibrary' ) );
			return;
		}

		$sunday_mode = '1' === sanitize_key( wp_unslash( $_GET['sunday_mode'] ?? '' ) );

		// Gather data — never mutates state.
		$active_holds   = $this->reservation_service->active_pickup_holds();
		$pending_guests = $this->reservation_service->pending_guest_requests();
		$waitlist       = $this->reservation_service->active_waitlist_entries();
		$all_loans      = $this->loan_repo->all();

		// Sort active holds by soonest hold_expires_at.
		usort(
			$active_holds,
			static fn( array $a, array $b ): int =>
				strcmp( (string) ( $a['hold_expires_at'] ?? '' ), (string) ( $b['hold_expires_at'] ?? '' ) )
		);

		// Compute due-soon and overdue counts using WP site timezone.
		$now_mysql    = current_time( 'mysql' );
		$now_ts       = (int) strtotime( $now_mysql );
		$lead_days    = (int) Settings::get( 'due_reminder_lead_days' );
		$cutoff_mysql = date( 'Y-m-d H:i:s', $now_ts + $lead_days * 86400 ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

		$active_loans = array_values(
			array_filter( $all_loans, static fn( array $l ): bool => 'active' === ( $l['status'] ?? '' ) )
		);
		$due_soon     = array_values(
			array_filter(
				$active_loans,
				static fn( array $l ): bool =>
					( $l['due_at'] ?? '' ) >= $now_mysql && ( $l['due_at'] ?? '' ) <= $cutoff_mysql
			)
		);
		$overdue      = array_values(
			array_filter(
				$active_loans,
				static fn( array $l ): bool =>
					'' !== ( $l['due_at'] ?? '' ) && ( $l['due_at'] ?? '' ) < $now_mysql
			)
		);

		$pickup_instructions = (string) Settings::get( 'pickup_instructions' );

		$wrapper_class = 'wrap connectlibrary-librarian-dashboard';
		if ( $sunday_mode ) {
			$wrapper_class .= ' connectlibrary-sunday-mode';
		}
		?>
		<div class="<?php echo esc_attr( $wrapper_class ); ?>">

			<h1><?php esc_html_e( 'Librarian Dashboard', 'connectlibrary' ); ?></h1>

			<?php if ( $sunday_mode ) : ?>
				<div class="notice notice-info inline connectlibrary-sunday-mode-notice">
					<p>
						<strong><?php esc_html_e( 'Sunday Mode', 'connectlibrary' ); ?></strong>
						&mdash;
						<?php esc_html_e( 'Quick circulation actions and queues appear first.', 'connectlibrary' ); ?>
						&ensp;<a href="<?php echo esc_url( $this->page_url() ); ?>">
							<?php esc_html_e( 'Exit Sunday Mode', 'connectlibrary' ); ?>
						</a>
					</p>
				</div>
			<?php else : ?>
				<p>
					<a class="button button-secondary connectlibrary-sunday-mode-toggle"
						href="<?php echo esc_url( add_query_arg( array( 'sunday_mode' => '1' ), $this->page_url() ) ); ?>">
						<?php esc_html_e( 'Enter Sunday Mode', 'connectlibrary' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php
			if ( $sunday_mode ) {
				// Sunday mode: scanner forms and queues first, summary cards and admin links below.
				$this->render_scanner_panel( $sunday_mode );
				$this->render_active_holds_queue( $active_holds );
				$this->render_loan_alerts( $due_soon, $overdue );
				$this->render_summary_cards(
					count( $active_holds ),
					count( $pending_guests ),
					count( $waitlist ),
					count( $due_soon ),
					count( $overdue ),
					count( $active_loans )
				);
				$this->render_admin_links();
				$this->render_hours_panel( $pickup_instructions );
			} else {
				// Normal mode: summary, scanner, queues, admin.
				$this->render_summary_cards(
					count( $active_holds ),
					count( $pending_guests ),
					count( $waitlist ),
					count( $due_soon ),
					count( $overdue ),
					count( $active_loans )
				);
				$this->render_scanner_panel( $sunday_mode );
				$this->render_active_holds_queue( $active_holds );
				$this->render_loan_alerts( $due_soon, $overdue );
				$this->render_admin_links();
				$this->render_hours_panel( $pickup_instructions );
			}
			?>

		</div><!-- .connectlibrary-librarian-dashboard -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Section renderers
	// -------------------------------------------------------------------------

	/**
	 * Render summary count cards with links to the relevant admin pages.
	 *
	 * @param int $holds_count   Active pickup holds.
	 * @param int $guests_count  Pending guest requests.
	 * @param int $waitlist_count Active waitlist entries.
	 * @param int $due_soon_count  Loans due within the reminder window.
	 * @param int $overdue_count  Overdue active loans.
	 * @param int $loans_count   All active loans.
	 */
	private function render_summary_cards(
		int $holds_count,
		int $guests_count,
		int $waitlist_count,
		int $due_soon_count,
		int $overdue_count,
		int $loans_count
	): void {
		$reservations_url = $this->reservations_url();
		$circulation_url  = $this->circulation_url();
		$borrowers_url    = $this->borrowers_url();
		$settings_url     = $this->settings_url();
		$add_book_url     = admin_url( 'post-new.php?post_type=' . BookPostType::POST_TYPE );
		?>
		<h2><?php esc_html_e( 'Library at a Glance', 'connectlibrary' ); ?></h2>
		<div class="connectlibrary-dashboard-cards" style="display:flex;flex-wrap:wrap;gap:1em;margin-bottom:2em;">

			<div class="connectlibrary-dashboard-card card <?php echo $holds_count > 0 ? 'connectlibrary-card-attention' : ''; ?>"
				style="min-width:160px;padding:1em;text-align:center;">
				<a href="<?php echo esc_url( $reservations_url ); ?>" style="text-decoration:none;color:inherit;">
					<div class="connectlibrary-card-count" style="font-size:2em;font-weight:bold;">
						<?php echo esc_html( (string) $holds_count ); ?>
					</div>
					<div class="connectlibrary-card-label">
						<?php esc_html_e( 'Active Pickup Holds', 'connectlibrary' ); ?>
					</div>
				</a>
			</div>

			<div class="connectlibrary-dashboard-card card <?php echo $guests_count > 0 ? 'connectlibrary-card-attention' : ''; ?>"
				style="min-width:160px;padding:1em;text-align:center;">
				<a href="<?php echo esc_url( $reservations_url ); ?>" style="text-decoration:none;color:inherit;">
					<div class="connectlibrary-card-count" style="font-size:2em;font-weight:bold;">
						<?php echo esc_html( (string) $guests_count ); ?>
					</div>
					<div class="connectlibrary-card-label">
						<?php esc_html_e( 'Pending Guest Requests', 'connectlibrary' ); ?>
					</div>
				</a>
			</div>

			<div class="connectlibrary-dashboard-card card"
				style="min-width:160px;padding:1em;text-align:center;">
				<a href="<?php echo esc_url( $reservations_url ); ?>" style="text-decoration:none;color:inherit;">
					<div class="connectlibrary-card-count" style="font-size:2em;font-weight:bold;">
						<?php echo esc_html( (string) $waitlist_count ); ?>
					</div>
					<div class="connectlibrary-card-label">
						<?php esc_html_e( 'Waitlist / Reservations', 'connectlibrary' ); ?>
					</div>
				</a>
			</div>

			<div class="connectlibrary-dashboard-card card <?php echo $due_soon_count > 0 ? 'connectlibrary-card-warning' : ''; ?>"
				style="min-width:160px;padding:1em;text-align:center;">
				<a href="<?php echo esc_url( $circulation_url ); ?>" style="text-decoration:none;color:inherit;">
					<div class="connectlibrary-card-count" style="font-size:2em;font-weight:bold;">
						<?php echo esc_html( (string) $due_soon_count ); ?>
					</div>
					<div class="connectlibrary-card-label">
						<?php esc_html_e( 'Due Soon', 'connectlibrary' ); ?>
					</div>
				</a>
			</div>

			<div class="connectlibrary-dashboard-card card <?php echo $overdue_count > 0 ? 'connectlibrary-card-alert' : ''; ?>"
				style="min-width:160px;padding:1em;text-align:center;">
				<a href="<?php echo esc_url( $circulation_url ); ?>" style="text-decoration:none;color:inherit;">
					<div class="connectlibrary-card-count" style="font-size:2em;font-weight:bold;">
						<?php echo esc_html( (string) $overdue_count ); ?>
					</div>
					<div class="connectlibrary-card-label">
						<?php esc_html_e( 'Overdue', 'connectlibrary' ); ?>
					</div>
				</a>
			</div>

			<div class="connectlibrary-dashboard-card card"
				style="min-width:160px;padding:1em;text-align:center;">
				<a href="<?php echo esc_url( $circulation_url ); ?>" style="text-decoration:none;color:inherit;">
					<div class="connectlibrary-card-count" style="font-size:2em;font-weight:bold;">
						<?php echo esc_html( (string) $loans_count ); ?>
					</div>
					<div class="connectlibrary-card-label">
						<?php esc_html_e( 'Active Loans', 'connectlibrary' ); ?>
					</div>
				</a>
			</div>

		</div><!-- .connectlibrary-dashboard-cards -->

		<?php if ( 0 === $holds_count && 0 === $guests_count && 0 === $waitlist_count && 0 === $loans_count ) : ?>
			<div class="notice notice-info inline connectlibrary-empty-state">
				<p>
					<?php esc_html_e( 'The library has no active holds, requests, or loans yet.', 'connectlibrary' ); ?>
					<?php esc_html_e( 'Add books to the catalog and set up borrowers to get started.', 'connectlibrary' ); ?>
					&ensp;<a href="<?php echo esc_url( $add_book_url ); ?>"><?php esc_html_e( 'Add a book', 'connectlibrary' ); ?></a>
					&ensp;<a href="<?php echo esc_url( $borrowers_url ); ?>"><?php esc_html_e( 'Manage borrowers', 'connectlibrary' ); ?></a>
					&ensp;<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'connectlibrary' ); ?></a>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render scanner-friendly borrower and item lookup forms.
	 *
	 * Forms pass to the existing CirculationPage GET conventions so the librarian
	 * lands on the circulation dashboard with the lookup already resolved.
	 *
	 * @param bool $sunday_mode Whether sunday mode is active (larger tap targets).
	 */
	private function render_scanner_panel( bool $sunday_mode ): void {
		$button_size = $sunday_mode ? 'button-large' : 'button';
		?>
		<h2><?php esc_html_e( 'Quick Lookup', 'connectlibrary' ); ?></h2>
		<div class="connectlibrary-scanner-panel" style="display:flex;flex-wrap:wrap;gap:2em;margin-bottom:2em;">

			<!-- Card token / borrower scan -->
			<div class="connectlibrary-scanner-form" style="min-width:260px;">
				<h3><?php esc_html_e( 'Borrower Card / Token', 'connectlibrary' ); ?></h3>
				<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
					<input type="hidden" name="post_type" value="<?php echo esc_attr( BookPostType::POST_TYPE ); ?>" />
					<input type="hidden" name="page" value="connectlibrary-circulation" />
					<label for="cl-dash-card-token" class="screen-reader-text">
						<?php esc_html_e( 'Scan or enter card token', 'connectlibrary' ); ?>
					</label>
					<input
						id="cl-dash-card-token"
						type="text"
						name="circ_card_token"
						autocomplete="off"
						placeholder="<?php echo esc_attr__( 'Scan card token…', 'connectlibrary' ); ?>"
						class="regular-text"
						<?php echo $sunday_mode ? 'style="font-size:1.1em;height:2.2em;"' : ''; ?>
					/>
					<button type="submit" class="button <?php echo esc_attr( $button_size ); ?>" style="margin-top:0.4em;display:block;">
						<?php esc_html_e( 'Look up borrower', 'connectlibrary' ); ?>
					</button>
				</form>

				<h3 style="margin-top:1em;"><?php esc_html_e( 'Search by Name', 'connectlibrary' ); ?></h3>
				<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
					<input type="hidden" name="post_type" value="<?php echo esc_attr( BookPostType::POST_TYPE ); ?>" />
					<input type="hidden" name="page" value="connectlibrary-circulation" />
					<label for="cl-dash-name-search" class="screen-reader-text">
						<?php esc_html_e( 'Search borrowers by name', 'connectlibrary' ); ?>
					</label>
					<input
						id="cl-dash-name-search"
						type="text"
						name="circ_name_search"
						autocomplete="off"
						placeholder="<?php echo esc_attr__( 'Borrower name…', 'connectlibrary' ); ?>"
						class="regular-text"
						<?php echo $sunday_mode ? 'style="font-size:1.1em;height:2.2em;"' : ''; ?>
					/>
					<button type="submit" class="button <?php echo esc_attr( $button_size ); ?>" style="margin-top:0.4em;display:block;">
						<?php esc_html_e( 'Search borrowers', 'connectlibrary' ); ?>
					</button>
				</form>
			</div>

			<!-- ISBN / copy barcode scan -->
			<div class="connectlibrary-scanner-form" style="min-width:260px;">
				<h3><?php esc_html_e( 'Item / ISBN / Barcode', 'connectlibrary' ); ?></h3>
				<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
					<input type="hidden" name="post_type" value="<?php echo esc_attr( BookPostType::POST_TYPE ); ?>" />
					<input type="hidden" name="page" value="connectlibrary-circulation" />
					<label for="cl-dash-copy-search" class="screen-reader-text">
						<?php esc_html_e( 'Search by ISBN or copy barcode', 'connectlibrary' ); ?>
					</label>
					<input
						id="cl-dash-copy-search"
						type="text"
						name="circ_copy_search"
						autocomplete="off"
						placeholder="<?php echo esc_attr__( 'Scan ISBN or barcode…', 'connectlibrary' ); ?>"
						class="regular-text"
						<?php echo $sunday_mode ? 'style="font-size:1.1em;height:2.2em;"' : ''; ?>
					/>
					<button type="submit" class="button <?php echo esc_attr( $button_size ); ?>" style="margin-top:0.4em;display:block;">
						<?php esc_html_e( 'Find item', 'connectlibrary' ); ?>
					</button>
				</form>
			</div>

		</div><!-- .connectlibrary-scanner-panel -->
		<?php
	}

	/**
	 * Render the active pickup holds queue sorted by soonest expiry.
	 *
	 * @param array<int,array<string,mixed>> $holds Active holds sorted by hold_expires_at.
	 */
	private function render_active_holds_queue( array $holds ): void {
		?>
		<h2><?php esc_html_e( 'Active Pickup Holds', 'connectlibrary' ); ?></h2>
		<?php if ( array() === $holds ) : ?>
			<p class="description connectlibrary-empty-state">
				<?php esc_html_e( 'No active pickup holds at this time.', 'connectlibrary' ); ?>
			</p>
		<?php else : ?>
			<table class="widefat striped connectlibrary-holds-queue" style="margin-bottom:2em;max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Book', 'connectlibrary' ); ?></th>
						<th><?php esc_html_e( 'Borrower / Patron', 'connectlibrary' ); ?></th>
						<th><?php esc_html_e( 'Hold Expires', 'connectlibrary' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'connectlibrary' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $holds as $hold ) : ?>
						<?php
						$book_id     = (int) ( $hold['book_post_id'] ?? 0 );
						$book_title  = $book_id > 0 && function_exists( 'get_the_title' )
							? get_the_title( $book_id ) : '';
						$borrower_id = (int) ( $hold['borrower_id'] ?? 0 );
						$expires     = (string) ( $hold['hold_expires_at'] ?? '' );
						$patron      = $borrower_id > 0 ? __( 'Registered borrower', 'connectlibrary' ) : __( 'Guest', 'connectlibrary' );

						// Deep link to reservations page for editing the hold.
						$manage_url = $this->reservations_url();
						?>
						<tr>
							<td><?php echo esc_html( $book_title ); ?></td>
							<td><?php echo esc_html( $patron ); ?></td>
							<td><?php echo esc_html( $expires ); ?></td>
							<td>
								<a href="<?php echo esc_url( $manage_url ); ?>" class="button button-small">
									<?php esc_html_e( 'Manage', 'connectlibrary' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render due-soon and overdue loan alert sections.
	 *
	 * @param array<int,array<string,mixed>> $due_soon Loans due within reminder window.
	 * @param array<int,array<string,mixed>> $overdue  Overdue active loans.
	 */
	private function render_loan_alerts(
		array $due_soon,
		array $overdue
	): void {
		$circulation_url = $this->circulation_url();

		if ( array() !== $overdue ) :
			?>
			<h2><?php esc_html_e( 'Overdue Loans', 'connectlibrary' ); ?></h2>
			<table class="widefat striped connectlibrary-overdue-loans" style="margin-bottom:2em;max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Borrower', 'connectlibrary' ); ?></th>
						<th><?php esc_html_e( 'Book', 'connectlibrary' ); ?></th>
						<th><?php esc_html_e( 'Due', 'connectlibrary' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'connectlibrary' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $overdue as $loan ) : ?>
						<?php
						$borrower_id  = (int) ( $loan['borrower_id'] ?? 0 );
						$book_id      = (int) ( $loan['book_post_id'] ?? 0 );
						$book_title   = $book_id > 0 && function_exists( 'get_the_title' ) ? get_the_title( $book_id ) : '';
						$due_at       = (string) ( $loan['due_at'] ?? '' );
						$borrower_lnk = add_query_arg(
							array( 'circ_borrower_id' => $borrower_id ),
							$circulation_url
						);
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $borrower_lnk ); ?>">
									<?php
									echo esc_html__( 'Registered borrower', 'connectlibrary' );
									?>
								</a>
							</td>
							<td><?php echo esc_html( $book_title ); ?></td>
							<td><?php echo esc_html( $due_at ); ?></td>
							<td>
								<a href="<?php echo esc_url( $borrower_lnk ); ?>" class="button button-small">
									<?php esc_html_e( 'View', 'connectlibrary' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;

		if ( array() !== $due_soon ) :
			?>
			<h2><?php esc_html_e( 'Due Soon', 'connectlibrary' ); ?></h2>
			<table class="widefat striped connectlibrary-due-soon-loans" style="margin-bottom:2em;max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Borrower', 'connectlibrary' ); ?></th>
						<th><?php esc_html_e( 'Book', 'connectlibrary' ); ?></th>
						<th><?php esc_html_e( 'Due', 'connectlibrary' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'connectlibrary' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $due_soon as $loan ) : ?>
						<?php
						$borrower_id  = (int) ( $loan['borrower_id'] ?? 0 );
						$book_id      = (int) ( $loan['book_post_id'] ?? 0 );
						$book_title   = $book_id > 0 && function_exists( 'get_the_title' ) ? get_the_title( $book_id ) : '';
						$due_at       = (string) ( $loan['due_at'] ?? '' );
						$borrower_lnk = add_query_arg(
							array( 'circ_borrower_id' => $borrower_id ),
							$circulation_url
						);
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $borrower_lnk ); ?>">
									<?php
									echo esc_html__( 'Registered borrower', 'connectlibrary' );
									?>
								</a>
							</td>
							<td><?php echo esc_html( $book_title ); ?></td>
							<td><?php echo esc_html( $due_at ); ?></td>
							<td>
								<a href="<?php echo esc_url( $borrower_lnk ); ?>" class="button button-small">
									<?php esc_html_e( 'View', 'connectlibrary' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;
	}

	/** Render quick-navigation admin links. */
	private function render_admin_links(): void {
		?>
		<h2><?php esc_html_e( 'Library Admin', 'connectlibrary' ); ?></h2>
		<ul class="connectlibrary-admin-links" style="margin-bottom:2em;">
			<li>
				<a href="<?php echo esc_url( $this->circulation_url() ); ?>">
					<?php esc_html_e( 'Circulation Dashboard', 'connectlibrary' ); ?>
				</a>
				&mdash; <?php esc_html_e( 'Checkout, return, renew items', 'connectlibrary' ); ?>
			</li>
			<li>
				<a href="<?php echo esc_url( $this->reservations_url() ); ?>">
					<?php esc_html_e( 'Reservations &amp; Holds', 'connectlibrary' ); ?>
				</a>
				&mdash; <?php esc_html_e( 'Approve guest requests, manage waitlist', 'connectlibrary' ); ?>
			</li>
			<li>
				<a href="<?php echo esc_url( $this->borrowers_url() ); ?>">
					<?php esc_html_e( 'Borrowers', 'connectlibrary' ); ?>
				</a>
				&mdash; <?php esc_html_e( 'Add or edit library card holders', 'connectlibrary' ); ?>
			</li>
			<li>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . BookPostType::POST_TYPE ) ); ?>">
					<?php esc_html_e( 'Add / Edit Books', 'connectlibrary' ); ?>
				</a>
				&mdash; <?php esc_html_e( 'Add a new book to the catalog', 'connectlibrary' ); ?>
			</li>
			<li>
				<a href="<?php echo esc_url( $this->settings_url() ); ?>">
					<?php esc_html_e( 'Settings &amp; Hours', 'connectlibrary' ); ?>
				</a>
				&mdash; <?php esc_html_e( 'Loan periods, pickup instructions, notifications', 'connectlibrary' ); ?>
			</li>
			<li>
				<a href="<?php echo esc_url( $this->reports_url() ); ?>">
					<?php esc_html_e( 'Reports', 'connectlibrary' ); ?>
				</a>
				&mdash; <?php esc_html_e( 'Overdue, holds, inventory and activity reports', 'connectlibrary' ); ?>
			</li>
			<li>
				<a href="<?php echo esc_url( $this->audit_history_url() ); ?>">
					<?php esc_html_e( 'Audit &amp; History', 'connectlibrary' ); ?>
				</a>
				&mdash; <?php esc_html_e( 'Review librarian activity, corrections, overrides and scoped histories', 'connectlibrary' ); ?>
			</li>
		</ul>
		<?php
	}

	/**
	 * Render the library hours / pickup instructions panel.
	 *
	 * Shows the configured pickup_instructions setting. When empty, shows an
	 * admin-only empty state with a link to Settings.
	 *
	 * @param string $pickup_instructions Current pickup_instructions setting value.
	 */
	private function render_hours_panel( string $pickup_instructions ): void {
		?>
		<h2><?php esc_html_e( 'Library Hours &amp; Pickup Instructions', 'connectlibrary' ); ?></h2>
		<div class="connectlibrary-hours-panel" style="margin-bottom:2em;max-width:700px;">
			<?php if ( '' !== trim( $pickup_instructions ) ) : ?>
				<div class="connectlibrary-pickup-instructions">
					<?php echo nl2br( esc_html( $pickup_instructions ) ); ?>
				</div>
			<?php else : ?>
				<div class="notice notice-warning inline connectlibrary-empty-state">
					<p>
						<?php esc_html_e( 'No pickup instructions have been configured yet.', 'connectlibrary' ); ?>
						&ensp;<a href="<?php echo esc_url( $this->settings_url() ); ?>">
							<?php esc_html_e( 'Add hours and instructions in Settings', 'connectlibrary' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// URL helpers (no PII in params)
	// -------------------------------------------------------------------------

	/** Base dashboard URL. */
	public function page_url(): string {
		return admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=' . self::PAGE_SLUG );
	}

	/** Circulation page URL. */
	private function circulation_url(): string {
		return admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=connectlibrary-circulation' );
	}

	/** Reservations page URL. */
	private function reservations_url(): string {
		return admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=connectlibrary-reservations' );
	}

	/** Borrowers page URL. */
	private function borrowers_url(): string {
		return admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=connectlibrary-borrowers' );
	}

	/** Settings page URL. */
	private function settings_url(): string {
		return admin_url( 'options-general.php?page=connectlibrary-settings' );
	}

	/** Reports page URL. */
	private function reports_url(): string {
		return admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=connectlibrary-reports' );
	}

	/** Audit history page URL. */
	private function audit_history_url(): string {
		return AuditHistoryPage::page_url();
	}
}
