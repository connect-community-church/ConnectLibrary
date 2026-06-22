<?php
/**
 * REST API route registration for ConnectLibrary.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Rest;

/**
 * Registers public REST API routes for the catalog namespace.
 */
final class Routes {
	public const NAMESPACE = 'connectlibrary/v1';

	/** Register route hooks. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/** Register all ConnectLibrary v1 routes. */
	public function register_routes(): void {
		$books        = new BooksController();
		$lookups      = new LookupsController();
		$borrowers    = new BorrowersController();
		$audit_events = new AuditEventsController();
		$overrides    = new OverridesController();

		$books->register_routes();
		$lookups->register_routes();
		$borrowers->register_routes();
		$audit_events->register_routes();
		$overrides->register_routes();
	}
}
