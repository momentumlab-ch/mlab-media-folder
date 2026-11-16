<?php
/**
 * Conservative background usage audit for potentially unused media.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Scans content and common attachment references in bounded post batches.
 */
final class Usage_Audit {

	const OPTION    = 'mlab_media_folder_usage_audit';
	const HOOK      = 'mlab_media_folder_usage_audit_batch';
	const SEEN_META = '_mlab_media_folder_usage_seen';
	const BATCH     = 100;

	/**
	 * Register lifecycle hooks.
	 */
	public function hooks(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		add_action( 'save_post', array( $this, 'content_changed' ), 30, 2 );
		add_action( 'deleted_post', array( $this, 'deleted_post' ), 30, 2 );
		add_action( 'add_attachment', array( __CLASS__, 'restart' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'restart' ) );
		add_action( 'init', array( $this, 'schedule_if_needed' ), 32 );
	}

	/**
	 * Restart after non-attachment content changes.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function content_changed( int $post_id, $post ): void {
		unset( $post_id );
		if ( $post instanceof \WP_Post && 'attachment' !== $post->post_type && 'revision' !== $post->post_type ) {
			self::restart();
		}
	}

	/**
	 * Restart after non-attachment content is deleted.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Deleted post.
	 */
	public function deleted_post( int $post_id, $post ): void {
		unset( $post_id );
		if ( $post instanceof \WP_Post && 'attachment' !== $post->post_type ) {
			self::restart();
		}
	}

	/**
	 * Start a new generation without deleting old markers.
	 */
	public static function restart(): void {
		Folders::clear_cache();
		update_option(
			self::OPTION,
			array(
				'generation' => wp_generate_uuid4(),
				'cursor'     => 0,
				'checked'    => 0,
				'complete'   => false,
				'updated'    => time(),
			),
			false
		);

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Ensure a scan exists.
	 */
	public function schedule_if_needed(): void {
		if ( ! is_array( get_option( self::OPTION ) ) ) {
			self::restart();
		}
	}

	/**
	 * Run one bounded post scan.
	 */
	public function run_batch(): void {
		global $wpdb;

		$state = self::status();
		if ( ! empty( $state['complete'] ) ) {
			return;
		}

		$cursor = absint( $state['cursor'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded indexed keyset scan over posts.
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				WHERE ID > %d
				AND post_type NOT IN ('attachment','revision','nav_menu_item')
				AND post_status NOT IN ('trash','auto-draft')
				ORDER BY ID ASC
				LIMIT %d",
				$cursor,
				self::BATCH
			)
		);

		foreach ( (array) $posts as $post ) {
			$post_id         = (int) $post->ID;
			$state['cursor'] = $post_id;
			++$state['checked'];

			$ids = self::ids_from_content( (string) $post->post_content );

			$thumbnail = absint( get_post_meta( $post_id, '_thumbnail_id', true ) );
			if ( 0 < $thumbnail ) {
				$ids[] = $thumbnail;
			}

			$product_gallery = (string) get_post_meta( $post_id, '_product_image_gallery', true );
			if ( '' !== $product_gallery ) {
				$ids = array_merge( $ids, array_map( 'absint', explode( ',', $product_gallery ) ) );
			}

			foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $attachment_id ) {
				if ( 'attachment' === get_post_type( $attachment_id ) ) {
					update_post_meta( $attachment_id, self::SEEN_META, $state['generation'] );
				}
			}
		}

		$state['complete'] = count( (array) $posts ) < self::BATCH;
		$state['updated']  = time();
		update_option( self::OPTION, $state, false );
		if ( $state['complete'] ) {
			Folders::clear_cache();
		}

		if ( ! $state['complete'] && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Extract attachment IDs from common WordPress/Gutenberg content patterns.
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	public static function ids_from_content( string $content ): array {
		$ids = array();

		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $content, $matches ) ) {
			$ids = array_merge( $ids, array_map( 'absint', $matches[1] ) );
		}
		if ( preg_match_all( '/["\']id["\']\s*:\s*(\d+)/', $content, $matches ) ) {
			$ids = array_merge( $ids, array_map( 'absint', $matches[1] ) );
		}
		if ( preg_match_all( '/\bids=["\']([0-9,\s]+)["\']/', $content, $matches ) ) {
			foreach ( $matches[1] as $list ) {
				$ids = array_merge( $ids, array_map( 'absint', false === preg_split( '/\s*,\s*/', $list ) ? array() : preg_split( '/\s*,\s*/', $list ) ) );
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Current normalized state.
	 *
	 * @return array{generation:string,cursor:int,checked:int,complete:bool,updated:int}
	 */
	public static function status(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array_merge(
			array(
				'generation' => '',
				'cursor'     => 0,
				'checked'    => 0,
				'complete'   => false,
				'updated'    => 0,
			),
			$state
		);
	}
}
