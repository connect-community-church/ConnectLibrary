<?php
/**
 * Plugin deactivation tasks.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary;

use ConnectLibrary\Circulation\DueReminderCron;

defined( 'ABSPATH' ) || exit;

/**
 * Handles safe deactivation work.
 */
final class Deactivator {
	/**
	 * Deactivate without deleting ConnectLibrary data.
	 */
	public static function deactivate(): void {
		DueReminderCron::clear();
		flush_rewrite_rules();
	}
}
