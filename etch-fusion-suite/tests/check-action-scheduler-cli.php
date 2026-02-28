<?php
/**
 * Check if Action Scheduler WP_CLI classes are loadable.
 */

$checks = [
    'EtchFusionSuite\\Vendor\\Action_Scheduler\\WP_CLI\\Action_Command',
    'EtchFusionSuite\\Vendor\\Action_Scheduler\\WP_CLI\\System_Command',
    'EtchFusionSuite\\Vendor\\Action_Scheduler\\WP_CLI\\Migration_Command',
    'EtchFusionSuite\\Vendor\\Action_Scheduler\\WP_CLI\\ProgressBar',
    'EtchFusionSuite\\Vendor\\Action_Scheduler\\Migration\\Controller',
    'EtchFusionSuite\\Vendor\\Action_Scheduler\\Migration\\Runner',
    'ActionScheduler',
    'ActionScheduler_QueueRunner',
    'ActionScheduler_WPCLI_Scheduler_command',
];

foreach ( $checks as $class ) {
    $exists = class_exists( $class ) || interface_exists( $class );
    printf( "%-70s %s\n", $class, $exists ? 'OK' : 'MISSING' );
}

// Check registered autoloaders
echo "\nRegistered autoloaders:\n";
foreach ( spl_autoload_functions() as $fn ) {
    if ( is_array( $fn ) ) {
        echo '  [' . get_class( $fn[0] ) . '::' . $fn[1] . "]\n";
    } elseif ( is_string( $fn ) ) {
        echo '  ' . $fn . "\n";
    } else {
        echo "  [closure]\n";
    }
}

// Check file existence
$base = WP_PLUGIN_DIR . '/etch-fusion-suite/vendor-prefixed/woocommerce/action-scheduler/classes/';
$files = [
    'WP_CLI/Action_Command.php',
    'WP_CLI/System_Command.php',
    'WP_CLI/Migration_Command.php',
    'WP_CLI/ProgressBar.php',
    'migration/Controller.php',
];
echo "\nFile existence check:\n";
foreach ( $files as $f ) {
    printf( "  %-40s %s\n", $f, file_exists( $base . $f ) ? 'EXISTS' : 'MISSING' );
}
