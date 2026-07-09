<?php
/**
 * Main plugin coordinator.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GREAT_IMPORTS_DIR . 'includes/class-gi-post-types.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-url-validator.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-http-client.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-jsonld-parser.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-candidate-store.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-eventbrite-importer.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-admin.php';

final class GI_Plugin {
    /** @var GI_Plugin|null */
    private static $instance = null;

    /**
     * Get the singleton instance.
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register plugin hooks.
     */
    public function boot() {
        add_action( 'init', array( 'GI_Post_Types', 'register' ) );

        if ( is_admin() ) {
            $admin = new GI_Admin(
                new GI_Eventbrite_Importer(
                    new GI_Url_Validator(),
                    new GI_Http_Client(),
                    new GI_Jsonld_Parser(),
                    new GI_Candidate_Store()
                )
            );
            $admin->register_hooks();
        }
    }
}
