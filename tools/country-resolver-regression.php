<?php

declare(strict_types=1);

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	define( 'AAP_TRUST_GEOIP_COUNTRY_CODE', true );
}

namespace AccessAnalyticsPlus {
	final class Settings {
		public static string $mode = 'all';
		public static array $countries = array( 'JP' );
		public static bool $trust_cloudflare = false;
		public static function country_mode(): string { return self::$mode; }
		public static function allowed_countries(): array { return self::$countries; }
		public static function trust_cloudflare_country(): bool { return self::$trust_cloudflare; }
		public static function current_ip(): string { return '133.130.64.1'; }
	}
	final class GeoIP_Database {
		public static array $result = array( 'code' => 'JP', 'source' => 'dbip_bundled', 'edition' => '2026-09' );
		public static function lookup( string $ip ): array { return self::$result; }
	}
	$GLOBALS['aap_country_override'] = '';
	function __( string $text, string $domain = '' ): string { return $text; }
	function wp_unslash( string $value ): string { return $value; }
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return 'aap_country_code' === $hook ? $GLOBALS['aap_country_override'] : $value;
	}
	require_once dirname( __DIR__ ) . '/includes/class-country-resolver.php';
	function expect_country( bool $condition, string $message ): void { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }

	expect_country( 'JP' === Country_Resolver::normalize( 'jp' ), 'ISO country codes are normalized' );
	expect_country( Country_Resolver::UNKNOWN === Country_Resolver::normalize( 'T1' ), 'Cloudflare special codes become unknown' );
	expect_country( 'JP' === Country_Resolver::resolve()['code'], 'local DB-IP is the automatic fallback' );
	expect_country( 'dbip_bundled' === Country_Resolver::resolve()['source'], 'local DB-IP source is preserved' );

	$_SERVER['GEOIP_COUNTRY_CODE'] = 'DE';
	expect_country( 'DE' === Country_Resolver::resolve()['code'], 'trusted server GeoIP takes priority over local DB-IP' );

	Settings::$trust_cloudflare = true;
	$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
	expect_country( 'US' === Country_Resolver::resolve()['code'], 'trusted Cloudflare takes priority over server GeoIP' );

	$GLOBALS['aap_country_override'] = 'AU';
	expect_country( 'AU' === Country_Resolver::resolve()['code'], 'aap_country_code has the highest priority' );
	expect_country( 'filter' === Country_Resolver::resolve()['source'], 'filter source is recorded' );

	Settings::$mode = 'allowlist';
	expect_country( Country_Resolver::is_allowed( 'JP' ), 'allowlisted country is included' );
	expect_country( ! Country_Resolver::is_allowed( 'US' ), 'known non-allowlisted country is excluded' );
	expect_country( Country_Resolver::is_allowed( Country_Resolver::UNKNOWN ), 'unknown country remains included' );
	echo "Country resolver regression checks passed.\n";
}
