<?php
/**
 * Plugin Name:       MomentumLab Media Folders (renamed)
 * Plugin URI:        https://momentumlab.ch/
 * Description:       The plugin is now called MomentumLab Media Folder (mlab-media-folder). On the next admin page this installs it, activates it and removes this old copy. Your folders are kept.
 * Version:           2.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            MomentumLab
 * Author URI:        https://momentumlab.ch/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/momentumlab-ch/mlab-media-folder
 *
 * Installed sites up to 1.1.6 update to this file, because their updater only
 * knows the old name. It ships the new plugin in payload/ and hands over to it.
 * It has no uninstall.php on purpose: deleting it never touches the folders.
 *
 * @package MomentumLab\MediaFolder
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_init',
	function () {
		global $pagenow, $wp_filesystem;

		if ( wp_doing_ajax() || 'update.php' === $pagenow || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$self    = plugin_basename( __FILE__ );
		$new     = 'mlab-media-folder/mlab-media-folder.php';
		$target  = trailingslashit( WP_PLUGIN_DIR ) . 'mlab-media-folder';
		$payload = __DIR__ . '/payload/mlab-media-folder';

		if ( 'direct' !== get_filesystem_method( array(), WP_PLUGIN_DIR ) || ! WP_Filesystem() ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-warning"><p>'
						. esc_html( 'MomentumLab Media Folder could not be installed automatically. Please upload mlab-media-folder.zip from https://github.com/momentumlab-ch/mlab-media-folder/releases under Plugins > Add New > Upload Plugin and activate it. Your folders are kept.' )
						. '</p></div>';
				}
			);
			return;
		}

		// Copy the new plugin unless the same or a newer version is already there.
		$installed = is_readable( $target . '/mlab-media-folder.php' ) ? get_plugin_data( $target . '/mlab-media-folder.php', false, false )['Version'] : '';
		$shipped   = get_plugin_data( $payload . '/mlab-media-folder.php', false, false )['Version'];
		if ( '' === $installed || version_compare( $installed, $shipped, '<' ) ) {
			wp_mkdir_p( $target );
			$copied = copy_dir( $payload, $target );
			if ( is_wp_error( $copied ) ) {
				return;
			}
		}
		wp_clean_plugins_cache( false );

		$network = is_multisite() && is_plugin_active_for_network( $self );

		// Activate the new plugin first, so a failure leaves this one running to retry.
		if ( is_wp_error( activate_plugin( $new, '', $network ) ) ) {
			return;
		}
		deactivate_plugins( $self, true, $network );

		$auto = (array) get_site_option( 'auto_update_plugins', array() );
		if ( in_array( $self, $auto, true ) ) {
			$auto   = array_diff( $auto, array( $self ) );
			$auto[] = $new;
			update_site_option( 'auto_update_plugins', array_values( array_unique( $auto ) ) );
		}

		$wp_filesystem->delete( __DIR__, true );
		wp_clean_plugins_cache( false );

		if ( isset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
			wp_safe_redirect( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
			exit;
		}
	}
);
