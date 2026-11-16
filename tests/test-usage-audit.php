<?php
/**
 * Conservative usage parser regression tests.
 */

define( 'ABSPATH', __DIR__ . '/' );

function absint( $value ) {
	return abs( (int) $value );
}

require_once dirname( __DIR__ ) . '/includes/class-usage-audit.php';

use MomentumLab\MediaFolder\Usage_Audit;

$content = '<img class="wp-image-42"> <!-- wp:image {"id":77} --> [gallery ids="8, 9, 42"]';
$actual  = Usage_Audit::ids_from_content( $content );
sort( $actual );
$expected = array( 8, 9, 42, 77 );

if ( $expected !== $actual ) {
	fwrite( STDERR, 'Usage parser failed: ' . json_encode( $actual ) . "\n" );
	exit( 1 );
}

if ( array() !== Usage_Audit::ids_from_content( '<p>No media IDs here.</p>' ) ) {
	fwrite( STDERR, "Usage empty-content test failed.\n" );
	exit( 1 );
}

echo "Usage audit parser tests passed.\n";
