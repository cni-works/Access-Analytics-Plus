<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-only-secret-' . $scheme;
}

function __( string $text, string $domain = '' ): string {
	unset( $domain );
	return $text;
}

require_once dirname( __DIR__ ) . '/includes/class-shadow-diagnostics.php';

use AccessAnalyticsPlus\Shadow_Diagnostics;

function expect_shadow( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$diagnose = new ReflectionMethod( Shadow_Diagnostics::class, 'diagnose' );
$hmac     = new ReflectionMethod( Shadow_Diagnostics::class, 'identifier_hmac' );
$family   = new ReflectionMethod( Shadow_Diagnostics::class, 'user_agent_family' );
$device   = new ReflectionMethod( Shadow_Diagnostics::class, 'device_from_user_agent' );

$base = array(
	'webdriver_state'      => 0,
	'origin_present'       => 1,
	'device_mismatch'      => 0,
	'ip_visitors_10m'      => 1,
	'ua_visitors_10m'      => 1,
	'ua_path_requests_10m' => 1,
	'regular_interval'     => 0,
	'shadow_class'         => 'pending',
	'visible_confirmed'    => 0,
	'interaction_mask'     => 0,
	'engagement_received'  => 0,
);

$pending = $diagnose->invoke( null, $base, 30 );
expect_shadow( 'pending' === $pending['class'], 'new diagnostics remain pending during the grace period' );
expect_shadow( 0 === $pending['score'], 'missing signals do not increase risk during the grace period' );

$unconfirmed = $diagnose->invoke( null, $base, Shadow_Diagnostics::PENDING_GRACE_SECONDS );
expect_shadow( 'unconfirmed' === $unconfirmed['class'], 'a short visit without other risk signals is not called a bot' );
expect_shadow( 3 === $unconfirmed['score'], 'missing visibility and engagement are combined only after grace' );

$human = $base;
$human['visible_confirmed'] = 1;
$human = $diagnose->invoke( null, $human, 30 );
expect_shadow( 'confirmed' === $human['class'], 'three-second visibility promotes a confirmed visit' );

$shared_ip = $base;
$shared_ip['ip_visitors_10m'] = 5;
$shared_ip['visible_confirmed'] = 1;
$shared_ip = $diagnose->invoke( null, $shared_ip, 30 );
expect_shadow( 'confirmed' === $shared_ip['class'], 'a shared IP alone does not block a confirmed visit' );

$rotating_bot = $base;
$rotating_bot['ip_visitors_10m'] = 5;
$rotating_bot = $diagnose->invoke( null, $rotating_bot, Shadow_Diagnostics::PENDING_GRACE_SECONDS );
expect_shadow( 'bot' === $rotating_bot['class'], 'many identifiers plus missing browser signals are suspicious' );

$webdriver_only = $base;
$webdriver_only['webdriver_state'] = 1;
$webdriver_only['visible_confirmed'] = 1;
$webdriver_only = $diagnose->invoke( null, $webdriver_only, 30 );
expect_shadow( 'confirmed' === $webdriver_only['class'], 'webdriver alone is not enough to block a confirmed visit' );

$automated_mismatch = $base;
$automated_mismatch['webdriver_state'] = 1;
$automated_mismatch['device_mismatch'] = 1;
$automated_mismatch['visible_confirmed'] = 1;
$automated_mismatch = $diagnose->invoke( null, $automated_mismatch, 30 );
expect_shadow( 'bot' === $automated_mismatch['class'], 'webdriver and device contradiction form a compound signal' );

$distributed = $base;
$distributed['ua_visitors_10m'] = 10;
$distributed['ua_path_requests_10m'] = 10;
$distributed = $diagnose->invoke( null, $distributed, Shadow_Diagnostics::PENDING_GRACE_SECONDS );
expect_shadow( 'bot' === $distributed['class'], 'distributed IP automation is detected by UA, path and missing signals' );

$ip_hmac = $hmac->invoke( null, '203.0.113.10', 'ip' );
$ua_hmac = $hmac->invoke( null, '203.0.113.10', 'user-agent' );
expect_shadow( 64 === strlen( $ip_hmac ), 'diagnostic identifiers use a full HMAC-SHA256 value' );
expect_shadow( '203.0.113.10' !== $ip_hmac, 'the source IP is not stored as the identifier' );
expect_shadow( $ip_hmac !== $ua_hmac, 'HMAC contexts prevent cross-field correlation' );
expect_shadow( $ip_hmac === $hmac->invoke( null, '203.0.113.10', 'ip' ), 'the same IP can be correlated during retention' );

$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
expect_shadow( 'safari' === $family->invoke( null, $iphone ), 'Safari family is derived without storing the full UA' );
expect_shadow( 'mobile' === $device->invoke( null, $iphone ), 'mobile device is derived server-side from the UA' );

$database_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-database.php' );
$analytics_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-analytics.php' );
$shadow_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-shadow-diagnostics.php' );
expect_shadow( is_string( $database_source ) && str_contains( $database_source, 'UNIQUE KEY pageview_id (pageview_id)' ), 'one diagnostic row per pageview is enforced by the schema' );
expect_shadow( str_contains( $database_source, 'KEY recorded_at (recorded_at)' ), 'retention queries have a recorded_at index' );
expect_shadow( str_contains( $database_source, 'KEY ip_recorded (ip_key, recorded_at)' ), 'anonymous IP queries have a composite index' );
expect_shadow( str_contains( $database_source, 'KEY visitor_recorded (visitor_key, recorded_at)' ), 'new visitor checks have a composite index' );
expect_shadow( str_contains( $database_source, 'KEY class_recorded (shadow_class, recorded_at)' ), 'classification queries have a composite index' );
expect_shadow( str_contains( $database_source, 'KEY ua_path_recorded (ua_hash, path_hash, recorded_at)' ), 'distributed automation queries have a UA/path index' );
expect_shadow( str_contains( $database_source, "aggregation_mode varchar(12) NOT NULL DEFAULT 'legacy'" ), 'existing diagnostic rows remain legacy during migration' );
expect_shadow( str_contains( $database_source, 'ua_hash char(64)' ) && ! str_contains( $database_source, 'user_agent text' ), 'only an HMAC-sized UA identifier is stored' );
expect_shadow( is_string( $shadow_source ) && preg_match( "/hash_hmac\\(\\s*'sha256'/", $shadow_source ) === 1, 'IP and UA identifiers use HMAC rather than a plain hash' );
expect_shadow( is_string( $analytics_source ) && ! str_contains( $analytics_source, 'shadow_events' ), 'normal analytics queries do not read shadow diagnostics' );

echo "Shadow diagnostics regression checks passed.\n";
