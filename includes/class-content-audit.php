<?php
/**
 * Incremental content fingerprint audit for duplicate detection.
 *
 * @package MomentumLab\MediaFolder
 */
namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

final class Content_Audit {
	const OPTION = 'mlab_media_folder_content_audit_state';
	const HOOK = 'mlab_media_folder_content_audit_batch';
	const BATCH = 10;
	const HASH_META = '_mlab_media_folder_sha256';
	const SIG_META = '_mlab_media_folder_signature';

	public function hooks() {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		add_action( 'add_attachment', array( __CLASS__, 'restart_scan' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'restart_scan' ) );
		add_action( 'updated_post_meta', array( $this, 'file_meta_changed' ), 10, 4 );
		add_action( 'init', array( $this, 'schedule_if_needed' ), 31 );
	}

	public function file_meta_changed( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_wp_attached_file' === $meta_key && 'attachment' === get_post_type( $post_id ) ) {
			delete_post_meta( $post_id, self::HASH_META );
			delete_post_meta( $post_id, self::SIG_META );
			self::restart_scan();
		}
	}

	public static function restart_scan() {
		delete_option( self::OPTION );
		Health::clear_cache();
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	public function schedule_if_needed() {
		if ( ! is_array( get_option( self::OPTION ) ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	public function run_batch() {
		global $wpdb;
		if ( ! apply_filters( 'mlab_media_folder_check_content_duplicates', true ) ) {
			delete_option( self::OPTION );
			Health::clear_cache();
			return;
		}
		$uploads = wp_get_upload_dir();
		$base = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( false === $base ) {
			$this->schedule_retry();
			return;
		}
		$state = self::status();
		if ( ! empty( $state['complete'] ) ) {
			return;
		}
		$cursor = absint( $state['cursor'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded indexed keyset scan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, m.meta_value AS file FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.ID > %d
				ORDER BY p.ID ASC LIMIT %d",
				$cursor,
				self::BATCH
			)
		);
		foreach ( (array) $rows as $row ) {
			$state['cursor'] = (int) $row->ID;
			++$state['checked'];
			$relative = self::safe_relative_path( (string) $row->file );
			if ( null === $relative ) {
				continue;
			}
			$path = $base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$size = filesize( $path );
			$mtime = filemtime( $path );
			if ( false === $size || false === $mtime ) {
				continue;
			}
			$signature = $size . ':' . $mtime;
			if ( $signature === (string) get_post_meta( (int) $row->ID, self::SIG_META, true )
				&& '' !== (string) get_post_meta( (int) $row->ID, self::HASH_META, true ) ) {
				continue;
			}
			$hash = hash_file( 'sha256', $path );
			if ( false !== $hash ) {
				update_post_meta( (int) $row->ID, self::HASH_META, $hash );
				update_post_meta( (int) $row->ID, self::SIG_META, $signature );
			}
		}
		$state['complete'] = count( (array) $rows ) < self::BATCH;
		$state['updated'] = time();
		update_option( self::OPTION, $state, false );
		if ( $state['complete'] ) {
			Health::clear_cache();
		} else {
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
		return is_array( $state ) ? $state : array( 'cursor' => 0, 'checked' => 0, 'complete' => false );
	}

	public static function safe_relative_path( $file ) {
		if ( '' === $file || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $file ) ) {
			return null;
		}
		$relative = str_replace( '\\', '/', $file );
		$segments = explode( '/', $relative );
		if ( '' === $relative || '/' === $relative[0] || preg_match( '/^[a-z]:/i', $relative )
			|| in_array( '..', $segments, true ) || in_array( '.', $segments, true ) ) {
			return null;
		}
		return implode( '/', $segments );
	}
}
