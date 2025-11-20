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
		// Capture the output of the render function
		ob_start();
		Admin_Status::status_page_content();
		$output = ob_get_clean();

		// Check that the output contains expected content

		$this->assertStringContainsString( 'Salesforce Instance URL:', $output );
		$this->assertStringContainsString( '<strong>Salesforce Instance URL:</strong> <pre>https://example.my.salesforce.com</pre>', $output );
	}
}
