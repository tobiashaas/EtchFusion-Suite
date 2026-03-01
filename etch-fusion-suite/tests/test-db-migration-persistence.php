<?php
/**
 * Database-Backed Migration Persistence Integration Test
 *
 * Tests full workflow: create → track → save → resume → complete
 * with all data persisted to wp_efs_migrations and wp_efs_migration_logs
 */

require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/repositories/class-db-migration-persistence.php';
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/repositories/class-wordpress-migration-repository.php';

use Bricks2Etch\Repositories\EFS_DB_Migration_Persistence;
use Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository;

$wpdb = $GLOBALS['wpdb'];
$passed = 0;
$failed = 0;

echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║  DB-BACKED MIGRATION PERSISTENCE INTEGRATION TEST             ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n\n";

// ============================================================
// SETUP: Ensure DB tables exist
// ============================================================
echo "→ SETUP: Ensuring database tables exist...\n";
do_action( 'activate_etch-fusion-suite/etch-fusion-suite.php' );
$tables_exist = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}efs_migrations'" );
if ( $tables_exist ) {
    echo "  ✓ Database tables ready\n\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Database tables not created\n";
    exit( 1 );
}

// ============================================================
// TEST 1: CREATE MIGRATION (Direct DB-Backed)
// ============================================================
echo "✓ TEST 1: Create Migration via DB-Backed Persistence\n";

$migration_id = 'test-' . wp_generate_uuid4();
$source = 'https://bricks.test';
$target = 'https://etch.test';

$created = EFS_DB_Migration_Persistence::create_migration( $migration_id, $source, $target );
echo "  → Migration ID: " . substr( $migration_id, 0, 8 ) . "...\n";

$mig = EFS_DB_Migration_Persistence::get_migration( $migration_id );
if ( $mig && $mig['status'] === 'pending' ) {
    echo "  ✓ PASS: Migration created in database\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Migration not found in database\n";
    $failed++;
}

// ============================================================
// TEST 2: SAVE PROGRESS (Repository → DB)
// ============================================================
echo "\n✓ TEST 2: Save Progress via Repository (writes to DB)\n";

$repo = new EFS_WordPress_Migration_Repository();

$progress = array(
    'migrationId'   => $migration_id,
    'sourceUrl'     => $source,
    'targetUrl'     => $target,
    'status'        => 'in_progress',
    'percentage'    => 0,
    'processedItems' => 0,
    'totalItems'    => 100,
    'message'       => 'Starting migration...',
);

$repo->save_progress( $progress );
echo "  → Saved: 0% progress, status: in_progress\n";

// Verify in database
$mig = EFS_DB_Migration_Persistence::get_migration( $migration_id );
if ( $mig && $mig['status'] === 'in_progress' && (int) $mig['total_items'] === 100 ) {
    echo "  ✓ PASS: Progress saved to database\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Progress not in database\n";
    var_dump( $mig );
    $failed++;
}

// ============================================================
// TEST 3: UPDATE PROGRESS (Simulate Migration)
// ============================================================
echo "\n✓ TEST 3: Update Progress Multiple Times\n";

for ( $step = 25; $step <= 100; $step += 25 ) {
    $progress['percentage']   = $step;
    $progress['processedItems'] = ( $step / 100 ) * 100;
    $progress['message']      = "Processing batch {$step}%";

    $repo->save_progress( $progress );
    echo "  → Step $step%\n";
}

$mig = EFS_DB_Migration_Persistence::get_migration( $migration_id );
if ( (int) $mig['progress_percent'] === 100 && (int) $mig['processed_items'] === 100 ) {
    echo "  ✓ PASS: Progress updated to 100%\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Final progress incorrect\n";
    $failed++;
}

// ============================================================
// TEST 4: AUDIT TRAIL (Events Logged)
// ============================================================
echo "\n✓ TEST 4: Audit Trail in Event Logs\n";

EFS_DB_Migration_Persistence::log_event(
    $migration_id,
    'info',
    'Migration step 1 completed',
    'content',
    array( 'posts_migrated' => 25 )
);

EFS_DB_Migration_Persistence::log_event(
    $migration_id,
    'warning',
    'Some media files failed to download',
    'media',
    array( 'failed_count' => 3 )
);

$logs = EFS_DB_Migration_Persistence::get_audit_trail( $migration_id );
if ( count( $logs ) >= 2 ) {
    echo "  ✓ PASS: " . count( $logs ) . " events logged\n";
    foreach ( $logs as $log ) {
        echo "    - [{$log['log_level']}] {$log['message']}\n";
    }
    $passed++;
} else {
    echo "  ✗ FAIL: Events not logged\n";
    $failed++;
}

// ============================================================
// TEST 5: COMPLETE MIGRATION
// ============================================================
echo "\n✓ TEST 5: Mark Migration Completed\n";

$progress['status'] = 'completed';
$repo->save_progress( $progress );

$mig = EFS_DB_Migration_Persistence::get_migration( $migration_id );
if ( $mig['status'] === 'completed' && ! empty( $mig['completed_at'] ) ) {
    echo "  ✓ PASS: Migration marked completed\n";
    echo "    - Completed at: {$mig['completed_at']}\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Migration not marked completed\n";
    $failed++;
}

// ============================================================
// TEST 6: STATISTICS
// ============================================================
echo "\n✓ TEST 6: Migration Statistics\n";

$stats = EFS_DB_Migration_Persistence::get_statistics();
echo "  → Total migrations: {$stats['total_migrations']}\n";
echo "  → Completed: {$stats['completed']}\n";
echo "  → Failed: {$stats['failed']}\n";
echo "  → In progress: {$stats['in_progress']}\n";
echo "  → Success rate: {$stats['success_rate']}%\n";

if ( $stats['total_migrations'] >= 1 && $stats['completed'] >= 1 ) {
    echo "  ✓ PASS: Statistics correct\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Statistics incorrect\n";
    $failed++;
}

// ============================================================
// TEST 7: RECENT MIGRATIONS (Dashboard Display)
// ============================================================
echo "\n✓ TEST 7: Recent Migrations for Dashboard\n";

$recent = EFS_DB_Migration_Persistence::get_recent_migrations( 10 );
echo "  → Found " . count( $recent ) . " recent migration(s)\n";

if ( count( $recent ) > 0 ) {
    $found = false;
    foreach ( $recent as $m ) {
        if ( $m['migration_uid'] === $migration_id ) {
            $found = true;
            echo "    - [{$m['status']}] {$m['source_url']} → {$m['target_url']}\n";
            echo "      Progress: {$m['progress_percent']}%\n";
            break;
        }
    }

    if ( $found ) {
        echo "  ✓ PASS: Migration visible in recent list\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: Migration not in recent list\n";
        $failed++;
    }
} else {
    echo "  ✗ FAIL: No migrations found\n";
    $failed++;
}

// ============================================================
// TEST 8: FALLBACK TO OPTIONS (Legacy Compat)
// ============================================================
echo "\n✓ TEST 8: Fallback to Options API\n";

$legacy_id = wp_generate_uuid4();
// Manually set options (legacy data)
update_option( 'efs_current_migration_id', $legacy_id );
update_option( 'efs_migration_progress', array(
    'status'     => 'in_progress',
    'percentage' => 50,
    'message'    => 'Legacy migration',
) );

// Repository should read from options when DB has no entry
$progress = $repo->get_progress();
if ( isset( $progress['migrationId'] ) && $progress['migrationId'] === $legacy_id ) {
    echo "  ✓ PASS: Fallback to options works for legacy data\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Fallback to options failed\n";
    $failed++;
}

// ============================================================
// SUMMARY
// ============================================================
echo "\n╔═══════════════════════════════════════════════════════════════╗\n";
echo "║  TEST RESULTS SUMMARY                                         ║\n";
echo "╠═══════════════════════════════════════════════════════════════╣\n";
echo sprintf( "║ Passed: %-54d ║\n", $passed );
echo sprintf( "║ Failed: %-54d ║\n", $failed );
echo sprintf( "║ Total:  %-54d ║\n", $passed + $failed );
echo sprintf( "║ Success Rate: %d%%%-42s║\n", round( ( $passed / ( $passed + $failed ) ) * 100 ), '' );
echo "╚═══════════════════════════════════════════════════════════════╝\n";

if ( $failed > 0 ) {
    echo "\n❌ Some tests failed. Check output above for details.\n";
    exit( 1 );
} else {
    echo "\n✅ All tests passed! Database persistence fully functional.\n";
    exit( 0 );
}
