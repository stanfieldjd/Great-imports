<?php
/**
 * Stores review candidates.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Candidate_Store {
    /**
     * Save or update an event candidate.
     *
     * @param array<string,mixed> $candidate Candidate data.
     * @return array{success:bool,post_id:int,error:string,updated:bool}
     */
    public function save_event_candidate( array $candidate ) {
        $source_url  = isset( $candidate['source_url'] ) ? esc_url_raw( $candidate['source_url'] ) : '';
        $fingerprint = $this->fingerprint( $candidate );
        $existing_id = $this->find_existing_candidate( $source_url, $fingerprint );

        $title   = ! empty( $candidate['title'] ) ? sanitize_text_field( $candidate['title'] ) : __( 'Untitled Eventbrite candidate', 'great-imports' );
        $content = ! empty( $candidate['description'] ) ? wp_kses_post( $candidate['description'] ) : '';

        $post_data = array(
            'post_type'    => 'gi_candidate',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
        );

        if ( $existing_id ) {
            $post_data['ID'] = $existing_id;
            $post_id         = wp_update_post( wp_slash( $post_data ), true );
            $updated         = true;
        } else {
            $post_id = wp_insert_post( wp_slash( $post_data ), true );
            $updated = false;
        }

        if ( is_wp_error( $post_id ) ) {
            return array(
                'success' => false,
                'post_id' => 0,
                'error'   => $post_id->get_error_message(),
                'updated' => false,
            );
        }

        $candidate['fingerprint'] = $fingerprint;
        $candidate['stored_at']   = current_time( 'mysql' );

        foreach ( $candidate as $key => $value ) {
            update_post_meta( $post_id, '_gi_' . sanitize_key( $key ), $value );
        }

        return array(
            'success' => true,
            'post_id' => (int) $post_id,
            'error'   => '',
            'updated' => $updated,
        );
    }

    /**
     * Get recent candidates for the admin page.
     *
     * @param int $limit Maximum rows.
     * @return WP_Post[]
     */
    public function get_recent_candidates( $limit = 20 ) {
        return get_posts(
            array(
                'post_type'      => 'gi_candidate',
                'post_status'    => 'draft',
                'posts_per_page' => absint( $limit ),
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );
    }

    /**
     * Build a duplicate fingerprint from title/date/location/source.
     *
     * @param array<string,mixed> $candidate Candidate data.
     */
    private function fingerprint( array $candidate ) {
        $parts = array(
            isset( $candidate['title'] ) ? $candidate['title'] : '',
            isset( $candidate['start_date'] ) ? $candidate['start_date'] : '',
            isset( $candidate['location_name'] ) ? $candidate['location_name'] : '',
            isset( $candidate['source_url'] ) ? $candidate['source_url'] : '',
        );

        $normalized = strtolower( implode( '|', array_map( 'sanitize_text_field', $parts ) ) );
        $normalized = preg_replace( '/\s+/', ' ', $normalized );

        return hash( 'sha256', trim( (string) $normalized ) );
    }

    /**
     * Find an existing candidate by source URL or fingerprint.
     *
     * @param string $source_url Source URL.
     * @param string $fingerprint Fingerprint.
     */
    private function find_existing_candidate( $source_url, $fingerprint ) {
        $queries = array();

        if ( '' !== $source_url ) {
            $queries[] = array(
                'key'   => '_gi_source_url',
                'value' => $source_url,
            );
        }

        if ( '' !== $fingerprint ) {
            $queries[] = array(
                'key'   => '_gi_fingerprint',
                'value' => $fingerprint,
            );
        }

        foreach ( $queries as $meta_query ) {
            $posts = get_posts(
                array(
                    'post_type'      => 'gi_candidate',
                    'post_status'    => 'draft',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array( $meta_query ),
                )
            );

            if ( ! empty( $posts[0] ) ) {
                return (int) $posts[0];
            }
        }

        return 0;
    }
}
