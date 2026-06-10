<?php
/**
 * Tests for Ingestion_Cron class.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_API_Client;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Cron;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Queue;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Sync_Progress;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_Cron_Test extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests for verification.
	 *
	 * @var array<int, array{url: string, method: string, body: string}>
	 */
	private array $captured_requests = [];

	public function setUp(): void {
		parent::setUp();
		Logger::disable();

		// Initialize cron hooks (registers the custom schedule).
		Ingestion_Cron::init();

		// Prime configs cache for API calls.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
			]
		);
		Ingestion_API_Client::clear_retry_status();
	}

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();

		// Clean up.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		remove_all_filters( 'vip_agentforce_transform_post' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'cron_schedules' );
		remove_all_actions( Ingestion_Cron::CRON_HOOK );
		Configs::flush_cache();
		$this->captured_requests = [];

		// Clean up sync queue (post meta).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
				Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC,
				Ingestion::META_KEY_INGESTION_ATTEMPTED
			)
		);

		// Clean up delete queue (option).
		delete_option( Ingestion_Queue::OPTION_DELETE_QUEUE );

		// Clean up sync progress.
		Ingestion_Sync_Progress::reset();

		// Unschedule cron.
		Ingestion_Cron::unschedule_processing();
		Ingestion_API_Client::clear_retry_status();
	}

	/**
	 * Prime Configs cache for deterministic tests.
	 *
	 * @param array<string, mixed> $config
	 */
	private function prime_configs_cache( array $config ): void {
		$ref  = new ReflectionClass( Configs::class );
		$prop = $ref->getProperty( 'cached_config' );
		$prop->setAccessible( true );
		$prop->setValue( null, $config );
	}

	/**
	 * Mock HTTP requests to return success (202) and capture request details.
	 */
	private function mock_http_success(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'test.salesforce.com' ) !== false ) {
					$this->captured_requests[] = [
						'url'    => $url,
						'method' => $args['method'] ?? 'GET',
						'body'   => $args['body'] ?? '',
					];
					return [
						'response' => [
							'code'    => 202,
							'message' => 'Accepted',
						],
						'body'     => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Helper to set up filters for a valid ingestion.
	 */
	private function setup_ingestion_filters(): void {
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );
		add_filter(
			'vip_agentforce_transform_post',
			function ( $record, $post ) {
				return new Ingestion_Post_Record(
					[
						'site_id'                 => '1',
						'blog_id'                 => '1',
						'post_id'                 => (string) $post->ID,
						'site_id_blog_id'         => '1_1',
						'site_id_blog_id_post_id' => '1_1_' . $post->ID,
						'published'               => true,
						'last_published_at'       => '2025-01-01T00:00:00+00:00',
						'last_modified_at'        => '2025-01-01T00:00:00+00:00',
						'title'                   => $post->post_title,
						'content'                 => $post->post_content,
						'excerpt'                 => $post->post_excerpt,
						'categories'              => '',
						'tags'                    => '',
						'author'                  => '',
						'url'                     => 'https://example.com',
						'post_type'               => $post->post_type,
						'post_status'             => $post->post_status,
					]
				);
			},
			10,
			2
		);
	}

	public function test_cron_hook_is_defined(): void {
		$this->assertSame( 'vip_agentforce_process_ingestion_queue', Ingestion_Cron::CRON_HOOK );
	}

	public function test_schedule_processing_schedules_cron(): void {
		$this->assertFalse( Ingestion_Cron::is_scheduled() );

		Ingestion_Cron::schedule_processing();

		$this->assertTrue( Ingestion_Cron::is_scheduled() );
	}

	public function test_schedule_processing_does_not_duplicate(): void {
		Ingestion_Cron::schedule_processing();
		Ingestion_Cron::schedule_processing();

		// Should only be scheduled once.
		$this->assertTrue( Ingestion_Cron::is_scheduled() );

		$crons = _get_cron_array();
		$count = 0;
		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks[ Ingestion_Cron::CRON_HOOK ] ) ) {
				++$count;
			}
		}

		$this->assertSame( 1, $count, 'Cron should only be scheduled once.' );
	}

	public function test_unschedule_processing_removes_cron(): void {
		Ingestion_Cron::schedule_processing();
		$this->assertTrue( Ingestion_Cron::is_scheduled() );

		Ingestion_Cron::unschedule_processing();
		$this->assertFalse( Ingestion_Cron::is_scheduled() );
	}

	public function test_process_queue_processes_syncs(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		$results = Ingestion_Cron::process_queue();

		$this->assertSame( 1, $results['synced'] );
		$this->assertSame( 0, $results['deleted'] );
		$this->assertSame( 0, $results['failed'] );

		// Should be dequeued.
		$this->assertEmpty( Ingestion_Queue::get_queued_for_sync() );
	}

	public function test_process_queue_processes_deletions(): void {
		$this->mock_http_success();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Mark as ingested and queue for deletion.
		update_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, time() );
		Ingestion_Queue::queue_for_delete( $post->ID );

		$results = Ingestion_Cron::process_queue();

		$this->assertSame( 1, $results['deleted'] );
		$this->assertSame( 0, $results['synced'] );

		// Should be dequeued.
		$this->assertEmpty( Ingestion_Queue::get_queued_for_delete() );
	}

	public function test_process_queue_respects_batch_size(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		// Create 5 posts.
		for ( $i = 0; $i < 5; $i++ ) {
			$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
			Ingestion_Queue::queue_for_sync( $post->ID );
		}

		// Process only 3.
		$results = Ingestion_Cron::process_queue( 3 );

		$this->assertSame( 3, $results['synced'] );

		// 2 should remain.
		$remaining = Ingestion_Queue::get_queued_for_sync();
		$this->assertCount( 2, $remaining );
	}

	public function test_process_queue_clamps_negative_batch_size(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		// Create 3 posts.
		$this->factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		// Start bulk sync.
		Ingestion_Sync_Progress::start( 3, [ 'post' ] );

		// Process with -1. If not clamped, it would process all 3 posts (posts_per_page=-1).
		// If clamped to 1, it should only process 1 post.
		$results = Ingestion_Cron::process_queue( -1 );

		$this->assertSame( 1, $results['synced'] );
		$this->assertTrue( Ingestion_Sync_Progress::is_running() );

		$progress = Ingestion_Sync_Progress::get();
		$this->assertSame( 1, $progress['processed'] );
	}

	public function test_process_queue_unschedules_when_empty(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		// Schedule cron.
		Ingestion_Cron::schedule_processing();
		$this->assertTrue( Ingestion_Cron::is_scheduled() );

		// Process queue.
		Ingestion_Cron::process_queue();

		// Should be unscheduled since queue is empty.
		$this->assertFalse( Ingestion_Cron::is_scheduled() );
	}

	public function test_process_queue_skips_deleted_posts(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		// Verify the post is queued.
		$this->assertNotEmpty( Ingestion_Queue::get_queued_for_sync() );

		// Delete the post before processing.
		// Note: wp_delete_post also deletes post meta, so the queue entry is removed.
		wp_delete_post( $post->ID, true );

		// Queue should be empty since post meta was deleted with the post.
		$this->assertEmpty( Ingestion_Queue::get_queued_for_sync() );

		$results = Ingestion_Cron::process_queue();

		// Nothing to process since queue entry was deleted with post.
		$this->assertSame( 0, $results['synced'] );
		$this->assertSame( 0, $results['skipped'] );
	}

	public function test_add_cron_schedule_adds_custom_interval(): void {
		$schedules = Ingestion_Cron::add_cron_schedule( [] );

		$this->assertArrayHasKey( 'vip_agentforce_ingestion', $schedules );
		$this->assertSame( 60, $schedules['vip_agentforce_ingestion']['interval'] );
	}

	public function test_cron_interval_is_filterable(): void {
		// Filter to increase beyond minimum (1800 = 30 min).
		add_filter( 'vip_agentforce_cron_interval', fn() => 1800 );

		$interval = Ingestion_Cron::get_cron_interval();

		$this->assertSame( 1800, $interval );

		remove_all_filters( 'vip_agentforce_cron_interval' );
	}

	public function test_cron_interval_has_minimum(): void {
		// Try to set interval to 30 seconds (below minimum of 60).
		add_filter( 'vip_agentforce_cron_interval', fn() => 30 );

		$interval = Ingestion_Cron::get_cron_interval();

		// Should be clamped to minimum of 60 seconds (1 minute).
		$this->assertSame( 60, $interval );

		remove_all_filters( 'vip_agentforce_cron_interval' );
	}

	public function test_run_now_processes_queue(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		$results = Ingestion_Cron::run_now();

		$this->assertSame( 1, $results['synced'] );
	}

	public function test_deletions_processed_before_syncs(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$sync_post   = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$delete_post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Queue sync.
		Ingestion_Queue::queue_for_sync( $sync_post->ID );

		// Mark as ingested and queue for deletion.
		update_post_meta( $delete_post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, time() );
		Ingestion_Queue::queue_for_delete( $delete_post->ID );

		$results = Ingestion_Cron::process_queue();

		// Both should be processed.
		$this->assertSame( 1, $results['synced'] );
		$this->assertSame( 1, $results['deleted'] );

		// Verify order: DELETE should come before POST.
		$this->assertCount( 2, $this->captured_requests );
		$this->assertSame( 'DELETE', $this->captured_requests[0]['method'] );
		$this->assertSame( 'POST', $this->captured_requests[1]['method'] );
	}

	public function test_process_queue_processes_bulk_sync_batch(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$this->factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		Ingestion_Sync_Progress::start( 3, [ 'post' ] );

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 3, $results['synced'] );
		$this->assertFalse( Ingestion_Sync_Progress::is_running() );

		$progress = Ingestion_Sync_Progress::get();
		$this->assertSame( 'completed', $progress['status'] );
	}

	public function test_bulk_sync_respects_batch_size(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$this->factory()->post->create_many( 5, [ 'post_status' => 'publish' ] );

		Ingestion_Sync_Progress::start( 5, [ 'post' ] );

		// Process only 3
		$results = Ingestion_Cron::process_queue( 3 );

		$this->assertSame( 3, $results['synced'] );
		$this->assertTrue( Ingestion_Sync_Progress::is_running() );

		$progress = Ingestion_Sync_Progress::get();
		$this->assertSame( 3, $progress['processed'] );
		$this->assertGreaterThan( 0, $progress['last_post_id'] );

		// Process remaining 2
		$results = Ingestion_Cron::process_queue( 3 );

		$this->assertSame( 2, $results['synced'] );
		$this->assertFalse( Ingestion_Sync_Progress::is_running() );
	}

	public function test_bulk_sync_writes_live_cache_without_extra_option_writes(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$this->factory()->post->create_many( 5, [ 'post_status' => 'publish' ] );

		Ingestion_Sync_Progress::start( 5, [ 'post' ] );

		$option_updates        = 0;
		$count_progress_update = function ( $value ) use ( &$option_updates ) {
			++$option_updates;
			return $value;
		};

		add_filter( 'pre_update_option_' . Ingestion_Sync_Progress::OPTION_NAME, $count_progress_update );

		Ingestion_Cron::process_queue( 3 );

		remove_filter( 'pre_update_option_' . Ingestion_Sync_Progress::OPTION_NAME, $count_progress_update );

		$progress = Ingestion_Sync_Progress::get();
		$sources  = Ingestion_Sync_Progress::get_progress_sources( $progress );

		$this->assertSame( 1, $option_updates );
		$this->assertSame( 3, $progress['processed'] );
		$this->assertTrue( $sources['cache']['available'] );
		$this->assertSame( 3, $sources['cache']['processed'] );
	}

	public function test_bulk_sync_cursor_paginates_correctly(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		// Create 5 posts and capture IDs
		$post_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$post_ids[] = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		}
		sort( $post_ids );

		Ingestion_Sync_Progress::start( 5, [ 'post' ] );

		// Batch 1 (2 posts)
		Ingestion_Cron::process_queue( 2 );
		$progress = Ingestion_Sync_Progress::get();
		$this->assertEquals( $post_ids[1], $progress['last_post_id'] );

		// Batch 2 (2 posts)
		Ingestion_Cron::process_queue( 2 );
		$progress = Ingestion_Sync_Progress::get();
		$this->assertEquals( $post_ids[3], $progress['last_post_id'] );

		// Batch 3 (1 post)
		$results = Ingestion_Cron::process_queue( 2 );
		$this->assertSame( 1, $results['synced'] );
		$this->assertFalse( Ingestion_Sync_Progress::is_running() );
	}

	public function test_bulk_sync_retryable_failure_keeps_partial_final_batch_running(): void {
		$this->mock_http_500();
		$this->setup_ingestion_filters();

		$this->factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );
		Ingestion_Sync_Progress::start( 3, [ 'post' ] );

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 1, $results['failed'] );
		$this->assertTrue( Ingestion_Sync_Progress::is_running(), 'A retryable failure must not mark an unfinished final bulk-sync batch completed.' );
		$this->assertCount( 1, $this->captured_requests, 'Bulk sync should stop the batch once retry backoff starts.' );

		$progress = Ingestion_Sync_Progress::get();
		$this->assertSame( Ingestion_Sync_Progress::STATUS_RUNNING, $progress['status'] );
		$this->assertSame( 1, $progress['processed'] );
	}

	public function test_bulk_sync_keeps_cron_scheduled(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$this->factory()->post->create_many( 5, [ 'post_status' => 'publish' ] );

		Ingestion_Sync_Progress::start( 5, [ 'post' ] );
		Ingestion_Cron::schedule_processing();

		// Process partial batch
		Ingestion_Cron::process_queue( 3 );

		$this->assertTrue( Ingestion_Cron::is_scheduled() );
	}

	public function test_bulk_sync_does_not_run_when_not_active(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$this->factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		// Don't start sync

		$results = Ingestion_Cron::process_queue();

		$this->assertSame( 0, $results['synced'] );
	}

	public function test_reschedule_stale_interval_when_too_far_in_future(): void {
		// Schedule with a timestamp 15 minutes in the future (simulating old 15-min interval).
		wp_schedule_event( time() + 900, 'vip_agentforce_ingestion', Ingestion_Cron::CRON_HOOK );
		$this->assertTrue( Ingestion_Cron::is_scheduled() );

		$old_next = wp_next_scheduled( Ingestion_Cron::CRON_HOOK );
		$this->assertGreaterThan( time() + 800, $old_next );

		// Queue an item so the cron stays scheduled.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		// This should detect the stale timestamp and reschedule.
		Ingestion_Cron::maybe_schedule_on_init();

		$new_next = wp_next_scheduled( Ingestion_Cron::CRON_HOOK );
		// New timestamp should be close to now (within a few seconds).
		$this->assertLessThanOrEqual( time() + 5, $new_next );
	}

	public function test_reschedule_stale_interval_when_overdue(): void {
		// Schedule with a timestamp 5 minutes in the past (overdue beyond 2× interval).
		wp_schedule_event( time() - 300, 'vip_agentforce_ingestion', Ingestion_Cron::CRON_HOOK );
		$this->assertTrue( Ingestion_Cron::is_scheduled() );

		// Queue an item so the cron stays scheduled.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		// This should detect the overdue timestamp and reschedule.
		Ingestion_Cron::maybe_schedule_on_init();

		$new_next = wp_next_scheduled( Ingestion_Cron::CRON_HOOK );
		// New timestamp should be close to now (within a few seconds).
		$this->assertLessThanOrEqual( time() + 5, $new_next );
		$this->assertGreaterThanOrEqual( time() - 2, $new_next );
	}

	public function test_no_reschedule_when_interval_is_normal(): void {
		// Schedule with a timestamp 30 seconds from now (within 2× of 60s interval).
		$expected_time = time() + 30;
		wp_schedule_event( $expected_time, 'vip_agentforce_ingestion', Ingestion_Cron::CRON_HOOK );

		// Queue an item so maybe_schedule_on_init doesn't skip.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		Ingestion_Cron::maybe_schedule_on_init();

		// Timestamp should be unchanged.
		$next = wp_next_scheduled( Ingestion_Cron::CRON_HOOK );
		$this->assertSame( $expected_time, $next );
	}

	public function test_process_queue_handles_queue_and_bulk_sync_together(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		// Queue one post for individual sync.
		$queued_post = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $queued_post );

		// Create 2 more posts for bulk sync.
		$this->factory()->post->create_many( 2, [ 'post_status' => 'publish' ] );

		// Start bulk sync (cursor at 0 will find all 3 posts).
		Ingestion_Sync_Progress::start( 3, [ 'post' ] );

		$results = Ingestion_Cron::process_queue( 10 );

		// 1 from queue + 3 from bulk sync (re-syncs queued post too) = 4 total.
		// Queue processing and bulk sync are independent — bulk sync processes
		// all posts by cursor, even those already handled by the queue.
		$this->assertSame( 4, $results['synced'] );
	}

	// =========================================================================
	// Retry-or-cap behavior
	//
	// When SF returns a transient failure (429, 408, 5xx) the queue item must
	// stay in place so the next cron tick picks it up. After
	// `Ingestion_Queue::MAX_RETRYABLE_ATTEMPTS` consecutive failures the item
	// is dequeued, the failure event fires, and the post stops retrying until
	// it's saved again.
	// =========================================================================

	/**
	 * Mock SF returning 429 once and capture the request.
	 */
	private function mock_http_429(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'test.salesforce.com' ) === false ) {
					return $preempt;
				}
				$this->captured_requests[] = [
					'url'    => $url,
					'method' => $args['method'] ?? 'GET',
					'body'   => $args['body'] ?? '',
				];
				return [
					'response' => [
						'code'    => 429,
						'message' => 'Too Many Requests',
					],
					// Intentionally no Retry-After: client falls back to a
					// shared exponential retry block.
					'headers'  => [],
					'body'     => '',
				];
			},
			10,
			3
		);
	}

	/**
	 * Mock SF returning 500 and capture the request.
	 */
	private function mock_http_500(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'test.salesforce.com' ) === false ) {
					return $preempt;
				}
				$this->captured_requests[] = [
					'url'    => $url,
					'method' => $args['method'] ?? 'GET',
					'body'   => $args['body'] ?? '',
				];
				return [
					'response' => [
						'code'    => 500,
						'message' => 'Server Error',
					],
					'headers'  => [],
					'body'     => '',
				];
			},
			10,
			3
		);
	}

	public function test_retryable_sync_failure_keeps_post_in_queue_and_increments_attempts(): void {
		$this->mock_http_429();
		$this->setup_ingestion_filters();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		// Clear any cache block left over from an earlier test or run.
		wp_cache_delete( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );

		$results = Ingestion_Cron::process_queue( 10 );

		// Cron sees a retryable failure; counted as skipped (not failed) so
		// we don't pollute "permanently failed" metrics.
		$this->assertSame( 1, $results['skipped'] );
		$this->assertSame( 0, $results['failed'] );

		// Post stays queued for the next tick.
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );
		$this->assertSame( 1, Ingestion_Queue::get_sync_attempts( $post->ID ) );
	}

	public function test_active_retry_backoff_skips_queue_without_incrementing_attempts(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		wp_cache_set( 'vip_agentforce_rate_limit_blocked_until', microtime( true ) + 60, 'vip_agentforce', 300 );

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 0, $results['synced'] );
		$this->assertSame( 0, $results['failed'] );
		$this->assertSame( 0, Ingestion_Queue::get_sync_attempts( $post->ID ), 'Deferred queue ticks must not burn retry attempts.' );
		$this->assertCount( 0, $this->captured_requests, 'Cron should not call Salesforce while shared backoff is active.' );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );
	}

	public function test_retryable_failure_stops_current_batch_before_next_sync(): void {
		$this->mock_http_500();
		$this->setup_ingestion_filters();

		$first_post  = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$second_post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $first_post->ID );
		Ingestion_Queue::queue_for_sync( $second_post->ID );
		update_post_meta( $first_post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, 1 );
		update_post_meta( $second_post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, 2 );

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 1, $results['skipped'] );
		$this->assertCount( 1, $this->captured_requests, 'Cron should stop the batch once retry backoff is active.' );
		$this->assertSame( 1, Ingestion_Queue::get_sync_attempts( $first_post->ID ) );
		$this->assertSame( 0, Ingestion_Queue::get_sync_attempts( $second_post->ID ) );
		$this->assertNotEmpty( get_post_meta( $second_post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );
	}

	public function test_retry_cap_exhausted_stops_current_batch_before_next_sync(): void {
		$this->mock_http_500();
		$this->setup_ingestion_filters();

		$first_post  = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$second_post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $first_post->ID );
		Ingestion_Queue::queue_for_sync( $second_post->ID );
		update_post_meta( $first_post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, 1 );
		update_post_meta( $second_post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, 2 );
		update_post_meta( $first_post->ID, Ingestion_Queue::META_KEY_SYNC_ATTEMPTS, Ingestion_Queue::MAX_RETRYABLE_ATTEMPTS - 1 );

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 1, $results['failed'] );
		$this->assertCount( 1, $this->captured_requests, 'A cap-hit retryable failure still starts shared backoff, so cron should stop before the next item.' );
		$this->assertSame( 0, Ingestion_Queue::get_sync_attempts( $second_post->ID ) );
		$this->assertNotEmpty( get_post_meta( $second_post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );
	}

	public function test_retryable_sync_failure_does_not_fire_ingestion_failed_event(): void {
		$this->mock_http_429();
		$this->setup_ingestion_filters();

		$fired = false;
		add_action(
			'vip_agentforce_post_ingestion_failed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );
		wp_cache_delete( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );

		Ingestion_Cron::process_queue( 10 );

		$this->assertFalse(
			$fired,
			'Failure event must not fire on a retryable failure — cron will retry it next tick.'
		);
	}

	public function test_sync_retry_cap_exhausted_dequeues_and_fires_failure_event(): void {
		$this->mock_http_429();
		$this->setup_ingestion_filters();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		// Pre-seed the attempt counter to one short of the cap so this
		// tick is the final one and we don't spend N minutes in the test.
		update_post_meta(
			$post->ID,
			Ingestion_Queue::META_KEY_SYNC_ATTEMPTS,
			Ingestion_Queue::MAX_RETRYABLE_ATTEMPTS - 1
		);
		wp_cache_delete( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );

		$fired = false;
		add_action(
			'vip_agentforce_post_ingestion_failed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 1, $results['failed'], 'Cap-hit attempt should count as a permanent failure.' );
		$this->assertTrue( $fired, 'Failure event must fire when the retry cap is exhausted.' );

		// Post is dequeued; both queue meta keys are gone.
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );
		$this->assertSame( 0, Ingestion_Queue::get_sync_attempts( $post->ID ) );
	}

	public function test_permanent_sync_failure_dequeues_immediately_and_fires_event(): void {
		// 4xx (other than 408/429) is permanent — no retry, fire event now.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'test.salesforce.com' ) === false ) {
					return $preempt;
				}
				return [
					'response' => [
						'code'    => 401,
						'message' => 'Unauthorized',
					],
					'headers'  => [],
					'body'     => '',
				];
			},
			10,
			3
		);
		$this->setup_ingestion_filters();

		$fired = false;
		add_action(
			'vip_agentforce_post_ingestion_failed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 1, $results['failed'] );
		$this->assertTrue( $fired, 'Permanent failures fire the event on the first cron pass.' );

		// Dequeued immediately — no point parking permanent failures.
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );
	}

	public function test_retryable_delete_failure_keeps_item_in_queue(): void {
		$this->mock_http_429();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_delete( $post->ID );
		wp_cache_delete( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );

		$fired = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 0, $results['failed'] );
		$this->assertSame( 1, $results['skipped'] );
		$this->assertFalse( $fired, 'Deletion failure event must not fire on retryable failures.' );

		// Item still in delete queue for next tick.
		$queued = Ingestion_Queue::get_queued_for_delete();
		$this->assertCount( 1, $queued );
		$this->assertSame( 1, Ingestion_Queue::get_delete_attempts( $post->ID ) );
	}

	public function test_retryable_delete_failure_stops_current_batch_before_next_delete(): void {
		$this->mock_http_500();

		$first_post  = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$second_post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_delete( $first_post->ID );
		Ingestion_Queue::queue_for_delete( $second_post->ID );

		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		foreach ( $queue as &$item ) {
			$item['queued_at'] = $item['post_id'] === $first_post->ID ? 1 : 2;
		}
		unset( $item );
		update_option( Ingestion_Queue::OPTION_DELETE_QUEUE, $queue, false );

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 1, $results['skipped'] );
		$this->assertCount( 1, $this->captured_requests, 'Cron should stop delete processing once retry backoff starts.' );
		$this->assertSame( 1, Ingestion_Queue::get_delete_attempts( $first_post->ID ) );
		$this->assertSame( 0, Ingestion_Queue::get_delete_attempts( $second_post->ID ) );
	}

	public function test_delete_retry_cap_exhausted_dequeues_and_fires_failure_event(): void {
		$this->mock_http_429();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_delete( $post->ID );

		// Pre-seed attempts on the delete queue entry so this tick is the
		// last one before cap.
		$queue                           = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$record_id                       = '101_1_' . $post->ID; // VIP_GO_APP_ID is 101 in the test bootstrap.
		$queue[ $record_id ]['attempts'] = Ingestion_Queue::MAX_RETRYABLE_ATTEMPTS - 1;
		update_option( Ingestion_Queue::OPTION_DELETE_QUEUE, $queue, false );
		wp_cache_delete( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );

		$fired = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 1, $results['failed'] );
		$this->assertTrue( $fired, 'Cap-hit on delete fires the deletion failure event.' );

		// Delete queue entry gone.
		$queued = Ingestion_Queue::get_queued_for_delete();
		$this->assertCount( 0, $queued );
	}

	public function test_delete_retry_cap_exhausted_stops_current_batch_before_next_delete(): void {
		$this->mock_http_500();

		$first_post  = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$second_post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_delete( $first_post->ID );
		Ingestion_Queue::queue_for_delete( $second_post->ID );

		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		foreach ( $queue as &$item ) {
			$item['queued_at'] = $item['post_id'] === $first_post->ID ? 1 : 2;
			if ( $item['post_id'] === $first_post->ID ) {
				$item['attempts'] = Ingestion_Queue::MAX_RETRYABLE_ATTEMPTS - 1;
			}
		}
		unset( $item );
		update_option( Ingestion_Queue::OPTION_DELETE_QUEUE, $queue, false );

		$results = Ingestion_Cron::process_queue( 10 );

		$this->assertSame( 1, $results['failed'] );
		$this->assertCount( 1, $this->captured_requests, 'A cap-hit delete failure still starts shared backoff, so cron should stop before the next delete.' );
		$this->assertSame( 0, Ingestion_Queue::get_delete_attempts( $first_post->ID ) );
		$this->assertSame( 0, Ingestion_Queue::get_delete_attempts( $second_post->ID ) );
	}
}
