<?php
/**
 * Removes the plugin under its old name.
 *
 * Up to 1.1.6 the plugin was called "mlab-media-folders" and lived in the
 * folder of that name. Its folders (taxonomy mlab_media_folder) are the same,
 * so only the old copy has to go: it is deactivated and its folder deleted.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivates and deletes the old mlab-media-folders plugin.
 */
final class Legacy {

	/**
	 * File name of the old plugin (and of the bridge that updated it to this one).
	 */
	const OLD_FILE = 'mlab-media-folders.php';

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		/*
		 * The old uninstall.php deletes all folders unless MLAB_MF_KEEP_DATA is set.
		 * Those folders are this plugin's folders now, so never let it run.
		 */
		if ( ! defined( 'MLAB_MF_KEEP_DATA' ) ) {
			define( 'MLAB_MF_KEEP_DATA', true );
		}

		add_action( 'admin_init', array( $this, 'remove_old_plugin' ) );
	}

	/**
	 * Deactivates the old plugin and deletes its folder without running its uninstall routine.
	 */
	public function remove_old_plugin() {
		if ( wp_doing_ajax() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$own = plugin_basename( MLAB_MEDIA_FOLDER_FILE );
		$old = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( self::OLD_FILE === basename( $file ) && dirname( $file ) !== dirname( $own ) && '.' !== dirname( $file ) ) {
				$old[] = $file;
			}
		}
		if ( ! $old ) {
			return;
		}

		foreach ( $old as $file ) {
			if ( is_plugin_active_for_network( $file ) ) {
				deactivate_plugins( $file, true, true );
			}
			if ( is_plugin_active( $file ) ) {
				deactivate_plugins( $file, true );
			}
			$this->move_auto_update( $file, $own );
		}

		if ( ! current_user_can( 'delete_plugins' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method( array(), WP_PLUGIN_DIR ) || ! WP_Filesystem() ) {
			return;
		}

		global $wp_filesystem;
		foreach ( $old as $file ) {
			$wp_filesystem->delete( trailingslashit( WP_PLUGIN_DIR ) . dirname( $file ), true );
		}
		wp_clean_plugins_cache( false );
	}

	/**
	 * Keeps automatic updates switched on when they were on for the old plugin.
	 *
	 * @param string $old_file Basename of the old plugin.
	 * @param string $new_file Basename of this plugin.
	 */
	private function move_auto_update( $old_file, $new_file ) {
		$auto = (array) get_site_option( 'auto_update_plugins', array() );
		if ( ! in_array( $old_file, $auto, true ) ) {
			return;
		}

		$auto   = array_diff( $auto, array( $old_file ) );
		$auto[] = $new_file;
		update_site_option( 'auto_update_plugins', array_values( array_unique( $auto ) ) );
	}
}
