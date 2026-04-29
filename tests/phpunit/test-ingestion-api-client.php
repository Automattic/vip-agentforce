<?php
/**
 * Tests for Ingestion_API_Client.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_API_Client;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_API_Client_Test extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests for verification.
	 *
	 * @var array<int, array{url: string, method: string, body: string, headers: array<string, string>}>
	 */
	private array $captured_requests = [];

	/**
	 * Mock responses to return.
	 *
	 * @var array<int, array<string, mixed>|WP_Error>
	 */
	private array $mock_responses = [];

	/**
	 * Index for mock responses.
	 *
	 * @var int
	 */
	private int $response_index = 0;

	public function setUp(): void {
		parent::setUp();
		Logger::disable();

		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
			]
		);

		// Clear object cache for rate limit testing.
		wp_cache_delete( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
	}

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();
		remove_all_filters( 'pre_http_request' );
		Configs::flush_cache();
		$this->captured_requests = [];
		$this->mock_responses    = [];
		$this->response_index    = 0;

		// Clear object cache.
		wp_cache_delete( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
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
	 * Set up mock HTTP responses and capture requests.
	 *
	 * @param array<int, array<string, mixed>|WP_Error> $responses Responses to return in sequence.
	 */
	private function mock_http_responses( array $responses ): void {
		$this->mock_responses = $responses;
		$this->response_index = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->captured_requests[] = [
					'url'     => $url,
					'method'  => $args['method'] ?? 'GET',
					'body'    => $args['body'] ?? '',
					'headers' => $args['headers'] ?? [],
				];

				if ( isset( $this->mock_responses[ $this->response_index ] ) ) {
					$response = $this->mock_responses[ $this->response_index ];
					++$this->response_index;
					return $response;
				}

				// Default success response if no more mocks.
				return [
					'response' => [
						'code'    => 202,
						'message' => 'Accepted',
					],
					'headers'  => [],
					'body'     => '',
				];
			},
			10,
			3
		);
	}

	/**
	 * Create a mock HTTP success response.
	 *
	 * @param array<string, string> $headers Optional headers to include.
	 * @return array<string, mixed> The mock response.
	 */
	private function success_response( array $headers = [] ): array {
		return [
			'response' => [
				'code'    => 202,
				'message' => 'Accepted',
			],
			'headers'  => $headers,
			'body'     => '',
		];
	}

	/**
	 * Create a mock HTTP 429 rate limited response.
	 *
	 * @param int|string $retry_after Retry-After header value.
	 * @return array<string, mixed> The mock response.
	 */
	private function rate_limited_response( $retry_after = 1 ): array {
		return [
			'response' => [
				'code'    => 429,
				'message' => 'Too Many Requests',
			],
			'headers'  => [
				'retry-after' => (string) $retry_after,
			],
			'body'     => '',
		];
	}

	/**
	 * Create a mock HTTP error response.
	 *
	 * @param int    $code    HTTP status code.
	 * @param string $message HTTP status message.
	 * @return array<string, mixed> The mock response.
	 */
	private function error_response( int $code, string $message = 'Error' ): array {
		return [
			'response' => [
				'code'    => $code,
				'message' => $message,
			],
			'headers'  => [],
			'body'     => '',
		];
	}

	/**
	 * Create a test Ingestion_Post_Record.
	 *
	 * @param string $record_id The record ID.
	 * @return Ingestion_Post_Record The test record.
	 */
	private function create_test_record( string $record_id = '1_1_123' ): Ingestion_Post_Record {
		return new Ingestion_Post_Record(
			[
				'site_id'                 => '1',
				'blog_id'                 => '1',
				'post_id'                 => '123',
				'site_id_blog_id'         => '1_1',
				'site_id_blog_id_post_id' => $record_id,
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

	// =========================================================================
	// Basic functionality tests
	// =========================================================================

	public function test_send_returns_success_on_202_response(): void {
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertSame( '1_1_123', $result->record_id );
		$this->assertNull( $result->error_message );
	}

	public function test_send_makes_post_request_with_correct_body(): void {
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$client->send( $record );

		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( 'POST', $this->captured_requests[0]['method'] );

		$body = json_decode( $this->captured_requests[0]['body'], true );
		$this->assertArrayHasKey( 'data', $body );
		$this->assertCount( 1, $body['data'] );
		$this->assertSame( '1_1_123', $body['data'][0]['site_id_blog_id_post_id'] );
	}

	public function test_delete_returns_success_on_202_response(): void {
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Ingestion_API_Client();

		$result = $client->delete( '1_1_456' );

		$this->assertTrue( $result->success );
		$this->assertSame( '1_1_456', $result->record_id );
	}

	public function test_delete_makes_delete_request_with_correct_body(): void {
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Ingestion_API_Client();

		$client->delete( '1_1_456' );

		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( 'DELETE', $this->captured_requests[0]['method'] );

		$body = json_decode( $this->captured_requests[0]['body'], true );
		$this->assertArrayHasKey( 'ids', $body );
		$this->assertSame( [ '1_1_456' ], $body['ids'] );
	}

	public function test_request_includes_auth_header(): void {
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$client->send( $record );

		$this->assertSame( 'Bearer test-token', $this->captured_requests[0]['headers']['Authorization'] );
	}

	public function test_request_uses_correct_url(): void {
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$client->send( $record );

		$this->assertSame(
			'https://test.salesforce.com/api/v1/ingest/sources/test-source/test-object',
			$this->captured_requests[0]['url']
		);
	}

	// =========================================================================
	// Config validation tests
	// =========================================================================

	public function test_returns_failure_when_config_missing(): void {
		$this->prime_configs_cache( [] );
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'Missing required API configuration', $result->error_message );
		$this->assertCount( 0, $this->captured_requests, 'No HTTP request should be made with invalid config' );
	}

	public function test_returns_failure_when_partial_config(): void {
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				// Missing other required fields.
			]
		);
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'ingestion_api_token', $result->error_message );
		$this->assertCount( 0, $this->captured_requests );
	}

	// =========================================================================
	// Error handling tests
	// =========================================================================

	public function test_returns_failure_on_non_retryable_error(): void {
		// Use 400 (Bad Request) which is a non-retryable client error.
		$this->mock_http_responses( [ $this->error_response( 400, 'Bad Request' ) ] );

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( '400', $result->error_message );
	}

	public function test_returns_failure_on_wp_error(): void {
		$this->mock_http_responses( [ new WP_Error( 'http_error', 'Connection failed' ) ] );

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertSame( 'Connection failed', $result->error_message );
	}

	// =========================================================================
	// Rate limiting + retryability tests
	// =========================================================================
	//
	// Retry of transient failures lives in `Ingestion_Cron`, not here. The
	// client's job is to make exactly one HTTP call per invocation and
	// surface a result whose `is_retryable()` tells the cron whether to
	// keep the queue item around for the next tick. The shared rate-limit
	// cache block is the coordination mechanism that prevents a swarm of
	// workers from pile-driving SF inside a 429 window.

	public function test_429_returns_retryable_failure_and_sets_cache_block(): void {
		$this->mock_http_responses( [ $this->rate_limited_response( 1 ) ] );

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertTrue( $result->is_retryable(), '429 must be marked retryable so cron re-queues.' );
		$this->assertCount( 1, $this->captured_requests, 'Client makes exactly one attempt; retry is the cron\'s job.' );

		$blocked_until = wp_cache_get( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
		$this->assertNotFalse( $blocked_until, '429 must store the Retry-After block in shared cache.' );
		$this->assertGreaterThan( microtime( true ), (float) $blocked_until );
	}

	public function test_429_without_retry_after_header_uses_short_default_block(): void {
		// Retry-After absent — client should still set a short default block
		// so other workers know to back off.
		$this->mock_http_responses(
			[
				[
					'response' => [
						'code'    => 429,
						'message' => 'Too Many Requests',
					],
					'headers'  => [],
					'body'     => '',
				],
			]
		);

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertTrue( $result->is_retryable() );

		$blocked_until = wp_cache_get( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
		$this->assertNotFalse( $blocked_until );
	}

	public function test_429_parses_http_date_retry_after(): void {
		$retry_date = gmdate( 'D, d M Y H:i:s', time() + 5 ) . ' GMT';
		$this->mock_http_responses(
			[
				[
					'response' => [
						'code'    => 429,
						'message' => 'Too Many Requests',
					],
					'headers'  => [
						'retry-after' => $retry_date,
					],
					'body'     => '',
				],
			]
		);

		$client = new Ingestion_API_Client();
		$client->send( $this->create_test_record() );

		$blocked_until = (float) wp_cache_get( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
		$this->assertGreaterThan( microtime( true ) + 3, $blocked_until, 'HTTP-date Retry-After should park the block several seconds out.' );
	}

	public function test_active_cache_block_defers_request_without_calling_sf(): void {
		// Park a block 10 seconds in the future.
		$blocked_until = microtime( true ) + 10;
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.LowCacheTime -- Test fixture mirrors production rate-limit TTL semantics; not a real cache write.
		wp_cache_set( 'vip_agentforce_rate_limit_blocked_until', $blocked_until, 'vip_agentforce', 12 );

		// Mock a success response so we'd notice if the client actually called.
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Ingestion_API_Client();
		$result = $client->send( $this->create_test_record() );

		$this->assertFalse( $result->success );
		$this->assertTrue( $result->is_retryable(), 'Deferred-by-block result must be retryable.' );
		$this->assertCount( 0, $this->captured_requests, 'Client must skip the HTTP call entirely while the block is active.' );
		$this->assertStringContainsString( 'block active', $result->error_message );
	}

	public function test_expired_cache_block_does_not_defer(): void {
		// Block already expired.
		$blocked_until = microtime( true ) - 1;
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.LowCacheTime -- Test fixture mirrors production rate-limit TTL semantics; not a real cache write.
		wp_cache_set( 'vip_agentforce_rate_limit_blocked_until', $blocked_until, 'vip_agentforce', 2 );

		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Ingestion_API_Client();
		$result = $client->send( $this->create_test_record() );

		$this->assertTrue( $result->success );
		$this->assertCount( 1, $this->captured_requests, 'Expired block should not stop the request.' );
	}

	public function test_preemptive_block_set_when_remaining_is_low_on_success(): void {
		// SF says "this one was OK but you have 1 left until reset" — client
		// should park the block preemptively so the next caller backs off.
		$reset_at = time() + 5;
		$headers  = [
			'x-ratelimit-remaining' => '1',
			'x-ratelimit-reset'     => (string) $reset_at,
		];
		$this->mock_http_responses( [ $this->success_response( $headers ) ] );

		$client = new Ingestion_API_Client();
		$result = $client->send( $this->create_test_record() );

		$this->assertTrue( $result->success );

		$blocked_until = wp_cache_get( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
		$this->assertNotFalse( $blocked_until, 'Preemptive block should land in cache when X-RateLimit-Remaining <= 1.' );
	}

	public function test_preemptive_block_not_set_when_remaining_is_healthy(): void {
		$headers = [
			'x-ratelimit-remaining' => '50',
			'x-ratelimit-reset'     => (string) ( time() + 60 ),
		];
		$this->mock_http_responses( [ $this->success_response( $headers ) ] );

		$client = new Ingestion_API_Client();
		$client->send( $this->create_test_record() );

		$blocked_until = wp_cache_get( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
		$this->assertFalse( $blocked_until, 'Healthy budget should not preempt; block is for the edge case only.' );
	}

	// =========================================================================
	// Server-side error (5xx / 408) tests — single attempt, retryable result
	// =========================================================================

	public function test_5xx_returns_retryable_failure(): void {
		foreach ( [ 500, 502, 503, 504 ] as $status_code ) {
			$this->captured_requests = [];
			remove_all_filters( 'pre_http_request' );

			$this->mock_http_responses( [ $this->error_response( $status_code ) ] );

			$client = new Ingestion_API_Client();
			$result = $client->send( $this->create_test_record() );

			$this->assertFalse( $result->success, "{$status_code} should fail" );
			$this->assertTrue( $result->is_retryable(), "{$status_code} should be retryable" );
			$this->assertCount( 1, $this->captured_requests, "{$status_code} must be a single attempt" );
		}
	}

	public function test_408_returns_retryable_failure(): void {
		$this->mock_http_responses( [ $this->error_response( 408, 'Request Timeout' ) ] );

		$client = new Ingestion_API_Client();
		$result = $client->send( $this->create_test_record() );

		$this->assertFalse( $result->success );
		$this->assertTrue( $result->is_retryable() );
		$this->assertCount( 1, $this->captured_requests );
	}

	public function test_503_with_retry_after_sets_cache_block(): void {
		// 503 with Retry-After should propagate to the shared block, same
		// way as 429 — so other workers also back off.
		$this->mock_http_responses(
			[
				[
					'response' => [
						'code'    => 503,
						'message' => 'Service Unavailable',
					],
					'headers'  => [
						'retry-after' => '5',
					],
					'body'     => '',
				],
			]
		);

		$client = new Ingestion_API_Client();
		$client->send( $this->create_test_record() );

		$blocked_until = wp_cache_get( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
		$this->assertNotFalse( $blocked_until, '503 Retry-After must set the block to coordinate workers.' );
	}

	// =========================================================================
	// Non-retryable error tests
	// =========================================================================

	public function test_does_not_retry_on_400(): void {
		$this->mock_http_responses(
			[
				$this->error_response( 400, 'Bad Request' ),
				$this->success_response(), // Should never reach this.
			]
		);

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( '400', $result->error_message );
		$this->assertCount( 1, $this->captured_requests, 'Should NOT retry on 400' );
	}

	public function test_does_not_retry_on_401(): void {
		$this->mock_http_responses(
			[
				$this->error_response( 401, 'Unauthorized' ),
				$this->success_response(),
			]
		);

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertCount( 1, $this->captured_requests, 'Should NOT retry on 401' );
	}

	public function test_does_not_retry_on_403(): void {
		$this->mock_http_responses(
			[
				$this->error_response( 403, 'Forbidden' ),
				$this->success_response(),
			]
		);

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertCount( 1, $this->captured_requests, 'Should NOT retry on 403' );
	}

	public function test_does_not_retry_on_404(): void {
		$this->mock_http_responses(
			[
				$this->error_response( 404, 'Not Found' ),
				$this->success_response(),
			]
		);

		$client = new Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertCount( 1, $this->captured_requests, 'Should NOT retry on 404' );
	}
}
