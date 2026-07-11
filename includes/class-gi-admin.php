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

        $candidates = ( new GI_Candidate_Store() )->get_recent_candidates( 20 );

        echo '<div class="wrap gi-wrap">';
        echo '<h1>' . esc_html__( 'Great Imports', 'great-imports' ) . '</h1>';
        $this->render_notice();
        $this->settings_card();
        $this->collect_card();
        $this->report_card();
        $this->candidates_card( $candidates );
        $this->manual_data_removal();
        echo '</div>';
    }

    private function settings_card() {
        echo '<div class="gi-card">';
        echo '<h2>' . esc_html__( 'Eventbrite API Settings', 'great-imports' ) . '</h2>';
        echo '<p><strong>' . esc_html__( 'Status:', 'great-imports' ) . '</strong> ' . esc_html( $this->api_client->has_private_token() ? __( 'Private token configured', 'great-imports' ) : __( 'Private token not configured', 'great-imports' ) ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_eventbrite_save_settings' );
        echo '<input type="hidden" name="action" value="gi_eventbrite_save_settings">';
        echo '<label class="gi-label" for="gi_eventbrite_private_token">' . esc_html__( 'Eventbrite private token', 'great-imports' ) . '</label>';
        echo '<input type="password" class="regular-text gi-token-input" id="gi_eventbrite_private_token" name="gi_eventbrite_private_token" placeholder="' . esc_attr( $this->api_client->has_private_token() ? __( 'Configured — enter a new token to replace it', 'great-imports' ) : __( 'Paste private token', 'great-imports' ) ) . '">';
        echo '<label class="gi-inline-check"><input type="checkbox" name="gi_eventbrite_clear_token" value="1"> ' . esc_html__( 'Clear saved token', 'great-imports' ) . '</label> ';
        submit_button( __( 'Save Eventbrite settings', 'great-imports' ), 'secondary', 'submit', false );
        echo '</form></div>';
    }

    private function collect_card() {
        echo '<div class="gi-card">';
        echo '<h2>' . esc_html__( 'One-time Eventbrite Import', 'great-imports' ) . '</h2>';
        echo '<p><strong>' . esc_html__( 'Current stage:', 'great-imports' ) . '</strong> ' . esc_html__( 'Evidence collection and candidate dry run only. This screen does not create Events Manager events yet.', 'great-imports' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_eventbrite_import_once' );
        echo '<input type="hidden" name="action" value="gi_eventbrite_import_once">';
        echo '<label class="gi-label" for="gi_eventbrite_url">' . esc_html__( 'Eventbrite URL', 'great-imports' ) . '</label>';
        echo '<input type="url" class="regular-text gi-url-input" id="gi_eventbrite_url" name="gi_eventbrite_url" placeholder="https://www.eventbrite.com/e/example-event-tickets-123456789" required> ';
        submit_button( __( 'Collect evidence / refresh candidate', 'great-imports' ), 'primary', 'submit', false );
        echo '</form></div>';
    }

    private function report_card() {
        echo '<div class="gi-card">';
        echo '<h2>' . esc_html__( 'Exploratory Report', 'great-imports' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_download_exploratory_report' );
        echo '<input type="hidden" name="action" value="gi_download_exploratory_report">';
        submit_button( __( 'Download Exploratory Report', 'great-imports' ), 'secondary', 'submit', false );
        echo '</form></div>';
    }

    private function candidates_card( array $candidates ) {
        echo '<div class="gi-card gi-candidates-card">';
        echo '<h2>' . esc_html__( 'Recent Event Candidates', 'great-imports' ) . '</h2>';

        if ( empty( $candidates ) ) {
            echo '<p>' . esc_html__( 'No candidates have been collected yet.', 'great-imports' ) . '</p>';
            echo '</div>';
            return;
        }

        $this->candidate_table( $candidates );
        echo '</div>';
    }

    private function candidate_table( array $candidates ) {
        echo '<table class="widefat striped gi-candidate-table">';
        echo '<thead><tr>';
        echo '<th class="column-primary">' . esc_html__( 'Title', 'great-imports' ) . '</th>';
        echo '<th>' . esc_html__( 'Date', 'great-imports' ) . '</th>';
        echo '<th>' . esc_html__( 'Venue', 'great-imports' ) . '</th>';
        echo '<th>' . esc_html__( 'Source', 'great-imports' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $candidates as $candidate ) {
            $id      = (int) $candidate->ID;
            $preview = $this->preview_builder->build_for_candidate( $candidate );
            $title   = GI_Candidate_Review::value( $id, 'title', '', get_the_title( $candidate ) );
            $date    = isset( $preview['public_event_fields']['start']['label'] ) ? (string) $preview['public_event_fields']['start']['label'] : GI_Candidate_Review::value( $id, 'start_date' );
            $venue   = GI_Candidate_Review::value( $id, 'location_name' );
            $source  = GI_Candidate_Review::source_value( $id, 'source_type' );
            $url     = (string) get_post_meta( $id, '_gi_source_url', true );
            $excerpt = wp_trim_words( wp_strip_all_tags( $candidate->post_content ), 28 );

            echo '<tr class="gi-candidate-main-row">';
            echo '<td class="title column-title column-primary">';
            echo '<strong>' . esc_html( $title ) . '</strong>';

            if ( $excerpt ) {
                echo '<p class="gi-candidate-excerpt">' . esc_html( $excerpt ) . '</p>';
            }

            if ( $url ) {
                echo '<div class="row-actions"><span><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Source', 'great-imports' ) . '</a></span></div>';
            }

            echo '</td>';
            echo '<td>' . esc_html( $date ) . '</td>';
            echo '<td>' . esc_html( $venue ) . '</td>';
            echo '<td>' . esc_html( $source ? $source : __( 'Source', 'great-imports' ) ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function manual_data_removal() {
        echo '<div class="gi-card gi-danger-card"><details class="gi-danger-details"><summary>' . esc_html__( 'Danger Zone: Manual Data Removal', 'great-imports' ) . '</summary><div class="gi-danger-body">';
        echo '<p><strong>' . esc_html__( 'Use this only when uninstall cleanup did not remove Great Imports data.', 'great-imports' ) . '</strong></p>';
        echo '<p>' . esc_html__( 'This removes only Great Imports-owned data. It does not delete Events Manager events, locations, tickets, media, categories, tags, or venue data.', 'great-imports' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_manual_data_removal' );
        echo '<input type="hidden" name="action" value="gi_manual_data_removal">';
        echo '<label class="gi-label"><input type="checkbox" name="gi_manual_cleanup_confirm" value="1" required> ' . esc_html__( 'I understand this permanently removes Great Imports review/evidence data and the saved Eventbrite token.', 'great-imports' ) . '</label>';
        echo '<label class="gi-label">' . esc_html__( 'Type REMOVE to confirm', 'great-imports' ) . '</label>';
        echo '<input type="text" class="regular-text" name="gi_manual_cleanup_phrase" required> ';
        submit_button( __( 'Remove Great Imports Data', 'great-imports' ), 'delete', 'submit', false );
        echo '</form></div></details></div>';
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
