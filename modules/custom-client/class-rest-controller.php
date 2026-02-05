<?php
/**
 * REST API Controller for Custom Client module.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Custom_Client;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Handles REST API endpoints for the custom chat client.
 */
class REST_Controller {

	/**
	 * REST API namespace.
	 */
	public const NAMESPACE = 'vip-agentforce/v1/custom-client';

	/**
	 * Initialize the REST API routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes(): void {
		// Initialize chat - get token and create conversation.
		register_rest_route(
			self::NAMESPACE,
			'/chat/initialize',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'initialize_chat' ],
				'permission_callback' => '__return_true',
			]
		);

		// Send a message.
		register_rest_route(
			self::NAMESPACE,
			'/chat/message',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'send_message' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'message' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Get messages (polling).
		register_rest_route(
			self::NAMESPACE,
			'/chat/message',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_messages' ],
				'permission_callback' => '__return_true',
			]
		);

		// End chat.
		register_rest_route(
			self::NAMESPACE,
			'/chat/end',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'end_chat' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Extract Bearer token from Authorization header.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string|null Token or null if not found.
	 */
	private static function get_token_from_header( WP_REST_Request $request ): ?string {
		$auth_header = $request->get_header( 'Authorization' );
		if ( ! $auth_header || ! str_starts_with( $auth_header, 'Bearer ' ) ) {
			return null;
		}
		return substr( $auth_header, 7 );
	}

	/**
	 * Get conversation ID from header.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string|null Conversation ID or null if not found.
	 */
	private static function get_conversation_id( WP_REST_Request $request ): ?string {
		return $request->get_header( 'X-Conversation-Id' );
	}

	/**
	 * Initialize chat session.
	 *
	 * Gets an access token and creates a new conversation.
	 *
	 * @param WP_REST_Request $request Request object (unused but required by REST API).
	 * @return WP_REST_Response|WP_Error
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API callback signature.
	public static function initialize_chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Get access token.
		$token_result = Salesforce_API::get_access_token();
		if ( is_wp_error( $token_result ) ) {
			return new WP_REST_Response(
				[
					'error'   => 'Failed to get access token',
					'details' => $token_result->get_error_message(),
				],
				500
			);
		}

		$access_token  = $token_result['accessToken'];
		$last_event_id = $token_result['lastEventId'];

		// Generate conversation ID.
		$conversation_id = strtolower( wp_generate_uuid4() );

		// Create conversation.
		$conv_result = Salesforce_API::create_conversation( $access_token, $conversation_id );
		if ( is_wp_error( $conv_result ) ) {
			return new WP_REST_Response(
				[
					'error'   => 'Failed to create conversation',
					'details' => $conv_result->get_error_message(),
				],
				500
			);
		}

		// Extract orgId from token if possible (for SSE compatibility).
		$org_id      = '';
		$token_parts = explode( '.', $access_token );
		if ( count( $token_parts ) >= 2 ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$payload = json_decode( base64_decode( $token_parts[1] ), true );
			$org_id  = $payload['orgId'] ?? '';
		}

		return new WP_REST_Response(
			[
				'accessToken'    => $access_token,
				'conversationId' => $conversation_id,
				'orgId'          => $org_id,
				'lastEventId'    => $last_event_id,
			],
			200
		);
	}

	/**
	 * Send a message.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function send_message( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token = self::get_token_from_header( $request );
		if ( ! $token ) {
			return new WP_REST_Response( [ 'error' => 'Missing authorization token' ], 401 );
		}

		$conversation_id = self::get_conversation_id( $request );
		if ( ! $conversation_id ) {
			return new WP_REST_Response( [ 'error' => 'Missing conversation ID' ], 400 );
		}

		$message = $request->get_param( 'message' );
		if ( empty( $message ) ) {
			return new WP_REST_Response( [ 'error' => 'Message is required' ], 400 );
		}

		$result = Salesforce_API::send_message( $token, $conversation_id, $message );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				[
					'error'   => 'Failed to send message',
					'details' => $result->get_error_message(),
				],
				500
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get messages (for polling).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_messages( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		$token = self::get_token_from_header( $request );
		if ( ! $token ) {
			return new WP_REST_Response( [ 'error' => 'Missing authorization token' ], 401 );
		}

		$conversation_id = self::get_conversation_id( $request );
		if ( ! $conversation_id ) {
			return new WP_REST_Response( [ 'error' => 'Missing conversation ID' ], 400 );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging.
		error_log( '[Custom Client REST] calling Salesforce_API::get_messages for conv: ' . $conversation_id );

		$result = Salesforce_API::get_messages( $token, $conversation_id );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				[
					'error'   => 'Failed to get messages',
					'details' => $result->get_error_message(),
				],
				500
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * End chat session.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function end_chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token = self::get_token_from_header( $request );
		if ( ! $token ) {
			return new WP_REST_Response( [ 'error' => 'Missing authorization token' ], 401 );
		}

		$conversation_id = self::get_conversation_id( $request );
		if ( ! $conversation_id ) {
			return new WP_REST_Response( [ 'error' => 'Missing conversation ID' ], 400 );
		}

		$result = Salesforce_API::end_conversation( $token, $conversation_id );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				[
					'error'   => 'Failed to end chat',
					'details' => $result->get_error_message(),
				],
				500
			);
		}

		return new WP_REST_Response( $result, 200 );
	}
}
