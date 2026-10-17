<?php
/**
 * Admin integration: assets, upload into folder, folder field on attachments.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the media library screens and media dialogs.
 */
final class Admin {

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_media_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_library_assets' ) );
		add_action( 'add_attachment', array( $this, 'assign_uploaded_attachment' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_folder_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_folder_field' ), 10, 2 );
	}

	/**
	 * Asset version: plugin version plus file time, so caches update on deploy.
	 *
	 * @param string $relative Path relative to the plugin directory.
	 * @return string
	 */
	private function asset_version( $relative ) {
		$mtime = @filemtime( MLAB_MEDIA_FOLDER_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return MLAB_MEDIA_FOLDER_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	/**
	 * Data shared with both scripts.
	 *
	 * @return array
	 */
	private function script_data() {
		return array(
			'version'   => MLAB_MEDIA_FOLDER_VERSION,
			'creditUrl' => 'https://momentumlab.ch/',
			'restPath'  => '/' . Rest_Controller::NAMESPACE,
			'param'     => Query::PARAM,
			'unfiled'   => Query::UNFILED,
			'canManage' => current_user_can( Plugin::manage_capability() ),
			'folders'   => Folders::flattened_tree(),
			'totals'    => Folders::totals(),
			'i18n'      => array(
				'title'           => __( 'Folders', 'mlab-media-folder' ),
				'all'             => __( 'All files', 'mlab-media-folder' ),
				'unfiled'         => __( 'Unfiled', 'mlab-media-folder' ),
				'newFolder'       => __( 'New folder', 'mlab-media-folder' ),
				'newSubfolder'    => __( 'New subfolder', 'mlab-media-folder' ),
				'rename'          => __( 'Rename', 'mlab-media-folder' ),
				'delete'          => __( 'Delete', 'mlab-media-folder' ),
				'save'            => __( 'Save', 'mlab-media-folder' ),
				'cancel'          => __( 'Cancel', 'mlab-media-folder' ),
				'namePlaceholder' => __( 'Folder name', 'mlab-media-folder' ),
				/* translators: %s: folder name. */
				'confirmDelete'   => __( 'Delete the folder "%s"? Its files and subfolders move up one level. No files are deleted.', 'mlab-media-folder' ),
				/* translators: %d: number of files. */
				'moved'           => __( '%d file(s) moved.', 'mlab-media-folder' ),
				'dropHint'        => __( 'Drag files onto a folder to move them.', 'mlab-media-folder' ),
				'error'           => __( 'That did not work. Please try again.', 'mlab-media-folder' ),
				'toggle'          => __( 'Show or hide subfolders', 'mlab-media-folder' ),
				'actions'         => __( 'Folder actions', 'mlab-media-folder' ),
				'filterLabel'     => __( 'Filter by folder', 'mlab-media-folder' ),
				'credit'          => __( 'Media Folder by MomentumLab', 'mlab-media-folder' ),
				'collapse'        => __( 'Collapse folders', 'mlab-media-folder' ),
				'expand'          => __( 'Show folders', 'mlab-media-folder' ),
				'resize'          => __( 'Resize folder panel (double-click to reset)', 'mlab-media-folder' ),
				'selectionBar'    => __( 'Selected files', 'mlab-media-folder' ),
				/* translators: %d: number of selected files. */
				'selected'        => __( '%d selected', 'mlab-media-folder' ),
				'selectAll'       => __( 'Select all', 'mlab-media-folder' ),
				'selectNone'      => __( 'Clear selection', 'mlab-media-folder' ),
				'loadingAll'      => __( 'Loading all files…', 'mlab-media-folder' ),
				'sweepHint'       => __( 'Hold the mouse button and drag across the files to select several at once.', 'mlab-media-folder' ),
			),
		);
	}

	/**
	 * Folder filter in every media dialog (editor, gallery, featured image).
	 */
	public function enqueue_media_assets() {
		if ( ! current_user_can( Plugin::use_capability() ) ) {
			return;
		}
		wp_enqueue_style( 'mlab-media-folder', MLAB_MEDIA_FOLDER_URL . 'assets/css/media-folder.css', array(), $this->asset_version( 'assets/css/media-folder.css' ) );
		wp_enqueue_script( 'mlab-media-folder-modal', MLAB_MEDIA_FOLDER_URL . 'assets/js/media-modal.js', array( 'jquery', 'media-views' ), $this->asset_version( 'assets/js/media-modal.js' ), true );
		wp_localize_script( 'mlab-media-folder-modal', 'mlabMediaFolder', $this->script_data() );
	}

	/**
	 * Folder tree sidebar on the media library screen (grid and list mode).
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_library_assets( $hook_suffix ) {
		if ( 'upload.php' !== $hook_suffix || ! current_user_can( Plugin::use_capability() ) ) {
			return;
		}
		$data         = $this->script_data();
		$mode         = get_user_option( 'media_library_mode', get_current_user_id() );
		$data['mode'] = 'list' === $mode ? 'list' : 'grid';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		if ( isset( $_GET['mode'] ) && in_array( $_GET['mode'], array( 'grid', 'list' ), true ) ) {
			$data['mode'] = sanitize_key( $_GET['mode'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in parse().
		$data['current'] = isset( $_GET[ Query::PARAM ] ) ? Query::parse( wp_unslash( $_GET[ Query::PARAM ] ) ) : '';

		wp_enqueue_style( 'mlab-media-folder', MLAB_MEDIA_FOLDER_URL . 'assets/css/media-folder.css', array(), $this->asset_version( 'assets/css/media-folder.css' ) );
		wp_enqueue_script( 'mlab-media-folder-library', MLAB_MEDIA_FOLDER_URL . 'assets/js/media-library.js', array( 'jquery', 'wp-api-fetch' ), $this->asset_version( 'assets/js/media-library.js' ), true );
		wp_localize_script( 'mlab-media-folder-library', 'mlabMediaFolderLibrary', $data );
	}

	/**
	 * Puts a new upload into the folder that was open in the media library.
	 * Core has already verified the upload nonce ("media-form") at this point.
	 *
	 * @param int $attachment_id New attachment ID.
	 */
	public function assign_uploaded_attachment( $attachment_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by core upload handler.
		if ( ! isset( $_POST[ Query::PARAM ] ) ) {
			return;
		}
		if ( ! current_user_can( Plugin::use_capability() ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in parse().
		$folder = Query::parse( wp_unslash( $_POST[ Query::PARAM ] ) );
		if ( is_int( $folder ) && $folder > 0 ) {
			wp_set_object_terms( (int) $attachment_id, array( $folder ), Taxonomy::NAME, false );
		}
	}

	/**
	 * Adds a folder select to the attachment details.
	 *
	 * @param array    $fields Fields.
	 * @param \WP_Post $post   Attachment.
	 * @return array
	 */
	public function add_folder_field( $fields, $post ) {
		if ( ! current_user_can( Plugin::use_capability() ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}

		$current = Folders::of_attachment( $post->ID );
		$name    = 'attachments[' . (int) $post->ID . '][' . Query::PARAM . ']';
		$id      = 'attachments-' . (int) $post->ID . '-' . Query::PARAM;

		$html  = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">';
		$html .= '<option value="0">' . esc_html__( '— Unfiled —', 'mlab-media-folder' ) . '</option>';
		foreach ( Folders::flattened_tree() as $folder ) {
			$html .= sprintf(
				'<option value="%1$d"%2$s>%3$s%4$s</option>',
				(int) $folder['id'],
				selected( $current, $folder['id'], false ),
				str_repeat( '&nbsp;&nbsp;&nbsp;', (int) $folder['depth'] ),
				esc_html( $folder['name'] )
			);
		}
		$html .= '</select>';

		$fields[ Query::PARAM ] = array(
			'label' => __( 'Folder', 'mlab-media-folder' ),
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}

	/**
	 * Saves the folder select. Core verifies the nonce of the edit request.
	 *
	 * @param array $post       Post data.
	 * @param array $attachment Submitted attachment fields.
	 * @return array
	 */
	public function save_folder_field( $post, $attachment ) {
		if ( ! isset( $attachment[ Query::PARAM ] ) || empty( $post['ID'] ) ) {
			return $post;
		}
		$attachment_id = (int) $post['ID'];
		if ( ! current_user_can( Plugin::use_capability() ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return $post;
		}
		$folder = absint( $attachment[ Query::PARAM ] );
		if ( 0 === $folder || Folders::exists( $folder ) ) {
			Folders::assign( array( $attachment_id ), $folder );
		}
		return $post;
	}
}
