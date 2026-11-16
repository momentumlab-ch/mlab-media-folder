<?php
/**
 * Safe naming rules for new uploads.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Changes only the incoming filename before WordPress stores a new upload.
 *
 * Existing attachments and URLs are never renamed.
 */
final class Upload_Naming {

	/**
	 * Register upload filter.
	 */
	public function hooks(): void {
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'rename_new_upload' ), 20 );
	}

	/**
	 * Apply the configured Pro naming pattern to a new upload.
	 *
	 * @param array<string,mixed> $file Upload payload.
	 * @return array<string,mixed>
	 */
	public function rename_new_upload( array $file ): array {
		if ( ! Features::available( 'naming_rules' ) ) {
			return $file;
		}

		$settings = Automation_Settings::get();
		if ( empty( $settings['naming_enabled'] ) || empty( $file['name'] ) ) {
			return $file;
		}

		$original = (string) $file['name'];
		$extension = pathinfo( $original, PATHINFO_EXTENSION );
		$base = pathinfo( $original, PATHINFO_FILENAME );
		$folder_name = '';

		// Folder is used only as naming context; the actual assignment remains in the existing upload workflow.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core validates the media upload request.
		if ( isset( $_POST[ Query::PARAM ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Query::parse validates the value.
			$folder = Query::parse( wp_unslash( $_POST[ Query::PARAM ] ) );
			if ( is_int( $folder ) && 0 < $folder ) {
				$path = Folders::path( $folder );
				$folder_name = $path ? end( $path ) : '';
			}
		}

		$user = wp_get_current_user();
		$name = Naming::render(
			(string) $settings['naming_pattern'],
			array(
				'company_name'  => (string) $settings['company_name'],
				'site_name'     => get_bloginfo( 'name' ),
				'image_name'    => $base,
				'original_name' => $base,
				'folder'        => $folder_name,
				'author'        => $user && $user->exists() ? $user->display_name : '',
			)
		);

		$file['name'] = sanitize_file_name( $name . ( '' !== $extension ? '.' . strtolower( $extension ) : '' ) );
		return $file;
	}
}
