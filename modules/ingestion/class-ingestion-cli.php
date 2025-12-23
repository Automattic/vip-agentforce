<?php
/**
 * WP-CLI commands for ingestion module.
 *
 * @package vip-agentforce
 */

namespace Automattic\VIP\Salesforce\Agentforce\Ingestion;

use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;
use WP_CLI;
use WP_CLI_Command;

/**
 * Manages Salesforce ingestion operations via WP-CLI.
 */
class Ingestion_CLI extends WP_CLI_Command {

	/**
	 * Force delete posts from Salesforce by record ID.
	 *
	 * This command bypasses normal checks - it will attempt to delete even if:
	 * - The post doesn't exist in WordPress
	 * - The post was never ingested (no tracking meta)
	 *
	 * ## OPTIONS
	 *
	 * <post_id>...
	 * : One or more post IDs to delete from Salesforce.
	 *
	 * [--blog-id=<blog_id>]
	 * : Blog ID for multisite. Defaults to current blog.
	 *
	 * [--switch-blog]
	 * : Switch to the target blog before deletion. This allows site-specific
	 *   hooks and filters to fire. Requires the blog to exist.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete a single post
	 *     wp vip-agentforce ingestion delete 123
	 *
	 *     # Delete multiple posts
	 *     wp vip-agentforce ingestion delete 123 456 789
	 *
	 *     # Delete with explicit blog ID (multisite)
	 *     wp vip-agentforce ingestion delete 123 --blog-id=2
	 *
	 *     # Delete with blog switch (fires site-specific hooks)
	 *     wp vip-agentforce ingestion delete 123 --blog-id=2 --switch-blog
	 *
	 * @subcommand delete
	 *
	 * @param array<int, string> $args       Positional arguments (post IDs).
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function delete( array $args, array $assoc_args ): void {
		$blog_id     = $assoc_args['blog-id'] ?? (string) get_current_blog_id();
		$site_id     = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';
		$switch_blog = isset( $assoc_args['switch-blog'] );

		// If --switch-blog is set, verify the blog exists and switch to it.
		if ( $switch_blog ) {
			if ( ! get_blog_details( (int) $blog_id ) ) {
				WP_CLI::error( sprintf( 'Blog ID %s does not exist. Remove --switch-blog to force delete without switching.', $blog_id ) );
				return;
			}
			switch_to_blog( (int) $blog_id );
			WP_CLI::log( sprintf( 'Switched to blog %s.', $blog_id ) );
		}

		$success_count = 0;
		$failure_count = 0;

		foreach ( $args as $post_id ) {
			$record_id = $site_id . '_' . $blog_id . '_' . $post_id;

			Logger::info(
				'ingestion-cli',
				'Attempting to force delete record from Salesforce',
				[
					'post_id'   => $post_id,
					'blog_id'   => $blog_id,
					'record_id' => $record_id,
				]
			);

			$response = Ingestion::delete_record_id_from_api( $record_id );

			if ( $response['success'] ) {
				WP_CLI::success( sprintf( 'Deleted record %s from Salesforce.', $record_id ) );
				++$success_count;

				Logger::info(
					'ingestion-cli',
					'Record deleted from Salesforce successfully',
					[
						'post_id'   => $post_id,
						'record_id' => $record_id,
					]
				);
			} else {
				WP_CLI::warning( sprintf( 'Failed to delete record %s: %s', $record_id, $response['error_message'] ?? 'Unknown error' ) );
				++$failure_count;

				Logger::info(
					'ingestion-cli',
					'Failed to delete record from Salesforce',
					[
						'post_id'   => $post_id,
						'record_id' => $record_id,
						'response'  => $response,
					]
				);
			}
		}

		if ( $switch_blog ) {
			restore_current_blog();
		}

		if ( $failure_count > 0 ) {
			WP_CLI::error( sprintf( 'Completed with %d success(es) and %d failure(s).', $success_count, $failure_count ), false );
		} else {
			WP_CLI::success( sprintf( 'All %d record(s) deleted successfully.', $success_count ) );
		}
	}
}

WP_CLI::add_command( 'vip-agentforce ingestion', Ingestion_CLI::class );
