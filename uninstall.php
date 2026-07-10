<?php
/**
 * Great Imports uninstall cleanup.
 *
 * Manual cleanup is also available from the Great Imports admin page because
 * some hosting/plugin deletion flows do not reliably execute uninstall.php.
 *
 * @package GreatImports
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-gi-data-cleaner.php';

if ( class_exists( 'GI_Data_Cleaner' ) ) {
    GI_Data_Cleaner::cleanup();
}
