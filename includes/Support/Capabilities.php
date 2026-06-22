<?php
/**
 * Capability constants and helpers for ConnectLibrary borrower management.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Support;

/**
 * Borrower management capabilities for ConnectLibrary.
 */
final class Capabilities {
	/**
	 * Custom capability for managing borrowers.
	 */
	public const MANAGE_BORROWERS = 'manage_connectlibrary_borrowers';

	/**
	 * Custom capability for librarian circulation actions (checkout/return/renew).
	 */
	public const MANAGE_CIRCULATION = 'manage_connectlibrary_circulation';

	/**
	 * WordPress core capability used as a fallback for manage_borrowers.
	 */
	public const MANAGE_OPTIONS = 'manage_options';

	/**
	 * Check whether the current user can manage borrowers.
	 *
	 * Grants access when the user holds the dedicated borrower capability OR
	 * the core manage_options capability (administrator fallback).
	 */
	public static function can_manage_borrowers(): bool {
		return current_user_can( self::MANAGE_BORROWERS )
			|| current_user_can( self::MANAGE_OPTIONS );
	}

	/**
	 * Check whether the current user can perform circulation actions.
	 *
	 * Librarians hold MANAGE_CIRCULATION; administrators fall through via
	 * MANAGE_OPTIONS.
	 */
	public static function can_manage_circulation(): bool {
		return current_user_can( self::MANAGE_CIRCULATION )
			|| current_user_can( self::MANAGE_OPTIONS );
	}

	/**
	 * Return the list of capabilities that grant borrower management access.
	 *
	 * @return string[]
	 */
	public static function borrower_capabilities(): array {
		return array(
			self::MANAGE_BORROWERS,
			self::MANAGE_OPTIONS,
		);
	}

	/**
	 * Return the list of capabilities that grant circulation access.
	 *
	 * @return string[]
	 */
	public static function circulation_capabilities(): array {
		return array(
			self::MANAGE_CIRCULATION,
			self::MANAGE_OPTIONS,
		);
	}
}
