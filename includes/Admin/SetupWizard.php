<?php
/**
 * First-run setup wizard for ConnectLibrary.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Settings\Settings;
use ConnectLibrary\Support\ScannerInput;

use function add_action;
use function add_query_arg;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function checked;
use function current_time;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_page_by_path;
use function get_post;
use function get_posts;
use function is_wp_error;
use function sanitize_key;
use function selected;
use function update_post_meta;
use function wp_dropdown_pages;
use function wp_insert_post;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_unslash;

use const HOUR_IN_SECONDS;

/**
 * Registers and renders the admin-only setup wizard.
 */
final class SetupWizard {
	private const PAGE_SLUG         = 'connectlibrary-setup';
	private const PARENT_SLUG       = 'edit.php?post_type=' . BookPostType::POST_TYPE;
	private const CAPABILITY        = 'manage_options';
	private const NONCE_ACTION      = 'connectlibrary_setup_wizard';
	private const ACTION_NAME       = 'connectlibrary_setup_wizard';
	private const CATALOG_SHORTCODE = '[connectlibrary_catalog]';
	private const DEMO_META_KEY     = '_connectlibrary_demo_book';

	/**
	 * Ordered wizard steps.
	 *
	 * @var array<int,string>
	 */
	private const STEPS = array( 'welcome', 'pages', 'defaults', 'metadata', 'scanner', 'demo', 'isbn', 'finish' );

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_notices', array( $this, 'render_setup_prompt' ) );
		add_action( 'admin_post_' . self::ACTION_NAME, array( $this, 'handle_post' ) );
	}

	/**
	 * Add the wizard below the Library admin menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			esc_html__( 'ConnectLibrary Setup', 'connectlibrary' ),
			esc_html__( 'Setup Wizard', 'connectlibrary' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Show a first-run prompt until setup is completed or temporarily dismissed.
	 */
	public function render_setup_prompt(): void {
		if ( ! current_user_can( self::CAPABILITY ) || $this->is_setup_complete() || $this->is_prompt_dismissed() ) {
			return;
		}

		$start_url = $this->step_url( 'welcome' );
		?>
		<div class="notice notice-info">
			<p><strong><?php echo esc_html__( 'ConnectLibrary setup is not finished yet.', 'connectlibrary' ); ?></strong></p>
			<p><?php echo esc_html__( 'Use the setup wizard to create the catalog page, confirm practical defaults, and learn which Phase 1 library tools are ready.', 'connectlibrary' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $start_url ); ?>"><?php echo esc_html__( 'Start setup wizard', 'connectlibrary' ); ?></a>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
				<input type="hidden" name="step" value="welcome" />
				<button type="submit" class="button-link" name="wizard_action" value="dismiss"><?php echo esc_html__( 'Skip for now', 'connectlibrary' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the current wizard step.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run ConnectLibrary setup.', 'connectlibrary' ) );
		}

		$step = $this->current_step();
		?>
		<div class="wrap connectlibrary-setup-wizard">
			<h1><?php echo esc_html__( 'ConnectLibrary Setup Wizard', 'connectlibrary' ); ?></h1>
			<?php $this->render_notice_from_request(); ?>
			<?php $this->render_progress( $step ); ?>
			<?php $this->render_step( $step ); ?>
		</div>
		<?php
	}

	/**
	 * Handle nonce-protected wizard submissions.
	 */
	public function handle_post(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to update ConnectLibrary setup.', 'connectlibrary' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$step   = isset( $_POST['step'] ) ? $this->normalize_step( sanitize_key( wp_unslash( $_POST['step'] ) ) ) : 'welcome';
		$action = isset( $_POST['wizard_action'] ) ? sanitize_key( wp_unslash( $_POST['wizard_action'] ) ) : 'continue';

		if ( 'back' === $action ) {
			$this->redirect_to_step( $this->previous_step( $step ) );
		}

		if ( 'dismiss' === $action ) {
			Settings::save( array( 'setup_dismissed_until' => gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) ) ) );
			$this->redirect_to_step( 'welcome', 'setup-skipped' );
		}

		switch ( $step ) {
			case 'pages':
				$this->handle_pages_step();
				break;
			case 'defaults':
				$this->handle_defaults_step();
				break;
			case 'metadata':
				$this->handle_metadata_step();
				break;
			case 'demo':
				$this->handle_demo_step();
				break;
			case 'isbn':
				$this->handle_isbn_step();
				break;
			case 'finish':
				Settings::save(
					array(
						'setup_completed_at'    => current_time( 'mysql' ),
						'setup_dismissed_until' => '',
					)
				);
				$this->redirect_to_step( 'finish', 'setup-complete' );
		}

		$this->redirect_to_step( $this->next_step( $step ) );
	}

	/**
	 * Current setup state.
	 */
	private function is_setup_complete(): bool {
		return '' !== (string) Settings::get( 'setup_completed_at' );
	}

	/**
	 * Whether the prompt is temporarily dismissed.
	 */
	private function is_prompt_dismissed(): bool {
		$dismissed_until = (string) Settings::get( 'setup_dismissed_until' );

		return '' !== $dismissed_until && strtotime( $dismissed_until ) > time();
	}

	/**
	 * Render the requested step.
	 *
	 * @param string $step Step slug.
	 */
	private function render_step( string $step ): void {
		switch ( $step ) {
			case 'pages':
				$this->render_pages_step();
				break;
			case 'defaults':
				$this->render_defaults_step();
				break;
			case 'metadata':
				$this->render_metadata_step();
				break;
			case 'scanner':
				$this->render_scanner_step();
				break;
			case 'demo':
				$this->render_demo_step();
				break;
			case 'isbn':
				$this->render_isbn_step();
				break;
			case 'finish':
				$this->render_finish_step();
				break;
			default:
				$this->render_welcome_step();
		}
	}

	/**
	 * Render the welcome step.
	 */
	private function render_welcome_step(): void {
		$this->open_form( 'welcome' );
		?>
		<h2><?php echo esc_html__( 'Welcome', 'connectlibrary' ); ?></h2>
		<p><?php echo esc_html__( 'This wizard will help prepare the online catalog foundation. It can create a catalog page, save safe defaults, explain scanner/card workflows, optionally create demo books, and point you to manual book entry.', 'connectlibrary' ); ?></p>
		<ul>
			<li><?php echo esc_html__( 'Status: Phase 1 online catalog foundation.', 'connectlibrary' ); ?></li>
			<li><?php echo esc_html__( 'Access: WordPress administrators can run this wizard. Librarian role access can be wired when that role is introduced.', 'connectlibrary' ); ?></li>
			<li><?php echo esc_html__( 'Out of scope: Offline/PWA, circulation, borrower records, card printing, and live emails.', 'connectlibrary' ); ?></li>
		</ul>
		<?php
		$this->render_actions( 'welcome', false, __( 'Continue', 'connectlibrary' ), true );
		$this->close_form();
	}

	/**
	 * Render the catalog page step.
	 */
	private function render_pages_step(): void {
		$settings = Settings::all();
		$this->open_form( 'pages' );
		?>
		<h2><?php echo esc_html__( 'Catalog page', 'connectlibrary' ); ?></h2>
		<p><?php echo esc_html__( 'Create or select the public page that will point visitors to the library catalog.', 'connectlibrary' ); ?></p>
		<fieldset>
			<legend class="screen-reader-text"><?php echo esc_html__( 'Catalog page setup choice', 'connectlibrary' ); ?></legend>
			<p><label><input type="radio" name="catalog_page_mode" value="create" checked="checked" /> <?php echo esc_html__( 'Create or reuse the standard Library Catalog page', 'connectlibrary' ); ?></label></p>
			<p><label><input type="radio" name="catalog_page_mode" value="select" /> <?php echo esc_html__( 'Use an existing page', 'connectlibrary' ); ?></label></p>
		</fieldset>
		<p>
			<label for="connectlibrary_catalog_page_id"><strong><?php echo esc_html__( 'Existing page', 'connectlibrary' ); ?></strong></label><br />
			<?php
			wp_dropdown_pages(
				array(
					'name'              => 'catalog_page_id',
					'id'                => 'connectlibrary_catalog_page_id',
					'selected'          => (int) $settings['catalog_page_id'],
					'show_option_none'  => __( '— Select —', 'connectlibrary' ),
					'option_none_value' => '0',
				)
			);
			?>
		</p>
		<p class="description"><?php echo esc_html__( 'Created catalog pages include the [connectlibrary_catalog] shortcode, which renders the public catalog once books are visible in the catalog.', 'connectlibrary' ); ?></p>
		<?php
		$this->render_actions( 'pages' );
		$this->close_form();
	}

	/**
	 * Render defaults step.
	 */
	private function render_defaults_step(): void {
		$settings = Settings::all();
		$this->open_form( 'defaults' );
		?>
		<h2><?php echo esc_html__( 'Library defaults', 'connectlibrary' ); ?></h2>
		<p><?php echo esc_html__( 'Confirm the practical defaults that later circulation features will reuse. Saving these does not send emails or enable checkout.', 'connectlibrary' ); ?></p>
		<table class="form-table" role="presentation"><tbody>
			<?php $this->render_number_row( 'default_loan_period_days', __( 'Loan period days', 'connectlibrary' ), (int) $settings['default_loan_period_days'], 1, 365 ); ?>
			<?php $this->render_number_row( 'default_hold_period_days', __( 'Hold period days', 'connectlibrary' ), (int) $settings['default_hold_period_days'], 1, 365 ); ?>
			<?php $this->render_number_row( 'due_reminder_lead_days', __( 'Reminder days before due date', 'connectlibrary' ), (int) $settings['due_reminder_lead_days'], 0, 60 ); ?>
			<tr><th scope="row"><label for="connectlibrary_librarian_email"><?php echo esc_html__( 'Librarian notification email', 'connectlibrary' ); ?></label></th><td><input type="email" class="regular-text" id="connectlibrary_librarian_email" name="librarian_email" value="<?php echo esc_attr( (string) $settings['librarian_email'] ); ?>" /></td></tr>
			<tr><th scope="row"><label for="connectlibrary_default_availability_status"><?php echo esc_html__( 'Default public availability wording', 'connectlibrary' ); ?></label></th><td><select id="connectlibrary_default_availability_status" name="default_availability_status">
				<?php foreach ( Settings::availability_status_choices() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $settings['default_availability_status'], $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
				<?php endforeach; ?>
			</select></td></tr>
		</tbody></table>
		<?php
		$this->render_actions( 'defaults' );
		$this->close_form();
	}

	/**
	 * Render metadata step.
	 */
	private function render_metadata_step(): void {
		$settings = Settings::all();
		$this->open_form( 'metadata' );
		?>
		<h2><?php echo esc_html__( 'Metadata and ISBN lookup defaults', 'connectlibrary' ); ?></h2>
		<p><?php echo esc_html__( 'ConnectLibrary uses Google Books first and Open Library as a fallback for ISBN metadata. No API keys are required here.', 'connectlibrary' ); ?></p>
		<p><label for="connectlibrary_metadata_provider_order"><strong><?php echo esc_html__( 'Provider order', 'connectlibrary' ); ?></strong></label><br />
		<select id="connectlibrary_metadata_provider_order" name="metadata_provider_order">
			<?php foreach ( Settings::metadata_provider_order_choices() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $settings['metadata_provider_order'], $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
			<?php endforeach; ?>
		</select></p>
		<p><label><input type="checkbox" name="import_covers_locally" value="1" <?php checked( 1, (int) $settings['import_covers_locally'] ); ?> /> <?php echo esc_html__( 'Store imported covers in the WordPress Media Library when ISBN lookup finds cover candidates.', 'connectlibrary' ); ?></label></p>
		<p><label for="connectlibrary_metadata_language"><strong><?php echo esc_html__( 'Preferred language', 'connectlibrary' ); ?></strong></label><br /><input type="text" id="connectlibrary_metadata_language" name="metadata_language" value="<?php echo esc_attr( (string) $settings['metadata_language'] ); ?>" class="small-text" /></p>
		<p class="description"><?php echo esc_html__( 'To use ISBN lookup, open Library > Add New Book or edit an existing book, then use Metadata source/import details > ISBN metadata lookup.', 'connectlibrary' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( $this->add_book_url() ); ?>"><?php echo esc_html__( 'Add a book with ISBN lookup', 'connectlibrary' ); ?></a></p>
		<?php
		$this->render_actions( 'metadata' );
		$this->close_form();
	}

	/**
	 * Render scanner explanation step.
	 */
	private function render_scanner_step(): void {
		$this->open_form( 'scanner' );
		?>
		<h2><?php echo esc_html__( 'Scanners and future borrower cards', 'connectlibrary' ); ?></h2>
		<p><?php echo esc_html__( 'Most USB or Bluetooth ISBN scanners work like a keyboard: click an ISBN field, scan the barcode, and the numbers are typed automatically.', 'connectlibrary' ); ?></p>
		<p><?php echo esc_html__( 'Future borrower cards should use secure random QR/barcode tokens. They should not encode WordPress IDs, member IDs, names, phone numbers, or other personal information.', 'connectlibrary' ); ?></p>
		<p><?php echo esc_html__( 'Full borrower cards, checkout, return, holds, and circulation scanning are later Phase 2/3 workflows and are not required to finish setup.', 'connectlibrary' ); ?></p>
		<?php
		$this->render_actions( 'scanner' );
		$this->close_form();
	}

	/**
	 * Render demo content step.
	 */
	private function render_demo_step(): void {
		$demo_count = count( $this->find_demo_books() );
		$this->open_form( 'demo' );
		?>
		<h2><?php echo esc_html__( 'Optional demo books', 'connectlibrary' ); ?></h2>
		<p><?php echo esc_html__( 'Demo books are safe sample catalog records only. They do not include borrower, member, or personal data.', 'connectlibrary' ); ?></p>
		<p><?php /* translators: %d: Number of existing demo books. */ echo esc_html( sprintf( __( 'Current demo books found: %d.', 'connectlibrary' ), $demo_count ) ); ?></p>
		<p><label><input type="radio" name="demo_choice" value="skip" checked="checked" /> <?php echo esc_html__( 'Skip demo books', 'connectlibrary' ); ?></label></p>
		<p><label><input type="radio" name="demo_choice" value="create" /> <?php echo esc_html__( 'Create safe sample books if they do not already exist', 'connectlibrary' ); ?></label></p>
		<?php
		$this->render_actions( 'demo' );
		$this->close_form();
	}

	/**
	 * Render ISBN step.
	 */
	private function render_isbn_step(): void {
		$this->open_form( 'isbn' );
		?>
		<h2><?php echo esc_html__( 'Add the first book by ISBN', 'connectlibrary' ); ?></h2>
		<p><?php echo esc_html__( 'Scan or type an ISBN here to open the Add New Book screen with the ISBN lookup field prefilled. On the book screen, use Metadata source/import details > ISBN metadata lookup, then review and apply the suggested fields.', 'connectlibrary' ); ?></p>
		<p><label for="connectlibrary_first_isbn"><strong><?php echo esc_html__( 'ISBN', 'connectlibrary' ); ?></strong></label><br /><input type="text" id="connectlibrary_first_isbn" name="isbn" class="regular-text" inputmode="numeric" autocomplete="off" /></p>
		<p><a class="button" href="<?php echo esc_url( $this->add_book_url() ); ?>"><?php echo esc_html__( 'Open Add New Book', 'connectlibrary' ); ?></a></p>
		<?php
		$this->render_actions( 'isbn', true, __( 'Continue without opening Add New Book', 'connectlibrary' ) );
		$this->close_form();
	}

	/**
	 * Render finish step.
	 */
	private function render_finish_step(): void {
		$settings        = Settings::all();
		$catalog_page_id = (int) $settings['catalog_page_id'];
		$this->open_form( 'finish' );
		?>
		<h2><?php echo esc_html__( 'Finish setup', 'connectlibrary' ); ?></h2>
		<ul>
			<li><?php /* translators: %d: WordPress page ID. */ echo esc_html( sprintf( __( 'Catalog page ID: %d', 'connectlibrary' ), $catalog_page_id ) ); ?></li>
			<li><?php /* translators: 1: Loan period days, 2: hold period days, 3: reminder lead days. */ echo esc_html( sprintf( __( 'Loan days: %1$d; hold days: %2$d; reminder lead days: %3$d.', 'connectlibrary' ), (int) $settings['default_loan_period_days'], (int) $settings['default_hold_period_days'], (int) $settings['due_reminder_lead_days'] ) ); ?></li>
			<li><?php echo esc_html__( 'Demo records are marked with private demo metadata when created.', 'connectlibrary' ); ?></li>
			<li><?php echo esc_html__( 'ISBN lookup is available on each Add/Edit Book screen under Metadata source/import details.', 'connectlibrary' ); ?></li>
		</ul>
		<p><?php echo esc_html__( 'To import a book by ISBN, choose Add Book, enter or scan the ISBN in the ISBN metadata lookup section, then review and apply only the fields you want to keep.', 'connectlibrary' ); ?></p>
		<p>
			<?php if ( $catalog_page_id > 0 ) : ?>
				<a class="button" href="<?php echo esc_url( get_permalink( $catalog_page_id ) ); ?>"><?php echo esc_html__( 'View catalog page', 'connectlibrary' ); ?></a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( $this->add_book_url() ); ?>"><?php echo esc_html__( 'Add Book with ISBN lookup', 'connectlibrary' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=connectlibrary-settings' ) ); ?>"><?php echo esc_html__( 'Settings', 'connectlibrary' ); ?></a>
			<a class="button" href="<?php echo esc_url( $this->step_url( 'welcome' ) ); ?>"><?php echo esc_html__( 'Run wizard again', 'connectlibrary' ); ?></a>
		</p>
		<?php
		$this->render_actions( 'finish', true, __( 'Mark setup complete', 'connectlibrary' ) );
		$this->close_form();
	}

	/**
	 * Save page selection/creation.
	 */
	private function handle_pages_step(): void {
		$mode    = isset( $_POST['catalog_page_mode'] ) ? sanitize_key( wp_unslash( $_POST['catalog_page_mode'] ) ) : 'create';
		$page_id = 0;

		if ( 'select' === $mode ) {
			$page_id = isset( $_POST['catalog_page_id'] ) ? absint( wp_unslash( $_POST['catalog_page_id'] ) ) : 0;
		} else {
			$page_id = $this->create_or_get_catalog_page();
		}

		if ( $page_id > 0 && 'page' === get_post_type( $page_id ) ) {
			Settings::save( array( 'catalog_page_id' => $page_id ) );
			$this->redirect_to_step( 'defaults', 'pages-saved' );
		}

		$this->redirect_to_step( 'pages', 'invalid-page' );
	}

	/**
	 * Save practical defaults.
	 */
	private function handle_defaults_step(): void {
		Settings::save(
			array(
				'default_loan_period_days'    => $_POST['default_loan_period_days'] ?? '',
				'default_hold_period_days'    => $_POST['default_hold_period_days'] ?? '',
				'due_reminder_lead_days'      => $_POST['due_reminder_lead_days'] ?? '',
				'librarian_email'             => $_POST['librarian_email'] ?? '',
				'default_availability_status' => $_POST['default_availability_status'] ?? '',
			)
		);
	}

	/**
	 * Save metadata defaults.
	 */
	private function handle_metadata_step(): void {
		Settings::save(
			array(
				'metadata_provider_order' => $_POST['metadata_provider_order'] ?? '',
				'import_covers_locally'   => isset( $_POST['import_covers_locally'] ) ? '1' : '',
				'metadata_language'       => $_POST['metadata_language'] ?? '',
			)
		);
	}

	/**
	 * Optionally create demo records.
	 */
	private function handle_demo_step(): void {
		$choice = isset( $_POST['demo_choice'] ) ? sanitize_key( wp_unslash( $_POST['demo_choice'] ) ) : 'skip';

		if ( 'create' === $choice ) {
			$this->create_demo_books();
			Settings::save( array( 'demo_content_created_at' => current_time( 'mysql' ) ) );
			$this->redirect_to_step( 'isbn', 'demo-created' );
		}
	}

	/**
	 * Send the first ISBN input to the Add Book screen for lookup.
	 */
	private function handle_isbn_step(): void {
		$isbn = isset( $_POST['isbn'] ) ? ScannerInput::sanitize_text( wp_unslash( $_POST['isbn'] ) ) : '';

		if ( '' !== $isbn ) {
			wp_safe_redirect( $this->add_book_url( $isbn ) );
			exit;
		}
	}

	/**
	 * Add New Book URL, optionally prefilled for ISBN lookup.
	 *
	 * @param string $isbn Optional raw ISBN from the wizard.
	 */
	private function add_book_url( string $isbn = '' ): string {
		$url = admin_url( 'post-new.php?post_type=' . BookPostType::POST_TYPE );
		if ( '' === $isbn ) {
			return $url;
		}

		return add_query_arg( array( 'connectlibrary_prefill_isbn' => ScannerInput::sanitize_text( $isbn ) ), $url );
	}

	/**
	 * Idempotently create or reuse the catalog page.
	 */
	private function create_or_get_catalog_page(): int {
		$current_page_id = (int) Settings::get( 'catalog_page_id' );
		if ( $current_page_id > 0 && 'page' === get_post_type( $current_page_id ) ) {
			return $current_page_id;
		}

		$existing = get_page_by_path( 'library-catalog' );
		if ( $existing && 'page' === get_post_type( (int) $existing->ID ) ) {
			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Library Catalog', 'connectlibrary' ),
				'post_name'    => 'library-catalog',
				'post_content' => "<!-- wp:paragraph -->\n<p>" . self::CATALOG_SHORTCODE . "</p>\n<!-- /wp:paragraph -->",
			),
			true
		);

		return is_wp_error( $page_id ) ? 0 : (int) $page_id;
	}

	/**
	 * Idempotently create safe demo books.
	 */
	private function create_demo_books(): void {
		if ( count( $this->find_demo_books() ) > 0 ) {
			return;
		}

		$books = array(
			array(
				'title'   => __( 'Demo Book: The Helpful Library', 'connectlibrary' ),
				'content' => __( 'A sample church-library book record for testing catalog setup.', 'connectlibrary' ),
			),
			array(
				'title'   => __( 'Demo Book: Sunday Stories', 'connectlibrary' ),
				'content' => __( 'A clearly marked demo record with no borrower or member data.', 'connectlibrary' ),
			),
			array(
				'title'   => __( 'Demo Book: Volunteers Guide', 'connectlibrary' ),
				'content' => __( 'A safe sample record librarians can delete after setup review.', 'connectlibrary' ),
			),
		);

		foreach ( $books as $book ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => BookPostType::POST_TYPE,
					'post_status'  => 'draft',
					'post_title'   => $book['title'],
					'post_content' => $book['content'],
				),
				true
			);

			if ( ! is_wp_error( $post_id ) && (int) $post_id > 0 ) {
				update_post_meta( (int) $post_id, self::DEMO_META_KEY, '1' );
			}
		}
	}

	/**
	 * Find existing demo books.
	 *
	 * @return array<int,mixed>
	 */
	private function find_demo_books(): array {
		return get_posts(
			array(
				'post_type'      => BookPostType::POST_TYPE,
				'post_status'    => array( 'any' ),
				'fields'         => 'ids',
				'posts_per_page' => 10,
				'meta_key'       => self::DEMO_META_KEY,
				'meta_value'     => '1',
			)
		);
	}

	/**
	 * Render wizard progress.
	 *
	 * @param string $current_step Current step slug.
	 */
	private function render_progress( string $current_step ): void {
		$labels = $this->step_labels();
		echo '<nav aria-label="' . esc_attr__( 'Setup progress', 'connectlibrary' ) . '"><ol class="connectlibrary-setup-progress">';
		foreach ( self::STEPS as $step ) {
			$label = $labels[ $step ] ?? ucfirst( $step );
			printf( '<li%1$s>%2$s</li>', $step === $current_step ? ' aria-current="step"' : '', esc_html( $label ) );
		}
		echo '</ol></nav>';
	}

	/**
	 * Translatable labels for each wizard step.
	 *
	 * @return array<string,string>
	 */
	private function step_labels(): array {
		return array(
			'welcome'  => __( 'Welcome', 'connectlibrary' ),
			'pages'    => __( 'Pages', 'connectlibrary' ),
			'defaults' => __( 'Defaults', 'connectlibrary' ),
			'metadata' => __( 'Metadata', 'connectlibrary' ),
			'scanner'  => __( 'Scanner', 'connectlibrary' ),
			'demo'     => __( 'Demo', 'connectlibrary' ),
			'isbn'     => __( 'ISBN', 'connectlibrary' ),
			'finish'   => __( 'Finish', 'connectlibrary' ),
		);
	}

	/**
	 * Render query-string notices.
	 */
	private function render_notice_from_request(): void {
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		$notices = array(
			'pages-saved'      => __( 'Catalog page setting saved.', 'connectlibrary' ),
			'invalid-page'     => __( 'Please create or select a valid WordPress page.', 'connectlibrary' ),
			'demo-created'     => __( 'Demo book step finished. Existing demo records are reused, not duplicated.', 'connectlibrary' ),
			'isbn-unavailable' => __( 'ISBN lookup is available from the Add/Edit Book screen. Use Metadata source/import details > ISBN metadata lookup, or enter the book manually if providers are rate limited.', 'connectlibrary' ),
			'setup-complete'   => __( 'Setup is marked complete. The wizard remains available from the Library menu.', 'connectlibrary' ),
			'setup-skipped'    => __( 'Setup prompt skipped for now. You can resume from the Library menu.', 'connectlibrary' ),
		);

		if ( isset( $notices[ $message ] ) ) {
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $notices[ $message ] ) );
		}
	}

	/**
	 * Open a standard wizard form.
	 *
	 * @param string $step Step slug.
	 */
	private function open_form( string $step ): void {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::NONCE_ACTION );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_NAME ) );
		printf( '<input type="hidden" name="step" value="%s" />', esc_attr( $step ) );
	}

	/**
	 * Close a wizard form.
	 */
	private function close_form(): void {
		echo '</form>';
	}

	/**
	 * Render navigation buttons.
	 *
	 * @param string $step Step slug.
	 * @param bool   $allow_skip Whether to show the optional skip button.
	 * @param string $continue_label Label for the primary button.
	 * @param bool   $allow_dismiss Whether to show prompt dismissal.
	 */
	private function render_actions( string $step, bool $allow_skip = false, string $continue_label = '', bool $allow_dismiss = false ): void {
		$label = '' !== $continue_label ? $continue_label : __( 'Continue', 'connectlibrary' );
		echo '<p class="submit">';
		if ( 'welcome' !== $step ) {
			echo '<button type="submit" class="button" name="wizard_action" value="back">' . esc_html__( 'Back', 'connectlibrary' ) . '</button> ';
		}
		if ( $allow_skip ) {
			echo '<button type="submit" class="button" name="wizard_action" value="skip">' . esc_html__( 'Skip optional step', 'connectlibrary' ) . '</button> ';
		}
		if ( $allow_dismiss ) {
			echo '<button type="submit" class="button" name="wizard_action" value="dismiss">' . esc_html__( 'Skip for now', 'connectlibrary' ) . '</button> ';
		}
		printf( '<button type="submit" class="button button-primary" name="wizard_action" value="continue">%s</button>', esc_html( $label ) );
		echo '</p>';
	}

	/**
	 * Render a number input row.
	 *
	 * @param string $key Field key.
	 * @param string $label Field label.
	 * @param int    $value Current value.
	 * @param int    $min Minimum value.
	 * @param int    $max Maximum value.
	 */
	private function render_number_row( string $key, string $label, int $value, int $min, int $max ): void {
		printf(
			'<tr><th scope="row"><label for="connectlibrary_%1$s">%2$s</label></th><td><input type="number" class="small-text" id="connectlibrary_%1$s" name="%1$s" value="%3$d" min="%4$d" max="%5$d" step="1" /></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			$value,
			$min,
			$max
		);
	}

	/**
	 * Get the requested step.
	 */
	private function current_step(): string {
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'welcome';

		return $this->normalize_step( $step );
	}

	/**
	 * Normalize step names to known values.
	 *
	 * @param string $step Step slug.
	 */
	private function normalize_step( string $step ): string {
		return in_array( $step, self::STEPS, true ) ? $step : 'welcome';
	}

	/**
	 * Return next step.
	 *
	 * @param string $step Step slug.
	 */
	private function next_step( string $step ): string {
		$index = array_search( $step, self::STEPS, true );

		return self::STEPS[ min( (int) $index + 1, count( self::STEPS ) - 1 ) ];
	}

	/**
	 * Return previous step.
	 *
	 * @param string $step Step slug.
	 */
	private function previous_step( string $step ): string {
		$index = array_search( $step, self::STEPS, true );

		return self::STEPS[ max( (int) $index - 1, 0 ) ];
	}

	/**
	 * Redirect to a wizard step and stop execution.
	 *
	 * @param string $step Step slug.
	 * @param string $message Optional notice message key.
	 */
	private function redirect_to_step( string $step, string $message = '' ): void {
		wp_safe_redirect( $this->step_url( $step, $message ) );
		exit;
	}

	/**
	 * Build an admin URL for a step.
	 *
	 * @param string $step Step slug.
	 * @param string $message Optional notice message key.
	 */
	private function step_url( string $step, string $message = '' ): string {
		$args = array(
			'post_type' => BookPostType::POST_TYPE,
			'page'      => self::PAGE_SLUG,
			'step'      => $step,
		);

		if ( '' !== $message ) {
			$args['message'] = $message;
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}
}
