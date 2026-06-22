<?php
/**
 * Admin metaboxes for Book metadata.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.VariableComment.Missing

use ConnectLibrary\Audit\AuditEventService;
use ConnectLibrary\Catalog\BookMetadata;
use ConnectLibrary\Catalog\BookMetadataRepository;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Catalog\BookRelationshipsRepository;
use ConnectLibrary\Catalog\BookTaxonomies;
use ConnectLibrary\Catalog\CoverImporter;
use ConnectLibrary\Catalog\Isbn;
use ConnectLibrary\Catalog\IsbnDuplicateDetector;
use ConnectLibrary\Catalog\IsbnMetadata;
use ConnectLibrary\Catalog\IsbnMetadataLookupService;
use ConnectLibrary\Support\Capabilities;
use ConnectLibrary\Support\ScannerInput;
use ConnectLibrary\Support\Statuses;
use WP_Post;

/**
 * Registers librarian-friendly metadata fields on the Book edit screen.
 */
final class BookMetadataMetaboxes {
	private const NONCE_ACTION = 'connectlibrary_save_book_metadata';
	private const NONCE_NAME   = 'connectlibrary_book_metadata_nonce';
	private const FIELD_NAME   = 'connectlibrary_book_metadata';
	private const LOOKUP_META  = '_connectlibrary_pending_isbn_lookup';
	private const AUDIT_ENTITY = 'book';

	private BookMetadataRepository $metadata;
	private BookRelationshipsRepository $relationships;

	/**
	 * Create the metabox coordinator.
	 */
	public function __construct() {
		$this->metadata      = new BookMetadataRepository();
		$this->relationships = new BookRelationshipsRepository();
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_filter( 'use_block_editor_for_post_type', array( $this, 'use_classic_editor_for_books' ), 10, 2 );
		add_action( 'add_meta_boxes_' . BookPostType::POST_TYPE, array( $this, 'add_metaboxes' ) );
		add_action( 'edit_form_top', array( $this, 'render_isbn_lookup_top_area' ) );
		add_action( 'save_post_' . BookPostType::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'wp_ajax_connectlibrary_isbn_lookup', array( $this, 'ajax_lookup_isbn_metadata' ) );
		add_action( 'wp_ajax_connectlibrary_import_isbn_cover', array( $this, 'ajax_import_isbn_cover' ) );
	}

	/**
	 * Keep the librarian book-entry screen in classic editor mode so metabox workflows are visible.
	 *
	 * @param bool   $use_block_editor Whether WordPress would use the block editor.
	 * @param string $post_type Post type being edited.
	 */
	public function use_classic_editor_for_books( bool $use_block_editor, string $post_type ): bool {
		if ( BookPostType::POST_TYPE === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Add grouped metaboxes.
	 */
	public function add_metaboxes(): void {
		add_meta_box(
			'connectlibrary_book_catalog_details',
			__( 'Catalog details', 'connectlibrary' ),
			array( $this, 'render_catalog_details' ),
			BookPostType::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'connectlibrary_book_authors_series',
			__( 'Authors and series', 'connectlibrary' ),
			array( $this, 'render_authors_series' ),
			BookPostType::POST_TYPE,
			'normal',
			'default'
		);
		add_meta_box(
			'connectlibrary_book_location_status',
			__( 'Location and item status', 'connectlibrary' ),
			array( $this, 'render_location_status' ),
			BookPostType::POST_TYPE,
			'side',
			'default'
		);
		add_meta_box(
			'connectlibrary_book_public_notes',
			__( 'Public notes and visibility', 'connectlibrary' ),
			array( $this, 'render_public_notes' ),
			BookPostType::POST_TYPE,
			'normal',
			'default'
		);
		add_meta_box(
			'connectlibrary_book_private_notes',
			__( 'Librarian/internal notes', 'connectlibrary' ),
			array( $this, 'render_private_notes' ),
			BookPostType::POST_TYPE,
			'normal',
			'default'
		);
		add_meta_box(
			'connectlibrary_book_source_details',
			__( 'Metadata source/import details', 'connectlibrary' ),
			array( $this, 'render_source_details' ),
			BookPostType::POST_TYPE,
			'normal',
			'low'
		);
	}

	/**
	 * Save submitted Book metadata.
	 *
	 * @param int     $post_id Book post ID.
	 * @param WP_Post $post Post object.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( BookPostType::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw    = isset( $_POST[ self::FIELD_NAME ] ) && is_array( $_POST[ self::FIELD_NAME ] ) ? wp_unslash( $_POST[ self::FIELD_NAME ] ) : array();
		$fields = BookMetadata::sanitize( $raw );

		if ( isset( $_POST['connectlibrary_apply_isbn_lookup'] ) ) {
			$fields = $this->apply_lookup_selection( $post_id, $fields );
			delete_post_meta( $post_id, self::LOOKUP_META );
		}

		$this->metadata->save( $post_id, $fields );
		$this->relationships->save( $post_id, $fields );

		if ( isset( $_POST['connectlibrary_isbn_lookup'] ) ) {
			$this->lookup_isbn_metadata( $post_id, $fields );
		}
	}

	/**
	 * Render catalog detail fields.
	 */
	public function render_catalog_details( WP_Post $post ): void {
		$fields = $this->load_fields( $post->ID );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$this->text_input( 'isbn_10', __( 'ISBN-10', 'connectlibrary' ), $fields['isbn_10'], __( 'Optional 10-digit ISBN. Hyphens are okay; they are cleaned when saved.', 'connectlibrary' ) );
		$this->text_input( 'isbn_13', __( 'ISBN-13', 'connectlibrary' ), $fields['isbn_13'], __( 'Optional 13-digit ISBN used by later import and catalog workflows.', 'connectlibrary' ) );
		$this->text_input( 'subtitle', __( 'Subtitle', 'connectlibrary' ), $fields['subtitle'], __( 'Shown with the book title in later catalog views.', 'connectlibrary' ) );
		$this->text_input( 'publisher', __( 'Publisher', 'connectlibrary' ), $fields['publisher'] );
		$this->text_input( 'published_date', __( 'Publication date/year', 'connectlibrary' ), $fields['published_date'], __( 'Use a year or full date if known.', 'connectlibrary' ) );
		$this->text_input( 'language', __( 'Language', 'connectlibrary' ), $fields['language'], __( 'Short label such as English or en.', 'connectlibrary' ) );
		$this->number_input( 'page_count', __( 'Page count', 'connectlibrary' ), (int) $fields['page_count'] );
		$this->text_input( 'age_level', __( 'Age/reading level note', 'connectlibrary' ), $fields['age_level'], __( 'Use the Age / Reading Levels taxonomy for structured browsing; this note is for imported or edition-specific wording.', 'connectlibrary' ) );
		$this->text_input( 'reading_level', __( 'Reading level detail', 'connectlibrary' ), $fields['reading_level'] );
		echo '<p class="description">' . esc_html__( 'Use the Book cover image box for the main cover and the Book Categories/Tags/Age taxonomies for browsing.', 'connectlibrary' ) . '</p>';
	}

	/**
	 * Render author and series selectors.
	 */
	public function render_authors_series( WP_Post $post ): void {
		$fields     = $this->load_fields( $post->ID );
		$author_ids = $this->relationships->get_author_ids( $post->ID );
		$series     = $this->relationships->get_series_selection( $post->ID );
		$authors    = $this->relationships->list_authors();
		$all_series = $this->relationships->list_series();
		?>
		<p><?php echo esc_html__( 'Choose existing authors from the custom author table, or add a basic new author name if needed.', 'connectlibrary' ); ?></p>
		<label for="connectlibrary_author_ids"><strong><?php echo esc_html__( 'Authors', 'connectlibrary' ); ?></strong></label>
		<select id="connectlibrary_author_ids" name="<?php echo esc_attr( self::FIELD_NAME ); ?>[author_ids][]" multiple="multiple" style="width:100%;min-height:8em;">
			<?php foreach ( $authors as $author ) : ?>
				<option value="<?php echo esc_attr( (string) $author['id'] ); ?>" <?php selected( in_array( absint( $author['id'] ), $author_ids, true ) ); ?>><?php echo esc_html( (string) $author['display_name'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( empty( $authors ) ) : ?>
			<p class="description"><?php echo esc_html__( 'No author records exist yet. Enter a new author below to create the first one.', 'connectlibrary' ); ?></p>
		<?php endif; ?>
		<?php $this->text_input( 'new_author_display_name', __( 'Add new author name', 'connectlibrary' ), $fields['new_author_display_name'], __( 'Creates a basic author record and links it to this book when saved.', 'connectlibrary' ) ); ?>
		<hr />
		<label for="connectlibrary_series_id"><strong><?php echo esc_html__( 'Primary series', 'connectlibrary' ); ?></strong></label>
		<select id="connectlibrary_series_id" name="<?php echo esc_attr( self::FIELD_NAME ); ?>[series_id]" style="width:100%;">
			<option value="0"><?php echo esc_html__( 'No series', 'connectlibrary' ); ?></option>
			<?php foreach ( $all_series as $series_row ) : ?>
				<option value="<?php echo esc_attr( (string) $series_row['id'] ); ?>" <?php selected( absint( $series_row['id'] ), $series['series_id'] ); ?>><?php echo esc_html( (string) $series_row['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( empty( $all_series ) ) : ?>
			<p class="description"><?php echo esc_html__( 'No series records exist yet. Enter a new series below if this book belongs to one.', 'connectlibrary' ); ?></p>
		<?php endif; ?>
		<?php
		$this->text_input( 'new_series_name', __( 'Add new series name', 'connectlibrary' ), $fields['new_series_name'], __( 'Creates a basic series record when saved.', 'connectlibrary' ) );
		$this->text_input( 'series_position', __( 'Series number/order', 'connectlibrary' ), $series['series_position'], __( 'Examples: 1, 2.5, Prequel, Book 4.', 'connectlibrary' ) );
	}

	/** Render location/status fields. */
	public function render_location_status( WP_Post $post ): void {
		$fields = $this->load_fields( $post->ID );
		$this->text_input( 'room', __( 'Room', 'connectlibrary' ), $fields['room'] );
		$this->text_input( 'shelf', __( 'Shelf', 'connectlibrary' ), $fields['shelf'] );
		$this->text_input( 'section', __( 'Section', 'connectlibrary' ), $fields['section'] );
		$this->select_input( 'condition_status', __( 'Condition', 'connectlibrary' ), $fields['condition_status'], Statuses::condition_labels() );
		$this->select_input( 'item_status', __( 'Item status', 'connectlibrary' ), $fields['item_status'], Statuses::item_labels() );
	}

	/** Render public notes and visibility fields. */
	public function render_public_notes( WP_Post $post ): void {
		$fields = $this->load_fields( $post->ID );
		$this->select_input( 'visibility', __( 'Public catalog visibility', 'connectlibrary' ), $fields['visibility'], BookMetadata::visibility_labels() );
		$this->checkbox_input( 'recommended', __( 'Librarian/pastoral recommended', 'connectlibrary' ), (bool) $fields['recommended'] );
		$this->textarea_input( 'church_notes', __( 'Public church note', 'connectlibrary' ), $fields['church_notes'], __( 'A short note that may be shown publicly later, such as suitability or pickup context.', 'connectlibrary' ) );
		$this->textarea_input( 'content_notes', __( 'Content/advisory notes', 'connectlibrary' ), $fields['content_notes'], __( 'Public rendering is decided by a later catalog card.', 'connectlibrary' ) );
	}

	/** Render private notes. */
	public function render_private_notes( WP_Post $post ): void {
		$fields = $this->load_fields( $post->ID );
		$this->textarea_input( 'private_notes', __( 'Internal librarian notes', 'connectlibrary' ), $fields['private_notes'], __( 'Private operational notes. These are not included in public-safe metadata payloads or REST meta registration.', 'connectlibrary' ) );
	}

	/** Render the ISBN lookup area before the title field. */
	public function render_isbn_lookup_top_area( WP_Post $post ): void {
		if ( BookPostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		$fields = $this->load_fields( $post->ID );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div id="connectlibrary-top-isbn-lookup" class="postbox" style="margin: 12px 0 16px; border-left: 4px solid #2271b1;">
			<div class="postbox-header">
				<h2><?php echo esc_html__( 'Scan ISBN / lookup metadata', 'connectlibrary' ); ?></h2>
			</div>
			<div class="inside">
				<p><?php echo esc_html__( 'Start here: click the ISBN field, scan the book barcode, then choose Lookup metadata. Most USB/Bluetooth scanners type the ISBN like a keyboard.', 'connectlibrary' ); ?></p>
				<?php $this->render_isbn_lookup_controls( $post, $fields ); ?>
			</div>
		</div>
		<?php
	}

	/** Render source details. */
	public function render_source_details( WP_Post $post ): void {
		$fields = $this->load_fields( $post->ID );
		$this->select_input( 'metadata_source', __( 'Metadata source', 'connectlibrary' ), $fields['metadata_source'], Statuses::metadata_source_labels() );
		$this->text_input( 'source_provider', __( 'Source provider', 'connectlibrary' ), $fields['source_provider'] );
		$this->text_input( 'source_record_id', __( 'Source record ID', 'connectlibrary' ), $fields['source_record_id'] );
		$this->text_input( 'source_record_link', __( 'Source record link', 'connectlibrary' ), $fields['source_record_link'] );
		$this->text_input( 'last_metadata_refresh', __( 'Last metadata refresh', 'connectlibrary' ), $fields['last_metadata_refresh'], __( 'Optional timestamp or date from a later import process.', 'connectlibrary' ) );
		$this->textarea_input( 'catalog_identifiers', __( 'Library identifiers', 'connectlibrary' ), $fields['catalog_identifiers'], __( 'Imported from library sources such as Open Library: OCLC, LCCN, Goodreads, LibraryThing, etc.', 'connectlibrary' ) );
		$this->textarea_input( 'library_classifications', __( 'Library classifications', 'connectlibrary' ), $fields['library_classifications'], __( 'Imported Dewey / Library of Congress values for librarian reference.', 'connectlibrary' ) );
		$this->text_input( 'physical_description', __( 'Physical description', 'connectlibrary' ), $fields['physical_description'], __( 'Imported pagination/format notes such as “xi, 194 p.”.', 'connectlibrary' ) );
		$this->textarea_input( 'provider_notes', __( 'Provider notes', 'connectlibrary' ), $fields['provider_notes'], __( 'Imported provider notes, such as bibliographical-reference notes. This is not a synopsis.', 'connectlibrary' ) );
		printf( '<p class="description">%s</p>', esc_html__( 'ISBN lookup is now at the very top of the edit screen before the title field. Audience is intentionally librarian-controlled; use the Audiences taxonomy box rather than provider maturity data.', 'connectlibrary' ) );
	}

	/** Run an ISBN lookup and store normalized suggestions for explicit review/apply. */
	private function lookup_isbn_metadata( int $post_id, array $fields ): void {
		$lookup_isbn = isset( $_POST['connectlibrary_lookup_isbn'] ) ? Isbn::normalize( wp_unslash( $_POST['connectlibrary_lookup_isbn'] ) ) : '';
		if ( '' === $lookup_isbn ) {
			$lookup_isbn = '' !== $fields['isbn_13'] ? (string) $fields['isbn_13'] : (string) $fields['isbn_10'];
		}

		$result = ( new IsbnMetadataLookupService() )->lookup( $lookup_isbn );
		update_post_meta( $post_id, self::LOOKUP_META, $result );
		$this->audit_isbn_event( 'isbn_lookup', $post_id, $lookup_isbn, $result );
	}

	/** AJAX lookup used by the visible scan box without requiring a post save first. */
	public function ajax_lookup_isbn_metadata(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $this->can_manage_isbn_workflow( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to look up ISBN metadata.', 'connectlibrary' ) ), 403 );
			return;
		}

		$isbn = isset( $_POST['isbn'] ) ? Isbn::normalize( wp_unslash( $_POST['isbn'] ) ) : '';

		if ( ! Isbn::is_valid( $isbn ) ) {
			$this->audit_isbn_event(
				'isbn_lookup',
				$post_id,
				$isbn,
				array(
					'status'  => 'invalid',
					'message' => 'Invalid ISBN rejected before provider lookup.',
				)
			);
			wp_send_json_success(
				array(
					'status'  => 'invalid',
					'isbn'    => $isbn,
					'message' => __( 'The ISBN entered is not valid. Please check the number and try again.', 'connectlibrary' ),
				)
			);
			return;
		}

		$duplicates = ( new IsbnDuplicateDetector() )->detect( $isbn, $post_id );
		if ( ! empty( $duplicates ) ) {
			$count = count( $duplicates );
			$this->audit_isbn_event(
				'isbn_duplicate_warning',
				$post_id,
				$isbn,
				array(
					'status'          => 'duplicate',
					'duplicate_count' => $count,
				)
			);
			wp_send_json_success(
				array(
					'status'     => 'duplicate',
					'isbn'       => $isbn,
					'isbn_type'  => Isbn::type( $isbn ),
					'duplicates' => $duplicates,
					'message'    => sprintf(
						/* translators: %d: number of duplicate books found in the catalog. */
						_n(
							'This ISBN is already in the catalog (%d book found). Review the existing record before adding a new one.',
							'This ISBN is already in the catalog (%d books found). Review the existing records before adding a new one.',
							$count,
							'connectlibrary'
						),
						$count
					),
					'metadata'   => array(),
				)
			);
			return;
		}

		$result = ( new IsbnMetadataLookupService() )->lookup( $isbn );
		if ( $post_id > 0 ) {
			update_post_meta( $post_id, self::LOOKUP_META, $result );
			if ( 'found' === ( $result['status'] ?? '' ) && is_array( $result['metadata'] ?? null ) ) {
				$this->assign_imported_taxonomies( $post_id, $result['metadata'] );
			}
		}
		$this->audit_isbn_event( 'isbn_lookup', $post_id, $isbn, $result );
		wp_send_json_success( $result );
	}

	/** Import the pending ISBN lookup cover as the book featured image. */
	public function ajax_import_isbn_cover(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 || ! $this->can_manage_isbn_workflow( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import a cover for this book.', 'connectlibrary' ) ), 403 );
			return;
		}

		$result = get_post_meta( $post_id, self::LOOKUP_META, true );
		if ( ! is_array( $result ) || 'found' !== ( $result['status'] ?? '' ) || ! is_array( $result['metadata'] ?? null ) ) {
			wp_send_json_error( array( 'message' => __( 'No pending ISBN metadata was found for this book. Run Lookup metadata first.', 'connectlibrary' ) ), 400 );
		}

		$import = ( new CoverImporter() )->import_for_book( $post_id, $result['metadata'], true );
		$this->audit_isbn_event( 'isbn_cover_import', $post_id, (string) ( $result['isbn'] ?? '' ), $import );
		if ( 'imported' !== ( $import['status'] ?? '' ) && 'skipped_existing' !== ( $import['status'] ?? '' ) ) {
			wp_send_json_error(
				array(
					'message' => (string) ( $import['error'] ?? __( 'Cover import failed.', 'connectlibrary' ) ),
					'result'  => $import,
				),
				400
			);
		}

		$attachment_id = absint( $import['attachment_id'] ?? 0 );
		wp_send_json_success(
			array(
				'message'        => __( 'Cover image imported and set as the featured image.', 'connectlibrary' ),
				'import'         => $import,
				'attachment_id'  => $attachment_id,
				'thumbnail_id'   => absint( get_post_thumbnail_id( $post_id ) ),
				'thumbnail_url'  => $attachment_id > 0 ? (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '',
				'attachment_url' => $attachment_id > 0 ? (string) wp_get_attachment_url( $attachment_id ) : '',
			)
		);
	}

	/** Assign imported provider taxonomies to the book. */
	private function assign_imported_taxonomies( int $post_id, array $metadata ): void {
		$categories = isset( $metadata['categories'] ) && is_array( $metadata['categories'] ) ? array_map( 'sanitize_text_field', $metadata['categories'] ) : array();
		$categories = array_values( array_filter( $categories ) );
		if ( ! empty( $categories ) ) {
			wp_set_object_terms( $post_id, $categories, BookTaxonomies::TAXONOMY_CATEGORY, true );
		}

		$subjects = isset( $metadata['subjects'] ) && is_array( $metadata['subjects'] ) ? array_map( 'sanitize_text_field', $metadata['subjects'] ) : array();
		$subjects = array_values( array_filter( $subjects ) );
		if ( ! empty( $subjects ) ) {
			wp_set_object_terms( $post_id, $subjects, BookTaxonomies::TAXONOMY_TAG, true );
		}
	}

	/** Apply selected suggested fields only after the librarian explicitly requests it. */
	private function apply_lookup_selection( int $post_id, array $fields ): array {
		$result = get_post_meta( $post_id, self::LOOKUP_META, true );
		if ( ! is_array( $result ) || 'found' !== ( $result['status'] ?? '' ) ) {
			return $fields;
		}

		$selected = isset( $_POST['connectlibrary_apply_lookup_fields'] ) && is_array( $_POST['connectlibrary_apply_lookup_fields'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['connectlibrary_apply_lookup_fields'] ) ) : array();
		$allowed  = array(
			'title',
			'subtitle',
			'isbn_10',
			'isbn_13',
			'authors',
			'publisher',
			'published_date',
			'description',
			'page_count',
			'language',
			'categories',
			'subjects',
			'enrichment',
			'source',
			'cover',
		);
		$selected = array_values( array_intersect( $selected, $allowed ) );
		$apply    = IsbnMetadata::apply_fields( is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array() );

		if ( in_array( 'title', $selected, true ) && '' !== $apply['title'] ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $apply['title'],
				)
			);
		}
		if ( in_array( 'subtitle', $selected, true ) ) {
			$fields['subtitle'] = $apply['subtitle'];
		}
		if ( in_array( 'isbn_10', $selected, true ) ) {
			$fields['isbn_10'] = $apply['isbn_10'];
		}
		if ( in_array( 'isbn_13', $selected, true ) ) {
			$fields['isbn_13'] = $apply['isbn_13'];
		}
		if ( in_array( 'authors', $selected, true ) ) {
			$fields['new_author_display_name'] = $apply['new_author_display_name'];
		}
		if ( in_array( 'publisher', $selected, true ) ) {
			$fields['publisher'] = $apply['publisher'];
		}
		if ( in_array( 'published_date', $selected, true ) ) {
			$fields['published_date'] = $apply['published_date'];
		}
		if ( in_array( 'description', $selected, true ) ) {
			$fields['content_notes'] = $apply['content_notes'];
		}
		if ( in_array( 'page_count', $selected, true ) ) {
			$fields['page_count'] = $apply['page_count'];
		}
		if ( in_array( 'language', $selected, true ) ) {
			$fields['language'] = $apply['language'];
		}
		if ( ( in_array( 'categories', $selected, true ) || in_array( 'subjects', $selected, true ) ) && is_array( $result['metadata'] ?? null ) ) {
			$this->assign_imported_taxonomies( $post_id, $result['metadata'] );
		}
		if ( in_array( 'enrichment', $selected, true ) ) {
			$fields['catalog_identifiers']     = $apply['catalog_identifiers'];
			$fields['library_classifications'] = $apply['library_classifications'];
			$fields['physical_description']    = $apply['physical_description'];
			$fields['provider_notes']          = $apply['provider_notes'];
		}
		if ( in_array( 'source', $selected, true ) ) {
			$fields['metadata_source']       = $apply['metadata_source'];
			$fields['source_provider']       = $apply['source_provider'];
			$fields['source_record_id']      = $apply['source_record_id'];
			$fields['source_record_link']    = $apply['source_record_link'];
			$fields['last_metadata_refresh'] = $apply['last_metadata_refresh'];
		}
		if ( in_array( 'cover', $selected, true ) && is_array( $result['metadata'] ?? null ) && ! empty( $result['metadata']['cover_url_candidates'] ) ) {
			$replace = isset( $_POST['connectlibrary_replace_cover'] );
			$import  = ( new CoverImporter() )->import_for_book( $post_id, $result['metadata'], $replace );
			$this->audit_isbn_event( 'isbn_cover_import', $post_id, (string) ( $result['isbn'] ?? '' ), $import );
		}

		if ( ! empty( $selected ) ) {
			$this->audit_isbn_event(
				'isbn_apply_corrections',
				$post_id,
				(string) ( $result['isbn'] ?? '' ),
				array(
					'status'          => 'applied',
					'selected_fields' => $selected,
					'provider'        => is_array( $result['metadata'] ?? null ) ? (string) ( $result['metadata']['source_provider'] ?? '' ) : '',
				)
			);
		}

		return $fields;
	}

	/** Determine whether the current user may run librarian ISBN workflows. */
	private function can_manage_isbn_workflow( int $post_id = 0 ): bool {
		if ( ! Capabilities::can_manage_circulation() ) {
			return false;
		}

		return $post_id <= 0 || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Write a privacy-safe audit event for ISBN lookup workflow activity.
	 *
	 * @param string              $action  Audit action key.
	 * @param int                 $post_id Target book post ID, when available.
	 * @param string              $isbn    Normalized ISBN involved in the workflow.
	 * @param array<string,mixed> $result  Lookup/import/apply result summary.
	 */
	private function audit_isbn_event( string $action, int $post_id, string $isbn, array $result ): void {
		$status   = (string) ( $result['status'] ?? '' );
		$metadata = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
		$provider = (string) ( $result['provider'] ?? ( $metadata['source_provider'] ?? '' ) );

		$audit_status = 'ok';
		if ( in_array( $status, array( 'invalid', 'provider_error', 'failed', 'error' ), true ) ) {
			$audit_status = 'failed';
		} elseif ( in_array( $status, array( 'duplicate', 'skipped_existing', 'skipped_no_cover' ), true ) ) {
			$audit_status = 'skipped';
		}

		$context = array(
			'isbn'            => Isbn::normalize( $isbn ),
			'isbn_type'       => Isbn::type( $isbn ),
			'lookup_status'   => $status,
			'provider'        => $provider,
			'duplicate_count' => (int) ( $result['duplicate_count'] ?? 0 ),
			'selected_fields' => is_array( $result['selected_fields'] ?? null ) ? array_values( array_map( 'sanitize_key', $result['selected_fields'] ) ) : array(),
			'attachment_id'   => absint( $result['attachment_id'] ?? 0 ),
		);

		$params = array(
			'source_channel' => 'admin',
			'entity_type'    => self::AUDIT_ENTITY,
			'entity_id'      => $post_id,
			'context'        => $context,
			'status'         => $audit_status,
			'summary'        => $this->audit_isbn_summary( $action, $status, $provider ),
			'correlation_id' => 'isbn-' . md5( $post_id . '|' . Isbn::normalize( $isbn ) ),
		);

		if ( 'failed' === $audit_status ) {
			$params['error_message'] = (string) ( $result['error'] ?? ( $result['message'] ?? '' ) );
		}

		try {
			( new AuditEventService() )->log( $action, $params );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/** Build a short privacy-safe audit summary. */
	private function audit_isbn_summary( string $action, string $status, string $provider ): string {
		if ( 'isbn_duplicate_warning' === $action ) {
			return 'ISBN lookup showed an existing catalog record warning.';
		}
		if ( 'isbn_cover_import' === $action ) {
			return 'ISBN cover import finished with status ' . $status . '.';
		}
		if ( 'isbn_apply_corrections' === $action ) {
			return 'Librarian applied selected ISBN metadata fields.';
		}

		return '' !== $provider
			? 'ISBN metadata lookup finished with status ' . $status . ' from ' . $provider . '.'
			: 'ISBN metadata lookup finished with status ' . $status . '.';
	}

	/** Render lookup controls and pending provider suggestions. */
	private function render_isbn_lookup_controls( WP_Post $post, array $fields ): void {
		$result = get_post_meta( $post->ID, self::LOOKUP_META, true );
		$isbn   = '' !== $fields['isbn_13'] ? $fields['isbn_13'] : $fields['isbn_10'];
		if ( '' === $isbn && isset( $_GET['connectlibrary_prefill_isbn'] ) ) {
			$isbn = Isbn::normalize( ScannerInput::sanitize_text( wp_unslash( $_GET['connectlibrary_prefill_isbn'] ) ) );
		}
		?>
		<hr />
		<p><strong><?php echo esc_html__( 'ISBN metadata lookup', 'connectlibrary' ); ?></strong></p>
		<p>
			<label for="connectlibrary_lookup_isbn"><?php echo esc_html__( 'Lookup ISBN', 'connectlibrary' ); ?></label><br />
			<input type="text" id="connectlibrary_lookup_isbn" name="connectlibrary_lookup_isbn" value="<?php echo esc_attr( (string) $isbn ); ?>" class="regular-text" autofocus aria-describedby="connectlibrary-isbn-hint" />
			<button type="button" class="button" id="connectlibrary_ajax_isbn_lookup" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"><?php echo esc_html__( 'Lookup metadata', 'connectlibrary' ); ?></button>
			<br /><span id="connectlibrary-isbn-hint" class="description"><?php echo esc_html__( 'Scan a barcode or type an ISBN-10 or ISBN-13. Scanners ending with Enter or Tab are supported.', 'connectlibrary' ); ?></span>
		</p>
		<p class="description"><?php echo esc_html__( 'Google Books is checked first, with Open Library fallback. Results are suggestions only until you apply selected fields. If a provider is rate-limited, timed out, or unavailable, enter the book manually and try ISBN lookup again later.', 'connectlibrary' ); ?></p>
		<div id="connectlibrary_ajax_isbn_result" aria-live="polite"></div>
		<?php $this->render_isbn_lookup_script(); ?>
		<?php
		if ( ! is_array( $result ) ) {
			return;
		}

		$type = 'found' === ( $result['status'] ?? '' ) ? 'notice-success' : 'notice-warning';
		echo '<div class="notice ' . esc_attr( $type ) . ' inline"><p>' . esc_html( (string) ( $result['message'] ?? '' ) ) . '</p>';
		foreach ( is_array( $result['errors'] ?? null ) ? $result['errors'] : array() as $error ) {
			echo '<p>' . esc_html( (string) $error ) . '</p>';
		}
		echo '</div>';
		if ( 'found' !== ( $result['status'] ?? '' ) || ! is_array( $result['metadata'] ?? null ) ) {
			return;
		}

		$this->render_lookup_suggestions( $result['metadata'], $post->ID );
	}

	/** Render the admin-side AJAX lookup script. */
	private function render_isbn_lookup_script(): void {
		?>
		<script>
		(function () {
			const button = document.getElementById('connectlibrary_ajax_isbn_lookup');
			const input = document.getElementById('connectlibrary_lookup_isbn');
			const resultBox = document.getElementById('connectlibrary_ajax_isbn_result');
			if (!button || !input || !resultBox || button.dataset.bound === '1') return;
			button.dataset.bound = '1';
			const esc = (value) => String(value || '').replace(/[&<>'"]/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch]));
			const setField = (name, value) => {
				const field = document.querySelector('[name="connectlibrary_book_metadata[' + name + ']"]');
				if (field && value !== undefined && value !== null) field.value = value;
			};
			const setMainContent = (value) => {
				if (!value) return;
				if (window.tinyMCE && window.tinyMCE.get && window.tinyMCE.get('content') && !window.tinyMCE.get('content').isHidden()) {
					window.tinyMCE.get('content').setContent(esc(value).replace(/\n\n+/g, '</p><p>').replace(/^/, '<p>').replace(/$/, '</p>'));
				}
				const content = document.getElementById('content');
				if (content) content.value = value;
			};
			const updateFeaturedImageBox = (data) => {
				const box = document.getElementById('postimagediv');
				const thumbUrl = data && (data.thumbnail_url || data.attachment_url);
				if (!box || !thumbUrl) return;
				const inside = box.querySelector('.inside');
				if (!inside) return;
				inside.innerHTML = '<p class="hide-if-no-js"><img src="' + esc(thumbUrl) + '" alt="" style="max-width:100%;height:auto;" /></p><p class="hide-if-no-js"><strong><?php echo esc_js( __( 'Book cover image set.', 'connectlibrary' ) ); ?></strong></p>';
			};
			const importCover = async () => {
				const postId = button.dataset.postId || '';
				if (!postId) {
					resultBox.insertAdjacentHTML('beforeend', '<div class="notice notice-warning inline"><p>' + esc('<?php echo esc_js( __( 'Save the draft before importing the cover image.', 'connectlibrary' ) ); ?>') + '</p></div>');
					return;
				}
				const coverButton = document.getElementById('connectlibrary_import_ajax_cover');
				if (coverButton) coverButton.disabled = true;
				try {
					const body = new URLSearchParams({ action: 'connectlibrary_import_isbn_cover', nonce: button.dataset.nonce, post_id: postId });
					const response = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body });
					const json = await response.json();
					if (!json.success) throw new Error((json.data && json.data.message) || 'Cover import failed.');
					updateFeaturedImageBox(json.data || {});
					resultBox.insertAdjacentHTML('beforeend', '<div class="notice notice-success inline"><p>' + esc(json.data.message || 'Cover image imported.') + '</p></div>');
				} catch (error) {
					resultBox.insertAdjacentHTML('beforeend', '<div class="notice notice-error inline"><p>' + esc(error.message || 'Cover import failed.') + '</p></div>');
				} finally {
					if (coverButton) coverButton.disabled = false;
				}
			};
			const applyMetadata = (metadata, isbn) => {
				if (metadata.title) {
					const title = document.getElementById('title');
					if (title) title.value = metadata.title;
				}
				setField('isbn_10', metadata.isbn_10 || '');
				setField('isbn_13', metadata.isbn_13 || isbn || input.value);
				setField('subtitle', metadata.subtitle || '');
				setField('publisher', metadata.publisher || '');
				setField('published_date', metadata.published_date || '');
				setField('language', metadata.language || '');
				setField('page_count', metadata.page_count || '');
				setField('catalog_identifiers', metadata.catalog_identifiers || '');
				setField('library_classifications', metadata.classifications || '');
				setField('physical_description', metadata.physical_description || '');
				setField('provider_notes', metadata.provider_notes || '');
				setField('content_notes', metadata.description || '');
				setMainContent(metadata.description || '');
				setField('new_author_display_name', Array.isArray(metadata.authors) ? metadata.authors.join(', ') : '');
				setField('metadata_source', 'imported');
				setField('source_provider', metadata.source_provider || '');
				setField('source_record_id', metadata.source_record_id || '');
				setField('source_record_link', metadata.source_record_link || '');
				setField('last_metadata_refresh', new Date().toISOString());
				resultBox.insertAdjacentHTML('afterbegin', '<div class="notice notice-success inline"><p>' + esc('<?php echo esc_js( __( 'Suggested metadata has been copied into the form. Review it, then Publish or Save Draft.', 'connectlibrary' ) ); ?>') + '</p></div>');
			};
			let lookupInFlight = false;
			let lastLookupIsbn = '';
			const normalizeKey = (v) => String(v).replace(/[\s\-]/g, '');
			const doLookup = async () => {
				const isbn = input.value.trim();
				const isbnKey = normalizeKey(isbn);
				if (lookupInFlight && isbnKey === lastLookupIsbn) return;
				lookupInFlight = true;
				lastLookupIsbn = isbnKey;
				button.disabled = true;
				resultBox.innerHTML = '<p><?php echo esc_js( __( 'Looking up ISBN metadata…', 'connectlibrary' ) ); ?></p>';
				try {
					const body = new URLSearchParams({ action: 'connectlibrary_isbn_lookup', nonce: button.dataset.nonce, isbn, post_id: button.dataset.postId || '' });
					const response = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body });
					const json = await response.json();
					if (!json.success) throw new Error((json.data && json.data.message) || 'Lookup failed.');
					const data = json.data || {};
					if (data.status === 'duplicate') {
						const dupes = Array.isArray(data.duplicates) ? data.duplicates : [];
						let html = '<div class="notice notice-warning inline"><p><strong>' + esc(data.message || 'Duplicate found.') + '</strong></p>';
						if (dupes.length) {
							html += '<table class="widefat striped"><thead><tr><th><?php echo esc_js( __( 'Title', 'connectlibrary' ) ); ?></th><th><?php echo esc_js( __( 'Authors', 'connectlibrary' ) ); ?></th><th><?php echo esc_js( __( 'ISBNs', 'connectlibrary' ) ); ?></th><th><?php echo esc_js( __( 'Status / Visibility', 'connectlibrary' ) ); ?></th><th></th></tr></thead><tbody>';
							for (const d of dupes) {
								const authors = Array.isArray(d.authors) ? d.authors.join(', ') : '';
								const isbns = [d.isbn_13, d.isbn_10].filter(Boolean).join(' / ');
								const statusParts = [d.item_status, d.visibility].filter(Boolean);
								const statusText = statusParts.length ? statusParts.join(' / ') : '—';
								html += '<tr><td>' + esc(d.title || '') + '</td><td>' + esc(authors) + '</td><td>' + esc(isbns) + '</td><td>' + esc(statusText) + '</td><td><a href="' + esc(d.edit_link || '') + '" class="button button-small" target="_blank"><?php echo esc_js( __( 'Edit existing book', 'connectlibrary' ) ); ?></a></td></tr>';
							}
							html += '</tbody></table>';
						}
						html += '</div>';
						resultBox.innerHTML = html;
						return;
					}
					if (data.status !== 'found' || !data.metadata) {
						const errors = Array.isArray(data.errors) ? '<ul><li>' + data.errors.map(esc).join('</li><li>') + '</li></ul>' : '';
						resultBox.innerHTML = '<div class="notice notice-warning inline"><p>' + esc(data.message || 'No metadata found.') + '</p>' + errors + '</div>';
						return;
					}
					const m = data.metadata;
					const rows = [['Title', m.title], ['Subtitle', m.subtitle], ['ISBN-10', m.isbn_10], ['ISBN-13', m.isbn_13], ['Authors', Array.isArray(m.authors) ? m.authors.join(', ') : ''], ['Publisher', m.publisher], ['Published', m.published_date], ['Pages', m.page_count], ['Language', m.language], ['Google category', Array.isArray(m.categories) ? m.categories.join(', ') : ''], ['Open Library subjects/tags', Array.isArray(m.subjects) ? m.subjects.join(', ') : ''], ['Library identifiers', m.catalog_identifiers], ['Classifications', m.classifications], ['Physical description', m.physical_description], ['Provider notes', m.provider_notes], ['Source', m.source_provider]];
					const covers = Array.isArray(m.cover_url_candidates) ? m.cover_url_candidates.filter(Boolean) : [];
					const coverHtml = covers.length ? '<p><img src="' + esc(covers[0]) + '" alt="" style="max-width:120px;height:auto;border:1px solid #ccd0d4;" /></p><p><button type="button" class="button" id="connectlibrary_import_ajax_cover"><?php echo esc_js( __( 'Import cover image', 'connectlibrary' ) ); ?></button></p>' : '<p><em><?php echo esc_js( __( 'No cover image was returned by the metadata provider.', 'connectlibrary' ) ); ?></em></p>';
					resultBox.innerHTML = '<div class="notice notice-success inline"><p>' + esc(data.message || 'Metadata found.') + '</p></div>' + coverHtml + '<table class="widefat striped"><tbody>' + rows.filter((row) => row[1]).map((row) => '<tr><th>' + esc(row[0]) + '</th><td>' + esc(row[1]) + '</td></tr>').join('') + '</tbody></table><p><button type="button" class="button button-primary" id="connectlibrary_apply_ajax_metadata"><?php echo esc_js( __( 'Copy suggestions into form', 'connectlibrary' ) ); ?></button></p>';
					document.getElementById('connectlibrary_apply_ajax_metadata').addEventListener('click', () => applyMetadata(m, isbn));
					const coverButton = document.getElementById('connectlibrary_import_ajax_cover');
					if (coverButton) coverButton.addEventListener('click', importCover);
				} catch (error) {
					resultBox.innerHTML = '<div class="notice notice-error inline"><p>' + esc(error.message || 'Lookup failed.') + '</p></div>';
				} finally {
					lookupInFlight = false;
					button.disabled = false;
				}
			};
			const looksLikeIsbn = (v) => String(v).replace(/\D/g, '').length >= 10;
			input.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					event.stopPropagation();
					doLookup();
				} else if (event.key === 'Tab' && looksLikeIsbn(input.value)) {
					event.preventDefault();
					event.stopPropagation();
					doLookup();
				}
			});
			button.addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();
				doLookup();
			});
		}());
		</script>
		<?php
	}

	/** Render normalized suggestions with explicit apply checkboxes. */
	private function render_lookup_suggestions( array $metadata, int $post_id = 0 ): void {
		$rows = array(
			'title'          => array( __( 'Title', 'connectlibrary' ), $metadata['title'] ?? '' ),
			'subtitle'       => array( __( 'Subtitle', 'connectlibrary' ), $metadata['subtitle'] ?? '' ),
			'isbn_10'        => array( __( 'ISBN-10', 'connectlibrary' ), $metadata['isbn_10'] ?? '' ),
			'isbn_13'        => array( __( 'ISBN-13', 'connectlibrary' ), $metadata['isbn_13'] ?? '' ),
			'authors'        => array( __( 'Authors', 'connectlibrary' ), implode( ', ', (array) ( $metadata['authors'] ?? array() ) ) ),
			'publisher'      => array( __( 'Publisher', 'connectlibrary' ), $metadata['publisher'] ?? '' ),
			'published_date' => array( __( 'Publication date/year', 'connectlibrary' ), $metadata['published_date'] ?? '' ),
			'description'    => array( __( 'Description', 'connectlibrary' ), $metadata['description'] ?? '' ),
			'page_count'     => array( __( 'Page count', 'connectlibrary' ), $metadata['page_count'] ?? '' ),
			'language'       => array( __( 'Language', 'connectlibrary' ), $metadata['language'] ?? '' ),
			'categories'     => array( __( 'Google categories', 'connectlibrary' ), implode( ', ', (array) ( $metadata['categories'] ?? array() ) ) ),
			'subjects'       => array( __( 'Open Library subjects/tags', 'connectlibrary' ), implode( ', ', (array) ( $metadata['subjects'] ?? array() ) ) ),
			'enrichment'     => array( __( 'Library identifiers/classifications/notes', 'connectlibrary' ), trim( implode( "\n", array_filter( array( $metadata['catalog_identifiers'] ?? '', $metadata['classifications'] ?? '', $metadata['physical_description'] ?? '', $metadata['provider_notes'] ?? '' ) ) ) ) ),
			'source'         => array( __( 'Source attribution', 'connectlibrary' ), trim( (string) ( $metadata['source_provider'] ?? '' ) . ' ' . (string) ( $metadata['source_record_id'] ?? '' ) ) ),
		);
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Field', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Suggested value', 'connectlibrary' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $key => $row ) : ?>
					<?php if ( '' === (string) $row[1] ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<tr>
						<td><label><input type="checkbox" name="connectlibrary_apply_lookup_fields[]" value="<?php echo esc_attr( $key ); ?>" /> <?php echo esc_html( $row[0] ); ?></label></td>
						<td><?php echo esc_html( (string) $row[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! empty( $metadata['cover_url_candidates'] ) ) : ?>
					<tr>
						<td style="vertical-align:top;">
							<label><input type="checkbox" name="connectlibrary_apply_lookup_fields[]" value="cover" /> <?php echo esc_html__( 'Cover image', 'connectlibrary' ); ?></label>
							<?php if ( $post_id > 0 && get_post_thumbnail_id( $post_id ) ) : ?>
								<br /><label><input type="checkbox" name="connectlibrary_replace_cover" value="1" /> <?php echo esc_html__( 'Replace existing cover', 'connectlibrary' ); ?></label>
							<?php endif; ?>
						</td>
						<td>
							<?php
							/* translators: %d: number of available cover image candidates. */
							echo esc_html( sprintf( __( '%d cover image candidate(s) available', 'connectlibrary' ), count( (array) $metadata['cover_url_candidates'] ) ) );
							?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<p><button type="submit" class="button button-primary" name="connectlibrary_apply_isbn_lookup" value="1"><?php echo esc_html__( 'Apply selected lookup fields', 'connectlibrary' ); ?></button></p>
		<?php
	}

	/** Load metadata with relationship fields. */
	private function load_fields( int $post_id ): array {
		$fields                    = $this->metadata->get( $post_id );
		$fields['author_ids']      = $this->relationships->get_author_ids( $post_id );
		$series                    = $this->relationships->get_series_selection( $post_id );
		$fields['series_id']       = $series['series_id'];
		$fields['series_position'] = $series['series_position'];

		return $fields;
	}

	/** Render text input. */
	private function text_input( string $key, string $label, mixed $value, string $help = '' ): void {
		printf( '<p><label><strong>%1$s</strong><br /><input type="text" name="%2$s[%3$s]" value="%4$s" class="widefat" /></label>', esc_html( $label ), esc_attr( self::FIELD_NAME ), esc_attr( $key ), esc_attr( (string) $value ) );
		$this->help( $help );
		echo '</p>';
	}

	/** Render number input. */
	private function number_input( string $key, string $label, int $value ): void {
		printf( '<p><label><strong>%1$s</strong><br /><input type="number" min="0" name="%2$s[%3$s]" value="%4$d" class="small-text" /></label></p>', esc_html( $label ), esc_attr( self::FIELD_NAME ), esc_attr( $key ), $value );
	}

	/** Render textarea. */
	private function textarea_input( string $key, string $label, mixed $value, string $help = '' ): void {
		printf( '<p><label><strong>%1$s</strong><br /><textarea name="%2$s[%3$s]" rows="4" class="widefat">%4$s</textarea></label>', esc_html( $label ), esc_attr( self::FIELD_NAME ), esc_attr( $key ), esc_textarea( (string) $value ) );
		$this->help( $help );
		echo '</p>';
	}

	/**
	 * Render select input.
	 *
	 * Accepts either a flat array of values (display label auto-generated from value)
	 * or an associative array of value => translatable label pairs.
	 */
	private function select_input( string $key, string $label, mixed $value, array $choices ): void {
		printf( '<p><label><strong>%1$s</strong><br /><select name="%2$s[%3$s]" class="widefat">', esc_html( $label ), esc_attr( self::FIELD_NAME ), esc_attr( $key ) );
		foreach ( $choices as $choice_key => $choice_val ) {
			if ( is_int( $choice_key ) ) {
				$opt_value = (string) $choice_val;
				$opt_label = ucfirst( str_replace( '_', ' ', $opt_value ) );
			} else {
				$opt_value = (string) $choice_key;
				$opt_label = (string) $choice_val;
			}
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $opt_value ), selected( $value, $opt_value, false ), esc_html( $opt_label ) );
		}
		echo '</select></label></p>';
	}

	/** Render checkbox input. */
	private function checkbox_input( string $key, string $label, bool $checked ): void {
		printf( '<p><label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label></p>', esc_attr( self::FIELD_NAME ), esc_attr( $key ), checked( $checked, true, false ), esc_html( $label ) );
	}

	/** Render help text. */
	private function help( string $help ): void {
		if ( '' !== $help ) {
			echo '<br /><span class="description">' . esc_html( $help ) . '</span>';
		}
	}
}
