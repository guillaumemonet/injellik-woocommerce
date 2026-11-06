<?php
/**
 * Display of the recommendation blocks.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recommendation blocks: WooCommerce hooks of the built-in placements, cart block, shortcode,
 * editor block, PHP function, front assets and asynchronous rendering (wc-ajax=injellik_render).
 */
final class Injellik_Display {

	/** @var array<string, bool> placements already displayed on the page by a hook */
	private static $displayed = array();

	public static function init() {
		foreach ( Injellik_Config::default_placements() as $key => $placement ) {
			if ( null === $placement['hook'] ) {
				continue;
			}
			add_action(
				$placement['hook'],
				static function ( $arg = null ) use ( $key ) {
					echo self::render_hook( $key, $arg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				$placement['priority']
			);
		}
		// Cart page built with the cart block: the classic hook is not called.
		add_filter( 'render_block_woocommerce/cart', array( __CLASS__, 'after_cart_block' ) );

		add_shortcode( 'injellik_recommendations', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wc_ajax_injellik_render', array( __CLASS__, 'ajax_render' ) );
		add_action( 'injellik_recommendations', 'injellik_recommendations' );
	}

	/**
	 * Block of a built-in placement, displayed by its hook.
	 *
	 * @param string $key Placement.
	 * @param mixed  $arg First argument of the hook (order id of woocommerce_thankyou).
	 */
	public static function render_hook( $key, $arg = null ) {
		if ( ! empty( self::$displayed[ $key ] ) ) {
			return '';
		}
		$config = array( 'placement' => $key );
		switch ( $key ) {
			case 'product':
				if ( ! is_product() ) {
					return '';
				}
				break;
			case 'category':
				if ( ! is_shop() && ! is_product_taxonomy() ) {
					return '';
				}
				break;
			case 'cart':
				if ( ! is_cart() ) {
					return '';
				}
				break;
			case 'order_confirmation':
				if ( ! is_numeric( $arg ) || ! (int) $arg ) {
					return '';
				}
				$config['order_id'] = (int) $arg;
				break;
		}
		self::$displayed[ $key ] = true;

		return self::render( $config, true );
	}

	public static function after_cart_block( $content ) {
		return $content . self::render_hook( 'cart' );
	}

	/**
	 * [injellik_recommendations placement="home"]
	 * [injellik_recommendations strategy="bought" fallback="most_bought" limit="4" title="Complete the look"]
	 * [injellik_recommendations strategy="similar" product_id="12,34"]
	 */
	public static function shortcode( $atts ) {
		return self::render(
			shortcode_atts(
				array(
					'placement'  => '',
					'strategy'   => '',
					'fallback'   => null,
					'limit'      => '',
					'title'      => '',
					'product_id' => '',
				),
				(array) $atts,
				'injellik_recommendations'
			)
		);
	}

	/**
	 * Recommendation block of a placement, with optional overrides.
	 *
	 * A block that names its placement is displayed even when the automatic display of this
	 * placement is disabled: only the WooCommerce hooks follow the setting.
	 *
	 * @param array $config placement, strategy, fallback, limit, title, product_id, order_id.
	 * @param bool  $auto   True for the hooks of the built-in placements.
	 */
	public static function render( array $config, $auto = false ) {
		// Suspended subscription or invalid licence key: no block, without calling the API.
		if ( ! Injellik_Config::is_configured() || Injellik_Config::is_api_blocked() ) {
			return '';
		}

		$key       = ! empty( $config['placement'] ) && is_string( $config['placement'] ) ? sanitize_key( $config['placement'] ) : 'custom';
		$key       = Injellik_Config::placement( $key ) ? $key : 'custom';
		$placement = Injellik_Config::placement( $key );
		if ( $auto && ! $placement['enabled'] ) {
			return '';
		}

		$overrides = array();
		foreach ( array( 'strategy', 'fallback', 'limit', 'title' ) as $option ) {
			if ( isset( $config[ $option ] ) && is_scalar( $config[ $option ] ) && ( 'fallback' === $option || '' !== (string) $config[ $option ] ) ) {
				$overrides[ $option ] = (string) $config[ $option ];
			}
		}

		try {
			return Injellik_Recommender::render_block( Injellik_Recommender::build_request( $key, self::context_products( $key, $config ), $overrides ) );
		} catch ( Exception $e ) {
			Injellik_Event_Queue::log( 'Block rendering failed: ' . $e->getMessage() );

			return '';
		}
	}

	/**
	 * Products the recommendations are computed from: explicit parameter, order, then page context.
	 *
	 * @return int[]
	 */
	private static function context_products( $key, array $config ) {
		if ( ! empty( $config['product_id'] ) ) {
			return array_map( 'intval', is_array( $config['product_id'] ) ? $config['product_id'] : explode( ',', (string) $config['product_id'] ) );
		}

		$order_id = ! empty( $config['order_id'] ) ? (int) $config['order_id'] : 0;
		if ( ! $order_id && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			$order_id = absint( get_query_var( 'order-received' ) );
		}
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$ids = array();
				foreach ( $order->get_items() as $item ) {
					if ( is_callable( array( $item, 'get_product_id' ) ) ) {
						$ids[] = (int) $item->get_product_id();
					}
				}

				return $ids;
			}
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return array( (int) get_queried_object_id() );
		}
		if ( ( 'cart' === $key || ( function_exists( 'is_cart' ) && is_cart() ) ) && function_exists( 'WC' ) && WC()->cart ) {
			return array_map( 'intval', wp_list_pluck( WC()->cart->get_cart(), 'product_id' ) );
		}

		return array();
	}

	/* ------------------------------------------------------------------
	 * Editor block
	 * ------------------------------------------------------------------ */

	public static function register_block() {
		register_block_type(
			INJELLIK_DIR . 'blocks/recommendations',
			array(
				'render_callback' => array( __CLASS__, 'render_editor_block' ),
			)
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'injellik-recommendations-editor-script', 'injellik-recommendations', INJELLIK_DIR . 'languages' );
		}
	}

	public static function render_editor_block( $attributes ) {
		$attributes = (array) $attributes;
		$config     = array(
			'placement'  => isset( $attributes['placement'] ) ? $attributes['placement'] : '',
			'strategy'   => isset( $attributes['strategy'] ) ? $attributes['strategy'] : '',
			'limit'      => ! empty( $attributes['limit'] ) ? (int) $attributes['limit'] : '',
			'title'      => isset( $attributes['title'] ) ? $attributes['title'] : '',
			'product_id' => isset( $attributes['productIds'] ) ? $attributes['productIds'] : '',
		);
		if ( isset( $attributes['fallback'] ) && 'default' !== $attributes['fallback'] ) {
			$config['fallback'] = $attributes['fallback'];
		}
		$html = self::render( $config );

		return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/* ------------------------------------------------------------------
	 * Front assets and asynchronous rendering
	 * ------------------------------------------------------------------ */

	public static function enqueue_assets() {
		if ( ! Injellik_Config::is_configured() ) {
			return;
		}
		wp_enqueue_style( 'injellik-front', INJELLIK_URL . 'assets/css/front.css', array(), INJELLIK_VERSION );
		wp_enqueue_script(
			'injellik-front',
			INJELLIK_URL . 'assets/js/front.js',
			array(),
			INJELLIK_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// The same for every visitor: the page can be cached.
		$config = array(
			'renderUrl' => WC_AJAX::get_endpoint( 'injellik_render' ),
			'trackUrl'  => WC_AJAX::get_endpoint( 'injellik_track' ),
			'view'      => is_product() ? (int) get_queried_object_id() : 0,
		);
		wp_add_inline_script( 'injellik-front', 'window.injellik = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Renders a block for front.js: {"html": "..."}. Only signed requests (generated by the shop when
	 * the page was rendered) are accepted.
	 */
	public static function ajax_render() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the request is signed.
		$payload   = isset( $_POST['payload'] ) ? sanitize_text_field( wp_unslash( $_POST['payload'] ) ) : '';
		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';
		// phpcs:enable

		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		$request = Injellik_Recommender::decode_request( $payload, $signature );
		if ( null === $request ) {
			wp_send_json( array( 'html' => '' ), 400 );
		}

		$html = '';
		try {
			if ( Injellik_Config::is_configured() && ! Injellik_Config::is_api_blocked() ) {
				$html = Injellik_Recommender::render_products( $request );
			}
		} catch ( Exception $e ) {
			Injellik_Event_Queue::log( 'Block rendering failed: ' . $e->getMessage() );
		}

		wp_send_json( array( 'html' => $html ) );
	}
}
