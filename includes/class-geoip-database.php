<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

final class GeoIP_Database {
	public const PROVIDER_URL = 'https://db-ip.com/';
	public const LICENSE_URL = 'https://creativecommons.org/licenses/by/4.0/';
	public const BUNDLED_EDITION = '2026-09';
	public const STALE_DAYS = 60;

	private const BUNDLED_FILE = 'data/dbip-country-lite-2026-09.mmdb';
	private const DOWNLOAD_HOST = 'download.db-ip.com';
	private const OPTION_STATE = 'aap_geoip_database_state';
	private const OPTION_LAST_CHECK = 'aap_geoip_last_check';
	private const MAX_COMPRESSED_BYTES = 12582912;
	private const MAX_DATABASE_BYTES = 25165824;

	/** @var array<string, \MaxMind\Db\Reader> */
	private static array $readers = array();
	private static bool $autoload_registered = false;

	public static function register(): void {
		add_action( 'admin_post_aap_update_geoip', array( self::class, 'handle_manual_update' ) );
	}

	/** @return array{code:string,source:string,edition:string} */
	public static function lookup( string $ip ): array {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return array( 'code' => Country_Resolver::UNKNOWN, 'source' => 'unknown', 'edition' => '' );
		}

		foreach ( self::database_candidates() as $candidate ) {
			try {
				$reader = self::reader( $candidate['path'] );
				$record = $reader->get( $ip );
				$code = is_array( $record ) && isset( $record['country']['iso_code'] )
					? Country_Resolver::normalize( (string) $record['country']['iso_code'] )
					: Country_Resolver::UNKNOWN;
				if ( Country_Resolver::UNKNOWN !== $code ) {
					return array( 'code' => $code, 'source' => $candidate['source'], 'edition' => $candidate['edition'] );
				}
			} catch ( \Throwable $error ) {
				// A missing or damaged update must silently fall back to the bundled database.
			}
		}

		return array( 'code' => Country_Resolver::UNKNOWN, 'source' => 'unknown', 'edition' => '' );
	}

	/**
	 * @return array{source:string,edition:string,last_updated:string,status:string,status_level:string,error:string}
	 */
	public static function status(): array {
		$probe = self::lookup( '8.8.8.8' );
		$active = Country_Resolver::UNKNOWN === $probe['code']
			? null
			: array( 'source' => $probe['source'], 'edition' => $probe['edition'] );
		$state = self::state();

		if ( null === $active ) {
			return array(
				'source' => __( '利用可能なデータなし', 'access-analytics-plus' ),
				'edition' => '—',
				'last_updated' => '—',
				'status' => __( '国判定データを読み込めません。国コードは判定不能（ZZ）として扱います。', 'access-analytics-plus' ),
				'status_level' => 'error',
				'error' => (string) ( $state['last_error'] ?? '' ),
			);
		}

		$edition_time = strtotime( $active['edition'] . '-01 00:00:00 UTC' );
		$is_stale = false !== $edition_time && $edition_time < time() - ( self::STALE_DAYS * DAY_IN_SECONDS );
		$is_updated = 'dbip_updated' === $active['source'];
		$last_updated = $is_updated && ! empty( $state['updated_at'] )
			? wp_date( 'Y-m-d H:i', (int) $state['updated_at'] )
			: sprintf( __( '%s版（同梱）', 'access-analytics-plus' ), $active['edition'] );

		$status = __( '利用可能です。更新に失敗した場合も、利用可能な既存データへ自動で戻ります。', 'access-analytics-plus' );
		$status_level = 'ok';
		if ( '' !== (string) ( $state['last_error'] ?? '' ) ) {
			$status = __( '直近の更新に失敗しました。表示中の既存または同梱データで国判定を継続しています。', 'access-analytics-plus' );
			$status_level = 'warning';
		} elseif ( $is_stale ) {
			$status = __( 'データ版が60日以上前です。国判定は継続しますが、更新を推奨します。', 'access-analytics-plus' );
			$status_level = 'warning';
		}

		return array(
			'source' => $is_updated ? __( 'DB-IP Country Lite（更新データ）', 'access-analytics-plus' ) : __( 'DB-IP Country Lite（同梱データ）', 'access-analytics-plus' ),
			'edition' => $active['edition'],
			'last_updated' => $last_updated,
			'status' => $status,
			'status_level' => $status_level,
			'error' => (string) ( $state['last_error'] ?? '' ),
		);
	}

	/** @return array{success:bool,message:string} */
	public static function maybe_update( bool $force = false ): array {
		$last_check = (int) get_option( self::OPTION_LAST_CHECK, 0 );
		if ( ! $force && $last_check > time() - DAY_IN_SECONDS ) {
			return array( 'success' => true, 'message' => 'not_due' );
		}
		update_option( self::OPTION_LAST_CHECK, time(), false );

		$target_edition = gmdate( 'Y-m' );
		$current = self::database_candidates()[0] ?? null;
		if ( ! $force && null !== $current && strcmp( $current['edition'], $target_edition ) >= 0 ) {
			return array( 'success' => true, 'message' => 'current' );
		}

		$result = self::download_edition( $target_edition );
		if ( ! $result['success'] ) {
			$state = self::state();
			$state['last_error'] = $result['message'];
			$state['last_attempt'] = time();
			update_option( self::OPTION_STATE, $state, false );
		}
		return $result;
	}

	public static function handle_manual_update(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( '国判定データを更新する権限がありません。', 'access-analytics-plus' ) );
		}
		check_admin_referer( 'aap_update_geoip' );
		$result = self::maybe_update( true );
		$url = add_query_arg(
			array(
				'page' => Settings::PAGE_SLUG,
				'geoip_update' => $result['success'] ? 'success' : 'error',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** @return array{success:bool,message:string} */
	private static function download_edition( string $edition ): array {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}$/', $edition ) ) {
			return array( 'success' => false, 'message' => 'invalid_edition' );
		}

		$url = sprintf( 'https://%s/free/dbip-country-lite-%s.mmdb.gz', self::DOWNLOAD_HOST, $edition );
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$compressed = download_url( $url, 25, false );
		if ( is_wp_error( $compressed ) ) {
			return array( 'success' => false, 'message' => sanitize_text_field( $compressed->get_error_message() ) );
		}

		$temporary = wp_tempnam( 'aap-dbip-' . $edition . '.mmdb' );
		if ( ! is_string( $temporary ) || '' === $temporary ) {
			@unlink( $compressed );
			return array( 'success' => false, 'message' => 'temporary_file_unavailable' );
		}

		try {
			$compressed_size = filesize( $compressed );
			if ( false === $compressed_size || $compressed_size < 1024 || $compressed_size > self::MAX_COMPRESSED_BYTES ) {
				throw new \RuntimeException( 'unexpected_download_size' );
			}
			self::decompress( $compressed, $temporary );
			self::validate_database( $temporary );

			$directory = self::updates_directory();
			if ( '' === $directory || ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) ) {
				throw new \RuntimeException( 'update_directory_unavailable' );
			}
			$filename = 'dbip-country-lite-' . $edition . '.mmdb';
			$destination = trailingslashit( $directory ) . $filename;
			if ( ! @rename( $temporary, $destination ) ) {
				if ( ! @copy( $temporary, $destination ) ) {
					throw new \RuntimeException( 'database_install_failed' );
				}
				@unlink( $temporary );
			}

			$previous = self::state();
			update_option(
				self::OPTION_STATE,
				array(
					'file' => $filename,
					'edition' => $edition,
					'updated_at' => time(),
					'last_attempt' => time(),
					'last_error' => '',
				),
				false
			);
			self::close_readers();

			$old_file = isset( $previous['file'] ) ? basename( (string) $previous['file'] ) : '';
			if ( '' !== $old_file && $old_file !== $filename && 1 === preg_match( '/^dbip-country-lite-\d{4}-\d{2}\.mmdb$/', $old_file ) ) {
				@unlink( trailingslashit( $directory ) . $old_file );
			}
			return array( 'success' => true, 'message' => 'updated' );
		} catch ( \Throwable $error ) {
			return array( 'success' => false, 'message' => sanitize_text_field( $error->getMessage() ) );
		} finally {
			@unlink( $compressed );
			@unlink( $temporary );
		}
	}

	private static function decompress( string $compressed, string $destination ): void {
		if ( ! function_exists( 'gzopen' ) ) {
			throw new \RuntimeException( 'zlib_unavailable' );
		}
		$input = gzopen( $compressed, 'rb' );
		$output = fopen( $destination, 'wb' );
		if ( false === $input || false === $output ) {
			if ( is_resource( $input ) ) { gzclose( $input ); }
			if ( is_resource( $output ) ) { fclose( $output ); }
			throw new \RuntimeException( 'decompression_failed' );
		}

		$written = 0;
		try {
			while ( ! gzeof( $input ) ) {
				$chunk = gzread( $input, 1048576 );
				if ( false === $chunk ) { throw new \RuntimeException( 'decompression_failed' ); }
				$written += strlen( $chunk );
				if ( $written > self::MAX_DATABASE_BYTES ) { throw new \RuntimeException( 'database_too_large' ); }
				$offset = 0;
				$length = strlen( $chunk );
				while ( $offset < $length ) {
					$bytes = fwrite( $output, substr( $chunk, $offset ) );
					if ( false === $bytes || 0 === $bytes ) { throw new \RuntimeException( 'database_write_failed' ); }
					$offset += $bytes;
				}
			}
		} finally {
			gzclose( $input );
			fclose( $output );
		}
		if ( $written < 1024 ) { throw new \RuntimeException( 'database_empty' ); }
	}

	private static function validate_database( string $path ): void {
		$reader = self::reader( $path, false );
		try {
			$metadata = $reader->metadata();
			if ( false === stripos( (string) $metadata->databaseType, 'country' ) ) {
				throw new \RuntimeException( 'unexpected_database_type' );
			}
			$record = $reader->get( '8.8.8.8' );
			if ( ! is_array( $record ) || 'US' !== (string) ( $record['country']['iso_code'] ?? '' ) ) {
				throw new \RuntimeException( 'database_validation_failed' );
			}
		} finally {
			$reader->close();
		}
	}

	private static function reader( string $path, bool $cache = true ): \MaxMind\Db\Reader {
		self::load_reader();
		if ( $cache && isset( self::$readers[ $path ] ) ) {
			return self::$readers[ $path ];
		}
		$reader = new \MaxMind\Db\Reader( $path );
		if ( $cache ) { self::$readers[ $path ] = $reader; }
		return $reader;
	}

	private static function load_reader(): void {
		if ( class_exists( '\\MaxMind\\Db\\Reader' ) || self::$autoload_registered ) {
			return;
		}
		self::$autoload_registered = true;
		spl_autoload_register(
			static function ( string $class ): void {
				$prefix = 'MaxMind\\Db\\';
				if ( ! str_starts_with( $class, $prefix ) ) { return; }
				$relative = substr( $class, strlen( $prefix ) );
				$path = AAP_PLUGIN_DIR . 'includes/vendor/maxmind-db-reader/src/MaxMind/Db/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $path ) ) { require_once $path; }
			}
		);
	}

	private static function close_readers(): void {
		foreach ( self::$readers as $reader ) {
			try { $reader->close(); } catch ( \Throwable $error ) {}
		}
		self::$readers = array();
	}

	/** @return array<int,array{path:string,source:string,edition:string}> */
	private static function database_candidates(): array {
		$candidates = array();
		$state = self::state();
		$file = isset( $state['file'] ) ? basename( (string) $state['file'] ) : '';
		$edition = isset( $state['edition'] ) ? (string) $state['edition'] : '';
		$updates = self::updates_directory();
		if ( '' !== $updates && 1 === preg_match( '/^dbip-country-lite-\d{4}-\d{2}\.mmdb$/', $file ) && 1 === preg_match( '/^\d{4}-\d{2}$/', $edition ) ) {
			$path = trailingslashit( $updates ) . $file;
			if ( is_readable( $path ) ) {
				$candidates[] = array( 'path' => $path, 'source' => 'dbip_updated', 'edition' => $edition );
			}
		}

		$bundled = AAP_PLUGIN_DIR . self::BUNDLED_FILE;
		if ( is_readable( $bundled ) ) {
			$candidates[] = array( 'path' => $bundled, 'source' => 'dbip_bundled', 'edition' => self::BUNDLED_EDITION );
		}
		return $candidates;
	}

	private static function updates_directory(): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		return trailingslashit( (string) $uploads['basedir'] ) . 'access-analytics-plus/geo';
	}

	/** @return array<string,mixed> */
	private static function state(): array {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}
}
