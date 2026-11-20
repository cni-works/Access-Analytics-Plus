<?php

declare(strict_types=1);

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	}
	class WP_REST_Request {
		/** @param array<string,mixed> $params */
		public function __construct( private array $params = array() ) {}
		public function get_header( string $name ): string { return 'content-length' === $name ? '256' : ''; }
		public function get_param( string $name ): mixed { return $this->params[ $name ] ?? null; }
	}
	class WP_REST_Response {
		/** @param array<string,mixed> $data */
		public function __construct( public array $data = array(), public int $status = 200 ) {}
	}
	final class AAP_Event_WPDB {
		/** @var array<int,array<string,mixed>> */
		public array $rows = array();
		public int $insert_id = 0;
		public string $last_error = '';
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sd]/', $replacement, $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function get_var( string $query ): string|int|null {
			if ( preg_match( "/event_key='([^']+)'/", $query, $matches ) ) {
				foreach ( $this->rows as $id => $row ) { if ( $row['event_key'] === $matches[1] ) { return $id; } }
			}
			return null;
		}
		/** @param array<string,mixed> $data @param string[] $formats */
		public function insert( string $table, array $data, array $formats = array() ): int|false {
			unset( $table, $formats );
			foreach ( $this->rows as $row ) { if ( $row['event_key'] === $data['event_key'] ) { return false; } }
			$this->insert_id = count( $this->rows ) + 1;
			$this->rows[ $this->insert_id ] = $data;
			return 1;
		}
	}

	$aap_event_options = array();
	$aap_event_transients = array();
	function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
	function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function get_option( string $key, mixed $default = false ): mixed { global $aap_event_options; return array_key_exists( $key, $aap_event_options ) ? $aap_event_options[ $key ] : $default; }
	function update_option( string $key, mixed $value, bool $autoload = false ): bool { global $aap_event_options; unset( $autoload ); $aap_event_options[ $key ] = $value; return true; }
	function wp_salt( string $scheme = '' ): string { return 'event-test-secret-' . $scheme; }
	function get_transient( string $key ): mixed { global $aap_event_transients; return $aap_event_transients[ $key ] ?? false; }
	function set_transient( string $key, mixed $value, int $expiration ): bool { global $aap_event_transients; unset( $expiration ); $aap_event_transients[ $key ] = $value; return true; }
	function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return '2026-09-20 12:00:00'; }
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
}

namespace AccessAnalyticsPlus {
	final class Tracker {
		public static function request_is_same_origin( \WP_REST_Request $request ): bool { unset( $request ); return true; }
		public static function current_user_is_excluded(): bool { return false; }
	}
	final class Settings {
		public static function tracking_enabled(): bool { return true; }
		public static function is_current_ip_excluded(): bool { return false; }
		public static function current_ip(): string { return '203.0.113.10'; }
	}
	final class Shadow_Diagnostics {
		/** @return array<string,mixed> */
		public static function accept_event( string $token ): array {
			return str_starts_with( $token, 's.' )
				? array( 'confirmed' => true, 'class' => 'confirmed', 'pageview_id' => 10, 'session_id' => 20, 'page_id' => 30 )
				: array( 'confirmed' => false, 'class' => 'invalid' );
		}
	}
	final class Database {
		/** @return array<string,string> */
		public static function tables(): array { return array( 'events' => 'wp_aap_events', 'pageviews' => 'wp_aap_pageviews', 'pages' => 'wp_aap_pages' ); }
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/includes/class-event-tracker.php';
	use AccessAnalyticsPlus\Event_Tracker;

	function event_expect( bool $condition, string $message ): void {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	}

	$definitions = Event_Tracker::definitions();
	event_expect( array( 'line_click', 'tel_click', 'contact_click', 'form_submit', 'map_click' ) === array_keys( $definitions ), 'only the phase-one event allowlist is exposed' );
	event_expect( ! empty( $definitions['form_submit']['default_conversion'] ), 'successful form submission is a default conversion' );
	event_expect( empty( $definitions['map_click']['default_conversion'] ), 'map click remains informational by default' );
	event_expect( 5 === count( Event_Tracker::enabled_events() ) && 4 === count( Event_Tracker::conversion_events() ), 'safe defaults enable five events and four conversions' );

	Event_Tracker::save_settings( array( 'tel_click', 'map_click', 'unknown' ), array( 'map_click', 'unknown', 'line_click' ) );
	event_expect( array( 'tel_click', 'map_click' ) === Event_Tracker::enabled_events(), 'unknown event settings are rejected' );
	event_expect( array( 'map_click' ) === Event_Tracker::conversion_events(), 'conversion settings are restricted to enabled events' );

	$GLOBALS['wpdb'] = new AAP_Event_WPDB();
	$request = new WP_REST_Request(
		array(
			'token' => 's.1.2.3.signature',
			'event_id' => '12345678-1234-4234-8234-123456789abc',
			'event_name' => 'tel_click',
			'event_location' => 'header',
			'object_type' => 'link',
			'object_key' => '',
		)
	);
	$first = Event_Tracker::record( $request );
	$second = Event_Tracker::record( $request );
	event_expect( $first instanceof WP_REST_Response && 201 === $first->status && true === $first->data['accepted'], 'confirmed event is accepted' );
	event_expect( $second instanceof WP_REST_Response && 200 === $second->status && true === $second->data['duplicate'], 'retry is idempotently accepted' );
	event_expect( 1 === count( $GLOBALS['wpdb']->rows ), 'duplicate retry creates one event row' );
	$stored = reset( $GLOBALS['wpdb']->rows );
	event_expect( 64 === strlen( (string) $stored['event_key'] ) && ! str_contains( (string) $stored['event_key'], '12345678-' ), 'raw event UUID is replaced by an HMAC' );
	event_expect( 20 === $stored['session_id'] && 10 === $stored['pageview_id'] && 30 === $stored['page_id'], 'event retains only confirmed relational IDs' );

	$GLOBALS['aap_event_transients'] = array();
	$rate_limit = new ReflectionMethod( Event_Tracker::class, 'passes_rate_limit' );
	for ( $attempt = 0; $attempt < 60; ++$attempt ) {
		event_expect( true === $rate_limit->invoke( null, 101 ), 'session rate limit allows the documented sixty-event window' );
	}
	event_expect( false === $rate_limit->invoke( null, 101 ), 'session rate limit blocks the sixty-first event' );
	event_expect( true === $rate_limit->invoke( null, 202 ), 'another confirmed session on the same shared IP remains eligible' );
	event_expect( 2 === count( array_filter( array_keys( $GLOBALS['aap_event_transients'] ), static fn ( string $key ): bool => str_starts_with( $key, 'aap_event_session_' ) ) ), 'rate limiting creates separate session buckets' );
	event_expect( 1 === count( array_filter( array_keys( $GLOBALS['aap_event_transients'] ), static fn ( string $key ): bool => str_starts_with( $key, 'aap_event_ip_' ) ) ), 'rate limiting retains one broad shared-IP safety bucket' );

	$database = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-database.php' );
	$tracker = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/tracker.js' );
	$rest = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-rest-controller.php' );
	$privacy = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tracker.php' );
	event_expect( str_contains( $database, 'UNIQUE KEY event_key' ) && str_contains( $database, 'page_event_occurred' ), 'event UUID idempotency and report indexes are part of the schema' );
	$event_schema = preg_match( '/CREATE TABLE \{\$tables\[\'events\'\]\} \((.*?)\) \{\$charset_collate\}/s', $database, $schema_match ) ? strtolower( $schema_match[1] ) : '';
	event_expect( '' !== $event_schema && ! str_contains( $event_schema, 'user_agent' ) && ! str_contains( $event_schema, 'referrer' ) && ! str_contains( $event_schema, 'ip_address' ), 'event table stores no raw IP, user agent, or referrer' );
	event_expect( str_contains( $tracker, 'wpcf7mailsent' ) && str_contains( $tracker, '^tel:' ) && str_contains( $tracker, 'data-aap-event' ), 'tracker covers CF7 success, phone links, and explicit CTA attributes' );
	event_expect( str_contains( $rest, "'/event'" ) && str_contains( $rest, "'event_id'" ), 'public event endpoint validates an idempotency ID' );
	event_expect( str_contains( $privacy, '入力内容、電話番号、リンク先URL、クリック位置は保存しません' ), 'privacy notice explicitly excludes sensitive CTA and form details' );
	event_expect( ! str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-event-tracker.php' ), 'verify_pageview_token' ), 'event tracking accepts only the staged confirmation token format' );

	echo "Event tracking regression checks passed.\n";
}
