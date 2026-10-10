<?php
/**
 * Bootstrap: wires the components together and runs version upgrades.
 *
 * @package MomentumLab\MediaFolders
 */

namespace MomentumLab\MediaFolders;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Option that stores the installed plugin version (for upgrade routines).
	 */
	const VERSION_OPTION = 'mlab_mf_version';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'init', array( Taxonomy::class, 'register' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
		add_action( 'rest_api_init', array( new Rest_Controller(), 'register_routes' ) );

		( new Query() )->hooks();
		( new Updater() )->hooks();

		if ( is_admin() ) {
			( new Admin() )->hooks();
		}
	}

	/**
	 * Loads the bundled translations (.l10n.php files, WordPress 6.5+).
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'mlab-media-folders', false, dirname( plugin_basename( MLAB_MF_FILE ) ) . '/languages' );
	}

	/**
	 * Activation: registers the taxonomy and stores the version.
	 */
	public static function activate() {
		Taxonomy::register();
		update_option( self::VERSION_OPTION, MLAB_MF_VERSION, false );
	}

	/**
	 * Runs upgrade routines when the stored version differs from the code version.
	 */
	public function maybe_upgrade() {
		$installed = get_option( self::VERSION_OPTION, '0' );
		if ( version_compare( $installed, MLAB_MF_VERSION, '>=' ) ) {
			return;
		}

		/*
		 * Future migrations go here, for example:
		 * if ( version_compare( $installed, '1.1.0', '<' ) ) { ... }
		 */

		update_option( self::VERSION_OPTION, MLAB_MF_VERSION, false );
	}

	/**
	 * Capability needed to see folders and to move files.
	 *
	 * @return string
	 */
	public static function use_capability() {
		/**
		 * Filters the capability required to use media folders.
		 *
		 * @param string $capability Default 'upload_files'.
		 */
		return (string) apply_filters( 'mlab_media_folders_use_capability', 'upload_files' );
	}

	/**
	 * Capability needed to create, rename, move and delete folders.
	 *
	 * @return string
	 */
	public static function manage_capability() {
		/**
		 * Filters the capability required to create, rename and delete folders.
		 *
		 * @param string $capability Default 'upload_files'.
		 */
		return (string) apply_filters( 'mlab_media_folders_manage_capability', 'upload_files' );
	}
}
