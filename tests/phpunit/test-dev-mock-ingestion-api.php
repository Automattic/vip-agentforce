<?php
/**
 * Tests for the local dev ingestion API mock.
 *
 * @package vip-agentforce
 */

class Dev_Mock_Ingestion_API_Test extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/dev/mock-ingestion-api.php';

		remove_filter( 'pre_http_request', 'vip_agentforce_mock_ingestion_api_handle_request', 10 );
		add_filter( 'pre_http_request', 'vip_agentforce_mock_ingestion_api_handle_request', 10, 3 );

		delete_option( 'vip_agentforce_mock_ingestion_scenario' );
		delete_option( 'vip_agentforce_mock_ingestion_retry_after' );
		delete_option( 'vip_agentforce_mock_ingestion_requests' );
		delete_option( 'vip_agentforce_mock_ingestion_token' );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', 'vip_agentforce_mock_ingestion_api_handle_request', 10 );
		delete_option( 'vip_agentforce_mock_ingestion_scenario' );
		delete_option( 'vip_agentforce_mock_ingestion_retry_after' );
		delete_option( 'vip_agentforce_mock_ingestion_requests' );
		delete_option( 'vip_agentforce_mock_ingestion_token' );

		parent::tearDown();
	}

	public function test_unknown_token_keeps_default_success_smoke_behavior(): void {
		$response = $this->request_with_token( 'fake-token' );

		$this->assertSame( 202, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( 'Accepted', wp_remote_retrieve_response_message( $response ) );
	}

	public function test_bearer_token_selects_mock_status(): void {
		$response = $this->request_with_token( 'mock:401' );

		$this->assertSame( 401, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( 'Unauthorized', wp_remote_retrieve_response_message( $response ) );
	}

	public function test_body_param_overrides_token_scenario(): void {
		$response = $this->request_with_token(
			'mock:202',
			[
				'vip_agentforce_mock_scenario' => '403',
			]
		);

		$this->assertSame( 403, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( 'Forbidden', wp_remote_retrieve_response_message( $response ) );
	}

	public function test_rate_limit_scenario_sets_retry_after_header(): void {
		update_option( 'vip_agentforce_mock_ingestion_retry_after', 7, false );

		$response = $this->request_with_token( 'mock:429' );

		$this->assertSame( 429, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( '7', wp_remote_retrieve_header( $response, 'retry-after' ) );
	}

	public function test_network_scenario_returns_wp_error(): void {
		$response = $this->request_with_token( 'mock:network' );

		$this->assertWPError( $response );
		$this->assertSame( 'vip_agentforce_mock_network_error', $response->get_error_code() );
	}

	public function test_rotate_recover_token_returns_401_and_rotates_config_token_option(): void {
		update_option( 'vip_agentforce_mock_ingestion_token', 'mock:rotate-recover', false );

		$response = $this->request_with_token( 'mock:rotate-recover' );

		$this->assertSame( 401, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( 'mock:202', get_option( 'vip_agentforce_mock_ingestion_token' ) );
	}

	public function test_mock_requests_are_recorded_without_real_token_values(): void {
		$this->request_with_token( 'real-looking-token' );

		$requests = get_option( 'vip_agentforce_mock_ingestion_requests', [] );

		$this->assertIsArray( $requests );
		$this->assertSame( 'non-mock-token', $requests[0]['token'] );
		$this->assertSame( '202', $requests[0]['status'] );
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request_with_token( string $token, array $body = [] ) {
		$args = [
			'method'  => 'POST',
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
			],
			'body'    => wp_json_encode( $body ),
		];

		return apply_filters(
			'pre_http_request',
			false,
			$args,
			'https://local-ingestion.example.test/api/v1/ingest/sources/wpvip_agents/wordpress_post'
		);
	}
}
