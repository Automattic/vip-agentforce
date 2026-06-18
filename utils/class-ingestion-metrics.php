<?php
/**
 * Ingestion Prometheus metrics helpers.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Utils;

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Queue;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Sync_Progress;
use Prometheus\RegistryInterface;

class Ingestion_Metrics {
	private const NAMESPACE            = 'vip_agentforce';
	private const CACHE_GROUP          = 'vip_agentforce';
	private const COUNTER_SAMPLES_KEY  = 'ingestion_metric_counter_samples';
	private const COUNTER_REPLAY_LOCK  = 'vip_agentforce_ingestion_metric_counter_replay_lock';
	private const COUNTER_LOCK_TTL     = 10;
	private const COUNTER_API_ERRORS   = 'api_errors';
	private const COUNTER_POSTS        = 'posts';
	private const COUNTER_API_REQUESTS = 'api_requests';

	/** @var \Prometheus\Gauge|null */
	private static $queue_pending_gauge;

	/** @var \Prometheus\Gauge|null */
	private static $bulk_status_gauge;

	/** @var \Prometheus\Gauge|null */
	private static $bulk_posts_gauge;

	/** @var \Prometheus\Gauge|null */
	private static $bulk_updated_age_gauge;

	/** @var \Prometheus\Counter|null */
	private static $api_errors_counter;

	/** @var \Prometheus\Counter|null */
	private static $posts_counter;

	/** @var \Prometheus\Counter|null */
	private static $api_requests_counter;

	private static bool $counter_storage_is_persistent = false;

	public static function initialize( RegistryInterface $registry ): void {
		self::$counter_storage_is_persistent = ! self::registry_uses_non_persistent_storage( $registry );

		self::$queue_pending_gauge = $registry->getOrRegisterGauge(
			self::NAMESPACE,
			'ingestion_queue_pending',
			'Current pending ingestion queue items by type.',
			[ 'type' ]
		);

		self::$bulk_status_gauge = $registry->getOrRegisterGauge(
			self::NAMESPACE,
			'ingestion_bulk_sync_status',
			'Current bulk sync status as one-hot gauges.',
			[ 'status' ]
		);

		self::$bulk_posts_gauge = $registry->getOrRegisterGauge(
			self::NAMESPACE,
			'ingestion_bulk_sync_posts',
			'Current bulk sync post counts by result.',
			[ 'result' ]
		);

		self::$bulk_updated_age_gauge = $registry->getOrRegisterGauge(
			self::NAMESPACE,
			'ingestion_bulk_sync_updated_age_seconds',
			'Seconds since the current bulk sync progress was last updated.',
			[]
		);

		self::$api_errors_counter = $registry->getOrRegisterCounter(
			self::NAMESPACE,
			'ingestion_api_errors_total',
			'Total ingestion API errors by class.',
			[ 'class' ]
		);

		self::$posts_counter = $registry->getOrRegisterCounter(
			self::NAMESPACE,
			'ingestion_posts_total',
			'Total terminal ingestion post outcomes by result and mode.',
			[ 'result', 'mode' ]
		);

		self::$api_requests_counter = $registry->getOrRegisterCounter(
			self::NAMESPACE,
			'ingestion_api_requests_total',
			'Total ingestion API requests by method, status code, and outcome.',
			[ 'method', 'status_code', 'outcome' ]
		);
	}

	public static function collect_gauges(): void {
		if ( null === self::$queue_pending_gauge ) {
			return;
		}

		$queue_counts = Ingestion_Queue::get_queue_counts();
		self::$queue_pending_gauge->set( $queue_counts['sync'], [ 'sync' ] );
		self::$queue_pending_gauge->set( $queue_counts['delete'], [ 'delete' ] );

		$progress = Ingestion_Sync_Progress::get();
		$status   = is_array( $progress ) ? (string) ( $progress['status'] ?? 'idle' ) : 'idle';

		foreach ( [ 'idle', 'running', 'completed', 'failed' ] as $known_status ) {
			self::$bulk_status_gauge->set( $known_status === $status ? 1 : 0, [ $known_status ] );
		}

		$counts = [
			'total'     => 0,
			'processed' => 0,
			'synced'    => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'deleted'   => 0,
		];

		if ( is_array( $progress ) ) {
			$current = Ingestion_Sync_Progress::get_status_response( $progress );
			foreach ( $counts as $key => $value ) {
				$counts[ $key ] = (int) ( $current[ $key ] ?? $value );
			}
		}

		foreach ( $counts as $result => $count ) {
			self::$bulk_posts_gauge->set( $count, [ $result ] );
		}

		$updated_at = is_array( $progress ) ? (int) ( $progress['updated_at'] ?? 0 ) : 0;
		self::$bulk_updated_age_gauge->set( $updated_at > 0 ? max( 0, time() - $updated_at ) : 0 );
	}

	public static function collect_counters(): void {
		if ( ! self::claim_counter_samples_lock( false ) ) {
			return;
		}

		try {
			$samples = self::get_pending_counter_samples();
			if ( [] === $samples ) {
				return;
			}

			$remaining_samples = [];
			foreach ( self::get_counter_metrics() as $counter_type => $counter_metric ) {
				$counter_samples = $samples[ $counter_type ] ?? [];
				if ( ! is_array( $counter_samples ) ) {
					continue;
				}

				if ( null === $counter_metric ) {
					$remaining_samples[ $counter_type ] = self::filter_counter_samples( $counter_type, $counter_samples );
					continue;
				}

				foreach ( self::filter_counter_samples( $counter_type, $counter_samples ) as $sample ) {
					$counter_metric->incBy( $sample['count'], $sample['labels'] );
				}
			}

			$remaining_samples = array_filter( $remaining_samples );
			if ( [] === $remaining_samples ) {
				wp_cache_delete( self::COUNTER_SAMPLES_KEY, self::CACHE_GROUP );
				return;
			}

			self::replace_pending_counter_samples( $remaining_samples );
		} finally {
			wp_cache_delete( self::COUNTER_REPLAY_LOCK, self::CACHE_GROUP );
		}
	}

	public static function record_api_error( string $error_class ): void {
		$labels = [ self::normalize_api_error_class( $error_class ) ];
		if ( ! self::record_counter( self::COUNTER_API_ERRORS, $labels ) ) {
			return;
		}

		self::$api_errors_counter->inc( $labels );
	}

	public static function record_post_result( string $result, string $mode ): void {
		$normalized_result = self::normalize_post_result( $result );
		$normalized_mode   = self::normalize_post_mode( $mode );

		self::record_post_result_stats( $normalized_result );

		$labels = [
			$normalized_result,
			$normalized_mode,
		];
		if ( ! self::record_counter( self::COUNTER_POSTS, $labels ) ) {
			return;
		}

		self::$posts_counter->inc( $labels );
	}

	private static function record_post_result_stats( string $result ): void {
		if ( ! in_array( $result, [ 'ingested', 'failed' ], true ) ) {
			return;
		}

		do_action( 'vip_agentforce_track_stat', self::get_site_stats_bucket(), 'posts_' . $result );
	}

	public static function record_api_request( string $method, string $status_code, string $outcome ): void {
		$labels = [
			self::normalize_method( $method ),
			self::normalize_status_code( $status_code ),
			self::normalize_request_outcome( $outcome ),
		];
		if ( ! self::record_counter( self::COUNTER_API_REQUESTS, $labels ) ) {
			return;
		}

		self::$api_requests_counter->inc( $labels );
	}

	/**
	 * @param array<int, string> $labels
	 */
	private static function record_counter( string $counter_type, array $labels ): bool {
		if ( null === self::get_counter_metric( $counter_type ) || ! self::$counter_storage_is_persistent ) {
			self::store_counter_sample( $counter_type, $labels );
			return false;
		}

		return true;
	}

	/**
	 * @param array<int, string> $labels
	 */
	private static function store_counter_sample( string $counter_type, array $labels ): void {
		if ( ! array_key_exists( $counter_type, self::get_counter_metrics() ) ) {
			return;
		}

		if ( ! self::claim_counter_samples_lock( true ) ) {
			return;
		}

		try {
			$samples = self::get_pending_counter_samples();
			$key     = (string) wp_json_encode( $labels );

			$counter_samples = [];
			if ( isset( $samples[ $counter_type ] ) && is_array( $samples[ $counter_type ] ) ) {
				$counter_samples = $samples[ $counter_type ];
			}

			$existing_count = 0;
			if ( isset( $counter_samples[ $key ] ) && is_array( $counter_samples[ $key ] ) && isset( $counter_samples[ $key ]['count'] ) && is_numeric( $counter_samples[ $key ]['count'] ) ) {
				$existing_count = (float) $counter_samples[ $key ]['count'];
			}

			$counter_samples[ $key ]  = [
				'labels' => $labels,
				'count'  => $existing_count + 1,
			];
			$samples[ $counter_type ] = $counter_samples;

			wp_cache_set( self::COUNTER_SAMPLES_KEY, $samples, self::CACHE_GROUP );
		} finally {
			wp_cache_delete( self::COUNTER_REPLAY_LOCK, self::CACHE_GROUP );
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_pending_counter_samples(): array {
		$found   = false;
		$samples = wp_cache_get( self::COUNTER_SAMPLES_KEY, self::CACHE_GROUP, false, $found );

		return $found && is_array( $samples ) ? $samples : [];
	}

	/**
	 * @param array<string, array<int, array{labels: array<int, string>, count: int|float}>> $samples
	 */
	private static function replace_pending_counter_samples( array $samples ): void {
		$pending_samples = [];
		foreach ( $samples as $counter_type => $counter_samples ) {
			foreach ( $counter_samples as $sample ) {
				$key                                      = (string) wp_json_encode( $sample['labels'] );
				$pending_samples[ $counter_type ][ $key ] = [
					'labels' => $sample['labels'],
					'count'  => $sample['count'],
				];
			}
		}

		wp_cache_set( self::COUNTER_SAMPLES_KEY, $pending_samples, self::CACHE_GROUP );
	}

	/**
	 * @param array<string, mixed> $samples
	 * @return array<int, array{labels: array<int, string>, count: int|float}>
	 */
	private static function filter_counter_samples( string $counter_type, array $samples ): array {
		$filtered = [];
		foreach ( $samples as $sample ) {
			if ( ! is_array( $sample ) || ! isset( $sample['labels'], $sample['count'] ) || ! is_array( $sample['labels'] ) || ! is_numeric( $sample['count'] ) ) {
				continue;
			}

			$labels = array_values( array_filter( $sample['labels'], 'is_string' ) );
			$count  = (float) $sample['count'];
			if ( count( $labels ) !== count( $sample['labels'] ) || $count <= 0 ) {
				continue;
			}

			if ( ! self::is_known_counter_label_tuple( $counter_type, $labels ) ) {
				continue;
			}

			$filtered_count = floor( $count ) === $count ? (int) $count : $count;
			$filtered[]     = [
				'labels' => $labels,
				'count'  => $filtered_count,
			];
		}

		return $filtered;
	}

	/**
	 * @return array<string, \Prometheus\Counter|null>
	 */
	private static function get_counter_metrics(): array {
		return [
			self::COUNTER_API_ERRORS   => self::$api_errors_counter,
			self::COUNTER_POSTS        => self::$posts_counter,
			self::COUNTER_API_REQUESTS => self::$api_requests_counter,
		];
	}

	private static function get_counter_metric( string $counter_type ): ?object {
		return self::get_counter_metrics()[ $counter_type ] ?? null;
	}

	/**
	 * @param array<int, string> $labels
	 */
	private static function is_known_counter_label_tuple( string $counter_type, array $labels ): bool {
		return match ( $counter_type ) {
			self::COUNTER_API_ERRORS => 1 === count( $labels ) && in_array( $labels[0], [ 'config', 'auth', 'rate_limit', 'server', 'network', 'client', 'unexpected' ], true ),
			self::COUNTER_POSTS => 2 === count( $labels ) && in_array( $labels[0], [ 'ingested', 'deleted', 'skipped', 'failed' ], true ) && in_array( $labels[1], [ 'queue', 'bulk', 'sync' ], true ),
			self::COUNTER_API_REQUESTS => 3 === count( $labels ) && in_array( $labels[0], [ 'POST', 'DELETE' ], true ) && self::is_known_status_code_label( $labels[1] ) && in_array( $labels[2], [ 'success', 'rate_limit', 'server_error', 'auth_error', 'client_error', 'network_error', 'unexpected' ], true ),
			default => false,
		};
	}

	private static function is_known_status_code_label( string $status_code ): bool {
		return 'none' === $status_code || 1 === preg_match( '/^\d{3}$/', $status_code );
	}

	private static function claim_counter_samples_lock( bool $wait ): bool {
		$deadline = microtime( true ) + self::COUNTER_LOCK_TTL;
		do {
			$lock_expires_at = time() + self::COUNTER_LOCK_TTL;
			if ( wp_cache_add( self::COUNTER_REPLAY_LOCK, $lock_expires_at, self::CACHE_GROUP ) ) {
				return true;
			}

			$current_lock_expires_at = (int) wp_cache_get( self::COUNTER_REPLAY_LOCK, self::CACHE_GROUP );
			if ( $current_lock_expires_at <= time() ) {
				wp_cache_delete( self::COUNTER_REPLAY_LOCK, self::CACHE_GROUP );
				continue;
			}

			if ( ! $wait ) {
				return false;
			}

			usleep( 100000 );
		} while ( microtime( true ) < $deadline );

		return false;
	}

	private static function registry_uses_non_persistent_storage( RegistryInterface $registry ): bool {
		return self::object_contains_non_persistent_storage( $registry, [] );
	}

	/**
	 * @param array<int, true> $seen
	 */
	private static function object_contains_non_persistent_storage( object $candidate, array $seen ): bool {
		$object_id = spl_object_id( $candidate );
		if ( isset( $seen[ $object_id ] ) ) {
			return false;
		}

		$seen[ $object_id ] = true;
		if ( self::is_non_persistent_storage_object( $candidate ) ) {
			return true;
		}

		foreach ( ( new \ReflectionObject( $candidate ) )->getProperties() as $property ) {
			$property->setAccessible( true );
			if ( method_exists( $property, 'isInitialized' ) && ! $property->isInitialized( $candidate ) ) {
				continue;
			}

			$value = $property->getValue( $candidate );
			if ( is_object( $value ) && self::object_contains_non_persistent_storage( $value, $seen ) ) {
				return true;
			}
		}

		return false;
	}

	private static function is_non_persistent_storage_object( object $storage ): bool {
		$storage_class = strtolower( get_class( $storage ) );

		return str_contains( $storage_class, 'inmemory' ) || str_contains( $storage_class, 'in_memory' );
	}

	private static function normalize_api_error_class( string $error_class ): string {
		return in_array( $error_class, [ 'config', 'auth', 'rate_limit', 'server', 'network', 'client', 'unexpected' ], true )
			? $error_class
			: 'unexpected';
	}

	private static function normalize_post_result( string $result ): string {
		return in_array( $result, [ 'ingested', 'deleted', 'skipped', 'failed' ], true )
			? $result
			: 'failed';
	}

	private static function normalize_post_mode( string $mode ): string {
		return in_array( $mode, [ 'queue', 'bulk', 'sync' ], true )
			? $mode
			: 'sync';
	}

	private static function get_site_stats_bucket(): string {
		return 'env_' . self::get_app_id() . '_' . get_current_blog_id();
	}

	private static function get_app_id(): string {
		$app_id = defined( 'VIP_GO_APP_ID' ) ? (string) constant( 'VIP_GO_APP_ID' ) : '0';
		$app_id = strtolower( $app_id );
		$app_id = (string) preg_replace( '/[^a-z0-9_]+/', '_', $app_id );
		$app_id = trim( $app_id, '_' );

		return '' !== $app_id ? $app_id : 'unknown';
	}

	private static function normalize_method( string $method ): string {
		return in_array( $method, [ 'POST', 'DELETE' ], true )
			? $method
			: 'POST';
	}

	private static function normalize_status_code( string $status_code ): string {
		return self::is_known_status_code_label( $status_code )
			? $status_code
			: 'none';
	}

	private static function normalize_request_outcome( string $outcome ): string {
		return in_array( $outcome, [ 'success', 'rate_limit', 'server_error', 'auth_error', 'client_error', 'network_error', 'unexpected' ], true )
			? $outcome
			: 'unexpected';
	}
}
