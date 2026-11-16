<?php
/**
 * Secret-storage regression test.
 */

define( 'ABSPATH', __DIR__ . '/' );

function wp_salt( $scheme = 'auth' ) {
	return 'test-secret-' . $scheme . '-123456789';
}

require_once dirname( __DIR__ ) . '/includes/class-secrets.php';

use MomentumLab\MediaFolder\Secrets;

$plain  = 'sk-test-do-not-log-this';
$stored = Secrets::encrypt( $plain );

if ( '' === $stored ) {
	fwrite( STDERR, "Secret encryption unavailable in test runtime.\n" );
	exit( 1 );
}
if ( $plain === $stored || $plain !== Secrets::decrypt( $stored ) ) {
	fwrite( STDERR, "Secret round-trip test failed.\n" );
	exit( 1 );
}
if ( '' !== Secrets::decrypt( 'invalid:ciphertext' ) ) {
	fwrite( STDERR, "Invalid secret did not fail closed.\n" );
	exit( 1 );
}

echo "Secret storage tests passed.\n";
