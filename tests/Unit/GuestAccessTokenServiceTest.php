<?php
/**
 * Tests for secure My Library guest-access token service.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Borrowers\BorrowerService;
use ConnectLibrary\Borrowers\GuestAccessTokenService;
use ConnectLibrary\Database\Schema;
use ConnectLibrary\Support\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies hashed storage and validation states for guest-access tokens.
 */
final class GuestAccessTokenServiceTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['connectlibrary_test_db_tables']        = array();
		$GLOBALS['connectlibrary_test_current_user_id']  = 0;
		$GLOBALS['connectlibrary_test_current_user_can'] = array(
			Capabilities::MANAGE_BORROWERS => true,
			Capabilities::MANAGE_OPTIONS   => false,
		);
	}

	public function test_create_token_stores_only_hash_and_resolves_active_borrower(): void {
		$borrower = $this->create_manual_borrower( 'Manual Guest', 'manual@example.test', 'secret note' );
		$service  = new GuestAccessTokenService();

		$created = $service->create_token( (int) $borrower['id'], '2026-06-20 12:00:00' );

		self::assertIsArray( $created );
		self::assertArrayHasKey( 'token', $created );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $created['token'] );
		self::assertSame( 64, strlen( $created['token'] ) );

		$rows = $this->guest_token_rows();
		self::assertCount( 1, $rows );
		self::assertSame( GuestAccessTokenService::hash_token( $created['token'] ), $rows[0]['token_hash'] );
		self::assertNotSame( $created['token'], $rows[0]['token_hash'] );
		self::assertStringNotContainsString( $created['token'], wp_json_encode( $rows ) );

		$resolved = $service->resolve_borrower( $created['token'] );

		self::assertIsArray( $resolved );
		self::assertSame( 'Manual Guest', $resolved['display_name'] );
	}

	/**
	 * Invalid guest-token states do not resolve a borrower.
	 *
	 * @param string $state Token failure state.
	 *
	 * @dataProvider invalid_token_state_provider
	 */
	public function test_resolve_borrower_returns_null_for_invalid_states( string $state ): void {
		$borrower    = $this->create_manual_borrower( 'Token State Guest', 'state@example.test', 'state private note' );
		$token       = 'state-token-abcdefghijklmnopqrstuvwxyz123456';
		$status      = 'revoked' === $state ? 'revoked' : 'active';
		$expires     = 'expired' === $state ? '2026-06-18 12:00:00' : '2026-06-20 12:00:00';
		$borrower_id = 'missing_borrower' === $state ? 9999 : (int) $borrower['id'];

		if ( 'invalid' !== $state ) {
			$this->create_guest_access_row( $borrower_id, $token, $status, $expires );
		}
		if ( 'disabled_borrower' === $state ) {
			$result = ( new BorrowerService() )->set_status( (int) $borrower['id'], 'disabled', 'test disabled borrower token path' );
			self::assertIsArray( $result );
		}

		self::assertNull( ( new GuestAccessTokenService() )->resolve_borrower( $token ) );
	}

	/**
	 * Return invalid guest-token states.
	 *
	 * @return array<string,array{state:string}>
	 */
	public static function invalid_token_state_provider(): array {
		return array(
			'invalid'           => array( 'state' => 'invalid' ),
			'expired'           => array( 'state' => 'expired' ),
			'revoked'           => array( 'state' => 'revoked' ),
			'missing_borrower'  => array( 'state' => 'missing_borrower' ),
			'disabled_borrower' => array( 'state' => 'disabled_borrower' ),
		);
	}

	/**
	 * Create a manual borrower for token service tests.
	 *
	 * @param string $name Borrower display name.
	 * @param string $email Borrower email.
	 * @param string $private_notes Private notes.
	 * @return array<string,mixed>
	 */
	private function create_manual_borrower( string $name, string $email, string $private_notes ): array {
		$borrower = ( new BorrowerService() )->create(
			array(
				'borrower_type' => 'manual',
				'display_name'  => $name,
				'email'         => $email,
				'private_notes' => $private_notes,
			)
		);

		self::assertIsArray( $borrower );
		return $borrower;
	}

	private function create_guest_access_row( int $borrower_id, string $token, string $status, string $expires_at ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->insert(
			$tables['guest_access_tokens'],
			array(
				'borrower_id' => $borrower_id,
				'token_hash'  => GuestAccessTokenService::hash_token( $token ),
				'status'      => $status,
				'expires_at'  => $expires_at,
				'created_at'  => '2026-06-19 12:00:00',
				'created_by'  => null,
				'revoked_at'  => 'revoked' === $status ? '2026-06-19 12:30:00' : null,
			)
		);
	}

	/**
	 * Return fake guest-token table rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function guest_token_rows(): array {
		$tables = Schema::table_names();
		$rows   = $GLOBALS['connectlibrary_test_db_tables'][ $tables['guest_access_tokens'] . ':rows' ] ?? array();

		return is_array( $rows ) ? $rows : array();
	}
}
