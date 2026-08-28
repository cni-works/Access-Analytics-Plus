<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

final class Capabilities {
	public const VIEW   = 'view_access_analytics';
	public const MANAGE = 'manage_access_analytics';

	public static function install(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}

		$role->add_cap( self::VIEW );
		$role->add_cap( self::MANAGE );
	}

	public static function uninstall(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}

		$role->remove_cap( self::VIEW );
		$role->remove_cap( self::MANAGE );
	}
}
