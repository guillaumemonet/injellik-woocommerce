<?php
/**
 * Plugin Name:          Injellik Recommendations
 * Plugin URI:           https://www.web-nest.fr/injellik
 * Description:          Personalized product recommendations powered by the Injellik API: tracks views, carts and orders, and displays recommendation blocks on your pages.
 * Version:              1.0.0
 * Author:               Web-Nest
 * Author URI:           https://web-nest.fr
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          injellik-recommendations
 * Domain Path:          /languages
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

define( 'INJELLIK_VERSION', '1.0.0' );
define( 'INJELLIK_FILE', __FILE__ );
define( 'INJELLIK_DIR', plugin_dir_path( __FILE__ ) );
define( 'INJELLIK_URL', plugin_dir_url( __FILE__ ) );

require_once INJELLIK_DIR . 'includes/class-injellik-api-exception.php';
require_once INJELLIK_DIR . 'includes/class-injellik-config.php';
require_once INJELLIK_DIR . 'includes/class-injellik-api-client.php';
require_once INJELLIK_DIR . 'includes/class-injellik-cache.php';
require_once INJELLIK_DIR . 'includes/class-injellik-identity.php';
require_once INJELLIK_DIR . 'includes/class-injellik-event-queue.php';
require_once INJELLIK_DIR . 'includes/class-injellik-stats.php';
require_once INJELLIK_DIR . 'includes/class-injellik-sync.php';
require_once INJELLIK_DIR . 'includes/class-injellik-recommender.php';
require_once INJELLIK_DIR . 'includes/class-injellik-installer.php';
require_once INJELLIK_DIR . 'includes/class-injellik-tracker.php';
require_once INJELLIK_DIR . 'includes/class-injellik-display.php';
require_once INJELLIK_DIR . 'includes/class-injellik-cron.php';
require_once INJELLIK_DIR . 'includes/class-injellik-privacy.php';
require_once INJELLIK_DIR . 'includes/class-injellik-plugin.php';

register_activation_hook( __FILE__, array( 'Injellik_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Injellik_Installer', 'deactivate' ) );

// High-Performance Order Storage and cart / checkout blocks.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Injellik_Plugin', 'boot' ) );

/**
 * Displays a recommendation block from a theme template:
 *
 *   <?php if ( function_exists( 'injellik_recommendations' ) ) injellik_recommendations( array( 'placement' => 'home' ) ); ?>
 *   <?php injellik_recommendations( array( 'strategy' => 'bought', 'fallback' => 'most_bought', 'limit' => 4, 'title' => 'Complete the look' ) ); ?>
 *
 * Same parameters as the [injellik_recommendations] shortcode.
 *
 * @param array $args placement, strategy, fallback, limit, title, product_id.
 * @param bool  $echo false to return the HTML.
 * @return string
 */
function injellik_recommendations( $args = array(), $echo = true ) {
	$html = class_exists( 'Injellik_Display' ) ? Injellik_Display::render( (array) $args ) : '';
	if ( $echo ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by templates that escape their output.
	}

	return $html;
}
