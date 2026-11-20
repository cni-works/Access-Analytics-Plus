<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Read-only, aggregate-only details for one local clock hour. */
final class Hourly_Detail {
	private const CURRENT_TTL = 30;
	private const FINISHED_TTL = 300;

	public static function rest_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( Settings::sample_enabled() ) {
			return self::response(
				array(
					'available' => false,
					'sample'    => true,
					'message'   => __( 'サンプル表示では時間別の詳細内訳を利用できません。実データ表示へ戻すと確認できます。', 'access-analytics-plus' ),
				)
			);
		}

		$date = sanitize_text_field( (string) $request->get_param( 'date' ) );
		$hour = (int) $request->get_param( 'hour' );
		$period = self::period( $date, $hour );
		if ( is_wp_error( $period ) ) {
			return $period;
		}

		$retention = (int) get_option( 'aap_retention_days', 90 );
		$retention = in_array( $retention, array( 90, 180, 365 ), true ) ? $retention : 90;
		$retention_start = current_datetime()->setTime( 0, 0 )->modify( '-' . ( $retention - 1 ) . ' days' );
		if ( $period['end_local'] <= $retention_start ) {
			return self::response(
				array(
					'available' => false,
					'sample'    => false,
					'message'   => sprintf( __( 'この時間の詳細データは保存期間（%d日）を過ぎています。', 'access-analytics-plus' ), $retention ),
				)
			);
		}

		$cache_key = 'aap_hd_' . md5( implode( '|', array( AAP_BUILD, wp_timezone_string(), $period['start_utc'], $period['end_utc'] ) ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$cached['cache_hit'] = true;
			return self::response( $cached );
		}

		$payload = self::aggregate( $period );
		$payload['cache_hit'] = false;
		$ttl = $period['start_local'] <= current_datetime() && current_datetime() < $period['end_local'] ? self::CURRENT_TTL : self::FINISHED_TTL;
		set_transient( $cache_key, $payload, $ttl );
		return self::response( $payload );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function period( string $date, int $hour ): array|WP_Error {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $hour < 0 || $hour > 23 ) {
			return new WP_Error( 'aap_invalid_hour', __( '日付または時間が正しくありません。', 'access-analytics-plus' ), array( 'status' => 400 ) );
		}
		$timezone = wp_timezone();
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' ' . sprintf( '%02d:00:00', $hour ), $timezone );
		if ( false === $start || $start->format( 'Y-m-d G' ) !== $date . ' ' . $hour ) {
			return new WP_Error( 'aap_invalid_hour', __( 'このタイムゾーンには存在しない時間です。', 'access-analytics-plus' ), array( 'status' => 400 ) );
		}
		if ( $start > current_datetime() ) {
			return new WP_Error( 'aap_future_hour', __( '未来の時間は表示できません。', 'access-analytics-plus' ), array( 'status' => 400 ) );
		}
		$end = $start->modify( '+1 hour' );
		$utc = new DateTimeZone( 'UTC' );
		return array(
			'date'        => $date,
			'hour'        => $hour,
			'start_local' => $start,
			'end_local'   => $end,
			'start_utc'   => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end_utc'     => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/** @param array<string,mixed> $period @return array<string,mixed> */
	private static function aggregate( array $period ): array {
		global $wpdb;
		$tables = Database::tables();
		$active_sql = "SELECT DISTINCT pv.session_id FROM {$tables['pageviews']} pv WHERE pv.viewed_at >= %s AND pv.viewed_at < %s";
		$active = $wpdb->prepare( $active_sql, $period['start_utc'], $period['end_utc'] );

		$overview = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT s.visitor_key) visitors, COUNT(DISTINCT pv.session_id) visits, COUNT(*) pageviews
				FROM {$tables['pageviews']} pv INNER JOIN {$tables['sessions']} s ON s.id=pv.session_id
				WHERE pv.viewed_at >= %s AND pv.viewed_at < %s",
				$period['start_utc'],
				$period['end_utc']
			),
			ARRAY_A
		);

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * MINUTE_IN_SECONDS ) );
		$engagement_start = (string) get_option( 'aap_engagement_started_at', '' );
		$quality_start = '' !== $engagement_start && $engagement_start > $period['start_utc'] ? $engagement_start : $period['start_utc'];
		$quality = array( 'average_engaged_seconds' => null, 'bounce_rate' => null, 'ended_visits' => 0, 'provisional' => $period['end_utc'] > $cutoff, 'partial' => $quality_start > $period['start_utc'] );
		if ( $quality_start < $period['end_utc'] ) {
			$row = (array) $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) ended_visits, COALESCE(SUM(q.engaged_seconds),0) engaged_seconds, COALESCE(SUM(q.pageview_count=1),0) bounces
					FROM (SELECT s.id,s.pageview_count,COALESCE(SUM(pv.engaged_seconds),0) engaged_seconds
					FROM {$tables['sessions']} s LEFT JOIN {$tables['pageviews']} pv ON pv.session_id=s.id
					WHERE s.started_at >= %s AND s.started_at < %s AND s.last_seen_at < %s GROUP BY s.id,s.pageview_count) q",
					$quality_start,
					$period['end_utc'],
					$cutoff
				),
				ARRAY_A
			);
			$ended = (int) ( $row['ended_visits'] ?? 0 );
			$quality['ended_visits'] = $ended;
			if ( $ended > 0 ) {
				$quality['average_engaged_seconds'] = (int) round( (int) $row['engaged_seconds'] / $ended );
				$quality['bounce_rate'] = round( ( (int) $row['bounces'] / $ended ) * 100, 1 );
			}
		}

		$countries = self::dimension( "SELECT s.country_code item_key,COUNT(*) total FROM {$tables['sessions']} s INNER JOIN ({$active}) a ON a.session_id=s.id GROUP BY s.country_code ORDER BY total DESC", 'country' );
		$devices = self::dimension( "SELECT s.device_type item_key,COUNT(*) total FROM {$tables['sessions']} s INNER JOIN ({$active}) a ON a.session_id=s.id GROUP BY s.device_type ORDER BY total DESC", 'device' );
		$sources = self::dimension( "SELECT s.referrer_type item_key,COUNT(*) total FROM {$tables['sessions']} s INNER JOIN ({$active}) a ON a.session_id=s.id GROUP BY s.referrer_type ORDER BY total DESC", 'source' );

		$region_started = (string) get_option( 'aap_region_tracking_started_at', '' );
		$region_start = '' !== $region_started && $region_started > $period['start_utc'] ? $region_started : $period['start_utc'];
		$regions = array( 'available' => $region_start < $period['end_utc'], 'partial' => $region_start > $period['start_utc'], 'total' => 0, 'items' => array() );
		if ( $regions['available'] ) {
			$region_active = $wpdb->prepare( $active_sql, $region_start, $period['end_utc'] );
			$regions = array_merge( $regions, self::dimension( "SELECT s.region_code item_key,COUNT(*) total FROM {$tables['sessions']} s INNER JOIN ({$region_active}) a ON a.session_id=s.id WHERE s.country_code='JP' GROUP BY s.region_code ORDER BY total DESC", 'region' ) );
		}

		$page_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.title,COUNT(*) total FROM {$tables['pageviews']} pv INNER JOIN {$tables['pages']} p ON p.id=pv.page_id
				WHERE pv.viewed_at >= %s AND pv.viewed_at < %s GROUP BY pv.page_id,p.title ORDER BY total DESC LIMIT 5",
				$period['start_utc'],
				$period['end_utc']
			),
			ARRAY_A
		);
		$page_total = max( 0, (int) ( $overview['pageviews'] ?? 0 ) );
		$pages = array_map(
			static fn ( array $row ): array => array( 'title' => '' !== trim( (string) $row['title'] ) ? (string) $row['title'] : __( 'タイトルなし', 'access-analytics-plus' ), 'pageviews' => (int) $row['total'], 'percent' => $page_total > 0 ? round( ( (int) $row['total'] / $page_total ) * 100, 1 ) : 0.0 ),
			$page_rows
		);

		$diagnostics = self::diagnostics( $period );
		$confirmation_started = (string) get_option( 'aap_confirmation_started_at', '' );
		return array(
			'available' => true,
			'sample' => false,
			'period' => array( 'date' => $period['date'], 'hour' => $period['hour'], 'label' => sprintf( '%s %02d:00〜%02d:00', $period['date'], $period['hour'], ( $period['hour'] + 1 ) % 24 ), 'timezone' => wp_timezone_string() ),
			'overview' => array( 'visitors' => (int) ( $overview['visitors'] ?? 0 ), 'visits' => (int) ( $overview['visits'] ?? 0 ), 'pageviews' => $page_total ),
			'quality' => $quality,
			'countries' => $countries,
			'regions' => $regions,
			'devices' => $devices,
			'sources' => $sources,
			'pages' => $pages,
			'diagnostics' => $diagnostics,
			'legacy' => '' !== $confirmation_started && $period['start_utc'] < $confirmation_started,
		);
	}

	/** @return array{total:int,items:array<int,array<string,mixed>>} */
	private static function dimension( string $sql, string $type ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- composed from fixed table names and a prepared bounded subquery.
		$total = array_sum( array_map( static fn ( array $row ): int => (int) $row['total'], $rows ) );
		$labels = array(
			'device' => array( 'mobile' => __( 'スマートフォン', 'access-analytics-plus' ), 'desktop' => __( 'PC', 'access-analytics-plus' ), 'tablet' => __( 'タブレット', 'access-analytics-plus' ), 'other' => __( 'その他', 'access-analytics-plus' ) ),
			'source' => array( 'search' => __( '検索', 'access-analytics-plus' ), 'social' => __( 'SNS', 'access-analytics-plus' ), 'direct' => __( 'ダイレクト', 'access-analytics-plus' ), 'external' => __( '外部サイト', 'access-analytics-plus' ) ),
		);
		$items = array();
		foreach ( $rows as $row ) {
			$key = strtoupper( trim( (string) $row['item_key'] ) );
			if ( 'region' === $type ) {
				$key = Region_Resolver::normalize( $key );
				$label = Region_Resolver::label( $key );
			} elseif ( 'country' === $type ) {
				$key = Country_Resolver::normalize( $key );
				$label = self::country_label( $key );
			} else {
				$key = strtolower( $key );
				$label = $labels[ $type ][ $key ] ?? __( 'その他', 'access-analytics-plus' );
			}
			$value = (int) $row['total'];
			$items[] = array( 'key' => '' !== $key ? $key : 'unknown', 'label' => $label, 'value' => $value, 'percent' => $total > 0 ? round( ( $value / $total ) * 100, 1 ) : 0.0 );
		}
		return array( 'total' => $total, 'items' => $items );
	}

	/** @param array<string,mixed> $period @return array<string,mixed> */
	private static function diagnostics( array $period ): array {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( Shadow_Diagnostics::RETENTION_DAYS * DAY_IN_SECONDS ) );
		if ( $period['end_utc'] <= $cutoff ) {
			return array( 'available' => false, 'message' => __( '自動アクセス診断の詳細は直近7日分のみ確認できます。', 'access-analytics-plus' ) );
		}
		$start = max( $period['start_utc'], $cutoff );
		$table = Database::tables()['shadow_events'];
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT shadow_class item_key,COUNT(*) total FROM {$table} WHERE recorded_at >= %s AND recorded_at < %s AND aggregation_mode='staged' GROUP BY shadow_class", $start, $period['end_utc'] ),
			ARRAY_A
		);
		$counts = array( 'pending' => 0, 'unconfirmed' => 0, 'bot' => 0, 'geo_excluded' => 0 );
		foreach ( $rows as $row ) {
			$key = 'suspected' === $row['item_key'] ? 'bot' : (string) $row['item_key'];
			if ( isset( $counts[ $key ] ) ) { $counts[ $key ] += (int) $row['total']; }
		}
		$geo_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT country_code item_key,COUNT(*) total FROM {$table} WHERE recorded_at >= %s AND recorded_at < %s AND aggregation_mode='staged' AND shadow_class='geo_excluded' GROUP BY country_code ORDER BY total DESC", $start, $period['end_utc'] ),
			ARRAY_A
		);
		$geo_total = array_sum( array_map( static fn ( array $row ): int => (int) $row['total'], $geo_rows ) );
		$geo = array_map( static fn ( array $row ): array => array( 'key' => Country_Resolver::normalize( (string) $row['item_key'] ), 'label' => self::country_label( Country_Resolver::normalize( (string) $row['item_key'] ) ), 'value' => (int) $row['total'], 'percent' => $geo_total > 0 ? round( ( (int) $row['total'] / $geo_total ) * 100, 1 ) : 0.0 ), $geo_rows );
		return array( 'available' => true, 'partial' => $start > $period['start_utc'], 'total' => array_sum( $counts ), 'counts' => $counts, 'geo_countries' => $geo );
	}

	private static function country_label( string $code ): string {
		$labels = array( 'JP' => '日本', 'US' => 'アメリカ', 'CN' => '中国', 'KR' => '韓国', 'TW' => '台湾', 'GB' => 'イギリス', 'DE' => 'ドイツ', 'FR' => 'フランス', 'CA' => 'カナダ', 'AU' => 'オーストラリア', 'IN' => 'インド', 'SG' => 'シンガポール', 'RU' => 'ロシア', 'ZZ' => '判定不能' );
		return $labels[ $code ] ?? $code;
	}

	/** @param array<string,mixed> $payload */
	private static function response( array $payload ): WP_REST_Response {
		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}
}
