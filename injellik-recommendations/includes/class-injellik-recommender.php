<?php
/**
 * Recommendations and their rendering.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches recommendations from the API and renders them with the product loop of the theme.
 *
 * A block is described by a "request": placement, strategy, fallback, limit, title and the
 * source products. Requests are signed when they are rendered asynchronously so that the
 * render endpoint only renders blocks the shop has generated.
 */
final class Injellik_Recommender {

	const TEMPLATE = 'recommendations.php';

	/**
	 * Builds the block request of a placement, with optional overrides (shortcode or block attributes).
	 *
	 * @param string $placement_key Placement.
	 * @param int[]  $product_ids   Products the recommendations are computed from.
	 * @param array  $overrides     strategy, fallback, limit, title.
	 */
	public static function build_request( $placement_key, array $product_ids = array(), array $overrides = array() ) {
		$key        = Injellik_Config::placement( $placement_key ) ? $placement_key : 'custom';
		$placement  = Injellik_Config::placement( $key );
		$strategies = Injellik_Config::strategies();

		$request = array(
			'placement' => $key,
			'strategy'  => $placement['strategy'],
			'fallback'  => $placement['fallback'],
			'limit'     => (int) $placement['limit'],
			'title'     => '',
			'products'  => array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) ),
		);

		if ( ! empty( $overrides['strategy'] ) && isset( $strategies[ $overrides['strategy'] ] ) ) {
			$request['strategy'] = $overrides['strategy'];
		}
		if ( isset( $overrides['fallback'] ) && ( '' === $overrides['fallback'] || 'none' === $overrides['fallback'] || isset( $strategies[ $overrides['fallback'] ] ) ) ) {
			$request['fallback'] = 'none' === $overrides['fallback'] ? '' : $overrides['fallback'];
		}
		if ( ! empty( $overrides['limit'] ) ) {
			$request['limit'] = max( 1, min( 24, (int) $overrides['limit'] ) );
		}
		if ( isset( $overrides['title'] ) && '' !== trim( (string) $overrides['title'] ) ) {
			$request['title'] = trim( wp_strip_all_tags( (string) $overrides['title'] ) );
		}
		if ( '' === $request['title'] ) {
			$request['title'] = '' !== $placement['title'] ? $placement['title'] : self::default_title( $request['strategy'] );
		}
		$request['title']    = function_exists( 'mb_substr' ) ? mb_substr( $request['title'], 0, 128 ) : substr( $request['title'], 0, 128 );
		$request['products'] = array_slice( $request['products'], 0, 20 );

		return $request;
	}

	/**
	 * Renders the block: the products directly ("server" mode), or a placeholder loaded by front.js.
	 */
	public static function render_block( array $request ) {
		if ( Injellik_Config::RENDER_SERVER === Injellik_Config::get( 'render_mode' ) ) {
			return self::render_products( $request );
		}

		$payload = base64_encode( wp_json_encode( $request ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return sprintf(
			'<div class="injellik-reco-placeholder js-injellik-reco" data-injellik-placement="%s" data-injellik-payload="%s" data-injellik-signature="%s"></div>',
			esc_attr( $request['placement'] ),
			esc_attr( $payload ),
			esc_attr( self::sign( $payload ) )
		);
	}

	/**
	 * Renders the product list, or an empty string when there is nothing to show.
	 */
	public static function render_products( array $request ) {
		$ids      = self::recommend( $request );
		$products = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$products[] = $product;
			}
		}
		if ( ! $products ) {
			return '';
		}

		Injellik_Stats::impression( $request['placement'] );

		$columns = max( 1, min( 6, (int) Injellik_Config::get( 'columns' ) ) );
		/**
		 * Filters the number of columns of a recommendation block.
		 *
		 * @param int   $columns Columns.
		 * @param array $request Block request.
		 */
		$columns = (int) apply_filters( 'injellik_columns', $columns, $request );

		ob_start();
		wc_get_template(
			self::TEMPLATE,
			array(
				'placement' => $request['placement'],
				'title'     => $request['title'],
				'products'  => $products,
				'columns'   => $columns,
				'request'   => $request,
			),
			'injellik-recommendations/',
			INJELLIK_DIR . 'templates/'
		);

		return (string) ob_get_clean();
	}

	/**
	 * Product ids recommended for a request: main strategy, then fallback, without the source products.
	 *
	 * @return int[]
	 */
	public static function recommend( array $request ) {
		$exclude = array_flip( $request['products'] );
		$ids     = array();
		foreach ( array_unique( array_filter( array( $request['strategy'], $request['fallback'] ) ) ) as $strategy ) {
			foreach ( self::fetch( $strategy, $request['products'], $request['limit'] + count( $exclude ) + 4 ) as $id ) {
				if ( ! isset( $exclude[ $id ] ) && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
			$ids = self::filter_available( $ids );
			if ( count( $ids ) >= $request['limit'] ) {
				break;
			}
		}

		/**
		 * Filters the product ids of a recommendation block, in display order.
		 *
		 * @param int[] $ids     Product ids.
		 * @param array $request Block request.
		 */
		$ids = (array) apply_filters( 'injellik_recommended_products', array_slice( $ids, 0, $request['limit'] ), $request );

		return array_map( 'intval', $ids );
	}

	/**
	 * Calls the API for one strategy. Failures are logged and give an empty list: a block never breaks a page.
	 *
	 * @param string $strategy    Strategy.
	 * @param int[]  $product_ids Source products.
	 * @param int    $limit       Number of results.
	 * @return int[]
	 */
	public static function fetch( $strategy, array $product_ids, $limit ) {
		$strategies = Injellik_Config::strategies();
		if ( ! isset( $strategies[ $strategy ] ) || ! Injellik_Config::is_configured() || Injellik_Config::is_api_blocked() ) {
			return array();
		}
		$context = $strategies[ $strategy ]['context'];
		if ( 'items' === $context && ! $product_ids ) {
			return array();
		}

		$limit     = max( 1, min( 100, (int) $limit ) );
		$user      = 'user' === $context ? Injellik_Identity::user_uuid() : null;
		$cache_key = implode( '|', array( $strategy, implode( ',', $product_ids ), (string) $user, $limit ) );
		$ttl       = 'user' === $context ? 60 : (int) Injellik_Config::get( 'cache_ttl' );

		// After a refusal of the API, the cache must not display blocks before the API accepts the shop again.
		$cached = Injellik_Config::api_state() ? null : Injellik_Cache::get( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$skus = self::extract_skus( self::call_api( $strategy, $product_ids, $user, $limit ) );
		} catch ( Injellik_Api_Exception $e ) {
			if ( $e->is_access_denied() ) {
				// Suspended subscription or invalid key: the block is hidden, the back office shows why.
				return array();
			}
			if ( 404 !== $e->get_status() ) {
				Injellik_Config::record_error( $e->getMessage() );

				return array();
			}
			// Unknown product (not synchronised yet): nothing to recommend, cache it like an empty result.
			$skus = array();
		}

		$ids = array();
		foreach ( $skus as $sku ) {
			if ( ctype_digit( (string) $sku ) && (int) $sku > 0 ) {
				$ids[] = (int) $sku;
			}
		}
		Injellik_Cache::set( $cache_key, $ids, $ttl, null !== $user );

		return $ids;
	}

	private static function call_api( $strategy, array $product_ids, $user, $limit ) {
		$client = Injellik_Api_Client::instance();
		$query  = array( 'limit' => $limit );
		$first  = $product_ids ? rawurlencode( (string) $product_ids[0] ) : '';
		if ( count( $product_ids ) > 1 ) {
			$query['item_sku'] = array_map( 'strval', array_slice( $product_ids, 1 ) );
		}

		switch ( $strategy ) {
			case 'viewed':
				return $client->get( '/api/recommendations/viewed/' . $first, $query );
			case 'bought':
				return $client->get( '/api/recommendations/bought/' . $first, $query );
			case 'similar':
				return $client->get( '/api/related/' . $first, array( 'limit' => $limit ) );
			case 'personalized':
				return $client->get( '/api/recommendations/user/' . rawurlencode( $user ), array( 'limit' => $limit ) );
			case 'predictive':
				return $client->post(
					'/api/recommendations/predictive',
					array(
						'user_uuid' => $user,
						'limit'     => $limit,
					)
				);
			case 'most_viewed':
				return $client->get( '/api/community-rankings/most-viewed', array( 'limit' => $limit ) );
			case 'most_bought':
				return $client->get( '/api/community-rankings/most-bought', array( 'limit' => $limit ) );
		}

		return array();
	}

	/**
	 * Reads the SKUs of any recommendation or ranking response.
	 *
	 * @return string[]
	 */
	public static function extract_skus( array $response ) {
		foreach ( array( 'recommendations', 'related_items', 'most_viewed_items', 'most_bought_items' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
				$skus = array();
				foreach ( $response[ $key ] as $row ) {
					if ( is_array( $row ) && isset( $row['sku'] ) && '' !== (string) $row['sku'] ) {
						$skus[] = (string) $row['sku'];
					}
				}

				return $skus;
			}
		}

		return array();
	}

	/**
	 * Keeps the products that are published, visible in the catalog and (optionally) in stock, in the API order.
	 *
	 * @param int[] $ids Product ids.
	 * @return int[]
	 */
	private static function filter_available( array $ids ) {
		$hide_out_of_stock = (bool) Injellik_Config::get( 'hide_out_of_stock' );
		$result            = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || $product->is_type( 'variation' ) || 'publish' !== $product->get_status()
				|| ! in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog' ), true )
				|| '' !== (string) $product->get_post_password() ) {
				continue;
			}
			if ( $hide_out_of_stock && ! $product->is_in_stock() ) {
				continue;
			}
			$result[] = $id;
		}

		return $result;
	}

	/**
	 * Title of a block whose placement has no custom title, worded after its strategy.
	 */
	public static function default_title( $strategy ) {
		switch ( $strategy ) {
			case 'viewed':
				return __( 'Customers who viewed this product also viewed', 'injellik-recommendations' );
			case 'bought':
				return __( 'Frequently bought together', 'injellik-recommendations' );
			case 'similar':
				return __( 'Similar products', 'injellik-recommendations' );
			case 'personalized':
			case 'predictive':
				return __( 'Selected for you', 'injellik-recommendations' );
			case 'most_viewed':
				return __( 'Most viewed products', 'injellik-recommendations' );
			case 'most_bought':
				return __( 'Best sellers', 'injellik-recommendations' );
		}

		return __( 'You may also like', 'injellik-recommendations' );
	}

	public static function sign( $payload ) {
		return hash_hmac( 'sha256', (string) $payload, wp_salt( 'nonce' ) . '|injellik' );
	}

	/**
	 * Decodes a signed request from front.js, or returns null when it was tampered with.
	 */
	public static function decode_request( $payload, $signature ) {
		if ( ! is_string( $payload ) || ! is_string( $signature ) || ! hash_equals( self::sign( $payload ), $signature ) ) {
			return null;
		}
		$request = json_decode( (string) base64_decode( $payload, true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_array( $request ) || ! isset( $request['placement'], $request['strategy'], $request['limit'], $request['products'] ) ) {
			return null;
		}
		$request['placement'] = (string) $request['placement'];
		$request['strategy']  = (string) $request['strategy'];
		$request['fallback']  = isset( $request['fallback'] ) ? (string) $request['fallback'] : '';
		$request['title']     = isset( $request['title'] ) ? (string) $request['title'] : '';
		$request['products']  = array_map( 'intval', (array) $request['products'] );
		$request['limit']     = max( 1, min( 24, (int) $request['limit'] ) );

		return $request;
	}
}
