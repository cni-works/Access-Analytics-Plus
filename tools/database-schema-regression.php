<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

final class AAP_Test_WPDB {
	public string $prefix = 'wp_';
	/** @var array<string,string[]> */
	public array $columns;
	/** @var array<string,string[]> */
	public array $indexes;
	/** @var array<string,bool> */
	public array $nullable;

	public function __construct() {
		$this->columns = array(
			'wp_aap_pages' => array( 'id', 'url_hash', 'path' ),
			'wp_aap_sessions' => array( 'id', 'session_key', 'visitor_key', 'pageview_count', 'country_code', 'region_code' ),
			'wp_aap_pageviews' => array( 'id', 'session_id', 'page_id', 'viewed_at' ),
			'wp_aap_daily' => array( 'stat_date', 'visitors', 'visits', 'pageviews' ),
			'wp_aap_daily_dimensions' => array( 'stat_date', 'dimension_type', 'dimension_key' ),
			'wp_aap_exclusions_daily' => array( 'stat_date', 'reason', 'excluded_count' ),
			'wp_aap_shadow_events' => array(
				'id', 'collect_key', 'pageview_id', 'session_id', 'recorded_at', 'updated_at', 'ip_key', 'visitor_key', 'session_key',
				'ua_hash', 'path_hash', 'path', 'title', 'referrer_type', 'referrer_host', 'search_source',
				'ua_family', 'ua_device_type', 'reported_device_type', 'country_code', 'country_source', 'region_code', 'region_source',
				'tracker_build', 'build_mismatch', 'device_mismatch', 'webdriver_state', 'origin_present',
				'visible_confirmed', 'interaction_mask', 'engagement_received', 'ip_visitors_10m',
				'ua_visitors_10m', 'ua_path_requests_10m', 'visitor_is_new', 'regular_interval',
				'risk_score', 'risk_flags', 'shadow_class', 'finalized', 'aggregation_mode', 'pipeline_stage',
				'last_completed_stage', 'promotion_attempts', 'signal_received_at', 'promoted_at',
				'last_error_stage', 'last_error_type', 'last_error_message', 'last_error_at', 'exclusion_recorded',
			),
		);
		$this->indexes = array(
			'wp_aap_pages' => array( 'PRIMARY', 'url_hash' ),
			'wp_aap_sessions' => array( 'PRIMARY', 'session_key', 'region_report' ),
			'wp_aap_pageviews' => array( 'PRIMARY', 'session_id', 'viewed_at' ),
			'wp_aap_daily' => array( 'PRIMARY' ),
			'wp_aap_daily_dimensions' => array( 'PRIMARY', 'dimension_lookup' ),
			'wp_aap_exclusions_daily' => array( 'PRIMARY' ),
			'wp_aap_shadow_events' => array( 'PRIMARY', 'collect_key', 'pageview_id', 'recorded_at', 'ip_recorded', 'class_recorded', 'pipeline_recorded', 'mismatch_recorded' ),
		);
		$this->nullable = array(
			'collect_key' => true,
			'pageview_id' => true,
			'session_id'  => true,
		);
	}

	public function esc_like( string $value ): string { return $value; }
	public function prepare( string $query, mixed ...$args ): string { return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
	public function get_var( string $query ): string {
		preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $matches );
		return isset( $matches[1], $this->columns[ $matches[1] ] ) ? $matches[1] : '';
	}
	/** @return string[] */
	public function get_col( string $query, int $column = 0 ): array {
		unset( $column );
		preg_match( '/FROM `([^`]+)`/', $query, $matches );
		$table = $matches[1] ?? '';
		return str_starts_with( $query, 'SHOW COLUMNS' ) ? ( $this->columns[ $table ] ?? array() ) : ( $this->indexes[ $table ] ?? array() );
	}
	public function get_row( string $query ): ?object {
		preg_match( "/LIKE '([^']+)'/", $query, $matches );
		$column = $matches[1] ?? '';
		return isset( $this->nullable[ $column ] ) ? (object) array( 'Null' => $this->nullable[ $column ] ? 'YES' : 'NO' ) : null;
	}
}

function aap_schema_expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-database.php';
$GLOBALS['wpdb'] = new AAP_Test_WPDB();
$verify = new ReflectionMethod( AccessAnalyticsPlus\Database::class, 'verify_schema' );

aap_schema_expect( true === $verify->invoke( null ), 'a complete DB Version 9 schema is accepted' );
$GLOBALS['wpdb']->columns['wp_aap_shadow_events'] = array_values( array_diff( $GLOBALS['wpdb']->columns['wp_aap_shadow_events'], array( 'tracker_build' ) ) );
aap_schema_expect( false === $verify->invoke( null ), 'a missing required column prevents the DB version update' );
$GLOBALS['wpdb'] = new AAP_Test_WPDB();
$GLOBALS['wpdb']->columns['wp_aap_shadow_events'] = array_values( array_diff( $GLOBALS['wpdb']->columns['wp_aap_shadow_events'], array( 'path' ) ) );
aap_schema_expect( false === $verify->invoke( null ), 'a missing pre-DB6 staging column also fails the physical schema check' );
$GLOBALS['wpdb'] = new AAP_Test_WPDB();
$GLOBALS['wpdb']->indexes['wp_aap_shadow_events'] = array_values( array_diff( $GLOBALS['wpdb']->indexes['wp_aap_shadow_events'], array( 'pipeline_recorded' ) ) );
aap_schema_expect( false === $verify->invoke( null ), 'a missing required index prevents the DB version update' );
$GLOBALS['wpdb'] = new AAP_Test_WPDB();
$GLOBALS['wpdb']->nullable['pageview_id'] = false;
aap_schema_expect( false === $verify->invoke( null ), 'a non-nullable staged pageview ID prevents the DB version update' );

echo "Database schema regression checks passed.\n";
