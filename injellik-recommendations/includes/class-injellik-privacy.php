<?php
/**
 * Personal data.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy policy text and personal data eraser (Tools > Erase Personal Data): the actions of the
 * customer are deleted in Injellik (DELETE /api/users/{user_uuid}) and in the local queue.
 */
final class Injellik_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
	}

	public static function register_eraser( $erasers ) {
		$erasers['injellik'] = array(
			'eraser_friendly_name' => __( 'Injellik recommendations', 'injellik-recommendations' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @param string $email E-mail address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email ) {
		global $wpdb;

		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		$uuids  = array( Injellik_Identity::guest_uuid( $email ) );
		$user   = get_user_by( 'email', $email );
		if ( $user ) {
			$uuids[] = Injellik_Identity::customer_uuid( $user->ID );
		}

		foreach ( $uuids as $uuid ) {
			$deleted = $wpdb->delete( Injellik_Event_Queue::table(), array( 'user_uuid' => $uuid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $deleted ) {
				$result['items_removed'] = true;
			}
			if ( ! Injellik_Config::is_configured() ) {
				continue;
			}
			try {
				Injellik_Api_Client::instance()->set_timeout( 20 )->delete( '/api/users/' . rawurlencode( $uuid ) );
				$result['items_removed'] = true;
			} catch ( Injellik_Api_Exception $e ) {
				$result['items_retained'] = true;
				/* translators: %s: error message */
				$result['messages'][] = sprintf( __( 'The actions could not be deleted in Injellik: %s', 'injellik-recommendations' ), Injellik_Config::api_error_message( $e ) );
			}
		}

		return $result;
	}

	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Injellik Recommendations',
			wp_kses_post(
				wpautop(
					__( 'To recommend products, this shop records the products you view, add to your cart, order and rate, and sends them to Injellik (Web-Nest), its recommendation service. You are identified by a pseudonymous identifier: a random identifier stored in the injellik_vid cookie, or a hash of your customer account. Your name, e-mail address, postal address and IP address are never sent. The injellik_sid cookie identifies your visit and expires after 30 minutes of inactivity.', 'injellik-recommendations' )
				)
			)
		);
	}
}
