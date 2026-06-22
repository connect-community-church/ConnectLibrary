<?php
/**
 * Public books REST controller.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Rest;

use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\CatalogQueryParams;
use ConnectLibrary\Support\Statuses;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles read-only public catalog book endpoints.
 */
final class BooksController {
	private const MAX_PER_PAGE = 50;

	/**
	 * Public book serializer.
	 *
	 * @var PublicBookSerializer
	 */
	private PublicBookSerializer $serializer;

	/** Create controller dependencies. */
	public function __construct() {
		$this->serializer = new PublicBookSerializer();
	}

	/** Register book list and detail routes. */
	public function register_routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/books',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => static fn(): bool => true,
				'args'                => $this->collection_params(),
			)
		);

		register_rest_route(
			Routes::NAMESPACE,
			'/books/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => static fn(): bool => true,
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Return paginated public book records.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $this->sanitize_collection_request( $request );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$payloads = array();
		foreach ( $this->fetch_candidate_posts() as $post ) {
			if ( ! $this->is_public_book( $post ) ) {
				continue;
			}

			$payload = $this->serializer->serialize( $post );
			if ( ! $this->payload_matches_filters( $payload, $params ) ) {
				continue;
			}
			$payloads[] = $payload;
		}

		$payloads = $this->sort_payloads( $payloads, $params['sort'], $params['order'] );
		$total    = count( $payloads );
		$pages    = (int) ceil( $total / $params['per_page'] );
		$offset   = ( $params['page'] - 1 ) * $params['per_page'];
		$items    = array_slice( $payloads, $offset, $params['per_page'] );
		$response = rest_ensure_response( $items );

		if ( method_exists( $response, 'header' ) ) {
			$response->header( 'X-WP-Total', (string) $total );
			$response->header( 'X-WP-TotalPages', (string) max( 1, $pages ) );
		}

		return $response;
	}

	/**
	 * Return one public book record.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$book_id = absint( $request['id'] ?? 0 );
		$post    = get_post( $book_id );

		if ( ! $post || ! $this->is_public_book( $post ) ) {
			return new WP_Error(
				'connectlibrary_book_not_found',
				__( 'Book not found.', 'connectlibrary' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->serializer->serialize( $post ) );
	}

	/**
	 * Public collection parameter schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function collection_params(): array {
		return array(
			'page'         => array( 'type' => 'integer' ),
			'per_page'     => array( 'type' => 'integer' ),
			'search'       => array( 'type' => 'string' ),
			'sort'         => array( 'type' => 'string' ),
			'order'        => array( 'type' => 'string' ),
			'category'     => array( 'type' => 'string' ),
			'tag'          => array( 'type' => 'string' ),
			'author'       => array( 'type' => 'string' ),
			'series'       => array( 'type' => 'string' ),
			'age_level'    => array( 'type' => 'string' ),
			'availability' => array( 'type' => 'string' ),
		);
	}

	/**
	 * Sanitize collection request values or return a REST error.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string,mixed>|WP_Error
	 */
	private function sanitize_collection_request( WP_REST_Request $request ): array|WP_Error {
		$sort     = sanitize_key( (string) ( $request['sort'] ?? 'title' ) );
		$order    = strtolower( sanitize_key( (string) ( $request['order'] ?? 'asc' ) ) );
		$per_page = absint( $request['per_page'] ?? 20 );
		$page     = max( 1, absint( $request['page'] ?? 1 ) );
		// UI sorts come from CatalogQueryParams::ALLOWED_SORTS. 'rating' is kept
		// here for REST backward compatibility but falls through to title sort
		// until rating metadata is added in a future build.
		$valid_sort = array_merge( CatalogQueryParams::ALLOWED_SORTS, array( 'rating' ) );

		if ( ! in_array( $sort, $valid_sort, true ) ) {
			return new WP_Error( 'connectlibrary_invalid_sort', __( 'Invalid catalog sort.', 'connectlibrary' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			return new WP_Error( 'connectlibrary_invalid_order', __( 'Invalid catalog order.', 'connectlibrary' ), array( 'status' => 400 ) );
		}
		if ( $per_page < 1 || $per_page > self::MAX_PER_PAGE ) {
			return new WP_Error( 'connectlibrary_invalid_per_page', __( 'Invalid per_page value.', 'connectlibrary' ), array( 'status' => 400 ) );
		}

		return array(
			'page'         => $page,
			'per_page'     => $per_page,
			'search'       => sanitize_text_field( (string) ( $request['search'] ?? '' ) ),
			'sort'         => $sort,
			'order'        => $order,
			'category'     => sanitize_text_field( (string) ( $request['category'] ?? '' ) ),
			'tag'          => sanitize_text_field( (string) ( $request['tag'] ?? '' ) ),
			'author'       => sanitize_text_field( (string) ( $request['author'] ?? '' ) ),
			'series'       => sanitize_text_field( (string) ( $request['series'] ?? '' ) ),
			'age_level'    => sanitize_text_field( (string) ( $request['age_level'] ?? '' ) ),
			'availability' => Availability::sanitize_filter( $request['availability'] ?? '' ),
		);
	}

	/**
	 * Fetch candidate book posts; final privacy filtering happens in PHP.
	 *
	 * @return array<int,object>
	 */
	private function fetch_candidate_posts(): array {
		$posts = get_posts(
			array(
				'post_type'      => BookPostType::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'numberposts'    => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Determine whether a post is a public visible book.
	 *
	 * @param object $post Book post object.
	 */
	private function is_public_book( object $post ): bool {
		$book_id = absint( $post->ID ?? 0 );

		return BookPostType::POST_TYPE === (string) ( $post->post_type ?? '' )
			&& 'publish' === (string) ( $post->post_status ?? '' )
			&& Availability::is_public( $book_id );
	}

	/**
	 * Apply public request filters to a serialized payload.
	 *
	 * @param array<string,mixed> $payload Public payload.
	 * @param array<string,mixed> $params Sanitized params.
	 */
	private function payload_matches_filters( array $payload, array $params ): bool {
		if ( ! $this->serializer->matches_search( $payload, (string) $params['search'] ) ) {
			return false;
		}
		if ( array() !== $params['availability'] && ! in_array( $payload['availability_status'], $params['availability'], true ) ) {
			return false;
		}
		if ( '' !== $params['age_level'] && (string) $payload['age_level'] !== (string) $params['age_level'] ) {
			return false;
		}
		if ( ! $this->matches_lookup_filter( $payload['categories'], (string) $params['category'] ) ) {
			return false;
		}
		if ( ! $this->matches_lookup_filter( $payload['tags'], (string) $params['tag'] ) ) {
			return false;
		}
		if ( ! $this->matches_lookup_filter( $payload['authors'], (string) $params['author'] ) ) {
			return false;
		}
		if ( '' !== $params['series'] && ! $this->matches_single_lookup_filter( $payload['series'], (string) $params['series'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Sort public payloads by an allow-listed key with a deterministic secondary sort.
	 *
	 * @param array<int,array<string,mixed>> $payloads Public payloads.
	 * @param string                         $sort Sort key.
	 * @param string                         $order Sort order.
	 * @return array<int,array<string,mixed>>
	 */
	private function sort_payloads( array $payloads, string $sort, string $order ): array {
		usort(
			$payloads,
			static function ( array $a, array $b ) use ( $sort, $order ): int {
				if ( 'availability' === $sort ) {
					$result = Availability::sort_rank( (string) $a['availability_status'] ) <=> Availability::sort_rank( (string) $b['availability_status'] );
					if ( 0 === $result ) {
						$result = strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
					}
				} elseif ( 'newest' === $sort ) {
					$result = (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 );
				} elseif ( 'author' === $sort ) {
					$a_author = (string) ( $a['authors'][0]['label'] ?? '' );
					$b_author = (string) ( $b['authors'][0]['label'] ?? '' );
					$result   = strcasecmp( $a_author, $b_author );
					if ( 0 === $result ) {
						$result = strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
					}
				} else {
					// title and deferred 'rating' both sort by title with ID as secondary.
					$result = strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
					if ( 0 === $result ) {
						$result = (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
					}
				}

				return 'desc' === $order ? -$result : $result;
			}
		);

		return $payloads;
	}

	/**
	 * Match a list of lookup objects by id, slug, or label.
	 *
	 * @param array<int,array<string,mixed>> $items Lookup objects.
	 * @param string                         $filter Filter value.
	 */
	private function matches_lookup_filter( array $items, string $filter ): bool {
		if ( '' === $filter ) {
			return true;
		}
		foreach ( $items as $item ) {
			if ( $this->lookup_matches( $item, $filter ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match a single lookup object by id, slug, or label.
	 *
	 * @param mixed  $item Lookup object or null.
	 * @param string $filter Filter value.
	 */
	private function matches_single_lookup_filter( mixed $item, string $filter ): bool {
		return '' === $filter || ( is_array( $item ) && $this->lookup_matches( $item, $filter ) );
	}

	/**
	 * Match lookup object fields.
	 *
	 * @param array<string,mixed> $item Lookup object.
	 * @param string              $filter Filter value.
	 */
	private function lookup_matches( array $item, string $filter ): bool {
		return (string) ( $item['id'] ?? '' ) === $filter
			|| (string) ( $item['slug'] ?? '' ) === $filter
			|| 0 === strcasecmp( (string) ( $item['label'] ?? '' ), $filter );
	}
}
