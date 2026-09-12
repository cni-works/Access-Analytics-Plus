<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) { exit; }
$root = dirname( __DIR__ );
$manifest = json_decode( (string) file_get_contents( $root . '/data/aap-japan-prefecture-2026-09.manifest.json' ), true );
spl_autoload_register( static function ( string $class ) use ( $root ): void {
	$prefix = 'MaxMind\\Db\\';
	if ( ! str_starts_with( $class, $prefix ) ) { return; }
	$path = $root . '/includes/vendor/maxmind-db-reader/src/MaxMind/Db/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_readable( $path ) ) { require_once $path; }
} );
$addresses = array();
foreach ( $manifest['probes'] as $probe ) { $addresses[] = $probe['ipv4']; $addresses[] = $probe['ipv6']; }
$opened = hrtime( true );
$reader = new MaxMind\Db\Reader( $root . '/data/aap-japan-prefecture-2026-09.mmdb' );
$open_ms = ( hrtime( true ) - $opened ) / 1e6;
echo sprintf( "Reader open: %.3f ms\n", $open_ms );
foreach ( array( 1, 1000, 10000 ) as $count ) {
	$started = hrtime( true );
	for ( $index = 0; $index < $count; $index++ ) { $reader->get( $addresses[ $index % count( $addresses ) ] ); }
	$elapsed = ( hrtime( true ) - $started ) / 1e6;
	echo sprintf( "%d lookups: %.3f ms (%.4f ms/lookup)\n", $count, $elapsed, $elapsed / $count );
}
$reader->close();
