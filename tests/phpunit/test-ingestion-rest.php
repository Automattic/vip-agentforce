<?php
/**
 * Tests for Ingestion_REST class.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_API_Client;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_REST;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Sync_Progress;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_REST_Test extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		Logger::disable();
		Ingestion_API_Client::clear_retry_status();

		wp_set_current_user(
			$this->factory()->user->create(
				[
					'role' => 'administrator',
				]
			)
		);

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		do_action( 'rest_api_init' );
		Ingestion_REST::register_routes();
	}

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();
		Ingestion_Sync_Progress::reset();
		Ingestion_API_Client::clear_retry_status();
		wp_set_current_user( 0 );

		global $wp_rest_server;
		$wp_rest_server = null;
	}

	private function request_sync_progress() {
		return rest_do_request(
			new WP_REST_Request(
				'GET',
				'/' . Ingestion_REST::REST_NAMESPACE . '/sync-progress'
			)
		);
	}

	public function test_get_sync_progress_returns_idle_when_no_sync_started(): void {
		$response = $this->request_sync_progress();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Ingestion_Sync_Progress::STATUS_IDLE, $data['status'] );
		$this->assertSame( 'No sync has been initiated.', $data['message'] );
		$this->assertArrayNotHasKey( 'progress_sources', $data );
		$this->assertArrayHasKey( 'retry_backoff', $data );
		$this->assertFalse( $data['retry_backoff']['active'] );
	}

	public function test_get_sync_progress_requires_manage_options_capability(): void {
		wp_set_current_user( 0 );

		$response = $this->request_sync_progress();
		$data     = $response->get_data();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $data['code'] );
	}

	public function test_get_sync_progress_uses_effective_cache_progress_when_cache_is_ahead(): void {
		Ingestion_Sync_Progress::start( 100, [ 'post' ] );

		Ingestion_Sync_Progress::update_live_progress(
			Ingestion_Sync_Progress::get(),
			[
				'synced'  => 7,
				'skipped' => 1,
				'failed'  => 0,
				'deleted' => 0,
			],
			55
		);

		$response = $this->request_sync_progress();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Ingestion_Sync_Progress::STATUS_RUNNING, $data['status'] );
		$this->assertSame( 8, $data['processed'] );
		$this->assertSame( 7, $data['synced'] );
		$this->assertSame( 1, $data['skipped'] );
		$this->assertSame( 55, $data['last_post_id'] );
		$this->assertSame( 8.0, $data['percentage'] );
		$this->assertSame( 'cache', $data['progress_sources']['effective']['source'] );
		$this->assertSame( 8, $data['progress_sources']['effective']['processed'] );
	}

	public function test_get_sync_progress_uses_stored_progress_when_cache_is_lower(): void {
		Ingestion_Sync_Progress::start( 100, [ 'post' ] );
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

		$response = $this->request_sync_progress();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Ingestion_Sync_Progress::STATUS_RUNNING, $data['status'] );
		$this->assertSame( 10, $data['processed'] );
		$this->assertSame( 10, $data['synced'] );
		$this->assertSame( 70, $data['last_post_id'] );
		$this->assertSame( 10.0, $data['percentage'] );
		$this->assertSame( 'stored', $data['progress_sources']['effective']['source'] );
		$this->assertSame( 'cache_behind_stored', $data['progress_sources']['effective']['reason'] );
	}
}
