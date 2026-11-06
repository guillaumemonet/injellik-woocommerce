<?php
/**
 * Scheduled tasks.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled tasks, run by WP-Cron or by a system cron calling the REST route:
 *
 *   curl -s "https://shop.example/wp-json/injellik/v1/cron?token=XXX"               (every 10 minutes)
 *   curl -s "https://shop.example/wp-json/injellik/v1/cron?token=XXX&task=catalog"  (full catalog, nightly)
 *
 * - events (default): sends the pending events, purges the old ones;
 * - catalog: synchronises the whole catalog.
 */
final class Injellik_Cron {

	const HOOK_EVENTS = 'injellik_cron_events';
	const HOOK_DAILY  = 'injellik_cron_daily';
	const SCHEDULE    = 'injellik_ten_minutes';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK_EVENTS, array( __CLASS__, 'run_events' ) );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'run_daily' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 10 minutes (Injellik)', 'injellik-recommendations' ),
		);

		return $schedules;
	}

	public static function schedule() {
		// At activation, the plugin has not been started yet.
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		}
		if ( ! wp_next_scheduled( self::HOOK_EVENTS ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK_EVENTS );
		}
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			// 02:30 in the timezone of the site.
			$next = new DateTime( 'tomorrow 02:30', wp_timezone() );
			wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK_DAILY );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_EVENTS );
		wp_clear_scheduled_hook( self::HOOK_DAILY );
	}

	/**
	 * @return array{events?: array, purged?: int}
	 */
	public static function run_events() {
		$report = array();
		if ( Injellik_Config::is_configured() ) {
			Injellik_Api_Client::instance()->set_timeout( 20 );
			$report['events'] = Injellik_Event_Queue::flush( 2000 );
		}
		$report['purged'] = Injellik_Event_Queue::purge( 30 );
		Injellik_Config::set_state( array( 'last_cron' => current_time( 'mysql' ) ) );

		return $report;
	}

	public static function run_daily() {
		if ( ! Injellik_Config::get( 'nightly_catalog' ) ) {
			return null;
		}

		return self::run_catalog();
	}

	/**
	 * @return array{products: int}|null null while the API refuses the shop
	 * @throws Injellik_Api_Exception When the API fails.
	 */
	public static function run_catalog() {
		if ( ! Injellik_Config::is_configured() || Injellik_Config::is_api_blocked() ) {
			return null;
		}
		Injellik_Api_Client::instance()->set_timeout( 20 );
		try {
			return array( 'products' => Injellik_Sync::sync_catalog() );
		} catch ( Injellik_Api_Exception $e ) {
			if ( ! $e->is_access_denied() ) {
				Injellik_Config::record_error( $e->getMessage() );
			}
			throw $e;
		}
	}

	public static function register_route() {
		register_rest_route(
			'injellik/v1',
			'/cron',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'rest_cron' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
					'task'  => array(
						'type' => 'string',
						'enum' => array( 'events', 'catalog' ),
					),
				),
			)
		);
	}

	public static function check_token( WP_REST_Request $request ) {
		$expected = (string) Injellik_Config::get( 'cron_token' );

		return '' !== $expected && hash_equals( $expected, (string) $request->get_param( 'token' ) )
			? true
			: new WP_Error( 'injellik_invalid_token', 'Invalid token', array( 'status' => 403 ) );
	}

	public static function rest_cron( WP_REST_Request $request ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$status = 200;
		$report = array();
		try {
			if ( 'catalog' === $request->get_param( 'task' ) ) {
				$report['catalog'] = self::run_catalog();
				Injellik_Config::set_state( array( 'last_cron' => current_time( 'mysql' ) ) );
			} else {
				$report = self::run_events();
			}
		} catch ( Injellik_Api_Exception $e ) {
			if ( ! $e->is_access_denied() ) {
				$status          = 502;
				$report['error'] = $e->getMessage();
			}
		}
		// "suspended" or "invalid_key": the API refuses the shop, the events are kept until it accepts it again.
		$state = Injellik_Config::api_state();
		if ( $state ) {
			$report['api'] = $state['reason'];
		}

		$response = new WP_REST_Response( $report, $status );
		$response->header( 'X-Robots-Tag', 'noindex' );

		return $response;
	}

	public static function url( $task = null ) {
		$args = array( 'token' => Injellik_Config::cron_token() );
		if ( $task ) {
			$args['task'] = $task;
		}

		return add_query_arg( $args, rest_url( 'injellik/v1/cron' ) );
	}
}
