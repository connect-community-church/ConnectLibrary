<?php
/**
 * Public front-end hook registration for the ConnectLibrary plugin.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Frontend;

use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Support\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the public book detail rendering hooks into WordPress.
 *
 * Uses the_content filter so the output is theme-friendly and does not
 * require a custom theme template file.  The CSS enqueue is scoped to
 * single book pages only.
 */
final class PublicServiceProvider {

	/**
	 * Book detail renderer.
	 *
	 * @var BookDetailRenderer
	 */
	private BookDetailRenderer $renderer;

	/**
	 * My Library shortcode renderer.
	 *
	 * @var MyLibraryPage
	 */
	private MyLibraryPage $my_library;

	/**
	 * Public reservation POST handler.
	 *
	 * @var PublicReservationRequests
	 */
	private PublicReservationRequests $reservation_requests;

	/** Create the service provider and its renderer. */
	public function __construct() {
		$this->reservation_requests = new PublicReservationRequests();
		$this->renderer             = new BookDetailRenderer();
		$this->my_library           = new MyLibraryPage();
	}

	/**
	 * Register public front-end hooks.
	 */
	public function register(): void {
		$this->my_library->register();
		add_filter( 'the_content', array( $this, 'render_book_detail' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_hidden_book' ) );
		add_action( 'init', array( $this->reservation_requests, 'handle_post' ) );
	}

	/**
	 * Replace the default post content with the structured book detail layout.
	 *
	 * Only fires on singular connectlibrary_book pages.  The already-filtered
	 * content HTML is passed into the renderer as the description block so
	 * WordPress content processing (wpautop, etc.) is preserved.
	 *
	 * @param string $content Current post content HTML.
	 * @return string Structured book detail HTML, or unmodified content.
	 */
	public function render_book_detail( string $content ): string {
		if ( ! is_singular( BookPostType::POST_TYPE ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		return $this->renderer->render( absint( $post_id ), $content );
	}

	/**
	 * Enqueue the minimal book detail stylesheet on single book pages.
	 */
	public function enqueue_styles(): void {
		if ( ! is_singular( BookPostType::POST_TYPE ) ) {
			return;
		}

		wp_enqueue_style(
			'connectlibrary-book-detail',
			plugin_dir_url( CONNECTLIBRARY_PLUGIN_FILE ) . 'assets/css/public-book-detail.css',
			array(),
			CONNECTLIBRARY_VERSION
		);
	}

	/**
	 * Serve a 404 for Availability-hidden books to non-editors on direct permalink.
	 *
	 * Fires on template_redirect so the page title, meta tags, and theme
	 * furniture are never delivered to anonymous visitors for hidden titles.
	 * Users with edit_post capability retain full access for review.
	 */
	public function maybe_redirect_hidden_book(): void {
		if ( ! is_singular( BookPostType::POST_TYPE ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$availability = Availability::for_book( absint( $post_id ) );
		if ( Statuses::AVAILABILITY_HIDDEN !== $availability['status'] ) {
			return;
		}

		if ( current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		wp_die( '', '', array( 'response' => 404 ) );
	}
}
