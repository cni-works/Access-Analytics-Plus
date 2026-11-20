<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

function readiness_expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$root = dirname( __DIR__ );
$main = (string) file_get_contents( $root . '/access-analytics-plus.php' );
$tracker = (string) file_get_contents( $root . '/includes/class-tracker.php' );
$shadow = (string) file_get_contents( $root . '/includes/class-shadow-diagnostics.php' );
$events = (string) file_get_contents( $root . '/includes/class-event-tracker.php' );
$cron = (string) file_get_contents( $root . '/includes/class-cron.php' );
$report = (string) file_get_contents( $root . '/includes/class-ai-report-admin.php' );
$uninstall = (string) file_get_contents( $root . '/uninstall.php' );

readiness_expect( str_contains( $main, "defined( 'AAP_INCLUDE_PRERELEASES' )" ), 'pre-release updates require an explicit opt-in constant' );
readiness_expect( str_contains( $main, "apply_filters( 'aap_include_prereleases'" ), 'pre-release updates expose an explicit developer filter' );
readiness_expect( ! str_contains( $main, "'include_prereleases' => true" ), 'pre-release updates are not enabled unconditionally' );

foreach ( array( 'store_pageview', 'create_engagement_token', 'verify_pageview_token', 'update_daily_totals(' ) as $legacy_symbol ) {
	readiness_expect( ! str_contains( $tracker, $legacy_symbol ), "legacy tracker symbol removed: {$legacy_symbol}" );
}
readiness_expect( ! str_contains( $shadow, 'update_legacy_signal' ), 'legacy shadow-token update path is removed' );
readiness_expect( ! str_contains( $events, 'pageview_context' ), 'legacy event-token context is removed' );

readiness_expect( str_contains( $cron, "HOURLY_HOOK = 'aap_hourly_maintenance'" ), 'pending finalization has a dedicated hourly hook' );
readiness_expect( str_contains( $cron, "self::ensure_event( self::CLEANUP_HOOK, 'daily'" ), 'retention cleanup is scheduled daily' );
readiness_expect( str_contains( $cron, 'delete_expired_transients' ), 'daily maintenance removes expired AAP transients' );

readiness_expect( ! str_contains( $report, 'Content-Length:' ), 'Markdown download avoids a compression-sensitive Content-Length header' );
readiness_expect( str_contains( $uninstall, "'_transient_aap_'" ) && str_contains( $uninstall, "'_transient_timeout_aap_'" ), 'full uninstall removes AAP transient rows' );

echo "Release readiness regression checks passed.\n";
