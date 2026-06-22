<?php
/**
 * REST API controller for shared audit event visibility.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Rest;

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Support\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Provides librarian/admin-only access to the shared audit event log.
 *
 * Borrowers and guests are denied at the permission_callback layer;
 * no audit data is ever exposed without MANAGE_BORROWERS or manage_options.
 */
final class AuditEventsController {

	/**
	 * Audit event service.
	 *
	 * @var AuditEventService
	 */
	private AuditEventService $audit;

	/**
	 * Constructor.
	 *
	 * @param AuditEventService|null $audit Optional override for testing.
	 */
	public function __construct( ?AuditEventService $audit = null ) {
		$this->audit = $audit ?? new AuditEventService();
	}

	/**
	 * Register the audit-events REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/audit-events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_events' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => $this->route_args(),
			)
		);
	}

	/**
	 * Handle GET /audit-events.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response
	 */
	public function list_events( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( 100, (int) ( $request['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $request['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$filters = $this->filters_from_request( $request );
		$events  = array_map(
			array( $this->audit, 'format_safe_event' ),
			$this->audit->query( $filters, $per_page, $offset )
		);

		return rest_ensure_response(
			array(
				'events'   => $events,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * REST route argument definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function route_args(): array {
		$args = array();
		foreach ( array( 'entity_type', 'object_type', 'action', 'action_group', 'actor_type', 'status', 'outcome', 'source_channel' ) as $key ) {
			$args[ $key ] = array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			);
		}
		foreach ( array( 'entity_id', 'object_id', 'actor_id' ) as $key ) {
			$args[ $key ] = array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			);
		}
		foreach ( array( 'correlation_id', 'from', 'to', 'search' ) as $key ) {
			$args[ $key ] = array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			);
		}
		$args['per_page'] = array(
			'type'    => 'integer',
			'default' => 50,
			'minimum' => 1,
			'maximum' => 100,
		);
		$args['page']     = array(
			'type'    => 'integer',
			'default' => 1,
			'minimum' => 1,
		);

		return $args;
	}

	/** Build sanitized query filters from a REST request. */
	private function filters_from_request( WP_REST_Request $request ): array {
		$filters = array();
		foreach ( array( 'entity_type', 'object_type', 'action', 'action_group', 'actor_type', 'status', 'outcome', 'source_channel', 'correlation_id', 'from', 'to', 'search' ) as $key ) {
			$val = $request[ $key ] ?? '';
			if ( '' !== (string) $val ) {
				$filters[ $key ] = $val;
			}
		}
		foreach ( array( 'entity_id', 'object_id', 'actor_id' ) as $key ) {
			$val = absint( $request[ $key ] ?? 0 );
			if ( $val > 0 ) {
				$filters[ $key ] = $val;
			}
		}

		return $filters;
	}

	/**
	 * Only librarians and administrators may access audit event data.
	 *
	 * Borrowers and guests must not receive audit log responses.
	 */
	public function permission_check(): bool {
		return Capabilities::can_manage_borrowers();
	}
}
