<?php
/**
 * Borrower/member service layer.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Borrowers;

// phpcs:disable WordPress.PHP.YodaConditions.NotYoda

use ConnectLibrary\Support\Capabilities;
use WP_Error;

/**
 * Capability-protected borrower/member operations.
 */
final class BorrowerService {
	private const TYPES    = array( 'wp_user', 'manual', 'guest', 'child' );
	private const STATUSES = array( 'active', 'disabled', 'anonymized', 'merge_needed' );

	/**
	 * Borrower persistence dependency.
	 *
	 * @var BorrowerRepository
	 */
	private BorrowerRepository $repository;

	/**
	 * Create service dependencies.
	 *
	 * @param BorrowerRepository|null $repository Optional repository override.
	 */
	public function __construct( ?BorrowerRepository $repository = null ) {
		$this->repository = $repository ?? new BorrowerRepository();
	}

	/**
	 * Create a borrower.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create( array $data ): array|WP_Error {
		$allowed = $this->require_access();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$row = $this->sanitize_data( $data, true );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$wp_user_valid = $this->validate_wp_user_link( (int) ( $row['wp_user_id'] ?? 0 ) );
		if ( is_wp_error( $wp_user_valid ) ) {
			return $wp_user_valid;
		}
		if ( $row['wp_user_id'] > 0 && $this->active_wp_user_exists( (int) $row['wp_user_id'] ) ) {
			return new WP_Error( 'connectlibrary_borrower_wp_user_exists', __( 'An active borrower already exists for this WordPress user.', 'connectlibrary' ), array( 'status' => 409 ) );
		}
		$guardian_valid = $this->validate_child_guardian( $row );
		if ( is_wp_error( $guardian_valid ) ) {
			return $guardian_valid;
		}

		$now               = current_time( 'mysql' );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$row['created_by'] = $this->current_user_id_or_null();
		$row['updated_by'] = $this->current_user_id_or_null();

		$id = $this->repository->insert( $row );
		$this->repository->audit( $id, 'create', array_keys( $row ) );
		if ( 'child' === $row['borrower_type'] ) {
			$this->repository->audit( $id, 'guardian_link', array( 'guardian' ) );
		}

		return $this->get( $id );
	}

	/**
	 * Update a borrower.
	 *
	 * @param int                 $id Borrower ID.
	 * @param array<string,mixed> $data Input data.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update( int $id, array $data ): array|WP_Error {
		$allowed = $this->require_access();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$current = $this->repository->get( $id );
		if ( null === $current ) {
			return $this->not_found();
		}

		$merged = array_merge( $current, $data );
		$row    = $this->sanitize_data( $merged, false );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$wp_user_valid = $this->validate_wp_user_link( (int) ( $row['wp_user_id'] ?? 0 ) );
		if ( is_wp_error( $wp_user_valid ) ) {
			return $wp_user_valid;
		}
		if ( $row['wp_user_id'] > 0 && (int) $row['wp_user_id'] !== (int) ( $current['wp_user_id'] ?? 0 ) && $this->active_wp_user_exists( (int) $row['wp_user_id'], $id ) ) {
			return new WP_Error( 'connectlibrary_borrower_wp_user_exists', __( 'An active borrower already exists for this WordPress user.', 'connectlibrary' ), array( 'status' => 409 ) );
		}
		$guardian_valid = $this->validate_child_guardian( $row, $id );
		if ( is_wp_error( $guardian_valid ) ) {
			return $guardian_valid;
		}

		$row['updated_at'] = current_time( 'mysql' );
		$row['updated_by'] = $this->current_user_id_or_null();
		unset( $row['id'], $row['created_at'], $row['created_by'] );

		$this->repository->update( $id, $row );
		$this->repository->audit( $id, 'update', array_keys( $data ) );
		if ( $this->has_guardian_link( $current ) && ! $this->has_guardian_link( $row ) ) {
			$this->repository->audit( $id, 'guardian_unlink', array( 'guardian' ) );
		}
		if ( $this->guardian_fields_changed( $data ) ) {
			$this->repository->audit( $id, 'guardian_link', array( 'guardian' ) );
		}

		return $this->get( $id );
	}

	/**
	 * Search borrowers.
	 *
	 * @param array<string,mixed> $args Search/filter args.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function search( array $args = array() ): array|WP_Error {
		$allowed = $this->require_access();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$search = strtolower( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$type   = sanitize_key( (string) ( $args['borrower_type'] ?? '' ) );
		$rows   = array();

		foreach ( $this->repository->all() as $row ) {
			if ( '' !== $status && $status !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			if ( '' !== $type && $type !== (string) ( $row['borrower_type'] ?? '' ) ) {
				continue;
			}
			if ( '' !== $search && ! str_contains( strtolower( implode( ' ', array_map( 'strval', array_intersect_key( $row, array_flip( array( 'display_name', 'preferred_name', 'email', 'phone', 'guardian_name', 'guardian_email', 'guardian_phone', 'guardian_relationship' ) ) ) ) ) ), $search ) ) {
				continue;
			}
			$rows[] = $this->admin_payload( $row );
		}

		return $rows;
	}

	/**
	 * Get one borrower.
	 *
	 * @param int $id Borrower ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get( int $id ): array|WP_Error {
		$allowed = $this->require_access();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$row = $this->repository->get( $id );
		if ( null === $row ) {
			return $this->not_found();
		}

		return $this->admin_payload( $row );
	}

	/**
	 * Set borrower status.
	 *
	 * @param int    $id Borrower ID.
	 * @param string $status New status.
	 * @param string $reason Optional reason.
	 * @return array<string,mixed>|WP_Error
	 */
	public function set_status( int $id, string $status, string $reason = '' ): array|WP_Error {
		$status = sanitize_key( $status );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'connectlibrary_invalid_borrower_status', __( 'Invalid borrower status.', 'connectlibrary' ), array( 'status' => 400 ) );
		}

		$result = $this->update( $id, array( 'status' => $status ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->repository->audit( $id, 'status', array( 'status' ), $reason );

		return $result;
	}

	/**
	 * Export one borrower.
	 *
	 * @param int $id Borrower ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function export( int $id ): array|WP_Error {
		$row = $this->get( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$this->repository->audit( $id, 'export', array( 'borrower' ) );

		return array(
			'borrower' => $row,
			'audit'    => $this->repository->audit_events( $id ),
		);
	}

	/**
	 * Anonymize one borrower while preserving non-identifying audit trail.
	 *
	 * @param int    $id Borrower ID.
	 * @param string $reason Optional reason.
	 * @return array<string,mixed>|WP_Error
	 */
	public function anonymize( int $id, string $reason = '' ): array|WP_Error {
		$allowed = $this->require_access();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( null === $this->repository->get( $id ) ) {
			return $this->not_found();
		}

		$this->repository->update(
			$id,
			array(
				'wp_user_id'            => null,
				'status'                => 'anonymized',
				'display_name'          => '',
				'preferred_name'        => null,
				'email'                 => null,
				'phone'                 => null,
				'guardian_borrower_id'  => null,
				'guardian_name'         => null,
				'guardian_email'        => null,
				'guardian_phone'        => null,
				'guardian_relationship' => null,
				'email_notices_allowed' => 0,
				'private_notes'         => null,
				'anonymized_at'         => current_time( 'mysql' ),
				'anonymized_by'         => $this->current_user_id_or_null(),
				'updated_at'            => current_time( 'mysql' ),
				'updated_by'            => $this->current_user_id_or_null(),
			)
		);
		$this->repository->audit( $id, 'anonymize', array( 'personal_fields' ), $reason );

		return $this->get( $id );
	}

	/**
	 * Return audit events for tests/admin detail.
	 *
	 * @param int $id Borrower ID.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function audit_events( int $id ): array|WP_Error {
		$allowed = $this->require_access();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return $this->repository->audit_events( $id );
	}

	/** Require borrower management access. */
	private function require_access(): true|WP_Error {
		if ( Capabilities::can_manage_borrowers() ) {
			return true;
		}

		return new WP_Error( 'connectlibrary_borrower_forbidden', __( 'You do not have permission to manage borrowers.', 'connectlibrary' ), array( 'status' => 403 ) );
	}

	/**
	 * Sanitize borrower input.
	 *
	 * @param array<string,mixed> $data Input/merged data.
	 * @param bool                $creating Whether creating a record.
	 * @return array<string,mixed>|WP_Error
	 */
	private function sanitize_data( array $data, bool $creating ): array|WP_Error {
		$type = sanitize_key( (string) ( $data['borrower_type'] ?? 'manual' ) );
		$type = in_array( $type, self::TYPES, true ) ? $type : 'manual';
		$name = sanitize_text_field( (string) ( $data['display_name'] ?? '' ) );

		if ( $creating && '' === $name ) {
			return new WP_Error( 'connectlibrary_borrower_name_required', __( 'Borrower name is required.', 'connectlibrary' ), array( 'status' => 400 ) );
		}

		$wp_user_id = absint( $data['wp_user_id'] ?? 0 );
		if ( 'wp_user' !== $type ) {
			$wp_user_id = 0;
		}

		$status = sanitize_key( (string) ( $data['status'] ?? 'active' ) );
		$status = in_array( $status, self::STATUSES, true ) ? $status : 'active';
		$row    = array(
			'borrower_type'         => $type,
			'wp_user_id'            => $wp_user_id > 0 ? $wp_user_id : null,
			'status'                => $status,
			'display_name'          => $name,
			'preferred_name'        => $this->nullable_text( $data['preferred_name'] ?? null ),
			'email'                 => $this->nullable_email( $data['email'] ?? null ),
			'phone'                 => $this->nullable_text( $data['phone'] ?? null ),
			'guardian_borrower_id'  => absint( $data['guardian_borrower_id'] ?? 0 ) > 0 ? absint( $data['guardian_borrower_id'] ?? 0 ) : null,
			'guardian_name'         => $this->nullable_text( $data['guardian_name'] ?? null ),
			'guardian_email'        => $this->nullable_email( $data['guardian_email'] ?? null ),
			'guardian_phone'        => $this->nullable_text( $data['guardian_phone'] ?? null ),
			'guardian_relationship' => $this->nullable_text( $data['guardian_relationship'] ?? null ),
			'email_notices_allowed' => ! empty( $data['email_notices_allowed'] ) ? 1 : 0,
			'private_notes'         => $this->nullable_textarea( $data['private_notes'] ?? null ),
		);

		if ( 'child' === $type && 'active' === $status && ! $this->has_guardian_path( $row ) ) {
			return new WP_Error( 'connectlibrary_child_guardian_required', __( 'Child borrowers require a guardian link or guardian contact snapshot.', 'connectlibrary' ), array( 'status' => 400 ) );
		}

		return $row;
	}

	/**
	 * Validate active child borrower guardian link rules.
	 *
	 * @param array<string,mixed> $row Sanitized borrower row.
	 * @param int                 $id Existing borrower ID for updates.
	 */
	private function validate_child_guardian( array $row, int $id = 0 ): true|WP_Error {
		if ( 'child' !== (string) ( $row['borrower_type'] ?? '' ) || 'active' !== (string) ( $row['status'] ?? '' ) ) {
			return true;
		}

		$guardian_id = (int) ( $row['guardian_borrower_id'] ?? 0 );
		if ( $guardian_id <= 0 ) {
			return true;
		}
		if ( $id > 0 && $guardian_id === $id ) {
			return new WP_Error( 'connectlibrary_child_guardian_self', __( 'A child borrower cannot be their own guardian.', 'connectlibrary' ), array( 'status' => 400 ) );
		}

		$guardian = $this->repository->get( $guardian_id );
		if ( null === $guardian || 'active' !== (string) ( $guardian['status'] ?? '' ) ) {
			return new WP_Error( 'connectlibrary_child_guardian_invalid', __( 'Child borrowers require an active adult guardian.', 'connectlibrary' ), array( 'status' => 400 ) );
		}
		if ( $id > 0 && (int) ( $guardian['guardian_borrower_id'] ?? 0 ) === $id ) {
			return new WP_Error( 'connectlibrary_child_guardian_circular', __( 'Guardian relationships cannot be circular.', 'connectlibrary' ), array( 'status' => 400 ) );
		}
		if ( 'child' === (string) ( $guardian['borrower_type'] ?? '' ) ) {
			return new WP_Error( 'connectlibrary_child_guardian_invalid', __( 'Child borrowers require an active adult guardian.', 'connectlibrary' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Whether a row has a linked guardian or contact snapshot.
	 *
	 * @param array<string,mixed> $row Borrower row.
	 */
	private function has_guardian_path( array $row ): bool {
		return ! empty( $row['guardian_borrower_id'] ) || ! empty( $row['guardian_name'] ) || ! empty( $row['guardian_email'] ) || ! empty( $row['guardian_phone'] );
	}

	/**
	 * Whether a row has a linked guardian borrower.
	 *
	 * @param array<string,mixed> $row Borrower row.
	 */
	private function has_guardian_link( array $row ): bool {
		return ! empty( $row['guardian_borrower_id'] );
	}

	/**
	 * Whether submitted data changed guardian/contact fields.
	 *
	 * @param array<string,mixed> $data Input data.
	 */
	private function guardian_fields_changed( array $data ): bool {
		foreach ( array( 'guardian_borrower_id', 'guardian_name', 'guardian_email', 'guardian_phone', 'guardian_relationship' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check active WP user uniqueness.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @param int $exclude_id Optional borrower ID to exclude.
	 */
	private function active_wp_user_exists( int $wp_user_id, int $exclude_id = 0 ): bool {
		foreach ( $this->repository->all() as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $exclude_id ) {
				continue;
			}
			if ( (int) ( $row['wp_user_id'] ?? 0 ) === $wp_user_id && 'active' === (string) ( $row['status'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate that a linked WordPress user exists when user lookup APIs are available.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 */
	private function validate_wp_user_link( int $wp_user_id ): true|WP_Error {
		if ( $wp_user_id <= 0 ) {
			return true;
		}

		if ( function_exists( 'get_userdata' ) ) {
			return get_userdata( $wp_user_id ) ? true : $this->missing_wp_user_error();
		}

		if ( function_exists( 'get_user_by' ) ) {
			return get_user_by( 'id', $wp_user_id ) ? true : $this->missing_wp_user_error();
		}

		return true;
	}

	/** Missing WordPress user link error. */
	private function missing_wp_user_error(): WP_Error {
		return new WP_Error( 'connectlibrary_borrower_wp_user_missing', __( 'Linked WordPress user does not exist.', 'connectlibrary' ), array( 'status' => 400 ) );
	}

	/**
	 * Build admin-only payload without public token fields.
	 *
	 * @param array<string,mixed> $row Borrower row.
	 * @return array<string,mixed>
	 */
	private function admin_payload( array $row ): array {
		$payload                          = array_intersect_key(
			$row,
			array_flip(
				array(
					'id',
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
					'created_at',
					'updated_at',
					'created_by',
					'updated_by',
					'anonymized_at',
					'anonymized_by',
				)
			)
		);
		$payload['id']                    = (int) ( $payload['id'] ?? 0 );
		$payload['wp_user_id']            = isset( $payload['wp_user_id'] ) ? ( null === $payload['wp_user_id'] ? null : (int) $payload['wp_user_id'] ) : null;
		$payload['guardian_borrower_id']  = isset( $payload['guardian_borrower_id'] ) ? ( null === $payload['guardian_borrower_id'] ? null : (int) $payload['guardian_borrower_id'] ) : null;
		$payload['email_notices_allowed'] = ! empty( $payload['email_notices_allowed'] );

		return $payload;
	}

	/**
	 * Nullable sanitized text field.
	 *
	 * @param mixed $value Raw value.
	 */
	private function nullable_text( mixed $value ): ?string {
		$text = sanitize_text_field( (string) ( $value ?? '' ) );

		return '' === $text ? null : $text;
	}

	/**
	 * Nullable sanitized textarea field.
	 *
	 * @param mixed $value Raw value.
	 */
	private function nullable_textarea( mixed $value ): ?string {
		$text = sanitize_textarea_field( (string) ( $value ?? '' ) );

		return '' === $text ? null : $text;
	}

	/**
	 * Nullable sanitized email field.
	 *
	 * @param mixed $value Raw value.
	 */
	private function nullable_email( mixed $value ): ?string {
		$email = sanitize_email( (string) ( $value ?? '' ) );

		return '' !== $email && is_email( $email ) ? $email : null;
	}

	/** Current user ID or null. */
	private function current_user_id_or_null(): ?int {
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;

		return $user_id > 0 ? $user_id : null;
	}

	/** Not found error. */
	private function not_found(): WP_Error {
		return new WP_Error( 'connectlibrary_borrower_not_found', __( 'Borrower not found.', 'connectlibrary' ), array( 'status' => 404 ) );
	}
}
