<?php
/**
 * Print Slip (Pro). A standalone printable document, not a wp-admin page —
 * overridable by copying this file to yourtheme/woocommerce/print/order-slip.php.
 *
 * @var \WC_Order $order
 * @var array     $items           Each: product_name (string), is_license (bool),
 *                                  serials (snw_serial_numbers rows), license
 *                                  (null|array{instructions,duration}), warranty
 *                                  (null|array{duration}).
 * @var string    $default_message Settings' configured default, pre-filled into
 *                                  the editable message box below.
 */

defined( 'ABSPATH' ) || exit;

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<title>
<?php
printf(
	/* translators: %s: order number */
	esc_html__( 'Print Slip - Order %s', 'serial-number-for-woocommerce' ),
	esc_html( $order->get_order_number() )
);
?>
</title>
<style>
	body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #222; max-width: 640px; margin: 40px auto; padding: 0 20px; }
	h1 { font-size: 20px; margin-bottom: 4px; }
	.snw-meta { color: #666; margin-bottom: 24px; }
	.snw-item { border-top: 1px solid #ddd; padding: 16px 0; }
	.snw-item h2 { font-size: 16px; margin: 0 0 8px; }
	.snw-item ul { margin: 0 0 8px; padding-left: 20px; }
	.snw-instructions, .snw-warranty { font-size: 14px; color: #444; margin: 4px 0; }
	.snw-message-box { margin: 24px 0; }
	.snw-message-box textarea { width: 100%; min-height: 80px; font-family: inherit; font-size: 14px; padding: 8px; box-sizing: border-box; }
	.snw-message-print { display: none; white-space: pre-wrap; font-size: 14px; margin: 24px 0; }
	@media print {
		.snw-print-hide { display: none !important; }
		.snw-message-print { display: block !important; }
		body { margin: 0; padding: 0; max-width: none; }
	}
</style>
</head>
<body>

<h1>
<?php
printf(
	/* translators: %s: order number */
	esc_html__( 'Order %s', 'serial-number-for-woocommerce' ),
	esc_html( $order->get_order_number() )
);
?>
</h1>
<p class="snw-meta">
<?php
printf(
	/* translators: 1: order date, 2: customer name */
	esc_html__( '%1$s — %2$s', 'serial-number-for-woocommerce' ),
	esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ),
	esc_html( $order->get_formatted_billing_full_name() )
);
?>
</p>

<?php foreach ( $items as $item ) : ?>
	<div class="snw-item">
		<h2><?php echo esc_html( $item['product_name'] ); ?></h2>
		<p>
			<strong>
			<?php
			echo esc_html(
				$item['is_license']
					? _n( 'License Key', 'License Keys', count( $item['serials'] ), 'serial-number-for-woocommerce' )
					: _n( 'Serial Number', 'Serial Numbers', count( $item['serials'] ), 'serial-number-for-woocommerce' )
			);
			?>
			:</strong>
		</p>
		<ul>
			<?php foreach ( $item['serials'] as $serial ) : ?>
				<li>
					<?php
					echo esc_html( $serial->serial_number );

					if ( $serial->expires_at ) {
						printf(
							/* translators: %s: expiry date */
							esc_html__( ' (expires %s)', 'serial-number-for-woocommerce' ),
							esc_html( date_i18n( get_option( 'date_format' ), strtotime( $serial->expires_at ) ) )
						);
					}
					?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $item['license'] && $item['license']['instructions'] ) : ?>
			<div class="snw-instructions"><?php echo wp_kses_post( wpautop( wptexturize( $item['license']['instructions'] ) ) ); ?></div>
		<?php endif; ?>

		<?php if ( $item['warranty'] ) : ?>
			<?php $duration = $item['warranty']['duration']; ?>
			<p class="snw-warranty">
			<?php
			printf(
				/* translators: 1: warranty length, 2: warranty period (Month(s)/Year(s)) */
				esc_html__( 'Warranty: %1$d %2$s', 'serial-number-for-woocommerce' ),
				$duration['length'],
				esc_html(
					'month' === $duration['period']
						? _n( 'Month', 'Months', $duration['length'], 'serial-number-for-woocommerce' )
						: _n( 'Year', 'Years', $duration['length'], 'serial-number-for-woocommerce' )
				)
			);
			?>
			</p>
		<?php endif; ?>
	</div>
<?php endforeach; ?>

<div class="snw-message-box snw-print-hide">
	<label for="snw-print-message"><strong><?php esc_html_e( 'Message', 'serial-number-for-woocommerce' ); ?></strong></label><br />
	<textarea id="snw-print-message"><?php echo esc_textarea( $default_message ); ?></textarea>
</div>
<div class="snw-message-print" id="snw-print-message-preview"><?php echo esc_html( $default_message ); ?></div>

<p class="snw-print-hide">
	<button type="button" onclick="window.print();"><?php esc_html_e( 'Print', 'serial-number-for-woocommerce' ); ?></button>
</p>

<script>
( function () {
	var textarea = document.getElementById( 'snw-print-message' );
	var preview  = document.getElementById( 'snw-print-message-preview' );

	if ( ! textarea || ! preview ) {
		return;
	}

	textarea.addEventListener( 'input', function () {
		preview.textContent = textarea.value;
	} );
}() );
</script>

</body>
</html>
