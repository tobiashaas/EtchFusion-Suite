<?php
/**
 * Migration Debug Script
 * 
 * Run from wp-env: npx wp-env run cli php /var/www/html/wp-content/plugins/etch-fusion-suite/debug-migration.php
 */

// Load WordPress
if ( file_exists( __DIR__ . '/../../../../wp-load.php' ) ) {
	require_once __DIR__ . '/../../../../wp-load.php';
}

echo "\n=== MIGRATION DEBUG REPORT ===\n\n";

// 1. CHECK IF MIGRATION_KEY IS SAVED
echo "[1] CHECKING MIGRATION_KEY IN SETTINGS\n";
$settings = get_option( 'efs_settings' );
echo "Settings found: " . ( $settings ? 'YES' : 'NO' ) . "\n";

if ( $settings && isset( $settings['migration_key'] ) ) {
	$migration_key = $settings['migration_key'];
	echo "Migration key exists: YES\n";
	echo "Migration key length: " . strlen( $migration_key ) . " chars\n";
	echo "First 50 chars: " . substr( $migration_key, 0, 50 ) . "...\n";
	
	// 2. CHECK IF JWT CAN BE DECODED
	echo "\n[2] ATTEMPTING JWT DECODE\n";
	$container = etch_fusion_suite_container();
	if ( $container && $container->has( 'token_manager' ) ) {
		$token_manager = $container->get( 'token_manager' );
		try {
			$decoded = $token_manager->decode_migration_key_locally( $migration_key );
			echo "JWT Decode: SUCCESS\n";
			echo "Decoded payload:\n";
			echo json_encode( $decoded, JSON_PRETTY_PRINT ) . "\n";
			
			// 3. CHECK IF TARGET_URL IN PAYLOAD
			echo "\n[3] CHECKING TARGET_URL IN JWT PAYLOAD\n";
			if ( isset( $decoded['target_url'] ) ) {
				echo "target_url exists: YES\n";
				echo "target_url value: " . $decoded['target_url'] . "\n";
			} else {
				echo "target_url exists: NO\n";
				echo "Available fields: " . implode( ', ', array_keys( (array) $decoded ) ) . "\n";
			}
			
		} catch ( \Exception $e ) {
			echo "JWT Decode: FAILED\n";
			echo "Error: " . $e->getMessage() . "\n";
		}
	} else {
		echo "token_manager not found in container\n";
	}
	
} else {
	echo "Migration key exists: NO\n";
	echo "Full settings: " . json_encode( $settings, JSON_PRETTY_PRINT ) . "\n";
}

// 4. CHECK MIGRATION LOGS TABLE
echo "\n[4] CHECKING MIGRATION LOGS\n";
global $wpdb;
$logs_table = $wpdb->prefix . 'efs_migration_logs';
$count = $wpdb->get_var( "SELECT COUNT(*) FROM $logs_table" );
echo "Migration logs found: $count\n";

if ( $count > 0 ) {
	// Get post_type breakdown
	$breakdown = $wpdb->get_results(
		"SELECT 
			JSON_EXTRACT(context, '$.post_type') as post_type,
			COUNT(*) as count
		FROM $logs_table
		GROUP BY JSON_EXTRACT(context, '$.post_type')"
	);
	
	echo "Breakdown by post_type:\n";
	foreach ( $breakdown as $item ) {
		echo "  - " . ( $item->post_type ?: 'NULL' ) . ": " . $item->count . "\n";
	}
} else {
	echo "No migration logs yet (migration may not have started)\n";
}

// 5. CHECK PROGRESS MANAGER
echo "\n[5] CHECKING PROGRESS MANAGER\n";
if ( $container && $container->has( 'progress_manager' ) ) {
	$progress_manager = $container->get( 'progress_manager' );
	
	// Get current migration
	$current_migration = get_option( 'efs_current_migration' );
	if ( $current_migration ) {
		echo "Current migration UID: " . $current_migration . "\n";
		
		try {
			$progress = $progress_manager->get_progress( $current_migration );
			echo "Progress data:\n";
			echo json_encode( $progress, JSON_PRETTY_PRINT ) . "\n";
			
			// Check for elapsed_seconds and breakdown
			if ( isset( $progress['elapsed_seconds'] ) ) {
				echo "elapsed_seconds: " . $progress['elapsed_seconds'] . "\n";
			} else {
				echo "elapsed_seconds: NOT IN RESPONSE\n";
			}
			
			if ( isset( $progress['estimated_time_remaining'] ) ) {
				echo "estimated_time_remaining: " . $progress['estimated_time_remaining'] . "\n";
			} else {
				echo "estimated_time_remaining: NOT IN RESPONSE\n";
			}
			
			if ( isset( $progress['breakdown'] ) ) {
				echo "breakdown: " . json_encode( $progress['breakdown'], JSON_PRETTY_PRINT ) . "\n";
			} else {
				echo "breakdown: NOT IN RESPONSE\n";
			}
			
		} catch ( \Exception $e ) {
			echo "Error getting progress: " . $e->getMessage() . "\n";
		}
	} else {
		echo "Current migration UID: NOT SET\n";
	}
} else {
	echo "progress_manager not found in container\n";
}

echo "\n=== END DEBUG REPORT ===\n\n";
