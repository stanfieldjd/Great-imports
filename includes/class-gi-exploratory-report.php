<?php
/**
 * Exploratory report exporter.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Exploratory_Report {
    /** @var GI_Eventbrite_API_Client */
    private $api_client;

    public function __construct( GI_Eventbrite_API_Client $api_client ) {
        $this->api_client = $api_client;
    }

    /**
     * Download the exploratory report as sanitized JSON.
     */
    public function download() {
        $report   = $this->generate();
        $filename = 'great-imports-exploratory-report-' . gmdate( 'Ymd-His' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * Generate a sanitized exploratory report.
     *
     * @return array<string,mixed>
     */
    public function generate() {
        global $wp_version;

        $candidates = $this->get_candidates();

        return array(
            'report_type'     => 'great_imports_exploratory_report',
            'generated_at'    => current_time( 'mysql' ),
            'generated_at_utc'=> gmdate( 'Y-m-d H:i:s' ),
            'secrets_policy'  => array(
                'secret_values_exported' => false,
                'note'                   => 'Token, secret, password, authorization, bearer, and key-like fields are redacted by field name.',
            ),
            'environment'     => array(
                'plugin_version'       => defined( 'GREAT_IMPORTS_VERSION' ) ? GREAT_IMPORTS_VERSION : '',
                'site_url'             => site_url(),
                'home_url'             => home_url(),
                'wordpress_version'    => isset( $wp_version ) ? $wp_version : '',
                'php_version'          => PHP_VERSION,
                'timezone_string'      => (string) get_option( 'timezone_string', '' ),
                'gmt_offset'           => (string) get_option( 'gmt_offset', '' ),
                'events_manager'       => $this->events_manager_status(),
            ),
            'settings_status' => array(
                'eventbrite_private_token_configured' => $this->api_client->has_private_token(),
                'eventbrite_private_token_value'      => $this->api_client->has_private_token() ? '[configured-not-exported]' : '[not-configured]',
            ),
            'summary'         => $this->summarize_candidates( $candidates ),
            'candidates'      => $candidates,
        );
    }

    /**
     * Return Events Manager presence without taking dependencies on it.
     *
     * @return array<string,mixed>
     */
    private function events_manager_status() {
        return array(
            'em_version_defined' => defined( 'EM_VERSION' ),
            'em_version'         => defined( 'EM_VERSION' ) ? EM_VERSION : '',
            'em_events_class'    => class_exists( 'EM_Events' ),
            'em_locations_class' => class_exists( 'EM_Locations' ),
        );
    }

    /**
     * Read all tracked Great Imports candidates.
     *
     * @return array<int,array<string,mixed>>
     */
    private function get_candidates() {
        $posts = get_posts(
            array(
                'post_type'      => 'gi_candidate',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );

        $candidates = array();

        foreach ( $posts as $post ) {
            $meta = $this->candidate_meta( (int) $post->ID );

            $candidates[] = array(
                'post_id'       => (int) $post->ID,
                'post_status'   => sanitize_text_field( (string) $post->post_status ),
                'post_title'    => sanitize_text_field( get_the_title( $post ) ),
                'post_content'  => wp_kses_post( (string) $post->post_content ),
                'created_at'    => sanitize_text_field( (string) $post->post_date ),
                'modified_at'   => sanitize_text_field( (string) $post->post_modified ),
                'tracked_meta'  => $meta,
            );
        }

        return $candidates;
    }

    /**
     * Read candidate metadata that belongs to Great Imports.
     *
     * @param int $post_id Candidate post ID.
     * @return array<string,mixed>
     */
    private function candidate_meta( $post_id ) {
        $raw_meta = get_post_meta( $post_id );
        $meta     = array();

        foreach ( $raw_meta as $key => $values ) {
            if ( 0 !== strpos( $key, '_gi_' ) ) {
                continue;
            }

            $report_key          = substr( $key, 4 );
            $value               = count( $values ) > 1 ? $values : maybe_unserialize( $values[0] );
            $meta[ $report_key ] = $this->sanitize_report_value( $report_key, $value );
        }

        ksort( $meta );

        return $meta;
    }

    /**
     * Summarize candidates by status, source type, and fetch method.
     *
     * @param array<int,array<string,mixed>> $candidates Candidates.
     * @return array<string,mixed>
     */
    private function summarize_candidates( array $candidates ) {
        $summary = array(
            'total_candidates' => count( $candidates ),
            'by_status'        => array(),
            'by_source_type'   => array(),
            'by_fetch_method'  => array(),
        );

        foreach ( $candidates as $candidate ) {
            $meta = isset( $candidate['tracked_meta'] ) && is_array( $candidate['tracked_meta'] ) ? $candidate['tracked_meta'] : array();

            $status = isset( $meta['candidate_status'] ) && '' !== $meta['candidate_status'] ? $meta['candidate_status'] : '[missing]';
            $source = isset( $meta['source_type'] ) && '' !== $meta['source_type'] ? $meta['source_type'] : '[missing]';
            $method = isset( $meta['fetch_method'] ) && '' !== $meta['fetch_method'] ? $meta['fetch_method'] : '[missing]';

            $summary['by_status'][ $status ]       = isset( $summary['by_status'][ $status ] ) ? $summary['by_status'][ $status ] + 1 : 1;
            $summary['by_source_type'][ $source ]  = isset( $summary['by_source_type'][ $source ] ) ? $summary['by_source_type'][ $source ] + 1 : 1;
            $summary['by_fetch_method'][ $method ] = isset( $summary['by_fetch_method'][ $method ] ) ? $summary['by_fetch_method'][ $method ] + 1 : 1;
        }

        ksort( $summary['by_status'] );
        ksort( $summary['by_source_type'] );
        ksort( $summary['by_fetch_method'] );

        return $summary;
    }

    /**
     * Sanitize report values and redact secret-like keys.
     *
     * @param string $key Field key.
     * @param mixed  $value Field value.
     * @return mixed
     */
    private function sanitize_report_value( $key, $value ) {
        if ( $this->is_secret_key( $key ) ) {
            return '[redacted]';
        }

        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $child_key => $child_value ) {
                $clean[ $child_key ] = $this->sanitize_report_value( (string) $child_key, $child_value );
            }
            return $clean;
        }

        if ( is_object( $value ) ) {
            return $this->sanitize_report_value( $key, (array) $value );
        }

        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
            return $value;
        }

        return wp_kses_post( (string) $value );
    }

    /**
     * Detect field names that must never export raw values.
     *
     * @param string $key Field key.
     */
    private function is_secret_key( $key ) {
        $key = strtolower( (string) $key );

        foreach ( array( 'token', 'secret', 'password', 'authorization', 'bearer', 'api_key', 'apikey', 'client_secret' ) as $needle ) {
            if ( false !== strpos( $key, $needle ) ) {
                return true;
            }
        }

        return false;
    }
}
