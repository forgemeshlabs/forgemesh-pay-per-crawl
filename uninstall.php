<?php
/**
 * Removes everything the plugin stored. Runs only when the plugin is deleted from wp-admin.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'fmppc_hits' ); // phpcs:ignore WordPress.DB
delete_option( 'fmppc_settings' );
delete_option( 'fmppc_ip_salt' );
delete_option( 'fmppc_db_version' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fmppc_%' OR option_name LIKE '_transient_timeout_fmppc_%'" ); // phpcs:ignore WordPress.DB
wp_clear_scheduled_hook( 'fmppc_daily' );
