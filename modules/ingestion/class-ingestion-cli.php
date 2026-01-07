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
	 * Sync all eligible published posts to Salesforce.
	 *
	 * This command queries all published posts, applies the configured filters,
	 * and syncs matching posts to Salesforce. It does NOT trigger WordPress save hooks.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview what would be synced without actually sending to Salesforce.
	 *
	 * [--batch-size=<number>]
	 * : Number of posts to process per batch. Default: 100.
	 *
	 * [--post-type=<type>]
	 * : Limit sync to a specific post type. Default: all public post types.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview what would be synced
	 *     wp vip-agentforce ingestion sync --dry-run
	 *
	 *     # Sync all eligible posts
	 *     wp vip-agentforce ingestion sync
	 *
	 *     # Sync only 'post' post type with smaller batches
	 *     wp vip-agentforce ingestion sync --post-type=post --batch-size=50
	 *
	 * @subcommand sync
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$dry_run    = isset( $assoc_args['dry-run'] );
		$batch_size = (int) ( $assoc_args['batch-size'] ?? 100 );
		$post_type  = $assoc_args['post-type'] ?? null;

		// Check that filters are registered.
		if ( ! has_filter( 'vip_agentforce_should_ingest_post' ) ) {
			WP_CLI::error( 'No vip_agentforce_should_ingest_post filter registered. Cannot determine which posts to sync.' );
			return;
		}

		// Determine post types to query.
		$post_types = $post_type ? [ $post_type ] : get_post_types( [ 'public' => true ] );

		if ( $dry_run ) {
			WP_CLI::log( 'DRY RUN: No changes will be made.' );
		}

		WP_CLI::log( sprintf( 'Starting sync for post types: %s', implode( ', ', $post_types ) ) );

		$success_count = 0;
		$failure_count = 0;
		$skipped_count = 0;
		$total_queried = 0;
		$page          = 1;

		do {
			$query = new \WP_Query(
				[
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => $batch_size,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => false,
				]
			);

			$total_posts = $query->found_posts;

			if ( 1 === $page ) {
				WP_CLI::log( sprintf( 'Found %d published posts to evaluate.', $total_posts ) );
			}

			foreach ( $query->posts as $post ) {
				++$total_queried;

				// Apply the should_ingest filter - this respects all configured filters.
				if ( ! Ingestion::should_ingest_post( $post ) ) {
					++$skipped_count;
					continue;
				}

				// Transform the post using the configured transformer.
				$record = Ingestion::transform_post( $post );
				if ( null === $record ) {
					WP_CLI::warning( sprintf( 'Post %d: Transform failed, skipping.', $post->ID ) );
					++$failure_count;
					continue;
				}

				if ( $dry_run ) {
					WP_CLI::log( sprintf( 'Post %d: Would sync "%s"', $post->ID, $post->post_title ) );
					++$success_count;
					continue;
				}

				// Mark that we're attempting to ingest this post (same as handle_save_post does).
				update_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, time() );

				// Send to API.
				$result = Ingestion::send_to_api( $record );

				if ( $result->success ) {
					WP_CLI::log( sprintf( 'Post %d: Synced successfully.', $post->ID ) );
					++$success_count;
				} else {
					WP_CLI::warning( sprintf( 'Post %d: API error - %s', $post->ID, $result->error_message ?? 'Unknown error' ) );
					++$failure_count;
				}
			}

			++$page;
		} while ( $total_queried < $total_posts );

		// Summary.
		WP_CLI::log( '' );
		WP_CLI::log( '=== Sync Summary ===' );
		WP_CLI::log( sprintf( 'Total posts evaluated: %d', $total_queried ) );
		WP_CLI::log( sprintf( 'Skipped (did not pass filters): %d', $skipped_count ) );
		WP_CLI::log( sprintf( 'Synced successfully: %d', $success_count ) );
		WP_CLI::log( sprintf( 'Failed: %d', $failure_count ) );

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run complete. No changes were made.' );
		} elseif ( $failure_count > 0 ) {
			WP_CLI::warning( sprintf( 'Sync completed with %d failure(s).', $failure_count ) );
		} else {
			WP_CLI::success( 'Sync completed successfully.' );
		}
	}

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

			$result = Ingestion::delete_record_id_from_api( $record_id );

			if ( $result->success ) {
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
				WP_CLI::warning( sprintf( 'Failed to delete record %s: %s', $record_id, $result->error_message ?? 'Unknown error' ) );
				++$failure_count;

				Logger::info(
					'ingestion-cli',
					'Failed to delete record from Salesforce',
					[
						'post_id'   => $post_id,
						'record_id' => $record_id,
						'result'    => $result,
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
