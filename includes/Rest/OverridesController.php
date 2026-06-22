<?php
/**
 * Protected safe override REST controller.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Rest;

// phpcs:disable Squiz.Commenting.VariableComment.Missing,Squiz.Commenting.FunctionComment.Missing,Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment.ParamCommentFullStop

use ConnectLibrary\Overrides\SafeOverrideService;
use ConnectLibrary\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes librarian-only override endpoints for admin surfaces.
 */
final class OverridesController {
	private SafeOverrideService $service;

	public function __construct( ?SafeOverrideService $service = null ) {
		$this->service = $service ?? new SafeOverrideService();
	}

	/** Register protected override routes. */
	public function register_routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/overrides/loans/(?P<id>\d+)/due-date',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'due_date' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/overrides/copies/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'copy_status' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/overrides/reservations/(?P<id>\d+)/hold',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'hold' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/overrides/loans/(?P<id>\d+)/correction',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'loan_correction' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/** Permission callback for all override endpoints. */
	public function permission_check(): bool|WP_Error {
		if ( Capabilities::can_manage_circulation() ) {
			return true;
		}

		return new WP_Error( 'connectlibrary_override_forbidden', __( 'You do not have permission to perform librarian overrides.', 'connectlibrary' ), array( 'status' => 403 ) );
	}

	/** @param WP_REST_Request $request REST request. */
	public function due_date( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->override_due_date( absint( $request['id'] ?? 0 ), $this->request_data( $request ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** @param WP_REST_Request $request REST request. */
	public function copy_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->override_copy_status( absint( $request['id'] ?? 0 ), $this->request_data( $request ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** @param WP_REST_Request $request REST request. */
	public function hold( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->override_hold( absint( $request['id'] ?? 0 ), $this->request_data( $request ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** @param WP_REST_Request $request REST request. */
	public function loan_correction( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->override_loan_correction( absint( $request['id'] ?? 0 ), $this->request_data( $request ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Extract sanitized override request fields.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string,mixed>
	 */
	private function request_data( WP_REST_Request $request ): array {
		$data = array();
		foreach ( array( 'new_due_at', 'due_at', 'status', 'operation', 'hold_expires_at', 'expires_at', 'reason', 'source_surface', 'source', 'confirm_override', 'confirmed', 'correlation_id', 'idempotency_key', 'barcode_hash', 'barcode_token' ) as $field ) {
			if ( isset( $request[ $field ] ) ) {
				$data[ $field ] = is_scalar( $request[ $field ] ) ? sanitize_text_field( (string) $request[ $field ] ) : '';
			}
		}

		return $data;
	}
}
