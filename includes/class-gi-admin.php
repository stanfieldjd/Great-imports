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
    /** @var GI_Eventbrite_Importer */
    private $importer;

    /** @var GI_Eventbrite_API_Client */
    private $api_client;

    /** @var GI_Exploratory_Report */
    private $exploratory_report;

    /** @var GI_Import_Preview_Builder */
    private $preview_builder;

    public function __construct( GI_Eventbrite_Importer $importer, GI_Eventbrite_API_Client $api_client, GI_Exploratory_Report $exploratory_report, GI_Import_Preview_Builder $preview_builder ) {
        $this->importer           = $importer;
        $this->api_client         = $api_client;
        $this->exploratory_report = $exploratory_report;
        $this->preview_builder    = $preview_builder;
    }

    /**
     * Register admin hooks.
     */
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

    public function register_admin_bar( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $wp_admin_bar->add_node(
            array(
                'id'    => 'great-imports',
                'title' => __( 'Great Imports', 'great-imports' ),
                'href'  => admin_url( 'admin.php?page=great-imports' ),
                'meta'  => array(
                    'title' => __( 'Great Imports', 'great-imports' ),
                ),
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to change Great Imports settings.', 'great-imports' ) );
        }

        check_admin_referer( 'gi_eventbrite_save_settings' );

        $clear = ! empty( $_POST['gi_eventbrite_clear_token'] );
        $token = isset( $_POST['gi_eventbrite_private_token'] ) ? wp_unslash( $_POST['gi_eventbrite_private_token'] ) : '';

        if ( $clear ) {
            $this->api_client->clear_private_token();
            $message = __( 'Eventbrite private token cleared.', 'great-imports' );
            $status  = 'success';
        } elseif ( '' !== trim( (string) $token ) ) {
            $this->api_client->save_private_token( $token );
            $message = __( 'Eventbrite private token saved.', 'great-imports' );
            $status  = 'success';
        } else {
            $message = __( 'No Eventbrite token changes were made.', 'great-imports' );
            $status  = 'error';
        }

        $this->redirect_with_notice( $status, $message, 0 );
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

        $this->redirect_with_notice(
            $result['success'] ? 'success' : 'error',
            $result['message'],
            absint( $result['post_id'] )
        );
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

        $this->redirect_with_notice(
            $result['success'] ? 'success' : 'error',
            $result['message'],
            $candidate_id
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $store             = new GI_Candidate_Store();
        $recent_candidates = $store->get_recent_candidates( 20 );
        ?>
        <div class="wrap gi-wrap">
            <h1><?php esc_html_e( 'Great Imports', 'great-imports' ); ?></h1>

            <?php $this->render_notice(); ?>

            <div class="gi-card">
                <h2><?php esc_html_e( 'Eventbrite API Settings', 'great-imports' ); ?></h2>
                <p><?php esc_html_e( 'Optional but recommended. Save the Eventbrite private token here so Great Imports can fetch verified API data from your WordPress server. The token is never displayed after saving.', 'great-imports' ); ?></p>
                <p><strong><?php esc_html_e( 'Status:', 'great-imports' ); ?></strong> <?php echo esc_html( $this->api_client->has_private_token() ? __( 'Private token configured', 'great-imports' ) : __( 'Private token not configured', 'great-imports' ) ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'gi_eventbrite_save_settings' ); ?>
                    <input type="hidden" name="action" value="gi_eventbrite_save_settings" />
                    <label for="gi_eventbrite_private_token" class="gi-label"><?php esc_html_e( 'Eventbrite private token', 'great-imports' ); ?></label>
                    <input type="password" class="regular-text gi-token-input" id="gi_eventbrite_private_token" name="gi_eventbrite_private_token" value="" autocomplete="off" placeholder="<?php echo esc_attr( $this->api_client->has_private_token() ? __( 'Configured — enter a new token to replace it', 'great-imports' ) : __( 'Paste private token', 'great-imports' ) ); ?>" />
                    <label class="gi-inline-check"><input type="checkbox" name="gi_eventbrite_clear_token" value="1" /> <?php esc_html_e( 'Clear saved token', 'great-imports' ); ?></label>
                    <?php submit_button( __( 'Save Eventbrite settings', 'great-imports' ), 'secondary', 'submit', false ); ?>
                </form>
            </div>

            <div class="gi-card">
                <h2><?php esc_html_e( 'One-time Eventbrite Import', 'great-imports' ); ?></h2>
                <p><?php esc_html_e( 'Paste one Eventbrite event URL. Great Imports will extract the event ID, try the Eventbrite API when a private token is configured, and fall back to public JSON-LD only if needed.', 'great-imports' ); ?></p>
                <p><strong><?php esc_html_e( 'Current stage:', 'great-imports' ); ?></strong> <?php esc_html_e( 'Evidence collection and import preview only. This screen does not create Events Manager events yet.', 'great-imports' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'gi_eventbrite_import_once' ); ?>
                    <input type="hidden" name="action" value="gi_eventbrite_import_once" />
                    <label for="gi_eventbrite_url" class="gi-label"><?php esc_html_e( 'Eventbrite URL', 'great-imports' ); ?></label>
                    <input type="url" class="regular-text gi-url-input" id="gi_eventbrite_url" name="gi_eventbrite_url" placeholder="https://www.eventbrite.com/e/example-event-tickets-123456789" required />
                    <?php submit_button( __( 'Collect evidence / refresh preview', 'great-imports' ), 'primary', 'submit', false ); ?>
                </form>
            </div>

            <div class="gi-card">
                <h2><?php esc_html_e( 'Exploratory Report', 'great-imports' ); ?></h2>
                <p><?php esc_html_e( 'Download a sanitized JSON report showing plugin state, candidates, evidence records, source-page display reports, import previews, and source coverage audits.', 'great-imports' ); ?></p>
                <p><?php esc_html_e( 'Secret values and cookies are not exported.', 'great-imports' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'gi_download_exploratory_report' ); ?>
                    <input type="hidden" name="action" value="gi_download_exploratory_report" />
                    <?php submit_button( __( 'Download Exploratory Report', 'great-imports' ), 'secondary', 'submit', false ); ?>
                </form>
            </div>

            <div class="gi-card">
                <h2><?php esc_html_e( 'Recent Review Candidates', 'great-imports' ); ?></h2>
                <?php if ( empty( $recent_candidates ) ) : ?>
                    <p><?php esc_html_e( 'No candidates have been collected yet.', 'great-imports' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped gi-candidate-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Title', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Review status', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Start', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Location', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Method', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Internal source', 'great-imports' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $recent_candidates as $candidate ) : ?>
                                <?php
                                $candidate_id   = (int) $candidate->ID;
                                $source_url     = (string) get_post_meta( $candidate_id, '_gi_source_url', true );
                                $preview        = $this->preview_builder->build_for_candidate( $candidate );
                                $display_title  = GI_Candidate_Review::value( $candidate_id, 'title', '', get_the_title( $candidate ) );
                                $review_status  = GI_Candidate_Review::value( $candidate_id, 'review_status', 'candidate_status', 'needs_review' );
                                $display_start  = GI_Candidate_Review::value( $candidate_id, 'start_date' );
                                $display_location = GI_Candidate_Review::value( $candidate_id, 'location_name' );
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $display_title ); ?></strong></td>
                                    <td><?php echo esc_html( $this->review_status_label( $review_status ) ); ?></td>
                                    <td><?php echo esc_html( $display_start ); ?></td>
                                    <td><?php echo esc_html( $display_location ); ?></td>
                                    <td><?php echo esc_html( (string) get_post_meta( $candidate_id, '_gi_fetch_method', true ) ); ?></td>
                                    <td>
                                        <?php if ( $source_url ) : ?>
                                            <a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open source', 'great-imports' ); ?></a>
                                        <?php else : ?>
                                            <?php esc_html_e( 'Source stored internally', 'great-imports' ); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr class="gi-preview-row">
                                    <td colspan="6">
                                        <details class="gi-preview gi-review-editor">
                                            <summary><?php esc_html_e( 'Review / edit candidate', 'great-imports' ); ?></summary>
                                            <?php $this->render_candidate_review_form( $candidate ); ?>
                                        </details>
                                        <details class="gi-preview">
                                            <summary><?php esc_html_e( 'Import preview / dry run', 'great-imports' ); ?></summary>
                                            <?php $this->render_import_preview( $preview ); ?>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php $this->render_manual_data_removal(); ?>
        </div>
        <?php
    }

    private function render_candidate_review_form( $candidate ) {
        $candidate_id = (int) $candidate->ID;
        $suggestions  = GI_Candidate_Review::location_suggestions( $candidate_id, 8 );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gi-review-form">
            <?php wp_nonce_field( 'gi_save_candidate_review_' . $candidate_id ); ?>
            <input type="hidden" name="action" value="gi_save_candidate_review" />
            <input type="hidden" name="gi_candidate_id" value="<?php echo esc_attr( $candidate_id ); ?>" />

            <div class="gi-review-grid">
                <section class="gi-preview-section">
                    <h3><?php esc_html_e( 'Editable event fields', 'great-imports' ); ?></h3>
                    <?php
                    $this->render_text_input( $candidate_id, 'title', __( 'Title', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'title', '', get_the_title( $candidate ) ) );
                    $this->render_text_input( $candidate_id, 'start_date', __( 'Start date/time', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'start_date' ) );
                    $this->render_text_input( $candidate_id, 'end_date', __( 'End date/time', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'end_date' ) );
                    $this->render_text_input( $candidate_id, 'timezone', __( 'Timezone', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'timezone' ) );
                    ?>
                </section>

                <section class="gi-preview-section">
                    <h3><?php esc_html_e( 'Editable location/address fields', 'great-imports' ); ?></h3>
                    <?php
                    $this->render_text_input( $candidate_id, 'location_name', __( 'Location name', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'location_name' ) );
                    $this->render_text_input( $candidate_id, 'location_address_1', __( 'Address 1', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'location_address_1' ) );
                    $this->render_text_input( $candidate_id, 'location_address_2', __( 'Address 2', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'location_address_2' ) );
                    $this->render_text_input( $candidate_id, 'location_city', __( 'City', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'location_city' ) );
                    $this->render_text_input( $candidate_id, 'location_state', __( 'State', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'location_state' ) );
                    $this->render_text_input( $candidate_id, 'location_postal_code', __( 'ZIP', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'location_postal_code' ) );
                    $this->render_text_input( $candidate_id, 'location_country', __( 'Country', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'location_country' ) );
                    $this->render_text_input( $candidate_id, 'stage_room', __( 'Stage / room', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'stage_room' ) );
                    ?>
                </section>

                <section class="gi-preview-section">
                    <h3><?php esc_html_e( 'Location decision', 'great-imports' ); ?></h3>
                    <?php
                    $this->render_select_field( $candidate_id, 'location_decision', __( 'Location decision', 'great-imports' ), GI_Candidate_Review::location_decision_options(), GI_Candidate_Review::review_value( $candidate_id, 'location_decision' ) );
                    $this->render_select_field( $candidate_id, 'address_verification', __( 'Address verification', 'great-imports' ), GI_Candidate_Review::address_verification_options(), GI_Candidate_Review::review_value( $candidate_id, 'address_verification' ) );
                    $this->render_em_location_select( $candidate_id, $suggestions );
                    ?>
                    <p class="description"><?php esc_html_e( 'Selecting an Events Manager location only records the reviewer decision. Great Imports still does not create or update EM events/locations from this screen.', 'great-imports' ); ?></p>
                </section>

                <section class="gi-preview-section">
                    <h3><?php esc_html_e( 'Ticket / price review', 'great-imports' ); ?></h3>
                    <?php
                    $this->render_text_input( $candidate_id, 'ticket_url', __( 'Ticket URL', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'ticket_url' ) );
                    $this->render_text_input( $candidate_id, 'price', __( 'Price', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'price' ) );
                    $this->render_text_input( $candidate_id, 'price_currency', __( 'Currency', 'great-imports' ), GI_Candidate_Review::value( $candidate_id, 'price_currency' ) );
                    ?>
                </section>

                <section class="gi-preview-section">
                    <h3><?php esc_html_e( 'Review status and notes', 'great-imports' ); ?></h3>
                    <?php
                    $this->render_select_field( $candidate_id, 'review_status', __( 'Review status', 'great-imports' ), GI_Candidate_Review::review_status_options(), GI_Candidate_Review::value( $candidate_id, 'review_status', 'candidate_status', 'needs_review' ) );
                    $this->render_textarea( $candidate_id, 'reviewer_notes', __( 'Reviewer notes', 'great-imports' ), GI_Candidate_Review::review_value( $candidate_id, 'reviewer_notes' ) );
                    ?>
                </section>

                <section class="gi-preview-section">
                    <h3><?php esc_html_e( 'Source values preserved', 'great-imports' ); ?></h3>
                    <?php $this->render_source_value_summary( $candidate_id, $candidate ); ?>
                    <p class="description"><?php esc_html_e( 'Review edits are saved as overrides. Raw evidence and original source fields stay available for comparison.', 'great-imports' ); ?></p>
                </section>
            </div>

            <p class="submit gi-review-submit">
                <?php submit_button( __( 'Save candidate review', 'great-imports' ), 'secondary', 'submit', false ); ?>
            </p>
        </form>
        <?php
    }

    private function render_import_preview( array $preview ) {
        ?>
        <div class="gi-preview-grid">
            <section class="gi-preview-section">
                <h3><?php esc_html_e( 'Events Manager public fields', 'great-imports' ); ?></h3>
                <?php $this->render_event_fields( $preview ); ?>
            </section>

            <section class="gi-preview-section">
                <h3><?php esc_html_e( 'Date, time, and timeslot handling', 'great-imports' ); ?></h3>
                <?php $this->render_time_handling( isset( $preview['time_handling'] ) && is_array( $preview['time_handling'] ) ? $preview['time_handling'] : array() ); ?>
            </section>

            <section class="gi-preview-section">
                <h3><?php esc_html_e( 'Events Manager location/address fields', 'great-imports' ); ?></h3>
                <?php $this->render_location_fields( isset( $preview['location_fields'] ) && is_array( $preview['location_fields'] ) ? $preview['location_fields'] : array() ); ?>
            </section>

            <section class="gi-preview-section">
                <h3><?php esc_html_e( 'Reviewer decisions', 'great-imports' ); ?></h3>
                <?php $this->render_reviewer_decisions( isset( $preview['reviewer_decisions'] ) && is_array( $preview['reviewer_decisions'] ) ? $preview['reviewer_decisions'] : array() ); ?>
            </section>

            <section class="gi-preview-section">
                <h3><?php esc_html_e( 'Image handling', 'great-imports' ); ?></h3>
                <?php $this->render_images( isset( $preview['images'] ) && is_array( $preview['images'] ) ? $preview['images'] : array() ); ?>
            </section>

            <section class="gi-preview-section gi-preview-description">
                <h3><?php esc_html_e( 'Assembled public description', 'great-imports' ); ?></h3>
                <div class="gi-description-preview">
                    <?php echo wp_kses( isset( $preview['description_html'] ) ? (string) $preview['description_html'] : '', $this->preview_allowed_html() ); ?>
                </div>
            </section>

            <section class="gi-preview-section">
                <h3><?php esc_html_e( 'Internal-only source tracking', 'great-imports' ); ?></h3>
                <?php $this->render_key_value_list( isset( $preview['internal_tracking'] ) && is_array( $preview['internal_tracking'] ) ? $preview['internal_tracking'] : array() ); ?>
            </section>

            <section class="gi-preview-section">
                <h3><?php esc_html_e( 'Excluded from public import', 'great-imports' ); ?></h3>
                <?php $this->render_simple_list( isset( $preview['excluded_public_data'] ) && is_array( $preview['excluded_public_data'] ) ? $preview['excluded_public_data'] : array() ); ?>
            </section>

            <section class="gi-preview-section">
                <h3><?php esc_html_e( 'Stage / room duplication rule', 'great-imports' ); ?></h3>
                <p><?php echo esc_html( isset( $preview['stage_handling']['note'] ) ? (string) $preview['stage_handling']['note'] : '' ); ?></p>
            </section>
        </div>
        <?php
    }

    private function render_manual_data_removal() {
        ?>
        <div class="gi-card gi-danger-card">
            <details class="gi-danger-details">
                <summary><?php esc_html_e( 'Danger Zone: Manual Data Removal', 'great-imports' ); ?></summary>
                <div class="gi-danger-body">
                    <h2><?php esc_html_e( 'Manual Data Removal', 'great-imports' ); ?></h2>
                    <p><strong><?php esc_html_e( 'Use this only when uninstall cleanup did not remove Great Imports data.', 'great-imports' ); ?></strong></p>
                    <p><?php esc_html_e( 'This removes only Great Imports-owned data: private token/options, review candidates, evidence records, Great Imports metadata, and Great Imports transients.', 'great-imports' ); ?></p>
                    <p><?php esc_html_e( 'It does not delete Events Manager events, locations, tickets, media, categories, tags, or venue data.', 'great-imports' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'gi_manual_data_removal' ); ?>
                        <input type="hidden" name="action" value="gi_manual_data_removal" />
                        <label class="gi-label"><input type="checkbox" name="gi_manual_cleanup_confirm" value="1" required /> <?php esc_html_e( 'I understand this permanently removes Great Imports review/evidence data and the saved Eventbrite token.', 'great-imports' ); ?></label>
                        <label for="gi_manual_cleanup_phrase" class="gi-label"><?php esc_html_e( 'Type REMOVE to confirm', 'great-imports' ); ?></label>
                        <input type="text" class="regular-text" id="gi_manual_cleanup_phrase" name="gi_manual_cleanup_phrase" value="" autocomplete="off" required />
                        <?php submit_button( __( 'Remove Great Imports Data', 'great-imports' ), 'delete', 'submit', false ); ?>
                    </form>
                </div>
            </details>
        </div>
        <?php
    }

    private function render_text_input( $candidate_id, $key, $label, $value ) {
        $id = 'gi_review_' . absint( $candidate_id ) . '_' . sanitize_key( $key );
        ?>
        <label for="<?php echo esc_attr( $id ); ?>" class="gi-label"><?php echo esc_html( $label ); ?></label>
        <input type="text" class="regular-text gi-review-input" id="<?php echo esc_attr( $id ); ?>" name="gi_review[<?php echo esc_attr( sanitize_key( $key ) ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" />
        <?php
    }

    private function render_textarea( $candidate_id, $key, $label, $value ) {
        $id = 'gi_review_' . absint( $candidate_id ) . '_' . sanitize_key( $key );
        ?>
        <label for="<?php echo esc_attr( $id ); ?>" class="gi-label"><?php echo esc_html( $label ); ?></label>
        <textarea class="large-text gi-review-textarea" rows="4" id="<?php echo esc_attr( $id ); ?>" name="gi_review[<?php echo esc_attr( sanitize_key( $key ) ); ?>]"><?php echo esc_textarea( (string) $value ); ?></textarea>
        <?php
    }

    private function render_select_field( $candidate_id, $key, $label, array $options, $selected ) {
        $id       = 'gi_review_' . absint( $candidate_id ) . '_' . sanitize_key( $key );
        $selected = sanitize_key( (string) $selected );
        ?>
        <label for="<?php echo esc_attr( $id ); ?>" class="gi-label"><?php echo esc_html( $label ); ?></label>
        <select id="<?php echo esc_attr( $id ); ?>" name="gi_review[<?php echo esc_attr( sanitize_key( $key ) ); ?>]" class="gi-review-select">
            <option value=""><?php esc_html_e( 'No reviewer decision yet', 'great-imports' ); ?></option>
            <?php foreach ( $options as $value => $text ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $text ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private function render_em_location_select( $candidate_id, array $suggestions ) {
        $selected = absint( GI_Candidate_Review::review_value( $candidate_id, 'em_location_id' ) );
        $id       = 'gi_review_' . absint( $candidate_id ) . '_em_location_id';
        ?>
        <label for="<?php echo esc_attr( $id ); ?>" class="gi-label"><?php esc_html_e( 'Suggested Events Manager location', 'great-imports' ); ?></label>
        <select id="<?php echo esc_attr( $id ); ?>" name="gi_review[em_location_id]" class="gi-review-select gi-em-location-select">
            <option value="0"><?php esc_html_e( 'No existing EM location selected', 'great-imports' ); ?></option>
            <?php if ( empty( $suggestions ) ) : ?>
                <option value="0" disabled><?php esc_html_e( 'No Events Manager suggestions found yet', 'great-imports' ); ?></option>
            <?php else : ?>
                <?php foreach ( $suggestions as $suggestion ) : ?>
                    <?php $value = isset( $suggestion['id'] ) ? absint( $suggestion['id'] ) : 0; ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $this->format_em_location_suggestion( $suggestion ) ); ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <?php
    }

    private function format_em_location_suggestion( array $suggestion ) {
        $parts = array_filter(
            array(
                isset( $suggestion['name'] ) ? $suggestion['name'] : '',
                isset( $suggestion['address'] ) ? $suggestion['address'] : '',
                trim( ( isset( $suggestion['city'] ) ? $suggestion['city'] : '' ) . ', ' . ( isset( $suggestion['state'] ) ? $suggestion['state'] : '' ) . ' ' . ( isset( $suggestion['postcode'] ) ? $suggestion['postcode'] : '' ) ),
            )
        );

        $label = implode( ' — ', array_map( 'sanitize_text_field', $parts ) );
        if ( ! empty( $suggestion['reason'] ) ) {
            $label .= ' (' . sanitize_text_field( (string) $suggestion['reason'] ) . ')';
        }

        return $label;
    }

    private function render_source_value_summary( $candidate_id, $candidate ) {
        $rows = array(
            __( 'Source title', 'great-imports' )    => get_the_title( $candidate ),
            __( 'Source start', 'great-imports' )    => GI_Candidate_Review::source_value( $candidate_id, 'start_date' ),
            __( 'Source end', 'great-imports' )      => GI_Candidate_Review::source_value( $candidate_id, 'end_date' ),
            __( 'Source location', 'great-imports' ) => GI_Candidate_Review::source_value( $candidate_id, 'location_name' ),
            __( 'Source address', 'great-imports' )  => GI_Candidate_Review::source_value( $candidate_id, 'location_address_1' ),
            __( 'Source city/state/ZIP', 'great-imports' ) => trim( GI_Candidate_Review::source_value( $candidate_id, 'location_city' ) . ', ' . GI_Candidate_Review::source_value( $candidate_id, 'location_state' ) . ' ' . GI_Candidate_Review::source_value( $candidate_id, 'location_postal_code' ) ),
        );
        $this->render_key_value_list( $rows );
    }

    private function render_event_fields( array $preview ) {
        $fields = isset( $preview['public_event_fields'] ) && is_array( $preview['public_event_fields'] ) ? $preview['public_event_fields'] : array();
        $rows   = array(
            __( 'Title', 'great-imports' )    => isset( $fields['title'] ) ? $fields['title'] : '',
            __( 'Start', 'great-imports' )    => isset( $fields['start']['label'] ) ? $fields['start']['label'] : '',
            __( 'End', 'great-imports' )      => isset( $fields['end']['label'] ) ? $fields['end']['label'] : '',
            __( 'Timezone', 'great-imports' ) => isset( $fields['timezone'] ) ? $fields['timezone'] : '',
            __( 'Status', 'great-imports' )   => isset( $fields['status'] ) ? $fields['status'] : '',
        );

        $this->render_key_value_list( $rows );
    }

    private function render_time_handling( array $time ) {
        $rows = array(
            __( 'Overall event window', 'great-imports' )             => isset( $time['overall_window'] ) ? $time['overall_window'] : '',
            __( 'Set times / performance schedule', 'great-imports' ) => ! empty( $time['set_times'] ) ? __( 'Source-backed set times found', 'great-imports' ) : __( 'None found', 'great-imports' ),
            __( 'Events Manager timeslots', 'great-imports' )         => ! empty( $time['em_timeslots'] ) ? __( 'Review required before use', 'great-imports' ) : __( 'Not used for this candidate', 'great-imports' ),
        );
        $this->render_key_value_list( $rows );

        if ( ! empty( $time['note'] ) ) {
            echo '<p class="description">' . esc_html( (string) $time['note'] ) . '</p>';
        }
    }

    private function render_location_fields( array $fields ) {
        $rows = array(
            __( 'Location name', 'great-imports' ) => isset( $fields['location_name'] ) ? $fields['location_name'] : '',
            __( 'Address', 'great-imports' )       => isset( $fields['location_address'] ) ? $fields['location_address'] : '',
            __( 'Address 2', 'great-imports' )     => isset( $fields['location_address2'] ) ? $fields['location_address2'] : '',
            __( 'City', 'great-imports' )          => isset( $fields['location_town'] ) ? $fields['location_town'] : '',
            __( 'State', 'great-imports' )         => isset( $fields['location_state'] ) ? $fields['location_state'] : '',
            __( 'ZIP', 'great-imports' )           => isset( $fields['location_postcode'] ) ? $fields['location_postcode'] : '',
            __( 'Country', 'great-imports' )       => isset( $fields['location_country'] ) ? $fields['location_country'] : '',
            __( 'Stage / room', 'great-imports' )  => isset( $fields['stage_room'] ) && '' !== $fields['stage_room'] ? $fields['stage_room'] : __( 'None found', 'great-imports' ),
        );
        $this->render_key_value_list( $rows );

        if ( ! empty( $fields['handoff_note'] ) ) {
            echo '<p class="description">' . esc_html( (string) $fields['handoff_note'] ) . '</p>';
        }
    }

    private function render_reviewer_decisions( array $decisions ) {
        $rows = array(
            __( 'Review status', 'great-imports' )        => isset( $decisions['review_status_label'] ) ? $decisions['review_status_label'] : '',
            __( 'Location decision', 'great-imports' )    => isset( $decisions['location_decision_label'] ) ? $decisions['location_decision_label'] : '',
            __( 'Address verification', 'great-imports' ) => isset( $decisions['address_verification_label'] ) ? $decisions['address_verification_label'] : '',
            __( 'Selected EM location ID', 'great-imports' ) => isset( $decisions['em_location_id'] ) ? $decisions['em_location_id'] : '',
            __( 'Reviewer notes', 'great-imports' )       => isset( $decisions['reviewer_notes'] ) ? $decisions['reviewer_notes'] : '',
        );
        $this->render_key_value_list( $rows );

        if ( ! empty( $decisions['note'] ) ) {
            echo '<p class="description">' . esc_html( (string) $decisions['note'] ) . '</p>';
        }
    }

    private function render_images( array $images ) {
        $url = isset( $images['primary_image_url'] ) ? esc_url( (string) $images['primary_image_url'] ) : '';

        if ( '' !== $url ) {
            echo '<p><img class="gi-preview-image" src="' . esc_url( $url ) . '" alt="" /></p>';
            echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open image source', 'great-imports' ) . '</a></p>';
        } else {
            echo '<p>' . esc_html__( 'No primary event image found.', 'great-imports' ) . '</p>';
        }

        if ( ! empty( $images['planned_action'] ) ) {
            echo '<p class="description">' . esc_html( (string) $images['planned_action'] ) . '</p>';
        }
    }

    private function render_key_value_list( array $rows ) {
        echo '<table class="widefat gi-kv"><tbody>';
        foreach ( $rows as $label => $value ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                $value = '';
            }
            echo '<tr><th>' . esc_html( (string) $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function render_simple_list( array $items ) {
        if ( empty( $items ) ) {
            echo '<p>' . esc_html__( 'None.', 'great-imports' ) . '</p>';
            return;
        }

        echo '<ul class="gi-simple-list">';
        foreach ( $items as $item ) {
            if ( is_array( $item ) || is_object( $item ) ) {
                continue;
            }
            echo '<li>' . esc_html( (string) $item ) . '</li>';
        }
        echo '</ul>';
    }

    private function review_status_label( $status ) {
        $options = GI_Candidate_Review::review_status_options();
        $status  = sanitize_key( (string) $status );

        return isset( $options[ $status ] ) ? $options[ $status ] : $status;
    }

    private function preview_allowed_html() {
        $allowed = wp_kses_allowed_html( 'post' );
        $allowed['details'] = array(
            'open'  => true,
            'class' => true,
        );
        $allowed['summary'] = array(
            'class' => true,
        );

        return $allowed;
    }

    private function redirect_with_notice( $status, $message, $post_id = 0 ) {
        $redirect = add_query_arg(
            array(
                'page'       => 'great-imports',
                'gi_status'  => sanitize_key( $status ),
                'gi_message' => sanitize_text_field( $message ),
                'gi_post_id' => absint( $post_id ),
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    private function render_notice() {
        if ( empty( $_GET['gi_status'] ) || empty( $_GET['gi_message'] ) ) {
            return;
        }

        $status  = sanitize_key( wp_unslash( $_GET['gi_status'] ) );
        $message = sanitize_text_field( wp_unslash( $_GET['gi_message'] ) );
        $class   = 'success' === $status ? 'notice-success' : 'notice-error';
        ?>
        <div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
            <p><?php echo esc_html( $message ); ?></p>
        </div>
        <?php
    }
}
