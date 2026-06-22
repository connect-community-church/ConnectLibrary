<?php
/**
 * ConnectLibrary settings storage and sanitization.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Settings;

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Circulation\DueReminderCron;

defined( 'ABSPATH' ) || exit;

/**
 * Central access point for the versioned ConnectLibrary settings option.
 */
final class Settings {
	public const OPTION_NAME = 'connectlibrary_settings';

	private const SETTINGS_VERSION = 1;

	private const CATALOG_LAYOUTS = array( 'grid', 'list' );

	private const AVAILABILITY_STATUSES = array( 'available', 'reserved', 'checked_out', 'waitlist_available' );

	private const METADATA_PROVIDER_ORDERS = array( 'google_books_open_library', 'open_library_google_books' );

	/**
	 * Circulation-relevant setting keys that require audit logging on change.
	 */
	private const CIRCULATION_AUDIT_KEYS = array(
		'default_loan_period_days',
		'default_hold_period_days',
		'due_reminder_lead_days',
		'librarian_email',
		'default_availability_status',
	);

	/**
	 * Get all settings with defaults merged in.
	 *
	 * Later setup/catalog/circulation cards should read through this method (or
	 * get()) instead of calling get_option() directly, so missing options and new
	 * defaults remain safe across upgrades.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Read one setting value with default fallback.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ): mixed {
		$settings = self::all();

		return $settings[ $key ] ?? null;
	}

	/**
	 * Safely create the option if it does not exist yet.
	 */
	public static function initialize_defaults(): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::defaults(), '', false );
		}
	}

	/**
	 * Sanitize and persist a partial settings update.
	 *
	 * @param array<string,mixed> $input Settings values to merge into existing settings.
	 * @return array<string,mixed> Sanitized settings saved to the option.
	 */
	public static function save( array $input ): array {
		$previous = self::all();
		$clean    = self::sanitize( array_merge( $previous, $input ) );

		update_option( self::OPTION_NAME, $clean, false );
		DueReminderCron::reschedule();
		self::log_circulation_setting_changes( $previous, $clean );

		return $clean;
	}

	/**
	 * Build runtime defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		$site_name   = get_bloginfo( 'name' );
		$admin_email = get_option( 'admin_email', '' );

		return array(
			'settings_version'            => self::SETTINGS_VERSION,
			'library_name'                => $site_name ? $site_name : __( 'ConnectLibrary', 'connectlibrary' ),
			'librarian_email'             => is_email( $admin_email ) ? $admin_email : '',
			'pickup_instructions'         => '',
			'catalog_page_id'             => 0,
			'catalog_layout'              => 'grid',
			'default_availability_status' => 'available',
			'metadata_provider_order'     => 'google_books_open_library',
			'import_covers_locally'       => 1,
			'metadata_language'           => 'en',
			'default_loan_period_days'    => 14,
			'default_hold_period_days'    => 14,
			'due_reminder_lead_days'      => 3,
			'preserve_data_on_uninstall'  => 1,
			'setup_completed_at'          => '',
			'setup_dismissed_until'       => '',
			'demo_content_created_at'     => '',
		);
	}

	/**
	 * Sanitize the submitted settings array for the WordPress Settings API.
	 *
	 * Invalid values fall back to the previous saved value, then to defaults, and
	 * add a settings error so administrators get feedback instead of silent bad
	 * saves.
	 *
	 * @param mixed $input Raw submitted settings.
	 * @return array<string,mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = self::all();
		$clean    = self::defaults();

		$clean['library_name']                = self::sanitize_text( $input, 'library_name', $previous );
		$clean['librarian_email']             = self::sanitize_email_value( $input, $previous );
		$clean['pickup_instructions']         = self::sanitize_textarea( $input, 'pickup_instructions', $previous );
		$clean['catalog_page_id']             = self::sanitize_page_id( $input, $previous );
		$clean['catalog_layout']              = self::sanitize_choice( $input, 'catalog_layout', self::CATALOG_LAYOUTS, $previous );
		$clean['default_availability_status'] = self::sanitize_choice(
			$input,
			'default_availability_status',
			self::AVAILABILITY_STATUSES,
			$previous
		);
		$clean['metadata_provider_order']     = self::sanitize_choice(
			$input,
			'metadata_provider_order',
			self::METADATA_PROVIDER_ORDERS,
			$previous
		);
		$clean['import_covers_locally']       = empty( $input['import_covers_locally'] ) ? 0 : 1;
		$clean['metadata_language']           = self::sanitize_language( $input, $previous );
		$clean['default_loan_period_days']    = self::sanitize_integer_range( $input, 'default_loan_period_days', 1, 365, $previous );
		$clean['default_hold_period_days']    = self::sanitize_integer_range( $input, 'default_hold_period_days', 1, 365, $previous );
		$clean['due_reminder_lead_days']      = self::sanitize_integer_range( $input, 'due_reminder_lead_days', 0, 60, $previous );
		$clean['preserve_data_on_uninstall']  = empty( $input['preserve_data_on_uninstall'] ) ? 0 : 1;
		$clean['setup_completed_at']          = self::sanitize_datetime_text( $input, 'setup_completed_at' );
		$clean['setup_dismissed_until']       = self::sanitize_datetime_text( $input, 'setup_dismissed_until' );
		$clean['demo_content_created_at']     = self::sanitize_datetime_text( $input, 'demo_content_created_at' );

		return $clean;
	}

	/**
	 * Get catalog layout select choices.
	 *
	 * @return array<string,string>
	 */
	public static function catalog_layout_choices(): array {
		return array(
			'grid' => __( 'Grid', 'connectlibrary' ),
			'list' => __( 'List', 'connectlibrary' ),
		);
	}

	/**
	 * Get availability status choices for Phase 1 wording defaults.
	 *
	 * @return array<string,string>
	 */
	public static function availability_status_choices(): array {
		return array(
			'available'          => __( 'Available', 'connectlibrary' ),
			'reserved'           => __( 'Reserved', 'connectlibrary' ),
			'checked_out'        => __( 'Checked Out', 'connectlibrary' ),
			'waitlist_available' => __( 'Waitlist Available', 'connectlibrary' ),
		);
	}

	/**
	 * Get metadata provider order choices.
	 *
	 * @return array<string,string>
	 */
	public static function metadata_provider_order_choices(): array {
		return array(
			'google_books_open_library' => __( 'Google Books, then Open Library', 'connectlibrary' ),
			'open_library_google_books' => __( 'Open Library, then Google Books', 'connectlibrary' ),
		);
	}

	/**
	 * Sanitize one plain-text input field.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param string              $key Setting key.
	 * @param array<string,mixed> $previous Previous settings.
	 */
	private static function sanitize_text( array $input, string $key, array $previous ): string {
		$value = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';

		return '' !== $value ? $value : (string) $previous[ $key ];
	}

	/**
	 * Sanitize one textarea input field.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param string              $key Setting key.
	 * @param array<string,mixed> $previous Previous settings.
	 */
	private static function sanitize_textarea( array $input, string $key, array $previous ): string {
		if ( ! isset( $input[ $key ] ) ) {
			return (string) $previous[ $key ];
		}

		return sanitize_textarea_field( wp_unslash( $input[ $key ] ) );
	}

	/**
	 * Sanitize and validate librarian email.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param array<string,mixed> $previous Previous settings.
	 */
	private static function sanitize_email_value( array $input, array $previous ): string {
		$email = isset( $input['librarian_email'] ) ? sanitize_email( wp_unslash( $input['librarian_email'] ) ) : '';

		if ( '' !== $email && is_email( $email ) ) {
			return $email;
		}

		self::add_error( 'librarian_email', __( 'Please enter a valid librarian notification email address.', 'connectlibrary' ) );

		return (string) $previous['librarian_email'];
	}

	/**
	 * Sanitize catalog page ID.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param array<string,mixed> $previous Previous settings.
	 */
	private static function sanitize_page_id( array $input, array $previous ): int {
		if ( ! isset( $input['catalog_page_id'] ) ) {
			return (int) $previous['catalog_page_id'];
		}

		if ( ! is_numeric( $input['catalog_page_id'] ) ) {
			self::add_error( 'catalog_page_id', __( 'Please choose a valid WordPress page for the public catalog.', 'connectlibrary' ) );

			return (int) $previous['catalog_page_id'];
		}

		$page_id = absint( $input['catalog_page_id'] );

		if ( 0 === $page_id || 'page' === get_post_type( $page_id ) ) {
			return $page_id;
		}

		self::add_error( 'catalog_page_id', __( 'Please choose a valid WordPress page for the public catalog.', 'connectlibrary' ) );

		return (int) $previous['catalog_page_id'];
	}

	/**
	 * Sanitize an allowlisted select value.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param string              $key Setting key.
	 * @param array<int,string>   $allowed Allowed values.
	 * @param array<string,mixed> $previous Previous settings.
	 */
	private static function sanitize_choice( array $input, string $key, array $allowed, array $previous ): string {
		$value = isset( $input[ $key ] ) ? sanitize_key( wp_unslash( $input[ $key ] ) ) : '';

		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}

		self::add_error( $key, __( 'One of the selected ConnectLibrary settings was not recognized and was not saved.', 'connectlibrary' ) );

		return (string) $previous[ $key ];
	}

	/**
	 * Sanitize metadata locale/language preference.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param array<string,mixed> $previous Previous settings.
	 */
	private static function sanitize_language( array $input, array $previous ): string {
		$language = isset( $input['metadata_language'] ) ? sanitize_text_field( wp_unslash( $input['metadata_language'] ) ) : '';
		$language = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $language ) ?? '' );

		if ( '' !== $language && strlen( $language ) <= 12 ) {
			return $language;
		}

		self::add_error( 'metadata_language', __( 'Please enter a short metadata language code such as en.', 'connectlibrary' ) );

		return (string) $previous['metadata_language'];
	}

	/**
	 * Sanitize an integer field with inclusive bounds.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param string              $key Setting key.
	 * @param int                 $min Minimum value.
	 * @param int                 $max Maximum value.
	 * @param array<string,mixed> $previous Previous settings.
	 */
	private static function sanitize_integer_range( array $input, string $key, int $min, int $max, array $previous ): int {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			self::add_error( $key, __( 'Please enter a number within the allowed range.', 'connectlibrary' ) );

			return (int) $previous[ $key ];
		}

		$value = (int) $input[ $key ];

		if ( $value >= $min && $value <= $max ) {
			return $value;
		}

		self::add_error( $key, __( 'Please enter a number within the allowed range.', 'connectlibrary' ) );

		return (int) $previous[ $key ];
	}

	/**
	 * Sanitize an internal setup timestamp string.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param string              $key Setting key.
	 */
	private static function sanitize_datetime_text( array $input, string $key ): string {
		if ( empty( $input[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $input[ $key ] ) );
	}

	/**
	 * Log one audit event per changed circulation-relevant setting.
	 *
	 * Best-effort: swallows all exceptions so a logging failure never breaks save().
	 *
	 * @param array<string,mixed> $previous Settings before save.
	 * @param array<string,mixed> $clean    Sanitized settings after save.
	 */
	private static function log_circulation_setting_changes( array $previous, array $clean ): void {
		$actor_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		foreach ( self::CIRCULATION_AUDIT_KEYS as $key ) {
			if ( (string) ( $previous[ $key ] ?? '' ) === (string) ( $clean[ $key ] ?? '' ) ) {
				continue;
			}

			try {
				( new AuditEventService() )->log(
					'settings_updated',
					array(
						'entity_type'    => 'settings',
						'entity_id'      => 0,
						'actor_type'     => $actor_id > 0 ? 'user' : 'system',
						'actor_id'       => $actor_id,
						'source_channel' => 'admin',
						'context'        => array( 'setting_key' => $key ),
						'before'         => array( 'value' => $previous[ $key ] ?? null ),
						'after'          => array( 'value' => $clean[ $key ] ?? null ),
						'summary'        => 'Circulation setting ' . $key . ' changed',
					)
				);
			} catch ( \Throwable $e ) {
				// Best-effort; non-fatal if audit service or DB is unavailable.
			}
		}
	}

	/**
	 * Add a Settings API validation message when the function is available.
	 *
	 * @param string $code Error code suffix.
	 * @param string $message Human-readable message.
	 */
	private static function add_error( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION_NAME, 'connectlibrary_' . $code, $message );
		}
	}
}
