<?php
/**
 * OrderChatz API
 *
 * @wordpress-plugin
 * Plugin Name:       OrderChatz API
 * Plugin URI:        https://wpbrewer.com
 * Description:       REST API for OrderChatz DM conversations (list, detail, reply). Requires OrderChatz.
 * Version:           1.2.1
 * Author:            WPBrewer
 * Author URI:        https://wpbrewer.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       otzapi
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Requires PHP:      8.0
 *
 * @package OrderChatzApi
 */

defined( 'ABSPATH' ) || exit;

define( 'OTZAPI_VERSION', '1.2.1' );
define( 'OTZAPI_PLUGIN_FILE', __FILE__ );
define( 'OTZAPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OTZAPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OTZAPI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'OrderChatzApi\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file     = OTZAPI_PLUGIN_DIR . 'src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Admin notice when OrderChatz is missing.
 *
 * @return void
 */
function otzapi_missing_orderchatz_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'OrderChatz API 需要先啟用 OrderChatz。', 'otzapi' );
	echo '</p></div>';
}

/**
 * Bootstrap after OrderChatz is available.
 *
 * @return void
 */
function otzapi_init_plugin(): void {
	if ( ! defined( 'OTZ_VERSION' ) ) {
		add_action( 'admin_notices', 'otzapi_missing_orderchatz_notice' );
		return;
	}

	OrderChatzApi\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'otzapi_init_plugin', 20 );
