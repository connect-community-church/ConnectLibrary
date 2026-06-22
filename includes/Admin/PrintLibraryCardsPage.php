<?php
/**
 * Librarian-only Print Library Cards admin page.
 *
 * @package ConnectLibrary
 */

namespace ConnectLibrary\Admin;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,Generic.Commenting.DocComment.MissingShort

use ConnectLibrary\Borrowers\BorrowerCardService;
use ConnectLibrary\Borrowers\BorrowerRepository;
use ConnectLibrary\Catalog\BookPostType;
use ConnectLibrary\Cards\BorrowerCardRenderer;
use ConnectLibrary\Support\Capabilities;
use ConnectLibrary\Support\ScannerInput;
use WP_Error;

/**
 * Dedicated "Print Library Cards" screen: single card, multi-borrower sheet,
 * and guardian/family grouped sheet.  All layouts use opaque card tokens only.
 */
final class PrintLibraryCardsPage {
	private const PAGE_SLUG         = 'connectlibrary-print-cards';
	private const PRINT_ACTION_NAME = 'connectlibrary_print_cards';
	private const NONCE_ACTION      = 'connectlibrary_print_cards';

	/** @var BorrowerCardService */
	private BorrowerCardService $card_service;

	/** @var BorrowerRepository */
	private BorrowerRepository $borrower_repo;

	/** @var BorrowerCardRenderer */
	private BorrowerCardRenderer $renderer;

	/**
	 * When true, render_print_preview skips exit (for unit tests).
	 *
	 * @var bool
	 */
	private bool $suppress_exit = false;

	public function __construct(
		?BorrowerCardService $card_service = null,
		?BorrowerRepository $borrower_repo = null,
		?BorrowerCardRenderer $renderer = null
	) {
		$this->card_service  = $card_service ?? new BorrowerCardService();
		$this->borrower_repo = $borrower_repo ?? new BorrowerRepository();
		$this->renderer      = $renderer ?? new BorrowerCardRenderer();
	}

	/** Disable exit after print output — call from tests only. */
	public function suppress_exit(): static {
		$this->suppress_exit = true;
		return $this;
	}

	/** Register admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_' . self::PRINT_ACTION_NAME, array( $this, 'handle_print' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/** Add submenu under Library (book post type). */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . BookPostType::POST_TYPE,
			esc_html__( 'Print Library Cards', 'connectlibrary' ),
			esc_html__( 'Print Cards', 'connectlibrary' ),
			Capabilities::MANAGE_BORROWERS,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/** Enqueue print CSS only on this page. */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'connectlibrary-print-cards',
			plugin_dir_url( CONNECTLIBRARY_PLUGIN_FILE ) . 'assets/css/admin-print-cards.css',
			array(),
			CONNECTLIBRARY_VERSION
		);
	}

	/** Render the print selection UI. */
	public function render(): void {
		if ( ! Capabilities::can_manage_borrowers() ) {
			wp_die( esc_html__( 'You do not have permission to print library cards.', 'connectlibrary' ) );
			return;
		}

		$search = ScannerInput::sanitize_text( wp_unslash( $_GET['s'] ?? '' ) );
		$active = $this->borrower_repo->search(
			array(
				'search' => $search,
				'status' => 'active',
			)
		);
		?>
		<div class="wrap connectlibrary-print-cards-admin">
			<h1><?php echo esc_html__( 'Print Library Cards', 'connectlibrary' ); ?></h1>
			<p><?php echo esc_html__( 'Cards include an opaque QR code and barcode only — no contact details, guardian details, notes, or borrowing history are printed.', 'connectlibrary' ); ?></p>

			<?php $this->render_layout_controls(); ?>

			<?php $this->render_search_filter( $search, count( $active ) ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="connectlibrary-print-cards-form">
				<?php wp_nonce_field( self::NONCE_ACTION, '_wpnonce' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PRINT_ACTION_NAME ); ?>" />
				<input type="hidden" name="layout" id="cl-layout-value" value="sheet" />
				<input type="hidden" name="cut_guides" id="cl-cut-guides-value" value="1" />
				<input type="hidden" name="card_size" id="cl-card-size-value" value="standard" />
				<input type="hidden" name="orientation" id="cl-orientation-value" value="portrait" />

				<h2><?php echo esc_html__( 'Select borrowers', 'connectlibrary' ); ?></h2>

				<?php $this->render_borrower_selection_table( $active ); ?>

				<p class="submit">
					<button type="button" class="button" id="cl-select-all-btn"><?php echo esc_html__( 'Select all', 'connectlibrary' ); ?></button>
					<button type="button" class="button" id="cl-select-none-btn"><?php echo esc_html__( 'Clear selection', 'connectlibrary' ); ?></button>
					<button type="submit" name="print_layout" value="sheet" class="button button-primary">
						<?php echo esc_html__( 'Print selected as sheet', 'connectlibrary' ); ?>
					</button>
					<button type="submit" name="print_layout" value="family" class="button button-secondary">
						<?php echo esc_html__( 'Print selected as family groups', 'connectlibrary' ); ?>
					</button>
					<button type="submit" name="print_layout" value="demo" class="button button-secondary">
						<?php echo esc_html__( 'Demo alignment preview', 'connectlibrary' ); ?>
					</button>
				</p>
			</form>

			<script>
			(function() {
				var form    = document.getElementById('connectlibrary-print-cards-form');
				var selAll  = document.getElementById('cl-select-all-btn');
				var selNone = document.getElementById('cl-select-none-btn');
				if (!form || !selAll || !selNone) { return; }

				selAll.addEventListener('click', function() {
					form.querySelectorAll('input[name="borrower_ids[]"]').forEach(function(cb) { cb.checked = true; });
				});
				selNone.addEventListener('click', function() {
					form.querySelectorAll('input[name="borrower_ids[]"]').forEach(function(cb) { cb.checked = false; });
				});

				var layoutEl   = document.getElementById('cl-layout-value');
				var cutEl      = document.getElementById('cl-cut-guides-value');
				var sizeEl     = document.getElementById('cl-card-size-value');
				var orientEl   = document.getElementById('cl-orientation-value');
				var layoutSel  = document.getElementById('cl-layout-select');
				var cutChk     = document.getElementById('cl-cut-guides-check');
				var sizeSel    = document.getElementById('cl-card-size-select');
				var orientSel  = document.getElementById('cl-orientation-select');

				if (layoutSel)  { layoutSel.addEventListener('change', function() { layoutEl.value  = this.value; }); }
				if (cutChk)     { cutChk.addEventListener('change',    function() { cutEl.value     = this.checked ? '1' : '0'; }); }
				if (sizeSel)    { sizeSel.addEventListener('change',   function() { sizeEl.value    = this.value; }); }
				if (orientSel)  { orientSel.addEventListener('change', function() { orientEl.value  = this.value; }); }
			}());
			</script>
		</div>
		<?php
	}

	/** Handle nonce-checked print POST. */
	public function handle_print(): void {
		if ( ! Capabilities::can_manage_borrowers() ) {
			wp_die( esc_html__( 'You do not have permission to print library cards.', 'connectlibrary' ) );
			return;
		}
		if ( false === check_admin_referer( self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Print cards security check failed.', 'connectlibrary' ) );
			return;
		}

		$raw_ids      = isset( $_POST['borrower_ids'] ) && is_array( $_POST['borrower_ids'] ) ? $_POST['borrower_ids'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$borrower_ids = array_map( 'absint', $raw_ids );
		$borrower_ids = array_filter( $borrower_ids );
		$print_layout = sanitize_key( wp_unslash( $_POST['print_layout'] ?? 'sheet' ) );
		$card_size    = sanitize_key( wp_unslash( $_POST['card_size'] ?? 'standard' ) );
		$orientation  = sanitize_key( wp_unslash( $_POST['orientation'] ?? 'portrait' ) );
		$cut_guides   = '1' === (string) ( $_POST['cut_guides'] ?? '1' );

		if ( 'demo' === $print_layout ) {
			$this->render_print_preview( $this->build_demo_print_items(), null, array(), $card_size, $orientation, $cut_guides, true );
			return;
		}

		if ( array() === $borrower_ids ) {
			wp_die( esc_html__( 'No borrowers selected. Please select at least one borrower and try again.', 'connectlibrary' ) );
			return;
		}

		$items     = $this->build_print_items( $borrower_ids );
		$skipped   = $items['skipped'];
		$printable = $items['printable'];

		if ( array() === $printable ) {
			wp_die( esc_html__( 'None of the selected borrowers have active library cards. Generate cards from the Borrowers screen first.', 'connectlibrary' ) );
			return;
		}

		$grouped = 'family' === $print_layout ? $this->group_by_family( $printable ) : null;
		$this->render_print_preview( $printable, $grouped, $skipped, $card_size, $orientation, $cut_guides );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/** Render layout controls (size, orientation, cut guides). */
	private function render_layout_controls(): void {
		?>
		<div class="connectlibrary-print-controls" style="background:#f0f0f1;padding:12px 16px;border-radius:4px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:16px;align-items:center;">
			<div>
				<label for="cl-card-size-select"><strong><?php echo esc_html__( 'Card size:', 'connectlibrary' ); ?></strong></label>
				<select id="cl-card-size-select" name="card_size_ui">
					<option value="standard"><?php echo esc_html__( 'Standard (3.5 × 2 in)', 'connectlibrary' ); ?></option>
					<option value="large"><?php echo esc_html__( 'Large (4 × 2.5 in)', 'connectlibrary' ); ?></option>
					<option value="compact"><?php echo esc_html__( 'Compact (3 × 1.75 in)', 'connectlibrary' ); ?></option>
				</select>
			</div>
			<div>
				<label for="cl-orientation-select"><strong><?php echo esc_html__( 'Orientation:', 'connectlibrary' ); ?></strong></label>
				<select id="cl-orientation-select" name="orientation_ui">
					<option value="portrait"><?php echo esc_html__( 'Portrait', 'connectlibrary' ); ?></option>
					<option value="landscape"><?php echo esc_html__( 'Landscape', 'connectlibrary' ); ?></option>
				</select>
			</div>
			<div>
				<label>
					<input type="checkbox" id="cl-cut-guides-check" checked />
					<?php echo esc_html__( 'Show cut guides', 'connectlibrary' ); ?>
				</label>
			</div>
		</div>
		<?php
	}

	/** Render server-side borrower search/filter controls for sheet selection. */
	private function render_search_filter( string $search, int $result_count ): void {
		?>
		<form method="get" class="connectlibrary-print-search" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( BookPostType::POST_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<label for="cl-borrower-search"><strong><?php echo esc_html__( 'Search borrowers:', 'connectlibrary' ); ?></strong></label>
			<input
				type="search"
				id="cl-borrower-search"
				name="s"
				value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php echo esc_attr__( 'Name, preferred name, or email', 'connectlibrary' ); ?>"
			/>
			<button type="submit" class="button"><?php echo esc_html__( 'Filter borrowers', 'connectlibrary' ); ?></button>
			<?php if ( '' !== $search ) : ?>
				<a class="button" href="<?php echo esc_url( $this->page_url() ); ?>"><?php echo esc_html__( 'Clear filter', 'connectlibrary' ); ?></a>
			<?php endif; ?>
			<span class="description">
				<?php
				/* translators: %d: number of active borrowers shown */
				echo esc_html( sprintf( _n( '%d active borrower shown for sheet selection.', '%d active borrowers shown for sheet selection.', $result_count, 'connectlibrary' ), $result_count ) );
				?>
			</span>
		</form>
		<?php
	}

	/** Render the borrower selection table with card status. */
	private function render_borrower_selection_table( array $borrowers ): void {
		if ( array() === $borrowers ) {
			echo '<p>' . esc_html__( 'No active borrowers found.', 'connectlibrary' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="cl-check-all-header" aria-label="<?php echo esc_attr__( 'Select all borrowers', 'connectlibrary' ); ?>" /></td>
					<th scope="col"><?php echo esc_html__( 'Borrower', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Type', 'connectlibrary' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Card status', 'connectlibrary' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $borrowers as $borrower ) : ?>
				<?php
				$bid      = (int) ( $borrower['id'] ?? 0 );
				$active   = $this->card_service->cards_for_borrower( $bid );
				$active   = array_values( array_filter( $active, static fn( array $c ): bool => BorrowerCardService::STATUS_ACTIVE === (string) ( $c['status'] ?? '' ) ) );
				$has_card = array() !== $active;
				?>
				<tr>
					<td class="check-column">
						<input
							type="checkbox"
							name="borrower_ids[]"
							value="<?php echo esc_attr( (string) $bid ); ?>"
							id="cl-borrower-<?php echo esc_attr( (string) $bid ); ?>"
							<?php
							if ( ! $has_card ) :
								?>
								disabled title="<?php echo esc_attr__( 'No active card — generate one from the Borrowers screen.', 'connectlibrary' ); ?>"<?php endif; ?>
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s: borrower name */ __( 'Select %s', 'connectlibrary' ), (string) ( $borrower['display_name'] ?? '' ) ) ); ?>"
						/>
					</td>
					<td><label for="cl-borrower-<?php echo esc_attr( (string) $bid ); ?>"><?php echo esc_html( (string) ( $borrower['display_name'] ?? '' ) ); ?></label></td>
					<td><?php echo esc_html( $this->type_label( (string) ( $borrower['borrower_type'] ?? '' ) ) ); ?></td>
					<td>
						<?php if ( $has_card ) : ?>
							<span style="color:#00a32a;">&#10003; <?php echo esc_html( (string) ( $active[0]['card_label'] ?? '' ) ); ?></span>
						<?php else : ?>
							<span style="color:#d63638;"><?php echo esc_html__( 'No active card', 'connectlibrary' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<script>
		(function() {
			var hdr = document.getElementById('cl-check-all-header');
			if (!hdr) { return; }
			hdr.addEventListener('change', function() {
				document.querySelectorAll('input[name="borrower_ids[]"]:not([disabled])').forEach(function(cb) { cb.checked = hdr.checked; });
			});
		}());
		</script>
		<?php
	}

	/**
	 * Build printable items and skipped list from borrower IDs.
	 *
	 * @param array<int,int> $borrower_ids Borrower IDs to look up.
	 * @return array{printable:array<int,array{borrower:array<string,mixed>,card:array<string,mixed>}>,skipped:array<int,array{borrower_id:int,reason:string}>}
	 */
	private function build_print_items( array $borrower_ids ): array {
		$printable = array();
		$skipped   = array();

		foreach ( $borrower_ids as $bid ) {
			$borrower = $this->borrower_repo->get( $bid );
			if ( null === $borrower ) {
				$skipped[] = array(
					'borrower_id' => $bid,
					'reason'      => __( 'Borrower not found.', 'connectlibrary' ),
				);
				continue;
			}
			if ( 'active' !== (string) ( $borrower['status'] ?? '' ) ) {
				$skipped[] = array(
					'borrower_id' => $bid,
					'reason'      => __( 'Borrower is inactive.', 'connectlibrary' ),
				);
				continue;
			}
			$card = $this->card_service->active_card( $bid );
			if ( is_wp_error( $card ) ) {
				$skipped[] = array(
					'borrower_id' => $bid,
					'reason'      => $card->get_error_message(),
				);
				continue;
			}
			$printable[] = array(
				'borrower' => $borrower,
				'card'     => $card,
			);
		}

		return array(
			'printable' => $printable,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Build synthetic cards for alignment demos. No real borrower/contact data is used.
	 *
	 * @return array<int,array{borrower:array<string,mixed>,card:array<string,mixed>}>
	 */
	private function build_demo_print_items(): array {
		$items = array();
		for ( $i = 1; $i <= 8; ++$i ) {
			$items[] = array(
				'borrower' => array(
					'id'            => 9000 + $i,
					'display_name'  => sprintf(
						/* translators: %d: demo card number */
						__( 'Demo Placeholder %d', 'connectlibrary' ),
						$i
					),
					'borrower_type' => 'manual',
					'status'        => 'active',
				),
				'card'     => array(
					'id'         => 9000 + $i,
					'payload'    => sprintf( 'CLCARD-DEMO-%03d', $i ),
					'card_label' => sprintf(
						/* translators: %d: demo card number */
						__( 'DEMO-%03d', 'connectlibrary' ),
						$i
					),
					'status'     => BorrowerCardService::STATUS_ACTIVE,
				),
			);
		}

		return $items;
	}

	/**
	 * Group printable items by family: guardian first, children beneath.
	 *
	 * @param array<int,array{borrower:array<string,mixed>,card:array<string,mixed>}> $items Printable items to group.
	 * @return array<int,array{guardian:array<string,mixed>|null,members:array<int,array{borrower:array<string,mixed>,card:array<string,mixed>}>}>
	 */
	private function group_by_family( array $items ): array {
		$by_id    = array();
		$children = array();

		foreach ( $items as $item ) {
			$bid           = (int) ( $item['borrower']['id'] ?? 0 );
			$by_id[ $bid ] = $item;
			if ( (int) ( $item['borrower']['guardian_borrower_id'] ?? 0 ) > 0 ) {
				$children[] = $bid;
			}
		}

		$groups = array();
		foreach ( $items as $item ) {
			$bid         = (int) ( $item['borrower']['id'] ?? 0 );
			$guardian_id = (int) ( $item['borrower']['guardian_borrower_id'] ?? 0 );
			if ( $guardian_id > 0 ) {
				continue;
			}
			$group_children = array();
			foreach ( $children as $cbid ) {
				$child_item = $by_id[ $cbid ] ?? null;
				if ( null === $child_item ) {
					continue;
				}
				if ( (int) ( $child_item['borrower']['guardian_borrower_id'] ?? 0 ) === $bid ) {
					$group_children[] = $child_item;
				}
			}
			$groups[] = array(
				'guardian' => $item,
				'members'  => $group_children,
			);
		}

		$unparented = array();
		foreach ( $children as $cbid ) {
			$child_item = $by_id[ $cbid ] ?? null;
			if ( null === $child_item ) {
				continue;
			}
			$gid = (int) ( $child_item['borrower']['guardian_borrower_id'] ?? 0 );
			if ( ! isset( $by_id[ $gid ] ) ) {
				$unparented[] = $child_item;
			}
		}
		if ( array() !== $unparented ) {
			$groups[] = array(
				'guardian' => null,
				'members'  => $unparented,
			);
		}

		return $groups;
	}

	/**
	 * Output the full standalone print preview page.
	 *
	 * @param array<int,array{borrower:array<string,mixed>,card:array<string,mixed>}>                                                                  $printable   Items ready to print.
	 * @param array<int,array{guardian:array<string,mixed>|null,members:array<int,array{borrower:array<string,mixed>,card:array<string,mixed>}>}>|null $grouped     Family groups, or null for sheet layout.
	 * @param array<int,array{borrower_id:int,reason:string}>                                                                                          $skipped     Items skipped with reasons.
	 * @param string                                                                                                                                   $card_size   Card size preset key.
	 * @param string                                                                                                                                   $orientation Page orientation.
	 * @param bool                                                                                                                                     $cut_guides  Whether to show cut guides.
	 * @param bool                                                                                                                                     $is_demo     Whether this is a fake-data alignment preview.
	 */
	private function render_print_preview( array $printable, ?array $grouped, array $skipped, string $card_size, string $orientation, bool $cut_guides, bool $is_demo = false ): void {
		$size_css = $this->card_size_css( $card_size );
		?>
		<!doctype html>
		<html lang="en">
		<head>
			<meta charset="utf-8" />
			<title><?php echo esc_html__( 'Print Library Cards — ConnectLibrary', 'connectlibrary' ); ?></title>
			<style>
				body{font-family:sans-serif;margin:0;padding:16px;background:#fff}
				.cl-print-controls{margin-bottom:16px;display:flex;gap:12px;align-items:center}
				.cl-print-controls button{padding:6px 14px;cursor:pointer}
				.cl-demo-notice{border:1px solid #2271b1;background:#f0f6fc;padding:10px 14px;border-radius:4px;margin-bottom:16px}
				.cl-skipped-notice{border:1px solid #d63638;background:#fcf0f1;padding:10px 14px;border-radius:4px;margin-bottom:16px}
				.cl-skipped-notice h3{margin:0 0 6px;color:#d63638}
				.cl-skipped-notice ul{margin:0;padding-left:1.4em}
				.cl-card-sheet{display:flex;flex-wrap:wrap;gap:<?php echo esc_attr( $cut_guides ? '0' : '16px' ); ?>}
				.cl-family-group{width:100%;page-break-inside:avoid;break-inside:avoid;border-top:2px solid #ccc;padding-top:12px;margin-bottom:12px}
				.cl-family-label{font-size:13px;font-weight:600;color:#555;margin-bottom:6px}
				.cl-family-members{display:flex;flex-wrap:wrap;gap:<?php echo esc_attr( $cut_guides ? '0' : '16px' ); ?>}
				.connectlibrary-card-print{
					<?php echo esc_attr( $size_css ); ?>
					border:1px solid #333;
					<?php echo $cut_guides ? 'border-style:dashed;' : 'border-radius:6px;'; ?>
					padding:10px;
					break-inside:avoid;
					page-break-inside:avoid;
					box-sizing:border-box;
					font-family:sans-serif;
					background:#fff;
				}
				.connectlibrary-card-print h1{font-size:11px;margin:0 0 4px;font-weight:600;letter-spacing:.02em}
				.borrower-name{font-size:16px;font-weight:700;margin:2px 0}
				.child-context{font-size:11px;color:#444;margin:1px 0}
				.card-label{font-size:10px;color:#666;margin:2px 0}
				.codes{display:flex;flex-direction:<?php echo 'landscape' === $orientation ? 'row' : 'column'; ?>;gap:4px;align-items:flex-start;margin-top:6px}
				.codes svg{max-width:100%}
				.privacy-note{font-size:9px;color:#888;margin-top:4px}
				<?php if ( $cut_guides ) : ?>
				.cut-guide-h{width:100%;border:none;border-top:1px dashed #aaa;margin:0}
				.cut-guide-v{height:100%;border:none;border-left:1px dashed #aaa;margin:0}
				<?php endif; ?>
				@media print{
					body{margin:0;padding:0}
					.cl-print-controls{display:none!important}
					.cl-demo-notice{display:none!important}
					.cl-skipped-notice{display:none!important}
					.cl-family-group{border-top:none;padding-top:0}
					.cl-family-label{display:none}
					@page{
						size: <?php echo 'landscape' === $orientation ? 'landscape' : 'portrait'; ?>;
						margin:10mm;
					}
				}
			</style>
		</head>
		<body>
			<div class="cl-print-controls" role="toolbar" aria-label="<?php echo esc_attr__( 'Print controls', 'connectlibrary' ); ?>">
				<button type="button" onclick="window.print()" aria-label="<?php echo esc_attr__( 'Print cards', 'connectlibrary' ); ?>">
					<?php echo esc_html__( 'Print', 'connectlibrary' ); ?>
				</button>
				<button type="button" onclick="window.close()" aria-label="<?php echo esc_attr__( 'Close preview', 'connectlibrary' ); ?>">
					<?php echo esc_html__( 'Close', 'connectlibrary' ); ?>
				</button>
				<span style="font-size:13px;color:#555">
					<?php
					/* translators: %d: number of cards */
					echo esc_html( sprintf( _n( '%d card ready to print', '%d cards ready to print', count( $printable ), 'connectlibrary' ), count( $printable ) ) );
					?>
				</span>
			</div>

			<?php if ( $is_demo ) : ?>
				<div class="cl-demo-notice" role="status">
					<strong><?php echo esc_html__( 'Demo alignment preview', 'connectlibrary' ); ?></strong>
					<?php echo esc_html__( 'These cards use fake placeholder names and synthetic demo codes only. No real borrower or contact data is included.', 'connectlibrary' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( array() !== $skipped ) : ?>
			<div class="cl-skipped-notice" role="alert">
				<h3><?php echo esc_html__( 'Some borrowers were skipped', 'connectlibrary' ); ?></h3>
				<ul>
					<?php foreach ( $skipped as $skip ) : ?>
						<li><?php echo esc_html( sprintf( /* translators: %s: skip reason. */ __( 'Borrower record: %s', 'connectlibrary' ), $skip['reason'] ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<?php if ( null !== $grouped ) : ?>
				<?php foreach ( $grouped as $group ) : ?>
					<div class="cl-family-group">
						<?php if ( null !== $group['guardian'] ) : ?>
							<div class="cl-family-label" aria-hidden="true">
								<?php echo esc_html( sprintf( /* translators: %s: guardian name */ __( 'Family: %s', 'connectlibrary' ), (string) ( $group['guardian']['borrower']['display_name'] ?? '' ) ) ); ?>
							</div>
						<?php endif; ?>
						<div class="cl-family-members">
							<?php if ( null !== $group['guardian'] ) : ?>
								<?php echo $this->render_single_card_html( $group['guardian']['borrower'], $group['guardian']['card'], null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all borrower fields ?>
							<?php endif; ?>
							<?php foreach ( $group['members'] as $member ) : ?>
								<?php
								$guardian_name = null !== $group['guardian'] ? (string) ( $group['guardian']['borrower']['display_name'] ?? '' ) : null;
								echo $this->render_single_card_html( $member['borrower'], $member['card'], $guardian_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all borrower fields
								?>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="cl-card-sheet">
					<?php foreach ( $printable as $item ) : ?>
						<?php echo $this->render_single_card_html( $item['borrower'], $item['card'], null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all borrower fields ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</body>
		</html>
		<?php
		if ( ! $this->suppress_exit ) {
			exit;
		}
	}

	/**
	 * Render a single card HTML fragment.  For child cards, shows minimal
	 * guardian label (name only, no contact data).
	 *
	 * @param array<string,mixed> $borrower      Borrower row.
	 * @param array<string,mixed> $card          Active card row.
	 * @param string|null         $guardian_name Guardian display name for child cards, or null.
	 */
	private function render_single_card_html( array $borrower, array $card, ?string $guardian_name ): string {
		$payload = (string) ( $card['payload'] ?? '' );
		$name    = (string) ( $borrower['display_name'] ?? '' );
		$label   = (string) ( $card['card_label'] ?? '' );
		$btype   = (string) ( $borrower['borrower_type'] ?? '' );

		if ( '' === $payload ) {
			return '<section class="connectlibrary-card-print"><p style="color:#d63638">' . esc_html__( 'Error: card payload missing. Cannot print.', 'connectlibrary' ) . '</p></section>';
		}

		$qr_result = $this->try_render_qr( $payload );
		$bc_result = $this->try_render_barcode( $payload );

		if ( is_string( $qr_result ) && '' === $qr_result ) {
			return '<section class="connectlibrary-card-print"><p style="color:#d63638">' . esc_html__( 'Error: QR code generation failed. Cannot print this card.', 'connectlibrary' ) . '</p></section>';
		}

		$child_context = '';
		if ( 'child' === $btype && null !== $guardian_name && '' !== $guardian_name ) {
			$child_context = '<p class="child-context">'
				. esc_html(
					sprintf(
						/* translators: %s: guardian display name */
						__( 'Guardian: %s', 'connectlibrary' ),
						$guardian_name
					)
				)
				. '</p>';
		}

		return '<section class="connectlibrary-card-print">'
			. '<h1>' . esc_html__( 'Connect Community Church Library', 'connectlibrary' ) . '</h1>'
			. '<p class="borrower-name">' . esc_html( $name ) . '</p>'
			. $child_context
			. '<p class="card-label">' . esc_html( $label ) . '</p>'
			. '<div class="codes">' . $qr_result . $bc_result . '</div>'
			. '<p class="privacy-note">' . esc_html__( 'Opaque library token only. No personal data.', 'connectlibrary' ) . '</p>'
			. '</section>';
	}

	/** Attempt QR render; return empty string on error (caller must check). */
	private function try_render_qr( string $payload ): string {
		try {
			return $this->renderer->qr_svg( $payload );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** Attempt barcode render; return error-notice HTML on failure. */
	private function try_render_barcode( string $payload ): string {
		try {
			return $this->renderer->barcode_svg( $payload );
		} catch ( \Throwable $e ) {
			return '<span style="color:#d63638;font-size:10px">' . esc_html__( 'Barcode error', 'connectlibrary' ) . '</span>';
		}
	}

	/** CSS dimensions string for the selected card size preset. */
	private function card_size_css( string $size ): string {
		return match ( $size ) {
			'large'   => 'width:384px;min-height:240px;',
			'compact' => 'width:288px;min-height:168px;',
			default   => 'width:336px;min-height:192px;',
		};
	}

	/** Human-readable borrower type label. */
	private function type_label( string $type ): string {
		return match ( $type ) {
			'child'   => __( 'Child/youth', 'connectlibrary' ),
			'wp_user' => __( 'WordPress-linked', 'connectlibrary' ),
			'guest'   => __( 'Guest', 'connectlibrary' ),
			default   => __( 'Adult/manual', 'connectlibrary' ),
		};
	}

	/** Page URL for redirects. */
	private function page_url(): string {
		return admin_url( 'edit.php?post_type=' . BookPostType::POST_TYPE . '&page=' . self::PAGE_SLUG );
	}
}
