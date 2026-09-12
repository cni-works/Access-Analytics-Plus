<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) { exit; }

function aap_region_db_expect( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$root = dirname( __DIR__ );
$manifest_path = $root . '/data/aap-japan-prefecture-2026-09.manifest.json';
$database_path = $root . '/data/aap-japan-prefecture-2026-09.mmdb';
$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
aap_region_db_expect( is_array( $manifest ), 'manifest is valid JSON' );
aap_region_db_expect( filesize( $database_path ) === (int) $manifest['artifact']['size'], 'MMDB size matches manifest' );
aap_region_db_expect( strtoupper( hash_file( 'sha256', $database_path ) ?: '' ) === $manifest['artifact']['sha256'], 'MMDB hash matches manifest' );
aap_region_db_expect( 'CC BY 4.0' === $manifest['source']['license'], 'manifest retains CC BY 4.0' );
aap_region_db_expect( $manifest['edition'] === $manifest['source']['edition'], 'manifest identifies the DB-IP source edition' );

spl_autoload_register( static function ( string $class ) use ( $root ): void {
	$prefix = 'MaxMind\\Db\\';
	if ( ! str_starts_with( $class, $prefix ) ) { return; }
	$path = $root . '/includes/vendor/maxmind-db-reader/src/MaxMind/Db/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_readable( $path ) ) { require_once $path; }
} );

$reader = new MaxMind\Db\Reader( $database_path );
try {
	aap_region_db_expect( 'Access-Analytics-Plus-JP-Prefecture-2026-09' === $reader->metadata()->databaseType, 'MMDB type and edition match' );
	aap_region_db_expect( 47 === count( $manifest['probes'] ), 'all 47 prefectures have probes' );
	foreach ( $manifest['probes'] as $code => $probes ) {
		foreach ( array( 'ipv4', 'ipv6' ) as $family ) {
			$record = $reader->get( (string) $probes[ $family ] );
			aap_region_db_expect( is_array( $record ) && $code === ( $record['region_code'] ?? '' ), "{$code} {$family} resolves" );
		}
	}
	aap_region_db_expect( null === $reader->get( '8.8.8.8' ), 'non-JP IPv4 is absent' );
	aap_region_db_expect( null === $reader->get( '2001:4860:4860::8888' ), 'non-JP IPv6 is absent' );
} finally { $reader->close(); }

$notices = (string) file_get_contents( $root . '/THIRD_PARTY_NOTICES.md' );
aap_region_db_expect( str_contains( $notices, 'DB-IP City Lite (2026-09 edition)' ), 'notice names source edition' );
aap_region_db_expect( str_contains( $notices, 'CC BY 4.0' ) && str_contains( $notices, 'IP Geolocation by DB-IP' ), 'notice includes license and attribution' );
aap_region_db_expect( str_contains( $notices, $manifest['artifact']['sha256'] ), 'notice identifies bundled derivative' );

echo "Region database regression checks passed.\n";
