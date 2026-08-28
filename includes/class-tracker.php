<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Tracker {
	private const SESSION_TIMEOUT = 1800;
	private const ENGAGEMENT_MAX_SECONDS = 1800;
	private const ENGAGEMENT_TOKEN_TTL = 2700;

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_init', array( self::class, 'add_privacy_policy_content' ) );
		add_filter( 'script_loader_tag', array( self::class, 'tracking_script_attributes' ), 10, 2 );
		add_filter( 'litespeed_optimize_js_excludes', array( self::class, 'litespeed_js_excludes' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( self::class, 'litespeed_js_excludes' ) );
		add_filter( 'litespeed_optm_gm_js_exc', array( self::class, 'litespeed_js_excludes' ) );
	}

	public static function tracking_script_attributes( string $tag, string $handle ): string {
		if ( 'aap-tracker' !== $handle || str_contains( $tag, 'data-cfasync=' ) ) {
			return $tag;
		}
		$tag_with_attributes = preg_replace(
			'/<script\s+/',
			'<script data-cfasync="false" data-wpfc-render="false" data-no-defer="1" ',
			$tag,
			1
		);
		return is_string( $tag_with_attributes ) ? $tag_with_attributes : $tag;
	}

	/**
	 * @param mixed $excludes LiteSpeed Cache exclusion list.
	 * @return string[]
	 */
	public static function litespeed_js_excludes( mixed $excludes ): array {
		$list   = is_array( $excludes ) ? $excludes : array();
		$list[] = 'access-analytics-plus/assets/js/tracker.js';
		return array_values( array_unique( $list ) );
	}

	public static function enqueue(): void {
		if ( is_admin() || is_feed() || is_robots() || wp_doing_ajax() ) {
			return;
		}

		/**
		 * Allows a consent-management plugin or site policy to disable tracking.
		 *
		 * @param bool $enabled Whether the tracking script should be loaded.
		 */
		if ( ! (bool) apply_filters( 'aap_tracking_enabled', Settings::tracking_enabled() ) ) {
			return;
		}

		if ( is_user_logged_in() && Settings::is_user_excluded( get_current_user_id() ) ) {
			self::record_exclusion( 'user_role' );
			return;
		}

		wp_enqueue_script(
			'aap-tracker',
			AAP_PLUGIN_URL . 'assets/js/tracker.js',
			array(),
			AAP_VERSION . '.' . AAP_BUILD,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'aap-tracker',
			'aapTracker',
			array(
				'endpoint'                 => esc_url_raw( rest_url( 'access-analytics-plus/v1/collect' ) ),
				'engagementEndpoint'       => esc_url_raw( rest_url( 'access-analytics-plus/v1/engagement' ) ),
				'sessionTimeout'           => self::SESSION_TIMEOUT,
				'engagementMaxSeconds'     => self::ENGAGEMENT_MAX_SECONDS,
				'engagementIdleSeconds'    => 300,
			)
		);
	}

	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'このサイトでは、アクセス傾向を把握するため、匿名のブラウザー識別子、閲覧ページ、流入元、端末分類、ページが画面に表示されていた概算時間をサイト内のデータベースへ保存します。クリック位置や入力内容、生のIPアドレスは解析テーブルへ保存しません。詳細データの初期保存期間は90日です。', 'access-analytics-plus' ) . '</p>';
		wp_add_privacy_policy_content( 'Access Analytics Plus', wp_kses_post( $content ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function collect( WP_REST_Request $request ) {
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( $_SERVER['CONTENT_LENGTH'] ) : 0;
		if ( $content_length > 16384 ) {
			return new WP_Error( 'aap_payload_too_large', __( '送信データが大きすぎます。', 'access-analytics-plus' ), array( 'status' => 413 ) );
		}

		if ( ! self::is_same_origin( $request ) ) {
			self::record_exclusion( 'origin' );
			return new WP_Error( 'aap_invalid_origin', __( '送信元を確認できません。', 'access-analytics-plus' ), array( 'status' => 403 ) );
		}

		if ( ! Settings::tracking_enabled() ) {
			return new WP_REST_Response( array( 'accepted' => false ), 202 );
		}

		if ( self::is_excluded_user() ) {
			self::record_exclusion( 'user_role' );
			return new WP_REST_Response( array( 'accepted' => false ), 202 );
		}

		if ( Settings::is_current_ip_excluded() ) {
			self::record_exclusion( 'ip' );
			return new WP_REST_Response( array( 'accepted' => false ), 202 );
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '';
		if ( self::is_bot( $user_agent ) ) {
			self::record_exclusion( 'bot' );
			return new WP_REST_Response( array( 'accepted' => false ), 202 );
		}

		$visitor_id = strtolower( (string) $request->get_param( 'visitor_id' ) );
		$session_id = strtolower( (string) $request->get_param( 'session_id' ) );

		if ( ! self::passes_rate_limit( $visitor_id ) ) {
			self::record_exclusion( 'rate_limit' );
			return new WP_Error( 'aap_rate_limited', __( '送信回数が多すぎます。', 'access-analytics-plus' ), array( 'status' => 429 ) );
		}

		$path       = self::normalize_path( (string) $request->get_param( 'path' ) );
		$title      = substr( sanitize_text_field( (string) $request->get_param( 'title' ) ), 0, 500 );
		$referrer   = esc_url_raw( (string) $request->get_param( 'referrer' ) );
		$device_type = sanitize_key( (string) $request->get_param( 'device_type' ) );

		if ( '' === $path ) {
			return new WP_Error( 'aap_invalid_path', __( 'ページ情報が正しくありません。', 'access-analytics-plus' ), array( 'status' => 400 ) );
		}

		return self::store_pageview( $visitor_id, $session_id, $path, $title, $referrer, $user_agent, $device_type );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function record_engagement( WP_REST_Request $request ) {
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( $_SERVER['CONTENT_LENGTH'] ) : 0;
		if ( $content_length > 4096 ) {
			return new WP_Error( 'aap_payload_too_large', __( '送信データが大きすぎます。', 'access-analytics-plus' ), array( 'status' => 413 ) );
		}

		if ( ! self::is_same_origin( $request ) ) {
			self::record_exclusion( 'origin' );
			return new WP_Error( 'aap_invalid_origin', __( '送信元を確認できません。', 'access-analytics-plus' ), array( 'status' => 403 ) );
		}

		if ( ! Settings::tracking_enabled() ) {
			return new WP_REST_Response( array( 'accepted' => false ), 202 );
		}

		if ( self::is_excluded_user() ) {
			self::record_exclusion( 'user_role' );
			return new WP_REST_Response( array( 'accepted' => false ), 202 );
		}

		if ( Settings::is_current_ip_excluded() ) {
			self::record_exclusion( 'ip' );
			return new WP_REST_Response( array( 'accepted' => false ), 202 );
		}

		$token_data = self::verify_engagement_token( (string) $request->get_param( 'token' ) );
		if ( false === $token_data ) {
			return new WP_Error( 'aap_invalid_engagement_token', __( '計測情報の有効期限が切れています。', 'access-analytics-plus' ), array( 'status' => 403 ) );
		}

		global $wpdb;
		$tables          = Database::tables();
		$elapsed_seconds = max( 0, time() - $token_data['issued_at'] + 5 );
		$seconds         = min( self::ENGAGEMENT_MAX_SECONDS, $elapsed_seconds, absint( $request->get_param( 'seconds' ) ) );

		$wpdb->query( 'START TRANSACTION' );
		$pageview = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, session_id, viewed_at, engaged_seconds FROM {$tables['pageviews']} WHERE id = %d LIMIT 1 FOR UPDATE",
				$token_data['pageview_id']
			),
			ARRAY_A
		);

		if ( ! $pageview ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'aap_pageview_not_found', __( '閲覧情報を確認できませんでした。', 'access-analytics-plus' ), array( 'status' => 404 ) );
		}

		$stored_seconds = (int) $pageview['engaged_seconds'];
		if ( $seconds <= $stored_seconds ) {
			$wpdb->query( 'COMMIT' );
			return new WP_REST_Response( array( 'accepted' => true, 'engaged_seconds' => $stored_seconds ), 200 );
		}

		$updated = $wpdb->update(
			$tables['pageviews'],
			array( 'engaged_seconds' => $seconds ),
			array( 'id' => (int) $pageview['id'] ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'aap_engagement_error', __( '閲覧時間を記録できませんでした。', 'access-analytics-plus' ), array( 'status' => 500 ) );
		}

		$viewed_at = new \DateTimeImmutable( (string) $pageview['viewed_at'], new \DateTimeZone( 'UTC' ) );
		$stat_date = $viewed_at->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		$delta     = $seconds - $stored_seconds;
		$daily_updated = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['daily']} (stat_date, engaged_seconds) VALUES (%s, %d)
				ON DUPLICATE KEY UPDATE engaged_seconds = engaged_seconds + VALUES(engaged_seconds)",
				$stat_date,
				$delta
			)
		);

		$session_updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['sessions']} SET last_seen_at = GREATEST(last_seen_at, %s) WHERE id = %d",
				current_time( 'mysql', true ),
				(int) $pageview['session_id']
			)
		);

		if ( false === $daily_updated || false === $session_updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'aap_engagement_error', __( '閲覧時間を記録できませんでした。', 'access-analytics-plus' ), array( 'status' => 500 ) );
		}

		$wpdb->query( 'COMMIT' );
		return new WP_REST_Response( array( 'accepted' => true, 'engaged_seconds' => $seconds ), 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	private static function store_pageview( string $visitor_id, string $session_id, string $path, string $title, string $referrer, string $user_agent, string $device_type ) {
		global $wpdb;

		$tables      = Database::tables();
		$now_utc     = current_time( 'mysql', true );
		$stat_date   = current_datetime()->format( 'Y-m-d' );
		$visitor_key = hash_hmac( 'sha256', $visitor_id, wp_salt( 'auth' ) );
		$session_key = hash_hmac( 'sha256', $session_id, wp_salt( 'secure_auth' ) );
		$page_id     = self::find_or_create_page( $path, $title, $now_utc );

		if ( ! $page_id ) {
			return new WP_Error( 'aap_page_error', __( 'ページを記録できませんでした。', 'access-analytics-plus' ), array( 'status' => 500 ) );
		}

		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, last_seen_at FROM {$tables['sessions']} WHERE session_key = %s LIMIT 1",
				$session_key
			),
			ARRAY_A
		);

		if ( $session ) {
			if ( strtotime( $now_utc ) - strtotime( (string) $session['last_seen_at'] ) > self::SESSION_TIMEOUT ) {
				return new WP_REST_Response(
					array(
						'accepted'     => false,
						'reset_session' => true,
					),
					409
				);
			}

			$session_db_id = (int) $session['id'];
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tables['sessions']} SET last_seen_at = %s, pageview_count = pageview_count + 1 WHERE id = %d",
					$now_utc,
					$session_db_id
				)
			);
		} else {
			$referrer_details = self::classify_referrer( $referrer );
			$inserted         = $wpdb->insert(
				$tables['sessions'],
				array(
					'session_key'    => $session_key,
					'visitor_key'    => $visitor_key,
					'started_at'     => $now_utc,
					'last_seen_at'   => $now_utc,
					'entry_page_id'  => $page_id,
					'pageview_count' => 1,
					'referrer_type'  => $referrer_details['type'],
					'referrer_host'  => $referrer_details['host'],
					'search_source'  => $referrer_details['search_source'],
					'device_type'    => self::classify_device( $user_agent, $device_type ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				return new WP_Error( 'aap_session_error', __( '訪問を記録できませんでした。', 'access-analytics-plus' ), array( 'status' => 500 ) );
			}
			$session_db_id = (int) $wpdb->insert_id;
		}

		$inserted = $wpdb->insert(
			$tables['pageviews'],
			array(
				'session_id' => $session_db_id,
				'page_id'    => $page_id,
				'viewed_at'  => $now_utc,
			),
			array( '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'aap_pageview_error', __( '閲覧を記録できませんでした。', 'access-analytics-plus' ), array( 'status' => 500 ) );
		}

		$pageview_id = (int) $wpdb->insert_id;
		self::update_daily_totals( $stat_date, $visitor_key, $session_db_id );

		return new WP_REST_Response(
			array(
				'accepted'        => true,
				'engagement_token' => self::create_engagement_token( $pageview_id ),
			),
			201
		);
	}

	private static function create_engagement_token( int $pageview_id ): string {
		$issued_at = time();
		$expires   = $issued_at + self::ENGAGEMENT_TOKEN_TTL;
		$payload   = $pageview_id . '|' . $issued_at . '|' . $expires;
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
		return $pageview_id . '.' . $issued_at . '.' . $expires . '.' . $signature;
	}

	/**
	 * @return array{pageview_id:int,issued_at:int}|false
	 */
	private static function verify_engagement_token( string $token ) {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)\.(\d+)\.([a-f0-9]{64})$/', $token, $matches ) ) {
			return false;
		}

		$pageview_id = absint( $matches[1] );
		$issued_at   = absint( $matches[2] );
		$expires     = absint( $matches[3] );
		if ( $pageview_id < 1 || $issued_at > time() + 60 || $expires < time() || $expires - $issued_at !== self::ENGAGEMENT_TOKEN_TTL ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $pageview_id . '|' . $issued_at . '|' . $expires, wp_salt( 'nonce' ) );
		if ( ! hash_equals( $expected, $matches[4] ) ) {
			return false;
		}

		return array( 'pageview_id' => $pageview_id, 'issued_at' => $issued_at );
	}

	private static function find_or_create_page( string $path, string $title, string $now_utc ): int {
		global $wpdb;

		$table    = Database::tables()['pages'];
		$url_hash = hash( 'sha256', $path );
		$page_id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s", $url_hash ) );

		if ( $page_id ) {
			$wpdb->update(
				$table,
				array(
					'title'        => $title,
					'last_seen_at' => $now_utc,
				),
				array( 'id' => $page_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return $page_id;
		}

		$wpdb->insert(
			$table,
			array(
				'url_hash'     => $url_hash,
				'path'         => $path,
				'post_id'      => url_to_postid( home_url( $path ) ) ?: null,
				'title'        => $title,
				'first_seen_at' => $now_utc,
				'last_seen_at' => $now_utc,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( $wpdb->insert_id ) {
			return (int) $wpdb->insert_id;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s", $url_hash ) );
	}

	private static function update_daily_totals( string $date, string $visitor_key, int $session_db_id ): void {
		global $wpdb;

		$tables = Database::tables();
		$wpdb->query( 'START TRANSACTION' );
		$is_new_visit = 1 === $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$tables['daily_dimensions']} (stat_date, dimension_type, dimension_key)
				VALUES (%s, 'unique_visit', %s)",
				$date,
				(string) $session_db_id
			)
		);
		$is_new_visitor = 1 === $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$tables['daily_dimensions']} (stat_date, dimension_type, dimension_key)
				VALUES (%s, 'unique_visitor', %s)",
				$date,
				$visitor_key
			)
		);

		$updated = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['daily']} (stat_date, visitors, visits, pageviews) VALUES (%s, %d, %d, 1)
				ON DUPLICATE KEY UPDATE visitors = visitors + VALUES(visitors), visits = visits + VALUES(visits), pageviews = pageviews + 1",
				$date,
				$is_new_visitor ? 1 : 0,
				$is_new_visit ? 1 : 0
			)
		);
		$wpdb->query( false === $updated ? 'ROLLBACK' : 'COMMIT' );
	}

	private static function normalize_path( string $value ): string {
		$parts = wp_parse_url( $value );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$path  = '/' . ltrim( rawurldecode( $path ), '/' );
		$path  = preg_replace( '#/+#', '/', $path ) ?: '/';
		return substr( sanitize_text_field( $path ), 0, 1000 );
	}

	/**
	 * @return array{type:string,host:string,search_source:string}
	 */
	private static function classify_referrer( string $referrer ): array {
		$host      = substr( strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) ), 0, 191 );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' === $host || $host === $site_host ) {
			return array( 'type' => 'direct', 'host' => '', 'search_source' => '' );
		}

		$search = array(
			'google.'       => 'Google',
			'bing.com'      => 'Bing',
			'search.yahoo.' => 'Yahoo!',
			'duckduckgo.com' => 'DuckDuckGo',
		);
		foreach ( $search as $needle => $label ) {
			$is_match = 'google.' === $needle
				? 1 === preg_match( '/(^|\.)google\.[a-z.]+$/', $host )
				: ( 'search.yahoo.' === $needle
					? 1 === preg_match( '/(^|\.)search\.yahoo\.[a-z.]+$/', $host )
					: self::host_matches( $host, $needle ) );
			if ( $is_match ) {
				return array( 'type' => 'search', 'host' => $host, 'search_source' => $label );
			}
		}

		$social_hosts = array( 'instagram.com', 'facebook.com', 't.co', 'twitter.com', 'x.com', 'youtube.com', 'youtu.be', 'line.me', 'tiktok.com' );
		foreach ( $social_hosts as $social_host ) {
			if ( self::host_matches( $host, $social_host ) ) {
				return array( 'type' => 'social', 'host' => $host, 'search_source' => '' );
			}
		}

		return array( 'type' => 'external', 'host' => $host, 'search_source' => '' );
	}

	private static function host_matches( string $host, string $domain ): bool {
		return $host === $domain || str_ends_with( $host, '.' . $domain );
	}

	private static function classify_device( string $user_agent, string $device_hint = '' ): string {
		if ( in_array( $device_hint, array( 'mobile', 'desktop', 'tablet', 'other' ), true ) ) {
			return $device_hint;
		}
		if ( preg_match( '/ipad|tablet|kindle|silk/i', $user_agent ) ) {
			return 'tablet';
		}
		if ( false !== stripos( $user_agent, 'android' ) && false === stripos( $user_agent, 'mobile' ) ) {
			return 'tablet';
		}
		if ( preg_match( '/mobile|iphone|ipod|android/i', $user_agent ) ) {
			return 'mobile';
		}
		return '' !== $user_agent ? 'desktop' : 'other';
	}

	private static function is_bot( string $user_agent ): bool {
		if ( '' === $user_agent ) {
			return true;
		}
		return 1 === preg_match( '/(?<!cu)bot|crawler|spider|slurp|headless|lighthouse|pagespeed|google-inspectiontool|facebookexternalhit|bingpreview|gptbot|chatgpt-user|oai-searchbot|claudebot|claude-web|anthropic-ai|perplexitybot|bytespider|ccbot|cohere-ai|amazonbot|semrush|ahrefs|mj12|dotbot|screaming frog|uptimerobot|pingdom|statuscake|site24x7|curl|wget|python-requests|go-http-client/i', $user_agent );
	}

	private static function is_same_origin( WP_REST_Request $request ): bool {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			return true;
		}
		return strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	private static function is_excluded_user(): bool {
		if ( ! defined( 'LOGGED_IN_COOKIE' ) || empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return false;
		}
		$cookie  = wp_unslash( (string) $_COOKIE[ LOGGED_IN_COOKIE ] );
		$user_id = wp_validate_auth_cookie( $cookie, 'logged_in' );
		return $user_id > 0 && Settings::is_user_excluded( $user_id );
	}

	private static function passes_rate_limit( string $visitor_id ): bool {
		$ip         = Settings::current_ip();
		$identifier = $ip . '|' . $visitor_id;
		$key        = 'aap_rate_' . substr( hash_hmac( 'sha256', $identifier, wp_salt( 'nonce' ) ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= 120 ) {
			return false;
		}
		set_transient( $key, $count + 1, 5 * MINUTE_IN_SECONDS );
		return true;
	}

	public static function record_exclusion( string $reason ): void {
		global $wpdb;
		$table = Database::tables()['exclusions_daily'];
		$reason = substr( sanitize_key( $reason ), 0, 32 );
		if ( '' === $reason ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (stat_date, reason, excluded_count) VALUES (%s, %s, 1)
				ON DUPLICATE KEY UPDATE excluded_count = excluded_count + 1",
				current_datetime()->format( 'Y-m-d' ),
				$reason
			)
		);
	}
}
