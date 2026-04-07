<?php

use Automattic\VIP\Salesforce\Agentforce\Cmp\Agentforce;
use Automattic\VIP\Salesforce\Agentforce\Cmp\Assets;
use Automattic\VIP\Salesforce\Agentforce\Cmp\Settings_Page;
use Automattic\VIP\Salesforce\Agentforce\Constants;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

class Cmp_Tests extends WP_UnitTestCase {

	/**
	 * Dequeue and deregister consent scripts to keep tests isolated.
	 *
	 * @param string $handle Script handle.
	 */
	private function reset_consent_script( string $handle ): void {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}

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

	private function get_embedding_script_fixture(): string {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture intentionally contains script tags.
		return <<<'HTML'
<script type="text/javascript">
function initEmbeddedMessaging() {
	window.__agentforceInitCalled = true;
}
</script>
<script type="text/javascript" src="https://example.local/assets/js/bootstrap.min.js" onload="initEmbeddedMessaging()"></script>
HTML;
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}

	/**
	 * Set or clear the Agentforce debug query string for current request.
	 *
	 * @param string|null $value Query value.
	 * @return void
	 */
	private function set_debug_query_value( ?string $value ): void {
		if ( null === $value ) {
			unset( $_GET['vip_agentforce_debug'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$_GET['vip_agentforce_debug'] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function tearDown(): void {
		delete_option( 'vip_agentforce_consent_type' );
		delete_option( 'vip_agentforce_onetrust_group_id' );
		delete_option( 'vip_agentforce_cookiebot_category' );
		delete_option( 'vip_agentforce_iubenda_category' );
		delete_option( 'vip_agentforce_alignment' );
		delete_option( 'vip_agentforce_custom_css' );
		delete_option( 'vip_agentforce_enable_oplog' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );
		$this->reset_consent_script( 'vip-af-cookiebot-consent' );
		$this->reset_consent_script( 'vip-af-onetrust-consent' );
		$this->reset_consent_script( 'vip-af-iubenda-consent' );
		$this->reset_consent_script( 'vip-af-custom-consent' );
		remove_all_filters( 'vip_agentforce_debug_preview_capabilities' );
		$this->set_debug_query_value( null );
		wp_set_current_user( 0 );

		Configs::flush_cache();

		parent::tearDown();
	}

	public function test_consent_script_not_enqueued_when_sdk_disabled(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => false,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue if SDK is not activated.'
		);
	}

	public function test_consent_script_not_enqueued_when_embedding_script_is_missing(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue when embedding script is missing.'
		);
	}

	public function test_consent_script_not_enqueued_when_embedding_script_is_invalid(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture intentionally contains script tags.
				'agentforce_embedding_script' => '<script src="https://example.local/assets/js/bootstrap.min.js"></script>',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue when embedding script parsing fails.'
		);
	}

	public function test_consent_script_not_enqueued_when_bootstrap_url_is_http(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture intentionally contains script tags.
				'agentforce_embedding_script' => '<script>function initEmbeddedMessaging(){window.__agentforceInitCalled=true;}</script><script src="http://example.local/assets/js/bootstrap.min.js" onload="initEmbeddedMessaging()"></script>',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue when bootstrap src is not HTTPS.'
		);
	}

	public function test_cookieyes_script_enqueued_with_localized_embedding_data(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
				'site_key'                    => 'site-key-123',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertTrue(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should enqueue when SDK is activated.'
		);

		$localized_data = wp_scripts()->get_data( 'vip-af-cookieyes-consent', 'data' );
		$this->assertStringContainsString( '"embedding":{"bootstrapSrc":"', $localized_data );
		$this->assertStringContainsString( 'bootstrap.min.js', $localized_data );
		$this->assertStringNotContainsString( '"onloadCallback"', $localized_data );
		$this->assertStringNotContainsString( '"sdkUrl"', $localized_data );

		$inline_data = wp_scripts()->get_data( 'vip-af-cookieyes-consent', 'before' );
		if ( is_array( $inline_data ) ) {
			$inline_data = implode( "\n", $inline_data );
		}
		$this->assertStringContainsString( 'function initEmbeddedMessaging()', strval( $inline_data ) );
	}

	public function test_debug_preview_enqueues_custom_cmp_for_users_with_manage_options(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
				'site_key'                    => 'site-key-123',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );
		$this->set_debug_query_value( 'true' );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );
		$this->reset_consent_script( 'vip-af-custom-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertTrue(
			wp_script_is( 'vip-af-custom-consent', 'enqueued' ),
			'Custom consent script should be enqueued when the logged-in user has manage_options.'
		);
		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Provider-specific consent script should be skipped for debug preview requests.'
		);
	}

	public function test_debug_preview_enqueues_even_when_sdk_is_disabled(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => false,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );
		$this->set_debug_query_value( 'true' );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );
		$this->reset_consent_script( 'vip-af-custom-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertTrue(
			wp_script_is( 'vip-af-custom-consent', 'enqueued' ),
			'Debug preview should enqueue custom consent script even when SDK activation is disabled.'
		);
		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Provider-specific consent script should still be skipped in debug preview mode.'
		);
	}

	public function test_debug_preview_does_not_bypass_cmp_for_logged_in_users_without_required_capability(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
				'site_key'                    => 'site-key-123',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );
		$this->set_debug_query_value( 'true' );

		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );
		$this->reset_consent_script( 'vip-af-custom-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertTrue(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Users without manage_options should still use the configured CMP flow.'
		);
		$this->assertFalse(
			wp_script_is( 'vip-af-custom-consent', 'enqueued' ),
			'Custom consent script should not be enqueued for users without required capability.'
		);
	}

	public function test_debug_preview_capability_filter_can_allow_additional_capabilities(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
				'site_key'                    => 'site-key-123',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );
		$this->set_debug_query_value( 'true' );

		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$allowed_capabilities_filter = function (): array {
			return [ 'manage_options', 'edit_pages' ];
		};
		add_filter( 'vip_agentforce_debug_preview_capabilities', $allowed_capabilities_filter );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );
		$this->reset_consent_script( 'vip-af-custom-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		remove_filter( 'vip_agentforce_debug_preview_capabilities', $allowed_capabilities_filter );

		$this->assertTrue(
			wp_script_is( 'vip-af-custom-consent', 'enqueued' ),
			'Custom consent script should enqueue when filtered capabilities include a capability the user has.'
		);
		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Configured CMP script should be skipped when filtered capability access is granted.'
		);
	}

	public function test_debug_preview_does_not_bypass_cmp_for_logged_out_users(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
				'site_key'                    => 'site-key-123',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );
		$this->set_debug_query_value( 'true' );
		wp_set_current_user( 0 );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );
		$this->reset_consent_script( 'vip-af-custom-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertTrue(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Logged-out users should still use the configured CMP flow.'
		);
		$this->assertFalse(
			wp_script_is( 'vip-af-custom-consent', 'enqueued' ),
			'Custom consent script should not be enqueued for logged-out users.'
		);
	}

	public function test_debug_preview_auto_loads_agentforce_sdk(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );
		$this->set_debug_query_value( 'true' );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$this->reset_consent_script( 'vip-af-custom-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$inline_data = wp_scripts()->get_data( 'vip-af-custom-consent', 'after' );
		if ( is_array( $inline_data ) ) {
			$inline_data = implode( "\n", $inline_data );
		}

		$this->assertStringContainsString( 'window.AgentforceCMP.loadSDK()', strval( $inline_data ) );
	}

	public function test_supported_cmp_asset_files_are_present(): void {
		$integration_path = dirname( VIP_AGENTFORCE_FILE );

		foreach ( Constants::SUPPORTED_CMPS as $cmp ) {

			$consent_script_filename_no_ext = 'cmp' . strtolower( $cmp );
			$asset_file                     = $integration_path . '/assets/build/js/' . $consent_script_filename_no_ext . '.asset.php';

			$this->assertFileExists(
				$asset_file,
				sprintf( 'Missing consent asset PHP file for CMP "%s": %s', $cmp, $asset_file )
			);
			$this->assertTrue(
				is_readable( $asset_file ),
				sprintf( 'Consent asset PHP file is not readable for CMP "%s": %s', $cmp, $asset_file )
			);

			$library_asset_file = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

			$this->assertIsArray( $library_asset_file, sprintf( 'Consent asset PHP file did not return an array for CMP "%s".', $cmp ) );
			$this->assertArrayHasKey( 'dependencies', $library_asset_file );
			$this->assertIsArray( $library_asset_file['dependencies'] );
			$this->assertArrayHasKey( 'version', $library_asset_file );
		}
	}

	public function test_onetrust_localization_uses_default_group(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'OneTrust' );
		delete_option( 'vip_agentforce_onetrust_group_id' );

		$this->reset_consent_script( 'vip-af-onetrust-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'vip-af-onetrust-consent', 'data' );
		$this->assertStringContainsString( '"groupId":"' . Constants::DEFAULT_ONETRUST_GROUP_ID . '"', $localized_data );
	}

	public function test_cookiebot_localization_uses_default_category(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieBot' );
		delete_option( 'vip_agentforce_cookiebot_category' );

		$this->reset_consent_script( 'vip-af-cookiebot-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'vip-af-cookiebot-consent', 'data' );
		$this->assertStringContainsString( '"cookiebotCategory":"' . Constants::DEFAULT_COOKIEBOT_CATEGORY . '"', $localized_data );
	}

	public function test_iubenda_localization_uses_default_purpose_id(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'iubenda' );
		delete_option( 'vip_agentforce_iubenda_category' );

		$this->reset_consent_script( 'vip-af-iubenda-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'vip-af-iubenda-consent', 'data' );
		$this->assertStringContainsString( '"iubendaPurposeId":"' . Constants::DEFAULT_IUBENDA_PURPOSE_ID . '"', $localized_data );
	}

	public function test_localization_includes_prechat_fields(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
				'site_key'                    => 'site-key-123',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'vip-af-cookieyes-consent', 'data' );
		$this->assertStringContainsString( '"prechatFields":', $localized_data );
		$this->assertStringContainsString( '"site_id_blog_id":', $localized_data );
		$this->assertStringContainsString( '"site_id_blog_id":"site-key-123"', $localized_data );
	}

	public function test_prechat_fields_use_legacy_site_id_blog_id_key(): void {
		$this->prime_configs_cache( [ 'site_key' => 'site-key-123' ] );

		$fields = Configs::get_prechat_fields();

		$this->assertArrayHasKey( 'site_id_blog_id', $fields );
		$this->assertSame( 'site-key-123', $fields['site_id_blog_id'] );
	}

	public function test_prechat_fields_filter_adds_custom_fields(): void {
		$this->prime_configs_cache( [ 'site_key' => 'site-key-123' ] );

		$filter = function ( array $fields ): array {
			$fields['custom_field'] = 'custom_value';
			return $fields;
		};

		add_filter( 'vip_agentforce_prechat_fields', $filter );
		$fields = Configs::get_prechat_fields();
		remove_filter( 'vip_agentforce_prechat_fields', $filter );

		$this->assertArrayHasKey( 'site_id_blog_id', $fields );
		$this->assertSame( 'custom_value', $fields['custom_field'] );
	}

	public function test_prechat_fields_filter_rejects_non_array_return(): void {
		$this->prime_configs_cache( [ 'site_key' => 'site-key-123' ] );

		$filter = function (): string {
			return 'garbage';
		};

		add_filter( 'vip_agentforce_prechat_fields', $filter );
		$fields = Configs::get_prechat_fields();
		remove_filter( 'vip_agentforce_prechat_fields', $filter );

		// Should fall back to the default fields.
		$this->assertArrayHasKey( 'site_id_blog_id', $fields );
	}

	public function test_prechat_fields_filter_strips_non_string_values(): void {
		$filter = function ( array $fields ): array {
			$fields['bad_int']   = 123;
			$fields['bad_array'] = array( 'nope' );
			$fields['good']      = 'yes';
			return $fields;
		};

		add_filter( 'vip_agentforce_prechat_fields', $filter );
		$fields = Configs::get_prechat_fields();
		remove_filter( 'vip_agentforce_prechat_fields', $filter );

		$this->assertArrayNotHasKey( 'bad_int', $fields );
		$this->assertArrayNotHasKey( 'bad_array', $fields );
		$this->assertSame( 'yes', $fields['good'] );
	}

	public function test_sanitize_consent_type_returns_value_for_supported_cmp(): void {
		$settings = Settings_Page::get_instance();

		$this->assertSame( 'CookieBot', $settings->sanitize_consent_type( 'CookieBot' ) );
	}

	public function test_sanitize_consent_type_falls_back_to_default_for_invalid_value(): void {
		$settings = Settings_Page::get_instance();

		$this->assertSame( Constants::DEFAULT_CMP, $settings->sanitize_consent_type( 'InvalidCMP' ) );
		$this->assertSame( Constants::DEFAULT_CMP, $settings->sanitize_consent_type( 'onetrust' ) );
	}

	public function test_sdk_activation_status_is_readonly_and_reflects_config(): void {
		$settings = Settings_Page::get_instance();

		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => false,
			]
		);

		ob_start();
		$settings->render_enable_sdk_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="agentforce-sdk-activation-status"', $output );
		$this->assertStringContainsString( 'data-status="inactive"', $output );
		$this->assertStringNotContainsString( '<input', $output );
	}

	public function test_embedding_script_status_is_readonly_and_reflects_config(): void {
		$settings = Settings_Page::get_instance();

		$this->prime_configs_cache(
			[
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		ob_start();
		$settings->render_embedding_script_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="agentforce-embedding-script-status"', $output );
		$this->assertStringContainsString( 'data-status="configured"', $output );
		$this->assertStringContainsString( 'Configured', $output );
		$this->assertStringNotContainsString( '<input', $output );
	}

	public function test_embedding_script_status_is_not_configured_when_script_is_invalid(): void {
		$settings = Settings_Page::get_instance();

		$this->prime_configs_cache(
			[
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture intentionally contains script tags.
				'agentforce_embedding_script' => '<script src="http://example.local/assets/js/bootstrap.min.js"></script>',
			]
		);

		ob_start();
		$settings->render_embedding_script_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="agentforce-embedding-script-status"', $output );
		$this->assertStringContainsString( 'data-status="not-configured"', $output );
		$this->assertStringContainsString( 'Not configured', $output );
		$this->assertStringNotContainsString( '<input', $output );
	}

	public function test_settings_page_uses_salesforce_js_embed_label(): void {
		$settings = Settings_Page::get_instance();

		ob_start();
		$settings->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Salesforce JS Embed', $output );
		$this->assertStringNotContainsString( 'Salesforce SDK URL', $output );
	}

	public function test_render_custom_css_includes_alignment_and_sanitizes_css(): void {
		update_option( 'vip_agentforce_alignment', 'bottom-left' );
		update_option( 'vip_agentforce_custom_css', 'body { color: red; }' );

		ob_start();
		Agentforce::get_instance()->render_custom_css();
		$output = ob_get_clean();

		$this->assertStringContainsString( '.embedded-messaging > .embeddedMessagingFrame { left: 10px }', $output );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'style id="agentforce-custom-css">body { color: red; }</style>', $output );
	}

	public function test_validation_returns_old_values_on_invalid_input(): void {
		$settings = Settings_Page::get_instance();

		update_option( 'vip_agentforce_iubenda_category', '3' );

		$this->assertSame(
			'3',
			$settings->validate_iubenda_category( '0' )
		);
	}
}
