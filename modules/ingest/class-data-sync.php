<?php

namespace Automattic\VIP\Salesforce\Agentforce\Ingest;

use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;
use WP_Post;

class Data_Sync {

	public static function init(): void {
		add_action( 'vip_agentforce_ingest_cron', [ __CLASS__, 'run_ingestion' ] );
		
		if ( ! wp_next_scheduled( 'vip_agentforce_ingest_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'vip_agentforce_ingest_cron' );
		}
	}

	public static function run_ingestion(): void {
		$posts = self::get_posts_to_sync();
		if ( empty( $posts ) ) {
			return;
		}

		$transformed_data = array_map( [ __CLASS__, 'transform_post' ], $posts );
		
		$result = Ingest_Client::ingest_data( $transformed_data );

		if ( ! $result['success'] ) {
			Logger::error( 'ingest', 'Ingestion failed: ' . $result['message'] );
		} else {
			Logger::info( 'ingest', 'Ingested ' . count( $posts ) . ' posts.' );
		}
	}

	/**
	 * @return array<int, WP_Post>
	 */
	private static function get_posts_to_sync(): array {
		// For now, just get latest 50 published posts.
		// In a real scenario, we'd track sync status/timestamps.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- suppress_filters is set to false
		return get_posts( [
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'numberposts'      => 50,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => false,
		] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function transform_post( WP_Post $post ): array {
		$categories  = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
		$tags        = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );
		$author_name = get_the_author_meta( 'display_name', (int) $post->post_author );

		return [
			'id'             => (string) $post->ID,
			'title'          => $post->post_title,
			'content'        => wp_strip_all_tags( $post->post_content ),
			'excerpt'        => get_the_excerpt( $post ),
			'categories'     => implode( ',', $categories ),
			'tags'           => implode( ',', $tags ),
			'author'         => $author_name,
			'published_date' => get_the_date( 'c', $post ),
			'release_year'   => (int) get_the_date( 'Y', $post ),
			// Add other fields if mapped in Data Cloud
		];
	}
}
