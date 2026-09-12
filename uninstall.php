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
	$wpdb->prefix . 'aap_shadow_events',
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

$uploads = wp_upload_dir( null, false );
if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
	$geo_directory = trailingslashit( (string) $uploads['basedir'] ) . 'access-analytics-plus/geo';
	$geo_files = glob( trailingslashit( $geo_directory ) . 'dbip-country-lite-????-??.mmdb' ) ?: array();
	$region_files = glob( trailingslashit( $geo_directory ) . 'aap-japan-prefecture-????-??-????????????.mmdb' ) ?: array();
	$geo_files = array_merge( $geo_files, $region_files );
	foreach ( $geo_files as $geo_file ) {
		if ( is_file( $geo_file ) ) { @unlink( $geo_file ); }
	}
	if ( is_dir( $geo_directory ) ) { @rmdir( $geo_directory ); }
	$geo_parent = dirname( $geo_directory );
	if ( is_dir( $geo_parent ) ) { @rmdir( $geo_parent ); }
}

delete_option( 'aap_db_version' );
delete_option( 'aap_db_schema_error' );
delete_option( 'aap_schema_repair_lock' );
delete_option( 'aap_last_collect_failure' );
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
delete_option( 'aap_shadow_diagnostics_enabled' );
delete_option( 'aap_country_mode' );
delete_option( 'aap_allowed_countries' );
delete_option( 'aap_trust_cloudflare_country' );
delete_option( 'aap_geoip_database_state' );
delete_option( 'aap_geoip_last_check' );
delete_option( 'aap_confirmation_started_at' );
delete_option( 'aap_region_tracking_started_at' );
delete_option( 'aap_region_database_state' );
delete_option( 'aap_region_database_last_check' );
delete_transient( 'aap_confirmation_finalize_lock' );
delete_option( 'aap_last_daily_rebuild' );
