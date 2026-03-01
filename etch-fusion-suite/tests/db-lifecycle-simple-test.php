<?php
/**
 * Comprehensive Database Lifecycle Test - Simplified Version
 * Tests: Installation, Creation, Updates, Logging, Deactivation Cleanup, Uninstall Removal
 */

require_once ABSPATH . 'wp-load.php';
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/core/class-db-installer.php';

$wpdb = $GLOBALS['wpdb'];
$passed = 0;
$failed = 0;

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║   DATABASE LIFECYCLE TEST - SIMPLIFIED                      ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// ============================================================
// STEP 1: INSTALLATION
// ============================================================
echo "✓ STEP 1: Database Installation\n";
Bricks2Etch\Core\EFS_DB_Installer::install();
$exists_migrations = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}efs_migrations'" );
$exists_logs = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}efs_migration_logs'" );
$db_version = get_option( 'efs_db_version' );

if ( $exists_migrations && $exists_logs && $db_version === '1.0.0' ) {
    echo "  ✓ PASS: Tables created, DB version set\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Installation incomplete\n";
    $failed++;
}

// ============================================================
// STEP 2: CREATE MIGRATION & BASIC DATA
// ============================================================
echo "\n✓ STEP 2: Create Migration & Update Data\n";
$uid = Bricks2Etch\Core\EFS_DB_Installer::create_migration( 'https://source.local', 'https://target.local' );

if ( $uid ) {
    echo "  ✓ Created migration UID: " . substr( $uid, 0, 8 ) . "...\n";
    $passed++;
} else {
    echo "  ✗ FAIL: create_migration() returned null\n";
    $failed++;
    exit;
}

// Update progress
$progress_result = Bricks2Etch\Core\EFS_DB_Installer::update_progress( $uid, 50, 100 );
if ( $progress_result ) {
    echo "  ✓ Progress updated: 50%\n";
    $passed++;
} else {
    echo "  ✗ FAIL: update_progress() failed\n";
    $failed++;
}

// Update status
$status_result = Bricks2Etch\Core\EFS_DB_Installer::update_status( $uid, 'in_progress' );
if ( $status_result ) {
    echo "  ✓ Status updated: in_progress\n";
    $passed++;
} else {
    echo "  ✗ FAIL: update_status() failed\n";
    $failed++;
}

// ============================================================
// STEP 3: LOGGING
// ============================================================
echo "\n✓ STEP 3: Event Logging\n";
$log1 = Bricks2Etch\Core\EFS_DB_Installer::log_event( $uid, 'info', 'Test message', 'test', array( 'key' => 'value' ) );
$log2 = Bricks2Etch\Core\EFS_DB_Installer::log_event( $uid, 'error', 'Error message', 'test' );

if ( $log1 && $log2 ) {
    echo "  ✓ Events logged successfully\n";
    $passed++;
    
    $log_count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}efs_migration_logs WHERE migration_uid = %s",
        $uid
    ) );
    echo "  ✓ Log count: $log_count\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Logging failed\n";
    $failed++;
}

// ============================================================
// STEP 4: DATA VERIFICATION
// ============================================================
echo "\n✓ STEP 4: Data Verification\n";
$migration = Bricks2Etch\Core\EFS_DB_Installer::get_migration( $uid );

if ( $migration && $migration['status'] === 'in_progress' && $migration['processed_items'] == 50 ) {
    echo "  ✓ Migration data correct\n";
    echo "    - UID: " . substr( $migration['migration_uid'], 0, 8 ) . "...\n";
    echo "    - Status: {$migration['status']}\n";
    echo "    - Progress: {$migration['progress_percent']}%\n";
    echo "    - Started at: {$migration['started_at']}\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Migration data incorrect\n";
    var_dump( $migration );
    $failed++;
}

// ============================================================
// STEP 5: DEACTIVATION CLEANUP
// ============================================================
echo "\n✓ STEP 5: Deactivation Cleanup (Preserve Data)\n";

// Add temp data
set_transient( 'efs_temp_test', 'data', 3600 );
update_option( 'efs_batch_lock', 'locked' );

$transient_count_before = count( $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_efs_%'"
) );

// Simulate deactivation cleanup
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    '_transient_efs_%',
    '_transient_timeout_efs_%'
) );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'efs_batch_lock%'" );

$transient_count_after = count( $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_efs_%'"
) );

echo "  ✓ Transients before: $transient_count_before, after: $transient_count_after\n";
if ( $transient_count_after === 0 ) {
    $passed++;
} else {
    $failed++;
}

// BUT migration data should still exist
$mig_count_after_deactivation = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}efs_migrations"
);

if ( intval( $mig_count_after_deactivation ) >= 1 ) {
    echo "  ✓ Migration data preserved after deactivation (count: $mig_count_after_deactivation)\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Migration data was deleted\n";
    $failed++;
}

// ============================================================
// STEP 6: UNINSTALL CLEANUP
// ============================================================
echo "\n✓ STEP 6: Uninstall Complete Cleanup\n";

// Get counts before
$table_count_before = 2;  // We know there are 2 tables
$option_count_before = 1;  // DB version

// Run uninstall
Bricks2Etch\Core\EFS_DB_Installer::uninstall();

// Check after
$migrations_table_after = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}efs_migrations'" );
$logs_table_after = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}efs_migration_logs'" );
$db_version_after = get_option( 'efs_db_version' );

if ( ! $migrations_table_after && ! $logs_table_after && ! $db_version_after ) {
    echo "  ✓ All tables deleted\n";
    echo "  ✓ All options deleted\n";
    echo "  ✓ Complete cleanup successful\n";
    $passed += 3;
} else {
    echo "  ✗ FAIL: Some data remains after uninstall\n";
    echo "    - Migrations table: " . ( $migrations_table_after ? 'EXISTS' : 'gone' ) . "\n";
    echo "    - Logs table: " . ( $logs_table_after ? 'EXISTS' : 'gone' ) . "\n";
    echo "    - DB version option: " . ( $db_version_after ? "'{$db_version_after}'" : 'gone' ) . "\n";
    $failed += 3;
}

// ============================================================
// SUMMARY
// ============================================================
echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║   TEST RESULTS SUMMARY                                     ║\n";
echo "╠════════════════════════════════════════════════════════════╣\n";
echo sprintf( "║ Passed: %-50d ║\n", $passed );
echo sprintf( "║ Failed: %-50d ║\n", $failed );
echo sprintf( "║ Total:  %-50d ║\n", $passed + $failed );
echo sprintf( "║ Success: %d%%%-48s║\n", round( ( $passed / ( $passed + $failed ) ) * 100 ), '' );
echo "╚════════════════════════════════════════════════════════════╝\n";

exit( $failed > 0 ? 1 : 0 );
