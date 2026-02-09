<?php
/**
 * Tests for Ingestion_Cron class.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Cron;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Queue;
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

		// Unschedule cron.
		Ingestion_Cron::unschedule_processing();
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
}
