<?php

declare(strict_types=1);

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'AAP_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	// Simulate another component having registered the official global function.
	function mmdb_autoload( string $class ): void {}
	function download_url( string $url, int $timeout = 25, bool $signature = false ): string {
		return \AccessAnalyticsPlus\download_url( $url, $timeout, $signature );
	}
}

namespace AccessAnalyticsPlus {
	$GLOBALS['aap_geoip_options'] = array();
	$GLOBALS['aap_geoip_test_root'] = sys_get_temp_dir() . '/aap-geoip-regression-' . bin2hex( random_bytes( 5 ) );
	$GLOBALS['aap_geoip_corrupt_download'] = false;
	final class Country_Resolver {
		public const UNKNOWN = 'ZZ';
		public static function normalize( string $code ): string {
			$code = strtoupper( trim( $code ) );
			return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : self::UNKNOWN;
		}
	}
	function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['aap_geoip_options'][ $name ] ?? $default; }
	function update_option( string $name, mixed $value, bool $autoload = false ): bool { $GLOBALS['aap_geoip_options'][ $name ] = $value; return true; }
	function wp_upload_dir( mixed $time = null, bool $create = true ): array { return array( 'basedir' => $GLOBALS['aap_geoip_test_root'], 'error' => '' ); }
	function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
	function wp_tempnam( string $filename = '' ): string { return tempnam( sys_get_temp_dir(), 'aap-mmdb-' ); }
	function is_wp_error( mixed $value ): bool { return false; }
	function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function download_url( string $url, int $timeout = 25, bool $signature = false ): string {
		$target = tempnam( sys_get_temp_dir(), 'aap-gzip-' );
		if ( $GLOBALS['aap_geoip_corrupt_download'] ) { file_put_contents( $target, 'invalid' ); return $target; }
		$input = fopen( dirname( __DIR__ ) . '/data/dbip-country-lite-2026-09.mmdb', 'rb' );
		$output = gzopen( $target, 'wb6' );
		while ( ! feof( $input ) ) { gzwrite( $output, (string) fread( $input, 1048576 ) ); }
		fclose( $input );
		gzclose( $output );
		return $target;
	}

	require_once dirname( __DIR__ ) . '/includes/class-geoip-database.php';

	function expect_geoip( bool $condition, string $message ): void {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	}

	$database = dirname( __DIR__ ) . '/data/dbip-country-lite-2026-09.mmdb';
	expect_geoip( is_readable( $database ), 'bundled MMDB is readable' );
	expect_geoip( filesize( $database ) > 1000000 && filesize( $database ) < 25165824, 'bundled MMDB size is within limits' );

	$checks = array( '8.8.8.8' => 'US', '133.130.64.0' => 'JP', '1.1.1.1' => 'AU' );
	foreach ( $checks as $ip => $expected ) {
		$result = GeoIP_Database::lookup( $ip );
		expect_geoip( $expected === $result['code'], "country lookup for {$ip}" );
		expect_geoip( 'dbip_bundled' === $result['source'], "bundled fallback for {$ip}" );
		expect_geoip( '2026-09' === $result['edition'], "edition for {$ip}" );
	}
	expect_geoip( 'ZZ' === GeoIP_Database::lookup( 'not-an-ip' )['code'], 'invalid IP becomes unknown' );

	$update = GeoIP_Database::maybe_update( true );
	expect_geoip( $update['success'], 'validated update is installed' );
	$updated_lookup = GeoIP_Database::lookup( '8.8.8.8' );
	expect_geoip( 'US' === $updated_lookup['code'] && 'dbip_updated' === $updated_lookup['source'], 'installed update takes priority over bundled data' );
	$GLOBALS['aap_geoip_corrupt_download'] = true;
	$failed_update = GeoIP_Database::maybe_update( true );
	expect_geoip( ! $failed_update['success'], 'invalid update is rejected' );
	expect_geoip( 'dbip_updated' === GeoIP_Database::lookup( '8.8.8.8' )['source'], 'failed update leaves the prior database active' );

	$reader = new \MaxMind\Db\Reader( $database );
	try {
		$metadata = $reader->metadata();
		expect_geoip( 'DBIP-Country-Lite' === $metadata->databaseType, 'database type is DBIP-Country-Lite' );
		expect_geoip( '2026-09' === gmdate( 'Y-m', $metadata->buildEpoch ), 'database build month matches bundled edition' );
	} finally {
		$reader->close();
	}

	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-geoip-database.php' );
	$resolver = file_get_contents( dirname( __DIR__ ) . '/includes/class-country-resolver.php' );
	$notices = file_get_contents( dirname( __DIR__ ) . '/THIRD_PARTY_NOTICES.md' );
	expect_geoip( str_contains( $source, 'download.db-ip.com' ), 'updates use the official DB-IP host' );
	expect_geoip( str_contains( $source, 'access-analytics-plus/geo' ), 'updates use the plugin uploads directory' );
	expect_geoip( str_contains( $source, 'validate_database' ), 'download is validated before activation' );
	expect_geoip( str_contains( $resolver, "apply_filters( 'aap_country_code', ''" ), 'country filter is the highest-priority override' );
	expect_geoip( str_contains( $notices, 'CC BY 4.0' ) && str_contains( $notices, '2026-09' ), 'attribution includes license and edition' );

	$state = $GLOBALS['aap_geoip_options']['aap_geoip_database_state'] ?? array();
	$installed = trailingslashit( $GLOBALS['aap_geoip_test_root'] ) . 'access-analytics-plus/geo/' . basename( (string) ( $state['file'] ?? '' ) );
	if ( is_file( $installed ) ) { unlink( $installed ); }
	@rmdir( dirname( $installed ) );
	@rmdir( dirname( dirname( $installed ) ) );
	@rmdir( $GLOBALS['aap_geoip_test_root'] );

	echo "GeoIP database regression checks passed.\n";
}
