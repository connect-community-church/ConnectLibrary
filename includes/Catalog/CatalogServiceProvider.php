<?php
/**
 * Catalog registration coordinator.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Catalog;

use ConnectLibrary\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Wires catalog domain registration into WordPress hooks.
 */
final class CatalogServiceProvider {
	/**
	 * Book post type registrar.
	 *
	 * @var BookPostType
	 */
	private BookPostType $book_post_type;

	/**
	 * Book taxonomy registrar.
	 *
	 * @var BookTaxonomies
	 */
	private BookTaxonomies $book_taxonomies;

	/**
	 * Public availability registrar.
	 *
	 * @var BookAvailability
	 */
	private BookAvailability $book_availability;

	/**
	 * REST API route registrar.
	 *
	 * @var Routes
	 */
	private Routes $routes;

	/**
	 * Public catalog shortcode and block registrar.
	 *
	 * @var PublicCatalog
	 */
	private PublicCatalog $public_catalog;

	/**
	 * Create the catalog service provider.
	 */
	public function __construct() {
		$this->book_post_type    = new BookPostType();
		$this->book_taxonomies   = new BookTaxonomies();
		$this->book_availability = new BookAvailability();
		$this->routes            = new Routes();
		$this->public_catalog    = new PublicCatalog();
	}

	/**
	 * Register catalog hooks.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_catalog_objects' ) );
		$this->book_availability->register();
		$this->routes->register();
		$this->public_catalog->register();
	}

	/**
	 * Register catalog objects immediately for activation-time rewrite flushing.
	 */
	public static function register_catalog_objects(): void {
		$provider = new self();

		$provider->book_post_type->register();
		$provider->book_taxonomies->register();
	}
}
