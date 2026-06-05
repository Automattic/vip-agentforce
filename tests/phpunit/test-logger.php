<?php

use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;
use Automattic\VIP\Salesforce\Agentforce\Utils\Testable_Logger;

/**
 * Tests for Automattic\VIP\Salesforce\Agentforce\Utils\Logger
 */
class TestLogger extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Testable_Logger::clear_entries();
	}

	public function tearDown(): void {
		delete_option( 'vip_agentforce_ingestion_log_verbosity' );
		remove_all_filters( 'vip_agentforce_ingestion_log_verbosity' );
		Testable_Logger::clear_entries();
		parent::tearDown();
	}

	/**
	 * Ensure that a warning is logged when a user is logged in.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_warning_logged_when_user_logged_in() {
		// Create and set current user.
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// Call the method that should attach the set_current_user hook.
		Logger::warning_log_if_user_logged_in( 'test-feature', 'User logged in test', [ 'key' => 'value' ] );

		// Trigger the hook by setting current user.
		wp_set_current_user( $user_id );

		$entries = Testable_Logger::get_entries();

		$this->assertNotEmpty( $entries, 'Expected at least one log entry.' );
		$this->assertEquals( 'warning', $entries[0]['severity'] );
		$this->assertEquals( 'test-feature', $entries[0]['feature'] );
		$this->assertEquals( 'User logged in test', $entries[0]['message'] );
	}

	/**
	 * Ensure that no warning is logged when no user is logged in.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_warning_logged_when_user_not_logged_in() {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		Logger::warning_log_if_user_logged_in( 'test-feature', 'No user logged in', [] );

		// Trigger the hook (user 0 is not logged in).
		wp_set_current_user( 0 );

		$entries = Testable_Logger::get_entries();

		$this->assertEmpty( $entries, 'No log entries should be present when user is not logged in.' );
	}

	public function test_ingestion_log_verbosity_defaults_to_normal_outside_local_dev(): void {
		$this->assertSame( 'normal', Logger::get_ingestion_log_verbosity() );
		$this->assertFalse( Logger::is_verbose_ingestion_logging() );
	}

	public function test_ingestion_log_verbosity_accepts_verbose_option(): void {
		update_option( 'vip_agentforce_ingestion_log_verbosity', 'verbose', false );

		$this->assertSame( 'verbose', Logger::get_ingestion_log_verbosity() );
		$this->assertTrue( Logger::is_verbose_ingestion_logging() );
	}

	public function test_ingestion_log_verbosity_filter_can_override_option(): void {
		update_option( 'vip_agentforce_ingestion_log_verbosity', 'normal', false );

		add_filter(
			'vip_agentforce_ingestion_log_verbosity',
			function () {
				return 'verbose';
			}
		);

		$this->assertSame( 'verbose', Logger::get_ingestion_log_verbosity() );
	}

	public function test_ingestion_log_verbosity_rejects_invalid_values(): void {
		update_option( 'vip_agentforce_ingestion_log_verbosity', 'debug', false );

		$this->assertSame( 'normal', Logger::get_ingestion_log_verbosity() );
	}
}
