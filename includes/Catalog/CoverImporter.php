<?php
/**
 * Imports remote ISBN metadata cover candidates into WordPress Media Library.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

use WP_Error;

/**
 * Server-side cover sideload service for Book posts.
 */
final class CoverImporter {
	public const META_SOURCE_PROVIDER = '_connectlibrary_cover_source_provider';
	public const META_SOURCE_URL      = '_connectlibrary_cover_source_url';
	public const META_IMPORTED_AT     = '_connectlibrary_cover_imported_at';
	public const META_STATUS          = '_connectlibrary_cover_import_status';
	public const META_ERROR           = '_connectlibrary_cover_import_error';

	private const MAX_BYTES = 5242880;

	/**
	 * Import the first usable cover candidate for a book.
	 *
	 * @param int                 $book_id Book post ID.
	 * @param array<string,mixed> $metadata Normalized ISBN metadata.
	 * @param bool                $replace Whether to replace an existing local cover.
	 * @return array{status:string,attachment_id:int,source_url:string,error:string}
	 */
	public function import_for_book( int $book_id, array $metadata, bool $replace = false ): array {
		$existing = absint( get_post_thumbnail_id( $book_id ) );
		if ( $existing > 0 && ! $replace ) {
			$this->record_status( $book_id, 'skipped_existing', '', '', '' );

			return $this->result( 'skipped_existing', $existing );
		}

		$candidates = $this->candidate_urls( $metadata['cover_url_candidates'] ?? array() );
		if ( empty( $candidates ) ) {
			$this->record_status( $book_id, 'not_found', '', '', __( 'No cover URL was provided by the metadata source.', 'connectlibrary' ) );

			return $this->result( 'not_found', 0, '', '' );
		}

		$last_error = '';
		foreach ( $candidates as $url ) {
			$validation = $this->validate_url( $url );
			if ( is_wp_error( $validation ) ) {
				$last_error = $validation->get_error_message();
				continue;
			}

			$download = $this->download_candidate( $url );
			if ( is_wp_error( $download ) ) {
				$last_error = $download->get_error_message();
				continue;
			}

			$attachment_id = $this->create_attachment( $book_id, $metadata, $url, $download );
			if ( is_wp_error( $attachment_id ) ) {
				$last_error = $attachment_id->get_error_message();
				continue;
			}

			$thumbnail_set = set_post_thumbnail( $book_id, $attachment_id );
			if ( false === $thumbnail_set ) {
				update_post_meta( $book_id, '_thumbnail_id', $attachment_id );
			}
			$this->record_status( $book_id, 'imported', (string) ( $metadata['source_provider'] ?? '' ), $url, '' );

			return $this->result( 'imported', $attachment_id, $url, '' );
		}

		if ( '' === $last_error ) {
			$last_error = __( 'No usable cover candidate was available.', 'connectlibrary' );
		}
		$this->record_status( $book_id, 'failed', (string) ( $metadata['source_provider'] ?? '' ), '', $last_error );

		return $this->result( 'failed', 0, '', $last_error );
	}

	/**
	 * Normalize candidate URLs.
	 *
	 * @param mixed $raw Raw candidate list.
	 * @return string[]
	 */
	private function candidate_urls( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array( $raw );
		}

		$urls = array();
		foreach ( $raw as $candidate ) {
			$url = esc_url_raw( (string) $candidate );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Validate a candidate URL before HTTP fetch.
	 *
	 * @param string $url URL to validate.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function validate_url( string $url ): true|WP_Error {
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return new WP_Error( 'connectlibrary_cover_unsafe_url', __( 'Cover URL scheme is not allowed.', 'connectlibrary' ) );
		}
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) ) {
			return new WP_Error( 'connectlibrary_cover_unsafe_host', __( 'Cover URL host is not allowed.', 'connectlibrary' ) );
		}
		if ( $this->is_private_ip( $host ) ) {
			return new WP_Error( 'connectlibrary_cover_unsafe_host', __( 'Cover URL host is not allowed.', 'connectlibrary' ) );
		}

		return true;
	}

	/**
	 * Download and validate a cover candidate.
	 *
	 * @param string $url URL to download.
	 * @return array{body:string,mime:string,extension:string}|WP_Error
	 */
	private function download_candidate( string $url ): array|WP_Error {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES + 1,
				'reject_unsafe_urls'  => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connectlibrary_cover_download_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			// translators: %d is the HTTP status code returned by the cover image server.
			return new WP_Error( 'connectlibrary_cover_http_status', sprintf( __( 'Cover download returned HTTP %d.', 'connectlibrary' ), $code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$size = strlen( $body );
		if ( 0 === $size ) {
			return new WP_Error( 'connectlibrary_cover_empty', __( 'Cover download was empty.', 'connectlibrary' ) );
		}
		if ( $size > self::MAX_BYTES ) {
			return new WP_Error( 'connectlibrary_cover_too_large', __( 'Cover image is larger than the allowed 5 MB limit.', 'connectlibrary' ) );
		}

		$strtok_result = strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' );
		$mime          = strtolower( false !== $strtok_result ? $strtok_result : '' );
		$extension     = $this->extension_for_mime( $mime );
		if ( '' === $extension ) {
			return new WP_Error( 'connectlibrary_cover_bad_type', __( 'Cover download was not a supported image type.', 'connectlibrary' ) );
		}

		return array(
			'body'      => $body,
			'mime'      => $mime,
			'extension' => $extension,
		);
	}

	/**
	 * Create a Media Library attachment from the validated download.
	 *
	 * @param int                                             $book_id    Book post ID.
	 * @param array<string,mixed>                             $metadata   Book/provider metadata.
	 * @param string                                          $source_url Cover source URL.
	 * @param array{body:string,mime:string,extension:string} $download   Download data.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	private function create_attachment( int $book_id, array $metadata, string $source_url, array $download ): int|WP_Error {
		$this->load_media_dependencies();

		$title = sanitize_text_field( (string) ( $metadata['title'] ?? '' ) );
		if ( '' === $title ) {
			$post  = get_post( $book_id );
			$title = sanitize_text_field( (string) ( $post->post_title ?? __( 'Book cover', 'connectlibrary' ) ) );
		}
		$isbn     = $this->isbn_for_filename( $metadata );
		$filename = $this->filename( $title, $isbn, $download['extension'] );
		$tmp      = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new WP_Error( 'connectlibrary_cover_temp_file', __( 'Could not create a temporary cover file.', 'connectlibrary' ) );
		}
		if ( false === file_put_contents( $tmp, $download['body'] ) ) {
			return new WP_Error( 'connectlibrary_cover_temp_write', __( 'Could not write the temporary cover file.', 'connectlibrary' ) );
		}

		$file = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
			'type'     => $download['mime'],
			'size'     => strlen( $download['body'] ),
			'error'    => 0,
		);

		$attachment_id = media_handle_sideload( $file, $book_id, $title );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );

			return $attachment_id;
		}

		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_title'   => $title,
				'post_excerpt' => '',
			)
		);
		// translators: %s is the book title.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sprintf( __( 'Cover of %s', 'connectlibrary' ), $title ) );
		update_post_meta( $attachment_id, self::META_SOURCE_URL, $source_url );

		return absint( $attachment_id );
	}

	/**
	 * Load WordPress media functions in admin/API contexts.
	 *
	 * @return void
	 */
	private function load_media_dependencies(): void {
		if ( defined( 'ABSPATH' ) ) {
			foreach ( array( 'file.php', 'media.php', 'image.php' ) as $file ) {
				$path = ABSPATH . 'wp-admin/includes/' . $file;
				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		}
	}

	/**
	 * Store cover import status metadata on the book post.
	 *
	 * @param int    $book_id    Book post ID.
	 * @param string $status     Import status key.
	 * @param string $provider   Source provider name.
	 * @param string $source_url Cover source URL.
	 * @param string $error      Error message, or empty string.
	 * @return void
	 */
	private function record_status( int $book_id, string $status, string $provider, string $source_url, string $error ): void {
		update_post_meta( $book_id, self::META_STATUS, sanitize_key( $status ) );
		update_post_meta( $book_id, self::META_SOURCE_PROVIDER, sanitize_text_field( $provider ) );
		update_post_meta( $book_id, self::META_SOURCE_URL, esc_url_raw( $source_url ) );
		update_post_meta( $book_id, self::META_IMPORTED_AT, current_time( 'mysql', true ) );
		update_post_meta( $book_id, self::META_ERROR, sanitize_text_field( $error ) );
	}

	/**
	 * Build a normalized result.
	 *
	 * @param string $status        Status key.
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $source_url    Source URL.
	 * @param string $error         Error message, or empty string.
	 * @return array{status:string,attachment_id:int,source_url:string,error:string}
	 */
	private function result( string $status, int $attachment_id = 0, string $source_url = '', string $error = '' ): array {
		return array(
			'status'        => $status,
			'attachment_id' => $attachment_id,
			'source_url'    => $source_url,
			'error'         => $error,
		);
	}

	/**
	 * Return extension for supported image MIME.
	 *
	 * @param string $mime MIME type.
	 * @return string File extension, or empty string if unsupported.
	 */
	private function extension_for_mime( string $mime ): string {
		return match ( $mime ) {
			'image/jpeg', 'image/jpg' => 'jpg',
			'image/png'              => 'png',
			'image/webp'             => 'webp',
			'image/gif'              => 'gif',
			default                  => '',
		};
	}

	/**
	 * Build a safe filename.
	 *
	 * @param string $title     Book title.
	 * @param string $isbn      ISBN string.
	 * @param string $extension File extension.
	 * @return string Safe filename.
	 */
	private function filename( string $title, string $isbn, string $extension ): string {
		$parts = array_filter(
			array(
				'connectlibrary-cover',
				$isbn,
				sanitize_title( $title ),
			),
			static fn( string $part ): bool => '' !== $part
		);

		return implode( '-', $parts ) . '.' . $extension;
	}

	/**
	 * Extract an ISBN-like value for filenames.
	 *
	 * @param array<string,mixed> $metadata Book metadata.
	 * @return string Sanitized ISBN string, or empty string.
	 */
	private function isbn_for_filename( array $metadata ): string {
		foreach ( array( 'isbn', 'isbn_13', 'isbn_10' ) as $key ) {
			$value = preg_replace( '/[^0-9Xx]/', '', (string) ( $metadata[ $key ] ?? '' ) ) ?? '';
			if ( '' !== $value ) {
				return strtoupper( $value );
			}
		}

		return '';
	}

	/**
	 * Detect private or loopback IP literal hosts.
	 *
	 * @param string $host Host string.
	 * @return bool True if host is a private IP address.
	 */
	private function is_private_ip( string $host ): bool {
		if ( false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return false === filter_var(
			$host,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
