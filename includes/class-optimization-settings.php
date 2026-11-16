<?php
/**
 * Image optimisation settings.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Stores upload optimisation preferences.
 */
final class Optimization_Settings {

	const OPTION = 'mlab_media_folder_optimization';

	/**
	 * Defaults are deliberately non-destructive.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'        => false,
			'max_dimension'  => 2560,
			'format'         => 'webp',
			'convert_png'    => false,
			'jpeg_quality'   => 82,
			'webp_quality'   => 82,
			'avif_quality'   => 72,
			'keep_original'  => true,
			'exif_policy'    => 'keep',
		);
	}

	/**
	 * Get normalized settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$value = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $value ) ? $value : array() );
	}

	/**
	 * Save settings from the admin page.
	 *
	 * @param array<string,mixed> $input Raw input.
	 */
	public static function save( array $input ): void {
		$current = self::get();
		$next    = $current;

		$next['enabled']       = ! empty( $input['enabled'] );
		$next['max_dimension'] = max( 1280, min( 7680, absint( $input['max_dimension'] ?? $current['max_dimension'] ) ) );

		$format = sanitize_key( (string) ( $input['format'] ?? $current['format'] ) );
		$allowed_formats = Features::is_pro() ? array( 'original', 'webp', 'avif' ) : array( 'original', 'webp' );
		$next['format'] = in_array( $format, $allowed_formats, true ) ? $format : 'webp';

		$next['convert_png']  = ! empty( $input['convert_png'] );
		$next['jpeg_quality'] = max( 50, min( 100, absint( $input['jpeg_quality'] ?? $current['jpeg_quality'] ) ) );
		$next['webp_quality'] = max( 50, min( 100, absint( $input['webp_quality'] ?? $current['webp_quality'] ) ) );
		$next['avif_quality'] = max( 40, min( 95, absint( $input['avif_quality'] ?? $current['avif_quality'] ) ) );

		// 3.0 intentionally keeps originals. Destructive replacement arrives only behind a later explicit migration-safe workflow.
		$next['keep_original'] = true;

		if ( Features::is_pro() ) {
			$policy = sanitize_key( (string) ( $input['exif_policy'] ?? $current['exif_policy'] ) );
			$next['exif_policy'] = in_array( $policy, array( 'keep', 'strip_gps', 'strip_all' ), true ) ? $policy : 'keep';
		}

		update_option( self::OPTION, $next, false );
	}
}
