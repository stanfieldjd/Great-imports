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

    /** @var GI_Import_Preview_Builder */
    private $preview_builder;

    /** @var GI_Page_Display_Report_Builder */
    private $display_builder;

    public function __construct( GI_Eventbrite_API_Client $api_client, GI_Evidence_Store $evidence_store, GI_Import_Preview_Builder $preview_builder, GI_Page_Display_Report_Builder $display_builder ) {
        $this->api_client      = $api_client;
        $this->evidence_store  = $evidence_store;
        $this->preview_builder = $preview_builder;
        $this->display_builder = $display_builder;
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

        $candidate_posts      = $this->get_candidate_posts();
        $candidates           = $this->format_candidates( $candidate_posts );
        $source_page_displays = $this->get_source_page_display_reports( $candidate_posts );
        $import_previews      = $this->get_import_previews( $candidate_posts );
        $evidence             = $this->get_evidence_records();

        return array(
            'report_type'                 => 'great_imports_exploratory_report',
            'generated_at'                => current_time( 'mysql' ),
            'generated_at_utc'            => gmdate( 'Y-m-d H:i:s' ),
            'capture_model'               => array(
                'rule'                => 'Full-view-first evidence capture before relevance, normalization, filtering, mapping, or handoff decisions.',
                'candidate_role'      => 'Candidate data is a downstream interpretation of evidence, not the evidence source.',
                'display_report_role' => 'Source page display reports show screenshot-style page evidence extracted from captured source evidence.',
                'preview_role'        => 'Import previews show proposed public Events Manager fields and exclusions. They do not save Events Manager events.',
            ),
            'report_hygiene'              => array(
                'secret_values_exported'                => false,
                'cookie_values_exported'                => false,
                'rate_limit_header_values_exported'     => false,
                'structured_coordinate_fields_exported' => false,
                'note'                                  => 'Token, secret, password, authorization, bearer, key-like, cookie, rate-limit, Eventbrite internal header, and structured coordinate fields are redacted by field name.',
            ),
            'environment'                 => array(
                'plugin_version'    => defined( 'GREAT_IMPORTS_VERSION' ) ? GREAT_IMPORTS_VERSION : '',
                'site_url'          => site_url(),
                'home_url'          => home_url(),
                'wordpress_version' => isset( $wp_version ) ? $wp_version : '',
                'php_version'       => PHP_VERSION,
                'timezone_string'   => (string) get_option( 'timezone_string', '' ),
                'gmt_offset'        => (string) get_option( 'gmt_offset', '' ),
                'events_manager'    => $this->events_manager_status(),
            ),
            'settings_status'             => array(
                'eventbrite_private_token_configured' => $this->api_client->has_private_token(),
                'eventbrite_private_token_value'      => $this->api_client->has_private_token() ? '[configured-not-exported]' : '[not-configured]',
            ),
            'summary'                     => $this->summarize_all( $candidates, $evidence, $import_previews, $source_page_displays ),
            'source_page_display_reports' => $source_page_displays,
            'import_previews'             => $import_previews,
            'evidence_records'            => $evidence,
            'candidates'                  => $candidates,
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
     * Read candidate posts.
     *
     * @return WP_Post[]
     */
    private function get_candidate_posts() {
        return get_posts(
            array(
                'post_type'      => 'gi_candidate',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );
    }

    /**
     * Format all tracked Great Imports candidates.
     *
     * @param WP_Post[] $posts Candidate posts.
     * @return array<int,array<string,mixed>>
     */
    private function format_candidates( array $posts ) {
        $candidates = array();

        foreach ( $posts as $post ) {
            $meta = $this->prefixed_meta( (int) $post->ID );

            $candidates[] = array(
                'post_id'      => (int) $post->ID,
                'post_status'  => sanitize_text_field( (string) $post->post_status ),
                'post_title'   => sanitize_text_field( get_the_title( $post ) ),
                'post_content' => $this->sanitize_report_value( 'post_content', (string) $post->post_content ),
                'created_at'   => sanitize_text_field( (string) $post->post_date ),
                'modified_at'  => sanitize_text_field( (string) $post->post_modified ),
                'tracked_meta' => $meta,
            );
        }

        return $candidates;
    }

    /**
     * Build source-page display reports for screenshot-style review.
     *
     * @param WP_Post[] $posts Candidate posts.
     * @return array<int,array<string,mixed>>
     */
    private function get_source_page_display_reports( array $posts ) {
        $reports = array();

        foreach ( $posts as $post ) {
            $reports[] = $this->sanitize_report_value( 'source_page_display_report', $this->display_builder->build_for_candidate( $post ) );
        }

        return $reports;
    }

    /**
     * Build report-friendly import previews.
     *
     * @param WP_Post[] $posts Candidate posts.
     * @return array<int,array<string,mixed>>
     */
    private function get_import_previews( array $posts ) {
        $previews = array();

        foreach ( $posts as $post ) {
            $preview = $this->preview_builder->build_for_candidate( $post );

            $previews[] = array(
                'candidate_post_id'        => (int) $post->ID,
                'candidate_title'          => sanitize_text_field( get_the_title( $post ) ),
                'public_event_fields'      => $this->sanitize_report_value( 'public_event_fields', isset( $preview['public_event_fields'] ) ? $preview['public_event_fields'] : array() ),
                'time_handling'            => $this->sanitize_report_value( 'time_handling', isset( $preview['time_handling'] ) ? $preview['time_handling'] : array() ),
                'location_fields'          => $this->sanitize_report_value( 'location_fields', isset( $preview['location_fields'] ) ? $preview['location_fields'] : array() ),
                'images'                   => $this->sanitize_report_value( 'images', isset( $preview['images'] ) ? $preview['images'] : array() ),
                'ticketing'                => $this->sanitize_report_value( 'ticketing', isset( $preview['ticketing'] ) ? $preview['ticketing'] : array() ),
                'stage_handling'           => $this->sanitize_report_value( 'stage_handling', isset( $preview['stage_handling'] ) ? $preview['stage_handling'] : array() ),
                'related_events'           => $this->sanitize_report_value( 'related_events', isset( $preview['related_events'] ) ? $preview['related_events'] : array() ),
                'description_html_preview' => $this->sanitize_report_value( 'description_html_preview', isset( $preview['description_html'] ) ? $preview['description_html'] : '' ),
                'internal_tracking'        => $this->sanitize_report_value( 'internal_tracking', isset( $preview['internal_tracking'] ) ? $preview['internal_tracking'] : array() ),
                'excluded_public_data'     => $this->sanitize_report_value( 'excluded_public_data', isset( $preview['excluded_public_data'] ) ? $preview['excluded_public_data'] : array() ),
                'preview_rule'             => 'Dry run only. This report section does not save Events Manager events or locations.',
            );
        }

        return $previews;
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
                'post_id'      => (int) $post->ID,
                'post_status'  => sanitize_text_field( (string) $post->post_status ),
                'post_title'   => sanitize_text_field( get_the_title( $post ) ),
                'created_at'   => sanitize_text_field( (string) $post->post_date ),
                'modified_at'  => sanitize_text_field( (string) $post->post_modified ),
                'tracked_meta' => $this->prefixed_meta( (int) $post->ID ),
                'bundle'       => $this->sanitize_report_value( 'evidence_bundle', $bundle ),
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
     * Summarize candidates, evidence records, previews, and source-page display reports.
     *
     * @param array<int,array<string,mixed>> $candidates Candidates.
     * @param array<int,array<string,mixed>> $evidence Evidence records.
     * @param array<int,array<string,mixed>> $previews Import previews.
     * @param array<int,array<string,mixed>> $display_reports Display reports.
     * @return array<string,mixed>
     */
    private function summarize_all( array $candidates, array $evidence, array $previews, array $display_reports ) {
        $summary = array(
            'total_candidates'                 => count( $candidates ),
            'total_evidence_records'           => count( $evidence ),
            'total_import_previews'            => count( $previews ),
            'total_source_page_display_reports'=> count( $display_reports ),
            'candidate_by_status'              => array(),
            'candidate_by_source_type'         => array(),
            'candidate_by_fetch_method'        => array(),
            'evidence_items_total'             => 0,
            'evidence_item_labels'             => array(),
            'preview_exclusion_labels'         => array(),
            'preview_stage_room_detected'      => 0,
            'preview_timeslots_detected'       => 0,
            'display_visible_text_lines_total' => 0,
            'display_faq_items_total'          => 0,
            'display_related_markers_total'    => 0,
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

        foreach ( $previews as $preview ) {
            if ( ! empty( $preview['stage_handling']['stage_room'] ) ) {
                $summary['preview_stage_room_detected']++;
            }

            if ( ! empty( $preview['time_handling']['em_timeslots'] ) ) {
                $summary['preview_timeslots_detected']++;
            }

            $excluded = isset( $preview['excluded_public_data'] ) && is_array( $preview['excluded_public_data'] ) ? $preview['excluded_public_data'] : array();
            foreach ( $excluded as $label ) {
                if ( is_array( $label ) || is_object( $label ) ) {
                    continue;
                }
                $label = (string) $label;
                $summary['preview_exclusion_labels'][ $label ] = isset( $summary['preview_exclusion_labels'][ $label ] ) ? $summary['preview_exclusion_labels'][ $label ] + 1 : 1;
            }
        }

        foreach ( $display_reports as $display ) {
            if ( ! empty( $display['visible_text_report']['line_count'] ) ) {
                $summary['display_visible_text_lines_total'] += (int) $display['visible_text_report']['line_count'];
            }
            if ( ! empty( $display['screenshot_visible_sections']['faq']['count'] ) ) {
                $summary['display_faq_items_total'] += (int) $display['screenshot_visible_sections']['faq']['count'];
            }
            if ( ! empty( $display['screenshot_visible_sections']['related']['section_markers_found'] ) && is_array( $display['screenshot_visible_sections']['related']['section_markers_found'] ) ) {
                $summary['display_related_markers_total'] += count( $display['screenshot_visible_sections']['related']['section_markers_found'] );
            }
        }

        ksort( $summary['candidate_by_status'] );
        ksort( $summary['candidate_by_source_type'] );
        ksort( $summary['candidate_by_fetch_method'] );
        ksort( $summary['evidence_item_labels'] );
        ksort( $summary['preview_exclusion_labels'] );

        return $summary;
    }

    /**
     * Sanitize report values and redact sensitive or excluded field names.
     *
     * @param string $key Field key.
     * @param mixed  $value Field value.
     * @return mixed
     */
    private function sanitize_report_value( $key, $value ) {
        if ( $this->is_secret_key( $key ) ) {
            return '[redacted]';
        }

        if ( $this->is_coordinate_key( $key ) ) {
            return '[excluded-not-used]';
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

        foreach ( array( 'token', 'secret', 'password', 'authorization', 'bearer', 'api_key', 'apikey', 'client_secret', 'cookie', 'set-cookie', 'rate-limit', 'ratelimit', 'x-eb-' ) as $needle ) {
            if ( false !== strpos( $key, $needle ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect structured coordinate fields. Great Imports does not use or export these in review reports.
     *
     * @param string $key Field key.
     */
    private function is_coordinate_key( $key ) {
        $key = strtolower( (string) $key );

        return in_array( $key, array( 'latitude', 'longitude', 'lat', 'lng', 'lon', 'long' ), true );
    }
}
