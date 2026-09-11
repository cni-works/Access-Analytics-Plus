<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Analytics {
	public static function rebuild_daily( string $date ): void {
		if ( ! self::valid_date( $date ) ) {
			return;
		}

		global $wpdb;
		$table = Database::tables()['daily'];
		$pageviews_table = Database::tables()['pageviews'];
		$start = new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );
		$end   = $start->modify( '+1 day' );
		$data  = self::metrics( $start, $end );
		$quality = self::quality_metrics( $start, $end );
		$engaged_seconds = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(engaged_seconds), 0) FROM {$pageviews_table} WHERE viewed_at >= %s AND viewed_at < %s",
				self::utc( $start ),
				self::utc( $end )
			)
		);

		$wpdb->replace(
			$table,
			array(
				'stat_date'       => $date,
				'visitors'        => $data['visitors'],
				'visits'          => $data['visits'],
				'pageviews'       => $data['pageviews'],
				'engaged_seconds' => $engaged_seconds,
				'bounces'         => $quality['bounces'],
			),
			array( '%s', '%d', '%d', '%d', '%d', '%d' )
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function report( WP_REST_Request $request ) {
		$period = self::resolve_period(
			sanitize_key( (string) $request->get_param( 'range' ) ),
			(string) $request->get_param( 'start' ),
			(string) $request->get_param( 'end' )
		);

		if ( is_wp_error( $period ) ) {
			return $period;
		}

		if ( 'dashboard' === (string) $request->get_param( 'context' ) ) {
			return self::dashboard_report( $period );
		}
		if ( Settings::sample_enabled() ) {
			return self::response( Sample_Data::report( $period, Settings::sample_scale(), Settings::sample_seed() ) );
		}
		if ( false === get_transient( 'aap_confirmation_finalize_lock' ) ) {
			set_transient( 'aap_confirmation_finalize_lock', 1, MINUTE_IN_SECONDS );
			Shadow_Diagnostics::finalize_pending( 500 );
		}
		$cache_key = self::report_cache_key( 'full', $period );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return self::response( $cached );
		}

		$current  = self::metrics( $period['start'], $period['end_exclusive'] );
		$previous = self::metrics( $period['previous_start'], $period['start'] );
		$current_quality  = self::quality_metrics( $period['start'], $period['end_exclusive'] );
		$previous_quality = self::quality_metrics( $period['previous_start'], $period['start'] );
		$current_pages_per_visit  = $current['visits'] > 0 ? round( $current['pageviews'] / $current['visits'], 1 ) : null;
		$previous_pages_per_visit = $previous['visits'] > 0 ? round( $previous['pageviews'] / $previous['visits'], 1 ) : null;

		$payload = array(
				'period'     => array(
					'start' => $period['start']->format( 'Y-m-d' ),
					'end'   => $period['end_exclusive']->modify( '-1 day' )->format( 'Y-m-d' ),
				),
				'metrics'    => array(
					'visitors' => self::metric_payload( $current['visitors'], $previous['visitors'] ),
					'pageviews' => self::metric_payload( $current['pageviews'], $previous['pageviews'] ),
					'visits' => self::metric_payload( $current['visits'], $previous['visits'] ),
					'average_engaged_seconds' => self::metric_payload( $current_quality['average_engaged_seconds'], $previous_quality['average_engaged_seconds'], true ),
					'bounce_rate' => self::metric_payload( $current_quality['bounce_rate'], $previous_quality['bounce_rate'], true ),
					'pages_per_visit' => self::metric_payload( $current_pages_per_visit, $previous_pages_per_visit ),
				),
				'quality'    => array(
					'ended_visits' => $current_quality['ended_visits'],
					'partial'      => $current_quality['partial'],
					'provisional'  => $current_quality['provisional'],
				),
				'timeseries' => self::timeseries( $period['start'], $period['end_exclusive'] ),
				'sources'    => self::sources( $period['start'], $period['end_exclusive'] ),
				'source_details' => array(
					'search' => self::traffic_details( 'search', $period['start'], $period['end_exclusive'] ),
					'social' => self::traffic_details( 'social', $period['start'], $period['end_exclusive'] ),
				),
				'pages'      => self::popular_pages( $period['start'], $period['end_exclusive'] ),
				'devices'    => self::devices( $period['start'], $period['end_exclusive'] ),
				'exclusions' => self::exclusions( $period['start'], $period['end_exclusive'] ),
				'dashboard'  => null,
				'updated_at' => current_datetime()->format( 'Y-m-d H:i' ),
				'sample'     => false,
		);
		set_transient( $cache_key, $payload, 30 );
		return self::response( $payload );
	}

	/**
	 * @param array{start:DateTimeImmutable,end_exclusive:DateTimeImmutable,previous_start:DateTimeImmutable} $period Period boundaries.
	 */
	private static function dashboard_report( array $period ): WP_REST_Response {
		$cache_key = self::report_cache_key( 'dashboard', $period );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return self::response( $cached );
		}
		$dashboard = self::dashboard_summary( $period );

		$payload = array(
				'period' => array(
					'start' => $period['start']->format( 'Y-m-d' ),
					'end'   => $period['end_exclusive']->modify( '-1 day' )->format( 'Y-m-d' ),
				),
				'metrics'        => $dashboard['metrics'],
				'timeseries'     => self::timeseries( $period['start'], $period['end_exclusive'], false ),
				'sources'        => array(),
				'source_details' => array( 'search' => array(), 'social' => array() ),
				'pages'          => self::popular_pages( $period['start'], $period['end_exclusive'] ),
				'devices'        => array(),
				'exclusions'     => array( 'total' => 0, 'items' => array() ),
				'dashboard'      => $dashboard,
				'updated_at'     => current_datetime()->format( 'Y-m-d H:i' ),
				'sample'         => false,
		);
		set_transient( $cache_key, $payload, 30 );
		return self::response( $payload );
	}

	/**
	 * @param array{start:DateTimeImmutable,end_exclusive:DateTimeImmutable,previous_start:DateTimeImmutable} $period Period boundaries.
	 *
	 * @return array{metrics:array<string,array<string,int|float|string|null>>,month_visitors:int}
	 */
	private static function dashboard_summary( array $period ): array {
		$today    = new DateTimeImmutable( 'today', wp_timezone() );
		$tomorrow = $today->modify( '+1 day' );
		$current  = self::metrics( $period['start'], $period['end_exclusive'] );
		$previous = self::metrics( $period['previous_start'], $period['start'] );
		$month    = self::metrics( $today->modify( 'first day of this month' ), $tomorrow );

		return array(
			'metrics' => array(
				'visitors'  => self::metric_payload( $current['visitors'], $previous['visitors'] ),
				'pageviews' => self::metric_payload( $current['pageviews'], $previous['pageviews'] ),
			),
			'month_visitors' => $month['visitors'],
		);
	}

	/**
	 * @param array{start:DateTimeImmutable,end_exclusive:DateTimeImmutable,previous_start:DateTimeImmutable} $period Period boundaries.
	 */
	private static function report_cache_key( string $context, array $period ): string {
		return 'aap_report_' . md5(
			implode(
				'|',
				array(
					AAP_BUILD,
					$context,
					$period['start']->format( DATE_ATOM ),
					$period['end_exclusive']->format( DATE_ATOM ),
					determine_locale(),
				)
			)
		);
	}

	/**
	 * @param array<string,mixed> $payload Report data.
	 */
	private static function response( array $payload ): WP_REST_Response {
		$payload['build'] = AAP_BUILD;
		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * @return array{start:DateTimeImmutable,end_exclusive:DateTimeImmutable,previous_start:DateTimeImmutable}|WP_Error
	 */
	private static function resolve_period( string $range, string $start, string $end ) {
		$timezone = wp_timezone();
		$today    = new DateTimeImmutable( 'today', $timezone );

		switch ( $range ) {
			case 'today':
				$period_start = $today;
				$period_end   = $today->modify( '+1 day' );
				break;
			case 'yesterday':
				$period_start = $today->modify( '-1 day' );
				$period_end   = $today;
				break;
			case '30d':
				$period_start = $today->modify( '-29 days' );
				$period_end   = $today->modify( '+1 day' );
				break;
			case 'month':
				$period_start = $today->modify( 'first day of this month' );
				$period_end   = $today->modify( '+1 day' );
				break;
			case 'custom':
				if ( ! self::valid_date( $start ) || ! self::valid_date( $end ) ) {
					return new WP_Error( 'aap_invalid_period', __( '期間を正しく指定してください。', 'access-analytics-plus' ), array( 'status' => 400 ) );
				}
				$period_start = new DateTimeImmutable( $start . ' 00:00:00', $timezone );
				$period_end   = ( new DateTimeImmutable( $end . ' 00:00:00', $timezone ) )->modify( '+1 day' );
				if ( $period_end <= $period_start || $period_start->diff( $period_end )->days > 90 ) {
					return new WP_Error( 'aap_period_too_long', __( '期間指定は90日以内にしてください。', 'access-analytics-plus' ), array( 'status' => 400 ) );
				}
				if ( $period_end > $today->modify( '+1 day' ) ) {
					return new WP_Error( 'aap_future_period', __( '未来の日付は指定できません。', 'access-analytics-plus' ), array( 'status' => 400 ) );
				}
				break;
			case '7d':
			default:
				$period_start = $today->modify( '-6 days' );
				$period_end   = $today->modify( '+1 day' );
				break;
		}

		$days = (int) $period_start->diff( $period_end )->days;
		return array(
			'start'          => $period_start,
			'end_exclusive'  => $period_end,
			'previous_start' => $period_start->sub( new DateInterval( 'P' . $days . 'D' ) ),
		);
	}

	/**
	 * @return array{visitors:int,visits:int,pageviews:int}
	 */
	private static function metrics( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		global $wpdb;
		$tables = Database::tables();
		$start_utc = self::utc( $start );
		$end_utc   = self::utc( $end );

		$session_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS visits, COUNT(DISTINCT visitor_key) AS visitors
				FROM {$tables['sessions']} WHERE started_at >= %s AND started_at < %s",
				$start_utc,
				$end_utc
			),
			ARRAY_A
		);
		$pageviews = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['pageviews']} WHERE viewed_at >= %s AND viewed_at < %s",
				$start_utc,
				$end_utc
			)
		);

		return array(
			'visitors'  => (int) ( $session_row['visitors'] ?? 0 ),
			'visits'    => (int) ( $session_row['visits'] ?? 0 ),
			'pageviews' => $pageviews,
		);
	}

	/**
	 * @return array{average_engaged_seconds:int|null,bounce_rate:float|null,ended_visits:int,bounces:int,partial:bool,provisional:bool}
	 */
	private static function quality_metrics( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		global $wpdb;
		$tables = Database::tables();
		$start_utc = self::utc( $start );
		$end_utc   = self::utc( $end );
		$tracking_started = (string) get_option( 'aap_engagement_started_at', '' );

		if ( '' === $tracking_started ) {
			$tracking_started = current_time( 'mysql', true );
			add_option( 'aap_engagement_started_at', $tracking_started, '', false );
		}

		$effective_start = $tracking_started > $start_utc ? $tracking_started : $start_utc;
		$cutoff_utc      = gmdate( 'Y-m-d H:i:s', time() - ( 30 * MINUTE_IN_SECONDS ) );
		$empty = array(
			'average_engaged_seconds' => null,
			'bounce_rate'              => null,
			'ended_visits'             => 0,
			'bounces'                  => 0,
			'partial'                  => $effective_start > $start_utc,
			'provisional'              => $end_utc > $cutoff_utc,
		);

		if ( $effective_start >= $end_utc ) {
			return $empty;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS ended_visits, COALESCE(SUM(ended.is_bounce), 0) AS bounces,
				COALESCE(SUM(ended.engaged_seconds), 0) AS engaged_seconds
				FROM (
					SELECT s.id, CASE WHEN s.pageview_count = 1 THEN 1 ELSE 0 END AS is_bounce,
					COALESCE(SUM(pv.engaged_seconds), 0) AS engaged_seconds
					FROM {$tables['sessions']} s
					LEFT JOIN {$tables['pageviews']} pv ON pv.session_id = s.id
					WHERE s.started_at >= %s AND s.started_at < %s AND s.last_seen_at < %s
					GROUP BY s.id, s.pageview_count
				) ended",
				$effective_start,
				$end_utc,
				$cutoff_utc
			),
			ARRAY_A
		);

		$ended_visits = (int) ( $row['ended_visits'] ?? 0 );
		if ( 0 === $ended_visits ) {
			return $empty;
		}

		$bounces          = (int) ( $row['bounces'] ?? 0 );
		$engaged_seconds  = (int) ( $row['engaged_seconds'] ?? 0 );
		return array(
			'average_engaged_seconds' => (int) round( $engaged_seconds / $ended_visits ),
			'bounce_rate'              => round( ( $bounces / $ended_visits ) * 100, 1 ),
			'ended_visits'             => $ended_visits,
			'bounces'                  => $bounces,
			'partial'                  => $effective_start > $start_utc,
			'provisional'              => $end_utc > $cutoff_utc,
		);
	}

	/**
	 * @return array<int, array{date:string,label:string,visitors:int,visits:int,pageviews:int,average_engaged_seconds:int|null,ended_visits:int}>
	 */
	private static function timeseries( DateTimeImmutable $start, DateTimeImmutable $end, bool $include_quality = true ): array {
		if ( 1 === (int) $start->diff( $end )->days ) {
			return self::hourly_timeseries( $start, $end, $include_quality );
		}

		global $wpdb;
		$table = Database::tables()['daily'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, visitors, visits, pageviews FROM {$table} WHERE stat_date >= %s AND stat_date < %s ORDER BY stat_date ASC",
				$start->format( 'Y-m-d' ),
				$end->format( 'Y-m-d' )
			),
			ARRAY_A
		);
		$indexed = array();
		$quality = $include_quality ? self::timeseries_quality( $start, $end, false ) : array();
		foreach ( $rows as $row ) {
			$indexed[ $row['stat_date'] ] = $row;
		}

		$result = array();
		for ( $day = $start; $day < $end; $day = $day->modify( '+1 day' ) ) {
			$key = $day->format( 'Y-m-d' );
			$row = $indexed[ $key ] ?? array();
			$quality_row = $quality[ $key ] ?? array( 'ended_visits' => 0, 'engaged_seconds' => 0 );
			$ended_visits = (int) $quality_row['ended_visits'];
			$result[] = array(
				'date'      => $key,
				'label'     => $day->format( 'n/j' ),
				'visitors'  => (int) ( $row['visitors'] ?? 0 ),
				'visits'    => (int) ( $row['visits'] ?? 0 ),
				'pageviews' => (int) ( $row['pageviews'] ?? 0 ),
				'average_engaged_seconds' => $ended_visits > 0 ? (int) round( (int) $quality_row['engaged_seconds'] / $ended_visits ) : null,
				'ended_visits'            => $ended_visits,
			);
		}
		return $result;
	}

	/**
	 * @return array<int, array{date:string,label:string,visitors:int,visits:int,pageviews:int,average_engaged_seconds:int|null,ended_visits:int}>
	 */
	private static function hourly_timeseries( DateTimeImmutable $start, DateTimeImmutable $end, bool $include_quality = true ): array {
		global $wpdb;
		$tables = Database::tables();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pv.viewed_at, pv.session_id, s.visitor_key
				FROM {$tables['pageviews']} pv INNER JOIN {$tables['sessions']} s ON s.id = pv.session_id
				WHERE pv.viewed_at >= %s AND pv.viewed_at < %s ORDER BY pv.viewed_at ASC",
				self::utc( $start ),
				self::utc( $end )
			),
			ARRAY_A
		);

		$buckets = array_fill( 0, 24, array( 'pageviews' => 0, 'visitors' => array(), 'visits' => array() ) );
		$quality = $include_quality ? self::timeseries_quality( $start, $end, true ) : array();
		foreach ( $rows as $row ) {
			$local = ( new DateTimeImmutable( (string) $row['viewed_at'], new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() );
			$hour  = (int) $local->format( 'G' );
			++$buckets[ $hour ]['pageviews'];
			$buckets[ $hour ]['visitors'][ (string) $row['visitor_key'] ] = true;
			$buckets[ $hour ]['visits'][ (string) $row['session_id'] ]    = true;
		}

		$result = array();
		for ( $hour = 0; $hour < 24; ++$hour ) {
			$quality_key = $start->format( 'Y-m-d' ) . sprintf( ' %02d', $hour );
			$quality_row = $quality[ $quality_key ] ?? array( 'ended_visits' => 0, 'engaged_seconds' => 0 );
			$ended_visits = (int) $quality_row['ended_visits'];
			$result[] = array(
				'date'      => $start->format( 'Y-m-d' ) . sprintf( ' %02d:00', $hour ),
				'label'     => $hour . '時',
				'visitors'  => count( $buckets[ $hour ]['visitors'] ),
				'visits'    => count( $buckets[ $hour ]['visits'] ),
				'pageviews' => (int) $buckets[ $hour ]['pageviews'],
				'average_engaged_seconds' => $ended_visits > 0 ? (int) round( (int) $quality_row['engaged_seconds'] / $ended_visits ) : null,
				'ended_visits'            => $ended_visits,
			);
		}
		return $result;
	}

	/**
	 * Groups completed visits by their local start day/hour without changing stored analytics data.
	 *
	 * @return array<string,array{ended_visits:int,engaged_seconds:int}>
	 */
	private static function timeseries_quality( DateTimeImmutable $start, DateTimeImmutable $end, bool $hourly ): array {
		global $wpdb;
		$tables = Database::tables();
		$start_utc = self::utc( $start );
		$end_utc   = self::utc( $end );
		$tracking_started = (string) get_option( 'aap_engagement_started_at', '' );
		$effective_start  = '' !== $tracking_started && $tracking_started > $start_utc ? $tracking_started : $start_utc;
		if ( $effective_start >= $end_utc ) {
			return array();
		}
		$cutoff_utc = gmdate( 'Y-m-d H:i:s', time() - ( 30 * MINUTE_IN_SECONDS ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.started_at, COALESCE(SUM(pv.engaged_seconds), 0) AS engaged_seconds
				FROM {$tables['sessions']} s
				LEFT JOIN {$tables['pageviews']} pv ON pv.session_id = s.id
				WHERE s.started_at >= %s AND s.started_at < %s AND s.last_seen_at < %s
				GROUP BY s.id, s.started_at",
				$effective_start,
				$end_utc,
				$cutoff_utc
			),
			ARRAY_A
		);
		$result = array();
		foreach ( $rows as $row ) {
			$local = ( new DateTimeImmutable( (string) $row['started_at'], new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() );
			$key   = $local->format( $hourly ? 'Y-m-d H' : 'Y-m-d' );
			if ( ! isset( $result[ $key ] ) ) {
				$result[ $key ] = array( 'ended_visits' => 0, 'engaged_seconds' => 0 );
			}
			++$result[ $key ]['ended_visits'];
			$result[ $key ]['engaged_seconds'] += (int) $row['engaged_seconds'];
		}
		return $result;
	}

	/**
	 * @return array<int, array{key:string,label:string,value:int,percent:float}>
	 */
	private static function sources( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		global $wpdb;
		$table = Database::tables()['sessions'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_type, COUNT(*) AS total FROM {$table}
				WHERE started_at >= %s AND started_at < %s GROUP BY referrer_type ORDER BY total DESC",
				self::utc( $start ),
				self::utc( $end )
			),
			ARRAY_A
		);
		$total  = array_sum( array_map( static fn ( array $row ): int => (int) $row['total'], $rows ) );
		$labels = array(
			'search'   => __( '検索', 'access-analytics-plus' ),
			'social'   => __( 'SNS', 'access-analytics-plus' ),
			'direct'   => __( 'ダイレクト', 'access-analytics-plus' ),
			'external' => __( '外部サイト', 'access-analytics-plus' ),
		);

		return array_map(
			static function ( array $row ) use ( $labels, $total ): array {
				$key   = (string) $row['referrer_type'];
				$value = (int) $row['total'];
				return array(
					'key'     => $key,
					'label'   => $labels[ $key ] ?? __( 'その他', 'access-analytics-plus' ),
					'value'   => $value,
					'percent' => $total > 0 ? round( ( $value / $total ) * 100, 1 ) : 0.0,
				);
			},
			$rows
		);
	}

	/**
	 * @return array<int, array{key:string,label:string,value:int,percent:float}>
	 */
	private static function devices( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		global $wpdb;
		$tables = Database::tables();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT device_type, COUNT(*) AS total FROM {$tables['sessions']}
				WHERE started_at >= %s AND started_at < %s GROUP BY device_type",
				self::utc( $start ),
				self::utc( $end )
			),
			ARRAY_A
		);

		$counts = array( 'mobile' => 0, 'desktop' => 0, 'tablet' => 0, 'other' => 0 );
		foreach ( $rows as $row ) {
			$key = (string) $row['device_type'];
			if ( ! isset( $counts[ $key ] ) ) {
				$key = 'other';
			}
			$counts[ $key ] += (int) $row['total'];
		}

		$total = array_sum( $counts );
		if ( 0 === $total ) {
			return array();
		}

		$labels = array(
			'mobile'  => __( 'スマートフォン', 'access-analytics-plus' ),
			'desktop' => __( 'PC', 'access-analytics-plus' ),
			'tablet'  => __( 'タブレット', 'access-analytics-plus' ),
			'other'   => __( 'その他', 'access-analytics-plus' ),
		);
		arsort( $counts );
		$result = array();
		foreach ( $counts as $key => $value ) {
			if ( 0 === $value ) {
				continue;
			}
			$result[] = array(
				'key'     => $key,
				'label'   => $labels[ $key ],
				'value'   => $value,
				'percent' => round( ( $value / $total ) * 100, 1 ),
			);
		}

		return $result;
	}

	/**
	 * @return array{total:int,items:array<int,array{key:string,label:string,value:int}>}
	 */
	private static function exclusions( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		global $wpdb;
		$table = Database::tables()['exclusions_daily'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT reason, SUM(excluded_count) AS total FROM {$table}
				WHERE stat_date >= %s AND stat_date < %s GROUP BY reason",
				$start->format( 'Y-m-d' ),
				$end->format( 'Y-m-d' )
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$key = sanitize_key( (string) $row['reason'] );
			if ( 'administrator' === $key ) {
				$key = 'user_role';
			}
			if ( ! in_array( $key, array( 'bot', 'automation', 'unconfirmed', 'geo_excluded', 'user_role', 'ip', 'rate_limit', 'origin' ), true ) ) {
				$key = 'other';
			}
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + (int) $row['total'];
		}
		arsort( $counts );

		$labels = array(
			'bot'        => __( 'Bot・自動巡回', 'access-analytics-plus' ),
			'automation' => __( 'Bot・自動巡回', 'access-analytics-plus' ),
			'unconfirmed' => __( '未確認アクセス', 'access-analytics-plus' ),
			'geo_excluded' => __( '地域設定による対象外', 'access-analytics-plus' ),
			'user_role'  => __( 'ログインユーザー', 'access-analytics-plus' ),
			'ip'         => __( 'IP除外', 'access-analytics-plus' ),
			'rate_limit' => __( '異常な連続送信', 'access-analytics-plus' ),
			'origin'     => __( '外部からの送信', 'access-analytics-plus' ),
			'other'      => __( 'その他', 'access-analytics-plus' ),
		);
		$items = array();
		foreach ( $counts as $key => $value ) {
			$items[] = array( 'key' => $key, 'label' => $labels[ $key ], 'value' => $value );
		}

		return array( 'total' => array_sum( $counts ), 'items' => $items );
	}

	/**
	 * @return array<int, array{label:string,value:int}>
	 */
	private static function traffic_details( string $type, DateTimeImmutable $start, DateTimeImmutable $end ): array {
		global $wpdb;
		$tables = Database::tables();
		$column = 'search' === $type ? 'search_source' : 'referrer_host';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS source_label, COUNT(*) AS total FROM {$tables['sessions']}
				WHERE referrer_type = %s AND started_at >= %s AND started_at < %s AND {$column} <> ''
				GROUP BY {$column} ORDER BY total DESC LIMIT 5",
				$type,
				self::utc( $start ),
				self::utc( $end )
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ) use ( $type ): array {
				$label = (string) $row['source_label'];
				if ( 'social' === $type ) {
					$social_labels = array(
						'instagram.com' => 'Instagram',
						'facebook.com'  => 'Facebook',
						't.co'          => 'X',
						'twitter.com'   => 'X',
						'x.com'         => 'X',
						'youtube.com'   => 'YouTube',
						'youtu.be'      => 'YouTube',
						'line.me'       => 'LINE',
						'tiktok.com'    => 'TikTok',
					);
					foreach ( $social_labels as $domain => $name ) {
						if ( $label === $domain || str_ends_with( $label, '.' . $domain ) ) {
							$label = $name;
							break;
						}
					}
				}
				return array( 'label' => $label, 'value' => (int) $row['total'] );
			},
			$rows
		);
	}

	/**
	 * @return array<int, array{title:string,path:string,pageviews:int}>
	 */
	private static function popular_pages( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		global $wpdb;
		$tables = Database::tables();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.title, p.path, COUNT(*) AS pageviews
				FROM {$tables['pageviews']} pv INNER JOIN {$tables['pages']} p ON p.id = pv.page_id
				WHERE pv.viewed_at >= %s AND pv.viewed_at < %s
				GROUP BY pv.page_id, p.title, p.path ORDER BY pageviews DESC LIMIT 5",
				self::utc( $start ),
				self::utc( $end )
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ): array => array(
				'title'     => '' !== $row['title'] ? (string) $row['title'] : (string) $row['path'],
				'path'      => (string) $row['path'],
				'pageviews' => (int) $row['pageviews'],
			),
			$rows
		);
	}

	/**
	 * @param int|float|null $value Current value.
	 * @param int|float|null $previous Previous-period value.
	 * @param bool           $zero_is_data Whether a zero previous value is a valid comparison baseline.
	 * @return array{value:int|float|null,previous:int|float|null,change:float|null,difference:float|null,direction:string}
	 */
	private static function metric_payload( int|float|null $value, int|float|null $previous, bool $zero_is_data = false ): array {
		$change     = null !== $value && null !== $previous && $previous > 0 ? round( ( ( $value - $previous ) / $previous ) * 100, 1 ) : null;
		$difference = null !== $value && null !== $previous ? round( $value - $previous, 1 ) : null;
		return array(
			'value'     => $value,
			'previous'  => $previous,
			'change'    => $change,
			'difference' => $difference,
			'direction' => null === $value || null === $previous || ( ! $zero_is_data && 0 == $previous && $value > 0 ) ? 'unavailable' : ( $value > $previous ? 'up' : ( $value < $previous ? 'down' : 'flat' ) ),
		);
	}

	private static function utc( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function valid_date( string $date ): bool {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}
}
