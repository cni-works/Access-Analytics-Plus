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
		add_option( 'aap_region_tracking_started_at', current_time( 'mysql', true ), '', false );
		add_option( 'aap_region_database_state', array(), '', false );
		add_option( 'aap_region_database_last_check', 0, '', false );
	}

	/**
	 * Makes one bounded schema-repair attempt after a live collect insert fails.
	 *
	 * The normal upgrade remains the primary path. This fallback exists for sites
	 * where an interrupted dbDelta run left the version option and physical table
	 * out of sync. The option lock prevents concurrent front-end requests from
	 * running dbDelta together.
	 */
	public static function repair_after_collect_failure(): bool {
		$lock_option = 'aap_schema_repair_lock';
		$lock_time   = (int) get_option( $lock_option, 0 );
		if ( $lock_time > 0 && $lock_time >= time() - 300 ) {
			return false;
		}
		if ( $lock_time > 0 ) {
			delete_option( $lock_option );
		}
		if ( ! add_option( $lock_option, time(), '', false ) ) {
			return false;
		}

		try {
			return self::install();
		} finally {
			delete_option( $lock_option );
		}
	}

	public static function install(): bool {
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
			country_code char(2) NOT NULL DEFAULT 'ZZ',
			region_code char(5) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY session_key (session_key),
			KEY visitor_started (visitor_key, started_at),
			KEY started_at (started_at),
			KEY last_seen_at (last_seen_at),
			KEY referrer_started (referrer_type, started_at),
			KEY started_report (started_at, visitor_key, device_type, referrer_type),
			KEY region_report (country_code, started_at, visitor_key, region_code),
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
			collect_key char(64) DEFAULT NULL,
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
			region_code char(5) NOT NULL DEFAULT '',
			region_source varchar(16) NOT NULL DEFAULT 'unknown',
			tracker_build varchar(64) NOT NULL DEFAULT 'unknown',
			build_mismatch tinyint(1) unsigned NOT NULL DEFAULT 0,
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
			pipeline_stage varchar(32) NOT NULL DEFAULT 'staged',
			last_completed_stage varchar(32) NOT NULL DEFAULT 'shadow_staged',
			promotion_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			signal_received_at datetime DEFAULT NULL,
			promoted_at datetime DEFAULT NULL,
			last_error_stage varchar(32) NOT NULL DEFAULT '',
			last_error_type varchar(64) NOT NULL DEFAULT '',
			last_error_message varchar(500) NOT NULL DEFAULT '',
			last_error_at datetime DEFAULT NULL,
			exclusion_recorded tinyint(1) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY collect_key (collect_key),
			UNIQUE KEY pageview_id (pageview_id),
			KEY recorded_at (recorded_at),
			KEY ip_recorded (ip_key, recorded_at),
			KEY visitor_recorded (visitor_key, recorded_at),
			KEY ua_recorded (ua_hash, recorded_at),
			KEY ua_path_recorded (ua_hash, path_hash, recorded_at),
			KEY class_recorded (shadow_class, recorded_at),
			KEY finalized_recorded (finalized, recorded_at),
			KEY mode_class_recorded (aggregation_mode, shadow_class, recorded_at),
			KEY pipeline_recorded (pipeline_stage, recorded_at),
			KEY mismatch_recorded (build_mismatch, recorded_at),
			KEY session_id (session_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( ! self::normalize_shadow_nullable_columns() ) {
			update_option( 'aap_db_schema_error', 'shadow_nullable_migration_failed', false );
			return false;
		}

		if ( ! self::verify_schema() ) {
			update_option( 'aap_db_schema_error', 'schema_verification_failed', false );
			return false;
		}

		delete_option( 'aap_db_schema_error' );
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
		add_option( 'aap_region_tracking_started_at', current_time( 'mysql', true ), '', false );
		add_option( 'aap_region_database_state', array(), '', false );
		add_option( 'aap_region_database_last_check', 0, '', false );
		return true;
	}

	/**
	 * dbDelta does not reliably change an existing numeric NOT NULL default from
	 * zero to NULL. Staged rows have no pageview/session yet, so zero would collide
	 * with the UNIQUE pageview_id index after the first pending request.
	 */
	private static function normalize_shadow_nullable_columns(): bool {
		global $wpdb;
		$table = self::tables()['shadow_events'];
		$changes = array(
			"ALTER TABLE `{$table}` MODIFY `collect_key` char(64) NULL DEFAULT NULL",
			"ALTER TABLE `{$table}` MODIFY `pageview_id` bigint(20) unsigned NULL DEFAULT NULL",
			"ALTER TABLE `{$table}` MODIFY `session_id` bigint(20) unsigned NULL DEFAULT NULL",
		);
		foreach ( $changes as $query ) {
			if ( false === $wpdb->query( $query ) ) {
				return false;
			}
		}
		$cleanup_queries = array(
			"UPDATE `{$table}` SET `collect_key`=NULL WHERE `collect_key`=''",
			"UPDATE `{$table}` SET `pageview_id`=NULL WHERE `pageview_id`=0",
			"UPDATE `{$table}` SET `session_id`=NULL WHERE `session_id`=0",
		);
		foreach ( $cleanup_queries as $query ) {
			if ( false === $wpdb->query( $query ) ) {
				return false;
			}
		}
		return true;
	}

	private static function verify_schema(): bool {
		global $wpdb;
		$tables = self::tables();
		$required_columns = array(
			'pages' => array( 'id', 'url_hash', 'path' ),
			'sessions' => array( 'id', 'session_key', 'visitor_key', 'pageview_count', 'country_code', 'region_code' ),
			'pageviews' => array( 'id', 'session_id', 'page_id', 'viewed_at' ),
			'daily' => array( 'stat_date', 'visitors', 'visits', 'pageviews' ),
			'daily_dimensions' => array( 'stat_date', 'dimension_type', 'dimension_key' ),
			'exclusions_daily' => array( 'stat_date', 'reason', 'excluded_count' ),
			'shadow_events' => array(
				'id', 'collect_key', 'pageview_id', 'session_id', 'recorded_at', 'updated_at', 'ip_key', 'visitor_key',
				'session_key', 'ua_hash', 'path_hash', 'path', 'title', 'referrer_type', 'referrer_host',
				'search_source', 'ua_family', 'ua_device_type', 'reported_device_type', 'country_code',
				'country_source', 'region_code', 'region_source', 'tracker_build', 'build_mismatch', 'device_mismatch', 'webdriver_state',
				'origin_present', 'visible_confirmed', 'interaction_mask', 'engagement_received',
				'ip_visitors_10m', 'ua_visitors_10m', 'ua_path_requests_10m', 'visitor_is_new',
				'regular_interval', 'risk_score', 'risk_flags', 'shadow_class', 'finalized',
				'aggregation_mode', 'pipeline_stage', 'last_completed_stage', 'promotion_attempts',
				'signal_received_at', 'promoted_at', 'last_error_stage', 'last_error_type',
				'last_error_message', 'last_error_at', 'exclusion_recorded',
			),
		);
		$required_indexes = array(
			'pages' => array( 'PRIMARY', 'url_hash' ),
			'sessions' => array( 'PRIMARY', 'session_key', 'region_report' ),
			'pageviews' => array( 'PRIMARY', 'session_id', 'viewed_at' ),
			'daily' => array( 'PRIMARY' ),
			'daily_dimensions' => array( 'PRIMARY', 'dimension_lookup' ),
			'exclusions_daily' => array( 'PRIMARY' ),
			'shadow_events' => array( 'PRIMARY', 'collect_key', 'pageview_id', 'recorded_at', 'ip_recorded', 'class_recorded', 'pipeline_recorded', 'mismatch_recorded' ),
		);

		foreach ( $required_columns as $key => $columns ) {
			$table = $tables[ $key ];
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $table !== $exists ) {
				return false;
			}
			$actual_columns = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ) );
			if ( array_diff( array_map( 'strtolower', $columns ), $actual_columns ) ) {
				return false;
			}
			if ( isset( $required_indexes[ $key ] ) ) {
				$actual_indexes = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW INDEX FROM `{$table}`", 2 ) );
				if ( array_diff( array_map( 'strtolower', $required_indexes[ $key ] ), array_unique( $actual_indexes ) ) ) {
					return false;
				}
			}
		}

		$shadow_table = $tables['shadow_events'];
		foreach ( array( 'collect_key', 'pageview_id', 'session_id' ) as $column ) {
			$definition = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$shadow_table}` LIKE %s", $column ) );
			if ( ! is_object( $definition ) || ! isset( $definition->Null ) || 'YES' !== strtoupper( (string) $definition->Null ) ) {
				return false;
			}
		}
		return true;
	}
}
