<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Stages new page views until a human-presence signal is received. */
final class Shadow_Diagnostics {
	public const RETENTION_DAYS = 7;
	public const PENDING_GRACE_SECONDS = 300;
	public const VISIBLE_SECONDS = 3;
	public const INTERACTION_SCROLL = 1;
	public const INTERACTION_POINTER = 2;
	public const INTERACTION_TOUCH = 4;
	public const INTERACTION_KEY = 8;
	private const TOKEN_TTL = 2700;
	private const FLAG_WEBDRIVER = 1;
	private const FLAG_ORIGIN_MISSING = 2;
	private const FLAG_DEVICE_MISMATCH = 4;
	private const FLAG_MANY_VISITORS_IP = 8;
	private const FLAG_VISIBLE_MISSING = 16;
	private const FLAG_INTERACTION_MISSING = 32;
	private const FLAG_ENGAGEMENT_MISSING = 64;
	private const FLAG_MANY_VISITORS_UA = 128;
	private const FLAG_REPEATED_UA_PATH = 256;
	private const FLAG_REGULAR_INTERVAL = 512;
	private const SUSPICIOUS_SCORE = 6;
	private const COLLECT_FAILURE_OPTION = 'aap_last_collect_failure';

	public static function enabled(): bool {
		return true;
	}

	/**
	 * @param array{type:string,host:string,search_source:string} $referrer
	 * @return array{token:string,class:string,country_code:string}|false
	 */
	public static function stage(
		string $collect_id,
		string $visitor_id,
		string $session_id,
		string $path,
		string $title,
		array $referrer,
		string $user_agent,
		string $reported_device,
		int $webdriver_state,
		bool $origin_present,
		string $tracker_build = 'unknown'
	) {
		try {
			global $wpdb;
			$tables      = Database::tables();
			$table       = $tables['shadow_events'];
			$now         = current_time( 'mysql', true );
			$collect_key = self::valid_uuid( $collect_id ) ? self::identifier_hmac( strtolower( $collect_id ), 'collect' ) : null;
			if ( null !== $collect_key ) {
				$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,shadow_class,country_code FROM {$table} WHERE collect_key=%s LIMIT 1", $collect_key ), ARRAY_A );
				if ( $existing ) {
					return array( 'token' => self::create_token( (int) $existing['id'] ), 'class' => (string) $existing['shadow_class'], 'country_code' => (string) $existing['country_code'] );
				}
			}
			$ip          = Settings::current_ip();
			$ip_key      = '' !== $ip ? self::identifier_hmac( $ip, 'ip' ) : '';
			// Prefer an existing DB5 key during its remaining lifetime, then use the
			// original HMAC scheme so upgrades preserve visitor/session continuity.
			$visitor_key = self::compatible_existing_key(
				$tables['sessions'],
				'visitor_key',
				hash_hmac( 'sha256', strtolower( $visitor_id ), wp_salt( 'auth' ) ),
				self::identifier_hmac( strtolower( $visitor_id ), 'visitor' )
			);
			$session_key = self::compatible_existing_key(
				$tables['sessions'],
				'session_key',
				hash_hmac( 'sha256', strtolower( $session_id ), wp_salt( 'secure_auth' ) ),
				self::identifier_hmac( strtolower( $session_id ), 'session' )
			);
			$ua_hash     = self::identifier_hmac( $user_agent, 'user-agent' );
			$ua_device   = self::device_from_user_agent( $user_agent );
			$reported    = in_array( $reported_device, array( 'mobile', 'desktop', 'tablet', 'other' ), true ) ? $reported_device : 'other';
			$country     = Country_Resolver::resolve();
			$region      = Region_Resolver::resolve( $country['code'], $ip );
			$visitor_is_new = 0 === (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE visitor_key=%s AND recorded_at>=%s",
					$visitor_key,
					gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS )
				)
			);
			$class = Country_Resolver::is_allowed( $country['code'] ) ? 'pending' : 'geo_excluded';
			$tracker_build = '' !== $tracker_build ? substr( sanitize_text_field( $tracker_build ), 0, 64 ) : 'unknown';
			$insert_data = array(
					'collect_key'          => $collect_key,
					'pageview_id'          => null,
					'session_id'           => null,
					'recorded_at'          => $now,
					'updated_at'           => $now,
					'ip_key'               => $ip_key,
					'visitor_key'          => $visitor_key,
					'session_key'          => $session_key,
					'ua_hash'              => $ua_hash,
					'path_hash'            => hash( 'sha256', $path ),
					'path'                 => $path,
					'title'                => $title,
					'referrer_type'        => $referrer['type'],
					'referrer_host'        => $referrer['host'],
					'search_source'        => $referrer['search_source'],
					'ua_family'            => self::user_agent_family( $user_agent ),
					'ua_device_type'       => $ua_device,
					'reported_device_type' => $reported,
					'country_code'         => $country['code'],
					'country_source'       => $country['source'],
					'region_code'          => $region['code'],
					'region_source'        => $region['source'],
					'tracker_build'        => $tracker_build,
					'build_mismatch'       => AAP_BUILD === $tracker_build ? 0 : 1,
					'device_mismatch'      => 'other' !== $reported && 'other' !== $ua_device && $reported !== $ua_device ? 1 : 0,
					'webdriver_state'      => in_array( $webdriver_state, array( -1, 0, 1 ), true ) ? $webdriver_state : -1,
					'origin_present'       => $origin_present ? 1 : 0,
					'visitor_is_new'       => $visitor_is_new ? 1 : 0,
					'shadow_class'         => $class,
					'finalized'            => 'geo_excluded' === $class ? 1 : 0,
					'aggregation_mode'     => 'staged',
					'pipeline_stage'       => 'staged',
					'last_completed_stage' => 'shadow_staged',
				);
			$inserted = $wpdb->insert( $table, $insert_data );
			$repair_attempted = false;
			if ( false === $inserted ) {
				$first_error = self::sanitize_database_error( (string) $wpdb->last_error );
				if ( null !== $collect_key ) {
					$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,shadow_class,country_code FROM {$table} WHERE collect_key=%s LIMIT 1", $collect_key ), ARRAY_A );
					if ( $existing ) {
						return array( 'token' => self::create_token( (int) $existing['id'] ), 'class' => (string) $existing['shadow_class'], 'country_code' => (string) $existing['country_code'] );
					}
				}
				$repair_attempted = Database::repair_after_collect_failure();
				if ( $repair_attempted ) {
					$inserted = $wpdb->insert( $table, $insert_data );
				}
				if ( false === $inserted ) {
					$message = self::sanitize_database_error( (string) $wpdb->last_error );
					self::record_collect_failure( 'shadow_insert_failed', '' !== $message ? $message : $first_error, $tracker_build, $repair_attempted );
					return false;
				}
			}
			delete_option( self::COLLECT_FAILURE_OPTION );
			$event_id = (int) $wpdb->insert_id;
			self::refresh_group_signals( $event_id, $ip_key, $ua_hash, hash( 'sha256', $path ) );
			self::evaluate( $event_id );
			if ( 'geo_excluded' === $class ) {
				self::flush_exclusions();
			}
			return array( 'token' => self::create_token( $event_id ), 'class' => $class, 'country_code' => $country['code'] );
		} catch ( \Throwable $error ) {
			self::record_collect_failure( 'shadow_exception', $error->getMessage(), $tracker_build, false );
			return false;
		}
	}

	/** @return array<string,mixed> */
	public static function last_collect_failure(): array {
		$value = get_option( self::COLLECT_FAILURE_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function record_collect_failure( string $type, string $message, string $tracker_build, bool $repair_attempted ): void {
		$payload = array(
			'time'             => current_time( 'mysql', true ),
			'stage'            => 'collect',
			'type'             => substr( sanitize_key( $type ), 0, 64 ),
			'message'          => self::sanitize_database_error( $message ),
			'php_build'        => AAP_BUILD,
			'tracker_build'    => substr( sanitize_text_field( $tracker_build ), 0, 64 ),
			'db_version'       => (string) get_option( Database::OPTION_DB_VERSION, '' ),
			'repair_attempted' => $repair_attempted ? 1 : 0,
		);
		update_option( self::COLLECT_FAILURE_OPTION, $payload, false );
		self::write_debug_log( 0, 'collect', $payload['type'], $payload['message'], $tracker_build );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function record_signal( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$data  = self::verify_token( $token );
		if ( false === $data ) {
			$legacy = Tracker::verify_pageview_token( $token );
			if ( false === $legacy ) {
				return new WP_Error( 'aap_invalid_shadow_token', __( '診断情報の有効期限が切れています。', 'access-analytics-plus' ), array( 'status' => 403 ) );
			}
			self::update_legacy_signal( $legacy['pageview_id'], (bool) $request->get_param( 'visible_confirmed' ), absint( $request->get_param( 'interaction_mask' ) ) );
			return new WP_REST_Response( array( 'accepted' => true, 'legacy' => true ), 200 );
		}

		$result = self::update_signal( $data['event_id'], (bool) $request->get_param( 'visible_confirmed' ), absint( $request->get_param( 'interaction_mask' ) ), false );
		if ( ! $result['success'] ) {
			return new WP_Error( 'aap_shadow_signal_failed', __( '確認信号を記録できませんでした。', 'access-analytics-plus' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'accepted' => true, 'class' => $result['class'], 'promoted' => $result['promoted'] ), 200 );
	}

	/** @return array{pageview_id:int,issued_at:int}|false */
	public static function accept_engagement( string $token ) {
		$data = self::verify_token( $token );
		if ( false === $data ) {
			return false;
		}
		$result = self::update_signal( $data['event_id'], false, 0, true );
		if ( ! $result['success'] ) {
			return false;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT pageview_id FROM ' . Database::tables()['shadow_events'] . ' WHERE id=%d', $data['event_id'] ), ARRAY_A );
		return $row && (int) $row['pageview_id'] > 0 ? array( 'pageview_id' => (int) $row['pageview_id'], 'issued_at' => $data['issued_at'] ) : false;
	}

	public static function mark_engagement_received( int $pageview_id ): void {
		if ( $pageview_id < 1 ) {
			return;
		}
		global $wpdb;
		$wpdb->update( Database::tables()['shadow_events'], array( 'engagement_received' => 1 ), array( 'pageview_id' => $pageview_id ) );
	}

	public static function finalize_pending( int $limit = 1000 ): int {
		global $wpdb;
		$table = Database::tables()['shadow_events'];
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE aggregation_mode='staged' AND finalized=0 AND recorded_at<=%s ORDER BY id ASC LIMIT %d", gmdate( 'Y-m-d H:i:s', time() - self::PENDING_GRACE_SECONDS ), max( 1, min( 5000, $limit ) ) ) );
		foreach ( $ids as $id ) {
			self::evaluate( (int) $id );
		}
		self::flush_exclusions();
		return count( $ids );
	}

	/** @return array<string,mixed> */
	public static function summary(): array {
		$empty = array(
			'total' => 0, 'human_like' => 0, 'unconfirmed' => 0, 'suspected' => 0, 'geo_excluded' => 0,
			'build_mismatch' => 0, 'promotion_failures' => 0,
			'signals' => array( 'visible' => 0, 'interaction' => 0, 'engagement' => 0, 'webdriver' => 0, 'origin_missing' => 0, 'device_mismatch' => 0 ),
			'reasons' => array(), 'ips' => array(), 'builds' => array(), 'countries' => array(), 'errors' => array(),
		);
		try {
			self::finalize_pending( 5000 );
			global $wpdb;
			$table = Database::tables()['shadow_events'];
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
			$summary = $empty;
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT shadow_class,COUNT(*) total FROM {$table} WHERE recorded_at>=%s GROUP BY shadow_class", $cutoff ), ARRAY_A ) as $row ) {
				$count = (int) $row['total'];
				$summary['total'] += $count;
				if ( in_array( $row['shadow_class'], array( 'confirmed', 'human_like' ), true ) ) { $summary['human_like'] += $count; }
				elseif ( in_array( $row['shadow_class'], array( 'bot', 'suspected' ), true ) ) { $summary['suspected'] += $count; }
				elseif ( 'geo_excluded' === $row['shadow_class'] ) { $summary['geo_excluded'] += $count; }
				else { $summary['unconfirmed'] += $count; }
			}
			$signals = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(visible_confirmed=1) visible,SUM(interaction_mask>0) interaction_count,SUM(engagement_received=1) engagement,SUM(webdriver_state=1) webdriver,SUM(origin_present=0) origin_missing,SUM(device_mismatch=1) device_mismatch,SUM(build_mismatch=1) build_mismatch,SUM(last_error_type<>'') promotion_failures FROM {$table} WHERE recorded_at>=%s", $cutoff ), ARRAY_A );
			foreach ( array( 'visible' => 'visible', 'interaction' => 'interaction_count', 'engagement' => 'engagement', 'webdriver' => 'webdriver', 'origin_missing' => 'origin_missing', 'device_mismatch' => 'device_mismatch' ) as $key => $column ) {
				$summary['signals'][ $key ] = (int) ( $signals[ $column ] ?? 0 );
			}
			$summary['build_mismatch'] = (int) ( $signals['build_mismatch'] ?? 0 );
			$summary['promotion_failures'] = (int) ( $signals['promotion_failures'] ?? 0 );
			$summary['builds'] = (array) $wpdb->get_results( $wpdb->prepare( "SELECT tracker_build,build_mismatch,COUNT(*) total FROM {$table} WHERE recorded_at>=%s GROUP BY tracker_build,build_mismatch ORDER BY total DESC LIMIT 5", $cutoff ), ARRAY_A );
			$summary['countries'] = (array) $wpdb->get_results( $wpdb->prepare( "SELECT country_code,country_source,SUM(shadow_class='geo_excluded') excluded,COUNT(*) total FROM {$table} WHERE recorded_at>=%s GROUP BY country_code,country_source ORDER BY total DESC LIMIT 10", $cutoff ), ARRAY_A );
			$labels = self::reason_labels();
			$selects = array();
			foreach ( array_keys( $labels ) as $flag ) { $selects[] = "SUM(CASE WHEN (risk_flags & {$flag})<>0 THEN 1 ELSE 0 END) flag_{$flag}"; }
			$reasons = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . implode( ',', $selects ) . " FROM {$table} WHERE recorded_at>=%s AND shadow_class IN ('bot','suspected')", $cutoff ), ARRAY_A );
			foreach ( $labels as $flag => $label ) {
				$value = (int) ( $reasons[ 'flag_' . $flag ] ?? 0 );
				if ( $value ) { $summary['reasons'][] = array( 'label' => $label, 'value' => $value ); }
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ip_key,COUNT(*) total,COUNT(DISTINCT visitor_key) visitors,SUM(visible_confirmed=1) visible,SUM(engagement_received=1) engagement,SUM(webdriver_state=1) webdriver,SUM(shadow_class IN ('bot','suspected')) suspected FROM {$table} WHERE recorded_at>=%s AND ip_key<>'' GROUP BY ip_key HAVING COUNT(*)>1 ORDER BY total DESC LIMIT 5", $cutoff ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$summary['ips'][] = array( 'key' => strtoupper( substr( (string) $row['ip_key'], 0, 4 ) ) . '…', 'total' => (int) $row['total'], 'visitors' => (int) $row['visitors'], 'visible' => (int) $row['visible'], 'engagement' => (int) $row['engagement'], 'webdriver' => (int) $row['webdriver'], 'suspected' => (int) $row['suspected'] );
			}
			$summary['errors'] = (array) $wpdb->get_results( $wpdb->prepare( "SELECT last_error_at,last_completed_stage,last_error_stage,last_error_type,last_error_message FROM {$table} WHERE recorded_at>=%s AND last_error_type<>'' ORDER BY last_error_at DESC LIMIT 5", $cutoff ), ARRAY_A );
			return $summary;
		} catch ( \Throwable $error ) {
			return $empty;
		}
	}

	/** @return array{success:bool,class:string,promoted:bool} */
	private static function update_signal( int $event_id, bool $visible, int $interactions, bool $engagement ): array {
		global $wpdb;
		$table = Database::tables()['shadow_events'];
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET visible_confirmed=GREATEST(visible_confirmed,%d),interaction_mask=interaction_mask | %d,engagement_received=GREATEST(engagement_received,%d),signal_received_at=COALESCE(signal_received_at,%s),pipeline_stage=IF(finalized=0,'signal_received',pipeline_stage),last_completed_stage=IF(finalized=0,'signal_received',last_completed_stage),updated_at=%s WHERE id=%d AND aggregation_mode='staged'", $visible ? 1 : 0, min( 15, $interactions ), $engagement ? 1 : 0, $now, $now, $event_id ) );
		if ( false === $updated ) {
			self::record_failure( $event_id, 'signal', 'signal_update_failed', (string) $wpdb->last_error, false );
			return array( 'success' => false, 'class' => 'pending', 'promoted' => false );
		}
		return self::evaluate( $event_id );
	}

	/** @return array{success:bool,class:string,promoted:bool} */
	private static function evaluate( int $event_id ): array {
		global $wpdb;
		$table = Database::tables()['shadow_events'];
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			self::record_failure( $event_id, 'transaction_start', 'transaction_start_failed', (string) $wpdb->last_error, false );
			return array( 'success' => false, 'class' => 'pending', 'promoted' => false );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d FOR UPDATE", $event_id ), ARRAY_A );
		if ( ! $row || 'staged' !== $row['aggregation_mode'] ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'success' => false, 'class' => 'missing', 'promoted' => false );
		}
		if ( 1 === (int) $row['finalized'] ) {
			$wpdb->query( 'COMMIT' );
			return array( 'success' => true, 'class' => (string) $row['shadow_class'], 'promoted' => 'confirmed' === $row['shadow_class'] );
		}
		$age = max( 0, time() - strtotime( (string) $row['recorded_at'] . ' UTC' ) );
		$diagnosis = self::diagnose( $row, $age );
		$pageview_id = 0;
		$session_id = 0;
		$promoted_at = null;
		if ( 'confirmed' === $diagnosis['class'] ) {
			$started = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET pipeline_stage='promotion_started',last_completed_stage='promotion_started',promotion_attempts=promotion_attempts+1 WHERE id=%d", $event_id ) );
			if ( false === $started ) {
				$error = (string) $wpdb->last_error;
				$wpdb->query( 'ROLLBACK' );
				self::record_failure( $event_id, 'promotion', 'promotion_start_failed', $error, true );
				return array( 'success' => false, 'class' => 'pending', 'promoted' => false );
			}
			$promoted = Tracker::promote_shadow_row( $row );
			if ( ! $promoted['success'] ) {
				$wpdb->query( 'ROLLBACK' );
				self::record_failure( $event_id, $promoted['stage'], $promoted['error_type'], $promoted['error_message'], true );
				return array( 'success' => false, 'class' => 'pending', 'promoted' => false );
			}
			$pageview_id = $promoted['pageview_id'];
			$session_id = $promoted['session_id'];
			$promoted_at = current_time( 'mysql', true );
		}
		$finalized = in_array( $diagnosis['class'], array( 'confirmed', 'bot', 'unconfirmed', 'geo_excluded' ), true ) ? 1 : 0;
		$updated = $wpdb->update(
			$table,
			array(
				'pageview_id' => $pageview_id ?: null, 'session_id' => $session_id ?: null,
				'risk_score' => $diagnosis['score'], 'risk_flags' => $diagnosis['flags'],
				'shadow_class' => $diagnosis['class'], 'finalized' => $finalized, 'promoted_at' => $promoted_at,
				'pipeline_stage' => 'confirmed' === $diagnosis['class'] ? 'promotion_completed' : $diagnosis['class'],
				'last_completed_stage' => 'confirmed' === $diagnosis['class'] ? 'promotion_completed' : $diagnosis['class'],
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $event_id )
		);
		if ( false === $updated ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			self::record_failure( $event_id, 'finalize', 'shadow_finalize_failed', $error, 'confirmed' === $diagnosis['class'] );
			return array( 'success' => false, 'class' => 'pending', 'promoted' => false );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			self::record_failure( $event_id, 'transaction_commit', 'transaction_commit_failed', (string) $wpdb->last_error, false );
			return array( 'success' => false, 'class' => 'pending', 'promoted' => false );
		}
		return array( 'success' => true, 'class' => $diagnosis['class'], 'promoted' => 'confirmed' === $diagnosis['class'] );
	}

	/** @param array<string,mixed> $row @return array{class:string,score:int,flags:int} */
	private static function diagnose( array $row, int $age ): array {
		if ( 'geo_excluded' === $row['shadow_class'] ) { return array( 'class' => 'geo_excluded', 'score' => 0, 'flags' => 0 ); }
		$flags = 0;
		$score = 0;
		if ( 1 === (int) $row['webdriver_state'] ) { $flags |= self::FLAG_WEBDRIVER; $score += 4; }
		if ( 0 === (int) $row['origin_present'] ) { $flags |= self::FLAG_ORIGIN_MISSING; $score += 1; }
		if ( 1 === (int) $row['device_mismatch'] ) { $flags |= self::FLAG_DEVICE_MISMATCH; $score += 2; }
		if ( (int) $row['ip_visitors_10m'] >= 5 ) { $flags |= self::FLAG_MANY_VISITORS_IP; $score += 4; }
		if ( (int) $row['ua_visitors_10m'] >= 10 ) { $flags |= self::FLAG_MANY_VISITORS_UA; $score += 2; }
		if ( (int) $row['ua_path_requests_10m'] >= 10 ) { $flags |= self::FLAG_REPEATED_UA_PATH; $score += 2; }
		if ( 1 === (int) $row['regular_interval'] ) { $flags |= self::FLAG_REGULAR_INTERVAL; $score += 1; }
		$human = 1 === (int) $row['visible_confirmed'] || (int) $row['interaction_mask'] > 0 || 1 === (int) $row['engagement_received'];
		if ( $human ) {
			// Aggregate IP/UA patterns remain diagnostic once direct browser activity is observed.
			$hard_automation = 1 === (int) $row['webdriver_state'] && 1 === (int) $row['device_mismatch'];
			return array( 'class' => $hard_automation ? 'bot' : 'confirmed', 'score' => $score, 'flags' => $flags );
		}
		if ( $age < self::PENDING_GRACE_SECONDS ) { return array( 'class' => 'pending', 'score' => $score, 'flags' => $flags ); }
		$flags |= self::FLAG_VISIBLE_MISSING | self::FLAG_INTERACTION_MISSING | self::FLAG_ENGAGEMENT_MISSING;
		$score += 3;
		return array( 'class' => $score >= self::SUSPICIOUS_SCORE ? 'bot' : 'unconfirmed', 'score' => $score, 'flags' => $flags );
	}

	public static function sanitize_database_error( string $message ): string {
		$message = preg_replace( '/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', '[identifier]', $message ) ?? '';
		$message = preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $message ) ?? '';
		$message = preg_replace( '/\b[0-9a-f]{64}\b/i', '[hash]', $message ) ?? '';
		return substr( sanitize_text_field( $message ), 0, 500 );
	}

	private static function record_failure( int $event_id, string $stage, string $type, string $message, bool $promotion_attempt ): void {
		global $wpdb;
		$table = Database::tables()['shadow_events'];
		$message = self::sanitize_database_error( $message );
		$completed_stage = self::completed_stage_before_failure( $stage );
		$sql = "UPDATE {$table} SET pipeline_stage='promotion_failed',last_completed_stage=%s,last_error_stage=%s,last_error_type=%s,last_error_message=%s,last_error_at=%s,updated_at=%s";
		if ( $promotion_attempt ) { $sql .= ',promotion_attempts=promotion_attempts+1'; }
		$sql .= ' WHERE id=%d';
		$wpdb->query( $wpdb->prepare( $sql, $completed_stage, substr( sanitize_key( $stage ), 0, 32 ), substr( sanitize_key( $type ), 0, 64 ), $message, current_time( 'mysql', true ), current_time( 'mysql', true ), $event_id ) );
		self::write_debug_log( $event_id, $stage, $type, $message, AAP_BUILD );
	}

	private static function completed_stage_before_failure( string $stage ): string {
		return match ( $stage ) {
			'session' => 'page_ready',
			'pageview' => 'session_updated',
			'daily' => 'pageview_created',
			'finalize', 'transaction_commit' => 'daily_updated',
			default => 'signal_received',
		};
	}

	private static function write_debug_log( int $event_id, string $stage, string $type, string $message, string $tracker_build ): void {
		$enabled = ( defined( 'AAP_DIAGNOSTIC_LOG' ) && AAP_DIAGNOSTIC_LOG ) || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
		if ( ! $enabled ) { return; }
		$payload = array(
			'event_id' => $event_id, 'stage' => substr( sanitize_key( $stage ), 0, 32 ), 'type' => substr( sanitize_key( $type ), 0, 64 ),
			'message' => self::sanitize_database_error( $message ), 'php_build' => AAP_BUILD, 'tracker_build' => substr( sanitize_text_field( $tracker_build ), 0, 64 ),
		);
		error_log( '[Access Analytics Plus] ' . wp_json_encode( $payload ) );
	}

	private static function refresh_group_signals( int $id, string $ip, string $ua, string $path ): void {
		global $wpdb;
		$table = Database::tables()['shadow_events'];
		$start = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
		$ip_count = '' === $ip ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_key) FROM {$table} WHERE ip_key=%s AND recorded_at>=%s", $ip, $start ) );
		$ua_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_key) FROM {$table} WHERE ua_hash=%s AND recorded_at>=%s", $ua, $start ) );
		$path_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ua_hash=%s AND path_hash=%s AND recorded_at>=%s", $ua, $path, $start ) );
		$times = $wpdb->get_col( $wpdb->prepare( "SELECT recorded_at FROM {$table} WHERE ua_hash=%s AND path_hash=%s AND id<>%d ORDER BY recorded_at DESC LIMIT 2", $ua, $path, $id ) );
		$regular = 0;
		if ( count( $times ) === 2 ) {
			$now = time();
			$gap1 = $now - strtotime( $times[0] . ' UTC' );
			$gap2 = strtotime( $times[0] . ' UTC' ) - strtotime( $times[1] . ' UTC' );
			$regular = $gap1 >= 30 && $gap2 >= 30 && abs( $gap1 - $gap2 ) <= 15 ? 1 : 0;
		}
		$wpdb->update( $table, array( 'ip_visitors_10m' => $ip_count, 'ua_visitors_10m' => $ua_count, 'ua_path_requests_10m' => $path_count, 'regular_interval' => $regular ), array( 'id' => $id ) );
	}

	private static function flush_exclusions(): void {
		global $wpdb;
		$table = Database::tables()['shadow_events'];
		$wpdb->query( 'START TRANSACTION' );
		$rows = $wpdb->get_results( "SELECT id,recorded_at,shadow_class FROM {$table} WHERE aggregation_mode='staged' AND finalized=1 AND exclusion_recorded=0 AND shadow_class IN ('unconfirmed','bot','geo_excluded') ORDER BY id ASC LIMIT 1000 FOR UPDATE", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$date = ( new \DateTimeImmutable( $row['recorded_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
			Tracker::record_exclusion_at( 'bot' === $row['shadow_class'] ? 'automation' : $row['shadow_class'], $date );
			$wpdb->update( $table, array( 'exclusion_recorded' => 1 ), array( 'id' => (int) $row['id'] ) );
		}
		$wpdb->query( 'COMMIT' );
	}

	private static function create_token( int $id ): string {
		$issued = time();
		$expires = $issued + self::TOKEN_TTL;
		$signature = hash_hmac( 'sha256', 's|' . $id . '|' . $issued . '|' . $expires, wp_salt( 'nonce' ) );
		return 's.' . $id . '.' . $issued . '.' . $expires . '.' . $signature;
	}

	/** @return array{event_id:int,issued_at:int}|false */
	private static function verify_token( string $token ) {
		if ( 1 !== preg_match( '/^s\.(\d+)\.(\d+)\.(\d+)\.([a-f0-9]{64})$/', $token, $matches ) ) { return false; }
		$id = absint( $matches[1] );
		$issued = absint( $matches[2] );
		$expires = absint( $matches[3] );
		if ( $id < 1 || $issued > time() + 60 || $expires < time() || $expires - $issued !== self::TOKEN_TTL ) { return false; }
		$expected = hash_hmac( 'sha256', 's|' . $id . '|' . $issued . '|' . $expires, wp_salt( 'nonce' ) );
		return hash_equals( $expected, $matches[4] ) ? array( 'event_id' => $id, 'issued_at' => $issued ) : false;
	}

	private static function update_legacy_signal( int $pageview, bool $visible, int $mask ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::tables()['shadow_events'] . " SET visible_confirmed=GREATEST(visible_confirmed,%d),interaction_mask=interaction_mask | %d WHERE pageview_id=%d AND aggregation_mode='legacy'", $visible ? 1 : 0, min( 15, $mask ), $pageview ) );
	}

	private static function identifier_hmac( string $value, string $context ): string { return hash_hmac( 'sha256', $context . '|' . $value, wp_salt( 'secure_auth' ) ); }

	private static function valid_uuid( string $value ): bool { return 1 === preg_match( '/^[a-f0-9-]{36}$/i', $value ); }

	private static function compatible_existing_key( string $sessions_table, string $column, string $preferred, string $db5_key ): string {
		global $wpdb;
		$column = 'session_key' === $column ? 'session_key' : 'visitor_key';
		$existing = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT {$column} FROM {$sessions_table} WHERE {$column} IN (%s,%s) ORDER BY last_seen_at DESC LIMIT 1",
				$preferred,
				$db5_key
			)
		);
		return '' !== $existing ? $existing : $preferred;
	}

	private static function device_from_user_agent( string $ua ): string {
		if ( preg_match( '/ipad|tablet|kindle|silk/i', $ua ) || ( false !== stripos( $ua, 'android' ) && false === stripos( $ua, 'mobile' ) ) ) { return 'tablet'; }
		if ( preg_match( '/mobile|iphone|ipod|android/i', $ua ) ) { return 'mobile'; }
		return '' !== $ua ? 'desktop' : 'other';
	}

	private static function user_agent_family( string $ua ): string {
		foreach ( array( 'samsung_internet' => 'SamsungBrowser', 'edge' => 'EdgA?|EdgiOS', 'chrome' => 'CriOS|Chrome', 'firefox' => 'FxiOS|Firefox', 'safari' => 'Safari' ) as $name => $pattern ) {
			if ( preg_match( '/' . $pattern . '/i', $ua ) ) { return $name; }
		}
		return '' !== $ua ? 'other' : 'unknown';
	}

	/** @return array<int,string> */
	private static function reason_labels(): array {
		return array(
			self::FLAG_WEBDRIVER => __( '自動操作ブラウザーの申告', 'access-analytics-plus' ),
			self::FLAG_ORIGIN_MISSING => __( 'Origin情報なし', 'access-analytics-plus' ),
			self::FLAG_DEVICE_MISMATCH => __( '端末情報の矛盾', 'access-analytics-plus' ),
			self::FLAG_MANY_VISITORS_IP => __( '同一匿名IPから多数の識別子', 'access-analytics-plus' ),
			self::FLAG_VISIBLE_MISSING => __( '3秒の表示確認なし', 'access-analytics-plus' ),
			self::FLAG_INTERACTION_MISSING => __( '操作確認なし', 'access-analytics-plus' ),
			self::FLAG_ENGAGEMENT_MISSING => __( '閲覧時間の送信なし', 'access-analytics-plus' ),
			self::FLAG_MANY_VISITORS_UA => __( '同じブラウザー系統から多数の新規識別子', 'access-analytics-plus' ),
			self::FLAG_REPEATED_UA_PATH => __( '同じブラウザー系統とページへの集中', 'access-analytics-plus' ),
			self::FLAG_REGULAR_INTERVAL => __( '機械的に一定なアクセス間隔', 'access-analytics-plus' ),
		);
	}
}
