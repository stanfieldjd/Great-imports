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

    public function __construct( GI_Eventbrite_Importer $importer ) {
        $this->importer = $importer;
    }

    /**
     * Register admin hooks.
     */
    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_bar_menu', array( $this, 'register_admin_bar' ), 90 );
        add_action( 'admin_post_gi_eventbrite_import_once', array( $this, 'handle_eventbrite_import_once' ) );
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
     * Handle one-time Eventbrite import form submission.
     */
    public function handle_eventbrite_import_once() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to import events.', 'great-imports' ) );
        }

        check_admin_referer( 'gi_eventbrite_import_once' );

        $url    = isset( $_POST['gi_eventbrite_url'] ) ? wp_unslash( $_POST['gi_eventbrite_url'] ) : '';
        $result = $this->importer->import_once( $url );

        $redirect = add_query_arg(
            array(
                'page'       => 'great-imports',
                'gi_status'  => $result['success'] ? 'success' : 'error',
                'gi_message' => $result['message'],
                'gi_post_id' => absint( $result['post_id'] ),
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
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
                <h2><?php esc_html_e( 'One-time Eventbrite Import', 'great-imports' ); ?></h2>
                <p><?php esc_html_e( 'Paste one Eventbrite event URL. Great Imports will collect public event data and create or update a review candidate.', 'great-imports' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'gi_eventbrite_import_once' ); ?>
                    <input type="hidden" name="action" value="gi_eventbrite_import_once" />
                    <label for="gi_eventbrite_url" class="gi-label"><?php esc_html_e( 'Eventbrite URL', 'great-imports' ); ?></label>
                    <input type="url" class="regular-text gi-url-input" id="gi_eventbrite_url" name="gi_eventbrite_url" placeholder="https://www.eventbrite.com/e/example-event-tickets-123456789" required />
                    <?php submit_button( __( 'Import once', 'great-imports' ), 'primary', 'submit', false ); ?>
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
                                <th><?php esc_html_e( 'Start', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Location', 'great-imports' ); ?></th>
                                <th><?php esc_html_e( 'Source', 'great-imports' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $recent_candidates as $candidate ) : ?>
                                <?php $source_url = (string) get_post_meta( $candidate->ID, '_gi_source_url', true ); ?>
                                <tr>
                                    <td><strong><?php echo esc_html( get_the_title( $candidate ) ); ?></strong></td>
                                    <td><?php echo esc_html( (string) get_post_meta( $candidate->ID, '_gi_start_date', true ) ); ?></td>
                                    <td><?php echo esc_html( (string) get_post_meta( $candidate->ID, '_gi_location_name', true ) ); ?></td>
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
