<?php
/**
 * Salesforce Messaging API client for Custom Client module.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Custom_Client;

use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use WP_Error;

/**
 * Handles communication with Salesforce Messaging for In-App and Web API.
 */
class Salesforce_API {

	/**
	 * Get Salesforce custom client credentials from config.
	 *
	 * @return array{Url: string, OrganizationId: string, DeveloperName: string}|null Credentials.
	 */
	private static function get_credentials(): ?array {
		$config = Configs::get_config();
		$creds  = $config['messaging_custom_client_credentials'] ?? null;

		if ( ! $creds ) {
			return null;
		}

		// Handle both object and array formats.
		if ( is_object( $creds ) ) {
			return [
				'Url'            => $creds['Url'] ?? '',
				'OrganizationId' => $creds['OrganizationId'] ?? '',
				'DeveloperName'  => $creds['DeveloperName'] ?? '',
			];
		}

		return $creds;
	}

	/**
	 * Get an unauthenticated access token from Salesforce.
	 *
	 * @return array{accessToken: string, lastEventId: string}|WP_Error
	 */
	public static function get_access_token(): array|WP_Error {
		$creds = self::get_credentials();
		if ( ! $creds ) {
			return new WP_Error( 'missing_credentials', 'Missing custom client credentials in config' );
		}

		$url = rtrim( $creds['Url'], '/' ) . '/iamessage/api/v2/authorization/unauthenticated/access-token';

		$response = wp_remote_post(
			$url,
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'orgId'               => $creds['OrganizationId'],
						'esDeveloperName'     => $creds['DeveloperName'],
						'capabilitiesVersion' => '1',
						'platform'            => 'Web',
						'context'             => [
							'appName'       => 'vip_agentforce_custom_client',
							'clientVersion' => '1.0.0',
						],
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'salesforce_api_error',
				'Failed to get access token: ' . ( $data['message'] ?? $body ),
				[ 'status_code' => $status_code ]
			);
		}

		return [
			'accessToken' => $data['accessToken'] ?? '',
			'lastEventId' => $data['lastEventId'] ?? '0',
		];
	}

	/**
	 * Create a new conversation with Salesforce.
	 *
	 * @param string $token          Access token.
	 * @param string $conversation_id UUID for the conversation.
	 * @return array{conversationId: string}|WP_Error
	 */
	public static function create_conversation( string $token, string $conversation_id ): array|WP_Error {
		$creds = self::get_credentials();
		if ( ! $creds ) {
			return new WP_Error( 'missing_credentials', 'Missing custom client credentials in config' );
		}

		$url = rtrim( $creds['Url'], '/' ) . '/iamessage/api/v2/conversation';

		$response = wp_remote_post(
			$url,
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				],
				'body'    => wp_json_encode(
					[
						'conversationId'  => $conversation_id,
						'esDeveloperName' => $creds['DeveloperName'],
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error(
				'salesforce_api_error',
				'Failed to create conversation: ' . $body,
				[ 'status_code' => $status_code ]
			);
		}

		return [ 'conversationId' => $conversation_id ];
	}

	/**
	 * Send a message to a conversation.
	 *
	 * @param string $token           Access token.
	 * @param string $conversation_id Conversation ID.
	 * @param string $message         Message text.
	 * @return array{success: bool}|WP_Error
	 */
	public static function send_message( string $token, string $conversation_id, string $message ): array|WP_Error {
		$creds = self::get_credentials();
		if ( ! $creds ) {
			return new WP_Error( 'missing_credentials', 'Missing custom client credentials in config' );
		}

		$url = rtrim( $creds['Url'], '/' ) . '/iamessage/api/v2/conversation/' . $conversation_id . '/message';

		$response = wp_remote_post(
			$url,
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				],
				'body'    => wp_json_encode(
					[
						'message'               => [
							'id'            => wp_generate_uuid4(),
							'messageType'   => 'StaticContentMessage',
							'staticContent' => [
								'formatType' => 'Text',
								'text'       => $message,
							],
						],
						'esDeveloperName'       => $creds['DeveloperName'],
						'isNewMessagingSession' => false,
						'language'              => '',
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error(
				'salesforce_api_error',
				'Failed to send message: ' . $body,
				[ 'status_code' => $status_code ]
			);
		}

		return [ 'success' => true ];
	}

	/**
	 * Get messages (conversation entries) for polling.
	 *
	 * @param string $token           Access token.
	 * @param string $conversation_id Conversation ID.
	 * @return array{conversationEntries: array}|WP_Error
	 */
	public static function get_messages( string $token, string $conversation_id ): array|WP_Error {
		$creds = self::get_credentials();
		if ( ! $creds ) {
			return new WP_Error( 'missing_credentials', 'Missing custom client credentials in config' );
		}

		$url = rtrim( $creds['Url'], '/' ) . '/iamessage/api/v2/conversation/' . $conversation_id . '/entries';

		$response = wp_remote_get(
			add_query_arg(
				[
					'limit'     => 50,
					'direction' => 'FromEnd',
				],
				$url
			),
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
				],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'salesforce_api_error',
				'Failed to get messages: ' . $body,
				[ 'status_code' => $status_code ]
			);
		}

		return [
			'conversationEntries' => $data['conversationEntries'] ?? [],
		];
	}

	/**
	 * End a conversation.
	 *
	 * @param string $token           Access token.
	 * @param string $conversation_id Conversation ID.
	 * @return array{success: bool}|WP_Error
	 */
	public static function end_conversation( string $token, string $conversation_id ): array|WP_Error {
		$creds = self::get_credentials();
		if ( ! $creds ) {
			return new WP_Error( 'missing_credentials', 'Missing custom client credentials in config' );
		}

		$url = rtrim( $creds['Url'], '/' ) . '/iamessage/api/v2/conversation/' . $conversation_id;
		$url = add_query_arg( 'esDeveloperName', $creds['DeveloperName'], $url );

		$response = wp_remote_request(
			$url,
			[
				'method'  => 'DELETE',
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
				],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error(
				'salesforce_api_error',
				'Failed to end conversation: ' . $body,
				[ 'status_code' => $status_code ]
			);
		}

		return [ 'success' => true ];
	}
}
