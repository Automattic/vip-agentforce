<?php
/**
 * Ingestion API Client.
 *
 * Handles API calls to Salesforce Data Cloud Ingestion API with rate limiting.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Ingestion;

use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

/**
 * Client for making API calls to the Salesforce Data Cloud Ingestion API.
 *
 * Implements reactive rate limiting based on response headers with exponential
 * backoff and jitter for retry handling.
 */
class Ingestion_API_Client {
	/**
	 * Cache key for rate limit block timestamp.
	 */
	private const CACHE_KEY_RATE_LIMIT_BLOCK = 'vip_agentforce_rate_limit_blocked_until';

	/**
	 * Cache group for rate limiting.
	 */
	private const CACHE_GROUP = 'vip_agentforce';

	/**
	 * Maximum number of retry attempts.
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Base delay for exponential backoff in seconds.
	 */
	private const BASE_DELAY_SECONDS = 1;

	/**
	 * Maximum delay cap for exponential backoff in seconds.
	 */
	private const MAX_DELAY_SECONDS = 30;

	/**
	 * Request timeout in seconds.
	 */
	private const REQUEST_TIMEOUT = 3;

	/**
	 * Send a record to the Salesforce Data Cloud Ingestion API.
	 *
	 * @param Ingestion_Post_Record $record The record to send.
	 * @return Ingestion_API_Result The API result.
	 */
	public function send( Ingestion_Post_Record $record ): Ingestion_API_Result {
		$record_id = $record->to_array()['site_id_blog_id_post_id'];

		return $this->make_request(
			'POST',
			wp_json_encode( [ 'data' => [ $record->to_array() ] ] ),
			$record_id
		);
	}

	/**
	 * Delete a record from the Salesforce Data Cloud Ingestion API.
	 *
	 * @param string $record_id The record ID to delete.
	 * @return Ingestion_API_Result The API result.
	 */
	public function delete( string $record_id ): Ingestion_API_Result {
		return $this->make_request(
			'DELETE',
			wp_json_encode( [ 'ids' => [ $record_id ] ] ),
			$record_id
		);
	}

	/**
	 * Make an API request with rate limiting and retry logic.
	 *
	 * @param string $method    HTTP method ('POST' or 'DELETE').
	 * @param string $body      JSON-encoded request body.
	 * @param string $record_id The record ID for the result.
	 * @return Ingestion_API_Result The API result.
	 */
	private function make_request( string $method, string $body, string $record_id ): Ingestion_API_Result {
		$config_error = $this->validate_config();
		if ( null !== $config_error ) {
			return Ingestion_API_Result::failure( $config_error, null, $record_id );
		}

		$attempt = 0;

		while ( $attempt <= self::MAX_RETRIES ) {
			// Calculate total wait time before making request.
			// Order: block_remaining (Retry-After) + jitter + exponential backoff.
			$block_remaining = $this->get_rate_limit_block_remaining();
			$backoff_delay   = $attempt > 0 ? $this->calculate_backoff_delay( $attempt ) : 0;
			$total_wait      = $block_remaining + $backoff_delay;

			if ( $total_wait > 0 ) {
				$this->sleep_with_jitter( $total_wait );
			}

			$response = $this->execute_request( $method, $body );

			if ( is_wp_error( $response ) ) {
				return Ingestion_API_Result::failure( $response->get_error_message(), $response, $record_id );
			}

			$status_code = wp_remote_retrieve_response_code( $response );

			// Success.
			if ( 202 === $status_code ) {
				$this->process_rate_limit_headers( $response );
				return Ingestion_API_Result::success( $record_id, $response );
			}

			// Rate limited - set block and retry.
			if ( 429 === $status_code ) {
				$this->handle_rate_limit_response( $response );

				++$attempt;
				if ( $attempt <= self::MAX_RETRIES ) {
					continue;
				}

				return Ingestion_API_Result::failure(
					'Rate limited after ' . self::MAX_RETRIES . ' retries',
					$response,
					$record_id
				);
			}

			// Other error - don't retry.
			return Ingestion_API_Result::failure(
				'Unexpected response code: ' . $status_code,
				$response,
				$record_id
			);
		}

		// Should never reach here, but just in case.
		return Ingestion_API_Result::failure( 'Max retries exceeded', null, $record_id );
	}

	/**
	 * Execute the actual HTTP request.
	 *
	 * @param string $method HTTP method.
	 * @param string $body   Request body.
	 * @return array<string, mixed>|\WP_Error The response or error.
	 */
	private function execute_request( string $method, string $body ) {
		$config = Configs::get_config();
		$token  = $config['ingestion_api_token'] ?? '';
		$url    = $this->build_api_url();

		return wp_remote_request(
			$url,
			[
				'method'  => $method,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				],
				'body'    => $body,
				'timeout' => self::REQUEST_TIMEOUT,
			]
		);
	}

	/**
	 * Validate that required API configuration fields are present.
	 *
	 * @return string|null Error message if validation fails, null if valid.
	 */
	private function validate_config(): ?string {
		$config = Configs::get_config();

		$fields_to_check = [
			'ingestion_api_instance_url',
			'ingestion_api_token',
			'ingestion_api_source_name',
			'ingestion_api_object_name',
		];

		$empty_fields = [];
		foreach ( $fields_to_check as $field ) {
			if ( empty( $config[ $field ] ) ) {
				$empty_fields[] = $field;
			}
		}

		if ( ! empty( $empty_fields ) ) {
			return 'Missing required API configuration: ' . implode( ', ', $empty_fields );
		}

		return null;
	}

	/**
	 * Build the Ingestion API URL.
	 *
	 * @return string The full API URL.
	 */
	private function build_api_url(): string {
		$config = Configs::get_config();

		$base_url    = $config['ingestion_api_instance_url'] ?? '';
		$source_name = $config['ingestion_api_source_name'] ?? '';
		$object_name = $config['ingestion_api_object_name'] ?? '';

		return rtrim( $base_url, '/' ) . '/api/v1/ingest/sources/' . rawurlencode( $source_name ) . '/' . rawurlencode( $object_name );
	}

	/**
	 * Get the remaining seconds until the rate limit block expires.
	 *
	 * @return float Seconds remaining, or 0 if not blocked.
	 */
	private function get_rate_limit_block_remaining(): float {
		$blocked_until = wp_cache_get( self::CACHE_KEY_RATE_LIMIT_BLOCK, self::CACHE_GROUP );

		if ( false === $blocked_until ) {
			return 0;
		}

		$remaining = (float) $blocked_until - microtime( true );

		return max( 0, $remaining );
	}

	/**
	 * Set the rate limit block in cache.
	 *
	 * @param float $duration_seconds How long to block in seconds.
	 */
	private function set_rate_limit_block( float $duration_seconds ): void {
		$blocked_until = microtime( true ) + $duration_seconds;
		$cache_ttl     = (int) ceil( $duration_seconds ) + 1; // Add 1 second buffer.

		wp_cache_set( self::CACHE_KEY_RATE_LIMIT_BLOCK, $blocked_until, self::CACHE_GROUP, $cache_ttl );
	}

	/**
	 * Handle a 429 rate limit response.
	 *
	 * Parses Retry-After header and sets the rate limit block.
	 *
	 * @param array<string, mixed> $response The HTTP response.
	 */
	private function handle_rate_limit_response( array $response ): void {
		$retry_after = $this->parse_retry_after_header( $response );

		if ( $retry_after > 0 ) {
			$this->set_rate_limit_block( $retry_after );
		} else {
			// Default to base delay if no Retry-After header.
			$this->set_rate_limit_block( self::BASE_DELAY_SECONDS );
		}
	}

	/**
	 * Process rate limit headers from a successful response.
	 *
	 * Sets preemptive block if remaining requests are low.
	 *
	 * @param array<string, mixed> $response The HTTP response.
	 */
	private function process_rate_limit_headers( array $response ): void {
		$headers   = wp_remote_retrieve_headers( $response );
		$remaining = $this->get_header_value( $headers, 'x-ratelimit-remaining' );
		$reset     = $this->get_header_value( $headers, 'x-ratelimit-reset' );

		// If we're running low on remaining requests, set a small block.
		if ( null !== $remaining && null !== $reset && (int) $remaining <= 1 ) {
			$reset_time = (int) $reset;
			$now        = time();

			if ( $reset_time > $now ) {
				$this->set_rate_limit_block( (float) ( $reset_time - $now ) );
			}
		}
	}

	/**
	 * Parse the Retry-After header value.
	 *
	 * Supports both seconds and HTTP-date formats.
	 *
	 * @param array<string, mixed> $response The HTTP response.
	 * @return float The retry delay in seconds, or 0 if not present.
	 */
	private function parse_retry_after_header( array $response ): float {
		$headers     = wp_remote_retrieve_headers( $response );
		$retry_after = $this->get_header_value( $headers, 'retry-after' );

		if ( null === $retry_after ) {
			return 0;
		}

		// Check if it's a number (seconds).
		if ( is_numeric( $retry_after ) ) {
			return (float) $retry_after;
		}

		// Try to parse as HTTP-date.
		$timestamp = strtotime( $retry_after );
		if ( false !== $timestamp ) {
			return max( 0, (float) ( $timestamp - time() ) );
		}

		return 0;
	}

	/**
	 * Get a header value case-insensitively.
	 *
	 * @param \WpOrg\Requests\Utility\CaseInsensitiveDictionary|array<string, string> $headers The headers.
	 * @param string                                                                  $name    The header name (lowercase).
	 * @return string|null The header value or null if not found.
	 */
	private function get_header_value( $headers, string $name ): ?string {
		if ( $headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary ) {
			$value = $headers[ $name ] ?? null;
			return null !== $value ? (string) $value : null;
		}

		if ( is_array( $headers ) ) {
			// Try exact match first.
			if ( isset( $headers[ $name ] ) ) {
				return (string) $headers[ $name ];
			}

			// Try case-insensitive match.
			foreach ( $headers as $key => $value ) {
				if ( strtolower( $key ) === $name ) {
					return (string) $value;
				}
			}
		}

		return null;
	}

	/**
	 * Calculate exponential backoff delay.
	 *
	 * @param int $attempt The current attempt number (1-based).
	 * @return float The delay in seconds.
	 */
	private function calculate_backoff_delay( int $attempt ): float {
		// Exponential backoff: base * 2^(attempt-1).
		$delay = self::BASE_DELAY_SECONDS * pow( 2, $attempt - 1 );

		return min( $delay, self::MAX_DELAY_SECONDS );
	}

	/**
	 * Sleep for the specified duration plus random jitter.
	 *
	 * @param float $base_seconds The base sleep duration in seconds.
	 */
	private function sleep_with_jitter( float $base_seconds ): void {
		// Add 0-50% jitter.
		$jitter        = $base_seconds * ( wp_rand( 0, 500 ) / 1000 );
		$total_seconds = $base_seconds + $jitter;

		// Convert to microseconds for usleep.
		$microseconds = (int) ( $total_seconds * 1000000 );

		if ( $microseconds > 0 ) {
			usleep( $microseconds );
		}
	}
}
