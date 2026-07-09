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

    /** @var GI_Evidence_Store */
    private $evidence_store;

    public function __construct( GI_Eventbrite_API_Client $api_client, GI_Evidence_Store $evidence_store ) {
        $this->api_client     = $api_client;
        $this->evidence_store = $evidence_store;
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
        $evidence   = $this->get_evidence_records();

        return array(
            'report_type'     => 'great_imports_exploratory_report',
            'generated_at'    => current_time( 'mysql' ),
            'generated_at_utc'=> gmdate( 'Y-m-d H:i:s' ),
            'capture_model'   => array(
                'rule' => 'Full-view-first evidence capture before relevance, normalization, filtering, mapping, or handoff decisions.',
                'candidate_role' => 'Candidate data is a downstream interpretation of evidence, not the evidence source.',
            ),
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
            'summary'         => $this->summarize_all( $candidates, $evidence ),
            'evidence_records'=> $evidence,
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
            $meta = $this->prefixed_meta( (int) $post->ID );

            $candidates[] = array(
                'post_id'       => (int) $post->ID,
                'post_status'   => sanitize_text_field( (string) $post->post_status ),
                'post_title'    => sanitize_text_field( get_the_title( $post ) ),
                'post_content'  => (string) $post->post_content,
                'created_at'    => sanitize_text_field( (string) $post->post_date ),
                'modified_at'   => sanitize_text_field( (string) $post->post_modified ),
                'tracked_meta'  => $meta,
            );
        }

        return $candidates;
    }

    /**
     * Read all captured evidence records.
     *
     * @return array<int,array<string,mixed>>
     */
    private function get_evidence_records() {
        $records = array();
        $posts   = $this->evidence_store->get_evidence_posts( -1 );

        foreach ( $posts as $post ) {
            $bundle = get_post_meta( (int) $post->ID, '_gi_evidence_bundle', true );

            $records[] = array(
                'post_id'       => (int) $post->ID,
                'post_status'   => sanitize_text_field( (string) $post->post_status ),
                'post_title'    => sanitize_text_field( get_the_title( $post ) ),
                'created_at'    => sanitize_text_field( (string) $post->post_date ),
                'modified_at'   => sanitize_text_field( (string) $post->post_modified ),
                'tracked_meta'  => $this->prefixed_meta( (int) $post->ID ),
                'bundle'        => $this->sanitize_report_value( 'evidence_bundle', $bundle ),
            );
        }

        return $records;
    }

    /**
     * Read post metadata that belongs to Great Imports.
     *
     * @param int $post_id Post ID.
     * @return array<string,mixed>
     */
    private function prefixed_meta( $post_id ) {
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
     * Summarize candidates and evidence records.
     *
     * @param array<int,array<string,mixed>> $candidates Candidates.
     * @param array<int,array<string,mixed>> $evidence Evidence records.
     * @return array<string,mixed>
     */
    private function summarize_all( array $candidates, array $evidence ) {
        $summary = array(
            'total_candidates'       => count( $candidates ),
            'total_evidence_records' => count( $evidence ),
            'candidate_by_status'    => array(),
            'candidate_by_source_type' => array(),
            'candidate_by_fetch_method' => array(),
            'evidence_items_total'   => 0,
            'evidence_item_labels'   => array(),
        );

        foreach ( $candidates as $candidate ) {
            $meta = isset( $candidate['tracked_meta'] ) && is_array( $candidate['tracked_meta'] ) ? $candidate['tracked_meta'] : array();

            $status = isset( $meta['candidate_status'] ) && '' !== $meta['candidate_status'] ? $meta['candidate_status'] : '[missing]';
            $source = isset( $meta['source_type'] ) && '' !== $meta['source_type'] ? $meta['source_type'] : '[missing]';
            $method = isset( $meta['fetch_method'] ) && '' !== $meta['fetch_method'] ? $meta['fetch_method'] : '[missing]';

            $summary['candidate_by_status'][ $status ]       = isset( $summary['candidate_by_status'][ $status ] ) ? $summary['candidate_by_status'][ $status ] + 1 : 1;
            $summary['candidate_by_source_type'][ $source ]  = isset( $summary['candidate_by_source_type'][ $source ] ) ? $summary['candidate_by_source_type'][ $source ] + 1 : 1;
            $summary['candidate_by_fetch_method'][ $method ] = isset( $summary['candidate_by_fetch_method'][ $method ] ) ? $summary['candidate_by_fetch_method'][ $method ] + 1 : 1;
        }

        foreach ( $evidence as $record ) {
            $bundle = isset( $record['bundle'] ) && is_array( $record['bundle'] ) ? $record['bundle'] : array();
            $items  = isset( $bundle['items'] ) && is_array( $bundle['items'] ) ? $bundle['items'] : array();
            $summary['evidence_items_total'] += count( $items );

            foreach ( $items as $key => $item ) {
                $label = isset( $item['label'] ) ? (string) $item['label'] : (string) $key;
                $summary['evidence_item_labels'][ $label ] = isset( $summary['evidence_item_labels'][ $label ] ) ? $summary['evidence_item_labels'][ $label ] + 1 : 1;
            }
        }

        ksort( $summary['candidate_by_status'] );
        ksort( $summary['candidate_by_source_type'] );
        ksort( $summary['candidate_by_fetch_method'] );
        ksort( $summary['evidence_item_labels'] );

        return $summary;
    }

    /**
     * Sanitize report values and redact secret-like keys while preserving raw evidence.
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

        return (string) $value;
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
