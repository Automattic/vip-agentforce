<?php
/**
 * Ingestion queue module - manages async processing of posts via cron.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Ingestion;

use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

/**
 * Handles queueing of posts for async Salesforce ingestion.
 *
 * Instead of making API calls synchronously on save_post hooks,
 * this class queues posts for processing by the cron job.
 *
 * Use the `vip_agentforce_use_async_ingestion` filter to toggle between:
 * - true (default): Queue posts for async processing via cron
 * - false: Process posts synchronously (legacy behavior)
 */
class Ingestion_Queue {
	/**
	 * Post meta key for tracking posts queued for ingestion.
	 */
	public const META_KEY_QUEUED_FOR_SYNC = 'vip_agentforce_queued_for_sync';

	/**
	 * Post meta key for tracking posts queued for deletion.
	 */
	public const META_KEY_QUEUED_FOR_DELETE = 'vip_agentforce_queued_for_delete';

	/**
	 * Queue action type constants.
	 */
	public const ACTION_SYNC = 'sync';
	public const ACTION_DELETE = 'delete';

	/**
	 * Initialize the queue hooks.
	 */
	public static function init(): void {
		add_action( 'save_post', [ __CLASS__, 'handle_save_post' ], 10, 2 );
		add_action( 'before_delete_post', [ __CLASS__, 'handle_before_delete_post' ], 10, 2 );
	}

	/**
	 * Check if async ingestion is enabled.
	 *
	 * @return bool True if async (cron) mode is enabled, false for sync (immediate) mode.
	 */
	public static function is_async_enabled(): bool {
		/**
		 * Filter to control async vs sync ingestion mode.
		 *
		 * When true (default), posts are queued for async processing via cron.
		 * When false, posts are processed synchronously during the request (legacy behavior).
		 *
		 * @since 1.0.0
		 *
		 * @param bool $use_async Whether to use async ingestion. Default true.
		 */
		return (bool) apply_filters( 'vip_agentforce_use_async_ingestion', true );
	}

	/**
	 * Handle post save - queue for sync or process immediately.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function handle_save_post( int $post_id, \WP_Post $post ): void {
		// Skip revisions and autosaves.
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( self::is_async_enabled() ) {
			self::queue_for_sync( $post_id );
		} else {
			// Sync mode: process immediately.
			Ingestion::sync_post( $post );
		}
	}

	/**
	 * Handle post deletion - queue for deletion or process immediately.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function handle_before_delete_post( int $post_id, \WP_Post $post ): void {
		// Only act if the post was previously ingested.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! Ingestion::was_post_ingested( $post ) ) {
			return;
		}

		if ( self::is_async_enabled() ) {
			self::queue_for_delete( $post_id, $post );
		} else {
			// Sync mode: delete immediately.
			Ingestion::handle_before_delete_post( $post_id, $post );
		}
	}

	/**
	 * Queue a post for sync.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function queue_for_sync( int $post_id ): void {
		// If already queued for delete, remove it (sync takes precedence on save).
		delete_post_meta( $post_id, self::META_KEY_QUEUED_FOR_DELETE );

		// Set the queue timestamp.
		update_post_meta( $post_id, self::META_KEY_QUEUED_FOR_SYNC, time() );

		// Ensure the cron is scheduled.
		Ingestion_Cron::schedule_processing();

		Logger::info(
			'ingestion-queue',
			'Post queued for sync',
			[
				'post_id' => $post_id,
			]
		);
	}

	/**
	 * Queue a post for deletion from Salesforce.
	 *
	 * For deletions, we also store the record_id since the post may not exist
	 * when the cron runs.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    The post object (needed to build record_id).
	 */
	public static function queue_for_delete( int $post_id, \WP_Post $post ): void {
		// Remove any pending sync - deletion supersedes.
		delete_post_meta( $post_id, self::META_KEY_QUEUED_FOR_SYNC );

		// Store the record_id along with the queue timestamp since the post
		// might be deleted by the time the cron runs.
		$site_id   = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';
		$blog_id   = (string) get_current_blog_id();
		$record_id = $site_id . '_' . $blog_id . '_' . $post_id;

		update_post_meta(
			$post_id,
			self::META_KEY_QUEUED_FOR_DELETE,
			[
				'queued_at' => time(),
				'record_id' => $record_id,
			]
		);

		// Ensure the cron is scheduled.
		Ingestion_Cron::schedule_processing();

		Logger::info(
			'ingestion-queue',
			'Post queued for deletion',
			[
				'post_id'   => $post_id,
				'record_id' => $record_id,
			]
		);
	}

	/**
	 * Get posts queued for sync.
	 *
	 * @param int $limit Maximum number of posts to return.
	 * @return array<int, int> Array of post IDs.
	 */
	public static function get_queued_for_sync( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				ORDER BY meta_value ASC
				LIMIT %d",
				self::META_KEY_QUEUED_FOR_SYNC,
				$limit
			)
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Get posts queued for deletion.
	 *
	 * @param int $limit Maximum number of posts to return.
	 * @return array<int, array{post_id: int, record_id: string}> Array of queued deletions.
	 */
	public static function get_queued_for_delete( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				LIMIT %d",
				self::META_KEY_QUEUED_FOR_DELETE,
				$limit
			)
		);

		$queued = [];
		foreach ( $results as $row ) {
			$meta_value = maybe_unserialize( $row->meta_value );
			if ( is_array( $meta_value ) && isset( $meta_value['record_id'] ) ) {
				$queued[] = [
					'post_id'   => (int) $row->post_id,
					'record_id' => $meta_value['record_id'],
				];
			}
		}

		return $queued;
	}

	/**
	 * Remove a post from the sync queue.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function dequeue_sync( int $post_id ): void {
		delete_post_meta( $post_id, self::META_KEY_QUEUED_FOR_SYNC );
	}

	/**
	 * Remove a post from the delete queue.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function dequeue_delete( int $post_id ): void {
		delete_post_meta( $post_id, self::META_KEY_QUEUED_FOR_DELETE );
	}

	/**
	 * Check if there are items in the queue.
	 *
	 * @return bool True if there are queued items.
	 */
	public static function has_queued_items(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
				WHERE meta_key IN (%s, %s)",
				self::META_KEY_QUEUED_FOR_SYNC,
				self::META_KEY_QUEUED_FOR_DELETE
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Get the count of queued items.
	 *
	 * @return array{sync: int, delete: int} Counts by action type.
	 */
	public static function get_queue_counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sync_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY_QUEUED_FOR_SYNC
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$delete_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY_QUEUED_FOR_DELETE
			)
		);

		return [
			'sync'   => $sync_count,
			'delete' => $delete_count,
		];
	}
}
