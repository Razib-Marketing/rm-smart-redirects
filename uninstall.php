<?php
/**
 * Uninstall RM Smart Redirects
 *
 * Fired when the plugin is deleted from WordPress admin.
 * Cleans up all plugin data from the database.
 *
 * @package RM_Smart_Redirects
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom tables.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Required for clean uninstall
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rmsmart_redirects" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Required for clean uninstall
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rmsmart_404_logs" );

