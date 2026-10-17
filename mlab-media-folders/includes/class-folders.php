<?php
/**
 * Folder operations (domain logic shared by REST, admin and query code).
 *
 * @package MomentumLab\MediaFolders
 */

namespace MomentumLab\MediaFolders;

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
				return array(
					'id'     => (int) $term->term_id,
					'name'   => self::name( $term ),
					'parent' => (int) $term->parent,
					'count'  => $counts[ (int) $term->term_id ] ?? 0,
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

		return $folders;
	}

	/**
	 * Number of files per folder, counting only attachments that still exist
	 * and are not in the trash. The stored term count is not used because it
	 * also counts trashed files and leftovers of files deleted outside
	 * WordPress, so a folder could show files it no longer has.
	 *
	 * @return array<int, int> Term ID => number of files.
	 */
	public static function counts() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Aggregate count, no API equivalent; one query per request.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, COUNT(DISTINCT p.ID) AS files
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				WHERE tt.taxonomy = %s AND p.post_type = 'attachment' AND p.post_status <> 'trash'
				GROUP BY tt.term_id",
				Taxonomy::NAME
			)
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->term_id ] = (int) $row->files;
		}
		return $counts;
	}

	/**
	 * Counts for the virtual "All files" and "Unfiled" entries.
	 *
	 * @return array{all:int, unfiled:int}
	 */
	public static function totals() {
		global $wpdb;

		$all = 0;
		foreach ( (array) wp_count_attachments() as $mime => $count ) {
			if ( 'trash' !== $mime ) {
				$all += (int) $count;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Aggregate count, no API equivalent; cached by the caller's request only.
		$filed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT tr.object_id)
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				WHERE tt.taxonomy = %s AND p.post_type = 'attachment' AND p.post_status <> 'trash'",
				Taxonomy::NAME
			)
		);

		return array(
			'all'     => $all,
			'unfiled' => max( 0, $all - $filed ),
		);
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
			return new WP_Error( 'mlab_mf_empty_name', __( 'Please enter a folder name.', 'mlab-media-folders' ), array( 'status' => 400 ) );
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
			return new WP_Error( 'mlab_mf_bad_parent', __( 'The parent folder does not exist.', 'mlab-media-folders' ), array( 'status' => 400 ) );
		}
		if ( self::sibling_exists( $name, $parent_id ) ) {
			return new WP_Error( 'mlab_mf_duplicate', __( 'A folder with this name already exists here.', 'mlab-media-folders' ), array( 'status' => 409 ) );
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

		return array(
			'id'     => (int) $result['term_id'],
			'name'   => $name,
			'parent' => $parent_id,
			'count'  => 0,
		);
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
			return new WP_Error( 'mlab_mf_not_found', __( 'Folder not found.', 'mlab-media-folders' ), array( 'status' => 404 ) );
		}
		$term = get_term( $id, Taxonomy::NAME );
		$args = array();

		$new_parent = null === $parent_id ? (int) $term->parent : (int) $parent_id;
		if ( null !== $parent_id ) {
			if ( $new_parent === $id ) {
				return new WP_Error( 'mlab_mf_bad_parent', __( 'A folder cannot be moved into itself.', 'mlab-media-folders' ), array( 'status' => 400 ) );
			}
			if ( $new_parent && ! self::exists( $new_parent ) ) {
				return new WP_Error( 'mlab_mf_bad_parent', __( 'The parent folder does not exist.', 'mlab-media-folders' ), array( 'status' => 400 ) );
			}
			$descendants = get_term_children( $id, Taxonomy::NAME );
			if ( ! is_wp_error( $descendants ) && in_array( $new_parent, array_map( 'intval', $descendants ), true ) ) {
				return new WP_Error( 'mlab_mf_bad_parent', __( 'A folder cannot be moved into one of its subfolders.', 'mlab-media-folders' ), array( 'status' => 400 ) );
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
			return new WP_Error( 'mlab_mf_duplicate', __( 'A folder with this name already exists here.', 'mlab-media-folders' ), array( 'status' => 409 ) );
		}

		if ( $args ) {
			$result = wp_update_term( $id, Taxonomy::NAME, $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$term = get_term( $id, Taxonomy::NAME );
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
			return new WP_Error( 'mlab_mf_not_found', __( 'Folder not found.', 'mlab-media-folders' ), array( 'status' => 404 ) );
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
		return true;
	}

	/**
	 * Moves attachments into a folder (0 = unfiled). Each attachment is checked
	 * against the current user's edit_post capability.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @param int   $folder_id      Target folder ID or 0.
	 * @return array{moved:int[], skipped:int[]}|WP_Error
	 */
	public static function assign( array $attachment_ids, $folder_id ) {
		$folder_id = (int) $folder_id;
		if ( $folder_id && ! self::exists( $folder_id ) ) {
			return new WP_Error( 'mlab_mf_not_found', __( 'Folder not found.', 'mlab-media-folders' ), array( 'status' => 404 ) );
		}

		$attachment_ids = array_slice( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ), 0, self::MAX_ASSIGN );
		$moved          = array();
		$skipped        = array();

		foreach ( $attachment_ids as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
				$skipped[] = $attachment_id;
				continue;
			}
			$result = wp_set_object_terms( $attachment_id, $folder_id ? array( $folder_id ) : array(), Taxonomy::NAME, false );
			if ( is_wp_error( $result ) ) {
				$skipped[] = $attachment_id;
			} else {
				$moved[] = $attachment_id;
			}
		}

		return array(
			'moved'   => $moved,
			'skipped' => $skipped,
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
}
