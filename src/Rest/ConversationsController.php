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
					'message'           => array(
						'description'       => 'Outbound text message body.',
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'quote_token'       => array(
						'description'       => 'Optional LINE quote token.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'quoted_message_id' => array(
						'description'       => 'Optional quoted LINE message id.',
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
	 * POST /conversations/{id}/messages — outbound text reply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_message( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$message = (string) $request->get_param( 'message' );

		// JSON body may use "text" as alias.
		if ( '' === trim( $message ) ) {
			$body = $request->get_json_params();
			if ( isset( $body['text'] ) ) {
				$message = sanitize_textarea_field( (string) $body['text'] );
			}
		}

		$result = $this->replies->send_text(
			$id,
			$message,
			array(
				'quote_token'       => (string) ( $request->get_param( 'quote_token' ) ?? '' ),
				'quoted_message_id' => (string) ( $request->get_param( 'quoted_message_id' ) ?? '' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}
}
