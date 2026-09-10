<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package OrderChatzApi
 */

// Load Composer autoloader + PHPUnit Polyfills for the WP testing suite.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define(
		'WP_TESTS_PHPUNIT_POLYFILLS_PATH',
		dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills'
	);
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Allow env override of polyfills path.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	// phpcs:ignore Generic.PHP.ForbiddenFunctions -- redefine only when env set before WP suite.
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 *
 * Define OTZ_VERSION so the soft-dependency check passes without OrderChatz core.
 */
function otzapi_manually_load_plugin(): void {
	if ( ! defined( 'OTZ_VERSION' ) ) {
		define( 'OTZ_VERSION', 'test' );
	}
	require dirname( __DIR__ ) . '/order-chatz-api.php';
}

tests_add_filter( 'muplugins_loaded', 'otzapi_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
