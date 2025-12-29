<?php
/**
 * Test double for Ingestion that simulates delete API failures.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;

/**
 * Test double that simulates delete API failures.
 */
class Ingestion_With_Failing_Delete_Api extends Ingestion {

	/**
	 * Override delete_from_api to always fail.
	 *
	 * @param \WP_Post $post The post to delete.
	 * @return array<string, mixed> Simulated failure response.
	 */
	public static function delete_from_api( \WP_Post $post ): array {
		unset( $post ); // Unused in mock.
		return [
			'success'       => false,
			'error_message' => 'Simulated delete API failure',
			'timestamp'     => gmdate( 'c' ),
		];
	}
}
