<?php
/**
 * Assets class.
 */

namespace Automattic\VIP\Salesforce\Agentforce\Cmp;

use Automattic\VIP\Salesforce\Agentforce\Utils\Traits\Singleton;
use Automattic\VIP\Salesforce\Agentforce\Utils\Traits\WithPluginPaths;

/**
 * Class Assets
 */
class Assets {

	use Singleton;
	use WithPluginPaths;

	/**
	 * Construct method.
	 */
	protected function __construct() {
		$this->setup_hooks();
	}

	/**
	 * To setup action/filter.
	 *
	 * @return void
	 */
	protected function setup_hooks() {

		/**
		 * Action
		 */
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'af_enqueue_cookieyes_consent_script' ) );
	}

	/**
	 * To enqueue scripts and styles.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {

		wp_register_script(
			'vip-agentforce-script',
			$this->get_integration_url() . '/assets/build/js/main.js',
			array(),
			filemtime( $this->get_integration_path() . '/assets/build/js/main.js' ),
			true
		);

		wp_register_style(
			'vip-agentforce-style',
			$this->get_integration_url() . '/assets/build/css/main.css',
			array(),
			filemtime( $this->get_integration_path() . '/assets/build/css/main.css' )
		);

		wp_enqueue_script( 'vip-agentforce-script' );
		wp_enqueue_style( 'vip-agentforce-style' );
	}

	/**
	 * To enqueue scripts and styles. in admin.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts() {

		wp_register_script(
			'vip-agentforce-script',
			$this->get_integration_url() . '/assets/build/js/admin.js',
			array(),
			filemtime( $this->get_integration_path() . '/assets/build/js/admin.js' ),
			true
		);

		wp_register_style(
			'vip-agentforce-style',
			$this->get_integration_url() . '/assets/build/css/admin.css',
			array(),
			filemtime( $this->get_integration_path() . '/assets/build/css/admin.css' )
		);

		wp_enqueue_script( 'vip-agentforce-script' );
		wp_enqueue_style( 'vip-agentforce-style' );
	}

	/**
	 * Enqueue the consent script based on the selected consent type.
	 *
	 * @return void
	 */
	/**
	 * Default OneTrust consent group ID
	 */
	const DEFAULT_ONETRUST_GROUP_ID = 'C0004';

	/**
	 * Default Cookiebot category
	 */
	const DEFAULT_COOKIEBOT_CATEGORY = 'marketing';

	/**
	 * Default iubenda Purpose ID
	 */
	const DEFAULT_IUBENDA_PURPOSE_ID = '5';

	/**
	 * Enqueue the consent script based on the selected consent type.
	 *
	 * @return void
	 */
	public function af_enqueue_cookieyes_consent_script() {
		$enable_sdk = get_option( 'agentforce_enable_sdk', 1 );
		if ( ! $enable_sdk ) {
			return; // Do not load SDK if disabled.
		}
		$salesforce_sdk_url = get_option( 'agentforce_salesforce_sdk_url', '' );
		$consent_type       = get_option( 'agentforce_consent_type', 'CookieYes' );
		$onetrust_group_id  = get_option( 'agentforce_onetrust_group_id', self::DEFAULT_ONETRUST_GROUP_ID );
		$cookiebot_category = get_option( 'agentforce_cookiebot_category', self::DEFAULT_COOKIEBOT_CATEGORY );
		$iubenda_purpose_id = get_option( 'agentforce_iubenda_category', self::DEFAULT_IUBENDA_PURPOSE_ID );

		$consent_scripts = array(
			'CookieYes' => 'cmpcookieyes',
			'CookieBot' => 'cmpcookiebot',
			'OneTrust'  => 'cmponetrust',
			'iubenda'   => 'cmpiubenda',
			'Custom'    => 'cmpcustom',
		);

		if ( ! isset( $consent_scripts[ $consent_type ] ) ) {
			return;
		}

		$script_handle      = 'af-' . strtolower( $consent_type ) . '-consent';
		$script_file        = $consent_scripts[ $consent_type ] . '.js';
		$library_asset_file = include sprintf(
			'%s/assets/build/js/%s.asset.php',
			$this->get_integration_path(),
			$consent_scripts[ $consent_type ]
		);

		wp_register_script(
			$script_handle,
			$this->get_integration_url() . '/assets/build/js/' . $script_file,
			$library_asset_file['dependencies'],
			$library_asset_file['version'],
			true
		);

		$localize_data = array(
			'sdkUrl' => esc_url( $salesforce_sdk_url ),
		);

		if ( 'OneTrust' === $consent_type ) {
			$localize_data['groupId'] = $onetrust_group_id;
		} elseif ( 'CookieBot' === $consent_type ) {
			$localize_data['cookiebotCategory'] = $cookiebot_category;
		} elseif ( 'iubenda' === $consent_type ) {
			$localize_data['iubendaPurposeId'] = $iubenda_purpose_id;
		}

		wp_localize_script(
			$script_handle,
			'afConsentData',
			$localize_data
		);

		wp_enqueue_script( $script_handle );
	}
}
