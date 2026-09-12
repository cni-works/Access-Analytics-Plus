<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

final class Plugin {
	public static function boot(): void {
		add_action( 'plugins_loaded', array( self::class, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( Database::class, 'maybe_upgrade' ), 5 );

		Cron::register();
		GeoIP_Database::register();
		Region_Database::register();
		Tracker::register();
		Rest_Controller::register();
		Admin::register();
		Settings::register();
		Dashboard_Widget::register();
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'access-analytics-plus',
			false,
			dirname( plugin_basename( AAP_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
