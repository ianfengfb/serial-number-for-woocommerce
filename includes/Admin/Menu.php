<?php
namespace SerialNumberForWooCommerce\Admin;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Ajax;
use SerialNumberForWooCommerce\Admin\SerialNumbers\FormController;
use SerialNumberForWooCommerce\Admin\SerialNumbers\ListTable;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Pro\BulkGenerate\Controller as BulkGenerateController;
use SerialNumberForWooCommerce\Pro\StockSync\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: registers the "Serial Numbers" submenu under WooCommerce and
 * routes between the list view and the Add New form.
 */
final class Menu {

	private string $hook_suffix = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		new Ajax();
	}

	public function register_menu(): void {
		$hook_suffix = add_submenu_page(
			'woocommerce',
			__( 'Serial Numbers', 'serial-number-for-woocommerce' ),
			__( 'Serial Numbers', 'serial-number-for-woocommerce' ),
			'manage_woocommerce',
			'serial-number-for-woocommerce',
			array( $this, 'render_page' )
		);

		$this->hook_suffix = (string) $hook_suffix;
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'snw-select2',
			SNW_PLUGIN_URL . 'assets/vendor/select2/select2.min.css',
			array(),
			SNW_VERSION
		);

		wp_enqueue_script(
			'snw-select2',
			SNW_PLUGIN_URL . 'assets/vendor/select2/select2.full.min.js',
			array( 'jquery' ),
			SNW_VERSION,
			true
		);

		wp_enqueue_script(
			'snw-admin',
			SNW_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'snw-select2' ),
			SNW_VERSION,
			true
		);

		wp_localize_script(
			'snw-admin',
			'SNWAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'snw_admin' ),
			)
		);

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'bulk-generate' === $action && Licensing::is_pro_active() ) {
			wp_enqueue_script(
				'snw-bulk-generate',
				SNW_PLUGIN_URL . 'assets/pro/js/bulk-generate.js',
				array( 'jquery', 'snw-admin' ),
				SNW_VERSION,
				true
			);
		}
	}

	public function render_page(): void {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'add' === $action ) {
			$form = new FormController();
			$form->handle();
			$form->render();
			return;
		}

		if ( 'edit' === $action ) {
			$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			$form = new FormController( $id );
			$form->handle();
			$form->render();
			return;
		}

		if ( 'bulk-generate' === $action ) {
			if ( ! Licensing::is_pro_active() ) {
				$this->render_bulk_generate_teaser();
				return;
			}

			$controller = new BulkGenerateController();
			$controller->handle();
			$controller->render();
			return;
		}

		if ( 'delete' === $action ) {
			$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			$this->handle_delete( $id );
			return;
		}

		$this->render_list();
	}

	/**
	 * Soft-deletes a serial number (sets its status to Deleted) and resyncs
	 * stock for its product, since a deleted row no longer counts toward
	 * Repository::count_available().
	 */
	private function handle_delete( int $id ): void {
		check_admin_referer( 'snw_delete_serial_number_' . $id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) );
		}

		$serial = Repository::find( $id );

		if ( $serial ) {
			Repository::mark_deleted( $id );

			if ( Licensing::is_pro_active() && $serial->product_id ) {
				StockSync::sync( (int) $serial->product_id );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'serial-number-for-woocommerce',
					'snw_notice' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Soft-deletes every selected serial number (see handle_delete()) and
	 * resyncs stock once per distinct product among them.
	 */
	private function handle_bulk_delete(): void {
		check_admin_referer( 'bulk-serial_numbers' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) );
		}

		$ids = isset( $_REQUEST['serial_ids'] ) && is_array( $_REQUEST['serial_ids'] )
			? array_filter( array_map( 'absint', $_REQUEST['serial_ids'] ) )
			: array();

		$product_ids = array();

		foreach ( $ids as $id ) {
			$serial = Repository::find( $id );

			if ( ! $serial ) {
				continue;
			}

			Repository::mark_deleted( $id );

			if ( $serial->product_id ) {
				$product_ids[ (int) $serial->product_id ] = true;
			}
		}

		if ( Licensing::is_pro_active() ) {
			foreach ( array_keys( $product_ids ) as $product_id ) {
				StockSync::sync( $product_id );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'serial-number-for-woocommerce',
					'snw_notice' => 'bulk_deleted',
					'snw_count'  => count( $ids ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function render_bulk_generate_teaser(): void {
		$back_url = add_query_arg( array( 'page' => 'serial-number-for-woocommerce' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Bulk Generate Serial Numbers', 'serial-number-for-woocommerce' ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'serial-number-for-woocommerce' ); ?></a>

			<div class="notice notice-info" style="margin-top: 20px;">
				<p><?php esc_html_e( 'Bulk Generate is a Pro feature. Upgrade to generate multiple serial numbers at once, across one or more products.', 'serial-number-for-woocommerce' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_list(): void {
		$list_table = new ListTable();

		if ( 'bulk_delete' === $list_table->current_action() ) {
			$this->handle_bulk_delete();
			return;
		}

		$list_table->prepare_items();

		$add_new_url = add_query_arg(
			array(
				'page'   => 'serial-number-for-woocommerce',
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);

		$bulk_generate_url = add_query_arg(
			array(
				'page'   => 'serial-number-for-woocommerce',
				'action' => 'bulk-generate',
			),
			admin_url( 'admin.php' )
		);

		$filter_product_id = isset( $_GET['snw_filter_product_id'] ) ? absint( $_GET['snw_filter_product_id'] ) : 0;
		$filter_no_product = isset( $_GET['snw_filter_no_product'] );
		$filter_search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$export_args = array( 'action' => 'snw_export_serials' );

		if ( '' !== $filter_search ) {
			$export_args['s'] = $filter_search;
		}

		if ( $filter_no_product ) {
			$export_args['snw_filter_no_product'] = 1;
		} elseif ( $filter_product_id ) {
			$export_args['snw_filter_product_id'] = $filter_product_id;
		}

		$export_url = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'snw_export_serials' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Serial Numbers', 'serial-number-for-woocommerce' ); ?></h1>
			<a href="<?php echo esc_url( $add_new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'serial-number-for-woocommerce' ); ?></a>
			<?php if ( Licensing::is_pro_active() ) : ?>
				<a href="<?php echo esc_url( $bulk_generate_url ); ?>" class="page-title-action"><?php esc_html_e( 'Bulk Generate', 'serial-number-for-woocommerce' ); ?></a>
				<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'serial-number-for-woocommerce' ); ?></a>
			<?php else : ?>
				<a
					href="<?php echo esc_url( $bulk_generate_url ); ?>"
					class="page-title-action"
					style="opacity: 0.5;"
					title="<?php esc_attr_e( 'Upgrade to Pro to unlock Bulk Generate', 'serial-number-for-woocommerce' ); ?>"
				>
					<?php esc_html_e( 'Bulk Generate', 'serial-number-for-woocommerce' ); ?>
					<span style="background: #7f54b3; color: #fff; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 3px; margin-left: 4px; vertical-align: middle;">
						<?php esc_html_e( 'PRO', 'serial-number-for-woocommerce' ); ?>
					</span>
				</a>
				<span
					class="page-title-action"
					style="opacity: 0.5;"
					title="<?php esc_attr_e( 'Upgrade to Pro to unlock Export CSV', 'serial-number-for-woocommerce' ); ?>"
				>
					<?php esc_html_e( 'Export CSV', 'serial-number-for-woocommerce' ); ?>
					<span style="background: #7f54b3; color: #fff; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 3px; margin-left: 4px; vertical-align: middle;">
						<?php esc_html_e( 'PRO', 'serial-number-for-woocommerce' ); ?>
					</span>
				</span>
			<?php endif; ?>

			<?php if ( isset( $_GET['snw_notice'] ) && 'added' === $_GET['snw_notice'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Serial number added.', 'serial-number-for-woocommerce' ); ?></p>
				</div>
			<?php elseif ( isset( $_GET['snw_notice'] ) && 'updated' === $_GET['snw_notice'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Serial number updated.', 'serial-number-for-woocommerce' ); ?></p>
				</div>
			<?php elseif ( isset( $_GET['snw_notice'] ) && 'bulk_generated' === $_GET['snw_notice'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of serial numbers generated */
							esc_html__( '%d serial numbers generated.', 'serial-number-for-woocommerce' ),
							isset( $_GET['snw_count'] ) ? absint( $_GET['snw_count'] ) : 0
						);
						?>
					</p>
				</div>
			<?php elseif ( isset( $_GET['snw_notice'] ) && 'deleted' === $_GET['snw_notice'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Serial number deleted.', 'serial-number-for-woocommerce' ); ?></p>
				</div>
			<?php elseif ( isset( $_GET['snw_notice'] ) && 'bulk_deleted' === $_GET['snw_notice'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of serial numbers deleted */
							esc_html__( '%d serial numbers deleted.', 'serial-number-for-woocommerce' ),
							isset( $_GET['snw_count'] ) ? absint( $_GET['snw_count'] ) : 0
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php
			$filter_product_option = null;

			if ( $filter_product_id ) {
				$filter_product = wc_get_product( $filter_product_id );

				if ( $filter_product ) {
					$filter_product_option = Ajax::format_product_option( $filter_product );
				}
			}
			?>

			<form method="get">
				<input type="hidden" name="page" value="serial-number-for-woocommerce" />

				<p style="display: flex; align-items: center; gap: 10px; margin: 1em 0;">
					<label for="snw-filter-product-id"><strong><?php esc_html_e( 'Filter by product:', 'serial-number-for-woocommerce' ); ?></strong></label>
					<select
						id="snw-filter-product-id"
						name="snw_filter_product_id"
						class="snw-search-select"
						data-type="product"
						data-placeholder="<?php esc_attr_e( 'All products', 'serial-number-for-woocommerce' ); ?>"
						style="min-width: 240px;"
						<?php disabled( $filter_no_product ); ?>
					>
						<option value=""></option>
						<?php if ( $filter_product_option ) : ?>
							<option value="<?php echo esc_attr( $filter_product_option['id'] ); ?>" selected><?php echo esc_html( $filter_product_option['text'] ); ?></option>
						<?php endif; ?>
					</select>
					<label>
						<input type="checkbox" id="snw-filter-no-product" name="snw_filter_no_product" value="1" <?php checked( $filter_no_product ); ?> />
						<?php esc_html_e( 'No product', 'serial-number-for-woocommerce' ); ?>
					</label>
				</p>

				<?php
				$list_table->search_box( __( 'Search Serial Numbers', 'serial-number-for-woocommerce' ), 'snw-search' );
				$list_table->display();
				?>
			</form>
		</div>
		<script>
		jQuery( function ( $ ) {
			var $productFilter = $( '#snw-filter-product-id' );
			var $noProductFilter = $( '#snw-filter-no-product' );

			$noProductFilter.on( 'change', function () {
				if ( $( this ).is( ':checked' ) ) {
					$productFilter.val( null ).trigger( 'change' ).prop( 'disabled', true );
				} else {
					$productFilter.prop( 'disabled', false );
				}

				this.form.submit();
			} );

			$productFilter.on( 'change', function () {
				if ( $( this ).val() ) {
					$noProductFilter.prop( 'checked', false );
				}

				this.form.submit();
			} );
		} );
		</script>
		<?php
	}
}
