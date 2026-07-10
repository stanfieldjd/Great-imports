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
                <p><?php esc_html_e( 'Download a sanitized JSON report showing plugin state, Events Manager detection, Eventbrite token status, candidates, evidence records, source-page display reports, import previews, and source coverage audits.', 'great-imports' ); ?></p>
                <p><?php esc_html_e( 'Secret values and cookies are not exported.', 'great-imports' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'gi_download_exploratory_report' ); ?>
                    <input type="hidden" name="action" value="gi_download_exploratory_report" />
                    <?php submit_button( __( 'Download Exploratory Report', 'great-imports' ), 'secondary', 'submit', false ); ?>
                </form>
            </div>

            <div class="gi-card gi-danger-card">
                <h2><?php esc_html_e( 'Manual Data Removal', 'great-imports' ); ?></h2>
                <p><strong><?php esc_html_e( 'Use this when uninstall cleanup did not remove Great Imports data.', 'great-imports' ); ?></strong></p>
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

            <div class="gi-card">
                <h2><?php esc_html_e( 'Recent Review Candidates', 'great-imports' ); ?></h2>
                <?php if ( empty( $recent_candidates ) ) : ?>
                    <p><?php esc_html_e( 'No candidates have been collected yet.', 'great-imports' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped gi-candidate-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Title', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Start', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Location', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Method', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Internal source', 'great-imports' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $recent_candidates as $candidate ) : ?>
                                <?php
                                $source_url = (string) get_post_meta( $candidate->ID, '_gi_source_url', true );
                                $preview    = $this->preview_builder->build_for_candidate( $candidate );
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( get_the_title( $candidate ) ); ?></strong></td>
                                    <td><?php echo esc_html( (string) get_post_meta( $candidate->ID, '_gi_candidate_status', true ) ); ?></td>
                                    <td><?php echo esc_html( (string) get_post_meta( $candidate->ID, '_gi_start_date', true ) ); ?></td>
                                    <td><?php echo esc_html( (string) get_post_meta( $candidate->ID, '_gi_location_name', true ) ); ?></td>
                                    <td><?php echo esc_html( (string) get_post_meta( $candidate->ID, '_gi_fetch_method', true ) ); ?></td>
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
        </div>
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
            __( 'Overall event window', 'great-imports' )              => isset( $time['overall_window'] ) ? $time['overall_window'] : '',
            __( 'Set times / performance schedule', 'great-imports' )  => ! empty( $time['set_times'] ) ? __( 'Source-backed set times found', 'great-imports' ) : __( 'None found', 'great-imports' ),
            __( 'Events Manager timeslots', 'great-imports' )          => ! empty( $time['em_timeslots'] ) ? __( 'Review required before use', 'great-imports' ) : __( 'Not used for this candidate', 'great-imports' ),
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
