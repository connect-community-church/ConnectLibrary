<?php
/**
 * Typed circulation-defaults adapter for Phase 2 workflows.
 *
 * All Phase 2 services that need loan period, hold period, reminder lead days,
 * librarian email, or default availability status must read through this class
 * rather than calling Settings::get() directly with hardcoded key strings. That
 * single point of access keeps option names, ranges, and fallbacks in one place.
 *
 * Settings are applied at action time and stored on the relevant records. Changing
 * a setting after a loan or hold is created does NOT retroactively alter existing
 * due dates or hold expiry timestamps.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Typed fallback adapter around ConnectLibrary\Settings\Settings for Phase 2.
 */
final class CirculationDefaults {

	/** Default loan/renewal period in days. */
	private const FALLBACK_LOAN_PERIOD_DAYS = 14;

	/** Default hold expiry period in days. */
	private const FALLBACK_HOLD_PERIOD_DAYS = 14;

	/** Default due-reminder lead days. */
	private const FALLBACK_REMINDER_LEAD_DAYS = 3;

	/** Default public availability status for new catalog titles. */
	private const FALLBACK_AVAILABILITY_STATUS = 'available';

	/** Allowlisted availability status values. */
	private const AVAILABILITY_STATUSES = array( 'available', 'reserved', 'checked_out', 'waitlist_available' );

	/**
	 * Loan period in days (1–365, default 14).
	 *
	 * Used by checkout and renewal to compute due_at from the current time.
	 */
	public static function loan_period_days(): int {
		$raw = Settings::get( 'default_loan_period_days' );
		$val = is_numeric( $raw ) ? (int) $raw : 0;

		return ( $val >= 1 && $val <= 365 ) ? $val : self::FALLBACK_LOAN_PERIOD_DAYS;
	}

	/**
	 * Hold expiry period in days (1–365, default 14).
	 *
	 * Used by reservation hold creation and waitlist promotion to compute hold_expires_at.
	 */
	public static function hold_period_days(): int {
		$raw = Settings::get( 'default_hold_period_days' );
		$val = is_numeric( $raw ) ? (int) $raw : 0;

		return ( $val >= 1 && $val <= 365 ) ? $val : self::FALLBACK_HOLD_PERIOD_DAYS;
	}

	/**
	 * Due-reminder lead days (0–60, default 3).
	 *
	 * 0 is valid and means reminders are eligible on the due date itself.
	 * Only falls back to 3 when the stored value is missing, non-numeric, or out of range.
	 */
	public static function due_reminder_lead_days(): int {
		$raw = Settings::get( 'due_reminder_lead_days' );

		if ( ! is_numeric( $raw ) ) {
			return self::FALLBACK_REMINDER_LEAD_DAYS;
		}

		$val = (int) $raw;

		return ( $val >= 0 && $val <= 60 ) ? $val : self::FALLBACK_REMINDER_LEAD_DAYS;
	}

	/**
	 * Librarian notification email (sanitized valid email, or '' when unconfigured).
	 *
	 * Never expose this in public REST responses or borrower-facing pages.
	 * If empty, callers must not fall back to an arbitrary address — they should
	 * surface an admin warning and log the event if the audit subsystem is available.
	 *
	 * TODO: wire audit log when the Phase 2 audit-log item is finalized.
	 */
	public static function librarian_email(): string {
		$raw   = (string) Settings::get( 'librarian_email' );
		$email = function_exists( 'sanitize_email' ) ? sanitize_email( $raw ) : trim( $raw );

		return ( '' !== $email && ( ! function_exists( 'is_email' ) || is_email( $email ) ) ) ? $email : '';
	}

	/**
	 * Default availability status for new catalog titles without a circulation state.
	 *
	 * Must not be used to override real circulation state: once reservations,
	 * loans, or waitlist entries exist, availability is derived from those records.
	 */
	public static function default_availability_status(): string {
		$val = (string) Settings::get( 'default_availability_status' );

		return in_array( $val, self::AVAILABILITY_STATUSES, true ) ? $val : self::FALLBACK_AVAILABILITY_STATUS;
	}
}
