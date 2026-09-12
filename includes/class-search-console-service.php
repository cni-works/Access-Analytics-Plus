<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Optional Search Console bridge that delegates Google access to Site Kit.
 *
 * This class deliberately does not read Site Kit options, user meta, tokens,
 * or instantiate Site Kit PHP classes. The internal REST route is the only
 * integration boundary and every failure is contained in this feature.
 */
final class Search_Console_Service {
	private const SITE_KIT_PLUGIN = 'google-site-kit/google-site-kit.php';
	private const MODULES_ROUTE = '/google-site-kit/v1/core/modules/data/list';
	private const REPORT_ROUTE = '/google-site-kit/v1/modules/search-console/data/searchanalytics';
	private const CACHE_TTL = 2 * HOUR_IN_SECONDS;
	private const VERIFIED_MIN_VERSION = '1.185.0';
	private const VERIFIED_MAX_VERSION = '1.188.0';
	private const DIMENSION = 'query';
	private const LIMIT = 20;

	public static function rest_report( WP_REST_Request $request ): array {
		$range = sanitize_key( (string) $request->get_param( 'range' ) );
		return self::get_report( $range );
	}

	public static function is_site_kit_installed(): bool {
		return is_readable( trailingslashit( WP_PLUGIN_DIR ) . self::SITE_KIT_PLUGIN );
	}

	/**
	 * Returns normalized query data for UI and future report exporters.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_report( string $range = '28d' ): array {
		$range = self::normalize_range( $range );
		$state = self::detect_site_kit();
		if ( 'ready' !== $state['status'] ) {
			return self::result_for_state( $state, $range );
		}

		$module_state = self::get_search_console_module_state();
		if ( is_wp_error( $module_state ) ) {
			return self::result_for_error( $module_state, $state, $range );
		}
		if ( empty( $module_state['active'] ) || empty( $module_state['connected'] ) ) {
			$state['status'] = 'search_console_not_connected';
			$state['message'] = __( 'Site KitでSearch Consoleを接続してください。', 'access-analytics-plus' );
			return self::result_for_state( $state, $range );
		}

		// Re-check Site Kit's user capabilities before serving even a user-scoped cache.
		if ( ! self::current_user_can_view_site_kit_data() ) {
			$state['status'] = 'permission_denied';
			$state['message'] = __( 'Site KitのSearch Console閲覧権限を確認してください。', 'access-analytics-plus' );
			return self::result_for_state( $state, $range );
		}

		$period = self::period( $range );
		$cache_key = self::cache_key( get_current_user_id(), $range, self::DIMENSION, $state['version'] );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['rows'], $cached['fetched_at'] ) && is_array( $cached['rows'] ) ) {
			$diagnostic = isset( $cached['diagnostic'] ) && is_array( $cached['diagnostic'] )
				? self::sanitize_diagnostic( $cached['diagnostic'] )
				: array(
					'top_level_type' => 'cache',
					'row_type'       => empty( $cached['rows'] ) ? 'none' : 'array',
					'row_count'      => count( $cached['rows'] ),
					'schema_result'  => 'valid',
				);
			return self::success_result( $state, $period, $cached['rows'], (string) $cached['fetched_at'], true, $diagnostic );
		}

		$site_kit_request = new WP_REST_Request( 'GET', self::REPORT_ROUTE );
		$site_kit_request->set_query_params(
			array(
				'startDate'  => $period['start'],
				'endDate'    => $period['end'],
				'dimensions' => array( self::DIMENSION ),
				'limit'      => self::LIMIT,
			)
		);
		$response = rest_do_request( $site_kit_request );
		if ( is_wp_error( $response ) ) {
			return self::result_for_error( $response, $state, $range );
		}
		if ( ! $response instanceof WP_REST_Response || $response->get_status() >= 400 ) {
			return self::result_for_error( self::response_error( $response ), $state, $range );
		}

		$diagnostic = array();
		$rows = self::normalize_rows( $response->get_data(), $diagnostic );
		if ( is_wp_error( $rows ) ) {
			return self::result_for_error( $rows, $state, $range );
		}

		$fetched_at = current_datetime()->format( DATE_ATOM );
		set_transient(
			$cache_key,
			array(
				'rows'       => $rows,
				'fetched_at' => $fetched_at,
				'diagnostic' => $diagnostic,
			),
			self::CACHE_TTL
		);

		return self::success_result( $state, $period, $rows, $fetched_at, false, $diagnostic );
	}

	/** @return array<string,mixed> */
	private static function detect_site_kit(): array {
		$plugin_file = trailingslashit( WP_PLUGIN_DIR ) . self::SITE_KIT_PLUGIN;
		$state = array(
			'site_kit_installed' => self::is_site_kit_installed(),
			'site_kit_active'    => false,
			'version'            => '',
			'compatibility'      => 'unknown',
			'status'             => 'site_kit_missing',
			'message'            => __( 'Google検索分析を利用するにはSite Kitをインストールしてください。', 'access-analytics-plus' ),
		);
		if ( ! $state['site_kit_installed'] ) {
			return $state;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$state['site_kit_active'] = is_plugin_active( self::SITE_KIT_PLUGIN ) || is_plugin_active_for_network( self::SITE_KIT_PLUGIN );
		$headers = get_file_data( $plugin_file, array( 'version' => 'Version' ), 'plugin' );
		$state['version'] = isset( $headers['version'] ) ? sanitize_text_field( (string) $headers['version'] ) : '';
		$state['compatibility'] = self::compatibility( $state['version'] );
		if ( ! $state['site_kit_active'] ) {
			$state['status'] = 'site_kit_inactive';
			$state['message'] = __( 'Site Kitを有効化してください。', 'access-analytics-plus' );
			return $state;
		}

		$routes = rest_get_server()->get_routes();
		if ( ! isset( $routes[ self::MODULES_ROUTE ] ) ) {
			$state['status'] = 'route_unavailable';
			$state['message'] = __( 'Site Kitのデータ取得経路を確認できませんでした。', 'access-analytics-plus' );
			return $state;
		}

		$state['status'] = 'ready';
		$state['message'] = '';
		return $state;
	}

	/** @return array<string,bool>|WP_Error */
	private static function get_search_console_module_state(): array|WP_Error {
		$request = new WP_REST_Request( 'GET', self::MODULES_ROUTE );
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof WP_REST_Response || $response->get_status() >= 400 ) {
			return self::response_error( $response );
		}
		$data = self::to_array( $response->get_data() );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'aap_site_kit_schema', __( 'Site Kitのモジュール情報形式を確認できませんでした。', 'access-analytics-plus' ) );
		}
		foreach ( $data as $module ) {
			if ( is_array( $module ) && 'search-console' === ( $module['slug'] ?? '' ) ) {
				return array(
					'active'    => ! empty( $module['active'] ),
					'connected' => ! empty( $module['connected'] ),
				);
			}
		}
		return new WP_Error( 'aap_search_console_module_missing', __( 'Site KitのSearch Consoleモジュールを確認できませんでした。', 'access-analytics-plus' ) );
	}

	private static function current_user_can_view_site_kit_data(): bool {
		return current_user_can( 'googlesitekit_view_authenticated_dashboard' )
			|| current_user_can( 'googlesitekit_view_posts_insights' )
			|| current_user_can( 'googlesitekit_read_shared_module_data', 'search-console' );
	}

	/** @return array<string,string> */
	private static function period( string $range ): array {
		$days = array( '7d' => 7, '28d' => 28, '3m' => 90 )[ $range ];
		$end = current_datetime()->modify( '-1 day' )->setTime( 0, 0 );
		return array(
			'key'   => $range,
			'start' => $end->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ),
			'end'   => $end->format( 'Y-m-d' ),
		);
	}

	private static function normalize_range( string $range ): string {
		return in_array( $range, array( '7d', '28d', '3m' ), true ) ? $range : '28d';
	}

	private static function compatibility( string $version ): string {
		if ( '' === $version ) {
			return 'unknown';
		}
		return version_compare( $version, self::VERIFIED_MIN_VERSION, '>=' )
			&& version_compare( $version, self::VERIFIED_MAX_VERSION, '<' ) ? 'verified' : 'unverified';
	}

	private static function cache_key( int $user_id, string $range, string $dimension, string $version ): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		return 'aap_sc_' . substr( hash( 'sha256', implode( '|', array( $blog_id, $user_id, $range, $dimension, $version ) ) ), 0, 32 );
	}

	/** @return array<int,array<string,float|int|string>>|WP_Error */
	private static function normalize_rows( mixed $data, array &$diagnostic ): array|WP_Error {
		$raw_rows = is_array( $data ) && array_key_exists( 'rows', $data ) ? $data['rows'] : $data;
		$diagnostic = array(
			'top_level_type' => self::diagnostic_type( $data ),
			'row_type'       => is_array( $raw_rows ) && ! empty( $raw_rows ) ? self::diagnostic_type( reset( $raw_rows ) ) : 'none',
			'row_count'      => is_array( $raw_rows ) ? count( $raw_rows ) : 0,
			'schema_result'  => 'not_evaluated',
		);

		$data = self::to_array( $data );
		if ( null === $data ) {
			return self::schema_error( __( 'Site KitのSearch Console応答を読み取れませんでした。', 'access-analytics-plus' ), $diagnostic, 'encoding_failed' );
		}
		if ( is_array( $data ) && array_key_exists( 'rows', $data ) ) {
			$data = $data['rows'];
		}
		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			return self::schema_error( __( 'Site KitのSearch Console応答形式が想定と異なります。', 'access-analytics-plus' ), $diagnostic, 'invalid_rows_container' );
		}
		$diagnostic['row_count'] = count( $data );

		$normalized = array();
		foreach ( array_slice( $data, 0, self::LIMIT ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['keys'], $row['clicks'], $row['impressions'], $row['ctr'], $row['position'] ) || ! is_array( $row['keys'] ) || ! isset( $row['keys'][0] ) ) {
				return self::schema_error( __( 'Site KitのSearch Console行データが想定と異なります。', 'access-analytics-plus' ), $diagnostic, 'invalid_row' );
			}
			if ( ! is_numeric( $row['clicks'] ) || ! is_numeric( $row['impressions'] ) || ! is_numeric( $row['ctr'] ) || ! is_numeric( $row['position'] ) ) {
				return self::schema_error( __( 'Site KitのSearch Console数値データが想定と異なります。', 'access-analytics-plus' ), $diagnostic, 'invalid_metrics' );
			}
			$query = trim( wp_strip_all_tags( (string) $row['keys'][0] ) );
			if ( '' === $query ) {
				continue;
			}
			$normalized[] = array(
				'query'       => self::truncate_query( $query ),
				'clicks'      => (float) $row['clicks'],
				'impressions' => (float) $row['impressions'],
				'ctr'         => (float) $row['ctr'],
				'position'    => (float) $row['position'],
			);
		}
		$diagnostic['schema_result'] = 'valid';
		return $normalized;
	}

	private static function truncate_query( string $query ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $query, 0, 500 ) : substr( $query, 0, 500 );
	}

	private static function to_array( mixed $value ): mixed {
		$encoded = wp_json_encode( $value );
		return false === $encoded ? null : json_decode( $encoded, true );
	}

	private static function diagnostic_type( mixed $value ): string {
		$type = gettype( $value );
		return match ( $type ) {
			'NULL'    => 'null',
			'integer' => 'integer',
			'double'  => 'float',
			default   => in_array( $type, array( 'array', 'object', 'string', 'boolean', 'resource' ), true ) ? $type : 'unknown',
		};
	}

	/** @param array<string,mixed> $diagnostic */
	private static function schema_error( string $message, array &$diagnostic, string $result ): WP_Error {
		$diagnostic['schema_result'] = $result;
		return new WP_Error( 'aap_site_kit_schema', $message, array( 'diagnostic' => self::sanitize_diagnostic( $diagnostic ) ) );
	}

	/** @param array<string,mixed> $diagnostic @return array<string,int|string> */
	private static function sanitize_diagnostic( array $diagnostic ): array {
		$allowed_types = array( 'array', 'object', 'string', 'integer', 'float', 'boolean', 'resource', 'null', 'none', 'cache', 'not_received', 'unknown' );
		$allowed_schema = array( 'valid', 'not_evaluated', 'encoding_failed', 'invalid_rows_container', 'invalid_row', 'invalid_metrics' );
		return array(
			'top_level_type' => in_array( $diagnostic['top_level_type'] ?? '', $allowed_types, true ) ? (string) $diagnostic['top_level_type'] : 'unknown',
			'row_type'       => in_array( $diagnostic['row_type'] ?? '', $allowed_types, true ) ? (string) $diagnostic['row_type'] : 'unknown',
			'row_count'      => max( 0, (int) ( $diagnostic['row_count'] ?? 0 ) ),
			'schema_result'  => in_array( $diagnostic['schema_result'] ?? '', $allowed_schema, true ) ? (string) $diagnostic['schema_result'] : 'not_evaluated',
		);
	}

	/** @return array<string,int|string> */
	private static function default_diagnostic(): array {
		return array(
			'top_level_type' => 'not_received',
			'row_type'       => 'not_received',
			'row_count'      => 0,
			'schema_result'  => 'not_evaluated',
		);
	}

	private static function response_error( mixed $response ): WP_Error {
		$data = $response instanceof WP_REST_Response ? self::to_array( $response->get_data() ) : null;
		$code = is_array( $data ) && isset( $data['code'] ) ? sanitize_key( (string) $data['code'] ) : 'aap_site_kit_rest_error';
		$message = is_array( $data ) && isset( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : __( 'Site Kitからデータを取得できませんでした。', 'access-analytics-plus' );
		$status = $response instanceof WP_REST_Response ? $response->get_status() : 500;
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** @param array<string,mixed> $state */
	private static function result_for_error( WP_Error $error, array $state, string $range ): array {
		$code = sanitize_key( (string) $error->get_error_code() );
		$message = strtolower( $code . ' ' . $error->get_error_message() );
		$route_unavailable = 'rest_no_route' === $code;
		$reauth = str_contains( $message, 'invalid_grant' ) || str_contains( $message, 'missing_required_scope' ) || str_contains( $message, 'reconnect' ) || str_contains( $message, 'reauth' );
		$denied = str_contains( $message, 'permission' ) || str_contains( $message, 'forbidden' ) || str_contains( $message, 'rest_cookie_invalid_nonce' );
		$state['status'] = $route_unavailable ? 'route_unavailable' : ( $reauth ? 'reauth_required' : ( $denied ? 'permission_denied' : ( 'aap_site_kit_schema' === $code ? 'schema_error' : 'temporary_error' ) ) );
		$state['message'] = $route_unavailable
			? __( 'Site Kitのデータ取得経路を確認できませんでした。', 'access-analytics-plus' )
			: ( $reauth
			? __( 'Site Kit側でGoogleへ再接続してください。', 'access-analytics-plus' )
			: ( $denied
				? __( 'Site KitのSearch Console閲覧権限を確認してください。', 'access-analytics-plus' )
				: ( 'aap_site_kit_schema' === $code
					? __( 'Site Kitから受け取った検索データの形式を確認できませんでした。', 'access-analytics-plus' )
					: __( 'Google検索データを一時的に取得できませんでした。時間をおいて再度お試しください。', 'access-analytics-plus' ) ) ) );
		$result = self::result_for_state( $state, $range );
		$error_data = $error->get_error_data();
		if ( is_array( $error_data ) && isset( $error_data['diagnostic'] ) && is_array( $error_data['diagnostic'] ) ) {
			$result['diagnostic'] = self::sanitize_diagnostic( $error_data['diagnostic'] );
		}
		$result['diagnostic_code'] = $code;
		return $result;
	}

	/** @param array<string,mixed> $state */
	private static function result_for_state( array $state, string $range ): array {
		return array(
			'status'      => $state['status'],
			'message'     => $state['message'],
			'site_kit'    => array(
				'installed'     => (bool) $state['site_kit_installed'],
				'active'        => (bool) $state['site_kit_active'],
				'version'       => (string) $state['version'],
				'compatibility' => (string) $state['compatibility'],
			),
			'period'      => array( 'key' => $range ),
			'dimension'   => self::DIMENSION,
			'rows'        => array(),
			'cache'       => array( 'hit' => false, 'ttl_seconds' => self::CACHE_TTL ),
			'diagnostic'  => self::default_diagnostic(),
			'experimental' => true,
		);
	}

	/** @param array<string,mixed> $state @param array<string,string> $period @param array<int,array<string,mixed>> $rows */
	private static function success_result( array $state, array $period, array $rows, string $fetched_at, bool $cache_hit, array $diagnostic ): array {
		$result = self::result_for_state( $state, $period['key'] );
		$result['status'] = 'ready';
		$result['message'] = '';
		$result['period'] = $period;
		$result['rows'] = $rows;
		$result['fetched_at'] = $fetched_at;
		$result['cache']['hit'] = $cache_hit;
		$result['diagnostic'] = self::sanitize_diagnostic( $diagnostic );
		return $result;
	}
}
