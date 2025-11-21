<?php

namespace Automattic\VIP\Salesforce\Agentforce\Ingest;

use Automattic\VIP\Salesforce\Agentforce\Salesforce\Credentials;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class DataKit_Deployment {

	private const DATAKIT_NAME           = 'WordPress_Posts_DataKit';
	private const DATAKIT_BUNDLE_NAME    = 'WordPress_Posts_DataKit';
	private const DATAKIT_CONNECTOR_NAME = 'wordpress_posts';

	/**
	 * @return array<string, mixed>
	 */
	public static function check_package_installation(): array {
		$credentials = Credentials::get_credentials();
		if ( ! $credentials['access_token'] || ! $credentials['instance_url'] ) {
			return [ 'isInstalled' => false ];
		}

		$query = "SELECT Id, SubscriberPackageId, SubscriberPackage.Name FROM InstalledSubscriberPackage WHERE SubscriberPackage.Name LIKE '%WordPress%' OR SubscriberPackage.Name LIKE '%DataKit%'";
		$url   = rtrim( $credentials['instance_url'], '/' ) . '/services/data/v61.0/query?q=' . rawurlencode( $query );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $credentials['access_token'],
				'Content-Type'  => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'datakit', 'Package check failed: ' . $response->get_error_message() );
			return [ 'isInstalled' => false ];
		}
		
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			Logger::error( 'datakit', 'Package check failed (' . wp_remote_retrieve_response_code( $response ) . '): ' . wp_remote_retrieve_body( $response ) );
			return [ 'isInstalled' => false ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		
		if ( ! empty( $body['records'] ) ) {
			Logger::info( 'datakit', 'Found installed package: ' . $body['records'][0]['SubscriberPackage']['Name'] );
			return [
				'isInstalled' => true,
				'packageId'   => $body['records'][0]['SubscriberPackageId'],
				'packageName' => $body['records'][0]['SubscriberPackage']['Name'],
			];
		}

		return [ 'isInstalled' => false ];
	}

	public static function check_data_stream_deployment(): bool {
		$credentials = Credentials::get_credentials();
		if ( ! $credentials['access_token'] || ! $credentials['instance_url'] ) {
			return false;
		}

		$query = "SELECT Id, Name, Status FROM DataStream WHERE Name = 'WordPress_Posts_Stream' LIMIT 1";
		$url   = rtrim( $credentials['instance_url'], '/' ) . '/services/data/v61.0/query?q=' . rawurlencode( $query );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $credentials['access_token'],
				'Content-Type'  => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'datakit', 'Data stream check failed: ' . $response->get_error_message() );
			return false;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			Logger::error( 'datakit', 'Data stream check failed (' . wp_remote_retrieve_response_code( $response ) . '): ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		
		return ( $body['totalSize'] ?? 0 ) > 0;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function deploy_datakit( ?string $site_id = null, ?string $blog_id = null ): array {
		$credentials = Credentials::get_credentials();
		if ( ! $credentials['access_token'] || ! $credentials['instance_url'] ) {
			return [
				'success' => false,
				'message' => 'Not authenticated',
			];
		}

		$connector_name = ( $site_id && $blog_id ) ? self::DATAKIT_CONNECTOR_NAME . "_{$site_id}_{$blog_id}" : self::DATAKIT_CONNECTOR_NAME;

		Logger::info( 'datakit', "Deploying DataKit with connector: {$connector_name}" );

		// Cast inner arrays to objects to ensure JSON dictionary structure
		$payload = [
			'inputs' => [
				[
					'dataKitComponentsInput' => [
						[
							'componentType' => 'DataStreamBundle',
							'bundleConfig'  => (object) [
								'connectorType'         => 'INGESTAPI',
								'bundleName'            => self::DATAKIT_BUNDLE_NAME,
								'forceNoRefresh'        => true,
								'bundleIngestApiConfig' => (object) [
									'connectorName' => $connector_name,
								],
							],
						],
					],
					'dataKitNameInput'       => self::DATAKIT_NAME,
					'dataKitDataSpaceInput'  => 'default',
				],
			],
		];

		$json_payload = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		Logger::info( 'datakit', "Deployment payload: {$json_payload}" );

		$url = rtrim( $credentials['instance_url'], '/' ) . '/services/data/v61.0/actions/custom/flow/sfdatakit__DeployDataKitComponents';

		$response = wp_remote_post( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $credentials['access_token'],
				'Content-Type'  => 'application/json',
			],
			'body'    => $json_payload,
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'datakit', 'Deployment request failed: ' . $response->get_error_message() );
			return [
				'success' => false,
				'message' => $response->get_error_message(),
				'debug'   => [ 'payload' => $json_payload ],
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			Logger::info( 'datakit', 'Deployment initiated successfully.' );
			return [ 
				'success' => true, 
				'message' => 'Deployment initiated',
				'debug'   => [
					'payload'  => $json_payload,
					'response' => $body,
				],
			];
		}

		Logger::error( 'datakit', "Deployment failed ({$code}): {$body}" );

		$friendly_message = 'Deployment failed: ' . $body;
		if ( strpos( $body, 'no-arg constructor' ) !== false ) {
			$friendly_message = 'Deployment failed: The installed Salesforce DataKit package appears to be incompatible or has a bug (missing no-arg constructor). Please update the "Data Cloud Data Kit" package in your Salesforce Org.';
		}

		return [
			'success' => false,
			'message' => $friendly_message,
			'debug'   => [
				'payload'  => $json_payload,
				'response' => $body,
			],
		];
	}
}
