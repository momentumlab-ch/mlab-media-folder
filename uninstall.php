<?php
/**
 * Uninstall: removes the folders and the version option.
 * Media files themselves are never touched.
 *
 * @package MomentumLab\MediaFolder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Set MLAB_MEDIA_FOLDER_KEEP_DATA to true in wp-config.php to keep the folders on uninstall.
 */
if ( ( defined( 'MLAB_MEDIA_FOLDER_KEEP_DATA' ) && MLAB_MEDIA_FOLDER_KEEP_DATA ) || ( defined( 'MLAB_MF_KEEP_DATA' ) && MLAB_MF_KEEP_DATA ) ) {
	return;
}

$mlab_media_folder_taxonomy = 'mlab_media_folder';

if ( ! taxonomy_exists( $mlab_media_folder_taxonomy ) ) {
	register_taxonomy(
		$mlab_media_folder_taxonomy,
		'attachment',
		array(
			'hierarchical' => true,
			'public'       => false,
			'rewrite'      => false,
		)
	);
}

$mlab_media_folder_terms = get_terms(
	array(
		'taxonomy'   => $mlab_media_folder_taxonomy,
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);

if ( ! is_wp_error( $mlab_media_folder_terms ) ) {
	foreach ( $mlab_media_folder_terms as $mlab_media_folder_term_id ) {
		wp_delete_term( (int) $mlab_media_folder_term_id, $mlab_media_folder_taxonomy );
	}
}

delete_option( 'mlab_media_folder_version' );
