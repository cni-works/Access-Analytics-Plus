<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

final class Cron {
	public const CLEANUP_HOOK = 'aap_daily_cleanup';

	public static function register(): void {
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );
		add_action( 'init', array( self::class, 'ensure_schedule' ) );
	}

	public static function schedule(): void {
		self::ensure_schedule();
	}

	public static function ensure_schedule(): void {
		$event = wp_get_scheduled_event( self::CLEANUP_HOOK );
		if ( $event && 'hourly' === $event->schedule ) {
			return;
		}
		if ( $event ) {
			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		}
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	public static function cleanup(): void {
		global $wpdb;

		$yesterday = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );
		if ( $yesterday !== (string) get_option( 'aap_last_daily_rebuild', '' ) ) {
			Analytics::rebuild_daily( $yesterday );
			update_option( 'aap_last_daily_rebuild', $yesterday, false );
		}

		$days      = max( 90, min( 365, absint( get_option( 'aap_retention_days', 90 ) ) ) );
		$retention_start = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . ( $days - 1 ) . ' days' );
		$threshold = $retention_start
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
		$threshold_date = $retention_start->format( 'Y-m-d' );
		$tables    = Database::tables();

		// Bounded deletes avoid a long lock on larger sites.
		for ( $batch = 0; $batch < 5; ++$batch ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tables['pageviews']} WHERE viewed_at < %s LIMIT 5000",
					$threshold
				)
			);
			if ( 5000 !== $deleted ) {
				break;
			}
		}

		for ( $batch = 0; $batch < 5; ++$batch ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tables['sessions']} WHERE last_seen_at < %s LIMIT 5000",
					$threshold
				)
			);
			if ( 5000 !== $deleted ) {
				break;
			}
		}

		// Unique visitor/session markers are detail data; daily totals remain in aap_daily.
		for ( $batch = 0; $batch < 5; ++$batch ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tables['daily_dimensions']} WHERE stat_date < %s LIMIT 5000",
					$threshold_date
				)
			);
			if ( 5000 !== $deleted ) {
				break;
			}
		}
	}
}
