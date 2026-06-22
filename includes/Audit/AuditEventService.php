<?php
/**
 * Shared audit event service for Phase 2 workflows.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Audit;

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

/**
 * Creates and queries shared append-only audit events.
 *
 * Corrections and overrides create new linked events via correlation_id;
 * original events are never mutated or deleted through this service.
 */
final class AuditEventService {

	/**
	 * Audit event repository.
	 *
	 * @var AuditEventRepository
	 */
	private AuditEventRepository $repository;

	/**
	 * Lowercase key substrings that trigger redaction in context/before/after payloads.
	 *
	 * Covers: guest secure-link tokens, nonces, passwords, API keys, cookies,
	 * card tokens, and password-reset links per the privacy spec.
	 *
	 * @var string[]
	 */
	private const REDACTED_KEY_SUBSTRINGS = array(
		'token',
		'nonce',
		'password',
		'secret',
		'api_key',
		'cookie',
		'reset_link',
		'reset_key',
		'auth_key',
		'auth_token',
		'card_token',
		'access_token',
	);

	/**
	 * Constructor.
	 *
	 * @param AuditEventRepository|null $repository Optional override for testing.
	 */
	public function __construct( ?AuditEventRepository $repository = null ) {
		$this->repository = $repository ?? new AuditEventRepository();
	}

	/**
	 * Log an append-only audit event.
	 *
	 * All context/before/after arrays are redacted before storage.
	 * Corrections link to an original event via the same correlation_id.
	 *
	 * Accepted $params keys:
	 *   actor_type            string  'user'|'system'|'cron'|'guest'  (inferred when absent)
	 *   actor_id              int     WordPress user ID; 0 = system/guest
	 *   source_channel        string  'admin'|'rest'|'cron'|'frontend'|'cli' (default: 'system')
	 *   entity_type           string  Primary entity: 'loan'|'reservation'|'copy'|'borrower'
	 *   entity_id             int     Primary entity ID
	 *   secondary_entity_type string  Secondary entity type
	 *   secondary_entity_id   int     Secondary entity ID
	 *   context               array   Structured context data (redacted before storage)
	 *   before                array   Snapshot before change (redacted)
	 *   after                 array   Snapshot after change (redacted)
	 *   status                string  'ok'|'failed'|'skipped' (default: 'ok')
	 *   reason                string  Human-readable reason/note (max 500 chars)
	 *   error_code            string  Sanitized error code for failed events
	 *   error_message         string  Sanitized error message (max 500 chars)
	 *   summary               string  Human-readable summary (max 500 chars)
	 *   correlation_id        string  Groups related events in the same workflow
	 *   action_group          string  Normalized UI/reporting group stored in safe context
	 *   safe_label            string  Privacy-safe target label stored in safe context
	 *
	 * @param string              $action Action type key (e.g. 'checkout', 'return').
	 * @param array<string,mixed> $params Optional event parameters.
	 * @return int Inserted audit event row ID, or 0 on failure.
	 */
	public function log( string $action, array $params = array() ): int {
		$actor_id   = (int) ( $params['actor_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
		$actor_type = (string) ( $params['actor_type'] ?? ( $actor_id > 0 ? 'user' : 'system' ) );

		$context = isset( $params['context'] ) && is_array( $params['context'] )
			? $this->redact( $params['context'] )
			: null;
		$before  = isset( $params['before'] ) && is_array( $params['before'] )
			? $this->redact( $params['before'] )
			: null;
		$after   = isset( $params['after'] ) && is_array( $params['after'] )
			? $this->redact( $params['after'] )
			: null;

		$entity_type  = sanitize_key( (string) ( $params['entity_type'] ?? '' ) );
		$sec_ent_type = sanitize_key( (string) ( $params['secondary_entity_type'] ?? '' ) );
		$entity_id    = (int) ( $params['entity_id'] ?? 0 );
		$sec_ent_id   = (int) ( $params['secondary_entity_id'] ?? 0 );
		$correlation  = (string) ( $params['correlation_id'] ?? '' );
		$reason       = (string) ( $params['reason'] ?? '' );
		$error_code   = (string) ( $params['error_code'] ?? '' );
		$error_msg    = (string) ( $params['error_message'] ?? '' );
		$summary      = (string) ( $params['summary'] ?? '' );
		$action_group = sanitize_key( strtolower( (string) ( $params['action_group'] ?? $context['action_group'] ?? '' ) ) );
		$safe_label   = sanitize_text_field( substr( (string) ( $params['safe_label'] ?? $context['safe_label'] ?? '' ), 0, 255 ) );

		if ( '' !== $action_group || '' !== $safe_label ) {
			$context = is_array( $context ) ? $context : array();
			if ( '' !== $action_group ) {
				$context['action_group'] = $action_group;
			}
			if ( '' !== $safe_label ) {
				$context['safe_label'] = $safe_label;
			}
		}

		$row = array(
			'action'                => sanitize_key( $action ),
			'actor_type'            => sanitize_key( $actor_type ),
			'actor_id'              => $actor_id > 0 ? $actor_id : null,
			'source_channel'        => sanitize_key( (string) ( $params['source_channel'] ?? 'system' ) ),
			'entity_type'           => '' !== $entity_type ? $entity_type : null,
			'entity_id'             => $entity_id > 0 ? $entity_id : null,
			'secondary_entity_type' => '' !== $sec_ent_type ? $sec_ent_type : null,
			'secondary_entity_id'   => $sec_ent_id > 0 ? $sec_ent_id : null,
			'context_json'          => null !== $context ? wp_json_encode( $context ) : null,
			'before_json'           => null !== $before ? wp_json_encode( $before ) : null,
			'after_json'            => null !== $after ? wp_json_encode( $after ) : null,
			'status'                => sanitize_key( (string) ( $params['status'] ?? 'ok' ) ),
			'reason'                => '' !== $reason ? sanitize_text_field( substr( $reason, 0, 500 ) ) : null,
			'error_code'            => '' !== $error_code ? sanitize_key( substr( $error_code, 0, 100 ) ) : null,
			'error_message'         => '' !== $error_msg ? sanitize_text_field( substr( $error_msg, 0, 500 ) ) : null,
			'summary'               => '' !== $summary ? sanitize_text_field( substr( $summary, 0, 500 ) ) : null,
			'correlation_id'        => '' !== $correlation ? $correlation : null,
			'created_at_utc'        => gmdate( 'Y-m-d H:i:s' ),
		);

		return $this->repository->insert( $row );
	}

	/**
	 * Recursively redact sensitive keys from a data array.
	 *
	 * Keys whose lowercase form contains any substring from REDACTED_KEY_SUBSTRINGS
	 * are replaced with '[redacted]'. Nested arrays are processed recursively.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return array<string,mixed> Redacted copy of the data.
	 */
	public function redact( array $data ): array {
		$result = array();

		foreach ( $data as $k => $v ) {
			$key_lower    = strtolower( (string) $k );
			$is_sensitive = false;

			foreach ( self::REDACTED_KEY_SUBSTRINGS as $pattern ) {
				if ( str_contains( $key_lower, $pattern ) ) {
					$is_sensitive = true;
					break;
				}
			}

			if ( $is_sensitive ) {
				$result[ $k ] = '[redacted]';
			} elseif ( is_array( $v ) ) {
				$result[ $k ] = $this->redact( $v );
			} else {
				$result[ $k ] = $v;
			}
		}

		return $result;
	}

	/**
	 * Query audit events with optional filters.
	 *
	 * Callers must enforce capability checks before exposing results.
	 *
	 * @param array<string,mixed> $filters  Column-value pairs and safe text/context filters.
	 * @param int                 $limit    Maximum rows to return.
	 * @param int                 $offset   Pagination offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function query( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
		return $this->repository->query( $filters, $limit, $offset );
	}

	/**
	 * Find one audit event by ID.
	 *
	 * @param int $id Event ID.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		return $this->repository->find( $id );
	}

	/**
	 * Format an event row for safe REST/admin display.
	 *
	 * @param array<string,mixed> $row Raw stored row.
	 * @return array<string,mixed> Redacted display-safe row.
	 */
	public function format_safe_event( array $row ): array {
		$context = $this->decode_json_array( $row['context_json'] ?? null );
		$before  = $this->decode_json_array( $row['before_json'] ?? null );
		$after   = $this->decode_json_array( $row['after_json'] ?? null );

		$context      = $this->redact( $context );
		$before       = $this->redact( $before );
		$after        = $this->redact( $after );
		$action       = (string) ( $row['action'] ?? '' );
		$entity_type  = (string) ( $row['entity_type'] ?? '' );
		$entity_id    = (int) ( $row['entity_id'] ?? 0 );
		$action_group = sanitize_key( (string) ( $context['action_group'] ?? $this->infer_action_group( $action ) ) );
		$safe_label   = sanitize_text_field( (string) ( $context['safe_label'] ?? '' ) );
		if ( '' === $safe_label ) {
			$safe_label = $this->fallback_safe_label( $entity_type, $entity_id, $action );
		}

		$privacy_state = sanitize_key( (string) ( $context['privacy_state'] ?? $context['borrower_state'] ?? '' ) );
		if ( '' === $privacy_state ) {
			$privacy_state = $this->infer_privacy_state( $context, $before, $after );
		}

		return array(
			'id'             => (int) ( $row['id'] ?? 0 ),
			'created_at_utc' => (string) ( $row['created_at_utc'] ?? '' ),
			'action'         => $action,
			'action_group'   => $action_group,
			'actor_type'     => (string) ( $row['actor_type'] ?? '' ),
			'actor_id'       => isset( $row['actor_id'] ) ? (int) $row['actor_id'] : null,
			'actor_label'    => $this->actor_label( $row ),
			'source_channel' => (string) ( $row['source_channel'] ?? '' ),
			'entity_type'    => $entity_type,
			'entity_id'      => $entity_id > 0 ? $entity_id : null,
			'safe_label'     => $safe_label,
			'status'         => (string) ( $row['status'] ?? '' ),
			'outcome'        => (string) ( $row['status'] ?? '' ),
			'summary'        => (string) ( $row['summary'] ?? '' ),
			'reason'         => (string) ( $row['reason'] ?? '' ),
			'error_code'     => (string) ( $row['error_code'] ?? '' ),
			'error_message'  => (string) ( $row['error_message'] ?? '' ),
			'correlation_id' => (string) ( $row['correlation_id'] ?? '' ),
			'context'        => $context,
			'before'         => $before,
			'after'          => $after,
			'privacy_state'  => $privacy_state,
			'has_override'   => str_contains( $action, 'override' ) || ! empty( $context['override'] ),
			'has_correction' => str_contains( $action, 'correction' ) || 'void' === $action || ! empty( $context['correction'] ),
			'report_export'  => 'report_export' === $action || 'report' === $entity_type,
		);
	}

	/** Decode a stored JSON object into an array. */
	private function decode_json_array( mixed $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Build a compact actor label without resolving private user data. */
	private function actor_label( array $row ): string {
		$actor_type = (string) ( $row['actor_type'] ?? 'system' );
		$actor_id   = (int) ( $row['actor_id'] ?? 0 );
		return $actor_id > 0 ? sprintf( '%s #%d', $actor_type, $actor_id ) : $actor_type;
	}

	/** Infer broad action group for older rows. */
	private function infer_action_group( string $action ): string {
		if ( str_contains( $action, 'report' ) ) {
			return 'reports';
		}
		if ( str_contains( $action, 'card' ) || str_contains( $action, 'borrower' ) ) {
			return 'borrowers';
		}
		if ( str_contains( $action, 'reservation' ) || str_contains( $action, 'waitlist' ) || str_contains( $action, 'hold' ) ) {
			return 'reservations';
		}
		if ( str_contains( $action, 'settings' ) ) {
			return 'settings';
		}
		return 'circulation';
	}

	/** Build a safe fallback target label. */
	private function fallback_safe_label( string $entity_type, int $entity_id, string $action ): string {
		if ( '' !== $entity_type && $entity_id > 0 ) {
			return sprintf( '%s #%d', $entity_type, $entity_id );
		}
		return '' !== $action ? $action : __( 'Audit event', 'connectlibrary' );
	}

	/** Infer anonymized/deleted borrower privacy states from safe fields only. */
	private function infer_privacy_state( array $context, array $before, array $after ): string {
		foreach ( array( $context, $before, $after ) as $payload ) {
			$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
			if ( in_array( $status, array( 'deleted', 'anonymized' ), true ) ) {
				return $status;
			}
			if ( ! empty( $payload['anonymized_at'] ) ) {
				return 'anonymized';
			}
		}
		return 'standard';
	}

	/**
	 * Generate a random UUID v4 for grouping related audit events.
	 *
	 * @return string UUID v4 string.
	 */
	public function new_correlation_id(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );

		return vsprintf(
			'%s%s-%s-%s-%s-%s%s%s',
			str_split( bin2hex( $data ), 4 )
		);
	}
}
