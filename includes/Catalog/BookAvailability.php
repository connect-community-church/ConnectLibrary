<?php
/**
 * WordPress integration for public book availability.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

use ConnectLibrary\Support\Statuses;
use WP_Error;
use WP_Query;
use WP_REST_Request;

/**
 * Registers title availability storage, admin controls, REST fields, and query hooks.
 */
final class BookAvailability {
	/**
	 * Register availability hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_public_queries' ) );
		add_filter( 'posts_clauses', array( $this, 'sort_public_queries_by_availability' ), 10, 2 );
		add_filter( 'rest_' . BookPostType::POST_TYPE . '_query', array( $this, 'filter_public_rest_query' ), 10, 2 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'block_hidden_rest_item_request' ), 10, 3 );
		add_action( 'add_meta_boxes_' . BookPostType::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . BookPostType::POST_TYPE, array( $this, 'save_meta_box' ) );
	}

	/**
	 * Register Phase 1 manual availability and public visibility post meta.
	 */
	public function register_meta(): void {
		register_post_meta(
			BookPostType::POST_TYPE,
			Availability::META_STATUS,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => Statuses::AVAILABILITY_AVAILABLE,
				'sanitize_callback' => array( Availability::class, 'normalize_status' ),
				'auth_callback'     => static fn(): bool => current_user_can( 'edit_posts' ),
				'show_in_rest'      => false,
			)
		);

		register_post_meta(
			BookPostType::POST_TYPE,
			Availability::META_VISIBILITY,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => Statuses::VISIBILITY_PUBLIC,
				'sanitize_callback' => array( Availability::class, 'normalize_visibility' ),
				'auth_callback'     => static fn(): bool => current_user_can( 'edit_posts' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Register safe public REST availability fields.
	 */
	public function register_rest_fields(): void {
		register_rest_field(
			BookPostType::POST_TYPE,
			'availability',
			array(
				'get_callback' => static function ( array $rest_object ): array {
					$post_id = isset( $rest_object['id'] ) ? absint( $rest_object['id'] ) : 0;

					return Availability::for_book( $post_id );
				},
				'schema'       => array(
					'description' => __( 'Privacy-safe public availability status for the library title.', 'connectlibrary' ),
					'type'        => 'object',
					'context'     => array( 'view', 'embed' ),
					'properties'  => array(
						'status'         => array(
							'type' => 'string',
							'enum' => Statuses::availability_statuses(),
						),
						'label'          => array(
							'type' => 'string',
						),
						'request_action' => array(
							'type' => 'string',
							'enum' => array( 'reserve', 'waitlist', 'contact_librarian', 'none' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Add the librarian-facing Phase 1 availability meta box.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'connectlibrary-book-availability',
			__( 'Public Catalog Availability', 'connectlibrary' ),
			array( $this, 'render_meta_box' ),
			BookPostType::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the availability meta box.
	 *
	 * @param object $post WordPress post object.
	 */
	public function render_meta_box( object $post ): void {
		$post_id    = isset( $post->ID ) ? absint( $post->ID ) : 0;
		$raw_status = get_post_meta( $post_id, Availability::META_STATUS, true );
		$status     = '' === $raw_status ? Statuses::AVAILABILITY_AVAILABLE : Availability::normalize_status( $raw_status );
		$visibility = Availability::normalize_visibility( get_post_meta( $post_id, Availability::META_VISIBILITY, true ) );
		$labels     = Availability::labels();

		wp_nonce_field( 'connectlibrary_book_availability', 'connectlibrary_book_availability_nonce' );
		?>
		<p><?php esc_html_e( 'Public catalog status shown until full circulation is implemented.', 'connectlibrary' ); ?></p>
		<p>
			<label for="connectlibrary-public-availability"><?php esc_html_e( 'Availability', 'connectlibrary' ); ?></label>
			<select id="connectlibrary-public-availability" name="connectlibrary_public_availability">
				<?php foreach ( Statuses::availability_statuses() as $availability_status ) : ?>
					<option value="<?php echo esc_attr( $availability_status ); ?>" <?php selected( $status, $availability_status ); ?>><?php echo esc_html( $labels[ $availability_status ] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="connectlibrary-public-visibility"><?php esc_html_e( 'Public visibility', 'connectlibrary' ); ?></label>
			<select id="connectlibrary-public-visibility" name="connectlibrary_public_visibility">
				<option value="public" <?php selected( $visibility, Statuses::VISIBILITY_PUBLIC ); ?>><?php esc_html_e( 'Public', 'connectlibrary' ); ?></option>
				<option value="hidden" <?php selected( $visibility, Statuses::VISIBILITY_HIDDEN ); ?>><?php esc_html_e( 'Hidden', 'connectlibrary' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Persist the Phase 1 manual availability fields.
	 *
	 * @param int $post_id Book post ID.
	 */
	public function save_meta_box( int $post_id ): void {
		$nonce = isset( $_POST['connectlibrary_book_availability_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['connectlibrary_book_availability_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'connectlibrary_book_availability' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$status = isset( $_POST['connectlibrary_public_availability'] ) ? wp_unslash( $_POST['connectlibrary_public_availability'] ) : Statuses::AVAILABILITY_UNAVAILABLE;
		update_post_meta( $post_id, Availability::META_STATUS, Availability::normalize_status( $status ) );

		$visibility = isset( $_POST['connectlibrary_public_visibility'] ) ? wp_unslash( $_POST['connectlibrary_public_visibility'] ) : Statuses::VISIBILITY_PUBLIC;
		update_post_meta( $post_id, Availability::META_VISIBILITY, Availability::normalize_visibility( $visibility ) );
	}

	/**
	 * Apply public visibility and availability filters to public book queries.
	 *
	 * @param WP_Query $query Query object.
	 */
	public function filter_public_queries( WP_Query $query ): void {
		if ( is_admin() || ! $this->is_book_query( $query ) ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query', array() );
		$meta_query[] = $this->public_visibility_clause();

		$availability_filter = Availability::sanitize_filter( $query->get( 'connectlibrary_availability', '' ) );
		if ( array() !== $availability_filter ) {
			$meta_query[] = $this->public_availability_clause( $availability_filter );
		}

		$query->set( 'meta_query', $meta_query );

		if ( 'availability' === $query->get( 'orderby' ) || 'connectlibrary_availability' === $query->get( 'orderby' ) ) {
			$query->set( 'connectlibrary_availability_sort', true );
		}
	}

	/**
	 * Apply the hidden-title exclusion to unauthenticated/public core REST collections.
	 *
	 * @param array<string,mixed> $args Prepared post query arguments.
	 * @param WP_REST_Request     $request REST request.
	 * @return array<string,mixed>
	 */
	public function filter_public_rest_query( array $args, WP_REST_Request $request ): array {
		unset( $request );

		if ( current_user_can( 'edit_posts' ) ) {
			return $args;
		}

		$meta_query         = (array) ( $args['meta_query'] ?? array() );
		$meta_query[]       = $this->public_visibility_clause();
		$args['meta_query'] = $meta_query;

		return $args;
	}

	/**
	 * Return a 404 before core REST prepares direct public hidden book records.
	 *
	 * @param mixed           $response Existing pre-dispatch response.
	 * @param array<mixed>    $handler Matched REST handler.
	 * @param WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public function block_hidden_rest_item_request( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		unset( $handler );

		if ( null !== $response ) {
			return $response;
		}

		$route = $request->get_route();
		if ( ! preg_match( '#^/wp/v2/' . preg_quote( BookPostType::REST_BASE, '#' ) . '/(\d+)$#', $route, $matches ) ) {
			return $response;
		}

		$book_id = absint( $matches[1] );
		if ( 0 === $book_id || current_user_can( 'edit_post', $book_id ) || Availability::is_public( $book_id ) ) {
			return $response;
		}

		return new WP_Error(
			'connectlibrary_book_not_found',
			__( 'Book not found.', 'connectlibrary' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Add SQL clauses for fixed availability sort order.
	 *
	 * @param array<string,string> $clauses Query clauses.
	 * @param WP_Query             $query Query object.
	 * @return array<string,string>
	 */
	public function sort_public_queries_by_availability( array $clauses, WP_Query $query ): array {
		if ( ! $query->get( 'connectlibrary_availability_sort' ) ) {
			return $clauses;
		}

		global $wpdb;

		$alias = 'connectlibrary_availability_meta';
		$join  = $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS connectlibrary_availability_meta ON ({$wpdb->posts}.ID = connectlibrary_availability_meta.post_id AND connectlibrary_availability_meta.meta_key = %s)",
			Availability::META_STATUS
		);

		if ( false === strpos( $clauses['join'] ?? '', $alias ) ) {
			$clauses['join'] = ( $clauses['join'] ?? '' ) . $join;
		}

		$direction          = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
		$order_sql          = "CASE COALESCE({$alias}.meta_value, '" . Statuses::AVAILABILITY_AVAILABLE . "') "
			. "WHEN '" . Statuses::AVAILABILITY_AVAILABLE . "' THEN 10 "
			. "WHEN '" . Statuses::AVAILABILITY_WAITLIST_AVAILABLE . "' THEN 20 "
			. "WHEN '" . Statuses::AVAILABILITY_RESERVED . "' THEN 30 "
			. "WHEN '" . Statuses::AVAILABILITY_CHECKED_OUT . "' THEN 40 "
			. "ELSE 50 END {$direction}";
		$existing_order     = $clauses['orderby'] ?? '';
		$clauses['orderby'] = '' === $existing_order ? $order_sql : $order_sql . ', ' . $existing_order;

		return $clauses;
	}

	/**
	 * Build the public visibility exclusion clause.
	 *
	 * @return array<string,mixed>
	 */
	public function public_visibility_clause(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => Availability::META_VISIBILITY,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Availability::META_VISIBILITY,
				'value'   => Statuses::VISIBILITY_HIDDEN,
				'compare' => '!=',
			),
		);
	}

	/**
	 * Build a public availability filter clause that honors resolver defaults.
	 *
	 * Titles without saved availability meta resolve to available, so the SQL
	 * filter must include missing meta whenever available is requested.
	 *
	 * @param string[] $availability_filter Availability statuses to include.
	 * @return array<string,mixed>
	 */
	public function public_availability_clause( array $availability_filter ): array {
		$availability_filter = Availability::sanitize_filter( $availability_filter );

		if ( in_array( Statuses::AVAILABILITY_AVAILABLE, $availability_filter, true ) ) {
			return array(
				'relation' => 'OR',
				array(
					'key'     => Availability::META_STATUS,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Availability::META_STATUS,
					'value'   => $availability_filter,
					'compare' => 'IN',
				),
			);
		}

		return array(
			'key'     => Availability::META_STATUS,
			'value'   => $availability_filter,
			'compare' => 'IN',
		);
	}

	/**
	 * Identify public queries for the Book post type.
	 *
	 * @param WP_Query $query Query object.
	 */
	private function is_book_query( WP_Query $query ): bool {
		$post_type = $query->get( 'post_type' );

		if ( BookPostType::POST_TYPE === $post_type ) {
			return true;
		}

		return is_array( $post_type ) && in_array( BookPostType::POST_TYPE, $post_type, true );
	}
}
