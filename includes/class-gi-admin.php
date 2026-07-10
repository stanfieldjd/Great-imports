<?php
/** Admin UI for Great Imports. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GI_Admin {
    private $importer, $api_client, $exploratory_report, $preview_builder;

    public function __construct( GI_Eventbrite_Importer $importer, GI_Eventbrite_API_Client $api_client, GI_Exploratory_Report $exploratory_report, GI_Import_Preview_Builder $preview_builder ) {
        $this->importer = $importer; $this->api_client = $api_client; $this->exploratory_report = $exploratory_report; $this->preview_builder = $preview_builder;
    }

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_bar_menu', array( $this, 'register_admin_bar' ), 90 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_gi_eventbrite_import_once', array( $this, 'handle_eventbrite_import_once' ) );
        add_action( 'admin_post_gi_eventbrite_save_settings', array( $this, 'handle_eventbrite_save_settings' ) );
        add_action( 'admin_post_gi_download_exploratory_report', array( $this, 'handle_download_exploratory_report' ) );
        add_action( 'admin_post_gi_manual_data_removal', array( $this, 'handle_manual_data_removal' ) );
        add_action( 'admin_post_gi_save_candidate_review', array( $this, 'handle_save_candidate_review' ) );
    }

    public function register_menu() { add_menu_page( __( 'Great Imports', 'great-imports' ), __( 'Great Imports', 'great-imports' ), 'manage_options', 'great-imports', array( $this, 'render_page' ), 'dashicons-download', 58 ); }
    public function register_admin_bar( $bar ) { if ( current_user_can( 'manage_options' ) ) { $bar->add_node( array( 'id' => 'great-imports', 'title' => __( 'Great Imports', 'great-imports' ), 'href' => admin_url( 'admin.php?page=great-imports' ) ) ); } }
    public function enqueue_assets( $hook ) { if ( 'toplevel_page_great-imports' === $hook ) { wp_enqueue_style( 'great-imports-admin', GREAT_IMPORTS_URL . 'assets/css/admin.css', array(), GREAT_IMPORTS_VERSION ); } }

    public function handle_eventbrite_save_settings() {
        $this->guard( 'change Great Imports settings' ); check_admin_referer( 'gi_eventbrite_save_settings' );
        $clear = ! empty( $_POST['gi_eventbrite_clear_token'] ); $token = isset( $_POST['gi_eventbrite_private_token'] ) ? wp_unslash( $_POST['gi_eventbrite_private_token'] ) : '';
        if ( $clear ) { $this->api_client->clear_private_token(); $this->redirect_with_notice( 'success', __( 'Eventbrite private token cleared.', 'great-imports' ), 0 ); }
        if ( '' !== trim( (string) $token ) ) { $this->api_client->save_private_token( $token ); $this->redirect_with_notice( 'success', __( 'Eventbrite private token saved.', 'great-imports' ), 0 ); }
        $this->redirect_with_notice( 'error', __( 'No Eventbrite token changes were made.', 'great-imports' ), 0 );
    }

    public function handle_download_exploratory_report() { $this->guard( 'download Great Imports reports' ); check_admin_referer( 'gi_download_exploratory_report' ); $this->exploratory_report->download(); }
    public function handle_eventbrite_import_once() { $this->guard( 'import events' ); check_admin_referer( 'gi_eventbrite_import_once' ); $url = isset( $_POST['gi_eventbrite_url'] ) ? wp_unslash( $_POST['gi_eventbrite_url'] ) : ''; $r = $this->importer->import_once( $url ); $this->redirect_with_notice( $r['success'] ? 'success' : 'error', $r['message'], absint( $r['post_id'] ) ); }
    public function handle_save_candidate_review() { $this->guard( 'edit Great Imports candidates' ); $id = isset( $_POST['gi_candidate_id'] ) ? absint( $_POST['gi_candidate_id'] ) : 0; check_admin_referer( 'gi_save_candidate_review_' . $id ); $data = isset( $_POST['gi_review'] ) && is_array( $_POST['gi_review'] ) ? $_POST['gi_review'] : array(); $r = GI_Candidate_Review::save( $id, $data ); $this->redirect_with_notice( $r['success'] ? 'success' : 'error', $r['message'], $id ); }

    public function handle_manual_data_removal() {
        $this->guard( 'remove Great Imports data' ); check_admin_referer( 'gi_manual_data_removal' );
        $ok = ! empty( $_POST['gi_manual_cleanup_confirm'] ); $phrase = isset( $_POST['gi_manual_cleanup_phrase'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['gi_manual_cleanup_phrase'] ) ) ) ) : '';
        if ( ! $ok || 'REMOVE' !== $phrase ) { $this->redirect_with_notice( 'error', __( 'Manual cleanup was not run. Check the confirmation box and type REMOVE.', 'great-imports' ), 0 ); }
        $this->redirect_with_notice( 'success', GI_Data_Cleaner::summary_message( GI_Data_Cleaner::cleanup() ), 0 );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $candidates = ( new GI_Candidate_Store() )->get_recent_candidates( 20 );
        echo '<div class="wrap gi-wrap"><h1>' . esc_html__( 'Great Imports', 'great-imports' ) . '</h1>'; $this->render_notice(); $this->settings_card(); $this->collect_card(); $this->report_card();
        echo '<div class="gi-card gi-candidates-card"><h2>' . esc_html__( 'Recent Event Candidates', 'great-imports' ) . '</h2>';
        if ( empty( $candidates ) ) { echo '<p>' . esc_html__( 'No candidates have been collected yet.', 'great-imports' ) . '</p></div>'; } else { $this->candidate_table( $candidates ); echo '</div>'; }
        $this->manual_data_removal(); echo '</div>';
    }

    private function settings_card() {
        echo '<div class="gi-card"><h2>' . esc_html__( 'Eventbrite API Settings', 'great-imports' ) . '</h2><p><strong>' . esc_html__( 'Status:', 'great-imports' ) . '</strong> ' . esc_html( $this->api_client->has_private_token() ? __( 'Private token configured', 'great-imports' ) : __( 'Private token not configured', 'great-imports' ) ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_eventbrite_save_settings' );
        echo '<input type="hidden" name="action" value="gi_eventbrite_save_settings"><label class="gi-label" for="gi_eventbrite_private_token">' . esc_html__( 'Eventbrite private token', 'great-imports' ) . '</label><input type="password" class="regular-text gi-token-input" id="gi_eventbrite_private_token" name="gi_eventbrite_private_token" placeholder="' . esc_attr( $this->api_client->has_private_token() ? __( 'Configured — enter a new token to replace it', 'great-imports' ) : __( 'Paste private token', 'great-imports' ) ) . '"><label class="gi-inline-check"><input type="checkbox" name="gi_eventbrite_clear_token" value="1"> ' . esc_html__( 'Clear saved token', 'great-imports' ) . '</label> ';
        submit_button( __( 'Save Eventbrite settings', 'great-imports' ), 'secondary', 'submit', false ); echo '</form></div>';
    }

    private function collect_card() {
        echo '<div class="gi-card"><h2>' . esc_html__( 'One-time Eventbrite Import', 'great-imports' ) . '</h2><p><strong>' . esc_html__( 'Current stage:', 'great-imports' ) . '</strong> ' . esc_html__( 'Evidence collection and candidate dry run only. This screen does not create Events Manager events yet.', 'great-imports' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'gi_eventbrite_import_once' );
        echo '<input type="hidden" name="action" value="gi_eventbrite_import_once"><label class="gi-label" for="gi_eventbrite_url">' . esc_html__( 'Eventbrite URL', 'great-imports' ) . '</label><input type="url" class="regular-text gi-url-input" id="gi_eventbrite_url" name="gi_eventbrite_url" placeholder="https://www.eventbrite.com/e/example-event-tickets-123456789" required> ';
        submit_button( __( 'Collect evidence / refresh candidate', 'great-imports' ), 'primary', 'submit', false ); echo '</form></div>';
    }

    private function report_card() { echo '<div class="gi-card"><h2>' . esc_html__( 'Exploratory Report', 'great-imports' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'gi_download_exploratory_report' ); echo '<input type="hidden" name="action" value="gi_download_exploratory_report">'; submit_button( __( 'Download Exploratory Report', 'great-imports' ), 'secondary', 'submit', false ); echo '</form></div>'; }

    private function candidate_table( array $candidates ) {
        echo '<table class="widefat striped gi-candidate-table"><thead><tr><th class="column-primary">' . esc_html__( 'Title', 'great-imports' ) . '</th><th>' . esc_html__( 'Date', 'great-imports' ) . '</th><th>' . esc_html__( 'Venue', 'great-imports' ) . '</th><th>' . esc_html__( 'Source', 'great-imports' ) . '</th><th>' . esc_html__( 'Action', 'great-imports' ) . '</th></tr></thead><tbody>';
        foreach ( $candidates as $candidate ) {
            $id = (int) $candidate->ID; $preview = $this->preview_builder->build_for_candidate( $candidate ); $title = GI_Candidate_Review::value( $id, 'title', '', get_the_title( $candidate ) ); $date = isset( $preview['public_event_fields']['start']['label'] ) ? (string) $preview['public_event_fields']['start']['label'] : GI_Candidate_Review::value( $id, 'start_date' ); $venue = GI_Candidate_Review::value( $id, 'location_name' ); $source = GI_Candidate_Review::source_value( $id, 'source_type' ); $url = (string) get_post_meta( $id, '_gi_source_url', true ); $excerpt = wp_trim_words( wp_strip_all_tags( $candidate->post_content ), 28 );
            echo '<tr class="gi-candidate-main-row"><td class="title column-title column-primary"><strong>' . esc_html( $title ) . '</strong>' . ( $excerpt ? '<p class="gi-candidate-excerpt">' . esc_html( $excerpt ) . '</p>' : '' ) . '<div class="row-actions"><span><a href="#gi-candidate-' . esc_attr( $id ) . '">' . esc_html__( 'Open below', 'great-imports' ) . '</a></span>' . ( $url ? ' | <span><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Source', 'great-imports' ) . '</a></span>' : '' ) . '</div></td><td>' . esc_html( $date ) . '</td><td>' . esc_html( $venue ) . '</td><td>' . esc_html( $source ? $source : __( 'Source', 'great-imports' ) ) . '</td><td>' . esc_html__( 'Open below', 'great-imports' ) . '</td></tr>';
            echo '<tr class="gi-candidate-detail-row"><td colspan="5"><details id="gi-candidate-' . esc_attr( $id ) . '" class="gi-candidate-details"><summary>' . esc_html__( 'Open candidate / dry run', 'great-imports' ) . '</summary>'; $this->candidate_workspace( $candidate, $preview, $url ); echo '</details></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function candidate_workspace( $candidate, array $preview, $source_url ) {
        $id = (int) $candidate->ID; $title = GI_Candidate_Review::value( $id, 'title', '', get_the_title( $candidate ) ); $start = GI_Candidate_Review::value( $id, 'start_date' ); $end = GI_Candidate_Review::value( $id, 'end_date' ); $tz = GI_Candidate_Review::value( $id, 'timezone' ); $venue = GI_Candidate_Review::value( $id, 'location_name' ); $address = $this->address_label( $id );
        echo '<div class="gi-candidate-workspace"><div class="gi-candidate-headline"><strong>' . esc_html( $title ) . '</strong><span>' . esc_html( trim( $this->datetime_label( $start ) . ( $venue ? ' · ' . $venue : '' ) ) ) . '</span>' . ( $address ? '<span>' . esc_html( $address ) . '</span>' : '' ) . '</div>';
        echo '<section class="gi-slim-panel"><h3>' . esc_html__( 'Event', 'great-imports' ) . '</h3>'; $this->text_row( $id, __( 'Title', 'great-imports' ), 'title', $title ); $this->datetime_row( $id, __( 'Start', 'great-imports' ), 'start_date', $start ); $this->datetime_row( $id, __( 'End', 'great-imports' ), 'end_date', $end ); $this->text_row( $id, __( 'Timezone', 'great-imports' ), 'timezone', $tz ); echo '</section>';
        echo '<section class="gi-slim-panel"><h3>' . esc_html__( 'Location', 'great-imports' ) . '</h3>'; $this->text_row( $id, __( 'Venue name', 'great-imports' ), 'location_name', $venue ); $this->address_match_row( $id, GI_Candidate_Review::location_suggestions( $id, 8 ) ); $this->text_row( $id, __( 'Stage / room', 'great-imports' ), 'stage_room', GI_Candidate_Review::value( $id, 'stage_room' ), __( 'None', 'great-imports' ) ); echo '</section>';
        $this->description_panel( $id, $preview ); $this->image_panel( $id ); $this->ticket_panel( $id, $preview ); $this->advanced_details( $id, $candidate, $preview, $source_url ); echo '</div>';
    }

    private function text_row( $id, $label, $key, $value, $empty = '' ) {
        $empty = $empty ? $empty : __( 'None found', 'great-imports' ); echo '<div class="gi-field-row"><div class="gi-field-main"><span class="gi-field-label">' . esc_html( $label ) . '</span><span class="gi-field-value">' . esc_html( '' !== trim( (string) $value ) ? (string) $value : $empty ) . '</span></div><details class="gi-field-edit"><summary>' . esc_html__( 'Edit', 'great-imports' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; $this->form_hidden( $id ); echo '<label class="gi-label">' . esc_html( $label ) . '</label><input type="text" class="regular-text" name="gi_review[' . esc_attr( sanitize_key( $key ) ) . ']" value="' . esc_attr( (string) $value ) . '">'; submit_button( __( 'Save', 'great-imports' ), 'secondary small', 'submit', false ); echo '</form></details></div>';
    }

    private function datetime_row( $id, $label, $key, $value ) {
        echo '<div class="gi-field-row"><div class="gi-field-main"><span class="gi-field-label">' . esc_html( $label ) . '</span><span class="gi-field-value">' . esc_html( $this->datetime_label( $value ) ) . '</span></div><details class="gi-field-edit"><summary>' . esc_html__( 'Edit', 'great-imports' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; $this->form_hidden( $id ); echo '<div class="gi-inline-fields"><label><span>' . esc_html__( 'Date', 'great-imports' ) . '</span><input type="date" name="gi_review[' . esc_attr( sanitize_key( $key ) ) . '_date]" value="' . esc_attr( $this->date_part( $value ) ) . '"></label><label><span>' . esc_html__( 'Time', 'great-imports' ) . '</span><input type="time" name="gi_review[' . esc_attr( sanitize_key( $key ) ) . '_time]" value="' . esc_attr( $this->time_part( $value ) ) . '"></label></div>'; submit_button( __( 'Save', 'great-imports' ), 'secondary small', 'submit', false ); echo '</form></details></div>';
    }

    private function address_match_row( $id, array $suggestions ) {
        $selected = absint( GI_Candidate_Review::review_value( $id, 'em_location_id' ) );
        echo '<div class="gi-field-row gi-location-match-row"><div class="gi-field-main"><span class="gi-field-label">' . esc_html__( 'Address / matched EM location', 'great-imports' ) . '</span><span class="gi-field-value">' . esc_html( $this->address_label( $id ) ? $this->address_label( $id ) : __( 'No address found', 'great-imports' ) ) . '</span><span class="gi-field-subvalue">' . esc_html( sprintf( __( 'Matched EM location: %s', 'great-imports' ), $this->selected_location_label( $selected, $suggestions ) ) ) . '</span></div><details class="gi-field-edit"><summary>' . esc_html__( 'Edit', 'great-imports' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; $this->form_hidden( $id ); echo '<div class="gi-location-edit-grid">';
        foreach ( array( 'location_address_1' => __( 'Address', 'great-imports' ), 'location_address_2' => __( 'Address 2', 'great-imports' ), 'location_city' => __( 'City', 'great-imports' ), 'location_state' => __( 'State', 'great-imports' ), 'location_postal_code' => __( 'ZIP', 'great-imports' ), 'location_country' => __( 'Country', 'great-imports' ) ) as $key => $label ) { $this->plain_input( $id, $key, $label, GI_Candidate_Review::value( $id, $key ) ); }
        echo '<label class="gi-field-wide"><span class="gi-label">' . esc_html__( 'Matched Events Manager location', 'great-imports' ) . '</span>'; $this->em_location_select( $id, $suggestions ); echo '</label></div>'; submit_button( __( 'Save address / match', 'great-imports' ), 'secondary small', 'submit', false ); echo '</form></details></div>';
    }

    private function description_panel( $id, array $preview ) {
        $review = get_post_meta( $id, '_gi_review_description_html', true ); $html = '' !== trim( (string) $review ) ? (string) $review : ( isset( $preview['description_html'] ) ? (string) $preview['description_html'] : '' );
        echo '<section class="gi-slim-panel"><h3>' . esc_html__( 'Description', 'great-imports' ) . '</h3><div class="gi-field-row gi-field-row-description"><div class="gi-field-main"><span class="gi-field-label">' . esc_html__( 'Public description', 'great-imports' ) . '</span><div class="gi-description-preview">' . wp_kses_post( $html ) . '</div></div><details class="gi-field-edit"><summary>' . esc_html__( 'Edit', 'great-imports' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; $this->form_hidden( $id ); echo '<label class="gi-label">' . esc_html__( 'Description text', 'great-imports' ) . '</label><textarea name="gi_review[description_html]" rows="10" class="large-text gi-plain-description-editor">' . esc_textarea( $this->html_to_plaintext( $html ) ) . '</textarea>'; submit_button( __( 'Save description', 'great-imports' ), 'secondary small', 'submit', false ); echo '</form></details></div></section>';
    }

    private function image_panel( $id ) {
        $url = GI_Candidate_Review::value( $id, 'image_url' ); echo '<section class="gi-slim-panel"><h3>' . esc_html__( 'Image', 'great-imports' ) . '</h3><div class="gi-field-row"><div class="gi-field-main"><span class="gi-field-label">' . esc_html__( 'Image URL', 'great-imports' ) . '</span>' . ( $url ? '<div class="gi-image-preview"><img src="' . esc_url( $url ) . '" alt=""></div><span class="gi-field-value gi-url-value">' . esc_html( $url ) . '</span>' : '<span class="gi-field-value gi-muted">' . esc_html__( 'No image found', 'great-imports' ) . '</span>' ) . '</div><details class="gi-field-edit"><summary>' . esc_html__( 'Edit', 'great-imports' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; $this->form_hidden( $id ); echo '<label class="gi-label">' . esc_html__( 'Image URL', 'great-imports' ) . '</label><input type="url" class="regular-text" name="gi_review[image_url]" value="' . esc_attr( $url ) . '">'; submit_button( __( 'Save image', 'great-imports' ), 'secondary small', 'submit', false ); echo '</form></details></div></section>';
    }

    private function ticket_panel( $id, array $preview ) {
        $t = isset( $preview['ticketing'] ) && is_array( $preview['ticketing'] ) ? $preview['ticketing'] : array(); $classes = isset( $t['ticket_classes'] ) && is_array( $t['ticket_classes'] ) ? $t['ticket_classes'] : array(); $url = GI_Candidate_Review::source_value( $id, 'ticket_url' ); $price = trim( GI_Candidate_Review::source_value( $id, 'price' ) . ' ' . GI_Candidate_Review::source_value( $id, 'price_currency' ) );
        echo '<section class="gi-slim-panel gi-ticket-panel"><h3>' . esc_html__( 'Tickets', 'great-imports' ) . '</h3>'; $this->readonly_row( __( 'Ticket URL', 'great-imports' ), $url ); $this->readonly_row( __( 'Price', 'great-imports' ), $price ); if ( $classes ) { $this->readonly_row( __( 'Ticket class', 'great-imports' ), $this->ticket_class_label( $classes ) ); } echo '<p class="description gi-scope-note">' . esc_html__( 'Ticket URL, price, currency, and ticket classes are source facts only. Great Imports is not editing ticket data or creating Events Manager tickets/bookings.', 'great-imports' ) . '</p></section>';
    }

    private function readonly_row( $label, $value ) { echo '<div class="gi-field-row gi-readonly-row"><div class="gi-field-main"><span class="gi-field-label">' . esc_html( $label ) . '</span><span class="gi-field-value gi-url-value">' . esc_html( $value ) . '</span></div><span class="gi-readonly-badge">' . esc_html__( 'Read-only', 'great-imports' ) . '</span></div>'; }
    private function plain_input( $id, $key, $label, $value ) { echo '<label><span class="gi-label">' . esc_html( $label ) . '</span><input type="text" class="regular-text" name="gi_review[' . esc_attr( sanitize_key( $key ) ) . ']" value="' . esc_attr( (string) $value ) . '"></label>'; }
    private function form_hidden( $id ) { wp_nonce_field( 'gi_save_candidate_review_' . absint( $id ) ); echo '<input type="hidden" name="action" value="gi_save_candidate_review"><input type="hidden" name="gi_candidate_id" value="' . esc_attr( absint( $id ) ) . '">'; }
    private function em_location_select( $id, array $suggestions ) { $selected = absint( GI_Candidate_Review::review_value( $id, 'em_location_id' ) ); echo '<select name="gi_review[em_location_id]" class="gi-em-location-select"><option value="0">' . esc_html__( 'No existing EM location selected', 'great-imports' ) . '</option>'; foreach ( $suggestions as $s ) { $v = isset( $s['id'] ) ? absint( $s['id'] ) : 0; echo '<option value="' . esc_attr( $v ) . '" ' . selected( $selected, $v, false ) . '>' . esc_html( $this->format_em_location_suggestion( $s ) ) . '</option>'; } echo '</select>'; }

    private function advanced_details( $id, $candidate, array $preview, $source_url ) {
        echo '<details class="gi-advanced-details"><summary>' . esc_html__( 'Advanced source/debug details', 'great-imports' ) . '</summary><div class="gi-advanced-grid"><section class="gi-preview-section"><h3>' . esc_html__( 'Source values preserved', 'great-imports' ) . '</h3>'; $this->kv( array( __( 'Source title', 'great-imports' ) => get_the_title( $candidate ), __( 'Source start', 'great-imports' ) => GI_Candidate_Review::source_value( $id, 'start_date' ), __( 'Source end', 'great-imports' ) => GI_Candidate_Review::source_value( $id, 'end_date' ), __( 'Source location', 'great-imports' ) => GI_Candidate_Review::source_value( $id, 'location_name' ), __( 'Source address', 'great-imports' ) => GI_Candidate_Review::source_value( $id, 'location_address_1' ), __( 'Source ticket URL', 'great-imports' ) => GI_Candidate_Review::source_value( $id, 'ticket_url' ), __( 'Source ticket price', 'great-imports' ) => GI_Candidate_Review::source_value( $id, 'price' ) ) ); echo '</section><section class="gi-preview-section"><h3>' . esc_html__( 'Internal-only source tracking', 'great-imports' ) . '</h3>'; $this->kv( isset( $preview['internal_tracking'] ) && is_array( $preview['internal_tracking'] ) ? $preview['internal_tracking'] : array() ); if ( $source_url ) { echo '<p><a href="' . esc_url( $source_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open source page', 'great-imports' ) . '</a></p>'; } echo '</section></div></details>';
    }

    private function manual_data_removal() { echo '<div class="gi-card gi-danger-card"><details class="gi-danger-details"><summary>' . esc_html__( 'Danger Zone: Manual Data Removal', 'great-imports' ) . '</summary><div class="gi-danger-body"><p><strong>' . esc_html__( 'Use this only when uninstall cleanup did not remove Great Imports data.', 'great-imports' ) . '</strong></p><p>' . esc_html__( 'This removes only Great Imports-owned data. It does not delete Events Manager events, locations, tickets, media, categories, tags, or venue data.', 'great-imports' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'gi_manual_data_removal' ); echo '<input type="hidden" name="action" value="gi_manual_data_removal"><label class="gi-label"><input type="checkbox" name="gi_manual_cleanup_confirm" value="1" required> ' . esc_html__( 'I understand this permanently removes Great Imports review/evidence data and the saved Eventbrite token.', 'great-imports' ) . '</label><label class="gi-label">' . esc_html__( 'Type REMOVE to confirm', 'great-imports' ) . '</label><input type="text" class="regular-text" name="gi_manual_cleanup_phrase" required> '; submit_button( __( 'Remove Great Imports Data', 'great-imports' ), 'delete', 'submit', false ); echo '</form></div></details></div>'; }

    private function address_label( $id ) { return trim( implode( ', ', array_filter( array( GI_Candidate_Review::value( $id, 'location_address_1' ), GI_Candidate_Review::value( $id, 'location_city' ), trim( GI_Candidate_Review::value( $id, 'location_state' ) . ' ' . GI_Candidate_Review::value( $id, 'location_postal_code' ) ), GI_Candidate_Review::value( $id, 'location_country' ) ) ) ) ); }
    private function datetime_label( $v ) { $v = trim( (string) $v ); $ts = $v ? strtotime( $v ) : false; return $ts ? date_i18n( 'l, F j, Y g:i A', $ts ) : ( $v ? $v : __( 'Not set', 'great-imports' ) ); }
    private function date_part( $v ) { return preg_match( '/^(\d{4}-\d{2}-\d{2})/', (string) $v, $m ) ? $m[1] : ( strtotime( (string) $v ) ? date( 'Y-m-d', strtotime( (string) $v ) ) : '' ); }
    private function time_part( $v ) { return ( preg_match( '/T(\d{2}:\d{2})/', (string) $v, $m ) || preg_match( '/\s(\d{2}:\d{2})/', (string) $v, $m ) ) ? $m[1] : ( strtotime( (string) $v ) ? date( 'H:i', strtotime( (string) $v ) ) : '' ); }
    private function html_to_plaintext( $html ) { $html = preg_replace( '/<\s*br\s*\/?>/i', "\n", (string) $html ); $html = preg_replace( '/<\/(p|div|h[1-6]|li|details)>/i', "\n\n", $html ); return trim( wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES, get_bloginfo( 'charset' ) ) ) ); }
    private function ticket_class_label( array $classes ) { $out = array(); foreach ( $classes as $c ) { if ( is_array( $c ) ) { $out[] = trim( ( $c['name'] ?? '' ) . ( ! empty( $c['cost'] ) ? ' — ' . $c['cost'] : '' ) ); } } return implode( ', ', array_filter( $out ) ); }
    private function selected_location_label( $selected, array $suggestions ) { if ( ! $selected ) { return __( 'No existing EM location selected', 'great-imports' ); } foreach ( $suggestions as $s ) { if ( isset( $s['id'] ) && absint( $s['id'] ) === absint( $selected ) ) { return $this->format_em_location_suggestion( $s ); } } return sprintf( __( 'Location ID %d', 'great-imports' ), absint( $selected ) ); }
    private function format_em_location_suggestion( array $s ) { return implode( ' — ', array_filter( array( sanitize_text_field( $s['name'] ?? '' ), sanitize_text_field( $s['address'] ?? '' ), trim( sanitize_text_field( $s['city'] ?? '' ) . ', ' . sanitize_text_field( $s['state'] ?? '' ) . ' ' . sanitize_text_field( $s['postcode'] ?? '' ) ) ) ) ); }
    private function kv( array $rows ) { if ( ! $rows ) { echo '<p>' . esc_html__( 'None found', 'great-imports' ) . '</p>'; return; } echo '<table class="widefat gi-kv"><tbody>'; foreach ( $rows as $k => $v ) { echo '<tr><th>' . esc_html( (string) $k ) . '</th><td>' . esc_html( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ) . '</td></tr>'; } echo '</tbody></table>'; }
    private function redirect_with_notice( $status, $message, $id = 0 ) { wp_safe_redirect( add_query_arg( array( 'page' => 'great-imports', 'gi_status' => sanitize_key( $status ), 'gi_message' => rawurlencode( (string) $message ), 'gi_candidate' => absint( $id ) ), admin_url( 'admin.php' ) ) ); exit; }
    private function render_notice() { if ( empty( $_GET['gi_message'] ) ) { return; } $status = isset( $_GET['gi_status'] ) && 'success' === $_GET['gi_status'] ? 'success' : 'error'; $message = sanitize_text_field( wp_unslash( $_GET['gi_message'] ) ); printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $status ), esc_html( $message ) ); }
    private function guard( $action ) { if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html( sprintf( __( 'You do not have permission to %s.', 'great-imports' ), $action ) ) ); } }
}
