<?php
/**
 * Plugin Name: ConnectLibrary
 * Plugin URI: https://github.com/connect-community-church/ConnectLibrary
 * Description: Phase 1 foundation for the Connect Community Church library catalog and circulation plugin.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Connect Community Church
 * Text Domain: connectlibrary
 * Domain Path: /languages
 *
 * @package ConnectLibrary
 */

defined( 'ABSPATH' ) || exit;

const CONNECTLIBRARY_VERSION     = '0.1.0';
const CONNECTLIBRARY_PLUGIN_FILE = __FILE__;
const CONNECTLIBRARY_PLUGIN_DIR  = __DIR__;
const CONNECTLIBRARY_TEXT_DOMAIN = 'connectlibrary';

define( 'CONNECTLIBRARY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ConnectLibrary\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class );
		$file           = CONNECTLIBRARY_PLUGIN_DIR . '/includes/' . $relative_path . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( CONNECTLIBRARY_PLUGIN_FILE, array( ConnectLibrary\Activator::class, 'activate' ) );
register_deactivation_hook( CONNECTLIBRARY_PLUGIN_FILE, array( ConnectLibrary\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		ConnectLibrary\Plugin::instance()->register();
	}
);
