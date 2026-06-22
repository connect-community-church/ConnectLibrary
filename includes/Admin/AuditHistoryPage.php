<?php
/**
 * Librarian Audit & History admin page.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.WP.I18n.MissingTranslatorsComment

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Support\Capabilities;
use ConnectLibrary\Support\ScannerInput;

/**
 * Renders the librarian-only audit log, detail, and scoped history views.
 */
final class AuditHistoryPage {

	public const PAGE_SLUG         = 'connectlibrary-audit-history';
	private const DEFAULT_PER_PAGE = 50;
	private const MAX_PER_PAGE     = 200;

	/**
	 * Audit event service.
	 *
	 * @var AuditEventService
	 */
	private AuditEventService $audit;

	/** Create page with optional dependency override for tests. */
	public function __construct( ?AuditEventService $audit = null ) {
		$this->audit = $audit ?? new AuditEventService();
	}

	/** Register admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/** Add submenu under the Library book post type. */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . BookPostType::POST_TYPE,
			esc_html__( 'Audit & History', 'connectlibrary' ),
			esc_html__( 'Audit & History', 'connectlibrary' ),
			Capabilities::MANAGE_CIRCULATION,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/** Render the audit page. */
	public function render(): void {
		if ( ! self::can_view_audit_history() ) {
			wp_die( esc_html__( 'You do not have permission to view audit history.', 'connectlibrary' ) );
			return;
		}

		$filters  = $this->filters_from_request( $_GET );
		$page     = max( 1, (int) $filters['page'] );
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) $filters['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;
		$event_id = absint( $_GET['cl_event_id'] ?? 0 );
		$events   = array_map(
			array( $this->audit, 'format_safe_event' ),
			$this->audit->query( $this->query_filters( $filters ), $per_page, $offset )
		);
		$detail   = $event_id > 0 ? $this->audit->find( $event_id ) : null;
		$detail   = is_array( $detail ) ? $this->audit->format_safe_event( $detail ) : null;
		$related  = array();
		if ( is_array( $detail ) && '' !== (string) ( $detail['correlation_id'] ?? '' ) ) {
			$related = array_map(
				array( $this->audit, 'format_safe_event' ),
				$this->audit->query( array( 'correlation_id' => (string) $detail['correlation_id'] ), 25, 0 )
			);
		}
		?>
		<div class="wrap connectlibrary-audit-history">
			<h1><?php esc_html_e( 'Audit & History', 'connectlibrary' ); ?></h1>
			<p><?php esc_html_e( 'Librarian-only, append-only operational history with server-side redaction.', 'connectlibrary' ); ?></p>
			<?php $this->render_scoped_heading( $filters ); ?>
			<?php $this->render_filter_form( $filters ); ?>
			<?php $this->render_events_table( $events ); ?>
			<?php $this->render_pagination( $filters, $page, $per_page, count( $events ) ); ?>
			<?php $this->render_detail( $detail, $related ); ?>
		</div>
		<?php
	}

	/** Return true when current user may view audit history. */
	public static function can_view_audit_history(): bool {
		return Capabilities::can_manage_circulation() || Capabilities::can_manage_borrowers();
	}

	/** Build a scoped Audit & History URL. */
	public static function scoped_url( string $entity_type, int $entity_id, array $extra = array() ): string {
		$args = array_merge(
			array(
				'post_type'   => BookPostType::POST_TYPE,
				'page'        => self::PAGE_SLUG,
				'entity_type' => sanitize_key( $entity_type ),
				'entity_id'   => max( 0, $entity_id ),
			),
			$extra
		);

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/** Base page URL. */
	public static function page_url( array $extra = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => BookPostType::POST_TYPE,
					'page'      => self::PAGE_SLUG,
				),
				$extra
			),
			admin_url( 'edit.php' )
		);
	}

	/** Normalize filters from request data. */
	public function filters_from_request( array $source ): array {
		$per_page = absint( $source['per_page'] ?? self::DEFAULT_PER_PAGE );
		if ( $per_page <= 0 ) {
			$per_page = self::DEFAULT_PER_PAGE;
		}

		return array(
			'from'           => ScannerInput::sanitize_text( wp_unslash( $source['from'] ?? '' ) ),
			'to'             => ScannerInput::sanitize_text( wp_unslash( $source['to'] ?? '' ) ),
			'action_group'   => sanitize_key( wp_unslash( $source['action_group'] ?? '' ) ),
			'action'         => sanitize_key( wp_unslash( $source['action'] ?? '' ) ),
			'entity_type'    => sanitize_key( wp_unslash( $source['entity_type'] ?? $source['object_type'] ?? '' ) ),
			'entity_id'      => absint( $source['entity_id'] ?? $source['object_id'] ?? 0 ),
			'actor_id'       => absint( $source['actor_id'] ?? 0 ),
			'actor_type'     => sanitize_key( wp_unslash( $source['actor_type'] ?? '' ) ),
			'status'         => sanitize_key( wp_unslash( $source['status'] ?? $source['outcome'] ?? '' ) ),
			'source_channel' => sanitize_key( wp_unslash( $source['source_channel'] ?? '' ) ),
			'correlation_id' => ScannerInput::sanitize_text( wp_unslash( $source['correlation_id'] ?? '' ) ),
			'search'         => ScannerInput::sanitize_text( wp_unslash( $source['search'] ?? '' ) ),
			'per_page'       => min( self::MAX_PER_PAGE, $per_page ),
			'page'           => max( 1, absint( $source['paged'] ?? $source['page_num'] ?? 1 ) ),
		);
	}

	/** Remove empty UI-only values before querying. */
	private function query_filters( array $filters ): array {
		$query = $filters;
		unset( $query['per_page'], $query['page'] );
		return array_filter(
			$query,
			static fn ( mixed $value ): bool => '' !== (string) $value && 0 !== (int) ( is_numeric( $value ) ? $value : 1 )
		);
	}

	/** Render scoped-history heading when object filters are active. */
	private function render_scoped_heading( array $filters ): void {
		$entity_type = (string) ( $filters['entity_type'] ?? '' );
		$entity_id   = (int) ( $filters['entity_id'] ?? 0 );
		if ( '' === $entity_type || $entity_id <= 0 ) {
			return;
		}
		?>
		<div class="notice notice-info inline connectlibrary-scoped-history">
			<p>
				<?php
				printf(
					/* translators: %s: entity type. */
					esc_html__( 'Scoped history: %s record', 'connectlibrary' ),
					esc_html( $entity_type )
				);
				?>
				&ensp;<a href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Clear scope', 'connectlibrary' ); ?></a>
			</p>
		</div>
		<?php
	}

	/** Render filter form. */
	private function render_filter_form( array $filters ): void {
		?>
		<form method="get" class="connectlibrary-audit-filter-form" style="margin:1em 0;">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( BookPostType::POST_TYPE ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<fieldset>
				<legend><?php esc_html_e( 'Filter audit events', 'connectlibrary' ); ?></legend>
				<?php $this->text_input( 'from', __( 'From', 'connectlibrary' ), $filters, 'date' ); ?>
				<?php $this->text_input( 'to', __( 'To', 'connectlibrary' ), $filters, 'date' ); ?>
				<?php $this->text_input( 'action_group', __( 'Group', 'connectlibrary' ), $filters ); ?>
				<?php $this->text_input( 'action', __( 'Action', 'connectlibrary' ), $filters ); ?>
				<?php $this->text_input( 'entity_type', __( 'Object type', 'connectlibrary' ), $filters ); ?>
				<?php $this->text_input( 'entity_id', __( 'Object reference', 'connectlibrary' ), $filters, 'number' ); ?>
				<?php $this->text_input( 'actor_type', __( 'Actor type', 'connectlibrary' ), $filters ); ?>
				<?php $this->text_input( 'actor_id', __( 'Actor reference', 'connectlibrary' ), $filters, 'number' ); ?>
				<?php $this->text_input( 'status', __( 'Outcome', 'connectlibrary' ), $filters ); ?>
				<?php $this->text_input( 'source_channel', __( 'Source', 'connectlibrary' ), $filters ); ?>
				<?php $this->text_input( 'search', __( 'Search safe labels', 'connectlibrary' ), $filters, 'search' ); ?>
				<?php $this->text_input( 'per_page', __( 'Per page', 'connectlibrary' ), $filters, 'number' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply filters', 'connectlibrary' ); ?></button>
			</fieldset>
		</form>
		<?php
	}

	/** Render a compact input. */
	private function text_input( string $name, string $label, array $filters, string $type = 'text' ): void {
		$value = (string) ( $filters[ $name ] ?? '' );
		?>
		<label for="cl-audit-<?php echo esc_attr( $name ); ?>" style="margin-left:.5em;"><?php echo esc_html( $label ); ?></label>
		<input id="cl-audit-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>" size="12">
		<?php
	}

	/** Render event rows or empty state. */
	private function render_events_table( array $events ): void {
		if ( array() === $events ) {
			?>
			<div class="notice notice-info inline connectlibrary-empty-state">
				<p><?php esc_html_e( 'No audit events match the selected filters.', 'connectlibrary' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<table class="widefat striped connectlibrary-audit-events" style="max-width:1200px;">
			<thead><tr>
				<th><?php esc_html_e( 'Time', 'connectlibrary' ); ?></th>
				<th><?php esc_html_e( 'Action', 'connectlibrary' ); ?></th>
				<th><?php esc_html_e( 'Group', 'connectlibrary' ); ?></th>
				<th><?php esc_html_e( 'Target', 'connectlibrary' ); ?></th>
				<th><?php esc_html_e( 'Actor', 'connectlibrary' ); ?></th>
				<th><?php esc_html_e( 'Outcome', 'connectlibrary' ); ?></th>
				<th><?php esc_html_e( 'Source', 'connectlibrary' ); ?></th>
				<th><?php esc_html_e( 'Links', 'connectlibrary' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $events as $event ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $event['created_at_utc'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $event['action'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $event['action_group'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $this->privacy_safe_label( (string) ( $event['safe_label'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( $this->actor_display_label( $event ) ); ?></td>
						<td><?php echo esc_html( (string) ( $event['status'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $event['source_channel'] ?? '' ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( self::page_url( array( 'cl_event_id' => (int) ( $event['id'] ?? 0 ) ) ) ); ?>"><?php esc_html_e( 'Details', 'connectlibrary' ); ?></a>
							<?php if ( ! empty( $event['entity_type'] ) && ! empty( $event['entity_id'] ) ) : ?>
								&ensp;<a href="<?php echo esc_url( self::scoped_url( (string) $event['entity_type'], (int) $event['entity_id'] ) ); ?>"><?php esc_html_e( 'Scope', 'connectlibrary' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Render simple pagination links. */
	private function render_pagination( array $filters, int $page, int $per_page, int $count ): void {
		$args = array_filter( $filters, static fn ( mixed $value ): bool => '' !== (string) $value );
		unset( $args['page'] );
		?>
		<p class="tablenav-pages">
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( self::page_url( array_merge( $args, array( 'page_num' => $page - 1 ) ) ) ); ?>"><?php esc_html_e( 'Previous', 'connectlibrary' ); ?></a>
			<?php endif; ?>
			<span><?php echo esc_html( sprintf( __( 'Page %1$d, showing up to %2$d events', 'connectlibrary' ), $page, $per_page ) ); ?></span>
			<?php if ( $count >= $per_page ) : ?>
				<a class="button" href="<?php echo esc_url( self::page_url( array_merge( $args, array( 'page_num' => $page + 1 ) ) ) ); ?>"><?php esc_html_e( 'Next', 'connectlibrary' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/** Render event detail and related correction chain. */
	private function render_detail( ?array $detail, array $related ): void {
		if ( null === $detail ) {
			return;
		}
		?>
		<hr>
		<section class="connectlibrary-audit-detail" aria-labelledby="cl-audit-detail-heading">
			<h2 id="cl-audit-detail-heading"><?php esc_html_e( 'Event detail', 'connectlibrary' ); ?></h2>
			<p><strong><?php echo esc_html( $this->privacy_safe_label( (string) ( $detail['safe_label'] ?? '' ) ) ); ?></strong></p>
			<dl>
				<dt><?php esc_html_e( 'Summary', 'connectlibrary' ); ?></dt><dd><?php echo esc_html( (string) ( $detail['summary'] ?? '' ) ); ?></dd>
				<dt><?php esc_html_e( 'Reason', 'connectlibrary' ); ?></dt><dd><?php echo esc_html( (string) ( $detail['reason'] ?? '' ) ); ?></dd>
				<dt><?php esc_html_e( 'Error', 'connectlibrary' ); ?></dt><dd><?php echo esc_html( trim( (string) ( $detail['error_code'] ?? '' ) . ' ' . (string) ( $detail['error_message'] ?? '' ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Privacy state', 'connectlibrary' ); ?></dt><dd><?php echo esc_html( (string) ( $detail['privacy_state'] ?? '' ) ); ?></dd>
			</dl>
			<?php $this->render_json_block( __( 'Context', 'connectlibrary' ), $detail['context'] ?? array() ); ?>
			<?php $this->render_json_block( __( 'Before', 'connectlibrary' ), $detail['before'] ?? array() ); ?>
			<?php $this->render_json_block( __( 'After', 'connectlibrary' ), $detail['after'] ?? array() ); ?>
			<?php if ( array() !== $related ) : ?>
				<h3><?php esc_html_e( 'Related correction / override chain', 'connectlibrary' ); ?></h3>
				<ul>
					<?php foreach ( $related as $event ) : ?>
						<li><?php echo esc_html( (string) ( $event['action'] ?? '' ) . ' — ' . $this->privacy_safe_label( (string) ( $event['summary'] ?? $event['safe_label'] ?? '' ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Render safe JSON for detail payloads. */
	private function render_json_block( string $label, mixed $data ): void {
		if ( ! is_array( $data ) || array() === $data ) {
			return;
		}
		?>
		<h3><?php echo esc_html( $label ); ?></h3>
		<?php $json = wp_json_encode( $this->privacy_safe_payload( $data ), JSON_PRETTY_PRINT ); ?>
		<pre style="white-space:pre-wrap;max-width:900px;background:#fff;border:1px solid #ccd0d4;padding:1em;"><?php echo esc_html( false !== $json ? $json : '' ); ?></pre>
		<?php
	}

	/** Build a privacy-safe display label from legacy safe labels that may contain raw internal IDs. */
	private function privacy_safe_label( string $label ): string {
		$label = preg_replace( '/\b(Borrower|Loan|Copy|Book|Reservation|Event)\s+#\d+\b/i', '$1 record', $label ) ?? $label;
		$label = preg_replace( '/\buser\s+#\d+\b/i', 'staff user', $label ) ?? $label;

		return $label;
	}

	/** Build a privacy-safe actor label. */
	private function actor_display_label( array $event ): string {
		$type = sanitize_key( (string) ( $event['actor_type'] ?? '' ) );
		if ( '' !== $type ) {
			return $type;
		}

		$label = $this->privacy_safe_label( (string) ( $event['actor_label'] ?? '' ) );
		return '' !== $label ? $label : __( 'Staff or automation', 'connectlibrary' );
	}

	/** Recursively scrub display-only JSON payloads before rendering details. */
	private function privacy_safe_payload( array $payload ): array {
		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$payload[ $key ] = $this->privacy_safe_payload( $value );
			} elseif ( is_string( $value ) ) {
				$payload[ $key ] = $this->privacy_safe_label( $value );
			}
		}

		return $payload;
	}
}
