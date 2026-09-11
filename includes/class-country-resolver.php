<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

final class Country_Resolver {
	public const UNKNOWN = 'ZZ';

	/** @return array{code:string,source:string} */
	public static function resolve(): array {
		$ip = Settings::current_ip();

		/**
		 * Provides the highest-priority country override without making a remote request.
		 * Return an ISO alpha-2 code, or an empty value to continue automatic detection.
		 * Returning ZZ explicitly marks the request as unknown.
		 *
		 * @param string $code Empty by default.
		 * @param string $ip Normalized request IP. Use in memory only; do not persist it.
		 * @param string $source Always "none" at this priority stage.
		 */
		$filtered = apply_filters( 'aap_country_code', '', $ip, 'none' );
		if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
			return array( 'code' => self::normalize( $filtered ), 'source' => 'filter' );
		}

		$trust_cloudflare = Settings::trust_cloudflare_country()
			|| ( defined( 'AAP_TRUST_CF_IPCOUNTRY' ) && AAP_TRUST_CF_IPCOUNTRY )
			|| ( defined( 'AAP_TRUST_CF_CONNECTING_IP' ) && AAP_TRUST_CF_CONNECTING_IP );
		if ( $trust_cloudflare && isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$candidate = self::normalize( wp_unslash( (string) $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
			if ( self::UNKNOWN !== $candidate ) {
				return array( 'code' => $candidate, 'source' => 'cloudflare' );
			}
		}

		if ( defined( 'AAP_TRUST_GEOIP_COUNTRY_CODE' ) && AAP_TRUST_GEOIP_COUNTRY_CODE && isset( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) {
			$candidate = self::normalize( wp_unslash( (string) $_SERVER['GEOIP_COUNTRY_CODE'] ) );
			if ( self::UNKNOWN !== $candidate ) {
				return array( 'code' => $candidate, 'source' => 'server' );
			}
		}

		$local = GeoIP_Database::lookup( $ip );
		if ( self::UNKNOWN !== $local['code'] ) {
			return array( 'code' => $local['code'], 'source' => $local['source'] );
		}

		return array( 'code' => self::UNKNOWN, 'source' => 'unknown' );
	}

	public static function is_allowed( string $country_code ): bool {
		if ( 'all' === Settings::country_mode() || self::UNKNOWN === $country_code ) {
			return true;
		}
		return in_array( $country_code, Settings::allowed_countries(), true );
	}

	public static function normalize( string $code ): string {
		$code = strtoupper( trim( $code ) );
		if ( in_array( $code, array( 'XX', 'T1', 'A1', 'A2', 'O1' ), true ) ) {
			return self::UNKNOWN;
		}
		return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : self::UNKNOWN;
	}

	public static function source_label(): string {
		if ( Settings::trust_cloudflare_country() || ( defined( 'AAP_TRUST_CF_IPCOUNTRY' ) && AAP_TRUST_CF_IPCOUNTRY ) || ( defined( 'AAP_TRUST_CF_CONNECTING_IP' ) && AAP_TRUST_CF_CONNECTING_IP ) ) {
			return __( 'サイト側の指定、信頼済みCloudflare、信頼済みサーバーGeoIP、ローカルDB-IPの順で判定します。', 'access-analytics-plus' );
		}
		if ( defined( 'AAP_TRUST_GEOIP_COUNTRY_CODE' ) && AAP_TRUST_GEOIP_COUNTRY_CODE ) {
			return __( 'サイト側の指定、信頼済みサーバーGeoIP、ローカルDB-IPの順で判定します。', 'access-analytics-plus' );
		}
		return __( 'サイト側の指定を優先し、通常はローカルDB-IPで判定します。判定不能（ZZ）は通常集計へ含めます。', 'access-analytics-plus' );
	}
}
