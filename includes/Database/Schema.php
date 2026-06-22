<?php
/**
 * Database schema management for ConnectLibrary catalog and borrower tables.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Database;

use RuntimeException;

/**
 * Creates and upgrades the non-destructive Phase 1 catalog schema and Phase 2 borrower schema.
 */
final class Schema {
	/**
	 * WordPress option storing the installed schema version.
	 */
	public const OPTION_NAME = 'connectlibrary_schema_version';

	/**
	 * Current ConnectLibrary schema version.
	 */
	public const VERSION = '1.6.1';

	/**
	 * Build table names using the active WordPress table prefix.
	 *
	 * @param string|null $prefix Optional table prefix. Defaults to $wpdb->prefix.
	 * @return array<string,string>
	 * @throws RuntimeException When no WordPress database prefix is available.
	 */
	public static function table_names( ?string $prefix = null ): array {
		if ( null === $prefix ) {
			global $wpdb;

			if ( ! isset( $wpdb ) || ! isset( $wpdb->prefix ) ) {
				throw new RuntimeException( 'ConnectLibrary schema requires a WordPress database prefix.' );
			}

			$prefix = $wpdb->prefix;
		}

		return array(
			'authors'             => $prefix . 'connectlibrary_authors',
			'book_authors'        => $prefix . 'connectlibrary_book_authors',
			'series'              => $prefix . 'connectlibrary_series',
			'book_series'         => $prefix . 'connectlibrary_book_series',
			'copies'              => $prefix . 'connectlibrary_copies',
			'book_metadata'       => $prefix . 'connectlibrary_book_metadata',
			'import_sources'      => $prefix . 'connectlibrary_import_sources',
			'borrowers'           => $prefix . 'connectlibrary_borrowers',
			'borrower_audit'      => $prefix . 'connectlibrary_borrower_audit',
			'guest_access_tokens' => $prefix . 'connectlibrary_guest_access_tokens',
			'borrower_cards'      => $prefix . 'connectlibrary_borrower_cards',
			'reservations'        => $prefix . 'connectlibrary_reservations',
			'reservation_audit'   => $prefix . 'connectlibrary_reservation_audit',
			'loans'               => $prefix . 'connectlibrary_loans',
			'loan_audit'          => $prefix . 'connectlibrary_loan_audit',
			'audit_events'        => $prefix . 'connectlibrary_audit_events',
		);
	}

	/**
	 * Create or upgrade catalog tables and save the schema version option.
	 *
	 * @throws RuntimeException When the WordPress database object is unavailable.
	 */
	public static function migrate(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_charset_collate' ) ) {
			throw new RuntimeException( 'ConnectLibrary schema migration requires the WordPress database object.' );
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( self::sql_definitions( self::table_names(), $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION_NAME, self::VERSION, false );
	}

	/**
	 * SQL definitions for the Phase 1 catalog schema and Phase 2 borrower schema.
	 *
	 * @param array<string,string> $tables Table names from table_names().
	 * @param string               $charset_collate Database charset/collation clause.
	 * @return array<string,string>
	 */
	public static function sql_definitions( array $tables, string $charset_collate ): array {
		return array(
			'authors'             => "CREATE TABLE {$tables['authors']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	display_name varchar(255) NOT NULL,
	sort_name varchar(255) DEFAULT NULL,
	slug varchar(200) NOT NULL,
	bio longtext DEFAULT NULL,
	external_ids longtext DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY slug (slug),
	KEY sort_name (sort_name(191)),
	KEY display_name (display_name(191))
) {$charset_collate};",
			'book_authors'        => "CREATE TABLE {$tables['book_authors']} (
	book_post_id bigint(20) unsigned NOT NULL,
	author_id bigint(20) unsigned NOT NULL,
	role varchar(32) NOT NULL DEFAULT 'author',
	position int(10) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (book_post_id,author_id,role),
	KEY author_id (author_id),
	KEY book_position (book_post_id,position)
) {$charset_collate};",
			'series'              => "CREATE TABLE {$tables['series']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	name varchar(255) NOT NULL,
	sort_name varchar(255) DEFAULT NULL,
	slug varchar(200) NOT NULL,
	description longtext DEFAULT NULL,
	external_ids longtext DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY slug (slug),
	KEY sort_name (sort_name(191)),
	KEY name (name(191))
) {$charset_collate};",
			'book_series'         => "CREATE TABLE {$tables['book_series']} (
	book_post_id bigint(20) unsigned NOT NULL,
	series_id bigint(20) unsigned NOT NULL,
	series_position varchar(64) DEFAULT NULL,
	position_sort decimal(10,3) DEFAULT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (book_post_id,series_id),
	KEY series_id (series_id),
	KEY book_post_id (book_post_id),
	KEY series_position_sort (series_id,position_sort)
) {$charset_collate};",
			'copies'              => "CREATE TABLE {$tables['copies']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	book_post_id bigint(20) unsigned NOT NULL,
	copy_number int(10) unsigned NOT NULL DEFAULT 1,
	barcode varchar(100) DEFAULT NULL,
	isbn_10 varchar(20) DEFAULT NULL,
	isbn_13 varchar(20) DEFAULT NULL,
	condition_status varchar(20) NOT NULL DEFAULT 'good',
	item_status varchar(20) NOT NULL DEFAULT 'active',
	circulation_status varchar(20) NOT NULL DEFAULT 'available',
	current_loan_id bigint(20) unsigned DEFAULT NULL,
	current_reservation_id bigint(20) unsigned DEFAULT NULL,
	visibility varchar(20) NOT NULL DEFAULT 'public',
	room varchar(100) DEFAULT NULL,
	shelf varchar(100) DEFAULT NULL,
	section varchar(100) DEFAULT NULL,
	private_notes longtext DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY barcode (barcode),
	KEY book_post_id (book_post_id),
	KEY isbn_13 (isbn_13),
	KEY isbn_10 (isbn_10),
	KEY item_status (item_status),
	KEY circulation_status (circulation_status),
	KEY current_loan_id (current_loan_id),
	KEY location (room,shelf,section)
) {$charset_collate};",
			'book_metadata'       => "CREATE TABLE {$tables['book_metadata']} (
	book_post_id bigint(20) unsigned NOT NULL,
	subtitle varchar(255) DEFAULT NULL,
	isbn_10 varchar(20) DEFAULT NULL,
	isbn_13 varchar(20) DEFAULT NULL,
	publisher varchar(255) DEFAULT NULL,
	published_date varchar(32) DEFAULT NULL,
	language varchar(32) DEFAULT NULL,
	page_count int(10) unsigned DEFAULT NULL,
	age_level varchar(100) DEFAULT NULL,
	reading_level varchar(100) DEFAULT NULL,
	content_notes longtext DEFAULT NULL,
	church_notes longtext DEFAULT NULL,
	recommended tinyint(1) NOT NULL DEFAULT 0,
	rating decimal(3,2) DEFAULT NULL,
	cover_attachment_id bigint(20) unsigned DEFAULT NULL,
	metadata_source varchar(32) NOT NULL DEFAULT 'manual',
	raw_provider_payload longtext DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (book_post_id),
	KEY isbn_13 (isbn_13),
	KEY isbn_10 (isbn_10),
	KEY publisher (publisher(191)),
	KEY language (language),
	KEY age_level (age_level),
	KEY recommended (recommended)
) {$charset_collate};",
			'import_sources'      => "CREATE TABLE {$tables['import_sources']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	book_post_id bigint(20) unsigned DEFAULT NULL,
	provider varchar(50) NOT NULL,
	provider_record_id varchar(191) DEFAULT NULL,
	isbn_10 varchar(20) DEFAULT NULL,
	isbn_13 varchar(20) DEFAULT NULL,
	status varchar(32) NOT NULL DEFAULT 'manual',
	raw_payload longtext DEFAULT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY book_post_id (book_post_id),
	KEY provider_record (provider,provider_record_id),
	KEY isbn_13 (isbn_13),
	KEY isbn_10 (isbn_10),
	KEY status (status)
) {$charset_collate};",
			'borrowers'           => "CREATE TABLE {$tables['borrowers']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	borrower_type varchar(20) NOT NULL DEFAULT 'manual',
	wp_user_id bigint(20) unsigned DEFAULT NULL,
	status varchar(20) NOT NULL DEFAULT 'active',
	display_name varchar(255) NOT NULL,
	preferred_name varchar(255) DEFAULT NULL,
	email varchar(191) DEFAULT NULL,
	phone varchar(50) DEFAULT NULL,
	guardian_borrower_id bigint(20) unsigned DEFAULT NULL,
	guardian_name varchar(255) DEFAULT NULL,
	guardian_email varchar(191) DEFAULT NULL,
	guardian_phone varchar(50) DEFAULT NULL,
	guardian_relationship varchar(100) DEFAULT NULL,
	email_notices_allowed tinyint(1) NOT NULL DEFAULT 0,
	private_notes longtext DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	created_by bigint(20) unsigned DEFAULT NULL,
	updated_by bigint(20) unsigned DEFAULT NULL,
	anonymized_at datetime DEFAULT NULL,
	anonymized_by bigint(20) unsigned DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY wp_user_id (wp_user_id),
	KEY email (email),
	KEY borrower_type (borrower_type),
	KEY status (status),
	KEY guardian_borrower_id (guardian_borrower_id),
	KEY created_at (created_at),
	KEY updated_at (updated_at)
) {$charset_collate};",
			'borrower_audit'      => "CREATE TABLE {$tables['borrower_audit']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	borrower_id bigint(20) unsigned NOT NULL,
	actor_user_id bigint(20) unsigned DEFAULT NULL,
	action varchar(50) NOT NULL,
	changed_fields longtext DEFAULT NULL,
	reason varchar(255) DEFAULT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY borrower_id (borrower_id),
	KEY actor_user_id (actor_user_id),
	KEY action (action),
	KEY created_at (created_at)
) {$charset_collate};",
			'guest_access_tokens' => "CREATE TABLE {$tables['guest_access_tokens']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	borrower_id bigint(20) unsigned NOT NULL,
	token_hash varchar(128) NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'active',
	expires_at datetime NOT NULL,
	created_at datetime NOT NULL,
	created_by bigint(20) unsigned DEFAULT NULL,
	revoked_at datetime DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY token_hash (token_hash),
	KEY borrower_id (borrower_id),
	KEY status (status),
	KEY expires_at (expires_at),
	KEY created_at (created_at)
	) {$charset_collate};",
			'borrower_cards'      => "CREATE TABLE {$tables['borrower_cards']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	borrower_id bigint(20) unsigned NOT NULL,
	token_hash varchar(128) NOT NULL,
	payload varchar(96) NOT NULL,
	card_label varchar(32) NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'active',
	replaces_card_id bigint(20) unsigned DEFAULT NULL,
	superseded_by_card_id bigint(20) unsigned DEFAULT NULL,
	replacement_reason varchar(100) DEFAULT NULL,
	replacement_note text DEFAULT NULL,
	audit_correlation_id varchar(36) DEFAULT NULL,
	created_at datetime NOT NULL,
	created_by bigint(20) unsigned DEFAULT NULL,
	updated_at datetime NOT NULL,
	updated_by bigint(20) unsigned DEFAULT NULL,
	disabled_at datetime DEFAULT NULL,
	replaced_at datetime DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY token_hash (token_hash),
	KEY borrower_id (borrower_id),
	KEY status (status),
	KEY replaces_card_id (replaces_card_id),
	KEY superseded_by_card_id (superseded_by_card_id),
	KEY audit_correlation_id (audit_correlation_id),
	KEY created_at (created_at)
	) {$charset_collate};",
			'reservations'        => "CREATE TABLE {$tables['reservations']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	book_post_id bigint(20) unsigned NOT NULL,
	copy_id bigint(20) unsigned DEFAULT NULL,
	borrower_id bigint(20) unsigned DEFAULT NULL,
	guest_name varchar(255) DEFAULT NULL,
	guest_email varchar(191) DEFAULT NULL,
	status varchar(30) NOT NULL DEFAULT 'pending_approval',
	hold_expires_at datetime DEFAULT NULL,
	requested_at datetime NOT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	acted_by bigint(20) unsigned DEFAULT NULL,
	notes longtext DEFAULT NULL,
	context varchar(255) DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY book_post_id (book_post_id),
	KEY copy_id (copy_id),
	KEY borrower_id (borrower_id),
	KEY guest_email (guest_email),
	KEY status (status),
	KEY hold_expires_at (hold_expires_at),
	KEY requested_at (requested_at)
) {$charset_collate};",
			'reservation_audit'   => "CREATE TABLE {$tables['reservation_audit']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	reservation_id bigint(20) unsigned NOT NULL,
	actor_user_id bigint(20) unsigned DEFAULT NULL,
	action varchar(50) NOT NULL,
	from_status varchar(30) DEFAULT NULL,
	to_status varchar(30) DEFAULT NULL,
	changed_fields longtext DEFAULT NULL,
	reason varchar(255) DEFAULT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY reservation_id (reservation_id),
	KEY actor_user_id (actor_user_id),
	KEY action (action),
	KEY created_at (created_at)
) {$charset_collate};",
			'loans'               => "CREATE TABLE {$tables['loans']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	book_post_id bigint(20) unsigned NOT NULL,
	copy_id bigint(20) unsigned DEFAULT NULL,
	borrower_id bigint(20) unsigned NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'active',
	checked_out_at datetime NOT NULL,
	due_at datetime NOT NULL,
	returned_at datetime DEFAULT NULL,
	renewal_count int(10) unsigned NOT NULL DEFAULT 0,
	renewal_limit int(10) unsigned NOT NULL DEFAULT 2,
	last_renewed_at datetime DEFAULT NULL,
	due_period_days int(10) unsigned DEFAULT NULL,
	source varchar(50) DEFAULT NULL,
	created_by bigint(20) unsigned DEFAULT NULL,
	returned_by bigint(20) unsigned DEFAULT NULL,
	updated_by bigint(20) unsigned DEFAULT NULL,
	override_note varchar(500) DEFAULT NULL,
	correction_note varchar(500) DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY book_post_id (book_post_id),
	KEY copy_id (copy_id),
	KEY borrower_id (borrower_id),
	KEY status (status),
	KEY due_at (due_at),
	KEY checked_out_at (checked_out_at)
) {$charset_collate};",
			'loan_audit'          => "CREATE TABLE {$tables['loan_audit']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	loan_id bigint(20) unsigned NOT NULL,
	actor_user_id bigint(20) unsigned DEFAULT NULL,
	action varchar(50) NOT NULL,
	changed_fields longtext DEFAULT NULL,
	reason varchar(255) DEFAULT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY loan_id (loan_id),
	KEY actor_user_id (actor_user_id),
	KEY action (action),
	KEY created_at (created_at)
) {$charset_collate};",
			'audit_events'        => "CREATE TABLE {$tables['audit_events']} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	correlation_id varchar(36) DEFAULT NULL,
	action varchar(80) NOT NULL,
	actor_type varchar(20) NOT NULL DEFAULT 'system',
	actor_id bigint(20) unsigned DEFAULT NULL,
	source_channel varchar(30) NOT NULL DEFAULT 'system',
	entity_type varchar(30) DEFAULT NULL,
	entity_id bigint(20) unsigned DEFAULT NULL,
	secondary_entity_type varchar(30) DEFAULT NULL,
	secondary_entity_id bigint(20) unsigned DEFAULT NULL,
	context_json longtext DEFAULT NULL,
	before_json longtext DEFAULT NULL,
	after_json longtext DEFAULT NULL,
	status varchar(20) NOT NULL DEFAULT 'ok',
	reason varchar(500) DEFAULT NULL,
	error_code varchar(100) DEFAULT NULL,
	error_message varchar(500) DEFAULT NULL,
	summary varchar(500) DEFAULT NULL,
	created_at_utc datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY action (action),
	KEY entity_lookup (entity_type,entity_id),
	KEY actor_id (actor_id),
	KEY correlation_id (correlation_id),
	KEY created_at_utc (created_at_utc)
) {$charset_collate};",
		);
	}
}
