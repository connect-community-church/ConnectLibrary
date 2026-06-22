<?php
/**
 * Librarian borrower admin screen.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag

use ConnectLibrary\Borrowers\BorrowerService;
use ConnectLibrary\Borrowers\BorrowerCardService;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Support\Capabilities;
use ConnectLibrary\Support\ScannerInput;
use WP_Error;

/**
 * Registers and renders the Phase 2 borrower management screen.
 */
final class BorrowersPage {
	private const PAGE_SLUG         = 'connectlibrary-borrowers';
	private const ACTION_NAME       = 'connectlibrary_save_borrower';
	private const CARD_ACTION_NAME  = 'connectlibrary_borrower_card_action';
	private const PRINT_ACTION_NAME = 'connectlibrary_print_borrower_cards';
	private const NONCE_ACTION      = 'connectlibrary_save_borrower';
	private const CARD_NONCE_ACTION = 'connectlibrary_borrower_card_action';

	/**
	 * Borrower service dependency.
	 *
	 * @var BorrowerService
	 */
	private BorrowerService $service;

	/**
	 * Borrower-card lifecycle service.
	 *
	 * @var BorrowerCardService
	 */
	private BorrowerCardService $card_service;

	/**
	 * Create page dependencies.
	 *
	 * @param BorrowerService|null $service Optional service override.
	 */
	public function __construct( ?BorrowerService $service = null, ?BorrowerCardService $card_service = null ) {
		$this->service      = $service ?? new BorrowerService();
		$this->card_service = $card_service ?? new BorrowerCardService();
	}

	/** Register admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_' . self::ACTION_NAME, array( $this, 'handle_post' ) );
		add_action( 'admin_post_' . self::CARD_ACTION_NAME, array( $this, 'handle_card_action' ) );
		add_action( 'admin_post_' . self::PRINT_ACTION_NAME, array( $this, 'handle_print_action' ) );
	}

	/** Add the borrowers screen under the Library admin menu. */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . BookPostType::POST_TYPE,
			esc_html__( 'ConnectLibrary Borrowers', 'connectlibrary' ),
			esc_html__( 'Borrowers', 'connectlibrary' ),
			Capabilities::MANAGE_BORROWERS,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/** Render the borrower list and create/edit form. */
	public function render(): void {
		if ( ! Capabilities::can_manage_borrowers() ) {
			wp_die( esc_html__( 'You do not have permission to manage borrowers.', 'connectlibrary' ) );
		}

		$filter_args = $this->get_filter_args();
		$borrowers   = $this->service->search( $filter_args );
		$borrowers   = is_wp_error( $borrowers ) ? array() : $borrowers;
		// Fetch unfiltered list for guardian dropdown and linked-children lookup.
		if ( array() !== $filter_args ) {
			$all_borrowers = $this->service->search();
			$all_borrowers = is_wp_error( $all_borrowers ) ? array() : $all_borrowers;
		} else {
			$all_borrowers = $borrowers;
		}
		$editing = $this->editing_borrower();
		?>
		<div class="wrap connectlibrary-borrowers-admin">
			<h1><?php echo esc_html__( 'Borrowers', 'connectlibrary' ); ?></h1>
			<?php $this->render_notice(); ?>
			<p><?php echo esc_html__( 'Create and maintain librarian-only borrower records. Guardian/contact details and private notes stay on this protected admin screen.', 'connectlibrary' ); ?></p>
			<?php $this->render_filter_form( $filter_args ); ?>
			<?php $this->render_table( $borrowers ); ?>
			<?php $this->render_form( is_array( $editing ) ? $editing : array(), $all_borrowers ); ?>
			<?php $this->render_sheet_print_button(); ?>
		</div>
		<?php
	}

	/** Handle nonce-protected borrower create/update submissions. */
	public function handle_post(): void {
		if ( ! Capabilities::can_manage_borrowers() ) {
			wp_die( esc_html__( 'You do not have permission to manage borrowers.', 'connectlibrary' ) );
		}
		if ( false === check_admin_referer( self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Borrower form security check failed.', 'connectlibrary' ) );
		}

		$id     = absint( wp_unslash( $_POST['borrower_id'] ?? 0 ) );
		$data   = $this->posted_data();
		$result = $id > 0 ? $this->service->update( $id, $data ) : $this->service->create( $data );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'borrower_error' => $result->get_error_code() ), $this->page_url() ) );
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'borrower_saved' => $id > 0 ? 'updated' : 'created',
					'borrower_id'    => (int) ( $result['id'] ?? $id ),
				),
				$this->page_url()
			)
		);
	}

	/** Handle staff-only library-card lifecycle actions. */
	public function handle_card_action(): void {
		if ( ! Capabilities::can_manage_borrowers() ) {
			wp_die( esc_html__( 'You do not have permission to manage borrower cards.', 'connectlibrary' ) );
		}
		if ( false === check_admin_referer( self::CARD_NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Borrower card security check failed.', 'connectlibrary' ) );
		}

		$borrower_id = absint( wp_unslash( $_POST['borrower_id'] ?? 0 ) );
		$card_action = sanitize_key( wp_unslash( $_POST['card_action'] ?? '' ) );
		if ( 'replace' === $card_action && '1' !== (string) wp_unslash( $_POST['lost_card_confirm'] ?? '' ) ) {
			$result = new WP_Error( 'connectlibrary_card_replace_unconfirmed', __( 'Confirm the old card will stop immediately before replacing a lost card.', 'connectlibrary' ), array( 'status' => 400 ) );
		} else {
			$replacement_reason = ScannerInput::sanitize_text( wp_unslash( $_POST['replacement_reason'] ?? __( 'Lost card', 'connectlibrary' ) ) );
			$replacement_note   = ScannerInput::sanitize_textarea( wp_unslash( $_POST['replacement_note'] ?? '' ) );
			$result             = match ( $card_action ) {
				'generate' => $this->card_service->generate_first_card( $borrower_id ),
				'reprint'  => $this->card_service->reprint_active_card( $borrower_id ),
				'replace'  => $this->card_service->replace_lost_card( $borrower_id, $replacement_reason, $replacement_note ),
				'disable'  => $this->card_service->disable_active_card( $borrower_id ),
				default    => new WP_Error( 'connectlibrary_card_unknown_action', __( 'Unknown card action.', 'connectlibrary' ) ),
			};
		}

		$args = array( 'borrower_id' => $borrower_id );
		if ( is_wp_error( $result ) ) {
			$args['borrower_error'] = $result->get_error_code();
			wp_safe_redirect( add_query_arg( $args, $this->page_url() ) );
			return;
		}

		if ( in_array( $card_action, array( 'generate', 'reprint', 'replace' ), true ) ) {
			$card = $this->printable_card_from_action_result( $result );
			$this->render_print_page( $card );
			return;
		}

		$args['borrower_saved'] = 'card_' . $card_action;
		wp_safe_redirect( add_query_arg( $args, $this->page_url() ) );
	}

	/** Handle active-card sheet printing. */
	public function handle_print_action(): void {
		if ( ! Capabilities::can_manage_borrowers() ) {
			wp_die( esc_html__( 'You do not have permission to print borrower cards.', 'connectlibrary' ) );
		}
		if ( false === check_admin_referer( self::CARD_NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Borrower card security check failed.', 'connectlibrary' ) );
		}

		$this->render_print_page( null );
	}

	/**
	 * Render GET search/filter controls.
	 *
	 * @param array<string,string> $current Current active filter values.
	 */
	private function render_filter_form( array $current ): void {
		$search = (string) ( $current['search'] ?? '' );
		$status = (string) ( $current['status'] ?? '' );
		$type   = (string) ( $current['borrower_type'] ?? '' );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( \ConnectLibrary\Catalog\BookPostType::POST_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<div class="alignleft actions">
				<label class="screen-reader-text" for="connectlibrary-filter-search"><?php echo esc_html__( 'Search borrowers', 'connectlibrary' ); ?></label>
				<input id="connectlibrary-filter-search" type="search" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Name, email, phone, guardian…', 'connectlibrary' ); ?>" />
				<select name="status">
					<option value=""><?php echo esc_html__( 'All statuses', 'connectlibrary' ); ?></option>
					<?php foreach ( $this->status_choices() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="borrower_type">
					<option value=""><?php echo esc_html__( 'All types', 'connectlibrary' ); ?></option>
					<?php foreach ( $this->type_choices() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php echo esc_html__( 'Filter', 'connectlibrary' ); ?></button>
				<?php if ( array() !== $current ) : ?>
					<a href="<?php echo esc_url( $this->page_url() ); ?>" class="button"><?php echo esc_html__( 'Clear filters', 'connectlibrary' ); ?></a>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the linked-children list for an adult borrower record.
	 *
	 * Shows child borrowers whose guardian_borrower_id equals the given adult ID.
	 * Private notes are intentionally excluded from this summary view.
	 *
	 * @param int                            $guardian_id   Adult borrower ID.
	 * @param array<int,array<string,mixed>> $all_borrowers All borrower rows.
	 */
	private function render_linked_children( int $guardian_id, array $all_borrowers ): void {
		$children = array_values(
			array_filter(
				$all_borrowers,
				static fn ( array $borrower ): bool => (int) ( $borrower['guardian_borrower_id'] ?? 0 ) === $guardian_id
			)
		);
		?>
		<h3><?php echo esc_html__( 'Linked children', 'connectlibrary' ); ?></h3>
		<?php if ( array() === $children ) : ?>
			<p><?php echo esc_html__( 'No child borrower records are linked to this adult.', 'connectlibrary' ); ?></p>
		<?php else : ?>
			<table class="widefat striped connectlibrary-linked-children">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Name', 'connectlibrary' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Type', 'connectlibrary' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Contact', 'connectlibrary' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Status', 'connectlibrary' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $children as $child ) : ?>
						<tr>
							<th scope="row"><a href="<?php echo esc_url( add_query_arg( array( 'borrower_id' => (int) $child['id'] ), $this->page_url() ) ); ?>"><?php echo esc_html( (string) ( $child['display_name'] ?? '' ) ); ?></a></th>
							<td><?php echo esc_html( $this->type_label( (string) ( $child['borrower_type'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( $this->contact_summary( $child ) ); ?></td>
							<td><?php echo esc_html( $this->status_label( (string) ( $child['status'] ?? '' ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Build BorrowerService search args from GET filter parameters.
	 *
	 * @return array<string,string>
	 */
	private function get_filter_args(): array {
		$args   = array();
		$search = ScannerInput::sanitize_text( wp_unslash( $_GET['search'] ?? '' ) );
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$type   = sanitize_key( wp_unslash( $_GET['borrower_type'] ?? '' ) );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}
		if ( '' !== $status ) {
			$args['status'] = $status;
		}
		if ( '' !== $type ) {
			$args['borrower_type'] = $type;
		}

		return $args;
	}

	/**
	 * Render the borrower list.
	 *
	 * @param array<int,array<string,mixed>> $borrowers Borrower rows.
	 */
	private function render_table( array $borrowers ): void {
		$borrower_map = $this->borrower_map( $borrowers );
		?>
		<h2><?php echo esc_html__( 'Borrower records', 'connectlibrary' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Name', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Type', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Contact', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'WordPress link', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Guardian', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Status', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Updated', 'connectlibrary' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $borrowers ) : ?>
					<tr><td colspan="7"><?php echo esc_html__( 'No borrowers yet.', 'connectlibrary' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $borrowers as $borrower ) : ?>
					<tr>
						<th scope="row"><a href="<?php echo esc_url( add_query_arg( array( 'borrower_id' => (int) $borrower['id'] ), $this->page_url() ) ); ?>"><?php echo esc_html( (string) ( $borrower['display_name'] ?? '' ) ); ?></a></th>
						<td><?php echo esc_html( $this->type_label( (string) ( $borrower['borrower_type'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( $this->contact_summary( $borrower ) ); ?></td>
						<td><?php echo esc_html( $this->wp_link_summary( $borrower ) ); ?></td>
						<td><?php echo esc_html( $this->guardian_summary( $borrower, $borrower_map ) ); ?></td>
						<td><?php echo esc_html( $this->status_label( (string) ( $borrower['status'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $borrower['updated_at'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render create/edit form.
	 *
	 * @param array<string,mixed>            $borrower     Editing borrower, or empty for create.
	 * @param array<int,array<string,mixed>> $all_borrowers All borrower rows for guardian choices and children lookup.
	 */
	private function render_form( array $borrower, array $all_borrowers ): void {
		$id   = (int) ( $borrower['id'] ?? 0 );
		$type = (string) ( $borrower['borrower_type'] ?? '' );
		?>
		<h2><?php echo esc_html( $id > 0 ? __( 'Edit borrower', 'connectlibrary' ) : __( 'Create borrower', 'connectlibrary' ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION, '_wpnonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
			<input type="hidden" name="borrower_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<table class="form-table" role="presentation">
				<tbody>
					<?php $this->render_core_fields( $borrower ); ?>
					<?php $this->render_guardian_fields( $borrower, $all_borrowers ); ?>
					<tr>
						<th scope="row"><label for="connectlibrary-private-notes"><?php echo esc_html__( 'Private notes', 'connectlibrary' ); ?></label></th>
						<td><textarea id="connectlibrary-private-notes" name="private_notes" rows="4" class="large-text"><?php echo esc_textarea( (string) ( $borrower['private_notes'] ?? '' ) ); ?></textarea><p class="description"><?php echo esc_html__( 'Private librarian notes. These are not shown in public catalog, member self-service, or list summaries.', 'connectlibrary' ); ?></p></td>
					</tr>
				</tbody>
			</table>
			<p class="submit"><button type="submit" class="button button-primary"><?php echo esc_html( $id > 0 ? __( 'Update borrower', 'connectlibrary' ) : __( 'Create borrower', 'connectlibrary' ) ); ?></button></p>
		</form>
		<?php if ( $id > 0 ) : ?>
			<?php $this->render_card_panel( $id ); ?>
		<?php endif; ?>
		<?php if ( $id > 0 && 'child' !== $type ) : ?>
			<?php $this->render_linked_children( $id, $all_borrowers ); ?>
		<?php endif; ?>
		<?php
	}

	/** Render library-card lifecycle controls for one borrower. */
	private function render_card_panel( int $borrower_id ): void {
		$cards  = $this->card_service->cards_for_borrower( $borrower_id );
		$active = null;
		foreach ( $cards as $card ) {
			if ( BorrowerCardService::STATUS_ACTIVE === (string) ( $card['status'] ?? '' ) ) {
				$active = $card;
				break;
			}
		}
		?>
		<h3><?php echo esc_html__( 'Library card', 'connectlibrary' ); ?></h3>
		<p><?php echo esc_html__( 'Cards print opaque QR/barcode tokens only. Contact details, guardian details, notes, and loan history are never included.', 'connectlibrary' ); ?></p>
		<?php if ( null !== $active ) : ?>
			<p><strong><?php echo esc_html__( 'Active card:', 'connectlibrary' ); ?></strong> <?php echo esc_html( (string) ( $active['card_label'] ?? '' ) ); ?></p>
		<?php else : ?>
			<p><strong><?php echo esc_html__( 'No active card', 'connectlibrary' ); ?></strong></p>
		<?php endif; ?>
		<div class="connectlibrary-card-actions">
			<?php $this->render_card_action_form( $borrower_id, null === $active ? 'generate' : 'reprint', null === $active ? __( 'Generate first card', 'connectlibrary' ) : __( 'Print active card', 'connectlibrary' ) ); ?>
			<?php if ( null !== $active ) : ?>
				<?php $this->render_lost_card_replacement_form( $borrower_id ); ?>
				<?php $this->render_card_action_form( $borrower_id, 'disable', __( 'Disable card', 'connectlibrary' ) ); ?>
			<?php endif; ?>
		</div>
		<?php if ( array() !== $cards ) : ?>
			<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'Card', 'connectlibrary' ); ?></th><th><?php echo esc_html__( 'Status', 'connectlibrary' ); ?></th><th><?php echo esc_html__( 'Created', 'connectlibrary' ); ?></th></tr></thead><tbody>
			<?php foreach ( $cards as $card ) : ?>
				<tr><td><?php echo esc_html( (string) ( $card['card_label'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $card['status'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $card['created_at'] ?? '' ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<?php
	}

	/** Render explicit lost-card replacement confirmation form. */
	private function render_lost_card_replacement_form( int $borrower_id ): void {
		$confirm_id = 'connectlibrary-lost-card-confirm-' . $borrower_id;
		$reason_id  = 'connectlibrary-lost-card-reason-' . $borrower_id;
		$note_id    = 'connectlibrary-lost-card-note-' . $borrower_id;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="connectlibrary-lost-card-replacement" style="display:inline-block;margin-right:0.5em;vertical-align:top;" aria-describedby="connectlibrary-lost-card-warning-<?php echo esc_attr( (string) $borrower_id ); ?>">
			<?php wp_nonce_field( self::CARD_NONCE_ACTION, '_wpnonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CARD_ACTION_NAME ); ?>" />
			<input type="hidden" name="borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
			<input type="hidden" name="card_action" value="replace" />
			<div role="group" aria-labelledby="connectlibrary-lost-card-heading-<?php echo esc_attr( (string) $borrower_id ); ?>">
				<strong id="connectlibrary-lost-card-heading-<?php echo esc_attr( (string) $borrower_id ); ?>"><?php echo esc_html__( 'Replace lost card', 'connectlibrary' ); ?></strong>
				<p id="connectlibrary-lost-card-warning-<?php echo esc_attr( (string) $borrower_id ); ?>" class="description" role="status" aria-live="polite"><?php echo esc_html__( 'Confirm the current card is lost. The old card stops immediately and a new card will print next.', 'connectlibrary' ); ?></p>
				<p><label for="<?php echo esc_attr( $reason_id ); ?>"><?php echo esc_html__( 'Reason', 'connectlibrary' ); ?></label><br /><input id="<?php echo esc_attr( $reason_id ); ?>" name="replacement_reason" type="text" required value="<?php echo esc_attr__( 'Lost card', 'connectlibrary' ); ?>" /></p>
				<p><label for="<?php echo esc_attr( $note_id ); ?>"><?php echo esc_html__( 'Optional note', 'connectlibrary' ); ?></label><br /><textarea id="<?php echo esc_attr( $note_id ); ?>" name="replacement_note" rows="2"></textarea></p>
				<p><label for="<?php echo esc_attr( $confirm_id ); ?>"><input id="<?php echo esc_attr( $confirm_id ); ?>" name="lost_card_confirm" type="checkbox" value="1" required /> <?php echo esc_html__( 'I understand the old card will stop immediately.', 'connectlibrary' ); ?></label></p>
				<button type="submit" class="button"><?php echo esc_html__( 'Confirm and print replacement', 'connectlibrary' ); ?></button>
			</div>
		</form>
		<?php
	}

	/** Render one card action form. */
	private function render_card_action_form( int $borrower_id, string $action, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:0.5em;">
			<?php wp_nonce_field( self::CARD_NONCE_ACTION, '_wpnonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CARD_ACTION_NAME ); ?>" />
			<input type="hidden" name="borrower_id" value="<?php echo esc_attr( (string) $borrower_id ); ?>" />
			<input type="hidden" name="card_action" value="<?php echo esc_attr( $action ); ?>" />
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/** Normalize generate/reprint/replace service output to one printable card row. @param array<string,mixed> $result Service result. @return array<string,mixed> */
	private function printable_card_from_action_result( array $result ): array {
		$row = $result['row'] ?? $result;
		return is_array( $row ) ? $row : array();
	}

	/** Render active-card sheet print button. */
	private function render_sheet_print_button(): void {
		?>
		<h2><?php echo esc_html__( 'Card printing', 'connectlibrary' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::CARD_NONCE_ACTION, '_wpnonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::PRINT_ACTION_NAME ); ?>" />
			<button type="submit" class="button"><?php echo esc_html__( 'Print active card sheet', 'connectlibrary' ); ?></button>
		</form>
		<?php
	}

	/** Render privacy-safe print HTML for one card or a sheet. @param array<string,mixed>|null $card Card row, or null for sheet. */
	private function render_print_page( ?array $card ): void {
		$html = null === $card ? $this->card_service->render_sheet_for_active_cards() : $this->card_service->render_single_card( $card );
		if ( is_wp_error( $html ) ) {
			wp_die( esc_html( $html->get_error_message() ) );
		}
		?>
		<!doctype html><html><head><meta charset="utf-8" /><title><?php echo esc_html__( 'Print library cards', 'connectlibrary' ); ?></title>
		<style>.connectlibrary-card-sheet{display:flex;flex-wrap:wrap;gap:16px}.connectlibrary-card-print{border:1px solid #333;border-radius:8px;padding:12px;width:320px;break-inside:avoid;font-family:sans-serif}.borrower-name{font-size:18px;font-weight:700}.codes{display:flex;gap:8px;align-items:center}.privacy-note{font-size:11px;color:#555}@media print{button{display:none}}</style></head><body>
		<button type="button" onclick="window.print()"><?php echo esc_html__( 'Print', 'connectlibrary' ); ?></button>
		<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes borrower fields and emits controlled SVG markup. ?>
		</body></html>
		<?php
	}

	/**
	 * Render type/status/contact fields.
	 *
	 * @param array<string,mixed> $borrower Editing borrower, or empty for create.
	 */
	private function render_core_fields( array $borrower ): void {
		$type   = (string) ( $borrower['borrower_type'] ?? 'manual' );
		$status = (string) ( $borrower['status'] ?? 'active' );
		?>
		<tr>
			<th scope="row"><label for="connectlibrary-borrower-type"><?php echo esc_html__( 'Borrower type', 'connectlibrary' ); ?></label></th>
			<td><select id="connectlibrary-borrower-type" name="borrower_type">
				<?php foreach ( $this->type_choices() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></td>
		</tr>
		<tr>
			<th scope="row"><label for="connectlibrary-status"><?php echo esc_html__( 'Status', 'connectlibrary' ); ?></label></th>
			<td><select id="connectlibrary-status" name="status">
				<?php foreach ( $this->status_choices() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></td>
		</tr>
		<?php
		$this->text_input( 'display_name', __( 'Display name', 'connectlibrary' ), $borrower, true );
		$this->text_input( 'preferred_name', __( 'Preferred name', 'connectlibrary' ), $borrower );
		$this->text_input( 'email', __( 'Email', 'connectlibrary' ), $borrower, false, 'email' );
		$this->text_input( 'phone', __( 'Phone', 'connectlibrary' ), $borrower );
		$this->text_input( 'wp_user_id', __( 'WordPress user ID', 'connectlibrary' ), $borrower, false, 'number' );
	}

	/**
	 * Render guardian controls.
	 *
	 * @param array<string,mixed>            $borrower  Editing borrower, or empty for create.
	 * @param array<int,array<string,mixed>> $borrowers Borrower rows for guardian choices.
	 */
	private function render_guardian_fields( array $borrower, array $borrowers ): void {
		$guardian_id = (int) ( $borrower['guardian_borrower_id'] ?? 0 );
		?>
		<tr>
			<th scope="row"><label for="connectlibrary-guardian-borrower-id"><?php echo esc_html__( 'Active adult guardian', 'connectlibrary' ); ?></label></th>
			<td>
				<select id="connectlibrary-guardian-borrower-id" name="guardian_borrower_id">
					<option value=""><?php echo esc_html__( 'Use contact snapshot / no linked adult', 'connectlibrary' ); ?></option>
					<?php foreach ( $this->adult_guardian_options( $borrowers, (int) ( $borrower['id'] ?? 0 ) ) as $adult ) : ?>
						<option value="<?php echo esc_attr( (string) $adult['id'] ); ?>" <?php selected( $guardian_id, (int) $adult['id'] ); ?>><?php echo esc_html( (string) $adult['display_name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo esc_html__( 'Active child borrowers must have a valid active adult guardian link or a guardian contact snapshot before saving.', 'connectlibrary' ); ?></p>
			</td>
		</tr>
		<?php
		$this->text_input( 'guardian_name', __( 'Guardian snapshot name', 'connectlibrary' ), $borrower );
		$this->text_input( 'guardian_email', __( 'Guardian snapshot email', 'connectlibrary' ), $borrower, false, 'email' );
		$this->text_input( 'guardian_phone', __( 'Guardian snapshot phone', 'connectlibrary' ), $borrower );
		$this->text_input( 'guardian_relationship', __( 'Guardian relationship', 'connectlibrary' ), $borrower );
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Email notices', 'connectlibrary' ); ?></th>
			<td><label><input type="checkbox" name="email_notices_allowed" value="1" <?php checked( ! empty( $borrower['email_notices_allowed'] ) ); ?> /> <?php echo esc_html__( 'Guardian/contact may receive borrower notices in later circulation workflows.', 'connectlibrary' ); ?></label></td>
		</tr>
		<?php
	}

	/**
	 * Render one text-like input.
	 *
	 * @param string              $field    Field key.
	 * @param string              $label    Field label.
	 * @param array<string,mixed> $borrower Editing borrower, or empty for create.
	 * @param bool                $required Whether the input is required.
	 * @param string              $type     Input type.
	 */
	private function text_input( string $field, string $label, array $borrower, bool $required = false, string $type = 'text' ): void {
		$value = (string) ( $borrower[ $field ] ?? '' );
		?>
		<tr>
			<th scope="row"><label for="connectlibrary-<?php echo esc_attr( str_replace( '_', '-', $field ) ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input id="connectlibrary-<?php echo esc_attr( str_replace( '_', '-', $field ) ); ?>" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" <?php echo $required ? 'required' : ''; ?> /></td>
		</tr>
		<?php
	}

	/** Return posted borrower data. */
	private function posted_data(): array {
		$fields = array( 'borrower_type', 'wp_user_id', 'status', 'display_name', 'preferred_name', 'email', 'phone', 'guardian_borrower_id', 'guardian_name', 'guardian_email', 'guardian_phone', 'guardian_relationship', 'private_notes' );
		$data   = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $_POST ) ) {
				$data[ $field ] = wp_unslash( $_POST[ $field ] );
			}
		}
		$data['email_notices_allowed'] = ! empty( $_POST['email_notices_allowed'] );

		return $data;
	}

	/** Return borrower being edited from request. */
	private function editing_borrower(): array|WP_Error|null {
		$id = absint( wp_unslash( $_GET['borrower_id'] ?? 0 ) );
		if ( $id <= 0 ) {
			return null;
		}

		return $this->service->get( $id );
	}

	/** Render notice from query string. */
	private function render_notice(): void {
		$saved = sanitize_key( wp_unslash( $_GET['borrower_saved'] ?? '' ) );
		$error = sanitize_key( wp_unslash( $_GET['borrower_error'] ?? '' ) );
		if ( '' !== $saved ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Borrower saved.', 'connectlibrary' ) );
		}
		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $this->error_message( $error ) ) );
		}
	}

	/**
	 * Human message for service errors.
	 *
	 * @param string $code Error code.
	 */
	private function error_message( string $code ): string {
		return match ( $code ) {
			'connectlibrary_child_guardian_required' => __( 'Active child borrowers require an active adult guardian link or guardian contact snapshot.', 'connectlibrary' ),
			'connectlibrary_child_guardian_invalid' => __( 'Please choose an active adult guardian borrower.', 'connectlibrary' ),
			'connectlibrary_child_guardian_self' => __( 'A child borrower cannot be their own guardian.', 'connectlibrary' ),
			'connectlibrary_borrower_wp_user_missing' => __( 'Linked WordPress user does not exist.', 'connectlibrary' ),
			'connectlibrary_borrower_wp_user_exists' => __( 'An active borrower already exists for that WordPress user.', 'connectlibrary' ),
			'connectlibrary_card_already_active' => __( 'This borrower already has an active library card.', 'connectlibrary' ),
			'connectlibrary_card_missing' => __( 'This borrower does not have an active card yet.', 'connectlibrary' ),
			'connectlibrary_card_borrower_invalid' => __( 'Library cards require an active borrower.', 'connectlibrary' ),
			default => __( 'Borrower could not be saved. Please review the fields and try again.', 'connectlibrary' ),
		};
	}

	/**
	 * Build borrower map by ID.
	 *
	 * @param array<int,array<string,mixed>> $borrowers Borrower rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function borrower_map( array $borrowers ): array {
		$map = array();
		foreach ( $borrowers as $borrower ) {
			$map[ (int) ( $borrower['id'] ?? 0 ) ] = $borrower;
		}

		return $map;
	}

	/**
	 * Adult active guardian choices.
	 *
	 * @param array<int,array<string,mixed>> $borrowers  Borrower rows.
	 * @param int                            $exclude_id Borrower ID to exclude.
	 * @return array<int,array<string,mixed>>
	 */
	private function adult_guardian_options( array $borrowers, int $exclude_id = 0 ): array {
		return array_values(
			array_filter(
				$borrowers,
				static fn ( array $borrower ): bool => (int) ( $borrower['id'] ?? 0 ) !== $exclude_id && 'active' === (string) ( $borrower['status'] ?? '' ) && 'child' !== (string) ( $borrower['borrower_type'] ?? '' )
			)
		);
	}

	/**
	 * Summarize contact fields.
	 *
	 * @param array<string,mixed> $borrower Borrower row.
	 */
	private function contact_summary( array $borrower ): string {
		$parts = array_filter( array( (string) ( $borrower['email'] ?? '' ), (string) ( $borrower['phone'] ?? '' ) ) );

		return array() === $parts ? __( 'No contact on file', 'connectlibrary' ) : implode( ' / ', $parts );
	}

	/**
	 * Summarize WP link state.
	 *
	 * @param array<string,mixed> $borrower Borrower row.
	 */
	private function wp_link_summary( array $borrower ): string {
		$wp_user_id = (int) ( $borrower['wp_user_id'] ?? 0 );

		return $wp_user_id > 0 ? __( 'WordPress-linked account', 'connectlibrary' ) : __( 'No WordPress link', 'connectlibrary' );
	}

	/**
	 * Summarize guardian fields.
	 *
	 * @param array<string,mixed>            $borrower     Borrower row.
	 * @param array<int,array<string,mixed>> $borrower_map Borrower rows keyed by ID.
	 */
	private function guardian_summary( array $borrower, array $borrower_map ): string {
		$guardian_id = (int) ( $borrower['guardian_borrower_id'] ?? 0 );
		if ( $guardian_id > 0 ) {
			$name = (string) ( $borrower_map[ $guardian_id ]['display_name'] ?? '' );

			if ( '' !== $name ) {
				/* translators: %s: borrower display name. */
				return sprintf( __( 'Linked to %s', 'connectlibrary' ), $name );
			}

			return __( 'Linked borrower', 'connectlibrary' );
		}

		$parts = array_filter( array( (string) ( $borrower['guardian_name'] ?? '' ), (string) ( $borrower['guardian_email'] ?? '' ), (string) ( $borrower['guardian_phone'] ?? '' ) ) );

		return array() === $parts ? __( 'No guardian recorded', 'connectlibrary' ) : implode( ' / ', $parts );
	}

	/** Borrower type labels. */
	private function type_choices(): array {
		return array(
			'manual'  => __( 'Adult/manual', 'connectlibrary' ),
			'wp_user' => __( 'WordPress-linked', 'connectlibrary' ),
			'child'   => __( 'Child/youth', 'connectlibrary' ),
			'guest'   => __( 'Guest', 'connectlibrary' ),
		);
	}

	/** Borrower status labels. */
	private function status_choices(): array {
		return array(
			'active'       => __( 'Active', 'connectlibrary' ),
			'disabled'     => __( 'Disabled', 'connectlibrary' ),
			'merge_needed' => __( 'Merge needed', 'connectlibrary' ),
			'anonymized'   => __( 'Anonymized', 'connectlibrary' ),
		);
	}

	/**
	 * Label for a type key.
	 *
	 * @param string $type Type key.
	 */
	private function type_label( string $type ): string {
		$choices = $this->type_choices();

		return $choices[ $type ] ?? $type;
	}

	/**
	 * Label for a status key.
	 *
	 * @param string $status Status key.
	 */
	private function status_label( string $status ): string {
		$choices = $this->status_choices();

		return $choices[ $status ] ?? $status;
	}

	/** Admin page URL. */
	private function page_url(): string {
		return admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=' . self::PAGE_SLUG );
	}
}
