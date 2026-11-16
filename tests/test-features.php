<?php
/**
 * Free/Pro gate regression tests.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mlab_test_pro'] = false;

function apply_filters( $hook, $value ) {
	if ( 'mlab_media_folder_pro_unlocked' === $hook ) {
		return (bool) $GLOBALS['mlab_test_pro'];
	}
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/class-features.php';

use MomentumLab\MediaFolder\Features;

if ( ! Features::available( 'folders' ) || ! Features::available( 'global_search' ) ) {
	fwrite( STDERR, "Free feature gate failed.\n" );
	exit( 1 );
}
if ( Features::available( 'ai_metadata' ) || Features::available( 'folder_permissions' ) ) {
	fwrite( STDERR, "Pro feature leaked into Free tier.\n" );
	exit( 1 );
}

$GLOBALS['mlab_test_pro'] = true;
if ( ! Features::available( 'ai_metadata' ) || ! Features::available( 'folder_permissions' ) || ! Features::is_pro() ) {
	fwrite( STDERR, "Pro feature gate failed.\n" );
	exit( 1 );
}

echo "Feature gate tests passed.\n";
