<?php
namespace Automattic\VIP\Salesforce\Agentforce\Admin_Status;

use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Salesforce\Credentials;
use Automattic\VIP\Salesforce\Agentforce\Ingest\DataKit_Deployment;
use Automattic\VIP\Salesforce\Agentforce\Ingest\Data_Sync;

class Admin_Status {

	/** @var bool */
	private static $errors_displayed = false;

	/** @var array<string> */
	private static $errors_added = [];

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_options_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_form_submission' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_actions' ] );
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

	public static function handle_form_submission(): void {
		if ( ! isset( $_POST['vip_agentforce_save_credentials'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'vip_agentforce_save_credentials', 'vip_agentforce_nonce' );

		$instance_url = sanitize_text_field( $_POST['salesforce_instance_url'] ?? '' );
		$access_token = sanitize_text_field( $_POST['salesforce_access_token'] ?? '' );

		Credentials::save_credentials( $instance_url, $access_token );

		add_settings_error(
			'vip_agentforce_messages',
			'vip_agentforce_message',
			__( 'Settings Saved', 'vip-agentforce' ),
			'updated'
		);
	}

	public static function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['vip_agentforce_deploy_datakit'] ) ) {
			check_admin_referer( 'vip_agentforce_actions', 'vip_agentforce_action_nonce' );
			
			// Prevent duplicate error if already added
			if ( ! in_array( 'deploy_datakit', self::$errors_added, true ) ) {
				$result = DataKit_Deployment::deploy_datakit();
				add_settings_error( 'vip_agentforce_messages', 'deploy_datakit', $result['message'], $result['success'] ? 'updated' : 'error' );
				self::$errors_added[] = 'deploy_datakit';
				
				// Store debug info in transient to display in page content
				if ( ! empty( $result['debug'] ) ) {
					set_transient( 'vip_agentforce_debug_' . get_current_user_id(), $result['debug'], 30 );
				}
			}
		}

		if ( isset( $_POST['vip_agentforce_sync_now'] ) ) {
			check_admin_referer( 'vip_agentforce_actions', 'vip_agentforce_action_nonce' );
			Data_Sync::run_ingestion();
			add_settings_error( 'vip_agentforce_messages', 'sync_now', __( 'Sync initiated.', 'vip-agentforce' ), 'updated' );
		}
	}

	public static function status_page_content(): void {
		$credentials  = Credentials::get_credentials();
		$instance_url = $credentials['instance_url'] ?? '';
		$access_token = $credentials['access_token'] ?? '';
		
		// Mask token for display
		$masked_token = $access_token ? substr( $access_token, 0, 10 ) . '...' . substr( $access_token, -5 ) : '';
		
		$is_configured = $instance_url && $access_token;
		$package_check = $is_configured ? DataKit_Deployment::check_package_installation() : [ 'isInstalled' => false ];
		$stream_check  = $is_configured ? DataKit_Deployment::check_data_stream_deployment() : false;

		// Output debug info if available
		$debug_info = get_transient( 'vip_agentforce_debug_' . get_current_user_id() );
		if ( $debug_info ) {
			delete_transient( 'vip_agentforce_debug_' . get_current_user_id() );
			$debug_json = wp_json_encode( $debug_info );
			?>
			<script>
				console.log('VIP Agentforce Debug:', <?php echo $debug_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>);
			</script>
			<?php
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VIP Agentforce Settings', 'vip-agentforce' ); ?></h1>
			<?php
			if ( ! self::$errors_displayed ) {
				settings_errors( 'vip_agentforce_messages' );
				self::$errors_displayed = true;
			}
			?>

			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h2 class="title">
					<span class="dashicons dashicons-info-outline" style="vertical-align: middle; color: #2271b1;"></span>
					<?php esc_html_e( 'How to get an Access Token', 'vip-agentforce' ); ?>
				</h2>
				<p><?php esc_html_e( 'To connect this site to Salesforce, you need a valid Access Token (Session ID) and your Instance URL.', 'vip-agentforce' ); ?></p>
				
				<hr style="margin: 15px 0;">

				<h3><?php esc_html_e( 'Option 1: Salesforce CLI (Recommended for Developers)', 'vip-agentforce' ); ?></h3>
				<p>
					<?php esc_html_e( 'Run the following command in your terminal:', 'vip-agentforce' ); ?>
					<br>
					<code style="display: block; margin-top: 10px; padding: 10px; background: #f0f0f1;">sf org display --target-org your-org-alias</code>
				</p>
				<p><?php esc_html_e( 'Copy the "Access Token" and "Instance Url" values from the output.', 'vip-agentforce' ); ?></p>

				<h3><?php esc_html_e( 'Option 2: Developer Console', 'vip-agentforce' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Log in to your Salesforce Org.', 'vip-agentforce' ); ?></li>
					<li><?php esc_html_e( 'Click the gear icon (Setup) > Developer Console.', 'vip-agentforce' ); ?></li>
					<li><?php esc_html_e( 'Open the "Debug" menu > "Open Execute Anonymous Window".', 'vip-agentforce' ); ?></li>
					<li><?php esc_html_e( 'Run this Apex code:', 'vip-agentforce' ); ?> <code>System.debug(UserInfo.getSessionId());</code></li>
					<li><?php esc_html_e( 'Copy the Session ID from the logs (checking "Debug Only" helps).', 'vip-agentforce' ); ?></li>
				</ol>
				
				<p class="description" style="margin-top: 15px; border-left: 4px solid #ffb900; padding-left: 10px;">
					<strong><?php esc_html_e( 'Note:', 'vip-agentforce' ); ?></strong> 
					<?php esc_html_e( 'Access Tokens expire periodically (usually every few hours). If the integration stops working, please generate a new token and update it here.', 'vip-agentforce' ); ?>
				</p>
			</div>

			<br class="clear">

			<form method="post" action="">
				<?php wp_nonce_field( 'vip_agentforce_save_credentials', 'vip_agentforce_nonce' ); ?>
				
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Salesforce Instance URL', 'vip-agentforce' ); ?></th>
						<td>
							<input type="text" name="salesforce_instance_url" value="<?php echo esc_attr( $instance_url ); ?>" class="regular-text" placeholder="https://my-domain.my.salesforce.com" />
							<p class="description"><?php esc_html_e( 'The base URL of your Salesforce org. Do not include "/lightning/..."', 'vip-agentforce' ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Access Token', 'vip-agentforce' ); ?></th>
						<td>
							<input type="password" name="salesforce_access_token" value="<?php echo esc_attr( $access_token ); ?>" class="regular-text" />
							<?php if ( $masked_token ) : ?>
								<p class="description" style="color: green;">
									<span class="dashicons dashicons-yes"></span> 
									<?php
									/* translators: %s: Last 5 characters of the access token */
									echo esc_html( sprintf( __( 'Token saved (ends with ...%s)', 'vip-agentforce' ), substr( $access_token, -5 ) ) );
									?>
								</p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Paste the full Access Token/Session ID here.', 'vip-agentforce' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Credentials', 'vip-agentforce' ), 'primary', 'vip_agentforce_save_credentials' ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Actions & Status', 'vip-agentforce' ); ?></h2>
			
			<form method="post" action="">
				<?php wp_nonce_field( 'vip_agentforce_actions', 'vip_agentforce_action_nonce' ); ?>
				<table class="widefat fixed" cellspacing="0">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Check', 'vip-agentforce' ); ?></th>
							<th><?php esc_html_e( 'Status', 'vip-agentforce' ); ?></th>
							<th><?php esc_html_e( 'Action', 'vip-agentforce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><?php esc_html_e( 'DataKit Package', 'vip-agentforce' ); ?></td>
							<td>
								<?php 
								if ( ! $is_configured ) {
									echo '-';
								} elseif ( $package_check['isInstalled'] ) {
									echo '<span style="color: green; font-weight: bold;">' . esc_html__( 'Installed', 'vip-agentforce' ) . '</span> <br><small>' . esc_html( $package_check['packageName'] ) . '</small>';
								} else {
									echo '<span style="color: #d63638; font-weight: bold;">' . esc_html__( 'Not Installed', 'vip-agentforce' ) . '</span>';
								}
								?>
							</td>
							<td>-</td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Data Stream', 'vip-agentforce' ); ?></td>
							<td>
								<?php 
								if ( ! $is_configured ) {
									echo '-';
								} elseif ( $stream_check ) {
									echo '<span style="color: green; font-weight: bold;">' . esc_html__( 'Deployed', 'vip-agentforce' ) . '</span>';
								} else {
									echo '<span style="color: #d63638; font-weight: bold;">' . esc_html__( 'Not Deployed', 'vip-agentforce' ) . '</span>';
								}
								?>
							</td>
							<td>
								<?php if ( $is_configured && ! $stream_check ) : ?>
									<button type="submit" name="vip_agentforce_deploy_datakit" class="button"><?php esc_html_e( 'Deploy DataKit', 'vip-agentforce' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Data Ingestion', 'vip-agentforce' ); ?></td>
							<td>-</td>
							<td>
								<?php if ( $is_configured && $stream_check ) : ?>
									<button type="submit" name="vip_agentforce_sync_now" class="button button-primary"><?php esc_html_e( 'Sync Now', 'vip-agentforce' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
			</form>
		</div>
		<?php
		do_action( 'vip_agentforce_track_event', 'admin_status_page_viewed', [] );
		do_action( 'vip_agentforce_track_stat', 'admin_status_page_viewed' );
	}
}

Admin_Status::init();
