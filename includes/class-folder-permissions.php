<?php
/**
 * Pro folder access rules.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

final class Folder_Permissions {

	const ROLES_META = '_mlab_mf_allowed_roles';
	const USERS_META = '_mlab_mf_allowed_users';
	const COLOR_META = '_mlab_mf_color';

	public static function can_access( int $folder_id, int $user_id = 0 ): bool {
		if ( ! Features::available( 'folder_permissions' ) || 0 === $folder_id ) {
			return true;
		}

		$user_id = $user_id ?: get_current_user_id();
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}

		$rules = self::effective_rules( $folder_id );
		if ( ! $rules['roles'] && ! $rules['users'] ) {
			return true;
		}
		if ( in_array( $user_id, $rules['users'], true ) ) {
			return true;
		}
		return (bool) array_intersect( $rules['roles'], (array) $user->roles );
	}

	/**
	 * Access rules inherit from the nearest ancestor that defines them.
	 *
	 * @return array{roles:string[],users:int[]}
	 */
	private static function effective_rules( int $folder_id ): array {
		$id    = $folder_id;
		$guard = 0;

		while ( 0 < $id && 30 > $guard++ ) {
			$roles = array_values( array_filter( array_map( 'sanitize_key', (array) get_term_meta( $id, self::ROLES_META, true ) ) ) );
			$users = array_values( array_filter( array_map( 'absint', (array) get_term_meta( $id, self::USERS_META, true ) ) ) );

			if ( $roles || $users ) {
				return array( 'roles' => $roles, 'users' => $users );
			}

			$term = get_term( $id, Taxonomy::NAME );
			if ( ! $term || is_wp_error( $term ) ) {
				break;
			}
			$id = (int) $term->parent;
		}

		return array( 'roles' => array(), 'users' => array() );
	}

	/**
	 * Folder IDs the current user must not see.
	 *
	 * @return int[]
	 */
	public static function inaccessible_ids(): array {
		if ( ! Features::available( 'folder_permissions' ) || current_user_can( 'manage_options' ) ) {
			return array();
		}

		$ids = get_terms(
			array(
				'taxonomy'   => Taxonomy::NAME,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static fn( int $id ): bool => ! self::can_access( $id )
			)
		);
	}

	/**
	 * Whether the current user can access an attachment through its folder.
	 */
	public static function can_access_attachment( int $attachment_id ): bool {
		$folder_id = Folders::of_attachment( $attachment_id );
		return 0 === $folder_id || self::can_access( $folder_id );
	}

	/**
	 * Filter a folder list for the current user.
	 *
	 * @param array<int,array<string,mixed>> $folders Folders.
	 * @return array<int,array<string,mixed>>
	 */
	public static function visible( array $folders ): array {
		if ( ! Features::available( 'folder_permissions' ) ) {
			return $folders;
		}
		return array_values(
			array_filter(
				$folders,
				static fn( array $folder ): bool => self::can_access( (int) $folder['id'] )
			)
		);
	}

	/**
	 * Folder presentation and access metadata.
	 *
	 * @return array{roles:string[],users:int[],color:string}
	 */
	public static function get( int $folder_id ): array {
		return array(
			'roles' => array_values( array_filter( array_map( 'sanitize_key', (array) get_term_meta( $folder_id, self::ROLES_META, true ) ) ) ),
			'users' => array_values( array_filter( array_map( 'absint', (array) get_term_meta( $folder_id, self::USERS_META, true ) ) ) ),
			'color' => sanitize_hex_color( (string) get_term_meta( $folder_id, self::COLOR_META, true ) ) ?: '',
		);
	}

	/**
	 * Copy stored Pro metadata internally when a folder is duplicated.
	 *
	 * @param int $source_id Source folder.
	 * @param int $target_id Target folder.
	 */
	public static function copy( int $source_id, int $target_id ): void {
		if ( ! Features::is_pro() || ! Folders::exists( $source_id ) || ! Folders::exists( $target_id ) ) {
			return;
		}
		$rules = self::get( $source_id );
		update_term_meta( $target_id, self::ROLES_META, $rules['roles'] );
		update_term_meta( $target_id, self::USERS_META, $rules['users'] );
		if ( Features::available( 'advanced_folder_style' ) ) {
			update_term_meta( $target_id, self::COLOR_META, $rules['color'] );
		}
	}

	/**
	 * Save Pro-only folder metadata.
	 *
	 * @param int      $folder_id Folder.
	 * @param string[] $roles     Roles.
	 * @param int[]    $users     Users.
	 * @param string   $color     Optional hex colour.
	 */
	public static function save( int $folder_id, array $roles, array $users, string $color = '' ): void {
		if ( ! Features::available( 'folder_permissions' ) || ! current_user_can( Plugin::settings_capability() ) ) {
			return;
		}

		$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', $roles ) ) ) );
		$users = array_values( array_unique( array_filter( array_map( 'absint', $users ) ) ) );
		$color = sanitize_hex_color( $color ) ?: '';

		update_term_meta( $folder_id, self::ROLES_META, $roles );
		update_term_meta( $folder_id, self::USERS_META, $users );

		if ( Features::available( 'advanced_folder_style' ) ) {
			update_term_meta( $folder_id, self::COLOR_META, $color );
		}
	}
}
