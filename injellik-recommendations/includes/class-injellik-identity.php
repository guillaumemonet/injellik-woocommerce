<?php
/**
 * Visitor identity.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Visitor identity sent with every action.
 *
 * - user_uuid: derived from the customer id when logged in (stable across devices and consistent
 *   with the order history import), otherwise a random id kept in the "injellik_vid" cookie.
 * - user_session_uuid: a browsing session ("injellik_sid" cookie), renewed after 30 minutes of
 *   inactivity and after each order. The API builds its "baskets" from sessions.
 *
 * No personal data leaves the shop: customer ids and e-mails are hashed with a secret of the site.
 */
final class Injellik_Identity {

	const COOKIE_VISITOR = 'injellik_vid';
	const COOKIE_SESSION = 'injellik_sid';
	const SESSION_IDLE   = 1800;

	public static function user_uuid() {
		$id = get_current_user_id();
		if ( $id ) {
			return self::customer_uuid( $id );
		}

		$visitor = self::cookie( self::COOKIE_VISITOR );
		if ( ! preg_match( '/^v-[0-9a-f-]{36}$/', $visitor ) ) {
			$visitor = 'v-' . wp_generate_uuid4();
			self::set_cookie( self::COOKIE_VISITOR, $visitor, time() + YEAR_IN_SECONDS );
		}

		return $visitor;
	}

	/**
	 * Stable pseudonymous id of a customer account.
	 */
	public static function customer_uuid( $customer_id ) {
		return 'c-' . substr( hash_hmac( 'sha256', 'customer|' . (int) $customer_id, self::secret() ), 0, 32 );
	}

	/**
	 * Stable pseudonymous id of a guest customer, from the e-mail address of the order.
	 */
	public static function guest_uuid( $email ) {
		return 'g-' . substr( hash_hmac( 'sha256', 'guest|' . strtolower( trim( (string) $email ) ), self::secret() ), 0, 32 );
	}

	/**
	 * Identity of the customer of an order (history import, orders validated outside of a visit).
	 */
	public static function order_customer_uuid( WC_Order $order ) {
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id ) {
			return self::customer_uuid( $customer_id );
		}
		$email = (string) $order->get_billing_email();

		return '' !== $email ? self::guest_uuid( $email ) : 'order-' . $order->get_id();
	}

	public static function session_uuid() {
		list( $session, $seen ) = self::read_session();
		if ( '' === $session || $seen < time() - self::SESSION_IDLE ) {
			$session = 's-' . wp_generate_uuid4();
			self::write_session( $session );
		} elseif ( $seen < time() - 60 ) {
			self::write_session( $session );
		}

		return $session;
	}

	/**
	 * True when the current request comes from a visitor whose browsing session is known
	 * (false for a payment notification sent by a server, a back-office order...).
	 */
	public static function has_session() {
		list( $session, $seen ) = self::read_session();

		return '' !== $session && $seen >= time() - self::SESSION_IDLE;
	}

	/**
	 * Creates the identifiers and extends the session. Call it before any output so that the cookies can be sent.
	 */
	public static function touch() {
		self::user_uuid();
		self::session_uuid();
	}

	/**
	 * Starts a new session (after an order: the next purchases belong to another basket).
	 */
	public static function reset_session() {
		self::write_session( 's-' . wp_generate_uuid4() );
	}

	public static function is_bot() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $user_agent ) {
			return true;
		}

		return (bool) preg_match( '/bot|crawl|spider|slurp|archiver|facebookexternalhit|embedly|preview|lighthouse|pagespeed|headless|phantomjs|curl|wget|python-requests|httpclient/i', $user_agent );
	}

	/**
	 * @return array{0: string, 1: int} session id and last activity
	 */
	private static function read_session() {
		$raw = self::cookie( self::COOKIE_SESSION );
		if ( preg_match( '/^(s-[0-9a-f-]{36})\.(\d+)$/', $raw, $match ) ) {
			return array( $match[1], (int) $match[2] );
		}

		return array( '', 0 );
	}

	private static function write_session( $session ) {
		// Session cookie: the browser forgets it when it closes, the timestamp handles the inactivity.
		self::set_cookie( self::COOKIE_SESSION, $session . '.' . time(), 0 );
	}

	private static function cookie( $name ) {
		return isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
	}

	private static function set_cookie( $name, $value, $expires ) {
		$_COOKIE[ $name ] = $value;
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => (int) $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private static function secret() {
		return wp_salt( 'auth' ) . '|injellik';
	}
}
