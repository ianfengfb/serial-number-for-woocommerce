<?php
namespace SerialNumberForWooCommerce\Admin\SerialNumbers;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and processes the "Add New Serial Number" form.
 */
final class FormController {

	const STATUSES = array(
		'active'   => 'Active',
		'inactive' => 'Inactive',
		'expired'  => 'Expired',
		'revoked'  => 'Revoked',
	);

	private array $errors = array();

	public function handle(): void {
		if ( isset( $_POST['snw_add_serial_number'] ) ) {
			$this->save();
		}
	}

	private function save(): void {
		check_admin_referer( 'snw_add_serial_number' );

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
		} elseif ( Repository::exists( $serial_number ) ) {
			$this->errors[] = __( 'This serial number already exists.', 'serial-number-for-woocommerce' );
		}

		if ( ! isset( self::STATUSES[ $status ] ) ) {
			$this->errors[] = __( 'Please choose a valid status.', 'serial-number-for-woocommerce' );
		}

		if ( '' !== $expires_at && ! \DateTime::createFromFormat( 'Y-m-d', $expires_at ) ) {
			$this->errors[] = __( 'Please enter a valid expiry date.', 'serial-number-for-woocommerce' );
		}

		if ( ! empty( $this->errors ) ) {
			return;
		}

		Repository::insert(
			array(
				'serial_number' => $serial_number,
				'status'        => $status,
				'product_id'    => $product_id,
				'order_id'      => $order_id,
				'expires_at'    => '' !== $expires_at ? $expires_at . ' 00:00:00' : '',
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'serial-number-for-woocommerce',
					'snw_notice' => 'added',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render(): void {
		$back_url = add_query_arg( array( 'page' => 'serial-number-for-woocommerce' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Add New Serial Number', 'serial-number-for-woocommerce' ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'serial-number-for-woocommerce' ); ?></a>

			<?php if ( ! empty( $this->errors ) ) : ?>
				<div class="notice notice-error">
					<?php foreach ( $this->errors as $error ) : ?>
						<p><?php echo esc_html( $error ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'snw_add_serial_number' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="snw-serial-number"><?php esc_html_e( 'Serial Number', 'serial-number-for-woocommerce' ); ?></label></th>
						<td>
							<input
								type="text"
								id="snw-serial-number"
								name="serial_number"
								class="regular-text"
								value="<?php echo isset( $_POST['serial_number'] ) ? esc_attr( wp_unslash( $_POST['serial_number'] ) ) : ''; ?>"
								required
							/>
						</td>
					</tr>
					<tr>
						<th><label for="snw-status"><?php esc_html_e( 'Status', 'serial-number-for-woocommerce' ); ?></label></th>
						<td>
							<select id="snw-status" name="status">
								<?php foreach ( self::STATUSES as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : 'active', $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="snw-product"><?php esc_html_e( 'Product', 'serial-number-for-woocommerce' ); ?></label></th>
						<td><select id="snw-product" name="product_id" class="snw-search-select" data-type="product" style="width: 25em;"></select></td>
					</tr>
					<tr>
						<th><label for="snw-order"><?php esc_html_e( 'Order', 'serial-number-for-woocommerce' ); ?></label></th>
						<td><select id="snw-order" name="order_id" class="snw-search-select" data-type="order" style="width: 25em;"></select></td>
					</tr>
					<tr>
						<th><label for="snw-expires-at"><?php esc_html_e( 'Expires On', 'serial-number-for-woocommerce' ); ?></label></th>
						<td>
							<input
								type="date"
								id="snw-expires-at"
								name="expires_at"
								value="<?php echo isset( $_POST['expires_at'] ) ? esc_attr( wp_unslash( $_POST['expires_at'] ) ) : ''; ?>"
							/>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add Serial Number', 'serial-number-for-woocommerce' ), 'primary', 'snw_add_serial_number' ); ?>
			</form>
		</div>
		<?php
	}
}
