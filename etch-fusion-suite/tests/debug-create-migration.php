<?php
/**
 * Debug save_progress flow
 */

require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/core/class-db-installer.php';
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/repositories/class-db-migration-persistence.php';
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/repositories/class-wordpress-migration-repository.php';

global $wpdb;

// Ensure tables exist
\Bricks2Etch\Core\EFS_DB_Installer::install();

$migration_id = 'debug-' . wp_generate_uuid4();

echo "Migration ID: $migration_id\n";

$repo = new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();

$progress = array(
	'migrationId'  => $migration_id,
	'status'       => 'running',
	'percentage'   => 0,
	'message'      => 'Test',
);

echo "Calling save_progress()...\n";
$result = $repo->save_progress( $progress );
echo "Result: " . var_export( $result, true ) . "\n";

// Check options
echo "\nOptions check:\n";
$opt_id = get_option( 'efs_current_migration_id' );
echo "  efs_current_migration_id: $opt_id\n";
$opt_progress = get_option( 'efs_migration_progress' );
echo "  efs_migration_progress: " . ( is_array( $opt_progress ) ? 'array(' . count( $opt_progress ) . ')' : 'not found' ) . "\n";

// Check DB directly
echo "\nDatabase check:\n";
$row = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
	$migration_id
), ARRAY_A );

if ( $row ) {
	echo "  Found in DB:\n";
	foreach ( $row as $key => $value ) {
		echo "    $key: $value\n";
	}
} else {
	echo "  NOT FOUND IN DB\n";
	echo "  Last query error: " . $wpdb->last_error . "\n";
}

// Check how many migrations total
$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}efs_migrations" );
echo "\nTotal migrations in DB: $count\n";

// List all
$all = $wpdb->get_results( "SELECT migration_uid, status FROM {$wpdb->prefix}efs_migrations LIMIT 5" );
echo "\nLast 5:\n";
foreach ( $all as $m ) {
	echo "  - {$m->migration_uid}: {$m->status}\n";
}



