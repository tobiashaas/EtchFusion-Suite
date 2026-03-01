<?php
/**
 * Show Current vs Proposed Progress Tracking Detail Level
 *
 * Demonstrates:
 * 1. Current tracking: Items count (10/45)
 * 2. Proposed detail tracking: Individual items with metadata
 *
 * Run: wp eval "require WP_PLUGIN_DIR.'/etch-fusion-suite/tests/show-progress-tracking-detail.php';"
 */

require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/core/class-db-installer.php';
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/repositories/class-db-migration-persistence.php';
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/repositories/class-wordpress-migration-repository.php';

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║        PROGRESS TRACKING - CURRENT vs PROPOSED LEVEL           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Setup
\Bricks2Etch\Core\EFS_DB_Installer::install();
$migration_id = 'progress-demo-' . wp_generate_uuid4();
$repo = new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();
$db_persist = new \Bricks2Etch\Repositories\EFS_DB_Migration_Persistence();

echo "Migration ID: $migration_id\n\n";

// ============================================================================
// CURRENT TRACKING LEVEL
// ============================================================================
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║           CURRENT TRACKING LEVEL (Aggregated)                 ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "What we currently store:\n";
echo "─────────────────────────────────────────────────────────────────\n";

// Initialize
$progress = array(
	'migrationId'       => $migration_id,
	'status'            => 'running',
	'current_step'      => 'content',
	'percentage'        => 0,
	'processedItems'    => 0,
	'totalItems'        => 45,  // Total posts to migrate
	'message'           => 'Starting post migration...',
);
$repo->save_progress( $progress );

echo "\n1️⃣  AGGREGATE PROGRESS\n";
echo "   • Total items: 45\n";
echo "   • Processed items: 0\n";
echo "   • Progress: 0%\n";
echo "   • Message: \"Starting post migration...\"\n";
echo "   ⚠️  Do NOT see: Which posts? Post titles? Status per post?\n\n";

// Simulate processing
for ( $i = 1; $i <= 45; $i++ ) {
	$pct = intval( ( $i / 45 ) * 100 );
	
	$progress['percentage']     = $pct;
	$progress['processedItems'] = $i;
	$progress['message']        = "Migrating posts... {$pct}%";
	$repo->save_progress( $progress );

	// Log only milestone events
	if ( 0 === $i % 15 || $i === 45 ) {
		$db_persist->log_event(
			$migration_id,
			'info',
			"Processed {$i}/45 posts",
			'content',
			array(
				'processed' => $i,
				'total'     => 45,
				'percentage' => $pct,
			)
		);
	}
}

echo "2️⃣  FINAL STATE (After all 45 posts)\n";
$final = $repo->get_progress();
echo "   • Processed items: " . $final['processedItems'] . "/45\n";
echo "   • Progress: " . $final['percentage'] . "%\n";
echo "   • Status: " . $final['status'] . "\n";
echo "   ⚠️  Still do NOT see: Individual post details\n\n";

echo "────────────────────────────────────────────────────────────────\n";
echo "Current Audit Trail (Milestones only):\n";
$audit = $db_persist->get_audit_trail( $migration_id );
foreach ( $audit as $i => $event ) {
	$num = count( $audit ) - $i;
	echo "  [$num] {$event['log_level']}: {$event['message']}\n";
}

// ============================================================================
// PROPOSED DETAILED TRACKING
// ============================================================================
echo "\n\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║          PROPOSED TRACKING LEVEL (Detailed)                   ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "What COULD be tracked with additional logging:\n";
echo "─────────────────────────────────────────────────────────────────\n\n";

echo "1️⃣  INDIVIDUAL POST EVENTS\n";
echo "   Each post migration logged separately:\n\n";

$posts = array(
	array( 'id' => 1, 'title' => 'Welcome to Bricks Builder' ),
	array( 'id' => 2, 'title' => 'How to Migrate Your Site' ),
	array( 'id' => 3, 'title' => 'Advanced CSS Techniques' ),
	array( 'id' => 4, 'title' => 'Gutenberg Basics' ),
	array( 'id' => 5, 'title' => 'Custom Fields with ACF' ),
);

foreach ( $posts as $post ) {
	$post_data = array(
		'post_id'           => $post['id'],
		'title'             => $post['title'],
		'post_type'         => 'post',
		'blocks_converted'  => rand( 5, 25 ),
		'custom_fields'     => rand( 0, 8 ),
		'media_references'  => rand( 0, 5 ),
		'duration_ms'       => rand( 100, 500 ),
		'status'            => 'migrated',
	);

	echo "   ✓ Post #{$post['id']}: \"{$post['title']}\"\n";
	echo "      - Blocks converted: {$post_data['blocks_converted']}\n";
	echo "      - Custom fields: {$post_data['custom_fields']}\n";
	echo "      - Media refs: {$post_data['media_references']}\n";
	echo "      - Duration: {$post_data['duration_ms']}ms\n";
	echo "      - Status: migrated ✓\n\n";

	// Log this as individual event
	$db_persist->log_event(
		$migration_id,
		'info',
		"Post migrated: \"{$post['title']}\" ({$post_data['blocks_converted']} blocks)",
		'content_post_migrated',
		$post_data
	);
}

echo "   ... (40 more posts logged individually)\n\n";

echo "2️⃣  REAL-TIME PROGRESS WITH DETAILS\n";
echo "   Dashboard could show:\n\n";
echo "   Progress: [████████░░░░░░░░░░░░░░░░░░░░] 37% (45/122)\n\n";
echo "   Currently Processing:\n";
echo "   └─ Post #42: \"Site Architecture Guide\"\n";
echo "      Status: Converting blocks... 8/15 completed\n";
echo "      Elapsed: 2.3 seconds\n";
echo "      Est. Time: +1.2 seconds\n\n";
echo "   Last 5 Completed:\n";
echo "   ✓ Post #41: \"Responsive Design Tips\" (12 blocks)\n";
echo "   ✓ Post #40: \"Performance Optimization\" (8 blocks)\n";
echo "   ✓ Post #39: \"SEO Best Practices\" (6 blocks)\n";
echo "   ✓ Post #38: \"Content Strategy\" (15 blocks)\n";
echo "   ✓ Post #37: \"Analytics Setup\" (4 blocks)\n\n";

echo "3️⃣  ERROR TRACKING WITH DETAIL\n";
echo "   If a post fails, log exactly what went wrong:\n\n";

$failed_post = array(
	'post_id'      => 15,
	'title'        => 'Complex Bricks Template',
	'error'        => 'unsupported_block_type',
	'error_detail' => 'Bricks form element not supported in Etch',
	'attempted_blocks' => array(
		'bricks-form' => 'custom',
		'bricks-slider' => 'not_found',
		'bricks-query-loop' => 'partially_supported',
	),
	'action'       => 'requires_manual_review',
);

echo "   ✗ Post #" . $failed_post['post_id'] . ": \"" . $failed_post['title'] . "\"\n";
echo "      Error: " . $failed_post['error_detail'] . "\n";
echo "      Blocks with issues:\n";
foreach ( $failed_post['attempted_blocks'] as $block => $status ) {
	echo "        - $block: $status\n";
}
echo "      Action: Manual review required\n\n";

$db_persist->log_event(
	$migration_id,
	'error',
	"Post failed: \"{$failed_post['title']}\" - {$failed_post['error_detail']}",
	'content_post_failed',
	$failed_post
);

echo "────────────────────────────────────────────────────────────────\n\n";

// ============================================================================
// COMPARISON TABLE
// ============================================================================
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║              COMPARISON: CURRENT vs PROPOSED                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$comparison = array(
	array(
		'Metric',
		'Current (Aggregate)',
		'Proposed (Detailed)',
	),
	array( '─────────────────────', '──────────────────────', '──────────────────────' ),
	array(
		'Items Count',
		'✓ 45/45',
		'✓ 45/45 + per-item detail',
	),
	array(
		'Progress %',
		'✓ 87%',
		'✓ 87% + per-item status',
	),
	array(
		'Individual Post Info',
		'✗ NO',
		'✓ YES - Title, blocks, fields',
	),
	array(
		'Error Per Post',
		'✗ NO',
		'✓ YES - What failed & why',
	),
	array(
		'Performance Metrics',
		'✗ NO',
		'✓ YES - Duration per post',
	),
	array(
		'Current Item Being Processed',
		'✗ NO',
		'✓ YES - Real-time details',
	),
	array(
		'Last N Completed Items',
		'✗ NO',
		'✓ YES - Audit trail',
	),
	array(
		'Dashboard Richness',
		'⚠️  Basic progress bar',
		'✓ Full item detail + status',
	),
	array(
		'Debuggability',
		'⚠️  Limited',
		'✓ Complete audit trail',
	),
	array(
		'Database Size Impact',
		'Minimal',
		'Moderate (45 posts = 45 events)',
	),
);

foreach ( $comparison as $row ) {
	if ( strpos( $row[0], '─────' ) === 0 ) {
		echo str_pad( $row[0], 23 ) . ' ' . str_pad( $row[1], 24 ) . ' ' . $row[2] . "\n";
	} else {
		echo str_pad( $row[0], 23 ) . ' ' . str_pad( $row[1], 24 ) . ' ' . $row[2] . "\n";
	}
}

echo "\n\n";

// ============================================================================
// IMPLEMENTATION SUGGESTION
// ============================================================================
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          HOW TO IMPLEMENT DETAILED TRACKING                   ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "In the Content Service, after converting each post:\n\n";
echo "```php\n";
echo "foreach ( \$posts as \$post ) {\n";
echo "    try {\n";
echo "        \$result = \$this->convert_bricks_to_gutenberg( \$post->ID, ... );\n";
echo "        \n";
echo "        // Log individual post success\n";
echo "        \$db_persist->log_event(\n";
echo "            \$migration_id,\n";
echo "            'info',\n";
echo "            \"Post migrated: \\\"\$post->post_title\\\"\",\n";
echo "            'content_post_migrated',\n";
echo "            array(\n";
echo "                'post_id'        => \$post->ID,\n";
echo "                'title'          => \$post->post_title,\n";
echo "                'blocks_converted' => \$result['blocks_count'],\n";
echo "                'custom_fields'  => \$result['fields_count'],\n";
echo "                'duration_ms'    => \$result['duration_ms'],\n";
echo "            )\n";
echo "        );\n";
echo "    } catch ( Exception \$e ) {\n";
echo "        // Log individual post failure\n";
echo "        \$db_persist->log_event(\n";
echo "            \$migration_id,\n";
echo "            'error',\n";
echo "            \"Post failed: \\\"\$post->post_title\\\" - \" . \$e->getMessage(),\n";
echo "            'content_post_failed',\n";
echo "            array(\n";
echo "                'post_id' => \$post->ID,\n";
echo "                'error'   => \$e->getMessage(),\n";
echo "            )\n";
echo "        );\n";
echo "    }\n";
echo "}\n";
echo "```\n\n";

echo "Result: Dashboard sees every post migration in real-time! ✓\n\n";

// ============================================================================
// SUMMARY
// ============================================================================
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                        SUMMARY                                 ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "CURRENT STATE:\n";
echo "✓ Aggregate tracking works (10/45 items processed)\n";
echo "✓ Milestone logging (25%, 50%, 75%, 100%)\n";
echo "✓ Error message captured\n";
echo "✗ Individual post titles NOT logged\n";
echo "✗ Per-post error details NOT available\n";
echo "✗ Performance metrics per post NOT tracked\n\n";

echo "WHAT'S NEEDED FOR FULL DETAIL:\n";
echo "□ Log each post migration as separate event\n";
echo "□ Include post title in event\n";
echo "□ Track blocks converted, fields, duration per post\n";
echo "□ Log detailed error info per post\n";
echo "□ Dashboard queries detailed logs for current item display\n\n";

echo "DATABASE IMPACT:\n";
echo "✓ Already supports detailed logging (context JSON column)\n";
echo "✓ Migration logging table designed for this\n";
echo "→ Just need to call log_event() for each post\n";
echo "→ Creates ~45 additional log entries (acceptable)\n\n";

echo "✅ INFRASTRUCTURE IS READY - JUST NEEDS SERVICE LAYER INTEGRATION\n\n";
