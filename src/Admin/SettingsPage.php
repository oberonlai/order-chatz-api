<?php
/**
 * API token settings under OrderChatz (Settings fallback).
 *
 * @package OrderChatzApi
 */

namespace OrderChatzApi\Admin;

use OrderChatzApi\Services\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Generate / rotate site token only.
 */
final class SettingsPage {

	public const SLUG = 'otz-api';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
	}

	/**
	 * Add submenu under OrderChatz, or Settings if parent missing.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$parent = $this->menu_parent();

		add_submenu_page(
			$parent,
			__( 'API', 'otzapi' ),
			__( 'API', 'otzapi' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Prefer OrderChatz menu; fall back to Settings.
	 *
	 * @return string
	 */
	private function menu_parent(): string {
		global $submenu;

		if ( is_array( $submenu ) && isset( $submenu['order-chatz'] ) ) {
			return 'order-chatz';
		}

		if ( function_exists( 'menu_page_url' ) && menu_page_url( 'order-chatz', false ) ) {
			return 'order-chatz';
		}

		return 'options-general.php';
	}

	/**
	 * Handle generate / rotate.
	 *
	 * @return void
	 */
	public function handle_post(): void {
		if ( ! isset( $_POST['otzapi_token_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'otzapi_rotate_token', 'otzapi_token_nonce' );

		if ( isset( $_POST['otzapi_rotate_token'] ) ) {
			Options::rotate_site_token();
			add_settings_error(
				'otzapi',
				'otzapi_rotated',
				__( 'API site token generated. Copy it now — it is shown below.', 'otzapi' ),
				'success'
			);
		}

		$this->redirect_after_save();
	}

	/**
	 * PRG redirect.
	 *
	 * @return void
	 */
	private function redirect_after_save(): void {
		set_transient( 'settings_errors', get_settings_errors( 'otzapi' ), 30 );

		$referer = wp_get_referer();
		if ( $referer ) {
			wp_safe_redirect( add_query_arg( 'settings-updated', '1', $referer ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::SLUG,
					'settings-updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'otzapi' ) );
		}

		$token = Options::get_site_token();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OrderChatz API', 'otzapi' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Site token grants access to conversation REST endpoints (list, detail, reply). You can also use a WordPress Application Password for a manage_options user.', 'otzapi' ); ?>
			</p>

			<?php settings_errors( 'otzapi' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site token', 'otzapi' ); ?></th>
						<td>
							<?php if ( '' === $token ) : ?>
								<p><em><?php esc_html_e( 'No token yet. Generate one below.', 'otzapi' ); ?></em></p>
							<?php else : ?>
								<code style="word-break:break-all;display:inline-block;max-width:100%;"><?php echo esc_html( $token ); ?></code>
								<p class="description">
									<?php esc_html_e( 'Send as header X-OTZ-API-Token or Authorization: Bearer <token>.', 'otzapi' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'REST namespace', 'otzapi' ); ?></th>
						<td><code>order-chatz/v1</code></td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="">
				<?php wp_nonce_field( 'otzapi_rotate_token', 'otzapi_token_nonce' ); ?>
				<p class="submit">
					<button type="submit" class="button button-primary" name="otzapi_rotate_token" value="1"
						onclick="return confirm('<?php echo esc_js( __( 'Generate a new token? The previous token will stop working immediately.', 'otzapi' ) ); ?>');">
						<?php
						echo esc_html(
							'' === $token
								? __( 'Generate token', 'otzapi' )
								: __( 'Regenerate token', 'otzapi' )
						);
						?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
