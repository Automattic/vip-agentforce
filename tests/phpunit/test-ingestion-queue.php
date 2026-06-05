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
use Automattic\VIP\Salesforce\Agentforce\Utils\Testable_Logger;

class Ingestion_Queue_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Logger::disable();
	}

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();
		remove_all_filters( 'vip_agentforce_ingestion_log_verbosity' );
		delete_option( 'vip_agentforce_ingestion_log_verbosity' );
		Testable_Logger::clear_entries();

		// Clean up sync queue (post meta).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC
			)
		);

		// Clean up delete queue (option).
		delete_option( Ingestion_Queue::OPTION_DELETE_QUEUE );

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

	public function test_queue_for_sync_suppresses_routine_log_in_normal_mode(): void {
		Logger::enable();
		Testable_Logger::clear_entries();
		update_option( 'vip_agentforce_ingestion_log_verbosity', 'normal', false );

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_sync( $post->ID );

		$messages = array_column( Testable_Logger::get_entries(), 'message' );
		$this->assertNotContains( 'Post queued for sync', $messages );
	}

	public function test_queue_for_sync_logs_routine_message_in_verbose_mode(): void {
		Logger::enable();
		Testable_Logger::clear_entries();
		update_option( 'vip_agentforce_ingestion_log_verbosity', 'verbose', false );

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_sync( $post->ID );

		$messages = array_column( Testable_Logger::get_entries(), 'message' );
		$this->assertContains( 'Post queued for sync', $messages );
	}

	public function test_queue_for_delete_stores_in_option(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_delete( $post->ID );

		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertIsArray( $queue, 'Delete queue option should be an array.' );
		$this->assertCount( 1, $queue, 'Delete queue should have one entry.' );

		$entry = reset( $queue );
		$this->assertArrayHasKey( 'queued_at', $entry );
		$this->assertArrayHasKey( 'record_id', $entry );
		$this->assertArrayHasKey( 'post_id', $entry );
		$this->assertStringContainsString( (string) $post->ID, $entry['record_id'] );
	}

	public function test_queue_for_sync_removes_pending_delete(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// First queue for delete.
		Ingestion_Queue::queue_for_delete( $post->ID );
		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( 1, $queue, 'Delete queue should have one entry.' );

		// Then queue for sync - should remove delete.
		Ingestion_Queue::queue_for_sync( $post->ID );

		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );
		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( 0, $queue, 'Delete queue should be empty after sync queue.' );
	}

	public function test_queue_for_delete_removes_pending_sync(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// First queue for sync.
		Ingestion_Queue::queue_for_sync( $post->ID );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, true ) );

		// Then queue for delete - should remove sync.
		Ingestion_Queue::queue_for_delete( $post->ID );

		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( 1, $queue, 'Delete queue should have one entry.' );
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

		Ingestion_Queue::queue_for_delete( $post->ID );

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

	public function test_dequeue_delete_removes_from_option(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_delete( $post->ID );
		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( 1, $queue, 'Delete queue should have one entry.' );

		Ingestion_Queue::dequeue_delete( $post->ID );
		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( 0, $queue, 'Delete queue should be empty after dequeue.' );
	}

	public function test_has_queued_items_returns_true_when_items_exist(): void {
		$this->assertFalse( Ingestion_Queue::has_queued_items() );

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $post->ID );

		$this->assertTrue( Ingestion_Queue::has_queued_items() );
	}

	public function test_has_queued_items_returns_true_for_delete_queue(): void {
		$this->assertFalse( Ingestion_Queue::has_queued_items() );

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_delete( $post->ID );

		$this->assertTrue( Ingestion_Queue::has_queued_items() );
	}

	public function test_get_queue_counts_returns_correct_counts(): void {
		$post1 = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$post2 = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$post3 = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_sync( $post1->ID );
		Ingestion_Queue::queue_for_sync( $post2->ID );
		Ingestion_Queue::queue_for_delete( $post3->ID );

		$counts = Ingestion_Queue::get_queue_counts();

		$this->assertSame( 2, $counts['sync'] );
		$this->assertSame( 1, $counts['delete'] );
	}

	public function test_handle_save_post_queues_for_sync(): void {
		Ingestion_Queue::init();
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

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

		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( 1, $queue, 'Previously ingested post should be queued for deletion.' );
	}

	public function test_handle_before_delete_post_skips_non_ingested_posts(): void {
		Ingestion_Queue::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Don't mark as ingested - just clear sync queue.
		Ingestion_Queue::dequeue_sync( $post->ID );

		// Simulate before_delete_post.
		Ingestion_Queue::handle_before_delete_post( $post->ID, $post );

		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( 0, $queue, 'Non-ingested post should not be queued for deletion.' );
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

	public function test_delete_queue_survives_post_deletion(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Mark as previously ingested.
		update_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, time() );

		// Queue for delete.
		Ingestion_Queue::queue_for_delete( $post->ID );

		$queue_before = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( 1, $queue_before, 'Delete queue should have one entry before deletion.' );

		// Delete the post - this would have cleared post meta in the old implementation.
		wp_delete_post( $post->ID, true );

		// Verify queue entry survives.
		$queue_after = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( 1, $queue_after, 'Delete queue entry should survive post deletion.' );
	}

	public function test_handle_before_delete_post_queues_trashed_ingested_posts(): void {
		Ingestion_Queue::init();

		// Create a published post and mark as ingested.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		update_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, time() );

		// Clear any sync queue from post creation.
		Ingestion_Queue::dequeue_sync( $post->ID );

		// Trash the post (simulates user moving to trash).
		wp_trash_post( $post->ID );
		$trashed_post = get_post( $post->ID );

		// Simulate permanent deletion of the trashed post.
		Ingestion_Queue::handle_before_delete_post( $trashed_post->ID, $trashed_post );

		$queue = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( 1, $queue, 'Trashed-then-deleted ingested post should be queued for Salesforce deletion.' );
	}

	public function test_queue_for_delete_falls_back_to_sync_when_at_capacity(): void {
		// Pre-populate the delete queue to max capacity.
		$queue = [];
		for ( $i = 0; $i < Ingestion_Queue::DELETE_QUEUE_MAX_SIZE; $i++ ) {
			$record_id           = '0_1_' . ( 10000 + $i );
			$queue[ $record_id ] = [
				'post_id'   => 10000 + $i,
				'record_id' => $record_id,
				'queued_at' => time() - $i,
			];
		}
		update_option( Ingestion_Queue::OPTION_DELETE_QUEUE, $queue );

		$this->assertCount( Ingestion_Queue::DELETE_QUEUE_MAX_SIZE, get_option( Ingestion_Queue::OPTION_DELETE_QUEUE ) );

		// Try to queue another post for delete.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_delete( $post->ID );

		// The queue should NOT have grown beyond the max size.
		$queue_after = get_option( Ingestion_Queue::OPTION_DELETE_QUEUE, [] );
		$this->assertCount( Ingestion_Queue::DELETE_QUEUE_MAX_SIZE, $queue_after, 'Queue should not exceed max size.' );

		// The new post should NOT be in the queue (was processed sync).
		$site_id   = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';
		$blog_id   = (string) get_current_blog_id();
		$record_id = $site_id . '_' . $blog_id . '_' . $post->ID;
		$this->assertArrayNotHasKey( $record_id, $queue_after, 'New post should not be queued when at capacity.' );
	}
}
