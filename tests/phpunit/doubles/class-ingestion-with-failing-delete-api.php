<?php
/**
 * Test double for Ingestion that simulates delete API failures.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_API_Result;

/**
 * Test double that simulates delete API failures.
 */
class Ingestion_With_Failing_Delete_Api extends Ingestion {

	/**
	 * Override delete_from_api to always fail.
	 *
	 * @param \WP_Post $post The post to delete.
	 * @return Ingestion_API_Result Simulated failure result.
	 */
	public static function delete_from_api( \WP_Post $post ): Ingestion_API_Result {
		unset( $post ); // Unused in mock.
		return Ingestion_API_Result::failure( 'Simulated delete API failure' );
	}
}
