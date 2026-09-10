<?php
/**
 * Outbound reply via OrderChatz send/storage helpers (fallback: LINE + DB for text).
 *
 * @package OrderChatzApi
 */

namespace OrderChatzApi\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends text and media replies.
 *
 * Prefers OrderChatz `LineApiService` / `MessageStorageService` / `MessageQueryService`
 * when those classes exist. Text has a minimal LINE HTTP fallback; media requires
 * OrderChatz collaborators (URL-based only — no multipart upload in v1).
 */
final class ReplyService {

	public const MAX_MESSAGE_LENGTH = 5000;

	/**
	 * Allowed message types.
	 *
	 * @var list<string>
	 */
	public const TYPES = array( 'text', 'image', 'video', 'file', 'sticker' );

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
	 * Dispatch by message type.
	 *
	 * @param int                 $conversation_id otz_users.id.
	 * @param string              $type            text|image|video|file|sticker.
	 * @param array<string,mixed> $args            Type-specific + quote fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public function send( int $conversation_id, string $type, array $args = array() ) {
		$type = strtolower( trim( $type ) );
		if ( '' === $type ) {
			$type = 'text';
		}

		switch ( $type ) {
			case 'text':
				$message = '';
				if ( isset( $args['message'] ) ) {
					$message = (string) $args['message'];
				} elseif ( isset( $args['text'] ) ) {
					$message = (string) $args['text'];
				}
				return $this->send_text( $conversation_id, $message, $args );

			case 'image':
				return $this->send_image(
					$conversation_id,
					(string) ( $args['image_url'] ?? '' ),
					$args
				);

			case 'video':
				return $this->send_video(
					$conversation_id,
					(string) ( $args['video_url'] ?? '' ),
					(string) ( $args['video_name'] ?? '' ),
					$args
				);

			case 'file':
				return $this->send_file(
					$conversation_id,
					(string) ( $args['file_url'] ?? '' ),
					(string) ( $args['file_name'] ?? '' ),
					$args
				);

			case 'sticker':
				return $this->send_sticker(
					$conversation_id,
					(string) ( $args['package_id'] ?? '' ),
					(string) ( $args['sticker_id'] ?? '' ),
					$args
				);

			default:
				return new WP_Error(
					'otzapi_invalid_type',
					__( 'Unsupported message type. Use text, image, video, file, or sticker.', 'otzapi' ),
					array( 'status' => 400 )
				);
		}
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

		$ctx = $this->resolve_context( $conversation_id );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$quote_token       = $this->arg_string( $args, 'quote_token' );
		$quoted_message_id = $this->arg_string( $args, 'quoted_message_id' );
		$message_to_line   = $this->maybe_prefix_sender_name( $message );

		if ( $this->is_using_orderchatz() ) {
			return $this->send_text_via_orderchatz(
				$ctx,
				$message,
				$message_to_line,
				$quote_token,
				$quoted_message_id
			);
		}

		return $this->send_via_fallback(
			$ctx['conversation_id'],
			$ctx['line_user_id'],
			$message,
			$message_to_line,
			$quote_token,
			$quoted_message_id,
			$ctx['source_type'],
			$ctx['group_id']
		);
	}

	/**
	 * Send an image (https URL). Optional quote fields stored / attached when supported.
	 *
	 * @param int                 $conversation_id Conversation id.
	 * @param string              $image_url       HTTPS image URL.
	 * @param array<string,mixed> $args            quote_token, quoted_message_id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function send_image( int $conversation_id, string $image_url, array $args = array() ) {
		$image_url = esc_url_raw( trim( $image_url ) );
		if ( ! $this->is_https_url( $image_url ) ) {
			return new WP_Error(
				'otzapi_invalid_media',
				__( 'image_url is required and must be an HTTPS URL.', 'otzapi' ),
				array( 'status' => 400 )
			);
		}

		$ctx = $this->resolve_context( $conversation_id );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$need = $this->require_orderchatz_for_media();
		if ( is_wp_error( $need ) ) {
			return $need;
		}

		$quote_token       = $this->arg_string( $args, 'quote_token' );
		$quoted_message_id = $this->arg_string( $args, 'quoted_message_id' );

		$image_message = array(
			'type'               => 'image',
			'originalContentUrl' => $image_url,
			'previewImageUrl'    => $image_url,
		);
		if ( '' !== $quote_token ) {
			$image_message['quoteToken'] = $quote_token;
		}

		$send = $this->send_with_reply_fallback(
			$ctx,
			function ( string $reply_token ) use ( $image_message ) {
				return $this->line_api->sendReplyImageMessage( $reply_token, $image_message );
			},
			function () use ( $ctx, $image_message ) {
				return $this->line_api->sendPushImageMessage(
					$ctx['line_user_id'],
					$image_message,
					$ctx['source_type'],
					$ctx['group_id']
				);
			}
		);

		if ( is_wp_error( $send ) ) {
			return $send;
		}

		$this->storage->saveImageMessage(
			$ctx['line_user_id'],
			$image_url,
			$send['api_used'],
			$send['line_message_id'],
			$send['quote_token'],
			( '' !== $quoted_message_id ) ? $quoted_message_id : null,
			$ctx['source_type'],
			$ctx['group_id']
		);

		return $this->success_payload(
			$ctx['conversation_id'],
			$ctx['line_user_id'],
			$image_url,
			$send['api_used'],
			$send['line_message_id'],
			$send['quote_token'],
			'image'
		);
	}

	/**
	 * Send a file as LINE text summary + store as file (OrderChatz mirror).
	 *
	 * @param int                 $conversation_id Conversation id.
	 * @param string              $file_url        HTTPS file URL.
	 * @param string              $file_name       Display name.
	 * @param array<string,mixed> $args            quote fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public function send_file( int $conversation_id, string $file_url, string $file_name, array $args = array() ) {
		$file_url  = esc_url_raw( trim( $file_url ) );
		$file_name = sanitize_text_field( $file_name );

		if ( ! $this->is_https_url( $file_url ) || '' === $file_name ) {
			return new WP_Error(
				'otzapi_invalid_media',
				__( 'file_url (HTTPS) and file_name are required.', 'otzapi' ),
				array( 'status' => 400 )
			);
		}

		$ctx = $this->resolve_context( $conversation_id );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$need = $this->require_orderchatz_for_media();
		if ( is_wp_error( $need ) ) {
			return $need;
		}

		$quote_token       = $this->arg_string( $args, 'quote_token' );
		$quoted_message_id = $this->arg_string( $args, 'quoted_message_id' );
		$formatted_name    = $this->format_file_name( $file_name );

		$file_content = sprintf(
			"📁 %s\n\n🔗 下載連結：%s",
			$formatted_name,
			$file_url
		);

		$send = $this->send_text_line_with_fallback( $ctx, $file_content, $quote_token );
		if ( is_wp_error( $send ) ) {
			return $send;
		}

		$this->storage->saveFileMessage(
			$ctx['line_user_id'],
			$file_url,
			$file_name,
			$send['api_used'],
			$send['line_message_id'],
			$send['quote_token'],
			( '' !== $quoted_message_id ) ? $quoted_message_id : null,
			$ctx['source_type'],
			$ctx['group_id']
		);

		return $this->success_payload(
			$ctx['conversation_id'],
			$ctx['line_user_id'],
			$file_url,
			$send['api_used'],
			$send['line_message_id'],
			$send['quote_token'],
			'file',
			array(
				'file_name' => $file_name,
				'file_url'  => $file_url,
			)
		);
	}

	/**
	 * Send a video as LINE text summary + store as video (OrderChatz sendVideoMessage mirror).
	 *
	 * @param int                 $conversation_id Conversation id.
	 * @param string              $video_url       HTTPS video URL.
	 * @param string              $video_name      Display name.
	 * @param array<string,mixed> $args            quote fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public function send_video( int $conversation_id, string $video_url, string $video_name, array $args = array() ) {
		$video_url  = esc_url_raw( trim( $video_url ) );
		$video_name = sanitize_text_field( $video_name );

		if ( ! $this->is_https_url( $video_url ) || '' === $video_name ) {
			return new WP_Error(
				'otzapi_invalid_media',
				__( 'video_url (HTTPS) and video_name are required.', 'otzapi' ),
				array( 'status' => 400 )
			);
		}

		$ctx = $this->resolve_context( $conversation_id );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$need = $this->require_orderchatz_for_media();
		if ( is_wp_error( $need ) ) {
			return $need;
		}

		$quote_token       = $this->arg_string( $args, 'quote_token' );
		$quoted_message_id = $this->arg_string( $args, 'quoted_message_id' );
		$formatted_name    = $this->format_file_name( $video_name );

		$video_content = sprintf(
			"🎥 %s\n\n🔗 觀看連結：%s",
			$formatted_name,
			$video_url
		);

		$send = $this->send_text_line_with_fallback( $ctx, $video_content, $quote_token );
		if ( is_wp_error( $send ) ) {
			return $send;
		}

		$this->storage->saveVideoMessage(
			$ctx['line_user_id'],
			$video_url,
			$video_name,
			$send['api_used'],
			$send['line_message_id'],
			$send['quote_token'],
			( '' !== $quoted_message_id ) ? $quoted_message_id : null,
			$ctx['source_type'],
			$ctx['group_id']
		);

		return $this->success_payload(
			$ctx['conversation_id'],
			$ctx['line_user_id'],
			$video_url,
			$send['api_used'],
			$send['line_message_id'],
			$send['quote_token'],
			'video',
			array(
				'video_name' => $video_name,
				'video_url'  => $video_url,
			)
		);
	}

	/**
	 * Send a sticker.
	 *
	 * @param int                 $conversation_id Conversation id.
	 * @param string              $package_id      LINE package id.
	 * @param string              $sticker_id      LINE sticker id.
	 * @param array<string,mixed> $args            quote_token, quoted_message_id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function send_sticker( int $conversation_id, string $package_id, string $sticker_id, array $args = array() ) {
		$package_id = sanitize_text_field( $package_id );
		$sticker_id = sanitize_text_field( $sticker_id );

		if ( '' === $package_id || '' === $sticker_id ) {
			return new WP_Error(
				'otzapi_invalid_media',
				__( 'package_id and sticker_id are required for sticker messages.', 'otzapi' ),
				array( 'status' => 400 )
			);
		}

		$ctx = $this->resolve_context( $conversation_id );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$need = $this->require_orderchatz_for_media();
		if ( is_wp_error( $need ) ) {
			return $need;
		}

		$quote_token = $this->arg_string( $args, 'quote_token' );

		$send = $this->send_with_reply_fallback(
			$ctx,
			function ( string $reply_token ) use ( $package_id, $sticker_id, $quote_token ) {
				return $this->line_api->sendReplyStickerMessage(
					$reply_token,
					$package_id,
					$sticker_id,
					( '' !== $quote_token ) ? $quote_token : null
				);
			},
			function () use ( $ctx, $package_id, $sticker_id, $quote_token ) {
				return $this->line_api->sendPushStickerMessage(
					$ctx['line_user_id'],
					$package_id,
					$sticker_id,
					( '' !== $quote_token ) ? $quote_token : null,
					$ctx['source_type'],
					$ctx['group_id']
				);
			}
		);

		if ( is_wp_error( $send ) ) {
			return $send;
		}

		$this->storage->saveStickerMessage(
			$ctx['line_user_id'],
			$package_id,
			$sticker_id,
			$send['api_used'],
			$send['line_message_id'],
			( '' !== $quote_token ) ? $quote_token : null,
			$ctx['source_type'],
			$ctx['group_id']
		);

		return $this->success_payload(
			$ctx['conversation_id'],
			$ctx['line_user_id'],
			$package_id . ':' . $sticker_id,
			$send['api_used'],
			$send['line_message_id'],
			$send['quote_token'],
			'sticker',
			array(
				'package_id' => $package_id,
				'sticker_id' => $sticker_id,
			)
		);
	}

	/**
	 * Text send via OrderChatz helpers.
	 *
	 * @param array{conversation_id:int,line_user_id:string,source_type:string,group_id:string} $ctx Context.
	 * @param string                                                                            $message Stored body.
	 * @param string                                                                            $message_to_line LINE body.
	 * @param string                                                                            $quote_token Quote token.
	 * @param string                                                                            $quoted_message_id Quoted id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function send_text_via_orderchatz( array $ctx, string $message, string $message_to_line, string $quote_token, string $quoted_message_id ) {
		$send = $this->send_text_line_with_fallback( $ctx, $message_to_line, $quote_token );
		if ( is_wp_error( $send ) ) {
			return $send;
		}

		$this->storage->saveOutboundMessage(
			$ctx['line_user_id'],
			$message,
			$send['api_used'],
			$send['line_message_id'],
			$send['quote_token'],
			( '' !== $quoted_message_id ) ? $quoted_message_id : null,
			$ctx['source_type'],
			$ctx['group_id']
		);

		return $this->success_payload(
			$ctx['conversation_id'],
			$ctx['line_user_id'],
			$message,
			$send['api_used'],
			$send['line_message_id'],
			$send['quote_token'],
			'text'
		);
	}

	/**
	 * Reply-then-push for LINE text payloads.
	 *
	 * @param array{conversation_id:int,line_user_id:string,source_type:string,group_id:string} $ctx Context.
	 * @param string                                                                            $text Text to LINE.
	 * @param string                                                                            $quote_token Quote token.
	 * @return array{api_used:string,line_message_id:?string,quote_token:?string}|WP_Error
	 */
	private function send_text_line_with_fallback( array $ctx, string $text, string $quote_token ) {
		return $this->send_with_reply_fallback(
			$ctx,
			function ( string $reply_token ) use ( $text, $quote_token ) {
				return $this->line_api->sendReplyMessage(
					$reply_token,
					$text,
					( '' !== $quote_token ) ? $quote_token : null
				);
			},
			function () use ( $ctx, $text, $quote_token ) {
				return $this->line_api->sendPushMessage(
					$ctx['line_user_id'],
					$text,
					( '' !== $quote_token ) ? $quote_token : null,
					null,
					$ctx['source_type'],
					$ctx['group_id']
				);
			}
		);
	}

	/**
	 * Shared reply-token → reply API, else push; reply failure falls back to push.
	 *
	 * @param array{conversation_id:int,line_user_id:string,source_type:string,group_id:string} $ctx Context.
	 * @param callable                                                                          $reply_fn fn(string $reply_token): array.
	 * @param callable                                                                          $push_fn  fn(): array.
	 * @return array{api_used:string,line_message_id:?string,quote_token:?string}|WP_Error
	 */
	private function send_with_reply_fallback( array $ctx, callable $reply_fn, callable $push_fn ) {
		$reply_token = (string) $this->query->getLatestReplyToken( $ctx['line_user_id'], $ctx['group_id'] );
		$api_used    = 'push';
		$result      = null;

		if ( '' !== $reply_token ) {
			$reply_result = $reply_fn( $reply_token );
			if ( ! empty( $reply_result['success'] ) ) {
				$api_used = 'reply';
				$result   = $reply_result;
				if ( method_exists( $this->query, 'markReplyTokenAsUsed' ) ) {
					$this->query->markReplyTokenAsUsed( $reply_token );
				}
			} else {
				$result   = $push_fn();
				$api_used = 'push';
			}
		} else {
			$result   = $push_fn();
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

		return array(
			'api_used'        => $api_used,
			'line_message_id' => isset( $result['line_message_id'] ) ? (string) $result['line_message_id'] : null,
			'quote_token'     => isset( $result['quote_token'] ) ? (string) $result['quote_token'] : null,
		);
	}

	/**
	 * Minimal fallback when OrderChatz classes are not loadable (text only).
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

		return $this->success_payload( $conversation_id, $line_user_id, $message, $api_used, $line_message_id, $api_quote_token, 'text' );
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
	 * @param int                 $conversation_id Id.
	 * @param string              $line_user_id LINE id.
	 * @param string              $message_content Content / URL.
	 * @param string              $api_used API.
	 * @param string|null         $line_message_id LINE mid.
	 * @param string|null         $quote_token Quote token.
	 * @param string              $message_type Type.
	 * @param array<string,mixed> $extra Extra message fields.
	 * @return array<string,mixed>
	 */
	private function success_payload(
		int $conversation_id,
		string $line_user_id,
		string $message_content,
		string $api_used,
		?string $line_message_id,
		?string $quote_token,
		string $message_type = 'text',
		array $extra = array()
	): array {
		$sent_at = current_time( 'mysql' );

		$message = array_merge(
			array(
				'line_user_id'    => $line_user_id,
				'sender_type'     => 'ACCOUNT',
				'group_id'        => null,
				'sent_at'         => $sent_at,
				'message_type'    => $message_type,
				'message_content' => $message_content,
				'sender_name'     => $this->current_sender_name(),
				'line_message_id' => $line_message_id,
			),
			$extra
		);

		return array(
			'success'         => true,
			'api_used'        => $api_used,
			'line_message_id' => $line_message_id,
			'quote_token'     => $quote_token,
			'conversation_id' => $conversation_id,
			'message'         => $message,
		);
	}

	/**
	 * Resolve friend context.
	 *
	 * @param int $conversation_id otz_users.id.
	 * @return array{conversation_id:int,line_user_id:string,source_type:string,group_id:string}|WP_Error
	 */
	private function resolve_context( int $conversation_id ) {
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

		return array(
			'conversation_id' => $conversation_id,
			'line_user_id'    => $line_user_id,
			'source_type'     => (string) ( $friend['source_type'] ?? 'user' ),
			'group_id'        => '',
		);
	}

	/**
	 * Media requires OrderChatz collaborators.
	 *
	 * @return true|WP_Error
	 */
	private function require_orderchatz_for_media() {
		if ( $this->is_using_orderchatz() ) {
			return true;
		}

		return new WP_Error(
			'otzapi_send_unavailable',
			__( 'Media reply requires OrderChatz LineApiService / MessageStorageService / MessageQueryService.', 'otzapi' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * HTTPS URL check.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_https_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && isset( $parts['scheme'] ) && 'https' === strtolower( (string) $parts['scheme'] ) && ! empty( $parts['host'] );
	}

	/**
	 * Format file name for LINE (prefer OrderChatz helper).
	 *
	 * @param string $file_name Name.
	 * @return string
	 */
	private function format_file_name( string $file_name ): string {
		if ( is_object( $this->line_api ) && method_exists( $this->line_api, 'formatFileNameForLine' ) ) {
			return (string) $this->line_api->formatFileNameForLine( $file_name );
		}
		return $file_name;
	}

	/**
	 * Read string arg.
	 *
	 * @param array<string,mixed> $args Args.
	 * @param string              $key  Key.
	 * @return string
	 */
	private function arg_string( array $args, string $key ): string {
		return isset( $args[ $key ] ) ? (string) $args[ $key ] : '';
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
