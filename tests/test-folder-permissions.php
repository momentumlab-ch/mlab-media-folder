<?php
/**
 * Fail-safe folder permission regression tests.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['mlab_test_options'] = array(
		'mlab_media_folder_permissions_configured' => 1,
	);

	function get_option( $key, $default = false ) {
		return $GLOBALS['mlab_test_options'][ $key ] ?? $default;
	}

	function get_current_user_id() {
		return 7;
	}

	function get_userdata( $user_id ) {
		unset( $user_id );
		return (object) array( 'roles' => array( 'editor' ) );
	}

	function user_can( $user, $capability ) {
		unset( $user );
		return 'manage_options' === $capability ? false : false;
	}

	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function get_term_meta( $term_id, $key, $single = false ) {
		unset( $single );
		if ( 10 !== (int) $term_id ) {
			return array();
		}
		if ( '_mlab_mf_allowed_roles' === $key ) {
			return array( 'administrator' );
		}
		if ( '_mlab_mf_allowed_users' === $key ) {
			return array();
		}
		return '';
	}

	function get_term( $term_id, $taxonomy ) {
		unset( $taxonomy );
		return (object) array(
			'term_id' => (int) $term_id,
			'parent'  => 0,
		);
	}

	function is_wp_error( $value ) {
		unset( $value );
		return false;
	}
}

namespace MomentumLab\MediaFolder {
	final class Taxonomy {
		const NAME = 'mlab_media_folder';
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/includes/class-folder-permissions.php';

	use MomentumLab\MediaFolder\Folder_Permissions;

	if ( Folder_Permissions::can_access( 10, 7 ) ) {
		fwrite( STDERR, "Configured restriction failed open.\n" );
		exit( 1 );
	}

	$GLOBALS['mlab_test_options']['mlab_media_folder_permissions_configured'] = 0;
	if ( ! Folder_Permissions::can_access( 10, 7 ) ) {
		fwrite( STDERR, "Unconfigured permissions should remain open.\n" );
		exit( 1 );
	}

	echo "Folder permission fail-safe tests passed.\n";
}
