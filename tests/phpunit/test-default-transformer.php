<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Default_Transformer;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;

class Default_Transformer_Test extends WP_UnitTestCase {

	/**
	 * Prime Configs cache for deterministic tests.
	 *
	 * @param array<string, mixed> $config
	 */
	private function prime_configs_cache( array $config ): void {
		$ref  = new ReflectionClass( \Automattic\VIP\Salesforce\Agentforce\Utils\Configs::class );
		$prop = $ref->getProperty( 'cached_config' );
		$prop->setAccessible( true );
		$prop->setValue( null, $config );
	}

	public function setUp(): void {
		parent::setUp();
		Default_Transformer::init();
	}

	public function tearDown(): void {
		\Automattic\VIP\Salesforce\Agentforce\Utils\Configs::flush_cache();
		parent::tearDown();
		remove_all_filters( 'vip_agentforce_transform_post' );
	}

	public function test_filter_is_registered(): void {
		$this->assertEquals( 10, has_filter( 'vip_agentforce_transform_post', [ Default_Transformer::class, 'transform' ] ) );
	}

	public function test_transforms_post_to_record(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_title'   => 'Test Post',
				'post_content' => 'Test content here.',
				'post_excerpt' => 'Test excerpt.',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertInstanceOf( Ingestion_Post_Record::class, $record );
		$this->assertSame( 'Test Post', $record->title );
		$this->assertSame( 'Test content here.', $record->content );
		$this->assertSame( 'Test excerpt.', $record->excerpt );
		$this->assertSame( 'publish', $record->post_status );
		$this->assertSame( 'post', $record->post_type );
		$this->assertTrue( $record->published );
	}

	public function test_returns_existing_record_if_already_transformed(): void {
		$post            = $this->factory()->post->create_and_get();
		$existing_record = new Ingestion_Post_Record(
			[
				'site_id'                 => '999',
				'blog_id'                 => '1',
				'site_key'                => '',
				'post_id'                 => '123',
				'site_id_blog_id'         => '999_1',
				'site_id_blog_id_post_id' => 'custom_id',
				'published'               => true,
				'last_published_at'       => '2025-01-01T00:00:00+00:00',
				'last_modified_at'        => '2025-01-01T00:00:00+00:00',
				'title'                   => 'Custom Title',
				'content'                 => 'Custom content',
				'excerpt'                 => 'Custom excerpt',
				'categories'              => 'Custom Cat',
				'tags'                    => 'custom-tag',
				'author'                  => 'Custom Author',
				'url'                     => 'https://custom.example.com',
				'post_type'               => 'custom-type',
				'post_status'             => 'publish',
			]
		);

		$result = Default_Transformer::transform( $existing_record, $post );

		$this->assertSame( $existing_record, $result );
		$this->assertSame( 'Custom Title', $result->title );
	}

	public function test_composite_ids_are_built_correctly(): void {
		$this->prime_configs_cache( [ 'site_key' => '101_1_site-key-123' ] );
		$post = $this->factory()->post->create_and_get();

		$record = Default_Transformer::transform( null, $post );

		$expected_site_id  = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';
		$expected_blog_id  = (string) get_current_blog_id();
		$expected_post_id  = (string) $post->ID;
		$expected_compound = $expected_site_id . '_' . $expected_blog_id . '_' . $expected_post_id;

		$this->assertSame( $expected_site_id, $record->site_id );
		$this->assertSame( $expected_blog_id, $record->blog_id );
		$this->assertSame( $expected_post_id, $record->post_id );
		$this->assertSame( '101_1_site-key-123', $record->site_id_blog_id );
		$this->assertSame( $expected_compound, $record->site_id_blog_id_post_id );
	}

	public function test_dates_are_formatted_as_iso8601(): void {
		$post_id = $this->factory()->post->create(
			[
				'post_date_gmt'     => '2025-11-25 12:30:45',
				'post_modified_gmt' => '2025-11-26 09:15:30',
			]
		);
		// Manually update post_modified_gmt since factory may override it.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup only.
		$wpdb->update(
			$wpdb->posts,
			[ 'post_modified_gmt' => '2025-11-26 09:15:30' ],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );
		$post = get_post( $post_id );

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( '2025-11-25T12:30:45+00:00', $record->last_published_at );
		$this->assertSame( '2025-11-26T09:15:30+00:00', $record->last_modified_at );
	}

	public function test_categories_are_comma_separated(): void {
		$cat1 = $this->factory()->category->create( [ 'name' => 'Technology' ] );
		$cat2 = $this->factory()->category->create( [ 'name' => 'Testing' ] );
		$post = $this->factory()->post->create_and_get();
		wp_set_post_categories( $post->ID, [ $cat1, $cat2 ] );

		$record = Default_Transformer::transform( null, $post );

		$this->assertStringContainsString( 'Technology', $record->categories );
		$this->assertStringContainsString( 'Testing', $record->categories );
		$this->assertStringContainsString( ', ', $record->categories );
	}

	public function test_tags_are_comma_separated(): void {
		$post = $this->factory()->post->create_and_get();
		wp_set_post_tags( $post->ID, [ 'api', 'test', 'data-cloud' ] );

		$record = Default_Transformer::transform( null, $post );

		$this->assertStringContainsString( 'api', $record->tags );
		$this->assertStringContainsString( 'test', $record->tags );
		$this->assertStringContainsString( 'data-cloud', $record->tags );
		$this->assertStringContainsString( ', ', $record->tags );
	}

	public function test_no_custom_categories_returns_uncategorized(): void {
		// WordPress assigns "Uncategorized" by default.
		$post = $this->factory()->post->create_and_get();

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( 'Uncategorized', $record->categories );
	}

	public function test_empty_tags_returns_empty_string(): void {
		$post = $this->factory()->post->create_and_get();

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( '', $record->tags );
	}

	public function test_author_name_is_resolved(): void {
		$user_id = $this->factory()->user->create(
			[
				'display_name' => 'John Doe',
			]
		);
		$post    = $this->factory()->post->create_and_get( [ 'post_author' => $user_id ] );

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( 'John Doe', $record->author );
	}

	public function test_invalid_author_returns_empty_string(): void {
		$post              = $this->factory()->post->create_and_get();
		$post->post_author = 99999;

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( '', $record->author );
	}

	public function test_permalink_is_set(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_name'   => 'test-post-slug',
				'post_status' => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertNotEmpty( $record->url );
		// Test uses plain permalinks by default, so URL contains ?p=ID.
		$this->assertStringContainsString( '?p=' . $post->ID, $record->url );
	}

	public function test_draft_post_has_published_false(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );

		$record = Default_Transformer::transform( null, $post );

		$this->assertFalse( $record->published );
		$this->assertSame( 'draft', $record->post_status );
	}

	public function test_content_is_stripped_to_plain_text(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => "<!-- wp:paragraph -->\n<p>Hello <strong>world</strong> &amp; friends</p>\n<!-- /wp:paragraph -->",
				'post_status'  => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		// Gutenberg delimiters and HTML tags removed, entities decoded.
		$this->assertSame( 'Hello world & friends', $record->content );
	}

	public function test_adjacent_blocks_do_not_fuse_into_one_word(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => '<p>Alpha</p><p>Beta</p><ul><li>one</li><li>two</li></ul>',
				'post_status'  => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( "Alpha\nBeta\none\ntwo", $record->content );
	}

	public function test_inline_markup_does_not_split_words(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => '<p>Word<em>Press</em> is <strong>great</strong></p>',
				'post_status'  => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( 'WordPress is great', $record->content );
	}

	public function test_self_closing_blocks_separate_their_neighbours(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => '<!-- wp:heading --><h2>Alpha</h2><!-- /wp:heading --><!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator --><!-- wp:paragraph --><p>Beta</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( "Alpha\nBeta", $record->content );
	}

	public function test_links_keep_their_target_alongside_their_text(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => '<p>See <a href="https://example.com/docs">the docs</a> for more.</p>',
				'post_status'  => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( 'See the docs (https://example.com/docs) for more.', $record->content );
	}

	public function test_images_keep_their_alt_text_and_source(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => '<p>Before</p><img src="https://example.com/thumb.jpg" alt="A red bicycle" /><p>After</p>',
				'post_status'  => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( "Before\nA red bicycle (image: https://example.com/thumb.jpg)\nAfter", $record->content );
	}

	public function test_an_image_without_alt_text_still_keeps_its_source(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => '<p>Before</p><img src="https://example.com/chart.png" /><p>After</p>',
				'post_status'  => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( "Before\n(image: https://example.com/chart.png)\nAfter", $record->content );
	}

	/**
	 * A base64 image addresses nothing an agent could fetch, and one of them can
	 * be hundreds of KB — the whole content budget spent on a single image.
	 */
	public function test_inline_base64_images_keep_only_their_alt_text(): void {
		$post               = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$post->post_content = '<p>Before</p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==" alt="A tiny dot" /><p>After</p>';

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( "Before\nA tiny dot\nAfter", $record->content );
	}

	public function test_a_linked_image_keeps_its_alt_text_and_both_urls(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => '<a href="https://example.com/full.jpg"><img src="https://example.com/thumb.jpg" alt="Example image"></a>',
				'post_status'  => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		// The thumbnail is what's shown, the href is where it goes: both are
		// worth keeping, and a thumbnail is rarely the full-size file.
		$this->assertSame(
			'Example image (image: https://example.com/thumb.jpg) (https://example.com/full.jpg)',
			$record->content
		);
	}

	public function test_in_page_anchors_and_self_titled_links_are_not_annotated(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => '<p><a href="#section-two">Jump to section two</a></p><p><a href="https://example.com">https://example.com</a></p>',
				'post_status'  => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( "Jump to section two\nhttps://example.com", $record->content );
	}

	/**
	 * Entities are decoded after tags are stripped, so markup an author escaped
	 * in order to write about it stays in the index as the text a reader sees.
	 * Decoding first would feed the sample back to wp_strip_all_tags, which drops
	 * script contents, and it would disappear entirely.
	 */
	public function test_escaped_markup_survives_as_text(): void {
		$post               = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$post->post_content = '<p>Example: &lt;script&gt;alert(1)&lt;/script&gt;</p>';

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( 'Example: <script>alert(1)</script>', $record->content );
	}

	public function test_script_style_and_svg_markup_are_removed(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		// Set on the object rather than in the DB: kses strips script/style on save
		// for users without unfiltered_html, and we want the transformer's own
		// handling under test.
		$post->post_content = '<p>Visible</p><script>var hidden = 1;</script><style>.x{color:red}</style><svg viewBox="0 0 10 10"><path d="M0 0 L10 10"/></svg>';

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( 'Visible', $record->content );
	}

	public function test_oversized_content_is_capped_under_the_limit(): void {
		$ref = new ReflectionClass( Default_Transformer::class );
		$max = (int) $ref->getConstant( 'MAX_CONTENT_BYTES' );

		// ~480 KB of plain text — well over the cap.
		$post = $this->factory()->post->create_and_get(
			[
				'post_content' => str_repeat( 'lorem ipsum ', 40000 ),
				'post_status'  => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertLessThanOrEqual(
			$max,
			strlen( $record->content ),
			'Content must be capped under the Data Cloud 200 KB request limit.'
		);
	}

	/**
	 * The API sizes the encoded body, where wp_json_encode turns each non-ASCII
	 * character into a \uXXXX escape. A raw-byte cap passes while the request
	 * that gets sent is still twice the limit, so assert on the encoded body.
	 *
	 * @dataProvider multibyte_content_provider
	 *
	 * @param string $unit  Repeated to build the post content.
	 * @param int    $times Repeat count.
	 */
	public function test_multibyte_content_stays_under_the_limit_once_encoded( string $unit, int $times ): void {
		$post               = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$post->post_content = str_repeat( $unit, $times );

		$record = Default_Transformer::transform( null, $post );
		$body   = wp_json_encode( [ 'data' => [ $record->to_array() ] ] );

		$this->assertLessThan(
			200000,
			strlen( $body ),
			'The encoded request body must stay under the Data Cloud 200 KB limit.'
		);
		$this->assertNotSame( '', $record->content, 'Content must survive the cap, not be emptied.' );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function multibyte_content_provider(): array {
		return [
			// 3 bytes raw, 6 encoded.
			'japanese' => [ '日本語のテキストです。', 30000 ],
			// 4 bytes raw, 12 encoded.
			'emoji'    => [ '🎉', 100000 ],
			// Mixed, so the inflation ratio isn't uniform across the string.
			'mixed'    => [ 'Latin text 日本語 🎉 more latin ', 20000 ],
		];
	}
}
