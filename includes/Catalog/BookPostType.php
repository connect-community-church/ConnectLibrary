<?php
/**
 * Book custom post type registration.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Book content type used by the catalog foundation.
 */
final class BookPostType {
	public const POST_TYPE = 'connectlibrary_book';
	public const REST_BASE = 'connectlibrary-books';

	/**
	 * Register the Book custom post type.
	 */
	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => $this->get_labels(),
				'description'        => __( 'Books in the Connect Community Church library catalog.', 'connectlibrary' ),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => true,
				'show_in_admin_bar'  => true,
				'show_in_rest'       => true,
				'rest_base'          => self::REST_BASE,
				'menu_position'      => 20,
				'menu_icon'          => 'dashicons-book-alt',
				'capability_type'    => 'post',
				'has_archive'        => 'library',
				'hierarchical'       => false,
				'rewrite'            => array(
					'slug'       => 'library/book',
					'with_front' => false,
				),
				'query_var'          => true,
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			)
		);
	}

	/**
	 * Build translated post type labels.
	 *
	 * @return array<string,string>
	 */
	private function get_labels(): array {
		return array(
			'name'                     => _x( 'Books', 'post type general name', 'connectlibrary' ),
			'singular_name'            => _x( 'Book', 'post type singular name', 'connectlibrary' ),
			'menu_name'                => _x( 'Library', 'admin menu', 'connectlibrary' ),
			'name_admin_bar'           => _x( 'Book', 'add new on admin bar', 'connectlibrary' ),
			'add_new'                  => _x( 'Add New', 'book', 'connectlibrary' ),
			'add_new_item'             => __( 'Add New Book', 'connectlibrary' ),
			'new_item'                 => __( 'New Book', 'connectlibrary' ),
			'edit_item'                => __( 'Edit Book', 'connectlibrary' ),
			'view_item'                => __( 'View Book', 'connectlibrary' ),
			'all_items'                => __( 'All Books', 'connectlibrary' ),
			'search_items'             => __( 'Search Books', 'connectlibrary' ),
			'parent_item_colon'        => __( 'Parent Books:', 'connectlibrary' ),
			'not_found'                => __( 'No books found.', 'connectlibrary' ),
			'not_found_in_trash'       => __( 'No books found in Trash.', 'connectlibrary' ),
			'featured_image'           => __( 'Book cover image', 'connectlibrary' ),
			'set_featured_image'       => __( 'Set book cover image', 'connectlibrary' ),
			'remove_featured_image'    => __( 'Remove book cover image', 'connectlibrary' ),
			'use_featured_image'       => __( 'Use as book cover image', 'connectlibrary' ),
			'archives'                 => __( 'Book archives', 'connectlibrary' ),
			'insert_into_item'         => __( 'Insert into book', 'connectlibrary' ),
			'uploaded_to_this_item'    => __( 'Uploaded to this book', 'connectlibrary' ),
			'filter_items_list'        => __( 'Filter books list', 'connectlibrary' ),
			'items_list_navigation'    => __( 'Books list navigation', 'connectlibrary' ),
			'items_list'               => __( 'Books list', 'connectlibrary' ),
			'item_published'           => __( 'Book published.', 'connectlibrary' ),
			'item_published_privately' => __( 'Book published privately.', 'connectlibrary' ),
			'item_reverted_to_draft'   => __( 'Book reverted to draft.', 'connectlibrary' ),
			'item_trashed'             => __( 'Book trashed.', 'connectlibrary' ),
			'item_scheduled'           => __( 'Book scheduled.', 'connectlibrary' ),
			'item_updated'             => __( 'Book updated.', 'connectlibrary' ),
		);
	}
}
