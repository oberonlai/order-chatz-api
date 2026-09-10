<?php
/**
 * REST: conversations list + detail + reply.
 *
 * @package OrderChatzApi
 */

namespace OrderChatzApi\Rest;

use OrderChatzApi\Services\Auth;
use OrderChatzApi\Services\ConversationRepository;
use OrderChatzApi\Services\ReplyService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Registers order-chatz/v1 conversation routes.
 */
final class ConversationsController {

	public const NAMESPACE = 'order-chatz/v1';

	/**
	 * Repository.
	 *
	 * @var ConversationRepository
	 */
	private ConversationRepository $repo;

	/**
	 * Reply service.
	 *
	 * @var ReplyService
	 */
	private ReplyService $replies;

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository|null $repo    Optional repository.
	 * @param ReplyService|null           $replies Optional reply service.
	 */
	public function __construct( ?ConversationRepository $repo = null, ?ReplyService $replies = null ) {
		$this->repo    = $repo ?? new ConversationRepository();
		$this->replies = $replies ?? new ReplyService();
	}

	/**
	 * Hook rest_api_init.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/conversations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_conversations' ),
				'permission_callback' => array( Auth::class, 'permission_callback' ),
				'args'                => array(
					'unread'             => array(
						'description'       => 'Only conversations with unread inbound messages.',
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'last_from_customer' => array(
						'description'       => 'Only conversations whose last message is from the customer.',
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit'              => array(
						'description'       => 'Page size (default 20, max 100).',
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'offset'             => array(
						'description'       => 'Offset for pagination.',
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_conversation' ),
				'permission_callback' => array( Auth::class, 'permission_callback' ),
				'args'                => array(
					'id' => array(
						'description'       => 'otz_users.id',
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversations/(?P<id>\d+)/messages',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_message' ),
				'permission_callback' => array( Auth::class, 'permission_callback' ),
				'args'                => array(
					'id'                => array(
						'description'       => 'otz_users.id',
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'type'              => array(
						'description'       => 'Message type: text|image|video|file|sticker (default text).',
						'type'              => 'string',
						'required'          => false,
						'default'           => 'text',
						'sanitize_callback' => 'sanitize_key',
					),
					'message'           => array(
						'description'       => 'Outbound text body (required when type=text). Alias: text.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'text'              => array(
						'description'       => 'Alias for message when type=text.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'image_url'         => array(
						'description'       => 'HTTPS image URL when type=image.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'video_url'         => array(
						'description'       => 'HTTPS video URL when type=video.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'video_name'        => array(
						'description'       => 'Video display name when type=video.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'file_url'          => array(
						'description'       => 'HTTPS file URL when type=file.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'file_name'         => array(
						'description'       => 'File display name when type=file.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'package_id'        => array(
						'description'       => 'LINE sticker package id when type=sticker.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'sticker_id'        => array(
						'description'       => 'LINE sticker id when type=sticker.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'quote_token'       => array(
						'description'       => 'Optional LINE quote token (留言／引用回覆).',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'quoted_message_id' => array(
						'description'       => 'Optional quoted LINE message id (留言／引用回覆).',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET /conversations
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_conversations( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->repo->list_conversations(
			array(
				'unread'             => (bool) $request->get_param( 'unread' ),
				'last_from_customer' => (bool) $request->get_param( 'last_from_customer' ),
				'limit'              => (int) $request->get_param( 'limit' ),
				'offset'             => (int) $request->get_param( 'offset' ),
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /conversations/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversation( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$data = $this->repo->get_conversation( $id );

		if ( null === $data ) {
			return new WP_Error(
				'otzapi_not_found',
				__( 'Conversation not found.', 'otzapi' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * POST /conversations/{id}/messages — outbound text or media reply (+ optional quote).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_message( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$type = (string) ( $request->get_param( 'type' ) ?? '' );
		if ( '' === $type && isset( $body['type'] ) ) {
			$type = sanitize_key( (string) $body['type'] );
		}
		if ( '' === $type ) {
			$type = 'text';
		}

		$args = array(
			'message'           => (string) ( $request->get_param( 'message' ) ?? '' ),
			'text'              => (string) ( $request->get_param( 'text' ) ?? '' ),
			'image_url'         => (string) ( $request->get_param( 'image_url' ) ?? '' ),
			'video_url'         => (string) ( $request->get_param( 'video_url' ) ?? '' ),
			'video_name'        => (string) ( $request->get_param( 'video_name' ) ?? '' ),
			'file_url'          => (string) ( $request->get_param( 'file_url' ) ?? '' ),
			'file_name'         => (string) ( $request->get_param( 'file_name' ) ?? '' ),
			'package_id'        => (string) ( $request->get_param( 'package_id' ) ?? '' ),
			'sticker_id'        => (string) ( $request->get_param( 'sticker_id' ) ?? '' ),
			'quote_token'       => (string) ( $request->get_param( 'quote_token' ) ?? '' ),
			'quoted_message_id' => (string) ( $request->get_param( 'quoted_message_id' ) ?? '' ),
		);

		// Fill from raw JSON when REST args missed nested/alias fields.
		foreach ( array_keys( $args ) as $key ) {
			if ( '' === $args[ $key ] && isset( $body[ $key ] ) ) {
				if ( in_array( $key, array( 'image_url', 'video_url', 'file_url' ), true ) ) {
					$args[ $key ] = esc_url_raw( (string) $body[ $key ] );
				} elseif ( 'message' === $key || 'text' === $key ) {
					$args[ $key ] = sanitize_textarea_field( (string) $body[ $key ] );
				} else {
					$args[ $key ] = sanitize_text_field( (string) $body[ $key ] );
				}
			}
		}

		$result = $this->replies->send( $id, $type, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}
}
