<?php
/**
 * Regression tests for shared-file deletion protection.
 *
 * @package MomentumLab\MediaFolder
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-content-audit.php';
require_once dirname( __DIR__ ) . '/includes/class-health.php';

use MomentumLab\MediaFolder\Health;

$health = new Health();
$property = new ReflectionProperty( Health::class, 'protected_paths' );
$property->setAccessible( true );
$property->setValue(
	$health,
	array(
		'/uploads/shared.jpg' => true,
		'/uploads/shared-300x300.jpg' => true,
	)
);

$failures = array();

if ( '' !== $health->keep_file( '/uploads/shared.jpg' ) ) {
	$failures[] = 'Shared original was not protected.';
}
if ( '' !== $health->keep_file( '/uploads/shared-300x300.jpg' ) ) {
	$failures[] = 'Shared derivative was not protected.';
}
if ( '/uploads/unrelated.jpg' !== $health->keep_file( '/uploads/unrelated.jpg' ) ) {
	$failures[] = 'Unrelated file was incorrectly protected.';
}

if ( $failures ) {
	fwrite( STDERR, "Shared-file guard tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Shared-file guard tests passed.\n";
