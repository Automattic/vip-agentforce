<?php

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

use Automattic\VIP\Salesforce\Agentforce\Admin_Status\Admin_Status;

class Admin_Status_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		Admin_Status::init();
	}

	public function test_menu_appears(): void {
		$this->assertEquals( has_action( 'admin_menu', [ Admin_Status::class, 'add_options_page' ] ), 10 );
	}

	public function test_page_renders(): void {
		// Set up test credentials
		update_option( 'vip_agentforce_salesforce_credentials', [
			'instance_url' => 'https://example.my.salesforce.com',
			'access_token' => 'test_token_12345',
		] );

		// Mock wp_remote_get to prevent actual HTTP requests during tests
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			// Return a mock response for Salesforce API calls
			if ( strpos( $url, 'salesforce.com' ) !== false ) {
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => wp_json_encode( [
						'totalSize' => 0,
						'records'   => [],
					] ),
				];
			}
			return $preempt;
		}, 10, 3 );

		// Capture the output of the render function
		ob_start();
		Admin_Status::status_page_content();
		$output = ob_get_clean();

		// Check that the output contains expected content
		$this->assertStringContainsString( 'VIP Agentforce Settings', $output );
		$this->assertStringContainsString( 'Salesforce Instance URL', $output );
		$this->assertStringContainsString( 'Access Token', $output );
		$this->assertStringContainsString( 'name="salesforce_instance_url"', $output );
		$this->assertStringContainsString( 'https://example.my.salesforce.com', $output );
	}
}
