<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

delete_option( 'cf7sg_settings' );
wp_clear_scheduled_hook( 'cf7sg_daily_cleanup' );

global $wpdb;
$table = $wpdb->prefix . 'cf7sg_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
