<?php
/**
 * Tests for catalog post type and taxonomy registration.
 *
 * @package ConnectLibrary
 */

declare( strict_types=1 );

namespace ConnectLibrary\Tests\Unit;

use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\BookTaxonomies;
use ConnectLibrary\Catalog\CatalogServiceProvider;
use PHPUnit\Framework\TestCase;

use function post_type_exists;
use function taxonomy_exists;

/**
 * Verifies the Phase 1 catalog registration surface.
 */
final class CatalogRegistrationTest extends TestCase {
    /**
     * Reset registration captures before each test.
     */
    protected function setUp(): void {
        $GLOBALS['connectlibrary_test_post_types'] = array();
        $GLOBALS['connectlibrary_test_taxonomies'] = array();
        $GLOBALS['connectlibrary_test_flush_count'] = 0;
    }

    public function test_book_post_type_is_registered_for_rest_and_library_rewrites(): void {
        ( new BookPostType() )->register();

        self::assertTrue( post_type_exists( BookPostType::POST_TYPE ) );

        $args = $GLOBALS['connectlibrary_test_post_types'][ BookPostType::POST_TYPE ];

        self::assertSame( 'connectlibrary-books', $args['rest_base'] );
        self::assertTrue( $args['show_in_rest'] );
        self::assertTrue( $args['public'] );
        self::assertSame( 'library', $args['has_archive'] );
        self::assertSame( 'library/book', $args['rewrite']['slug'] );
        self::assertSame( array( 'title', 'editor', 'thumbnail', 'excerpt' ), $args['supports'] );
        self::assertSame( 'dashicons-book-alt', $args['menu_icon'] );
        self::assertSame( 'Library', $args['labels']['menu_name'] );
    }

    public function test_only_core_book_taxonomies_are_registered_and_attached_to_books(): void {
        ( new BookTaxonomies() )->register();

        $expected_taxonomies = array(
            BookTaxonomies::TAXONOMY_CATEGORY,
            BookTaxonomies::TAXONOMY_TAG,
            BookTaxonomies::TAXONOMY_AGE_LEVEL,
            BookTaxonomies::TAXONOMY_AUDIENCE,
            BookTaxonomies::TAXONOMY_COLLECTION,
        );

        self::assertSame( $expected_taxonomies, array_keys( $GLOBALS['connectlibrary_test_taxonomies'] ) );

        foreach ( $expected_taxonomies as $taxonomy ) {
            self::assertTrue( taxonomy_exists( $taxonomy ) );
            self::assertSame(
                array( BookPostType::POST_TYPE ),
                $GLOBALS['connectlibrary_test_taxonomies'][ $taxonomy ]['object_type']
            );
            self::assertTrue( $GLOBALS['connectlibrary_test_taxonomies'][ $taxonomy ]['args']['show_in_rest'] );
            self::assertTrue( $GLOBALS['connectlibrary_test_taxonomies'][ $taxonomy ]['args']['show_ui'] );
        }

        self::assertFalse( taxonomy_exists( 'connectlibrary_author' ) );
        self::assertFalse( taxonomy_exists( 'connectlibrary_series' ) );
        self::assertFalse( taxonomy_exists( 'connectlibrary_availability' ) );
    }

    public function test_taxonomy_hierarchy_and_rewrite_configuration_matches_spec(): void {
        ( new BookTaxonomies() )->register();

        $taxonomies = $GLOBALS['connectlibrary_test_taxonomies'];

        self::assertTrue( $taxonomies[ BookTaxonomies::TAXONOMY_CATEGORY ]['args']['hierarchical'] );
        self::assertSame( 'library/category', $taxonomies[ BookTaxonomies::TAXONOMY_CATEGORY ]['args']['rewrite']['slug'] );

        self::assertFalse( $taxonomies[ BookTaxonomies::TAXONOMY_TAG ]['args']['hierarchical'] );
        self::assertSame( 'library/tag', $taxonomies[ BookTaxonomies::TAXONOMY_TAG ]['args']['rewrite']['slug'] );

        self::assertTrue( $taxonomies[ BookTaxonomies::TAXONOMY_AGE_LEVEL ]['args']['hierarchical'] );
        self::assertSame( 'library/age-level', $taxonomies[ BookTaxonomies::TAXONOMY_AGE_LEVEL ]['args']['rewrite']['slug'] );

        self::assertTrue( $taxonomies[ BookTaxonomies::TAXONOMY_AUDIENCE ]['args']['hierarchical'] );
        self::assertSame( 'library/audience', $taxonomies[ BookTaxonomies::TAXONOMY_AUDIENCE ]['args']['rewrite']['slug'] );

        self::assertFalse( $taxonomies[ BookTaxonomies::TAXONOMY_COLLECTION ]['args']['hierarchical'] );
        self::assertSame( 'library/collection', $taxonomies[ BookTaxonomies::TAXONOMY_COLLECTION ]['args']['rewrite']['slug'] );
    }

    public function test_activation_registration_helper_registers_catalog_without_flushing(): void {
        CatalogServiceProvider::register_catalog_objects();

        self::assertTrue( post_type_exists( BookPostType::POST_TYPE ) );
        self::assertTrue( taxonomy_exists( BookTaxonomies::TAXONOMY_CATEGORY ) );
        self::assertSame( 0, $GLOBALS['connectlibrary_test_flush_count'] );
    }
}
