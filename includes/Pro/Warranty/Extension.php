<?php
namespace SerialNumberForWooCommerce\Pro\Warranty;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: lets a customer pay to extend a product's warranty at the moment
 * they purchase it — an option on the product page, not a separate product,
 * so there's nothing for the customer to mismatch or forget to add in the
 * right quantity. The chosen duration is snapshotted onto the order line
 * item at checkout, so later changes to the product's extension settings
 * never retroactively change what an already-placed order paid for.
 */
final class Extension {

	/** POST field name on the single-product add-to-cart form. */
	const CHECKBOX_FIELD = 'snw_add_warranty_extension';

	/** Cart item data key holding the chosen duration while it's in the cart. */
	const CART_ITEM_KEY = 'snw_warranty_extension';

	/** Order item meta key the duration is snapshotted to at checkout. */
	const ITEM_META_KEY = '_snw_warranty_extension';

	public function __construct() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_checkbox' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'adjust_price' ) );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );
	}

	public static function is_enabled_for_product( int $product_id ): bool {
		return Warranty::is_enabled_for_product( $product_id )
			&& 'yes' === get_post_meta( $product_id, ProductTab::WARRANTY_EXTENSION_ENABLED_META_KEY, true );
	}

	/**
	 * @return array{length: int, period: string} period is 'month' or 'year'.
	 */
	public static function duration_for_product( int $product_id ): array {
		$length = absint( get_post_meta( $product_id, ProductTab::WARRANTY_EXTENSION_LENGTH_META_KEY, true ) );
		$period = get_post_meta( $product_id, ProductTab::WARRANTY_EXTENSION_PERIOD_META_KEY, true );

		return array(
			'length' => $length > 0 ? $length : 1,
			'period' => in_array( $period, array( 'month', 'year' ), true ) ? $period : 'year',
		);
	}

	public static function price_for_product( int $product_id ): float {
		return (float) get_post_meta( $product_id, ProductTab::WARRANTY_EXTENSION_PRICE_META_KEY, true );
	}

	/**
	 * The duration purchased for a specific order line item, or null if that
	 * item didn't include the extension. Reads the checkout-time snapshot,
	 * not the product's current settings.
	 */
	public static function duration_for_order_item( \WC_Order_Item_Product $item ): ?array {
		$data = $item->get_meta( self::ITEM_META_KEY, true );

		if ( ! is_array( $data ) || empty( $data['length'] ) || empty( $data['period'] ) ) {
			return null;
		}

		return array(
			'length' => absint( $data['length'] ),
			'period' => in_array( $data['period'], array( 'month', 'year' ), true ) ? $data['period'] : 'year',
		);
	}

	public function render_checkbox(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! self::is_enabled_for_product( $product->get_id() ) ) {
			return;
		}

		$duration = self::duration_for_product( $product->get_id() );
		$price    = self::price_for_product( $product->get_id() );
		?>
		<p class="snw-warranty-extension-offer">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::CHECKBOX_FIELD ); ?>" value="1" />
				<?php
				printf(
					/* translators: 1: extension duration (e.g. "6 Months"), 2: formatted extension price */
					esc_html__( 'Add %1$s extra warranty (+%2$s)', 'serial-number-for-woocommerce' ),
					esc_html( self::format_duration( $duration ) ),
					wp_kses_post( wc_price( $price ) )
				);
				?>
			</label>
		</p>
		<?php
	}

	public function add_cart_item_data( array $cart_item_data, int $product_id ): array {
		if ( self::is_enabled_for_product( $product_id ) && isset( $_POST[ self::CHECKBOX_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$cart_item_data[ self::CART_ITEM_KEY ] = self::duration_for_product( $product_id );
		}

		return $cart_item_data;
	}

	public function adjust_price( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item[ self::CART_ITEM_KEY ] ) ) {
				continue;
			}

			$price = self::price_for_product( $cart_item['product_id'] );

			if ( $price > 0 ) {
				$cart_item['data']->set_price( $cart_item['data']->get_price() + $price );
			}
		}
	}

	public function display_cart_item_data( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item[ self::CART_ITEM_KEY ] ) ) {
			$item_data[] = array(
				'name'  => __( 'Warranty extension', 'serial-number-for-woocommerce' ),
				'value' => self::format_duration( $cart_item[ self::CART_ITEM_KEY ] ),
			);
		}

		return $item_data;
	}

	/**
	 * @param array $values The cart item's full data array (including our
	 *                       CART_ITEM_KEY entry, if the extension was chosen).
	 */
	public function save_order_item_meta( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		if ( ! empty( $values[ self::CART_ITEM_KEY ] ) ) {
			$item->add_meta_data( self::ITEM_META_KEY, $values[ self::CART_ITEM_KEY ] );
		}
	}

	private static function format_duration( array $duration ): string {
		$label = 'month' === $duration['period']
			? _n( '%d Month', '%d Months', $duration['length'], 'serial-number-for-woocommerce' )
			: _n( '%d Year', '%d Years', $duration['length'], 'serial-number-for-woocommerce' );

		return sprintf( $label, $duration['length'] );
	}
}
