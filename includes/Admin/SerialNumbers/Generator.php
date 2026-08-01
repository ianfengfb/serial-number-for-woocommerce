<?php
namespace SerialNumberForWooCommerce\Admin\SerialNumbers;

defined( 'ABSPATH' ) || exit;

/**
 * Builds random serial numbers from the auto-generation rules configured on
 * WooCommerce > Settings > Serial Numbers (prefix, postfix, random length).
 *
 * Generation is server-side so the result can be checked for uniqueness
 * against the snw_serial_numbers table before it is handed back — and so the
 * same logic can be reused later (e.g. auto-assigning serials to orders).
 */
final class Generator {

	/**
	 * Character sets for the random part, keyed by the `snw_auto_charset` option.
	 *
	 * The mixed set excludes visually ambiguous glyphs (0/O, 1/I/L) so serials
	 * are less error-prone to read/type; those glyphs are unambiguous — and so
	 * kept — when the set is pure numbers or pure letters.
	 */
	const CHARSET_ALNUM   = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	const CHARSET_ALPHA   = 'ABCDEFGHJKMNPQRSTUVWXYZ';
	const CHARSET_NUMERIC = '0123456789';

	/** How many times to retry on a collision before giving up. */
	const MAX_ATTEMPTS = 10;

	/**
	 * Generate a unique serial number using the configured rules.
	 *
	 * $overrides lets a caller (e.g. a product's own custom rule, or a Pro
	 * bulk-generate row) supply any of 'prefix', 'suffix', 'length', 'charset';
	 * any left unset or empty falls back to the global rule from WooCommerce >
	 * Settings > Serial Numbers.
	 *
	 * Falls back to the last candidate if every attempt collides (astronomically
	 * unlikely); the Add New form's save-time uniqueness check is the backstop.
	 */
	public static function generate( array $overrides = array() ): string {
		$prefix  = ! empty( $overrides['prefix'] ) ? (string) $overrides['prefix'] : (string) get_option( 'snw_auto_prefix', '' );
		$postfix = ! empty( $overrides['suffix'] ) ? (string) $overrides['suffix'] : (string) get_option( 'snw_auto_postfix', '' );
		$length  = ! empty( $overrides['length'] ) ? (int) $overrides['length'] : (int) get_option( 'snw_auto_length', 12 );
		$length  = max( 1, min( 64, $length ) );
		$charset = self::charset_for( ! empty( $overrides['charset'] ) ? (string) $overrides['charset'] : (string) get_option( 'snw_auto_charset', 'alphanumeric' ) );

		$serial = '';
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$serial = self::compose( $prefix, self::random_string( $length, $charset ), $postfix );

			if ( ! Repository::exists( $serial ) ) {
				return $serial;
			}
		}

		return $serial;
	}

	/**
	 * Join prefix, random part and postfix with dashes, skipping any empty
	 * segment so there is no leading/trailing/double dash.
	 */
	private static function compose( string $prefix, string $random, string $postfix ): string {
		$parts = array();

		if ( '' !== $prefix ) {
			$parts[] = $prefix;
		}

		$parts[] = $random;

		if ( '' !== $postfix ) {
			$parts[] = $postfix;
		}

		return implode( '-', $parts );
	}

	private static function charset_for( string $mode ): string {
		switch ( $mode ) {
			case 'numeric':
				return self::CHARSET_NUMERIC;

			case 'alpha':
				return self::CHARSET_ALPHA;

			default:
				return self::CHARSET_ALNUM;
		}
	}

	private static function random_string( int $length, string $charset ): string {
		$max = strlen( $charset ) - 1;
		$out = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $charset[ wp_rand( 0, $max ) ];
		}

		return $out;
	}
}
