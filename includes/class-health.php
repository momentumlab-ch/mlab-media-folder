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
	 * Absolute normalized paths that surviving attachments still reference.
	 *
	 * @var array<string,bool>
	 */
	private $protected_paths = array();

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
		add_action( 'updated_post_meta', array( __CLASS__, 'maybe_clear_file_cache' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'maybe_clear_file_cache' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'maybe_clear_file_cache' ), 10, 4 );
		add_filter( 'wp_delete_file', array( $this, 'keep_file' ) );
	}

	/**
	 * Clears health results when the physical attachment path changes.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Attachment ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function maybe_clear_file_cache( $meta_id, $post_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );
		if ( '_wp_attached_file' === $meta_key && 'attachment' === get_post_type( $post_id ) ) {
			self::clear_cache();
		}
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

		$groups             = self::find_duplicates();
		$content_duplicates = self::find_content_duplicates();
		$audit              = Background_Audit::status();
		// The incremental audit covers the entire library only after completion.
		// During a scan, keep the bounded synchronous results available.
		$missing = ! empty( $audit['complete'] ) && isset( $audit['missing'] ) && is_array( $audit['missing'] )
			? array_map( 'absint', $audit['missing'] )
			: self::find_missing();
		$result  = array(
			'duplicate' => array_values( array_unique( array_merge( $groups['all'], $content_duplicates ) ) ),
			'extra'     => $groups['extra'],
			'missing'   => $missing,
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
				WHERE m2.meta_key = '_wp_attached_file' AND m2.meta_value <> '' AND p2.post_type = 'attachment' AND p2.post_status <> 'trash'
				GROUP BY m2.meta_value
				HAVING COUNT(*) > 1
			) d ON d.file = m.meta_value
			WHERE m.meta_key = '_wp_attached_file' AND m.meta_value <> '' AND p.post_type = 'attachment' AND p.post_status <> 'trash'
			ORDER BY m.post_id ASC"
		);

		$all   = array();
		$extra = array();
		$seen  = array();
		foreach ( (array) $rows as $row ) {
			if ( null === Content_Audit::safe_relative_path( (string) $row->file ) ) {
				continue;
			}
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
	 * Entries whose physical files have identical SHA-256 content hashes.
	 * Only complete background-audit results are used to avoid partial groups.
	 *
	 * @return int[]
	 */
	private static function find_content_duplicates() {
		global $wpdb;
		$status = Content_Audit::status();
		if ( empty( $status['complete'] ) || ! empty( $status['disabled'] ) ) {
			return array();
		}
		$hash_key = Content_Audit::HASH_META;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal fixed meta key, aggregate query.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.post_id
				FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				INNER JOIN (
					SELECT meta_value
					FROM {$wpdb->postmeta}
					WHERE meta_key = %s AND meta_value <> ''
					GROUP BY meta_value HAVING COUNT(*) > 1
				) d ON d.meta_value = m.meta_value
				WHERE m.meta_key = %s AND p.post_type = 'attachment' AND p.post_status <> 'trash'
				ORDER BY m.post_id ASC",
				$hash_key,
				$hash_key
			)
		);
		return array_map( 'intval', (array) $rows );
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
		$limit   = max( 1, min( 50000, (int) apply_filters( 'mlab_media_folder_missing_scan_limit', 5000 ) ) );
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
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
			$relative = Content_Audit::safe_relative_path( (string) $row->file );
			if ( null === $relative ) {
				continue;
			}
			$path = $base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
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
		if ( null === Content_Audit::safe_relative_path( $file ) ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => array_values( get_post_stati() ),
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
	 * Other entries whose current physical files have the same SHA-256 content.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $limit Maximum number of IDs.
	 * @return int[]
	 */
	public static function others_sharing_content( $attachment_id, $limit = 3 ) {
		$hash   = (string) get_post_meta( (int) $attachment_id, Content_Audit::HASH_META, true );
		$status = Content_Audit::status();
		if ( '' === $hash || empty( $status['complete'] ) || ! empty( $status['disabled'] ) ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => array_values( get_post_stati() ),
				'numberposts'      => max( 1, (int) $limit ),
				'post__not_in'     => array( (int) $attachment_id ),
				'fields'           => 'ids',
				'meta_key'         => Content_Audit::HASH_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
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
		$this->protected_paths = array();
		foreach ( self::others_sharing_file( $attachment_id, -1 ) as $other_id ) {
			foreach ( self::attachment_paths( $other_id ) as $path ) {
				$this->protected_paths[ wp_normalize_path( $path ) ] = true;
			}
		}
	}

	/**
	 * All physical files currently referenced by one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	private static function attachment_paths( $attachment_id ) {
		$file = get_attached_file( (int) $attachment_id );
		if ( ! is_string( $file ) || '' === $file ) {
			return array();
		}

		$paths = array( $file );
		$dir   = dirname( $file );
		$meta  = wp_get_attachment_metadata( (int) $attachment_id );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['thumb'] ) ) {
				$paths[] = path_join( $dir, wp_basename( (string) $meta['thumb'] ) );
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$paths[] = path_join( $dir, wp_basename( (string) $meta['original_image'] ) );
			}
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$paths[] = path_join( $dir, wp_basename( (string) $size['file'] ) );
				}
			}
		}

		$backups = get_post_meta( (int) $attachment_id, '_wp_attachment_backup_sizes', true );
		foreach ( (array) $backups as $backup ) {
			if ( is_array( $backup ) && ! empty( $backup['file'] ) ) {
				$paths[] = path_join( $dir, wp_basename( (string) $backup['file'] ) );
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Filter for every file WordPress is about to delete.
	 *
	 * Only paths still referenced by a surviving attachment are suppressed.
	 *
	 * @param string $path Absolute file path.
	 * @return string Empty to skip a protected delete.
	 */
	public function keep_file( $path ) {
		$normalized = wp_normalize_path( (string) $path );
		return isset( $this->protected_paths[ $normalized ] ) ? '' : $path;
	}
}
