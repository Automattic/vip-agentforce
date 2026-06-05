<?php
/**
 * Ingestion API Client.
 *
 * Handles single-attempt API calls to the Salesforce Data Cloud Ingestion API
 * and maintains a shared rate-limit cache block that coordinates back-off
 * across workers. Retry of transient failures is owned by `Ingestion_Cron`,
 * which keeps queued items in place across cron ticks until they succeed
 * or the retry cap is exhausted.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Ingestion;

use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

/**
 * Client for making API calls to the Salesforce Data Cloud Ingestion API.
 *
 * Responsibilities are deliberately narrow:
 *
 * - Make exactly one HTTP request per call. The caller (cron) decides whether
 *   to retry based on `Ingestion_API_Result::is_retryable()`.
 * - Maintain a shared rate-limit cache block:
 *   - Reactive: a 429 response stores the Retry-After window so other workers
 *     and other queue items defer the same way until it expires.
 *   - Preemptive: a successful 202 with `X-RateLimit-Remaining: 1` parks the
 *     next call until `X-RateLimit-Reset` instead of waiting for an actual 429.
 * - Skip the HTTP call entirely when the cache block is active — there's no
 *   point firing a request we already know SF will reject. The result is
 *   marked retryable so cron picks it up after the block expires.
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
	 * Default rate-limit block duration when SF returns 429 without a
	 * Retry-After header. Short — the block exists primarily to coordinate
	 * other workers; if we guess wrong, the next 429 will reset it.
	 */
	private const DEFAULT_BLOCK_SECONDS = 1;

	/**
	 * Default request timeout in seconds.
	 *
	 * The effective timeout is `vip_agentforce_api_timeout` (filterable);
	 * the default is bumped to 15s under WP-CLI to suit bulk sync runs and
	 * stays at 3s for normal request-response paths to keep latency tight.
	 */
	private const DEFAULT_TIMEOUT_CLI    = 15;
	private const DEFAULT_TIMEOUT_NORMAL = 3;

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
	 * Make a single API request, honoring the shared rate-limit block and
	 * returning a result that the cron can act on (retry or give up).
	 *
	 * @param string $method    HTTP method ('POST' or 'DELETE').
	 * @param string $body      JSON-encoded request body.
	 * @param string $record_id The record ID for the result.
	 * @return Ingestion_API_Result The API result.
	 */
	private function make_request( string $method, string $body, string $record_id ): Ingestion_API_Result {
		$config_error = $this->validate_config();
		if ( null !== $config_error ) {
			// Permanent — don't mark retryable; cron will fire the failure event.
			return Ingestion_API_Result::failure( $config_error, null, $record_id );
		}

		// If a previous 429 (or our own preemptive block) said "back off",
		// defer this call entirely. Cron will pick it up on a later tick once
		// the block expires. Saves us a guaranteed-rejected request to SF.
		$block_remaining = $this->get_rate_limit_block_remaining();
		if ( $block_remaining > 0 ) {
			return Ingestion_API_Result::deferred(
				sprintf( 'Rate-limit block active for %.1fs; deferring request', $block_remaining ),
				$record_id
			);
		}

		$response = $this->execute_request( $method, $body );

		if ( is_wp_error( $response ) ) {
			Logger::warning(
				'ingestion-api',
				'HTTP error contacting Salesforce',
				[
					'record_id' => $record_id,
					'error'     => $response->get_error_message(),
				]
			);
			return Ingestion_API_Result::failure( $response->get_error_message(), $response, $record_id );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		// Success.
		if ( 202 === $status_code ) {
			$this->process_rate_limit_headers( $response );
			return Ingestion_API_Result::success( $record_id, $response );
		}

		// Rate limited: store the block for other workers, return failure.
		// The cron caller will see is_retryable() === true and keep the
		// queue item in place for the next tick.
		if ( 429 === $status_code ) {
			$this->handle_rate_limit_response( $response );

			Logger::warning(
				'ingestion-api',
				'Rate limited by Salesforce',
				[
					'record_id'     => $record_id,
					'status_code'   => $status_code,
					'response_body' => wp_remote_retrieve_body( $response ),
				]
			);

			return Ingestion_API_Result::failure(
				'Rate limited by Salesforce',
				$response,
				$record_id
			);
		}

		// Server-side / transient errors. 503 occasionally includes
		// Retry-After; honor it the same way we honor 429 so the next
		// cron tick observes the block.
		if ( in_array( $status_code, [ 408, 500, 502, 503, 504 ], true ) ) {
			$retry_after = $this->parse_retry_after_header( $response );
			if ( $retry_after > 0 ) {
				$this->set_rate_limit_block( $retry_after );
			}

			Logger::warning(
				'ingestion-api',
				'Transient server error from Salesforce',
				[
					'record_id'     => $record_id,
					'status_code'   => $status_code,
					'response_body' => wp_remote_retrieve_body( $response ),
				]
			);

			return Ingestion_API_Result::failure(
				'Server error (' . $status_code . ')',
				$response,
				$record_id
			);
		}

		// Permanent error (4xx other than 408/429). Cron will see
		// is_retryable() === false and surface a failure event.
		Logger::error(
			'ingestion-api',
			'Permanent error from Salesforce',
			[
				'record_id'     => $record_id,
				'status_code'   => $status_code,
				'response_body' => wp_remote_retrieve_body( $response ),
			]
		);

		return Ingestion_API_Result::failure(
			'Unexpected response code: ' . $status_code,
			$response,
			$record_id
		);
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
				'timeout' => $this->get_request_timeout(),
			]
		);
	}

	/**
	 * Get the API request timeout in seconds.
	 *
	 * Uses a longer default in WP-CLI context (bulk sync runs) and a tight
	 * default everywhere else. Filterable via `vip_agentforce_api_timeout`
	 * so deployments can override either path.
	 *
	 * @return int Timeout in seconds.
	 */
	protected function get_request_timeout(): int {
		$default = ( defined( 'WP_CLI' ) && WP_CLI )
			? self::DEFAULT_TIMEOUT_CLI
			: self::DEFAULT_TIMEOUT_NORMAL;

		return (int) apply_filters( 'vip_agentforce_api_timeout', $default );
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

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- Rate-limit blocks are intentionally short-lived: the value is the Retry-After window we're waiting out, typically <30s. Forcing >=300s would defeat the mechanism.
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
			// Default to a short block if Retry-After was missing.
			$this->set_rate_limit_block( self::DEFAULT_BLOCK_SECONDS );
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

		if ( ! is_array( $headers ) ) {
			return null;
		}

		$lower_name = strtolower( $name );
		$value      = $headers[ $name ] ?? $headers[ $lower_name ] ?? null;

		return null !== $value ? (string) $value : null;
	}
}
