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

    /** @var GI_Source_Coverage_Audit_Builder */
    private $coverage_auditor;

    public function __construct( GI_Eventbrite_API_Client $api_client, GI_Evidence_Store $evidence_store, GI_Import_Preview_Builder $preview_builder, GI_Page_Display_Report_Builder $display_builder, GI_Source_Coverage_Audit_Builder $coverage_auditor ) {
        $this->api_client       = $api_client;
        $this->evidence_store   = $evidence_store;
        $this->preview_builder  = $preview_builder;
        $this->display_builder  = $display_builder;
        $this->coverage_auditor = $coverage_auditor;
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
        $coverage_audits      = $this->get_coverage_audits( $candidate_posts, $source_page_displays, $import_previews );
        $evidence             = $this->get_evidence_records();
        $em_location_traces   = $this->get_events_manager_location_traces( $candidate_posts );

        return array(
            'report_type'                 => 'great_imports_exploratory_report',
            'generated_at'                => current_time( 'mysql' ),
            'generated_at_utc'            => gmdate( 'Y-m-d H:i:s' ),
            'capture_model'               => array(
                'rule'                => 'Full-view-first evidence capture before relevance, normalization, filtering, mapping, or handoff decisions.',
                'candidate_role'      => 'Candidate data is a downstream interpretation of evidence, not the evidence source.',
                'display_report_role' => 'Source page display reports show screenshot-style page evidence extracted from captured source evidence.',
                'preview_role'        => 'Import previews show proposed public Events Manager fields and exclusions. They do not save Events Manager events.',
                'coverage_audit_role' => 'Coverage audits list captured, missing-required, missing-optional, excluded, and browser-rendering-gap areas so source information is not silently dropped.',
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
            'summary'                     => $this->summarize_all( $candidates, $evidence, $import_previews, $source_page_displays, $coverage_audits, $em_location_traces ),
            'source_coverage_audits'      => $coverage_audits,
            'source_page_display_reports' => $source_page_displays,
            'import_previews'             => $import_previews,
            'events_manager_location_traces' => $em_location_traces,
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
                'events_manager_payload'   => $this->sanitize_report_value( 'events_manager_payload', isset( $preview['events_manager_payload'] ) ? $preview['events_manager_payload'] : array() ),
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
     * Build source coverage audits by candidate ID.
     *
     * @param WP_Post[] $posts Candidate posts.
     * @param array<int,array<string,mixed>> $display_reports Display reports.
     * @param array<int,array<string,mixed>> $previews Import previews.
     * @return array<int,array<string,mixed>>
     */
    private function get_coverage_audits( array $posts, array $display_reports, array $previews ) {
        $display_by_id = $this->index_by_candidate_id( $display_reports );
        $preview_by_id = $this->index_by_candidate_id( $previews );
        $audits        = array();

        foreach ( $posts as $post ) {
            $post_id = (int) $post->ID;
            $display = isset( $display_by_id[ $post_id ] ) && is_array( $display_by_id[ $post_id ] ) ? $display_by_id[ $post_id ] : array();
            $preview = isset( $preview_by_id[ $post_id ] ) && is_array( $preview_by_id[ $post_id ] ) ? $preview_by_id[ $post_id ] : array();
            $audits[] = $this->sanitize_report_value( 'source_coverage_audit', $this->coverage_auditor->build_for_candidate( $post, $display, $preview ) );
        }

        return $audits;
    }

    /**
     * Index report rows by candidate_post_id.
     *
     * @param array<int,array<string,mixed>> $items Items.
     * @return array<int,array<string,mixed>>
     */
    private function index_by_candidate_id( array $items ) {
        $indexed = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || empty( $item['candidate_post_id'] ) ) {
                continue;
            }
            $indexed[ (int) $item['candidate_post_id'] ] = $item;
        }

        return $indexed;
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
     * Build Events Manager import trace rows with current report-time snapshots.
     *
     * @param WP_Post[] $posts Candidate posts.
     * @return array<int,array<string,mixed>>
     */
    private function get_events_manager_location_traces( array $posts ) {
        $traces = array();

        foreach ( $posts as $post ) {
            $candidate_id = (int) $post->ID;
            $stored_trace = get_post_meta( $candidate_id, '_gi_em_import_trace', true );
            $em_event_id  = absint( get_post_meta( $candidate_id, '_gi_em_event_id', true ) );
            $em_location_id = absint( get_post_meta( $candidate_id, '_gi_em_location_id', true ) );

            $traces[] = $this->sanitize_report_value(
                'events_manager_location_trace',
                array(
                    'candidate_post_id' => $candidate_id,
                    'candidate_title'   => sanitize_text_field( get_the_title( $post ) ),
                    'trace_available'   => is_array( $stored_trace ) && ! empty( $stored_trace ),
                    'stored_import_trace' => is_array( $stored_trace ) ? $stored_trace : array(),
                    'current_report_time_snapshot' => array(
                        'generated_at' => current_time( 'mysql' ),
                        'em_event_id'  => $em_event_id,
                        'em_location_id' => $em_location_id,
                        'event'        => $em_event_id ? $this->event_snapshot( $em_event_id ) : array(),
                        'location'     => $em_location_id ? $this->location_snapshot( $em_location_id ) : array(),
                    ),
                    'trace_rule' => 'Before/during/after snapshots trace the Events Manager storage handoff. Coordinate values are redacted while presence, completeness, and preservation decisions are reported.',
                )
            );
        }

        return $traces;
    }

    private function location_post_id( $location_id ) {
        global $wpdb;

        $location_id = absint( $location_id );
        if ( ! $location_id ) {
            return 0;
        }

        $table = $wpdb->prefix . 'em_locations';
        return absint( $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE location_id = %d", $location_id ) ) );
    }

    private function location_snapshot( $location_id ) {
        global $wpdb;

        $location_id = absint( $location_id );
        if ( ! $location_id ) {
            return array( 'found' => false, 'location_id' => 0 );
        }

        $table = $wpdb->prefix . 'em_locations';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT location_id, post_id, location_name, location_address, location_town, location_state, location_postcode, location_country, location_latitude, location_longitude FROM {$table} WHERE location_id = %d",
                $location_id
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return array( 'found' => false, 'location_id' => $location_id );
        }

        $post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
        $meta_latitude  = $post_id ? get_post_meta( $post_id, '_location_latitude', true ) : '';
        $meta_longitude = $post_id ? get_post_meta( $post_id, '_location_longitude', true ) : '';
        $address        = array(
            'location_name'     => isset( $row['location_name'] ) ? sanitize_text_field( (string) $row['location_name'] ) : '',
            'location_address'  => isset( $row['location_address'] ) ? sanitize_text_field( (string) $row['location_address'] ) : '',
            'location_town'     => isset( $row['location_town'] ) ? sanitize_text_field( (string) $row['location_town'] ) : '',
            'location_state'    => isset( $row['location_state'] ) ? sanitize_text_field( (string) $row['location_state'] ) : '',
            'location_postcode' => isset( $row['location_postcode'] ) ? sanitize_text_field( (string) $row['location_postcode'] ) : '',
            'location_country'  => isset( $row['location_country'] ) ? sanitize_text_field( (string) $row['location_country'] ) : '',
        );
        $coordinate_state = array(
            'values_redacted' => true,
            'post_meta'       => array(
                'latitude_present'  => $this->coordinate_present( $meta_latitude ),
                'longitude_present' => $this->coordinate_present( $meta_longitude ),
                'complete'          => $this->coordinate_present( $meta_latitude ) && $this->coordinate_present( $meta_longitude ),
            ),
            'em_locations_table' => array(
                'latitude_present'  => $this->coordinate_present( isset( $row['location_latitude'] ) ? $row['location_latitude'] : '' ),
                'longitude_present' => $this->coordinate_present( isset( $row['location_longitude'] ) ? $row['location_longitude'] : '' ),
                'complete'          => $this->coordinate_present( isset( $row['location_latitude'] ) ? $row['location_latitude'] : '' ) && $this->coordinate_present( isset( $row['location_longitude'] ) ? $row['location_longitude'] : '' ),
            ),
        );
        $has_complete_coordinates = ! empty( $coordinate_state['post_meta']['complete'] ) || ! empty( $coordinate_state['em_locations_table']['complete'] );

        return array(
            'found'       => true,
            'location_id' => $location_id,
            'post_id'     => $post_id,
            'post_status' => $post_id ? sanitize_text_field( (string) get_post_status( $post_id ) ) : '',
            'post_title'  => $post_id ? sanitize_text_field( get_the_title( $post_id ) ) : '',
            'admin_edit_url' => $post_id ? esc_url_raw( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) : '',
            'address'     => $address,
            'coordinate_state' => $coordinate_state,
            'has_complete_coordinates' => $has_complete_coordinates,
            'trace_note'  => 'Coordinate values are intentionally redacted. Existing coordinate values are preserved unless an explicit replacement decision is recorded.',
        );
    }

    private function event_snapshot( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id || ! class_exists( 'EM_Event' ) ) {
            return array( 'found' => false, 'event_id' => $event_id );
        }

        if ( function_exists( 'em_get_event' ) ) {
            $event = em_get_event( $event_id );
        } else {
            $event = new EM_Event( $event_id );
        }

        if ( ! $event || empty( $event->event_id ) ) {
            return array( 'found' => false, 'event_id' => $event_id );
        }

        $post_id = ! empty( $event->post_id ) ? absint( $event->post_id ) : 0;
        return array(
            'found'       => true,
            'event_id'    => absint( $event->event_id ),
            'post_id'     => $post_id,
            'post_status' => $post_id ? sanitize_text_field( (string) get_post_status( $post_id ) ) : '',
            'post_title'  => $post_id ? sanitize_text_field( get_the_title( $post_id ) ) : '',
            'location_id' => ! empty( $event->location_id ) ? absint( $event->location_id ) : 0,
            'admin_edit_url' => $post_id ? esc_url_raw( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) : '',
        );
    }

    private function coordinate_present( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value || ! is_numeric( $value ) ) {
            return false;
        }
        return (float) $value !== 0.0;
    }

    private function address_present( array $address ) {
        foreach ( array( 'location_address', 'location_town', 'location_state', 'location_postcode', 'location_country' ) as $key ) {
            if ( ! empty( $address[ $key ] ) ) {
                return true;
            }
        }
        return false;
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
     * Summarize candidates, evidence records, previews, display reports, and coverage audits.
     *
     * @param array<int,array<string,mixed>> $candidates Candidates.
     * @param array<int,array<string,mixed>> $evidence Evidence records.
     * @param array<int,array<string,mixed>> $previews Import previews.
     * @param array<int,array<string,mixed>> $display_reports Display reports.
     * @param array<int,array<string,mixed>> $coverage_audits Coverage audits.
     * @param array<int,array<string,mixed>> $em_location_traces Events Manager traces.
     * @return array<string,mixed>
     */
    private function summarize_all( array $candidates, array $evidence, array $previews, array $display_reports, array $coverage_audits, array $em_location_traces ) {
        $summary = array(
            'total_candidates'                    => count( $candidates ),
            'total_evidence_records'              => count( $evidence ),
            'total_import_previews'               => count( $previews ),
            'total_source_page_display_reports'   => count( $display_reports ),
            'total_source_coverage_audits'        => count( $coverage_audits ),
            'total_events_manager_location_traces' => count( $em_location_traces ),
            'events_manager_location_trace_status' => array(),
            'candidate_by_status'                 => array(),
            'candidate_by_source_type'            => array(),
            'candidate_by_fetch_method'           => array(),
            'evidence_items_total'                => 0,
            'evidence_item_labels'                => array(),
            'preview_exclusion_labels'            => array(),
            'preview_stage_room_detected'         => 0,
            'preview_timeslots_detected'          => 0,
            'display_visible_text_lines_total'    => 0,
            'display_faq_items_total'             => 0,
            'display_related_markers_total'       => 0,
            'coverage_missing_required_total'     => 0,
            'coverage_missing_optional_total'     => 0,
            'coverage_import_readiness'           => array(),
            'coverage_missing_required_sections'  => array(),
            'coverage_missing_optional_sections'  => array(),
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

        foreach ( $coverage_audits as $audit ) {
            $coverage_summary = isset( $audit['coverage_summary'] ) && is_array( $audit['coverage_summary'] ) ? $audit['coverage_summary'] : array();
            $summary['coverage_missing_required_total'] += isset( $coverage_summary['missing_required_count'] ) ? (int) $coverage_summary['missing_required_count'] : 0;
            $summary['coverage_missing_optional_total'] += isset( $coverage_summary['missing_optional_count'] ) ? (int) $coverage_summary['missing_optional_count'] : 0;

            $readiness = isset( $coverage_summary['import_readiness'] ) ? (string) $coverage_summary['import_readiness'] : '[missing]';
            $summary['coverage_import_readiness'][ $readiness ] = isset( $summary['coverage_import_readiness'][ $readiness ] ) ? $summary['coverage_import_readiness'][ $readiness ] + 1 : 1;

            $required = isset( $coverage_summary['missing_required_sections'] ) && is_array( $coverage_summary['missing_required_sections'] ) ? $coverage_summary['missing_required_sections'] : array();
            foreach ( $required as $section ) {
                if ( is_array( $section ) || is_object( $section ) ) {
                    continue;
                }
                $section = (string) $section;
                $summary['coverage_missing_required_sections'][ $section ] = isset( $summary['coverage_missing_required_sections'][ $section ] ) ? $summary['coverage_missing_required_sections'][ $section ] + 1 : 1;
            }

            $optional = isset( $coverage_summary['missing_optional_sections'] ) && is_array( $coverage_summary['missing_optional_sections'] ) ? $coverage_summary['missing_optional_sections'] : array();
            foreach ( $optional as $section ) {
                if ( is_array( $section ) || is_object( $section ) ) {
                    continue;
                }
                $section = (string) $section;
                $summary['coverage_missing_optional_sections'][ $section ] = isset( $summary['coverage_missing_optional_sections'][ $section ] ) ? $summary['coverage_missing_optional_sections'][ $section ] + 1 : 1;
            }
        }

        foreach ( $em_location_traces as $trace ) {
            $stored = isset( $trace['stored_import_trace'] ) && is_array( $trace['stored_import_trace'] ) ? $trace['stored_import_trace'] : array();
            $status = isset( $stored['status'] ) && '' !== $stored['status'] ? (string) $stored['status'] : '[missing]';
            $summary['events_manager_location_trace_status'][ $status ] = isset( $summary['events_manager_location_trace_status'][ $status ] ) ? $summary['events_manager_location_trace_status'][ $status ] + 1 : 1;
        }

        ksort( $summary['candidate_by_status'] );
        ksort( $summary['candidate_by_source_type'] );
        ksort( $summary['candidate_by_fetch_method'] );
        ksort( $summary['evidence_item_labels'] );
        ksort( $summary['preview_exclusion_labels'] );
        ksort( $summary['coverage_import_readiness'] );
        ksort( $summary['coverage_missing_required_sections'] );
        ksort( $summary['coverage_missing_optional_sections'] );
        ksort( $summary['events_manager_location_trace_status'] );

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
            return '[coordinate-redacted-storage-handoff]';
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
     * Detect raw structured coordinate fields. Raw coordinate values are never exported in review reports,
     * even when coordinate presence and storage-handoff decisions are reported.
     *
     * @param string $key Field key.
     */
    private function is_coordinate_key( $key ) {
        $key = strtolower( (string) $key );

        if ( preg_match( '/(?:latitude|longitude)_(?:present|complete)$/', $key ) ) {
            return false;
        }

        if ( in_array( $key, array( 'latitude', 'longitude', 'lat', 'lng', 'lon', 'long' ), true ) ) {
            return true;
        }

        return false !== strpos( $key, 'latitude' ) || false !== strpos( $key, 'longitude' );
    }
}
