<?php
namespace SerialNumberForWooCommerce\Pro\CustomRules;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;
use SerialNumberForWooCommerce\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: a product's own auto-generation rule, overriding the global one from
 * WooCommerce > Settings > Serial Numbers for that product only.
 */
final class CustomRules {

	public static function is_enabled_for_product( int $product_id ): bool {
		return Licensing::is_pro_active()
			&& 'yes' === get_post_meta( $product_id, ProductTab::META_KEY, true )
			&& 'yes' === get_post_meta( $product_id, ProductTab::CUSTOM_RULE_ENABLED_META_KEY, true );
	}

	/**
	 * Effective Generator overrides for a product: its own custom rule (when
	 * enabled) with $extra_overrides layered on top for anything the caller
	 * explicitly supplies — e.g. a Bulk Generate row's own prefix/suffix,
	 * which should win over the product's stored rule for that one run.
	 */
	public static function resolve_overrides( int $product_id, array $extra_overrides = array() ): array {
		$overrides = array();

		if ( self::is_enabled_for_product( $product_id ) ) {
			$overrides = array(
				'prefix'  => get_post_meta( $product_id, ProductTab::CUSTOM_PREFIX_META_KEY, true ),
				'suffix'  => get_post_meta( $product_id, ProductTab::CUSTOM_SUFFIX_META_KEY, true ),
				'length'  => get_post_meta( $product_id, ProductTab::CUSTOM_LENGTH_META_KEY, true ),
				'charset' => get_post_meta( $product_id, ProductTab::CUSTOM_CHARSET_META_KEY, true ),
			);
		}

		foreach ( $extra_overrides as $key => $value ) {
			if ( '' !== $value && null !== $value ) {
				$overrides[ $key ] = $value;
			}
		}

		return $overrides;
	}
}
