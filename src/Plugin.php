<?php
/**
 * Plugin bootstrap.
 *
 * @package OrderChatzApi
 */

namespace OrderChatzApi;

use OrderChatzApi\Admin\SettingsPage;
use OrderChatzApi\Rest\ConversationsController;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		load_plugin_textdomain(
			'otzapi',
			false,
			dirname( OTZAPI_PLUGIN_BASENAME ) . '/languages'
		);

		( new SettingsPage() )->init();
		( new ConversationsController() )->init();
	}
}
