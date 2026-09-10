<?php
/**
 * Site API token option helper.
 *
 * @package OrderChatzApi
 */

namespace OrderChatzApi\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes otzapi_site_token.
 */
final class Options {

	public const OPTION_KEY = 'otzapi_site_token';

	/**
	 * Get stored site token (plaintext).
	 *
	 * @return string
	 */
	public static function get_site_token(): string {
		$token = get_option( self::OPTION_KEY, '' );
		return is_string( $token ) ? $token : '';
	}

	/**
	 * Persist site token.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function set_site_token( string $token ): bool {
		return update_option( self::OPTION_KEY, $token, false );
	}

	/**
	 * Generate a new random token and store it.
	 *
	 * @return string New plaintext token.
	 */
	public static function rotate_site_token(): string {
		$token = wp_generate_password( 48, false, false );
		self::set_site_token( $token );
		return $token;
	}

	/**
	 * Delete stored token.
	 *
	 * @return bool
	 */
	public static function delete_site_token(): bool {
		return delete_option( self::OPTION_KEY );
	}
}
