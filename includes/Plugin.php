<?php
/**
 * Main plugin bootstrap.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary;

use ConnectLibrary\Admin\BookMetadataMetaboxes;
use ConnectLibrary\Admin\AuditHistoryPage;
use ConnectLibrary\Admin\BorrowersPage;
use ConnectLibrary\Admin\CirculationPage;
use ConnectLibrary\Admin\LibrarianDashboardPage;
use ConnectLibrary\Admin\PrintLibraryCardsPage;
use ConnectLibrary\Admin\ReservationsPage;
use ConnectLibrary\Admin\SettingsPage;
use ConnectLibrary\Admin\SetupWizard;
use ConnectLibrary\Admin\ReportsPage;
use ConnectLibrary\Admin\Status;
use ConnectLibrary\Catalog\CatalogServiceProvider;
use ConnectLibrary\Circulation\DueReminderCron;
use ConnectLibrary\Frontend\PublicServiceProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates ConnectLibrary hooks for the current request.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Admin status screen.
	 *
	 * @var Status
	 */
	private Status $status_screen;

	/**
	 * Admin settings screen.
	 *
	 * @var SettingsPage
	 */
	private SettingsPage $settings_page;

	/**
	 * Book metadata admin metaboxes.
	 *
	 * @var BookMetadataMetaboxes
	 */
	private BookMetadataMetaboxes $book_metadata_metaboxes;

	/**
	 * Borrower admin screen.
	 *
	 * @var BorrowersPage
	 */
	private BorrowersPage $borrowers_page;

	/**
	 * Reservation admin screen.
	 *
	 * @var ReservationsPage
	 */
	private ReservationsPage $reservations_page;

	/**
	 * Librarian operations dashboard.
	 *
	 * @var LibrarianDashboardPage
	 */
	private LibrarianDashboardPage $librarian_dashboard;

	/**
	 * Circulation admin dashboard.
	 *
	 * @var CirculationPage
	 */
	private CirculationPage $circulation_page;

	/**
	 * Library reports screen.
	 *
	 * @var ReportsPage
	 */
	private ReportsPage $reports_page;

	/**
	 * Audit and history admin screen.
	 *
	 * @var AuditHistoryPage
	 */
	private AuditHistoryPage $audit_history_page;

	/**
	 * Print library cards screen.
	 *
	 * @var PrintLibraryCardsPage
	 */
	private PrintLibraryCardsPage $print_cards_page;

	/**
	 * First-run setup wizard.
	 *
	 * @var SetupWizard
	 */
	private SetupWizard $setup_wizard;

	/**
	 * Catalog service provider.
	 *
	 * @var CatalogServiceProvider
	 */
	private CatalogServiceProvider $catalog;

	/**
	 * Public front-end service provider.
	 *
	 * @var PublicServiceProvider
	 */
	private PublicServiceProvider $public_frontend;

	/**
	 * Create the plugin coordinator.
	 */
	private function __construct() {
		$this->status_screen           = new Status();
		$this->settings_page           = new SettingsPage();
		$this->book_metadata_metaboxes = new BookMetadataMetaboxes();
		$this->librarian_dashboard     = new LibrarianDashboardPage();
		$this->borrowers_page          = new BorrowersPage();
		$this->reservations_page       = new ReservationsPage();
		$this->circulation_page        = new CirculationPage();
		$this->reports_page            = new ReportsPage();
		$this->audit_history_page      = new AuditHistoryPage();
		$this->print_cards_page        = new PrintLibraryCardsPage();
		$this->setup_wizard            = new SetupWizard();
		$this->catalog                 = new CatalogServiceProvider();
		$this->public_frontend         = new PublicServiceProvider();
	}

	/**
	 * Get the plugin coordinator instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		DueReminderCron::register();
		$this->catalog->register();
		$this->public_frontend->register();

		if ( is_admin() ) {
			$this->status_screen->register();
			$this->settings_page->register();
			$this->book_metadata_metaboxes->register();
			$this->librarian_dashboard->register();
			$this->borrowers_page->register();
			$this->reservations_page->register();
			$this->circulation_page->register();
			$this->reports_page->register();
			$this->audit_history_page->register();
			$this->print_cards_page->register();
			$this->setup_wizard->register();
		}
	}

	/**
	 * Load translations when language files are available.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			CONNECTLIBRARY_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( CONNECTLIBRARY_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
