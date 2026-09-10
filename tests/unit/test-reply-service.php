<?php
/**
 * Tests for ReplyService.
 *
 * @package OrderChatzApi
 */

use OrderChatzApi\Services\ReplyService;

/**
 * @group reply
 */
class Test_Reply_Service extends WP_UnitTestCase {

	/**
	 * Fake LINE API used by tests.
	 *
	 * @var object
	 */
	private $line_api;

	/**
	 * Fake storage.
	 *
	 * @var object
	 */
	private $storage;

	/**
	 * Fake query.
	 *
	 * @var object
	 */
	private $query;

	/**
	 * Set up fakes.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$table = $wpdb->prefix . 'otz_users';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				line_user_id varchar(64) NOT NULL DEFAULT '',
				wp_user_id bigint(20) unsigned DEFAULT 0,
				display_name varchar(255) DEFAULT '',
				avatar_url text,
				source_type varchar(32) DEFAULT 'user',
				group_id varchar(64) DEFAULT NULL,
				last_active datetime DEFAULT NULL,
				read_time datetime DEFAULT NULL,
				status varchar(32) DEFAULT 'active',
				notes text,
				PRIMARY KEY (id)
			) {$wpdb->get_charset_collate()}"
		);

		$this->line_api = new class() {
			public $push_calls  = array();
			public $reply_calls = array();
			public $push_result = array(
				'success'         => true,
				'line_message_id' => 'mid-push',
				'quote_token'     => 'qt-push',
			);
			public $reply_result = array(
				'success'         => true,
				'line_message_id' => 'mid-reply',
				'quote_token'     => 'qt-reply',
			);

			public function sendPushMessage( $line_user_id, $message, $quote_token = null, $quick_reply = null, $source_type = null, $group_id = null ) {
				$this->push_calls[] = compact( 'line_user_id', 'message', 'quote_token', 'source_type', 'group_id' );
				return $this->push_result;
			}

			public function sendReplyMessage( $reply_token, $message, $quote_token = null, $quick_reply = null ) {
				$this->reply_calls[] = compact( 'reply_token', 'message', 'quote_token' );
				return $this->reply_result;
			}
		};

		$this->storage = new class() {
			public $saved = array();

			public function saveOutboundMessage( $line_user_id, $message, $api_used, $line_message_id, $quote_token = null, $quoted_message_id = null, $source_type = '', $group_id = '' ) {
				$this->saved[] = compact( 'line_user_id', 'message', 'api_used', 'line_message_id', 'quote_token', 'quoted_message_id', 'source_type', 'group_id' );
			}
		};

		$this->query = new class() {
			public $token = '';
			public $marked = array();

			public function getLatestReplyToken( $line_user_id, $group_id = '' ) {
				return $this->token;
			}

			public function markReplyTokenAsUsed( $reply_token ) {
				$this->marked[] = $reply_token;
			}
		};
	}

	/**
	 * Insert a friend row.
	 *
	 * @param string $line_user_id LINE id.
	 * @return int
	 */
	private function insert_friend( string $line_user_id = 'Utest123' ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'otz_users',
			array(
				'line_user_id' => $line_user_id,
				'display_name' => 'Test Friend',
				'source_type'  => 'user',
				'status'       => 'active',
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Build service under test.
	 *
	 * @return ReplyService
	 */
	private function service(): ReplyService {
		return new ReplyService( $this->line_api, $this->storage, $this->query );
	}

	public function test_empty_message_returns_400(): void {
		$id = $this->insert_friend();
		$err = $this->service()->send_text( $id, '   ' );
		$this->assertWPError( $err );
		$this->assertSame( 'otzapi_invalid_message', $err->get_error_code() );
		$this->assertSame( 400, $err->get_error_data()['status'] );
	}

	public function test_missing_conversation_returns_404(): void {
		$err = $this->service()->send_text( 999999, 'hello' );
		$this->assertWPError( $err );
		$this->assertSame( 'otzapi_not_found', $err->get_error_code() );
		$this->assertSame( 404, $err->get_error_data()['status'] );
	}

	public function test_send_uses_push_when_no_reply_token(): void {
		$id = $this->insert_friend( 'Uabc' );
		$this->query->token = '';

		$result = $this->service()->send_text( $id, 'Hello LINE' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'push', $result['api_used'] );
		$this->assertSame( 'mid-push', $result['line_message_id'] );
		$this->assertCount( 1, $this->line_api->push_calls );
		$this->assertSame( 'Uabc', $this->line_api->push_calls[0]['line_user_id'] );
		$this->assertSame( 'Hello LINE', $this->line_api->push_calls[0]['message'] );
		$this->assertCount( 1, $this->storage->saved );
		$this->assertSame( 'push', $this->storage->saved[0]['api_used'] );
		$this->assertSame( 'Hello LINE', $this->storage->saved[0]['message'] );
	}

	public function test_send_prefers_reply_api_when_token_present(): void {
		$id = $this->insert_friend( 'Ureply' );
		$this->query->token = 'rtoken-1';

		$result = $this->service()->send_text( $id, 'Reply hi' );

		$this->assertIsArray( $result );
		$this->assertSame( 'reply', $result['api_used'] );
		$this->assertSame( 'mid-reply', $result['line_message_id'] );
		$this->assertCount( 1, $this->line_api->reply_calls );
		$this->assertSame( array( 'rtoken-1' ), $this->query->marked );
		$this->assertCount( 0, $this->line_api->push_calls );
	}

	public function test_reply_failure_falls_back_to_push(): void {
		$id = $this->insert_friend( 'Ufb' );
		$this->query->token = 'bad-token';
		$this->line_api->reply_result = array(
			'success' => false,
			'error'   => 'Reply token 已過期或無效',
		);

		$result = $this->service()->send_text( $id, 'fallback' );

		$this->assertIsArray( $result );
		$this->assertSame( 'push', $result['api_used'] );
		$this->assertCount( 1, $this->line_api->reply_calls );
		$this->assertCount( 1, $this->line_api->push_calls );
		$this->assertSame( array(), $this->query->marked );
	}

	public function test_line_failure_returns_502(): void {
		$id = $this->insert_friend();
		$this->query->token = '';
		$this->line_api->push_result = array(
			'success' => false,
			'error'   => 'quota exceeded',
		);

		$err = $this->service()->send_text( $id, 'nope' );

		$this->assertWPError( $err );
		$this->assertSame( 'otzapi_send_failed', $err->get_error_code() );
		$this->assertSame( 502, $err->get_error_data()['status'] );
		$this->assertCount( 0, $this->storage->saved );
	}

}
