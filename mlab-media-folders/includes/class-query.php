<?php
/**
 * Filters media queries by folder.
 *
 * @package MomentumLab\MediaFolders
 */

namespace MomentumLab\MediaFolders;

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
	 * Value for files that are in no folder.
	 */
	const UNFILED = 'none';

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_ajax_query' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_list_query' ) );
	}

	/**
	 * Normalises a raw folder value: '' (all), 'none' (unfiled) or a folder ID.
	 *
	 * @param mixed $raw Raw value from the request.
	 * @return string|int
	 */
	public static function parse( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$raw = sanitize_key( (string) $raw );
		if ( self::UNFILED === $raw ) {
			return self::UNFILED;
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
		$clause = self::tax_clause( self::parse( $raw ) );
		if ( $clause ) {
			$query['tax_query'] = self::merge( $query['tax_query'] ?? array(), $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		return $query;
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( ! isset( $_GET[ self::PARAM ] ) || ! current_user_can( Plugin::use_capability() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in parse().
		$clause = self::tax_clause( self::parse( wp_unslash( $_GET[ self::PARAM ] ) ) );
		if ( $clause ) {
			$query->set( 'tax_query', self::merge( $query->get( 'tax_query' ), $clause ) );
		}
	}
}
