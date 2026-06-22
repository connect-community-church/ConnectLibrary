<?php
/**
 * Protected borrower REST controller.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Rest;

use ConnectLibrary\Borrowers\BorrowerService;
use ConnectLibrary\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles librarian-only borrower/member endpoints.
 */
final class BorrowersController {
	/**
	 * Borrower service dependency.
	 *
	 * @var BorrowerService
	 */
	private BorrowerService $service;

	/**
	 * Create controller dependencies.
	 *
	 * @param BorrowerService|null $service Optional service override.
	 */
	public function __construct( ?BorrowerService $service = null ) {
		$this->service = $service ?? new BorrowerService();
	}

	/** Register protected borrower routes. */
	public function register_routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/borrowers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/borrowers',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/borrowers/(?P<id>\d+)',
			array(
				'methods'             => 'GET, PATCH, POST',
				'callback'            => array( $this, 'get_or_update_item' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/borrowers/(?P<id>\d+)/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'export_item' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/borrowers/(?P<id>\d+)/anonymize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'anonymize_item' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/borrowers/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'status_item' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/** Permission callback for borrower endpoints. */
	public function permission_check(): bool|WP_Error {
		if ( Capabilities::can_manage_borrowers() ) {
			return true;
		}

		return new WP_Error( 'connectlibrary_borrower_forbidden', __( 'You do not have permission to manage borrowers.', 'connectlibrary' ), array( 'status' => 403 ) );
	}

	/**
	 * List borrowers.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->search(
			array(
				'search'        => $request['search'] ?? '',
				'status'        => $request['status'] ?? '',
				'borrower_type' => $request['borrower_type'] ?? '',
			)
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Create borrower.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->create( $this->request_data( $request ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Get or update based on method when WordPress dispatches combined route.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function get_or_update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$method = strtoupper( $request->get_method() );

		if ( in_array( $method, array( 'PATCH', 'POST' ), true ) ) {
			return $this->update_item( $request );
		}

		return $this->get_item( $request );
	}

	/**
	 * Editable borrower fields accepted by create/update requests.
	 *
	 * @return array<int,string>
	 */
	private function editable_fields(): array {
		return array(
			'borrower_type',
			'wp_user_id',
			'status',
			'display_name',
			'preferred_name',
			'email',
			'phone',
			'guardian_borrower_id',
			'guardian_name',
			'guardian_email',
			'guardian_phone',
			'guardian_relationship',
			'email_notices_allowed',
			'private_notes',
		);
	}

	/**
	 * Get borrower.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->get( absint( $request['id'] ?? 0 ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Update borrower.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->update( absint( $request['id'] ?? 0 ), $this->request_data( $request ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	/**
	 * Export borrower.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function export_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->export( absint( $request['id'] ?? 0 ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Anonymize borrower.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function anonymize_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->anonymize( absint( $request['id'] ?? 0 ), sanitize_text_field( (string) ( $request['reason'] ?? '' ) ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Set borrower status.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function status_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->set_status(
			absint( $request['id'] ?? 0 ),
			sanitize_key( (string) ( $request['status'] ?? '' ) ),
			sanitize_text_field( (string) ( $request['reason'] ?? '' ) )
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Extract allowed borrower fields from the request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string,mixed>
	 */
	private function request_data( WP_REST_Request $request ): array {
		$data = array();
		foreach ( $this->editable_fields() as $field ) {
			if ( $request->offsetExists( $field ) ) {
				$data[ $field ] = $request[ $field ];
			}
		}

		return $data;
	}
}
