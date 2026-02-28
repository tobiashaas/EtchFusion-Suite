<?php
/**
 * Phase 1–5 Fixes Verification Script
 *
 * Verifies that all code-level changes from the stabilisation phases are present
 * and behave correctly in a live WordPress environment.
 *
 * Run inside Docker with:
 *   npm run wp -- eval "require WP_PLUGIN_DIR.'/etch-fusion-suite/tests/phase-fixes-verification.php';"
 *
 * Expected output: all checks PASS, final line "All checks passed."
 *
 * @package Bricks2Etch\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pass  = 0;
$fail  = 0;
$notes = array();

/**
 * Assert a condition and print the result.
 *
 * @param bool   $condition
 * @param string $description
 * @param string $detail     Additional detail shown on failure.
 */
function efs_verify( bool $condition, string $description, string $detail = '' ): void {
	global $pass, $fail;
	if ( $condition ) {
		echo '  [PASS] ' . $description . "\n";
		++$pass;
	} else {
		echo '  [FAIL] ' . $description . ( $detail ? ': ' . $detail : '' ) . "\n";
		++$fail;
	}
}

echo "\n=== Etch Fusion Suite — Phase Fixes Verification ===\n\n";

// =============================================================================
// Phase 1: Action Scheduler autoloader
// =============================================================================

echo "--- Phase 1: Action Scheduler Autoloader ---\n";

// The AS global classes must be loadable (plugin must be active for this).
efs_verify(
	class_exists( 'ActionScheduler' ),
	'ActionScheduler global class is loadable'
);
efs_verify(
	class_exists( 'ActionScheduler_Store' ),
	'ActionScheduler_Store global class is loadable'
);

// Autoloader for vendor-prefixed AS namespace must be registered.
$loaders          = spl_autoload_functions();
$efs_as_loader_ok = false;
foreach ( $loaders as $loader ) {
	if ( is_string( $loader ) && false !== strpos( $loader, 'efs_autoload_action_scheduler' ) ) {
		$efs_as_loader_ok = true;
		break;
	}
	if ( is_array( $loader ) && isset( $loader[1] ) && 'efs_autoload_action_scheduler' === $loader[1] ) {
		$efs_as_loader_ok = true;
		break;
	}
}
efs_verify( $efs_as_loader_ok, 'efs_autoload_action_scheduler is registered as SPL autoloader' );

// The vendor-prefixed AS Migration\Controller class must resolve to lowercase path.
$class_file = ETCH_FUSION_SUITE_DIR . 'vendor-prefixed/woocommerce/action-scheduler/classes/migration/Controller.php';
efs_verify(
	file_exists( $class_file ),
	'Action Scheduler migration/Controller.php exists at lowercase path'
);

// =============================================================================
// Phase 2: PSR-4 autoloader
// =============================================================================

echo "\n--- Phase 2: PSR-4 Autoloader ---\n";

$core_classes = array(
	'Bricks2Etch\\Core\\EFS_Error_Handler',
	'Bricks2Etch\\Parsers\\EFS_CSS_Converter',
	'Bricks2Etch\\CSS\\EFS_CSS_Normalizer',
	'Bricks2Etch\\CSS\\EFS_Breakpoint_Resolver',
	'Bricks2Etch\\CSS\\EFS_ACSS_Handler',
	'Bricks2Etch\\CSS\\EFS_Settings_CSS_Converter',
	'Bricks2Etch\\CSS\\EFS_CSS_Stylesheet_Parser',
	'Bricks2Etch\\CSS\\EFS_Class_Reference_Scanner',
	'Bricks2Etch\\CSS\\EFS_Element_ID_Style_Collector',
	'Bricks2Etch\\CSS\\EFS_Style_Importer',
	'Bricks2Etch\\Migrators\\EFS_Migrator_Registry',
	'Bricks2Etch\\Migrators\\EFS_Migrator_Discovery',
	'Bricks2Etch\\Services\\EFS_Batch_Processor',
	'Bricks2Etch\\Services\\EFS_Batch_Phase_Runner',
	'Bricks2Etch\\Services\\EFS_Async_Migration_Runner',
	'Bricks2Etch\\Services\\EFS_Background_Spawn_Handler',
);

$all_classes_ok = true;
foreach ( $core_classes as $class ) {
	if ( ! class_exists( $class ) ) {
		efs_verify( false, "Class {$class} is loadable" );
		$all_classes_ok = false;
	}
}
if ( $all_classes_ok ) {
	efs_verify( true, 'All 16 core classes autoload correctly' );
}

// =============================================================================
// Phase 3: Migrator system fixes
// =============================================================================

echo "\n--- Phase 3: Migrator System ---\n";

// Null-guard in EFS_Batch_Processor::process_batch().
$batch_processor_file = ETCH_FUSION_SUITE_DIR . 'includes/services/class-batch-processor.php';
$bp_content           = file_get_contents( $batch_processor_file );
efs_verify(
	false !== strpos( $bp_content, "'no_batch_phase_runner'" ),
	'Null-guard for $batch_phase_runner present in EFS_Batch_Processor'
);

// Dead variables removed from batch-phase-runner.
$bpr_file    = ETCH_FUSION_SUITE_DIR . 'includes/services/class-batch-phase-runner.php';
$bpr_content = file_get_contents( $bpr_file );
efs_verify(
	false === strpos( $bpr_content, '$is_cron_context' ),
	'Dead variable $is_cron_context removed from EFS_Batch_Phase_Runner'
);
efs_verify(
	false === strpos( $bpr_content, '$is_ajax_context' ),
	'Dead variable $is_ajax_context removed from EFS_Batch_Phase_Runner'
);

// Registry resilience: try/catch in get_all().
$registry_file    = ETCH_FUSION_SUITE_DIR . 'includes/migrators/class-migrator-registry.php';
$registry_content = file_get_contents( $registry_file );
efs_verify(
	false !== strpos( $registry_content, 'get_priority() threw on' ),
	'Try/catch guard for get_priority() present in EFS_Migrator_Registry::get_all()'
);
efs_verify(
	false !== strpos( $registry_content, 'supports() threw on' ),
	'Try/catch guard for supports() present in EFS_Migrator_Registry::get_supported()'
);

// Discovery resilience: try/catch around require_once.
$discovery_file    = ETCH_FUSION_SUITE_DIR . 'includes/migrators/class-migrator-discovery.php';
$discovery_content = file_get_contents( $discovery_file );
efs_verify(
	false !== strpos( $discovery_content, 'EFS_Migrator_Discovery: failed to include' ),
	'Try/catch around require_once present in EFS_Migrator_Discovery::auto_discover_from_directory()'
);

// =============================================================================
// Phase 4: CSS module tests exist
// =============================================================================

echo "\n--- Phase 4: CSS Converter Tests ---\n";

efs_verify(
	file_exists( ETCH_FUSION_SUITE_DIR . 'tests/unit/CSS/CssNormalizerTest.php' ),
	'CssNormalizerTest.php exists'
);
efs_verify(
	file_exists( ETCH_FUSION_SUITE_DIR . 'tests/unit/CSS/BreakpointResolverTest.php' ),
	'BreakpointResolverTest.php exists'
);

// Spot-check: EFS_CSS_Normalizer correctly converts margin-top → margin-block-start.
$normalizer = new \Bricks2Etch\CSS\EFS_CSS_Normalizer();
$logical    = $normalizer->convert_to_logical_properties( '.el { margin-top: 10px; }' );
efs_verify(
	false !== strpos( $logical, 'margin-block-start' ),
	'EFS_CSS_Normalizer: margin-top converted to margin-block-start'
);

// Spot-check: Bricks ID selector renamed.
$renamed = $normalizer->normalize_bricks_id_selectors_in_css( '#brxe-abc { color: red; }' );
efs_verify(
	false !== strpos( $renamed, '#etch-abc' ) && false === strpos( $renamed, '#brxe-' ),
	'EFS_CSS_Normalizer: #brxe-abc renamed to #etch-abc'
);

// Spot-check: BreakpointResolver default map.
$resolver  = new \Bricks2Etch\CSS\EFS_Breakpoint_Resolver();
$map       = $resolver->get_breakpoint_width_map();
$map_keys  = array_keys( $map );
efs_verify(
	'desktop' === $map_keys[0],
	'EFS_Breakpoint_Resolver: desktop is first in default map'
);
efs_verify(
	isset( $map['tablet_portrait'] ) && 'max' === $map['tablet_portrait']['type'],
	'EFS_Breakpoint_Resolver: tablet_portrait has type=max'
);

// Spot-check: media condition normalisation.
$condition = $resolver->normalize_media_condition_to_etch( '(min-width: 1200px)' );
efs_verify(
	'(width >= to-rem(1200px))' === $condition,
	'EFS_Breakpoint_Resolver: (min-width: 1200px) → (width >= to-rem(1200px))'
);

// =============================================================================
// Phase 5: Error codes and debug logging
// =============================================================================

echo "\n--- Phase 5: Error Codes & Logging ---\n";

$expected_error_codes = array( 'E106', 'E108', 'E905', 'E906', 'E907' );
$error_codes          = \Bricks2Etch\Core\EFS_Error_Handler::ERROR_CODES;
foreach ( $expected_error_codes as $code ) {
	efs_verify(
		isset( $error_codes[ $code ] ),
		"Error code {$code} is defined in EFS_Error_Handler::ERROR_CODES"
	);
}

$expected_warning_codes = array( 'W013', 'W401', 'W900' );
$warning_codes          = \Bricks2Etch\Core\EFS_Error_Handler::WARNING_CODES;
foreach ( $expected_warning_codes as $code ) {
	efs_verify(
		isset( $warning_codes[ $code ] ),
		"Warning code {$code} is defined in EFS_Error_Handler::WARNING_CODES"
	);
}

// Verify none of the new codes falls back to "Unknown".
$error_handler = new \Bricks2Etch\Core\EFS_Error_Handler();
$w013_info     = $error_handler->get_warning_info( 'W013' );
efs_verify(
	is_array( $w013_info ) && 'Unknown Warning' !== $w013_info['title'],
	'W013 get_warning_info() returns a real title (not "Unknown Warning")'
);
$e906_info = $error_handler->get_error_info( 'E906' );
efs_verify(
	is_array( $e906_info ) && 'Unknown Error' !== $e906_info['title'],
	'E906 get_error_info() returns a real title (not "Unknown Error")'
);

// Dead variables removed from async-migration-runner.
$amr_file    = ETCH_FUSION_SUITE_DIR . 'includes/services/class-async-migration-runner.php';
$amr_content = file_get_contents( $amr_file );
efs_verify(
	false === strpos( $amr_content, '$is_cron_context' ),
	'Dead variable $is_cron_context removed from EFS_Async_Migration_Runner'
);
efs_verify(
	false === strpos( $amr_content, '$is_ajax_context' ),
	'Dead variable $is_ajax_context removed from EFS_Async_Migration_Runner'
);

// debug_log for successful spawn is present.
$spawn_file    = ETCH_FUSION_SUITE_DIR . 'includes/services/class-background-spawn-handler.php';
$spawn_content = file_get_contents( $spawn_file );
efs_verify(
	false !== strpos( $spawn_content, "'Background spawn accepted'" ),
	'debug_log for successful background spawn present in EFS_Background_Spawn_Handler'
);

// =============================================================================
// Summary
// =============================================================================

echo "\n=== Summary ===\n";
echo "  PASS: {$pass}\n";
echo "  FAIL: {$fail}\n";

if ( 0 === $fail ) {
	echo "\nAll checks passed.\n\n";
} else {
	echo "\n{$fail} check(s) FAILED — review output above.\n\n";
}
