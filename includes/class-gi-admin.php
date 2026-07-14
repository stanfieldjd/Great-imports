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
    private $em_importer;

    public function __construct( GI_Eventbrite_Importer $importer, GI_Eventbrite_API_Client $api_client, GI_Exploratory_Report $exploratory_report, GI_Import_Preview_Builder $preview_builder, GI_EM_Importer $em_importer ) {
        $this->importer           = $importer;
        $this->api_client         = $api_client;
        $this->exploratory_report = $exploratory_report;
        $this->preview_builder    = $preview_builder;
        $this->em_importer        = $em_importer;
    }

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_bar_menu', array( $this, 'register_admin_bar' ), 90 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_gi_eventbrite_import_once', array( $this, 'handle_eventbrite_import_once' ) );
        add_action( 'admin_post_gi_eventbrite_save_settings', array( $this, 'handle_eventbrite_save_settings' ) );
        add_action( 'admin_post_gi_eventbrite_save_recurring_from_source', array( $this, 'handle_eventbrite_save_recurring_from_source' ) );
        add_action( 'admin_post_gi_run_recurring_source', array( $this, 'handle_run_recurring_source' ) );
        add_action( 'admin_post_gi_save_recurring_source_settings', array( $this, 'handle_save_recurring_source_settings' ) );
        add_action( 'great_imports_run_recurring_sources', array( $this, 'run_due_recurring_sources' ) );
        add_action( 'admin_post_gi_download_exploratory_report', array( $this, 'handle_download_exploratory_report' ) );
        add_action( 'admin_post_gi_manual_data_removal', array( $this, 'handle_manual_data_removal' ) );
        add_action( 'admin_post_gi_save_candidate_field', array( $this, 'handle_save_candidate_field' ) );
        add_action( 'admin_post_gi_import_candidate_to_em', array( $this, 'handle_import_candidate_to_em' ) );
        add_action( 'admin_post_gi_import_recurring_candidate_to_em', array( $this, 'handle_import_recurring_candidate_to_em' ) );
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

        $url = isset( $_POST['gi_eventbrite_url'] ) ? wp_unslash( $_POST['gi_eventbrite_url'] ) : '';
        $this->save_source_search_trace(
            array(
                'stage'          => 'received',
                'submitted_url'  => esc_url_raw( (string) $url ),
                'started_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
                'plugin_version' => defined( 'GREAT_IMPORTS_VERSION' ) ? GREAT_IMPORTS_VERSION : '',
                'admin_action'   => 'gi_eventbrite_import_once',
                'user_id'        => get_current_user_id(),
                'message'        => 'Source Search submitted and importer is about to run.',
            )
        );

        try {
            $result = $this->importer->import_once( $url );
        } catch ( Throwable $exception ) {
            $message = sprintf(
                __( 'Source Search stopped before candidates could be collected: %s', 'great-imports' ),
                $exception->getMessage()
            );
            $this->save_source_search_trace(
                array(
                    'stage'           => 'exception',
                    'submitted_url'   => esc_url_raw( (string) $url ),
                    'finished_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
                    'plugin_version'  => defined( 'GREAT_IMPORTS_VERSION' ) ? GREAT_IMPORTS_VERSION : '',
                    'admin_action'    => 'gi_eventbrite_import_once',
                    'success'         => false,
                    'message'         => $message,
                    'exception_type'  => get_class( $exception ),
                    'exception_file'  => $exception->getFile(),
                    'exception_line'  => $exception->getLine(),
                )
            );
            $this->redirect_with_notice( 'error', $message, 0 );
        }

        $this->save_source_search_trace(
            array(
                'stage'           => 'completed',
                'submitted_url'   => esc_url_raw( (string) $url ),
                'finished_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
                'plugin_version'  => defined( 'GREAT_IMPORTS_VERSION' ) ? GREAT_IMPORTS_VERSION : '',
                'admin_action'    => 'gi_eventbrite_import_once',
                'success'         => ! empty( $result['success'] ),
                'message'         => isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '',
                'post_id'         => isset( $result['post_id'] ) ? absint( $result['post_id'] ) : 0,
                'updated'         => ! empty( $result['updated'] ),
                'event_summary'   => isset( $result['event'] ) && is_array( $result['event'] ) ? $this->source_search_event_summary( $result['event'] ) : array(),
            )
        );

        $this->redirect_with_notice( $result['success'] ? 'success' : 'error', $result['message'], absint( $result['post_id'] ) );
    }

    public function handle_eventbrite_save_recurring_from_source() {
        $this->guard( 'save recurring source URLs' );
        check_admin_referer( 'gi_eventbrite_import_once' );

        $url = isset( $_POST['gi_eventbrite_url'] ) ? wp_unslash( $_POST['gi_eventbrite_url'] ) : '';
        $validator = new GI_Url_Validator();
        $validated = $validator->validate_eventbrite_url( $url );

        if ( empty( $validated['valid'] ) ) {
            $message = isset( $validated['error'] ) ? (string) $validated['error'] : __( 'The recurring source URL could not be saved.', 'great-imports' );
            $this->save_source_search_trace(
                array(
                    'stage'          => 'save_recurring_url_failed',
                    'submitted_url'  => esc_url_raw( (string) $url ),
                    'finished_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
                    'plugin_version' => defined( 'GREAT_IMPORTS_VERSION' ) ? GREAT_IMPORTS_VERSION : '',
                    'admin_action'   => 'gi_eventbrite_save_recurring_from_source',
                    'success'        => false,
                    'message'        => sanitize_text_field( $message ),
                )
            );
            $this->redirect_with_notice( 'error', $message, 0 );
        }

        $saved = $this->saved_recurring_sources();
        $key   = md5( (string) $validated['url'] );
        $now   = current_time( 'mysql' );
        $existing = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();
        $saved[ $key ] = array(
            'id'                   => $key,
            'name'                 => $this->recurring_source_name_from_validation( $validated ),
            'submitted_url'        => esc_url_raw( (string) $url ),
            'source_url'           => esc_url_raw( (string) $validated['url'] ),
            'source_kind'          => isset( $validated['source_kind'] ) ? sanitize_key( (string) $validated['source_kind'] ) : '',
            'eventbrite_event_id'  => isset( $validated['event_id'] ) ? sanitize_text_field( (string) $validated['event_id'] ) : '',
            'eventbrite_organizer_id' => isset( $validated['organizer_id'] ) ? sanitize_text_field( (string) $validated['organizer_id'] ) : '',
            'enabled'              => isset( $existing['enabled'] ) ? (bool) $existing['enabled'] : true,
            'auto_enabled'         => isset( $existing['auto_enabled'] ) ? (bool) $existing['auto_enabled'] : false,
            'frequency_hours'      => isset( $existing['frequency_hours'] ) ? $this->normalize_recurring_frequency( $existing['frequency_hours'] ) : 24,
            'cadence'              => isset( $existing['cadence'] ) ? sanitize_key( (string) $existing['cadence'] ) : 'manual',
            'search_ahead_days'    => isset( $existing['search_ahead_days'] ) ? absint( $existing['search_ahead_days'] ) : 30,
            'schedule_label'       => isset( $existing['schedule_label'] ) ? sanitize_text_field( (string) $existing['schedule_label'] ) : __( 'Manual run', 'great-imports' ),
            'last_run_at'          => isset( $existing['last_run_at'] ) ? sanitize_text_field( (string) $existing['last_run_at'] ) : '',
            'last_run_status'      => isset( $existing['last_run_status'] ) ? sanitize_text_field( (string) $existing['last_run_status'] ) : __( 'Never run', 'great-imports' ),
            'last_run_message'     => isset( $existing['last_run_message'] ) ? sanitize_text_field( (string) $existing['last_run_message'] ) : '',
            'next_run_at'          => isset( $existing['next_run_at'] ) ? sanitize_text_field( (string) $existing['next_run_at'] ) : '',
            'created_at'           => isset( $existing['created_at'] ) ? sanitize_text_field( (string) $existing['created_at'] ) : $now,
            'updated_at'           => $now,
            'saved_by'             => get_current_user_id(),
        );

        update_option( 'great_imports_recurring_sources', $saved, false );

        $message = __( 'Recurring source URL saved.', 'great-imports' );
        $this->save_source_search_trace(
            array(
                'stage'          => 'saved_recurring_url',
                'submitted_url'  => esc_url_raw( (string) $url ),
                'source_url'     => esc_url_raw( (string) $validated['url'] ),
                'finished_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
                'plugin_version' => defined( 'GREAT_IMPORTS_VERSION' ) ? GREAT_IMPORTS_VERSION : '',
                'admin_action'   => 'gi_eventbrite_save_recurring_from_source',
                'success'        => true,
                'message'        => $message,
                'saved_source_id' => $key,
                'source_kind'     => $saved[ $key ]['source_kind'],
            )
        );

        $this->redirect_with_notice( 'success', $message, 0 );
    }

    public function handle_save_recurring_source_settings() {
        $this->guard( 'save recurring source settings' );

        $source_id = isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : '';
        check_admin_referer( 'gi_save_recurring_source_settings_' . $source_id );

        $saved = $this->saved_recurring_sources();
        if ( '' === $source_id || empty( $saved[ $source_id ] ) || ! is_array( $saved[ $source_id ] ) ) {
            $this->redirect_with_notice( 'error', __( 'Saved recurring source could not be found.', 'great-imports' ), 0 );
        }

        $auto_enabled      = ! empty( $_POST['auto_enabled'] );
        $frequency_hours   = isset( $_POST['frequency_hours'] ) ? $this->normalize_recurring_frequency( wp_unslash( $_POST['frequency_hours'] ) ) : 24;
        $search_ahead_days = isset( $_POST['search_ahead_days'] ) ? absint( wp_unslash( $_POST['search_ahead_days'] ) ) : 30;
        $search_ahead_days = max( 1, min( 365, $search_ahead_days ) );
        $now               = current_time( 'mysql' );

        $saved[ $source_id ]['auto_enabled']      = $auto_enabled;
        $saved[ $source_id ]['enabled']           = true;
        $saved[ $source_id ]['frequency_hours']   = $frequency_hours;
        $saved[ $source_id ]['cadence']           = $auto_enabled ? 'auto' : 'manual';
        $saved[ $source_id ]['search_ahead_days'] = $search_ahead_days;
        $saved[ $source_id ]['schedule_label']    = $auto_enabled ? $this->recurring_source_frequency_label( $frequency_hours ) : __( 'Manual run', 'great-imports' );
        $saved[ $source_id ]['next_run_at']       = $auto_enabled ? $this->recurring_source_next_run_time( $frequency_hours ) : '';
        $saved[ $source_id ]['updated_at']        = $now;

        update_option( 'great_imports_recurring_sources', $saved, false );

        $this->redirect_with_notice( 'success', __( 'Saved recurring source settings updated.', 'great-imports' ), 0 );
    }

    public function handle_run_recurring_source() {
        $this->guard( 'run saved recurring sources' );

        $source_id = isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : '';
        check_admin_referer( 'gi_run_recurring_source_' . $source_id );

        $saved = $this->saved_recurring_sources();
        if ( '' === $source_id || empty( $saved[ $source_id ] ) || ! is_array( $saved[ $source_id ] ) ) {
            $this->redirect_with_notice( 'error', __( 'Saved recurring source could not be found.', 'great-imports' ), 0 );
        }

        $url = isset( $saved[ $source_id ]['source_url'] ) ? esc_url_raw( (string) $saved[ $source_id ]['source_url'] ) : '';
        if ( '' === $url ) {
            $this->redirect_with_notice( 'error', __( 'Saved recurring source does not have a URL to run.', 'great-imports' ), 0 );
        }

        $search_ahead_days = isset( $_POST['search_ahead_days'] ) ? absint( wp_unslash( $_POST['search_ahead_days'] ) ) : 30;
        $search_ahead_days = max( 1, min( 365, $search_ahead_days ) );
        $result = $this->run_recurring_source( $source_id, $saved[ $source_id ], $search_ahead_days, 'manual' );
        $message = isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '';

        $this->redirect_with_notice( ! empty( $result['success'] ) ? 'success' : 'error', $message, isset( $result['post_id'] ) ? absint( $result['post_id'] ) : 0 );
    }

    public function run_due_recurring_sources() {
        $saved = $this->saved_recurring_sources();
        if ( empty( $saved ) ) {
            return;
        }

        $now_ts = current_time( 'timestamp' );
        foreach ( $saved as $source_id => $source ) {
            if ( ! is_array( $source ) || empty( $source['auto_enabled'] ) ) {
                continue;
            }

            $next_run_at = isset( $source['next_run_at'] ) ? sanitize_text_field( (string) $source['next_run_at'] ) : '';
            $next_ts     = '' !== $next_run_at ? strtotime( $next_run_at ) : 0;
            if ( $next_ts && $next_ts > $now_ts ) {
                continue;
            }

            $days = isset( $source['search_ahead_days'] ) ? absint( $source['search_ahead_days'] ) : 30;
            $this->run_recurring_source( sanitize_text_field( (string) $source_id ), $source, $days, 'auto' );
        }
    }

    public function handle_save_candidate_field() {
        $this->guard( 'edit Great Imports candidates' );

        $candidate_id = isset( $_POST['candidate_id'] ) ? absint( $_POST['candidate_id'] ) : 0;
        check_admin_referer( 'gi_save_candidate_field_' . $candidate_id );

        $group = isset( $_POST['field_group'] ) ? sanitize_key( wp_unslash( $_POST['field_group'] ) ) : '';
        $allowed_fields = array(
            'title'    => array( 'title' ),
            'date'     => array( 'start_date_date', 'start_date_time', 'end_date_date', 'end_date_time' ),
            'venue'    => array( 'location_name', 'location_address_1', 'location_address_2', 'location_city', 'location_state', 'location_postal_code', 'location_country' ),
            'location' => array( 'em_location_id' ),
        );

        if ( ! isset( $allowed_fields[ $group ] ) ) {
            $this->redirect_with_notice( 'error', __( 'Candidate field was not saved because the edit group was invalid.', 'great-imports' ), $candidate_id );
        }

        $data = array();
        foreach ( $allowed_fields[ $group ] as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                $data[ $field ] = wp_unslash( $_POST[ $field ] );
            }
        }

        $result = GI_Candidate_Review::save( $candidate_id, $data );
        $this->redirect_with_notice( $result['success'] ? 'success' : 'error', $result['message'], $candidate_id );
    }

    public function handle_import_candidate_to_em() {
        $this->guard( 'import Great Imports candidates into Events Manager' );

        $candidate_id = isset( $_POST['candidate_id'] ) ? absint( $_POST['candidate_id'] ) : 0;
        check_admin_referer( 'gi_import_candidate_to_em_' . $candidate_id );

        $result = $this->em_importer->import_candidate( $candidate_id );
        $this->redirect_with_notice( $result['success'] ? 'success' : 'error', $result['message'], $candidate_id );
    }

    public function handle_import_recurring_candidate_to_em() {
        $this->guard( 'import Great Imports recurring candidates into Events Manager' );

        $candidate_id = isset( $_POST['candidate_id'] ) ? absint( $_POST['candidate_id'] ) : 0;
        check_admin_referer( 'gi_import_recurring_candidate_to_em_' . $candidate_id );

        $result = $this->em_importer->import_recurring_candidate( $candidate_id );
        $this->redirect_with_notice( $result['success'] ? 'success' : 'error', $result['message'], $candidate_id );
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
        echo '</div>';
        echo '<hr class="wp-header-end">';
    }

    private function collect_panel() {
        echo '<section id="gi-collect-url" class="gi-collect-panel" aria-labelledby="gi-collect-heading">';
        echo '<div class="gi-panel-heading">';
        echo '<h2 id="gi-collect-heading">' . esc_html__( 'Source', 'great-imports' ) . '</h2>';
        echo '<span class="gi-stage-badge">' . esc_html__( 'Candidate only', 'great-imports' ) . '</span>';
        echo '</div>';
        echo '<form class="gi-collect-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_eventbrite_import_once' );
        echo '<label class="screen-reader-text" for="gi_eventbrite_url">' . esc_html__( 'Eventbrite URL', 'great-imports' ) . '</label>';
        echo '<input type="url" class="regular-text gi-url-input" id="gi_eventbrite_url" name="gi_eventbrite_url" placeholder="https://www.eventbrite.com/e/example-event-tickets-123456789 or https://www.eventbrite.com/o/123456789" required> ';
        echo '<button type="submit" class="button button-primary" name="action" value="gi_eventbrite_import_once">' . esc_html__( 'Search Source', 'great-imports' ) . '</button>';
        echo ' <button type="submit" class="button" name="action" value="gi_eventbrite_save_recurring_from_source">' . esc_html__( 'Save Recurring', 'great-imports' ) . '</button>';
        echo '</form>';
        $this->saved_recurring_sources_list();
        echo '</section>';
    }

    private function saved_recurring_sources_list() {
        $sources = $this->saved_recurring_sources();
        if ( empty( $sources ) ) {
            return;
        }

        uasort(
            $sources,
            static function ( $a, $b ) {
                return strcmp( isset( $b['updated_at'] ) ? (string) $b['updated_at'] : '', isset( $a['updated_at'] ) ? (string) $a['updated_at'] : '' );
            }
        );

        echo '<div class="gi-recurring-sources">';
        echo '<h3>' . esc_html__( 'Saved Recurring Sources', 'great-imports' ) . '</h3>';
        echo '<table class="widefat striped gi-recurring-source-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Name', 'great-imports' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'URL', 'great-imports' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Type', 'great-imports' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Auto run', 'great-imports' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Every', 'great-imports' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Last run', 'great-imports' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Next run', 'great-imports' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Actions', 'great-imports' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Saved', 'great-imports' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ( $sources as $source ) {
            $url = isset( $source['source_url'] ) ? esc_url_raw( (string) $source['source_url'] ) : '';
            if ( '' === $url ) {
                continue;
            }
            echo '<tr>';
            echo '<td>' . esc_html( $this->recurring_source_name( $source ) ) . '</td>';
            echo '<td class="gi-recurring-source-url"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a></td>';
            echo '<td>' . esc_html( $this->recurring_source_kind_label( $source ) ) . '</td>';
            echo '<td>' . esc_html( ! empty( $source['auto_enabled'] ) ? __( 'On', 'great-imports' ) : __( 'Off', 'great-imports' ) ) . '</td>';
            echo '<td>' . esc_html( $this->recurring_source_schedule_label( $source ) ) . '</td>';
            echo '<td>' . esc_html( $this->recurring_source_last_run_label( $source ) ) . '</td>';
            echo '<td>' . esc_html( $this->recurring_source_time_label( $source, 'next_run_at', __( 'Not scheduled', 'great-imports' ) ) ) . '</td>';
            echo '<td>' . $this->recurring_source_actions( $source ) . '</td>';
            echo '<td>' . esc_html( $this->recurring_source_time_label( $source, 'updated_at', __( 'Unknown', 'great-imports' ) ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    private function candidates_panel( GI_Candidate_List_Table $candidate_table ) {
        $count = $candidate_table->get_item_count();

        echo '<section class="gi-list-panel" aria-labelledby="gi-candidates-heading">';
        echo '<div class="gi-list-header">';
        echo '<h2 id="gi-candidates-heading">' . esc_html__( 'Recent Event Candidates', 'great-imports' ) . '</h2>';
        echo '<span class="gi-count">' . esc_html( sprintf( _n( '%s item', '%s items', $count, 'great-imports' ), number_format_i18n( $count ) ) ) . '</span>';
        echo '</div>';
        $candidate_table->display();
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

    private function save_source_search_trace( array $trace ) {
        update_option( 'great_imports_last_source_search', $trace, false );
    }

    private function saved_recurring_sources() {
        $sources = get_option( 'great_imports_recurring_sources', array() );
        return is_array( $sources ) ? $sources : array();
    }

    private function recurring_source_name_from_validation( array $validated ) {
        $kind = isset( $validated['source_kind'] ) ? sanitize_key( (string) $validated['source_kind'] ) : '';
        if ( 'organizer' === $kind && ! empty( $validated['organizer_id'] ) ) {
            return sprintf(
                /* translators: %s: Eventbrite organizer ID. */
                __( 'Eventbrite organizer %s', 'great-imports' ),
                sanitize_text_field( (string) $validated['organizer_id'] )
            );
        }

        if ( ! empty( $validated['event_id'] ) ) {
            return sprintf(
                /* translators: %s: Eventbrite event ID. */
                __( 'Eventbrite event %s', 'great-imports' ),
                sanitize_text_field( (string) $validated['event_id'] )
            );
        }

        return __( 'Eventbrite source', 'great-imports' );
    }

    private function recurring_source_name( array $source ) {
        if ( ! empty( $source['name'] ) ) {
            return sanitize_text_field( (string) $source['name'] );
        }

        if ( ! empty( $source['eventbrite_organizer_id'] ) ) {
            return sprintf(
                /* translators: %s: Eventbrite organizer ID. */
                __( 'Eventbrite organizer %s', 'great-imports' ),
                sanitize_text_field( (string) $source['eventbrite_organizer_id'] )
            );
        }

        if ( ! empty( $source['eventbrite_event_id'] ) ) {
            return sprintf(
                /* translators: %s: Eventbrite event ID. */
                __( 'Eventbrite event %s', 'great-imports' ),
                sanitize_text_field( (string) $source['eventbrite_event_id'] )
            );
        }

        return __( 'Eventbrite source', 'great-imports' );
    }

    private function recurring_source_kind_label( array $source ) {
        $kind = isset( $source['source_kind'] ) ? sanitize_key( (string) $source['source_kind'] ) : '';
        if ( 'organizer' === $kind ) {
            return __( 'Organizer URL', 'great-imports' );
        }

        if ( 'event' === $kind ) {
            return __( 'Event URL', 'great-imports' );
        }

        return __( 'Eventbrite URL', 'great-imports' );
    }

    private function recurring_source_schedule_label( array $source ) {
        if ( ! empty( $source['auto_enabled'] ) ) {
            return $this->recurring_source_frequency_label( isset( $source['frequency_hours'] ) ? $source['frequency_hours'] : 24 );
        }

        if ( ! empty( $source['schedule_label'] ) ) {
            return sanitize_text_field( (string) $source['schedule_label'] );
        }

        return __( 'Manual run', 'great-imports' );
    }

    private function recurring_source_search_ahead_label( array $source ) {
        $days = isset( $source['search_ahead_days'] ) ? absint( $source['search_ahead_days'] ) : 30;
        $days = max( 1, min( 365, $days ) );

        return sprintf(
            /* translators: %d: number of days to search ahead. */
            _n( '%d day', '%d days', $days, 'great-imports' ),
            $days
        );
    }

    private function recurring_source_last_run_label( array $source ) {
        if ( ! empty( $source['last_run_at'] ) ) {
            $label = sanitize_text_field( (string) $source['last_run_at'] );
            if ( ! empty( $source['last_run_status'] ) ) {
                $label .= ' - ' . sanitize_text_field( (string) $source['last_run_status'] );
            }
            return $label;
        }

        if ( ! empty( $source['last_run_status'] ) ) {
            return sanitize_text_field( (string) $source['last_run_status'] );
        }

        return __( 'Never run', 'great-imports' );
    }

    private function recurring_source_time_label( array $source, $key, $fallback ) {
        if ( ! empty( $source[ $key ] ) ) {
            return sanitize_text_field( (string) $source[ $key ] );
        }

        return $fallback;
    }

    private function recurring_source_actions( array $source ) {
        $source_id = isset( $source['id'] ) ? sanitize_text_field( (string) $source['id'] ) : '';
        if ( '' === $source_id ) {
            return '';
        }

        $days = isset( $source['search_ahead_days'] ) ? absint( $source['search_ahead_days'] ) : 30;
        $days = max( 1, min( 365, $days ) );
        $frequency = $this->normalize_recurring_frequency( isset( $source['frequency_hours'] ) ? $source['frequency_hours'] : 24 );
        $auto_checked = ! empty( $source['auto_enabled'] ) ? ' checked' : '';

        $output  = '<div class="gi-recurring-actions">';
        $output .= '<form class="gi-recurring-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        $output .= '<input type="hidden" name="action" value="gi_save_recurring_source_settings">';
        $output .= '<input type="hidden" name="source_id" value="' . esc_attr( $source_id ) . '">';
        $output .= wp_nonce_field( 'gi_save_recurring_source_settings_' . $source_id, '_wpnonce', true, false );
        $output .= '<label class="gi-recurring-inline-check"><input type="checkbox" name="auto_enabled" value="1"' . $auto_checked . '> ' . esc_html__( 'Auto run', 'great-imports' ) . '</label>';
        $output .= '<label><span class="screen-reader-text">' . esc_html__( 'Run frequency', 'great-imports' ) . '</span>';
        $output .= '<select name="frequency_hours">';
        foreach ( $this->recurring_frequency_options() as $hours => $label ) {
            $output .= '<option value="' . esc_attr( $hours ) . '"' . selected( $frequency, $hours, false ) . '>' . esc_html( $label ) . '</option>';
        }
        $output .= '</select></label>';
        $output .= '<label><span class="gi-recurring-field-label">' . esc_html__( 'Ahead', 'great-imports' ) . '</span> ';
        $output .= '<input type="number" class="small-text gi-search-ahead-input" name="search_ahead_days" min="1" max="365" value="' . esc_attr( $days ) . '"> ';
        $output .= esc_html__( 'days', 'great-imports' ) . '</label>';
        $output .= '<button type="submit" class="button button-small">' . esc_html__( 'Save', 'great-imports' ) . '</button>';
        $output .= '</form>';

        $output .= '<form class="gi-recurring-run-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        $output .= '<input type="hidden" name="action" value="gi_run_recurring_source">';
        $output .= '<input type="hidden" name="source_id" value="' . esc_attr( $source_id ) . '">';
        $output .= '<input type="hidden" name="search_ahead_days" value="' . esc_attr( $days ) . '">';
        $output .= wp_nonce_field( 'gi_run_recurring_source_' . $source_id, '_wpnonce', true, false );
        $output .= '<button type="submit" class="button button-small">' . esc_html__( 'Run now', 'great-imports' ) . '</button>';
        $output .= '</form>';
        $output .= '</div>';

        return $output;
    }

    private function run_recurring_source( $source_id, array $source, $search_ahead_days, $run_mode ) {
        $saved = $this->saved_recurring_sources();
        if ( empty( $saved[ $source_id ] ) || ! is_array( $saved[ $source_id ] ) ) {
            return array(
                'success' => false,
                'message' => __( 'Saved recurring source could not be found.', 'great-imports' ),
                'post_id' => 0,
            );
        }

        $url = isset( $source['source_url'] ) ? esc_url_raw( (string) $source['source_url'] ) : '';
        if ( '' === $url ) {
            return array(
                'success' => false,
                'message' => __( 'Saved recurring source does not have a URL to run.', 'great-imports' ),
                'post_id' => 0,
            );
        }

        $search_ahead_days = max( 1, min( 365, absint( $search_ahead_days ) ) );
        $frequency_hours   = $this->normalize_recurring_frequency( isset( $source['frequency_hours'] ) ? $source['frequency_hours'] : 24 );
        $now               = current_time( 'mysql' );

        $saved[ $source_id ]['search_ahead_days'] = $search_ahead_days;
        $saved[ $source_id ]['last_run_at']       = $now;
        $saved[ $source_id ]['last_run_status']   = __( 'Running', 'great-imports' );
        $saved[ $source_id ]['updated_at']        = $now;
        update_option( 'great_imports_recurring_sources', $saved, false );

        try {
            $result = $this->importer->import_once( $url );
        } catch ( Throwable $exception ) {
            $result = array(
                'success' => false,
                'message' => sprintf(
                    __( 'Saved recurring source run stopped before candidates could be collected: %s', 'great-imports' ),
                    $exception->getMessage()
                ),
                'post_id' => 0,
                'event'   => array(),
            );
        }

        $message = isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '';
        $saved   = $this->saved_recurring_sources();
        $saved[ $source_id ]['last_run_status']  = ! empty( $result['success'] ) ? __( 'Completed', 'great-imports' ) : __( 'Failed', 'great-imports' );
        $saved[ $source_id ]['last_run_message'] = $message;
        $saved[ $source_id ]['last_run_at']      = current_time( 'mysql' );
        $saved[ $source_id ]['last_run_mode']    = sanitize_key( (string) $run_mode );
        $saved[ $source_id ]['last_post_id']     = isset( $result['post_id'] ) ? absint( $result['post_id'] ) : 0;
        $saved[ $source_id ]['next_run_at']      = ! empty( $saved[ $source_id ]['auto_enabled'] ) ? $this->recurring_source_next_run_time( $frequency_hours ) : '';
        $saved[ $source_id ]['updated_at']       = current_time( 'mysql' );
        update_option( 'great_imports_recurring_sources', $saved, false );

        $this->save_source_search_trace(
            array(
                'stage'             => 'ran_saved_recurring_source',
                'submitted_url'     => $url,
                'source_url'        => $url,
                'finished_at_gmt'   => gmdate( 'Y-m-d H:i:s' ),
                'plugin_version'    => defined( 'GREAT_IMPORTS_VERSION' ) ? GREAT_IMPORTS_VERSION : '',
                'admin_action'      => 'gi_run_recurring_source',
                'run_mode'          => sanitize_key( (string) $run_mode ),
                'success'           => ! empty( $result['success'] ),
                'message'           => $message,
                'saved_source_id'   => $source_id,
                'search_ahead_days' => $search_ahead_days,
                'post_id'           => isset( $result['post_id'] ) ? absint( $result['post_id'] ) : 0,
                'event_summary'     => isset( $result['event'] ) && is_array( $result['event'] ) ? $this->source_search_event_summary( $result['event'] ) : array(),
            )
        );

        return $result;
    }

    private function normalize_recurring_frequency( $hours ) {
        $hours = absint( $hours );
        $allowed = array_keys( $this->recurring_frequency_options() );
        return in_array( $hours, $allowed, true ) ? $hours : 24;
    }

    private function recurring_frequency_options() {
        return array(
            1   => __( 'Hourly', 'great-imports' ),
            6   => __( 'Every 6 hours', 'great-imports' ),
            12  => __( 'Every 12 hours', 'great-imports' ),
            24  => __( 'Daily', 'great-imports' ),
            168 => __( 'Weekly', 'great-imports' ),
        );
    }

    private function recurring_source_frequency_label( $hours ) {
        $hours = $this->normalize_recurring_frequency( $hours );
        $options = $this->recurring_frequency_options();
        return isset( $options[ $hours ] ) ? $options[ $hours ] : __( 'Daily', 'great-imports' );
    }

    private function recurring_source_next_run_time( $frequency_hours ) {
        $frequency_hours = $this->normalize_recurring_frequency( $frequency_hours );
        return date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $frequency_hours * HOUR_IN_SECONDS ) );
    }

    private function source_search_event_summary( array $event ) {
        $summary = array();
        foreach ( array( 'source_type', 'source_url', 'event_urls', 'results', 'evidence_bundle_id' ) as $key ) {
            if ( isset( $event[ $key ] ) ) {
                $summary[ $key ] = $event[ $key ];
            }
        }
        return $summary;
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
