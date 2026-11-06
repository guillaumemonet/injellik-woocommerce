<?php
/**
 * Activation, tables and updates.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tables, default settings and scheduled tasks; shared by the activation and the updates.
 */
final class Injellik_Installer {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'injellik_db_version';

	/**
	 * Activation, on every site of the network for a network activation.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}

			return;
		}
		self::install();
	}

	public static function deactivate() {
		Injellik_Cron::unschedule();
	}

	public static function install() {
		self::create_tables();
		self::install_settings();
		Injellik_Cron::schedule();
	}

	/**
	 * Updates the tables after an update of the plugin.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$events  = Injellik_Event_Queue::table();
		$stats   = Injellik_Stats::table();

		dbDelta(
			"CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  action_type varchar(50) NOT NULL,
  item_sku varchar(50) NOT NULL,
  item_name varchar(255) NULL,
  user_uuid varchar(64) NOT NULL,
  session_uuid varchar(64) NULL,
  rating_value tinyint(3) unsigned NULL,
  status varchar(10) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_error varchar(255) NULL,
  date_add datetime NOT NULL,
  date_sent datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY date_add (date_add),
  KEY user_uuid (user_uuid)
) {$collate};
CREATE TABLE {$stats} (
  date date NOT NULL,
  placement varchar(32) NOT NULL,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (date,placement)
) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Missing settings only, so that a reactivation keeps the credentials.
	 */
	public static function install_settings() {
		$stored   = get_option( Injellik_Config::OPTION, array() );
		$settings = array_merge( Injellik_Config::defaults(), is_array( $stored ) ? $stored : array() );
		if ( '' === (string) $settings['cron_token'] ) {
			$settings['cron_token'] = wp_generate_password( 32, false );
		}
		update_option( Injellik_Config::OPTION, $settings, true );
		if ( false === get_option( Injellik_Config::STATE_OPTION ) ) {
			add_option( Injellik_Config::STATE_OPTION, array(), '', true );
		}
	}

	/**
	 * Removes the tables and the options of the current site (uninstall.php).
	 */
	public static function uninstall_site() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . Injellik_Event_Queue::table() . ', ' . Injellik_Stats::table() ); // phpcs:ignore WordPress.DB
		foreach ( array( Injellik_Config::OPTION, Injellik_Config::STATE_OPTION, self::DB_VERSION_OPTION, Injellik_Cache::GENERATION ) as $option ) {
			delete_option( $option );
		}
		Injellik_Cron::unschedule();
	}
}
