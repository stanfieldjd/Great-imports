<?php
/**
 * Plugin Name: Great Imports
 * Plugin URI: https://www.chattanoogamusicscene.com/
 * Description: URL, feed, and file event importer with review, recurring sources, duplicate protection, and direct Events Manager synchronization.
 * Version: 4.9.9
 * Author: Chattanooga Music Scene
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: great-imports
 */

defined( 'ABSPATH' ) || exit;

define( 'GI_VERSION', '4.9.9' );
define( 'GI_FILE', __FILE__ );
define( 'GI_DIR', plugin_dir_path( __FILE__ ) );
define( 'GI_URL', plugin_dir_url( __FILE__ ) );

require_once GI_DIR . 'includes/class-gi-utils.php';
require_once GI_DIR . 'includes/class-gi-storage.php';
require_once GI_DIR . 'includes/class-gi-content-filter.php';
require_once GI_DIR . 'includes/class-gi-collector.php';
require_once GI_DIR . 'includes/class-gi-normalizer.php';
require_once GI_DIR . 'includes/class-gi-events-manager.php';
require_once GI_DIR . 'includes/class-gi-runner.php';
require_once GI_DIR . 'includes/class-gi-scheduler.php';
require_once GI_DIR . 'includes/class-gi-admin.php';
require_once GI_DIR . 'includes/class-gi-plugin.php';

register_activation_hook( __FILE__, array( 'GI_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GI_Plugin', 'deactivate' ) );

GI_Plugin::instance()->boot();
