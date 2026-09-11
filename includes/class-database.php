<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

final class Database {
	public const OPTION_DB_VERSION = 'aap_db_version';

	/**
	 * @return array<string, string>
	 */
	public static function tables(): array {
		global $wpdb;

		return array(
			'pages'            => $wpdb->prefix . 'aap_pages',
			'sessions'         => $wpdb->prefix . 'aap_sessions',
			'pageviews'        => $wpdb->prefix . 'aap_pageviews',
			'daily'            => $wpdb->prefix . 'aap_daily',
			'daily_dimensions' => $wpdb->prefix . 'aap_daily_dimensions',
			'exclusions_daily' => $wpdb->prefix . 'aap_exclusions_daily',
			'shadow_events'    => $wpdb->prefix . 'aap_shadow_events',
		);
	}

	public static function maybe_upgrade(): void {
		if ( AAP_DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '' ) ) {
			self::install();
		}

		if ( false === get_option( 'aap_engagement_started_at', false ) ) {
			global $wpdb;
			$tables = self::tables();
			$earliest = (string) $wpdb->get_var(
				"SELECT MIN(s.started_at) FROM {$tables['sessions']} s
				INNER JOIN {$tables['pageviews']} pv ON pv.session_id = s.id
				WHERE pv.engaged_seconds > 0"
			);
			add_option( 'aap_engagement_started_at', '' !== $earliest ? $earliest : current_time( 'mysql', true ), '', false );
		}
		add_option( 'aap_tracking_active', 1, '', false );
		add_option( 'aap_excluded_roles', array( 'administrator' ), '', false );
		add_option( 'aap_excluded_ips', array(), '', false );
		add_option( 'aap_sample_enabled', 0, '', false );
		add_option( 'aap_sample_scale', 'standard', '', false );
		add_option( 'aap_sample_seed', wp_rand( 1, 2147483647 ), '', false );
		add_option( 'aap_shadow_diagnostics_enabled', 1, '', false );
		add_option( 'aap_country_mode', 'all', '', false );
		add_option( 'aap_allowed_countries', array( 'JP' ), '', false );
		add_option( 'aap_trust_cloudflare_country', 0, '', false );
		add_option( 'aap_geoip_database_state', array(), '', false );
		add_option( 'aap_geoip_last_check', 0, '', false );
		add_option( 'aap_confirmation_started_at', current_time( 'mysql', true ), '', false );
	}

	public static function install(): void {
		global $wpdb;

		$tables          = self::tables();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = array();
		$sql[] = "CREATE TABLE {$tables['pages']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(64) NOT NULL,
			path varchar(1000) NOT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			title text NOT NULL,
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY post_id (post_id),
			KEY last_seen_at (last_seen_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['sessions']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_key char(64) NOT NULL,
			visitor_key char(64) NOT NULL,
			started_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			entry_page_id bigint(20) unsigned NOT NULL,
			pageview_count int(10) unsigned NOT NULL DEFAULT 1,
			referrer_type varchar(20) NOT NULL DEFAULT 'direct',
			referrer_host varchar(191) NOT NULL DEFAULT '',
			search_source varchar(32) NOT NULL DEFAULT '',
			device_type varchar(20) NOT NULL DEFAULT 'other',
			PRIMARY KEY  (id),
			UNIQUE KEY session_key (session_key),
			KEY visitor_started (visitor_key, started_at),
			KEY started_at (started_at),
			KEY last_seen_at (last_seen_at),
			KEY referrer_started (referrer_type, started_at),
			KEY started_report (started_at, visitor_key, device_type, referrer_type),
			KEY quality_period (started_at, last_seen_at, pageview_count, id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['pageviews']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			page_id bigint(20) unsigned NOT NULL,
			viewed_at datetime NOT NULL,
			engaged_seconds int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY viewed_at (viewed_at),
			KEY page_viewed (page_id, viewed_at),
			KEY viewed_report (viewed_at, session_id, page_id),
			KEY session_engagement (session_id, engaged_seconds)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['daily']} (
			stat_date date NOT NULL,
			visitors bigint(20) unsigned NOT NULL DEFAULT 0,
			visits bigint(20) unsigned NOT NULL DEFAULT 0,
			pageviews bigint(20) unsigned NOT NULL DEFAULT 0,
			engaged_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
			bounces bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (stat_date)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['daily_dimensions']} (
			stat_date date NOT NULL,
			dimension_type varchar(20) NOT NULL,
			dimension_key varchar(191) NOT NULL,
			label varchar(255) NOT NULL DEFAULT '',
			visitors bigint(20) unsigned NOT NULL DEFAULT 0,
			visits bigint(20) unsigned NOT NULL DEFAULT 0,
			pageviews bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (stat_date, dimension_type, dimension_key),
			KEY dimension_lookup (dimension_type, stat_date)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['exclusions_daily']} (
			stat_date date NOT NULL,
			reason varchar(32) NOT NULL,
			excluded_count bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (stat_date, reason)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['shadow_events']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pageview_id bigint(20) unsigned DEFAULT NULL,
			session_id bigint(20) unsigned DEFAULT NULL,
			recorded_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			ip_key char(64) NOT NULL DEFAULT '',
			visitor_key char(64) NOT NULL,
			session_key char(64) NOT NULL DEFAULT '',
			ua_hash char(64) NOT NULL,
			path_hash char(64) NOT NULL DEFAULT '',
			path varchar(1000) NOT NULL DEFAULT '/',
			title text NOT NULL,
			referrer_type varchar(20) NOT NULL DEFAULT 'direct',
			referrer_host varchar(191) NOT NULL DEFAULT '',
			search_source varchar(32) NOT NULL DEFAULT '',
			ua_family varchar(32) NOT NULL DEFAULT 'other',
			ua_device_type varchar(20) NOT NULL DEFAULT 'other',
			reported_device_type varchar(20) NOT NULL DEFAULT 'other',
			country_code char(2) NOT NULL DEFAULT 'ZZ',
			country_source varchar(16) NOT NULL DEFAULT 'unknown',
			device_mismatch tinyint(1) unsigned NOT NULL DEFAULT 0,
			webdriver_state tinyint(2) NOT NULL DEFAULT -1,
			origin_present tinyint(1) unsigned NOT NULL DEFAULT 0,
			visible_confirmed tinyint(1) unsigned NOT NULL DEFAULT 0,
			interaction_mask tinyint(3) unsigned NOT NULL DEFAULT 0,
			engagement_received tinyint(1) unsigned NOT NULL DEFAULT 0,
			ip_visitors_10m int(10) unsigned NOT NULL DEFAULT 0,
			ua_visitors_10m int(10) unsigned NOT NULL DEFAULT 0,
			ua_path_requests_10m int(10) unsigned NOT NULL DEFAULT 0,
			visitor_is_new tinyint(1) unsigned NOT NULL DEFAULT 1,
			regular_interval tinyint(1) unsigned NOT NULL DEFAULT 0,
			risk_score smallint(5) unsigned NOT NULL DEFAULT 0,
			risk_flags bigint(20) unsigned NOT NULL DEFAULT 0,
			shadow_class varchar(16) NOT NULL DEFAULT 'pending',
			finalized tinyint(1) unsigned NOT NULL DEFAULT 0,
			aggregation_mode varchar(12) NOT NULL DEFAULT 'legacy',
			promoted_at datetime DEFAULT NULL,
			exclusion_recorded tinyint(1) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY pageview_id (pageview_id),
			KEY recorded_at (recorded_at),
			KEY ip_recorded (ip_key, recorded_at),
			KEY visitor_recorded (visitor_key, recorded_at),
			KEY ua_recorded (ua_hash, recorded_at),
			KEY ua_path_recorded (ua_hash, path_hash, recorded_at),
			KEY class_recorded (shadow_class, recorded_at),
			KEY finalized_recorded (finalized, recorded_at),
			KEY mode_class_recorded (aggregation_mode, shadow_class, recorded_at),
			KEY session_id (session_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::OPTION_DB_VERSION, AAP_DB_VERSION, false );
		add_option( 'aap_retention_days', 90, '', false );
		add_option( 'aap_delete_data_on_uninstall', 0, '', false );
		add_option( 'aap_installed_at', current_time( 'mysql', true ), '', false );
		add_option( 'aap_engagement_started_at', current_time( 'mysql', true ), '', false );
		add_option( 'aap_tracking_active', 1, '', false );
		add_option( 'aap_excluded_roles', array( 'administrator' ), '', false );
		add_option( 'aap_excluded_ips', array(), '', false );
		add_option( 'aap_sample_enabled', 0, '', false );
		add_option( 'aap_sample_scale', 'standard', '', false );
		add_option( 'aap_sample_seed', wp_rand( 1, 2147483647 ), '', false );
		add_option( 'aap_shadow_diagnostics_enabled', 1, '', false );
		add_option( 'aap_country_mode', 'all', '', false );
		add_option( 'aap_allowed_countries', array( 'JP' ), '', false );
		add_option( 'aap_trust_cloudflare_country', 0, '', false );
		add_option( 'aap_geoip_database_state', array(), '', false );
		add_option( 'aap_geoip_last_check', 0, '', false );
		add_option( 'aap_confirmation_started_at', current_time( 'mysql', true ), '', false );
	}
}
