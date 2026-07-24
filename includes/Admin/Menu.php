<?php
namespace SerialNumberForWooCommerce\Admin;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Ajax;
use SerialNumberForWooCommerce\Admin\SerialNumbers\FormController;
use SerialNumberForWooCommerce\Admin\SerialNumbers\ListTable;

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

		$this->render_list();
	}

	private function render_list(): void {
		$list_table = new ListTable();
		$list_table->prepare_items();

		$add_new_url = add_query_arg(
			array(
				'page'   => 'serial-number-for-woocommerce',
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Serial Numbers', 'serial-number-for-woocommerce' ); ?></h1>
			<a href="<?php echo esc_url( $add_new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'serial-number-for-woocommerce' ); ?></a>

			<?php if ( isset( $_GET['snw_notice'] ) && 'added' === $_GET['snw_notice'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Serial number added.', 'serial-number-for-woocommerce' ); ?></p>
				</div>
			<?php elseif ( isset( $_GET['snw_notice'] ) && 'updated' === $_GET['snw_notice'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Serial number updated.', 'serial-number-for-woocommerce' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="serial-number-for-woocommerce" />
				<?php
				$list_table->search_box( __( 'Search Serial Numbers', 'serial-number-for-woocommerce' ), 'snw-search' );
				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}
}
