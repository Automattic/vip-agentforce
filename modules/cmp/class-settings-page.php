<?php
/**
 * Class for Settings Page using WP Settings API.
 */

namespace Automattic\VIP\Salesforce\Agentforce\Cmp;

use Automattic\VIP\Salesforce\Agentforce\Utils\Traits\Singleton;
use Automattic\VIP\Salesforce\Agentforce\Utils\Traits\WithPluginPaths;

/**
 * Class Settings_Page
 */
class Settings_Page {

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
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'handle_settings_saved' ) );
	}

	/**
	 * Handle settings saved message.
	 */
	public function handle_settings_saved() {
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_agentforce-settings' === $screen->id &&
			isset( $_GET['settings-updated'] ) && sanitize_text_field( $_GET['settings-updated'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( ! get_settings_errors( 'agentforce_messages' ) ) {
				add_settings_error(
					'agentforce_messages',
					'agentforce_message',
					__( 'Settings Saved', 'wp-agentforce-features' ),
					'success'
				);
			}
		}
	}

	/**
	 * Validate Salesforce SDK URL.
	 *
	 * @param string $url The URL to validate.
	 *
	 * @return string|mixed The validated URL or old value if invalid.
	 */
	public function validate_salesforce_sdk_url( $url ) {
		$url       = trim( $url );
		$old_value = get_option( 'agentforce_salesforce_sdk_url' );

		if ( empty( $url ) ) {
			add_settings_error(
				'agentforce_messages',
				'agentforce_salesforce_sdk_url_error',
				__( 'Salesforce SDK URL cannot be empty.', 'wp-agentforce-features' ),
				'error'
			);

			return $old_value;
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			add_settings_error(
				'agentforce_messages',
				'agentforce_salesforce_sdk_url_error',
				__( 'Please enter a valid URL for the Salesforce SDK.', 'wp-agentforce-features' ),
				'error'
			);

			return $old_value;
		}

		return esc_url_raw( $url );
	}

	/**
	 * Validate iubenda Purpose ID.
	 *
	 * @param string $purpose_id The Purpose ID to validate.
	 *
	 * @return string|mixed The validated Purpose ID or old value if invalid.
	 */
	public function validate_iubenda_category( $purpose_id ) {
		$purpose_id = trim( $purpose_id );
		$old_value  = get_option( 'agentforce_iubenda_category' );

		if ( empty( $purpose_id ) ) {
			add_settings_error(
				'agentforce_messages',
				'agentforce_iubenda_category_error',
				__( 'iubenda Purpose ID cannot be empty.', 'wp-agentforce-features' ),
				'error'
			);

			return $old_value;
		}

		$purpose_id = intval( $purpose_id );
		if ( $purpose_id < 1 || $purpose_id > 5 ) {
			add_settings_error(
				'agentforce_messages',
				'agentforce_iubenda_category_error',
				__( 'iubenda Purpose ID must be between 1 and 5.', 'wp-agentforce-features' ),
				'error'
			);

			return $old_value;
		}

		return strval( $purpose_id );
	}

	/**
	 * Sanitize toggle checkbox value.
	 *
	 * @param mixed $val The value to sanitize.
	 *
	 * @return int Sanitized value (1 for checked, 0 for unchecked).
	 */
	public function sanitize_toggle( $val ) {
		return ! empty( $val ) ? 1 : 0;
	}

	/**
	 * Validate alignment value.
	 *
	 * @param string $val The alignment value to validate.
	 *
	 * @return string Validated alignment value or default if invalid.
	 */
	public function validate_alignment( $val ) {
		$allowed = array( 'bottom-right', 'bottom-left' );
		$val     = is_string( $val ) ? strtolower( trim( $val ) ) : 'bottom-right';

		return in_array( $val, $allowed, true ) ? $val : 'bottom-right';
	}

	/**
	 * Sanitize custom CSS.
	 *
	 * @param mixed $css The CSS to sanitize.
	 *
	 * @return string Sanitized CSS.
	 */
	public function sanitize_custom_css( $css ) {
		$css = is_string( $css ) ? $css : '';

		return wp_kses( $css, array() );
	}

	/** Render: Enable log checkbox */
	public function render_oplog_field() {
		$value = (int) get_option( 'agentforce_enable_oplog', 1 );
		echo '<label><input type="checkbox" name="agentforce_enable_oplog" value="1" ' . checked( 1, $value, false ) . '> ' . esc_html__( 'Enable log', 'wp-agentforce-features' ) . '</label>';
	}

	/** Render: Alignment radio buttons */
	public function render_alignment_field() {
		$value   = get_option( 'agentforce_alignment', 'bottom-right' );
		$options = array(
			'bottom-right' => __( 'Bottom right', 'wp-agentforce-features' ),
			'bottom-left'  => __( 'Bottom left', 'wp-agentforce-features' ),
		);
		echo '<fieldset class="agentforce-radios">';
		foreach ( $options as $k => $label ) {
			echo '<label style="display:block;margin:6px 0;">';
			echo '<input type="radio" name="agentforce_alignment" value="' . esc_attr( $k ) . '" ' . checked( $value, $k, false ) . '> ' . esc_html( $label );
			echo '</label>';
		}
		echo '</fieldset>';
	}

	/** Render: Custom CSS textarea */
	public function render_custom_css_field() {
		$value = get_option( 'agentforce_custom_css', '' );
		echo '<textarea name="agentforce_custom_css" rows="3" class="large-text code" placeholder="/* Paste CSS to override agentforce styles */">' . esc_textarea( $value ) . '</textarea>';
	}

	/**
	 * Add settings page to the admin menu.
	 */
	public function add_settings_page() {
		add_menu_page(
			__( 'AgentForce Settings', 'wp-agentforce-features' ),
			__( 'AgentForce', 'wp-agentforce-features' ),
			'manage_options',
			'agentforce-settings',
			array( $this, 'render_settings_page' ),
			$this->get_integration_url() . '/assets/images/agentforce-icon.svg',
		);
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public function register_settings() {
		register_setting(
			'agentforce_settings_group',
			'agentforce_enable_sdk',
			array(
				'sanitize_callback' => array( $this, 'sanitize_toggle' ),
			)
		);
		register_setting(
			'agentforce_settings_group',
			'agentforce_salesforce_sdk_url',
			array(
				'sanitize_callback' => array( $this, 'validate_salesforce_sdk_url' ),
			)
		);
		register_setting( 'agentforce_settings_group', 'agentforce_consent_type' );
		register_setting( 'agentforce_settings_group', 'agentforce_onetrust_group_id' );
		register_setting( 'agentforce_settings_group', 'agentforce_cookiebot_category' );
		register_setting(
			'agentforce_settings_group',
			'agentforce_iubenda_category',
			array(
				'sanitize_callback' => array( $this, 'validate_iubenda_category' ),
			)
		);
		register_setting(
			'agentforce_settings_group',
			'agentforce_enable_oplog',
			array(
				'sanitize_callback' => array(
					$this,
					'sanitize_toggle',
				),
			)
		);
		register_setting(
			'agentforce_settings_group',
			'agentforce_alignment',
			array(
				'sanitize_callback' => array(
					$this,
					'validate_alignment',
				),
			)
		);
		register_setting(
			'agentforce_settings_group',
			'agentforce_custom_css',
			array(
				'sanitize_callback' => array(
					$this,
					'sanitize_custom_css',
				),
			)
		);

		add_settings_section(
			'agentforce_settings_section',
			__( 'General Settings', 'wp-agentforce-features' ),
			'__return_false',
			'agentforce-settings'
		);

		add_settings_field(
			'agentforce_enable_sdk',
			__( 'Enable SDK', 'wp-agentforce-features' ),
			array( $this, 'render_enable_sdk_field' ),
			'agentforce-settings',
			'agentforce_settings_section'
		);

		add_settings_field(
			'agentforce_salesforce_sdk_url',
			__( 'Salesforce SDK URL', 'wp-agentforce-features' ),
			array( $this, 'render_sdk_url_field' ),
			'agentforce-settings',
			'agentforce_settings_section'
		);

		add_settings_field(
			'agentforce_consent_type',
			__( 'Consent Type', 'wp-agentforce-features' ),
			array( $this, 'render_consent_type_field' ),
			'agentforce-settings',
			'agentforce_settings_section'
		);

		add_settings_field(
			'agentforce_onetrust_group_id',
			__( 'OneTrust Group ID', 'wp-agentforce-features' ),
			array( $this, 'render_onetrust_group_id_field' ),
			'agentforce-settings',
			'agentforce_settings_section'
		);

		add_settings_field(
			'agentforce_cookiebot_category',
			__( 'Cookiebot Category', 'wp-agentforce-features' ),
			array( $this, 'render_cookiebot_category_field' ),
			'agentforce-settings',
			'agentforce_settings_section'
		);

		add_settings_field(
			'agentforce_iubenda_category',
			__( 'iubenda Purpose ID', 'wp-agentforce-features' ),
			array( $this, 'render_iubenda_category_field' ),
			'agentforce-settings',
			'agentforce_settings_section'
		);

		add_settings_section(
			'agentforce_bot_ui_section',
			__( 'Agent UI', 'wp-agentforce-features' ),
			'__return_false',
			'agentforce-settings'
		);

		add_settings_field(
			'agentforce_alignment',
			__( 'Alignment', 'wp-agentforce-features' ),
			array( $this, 'render_alignment_field' ),
			'agentforce-settings',
			'agentforce_bot_ui_section'
		);

		add_settings_field(
			'agentforce_custom_css',
			__( 'Custom CSS (optional)', 'wp-agentforce-features' ),
			array( $this, 'render_custom_css_field' ),
			'agentforce-settings',
			'agentforce_bot_ui_section'
		);

		add_settings_section(
			'agentforce_debug_section',
			__( 'Debug', 'wp-agentforce-features' ),
			'__return_false',
			'agentforce-settings'
		);

		add_settings_field(
			'agentforce_enable_oplog',
			__( 'Enable log', 'wp-agentforce-features' ),
			array( $this, 'render_oplog_field' ),
			'agentforce-settings',
			'agentforce_debug_section'
		);
	}

	/**
	 * Render the Enable SDK checkbox.
	 */
	public function render_enable_sdk_field() {
		$value = (int) get_option( 'agentforce_enable_sdk', 1 );
		echo '<label><input type="checkbox" name="agentforce_enable_sdk" value="1" ' . checked( 1, $value, false ) . '> ' . esc_html__( 'Enable Salesforce SDK', 'wp-agentforce-features' ) . '</label>';
	}

	/**
	 * Render the Salesforce SDK URL text field.
	 */
	public function render_sdk_url_field() {
		$value = esc_attr( get_option( 'agentforce_salesforce_sdk_url', '' ) );
		printf(
			'<input type="text" name="agentforce_salesforce_sdk_url" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
	}

	/**
	 * Render the consent type dropdown.
	 */
	public function render_consent_type_field() {
		$value = get_option( 'agentforce_consent_type', 'CookieYes' );
		?>
		<select name="agentforce_consent_type">
			<option
				value="CookieYes" <?php selected( $value, 'CookieYes' ); ?>><?php esc_html_e( 'CookieYes', 'wp-agentforce-features' ); ?>
			</option>
			<option
				value="CookieBot" <?php selected( $value, 'CookieBot' ); ?>><?php esc_html_e( 'CookieBot', 'wp-agentforce-features' ); ?>
			</option>
			<option
				value="OneTrust" <?php selected( $value, 'OneTrust' ); ?>><?php esc_html_e( 'OneTrust', 'wp-agentforce-features' ); ?>
			</option>
			<option
				value="iubenda" <?php selected( $value, 'iubenda' ); ?>><?php esc_html_e( 'iubenda', 'wp-agentforce-features' ); ?>
			</option>
			<option
				value="Custom" <?php selected( $value, 'Custom' ); ?>><?php esc_html_e( 'Custom', 'wp-agentforce-features' ); ?>
			</option>
		</select>
		<?php
	}

	/**
	 * Render the OneTrust Group ID text field.
	 */
	public function render_onetrust_group_id_field() {
		// Use the constant from Assets class.
		$value = get_option( 'agentforce_onetrust_group_id', Assets::DEFAULT_ONETRUST_GROUP_ID );
		printf(
			'<input type="text" name="agentforce_onetrust_group_id" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
	}

	/**
	 * Render the Cookiebot category text field.
	 */
	public function render_cookiebot_category_field() {
		$value = get_option( 'agentforce_cookiebot_category', Assets::DEFAULT_COOKIEBOT_CATEGORY );
		printf(
			'<input type="text" name="agentforce_cookiebot_category" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
		printf(
			'<p class="description">%s<br>%s<br><code>window.Cookiebot.consent</code></p>',
			esc_html__( 'Enter the Cookiebot category (e.g., necessary, preferences, statistics, marketing).', 'wp-agentforce-features' ),
			esc_html__( 'To see all available categories for your site, open browser console and run:', 'wp-agentforce-features' )
		);
	}

	/**
	 * Render the iubenda Purpose ID text field.
	 */
	public function render_iubenda_category_field() {
		$value = get_option( 'agentforce_iubenda_category', Assets::DEFAULT_IUBENDA_PURPOSE_ID );
		printf(
			'<input type="number" name="agentforce_iubenda_category" value="%s" class="small-text" min="1" max="5" />',
			esc_attr( $value )
		);
		printf(
			'<p class="description">%s %s <a href="https://www.iubenda.com/en/help/1205-how-to-configure-your-cookie-solution-advanced-guide#per-category-consent" target="_blank">%s</a><br>%s<br><code>_iub.cs.api.getPreferences().purposes</code></p>',
			esc_html__( 'Enter the iubenda Purpose ID (1-5).', 'wp-agentforce-features' ),
			esc_html__( 'See the', 'wp-agentforce-features' ),
			esc_html__( 'iubenda documentation', 'wp-agentforce-features' ),
			esc_html__( 'To see all available purpose IDs for your site, open browser console and run:', 'wp-agentforce-features' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		$php_version = PHP_VERSION;
		$xdebug      = extension_loaded( 'xdebug' ) ? __( 'Enabled', 'wp-agentforce-features' ) : __( 'Disabled', 'wp-agentforce-features' );
		$site_url    = home_url();
		?>
		<div class="wrap agentforce-wrap">
			<h1><?php esc_html_e( 'AgentForce Settings', 'wp-agentforce-features' ); ?></h1>
			<?php settings_errors( 'agentforce_messages' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'agentforce_settings_group' ); ?>

				<div class="af-grid">
					<div class="af-col">
						<div class="af-card">
							<h2><?php esc_html_e( 'General', 'wp-agentforce-features' ); ?></h2>
							<table class="form-table" role="presentation">
								<tr id="row_enable_sdk">
									<th scope="row"><?php esc_html_e( 'Enable SDK', 'wp-agentforce-features' ); ?></th>
									<td><?php $this->render_enable_sdk_field(); ?></td>
								</tr>
								<tr id="row_sdk">
									<th scope="row"><?php esc_html_e( 'Salesforce SDK URL', 'wp-agentforce-features' ); ?></th>
									<td><?php $this->render_sdk_url_field(); ?><p
											class="description"><?php esc_html_e( 'Must be HTTPS. The SDK will be enqueued on the frontend.', 'wp-agentforce-features' ); ?></p>
									</td>
								</tr>
								<tr id="row_consent">
									<th scope="row"><?php esc_html_e( 'Consent Type', 'wp-agentforce-features' ); ?></th>
									<td>
										<?php $this->render_consent_type_field(); ?>
									</td>
								</tr>
								<tr id="row_onetrust">
									<th scope="row"><?php esc_html_e( 'OneTrust Group ID', 'wp-agentforce-features' ); ?></th>
									<td><?php $this->render_onetrust_group_id_field(); ?></td>
								</tr>
								<tr id="row_cookiebot">
									<th scope="row"><?php esc_html_e( 'Cookiebot Category', 'wp-agentforce-features' ); ?></th>
									<td><?php $this->render_cookiebot_category_field(); ?></td>
								</tr>
								<tr id="row_iubenda">
									<th scope="row"><?php esc_html_e( 'iubenda Purpose ID', 'wp-agentforce-features' ); ?></th>
									<td><?php $this->render_iubenda_category_field(); ?></td>
								</tr>
							</table>
						</div>

						<div class="af-card">
							<h2><?php esc_html_e( 'Agent UI', 'wp-agentforce-features' ); ?></h2>
							<p><?php esc_html_e( 'Alignment and custom styles for the launcher/widget.', 'wp-agentforce-features' ); ?></p>
							<table class="form-table" role="presentation">
								<tr id="row_alignment">
									<th scope="row"><?php esc_html_e( 'Alignment', 'wp-agentforce-features' ); ?></th>
									<td><?php $this->render_alignment_field(); ?></td>
								</tr>
								<tr id="row_custom_css">
									<th scope="row"><?php esc_html_e( 'Custom CSS (optional)', 'wp-agentforce-features' ); ?></th>
									<td><?php $this->render_custom_css_field(); ?></td>
								</tr>
							</table>
						</div>

						<div class="af-card">
							<h2><?php esc_html_e( 'Debug', 'wp-agentforce-features' ); ?></h2>
							<table class="form-table" role="presentation">
								<tr id="row_oplog">
									<th scope="row"><?php esc_html_e( 'Enable log', 'wp-agentforce-features' ); ?></th>
									<td><?php $this->render_oplog_field(); ?></td>
								</tr>
							</table>
						</div>

						<p class="submit">
							<button type="submit"
									class="button button-primary button-hero"><?php esc_html_e( 'Save Changes', 'wp-agentforce-features' ); ?></button>
							<span
								class="af-note"><?php esc_html_e( 'Changes apply on next page load.', 'wp-agentforce-features' ); ?></span>
						</p>
					</div>

					<aside class="af-col af-sidebar">
						<div class="af-card">
							<h2><?php esc_html_e( 'Documentation', 'wp-agentforce-features' ); ?></h2>
							<ul class="af-docs">
								<li><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><a href="#"
																												target="_blank"
																												rel="noopener">Install
										/ Embed</a></li>
								<li><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><a href="#"
																												target="_blank"
																												rel="noopener">Consent
										integration</a></li>
								<li><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><a href="#"
																												target="_blank"
																												rel="noopener">Debugging</a>
								</li>
								<li><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><a href="#"
																												target="_blank"
																												rel="noopener">JS
										API</a></li>
							</ul>
						</div>

						<div class="af-card">
							<h2><?php esc_html_e( 'Status', 'wp-agentforce-features' ); ?></h2>
							<table class="af-status">
								<tr>
									<th><?php esc_html_e( 'PHP version', 'wp-agentforce-features' ); ?></th>
									<td><?php echo esc_html( $php_version ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Xdebug', 'wp-agentforce-features' ); ?></th>
									<td><?php echo esc_html( $xdebug ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Site URL', 'wp-agentforce-features' ); ?></th>
									<td><a href="<?php echo esc_html( $site_url ); ?>"><?php echo esc_html( $site_url ); ?></a></td>
								</tr>
							</table>
						</div>
					</aside>
				</div>

				<script>
					document.addEventListener('DOMContentLoaded', function () {
						var consentType = document.querySelector('[name="agentforce_consent_type"]');
						var oneTrustRow = document.getElementById('row_onetrust');
						var cookiebotRow = document.getElementById('row_cookiebot');
						var iubendaRow = document.getElementById('row_iubenda');

						function toggleFields() {
							oneTrustRow.style.display = (consentType.value === 'OneTrust') ? '' : 'none';
							cookiebotRow.style.display = (consentType.value === 'CookieBot') ? '' : 'none';
							iubendaRow.style.display = (consentType.value === 'iubenda') ? '' : 'none';
						}

						toggleFields();
						consentType.addEventListener('change', toggleFields);
					});
				</script>

			</form>
		</div>
		<?php
	}
}
