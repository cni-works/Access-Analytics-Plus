<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}
class WP_REST_Request {}
class WP_REST_Response {}

function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Tokyo' ); }
function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

define( 'AAP_BUILD', 'phase2-5.test' );

require_once dirname( __DIR__ ) . '/includes/class-analytics.php';
require_once dirname( __DIR__ ) . '/includes/class-tracker.php';
require_once dirname( __DIR__ ) . '/includes/class-sample-data.php';

use AccessAnalyticsPlus\Analytics;
use AccessAnalyticsPlus\Tracker;
use AccessAnalyticsPlus\Sample_Data;

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$resolve = new ReflectionMethod( Analytics::class, 'resolve_period' );
$today   = new DateTimeImmutable( 'today', wp_timezone() );
$cases   = array(
	'today'     => array( $today, $today->modify( '+1 day' ) ),
	'yesterday' => array( $today->modify( '-1 day' ), $today ),
	'7d'        => array( $today->modify( '-6 days' ), $today->modify( '+1 day' ) ),
	'30d'       => array( $today->modify( '-29 days' ), $today->modify( '+1 day' ) ),
	'month'     => array( $today->modify( 'first day of this month' ), $today->modify( '+1 day' ) ),
);
foreach ( $cases as $range => [ $expected_start, $expected_end ] ) {
	$period = $resolve->invoke( null, $range, '', '' );
	expect( ! is_wp_error( $period ), "{$range} must resolve" );
	expect( $period['start'] == $expected_start, "{$range} start" );
	expect( $period['end_exclusive'] == $expected_end, "{$range} end" );
}

$custom = $resolve->invoke( null, 'custom', $today->modify( '-2 days' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
expect( ! is_wp_error( $custom ) && 3 === $custom['start']->diff( $custom['end_exclusive'] )->days, 'custom three-day period' );
$too_long = $resolve->invoke( null, 'custom', $today->modify( '-90 days' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
expect( is_wp_error( $too_long ), 'custom period over 90 days must fail' );
$future = $resolve->invoke( null, 'custom', $today->format( 'Y-m-d' ), $today->modify( '+1 day' )->format( 'Y-m-d' ) );
expect( is_wp_error( $future ), 'future period must fail' );

$is_bot = new ReflectionMethod( Tracker::class, 'is_bot' );
expect( true === $is_bot->invoke( null, 'Googlebot/2.1 (+http://www.google.com/bot.html)' ), 'Googlebot exclusion' );
expect( true === $is_bot->invoke( null, 'Mozilla/5.0 compatible; GPTBot/1.2' ), 'AI crawler exclusion' );
expect( false === $is_bot->invoke( null, 'Mozilla/5.0 (Linux; Android 14; CUBOT X70) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36' ), 'CUBOT phone is not a bot' );
expect( false === $is_bot->invoke( null, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1' ), 'iPhone Safari is not a bot' );

$tag = Tracker::tracking_script_attributes( '<script src="tracker.js" defer></script>', 'aap-tracker' );
expect( str_contains( $tag, 'data-cfasync="false"' ) && strpos( $tag, 'data-cfasync' ) < strpos( $tag, 'src=' ), 'cache compatibility attributes' );
$excludes = Tracker::litespeed_js_excludes( array( 'existing.js' ) );
expect( in_array( 'access-analytics-plus/assets/js/tracker.js', $excludes, true ), 'LiteSpeed exclusion' );

$sample_period = array(
	'start'          => $today,
	'end_exclusive'  => $today->modify( '+1 day' ),
	'previous_start' => $today->modify( '-1 day' ),
);
$scale_ranges = array( 'low' => array( 5, 30 ), 'standard' => array( 30, 100 ), 'high' => array( 100, 500 ) );
foreach ( $scale_ranges as $scale => [ $minimum, $maximum ] ) {
	$sample = Sample_Data::report( $sample_period, $scale, 24680 );
	$visits = (int) $sample['metrics']['visits']['value'];
	expect( $visits >= $minimum && $visits <= $maximum, "sample {$scale} visit range" );
	expect( true === $sample['sample'], "sample {$scale} marker" );
	expect( (int) $sample['metrics']['pageviews']['value'] > $visits, "sample {$scale} pageviews exceed visits" );
	expect( array_sum( array_column( $sample['timeseries'], 'visits' ) ) === $visits, "sample {$scale} hourly visits total" );
	expect( array_sum( array_column( $sample['timeseries'], 'visitors' ) ) === (int) $sample['metrics']['visitors']['value'], "sample {$scale} hourly visitor total" );
	expect( array_sum( array_column( $sample['timeseries'], 'pageviews' ) ) === (int) $sample['metrics']['pageviews']['value'], "sample {$scale} hourly pageview total" );
	expect( array_sum( array_column( $sample['sources'], 'value' ) ) === $visits, "sample {$scale} source total" );
	$source_values = array_column( $sample['sources'], 'value', 'key' );
	expect( array_sum( array_column( $sample['source_details']['search'], 'value' ) ) === (int) $source_values['search'], "sample {$scale} search detail total" );
	expect( array_sum( array_column( $sample['source_details']['social'], 'value' ) ) === (int) $source_values['social'], "sample {$scale} social detail total" );
	expect( array_sum( array_column( $sample['devices'], 'value' ) ) === $visits, "sample {$scale} device total" );
	expect( array_sum( array_column( $sample['pages'], 'pageviews' ) ) === (int) $sample['metrics']['pageviews']['value'], "sample {$scale} page total" );
	expect( array_sum( array_column( $sample['regions']['items'], 'value' ) ) === (int) $sample['regions']['total'], "sample {$scale} region total" );
}
$same_sample = Sample_Data::report( $sample_period, 'standard', 24680 );
expect( $same_sample === Sample_Data::report( $sample_period, 'standard', 24680 ), 'sample data remains fixed for the same seed' );
expect( $same_sample !== Sample_Data::report( $sample_period, 'standard', 13579 ), 'sample regeneration seed changes the dataset' );
$week_period = array(
	'start'          => $today->modify( '-6 days' ),
	'end_exclusive'  => $today->modify( '+1 day' ),
	'previous_start' => $today->modify( '-13 days' ),
);
$week_sample = Sample_Data::report( $week_period, 'standard', 24680 );
expect( 7 === count( $week_sample['timeseries'] ), 'sample week has seven daily rows' );
expect( array_sum( array_column( $week_sample['timeseries'], 'visits' ) ) === (int) $week_sample['metrics']['visits']['value'], 'sample daily visits match period total' );
expect( array_sum( array_column( $week_sample['timeseries'], 'pageviews' ) ) === (int) $week_sample['metrics']['pageviews']['value'], 'sample daily pageviews match period total' );
$expected_pages_per_visit = round( (int) $week_sample['metrics']['pageviews']['value'] / (int) $week_sample['metrics']['visits']['value'], 1 );
expect( $expected_pages_per_visit === $week_sample['metrics']['pages_per_visit']['value'], 'sample pages per visit is arithmetically consistent' );
$long_period = array(
	'start'          => $today->modify( '-89 days' ),
	'end_exclusive'  => $today->modify( '+1 day' ),
	'previous_start' => $today->modify( '-179 days' ),
);
$long_sample = Sample_Data::report( $long_period, 'high', 24680 );
expect( 90 === count( $long_sample['timeseries'] ), 'sample supports the maximum 90-day period' );

echo "PHP regression checks passed.\n";
