<?php
/**
 * Block statistics and dashboard aggregates.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily counters of the recommendation blocks (impressions and clicks per placement)
 * and the aggregates displayed on the dashboard.
 */
final class Injellik_Stats {

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'injellik_stats';
	}

	public static function impression( $placement ) {
		self::increment( $placement, 'impressions' );
	}

	public static function click( $placement ) {
		self::increment( $placement, 'clicks' );
	}

	private static function increment( $placement, $column ) {
		global $wpdb;

		$placement = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $placement ) );
		$placement = '' !== $placement ? substr( $placement, 0, 32 ) : 'custom';
		$column    = 'clicks' === $column ? 'clicks' : 'impressions';
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . " (`date`, placement, {$column}) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE {$column} = {$column} + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'Y-m-d' ),
				$placement
			)
		);
	}

	private static function since( $days ) {
		return gmdate( 'Y-m-d', current_time( 'timestamp' ) - ( (int) $days - 1 ) * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	/**
	 * @return array<string, array{impressions: int, clicks: int, ctr: float}>
	 */
	public static function by_placement( $days = 30 ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT placement, SUM(impressions) AS impressions, SUM(clicks) AS clicks FROM ' . self::table() . ' WHERE `date` >= %s GROUP BY placement ORDER BY impressions DESC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days )
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$impressions                 = (int) $row['impressions'];
			$clicks                      = (int) $row['clicks'];
			$result[ $row['placement'] ] = array(
				'impressions' => $impressions,
				'clicks'      => $clicks,
				'ctr'         => $impressions ? round( 100 * $clicks / $impressions, 2 ) : 0.0,
			);
		}

		return $result;
	}

	/**
	 * Events per day and per action type over the last $days days (zero-filled).
	 *
	 * @return array<string, array<string, int>> date => [action_type => count]
	 */
	public static function events_per_day( $days = 30 ) {
		global $wpdb;

		$now    = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$series = array();
		for ( $i = $days - 1; $i >= 0; --$i ) {
			$series[ gmdate( 'Y-m-d', $now - $i * DAY_IN_SECONDS ) ] = array();
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT DATE(date_add) AS day, action_type, COUNT(*) AS total FROM ' . Injellik_Event_Queue::table() . ' WHERE date_add >= %s GROUP BY day, action_type', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days ) . ' 00:00:00'
			),
			ARRAY_A
		);
		foreach ( $rows ? $rows : array() as $row ) {
			if ( isset( $series[ $row['day'] ] ) ) {
				$series[ $row['day'] ][ $row['action_type'] ] = (int) $row['total'];
			}
		}

		return $series;
	}

	/**
	 * @return array{pending: int, sent: int, error: int, last24h: int, last_sent: string|null}
	 */
	public static function queue_summary() {
		global $wpdb;

		$table   = Injellik_Event_Queue::table();
		$summary = array(
			'pending' => 0,
			'sent'    => 0,
			'error'   => 0,
		);
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$summary[ $row['status'] ] = (int) $row['total'];
		}
		$summary['last24h']   = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE date_add >= %s", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DateTime.CurrentTimeTimestamp.Requested
		);
		$last_sent            = $wpdb->get_var( "SELECT MAX(date_sent) FROM {$table}" ); // phpcs:ignore WordPress.DB
		$summary['last_sent'] = $last_sent ? $last_sent : null;

		return $summary;
	}

	/**
	 * @return array[]
	 */
	public static function recent_events( $limit = 25, $only_problems = false ) {
		global $wpdb;

		$where = $only_problems ? $wpdb->prepare( 'WHERE last_error IS NOT NULL AND status <> %s', Injellik_Event_Queue::STATUS_SENT ) : '';
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . Injellik_Event_Queue::table() . " {$where} ORDER BY date_add DESC, id DESC LIMIT " . (int) $limit, ARRAY_A ); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}
}
