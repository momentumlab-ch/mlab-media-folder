<?php
/**
 * Updates from GitHub releases.
 *
 * The plugin header sets "Update URI" to the GitHub repository, so WordPress
 * asks this class (through the update_plugins_github.com filter) instead of
 * WordPress.org whether a newer version exists.
 *
 * @package MomentumLab\MediaFolders
 */

namespace MomentumLab\MediaFolders;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the latest GitHub release and offers it as a plugin update.
 */
final class Updater {

	/**
	 * Default GitHub repository (owner/name).
	 */
	const REPOSITORY = 'momentumlab-ch/wp-media-folder';

	/**
	 * Release assets are named {ASSET_NAME}-{version}.zip.
	 */
	const ASSET_NAME = 'mlab-media-folders';

	/**
	 * Site transient that caches the latest release.
	 */
	const CACHE_KEY = 'mlab_mf_github_release';

	/**
	 * Cache lifetime after a successful check.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Cache lifetime after a failed check, so GitHub is not asked on every page load.
	 */
	const ERROR_TTL = HOUR_IN_SECONDS;

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_filter( 'update_plugins_github.com', array( $this, 'check_update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ) );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cache' ) );
		// Before core's wp_update_plugins() on the same hook (priority 10).
		add_action( 'load-update-core.php', array( $this, 'maybe_force_check' ), 5 );
	}

	/**
	 * Drops the cached release when the user clicks "Check again" under Dashboard > Updates.
	 */
	public function maybe_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core's own link carries no nonce; this only clears a cache.
		if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			$this->clear_cache();
		}
	}

	/**
	 * Supplies update data for this plugin.
	 *
	 * @param array|false $update      Update data from earlier filters.
	 * @param array       $plugin_data Plugin header data.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public function check_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( MLAB_MF_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			/*
			 * No release found (none published yet, or GitHub unreachable). Report the
			 * installed version so WordPress lists the plugin under "no_update": only
			 * plugins it knows an update source for get the auto-update toggle.
			 */
			$release = array(
				'version' => $plugin_data['Version'],
				'url'     => 'https://github.com/' . $this->repository(),
				'package' => '',
			);
		}

		return array(
			'id'      => $plugin_data['UpdateURI'],
			'slug'    => $this->slug(),
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
	}

	/**
	 * Fills the "View details" dialog for this plugin.
	 *
	 * @param false|object|array $result The result so far.
	 * @param string             $action The API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $this->slug() !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $result;
		}

		$plugin = get_plugin_data( MLAB_MF_FILE, false, false );

		return (object) array(
			'name'          => $plugin['Name'],
			'slug'          => $this->slug(),
			'version'       => $release['version'],
			'author'        => $plugin['Author'],
			'homepage'      => 'https://github.com/' . $this->repository(),
			'requires'      => $plugin['RequiresWP'],
			'requires_php'  => $plugin['RequiresPHP'],
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '<p>' . esc_html( $plugin['Description'] ) . '</p>',
				'changelog'   => $this->markdown_to_html( $release['notes'] ),
			),
		);
	}

	/**
	 * Keeps the installed folder name when the archive uses a different one,
	 * so the plugin stays active after the update.
	 *
	 * @param string       $source        Unpacked source directory.
	 * @param string       $remote_source Parent directory of the source.
	 * @param \WP_Upgrader $upgrader      Upgrader instance.
	 * @param array        $hook_extra    Extra data, contains the plugin basename.
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || plugin_basename( MLAB_MF_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . $this->slug() . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}

		if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $wanted ) ) {
			return new \WP_Error( 'mlab_mf_update_rename', __( 'The update folder could not be renamed.', 'mlab-media-folders' ) );
		}

		return $wanted;
	}

	/**
	 * Deletes the cached release.
	 */
	public function clear_cache() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Returns the latest release with a plugin archive, cached.
	 *
	 * @return array|null Keys: version, url, package, notes, published.
	 */
	private function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$release = $this->fetch_release();
		set_site_transient( self::CACHE_KEY, $release ? $release : array(), $release ? self::CACHE_TTL : self::ERROR_TTL );

		return $release;
	}

	/**
	 * Asks the GitHub API for the latest published release.
	 *
	 * @return array|null
	 */
	private function fetch_release() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repository() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'MomentumLab-Media-Folders/' . MLAB_MF_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}

		// Only the built plugin archive is accepted, never GitHub's source zipball.
		$package = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET_NAME . '-' . $version . '.zip' === $asset['name'] ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package || 0 !== strpos( $package, 'https://github.com/' ) ) {
			return null;
		}

		return array(
			'version'   => $version,
			'url'       => esc_url_raw( (string) ( $data['html_url'] ?? '' ) ),
			'package'   => esc_url_raw( $package ),
			'notes'     => (string) ( $data['body'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
	}

	/**
	 * Turns the release notes (headings and bullet lists) into safe HTML.
	 *
	 * @param string $markdown Release notes.
	 * @return string
	 */
	private function markdown_to_html( $markdown ) {
		$html    = '';
		$in_list = false;

		foreach ( preg_split( '/\R/', $markdown ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^[-*]\s+(.*)$/', $line, $m ) ) {
				$html   .= ( $in_list ? '' : '<ul>' ) . '<li>' . esc_html( $m[1] ) . '</li>';
				$in_list = true;
				continue;
			}
			if ( $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}
			if ( preg_match( '/^#{1,6}\s+(.*)$/', $line, $m ) ) {
				$html .= '<h4>' . esc_html( $m[1] ) . '</h4>';
			} elseif ( '' !== $line ) {
				$html .= '<p>' . esc_html( $line ) . '</p>';
			}
		}

		return $in_list ? $html . '</ul>' : $html;
	}

	/**
	 * Plugin folder name, also used as the slug.
	 *
	 * @return string
	 */
	private function slug() {
		return dirname( plugin_basename( MLAB_MF_FILE ) );
	}

	/**
	 * GitHub repository to check, as owner/name.
	 *
	 * @return string
	 */
	private function repository() {
		/**
		 * Filters the GitHub repository that is checked for updates.
		 *
		 * @param string $repository Default 'momentumlab-ch/wp-media-folder'.
		 */
		return (string) apply_filters( 'mlab_media_folders_update_repository', self::REPOSITORY );
	}
}
