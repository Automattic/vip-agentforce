<?php
/**
 * Test double for Ingestion that simulates API success.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;

/**
 * Test double that simulates API success.
 */
class Ingestion_With_Succeeding_Api extends Ingestion {

	/**
	 * Override send_to_api to always succeed.
	 *
	 * @param Ingestion_Post_Record $record The record to send.
	 * @return array<string, mixed> Simulated success response.
	 */
	public static function send_to_api( Ingestion_Post_Record $record ): array {
		return [
			'success'   => true,
			'record_id' => $record->to_array()['site_id_blog_id_post_id'],
			'timestamp' => gmdate( 'c' ),
		];
	}
}
