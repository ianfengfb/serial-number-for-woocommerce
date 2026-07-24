<?php
namespace SerialNumberForWooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Single gate every Pro-only feature must check before running.
 *
 * Local development: define SNW_DEV_UNLOCK_ALL as true (e.g. in wp-config.php)
 * to unlock Pro features without a license key.
 */
final class Licensing {

	public static function is_pro_active(): bool {
		if ( defined( 'SNW_DEV_UNLOCK_ALL' ) && true === SNW_DEV_UNLOCK_ALL ) {
			return true;
		}

		return (bool) apply_filters( 'snw_is_pro_active', false );
	}
}
