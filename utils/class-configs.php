<?php

namespace Automattic\VIP\Salesforce\Agentforce\Utils;

class Configs {
	/**
	 * Cached config
	 *
	 * @var array<string, mixed>|null
	 */
	private static $cached_config = null;

	/**
	 * Flush the cached config.
	 *
	 * Primarily useful for tests where configs may be overridden via filters.
	 */
	public static function flush_cache(): void {
		self::$cached_config = null;
	}

	/**
	 * Returns whether the Agentforce JS SDK is activated in integration config.
	 */
	public static function is_js_sdk_activated(): bool {
		$config = self::get_config();

		if ( ! array_key_exists( 'agentforce_js_sdk_activated', $config ) ) {
			return false;
		}

		$activated = filter_var( $config['agentforce_js_sdk_activated'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return null === $activated ? false : $activated;
	}

	/**
	 * Returns the Agentforce JS SDK URL from integration config.
	 */
	public static function get_js_sdk_url(): string {
		$config = self::get_config();
		$url    = $config['agentforce_js_sdk_url'] ?? '';

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Get the config
	 * @return array{
	 *     salesforce_instance_url?: string,
	 *     ingestion_api_instance_url?: string,
	 *     ingestion_api_token?: string,
	 *     ingestion_api_endpoint?: string,
	 *     ingestion_api_source_name?: string,
	 *     ingestion_api_object_name?: string,
	 *     ingestion_api_sync_all_posts?: bool,
	 *     ingestion_api_categories?: array<mixed>,
	 *     agentforce_js_sdk_url?: string,
	 *     agentforce_js_sdk_activated?: bool
	 * } The module configs. Returns an empty array if configs are not found, not defined, or if JSON parsing fails.
	 *
	 */
	public static function get_config(): array {
		if ( null === self::$cached_config ) {
			self::init();
		}

		$current_config = [];

		if ( isset( self::$cached_config ) ) {
			$current_config = self::$cached_config;
		}

		if ( ! is_array( $current_config ) ) {
			return [];
		}

		return $current_config;
	}

	private static function init(): void {
		self::$cached_config = self::get_actual_config();
	}

	/**
	 * Retrieve the actual configuration from the defined constant.
	 *
	 * @return array<string, mixed> The configuration array.
	 */
	private static function get_actual_config(): array {
		if ( ! defined( 'VIP_AGENTFORCE_CONFIGS' ) ) {
			Logger::warning_log_if_user_logged_in( 'sb_configs', 'VIP_AGENTFORCE_CONFIGS is not defined.' );
			return [];
		}

		$configs = constant( 'VIP_AGENTFORCE_CONFIGS' );
		if ( is_string( $configs ) ) {
			$configs = json_decode( $configs, true );
		}
		if ( ! is_array( $configs ) ) {
			return [];
		}

		return $configs;
	}

	public static function is_local_env(): bool {
		return ! defined( 'VIP_GO_APP_ENVIRONMENT' ) || 'local' === constant( 'VIP_GO_APP_ENVIRONMENT' );
	}

	public static function is_production_env(): bool {
		return defined( 'VIP_GO_APP_ENVIRONMENT' ) && 'production' === constant( 'VIP_GO_APP_ENVIRONMENT' );
	}

	/**
	 * Returns whether all posts should be synced to the Ingestion API.
	 *
	 * When true, all published posts will be ingested regardless of other filters.
	 *
	 * @return bool
	 */
	public static function should_sync_all_posts(): bool {
		$config = self::get_config();

		if ( ! array_key_exists( 'ingestion_api_sync_all_posts', $config ) ) {
			return false;
		}

		$sync_all = filter_var( $config['ingestion_api_sync_all_posts'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return null === $sync_all ? false : $sync_all;
	}

	/**
	 * Returns the list of category names or IDs to sync to the Ingestion API.
	 *
	 * Posts in any of these categories will be ingested.
	 * Uses category name (not slug) for human readability and resilience to slug changes.
	 *
	 * @return string[] Array of category names or IDs.
	 */
	public static function get_ingestion_categories(): array {
		$config = self::get_config();

		if ( ! array_key_exists( 'ingestion_api_categories', $config ) ) {
			return [];
		}

		$categories = $config['ingestion_api_categories'];

		if ( ! is_array( $categories ) ) {
			return [];
		}

		// Filter out non-string/int values and ensure strings.
		$result = [];
		foreach ( $categories as $cat ) {
			if ( is_int( $cat ) ) {
				$result[] = (string) $cat;
			} elseif ( is_string( $cat ) && '' !== $cat ) {
				$result[] = $cat;
			}
		}
		return $result;
	}
}
