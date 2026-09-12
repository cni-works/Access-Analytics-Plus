<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

/** Resolves a Japanese prefecture for analysis only. */
final class Region_Resolver {
	public const UNKNOWN = '';

	/** @return array{code:string,source:string,edition:string} */
	public static function resolve( string $country_code, string $ip ): array {
		if ( 'JP' !== Country_Resolver::normalize( $country_code ) ) {
			return array( 'code' => self::UNKNOWN, 'source' => 'not_jp', 'edition' => '' );
		}

		/**
		 * Supplies the highest-priority prefecture override without a remote request.
		 * Return JP-01 through JP-47, or an empty value to continue detection.
		 * The IP is provided for in-memory lookup only and must not be persisted.
		 *
		 * @param string $region_code Empty by default.
		 * @param string $ip Normalized client IP.
		 * @param string $country_code Always JP at this point.
		 */
		$filtered = apply_filters( 'aap_region_code', '', $ip, 'JP' );
		if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
			$code = self::normalize( $filtered );
			if ( self::UNKNOWN !== $code ) {
				return array( 'code' => $code, 'source' => 'filter', 'edition' => '' );
			}
		}

		$trust_cloudflare = Settings::trust_cloudflare_country()
			|| ( defined( 'AAP_TRUST_CF_IPCOUNTRY' ) && AAP_TRUST_CF_IPCOUNTRY )
			|| ( defined( 'AAP_TRUST_CF_CONNECTING_IP' ) && AAP_TRUST_CF_CONNECTING_IP );
		if ( $trust_cloudflare && isset( $_SERVER['HTTP_CF_REGION_CODE'] ) ) {
			$code = self::normalize( wp_unslash( (string) $_SERVER['HTTP_CF_REGION_CODE'] ) );
			if ( self::UNKNOWN !== $code ) {
				return array( 'code' => $code, 'source' => 'cloudflare', 'edition' => '' );
			}
		}

		if ( defined( 'AAP_TRUST_GEOIP_REGION_CODE' ) && AAP_TRUST_GEOIP_REGION_CODE && isset( $_SERVER['GEOIP_REGION_CODE'] ) ) {
			$code = self::normalize( wp_unslash( (string) $_SERVER['GEOIP_REGION_CODE'] ) );
			if ( self::UNKNOWN !== $code ) {
				return array( 'code' => $code, 'source' => 'server', 'edition' => '' );
			}
		}

		return Region_Database::lookup( $ip );
	}

	public static function normalize( string $code ): string {
		$code = strtoupper( trim( $code ) );
		if ( 1 === preg_match( '/^JP-(0[1-9]|[1-3][0-9]|4[0-7])$/', $code ) ) {
			return $code;
		}
		if ( 1 === preg_match( '/^(0[1-9]|[1-3][0-9]|4[0-7])$/', $code ) ) {
			return 'JP-' . $code;
		}
		return self::UNKNOWN;
	}

	public static function label( string $code ): string {
		return self::labels()[ self::normalize( $code ) ] ?? __( '判定不能', 'access-analytics-plus' );
	}

	/** @return array<string,string> */
	public static function labels(): array {
		$names = array(
			'北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県', '茨城県', '栃木県', '群馬県',
			'埼玉県', '千葉県', '東京都', '神奈川県', '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県',
			'岐阜県', '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県', '奈良県', '和歌山県',
			'鳥取県', '島根県', '岡山県', '広島県', '山口県', '徳島県', '香川県', '愛媛県', '高知県', '福岡県',
			'佐賀県', '長崎県', '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県',
		);
		$result = array();
		foreach ( $names as $index => $name ) {
			$result[ sprintf( 'JP-%02d', $index + 1 ) ] = $name;
		}
		return $result;
	}
}
