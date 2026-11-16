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

		$roles = array_values( array_filter( array_map( 'sanitize_key', (array) get_term_meta( $folder_id, self::ROLES_META, true ) ) ) );
		$users = array_values( array_filter( array_map( 'absint', (array) get_term_meta( $folder_id, self::USERS_META, true ) ) ) );

		if ( ! $roles && ! $users ) {
			return true;
		}
		if ( in_array( $user_id, $users, true ) ) {
			return true;
		}
		return (bool) array_intersect( $roles, (array) $user->roles );
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
