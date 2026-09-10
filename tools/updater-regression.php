<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

final class WP_Error {}

$test_filters    = array();
$test_actions    = array();
$test_transients = array();
$test_http       = array( 'code' => 500, 'body' => '' );
$test_requests   = array();
$test_is_admin   = true;
$test_can_update = true;

function wp_parse_args( $args, $defaults = array() ): array {
	return array_merge( $defaults, $args );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ): void {
	global $test_actions;
	$test_actions[] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ): void {
	global $test_filters;
	$test_filters[] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function untrailingslashit( $value ): string {
	return rtrim( (string) $value, '/\\' );
}

function get_site_transient( $key ) {
	global $test_transients;
	return $test_transients[ $key ]['value'] ?? false;
}

function set_site_transient( $key, $value, $expiration ): bool {
	global $test_transients;
	$test_transients[ $key ] = array( 'value' => $value, 'expiration' => $expiration );
	return true;
}

function delete_site_transient( $key ): bool {
	global $test_transients;
	unset( $test_transients[ $key ] );
	return true;
}

function wp_safe_remote_get( $url, $args ) {
	global $test_http, $test_requests;
	$test_requests[] = array( 'url' => $url, 'args' => $args );
	return $test_http;
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function wp_remote_retrieve_response_code( $response ): int {
	return is_array( $response ) ? (int) ( $response['code'] ?? 0 ) : 0;
}

function wp_remote_retrieve_body( $response ): string {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}

function home_url( $path = '' ): string {
	return 'https://example.test' . $path;
}

function wp_http_validate_url( $url ): bool {
	return false !== filter_var( $url, FILTER_VALIDATE_URL );
}

function is_admin(): bool {
	global $test_is_admin;
	return $test_is_admin;
}

function current_user_can( $capability ): bool {
	global $test_can_update;
	return $test_can_update && in_array( $capability, array( 'update_plugins', 'update_themes' ), true );
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function wp_unslash( $value ) {
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/updater/class-github-release-updater.php';

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function config( array $overrides = array() ): array {
	return array_merge(
		array(
			'type'          => 'plugin',
			'owner'         => 'cni-works',
			'repository'    => 'Access-Analytics-Plus',
			'slug'          => 'access-analytics-plus',
			'plugin_file'   => 'access-analytics-plus/access-analytics-plus.php',
			'version'       => '0.1.2',
			'update_uri'    => 'https://github.com/cni-works/Access-Analytics-Plus',
			'requires'      => '6.8',
			'requires_php'  => '8.1',
			'cache_hours'   => 12,
			'failure_hours' => 1,
			'timeout'       => 5,
			'include_prereleases' => false,
		),
		$overrides
	);
}

function asset( string $version = '0.1.3', string $host = 'github.com' ): array {
	return array(
		'name'                 => 'access-analytics-plus-' . $version . '.zip',
		'state'                => 'uploaded',
		'browser_download_url' => 'https://' . $host . '/cni-works/Access-Analytics-Plus/releases/download/v' . $version . '/access-analytics-plus-' . $version . '.zip',
	);
}

function release_body( string $version = '0.1.3', array $overrides = array() ): string {
	return json_encode(
		array_merge(
			array(
				'draft'      => false,
				'prerelease' => false,
				'tag_name'   => 'v' . $version,
				'html_url'   => 'https://github.com/cni-works/Access-Analytics-Plus/releases/tag/v' . $version,
				'assets'     => array( asset( $version ) ),
			),
			$overrides
		),
		JSON_THROW_ON_ERROR
	);
}

function release_list_body( array $releases ): string {
	return json_encode(
		array_map(
			static function ( string $release ): array {
				return json_decode( $release, true, 512, JSON_THROW_ON_ERROR );
			},
			$releases
		),
		JSON_THROW_ON_ERROR
	);
}

function reset_state(): void {
	global $test_filters, $test_actions, $test_transients, $test_requests, $test_http, $test_is_admin, $test_can_update;
	$test_filters    = array();
	$test_actions    = array();
	$test_transients = array();
	$test_requests   = array();
	$test_http       = array( 'code' => 500, 'body' => '' );
	$test_is_admin   = true;
	$test_can_update = true;
}

function filter_result( array $configuration = array() ) {
	$updater = new \CniWorks\AccessAnalyticsPlus\Updater\GitHub_Release_Updater( config( $configuration ) );
	return $updater->filter_plugin_update(
		false,
		array( 'UpdateURI' => 'https://github.com/cni-works/Access-Analytics-Plus' ),
		'access-analytics-plus/access-analytics-plus.php',
		array( 'ja' )
	);
}

reset_state();
$test_http = array( 'code' => 200, 'body' => release_body() );
$result    = filter_result();
expect( '0.1.3' === $result['new_version'], 'New release version must be returned.' );
expect( str_ends_with( $result['package'], 'access-analytics-plus-0.1.3.zip' ), 'Dedicated asset must be selected.' );
expect( 'access-analytics-plus' === $result['slug'], 'Plugin slug must be returned.' );
expect( 1 === count( $test_requests ), 'A valid uncached request must call GitHub once.' );
expect( 'https://api.github.com/repos/cni-works/Access-Analytics-Plus/releases/latest' === $test_requests[0]['url'], 'API endpoint mismatch.' );
expect( 5 === $test_requests[0]['args']['timeout'], 'Timeout must be five seconds.' );
expect( 3 === $test_requests[0]['args']['redirection'], 'Redirect limit must be three.' );
expect( '2022-11-28' === $test_requests[0]['args']['headers']['X-GitHub-Api-Version'], 'GitHub API version mismatch.' );
expect( 'update_plugins_github.com' === $test_filters[0]['hook'], 'Dynamic Update URI filter must be registered.' );

reset_state();
$test_http = array(
	'code' => 200,
	'body' => release_list_body(
		array(
			release_body( '0.1.3' ),
			release_body( '0.5.0-beta', array( 'prerelease' => true ) ),
		)
	),
);
$result = filter_result( array( 'include_prereleases' => true ) );
expect( '0.5.0-beta' === $result['new_version'], 'Beta channel must select a newer validated prerelease.' );
expect( str_ends_with( $result['package'], 'access-analytics-plus-0.5.0-beta.zip' ), 'Beta channel must select the dedicated prerelease asset.' );
expect( 'https://api.github.com/repos/cni-works/Access-Analytics-Plus/releases?per_page=20' === $test_requests[0]['url'], 'Beta API endpoint mismatch.' );

reset_state();
$test_http = array(
	'code' => 200,
	'body' => release_list_body(
		array(
			release_body( '0.5.1-beta', array( 'prerelease' => true ) ),
			release_body( '0.5.0-beta', array( 'prerelease' => true ) ),
		)
	),
);
$result = filter_result( array( 'version' => '0.5.0-beta', 'include_prereleases' => true ) );
expect( '0.5.1-beta' === $result['new_version'], 'Installed beta must receive a newer beta update.' );

reset_state();
$test_http = array( 'code' => 200, 'body' => release_body( '0.1.2' ) );
$result    = filter_result();
expect( '0.1.2' === $result['new_version'], 'Same version must return local metadata.' );
expect( ! isset( $result['package'] ), 'No-update metadata must not contain a package.' );
expect( 'access-analytics-plus/access-analytics-plus.php' === $result['plugin'], 'No-update metadata must contain plugin basename.' );

$invalid_releases = array(
	array( 'code' => 500, 'body' => '' ),
	array( 'code' => 200, 'body' => '{invalid' ),
	array( 'code' => 200, 'body' => release_body( '0.1.3', array( 'draft' => true ) ) ),
	array( 'code' => 200, 'body' => release_body( '0.1.3', array( 'prerelease' => true ) ) ),
	array( 'code' => 200, 'body' => release_body( '0.1.3', array( 'tag_name' => '0.1.3' ) ) ),
	array( 'code' => 200, 'body' => release_body( '0.1.3', array( 'tag_name' => 'v01.1.3' ) ) ),
	array( 'code' => 200, 'body' => release_body( '0.1.3', array( 'assets' => array() ) ) ),
	array( 'code' => 200, 'body' => release_body( '0.1.3', array( 'assets' => array( asset(), asset() ) ) ) ),
	array( 'code' => 200, 'body' => release_body( '0.1.3', array( 'assets' => array( asset( '0.1.4' ) ) ) ) ),
	array( 'code' => 200, 'body' => release_body( '0.1.3', array( 'assets' => array( array_merge( asset(), array( 'state' => 'new' ) ) ) ) ) ),
	array( 'code' => 200, 'body' => release_body( '0.1.3', array( 'assets' => array( asset( '0.1.3', 'evil.example' ) ) ) ) ),
);
foreach ( $invalid_releases as $index => $invalid_response ) {
	reset_state();
	$test_http = $invalid_response;
	$result    = filter_result();
	expect( '0.1.2' === $result['new_version'] && ! isset( $result['package'] ), 'Invalid release must fail safely: ' . $index );
	$cached = reset( $test_transients );
	expect( 3600 === $cached['expiration'], 'Failures must be cached for one hour: ' . $index );
}

reset_state();
$test_http = new WP_Error();
$result    = filter_result();
expect( '0.1.2' === $result['new_version'] && ! isset( $result['package'] ), 'WP_Error must fail safely.' );

reset_state();
$test_http = array( 'code' => 200, 'body' => release_body() );
$updater   = new \CniWorks\AccessAnalyticsPlus\Updater\GitHub_Release_Updater( config() );
$mismatch  = $updater->filter_plugin_update(
	false,
	array( 'UpdateURI' => 'https://github.com/cni-works/Other' ),
	'access-analytics-plus/access-analytics-plus.php',
	array()
);
expect( false === $mismatch && 0 === count( $test_requests ), 'Update URI mismatch must not query GitHub.' );

$wrong_file = $updater->filter_plugin_update(
	false,
	array( 'UpdateURI' => 'https://github.com/cni-works/Access-Analytics-Plus' ),
	'other/plugin.php',
	array()
);
expect( false === $wrong_file && 0 === count( $test_requests ), 'Other plugin basename must not query GitHub.' );

reset_state();
new \CniWorks\AccessAnalyticsPlus\Updater\GitHub_Release_Updater( config( array( 'plugin_file' => 'wrong/access-analytics-plus.php' ) ) );
expect( 0 === count( $test_filters ), 'Invalid installation directory must not register a filter.' );

reset_state();
$test_http = array( 'code' => 200, 'body' => release_body() );
$first     = filter_result();
$test_http = array( 'code' => 500, 'body' => '' );
$second    = filter_result();
expect( '0.1.3' === $first['new_version'] && '0.1.3' === $second['new_version'], 'Success cache must preserve a validated release.' );
expect( 1 === count( $test_requests ), 'Success cache must avoid a second HTTP request.' );
$cached = reset( $test_transients );
expect( 43200 === $cached['expiration'], 'Success cache must last 12 hours.' );

reset_state();
$test_http = array( 'code' => 500, 'body' => '' );
$first     = filter_result();
$test_http = array( 'code' => 200, 'body' => release_body() );
$second    = filter_result();
expect( '0.1.2' === $first['new_version'] && '0.1.2' === $second['new_version'], 'Failure cache must return safe local metadata.' );
expect( 1 === count( $test_requests ), 'Failure cache must avoid repeated HTTP requests.' );

$cache_key = 'cniworks_gh_release_' . md5( 'cni-works/access-analytics-plus' );
expect( isset( $test_transients[ $cache_key ] ), 'Repository-specific cache key must exist.' );
$_GET['force-check'] = '1';
$updater = new \CniWorks\AccessAnalyticsPlus\Updater\GitHub_Release_Updater( config() );
$updater->maybe_clear_cache_for_forced_check();
expect( ! isset( $test_transients[ $cache_key ] ), 'Forced check with permission must clear only this cache.' );
unset( $_GET['force-check'] );

echo "Updater regression checks passed.\n";
