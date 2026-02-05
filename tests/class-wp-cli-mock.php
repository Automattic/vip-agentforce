<?php
/**
 * Mock WP_CLI classes for testing when WP-CLI is not available.
 *
 * @package vip-agentforce
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_CLI_Command {}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Mock WP_CLI class for testing.
	 */
	class WP_CLI {
		/**
		 * Register a command.
		 *
		 * @param string          $name     Command name.
		 * @param callable|string $callback Command callback.
		 * @param array           $args     Command arguments.
		 */
		public static function add_command( $name, $callback, $args = array() ) {}

		/**
		 * Log a message.
		 *
		 * @param string $message Message to log.
		 */
		public static function log( $message ) {}

		/**
		 * Log a success message.
		 *
		 * @param string $message Message to log.
		 */
		public static function success( $message ) {}

		/**
		 * Log a warning message.
		 *
		 * @param string $message Message to log.
		 */
		public static function warning( $message ) {}

		/**
		 * Log an error message.
		 *
		 * @param string $message    Message to log.
		 * @param bool   $should_exit Whether to exit after logging.
		 */
		public static function error( $message, $should_exit = true ) {
			// Don't exit in tests - just log the error.
		}

		/**
		 * Log a line.
		 *
		 * @param string $message Message to log.
		 */
		public static function line( $message = '' ) {}
	}
}
