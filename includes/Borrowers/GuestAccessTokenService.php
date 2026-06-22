<?php
/**
 * Service layer for secure guest My Library access tokens.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Borrowers;

use RuntimeException;
use WP_Error;

/**
 * Issues and validates high-entropy hashed guest-access tokens.
 */
final class GuestAccessTokenService {
	private const STATUS_ACTIVE    = 'active';
	private const STATUS_REVOKED   = 'revoked';
	private const MIN_TOKEN_LENGTH = 32;

	/**
	 * Token persistence dependency.
	 *
	 * @var GuestAccessTokenRepository
	 */
	private GuestAccessTokenRepository $tokens;

	/**
	 * Borrower persistence dependency.
	 *
	 * @var BorrowerRepository
	 */
	private BorrowerRepository $borrowers;

	/**
	 * Create service dependencies.
	 *
	 * @param GuestAccessTokenRepository|null $tokens Optional token repository.
	 * @param BorrowerRepository|null         $borrowers Optional borrower repository.
	 */
	public function __construct( ?GuestAccessTokenRepository $tokens = null, ?BorrowerRepository $borrowers = null ) {
		$this->tokens    = $tokens ?? new GuestAccessTokenRepository();
		$this->borrowers = $borrowers ?? new BorrowerRepository();
	}

	/**
	 * Create a new active guest-access token for a borrower.
	 *
	 * The plaintext token is returned exactly once for email/link generation; only
	 * its keyed hash is stored.
	 *
	 * @param int    $borrower_id Borrower ID.
	 * @param string $expires_at MySQL datetime expiry.
	 * @return array{token:string,row:array<string,mixed>}|WP_Error
	 */
	public function create_token( int $borrower_id, string $expires_at ): array|WP_Error {
		$borrower = $this->borrowers->get( $borrower_id );
		if ( null === $borrower || 'active' !== (string) ( $borrower['status'] ?? '' ) ) {
			return new WP_Error( 'connectlibrary_guest_token_borrower_invalid', __( 'Guest access requires an active borrower.', 'connectlibrary' ), array( 'status' => 400 ) );
		}

		$token = $this->new_plaintext_token();
		$row   = array(
			'borrower_id' => $borrower_id,
			'token_hash'  => self::hash_token( $token ),
			'status'      => self::STATUS_ACTIVE,
			'expires_at'  => sanitize_text_field( $expires_at ),
			'created_at'  => current_time( 'mysql' ),
			'created_by'  => $this->current_user_id_or_null(),
			'revoked_at'  => null,
		);

		$id        = $this->tokens->insert( $row );
		$row['id'] = $id;

		return array(
			'token' => $token,
			'row'   => $row,
		);
	}

	/**
	 * Resolve a plaintext guest token to its active borrower, if valid.
	 *
	 * Invalid, expired, revoked, missing-token, and missing-borrower states all
	 * return null so callers can render one privacy-safe message.
	 *
	 * @param string $token Plaintext request token.
	 * @return array<string,mixed>|null
	 */
	public function resolve_borrower( string $token ): ?array {
		$token = trim( $token );
		if ( strlen( $token ) < self::MIN_TOKEN_LENGTH ) {
			return null;
		}

		$row = $this->tokens->find_by_hash( self::hash_token( $token ) );
		if ( null === $row ) {
			return null;
		}
		if ( self::STATUS_ACTIVE !== (string) ( $row['status'] ?? '' ) ) {
			return null;
		}
		if ( $this->is_expired( (string) ( $row['expires_at'] ?? '' ) ) ) {
			return null;
		}

		$borrower = $this->borrowers->get( (int) ( $row['borrower_id'] ?? 0 ) );
		if ( null === $borrower || 'active' !== (string) ( $borrower['status'] ?? '' ) ) {
			return null;
		}

		return $borrower;
	}

	/**
	 * Hash a plaintext guest token for storage/lookup.
	 *
	 * @param string $token Plaintext token.
	 */
	public static function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, self::hash_key() );
	}

	/**
	 * Generate a high-entropy plaintext token.
	 *
	 * @throws RuntimeException When a secure random token cannot be generated.
	 */
	private function new_plaintext_token(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			throw new RuntimeException( 'Unable to generate a secure guest-access token.', 0, $error );
		}
	}

	/**
	 * Whether a MySQL datetime has expired.
	 *
	 * @param string $expires_at Expiry datetime.
	 */
	private function is_expired( string $expires_at ): bool {
		if ( '' === trim( $expires_at ) ) {
			return true;
		}

		return strtotime( $expires_at ) <= strtotime( current_time( 'mysql' ) );
	}

	/** Current user ID or null. */
	private function current_user_id_or_null(): ?int {
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;

		return $user_id > 0 ? $user_id : null;
	}

	/** Return a site-specific key for token hashes. */
	private static function hash_key(): string {
		if ( function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'auth' );
			if ( '' !== $salt ) {
				return $salt;
			}
		}

		if ( defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ) {
			return (string) AUTH_SALT;
		}

		return 'connectlibrary-guest-access-token';
	}
}
