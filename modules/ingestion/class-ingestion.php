<?php
/**
 * Ingestion module.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Ingestion;

use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

/**
 * Handles ingestion filtering for records to be sent to Salesforce.
 *
 * @phpstan-import-type Post_Ingestion_Record from Ingestion_Record
 */
class Ingestion {
	/**
	 * Initialize the module.
	 */
	public static function init(): void {
		add_action( 'save_post', [ __CLASS__, 'on_save_post' ], 10, 2 );
	}

	/**
	 * Hook: Fires when a post is saved.
	 * Checks if the post should be ingested and logs the decision.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function on_save_post( int $post_id, \WP_Post $post ): void {
		$ingestion_record = new Ingestion_Record( Ingestion_Record::TYPE_POST, $post );
		$should_ingest    = self::should_ingest_record( $ingestion_record );

		Logger::info(
			'ingestion',
			$should_ingest ? 'Record will be ingested' : 'Record will not be ingested',
			[
				'type'        => $ingestion_record->type,
				'post_id'     => $post_id,
				'post_status' => $post->post_status,
				'post_type'   => $post->post_type,
				'result'      => $should_ingest,
			]
		);
	}

	/**
	 * Determine if a record should be ingested.
	 *
	 * Returns false by default unless a filter explicitly opts in.
	 * This prevents accidental mass ingestion on sites with millions of posts.
	 *
	 * @param Ingestion_Record<'post'|'comment'|'user', \WP_Post|\WP_Comment|\WP_User> $ingestion_record The record to evaluate.
	 * @return bool Whether the record should be ingested.
	 */
	public static function should_ingest_record( Ingestion_Record $ingestion_record ): bool {
		// Safety: No filters = no ingestion.
		if ( ! has_filter( 'vip_agentforce_should_ingest_record' ) ) {
			return false;
		}

		// Post-specific: Only 'publish' status allowed.
		if ( Ingestion_Record::TYPE_POST === $ingestion_record->type && $ingestion_record->record instanceof \WP_Post ) {
			if ( 'publish' !== $ingestion_record->record->post_status ) {
				return false;
			}
		}

		/**
		 * Filter whether a record should be ingested into Salesforce.
		 *
		 * @param bool             $should_ingest    Default false - must explicitly return true to ingest.
		 * @param Ingestion_Record $ingestion_record Contains type and record.
		 * @return bool Whether to ingest the record.
		 */
		return (bool) apply_filters( 'vip_agentforce_should_ingest_record', false, $ingestion_record );
	}
}

Ingestion::init();
