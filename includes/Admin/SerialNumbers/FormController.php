<?php
namespace SerialNumberForWooCommerce\Admin\SerialNumbers;

use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Pro\StockSync\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and processes the "Add New Serial Number" form.
 */
final class FormController {

	private int $id;

	private ?object $existing = null;

	private array $errors = array();

	public function __construct( int $id = 0 ) {
		$this->id = $id;

		if ( $this->id > 0 ) {
			$this->existing = Repository::find( $this->id );

			if ( ! $this->existing ) {
				wp_die( esc_html__( 'Serial number not found.', 'serial-number-for-woocommerce' ) );
			}
		}
	}

	private function is_edit(): bool {
		return $this->id > 0;
	}

	private function nonce_action(): string {
		return $this->is_edit() ? 'snw_edit_serial_number_' . $this->id : 'snw_add_serial_number';
	}

	public function handle(): void {
		if ( isset( $_POST['snw_save_serial_number'] ) ) {
			$this->save();
		}
	}

	private function save(): void {
		check_admin_referer( $this->nonce_action() );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) );
		}

		$serial_number = isset( $_POST['serial_number'] ) ? sanitize_text_field( wp_unslash( $_POST['serial_number'] ) ) : '';
		$status        = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$product_id    = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$order_id      = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$expires_at    = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '';

		if ( '' === $serial_number ) {
			$this->errors[] = __( 'Serial number is required.', 'serial-number-for-woocommerce' );
		} elseif ( Repository::exists( $serial_number, $this->id ) ) {
			$this->errors[] = __( 'This serial number already exists.', 'serial-number-for-woocommerce' );
		}

		if ( ! Status::exists( $status ) ) {
			$this->errors[] = __( 'Please choose a valid status.', 'serial-number-for-woocommerce' );
		}

		if ( '' !== $expires_at && ! \DateTime::createFromFormat( 'Y-m-d', $expires_at ) ) {
			$this->errors[] = __( 'Please enter a valid expiry date.', 'serial-number-for-woocommerce' );
		}

		if ( ! empty( $this->errors ) ) {
			return;
		}

		$data = array(
			'serial_number' => $serial_number,
			'status'        => $status,
			'product_id'    => $product_id,
			'order_id'      => $order_id,
			'expires_at'    => '' !== $expires_at ? $expires_at . ' 00:00:00' : '',
		);

		if ( $this->is_edit() ) {
			$previous_product_id = (int) ( $this->existing->product_id ?? 0 );
			Repository::update( $this->id, $data );
			$notice = 'updated';

			if ( Licensing::is_pro_active() && $previous_product_id && $previous_product_id !== $product_id ) {
				StockSync::sync( $previous_product_id );
			}
		} else {
			Repository::insert( $data );
			$notice = 'added';
		}

		if ( Licensing::is_pro_active() && $product_id ) {
			StockSync::sync( $product_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'serial-number-for-woocommerce',
					'snw_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Resolves a field's display value: submitted POST value first (so a
	 * validation error redisplay keeps what the user typed), then the
	 * existing record's value when editing, then the given default.
	 */
	private function field( string $key, string $default = '' ): string {
		if ( isset( $_POST[ $key ] ) ) {
			return (string) wp_unslash( $_POST[ $key ] );
		}

		if ( $this->existing && isset( $this->existing->$key ) && null !== $this->existing->$key ) {
			return (string) $this->existing->$key;
		}

		return $default;
	}

	private function selected_option( string $key ): ?array {
		$id = isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : ( $this->existing->$key ?? 0 );

		if ( ! $id ) {
			return null;
		}

		if ( 'product_id' === $key ) {
			$product = wc_get_product( $id );
			return $product ? Ajax::format_product_option( $product ) : null;
		}

		$order = wc_get_order( $id );
		return $order ? Ajax::format_order_option( $order ) : null;
	}

	public function render(): void {
		$back_url       = add_query_arg( array( 'page' => 'serial-number-for-woocommerce' ), admin_url( 'admin.php' ) );
		$status_default = $this->field( 'status', Status::configured_default() );
		$expires_at      = $this->field( 'expires_at' );
		if ( '' !== $expires_at && ! isset( $_POST['expires_at'] ) ) {
			$expires_at = substr( $expires_at, 0, 10 );
		}
		$product_option = $this->selected_option( 'product_id' );
		$order_option   = $this->selected_option( 'order_id' );
		$title          = $this->is_edit() ? __( 'Edit Serial Number', 'serial-number-for-woocommerce' ) : __( 'Add New Serial Number', 'serial-number-for-woocommerce' );
		$submit_label   = $this->is_edit() ? __( 'Update Serial Number', 'serial-number-for-woocommerce' ) : __( 'Add Serial Number', 'serial-number-for-woocommerce' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'serial-number-for-woocommerce' ); ?></a>

			<?php if ( ! empty( $this->errors ) ) : ?>
				<div class="notice notice-error">
					<?php foreach ( $this->errors as $error ) : ?>
						<p><?php echo esc_html( $error ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( $this->nonce_action() ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="snw-product"><?php esc_html_e( 'Product', 'serial-number-for-woocommerce' ); ?></label></th>
						<td>
							<select
								id="snw-product"
								name="product_id"
								class="snw-search-select"
								data-type="product"
								data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'serial-number-for-woocommerce' ); ?>"
							>
								<option value=""></option>
								<?php if ( $product_option ) : ?>
									<option value="<?php echo esc_attr( $product_option['id'] ); ?>" selected><?php echo esc_html( $product_option['text'] ); ?></option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="snw-serial-number"><?php esc_html_e( 'Serial Number', 'serial-number-for-woocommerce' ); ?></label></th>
						<td>
							<input
								type="text"
								id="snw-serial-number"
								name="serial_number"
								class="regular-text"
								value="<?php echo esc_attr( $this->field( 'serial_number' ) ); ?>"
								required
							/>
							<button type="button" id="snw-generate-serial" class="button"><?php esc_html_e( 'Generate', 'serial-number-for-woocommerce' ); ?></button>
							<p class="description"><?php esc_html_e( 'Click Generate to fill this field using the rules in WooCommerce > Settings > Serial Numbers — or the product\'s own custom rule if one is selected above and enabled for it.', 'serial-number-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="snw-status"><?php esc_html_e( 'Status', 'serial-number-for-woocommerce' ); ?></label></th>
						<td>
							<select id="snw-status" name="status">
								<?php foreach ( Status::all() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status_default, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="snw-order"><?php esc_html_e( 'Order', 'serial-number-for-woocommerce' ); ?></label></th>
						<td>
							<select
								id="snw-order"
								name="order_id"
								class="snw-search-select"
								data-type="order"
								data-placeholder="<?php esc_attr_e( 'Search for an order&hellip;', 'serial-number-for-woocommerce' ); ?>"
							>
								<option value=""></option>
								<?php if ( $order_option ) : ?>
									<option value="<?php echo esc_attr( $order_option['id'] ); ?>" selected><?php echo esc_html( $order_option['text'] ); ?></option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="snw-expires-at"><?php esc_html_e( 'Expires On', 'serial-number-for-woocommerce' ); ?></label></th>
						<td>
							<input
								type="date"
								id="snw-expires-at"
								name="expires_at"
								value="<?php echo esc_attr( $expires_at ); ?>"
							/>
						</td>
					</tr>
				</table>
				<?php submit_button( $submit_label, 'primary', 'snw_save_serial_number' ); ?>
			</form>
		</div>
		<?php
	}
}
