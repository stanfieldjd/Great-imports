<?php
/**
 * Admin UI for Great Imports.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Admin {
    private $importer;
    private $api_client;
    private $exploratory_report;
    private $preview_builder;

    public function __construct( GI_Eventbrite_Importer $importer, GI_Eventbrite_API_Client $api_client, GI_Exploratory_Report $exploratory_report, GI_Import_Preview_Builder $preview_builder ) {
        $this->importer           = $importer;
        $this->api_client         = $api_client;
        $this->exploratory_report = $exploratory_report;
        $this->preview_builder    = $preview_builder;
    }

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_bar_menu', array( $this, 'register_admin_bar' ), 90 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_gi_eventbrite_import_once', array( $this, 'handle_eventbrite_import_once' ) );
        add_action( 'admin_post_gi_eventbrite_save_settings', array( $this, 'handle_eventbrite_save_settings' ) );
        add_action( 'admin_post_gi_download_exploratory_report', array( $this, 'handle_download_exploratory_report' ) );
        add_action( 'admin_post_gi_manual_data_removal', array( $this, 'handle_manual_data_removal' ) );
    }

    public function register_menu() {
        add_menu_page(
            __( 'Great Imports', 'great-imports' ),
            __( 'Great Imports', 'great-imports' ),
            'manage_options',
            'great-imports',
            array( $this, 'render_page' ),
            'dashicons-download',
            58
        );
    }

    public function register_admin_bar( $bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $bar->add_node(
            array(
                'id'    => 'great-imports',
                'title' => __( 'Great Imports', 'great-imports' ),
                'href'  => admin_url( 'admin.php?page=great-imports' ),
            )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_great-imports' !== $hook ) {
            return;
        }

        wp_enqueue_style( 'great-imports-admin', GREAT_IMPORTS_URL . 'assets/css/admin.css', array(), GREAT_IMPORTS_VERSION );
    }

    public function handle_eventbrite_save_settings() {
        $this->guard( 'change Great Imports settings' );
        check_admin_referer( 'gi_eventbrite_save_settings' );

        $clear = ! empty( $_POST['gi_eventbrite_clear_token'] );
        $token = isset( $_POST['gi_eventbrite_private_token'] ) ? wp_unslash( $_POST['gi_eventbrite_private_token'] ) : '';

        if ( $clear ) {
            $this->api_client->clear_private_token();
            $this->redirect_with_notice( 'success', __( 'Eventbrite private token cleared.', 'great-imports' ), 0 );
        }

        if ( '' !== trim( (string) $token ) ) {
            $this->api_client->save_private_token( $token );
            $this->redirect_with_notice( 'success', __( 'Eventbrite private token saved.', 'great-imports' ), 0 );
        }

        $this->redirect_with_notice( 'error', __( 'No Eventbrite token changes were made.', 'great-imports' ), 0 );
    }

    public function handle_download_exploratory_report() {
        $this->guard( 'download Great Imports reports' );
        check_admin_referer( 'gi_download_exploratory_report' );
        $this->exploratory_report->download();
    }

    public function handle_eventbrite_import_once() {
        $this->guard( 'import events' );
        check_admin_referer( 'gi_eventbrite_import_once' );

        $url    = isset( $_POST['gi_eventbrite_url'] ) ? wp_unslash( $_POST['gi_eventbrite_url'] ) : '';
        $result = $this->importer->import_once( $url );

        $this->redirect_with_notice( $result['success'] ? 'success' : 'error', $result['message'], absint( $result['post_id'] ) );
    }

    public function handle_manual_data_removal() {
        $this->guard( 'remove Great Imports data' );
        check_admin_referer( 'gi_manual_data_removal' );

        $confirmed = ! empty( $_POST['gi_manual_cleanup_confirm'] );
        $phrase    = isset( $_POST['gi_manual_cleanup_phrase'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['gi_manual_cleanup_phrase'] ) ) ) ) : '';

        if ( ! $confirmed || 'REMOVE' !== $phrase ) {
            $this->redirect_with_notice( 'error', __( 'Manual cleanup was not run. Check the confirmation box and type REMOVE.', 'great-imports' ), 0 );
        }

        $this->redirect_with_notice( 'success', GI_Data_Cleaner::summary_message( GI_Data_Cleaner::cleanup() ), 0 );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $candidate_table = new GI_Candidate_List_Table( $this->preview_builder );
        $candidate_table->prepare_items();

        echo '<div class="wrap gi-wrap gi-admin-screen">';
        $this->page_header();
        $this->render_notice();

        echo '<div class="gi-admin-grid">';
        echo '<aside class="gi-utility-column" aria-label="' . esc_attr__( 'Great Imports utilities', 'great-imports' ) . '">';
        $this->version_panel();
        $this->settings_panel();
        $this->report_panel();
        $this->manual_data_removal();
        echo '</aside>';

        echo '<main class="gi-main-column">';
        $this->collect_panel();
        $this->candidates_panel( $candidate_table );
        echo '</main>';
        echo '</div>';
        echo '</div>';
    }

    private function page_header() {
        echo '<div class="gi-page-header">';
        echo '<div>';
        echo '<h1 class="wp-heading-inline">' . esc_html__( 'Great Imports', 'great-imports' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Collect Eventbrite evidence into candidates before any Events Manager save.', 'great-imports' ) . '</p>';
        echo '</div>';
        echo '<a class="page-title-action" href="#gi-collect-url">' . esc_html__( 'Collect URL', 'great-imports' ) . '</a>';
        echo '</div>';
        echo '<hr class="wp-header-end">';
    }

    private function collect_panel() {
        echo '<section id="gi-collect-url" class="gi-collect-panel" aria-labelledby="gi-collect-heading">';
        echo '<div class="gi-panel-heading">';
        echo '<h2 id="gi-collect-heading">' . esc_html__( 'Collect Eventbrite URL', 'great-imports' ) . '</h2>';
        echo '<span class="gi-stage-badge">' . esc_html__( 'Candidate only', 'great-imports' ) . '</span>';
        echo '</div>';
        echo '<form class="gi-collect-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_eventbrite_import_once' );
        echo '<input type="hidden" name="action" value="gi_eventbrite_import_once">';
        echo '<label class="screen-reader-text" for="gi_eventbrite_url">' . esc_html__( 'Eventbrite URL', 'great-imports' ) . '</label>';
        echo '<input type="url" class="regular-text gi-url-input" id="gi_eventbrite_url" name="gi_eventbrite_url" placeholder="https://www.eventbrite.com/e/example-event-tickets-123456789" required> ';
        submit_button( __( 'Collect evidence', 'great-imports' ), 'primary', 'submit', false );
        echo '</form>';
        echo '<p class="description">' . esc_html__( 'This refreshes the candidate list only. It does not create Events Manager events or locations.', 'great-imports' ) . '</p>';
        echo '</section>';
    }

    private function candidates_panel( GI_Candidate_List_Table $candidate_table ) {
        $count = $candidate_table->get_item_count();

        echo '<section class="gi-list-panel" aria-labelledby="gi-candidates-heading">';
        echo '<div class="gi-list-header">';
        echo '<h2 id="gi-candidates-heading">' . esc_html__( 'Recent Event Candidates', 'great-imports' ) . '</h2>';
        echo '<span class="gi-count">' . esc_html( sprintf( _n( '%s item', '%s items', $count, 'great-imports' ), number_format_i18n( $count ) ) ) . '</span>';
        echo '</div>';
        echo '<form class="gi-candidates-form" method="get">';
        echo '<input type="hidden" name="page" value="great-imports">';
        $candidate_table->display();
        echo '</form>';
        echo '</section>';
    }

    private function version_panel() {
        echo '<div class="gi-utility-panel gi-version-panel">';
        echo '<strong>' . esc_html__( 'Current Version', 'great-imports' ) . '</strong>';
        echo '<em>' . esc_html( GREAT_IMPORTS_VERSION ) . '</em>';
        echo '</div>';
    }

    private function settings_panel() {
        $status = $this->api_client->has_private_token() ? __( 'Configured', 'great-imports' ) : __( 'Not configured', 'great-imports' );

        echo '<details class="gi-utility-panel">';
        echo '<summary><span>' . esc_html__( 'Eventbrite API Settings', 'great-imports' ) . '</span><em>' . esc_html( $status ) . '</em></summary>';
        echo '<div class="gi-utility-body">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_eventbrite_save_settings' );
        echo '<input type="hidden" name="action" value="gi_eventbrite_save_settings">';
        echo '<label class="gi-label" for="gi_eventbrite_private_token">' . esc_html__( 'Private token', 'great-imports' ) . '</label>';
        echo '<input type="password" class="regular-text gi-token-input" id="gi_eventbrite_private_token" name="gi_eventbrite_private_token" placeholder="' . esc_attr( $this->api_client->has_private_token() ? __( 'Configured — enter a new token to replace it', 'great-imports' ) : __( 'Paste private token', 'great-imports' ) ) . '">';
        echo '<label class="gi-inline-check"><input type="checkbox" name="gi_eventbrite_clear_token" value="1"> ' . esc_html__( 'Clear saved token', 'great-imports' ) . '</label>';
        submit_button( __( 'Save settings', 'great-imports' ), 'secondary', 'submit', false );
        echo '</form>';
        echo '</div></details>';
    }

    private function report_panel() {
        echo '<details class="gi-utility-panel">';
        echo '<summary><span>' . esc_html__( 'Exploratory Report', 'great-imports' ) . '</span></summary>';
        echo '<div class="gi-utility-body">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_download_exploratory_report' );
        echo '<input type="hidden" name="action" value="gi_download_exploratory_report">';
        submit_button( __( 'Download report', 'great-imports' ), 'secondary', 'submit', false );
        echo '</form>';
        echo '</div></details>';
    }

    private function manual_data_removal() {
        echo '<details class="gi-utility-panel gi-danger-panel">';
        echo '<summary><span>' . esc_html__( 'Danger Zone: Manual Data Removal', 'great-imports' ) . '</span></summary>';
        echo '<div class="gi-utility-body">';
        echo '<p><strong>' . esc_html__( 'Use this only when uninstall cleanup did not remove Great Imports data.', 'great-imports' ) . '</strong></p>';
        echo '<p>' . esc_html__( 'This removes only Great Imports-owned data. It does not delete Events Manager events, locations, tickets, media, categories, tags, or venue data.', 'great-imports' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_manual_data_removal' );
        echo '<input type="hidden" name="action" value="gi_manual_data_removal">';
        echo '<label class="gi-label"><input type="checkbox" name="gi_manual_cleanup_confirm" value="1" required> ' . esc_html__( 'I understand this permanently removes Great Imports review/evidence data and the saved Eventbrite token.', 'great-imports' ) . '</label>';
        echo '<label class="gi-label">' . esc_html__( 'Type REMOVE to confirm', 'great-imports' ) . '</label>';
        echo '<input type="text" class="regular-text" name="gi_manual_cleanup_phrase" required> ';
        submit_button( __( 'Remove Great Imports Data', 'great-imports' ), 'delete', 'submit', false );
        echo '</form>';
        echo '</div></details>';
    }

    private function redirect_with_notice( $status, $message, $id = 0 ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'         => 'great-imports',
                    'gi_status'    => sanitize_key( $status ),
                    'gi_message'   => rawurlencode( (string) $message ),
                    'gi_candidate' => absint( $id ),
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    private function render_notice() {
        if ( empty( $_GET['gi_message'] ) ) {
            return;
        }

        $status  = isset( $_GET['gi_status'] ) && 'success' === $_GET['gi_status'] ? 'success' : 'error';
        $message = sanitize_text_field( wp_unslash( $_GET['gi_message'] ) );

        printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $status ), esc_html( $message ) );
    }

    private function guard( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html( sprintf( __( 'You do not have permission to %s.', 'great-imports' ), $action ) ) );
        }
    }
}
