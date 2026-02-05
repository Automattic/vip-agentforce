<?php
/**
 * Ingestion cron module - handles scheduled processing of the queue.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Ingestion;

use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

/**
 * Handles cron-based processing of the ingestion queue.
 *
 * All Salesforce API calls are made through this cron job,
 * never synchronously during the request lifecycle.
 */
class Ingestion_Cron {
	/**
	 * Cron hook name for processing the queue.
	 */
	public const CRON_HOOK = 'vip_agentforce_process_ingestion_queue';

	/**
	 * Default batch size for processing.
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Default cron interval in seconds (15 minutes - VIP minimum).
	 */
	public const DEFAULT_CRON_INTERVAL = 900;

	/**
	 * Initialize the cron hooks.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'process_queue' ] );
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] );

		// Schedule on init if there are queued items.
		add_action( 'init', [ __CLASS__, 'maybe_schedule_on_init' ], 999 );
	}

	/**
	 * Add custom cron schedule for frequent processing.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}> Modified schedules.
	 */
	public static function add_cron_schedule( array $schedules ): array {
		$schedules['vip_agentforce_ingestion'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'VIP Agentforce Ingestion', 'vip-agentforce' ),
		];

		// Allow filtering to increase interval (but not below VIP minimum of 15 min).
		$filtered_interval = self::get_cron_interval();
		if ( $filtered_interval > 15 * MINUTE_IN_SECONDS ) {
			$schedules['vip_agentforce_ingestion']['interval'] = $filtered_interval;
		}

		return $schedules;
	}

	/**
	 * Get the cron interval in seconds.
	 *
	 * @return int Interval in seconds (minimum 900 = 15 minutes per VIP requirements).
	 */
	public static function get_cron_interval(): int {
		/**
		 * Filter the cron interval for queue processing.
		 *
		 * @since 1.0.0
		 *
		 * @param int $interval Interval in seconds. Default 900 (15 minutes).
		 */
		$interval = (int) apply_filters( 'vip_agentforce_cron_interval', self::DEFAULT_CRON_INTERVAL );

		// Minimum 15 minutes per VIP platform requirements.
		return max( 15 * MINUTE_IN_SECONDS, $interval );
	}

	/**
	 * Maybe schedule the cron on init if there are pending items.
	 */
	public static function maybe_schedule_on_init(): void {
		if ( Ingestion_Queue::has_queued_items() && ! self::is_scheduled() ) {
			self::schedule_processing();
		}
	}

	/**
	 * Schedule the cron job if not already scheduled.
	 */
	public static function schedule_processing(): void {
		if ( self::is_scheduled() ) {
			return;
		}

		$scheduled = wp_schedule_event( time(), 'vip_agentforce_ingestion', self::CRON_HOOK );

		if ( false !== $scheduled ) {
			Logger::info(
				'ingestion-cron',
				'Scheduled ingestion queue processing cron',
				[ 'interval' => self::get_cron_interval() ]
			);
		}
	}

	/**
	 * Unschedule the cron job.
	 */
	public static function unschedule_processing(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );

			Logger::info(
				'ingestion-cron',
				'Unscheduled ingestion queue processing cron'
			);
		}
	}

	/**
	 * Check if the cron is scheduled.
	 *
	 * @return bool True if scheduled.
	 */
	public static function is_scheduled(): bool {
		return false !== wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Process the ingestion queue.
	 *
	 * This is the main cron callback that processes queued posts.
	 *
	 * @param int|null $batch_size Optional batch size override.
	 * @return array{synced: int, deleted: int, failed: int, skipped: int} Processing results.
	 */
	public static function process_queue( ?int $batch_size = null ): array {
		$batch_size = $batch_size ?? self::get_batch_size();

		$results = [
			'synced'  => 0,
			'deleted' => 0,
			'failed'  => 0,
			'skipped' => 0,
		];

		Logger::info(
			'ingestion-cron',
			'Starting queue processing',
			[ 'batch_size' => $batch_size ]
		);

		// Process deletions first (they may free up space in Salesforce).
		$results = self::process_deletions( $results, $batch_size );

		// Process syncs with remaining batch capacity.
		$remaining_batch = $batch_size - $results['deleted'] - $results['failed'];
		if ( $remaining_batch > 0 ) {
			$results = self::process_syncs( $results, $remaining_batch );
		}

		Logger::info(
			'ingestion-cron',
			'Queue processing complete',
			$results
		);

		// Unschedule if queue is empty.
		if ( ! Ingestion_Queue::has_queued_items() ) {
			self::unschedule_processing();
		}

		return $results;
	}

	/**
	 * Process queued deletions.
	 *
	 * @param array{synced: int, deleted: int, failed: int, skipped: int} $results Current results.
	 * @param int                                                          $limit   Max items to process.
	 * @return array{synced: int, deleted: int, failed: int, skipped: int} Updated results.
	 */
	private static function process_deletions( array $results, int $limit ): array {
		$queued_deletions = Ingestion_Queue::get_queued_for_delete( $limit );

		foreach ( $queued_deletions as $item ) {
			$post_id   = $item['post_id'];
			$record_id = $item['record_id'];

			$api_result = Ingestion::delete_record_id_from_api( $record_id );

			if ( $api_result->success ) {
				++$results['deleted'];

				Logger::info(
					'ingestion-cron',
					'Successfully deleted record from Salesforce',
					[
						'post_id'   => $post_id,
						'record_id' => $record_id,
					]
				);
			} else {
				++$results['failed'];

				Logger::warning(
					'ingestion-cron',
					'Failed to delete record from Salesforce',
					[
						'post_id'       => $post_id,
						'record_id'     => $record_id,
						'error_message' => $api_result->error_message,
					]
				);
			}

			// Always dequeue to avoid infinite retry loops.
			// Failed deletions can be retried via CLI if needed.
			Ingestion_Queue::dequeue_delete( $post_id );
		}

		return $results;
	}

	/**
	 * Process queued syncs.
	 *
	 * @param array{synced: int, deleted: int, failed: int, skipped: int} $results Current results.
	 * @param int                                                          $limit   Max items to process.
	 * @return array{synced: int, deleted: int, failed: int, skipped: int} Updated results.
	 */
	private static function process_syncs( array $results, int $limit ): array {
		$queued_post_ids = Ingestion_Queue::get_queued_for_sync( $limit );

		foreach ( $queued_post_ids as $post_id ) {
			$post = get_post( $post_id );

			// Post was deleted before cron ran.
			if ( ! $post ) {
				Ingestion_Queue::dequeue_sync( $post_id );
				++$results['skipped'];
				continue;
			}

			// Use the core sync logic.
			$sync_result = Ingestion::sync_post( $post );

			switch ( $sync_result->status ) {
				case Sync_Result::INGESTED:
					++$results['synced'];

					Logger::info(
						'ingestion-cron',
						'Successfully synced post to Salesforce',
						[ 'post_id' => $post_id ]
					);
					break;

				case Sync_Result::DELETED:
					++$results['deleted'];

					Logger::info(
						'ingestion-cron',
						'Deleted post from Salesforce (no longer matches filter)',
						[ 'post_id' => $post_id ]
					);
					break;

				case Sync_Result::SKIPPED:
					++$results['skipped'];
					break;

				case Sync_Result::FAILED_TRANSFORM:
				case Sync_Result::FAILED_API:
					++$results['failed'];

					Logger::warning(
						'ingestion-cron',
						'Failed to sync post to Salesforce',
						[
							'post_id'       => $post_id,
							'status'        => $sync_result->status,
							'error_message' => $sync_result->error_message,
						]
					);
					break;
			}

			// Dequeue regardless of result to avoid infinite loops.
			Ingestion_Queue::dequeue_sync( $post_id );
		}

		return $results;
	}

	/**
	 * Get the batch size for processing.
	 *
	 * @return int Batch size.
	 */
	private static function get_batch_size(): int {
		/**
		 * Filter the batch size for queue processing.
		 *
		 * @param int $batch_size Default batch size.
		 */
		return (int) apply_filters( 'vip_agentforce_cron_batch_size', self::DEFAULT_BATCH_SIZE );
	}

	/**
	 * Run the queue processing immediately (for CLI use).
	 *
	 * @param int|null $batch_size Optional batch size.
	 * @return array{synced: int, deleted: int, failed: int, skipped: int} Processing results.
	 */
	public static function run_now( ?int $batch_size = null ): array {
		return self::process_queue( $batch_size );
	}
}
