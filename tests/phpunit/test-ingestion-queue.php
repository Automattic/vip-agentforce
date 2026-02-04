<?php
/**
 * Tests for Ingestion_Queue class.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Cron;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Queue;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_Queue_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Logger::disable();
	}

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();

		// Clean up any queued items.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
				Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC,
				Ingestion_Queue::META_KEY_QUEUED_FOR_DELETE
			)
		);

		// Unschedule cron.
		Ingestion_Cron::unschedule_processing();
	}

	public function test_queue_for_sync_sets_meta(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_sync( $post->ID );

		$meta = get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true );
		$this->assertNotEmpty( $meta, 'Sync queue meta should be set.' );
		$this->assertIsNumeric( $meta, 'Sync queue meta should be a timestamp.' );
	}

	public function test_queue_for_delete_sets_meta_with_record_id(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_delete( $post->ID, $post );

		$meta = get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_DELETE, true );
		$this->assertIsArray( $meta, 'Delete queue meta should be an array.' );
		$this->assertArrayHasKey( 'queued_at', $meta );
		$this->assertArrayHasKey( 'record_id', $meta );
		$this->assertStringContainsString( (string) $post->ID, $meta['record_id'] );
	}

	public function test_queue_for_sync_removes_pending_delete(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// First queue for delete.
		Ingestion_Queue::queue_for_delete( $post->ID, $post );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_DELETE, true ) );

		// Then queue for sync - should remove delete.
		Ingestion_Queue::queue_for_sync( $post->ID );

		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_DELETE, true ) );
	}

	public function test_queue_for_delete_removes_pending_sync(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// First queue for sync.
		Ingestion_Queue::queue_for_sync( $post->ID );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );

		// Then queue for delete - should remove sync.
		Ingestion_Queue::queue_for_delete( $post->ID, $post );

		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_DELETE, true ) );
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );
	}

	public function test_get_queued_for_sync_returns_post_ids(): void {
		$post1 = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$post2 = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_sync( $post1->ID );
		Ingestion_Queue::queue_for_sync( $post2->ID );

		$queued = Ingestion_Queue::get_queued_for_sync();

		$this->assertContains( $post1->ID, $queued );
		$this->assertContains( $post2->ID, $queued );
	}

	public function test_get_queued_for_delete_returns_post_ids_and_record_ids(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_delete( $post->ID, $post );

		$queued = Ingestion_Queue::get_queued_for_delete();

		$this->assertCount( 1, $queued );
		$this->assertSame( $post->ID, $queued[0]['post_id'] );
		$this->assertStringContainsString( (string) $post->ID, $queued[0]['record_id'] );
	}

	public function test_dequeue_sync_removes_meta(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_sync( $post->ID );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );

		Ingestion_Queue::dequeue_sync( $post->ID );
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );
	}

	public function test_dequeue_delete_removes_meta(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_delete( $post->ID, $post );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_DELETE, true ) );

		Ingestion_Queue::dequeue_delete( $post->ID );
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_DELETE, true ) );
	}

	public function test_has_queued_items_returns_true_when_items_exist(): void {
		$this->assertFalse( Ingestion_Queue::has_queued_items() );

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		$this->assertTrue( Ingestion_Queue::has_queued_items() );
	}

	public function test_get_queue_counts_returns_correct_counts(): void {
		$post1 = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$post2 = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$post3 = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_sync( $post1->ID );
		Ingestion_Queue::queue_for_sync( $post2->ID );
		Ingestion_Queue::queue_for_delete( $post3->ID, $post3 );

		$counts = Ingestion_Queue::get_queue_counts();

		$this->assertSame( 2, $counts['sync'] );
		$this->assertSame( 1, $counts['delete'] );
	}

	public function test_handle_save_post_queues_for_sync(): void {
		Ingestion_Queue::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// The factory triggers save_post, which should queue.
		$meta = get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true );
		$this->assertNotEmpty( $meta, 'Post should be queued for sync on save.' );
	}

	public function test_handle_save_post_skips_revisions(): void {
		Ingestion_Queue::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Clear the queue from initial create.
		Ingestion_Queue::dequeue_sync( $post->ID );

		// Create a revision.
		$revision_id = wp_save_post_revision( $post->ID );

		// Revision should not be queued.
		$meta = get_post_meta( $revision_id, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true );
		$this->assertEmpty( $meta, 'Revisions should not be queued for sync.' );
	}

	public function test_handle_before_delete_post_queues_ingested_posts(): void {
		Ingestion_Queue::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Mark as previously ingested.
		update_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, time() );

		// Clear any sync queue.
		Ingestion_Queue::dequeue_sync( $post->ID );

		// Simulate before_delete_post.
		Ingestion_Queue::handle_before_delete_post( $post->ID, $post );

		$meta = get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_DELETE, true );
		$this->assertNotEmpty( $meta, 'Previously ingested post should be queued for deletion.' );
	}

	public function test_handle_before_delete_post_skips_non_ingested_posts(): void {
		Ingestion_Queue::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Don't mark as ingested - just clear sync queue.
		Ingestion_Queue::dequeue_sync( $post->ID );

		// Simulate before_delete_post.
		Ingestion_Queue::handle_before_delete_post( $post->ID, $post );

		$meta = get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_DELETE, true );
		$this->assertEmpty( $meta, 'Non-ingested post should not be queued for deletion.' );
	}

	public function test_get_queued_for_sync_respects_limit(): void {
		// Create 5 posts.
		for ( $i = 0; $i < 5; $i++ ) {
			$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
			Ingestion_Queue::queue_for_sync( $post->ID );
		}

		$queued = Ingestion_Queue::get_queued_for_sync( 3 );

		$this->assertCount( 3, $queued, 'Should respect the limit parameter.' );
	}
}
