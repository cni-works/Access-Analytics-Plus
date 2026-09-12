<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$fixture_root = sys_get_temp_dir() . '/aap-search-console-' . bin2hex( random_bytes( 5 ) );
$plugin_root  = $fixture_root . '/plugins';
$plugin_file  = $plugin_root . '/google-site-kit/google-site-kit.php';
mkdir( dirname( $plugin_file ), 0777, true );

define( 'ABSPATH', $fixture_root . '/' );
define( 'WP_PLUGIN_DIR', $plugin_root );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

class WP_REST_Request {
	private array $params = array();
	public function __construct( public string $method = 'GET', public string $route = '' ) {}
	public function set_query_params( array $params ): void { $this->params = $params; }
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
	public function params(): array { return $this->params; }
}

class WP_REST_Response {
	public function __construct( private mixed $data = null, private int $status = 200 ) {}
	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
}

/** Site Kit 1.187.0が返すGoogle SearchAnalyticsQueryResponse行に近いJSON化可能オブジェクト。 */
final class AAP_Test_Google_Row implements JsonSerializable {
	/** @param array<string,mixed> $data */
	public function __construct( private array $data ) {}
	public function jsonSerialize(): mixed { return $this->data; }
}

final class AAP_Test_Rest_Server {
	public function get_routes(): array {
		global $aap_sc_env;
		return $aap_sc_env['routes'];
	}
}

$aap_sc_env = array();

function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
function is_plugin_active( string $plugin ): bool { global $aap_sc_env; unset( $plugin ); return $aap_sc_env['active']; }
function is_plugin_active_for_network( string $plugin ): bool { unset( $plugin ); return false; }
function get_file_data( string $file, array $headers, string $context = '' ): array {
	unset( $headers, $context );
	$contents = file_get_contents( $file );
	preg_match( '/^\s*\*?\s*Version:\s*(\S+)/mi', (string) $contents, $match );
	return array( 'version' => $match[1] ?? '' );
}
function rest_get_server(): AAP_Test_Rest_Server { return new AAP_Test_Rest_Server(); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function current_user_can( string $capability, mixed ...$args ): bool {
	global $aap_sc_env;
	unset( $args );
	return 'googlesitekit_view_authenticated_dashboard' === $capability
		? $aap_sc_env['direct_access']
		: ( 'googlesitekit_view_posts_insights' === $capability
			? $aap_sc_env['insights_access']
			: ( 'googlesitekit_read_shared_module_data' === $capability && $aap_sc_env['shared_access'] ) );
}
function get_current_user_id(): int { global $aap_sc_env; return $aap_sc_env['user_id']; }
function get_current_blog_id(): int { return 1; }
function current_datetime(): DateTimeImmutable { return new DateTimeImmutable( '2026-09-12 12:00:00', new DateTimeZone( 'Asia/Tokyo' ) ); }
function get_transient( string $key ): mixed { global $aap_sc_env; return $aap_sc_env['transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $ttl ): bool {
	global $aap_sc_env;
	$aap_sc_env['transients'][ $key ] = $value;
	$aap_sc_env['transient_ttls'][ $key ] = $ttl;
	return true;
}
function rest_do_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	global $aap_sc_env;
	$aap_sc_env['requests'][] = array( 'route' => $request->route, 'params' => $request->params() );
	if ( str_contains( $request->route, '/core/modules/' ) ) {
		return $aap_sc_env['module_response'];
	}
	$aap_sc_env['report_calls']++;
	return $aap_sc_env['report_response'];
}

require_once dirname( __DIR__ ) . '/includes/class-search-console-service.php';

use AccessAnalyticsPlus\Search_Console_Service;

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function reset_environment(): void {
	global $aap_sc_env;
	$aap_sc_env = array(
		'active'          => true,
		'routes'          => array(
			'/google-site-kit/v1/core/modules/data/list' => true,
			'/google-site-kit/v1/modules/(?P<slug>[a-z0-9\-]+)/data/(?P<datapoint>[a-z\-]+)' => true,
		),
		'direct_access'   => true,
		'insights_access' => false,
		'shared_access'   => false,
		'user_id'         => 7,
		'module_response' => new WP_REST_Response(
			array( array( 'slug' => 'search-console', 'active' => true, 'connected' => true ) )
		),
		'report_response' => new WP_REST_Response(
			array(
				array( 'keys' => array( '屋根修理 武蔵村山' ), 'clicks' => 4, 'impressions' => 80, 'ctr' => 0.05, 'position' => 6.2 ),
				array( 'keys' => array( '<b>雨漏り修理</b>' ), 'clicks' => 2, 'impressions' => 35, 'ctr' => 0.0571, 'position' => 8.4 ),
			)
		),
		'report_calls'    => 0,
		'requests'        => array(),
		'transients'      => array(),
		'transient_ttls'  => array(),
	);
}

function install_fixture( string $version = '1.186.0' ): void {
	global $plugin_file;
	file_put_contents( $plugin_file, "<?php\n/**\n * Plugin Name: Site Kit by Google\n * Version: {$version}\n */\n" );
}

reset_environment();
expect( false === Search_Console_Service::is_site_kit_installed(), 'Site Kit installation probe is false when plugin file is absent' );
$missing = Search_Console_Service::get_report();
expect( 'site_kit_missing' === $missing['status'], 'missing Site Kit state' );

install_fixture();
expect( true === Search_Console_Service::is_site_kit_installed(), 'Site Kit installation probe uses the plugin file without reading credentials' );
$aap_sc_env['active'] = false;
$inactive = Search_Console_Service::get_report();
expect( 'site_kit_inactive' === $inactive['status'], 'inactive Site Kit state' );

reset_environment();
$aap_sc_env['routes'] = array();
$route_missing = Search_Console_Service::get_report();
expect( 'route_unavailable' === $route_missing['status'], 'missing internal route state' );

reset_environment();
$aap_sc_env['module_response'] = new WP_REST_Response( array( array( 'slug' => 'search-console', 'active' => true, 'connected' => false ) ) );
$disconnected = Search_Console_Service::get_report();
expect( 'search_console_not_connected' === $disconnected['status'], 'disconnected Search Console state' );

reset_environment();
$aap_sc_env['direct_access'] = false;
$denied = Search_Console_Service::get_report();
expect( 'permission_denied' === $denied['status'] && 0 === $aap_sc_env['report_calls'], 'permission checked before report request' );

reset_environment();
$report = Search_Console_Service::get_report( '7d' );
expect( 'ready' === $report['status'], 'successful report state' );
expect( ! isset( $aap_sc_env['routes']['/google-site-kit/v1/modules/search-console/data/searchanalytics'] ), 'generic Site Kit route does not require an exact concrete key' );
expect( '2026-09-05' === $report['period']['start'] && '2026-09-11' === $report['period']['end'], 'seven-day range ends yesterday' );
expect( 2 === count( $report['rows'] ) && '雨漏り修理' === $report['rows'][1]['query'], 'rows normalized and HTML removed' );
expect( 0.05 === $report['rows'][0]['ctr'] && 6.2 === $report['rows'][0]['position'], 'Search Console metrics preserved' );
$last_request = end( $aap_sc_env['requests'] );
expect( array( 'query' ) === $last_request['params']['dimensions'] && 20 === $last_request['params']['limit'], 'query-only request and fixed limit' );
expect( false === $report['cache']['hit'] && 7200 === $report['cache']['ttl_seconds'], 'short two-hour cache' );
expect( 'array' === $report['diagnostic']['top_level_type'] && 'array' === $report['diagnostic']['row_type'], 'ordinary array response diagnostics preserved' );
expect( 2 === $report['diagnostic']['row_count'] && 'valid' === $report['diagnostic']['schema_result'], 'ordinary array response schema validated' );

$cached = Search_Console_Service::get_report( '7d' );
expect( true === $cached['cache']['hit'] && 1 === $aap_sc_env['report_calls'], 'same user and period uses cache' );
$aap_sc_env['user_id'] = 8;
$other_user = Search_Console_Service::get_report( '7d' );
expect( false === $other_user['cache']['hit'] && 2 === $aap_sc_env['report_calls'], 'cache is isolated by user' );

reset_environment();
$aap_sc_env['direct_access'] = false;
$aap_sc_env['insights_access'] = true;
$insights = Search_Console_Service::get_report();
expect( 'ready' === $insights['status'], 'Site Kit posts insights permission supported' );

reset_environment();
$aap_sc_env['direct_access'] = false;
$aap_sc_env['shared_access'] = true;
$shared = Search_Console_Service::get_report();
expect( 'ready' === $shared['status'], 'Site Kit dashboard sharing permission supported' );

reset_environment();
$aap_sc_env['report_response'] = new WP_REST_Response(
	array(
		new AAP_Test_Google_Row(
			array(
				'keys'        => array( '外壁工事 武蔵村山' ),
				'clicks'      => 3,
				'impressions' => 64,
				'ctr'         => 0.046875,
				'position'    => 7.3,
			)
		),
	)
);
$object_rows = Search_Console_Service::get_report();
expect( 'ready' === $object_rows['status'], 'nested Google row objects are accepted' );
expect( '外壁工事 武蔵村山' === $object_rows['rows'][0]['query'], 'nested Google row object query normalized' );
expect( 3.0 === $object_rows['rows'][0]['clicks'] && 64.0 === $object_rows['rows'][0]['impressions'], 'nested Google row object metrics preserved' );
expect( 0.046875 === $object_rows['rows'][0]['ctr'] && 7.3 === $object_rows['rows'][0]['position'], 'nested Google row object rate and position preserved' );
expect( 'array' === $object_rows['diagnostic']['top_level_type'] && 'object' === $object_rows['diagnostic']['row_type'], 'nested object types exposed without object contents' );
expect( 1 === $object_rows['diagnostic']['row_count'] && 'valid' === $object_rows['diagnostic']['schema_result'], 'nested object schema diagnostics are valid' );

reset_environment();
$aap_sc_env['report_response'] = new WP_REST_Response( array( 'code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.' ), 404 );
$missing_report_route = Search_Console_Service::get_report();
expect( 'route_unavailable' === $missing_report_route['status'], 'actual missing report route is mapped after dispatch' );

reset_environment();
$aap_sc_env['report_response'] = new WP_REST_Response( array( 'code' => 'missing_required_scopes', 'message' => 'Reconnect required' ), 401 );
$reauth = Search_Console_Service::get_report();
expect( 'reauth_required' === $reauth['status'], 'reauthentication error mapped' );

reset_environment();
$aap_sc_env['report_response'] = new WP_Error( 'rest_forbidden', 'Permission denied' );
$forbidden = Search_Console_Service::get_report();
expect( 'permission_denied' === $forbidden['status'], 'REST permission error mapped' );

reset_environment();
$aap_sc_env['report_response'] = new WP_Error( 'http_request_failed', 'Connection timed out' );
$temporary = Search_Console_Service::get_report();
expect( 'temporary_error' === $temporary['status'], 'communication failure remains a temporary error' );
expect( 'not_received' === $temporary['diagnostic']['top_level_type'] && 'not_evaluated' === $temporary['diagnostic']['schema_result'], 'communication failure is distinct from schema evaluation' );

reset_environment();
$aap_sc_env['report_response'] = new WP_REST_Response( array( 'unexpected' => true ) );
$schema = Search_Console_Service::get_report();
expect( 'schema_error' === $schema['status'], 'unexpected Site Kit schema contained' );
expect( str_contains( $schema['message'], '形式' ), 'schema error has a distinct user-facing message' );
expect( 'array' === $schema['diagnostic']['top_level_type'] && 'invalid_rows_container' === $schema['diagnostic']['schema_result'], 'schema diagnostics expose only shape and result' );

reset_environment();
$aap_sc_env['report_response'] = new WP_REST_Response(
	array(
		new AAP_Test_Google_Row( array( 'keys' => array( '不完全な行' ), 'clicks' => 1 ) ),
	)
);
$object_schema = Search_Console_Service::get_report();
expect( 'schema_error' === $object_schema['status'], 'malformed nested Google row object is rejected safely' );
expect( 'object' === $object_schema['diagnostic']['row_type'] && 'invalid_row' === $object_schema['diagnostic']['schema_result'], 'malformed object row diagnostic preserves only its type' );

reset_environment();
$first = Search_Console_Service::get_report();
expect( 'ready' === $first['status'], 'cache setup succeeds' );
$aap_sc_env['active'] = false;
$after_deactivation = Search_Console_Service::get_report();
expect( 'site_kit_inactive' === $after_deactivation['status'], 'plugin state is rechecked before cached data' );

reset_environment();
install_fixture( '2.0.0' );
$unverified = Search_Console_Service::get_report( 'invalid-range' );
expect( 'ready' === $unverified['status'], 'unverified Site Kit version remains fail-soft' );
expect( 'unverified' === $unverified['site_kit']['compatibility'], 'unverified version is exposed diagnostically' );
expect( '28d' === $unverified['period']['key'], 'invalid range normalizes to 28 days' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-search-console-service.php' );
expect( ! str_contains( (string) $source, 'get_user_meta(' ) && ! str_contains( (string) $source, 'get_option(' ), 'adapter does not read Site Kit storage' );
expect( ! str_contains( (string) $source, 'Google\\Site_Kit' ), 'adapter does not instantiate Site Kit internals' );

@unlink( $plugin_file );
@rmdir( dirname( $plugin_file ) );
@rmdir( $plugin_root );
@rmdir( $fixture_root );

echo "Search Console Site Kit regression checks passed.\n";
