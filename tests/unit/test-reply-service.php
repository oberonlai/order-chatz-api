<?php
/**
 * Tests for ReplyService (text + media + quote).
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
			public $push_calls         = array();
			public $reply_calls        = array();
			public $push_image_calls   = array();
			public $reply_image_calls  = array();
			public $push_sticker_calls = array();
			public $reply_sticker_calls = array();
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
			public $push_image_result = array(
				'success'         => true,
				'line_message_id' => 'mid-img-push',
				'quote_token'     => 'qt-img',
			);
			public $reply_image_result = array(
				'success'         => true,
				'line_message_id' => 'mid-img-reply',
				'quote_token'     => 'qt-img-r',
			);
			public $push_sticker_result = array(
				'success'         => true,
				'line_message_id' => 'mid-stk',
				'quote_token'     => 'qt-stk',
			);
			public $reply_sticker_result = array(
				'success'         => true,
				'line_message_id' => 'mid-stk-r',
				'quote_token'     => 'qt-stk-r',
			);

			public function sendPushMessage( $line_user_id, $message, $quote_token = null, $quick_reply = null, $source_type = null, $group_id = null ) {
				$this->push_calls[] = compact( 'line_user_id', 'message', 'quote_token', 'source_type', 'group_id' );
				return $this->push_result;
			}

			public function sendReplyMessage( $reply_token, $message, $quote_token = null, $quick_reply = null ) {
				$this->reply_calls[] = compact( 'reply_token', 'message', 'quote_token' );
				return $this->reply_result;
			}

			public function sendPushImageMessage( $line_user_id, $image_message, $source_type = null, $group_id = null ) {
				$this->push_image_calls[] = compact( 'line_user_id', 'image_message', 'source_type', 'group_id' );
				return $this->push_image_result;
			}

			public function sendReplyImageMessage( $reply_token, $image_message ) {
				$this->reply_image_calls[] = compact( 'reply_token', 'image_message' );
				return $this->reply_image_result;
			}

			public function sendPushStickerMessage( $line_user_id, $package_id, $sticker_id, $quote_token = null, $source_type = null, $group_id = null ) {
				$this->push_sticker_calls[] = compact( 'line_user_id', 'package_id', 'sticker_id', 'quote_token', 'source_type', 'group_id' );
				return $this->push_sticker_result;
			}

			public function sendReplyStickerMessage( $reply_token, $package_id, $sticker_id, $quote_token = null ) {
				$this->reply_sticker_calls[] = compact( 'reply_token', 'package_id', 'sticker_id', 'quote_token' );
				return $this->reply_sticker_result;
			}

			public function formatFileNameForLine( $file_name ) {
				return (string) $file_name;
			}
		};

		$this->storage = new class() {
			public $saved         = array();
			public $saved_images  = array();
			public $saved_files   = array();
			public $saved_videos  = array();
			public $saved_stickers = array();

			public function saveOutboundMessage( $line_user_id, $message, $api_used, $line_message_id, $quote_token = null, $quoted_message_id = null, $source_type = '', $group_id = '' ) {
				$this->saved[] = compact( 'line_user_id', 'message', 'api_used', 'line_message_id', 'quote_token', 'quoted_message_id', 'source_type', 'group_id' );
			}

			public function saveImageMessage( $line_user_id, $image_url, $api_used, $line_message_id = null, $quote_token = null, $quoted_message_id = null, $source_type = '', $group_id = '' ) {
				$this->saved_images[] = compact( 'line_user_id', 'image_url', 'api_used', 'line_message_id', 'quote_token', 'quoted_message_id', 'source_type', 'group_id' );
			}

			public function saveFileMessage( $line_user_id, $file_url, $file_name, $api_used, $line_message_id = null, $quote_token = null, $quoted_message_id = null, $source_type = '', $group_id = '' ) {
				$this->saved_files[] = compact( 'line_user_id', 'file_url', 'file_name', 'api_used', 'line_message_id', 'quote_token', 'quoted_message_id', 'source_type', 'group_id' );
			}

			public function saveVideoMessage( $line_user_id, $video_url, $video_name, $api_used, $line_message_id = null, $quote_token = null, $quoted_message_id = null, $source_type = '', $group_id = '' ) {
				$this->saved_videos[] = compact( 'line_user_id', 'video_url', 'video_name', 'api_used', 'line_message_id', 'quote_token', 'quoted_message_id', 'source_type', 'group_id' );
			}

			public function saveStickerMessage( $line_user_id, $package_id, $sticker_id, $api_used, $line_message_id = null, $quote_token = null, $source_type = '', $group_id = '' ) {
				$this->saved_stickers[] = compact( 'line_user_id', 'package_id', 'sticker_id', 'api_used', 'line_message_id', 'quote_token', 'source_type', 'group_id' );
			}
		};

		$this->query = new class() {
			public $token  = '';
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
		$id  = $this->insert_friend();
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
		$id                 = $this->insert_friend( 'Uabc' );
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
		$id                 = $this->insert_friend( 'Ureply' );
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
		$id                           = $this->insert_friend( 'Ufb' );
		$this->query->token           = 'bad-token';
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
		$id                          = $this->insert_friend();
		$this->query->token          = '';
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

	public function test_quote_on_text_passes_quote_token_and_quoted_id(): void {
		$id                 = $this->insert_friend( 'Uquote' );
		$this->query->token = '';

		$result = $this->service()->send_text(
			$id,
			'quoted reply',
			array(
				'quote_token'       => 'qt-1',
				'quoted_message_id' => 'mid-9',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'qt-1', $this->line_api->push_calls[0]['quote_token'] );
		$this->assertSame( 'mid-9', $this->storage->saved[0]['quoted_message_id'] );
	}

	public function test_image_happy_path_push(): void {
		$id                 = $this->insert_friend( 'Uimg' );
		$this->query->token = '';
		$url                = 'https://cdn.example.com/a.jpg';

		$result = $this->service()->send_image( $id, $url );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'push', $result['api_used'] );
		$this->assertSame( 'image', $result['message']['message_type'] );
		$this->assertCount( 1, $this->line_api->push_image_calls );
		$msg = $this->line_api->push_image_calls[0]['image_message'];
		$this->assertSame( 'image', $msg['type'] );
		$this->assertSame( $url, $msg['originalContentUrl'] );
		$this->assertSame( $url, $msg['previewImageUrl'] );
		$this->assertCount( 1, $this->storage->saved_images );
		$this->assertSame( $url, $this->storage->saved_images[0]['image_url'] );
	}

	public function test_file_happy_path_sends_text_summary(): void {
		$id                 = $this->insert_friend( 'Ufile' );
		$this->query->token = '';
		$url                = 'https://cdn.example.com/doc.pdf';

		$result = $this->service()->send_file( $id, $url, 'doc.pdf' );

		$this->assertIsArray( $result );
		$this->assertSame( 'file', $result['message']['message_type'] );
		$this->assertCount( 1, $this->line_api->push_calls );
		$this->assertStringContainsString( 'doc.pdf', $this->line_api->push_calls[0]['message'] );
		$this->assertStringContainsString( $url, $this->line_api->push_calls[0]['message'] );
		$this->assertCount( 1, $this->storage->saved_files );
		$this->assertSame( $url, $this->storage->saved_files[0]['file_url'] );
		$this->assertSame( 'doc.pdf', $this->storage->saved_files[0]['file_name'] );
	}

	public function test_missing_image_url_returns_400(): void {
		$id  = $this->insert_friend();
		$err = $this->service()->send_image( $id, '' );
		$this->assertWPError( $err );
		$this->assertSame( 'otzapi_invalid_media', $err->get_error_code() );
		$this->assertSame( 400, $err->get_error_data()['status'] );
		$this->assertCount( 0, $this->storage->saved_images );
	}

	public function test_non_https_image_url_returns_400(): void {
		$id  = $this->insert_friend();
		$err = $this->service()->send_image( $id, 'http://insecure.example.com/a.jpg' );
		$this->assertWPError( $err );
		$this->assertSame( 'otzapi_invalid_media', $err->get_error_code() );
		$this->assertSame( 400, $err->get_error_data()['status'] );
	}

	public function test_image_line_failure_returns_502(): void {
		$id                                = $this->insert_friend();
		$this->query->token                = '';
		$this->line_api->push_image_result = array(
			'success' => false,
			'error'   => 'image rejected',
		);

		$err = $this->service()->send_image( $id, 'https://cdn.example.com/a.jpg' );

		$this->assertWPError( $err );
		$this->assertSame( 'otzapi_send_failed', $err->get_error_code() );
		$this->assertSame( 502, $err->get_error_data()['status'] );
		$this->assertCount( 0, $this->storage->saved_images );
	}

	public function test_unknown_type_returns_400(): void {
		$id  = $this->insert_friend();
		$err = $this->service()->send( $id, 'foo', array() );
		$this->assertWPError( $err );
		$this->assertSame( 'otzapi_invalid_type', $err->get_error_code() );
		$this->assertSame( 400, $err->get_error_data()['status'] );
	}

	public function test_video_happy_path(): void {
		$id                 = $this->insert_friend( 'Uvid' );
		$this->query->token = '';
		$url                = 'https://cdn.example.com/clip.mp4';

		$result = $this->service()->send_video( $id, $url, 'clip.mp4' );

		$this->assertIsArray( $result );
		$this->assertSame( 'video', $result['message']['message_type'] );
		$this->assertCount( 1, $this->line_api->push_calls );
		$this->assertStringContainsString( $url, $this->line_api->push_calls[0]['message'] );
		$this->assertCount( 1, $this->storage->saved_videos );
		$this->assertSame( 'clip.mp4', $this->storage->saved_videos[0]['video_name'] );
	}

	public function test_sticker_happy_path_with_quote(): void {
		$id                 = $this->insert_friend( 'Ustk' );
		$this->query->token = '';

		$result = $this->service()->send_sticker(
			$id,
			'1',
			'2',
			array( 'quote_token' => 'qt-stk' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'sticker', $result['message']['message_type'] );
		$this->assertCount( 1, $this->line_api->push_sticker_calls );
		$this->assertSame( '1', $this->line_api->push_sticker_calls[0]['package_id'] );
		$this->assertSame( '2', $this->line_api->push_sticker_calls[0]['sticker_id'] );
		$this->assertSame( 'qt-stk', $this->line_api->push_sticker_calls[0]['quote_token'] );
		$this->assertCount( 1, $this->storage->saved_stickers );
	}

	public function test_image_with_quoted_message_id_stored(): void {
		$id                 = $this->insert_friend( 'Uimgq' );
		$this->query->token = '';

		$result = $this->service()->send_image(
			$id,
			'https://cdn.example.com/b.jpg',
			array( 'quoted_message_id' => 'mid-quoted' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'mid-quoted', $this->storage->saved_images[0]['quoted_message_id'] );
	}
}
