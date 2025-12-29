<?php
/**
 * Test double for Ingestion that simulates API failures.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;

/**
 * Test double that simulates API failures.
 */
class Ingestion_With_Failing_Api extends Ingestion {

	/**
	 * Override send_to_api to always fail.
	 *
	 * @param Ingestion_Post_Record $record The record to send.
	 * @return array<string, mixed> Simulated failure response.
	 */
	public static function send_to_api( Ingestion_Post_Record $record ): array {
		unset( $record ); // Unused in mock.
		return [
			'success'       => false,
			'error_message' => 'Simulated API failure',
			'timestamp'     => gmdate( 'c' ),
		];
	}
}
