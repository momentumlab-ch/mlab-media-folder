<?php
/**
 * Safe folder migration helpers.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Copies supported folder structures into Media Folder without mutating sources.
 */
final class Migration {

	/**
	 * Known source taxonomies. Developers can add more through the filter.
	 *
	 * @return array<string,string>
	 */
	public static function sources(): array {
		$sources = array(
			'__filebird_fbv'      => 'FileBird',
			'happyfiles_category' => 'HappyFiles',
			'nt_wmc_folder'       => 'FileBird (legacy taxonomy)',
		);

		/**
		 * Filters supported source taxonomies.
		 *
		 * @param array<string,string> $sources Taxonomy => label.
		 */
		return (array) apply_filters( 'mlab_media_folder_migration_sources', $sources );
	}

	/**
	 * Detect installed source structures.
	 *
	 * @return array<int,array{taxonomy:string,label:string,folders:int,attachments:int}>
	 */
	public static function detect(): array {
		global $wpdb;

		$out = array();
		foreach ( self::sources() as $source => $label ) {
			if ( '__filebird_fbv' === $source ) {
				$folder_table     = $wpdb->prefix . 'fbv';
				$assignment_table = $wpdb->prefix . 'fbv_attachment_folder';

				// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Read-only migration source detection.
				$folder_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $folder_table ) ) );
				$assign_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $assignment_table ) ) );
				if ( $folder_table === $folder_exists && $assignment_table === $assign_exists ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified table names are derived from the trusted WordPress prefix.
					$folders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$folder_table}" );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified table names are derived from the trusted WordPress prefix.
					$attachments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$assignment_table}" );
					if ( 0 < $folders ) {
						$out[] = array(
							'taxonomy'    => $source,
							'label'       => $label,
							'folders'     => $folders,
							'attachments' => $attachments,
						);
					}
				}
				// phpcs:enable WordPress.DB.DirectDatabaseQuery
				continue;
			}

			if ( ! self::taxonomy_has_data( $source ) ) {
				continue;
			}
			self::ensure_source_taxonomy( $source );

			$terms = get_terms(
				array(
					'taxonomy'   => $source,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}

			$attachments = 0;
			foreach ( $terms as $term_id ) {
				$objects = get_objects_in_term( (int) $term_id, $source );
				if ( ! is_wp_error( $objects ) ) {
					$attachments += count( $objects );
				}
			}
			$out[] = array(
				'taxonomy'    => sanitize_key( $source ),
				'label'       => sanitize_text_field( (string) $label ),
				'folders'     => count( $terms ),
				'attachments' => $attachments,
			);
		}
		return $out;
	}


	/**
	 * Copy one hierarchical taxonomy into Media Folder.
	 *
	 * Existing source terms and relationships are never changed or deleted.
	 *
	 * @param string $taxonomy Source taxonomy.
	 * @return array{folders:int,assignments:int}|\WP_Error
	 */
	public static function import( string $taxonomy ) {
		$sources = self::sources();
		if ( ! isset( $sources[ $taxonomy ] ) ) {
			return new \WP_Error( 'mlab_media_folder_migration_source', __( 'The selected migration source is not available.', 'mlab-media-folder' ), array( 'status' => 400 ) );
		}

		if ( '__filebird_fbv' === $taxonomy ) {
			return self::import_filebird_tables();
		}

		self::ensure_source_taxonomy( $taxonomy );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'mlab_media_folder_migration_source', __( 'The selected migration source is not available.', 'mlab-media-folder' ), array( 'status' => 400 ) );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$map         = array();
		$created     = 0;
		$assignments = 0;
		$pending     = $terms;
		$guard       = 0;

		while ( $pending && 50 > $guard ) {
			++$guard;
			$next     = array();
			$progress = false;

			foreach ( $pending as $term ) {
				$source_parent = (int) $term->parent;
				if ( 0 !== $source_parent && ! isset( $map[ $source_parent ] ) ) {
					$next[] = $term;
					continue;
				}

				$parent = 0 === $source_parent ? 0 : (int) $map[ $source_parent ];
				$name   = Folders::name( $term );
				$target = self::find_child_by_name( $name, $parent );

				if ( ! $target ) {
					$result = Folders::create( $name, $parent );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					$target = (int) $result['id'];
					++$created;
				}
				$map[ (int) $term->term_id ] = $target;

				$objects = get_objects_in_term( (int) $term->term_id, $taxonomy );
				if ( ! is_wp_error( $objects ) ) {
					foreach ( array_map( 'absint', $objects ) as $attachment_id ) {
						if ( 'attachment' !== get_post_type( $attachment_id ) ) {
							continue;
						}
						$result = wp_set_object_terms( $attachment_id, array( $target ), Taxonomy::NAME, false );
						if ( ! is_wp_error( $result ) ) {
							++$assignments;
						}
					}
				}
				$progress = true;
			}

			if ( ! $progress ) {
				break;
			}
			$pending = $next;
		}

		return array(
			'folders'     => $created,
			'assignments' => $assignments,
		);
	}

	/**
	 * Prepare a deterministic source-folder to target-folder map.
	 *
	 * Folder creation is intentionally separated from attachment assignment so
	 * large libraries can copy relationships in resumable background batches.
	 *
	 * @param string $taxonomy Source identifier.
	 * @return array{map:array<int,array{target:int,depth:int}>,created:int}|\WP_Error
	 */
	public static function prepare_map( string $taxonomy ) {
		$sources = self::sources();
		if ( ! isset( $sources[ $taxonomy ] ) ) {
			return new \WP_Error( 'mlab_media_folder_migration_source', __( 'The selected migration source is not available.', 'mlab-media-folder' ) );
		}

		if ( '__filebird_fbv' === $taxonomy ) {
			return self::prepare_filebird_map();
		}

		self::ensure_source_taxonomy( $taxonomy );
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$map     = array();
		$created = 0;
		$pending = (array) $terms;
		$guard   = 0;

		while ( $pending && 100 > $guard ) {
			++$guard;
			$next     = array();
			$progress = false;

			foreach ( $pending as $term ) {
				$source_id     = (int) $term->term_id;
				$source_parent = (int) $term->parent;
				if ( 0 !== $source_parent && ! isset( $map[ $source_parent ] ) ) {
					$next[] = $term;
					continue;
				}

				$parent_target = 0 === $source_parent ? 0 : (int) $map[ $source_parent ]['target'];
				$depth         = 0 === $source_parent ? 0 : (int) $map[ $source_parent ]['depth'] + 1;
				$target        = self::find_child_by_name( Folders::name( $term ), $parent_target );
				if ( ! $target ) {
					$result = Folders::create( Folders::name( $term ), $parent_target );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					$target = (int) $result['id'];
					++$created;
				}
				$map[ $source_id ] = array(
					'target' => $target,
					'depth'  => $depth,
				);
				$progress = true;
			}

			if ( ! $progress ) {
				break;
			}
			$pending = $next;
		}

		if ( $pending ) {
			return new \WP_Error( 'mlab_media_folder_migration_hierarchy', __( 'The source folder hierarchy could not be resolved safely.', 'mlab-media-folder' ) );
		}

		return array(
			'map'     => $map,
			'created' => $created,
		);
	}

	/**
	 * Copy one bounded batch of attachment relationships.
	 *
	 * When a source permits multiple folders per attachment, the deepest source
	 * folder wins deterministically because Media Folder intentionally uses one
	 * organisational folder per attachment.
	 *
	 * @param string                                      $taxonomy Source identifier.
	 * @param array<int,array{target:int,depth:int}>       $map      Prepared folder map.
	 * @param int                                         $cursor   Last processed attachment ID.
	 * @param int                                         $limit    Maximum attachments in this batch.
	 * @return array{cursor:int,checked:int,assignments:int,complete:bool}|\WP_Error
	 */
	public static function assignment_batch( string $taxonomy, array $map, int $cursor, int $limit = 100 ) {
		global $wpdb;

		$limit = max( 10, min( 250, $limit ) );
		$ids   = array();

		if ( '__filebird_fbv' === $taxonomy ) {
			$table = $wpdb->prefix . 'fbv_attachment_folder';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only verified migration source table.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT attachment_id FROM {$table} WHERE attachment_id > %d ORDER BY attachment_id ASC LIMIT %d",
					$cursor,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read-only keyset scan of source taxonomy relationships.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT tr.object_id
					FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.taxonomy = %s AND tr.object_id > %d
					ORDER BY tr.object_id ASC
					LIMIT %d",
					$taxonomy,
					$cursor,
					$limit
				)
			);
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids ) {
			return array(
				'cursor'      => $cursor,
				'checked'     => 0,
				'assignments' => 0,
				'complete'    => true,
			);
		}

		$relations = self::relations_for_attachments( $taxonomy, $ids );
		if ( is_wp_error( $relations ) ) {
			return $relations;
		}

		$assignments = 0;
		foreach ( $ids as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}
			$source_ids = $relations[ $attachment_id ] ?? array();
			$best       = 0;
			$best_depth = -1;
			foreach ( $source_ids as $source_id ) {
				if ( ! isset( $map[ $source_id ] ) ) {
					continue;
				}
				$depth = (int) $map[ $source_id ]['depth'];
				if ( $depth > $best_depth || ( $depth === $best_depth && $source_id < $best ) ) {
					$best       = $source_id;
					$best_depth = $depth;
				}
			}
			if ( ! $best ) {
				continue;
			}

			$result = wp_set_object_terms( $attachment_id, array( (int) $map[ $best ]['target'] ), Taxonomy::NAME, false );
			if ( ! is_wp_error( $result ) ) {
				++$assignments;
			}
		}

		return array(
			'cursor'      => max( $ids ),
			'checked'     => count( $ids ),
			'assignments' => $assignments,
			'complete'    => count( $ids ) < $limit,
		);
	}

	/**
	 * Read all source folder relations for a bounded attachment ID set.
	 *
	 * @param string $taxonomy Source identifier.
	 * @param int[]  $ids      Attachment IDs.
	 * @return array<int,int[]>|\WP_Error
	 */
	private static function relations_for_attachments( string $taxonomy, array $ids ) {
		global $wpdb;

		$out = array();
		if ( ! $ids ) {
			return $out;
		}
		$id_list = implode( ',', array_map( 'absint', $ids ) );

		if ( '__filebird_fbv' === $taxonomy ) {
			$table = $wpdb->prefix . 'fbv_attachment_folder';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are absint and table derives from trusted prefix.
			$rows = $wpdb->get_results( "SELECT attachment_id AS object_id, folder_id AS source_id FROM {$table} WHERE attachment_id IN ({$id_list})" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are absint; taxonomy is prepared below.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tr.object_id, tt.term_id AS source_id
					FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.taxonomy = %s AND tr.object_id IN ({$id_list})",
					$taxonomy
				)
			);
		}

		foreach ( (array) $rows as $row ) {
			$object_id = absint( $row->object_id );
			$source_id = absint( $row->source_id );
			if ( $object_id && $source_id ) {
				$out[ $object_id ][] = $source_id;
			}
		}
		return $out;
	}

	/**
	 * Prepare FileBird folder hierarchy only.
	 *
	 * @return array{map:array<int,array{target:int,depth:int}>,created:int}|\WP_Error
	 */
	private static function prepare_filebird_map() {
		global $wpdb;

		$table = $wpdb->prefix . 'fbv';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read-only verified migration source.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $exists ) {
			return new \WP_Error( 'mlab_media_folder_filebird_missing', __( 'FileBird migration tables were not found.', 'mlab-media-folder' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified table name derives from trusted WordPress prefix.
		$folders = $wpdb->get_results( "SELECT id, name, parent FROM {$table} ORDER BY id ASC" );

		$map     = array();
		$created = 0;
		$pending = (array) $folders;
		$guard   = 0;

		while ( $pending && 100 > $guard ) {
			++$guard;
			$next     = array();
			$progress = false;
			foreach ( $pending as $folder ) {
				$source_id     = absint( $folder->id );
				$source_parent = absint( $folder->parent );
				if ( 0 !== $source_parent && ! isset( $map[ $source_parent ] ) ) {
					$next[] = $folder;
					continue;
				}
				$parent_target = 0 === $source_parent ? 0 : (int) $map[ $source_parent ]['target'];
				$depth         = 0 === $source_parent ? 0 : (int) $map[ $source_parent ]['depth'] + 1;
				$name          = sanitize_text_field( (string) $folder->name );
				$target        = self::find_child_by_name( $name, $parent_target );
				if ( ! $target ) {
					$result = Folders::create( $name, $parent_target );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					$target = (int) $result['id'];
					++$created;
				}
				$map[ $source_id ] = array(
					'target' => $target,
					'depth'  => $depth,
				);
				$progress = true;
			}
			if ( ! $progress ) {
				break;
			}
			$pending = $next;
		}

		if ( $pending ) {
			return new \WP_Error( 'mlab_media_folder_migration_hierarchy', __( 'The FileBird folder hierarchy could not be resolved safely.', 'mlab-media-folder' ) );
		}
		return array(
			'map'     => $map,
			'created' => $created,
		);
	}

	/**
	 * Whether a taxonomy has stored terms even when its source plugin is inactive.
	 *
	 * @param string $taxonomy Source taxonomy.
	 */
	private static function taxonomy_has_data( string $taxonomy ): bool {
		global $wpdb;

		if ( taxonomy_exists( $taxonomy ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read-only source detection.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
				$taxonomy
			)
		);
		return 0 < (int) $count;
	}

	/**
	 * Register a hidden source taxonomy for this request so core term APIs can read inactive-plugin data.
	 *
	 * @param string $taxonomy Source taxonomy.
	 */
	private static function ensure_source_taxonomy( string $taxonomy ): void {
		if ( taxonomy_exists( $taxonomy ) ) {
			return;
		}
		register_taxonomy(
			$taxonomy,
			'attachment',
			array(
				'public'       => false,
				'show_ui'      => false,
				'hierarchical' => true,
				'rewrite'      => false,
			)
		);
	}

	/**
	 * Import current FileBird custom tables.
	 *
	 * @return array{folders:int,assignments:int}|\WP_Error
	 */
	private static function import_filebird_tables() {
		global $wpdb;

		$folder_table     = $wpdb->prefix . 'fbv';
		$assignment_table = $wpdb->prefix . 'fbv_attachment_folder';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Read-only migration source.
		$folder_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $folder_table ) ) );
		$assign_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $assignment_table ) ) );
		if ( $folder_table !== $folder_exists || $assignment_table !== $assign_exists ) {
			return new \WP_Error( 'mlab_media_folder_filebird_missing', __( 'FileBird migration tables were not found.', 'mlab-media-folder' ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified table name from trusted WordPress prefix.
		$folders = $wpdb->get_results( "SELECT id, name, parent FROM {$folder_table} ORDER BY id ASC" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified table name from trusted WordPress prefix.
		$relations = $wpdb->get_results( "SELECT attachment_id, folder_id FROM {$assignment_table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$map         = array();
		$created     = 0;
		$assignments = 0;
		$pending     = (array) $folders;
		$guard       = 0;

		while ( $pending && 100 > $guard ) {
			++$guard;
			$next     = array();
			$progress = false;

			foreach ( $pending as $folder ) {
				$source_id     = (int) $folder->id;
				$source_parent = (int) $folder->parent;
				if ( 0 !== $source_parent && ! isset( $map[ $source_parent ] ) ) {
					$next[] = $folder;
					continue;
				}

				$parent = 0 === $source_parent ? 0 : (int) $map[ $source_parent ];
				$name   = sanitize_text_field( (string) $folder->name );
				$target = self::find_child_by_name( $name, $parent );
				if ( ! $target ) {
					$result = Folders::create( $name, $parent );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					$target = (int) $result['id'];
					++$created;
				}
				$map[ $source_id ] = $target;
				$progress          = true;
			}

			if ( ! $progress ) {
				break;
			}
			$pending = $next;
		}

		foreach ( (array) $relations as $relation ) {
			$attachment_id = absint( $relation->attachment_id );
			$source_folder = absint( $relation->folder_id );
			if ( ! $attachment_id || ! isset( $map[ $source_folder ] ) || 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}
			$result = wp_set_object_terms( $attachment_id, array( (int) $map[ $source_folder ] ), Taxonomy::NAME, false );
			if ( ! is_wp_error( $result ) ) {
				++$assignments;
			}
		}

		return array(
			'folders'     => $created,
			'assignments' => $assignments,
		);
	}

	/**
	 * Find an existing direct child by display name.
	 *
	 * @param string $name      Folder name.
	 * @param int    $parent_id Parent folder ID.
	 */
	private static function find_child_by_name( string $name, int $parent_id ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => Taxonomy::NAME,
				'hide_empty' => false,
				'parent'     => $parent_id,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return 0;
		}
		foreach ( $terms as $term ) {
			if ( 0 === strcasecmp( Folders::name( $term ), $name ) ) {
				return (int) $term->term_id;
			}
		}
		return 0;
	}
}
