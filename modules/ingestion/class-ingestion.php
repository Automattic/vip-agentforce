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

		if ( ! $should_ingest ) {
			Logger::info(
				'ingestion',
				'Post will not be ingested',
				[
					'post_id'     => $post_id,
					'post_status' => $post->post_status,
					'post_type'   => $post->post_type,
					'result'      => false,
				]
			);
			return;
		}

		$record = self::transform_post( $post );
		if ( null === $record ) {
			Logger::info(
				'ingestion',
				'Post transformation failed, skipping ingestion',
				[
					'post_id'     => $post_id,
					'post_status' => $post->post_status,
					'post_type'   => $post->post_type,
				]
			);
			return;
		}

		Logger::info(
			'ingestion',
			'Post will be ingested',
			[
				'post_id'        => $post_id,
				'post_status'    => $post->post_status,
				'post_type'      => $post->post_type,
				'post_processed' => $record->to_array(),
				'result'         => true,
			]
		);
	}

	/**
	 * Transform a post into an Ingestion_Post_Record.
	 *
	 * @param \WP_Post $post The post to transform.
	 * @return Ingestion_Post_Record|null The transformed record, or null if transformation failed.
	 */
	public static function transform_post( \WP_Post $post ): ?Ingestion_Post_Record {
		/**
		 * Filter to transform a WP_Post into an Ingestion_Post_Record for Salesforce.
		 *
		 * @param Ingestion_Post_Record|null $record The transformed record (null if not yet transformed).
		 * @param \WP_Post                   $post   The post being transformed.
		 * @return Ingestion_Post_Record|null The transformed record, or null to skip ingestion.
		 */
		$record = apply_filters( 'vip_agentforce_transform_post', null, $post );

		if ( ! $record instanceof Ingestion_Post_Record ) {
			Logger::warning(
				'ingestion',
				'vip_agentforce_transform_post filter must return an Ingestion_Post_Record instance',
				[
					'post_id'       => $post->ID,
					'returned_type' => is_object( $record ) ? get_class( $record ) : gettype( $record ),
				]
			);
			return null;
		}

		return $record;
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
