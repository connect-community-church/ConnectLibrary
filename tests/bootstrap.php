<?php
/**
 * PHPUnit bootstrap for ConnectLibrary unit tests.
 *
 * Provides minimal WordPress function stubs so the current plugin skeleton can
 * be smoke tested without a live WordPress site or church data.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wordpress-stub/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['connectlibrary_test_wp_die']             = null;
$GLOBALS['connectlibrary_test_hooks']              = array();
$GLOBALS['connectlibrary_test_options']            = array();
$GLOBALS['connectlibrary_test_dbdelta']            = array();
$GLOBALS['connectlibrary_test_db_tables']          = array();
$GLOBALS['connectlibrary_test_db_insert_failures'] = array();
$GLOBALS['connectlibrary_test_db_query_results']   = array();
$GLOBALS['connectlibrary_test_post_types']         = array();
$GLOBALS['connectlibrary_test_taxonomies']         = array();
$GLOBALS['connectlibrary_test_posts']              = array();
$GLOBALS['connectlibrary_test_post_objects']       = array();
$GLOBALS['connectlibrary_test_registered_meta']    = array();
$GLOBALS['connectlibrary_test_rest_fields']        = array();
$GLOBALS['connectlibrary_test_rest_routes']        = array();
$GLOBALS['connectlibrary_test_object_terms']       = array();
$GLOBALS['connectlibrary_test_settings_errors']    = array();
$GLOBALS['connectlibrary_test_post_meta']          = array();
$GLOBALS['connectlibrary_test_meta_boxes']         = array();
$GLOBALS['connectlibrary_test_http_responses']     = array();
$GLOBALS['connectlibrary_test_http_requests']      = array();
$GLOBALS['connectlibrary_test_transients']         = array();
$GLOBALS['connectlibrary_test_flush_count']        = 0;
$GLOBALS['connectlibrary_test_attachments']        = array();
$GLOBALS['connectlibrary_test_uploads']            = array();
$GLOBALS['connectlibrary_test_enqueued_styles']    = array();
$GLOBALS['connectlibrary_test_block_variations']   = array();
$GLOBALS['connectlibrary_test_is_singular']        = false;
$GLOBALS['connectlibrary_test_current_post_id']    = 0;
$GLOBALS['connectlibrary_test_current_user_can']   = array();
$GLOBALS['connectlibrary_test_current_user_id']    = 1;
$GLOBALS['connectlibrary_test_created_users']      = array();
$GLOBALS['connectlibrary_test_users']              = array();
$GLOBALS['connectlibrary_test_shortcodes']         = array();
$GLOBALS['connectlibrary_test_blocks']             = array();
$GLOBALS['connectlibrary_test_registered_scripts'] = array();
$GLOBALS['connectlibrary_test_enqueued_scripts']   = array();
$GLOBALS['connectlibrary_test_registered_styles']  = array();
$GLOBALS['connectlibrary_test_script_data']        = array();
$GLOBALS['connectlibrary_test_admin_pages']        = array();
$GLOBALS['connectlibrary_test_safe_redirect']      = null;
$GLOBALS['connectlibrary_test_roles']              = array();
$GLOBALS['connectlibrary_test_query_vars']         = array();
$GLOBALS['connectlibrary_test_json_success']       = null;
$GLOBALS['connectlibrary_test_json_error']         = null;
$GLOBALS['connectlibrary_test_nocache_headers']    = 0;
$GLOBALS['connectlibrary_test_cron_events']        = array();
$GLOBALS['connectlibrary_test_mail']               = array();
$GLOBALS['connectlibrary_test_mail_should_fail']   = false;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $priority, $accepted_args );
		$GLOBALS['connectlibrary_test_hooks'][ $hook_name ][] = $callback;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['connectlibrary_test_hooks'][ $hook_name ][] = compact( 'callback', 'priority', 'accepted_args' );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
		unset( $priority );
		if ( empty( $GLOBALS['connectlibrary_test_hooks'][ $hook_name ] ) ) {
			return false;
		}

		$before = count( $GLOBALS['connectlibrary_test_hooks'][ $hook_name ] );
		$GLOBALS['connectlibrary_test_hooks'][ $hook_name ] = array_values(
			array_filter(
				$GLOBALS['connectlibrary_test_hooks'][ $hook_name ],
				static fn ( mixed $entry ): bool => ! is_array( $entry ) || ( $entry['callback'] ?? null ) !== $callback
			)
		);

		return count( $GLOBALS['connectlibrary_test_hooks'][ $hook_name ] ) < $before;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		foreach ( $GLOBALS['connectlibrary_test_hooks'][ $hook_name ] ?? array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['callback'] ) ) {
				$value = call_user_func( $entry['callback'], $value, ...array_slice( $args, 0, max( 0, (int) ( $entry['accepted_args'] ?? 1 ) - 1 ) ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, mixed ...$args ): void {
		foreach ( $GLOBALS['connectlibrary_test_hooks'][ $hook_name ] ?? array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['callback'] ) ) {
				call_user_func( $entry['callback'], ...array_slice( $args, 0, (int) ( $entry['accepted_args'] ?? 1 ) ) );
			} elseif ( is_callable( $entry ) ) {
				call_user_func( $entry, ...$args );
			}
		}
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, callable $callback ): void {
		$GLOBALS['connectlibrary_test_hooks'][ 'activate_' . basename( $file ) ][] = $callback;
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, callable $callback ): void {
		$GLOBALS['connectlibrary_test_hooks'][ 'deactivate_' . basename( $file ) ][] = $callback;
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( string $domain, bool $deprecated = false, string $plugin_rel_path = '' ): bool {
		return '' !== $domain && '' !== $plugin_rel_path && false === $deprecated;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( string $post_type, array $args = array() ): object {
		$GLOBALS['connectlibrary_test_post_types'][ $post_type ] = $args;

		return (object) array(
			'name' => $post_type,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( string $post_type ): bool {
		return array_key_exists( $post_type, $GLOBALS['connectlibrary_test_post_types'] );
	}
}

if ( ! function_exists( 'register_taxonomy' ) ) {
	function register_taxonomy( string $taxonomy, array|string $object_type, array $args = array() ): object {
		$object_types = is_array( $object_type ) ? $object_type : array( $object_type );

		$GLOBALS['connectlibrary_test_taxonomies'][ $taxonomy ] = array(
			'object_type' => $object_types,
			'args'        => $args,
		);

		return (object) array(
			'name'        => $taxonomy,
			'object_type' => $object_types,
			'args'        => $args,
		);
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( string $taxonomy ): bool {
		return array_key_exists( $taxonomy, $GLOBALS['connectlibrary_test_taxonomies'] );
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( bool $hard = true ): void {
		++$GLOBALS['connectlibrary_test_flush_count'];
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $query_var, mixed $default_value = '' ): mixed {
		return $GLOBALS['connectlibrary_test_query_vars'][ $query_var ] ?? $default_value;
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {
		++$GLOBALS['connectlibrary_test_nocache_headers'];
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['connectlibrary_test_options'][ $option ] = array(
			'value'    => $value,
			'autoload' => $autoload,
		);

		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return $GLOBALS['connectlibrary_test_options'][ $option ]['value'] ?? $default;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, mixed $value = '', string $deprecated = '', mixed $autoload = null ): bool {
		if ( array_key_exists( $option, $GLOBALS['connectlibrary_test_options'] ) ) {
			return false;
		}

		$GLOBALS['connectlibrary_test_options'][ $option ] = array(
			'value'    => $value,
			'autoload' => $autoload,
		);

		return true;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
		return 'Connect Community Church';
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( mixed $email ): string|false {
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? (string) $email : false;
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( mixed $email ): string {
		return filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( mixed $value ): string {
		return trim( preg_replace( '/<[^>]*>/', '', (string) $value ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( mixed $title ): string {
		$title = strtolower( trim( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( mixed $url ): string {
		return filter_var( (string) $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		unset( $special_chars, $extra_special_chars );
		return substr( str_repeat( 'a', max( 1, $length ) ), 0, max( 1, $length ) );
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( string|array $to, string $subject, string $message, string|array $headers = array(), array $attachments = array() ): bool {
		$GLOBALS['connectlibrary_test_mail'][] = compact( 'to', 'subject', 'message', 'headers', 'attachments' );

		return empty( $GLOBALS['connectlibrary_test_mail_should_fail'] );
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = array() ): int|false {
		unset( $args );
		foreach ( $GLOBALS['connectlibrary_test_cron_events'] as $event ) {
			if ( $hook === (string) ( $event['hook'] ?? '' ) ) {
				return (int) $event['timestamp'];
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array(), bool $wp_error = false ): bool {
		unset( $wp_error );
		$GLOBALS['connectlibrary_test_cron_events'][] = compact( 'timestamp', 'recurrence', 'hook', 'args' );

		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( int $timestamp, string $hook, array $args = array(), bool $wp_error = false ): bool {
		unset( $args, $wp_error );
		$GLOBALS['connectlibrary_test_cron_events'] = array_values(
			array_filter(
				$GLOBALS['connectlibrary_test_cron_events'],
				static fn ( array $event ): bool => (int) $event['timestamp'] !== $timestamp || (string) $event['hook'] !== $hook
			)
		);

		return true;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $post_id ): string|false {
		return $GLOBALS['connectlibrary_test_posts'][ $post_id ] ?? false;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false, bool $fire_after_hooks = true ): int {
		unset( $wp_error, $fire_after_hooks );

		$post_id   = count( $GLOBALS['connectlibrary_test_post_objects'] ) + 1;
		$post_type = (string) ( $postarr['post_type'] ?? 'post' );
		$post      = (object) array(
			'ID'           => $post_id,
			'post_type'    => $post_type,
			'post_status'  => (string) ( $postarr['post_status'] ?? 'draft' ),
			'post_title'   => (string) ( $postarr['post_title'] ?? '' ),
			'post_name'    => (string) ( $postarr['post_name'] ?? '' ),
			'post_content' => (string) ( $postarr['post_content'] ?? '' ),
		);

		$GLOBALS['connectlibrary_test_posts'][ $post_id ]        = $post_type;
		$GLOBALS['connectlibrary_test_post_objects'][ $post_id ] = $post;

		return $post_id;
	}
}

if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $page_path, string $output = OBJECT, string|array $post_type = 'page' ): object|null {
		unset( $output );
		foreach ( $GLOBALS['connectlibrary_test_post_objects'] as $post ) {
			if ( (string) $post->post_name === $page_path && in_array( (string) $post->post_type, (array) $post_type, true ) ) {
				return $post;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = array() ): array {
		$results = array();
		foreach ( $GLOBALS['connectlibrary_test_post_objects'] as $post_id => $post ) {
			if ( isset( $args['post_type'] ) && (string) $post->post_type !== (string) $args['post_type'] ) {
				continue;
			}
			if ( isset( $args['post_status'] ) ) {
				$statuses = (array) $args['post_status'];
				if ( ! in_array( 'any', $statuses, true ) && ! in_array( (string) $post->post_status, $statuses, true ) ) {
					continue;
				}
			}
			if ( isset( $args['meta_key'], $args['meta_value'] ) && (string) ( $GLOBALS['connectlibrary_test_post_meta'][ $post_id ][ $args['meta_key'] ] ?? '' ) !== (string) $args['meta_value'] ) {
				continue;
			}
			$results[] = ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) ? (int) $post_id : $post;
		}

		return $results;
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( int $post_id, string $taxonomy ): array|false {
		$terms = $GLOBALS['connectlibrary_test_object_terms'][ $post_id ][ $taxonomy ] ?? array();

		return is_array( $terms ) && array() !== $terms ? $terms : false;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int|object|null $post = null ): object|null {
		if ( is_object( $post ) ) {
			return $post;
		}

		$post_id = absint( $post );

		return $GLOBALS['connectlibrary_test_post_objects'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $post_id ): string {
		return 'https://example.test/?p=' . $post_id;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( int $post_id ): int|false {
		return false;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( int $post_id ): int|false {
		return false;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		return '2026-06-19 12:00:00';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( int $post_id ): int|false {
		$attachment_id = (int) ( $GLOBALS['connectlibrary_test_post_meta'][ $post_id ]['_thumbnail_id'] ?? 0 );

		return $attachment_id > 0 ? $attachment_id : false;
	}
}

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( int $post_id, int $thumbnail_id ): int|bool {
		$GLOBALS['connectlibrary_test_post_meta'][ $post_id ]['_thumbnail_id'] = $thumbnail_id;

		return true;
	}
}

if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( int $attachment_id, string|array $size = 'thumbnail', bool $icon = false ): string|false {
		unset( $icon );
		$attachment = $GLOBALS['connectlibrary_test_attachments'][ $attachment_id ] ?? array();
		if ( is_string( $size ) && 'full' !== $size ) {
			return ! empty( $attachment['sizes'][ $size ] ) ? (string) $attachment['sizes'][ $size ] : false;
		}

		return isset( $attachment['url'] ) ? (string) $attachment['url'] : false;
	}
}

if ( ! function_exists( 'register_post_meta' ) ) {
	function register_post_meta( string $post_type, string $meta_key, array $args ): bool {
		$GLOBALS['connectlibrary_test_registered_meta'][ $post_type ][ $meta_key ] = $args;

		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		if ( '' === $key ) {
			return $GLOBALS['connectlibrary_test_post_meta'][ $post_id ] ?? array();
		}

		$value = $GLOBALS['connectlibrary_test_post_meta'][ $post_id ][ $key ] ?? '';

		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, mixed $meta_value ): int|bool {
		$GLOBALS['connectlibrary_test_post_meta'][ $post_id ][ $meta_key ] = $meta_value;

		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $meta_key ): bool {
		unset( $GLOBALS['connectlibrary_test_post_meta'][ $post_id ][ $meta_key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $postarr, bool $wp_error = false, bool $fire_after_hooks = true ): int {
		unset( $wp_error, $fire_after_hooks );
		$post_id = absint( $postarr['ID'] ?? 0 );
		if ( $post_id > 0 && isset( $GLOBALS['connectlibrary_test_post_objects'][ $post_id ] ) ) {
			foreach ( $postarr as $key => $value ) {
				if ( 'ID' !== $key ) {
					$GLOBALS['connectlibrary_test_post_objects'][ $post_id ]->{$key} = $value;
				}
			}
		}

		return $post_id;
	}
}

if ( ! function_exists( 'register_rest_field' ) ) {
	function register_rest_field( string|array $object_type, string $attribute, array $args = array() ): void {
		foreach ( (array) $object_type as $type ) {
			$GLOBALS['connectlibrary_test_rest_fields'][ $type ][ $attribute ] = $args;
		}
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $route_namespace, string $route, array $args = array(), bool $override = false ): bool {
		unset( $override );

		$GLOBALS['connectlibrary_test_rest_routes'][ trim( $route_namespace, '/' ) . '/' . trim( $route, '/' ) ] = $args;

		return true;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( mixed $response ): WP_REST_Response {
		return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( $response );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null ): string|false {
		$GLOBALS['connectlibrary_test_admin_pages'][ $menu_slug ] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'callback' );

		return $menu_slug;
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( int|string $action = -1, string $query_arg = '_wpnonce' ): int|false {
		$nonce = isset( $_POST[ $query_arg ] ) ? sanitize_text_field( wp_unslash( $_POST[ $query_arg ] ) ) : '';

		return wp_verify_nonce( $nonce, (string) $action );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( int|string $action = -1, string $query_arg = '_ajax_nonce', bool $die = true ): int|false {
		return 1;
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( mixed $data = null, ?int $status_code = null ): void {
		$GLOBALS['connectlibrary_test_json_success'] = $data;
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( mixed $data = null, ?int $status_code = null ): void {
		$GLOBALS['connectlibrary_test_json_error'] = $data;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): bool {
		$GLOBALS['connectlibrary_test_safe_redirect'] = compact( 'location', 'status', 'x_redirect_by' );

		return true;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( string|array $args, string $value_or_url = '', string $url = '' ): string {
		if ( is_string( $args ) ) {
			$query_args = array( $args => $value_or_url );
			$base_url   = $url;
		} else {
			$query_args = $args;
			$base_url   = $value_or_url;
		}
		$separator = str_contains( $base_url, '?' ) ? '&' : '?';

		return $base_url . $separator . http_build_query( $query_args );
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( string|array $key, string $url = '' ): string {
		if ( '' === $url ) {
			$url = 'https://example.test/';
		}
		$keys = (array) $key;
		if ( str_contains( $url, '?' ) ) {
			[ $base, $query ] = explode( '?', $url, 2 );
			parse_str( $query, $params );
			foreach ( $keys as $k ) {
				unset( $params[ $k ] );
			}
			return $base . ( empty( $params ) ? '' : '?' . http_build_query( $params ) );
		}
		return $url;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ): array|WP_Error {
		$GLOBALS['connectlibrary_test_http_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		foreach ( $GLOBALS['connectlibrary_test_http_responses'] as $needle => $response ) {
			if ( str_contains( $url, (string) $needle ) ) {
				return $response;
			}
		}

		return new WP_Error( 'http_request_failed', 'No test HTTP response registered.' );
	}
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( string $url, array $args = array() ): array|WP_Error {
		return wp_remote_get( $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( array $response, string $header ): string {
		$headers = array_change_key_case( is_array( $response['headers'] ?? null ) ? $response['headers'] : array(), CASE_LOWER );

		return (string) ( $headers[ strtolower( $header ) ] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( string $filename = '', string $dir = '' ): string|false {
		unset( $filename );
		$dir = '' !== $dir ? $dir : sys_get_temp_dir();

		return tempnam( $dir, 'connectlibrary-cover-' );
	}
}

if ( ! function_exists( 'media_handle_sideload' ) ) {
	function media_handle_sideload( array $file_array, int $post_id = 0, string $desc = '', array $post_data = array() ): int|WP_Error {
		unset( $post_data );
		if ( ! empty( $file_array['error'] ) ) {
			return new WP_Error( 'upload_error', 'Upload failed.' );
		}

		$attachment_id                            = 1001 + count( $GLOBALS['connectlibrary_test_attachments'] ?? array() );
		$GLOBALS['connectlibrary_test_uploads'][] = $file_array;
		$GLOBALS['connectlibrary_test_attachments'][ $attachment_id ] = array(
			'file'   => $file_array,
			'parent' => $post_id,
			'title'  => $desc,
			'url'    => 'https://example.test/wp-content/uploads/' . basename( (string) ( $file_array['name'] ?? 'cover.jpg' ) ),
			'alt'    => '',
			'sizes'  => array(),
		);

		return $attachment_id;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ): mixed {
		return $GLOBALS['connectlibrary_test_transients'][ $transient ]['value'] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['connectlibrary_test_transients'][ $transient ] = compact( 'value', 'expiration' );

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['connectlibrary_test_transients'][ $transient ] );

		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, mixed ...$args ): bool {
		$key = '' === $capability ? '' : $capability;
		if ( isset( $args[0] ) ) {
			$key .= ':' . (string) $args[0];
		}

		if ( array_key_exists( $key, $GLOBALS['connectlibrary_test_current_user_can'] ?? array() ) ) {
			return (bool) $GLOBALS['connectlibrary_test_current_user_can'][ $key ];
		}
		if ( array_key_exists( $capability, $GLOBALS['connectlibrary_test_current_user_can'] ?? array() ) ) {
			return (bool) $GLOBALS['connectlibrary_test_current_user_can'][ $capability ];
		}

		return true;
	}
}

if ( ! class_exists( 'ConnectLibrary_Test_Role' ) ) {
	class ConnectLibrary_Test_Role {
		/**
		 * Role capabilities.
		 *
		 * @var array<string,bool>
		 */
		private array $capabilities;

		/**
		 * @param array<string,bool> $capabilities Initial capabilities.
		 */
		public function __construct( array $capabilities = array() ) {
			$this->capabilities = $capabilities;
		}

		public function add_cap( string $capability, bool $grant = true ): void {
			$this->capabilities[ $capability ] = $grant;
		}

		public function has_cap( string $capability ): bool {
			return (bool) ( $this->capabilities[ $capability ] ?? false );
		}
	}
}

if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ): ?ConnectLibrary_Test_Role {
		return $GLOBALS['connectlibrary_test_roles'][ $role ] ?? null;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['connectlibrary_test_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_create_user' ) ) {
	function wp_create_user( string $username, string $password, string $email = '' ): int|WP_Error {
		$GLOBALS['connectlibrary_test_created_users'][] = compact( 'username', 'password', 'email' );

		$user_id = count( $GLOBALS['connectlibrary_test_created_users'] );
		$GLOBALS['connectlibrary_test_users'][ $user_id ] = (object) array(
			'ID'         => $user_id,
			'user_login' => $username,
			'user_email' => $email,
		);

		return $user_id;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ): object|false {
		return $GLOBALS['connectlibrary_test_users'][ $user_id ] ?? false;
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, int|string $value ): object|false {
		foreach ( $GLOBALS['connectlibrary_test_users'] as $user ) {
			if ( isset( $user->{$field} ) && (string) $user->{$field} === (string) $value ) {
				return $user;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action = '' ): int|false {
		return '' !== $nonce && '' !== $action ? 1 : false;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action, string $name = '_wpnonce' ): void {
		unset( $action, $name );
	}
}

if ( ! function_exists( 'add_meta_box' ) ) {
	function add_meta_box( string $id, string $title, callable $callback, string|array|null $screen = null, string $context = 'advanced', string $priority = 'default' ): void {
		$GLOBALS['connectlibrary_test_meta_boxes'][ $id ] = compact( 'title', 'callback', 'screen', 'context', 'priority' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
		$result = (string) $selected === (string) $current ? 'selected="selected"' : '';
		if ( $display ) {
			echo $result;
		}

		return $result;
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
		$result = (bool) $checked === (bool) $current ? 'checked="checked"' : '';
		if ( $display ) {
			echo $result;
		}

		return $result;
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'add_settings_error' ) ) {
	function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
		$GLOBALS['connectlibrary_test_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( string $sql ): array {
		$GLOBALS['connectlibrary_test_dbdelta'][] = $sql;

		if ( preg_match( '/CREATE TABLE\s+([^\s(]+)/i', $sql, $matches ) ) {
			$GLOBALS['connectlibrary_test_db_tables'][ trim( $matches[1], '`' ) ] = $sql;
		}

		return array();
	}
}

if ( ! class_exists( 'ConnectLibrary_Test_WPDB' ) ) {
	class ConnectLibrary_Test_WPDB {
		/**
		 * Test database prefix.
		 *
		 * @var string
		 */
		public string $prefix = 'wp_test_';

		/**
		 * Test posts table name.
		 *
		 * @var string
		 */
		public string $posts = 'wp_test_posts';

		/**
		 * Test post meta table name.
		 *
		 * @var string
		 */
		public string $postmeta = 'wp_test_postmeta';

		/**
		 * Return a realistic charset/collation clause.
		 */
		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		/** Last inserted ID. */
		public int $insert_id = 0;

		/** Minimal prepare implementation for tests. */
		public function prepare( string $query, mixed ...$args ): string {
			$index = 0;

			return preg_replace_callback(
				'/%[ds]/',
				static function ( array $matches ) use ( $args, &$index ): string {
					$arg = $args[ $index ] ?? '';
					++$index;

					if ( '%d' === $matches[0] ) {
						return (string) (int) $arg;
					}

					return "'" . addslashes( (string) $arg ) . "'";
				},
				$query
			) ?? $query;
		}

		/** Escape text for LIKE clauses. */
		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		/** Execute a minimal subset of SQL used by repository unit tests. */
		public function query( string $query ): int|bool {
			$normalized = strtoupper( trim( $query ) );

			if ( 'START TRANSACTION' === $normalized ) {
				$GLOBALS['connectlibrary_test_db_transaction_snapshot'] = unserialize( serialize( $GLOBALS['connectlibrary_test_db_tables'] ) );
				return true;
			}

			if ( 'ROLLBACK' === $normalized ) {
				if ( isset( $GLOBALS['connectlibrary_test_db_transaction_snapshot'] ) ) {
					$GLOBALS['connectlibrary_test_db_tables'] = $GLOBALS['connectlibrary_test_db_transaction_snapshot'];
					unset( $GLOBALS['connectlibrary_test_db_transaction_snapshot'] );
				}
				return true;
			}

			if ( 'COMMIT' === $normalized ) {
				unset( $GLOBALS['connectlibrary_test_db_transaction_snapshot'] );
				return true;
			}

			if ( str_starts_with( $normalized, 'UPDATE ' ) ) {
				// Checkout UPDATE modifies circulation_status on the copies table.
				if ( false !== stripos( $query, 'circulation_status' ) ) {
					return $this->guarded_copy_checkout_update( $query );
				}
				if ( false === stripos( $query, 'last_renewed_at' ) ) {
					return $this->guarded_loan_due_change_update( $query );
				}
				return $this->guarded_loan_renewal_update( $query );
			}

			return false;
		}

		/**
		 * Apply the guarded atomic copy checkout UPDATE.
		 *
		 * Matches: UPDATE {copies} SET circulation_status = 'checked_out', updated_at = '...'
		 *           WHERE id = N AND book_post_id = N AND circulation_status = 'available|on_hold'
		 */
		private function guarded_copy_checkout_update( string $query ): int {
			if ( array_key_exists( 'guarded_copy_checkout_update', $GLOBALS['connectlibrary_test_db_query_results'] ) ) {
				return (int) $GLOBALS['connectlibrary_test_db_query_results']['guarded_copy_checkout_update'];
			}

			if ( ! preg_match( '/UPDATE\s+([^\s]+).*?WHERE\s+id\s*=\s*(\d+)\s+AND\s+book_post_id\s*=\s*(\d+)\s+AND\s+circulation_status\s*=\s*\'([^\']*)\'/is', $query, $matches ) ) {
				return 0;
			}

			$table        = trim( $matches[1], '`' );
			$copy_id      = (int) $matches[2];
			$book_post_id = (int) $matches[3];
			$claim_status = stripslashes( $matches[4] );
			$rows         = &$GLOBALS['connectlibrary_test_db_tables'][ $table . ':rows' ];
			$affected     = 0;

			if ( ! is_array( $rows ?? null ) ) {
				return 0;
			}

			preg_match( "/updated_at\s*=\s*'([^']+)'/i", $query, $time_match );
			$updated_at = $time_match[1] ?? '2026-06-19 12:00:00';

			foreach ( $rows as &$row ) {
				if (
					(int) ( $row['id'] ?? 0 ) === $copy_id
					&& (int) ( $row['book_post_id'] ?? 0 ) === $book_post_id
					&& $claim_status === (string) ( $row['circulation_status'] ?? '' )
				) {
					$row['circulation_status'] = 'checked_out';
					$row['updated_at']         = $updated_at;
					++$affected;
				}
			}

			return $affected;
		}

		/** Apply the guarded atomic loan renewal UPDATE used by LoanRepository. */
		private function guarded_loan_renewal_update( string $query ): int {
			if ( array_key_exists( 'guarded_loan_renewal_update', $GLOBALS['connectlibrary_test_db_query_results'] ) ) {
				return (int) $GLOBALS['connectlibrary_test_db_query_results']['guarded_loan_renewal_update'];
			}

			if ( ! preg_match( '/UPDATE\s+([^\s]+).*?due_at\s*=\s*\'([^\']*)\'.*?last_renewed_at\s*=\s*\'([^\']*)\'.*?updated_at\s*=\s*\'([^\']*)\'.*?WHERE\s+id\s*=\s*(\d+).*?borrower_id\s*=\s*(\d+).*?status\s*=\s*\'([^\']*)\'/is', $query, $matches ) ) {
				return 0;
			}

			$table       = trim( $matches[1], '`' );
			$new_due_at  = stripslashes( $matches[2] );
			$renewed_at  = stripslashes( $matches[3] );
			$updated_at  = stripslashes( $matches[4] );
			$loan_id     = (int) $matches[5];
			$borrower_id = (int) $matches[6];
			$status      = stripslashes( $matches[7] );
			$rows        = &$GLOBALS['connectlibrary_test_db_tables'][ $table . ':rows' ];
			$affected    = 0;

			if ( ! is_array( $rows ?? null ) ) {
				return 0;
			}

			foreach ( $rows as &$row ) {
				if (
					(int) ( $row['id'] ?? 0 ) === $loan_id
					&& (int) ( $row['borrower_id'] ?? 0 ) === $borrower_id
					&& (string) ( $row['status'] ?? '' ) === $status
					&& (int) ( $row['renewal_count'] ?? 0 ) < (int) ( $row['renewal_limit'] ?? 0 )
				) {
					$row['due_at']          = $new_due_at;
					$row['renewal_count']   = (int) ( $row['renewal_count'] ?? 0 ) + 1;
					$row['last_renewed_at'] = $renewed_at;
					$row['updated_at']      = $updated_at;
					++$affected;
				}
			}

			return $affected;
		}

		/** Apply the guarded atomic loan due-date UPDATE used by LoanRepository. */
		private function guarded_loan_due_change_update( string $query ): int {
			if ( array_key_exists( 'guarded_loan_due_change_update', $GLOBALS['connectlibrary_test_db_query_results'] ) ) {
				return (int) $GLOBALS['connectlibrary_test_db_query_results']['guarded_loan_due_change_update'];
			}

			if ( ! preg_match( '/UPDATE\s+([^\s]+).*?due_at\s*=\s*\'([^\']*)\'.*?updated_at\s*=\s*\'([^\']*)\'.*?WHERE\s+id\s*=\s*(\d+).*?status\s*=\s*\'([^\']*)\'/is', $query, $matches ) ) {
				return 0;
			}

			$table      = trim( $matches[1], '`' );
			$new_due_at = stripslashes( $matches[2] );
			$updated_at = stripslashes( $matches[3] );
			$loan_id    = (int) $matches[4];
			$status     = stripslashes( $matches[5] );
			$rows       = &$GLOBALS['connectlibrary_test_db_tables'][ $table . ':rows' ];
			$affected   = 0;

			if ( ! is_array( $rows ?? null ) ) {
				return 0;
			}

			foreach ( $rows as &$row ) {
				if (
					(int) ( $row['id'] ?? 0 ) === $loan_id
					&& (string) ( $row['status'] ?? '' ) === $status
				) {
					$row['due_at']     = $new_due_at;
					$row['updated_at'] = $updated_at;
					++$affected;
				}
			}

			return $affected;
		}

		/** Insert a row into the fake table store. */
		public function insert( string $table, array $data ): bool {
			if ( ! empty( $GLOBALS['connectlibrary_test_db_insert_failures'][ $table . ':rows' ] ) ) {
				$this->insert_id = 0;
				return false;
			}

			$rows = &$GLOBALS['connectlibrary_test_db_tables'][ $table . ':rows' ];
			if ( ! is_array( $rows ?? null ) ) {
				$rows = array();
			}

			if ( ! isset( $data['id'] ) && ( str_ends_with( $table, '_authors' ) || str_ends_with( $table, '_series' ) || str_ends_with( $table, '_copies' ) || str_ends_with( $table, '_import_sources' ) || str_ends_with( $table, '_borrowers' ) || str_ends_with( $table, '_borrower_audit' ) || str_ends_with( $table, '_guest_access_tokens' ) || str_ends_with( $table, '_borrower_cards' ) || str_ends_with( $table, '_reservations' ) || str_ends_with( $table, '_reservation_audit' ) || str_ends_with( $table, '_loans' ) || str_ends_with( $table, '_loan_audit' ) || str_ends_with( $table, '_audit_events' ) ) ) {
				$data['id'] = count( $rows ) + 1;
			}
			$this->insert_id = (int) ( $data['id'] ?? 0 );
			$rows[]          = $data;

			return true;
		}

		/** Update rows in the fake table store. */
		public function update( string $table, array $data, array $where ): bool {
			$rows = &$GLOBALS['connectlibrary_test_db_tables'][ $table . ':rows' ];
			if ( ! is_array( $rows ?? null ) ) {
				return false;
			}

			foreach ( $rows as &$row ) {
				if ( $this->row_matches( $row, $where ) ) {
					$row = array_merge( $row, $data );
				}
			}

			return true;
		}

		/** Delete rows from the fake table store. */
		public function delete( string $table, array $where ): bool {
			$rows = &$GLOBALS['connectlibrary_test_db_tables'][ $table . ':rows' ];
			if ( ! is_array( $rows ?? null ) ) {
				return true;
			}

			$rows = array_values(
				array_filter(
					$rows,
					fn ( array $row ): bool => ! $this->row_matches( $row, $where )
				)
			);

			return true;
		}

		/** Fetch the first matching row. */
		public function get_row( string $query, string $output = OBJECT ): array|object|null {
			$rows = $this->select_rows( $query );
			$row  = $rows[0] ?? null;
			if ( null === $row ) {
				return null;
			}

			return ARRAY_A === $output ? $row : (object) $row;
		}

		/** Fetch the first column of the first matching row. */
		public function get_var( string $query ): mixed {
			$rows = $this->select_rows( $query );
			if ( str_contains( strtoupper( $query ), 'COUNT(*)' ) ) {
				return count( $rows );
			}
			if ( empty( $rows ) ) {
				return null;
			}

			return reset( $rows[0] );
		}

		/** Fetch the first column of all matching rows. */
		public function get_col( string $query ): array {
			$rows   = $this->select_rows( $query );
			$column = '';
			if ( preg_match( '/SELECT\s+([a-zA-Z0-9_]+)\s+FROM/i', $query, $matches ) ) {
				$column = $matches[1];
			}

			return array_map(
				static fn ( array $row ): mixed => '' !== $column && array_key_exists( $column, $row ) ? $row[ $column ] : reset( $row ),
				$rows
			);
		}

		/** Fetch all matching rows. */
		public function get_results( string $query, string $output = OBJECT ): array {
			$rows = $this->select_rows( $query );
			if ( ARRAY_A === $output ) {
				return $rows;
			}

			return array_map( static fn ( array $row ): object => (object) $row, $rows );
		}

		/** Determine if a fake row matches exact where values. */
		private function row_matches( array $row, array $where ): bool {
			foreach ( $where as $key => $value ) {
				if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
					return false;
				}
			}

			return true;
		}

		/** Very small SQL selector for the repository unit tests. */
		private function select_rows( string $query ): array {
			if ( ! preg_match( '/FROM\s+([^\s]+)/i', $query, $matches ) ) {
				return array();
			}
			$table = trim( $matches[1], '`' );
			$rows  = $GLOBALS['connectlibrary_test_db_tables'][ $table . ':rows' ] ?? array();
			if ( ! is_array( $rows ) ) {
				return array();
			}

			if ( preg_match( '/WHERE\s+book_post_id\s*=\s*(\d+)/i', $query, $where ) ) {
				$book_id = (int) $where[1];
				$rows    = array_values( array_filter( $rows, static fn ( array $row ): bool => (int) ( $row['book_post_id'] ?? 0 ) === $book_id ) );
			}
			if ( preg_match( "/WHERE\s+slug\s*=\s*'([^']+)'/i", $query, $where ) ) {
				$slug = stripslashes( $where[1] );
				$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) ( $row['slug'] ?? '' ) === $slug ) );
			}
			if ( preg_match( "/WHERE\\s+(isbn_10|isbn_13)\\s*=\\s*'([^']*)'/i", $query, $where ) ) {
				$col  = strtolower( $where[1] );
				$val  = stripslashes( $where[2] );
				$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) ( $row[ $col ] ?? '' ) === $val ) );
			}
			$rows = $this->apply_report_where_clauses( $query, $rows );
			$rows = $this->apply_report_ordering( $query, $rows );
			$rows = $this->apply_report_limit_offset( $query, $rows );

			return $rows;
		}

		/** Apply the report WHERE clauses used by bounded repository tests. */
		private function apply_report_where_clauses( string $query, array $rows ): array {
			$checks = array(
				'/\\bid\\s*=\\s*\'?(\\d+)\'?/i'            => static fn ( array $row, string $value ): bool => (string) ( $row['id'] ?? '' ) === $value,
				'/\\bstatus\\s*=\\s*\'([^\']*)\'/i'        => static fn ( array $row, string $value ): bool => (string) ( $row['status'] ?? '' ) === $value,
				'/\\bdue_at\\s*<\\s*\'([^\']*)\'/i'        => static fn ( array $row, string $value ): bool => (string) ( $row['due_at'] ?? '' ) < $value,
				'/\\bdue_at\\s*>=\\s*\'([^\']*)\'/i'       => static fn ( array $row, string $value ): bool => (string) ( $row['due_at'] ?? '' ) >= $value,
				'/\\bdue_at\\s*<=\\s*\'([^\']*)\'/i'       => static fn ( array $row, string $value ): bool => (string) ( $row['due_at'] ?? '' ) <= $value,
				'/\\bcreated_at\\s*>=\\s*\'([^\']*)\'/i'   => static fn ( array $row, string $value ): bool => (string) ( $row['created_at'] ?? '' ) >= $value,
				'/\\bcreated_at\\s*<=\\s*\'([^\']*)\'/i'   => static fn ( array $row, string $value ): bool => (string) ( $row['created_at'] ?? '' ) <= $value,
				'/\\bcreated_at_utc\\s*>=\\s*\'([^\']*)\'/i' => static fn ( array $row, string $value ): bool => (string) ( $row['created_at_utc'] ?? '' ) >= $value,
				'/\\bcreated_at_utc\\s*<=\\s*\'([^\']*)\'/i' => static fn ( array $row, string $value ): bool => (string) ( $row['created_at_utc'] ?? '' ) <= $value,
				'/\\bhold_expires_at\\s*>=\\s*\'([^\']*)\'/i' => static fn ( array $row, string $value ): bool => (string) ( $row['hold_expires_at'] ?? '' ) >= $value,
				'/\\bhold_expires_at\\s*<=\\s*\'([^\']*)\'/i' => static fn ( array $row, string $value ): bool => (string) ( $row['hold_expires_at'] ?? '' ) <= $value,
				'/\\brequested_at\\s*>=\\s*\'([^\']*)\'/i' => static fn ( array $row, string $value ): bool => (string) ( $row['requested_at'] ?? '' ) >= $value,
				'/\\brequested_at\\s*<=\\s*\'([^\']*)\'/i' => static fn ( array $row, string $value ): bool => (string) ( $row['requested_at'] ?? '' ) <= $value,
				'/\\baction\\s*=\\s*\'([^\']*)\'/i'        => static fn ( array $row, string $value ): bool => (string) ( $row['action'] ?? '' ) === $value,
				'/\\bentity_type\\s*=\\s*\'([^\']*)\'/i'   => static fn ( array $row, string $value ): bool => (string) ( $row['entity_type'] ?? '' ) === $value,
				'/\\bentity_id\\s*=\\s*\'([^\']*)\'/i'     => static fn ( array $row, string $value ): bool => (string) ( $row['entity_id'] ?? '' ) === $value,
				'/\\bactor_id\\s*=\\s*\'([^\']*)\'/i'      => static fn ( array $row, string $value ): bool => (string) ( $row['actor_id'] ?? '' ) === $value,
				'/\\bcorrelation_id\\s*=\\s*\'([^\']*)\'/i' => static fn ( array $row, string $value ): bool => (string) ( $row['correlation_id'] ?? '' ) === $value,
				'/`condition`\\s*=\\s*\'([^\']*)\'/i'      => static fn ( array $row, string $value ): bool => (string) ( $row['condition'] ?? '' ) === $value,
			);

			foreach ( $checks as $pattern => $callback ) {
				if ( preg_match( $pattern, $query, $match ) ) {
					$value = stripslashes( $match[1] );
					$rows  = array_values( array_filter( $rows, static fn ( array $row ): bool => $callback( $row, $value ) ) );
				}
			}

			if ( preg_match( "/\\(status\\s*=\\s*'([^']*)'\\s+OR\\s+circulation_status\\s*=\\s*'([^']*)'\\)/i", $query, $match ) ) {
				$status = stripslashes( $match[1] );
				$rows   = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) ( $row['status'] ?? '' ) === $status || (string) ( $row['circulation_status'] ?? '' ) === $status ) );
			}
			if ( preg_match( "/LOWER\\(call_number\\)\\s+LIKE\\s+'%([^']*)%'/i", $query, $match ) ) {
				$needle = strtolower( stripslashes( $match[1] ) );
				$rows   = array_values( array_filter( $rows, static fn ( array $row ): bool => str_contains( strtolower( (string) ( $row['call_number'] ?? '' ) ), $needle ) ) );
			}
			if ( preg_match( "/CAST\\(id AS CHAR\\).*?LIKE\\s+'%([^']*)%'/i", $query, $match ) ) {
				$needle = strtolower( stripslashes( $match[1] ) );
				$rows   = array_values(
					array_filter(
						$rows,
						static function ( array $row ) use ( $needle ): bool {
							$haystack = strtolower( implode( ' ', array( (string) ( $row['id'] ?? '' ), (string) ( $row['book_post_id'] ?? '' ), (string) ( $row['barcode'] ?? '' ), (string) ( $row['call_number'] ?? '' ) ) ) );
							return str_contains( $haystack, $needle );
						}
					)
				);
			}
			if ( preg_match( "/context_json\\s+LIKE\\s+'%.*?\\\"action_group\\\":\\\"([^\\\"]+)\\\".*?%'/i", $query, $match )
				|| preg_match( "/context_json\\s+LIKE\\s+'%.*?\"action_group\":\"([^\"]+)\".*?%'/i", $query, $match )
			) {
				$needle = '"action_group":"' . strtolower( stripslashes( $match[1] ) ) . '"';
				$rows   = array_values( array_filter( $rows, static fn ( array $row ): bool => str_contains( strtolower( (string) ( $row['context_json'] ?? '' ) ), $needle ) ) );
			}

			return $rows;
		}

		/** Apply report ORDER BY clauses used in repository tests. */
		private function apply_report_ordering( string $query, array $rows ): array {
			if ( preg_match( '/ORDER BY\\s+id\\s+DESC/i', $query ) ) {
				usort( $rows, static fn ( array $a, array $b ): int => (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) );
				return $rows;
			}

			$date_key = null;
			if ( preg_match( '/ORDER BY\\s+due_at\\s+ASC/i', $query ) ) {
				$date_key = 'due_at';
			} elseif ( preg_match( '/ORDER BY\\s+hold_expires_at\\s+ASC/i', $query ) ) {
				$date_key = 'hold_expires_at';
			} elseif ( preg_match( '/ORDER BY\\s+requested_at\\s+ASC/i', $query ) ) {
				$date_key = 'requested_at';
			}

			if ( null !== $date_key ) {
				usort(
					$rows,
					static function ( array $a, array $b ) use ( $date_key ): int {
						$date_cmp = strcmp( (string) ( $a[ $date_key ] ?? '' ), (string) ( $b[ $date_key ] ?? '' ) );
						return 0 !== $date_cmp ? $date_cmp : (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
					}
				);
				return $rows;
			}

			if ( preg_match( '/ORDER BY\\s+COALESCE\\(status, circulation_status/i', $query ) ) {
				usort(
					$rows,
					static function ( array $a, array $b ): int {
						$status_cmp = strcmp( (string) ( $a['status'] ?? $a['circulation_status'] ?? '' ), (string) ( $b['status'] ?? $b['circulation_status'] ?? '' ) );
						if ( 0 !== $status_cmp ) {
							return $status_cmp;
						}
						$call_cmp = strcmp( (string) ( $a['call_number'] ?? '' ), (string) ( $b['call_number'] ?? '' ) );
						return 0 !== $call_cmp ? $call_cmp : (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
					}
				);
			}

			return $rows;
		}

		/** Apply LIMIT/OFFSET clauses used in repository tests. */
		private function apply_report_limit_offset( string $query, array $rows ): array {
			if ( preg_match( '/LIMIT\\s+(\\d+)\\s+OFFSET\\s+(\\d+)/i', $query, $match ) ) {
				return array_slice( $rows, (int) $match[2], (int) $match[1] );
			}

			return $rows;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		public string $post_type;

		public function __construct( int $id = 0, string $post_type = '' ) {
			$this->ID        = $id;
			$this->post_type = $post_type;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** Error code. */
		private string $code;

		/** Error message. */
		private string $message;

		/** Error data. */
		private mixed $data;

		/** Create a test error object. */
		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/** Get the error code. */
		public function get_error_code(): string {
			return $this->code;
		}

		/** Get the error message. */
		public function get_error_message(): string {
			return $this->message;
		}

		/** Get the error data. */
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		/** Response data. */
		private mixed $data;

		/** Response headers. */
		private array $headers = array();

		/** Create a test REST response. */
		public function __construct( mixed $data = null ) {
			$this->data = $data;
		}

		/** Get response data. */
		public function get_data(): mixed {
			return $this->data;
		}

		/** Set a response header. */
		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		/** Get response headers. */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		/** Request parameters. */
		private array $params;

		/** Request method. */
		private string $method = 'GET';

		/** Request route. */
		private string $route;

		/** Create a test REST request. */
		public function __construct( array|string $params = array(), string $route = '', array $attributes = array() ) {
			if ( is_string( $params ) ) {
				$this->method = strtoupper( $params );
				$this->route  = $route;
				$this->params = $attributes;

				return;
			}

			$this->params = $params;
			$this->route  = $route;
		}

		/** Get the HTTP method for the REST request. */
		public function get_method(): string {
			return $this->method;
		}

		/** Set the HTTP method for the REST request. */
		public function set_method( string $method ): void {
			$this->method = strtoupper( $method );
		}

		/** Get the matched REST route. */
		public function get_route(): string {
			return $this->route;
		}

		/** Whether a parameter exists. */
		public function offsetExists( mixed $offset ): bool {
			return array_key_exists( (string) $offset, $this->params );
		}

		/** Get a request parameter. */
		public function offsetGet( mixed $offset ): mixed {
			return $this->params[ (string) $offset ] ?? null;
		}

		/** Set a request parameter. */
		public function offsetSet( mixed $offset, mixed $value ): void {
			$this->params[ (string) $offset ] = $value;
		}

		/** Remove a request parameter. */
		public function offsetUnset( mixed $offset ): void {
			unset( $this->params[ (string) $offset ] );
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		/**
		 * Query variables.
		 *
		 * @var array<string,mixed>
		 */
		private array $vars;

		/**
		 * Create test query object.
		 *
		 * @param array<string,mixed> $vars Query variables.
		 */
		public function __construct( array $vars = array() ) {
			$this->vars = $vars;
		}

		/**
		 * Read a query variable.
		 *
		 * @param string $key Query variable key.
		 * @param mixed  $default Default value.
		 */
		public function get( string $key, mixed $default = '' ): mixed {
			return $this->vars[ $key ] ?? $default;
		}

		/**
		 * Set a query variable.
		 *
		 * @param string $key Query variable key.
		 * @param mixed  $value Query variable value.
		 */
		public function set( string $key, mixed $value ): void {
			$this->vars[ $key ] = $value;
		}
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int $post_id = 0 ): string {
		$post = $GLOBALS['connectlibrary_test_post_objects'][ $post_id ] ?? null;
		if ( null === $post ) {
			return 'Library item';
		}
		$title = isset( $post->post_title ) ? (string) $post->post_title : '';
		return '' !== $title ? $title : 'Library item';
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '' ): string {
		return '' !== $action ? $action : 'nonce';
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( mixed $url, mixed $protocols = null, string $_context = 'display' ): string {
		unset( $protocols, $_context );
		return filter_var( (string) $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return esc_attr( $text );
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( object $term, string $taxonomy = '' ): string {
		$slug = isset( $term->slug ) ? (string) $term->slug : '';
		return 'https://example.test/' . sanitize_key( $taxonomy ) . '/' . $slug . '/';
	}
}

if ( ! function_exists( 'wp_get_attachment_image' ) ) {
	function wp_get_attachment_image( int $attachment_id, mixed $size = 'thumbnail', bool $icon = false, array $attr = array() ): string {
		unset( $size, $icon );
		$attachment = $GLOBALS['connectlibrary_test_attachments'][ $attachment_id ] ?? array();
		if ( empty( $attachment ) ) {
			return '';
		}
		$url   = esc_attr( (string) ( $attachment['url'] ?? '' ) );
		$alt   = isset( $attr['alt'] ) ? esc_attr( (string) $attr['alt'] ) : esc_attr( (string) ( $attachment['alt'] ?? '' ) );
		$class = isset( $attr['class'] ) ? esc_attr( (string) $attr['class'] ) : '';
		if ( '' === $url ) {
			return '';
		}
		return '<img src="' . $url . '" alt="' . $alt . '" class="' . $class . '">';
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( mixed $message = '', mixed $title = '', mixed $args = array() ): void {
		$args                                  = is_array( $args ) ? $args : array( 'response' => (int) $args );
		$GLOBALS['connectlibrary_test_wp_die'] = array(
			'message'  => $message,
			'title'    => $title,
			'response' => (int) ( $args['response'] ?? 500 ),
		);
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), mixed $ver = false, string $media = 'all' ): void {
		$GLOBALS['connectlibrary_test_enqueued_styles'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' );
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( mixed $post_types = '' ): bool {
		return (bool) ( $GLOBALS['connectlibrary_test_is_singular'] ?? false );
	}
}

if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID(): int|false {
		$id = (int) ( $GLOBALS['connectlibrary_test_current_post_id'] ?? 0 );
		return $id > 0 ? $id : false;
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( string $tag, callable $callback ): void {
		$GLOBALS['connectlibrary_test_shortcodes'][ $tag ] = $callback;
	}
}

if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( string $content ): string {
		return $content;
	}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( array $pairs, array $atts, string $shortcode = '' ): array {
		unset( $shortcode );
		$out = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
}

if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( string $name, array $args = array() ): object {
		$GLOBALS['connectlibrary_test_blocks'][ $name ] = $args;
		return (object) array(
			'name' => $name,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( string $handle, string $src = '', array $deps = array(), mixed $ver = false, bool $in_footer = false ): bool {
		$GLOBALS['connectlibrary_test_registered_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), mixed $ver = false, bool $in_footer = false ): void {
		$GLOBALS['connectlibrary_test_enqueued_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( string $handle, string $src = '', array $deps = array(), mixed $ver = false, string $media = 'all' ): bool {
		$GLOBALS['connectlibrary_test_registered_styles'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' );
		return true;
	}
}

if ( ! function_exists( 'wp_script_add_data' ) ) {
	function wp_script_add_data( string $handle, string $key, mixed $value ): bool {
		$GLOBALS['connectlibrary_test_script_data'][ $handle ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( string $path = '', string $plugin = '' ): string {
		$base = 'https://example.test/wp-content/plugins/';
		if ( '' !== $plugin ) {
			$base .= basename( dirname( $plugin ) ) . '/';
		}
		return $base . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'register_block_variation' ) ) {
	function register_block_variation( string $block_name, array $variation ): void {
		$GLOBALS['connectlibrary_test_block_variations'][ $block_name ][] = $variation;
	}
}

$GLOBALS['wpdb'] = new ConnectLibrary_Test_WPDB();

require_once dirname( __DIR__ ) . '/connectlibrary.php';
