<?php

use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

class ClassConfigsTest extends WP_UnitTestCase {
	public function tearDown(): void {
		remove_all_filters( 'vip_agentforce_config' );
		remove_all_filters( 'vip_integrations_pre_load_config' );
		Configs::flush_cache();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function prime_configs_cache( array $config ): void {
		$ref = new ReflectionClass( Configs::class );

		$config_prop = $ref->getProperty( 'cached_config' );
		$config_prop->setAccessible( true );
		$config_prop->setValue( null, $config );

		$detect_token_failure = $ref->getMethod( 'detect_ingestion_token_failure' );
		$detect_token_failure->setAccessible( true );

		$token_failure_prop = $ref->getProperty( 'cached_ingestion_token_failure' );
		$token_failure_prop->setAccessible( true );
		$token_failure_prop->setValue( null, $detect_token_failure->invoke( null, $config ) );

		$token_status_loaded_prop = $ref->getProperty( 'cached_ingestion_token_status_loaded' );
		$token_status_loaded_prop->setAccessible( true );
		$token_status_loaded_prop->setValue( null, true );
	}

	public function test_is_js_sdk_activated_defaults_to_false_when_missing(): void {
		$this->prime_configs_cache( [] );
		$this->assertFalse( Configs::is_js_sdk_activated() );
	}

	public function test_is_js_sdk_activated_true_when_truthy(): void {
		$this->prime_configs_cache( [ 'agentforce_js_sdk_activated' => true ] );
		$this->assertTrue( Configs::is_js_sdk_activated() );

		$this->prime_configs_cache( [ 'agentforce_js_sdk_activated' => 'true' ] );
		$this->assertTrue( Configs::is_js_sdk_activated() );
	}

	public function test_is_js_sdk_activated_false_when_falsey(): void {
		$this->prime_configs_cache( [ 'agentforce_js_sdk_activated' => false ] );
		$this->assertFalse( Configs::is_js_sdk_activated() );

		$this->prime_configs_cache( [ 'agentforce_js_sdk_activated' => '0' ] );
		$this->assertFalse( Configs::is_js_sdk_activated() );
	}

	public function test_get_js_sdk_url_returns_empty_when_missing(): void {
		$this->prime_configs_cache( [] );
		$this->assertSame( '', Configs::get_js_sdk_url() );
	}

	public function test_get_js_sdk_url_returns_string_when_present(): void {
		$this->prime_configs_cache( [ 'agentforce_js_sdk_url' => 'https://example.local' ] );
		$this->assertSame( 'https://example.local', Configs::get_js_sdk_url() );
	}

	public function test_get_js_sdk_url_returns_empty_when_non_string(): void {
		$this->prime_configs_cache( [ 'agentforce_js_sdk_url' => [ 'https://example.local' ] ] );
		$this->assertSame( '', Configs::get_js_sdk_url() );

		$this->prime_configs_cache( [ 'agentforce_js_sdk_url' => 123 ] );
		$this->assertSame( '', Configs::get_js_sdk_url() );

		$this->prime_configs_cache( [ 'agentforce_js_sdk_url' => null ] );
		$this->assertSame( '', Configs::get_js_sdk_url() );
	}

	public function test_get_embedding_script_returns_empty_when_missing(): void {
		$this->prime_configs_cache( [] );
		$this->assertSame( '', Configs::get_embedding_script() );
	}

	public function test_get_embedding_script_returns_string_when_present(): void {
		$this->prime_configs_cache(
			[
				'agentforce_embedding_script' => '<script>function initEmbeddedMessaging(){}</script>',
			]
		);
		$this->assertSame( '<script>function initEmbeddedMessaging(){}</script>', Configs::get_embedding_script() );
	}

	public function test_get_embedding_script_returns_empty_when_non_string(): void {
		$this->prime_configs_cache( [ 'agentforce_embedding_script' => [ '<script></script>' ] ] );
		$this->assertSame( '', Configs::get_embedding_script() );

		$this->prime_configs_cache( [ 'agentforce_embedding_script' => 123 ] );
		$this->assertSame( '', Configs::get_embedding_script() );

		$this->prime_configs_cache( [ 'agentforce_embedding_script' => null ] );
		$this->assertSame( '', Configs::get_embedding_script() );
	}

	public function test_has_valid_ingestion_token_returns_false_when_token_is_missing(): void {
		$config = [
			'ingestion_api_token' => '',
		];

		$this->prime_configs_cache( $config );

		$this->assertFalse( Configs::has_valid_ingestion_token() );
		$this->assertSame(
			[
				'message'     => 'Missing required API configuration: ingestion_api_token',
				'error_class' => 'config',
				'error_code'  => 'missing_api_config',
			],
			Configs::get_ingestion_token_failure()
		);
	}

	public function test_has_valid_ingestion_token_returns_false_when_token_expiry_is_invalid(): void {
		$config = [
			'ingestion_api_token'            => 'token',
			'ingestion_api_token_expires_at' => 'not-a-date',
		];

		$this->prime_configs_cache( $config );

		$this->assertFalse( Configs::has_valid_ingestion_token() );
		$this->assertSame(
			[
				'message'     => 'Ingestion API token expiry is invalid',
				'error_class' => 'auth',
				'error_code'  => 'token_invalid',
			],
			Configs::get_ingestion_token_failure()
		);
	}

	public function test_has_valid_ingestion_token_returns_false_when_token_expired(): void {
		$config = [
			'ingestion_api_token'            => 'token',
			'ingestion_api_token_expires_at' => time() - HOUR_IN_SECONDS,
		];

		$this->prime_configs_cache( $config );

		$this->assertFalse( Configs::has_valid_ingestion_token() );
		$this->assertSame(
			[
				'message'     => 'Ingestion API token has expired',
				'error_class' => 'auth',
				'error_code'  => 'token_expired',
			],
			Configs::get_ingestion_token_failure()
		);
	}

	public function test_has_valid_ingestion_token_returns_true_when_token_expiry_is_future(): void {
		$config = [
			'ingestion_api_token'            => 'token',
			'ingestion_api_token_expires_at' => time() + HOUR_IN_SECONDS,
		];

		$this->prime_configs_cache( $config );

		$this->assertTrue( Configs::has_valid_ingestion_token() );
		$this->assertNull( Configs::get_ingestion_token_failure() );
	}

	public function test_has_valid_ingestion_token_returns_true_when_token_expiry_is_absent(): void {
		$config = [
			'ingestion_api_token' => 'token',
		];

		$this->prime_configs_cache( $config );

		$this->assertTrue( Configs::has_valid_ingestion_token() );
		$this->assertNull( Configs::get_ingestion_token_failure() );
	}

	public function test_refresh_config_reloads_filtered_config_and_token_status(): void {
		$this->prime_configs_cache(
			[
				'ingestion_api_token'            => 'expired-token',
				'ingestion_api_token_expires_at' => time() - HOUR_IN_SECONDS,
			]
		);

		add_filter(
			'vip_agentforce_config',
			function () {
				return [
					'ingestion_api_token'            => 'fresh-token',
					'ingestion_api_token_expires_at' => time() + HOUR_IN_SECONDS,
				];
			}
		);

		$config = Configs::refresh_config();

		$this->assertSame( 'fresh-token', $config['ingestion_api_token'] );
		$this->assertTrue( Configs::has_valid_ingestion_token() );
		$this->assertNull( Configs::get_ingestion_token_failure() );
	}

	public function test_get_site_key_returns_empty_when_missing(): void {
		$this->prime_configs_cache( [] );
		$this->assertSame( '', Configs::get_site_key() );
	}

	public function test_get_site_key_returns_stored_value(): void {
		$this->prime_configs_cache( [ 'site_key' => 'site-key-123' ] );

		$this->assertSame( 'site-key-123', Configs::get_site_key() );
	}
}
