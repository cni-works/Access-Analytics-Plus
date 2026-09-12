<?php

declare(strict_types=1);

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['aap_options'] = array( 'aap_region_tracking_started_at' => '2026-09-01 00:00:00' );
	function __( string $text, string $domain = '' ): string { return $text; }
	function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['aap_options'][ $name ] ?? $default; }
	function add_option( string $name, mixed $value, string $deprecated = '', bool $autoload = true ): bool { $GLOBALS['aap_options'][ $name ] = $value; return true; }
	function current_time( string $type, bool $gmt = false ): string { return '2026-09-12 00:00:00'; }
	function wp_timezone(): \DateTimeZone { return new \DateTimeZone( 'UTC' ); }
	function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?: ''; }
	function aap_region_analytics_expect( bool $condition, string $message ): void { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
}

namespace AccessAnalyticsPlus {
	final class Database { public static function tables(): array { return array( 'sessions' => 'wp_aap_sessions' ); } }
	final class Region_Resolver {
		public static function normalize( string $code ): string { return preg_match( '/^JP-(0[1-9]|[1-3][0-9]|4[0-7])$/', $code ) ? $code : ''; }
		public static function label( string $code ): string { return array( 'JP-13' => '東京都', 'JP-11' => '埼玉県' )[ $code ] ?? '判定不能'; }
	}
	final class AAP_Region_Analytics_WPDB {
		public string $last_query = '';
		public function prepare( string $query, mixed ...$args ): string { $this->last_query = vsprintf( str_replace( '%s', "'%s'", $query ), $args ); return $this->last_query; }
		public function get_results( string $query, string $format ): array {
			$this->last_query = $query;
			return array(
				array( 'region_code' => 'JP-13', 'visitors' => 2 ),
				array( 'region_code' => 'JP-11', 'visitors' => 1 ),
				array( 'region_code' => '', 'visitors' => 1 ),
			);
		}
	}
	$GLOBALS['wpdb'] = new AAP_Region_Analytics_WPDB();
	require_once dirname( __DIR__ ) . '/includes/class-analytics.php';
	$method = new \ReflectionMethod( Analytics::class, 'regions' );
	$result = $method->invoke( null, new \DateTimeImmutable( '2026-08-25 00:00:00', new \DateTimeZone( 'UTC' ) ), new \DateTimeImmutable( '2026-09-12 00:00:00', new \DateTimeZone( 'UTC' ) ) );
	aap_region_analytics_expect( 4 === $result['total'], 'region total uses unique visitor rows' );
	aap_region_analytics_expect( 50.0 === $result['items'][0]['percent'] && '東京都' === $result['items'][0]['label'], 'percent uses domestic visitor total' );
	aap_region_analytics_expect( 'unknown' === $result['items'][2]['key'] && 25.0 === $result['items'][2]['percent'], 'unknown JP region remains visible' );
	aap_region_analytics_expect( true === $result['partial'], 'period before tracking start is marked partial' );
	aap_region_analytics_expect( str_contains( $GLOBALS['wpdb']->last_query, "country_code = 'JP'" ), 'query includes JP only' );
	aap_region_analytics_expect( str_contains( $GLOBALS['wpdb']->last_query, 'MIN(id) AS first_session_id' ) && str_contains( $GLOBALS['wpdb']->last_query, 'GROUP BY visitor_key' ), 'query assigns first session per visitor' );
	aap_region_analytics_expect( ! str_contains( $GLOBALS['wpdb']->last_query, 'shadow_events' ), 'pending, bot, unconfirmed and geo-excluded staging rows cannot enter region analytics' );

	echo "Region analytics regression checks passed.\n";
}
