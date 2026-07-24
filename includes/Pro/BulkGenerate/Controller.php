<?php
namespace SerialNumberForWooCommerce\Pro\BulkGenerate;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Generator;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: generates many serial numbers at once across one or more
 * prefix / suffix / product / amount rows.
 */
final class Controller {

	const MAX_AMOUNT_PER_ROW = 500;

	private array $errors = array();

	public function handle(): void {
		if ( ! Licensing::is_pro_active() ) {
			return;
		}

		if ( isset( $_POST['snw_bulk_generate'] ) ) {
			$this->generate();
		}
	}

	private function generate(): void {
		check_admin_referer( 'snw_bulk_generate_serial_numbers' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) );
		}

		$rows = $this->parse_rows();

		if ( empty( $rows ) ) {
			if ( empty( $this->errors ) ) {
				$this->errors[] = __( 'Add at least one row with a product and an amount.', 'serial-number-for-woocommerce' );
			}
			return;
		}

		$default_status = get_option( 'snw_default_status', 'active' );
		$total          = 0;

		foreach ( $rows as $row ) {
			for ( $i = 0; $i < $row['amount']; $i++ ) {
				Repository::insert(
					array(
						'serial_number' => Generator::generate( $row['prefix'], $row['suffix'] ),
						'status'        => $default_status,
						'product_id'    => $row['product_id'],
						'order_id'      => 0,
						'expires_at'    => '',
					)
				);
				++$total;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'serial-number-for-woocommerce',
					'snw_notice' => 'bulk_generated',
					'snw_count'  => $total,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Validates and normalizes submitted rows, silently skipping any row left
	 * completely blank (e.g. an extra "Add Row" click the user never filled in).
	 */
	private function parse_rows(): array {
		$posted = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array();
		$rows   = array();

		foreach ( $posted as $index => $row ) {
			$prefix     = isset( $row['prefix'] ) ? sanitize_text_field( $row['prefix'] ) : '';
			$suffix     = isset( $row['suffix'] ) ? sanitize_text_field( $row['suffix'] ) : '';
			$product_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
			$amount     = isset( $row['amount'] ) ? absint( $row['amount'] ) : 0;

			if ( ! $product_id && ! $amount && '' === $prefix && '' === $suffix ) {
				continue;
			}

			$row_number = (int) $index + 1;

			if ( ! $product_id || ! wc_get_product( $product_id ) ) {
				$this->errors[] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: please choose a product.', 'serial-number-for-woocommerce' ),
					$row_number
				);
				continue;
			}

			if ( $amount < 1 || $amount > self::MAX_AMOUNT_PER_ROW ) {
				$this->errors[] = sprintf(
					/* translators: 1: row number, 2: maximum amount allowed per row */
					__( 'Row %1$d: amount must be between 1 and %2$d.', 'serial-number-for-woocommerce' ),
					$row_number,
					self::MAX_AMOUNT_PER_ROW
				);
				continue;
			}

			$rows[] = array(
				'prefix'     => $prefix,
				'suffix'     => $suffix,
				'product_id' => $product_id,
				'amount'     => $amount,
			);
		}

		if ( ! empty( $this->errors ) ) {
			return array();
		}

		return $rows;
	}

	public function render(): void {
		if ( ! Licensing::is_pro_active() ) {
			return;
		}

		$back_url = add_query_arg( array( 'page' => 'serial-number-for-woocommerce' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Bulk Generate Serial Numbers', 'serial-number-for-woocommerce' ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'serial-number-for-woocommerce' ); ?></a>

			<?php if ( ! empty( $this->errors ) ) : ?>
				<div class="notice notice-error">
					<?php foreach ( $this->errors as $error ) : ?>
						<p><?php echo esc_html( $error ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Leave Prefix/Suffix blank on a row to use the global rules from WooCommerce > Settings > Serial Numbers.', 'serial-number-for-woocommerce' ); ?></p>

			<form method="post" id="snw-bulk-generate-form">
				<?php wp_nonce_field( 'snw_bulk_generate_serial_numbers' ); ?>
				<table class="widefat" id="snw-bulk-rows">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Prefix', 'serial-number-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Suffix', 'serial-number-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Product', 'serial-number-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'serial-number-for-woocommerce' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php echo $this->render_row( 0 ); ?>
					</tbody>
				</table>
				<p>
					<button type="button" id="snw-add-row" class="button"><?php esc_html_e( 'Add Row', 'serial-number-for-woocommerce' ); ?></button>
				</p>
				<?php submit_button( __( 'Generate Serial Numbers', 'serial-number-for-woocommerce' ), 'primary', 'snw_bulk_generate' ); ?>
			</form>

			<script type="text/html" id="snw-bulk-row-template">
				<?php echo $this->render_row( '__INDEX__' ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * @param int|string $index Row index, or the literal placeholder
	 *                          "__INDEX__" when rendering the JS clone template.
	 */
	private function render_row( $index ): string {
		ob_start();
		?>
		<tr class="snw-bulk-row">
			<td><input type="text" name="rows[<?php echo esc_attr( $index ); ?>][prefix]" class="regular-text" /></td>
			<td><input type="text" name="rows[<?php echo esc_attr( $index ); ?>][suffix]" class="regular-text" /></td>
			<td>
				<select
					name="rows[<?php echo esc_attr( $index ); ?>][product_id]"
					class="snw-search-select"
					data-type="product"
					data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'serial-number-for-woocommerce' ); ?>"
				>
					<option value=""></option>
				</select>
			</td>
			<td>
				<input
					type="number"
					name="rows[<?php echo esc_attr( $index ); ?>][amount]"
					min="1"
					max="<?php echo esc_attr( self::MAX_AMOUNT_PER_ROW ); ?>"
					value="1"
					class="small-text"
				/>
			</td>
			<td><button type="button" class="button snw-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'serial-number-for-woocommerce' ); ?>">&times;</button></td>
		</tr>
		<?php
		return ob_get_clean();
	}
}
