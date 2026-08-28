<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

final class Activator {
	public static function activate(): void {
		Database::install();
		Capabilities::install();
		Cron::schedule();
	}

	public static function deactivate(): void {
		Cron::unschedule();
	}
}
