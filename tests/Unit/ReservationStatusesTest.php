<?php
/**
 * Tests for ReservationStatuses constants and transition helpers.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

use ConnectLibrary\Reservations\ReservationStatuses;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the reservation status vocabulary and transition graph.
 */
final class ReservationStatusesTest extends TestCase {

	public function test_status_constants_have_correct_string_values(): void {
		self::assertSame( 'pending_approval', ReservationStatuses::PENDING_APPROVAL );
		self::assertSame( 'active_hold', ReservationStatuses::ACTIVE_HOLD );
		self::assertSame( 'picked_up', ReservationStatuses::PICKED_UP );
		self::assertSame( 'fulfilled', ReservationStatuses::FULFILLED );
		self::assertSame( 'expired', ReservationStatuses::EXPIRED );
		self::assertSame( 'cancelled', ReservationStatuses::CANCELLED );
		self::assertSame( 'denied', ReservationStatuses::DENIED );
		self::assertSame( 'waitlisted', ReservationStatuses::WAITLISTED );
	}

	public function test_all_statuses_returns_all_eight(): void {
		$all = ReservationStatuses::all_statuses();

		self::assertCount( 8, $all );
		self::assertContains( ReservationStatuses::PENDING_APPROVAL, $all );
		self::assertContains( ReservationStatuses::ACTIVE_HOLD, $all );
		self::assertContains( ReservationStatuses::PICKED_UP, $all );
		self::assertContains( ReservationStatuses::FULFILLED, $all );
		self::assertContains( ReservationStatuses::EXPIRED, $all );
		self::assertContains( ReservationStatuses::CANCELLED, $all );
		self::assertContains( ReservationStatuses::DENIED, $all );
		self::assertContains( ReservationStatuses::WAITLISTED, $all );
	}

	public function test_terminal_statuses_contains_exactly_four_terminal_values(): void {
		$terminal = ReservationStatuses::terminal_statuses();

		self::assertCount( 4, $terminal );
		self::assertContains( ReservationStatuses::FULFILLED, $terminal );
		self::assertContains( ReservationStatuses::EXPIRED, $terminal );
		self::assertContains( ReservationStatuses::CANCELLED, $terminal );
		self::assertContains( ReservationStatuses::DENIED, $terminal );

		self::assertNotContains( ReservationStatuses::PENDING_APPROVAL, $terminal );
		self::assertNotContains( ReservationStatuses::ACTIVE_HOLD, $terminal );
		self::assertNotContains( ReservationStatuses::PICKED_UP, $terminal );
		self::assertNotContains( ReservationStatuses::WAITLISTED, $terminal );
	}

	public function test_non_terminal_statuses_are_complement_of_terminal(): void {
		$non_terminal = ReservationStatuses::non_terminal_statuses();
		$terminal     = ReservationStatuses::terminal_statuses();

		self::assertCount( 4, $non_terminal );

		foreach ( $non_terminal as $status ) {
			self::assertNotContains( $status, $terminal );
		}
	}

	/**
	 * @dataProvider terminal_status_provider
	 */
	public function test_is_terminal_returns_true_for_terminal_statuses( string $status ): void {
		self::assertTrue( ReservationStatuses::is_terminal( $status ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function terminal_status_provider(): array {
		return array(
			'fulfilled' => array( ReservationStatuses::FULFILLED ),
			'expired'   => array( ReservationStatuses::EXPIRED ),
			'cancelled' => array( ReservationStatuses::CANCELLED ),
			'denied'    => array( ReservationStatuses::DENIED ),
		);
	}

	/**
	 * @dataProvider non_terminal_status_provider
	 */
	public function test_is_terminal_returns_false_for_non_terminal_statuses( string $status ): void {
		self::assertFalse( ReservationStatuses::is_terminal( $status ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function non_terminal_status_provider(): array {
		return array(
			'pending_approval' => array( ReservationStatuses::PENDING_APPROVAL ),
			'active_hold'      => array( ReservationStatuses::ACTIVE_HOLD ),
			'picked_up'        => array( ReservationStatuses::PICKED_UP ),
			'waitlisted'       => array( ReservationStatuses::WAITLISTED ),
		);
	}

	public function test_valid_transitions_covers_all_non_terminal_statuses(): void {
		$map          = ReservationStatuses::valid_transitions();
		$non_terminal = ReservationStatuses::non_terminal_statuses();

		foreach ( $non_terminal as $status ) {
			self::assertArrayHasKey( $status, $map, "Non-terminal status '{$status}' must have at least one outbound transition." );
		}
	}

	public function test_valid_transitions_has_no_entry_for_terminal_statuses(): void {
		$map      = ReservationStatuses::valid_transitions();
		$terminal = ReservationStatuses::terminal_statuses();

		foreach ( $terminal as $status ) {
			self::assertArrayNotHasKey( $status, $map, "Terminal status '{$status}' must not appear as a transition source." );
		}
	}

	/**
	 * @dataProvider allowed_transition_provider
	 */
	public function test_can_transition_returns_true_for_allowed_pairs( string $from, string $to ): void {
		self::assertTrue( ReservationStatuses::can_transition( $from, $to ) );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function allowed_transition_provider(): array {
		return array(
			'pending_approval → active_hold'  => array( ReservationStatuses::PENDING_APPROVAL, ReservationStatuses::ACTIVE_HOLD ),
			'pending_approval → waitlisted'   => array( ReservationStatuses::PENDING_APPROVAL, ReservationStatuses::WAITLISTED ),
			'pending_approval → denied'       => array( ReservationStatuses::PENDING_APPROVAL, ReservationStatuses::DENIED ),
			'pending_approval → cancelled'    => array( ReservationStatuses::PENDING_APPROVAL, ReservationStatuses::CANCELLED ),
			'waitlisted → active_hold'        => array( ReservationStatuses::WAITLISTED, ReservationStatuses::ACTIVE_HOLD ),
			'waitlisted → cancelled'          => array( ReservationStatuses::WAITLISTED, ReservationStatuses::CANCELLED ),
			'active_hold → picked_up'         => array( ReservationStatuses::ACTIVE_HOLD, ReservationStatuses::PICKED_UP ),
			'active_hold → expired'           => array( ReservationStatuses::ACTIVE_HOLD, ReservationStatuses::EXPIRED ),
			'active_hold → cancelled'         => array( ReservationStatuses::ACTIVE_HOLD, ReservationStatuses::CANCELLED ),
			'picked_up → fulfilled'           => array( ReservationStatuses::PICKED_UP, ReservationStatuses::FULFILLED ),
			'picked_up → cancelled'           => array( ReservationStatuses::PICKED_UP, ReservationStatuses::CANCELLED ),
		);
	}

	/**
	 * @dataProvider forbidden_transition_provider
	 */
	public function test_can_transition_returns_false_for_forbidden_pairs( string $from, string $to ): void {
		self::assertFalse( ReservationStatuses::can_transition( $from, $to ) );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function forbidden_transition_provider(): array {
		return array(
			'fulfilled → cancelled (terminal)'    => array( ReservationStatuses::FULFILLED, ReservationStatuses::CANCELLED ),
			'expired → active_hold (terminal)'    => array( ReservationStatuses::EXPIRED, ReservationStatuses::ACTIVE_HOLD ),
			'denied → pending_approval (terminal)' => array( ReservationStatuses::DENIED, ReservationStatuses::PENDING_APPROVAL ),
			'cancelled → active_hold (terminal)'  => array( ReservationStatuses::CANCELLED, ReservationStatuses::ACTIVE_HOLD ),
			'pending_approval → fulfilled (skip)' => array( ReservationStatuses::PENDING_APPROVAL, ReservationStatuses::FULFILLED ),
			'active_hold → pending_approval (back)' => array( ReservationStatuses::ACTIVE_HOLD, ReservationStatuses::PENDING_APPROVAL ),
			'picked_up → pending_approval (back)' => array( ReservationStatuses::PICKED_UP, ReservationStatuses::PENDING_APPROVAL ),
		);
	}

	public function test_labels_covers_all_statuses(): void {
		$labels = ReservationStatuses::labels();

		foreach ( ReservationStatuses::all_statuses() as $status ) {
			self::assertArrayHasKey( $status, $labels, "Labels array must include entry for '{$status}'." );
			self::assertNotEmpty( $labels[ $status ] );
		}
	}
}
