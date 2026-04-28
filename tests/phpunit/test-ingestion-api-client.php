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

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test double is private to this file.

/**
 * Test double that skips sleeping for faster tests.
 */
class Test_Ingestion_API_Client extends Ingestion_API_Client {
	protected function sleep_with_jitter( float $base_seconds ): void {
		// No-op for tests.
	}
}

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

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertSame( '1_1_123', $result->record_id );
		$this->assertNull( $result->error_message );
	}

	public function test_send_makes_post_request_with_correct_body(): void {
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Test_Ingestion_API_Client();
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

		$client = new Test_Ingestion_API_Client();

		$result = $client->delete( '1_1_456' );

		$this->assertTrue( $result->success );
		$this->assertSame( '1_1_456', $result->record_id );
	}

	public function test_delete_makes_delete_request_with_correct_body(): void {
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Test_Ingestion_API_Client();

		$client->delete( '1_1_456' );

		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( 'DELETE', $this->captured_requests[0]['method'] );

		$body = json_decode( $this->captured_requests[0]['body'], true );
		$this->assertArrayHasKey( 'ids', $body );
		$this->assertSame( [ '1_1_456' ], $body['ids'] );
	}

	public function test_request_includes_auth_header(): void {
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$client->send( $record );

		$this->assertSame( 'Bearer test-token', $this->captured_requests[0]['headers']['Authorization'] );
	}

	public function test_request_uses_correct_url(): void {
		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Test_Ingestion_API_Client();
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

		$client = new Test_Ingestion_API_Client();
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

		$client = new Test_Ingestion_API_Client();
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

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( '400', $result->error_message );
	}

	public function test_returns_failure_on_wp_error(): void {
		$this->mock_http_responses( [ new WP_Error( 'http_error', 'Connection failed' ) ] );

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertSame( 'Connection failed', $result->error_message );
	}

	// =========================================================================
	// Rate limiting tests
	// =========================================================================

	public function test_retries_on_429_and_eventually_succeeds(): void {
		$this->mock_http_responses(
			[
				$this->rate_limited_response( 0 ), // First attempt: rate limited.
				$this->success_response(),         // Second attempt: success.
			]
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertCount( 2, $this->captured_requests, 'Should retry after 429' );
	}

	public function test_retries_after_429_with_retry_after_header(): void {
		$this->mock_http_responses(
			[
				$this->rate_limited_response( 1 ),
				$this->success_response(),
			]
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertCount( 2, $this->captured_requests, 'Should retry after 429' );
	}

	public function test_fails_after_max_retries(): void {
		// 11 responses for 1 initial + 10 retries.
		$this->mock_http_responses(
			array_fill( 0, 11, $this->rate_limited_response( 0 ) )
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'Rate limited after', $result->error_message );
		$this->assertCount( 11, $this->captured_requests, 'Should make 11 attempts (1 + 10 retries)' );
	}

	public function test_succeeds_after_rate_limit_block_expires(): void {
		// Set up an already-expired rate limit block in cache.
		$blocked_until = microtime( true ) - 1; // Expired 1 second ago.
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.LowCacheTime -- Test fixture mirrors production rate-limit TTL semantics; not a real cache write.
		wp_cache_set( 'vip_agentforce_rate_limit_blocked_until', $blocked_until, 'vip_agentforce', 2 );

		$this->mock_http_responses( [ $this->success_response() ] );

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertCount( 1, $this->captured_requests );
	}

	public function test_processes_rate_limit_headers_on_success(): void {
		// Response with rate limit headers indicating low remaining.
		$headers = [
			'x-ratelimit-remaining' => '0',
			'x-ratelimit-reset'     => (string) ( time() + 2 ),
		];
		$this->mock_http_responses( [ $this->success_response( $headers ) ] );

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );

		// Check that a rate limit block was set.
		$blocked_until = wp_cache_get( 'vip_agentforce_rate_limit_blocked_until', 'vip_agentforce' );
		$this->assertNotFalse( $blocked_until, 'Should set rate limit block when remaining is low' );
	}

	// =========================================================================
	// Multiple retry tests
	// =========================================================================

	public function test_retries_multiple_times_before_success(): void {
		$this->mock_http_responses(
			[
				$this->rate_limited_response( 0 ),
				$this->rate_limited_response( 0 ),
				$this->success_response(),
			]
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertCount( 3, $this->captured_requests, 'Should make 3 attempts before success' );
	}

	// =========================================================================
	// HTTP-date Retry-After format test
	// =========================================================================

	public function test_parses_http_date_retry_after(): void {
		$retry_date = gmdate( 'D, d M Y H:i:s', time() + 1 ) . ' GMT';
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
				$this->success_response(),
			]
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertCount( 2, $this->captured_requests );
	}

	// =========================================================================
	// Server error (5xx) retry tests
	// =========================================================================

	public function test_retries_on_500_and_eventually_succeeds(): void {
		$this->mock_http_responses(
			[
				$this->error_response( 500, 'Internal Server Error' ),
				$this->success_response(),
			]
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertCount( 2, $this->captured_requests, 'Should retry after 500' );
	}

	public function test_retries_on_502_and_eventually_succeeds(): void {
		$this->mock_http_responses(
			[
				$this->error_response( 502, 'Bad Gateway' ),
				$this->success_response(),
			]
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertCount( 2, $this->captured_requests, 'Should retry after 502' );
	}

	public function test_retries_on_503_with_retry_after_header(): void {
		$this->mock_http_responses(
			[
				[
					'response' => [
						'code'    => 503,
						'message' => 'Service Unavailable',
					],
					'headers'  => [
						'retry-after' => '0',
					],
					'body'     => '',
				],
				$this->success_response(),
			]
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertCount( 2, $this->captured_requests, 'Should retry after 503' );
	}

	public function test_retries_on_504_and_eventually_succeeds(): void {
		$this->mock_http_responses(
			[
				$this->error_response( 504, 'Gateway Timeout' ),
				$this->success_response(),
			]
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertCount( 2, $this->captured_requests, 'Should retry after 504' );
	}

	public function test_retries_on_408_request_timeout(): void {
		$this->mock_http_responses(
			[
				$this->error_response( 408, 'Request Timeout' ),
				$this->success_response(),
			]
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertTrue( $result->success );
		$this->assertCount( 2, $this->captured_requests, 'Should retry after 408' );
	}

	public function test_fails_after_max_retries_on_5xx(): void {
		// 11 responses for 1 initial + 10 retries.
		$this->mock_http_responses(
			array_fill( 0, 11, $this->error_response( 500 ) )
		);

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'Server error (500)', $result->error_message );
		$this->assertCount( 11, $this->captured_requests, 'Should make 11 attempts (1 + 10 retries)' );
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

		$client = new Test_Ingestion_API_Client();
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

		$client = new Test_Ingestion_API_Client();
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

		$client = new Test_Ingestion_API_Client();
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

		$client = new Test_Ingestion_API_Client();
		$record = $this->create_test_record();

		$result = $client->send( $record );

		$this->assertFalse( $result->success );
		$this->assertCount( 1, $this->captured_requests, 'Should NOT retry on 404' );
	}
}
