<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Records a small allowlist of conversion-related events for confirmed visits. */
final class Event_Tracker {
	private const OPTION_ENABLED = 'aap_enabled_events';
	private const OPTION_CONVERSIONS = 'aap_conversion_events';

	/** @return array<string,array{label:string,category:string,default_enabled:bool,default_conversion:bool}> */
	public static function definitions(): array {
		return array(
			'line_click'    => array( 'label' => __( 'LINEクリック', 'access-analytics-plus' ), 'category' => 'cta', 'default_enabled' => true, 'default_conversion' => true ),
			'tel_click'     => array( 'label' => __( '電話クリック', 'access-analytics-plus' ), 'category' => 'cta', 'default_enabled' => true, 'default_conversion' => true ),
			'contact_click' => array( 'label' => __( 'お問い合わせクリック', 'access-analytics-plus' ), 'category' => 'cta', 'default_enabled' => true, 'default_conversion' => true ),
			'form_submit'   => array( 'label' => __( 'フォーム送信成功', 'access-analytics-plus' ), 'category' => 'form', 'default_enabled' => true, 'default_conversion' => true ),
			'map_click'     => array( 'label' => __( '地図クリック', 'access-analytics-plus' ), 'category' => 'cta', 'default_enabled' => true, 'default_conversion' => false ),
		);
	}

	/** @return string[] */
	public static function enabled_events(): array {
		return self::option_events( self::OPTION_ENABLED, 'default_enabled' );
	}

	/** @return string[] */
	public static function conversion_events(): array {
		return array_values( array_intersect( self::option_events( self::OPTION_CONVERSIONS, 'default_conversion' ), self::enabled_events() ) );
	}

	/** @param mixed $values */
	public static function save_settings( mixed $values, mixed $conversions ): void {
		$allowed = array_keys( self::definitions() );
		$enabled = is_array( $values ) ? array_values( array_intersect( $allowed, array_map( 'sanitize_key', wp_unslash( $values ) ) ) ) : array();
		$conversion_values = is_array( $conversions ) ? array_map( 'sanitize_key', wp_unslash( $conversions ) ) : array();
		$conversion = array_values( array_intersect( $enabled, $conversion_values ) );
		update_option( self::OPTION_ENABLED, $enabled, false );
		update_option( self::OPTION_CONVERSIONS, $conversion, false );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function record( WP_REST_Request $request ) {
		$content_length = (int) $request->get_header( 'content-length' );
		if ( $content_length > 4096 ) {
			return new WP_Error( 'aap_event_too_large', __( 'イベント情報が大きすぎます。', 'access-analytics-plus' ), array( 'status' => 413 ) );
		}
		if ( ! Tracker::request_is_same_origin( $request ) ) {
			return new WP_Error( 'aap_event_origin', __( '送信元を確認できません。', 'access-analytics-plus' ), array( 'status' => 403 ) );
		}
		if ( ! Settings::tracking_enabled() || Tracker::current_user_is_excluded() || Settings::is_current_ip_excluded() ) {
			return new WP_REST_Response( array( 'accepted' => false ), 202 );
		}

		$name = sanitize_key( (string) $request->get_param( 'event_name' ) );
		if ( ! in_array( $name, self::enabled_events(), true ) ) {
			return new WP_Error( 'aap_event_disabled', __( 'このイベントは計測対象ではありません。', 'access-analytics-plus' ), array( 'status' => 400 ) );
		}
		$event_id = strtolower( (string) $request->get_param( 'event_id' ) );
		$event_key = hash_hmac( 'sha256', $event_id, wp_salt( 'nonce' ) );
		$location = sanitize_key( (string) $request->get_param( 'event_location' ) );
		$location = 1 === preg_match( '/^[a-z0-9_-]{1,64}$/', $location ) ? $location : 'content';
		$object_type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$object_type = in_array( $object_type, array( 'link', 'form' ), true ) ? $object_type : 'link';
		$object_key = sanitize_key( (string) $request->get_param( 'object_key' ) );
		$object_key = substr( $object_key, 0, 64 );

		$token = (string) $request->get_param( 'token' );
		$context = Shadow_Diagnostics::accept_event( $token );
		if ( isset( $context['error'] ) ) {
			return new WP_Error( 'aap_event_context_failed', __( '閲覧情報を確定できませんでした。', 'access-analytics-plus' ), array( 'status' => 503 ) );
		}
		if ( empty( $context['confirmed'] ) ) {
			return new WP_REST_Response( array( 'accepted' => false, 'reason' => (string) ( $context['class'] ?? 'invalid' ) ), 202 );
		}

		global $wpdb;
		$table = Database::tables()['events'];
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_key=%s LIMIT 1", $event_key ) );
		if ( $existing > 0 ) {
			return new WP_REST_Response( array( 'accepted' => true, 'duplicate' => true ), 200 );
		}
		if ( ! self::passes_rate_limit( (int) $context['session_id'] ) ) {
			return new WP_Error( 'aap_event_rate_limit', __( 'イベント送信が多すぎます。', 'access-analytics-plus' ), array( 'status' => 429 ) );
		}
		$inserted = $wpdb->insert(
			$table,
			array(
				'event_key'      => $event_key,
				'session_id'     => (int) $context['session_id'],
				'pageview_id'    => (int) $context['pageview_id'],
				'page_id'        => (int) $context['page_id'],
				'event_name'     => $name,
				'event_location' => $location,
				'object_type'    => $object_type,
				'object_key'     => $object_key,
				'occurred_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_key=%s LIMIT 1", $event_key ) );
			if ( $existing > 0 ) {
				return new WP_REST_Response( array( 'accepted' => true, 'duplicate' => true ), 200 );
			}
			return new WP_Error( 'aap_event_store_failed', __( 'イベントを保存できませんでした。', 'access-analytics-plus' ), array( 'status' => 503 ) );
		}
		return new WP_REST_Response( array( 'accepted' => true, 'duplicate' => false ), 201 );
	}

	/** @return array<string,mixed> */
	public static function report( DateTimeImmutable $start, DateTimeImmutable $end, int $visits ): array {
		global $wpdb;
		$tables = Database::tables();
		$table = $tables['events'];
		$from = self::utc( $start );
		$to = self::utc( $end );
		$definitions = self::definitions();
		$counts = array_fill_keys( array_keys( $definitions ), 0 );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT event_name,COUNT(*) total FROM {$table} WHERE occurred_at>=%s AND occurred_at<%s GROUP BY event_name", $from, $to ), ARRAY_A ) as $row ) {
			if ( isset( $counts[ $row['event_name'] ] ) ) { $counts[ $row['event_name'] ] = (int) $row['total']; }
		}
		$items = array();
		foreach ( self::enabled_events() as $name ) {
			$items[] = array( 'key' => $name, 'label' => $definitions[ $name ]['label'], 'value' => $counts[ $name ] ?? 0, 'conversion' => in_array( $name, self::conversion_events(), true ) );
		}

		$conversion_names = self::conversion_events();
		$conversion_visits = 0;
		$page_rows = array();
		if ( $conversion_names ) {
			$placeholders = implode( ',', array_fill( 0, count( $conversion_names ), '%s' ) );
			$params = array_merge( array( $from, $to ), $conversion_names );
			$conversion_visits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT session_id) FROM {$table} WHERE occurred_at>=%s AND occurred_at<%s AND event_name IN ({$placeholders})", ...$params ) );
			$page_rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.page_id,p.title,p.path,COUNT(*) event_count,COUNT(DISTINCT e.session_id) cv_visits
					FROM {$table} e INNER JOIN {$tables['pages']} p ON p.id=e.page_id
					WHERE e.occurred_at>=%s AND e.occurred_at<%s AND e.event_name IN ({$placeholders})
					GROUP BY e.page_id,p.title,p.path ORDER BY cv_visits DESC,event_count DESC LIMIT 10",
					...$params
				),
				ARRAY_A
			);
		}
		$page_ids = array_values( array_filter( array_map( static fn ( array $row ): int => (int) $row['page_id'], $page_rows ) ) );
		$page_visits = array();
		if ( $page_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
			$params = array_merge( array( $from, $to ), $page_ids );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT page_id,COUNT(DISTINCT session_id) viewing_visits FROM {$tables['pageviews']} WHERE viewed_at>=%s AND viewed_at<%s AND page_id IN ({$placeholders}) GROUP BY page_id", ...$params ), ARRAY_A ) as $row ) {
				$page_visits[ (int) $row['page_id'] ] = (int) $row['viewing_visits'];
			}
		}
		$pages = array();
		foreach ( $page_rows as $row ) {
			$viewing_visits = $page_visits[ (int) $row['page_id'] ] ?? 0;
			$pages[] = array(
				'page_id'        => (int) $row['page_id'],
				'title'          => sanitize_text_field( (string) $row['title'] ),
				'path'           => sanitize_text_field( (string) $row['path'] ),
				'event_count'    => (int) $row['event_count'],
				'cv_visits'      => (int) $row['cv_visits'],
				'viewing_visits' => $viewing_visits,
				'cv_rate'        => $viewing_visits > 0 ? round( (int) $row['cv_visits'] / $viewing_visits * 100, 1 ) : 0.0,
			);
		}
		return array(
			'items'             => $items,
			'conversion_visits' => $conversion_visits,
			'conversion_rate'   => $visits > 0 ? round( $conversion_visits / $visits * 100, 1 ) : 0.0,
			'pages'             => $pages,
			'definition_note'   => __( 'CV数は、設定した成果イベントが1回以上あった訪問数です。', 'access-analytics-plus' ),
		);
	}

	/** @return string[] */
	private static function option_events( string $option, string $default_key ): array {
		$definitions = self::definitions();
		$defaults = array_keys( array_filter( $definitions, static fn ( array $definition ): bool => $definition[ $default_key ] ) );
		$value = get_option( $option, $defaults );
		return is_array( $value ) ? array_values( array_intersect( array_keys( $definitions ), array_map( 'sanitize_key', $value ) ) ) : $defaults;
	}

	private static function passes_rate_limit( int $session_id ): bool {
		$session_key = 'aap_event_session_' . substr( hash_hmac( 'sha256', (string) $session_id, wp_salt( 'nonce' ) ), 0, 24 );
		$ip_key = 'aap_event_ip_' . substr( hash_hmac( 'sha256', Settings::current_ip(), wp_salt( 'nonce' ) ), 0, 24 );
		$session_count = (int) get_transient( $session_key );
		$ip_count = (int) get_transient( $ip_key );
		// Keep the shared-IP ceiling deliberately generous for office NAT and mobile carriers.
		if ( $session_count >= 60 || $ip_count >= 600 ) { return false; }
		set_transient( $session_key, $session_count + 1, 5 * MINUTE_IN_SECONDS );
		set_transient( $ip_key, $ip_count + 1, 5 * MINUTE_IN_SECONDS );
		return true;
	}

	private static function utc( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
