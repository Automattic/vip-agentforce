<?php

use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

class ClassConfigsTest extends WP_UnitTestCase {
	public function test_returns_config(): void {
		$this->assertEquals(
			[ 'salesforce_instance_url' => 'https://example.my.salesforce.com' ],
			Configs::get_config()
		);
	}
}
