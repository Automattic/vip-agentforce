<?php
namespace Automattic\VIP\Salesforce\Agentforce\Admin_Status;

use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

class Admin_Status {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_options_page' ] );
	}

	public static function add_options_page(): void {
		add_options_page(
			__( 'Agentforce', 'vip-agentforce' ),
			__( 'Agentforce', 'vip-agentforce' ),
			'manage_options',
			'vip-agentforce-integration-status',
			[ __CLASS__, 'status_page_content' ]
		);
	}

	public static function status_page_content(): void {
		$config                  = Configs::get_config();
		$salesforce_instance_url = $config['salesforce_instance_url'] ?? 'Not configured';
		printf(
			'<div id="vip-agentforce-integration-status-wrapper">
				<h1>%s</h1>
				<div id="vip-agentforce-integration-status">
					<strong>%s:</strong> <pre>%s</pre>
				</div>
			</div>',
			esc_html__( 'VIP Agentforce Integration Status', 'vip-agentforce' ),
			esc_html__( 'Salesforce Instance URL', 'vip-agentforce' ),
			esc_html( $salesforce_instance_url )
		);
		do_action( 'vip_agentforce_track_event', 'admin_status_page_viewed', [] );
		do_action( 'vip_agentforce_track_stat', 'admin_status_page_viewed', [] );
	}
}

Admin_Status::init();
