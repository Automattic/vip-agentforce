<?php
/**
 * Agentforce class.
 */

namespace Automattic\VIP\Salesforce\Agentforce\Cmp;

use Automattic\VIP\Salesforce\Agentforce\Utils\Traits\Singleton;

/**
 * Class Agentforce
 */
class Agentforce {

	use Singleton;

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
		add_action( 'wp_head', array( $this, 'wp_af_custom_css_render' ) );
	}

	/**
	 * Render custom CSS in the head section.
	 *
	 * @return void
	 */
	public function wp_af_custom_css_render() {
		$custom_css = get_option( 'agentforce_custom_css', '' );
		$alignment  = get_option( 'agentforce_alignment', 'bottom-right' );
		if ( 'bottom-left' === $alignment ) {
			echo '<style>
		          .embedded-messaging > .embeddedMessagingFrame { left: 10px }
		          .embedded-messaging > .embeddedMessagingFrame.isMinimized { right: unset; }
		          .embedded-messaging > .embeddedMessagingFrame.isMaximized { right: unset; }
		          button#embeddedMessagingConversationButton { right: unset; left: 10px; }
                 </style>';
		}

		if ( ! empty( $custom_css ) ) {
			echo '<style id="agentforce-custom-css">' . esc_html( wp_strip_all_tags( $custom_css ) ) . '</style>';
		}
	}
}
