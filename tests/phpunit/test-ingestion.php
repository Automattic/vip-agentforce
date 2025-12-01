<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;

class Ingestion_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Ingestion::init();
	}

	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		remove_all_filters( 'vip_agentforce_transform_post' );
	}

	public function test_save_post_hook_is_registered(): void {
		$this->assertEquals( 10, has_action( 'save_post', [ Ingestion::class, 'ingest_post' ] ) );
	}

	public function test_returns_false_when_no_filter_registered(): void {
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$post   = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$result = Ingestion::should_ingest_post( $post );

		$this->assertFalse( $result, 'Should return false when no filter is registered (safety default).' );
	}

	public function test_published_post_returns_true_when_filter_opts_in(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		$result = Ingestion::should_ingest_post( $post );

		$this->assertTrue( $result );
	}

	/**
	 * Data provider for non-published post statuses.
	 *
	 * @return array<string, array{status: string, post_date?: string}>
	 */
	public function non_published_statuses_provider(): array {
		return [
			'draft'      => [ 'status' => 'draft' ],
			'pending'    => [ 'status' => 'pending' ],
			'private'    => [ 'status' => 'private' ],
			'trash'      => [ 'status' => 'trash' ],
			'auto-draft' => [ 'status' => 'auto-draft' ],
			'future'     => [
				'status'    => 'future',
				'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ),
			],
		];
	}

	/**
	 * @dataProvider non_published_statuses_provider
	 */
	public function test_should_not_ingest_if_not_published( string $status, ?string $post_date = null ): void {
		$args = [ 'post_status' => $status ];
		if ( $post_date ) {
			$args['post_date'] = $post_date;
		}

		$post   = $this->factory()->post->create_and_get( $args );
		$result = Ingestion::should_ingest_post( $post );

		$this->assertFalse( $result, "Post with status '{$status}' should not be ingested." );
	}

	public function test_filter_can_block_published_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_false' );

		$result = Ingestion::should_ingest_post( $post );

		$this->assertFalse( $result );
	}

	public function test_filter_cannot_override_draft_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );

		// Even if filter returns true, draft posts should still return false.
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		$result = Ingestion::should_ingest_post( $post );

		$this->assertFalse( $result );
	}

	public function test_filter_receives_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		/** @var \WP_Post|null $received_post */
		$received_post = null;

		add_filter(
			'vip_agentforce_should_ingest_post',
			function ( $should_ingest, $filter_post ) use ( &$received_post ) {
				$received_post = $filter_post;
				return true;
			},
			10,
			2
		);

		Ingestion::should_ingest_post( $post );

		$this->assertNotNull( $received_post );
		$this->assertInstanceOf( WP_Post::class, $received_post );
		$this->assertEquals( $post->ID, $received_post->ID );
	}

	public function test_filter_not_called_for_non_published_posts(): void {
		$post          = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
		$filter_called = false;

		add_filter(
			'vip_agentforce_should_ingest_post',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Testing if filter is called, not its value.
			function ( $should_ingest ) use ( &$filter_called ) {
				$filter_called = true;
				return true;
			}
		);

		Ingestion::should_ingest_post( $post );

		$this->assertFalse( $filter_called );
	}

	public function test_transform_post_returns_null_without_filter(): void {
		remove_all_filters( 'vip_agentforce_transform_post' );

		$post   = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$result = Ingestion::transform_post( $post );

		$this->assertNull( $result );
	}

	public function test_transform_post_returns_record_with_valid_filter(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter(
			'vip_agentforce_transform_post',
			function ( $record, $filter_post ) {
				return new Ingestion_Post_Record(
					[
						'site_id'                 => '1',
						'blog_id'                 => '1',
						'post_id'                 => (string) $filter_post->ID,
						'site_id_blog_id'         => '1_1',
						'site_id_blog_id_post_id' => '1_1_' . $filter_post->ID,
						'published'               => true,
						'last_published_at'       => '2025-01-01T00:00:00+00:00',
						'last_modified_at'        => '2025-01-01T00:00:00+00:00',
						'title'                   => $filter_post->post_title,
						'content'                 => $filter_post->post_content,
						'excerpt'                 => $filter_post->post_excerpt,
						'categories'              => '',
						'tags'                    => '',
						'author'                  => '',
						'url'                     => 'https://example.com',
						'post_type'               => $filter_post->post_type,
						'post_status'             => $filter_post->post_status,
					]
				);
			},
			10,
			2
		);

		$result = Ingestion::transform_post( $post );

		$this->assertInstanceOf( Ingestion_Post_Record::class, $result );
		$this->assertSame( (string) $post->ID, $result->post_id );
	}

	public function test_transform_post_returns_null_when_filter_returns_wrong_type(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter(
			'vip_agentforce_transform_post',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Testing wrong return type.
			function ( $record, $filter_post ) {
				return [ 'not' => 'a record object' ];
			},
			10,
			2
		);

		$result = Ingestion::transform_post( $post );

		$this->assertNull( $result );
	}

	public function test_transform_post_filter_receives_post(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_title'  => 'Test Title for Filter',
				'post_status' => 'publish',
			]
		);
		/** @var \WP_Post|null $received_post */
		$received_post = null;

		add_filter(
			'vip_agentforce_transform_post',
			function ( $record, $filter_post ) use ( &$received_post ) {
				$received_post = $filter_post;
				return new Ingestion_Post_Record(
					[
						'site_id'                 => '1',
						'blog_id'                 => '1',
						'post_id'                 => '1',
						'site_id_blog_id'         => '1_1',
						'site_id_blog_id_post_id' => '1_1_1',
						'published'               => true,
						'last_published_at'       => '2025-01-01T00:00:00+00:00',
						'last_modified_at'        => '2025-01-01T00:00:00+00:00',
						'title'                   => 'Title',
						'content'                 => 'Content',
						'excerpt'                 => 'Excerpt',
						'categories'              => '',
						'tags'                    => '',
						'author'                  => '',
						'url'                     => 'https://example.com',
						'post_type'               => 'post',
						'post_status'             => 'publish',
					]
				);
			},
			10,
			2
		);

		Ingestion::transform_post( $post );

		$this->assertNotNull( $received_post );
		$this->assertInstanceOf( WP_Post::class, $received_post );
		$this->assertEquals( $post->ID, $received_post->ID );
		$this->assertSame( 'Test Title for Filter', $received_post->post_title );
	}
}
