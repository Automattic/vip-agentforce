<?php
/**
 * Fake metric collector for ingestion metrics tests.
 *
 * @package vip-agentforce
 */

class Fake_Ingestion_Metric {
	/**
	 * @var array<string, int|float>
	 */
	private array $samples = [];

	/**
	 * @param array<int, string> $labels
	 */
	public function inc( array $labels = [] ): void {
		$this->incBy( 1, $labels );
	}

	/**
	 * @param array<int, string> $labels
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors Prometheus counter API.
	public function incBy( int|float $count, array $labels = [] ): void {
		$key                   = wp_json_encode( $labels );
		$this->samples[ $key ] = ( $this->samples[ $key ] ?? 0 ) + $count;
	}

	/**
	 * @param array<int, string> $labels
	 */
	public function set( int|float $value, array $labels = [] ): void {
		$this->samples[ wp_json_encode( $labels ) ] = $value;
	}

	/**
	 * @param array<int, string> $labels
	 */
	public function get_sample( array $labels = [] ) {
		return $this->samples[ wp_json_encode( $labels ) ] ?? null;
	}
}
