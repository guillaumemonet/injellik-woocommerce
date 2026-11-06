<?php
/**
 * HTTP client of the Injellik API.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client of the Injellik API (JSON in and out), built on the WordPress HTTP API.
 *
 * Two authentication modes:
 * - licence key (default): "X-Api-Key" header on every request, no login;
 * - login and password (accounts created before the subscriptions): JWT from /api/login, cached in the
 *   state option until shortly before it expires, so that a front request costs a single HTTP call.
 *
 * When the API refuses the shop (403 "tenant_suspended", or 401 with a licence key), the state is stored
 * and the client stops calling the API for Injellik_Config::STATE_RETRY_DELAY seconds.
 */
class Injellik_Api_Client {

	/** @var Injellik_Api_Client|null */
	private static $instance;

	private $base_url;
	private $login;
	private $password;
	private $licence_key;
	private $timeout;
	private $token;
	private $persist;

	/**
	 * @param string $base_url    URL of the API.
	 * @param string $login       Login (accounts created before the subscriptions).
	 * @param string $password    Password.
	 * @param int    $timeout     Seconds.
	 * @param bool   $persist     False for a client that must not touch the state (connection test): no cached JWT, no stored API state.
	 * @param string $licence_key When not empty, used instead of the login and the password.
	 */
	public function __construct( $base_url, $login, $password, $timeout = 3, $persist = true, $licence_key = '' ) {
		$this->base_url    = self::normalize_url( $base_url );
		$this->login       = (string) $login;
		$this->password    = (string) $password;
		$this->licence_key = trim( (string) $licence_key );
		$this->timeout     = max( 1, (int) $timeout );
		$this->persist     = (bool) $persist;
	}

	/**
	 * Client built from the settings.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self(
				Injellik_Config::api_url(),
				Injellik_Config::get( 'api_login' ),
				Injellik_Config::get( 'api_password' ),
				(int) Injellik_Config::get( 'timeout' ),
				true,
				Injellik_Config::get( 'licence_key' )
			);
		}

		return self::$instance;
	}

	public static function reset() {
		self::$instance = null;
	}

	/**
	 * Accepts "https://host", "https://host/" or "https://host/api".
	 */
	public static function normalize_url( $url ) {
		$url = rtrim( trim( (string) $url ), '/' );
		if ( '/api' === strtolower( substr( $url, -4 ) ) ) {
			$url = substr( $url, 0, -4 );
		}

		return $url;
	}

	public function is_configured() {
		return '' !== $this->base_url && ( $this->uses_licence_key() || ( '' !== $this->login && '' !== $this->password ) );
	}

	public function uses_licence_key() {
		return '' !== $this->licence_key;
	}

	public function set_timeout( $timeout ) {
		$this->timeout = max( 1, (int) $timeout );

		return $this;
	}

	/**
	 * Public health probe: {"status": "up", "database": "up"}.
	 */
	public function status() {
		return $this->send( 'GET', $this->base_url . '/status', array(), null );
	}

	/**
	 * Authenticates and returns a fresh JWT (login and password mode only: a licence key needs no login).
	 */
	public function authenticate() {
		if ( $this->uses_licence_key() ) {
			throw new Injellik_Api_Exception( 'A licence key does not use /api/login.', 0 );
		}
		$response = $this->send(
			'POST',
			$this->base_url . '/api/login',
			array(),
			array(
				'email'    => $this->login,
				'password' => $this->password,
			)
		);
		if ( empty( $response['token'] ) ) {
			throw new Injellik_Api_Exception( 'The API did not return a token.', 500 );
		}
		$this->token = (string) $response['token'];

		if ( $this->persist ) {
			Injellik_Config::set_state(
				array(
					'jwt'         => $this->token,
					'jwt_expires' => self::token_expiry( $this->token ),
				)
			);
		}

		return $this->token;
	}

	public function get( $path, array $query = array() ) {
		return $this->request( 'GET', $path, $query );
	}

	public function post( $path, $body ) {
		return $this->request( 'POST', $path, array(), $body );
	}

	public function delete( $path ) {
		return $this->request( 'DELETE', $path );
	}

	/**
	 * Creates or updates items: {"created": n, "updated": n}.
	 */
	public function import_items( array $items ) {
		return $this->post( '/api/items/import', array_values( $items ) );
	}

	/**
	 * Authenticated call to /api/*.
	 *
	 * Licence key: "X-Api-Key" header, a 401 means that the key is invalid or revoked (no retry).
	 * Login and password: a 401 triggers one re-authentication (expired or revoked JWT).
	 * A shop refused by the API is not called again before Injellik_Config::STATE_RETRY_DELAY seconds;
	 * the first successful call clears the state.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path, starting with /api.
	 * @param array      $query  Query string.
	 * @param mixed|null $body   JSON body.
	 * @return array
	 * @throws Injellik_Api_Exception On any failure.
	 */
	public function request( $method, $path, array $query = array(), $body = null ) {
		if ( ! $this->is_configured() ) {
			throw new Injellik_Api_Exception( 'The Injellik API is not configured.', 0 );
		}

		$url = $this->base_url . $path;
		if ( $query ) {
			$url .= '?' . http_build_query( $query, '', '&' );
		}

		$state = $this->persist ? Injellik_Config::api_state() : null;
		if ( $state ) {
			if ( Injellik_Config::is_api_blocked() ) {
				throw self::refusal( $state['reason'] );
			}
			// This request is the new attempt: the concurrent ones keep waiting.
			Injellik_Config::touch_api_state();
		}

		try {
			$data = $this->uses_licence_key() ? $this->send_with_key( $method, $url, $body ) : $this->send_with_jwt( $method, $url, $body );
		} catch ( Injellik_Api_Exception $e ) {
			if ( $this->persist && $e->is_access_denied() ) {
				Injellik_Config::set_api_state( $e->is_suspended() ? Injellik_Config::STATE_SUSPENDED : Injellik_Config::STATE_INVALID_KEY );
			}
			throw $e;
		}

		if ( $state ) {
			Injellik_Config::clear_api_state();
		}

		return $data;
	}

	private function send_with_key( $method, $url, $body ) {
		try {
			return $this->send( $method, $url, array( 'X-Api-Key' => $this->licence_key ), $body );
		} catch ( Injellik_Api_Exception $e ) {
			if ( 401 === $e->get_status() ) {
				throw new Injellik_Api_Exception( $e->getMessage(), 401, Injellik_Api_Exception::ERROR_INVALID_KEY );
			}
			throw $e;
		}
	}

	private function send_with_jwt( $method, $url, $body ) {
		$token = $this->token();
		try {
			return $this->send( $method, $url, array( 'Authorization' => 'Bearer ' . $token ), $body );
		} catch ( Injellik_Api_Exception $e ) {
			if ( 401 !== $e->get_status() ) {
				throw $e;
			}
			$this->authenticate();

			return $this->send( $method, $url, array( 'Authorization' => 'Bearer ' . $this->token ), $body );
		}
	}

	/**
	 * Exception thrown without calling the API while it refuses the shop.
	 */
	private static function refusal( $reason ) {
		return Injellik_Config::STATE_SUSPENDED === $reason
			? new Injellik_Api_Exception( Injellik_Api_Exception::ERROR_SUSPENDED, 403, Injellik_Api_Exception::ERROR_SUSPENDED )
			: new Injellik_Api_Exception( 'Invalid or revoked licence key.', 401, Injellik_Api_Exception::ERROR_INVALID_KEY );
	}

	private function token() {
		if ( null === $this->token && $this->persist && (int) Injellik_Config::state( 'jwt_expires', 0 ) > time() ) {
			$this->token = (string) Injellik_Config::state( 'jwt', '' );
		}

		return $this->token ? $this->token : $this->authenticate();
	}

	/**
	 * Expiry read from the JWT payload, one minute early; one hour when the payload is unreadable.
	 */
	private static function token_expiry( $jwt ) {
		$parts = explode( '.', $jwt );
		if ( 3 === count( $parts ) ) {
			$payload = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( isset( $payload['exp'] ) ) {
				return (int) $payload['exp'] - 60;
			}
		}

		return time() + 3600;
	}

	private function send( $method, $url, array $headers, $body ) {
		global $wp_version;

		$headers['Accept'] = 'application/json';
		$args              = array(
			'method'      => $method,
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'user-agent'  => 'InjellikWooCommerce/' . INJELLIK_VERSION . ' WooCommerce/' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' WordPress/' . $wp_version,
			'headers'     => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new Injellik_Api_Exception( sprintf( 'Injellik API unreachable (%s): %s', wp_parse_url( $url, PHP_URL_HOST ), $response->get_error_message() ), 0 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = '' === $raw ? array() : json_decode( $raw, true );

		if ( $status >= 400 ) {
			$error   = is_array( $data ) && isset( $data['error'] ) && is_scalar( $data['error'] ) ? (string) $data['error'] : null;
			$message = null !== $error ? $error : sprintf( 'HTTP %d', $status );
			if ( is_array( $data ) && ! empty( $data['violations'] ) && is_array( $data['violations'] ) ) {
				$message .= ' (' . self::violations( $data['violations'] ) . ')';
			}
			$suspended = 403 === $status && Injellik_Api_Exception::ERROR_SUSPENDED === $error;
			throw new Injellik_Api_Exception( $message, $status, $suspended ? $error : null );
		}
		if ( ! is_array( $data ) ) {
			throw new Injellik_Api_Exception( sprintf( 'Invalid JSON response (HTTP %d).', $status ), $status ? $status : 500 );
		}

		return $data;
	}

	/**
	 * "field: message; field: message".
	 */
	public static function violations( array $violations ) {
		$details = array();
		foreach ( $violations as $violation ) {
			if ( is_array( $violation ) ) {
				$details[] = ( isset( $violation['field'] ) ? $violation['field'] . ': ' : '' ) . ( isset( $violation['message'] ) ? $violation['message'] : '' );
			}
		}

		return implode( '; ', $details );
	}
}
