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
	}
}
