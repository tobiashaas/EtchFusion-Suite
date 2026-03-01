<?php
// Debug REST API routes
define( 'WP_USE_THEMES', false );
require( '/var/www/html/wp-load.php' );

do_action( 'rest_api_init' );

$server = rest_get_server();
if ( $server ) {
	$routes = $server->get_routes();
	error_log( 'Total REST routes: ' . count( $routes ) );

	// Check for efs routes
	$efs_found = false;
	foreach ( array_keys( $routes ) as $route ) {
		if ( strpos( $route, 'efs' ) !== false ) {
			error_log( 'EFS route: ' . $route );
			$efs_found = true;
		}
	}

	if ( ! $efs_found ) {
		error_log( 'NO EFS ROUTES FOUND!' );
	}
} else {
	error_log( 'REST Server not available' );
}

// Try to call register_routes directly
if ( class_exists( '\Bricks2Etch\Admin\EFS_Progress_Dashboard_API' ) ) {
	error_log( 'EFS_Progress_Dashboard_API class exists' );
	try {
		\Bricks2Etch\Admin\EFS_Progress_Dashboard_API::register_routes();
		error_log( 'Routes registered successfully' );
	} catch ( Exception $e ) {
		error_log( 'Error registering routes: ' . $e->getMessage() );
	}
} else {
	error_log( 'EFS_Progress_Dashboard_API class NOT found' );
}
?>
