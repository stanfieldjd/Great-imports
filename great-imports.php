<?php
/**
 * Plugin Name: Great Imports
 * Description: One-time Eventbrite URL importer for collecting event review candidates.
 * Version: 0.1.1
 * Author: Great Imports
 * Text Domain: great-imports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GREAT_IMPORTS_VERSION', '0.1.1' );
define( 'GREAT_IMPORTS_FILE', __FILE__ );
define( 'GREAT_IMPORTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'GREAT_IMPORTS_URL', plugin_dir_url( __FILE__ ) );

require_once GREAT_IMPORTS_DIR . 'includes/class-gi-plugin.php';

GI_Plugin::instance()->boot();
