<?php
/**
 * ISBN metadata lookup orchestration.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

/**
 * Coordinates ISBN validation, provider fallback, and transient caching.
 */
final class IsbnMetadataLookupService {
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * ISBN provider client.
	 *
	 * @var IsbnProviderClient
	 */
	private IsbnProviderClient $client;

	/**
	 * Create service.
	 *
	 * @param IsbnProviderClient|null $client Optional provider client.
	 */
	public function __construct( ?IsbnProviderClient $client = null ) {
		$this->client = $client ?? new IsbnProviderClient();
	}

	/**
	 * Lookup ISBN metadata.
	 *
	 * @param mixed $raw_isbn Raw ISBN input.
	 * @param bool  $bypass_cache Whether to bypass transient cache.
	 * @return array{status:string,isbn:string,isbn_type:string,metadata:array<string,mixed>,message:string,errors:string[]}
	 */
	public function lookup( mixed $raw_isbn, bool $bypass_cache = false ): array {
		$isbn = Isbn::normalize( $raw_isbn );
		$type = Isbn::type( $isbn );

		if ( '' === $isbn ) {
			return $this->result( 'invalid', $isbn, '', __( 'Enter an ISBN before looking up metadata.', 'connectlibrary' ) );
		}
		if ( '' === $type ) {
			return $this->result( 'invalid', $isbn, '', __( 'Enter a valid ISBN-10 or ISBN-13.', 'connectlibrary' ) );
		}

		$cached = $bypass_cache ? false : get_transient( $this->cache_key( $isbn ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$errors = array();
		$google = $this->client->google_books( $isbn );
		if ( $google['ok'] ) {
			$metadata = $google['metadata'];
			$open     = $this->client->open_library( $isbn );
			if ( $open['ok'] ) {
				$metadata = IsbnMetadata::fill_missing( $metadata, $open['metadata'] );
			}
			$result = $this->result( 'found', $isbn, $type, __( 'Metadata found from Google Books.', 'connectlibrary' ), $metadata );
			set_transient( $this->cache_key( $isbn ), $result, self::CACHE_TTL );

			return $result;
		}
		if ( '' !== $google['error'] ) {
			$errors[] = 'Google Books: ' . $google['error'];
		}

		$open = $this->client->open_library( $isbn );
		if ( $open['ok'] ) {
			$message = empty( $errors )
				? __( 'Google Books did not return metadata for this ISBN; record provided by Open Library.', 'connectlibrary' )
				: __( 'Google Books was temporarily unavailable; record provided by Open Library.', 'connectlibrary' );
			$result  = $this->result( 'found', $isbn, $type, $message, $open['metadata'], $errors );
			set_transient( $this->cache_key( $isbn ), $result, self::CACHE_TTL );

			return $result;
		}
		if ( '' !== $open['error'] ) {
			$errors[] = 'Open Library: ' . $open['error'];
		}

		$status  = empty( $errors ) ? 'not_found' : 'provider_error';
		$message = empty( $errors ) ? __( 'No provider match was found for that ISBN.', 'connectlibrary' ) : __( 'ISBN metadata providers are temporarily unavailable or rate limited. You can enter the book manually and try lookup again later.', 'connectlibrary' );
		$result  = $this->result( $status, $isbn, $type, $message, IsbnMetadata::defaults(), $errors );
		if ( 'not_found' === $status ) {
			set_transient( $this->cache_key( $isbn ), $result, self::CACHE_TTL );
		}

		return $result;
	}

	/**
	 * Cache key based only on provider feature and normalized ISBN.
	 *
	 * @param string $isbn Normalized ISBN value.
	 */
	private function cache_key( string $isbn ): string {
		return 'connectlibrary_isbn_lookup_' . md5( $isbn );
	}

	/**
	 * Build a result array.
	 *
	 * @param string              $status Result status.
	 * @param string              $isbn Normalized ISBN value.
	 * @param string              $type ISBN type.
	 * @param string              $message Human-readable message.
	 * @param array<string,mixed> $metadata Metadata suggestion.
	 * @param string[]            $errors Provider errors.
	 * @return array{status:string,isbn:string,isbn_type:string,metadata:array<string,mixed>,message:string,errors:string[]}
	 */
	private function result( string $status, string $isbn, string $type, string $message, array $metadata = array(), array $errors = array() ): array {
		return array(
			'status'    => $status,
			'isbn'      => $isbn,
			'isbn_type' => $type,
			'metadata'  => array_merge( IsbnMetadata::defaults(), $metadata ),
			'message'   => $message,
			'errors'    => $errors,
		);
	}
}
