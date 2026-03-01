<?php
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/core/class-db-installer.php';

global $wpdb;

$migration_id = 'test-' . wp_generate_uuid4();
echo "Creating migration: $migration_id\n";
echo "Leng: " . strlen( $migration_id ) . "\n";

// First ensure DB is installed
Bricks2Etch\Core\EFS_DB_Installer::install();

// Check table structure
$cols = $wpdb->get_results( "DESC {$wpdb->prefix}efs_migrations" );
foreach ( $cols as $col ) {
    if ( $col->Field === 'migration_uid' ) {
        echo "Column: {$col->Field}, Type: {$col->Type}, Null: {$col->Null}\n";
    }
}

// Try insert with escaped string
$escaped_id = $wpdb->esc_like( $migration_id );
echo "Escaped ID: $escaped_id\n";
echo "Escaped Len: " . strlen( $escaped_id ) . "\n";

// Try raw SQL
$sql = $wpdb->prepare(
    "INSERT INTO {$wpdb->prefix}efs_migrations (migration_uid, source_url, target_url, status) VALUES (%s, %s, %s, %s)",
    $migration_id,
    'https://bricks.test',
    'https://etch.test',
    'pending'
);

echo "SQL: $sql\n";
$result = $wpdb->query( $sql );
echo "Result: " . var_export( $result, true ) . "\n";
echo "Last error: " . $wpdb->last_error . "\n";


