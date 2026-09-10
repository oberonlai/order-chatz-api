<?php
/**
 * Uninstall cleanup.
 *
 * @package OrderChatzApi
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'otzapi_site_token' );
