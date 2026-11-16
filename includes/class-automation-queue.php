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

	const HOOK        = 'mlab_media_folder_ai_enrich';
	const BULK_HOOK   = 'mlab_media_folder_ai_bulk_batch';
	const BULK_OPTION = 'mlab_media_folder_ai_bulk_queue';
	const BULK_BATCH  = 3;

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'add_attachment', array( $this, 'schedule' ), 40 );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::BULK_HOOK, array( $this, 'run_bulk' ) );
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
	 * Add explicitly selected attachments to the background AI queue.
	 *
	 * @param int[] $ids Attachment IDs already permission-checked by the REST layer.
	 * @return int Number queued.
	 */
	public static function schedule_bulk( array $ids ): int {
		if ( ! Features::available( 'ai_metadata' ) || 'none' === Automation_Settings::api_key_source() ) {
			return 0;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return 0;
		}

		$state = get_option( self::BULK_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$queue = isset( $state['ids'] ) && is_array( $state['ids'] ) ? array_map( 'absint', $state['ids'] ) : array();
		$queue = array_values( array_unique( array_merge( $queue, $ids ) ) );

		update_option(
			self::BULK_OPTION,
			array(
				'ids'       => $queue,
				'processed' => absint( $state['processed'] ?? 0 ),
				'failed'    => absint( $state['failed'] ?? 0 ),
				'updated'   => time(),
			),
			false
		);

		if ( ! wp_next_scheduled( self::BULK_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BULK_HOOK );
		}
		return count( $ids );
	}

	/**
	 * Process a few explicitly queued attachments per cron request.
	 */
	public function run_bulk(): void {
		$state = get_option( self::BULK_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['ids'] ) || ! is_array( $state['ids'] ) ) {
			delete_option( self::BULK_OPTION );
			return;
		}

		$queue = array_values( array_filter( array_map( 'absint', $state['ids'] ) ) );
		$batch = array_splice( $queue, 0, self::BULK_BATCH );

		foreach ( $batch as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
				++$state['failed'];
				continue;
			}

			$suggestion = AI::suggest( $attachment_id );
			if ( is_wp_error( $suggestion ) ) {
				++$state['failed'];
				update_post_meta( $attachment_id, '_mlab_media_folder_ai_error', sanitize_text_field( $suggestion->get_error_message() ) );
				continue;
			}

			$write_alt = '' === trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
			$result    = AI::apply( $attachment_id, $suggestion, $write_alt, true, false );
			if ( is_wp_error( $result ) ) {
				++$state['failed'];
				update_post_meta( $attachment_id, '_mlab_media_folder_ai_error', sanitize_text_field( $result->get_error_message() ) );
				continue;
			}

			++$state['processed'];
			delete_post_meta( $attachment_id, '_mlab_media_folder_ai_error' );
			update_post_meta( $attachment_id, '_mlab_media_folder_ai_processed', time() );
		}

		$state['ids']     = $queue;
		$state['updated'] = time();

		if ( $queue ) {
			update_option( self::BULK_OPTION, $state, false );
			if ( ! wp_next_scheduled( self::BULK_HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BULK_HOOK );
			}
		} else {
			delete_option( self::BULK_OPTION );
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
