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

		$this->assertSame( 10, $progress['processed'] ); // (5+1) + (3+1)
		$this->assertSame( 8, $progress['synced'] ); // 5 + 3
		$this->assertSame( 1, $progress['skipped'] ); // 1 + 0
		$this->assertSame( 1, $progress['failed'] ); // 0 + 1
		$this->assertSame( 20, $progress['last_post_id'] );
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
