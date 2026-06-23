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
	/**
	 * @var array<string, mixed>
	 */
	private array $refreshable_config = [];

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
		$this->clear_pending_counter_samples();

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
		remove_all_filters( 'vip_agentforce_config' );
		remove_all_filters( 'vip_agentforce_api_auth_retry_count' );
		Configs::flush_cache();
		delete_option( Ingestion_Queue::OPTION_DELETE_QUEUE );
		$this->clear_pending_counter_samples();
		Ingestion_Sync_Progress::reset();
		wp_cache_delete( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
		Testable_Logger::clear_entries();

		foreach ( [ 'queue_pending_gauge', 'bulk_status_gauge', 'bulk_posts_gauge', 'bulk_updated_age_gauge', 'api_errors_counter', 'posts_counter', 'api_requests_counter' ] as $property ) {
			$this->set_metric_property( $property, null );
		}

		parent::tearDown();
	}

	private function collect_counter_metrics(): void {
		Ingestion_Metrics::collect_counters();
	}

	private function clear_pending_counter_samples(): void {
		wp_cache_delete( 'ingestion_metric_counter_samples', 'vip_agentforce' );
		wp_cache_delete( 'ingestion_metric_counter_replay_lock', 'vip_agentforce' );
	}

	/**
	 * @return mixed
	 */
	private function get_pending_counter_samples() {
		$found   = false;
		$samples = wp_cache_get( 'ingestion_metric_counter_samples', 'vip_agentforce', false, $found );

		return $found ? $samples : false;
	}

	/**
	 * @param mixed $samples
	 */
	private function update_pending_counter_samples( $samples ): void {
		wp_cache_set( 'ingestion_metric_counter_samples', $samples, 'vip_agentforce' );
	}

	private function set_metric_property( string $property, ?Fake_Ingestion_Metric $value ): void {
		$gauge_keys = [
			'queue_pending_gauge'    => 'queue_pending',
			'bulk_status_gauge'      => 'bulk_status',
			'bulk_posts_gauge'       => 'bulk_posts',
			'bulk_updated_age_gauge' => 'bulk_updated_age',
		];
		if ( array_key_exists( $property, $gauge_keys ) ) {
			$this->set_metric_array_value( 'gauges', $gauge_keys[ $property ], $value );
			return;
		}

		$counter_keys = [
			'api_errors_counter'   => 'api_errors',
			'posts_counter'        => 'posts',
			'api_requests_counter' => 'api_requests',
		];
		if ( array_key_exists( $property, $counter_keys ) ) {
			$this->set_metric_array_value( 'counters', $counter_keys[ $property ], $value );
		}
	}

	private function set_metric_array_value( string $property, string $key, ?Fake_Ingestion_Metric $value ): void {
		$ref  = new ReflectionClass( Ingestion_Metrics::class );
		$prop = $ref->getProperty( $property );
		$prop->setAccessible( true );

		$metrics = $prop->getValue();
		if ( ! is_array( $metrics ) ) {
			$metrics = [];
		}

		if ( null === $value ) {
			unset( $metrics[ $key ] );
		} else {
			$metrics[ $key ] = $value;
		}

		$prop->setValue( null, $metrics );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function prime_configs_cache( array $config ): void {
		$this->refreshable_config = $config;

		$ref  = new ReflectionClass( Configs::class );
		$prop = $ref->getProperty( 'cached_config' );
		$prop->setAccessible( true );
		$prop->setValue( null, $config );

		remove_all_filters( 'vip_agentforce_config' );
		add_filter(
			'vip_agentforce_config',
			function () {
				return $this->refreshable_config;
			}
		);
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

		$storage = new \Prometheus\Storage\InMemory();
		if ( class_exists( 'Automattic\\VIP\\Prometheus\\SafeAdapter' ) ) {
			$storage = new \Automattic\VIP\Prometheus\SafeAdapter( $storage );
		}

		$registry  = new \Prometheus\CollectorRegistry( $storage );
		$collector = new \Automattic\VIP\Salesforce\Agentforce\Utils\Collector();

		$collector->initialize( $registry );
		Ingestion_Metrics::record_api_request( 'POST', '202', 'success' );

		$collector->collect_metrics();

		$metric_names = [];
		foreach ( $registry->getMetricFamilySamples() as $family ) {
			if ( method_exists( $family, 'getName' ) ) {
				$metric_names[] = $family->getName();
			}
		}

		$this->assertContains( 'vip_agentforce_ingestion_api_requests_total', $metric_names );
		$this->assertFalse( $this->get_pending_counter_samples(), 'Initialized counters should write directly without pending replay state.' );
	}

	public function test_api_request_metrics_track_auth_failures(): void {
		add_filter( 'vip_agentforce_api_auth_retry_count', '__return_zero' );

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
		$this->collect_counter_metrics();

		$this->assertFalse( $result->success );
		$this->assertSame( 'auth', $result->get_error_class() );
		$this->assertSame( 1, $this->api_requests_counter->get_sample( [ 'POST', '401', 'auth_error' ] ) );
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'auth' ] ) );
	}

	public function test_deferred_auth_retry_does_not_record_api_error_metrics(): void {
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
		$this->assertTrue( $result->is_retryable() );
		$this->assertSame( 'auth', $result->get_error_class() );

		$deferred_result = $client->send( $this->create_test_record() );
		$this->collect_counter_metrics();

		$this->assertFalse( $deferred_result->success );
		$this->assertTrue( $deferred_result->is_retryable() );
		$this->assertSame( 1, $this->request_count, 'Active auth backoff should defer without another HTTP request.' );
		$this->assertNull( $this->api_requests_counter->get_sample( [ 'POST', '401', 'auth_error' ] ) );
		$this->assertNull( $this->api_errors_counter->get_sample( [ 'auth' ] ) );
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
		$this->collect_counter_metrics();

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
		$this->collect_counter_metrics();

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
		$this->collect_counter_metrics();

		$this->assertFalse( $result->success );
		$this->assertSame( 'network', $result->get_error_class() );
		$this->assertSame( 1, $this->api_requests_counter->get_sample( [ 'POST', 'none', 'network_error' ] ) );
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'network' ] ) );
	}

	public function test_missing_config_tracks_error_without_request_metric(): void {
		$this->prime_configs_cache( [] );

		$client = new Ingestion_API_Client();
		$result = $client->send( $this->create_test_record() );
		$this->collect_counter_metrics();

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
		$this->collect_counter_metrics();

		$this->assertSame( 1, $this->posts_counter->get_sample( [ 'ingested', 'bulk' ] ) );
		$this->assertSame( 1, $this->posts_counter->get_sample( [ 'failed', 'queue' ] ) );
		$this->assertSame( 1, $this->posts_counter->get_sample( [ 'deleted', 'sync' ] ) );
	}

	public function test_counter_samples_recorded_before_initialization_are_replayed_once(): void {
		$this->set_metric_property( 'api_errors_counter', null );
		$this->set_metric_property( 'posts_counter', null );
		$this->set_metric_property( 'api_requests_counter', null );

		Ingestion_Metrics::record_api_request( 'POST', '202', 'success' );
		Ingestion_Metrics::record_api_request( 'POST', '202', 'success' );
		Ingestion_Metrics::record_api_error( 'server' );
		Ingestion_Metrics::record_post_result( 'ingested', 'bulk' );

		$this->assertNull( $this->api_requests_counter->get_sample( [ 'POST', '202', 'success' ] ) );
		$this->assertNull( $this->api_errors_counter->get_sample( [ 'server' ] ) );
		$this->assertNull( $this->posts_counter->get_sample( [ 'ingested', 'bulk' ] ) );

		$this->set_metric_property( 'api_errors_counter', $this->api_errors_counter );
		$this->set_metric_property( 'posts_counter', $this->posts_counter );
		$this->set_metric_property( 'api_requests_counter', $this->api_requests_counter );

		$this->collect_counter_metrics();

		$this->assertSame( 2, $this->api_requests_counter->get_sample( [ 'POST', '202', 'success' ] ) );
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'server' ] ) );
		$this->assertSame( 1, $this->posts_counter->get_sample( [ 'ingested', 'bulk' ] ) );
		$this->assertFalse( $this->get_pending_counter_samples() );

		$this->collect_counter_metrics();

		$this->assertSame( 2, $this->api_requests_counter->get_sample( [ 'POST', '202', 'success' ] ) );
		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'server' ] ) );
		$this->assertSame( 1, $this->posts_counter->get_sample( [ 'ingested', 'bulk' ] ) );
	}

	public function test_counter_collection_preserves_known_samples_when_metric_is_unavailable(): void {
		$this->set_metric_property( 'posts_counter', null );
		$this->update_pending_counter_samples(
			[
				'api_requests' => [
					'["POST","202","success"]' => [
						'labels' => [ 'POST', '202', 'success' ],
						'count'  => 2,
					],
				],
				'posts'        => [
					'["failed","queue"]' => [
						'labels' => [ 'failed', 'queue' ],
						'count'  => 3,
					],
				],
			]
		);

		$this->collect_counter_metrics();

		$this->assertSame( 2, $this->api_requests_counter->get_sample( [ 'POST', '202', 'success' ] ) );
		$this->assertSame(
			[
				'posts' => [
					'["failed","queue"]' => [
						'labels' => [ 'failed', 'queue' ],
						'count'  => 3,
					],
				],
			],
			$this->get_pending_counter_samples(),
			'Unavailable known counters should remain buffered for a later scrape.'
		);
	}

	public function test_counter_collection_leaves_samples_pending_when_replay_lock_is_held(): void {
		$pending_samples = [
			'api_requests' => [
				'["POST","202","success"]' => [
					'labels' => [ 'POST', '202', 'success' ],
					'count'  => 2,
				],
			],
		];

		$this->update_pending_counter_samples( $pending_samples );
		wp_cache_add( 'ingestion_metric_counter_replay_lock', true, 'vip_agentforce', 300 );

		$this->collect_counter_metrics();

		$this->assertNull( $this->api_requests_counter->get_sample( [ 'POST', '202', 'success' ] ) );
		$this->assertSame(
			$pending_samples,
			$this->get_pending_counter_samples(),
			'A collector that cannot claim the replay lock should leave samples for a later scrape.'
		);
	}

	public function test_counter_collection_keeps_pending_samples_when_replay_throws(): void {
		$pending_samples = [
			'api_requests' => [
				'["POST","202","success"]' => [
					'labels' => [ 'POST', '202', 'success' ],
					'count'  => 2,
				],
			],
		];

		$this->update_pending_counter_samples( $pending_samples );
		$this->set_metric_property(
			'api_requests_counter',
			new class() extends Fake_Ingestion_Metric {
				/**
				 * @param array<int, string> $labels
				 */
				// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors Prometheus counter API.
				public function incBy( int|float $count, array $labels = [] ): void {
					throw new RuntimeException( 'Counter replay failed.' );
				}
			}
		);

		try {
			$this->collect_counter_metrics();
			$this->fail( 'Expected counter replay to throw.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Counter replay failed.', $exception->getMessage() );
		}

		$this->assertSame(
			$pending_samples,
			$this->get_pending_counter_samples(),
			'Pending samples should not be removed until replay completes successfully.'
		);
	}

	public function test_counter_collection_does_not_replay_samples_that_succeeded_before_later_failure(): void {
		$this->update_pending_counter_samples(
			[
				'api_errors'   => [
					'["server"]' => [
						'labels' => [ 'server' ],
						'count'  => 1,
					],
				],
				'posts'        => [
					'["failed","queue"]' => [
						'labels' => [ 'failed', 'queue' ],
						'count'  => 2,
					],
				],
				'api_requests' => [
					'["POST","202","success"]' => [
						'labels' => [ 'POST', '202', 'success' ],
						'count'  => 1,
					],
				],
			]
		);

		$this->posts_counter = new class() extends Fake_Ingestion_Metric {
			private bool $has_thrown = false;

			/**
			 * @param array<int, string> $labels
			 */
			// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors Prometheus counter API.
			public function incBy( int|float $count, array $labels = [] ): void {
				if ( [ 'failed', 'queue' ] === $labels && ! $this->has_thrown ) {
					$this->has_thrown = true;
					throw new RuntimeException( 'Counter replay failed mid-batch.' );
				}

				parent::incBy( $count, $labels );
			}
		};

		$this->set_metric_property(
			'posts_counter',
			$this->posts_counter
		);

		try {
			$this->collect_counter_metrics();
			$this->fail( 'Expected counter replay to throw.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Counter replay failed mid-batch.', $exception->getMessage() );
		}

		$this->assertSame( 1, $this->api_errors_counter->get_sample( [ 'server' ] ) );
		$this->assertNull( $this->posts_counter->get_sample( [ 'failed', 'queue' ] ) );
		$this->assertNull( $this->api_requests_counter->get_sample( [ 'POST', '202', 'success' ] ) );
		$this->assertSame(
			[
				'posts'        => [
					'["failed","queue"]' => [
						'labels' => [ 'failed', 'queue' ],
						'count'  => 2,
					],
				],
				'api_requests' => [
					'["POST","202","success"]' => [
						'labels' => [ 'POST', '202', 'success' ],
						'count'  => 1,
					],
				],
			],
			$this->get_pending_counter_samples(),
			'Samples already replayed before a later failure should not remain buffered, while unattempted known samples should stay pending.'
		);

		$this->collect_counter_metrics();

		$this->assertSame( 1, $this->api_requests_counter->get_sample( [ 'POST', '202', 'success' ] ) );
		$this->assertSame( 2, $this->posts_counter->get_sample( [ 'failed', 'queue' ] ) );
		$this->assertFalse( $this->get_pending_counter_samples() );
	}

	public function test_counter_collection_ignores_unknown_and_malformed_pending_samples(): void {
		$this->update_pending_counter_samples(
			[
				'unknown'      => [
					'["unexpected"]' => [
						'labels' => [ 'unexpected' ],
						'count'  => 99,
					],
				],
				'api_requests' => [
					'["POST","202","success"]' => [
						'labels' => [ 'POST', '202', 'success' ],
						'count'  => 2,
					],
					'bad-labels'               => [
						'labels' => [ 'POST', 202, 'success' ],
						'count'  => 5,
					],
					'bad-count'                => [
						'labels' => [ 'POST', '500', 'server_error' ],
						'count'  => 'not numeric',
					],
					'zero-count'               => [
						'labels' => [ 'POST', '401', 'auth_error' ],
						'count'  => 0,
					],
					'wrong-arity'              => [
						'labels' => [ 'POST', '202' ],
						'count'  => 7,
					],
					'unknown-method'           => [
						'labels' => [ 'PUT', '202', 'success' ],
						'count'  => 8,
					],
					'unknown-status'           => [
						'labels' => [ 'POST', 'garbage', 'success' ],
						'count'  => 9,
					],
				],
			]
		);

		$this->collect_counter_metrics();

		$this->assertSame( 2, $this->api_requests_counter->get_sample( [ 'POST', '202', 'success' ] ) );
		$this->assertNull( $this->api_requests_counter->get_sample( [ 'POST', '500', 'server_error' ] ) );
		$this->assertNull( $this->api_requests_counter->get_sample( [ 'POST', '401', 'auth_error' ] ) );
		$this->assertFalse( $this->get_pending_counter_samples() );
	}

	public function test_counter_samples_write_directly_when_counter_is_available(): void {
		Ingestion_Metrics::record_api_request( 'POST', '202', 'success' );
		Ingestion_Metrics::record_api_request( 'POST', 'garbage', 'success' );

		$this->assertSame( 1, $this->api_requests_counter->get_sample( [ 'POST', '202', 'success' ] ) );
		$this->assertSame( 1, $this->api_requests_counter->get_sample( [ 'POST', 'none', 'success' ] ) );
		$this->assertNull( $this->api_requests_counter->get_sample( [ 'POST', 'garbage', 'success' ] ) );
		$this->assertFalse( $this->get_pending_counter_samples() );
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

		Tracking::record_stats( 'env_101_1', 'posts_ingested' );
		Tracking::record_stats( 'cmp_page_viewed' );

		$entries = Testable_Logger::get_entries();

		$this->assertSame( 0, $pixel_requests, 'Test environment should log Stats paths without sending tracking pixels.' );
		$this->assertNotEmpty( $entries, 'Test environment should log the Stats path.' );
		$this->assertSame( 'info', $entries[0]['severity'] );
		$this->assertSame( 'vip-agentforce', $entries[0]['feature'] );
		$this->assertSame( 'Bumping stats for /s/vip_agentforce_posts_ingested/env_101_1', $entries[0]['message'] );
		$this->assertSame( 'vip_agentforce_posts_ingested', $entries[0]['extra']['stat_code'] );
		$this->assertSame( 'env_101_1', $entries[0]['extra']['stat_name'] );
		$this->assertSame( 'Bumping stats for /s/vip_agentforce_nonprod/cmp_page_viewed', $entries[1]['message'] );
		$this->assertSame( 'vip_agentforce_nonprod', $entries[1]['extra']['stat_code'] );
		$this->assertSame( 'cmp_page_viewed', $entries[1]['extra']['stat_name'] );
	}

	public function test_post_result_stats_track_terminal_ingestion_outcomes_with_site_drilldown(): void {
		$tracked_stats = [];
		$callback      = static function ( string $stat_name, string $stat_code_suffix ) use ( &$tracked_stats ): void {
			$tracked_stats[] = [
				'stat_code_suffix' => $stat_code_suffix,
				'stat_name'        => $stat_name,
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
					'stat_code_suffix' => 'posts_ingested',
					'stat_name'        => 'env_101_1',
				],
				[
					'stat_code_suffix' => 'posts_failed',
					'stat_name'        => 'env_101_1',
				],
			],
			$tracked_stats,
			'Stats should expose top-level ingested/failed suffixes plus VIP app/blog-specific drilldown counters.'
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
