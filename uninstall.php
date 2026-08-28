<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}


$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'view_access_analytics' );
	$role->remove_cap( 'manage_access_analytics' );
}

if ( ! (bool) get_option( 'aap_delete_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'aap_pageviews',
	$wpdb->prefix . 'aap_sessions',
	$wpdb->prefix . 'aap_pages',
	$wpdb->prefix . 'aap_daily_dimensions',
	$wpdb->prefix . 'aap_exclusions_daily',
	$wpdb->prefix . 'aap_daily',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'aap_db_version' );
delete_option( 'aap_retention_days' );
delete_option( 'aap_delete_data_on_uninstall' );
delete_option( 'aap_installed_at' );
delete_option( 'aap_engagement_started_at' );
delete_option( 'aap_tracking_active' );
delete_option( 'aap_excluded_roles' );
delete_option( 'aap_excluded_ips' );
delete_option( 'aap_sample_enabled' );
delete_option( 'aap_sample_scale' );
delete_option( 'aap_sample_seed' );
delete_option( 'aap_last_daily_rebuild' );
