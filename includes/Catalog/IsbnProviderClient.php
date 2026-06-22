<?php
/**
 * ISBN metadata provider HTTP client.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

/**
 * Fetches and maps Google Books / Open Library ISBN responses.
 */
final class IsbnProviderClient {
	private const GOOGLE_TIMEOUT_SECONDS       = 10;
	private const OPEN_LIBRARY_TIMEOUT_SECONDS = 30;

	/**
	 * Query Google Books by ISBN.
	 *
	 * @param string $isbn Normalized ISBN value.
	 * @return array{ok:bool,metadata:array<string,mixed>,error:string,not_found:bool}
	 */
	public function google_books( string $isbn ): array {
		$args = array( 'q' => 'isbn:' . $isbn );
		$key  = $this->google_books_api_key();
		if ( '' !== $key ) {
			$args['key'] = $key;
		}
		$url = add_query_arg(
			$args,
			'https://www.googleapis.com/books/v1/volumes'
		);

		$response = $this->request_json( esc_url_raw( $url ), self::GOOGLE_TIMEOUT_SECONDS );
		if ( ! $response['ok'] ) {
			return $response;
		}

		$metadata = IsbnMetadata::from_google_books( $response['json'] );
		if ( ! IsbnMetadata::is_useful( $metadata ) ) {
			return $this->empty_result();
		}

		return array(
			'ok'        => true,
			'metadata'  => $metadata,
			'error'     => '',
			'not_found' => false,
		);
	}

	/**
	 * Query Open Library by ISBN.
	 *
	 * @param string $isbn Normalized ISBN value.
	 * @return array{ok:bool,metadata:array<string,mixed>,error:string,not_found:bool}
	 */
	public function open_library( string $isbn ): array {
		$url      = 'https://openlibrary.org/isbn/' . rawurlencode( $isbn ) . '.json';
		$response = $this->request_json( esc_url_raw( $url ), self::OPEN_LIBRARY_TIMEOUT_SECONDS );
		if ( $response['ok'] ) {
			$metadata = IsbnMetadata::from_open_library( $response['json'], $isbn );
			if ( IsbnMetadata::is_useful( $metadata ) && ! empty( $metadata['authors'] ) ) {
				return array(
					'ok'        => true,
					'metadata'  => $metadata,
					'error'     => '',
					'not_found' => false,
				);
			}
		}

		$primary_error = $response['error'] ?? '';
		$fallback_url  = add_query_arg(
			array(
				'bibkeys' => 'ISBN:' . $isbn,
				'format'  => 'json',
				'jscmd'   => 'data',
			),
			'https://openlibrary.org/api/books'
		);
		$fallback      = $this->request_json( esc_url_raw( $fallback_url ), self::OPEN_LIBRARY_TIMEOUT_SECONDS );
		$fallback_key  = 'ISBN:' . $isbn;
		if ( ! $fallback['ok'] ) {
			return '' !== $primary_error ? $this->error_result( $primary_error . '; fallback failed: ' . $fallback['error'] ) : $fallback;
		}

		$book = isset( $fallback['json'][ $fallback_key ] ) && is_array( $fallback['json'][ $fallback_key ] ) ? $fallback['json'][ $fallback_key ] : array();
		if ( empty( $book ) ) {
			return $this->empty_result();
		}
		$metadata = IsbnMetadata::from_open_library_api_book( $book, $isbn );
		if ( ! IsbnMetadata::is_useful( $metadata ) ) {
			return $this->empty_result();
		}

		return array(
			'ok'        => true,
			'metadata'  => $metadata,
			'error'     => '',
			'not_found' => false,
		);
	}

	/**
	 * Read the optional Google Books API key from wp-config or environment.
	 */
	private function google_books_api_key(): string {
		if ( defined( 'CONNECTLIBRARY_GOOGLE_BOOKS_API_KEY' ) ) {
			return sanitize_text_field( (string) constant( 'CONNECTLIBRARY_GOOGLE_BOOKS_API_KEY' ) );
		}

		$key = getenv( 'CONNECTLIBRARY_GOOGLE_BOOKS_API_KEY' );
		return false === $key ? '' : sanitize_text_field( (string) $key );
	}

	/**
	 * Build a referer for API keys restricted to the church websites.
	 */
	private function request_referer(): string {
		if ( function_exists( 'home_url' ) ) {
			return esc_url_raw( home_url( '/' ) );
		}

		return 'https://staging.connectcommunitychurch.ca/';
	}

	/**
	 * Fetch and decode JSON with WordPress HTTP APIs.
	 *
	 * @param string $url             Provider URL.
	 * @param int    $timeout_seconds HTTP timeout seconds.
	 * @return array{ok:bool,json:array<string,mixed>,metadata:array<string,mixed>,error:string,not_found:bool}
	 */
	private function request_json( string $url, int $timeout_seconds ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout_seconds,
				'redirection' => 2,
				'user-agent'  => 'ConnectLibrary/' . ( defined( 'CONNECTLIBRARY_VERSION' ) ? CONNECTLIBRARY_VERSION : 'dev' ) . ' (staging.connectcommunitychurch.ca)',
				'headers'     => array(
					'Accept'  => 'application/json',
					'Referer' => $this->request_referer(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error_result( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return $this->empty_result();
		}
		if ( 429 === $code ) {
			return $this->error_result( 'Provider returned HTTP 429 (rate limited). Try again later or enter the book manually.' );
		}
		if ( $code < 200 || $code >= 300 ) {
			return $this->error_result( 'Provider returned HTTP ' . $code . '.' );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return $this->error_result( 'Provider returned invalid JSON.' );
		}

		return array(
			'ok'        => true,
			'json'      => $json,
			'metadata'  => IsbnMetadata::defaults(),
			'error'     => '',
			'not_found' => false,
		);
	}

	/** Empty/no-match result. */
	private function empty_result(): array {
		return array(
			'ok'        => false,
			'metadata'  => IsbnMetadata::defaults(),
			'error'     => '',
			'not_found' => true,
		);
	}

	/**
	 * Provider error result.
	 *
	 * @param string $message Provider error message.
	 */
	private function error_result( string $message ): array {
		return array(
			'ok'        => false,
			'metadata'  => IsbnMetadata::defaults(),
			'error'     => sanitize_text_field( $message ),
			'not_found' => false,
		);
	}
}
