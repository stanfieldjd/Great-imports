<?php
/**
 * Browser-side observer for Events Manager location map refresh flow.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_EM_Location_Browser_Trace {
    const LOCATION_META_KEY  = '_gi_em_browser_location_trace';
    const CANDIDATE_META_KEY = '_gi_em_browser_location_trace';

    public function register_hooks() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 5 );
        add_action( 'wp_ajax_gi_em_location_browser_trace', array( $this, 'handle_trace' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( 'post.php' !== $hook ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        $location_post_type = defined( 'EM_POST_TYPE_LOCATION' ) ? EM_POST_TYPE_LOCATION : 'location';

        if ( ! $screen || $location_post_type !== $screen->post_type || ! $post_id ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        wp_enqueue_script( 'great-imports-em-location-browser-trace', GREAT_IMPORTS_URL . 'assets/js/em-location-browser-trace.js', array(), GREAT_IMPORTS_VERSION, false );
        wp_localize_script(
            'great-imports-em-location-browser-trace',
            'GreatImportsEMLocationTrace',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'gi_em_location_browser_trace_' . $post_id ),
                'postId'  => $post_id,
            )
        );
    }

    public function handle_trace() {
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
        }
        check_ajax_referer( 'gi_em_location_browser_trace_' . $post_id, 'nonce' );

        $event = isset( $_POST['trace_event'] ) ? sanitize_key( wp_unslash( $_POST['trace_event'] ) ) : '';
        $snapshot_json = isset( $_POST['snapshot'] ) ? wp_unslash( $_POST['snapshot'] ) : '{}';
        $snapshot = json_decode( (string) $snapshot_json, true );
        if ( ! is_array( $snapshot ) ) {
            $snapshot = array();
        }

        $location_id = absint( get_post_meta( $post_id, '_location_id', true ) );
        $record = array(
            'recorded_at'     => current_time( 'mysql' ),
            'event'           => $event ? $event : 'unknown',
            'location_post_id'=> $post_id,
            'em_location_id'  => $location_id,
            'snapshot'        => $this->sanitize_snapshot( $snapshot ),
            'trace_rule'      => 'Browser observer records coordinate field presence only. Raw coordinate values are not sent or stored.',
        );

        $this->append_trace( $post_id, self::LOCATION_META_KEY, $record );

        foreach ( $this->candidate_ids_for_location( $location_id ) as $candidate_id ) {
            $this->append_trace( $candidate_id, self::CANDIDATE_META_KEY, $record );
        }

        wp_send_json_success( array( 'stored' => true ) );
    }

    private function sanitize_snapshot( array $snapshot ) {
        $bool_keys = array(
            'latitude_field_present',
            'longitude_field_present',
            'latitude_has_value',
            'longitude_has_value',
            'latitude_present',
            'longitude_present',
            'complete',
            'address_present',
            'form_present',
        );
        $text_keys = array(
            'label',
            'path',
        );

        $clean = array();
        foreach ( $bool_keys as $key ) {
            if ( array_key_exists( $key, $snapshot ) ) {
                $clean[ $key ] = ! empty( $snapshot[ $key ] );
            }
        }
        foreach ( $text_keys as $key ) {
            if ( isset( $snapshot[ $key ] ) ) {
                $clean[ $key ] = sanitize_text_field( (string) $snapshot[ $key ] );
            }
        }
        if ( isset( $snapshot['elapsed_ms'] ) ) {
            $clean['elapsed_ms'] = max( 0, absint( $snapshot['elapsed_ms'] ) );
        }

        return $clean;
    }

    private function candidate_ids_for_location( $location_id ) {
        $location_id = absint( $location_id );
        if ( ! $location_id ) {
            return array();
        }

        return array_map(
            'absint',
            get_posts(
                array(
                    'post_type'      => 'gi_candidate',
                    'post_status'    => 'any',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_key'       => '_gi_em_location_id',
                    'meta_value'     => (string) $location_id,
                )
            )
        );
    }

    private function append_trace( $post_id, $meta_key, array $record ) {
        $trace = get_post_meta( $post_id, $meta_key, true );
        if ( ! is_array( $trace ) ) {
            $trace = array();
        }

        $trace[] = $record;
        if ( count( $trace ) > 80 ) {
            $trace = array_slice( $trace, -80 );
        }

        update_post_meta( $post_id, $meta_key, $trace );
    }
}
