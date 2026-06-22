<?php
/**
 * Library reports admin page.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.WP.I18n.MissingTranslatorsComment

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Circulation\CopyRepository;
use ConnectLibrary\Circulation\LoanRepository;
use ConnectLibrary\Reservations\ReservationService;
use ConnectLibrary\Support\Capabilities;
use ConnectLibrary\Support\ScannerInput;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only tabular reports for librarians: overdue, current, holds, waitlists,
 * activity, and inventory.  CSV export is audit-logged (metadata only).
 */
final class ReportsPage {

	public const PAGE_SLUG      = 'connectlibrary-reports';
	private const EXPORT_ACTION = 'connectlibrary_reports_export';
	private const NONCE_ACTION  = 'connectlibrary_reports_export';
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT     = 200;

	/**
	 * Loan persistence helper.
	 *
	 * @var LoanRepository
	 */
	private LoanRepository $loan_repo;

	/**
	 * Reservation and waitlist service.
	 *
	 * @var ReservationService
	 */
	private ReservationService $reservation_service;

	/**
	 * Copy persistence helper.
	 *
	 * @var CopyRepository
	 */
	private CopyRepository $copy_repo;

	/**
	 * Audit event service.
	 *
	 * @var AuditEventService
	 */
	private AuditEventService $audit;

	/**
	 * Create page with optional dependency overrides for testing.
	 *
	 * @param LoanRepository|null     $loan_repo           Optional override.
	 * @param ReservationService|null $reservation_service Optional override.
	 * @param CopyRepository|null     $copy_repo           Optional override.
	 * @param AuditEventService|null  $audit               Optional override.
	 */
	public function __construct(
		?LoanRepository $loan_repo = null,
		?ReservationService $reservation_service = null,
		?CopyRepository $copy_repo = null,
		?AuditEventService $audit = null
	) {
		$this->loan_repo           = $loan_repo ?? new LoanRepository();
		$this->reservation_service = $reservation_service ?? new ReservationService();
		$this->copy_repo           = $copy_repo ?? new CopyRepository();
		$this->audit               = $audit ?? new AuditEventService();
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/** Register admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
	}

	/** Add the Reports submenu page. */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . BookPostType::POST_TYPE,
			esc_html__( 'Library Reports', 'connectlibrary' ),
			esc_html__( 'Reports', 'connectlibrary' ),
			Capabilities::MANAGE_CIRCULATION,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	// -------------------------------------------------------------------------
	// Report identifiers and labels
	// -------------------------------------------------------------------------

	/**
	 * Return the six canonical report identifiers mapped to display labels.
	 *
	 * @return array<string,string>
	 */
	public static function report_labels(): array {
		return array(
			'overdue'   => __( 'Overdue Loans', 'connectlibrary' ),
			'current'   => __( 'Current Loans', 'connectlibrary' ),
			'holds'     => __( 'Active Holds', 'connectlibrary' ),
			'waitlists' => __( 'Waitlists', 'connectlibrary' ),
			'activity'  => __( 'Activity Log', 'connectlibrary' ),
			'inventory' => __( 'Inventory', 'connectlibrary' ),
		);
	}

	// -------------------------------------------------------------------------
	// Capability helper
	// -------------------------------------------------------------------------

	/**
	 * Return true when the current user may view reports.
	 *
	 * Grants access for MANAGE_CIRCULATION or MANAGE_OPTIONS (admin fallback).
	 */
	public static function can_view_reports(): bool {
		return Capabilities::can_manage_circulation();
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/** Render the reports page (landing or individual report). */
	public function render(): void {
		if ( ! self::can_view_reports() ) {
			wp_die( esc_html__( 'You do not have permission to view reports.', 'connectlibrary' ) );
			return;
		}

		$report = sanitize_key( wp_unslash( $_GET['cl_report'] ?? '' ) );
		$labels = self::report_labels();

		if ( '' === $report || ! array_key_exists( $report, $labels ) ) {
			$this->render_landing();
			return;
		}

		$this->render_report( $report );
	}

	/** Render the six-tile landing page. */
	private function render_landing(): void {
		$labels = self::report_labels();
		?>
		<div class="wrap connectlibrary-reports-landing">
			<h1><?php esc_html_e( 'Library Reports', 'connectlibrary' ); ?></h1>
			<p><?php esc_html_e( 'Select a report to view filtered data and export to CSV.', 'connectlibrary' ); ?></p>
			<div class="connectlibrary-reports-tiles" style="display:flex;flex-wrap:wrap;gap:1.5em;margin-top:1.5em;">
				<?php foreach ( $labels as $id => $label ) : ?>
					<a class="connectlibrary-report-tile"
						href="<?php echo esc_url( $this->report_url( $id ) ); ?>"
						style="display:block;padding:1.5em 2em;background:#fff;border:1px solid #c3c4c7;border-radius:4px;text-decoration:none;min-width:180px;text-align:center;">
						<strong style="font-size:1.1em;"><?php echo esc_html( $label ); ?></strong>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single report page.
	 *
	 * @param string $report Report identifier.
	 */
	private function render_report( string $report ): void {
		$labels = self::report_labels();
		$label  = $labels[ $report ] ?? $report;

		$filters = $this->filters_from_request( $_GET );

		list( $columns, $rows ) = $this->build_report_data( $report, $filters );

		$export_url = $this->export_url( $report, $filters );
		$print_url  = add_query_arg( 'cl_print', '1', $this->report_url( $report, $filters ) );
		$back_url   = admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=' . self::PAGE_SLUG );
		?>
		<div class="wrap connectlibrary-reports-page">
			<style>
				@media print {
					.connectlibrary-reports-actions,
					.connectlibrary-filter-form,
					#wpcontent .notice { display:none !important; }
					.widefat { font-size:11px; }
				}
			</style>

			<h1>
				<?php echo esc_html( $label ); ?>
				&ensp;<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
					<?php esc_html_e( '&larr; All Reports', 'connectlibrary' ); ?>
				</a>
			</h1>

			<form method="get" class="connectlibrary-filter-form" style="margin-bottom:1.5em;">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( BookPostType::POST_TYPE ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="cl_report" value="<?php echo esc_attr( $report ); ?>">
				<fieldset>
					<legend><?php esc_html_e( 'Filter', 'connectlibrary' ); ?></legend>
					<label for="cl-report-from"><?php esc_html_e( 'From date', 'connectlibrary' ); ?></label>
					<input type="date" id="cl-report-from" name="cl_from"
						value="<?php echo esc_attr( (string) ( $filters['from'] ?? '' ) ); ?>">
					<label for="cl-report-to" style="margin-left:1em;"><?php esc_html_e( 'To date', 'connectlibrary' ); ?></label>
					<input type="date" id="cl-report-to" name="cl_to"
						value="<?php echo esc_attr( (string) ( $filters['to'] ?? '' ) ); ?>">
					<label for="cl-report-status" style="margin-left:1em;"><?php esc_html_e( 'Status', 'connectlibrary' ); ?></label>
					<input type="text" id="cl-report-status" name="cl_status" size="10"
						value="<?php echo esc_attr( (string) ( $filters['status'] ?? '' ) ); ?>">
					<label for="cl-report-action" style="margin-left:1em;"><?php esc_html_e( 'Action', 'connectlibrary' ); ?></label>
					<input type="text" id="cl-report-action" name="cl_action_filter" size="12"
						value="<?php echo esc_attr( (string) ( $filters['action'] ?? '' ) ); ?>">
					<?php if ( 'activity' === $report ) : ?>
						<label for="cl-report-outcome" style="margin-left:1em;"><?php esc_html_e( 'Outcome', 'connectlibrary' ); ?></label>
						<input type="text" id="cl-report-outcome" name="cl_outcome" size="10"
							value="<?php echo esc_attr( (string) ( $filters['outcome'] ?? '' ) ); ?>">
						<label for="cl-report-actor-id" style="margin-left:1em;"><?php esc_html_e( 'Actor reference', 'connectlibrary' ); ?></label>
						<input type="number" id="cl-report-actor-id" name="cl_actor_id" min="1" size="8"
							value="<?php echo esc_attr( (string) ( $filters['actor_id'] ?? '' ) ); ?>">
						<label for="cl-report-object-type" style="margin-left:1em;"><?php esc_html_e( 'Object type', 'connectlibrary' ); ?></label>
						<input type="text" id="cl-report-object-type" name="cl_object_type" size="10"
							value="<?php echo esc_attr( (string) ( $filters['object_type'] ?? '' ) ); ?>">
						<label for="cl-report-object-id" style="margin-left:1em;"><?php esc_html_e( 'Object reference', 'connectlibrary' ); ?></label>
						<input type="number" id="cl-report-object-id" name="cl_object_id" min="1" size="8"
							value="<?php echo esc_attr( (string) ( $filters['object_id'] ?? '' ) ); ?>">
					<?php endif; ?>
					<label for="cl-report-search" style="margin-left:1em;"><?php esc_html_e( 'Search', 'connectlibrary' ); ?></label>
					<input type="search" id="cl-report-search" name="cl_search" size="12"
						value="<?php echo esc_attr( (string) ( $filters['search'] ?? '' ) ); ?>">
					<label for="cl-report-condition" style="margin-left:1em;"><?php esc_html_e( 'Condition', 'connectlibrary' ); ?></label>
					<input type="text" id="cl-report-condition" name="cl_condition" size="10"
						value="<?php echo esc_attr( (string) ( $filters['condition'] ?? '' ) ); ?>">
					<label for="cl-report-call-number" style="margin-left:1em;"><?php esc_html_e( 'Call #', 'connectlibrary' ); ?></label>
					<input type="text" id="cl-report-call-number" name="cl_call_number" size="10"
						value="<?php echo esc_attr( (string) ( $filters['call_number'] ?? '' ) ); ?>">
					<label for="cl-report-limit" style="margin-left:1em;"><?php esc_html_e( 'Limit', 'connectlibrary' ); ?></label>
					<input type="number" id="cl-report-limit" name="cl_limit" min="1" max="<?php echo esc_attr( (string) self::MAX_LIMIT ); ?>"
						value="<?php echo esc_attr( (string) ( $filters['limit'] ?? self::DEFAULT_LIMIT ) ); ?>">
					<label for="cl-report-page" style="margin-left:1em;"><?php esc_html_e( 'Page', 'connectlibrary' ); ?></label>
					<input type="number" id="cl-report-page" name="cl_paged" min="1"
						value="<?php echo esc_attr( (string) ( $filters['paged'] ?? 1 ) ); ?>">
					<button type="submit" class="button" style="margin-left:1em;">
						<?php esc_html_e( 'Apply', 'connectlibrary' ); ?>
					</button>
				</fieldset>
			</form>

			<p class="connectlibrary-reports-actions">
				<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Export CSV', 'connectlibrary' ); ?>
				</a>
				&ensp;
				<a href="<?php echo esc_url( $print_url ); ?>" class="button button-secondary"
					onclick="window.print();return false;">
					<?php esc_html_e( 'Print', 'connectlibrary' ); ?>
				</a>
			</p>

			<?php $this->render_table( $columns, $rows, $label ); ?>
		</div>
		<?php
	}

	/**
	 * Render an accessible table or empty state.
	 *
	 * @param string[]                    $columns Column headers.
	 * @param array<int,array<int,mixed>> $rows Data rows.
	 * @param string                      $caption Table caption / report label.
	 */
	private function render_table( array $columns, array $rows, string $caption ): void {
		if ( array() === $rows ) {
			?>
			<div class="notice notice-info inline connectlibrary-empty-state">
				<p><?php esc_html_e( 'No matching records found for the selected filters.', 'connectlibrary' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<table class="widefat striped connectlibrary-report-table" style="max-width:1100px;">
			<caption class="screen-reader-text"><?php echo esc_html( $caption ); ?></caption>
			<thead>
				<tr>
					<?php foreach ( $columns as $col ) : ?>
						<th scope="col"><?php echo esc_html( $col ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<?php foreach ( $row as $cell ) : ?>
							<td><?php $this->render_cell( $cell ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a table cell that may be a safe deep link.
	 *
	 * @param mixed $cell Cell value.
	 */
	private function render_cell( mixed $cell ): void {
		if ( is_array( $cell ) && isset( $cell['label'], $cell['url'] ) ) {
			?>
			<a href="<?php echo esc_url( (string) $cell['url'] ); ?>"><?php echo esc_html( (string) $cell['label'] ); ?></a>
			<?php
			return;
		}

		echo esc_html( (string) $cell );
	}

	// -------------------------------------------------------------------------
	// Data builders
	// -------------------------------------------------------------------------

	/**
	 * Return [columns, rows] for the given report and filters.
	 *
	 * @param string              $report  Report identifier.
	 * @param array<string,mixed> $filters Report filters.
	 * @return array{0: string[], 1: array<int,array<int,mixed>>}
	 */
	public function build_report_data( string $report, array $filters ): array {
		$filters = $this->normalize_filters( $filters );
		$limit   = (int) $filters['limit'];
		$offset  = ( (int) $filters['paged'] - 1 ) * $limit;

		switch ( $report ) {
			case 'overdue':
				return $this->report_overdue( $filters, $limit, $offset );
			case 'current':
				return $this->report_current( $filters, $limit, $offset );
			case 'holds':
				return $this->report_holds( $filters, $limit, $offset );
			case 'waitlists':
				return $this->report_waitlists( $filters, $limit, $offset );
			case 'activity':
				return $this->report_activity( $filters, $limit, $offset );
			case 'inventory':
				return $this->report_inventory( $filters, $limit, $offset );
			default:
				return array( array(), array() );
		}
	}

	/**
	 * Normalize report filter values from a request-like array.
	 *
	 * @param array<string,mixed> $source Request/filter source.
	 * @return array<string,mixed>
	 */
	private function filters_from_request( array $source ): array {
		return $this->normalize_filters(
			array(
				'from'        => ScannerInput::sanitize_text( wp_unslash( $source['cl_from'] ?? '' ) ),
				'to'          => ScannerInput::sanitize_text( wp_unslash( $source['cl_to'] ?? '' ) ),
				'status'      => sanitize_key( wp_unslash( $source['cl_status'] ?? '' ) ),
				'action'      => sanitize_key( wp_unslash( $source['cl_action_filter'] ?? '' ) ),
				'outcome'     => sanitize_key( wp_unslash( $source['cl_outcome'] ?? '' ) ),
				'actor_id'    => absint( $source['cl_actor_id'] ?? 0 ),
				'object_type' => sanitize_key( wp_unslash( $source['cl_object_type'] ?? '' ) ),
				'object_id'   => absint( $source['cl_object_id'] ?? 0 ),
				'condition'   => sanitize_key( wp_unslash( $source['cl_condition'] ?? '' ) ),
				'call_number' => ScannerInput::sanitize_text( wp_unslash( $source['cl_call_number'] ?? '' ) ),
				'search'      => ScannerInput::sanitize_text( wp_unslash( $source['cl_search'] ?? '' ) ),
				'limit'       => absint( $source['cl_limit'] ?? self::DEFAULT_LIMIT ),
				'paged'       => absint( $source['cl_paged'] ?? 1 ),
			)
		);
	}

	/**
	 * Normalize caller-provided filter arrays.
	 *
	 * @param array<string,mixed> $filters Raw filters.
	 * @return array<string,mixed>
	 */
	private function normalize_filters( array $filters ): array {
		$limit = (int) ( $filters['limit'] ?? self::DEFAULT_LIMIT );
		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LIMIT;
		}
		$limit = min( self::MAX_LIMIT, $limit );

		$paged = max( 1, (int) ( $filters['paged'] ?? 1 ) );

		return array(
			'from'        => (string) ( $filters['from'] ?? '' ),
			'to'          => (string) ( $filters['to'] ?? '' ),
			'status'      => sanitize_key( (string) ( $filters['status'] ?? '' ) ),
			'action'      => sanitize_key( (string) ( $filters['action'] ?? '' ) ),
			'outcome'     => sanitize_key( (string) ( $filters['outcome'] ?? '' ) ),
			'actor_id'    => (int) ( $filters['actor_id'] ?? 0 ),
			'object_type' => sanitize_key( (string) ( $filters['object_type'] ?? '' ) ),
			'object_id'   => (int) ( $filters['object_id'] ?? 0 ),
			'condition'   => sanitize_key( (string) ( $filters['condition'] ?? '' ) ),
			'call_number' => ScannerInput::sanitize_text( $filters['call_number'] ?? '' ),
			'search'      => ScannerInput::sanitize_text( $filters['search'] ?? '' ),
			'limit'       => $limit,
			'paged'       => $paged,
		);
	}

	/**
	 * Build the overdue-loans report.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $limit   Maximum rows.
	 * @param int                 $offset  Pagination offset.
	 * @return array{0: string[], 1: array<int,array<int,mixed>>}
	 */
	private function report_overdue( array $filters, int $limit, int $offset ): array {
		$columns                 = array( __( 'Loan', 'connectlibrary' ), __( 'Borrower', 'connectlibrary' ), __( 'Book', 'connectlibrary' ), __( 'Copy', 'connectlibrary' ), __( 'Due Date', 'connectlibrary' ), __( 'Actions', 'connectlibrary' ) );
		$filters['status']       = '' !== (string) $filters['status'] ? $filters['status'] : 'active';
		$filters['overdue_only'] = true;
		$rows                    = array();
		foreach ( $this->loan_repo->report_active_loans( $filters, $limit, $offset ) as $loan ) {
			$rows[] = array(
				$this->record_summary( 'loan' ),
				$this->borrower_summary( (int) ( $loan['borrower_id'] ?? 0 ) ),
				$this->book_summary( (int) ( $loan['book_post_id'] ?? 0 ) ),
				$this->record_summary( 'copy' ),
				(string) ( $loan['due_at'] ?? '' ),
				$this->action_cell( __( 'Open circulation', 'connectlibrary' ), $this->circulation_url( array( 'cl_loan_id' => (string) ( $loan['id'] ?? '' ) ) ) ),
			);
		}

		return array( $columns, $rows );
	}

	/** Build the current-loans report. */
	private function report_current( array $filters, int $limit, int $offset ): array {
		$columns                 = array( __( 'Loan', 'connectlibrary' ), __( 'Borrower', 'connectlibrary' ), __( 'Book', 'connectlibrary' ), __( 'Copy', 'connectlibrary' ), __( 'Due Date', 'connectlibrary' ), __( 'Status', 'connectlibrary' ), __( 'Actions', 'connectlibrary' ) );
		$filters['status']       = '' !== (string) $filters['status'] ? $filters['status'] : 'active';
		$filters['overdue_only'] = false;
		$rows                    = array();
		foreach ( $this->loan_repo->report_active_loans( $filters, $limit, $offset ) as $loan ) {
			$rows[] = array(
				$this->record_summary( 'loan' ),
				$this->borrower_summary( (int) ( $loan['borrower_id'] ?? 0 ) ),
				$this->book_summary( (int) ( $loan['book_post_id'] ?? 0 ) ),
				$this->record_summary( 'copy' ),
				(string) ( $loan['due_at'] ?? '' ),
				(string) ( $loan['status'] ?? '' ),
				$this->action_cell( __( 'Open circulation', 'connectlibrary' ), $this->circulation_url( array( 'cl_loan_id' => (string) ( $loan['id'] ?? '' ) ) ) ),
			);
		}

		return array( $columns, $rows );
	}

	/** Build the active-holds report. */
	private function report_holds( array $filters, int $limit, int $offset ): array {
		$columns           = array( __( 'Reservation', 'connectlibrary' ), __( 'Patron', 'connectlibrary' ), __( 'Book', 'connectlibrary' ), __( 'Expires At', 'connectlibrary' ), __( 'Status', 'connectlibrary' ), __( 'Actions', 'connectlibrary' ) );
		$filters['status'] = '' !== (string) $filters['status'] ? $filters['status'] : 'active_hold';
		$rows              = array();
		foreach ( $this->reservation_service->report_pickup_holds( $filters, $limit, $offset ) as $hold ) {
			$borrower_id = (int) ( $hold['borrower_id'] ?? 0 );
			$patron      = $this->borrower_summary( $borrower_id );
			$rows[]      = array( $this->record_summary( 'reservation' ), $patron, $this->book_summary( (int) ( $hold['book_post_id'] ?? 0 ) ), (string) ( $hold['hold_expires_at'] ?? '' ), (string) ( $hold['status'] ?? '' ), $this->action_cell( __( 'Open reservations', 'connectlibrary' ), $this->reservations_url( array( 'cl_reservation_id' => (string) ( $hold['id'] ?? '' ) ) ) ) );
		}

		return array( $columns, $rows );
	}

	/** Build the waitlists report. */
	private function report_waitlists( array $filters, int $limit, int $offset ): array {
		$columns           = array( __( 'Reservation', 'connectlibrary' ), __( 'Patron', 'connectlibrary' ), __( 'Book', 'connectlibrary' ), __( 'Requested At', 'connectlibrary' ), __( 'Status', 'connectlibrary' ), __( 'Actions', 'connectlibrary' ) );
		$filters['status'] = '' !== (string) $filters['status'] ? $filters['status'] : 'waitlisted';
		$rows              = array();
		foreach ( $this->reservation_service->report_waitlist_entries( $filters, $limit, $offset ) as $entry ) {
			$borrower_id = (int) ( $entry['borrower_id'] ?? 0 );
			$patron      = $this->borrower_summary( $borrower_id );
			$rows[]      = array( $this->record_summary( 'reservation' ), $patron, $this->book_summary( (int) ( $entry['book_post_id'] ?? 0 ) ), (string) ( $entry['requested_at'] ?? $entry['created_at'] ?? '' ), (string) ( $entry['status'] ?? '' ), $this->action_cell( __( 'Open reservations', 'connectlibrary' ), $this->reservations_url( array( 'cl_reservation_id' => (string) ( $entry['id'] ?? '' ) ) ) ) );
		}

		return array( $columns, $rows );
	}

	/** Build the circulation activity report. */
	private function report_activity( array $filters, int $limit, int $offset ): array {
		$columns       = array( __( 'Event', 'connectlibrary' ), __( 'Date (UTC)', 'connectlibrary' ), __( 'Action', 'connectlibrary' ), __( 'Actor', 'connectlibrary' ), __( 'Entity', 'connectlibrary' ), __( 'Entity Summary', 'connectlibrary' ), __( 'Status', 'connectlibrary' ), __( 'Actions', 'connectlibrary' ) );
		$audit_filters = array();
		foreach ( array(
			'action',
			'status'      => 'outcome',
			'actor_id',
			'entity_type' => 'object_type',
			'entity_id'   => 'object_id',
			'from',
			'to',
		) as $audit_key => $filter_key ) {
			if ( is_int( $audit_key ) ) {
				$audit_key  = $filter_key;
				$filter_key = $filter_key;
			}
			if ( ! empty( $filters[ $filter_key ] ) ) {
				$audit_filters[ $audit_key ] = $filters[ $filter_key ];
			}
		}
		$events = $this->audit->query( $audit_filters, $limit, $offset );
		$rows   = array();
		foreach ( $events as $event ) {
			$created = (string) ( $event['created_at_utc'] ?? '' );
			$rows[]  = array(
				$this->record_summary( 'event' ),
				$created,
				(string) ( $event['action'] ?? '' ),
				$this->actor_summary( $event ),
				$this->entity_type_summary( (string) ( $event['entity_type'] ?? '' ) ),
				$this->record_summary( (string) ( $event['entity_type'] ?? '' ) ),
				(string) ( $event['status'] ?? '' ),
				$this->action_cell(
					__( 'Open audit history', 'connectlibrary' ),
					$this->reports_url(
						array(
							'cl_report'      => 'activity',
							'cl_object_type' => (string) ( $event['entity_type'] ?? '' ),
							'cl_object_id'   => (string) ( $event['entity_id'] ?? '' ),
						)
					)
				),
			);
		}

		return array( $columns, $rows );
	}

	/** Build the inventory/status report. */
	private function report_inventory( array $filters, int $limit, int $offset ): array {
		$columns = array( __( 'Copy', 'connectlibrary' ), __( 'Book', 'connectlibrary' ), __( 'Call Number', 'connectlibrary' ), __( 'Condition', 'connectlibrary' ), __( 'Status', 'connectlibrary' ), __( 'Added', 'connectlibrary' ), __( 'Actions', 'connectlibrary' ) );
		$rows    = array();
		foreach ( $this->copy_repo->report_inventory( $filters, $limit, $offset ) as $copy ) {
			$rows[] = array( $this->record_summary( 'copy' ), $this->book_summary( (int) ( $copy['book_post_id'] ?? 0 ) ), (string) ( $copy['call_number'] ?? '' ), (string) ( $copy['condition'] ?? '' ), (string) ( $copy['status'] ?? $copy['circulation_status'] ?? '' ), (string) ( $copy['created_at'] ?? '' ), $this->action_cell( __( 'Edit book', 'connectlibrary' ), $this->book_url( (int) ( $copy['book_post_id'] ?? 0 ) ) ) );
		}

		return array( $columns, $rows );
	}

	/** Build a privacy-safe borrower display summary. */
	private function borrower_summary( int $borrower_id ): string {
		return $borrower_id > 0 ? __( 'Registered borrower', 'connectlibrary' ) : __( 'Guest', 'connectlibrary' );
	}

	/** Build a privacy-safe book display summary. */
	private function book_summary( int $book_post_id ): string {
		$title = $book_post_id > 0 && function_exists( 'get_the_title' ) ? get_the_title( $book_post_id ) : '';

		return '' !== $title ? (string) $title : __( 'Library item', 'connectlibrary' );
	}

	/** Build a privacy-safe generic record summary without raw internal IDs. */
	private function record_summary( string $entity_type ): string {
		$entity_type = sanitize_key( $entity_type );

		return match ( $entity_type ) {
			'loan'     => __( 'Loan record', 'connectlibrary' ),
			'copy'     => __( 'Copy record', 'connectlibrary' ),
			'event'    => __( 'Audit event', 'connectlibrary' ),
			'borrower' => __( 'Borrower record', 'connectlibrary' ),
			'book'        => __( 'Book record', 'connectlibrary' ),
			'reservation' => __( 'Reservation record', 'connectlibrary' ),
			default       => __( 'Entity record', 'connectlibrary' ),
		};
	}

	/** Build a privacy-safe actor display summary. */
	private function actor_summary( array $event ): string {
		$label = trim( (string) ( $event['actor_label'] ?? '' ) );
		if ( '' !== $label ) {
			return $label;
		}

		$type = sanitize_key( (string) ( $event['actor_type'] ?? '' ) );
		return '' !== $type ? $type : __( 'Staff or automation', 'connectlibrary' );
	}

	/** Build a privacy-safe entity type display summary. */
	private function entity_type_summary( string $entity_type ): string {
		$entity_type = sanitize_key( $entity_type );

		return '' !== $entity_type ? $entity_type : __( 'Entity', 'connectlibrary' );
	}

	/** Build a link cell. */
	private function action_cell( string $label, string $url ): array {
		return array(
			'label' => $label,
			'url'   => $url,
		);
	}

	// -------------------------------------------------------------------------
	// CSV export
	// -------------------------------------------------------------------------

	/**
	 * Handle admin_post CSV export request.
	 *
	 * Capability-checks, nonce-validates, streams CSV, audit-logs metadata only.
	 */
	public function handle_export(): void {
		if ( ! self::can_view_reports() ) {
			wp_die( esc_html__( 'You do not have permission to export reports.', 'connectlibrary' ) );
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		$report = sanitize_key( wp_unslash( $_POST['cl_report'] ?? $_GET['cl_report'] ?? '' ) );
		$labels = self::report_labels();

		if ( '' === $report || ! array_key_exists( $report, $labels ) ) {
			wp_die( esc_html__( 'Invalid report identifier.', 'connectlibrary' ) );
			return;
		}

		$filters = $this->filters_from_request( array_merge( $_GET, $_POST ) );

		list( $columns, $rows ) = $this->build_report_data( $report, $filters );

		$row_count = count( $rows );

		// Audit-log metadata only — no row contents.
		$this->audit->log(
			'report_export',
			array(
				'source_channel' => 'admin',
				'entity_type'    => 'report',
				'context'        => array(
					'report'    => $report,
					'filters'   => $filters,
					'row_count' => $row_count,
					'format'    => 'csv',
				),
				'summary'        => sprintf(
					/* translators: 1: report name, 2: row count */
					__( 'Exported %1$s report (%2$d rows) as CSV', 'connectlibrary' ),
					$report,
					$row_count
				),
			)
		);

		$filename = 'connectlibrary-' . $report . '-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		if ( false !== $out ) {
			fputcsv( $out, array_map( array( $this, 'escape_csv_cell' ), $columns ) );
			foreach ( $rows as $row ) {
				fputcsv( $out, array_map( array( $this, 'escape_csv_cell' ), $this->row_to_csv_values( $row ) ) );
			}
			fclose( $out );
		}

		exit;
	}

	/**
	 * Convert a display row to scalar CSV cells.
	 *
	 * @param array<int,mixed> $row Display row.
	 * @return string[]
	 */
	private function row_to_csv_values( array $row ): array {
		return array_map(
			static function ( mixed $cell ): string {
				if ( is_array( $cell ) && isset( $cell['label'] ) ) {
					return (string) $cell['label'];
				}

				return (string) $cell;
			},
			$row
		);
	}

	/**
	 * Escape a CSV cell value to prevent formula injection.
	 *
	 * Cells that begin with = + - @ or whitespace (tab, CR) are prefixed with
	 * a single-quote so spreadsheet applications treat them as literals.
	 *
	 * @param string $value Raw cell value.
	 * @return string Safe cell value.
	 */
	public static function escape_csv_cell( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}
		$first = $value[0];
		if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first
			|| "\t" === $first || "\r" === $first ) {
			return "'" . $value;
		}
		return $value;
	}

	// -------------------------------------------------------------------------
	// URL helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a URL to a specific report page.
	 *
	 * @param string              $report  Report identifier.
	 * @param array<string,mixed> $filters Optional active filters.
	 * @return string
	 */
	public function report_url( string $report, array $filters = array() ): string {
		$args = array_merge( array( 'cl_report' => $report ), $this->filters_to_query_args( $filters ) );
		return $this->reports_url( $args );
	}

	/** Build base reports admin URL with optional query args. */
	private function reports_url( array $args = array() ): string {
		$base = admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=' . self::PAGE_SLUG );
		return empty( $args ) ? $base : add_query_arg( $args, $base );
	}

	/** Build circulation admin URL. */
	private function circulation_url( array $args = array() ): string {
		$base = admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=connectlibrary-circulation' );
		return empty( $args ) ? $base : add_query_arg( $args, $base );
	}

	/** Build reservations admin URL. */
	private function reservations_url( array $args = array() ): string {
		$base = admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=connectlibrary-reservations' );
		return empty( $args ) ? $base : add_query_arg( $args, $base );
	}

	/** Build edit-book URL. */
	private function book_url( int $book_post_id ): string {
		if ( $book_post_id <= 0 ) {
			return admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE );
		}

		return admin_url( 'post.php?post=' . $book_post_id . '&action=edit' );
	}

	/**
	 * Build a CSV export URL including nonce and current filters.
	 *
	 * @param string              $report  Report identifier.
	 * @param array<string,mixed> $filters Active filters.
	 * @return string
	 */
	private function export_url( string $report, array $filters ): string {
		$args             = array_merge(
			array(
				'action'    => self::EXPORT_ACTION,
				'cl_report' => $report,
			),
			$this->filters_to_query_args( $filters )
		);
		$args['_wpnonce'] = wp_create_nonce( self::NONCE_ACTION );
		return admin_url( 'admin-post.php?' . http_build_query( $args ) );
	}

	/** Convert normalized filters to query args. */
	private function filters_to_query_args( array $filters ): array {
		$filters = $this->normalize_filters( $filters );
		$map     = array(
			'from'        => 'cl_from',
			'to'          => 'cl_to',
			'status'      => 'cl_status',
			'action'      => 'cl_action_filter',
			'outcome'     => 'cl_outcome',
			'actor_id'    => 'cl_actor_id',
			'object_type' => 'cl_object_type',
			'object_id'   => 'cl_object_id',
			'condition'   => 'cl_condition',
			'call_number' => 'cl_call_number',
			'search'      => 'cl_search',
			'limit'       => 'cl_limit',
			'paged'       => 'cl_paged',
		);
		$args    = array();
		foreach ( $map as $filter_key => $query_key ) {
			$value = $filters[ $filter_key ] ?? '';
			if ( '' !== (string) $value && 0 !== $value ) {
				$args[ $query_key ] = $value;
			}
		}

		return $args;
	}
}
