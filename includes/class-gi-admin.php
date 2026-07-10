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
        add_action( 'admin_post_gi_eventbrite_import_once', array( $this, 'handle_eventbrite_import_once' ) );
        add_action( 'admin_post_gi_eventbrite_save_settings', array( $this, 'handle_eventbrite_save_settings' ) );
        add_action( 'admin_post_gi_download_exploratory_report', array( $this, 'handle_download_exploratory_report' ) );
        add_action( 'admin_post_gi_manual_data_removal', array( $this, 'handle_manual_data_removal' ) );
        add_action( 'admin_post_gi_save_candidate_review', array( $this, 'handle_save_candidate_review' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register_menu() {
        add_menu_page( __( 'Great Imports', 'great-imports' ), __( 'Great Imports', 'great-imports' ), 'manage_options', 'great-imports', array( $this, 'render_page' ), 'dashicons-download', 58 );
    }

    public function register_admin_bar( $wp_admin_bar ) {
        if ( current_user_can( 'manage_options' ) ) {
            $wp_admin_bar->add_node( array( 'id' => 'great-imports', 'title' => __( 'Great Imports', 'great-imports' ), 'href' => admin_url( 'admin.php?page=great-imports' ) ) );
        }
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_great-imports' === $hook ) {
            wp_enqueue_style( 'great-imports-admin', GREAT_IMPORTS_URL . 'assets/css/admin.css', array(), GREAT_IMPORTS_VERSION );
        }
    }

    public function handle_eventbrite_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to change Great Imports settings.', 'great-imports' ) );
        }
        check_admin_referer( 'gi_eventbrite_save_settings' );
        $clear = ! empty( $_POST['gi_eventbrite_clear_token'] );
        $token = isset( $_POST['gi_eventbrite_private_token'] ) ? wp_unslash( $_POST['gi_eventbrite_private_token'] ) : '';
        if ( $clear ) {
            $this->api_client->clear_private_token();
            $this->redirect_with_notice( 'success', __( 'Eventbrite private token cleared.', 'great-imports' ), 0 );
        } elseif ( '' !== trim( (string) $token ) ) {
            $this->api_client->save_private_token( $token );
            $this->redirect_with_notice( 'success', __( 'Eventbrite private token saved.', 'great-imports' ), 0 );
        }
        $this->redirect_with_notice( 'error', __( 'No Eventbrite token changes were made.', 'great-imports' ), 0 );
    }

    public function handle_download_exploratory_report() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to download Great Imports reports.', 'great-imports' ) );
        }
        check_admin_referer( 'gi_download_exploratory_report' );
        $this->exploratory_report->download();
    }

    public function handle_eventbrite_import_once() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to import events.', 'great-imports' ) );
        }
        check_admin_referer( 'gi_eventbrite_import_once' );
        $url    = isset( $_POST['gi_eventbrite_url'] ) ? wp_unslash( $_POST['gi_eventbrite_url'] ) : '';
        $result = $this->importer->import_once( $url );
        $this->redirect_with_notice( $result['success'] ? 'success' : 'error', $result['message'], absint( $result['post_id'] ) );
    }

    public function handle_manual_data_removal() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to remove Great Imports data.', 'great-imports' ) );
        }
        check_admin_referer( 'gi_manual_data_removal' );
        $confirmed = ! empty( $_POST['gi_manual_cleanup_confirm'] );
        $phrase    = isset( $_POST['gi_manual_cleanup_phrase'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['gi_manual_cleanup_phrase'] ) ) ) ) : '';
        if ( ! $confirmed || 'REMOVE' !== $phrase ) {
            $this->redirect_with_notice( 'error', __( 'Manual cleanup was not run. Check the confirmation box and type REMOVE.', 'great-imports' ), 0 );
        }
        $counts = GI_Data_Cleaner::cleanup();
        $this->redirect_with_notice( 'success', GI_Data_Cleaner::summary_message( $counts ), 0 );
    }

    public function handle_save_candidate_review() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to review Great Imports candidates.', 'great-imports' ) );
        }
        $candidate_id = isset( $_POST['gi_candidate_id'] ) ? absint( $_POST['gi_candidate_id'] ) : 0;
        check_admin_referer( 'gi_save_candidate_review_' . $candidate_id );
        $review_data = isset( $_POST['gi_review'] ) && is_array( $_POST['gi_review'] ) ? $_POST['gi_review'] : array();
        $result      = GI_Candidate_Review::save( $candidate_id, $review_data );
        $this->redirect_with_notice( $result['success'] ? 'success' : 'error', $result['message'], $candidate_id );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $store      = new GI_Candidate_Store();
        $candidates = $store->get_recent_candidates( 20 );
        ?>
        <div class="wrap gi-wrap">
            <h1><?php esc_html_e( 'Great Imports', 'great-imports' ); ?></h1>
            <?php $this->render_notice(); ?>
            <?php $this->render_settings_card(); ?>
            <?php $this->render_collect_card(); ?>
            <?php $this->render_report_card(); ?>
            <div class="gi-card">
                <h2><?php esc_html_e( 'Recent Review Candidates', 'great-imports' ); ?></h2>
                <?php if ( empty( $candidates ) ) : ?>
                    <p><?php esc_html_e( 'No candidates have been collected yet.', 'great-imports' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped gi-candidate-table">
                        <thead><tr><th><?php esc_html_e( 'Title', 'great-imports' ); ?></th><th><?php esc_html_e( 'Date', 'great-imports' ); ?></th><th><?php esc_html_e( 'Venue', 'great-imports' ); ?></th><th><?php esc_html_e( 'Status', 'great-imports' ); ?></th><th><?php esc_html_e( 'Action', 'great-imports' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $candidates as $candidate ) : ?>
                            <?php
                            $candidate_id = (int) $candidate->ID;
                            $preview      = $this->preview_builder->build_for_candidate( $candidate );
                            $title        = GI_Candidate_Review::value( $candidate_id, 'title', '', get_the_title( $candidate ) );
                            $date         = isset( $preview['public_event_fields']['start']['label'] ) ? (string) $preview['public_event_fields']['start']['label'] : GI_Candidate_Review::value( $candidate_id, 'start_date' );
                            $venue        = GI_Candidate_Review::value( $candidate_id, 'location_name' );
                            $status       = GI_Candidate_Review::value( $candidate_id, 'review_status', 'candidate_status', 'needs_review' );
                            $source_url   = (string) get_post_meta( $candidate_id, '_gi_source_url', true );
                            ?>
                            <tr><td><strong><?php echo esc_html( $title ); ?></strong></td><td><?php echo esc_html( $date ); ?></td><td><?php echo esc_html( $venue ); ?></td><td><?php echo esc_html( $this->review_status_label( $status ) ); ?></td><td><?php esc_html_e( 'Review', 'great-imports' ); ?></td></tr>
                            <tr class="gi-preview-row"><td colspan="5"><details class="gi-preview gi-review-editor"><summary><?php esc_html_e( 'Review Candidate / Dry Run', 'great-imports' ); ?></summary><?php $this->render_candidate_review_workspace( $candidate, $preview, $source_url ); ?></details></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php $this->render_manual_data_removal(); ?>
        </div>
        <?php
    }

    private function render_settings_card() {
        ?>
        <div class="gi-card"><h2><?php esc_html_e( 'Eventbrite API Settings', 'great-imports' ); ?></h2><p><strong><?php esc_html_e( 'Status:', 'great-imports' ); ?></strong> <?php echo esc_html( $this->api_client->has_private_token() ? __( 'Private token configured', 'great-imports' ) : __( 'Private token not configured', 'great-imports' ) ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'gi_eventbrite_save_settings' ); ?><input type="hidden" name="action" value="gi_eventbrite_save_settings" /><label for="gi_eventbrite_private_token" class="gi-label"><?php esc_html_e( 'Eventbrite private token', 'great-imports' ); ?></label><input type="password" class="regular-text gi-token-input" id="gi_eventbrite_private_token" name="gi_eventbrite_private_token" value="" autocomplete="off" placeholder="<?php echo esc_attr( $this->api_client->has_private_token() ? __( 'Configured — enter a new token to replace it', 'great-imports' ) : __( 'Paste private token', 'great-imports' ) ); ?>" /><label class="gi-inline-check"><input type="checkbox" name="gi_eventbrite_clear_token" value="1" /> <?php esc_html_e( 'Clear saved token', 'great-imports' ); ?></label><?php submit_button( __( 'Save Eventbrite settings', 'great-imports' ), 'secondary', 'submit', false ); ?></form></div>
        <?php
    }

    private function render_collect_card() {
        ?>
        <div class="gi-card"><h2><?php esc_html_e( 'One-time Eventbrite Import', 'great-imports' ); ?></h2><p><strong><?php esc_html_e( 'Current stage:', 'great-imports' ); ?></strong> <?php esc_html_e( 'Evidence collection and review/dry run only. This screen does not create Events Manager events yet.', 'great-imports' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'gi_eventbrite_import_once' ); ?><input type="hidden" name="action" value="gi_eventbrite_import_once" /><label for="gi_eventbrite_url" class="gi-label"><?php esc_html_e( 'Eventbrite URL', 'great-imports' ); ?></label><input type="url" class="regular-text gi-url-input" id="gi_eventbrite_url" name="gi_eventbrite_url" placeholder="https://www.eventbrite.com/e/example-event-tickets-123456789" required /><?php submit_button( __( 'Collect evidence / refresh review', 'great-imports' ), 'primary', 'submit', false ); ?></form></div>
        <?php
    }

    private function render_report_card() {
        ?>
        <div class="gi-card"><h2><?php esc_html_e( 'Exploratory Report', 'great-imports' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'gi_download_exploratory_report' ); ?><input type="hidden" name="action" value="gi_download_exploratory_report" /><?php submit_button( __( 'Download Exploratory Report', 'great-imports' ), 'secondary', 'submit', false ); ?></form></div>
        <?php
    }

    private function render_candidate_review_workspace( $candidate, array $preview, $source_url = '' ) {
        $candidate_id = (int) $candidate->ID;
        $suggestions  = GI_Candidate_Review::location_suggestions( $candidate_id, 8 );
        $title        = GI_Candidate_Review::value( $candidate_id, 'title', '', get_the_title( $candidate ) );
        $status       = GI_Candidate_Review::value( $candidate_id, 'review_status', 'candidate_status', 'needs_review' );
        $start_label  = isset( $preview['public_event_fields']['start']['label'] ) ? (string) $preview['public_event_fields']['start']['label'] : GI_Candidate_Review::value( $candidate_id, 'start_date' );
        $location     = GI_Candidate_Review::value( $candidate_id, 'location_name' );
        $address      = trim( implode( ', ', array_filter( array( GI_Candidate_Review::value( $candidate_id, 'location_address_1' ), GI_Candidate_Review::value( $candidate_id, 'location_city' ), GI_Candidate_Review::value( $candidate_id, 'location_state' ), GI_Candidate_Review::value( $candidate_id, 'location_postal_code' ) ) ) ) );
        ?>
        <div class="gi-review-workspace-simple">
            <div class="gi-review-summary-simple"><strong><?php echo esc_html( $title ); ?></strong><span><?php echo esc_html( $start_label ); ?></span><span><?php echo esc_html( trim( $location . ( $address ? ' — ' . $address : '' ) ) ); ?></span><span><?php echo esc_html( $this->review_status_label( $status ) ); ?></span></div>
            <?php $this->render_event_section( $candidate_id, $title ); ?>
            <?php $this->render_location_section( $candidate_id ); ?>
            <?php $this->render_ticket_section( $candidate_id ); ?>
            <?php $this->render_description_section( $candidate_id, $preview ); ?>
            <?php $this->render_image_section( $candidate_id ); ?>
            <?php $this->render_decision_section( $candidate_id, $status, $suggestions ); ?>
            <?php $this->render_advanced_details( $candidate_id, $candidate, $preview, $source_url ); ?>
        </div>
        <?php
    }

    private function render_event_section( $candidate_id, $title ) {
        $this->section_open( $candidate_id, 'event', __( 'Event', 'great-imports' ) );
        echo '<div class="gi-section-grid"><div class="gi-field gi-field-wide">';
        $this->render_text_input( $candidate_id, 'title', __( 'Title', 'great-imports' ), $title );
        echo '</div>';
        $this->render_datetime_controls( $candidate_id, 'start_date', __( 'Start', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'start_date' ) );
        $this->render_datetime_controls( $candidate_id, 'end_date', __( 'End', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'end_date' ) );
        echo '<div class="gi-field gi-field-wide">';
        $this->render_text_input( $candidate_id, 'timezone', __( 'Timezone', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'timezone' ) );
        echo '</div></div>';
        $this->section_close( __( 'Save event', 'great-imports' ) );
    }

    private function render_location_section( $candidate_id ) {
        $this->section_open( $candidate_id, 'location', __( 'Location', 'great-imports' ) );
        echo '<div class="gi-section-grid">';
        foreach ( array( 'location_name' => __( 'Venue name', 'great-imports' ), 'location_address_1' => __( 'Address', 'great-imports' ), 'location_address_2' => __( 'Address 2', 'great-imports' ) ) as $key => $label ) {
            echo '<div class="gi-field gi-field-wide">'; $this->render_text_input( $candidate_id, $key, $label, GI_Candidate_Review::value( $candidate_id, $key ) ); echo '</div>';
        }
        foreach ( array( 'location_city' => __( 'City', 'great-imports' ), 'location_state' => __( 'State', 'great-imports' ), 'location_postal_code' => __( 'ZIP', 'great-imports' ), 'location_country' => __( 'Country', 'great-imports' ) ) as $key => $label ) {
            echo '<div class="gi-field">'; $this->render_text_input( $candidate_id, $key, $label, GI_Candidate_Review::value( $candidate_id, $key ) ); echo '</div>';
        }
        echo '<div class="gi-field gi-field-wide">'; $this->render_text_input( $candidate_id, 'stage_room', __( 'Stage / room', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'stage_room' ) ); echo '</div></div>';
        $this->section_close( __( 'Save location', 'great-imports' ) );
    }

    private function render_ticket_section( $candidate_id ) {
        $this->section_open( $candidate_id, 'tickets', __( 'Tickets', 'great-imports' ) );
        echo '<div class="gi-section-grid"><div class="gi-field gi-field-wide">';
        $this->render_text_input( $candidate_id, 'ticket_url', __( 'Ticket URL', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'ticket_url' ) );
        echo '</div><div class="gi-field">'; $this->render_text_input( $candidate_id, 'price', __( 'Price', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'price' ) );
        echo '</div><div class="gi-field">'; $this->render_text_input( $candidate_id, 'price_currency', __( 'Currency', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'price_currency' ) );
        echo '</div></div>';
        $this->section_close( __( 'Save tickets', 'great-imports' ) );
    }

    private function render_description_section( $candidate_id, array $preview ) {
        $this->section_open( $candidate_id, 'description', __( 'Description', 'great-imports' ) );
        $review = get_post_meta( $candidate_id, '_gi_review_description_html', true );
        $value  = '' !== trim( (string) $review ) ? (string) $review : ( isset( $preview['description_html'] ) ? (string) $preview['description_html'] : '' );
        $this->render_textarea( $candidate_id, 'description_html', __( 'Public description HTML', 'great-imports' ), $value, 12 );
        echo '<p class="description">' . esc_html__( 'This is the dry-run description that would be handed to Events Manager later.', 'great-imports' ) . '</p>';
        $this->section_close( __( 'Save description', 'great-imports' ) );
    }

    private function render_image_section( $candidate_id ) {
        $this->section_open( $candidate_id, 'image', __( 'Image', 'great-imports' ) );
        $this->render_text_input( $candidate_id, 'image_url', __( 'Image URL', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'image_url' ) );
        echo '<p class="description">' . esc_html__( 'Later import should download the event image into the WordPress Media Library and assign it as featured image.', 'great-imports' ) . '</p>';
        $this->section_close( __( 'Save image', 'great-imports' ) );
    }

    private function render_decision_section( $candidate_id, $status, array $suggestions ) {
        $this->section_open( $candidate_id, 'decision', __( 'Review decision', 'great-imports' ) );
        echo '<div class="gi-section-grid"><div class="gi-field">';
        $this->render_select_field( $candidate_id, 'review_status', __( 'Review status', 'great-imports' ), GI_Candidate_Review::review_status_options(), $status );
        echo '</div><div class="gi-field">';
        $this->render_select_field( $candidate_id, 'location_decision', __( 'Location decision', 'great-imports' ), GI_Candidate_Review::location_decision_options(), GI_Candidate_Review::review_value( $candidate_id, 'location_decision' ) );
        echo '</div><div class="gi-field">';
        $this->render_select_field( $candidate_id, 'address_verification', __( 'Address verification', 'great-imports' ), GI_Candidate_Review::address_verification_options(), GI_Candidate_Review::review_value( $candidate_id, 'address_verification' ) );
        echo '</div><div class="gi-field">';
        $this->render_em_location_select( $candidate_id, $suggestions );
        echo '</div><div class="gi-field gi-field-wide">';
        $this->render_textarea( $candidate_id, 'reviewer_notes', __( 'Reviewer notes', 'great-imports' ), GI_Candidate_Review::review_value( $candidate_id, 'reviewer_notes' ), 4 );
        echo '</div></div>';
        $this->section_close( __( 'Save decision', 'great-imports' ) );
    }

    private function render_advanced_details( $candidate_id, $candidate, array $preview, $source_url ) {
        ?>
        <details class="gi-advanced-details"><summary><?php esc_html_e( 'Advanced source/debug details', 'great-imports' ); ?></summary><div class="gi-advanced-grid"><section class="gi-preview-section"><h3><?php esc_html_e( 'Source values preserved', 'great-imports' ); ?></h3><?php $this->render_source_value_summary( $candidate_id, $candidate ); ?></section><section class="gi-preview-section"><h3><?php esc_html_e( 'Internal-only source tracking', 'great-imports' ); ?></h3><?php $this->render_key_value_list( isset( $preview['internal_tracking'] ) && is_array( $preview['internal_tracking'] ) ? $preview['internal_tracking'] : array() ); ?><?php if ( $source_url ) : ?><p><a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open source page', 'great-imports' ); ?></a></p><?php endif; ?></section><section class="gi-preview-section"><h3><?php esc_html_e( 'Excluded from public import', 'great-imports' ); ?></h3><?php $this->render_simple_list( isset( $preview['excluded_public_data'] ) && is_array( $preview['excluded_public_data'] ) ? $preview['excluded_public_data'] : array() ); ?></section></div></details>
        <?php
    }

    private function section_open( $candidate_id, $section_key, $heading ) {
        ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gi-review-section-form gi-review-section-<?php echo esc_attr( sanitize_key( $section_key ) ); ?>"><?php wp_nonce_field( 'gi_save_candidate_review_' . absint( $candidate_id ) ); ?><input type="hidden" name="action" value="gi_save_candidate_review" /><input type="hidden" name="gi_candidate_id" value="<?php echo esc_attr( absint( $candidate_id ) ); ?>" /><input type="hidden" name="gi_review_section" value="<?php echo esc_attr( sanitize_key( $section_key ) ); ?>" /><h3><?php echo esc_html( $heading ); ?></h3><?php
    }

    private function section_close( $button_label ) {
        ?><p class="submit gi-section-submit"><?php submit_button( $button_label, 'secondary', 'submit', false ); ?></p></form><?php
    }

    private function render_datetime_controls( $candidate_id, $key, $label, $value ) {
        $date_id = 'gi_review_' . absint( $candidate_id ) . '_' . sanitize_key( $key ) . '_date';
        $time_id = 'gi_review_' . absint( $candidate_id ) . '_' . sanitize_key( $key ) . '_time';
        ?><div class="gi-field gi-datetime-field"><span class="gi-label"><?php echo esc_html( $label ); ?></span><div class="gi-datetime-controls"><label for="<?php echo esc_attr( $date_id ); ?>"><span><?php esc_html_e( 'Date', 'great-imports' ); ?></span><input type="date" id="<?php echo esc_attr( $date_id ); ?>" name="gi_review[<?php echo esc_attr( sanitize_key( $key ) ); ?>_date]" value="<?php echo esc_attr( $this->datetime_date_part( $value ) ); ?>" /></label><label for="<?php echo esc_attr( $time_id ); ?>"><span><?php esc_html_e( 'Time', 'great-imports' ); ?></span><input type="time" id="<?php echo esc_attr( $time_id ); ?>" name="gi_review[<?php echo esc_attr( sanitize_key( $key ) ); ?>_time]" value="<?php echo esc_attr( $this->datetime_time_part( $value ) ); ?>" /></label></div></div><?php
    }

    private function datetime_date_part( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $matches ) ) {
            return $matches[1];
        }
        $timestamp = strtotime( $value );
        return $timestamp ? date( 'Y-m-d', $timestamp ) : '';
    }

    private function datetime_time_part( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/T(\d{2}:\d{2})/', $value, $matches ) || preg_match( '/\s(\d{2}:\d{2})/', $value, $matches ) ) {
            return $matches[1];
        }
        $timestamp = strtotime( $value );
        return $timestamp ? date( 'H:i', $timestamp ) : '';
    }

    private function render_manual_data_removal() {
        ?><div class="gi-card gi-danger-card"><details class="gi-danger-details"><summary><?php esc_html_e( 'Danger Zone: Manual Data Removal', 'great-imports' ); ?></summary><div class="gi-danger-body"><h2><?php esc_html_e( 'Manual Data Removal', 'great-imports' ); ?></h2><p><strong><?php esc_html_e( 'Use this only when uninstall cleanup did not remove Great Imports data.', 'great-imports' ); ?></strong></p><p><?php esc_html_e( 'This removes only Great Imports-owned data. It does not delete Events Manager events, locations, tickets, media, categories, tags, or venue data.', 'great-imports' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'gi_manual_data_removal' ); ?><input type="hidden" name="action" value="gi_manual_data_removal" /><label class="gi-label"><input type="checkbox" name="gi_manual_cleanup_confirm" value="1" required /> <?php esc_html_e( 'I understand this permanently removes Great Imports review/evidence data and the saved Eventbrite token.', 'great-imports' ); ?></label><label for="gi_manual_cleanup_phrase" class="gi-label"><?php esc_html_e( 'Type REMOVE to confirm', 'great-imports' ); ?></label><input type="text" class="regular-text" id="gi_manual_cleanup_phrase" name="gi_manual_cleanup_phrase" value="" autocomplete="off" required /><?php submit_button( __( 'Remove Great Imports Data', 'great-imports' ), 'delete', 'submit', false ); ?></form></div></details></div><?php
    }

    private function render_text_input( $candidate_id, $key, $label, $value ) {
        $id = 'gi_review_' . absint( $candidate_id ) . '_' . sanitize_key( $key );
        ?><label for="<?php echo esc_attr( $id ); ?>" class="gi-label"><?php echo esc_html( $label ); ?></label><input type="text" class="regular-text gi-review-input" id="<?php echo esc_attr( $id ); ?>" name="gi_review[<?php echo esc_attr( sanitize_key( $key ) ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" /><?php
    }

    private function render_textarea( $candidate_id, $key, $label, $value, $rows = 4 ) {
        $id = 'gi_review_' . absint( $candidate_id ) . '_' . sanitize_key( $key );
        ?><label for="<?php echo esc_attr( $id ); ?>" class="gi-label"><?php echo esc_html( $label ); ?></label><textarea class="large-text gi-review-textarea" rows="<?php echo esc_attr( max( 2, absint( $rows ) ) ); ?>" id="<?php echo esc_attr( $id ); ?>" name="gi_review[<?php echo esc_attr( sanitize_key( $key ) ); ?>]"><?php echo esc_textarea( (string) $value ); ?></textarea><?php
    }

    private function render_select_field( $candidate_id, $key, $label, array $options, $selected ) {
        $id       = 'gi_review_' . absint( $candidate_id ) . '_' . sanitize_key( $key );
        $selected = sanitize_key( (string) $selected );
        ?><label for="<?php echo esc_attr( $id ); ?>" class="gi-label"><?php echo esc_html( $label ); ?></label><select id="<?php echo esc_attr( $id ); ?>" name="gi_review[<?php echo esc_attr( sanitize_key( $key ) ); ?>]" class="gi-review-select"><option value=""><?php esc_html_e( 'No reviewer decision yet', 'great-imports' ); ?></option><?php foreach ( $options as $value => $text ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $text ); ?></option><?php endforeach; ?></select><?php
    }

    private function render_em_location_select( $candidate_id, array $suggestions ) {
        $selected = absint( GI_Candidate_Review::review_value( $candidate_id, 'em_location_id' ) );
        ?><label class="gi-label"><?php esc_html_e( 'Suggested Events Manager location', 'great-imports' ); ?></label><select name="gi_review[em_location_id]" class="gi-review-select gi-em-location-select"><option value="0"><?php esc_html_e( 'No existing EM location selected', 'great-imports' ); ?></option><?php foreach ( $suggestions as $suggestion ) : ?><?php $value = isset( $suggestion['id'] ) ? absint( $suggestion['id'] ) : 0; ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $this->format_em_location_suggestion( $suggestion ) ); ?></option><?php endforeach; ?></select><?php
    }

    private function format_em_location_suggestion( array $suggestion ) {
        $parts = array_filter( array( isset( $suggestion['name'] ) ? $suggestion['name'] : '', isset( $suggestion['address'] ) ? $suggestion['address'] : '', trim( ( isset( $suggestion['city'] ) ? $suggestion['city'] : '' ) . ', ' . ( isset( $suggestion['state'] ) ? $suggestion['state'] : '' ) . ' ' . ( isset( $suggestion['postcode'] ) ? $suggestion['postcode'] : '' ) ) ) );
        return implode( ' — ', array_map( 'sanitize_text_field', $parts ) );
    }

    private function render_source_value_summary( $candidate_id, $candidate ) {
        $rows = array( __( 'Source title', 'great-imports' ) => get_the_title( $candidate ), __( 'Source start', 'great-imports' ) => GI_Candidate_Review::source_value( $candidate_id, 'start_date' ), __( 'Source end', 'great-imports' ) => GI_Candidate_Review::source_value( $candidate_id, 'end_date' ), __( 'Source location', 'great-imports' ) => GI_Candidate_Review::source_value( $candidate_id, 'location_name' ), __( 'Source address', 'great-imports' ) => GI_Candidate_Review::source_value( $candidate_id, 'location_address_1' ) );
        $this->render_key_value_list( $rows );
    }

    private function render_key_value_list( array $rows ) {
        if ( empty( $rows ) ) { echo '<p>' . esc_html__( 'None found', 'great-imports' ) . '</p>'; return; }
        echo '<table class="widefat gi-kv"><tbody>';
        foreach ( $rows as $key => $value ) { echo '<tr><th>' . esc_html( (string) $key ) . '</th><td>' . esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) . '</td></tr>'; }
        echo '</tbody></table>';
    }

    private function render_simple_list( array $items ) {
        if ( empty( $items ) ) { echo '<p>' . esc_html__( 'None found', 'great-imports' ) . '</p>'; return; }
        echo '<ul class="gi-simple-list">'; foreach ( $items as $item ) { echo '<li>' . esc_html( (string) $item ) . '</li>'; } echo '</ul>';
    }

    private function review_status_label( $status ) {
        $options = GI_Candidate_Review::review_status_options();
        $status  = sanitize_key( (string) $status );
        return isset( $options[ $status ] ) ? $options[ $status ] : $status;
    }

    private function redirect_with_notice( $status, $message, $candidate_id = 0 ) {
        wp_safe_redirect( add_query_arg( array( 'page' => 'great-imports', 'gi_status' => sanitize_key( $status ), 'gi_message' => rawurlencode( (string) $message ), 'gi_candidate' => absint( $candidate_id ) ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private function render_notice() {
        if ( empty( $_GET['gi_message'] ) ) { return; }
        $status  = isset( $_GET['gi_status'] ) && 'success' === $_GET['gi_status'] ? 'success' : 'error';
        $message = sanitize_text_field( wp_unslash( $_GET['gi_message'] ) );
        printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $status ), esc_html( $message ) );
    }
}
