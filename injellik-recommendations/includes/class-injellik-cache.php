<?php
/**
 * Cache of the API responses.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cache of the API responses: the persistent object cache when there is one (Redis, Memcached),
 * transients otherwise. Rankings and item recommendations change once a night (training).
 *
 * Without a persistent object cache, the answers that depend on the visitor are not cached, so that
 * the options table does not fill up with one transient per visitor.
 *
 * Keys carry a generation number: clearing the cache increments it, the old entries expire on their own.
 */
final class Injellik_Cache {

	const GROUP      = 'injellik';
	const GENERATION = 'injellik_cache_generation';

	private static function key( $key ) {
		return 'injellik_' . md5( (int) get_option( self::GENERATION, 1 ) . '|' . $key );
	}

	/**
	 * @return mixed|null null when missing or expired
	 */
	public static function get( $key ) {
		if ( wp_using_ext_object_cache() ) {
			$found = false;
			$value = wp_cache_get( self::key( $key ), self::GROUP, false, $found );

			return $found ? $value : null;
		}
		$value = get_transient( self::key( $key ) );

		return false === $value ? null : $value;
	}

	/**
	 * @param string $key      Key.
	 * @param mixed  $value    Value (not false).
	 * @param int    $ttl      Seconds; 0 does not cache.
	 * @param bool   $per_user True for an answer that depends on the visitor.
	 */
	public static function set( $key, $value, $ttl, $per_user = false ) {
		$ttl = (int) $ttl;
		if ( $ttl <= 0 ) {
			return;
		}
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( self::key( $key ), $value, self::GROUP, $ttl );
		} elseif ( ! $per_user ) {
			set_transient( self::key( $key ), $value, $ttl );
		}
	}

	public static function clear() {
		update_option( self::GENERATION, (int) get_option( self::GENERATION, 1 ) + 1, true );
	}
}
