<?php
/**
 * Tests for Ingestion_Config_Filters class.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Config_Filters;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_Config_Filters_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Logger::disable();
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		Configs::flush_cache();
	}

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		Configs::flush_cache();
	}

	/**
	 * Prime Configs cache for deterministic tests.
	 *
	 * @param array<string, mixed> $config
	 */
	private function prime_configs_cache( array $config ): void {
		$ref  = new ReflectionClass( Configs::class );
		$prop = $ref->getProperty( 'cached_config' );
		$prop->setAccessible( true );
		$prop->setValue( null, $config );
	}

	// =========================================================================
	// Tests for Configs::should_sync_all_posts()
	// =========================================================================

	public function test_should_sync_all_posts_returns_false_when_not_configured(): void {
		$this->prime_configs_cache( [] );

		$this->assertFalse( Configs::should_sync_all_posts() );
	}

	public function test_should_sync_all_posts_returns_true_when_enabled(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => true ] );

		$this->assertTrue( Configs::should_sync_all_posts() );
	}

	public function test_should_sync_all_posts_returns_true_for_string_true(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => 'true' ] );

		$this->assertTrue( Configs::should_sync_all_posts() );
	}

	public function test_should_sync_all_posts_returns_false_when_disabled(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => false ] );

		$this->assertFalse( Configs::should_sync_all_posts() );
	}

	public function test_should_sync_all_posts_returns_false_for_invalid_value(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => 'invalid' ] );

		$this->assertFalse( Configs::should_sync_all_posts() );
	}

	// =========================================================================
	// Tests for Configs::get_ingestion_categories()
	// =========================================================================

	public function test_get_ingestion_categories_returns_empty_array_when_not_configured(): void {
		$this->prime_configs_cache( [] );

		$this->assertSame( [], Configs::get_ingestion_categories() );
	}

	public function test_get_ingestion_categories_returns_categories_array(): void {
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'news', 'blog' ] ] );

		$this->assertSame( [ 'news', 'blog' ], Configs::get_ingestion_categories() );
	}

	public function test_get_ingestion_categories_converts_int_ids_to_strings(): void {
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 1, 2, 'news' ] ] );

		$this->assertSame( [ '1', '2', 'news' ], Configs::get_ingestion_categories() );
	}

	public function test_get_ingestion_categories_returns_empty_for_non_array(): void {
		$this->prime_configs_cache( [ 'ingestion_api_categories' => 'not-an-array' ] );

		$this->assertSame( [], Configs::get_ingestion_categories() );
	}

	public function test_get_ingestion_categories_filters_out_invalid_values(): void {
		$this->prime_configs_cache(
			[
				'ingestion_api_categories' => [
					'news',
					null,
					'',
					[ 'nested' ],
					'blog',
				],
			]
		);

		$this->assertSame( [ 'news', 'blog' ], Configs::get_ingestion_categories() );
	}

	// =========================================================================
	// Tests for Ingestion_Config_Filters::init() with sync_all_posts
	// =========================================================================

	public function test_init_registers_return_true_filter_when_sync_all_posts_enabled(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => true ] );

		Ingestion_Config_Filters::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertTrue( Ingestion::should_ingest_post( $post ) );
	}

	public function test_init_does_not_register_filter_when_sync_all_posts_disabled(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => false ] );

		Ingestion_Config_Filters::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertFalse( Ingestion::should_ingest_post( $post ) );
	}

	// =========================================================================
	// Tests for Ingestion_Config_Filters::init() with categories
	// =========================================================================

	public function test_init_registers_categories_filter_when_configured(): void {
		$category = wp_insert_term( 'News', 'category' );
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'news' ] ] );

		Ingestion_Config_Filters::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post->ID, [ $category['term_id'] ] );

		$this->assertTrue( Ingestion::should_ingest_post( $post ) );
	}

	public function test_categories_filter_rejects_post_without_matching_category(): void {
		$category       = wp_insert_term( 'News', 'category' );
		$other_category = wp_insert_term( 'Sports', 'category' );
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'news' ] ] );

		Ingestion_Config_Filters::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post->ID, [ $other_category['term_id'] ] );

		$this->assertFalse( Ingestion::should_ingest_post( $post ) );
	}

	public function test_categories_filter_matches_by_category_id(): void {
		$category = wp_insert_term( 'News', 'category' );
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ (string) $category['term_id'] ] ] );

		Ingestion_Config_Filters::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post->ID, [ $category['term_id'] ] );

		$this->assertTrue( Ingestion::should_ingest_post( $post ) );
	}

	public function test_categories_filter_matches_any_configured_category(): void {
		$category1 = wp_insert_term( 'News', 'category' );
		$category2 = wp_insert_term( 'Blog', 'category' );
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'news', 'blog' ] ] );

		Ingestion_Config_Filters::init();

		// Post with second category.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post->ID, [ $category2['term_id'] ] );

		$this->assertTrue( Ingestion::should_ingest_post( $post ) );
	}

	public function test_categories_filter_returns_false_for_post_without_categories(): void {
		wp_insert_term( 'News', 'category' );
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'news' ] ] );

		Ingestion_Config_Filters::init();

		// Create post without setting categories (gets default "Uncategorized").
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertFalse( Ingestion::should_ingest_post( $post ) );
	}

	// =========================================================================
	// Tests for sync_all_posts taking precedence over categories
	// =========================================================================

	public function test_sync_all_posts_takes_precedence_over_categories(): void {
		wp_insert_term( 'Sports', 'category' );
		$this->prime_configs_cache(
			[
				'ingestion_api_sync_all_posts' => true,
				'ingestion_api_categories'     => [ 'news' ], // This should be ignored.
			]
		);

		Ingestion_Config_Filters::init();

		// Post with category not in the list should still be ingested.
		$sports_cat = get_term_by( 'slug', 'sports', 'category' );
		$post       = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post->ID, [ $sports_cat->term_id ] );

		$this->assertTrue( Ingestion::should_ingest_post( $post ) );
	}

	// =========================================================================
	// Tests for filter_by_categories preserving existing true values
	// =========================================================================

	public function test_categories_filter_preserves_existing_true_value(): void {
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'news' ] ] );

		// Add a filter that returns true first.
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true', 5 );

		Ingestion_Config_Filters::init();

		// Post without matching category should still be ingested due to earlier filter.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertTrue( Ingestion::should_ingest_post( $post ) );
	}
}
