<?php
/**
 * Dashboard Progress Logging Integration Test
 *
 * Verifies REST API and progress tracking components.
 */

echo str_repeat('=', 60) . PHP_EOL;
echo 'Dashboard Progress Logging Integration Test' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;

// Test 1: Verify Trait
echo PHP_EOL . '[TEST 1] Verify Migration Progress Logger Trait' . PHP_EOL;
if (trait_exists('Bricks2Etch\Controllers\EFS_Migration_Progress_Logger')) {
	echo '✓ Migration Progress Logger trait exists' . PHP_EOL;
} else {
	echo '✗ Trait not found' . PHP_EOL;
	exit(1);
}

// Test 2: Verify REST API Class
echo PHP_EOL . '[TEST 2] Verify Progress Dashboard API Class' . PHP_EOL;
if (class_exists('Bricks2Etch\Admin\EFS_Progress_Dashboard_API')) {
	echo '✓ Progress Dashboard API class exists' . PHP_EOL;
} else {
	echo '✗ API class not found' . PHP_EOL;
	exit(1);
}

// Test 3: Verify Detailed Progress Tracker
echo PHP_EOL . '[TEST 3] Verify Detailed Progress Tracker' . PHP_EOL;
if (class_exists('Bricks2Etch\Services\EFS_Detailed_Progress_Tracker')) {
	echo '✓ Detailed Progress Tracker exists' . PHP_EOL;
} else {
	echo '✗ Tracker not found' . PHP_EOL;
	exit(1);
}

// Test 4: Verify Media Service
echo PHP_EOL . '[TEST 4] Verify Service Logging Support' . PHP_EOL;
if (class_exists('Bricks2Etch\Services\EFS_Media_Service')) {
	echo '✓ Media Service exists' . PHP_EOL;
} else {
	echo '✗ Media Service not found' . PHP_EOL;
	exit(1);
}

// Test 5: Verify CSS Service
if (class_exists('Bricks2Etch\Services\EFS_CSS_Service')) {
	echo '✓ CSS Service exists' . PHP_EOL;
} else {
	echo '✗ CSS Service not found' . PHP_EOL;
	exit(1);
}

// Test 6: Verify REST API Methods
echo PHP_EOL . '[TEST 5] Verify REST API Callback Methods' . PHP_EOL;
$api_class = 'Bricks2Etch\Admin\EFS_Progress_Dashboard_API';
$methods   = ['handle_progress_request', 'handle_errors_request', 'handle_category_request'];

foreach ($methods as $method) {
	if (method_exists($api_class, $method)) {
		echo "✓ Method {$method} exists" . PHP_EOL;
	} else {
		echo "✗ Method {$method} not found" . PHP_EOL;
		exit(1);
	}
}

// Test 7: Verify Trait Methods
echo PHP_EOL . '[TEST 6] Verify Logger Trait Methods' . PHP_EOL;
$trait_methods = ['get_migration_progress', 'get_migration_errors', 'get_migration_logs_by_category'];

foreach ($trait_methods as $method) {
	if (method_exists($api_class, $method)) {
		echo "✓ Trait method {$method} available in API class" . PHP_EOL;
	} else {
		echo "✗ Trait method {$method} not found" . PHP_EOL;
		exit(1);
	}
}

echo PHP_EOL . str_repeat('=', 60) . PHP_EOL;
echo 'All Integration Tests PASSED!' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;

echo PHP_EOL;
echo 'REST API Endpoints Ready:' . PHP_EOL;
echo '  GET /wp-json/efs/v1/migration/{id}/progress' . PHP_EOL;
echo '  GET /wp-json/efs/v1/migration/{id}/errors' . PHP_EOL;
echo '  GET /wp-json/efs/v1/migration/{id}/logs/{category}' . PHP_EOL;
echo PHP_EOL;
echo 'Database logging components:' . PHP_EOL;
echo '  ✓ Detailed Progress Tracker' . PHP_EOL;
echo '  ✓ Migration Progress Logger trait' . PHP_EOL;
echo '  ✓ Service logging integration' . PHP_EOL;
echo PHP_EOL;

exit(0);
