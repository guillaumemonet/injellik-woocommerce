<?php
/**
 * Back office.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Injellik" menu: onboarding wizard (until it is completed), settings and dashboard.
 */
final class Injellik_Admin {

	const CAPABILITY    = 'manage_woocommerce';
	const PAGE_DASHBOARD = 'injellik';
	const PAGE_SETTINGS  = 'injellik-settings';
	const STEPS          = array( 'welcome', 'connection', 'catalog', 'history', 'placements', 'done' );
	const MODE_LICENCE   = 'licence';
	const MODE_LOGIN     = 'login';
	const NONCE          = 'injellik_admin';

	/** Colors of the action types on the dashboard chart. */
	const ACTION_COLORS = array(
		'VIEW'    => '#2271b1',
		'CLICK'   => '#8c5fd6',
		'ADDCART' => '#dba617',
		'BUY'     => '#00a32a',
		'RATED'   => '#d63638',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_injellik_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( INJELLIK_FILE ), array( __CLASS__, 'action_links' ) );
		foreach ( array( 'test_connection', 'sync_catalog', 'import_orders', 'flush_queue' ) as $action ) {
			add_action( 'wp_ajax_injellik_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	public static function menu() {
		add_menu_page(
			__( 'Injellik', 'injellik-recommendations' ),
			__( 'Injellik', 'injellik-recommendations' ),
			self::CAPABILITY,
			self::PAGE_DASHBOARD,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-area',
			'55.7'
		);
		$dashboard = add_submenu_page( self::PAGE_DASHBOARD, __( 'Injellik dashboard', 'injellik-recommendations' ), __( 'Dashboard', 'injellik-recommendations' ), self::CAPABILITY, self::PAGE_DASHBOARD, array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( self::PAGE_DASHBOARD, __( 'Injellik settings', 'injellik-recommendations' ), __( 'Settings', 'injellik-recommendations' ), self::CAPABILITY, self::PAGE_SETTINGS, array( __CLASS__, 'render_settings' ) );

		add_action( 'load-' . $dashboard, array( __CLASS__, 'load_dashboard' ) );
	}

	/**
	 * The dashboard needs a completed onboarding.
	 */
	public static function load_dashboard() {
		if ( ! Injellik_Config::is_onboarded() ) {
			wp_safe_redirect( self::settings_url() );
			exit;
		}
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'injellik-recommendations' ) . '</a>' );

		return $links;
	}

	public static function assets( $hook ) {
		if ( ! self::is_plugin_page() ) {
			return;
		}
		wp_enqueue_style( 'injellik-admin', INJELLIK_URL . 'assets/css/admin.css', array(), INJELLIK_VERSION );
		wp_enqueue_script( 'injellik-admin', INJELLIK_URL . 'assets/js/admin.js', array(), INJELLIK_VERSION, true );
		wp_localize_script(
			'injellik-admin',
			'injellikAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'copied'  => __( 'Copied', 'injellik-recommendations' ),
			)
		);
	}

	private static function is_plugin_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $page, array( self::PAGE_DASHBOARD, self::PAGE_SETTINGS ), true );
	}

	/* ------------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------------ */

	public static function settings_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) );
	}

	public static function dashboard_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_DASHBOARD ) );
	}

	public static function step_url( $step ) {
		return self::settings_url(
			array(
				'onboarding' => 1,
				'step'       => $step,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	/**
	 * Discreet notices on the other pages of the back office: the API refuses the shop,
	 * or the plugin is not set up yet (the pages of the plugin display detailed ones).
	 */
	public static function notices() {
		if ( ! current_user_can( self::CAPABILITY ) || self::is_plugin_page() ) {
			return;
		}
		$state = Injellik_Config::api_state();
		if ( $state ) {
			self::view(
				'api-state',
				array(
					'api_state' => $state,
					'compact'   => true,
				)
			);

			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! Injellik_Config::is_onboarded() && $screen && in_array( $screen->id, array( 'plugins', 'dashboard', 'woocommerce_page_wc-settings' ), true ) ) {
			printf(
				'<div class="notice notice-info"><p><strong>%s</strong> %s <a href="%s" class="button button-primary">%s</a></p></div>',
				esc_html__( 'Injellik Recommendations is installed.', 'injellik-recommendations' ),
				esc_html__( 'Connect your shop to start recommending products.', 'injellik-recommendations' ),
				esc_url( self::settings_url() ),
				esc_html__( 'Start the setup', 'injellik-recommendations' )
			);
		}
	}

	/**
	 * Errors of the last form, kept for the next page.
	 *
	 * @param string[] $errors Messages.
	 * @param array    $posted Posted values displayed again.
	 */
	private static function flash( array $errors, array $posted = array() ) {
		set_transient(
			'injellik_flash_' . get_current_user_id(),
			array(
				'errors' => $errors,
				'posted' => $posted,
			),
			300
		);
	}

	private static function take_flash() {
		$key   = 'injellik_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		delete_transient( $key );

		return is_array( $flash ) ? $flash + array(
			'errors' => array(),
			'posted' => array(),
		) : array(
			'errors' => array(),
			'posted' => array(),
		);
	}

	/* ------------------------------------------------------------------
	 * Form submissions (admin-post.php?action=injellik_save)
	 * ------------------------------------------------------------------ */

	public static function save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change the Injellik settings.', 'injellik-recommendations' ), 403 );
		}
		check_admin_referer( 'injellik_save' );
		$section    = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		$onboarding = ! empty( $_POST['onboarding'] );

		switch ( $section ) {
			case 'connection':
				$redirect = self::save_connection( $onboarding );
				break;
			case 'placements':
				$redirect = self::save_placements( $onboarding );
				break;
			case 'advanced':
				$redirect = self::save_advanced();
				break;
			case 'regenerate_token':
				Injellik_Config::set( array( 'cron_token' => wp_generate_password( 32, false ) ) );
				$redirect = self::settings_url( array( 'updated' => 1 ) ) . '#data';
				break;
			case 'reset_history':
				Injellik_Config::set_state(
					array(
						'import_last_order'   => 0,
						'import_orders_count' => 0,
						'import_orders_at'    => null,
					)
				);
				$redirect = self::settings_url( array( 'updated' => 1 ) ) . '#data';
				break;
			case 'finish_onboarding':
				Injellik_Config::set( array( 'onboarded' => true ) );
				$redirect = self::step_url( 'done' );
				break;
			case 'retry_errors':
				Injellik_Event_Queue::retry_errors();
				$redirect = self::dashboard_url( array( 'updated' => 1 ) );
				break;
			case 'clear_error':
				Injellik_Config::set_state( array( 'last_error' => null ) );
				$redirect = self::dashboard_url( array( 'updated' => 1 ) );
				break;
			default:
				$redirect = self::settings_url();
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	private static function save_connection( $onboarding ) {
		$credentials = self::posted_credentials();
		$errors      = self::validate_credentials( $credentials );
		$back        = $onboarding ? self::step_url( 'connection' ) : self::settings_url() . '#connection';
		$posted      = array(
			'auth_mode'   => $credentials['mode'],
			'api_url'     => $credentials['url'],
			'api_login'   => $credentials['login'],
			'licence_key' => isset( $_POST['licence_key'] ) ? sanitize_text_field( wp_unslash( $_POST['licence_key'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in save().
		);
		if ( $errors ) {
			self::flash( $errors, $posted );

			return $back;
		}

		if ( self::MODE_LICENCE === $credentials['mode'] ) {
			Injellik_Config::set( array( 'licence_key' => $credentials['licence_key'] ) );
		} else {
			// Without a licence key, the login and the password are used again.
			Injellik_Config::set(
				array(
					'licence_key'  => '',
					'api_url'      => $credentials['url'],
					'api_login'    => $credentials['login'],
					'api_password' => $credentials['password'],
				)
			);
		}
		Injellik_Config::set_state(
			array(
				'jwt'         => null,
				'jwt_expires' => null,
			)
		);
		Injellik_Api_Client::reset();
		Injellik_Cache::clear();

		// Saving the connection is also a new attempt for a shop the API refused.
		$test = self::test_connection( $credentials );
		if ( $test['ok'] ) {
			Injellik_Config::clear_api_state();
		} elseif ( $test['state'] ) {
			Injellik_Config::set_api_state( $test['state'] );
		} else {
			Injellik_Config::allow_api_retry();
		}
		if ( ! $test['ok'] ) {
			self::flash( array( $test['message'] ), $posted );

			return $back;
		}

		return $onboarding ? self::step_url( 'catalog' ) : self::settings_url( array( 'updated' => 1 ) ) . '#connection';
	}

	/**
	 * Connection settings posted by the form, completed with the stored values (empty secret = unchanged).
	 *
	 * @return array{mode: string, url: string, licence_key: string, login: string, password: string}
	 */
	private static function posted_credentials() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by the callers.
		$url         = isset( $_POST['api_url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['api_url'] ) ) ) : '';
		$licence_key = isset( $_POST['licence_key'] ) ? Injellik_Config::normalize_licence_key( sanitize_text_field( wp_unslash( $_POST['licence_key'] ) ) ) : '';
		$login       = isset( $_POST['api_login'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_login'] ) ) ) : null;
		$password    = isset( $_POST['api_password'] ) ? (string) wp_unslash( $_POST['api_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is used as typed.
		$mode        = isset( $_POST['auth_mode'] ) && self::MODE_LOGIN === $_POST['auth_mode'] ? self::MODE_LOGIN : self::MODE_LICENCE;
		// phpcs:enable

		return array(
			'mode'        => $mode,
			'url'         => Injellik_Api_Client::normalize_url( '' !== $url ? $url : Injellik_Config::api_url() ),
			'licence_key' => '' !== $licence_key ? $licence_key : (string) Injellik_Config::get( 'licence_key' ),
			'login'       => null !== $login ? $login : (string) Injellik_Config::get( 'api_login' ),
			'password'    => '' !== $password ? $password : (string) Injellik_Config::get( 'api_password' ),
		);
	}

	/**
	 * @return string[] error messages
	 */
	private static function validate_credentials( array $credentials ) {
		$errors = array();
		if ( ! wp_http_validate_url( $credentials['url'] ) && ! preg_match( '#^https?://[^/\s]+#i', $credentials['url'] ) ) {
			$errors[] = __( 'The API URL is not valid.', 'injellik-recommendations' );
		}
		if ( self::MODE_LICENCE === $credentials['mode'] ) {
			if ( '' === $credentials['licence_key'] ) {
				$errors[] = __( 'The licence key is required.', 'injellik-recommendations' );
			} elseif ( ! Injellik_Config::is_valid_licence_key( $credentials['licence_key'] ) ) {
				$errors[] = __( 'This licence key is not valid: it starts with "ijk_lic_", followed by 40 characters (digits and letters a to f). Copy it again from your web-nest.fr account.', 'injellik-recommendations' );
			}
		} elseif ( '' === $credentials['login'] || '' === $credentials['password'] ) {
			$errors[] = __( 'The login and the password are required.', 'injellik-recommendations' );
		}

		return $errors;
	}

	/**
	 * Checks that the API answers and accepts the credentials, with a light authenticated request.
	 *
	 * The "state" of the result is the API state to record (suspended subscription, invalid licence key).
	 *
	 * @return array{ok: bool, message: string, state: string|null}
	 */
	private static function test_connection( array $credentials ) {
		$client = new Injellik_Api_Client(
			$credentials['url'],
			$credentials['login'],
			$credentials['password'],
			10,
			false,
			self::MODE_LICENCE === $credentials['mode'] ? $credentials['licence_key'] : ''
		);
		try {
			$client->status();
		} catch ( Injellik_Api_Exception $e ) {
			return array(
				'ok'      => false,
				'state'   => null,
				/* translators: %s: error message */
				'message' => sprintf( __( 'The API cannot be reached: %s', 'injellik-recommendations' ), $e->getMessage() ),
			);
		}
		try {
			if ( ! $client->uses_licence_key() ) {
				$client->authenticate();
			}
			try {
				$client->get( '/api/usage' );
			} catch ( Injellik_Api_Exception $e ) {
				if ( 404 !== $e->get_status() ) {
					throw $e;
				}
				// API without the usage route: any other authenticated route proves the credentials.
				$client->get( '/api/actiontypes' );
			}
		} catch ( Injellik_Api_Exception $e ) {
			if ( $e->is_suspended() || $e->is_invalid_key() ) {
				return array(
					'ok'      => false,
					'state'   => $e->is_suspended() ? Injellik_Config::STATE_SUSPENDED : Injellik_Config::STATE_INVALID_KEY,
					'message' => Injellik_Config::api_error_message( $e ),
				);
			}

			return array(
				'ok'      => false,
				'state'   => null,
				/* translators: %s: error message */
				'message' => 401 === $e->get_status() ? __( 'The API rejected the login or the password.', 'injellik-recommendations' ) : sprintf( __( 'Authentication failed: %s', 'injellik-recommendations' ), $e->getMessage() ),
			);
		}

		return array(
			'ok'      => true,
			'state'   => null,
			'message' => __( 'Connection successful.', 'injellik-recommendations' ),
		);
	}

	private static function save_placements( $onboarding ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in save(), sanitized by Injellik_Config::save_placements().
		$input      = isset( $_POST['placements'] ) && is_array( $_POST['placements'] ) ? wp_unslash( $_POST['placements'] ) : array();
		$placements = array();
		foreach ( Injellik_Config::default_placements() as $key => $default ) {
			$row                = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$placements[ $key ] = array(
				'enabled'  => ! empty( $row['enabled'] ),
				'strategy' => isset( $row['strategy'] ) ? sanitize_key( $row['strategy'] ) : $default['strategy'],
				'fallback' => isset( $row['fallback'] ) ? sanitize_key( $row['fallback'] ) : $default['fallback'],
				'limit'    => isset( $row['limit'] ) ? (int) $row['limit'] : $default['limit'],
				'title'    => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
			);
		}
		Injellik_Config::save_placements( $placements );
		Injellik_Cache::clear();
		Injellik_Config::allow_api_retry();

		if ( $onboarding ) {
			Injellik_Config::set( array( 'onboarded' => true ) );

			return self::step_url( 'done' );
		}

		return self::settings_url( array( 'updated' => 1 ) ) . '#placements';
	}

	private static function save_advanced() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in save().
		$url = isset( $_POST['api_url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['api_url'] ) ) ) : '';
		$url = Injellik_Api_Client::normalize_url( '' !== $url ? $url : Injellik_Config::DEFAULT_API_URL );
		if ( ! preg_match( '#^https?://[^/\s]+#i', $url ) ) {
			self::flash( array( __( 'The API URL is not valid.', 'injellik-recommendations' ) ) );

			return self::settings_url() . '#advanced';
		}
		$values = array(
			'render_mode'       => isset( $_POST['render_mode'] ) && Injellik_Config::RENDER_SERVER === $_POST['render_mode'] ? Injellik_Config::RENDER_SERVER : Injellik_Config::RENDER_AJAX,
			'send_mode'         => isset( $_POST['send_mode'] ) && Injellik_Config::SEND_CRON === $_POST['send_mode'] ? Injellik_Config::SEND_CRON : Injellik_Config::SEND_REALTIME,
			'timeout'           => max( 1, min( 10, isset( $_POST['timeout'] ) ? (int) $_POST['timeout'] : 3 ) ),
			'cache_ttl'         => max( 0, min( 86400, isset( $_POST['cache_ttl'] ) ? (int) $_POST['cache_ttl'] : 900 ) ),
			'columns'           => max( 1, min( 6, isset( $_POST['columns'] ) ? (int) $_POST['columns'] : 4 ) ),
			'hide_out_of_stock' => ! empty( $_POST['hide_out_of_stock'] ),
			'exclude_bots'      => ! empty( $_POST['exclude_bots'] ),
			'auto_sync'         => ! empty( $_POST['auto_sync'] ),
			'nightly_catalog'   => ! empty( $_POST['nightly_catalog'] ),
		);
		// phpcs:enable
		if ( Injellik_Api_Client::normalize_url( Injellik_Config::api_url() ) !== $url ) {
			$values['api_url'] = $url;
			Injellik_Config::set_state(
				array(
					'jwt'         => null,
					'jwt_expires' => null,
				)
			);
		}
		Injellik_Config::set( $values );
		Injellik_Api_Client::reset();
		Injellik_Cache::clear();
		// Saving the settings is also a new attempt for a shop the API refused.
		Injellik_Config::allow_api_retry();

		return self::settings_url( array( 'updated' => 1 ) ) . '#advanced';
	}

	/* ------------------------------------------------------------------
	 * AJAX actions (assets/js/admin.js)
	 * ------------------------------------------------------------------ */

	private static function check_ajax() {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json(
				array(
					'ok'      => false,
					'message' => __( 'You are not allowed to do this.', 'injellik-recommendations' ),
				),
				403
			);
		}
		// Back-office jobs are not on the visitor path: they can wait longer than the front timeout.
		Injellik_Api_Client::instance()->set_timeout( 20 );
	}

	public static function ajax_test_connection() {
		self::check_ajax();
		$credentials = self::posted_credentials();
		$errors      = self::validate_credentials( $credentials );
		if ( $errors ) {
			wp_send_json(
				array(
					'ok'      => false,
					'message' => implode( ' ', $errors ),
				)
			);
		}
		wp_send_json( self::test_connection( $credentials ) );
	}

	public static function ajax_sync_catalog() {
		self::check_ajax();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check_ajax().
		$after_id = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
		$done     = isset( $_POST['done_count'] ) ? absint( $_POST['done_count'] ) : 0;
		// phpcs:enable
		$total = Injellik_Sync::count_products();

		try {
			$batch = Injellik_Sync::sync_catalog_batch( $after_id );
		} catch ( Injellik_Api_Exception $e ) {
			self::job_failed( $e );
		}

		$done += $batch['processed'];
		if ( $batch['done'] ) {
			Injellik_Sync::catalog_synced( $done );
		}

		wp_send_json(
			array(
				'ok'         => true,
				'done'       => $batch['done'],
				'after_id'   => $batch['last_id'],
				'done_count' => $done,
				'total'      => $total,
				'message'    => $batch['done']
					/* translators: %d: number of products */
					? sprintf( _n( '%d product synchronised.', '%d products synchronised.', $done, 'injellik-recommendations' ), $done )
					/* translators: 1: products done, 2: total */
					: sprintf( __( '%1$d / %2$d products', 'injellik-recommendations' ), $done, $total ),
			)
		);
	}

	public static function ajax_import_orders() {
		self::check_ajax();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check_ajax().
		$since      = isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '';
		$done_count = isset( $_POST['done_count'] ) ? absint( $_POST['done_count'] ) : 0;
		// phpcs:enable
		$since    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ? $since : null;
		$after_id = (int) Injellik_Config::state( 'import_last_order', 0 );
		$total    = $done_count + Injellik_Sync::count_orders( $after_id, $since );

		$batch = Injellik_Sync::import_orders_batch( $after_id, $since );

		$done_count += $batch['processed'];
		Injellik_Config::set_state(
			array(
				'import_last_order'   => $batch['last_id'],
				'import_orders_count' => (int) Injellik_Config::state( 'import_orders_count', 0 ) + $batch['processed'],
			)
		);
		if ( $batch['error'] ) {
			self::job_failed( $batch['error'] );
		}
		if ( $batch['done'] ) {
			Injellik_Config::set_state( array( 'import_orders_at' => current_time( 'mysql' ) ) );
		}

		wp_send_json(
			array(
				'ok'         => true,
				'done'       => $batch['done'],
				'done_count' => $done_count,
				'total'      => max( $total, $done_count ),
				'message'    => $batch['done']
					/* translators: %d: number of orders */
					? sprintf( _n( '%d order imported.', '%d orders imported.', $done_count, 'injellik-recommendations' ), $done_count )
					/* translators: 1: orders done, 2: total */
					: sprintf( __( '%1$d / %2$d orders', 'injellik-recommendations' ), $done_count, $total ),
			)
		);
	}

	public static function ajax_flush_queue() {
		self::check_ajax();
		$result = Injellik_Event_Queue::flush( 500 );
		wp_send_json(
			array(
				'ok'         => true,
				'done'       => 0 === $result['pending'] || 0 === $result['sent'],
				'done_count' => $result['sent'],
				'total'      => $result['sent'] + $result['pending'],
				/* translators: 1: sent events, 2: failed events, 3: pending events */
				'message'    => sprintf( __( '%1$d sent, %2$d failed, %3$d pending', 'injellik-recommendations' ), $result['sent'], $result['failed'], $result['pending'] ),
			)
		);
	}

	/**
	 * Ends a batched job with the error of the API (the refusals of the API are shown by the page banner).
	 */
	private static function job_failed( Injellik_Api_Exception $e ) {
		if ( ! $e->is_access_denied() ) {
			Injellik_Config::record_error( $e->getMessage() );
		}
		wp_send_json(
			array(
				'ok'      => false,
				'message' => Injellik_Config::api_error_message( $e ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------ */

	public static function render_settings() {
		$onboarding = ! empty( $_GET['onboarding'] ) || ! Injellik_Config::is_onboarded(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$vars       = self::common_variables();

		if ( $onboarding ) {
			$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'welcome'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$step = in_array( $step, self::STEPS, true ) ? $step : 'welcome';
			// The steps after the connection need working credentials.
			if ( ! Injellik_Config::is_configured() && ! in_array( $step, array( 'welcome', 'connection' ), true ) ) {
				$step = 'connection';
			}
			self::view(
				'onboarding',
				$vars + array(
					'step'               => $step,
					'steps'              => self::step_labels(),
					'step_index'         => array_search( $step, self::STEPS, true ),
					'step_urls'          => array_combine( self::STEPS, array_map( array( __CLASS__, 'step_url' ), self::STEPS ) ),
					'product_count'      => Injellik_Sync::count_products(),
					'order_count'        => Injellik_Sync::count_orders(),
					'history_since'      => gmdate( 'Y-m-d', strtotime( '-1 year' ) ),
					'catalog_synced_at'  => Injellik_Config::state( 'catalog_synced_at' ),
					'orders_imported_at' => Injellik_Config::state( 'import_orders_at' ),
				)
			);

			return;
		}

		self::view(
			'settings',
			$vars + array(
				'catalog_synced_at'  => Injellik_Config::state( 'catalog_synced_at' ),
				'catalog_count'      => (int) Injellik_Config::state( 'catalog_count', 0 ),
				'product_count'      => Injellik_Sync::count_products(),
				'orders_imported_at' => Injellik_Config::state( 'import_orders_at' ),
				'orders_imported'    => (int) Injellik_Config::state( 'import_orders_count', 0 ),
				'orders_remaining'   => Injellik_Sync::count_orders( (int) Injellik_Config::state( 'import_last_order', 0 ) ),
				'history_since'      => gmdate( 'Y-m-d', strtotime( '-1 year' ) ),
				'cron_url'           => Injellik_Cron::url(),
				'cron_catalog_url'   => Injellik_Cron::url( 'catalog' ),
				'last_cron'          => Injellik_Config::state( 'last_cron' ),
				'wp_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'next_cron'          => wp_next_scheduled( Injellik_Cron::HOOK_EVENTS ),
				'settings'           => Injellik_Config::all(),
				'onboarding_url'     => self::step_url( 'welcome' ),
			)
		);
	}

	public static function render_dashboard() {
		Injellik_Api_Client::instance()->set_timeout( 10 );
		$days = 30;

		self::view(
			'dashboard',
			self::common_variables() + array(
				'days'      => $days,
				'api'       => self::api_health(),
				'last_error' => Injellik_Config::last_error(),
				'queue'     => Injellik_Stats::queue_summary(),
				'send_mode' => Injellik_Config::get( 'send_mode' ),
				'last_cron' => Injellik_Config::state( 'last_cron' ),
				'catalog'   => array(
					'synced_at' => Injellik_Config::state( 'catalog_synced_at' ),
					'count'     => (int) Injellik_Config::state( 'catalog_count', 0 ),
					'products'  => Injellik_Sync::count_products(),
				),
				'orders'    => array(
					'imported_at' => Injellik_Config::state( 'import_orders_at' ),
					'count'       => (int) Injellik_Config::state( 'import_orders_count', 0 ),
				),
				'chart'     => self::chart( Injellik_Stats::events_per_day( $days ) ),
				'colors'    => self::ACTION_COLORS,
				'blocks'    => self::blocks( Injellik_Stats::by_placement( $days ) ),
				'rankings'  => self::rankings(),
				'problems'  => Injellik_Stats::recent_events( 10, true ),
				'recent'    => Injellik_Stats::recent_events( 15 ),
			)
		);
	}

	private static function common_variables() {
		$flash = self::take_flash();

		return array(
			'version'         => INJELLIK_VERSION,
			'logo'            => INJELLIK_URL . 'assets/images/logo.png',
			'settings_url'    => self::settings_url(),
			'dashboard_url'   => self::dashboard_url(),
			'form_url'        => admin_url( 'admin-post.php' ),
			'links'           => Injellik_Config::links(),
			'api_state'       => Injellik_Config::api_state(),
			'errors'          => $flash['errors'],
			'posted'          => $flash['posted'],
			'updated'         => ! empty( $_GET['updated'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'connection'      => array(
				'mode'             => self::connection_mode( $flash['posted'] ),
				'url'              => Injellik_Api_Client::normalize_url( Injellik_Config::api_url() ),
				'default_url'      => Injellik_Config::DEFAULT_API_URL,
				'licence_key_hint' => self::mask_licence_key( (string) Injellik_Config::get( 'licence_key' ) ),
				'login'            => (string) Injellik_Config::get( 'api_login' ),
				'has_password'     => '' !== (string) Injellik_Config::get( 'api_password' ),
				'configured'       => Injellik_Config::is_configured(),
			),
			'placements'      => self::placements_for_form(),
			'strategies'      => Injellik_Config::strategy_labels(),
		);
	}

	/**
	 * Form displayed first: the one posted, the licence key, or the login of an account created before the subscriptions.
	 */
	private static function connection_mode( array $posted ) {
		if ( isset( $posted['auth_mode'] ) && in_array( $posted['auth_mode'], array( self::MODE_LOGIN, self::MODE_LICENCE ), true ) ) {
			return $posted['auth_mode'];
		}

		return ! Injellik_Config::uses_licence_key() && '' !== (string) Injellik_Config::get( 'api_login' ) ? self::MODE_LOGIN : self::MODE_LICENCE;
	}

	/**
	 * "ijk_lic_••••••••3f9a": enough to recognise the stored key without displaying it.
	 */
	private static function mask_licence_key( $key ) {
		return '' === $key ? '' : 'ijk_lic_' . str_repeat( "\u{2022}", 8 ) . substr( $key, -4 );
	}

	private static function step_labels() {
		return array(
			'welcome'    => __( 'Welcome', 'injellik-recommendations' ),
			'connection' => __( 'Connection', 'injellik-recommendations' ),
			'catalog'    => __( 'Catalog', 'injellik-recommendations' ),
			'history'    => __( 'Order history', 'injellik-recommendations' ),
			'placements' => __( 'Blocks', 'injellik-recommendations' ),
			'done'       => __( 'Done', 'injellik-recommendations' ),
		);
	}

	public static function placement_labels() {
		return array(
			'product'            => array( __( 'Product page', 'injellik-recommendations' ), __( 'Below the product summary, after the tabs.', 'injellik-recommendations' ) ),
			'cart'               => array( __( 'Cart', 'injellik-recommendations' ), __( 'Below the cart, computed from the products in the cart (classic cart and cart block).', 'injellik-recommendations' ) ),
			'order_confirmation' => array( __( 'Order confirmation', 'injellik-recommendations' ), __( 'On the "thank you" page, computed from the products ordered.', 'injellik-recommendations' ) ),
			'category'           => array( __( 'Shop and category pages', 'injellik-recommendations' ), __( 'Below the product list of the shop, categories and tags.', 'injellik-recommendations' ) ),
			'home'               => array( __( 'Home page', 'injellik-recommendations' ), __( 'Personalized selection, inserted with the block or the shortcode placement="home".', 'injellik-recommendations' ) ),
			'custom'             => array( __( 'Block and shortcode', 'injellik-recommendations' ), __( 'Settings of the Injellik blocks and shortcodes that do not name a placement.', 'injellik-recommendations' ) ),
		);
	}

	private static function placements_for_form() {
		$labels     = self::placement_labels();
		$placements = array();
		foreach ( Injellik_Config::placements() as $key => $placement ) {
			$placement['key']           = $key;
			$placement['label']         = $labels[ $key ][0];
			$placement['help']          = $labels[ $key ][1];
			$placement['automatic']     = null !== $placement['hook'];
			$placement['default_title'] = Injellik_Recommender::default_title( $placement['strategy'] );
			$placements[ $key ]         = $placement;
		}

		return $placements;
	}

	private static function api_health() {
		if ( ! Injellik_Config::is_configured() ) {
			return array(
				'ok'       => false,
				'database' => null,
				'latency'  => null,
				'message'  => __( 'Not configured.', 'injellik-recommendations' ),
			);
		}
		$client = Injellik_Api_Client::instance();
		$start  = microtime( true );
		try {
			$status  = $client->status();
			$latency = (int) round( ( microtime( true ) - $start ) * 1000 );
			$client->get( '/api/actiontypes' );

			return array(
				'ok'       => true,
				'database' => isset( $status['database'] ) ? $status['database'] : null,
				'latency'  => $latency,
				'message'  => '',
			);
		} catch ( Injellik_Api_Exception $e ) {
			return array(
				'ok'       => false,
				'database' => null,
				'latency'  => null,
				'message'  => Injellik_Config::api_error_message( $e ),
			);
		}
	}

	/**
	 * Stacked bars of the events per day, scaled on the busiest day.
	 */
	private static function chart( array $series ) {
		$max    = 1;
		$totals = array();
		foreach ( $series as $day => $counts ) {
			$totals[ $day ] = array_sum( $counts );
			$max            = max( $max, $totals[ $day ] );
		}

		$bars = array();
		foreach ( $series as $day => $counts ) {
			$segments = array();
			foreach ( self::ACTION_COLORS as $type => $color ) {
				if ( ! empty( $counts[ $type ] ) ) {
					$segments[] = array(
						'type'   => $type,
						'count'  => $counts[ $type ],
						'color'  => $color,
						'height' => round( 100 * $counts[ $type ] / $max, 2 ),
					);
				}
			}
			foreach ( $counts as $type => $count ) {
				if ( ! isset( self::ACTION_COLORS[ $type ] ) ) {
					$segments[] = array(
						'type'   => $type,
						'count'  => $count,
						'color'  => '#a7aaad',
						'height' => round( 100 * $count / $max, 2 ),
					);
				}
			}
			$bars[] = array(
				'day'      => $day,
				'label'    => date_i18n( get_option( 'date_format' ), strtotime( $day ) ),
				'total'    => $totals[ $day ],
				'segments' => $segments,
			);
		}

		$by_type = array();
		foreach ( $series as $counts ) {
			foreach ( $counts as $type => $count ) {
				$by_type[ $type ] = ( isset( $by_type[ $type ] ) ? $by_type[ $type ] : 0 ) + $count;
			}
		}
		arsort( $by_type );

		return array(
			'bars'    => $bars,
			'max'     => $max,
			'total'   => array_sum( $totals ),
			'by_type' => $by_type,
		);
	}

	private static function blocks( array $stats ) {
		$labels     = self::placement_labels();
		$strategies = Injellik_Config::strategy_labels();
		$rows       = array();
		foreach ( Injellik_Config::placements() as $key => $placement ) {
			$row = isset( $stats[ $key ] ) ? $stats[ $key ] : array(
				'impressions' => 0,
				'clicks'      => 0,
				'ctr'         => 0.0,
			);

			$row['key']      = $key;
			$row['label']    = $labels[ $key ][0];
			$row['enabled']  = $placement['enabled'];
			$row['strategy'] = isset( $strategies[ $placement['strategy'] ] ) ? $strategies[ $placement['strategy'] ]['label'] : $placement['strategy'];
			$rows[]          = $row;
		}

		return $rows;
	}

	/**
	 * Most viewed and most bought products of the last 30 days, according to the API.
	 */
	private static function rankings() {
		$result = array(
			'most_viewed_items' => array(),
			'most_bought_items' => array(),
		);
		if ( ! Injellik_Config::is_configured() || Injellik_Config::is_api_blocked() ) {
			return $result;
		}
		$client = Injellik_Api_Client::instance();
		foreach ( array(
			'most-viewed' => 'most_viewed_items',
			'most-bought' => 'most_bought_items',
		) as $endpoint => $key ) {
			try {
				$response = $client->get( '/api/community-rankings/' . $endpoint, array( 'limit' => 10 ) );
				$rows     = isset( $response[ $key ] ) && is_array( $response[ $key ] ) ? $response[ $key ] : array();
			} catch ( Injellik_Api_Exception $e ) {
				$rows = array();
			}
			foreach ( $rows as $row ) {
				$id               = isset( $row['sku'] ) && ctype_digit( (string) $row['sku'] ) ? (int) $row['sku'] : 0;
				$product          = $id ? wc_get_product( $id ) : null;
				$result[ $key ][] = array(
					'sku'   => isset( $row['sku'] ) ? (string) $row['sku'] : '',
					'label' => $product ? $product->get_name() : ( isset( $row['name'] ) ? (string) $row['name'] : '' ),
					'url'   => $product ? get_edit_post_link( $id ) : null,
					'count' => isset( $row['count'] ) ? (int) $row['count'] : ( isset( $row['score'] ) ? $row['score'] : null ),
				);
			}
		}

		return $result;
	}

	/**
	 * Renders a view of includes/admin/views with its variables.
	 *
	 * @param string $name View.
	 * @param array  $vars Variables, available as $injellik.
	 */
	public static function view( $name, array $vars = array() ) {
		$injellik = $vars + array(
			'links' => Injellik_Config::links(),
		);
		include INJELLIK_DIR . 'includes/admin/views/' . $name . '.php';
	}
}
