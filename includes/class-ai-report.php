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
	public const SCHEMA_VERSION = 2;
	private const MAX_INPUT_LENGTH = 2000;
	private const MAX_OTHER_LENGTH = 500;
	private const RECENT_POST_LIMIT = 20;
	private const ALL_POST_LIMIT = 300;
	private const PAGE_LIMIT = 200;

	/** @return array<string,string> */
	public static function concern_options(): array {
		return array(
			'increase_inquiries'  => __( '問い合わせを増やしたい', 'access-analytics-plus' ),
			'increase_visits'     => __( 'アクセス数を増やしたい', 'access-analytics-plus' ),
			'improve_search'      => __( 'Google検索からの流入を増やしたい', 'access-analytics-plus' ),
			'improve_pages'       => __( 'よく見られるページを改善したい', 'access-analytics-plus' ),
			'understand_visitors' => __( '訪問者の傾向を知りたい', 'access-analytics-plus' ),
			'explain_changes'     => __( '数字が増減した理由を知りたい', 'access-analytics-plus' ),
			'other'               => __( 'その他', 'access-analytics-plus' ),
		);
	}

	/** @return array<string,string> */
	public static function advice_options(): array {
		return array(
			'summary'       => __( '現状を短く要約してほしい', 'access-analytics-plus' ),
			'causes'        => __( '数字の理由や課題を分析してほしい', 'access-analytics-plus' ),
			'priorities'    => __( '優先順位をつけて改善案を示してほしい', 'access-analytics-plus' ),
			'quick_actions' => __( 'すぐ実行できる具体策がほしい', 'access-analytics-plus' ),
			'roadmap'       => __( '3か月程度の改善計画を作ってほしい', 'access-analytics-plus' ),
			'other'         => __( 'その他', 'access-analytics-plus' ),
		);
	}

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

		$guided = ! empty( $source['guided_input'] );
		$concern_topics = self::normalize_choices( $source['concern_topics'] ?? array(), self::concern_options() );
		$advice_types = self::normalize_choices( $source['advice_types'] ?? array(), self::advice_options() );
		$concern_other = in_array( 'other', $concern_topics, true ) ? self::clean_other( self::scalar_text( $source['concern_other'] ?? '' ) ) : '';
		$advice_other = in_array( 'other', $advice_types, true ) ? self::clean_other( self::scalar_text( $source['advice_other'] ?? '' ) ) : '';
		$concern = $guided ? self::choices_text( $concern_topics, self::concern_options(), $concern_other ) : self::clean_text( self::scalar_text( $source['concern'] ?? '' ) );
		$question = $guided ? self::choices_text( $advice_types, self::advice_options(), $advice_other ) : self::clean_text( self::scalar_text( $source['question'] ?? '' ) );

		$inquiries = null;
		$accept_inquiries = ! $guided || in_array( 'increase_inquiries', $concern_topics, true );
		$inquiries_input = self::scalar_text( $source['inquiries'] ?? '' );
		if ( $accept_inquiries && '' !== trim( $inquiries_input ) ) {
			$inquiries = filter_var( $inquiries_input, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => 1000000 ) ) );
			if ( false === $inquiries ) {
				return new WP_Error( 'aap_ai_report_invalid_inquiries', __( '問い合わせ件数は0以上の整数で入力してください。', 'access-analytics-plus' ) );
			}
		}

		return array(
			'range'              => $range,
			'period'             => $period,
			'guided_input'       => $guided,
			'concern_topics'     => $concern_topics,
			'concern_other'      => $concern_other,
			'advice_types'       => $advice_types,
			'advice_other'       => $advice_other,
			'concern'            => $concern,
			'question'           => $question,
			'inquiries'          => $inquiries,
			'inquiries_relevant' => $accept_inquiries,
			'include_search'     => ! empty( $source['include_search'] ),
			'include_paths'      => ! empty( $source['include_paths'] ),
			'include_site_structure' => ! empty( $source['include_site_structure'] ),
			'include_all_post_titles' => ! empty( $source['include_site_structure'] ) && ! empty( $source['include_all_post_titles'] ),
		);
	}

	/** @param mixed $source @param array<string,string> $options @return string[] */
	private static function normalize_choices( mixed $source, array $options ): array {
		if ( ! is_array( $source ) ) {
			return array();
		}
		$result = array();
		foreach ( $source as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$key = sanitize_key( (string) $value );
			if ( isset( $options[ $key ] ) && ! in_array( $key, $result, true ) ) {
				$result[] = $key;
			}
		}
		return $result;
	}

	/** @param string[] $choices @param array<string,string> $options */
	private static function choices_text( array $choices, array $options, string $other ): string {
		$lines = array();
		foreach ( $choices as $choice ) {
			if ( 'other' !== $choice && isset( $options[ $choice ] ) ) {
				$lines[] = $options[ $choice ];
			}
		}
		if ( in_array( 'other', $choices, true ) && '' !== $other ) {
			$lines[] = __( 'その他：', 'access-analytics-plus' ) . $other;
		}
		return self::clean_text( implode( "\n", $lines ) );
	}

	private static function clean_other( string $value ): string {
		$value = sanitize_textarea_field( $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::MAX_OTHER_LENGTH );
		}
		return 1 === preg_match( '/^(.{0,' . self::MAX_OTHER_LENGTH . '})/us', $value, $match ) ? $match[1] : substr( $value, 0, self::MAX_OTHER_LENGTH );
	}

	private static function scalar_text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
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
		$site_structure = array( 'included' => false );
		if ( ! empty( $request['include_site_structure'] ) ) {
			try {
				$site_structure = self::site_structure( ! empty( $request['include_all_post_titles'] ), ! empty( $request['include_paths'] ) );
			} catch ( \Throwable ) {
				// Site structure is optional. A third-party post type must not prevent the analytics report from being generated.
				$site_structure = array(
					'included'  => true,
					'available' => false,
					'message'   => __( 'サイト構成を取得できませんでした。アクセス解析レポートのその他の項目は利用できます。', 'access-analytics-plus' ),
				);
			}
		}
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
			$countries,
			$site_structure
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
	 * @param array<string,mixed> $site_structure
	 * @return array<string,mixed>
	 */
	public static function build_payload( array $analytics, array $search, array $request, array $profile, array $site, array $pages, array $countries, array $site_structure = array() ): array {
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
				'concern'            => self::clean_text( (string) ( $request['concern'] ?? '' ) ),
				'question'           => self::clean_text( (string) ( $request['question'] ?? '' ) ),
				'inquiries'          => $request['inquiries'] ?? null,
				'inquiries_relevant' => (bool) ( $request['inquiries_relevant'] ?? true ),
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
				'conversions' => (array) ( $analytics['conversions'] ?? array() ),
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
			'site_structure' => ! empty( $request['include_site_structure'] )
				? array_merge( array( 'included' => true ), $site_structure )
				: array( 'included' => false ),
			'missing'        => array_values( array_unique( array_filter( $missing ) ) ),
			'include_paths'  => (bool) ( $request['include_paths'] ?? false ),
		);
	}

	/**
	 * Returns public WordPress structure only. Post content, authors, drafts,
	 * password-protected content, attachments, and non-public/admin-only post
	 * types are omitted. Public and publicly queryable custom post types are
	 * included because they form part of the visitor-facing site structure.
	 *
	 * @return array<string,mixed>
	 */
	private static function site_structure( bool $include_all_posts, bool $include_paths ): array {
		$raw_pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'menu_order,post_title',
				'sort_order'  => 'ASC',
				'number'      => self::PAGE_LIMIT + 1,
			)
		);
		$page_truncated = count( $raw_pages ) > self::PAGE_LIMIT;
		$raw_pages = array_slice( $raw_pages, 0, self::PAGE_LIMIT );
		$page_ids = array_fill_keys( array_map( static fn ( object $page ): int => (int) $page->ID, $raw_pages ), true );
		$pages = array();
		$latest_modified = '';
		foreach ( $raw_pages as $page ) {
			if ( ! empty( $page->post_password ) ) {
				continue;
			}
			$modified = self::public_modified_date( (string) ( $page->post_modified_gmt ?? '' ) );
			if ( $modified > $latest_modified ) { $latest_modified = $modified; }
			$item = array(
				'title' => self::clean_text( get_the_title( $page ) ),
				'depth' => self::page_depth( $page, $raw_pages, $page_ids ),
			);
			if ( $include_paths ) {
				$path = self::public_path( (string) get_permalink( $page ) );
				if ( null !== $path ) { $item['path'] = $path; }
			}
			$pages[] = $item;
		}

		$post_types = get_post_types( array( 'public' => true, 'publicly_queryable' => true ), 'objects' );
		unset( $post_types['page'], $post_types['attachment'] );
		uasort(
			$post_types,
			static function ( object $left, object $right ): int {
				if ( 'post' === $left->name ) { return -1; }
				if ( 'post' === $right->name ) { return 1; }
				return strnatcasecmp( (string) $left->label, (string) $right->label );
			}
		);
		$post_types = array_slice( $post_types, 0, 10, true );
		$content_types = array();
		$remaining_all = self::ALL_POST_LIMIT;
		$published_posts = 0;
		foreach ( $post_types as $post_type ) {
			$type_key = sanitize_key( (string) $post_type->name );
			if ( '' === $type_key ) { continue; }
			$counts = wp_count_posts( $type_key );
			$published = isset( $counts->publish ) ? max( 0, (int) $counts->publish ) : 0;
			if ( 'post' === $type_key ) { $published_posts = $published; }
			$display_limit = $include_all_posts ? min( $remaining_all, $published ) : min( self::RECENT_POST_LIMIT, $published );
			$raw_posts = $display_limit > 0 ? get_posts(
				array(
					'post_type'              => $type_key,
					'post_status'            => 'publish',
					'posts_per_page'         => $display_limit + 1,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			) : array();
			$posts = array();
			foreach ( array_slice( $raw_posts, 0, $display_limit ) as $post ) {
				if ( ! empty( $post->post_password ) ) { continue; }
				$modified = self::public_modified_date( (string) ( $post->post_modified_gmt ?? '' ) );
				if ( $modified > $latest_modified ) { $latest_modified = $modified; }
				$item = array( 'title' => self::clean_text( get_the_title( $post ) ), 'modified' => $modified );
				if ( $include_paths ) {
					$path = self::public_path( (string) get_permalink( $post ) );
					if ( null !== $path ) { $item['path'] = $path; }
				}
				$posts[] = $item;
			}
			if ( $include_all_posts ) { $remaining_all = max( 0, $remaining_all - count( $posts ) ); }
			$content_types[] = array(
				'key'       => $type_key,
				'label'     => self::clean_text( (string) $post_type->label ),
				'count'     => $published,
				'posts'     => $posts,
				'truncated' => count( $raw_posts ) > $display_limit || $published > count( $posts ),
			);
		}

		$categories = array();
		foreach ( get_categories( array( 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ) ) as $category ) {
			$categories[] = array( 'name' => self::clean_text( (string) $category->name ), 'count' => max( 0, (int) $category->count ) );
		}

		return array(
			'included'                => true,
			'available'               => true,
			'published_pages'         => count( $pages ),
			'published_posts'         => $published_posts,
			'last_modified'           => $latest_modified,
			'pages'                   => $pages,
			'pages_truncated'         => $page_truncated,
			'categories'              => $categories,
			'content_types'            => $content_types,
			'all_post_titles'         => $include_all_posts,
			'post_title_limit'        => $include_all_posts ? self::ALL_POST_LIMIT : self::RECENT_POST_LIMIT,
		);
	}

	/** @param object[] $pages @param array<int,bool> $page_ids */
	private static function page_depth( object $page, array $pages, array $page_ids ): int {
		$parents = array();
		foreach ( $pages as $candidate ) { $parents[ (int) $candidate->ID ] = (int) $candidate->post_parent; }
		$depth = 0;
		$parent = (int) ( $page->post_parent ?? 0 );
		$seen = array();
		while ( $parent > 0 && isset( $page_ids[ $parent ] ) && ! isset( $seen[ $parent ] ) && $depth < 10 ) {
			$seen[ $parent ] = true;
			++$depth;
			$parent = $parents[ $parent ] ?? 0;
		}
		return $depth;
	}

	private static function public_modified_date( string $gmt ): string {
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) { return ''; }
		try {
			return ( new DateTimeImmutable( $gmt, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		} catch ( \Exception ) {
			return '';
		}
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
