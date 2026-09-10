<?php
/**
 * Plugin Name:       Access Analytics Plus
 * Description:       WordPress管理画面でアクセス状況を簡単に確認できる軽量アクセス解析プラグインです。
 * Version:           0.5.0-beta
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Access Analytics Plus
 * Update URI:        https://github.com/cni-works/Access-Analytics-Plus
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       access-analytics-plus
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace AccessAnalyticsPlus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AAP_VERSION', '0.5.0-beta' );
define( 'AAP_BUILD', 'beta.20260911.1' );
define( 'AAP_DB_VERSION', '2' );
define( 'AAP_PLUGIN_FILE', __FILE__ );
define( 'AAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$aap_updater_file = AAP_PLUGIN_DIR . 'includes/updater/class-github-release-updater.php';
if ( is_readable( $aap_updater_file ) ) {
	try {
		require_once $aap_updater_file;
		$aap_plugin_headers = get_file_data(
			__FILE__,
			array(
				'version'      => 'Version',
				'update_uri'   => 'Update URI',
				'requires'     => 'Requires at least',
				'requires_php' => 'Requires PHP',
			),
			'plugin'
		);

		new \CniWorks\AccessAnalyticsPlus\Updater\GitHub_Release_Updater(
			array(
				'type'          => 'plugin',
				'owner'         => 'cni-works',
				'repository'    => 'Access-Analytics-Plus',
				'slug'          => 'access-analytics-plus',
				'plugin_file'   => plugin_basename( __FILE__ ),
				'version'       => (string) $aap_plugin_headers['version'],
				'update_uri'    => (string) $aap_plugin_headers['update_uri'],
				'requires'      => (string) $aap_plugin_headers['requires'],
				'requires_php'  => (string) $aap_plugin_headers['requires_php'],
				'cache_hours'   => 12,
				'failure_hours' => 1,
				'timeout'       => 5,
				'include_prereleases' => true,
			)
		);
	} catch ( \Throwable $aap_updater_error ) {
		// Updater failures must never stop analytics, admin pages, or tracking.
	}
}

require_once AAP_PLUGIN_DIR . 'includes/class-database.php';
require_once AAP_PLUGIN_DIR . 'includes/class-capabilities.php';
require_once AAP_PLUGIN_DIR . 'includes/class-activator.php';
require_once AAP_PLUGIN_DIR . 'includes/class-cron.php';
require_once AAP_PLUGIN_DIR . 'includes/class-settings.php';
require_once AAP_PLUGIN_DIR . 'includes/class-tracker.php';
require_once AAP_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once AAP_PLUGIN_DIR . 'includes/class-sample-data.php';
require_once AAP_PLUGIN_DIR . 'includes/class-analytics.php';
require_once AAP_PLUGIN_DIR . 'includes/class-admin.php';
require_once AAP_PLUGIN_DIR . 'includes/class-dashboard-widget.php';
require_once AAP_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Activator::class, 'deactivate' ) );

Plugin::boot();
