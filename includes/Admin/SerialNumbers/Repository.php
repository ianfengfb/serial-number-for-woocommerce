<?php
namespace SerialNumberForWooCommerce\Admin\SerialNumbers;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for the snw_serial_numbers table.
 */
final class Repository {

	/**
	 * How many times claim_available() re-reads the pool after losing a row to
	 * a concurrent claim before giving up and letting the caller generate one.
	 */
	const CLAIM_ATTEMPTS = 5;

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'snw_serial_numbers';
	}

	public static function exists( string $serial_number, int $exclude_id = 0 ): bool {
		global $wpdb;

		$table = self::table_name();

		if ( $exclude_id > 0 ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE serial_number = %s AND id != %d",
					$serial_number,
					$exclude_id
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE serial_number = %s", $serial_number )
			);
		}

		return $count > 0;
	}

	public static function find( int $id ): ?object {
		global $wpdb;

		$table = self::table_name();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row ?: null;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'serial_number' => $data['serial_number'],
				'status'        => $data['status'],
				'product_id'    => ! empty( $data['product_id'] ) ? absint( $data['product_id'] ) : null,
				'order_id'      => ! empty( $data['order_id'] ) ? absint( $data['order_id'] ) : null,
				'expires_at'    => ! empty( $data['expires_at'] ) ? $data['expires_at'] : null,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Returns the new row's ID, or 0 when the insert failed — e.g. because the
	 * serial number collided with the unique key. `$wpdb->insert_id` keeps its
	 * previous value on a failed query, so it is only trustworthy after a
	 * successful one.
	 */
	public static function insert( array $data ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
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

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Takes one available serial number out of the pool for a product and marks
	 * it Assigned to the given order, returning its ID (0 when the pool is empty).
	 *
	 * The claim is a compare-and-swap: the UPDATE only matches while the row is
	 * still Available, so two simultaneous checkouts can never be handed the same
	 * serial — the loser sees 0 affected rows and reads the next candidate.
	 * Rows already past their expiry date are skipped rather than handed out.
	 */
	public static function claim_available( int $product_id, int $order_id ): int {
		global $wpdb;

		$table = self::table_name();
		$now   = current_time( 'mysql' );

		for ( $attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++ ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table}
					WHERE status = %s
						AND product_id = %d
						AND ( order_id IS NULL OR order_id = 0 )
						AND ( expires_at IS NULL OR expires_at > %s )
					ORDER BY id ASC
					LIMIT 1",
					Status::AVAILABLE,
					$product_id,
					$now
				)
			);

			if ( ! $id ) {
				return 0;
			}

			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, order_id = %d WHERE id = %d AND status = %s",
					Status::ASSIGNED,
					$order_id,
					$id,
					Status::AVAILABLE
				)
			);

			if ( $claimed ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Counts a product's Available, unexpired serial numbers — the number
	 * StockSync mirrors onto WooCommerce stock when that's switched on.
	 */
	public static function count_available( int $product_id ): int {
		global $wpdb;

		$table = self::table_name();
		$now   = current_time( 'mysql' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE status = %s
					AND product_id = %d
					AND ( expires_at IS NULL OR expires_at > %s )",
				Status::AVAILABLE,
				$product_id,
				$now
			)
		);
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
