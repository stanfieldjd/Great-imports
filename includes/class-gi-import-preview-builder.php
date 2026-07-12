<?php
/**
 * Builds review-only import previews before any Events Manager save.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Import_Preview_Builder {
    /**
     * Build a non-saving import preview for a candidate.
     *
     * @param WP_Post $candidate Candidate post.
     * @return array<string,mixed>
     */
    public function build_for_candidate( $candidate ) {
        $post_id = isset( $candidate->ID ) ? (int) $candidate->ID : 0;
        $bundle  = $this->evidence_bundle_for_candidate( $post_id );

        $title = $this->candidate_value( $post_id, 'title', '', get_the_title( $candidate ) );
        $start = $this->split_local_datetime( $this->candidate_value( $post_id, 'start_date' ) );
        $end   = $this->split_local_datetime( $this->candidate_value( $post_id, 'end_date' ) );

        $ticket_classes = $this->ticket_classes_from_bundle( $bundle );
        $faqs           = $this->faqs_from_bundle( $bundle );
        $description    = $this->assemble_description_html( $candidate, $post_id, $start, $end, $ticket_classes, $faqs );
        $stage_room     = $this->candidate_value( $post_id, 'stage_room' );
        $location       = GI_Candidate_Review::normalized_location_fields( $post_id );

        return array(
            'public_event_fields' => array(
                'title'    => $title,
                'start'    => $start,
                'end'      => $end,
                'timezone' => $this->candidate_value( $post_id, 'timezone' ),
                'status'   => __( 'Preview only — no Events Manager event will be saved from this screen.', 'great-imports' ),
            ),
            'time_handling'       => array(
                'overall_window' => $this->overall_window_label( $start, $end ),
                'set_times'      => array(),
                'em_timeslots'   => array(),
                'note'           => __( 'Overall event time is available. No separate source-backed set times or Events Manager timeslots have been extracted for this candidate.', 'great-imports' ),
            ),
            'location_fields'     => array(
                'location_name'     => $location['name'],
                'location_address'  => $location['address_1'],
                'location_address2' => $location['address_2'],
                'location_town'     => $location['city'],
                'location_state'    => $location['state'],
                'location_postcode' => $location['postcode'],
                'location_country'  => $location['country'],
                'stage_room'        => $stage_room,
                'handoff_note'      => __( 'Great Imports prepares reviewed Events Manager location fields for the storage handoff when an import step is approved.', 'great-imports' ),
            ),
            'reviewer_decisions'  => $this->reviewer_decisions( $post_id ),
            'images'              => array(
                'primary_image_url' => $this->candidate_value( $post_id, 'image_url' ),
                'planned_action'    => __( 'Actual event image should be downloaded into the WordPress Media Library and assigned as the featured image when import is later approved.', 'great-imports' ),
                'excluded'          => array(
                    __( 'tracking pixels', 'great-imports' ),
                    __( 'UI icons', 'great-imports' ),
                    __( 'CSS/JS assets', 'great-imports' ),
                    __( 'unrelated page graphics', 'great-imports' ),
                ),
            ),
            'description_html'    => $description,
            'ticketing'           => array(
                'ticket_url'     => $this->candidate_value( $post_id, 'ticket_url' ),
                'price'          => $this->candidate_value( $post_id, 'price' ),
                'currency'       => $this->candidate_value( $post_id, 'price_currency' ),
                'ticket_classes' => $ticket_classes,
                'public_rule'    => __( 'Eventbrite may appear publicly only as the purchase-ticket URL.', 'great-imports' ),
            ),
            'events_manager_payload' => $this->events_manager_payload( $post_id, $title, $start, $end, $location, $description ),
            'stage_handling'      => array(
                'stage_room' => $stage_room,
                'note'       => __( 'Multiple stages/rooms at the same address are valid evidence and are not a rejection reason. Stage/room evidence belongs in details unless review chooses otherwise.', 'great-imports' ),
            ),
            'related_events'      => array(
                'items' => array(),
                'note'  => __( 'Related-event cards are only included when captured as structured event-card evidence. No structured related-event cards are available for this candidate yet.', 'great-imports' ),
            ),
            'internal_tracking'   => array(
                'source_type'             => $this->meta( $post_id, 'source_type' ),
                'source_url'              => $this->meta( $post_id, 'source_url' ),
                'submitted_url'           => $this->meta( $post_id, 'submitted_url' ),
                'eventbrite_event_id'     => $this->meta( $post_id, 'eventbrite_event_id' ),
                'evidence_bundle_id'      => $this->meta( $post_id, 'evidence_bundle_id' ),
                'evidence_capture_run_id' => $this->meta( $post_id, 'evidence_capture_run_id' ),
                'fetch_method'            => $this->meta( $post_id, 'fetch_method' ),
                'reviewed_at'             => $this->review_meta( $post_id, 'reviewed_at' ),
                'reviewed_by_user_id'     => $this->review_meta( $post_id, 'reviewed_by' ),
            ),
            'excluded_public_data' => array(
                __( 'latitude/longitude', 'great-imports' ),
                __( 'manual Events Manager location ID assignment unless reviewer selected an existing EM location for later import', 'great-imports' ),
                __( 'raw scripts', 'great-imports' ),
                __( 'raw cookies/headers', 'great-imports' ),
                __( 'Eventbrite source attribution except ticket purchase URL', 'great-imports' ),
                __( 'Eventbrite API IDs in public content', 'great-imports' ),
            ),
        );
    }

    private function assemble_description_html( $candidate, $post_id, array $start, array $end, array $ticket_classes, array $faqs ) {
        $html     = '';
        $overview = trim( (string) $candidate->post_content );

        if ( '' !== $overview ) {
            $html .= '<h2>' . esc_html__( 'Overview', 'great-imports' ) . '</h2>';
            $html .= wp_kses_post( $overview );
        }

        $good_to_know = array();
        $duration     = $this->duration_label( $start, $end );
        if ( '' !== $duration ) {
            $good_to_know[] = sprintf( __( 'Duration: %s', 'great-imports' ), $duration );
        }

        if ( '' !== $this->candidate_value( $post_id, 'location_name' ) ) {
            $good_to_know[] = __( 'In person', 'great-imports' );
        }

        if ( ! empty( $good_to_know ) ) {
            $html .= '<h2>' . esc_html__( 'Good to know', 'great-imports' ) . '</h2><ul>';
            foreach ( $good_to_know as $item ) {
                $html .= '<li>' . esc_html( $item ) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= $this->ticket_section_html( $post_id, $ticket_classes );
        $html .= $this->organizer_section_html( $post_id );
        $html .= $this->venue_section_html( $post_id );
        $html .= $this->faq_section_html( $faqs );

        return $html;
    }

    private function ticket_section_html( $post_id, array $ticket_classes ) {
        $ticket_url = $this->candidate_value( $post_id, 'ticket_url' );
        $price      = $this->candidate_value( $post_id, 'price' );
        $currency   = $this->candidate_value( $post_id, 'price_currency' );

        if ( '' === $ticket_url && '' === $price && empty( $ticket_classes ) ) {
            return '';
        }

        $html = '<h2>' . esc_html__( 'Tickets', 'great-imports' ) . '</h2>';

        if ( ! empty( $ticket_classes ) ) {
            $html .= '<ul>';
            foreach ( $ticket_classes as $ticket_class ) {
                $label = isset( $ticket_class['name'] ) && '' !== $ticket_class['name'] ? $ticket_class['name'] : __( 'Ticket', 'great-imports' );
                $cost  = isset( $ticket_class['cost'] ) ? $ticket_class['cost'] : '';
                $html .= '<li>' . esc_html( trim( $label . ( '' !== $cost ? ' — ' . $cost : '' ) ) ) . '</li>';
            }
            $html .= '</ul>';
        } elseif ( '' !== $price ) {
            $html .= '<p>' . esc_html( trim( $price . ' ' . $currency ) ) . '</p>';
        }

        if ( '' !== $ticket_url ) {
            $html .= '<p><a href="' . esc_url( $ticket_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Purchase tickets', 'great-imports' ) . '</a></p>';
        }

        return $html;
    }

    private function organizer_section_html( $post_id ) {
        $organizer = $this->meta( $post_id, 'organizer_name' );

        if ( '' === $organizer ) {
            return '';
        }

        return '<h2>' . esc_html__( 'Organizer', 'great-imports' ) . '</h2><p>' . esc_html( $organizer ) . '</p>';
    }

    private function venue_section_html( $post_id ) {
        $location = GI_Candidate_Review::normalized_location_fields( $post_id );
        $name     = $location['name'];
        $address  = array_filter(
            array(
                $location['address_1'],
                $location['address_2'],
                trim( $location['city'] . ', ' . $location['state'] . ' ' . $location['postcode'] ),
                $location['country'],
            )
        );
        $stage   = $this->candidate_value( $post_id, 'stage_room' );

        if ( '' === $name && empty( $address ) && '' === $stage ) {
            return '';
        }

        $html = '<h2>' . esc_html__( 'Location', 'great-imports' ) . '</h2>';

        if ( '' !== $name ) {
            $html .= '<p><strong>' . esc_html( $name ) . '</strong></p>';
        }

        if ( '' !== $stage ) {
            $html .= '<p>' . esc_html__( 'Stage / Room:', 'great-imports' ) . ' ' . esc_html( $stage ) . '</p>';
        }

        if ( ! empty( $address ) ) {
            $html .= '<p>' . implode( '<br>', array_map( 'esc_html', $address ) ) . '</p>';
        }

        return $html;
    }

    private function faq_section_html( array $faqs ) {
        if ( empty( $faqs ) ) {
            return '';
        }

        $html = '<h2>' . esc_html__( 'Frequently asked questions', 'great-imports' ) . '</h2>';

        foreach ( $faqs as $faq ) {
            $question = isset( $faq['question'] ) ? $faq['question'] : '';
            $answer   = isset( $faq['answer'] ) ? $faq['answer'] : '';

            if ( '' === $question || '' === $answer ) {
                continue;
            }

            $html .= '<details><summary>' . esc_html( $question ) . '</summary><p>' . esc_html( $answer ) . '</p></details>';
        }

        return $html;
    }

    private function reviewer_decisions( $post_id ) {
        $review_status        = $this->candidate_value( $post_id, 'review_status', 'candidate_status', 'needs_review' );
        $location_decision    = $this->review_meta( $post_id, 'location_decision' );
        $address_verification = $this->review_meta( $post_id, 'address_verification' );
        $em_location_id       = $this->review_meta( $post_id, 'em_location_id' );

        return array(
            'review_status'              => $review_status,
            'review_status_label'        => $this->option_label( GI_Candidate_Review::review_status_options(), $review_status ),
            'location_decision'          => $location_decision,
            'location_decision_label'    => $this->option_label( GI_Candidate_Review::location_decision_options(), $location_decision ),
            'address_verification'       => $address_verification,
            'address_verification_label' => $this->option_label( GI_Candidate_Review::address_verification_options(), $address_verification ),
            'em_location_id'             => $em_location_id,
            'reviewer_notes'             => $this->review_meta( $post_id, 'reviewer_notes' ),
            'source_location_fields'     => array(
                'location_name'     => $this->meta( $post_id, 'location_name' ),
                'location_address'  => $this->meta( $post_id, 'location_address_1' ),
                'location_town'     => $this->meta( $post_id, 'location_city' ),
                'location_state'    => $this->meta( $post_id, 'location_state' ),
                'location_postcode' => $this->meta( $post_id, 'location_postal_code' ),
            ),
            'reviewed_location_fields'   => array(
                'location_name'     => $this->candidate_value( $post_id, 'location_name' ),
                'location_address'  => $this->candidate_value( $post_id, 'location_address_1' ),
                'location_town'     => $this->candidate_value( $post_id, 'location_city' ),
                'location_state'    => $this->candidate_value( $post_id, 'location_state' ),
                'location_postcode' => $this->candidate_value( $post_id, 'location_postal_code' ),
            ),
            'note'                       => __( 'Reviewer overrides affect the preview only. Raw source evidence remains unchanged.', 'great-imports' ),
        );
    }

    private function option_label( array $options, $key ) {
        $key = sanitize_key( (string) $key );

        return isset( $options[ $key ] ) ? $options[ $key ] : $key;
    }

    private function ticket_classes_from_bundle( array $bundle ) {
        $ticket_classes = array();
        $items          = isset( $bundle['items'] ) && is_array( $bundle['items'] ) ? $bundle['items'] : array();
        $payload        = isset( $items['eventbrite_api_ticket_classes']['payload'] ) && is_array( $items['eventbrite_api_ticket_classes']['payload'] ) ? $items['eventbrite_api_ticket_classes']['payload'] : array();
        $classes        = isset( $payload['ticket_classes'] ) && is_array( $payload['ticket_classes'] ) ? $payload['ticket_classes'] : array();

        foreach ( $classes as $ticket_class ) {
            if ( ! is_array( $ticket_class ) ) {
                continue;
            }

            $name = isset( $ticket_class['display_name'] ) ? sanitize_text_field( (string) $ticket_class['display_name'] ) : '';
            if ( '' === $name && isset( $ticket_class['name'] ) ) {
                $name = sanitize_text_field( (string) $ticket_class['name'] );
            }

            $cost = '';
            if ( isset( $ticket_class['cost']['display'] ) ) {
                $cost = sanitize_text_field( (string) $ticket_class['cost']['display'] );
            }

            $ticket_classes[] = array(
                'name' => $name,
                'cost' => $cost,
            );
        }

        return $ticket_classes;
    }

    private function faqs_from_bundle( array $bundle ) {
        $faqs   = array();
        $items  = isset( $bundle['items'] ) && is_array( $bundle['items'] ) ? $bundle['items'] : array();
        $blocks = isset( $items['public_event_page_html_extracted_evidence']['json_ld_blocks'] ) && is_array( $items['public_event_page_html_extracted_evidence']['json_ld_blocks'] ) ? $items['public_event_page_html_extracted_evidence']['json_ld_blocks'] : array();

        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) || empty( $block['decoded'] ) || ! is_array( $block['decoded'] ) ) {
                continue;
            }
            $this->collect_faqs_from_jsonld_node( $block['decoded'], $faqs );
        }

        return $faqs;
    }

    private function collect_faqs_from_jsonld_node( $node, array &$faqs ) {
        if ( ! is_array( $node ) ) {
            return;
        }

        $type = isset( $node['@type'] ) ? $node['@type'] : '';
        if ( is_array( $type ) ) {
            $type = implode( ',', array_map( 'strval', $type ) );
        }

        if ( false !== stripos( (string) $type, 'FAQPage' ) && ! empty( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ) {
            foreach ( $node['mainEntity'] as $question_node ) {
                if ( ! is_array( $question_node ) ) {
                    continue;
                }

                $question = isset( $question_node['name'] ) ? sanitize_text_field( (string) $question_node['name'] ) : '';
                $answer   = '';

                if ( isset( $question_node['acceptedAnswer'] ) && is_array( $question_node['acceptedAnswer'] ) ) {
                    if ( isset( $question_node['acceptedAnswer']['text'] ) ) {
                        $answer = sanitize_text_field( wp_strip_all_tags( (string) $question_node['acceptedAnswer']['text'] ) );
                    }
                }

                if ( '' !== $question && '' !== $answer ) {
                    $faqs[] = array(
                        'question' => $question,
                        'answer'   => $answer,
                    );
                }
            }
        }

        foreach ( $node as $child ) {
            if ( is_array( $child ) ) {
                $this->collect_faqs_from_jsonld_node( $child, $faqs );
            }
        }
    }

    private function evidence_bundle_for_candidate( $candidate_id ) {
        $evidence_id = absint( get_post_meta( $candidate_id, '_gi_evidence_bundle_id', true ) );
        if ( ! $evidence_id ) {
            return array();
        }

        $bundle = get_post_meta( $evidence_id, '_gi_evidence_bundle', true );
        return is_array( $bundle ) ? $bundle : array();
    }

    private function candidate_value( $post_id, $key, $source_key = '', $default = '' ) {
        if ( class_exists( 'GI_Candidate_Review' ) ) {
            return GI_Candidate_Review::value( $post_id, $key, $source_key, $default );
        }

        $fallback_key = '' !== $source_key ? $source_key : $key;
        $value        = $this->meta( $post_id, $fallback_key );

        return '' !== $value ? $value : sanitize_text_field( (string) $default );
    }

    private function review_meta( $post_id, $key ) {
        $value = get_post_meta( $post_id, '_gi_review_' . sanitize_key( $key ), true );

        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        return sanitize_text_field( (string) $value );
    }

    private function meta( $post_id, $key ) {
        $value = get_post_meta( $post_id, '_gi_' . sanitize_key( $key ), true );

        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        return sanitize_text_field( (string) $value );
    }

    private function events_manager_payload( $post_id, $title, array $start, array $end, array $location, $description ) {
        $selected_location_id = absint( $this->review_meta( $post_id, 'em_location_id' ) );
        $source_timezone      = $this->candidate_value( $post_id, 'timezone' );
        $timezone             = '' !== $source_timezone ? $source_timezone : wp_timezone_string();
        $timezone_provenance  = '' !== $source_timezone ? 'source' : 'wordpress_site_fallback';
        $errors               = array();
        $warnings             = array();

        if ( '' === trim( (string) $title ) ) {
            $errors[] = __( 'Event title is required.', 'great-imports' );
        }
        if ( empty( $start['date'] ) || empty( $start['time'] ) ) {
            $errors[] = __( 'Event start date and time are required.', 'great-imports' );
        }
        if ( empty( $end['date'] ) || empty( $end['time'] ) ) {
            $errors[] = __( 'Event end date and time are required.', 'great-imports' );
        }
        if ( '' === $timezone ) {
            $errors[] = __( 'Event timezone is required.', 'great-imports' );
        } elseif ( 'wordpress_site_fallback' === $timezone_provenance ) {
            $warnings[] = __( 'Source timezone was absent; the WordPress site timezone will be used.', 'great-imports' );
        }
        if ( ! $selected_location_id && '' === $location['name'] && '' === $location['address_1'] ) {
            $errors[] = __( 'A selected Events Manager location or source-backed location is required.', 'great-imports' );
        }
        return array(
            'candidate_post_id' => absint( $post_id ),
            'ready_for_save'    => empty( $errors ),
            'validation'        => array(
                'errors'   => $errors,
                'warnings' => $warnings,
            ),
            'event'             => array(
                'event_name'       => $title,
                'event_start_date' => isset( $start['date'] ) ? $start['date'] : '',
                'event_start_time' => isset( $start['time'] ) ? $start['time'] : '',
                'event_end_date'   => isset( $end['date'] ) ? $end['date'] : '',
                'event_end_time'   => isset( $end['time'] ) ? $end['time'] : '',
                'event_timezone'   => $timezone,
                'timezone_source'  => $timezone_provenance,
                'post_content'     => wp_kses_post( $description ),
                'event_status'     => 'draft_review',
            ),
            'location'          => array(
                'strategy'          => $selected_location_id ? 'existing' : 'create',
                'em_location_id'    => $selected_location_id,
                'location_name'     => $location['name'],
                'location_address'  => $location['address_1'],
                'location_address2' => $location['address_2'],
                'location_town'     => $location['city'],
                'location_state'    => $location['state'],
                'location_postcode' => $location['postcode'],
                'location_country'  => $location['country'],
            ),
            'ticketing'         => array(
                'handling'   => 'description_only',
                'ticket_url' => $this->candidate_value( $post_id, 'ticket_url' ),
                'price'      => $this->candidate_value( $post_id, 'price' ),
                'currency'   => $this->candidate_value( $post_id, 'price_currency' ),
            ),
            'source_identity'   => array(
                'source_type'         => $this->meta( $post_id, 'source_type' ),
                'source_url'          => $this->meta( $post_id, 'source_url' ),
                'eventbrite_event_id' => $this->meta( $post_id, 'eventbrite_event_id' ),
                'fingerprint'          => $this->meta( $post_id, 'fingerprint' ),
            ),
        );
    }

    private function split_local_datetime( $datetime ) {
        $datetime = trim( (string) $datetime );

        try {
            $value = '' !== $datetime ? new DateTimeImmutable( $datetime, wp_timezone() ) : null;
        } catch ( Exception $exception ) {
            $value = null;
        }

        if ( ! $value ) {
            return array(
                'raw'       => $datetime,
                'date'      => '',
                'time'      => '',
                'label'     => $datetime,
                'timestamp' => null,
            );
        }

        return array(
            'raw'       => $datetime,
            'date'      => $value->format( 'Y-m-d' ),
            'time'      => $value->format( 'g:i A' ),
            'label'     => $value->format( 'l, F j, Y g:i A' ),
            'timestamp' => $value->getTimestamp(),
        );
    }

    private function duration_label( array $start, array $end ) {
        if ( empty( $start['timestamp'] ) || empty( $end['timestamp'] ) ) {
            return '';
        }

        $seconds = max( 0, (int) $end['timestamp'] - (int) $start['timestamp'] );
        if ( ! $seconds ) {
            return '';
        }

        $hours   = floor( $seconds / HOUR_IN_SECONDS );
        $minutes = floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
        $parts   = array();

        if ( $hours ) {
            $parts[] = sprintf( _n( '%d hour', '%d hours', $hours, 'great-imports' ), $hours );
        }

        if ( $minutes ) {
            $parts[] = sprintf( _n( '%d minute', '%d minutes', $minutes, 'great-imports' ), $minutes );
        }

        return implode( ' ', $parts );
    }

    private function overall_window_label( array $start, array $end ) {
        if ( empty( $start['label'] ) && empty( $end['label'] ) ) {
            return '';
        }

        if ( ! empty( $start['date'] ) && $start['date'] === $end['date'] ) {
            return trim( $start['label'] . ' - ' . ( isset( $end['time'] ) ? $end['time'] : '' ) );
        }

        return trim( ( isset( $start['label'] ) ? $start['label'] : '' ) . ' - ' . ( isset( $end['label'] ) ? $end['label'] : '' ) );
    }
}
