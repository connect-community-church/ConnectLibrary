<?php
/**
 * ConnectLibrary admin settings page.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

use ConnectLibrary\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders Settings > ConnectLibrary.
 */
final class SettingsPage {
	private const PAGE_SLUG    = 'connectlibrary-settings';
	private const CAPABILITY   = 'manage_options';
	private const OPTION_GROUP = 'connectlibrary_settings_group';

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( CONNECTLIBRARY_PLUGIN_FILE ),
			array( $this, 'add_plugin_action_link' )
		);
	}

	/**
	 * Add the settings page under the WordPress Settings menu.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'ConnectLibrary Settings', 'connectlibrary' ),
			__( 'ConnectLibrary', 'connectlibrary' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Add a Settings shortcut on the Plugins screen.
	 *
	 * @param array<int|string,string> $links Existing plugin action links.
	 * @return array<int|string,string>
	 */
	public function add_plugin_action_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'connectlibrary' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Register the settings option, sections, and fields.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);

		$this->add_sections();
		$this->add_fields();
	}

	/**
	 * Render the settings page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage ConnectLibrary settings.', 'connectlibrary' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'ConnectLibrary Settings', 'connectlibrary' ); ?></h1>
			<p><?php echo esc_html__( 'Configure the Phase 1 library catalog defaults used by administrators, catalog pages, and later setup workflows.', 'connectlibrary' ); ?></p>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Add grouped settings sections.
	 */
	private function add_sections(): void {
		$sections = array(
			'general'  => array(
				'title'       => __( 'General / contact', 'connectlibrary' ),
				'description' => __( 'Basic library identity and instructions shown or reused by catalog-facing features.', 'connectlibrary' ),
			),
			'catalog'  => array(
				'title'       => __( 'Catalog display', 'connectlibrary' ),
				'description' => __( 'Default public catalog page and presentation choices. This does not create circulation behavior.', 'connectlibrary' ),
			),
			'metadata' => array(
				'title'       => __( 'Metadata lookup', 'connectlibrary' ),
				'description' => __( 'Provider preferences for future ISBN/book metadata lookup. Providers may not always return the requested language.', 'connectlibrary' ),
			),
			'lending'  => array(
				'title'       => __( 'Lending defaults', 'connectlibrary' ),
				'description' => __( 'Stored defaults for later reservation and circulation cards. Saving these values does not enable checkout, holds, reminders, or email sending.', 'connectlibrary' ),
			),
			'advanced' => array(
				'title'       => __( 'Advanced / privacy-safe defaults', 'connectlibrary' ),
				'description' => __( 'Safety defaults for future maintenance work. ConnectLibrary preserves data by default and this build does not perform destructive uninstall actions.', 'connectlibrary' ),
			),
		);

		foreach ( $sections as $id => $section ) {
			add_settings_section(
				'connectlibrary_' . $id,
				$section['title'],
				array( $this, 'render_section_description' ),
				self::PAGE_SLUG,
				array( 'description' => $section['description'] )
			);
		}
	}

	/**
	 * Add settings fields.
	 */
	private function add_fields(): void {
		$this->add_text_field(
			'general',
			'library_name',
			__( 'Library name / display label', 'connectlibrary' ),
			__( 'Shown as the public label for this church library.', 'connectlibrary' )
		);
		$this->add_text_field(
			'general',
			'librarian_email',
			__( 'Librarian notification email', 'connectlibrary' ),
			__( 'Used by later workflows as the default librarian contact. Must be a valid email address.', 'connectlibrary' ),
			'email'
		);
		$this->add_textarea_field(
			'general',
			'pickup_instructions',
			__( 'Library hours / pickup instructions', 'connectlibrary' ),
			__( 'Short plain-language instructions for pickup hours or location.', 'connectlibrary' )
		);
		$this->add_page_field(
			'catalog',
			'catalog_page_id',
			__( 'Catalog page', 'connectlibrary' ),
			__( 'Optional WordPress page that will host or link to the public library catalog.', 'connectlibrary' )
		);
		$this->add_select_field(
			'catalog',
			'catalog_layout',
			__( 'Default catalog layout', 'connectlibrary' ),
			Settings::catalog_layout_choices(),
			__( 'Default layout for future catalog views.', 'connectlibrary' )
		);
		$this->add_select_field(
			'catalog',
			'default_availability_status',
			__( 'Default public availability wording', 'connectlibrary' ),
			Settings::availability_status_choices(),
			__( 'Default status wording aligned with Phase 1 availability labels only; this does not implement circulation.', 'connectlibrary' )
		);
		$this->add_select_field(
			'metadata',
			'metadata_provider_order',
			__( 'Metadata provider order', 'connectlibrary' ),
			Settings::metadata_provider_order_choices(),
			__( 'Preferred lookup order for future ISBN metadata imports.', 'connectlibrary' )
		);
		$this->add_checkbox_field(
			'metadata',
			'import_covers_locally',
			__( 'Import covers locally', 'connectlibrary' ),
			__( 'Store imported cover images in the WordPress Media Library when metadata import is implemented.', 'connectlibrary' )
		);
		$this->add_text_field(
			'metadata',
			'metadata_language',
			__( 'Preferred metadata language/locale', 'connectlibrary' ),
			__( 'Short preference such as en. Providers may return different languages.', 'connectlibrary' )
		);
		$this->add_number_field(
			'lending',
			'default_loan_period_days',
			__( 'Default loan period days', 'connectlibrary' ),
			1,
			365,
			__( 'Stored for later checkout workflows; default is 14 days.', 'connectlibrary' )
		);
		$this->add_number_field(
			'lending',
			'default_hold_period_days',
			__( 'Default hold period days', 'connectlibrary' ),
			1,
			365,
			__( 'Stored for later hold/reservation workflows; default is 14 days.', 'connectlibrary' )
		);
		$this->add_number_field(
			'lending',
			'due_reminder_lead_days',
			__( 'Due reminder lead days', 'connectlibrary' ),
			0,
			60,
			__( 'Stored for later reminder workflows; this build does not send email.', 'connectlibrary' )
		);
		$this->add_checkbox_field(
			'advanced',
			'preserve_data_on_uninstall',
			__( 'Preserve data on uninstall', 'connectlibrary' ),
			__( 'Keep this enabled. Destructive data removal is intentionally not implemented in this build.', 'connectlibrary' )
		);
	}

	/**
	 * Render a section description.
	 *
	 * @param array<string,string> $args Section callback arguments.
	 */
	public function render_section_description( array $args ): void {
		if ( empty( $args['description'] ) ) {
			return;
		}

		printf( '<p>%s</p>', esc_html( $args['description'] ) );
	}

	/**
	 * Render a text-like input.
	 *
	 * @param array<string,mixed> $args Field arguments.
	 */
	public function render_text_field( array $args ): void {
		$settings = Settings::all();
		$key      = (string) $args['key'];
		$type     = (string) $args['type'];

		printf(
			'<input type="%1$s" id="connectlibrary_%2$s" name="%3$s[%2$s]" value="%4$s" class="regular-text" />',
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( (string) $settings[ $key ] )
		);
		$this->render_description( $args );
	}

	/**
	 * Render a textarea input.
	 *
	 * @param array<string,mixed> $args Field arguments.
	 */
	public function render_textarea_field( array $args ): void {
		$settings = Settings::all();
		$key      = (string) $args['key'];

		printf(
			'<textarea id="connectlibrary_%1$s" name="%2$s[%1$s]" rows="4" class="large-text">%3$s</textarea>',
			esc_attr( $key ),
			esc_attr( Settings::OPTION_NAME ),
			esc_textarea( (string) $settings[ $key ] )
		);
		$this->render_description( $args );
	}

	/**
	 * Render a WordPress page selector.
	 *
	 * @param array<string,mixed> $args Field arguments.
	 */
	public function render_page_field( array $args ): void {
		$settings = Settings::all();
		$key      = (string) $args['key'];

		wp_dropdown_pages(
			array(
				'name'              => Settings::OPTION_NAME . '[' . $key . ']',
				'id'                => 'connectlibrary_' . $key,
				'selected'          => (int) $settings[ $key ],
				'show_option_none'  => __( '— Select —', 'connectlibrary' ),
				'option_none_value' => '0',
			)
		);
		$this->render_description( $args );
	}

	/**
	 * Render an allowlisted select field.
	 *
	 * @param array<string,mixed> $args Field arguments.
	 */
	public function render_select_field( array $args ): void {
		$settings = Settings::all();
		$key      = (string) $args['key'];
		$choices  = is_array( $args['choices'] ) ? $args['choices'] : array();

		printf(
			'<select id="connectlibrary_%1$s" name="%2$s[%1$s]">',
			esc_attr( $key ),
			esc_attr( Settings::OPTION_NAME )
		);

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( $settings[ $key ], $value, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>';
		$this->render_description( $args );
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array<string,mixed> $args Field arguments.
	 */
	public function render_checkbox_field( array $args ): void {
		$settings = Settings::all();
		$key      = (string) $args['key'];

		printf(
			'<label><input type="checkbox" id="connectlibrary_%1$s" name="%2$s[%1$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( $key ),
			esc_attr( Settings::OPTION_NAME ),
			checked( 1, (int) $settings[ $key ], false ),
			esc_html( (string) $args['description'] )
		);
	}

	/**
	 * Render a bounded number input.
	 *
	 * @param array<string,mixed> $args Field arguments.
	 */
	public function render_number_field( array $args ): void {
		$settings = Settings::all();
		$key      = (string) $args['key'];

		printf(
			'<input type="number" id="connectlibrary_%1$s" name="%2$s[%1$s]" value="%3$d" min="%4$d" max="%5$d" step="1" class="small-text" />',
			esc_attr( $key ),
			esc_attr( Settings::OPTION_NAME ),
			(int) $settings[ $key ],
			(int) $args['min'],
			(int) $args['max']
		);
		$this->render_description( $args );
	}

	/**
	 * Add a text-like field.
	 *
	 * @param string $section Section suffix.
	 * @param string $key Setting key.
	 * @param string $label Field label.
	 * @param string $description Help text.
	 * @param string $type Input type.
	 */
	private function add_text_field( string $section, string $key, string $label, string $description, string $type = 'text' ): void {
		$this->add_field( $section, $key, $label, 'render_text_field', compact( 'key', 'description', 'type' ) );
	}

	/**
	 * Add a textarea field.
	 *
	 * @param string $section Section suffix.
	 * @param string $key Setting key.
	 * @param string $label Field label.
	 * @param string $description Help text.
	 */
	private function add_textarea_field( string $section, string $key, string $label, string $description ): void {
		$this->add_field( $section, $key, $label, 'render_textarea_field', compact( 'key', 'description' ) );
	}

	/**
	 * Add a page selector field.
	 *
	 * @param string $section Section suffix.
	 * @param string $key Setting key.
	 * @param string $label Field label.
	 * @param string $description Help text.
	 */
	private function add_page_field( string $section, string $key, string $label, string $description ): void {
		$this->add_field( $section, $key, $label, 'render_page_field', compact( 'key', 'description' ) );
	}

	/**
	 * Add a select field.
	 *
	 * @param string               $section Section suffix.
	 * @param string               $key Setting key.
	 * @param string               $label Field label.
	 * @param array<string,string> $choices Allowlisted choices.
	 * @param string               $description Help text.
	 */
	private function add_select_field( string $section, string $key, string $label, array $choices, string $description ): void {
		$this->add_field( $section, $key, $label, 'render_select_field', compact( 'key', 'choices', 'description' ) );
	}

	/**
	 * Add a checkbox field.
	 *
	 * @param string $section Section suffix.
	 * @param string $key Setting key.
	 * @param string $label Field label.
	 * @param string $description Help text.
	 */
	private function add_checkbox_field( string $section, string $key, string $label, string $description ): void {
		$this->add_field( $section, $key, $label, 'render_checkbox_field', compact( 'key', 'description' ) );
	}

	/**
	 * Add a number field.
	 *
	 * @param string $section Section suffix.
	 * @param string $key Setting key.
	 * @param string $label Field label.
	 * @param int    $min Minimum value.
	 * @param int    $max Maximum value.
	 * @param string $description Help text.
	 */
	private function add_number_field( string $section, string $key, string $label, int $min, int $max, string $description ): void {
		$this->add_field( $section, $key, $label, 'render_number_field', compact( 'key', 'min', 'max', 'description' ) );
	}

	/**
	 * Add a Settings API field.
	 *
	 * @param string              $section Section suffix.
	 * @param string              $key Setting key.
	 * @param string              $label Field label.
	 * @param string              $callback Callback method.
	 * @param array<string,mixed> $args Field callback args.
	 */
	private function add_field( string $section, string $key, string $label, string $callback, array $args ): void {
		add_settings_field(
			'connectlibrary_' . $key,
			$label,
			array( $this, $callback ),
			self::PAGE_SLUG,
			'connectlibrary_' . $section,
			$args
		);
	}

	/**
	 * Render paragraph help text for standard fields.
	 *
	 * @param array<string,mixed> $args Field callback args.
	 */
	private function render_description( array $args ): void {
		if ( empty( $args['description'] ) ) {
			return;
		}

		printf( '<p class="description">%s</p>', esc_html( (string) $args['description'] ) );
	}
}
