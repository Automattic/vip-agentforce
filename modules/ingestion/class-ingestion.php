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
		add_action( 'transition_post_status', [ __CLASS__, 'handle_post_unpublished' ], 10, 3 );
		add_action( 'before_delete_post', [ __CLASS__, 'handle_post_deleted' ], 10, 2 );
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

		$response = static::send_to_api( $record );

		if ( ! $response['success'] ) {
			Logger::info(
				'ingestion',
				'API call failed',
				[
					'post_id'  => $post_id,
					'response' => $response,
				]
			);

			self::fire_ingestion_failure(
				new Ingestion_Failure(
					[
						'failure_code' => Ingestion_Failure::CODE_API_ERROR,
						'post'         => $post,
						'error'        => new \WP_Error(
							'vip_agentforce_api_error',
							$response['error_message'] ?? 'API call failed',
							[
								'post_id'   => $post_id,
								'response'  => $response,
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Intentional for error tracing.
								'backtrace' => debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 ),
							]
						),
					]
				)
			);
			return;
		}

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
	 * @return array<string, mixed> Response with 'success' key (true/false) and error details if failed.
	 */
	public static function send_to_api( Ingestion_Post_Record $record ): array {
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

	/**
	 * Handle post status transitions that result in unpublishing.
	 *
	 * When a post transitions from 'publish' to any other status,
	 * we delete it from Salesforce if it was previously ingestible.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public static function handle_post_unpublished( string $new_status, string $old_status, \WP_Post $post ): void {
		// Skip revisions and autosaves.
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		// Only act if transitioning FROM 'publish' to a non-publish status.
		if ( 'publish' !== $old_status || 'publish' === $new_status ) {
			return;
		}

		// Check if the post was ingestible (would have been sent to Salesforce).
		if ( ! self::was_post_ingestible( $post ) ) {
			Logger::info(
				'ingestion',
				'Post was not previously ingestible, skipping deletion',
				[
					'post_id'    => $post->ID,
					'old_status' => $old_status,
					'new_status' => $new_status,
				]
			);
			return;
		}

		self::delete_post_from_salesforce( $post, 'unpublished' );
	}

	/**
	 * Handle permanent post deletion.
	 *
	 * When a published post is permanently deleted, we delete it from Salesforce
	 * if it was ingestible.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function handle_post_deleted( int $post_id, \WP_Post $post ): void {
		// Skip revisions and autosaves.
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		// Only act if the post was published (otherwise it wouldn't be in Salesforce).
		if ( 'publish' !== $post->post_status ) {
			Logger::info(
				'ingestion',
				'Deleted post was not published, skipping Salesforce deletion',
				[
					'post_id'     => $post_id,
					'post_status' => $post->post_status,
				]
			);
			return;
		}

		// Check if the post was ingestible.
		if ( ! self::was_post_ingestible( $post ) ) {
			Logger::info(
				'ingestion',
				'Deleted post was not ingestible, skipping deletion',
				[ 'post_id' => $post_id ]
			);
			return;
		}

		self::delete_post_from_salesforce( $post, 'deleted' );
	}

	/**
	 * Check if a post was previously ingestible (would have been ingested when published).
	 *
	 * This is used to determine if we should delete from Salesforce.
	 * A post is considered "was ingestible" if the filter is registered AND would return true.
	 *
	 * @param \WP_Post $post The post to check.
	 * @return bool Whether the post was previously ingestible.
	 */
	private static function was_post_ingestible( \WP_Post $post ): bool {
		// Safety: No filters = no ingestion ever happened.
		if ( ! has_filter( 'vip_agentforce_should_ingest_post' ) ) {
			return false;
		}

		// Temporarily set post status to 'publish' to check filter
		// (in case the post status has already changed).
		$current_status    = $post->post_status;
		$post->post_status = 'publish';

		/** This filter is documented in class-ingestion.php */
		$would_ingest = (bool) apply_filters( 'vip_agentforce_should_ingest_post', false, $post );

		// Restore original status.
		$post->post_status = $current_status;

		return $would_ingest;
	}

	/**
	 * Delete a post from Salesforce.
	 *
	 * @param \WP_Post $post   The post to delete.
	 * @param string   $reason The reason for deletion ('unpublished' or 'deleted').
	 */
	private static function delete_post_from_salesforce( \WP_Post $post, string $reason ): void {
		$record_id = self::build_record_id( $post );

		Logger::info(
			'ingestion',
			'Attempting to delete post from Salesforce',
			[
				'post_id'   => $post->ID,
				'record_id' => $record_id,
				'reason'    => $reason,
			]
		);

		$response = static::delete_from_api( $post );

		if ( ! $response['success'] ) {
			Logger::info(
				'ingestion',
				'Delete API call failed',
				[
					'post_id'   => $post->ID,
					'record_id' => $record_id,
					'response'  => $response,
				]
			);

			self::fire_deletion_failure(
				new Deletion_Failure(
					[
						'failure_code' => Deletion_Failure::CODE_DELETE_API_ERROR,
						'post'         => $post,
						'record_id'    => $record_id,
						'error'        => new \WP_Error(
							'vip_agentforce_delete_api_error',
							$response['error_message'] ?? 'Delete API call failed',
							[
								'post_id'   => $post->ID,
								'record_id' => $record_id,
								'response'  => $response,
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Intentional for error tracing.
								'backtrace' => debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 ),
							]
						),
					]
				)
			);
			return;
		}

		Logger::info(
			'ingestion',
			'Post deleted from Salesforce successfully',
			[
				'post_id'   => $post->ID,
				'record_id' => $record_id,
				'reason'    => $reason,
			]
		);
	}

	/**
	 * Delete a post record from Salesforce API.
	 *
	 * @param \WP_Post $post The post to delete.
	 * @return array<string, mixed> Response with 'success' key (true/false) and error details if failed.
	 */
	public static function delete_from_api( \WP_Post $post ): array {
		$record_id = self::build_record_id( $post );

		// TODO: Implement actual Salesforce delete API call.
		return [
			'success'   => true,
			'record_id' => $record_id,
			'timestamp' => gmdate( 'c' ),
		];
	}

	/**
	 * Build the record ID for a post (site_id_blog_id_post_id format).
	 *
	 * @param \WP_Post $post The post.
	 * @return string The record ID.
	 */
	private static function build_record_id( \WP_Post $post ): string {
		$site_id = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';
		$blog_id = (string) get_current_blog_id();
		$post_id = (string) $post->ID;

		return $site_id . '_' . $blog_id . '_' . $post_id;
	}

	/**
	 * Fire the deletion failure action.
	 *
	 * @param Deletion_Failure $failure The deletion failure.
	 */
	private static function fire_deletion_failure( Deletion_Failure $failure ): void {
		/**
		 * Fires when a post deletion from Salesforce fails.
		 *
		 * This action fires when we attempt to delete a post from Salesforce
		 * but the API call fails.
		 *
		 * @since 1.0.0
		 *
		 * @param Deletion_Failure $failure The failure object containing:
		 *                                  - failure_code: One of the Deletion_Failure::CODE_* constants
		 *                                  - post: The original WP_Post object
		 *                                  - record_id: The Salesforce record ID that failed to delete
		 *                                  - error: WP_Error with failure details
		 */
		do_action( 'vip_agentforce_post_deletion_failed', $failure );
	}
}

Ingestion::init();
