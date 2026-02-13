<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_CLI;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Sync_Progress;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Cron;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_CLI_Test extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests for verification.
	 *
	 * @var array<int, array{url: string, method: string, body: string}>
	 */
	private array $captured_requests = [];

	/**
	 * Prime Configs cache for deterministic tests without mutating VIP_AGENTFORCE_CONFIGS.
	 *
	 * @param array<string, mixed> $config
	 */
	private function prime_configs_cache( array $config ): void {
		$ref  = new ReflectionClass( Configs::class );
		$prop = $ref->getProperty( 'cached_config' );
		$prop->setAccessible( true );
		$prop->setValue( null, $config );
	}

	public function setUp(): void {
		parent::setUp();
		Logger::disable();
		$this->captured_requests = [];

		// Initialize cron hooks (registers the custom schedule needed by schedule_processing).
		Ingestion_Cron::init();

		// Set up config for API calls via cache priming.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
			]
		);

		// Mock HTTP requests to return success and capture them.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Only mock requests to our test Salesforce instance.
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

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();
		Configs::flush_cache();
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		remove_all_filters( 'vip_agentforce_transform_post' );
		remove_all_filters( 'cron_schedules' );
		remove_all_actions( Ingestion_Cron::CRON_HOOK );
		$this->captured_requests = [];
		Ingestion_Sync_Progress::reset();
		Ingestion_Cron::unschedule_processing();
	}

	/**
	 * Get ingestion (POST) requests from captured HTTP calls.
	 *
	 * @return array<int, array{url: string, method: string, body: string}>
	 */
	private function get_ingestion_requests(): array {
		return array_values(
			array_filter(
				$this->captured_requests,
				fn( $req ) => 'POST' === $req['method']
			)
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

	// =========================================================================
	// delete_record_id_from_api Tests
	// =========================================================================

	public function test_delete_record_id_from_api_returns_success(): void {
		$record_id = '101_1_123';

		$result = Ingestion::delete_record_id_from_api( $record_id );

		$this->assertTrue( $result->success );
		$this->assertSame( $record_id, $result->record_id );
		$this->assertNotEmpty( $result->timestamp );
	}

	public function test_delete_record_id_from_api_accepts_any_format(): void {
		// Even invalid formats should be accepted - it's the API's job to reject them.
		$record_id = 'invalid_format';

		$result = Ingestion::delete_record_id_from_api( $record_id );

		$this->assertTrue( $result->success );
		$this->assertSame( $record_id, $result->record_id );
	}

	public function test_delete_from_api_uses_delete_record_id_from_api(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$result = Ingestion::delete_from_api( $post );

		$this->assertTrue( $result->success );
		$this->assertNotNull( $result->record_id );
		// Record ID should contain the post ID.
		$this->assertStringContainsString( (string) $post->ID, $result->record_id );
	}

	public function test_delete_record_id_from_api_includes_response(): void {
		$record_id = '101_1_123';

		$result = Ingestion::delete_record_id_from_api( $record_id );

		$this->assertTrue( $result->success );
		$this->assertNotNull( $result->response );
		$this->assertIsArray( $result->response );
	}

	// =========================================================================
	// Record ID Format Tests
	// =========================================================================

	public function test_record_id_format_contains_site_blog_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$result = Ingestion::delete_from_api( $post );

		// Format should be site_id_blog_id_post_id.
		$parts = explode( '_', $result->record_id );
		$this->assertCount( 3, $parts, 'Record ID should have 3 parts separated by underscores.' );

		// VIP_GO_APP_ID is defined as 101 in test setup.
		$expected_site_id = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';
		$this->assertSame( $expected_site_id, $parts[0], 'First part should be site_id.' );

		$this->assertSame( (string) get_current_blog_id(), $parts[1], 'Second part should be blog_id.' );
		$this->assertSame( (string) $post->ID, $parts[2], 'Third part should be post_id.' );
	}

	public function test_record_id_uses_correct_site_id(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$result = Ingestion::delete_from_api( $post );

		// Site ID is VIP_GO_APP_ID if defined, otherwise '0'.
		$expected_site_id = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';
		$this->assertStringStartsWith( $expected_site_id . '_', $result->record_id );
	}

	// =========================================================================
	// CLI Record ID Generation Tests (simulating CLI behavior)
	// =========================================================================

	public function test_cli_record_id_generation_with_default_blog_id(): void {
		$post_id = '123';
		$blog_id = (string) get_current_blog_id();
		$site_id = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';

		$expected_record_id = $site_id . '_' . $blog_id . '_' . $post_id;

		// This simulates what the CLI command does.
		$result = Ingestion::delete_record_id_from_api( $expected_record_id );

		$this->assertTrue( $result->success );
		$this->assertSame( $expected_record_id, $result->record_id );
	}

	public function test_cli_record_id_generation_with_explicit_blog_id(): void {
		$post_id = '456';
		$blog_id = '2'; // Explicit blog ID, different from current.
		$site_id = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';

		$expected_record_id = $site_id . '_' . $blog_id . '_' . $post_id;

		$result = Ingestion::delete_record_id_from_api( $expected_record_id );

		$this->assertTrue( $result->success );
		$this->assertSame( $expected_record_id, $result->record_id );
		$this->assertStringContainsString( '_2_', $result->record_id );
	}

	public function test_cli_can_delete_non_existent_post(): void {
		// Post ID that doesn't exist in WordPress.
		$non_existent_post_id = '999999';
		$blog_id              = (string) get_current_blog_id();
		$site_id              = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';

		$record_id = $site_id . '_' . $blog_id . '_' . $non_existent_post_id;

		// This should succeed - the API doesn't care if the post exists in WP.
		$result = Ingestion::delete_record_id_from_api( $record_id );

		$this->assertTrue( $result->success );
		$this->assertSame( $record_id, $result->record_id );
	}

	// =========================================================================
	// Ingestion_CLI::sync() Tests
	// =========================================================================

	/**
	 * Run CLI sync command and capture output.
	 *
	 * @param array<string, string> $assoc_args Associative arguments passed to the sync command.
	 *
	 * @return string Captured CLI output.
	 */
	private function run_cli_sync( array $assoc_args = [] ): string {
		$cli = new Ingestion_CLI();
		ob_start();
		$cli->sync( [], $assoc_args );
		return ob_get_clean();
	}

	public function test_cli_sync_errors_when_no_filter_registered(): void {
		// Ensure no filter is registered.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$this->run_cli_sync();

		// Should not start progress when filter is missing.
		$this->assertNull( Ingestion_Sync_Progress::get() );
	}

	public function test_cli_sync_starts_bulk_sync_progress(): void {
		$this->setup_ingestion_filters();

		// Create posts.
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Clear any requests from post creation.
		$this->captured_requests = [];

		$this->run_cli_sync();

		$this->assertTrue( Ingestion_Sync_Progress::is_running() );
		$this->assertEquals( 2, Ingestion_Sync_Progress::get()['total'] );

		// Should have 0 API calls (sync just queues).
		$ingestion_requests = $this->get_ingestion_requests();
		$this->assertCount( 0, $ingestion_requests, 'Sync should not make API calls directly.' );
	}

	public function test_cli_sync_reports_correct_total_count(): void {
		$this->setup_ingestion_filters();

		// Create 3 posts.
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->run_cli_sync();

		$this->assertEquals( 3, Ingestion_Sync_Progress::get()['total'] );
	}

	public function test_cli_sync_counts_only_published_posts(): void {
		$this->setup_ingestion_filters();

		// Create posts with different statuses.
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'pending' ] );

		$this->run_cli_sync();

		// Only the published post should be counted.
		$this->assertEquals( 1, Ingestion_Sync_Progress::get()['total'] );
	}

	public function test_cli_sync_does_not_trigger_save_post_hook(): void {
		$this->setup_ingestion_filters();

		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Clear any requests from post creation.
		$this->captured_requests = [];

		// Track if save_post is called during sync.
		$save_post_called = false;
		add_action(
			'save_post',
			function () use ( &$save_post_called ) {
				$save_post_called = true;
			}
		);

		$this->run_cli_sync();

		// save_post should NOT have been called.
		$this->assertFalse( $save_post_called, 'Sync should NOT trigger save_post hook.' );

		// No API calls should be made either.
		$this->assertCount( 0, $this->get_ingestion_requests() );
	}

	public function test_cli_sync_blocks_when_already_running(): void {
		$this->setup_ingestion_filters();
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Simulate running sync.
		Ingestion_Sync_Progress::start( 100, [ 'post' ] );

		$this->run_cli_sync();

		// Should not overwrite existing progress.
		$this->assertEquals( 100, Ingestion_Sync_Progress::get()['total'] );

		// No new API calls.
		$this->assertCount( 0, $this->get_ingestion_requests() );
	}

	public function test_cli_sync_schedules_cron(): void {
		$this->setup_ingestion_filters();
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertFalse( Ingestion_Cron::is_scheduled() );

		$this->run_cli_sync();

		$this->assertTrue( Ingestion_Cron::is_scheduled() );
	}

	public function test_cli_sync_status_flag(): void {
		// Don't start any sync.
		$this->run_cli_sync( [ 'status' => '' ] );

		$this->assertNull( Ingestion_Sync_Progress::get() );
	}

	// =========================================================================
	// JSON Format Tests
	// =========================================================================

	public function test_cli_sync_status_json_no_sync(): void {
		$output = $this->run_cli_sync( [
			'status' => '',
			'format' => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 'idle', $data['status'] );
		$this->assertSame( 'No sync has been initiated.', $data['message'] );
	}

	public function test_cli_sync_status_json_running(): void {
		Ingestion_Sync_Progress::start( 200, [ 'post', 'page' ] );

		$output = $this->run_cli_sync( [
			'status' => '',
			'format' => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( 200, $data['total'] );
		$this->assertSame( 0, $data['processed'] );
		$this->assertSame( 0.0, $data['percentage'] );
		$this->assertContains( 'post', $data['post_types'] );
		$this->assertContains( 'page', $data['post_types'] );
	}

	public function test_cli_sync_status_json_with_progress(): void {
		Ingestion_Sync_Progress::start( 100, [ 'post' ] );
		Ingestion_Sync_Progress::update(
			[
				'synced'  => 30,
				'skipped' => 10,
				'failed'  => 5,
				'deleted' => 5,
			],
			999
		);

		$output = $this->run_cli_sync( [
			'status' => '',
			'format' => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( 100, $data['total'] );
		$this->assertSame( 50, $data['processed'] );
		$this->assertSame( 30, $data['synced'] );
		$this->assertSame( 10, $data['skipped'] );
		$this->assertSame( 5, $data['failed'] );
		$this->assertSame( 5, $data['deleted'] );
		$this->assertSame( 999, $data['last_post_id'] );
		$this->assertSame( 50.0, $data['percentage'] );
	}

	public function test_cli_sync_status_json_completed(): void {
		Ingestion_Sync_Progress::start( 10, [ 'post' ] );
		Ingestion_Sync_Progress::update(
			[
				'synced'  => 8,
				'skipped' => 2,
				'failed'  => 0,
				'deleted' => 0,
			],
			42
		);
		Ingestion_Sync_Progress::complete();

		$output = $this->run_cli_sync( [
			'status' => '',
			'format' => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 'completed', $data['status'] );
		$this->assertSame( 100.0, $data['percentage'] );
		$this->assertNotNull( $data['completed_at'] );
	}

	public function test_cli_sync_status_json_failed(): void {
		Ingestion_Sync_Progress::start( 50, [ 'post' ] );
		Ingestion_Sync_Progress::fail( 'API timeout' );

		$output = $this->run_cli_sync( [
			'status' => '',
			'format' => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 'failed', $data['status'] );
		$this->assertSame( 'API timeout', $data['error'] );
		$this->assertArrayHasKey( 'percentage', $data );
	}

	public function test_cli_sync_status_json_via_subcommand(): void {
		Ingestion_Sync_Progress::start( 75, [ 'post' ] );

		$cli = new Ingestion_CLI();
		ob_start();
		$cli->sync_status( [], [ 'format' => 'json' ] );
		$output = ob_get_clean();
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( 75, $data['total'] );
	}

	public function test_cli_sync_start_json_success(): void {
		$this->setup_ingestion_filters();
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$output = $this->run_cli_sync( [ 'format' => 'json' ] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( 1, $data['total'] );
		$this->assertArrayHasKey( 'post_types', $data );
	}

	public function test_cli_sync_start_json_already_running(): void {
		$this->setup_ingestion_filters();
		Ingestion_Sync_Progress::start( 100, [ 'post' ] );

		$output = $this->run_cli_sync( [ 'format' => 'json' ] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'running', $data['status'] );
		$this->assertStringContainsString( 'already in progress', $data['message'] );
	}

	public function test_cli_sync_start_json_no_published_posts(): void {
		$this->setup_ingestion_filters();

		$output = $this->run_cli_sync( [ 'format' => 'json' ] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'idle', $data['status'] );
		$this->assertSame( 'No published posts found to sync.', $data['message'] );
	}

	public function test_cli_sync_reset_flag(): void {
		$this->setup_ingestion_filters();
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->run_cli_sync();
		$this->assertTrue( Ingestion_Sync_Progress::is_running() );

		$cli = new Ingestion_CLI();
		ob_start();
		$cli->sync( [], [ 'reset' => '' ] );
		ob_get_clean();

		$this->assertNull( Ingestion_Sync_Progress::get() );
	}
}
