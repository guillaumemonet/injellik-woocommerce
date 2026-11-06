<?php
/**
 * Settings, runtime state, placements and strategies.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Two options:
 * - "injellik_settings": what the merchant configures (connection, blocks, advanced settings);
 * - "injellik_state": what the plugin records (cached JWT, refusal of the API, last synchronisations, errors).
 *
 * On a multisite network, each site has its own options, tables and licence key.
 */
final class Injellik_Config {

	const OPTION       = 'injellik_settings';
	const STATE_OPTION = 'injellik_state';

	/** Production API, used when no URL is configured. */
	const DEFAULT_API_URL = 'https://injellik.web-nest.fr';
	/** Licence key of a shop, displayed in the web-nest.fr account: "ijk_lic_" + 40 lowercase hexadecimal characters. */
	const LICENCE_KEY_PATTERN = '/^ijk_lic_[0-9a-f]{40}$/';

	/** The subscription of the account is suspended (HTTP 403 "tenant_suspended"). */
	const STATE_SUSPENDED = 'suspended';
	/** The licence key is unknown or revoked (HTTP 401). */
	const STATE_INVALID_KEY = 'invalid_key';
	/** Seconds between two attempts while the API refuses the shop. */
	const STATE_RETRY_DELAY = 900;

	const SUBSCRIPTION_URL  = 'https://web-nest.fr/mon-compte/abonnement/';
	const ACCOUNT_SHOPS_URL = 'https://web-nest.fr/mon-compte/boutiques/';
	const PRICING_URL       = 'https://web-nest.fr/#tarifs';

	const RENDER_AJAX   = 'ajax';
	const RENDER_SERVER = 'server';
	const SEND_REALTIME = 'realtime';
	const SEND_CRON     = 'cron';

	/**
	 * Settings written at activation (only the missing ones, so that a reactivation keeps the credentials).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'api_url'           => self::DEFAULT_API_URL,
			'licence_key'       => '',
			'api_login'         => '',
			'api_password'      => '',
			'onboarded'         => false,
			'render_mode'       => self::RENDER_AJAX,
			'send_mode'         => self::SEND_REALTIME,
			'timeout'           => 3,
			'cache_ttl'         => 900,
			'columns'           => 4,
			'hide_out_of_stock' => true,
			'exclude_bots'      => true,
			'auto_sync'         => true,
			'nightly_catalog'   => true,
			'cron_token'        => '',
			'placements'        => array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function get( $key ) {
		$settings = self::all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : null;
	}

	/**
	 * @param array<string, mixed> $values Settings to change.
	 */
	public static function set( array $values ) {
		return update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * Runtime state recorded by the plugin.
	 */
	public static function state( $key, $default = null ) {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) && array_key_exists( $key, $state ) ? $state[ $key ] : $default;
	}

	/**
	 * @param array<string, mixed> $values State values to change (null removes a value).
	 */
	public static function set_state( array $values ) {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		foreach ( $values as $key => $value ) {
			if ( null === $value ) {
				unset( $state[ $key ] );
			} else {
				$state[ $key ] = $value;
			}
		}

		return update_option( self::STATE_OPTION, $state );
	}

	/**
	 * URL of the API, the production API when none is configured.
	 */
	public static function api_url() {
		$url = trim( (string) self::get( 'api_url' ) );

		return '' !== $url ? $url : self::DEFAULT_API_URL;
	}

	/**
	 * A licence key (default mode) or a login and a password (accounts created before the subscriptions).
	 */
	public static function is_configured() {
		return self::uses_licence_key() || ( '' !== (string) self::get( 'api_login' ) && '' !== (string) self::get( 'api_password' ) );
	}

	public static function uses_licence_key() {
		return '' !== (string) self::get( 'licence_key' );
	}

	public static function is_onboarded() {
		return (bool) self::get( 'onboarded' );
	}

	/**
	 * Trims and lowercases a licence key typed or pasted in the back office.
	 */
	public static function normalize_licence_key( $key ) {
		return strtolower( trim( (string) $key ) );
	}

	public static function is_valid_licence_key( $key ) {
		return (bool) preg_match( self::LICENCE_KEY_PATTERN, (string) $key );
	}

	/**
	 * Token of the cron URL, created on first use.
	 */
	public static function cron_token() {
		$token = (string) self::get( 'cron_token' );
		if ( '' === $token ) {
			$token = wp_generate_password( 32, false );
			self::set( array( 'cron_token' => $token ) );
		}

		return $token;
	}

	/* ------------------------------------------------------------------
	 * Refusal of the API
	 * ------------------------------------------------------------------ */

	/**
	 * State of a shop the API refuses: subscription suspended or licence key invalid.
	 *
	 * @return array{reason: string, since: string, checked_at: int}|null
	 */
	public static function api_state() {
		$state = self::state( 'api_state' );

		return is_array( $state ) && isset( $state['reason'] ) ? $state + array(
			'since'      => '',
			'checked_at' => 0,
		) : null;
	}

	/**
	 * True while the API must not be called: it refused the shop less than STATE_RETRY_DELAY seconds ago.
	 */
	public static function is_api_blocked() {
		$state = self::api_state();

		return null !== $state && (int) $state['checked_at'] > time() - self::STATE_RETRY_DELAY;
	}

	/**
	 * Records a refusal of the API (the first date is kept while the reason is the same).
	 */
	public static function set_api_state( $reason ) {
		$state = self::api_state();

		return self::set_state(
			array(
				'api_state' => array(
					'reason'     => (string) $reason,
					'since'      => $state && $state['reason'] === $reason && $state['since'] ? $state['since'] : current_time( 'mysql' ),
					'checked_at' => time(),
				),
			)
		);
	}

	/**
	 * Marks the start of a new attempt, so that concurrent requests keep waiting.
	 */
	public static function touch_api_state() {
		$state = self::api_state();
		if ( $state ) {
			$state['checked_at'] = time();
			self::set_state( array( 'api_state' => $state ) );
		}
	}

	/**
	 * Allows a new attempt at the next call (the state stays displayed until then).
	 */
	public static function allow_api_retry() {
		$state = self::api_state();
		if ( $state ) {
			$state['checked_at'] = 0;
			self::set_state( array( 'api_state' => $state ) );
		}
	}

	public static function clear_api_state() {
		if ( self::api_state() ) {
			self::set_state( array( 'api_state' => null ) );
		}
	}

	/**
	 * Back-office message of an API error, worded for the refusals the merchant can fix.
	 */
	public static function api_error_message( Exception $e ) {
		if ( $e instanceof Injellik_Api_Exception ) {
			if ( $e->is_suspended() ) {
				return __( 'Your Injellik subscription is suspended.', 'injellik-recommendations' );
			}
			if ( $e->is_invalid_key() ) {
				return __( 'Invalid or revoked licence key.', 'injellik-recommendations' );
			}
		}

		return $e->getMessage();
	}

	/**
	 * Pages of the web-nest.fr account linked from the back office.
	 *
	 * @return array{pricing: string, shops: string, subscription: string}
	 */
	public static function links() {
		return array(
			'pricing'      => self::PRICING_URL,
			'shops'        => self::ACCOUNT_SHOPS_URL,
			'subscription' => self::SUBSCRIPTION_URL,
		);
	}

	public static function record_error( $message ) {
		self::set_state(
			array(
				'last_error' => array(
					'date'    => current_time( 'mysql' ),
					'message' => function_exists( 'mb_substr' ) ? mb_substr( (string) $message, 0, 500 ) : substr( (string) $message, 0, 500 ),
				),
			)
		);
	}

	/**
	 * @return array{date: string, message: string}|null
	 */
	public static function last_error() {
		$error = self::state( 'last_error' );

		return is_array( $error ) ? $error : null;
	}

	/* ------------------------------------------------------------------
	 * Strategies and placements
	 * ------------------------------------------------------------------ */

	/**
	 * Recommendation strategies and the context they need:
	 * "items" strategies need at least one product, "user" strategies use the visitor identity.
	 *
	 * @return array<string, array{context: string}>
	 */
	public static function strategies() {
		return array(
			'viewed'       => array( 'context' => 'items' ),
			'bought'       => array( 'context' => 'items' ),
			'similar'      => array( 'context' => 'items' ),
			'personalized' => array( 'context' => 'user' ),
			'predictive'   => array( 'context' => 'user' ),
			'most_viewed'  => array( 'context' => 'none' ),
			'most_bought'  => array( 'context' => 'none' ),
		);
	}

	/**
	 * Labels of the strategies for the back office.
	 *
	 * @return array<string, array{label: string, help: string, context: string}>
	 */
	public static function strategy_labels() {
		return array(
			'viewed'       => array(
				'label'   => __( 'Viewed together', 'injellik-recommendations' ),
				'help'    => __( 'Products viewed in the same sessions as the current product(s).', 'injellik-recommendations' ),
				'context' => 'items',
			),
			'bought'       => array(
				'label'   => __( 'Bought together', 'injellik-recommendations' ),
				'help'    => __( 'Products ordered with the current product(s).', 'injellik-recommendations' ),
				'context' => 'items',
			),
			'similar'      => array(
				'label'   => __( 'Similar products', 'injellik-recommendations' ),
				'help'    => __( 'Products with close categories, brand and price.', 'injellik-recommendations' ),
				'context' => 'items',
			),
			'personalized' => array(
				'label'   => __( 'Personalized', 'injellik-recommendations' ),
				'help'    => __( 'Based on the recent activity of the visitor.', 'injellik-recommendations' ),
				'context' => 'user',
			),
			'predictive'   => array(
				'label'   => __( 'Customers like you', 'injellik-recommendations' ),
				'help'    => __( 'What customers with similar purchases also bought.', 'injellik-recommendations' ),
				'context' => 'user',
			),
			'most_viewed'  => array(
				'label'   => __( 'Most viewed', 'injellik-recommendations' ),
				'help'    => __( 'Most viewed products over the last 30 days.', 'injellik-recommendations' ),
				'context' => 'none',
			),
			'most_bought'  => array(
				'label'   => __( 'Best sellers', 'injellik-recommendations' ),
				'help'    => __( 'Most ordered products over the last 30 days.', 'injellik-recommendations' ),
				'context' => 'none',
			),
		);
	}

	/**
	 * Built-in placements and the WooCommerce hook each one is displayed on.
	 * The placements without a hook are displayed by the block, the shortcode or the PHP function.
	 *
	 * @return array<string, array{hook: string|null, priority: int, enabled: bool, strategy: string, fallback: string, limit: int, title: string}>
	 */
	public static function default_placements() {
		return array(
			'product'            => array(
				'hook'     => 'woocommerce_after_single_product_summary',
				'priority' => 25,
				'enabled'  => true,
				'strategy' => 'viewed',
				'fallback' => 'similar',
				'limit'    => 4,
				'title'    => '',
			),
			'cart'               => array(
				'hook'     => 'woocommerce_after_cart',
				'priority' => 10,
				'enabled'  => true,
				'strategy' => 'bought',
				'fallback' => 'most_bought',
				'limit'    => 4,
				'title'    => '',
			),
			'order_confirmation' => array(
				'hook'     => 'woocommerce_thankyou',
				'priority' => 20,
				'enabled'  => true,
				'strategy' => 'bought',
				'fallback' => 'personalized',
				'limit'    => 4,
				'title'    => '',
			),
			'category'           => array(
				'hook'     => 'woocommerce_after_shop_loop',
				'priority' => 20,
				'enabled'  => false,
				'strategy' => 'personalized',
				'fallback' => 'most_viewed',
				'limit'    => 4,
				'title'    => '',
			),
			'home'               => array(
				'hook'     => null,
				'priority' => 0,
				'enabled'  => true,
				'strategy' => 'personalized',
				'fallback' => 'most_viewed',
				'limit'    => 8,
				'title'    => '',
			),
			'custom'             => array(
				'hook'     => null,
				'priority' => 0,
				'enabled'  => true,
				'strategy' => 'personalized',
				'fallback' => 'most_viewed',
				'limit'    => 4,
				'title'    => '',
			),
		);
	}

	/**
	 * Stored placements merged over the defaults (new placements appear after an update).
	 */
	public static function placements() {
		$stored     = self::get( 'placements' );
		$placements = self::default_placements();
		foreach ( $placements as $key => $default ) {
			if ( is_array( $stored ) && isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
				$placements[ $key ] = self::sanitize_placement( array_merge( $default, $stored[ $key ] ), $default );
			}
		}

		return $placements;
	}

	public static function placement( $key ) {
		$placements = self::placements();

		return isset( $placements[ $key ] ) ? $placements[ $key ] : null;
	}

	public static function save_placements( array $placements ) {
		$clean = array();
		foreach ( self::default_placements() as $key => $default ) {
			$placement = self::sanitize_placement( isset( $placements[ $key ] ) ? array_merge( $default, (array) $placements[ $key ] ) : $default, $default );
			unset( $placement['hook'], $placement['priority'] );
			$clean[ $key ] = $placement;
		}

		return self::set( array( 'placements' => $clean ) );
	}

	private static function sanitize_placement( array $placement, array $default ) {
		$strategies            = self::strategies();
		$placement['hook']     = $default['hook'];
		$placement['priority'] = $default['priority'];
		$placement['enabled']  = null === $default['hook'] ? true : (bool) $placement['enabled'];
		if ( ! isset( $strategies[ $placement['strategy'] ] ) ) {
			$placement['strategy'] = $default['strategy'];
		}
		if ( '' !== $placement['fallback'] && ! isset( $strategies[ $placement['fallback'] ] ) ) {
			$placement['fallback'] = $default['fallback'];
		}
		$placement['limit'] = max( 1, min( 24, (int) $placement['limit'] ) );
		$title              = is_scalar( $placement['title'] ) ? trim( wp_strip_all_tags( (string) $placement['title'] ) ) : '';
		$placement['title'] = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 128 ) : substr( $title, 0, 128 );

		return $placement;
	}
}
