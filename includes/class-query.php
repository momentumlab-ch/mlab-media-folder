<?php
/**
 * Filters media queries by folder.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the folder filter to the media grid (Ajax) and the list view.
 */
final class Query {

	/**
	 * Request key used by the media grid, the list view and uploads.
	 */
	const PARAM = 'mlab_folder';

	/**
	 * Request key of the media library screen: list one entry per file.
	 */
	const CLEAN = 'mlab_clean';

	/**
	 * Value for files that are in no folder.
	 */
	const UNFILED = 'none';

	/**
	 * Value for images that have no alternative text.
	 */
	const NO_ALT = 'noalt';

	/**
	 * Value for files that were not uploaded to a page or post.
	 */
	const UNATTACHED = 'unattached';

	/**
	 * Value for entries that share their file with another entry.
	 */
	const DUPLICATE = 'duplicate';

	/**
	 * Value for entries whose file is gone from the uploads folder.
	 */
	const MISSING = 'missing';

	/**
	 * Views that list files needing attention instead of a folder.
	 */
	const TASKS = array( self::UNFILED, self::NO_ALT, self::UNATTACHED, self::DUPLICATE, self::MISSING );

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_ajax_query' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_list_query' ) );
	}

	/**
	 * Normalises a raw folder value: '' (all), a task view ('none', 'noalt',
	 * 'unattached') or a folder ID.
	 *
	 * @param mixed $raw Raw value from the request.
	 * @return string|int
	 */
	public static function parse( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$raw = sanitize_key( (string) $raw );
		if ( in_array( $raw, self::TASKS, true ) ) {
			return $raw;
		}
		if ( str_starts_with( $raw, 'view-' ) && Dynamic_Views::get( substr( $raw, 5 ) ) ) {
			return $raw;
		}
		$id = absint( $raw );
		return ( $id && Folders::exists( $id ) ) ? $id : '';
	}

	/**
	 * Builds the tax_query clause for a parsed folder value.
	 *
	 * @param string|int $folder Parsed folder value.
	 * @return array|null
	 */
	public static function tax_clause( $folder ) {
		if ( self::UNFILED === $folder ) {
			return array(
				'taxonomy' => Taxonomy::NAME,
				'operator' => 'NOT EXISTS',
			);
		}
		if ( is_int( $folder ) && $folder > 0 ) {
			return array(
				'taxonomy'         => Taxonomy::NAME,
				'field'            => 'term_id',
				'terms'            => array( $folder ),
				'include_children' => false,
			);
		}
		return null;
	}

	/**
	 * Applies a parsed folder or task value to WP_Query arguments.
	 *
	 * @param array      $args   WP_Query arguments.
	 * @param string|int $folder Parsed folder value.
	 * @param bool       $clean  Hide entries without a file and extra entries for a file
	 *                           (media library screen only, not the editor dialogs).
	 * @return array
	 */
	public static function apply( array $args, $folder, $clean = false ) {
		if ( $clean && self::DUPLICATE !== $folder && self::MISSING !== $folder && 'trash' !== ( $args['post_status'] ?? '' ) ) {
			$hidden = Health::hidden_ids();
			if ( $hidden ) {
				$args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), $hidden ) ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			}
		}

		if ( is_string( $folder ) && str_starts_with( $folder, 'view-' ) ) {
			return Dynamic_Views::apply( $args, substr( $folder, 5 ) );
		}

		if ( self::UNATTACHED === $folder ) {
			$args['post_parent'] = 0;
			return $args;
		}

		if ( self::DUPLICATE === $folder || self::MISSING === $folder ) {
			$ids              = Health::scan()[ self::DUPLICATE === $folder ? 'duplicate' : 'missing' ];
			$args['post__in'] = $ids ? $ids : array( 0 );
			return $args;
		}

		if ( self::NO_ALT === $folder ) {
			if ( empty( $args['post_mime_type'] ) ) {
				$args['post_mime_type'] = 'image';
			}
			$missing  = array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
			);
			$existing = $args['meta_query'] ?? array();
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = $existing
				? array(
					'relation' => 'AND',
					$existing,
					$missing,
				)
				: $missing;
			return $args;
		}

		$clause = self::tax_clause( $folder );
		if ( $clause ) {
			$args['tax_query'] = self::merge( $args['tax_query'] ?? array(), $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		return $args;
	}

	/**
	 * Adds a clause to a tax_query, keeping existing clauses.
	 *
	 * @param mixed $tax_query Existing tax_query.
	 * @param array $clause    Clause to add.
	 * @return array
	 */
	private static function merge( $tax_query, array $clause ) {
		$tax_query   = is_array( $tax_query ) ? $tax_query : array();
		$tax_query[] = $clause;
		if ( count( $tax_query ) > 1 && ! isset( $tax_query['relation'] ) ) {
			$tax_query['relation'] = 'AND';
		}
		return $tax_query;
	}

	/**
	 * Media grid and media dialogs: core strips unknown query keys before this
	 * filter runs, so the folder is read from the original request. Core has
	 * already checked the upload_files capability for this Ajax action.
	 *
	 * @param array $query WP_Query arguments.
	 * @return array
	 */
	public function filter_ajax_query( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only filter, value is sanitised in parse().
		$raw = isset( $_REQUEST['query'][ self::PARAM ] ) ? wp_unslash( $_REQUEST['query'][ self::PARAM ] ) : '';
		if ( ! current_user_can( Plugin::use_capability() ) ) {
			return $query;
		}
		// The media library screen sends this flag; the editor dialogs do not, so a post can still open any entry it uses.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag.
		$clean = ! empty( $_REQUEST['query'][ self::CLEAN ] );
		return self::apply( $query, self::parse( $raw ), $clean );
	}

	/**
	 * Media list view (upload.php?mode=list).
	 *
	 * @param WP_Query $query Query.
	 */
	public function filter_list_query( $query ) {
		global $pagenow;

		if ( ! is_admin() || ! $query->is_main_query() || 'upload.php' !== $pagenow ) {
			return;
		}
		if ( ! current_user_can( Plugin::use_capability() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in parse().
		$folder = isset( $_GET[ self::PARAM ] ) ? self::parse( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		$args   = self::apply(
			array(
				'tax_query'      => $query->get( 'tax_query' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'meta_query'     => $query->get( 'meta_query' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'post_mime_type' => $query->get( 'post_mime_type' ),
				'post__in'       => $query->get( 'post__in' ),
				'post__not_in'   => $query->get( 'post__not_in' ),
				'post_status'    => $query->get( 'post_status' ),
			),
			$folder,
			true
		);
		foreach ( array( 'tax_query', 'meta_query', 'post_mime_type', 'post_parent', 'post__in', 'post__not_in' ) as $key ) {
			if ( isset( $args[ $key ] ) && $args[ $key ] !== $query->get( $key ) ) {
				$query->set( $key, $args[ $key ] );
			}
		}
	}
}
