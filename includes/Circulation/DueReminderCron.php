<?php
/**
 * WordPress cron wiring for due-date reminders.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Circulation;

/**
 * Registers and runs the due-reminder cron event.
 */
final class DueReminderCron {
	public const HOOK = 'connectlibrary_due_reminder_cron';

	/**
	 * Register runtime hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Schedule the recurring event if missing.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Clear scheduled events.
	 */
	public static function clear(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( (int) $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Reschedule after activation/setup/settings changes.
	 */
	public static function reschedule(): void {
		self::clear();
		self::schedule();
	}

	/**
	 * Cron callback.
	 *
	 * @return array<string,int|string> Batch summary for tests/manual callers.
	 */
	public static function run(): array {
		$service = new DueReminderService();

		return $service->process_due_reminders();
	}
}
