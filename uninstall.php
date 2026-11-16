<?php
/**
 * Uninstall cleanup.
 *
 * Folder data can be retained explicitly. Secrets, automation state, audit
 * state and plugin-private AI data are always removed.
 *
 * @package MomentumLab\MediaFolder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mlab_media_folder_taxonomy = 'mlab_media_folder';
$mlab_media_tag_taxonomy    = 'mlab_media_tag';
$mlab_keep_folders          = ( defined( 'MLAB_MEDIA_FOLDER_KEEP_DATA' ) && MLAB_MEDIA_FOLDER_KEEP_DATA )
	|| ( defined( 'MLAB_MF_KEEP_DATA' ) && MLAB_MF_KEEP_DATA );

if ( ! $mlab_keep_folders ) {
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
}

if ( ! taxonomy_exists( $mlab_media_tag_taxonomy ) ) {
	register_taxonomy(
		$mlab_media_tag_taxonomy,
		'attachment',
		array(
			'hierarchical' => false,
			'public'       => false,
			'rewrite'      => false,
		)
	);
}

$mlab_media_tag_terms = get_terms(
	array(
		'taxonomy'   => $mlab_media_tag_taxonomy,
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( ! is_wp_error( $mlab_media_tag_terms ) ) {
	foreach ( $mlab_media_tag_terms as $mlab_media_tag_term_id ) {
		wp_delete_term( (int) $mlab_media_tag_term_id, $mlab_media_tag_taxonomy );
	}
}

delete_option( 'mlab_media_folder_version' );
delete_option( 'mlab_media_folder_audit_state' );
delete_option( 'mlab_media_folder_content_audit_state' );
delete_option( 'mlab_media_folder_usage_audit' );
delete_option( 'mlab_media_folder_migration_job' );
delete_option( 'mlab_media_folder_automation' );
delete_option( 'mlab_media_folder_optimization' );
delete_option( 'mlab_media_folder_dynamic_views' );
delete_option( 'mlab_media_folder_bulk_optimize' );
delete_option( 'mlab_media_folder_ai_bulk_queue' );
delete_option( 'mlab_media_folder_counts_generation' );

delete_site_transient( 'mlab_media_folder_health' );
delete_site_transient( 'mlab_media_folder_github_release' );

delete_post_meta_by_key( '_mlab_media_folder_sha256' );
delete_post_meta_by_key( '_mlab_media_folder_signature' );
delete_post_meta_by_key( '_mlab_media_folder_file_size' );
delete_post_meta_by_key( '_mlab_media_folder_usage_seen' );
delete_post_meta_by_key( '_mlab_media_folder_ai_error' );
delete_post_meta_by_key( '_mlab_media_folder_ai_processed' );

wp_clear_scheduled_hook( 'mlab_media_folder_audit_batch' );
wp_clear_scheduled_hook( 'mlab_media_folder_content_audit_batch' );
wp_clear_scheduled_hook( 'mlab_media_folder_usage_audit_batch' );
wp_clear_scheduled_hook( 'mlab_media_folder_migration_batch' );
wp_clear_scheduled_hook( 'mlab_media_folder_ai_enrich' );
wp_clear_scheduled_hook( 'mlab_media_folder_ai_bulk_batch' );
wp_clear_scheduled_hook( 'mlab_media_folder_bulk_optimize_batch' );
