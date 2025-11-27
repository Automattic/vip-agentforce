<?php
/**
 * Ingestion module.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Ingestion;

use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

/**
 * Handles ingestion filtering for posts to be sent to Salesforce.
 */
class Ingestion {
	/**
	 * Initialize the module.
	 */
	public static function init(): void {
		add_action( 'save_post', [ __CLASS__, 'ingest_post' ], 10, 2 );
	}

	/**
	 * Attempts to ingest a post if it passes the filter.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function ingest_post( int $post_id, \WP_Post $post ): void {
		$should_ingest = self::should_ingest_post( $post );

		Logger::info(
			'ingestion',
			$should_ingest ? 'Post will be ingested' : 'Post will not be ingested',
			[
				'post_id'     => $post_id,
				'post_status' => $post->post_status,
				'post_type'   => $post->post_type,
				'result'      => $should_ingest,
			]
		);
	}

	/**
	 * Determine if a post should be ingested.
	 *
	 * Returns false by default unless a filter explicitly opts in.
	 * This prevents accidental mass ingestion on sites with millions of posts.
	 *
	 * @param \WP_Post $post The post to evaluate.
	 * @return bool Whether the post should be ingested.
	 */
	public static function should_ingest_post( \WP_Post $post ): bool {
		// Safety: No filters = no ingestion.
		if ( ! has_filter( 'vip_agentforce_should_ingest_post' ) ) {
			return false;
		}

		// Only 'publish' status allowed.
		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		/**
		 * Filter whether a post should be ingested into Salesforce.
		 *
		 * @param bool     $should_ingest Default false - must explicitly return true to ingest.
		 * @param \WP_Post $post          The post being evaluated.
		 * @return bool Whether to ingest the post.
		 */
		return (bool) apply_filters( 'vip_agentforce_should_ingest_post', false, $post );
	}
}

Ingestion::init();
