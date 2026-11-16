<?php
/**
 * Migration mapping regression tests.
 */

define( 'ABSPATH', __DIR__ . '/' );

function absint( $value ) {
	return abs( (int) $value );
}

require_once dirname( __DIR__ ) . '/includes/class-migration.php';

use MomentumLab\MediaFolder\Migration;

$map = array(
	10 => array( 'target' => 100, 'depth' => 0 ),
	20 => array( 'target' => 200, 'depth' => 2 ),
	30 => array( 'target' => 300, 'depth' => 2 ),
);

$selected = Migration::select_source_folder( array( 10, 30, 20 ), $map );
if ( 20 !== $selected ) {
	fwrite( STDERR, "Migration folder selection failed.\n" );
	exit( 1 );
}

if ( 0 !== Migration::select_source_folder( array( 999 ), $map ) ) {
	fwrite( STDERR, "Unknown migration folder should not be selected.\n" );
	exit( 1 );
}

echo "Migration mapping tests passed.\n";
