<?php
/**
 * Folder taxonomy for attachments.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the private, hierarchical folder taxonomy.
 */
final class Taxonomy {

	/**
	 * Taxonomy name.
	 */
	const NAME = 'mlab_media_folder';

	/**
	 * Registers the taxonomy. It has no public URLs and no core UI; the plugin
	 * provides its own UI and REST endpoints.
	 */
	public static function register() {
		if ( taxonomy_exists( self::NAME ) ) {
			return;
		}

		register_taxonomy(
			self::NAME,
			'attachment',
			array(
				'labels'                => array(
					'name'          => __( 'Media folders', 'mlab-media-folder' ),
					'singular_name' => __( 'Media folder', 'mlab-media-folder' ),
				),
				'hierarchical'          => true,
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => false,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_in_rest'          => false,
				'show_tagcloud'         => false,
				'show_admin_column'     => false,
				'query_var'             => false,
				'rewrite'               => false,
				// Attachments use the "inherit" status, so count every relationship.
				'update_count_callback' => '_update_generic_term_count',
			)
		);
	}
}
