<?php
/**
 * Plugin Name:       MomentumLab Media Folders
 * Plugin URI:        https://momentumlab.ch/
 * Description:       Organise the media library in folders: folder tree, drag and drop, upload into the current folder and a folder filter in every media dialog.
 * Version:           1.1.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            MomentumLab
 * Author URI:        https://momentumlab.ch/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mlab-media-folders
 * Domain Path:       /languages
 * Update URI:        https://github.com/momentumlab-ch/wp-media-folder
 *
 * @package MomentumLab\MediaFolders
 */

defined( 'ABSPATH' ) || exit;

define( 'MLAB_MF_VERSION', '1.1.4' );
define( 'MLAB_MF_FILE', __FILE__ );
define( 'MLAB_MF_DIR', plugin_dir_path( __FILE__ ) );
define( 'MLAB_MF_URL', plugin_dir_url( __FILE__ ) );

require_once MLAB_MF_DIR . 'includes/class-taxonomy.php';
require_once MLAB_MF_DIR . 'includes/class-folders.php';
require_once MLAB_MF_DIR . 'includes/class-rest-controller.php';
require_once MLAB_MF_DIR . 'includes/class-query.php';
require_once MLAB_MF_DIR . 'includes/class-admin.php';
require_once MLAB_MF_DIR . 'includes/class-updater.php';
require_once MLAB_MF_DIR . 'includes/class-plugin.php';

\MomentumLab\MediaFolders\Plugin::instance();

register_activation_hook( __FILE__, array( '\MomentumLab\MediaFolders\Plugin', 'activate' ) );
