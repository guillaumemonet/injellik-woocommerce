<?php
/**
 * Local queue of the actions sent to the API.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Local queue of the actions sent to the API.
 *
 * Hooks only insert a row (a few milliseconds, never a network call), so an API outage can
 * neither slow down nor break the shop. Rows are sent after the response has been delivered
 * ("realtime" mode) or by the cron task, in batches (POST /api/actions/batch), and retried until MAX_ATTEMPTS.
 *
 * While the API refuses the shop (suspended subscription, invalid licence key), the events stay pending
 * without using their attempts, and are sent once the API accepts the shop again. The purge keeps the
 * queue bounded meanwhile.
 */
final class Injellik_Event_Queue {

	const STATUS_PENDING = 'pending';
	const STATUS_SENT    = 'sent';
	const STATUS_ERROR   = 'error';
	const MAX_ATTEMPTS   = 5;
	/** Maximum number of actions of POST /api/actions/batch. */
	const BATCH_SIZE = 500;

	/** @var int[] rows created by the current request */
	private static $created = array();
	private static $shutdown_registered = false;
	/** @var bool the API has no batch route (older version): actions are sent one by one */
	private static $no_batch = false;

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'injellik_events';
	}

	/**
	 * @return int|null id of the queued event
	 */
	public static function push( $action_type, $sku, $user_uuid, $session_uuid = null, $name = null, $rating = null ) {
		global $wpdb;

		$sku = substr( trim( (string) $sku ), 0, 50 );
		if ( '' === $sku || '' === (string) $user_uuid ) {
			return null;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'action_type'  => substr( (string) $action_type, 0, 50 ),
				'item_sku'     => $sku,
				'item_name'    => null === $name ? null : self::cut( $name, 255 ),
				'user_uuid'    => substr( (string) $user_uuid, 0, 64 ),
				'session_uuid' => null === $session_uuid ? null : substr( (string) $session_uuid, 0, 64 ),
				'rating_value' => null === $rating ? null : max( 1, min( 5, (int) $rating ) ),
				'status'       => self::STATUS_PENDING,
				'attempts'     => 0,
				'date_add'     => current_time( 'mysql' ),
			)
		);
		if ( ! $inserted ) {
			return null;
		}
		$id = (int) $wpdb->insert_id;

		if ( Injellik_Config::SEND_CRON !== Injellik_Config::get( 'send_mode' ) && ! Injellik_Config::is_api_blocked() ) {
			self::$created[] = $id;
			if ( ! self::$shutdown_registered ) {
				self::$shutdown_registered = true;
				add_action( 'shutdown', array( __CLASS__, 'send_after_response' ), 1000 );
			}
		}

		return $id;
	}

	/**
	 * Shutdown handler: hands the response to the visitor first when PHP-FPM allows it.
	 */
	public static function send_after_response() {
		global $wpdb;

		if ( ! self::$created ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$ids           = array_map( 'intval', self::$created );
		self::$created = array();

		try {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . ' WHERE id IN (' . implode( ',', $ids ) . ') AND status = %s ORDER BY id', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_PENDING
				),
				ARRAY_A
			);
			self::send_rows( $rows ? $rows : array() );
		} catch ( Exception $e ) {
			self::log( $e->getMessage() );
		}
	}

	/**
	 * Sends the pending events (cron, dashboard).
	 *
	 * @return array{sent: int, failed: int, pending: int}
	 */
	public static function flush( $limit = 500 ) {
		global $wpdb;

		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY id LIMIT %d', self::STATUS_PENDING, max( 1, (int) $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$result = self::send_rows( $rows ? $rows : array() );

		$result['pending'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', self::STATUS_PENDING ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $result;
	}

	/**
	 * Puts the events in error back in the queue.
	 */
	public static function retry_errors() {
		global $wpdb;

		return $wpdb->update( self::table(), array( 'status' => self::STATUS_PENDING, 'attempts' => 0 ), array( 'status' => self::STATUS_ERROR ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes the sent events older than $days and every event older than 3 × $days.
	 *
	 * @return int deleted rows
	 */
	public static function purge( $days = 30 ) {
		global $wpdb;

		$days = max( 1, (int) $days );
		$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- dates are stored in local time.

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE (status = %s AND date_add < %s) OR date_add < %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_SENT,
				gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', $now - 3 * $days * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * @param array[] $rows Rows of the table.
	 * @return array{sent: int, failed: int}
	 */
	private static function send_rows( array $rows ) {
		$result = array(
			'sent'   => 0,
			'failed' => 0,
		);
		if ( ! $rows || ! Injellik_Config::is_configured() || Injellik_Config::is_api_blocked() ) {
			return $result;
		}

		$payloads = array();
		$by_id    = array();
		foreach ( $rows as $row ) {
			$id      = (int) $row['id'];
			$payload = array(
				'action_type' => $row['action_type'],
				'item_sku'    => $row['item_sku'],
				'user_uuid'   => $row['user_uuid'],
			);
			if ( null !== $row['session_uuid'] && '' !== $row['session_uuid'] ) {
				$payload['user_session_uuid'] = $row['session_uuid'];
			}
			if ( null !== $row['item_name'] && '' !== $row['item_name'] ) {
				$payload['name'] = $row['item_name'];
			}
			if ( null !== $row['rating_value'] && '' !== $row['rating_value'] ) {
				$payload['rating_value'] = (int) $row['rating_value'];
			}
			$payloads[ $id ] = $payload;
			$by_id[ $id ]    = $row;
		}

		$delivery = self::deliver( $payloads );

		if ( $delivery['sent'] ) {
			self::mark_sent( $delivery['sent'] );
			$result['sent'] = count( $delivery['sent'] );
		}
		foreach ( $delivery['rejected'] as $id => $message ) {
			// Refused by the API (validation): sending it again would give the same answer.
			self::update(
				$id,
				array(
					'status'     => self::STATUS_ERROR,
					'attempts'   => (int) $by_id[ $id ]['attempts'] + 1,
					'last_error' => self::cut( $message, 255 ),
				)
			);
			++$result['failed'];
		}
		if ( $delivery['rejected'] ) {
			Injellik_Config::record_error( reset( $delivery['rejected'] ) );
		}

		$error = $delivery['error'];
		if ( $error ) {
			if ( $error->is_access_denied() ) {
				// The events are valid: they stay pending, without using an attempt. The state of the
				// API is stored by the client and displayed in the back office.
				self::purge();

				return $result;
			}
			foreach ( $by_id as $id => $row ) {
				if ( in_array( $id, $delivery['sent'], true ) || isset( $delivery['rejected'][ $id ] ) ) {
					continue;
				}
				$attempts = (int) $row['attempts'] + 1;
				$retry    = $error->is_retryable() && $attempts < self::MAX_ATTEMPTS;
				self::update(
					$id,
					array(
						'status'     => $retry ? self::STATUS_PENDING : self::STATUS_ERROR,
						'attempts'   => $attempts,
						'last_error' => self::cut( $error->getMessage(), 255 ),
					)
				);
				++$result['failed'];
			}
			Injellik_Config::record_error( $error->getMessage() );
		}

		return $result;
	}

	/**
	 * Sends actions, in batches of BATCH_SIZE (one by one with an API that has no batch route).
	 *
	 * @param array<int|string, array> $payloads Actions, by key.
	 * @return array{sent: array<int, int|string>, rejected: array<int|string, string>, error: Injellik_Api_Exception|null}
	 *         keys sent, keys refused by the API with their message, and the error that stopped the delivery
	 *         (network, server, refusal of the shop): the keys neither sent nor rejected were not delivered.
	 */
	public static function deliver( array $payloads ) {
		$result = array(
			'sent'     => array(),
			'rejected' => array(),
			'error'    => null,
		);
		$client = Injellik_Api_Client::instance();

		if ( ! self::$no_batch ) {
			try {
				foreach ( array_chunk( $payloads, self::BATCH_SIZE, true ) as $chunk ) {
					$keys     = array_keys( $chunk );
					$response = $client->post( '/api/actions/batch', array( 'actions' => array_values( $chunk ) ) );
					$rejected = array();
					foreach ( isset( $response['errors'] ) && is_array( $response['errors'] ) ? $response['errors'] : array() as $error ) {
						$index = isset( $error['index'] ) ? (int) $error['index'] : -1;
						if ( isset( $keys[ $index ] ) ) {
							$message = isset( $error['error'] ) && is_scalar( $error['error'] ) ? (string) $error['error'] : 'rejected';
							if ( ! empty( $error['violations'] ) && is_array( $error['violations'] ) ) {
								$message .= ' (' . Injellik_Api_Client::violations( $error['violations'] ) . ')';
							}
							$rejected[ $keys[ $index ] ] = $message;
						}
					}
					foreach ( $keys as $key ) {
						if ( isset( $rejected[ $key ] ) ) {
							$result['rejected'][ $key ] = $rejected[ $key ];
						} else {
							$result['sent'][] = $key;
						}
					}
				}

				return $result;
			} catch ( Injellik_Api_Exception $e ) {
				if ( ! in_array( $e->get_status(), array( 404, 405 ), true ) ) {
					$result['error'] = $e;

					return $result;
				}
				self::$no_batch = true;
			}
		}

		foreach ( $payloads as $key => $payload ) {
			if ( in_array( $key, $result['sent'], true ) || isset( $result['rejected'][ $key ] ) ) {
				continue;
			}
			try {
				$client->post( '/api/actions', $payload );
				$result['sent'][] = $key;
			} catch ( Injellik_Api_Exception $e ) {
				if ( $e->is_retryable() || $e->is_access_denied() ) {
					// Do not wait for one timeout per action: the rest is sent later.
					$result['error'] = $e;

					return $result;
				}
				$result['rejected'][ $key ] = $e->getMessage();
			}
		}

		return $result;
	}

	private static function mark_sent( array $ids ) {
		global $wpdb;

		$ids = array_map( 'intval', $ids );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'UPDATE ' . self::table() . ' SET status = %s, attempts = attempts + 1, last_error = NULL, date_sent = %s WHERE id IN (' . implode( ',', $chunk ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_SENT,
					current_time( 'mysql' )
				)
			);
		}
	}

	private static function update( $id, array $data ) {
		global $wpdb;

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function cut( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $length ) : substr( (string) $text, 0, $length );
	}

	public static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( (string) $message, array( 'source' => 'injellik' ) );
		}
	}
}
