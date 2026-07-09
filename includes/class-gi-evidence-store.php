<?php
/**
 * Stores source evidence bundles before interpretation.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Evidence_Store {
    /**
     * Create a new evidence bundle skeleton.
     *
     * @param array<string,mixed> $context Capture context.
     * @return array<string,mixed>
     */
    public function create_bundle( array $context ) {
        return array(
            'capture_run_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'gi_', true ),
            'created_at'     => current_time( 'mysql' ),
            'created_at_utc' => gmdate( 'Y-m-d H:i:s' ),
            'context'        => $context,
            'items'          => array(),
            'notes'          => array(
                'capture_rule' => 'Full-view-first: raw evidence captured before relevance, filtering, mapping, or handoff decisions.',
            ),
        );
    }

    /**
     * Add an evidence item to a bundle.
     *
     * @param array<string,mixed> $bundle Bundle.
     * @param string              $key Stable item key.
     * @param array<string,mixed> $item Evidence item.
     * @return array<string,mixed>
     */
    public function add_item( array $bundle, $key, array $item ) {
        if ( ! isset( $bundle['items'] ) || ! is_array( $bundle['items'] ) ) {
            $bundle['items'] = array();
        }

        $bundle['items'][ sanitize_key( $key ) ] = $item;

        return $bundle;
    }

    /**
     * Save a captured evidence bundle.
     *
     * @param array<string,mixed> $bundle Evidence bundle.
     * @return array{success:bool,post_id:int,error:string}
     */
    public function save_bundle( array $bundle ) {
        $context      = isset( $bundle['context'] ) && is_array( $bundle['context'] ) ? $bundle['context'] : array();
        $source_type  = isset( $context['source_type'] ) ? sanitize_text_field( (string) $context['source_type'] ) : 'source';
        $source_label = isset( $context['eventbrite_event_id'] ) && '' !== $context['eventbrite_event_id'] ? sanitize_text_field( (string) $context['eventbrite_event_id'] ) : sanitize_text_field( (string) $source_type );
        $title        = 'Evidence ' . $source_type . ' ' . $source_label . ' ' . current_time( 'mysql' );

        $post_id = wp_insert_post(
            wp_slash(
                array(
                    'post_type'    => 'gi_evidence',
                    'post_title'   => $title,
                    'post_content' => '',
                    'post_status'  => 'draft',
                )
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            return array(
                'success' => false,
                'post_id' => 0,
                'error'   => $post_id->get_error_message(),
            );
        }

        $bundle['evidence_post_id'] = (int) $post_id;
        $bundle['item_count']       = isset( $bundle['items'] ) && is_array( $bundle['items'] ) ? count( $bundle['items'] ) : 0;

        update_post_meta( $post_id, '_gi_evidence_bundle', $bundle );
        update_post_meta( $post_id, '_gi_capture_run_id', isset( $bundle['capture_run_id'] ) ? sanitize_text_field( (string) $bundle['capture_run_id'] ) : '' );
        update_post_meta( $post_id, '_gi_source_type', $source_type );
        update_post_meta( $post_id, '_gi_source_url', isset( $context['source_url'] ) ? esc_url_raw( (string) $context['source_url'] ) : '' );
        update_post_meta( $post_id, '_gi_submitted_url', isset( $context['submitted_url'] ) ? esc_url_raw( (string) $context['submitted_url'] ) : '' );
        update_post_meta( $post_id, '_gi_eventbrite_event_id', isset( $context['eventbrite_event_id'] ) ? sanitize_text_field( (string) $context['eventbrite_event_id'] ) : '' );
        update_post_meta( $post_id, '_gi_item_count', $bundle['item_count'] );

        return array(
            'success' => true,
            'post_id' => (int) $post_id,
            'error'   => '',
        );
    }

    /**
     * Get evidence records for reports.
     *
     * @param int $limit Maximum records; -1 for all.
     * @return WP_Post[]
     */
    public function get_evidence_posts( $limit = -1 ) {
        return get_posts(
            array(
                'post_type'      => 'gi_evidence',
                'post_status'    => 'any',
                'posts_per_page' => (int) $limit,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );
    }
}
