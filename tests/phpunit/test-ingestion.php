<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Record;

  /**
   * @phpstan-import-type Post_Ingestion_Record from Ingestion_Record
   */
class Ingestion_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Ingestion::init();
	}

	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'vip_agentforce_should_ingest_record' );
	}

	public function test_save_post_hook_is_registered(): void {
		$this->assertEquals( 10, has_action( 'save_post', [ Ingestion::class, 'on_save_post' ] ) );
	}

	public function test_returns_false_when_no_filter_registered(): void {
		remove_all_filters( 'vip_agentforce_should_ingest_record' );

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$ingestion_record = new Ingestion_Record( Ingestion_Record::TYPE_POST, $post );
		$result           = Ingestion::should_ingest_record( $ingestion_record );

		$this->assertFalse( $result, 'Should return false when no filter is registered (safety default).' );
	}

	public function test_published_post_returns_true_when_filter_opts_in(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_record', '__return_true' );

		$ingestion_record = new Ingestion_Record( Ingestion_Record::TYPE_POST, $post );
		$result           = Ingestion::should_ingest_record( $ingestion_record );

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

		$post = $this->factory()->post->create_and_get( $args );

		$ingestion_record = new Ingestion_Record( Ingestion_Record::TYPE_POST, $post );
		$result           = Ingestion::should_ingest_record( $ingestion_record );

		$this->assertFalse( $result, "Post with status '{$status}' should not be ingested." );
	}

	public function test_filter_can_block_published_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_record', '__return_false' );

		$ingestion_record = new Ingestion_Record( Ingestion_Record::TYPE_POST, $post );
		$result           = Ingestion::should_ingest_record( $ingestion_record );

		$this->assertFalse( $result );
	}

	public function test_filter_cannot_override_draft_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );

		// Even if filter returns true, draft posts should still return false.
		add_filter( 'vip_agentforce_should_ingest_record', '__return_true' );

		$ingestion_record = new Ingestion_Record( Ingestion_Record::TYPE_POST, $post );
		$result           = Ingestion::should_ingest_record( $ingestion_record );

		$this->assertFalse( $result );
	}

	public function test_filter_receives_ingestion_record(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		/** @var Post_Ingestion_Record|null $received_record */
		$received_record = null;

		add_filter(
			'vip_agentforce_should_ingest_record',
			function ( $should_ingest, $ingestion_record ) use ( &$received_record ) {
				$received_record = $ingestion_record;
				return true;
			},
			10,
			2
		);

		$ingestion_record = new Ingestion_Record( Ingestion_Record::TYPE_POST, $post );
		Ingestion::should_ingest_record( $ingestion_record );
		
		$this->assertNotNull( $received_record );
		$this->assertInstanceOf( Ingestion_Record::class, $received_record );
		$this->assertEquals( Ingestion_Record::TYPE_POST, $received_record->type );
		$this->assertInstanceOf( WP_Post::class, $received_record->record );
	}

	public function test_filter_not_called_for_non_published_posts(): void {
		$post          = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
		$filter_called = false;

		add_filter(
			'vip_agentforce_should_ingest_record',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Testing if filter is called, not its value.
			function ( $record ) use ( &$filter_called ) {
				$filter_called = true;
				return true;
			}
		);

		$ingestion_record = new Ingestion_Record( Ingestion_Record::TYPE_POST, $post );
		Ingestion::should_ingest_record( $ingestion_record );

		$this->assertFalse( $filter_called );
	}

	public function test_ingestion_record_constructor(): void {
		$post             = $this->factory()->post->create_and_get();
		$ingestion_record = new Ingestion_Record( Ingestion_Record::TYPE_POST, $post );

		$this->assertEquals( Ingestion_Record::TYPE_POST, $ingestion_record->type );
		$this->assertSame( $post, $ingestion_record->record );
	}
}
