<?php

declare(strict_types=1);

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	$GLOBALS['aap_region_filter'] = '';
	function __( string $text, string $domain = '' ): string { return $text; }
	function wp_unslash( string $value ): string { return $value; }
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return 'aap_region_code' === $hook && '' !== $GLOBALS['aap_region_filter'] ? $GLOBALS['aap_region_filter'] : $value;
	}
	function aap_region_expect( bool $condition, string $message ): void {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	}
}

namespace AccessAnalyticsPlus {
	final class Country_Resolver { public static function normalize( string $code ): string { return strtoupper( trim( $code ) ); } }
	final class Settings { public static bool $trust = false; public static function trust_cloudflare_country(): bool { return self::$trust; } }
	final class Region_Database {
		public static int $lookups = 0;
		public static function lookup( string $ip ): array { self::$lookups++; return array( 'code' => 'JP-13', 'source' => 'region_bundled', 'edition' => '2026-09' ); }
	}
	require_once dirname( __DIR__ ) . '/includes/class-region-resolver.php';

	aap_region_expect( '' === Region_Resolver::resolve( 'US', '8.8.8.8' )['code'], 'non-JP country does not resolve a prefecture' );
	aap_region_expect( 0 === Region_Database::$lookups, 'non-JP country skips the local MMDB' );
	$GLOBALS['aap_region_filter'] = 'JP-27';
	aap_region_expect( 'JP-27' === Region_Resolver::resolve( 'JP', '1.0.16.1' )['code'], 'filter has highest priority' );
	aap_region_expect( 0 === Region_Database::$lookups, 'filter avoids the MMDB' );
	$GLOBALS['aap_region_filter'] = '';
	Settings::$trust = true;
	$_SERVER['HTTP_CF_REGION_CODE'] = '14';
	$result = Region_Resolver::resolve( 'JP', '1.0.16.1' );
	aap_region_expect( 'JP-14' === $result['code'] && 'cloudflare' === $result['source'], 'trusted Cloudflare region is normalized' );
	unset( $_SERVER['HTTP_CF_REGION_CODE'] );
	Settings::$trust = false;
	$result = Region_Resolver::resolve( 'JP', '1.0.16.1' );
	aap_region_expect( 'JP-13' === $result['code'] && 1 === Region_Database::$lookups, 'JP falls back to local MMDB' );
	aap_region_expect( '' === Region_Resolver::normalize( 'JP-48' ) && 'JP-01' === Region_Resolver::normalize( '01' ), 'region validation is bounded to 47 codes' );
	aap_region_expect( '東京都' === Region_Resolver::label( 'JP-13' ) && 47 === count( Region_Resolver::labels() ), 'all prefecture labels are available' );

	echo "Region resolver regression checks passed.\n";
}
