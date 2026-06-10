<?php
/**
 * Tests for ingestion metrics helpers.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_API_Client;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Queue;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Sync_Progress;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Ingestion_Metrics;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;
use Automattic\VIP\Salesforce\Agentforce\Utils\Testable_Logger;
use Automattic\VIP\Salesforce\Agentforce\Utils\Tracking;

require_once __DIR__ . '/../class-fake-ingestion-metric.php';

class Ingestion_Metrics_Test extends WP_UnitTestCase {
	private Fake_Ingestion_Metric $queue_pending_gauge;
	private Fake_Ingestion_Metric $bulk_status_gauge;
	private Fake_Ingestion_Metric $bulk_posts_gauge;
	private Fake_Ingestion_Metric $bulk_updated_age_gauge;
	private Fake_Ingestion_Metric $api_errors_counter;
	private Fake_Ingestion_Metric $posts_counter;
	private Fake_Ingestion_Metric $api_requests_counter;
	private int $request_count = 0;

	public function setUp(): void {
		parent::setUp();
		Logger::disable();

		$this->queue_pending_gauge    = new Fake_Ingestion_Metric();
		$this->bulk_status_gauge      = new Fake_Ingestion_Metric();
		$this->bulk_posts_gauge       = new Fake_Ingestion_Metric();
		$this->bulk_updated_age_gauge = new Fake_Ingestion_Metric();
		$this->api_errors_counter     = new Fake_Ingestion_Metric();
		$this->posts_counter          = new Fake_Ingestion_Metric();
		$this->api_requests_counter   = new Fake_Ingestion_Metric();

		$this->set_metric_property( 'queue_pending_gauge', $this->queue_pending_gauge );
		$this->set_metric_property( 'bulk_status_gauge', $this->bulk_status_gauge );
		$this->set_metric_property( 'bulk_posts_gauge', $this->bulk_posts_gauge );
		$this->set_metric_property( 'bulk_updated_age_gauge', $this->bulk_updated_age_gauge );
		$this->set_metric_property( 'api_errors_counter', $this->api_errors_counter );
		$this->set_metric_property( 'posts_counter', $this->posts_counter );
		$this->set_metric_property( 'api_requests_counter', $this->api_requests_counter );

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
		Logger::enable();
		remove_all_filters( 'pre_http_request' );
		Configs::flush_cache();
		delete_option( Ingestion_Queue::OPTION_DELETE_QUEUE );
		Ingestion_Sync_Progress::reset();
		wp_cache_delete( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
		Testable_Logger::clear_entries();

		foreach ( [ 'queue_pending_gauge', 'bulk_status_gauge', 'bulk_posts_gauge', 'bulk_updated_age_gauge', 'api_errors_counter', 'posts_counter', 'api_requests_counter' ] as $property ) {
			$this->set_metric_property( $property, null );
		}

		parent::tearDown();
	}

	private function set_metric_property( string $property, ?Fake_Ingestion_Metric $value ): void {
		$ref  = new ReflectionClass( Ingestion_Metrics::class );
		$prop = $ref->getProperty( $property );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function prime_configs_cache( array $config ): void {
		$ref  = new ReflectionClass( Configs::class );
		$prop = $ref->getProperty( 'cached_config' );
		$prop->setAccessible( true );
		$prop->setValue( null, $config );
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function mock_http_response( array $response ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $response ) {
				if ( strpos( $url, 'test.salesforce.com' ) === false ) {
					return $preempt;
				}

				++$this->request_count;
				return $response;
			},
			10,
			3
		);
	}

	private function create_test_record(): Ingestion_Post_Record {
		return new Ingestion_Post_Record(
			[
				'site_id'                 => '1',
				'blog_id'                 => '1',
				'post_id'                 => '123',
				'site_id_blog_id'         => '1_1',
				'site_id_blog_id_post_id' => '1_1_123',
				'published'               => true,
				'last_published_at'       => '2025-01-01T00:00:00+00:00',
				'last_modified_at'        => '2025-01-01T00:00:00+00:00',
				'title'                   => 'Test Post',
				'content'                 => 'Test content',
				'excerpt'                 => 'Test excerpt',
				'categories'              => '',
				'tags'                    => '',
				'author'                  => '',
				'url'                     => 'https://example.com/test',
				'post_type'               => 'post',
				'post_status'             => 'publish',
			]
		);
	}

	public function test_collector_registers_ingestion_metrics_when_prometheus_runtime_is_available(): void {
		if ( ! class_exists( 'Prometheus\\CollectorRegistry' ) || ! class_exists( 'Prometheus\\Storage\\InMemory' ) ) {
			$this->markTestSkipped( 'Prometheus runtime is not available in this test environment.' );
		}

		$registry  = new \Prometheus\CollectorRegistry( new \Prometheus\Storage\InMemory() );
		$collector = new \Automattic\VIP\Salesforce\Agentforce\Utils\Collector();

		$collector->initialize( $registry );
		Ingestion_Metrics::record_api_request( 'POST', '202', 'success' );

		$metric_names = [];
		foreach ( $registry->getMetricFamilySamples() as $family ) {
			if ( method_exists( $family, 'getName' ) ) {
				$metric_names[] = $family->getName();
			}
		}

		$this->assertContains( 'vip_agentforce_ingestion_api_requests_total', $metric_names );
	}

	public function test_api_request_metrics_track_auth_failures(): void {
		$this->mock_http_response(
			[
				'response' => [
					'code'    => 401,
					'message' => 'Unauthorized',
				],
				'headers'  => [],
				'body'     => '',
			]
		);

		$client = new Ingestion_API_Client();
		$result = $client->send( $this->create_test_record() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'auth', $result->get_error_class() );
		$this->assertSame( 1, $this->api_requests_counter->get_sample( [ 'POST', '401', 'auth_error' ] ) );
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'auth' ] ) );
	}

	public function test_api_request_metrics_track_rate_limit_failures(): void {
		$this->mock_http_response(
			[
				'response' => [
					'code'    => 429,
					'message' => 'Too Many Requests',
				],
				'headers'  => [],
				'body'     => '',
			]
		);

		$client = new Ingestion_API_Client();
		$result = $client->send( $this->create_test_record() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'rate_limit', $result->get_error_class() );
		$this->assertSame( 1, $this->api_requests_counter->get_sample( [ 'POST', '429', 'rate_limit' ] ) );
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'rate_limit' ] ) );
	}

	public function test_api_request_metrics_track_server_failures(): void {
		$this->mock_http_response(
			[
				'response' => [
					'code'    => 501,
					'message' => 'Not Implemented',
				],
				'headers'  => [],
				'body'     => '',
			]
		);

		$client = new Ingestion_API_Client();
		$result = $client->send( $this->create_test_record() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'server', $result->get_error_class() );
		$this->assertSame( 1, $this->api_requests_counter->get_sample( [ 'POST', '501', 'server_error' ] ) );
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'server' ] ) );
	}

	public function test_api_request_metrics_track_network_failures(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'test.salesforce.com' ) === false ) {
					return $preempt;
				}

				++$this->request_count;
				return new WP_Error( 'vip_agentforce_network_failure', 'Network unavailable' );
			},
			10,
			3
		);

		$client = new Ingestion_API_Client();
		$result = $client->send( $this->create_test_record() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'network', $result->get_error_class() );
		$this->assertSame( 1, $this->api_requests_counter->get_sample( [ 'POST', 'none', 'network_error' ] ) );
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'network' ] ) );
	}

	public function test_missing_config_tracks_error_without_request_metric(): void {
		$this->prime_configs_cache( [] );

		$client = new Ingestion_API_Client();
		$result = $client->send( $this->create_test_record() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'config', $result->get_error_class() );
		$this->assertSame( 0, $this->request_count );
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'config' ] ) );
		$this->assertNull( $this->api_requests_counter->get_sample( [ 'POST', 'none', 'network_error' ] ) );
	}

	public function test_post_result_metrics_use_result_and_mode_labels(): void {
		Ingestion_Metrics::record_post_result( 'ingested', 'bulk' );
		Ingestion_Metrics::record_post_result( 'failed', 'queue' );
		Ingestion_Metrics::record_post_result( 'deleted', 'sync' );

		$this->assertSame( 1, $this->posts_counter->get_sample( [ 'ingested', 'bulk' ] ) );
		$this->assertSame( 1, $this->posts_counter->get_sample( [ 'failed', 'queue' ] ) );
		$this->assertSame( 1, $this->posts_counter->get_sample( [ 'deleted', 'sync' ] ) );
	}

	public function test_record_stats_logs_without_sending_pixel_in_test_environment(): void {
		Logger::enable();
		Testable_Logger::clear_entries();

		$pixel_requests = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$pixel_requests ) {
				if ( is_string( $url ) && str_contains( $url, 'pixel.wp.com' ) ) {
					++$pixel_requests;
					return new WP_Error( 'vip_agentforce_unexpected_tracking_pixel', 'Unexpected tracking pixel request in test environment.' );
				}

				return $preempt;
			},
			10,
			3
		);

		Tracking::record_stats( 'env_test_1', 'vip_agentforce_posts_ingested' );

		$entries = Testable_Logger::get_entries();

		$this->assertSame( 0, $pixel_requests, 'Test environment should log Stats paths without sending tracking pixels.' );
		$this->assertNotEmpty( $entries, 'Test environment should log the Stats path.' );
		$this->assertSame( 'info', $entries[0]['severity'] );
		$this->assertSame( 'vip-agentforce', $entries[0]['feature'] );
		$this->assertSame( 'Bumping stats for /s/vip_agentforce_posts_ingested/env_test_1', $entries[0]['message'] );
		$this->assertSame( 'vip_agentforce_posts_ingested', $entries[0]['extra']['stat_code'] );
		$this->assertSame( 'env_test_1', $entries[0]['extra']['stat_name'] );
	}

	public function test_post_result_stats_track_terminal_ingestion_outcomes_with_environment_drilldown(): void {
		$tracked_stats = [];
		$callback      = static function ( string $stat_name, string $stat_code ) use ( &$tracked_stats ): void {
			$tracked_stats[] = [
				'stat_code' => $stat_code,
				'stat_name' => $stat_name,
			];
		};

		add_action( 'vip_agentforce_track_stat', $callback, 1, 2 );

		try {
			Ingestion_Metrics::record_post_result( 'ingested', 'bulk' );
			Ingestion_Metrics::record_post_result( 'failed', 'queue' );
			Ingestion_Metrics::record_post_result( 'deleted', 'sync' );
			Ingestion_Metrics::record_post_result( 'skipped', 'sync' );
		} finally {
			remove_action( 'vip_agentforce_track_stat', $callback, 1 );
		}

		$this->assertSame(
			[
				[
					'stat_code' => 'vip_agentforce_posts_ingested',
					'stat_name' => 'env_test_1',
				],
				[
					'stat_code' => 'vip_agentforce_posts_failed',
					'stat_name' => 'env_test_1',
				],
			],
			$tracked_stats,
			'Stats should expose top-level ingested/failed counters plus environment-specific drilldown counters.'
		);
	}

	public function test_collect_gauges_tracks_queue_and_bulk_sync_progress(): void {
		$sync_post   = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$delete_post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_Queue::queue_for_sync( $sync_post->ID );
		Ingestion_Queue::queue_for_delete( $delete_post->ID );
		Ingestion_Sync_Progress::start( 5, [ 'post' ] );
		Ingestion_Sync_Progress::update(
			[
				'synced'  => 2,
				'skipped' => 1,
				'failed'  => 1,
				'deleted' => 0,
			],
			$sync_post->ID
		);

		Ingestion_Metrics::collect_gauges();

		$this->assertSame( 1, $this->queue_pending_gauge->get_sample( [ 'sync' ] ) );
		$this->assertSame( 1, $this->queue_pending_gauge->get_sample( [ 'delete' ] ) );
		$this->assertSame( 1, $this->bulk_status_gauge->get_sample( [ 'running' ] ) );
		$this->assertSame( 5, $this->bulk_posts_gauge->get_sample( [ 'total' ] ) );
		$this->assertSame( 4, $this->bulk_posts_gauge->get_sample( [ 'processed' ] ) );
		$this->assertSame( 1, $this->bulk_posts_gauge->get_sample( [ 'failed' ] ) );
		$this->assertIsNumeric( $this->bulk_updated_age_gauge->get_sample() );
	}
}
