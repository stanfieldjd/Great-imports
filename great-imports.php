<?php
/**
 * Plugin Name: Great Imports
 * Description: Full evidence-first Eventbrite importer for collecting review candidates and import previews.
 * Version: 0.2.2
 * Author: Great Imports
 * Text Domain: great-imports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GREAT_IMPORTS_VERSION', '0.2.2' );
define( 'GREAT_IMPORTS_FILE', __FILE__ );
define( 'GREAT_IMPORTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'GREAT_IMPORTS_URL', plugin_dir_url( __FILE__ ) );

require_once GREAT_IMPORTS_DIR . 'includes/class-gi-plugin.php';

GI_Plugin::instance()->boot();
