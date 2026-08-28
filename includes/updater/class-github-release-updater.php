<?php
/**
 * GitHub Release updater for Access Analytics Plus.
 *
 * @package AccessAnalyticsPlus
 */

namespace CniWorks\AccessAnalyticsPlus\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates a public GitHub Release with WordPress plugin updates.
 */
final class GitHub_Release_Updater {
	/** GitHub REST API version. */
	const API_VERSION = '2022-11-28';

	/** @var array<string, mixed> */
	private $config = array();

	/** @var string */
	private $cache_key = '';

	/** @var bool */
	private $release_loaded = false;

	/** @var array<string, string>|null */
	private $release = null;

	/**
	 * Registers the updater when its configuration is safe and complete.
	 *
	 * @param array<string, mixed> $config Updater configuration.
	 */
	public function __construct( array $config ) {
		$this->config = wp_parse_args(
			$config,
			array(
				'type'          => '',
				'owner'         => '',
				'repository'    => '',
				'slug'          => '',
				'plugin_file'   => '',
				'stylesheet'    => '',
				'version'       => '',
				'update_uri'    => '',
				'requires'      => '6.1',
				'requires_php'  => '',
				'cache_hours'   => 12,
				'failure_hours' => 1,
				'timeout'       => 5,
			)
		);

		if ( ! $this->has_valid_configuration() ) {
			return;
		}

		$this->cache_key = 'cniworks_gh_release_' . md5(
			strtolower( $this->config['owner'] . '/' . $this->config['repository'] )
		);

		add_action( 'load-update-core.php', array( $this, 'maybe_clear_cache_for_forced_check' ), 5 );

		$hostname = wp_parse_url( $this->config['update_uri'], PHP_URL_HOST );
		if ( 'plugin' === $this->config['type'] ) {
			add_filter( 'update_plugins_' . $hostname, array( $this, 'filter_plugin_update' ), 10, 4 );
		} else {
			add_filter( 'update_themes_' . $hostname, array( $this, 'filter_theme_update' ), 10, 4 );
		}
	}

	/**
	 * Supplies update data for this plugin only.
	 *
	 * @param array|false          $update      Existing update data.
	 * @param array<string, mixed> $plugin_data Plugin headers.
	 * @param string               $plugin_file Plugin basename.
	 * @param string[]             $locales     Requested locales.
	 * @return array|false
	 */
	public function filter_plugin_update( $update, $plugin_data, $plugin_file, $locales ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( false !== $update || $plugin_file !== $this->config['plugin_file'] ) {
			return $update;
		}

		if ( ! $this->matches_update_uri( $plugin_data ) ) {
			return false;
		}

		$release = $this->get_release();
		if ( null === $release || ! version_compare( $release['version'], $this->config['version'], '>' ) ) {
			return $this->make_no_update_data( 'plugin' );
		}

		return $this->make_update_data( $release, 'plugin' );
	}

	/**
	 * Supplies update data for a theme when the reusable class is adapted later.
	 *
	 * @param array|false          $update           Existing update data.
	 * @param array<string, mixed> $theme_data       Theme headers.
	 * @param string               $theme_stylesheet Theme stylesheet.
	 * @param string[]             $locales          Requested locales.
	 * @return array|false
	 */
	public function filter_theme_update( $update, $theme_data, $theme_stylesheet, $locales ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( false !== $update || $theme_stylesheet !== $this->config['stylesheet'] ) {
			return $update;
		}

		if ( ! $this->matches_update_uri( $theme_data ) ) {
			return false;
		}

		$release = $this->get_release();
		if ( null === $release || ! version_compare( $release['version'], $this->config['version'], '>' ) ) {
			return $this->make_no_update_data( 'theme' );
		}

		return $this->make_update_data( $release, 'theme' );
	}

	/**
	 * Validates product configuration without affecting the plugin.
	 *
	 * @return bool
	 */
	private function has_valid_configuration() {
		if ( ! in_array( $this->config['type'], array( 'plugin', 'theme' ), true ) ) {
			return false;
		}

		foreach ( array( 'owner', 'repository', 'slug' ) as $key ) {
			if ( ! is_string( $this->config[ $key ] ) || ! preg_match( '/^[A-Za-z0-9._-]+$/', $this->config[ $key ] ) ) {
				return false;
			}
		}

		if ( ! $this->is_semantic_version( $this->config['version'] ) || ! is_string( $this->config['update_uri'] ) ) {
			return false;
		}

		$expected_update_uri = sprintf(
			'https://github.com/%s/%s',
			$this->config['owner'],
			$this->config['repository']
		);
		if ( untrailingslashit( $this->config['update_uri'] ) !== $expected_update_uri ) {
			return false;
		}

		if ( 'plugin' === $this->config['type'] ) {
			if ( ! is_string( $this->config['plugin_file'] ) || dirname( $this->config['plugin_file'] ) !== $this->config['slug'] ) {
				return false;
			}
		} elseif ( $this->config['stylesheet'] !== $this->config['slug'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Clears only this repository's cache during an explicit check-again request.
	 */
	public function maybe_clear_cache_for_forced_check() {
		if ( ! is_admin() || ! function_exists( 'current_user_can' ) ) {
			return;
		}

		$force_check = isset( $_GET['force-check'] ) && is_string( $_GET['force-check'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( $_GET['force-check'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$capability = 'plugin' === $this->config['type'] ? 'update_plugins' : 'update_themes';
		if ( '1' === $force_check && current_user_can( $capability ) ) {
			delete_site_transient( $this->cache_key );
		}
	}

	/**
	 * Returns the latest validated release, using positive and negative caches.
	 *
	 * @return array<string, string>|null
	 */
	private function get_release() {
		if ( $this->release_loaded ) {
			return $this->release;
		}

		$this->release_loaded = true;
		$cached               = get_site_transient( $this->cache_key );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			if ( 'success' === $cached['state'] && isset( $cached['release'] ) && is_array( $cached['release'] ) ) {
				$this->release = $cached['release'];
			}

			return $this->release;
		}

		$release = $this->request_release();
		if ( null === $release ) {
			set_site_transient(
				$this->cache_key,
				array( 'state' => 'failure' ),
				$this->hours_to_seconds( $this->config['failure_hours'], 1 )
			);

			return null;
		}

		$this->release = $release;
		set_site_transient(
			$this->cache_key,
			array(
				'state'   => 'success',
				'release' => $release,
			),
			$this->hours_to_seconds( $this->config['cache_hours'], 12 )
		);

		return $this->release;
	}

	/**
	 * Fetches and validates GitHub's latest published release.
	 *
	 * @return array<string, string>|null
	 */
	private function request_release() {
		$endpoint = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->config['owner'] ),
			rawurlencode( $this->config['repository'] )
		);
		$response = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout'     => $this->normalized_timeout(),
				'redirection' => 3,
				'headers'     => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => self::API_VERSION,
					'User-Agent'           => 'CniWorks-WordPress-Updater/1.0; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		if ( ! empty( $body['draft'] ) || ! empty( $body['prerelease'] ) || ! isset( $body['tag_name'] ) || ! is_string( $body['tag_name'] ) ) {
			return null;
		}

		$tag_match = array();
		if ( ! preg_match( '/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', $body['tag_name'], $tag_match ) ) {
			return null;
		}

		$release_version   = $tag_match[1] . '.' . $tag_match[2] . '.' . $tag_match[3];
		$expected_filename = $this->config['slug'] . '-' . $release_version . '.zip';
		$matching_assets   = array();
		if ( empty( $body['assets'] ) || ! is_array( $body['assets'] ) ) {
			return null;
		}

		foreach ( $body['assets'] as $asset ) {
			if (
				is_array( $asset ) &&
				isset( $asset['name'], $asset['state'], $asset['browser_download_url'] ) &&
				$expected_filename === $asset['name'] &&
				'uploaded' === $asset['state']
			) {
				$matching_assets[] = $asset;
			}
		}

		if ( 1 !== count( $matching_assets ) ) {
			return null;
		}

		$package_url = $matching_assets[0]['browser_download_url'];
		if ( ! $this->is_valid_github_url( $package_url ) ) {
			return null;
		}

		$release_url = isset( $body['html_url'] ) && $this->is_valid_github_url( $body['html_url'] )
			? $body['html_url']
			: $this->config['update_uri'];

		return array(
			'version' => $release_version,
			'package' => $package_url,
			'url'     => $release_url,
		);
	}

	/**
	 * Builds the update data WordPress expects.
	 *
	 * @param array<string, string> $release Validated release.
	 * @param string                $type    Product type.
	 * @return array<string, string>
	 */
	private function make_update_data( $release, $type ) {
		$update = array(
			'id'          => $this->config['update_uri'],
			'version'     => $release['version'],
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'requires'    => $this->config['requires'],
		);
		if ( ! empty( $this->config['requires_php'] ) ) {
			$update['requires_php'] = $this->config['requires_php'];
		}
		if ( 'plugin' === $type ) {
			$update['slug'] = $this->config['slug'];
		} else {
			$update['theme'] = $this->config['slug'];
		}

		return $update;
	}

	/**
	 * Builds safe metadata for WordPress's no_update collection.
	 *
	 * @param string $type Product type.
	 * @return array<string, string>
	 */
	private function make_no_update_data( $type ) {
		$update = array(
			'id'          => $this->config['update_uri'],
			'version'     => $this->config['version'],
			'new_version' => $this->config['version'],
			'url'         => $this->config['update_uri'],
			'requires'    => $this->config['requires'],
		);
		if ( ! empty( $this->config['requires_php'] ) ) {
			$update['requires_php'] = $this->config['requires_php'];
		}
		if ( 'plugin' === $type ) {
			$update['plugin'] = $this->config['plugin_file'];
			$update['slug']   = $this->config['slug'];
		} else {
			$update['theme'] = $this->config['slug'];
		}

		return $update;
	}

	/**
	 * Confirms that WordPress is asking about this exact product.
	 *
	 * @param array<string, mixed> $headers Plugin or theme headers.
	 * @return bool
	 */
	private function matches_update_uri( $headers ) {
		return isset( $headers['UpdateURI'] ) &&
			is_string( $headers['UpdateURI'] ) &&
			untrailingslashit( $headers['UpdateURI'] ) === untrailingslashit( $this->config['update_uri'] );
	}

	/**
	 * Allows only HTTPS URLs hosted by GitHub.
	 *
	 * @param mixed $url URL to validate.
	 * @return bool
	 */
	private function is_valid_github_url( $url ) {
		if ( ! is_string( $url ) || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) &&
			'github.com' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Validates the supported stable X.Y.Z format.
	 *
	 * @param mixed $version Version to validate.
	 * @return bool
	 */
	private function is_semantic_version( $version ) {
		return is_string( $version ) &&
			1 === preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', $version );
	}

	/**
	 * Normalizes a cache duration to seconds.
	 *
	 * @param mixed $hours   Configured hours.
	 * @param int   $default Default hours.
	 * @return int
	 */
	private function hours_to_seconds( $hours, $default ) {
		$hours = is_numeric( $hours ) ? (float) $hours : (float) $default;
		if ( $hours <= 0 ) {
			$hours = (float) $default;
		}

		return max( 60, (int) round( $hours * HOUR_IN_SECONDS ) );
	}

	/**
	 * Restricts the HTTP timeout to a safe range.
	 *
	 * @return int
	 */
	private function normalized_timeout() {
		$timeout = is_numeric( $this->config['timeout'] ) ? (int) $this->config['timeout'] : 5;

		return min( 5, max( 3, $timeout ) );
	}
}
