<?php
/**
 * Safe web image optimisation.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Optimises WordPress-generated image derivatives only.
 *
 * Originals are never renamed, replaced or deleted by this class.
 */
final class Image_Optimization {

	/**
	 * Registers image-editor filters.
	 */
	public function hooks(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'size_threshold' ), 20 );
		add_filter( 'image_editor_output_format', array( $this, 'output_format' ), 20 );
		add_filter( 'wp_editor_set_quality', array( $this, 'quality' ), 20, 2 );
	}

	/**
	 * Maximum source dimension before WordPress creates a scaled derivative.
	 *
	 * @param int|false $threshold Core threshold.
	 * @return int|false
	 */
	public function size_threshold( $threshold ) {
		$settings = Optimization_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return $threshold;
		}

		$max = absint( $settings['max_dimension'] );
		return 0 < $max ? max( 1280, min( 7680, $max ) ) : $threshold;
	}

	/**
	 * Converts generated JPEG/PNG derivatives when the server supports the target format.
	 *
	 * @param array<string,string> $formats Current mapping.
	 * @return array<string,string>
	 */
	public function output_format( array $formats ): array {
		$settings = Optimization_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return $formats;
		}

		$format = (string) $settings['format'];
		if ( 'original' === $format || ! self::supports( $format ) ) {
			return $formats;
		}

		$mime = 'avif' === $format ? 'image/avif' : 'image/webp';
		$formats['image/jpeg'] = $mime;

		if ( ! empty( $settings['convert_png'] ) ) {
			$formats['image/png'] = $mime;
		}

		return $formats;
	}

	/**
	 * Sets output quality for generated images.
	 *
	 * @param int    $quality Current quality.
	 * @param string $mime_type Output MIME type.
	 */
	public function quality( int $quality, string $mime_type ): int {
		$settings = Optimization_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return $quality;
		}

		if ( 'image/avif' === $mime_type ) {
			return max( 40, min( 95, absint( $settings['avif_quality'] ) ) );
		}
		if ( 'image/webp' === $mime_type ) {
			return max( 50, min( 100, absint( $settings['webp_quality'] ) ) );
		}
		if ( 'image/jpeg' === $mime_type ) {
			return max( 50, min( 100, absint( $settings['jpeg_quality'] ) ) );
		}

		return $quality;
	}

	/**
	 * Whether the active WordPress image editor can write a format.
	 */
	public static function supports( string $format ): bool {
		$mime = 'avif' === $format ? 'image/avif' : ( 'webp' === $format ? 'image/webp' : '' );
		if ( '' === $mime ) {
			return true;
		}
		return wp_image_editor_supports( array( 'mime_type' => $mime ) );
	}

	/**
	 * Capability snapshot for the settings UI.
	 *
	 * @return array<string,bool>
	 */
	public static function capabilities(): array {
		return array(
			'webp' => self::supports( 'webp' ),
			'avif' => self::supports( 'avif' ),
		);
	}
}
