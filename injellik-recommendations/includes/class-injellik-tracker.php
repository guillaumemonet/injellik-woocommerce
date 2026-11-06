<?php
/**
 * Tracking of the visitor actions.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records the actions of the visitors in the local queue (never calls the API during the request).
 *
 * - VIEW and CLICK are reported by front.js (wc-ajax=injellik_track), so that they also work behind a
 *   full page cache, where PHP does not run for the page itself;
 * - ADDCART, BUY and RATED come from the WooCommerce hooks.
 *
 * Sessions are the basis of the subscription billing: nothing is tracked for the bots (when they are
 * excluded), and an order validated outside of a visit (payment notification, back-office order) gets
 * a session of its own instead of a new one.
 */
final class Injellik_Tracker {

	const META_USER    = '_injellik_user';
	const META_SESSION = '_injellik_session';
	const META_TRACKED = '_injellik_tracked';

	public static function init() {
		add_action( 'wc_ajax_injellik_track', array( __CLASS__, 'ajax_track' ) );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'add_to_cart' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'remember_visitor' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'remember_visitor' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_status_changed' ), 10, 4 );
		add_action( 'comment_post', array( __CLASS__, 'review_posted' ), 20, 2 );
		add_action( 'transition_comment_status', array( __CLASS__, 'review_approved' ), 10, 3 );
	}

	public static function is_tracking_enabled() {
		return Injellik_Config::is_configured()
			&& ! ( Injellik_Config::get( 'exclude_bots' ) && Injellik_Identity::is_bot() );
	}

	/**
	 * Queues an action of the current visitor.
	 *
	 * @return int|null id of the queued event
	 */
	public static function track( $action_type, $product_id, $rating = null ) {
		if ( ! self::is_tracking_enabled() ) {
			return null;
		}

		return Injellik_Event_Queue::push(
			$action_type,
			(string) (int) $product_id,
			Injellik_Identity::user_uuid(),
			Injellik_Identity::session_uuid(),
			null,
			$rating
		);
	}

	/**
	 * VIEW of a product page and CLICK on a recommended product, sent by front.js.
	 */
	public static function ajax_track() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public tracking beacon, sent from cacheable pages.
		$type       = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$placement  = isset( $_POST['placement'] ) ? sanitize_key( wp_unslash( $_POST['placement'] ) ) : '';
		// phpcs:enable

		$product = 'post' === $method && $product_id ? wc_get_product( $product_id ) : null;
		if ( $product && $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( $product && 'publish' === $product->get_status() ) {
			if ( 'view' === $type ) {
				self::track( 'VIEW', $product->get_id() );
			} elseif ( 'click' === $type ) {
				self::track( 'CLICK', $product->get_id() );
				if ( Injellik_Config::placement( $placement ) ) {
					Injellik_Stats::click( $placement );
				}
			}
		}

		nocache_headers();
		status_header( 204 );
		exit;
	}

	public static function add_to_cart( $cart_item_key, $product_id, $quantity = 1, $variation_id = 0 ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		self::track( 'ADDCART', (int) $product_id );
	}

	/**
	 * Remembers the visitor and the session of the checkout on the order: the BUY actions are recorded
	 * when the order is paid, often in a payment notification sent by a server, outside of the visit.
	 *
	 * @param WC_Order|mixed $order Order.
	 */
	public static function remember_visitor( $order ) {
		if ( ! $order instanceof WC_Order || ! self::is_tracking_enabled() ) {
			return;
		}
		$order->update_meta_data( self::META_USER, Injellik_Identity::user_uuid() );
		$order->update_meta_data( self::META_SESSION, Injellik_Identity::has_session() ? Injellik_Identity::session_uuid() : '' );
		$order->save_meta_data();
	}

	/**
	 * BUY actions, once per order, when it reaches a status that validates it.
	 */
	public static function order_status_changed( $order_id, $from, $to, $order = null ) {
		/**
		 * Filters the order statuses that record the purchase (once per order).
		 *
		 * @param string[] $statuses Statuses, without the "wc-" prefix.
		 */
		$statuses = (array) apply_filters( 'injellik_tracked_order_statuses', array( 'processing', 'completed', 'on-hold' ) );
		if ( ! in_array( $to, $statuses, true ) || ! Injellik_Config::is_configured() ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::META_TRACKED ) ) {
			return;
		}
		$order->update_meta_data( self::META_TRACKED, current_time( 'mysql' ) );
		$order->save_meta_data();

		$user    = (string) $order->get_meta( self::META_USER );
		$user    = '' !== $user ? $user : Injellik_Identity::order_customer_uuid( $order );
		$session = (string) $order->get_meta( self::META_SESSION );
		$visit   = '' !== $session;
		$session = $visit ? $session : 'order-' . $order->get_id();

		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product_id = is_callable( array( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;
			if ( $product_id > 0 ) {
				Injellik_Event_Queue::push( 'BUY', (string) $product_id, $user, $session, $item->get_name() );
			}
		}

		// The next purchases of the visitor belong to another basket.
		if ( $visit && Injellik_Identity::has_session() && Injellik_Identity::session_uuid() === $session ) {
			Injellik_Identity::reset_session();
		}
	}

	/**
	 * Product review published immediately: RATED action of the visitor.
	 */
	public static function review_posted( $comment_id, $approved ) {
		if ( 1 === $approved || '1' === $approved ) {
			self::track_review( get_comment( $comment_id ), true );
		}
	}

	/**
	 * Product review approved by the merchant: RATED action of its author.
	 */
	public static function review_approved( $new_status, $old_status, $comment ) {
		if ( 'approved' === $new_status && 'approved' !== $old_status ) {
			self::track_review( $comment, false );
		}
	}

	/**
	 * @param WP_Comment|null $comment Review.
	 * @param bool            $live    True when the author is the visitor of the current request.
	 */
	private static function track_review( $comment, $live ) {
		if ( ! $comment instanceof WP_Comment || ! Injellik_Config::is_configured() || 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}
		$rating = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
		if ( $rating < 1 || $rating > 5 || get_comment_meta( $comment->comment_ID, self::META_TRACKED, true ) ) {
			return;
		}
		if ( $live ) {
			if ( ! self::is_tracking_enabled() ) {
				return;
			}
			$user    = Injellik_Identity::user_uuid();
			$session = Injellik_Identity::session_uuid();
		} else {
			$user    = $comment->user_id ? Injellik_Identity::customer_uuid( (int) $comment->user_id ) : Injellik_Identity::guest_uuid( $comment->comment_author_email );
			$session = 'review-' . $comment->comment_ID;
		}
		update_comment_meta( $comment->comment_ID, self::META_TRACKED, 1 );
		Injellik_Event_Queue::push( 'RATED', (string) (int) $comment->comment_post_ID, $user, $session, null, $rating );
	}
}
