<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Deletion_Failure;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

require_once __DIR__ . '/doubles/class-ingestion-with-failing-delete-api.php';

class Ingestion_Deletion_Test extends WP_UnitTestCase {

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
		Ingestion::init();

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
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		remove_all_filters( 'vip_agentforce_transform_post' );
		remove_all_actions( 'vip_agentforce_post_deletion_failed' );
		Configs::flush_cache();
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Helper to set up a post that was "previously ingested" (has tracking meta).
	 *
	 * @param \WP_Post $post The post to mark as ingested.
	 */
	private function mark_post_as_ingested( \WP_Post $post ): void {
		update_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, time() );
	}

	/**
	 * Helper to set up filters for a valid ingestion.
	 */
	private function setup_ingestion_filters(): void {
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );
		add_filter(
			'vip_agentforce_transform_post',
			function ( $record, $post ) {
				return new Ingestion_Post_Record(
					[
						'site_id'                 => '1',
						'blog_id'                 => '1',
						'post_id'                 => (string) $post->ID,
						'site_id_blog_id'         => '1_1',
						'site_id_blog_id_post_id' => '1_1_' . $post->ID,
						'published'               => true,
						'last_published_at'       => '2025-01-01T00:00:00+00:00',
						'last_modified_at'        => '2025-01-01T00:00:00+00:00',
						'title'                   => $post->post_title,
						'content'                 => $post->post_content,
						'excerpt'                 => $post->post_excerpt,
						'categories'              => '',
						'tags'                    => '',
						'author'                  => '',
						'url'                     => 'https://example.com',
						'post_type'               => $post->post_type,
						'post_status'             => $post->post_status,
					]
				);
			},
			10,
			2
		);
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
	// Post Meta Tracking Tests
	// =========================================================================

	public function test_ingestion_sets_meta_on_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->setup_ingestion_filters();

		// Trigger ingestion.
		Ingestion::on_save_post( $post->ID, $post );

		// Verify meta was set.
		$meta = get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true );
		$this->assertNotEmpty( $meta, 'Ingestion should set tracking meta on post.' );
	}

	public function test_successful_deletion_clears_meta(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->mark_post_as_ingested( $post );

		// Verify meta exists before deletion.
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Trigger deletion (unpublish).
		Ingestion::handle_post_unpublished( 'draft', 'publish', $post );

		// Verify meta was cleared.
		$meta = get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true );
		$this->assertEmpty( $meta, 'Successful deletion should clear tracking meta.' );
	}

	public function test_meta_key_constant_is_defined(): void {
		$this->assertSame( 'vip_agentforce_ingestion_attempted', Ingestion::META_KEY_INGESTION_ATTEMPTED );
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
	public function test_unpublish_triggers_deletion_when_meta_exists( string $new_status ): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->mark_post_as_ingested( $post );

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
		// Meta should be cleared on success.
		$this->assertFalse( $deletion_attempted, "No failure should occur on successful deletion (publish -> {$new_status})." );
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ), 'Meta should be cleared after deletion.' );
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
		$this->mark_post_as_ingested( $post );

		Ingestion_With_Failing_Delete_Api::init();

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( $new_status, $old_status, $post );

		$this->assertFalse( $deletion_attempted, "No deletion should occur for {$old_status} -> {$new_status}." );
	}

	// =========================================================================
	// Post Meta-Based Deletion Tests
	// =========================================================================

	public function test_unpublish_without_meta_does_not_delete(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		// Do NOT set meta - post was never ingested.

		Ingestion_With_Failing_Delete_Api::init();

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( 'draft', 'publish', $post );

		$this->assertFalse( $deletion_attempted, 'No deletion should occur when post has no ingestion meta.' );
	}

	public function test_unpublish_with_meta_triggers_deletion(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->mark_post_as_ingested( $post );

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

		$this->assertTrue( $deletion_attempted, 'Deletion should be attempted when post has ingestion meta.' );
	}

	public function test_deletion_works_even_when_filter_changed(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->mark_post_as_ingested( $post );

		// Filter now returns false (developer changed it), but post was previously ingested.
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

		$this->assertTrue( $deletion_attempted, 'Deletion should occur based on meta, not current filter state.' );
	}

	// =========================================================================
	// Permanent Deletion Tests (before_delete_post)
	// =========================================================================

	public function test_delete_published_post_with_meta_triggers_deletion(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->mark_post_as_ingested( $post );

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

		$this->assertTrue( $deletion_attempted, 'Deletion should be attempted for published post with meta.' );
	}

	public function test_delete_published_post_without_meta_does_not_delete(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		// Do NOT set meta.

		Ingestion_With_Failing_Delete_Api::init();

		$deletion_attempted = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$deletion_attempted ) {
				$deletion_attempted = true;
			}
		);

		Ingestion_With_Failing_Delete_Api::handle_post_deleted( $post->ID, $post );

		$this->assertFalse( $deletion_attempted, 'No deletion should occur for published post without meta.' );
	}

	public function test_delete_draft_post_does_not_trigger_deletion(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
		$this->mark_post_as_ingested( $post ); // Even with meta, drafts shouldn't trigger deletion.

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

	// =========================================================================
	// Failure Action Tests
	// =========================================================================

	public function test_deletion_failure_action_fires_on_api_error(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->mark_post_as_ingested( $post );

		Ingestion_With_Failing_Delete_Api::init();

		$action_fired = false;
		/** @var Deletion_Failure|null $received_failure */
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
		$this->mark_post_as_ingested( $post );

		Ingestion_With_Failing_Delete_Api::init();

		/** @var Deletion_Failure|null $received_failure */
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
		$this->mark_post_as_ingested( $post );

		Ingestion_With_Failing_Delete_Api::init();

		/** @var Deletion_Failure|null $received_failure */
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

	public function test_deletion_failure_to_array(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->mark_post_as_ingested( $post );

		Ingestion_With_Failing_Delete_Api::init();

		/** @var Deletion_Failure|null $received_failure */
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

	public function test_meta_not_cleared_on_deletion_failure(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->mark_post_as_ingested( $post );

		Ingestion_With_Failing_Delete_Api::init();

		Ingestion_With_Failing_Delete_Api::handle_post_unpublished( 'draft', 'publish', $post );

		// Meta should NOT be cleared on failure - we still need to track that the post is in Salesforce.
		$meta = get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true );
		$this->assertNotEmpty( $meta, 'Meta should NOT be cleared when deletion fails.' );
	}

	// =========================================================================
	// Success Tests (no failure action)
	// =========================================================================

	public function test_deletion_failure_action_does_not_fire_on_success(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->mark_post_as_ingested( $post );

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
