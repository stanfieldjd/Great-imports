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
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-http-evidence-client.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-html-evidence-extractor.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-jsonld-parser.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-candidate-store.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-candidate-review.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-evidence-store.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-eventbrite-api-client.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-eventbrite-api-normalizer.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-import-preview-builder.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-page-display-report-builder.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-source-coverage-audit-builder.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-data-cleaner.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-eventbrite-importer.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-exploratory-report.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-candidate-list-table.php';
require_once GREAT_IMPORTS_DIR . 'includes/class-gi-em-importer.php';
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
        add_action( 'admin_init', array( $this, 'repair_events_manager_single_event_format' ) );

        if ( is_admin() ) {
            $api_client       = new GI_Eventbrite_API_Client();
            $evidence_store   = new GI_Evidence_Store();
            $preview_builder  = new GI_Import_Preview_Builder();
            $display_builder  = new GI_Page_Display_Report_Builder();
            $coverage_auditor = new GI_Source_Coverage_Audit_Builder();
            $admin            = new GI_Admin(
                new GI_Eventbrite_Importer(
                    new GI_Url_Validator(),
                    new GI_Http_Client(),
                    new GI_Jsonld_Parser(),
                    new GI_Candidate_Store(),
                    $api_client,
                    new GI_Eventbrite_API_Normalizer(),
                    $evidence_store,
                    new GI_HTTP_Evidence_Client(),
                    new GI_HTML_Evidence_Extractor()
                ),
                $api_client,
                new GI_Exploratory_Report( $api_client, $evidence_store, $preview_builder, $display_builder, $coverage_auditor ),
                $preview_builder,
                new GI_EM_Importer( $preview_builder )
            );
            $admin->register_hooks();
        }
    }

    public function repair_events_manager_single_event_format() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $format = get_option( 'dbem_single_event_format', null );
        if ( null === $format && function_exists( 'em_get_option' ) ) {
            $format = em_get_option( 'dbem_single_event_format' );
        }
        if ( ! is_string( $format ) || '' === $format || false === stripos( $format, '#_OPENSTREETMAP' ) ) {
            return;
        }

        $repaired = preg_replace( '/#_OPENSTREETMAP\b/i', '#_LOCATIONMAP', $format );
        if ( ! is_string( $repaired ) || $repaired === $format ) {
            return;
        }

        update_option( 'dbem_single_event_format', $repaired );
        update_option(
            'gi_em_single_event_format_repair',
            array(
                'repaired_at' => current_time( 'mysql' ),
                'from'        => '#_OPENSTREETMAP',
                'to'          => '#_LOCATIONMAP',
                'reason'      => 'Events Manager rendered #_OPENSTREETMAP as literal text in single event output.',
            ),
            false
        );
    }
}
