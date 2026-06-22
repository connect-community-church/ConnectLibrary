<?php
/**
 * Service layer for borrower library-card generation and lifecycle.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Borrowers;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.VariableComment.Missing,Squiz.Commenting.FunctionComment.ParamCommentFullStop,Squiz.Commenting.FunctionComment.MissingParamTag

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Cards\BorrowerCardRenderer;
use RuntimeException;
use WP_Error;

/**
 * Issues privacy-safe opaque card tokens and manages reprint/replace/disable flows.
 */
final class BorrowerCardService {
	public const STATUS_ACTIVE        = 'active';
	public const STATUS_DISABLED      = 'disabled';
	public const STATUS_REPLACED      = 'replaced';
	public const STATUS_REPLACED_LOST = 'replaced_lost';

	private BorrowerCardRepository $cards;
	private BorrowerRepository $borrowers;
	private AuditEventService $audit;
	private BorrowerCardRenderer $renderer;

	public function __construct( ?BorrowerCardRepository $cards = null, ?BorrowerRepository $borrowers = null, ?AuditEventService $audit = null, ?BorrowerCardRenderer $renderer = null ) {
		$this->cards     = $cards ?? new BorrowerCardRepository();
		$this->borrowers = $borrowers ?? new BorrowerRepository();
		$this->audit     = $audit ?? new AuditEventService();
		$this->renderer  = $renderer ?? new BorrowerCardRenderer();
	}

	/** @return array{token:string,row:array<string,mixed>}|WP_Error */
	public function generate_first_card( int $borrower_id ): array|WP_Error {
		if ( null !== $this->cards->active_for_borrower( $borrower_id ) ) {
			return new WP_Error( 'connectlibrary_card_already_active', __( 'This borrower already has an active library card. Reprint the existing card or replace it if lost.', 'connectlibrary' ), array( 'status' => 409 ) );
		}
		return $this->issue_card( $borrower_id, 'generated', 0 );
	}

	/** @return array<string,mixed>|WP_Error */
	public function active_card( int $borrower_id ): array|WP_Error {
		$borrower = $this->active_borrower_or_error( $borrower_id );
		if ( is_wp_error( $borrower ) ) {
			return $borrower;
		}
		$card = $this->cards->active_for_borrower( $borrower_id );
		if ( null === $card ) {
			return new WP_Error( 'connectlibrary_card_missing', __( 'This borrower does not have an active card yet.', 'connectlibrary' ), array( 'status' => 404 ) );
		}
		return $card;
	}

	/** @return array<string,mixed>|WP_Error */
	public function reprint_active_card( int $borrower_id ): array|WP_Error {
		$card = $this->active_card( $borrower_id );
		if ( is_wp_error( $card ) ) {
			return $card;
		}
		$this->audit_card_event( 'card_reprinted', $borrower_id, (int) ( $card['id'] ?? 0 ), 'Library card reprinted without token rotation.' );
		return $card;
	}

	/** @return array{token:string,row:array<string,mixed>}|WP_Error */
	public function replace_lost_card( int $borrower_id, string $reason = 'Lost card', string $note = '' ): array|WP_Error {
		$active = $this->active_card( $borrower_id );
		if ( is_wp_error( $active ) ) {
			return $active;
		}

		$reason         = $this->sanitize_reason( $reason );
		$note           = $this->sanitize_note( $note );
		$correlation_id = $this->new_correlation_id();
		$old_card_id    = (int) ( $active['id'] ?? 0 );
		$this->cards->begin_transaction();

		$issued = $this->issue_card(
			$borrower_id,
			'replaced_lost',
			$old_card_id,
			array(
				'replacement_reason'   => $reason,
				'replacement_note'     => $note,
				'audit_correlation_id' => $correlation_id,
			),
			false
		);
		if ( is_wp_error( $issued ) ) {
			$this->cards->rollback();
			return $issued;
		}

		$new_card_id = (int) ( $issued['row']['id'] ?? 0 );
		$now         = current_time( 'mysql' );
		$updated     = $this->cards->update(
			$old_card_id,
			array(
				'status'                => self::STATUS_REPLACED_LOST,
				'replaced_at'           => $now,
				'updated_at'            => $now,
				'updated_by'            => $this->current_user_id_or_null(),
				'replacement_reason'    => $reason,
				'replacement_note'      => '' !== $note ? $note : null,
				'superseded_by_card_id' => $new_card_id,
				'audit_correlation_id'  => $correlation_id,
			)
		);

		if ( ! $updated ) {
			$this->cards->rollback();
			return new WP_Error( 'connectlibrary_card_replace_failed', __( 'Unable to retire the lost card. The existing active card was left unchanged.', 'connectlibrary' ), array( 'status' => 500 ) );
		}

		$this->cards->commit();
		$issued['row']['audit_correlation_id'] = $correlation_id;
		$this->audit_card_event(
			'card_replaced_lost',
			$borrower_id,
			$old_card_id,
			'Lost library card replaced; previous card stopped immediately.',
			array(
				'reason'                => $reason,
				'note_present'          => '' !== $note,
				'superseded_by_card_id' => $new_card_id,
			),
			$correlation_id,
			$reason
		);
		$this->audit_card_event( 'card_replacement_issued', $borrower_id, $new_card_id, 'Replacement library card issued.', array( 'replaces_card_id' => $old_card_id ), $correlation_id, $reason );
		return $issued;
	}

	/** @return array<string,mixed>|WP_Error */
	public function disable_active_card( int $borrower_id ): array|WP_Error {
		$card = $this->active_card( $borrower_id );
		if ( is_wp_error( $card ) ) {
			return $card;
		}
		$this->cards->update(
			(int) ( $card['id'] ?? 0 ),
			array(
				'status'      => self::STATUS_DISABLED,
				'disabled_at' => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
				'updated_by'  => $this->current_user_id_or_null(),
			)
		);
		$card['status'] = self::STATUS_DISABLED;
		$this->audit_card_event( 'card_disabled', $borrower_id, (int) ( $card['id'] ?? 0 ), 'Library card disabled.' );
		return $card;
	}

	/** @return array<string,mixed>|WP_Error */
	public function resolve_card_token( string $scanned_payload ): array|WP_Error {
		$token = $this->extract_token( $scanned_payload );
		if ( '' === $token ) {
			return new WP_Error( 'connectlibrary_card_malformed', __( 'That library card code is not valid.', 'connectlibrary' ) );
		}
		$matches = $this->cards->find_all_by_hash( self::hash_token( $token ) );
		if ( count( $matches ) > 1 ) {
			return new WP_Error( 'connectlibrary_card_duplicate', __( 'This card record needs librarian review before it can be used.', 'connectlibrary' ) );
		}
		$card = $matches[0] ?? null;
		if ( null === $card ) {
			return new WP_Error( 'connectlibrary_card_not_found', __( 'Library card not found. Verify the card or look up the borrower by name.', 'connectlibrary' ) );
		}
		if ( self::STATUS_ACTIVE !== (string) ( $card['status'] ?? '' ) ) {
			$this->audit_inactive_scan( $card );
			return new WP_Error( 'connectlibrary_card_inactive', __( 'This library card is inactive. Please ask a librarian to verify the current card.', 'connectlibrary' ), array( 'status' => 410 ) );
		}
		$borrower = $this->borrowers->get( (int) ( $card['borrower_id'] ?? 0 ) );
		if ( null === $borrower || 'active' !== (string) ( $borrower['status'] ?? '' ) ) {
			return new WP_Error( 'connectlibrary_card_borrower_inactive', __( 'This borrower account is not active. Please look up the borrower by name and review the account before circulation.', 'connectlibrary' ) );
		}
		return $card;
	}

	/** @param array<string,mixed> $card @return string|WP_Error */
	public function render_single_card( array $card ): string|WP_Error {
		$borrower = $this->borrowers->get( (int) ( $card['borrower_id'] ?? 0 ) );
		if ( null === $borrower ) {
			return new WP_Error( 'connectlibrary_card_borrower_missing', __( 'Borrower record for this card was not found.', 'connectlibrary' ) );
		}
		return $this->renderer->render_card_html( $borrower, $card );
	}

	/** @return string */
	public function render_sheet_for_active_cards(): string {
		$items = array();
		foreach ( $this->cards->all() as $card ) {
			if ( self::STATUS_ACTIVE !== (string) ( $card['status'] ?? '' ) ) {
				continue;
			}
			$borrower = $this->borrowers->get( (int) ( $card['borrower_id'] ?? 0 ) );
			if ( null !== $borrower && 'active' === (string) ( $borrower['status'] ?? '' ) ) {
				$items[] = array(
					'borrower' => $borrower,
					'card'     => $card,
				);
			}
		}
		return $this->renderer->render_sheet_html( $items );
	}

	/** @return array<int,array<string,mixed>> */
	public function cards_for_borrower( int $borrower_id ): array {
		return $this->cards->for_borrower( $borrower_id );
	}

	public static function hash_token( string $token ): string {
		return hash_hmac( 'sha256', trim( $token ), self::hash_key() );
	}

	public static function payload_for_token( string $token ): string {
		return 'CLCARD-' . trim( $token );
	}

	public function extract_token( string $payload ): string {
		$value = trim( $payload );
		if ( 1 === preg_match( '/^CLCARD[-:]([A-Za-z0-9]{32,})$/', $value, $matches ) ) {
			return $matches[1];
		}
		if ( 1 === preg_match( '/^[A-Za-z0-9]{32,}$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Issue a new active borrower card.
	 *
	 * @param int                 $borrower_id      Borrower ID.
	 * @param string              $event            Audit event suffix.
	 * @param int                 $replaces_card_id Replaced card ID, if any.
	 * @param array<string,mixed> $metadata         Optional card metadata.
	 * @param bool                $audit            Whether to audit immediately.
	 * @return array{token:string,row:array<string,mixed>}|WP_Error
	 */
	private function issue_card( int $borrower_id, string $event, int $replaces_card_id, array $metadata = array(), bool $audit = true ): array|WP_Error {
		$borrower = $this->active_borrower_or_error( $borrower_id );
		if ( is_wp_error( $borrower ) ) {
			return $borrower;
		}
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$token = $this->new_plaintext_token();
			$hash  = self::hash_token( $token );
			if ( array() !== $this->cards->find_all_by_hash( $hash ) ) {
				continue;
			}
			$now = current_time( 'mysql' );
			$row = array(
				'borrower_id'           => $borrower_id,
				'token_hash'            => $hash,
				'payload'               => self::payload_for_token( $token ),
				'card_label'            => 'CL-' . str_pad( (string) $borrower_id, 6, '0', STR_PAD_LEFT ),
				'status'                => self::STATUS_ACTIVE,
				'replaces_card_id'      => $replaces_card_id > 0 ? $replaces_card_id : null,
				'superseded_by_card_id' => null,
				'replacement_reason'    => $metadata['replacement_reason'] ?? null,
				'replacement_note'      => $metadata['replacement_note'] ?? null,
				'audit_correlation_id'  => $metadata['audit_correlation_id'] ?? null,
				'created_at'            => $now,
				'created_by'            => $this->current_user_id_or_null(),
				'updated_at'            => $now,
				'updated_by'            => $this->current_user_id_or_null(),
				'disabled_at'           => null,
				'replaced_at'           => null,
			);
			$id  = $this->cards->insert( $row );
			if ( $id <= 0 ) {
				return new WP_Error( 'connectlibrary_card_insert_failed', __( 'Unable to save the new library card. The existing active card was left unchanged.', 'connectlibrary' ), array( 'status' => 500 ) );
			}
			$row['id'] = $id;
			if ( $audit ) {
				$this->audit_card_event( 'card_' . $event, $borrower_id, (int) $row['id'], 'Library card ' . $event . '.' );
			}
			return array(
				'token' => $token,
				'row'   => $row,
			);
		}
		return new WP_Error( 'connectlibrary_card_token_collision', __( 'Unable to generate a unique card token. Please try again.', 'connectlibrary' ), array( 'status' => 500 ) );
	}

	/** @return array<string,mixed>|WP_Error */
	private function active_borrower_or_error( int $borrower_id ): array|WP_Error {
		$borrower = $this->borrowers->get( $borrower_id );
		if ( null === $borrower || 'active' !== (string) ( $borrower['status'] ?? '' ) ) {
			return new WP_Error( 'connectlibrary_card_borrower_invalid', __( 'Library cards require an active borrower.', 'connectlibrary' ), array( 'status' => 400 ) );
		}
		return $borrower;
	}

	/**
	 * Log a card lifecycle audit event.
	 *
	 * @param string              $action         Audit action.
	 * @param int                 $borrower_id    Borrower ID.
	 * @param int                 $card_id        Card ID.
	 * @param string              $summary        Human-readable summary.
	 * @param array<string,mixed> $context        Additional safe context.
	 * @param string              $correlation_id Optional workflow correlation ID.
	 * @param string              $reason         Optional reason.
	 */
	private function audit_card_event( string $action, int $borrower_id, int $card_id, string $summary, array $context = array(), string $correlation_id = '', string $reason = '' ): void {
		$context = array_merge( array( 'card_id' => $card_id ), $context );
		$this->audit->log(
			$action,
			array(
				'source_channel'        => 'admin',
				'entity_type'           => 'borrower',
				'entity_id'             => $borrower_id,
				'secondary_entity_type' => 'borrower_card',
				'secondary_entity_id'   => $card_id,
				'context'               => $context,
				'summary'               => $summary,
				'correlation_id'        => $correlation_id,
				'reason'                => $reason,
			)
		);
	}

	/** @param array<string,mixed> $card */
	private function audit_inactive_scan( array $card ): void {
		$this->audit->log(
			'card_inactive_scan',
			array(
				'source_channel'        => 'admin',
				'entity_type'           => 'borrower_card',
				'entity_id'             => (int) ( $card['id'] ?? 0 ),
				'secondary_entity_type' => 'borrower_card',
				'secondary_entity_id'   => (int) ( $card['id'] ?? 0 ),
				'context'               => array(
					'card_id' => (int) ( $card['id'] ?? 0 ),
					'status'  => (string) ( $card['status'] ?? '' ),
				),
				'summary'               => 'Inactive library card scan blocked with privacy-safe response.',
			)
		);
	}

	private function sanitize_reason( string $reason ): string {
		$reason = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $reason ) : trim( wp_strip_all_tags( $reason ) );
		return '' !== $reason ? substr( $reason, 0, 100 ) : 'Lost card';
	}

	private function sanitize_note( string $note ): string {
		$note = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $note ) : trim( wp_strip_all_tags( $note ) );
		return substr( $note, 0, 1000 );
	}

	private function new_correlation_id(): string {
		try {
			$bytes = random_bytes( 16 );
			$hex   = bin2hex( $bytes );
			return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
		} catch ( \Throwable ) {
			return uniqid( 'clcard-', true );
		}
	}

	private function new_plaintext_token(): string {
		try {
			return bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable $error ) {
			throw new RuntimeException( 'Unable to generate a secure library-card token.', 0, $error );
		}
	}

	private function current_user_id_or_null(): ?int {
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		return $user_id > 0 ? $user_id : null;
	}

	private static function hash_key(): string {
		if ( function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'auth' );
			if ( '' !== $salt ) {
				return $salt;
			}
		}
		return defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ? (string) AUTH_SALT : 'connectlibrary-borrower-card-token';
	}
}
