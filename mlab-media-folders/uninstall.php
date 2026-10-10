<?php
/**
 * Uninstall: removes the folders and the version option.
 * Media files themselves are never touched.
 *
 * @package MomentumLab\MediaFolders
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Set MLAB_MF_KEEP_DATA to true in wp-config.php to keep the folders on uninstall.
 */
if ( defined( 'MLAB_MF_KEEP_DATA' ) && MLAB_MF_KEEP_DATA ) {
	return;
}

$mlab_mf_taxonomy = 'mlab_media_folder';

if ( ! taxonomy_exists( $mlab_mf_taxonomy ) ) {
	register_taxonomy(
		$mlab_mf_taxonomy,
		'attachment',
		array(
			'hierarchical' => true,
			'public'       => false,
			'rewrite'      => false,
		)
	);
}

$mlab_mf_terms = get_terms(
	array(
		'taxonomy'   => $mlab_mf_taxonomy,
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);

if ( ! is_wp_error( $mlab_mf_terms ) ) {
	foreach ( $mlab_mf_terms as $mlab_mf_term_id ) {
		wp_delete_term( (int) $mlab_mf_term_id, $mlab_mf_taxonomy );
	}
}

delete_option( 'mlab_mf_version' );
