<?php
/**
 * Public single-book detail renderer.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Frontend;

use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Catalog\Availability;
use ConnectLibrary\Catalog\BookMetadata;
use ConnectLibrary\Catalog\BookMetadataRepository;
use ConnectLibrary\Catalog\BookRelationshipsRepository;
use ConnectLibrary\Catalog\BookTaxonomies;
use ConnectLibrary\Settings\Settings;
use ConnectLibrary\Support\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the public HTML for a single book detail page.
 *
 * Shares field and status formatting logic with catalog and REST code by
 * using the same BookMetadataRepository, BookMetadata::public_payload(), and
 * Availability::for_book() that the REST serializer uses.  Private fields
 * (private_notes, borrower data, internal IDs) are never exposed here.
 */
final class BookDetailRenderer {

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

	/**
	 * Borrower repository for active-borrower lookup.
	 *
	 * @var BorrowerRepository
	 */
	private BorrowerRepository $borrower_repo;

	/** Create renderer dependencies. */
	public function __construct() {
		$this->metadata      = new BookMetadataRepository();
		$this->relationships = new BookRelationshipsRepository();
		$this->borrower_repo = new BorrowerRepository();
	}

	/**
	 * Build the full public HTML for a single book.
	 *
	 * @param int    $post_id         Book post ID.
	 * @param string $description_html Already-filtered post content HTML.
	 * @return string Escaped HTML ready for output, or empty string for hidden/missing books.
	 */
	public function render( int $post_id, string $description_html ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$fields       = $this->metadata->get( $post_id );
		$public       = BookMetadata::public_payload( $fields );
		$availability = Availability::for_book( $post_id );

		if ( Statuses::AVAILABILITY_HIDDEN === $availability['status'] ) {
			return '';
		}

		$title = (string) ( $post->post_title ?? '' );

		$sidebar = $this->cover_section( $post_id, $title )
			. $this->status_section( $availability )
			. $this->notice_section()
			. $this->action_panel( $post_id, $availability );

		$main_parts = array(
			$this->authors_section( $post_id ),
			$this->series_section( $post_id ),
			$this->details_section( $public ),
			'' !== $description_html
				? '<div class="connectlibrary-book__description">' . $description_html . '</div>'
				: '',
			$this->notes_section( $public ),
			$this->terms_section( $post_id, BookTaxonomies::TAXONOMY_CATEGORY, __( 'Categories', 'connectlibrary' ) ),
			$this->terms_section( $post_id, BookTaxonomies::TAXONOMY_TAG, __( 'Tags', 'connectlibrary' ) ),
			$this->terms_section( $post_id, BookTaxonomies::TAXONOMY_AGE_LEVEL, __( 'Age / Reading Level', 'connectlibrary' ) ),
			$this->location_section( $fields ),
			$this->catalog_nav(),
		);

		$main = implode( '', array_filter( $main_parts ) );

		return '<div class="connectlibrary-book">'
			. '<div class="connectlibrary-book__sidebar">' . $sidebar . '</div>'
			. '<div class="connectlibrary-book__main">' . $main . '</div>'
			. '</div>';
	}

	/**
	 * Build the cover image or fallback placeholder HTML.
	 *
	 * @param int    $post_id Book post ID.
	 * @param string $title   Book title used for alt text.
	 * @return string HTML string.
	 */
	private function cover_section( int $post_id, string $title ): string {
		$attachment_id = get_post_thumbnail_id( $post_id );

		if ( $attachment_id ) {
			$alt = sprintf(
				/* translators: %s: book title. */
				__( 'Cover of %s', 'connectlibrary' ),
				$title
			);
			$img = wp_get_attachment_image(
				$attachment_id,
				array( 200, 300 ),
				false,
				array(
					'class' => 'connectlibrary-book__cover-image',
					'alt'   => $alt,
				)
			);
			if ( '' !== $img ) {
				return '<div class="connectlibrary-book__cover">' . $img . '</div>';
			}
		}

		return '<div class="connectlibrary-book__cover connectlibrary-book__cover--no-image">'
			. '<span class="connectlibrary-book__cover-placeholder" aria-label="'
			. esc_attr( __( 'No cover image', 'connectlibrary' ) )
			. '"></span></div>';
	}

	/**
	 * Build the public availability status badge HTML.
	 *
	 * Uses a CSS modifier class and a visible text label so status is never
	 * communicated by colour alone.
	 *
	 * @param array<string,string> $availability Availability response from Availability::for_book().
	 * @return string HTML string.
	 */
	private function status_section( array $availability ): string {
		$modifier = esc_attr( str_replace( '_', '-', $availability['status'] ) );
		$label    = esc_html( $availability['label'] );

		return '<div class="connectlibrary-book__status connectlibrary-book__status--' . $modifier . '">'
			. '<span class="connectlibrary-book__status-label">' . $label . '</span>'
			. '</div>';
	}

	/**
	 * Build the author by-line HTML.
	 *
	 * @param int $post_id Book post ID.
	 * @return string HTML string, or empty string if no authors.
	 */
	private function authors_section( int $post_id ): string {
		$author_ids = $this->relationships->get_author_ids( $post_id );
		if ( empty( $author_ids ) ) {
			return '';
		}

		$labels = array();
		foreach ( $this->relationships->list_authors() as $author ) {
			if ( in_array( absint( $author['id'] ?? 0 ), $author_ids, true ) ) {
				$labels[] = esc_html( (string) ( $author['display_name'] ?? '' ) );
			}
		}

		if ( empty( $labels ) ) {
			return '';
		}

		return '<p class="connectlibrary-book__authors">'
			. '<span class="connectlibrary-book__meta-label">' . esc_html__( 'By', 'connectlibrary' ) . '</span>'
			. ' ' . implode( ', ', $labels )
			. '</p>';
	}

	/**
	 * Build the series by-line HTML.
	 *
	 * @param int $post_id Book post ID.
	 * @return string HTML string, or empty string if no series.
	 */
	private function series_section( int $post_id ): string {
		$selection = $this->relationships->get_series_selection( $post_id );
		$series_id = absint( $selection['series_id'] ?? 0 );
		if ( $series_id <= 0 ) {
			return '';
		}

		$series_name = '';
		$position    = (string) ( $selection['series_position'] ?? '' );

		foreach ( $this->relationships->list_series() as $series ) {
			if ( absint( $series['id'] ?? 0 ) === $series_id ) {
				$series_name = (string) ( $series['name'] ?? '' );
				break;
			}
		}

		if ( '' === $series_name ) {
			return '';
		}

		$text = esc_html( $series_name );
		if ( '' !== $position ) {
			/* translators: %s: series volume or number. */
			$text .= ' &middot; ' . sprintf( esc_html__( '#%s', 'connectlibrary' ), esc_html( $position ) );
		}

		return '<p class="connectlibrary-book__series">'
			. '<span class="connectlibrary-book__meta-label">' . esc_html__( 'Series', 'connectlibrary' ) . '</span>'
			. ' ' . $text
			. '</p>';
	}

	/**
	 * Build the details definition list HTML.
	 *
	 * Only renders rows that have a non-empty value.  Never exposes internal
	 * IDs, private notes, or borrower data.
	 *
	 * @param array<string,mixed> $payload Public-safe metadata from BookMetadata::public_payload().
	 * @return string HTML string, or empty string if all detail fields are empty.
	 */
	private function details_section( array $payload ): string {
		$rows = array();

		if ( ! empty( $payload['subtitle'] ) ) {
			$rows[] = $this->detail_row( __( 'Subtitle', 'connectlibrary' ), esc_html( (string) $payload['subtitle'] ) );
		}

		$isbn = '';
		if ( ! empty( $payload['isbn_13'] ) ) {
			$isbn = esc_html( (string) $payload['isbn_13'] );
		} elseif ( ! empty( $payload['isbn_10'] ) ) {
			$isbn = esc_html( (string) $payload['isbn_10'] );
		}
		if ( '' !== $isbn ) {
			$rows[] = $this->detail_row( __( 'ISBN', 'connectlibrary' ), $isbn );
		}

		if ( ! empty( $payload['publisher'] ) ) {
			$rows[] = $this->detail_row( __( 'Publisher', 'connectlibrary' ), esc_html( (string) $payload['publisher'] ) );
		}

		if ( ! empty( $payload['published_date'] ) ) {
			$rows[] = $this->detail_row( __( 'Published', 'connectlibrary' ), esc_html( (string) $payload['published_date'] ) );
		}

		if ( ! empty( $payload['language'] ) ) {
			$rows[] = $this->detail_row( __( 'Language', 'connectlibrary' ), esc_html( strtoupper( (string) $payload['language'] ) ) );
		}

		$page_count = (int) ( $payload['page_count'] ?? 0 );
		if ( $page_count > 0 ) {
			$rows[] = $this->detail_row( __( 'Pages', 'connectlibrary' ), esc_html( (string) $page_count ) );
		}

		if ( ! empty( $payload['age_level'] ) ) {
			$rows[] = $this->detail_row( __( 'Age Level', 'connectlibrary' ), esc_html( (string) $payload['age_level'] ) );
		}

		if ( ! empty( $payload['reading_level'] ) ) {
			$rows[] = $this->detail_row( __( 'Reading Level', 'connectlibrary' ), esc_html( (string) $payload['reading_level'] ) );
		}

		if ( empty( $rows ) ) {
			return '';
		}

		return '<dl class="connectlibrary-book__details">' . implode( '', $rows ) . '</dl>';
	}

	/**
	 * Build a single definition-list row.
	 *
	 * @param string $label         Translated row label (will be escaped).
	 * @param string $escaped_value Pre-escaped value HTML.
	 * @return string HTML string.
	 */
	private function detail_row( string $label, string $escaped_value ): string {
		return '<div class="connectlibrary-book__detail">'
			. '<dt>' . esc_html( $label ) . '</dt>'
			. '<dd>' . $escaped_value . '</dd>'
			. '</div>';
	}

	/**
	 * Build public notes sections HTML.
	 *
	 * Renders the recommended badge, content notes, and church/library notes
	 * when they carry a non-empty value.  Never exposes private librarian notes.
	 *
	 * @param array<string,mixed> $payload Public-safe metadata payload.
	 * @return string HTML string.
	 */
	private function notes_section( array $payload ): string {
		$out = '';

		if ( ! empty( $payload['recommended'] ) ) {
			$out .= '<p class="connectlibrary-book__recommended">'
				. '<strong>' . esc_html__( 'Recommended by the librarian', 'connectlibrary' ) . '</strong>'
				. '</p>';
		}

		if ( ! empty( $payload['content_notes'] ) ) {
			$out .= '<div class="connectlibrary-book__content-notes">'
				. '<h2 class="connectlibrary-book__section-heading">' . esc_html__( 'Content Notes', 'connectlibrary' ) . '</h2>'
				. '<p>' . esc_html( (string) $payload['content_notes'] ) . '</p>'
				. '</div>';
		}

		if ( ! empty( $payload['church_notes'] ) ) {
			$out .= '<div class="connectlibrary-book__church-notes">'
				. '<h2 class="connectlibrary-book__section-heading">' . esc_html__( 'Library Notes', 'connectlibrary' ) . '</h2>'
				. '<p>' . esc_html( (string) $payload['church_notes'] ) . '</p>'
				. '</div>';
		}

		return $out;
	}

	/**
	 * Build a taxonomy terms group HTML.
	 *
	 * Links use get_term_link() when the term link is available; otherwise
	 * names are rendered as plain text so visitors see the value even when
	 * the archive route does not exist yet.
	 *
	 * @param int    $post_id  Book post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $label    Translated label for the group.
	 * @return string HTML string, or empty string if no terms are assigned.
	 */
	private function terms_section( int $post_id, string $taxonomy, string $label ): string {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		$items = array();
		foreach ( $terms as $term ) {
			$name = esc_html( (string) ( $term->name ?? '' ) );
			$link = get_term_link( $term, $taxonomy );
			if ( is_string( $link ) && '' !== $link ) {
				$items[] = '<a href="' . esc_url( $link ) . '" class="connectlibrary-book__term-link">' . $name . '</a>';
			} else {
				$items[] = '<span class="connectlibrary-book__term-name">' . $name . '</span>';
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		return '<div class="connectlibrary-book__terms connectlibrary-book__terms--' . esc_attr( sanitize_key( $taxonomy ) ) . '">'
			. '<span class="connectlibrary-book__meta-label">' . esc_html( $label ) . ':</span>'
			. ' ' . implode( ', ', $items )
			. '</div>';
	}

	/**
	 * Build the location section HTML.
	 *
	 * Shows room, shelf, and section when at least one is set.  Internal copy
	 * IDs and private notes are never included.
	 *
	 * @param array<string,mixed> $fields Full metadata fields (only public location keys are read).
	 * @return string HTML string, or empty string if all location fields are empty.
	 */
	private function location_section( array $fields ): string {
		$room    = (string) ( $fields['room'] ?? '' );
		$shelf   = (string) ( $fields['shelf'] ?? '' );
		$section = (string) ( $fields['section'] ?? '' );

		if ( '' === $room && '' === $shelf && '' === $section ) {
			return '';
		}

		$parts = array();
		if ( '' !== $room ) {
			$parts[] = esc_html__( 'Room', 'connectlibrary' ) . ': ' . esc_html( $room );
		}
		if ( '' !== $shelf ) {
			$parts[] = esc_html__( 'Shelf', 'connectlibrary' ) . ': ' . esc_html( $shelf );
		}
		if ( '' !== $section ) {
			$parts[] = esc_html__( 'Section', 'connectlibrary' ) . ': ' . esc_html( $section );
		}

		return '<div class="connectlibrary-book__location">'
			. '<h2 class="connectlibrary-book__section-heading">' . esc_html__( 'Location', 'connectlibrary' ) . '</h2>'
			. '<p class="connectlibrary-book__location-detail">' . implode( ' &bull; ', $parts ) . '</p>'
			. '</div>';
	}

	/**
	 * Build the pending-notice section HTML.
	 *
	 * Reads the static notice set by PublicReservationRequests and renders
	 * a privacy-safe success or error banner.  Returns empty string when no
	 * notice is pending so existing pages are unaffected.
	 *
	 * @return string HTML string, or empty string when no notice is pending.
	 */
	private function notice_section(): string {
		$notice = PublicReservationRequests::get_notice();
		if ( null === $notice ) {
			return '';
		}

		$type    = 'error' === ( $notice['type'] ?? '' ) ? 'error' : 'success';
		$message = esc_html( (string) ( $notice['message'] ?? '' ) );

		return '<div class="connectlibrary-book__notice connectlibrary-book__notice--' . esc_attr( $type ) . '">'
			. $message
			. '</div>';
	}

	/**
	 * Build the reservation action panel HTML for the sidebar.
	 *
	 * Three cases:
	 * - Available + logged-in active borrower  → one-click hold form.
	 * - Available + guest or non-borrower user → guest request form.
	 * - Not available / waitlist states        → privacy-safe informational notice.
	 *
	 * Hidden books never reach this method (render() returns early).
	 * No borrower names, emails, or IDs are ever emitted here.
	 *
	 * @param int                  $post_id      Book post ID.
	 * @param array<string,string> $availability Availability result from Availability::for_book().
	 * @return string HTML string.
	 */
	private function action_panel( int $post_id, array $availability ): string {
		$action = $availability['request_action'] ?? '';

		if ( 'none' === $action ) {
			return '';
		}

		if ( 'reserve' === $action ) {
			return $this->reserve_action( $post_id );
		}

		if ( 'waitlist' === $action ) {
			return $this->waitlist_action( $post_id );
		}

		// contact_librarian or other: privacy-safe informational notice.
		return '<div class="connectlibrary-book__reserve-panel">'
			. '<p class="connectlibrary-book__reserve-notice">'
			. esc_html__( 'This book is currently unavailable. Please contact the librarian for assistance.', 'connectlibrary' )
			. '</p>'
			. '</div>';
	}

	/**
	 * Build the waitlist action panel for an unavailable book.
	 *
	 * Shows a one-click join form for logged-in borrowers. Guests see the
	 * standard guest request form so the librarian can approve/queue them.
	 * No borrower names, emails, or queue identities are ever emitted here.
	 *
	 * @param int $post_id Book post ID.
	 * @return string HTML string.
	 */
	private function waitlist_action( int $post_id ): string {
		$user_id  = get_current_user_id();
		$borrower = $user_id > 0 ? $this->borrower_repo->find_by_wp_user_id( $user_id ) : null;

		if ( null !== $borrower ) {
			return $this->waitlist_join_form( $post_id );
		}

		return $this->guest_request_form( $post_id );
	}

	/**
	 * Build the one-click waitlist join form for logged-in borrowers.
	 *
	 * @param int $post_id Book post ID.
	 * @return string HTML string.
	 */
	private function waitlist_join_form( int $post_id ): string {
		$action_url = esc_url( get_permalink( $post_id ) );
		$nonce      = esc_attr( wp_create_nonce( PublicReservationRequests::waitlist_nonce_action( $post_id ) ) );
		$book_id    = esc_attr( (string) $post_id );

		return '<div class="connectlibrary-book__reserve-panel">'
			. '<p class="connectlibrary-book__reserve-notice">'
			. esc_html__( 'This book is currently unavailable.', 'connectlibrary' )
			. '</p>'
			. '<form method="post" action="' . $action_url . '" class="connectlibrary-book__waitlist-form">'
			. '<input type="hidden" name="connectlibrary_action" value="join_waitlist">'
			. '<input type="hidden" name="connectlibrary_book_id" value="' . $book_id . '">'
			. '<input type="hidden" name="' . esc_attr( PublicReservationRequests::NONCE_FIELD_WAITLIST ) . '" value="' . $nonce . '">'
			. '<button type="submit" class="connectlibrary-book__reserve-button">'
			. esc_html__( 'Join Waitlist', 'connectlibrary' )
			. '</button>'
			. '</form>'
			. '</div>';
	}

	/**
	 * Build the reserve-action panel for an available book.
	 *
	 * Shows the borrower hold button when the current user has an active
	 * borrower record, or the guest request form otherwise.
	 *
	 * @param int $post_id Book post ID.
	 * @return string HTML string.
	 */
	private function reserve_action( int $post_id ): string {
		$user_id  = get_current_user_id();
		$borrower = $user_id > 0 ? $this->borrower_repo->find_by_wp_user_id( $user_id ) : null;

		if ( null !== $borrower ) {
			return $this->hold_button_form( $post_id );
		}

		return $this->guest_request_form( $post_id );
	}

	/**
	 * Build the one-click borrower hold form HTML.
	 *
	 * @param int $post_id Book post ID.
	 * @return string HTML string.
	 */
	private function hold_button_form( int $post_id ): string {
		$action_url = esc_url( get_permalink( $post_id ) );
		$nonce      = esc_attr( wp_create_nonce( PublicReservationRequests::hold_nonce_action( $post_id ) ) );
		$book_id    = esc_attr( (string) $post_id );

		return '<div class="connectlibrary-book__reserve-panel">'
			. '<form method="post" action="' . $action_url . '" class="connectlibrary-book__hold-form">'
			. '<input type="hidden" name="connectlibrary_action" value="reserve_hold">'
			. '<input type="hidden" name="connectlibrary_book_id" value="' . $book_id . '">'
			. '<input type="hidden" name="' . esc_attr( PublicReservationRequests::NONCE_FIELD_HOLD ) . '" value="' . $nonce . '">'
			. '<button type="submit" class="connectlibrary-book__reserve-button">'
			. esc_html__( 'Reserve this book', 'connectlibrary' )
			. '</button>'
			. '</form>'
			. '</div>';
	}

	/**
	 * Build the guest reservation request form HTML.
	 *
	 * Includes a honeypot hidden field for basic bot filtering.
	 * Name and email are required; phone and note are optional.
	 *
	 * @param int $post_id Book post ID.
	 * @return string HTML string.
	 */
	private function guest_request_form( int $post_id ): string {
		$action_url = esc_url( get_permalink( $post_id ) );
		$nonce      = esc_attr( wp_create_nonce( PublicReservationRequests::guest_nonce_action( $post_id ) ) );
		$book_id    = esc_attr( (string) $post_id );
		$honeypot   = esc_attr( PublicReservationRequests::HONEYPOT_FIELD );

		return '<div class="connectlibrary-book__reserve-panel">'
			. '<h2 class="connectlibrary-book__section-heading">' . esc_html__( 'Request this book', 'connectlibrary' ) . '</h2>'
			. '<form method="post" action="' . $action_url . '" class="connectlibrary-book__guest-form">'
			. '<input type="hidden" name="connectlibrary_action" value="guest_request">'
			. '<input type="hidden" name="connectlibrary_book_id" value="' . $book_id . '">'
			. '<input type="hidden" name="' . esc_attr( PublicReservationRequests::NONCE_FIELD_GUEST ) . '" value="' . $nonce . '">'
			. '<div aria-hidden="true" class="connectlibrary-book__hp-field">'
			. '<label for="' . $honeypot . '">' . esc_html__( 'Leave this field empty', 'connectlibrary' ) . '</label>'
			. '<input type="text" id="' . $honeypot . '" name="' . $honeypot . '" tabindex="-1" autocomplete="off" value="">'
			. '</div>'
			. '<div class="connectlibrary-book__form-field">'
			. '<label for="cl_guest_name">' . esc_html__( 'Name', 'connectlibrary' ) . ' <span aria-hidden="true">*</span></label>'
			. '<input type="text" id="cl_guest_name" name="cl_guest_name" required autocomplete="name">'
			. '</div>'
			. '<div class="connectlibrary-book__form-field">'
			. '<label for="cl_guest_email">' . esc_html__( 'Email', 'connectlibrary' ) . ' <span aria-hidden="true">*</span></label>'
			. '<input type="email" id="cl_guest_email" name="cl_guest_email" required autocomplete="email">'
			. '</div>'
			. '<div class="connectlibrary-book__form-field">'
			. '<label for="cl_guest_phone">' . esc_html__( 'Phone (optional)', 'connectlibrary' ) . '</label>'
			. '<input type="tel" id="cl_guest_phone" name="cl_guest_phone" autocomplete="tel">'
			. '</div>'
			. '<div class="connectlibrary-book__form-field">'
			. '<label for="cl_guest_note">' . esc_html__( 'Note (optional)', 'connectlibrary' ) . '</label>'
			. '<textarea id="cl_guest_note" name="cl_guest_note" rows="3"></textarea>'
			. '</div>'
			. '<button type="submit" class="connectlibrary-book__reserve-button">'
			. esc_html__( 'Send Request', 'connectlibrary' )
			. '</button>'
			. '</form>'
			. '</div>';
	}

	/**
	 * Build the back-to-catalog navigation link HTML.
	 *
	 * Only outputs the link when a catalog page ID is configured in Settings.
	 * Never hard-codes a URL; uses get_permalink() via the configured page ID.
	 *
	 * @return string HTML string, or empty string if no catalog page is configured.
	 */
	private function catalog_nav(): string {
		$catalog_page_id = (int) Settings::get( 'catalog_page_id' );
		if ( $catalog_page_id <= 0 ) {
			return '';
		}

		$url = get_permalink( $catalog_page_id );
		if ( ! $url ) {
			return '';
		}

		return '<nav class="connectlibrary-book__navigation" aria-label="' . esc_attr( __( 'Library navigation', 'connectlibrary' ) ) . '">'
			. '<a href="' . esc_url( $url ) . '" class="connectlibrary-book__back-link">'
			. '&larr; ' . esc_html__( 'Back to catalog', 'connectlibrary' )
			. '</a>'
			. '</nav>';
	}
}
