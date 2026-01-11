<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_CLI_Test extends WP_UnitTestCase {

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

		// Set up config for API calls via cache priming.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
			]
		);

		// Mock HTTP requests to return success.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Only mock requests to our test Salesforce instance.
				if ( strpos( $url, 'test.salesforce.com' ) !== false ) {
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
}
