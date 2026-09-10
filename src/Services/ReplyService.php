<?php
/**
 * Outbound reply via OrderChatz send/storage helpers (fallback: LINE + DB).
 *
 * @package OrderChatzApi
 */

namespace OrderChatzApi\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends text replies.
 *
 * Prefers OrderChatz `LineApiService` / `MessageStorageService` / `MessageQueryService`
 * when those classes exist. Otherwise uses a minimal safe fallback that talks to
 * LINE Messaging API with `otz_access_token` and writes the same monthly
 * `otz_messages_YYYY_MM` pattern OrderChatz uses — without modifying OrderChatz core.
 */
final class ReplyService {

	public const MAX_MESSAGE_LENGTH = 5000;

	/**
	 * LINE API collaborator (OrderChatz or fake).
	 *
	 * @var object|null
	 */
	private $line_api;

	/**
	 * Storage collaborator.
	 *
	 * @var object|null
	 */
	private $storage;

	/**
	 * Query collaborator.
	 *
	 * @var object|null
	 */
	private $query;

	/**
	 * Whether OrderChatz collaborators were resolved.
	 *
	 * @var bool
	 */
	private bool $using_orderchatz;

	/**
	 * Constructor.
	 *
	 * @param object|null $line_api     Optional LINE API service.
	 * @param object|null $storage      Optional message storage service.
	 * @param object|null $query        Optional message query service.
	 * @param bool        $auto_resolve When true and collaborators null, resolve OrderChatz classes.
	 */
	public function __construct( $line_api = null, $storage = null, $query = null, bool $auto_resolve = true ) {
		$this->using_orderchatz = false;

		if ( $auto_resolve && ( null === $line_api || null === $storage || null === $query ) ) {
			$resolved = self::resolve_orderchatz_collaborators();
			if ( null !== $resolved['line_api'] && null !== $resolved['storage'] && null !== $resolved['query'] ) {
				$line_api               = $line_api ?? $resolved['line_api'];
				$storage                = $storage ?? $resolved['storage'];
				$query                  = $query ?? $resolved['query'];
				$this->using_orderchatz = true;
			}
		} elseif ( null !== $line_api && null !== $storage && null !== $query ) {
			$this->using_orderchatz = true;
		}

		$this->line_api = $line_api;
		$this->storage  = $storage;
		$this->query    = $query;
	}

	/**
	 * Instantiate OrderChatz helpers when the core plugin is loaded.
	 *
	 * @return array{line_api:?object,storage:?object,query:?object}
	 */
	public static function resolve_orderchatz_collaborators(): array {
		$line_api = null;
		$storage  = null;
		$query    = null;

		if ( class_exists( '\OrderChatz\Ajax\Message\LineApiService' ) ) {
			$line_api = new \OrderChatz\Ajax\Message\LineApiService();
		}
		if ( class_exists( '\OrderChatz\Ajax\Message\MessageStorageService' ) ) {
			$storage = new \OrderChatz\Ajax\Message\MessageStorageService();
		}
		if ( class_exists( '\OrderChatz\Ajax\Message\MessageQueryService' ) ) {
			$query = new \OrderChatz\Ajax\Message\MessageQueryService();
		}

		return compact( 'line_api', 'storage', 'query' );
	}

	/**
	 * Whether OrderChatz send collaborators are wired.
	 *
	 * @return bool
	 */
	public function is_using_orderchatz(): bool {
		return $this->using_orderchatz
			&& is_object( $this->line_api )
			&& is_object( $this->storage )
			&& is_object( $this->query );
	}

	/**
	 * Send a text reply to a conversation (otz_users.id).
	 *
	 * @param int                 $conversation_id otz_users.id.
	 * @param string              $message         Message body.
	 * @param array<string,mixed> $args            Optional: quote_token, quoted_message_id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function send_text( int $conversation_id, string $message, array $args = array() ) {
		$message = trim( wp_unslash( $message ) );

		if ( '' === $message || mb_strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
			return new WP_Error(
				'otzapi_invalid_message',
				__( 'Message text is required and must be 5000 characters or fewer.', 'otzapi' ),
				array( 'status' => 400 )
			);
		}

		$friend = $this->get_friend( $conversation_id );
		if ( null === $friend ) {
			return new WP_Error(
				'otzapi_not_found',
				__( 'Conversation not found.', 'otzapi' ),
				array( 'status' => 404 )
			);
		}

		$line_user_id = (string) ( $friend['line_user_id'] ?? '' );
		if ( '' === $line_user_id ) {
			return new WP_Error(
				'otzapi_not_found',
				__( 'Conversation has no LINE user id.', 'otzapi' ),
				array( 'status' => 404 )
			);
		}

		$quote_token       = isset( $args['quote_token'] ) ? (string) $args['quote_token'] : '';
		$quoted_message_id = isset( $args['quoted_message_id'] ) ? (string) $args['quoted_message_id'] : '';
		$source_type       = (string) ( $friend['source_type'] ?? 'user' );
		$group_id          = '';

		$message_to_line = $this->maybe_prefix_sender_name( $message );

		if ( $this->is_using_orderchatz() ) {
			return $this->send_via_orderchatz(
				$conversation_id,
				$line_user_id,
				$message,
				$message_to_line,
				$quote_token,
				$quoted_message_id,
				$source_type,
				$group_id
			);
		}

		return $this->send_via_fallback(
			$conversation_id,
			$line_user_id,
			$message,
			$message_to_line,
			$quote_token,
			$quoted_message_id,
			$source_type,
			$group_id
		);
	}

	/**
	 * Send using OrderChatz public helpers.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $line_user_id    LINE user id.
	 * @param string $message         Stored message body.
	 * @param string $message_to_line Message sent to LINE.
	 * @param string $quote_token     Quote token.
	 * @param string $quoted_message_id Quoted message id.
	 * @param string $source_type     Source type.
	 * @param string $group_id        Group id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function send_via_orderchatz(
		int $conversation_id,
		string $line_user_id,
		string $message,
		string $message_to_line,
		string $quote_token,
		string $quoted_message_id,
		string $source_type,
		string $group_id
	) {
		$reply_token = (string) $this->query->getLatestReplyToken( $line_user_id, $group_id );
		$api_used    = 'push';
		$result      = null;

		if ( '' !== $reply_token ) {
			$reply_result = $this->line_api->sendReplyMessage( $reply_token, $message_to_line, ( '' !== $quote_token ) ? $quote_token : null );
			if ( ! empty( $reply_result['success'] ) ) {
				$api_used = 'reply';
				$result   = $reply_result;
				if ( method_exists( $this->query, 'markReplyTokenAsUsed' ) ) {
					$this->query->markReplyTokenAsUsed( $reply_token );
				}
			} else {
				$result   = $this->line_api->sendPushMessage( $line_user_id, $message_to_line, ( '' !== $quote_token ) ? $quote_token : null, null, $source_type, $group_id );
				$api_used = 'push';
			}
		} else {
			$result   = $this->line_api->sendPushMessage( $line_user_id, $message_to_line, ( '' !== $quote_token ) ? $quote_token : null, null, $source_type, $group_id );
			$api_used = 'push';
		}

		if ( empty( $result['success'] ) ) {
			$error  = isset( $result['error'] ) ? (string) $result['error'] : __( 'Failed to send message via LINE.', 'otzapi' );
			$status = ( false !== stripos( $error, 'Access Token' ) || false !== stripos( $error, '未設定' ) ) ? 400 : 502;
			return new WP_Error(
				'otzapi_send_failed',
				$error,
				array( 'status' => $status )
			);
		}

		$line_message_id = isset( $result['line_message_id'] ) ? (string) $result['line_message_id'] : null;
		$api_quote_token = isset( $result['quote_token'] ) ? (string) $result['quote_token'] : null;

		$this->storage->saveOutboundMessage(
			$line_user_id,
			$message,
			$api_used,
			$line_message_id,
			$api_quote_token,
			( '' !== $quoted_message_id ) ? $quoted_message_id : null,
			$source_type,
			$group_id
		);

		return $this->success_payload( $conversation_id, $line_user_id, $message, $api_used, $line_message_id, $api_quote_token );
	}

	/**
	 * Minimal fallback when OrderChatz classes are not loadable (e.g. unit test env).
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $line_user_id    LINE user id.
	 * @param string $message         Stored message body.
	 * @param string $message_to_line Message sent to LINE.
	 * @param string $quote_token     Quote token.
	 * @param string $quoted_message_id Quoted message id.
	 * @param string $source_type     Source type.
	 * @param string $group_id        Group id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function send_via_fallback(
		int $conversation_id,
		string $line_user_id,
		string $message,
		string $message_to_line,
		string $quote_token,
		string $quoted_message_id,
		string $source_type,
		string $group_id
	) {
		$access_token = (string) get_option( 'otz_access_token', '' );
		if ( '' === $access_token ) {
			return new WP_Error(
				'otzapi_missing_access_token',
				__( 'LINE Channel Access Token is not configured (otz_access_token).', 'otzapi' ),
				array( 'status' => 400 )
			);
		}

		// Prefer push in fallback (no reply-token lookup without OrderChatz tables helpers).
		$result = $this->line_http_push( $access_token, $line_user_id, $message_to_line, $quote_token, $source_type, $group_id );
		if ( empty( $result['success'] ) ) {
			$error = isset( $result['error'] ) ? (string) $result['error'] : __( 'Failed to send message via LINE.', 'otzapi' );
			return new WP_Error(
				'otzapi_send_failed',
				$error,
				array( 'status' => 502 )
			);
		}

		$line_message_id = isset( $result['line_message_id'] ) ? (string) $result['line_message_id'] : null;
		$api_quote_token = isset( $result['quote_token'] ) ? (string) $result['quote_token'] : null;
		$api_used        = 'push';

		$this->persist_outbound_fallback(
			$line_user_id,
			$message,
			$api_used,
			$line_message_id,
			$api_quote_token,
			$quoted_message_id,
			$source_type,
			$group_id
		);

		return $this->success_payload( $conversation_id, $line_user_id, $message, $api_used, $line_message_id, $api_quote_token );
	}

	/**
	 * LINE Messaging API push (fallback path).
	 *
	 * @param string $access_token Channel access token.
	 * @param string $line_user_id LINE user id.
	 * @param string $message      Text.
	 * @param string $quote_token  Optional quote token.
	 * @param string $source_type  Source type.
	 * @param string $group_id     Group id.
	 * @return array<string,mixed>
	 */
	private function line_http_push(
		string $access_token,
		string $line_user_id,
		string $message,
		string $quote_token,
		string $source_type,
		string $group_id
	): array {
		$url     = 'https://api.line.me/v2/bot/message/push';
		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $access_token,
		);

		$message_data = array(
			'type' => 'text',
			'text' => $message,
		);
		if ( '' !== $quote_token ) {
			$message_data['quoteToken'] = $quote_token;
		}

		$to = $line_user_id;
		if ( 'group' === $source_type && '' !== $group_id ) {
			$to = $group_id;
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'to'       => $to,
						'messages' => array( $message_data ),
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 === $code ) {
			$line_message_id = null;
			$qt              = null;
			if ( is_array( $data ) && ! empty( $data['sentMessages'][0]['id'] ) ) {
				$line_message_id = (string) $data['sentMessages'][0]['id'];
			}
			if ( is_array( $data ) && ! empty( $data['sentMessages'][0]['quoteToken'] ) ) {
				$qt = (string) $data['sentMessages'][0]['quoteToken'];
			}
			return array(
				'success'         => true,
				'line_message_id' => $line_message_id,
				'quote_token'     => $qt,
			);
		}

		$error = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'HTTP Error: ' . $code;
		return array(
			'success' => false,
			'error'   => $error,
		);
	}

	/**
	 * Persist outbound text into current month partition (OrderChatz schema).
	 *
	 * @param string      $line_user_id LINE user id.
	 * @param string      $message Message.
	 * @param string      $api_used API used.
	 * @param string|null $line_message_id LINE message id.
	 * @param string|null $quote_token Quote token.
	 * @param string      $quoted_message_id Quoted id.
	 * @param string      $source_type Source.
	 * @param string      $group_id Group.
	 * @return void
	 */
	private function persist_outbound_fallback(
		string $line_user_id,
		string $message,
		string $api_used,
		?string $line_message_id,
		?string $quote_token,
		string $quoted_message_id,
		string $source_type,
		string $group_id
	): void {
		global $wpdb;

		$month = gmdate( 'Y_m' );
		$table = $wpdb->prefix . 'otz_messages_' . $month;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			// Soft-skip if partition missing — OrderChatz normally creates it.
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'event_id'          => 'otzapi_text_' . uniqid( '', true ),
				'line_user_id'      => $line_user_id,
				'source_type'       => '' !== $source_type ? $source_type : 'user',
				'sender_type'       => 'ACCOUNT',
				'sender_name'       => $this->current_sender_name(),
				'group_id'          => '' !== $group_id ? $group_id : null,
				'sent_date'         => current_time( 'Y-m-d' ),
				'sent_time'         => current_time( 'H:i:s' ),
				'message_type'      => 'text',
				'message_content'   => $message,
				'reply_token'       => 'NULL',
				'quoted_message_id' => '' !== $quoted_message_id ? $quoted_message_id : null,
				'quote_token'       => $quote_token,
				'line_message_id'   => $line_message_id,
				'raw_payload'       => wp_json_encode(
					array(
						'api_used'      => $api_used,
						'sent_via'      => 'order-chatz-api',
						'admin_user_id' => get_current_user_id(),
					)
				),
				'created_by'        => get_current_user_id(),
				'created_at'        => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Success response shape.
	 *
	 * @param int         $conversation_id Id.
	 * @param string      $line_user_id LINE id.
	 * @param string      $message Text.
	 * @param string      $api_used API.
	 * @param string|null $line_message_id LINE mid.
	 * @param string|null $quote_token Quote token.
	 * @return array<string,mixed>
	 */
	private function success_payload(
		int $conversation_id,
		string $line_user_id,
		string $message,
		string $api_used,
		?string $line_message_id,
		?string $quote_token
	): array {
		$sent_at = current_time( 'mysql' );

		return array(
			'success'         => true,
			'api_used'        => $api_used,
			'line_message_id' => $line_message_id,
			'quote_token'     => $quote_token,
			'conversation_id' => $conversation_id,
			'message'         => array(
				'line_user_id'    => $line_user_id,
				'sender_type'     => 'ACCOUNT',
				'group_id'        => null,
				'sent_at'         => $sent_at,
				'message_type'    => 'text',
				'message_content' => $message,
				'sender_name'     => $this->current_sender_name(),
				'line_message_id' => $line_message_id,
			),
		);
	}

	/**
	 * Load friend row by id.
	 *
	 * @param int $id otz_users.id.
	 * @return array<string,mixed>|null
	 */
	private function get_friend( int $id ): ?array {
		global $wpdb;

		if ( $id < 1 ) {
			return null;
		}

		$table = $wpdb->prefix . 'otz_users';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from trusted prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Mirror OrderChatz otz_show_sender_name when a WP user is logged in.
	 *
	 * @param string $message Original message.
	 * @return string
	 */
	private function maybe_prefix_sender_name( string $message ): string {
		if ( ! get_option( 'otz_show_sender_name', false ) ) {
			return $message;
		}

		$name = $this->current_sender_name();
		if ( '' === $name || 'OrderChatz Bot' === $name ) {
			return $message;
		}

		return $name . ': ' . $message;
	}

	/**
	 * Display name for outbound sender.
	 *
	 * @return string
	 */
	private function current_sender_name(): string {
		$user = wp_get_current_user();
		if ( $user instanceof \WP_User && $user->exists() && '' !== (string) $user->display_name ) {
			return (string) $user->display_name;
		}

		return 'OrderChatz Bot';
	}
}
