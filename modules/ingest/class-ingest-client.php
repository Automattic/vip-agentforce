<?php

namespace Automattic\VIP\Salesforce\Agentforce\Ingest;

use Automattic\VIP\Salesforce\Agentforce\Salesforce\Credentials;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingest_Client {

	private const DATA_CLOUD_TOKEN_TRANSIENT = 'vip_agentforce_data_cloud_token';

	public static function get_data_cloud_token(): ?string {
		$token = get_transient( self::DATA_CLOUD_TOKEN_TRANSIENT );
		if ( $token ) {
			return $token;
		}

		$credentials = Credentials::get_credentials();
		if ( ! $credentials['access_token'] || ! $credentials['instance_url'] ) {
			return null;
		}

		$url = rtrim( $credentials['instance_url'], '/' ) . '/services/a360/token';
		
		$response = wp_remote_post( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $credentials['access_token'],
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => [
				'grant_type'         => 'urn:salesforce:grant-type:external:cdp',
				'subject_token'      => $credentials['access_token'],
				'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
			],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'ingest', 'Data Cloud token exchange failed: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			Logger::error( 'ingest', 'Data Cloud token exchange failed: ' . wp_remote_retrieve_body( $response ) );
			return null;
		}

		$body         = json_decode( wp_remote_retrieve_body( $response ), true );
		$access_token = $body['access_token'] ?? null;

		if ( $access_token ) {
			// Cache for 50 minutes (tokens usually last 1 hour)
			set_transient( self::DATA_CLOUD_TOKEN_TRANSIENT, $access_token, 50 * MINUTE_IN_SECONDS );
		}

		return $access_token;
	}

	public static function ingest_data( array $data, ?string $site_id = null, ?string $blog_id = null ): array {
		$data_cloud_token = self::get_data_cloud_token();
		$credentials      = Credentials::get_credentials();

		if ( ! $data_cloud_token || ! $credentials['instance_url'] ) {
			return [
				'success' => false,
				'message' => 'Not authenticated',
			];
		}

		$connector_name = ( $site_id && $blog_id ) ? "wordpress_posts_{$site_id}_{$blog_id}" : 'wordpress_posts';
		$url            = rtrim( $credentials['instance_url'], '/' ) . "/api/v1/ingest/sources/{$connector_name}/wordpress_post";

		$payload = [ 'data' => $data ];
		
		$response = wp_remote_post( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $data_cloud_token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 202 === $code || 200 === $code ) {
			return [
				'success' => true,
				'message' => 'Ingestion successful',
			];
		}

		return [
			'success' => false,
			'message' => "Ingestion failed ({$code}): {$body}",
		];
	}
}
