<?php
/**
 * Public account registration and saved-event calendar integration.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Account;

use ConnectLibrary\Borrowers\BorrowerCardService;
use ConnectLibrary\Borrowers\BorrowerRepository;
use WP_Error;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment.Missing,Generic.Commenting.DocComment.MissingShort

defined( 'ABSPATH' ) || exit;

/**
 * Open church account registration foundation shared by library and events.
 */
final class AccountRegistrationService {
	public const REGISTRATION_SHORTCODE = 'connectlibrary_register';
	public const CALENDAR_SHORTCODE     = 'connectlibrary_my_church_calendar';
	public const ACTION_FIELD           = 'connectlibrary_account_action';
	public const SAVED_EVENTS_META      = 'connectlibrary_saved_event_ids';
	public const ICAL_TOKEN_META        = 'connectlibrary_saved_events_ical_token';
	public const ICAL_RANGE_META        = 'connectlibrary_saved_events_ical_range';
	public const ICAL_TOKEN_MAP_OPTION  = 'connectlibrary_saved_events_ical_tokens';
	public const ICAL_QUERY_VAR         = 'cl_saved_events_ical';

	private BorrowerRepository $borrowers;

	public function __construct( ?BorrowerRepository $borrowers = null ) {
		$this->borrowers = $borrowers ?? new BorrowerRepository();
	}

	/** Register public hooks and shortcodes. */
	public function register(): void {
		add_action( 'user_register', array( $this, 'handle_user_register' ), 10, 1 );
		add_action( 'init', array( $this, 'handle_post' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_ical_feed' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'the_content', array( $this, 'append_event_save_prompt' ), 20 );
		add_shortcode( self::REGISTRATION_SHORTCODE, array( $this, 'render_registration_shortcode' ) );
		add_shortcode( self::CALENDAR_SHORTCODE, array( $this, 'render_calendar_shortcode' ) );
	}

	/** @param array<int,string> $vars Query vars. @return array<int,string> */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::ICAL_QUERY_VAR;
		return array_values( array_unique( $vars ) );
	}

	/** WordPress user_register hook. */
	public function handle_user_register( int $user_id ): void {
		$this->ensure_borrower_for_user( $user_id );
	}

	/** Process public account/calendar POST actions. */
	public function handle_post(): void {
		$action = isset( $_POST[ self::ACTION_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		$nonce = isset( $_POST['_cl_account_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_cl_account_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'connectlibrary-account' ) ) {
			return;
		}

		if ( 'register' === $action ) {
			$this->handle_registration_post();
			return;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return;
		}

		if ( 'save_event' === $action ) {
			$this->save_event_for_user( $user_id, absint( $_POST['event_id'] ?? 0 ) );
		} elseif ( 'unsave_event' === $action ) {
			$this->unsave_event_for_user( $user_id, absint( $_POST['event_id'] ?? 0 ) );
		} elseif ( 'set_ical_range' === $action ) {
			$this->set_ical_range_for_user( $user_id, sanitize_key( (string) wp_unslash( $_POST['ical_range'] ?? 'upcoming' ) ) );
		}
	}

	/** @return array<string,mixed>|WP_Error */
	private function handle_registration_post(): array|WP_Error {
		if ( '' !== trim( (string) wp_unslash( $_POST['cl_account_hp'] ?? '' ) ) ) {
			return new WP_Error( 'connectlibrary_registration_spam', __( 'Registration could not be completed.', 'connectlibrary' ) );
		}

		$name              = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
		$email             = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$password          = (string) wp_unslash( $_POST['password'] ?? '' );
		$borrower_category = $this->borrower_category_from_value( wp_unslash( $_POST['borrower_category'] ?? 'in_person' ) );

		if ( '' === $name || '' === $email || ! is_email( $email ) || '' === $password ) {
			return new WP_Error( 'connectlibrary_registration_required', __( 'Name, email, and password are required.', 'connectlibrary' ) );
		}

		$username = $this->username_from_email( $email );
		$user_id  = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( function_exists( 'wp_update_user' ) ) {
			wp_update_user(
				array(
					'ID'           => (int) $user_id,
					'display_name' => $name,
				)
			);
		} elseif ( isset( $GLOBALS['connectlibrary_test_users'][ (int) $user_id ] ) ) {
			$GLOBALS['connectlibrary_test_users'][ (int) $user_id ]->display_name = $name;
		}

		$borrower = $this->ensure_borrower_for_user( (int) $user_id, $name, $borrower_category );
		$this->send_verification_reminder( (int) $user_id, $email );

		return $borrower;
	}

	/**
	 * Ensure a WordPress user has an active borrower/patron profile.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function ensure_borrower_for_user( int $user_id, string $display_name_override = '', string $borrower_category = 'online' ): array|WP_Error {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'connectlibrary_account_user_missing', __( 'A valid user is required.', 'connectlibrary' ) );
		}

		$borrower_category = $this->borrower_category_from_value( $borrower_category );
		$existing          = $this->borrowers->find_by_wp_user_id( $user_id );
		if ( null !== $existing ) {
			if ( $borrower_category !== (string) ( $existing['borrower_category'] ?? 'in_person' ) ) {
				$this->borrowers->update(
					(int) $existing['id'],
					array(
						'borrower_category' => $borrower_category,
						'updated_at'        => current_time( 'mysql' ),
						'updated_by'        => $user_id,
					)
				);
				$this->borrowers->audit( (int) $existing['id'], 'borrower_category_update', array( 'borrower_category' ) );
				return $this->borrowers->get( (int) $existing['id'] ) ?? $existing;
			}
			return $existing;
		}

		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'connectlibrary_account_user_missing', __( 'A valid user is required.', 'connectlibrary' ) );
		}

		$email        = sanitize_email( (string) ( $user->user_email ?? '' ) );
		$display_name = '' !== trim( $display_name_override )
			? sanitize_text_field( $display_name_override )
			: sanitize_text_field( (string) ( $user->display_name ?? $user->user_login ?? $email ) );
		if ( '' === $display_name ) {
			$display_name = $email;
		}

		$matching = '' !== $email ? $this->borrowers->find_active_by_email( $email ) : null;
		if ( null !== $matching && empty( $matching['wp_user_id'] ) ) {
			$this->borrowers->update(
				(int) $matching['id'],
				array(
					'borrower_type'     => 'wp_user',
					'borrower_category' => $borrower_category,
					'wp_user_id'        => $user_id,
					'display_name'  => $display_name,
					'email'         => $email,
					'updated_at'    => current_time( 'mysql' ),
					'updated_by'    => $user_id,
				)
			);
			$this->borrowers->audit( (int) $matching['id'], 'wp_user_auto_link', array( 'wp_user_id', 'email' ) );
			( new BorrowerCardService() )->generate_first_card( (int) $matching['id'] );
			return $this->borrowers->get( (int) $matching['id'] ) ?? $matching;
		}

		$now = current_time( 'mysql' );
		$id  = $this->borrowers->insert(
			array(
				'borrower_type'         => 'wp_user',
				'borrower_category'     => $borrower_category,
				'wp_user_id'            => $user_id,
				'status'                => 'active',
				'display_name'          => $display_name,
				'preferred_name'        => null,
				'email'                 => '' !== $email ? $email : null,
				'phone'                 => null,
				'guardian_borrower_id'  => null,
				'guardian_name'         => null,
				'guardian_email'        => null,
				'guardian_phone'        => null,
				'guardian_relationship' => null,
				'email_notices_allowed' => 1,
				'private_notes'         => null,
				'created_at'            => $now,
				'updated_at'            => $now,
				'created_by'            => $user_id,
				'updated_by'            => $user_id,
			)
		);
		$this->borrowers->audit( $id, 'account_registration_auto_create', array( 'wp_user_id', 'email' ) );
		( new BorrowerCardService() )->generate_first_card( $id );

		return $this->borrowers->get( $id ) ?? array();
	}

	/** Normalize the public/admin borrower category value. */
	private function borrower_category_from_value( mixed $value ): string {
		$category = sanitize_key( (string) $value );
		return in_array( $category, array( 'in_person', 'online', 'other' ), true ) ? $category : 'in_person';
	}

	/** Save an event for a user. */
	public function save_event_for_user( int $user_id, int $event_id ): void {
		if ( $user_id <= 0 || $event_id <= 0 ) {
			return;
		}
		$events   = $this->saved_events_for_user( $user_id );
		$events[] = $event_id;
		$this->update_saved_events( $user_id, $events );
		$this->feed_token_for_user( $user_id );
	}

	/** Unsave an event for a user. */
	public function unsave_event_for_user( int $user_id, int $event_id ): void {
		$events = array_values( array_filter( $this->saved_events_for_user( $user_id ), static fn( int $id ): bool => $id !== $event_id ) );
		$this->update_saved_events( $user_id, $events );
	}

	/** @return array<int,int> */
	public function saved_events_for_user( int $user_id ): array {
		$value = function_exists( 'get_user_meta' ) ? get_user_meta( $user_id, self::SAVED_EVENTS_META, true ) : array();
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	/** Store iCal range preference. */
	public function set_ical_range_for_user( int $user_id, string $range ): void {
		$range = 'all' === $range ? 'all' : 'upcoming';
		update_user_meta( $user_id, self::ICAL_RANGE_META, $range );
	}

	/** Get iCal range preference. */
	public function ical_range_for_user( int $user_id ): string {
		$value = get_user_meta( $user_id, self::ICAL_RANGE_META, true );
		return 'all' === $value ? 'all' : 'upcoming';
	}

	/** Return/create the user's private feed token. */
	public function feed_token_for_user( int $user_id ): string {
		$token = (string) get_user_meta( $user_id, self::ICAL_TOKEN_META, true );
		if ( '' === $token ) {
			$token = wp_generate_password( 40, false, false );
			update_user_meta( $user_id, self::ICAL_TOKEN_META, $token );
		}
		$map           = get_option( self::ICAL_TOKEN_MAP_OPTION, array() );
		$map           = is_array( $map ) ? $map : array();
		$map[ $token ] = $user_id;
		update_option( self::ICAL_TOKEN_MAP_OPTION, $map, false );
		return $token;
	}

	/** Render public registration shortcode. */
	public function render_registration_shortcode(): string {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return '<p class="connectlibrary-account-notice">' . esc_html__( 'You are already registered and signed in.', 'connectlibrary' ) . '</p>';
		}
		$nonce = wp_create_nonce( 'connectlibrary-account' );
		return '<style>.connectlibrary-registration-form{max-width:440px;margin:1.5rem 0 0;display:grid;gap:1rem;}.connectlibrary-registration-form p{margin:0;}.connectlibrary-registration-form label{display:block;font-weight:600;color:#25364a;line-height:1.35;}.connectlibrary-registration-form input[type="text"],.connectlibrary-registration-form input[type="email"],.connectlibrary-registration-form input[type="password"],.connectlibrary-registration-form select{display:block;width:100%;max-width:100%;box-sizing:border-box;margin:.4rem 0 0;padding:.72rem .8rem;border:1px solid #c8d3df;border-radius:6px;background:#fff;color:#1f2937;font:inherit;line-height:1.35;}.connectlibrary-registration-form input:focus{outline:2px solid #1f5d9c;outline-offset:2px;border-color:#1f5d9c;}.connectlibrary-registration-form button{justify-self:start;margin-top:.25rem;padding:.75rem 1.25rem;border:0;border-radius:6px;background:#2d82cf;color:#fff;font:inherit;font-weight:600;cursor:pointer;}.connectlibrary-registration-form button:hover,.connectlibrary-registration-form button:focus-visible{background:#1f6fb2;}.connectlibrary-hp-field{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important;}</style>'
			. '<form class="connectlibrary-registration-form" method="post">'
			. '<input type="hidden" name="' . esc_attr( self::ACTION_FIELD ) . '" value="register">'
			. '<input type="hidden" name="_cl_account_nonce" value="' . esc_attr( $nonce ) . '">'
			. '<p class="connectlibrary-hp-field" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'connectlibrary' ) . '<input type="text" name="cl_account_hp" tabindex="-1" autocomplete="off"></label></p>'
			. '<p><label>' . esc_html__( 'Name', 'connectlibrary' ) . '<input required name="display_name" type="text" autocomplete="name"></label></p>'
			. '<p><label>' . esc_html__( 'Email', 'connectlibrary' ) . '<input required name="email" type="email" autocomplete="email"></label></p>'
			. '<p><label>' . esc_html__( 'Password', 'connectlibrary' ) . '<input required name="password" type="password" autocomplete="new-password"></label></p>'
			. '<p><label>' . esc_html__( 'Library access', 'connectlibrary' ) . '<select name="borrower_category"><option value="in_person">' . esc_html__( 'I can pick up books in person', 'connectlibrary' ) . '</option><option value="online">' . esc_html__( 'Online only / I will not pick up books at church', 'connectlibrary' ) . '</option></select></label></p>'
			. '<button type="submit">' . esc_html__( 'Create my church account', 'connectlibrary' ) . '</button>'
			. '</form>';
	}

	/** Render My Church Calendar shortcode. */
	public function render_calendar_shortcode(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return '<section class="connectlibrary-my-calendar"><p>' . esc_html__( 'Log in or create an account to save events to your church calendar.', 'connectlibrary' ) . '</p></section>';
		}
		$events = $this->saved_events_for_user( $user_id );
		$token  = $this->feed_token_for_user( $user_id );
		$url    = add_query_arg( self::ICAL_QUERY_VAR, rawurlencode( $token ), home_url( '/' ) );
		$out    = '<section class="connectlibrary-my-calendar"><h2>' . esc_html__( 'My Church Calendar', 'connectlibrary' ) . '</h2>';
		$out   .= '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Subscribe or download my saved-event iCal feed', 'connectlibrary' ) . '</a></p>';
		$out   .= $this->render_ical_range_form( $user_id );
		if ( array() === $events ) {
			return $out . '<p>' . esc_html__( 'No saved events yet.', 'connectlibrary' ) . '</p></section>';
		}
		$out .= '<ul class="connectlibrary-saved-events">';
		foreach ( $events as $event_id ) {
			$out .= '<li>' . esc_html( $this->event_title( $event_id ) ) . $this->render_unsave_event_form( $event_id ) . '</li>';
		}
		return $out . '</ul></section>';
	}

	/** Append a save-event button to event detail content. */
	public function append_event_save_prompt( string $content ): string {
		$post_id = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
		if ( $post_id <= 0 || ! $this->is_event_request( $post_id ) ) {
			return $content;
		}
		return $content . $this->render_save_event_form( $post_id );
	}

	/** Serve private iCal feed. */
	public function maybe_serve_ical_feed(): void {
		$token = function_exists( 'get_query_var' ) ? (string) get_query_var( self::ICAL_QUERY_VAR, '' ) : '';
		if ( '' === $token && isset( $_GET[ self::ICAL_QUERY_VAR ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET[ self::ICAL_QUERY_VAR ] ) );
		}
		if ( '' === $token ) {
			return;
		}
		$map     = get_option( self::ICAL_TOKEN_MAP_OPTION, array() );
		$user_id = is_array( $map ) ? absint( $map[ $token ] ?? 0 ) : 0;
		if ( $user_id <= 0 ) {
			wp_die( esc_html__( 'Calendar feed not found.', 'connectlibrary' ), '', array( 'response' => 404 ) );
		}
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="connect-community-church-saved-events.ics"' );
		echo $this->build_ical_feed( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/** Build a simple iCal feed body. */
	public function build_ical_feed( int $user_id ): string {
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Connect Community Church//ConnectLibrary//EN', 'CALSCALE:GREGORIAN' );
		foreach ( $this->saved_events_for_user( $user_id ) as $event_id ) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:connect-event-' . $event_id . '@connectcommunitychurch.ca';
			$lines[] = 'SUMMARY:' . $this->ical_escape( $this->event_title( $event_id ) );
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/** @param array<int,int> $events Saved event IDs. */
	private function update_saved_events( int $user_id, array $events ): void {
		update_user_meta( $user_id, self::SAVED_EVENTS_META, array_values( array_unique( array_filter( array_map( 'absint', $events ) ) ) ) );
	}

	private function username_from_email( string $email ): string {
		$email_part = strstr( $email, '@', true );
		$base       = sanitize_user( false !== $email_part ? $email_part : $email, true );
		return '' !== $base ? $base : 'connect-reader-' . wp_generate_password( 8, false, false );
	}

	private function send_verification_reminder( int $user_id, string $email ): void {
		unset( $user_id );
		wp_mail( $email, __( 'Verify your Connect Community Church account', 'connectlibrary' ), __( 'Thanks for creating your church account. You can use the site now; please keep this email as a reminder to verify your address when prompted.', 'connectlibrary' ) );
	}

	private function render_ical_range_form( int $user_id ): string {
		$current = $this->ical_range_for_user( $user_id );
		return '<form class="connectlibrary-ical-range" method="post"><input type="hidden" name="' . esc_attr( self::ACTION_FIELD ) . '" value="set_ical_range"><input type="hidden" name="_cl_account_nonce" value="' . esc_attr( wp_create_nonce( 'connectlibrary-account' ) ) . '"><label>' . esc_html__( 'Calendar feed range', 'connectlibrary' ) . '<select name="ical_range"><option value="upcoming" ' . selected( $current, 'upcoming', false ) . '>' . esc_html__( 'Upcoming events only', 'connectlibrary' ) . '</option><option value="all" ' . selected( $current, 'all', false ) . '>' . esc_html__( 'Past and future saved events', 'connectlibrary' ) . '</option></select></label><button type="submit">' . esc_html__( 'Save calendar setting', 'connectlibrary' ) . '</button></form>';
	}

	private function render_save_event_form( int $event_id ): string {
		if ( function_exists( 'get_current_user_id' ) && get_current_user_id() <= 0 ) {
			$register_url = esc_url( home_url( '/register/' ) );
			$login_url    = esc_url( function_exists( 'wp_login_url' ) ? \wp_login_url( get_permalink( $event_id ) ) : home_url( '/wp-login.php' ) );
			return '<aside class="connectlibrary-event-save"><p>' . esc_html__( 'Want to save this event to My Church Calendar?', 'connectlibrary' ) . '</p><p><a href="' . $register_url . '">' . esc_html__( 'Create an account', 'connectlibrary' ) . '</a><span aria-hidden="true"> · </span><a href="' . $login_url . '">' . esc_html__( 'Log in', 'connectlibrary' ) . '</a></p></aside>';
		}
		return '<form class="connectlibrary-event-save" method="post"><input type="hidden" name="' . esc_attr( self::ACTION_FIELD ) . '" value="save_event"><input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '"><input type="hidden" name="_cl_account_nonce" value="' . esc_attr( wp_create_nonce( 'connectlibrary-account' ) ) . '"><button type="submit">' . esc_html__( 'Save to My Church Calendar', 'connectlibrary' ) . '</button></form>';
	}

	private function render_unsave_event_form( int $event_id ): string {
		return '<form class="connectlibrary-event-unsave" method="post"><input type="hidden" name="' . esc_attr( self::ACTION_FIELD ) . '" value="unsave_event"><input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '"><input type="hidden" name="_cl_account_nonce" value="' . esc_attr( wp_create_nonce( 'connectlibrary-account' ) ) . '"><button type="submit">' . esc_html__( 'Remove', 'connectlibrary' ) . '</button></form>';
	}

	private function event_title( int $event_id ): string {
		return function_exists( 'get_the_title' ) ? get_the_title( $event_id ) : sprintf( 'Event #%d', $event_id );
	}

	private function is_event_request( int $post_id ): bool {
		if ( function_exists( 'get_post_type' ) ) {
			return 'tribe_events' === get_post_type( $post_id ) || 'event' === get_post_type( $post_id );
		}
		return false;
	}

	private function ical_escape( string $value ): string {
		return str_replace( array( '\\', ';', ',', "\n", "\r" ), array( '\\\\', '\;', '\,', '\n', '' ), $value );
	}
}
