<?php
/**
 * Tests for Ingestion_Sync_Progress class.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Sync_Progress;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_Sync_Progress_Test extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		Logger::disable();
	}

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();
		Ingestion_Sync_Progress::reset();
	}

	public function test_get_returns_null_when_no_sync_initiated(): void {
		$this->assertNull( Ingestion_Sync_Progress::get() );
	}

	public function test_start_creates_progress_with_correct_initial_state(): void {
		$total      = 100;
		$post_types = [ 'post', 'page' ];

		$started = Ingestion_Sync_Progress::start( $total, $post_types );

		$this->assertTrue( $started );

		$progress = Ingestion_Sync_Progress::get();

		$this->assertNotNull( $progress );
		$this->assertSame( Ingestion_Sync_Progress::STATUS_RUNNING, $progress['status'] );
		$this->assertSame( $total, $progress['total'] );
		$this->assertSame( 0, $progress['processed'] );
		$this->assertSame( 0, $progress['synced'] );
		$this->assertSame( 0, $progress['skipped'] );
		$this->assertSame( 0, $progress['failed'] );
		$this->assertSame( 0, $progress['deleted'] );
		$this->assertSame( 0, $progress['last_post_id'] );
		$this->assertSame( $post_types, $progress['post_types'] );
		$this->assertNotEmpty( $progress['sync_id'] );
		$this->assertNotNull( $progress['started_at'] );
		$this->assertNull( $progress['completed_at'] );
	}

	public function test_start_returns_false_when_sync_already_running(): void {
		// First start should succeed.
		$this->assertTrue( Ingestion_Sync_Progress::start( 100 ) );

		// Capture initial state.
		$initial_progress = Ingestion_Sync_Progress::get();

		// Second start should fail.
		$this->assertFalse( Ingestion_Sync_Progress::start( 200 ) );

		// Progress should be unchanged.
		$current_progress = Ingestion_Sync_Progress::get();
		$this->assertSame( $initial_progress, $current_progress );
		$this->assertSame( 100, $current_progress['total'] );
	}

	public function test_start_succeeds_after_completed_sync_is_reset(): void {
		// Start and complete a sync.
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::complete();

		// Reset.
		Ingestion_Sync_Progress::reset();

		// Start new sync.
		$this->assertTrue( Ingestion_Sync_Progress::start( 200 ) );

		$progress = Ingestion_Sync_Progress::get();
		$this->assertSame( 200, $progress['total'] );
	}

	public function test_is_running_returns_false_when_no_sync(): void {
		$this->assertFalse( Ingestion_Sync_Progress::is_running() );
	}

	public function test_is_running_returns_true_when_running(): void {
		Ingestion_Sync_Progress::start( 100 );
		$this->assertTrue( Ingestion_Sync_Progress::is_running() );
	}

	public function test_is_running_returns_false_when_completed(): void {
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::complete();
		$this->assertFalse( Ingestion_Sync_Progress::is_running() );
	}

	public function test_is_running_returns_false_when_failed(): void {
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::fail( 'test' );
		$this->assertFalse( Ingestion_Sync_Progress::is_running() );
	}

	public function test_update_increments_counters(): void {
		Ingestion_Sync_Progress::start( 100 );

		$batch_results = [
			'synced'  => 5,
			'skipped' => 2,
			'failed'  => 1,
			'deleted' => 0,
		];
		$last_post_id  = 42;

		Ingestion_Sync_Progress::update( $batch_results, $last_post_id );

		$progress = Ingestion_Sync_Progress::get();

		$this->assertSame( 8, $progress['processed'] ); // 5 + 2 + 1 + 0
		$this->assertSame( 5, $progress['synced'] );
		$this->assertSame( 2, $progress['skipped'] );
		$this->assertSame( 1, $progress['failed'] );
		$this->assertSame( 0, $progress['deleted'] );
		$this->assertSame( 42, $progress['last_post_id'] );
	}

	public function test_update_accumulates_across_batches(): void {
		Ingestion_Sync_Progress::start( 100 );

		// First batch.
		Ingestion_Sync_Progress::update(
			[
				'synced'  => 5,
				'skipped' => 1,
				'failed'  => 0,
				'deleted' => 0,
			],
			10
		);

		// Second batch.
		Ingestion_Sync_Progress::update(
			[
				'synced'  => 3,
				'skipped' => 0,
				'failed'  => 1,
				'deleted' => 0,
			],
			20
		);

		$progress = Ingestion_Sync_Progress::get();

		$this->assertSame( 10, $progress['processed'] ); // 6 from first batch + 4 from second.
		$this->assertSame( 8, $progress['synced'] ); // 5 from first batch + 3 from second.
		$this->assertSame( 1, $progress['skipped'] ); // 1 from first batch only.
		$this->assertSame( 1, $progress['failed'] ); // 1 from second batch only.
		$this->assertSame( 20, $progress['last_post_id'] );
	}

	public function test_update_live_progress_does_not_change_stored_progress(): void {
		Ingestion_Sync_Progress::start( 100 );

		$stored_progress = Ingestion_Sync_Progress::get();

		Ingestion_Sync_Progress::update_live_progress(
			$stored_progress,
			[
				'synced'  => 7,
				'skipped' => 1,
				'failed'  => 0,
				'deleted' => 0,
			],
			55
		);

		$progress = Ingestion_Sync_Progress::get();

		$this->assertSame( 0, $progress['processed'] );
		$this->assertSame( 0, $progress['synced'] );
		$this->assertSame( 0, $progress['skipped'] );
		$this->assertSame( 0, $progress['last_post_id'] );
	}

	public function test_progress_sources_prefers_cache_when_cache_is_ahead(): void {
		Ingestion_Sync_Progress::start( 100 );

		$stored_progress = Ingestion_Sync_Progress::get();

		Ingestion_Sync_Progress::update_live_progress(
			$stored_progress,
			[
				'synced'  => 7,
				'skipped' => 1,
				'failed'  => 0,
				'deleted' => 0,
			],
			55
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertSame( 0, $sources['stored']['processed'] );
		$this->assertTrue( $sources['cache']['available'] );
		$this->assertTrue( $sources['cache']['valid'] );
		$this->assertSame( 8, $sources['cache']['processed'] );
		$this->assertSame( 'cache', $sources['effective']['source'] );
		$this->assertSame( 'cache_ahead_of_stored', $sources['effective']['reason'] );
		$this->assertSame( 8, $sources['effective']['processed'] );
		$this->assertSame( 8.0, $sources['effective']['percentage'] );
	}

	public function test_live_progress_advances_within_each_hundred_post_batch_and_falls_back_to_stored_batch_boundary(): void {
		Ingestion_Sync_Progress::start( 200 );

		$first_batch_progress = Ingestion_Sync_Progress::get();

		Ingestion_Sync_Progress::update_live_progress(
			$first_batch_progress,
			[
				'synced'  => 10,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			10
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertSame( 0, $sources['stored']['processed'] );
		$this->assertSame( 'cache', $sources['effective']['source'] );
		$this->assertSame( 10, $sources['effective']['processed'] );

		Ingestion_Sync_Progress::clear_live_progress();

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertSame( 'stored', $sources['effective']['source'] );
		$this->assertSame( 0, $sources['effective']['processed'] );

		Ingestion_Sync_Progress::update(
			[
				'synced'  => 100,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			100
		);

		$second_batch_progress = Ingestion_Sync_Progress::get();

		$this->assertSame( 100, $second_batch_progress['processed'] );

		Ingestion_Sync_Progress::update_live_progress(
			$second_batch_progress,
			[
				'synced'  => 11,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			111
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertSame( 100, $sources['stored']['processed'] );
		$this->assertSame( 'cache', $sources['effective']['source'] );
		$this->assertSame( 111, $sources['effective']['processed'] );

		Ingestion_Sync_Progress::clear_live_progress();

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertSame( 'stored', $sources['effective']['source'] );
		$this->assertSame( 100, $sources['effective']['processed'] );
	}

	public function test_progress_sources_prefers_stored_when_cache_is_not_ahead(): void {
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::update(
			[
				'synced'  => 10,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			70
		);

		$stored_progress = Ingestion_Sync_Progress::get();

		Ingestion_Sync_Progress::update_live_progress(
			$stored_progress,
			[
				'synced'  => 0,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			70
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( $stored_progress );

		$this->assertTrue( $sources['cache']['valid'] );
		$this->assertSame( 10, $sources['cache']['processed'] );
		$this->assertSame( 'stored', $sources['effective']['source'] );
		$this->assertSame( 'cache_not_ahead_of_stored', $sources['effective']['reason'] );
		$this->assertSame( 10, $sources['effective']['processed'] );
	}

	public function test_progress_sources_prefers_stored_when_cache_is_lower_than_stored(): void {
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::update(
			[
				'synced'  => 10,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			70
		);

		$cached_progress                 = Ingestion_Sync_Progress::get();
		$cached_progress['processed']    = 5;
		$cached_progress['synced']       = 5;
		$cached_progress['last_post_id'] = 35;
		$cached_progress['blog_id']      = get_current_blog_id();

		wp_cache_set(
			Ingestion_Sync_Progress::get_live_progress_cache_key(),
			$cached_progress,
			Ingestion_Sync_Progress::LIVE_PROGRESS_CACHE_GROUP,
			600
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertTrue( $sources['cache']['valid'] );
		$this->assertSame( 5, $sources['cache']['processed'] );
		$this->assertSame( 'stored', $sources['effective']['source'] );
		$this->assertSame( 'cache_not_ahead_of_stored', $sources['effective']['reason'] );
		$this->assertSame( 10, $sources['effective']['processed'] );
	}

	public function test_progress_sources_prefers_stored_when_cache_is_missing(): void {
		Ingestion_Sync_Progress::start( 100 );

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertFalse( $sources['cache']['available'] );
		$this->assertFalse( $sources['cache']['valid'] );
		$this->assertSame( 'cache_missing', $sources['effective']['reason'] );
		$this->assertSame( 'stored', $sources['effective']['source'] );
	}

	public function test_progress_sources_prefers_stored_when_cache_is_malformed(): void {
		Ingestion_Sync_Progress::start( 100 );

		wp_cache_set(
			Ingestion_Sync_Progress::get_live_progress_cache_key(),
			'not-progress',
			Ingestion_Sync_Progress::LIVE_PROGRESS_CACHE_GROUP,
			600
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertTrue( $sources['cache']['available'] );
		$this->assertFalse( $sources['cache']['valid'] );
		$this->assertSame( 'cache_malformed', $sources['cache']['reason'] );
		$this->assertSame( 'stored', $sources['effective']['source'] );
	}

	public function test_progress_sources_prefers_stored_when_cache_total_does_not_match(): void {
		Ingestion_Sync_Progress::start( 100 );

		$cached_progress            = Ingestion_Sync_Progress::get();
		$cached_progress['total']   = 200;
		$cached_progress['blog_id'] = get_current_blog_id();

		wp_cache_set(
			Ingestion_Sync_Progress::get_live_progress_cache_key(),
			$cached_progress,
			Ingestion_Sync_Progress::LIVE_PROGRESS_CACHE_GROUP,
			600
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertTrue( $sources['cache']['available'] );
		$this->assertFalse( $sources['cache']['valid'] );
		$this->assertSame( 'cache_total_mismatch', $sources['cache']['reason'] );
		$this->assertSame( 'stored', $sources['effective']['source'] );
	}

	public function test_progress_sources_prefers_stored_when_cache_sync_id_does_not_match(): void {
		Ingestion_Sync_Progress::start( 100 );

		$cached_progress                 = Ingestion_Sync_Progress::get();
		$cached_progress['sync_id']      = 'old-sync-id';
		$cached_progress['processed']    = 5;
		$cached_progress['synced']       = 5;
		$cached_progress['last_post_id'] = 35;
		$cached_progress['blog_id']      = get_current_blog_id();

		wp_cache_set(
			Ingestion_Sync_Progress::get_live_progress_cache_key(),
			$cached_progress,
			Ingestion_Sync_Progress::LIVE_PROGRESS_CACHE_GROUP,
			600
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertTrue( $sources['cache']['available'] );
		$this->assertFalse( $sources['cache']['valid'] );
		$this->assertSame( 'cache_sync_mismatch', $sources['cache']['reason'] );
		$this->assertSame( 'stored', $sources['effective']['source'] );
	}

	public function test_progress_sources_prefers_stored_when_cache_is_stale(): void {
		Ingestion_Sync_Progress::start( 100 );

		$stored_progress               = Ingestion_Sync_Progress::get();
		$stored_progress['updated_at'] = time() - Ingestion_Sync_Progress::LIVE_PROGRESS_MAX_AGE - 10;

		update_option( Ingestion_Sync_Progress::OPTION_NAME, $stored_progress, false );

		$cached_progress                 = $stored_progress;
		$cached_progress['processed']    = 5;
		$cached_progress['synced']       = 5;
		$cached_progress['last_post_id'] = 35;
		$cached_progress['updated_at']   = time() - Ingestion_Sync_Progress::LIVE_PROGRESS_MAX_AGE - 1;
		$cached_progress['blog_id']      = get_current_blog_id();

		wp_cache_set(
			Ingestion_Sync_Progress::get_live_progress_cache_key(),
			$cached_progress,
			Ingestion_Sync_Progress::LIVE_PROGRESS_CACHE_GROUP,
			Ingestion_Sync_Progress::LIVE_PROGRESS_CACHE_TTL
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertTrue( $sources['cache']['available'] );
		$this->assertFalse( $sources['cache']['valid'] );
		$this->assertSame( 'cache_stale', $sources['cache']['reason'] );
		$this->assertSame( 'stored', $sources['effective']['source'] );
		$this->assertSame( 0, $sources['effective']['processed'] );
	}

	public function test_update_live_progress_does_not_repopulate_cache_after_new_sync_starts(): void {
		Ingestion_Sync_Progress::start( 100 );
		$old_progress = Ingestion_Sync_Progress::get();

		Ingestion_Sync_Progress::reset();
		Ingestion_Sync_Progress::start( 100 );

		Ingestion_Sync_Progress::update_live_progress(
			$old_progress,
			[
				'synced'  => 5,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			35
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertFalse( $sources['cache']['available'] );
		$this->assertSame( 'stored', $sources['effective']['source'] );
		$this->assertSame( 0, $sources['effective']['processed'] );
	}

	public function test_progress_sources_prefers_stored_when_cache_counters_are_incoherent(): void {
		Ingestion_Sync_Progress::start( 100 );

		$cached_progress                 = Ingestion_Sync_Progress::get();
		$cached_progress['processed']    = 8;
		$cached_progress['synced']       = 5;
		$cached_progress['last_post_id'] = 35;
		$cached_progress['blog_id']      = get_current_blog_id();

		wp_cache_set(
			Ingestion_Sync_Progress::get_live_progress_cache_key(),
			$cached_progress,
			Ingestion_Sync_Progress::LIVE_PROGRESS_CACHE_GROUP,
			600
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertTrue( $sources['cache']['available'] );
		$this->assertFalse( $sources['cache']['valid'] );
		$this->assertSame( 'cache_counter_mismatch', $sources['cache']['reason'] );
		$this->assertSame( 'stored', $sources['effective']['source'] );
	}

	public function test_progress_sources_prefers_stored_when_cache_processed_exceeds_total(): void {
		Ingestion_Sync_Progress::start( 100 );

		$cached_progress                 = Ingestion_Sync_Progress::get();
		$cached_progress['processed']    = 101;
		$cached_progress['synced']       = 101;
		$cached_progress['last_post_id'] = 35;
		$cached_progress['blog_id']      = get_current_blog_id();

		wp_cache_set(
			Ingestion_Sync_Progress::get_live_progress_cache_key(),
			$cached_progress,
			Ingestion_Sync_Progress::LIVE_PROGRESS_CACHE_GROUP,
			600
		);

		$sources = Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() );

		$this->assertTrue( $sources['cache']['available'] );
		$this->assertFalse( $sources['cache']['valid'] );
		$this->assertSame( 'cache_processed_exceeds_total', $sources['cache']['reason'] );
		$this->assertSame( 'stored', $sources['effective']['source'] );
	}

	public function test_terminal_and_reset_flows_clear_live_progress(): void {
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::update_live_progress(
			Ingestion_Sync_Progress::get(),
			[
				'synced'  => 1,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			10
		);

		$this->assertTrue(
			Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() )['cache']['available']
		);

		Ingestion_Sync_Progress::complete();

		$this->assertFalse(
			Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() )['cache']['available']
		);

		Ingestion_Sync_Progress::reset();
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::update_live_progress(
			Ingestion_Sync_Progress::get(),
			[
				'synced'  => 1,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			10
		);
		Ingestion_Sync_Progress::fail( 'test failure' );

		$this->assertFalse(
			Ingestion_Sync_Progress::get_progress_sources( Ingestion_Sync_Progress::get() )['cache']['available']
		);

		Ingestion_Sync_Progress::reset();
		$this->assertNull( Ingestion_Sync_Progress::get() );
	}

	public function test_update_does_nothing_when_not_running(): void {
		// Ensure no sync is running.
		Ingestion_Sync_Progress::reset();

		Ingestion_Sync_Progress::update(
			[
				'synced'  => 5,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			10
		);

		$this->assertNull( Ingestion_Sync_Progress::get() );
	}

	public function test_complete_sets_status_and_timestamp(): void {
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::update(
			[
				'synced'  => 5,
				'skipped' => 0,
				'failed'  => 0,
				'deleted' => 0,
			],
			10
		);

		Ingestion_Sync_Progress::complete();

		$progress = Ingestion_Sync_Progress::get();

		$this->assertSame( Ingestion_Sync_Progress::STATUS_COMPLETED, $progress['status'] );
		$this->assertNotNull( $progress['completed_at'] );
	}

	public function test_fail_sets_status_and_reason(): void {
		Ingestion_Sync_Progress::start( 100 );
		$reason = 'API error';

		Ingestion_Sync_Progress::fail( $reason );

		$progress = Ingestion_Sync_Progress::get();

		$this->assertSame( Ingestion_Sync_Progress::STATUS_FAILED, $progress['status'] );
		$this->assertSame( $reason, $progress['error'] );
		$this->assertNotNull( $progress['completed_at'] );
	}

	public function test_reset_clears_progress(): void {
		Ingestion_Sync_Progress::start( 100 );
		$this->assertNotNull( Ingestion_Sync_Progress::get() );

		Ingestion_Sync_Progress::reset();

		$this->assertNull( Ingestion_Sync_Progress::get() );
	}

	public function test_get_summary_no_sync(): void {
		$this->assertSame( 'No sync has been initiated.', Ingestion_Sync_Progress::get_summary() );
	}

	public function test_get_summary_running(): void {
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::update(
			[
				'synced'  => 25,
				'skipped' => 5,
				'failed'  => 0,
				'deleted' => 0,
			],
			30
		);

		$summary = Ingestion_Sync_Progress::get_summary();

		$this->assertStringContainsString( 'in progress', $summary );
		$this->assertStringContainsString( '30/100', $summary );
		$this->assertStringContainsString( '30.0%', $summary );
	}

	public function test_get_summary_completed(): void {
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::complete();

		$summary = Ingestion_Sync_Progress::get_summary();

		$this->assertStringContainsString( 'completed', $summary );
	}

	public function test_get_summary_failed(): void {
		Ingestion_Sync_Progress::start( 100 );
		Ingestion_Sync_Progress::fail( 'Test error' );

		$summary = Ingestion_Sync_Progress::get_summary();

		$this->assertStringContainsString( 'failed', $summary );
		$this->assertStringContainsString( 'Test error', $summary );
	}
}
