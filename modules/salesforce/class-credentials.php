<?php

namespace Automattic\VIP\Salesforce\Agentforce\Salesforce;

use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

class Credentials {
	private const OPTION_NAME = 'vip_agentforce_salesforce_credentials';

	/**
	 * @return array<string, string|null>
	 */
	public static function get_credentials(): array {
		$config = Configs::get_config();
		
		// Try to get from constant/config first
		$instance_url = $config['salesforce_instance_url'] ?? null;
		$access_token = $config['salesforce_access_token'] ?? null;

		// If not in config, check options
		if ( ! $instance_url || ! $access_token ) {
			$stored = get_option( self::OPTION_NAME, [] );
			
			if ( ! $instance_url ) {
				$instance_url = $stored['instance_url'] ?? null;
			}
			if ( ! $access_token ) {
				$access_token = $stored['access_token'] ?? null;
			}
		}

		return [
			'instance_url' => $instance_url,
			'access_token' => $access_token,
		];
	}

	public static function save_credentials( string $instance_url, string $access_token ): void {
		update_option( self::OPTION_NAME, [
			'instance_url' => rtrim( $instance_url, '/' ),
			'access_token' => $access_token,
		] );
	}
}
