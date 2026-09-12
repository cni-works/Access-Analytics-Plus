<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI || ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tools/wordpress-promotion-integration.php\n" );
	exit( 1 );
}
if ( '1' !== getenv( 'AAP_ALLOW_INTEGRATION_TEST' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	fwrite( STDERR, "Refusing to modify this database. Use a disposable local/development WordPress DB and AAP_ALLOW_INTEGRATION_TEST=1.\n" );
	exit( 1 );
}

if ( ! class_exists( AccessAnalyticsPlus\Tracker::class ) ) {
	fwrite( STDERR, "Access Analytics Plus must be active.\n" );
	exit( 1 );
}

function aap_integration_expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array{token:string,event_id:int,path:string} */
function aap_integration_collect( string $suffix, string $tracker_build = AAP_BUILD ): array {
	$uuid = wp_generate_uuid4();
	$path = '/aap-integration-' . sanitize_key( $suffix ) . '-' . $uuid . '/';
	$request = new WP_REST_Request( 'POST', '/access-analytics-plus/v1/collect' );
	$request->set_header( 'Origin', home_url() );
	$request->set_body_params(
		array(
			'collect_id' => wp_generate_uuid4(),
			'visitor_id' => $uuid,
			'session_id' => wp_generate_uuid4(),
			'path' => $path,
			'title' => 'AAP integration test',
			'referrer' => '',
			'device_type' => 'mobile',
			'webdriver' => 0,
			'tracker_build' => $tracker_build,
		)
	);
	$response = rest_do_request( $request );
	$data = $response->get_data();
	aap_integration_expect( 201 === $response->get_status(), 'collect did not return 201' );
	aap_integration_expect( ! empty( $data['accepted'] ) && is_string( $data['engagement_token'] ?? null ), 'collect did not create a staged token' );
	aap_integration_expect( 1 === preg_match( '/^s\.(\d+)\./', $data['engagement_token'], $matches ), 'collect returned an unexpected token' );
	return array( 'token' => $data['engagement_token'], 'event_id' => (int) $matches[1], 'path' => $path );
}

function aap_integration_signal( string $token ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/access-analytics-plus/v1/shadow-signal' );
	$request->set_body_params( array( 'token' => $token, 'visible_confirmed' => true, 'interaction_mask' => 1 ) );
	return rest_do_request( $request );
}

function aap_integration_cleanup( int $event_id, string $path ): void {
	global $wpdb;
	$tables = AccessAnalyticsPlus\Database::tables();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['shadow_events']} WHERE id=%d", $event_id ), ARRAY_A );
	$page_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['pages']} WHERE url_hash=%s", hash( 'sha256', $path ) ) );
	if ( $row ) {
		$pageview_id = (int) $row['pageview_id'];
		$session_id = (int) $row['session_id'];
		if ( $pageview_id > 0 ) {
			$wpdb->delete( $tables['pageviews'], array( 'id' => $pageview_id ), array( '%d' ) );
		}
		if ( $session_id > 0 ) {
			$viewed_at = new DateTimeImmutable( (string) $row['recorded_at'], new DateTimeZone( 'UTC' ) );
			$stat_date = $viewed_at->setTimezone( wp_timezone() )->format( 'Y-m-d' );
			$wpdb->delete( $tables['daily_dimensions'], array( 'stat_date' => $stat_date, 'dimension_type' => 'unique_visit', 'dimension_key' => (string) $session_id ) );
			$wpdb->delete( $tables['daily_dimensions'], array( 'stat_date' => $stat_date, 'dimension_type' => 'unique_visitor', 'dimension_key' => (string) $row['visitor_key'] ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$tables['daily']} SET visitors=GREATEST(0,visitors-1),visits=GREATEST(0,visits-1),pageviews=GREATEST(0,pageviews-1) WHERE stat_date=%s", $stat_date ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['daily']} WHERE stat_date=%s AND visitors=0 AND visits=0 AND pageviews=0 AND engaged_seconds=0 AND bounces=0", $stat_date ) );
			$wpdb->delete( $tables['sessions'], array( 'id' => $session_id ), array( '%d' ) );
		} else {
			$wpdb->delete( $tables['sessions'], array( 'session_key' => (string) $row['session_key'] ), array( '%s' ) );
		}
		$wpdb->delete( $tables['shadow_events'], array( 'id' => $event_id ), array( '%d' ) );
	}
	if ( $page_id > 0 ) {
		$wpdb->delete( $tables['pages'], array( 'id' => $page_id ), array( '%d' ) );
	}
}

$old_country_mode = get_option( 'aap_country_mode', 'all' );
$old_tracking = get_option( 'aap_tracking_active', 1 );
$events = array();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
$country_override = static fn (): string => 'JP';
$region_override = static fn (): string => 'JP-13';

try {
	update_option( 'aap_country_mode', 'all', false );
	update_option( 'aap_tracking_active', 1, false );
	add_filter( 'aap_country_code', $country_override );
	add_filter( 'aap_region_code', $region_override );

	$normal = aap_integration_collect( 'normal' );
	$events[] = $normal;
	global $wpdb;
	$tables = AccessAnalyticsPlus\Database::tables();
	$staged_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['shadow_events']} WHERE id=%d", $normal['event_id'] ), ARRAY_A );
	aap_integration_expect( $staged_row && 'pending' === $staged_row['shadow_class'] && empty( $staged_row['session_id'] ), 'collect stores only a pending region candidate before confirmation' );
	$response = aap_integration_signal( $normal['token'] );
	aap_integration_expect( 200 === $response->get_status(), 'human signal did not return 200' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['shadow_events']} WHERE id=%d", $normal['event_id'] ), ARRAY_A );
	aap_integration_expect( $row && 'confirmed' === $row['shadow_class'], 'shadow event was not confirmed' );
	aap_integration_expect( 'JP' === $row['country_code'] && 'JP-13' === $row['region_code'], 'shadow event did not retain country and region candidates' );
	aap_integration_expect( 'promotion_completed' === $row['pipeline_stage'] && (int) $row['pageview_id'] > 0 && (int) $row['session_id'] > 0, 'promotion did not reach completion' );
	aap_integration_expect( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['pageviews']} WHERE id=%d", (int) $row['pageview_id'] ) ), 'pageview was not created' );
	aap_integration_expect( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['sessions']} WHERE id=%d", (int) $row['session_id'] ) ), 'session was not created' );
	$session_region = $wpdb->get_row( $wpdb->prepare( "SELECT country_code,region_code FROM {$tables['sessions']} WHERE id=%d", (int) $row['session_id'] ), ARRAY_A );
	aap_integration_expect( $session_region && 'JP' === $session_region['country_code'] && 'JP-13' === $session_region['region_code'], 'promotion did not copy region to the confirmed session' );
	$stat_date = ( new DateTimeImmutable( (string) $row['recorded_at'], new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	aap_integration_expect( (int) $wpdb->get_var( $wpdb->prepare( "SELECT pageviews FROM {$tables['daily']} WHERE stat_date=%s", $stat_date ) ) >= 1, 'daily total was not updated' );
	aap_integration_expect( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['daily_dimensions']} WHERE stat_date=%s AND dimension_type='unique_visit' AND dimension_key=%s", $stat_date, (string) $row['session_id'] ) ), 'daily visit dimension was not created' );

	$idempotent_uuid = wp_generate_uuid4();
	$idempotent_request = new WP_REST_Request( 'POST', '/access-analytics-plus/v1/collect' );
	$idempotent_request->set_header( 'Origin', home_url() );
	$idempotent_request->set_body_params( array(
		'collect_id' => $idempotent_uuid,
		'visitor_id' => wp_generate_uuid4(),
		'session_id' => wp_generate_uuid4(),
		'path' => '/aap-idempotency-test/',
		'title' => 'AAP idempotency test',
		'device_type' => 'mobile',
		'webdriver' => 0,
		'tracker_build' => AAP_BUILD,
	) );
	$first_idempotent = rest_do_request( $idempotent_request );
	$second_idempotent = rest_do_request( $idempotent_request );
	aap_integration_expect( 201 === $first_idempotent->get_status() && 201 === $second_idempotent->get_status(), 'idempotent collect retry was not accepted' );
	$first_token = (string) ( $first_idempotent->get_data()['engagement_token'] ?? '' );
	$second_token = (string) ( $second_idempotent->get_data()['engagement_token'] ?? '' );
	aap_integration_expect( $first_token === $second_token, 'idempotent collect retry did not reuse the staged row' );
	if ( preg_match( '/^s\.(\d+)\./', $first_token, $idempotent_match ) ) {
		$events[] = array( 'token' => $first_token, 'event_id' => (int) $idempotent_match[1], 'path' => '/aap-idempotency-test/' );
	}

	$old_build = aap_integration_collect( 'old-build', 'beta.cached-old-build' );
	$events[] = $old_build;
	$old_build_row = $wpdb->get_row( $wpdb->prepare( "SELECT build_mismatch,tracker_build FROM {$tables['shadow_events']} WHERE id=%d", $old_build['event_id'] ), ARRAY_A );
	aap_integration_expect( $old_build_row && 1 === (int) $old_build_row['build_mismatch'], 'old tracker build was not diagnosed' );

	if ( ! defined( 'AAP_ENABLE_TEST_FAILURES' ) ) {
		define( 'AAP_ENABLE_TEST_FAILURES', true );
	}
	add_filter( 'aap_test_promotion_failure', static fn ( bool $fail, string $stage ): bool => $fail || 'daily' === $stage, 10, 2 );
	$failure = aap_integration_collect( 'failure' );
	$events[] = $failure;
	$response = aap_integration_signal( $failure['token'] );
	aap_integration_expect( 500 === $response->get_status(), 'injected promotion failure did not return 500' );
	$failed_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['shadow_events']} WHERE id=%d", $failure['event_id'] ), ARRAY_A );
	aap_integration_expect( $failed_row && 'promotion_failed' === $failed_row['pipeline_stage'] && 'pageview_created' === $failed_row['last_completed_stage'] && 'daily' === $failed_row['last_error_stage'], 'promotion failure stage was not retained' );
	aap_integration_expect( 0 === (int) $failed_row['pageview_id'], 'failed promotion exposed a pageview id' );
	aap_integration_expect( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['sessions']} WHERE session_key=%s", $failed_row['session_key'] ) ), 'failed promotion did not roll back its session' );

	echo "WordPress REST + DB promotion integration checks passed.\n";
} finally {
	remove_filter( 'aap_country_code', $country_override );
	remove_filter( 'aap_region_code', $region_override );
	foreach ( array_reverse( $events ) as $event ) {
		aap_integration_cleanup( $event['event_id'], $event['path'] );
	}
	update_option( 'aap_country_mode', $old_country_mode, false );
	update_option( 'aap_tracking_active', $old_tracking, false );
}
