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
		add_action( 'admin_notices', array( $this, 'audit_notice' ) );
		add_action( 'admin_post_mlab_media_folder_rescan', array( $this, 'rescan' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_library_assets' ) );
		add_action( 'add_attachment', array( $this, 'assign_uploaded_attachment' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_folder_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_origin_field' ), 11, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_folder_field' ), 10, 2 );
	}

	/**
	 * Read-only audit progress in the media library; no unrelated admin notices.
	 */
	public function audit_notice() {
		global $pagenow;
		if ( 'upload.php' !== $pagenow || ! current_user_can( Plugin::use_capability() ) ) {
			return;
		}
		$files   = Background_Audit::status();
		$content = Content_Audit::status();
		$checked = absint( $files['checked'] );
		$missing = count( (array) $files['missing'] );
		if ( ! empty( $files['complete'] ) ) {
			/* translators: 1: number checked, 2: missing files. */
			$file_label = sprintf( __( 'Files checked: %1$d · Missing: %2$d', 'mlab-media-folder' ), $checked, $missing );
		} else {
			/* translators: number checked so far. */
			$file_label = sprintf( __( 'File audit running: %d checked so far', 'mlab-media-folder' ), $checked );
		}

		if ( ! empty( $content['complete'] ) ) {
			/* translators: number fingerprinted. */
			$content_label = sprintf( __( 'Duplicate-content audit complete: %d checked', 'mlab-media-folder' ), absint( $content['checked'] ) );
		} else {
			/* translators: number fingerprinted so far. */
			$content_label = sprintf( __( 'Duplicate-content audit running: %d checked so far', 'mlab-media-folder' ), absint( $content['checked'] ) );
		}
		echo '<div class="notice notice-info mlab-media-folder-audit-notice"><p><strong>' . esc_html__( 'Media health', 'mlab-media-folder' ) . '</strong><br>' . esc_html( $file_label ) . '<br>' . esc_html( $content_label ) . '</p>';
		if ( current_user_can( Plugin::audit_capability() ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=mlab_media_folder_rescan' ), 'mlab_media_folder_rescan' );
			echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Scan again', 'mlab-media-folder' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Restarts both read-only health audits from the Media Library.
	 */
	public function rescan() {
		if ( ! current_user_can( Plugin::manage_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to run the media audit.', 'mlab-media-folder' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mlab_media_folder_rescan' );
		Background_Audit::restart_scan();
		Content_Audit::force_restart();
		wp_safe_redirect( admin_url( 'upload.php' ) );
		exit;
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
			'version'    => MLAB_MEDIA_FOLDER_VERSION,
			'creditUrl'  => 'https://momentumlab.ch/',
			'restPath'   => '/' . Rest_Controller::NAMESPACE,
			'param'      => Query::PARAM,
			'cleanParam' => Query::CLEAN,
			'unfiled'    => Query::UNFILED,
			'canManage'  => current_user_can( Plugin::manage_capability() ),
			'maxAssign'  => Folders::MAX_ASSIGN,
			'folders'    => Folders::flattened_tree(),
			'totals'     => Folders::totals(),
			'i18n'       => array(
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
				'dropHint'        => __( 'Drag files onto a folder to move them.', 'mlab-media-folder' ),
				'error'           => __( 'That did not work. Check your connection and try again.', 'mlab-media-folder' ),
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
				'library'         => __( 'Library', 'mlab-media-folder' ),
				'todo'            => __( 'To do', 'mlab-media-folder' ),
				'noAlt'           => __( 'No alt text', 'mlab-media-folder' ),
				'unattached'      => __( 'Not in a page', 'mlab-media-folder' ),
				/* translators: 1: number of images shown, 2: number of entries in the library. */
				'countNote'       => __( '%1$d images shown, %2$d entries in the library. The library shows each file once and hides entries without a file. See "Duplicates" and "File missing".', 'mlab-media-folder' ),
				'duplicate'       => __( 'Duplicates', 'mlab-media-folder' ),
				'missing'         => __( 'File missing', 'mlab-media-folder' ),
				/* translators: %d: number of entries. */
				'nextDuplicate'   => __( 'Check %d entries that share a file with another entry.', 'mlab-media-folder' ),
				/* translators: %d: number of entries. */
				'nextMissing'     => __( 'Fix %d entries whose file is missing.', 'mlab-media-folder' ),
				'foundDuplicate'  => __( 'Entries that point to the same physical file or have identical file contents. The background audit fingerprints files with SHA-256 so copies with different names can also be found.', 'mlab-media-folder' ),
				'doDuplicate'     => __( 'Open each entry and check "Origin" to see where it is used. Keep one, then delete the others. A file that another entry still uses is never removed from the server.', 'mlab-media-folder' ),
				'foundMissing'    => __( 'Entries in the library whose file is no longer in the uploads folder. They show a broken preview. Files stored outside this server are not checked.', 'mlab-media-folder' ),
				'doMissing'       => __( 'Upload the file again and replace the entry, or delete the entry if you do not need it.', 'mlab-media-folder' ),
				'nextStep'        => __( 'Next step', 'mlab-media-folder' ),
				'open'            => __( 'Open', 'mlab-media-folder' ),
				'allDone'         => __( 'Everything is in order. New uploads show up here when they need attention.', 'mlab-media-folder' ),
				/* translators: %d: number of files. */
				'nextUnfiled'     => __( 'Sort %d files into folders.', 'mlab-media-folder' ),
				/* translators: %d: number of images. */
				'nextNoAlt'       => __( 'Describe %d images.', 'mlab-media-folder' ),
				/* translators: %d: number of files. */
				'nextUnattached'  => __( 'Review %d files that are not in a page.', 'mlab-media-folder' ),
				'foundLabel'      => __( 'Found', 'mlab-media-folder' ),
				'doLabel'         => __( 'What to do', 'mlab-media-folder' ),
				'foundUnfiled'    => __( 'Files in the library that are in no folder, for example new uploads.', 'mlab-media-folder' ),
				'doUnfiled'       => __( 'Drag them onto a folder, or select them (Bulk select) and use "Move to…".', 'mlab-media-folder' ),
				'foundNoAlt'      => __( 'Images whose alternative text is empty. Screen readers and search engines depend on it.', 'mlab-media-folder' ),
				'doNoAlt'         => __( 'Click an image and fill in "Alternative Text" in the details.', 'mlab-media-folder' ),
				'foundUnattached' => __( 'Files that were not uploaded from a page or post. Some of them may be unused.', 'mlab-media-folder' ),
				'doUnattached'    => __( 'Check each file: delete it if you do not need it, otherwise move it to a folder.', 'mlab-media-folder' ),
				'taskEmpty'       => __( 'Nothing to do here. Well done.', 'mlab-media-folder' ),
				'showAll'         => __( 'Show all files', 'mlab-media-folder' ),
				'moveTo'          => __( 'Move to…', 'mlab-media-folder' ),
				'moveSelected'    => __( 'Move selected files to a folder', 'mlab-media-folder' ),
				/* translators: 1: number of files, 2: folder name. */
				'movedTo'         => __( '%1$d file(s) moved to "%2$s".', 'mlab-media-folder' ),
				'undo'            => __( 'Undo', 'mlab-media-folder' ),
				'undone'          => __( 'Move undone.', 'mlab-media-folder' ),
				/* translators: %d: number of files. */
				'skipped'         => __( '%d file(s) skipped: you are not allowed to edit them.', 'mlab-media-folder' ),
				'searchFolders'   => __( 'Search folders', 'mlab-media-folder' ),
				'noMatch'         => __( 'No folder found.', 'mlab-media-folder' ),
				'noFolders'       => __( 'No folders yet. Create one to start sorting your files.', 'mlab-media-folder' ),
				'deleteFolder'    => __( 'Delete folder', 'mlab-media-folder' ),
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
	 * Read-only "Origin" field: who uploaded the file, where it is used, in
	 * which folder it is, and what still has to be done for it.
	 *
	 * @param array    $fields Fields.
	 * @param \WP_Post $post   Attachment.
	 * @return array
	 */
	public function add_origin_field( $fields, $post ) {
		if ( ! current_user_can( Plugin::use_capability() ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}

		$rows   = array();
		$author = get_the_author_meta( 'display_name', (int) $post->post_author );
		$rows[] = sprintf(
			/* translators: 1: user name, 2: date. */
			esc_html__( 'Uploaded by %1$s on %2$s', 'mlab-media-folder' ),
			esc_html( $author ? $author : '—' ),
			esc_html( get_the_date( '', $post ) )
		);

		$rows = array_merge( $rows, $this->usage_rows( $post ) );

		$path   = Folders::path( Folders::of_attachment( $post->ID ) );
		$rows[] = $path
			? esc_html__( 'Folder', 'mlab-media-folder' ) . ': ' . esc_html( implode( ' › ', $path ) )
			: esc_html__( 'Unfiled', 'mlab-media-folder' );

		$todo = array();
		$todo = array_merge( $todo, $this->health_rows( $post, $rows ) );
		if ( ! $path ) {
			$todo[] = esc_html__( 'Choose a folder', 'mlab-media-folder' );
		}
		if ( wp_attachment_is_image( $post ) && '' === trim( (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) ) ) {
			$todo[] = esc_html__( 'Add alternative text', 'mlab-media-folder' );
		}

		$html = '<div class="mlab-media-folder-origin"><ul><li>' . implode( '</li><li>', $rows ) . '</li></ul>';
		if ( $todo ) {
			$html .= '<p class="mlab-media-folder-origin-todo"><strong>' . esc_html__( 'To do', 'mlab-media-folder' ) . ':</strong> ' . implode( ', ', $todo ) . '</p>';
		}
		$html .= '</div>';

		$fields['mlab_origin'] = array(
			'label' => __( 'Origin', 'mlab-media-folder' ),
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}

	/**
	 * Duplicate and missing-file notes for one entry. Adds the other entries
	 * that share the file to the origin rows and returns the open tasks.
	 *
	 * @param \WP_Post $post Attachment.
	 * @param array    $rows  Origin rows (HTML), extended by reference.
	 * @return string[] Open tasks (HTML, escaped).
	 */
	private function health_rows( $post, array &$rows ) {
		$todo   = array();
		$health = Health::scan();

		if ( in_array( (int) $post->ID, $health['duplicate'], true ) ) {
			$other_ids = array_values(
				array_unique(
					array_merge(
						Health::others_sharing_file( $post->ID ),
						Health::others_sharing_content( $post->ID )
					)
				)
			);
			$others    = array_filter( array_map( 'get_post', $other_ids ) );
			if ( $others ) {
				$rows[] = esc_html__( 'Same file or content as', 'mlab-media-folder' ) . ': ' . implode( ', ', array_map( array( $this, 'attachment_link' ), $others ) );
			}
			$todo[] = esc_html__( 'Duplicate: compare and keep the correct entry', 'mlab-media-folder' );
		}
		if ( in_array( (int) $post->ID, $health['missing'], true ) ) {
			$todo[] = esc_html__( 'File is missing on the server', 'mlab-media-folder' );
		}
		return $todo;
	}

	/**
	 * Escaped link to the edit screen of an attachment.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return string
	 */
	private function attachment_link( $post ) {
		$title = get_the_title( $post );
		$title = '' === $title ? '#' . $post->ID : $title . ' (#' . $post->ID . ')';
		$url   = current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : '';
		return $url
			? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a>'
			: esc_html( $title );
	}

	/**
	 * Where a file was uploaded to and where it is the featured image.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return string[] HTML rows (already escaped).
	 */
	private function usage_rows( $post ) {
		$rows = array();

		$parent = $post->post_parent ? get_post( $post->post_parent ) : null;
		if ( $parent ) {
			$rows[] = esc_html__( 'Uploaded to', 'mlab-media-folder' ) . ': ' . $this->post_link( $parent );
		} else {
			$rows[] = esc_html__( 'Not uploaded to a page or post', 'mlab-media-folder' );
		}

		$featured = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'numberposts'      => 3,
				'meta_key'         => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => (int) $post->ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		);
		if ( $featured ) {
			$links  = array_map( array( $this, 'post_link' ), $featured );
			$rows[] = esc_html__( 'Featured image of', 'mlab-media-folder' ) . ': ' . implode( ', ', $links );
		}
		return $rows;
	}

	/**
	 * Escaped link to the editor of a post, or its plain title.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function post_link( $post ) {
		$title = get_the_title( $post );
		$title = '' === $title ? __( '(no title)', 'mlab-media-folder' ) : $title;
		$url   = current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : '';
		return $url
			? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a>'
			: esc_html( $title );
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
