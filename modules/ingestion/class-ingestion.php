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

			self::fire_ingestion_failure(
				new Ingestion_Failure(
					[
						'failure_code' => Ingestion_Failure::CODE_TRANSFORM_FAILED,
						'post'         => $post,
						'error'        => new \WP_Error(
							'vip_agentforce_transform_failed',
							'Post transformation returned null',
							[
								'post_id'   => $post_id,
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Intentional for error tracing.
								'backtrace' => debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 ),
							]
						),
					]
				)
			);
			return;
		}

		self::send_to_api( $record );

		Logger::info(
			'ingestion',
			'Post ingested successfully',
			[
				'post_id'        => $post_id,
				'post_status'    => $post->post_status,
				'post_type'      => $post->post_type,
				'post_processed' => $record->to_array(),
			]
		);
	}

	/**
	 * Fire the ingestion failure action.
	 *
	 * @param Ingestion_Failure $failure The ingestion failure.
	 */
	private static function fire_ingestion_failure( Ingestion_Failure $failure ): void {
		/**
		 * Fires when a post ingestion fails.
		 *
		 * This action only fires on actual failures (transform or API errors),
		 * not when ingestion is skipped by filters.
		 *
		 * @since 1.0.0
		 *
		 * @param Ingestion_Failure $failure The failure object containing:
		 *                                   - failure_code: One of the Ingestion_Failure::CODE_* constants
		 *                                   - post: The original WP_Post object
		 *                                   - error: WP_Error with failure details
		 */
		do_action( 'vip_agentforce_post_ingestion_failed', $failure );
	}

	/**
	 * Send record to Salesforce API.
	 *
	 * @param Ingestion_Post_Record $record The record to send.
	 * @return array<string, mixed> Mock response (placeholder for future Salesforce integration).
	 */
	private static function send_to_api( Ingestion_Post_Record $record ): array {
		// TODO: Implement actual Salesforce API call.
		return [
			'success'   => true,
			'record_id' => $record->to_array()['site_id_blog_id_post_id'],
			'timestamp' => gmdate( 'c' ),
		];
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
