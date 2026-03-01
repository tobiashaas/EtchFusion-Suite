<?php
/**
 * Plugin Uninstall Handler
 *
 * This file is executed when the plugin is deleted (not deactivated).
 * It completely removes all plugin data from the database.
 *
 * @package Etch_Fusion_Suite
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load our DB installer class
require_once __DIR__ . '/includes/core/class-db-installer.php';

// Clean up all plugin data
Bricks2Etch\Core\EFS_DB_Installer::uninstall();
