<?php
/**
 * POST /conversations/{id}/messages acceptance tests.
 *
 * @package OrderChatzApi
 */

/**
 * Reply-message REST scenarios.
 */
class Test_Reply_Message extends WP_UnitTestCase {

	/**
	 * Site token used in tests.
	 *
	 * @var string
	 */
	private string $token = 'test-site-token-reply-message-1234567890';

	/**
	 * Friend id created in setUp.
	 *
	 * @var int
	 */
	private int $friend_id = 0;

	/**
	 * LINE user id for the friend.
	 *
	 * @var string
	 */
	private string $line_user_id = 'U_test_line_user_reply';

	/**
	 * Create tables + friend + token.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		update_option( 'otzapi_site_token', $this->token );
		update_option( 'otz_access_token', 'test-line-channel-token' );

		$users = $wpdb->prefix . 'otz_users';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$users} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				line_user_id varchar(100) NOT NULL,
				wp_user_id bigint(20) unsigned DEFAULT 0,
				display_name varchar(255) DEFAULT '',
				avatar_url text,
				source_type varchar(20) DEFAULT 'user',
				group_id varchar(64) DEFAULT NULL,
				status varchar(20) DEFAULT 'active',
				last_active datetime DEFAULT NULL,
				read_time datetime DEFAULT NULL,
				notes text,
				PRIMARY KEY (id)
			) {$wpdb->get_charset_collate()}"
		);

		$month = gmdate( 'Y_m' );
		$msgs  = $wpdb->prefix . 'otz_messages_' . $month;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$msgs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id varchar(100) NOT NULL,
				line_user_id varchar(100) NOT NULL,
				source_type varchar(20) NOT NULL DEFAULT 'user',
				sender_type varchar(20) NOT NULL DEFAULT 'ACCOUNT',
				sender_name varchar(255) DEFAULT '',
				group_id varchar(64) DEFAULT NULL,
				sent_date date NOT NULL,
				sent_time time NOT NULL,
				message_type varchar(50) NOT NULL DEFAULT 'text',
				message_content longtext,
				reply_token varchar(255) DEFAULT NULL,
				quote_token varchar(255) DEFAULT NULL,
				quoted_message_id varchar(255) DEFAULT NULL,
				line_message_id varchar(255) DEFAULT NULL,
				raw_payload longtext,
				created_by bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_id (event_id)
			) {$wpdb->get_charset_collate()}"
		);

		$wpdb->insert(
			$users,
			array(
				'line_user_id' => $this->line_user_id,
				'display_name' => 'Test Friend',
				'source_type'  => 'user',
				'status'       => 'active',
				'last_active'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		$this->friend_id = (int) $wpdb->insert_id;

		do_action( 'rest_api_init' );
	}

	/**
	 * Clean token.
	 */
	public function tear_down(): void {
		delete_option( 'otzapi_site_token' );
		delete_option( 'otz_access_token' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Build REST request.
	 *
	 * @param int                  $id      Friend id.
	 * @param array<string,mixed>  $body    JSON body.
	 * @param array<string,string> $headers Extra headers.
	 * @return WP_REST_Request
	 */
	private function make_request( int $id, array $body = array(), array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/order-chatz/v1/conversations/' . $id . '/messages' );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	/**
	 * Mock successful LINE Messaging API HTTP.
	 *
	 * @param string $api_path Path fragment: reply|push.
	 * @return void
	 */
	private function mock_line_success( string $api_path = 'push' ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $api_path ) {
				if ( false === strpos( $url, 'api.line.me/v2/bot/message/' . $api_path ) ) {
					return $preempt;
				}
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'sentMessages' => array(
								array(
									'id'         => 'mid-123',
									'quoteToken' => 'qt-abc',
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Mock LINE API failure.
	 *
	 * @return void
	 */
	private function mock_line_failure(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false === strpos( $url, 'api.line.me' ) ) {
					return $preempt;
				}
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'message' => 'The request body has 1 error(s)',
						)
					),
					'response' => array(
						'code'    => 400,
						'message' => 'Bad Request',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Scenario: Unauthorized request is rejected.
	 */
	public function test_unauthorized_is_rejected(): void {
		$request  = $this->make_request( $this->friend_id, array( 'message' => 'hi' ) );
		$response = rest_do_request( $request );
		$this->assertTrue( in_array( $response->get_status(), array( 401, 403 ), true ) );
	}

	/**
	 * Scenario: Site token may send.
	 */
	public function test_site_token_can_send(): void {
		$this->mock_line_success( 'push' );

		$request = $this->make_request(
			$this->friend_id,
			array( 'message' => 'hello' ),
			array( 'X-OTZ-API-Token' => $this->token )
		);
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'push', $data['api_used'] );
		$this->assertArrayHasKey( 'message', $data );
		$this->assertSame( 'hello', $data['message']['message_content'] );
	}

	/**
	 * Scenario: Application Password / manage_options may send.
	 */
	public function test_admin_can_send(): void {
		$this->mock_line_success( 'push' );
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request  = $this->make_request( $this->friend_id, array( 'message' => 'from admin' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'push', $data['api_used'] );
	}

	/**
	 * Scenario: Missing conversation returns 404.
	 */
	public function test_missing_conversation_404(): void {
		$request = $this->make_request(
			999999,
			array( 'message' => 'hello' ),
			array( 'X-OTZ-API-Token' => $this->token )
		);
		$response = rest_do_request( $request );
		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'otzapi_not_found', $data['code'] );
	}

	/**
	 * Scenario: Empty message is rejected.
	 */
	public function test_empty_message_400(): void {
		$request = $this->make_request(
			$this->friend_id,
			array( 'message' => '   ' ),
			array( 'X-OTZ-API-Token' => $this->token )
		);
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'otzapi_invalid_message', $data['code'] );
	}

	/**
	 * Scenario: Oversized message is rejected.
	 */
	public function test_oversized_message_400(): void {
		$request = $this->make_request(
			$this->friend_id,
			array( 'message' => str_repeat( 'a', 5001 ) ),
			array( 'X-OTZ-API-Token' => $this->token )
		);
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'otzapi_invalid_message', $data['code'] );
	}

	/**
	 * Scenario: LINE failure never claims success.
	 */
	public function test_line_failure_returns_error(): void {
		$this->mock_line_failure();

		$request = $this->make_request(
			$this->friend_id,
			array( 'message' => 'will fail' ),
			array( 'X-OTZ-API-Token' => $this->token )
		);
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 400, 502 ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertStringContainsString( 'otzapi_', $data['code'] );
	}

	/**
	 * Scenario: Missing access token fails cleanly on fallback path.
	 */
	public function test_missing_access_token_400(): void {
		delete_option( 'otz_access_token' );

		$request = $this->make_request(
			$this->friend_id,
			array( 'message' => 'no token' ),
			array( 'X-OTZ-API-Token' => $this->token )
		);
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
	}
}
