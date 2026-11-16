<?php
/**
 * Folder operations (domain logic shared by REST, admin and query code).
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Create, rename, move, delete and assign folders.
 */
final class Folders {

	/**
	 * Maximum length of a folder name.
	 */
	const MAX_NAME_LENGTH = 100;

	/**
	 * Maximum number of attachments moved in one request.
	 */
	const MAX_ASSIGN = 500;

	/**
	 * Generation option for folder/Smart View count caches.
	 */
	const CACHE_GENERATION_OPTION = 'mlab_media_folder_counts_generation';

	/**
	 * Object-cache group for count payloads.
	 */
	const CACHE_GROUP = 'mlab_media_folder';

	/**
	 * Invalidate count caches for every user without enumerating cache keys.
	 */
	public static function clear_cache(): void {
		$generation = absint( get_option( self::CACHE_GENERATION_OPTION, 1 ) );
		update_option( self::CACHE_GENERATION_OPTION, max( 2, $generation + 1 ), false );
	}

	/**
	 * Per-user cache key because Pro folder permissions can change visible totals.
	 */
	private static function cache_key( string $scope ): string {
		$generation = absint( get_option( self::CACHE_GENERATION_OPTION, 1 ) );
		return sanitize_key(
			$scope . ':' . $generation . ':' . get_current_user_id() . ':' . ( Features::is_pro() ? 'pro' : 'free' )
		);
	}

	/**
	 * Display name of a term. WordPress stores term names HTML-encoded
	 * ("&amp;"); the API returns plain text and every output escapes it.
	 *
	 * @param \WP_Term $term Term.
	 * @return string
	 */
	public static function name( $term ) {
		return html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Returns all folders as a flat list, sorted naturally by name.
	 *
	 * @return array<int, array{id:int, name:string, parent:int, count:int}>
	 */
	public static function all() {
		$terms = get_terms(
			array(
				'taxonomy'   => Taxonomy::NAME,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$counts  = self::counts();
		$folders = array_map(
			static function ( $term ) use ( $counts ) {
				$rules = Folder_Permissions::get( (int) $term->term_id );
				return array(
					'id'     => (int) $term->term_id,
					'name'   => self::name( $term ),
					'parent' => (int) $term->parent,
					'count'  => $counts[ (int) $term->term_id ] ?? 0,
					'color'  => Features::available( 'advanced_folder_style' ) ? $rules['color'] : '',
				);
			},
			$terms
		);

		usort(
			$folders,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);

		return Folder_Permissions::visible( $folders );
	}

	/**
	 * SQL condition for the entries the media library lists: not in the
	 * trash, file present on the server, and one entry per file (see
	 * Health::hidden_ids()). The counters use the same rule, so a counter
	 * always equals the number of images shown.
	 *
	 * @return string
	 */
	private static function visible_sql() {
		global $wpdb;

		$where  = "p.post_type = 'attachment' AND p.post_status <> 'trash'";
		$hidden = array_map( 'intval', Health::hidden_ids() );
		if ( $hidden ) {
			$where .= ' AND p.ID NOT IN (' . implode( ',', $hidden ) . ')';
		}

		$blocked = Folder_Permissions::inaccessible_ids();
		if ( $blocked ) {
			$where .= " AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} atr
				INNER JOIN {$wpdb->term_taxonomy} att ON att.term_taxonomy_id = atr.term_taxonomy_id
				WHERE atr.object_id = p.ID
				AND att.taxonomy = '" . Taxonomy::NAME . "'
				AND att.term_id IN (" . implode( ',', array_map( 'intval', $blocked ) ) . ')
			)';
		}
		return $where;
	}

	/**
	 * Number of images shown per folder.
	 *
	 * @return array<int, int> Term ID => number of images.
	 */
	public static function counts() {
		global $wpdb;

		$key    = self::cache_key( 'counts' );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$where = self::visible_sql();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate count, no API equivalent; the condition comes from visible_sql().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, COUNT(DISTINCT p.ID) AS files
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				WHERE tt.taxonomy = %s AND {$where}
				GROUP BY tt.term_id",
				Taxonomy::NAME
			)
		);
		// phpcs:enable

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->term_id ] = (int) $row->files;
		}
		wp_cache_set( $key, $counts, self::CACHE_GROUP, 300 );
		return $counts;
	}

	/**
	 * Number of shown images that match extra SQL conditions.
	 *
	 * @param string $join  Extra JOIN clauses (constant SQL).
	 * @param string $where Extra conditions, starting with AND (constant SQL).
	 * @return int
	 */
	private static function count_visible( $join = '', $where = '' ) {
		global $wpdb;

		$visible = self::visible_sql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate count, no API equivalent; all pieces are constant SQL.
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join} WHERE {$visible} {$where}" );
	}

	/**
	 * Number of visible attachments uploaded in the last seven days.
	 */
	private static function count_recent() {
		global $wpdb;

		$visible = self::visible_sql();
		$after   = wp_date( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate count; the visibility clause is generated internally and contains only sanitized IDs/constants.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p WHERE {$visible} AND p.post_date >= %s",
				$after
			)
		);
	}

	/**
	 * Number of conservatively unused visible attachments after a complete usage audit.
	 */
	private static function count_unused() {
		global $wpdb;

		$usage = Usage_Audit::status();
		if ( empty( $usage['complete'] ) || '' === $usage['generation'] ) {
			return 0;
		}

		$visible = self::visible_sql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate count; the visibility clause is internally generated and the generation is prepared.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} u
					ON u.post_id = p.ID AND u.meta_key = %s
				WHERE {$visible}
				AND p.post_parent = 0
				AND (u.meta_id IS NULL OR u.meta_value <> %s)",
				Usage_Audit::SEEN_META,
				$usage['generation']
			)
		);
	}

	/**
	 * Counts for the virtual entries: all images and the task views. The
	 * "duplicate" and "missing" views list the entries the library hides, so
	 * they count those entries.
	 *
	 * @return array{all:int, entries:int, recent:int, unfiled:int, noalt:int, unused:int, unattached:int, duplicate:int, missing:int}
	 */
	public static function totals() {
		global $wpdb;

		$key    = self::cache_key( 'totals' );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$entries = 0;
		foreach ( (array) wp_count_attachments() as $mime => $count ) {
			if ( 'trash' !== $mime ) {
				$entries += (int) $count;
			}
		}

		$in_folder = "AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tr.object_id = p.ID AND tt.taxonomy = '" . Taxonomy::NAME . "' )";
		$alt_join  = "LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt'";
		$no_alt    = "AND p.post_mime_type LIKE 'image/%' AND ( a.meta_id IS NULL OR a.meta_value = '' )";

		$health              = Health::scan();
		$health['duplicate'] = array_values( array_filter( $health['duplicate'], static fn( int $id ): bool => Folder_Permissions::can_access_attachment( $id ) ) );
		$health['missing']   = array_values( array_filter( $health['missing'], static fn( int $id ): bool => Folder_Permissions::can_access_attachment( $id ) ) );

		$totals = array(
			'all'        => self::count_visible(),
			'entries'    => $entries,
			'recent'     => self::count_recent(),
			'unfiled'    => self::count_visible( '', $in_folder ),
			'noalt'      => self::count_visible( $alt_join, $no_alt ),
			'unused'     => self::count_unused(),
			'unattached' => self::count_visible( '', 'AND p.post_parent = 0' ),
			'duplicate'  => count( $health['duplicate'] ),
			'missing'    => count( $health['missing'] ),
		);
		wp_cache_set( $key, $totals, self::CACHE_GROUP, 300 );
		return $totals;
	}

	/**
	 * Checks that a term ID is an existing folder.
	 *
	 * @param int $id Folder ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		$term = get_term( $id, Taxonomy::NAME );
		return $term && ! is_wp_error( $term );
	}

	/**
	 * Validates and normalises a folder name.
	 *
	 * @param mixed $name Raw name.
	 * @return string|WP_Error
	 */
	public static function sanitize_name( $name ) {
		$name = trim( sanitize_text_field( (string) $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'mlab_media_folder_empty_name', __( 'Please enter a folder name.', 'mlab-media-folder' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $name ) > self::MAX_NAME_LENGTH ) {
			$name = mb_substr( $name, 0, self::MAX_NAME_LENGTH );
		}
		return $name;
	}

	/**
	 * Returns true if a sibling folder with the same name exists.
	 *
	 * @param string $name       Folder name.
	 * @param int    $parent_id     Parent folder ID (0 = top level).
	 * @param int    $exclude_id Folder to ignore (when renaming).
	 * @return bool
	 */
	private static function sibling_exists( $name, $parent_id, $exclude_id = 0 ) {
		$siblings = get_terms(
			array(
				'taxonomy'   => Taxonomy::NAME,
				'hide_empty' => false,
				'parent'     => (int) $parent_id,
			)
		);
		if ( is_wp_error( $siblings ) ) {
			return false;
		}
		foreach ( $siblings as $sibling ) {
			if ( (int) $sibling->term_id !== (int) $exclude_id && 0 === strcasecmp( self::name( $sibling ), $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Creates a folder.
	 *
	 * @param mixed $name   Folder name.
	 * @param int   $parent_id Parent folder ID (0 = top level).
	 * @return array|WP_Error The new folder.
	 */
	public static function create( $name, $parent_id = 0 ) {
		$name = self::sanitize_name( $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$parent_id = (int) $parent_id;
		if ( $parent_id && ! self::exists( $parent_id ) ) {
			return new WP_Error( 'mlab_media_folder_bad_parent', __( 'The parent folder does not exist.', 'mlab-media-folder' ), array( 'status' => 400 ) );
		}
		if ( $parent_id && ! Folder_Permissions::can_access( $parent_id ) ) {
			return new WP_Error( 'mlab_media_folder_forbidden_folder', __( 'You are not allowed to use this folder.', 'mlab-media-folder' ), array( 'status' => 403 ) );
		}
		if ( self::sibling_exists( $name, $parent_id ) ) {
			return new WP_Error( 'mlab_media_folder_duplicate', __( 'A folder with this name already exists here.', 'mlab-media-folder' ), array( 'status' => 409 ) );
		}

		$result = wp_insert_term(
			$name,
			Taxonomy::NAME,
			array(
				'parent' => $parent_id,
				'slug'   => sanitize_title( $name ) . '-' . strtolower( wp_generate_password( 6, false ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::clear_cache();
		return array(
			'id'     => (int) $result['term_id'],
			'name'   => $name,
			'parent' => $parent_id,
			'count'  => 0,
		);
	}

	/**
	 * Duplicate one folder next to the original. Files are not duplicated.
	 *
	 * @param int $id Source folder ID.
	 * @return array|WP_Error
	 */
	public static function duplicate( $id ) {
		$id = (int) $id;
		if ( ! Features::is_pro() ) {
			return new WP_Error( 'mlab_media_folder_pro_required', __( 'Folder duplication requires MomentumLab Media Folder Pro.', 'mlab-media-folder' ), array( 'status' => 403 ) );
		}
		if ( ! self::exists( $id ) || ! Folder_Permissions::can_access( $id ) ) {
			return new WP_Error( 'mlab_media_folder_not_found', __( 'Folder not found.', 'mlab-media-folder' ), array( 'status' => 404 ) );
		}

		$term   = get_term( $id, Taxonomy::NAME );
		$base   = self::name( $term );
		$name   = $base . ' ' . __( 'Copy', 'mlab-media-folder' );
		$suffix = 2;
		while ( self::sibling_exists( $name, (int) $term->parent ) ) {
			$name = $base . ' ' . __( 'Copy', 'mlab-media-folder' ) . ' ' . $suffix;
			++$suffix;
		}

		$result = self::create( $name, (int) $term->parent );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Folder_Permissions::copy( $id, (int) $result['id'] );
		return $result;
	}

	/**
	 * Renames and/or moves a folder.
	 *
	 * @param int        $id     Folder ID.
	 * @param mixed|null $name   New name or null to keep it.
	 * @param int|null   $parent_id New parent or null to keep it.
	 * @return array|WP_Error The updated folder.
	 */
	public static function update( $id, $name = null, $parent_id = null ) {
		$id = (int) $id;
		if ( ! self::exists( $id ) ) {
			return new WP_Error( 'mlab_media_folder_not_found', __( 'Folder not found.', 'mlab-media-folder' ), array( 'status' => 404 ) );
		}
		if ( ! Folder_Permissions::can_access( $id ) ) {
			return new WP_Error( 'mlab_media_folder_forbidden_folder', __( 'You are not allowed to use this folder.', 'mlab-media-folder' ), array( 'status' => 403 ) );
		}
		$term = get_term( $id, Taxonomy::NAME );
		$args = array();

		$new_parent = null === $parent_id ? (int) $term->parent : (int) $parent_id;
		if ( null !== $parent_id ) {
			if ( $new_parent === $id ) {
				return new WP_Error( 'mlab_media_folder_bad_parent', __( 'A folder cannot be moved into itself.', 'mlab-media-folder' ), array( 'status' => 400 ) );
			}
			if ( $new_parent && ! self::exists( $new_parent ) ) {
				return new WP_Error( 'mlab_media_folder_bad_parent', __( 'The parent folder does not exist.', 'mlab-media-folder' ), array( 'status' => 400 ) );
			}
			$descendants = get_term_children( $id, Taxonomy::NAME );
			if ( ! is_wp_error( $descendants ) && in_array( $new_parent, array_map( 'intval', $descendants ), true ) ) {
				return new WP_Error( 'mlab_media_folder_bad_parent', __( 'A folder cannot be moved into one of its subfolders.', 'mlab-media-folder' ), array( 'status' => 400 ) );
			}
			$args['parent'] = $new_parent;
		}

		$new_name = self::name( $term );
		if ( null !== $name ) {
			$new_name = self::sanitize_name( $name );
			if ( is_wp_error( $new_name ) ) {
				return $new_name;
			}
			$args['name'] = $new_name;
		}

		if ( self::sibling_exists( $new_name, $new_parent, $id ) ) {
			return new WP_Error( 'mlab_media_folder_duplicate', __( 'A folder with this name already exists here.', 'mlab-media-folder' ), array( 'status' => 409 ) );
		}

		if ( $args ) {
			$result = wp_update_term( $id, Taxonomy::NAME, $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$term = get_term( $id, Taxonomy::NAME );
		self::clear_cache();
		return array(
			'id'     => (int) $term->term_id,
			'name'   => self::name( $term ),
			'parent' => (int) $term->parent,
			'count'  => self::counts()[ (int) $term->term_id ] ?? 0,
		);
	}

	/**
	 * Deletes a folder. Its files and subfolders move to the parent folder
	 * (or become unfiled / top level). Files themselves are never deleted.
	 *
	 * @param int $id Folder ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id = (int) $id;
		if ( ! self::exists( $id ) ) {
			return new WP_Error( 'mlab_media_folder_not_found', __( 'Folder not found.', 'mlab-media-folder' ), array( 'status' => 404 ) );
		}
		if ( ! Folder_Permissions::can_access( $id ) ) {
			return new WP_Error( 'mlab_media_folder_forbidden_folder', __( 'You are not allowed to use this folder.', 'mlab-media-folder' ), array( 'status' => 403 ) );
		}
		$term      = get_term( $id, Taxonomy::NAME );
		$parent_id = (int) $term->parent;

		$children = get_terms(
			array(
				'taxonomy'   => Taxonomy::NAME,
				'hide_empty' => false,
				'parent'     => $id,
				'fields'     => 'ids',
			)
		);
		if ( ! is_wp_error( $children ) ) {
			foreach ( $children as $child_id ) {
				wp_update_term( (int) $child_id, Taxonomy::NAME, array( 'parent' => $parent_id ) );
			}
		}

		$attachment_ids = get_objects_in_term( $id, Taxonomy::NAME );
		if ( ! is_wp_error( $attachment_ids ) && $attachment_ids && $parent_id ) {
			foreach ( $attachment_ids as $attachment_id ) {
				wp_set_object_terms( (int) $attachment_id, array( $parent_id ), Taxonomy::NAME, false );
			}
		}

		$result = wp_delete_term( $id, Taxonomy::NAME );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::clear_cache();
		return true;
	}

	/**
	 * Moves attachments into a folder (0 = unfiled). Each attachment is checked
	 * against the current user's edit_post capability.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @param int   $folder_id      Target folder ID or 0.
	 * @return array{moved:int[], skipped:int[], previous:array<int,int>}|WP_Error
	 */
	public static function assign( array $attachment_ids, $folder_id ) {
		$folder_id = (int) $folder_id;
		if ( $folder_id && ! self::exists( $folder_id ) ) {
			return new WP_Error( 'mlab_media_folder_not_found', __( 'Folder not found.', 'mlab-media-folder' ), array( 'status' => 404 ) );
		}
		if ( $folder_id && ! Folder_Permissions::can_access( $folder_id ) ) {
			return new WP_Error( 'mlab_media_folder_forbidden_folder', __( 'You are not allowed to use this folder.', 'mlab-media-folder' ), array( 'status' => 403 ) );
		}

		$attachment_ids = array_slice( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ), 0, self::MAX_ASSIGN );
		$moved          = array();
		$skipped        = array();
		$previous       = array();

		foreach ( $attachment_ids as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
				$skipped[] = $attachment_id;
				continue;
			}
			$before = self::of_attachment( $attachment_id );
			$result = wp_set_object_terms( $attachment_id, $folder_id ? array( $folder_id ) : array(), Taxonomy::NAME, false );
			if ( is_wp_error( $result ) ) {
				$skipped[] = $attachment_id;
			} else {
				$moved[]                    = $attachment_id;
				$previous[ $attachment_id ] = $before;
			}
		}

		return array(
			'moved'    => $moved,
			'skipped'  => $skipped,
			// Folder each moved file was in before (0 = unfiled), so a move can be undone.
			'previous' => (object) $previous,
		);
	}

	/**
	 * Returns the folder ID of an attachment (0 = unfiled).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	public static function of_attachment( $attachment_id ) {
		$ids = wp_get_object_terms( (int) $attachment_id, Taxonomy::NAME, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $ids ) || ! $ids ) {
			return 0;
		}
		return (int) $ids[0];
	}

	/**
	 * Folders ordered as a tree, each with its depth (for select lists).
	 *
	 * @return array<int, array{id:int, name:string, parent:int, count:int, depth:int}>
	 */
	public static function flattened_tree() {
		$by_parent = array();
		foreach ( self::all() as $folder ) {
			$by_parent[ $folder['parent'] ][] = $folder;
		}

		$out  = array();
		$walk = static function ( $parent_id, $depth ) use ( &$walk, &$out, $by_parent ) {
			if ( empty( $by_parent[ $parent_id ] ) || $depth > 20 ) {
				return;
			}
			foreach ( $by_parent[ $parent_id ] as $folder ) {
				$folder['depth'] = $depth;
				$out[]           = $folder;
				$walk( $folder['id'], $depth + 1 );
			}
		};
		$walk( 0, 0 );

		return $out;
	}

	/**
	 * Folder names from the top level down to a folder.
	 *
	 * @param int $folder_id Folder ID.
	 * @return string[]
	 */
	public static function path( $folder_id ) {
		$names = array();
		$guard = 0;
		$id    = (int) $folder_id;
		while ( $id && $guard++ < 20 ) {
			$term = get_term( $id, Taxonomy::NAME );
			if ( ! $term || is_wp_error( $term ) ) {
				break;
			}
			array_unshift( $names, self::name( $term ) );
			$id = (int) $term->parent;
		}
		return $names;
	}
}
