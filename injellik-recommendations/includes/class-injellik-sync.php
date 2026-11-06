<?php
/**
 * Catalog synchronisation and order history import.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Catalog synchronisation and order history import, both processed in batches so that
 * the back office can show a progress bar and never hits max_execution_time.
 *
 * The SKU sent to the API is the WooCommerce product id (variations are reported on their parent product).
 */
final class Injellik_Sync {

	const CATALOG_BATCH = 100;
	const ORDERS_BATCH  = 50;

	/** Statuses of the products sent to the API (the unpublished ones are sent disabled). */
	const PRODUCT_STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	/** @var int[] products saved during the current request (automatic synchronisation) */
	private static $dirty_products = array();
	private static $shutdown_registered = false;

	/* ------------------------------------------------------------------
	 * Catalog
	 * ------------------------------------------------------------------ */

	public static function count_products() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN (" . self::product_statuses_sql() . ')' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Sends the next batch of products after $after_id.
	 *
	 * @return array{done: bool, last_id: int, processed: int, created: int, updated: int}
	 */
	public static function sync_catalog_batch( $after_id, $batch = self::CATALOG_BATCH ) {
		global $wpdb;

		$ids = array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN (" . self::product_statuses_sql() . ') AND ID > %d ORDER BY ID LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $after_id,
					(int) $batch
				)
			)
		);

		$result = array(
			'done'      => count( $ids ) < $batch,
			'last_id'   => (int) $after_id,
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
		);
		if ( ! $ids ) {
			return $result;
		}

		$response            = Injellik_Api_Client::instance()->import_items( self::build_items( $ids ) );
		$result['last_id']   = max( $ids );
		$result['processed'] = count( $ids );
		$result['created']   = isset( $response['created'] ) ? (int) $response['created'] : 0;
		$result['updated']   = isset( $response['updated'] ) ? (int) $response['updated'] : 0;

		return $result;
	}

	/**
	 * Synchronises the whole catalog (cron, WP-CLI).
	 *
	 * @return int products sent
	 */
	public static function sync_catalog() {
		$last_id = 0;
		$total   = 0;
		do {
			$batch    = self::sync_catalog_batch( $last_id );
			$last_id  = $batch['last_id'];
			$total   += $batch['processed'];
		} while ( ! $batch['done'] );

		self::catalog_synced( $total );

		return $total;
	}

	public static function catalog_synced( $count ) {
		Injellik_Config::set_state(
			array(
				'catalog_synced_at' => current_time( 'mysql' ),
				'catalog_count'     => (int) $count,
			)
		);
		Injellik_Cache::clear();
	}

	/**
	 * Item payloads for the API. The "profile" feeds the similarity model (/api/related).
	 *
	 * @param int[] $ids Product ids.
	 * @return array[]
	 */
	public static function build_items( array $ids ) {
		$items = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product || $product->is_type( 'variation' ) ) {
				continue;
			}
			$items[] = self::build_item( $product );
		}

		return $items;
	}

	private static function build_item( WC_Product $product ) {
		$id          = $product->get_id();
		$price       = (float) wc_get_price_including_tax( $product );
		$price       = max( 0, round( $price, 2 ) );
		$name        = trim( wp_strip_all_tags( $product->get_name() ) );
		$description = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
		$description = trim( html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $description ) ), ENT_QUOTES, 'UTF-8' ) );
		$description = trim( preg_replace( '/\s+/u', ' ', $description ) );
		$categories  = array_map( 'strval', $product->get_category_ids() );

		$item = array(
			'sku'     => (string) $id,
			'name'    => self::cut( '' !== $name ? $name : 'Product #' . $id, 255 ),
			'price'   => $price,
			'enabled' => 'publish' === $product->get_status()
				&& in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog', 'search' ), true )
				&& '' === (string) $product->get_post_password(),
			'profile' => array(
				'categories'       => $categories,
				'default_category' => $categories ? $categories[0] : null,
				'price'            => $price,
			),
		);
		if ( '' !== $description ) {
			$item['description'] = self::cut( $description, 2000 );
		}

		$brand = self::brand( $id );
		if ( null !== $brand ) {
			$item['profile']['manufacturer'] = $brand;
		}
		$tags = array_map( 'strval', $product->get_tag_ids() );
		if ( $tags ) {
			$item['profile']['tags'] = $tags;
		}
		if ( null === $item['profile']['default_category'] ) {
			unset( $item['profile']['default_category'] );
		}

		// GTIN / EAN field of WooCommerce 9.2+.
		$ean = method_exists( $product, 'get_global_unique_id' ) ? trim( (string) $product->get_global_unique_id() ) : '';
		if ( '' !== $ean && strlen( $ean ) <= 13 ) {
			$item['ean'] = $ean;
		}

		/**
		 * Filters the item sent to Injellik for a product (add attributes to the profile, change the name...).
		 *
		 * @param array      $item    Item: sku, name, description, price, enabled, profile, ean.
		 * @param WC_Product $product Product.
		 */
		return apply_filters( 'injellik_item', $item, $product );
	}

	/**
	 * Brand of the product: taxonomy of WooCommerce 9.6+ or of the common brand plugins.
	 */
	private static function brand( $product_id ) {
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) && $terms ) {
					return (string) $terms[0];
				}
			}
		}

		return null;
	}

	/**
	 * Queues a product for synchronisation at the end of the request (one API call for a whole import batch).
	 */
	public static function mark_product_dirty( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}
		self::$dirty_products[ $product_id ] = $product_id;
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'sync_dirty_products' ), 1010 );
		}
	}

	public static function sync_dirty_products() {
		if ( ! self::$dirty_products ) {
			return;
		}
		$ids                  = array_values( self::$dirty_products );
		self::$dirty_products = array();
		if ( ! Injellik_Config::is_configured() || ! Injellik_Config::get( 'auto_sync' ) || Injellik_Config::is_api_blocked() ) {
			return;
		}

		try {
			$items = self::build_items( $ids );
			// Products deleted from the shop: disable them so that they are no longer recommended.
			$found = array_column( $items, 'sku' );
			foreach ( $ids as $id ) {
				if ( ! in_array( (string) $id, $found, true ) && ! get_post( $id ) ) {
					$items[] = array(
						'sku'     => (string) $id,
						'name'    => 'Product #' . $id,
						'enabled' => false,
					);
				}
			}
			foreach ( array_chunk( $items, self::CATALOG_BATCH ) as $chunk ) {
				Injellik_Api_Client::instance()->import_items( $chunk );
			}
		} catch ( Injellik_Api_Exception $e ) {
			if ( $e->is_access_denied() ) {
				// Already displayed in the back office; the next full synchronisation sends these products.
				return;
			}
			Injellik_Config::record_error( $e->getMessage() );
			Injellik_Event_Queue::log( 'Product synchronisation failed: ' . $e->getMessage() );
		}
	}

	/* ------------------------------------------------------------------
	 * Order history
	 * ------------------------------------------------------------------ */

	public static function count_orders( $after_id = 0, $since = null ) {
		global $wpdb;

		list( $table, $id_column, $where ) = self::orders_query( $after_id, $since );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Sends a BUY action for each product of the next orders after $after_id.
	 * Each order becomes its own session (= one basket for the association rules).
	 *
	 * When the API cannot be reached, the batch stops: "error" holds the exception and "last_id"
	 * the last order completely sent, from which the next call restarts.
	 *
	 * @return array{done: bool, last_id: int, processed: int, sent: int, errors: int, error: Injellik_Api_Exception|null}
	 */
	public static function import_orders_batch( $after_id, $since = null, $batch = self::ORDERS_BATCH ) {
		global $wpdb;

		list( $table, $id_column, $where ) = self::orders_query( $after_id, $since );

		$order_ids = array_map( 'intval', $wpdb->get_col( "SELECT {$id_column} FROM {$table} WHERE {$where} ORDER BY {$id_column} LIMIT " . (int) $batch ) ); // phpcs:ignore WordPress.DB

		$result = array(
			'done'      => count( $order_ids ) < $batch,
			'last_id'   => (int) $after_id,
			'processed' => 0,
			'sent'      => 0,
			'errors'    => 0,
			'error'     => null,
		);
		if ( ! $order_ids ) {
			return $result;
		}

		// The API refuses actions dated more than 3 years ago: older orders are skipped.
		$oldest   = time() - 3 * YEAR_IN_SECONDS + DAY_IN_SECONDS;
		$payloads = array();
		$owners   = array();
		$included = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			$date  = $order ? $order->get_date_created() : null;
			$lines = array();
			// Orders already sent by the live tracking (placed after the installation) are not sent twice.
			if ( $order && $date && $date->getTimestamp() >= $oldest && ! $order->get_meta( Injellik_Tracker::META_TRACKED ) ) {
				foreach ( $order->get_items() as $item ) {
					/** @var WC_Order_Item_Product $item */
					$product_id = is_callable( array( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;
					if ( $product_id > 0 ) {
						$lines[ $product_id ] = $item->get_name();
					}
				}
			}
			// One API call per batch: stop before exceeding the size of a batch.
			if ( $included && count( $payloads ) + count( $lines ) > Injellik_Event_Queue::BATCH_SIZE ) {
				$result['done'] = false;
				break;
			}
			$included[] = $order_id;
			foreach ( $lines as $product_id => $name ) {
				$key              = $order_id . ':' . $product_id;
				$owners[ $key ]   = $order_id;
				$payloads[ $key ] = array(
					'action_type'       => 'BUY',
					'item_sku'          => (string) $product_id,
					'user_uuid'         => Injellik_Identity::order_customer_uuid( $order ),
					'user_session_uuid' => 'order-' . $order_id,
					'name'              => self::cut( $name, 255 ),
					// History import: real order date, and not counted as a billable session.
					'source'            => 'import',
					'occurred_at'       => $date->format( 'c' ),
				);
			}
		}

		$delivery = $payloads ? Injellik_Event_Queue::deliver( $payloads ) : array(
			'sent'     => array(),
			'rejected' => array(),
			'error'    => null,
		);
		$result['sent']   = count( $delivery['sent'] );
		$result['errors'] = count( $delivery['rejected'] );

		// Orders whose actions were all delivered (sent or refused by the API).
		$undelivered = array();
		if ( $delivery['error'] ) {
			foreach ( $owners as $key => $order_id ) {
				if ( ! in_array( $key, $delivery['sent'], true ) && ! isset( $delivery['rejected'][ $key ] ) ) {
					$undelivered[ $order_id ] = true;
				}
			}
			$result['error'] = $delivery['error'];
			$result['done']  = false;
		}
		foreach ( $included as $order_id ) {
			if ( isset( $undelivered[ $order_id ] ) ) {
				break;
			}
			$result['last_id'] = $order_id;
			++$result['processed'];
		}

		return $result;
	}

	/**
	 * Table, id column and conditions of the paid orders, with or without High-Performance Order Storage.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	private static function orders_query( $after_id, $since ) {
		global $wpdb;

		$statuses = array();
		foreach ( wc_get_is_paid_statuses() as $status ) {
			$statuses[] = $wpdb->prepare( '%s', 'wc-' . $status );
		}
		$statuses = implode( ',', $statuses );
		$since    = is_string( $since ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ? $since . ' 00:00:00' : null;

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table = OrderUtil::get_table_for_orders();
			$where = $wpdb->prepare( "type = 'shop_order' AND status IN ({$statuses}) AND id > %d", (int) $after_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $since ) {
				$where .= $wpdb->prepare( ' AND date_created_gmt >= %s', get_gmt_from_date( $since ) );
			}

			return array( $table, 'id', $where );
		}

		$where = $wpdb->prepare( "post_type = 'shop_order' AND post_status IN ({$statuses}) AND ID > %d", (int) $after_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $since ) {
			$where .= $wpdb->prepare( ' AND post_date >= %s', $since );
		}

		return array( $wpdb->posts, 'ID', $where );
	}

	private static function product_statuses_sql() {
		return "'" . implode( "','", self::PRODUCT_STATUSES ) . "'";
	}

	private static function cut( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $length ) : substr( (string) $text, 0, $length );
	}
}
