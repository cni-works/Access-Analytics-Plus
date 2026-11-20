<?php

declare(strict_types=1);

namespace {
	const ARRAY_A = 'ARRAY_A';
	const MINUTE_IN_SECONDS = 60;
	const DAY_IN_SECONDS = 86400;
	const AAP_BUILD = 'test.build';
	final class WP_Error { public function __construct( public string $code, public string $message = '', public mixed $data = null ) {} }
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	final class WP_REST_Request {
		public function __construct( private array $params ) {}
		public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
	}
	final class WP_REST_Response {
		public array $headers = array();
		public function __construct( public mixed $data, public int $status ) {}
		public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
	}
	function sanitize_text_field( string $value ): string { return trim( $value ); }
	function __( string $value, string $domain = '' ): string { return $value; }
	$GLOBALS['aap_options'] = array(
			'aap_retention_days' => 90,
			'aap_engagement_started_at' => '2026-01-01 00:00:00',
			'aap_region_tracking_started_at' => '2026-01-01 00:00:00',
			'aap_confirmation_started_at' => '2026-01-01 00:00:00',
	);
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['aap_options'][ $key ] ?? $default;
	}
	$GLOBALS['aap_timezone'] = new \DateTimeZone( 'Asia/Tokyo' );
	$GLOBALS['aap_now'] = '2026-09-15 12:00:00';
	function wp_timezone(): \DateTimeZone { return $GLOBALS['aap_timezone']; }
	function wp_timezone_string(): string { return wp_timezone()->getName(); }
	function current_datetime(): \DateTimeImmutable { return new \DateTimeImmutable( $GLOBALS['aap_now'], wp_timezone() ); }
	$GLOBALS['aap_transients'] = array();
	function get_transient( string $key ): mixed { return $GLOBALS['aap_transients'][ $key ] ?? false; }
	function set_transient( string $key, mixed $value, int $ttl ): bool { $GLOBALS['aap_transients'][ $key ] = $value; return true; }
}

namespace AccessAnalyticsPlus {
	// Keep the seven-day diagnostics window deterministic instead of depending on the real test date.
	function time(): int { return ( new \DateTimeImmutable( '2026-09-15 12:00:00', new \DateTimeZone( 'Asia/Tokyo' ) ) )->getTimestamp(); }
	final class Settings { public static bool $sample = false; public static function sample_enabled(): bool { return self::$sample; } }
	final class Database { public static function tables(): array { return array( 'pageviews' => 'wp_aap_pageviews', 'sessions' => 'wp_aap_sessions', 'pages' => 'wp_aap_pages', 'shadow_events' => 'wp_aap_shadow_events' ); } }
	final class Country_Resolver { public static function normalize( string $code ): string { $code = strtoupper( trim( $code ) ); return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : 'ZZ'; } }
	final class Region_Resolver { public static function normalize( string $code ): string { return preg_match( '/^JP-\d{2}$/', $code ) ? $code : ''; } public static function label( string $code ): string { return 'JP-13' === $code ? '東京都' : '判定不能'; } }
	final class Shadow_Diagnostics { public const RETENTION_DAYS = 7; }
	final class FakeWpdb {
		public array $queries = array();
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) { $query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 ); }
			return $query;
		}
		public function get_row( string $query, string $format ): array {
			$this->queries[] = $query;
			if ( str_contains( $query, 'COUNT(DISTINCT s.visitor_key)' ) ) return array( 'visitors' => 2, 'visits' => 2, 'pageviews' => 3 );
			return array( 'ended_visits' => 2, 'engaged_seconds' => 80, 'bounces' => 1 );
		}
		public function get_results( string $query, string $format ): array {
			$this->queries[] = $query;
			if ( str_contains( $query, 'p.title' ) ) return array( array( 'title' => '会社案内', 'total' => 2 ) );
			if ( str_contains( $query, 'shadow_class item_key' ) ) return array( array( 'item_key' => 'pending', 'total' => 1 ), array( 'item_key' => 'unconfirmed', 'total' => 2 ) );
			if ( str_contains( $query, "shadow_class='geo_excluded'" ) ) return array( array( 'item_key' => 'US', 'total' => 1 ) );
			if ( str_contains( $query, 's.region_code' ) ) return array( array( 'item_key' => 'JP-13', 'total' => 2 ) );
			if ( str_contains( $query, 's.country_code' ) ) return array( array( 'item_key' => 'JP', 'total' => 2 ) );
			if ( str_contains( $query, 's.device_type' ) ) return array( array( 'item_key' => 'mobile', 'total' => 2 ) );
			if ( str_contains( $query, 's.referrer_type' ) ) return array( array( 'item_key' => 'search', 'total' => 1 ), array( 'item_key' => 'direct', 'total' => 1 ) );
			return array();
		}
	}
	$GLOBALS['wpdb'] = new FakeWpdb();
	require dirname( __DIR__ ) . '/includes/class-hourly-detail.php';

	function assert_true( bool $condition, string $message ): void { if ( ! $condition ) { throw new \RuntimeException( $message ); } }

	$response = Hourly_Detail::rest_report( new \WP_REST_Request( array( 'date' => '2026-09-14', 'hour' => 10 ) ) );
	assert_true( $response instanceof \WP_REST_Response, 'valid request returns response' );
	assert_true( 2 === $response->data['overview']['visitors'] && 3 === $response->data['overview']['pageviews'], 'overview aggregate' );
	assert_true( 40 === $response->data['quality']['average_engaged_seconds'] && 50.0 === $response->data['quality']['bounce_rate'], 'ended-visit quality' );
	assert_true( '東京都' === $response->data['regions']['items'][0]['label'], 'prefecture aggregate' );
	assert_true( 3 === $response->data['diagnostics']['total'], 'shadow classes remain diagnostic only' );
	assert_true( 'private, no-store, max-age=0' === $response->headers['Cache-Control'], 'browser cache disabled' );
	$encoded = json_encode( $response->data );
	foreach ( array( 'visitor_key', 'session_id', 'ip_key', 'recorded_at', 'path' ) as $forbidden ) assert_true( ! str_contains( $encoded, $forbidden ), 'privacy field omitted: ' . $forbidden );
	assert_true( count( $GLOBALS['aap_transients'] ) === 1, 'aggregate cached' );
	assert_true( count( $GLOBALS['wpdb']->queries ) <= 9, 'bounded query count' );
	assert_true( str_contains( $GLOBALS['wpdb']->queries[0], "viewed_at >= '2026-09-14 01:00:00'" ) && str_contains( $GLOBALS['wpdb']->queries[0], "viewed_at < '2026-09-14 02:00:00'" ), 'WordPress timezone converted to UTC boundaries' );
	$again = Hourly_Detail::rest_report( new \WP_REST_Request( array( 'date' => '2026-09-14', 'hour' => 10 ) ) );
	assert_true( true === $again->data['cache_hit'], 'repeat request uses transient' );

	$future = Hourly_Detail::rest_report( new \WP_REST_Request( array( 'date' => '2026-09-16', 'hour' => 0 ) ) );
	assert_true( $future instanceof \WP_Error && 'aap_future_hour' === $future->code, 'future rejected' );
	$invalid = Hourly_Detail::rest_report( new \WP_REST_Request( array( 'date' => '2026-02-30', 'hour' => 4 ) ) );
	assert_true( $invalid instanceof \WP_Error, 'invalid calendar date rejected' );
	$old = Hourly_Detail::rest_report( new \WP_REST_Request( array( 'date' => '2026-09-01', 'hour' => 4 ) ) );
	assert_true( false === $old->data['diagnostics']['available'] && ! isset( $old->data['diagnostics']['counts'] ), 'expired shadow detail is unavailable, never zero-filled' );
	$GLOBALS['aap_options']['aap_region_tracking_started_at'] = '2026-09-14 02:30:00';
	$partial_region = Hourly_Detail::rest_report( new \WP_REST_Request( array( 'date' => '2026-09-14', 'hour' => 11 ) ) );
	assert_true( true === $partial_region->data['regions']['partial'], 'region tracking boundary reported as partial' );

	Settings::$sample = true;
	$sample = Hourly_Detail::rest_report( new \WP_REST_Request( array( 'date' => '2026-09-14', 'hour' => 10 ) ) );
	assert_true( false === $sample->data['available'] && true === $sample->data['sample'], 'sample mode never queries real detail' );

	Settings::$sample = false;
	$GLOBALS['aap_timezone'] = new \DateTimeZone( 'America/New_York' );
	$dst_gap = Hourly_Detail::rest_report( new \WP_REST_Request( array( 'date' => '2026-03-08', 'hour' => 2 ) ) );
	assert_true( $dst_gap instanceof \WP_Error && 'aap_invalid_hour' === $dst_gap->code, 'DST gap rejected explicitly' );
	$GLOBALS['aap_now'] = '2026-11-02 12:00:00';
	$before_fall = count( $GLOBALS['wpdb']->queries );
	$dst_repeat = Hourly_Detail::rest_report( new \WP_REST_Request( array( 'date' => '2026-11-01', 'hour' => 1 ) ) );
	assert_true( $dst_repeat instanceof \WP_REST_Response, 'DST repeated hour accepted' );
	$fall_queries = array_slice( $GLOBALS['wpdb']->queries, $before_fall );
	assert_true( str_contains( $fall_queries[0], "viewed_at >= '2026-11-01 05:00:00'" ) && str_contains( $fall_queries[0], "viewed_at < '2026-11-01 07:00:00'" ), 'DST repeated clock hour includes both UTC hours' );

	$rest_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-rest-controller.php' );
	assert_true( str_contains( $rest_source, "'/hour-detail'" ) && str_contains( $rest_source, "current_user_can( Capabilities::VIEW )" ), 'route requires analytics capability' );
	assert_true( str_contains( $rest_source, "'minimum' => 0" ) && str_contains( $rest_source, "'maximum' => 23" ), 'REST hour schema bounded' );

	echo "Hourly detail regression passed.\n";
}
