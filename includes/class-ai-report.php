<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Builds a format-neutral, aggregate-only payload for user-initiated AI consultation exports. */
final class AI_Report {
	public const SCHEMA_VERSION = 1;
	private const MAX_INPUT_LENGTH = 2000;

	/** @param array<string,mixed> $source @return array<string,mixed>|WP_Error */
	public static function normalize_request( array $source ): array|WP_Error {
		$range = sanitize_key( (string) ( $source['report_range'] ?? '28d' ) );
		if ( ! in_array( $range, array( '7d', '28d', '3m', 'custom' ), true ) ) {
			$range = '28d';
		}
		$period = self::period( $range, (string) ( $source['start'] ?? '' ), (string) ( $source['end'] ?? '' ) );
		if ( is_wp_error( $period ) ) {
			return $period;
		}

		$inquiries = null;
		if ( isset( $source['inquiries'] ) && '' !== trim( (string) $source['inquiries'] ) ) {
			$inquiries = filter_var( $source['inquiries'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => 1000000 ) ) );
			if ( false === $inquiries ) {
				return new WP_Error( 'aap_ai_report_invalid_inquiries', __( '問い合わせ件数は0以上の整数で入力してください。', 'access-analytics-plus' ) );
			}
		}

		return array(
			'range'            => $range,
			'period'           => $period,
			'concern'          => self::clean_text( (string) ( $source['concern'] ?? '' ) ),
			'question'         => self::clean_text( (string) ( $source['question'] ?? '' ) ),
			'inquiries'        => $inquiries,
			'include_search'    => ! empty( $source['include_search'] ),
			'include_paths'     => ! empty( $source['include_paths'] ),
		);
	}

	/** @param array<string,mixed> $request @param array<string,string> $profile @return array<string,mixed>|WP_Error */
	public static function generate( array $request, array $profile ): array|WP_Error {
		if ( Settings::sample_enabled() ) {
			return new WP_Error( 'aap_ai_report_sample', __( '現在サンプルデータを表示しているため、AI相談用レポートは生成できません。', 'access-analytics-plus' ) );
		}
		if ( empty( $request['period'] ) || ! is_array( $request['period'] ) ) {
			return new WP_Error( 'aap_ai_report_period', __( '集計期間を確認できませんでした。', 'access-analytics-plus' ) );
		}

		$period = $request['period'];
		$analytics_request = new WP_REST_Request( 'GET', '/access-analytics-plus/v1/report' );
		$analytics_request->set_query_params(
			array(
				'range'   => 'custom',
				'start'   => $period['start']->format( 'Y-m-d' ),
				'end'     => $period['end']->format( 'Y-m-d' ),
				'context' => 'full',
			)
		);
		$response = Analytics::report( $analytics_request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof WP_REST_Response || ! is_array( $response->get_data() ) ) {
			return new WP_Error( 'aap_ai_report_analytics', __( 'アクセス解析データを取得できませんでした。', 'access-analytics-plus' ) );
		}

		$analytics = $response->get_data();
		$pages = self::popular_public_pages( $period['start'], $period['end_exclusive'], (bool) $request['include_paths'], (int) ( $analytics['metrics']['pageviews']['value'] ?? 0 ) );
		$countries = self::countries( $period['start'], $period['end_exclusive'] );
		$search = self::search_console( $request );
		return self::build_payload(
			$analytics,
			$search,
			$request,
			$profile,
			array(
				'name' => sanitize_text_field( (string) get_bloginfo( 'name' ) ),
				'url'  => esc_url_raw( home_url( '/' ) ),
			),
			$pages,
			$countries
		);
	}

	/**
	 * Pure normalization boundary used by renderers and tests.
	 *
	 * @param array<string,mixed>  $analytics
	 * @param array<string,mixed>  $search
	 * @param array<string,mixed>  $request
	 * @param array<string,string> $profile
	 * @param array<string,string> $site
	 * @param array<int,array<string,mixed>> $pages
	 * @param array<int,array<string,mixed>> $countries
	 * @return array<string,mixed>
	 */
	public static function build_payload( array $analytics, array $search, array $request, array $profile, array $site, array $pages, array $countries ): array {
		$period = $request['period'];
		$days = (int) $period['start']->diff( $period['end_exclusive'] )->days;
		$visits = (int) ( $analytics['metrics']['visits']['value'] ?? 0 );
		$available_days = self::available_days( $period['start'], $period['end_exclusive'] );
		$comparison_days = self::available_days( $period['previous_start'], $period['start'] );
		$regions = isset( $analytics['regions'] ) && is_array( $analytics['regions'] ) ? $analytics['regions'] : array( 'total' => 0, 'items' => array(), 'tracking_started' => '', 'partial' => false );
		$country_known = array_sum( array_map( static fn ( array $row ): int => 'ZZ' === ( $row['key'] ?? 'ZZ' ) ? 0 : (int) ( $row['value'] ?? 0 ), $countries ) );
		$country_total = array_sum( array_map( static fn ( array $row ): int => (int) ( $row['value'] ?? 0 ), $countries ) );
		$region_known = array_sum( array_map( static fn ( array $row ): int => 'unknown' === ( $row['key'] ?? 'unknown' ) ? 0 : (int) ( $row['value'] ?? 0 ), (array) ( $regions['items'] ?? array() ) ) );
		$region_total = (int) ( $regions['total'] ?? 0 );

		$missing = array();
		if ( ! empty( $request['include_search'] ) && 'ready' !== ( $search['status'] ?? '' ) ) {
			$missing[] = (string) ( $search['message'] ?? __( 'Google検索データは含まれていません。', 'access-analytics-plus' ) );
		} elseif ( ! empty( $request['include_search'] ) && empty( $search['rows'] ) ) {
			$missing[] = __( 'Google検索キーワードは対象期間にデータがありません。', 'access-analytics-plus' );
		}
		if ( 0 === $visits ) {
			$missing[] = __( '対象期間に確認済みの訪問データがありません。', 'access-analytics-plus' );
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'generated_at'   => current_datetime()->format( DATE_ATOM ),
			'site'           => $site,
			'profile'        => array(
				'purpose'        => self::clean_text( $profile['purpose'] ?? '' ),
				'target_area'    => self::clean_text( $profile['target_area'] ?? '' ),
				'focus_services' => self::clean_text( $profile['focus_services'] ?? '' ),
			),
			'consultation'   => array(
				'concern'   => self::clean_text( (string) ( $request['concern'] ?? '' ) ),
				'question'  => self::clean_text( (string) ( $request['question'] ?? '' ) ),
				'inquiries' => $request['inquiries'] ?? null,
			),
			'period'         => array(
				'start'            => $period['start']->format( 'Y-m-d' ),
				'end'              => $period['end']->format( 'Y-m-d' ),
				'days'             => $days,
				'comparison_start' => $period['previous_start']->format( 'Y-m-d' ),
				'comparison_end'   => $period['start']->modify( '-1 day' )->format( 'Y-m-d' ),
			),
			'sufficiency'    => self::sufficiency( $days, $available_days, $visits, $comparison_days, $country_total > 0 ? round( $country_known / $country_total * 100, 1 ) : null, count( (array) ( $search['rows'] ?? array() ) ) ),
			'analytics'      => array(
				'metrics' => (array) ( $analytics['metrics'] ?? array() ),
				'quality' => (array) ( $analytics['quality'] ?? array() ),
				'sources' => self::normalize_sources( (array) ( $analytics['sources'] ?? array() ) ),
				'pages'   => array_slice( $pages, 0, 10 ),
				'devices' => self::normalize_devices( (array) ( $analytics['devices'] ?? array() ) ),
				'geography' => array(
					'countries'                => $countries,
					'country_detection_rate'   => $country_total > 0 ? round( $country_known / $country_total * 100, 1 ) : null,
					'prefectures'               => array_slice( (array) ( $regions['items'] ?? array() ), 0, 10 ),
					'prefecture_detection_rate' => $region_total > 0 ? round( $region_known / $region_total * 100, 1 ) : null,
					'tracking_started'          => (string) ( $regions['tracking_started'] ?? '' ),
					'partial'                   => (bool) ( $regions['partial'] ?? false ),
				),
			),
			'search_console' => array(
				'included'   => (bool) ( $request['include_search'] ?? false ),
				'status'     => (string) ( $search['status'] ?? 'not_requested' ),
				'message'    => (string) ( $search['message'] ?? '' ),
				'period'     => (array) ( $search['period'] ?? array() ),
				'fetched_at' => (string) ( $search['fetched_at'] ?? '' ),
				'rows'       => array_slice( (array) ( $search['rows'] ?? array() ), 0, 20 ),
			),
			'missing'        => array_values( array_unique( array_filter( $missing ) ) ),
			'include_paths'  => (bool) ( $request['include_paths'] ?? false ),
		);
	}

	/** @return array<string,mixed> */
	public static function sufficiency( int $selected_days, int $available_days, int $visits, int $comparison_days, ?float $region_rate, int $query_count ): array {
		$period_level = $available_days <= 2 ? 'very_low' : ( $available_days <= 6 ? 'low' : ( $available_days <= 27 ? 'short' : 'standard' ) );
		$volume_level = $visits < 5 ? 'very_low' : ( $visits < 20 ? 'low' : ( $visits < 50 ? 'modest' : 'sufficient' ) );
		$limited = in_array( $period_level, array( 'very_low', 'low' ), true ) || in_array( $volume_level, array( 'very_low', 'low' ), true );
		return array(
			'selected_days'     => $selected_days,
			'available_days'    => $available_days,
			'period_level'      => $period_level,
			'period_label'      => array( 'very_low' => '非常に少ない', 'low' => '少ない', 'short' => '短期傾向', 'standard' => '標準的な分析期間' )[ $period_level ],
			'visits'            => $visits,
			'volume_level'      => $volume_level,
			'volume_label'      => array( 'very_low' => '非常に少ない', 'low' => '少ない', 'modest' => '小規模サイトの傾向確認向き', 'sufficient' => '十分' )[ $volume_level ],
			'overall'           => $limited ? '参考データとして利用できます' : '傾向の確認に利用できます',
			'limited'           => $limited,
			'region_rate'       => $region_rate,
			'query_count'       => $query_count,
			'comparison_days'   => $comparison_days,
		);
	}

	private static function search_console( array $request ): array {
		if ( empty( $request['include_search'] ) ) {
			return array( 'status' => 'not_requested', 'message' => __( 'Google検索データは利用者の選択により含まれていません。', 'access-analytics-plus' ), 'rows' => array() );
		}
		if ( 'custom' === ( $request['range'] ?? '' ) ) {
			return array( 'status' => 'custom_period_unavailable', 'message' => __( '期間指定ではGoogle検索データを含められません。7日・28日・3か月を選ぶと利用できます。', 'access-analytics-plus' ), 'rows' => array() );
		}
		$result = Search_Console_Service::get_report( (string) $request['range'] );
		if ( 'ready' === ( $result['status'] ?? '' ) ) {
			$result['rows'] = array_map(
				static fn ( array $row ): array => array(
					'query'       => AI_Report_Markdown::mask_query( (string) ( $row['query'] ?? '' ) ),
					'clicks'      => (float) ( $row['clicks'] ?? 0 ),
					'impressions' => (float) ( $row['impressions'] ?? 0 ),
					'ctr'         => (float) ( $row['ctr'] ?? 0 ),
					'position'    => (float) ( $row['position'] ?? 0 ),
				),
				(array) ( $result['rows'] ?? array() )
			);
			return $result;
		}
		return array(
			'status'  => (string) ( $result['status'] ?? 'error' ),
			'message' => self::search_status_message( (string) ( $result['status'] ?? 'error' ) ),
			'rows'    => array(),
		);
	}

	private static function search_status_message( string $status ): string {
		return match ( $status ) {
			'site_kit_missing'             => __( 'Site Kitが未導入のため、Google検索データは含まれていません。', 'access-analytics-plus' ),
			'site_kit_inactive'            => __( 'Site Kitが停止中のため、Google検索データは含まれていません。', 'access-analytics-plus' ),
			'search_console_not_connected' => __( 'Site KitでSearch Consoleが未接続のため、Google検索データは含まれていません。', 'access-analytics-plus' ),
			'permission_denied'             => __( 'Google検索データの閲覧権限を確認できなかったため含まれていません。', 'access-analytics-plus' ),
			'reauth_required'               => __( 'Site KitでGoogleへの再接続が必要なため、Google検索データは含まれていません。', 'access-analytics-plus' ),
			'temporary_error'               => __( 'Google検索データを一時的に取得できなかったため含まれていません。', 'access-analytics-plus' ),
			'schema_error'                  => __( 'Google検索データの形式を確認できなかったため含まれていません。', 'access-analytics-plus' ),
			default                         => __( 'Google検索データを取得できなかったため含まれていません。', 'access-analytics-plus' ),
		};
	}

	/** @return array{start:DateTimeImmutable,end:DateTimeImmutable,end_exclusive:DateTimeImmutable,previous_start:DateTimeImmutable}|WP_Error */
	private static function period( string $range, string $start, string $end ): array|WP_Error {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		if ( 'custom' === $range ) {
			if ( ! self::valid_date( $start ) || ! self::valid_date( $end ) ) {
				return new WP_Error( 'aap_ai_report_invalid_period', __( '期間を正しく指定してください。', 'access-analytics-plus' ) );
			}
			$period_start = new DateTimeImmutable( $start . ' 00:00:00', wp_timezone() );
			$period_end = new DateTimeImmutable( $end . ' 00:00:00', wp_timezone() );
			if ( $period_start > $period_end || $period_start->diff( $period_end )->days >= 90 || $period_end > $today ) {
				return new WP_Error( 'aap_ai_report_period_too_long', __( '期間指定は今日までの90日以内にしてください。', 'access-analytics-plus' ) );
			}
		} else {
			$days = array( '7d' => 7, '28d' => 28, '3m' => 90 )[ $range ];
			$period_end = $today;
			$period_start = $today->sub( new DateInterval( 'P' . ( $days - 1 ) . 'D' ) );
		}
		$end_exclusive = $period_end->modify( '+1 day' );
		$days = (int) $period_start->diff( $end_exclusive )->days;
		return array(
			'start'          => $period_start,
			'end'            => $period_end,
			'end_exclusive'  => $end_exclusive,
			'previous_start' => $period_start->sub( new DateInterval( 'P' . $days . 'D' ) ),
		);
	}

	private static function valid_date( string $value ): bool {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}

	private static function available_days( DateTimeImmutable $start, DateTimeImmutable $end ): int {
		$tracking = (string) get_option( 'aap_confirmation_started_at', get_option( 'aap_installed_at', '' ) );
		if ( '' === $tracking ) {
			return (int) $start->diff( $end )->days;
		}
		try {
			$tracking_date = ( new DateTimeImmutable( $tracking, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );
		} catch ( \Exception ) {
			return (int) $start->diff( $end )->days;
		}
		$effective = $tracking_date > $start ? $tracking_date : $start;
		return $effective < $end ? (int) $effective->diff( $end )->days : 0;
	}

	/** @return array<int,array<string,mixed>> */
	private static function popular_public_pages( DateTimeImmutable $start, DateTimeImmutable $end, bool $include_paths, int $total_pageviews ): array {
		global $wpdb;
		$tables = Database::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.title, p.path, COUNT(*) AS pageviews FROM {$tables['pageviews']} pv INNER JOIN {$tables['pages']} p ON p.id = pv.page_id WHERE pv.viewed_at >= %s AND pv.viewed_at < %s GROUP BY pv.page_id, p.title, p.path ORDER BY pageviews DESC LIMIT 20",
				self::utc( $start ),
				self::utc( $end )
			),
			ARRAY_A
		);
		$result = array();
		foreach ( (array) $rows as $row ) {
			$path = self::public_path( (string) ( $row['path'] ?? '' ) );
			if ( null === $path ) {
				continue;
			}
			$pageviews = (int) ( $row['pageviews'] ?? 0 );
			$item = array(
				'title'     => self::clean_text( (string) ( $row['title'] ?? $path ) ),
				'pageviews' => $pageviews,
				'percent'   => $total_pageviews > 0 ? round( $pageviews / $total_pageviews * 100, 1 ) : 0.0,
			);
			if ( $include_paths ) {
				$item['path'] = $path;
			}
			$result[] = $item;
			if ( count( $result ) >= 10 ) {
				break;
			}
		}
		return $result;
	}

	private static function public_path( string $value ): ?string {
		$parts = wp_parse_url( $value );
		$home_parts = wp_parse_url( home_url( '/' ) );
		if (
			is_array( $parts )
			&& isset( $parts['host'] )
			&& ( ! is_array( $home_parts ) || ! isset( $home_parts['host'] ) || 0 !== strcasecmp( (string) $parts['host'], (string) $home_parts['host'] ) )
		) {
			return null;
		}
		$path = is_array( $parts ) ? (string) ( $parts['path'] ?? '' ) : '';
		if ( '' === $path ) {
			$path = '/';
		}
		$path = '/' . ltrim( $path, '/' );
		if ( preg_match( '#^/(?:wp-admin(?:/|$)|wp-login\.php(?:/|$)|wp-json(?:/|$)|xmlrpc\.php(?:/|$))#i', $path ) ) {
			return null;
		}
		$post_id = url_to_postid( home_url( $path ) );
		if ( $post_id > 0 && 'publish' !== get_post_status( $post_id ) ) {
			return null;
		}
		return substr( sanitize_text_field( $path ), 0, 1000 );
	}

	/** @return array<int,array<string,mixed>> */
	private static function countries( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		global $wpdb;
		$table = Database::tables()['sessions'];
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT selected.country_code, COUNT(*) AS visitors FROM {$table} selected INNER JOIN (SELECT visitor_key, MIN(id) AS first_session_id FROM {$table} WHERE started_at >= %s AND started_at < %s GROUP BY visitor_key) first_visit ON first_visit.first_session_id = selected.id GROUP BY selected.country_code ORDER BY visitors DESC, selected.country_code ASC LIMIT 20",
				self::utc( $start ),
				self::utc( $end )
			),
			ARRAY_A
		);
		$total = array_sum( array_map( static fn ( array $row ): int => (int) ( $row['visitors'] ?? 0 ), (array) $rows ) );
		$result = array();
		foreach ( (array) $rows as $row ) {
			$code = strtoupper( sanitize_key( (string) ( $row['country_code'] ?? 'ZZ' ) ) );
			if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
				$code = 'ZZ';
			}
			$value = (int) ( $row['visitors'] ?? 0 );
			$result[] = array(
				'key'     => $code,
				'label'   => 'JP' === $code ? __( '日本', 'access-analytics-plus' ) : ( 'ZZ' === $code ? __( '判定不能', 'access-analytics-plus' ) : $code ),
				'value'   => $value,
				'percent' => $total > 0 ? round( $value / $total * 100, 1 ) : 0.0,
			);
		}
		return $result;
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private static function normalize_sources( array $rows ): array {
		$labels = array( 'search' => '検索', 'direct' => 'ダイレクト', 'social' => 'SNS', 'external' => '外部サイト', 'other' => 'その他' );
		$indexed = array();
		foreach ( $rows as $row ) {
			$key = isset( $labels[ $row['key'] ?? '' ] ) ? (string) $row['key'] : 'other';
			$indexed[ $key ] = array( 'key' => $key, 'label' => $labels[ $key ], 'value' => (int) ( $row['value'] ?? 0 ), 'percent' => (float) ( $row['percent'] ?? 0 ) );
		}
		return array_map( static fn ( string $key ): array => $indexed[ $key ] ?? array( 'key' => $key, 'label' => $labels[ $key ], 'value' => 0, 'percent' => 0.0 ), array_keys( $labels ) );
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private static function normalize_devices( array $rows ): array {
		$labels = array( 'mobile' => 'スマートフォン', 'desktop' => 'PC', 'tablet' => 'タブレット', 'other' => '判定不能' );
		$indexed = array();
		foreach ( $rows as $row ) {
			$key = isset( $labels[ $row['key'] ?? '' ] ) ? (string) $row['key'] : 'other';
			$indexed[ $key ] = array( 'key' => $key, 'label' => $labels[ $key ], 'value' => (int) ( $row['value'] ?? 0 ), 'percent' => (float) ( $row['percent'] ?? 0 ) );
		}
		return array_map( static fn ( string $key ): array => $indexed[ $key ] ?? array( 'key' => $key, 'label' => $labels[ $key ], 'value' => 0, 'percent' => 0.0 ), array_keys( $labels ) );
	}

	private static function clean_text( string $value ): string {
		$value = sanitize_textarea_field( $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::MAX_INPUT_LENGTH );
		}
		return 1 === preg_match( '/^(.{0,' . self::MAX_INPUT_LENGTH . '})/us', $value, $match ) ? $match[1] : substr( $value, 0, self::MAX_INPUT_LENGTH );
	}

	private static function utc( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
