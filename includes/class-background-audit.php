<?php
/**
 * Incremental missing-file audit. Never deletes or modifies attachments.
 *
 * @package MomentumLab\MediaFolder
 */
namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

final class Background_Audit {
	const OPTION = 'mlab_media_folder_audit_state';
	const HOOK = 'mlab_media_folder_audit_batch';
	const BATCH = 200;

	public function hooks() {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		add_action( 'add_attachment', array( $this, 'restart' ) );
		add_action( 'delete_attachment', array( $this, 'restart' ) );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'init', array( $this, 'schedule_if_needed' ), 30 );
	}

	public function meta_changed( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_wp_attached_file' === $meta_key && 'attachment' === get_post_type( $post_id ) ) {
			$this->restart();
		}
	}

	public function restart() {
		delete_option( self::OPTION );
		Health::clear_cache();
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	public function schedule_if_needed() {
		$state = get_option( self::OPTION );
		if ( ! is_array( $state ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Read-only keyset pagination, capped per cron execution.
	 * The audit is informational: offloaded files are intentionally excluded.
	 */
	public function run_batch() {
		global $wpdb;
		if ( ! apply_filters( 'mlab_media_folder_check_missing_files', true ) ) {
			return;
		}
		$uploads = wp_get_upload_dir();
		$base = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( false === $base ) {
			$this->schedule_retry();
			return;
		}
		$state = get_option( self::OPTION );
		if ( ! is_array( $state ) ) {
			$state = array( 'cursor' => 0, 'checked' => 0, 'missing' => array(), 'complete' => false );
		}
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
			$file = (string) $row->file;
			if ( '' === $file || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $file ) ) {
				continue;
			}
			$relative = str_replace( '\\', '/', $file );
			$segments = explode( '/', $relative );
			if ( '/' === $relative[0] || preg_match( '/^[a-z]:/i', $relative ) || in_array( '..', $segments, true ) || in_array( '.', $segments, true ) ) {
				continue;
			}
			if ( ! file_exists( $base . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $segments ) ) ) {
				$state['missing'][] = (int) $row->ID;
			}
		}
		$state['complete'] = count( (array) $rows ) < self::BATCH;
		$state['updated'] = time();
		update_option( self::OPTION, $state, false );
		if ( $state['complete'] ) {
			Health::clear_cache();
		}
		if ( ! $state['complete'] ) {
			$this->schedule_retry();
		}
	}

	private function schedule_retry() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	public static function status() {
		$state = get_option( self::OPTION );
		return is_array( $state ) ? $state : array( 'cursor' => 0, 'checked' => 0, 'missing' => array(), 'complete' => false );
	}
}
