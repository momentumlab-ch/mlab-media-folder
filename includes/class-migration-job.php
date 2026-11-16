<?php
/**
 * Resumable background folder migration.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Copies source relationships in bounded WP-Cron batches.
 */
final class Migration_Job {

	const OPTION = 'mlab_media_folder_migration_job';
	const HOOK   = 'mlab_media_folder_migration_batch';

	/**
	 * Register the background worker.
	 */
	public function hooks(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		add_action( 'init', array( $this, 'schedule_if_needed' ), 33 );
	}

	/**
	 * Resume an incomplete migration after missed cron events or reactivation.
	 */
	public function schedule_if_needed(): void {
		$state = self::status();
		if ( $state && empty( $state['complete'] ) && ! empty( $state['taxonomy'] ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Start a fresh migration job.
	 *
	 * @param string $taxonomy Source identifier.
	 * @return true|\WP_Error
	 */
	public static function start( string $taxonomy ) {
		$prepared = Migration::prepare_map( $taxonomy );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		update_option(
			self::OPTION,
			array(
				'taxonomy'    => $taxonomy,
				'map'         => $prepared['map'],
				'created'     => (int) $prepared['created'],
				'cursor'      => 0,
				'checked'     => 0,
				'assignments' => 0,
				'complete'    => false,
				'error'       => '',
				'updated'     => time(),
			),
			false
		);

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
		return true;
	}

	/**
	 * Process one bounded relationship batch.
	 */
	public function run_batch(): void {
		$state = self::status();
		if ( ! $state || ! empty( $state['complete'] ) || empty( $state['taxonomy'] ) ) {
			return;
		}

		$result = Migration::assignment_batch(
			(string) $state['taxonomy'],
			is_array( $state['map'] ) ? $state['map'] : array(),
			absint( $state['cursor'] ),
			100
		);

		if ( is_wp_error( $result ) ) {
			$state['error']    = sanitize_text_field( $result->get_error_message() );
			$state['complete'] = true;
			$state['updated']  = time();
			unset( $state['map'] );
			update_option( self::OPTION, $state, false );
			return;
		}

		$state['cursor']       = (int) $result['cursor'];
		$state['checked']     = (int) $state['checked'] + (int) $result['checked'];
		$state['assignments'] = (int) $state['assignments'] + (int) $result['assignments'];
		$state['complete']    = ! empty( $result['complete'] );
		$state['updated']     = time();
		if ( $state['complete'] ) {
			unset( $state['map'] );
		}
		update_option( self::OPTION, $state, false );

		if ( ! $state['complete'] && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/**
	 * Return the persisted migration state.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}
}
