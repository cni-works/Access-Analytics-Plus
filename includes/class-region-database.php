<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

/** Fail-safe runtime reader and future data-feed updater for the JP-only MMDB. */
final class Region_Database {
	public const BUNDLED_EDITION = '2026-09';
	public const STALE_DAYS = 60;
	private const BUNDLED_FILE = 'data/aap-japan-prefecture-2026-09.mmdb';
	private const OPTION_STATE = 'aap_region_database_state';
	private const OPTION_LAST_CHECK = 'aap_region_database_last_check';
	private const MAX_MANIFEST_BYTES = 65536;
	private const MAX_DATABASE_BYTES = 5242880;

	/** @var array<string,\MaxMind\Db\Reader> */
	private static array $readers = array();
	private static bool $autoload_registered = false;

	public static function register(): void {
		add_action( 'admin_post_aap_update_region_database', array( self::class, 'handle_manual_update' ) );
	}

	/** @return array{code:string,source:string,edition:string} */
	public static function lookup( string $ip ): array {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return array( 'code' => Region_Resolver::UNKNOWN, 'source' => 'unknown', 'edition' => '' );
		}
		foreach ( self::database_candidates() as $candidate ) {
			try {
				$record = self::reader( $candidate['path'] )->get( $ip );
				$code = is_array( $record ) ? Region_Resolver::normalize( (string) ( $record['region_code'] ?? '' ) ) : '';
				if ( Region_Resolver::UNKNOWN !== $code ) {
					return array( 'code' => $code, 'source' => $candidate['source'], 'edition' => $candidate['edition'] );
				}
			} catch ( \Throwable $error ) {
				// A damaged update must not affect collect or promotion; try the bundled copy.
			}
		}
		return array( 'code' => Region_Resolver::UNKNOWN, 'source' => 'unknown', 'edition' => '' );
	}

	/** @return array{source:string,edition:string,last_updated:string,status:string,status_level:string,error:string,feed_available:bool} */
	public static function status(): array {
		$probe = self::lookup( '1.0.16.1' );
		$state = self::state();
		$feed_available = '' !== self::manifest_url();
		if ( 'JP-13' !== $probe['code'] ) {
			return array(
				'source' => __( '利用可能なデータなし', 'access-analytics-plus' ), 'edition' => '—', 'last_updated' => '—',
				'status' => __( '都道府県データを読み込めません。アクセス計測は継続し、地域だけ判定不能として扱います。', 'access-analytics-plus' ),
				'status_level' => 'error', 'error' => (string) ( $state['last_error'] ?? '' ), 'feed_available' => $feed_available,
			);
		}
		$edition_time = strtotime( $probe['edition'] . '-01 00:00:00 UTC' );
		$is_stale = false !== $edition_time && $edition_time < time() - self::STALE_DAYS * DAY_IN_SECONDS;
		$is_updated = 'region_updated' === $probe['source'];
		$status = $feed_available
			? __( '利用可能です。更新失敗時は既存または同梱データへ自動で戻ります。', 'access-analytics-plus' )
			: __( '同梱データを利用中です。外部の地域DB更新フィードはまだ設定されていません。', 'access-analytics-plus' );
		$level = 'ok';
		if ( '' !== (string) ( $state['last_error'] ?? '' ) ) {
			$status = __( '直近の更新に失敗しました。利用可能な既存または同梱データで判定を継続しています。', 'access-analytics-plus' );
			$level = 'warning';
		} elseif ( $is_stale ) {
			$status = __( 'データ版が60日以上前です。地域判定は継続しますが、更新を推奨します。', 'access-analytics-plus' );
			$level = 'warning';
		}
		return array(
			'source' => $is_updated ? __( '日本都道府県DB（更新データ）', 'access-analytics-plus' ) : __( '日本都道府県DB（同梱データ）', 'access-analytics-plus' ),
			'edition' => $probe['edition'],
			'last_updated' => $is_updated && ! empty( $state['updated_at'] ) ? wp_date( 'Y-m-d H:i', (int) $state['updated_at'] ) : sprintf( __( '%s版（同梱）', 'access-analytics-plus' ), $probe['edition'] ),
			'status' => $status, 'status_level' => $level, 'error' => (string) ( $state['last_error'] ?? '' ), 'feed_available' => $feed_available,
		);
	}

	/** @return array{success:bool,message:string} */
	public static function maybe_update( bool $force = false ): array {
		$url = self::manifest_url();
		if ( '' === $url ) {
			return array( 'success' => false, 'message' => 'feed_unavailable' );
		}
		$last_check = (int) get_option( self::OPTION_LAST_CHECK, 0 );
		if ( ! $force && $last_check > time() - DAY_IN_SECONDS ) {
			return array( 'success' => true, 'message' => 'not_due' );
		}
		update_option( self::OPTION_LAST_CHECK, time(), false );

		$response = wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 3, 'limit_response_size' => self::MAX_MANIFEST_BYTES ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::update_failure( is_wp_error( $response ) ? $response->get_error_message() : 'manifest_http_error' );
		}
		$manifest = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $manifest ) ) {
			return self::update_failure( 'invalid_manifest' );
		}
		$validated = self::validate_manifest( $manifest );
		if ( false === $validated ) {
			return self::update_failure( 'invalid_manifest_fields' );
		}
		$current = self::database_candidates()[0] ?? null;
		if ( ! $force && null !== $current && strcmp( $current['edition'], $validated['edition'] ) >= 0 ) {
			return array( 'success' => true, 'message' => 'current' );
		}
		return self::download_database( $validated );
	}

	public static function handle_manual_update(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( '都道府県データを更新する権限がありません。', 'access-analytics-plus' ) );
		}
		check_admin_referer( 'aap_update_region_database' );
		$result = self::maybe_update( true );
		wp_safe_redirect( add_query_arg( array( 'page' => Settings::PAGE_SLUG, 'region_update' => $result['success'] ? 'success' : 'error' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function manifest_url(): string {
		$url = defined( 'AAP_REGION_DATABASE_MANIFEST_URL' ) ? (string) AAP_REGION_DATABASE_MANIFEST_URL : '';
		$url = (string) apply_filters( 'aap_region_database_manifest_url', $url );
		return wp_http_validate_url( $url ) && str_starts_with( strtolower( $url ), 'https://' ) ? $url : '';
	}

	/** @return array{edition:string,url:string,sha256:string,size:int}|false */
	private static function validate_manifest( array $manifest ) {
		$artifact = isset( $manifest['artifact'] ) && is_array( $manifest['artifact'] ) ? $manifest['artifact'] : $manifest;
		$edition = (string) ( $manifest['edition'] ?? '' );
		$url = (string) ( $artifact['download_url'] ?? $artifact['url'] ?? '' );
		$sha256 = strtoupper( (string) ( $artifact['sha256'] ?? '' ) );
		$size = absint( $artifact['size'] ?? 0 );
		$source = isset( $manifest['source'] ) && is_array( $manifest['source'] ) ? $manifest['source'] : array();
		$source_edition = (string) ( $manifest['source_version'] ?? $source['edition'] ?? '' );
		$license = (string) ( $manifest['license'] ?? $source['license'] ?? '' );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}$/', $edition ) || 1 !== preg_match( '/^[A-F0-9]{64}$/', $sha256 ) ) { return false; }
		if ( $edition !== $source_edition ) { return false; }
		if ( $size < 1024 || $size > self::MAX_DATABASE_BYTES || 'CC BY 4.0' !== $license ) { return false; }
		if ( ! wp_http_validate_url( $url ) || ! str_starts_with( strtolower( $url ), 'https://' ) ) { return false; }
		return array( 'edition' => $edition, 'url' => $url, 'sha256' => $sha256, 'size' => $size );
	}

	/** @param array{edition:string,url:string,sha256:string,size:int} $artifact @return array{success:bool,message:string} */
	private static function download_database( array $artifact ): array {
		if ( ! function_exists( 'download_url' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
		$temporary = download_url( $artifact['url'], 20, false );
		if ( is_wp_error( $temporary ) ) { return self::update_failure( $temporary->get_error_message() ); }
		try {
			$size = filesize( $temporary );
			if ( $artifact['size'] !== $size || $size > self::MAX_DATABASE_BYTES ) { throw new \RuntimeException( 'database_size_mismatch' ); }
			if ( ! hash_equals( $artifact['sha256'], strtoupper( hash_file( 'sha256', $temporary ) ?: '' ) ) ) { throw new \RuntimeException( 'database_hash_mismatch' ); }
			self::validate_database( $temporary, $artifact['edition'] );
			$directory = self::updates_directory();
			if ( '' === $directory || ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) ) { throw new \RuntimeException( 'update_directory_unavailable' ); }
			$filename = sprintf( 'aap-japan-prefecture-%s-%s.mmdb', $artifact['edition'], strtolower( substr( $artifact['sha256'], 0, 12 ) ) );
			$destination = trailingslashit( $directory ) . $filename;
			if ( ! @rename( $temporary, $destination ) && ! @copy( $temporary, $destination ) ) { throw new \RuntimeException( 'database_install_failed' ); }
			$previous = self::state();
			update_option( self::OPTION_STATE, array( 'file' => $filename, 'edition' => $artifact['edition'], 'sha256' => $artifact['sha256'], 'updated_at' => time(), 'last_error' => '' ), false );
			self::close_readers();
			$old_file = basename( (string) ( $previous['file'] ?? '' ) );
			if ( '' !== $old_file && $old_file !== $filename && 1 === preg_match( '/^aap-japan-prefecture-\d{4}-\d{2}-[a-f0-9]{12}\.mmdb$/', $old_file ) ) {
				@unlink( trailingslashit( $directory ) . $old_file );
			}
			return array( 'success' => true, 'message' => 'updated' );
		} catch ( \Throwable $error ) {
			return self::update_failure( $error->getMessage() );
		} finally {
			@unlink( $temporary );
		}
	}

	private static function validate_database( string $path, string $edition ): void {
		$reader = self::reader( $path, false );
		try {
			$type = (string) $reader->metadata()->databaseType;
			if ( false === stripos( $type, 'Access-Analytics-Plus-JP-Prefecture-' . $edition ) ) { throw new \RuntimeException( 'unexpected_database_type' ); }
			$record = $reader->get( '1.0.16.1' );
			if ( ! is_array( $record ) || 'JP-13' !== Region_Resolver::normalize( (string) ( $record['region_code'] ?? '' ) ) ) { throw new \RuntimeException( 'database_validation_failed' ); }
			if ( null !== $reader->get( '8.8.8.8' ) ) { throw new \RuntimeException( 'database_contains_non_jp_probe' ); }
		} finally { $reader->close(); }
	}

	/** @return array{success:bool,message:string} */
	private static function update_failure( string $message ): array {
		$state = self::state();
		$state['last_error'] = substr( sanitize_text_field( $message ), 0, 300 );
		$state['last_attempt'] = time();
		update_option( self::OPTION_STATE, $state, false );
		return array( 'success' => false, 'message' => (string) $state['last_error'] );
	}

	private static function reader( string $path, bool $cache = true ): \MaxMind\Db\Reader {
		self::load_reader();
		if ( $cache && isset( self::$readers[ $path ] ) ) { return self::$readers[ $path ]; }
		$reader = new \MaxMind\Db\Reader( $path );
		if ( $cache ) { self::$readers[ $path ] = $reader; }
		return $reader;
	}

	private static function load_reader(): void {
		if ( class_exists( '\\MaxMind\\Db\\Reader' ) || self::$autoload_registered ) { return; }
		self::$autoload_registered = true;
		spl_autoload_register( static function ( string $class ): void {
			$prefix = 'MaxMind\\Db\\';
			if ( ! str_starts_with( $class, $prefix ) ) { return; }
			$relative = substr( $class, strlen( $prefix ) );
			$path = AAP_PLUGIN_DIR . 'includes/vendor/maxmind-db-reader/src/MaxMind/Db/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) { require_once $path; }
		} );
	}

	private static function close_readers(): void {
		foreach ( self::$readers as $reader ) { try { $reader->close(); } catch ( \Throwable $error ) {} }
		self::$readers = array();
	}

	/** @return array<int,array{path:string,source:string,edition:string}> */
	private static function database_candidates(): array {
		$candidates = array();
		$state = self::state();
		$file = basename( (string) ( $state['file'] ?? '' ) );
		$edition = (string) ( $state['edition'] ?? '' );
		$directory = self::updates_directory();
		if ( '' !== $directory && 1 === preg_match( '/^aap-japan-prefecture-\d{4}-\d{2}-[a-f0-9]{12}\.mmdb$/', $file ) && 1 === preg_match( '/^\d{4}-\d{2}$/', $edition ) ) {
			$path = trailingslashit( $directory ) . $file;
			if ( is_readable( $path ) ) { $candidates[] = array( 'path' => $path, 'source' => 'region_updated', 'edition' => $edition ); }
		}
		$bundled = AAP_PLUGIN_DIR . self::BUNDLED_FILE;
		if ( is_readable( $bundled ) ) { $candidates[] = array( 'path' => $bundled, 'source' => 'region_bundled', 'edition' => self::BUNDLED_EDITION ); }
		return $candidates;
	}

	private static function updates_directory(): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) { return ''; }
		return trailingslashit( (string) $uploads['basedir'] ) . 'access-analytics-plus/geo';
	}

	/** @return array<string,mixed> */
	private static function state(): array {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}
}
