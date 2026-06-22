<?php
/**
 * Plugin activation tasks.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary;

use ConnectLibrary\Catalog\CatalogServiceProvider;
use ConnectLibrary\Circulation\DueReminderCron;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Settings\Settings;
use ConnectLibrary\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Handles safe, non-destructive activation work.
 */
final class Activator {
	/**
	 * Store the installed plugin version, initialize settings defaults, and refresh catalog rewrites.
	 *
	 * Activation intentionally does not create catalog records, borrower data,
	 * pages, emails, external API calls, or destructive migrations.
	 */
	public static function activate(): void {
		Schema::migrate();
		update_option( 'connectlibrary_version', CONNECTLIBRARY_VERSION, false );
		Settings::initialize_defaults();
		DueReminderCron::schedule();
		self::grant_borrower_capabilities();
		CatalogServiceProvider::register_catalog_objects();
		flush_rewrite_rules();
	}

	/**
	 * Grant custom borrower capabilities to built-in roles that already satisfy
	 * the manage_options fallback used by protected borrower screens.
	 */
	private static function grant_borrower_capabilities(): void {
		$administrator = get_role( 'administrator' );

		if ( null !== $administrator ) {
			$administrator->add_cap( Capabilities::MANAGE_BORROWERS );
		}
	}
}
