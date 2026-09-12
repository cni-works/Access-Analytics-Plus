<?php

declare(strict_types=1);

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	$fixture_root = sys_get_temp_dir() . '/aap-region-runtime-' . bin2hex( random_bytes( 6 ) );
	mkdir( $fixture_root . '/plugin/data', 0777, true );
	mkdir( $fixture_root . '/uploads/access-analytics-plus/geo', 0777, true );
	copy( dirname( __DIR__ ) . '/data/aap-japan-prefecture-2026-09.mmdb', $fixture_root . '/plugin/data/aap-japan-prefecture-2026-09.mmdb' );
	define( 'AAP_PLUGIN_DIR', $fixture_root . '/plugin/' );
	define( 'DAY_IN_SECONDS', 86400 );
	require_once dirname( __DIR__ ) . '/includes/vendor/maxmind-db-reader/autoload.php';
	$GLOBALS['aap_region_runtime_options'] = array();
	function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['aap_region_runtime_options'][ $name ] ?? $default; }
	function update_option( string $name, mixed $value, bool $autoload = true ): bool { $GLOBALS['aap_region_runtime_options'][ $name ] = $value; return true; }
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return $value; }
	function wp_upload_dir( mixed $time = null, bool $create = true ): array { global $fixture_root; return array( 'basedir' => $fixture_root . '/uploads', 'error' => false ); }
	function trailingslashit( string $path ): string { return rtrim( $path, '/\\' ) . '/'; }
	function __( string $text, string $domain = '' ): string { return $text; }
	function wp_date( string $format, int $timestamp ): string { return gmdate( $format, $timestamp ); }
	function wp_http_validate_url( string $url ): string|false { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false; }
	function sanitize_text_field( string $value ): string { return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ?: '' ); }
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function aap_region_runtime_expect( bool $condition, string $message ): void { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
}

namespace AccessAnalyticsPlus {
	final class Region_Resolver {
		public const UNKNOWN = '';
		public static function normalize( string $code ): string { return preg_match( '/^JP-(0[1-9]|[1-3][0-9]|4[0-7])$/', $code ) ? $code : ''; }
	}
	require_once dirname( __DIR__ ) . '/includes/class-region-database.php';

	$bundled = Region_Database::lookup( '1.0.16.1' );
	aap_region_runtime_expect( 'JP-13' === $bundled['code'] && 'region_bundled' === $bundled['source'], 'bundled MMDB resolves' );
	$bad_name = 'aap-japan-prefecture-2026-10-aaaaaaaaaaaa.mmdb';
	file_put_contents( $fixture_root . '/uploads/access-analytics-plus/geo/' . $bad_name, 'damaged' );
	$GLOBALS['aap_region_runtime_options']['aap_region_database_state'] = array( 'file' => $bad_name, 'edition' => '2026-10' );
	$fallback = Region_Database::lookup( '1.0.16.1' );
	aap_region_runtime_expect( 'JP-13' === $fallback['code'] && 'region_bundled' === $fallback['source'], 'damaged update falls back to bundled MMDB' );
	$close = new \ReflectionMethod( Region_Database::class, 'close_readers' );
	$close->invoke( null );
	unlink( $fixture_root . '/plugin/data/aap-japan-prefecture-2026-09.mmdb' );
	$missing = Region_Database::lookup( '1.0.16.1' );
	aap_region_runtime_expect( '' === $missing['code'] && 'unknown' === $missing['source'], 'missing updated and bundled MMDB fails safely' );
	aap_region_runtime_expect( '' === Region_Database::lookup( 'not-an-ip' )['code'], 'invalid IP fails safely' );
	$validate_manifest = new \ReflectionMethod( Region_Database::class, 'validate_manifest' );
	$valid_manifest = array(
		'edition' => '2026-10',
		'source' => array( 'edition' => '2026-10', 'license' => 'CC BY 4.0' ),
		'artifact' => array( 'download_url' => 'https://data.example.test/region.mmdb', 'sha256' => str_repeat( 'A', 64 ), 'size' => 1900000 ),
	);
	aap_region_runtime_expect( is_array( $validate_manifest->invoke( null, $valid_manifest ) ), 'future feed manifest validates version, source version, license, URL, hash and size' );
	$valid_manifest['source']['edition'] = '2026-09';
	aap_region_runtime_expect( false === $validate_manifest->invoke( null, $valid_manifest ), 'source edition mismatch is rejected' );

	unlink( $fixture_root . '/uploads/access-analytics-plus/geo/' . $bad_name );
	rmdir( $fixture_root . '/uploads/access-analytics-plus/geo' );
	rmdir( $fixture_root . '/uploads/access-analytics-plus' );
	rmdir( $fixture_root . '/uploads' );
	rmdir( $fixture_root . '/plugin/data' );
	rmdir( $fixture_root . '/plugin' );
	rmdir( $fixture_root );
	echo "Region runtime fallback checks passed.\n";
}
