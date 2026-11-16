<?php
/**
 * Background AI automation for new uploads.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Runs optional AI enrichment outside the upload request.
 */
final class Automation_Queue {

	const HOOK = 'mlab_media_folder_ai_enrich';

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'add_attachment', array( $this, 'schedule' ), 40 );
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedule AI enrichment for one new image when explicitly enabled.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function schedule( int $attachment_id ): void {
		if ( ! Features::available( 'advanced_automation' ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$settings = Automation_Settings::get();
		if ( empty( $settings['auto_alt'] ) && empty( $settings['auto_tags'] ) ) {
			return;
		}
		if ( 'none' === Automation_Settings::api_key_source() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK, array( $attachment_id ) ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( $attachment_id ) );
		}
	}

	/**
	 * Generate and apply only the fields the administrator enabled.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function run( int $attachment_id ): void {
		if ( ! Features::available( 'advanced_automation' ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			return;
		}

		$settings   = Automation_Settings::get();
		$suggestion = AI::suggest( $attachment_id );
		if ( is_wp_error( $suggestion ) ) {
			update_post_meta( $attachment_id, '_mlab_media_folder_ai_error', sanitize_text_field( $suggestion->get_error_message() ) );
			return;
		}

		$result = AI::apply(
			$attachment_id,
			$suggestion,
			! empty( $settings['auto_alt'] ),
			! empty( $settings['auto_tags'] ),
			false
		);

		if ( is_wp_error( $result ) ) {
			update_post_meta( $attachment_id, '_mlab_media_folder_ai_error', sanitize_text_field( $result->get_error_message() ) );
			return;
		}

		delete_post_meta( $attachment_id, '_mlab_media_folder_ai_error' );
		update_post_meta( $attachment_id, '_mlab_media_folder_ai_processed', time() );
	}
}
