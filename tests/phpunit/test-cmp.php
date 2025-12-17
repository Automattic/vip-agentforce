<?php

use Automattic\VIP\Salesforce\Agentforce\Cmp\Agentforce;
use Automattic\VIP\Salesforce\Agentforce\Cmp\Assets;
use Automattic\VIP\Salesforce\Agentforce\Cmp\Settings_Page;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

class Cmp_Tests extends WP_UnitTestCase {

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

	public function tearDown(): void {
		delete_option( 'agentforce_consent_type' );
		delete_option( 'agentforce_onetrust_group_id' );
		delete_option( 'agentforce_cookiebot_category' );
		delete_option( 'agentforce_iubenda_category' );
		delete_option( 'agentforce_alignment' );
		delete_option( 'agentforce_custom_css' );
		delete_option( 'agentforce_enable_oplog' );

		Configs::flush_cache();

		parent::tearDown();
	}

	public function test_consent_script_not_enqueued_when_sdk_disabled(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => false,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		update_option( 'agentforce_consent_type', 'CookieYes' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue if SDK is not activated.'
		);
	}

	public function test_cookieyes_script_enqueued_with_localized_sdk_url(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		update_option( 'agentforce_consent_type', 'CookieYes' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertTrue(
			wp_script_is( 'af-cookieyes-consent', 'enqueued' ),
			'Consent script should enqueue when SDK is activated.'
		);

		$localized_data = wp_scripts()->get_data( 'af-cookieyes-consent', 'data' );

		// WP Version 6.9 changed how the data is returned.
		if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
			$this->assertStringContainsString( '"sdkUrl":"https:\/\/example.local"', $localized_data );
		} else {
			$this->assertStringContainsString( '"sdkUrl":"https://example.local"', $localized_data );
		}
	}

	public function test_onetrust_localization_uses_default_group(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		update_option( 'agentforce_consent_type', 'OneTrust' );
		delete_option( 'agentforce_onetrust_group_id' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'af-onetrust-consent', 'data' );
		$this->assertStringContainsString( '"groupId":"' . Assets::DEFAULT_ONETRUST_GROUP_ID . '"', $localized_data );
	}

	public function test_sdk_activation_status_is_readonly_and_reflects_config(): void {
		$settings = Settings_Page::get_instance();

		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => false,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		ob_start();
		$settings->render_enable_sdk_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="agentforce-sdk-activation-status"', $output );
		$this->assertStringContainsString( 'data-status="inactive"', $output );
		$this->assertStringNotContainsString( '<input', $output );
	}

	public function test_sdk_url_is_readonly_and_reflects_config(): void {
		$settings = Settings_Page::get_instance();

		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		ob_start();
		$settings->render_sdk_url_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="agentforce-sdk-url"', $output );
		$this->assertStringContainsString( 'data-url="https://example.local"', $output );
		$this->assertStringNotContainsString( 'name="agentforce_salesforce_sdk_url"', $output );
	}

	public function test_render_custom_css_includes_alignment_and_sanitizes_css(): void {
		update_option( 'agentforce_alignment', 'bottom-left' );
		update_option( 'agentforce_custom_css', 'body { color: red; }' );

		ob_start();
		Agentforce::get_instance()->wp_af_custom_css_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '.embedded-messaging > .embeddedMessagingFrame { left: 10px }', $output );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'style id="agentforce-custom-css">body { color: red; }</style>', $output );
	}

	public function test_validation_returns_old_values_on_invalid_input(): void {
		$settings = Settings_Page::get_instance();

		update_option( 'agentforce_iubenda_category', '3' );

		$this->assertSame(
			'3',
			$settings->validate_iubenda_category( '0' )
		);
	}
}
