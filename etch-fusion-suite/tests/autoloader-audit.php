<?php
/**
 * Autoloader audit script — resolves all known Bricks2Etch\ classes offline.
 *
 * Usage: php tests/autoloader-audit.php
 */

$prefix   = 'Bricks2Etch\\';
$base_dir = __DIR__ . '/../includes/';

$namespace_map = [
    'Container\\'                => 'container/',
    'Services\\Interfaces\\'     => 'services/interfaces/',
    'Services\\'                 => 'services/',
    'Repositories\\Interfaces\\' => 'repositories/interfaces/',
    'Repositories\\'             => 'repositories/',
    'Core\\'                     => '',
    'Api\\'                      => '',
    'Updater\\'                  => 'updater/',
    'Controllers\\'              => 'controllers/',
    'Admin\\'                    => 'admin/',
    'Ajax\\Handlers\\'           => 'ajax/handlers/',
    'Ajax\\'                     => 'ajax/',
    'CSS\\'                      => 'css/',
    'Parsers\\'                  => '',
    'Templates\\Interfaces\\'    => 'templates/interfaces/',
    'Templates\\'                => 'templates/',
    'Migrators\\Interfaces\\'    => 'migrators/interfaces/',
    'Migrators\\'                => '',
    'Converters\\Interfaces\\'   => 'converters/interfaces/',
    'Converters\\Elements\\'     => 'converters/elements/',
    'Converters\\'               => 'converters/',
    'Security\\'                 => 'security/',
];

$classes = [
    // Container
    'Bricks2Etch\\Container\\EFS_Service_Container',
    'Bricks2Etch\\Container\\EFS_Service_Provider',
    // Services\Interfaces
    'Bricks2Etch\\Services\\Interfaces\\Phase_Handler_Interface',
    // Services
    'Bricks2Etch\\Services\\EFS_Action_Scheduler_Loopback_Runner',
    'Bricks2Etch\\Services\\EFS_Async_Migration_Runner',
    'Bricks2Etch\\Services\\EFS_Background_Spawn_Handler',
    'Bricks2Etch\\Services\\EFS_Batch_Phase_Runner',
    'Bricks2Etch\\Services\\EFS_Batch_Processor',
    'Bricks2Etch\\Services\\EFS_Content_Service',
    'Bricks2Etch\\Services\\EFS_CSS_Service',
    'Bricks2Etch\\Services\\EFS_Headless_Migration_Job',
    'Bricks2Etch\\Services\\EFS_Media_Phase_Handler',
    'Bricks2Etch\\Services\\EFS_Media_Service',
    'Bricks2Etch\\Services\\EFS_Migration_ETA_Calculator',
    'Bricks2Etch\\Services\\EFS_Migration_Logger',
    'Bricks2Etch\\Services\\EFS_Migration_Orchestrator',
    'Bricks2Etch\\Services\\EFS_Migration_Run_Finalizer',
    'Bricks2Etch\\Services\\EFS_Migration_Service',
    'Bricks2Etch\\Services\\EFS_Migration_Starter',
    'Bricks2Etch\\Services\\EFS_Migrator_Executor',
    'Bricks2Etch\\Services\\EFS_Posts_Phase_Handler',
    'Bricks2Etch\\Services\\EFS_Pre_Flight_Checker',
    'Bricks2Etch\\Services\\EFS_Progress_Manager',
    'Bricks2Etch\\Services\\EFS_Steps_Manager',
    'Bricks2Etch\\Services\\EFS_Template_Extractor_Service',
    'Bricks2Etch\\Services\\EFS_Wizard_State_Service',
    // Repositories\Interfaces
    'Bricks2Etch\\Repositories\\Interfaces\\Checkpoint_Repository_Interface',
    'Bricks2Etch\\Repositories\\Interfaces\\Migration_Repository_Interface',
    'Bricks2Etch\\Repositories\\Interfaces\\Progress_Repository_Interface',
    'Bricks2Etch\\Repositories\\Interfaces\\Settings_Repository_Interface',
    'Bricks2Etch\\Repositories\\Interfaces\\Style_Repository_Interface',
    'Bricks2Etch\\Repositories\\Interfaces\\Token_Repository_Interface',
    // Repositories
    'Bricks2Etch\\Repositories\\EFS_Migration_Runs_Repository',
    'Bricks2Etch\\Repositories\\EFS_WordPress_Migration_Repository',
    'Bricks2Etch\\Repositories\\EFS_WordPress_Settings_Repository',
    'Bricks2Etch\\Repositories\\EFS_WordPress_Style_Repository',
    // Core
    'Bricks2Etch\\Core\\EFS_Error_Handler',
    'Bricks2Etch\\Core\\EFS_Migration_Manager',
    'Bricks2Etch\\Core\\EFS_Migration_Token_Manager',
    'Bricks2Etch\\Core\\EFS_Plugin_Detector',
    // Api
    'Bricks2Etch\\Api\\EFS_API_Client',
    'Bricks2Etch\\Api\\EFS_API_Endpoints',
    // Updater
    'Bricks2Etch\\Updater\\EFS_GitHub_Updater',
    // Controllers
    'Bricks2Etch\\Controllers\\EFS_Dashboard_Controller',
    'Bricks2Etch\\Controllers\\EFS_Migration_Controller',
    'Bricks2Etch\\Controllers\\EFS_Settings_Controller',
    'Bricks2Etch\\Controllers\\EFS_Template_Controller',
    // Admin
    'Bricks2Etch\\Admin\\EFS_Admin_Interface',
    'Bricks2Etch\\Admin\\EFS_Admin_Notice_Manager',
    // Ajax
    'Bricks2Etch\\Ajax\\EFS_Ajax_Handler',
    'Bricks2Etch\\Ajax\\EFS_Base_Ajax_Handler',
    // Ajax\Handlers
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Cleanup_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Connection_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Content_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_CSS_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Debug_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Logs_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Media_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Migration_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Pre_Flight_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Progress_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Template_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Validation_Ajax_Handler',
    'Bricks2Etch\\Ajax\\Handlers\\EFS_Wizard_Ajax_Handler',
    // CSS modules
    'Bricks2Etch\\CSS\\EFS_ACSS_Handler',
    'Bricks2Etch\\CSS\\EFS_Breakpoint_Resolver',
    'Bricks2Etch\\CSS\\EFS_Class_Reference_Scanner',
    'Bricks2Etch\\CSS\\EFS_CSS_Normalizer',
    'Bricks2Etch\\CSS\\EFS_CSS_Stylesheet_Parser',
    'Bricks2Etch\\CSS\\EFS_Element_ID_Style_Collector',
    'Bricks2Etch\\CSS\\EFS_Settings_CSS_Converter',
    'Bricks2Etch\\CSS\\EFS_Style_Importer',
    // Parsers (root-level files with non-standard names)
    'Bricks2Etch\\Parsers\\EFS_CSS_Converter',
    'Bricks2Etch\\Parsers\\EFS_Content_Parser',
    'Bricks2Etch\\Parsers\\EFS_Dynamic_Data_Converter',
    'Bricks2Etch\\Parsers\\EFS_Gutenberg_Generator',
    // Templates\Interfaces
    'Bricks2Etch\\Templates\\Interfaces\\EFS_HTML_Sanitizer_Interface',
    'Bricks2Etch\\Templates\\Interfaces\\EFS_Template_Analyzer_Interface',
    'Bricks2Etch\\Templates\\Interfaces\\EFS_Template_Extractor_Interface',
    // Templates
    'Bricks2Etch\\Templates\\EFS_Etch_Template_Generator',
    'Bricks2Etch\\Templates\\EFS_Framer_HTML_Sanitizer',
    'Bricks2Etch\\Templates\\EFS_Framer_Template_Analyzer',
    'Bricks2Etch\\Templates\\EFS_Framer_To_Etch_Converter',
    'Bricks2Etch\\Templates\\EFS_HTML_Parser',
    'Bricks2Etch\\Templates\\EFS_HTML_Sanitizer',
    'Bricks2Etch\\Templates\\EFS_Template_Analyzer',
    // Migrators\Interfaces
    'Bricks2Etch\\Migrators\\Interfaces\\Migrator_Interface',
    // Migrators (some in root, some in migrators/)
    'Bricks2Etch\\Migrators\\Abstract_Migrator',
    'Bricks2Etch\\Migrators\\EFS_Component_Migrator',
    'Bricks2Etch\\Migrators\\EFS_Migrator_Discovery',
    'Bricks2Etch\\Migrators\\EFS_Migrator_Registry',
    'Bricks2Etch\\Migrators\\EFS_ACF_Field_Groups_Migrator',
    'Bricks2Etch\\Migrators\\EFS_CPT_Migrator',
    'Bricks2Etch\\Migrators\\EFS_Custom_Fields_Migrator',
    'Bricks2Etch\\Migrators\\EFS_Media_Migrator',
    'Bricks2Etch\\Migrators\\EFS_MetaBox_Migrator',
    // Converters\Interfaces
    'Bricks2Etch\\Converters\\Interfaces\\Needs_Error_Handler',
    // Converters
    'Bricks2Etch\\Converters\\EFS_Base_Element',
    'Bricks2Etch\\Converters\\EFS_Converter_Discovery',
    'Bricks2Etch\\Converters\\EFS_Converter_Registry',
    'Bricks2Etch\\Converters\\EFS_Element_Factory',
    // Converters\Elements
    'Bricks2Etch\\Converters\\Elements\\EFS_Button_Converter',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Code',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Component',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Condition',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Container',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Div',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Heading',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Html',
    'Bricks2Etch\\Converters\\Elements\\EFS_Icon_Converter',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Image',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Notes',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Paragraph',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Section',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Shortcode',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Svg',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Text',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_TextLink',
    'Bricks2Etch\\Converters\\Elements\\EFS_Element_Video',
    // Security
    'Bricks2Etch\\Security\\EFS_Audit_Logger',
    'Bricks2Etch\\Security\\EFS_CORS_Manager',
    'Bricks2Etch\\Security\\EFS_Environment_Detector',
    'Bricks2Etch\\Security\\EFS_Input_Validator',
    'Bricks2Etch\\Security\\EFS_Rate_Limiter',
    'Bricks2Etch\\Security\\EFS_Security_Headers',
];

/**
 * Mirrors the autoloader resolution logic from includes/autoloader.php.
 *
 * @param string $fqcn       Fully-qualified class name.
 * @param string $prefix     Namespace prefix.
 * @param string $base_dir   Base directory (includes/).
 * @param array  $namespace_map Namespace → subdirectory mapping.
 * @return string|null Resolved relative path, or null if not found.
 */
function resolve_class( $fqcn, $prefix, $base_dir, $namespace_map ) {
    $relative_class = substr( $fqcn, strlen( $prefix ) );
    foreach ( $namespace_map as $namespace => $dir ) {
        if ( strpos( $relative_class, $namespace ) !== 0 ) {
            continue;
        }
        $rn   = substr( $relative_class, strlen( $namespace ) );
        $slug = strtolower( str_replace( '_', '-', $rn ) );
        $slug_np  = preg_replace( '/^(?:b2e|efs)-/', '', $slug );
        $slug_tr  = preg_replace( '/^(?:b2e|efs)-(?:element-)?/', '', $slug );
        $us       = strtolower( $rn );
        $us_np    = preg_replace( '/^(?:b2e|efs)_/', '', $us );
        $slug_ns  = preg_replace( '/-interface$/', '', $slug );
        $slug_npns = preg_replace( '/-interface$/', '', $slug_np );

        $cands = [ "class-{$slug}.php", "{$slug}.php", "class-{$us}.php", "{$us}.php", "interface-{$slug}.php" ];

        if ( $slug_np !== $slug )      { $cands[] = "class-{$slug_np}.php"; $cands[] = "{$slug_np}.php"; }
        if ( $us_np !== $us )          { $cands[] = "class-{$us_np}.php";   $cands[] = "{$us_np}.php"; }
        if ( $slug_ns !== $slug )      { $cands[] = "interface-{$slug_ns}.php"; }
        if ( $slug_npns !== $slug_np ) { $cands[] = "interface-{$slug_npns}.php"; }
        if ( $slug_tr !== $slug )      { $cands[] = "class-{$slug_tr}.php"; }
        if ( strpos( $slug, 'abstract-' ) === 0 ) { $cands[] = 'abstract-class-' . substr( $slug, 9 ) . '.php'; }
        if ( substr( $slug, -13 ) === '-ajax-handler' ) {
            $cands[] = 'class-' . substr( $slug_np, 0, -13 ) . '-ajax.php';
        }

        foreach ( array_unique( $cands ) as $f ) {
            if ( file_exists( $base_dir . $dir . $f ) ) {
                return $dir . $f;
            }
        }

        // Migrators fallback: try migrators/ subdirectory.
        if ( 'Migrators\\' === $namespace ) {
            foreach ( array_unique( $cands ) as $f ) {
                if ( file_exists( $base_dir . 'migrators/' . $f ) ) {
                    return 'migrators/' . $f;
                }
            }
        }

        break; // only check the first matching namespace
    }
    return null;
}

$not_found = [];
$found     = [];

foreach ( $classes as $fqcn ) {
    $path = resolve_class( $fqcn, $prefix, $base_dir, $namespace_map );
    if ( null === $path ) {
        $not_found[] = $fqcn;
    } else {
        $found[] = sprintf( '%-70s -> %s', $fqcn, $path );
    }
}

printf( "FOUND:     %d\n", count( $found ) );
printf( "NOT FOUND: %d\n\n", count( $not_found ) );

if ( $not_found ) {
    echo "=== MISSING CLASSES ===\n";
    foreach ( $not_found as $f ) {
        echo "  MISSING: {$f}\n";
    }
    echo "\n";
} else {
    echo "All classes resolved successfully.\n";
}

if ( $found ) {
    echo "=== RESOLVED ===\n";
    foreach ( $found as $f ) {
        echo "  OK  {$f}\n";
    }
}
