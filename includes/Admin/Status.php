<?php
/**
 * Minimal admin status screen.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Tools submenu proving the plugin loaded successfully.
 */
final class Status {
	private const MENU_SLUG = 'connectlibrary-status';

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/**
	 * Add the status page under Tools.
	 */
	public function add_menu_page(): void {
		add_management_page(
			esc_html__( 'ConnectLibrary Status', 'connectlibrary' ),
			esc_html__( 'ConnectLibrary', 'connectlibrary' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the status page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'connectlibrary' ) );
		}

		$stored_version = get_option( 'connectlibrary_version', '' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'ConnectLibrary Status', 'connectlibrary' ); ?></h1>
			<p><?php echo esc_html__( 'ConnectLibrary loaded successfully.', 'connectlibrary' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Setting', 'connectlibrary' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Value', 'connectlibrary' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Plugin version', 'connectlibrary' ); ?></th>
						<td><?php echo esc_html( CONNECTLIBRARY_VERSION ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Stored version option', 'connectlibrary' ); ?></th>
						<td><?php echo esc_html( $stored_version ? (string) $stored_version : __( 'Not set yet', 'connectlibrary' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current phase', 'connectlibrary' ); ?></th>
						<td><?php echo esc_html__( 'Phase 1 foundation only; not ready for live catalog or circulation use.', 'connectlibrary' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
