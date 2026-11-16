<?php
/**
 * Safe folder migration helpers.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

final class Migration {

	/**
	 * Known source taxonomies. Developers can add more through the filter.
	 *
	 * @return array<string,string>
	 */
	public static function sources(): array {
		$sources = array(
			'nt_wmc_folder' => 'FileBird',
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
		$out = array();
		foreach ( self::sources() as $taxonomy => $label ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$attachments = 0;
			foreach ( $terms as $term_id ) {
				$objects = get_objects_in_term( (int) $term_id, $taxonomy );
				if ( ! is_wp_error( $objects ) ) {
					$attachments += count( $objects );
				}
			}
			$out[] = array(
				'taxonomy'    => sanitize_key( $taxonomy ),
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
	 * @return array{folders:int,assignments:int}|\WP_Error
	 */
	public static function import( string $taxonomy ) {
		$sources = self::sources();
		if ( ! isset( $sources[ $taxonomy ] ) || ! taxonomy_exists( $taxonomy ) ) {
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

		$map = array();
		$created = 0;
		$assignments = 0;
		$pending = $terms;
		$guard = 0;

		while ( $pending && 50 > $guard++ ) {
			$next = array();
			$progress = false;

			foreach ( $pending as $term ) {
				$source_parent = (int) $term->parent;
				if ( 0 !== $source_parent && ! isset( $map[ $source_parent ] ) ) {
					$next[] = $term;
					continue;
				}

				$parent = 0 === $source_parent ? 0 : (int) $map[ $source_parent ];
				$name = Folders::name( $term );
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
	 * Find an existing direct child by display name.
	 */
	private static function find_child_by_name( string $name, int $parent ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => Taxonomy::NAME,
				'hide_empty' => false,
				'parent'     => $parent,
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
