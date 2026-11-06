<?php
/**
 * Bootstrap.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Starts the plugin once WooCommerce is loaded.
 */
final class Injellik_Plugin {

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing' ) );

			return;
		}

		Injellik_Installer::maybe_upgrade();
		Injellik_Tracker::init();
		Injellik_Display::init();
		Injellik_Cron::init();
		Injellik_Privacy::init();
		add_action( 'woocommerce_new_product', array( __CLASS__, 'product_saved' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'product_saved' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'variation_saved' ) );
		add_action( 'trashed_post', array( __CLASS__, 'post_changed' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'post_changed' ) );
		add_action( 'deleted_post', array( __CLASS__, 'post_deleted' ), 10, 2 );

		if ( is_admin() ) {
			require_once INJELLIK_DIR . 'includes/admin/class-injellik-admin.php';
			Injellik_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once INJELLIK_DIR . 'includes/class-injellik-cli.php';
			WP_CLI::add_command( 'injellik', 'Injellik_CLI' );
		}
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'injellik-recommendations', false, dirname( plugin_basename( INJELLIK_FILE ) ) . '/languages' );
	}

	public static function woocommerce_missing() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Injellik Recommendations requires WooCommerce.', 'injellik-recommendations' ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------
	 * Automatic catalog synchronisation
	 * ------------------------------------------------------------------ */

	public static function product_saved( $product_id ) {
		if ( Injellik_Config::is_configured() ) {
			Injellik_Sync::mark_product_dirty( $product_id );
		}
	}

	public static function variation_saved( $variation_id ) {
		$parent = wp_get_post_parent_id( $variation_id );
		if ( $parent ) {
			self::product_saved( $parent );
		}
	}

	public static function post_changed( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			self::product_saved( $post_id );
		}
	}

	public static function post_deleted( $post_id, $post = null ) {
		if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
			self::product_saved( $post_id );
		}
	}
}
