<?php
namespace SerialNumberForWooCommerce\Admin\SerialNumbers;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for the snw_serial_numbers table.
 */
final class Repository {

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'snw_serial_numbers';
	}

	public static function exists( string $serial_number ): bool {
		global $wpdb;

		$table = self::table_name();
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE serial_number = %s", $serial_number )
		);

		return $count > 0;
	}

	public static function insert( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'serial_number' => $data['serial_number'],
				'status'        => $data['status'],
				'product_id'    => ! empty( $data['product_id'] ) ? absint( $data['product_id'] ) : null,
				'order_id'      => ! empty( $data['order_id'] ) ? absint( $data['order_id'] ) : null,
				'created_at'    => current_time( 'mysql' ),
				'expires_at'    => ! empty( $data['expires_at'] ) ? $data['expires_at'] : null,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function search( string $search, int $per_page, int $page ): array {
		global $wpdb;

		$table  = self::table_name();
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE serial_number LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$like,
					$per_page,
					$offset
				)
			);
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE serial_number LIKE %s", $like )
			);
		} else {
			$items = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset )
			);
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
