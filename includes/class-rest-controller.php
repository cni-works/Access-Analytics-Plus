<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use WP_REST_Request;

final class Rest_Controller {
	private const NAMESPACE = 'access-analytics-plus/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( self::class, 'prevent_caching' ), 10, 3 );
	}

	public static function prevent_caching( \WP_HTTP_Response $response, \WP_REST_Server $server, WP_REST_Request $request ): \WP_HTTP_Response {
		unset( $server );
		if ( str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
		}
		return $response;
	}

	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( Tracker::class, 'collect' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'visitor_id' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( self::class, 'is_uuid' ),
					),
					'session_id' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( self::class, 'is_uuid' ),
					),
					'path'       => array( 'required' => true, 'type' => 'string' ),
					'title'      => array( 'default' => '', 'type' => 'string' ),
					'referrer'   => array( 'default' => '', 'type' => 'string' ),
					'device_type' => array(
						'default' => '',
						'type'    => 'string',
						'enum'    => array( '', 'mobile', 'desktop', 'tablet', 'other' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/engagement',
			array(
				'methods'             => 'POST',
				'callback'            => array( Tracker::class, 'record_engagement' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'seconds' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 0,
						'maximum'  => 1800,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( Analytics::class, 'report' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::VIEW ),
				'args'                => array(
					'range' => array( 'default' => '7d', 'type' => 'string' ),
					'start' => array( 'default' => '', 'type' => 'string' ),
					'end'   => array( 'default' => '', 'type' => 'string' ),
					'context' => array( 'default' => 'full', 'type' => 'string', 'enum' => array( 'full', 'dashboard' ) ),
				),
			)
		);
	}

	public static function is_uuid( mixed $value, WP_REST_Request $request, string $param ): bool {
		unset( $request, $param );
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/i', $value );
	}
}
