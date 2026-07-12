<?php
/**
 * Audits source coverage so missing information is explicit.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Source_Coverage_Audit_Builder {
    /**
     * Build a completeness audit for one candidate.
     *
     * @param WP_Post             $candidate Candidate post.
     * @param array<string,mixed> $display_report Source page display report.
     * @param array<string,mixed> $import_preview Import preview.
     * @return array<string,mixed>
     */
    public function build_for_candidate( $candidate, array $display_report, array $import_preview ) {
        $post_id = isset( $candidate->ID ) ? (int) $candidate->ID : 0;
        $sections = array(
            'source_page_fetch' => $this->section(
                'Source page fetch',
                $this->has_path( $display_report, array( 'source_page_fetch', 'success' ) ) && ! empty( $display_report['source_page_fetch']['success'] ),
                array( 'source_page_display_reports.source_page_fetch' ),
                'Required evidence for page-derived fields. If missing, API-only import review may still be possible.'
            ),
            'visible_text' => $this->section(
                'Visible text lines',
                $this->int_path( $display_report, array( 'visible_text_report', 'line_count' ) ) > 0,
                array( 'source_page_display_reports.visible_text_report.lines' ),
                'Used to compare against browser screenshots. Initial HTML text is not the same as a fully rendered browser DOM.'
            ),
            'title' => $this->section(
                'Title',
                '' !== $this->path_string( $import_preview, array( 'public_event_fields', 'title' ) ) || $this->has_nonempty_array_path( $display_report, array( 'screenshot_visible_sections', 'title', 'html_titles' ) ),
                array( 'import_previews.public_event_fields.title', 'source_page_display_reports.screenshot_visible_sections.title' ),
                'Expected on every event.'
            ),
            'date_time' => $this->section(
                'Date and time',
                '' !== $this->path_string( $import_preview, array( 'public_event_fields', 'start', 'raw' ) ) || '' !== $this->path_string( $display_report, array( 'screenshot_visible_sections', 'date_time', 'api_start_local' ) ),
                array( 'import_previews.public_event_fields.start', 'source_page_display_reports.screenshot_visible_sections.date_time' ),
                'Expected on every event. Separate set times are optional and must be source-backed.'
            ),
            'timezone' => $this->section(
                'Timezone',
                '' !== $this->path_string( $import_preview, array( 'public_event_fields', 'timezone' ) ),
                array( 'import_previews.public_event_fields.timezone' ),
                'Needed so Events Manager receives the correct event timezone.'
            ),
            'overall_event_window' => $this->section(
                'Overall event window',
                '' !== $this->path_string( $import_preview, array( 'time_handling', 'overall_window' ) ),
                array( 'import_previews.time_handling.overall_window' ),
                'Overall start/end time is separate from performance set times and Events Manager timeslots.'
            ),
            'set_times_or_timeslots' => $this->section(
                'Set times / Events Manager timeslots',
                $this->has_nonempty_array_path( $import_preview, array( 'time_handling', 'set_times' ) ) || $this->has_nonempty_array_path( $import_preview, array( 'time_handling', 'em_timeslots' ) ),
                array( 'import_previews.time_handling' ),
                'Optional. Missing set times do not block import. Great Imports should not invent band times or EM timeslots.'
            ),
            'venue_name' => $this->section(
                'Venue name',
                '' !== $this->path_string( $import_preview, array( 'location_fields', 'location_name' ) ),
                array( 'import_previews.location_fields.location_name', 'source_page_display_reports.screenshot_visible_sections.location' ),
                'Required for a clean Events Manager location handoff.'
            ),
            'venue_address' => $this->section(
                'Venue address',
                '' !== $this->path_string( $import_preview, array( 'location_fields', 'location_address' ) ) || '' !== $this->path_string( $import_preview, array( 'location_fields', 'location_town' ) ),
                array( 'import_previews.location_fields', 'source_page_display_reports.screenshot_visible_sections.location' ),
                'Do not reject partial addresses. Missing pieces should be visible for correction.'
            ),
            'stage_or_room' => $this->section(
                'Stage / room / area',
                '' !== $this->path_string( $import_preview, array( 'stage_handling', 'stage_room' ) ),
                array( 'import_previews.stage_handling', 'source_page_display_reports.screenshot_visible_sections.location' ),
                'Optional. Multiple stages at the same address are valid evidence and must not reject the location.'
            ),
            'overview_description' => $this->section(
                'Overview / description',
                '' !== $this->path_string( $import_preview, array( 'description_html_preview' ) ) || '' !== $this->path_string( $display_report, array( 'screenshot_visible_sections', 'overview', 'plain_text' ) ),
                array( 'import_previews.description_html_preview', 'source_page_display_reports.screenshot_visible_sections.overview' ),
                'Expected. This should include the long details, not only the short summary.'
            ),
            'good_to_know' => $this->section(
                'Good to know',
                $this->has_nonempty_array_path( $display_report, array( 'screenshot_visible_sections', 'good_to_know', 'line_matches' ) ),
                array( 'source_page_display_reports.screenshot_visible_sections.good_to_know' ),
                'Optional but should be reported when visible in screenshots/source evidence.'
            ),
            'faq' => $this->section(
                'FAQ / questions',
                $this->int_path( $display_report, array( 'screenshot_visible_sections', 'faq', 'count' ) ) > 0 || false !== strpos( strtolower( $this->path_string( $import_preview, array( 'description_html_preview' ) ) ), '<details' ),
                array( 'source_page_display_reports.screenshot_visible_sections.faq', 'import_previews.description_html_preview' ),
                'FAQ should be preserved as dropdown/details content when found.'
            ),
            'ticketing' => $this->section(
                'Ticketing and price',
                '' !== $this->path_string( $import_preview, array( 'ticketing', 'ticket_url' ) ) || '' !== $this->path_string( $import_preview, array( 'ticketing', 'price' ) ) || $this->has_nonempty_array_path( $import_preview, array( 'ticketing', 'ticket_classes' ) ),
                array( 'import_previews.ticketing', 'source_page_display_reports.screenshot_visible_sections.ticketing' ),
                'Eventbrite may appear publicly only as the ticket purchase URL.'
            ),
            'organizer' => $this->section(
                'Organizer',
                $this->has_nonempty_path( $display_report, array( 'screenshot_visible_sections', 'organizer', 'candidate_organizer_name' ) ) || $this->has_nonempty_path( $display_report, array( 'screenshot_visible_sections', 'organizer', 'api_organizer_name' ) ),
                array( 'source_page_display_reports.screenshot_visible_sections.organizer' ),
                'Organizer details should be reported, with account-specific context excluded.'
            ),
            'images' => $this->section(
                'Images',
                '' !== $this->path_string( $import_preview, array( 'images', 'primary_image_url' ) ) || $this->has_nonempty_array_path( $display_report, array( 'screenshot_visible_sections', 'images', 'html_image_urls' ) ),
                array( 'import_previews.images', 'source_page_display_reports.screenshot_visible_sections.images' ),
                'Actual event images should be tracked for later Media Library import. UI icons/pixels are not event images.'
            ),
            'related_cards' => $this->section(
                'Related / more events cards',
                $this->has_nonempty_array_path( $display_report, array( 'screenshot_visible_sections', 'related', 'structured_cards_found' ) ),
                array( 'source_page_display_reports.screenshot_visible_sections.related' ),
                'Optional. Section markers are not enough; related event cards need structured card evidence before reliable import/display.'
            ),
            'internal_tracking' => $this->section(
                'Internal source tracking',
                '' !== $this->path_string( $import_preview, array( 'internal_tracking', 'source_url' ) ) || '' !== $this->path_string( $import_preview, array( 'internal_tracking', 'eventbrite_event_id' ) ),
                array( 'import_previews.internal_tracking' ),
                'Required internally for duplicate/update evidence. Not public output except allowed ticket purchase URL.'
            ),
            'excluded_public_fields' => $this->section(
                'Excluded public fields',
                $this->has_nonempty_array_path( $import_preview, array( 'excluded_public_data' ) ),
                array( 'import_previews.excluded_public_data' ),
                'Report must show excluded or redacted fields such as raw latitude/longitude values, raw scripts, raw headers, and public source attribution.'
            ),
        );

        $missing_required = array();
        $missing_optional = array();
        $captured         = array();

        foreach ( $sections as $key => $section ) {
            if ( ! empty( $section['captured'] ) ) {
                $captured[] = $key;
                continue;
            }

            if ( ! empty( $section['required'] ) ) {
                $missing_required[] = $key;
            } else {
                $missing_optional[] = $key;
            }
        }

        return array(
            'candidate_post_id'   => $post_id,
            'candidate_title'     => get_the_title( $candidate ),
            'coverage_summary'    => array(
                'captured_count'           => count( $captured ),
                'missing_required_count'   => count( $missing_required ),
                'missing_optional_count'   => count( $missing_optional ),
                'captured_sections'        => $captured,
                'missing_required_sections'=> $missing_required,
                'missing_optional_sections'=> $missing_optional,
                'import_readiness'         => empty( $missing_required ) ? 'review_ready' : 'needs_missing_required_review',
                'note'                     => 'Missing optional sections do not block review. Missing required sections should stay visible and be corrected or accepted as partial before import.',
            ),
            'sections'            => $sections,
            'browser_gap_summary' => $this->browser_gap_summary( $display_report ),
            'anti_silent_drop_rule'=> 'No source information should disappear silently. Captured-but-unused, missing, optional, excluded, and browser-rendered-only fields must be reported separately.',
        );
    }

    /**
     * Build a section row.
     */
    private function section( $label, $captured, array $evidence_paths, $note, $required = false ) {
        return array(
            'label'          => sanitize_text_field( (string) $label ),
            'captured'       => (bool) $captured,
            'required'       => (bool) $required,
            'evidence_paths' => array_values( array_map( 'sanitize_text_field', array_map( 'strval', $evidence_paths ) ) ),
            'note'           => sanitize_text_field( (string) $note ),
        );
    }

    private function browser_gap_summary( array $display_report ) {
        $gaps = isset( $display_report['browser_rendering_gaps'] ) && is_array( $display_report['browser_rendering_gaps'] ) ? $display_report['browser_rendering_gaps'] : array();
        return array(
            'javascript_executed'        => ! empty( $gaps['javascript_executed'] ),
            'browser_dom_captured'       => ! empty( $gaps['browser_dom_captured'] ),
            'related_cards_structured'   => ! empty( $gaps['related_cards_structured'] ),
            'css_js_file_contents_saved' => ! empty( $gaps['css_js_file_contents_saved'] ),
            'image_binaries_saved'       => ! empty( $gaps['image_binaries_saved'] ),
            'note'                       => isset( $gaps['note'] ) ? sanitize_text_field( (string) $gaps['note'] ) : 'Browser-only content should be called out as a capture gap, not treated as absent from the real page.',
        );
    }

    private function has_nonempty_path( array $data, array $path ) {
        return '' !== $this->path_string( $data, $path );
    }

    private function has_nonempty_array_path( array $data, array $path ) {
        $value = $this->path_value( $data, $path );
        return is_array( $value ) && ! empty( $value );
    }

    private function has_path( array $data, array $path ) {
        $found = true;
        $this->path_value( $data, $path, $found );
        return $found;
    }

    private function int_path( array $data, array $path ) {
        $value = $this->path_value( $data, $path );
        return is_numeric( $value ) ? (int) $value : 0;
    }

    private function path_string( array $data, array $path ) {
        $value = $this->path_value( $data, $path );
        if ( is_array( $value ) || is_object( $value ) || null === $value ) {
            return '';
        }
        return trim( (string) $value );
    }

    private function path_value( array $data, array $path, &$found = null ) {
        $value = $data;
        foreach ( $path as $key ) {
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
                $found = false;
                return null;
            }
            $value = $value[ $key ];
        }
        $found = true;
        return $value;
    }
}
