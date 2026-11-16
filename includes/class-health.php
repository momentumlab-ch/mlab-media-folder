<?php
/**
 * Library health: entries that share one file and entries whose file is gone.
 *
 * The media library lists database entries (attachments), not files. Two
 * entries can point to the same file in the uploads folder, and an entry can
 * outlive its file. This class finds both and keeps a delete from removing a
 * file that another entry still uses.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Finds duplicate and missing files and protects shared files.
 */
final class Health {

	/**
	 * Site transient that caches the result of the last scan.
	 */
	const CACHE_KEY = 'mlab_media_folder_health';

	/**
	 * Cache lifetime. The cache is also cleared whenever an entry is added, deleted, trashed or restored.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * True while a file that other entries share must not be removed from disk.
	 *
	 * @var bool
	 */
	private $protecting = false;

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'add_attachment', array( __CLASS__, 'clear_cache' ) );
		// The kept entry of a file can move to the trash and back; the others must show up again.
		add_action( 'trashed_post', array( __CLASS__, 'clear_cache' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'clear_cache' ) );
		add_action( 'delete_attachment', array( $this, 'protect_shared_file' ), 9 );
		add_action( 'delete_attachment', array( __CLASS__, 'clear_cache' ), 10 );
		add_action( 'deleted_post', array( $this, 'release' ) );
		add_filter( 'wp_delete_file', array( $this, 'keep_file' ) );
	}

	/**
	 * Drops the cached scan.
	 */
	public static function clear_cache() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Attachment IDs per problem, from the cache or a fresh scan.
	 *
	 * "duplicate" lists every entry that shares its file, "extra" the entries
	 * that are hidden in the library because another entry with the lowest ID
	 * already shows the same file, "missing" the entries without a file.
	 *
	 * @return array{duplicate:int[], extra:int[], missing:int[]}
	 */
	public static function scan() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['duplicate'], $cached['extra'], $cached['missing'] ) ) {
			return $cached;
		}

		$groups = self::find_duplicates();
		$result = array(
			'duplicate' => $groups['all'],
			'extra'     => $groups['extra'],
			'missing'   => self::find_missing(),
		);
		set_site_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Entries in the library that must not be listed: their file is missing
	 * or another entry already shows the same file.
	 *
	 * @return int[]
	 */
	public static function hidden_ids() {
		$scan = self::scan();
		return array_values( array_unique( array_merge( $scan['missing'], $scan['extra'] ) ) );
	}

	/**
	 * Entries that point to the same file as at least one other entry.
	 *
	 * @return array{all:int[], extra:int[]} All entries of such groups, and all but the lowest ID of each group.
	 */
	private static function find_duplicates() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Aggregate lookup, no API equivalent; no user input.
		$rows = $wpdb->get_results(
			"SELECT m.post_id, m.meta_value AS file
			FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			INNER JOIN (
				SELECT m2.meta_value AS file
				FROM {$wpdb->postmeta} m2
				INNER JOIN {$wpdb->posts} p2 ON p2.ID = m2.post_id
				WHERE m2.meta_key = '_wp_attached_file' AND p2.post_type = 'attachment' AND p2.post_status <> 'trash'
				GROUP BY m2.meta_value
				HAVING COUNT(*) > 1
			) d ON d.file = m.meta_value
			WHERE m.meta_key = '_wp_attached_file' AND p.post_type = 'attachment' AND p.post_status <> 'trash'
			ORDER BY m.post_id ASC"
		);

		$all   = array();
		$extra = array();
		$seen  = array();
		foreach ( (array) $rows as $row ) {
			$id    = (int) $row->post_id;
			$all[] = $id;
			if ( isset( $seen[ $row->file ] ) ) {
				$extra[] = $id;
			}
			$seen[ $row->file ] = true;
		}
		return array(
			'all'   => $all,
			'extra' => $extra,
		);
	}

	/**
	 * Entries whose file is not in the uploads folder any more. Files stored
	 * elsewhere (a full URL as file name) are not checked, and the scan covers
	 * the newest entries only, so a very large library stays fast.
	 *
	 * @return int[]
	 */
	private static function find_missing() {
		global $wpdb;

		/**
		 * Filters whether entries are checked for a missing file. Turn it off
		 * when files live on a CDN or offload service and not on this server.
		 *
		 * @param bool $check Default true.
		 */
		if ( ! apply_filters( 'mlab_media_folder_check_missing_files', true ) ) {
			return array();
		}

		/**
		 * Filters how many of the newest entries are checked for a missing file.
		 *
		 * @param int $limit Default 5000.
		 */
		$limit = max( 1, min( 50000, (int) apply_filters( 'mlab_media_folder_missing_scan_limit', 5000 ) ) );
		$uploads = wp_get_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		if ( false === $base ) {
			// Do not classify the entire library as missing when uploads is unavailable.
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One bounded lookup per scan, cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, m.meta_value AS file
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'
				ORDER BY p.ID DESC
				LIMIT %d",
				$limit
			)
		);

		$missing = array();
		foreach ( (array) $rows as $row ) {
			if ( '' === $row->file || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $row->file ) ) {
				continue;
			}
			// Ignore absolute and traversal paths: never resolve a stored attachment outside uploads.
			$relative = str_replace( '\\', '/', (string) $row->file );
			$segments = explode( '/', $relative );
			if ( '' === $relative || '/' === $relative[0] || in_array( '..', $segments, true ) || in_array( '.', $segments, true ) || preg_match( '/^[a-z]:/i', $relative ) ) {
				continue;
			}
			$path = $base . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $segments );
			if ( ! file_exists( $path ) ) {
				$missing[] = (int) $row->ID;
			}
		}
		return $missing;
	}

	/**
	 * Other entries that point to the same file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $limit         Maximum number of IDs.
	 * @return int[]
	 */
	public static function others_sharing_file( $attachment_id, $limit = 3 ) {
		$file = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
		if ( '' === $file ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'numberposts'      => (int) $limit,
				'post__not_in'     => array( (int) $attachment_id ),
				'fields'           => 'ids',
				'meta_key'         => '_wp_attached_file', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $file, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Before an entry is deleted: if another entry uses the same file, keep
	 * the file on disk. WordPress would otherwise delete it and leave the
	 * other entry without an image.
	 *
	 * @param int $attachment_id Attachment about to be deleted.
	 */
	public function protect_shared_file( $attachment_id ) {
		$this->protecting = (bool) self::others_sharing_file( $attachment_id, 1 );
	}

	/**
	 * Filter for every file WordPress is about to delete.
	 *
	 * @param string $path Absolute file path.
	 * @return string Empty to skip the delete.
	 */
	public function keep_file( $path ) {
		return $this->protecting ? '' : $path;
	}

	/**
	 * After the delete: later deletes in the same request are checked on their own.
	 */
	public function release() {
		$this->protecting = false;
	}
}
