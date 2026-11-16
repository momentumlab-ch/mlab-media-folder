<?php
/**
 * Pro saved dynamic media views.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Stores Pro-only reusable media filters without replacing the native library.
 */
final class Dynamic_Views {

	const OPTION = 'mlab_media_folder_dynamic_views';

	/**
	 * Return all saved views.
	 *
	 * @return array<int,array{id:string,name:string,mime:string,author:int,days:int,min_mb:int}>
	 */
	public static function all(): array {
		if ( ! Features::available( 'dynamic_views' ) ) {
			return array();
		}
		$views = get_option( self::OPTION, array() );
		return is_array( $views ) ? array_values( $views ) : array();
	}

	/**
	 * Get one view by ID.
	 *
	 * @param string $id View ID.
	 * @return array{id:string,name:string,mime:string,author:int,days:int,min_mb:int}|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $view ) {
			if ( (string) ( $view['id'] ?? '' ) === $id ) {
				return $view;
			}
		}
		return null;
	}

	/**
	 * Save a view and return its normalized value.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function save( array $input ) {
		if ( ! Features::available( 'dynamic_views' ) ) {
			return new \WP_Error( 'mlab_media_folder_pro_required', __( 'Dynamic Views require MomentumLab Media Folder Pro.', 'mlab-media-folder' ), array( 'status' => 403 ) );
		}

		$view = self::normalize( $input );
		if ( is_wp_error( $view ) ) {
			return $view;
		}
		$id = (string) $view['id'];

		$views = self::all();

		$found = false;
		foreach ( $views as $index => $candidate ) {
			if ( (string) ( $candidate['id'] ?? '' ) === $id ) {
				$views[ $index ] = $view;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$views[] = $view;
		}

		update_option( self::OPTION, array_values( $views ), false );
		return $view;
	}

	/**
	 * Replace the complete saved-view collection from the settings screen.
	 *
	 * @param array<int,array<string,mixed>> $views Submitted views.
	 */
	public static function replace( array $views ): void {
		if ( ! Features::available( 'dynamic_views' ) ) {
			return;
		}
		$normalized = array();
		foreach ( $views as $view ) {
			if ( ! is_array( $view ) || '' === trim( (string) ( $view['name'] ?? '' ) ) ) {
				continue;
			}
			$result = self::normalize( $view );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			$normalized[] = $result;
		}
		update_option( self::OPTION, array_values( $normalized ), false );
	}

	/**
	 * Normalize one view without persisting it.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function normalize( array $input ) {
		$name = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
		if ( '' === $name ) {
			return new \WP_Error( 'mlab_media_folder_view_name', __( 'Enter a name for the Dynamic View.', 'mlab-media-folder' ), array( 'status' => 400 ) );
		}
		$id = sanitize_key( (string) ( $input['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = 'v' . strtolower( wp_generate_password( 10, false, false ) );
		}
		$mime         = sanitize_text_field( (string) ( $input['mime'] ?? '' ) );
		$allowed_mime = array( '', 'image', 'video', 'audio', 'application/pdf' );
		$mime         = in_array( $mime, $allowed_mime, true ) ? $mime : '';

		return array(
			'id'     => $id,
			'name'   => $name,
			'mime'   => $mime,
			'author' => absint( $input['author'] ?? 0 ),
			'days'   => min( 3650, absint( $input['days'] ?? 0 ) ),
			'min_mb' => min( 102400, absint( $input['min_mb'] ?? 0 ) ),
		);
	}

	/**
	 * Delete a saved view.
	 *
	 * @param string $id View ID.
	 */
	public static function delete( string $id ): bool {
		if ( ! Features::available( 'dynamic_views' ) ) {
			return false;
		}
		$before = self::all();
		$after  = array_values(
			array_filter(
				$before,
				static fn( array $view ): bool => (string) ( $view['id'] ?? '' ) !== $id
			)
		);
		update_option( self::OPTION, $after, false );
		return count( $after ) < count( $before );
	}

	/**
	 * Apply a saved view to WP_Query args.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @param string              $id   View ID.
	 * @return array<string,mixed>
	 */
	public static function apply( array $args, string $id ): array {
		$view = self::get( $id );
		if ( ! $view ) {
			return $args;
		}

		if ( '' !== $view['mime'] ) {
			$args['post_mime_type'] = 'application/pdf' === $view['mime'] ? 'application/pdf' : $view['mime'];
		}
		if ( 0 < (int) $view['author'] ) {
			$args['author'] = (int) $view['author'];
		}
		if ( 0 < (int) $view['days'] ) {
			$args['date_query'] = array(
				array(
					'after'     => (int) $view['days'] . ' days ago',
					'inclusive' => true,
				),
			);
		}

		if ( 0 < (int) $view['min_mb'] ) {
			$size_clause = array(
				'key'     => Content_Audit::SIZE_META,
				'value'   => (int) $view['min_mb'] * MB_IN_BYTES,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
			$existing    = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
			$existing[]  = $size_clause;
			if ( 1 < count( $existing ) && ! isset( $existing['relation'] ) ) {
				$existing['relation'] = 'AND';
			}
			$args['meta_query'] = $existing; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		return $args;
	}
}
