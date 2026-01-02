<?php
/**
 * Test double for Ingestion that simulates API failures.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_API_Result;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;

/**
 * Test double that simulates API failures.
 */
class Ingestion_With_Failing_Api extends Ingestion {

	/**
	 * Override send_to_api to always fail.
	 *
	 * @param Ingestion_Post_Record $record The record to send.
	 * @return Ingestion_API_Result Simulated failure result.
	 */
	public static function send_to_api( Ingestion_Post_Record $record ): Ingestion_API_Result {
		unset( $record ); // Unused in mock.
		return Ingestion_API_Result::failure( 'Simulated API failure' );
	}
}
