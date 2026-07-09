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

    public function __construct( GI_Eventbrite_Importer $importer, GI_Eventbrite_API_Client $api_client, GI_Exploratory_Report $exploratory_report ) {
        $this->importer           = $importer;
        $this->api_client         = $api_client;
        $this->exploratory_report = $exploratory_report;
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
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Add the top-level Great Imports menu.
     */
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

    /**
     * Add Great Imports to the WordPress admin toolbar.
     *
     * @param WP_Admin_Bar $wp_admin_bar Admin toolbar instance.
     */
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

    /**
     * Enqueue admin CSS only on this page.
     *
     * @param string $hook Current admin hook.
     */
    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_great-imports' !== $hook ) {
            return;
        }

        wp_enqueue_style( 'great-imports-admin', GREAT_IMPORTS_URL . 'assets/css/admin.css', array(), GREAT_IMPORTS_VERSION );
    }

    /**
     * Handle Eventbrite API settings save.
     */
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

    /**
     * Handle exploratory report download.
     */
    public function handle_download_exploratory_report() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to download Great Imports reports.', 'great-imports' ) );
        }

        check_admin_referer( 'gi_download_exploratory_report' );

        $this->exploratory_report->download();
    }

    /**
     * Handle one-time Eventbrite import form submission.
     */
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

    /**
     * Render admin page.
     */
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

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'gi_eventbrite_import_once' ); ?>
                    <input type="hidden" name="action" value="gi_eventbrite_import_once" />
                    <label for="gi_eventbrite_url" class="gi-label"><?php esc_html_e( 'Eventbrite URL', 'great-imports' ); ?></label>
                    <input type="url" class="regular-text gi-url-input" id="gi_eventbrite_url" name="gi_eventbrite_url" placeholder="https://www.eventbrite.com/e/example-event-tickets-123456789" required />
                    <?php submit_button( __( 'Import once', 'great-imports' ), 'primary', 'submit', false ); ?>
                </form>
            </div>

            <div class="gi-card">
                <h2><?php esc_html_e( 'Exploratory Report', 'great-imports' ); ?></h2>
                <p><?php esc_html_e( 'Download a sanitized JSON report showing plugin state, Events Manager detection, Eventbrite token status, candidate summaries, source URLs, Eventbrite IDs, fetch methods, status codes, errors, and all tracked Great Imports candidate metadata.', 'great-imports' ); ?></p>
                <p><?php esc_html_e( 'Secret values are not exported.', 'great-imports' ); ?></p>
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
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Title', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Start', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Location', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Method', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Source', 'great-imports' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $recent_candidates as $candidate ) : ?>
                                <?php $source_url = (string) get_post_meta( $candidate->ID, '_gi_source_url', true ); ?>
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
                                            <?php esc_html_e( 'Eventbrite', 'great-imports' ); ?>
                                        <?php endif; ?>
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

    /**
     * Redirect to admin page with a notice.
     *
     * @param string $status Notice status.
     * @param string $message Notice message.
     * @param int    $post_id Candidate post ID.
     */
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

    /**
     * Render status notice after import attempt.
     */
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
