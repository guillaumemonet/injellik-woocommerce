<?php
/**
 * WP-CLI commands.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Injellik Recommendations: queue, catalog and order history.
 */
class Injellik_CLI {

	/**
	 * Displays the connection, the queue and the last synchronisations.
	 *
	 * ## EXAMPLES
	 *
	 *     wp injellik status
	 *
	 * @when after_wp_load
	 */
	public function status() {
		$client = Injellik_Api_Client::instance()->set_timeout( 10 );
		$state  = Injellik_Config::api_state();
		$queue  = Injellik_Stats::queue_summary();
		$health = 'not configured';
		if ( Injellik_Config::is_configured() ) {
			try {
				$client->get( '/api/actiontypes' );
				$health = 'ok';
			} catch ( Injellik_Api_Exception $e ) {
				$health = Injellik_Config::api_error_message( $e );
			}
		}

		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'key'   => 'api',
					'value' => Injellik_Config::api_url() . ' (' . ( Injellik_Config::uses_licence_key() ? 'licence key' : 'login' ) . ')',
				),
				array(
					'key'   => 'connection',
					'value' => $health,
				),
				array(
					'key'   => 'api_state',
					'value' => $state ? $state['reason'] . ' since ' . $state['since'] : '-',
				),
				array(
					'key'   => 'queue',
					'value' => sprintf( '%d pending, %d sent, %d in error', $queue['pending'], $queue['sent'], $queue['error'] ),
				),
				array(
					'key'   => 'catalog',
					'value' => (string) Injellik_Config::state( 'catalog_synced_at', '-' ) . ' (' . (int) Injellik_Config::state( 'catalog_count', 0 ) . ' products)',
				),
				array(
					'key'   => 'last_cron',
					'value' => (string) Injellik_Config::state( 'last_cron', '-' ),
				),
			),
			array( 'key', 'value' )
		);
	}

	/**
	 * Sends the pending events and purges the old ones.
	 *
	 * ## OPTIONS
	 *
	 * [--retry-errors]
	 * : Puts the events in error back in the queue first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp injellik flush
	 *
	 * @when after_wp_load
	 */
	public function flush( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['retry-errors'] ) ) {
			Injellik_Event_Queue::retry_errors();
		}
		$report = Injellik_Cron::run_events();
		$events = isset( $report['events'] ) ? $report['events'] : array(
			'sent'    => 0,
			'failed'  => 0,
			'pending' => 0,
		);
		WP_CLI::success( sprintf( '%d sent, %d failed, %d pending, %d purged.', $events['sent'], $events['failed'], $events['pending'], $report['purged'] ) );
	}

	/**
	 * Synchronises the whole catalog.
	 *
	 * ## EXAMPLES
	 *
	 *     wp injellik sync-catalog
	 *
	 * @subcommand sync-catalog
	 * @when after_wp_load
	 */
	public function sync_catalog() {
		$this->require_api();
		try {
			$report = Injellik_Cron::run_catalog();
		} catch ( Injellik_Api_Exception $e ) {
			WP_CLI::error( Injellik_Config::api_error_message( $e ) );
		}
		WP_CLI::success( sprintf( '%d products synchronised.', $report['products'] ) );
	}

	/**
	 * Imports the paid orders (BUY actions with their real date, not counted as billable sessions).
	 *
	 * ## OPTIONS
	 *
	 * [--since=<date>]
	 * : Orders placed since this date (YYYY-MM-DD).
	 *
	 * [--restart]
	 * : Imports again the orders already imported.
	 *
	 * ## EXAMPLES
	 *
	 *     wp injellik import-orders --since=2025-01-01
	 *
	 * @subcommand import-orders
	 * @when after_wp_load
	 */
	public function import_orders( $args, $assoc_args ) {
		$this->require_api();
		Injellik_Api_Client::instance()->set_timeout( 30 );
		$since = isset( $assoc_args['since'] ) ? (string) $assoc_args['since'] : null;
		if ( null !== $since && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
			WP_CLI::error( 'Invalid date, expected YYYY-MM-DD.' );
		}
		if ( ! empty( $assoc_args['restart'] ) ) {
			Injellik_Config::set_state(
				array(
					'import_last_order'   => 0,
					'import_orders_count' => 0,
				)
			);
		}

		$after    = (int) Injellik_Config::state( 'import_last_order', 0 );
		$total    = Injellik_Sync::count_orders( $after, $since );
		$progress = WP_CLI\Utils\make_progress_bar( 'Importing orders', $total );
		$sent     = 0;
		$errors   = 0;
		do {
			$batch = Injellik_Sync::import_orders_batch( $after, $since );
			$after = $batch['last_id'];
			Injellik_Config::set_state(
				array(
					'import_last_order'   => $after,
					'import_orders_count' => (int) Injellik_Config::state( 'import_orders_count', 0 ) + $batch['processed'],
				)
			);
			$sent   += $batch['sent'];
			$errors += $batch['errors'];
			$progress->tick( $batch['processed'] );
			if ( $batch['error'] ) {
				$progress->finish();
				WP_CLI::error( Injellik_Config::api_error_message( $batch['error'] ) . ' Run the command again to resume.' );
			}
		} while ( ! $batch['done'] );
		$progress->finish();

		Injellik_Config::set_state( array( 'import_orders_at' => current_time( 'mysql' ) ) );
		WP_CLI::success( sprintf( '%d purchases sent, %d refused by the API.', $sent, $errors ) );
	}

	private function require_api() {
		if ( ! Injellik_Config::is_configured() ) {
			WP_CLI::error( 'Injellik is not configured: save a licence key first.' );
		}
		if ( Injellik_Config::is_api_blocked() ) {
			Injellik_Config::allow_api_retry();
		}
	}
}
