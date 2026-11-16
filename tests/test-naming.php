<?php
/**
 * Naming rule regression tests.
 */

define( 'ABSPATH', __DIR__ . '/' );

function wp_timezone() {
	return new DateTimeZone( 'Europe/Zurich' );
}
function get_bloginfo( $field ) {
	unset( $field );
	return 'Demo Site';
}
function remove_accents( $value ) {
	return strtr( $value, array( 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U' ) );
}

require_once dirname( __DIR__ ) . '/includes/class-naming.php';

use MomentumLab\MediaFolder\Naming;

$name = Naming::render(
	'%companyname%-%folder%-%imagename%-%date%-%counter%',
	array(
		'company_name'  => 'MomentumLab AG',
		'site_name'     => 'Demo',
		'image_name'    => 'Startbereich Eigergletscher',
		'original_name' => 'IMG_8421',
		'folder'        => 'Skimo WM',
		'author'        => 'Redaktion',
		'date'          => new DateTimeImmutable( '2026-11-16', wp_timezone() ),
	),
	7
);

$expected = 'momentumlab-ag-skimo-wm-startbereich-eigergletscher-2026-11-16-007';
if ( $expected !== $name ) {
	fwrite( STDERR, "Naming test failed: expected {$expected}, got {$name}\n" );
	exit( 1 );
}

if ( strlen( Naming::render( str_repeat( '%imagename%-', 40 ), array( 'company_name' => 'A', 'image_name' => 'Long Name', 'original_name' => 'x' ) ) ) > 160 ) {
	fwrite( STDERR, "Naming max-length test failed.\n" );
	exit( 1 );
}

echo "Naming tests passed.\n";
