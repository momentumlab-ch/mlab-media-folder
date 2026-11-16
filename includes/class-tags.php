<?php
/**
 * Private media tags used by AI and Smart Views.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

final class Tags {

	const NAME = 'mlab_media_tag';

	/**
	 * Register the non-public attachment taxonomy.
	 */
	public static function register(): void {
		register_taxonomy(
			self::NAME,
			'attachment',
			array(
				'public'            => false,
				'show_ui'           => false,
				'show_in_rest'      => false,
				'hierarchical'      => false,
				'query_var'         => false,
				'rewrite'           => false,
				'show_admin_column' => false,
			)
		);
	}
}
