<?php
/**
 * Public REST serialization for book catalog records.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Rest;

use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Catalog\BookMetadata;
use ConnectLibrary\Catalog\BookMetadataRepository;
use ConnectLibrary\Catalog\BookRelationshipsRepository;
use ConnectLibrary\Catalog\BookTaxonomies;
use WP_Post;

/**
 * Shapes allow-listed public book fields for catalog API responses.
 */
final class PublicBookSerializer {
	/**
	 * Book metadata repository.
	 *
	 * @var BookMetadataRepository
	 */
	private BookMetadataRepository $metadata;

	/**
	 * Book relationship repository.
	 *
	 * @var BookRelationshipsRepository
	 */
	private BookRelationshipsRepository $relationships;

	/** Create serializer dependencies. */
	public function __construct() {
		$this->metadata      = new BookMetadataRepository();
		$this->relationships = new BookRelationshipsRepository();
	}

	/**
	 * Convert a public book post to a safe REST payload.
	 *
	 * @param WP_Post|object $post Book post object.
	 * @return array<string,mixed>
	 */
	public function serialize( object $post ): array {
		$book_id      = absint( $post->ID ?? 0 );
		$fields       = $this->metadata->get( $book_id );
		$public_meta  = BookMetadata::public_payload( $fields );
		$availability = Availability::for_book( $book_id );

		unset( $public_meta['visibility'] );

		return array(
			'id'                  => $book_id,
			'slug'                => (string) ( $post->post_name ?? '' ),
			'title'               => (string) ( $post->post_title ?? '' ),
			'subtitle'            => $public_meta['subtitle'],
			'authors'             => $this->authors_for_book( $book_id ),
			'series'              => $this->series_for_book( $book_id ),
			'categories'          => $this->terms_for_book( $book_id, BookTaxonomies::TAXONOMY_CATEGORY ),
			'tags'                => $this->terms_for_book( $book_id, BookTaxonomies::TAXONOMY_TAG ),
			'age_level'           => $fields['age_level'] ?? '',
			'reading_level'       => $fields['reading_level'] ?? '',
			'description'         => wp_strip_all_tags( (string) ( $post->post_content ?? '' ) ),
			'isbn_10'             => $public_meta['isbn_10'],
			'isbn_13'             => $public_meta['isbn_13'],
			'publisher'           => $public_meta['publisher'],
			'published_date'      => $public_meta['published_date'],
			'language'            => $public_meta['language'],
			'page_count'          => $public_meta['page_count'],
			'cover'               => $this->cover_for_book( $book_id ),
			'availability_status' => $availability['status'],
			'availability_label'  => $availability['label'],
			'availability'        => $availability,
			'location'            => $this->location_from_fields( $fields ),
			'recommended'         => $public_meta['recommended'],
			'content_notes'       => $public_meta['content_notes'],
			'church_notes'        => $public_meta['church_notes'],
			'links'               => array(
				'detail' => get_permalink( $book_id ),
				'self'   => function_exists( 'rest_url' ) ? rest_url( Routes::NAMESPACE . '/books/' . $book_id ) : '/wp-json/' . Routes::NAMESPACE . '/books/' . $book_id,
			),
		);
	}

	/**
	 * Whether a serialized payload matches the public search term.
	 *
	 * @param array<string,mixed> $payload Public payload.
	 * @param string              $search Search term.
	 */
	public function matches_search( array $payload, string $search ): bool {
		$search = strtolower( trim( $search ) );
		if ( '' === $search ) {
			return true;
		}

		$haystack = array(
			$payload['title'] ?? '',
			$payload['subtitle'] ?? '',
			$payload['description'] ?? '',
			$payload['isbn_10'] ?? '',
			$payload['isbn_13'] ?? '',
			$payload['publisher'] ?? '',
		);
		foreach ( $payload['authors'] ?? array() as $author ) {
			$haystack[] = $author['label'] ?? '';
		}
		if ( is_array( $payload['series'] ?? null ) ) {
			$haystack[] = $payload['series']['label'] ?? '';
		}

		return str_contains( strtolower( implode( ' ', array_map( 'strval', $haystack ) ) ), $search );
	}

	/**
	 * Get public author objects for a book.
	 *
	 * @param int $book_id Book post ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function authors_for_book( int $book_id ): array {
		$ids     = $this->relationships->get_author_ids( $book_id );
		$authors = array();

		foreach ( $this->relationships->list_authors() as $author ) {
			$author_id = absint( $author['id'] ?? 0 );
			if ( ! in_array( $author_id, $ids, true ) ) {
				continue;
			}
			$authors[] = array(
				'id'    => $author_id,
				'slug'  => (string) ( $author['slug'] ?? '' ),
				'label' => (string) ( $author['display_name'] ?? '' ),
			);
		}

		return $authors;
	}

	/**
	 * Get public series object for a book.
	 *
	 * @param int $book_id Book post ID.
	 * @return array<string,mixed>|null
	 */
	private function series_for_book( int $book_id ): ?array {
		$selection = $this->relationships->get_series_selection( $book_id );
		$series_id = absint( $selection['series_id'] ?? 0 );
		if ( 0 === $series_id ) {
			return null;
		}

		foreach ( $this->relationships->list_series() as $series ) {
			if ( absint( $series['id'] ?? 0 ) !== $series_id ) {
				continue;
			}

			return array(
				'id'       => $series_id,
				'slug'     => (string) ( $series['slug'] ?? '' ),
				'label'    => (string) ( $series['name'] ?? '' ),
				'position' => (string) ( $selection['series_position'] ?? '' ),
			);
		}

		return null;
	}

	/**
	 * Get public taxonomy terms for a book.
	 *
	 * @param int    $book_id Book post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int,array<string,mixed>>
	 */
	private function terms_for_book( int $book_id, string $taxonomy ): array {
		if ( ! function_exists( 'get_the_terms' ) ) {
			return array();
		}

		$terms = get_the_terms( $book_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_map(
				static function ( object $term ): array {
					return array(
						'id'    => absint( $term->term_id ?? 0 ),
						'slug'  => (string) ( $term->slug ?? '' ),
						'label' => (string) ( $term->name ?? '' ),
					);
				},
				$terms
			)
		);
	}

	/**
	 * Build public cover metadata.
	 *
	 * @param int $book_id Book post ID.
	 * @return array<string,mixed>|null
	 */
	private function cover_for_book( int $book_id ): ?array {
		$attachment_id = get_post_thumbnail_id( $book_id );
		if ( ! $attachment_id ) {
			return null;
		}

		$full  = function_exists( 'wp_get_attachment_image_url' ) ? wp_get_attachment_image_url( $attachment_id, 'full' ) : '';
		$sizes = array();
		if ( function_exists( 'wp_get_attachment_image_url' ) ) {
			foreach ( array( 'thumbnail', 'medium', 'large' ) as $size ) {
				$url = wp_get_attachment_image_url( $attachment_id, $size );
				if ( $url ) {
					$sizes[ $size ] = esc_url_raw( $url );
				}
			}
		}

		return array(
			'id'    => absint( $attachment_id ),
			'url'   => $full ? esc_url_raw( $full ) : '',
			'alt'   => function_exists( 'get_post_meta' ) ? (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) : '',
			'sizes' => $sizes,
		);
	}

	/**
	 * Build public-safe location metadata.
	 *
	 * @param array<string,mixed> $fields Book metadata fields.
	 * @return array<string,string>
	 */
	private function location_from_fields( array $fields ): array {
		return array_filter(
			array(
				'room'    => (string) ( $fields['room'] ?? '' ),
				'shelf'   => (string) ( $fields['shelf'] ?? '' ),
				'section' => (string) ( $fields['section'] ?? '' ),
			),
			static fn( string $value ): bool => '' !== $value
		);
	}
}
