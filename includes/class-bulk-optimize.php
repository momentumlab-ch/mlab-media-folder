<?php
/**
 * Explicit Pro rebuild of existing image derivatives.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds existing attachment derivatives in bounded background batches.
 *
 * Old derivatives are not deleted, so embedded historic URLs continue to work.
 */
final class Bulk_Optimize {

	const OPTION = 'mlab_media_folder_bulk_optimize';
	const HOOK   = 'mlab_media_folder_bulk_optimize_batch';
	const BATCH  = 8;

	/**
	 * Register cron hook.
	 */
	public function hooks(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
	}

	/**
	 * Start a fresh rebuild.
	 */
	public static function start(): bool {
		if ( ! Features::is_pro() ) {
			return false;
		}

		update_option(
			self::OPTION,
			array(
				'cursor'   => 0,
				'checked'  => 0,
				'rebuilt'  => 0,
				'failed'   => 0,
				'complete' => false,
				'updated'  => time(),
			),
			false
		);

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
		return true;
	}

	/**
	 * Run one bounded batch.
	 */
	public function run_batch(): void {
		global $wpdb;

		if ( ! Features::is_pro() ) {
			delete_option( self::OPTION );
			return;
		}

		$state = self::status();
		if ( ! empty( $state['complete'] ) ) {
			return;
		}

		$cursor = absint( $state['cursor'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded indexed keyset scan.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'attachment'
				AND post_status <> 'trash'
				AND post_mime_type LIKE %s
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				'image/%',
				$cursor,
				self::BATCH
			)
		);

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$state['cursor'] = $id;
			++$state['checked'];

			$file = get_attached_file( $id );
			if ( ! is_string( $file ) || ! is_readable( $file ) ) {
				++$state['failed'];
				continue;
			}

			$metadata = wp_generate_attachment_metadata( $id, $file );
			if ( is_array( $metadata ) && $metadata ) {
				wp_update_attachment_metadata( $id, $metadata );
				++$state['rebuilt'];
			} else {
				++$state['failed'];
			}
		}

		$state['complete'] = count( (array) $ids ) < self::BATCH;
		$state['updated']  = time();
		update_option( self::OPTION, $state, false );

		if ( ! $state['complete'] && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Current progress.
	 *
	 * @return array{cursor:int,checked:int,rebuilt:int,failed:int,complete:bool,updated:int}
	 */
	public static function status(): array {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return array_merge(
			array(
				'cursor'   => 0,
				'checked'  => 0,
				'rebuilt'  => 0,
				'failed'   => 0,
				'complete' => false,
				'updated'  => 0,
			),
			$state
		);
	}
}
