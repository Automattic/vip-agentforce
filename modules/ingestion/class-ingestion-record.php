<?php
/**
 * Ingestion Record data class.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Ingestion;

/**
 * Data class representing a record to be evaluated for ingestion.
 *
 * PHPStan will automatically infer types from constructor arguments:
 *   new Ingestion_Record( TYPE_POST, $post ) => Ingestion_Record<'post', WP_Post>
 *
 * @template-covariant TType of 'post'|'comment'|'user'
 * @template-covariant TRecord of \WP_Post|\WP_Comment|\WP_User
 *
 * @phpstan-type Post_Ingestion_Record Ingestion_Record<'post', \WP_Post>
 * @phpstan-type Comment_Ingestion_Record Ingestion_Record<'comment', \WP_Comment>
 * @phpstan-type User_Ingestion_Record Ingestion_Record<'user', \WP_User>
 */
class Ingestion_Record {
	public const TYPE_POST = 'post';
	// unimplemented types for future use
	public const TYPE_COMMENT = 'comment';
	// unimplemented types for future use
	public const TYPE_USER = 'user';

	/**
	 * Record type constant.
	 *
	 * @var TType
	 */
	public readonly string $type;

	/**
	 * The WordPress object.
	 *
	 * @var TRecord
	 */
	public readonly mixed $record;

	/**
	 * Constructor.
	 *
	 * @param TType   $type   Record type (TYPE_POST, TYPE_COMMENT, TYPE_USER).
	 * @param TRecord $record The WordPress object.
	 */
	public function __construct( string $type, mixed $record ) {
		$this->type   = $type;
		$this->record = $record;
	}
}
