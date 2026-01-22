<?php
/**
 * Config-based ingestion filters.
 *
 * Registers should_ingest_post filters based on VIP_AGENTFORCE_CONFIGS values.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Ingestion;

use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

/**
 * Handles config-based ingestion filter registration.
 *
 * Supports:
 * - `ingestion_api_sync_all_posts`: When true, all published posts will be ingested.
 * - `ingestion_api_categories`: Array of category slugs/IDs - posts in any of these categories will be ingested.
 */
class Ingestion_Config_Filters {
	/**
	 * Initialize the config-based filters.
	 */
	public static function init(): void {
		// Register sync_all_posts filter if enabled.
		if ( Configs::should_sync_all_posts() ) {
			add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );
			return; // If syncing all posts, no need to check categories.
		}

		// Register categories filter if configured.
		$categories = Configs::get_ingestion_categories();
		if ( ! empty( $categories ) ) {
			add_filter( 'vip_agentforce_should_ingest_post', [ __CLASS__, 'filter_by_categories' ], 10, 2 );
		}
	}

	/**
	 * Filter posts by configured categories.
	 *
	 * Returns true if the post is in any of the configured categories.
	 *
	 * @param bool     $should_ingest Current filter value.
	 * @param \WP_Post $post          The post being evaluated.
	 * @return bool Whether to ingest the post.
	 */
	public static function filter_by_categories( bool $should_ingest, \WP_Post $post ): bool {
		// If already approved by another filter, keep it.
		if ( $should_ingest ) {
			return true;
		}

		$configured_categories = Configs::get_ingestion_categories();
		if ( empty( $configured_categories ) ) {
			return false;
		}

		$post_categories = get_the_category( $post->ID );
		if ( empty( $post_categories ) || is_wp_error( $post_categories ) ) {
			return false;
		}

		// Check if any post category matches the configured categories.
		foreach ( $post_categories as $category ) {
			// Match by slug or ID.
			if ( in_array( $category->slug, $configured_categories, true ) ||
				in_array( (string) $category->term_id, $configured_categories, true ) ) {
				return true;
			}
		}

		return false;
	}
}

Ingestion_Config_Filters::init();
