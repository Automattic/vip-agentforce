<?php
/**
 * Custom Client module - main entry point.
 *
 * Provides a custom chat client UI for Salesforce Agentforce,
 * using the Messaging for In-App and Web API.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Custom_Client;

/**
 * Main Custom Client module class.
 */
class Custom_Client {

	/**
	 * Initialize the module.
	 */
	public static function init(): void {
		// Initialize REST API routes.
		REST_Controller::init();

		// Enqueue frontend assets.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// Add mount point for React app.
		add_action( 'wp_footer', [ __CLASS__, 'render_mount_point' ] );
	}

	/**
	 * Enqueue frontend JavaScript and CSS.
	 */
	public static function enqueue_assets(): void {
		// Use VIP_AGENTFORCE_FILE for consistent paths in VIP dev environments.
		$plugin_path = plugin_dir_path( VIP_AGENTFORCE_FILE );
		$plugin_url  = plugin_dir_url( VIP_AGENTFORCE_FILE );

		// Assets are in the main assets folder for proper web server serving.
		$assets_dir = $plugin_path . 'assets/build/js/custom-client/';
		$assets_url = $plugin_url . 'assets/build/js/custom-client/';

		// Check if built assets exist.
		if ( ! file_exists( $assets_dir . 'index.js' ) ) {
			// Assets not built yet, skip enqueueing.
			return;
		}

		// Enqueue the main JavaScript bundle.
		wp_enqueue_script(
			'vip-agentforce-custom-client',
			$assets_url . 'index.js',
			[],
			filemtime( $assets_dir . 'index.js' ),
			true
		);

		// Make it a module for ES6 support.
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle ) {
				if ( 'vip-agentforce-custom-client' === $handle ) {
					return str_replace( ' src', ' type="module" src', $tag );
				}
				return $tag;
			},
			10,
			2
		);

		// Enqueue CSS if it exists.
		if ( file_exists( $assets_dir . 'index.css' ) ) {
			wp_enqueue_style(
				'vip-agentforce-custom-client',
				$assets_url . 'index.css',
				[],
				filemtime( $assets_dir . 'index.css' )
			);
		}
	}

	/**
	 * Render the mount point for the React app in the footer.
	 */
	public static function render_mount_point(): void {
		$plugin_path = plugin_dir_path( VIP_AGENTFORCE_FILE );
		$assets_dir  = $plugin_path . 'assets/build/js/custom-client/';

		// Only render if assets are built.
		if ( ! file_exists( $assets_dir . 'index.js' ) ) {
			return;
		}

		echo '<div id="root"></div>';
	}
}

Custom_Client::init();
