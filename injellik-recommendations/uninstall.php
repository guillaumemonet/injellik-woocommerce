<?php
/**
 * Uninstall: removes the tables and the settings of every site.
 * The data already sent to Injellik is kept.
 *
 * @package Injellik
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'INJELLIK_VERSION' ) ) {
	define( 'INJELLIK_VERSION', '' );
}
require_once __DIR__ . '/includes/class-injellik-config.php';
require_once __DIR__ . '/includes/class-injellik-cache.php';
require_once __DIR__ . '/includes/class-injellik-event-queue.php';
require_once __DIR__ . '/includes/class-injellik-stats.php';
require_once __DIR__ . '/includes/class-injellik-cron.php';
require_once __DIR__ . '/includes/class-injellik-installer.php';

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $injellik_site_id ) {
		switch_to_blog( $injellik_site_id );
		Injellik_Installer::uninstall_site();
		restore_current_blog();
	}
} else {
	Injellik_Installer::uninstall_site();
}
