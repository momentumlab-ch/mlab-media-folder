<?php
/**
 * Incremental missing-file audit.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the complete attachment library in bounded, resumable batches.
 */
final class Background_Audit {

	/**
	 * Persisted audit state option.
	 */
	const OPTION = 'mlab_media_folder_audit_state';

	/**
	 * WP-Cron hook.
	 */
	const HOOK = 'mlab_media_folder_audit_batch';

	/**
	 * Maximum attachments checked per batch.
	 */
	const BATCH = 200;

	/**
	 * Registers audit lifecycle hooks.
	 */
	public function hooks() {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		add_action( 'add_attachment', array( __CLASS__, 'restart_scan' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'restart_scan' ) );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'init', array( $this, 'schedule_if_needed' ), 30 );
	}

	/**
	 * Restarts the audit when the physical attachment path changes.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Attachment ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public function meta_changed( $meta_id, $post_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );
		if ( '_wp_attached_file' === $meta_key && 'attachment' === get_post_type( $post_id ) ) {
			self::restart_scan();
		}
	}

	/**
	 * Clears progress and schedules the first batch.
	 */
	public static function restart_scan() {
		delete_option( self::OPTION );
		Health::clear_cache();
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Ensures an enabled audit eventually runs without a repeating cron.
	 */
	public function schedule_if_needed() {
		$state   = get_option( self::OPTION );
		$enabled = (bool) apply_filters( 'mlab_media_folder_check_missing_files', true );

		if ( $enabled && is_array( $state ) && ! empty( $state['disabled'] ) ) {
			self::restart_scan();
			return;
		}

		if ( ! is_array( $state ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Runs one read-only keyset-pagination batch.
	 */
	public function run_batch() {
		global $wpdb;

		if ( ! apply_filters( 'mlab_media_folder_check_missing_files', true ) ) {
			update_option(
				self::OPTION,
				array(
					'cursor'   => 0,
					'checked'  => 0,
					'missing'  => array(),
					'complete' => true,
					'disabled' => true,
					'updated'  => time(),
				),
				false
			);
			Health::clear_cache();
			return;
		}

		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( false === $base ) {
			$this->schedule_retry();
			return;
		}

		$state = self::status();
		if ( ! empty( $state['complete'] ) ) {
			return;
		}

		$cursor = absint( $state['cursor'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded indexed keyset scan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, m.meta_value AS file FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.ID > %d
				ORDER BY p.ID ASC LIMIT %d",
				$cursor,
				self::BATCH
			)
		);

		foreach ( (array) $rows as $row ) {
			$state['cursor'] = (int) $row->ID;
			++$state['checked'];

			$relative = Content_Audit::safe_relative_path( (string) $row->file );
			if ( null === $relative ) {
				continue;
			}

			$path = $base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( ! file_exists( $path ) ) {
				$state['missing'][] = (int) $row->ID;
			}
		}

		$state['missing']  = array_values( array_unique( array_map( 'absint', (array) $state['missing'] ) ) );
		$state['complete'] = count( (array) $rows ) < self::BATCH;
		$state['disabled'] = false;
		$state['updated']  = time();
		update_option( self::OPTION, $state, false );

		if ( $state['complete'] ) {
			Health::clear_cache();
		} else {
			$this->schedule_retry();
		}
	}

	/**
	 * Schedules the next batch.
	 */
	private function schedule_retry() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Returns normalized persisted progress.
	 *
	 * @return array{cursor:int,checked:int,missing:int[],complete:bool,disabled?:bool,updated?:int}
	 */
	public static function status() {
		$state = get_option( self::OPTION );
		return is_array( $state )
			? $state
			: array(
				'cursor'   => 0,
				'checked'  => 0,
				'missing'  => array(),
				'complete' => false,
			);
	}
}
