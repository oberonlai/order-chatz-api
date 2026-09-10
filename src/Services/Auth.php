<?php
/**
 * REST auth: Application Password (manage_options) or site token.
 *
 * @package OrderChatzApi
 */

namespace OrderChatzApi\Services;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Permission checks for conversation REST endpoints.
 */
final class Auth {

	/**
	 * Allow if logged-in manage_options OR valid site token header (read + reply).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permission_callback( WP_REST_Request $request ): bool {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		return self::token_matches( self::extract_token( $request ) );
	}

	/**
	 * Extract token from X-OTZ-API-Token or Authorization Bearer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	public static function extract_token( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'x-otz-api-token' );
		if ( '' !== $header ) {
			return trim( $header );
		}

		$auth = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^\s*Bearer\s+(.+)\s*$/i', $auth, $m ) ) {
			return trim( $m[1] );
		}

		return '';
	}

	/**
	 * Compare provided token to stored option (timing-safe).
	 *
	 * @param string $provided Token from request.
	 * @return bool
	 */
	public static function token_matches( string $provided ): bool {
		if ( '' === $provided ) {
			return false;
		}

		$stored = Options::get_site_token();
		if ( '' === $stored ) {
			return false;
		}

		return hash_equals( $stored, $provided );
	}
}
