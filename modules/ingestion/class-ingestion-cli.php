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
	 * For multisite, use the --url flag to target a specific site. This ensures
	 * the site's theme and plugins are loaded, allowing site-specific hooks to fire.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>...
	 * : One or more post IDs to delete from Salesforce.
	 *
	 * [--blog-id=<blog_id>]
	 * : Blog ID for multisite. Doesn't require the blog to exist - useful for deleting
	 *   records from deleted blogs. Defaults to current blog. If used in conjunction
	 *   with --url to load site context, the blog ID should match the site loaded by --url.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete a single post
	 *     wp vip-agentforce ingestion delete 123
	 *
	 *     # Delete multiple posts
	 *     wp vip-agentforce ingestion delete 123 456 789
	 *
	 *     # Delete on a specific site (multisite) - use --url to load site context
	 *     wp vip-agentforce ingestion delete 123 --blog-id=2 --url=https://subsite.example.com
	 *
	 * @subcommand delete
	 *
	 * @param array<int, string> $args       Positional arguments (post IDs).
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function delete( array $args, array $assoc_args ): void {
		$blog_id = $assoc_args['blog-id'] ?? (string) get_current_blog_id();
		$site_id = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';

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

		if ( $failure_count > 0 ) {
			WP_CLI::error( sprintf( 'Completed with %d success(es) and %d failure(s).', $success_count, $failure_count ), false );
		} else {
			WP_CLI::success( sprintf( 'All %d record(s) deleted successfully.', $success_count ) );
		}
	}
}

WP_CLI::add_command( 'vip-agentforce ingestion', Ingestion_CLI::class );
