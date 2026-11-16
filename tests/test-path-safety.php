<?php
/**
 * Minimal behaviour tests that run without bootstrapping WordPress.
 *
 * @package MomentumLab\MediaFolder
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-content-audit.php';

use MomentumLab\MediaFolder\Content_Audit;

$cases = array(
	'2026/11/photo.jpg'       => '2026/11/photo.jpg',
	'2026\\11\\photo.jpg' => '2026/11/photo.jpg',
	''                        => null,
	'https://cdn.test/a.jpg'  => null,
	'/etc/passwd'             => null,
	'C:\\temp\\a.jpg'     => null,
	'2026/../secret.jpg'      => null,
	'./photo.jpg'             => null,
);

$failed = array();
foreach ( $cases as $input => $expected ) {
	$actual = Content_Audit::safe_relative_path( $input );
	if ( $actual !== $expected ) {
		$failed[] = sprintf( '%s => expected %s, got %s', $input, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

if ( $failed ) {
	fwrite( STDERR, "Path safety tests failed:\n- " . implode( "\n- ", $failed ) . "\n" );
	exit( 1 );
}

echo "Path safety tests passed.\n";
