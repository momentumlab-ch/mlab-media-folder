<?php
/**
 * Free/Pro feature gates.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Central feature availability. Existing 2.x features deliberately remain free.
 */
final class Features {

	const PRO_PRODUCT = 'mlab-media-folder-pro';

	/**
	 * Whether the current site owns the Pro unlock.
	 */
	public static function is_pro(): bool {
		$unlocked = false;

		if ( function_exists( 'mlab_core_product_unlocked' ) ) {
			$unlocked = (bool) mlab_core_product_unlocked( self::PRO_PRODUCT );
		} elseif ( function_exists( 'mlab_license' ) ) {
			$license = mlab_license();
			if ( is_object( $license ) && method_exists( $license, 'unlocked' ) ) {
				$unlocked = (bool) $license->unlocked( self::PRO_PRODUCT );
			}
		}

		/**
		 * Filters Pro availability, primarily for custom licensing and automated tests.
		 *
		 * @param bool   $unlocked Current answer.
		 * @param string $product  License-server product slug.
		 */
		return (bool) apply_filters( 'mlab_media_folder_pro_unlocked', $unlocked, self::PRO_PRODUCT );
	}

	/**
	 * Whether a named feature is available.
	 *
	 * @param string $feature Feature key.
	 */
	public static function available( string $feature ): bool {
		$free = array(
			'folders',
			'bulk_workflow',
			'global_search',
			'inspector',
			'media_health',
			'migration',
			'mobile_workspace',
			'smart_views_core',
		);
		$pro  = array(
			'ai_metadata',
			'glossary',
			'naming_rules',
			'folder_permissions',
			'dynamic_views',
			'advanced_automation',
			'advanced_folder_style',
		);

		$available = in_array( $feature, $free, true )
			|| ( in_array( $feature, $pro, true ) && self::is_pro() );

		/**
		 * Filters one feature gate.
		 *
		 * @param bool   $available Current answer.
		 * @param string $feature   Feature key.
		 * @param bool   $is_pro    Whether Pro is unlocked.
		 */
		return (bool) apply_filters( 'mlab_media_folder_feature_available', $available, $feature, self::is_pro() );
	}

	/**
	 * Feature matrix exposed to the admin UI.
	 *
	 * @return array<string,array{tier:string,available:bool}>
	 */
	public static function matrix(): array {
		$matrix = array(
			'folders'               => 'free',
			'bulk_workflow'         => 'free',
			'global_search'         => 'free',
			'inspector'             => 'free',
			'media_health'          => 'free',
			'migration'             => 'free',
			'mobile_workspace'      => 'free',
			'smart_views_core'      => 'free',
			'ai_metadata'           => 'pro',
			'glossary'              => 'pro',
			'naming_rules'          => 'pro',
			'folder_permissions'    => 'pro',
			'dynamic_views'         => 'pro',
			'advanced_automation'   => 'pro',
			'advanced_folder_style' => 'pro',
		);

		$out = array();
		foreach ( $matrix as $feature => $tier ) {
			$out[ $feature ] = array(
				'tier'      => $tier,
				'available' => self::available( $feature ),
			);
		}
		return $out;
	}
}
