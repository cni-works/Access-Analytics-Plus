<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use DateTimeImmutable;

final class Sample_Data {
	/**
	 * @param array{start:DateTimeImmutable,end_exclusive:DateTimeImmutable,previous_start:DateTimeImmutable} $period Period boundaries.
	 * @return array<string,mixed>
	 */
	public static function report( array $period, string $scale, int $seed ): array {
		$current_days  = self::days( $period['start'], $period['end_exclusive'], $scale, $seed );
		$previous_days = self::days( $period['previous_start'], $period['start'], $scale, $seed );
		$current       = self::aggregate( $current_days );
		$previous      = self::aggregate( $previous_days );
		$is_single_day = 1 === (int) $period['start']->diff( $period['end_exclusive'] )->days;
		$timeseries    = $is_single_day
			? self::day( $period['start'], $scale, $seed )['hours']
			: array_map(
				static fn ( array $day ): array => array(
					'date'                    => $day['date'],
					'label'                   => $day['label'],
					'visitors'                => $day['visitors'],
					'visits'                  => $day['visits'],
					'pageviews'               => $day['pageviews'],
					'average_engaged_seconds' => $day['average_engaged_seconds'],
					'ended_visits'            => $day['visits'],
				),
				$current_days
			);
		$sources = self::distribution(
			$current['visits'],
			array( 'search' => '検索', 'direct' => 'ダイレクト', 'social' => 'SNS', 'external' => '外部サイト' ),
			array( 0.49, 0.29, 0.13, 0.09 )
		);
		$source_counts = array_column( $sources, 'value', 'key' );

		return array(
			'period'         => array(
				'start' => $period['start']->format( 'Y-m-d' ),
				'end'   => $period['end_exclusive']->modify( '-1 day' )->format( 'Y-m-d' ),
			),
			'metrics'        => array(
				'visitors'                => self::metric( $current['visitors'], $previous['visitors'] ),
				'pageviews'               => self::metric( $current['pageviews'], $previous['pageviews'] ),
				'visits'                  => self::metric( $current['visits'], $previous['visits'] ),
				'average_engaged_seconds' => self::metric( $current['average_engaged_seconds'], $previous['average_engaged_seconds'], true ),
				'bounce_rate'             => self::metric( $current['bounce_rate'], $previous['bounce_rate'], true ),
				'pages_per_visit'         => self::metric( $current['pages_per_visit'], $previous['pages_per_visit'] ),
			),
			'quality'        => array(
				'ended_visits' => $current['visits'],
				'partial'      => false,
				'provisional'  => false,
			),
			'timeseries'     => $timeseries,
			'sources'        => $sources,
			'source_details' => array(
				'search' => self::simple_counts( (int) ( $source_counts['search'] ?? 0 ), array( 'Google', 'Bing', 'Yahoo!' ), array( 0.78, 0.13, 0.09 ) ),
				'social' => self::simple_counts( (int) ( $source_counts['social'] ?? 0 ), array( 'Instagram', 'Facebook', 'X' ), array( 0.52, 0.31, 0.17 ) ),
			),
			'pages'          => self::pages( $current['pageviews'] ),
			'devices'        => self::distribution(
				$current['visits'],
				array( 'mobile' => 'スマートフォン', 'desktop' => 'PC', 'tablet' => 'タブレット' ),
				array( 0.64, 0.31, 0.05 )
			),
			'exclusions'     => self::exclusions( $current['visits'] ),
			'dashboard'      => null,
			'updated_at'     => __( '固定サンプル', 'access-analytics-plus' ),
			'sample'         => true,
			'sample_scale'   => $scale,
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function days( DateTimeImmutable $start, DateTimeImmutable $end, string $scale, int $seed ): array {
		$days = array();
		for ( $day = $start; $day < $end; $day = $day->modify( '+1 day' ) ) {
			$days[] = self::day( $day, $scale, $seed );
		}
		return $days;
	}

	/** @return array<string,mixed> */
	private static function day( DateTimeImmutable $date, string $scale, int $seed ): array {
		$ranges = array(
			'low'      => array( 5, 30 ),
			'standard' => array( 30, 100 ),
			'high'     => array( 100, 500 ),
		);
		[ $minimum, $maximum ] = $ranges[ $scale ] ?? $ranges['standard'];
		$key             = $date->format( 'Y-m-d' );
		$weekday         = (int) $date->format( 'N' );
		$weekday_factor  = 6 === $weekday ? 0.78 : ( 7 === $weekday ? 0.67 : ( 1 === $weekday ? 0.9 : 1.0 ) );
		$visits          = (int) round( ( $minimum + self::random( $seed, $key . ':visits' ) * ( $maximum - $minimum ) ) * $weekday_factor );
		$visits          = max( $minimum, min( $maximum, $visits ) );
		$visitors        = max( 1, min( $visits, (int) round( $visits * ( 0.82 + self::random( $seed, $key . ':visitors' ) * 0.13 ) ) ) );
		$pageviews       = max( $visits, (int) round( $visits * ( 1.65 + self::random( $seed, $key . ':pages' ) * 1.05 ) ) );
		$average_seconds = (int) round( 48 + self::random( $seed, $key . ':engagement' ) * 142 );
		$engaged_seconds = $average_seconds * $visits;
		$bounces         = min( $visits, (int) round( $visits * ( 0.36 + self::random( $seed, $key . ':bounce' ) * 0.24 ) ) );
		$base_weights    = array( 0.4, 0.25, 0.18, 0.15, 0.18, 0.35, 0.8, 1.45, 2.3, 3.2, 3.8, 4.2, 3.7, 3.3, 3.8, 4.3, 4.8, 4.2, 3.5, 2.7, 1.9, 1.3, 0.85, 0.55 );
		$weights         = array();
		foreach ( $base_weights as $hour => $weight ) {
			$weights[] = $weight * ( 0.84 + self::random( $seed, $key . ':hour:' . $hour ) * 0.32 );
		}
		$hour_visits     = self::allocate( $visits, $weights );
		$hour_visitors   = self::allocate( $visitors, $hour_visits );
		$hour_pageviews  = self::allocate( $pageviews, $hour_visits );
		$hour_engagement = self::allocate( $engaged_seconds, $hour_visits );
		$hours           = array();
		for ( $hour = 0; $hour < 24; ++$hour ) {
			$ended = $hour_visits[ $hour ];
			$hours[] = array(
				'date'                    => $key . sprintf( ' %02d:00', $hour ),
				'label'                   => $hour . '時',
				'visitors'                => $hour_visitors[ $hour ],
				'visits'                  => $ended,
				'pageviews'               => $hour_pageviews[ $hour ],
				'average_engaged_seconds' => $ended > 0 ? (int) round( $hour_engagement[ $hour ] / $ended ) : null,
				'ended_visits'            => $ended,
			);
		}

		return array(
			'date'                    => $key,
			'label'                   => $date->format( 'n/j' ),
			'visitors'                => $visitors,
			'visits'                  => $visits,
			'pageviews'               => $pageviews,
			'engaged_seconds'         => $engaged_seconds,
			'average_engaged_seconds' => $average_seconds,
			'bounces'                 => $bounces,
			'hours'                   => $hours,
		);
	}

	/** @param array<int,array<string,mixed>> $days @return array<string,int|float> */
	private static function aggregate( array $days ): array {
		$result = array( 'visitors' => 0, 'visits' => 0, 'pageviews' => 0, 'engaged_seconds' => 0, 'bounces' => 0 );
		foreach ( $days as $day ) {
			foreach ( array_keys( $result ) as $key ) {
				$result[ $key ] += (int) $day[ $key ];
			}
		}
		$result['average_engaged_seconds'] = $result['visits'] > 0 ? (int) round( $result['engaged_seconds'] / $result['visits'] ) : 0;
		$result['bounce_rate']              = $result['visits'] > 0 ? round( $result['bounces'] / $result['visits'] * 100, 1 ) : 0.0;
		$result['pages_per_visit']          = $result['visits'] > 0 ? round( $result['pageviews'] / $result['visits'], 1 ) : 0.0;
		return $result;
	}

	/** @param array<string,string> $labels @param float[] $weights @return array<int,array<string,int|float|string>> */
	private static function distribution( int $total, array $labels, array $weights ): array {
		$counts = self::allocate( $total, $weights );
		$result = array();
		foreach ( array_values( $labels ) as $index => $label ) {
			$result[] = array(
				'key'     => array_keys( $labels )[ $index ],
				'label'   => $label,
				'value'   => $counts[ $index ],
				'percent' => $total > 0 ? round( $counts[ $index ] / $total * 100, 1 ) : 0.0,
			);
		}
		return $result;
	}

	/** @param string[] $labels @param float[] $weights @return array<int,array{label:string,value:int}> */
	private static function simple_counts( int $total, array $labels, array $weights ): array {
		$counts = self::allocate( $total, $weights );
		return array_map(
			static fn ( string $label, int $value ): array => array( 'label' => $label, 'value' => $value ),
			$labels,
			$counts
		);
	}

	/** @return array<int,array{title:string,path:string,pageviews:int}> */
	private static function pages( int $pageviews ): array {
		$counts = self::allocate( $pageviews, array( 0.31, 0.24, 0.19, 0.15, 0.11 ) );
		$titles = array( 'トップページ', 'サービス案内', '会社案内', '施工事例', 'お問い合わせ' );
		$paths  = array( '/', '/service/', '/company/', '/works/', '/contact/' );
		$result = array();
		foreach ( $titles as $index => $title ) {
			$result[] = array( 'title' => $title, 'path' => $paths[ $index ], 'pageviews' => $counts[ $index ] );
		}
		return $result;
	}

	/** @return array{total:int,items:array<int,array{key:string,label:string,value:int}>} */
	private static function exclusions( int $visits ): array {
		$bot  = max( 2, (int) round( $visits * 0.14 ) );
		$user = max( 1, (int) round( $visits * 0.025 ) );
		return array(
			'total' => $bot + $user,
			'items' => array(
				array( 'key' => 'bot', 'label' => 'Bot・自動巡回', 'value' => $bot ),
				array( 'key' => 'user_role', 'label' => 'ログインユーザー', 'value' => $user ),
			),
		);
	}

	/** @param float[]|int[] $weights @return int[] */
	private static function allocate( int $total, array $weights ): array {
		$sum = array_sum( $weights );
		if ( $total <= 0 || $sum <= 0 ) {
			return array_fill( 0, count( $weights ), 0 );
		}
		$values = array();
		$remainders = array();
		$used = 0;
		foreach ( $weights as $index => $weight ) {
			$exact              = $total * $weight / $sum;
			$values[ $index ]    = (int) floor( $exact );
			$remainders[ $index ] = $exact - $values[ $index ];
			$used += $values[ $index ];
		}
		arsort( $remainders );
		foreach ( array_slice( array_keys( $remainders ), 0, $total - $used ) as $index ) {
			++$values[ $index ];
		}
		ksort( $values );
		return array_values( $values );
	}

	private static function random( int $seed, string $key ): float {
		return hexdec( substr( hash( 'sha256', $seed . '|' . $key ), 0, 8 ) ) / 4294967295;
	}

	/** @return array{value:int|float,previous:int|float,change:float|null,difference:float,direction:string} */
	private static function metric( int|float $value, int|float $previous, bool $zero_is_data = false ): array {
		$change = $previous > 0 ? round( ( $value - $previous ) / $previous * 100, 1 ) : null;
		return array(
			'value'      => $value,
			'previous'   => $previous,
			'change'     => $change,
			'difference' => round( $value - $previous, 1 ),
			'direction'  => ! $zero_is_data && 0 == $previous && $value > 0 ? 'unavailable' : ( $value > $previous ? 'up' : ( $value < $previous ? 'down' : 'flat' ) ),
		);
	}
}
