<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Deletion_Failure;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;

require_once __DIR__ . '/doubles/class-ingestion-with-failing-delete-api.php';

class Ingestion_Deletion_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Ingestion::init();
	}

	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		remove_all_actions( 'vip_agentforce_post_deletion_failed' );
	}

	// =========================================================================
	// Hook Registration Tests
	// =========================================================================

	public function test_transition_post_status_hook_is_registered(): void {
		$this->assertEquals( 10, has_action( 'transition_post_status', [ Ingestion::class, 'handle_post_unpublished' ] ) );
	}

	public function test_before_delete_post_hook_is_registered(): void {
		$this->assertEquals( 10, has_action( 'before_delete_post', [ Ingestion::class, 'handle_post_deleted' ] ) );
	}

	// =========================================================================
	// Unpublishing Tests (transition_post_status)
	// =========================================================================

	/**
	 * Data provider for unpublish transitions that should trigger deletion.
	 *
	 * @return array<string, array{new_status: string}>
	 */
	public function unpublish_statuses_provider(): array {
		return [
			'publish_to_draft'   => [ 'new_status' => 'draft' ],
			'publish_to_pending' => [ 'new_status' => 'pending' ],
			'publish_to_private' => [ 'new_status' => 'private' ],
			'publish_to_trash'   => [ 'new_status' => 'trash' ],
		];
	}

	/**
	 * @dataProvider unpublish_statuses_provider
	 */
	public function test_unpublish_triggers_deletion_when_filter_opts_in( string $new_status ): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		// Simulate status transition.
		Ingestion::handle_post_unpublished( $new_status, 'publish', $post );

		// Since delete_from_api returns success by default, no failure action should fire.
		// We can't easily verify the deletion was attempted without mocking,
		// but we can verify no failure occurred.
		$this->assertFalse( $deletion_attempted, "No failure should occur on successful deletion (publish -> {$new_status})." );
	}

	/**
	 * Data provider for transitions that should NOT trigger deletion.
	 *
	 * @return array<string, array{old_status: string, new_status: string}>
	 */
	public function non_deletion_transitions_provider(): array {
		return [
			'draft_to_publish'   => [
				'old_status' => 'draft',
				'new_status' => 'publish',
			],
			'draft_to_trash'     => [
				'old_status' => 'draft',
				'new_status' => 'trash',
			],
			'pending_to_draft'   => [
				'old_status' => 'pending',
				'new_status' => 'draft',
			],
			'publish_to_publish' => [
				'old_status' => 'publish',
				'new_status' => 'publish',
			],
			'trash_to_draft'     => [
				'old_status' => 'trash',
				'new_status' => 'draft',
			],
		];
	}

	/**
	 * @dataProvider non_deletion_transitions_provider
	 */
	public function test_non_publish_transitions_do_not_trigger_deletion( string $old_status, string $new_status ): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => $old_status ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		Ingestion::handle_post_unpublished( $new_status, $old_status, $post );

		$this->assertFalse( $deletion_attempted, "No deletion should occur for {$old_status} -> {$new_status}." );
	}

	// =========================================================================
	// Filter Opt-In Tests (Unpublishing)
	// =========================================================================

	public function test_unpublish_without_filter_registered_does_not_delete(): void {
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Use the failing API test double to ensure we can detect if deletion is attempted.
		Ingestion_With_Failing_Delete_Api::init();

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( 'draft', 'publish', $post );

		$this->assertFalse( $deletion_attempted, 'No deletion should occur when no filter is registered.' );
	}

	public function test_unpublish_with_filter_returning_false_does_not_delete(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_false' );

		Ingestion_With_Failing_Delete_Api::init();

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( 'draft', 'publish', $post );

		$this->assertFalse( $deletion_attempted, 'No deletion should occur when filter returns false.' );
	}

	public function test_unpublish_with_filter_returning_true_triggers_deletion(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		// Use failing API to detect deletion attempt.
		Ingestion_With_Failing_Delete_Api::init();

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( 'draft', 'publish', $post );

		$this->assertTrue( $deletion_attempted, 'Deletion should be attempted when filter returns true.' );
	}

	// =========================================================================
	// Permanent Deletion Tests (before_delete_post)
	// =========================================================================

	public function test_delete_published_post_triggers_deletion(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		// Use failing API to detect deletion attempt.
		Ingestion_With_Failing_Delete_Api::init();

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_deleted( $post->ID, $post );

		$this->assertTrue( $deletion_attempted, 'Deletion should be attempted for published post.' );
	}

	public function test_delete_draft_post_does_not_trigger_deletion(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		Ingestion_With_Failing_Delete_Api::init();

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_deleted( $post->ID, $post );

		$this->assertFalse( $deletion_attempted, 'No deletion should occur for draft post.' );
	}

	public function test_delete_without_filter_registered_does_not_delete(): void {
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		Ingestion_With_Failing_Delete_Api::init();

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_deleted( $post->ID, $post );

		$this->assertFalse( $deletion_attempted, 'No deletion should occur when no filter is registered.' );
	}

	// =========================================================================
	// Failure Action Tests
	// =========================================================================

	public function test_deletion_failure_action_fires_on_api_error(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		Ingestion_With_Failing_Delete_Api::init();

		$action_fired     = false;
		$received_failure = null;

		add_action(
			'vip_agentforce_post_deletion_failed',
			function ( $failure ) use ( &$action_fired, &$received_failure ) {
				$action_fired     = true;
				$received_failure = $failure;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( 'draft', 'publish', $post );

		$this->assertTrue( $action_fired, 'Failure action should fire on API error.' );
		$this->assertInstanceOf( Deletion_Failure::class, $received_failure );
		$this->assertSame( Deletion_Failure::CODE_DELETE_API_ERROR, $received_failure->failure_code );
		$this->assertTrue( $received_failure->is_delete_api_error() );
	}

	public function test_deletion_failure_contains_record_id(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		Ingestion_With_Failing_Delete_Api::init();

		$received_failure = null;

		add_action(
			'vip_agentforce_post_deletion_failed',
			function ( $failure ) use ( &$received_failure ) {
				$received_failure = $failure;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( 'draft', 'publish', $post );

		$this->assertInstanceOf( Deletion_Failure::class, $received_failure );
		$this->assertNotEmpty( $received_failure->record_id );
		// Record ID format: site_id_blog_id_post_id.
		$this->assertStringContainsString( (string) $post->ID, $received_failure->record_id );
	}

	public function test_deletion_failure_contains_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		Ingestion_With_Failing_Delete_Api::init();

		$received_failure = null;

		add_action(
			'vip_agentforce_post_deletion_failed',
			function ( $failure ) use ( &$received_failure ) {
				$received_failure = $failure;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( 'draft', 'publish', $post );

		$this->assertInstanceOf( Deletion_Failure::class, $received_failure );
		$this->assertInstanceOf( WP_Post::class, $received_failure->post );
		$this->assertSame( $post->ID, $received_failure->post->ID );
	}

	public function test_deletion_failure_contains_backtrace(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		Ingestion_With_Failing_Delete_Api::init();

		$received_failure = null;

		add_action(
			'vip_agentforce_post_deletion_failed',
			function ( $failure ) use ( &$received_failure ) {
				$received_failure = $failure;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( 'draft', 'publish', $post );

		$this->assertInstanceOf( Deletion_Failure::class, $received_failure );
		$error_data = $received_failure->error->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertArrayHasKey( 'backtrace', $error_data );
		$this->assertIsArray( $error_data['backtrace'] );
		$this->assertNotEmpty( $error_data['backtrace'] );
	}

	public function test_deletion_failure_to_array(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		Ingestion_With_Failing_Delete_Api::init();

		$received_failure = null;

		add_action(
			'vip_agentforce_post_deletion_failed',
			function ( $failure ) use ( &$received_failure ) {
				$received_failure = $failure;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( 'draft', 'publish', $post );

		$this->assertInstanceOf( Deletion_Failure::class, $received_failure );
		$array = $received_failure->to_array();
		$this->assertSame( Deletion_Failure::CODE_DELETE_API_ERROR, $array['failure_code'] );
		$this->assertSame( $post->ID, $array['post_id'] );
		$this->assertNotEmpty( $array['record_id'] );
		$this->assertSame( 'vip_agentforce_delete_api_error', $array['error_code'] );
		$this->assertNotEmpty( $array['error_message'] );
	}

	// =========================================================================
	// Success Tests (no failure action)
	// =========================================================================

	public function test_deletion_failure_action_does_not_fire_on_success(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		// Use the real Ingestion class (which has a successful delete_from_api).
		$action_fired = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		Ingestion::handle_post_unpublished( 'draft', 'publish', $post );

		$this->assertFalse( $action_fired, 'Failure action should NOT fire on successful deletion.' );
	}
}
