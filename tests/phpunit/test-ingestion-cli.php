<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_API_Client;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_CLI;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Error;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Queue;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Sync_Progress;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Cron;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Ingestion_Metrics;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

require_once __DIR__ . '/../class-fake-ingestion-metric.php';

class Ingestion_CLI_Test extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests for verification.
	 *
	 * @var array<int, array{url: string, method: string, body: string}>
	 */
	private array $captured_requests = [];

	private Fake_Ingestion_Metric $api_errors_counter;

	/**
	 * Prime Configs cache for deterministic tests without mutating VIP_AGENTFORCE_CONFIGS.
	 *
	 * @param array<string, mixed> $config
	 */
	private function prime_configs_cache( array $config ): void {
		$ref = new ReflectionClass( Configs::class );

		$config_prop = $ref->getProperty( 'cached_config' );
		$config_prop->setAccessible( true );
		$config_prop->setValue( null, $config );

		$token_failure_prop = $ref->getProperty( 'cached_ingestion_token_failure' );
		$token_failure_prop->setAccessible( true );
		$token_failure_prop->setValue( null, null );

		$token_status_loaded_prop = $ref->getProperty( 'cached_ingestion_token_status_loaded' );
		$token_status_loaded_prop->setAccessible( true );
		$token_status_loaded_prop->setValue( null, false );
	}

	public function setUp(): void {
		parent::setUp();
		Logger::disable();
		$this->captured_requests  = [];
		$this->api_errors_counter = new Fake_Ingestion_Metric();
		$this->set_metric_property( 'api_errors_counter', $this->api_errors_counter );
		$this->clear_pending_counter_samples();

		// Initialize cron hooks (registers the custom schedule needed by schedule_processing).
		Ingestion_Cron::init();
		Ingestion_API_Client::clear_retry_status();

		// Set up config for API calls via cache priming.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
			]
		);

		wp_cache_delete( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );

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
		remove_all_filters( 'vip_agentforce_cron_batch_size' );
		remove_all_filters( 'cron_schedules' );
		remove_all_actions( Ingestion_Cron::CRON_HOOK );
		$this->captured_requests = [];
		$this->set_metric_property( 'api_errors_counter', null );
		$this->clear_pending_counter_samples();
		Ingestion_Sync_Progress::reset();
		Ingestion_Cron::unschedule_processing();
		Ingestion_API_Client::clear_retry_status();
	}

	private function set_metric_property( string $property, ?Fake_Ingestion_Metric $value ): void {
		$counter_keys = [
			'api_errors_counter' => 'api_errors',
		];
		if ( ! array_key_exists( $property, $counter_keys ) ) {
			return;
		}

		$ref  = new ReflectionClass( Ingestion_Metrics::class );
		$prop = $ref->getProperty( 'counters' );
		$prop->setAccessible( true );

		$metrics = $prop->getValue();
		if ( ! is_array( $metrics ) ) {
			$metrics = [];
		}

		if ( null === $value ) {
			unset( $metrics[ $counter_keys[ $property ] ] );
		} else {
			$metrics[ $counter_keys[ $property ] ] = $value;
		}

		$prop->setValue( null, $metrics );
	}

	private function collect_counter_metrics(): void {
		Ingestion_Metrics::collect_counters();
	}

	private function clear_pending_counter_samples(): void {
		wp_cache_delete( 'ingestion_metric_counter_samples', 'vip_agentforce' );
		wp_cache_delete( 'ingestion_metric_counter_replay_lock', 'vip_agentforce' );
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

		$this->assertTrue( $result->success, $result->error_message ?? 'Expected deletion API result to succeed.' );
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

	public function test_cli_delete_uses_current_site_context(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite.' );
		}

		$site = self::factory()->blog->create_and_get();

		// Simulate WP-CLI booting against the target site via --url.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Test setup needs multisite blog context.
		switch_to_blog( (int) $site->blog_id );
		try {
			$cli = new Ingestion_CLI();
			ob_start();
			$cli->delete( [ '456' ], [] );
			ob_end_clean();
		} finally {
			restore_current_blog();
		}

		$delete_requests = array_values(
			array_filter(
				$this->captured_requests,
				fn( $req ) => 'DELETE' === $req['method']
			)
		);

		$this->assertCount( 1, $delete_requests );
		$body = json_decode( $delete_requests[0]['body'], true );
		$this->assertSame(
			( defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0' ) . '_' . $site->blog_id . '_456',
			$body['ids'][0]
		);
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

	/**
	 * Run CLI clear-retry-backoff command.
	 */
	private function run_cli_clear_retry_backoff(): void {
		$cli = new Ingestion_CLI();
		$cli->clear_retry_backoff();
	}

	public function test_cli_sync_errors_when_no_filter_registered(): void {
		// Ensure no filter is registered.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$this->run_cli_sync();

		// Should not start progress when filter is missing.
		$this->assertNull( Ingestion_Sync_Progress::get() );
	}

	public function test_cli_queue_status_preserves_active_retry_backoff(): void {
		wp_cache_set( 'vip_agentforce_rate_limit_blocked_until', microtime( true ) + 60, 'vip_agentforce', 300 );
		wp_cache_set(
			'vip_agentforce_ingestion_api_retry_state',
			[
				'blocked_until'        => microtime( true ) + 60,
				'consecutive_failures' => 2,
				'reason'               => 'transient_server_error',
				'status_code'          => 500,
				'last_error_at'        => gmdate( 'c' ),
				'last_error_message'   => 'Server error (500)',
			],
			'vip_agentforce',
			DAY_IN_SECONDS
		);

		$cli = new Ingestion_CLI();
		$cli->queue_status();

		$status = Ingestion_API_Client::get_retry_status();
		$this->assertTrue( $status['active'] );
		$this->assertSame( 2, $status['consecutive_failures'] );
		$this->assertSame( 'transient_server_error', $status['reason'] );
		$this->assertSame( 500, $status['status_code'] );
	}

	public function test_cli_retry_status_details_include_inactive_preflight_reason(): void {
		$cli = new Ingestion_CLI();
		$ref = new ReflectionMethod( $cli, 'has_retry_status_details' );
		$ref->setAccessible( true );

		$this->assertTrue(
			$ref->invoke(
				$cli,
				[
					'active'               => false,
					'consecutive_failures' => 0,
					'reason'               => Ingestion_Error::TOKEN_EXPIRED,
					'last_error_message'   => 'Ingestion API token has expired',
				]
			),
			'Inactive preflight diagnostics should stay visible for Support.'
		);
	}

	public function test_cli_clear_retry_backoff_resets_retry_status(): void {
		wp_cache_set( 'vip_agentforce_rate_limit_blocked_until', microtime( true ) + 60, 'vip_agentforce', 300 );
		wp_cache_set(
			'vip_agentforce_ingestion_api_retry_state',
			[
				'blocked_until'        => microtime( true ) + 60,
				'consecutive_failures' => 1,
				'reason'               => 'http_error',
				'last_error_at'        => gmdate( 'c' ),
				'last_error_message'   => 'Connection failed',
			],
			'vip_agentforce',
			DAY_IN_SECONDS
		);

		$this->run_cli_clear_retry_backoff();
		$status = Ingestion_API_Client::get_retry_status();

		$this->assertFalse( $status['active'] );
		$this->assertSame( 0, $status['consecutive_failures'] );
	}

	public function test_cli_process_queue_all_stops_after_retry_backoff_starts(): void {
		remove_all_filters( 'pre_http_request' );
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
		$this->setup_ingestion_filters();

		$first_post  = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$second_post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $first_post->ID );
		Ingestion_Queue::queue_for_sync( $second_post->ID );
		update_post_meta( $first_post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, 1 );
		update_post_meta( $second_post->ID, Ingestion_Queue::META_KEY_QUEUED_FOR_SYNC, 2 );

		$batch_size_resolutions = 0;
		add_filter(
			'vip_agentforce_cron_batch_size',
			function () use ( &$batch_size_resolutions ) {
				++$batch_size_resolutions;
				return 1;
			}
		);

		$cli = new Ingestion_CLI();
		ob_start();
		$cli->process_queue( [], [ 'all' => '' ] );
		ob_end_clean();

		$this->assertSame( 1, $batch_size_resolutions, 'The --all loop must stop before resolving a second batch while retry backoff is active.' );
		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( 1, Ingestion_Queue::get_sync_attempts( $first_post->ID ) );
		$this->assertSame( 0, Ingestion_Queue::get_sync_attempts( $second_post->ID ) );
	}

	public function test_cli_process_queue_all_continues_after_inactive_retryable_reason(): void {
		wp_cache_set(
			'vip_agentforce_ingestion_api_retry_state',
			[
				'blocked_until'        => microtime( true ) - 60,
				'consecutive_failures' => 1,
				'reason'               => 'server_error',
				'status_code'          => 500,
				'last_error_at'        => gmdate( 'c' ),
				'last_error_message'   => 'Server Error',
			],
			'vip_agentforce',
			DAY_IN_SECONDS
		);
		$this->setup_ingestion_filters();

		$first_post  = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$second_post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Queue::queue_for_sync( $first_post->ID );
		Ingestion_Queue::queue_for_sync( $second_post->ID );

		$batch_size_resolutions = 0;
		add_filter(
			'vip_agentforce_cron_batch_size',
			function () use ( &$batch_size_resolutions ) {
				++$batch_size_resolutions;
				return 1;
			}
		);

		$cli = new Ingestion_CLI();
		ob_start();
		$cli->process_queue( [], [ 'all' => '' ] );
		ob_end_clean();

		$this->assertSame( 2, $batch_size_resolutions, 'Expired retry windows with retryable reasons should continue into the next --all batch.' );
		$this->assertCount( 2, $this->captured_requests, 'Expired retry windows with retryable reasons should not pause --all processing.' );
		$this->assertFalse( Ingestion_Queue::has_queued_items() );
		$this->assertNull( Ingestion_API_Client::get_retry_status()['reason'] );
	}

	public function test_cli_process_queue_all_stops_after_inactive_preflight_failure(): void {
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url'     => 'https://test.salesforce.com',
				'ingestion_api_token'            => 'expired-token',
				'ingestion_api_token_expires_at' => time() - HOUR_IN_SECONDS,
				'ingestion_api_source_name'      => 'test-source',
				'ingestion_api_object_name'      => 'test-object',
			]
		);
		Ingestion_Sync_Progress::start( 2, [ 'post' ] );

		$batch_size_resolutions = 0;
		add_filter(
			'vip_agentforce_cron_batch_size',
			function () use ( &$batch_size_resolutions ) {
				++$batch_size_resolutions;
				return 1;
			}
		);

		$cli = new Ingestion_CLI();
		ob_start();
		$cli->process_queue( [], [ 'all' => '' ] );
		ob_end_clean();

		$status = Ingestion_API_Client::get_retry_status();
		$this->assertSame( Ingestion_Error::TOKEN_EXPIRED, $status['reason'] );
		$this->assertSame( 1, $batch_size_resolutions, 'The --all loop must stop before resolving a second batch while an expired token pauses processing.' );
		$this->assertCount( 0, $this->captured_requests );
		$this->assertTrue( Ingestion_Sync_Progress::is_running(), 'Expired-token skips should pause work without consuming bulk-sync progress.' );
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

	public function test_cli_sync_uses_current_site_context(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite.' );
		}

		$site = self::factory()->blog->create_and_get();

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Test setup needs multisite blog context.
		switch_to_blog( (int) $site->blog_id );
		$data           = null;
		$expected_total = 0;
		try {
			$this->setup_ingestion_filters();
			$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
			$post_types     = get_post_types( [ 'public' => true ] );
			$query          = new WP_Query(
				[
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'no_found_rows'  => false,
					'fields'         => 'ids',
				]
			);
			$expected_total = $query->found_posts;

			$output = $this->run_cli_sync(
				[
					'format' => 'json',
				]
			);
			$data   = json_decode( $output, true );
		} finally {
			restore_current_blog();
		}

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $expected_total, $data['total'] );
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

	public function test_cli_sync_preserves_running_progress(): void {
		$this->setup_ingestion_filters();
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Simulate running sync.
		Ingestion_Sync_Progress::start( 100, [ 'post' ] );
		$old_sync_id = Ingestion_Sync_Progress::get()['sync_id'];

		$this->run_cli_sync();

		$progress = Ingestion_Sync_Progress::get();

		$this->assertSame( Ingestion_Sync_Progress::STATUS_RUNNING, $progress['status'] );
		$this->assertSame( 100, $progress['total'] );
		$this->assertSame( $old_sync_id, $progress['sync_id'] );
		$this->assertCount( 0, $this->get_ingestion_requests() );
	}

	public function test_cli_sync_restarts_after_failed_progress(): void {
		$this->setup_ingestion_filters();
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Sync_Progress::start( 100, [ 'post' ] );
		Ingestion_Sync_Progress::fail( 'Previous sync failed' );
		$old_sync_id = Ingestion_Sync_Progress::get()['sync_id'];

		$this->run_cli_sync();

		$progress = Ingestion_Sync_Progress::get();

		$this->assertSame( Ingestion_Sync_Progress::STATUS_RUNNING, $progress['status'] );
		$this->assertSame( 1, $progress['total'] );
		$this->assertNotSame( $old_sync_id, $progress['sync_id'] );
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
		$this->assertArrayHasKey( 'retry_backoff', $data );
		$this->assertFalse( $data['retry_backoff']['active'] );
	}

	public function test_cli_sync_status_json_no_sync_includes_active_retry_backoff(): void {
		$blocked_until = microtime( true ) + 60;
		wp_cache_set( 'vip_agentforce_rate_limit_blocked_until', $blocked_until, 'vip_agentforce', 300 );
		wp_cache_set(
			'vip_agentforce_ingestion_api_retry_state',
			[
				'blocked_until'        => $blocked_until,
				'consecutive_failures' => 1,
				'reason'               => 'rate_limited',
				'status_code'          => 429,
				'last_error_at'        => '2026-06-10T12:00:00+00:00',
				'last_error_message'   => 'Rate limited by Salesforce',
			],
			'vip_agentforce',
			DAY_IN_SECONDS
		);

		$output = $this->run_cli_sync( [
			'status' => '',
			'format' => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 'idle', $data['status'] );
		$this->assertTrue( $data['retry_backoff']['active'] );
		$this->assertGreaterThan( 0, $data['retry_backoff']['seconds_remaining'] );
		$this->assertSame( 1, $data['retry_backoff']['consecutive_failures'] );
		$this->assertSame( 'rate_limited', $data['retry_backoff']['reason'] );
		$this->assertSame( 429, $data['retry_backoff']['status_code'] );
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
		$this->assertArrayHasKey( 'progress_sources', $data );
		$this->assertArrayHasKey( 'retry_backoff', $data );
		$this->assertFalse( $data['retry_backoff']['active'] );
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

	public function test_cli_sync_status_json_uses_effective_cache_progress_when_cache_is_ahead(): void {
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

		$output = $this->run_cli_sync( [
			'status' => '',
			'format' => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( 8, $data['processed'] );
		$this->assertSame( 7, $data['synced'] );
		$this->assertSame( 1, $data['skipped'] );
		$this->assertSame( 55, $data['last_post_id'] );
		$this->assertSame( 8.0, $data['percentage'] );
		$this->assertSame( 'cache', $data['progress_sources']['effective']['source'] );
		$this->assertSame( 8, $data['progress_sources']['effective']['processed'] );
		$this->assertSame( 8.0, $data['progress_sources']['effective']['percentage'] );
	}

	public function test_cli_sync_status_json_uses_stored_progress_when_cache_is_lower(): void {
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

		$output = $this->run_cli_sync( [
			'status' => '',
			'format' => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( 10, $data['processed'] );
		$this->assertSame( 10, $data['synced'] );
		$this->assertSame( 70, $data['last_post_id'] );
		$this->assertSame( 10.0, $data['percentage'] );
		$this->assertSame( 'stored', $data['progress_sources']['effective']['source'] );
		$this->assertSame( 'cache_behind_stored', $data['progress_sources']['effective']['reason'] );
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
		$this->assertSame( 'sync_failed', $data['error_code'] );
		$this->assertNotEmpty( $data['error_message'] );
		$this->assertArrayHasKey( 'percentage', $data );
	}

	public function test_cli_sync_status_json_failed_surfaces_friendly_message_for_error_code(): void {
		Ingestion_Sync_Progress::start( 50, [ 'post' ] );
		Ingestion_Sync_Progress::fail( 'Bulk sync fast-failed after global auth error: 401 Unauthorized', 'auth_failed' );

		$output = $this->run_cli_sync( [
			'status' => '',
			'format' => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 'failed', $data['status'] );
		$this->assertSame( 'auth_failed', $data['error_code'] );
		// Friendly message must not leak the raw developer detail.
		$this->assertStringNotContainsString( '401', $data['error_message'] );
		$this->assertStringContainsString( '401 Unauthorized', $data['error'] );
	}

	public function test_cli_sync_start_json_no_filter_registered(): void {
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$output = $this->run_cli_sync( [ 'format' => 'json' ] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'filter_not_registered', $data['error_code'] );
		// The raw, developer-facing text from the ticket must not leak into the message.
		$this->assertStringNotContainsString( 'vip_agentforce_should_ingest_post', $data['message'] );
		$this->assertStringContainsString( 'vip_agentforce_should_ingest_post', $data['detail'] );
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

	public function test_cli_sync_start_json_preserves_running_progress(): void {
		$this->setup_ingestion_filters();
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Sync_Progress::start( 100, [ 'post' ] );
		$old_sync_id = Ingestion_Sync_Progress::get()['sync_id'];

		$output   = $this->run_cli_sync( [ 'format' => 'json' ] );
		$data     = json_decode( $output, true );
		$progress = Ingestion_Sync_Progress::get();

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'sync_in_progress', $data['error_code'] );
		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( 100, $progress['total'] );
		$this->assertSame( $old_sync_id, $progress['sync_id'] );
	}

	public function test_cli_sync_start_json_restarts_after_failed_progress(): void {
		$this->setup_ingestion_filters();
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Sync_Progress::start( 100, [ 'post' ] );
		Ingestion_Sync_Progress::fail( 'Previous sync failed' );
		$old_sync_id = Ingestion_Sync_Progress::get()['sync_id'];

		$output   = $this->run_cli_sync( [ 'format' => 'json' ] );
		$data     = json_decode( $output, true );
		$progress = Ingestion_Sync_Progress::get();

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( 1, $data['total'] );
		$this->assertNotSame( $old_sync_id, $progress['sync_id'] );
	}

	public function test_cli_sync_start_json_preserves_existing_progress_when_preflight_fails(): void {
		$this->setup_ingestion_filters();
		$this->prime_configs_cache( [] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Sync_Progress::start( 100, [ 'post' ] );
		$old_progress = Ingestion_Sync_Progress::get();

		$output = $this->run_cli_sync( [ 'format' => 'json' ] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'missing_api_config', $data['error_code'] );
		$this->assertSame( $old_progress, Ingestion_Sync_Progress::get() );
	}

	public function test_cli_sync_start_json_no_published_posts(): void {
		$this->setup_ingestion_filters();

		$output = $this->run_cli_sync( [ 'format' => 'json' ] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'idle', $data['status'] );
		$this->assertSame( 'no_published_posts', $data['error_code'] );
		$this->assertSame( 'No published posts found to sync.', $data['detail'] );
		$this->assertNotEmpty( $data['message'] );
	}

	public function test_cli_sync_start_json_fails_before_scheduling_when_api_config_is_missing(): void {
		$this->setup_ingestion_filters();
		$this->prime_configs_cache( [] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Cron::unschedule_processing();

		$output = $this->run_cli_sync( [ 'format' => 'json' ] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'idle', $data['status'] );
		$this->assertSame( 'config', $data['error_class'] );
		$this->assertSame( 'missing_api_config', $data['error_code'] );
		$this->assertStringContainsString( 'Missing required API configuration', $data['detail'] );
		$this->assertNotEmpty( $data['message'] );
		$this->collect_counter_metrics();
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'config' ] ) );
		$this->assertNull( Ingestion_Sync_Progress::get(), 'Sync progress should not start when request preflight fails.' );
		$this->assertFalse( Ingestion_Cron::is_scheduled(), 'Cron should not be scheduled when request preflight fails.' );
	}

	public function test_cli_sync_start_json_fails_before_scheduling_when_token_is_expired(): void {
		$this->setup_ingestion_filters();
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url'     => 'https://test.salesforce.com',
				'ingestion_api_token'            => 'test-token',
				'ingestion_api_token_expires_at' => time() - HOUR_IN_SECONDS,
				'ingestion_api_source_name'      => 'test-source',
				'ingestion_api_object_name'      => 'test-object',
			]
		);
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		Ingestion_Cron::unschedule_processing();

		$output = $this->run_cli_sync( [ 'format' => 'json' ] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'idle', $data['status'] );
		$this->assertSame( 'auth', $data['error_class'] );
		$this->assertSame( 'token_expired', $data['error_code'] );
		$this->assertSame( 'Ingestion API token has expired', $data['detail'] );
		$this->assertNotEmpty( $data['message'] );
		$this->collect_counter_metrics();
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'auth' ] ) );
		$this->assertNull( Ingestion_Sync_Progress::get(), 'Sync progress should not start when request preflight fails.' );
		$this->assertFalse( Ingestion_Cron::is_scheduled(), 'Cron should not be scheduled when request preflight fails.' );
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

	// =========================================================================
	// Preflight Check Tests
	// =========================================================================

	public function test_cli_preflight_check_returns_ready_when_configured(): void {
		$this->setup_ingestion_filters();

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertTrue( $data['ready'] );
		$this->assertTrue( $data['filter_registered'] );
		$this->assertTrue( $data['has_api_url'] );
		$this->assertTrue( $data['has_api_token'] );
		$this->assertTrue( $data['has_valid_ingestion_token'] );
		$this->assertTrue( $data['has_api_source'] );
		$this->assertTrue( $data['has_api_object'] );
		$this->assertArrayNotHasKey( 'retry_backoff', $data, 'Preflight reports static readiness; runtime retry backoff belongs in sync status.' );
	}

	public function test_cli_preflight_check_returns_not_ready_when_no_filter(): void {
		// Ensure no filter is registered.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['ready'] );
		$this->assertFalse( $data['filter_registered'] );
	}

	public function test_cli_preflight_check_returns_not_ready_when_missing_api_config(): void {
		$this->setup_ingestion_filters();

		// Prime config without API credentials.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => '',
				'ingestion_api_token'        => '',
				'ingestion_api_source_name'  => '',
				'ingestion_api_object_name'  => '',
			]
		);

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['ready'] );
		$this->assertTrue( $data['filter_registered'] );
		$this->assertFalse( $data['has_api_url'] );
		$this->assertFalse( $data['has_api_token'] );
		$this->assertFalse( $data['has_valid_ingestion_token'] );
	}

	public function test_cli_preflight_check_returns_not_ready_when_token_expiry_is_invalid(): void {
		$this->setup_ingestion_filters();
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url'     => 'https://test.salesforce.com',
				'ingestion_api_token'            => 'test-token',
				'ingestion_api_token_expires_at' => 'not-a-date',
				'ingestion_api_source_name'      => 'test-source',
				'ingestion_api_object_name'      => 'test-object',
			]
		);

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['ready'] );
		$this->assertTrue( $data['filter_registered'] );
		$this->assertTrue( $data['has_api_token'] );
		$this->assertFalse( $data['has_valid_ingestion_token'] );
		$this->assertSame( 'auth', $data['token_error_class'] );
		$this->assertSame( 'Ingestion API token expiry is invalid', $data['token_error'] );
	}

	public function test_cli_preflight_check_returns_not_ready_when_token_is_expired(): void {
		$this->setup_ingestion_filters();
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url'     => 'https://test.salesforce.com',
				'ingestion_api_token'            => 'test-token',
				'ingestion_api_token_expires_at' => time() - HOUR_IN_SECONDS,
				'ingestion_api_source_name'      => 'test-source',
				'ingestion_api_object_name'      => 'test-object',
			]
		);

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['ready'] );
		$this->assertTrue( $data['filter_registered'] );
		$this->assertTrue( $data['has_api_token'] );
		$this->assertFalse( $data['has_valid_ingestion_token'] );
		$this->assertSame( 'auth', $data['token_error_class'] );
		$this->assertSame( 'Ingestion API token has expired', $data['token_error'] );
	}

	public function test_cli_preflight_check_reports_sync_all_posts(): void {
		$this->setup_ingestion_filters();

		// Prime config with sync_all_posts enabled.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url'   => 'https://test.salesforce.com',
				'ingestion_api_token'          => 'test-token',
				'ingestion_api_source_name'    => 'test-source',
				'ingestion_api_object_name'    => 'test-object',
				'ingestion_api_sync_all_posts' => true,
			]
		);

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertTrue( $data['sync_all_posts'] );
	}

	public function test_cli_preflight_check_reports_categories_count(): void {
		$this->setup_ingestion_filters();

		// Prime config with categories.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
				'ingestion_api_categories'   => [ 'News', 'Updates', 'Featured' ],
			]
		);

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertSame( 3, $data['categories_count'] );
	}

	public function test_cli_preflight_check_reports_categories_array(): void {
		$this->setup_ingestion_filters();

		$expected_categories = [ 'News', 'Updates', 'Featured' ];

		// Prime config with categories.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
				'ingestion_api_categories'   => $expected_categories,
			]
		);

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertArrayHasKey( 'categories', $data );
		$this->assertIsArray( $data['categories'] );
		$this->assertSame( $expected_categories, $data['categories'] );
	}

	public function test_cli_preflight_check_filter_registered_is_boolean(): void {
		$this->setup_ingestion_filters();

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertArrayHasKey( 'filter_registered', $data );
		$this->assertIsBool( $data['filter_registered'] );
		$this->assertTrue( $data['filter_registered'] );
	}

	public function test_cli_preflight_check_filter_registered_is_boolean_when_no_filter(): void {
		// Ensure no filter is registered.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertArrayHasKey( 'filter_registered', $data );
		$this->assertIsBool( $data['filter_registered'] );
		$this->assertFalse( $data['filter_registered'] );
	}

	public function test_cli_preflight_check_does_not_start_sync(): void {
		$this->setup_ingestion_filters();
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );

		// Preflight check should NOT start a sync.
		$this->assertFalse( Ingestion_Sync_Progress::is_running() );
		$this->assertNull( Ingestion_Sync_Progress::get() );
	}

	public function test_cli_preflight_check_partial_api_config(): void {
		$this->setup_ingestion_filters();

		// Prime config with partial API credentials.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => '',  // Missing
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => '',  // Missing
			]
		);

		$output = $this->run_cli_sync( [
			'preflight-check' => '',
			'format'          => 'json',
		] );
		$data   = json_decode( $output, true );

		$this->assertNotNull( $data, 'Output should be valid JSON.' );
		$this->assertFalse( $data['ready'] );
		$this->assertTrue( $data['has_api_url'] );
		$this->assertFalse( $data['has_api_token'] );
		$this->assertFalse( $data['has_valid_ingestion_token'] );
		$this->assertTrue( $data['has_api_source'] );
		$this->assertFalse( $data['has_api_object'] );
	}
}
