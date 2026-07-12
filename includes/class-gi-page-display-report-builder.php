<?php
/**
 * Builds report sections for source-page display evidence.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Page_Display_Report_Builder {
    /**
     * Build a screenshot-style source page report for a candidate.
     *
     * @param WP_Post $candidate Candidate post.
     * @return array<string,mixed>
     */
    public function build_for_candidate( $candidate ) {
        $post_id = isset( $candidate->ID ) ? (int) $candidate->ID : 0;
        $bundle  = $this->evidence_bundle_for_candidate( $post_id );
        $items   = isset( $bundle['items'] ) && is_array( $bundle['items'] ) ? $bundle['items'] : array();

        $public_page   = isset( $items['public_event_page'] ) && is_array( $items['public_event_page'] ) ? $items['public_event_page'] : array();
        $html_evidence = isset( $items['public_event_page_html_extracted_evidence'] ) && is_array( $items['public_event_page_html_extracted_evidence'] ) ? $items['public_event_page_html_extracted_evidence'] : array();
        $event_detail  = isset( $items['eventbrite_api_event_detail']['payload'] ) && is_array( $items['eventbrite_api_event_detail']['payload'] ) ? $items['eventbrite_api_event_detail']['payload'] : array();
        $description   = isset( $items['eventbrite_api_event_description']['payload'] ) && is_array( $items['eventbrite_api_event_description']['payload'] ) ? $items['eventbrite_api_event_description']['payload'] : array();
        $organizer     = isset( $items['eventbrite_api_organizer']['payload'] ) && is_array( $items['eventbrite_api_organizer']['payload'] ) ? $items['eventbrite_api_organizer']['payload'] : array();
        $venue         = isset( $items['eventbrite_api_venue']['payload'] ) && is_array( $items['eventbrite_api_venue']['payload'] ) ? $items['eventbrite_api_venue']['payload'] : array();
        $ticket_payload= isset( $items['eventbrite_api_ticket_classes']['payload'] ) && is_array( $items['eventbrite_api_ticket_classes']['payload'] ) ? $items['eventbrite_api_ticket_classes']['payload'] : array();

        $body               = isset( $public_page['body'] ) ? (string) $public_page['body'] : '';
        $visible_text_lines = ! empty( $html_evidence['visible_text_lines'] ) && is_array( $html_evidence['visible_text_lines'] ) ? $html_evidence['visible_text_lines'] : $this->visible_text_lines_from_html( $body );
        $faqs               = $this->faqs_from_jsonld( $html_evidence );
        $related_markers    = $this->related_markers_from_lines( $visible_text_lines );

        return array(
            'candidate_post_id' => $post_id,
            'source_page_fetch' => array(
                'url'          => isset( $public_page['url'] ) ? esc_url_raw( (string) $public_page['url'] ) : '',
                'success'      => ! empty( $public_page['success'] ),
                'status'       => isset( $public_page['status'] ) ? (int) $public_page['status'] : 0,
                'content_type' => isset( $public_page['content_type'] ) ? sanitize_text_field( (string) $public_page['content_type'] ) : '',
                'body_bytes'   => isset( $public_page['body_bytes'] ) ? (int) $public_page['body_bytes'] : 0,
                'body_sha256'  => isset( $public_page['body_sha256'] ) ? sanitize_text_field( (string) $public_page['body_sha256'] ) : '',
            ),
            'screenshot_visible_sections' => array(
                'title'        => $this->title_report( $candidate, $html_evidence, $event_detail ),
                'date_time'    => $this->date_time_report( $post_id, $event_detail ),
                'ticketing'    => $this->ticketing_report( $post_id, $event_detail, $ticket_payload ),
                'overview'     => $this->overview_report( $candidate, $description, $visible_text_lines ),
                'good_to_know' => $this->good_to_know_report( $post_id, $visible_text_lines ),
                'location'     => $this->location_report( $post_id, $venue, $visible_text_lines ),
                'organizer'    => $this->organizer_report( $post_id, $organizer, $visible_text_lines ),
                'faq'          => array(
                    'items'  => $faqs,
                    'count'  => count( $faqs ),
                    'source' => ! empty( $faqs ) ? 'FAQPage JSON-LD / captured source page' : 'not found',
                ),
                'images'       => $this->images_report( $post_id, $html_evidence, $event_detail, $organizer ),
                'related'      => array(
                    'section_markers_found'   => $related_markers,
                    'structured_cards_found'  => array(),
                    'capture_note'            => 'Section headings/text markers are reported when present. Browser-rendered related-event cards require structured card evidence before they can be listed reliably.',
                ),
            ),
            'visible_text_report' => array(
                'line_count' => count( $visible_text_lines ),
                'lines'      => $visible_text_lines,
                'note'       => 'These lines are extracted from the captured initial HTML. They are not a browser-executed DOM capture.',
            ),
            'browser_rendering_gaps' => array(
                'javascript_executed'        => false,
                'browser_dom_captured'       => false,
                'related_cards_structured'   => false,
                'css_js_file_contents_saved' => false,
                'image_binaries_saved'       => false,
                'note'                       => 'Anything shown only after browser JavaScript rendering may appear as raw HTML/script evidence or may require a later browser-rendered capture layer.',
            ),
        );
    }

    private function title_report( $candidate, array $html_evidence, array $event_detail ) {
        return array(
            'candidate_title' => get_the_title( $candidate ),
            'api_title'       => $this->nested_string( $event_detail, array( 'name', 'text' ) ),
            'html_titles'     => isset( $html_evidence['title_tags'] ) && is_array( $html_evidence['title_tags'] ) ? array_values( $html_evidence['title_tags'] ) : array(),
            'meta_titles'     => $this->meta_contents_by_names( $html_evidence, array( 'og:title', 'twitter:title' ) ),
        );
    }

    private function date_time_report( $post_id, array $event_detail ) {
        return array(
            'candidate_start' => $this->meta( $post_id, 'start_date' ),
            'candidate_end'   => $this->meta( $post_id, 'end_date' ),
            'timezone'        => $this->meta( $post_id, 'timezone' ),
            'api_start_local' => $this->nested_string( $event_detail, array( 'start', 'local' ) ),
            'api_end_local'   => $this->nested_string( $event_detail, array( 'end', 'local' ) ),
        );
    }

    private function ticketing_report( $post_id, array $event_detail, array $ticket_payload ) {
        return array(
            'ticket_url'      => $this->meta( $post_id, 'ticket_url' ),
            'price'           => $this->meta( $post_id, 'price' ),
            'currency'        => $this->meta( $post_id, 'price_currency' ),
            'availability'    => isset( $event_detail['ticket_availability'] ) && is_array( $event_detail['ticket_availability'] ) ? $event_detail['ticket_availability'] : array(),
            'ticket_classes'  => $this->ticket_classes( $ticket_payload ),
            'public_note'     => 'Eventbrite may appear publicly only as the purchase-ticket URL.',
        );
    }

    private function overview_report( $candidate, array $description, array $visible_text_lines ) {
        $html = isset( $description['description'] ) ? (string) $description['description'] : (string) $candidate->post_content;

        return array(
            'html'         => wp_kses_post( $html ),
            'plain_text'   => $this->plain_text( $html ),
            'line_matches' => $this->lines_containing_any( $visible_text_lines, array( 'overview', 'about this event', 'present', 'support', 'live at' ), 30 ),
        );
    }

    private function good_to_know_report( $post_id, array $visible_text_lines ) {
        return array(
            'duration_guess' => $this->duration_guess( $this->meta( $post_id, 'start_date' ), $this->meta( $post_id, 'end_date' ) ),
            'line_matches'   => $this->lines_containing_any( $visible_text_lines, array( 'good to know', 'hours', 'in person', 'refund policy', 'no refunds', 'age', 'parking' ), 40 ),
        );
    }

    private function location_report( $post_id, array $venue, array $visible_text_lines ) {
        return array(
            'candidate_location_name' => $this->meta( $post_id, 'location_name' ),
            'candidate_address'       => array(
                'address_1' => $this->meta( $post_id, 'location_address_1' ),
                'address_2' => $this->meta( $post_id, 'location_address_2' ),
                'city'      => $this->meta( $post_id, 'location_city' ),
                'state'     => $this->meta( $post_id, 'location_state' ),
                'postcode'  => $this->meta( $post_id, 'location_postal_code' ),
                'country'   => $this->meta( $post_id, 'location_country' ),
            ),
            'api_venue_name'          => isset( $venue['name'] ) ? sanitize_text_field( (string) $venue['name'] ) : '',
            'line_matches'            => $this->lines_containing_any( $visible_text_lines, array( 'location', 'venue', 'station street', 'directions', 'driving', 'walking', 'public transport', 'biking' ), 40 ),
            'coordinate_rule'         => 'Latitude/longitude are redacted from reports and are not handed to Events Manager; Events Manager owns geocoding/map behavior from the saved address.',
        );
    }

    private function organizer_report( $post_id, array $organizer, array $visible_text_lines ) {
        return array(
            'candidate_organizer_name' => $this->meta( $post_id, 'organizer_name' ),
            'api_organizer_name'       => isset( $organizer['name'] ) ? sanitize_text_field( (string) $organizer['name'] ) : '',
            'website'                  => isset( $organizer['website'] ) ? esc_url_raw( (string) $organizer['website'] ) : '',
            'followers'                => isset( $organizer['num_followers'] ) ? (int) $organizer['num_followers'] : null,
            'events_count'             => null,
            'years_hosting'            => null,
            'total_attendees'          => null,
            'account_context_excluded' => array( 'followed_by_you', 'followed_at' ),
            'line_matches'             => $this->lines_containing_any( $visible_text_lines, array( 'organized by', 'organizer', 'followers', 'events', 'hosting', 'contact', 'follow' ), 40 ),
        );
    }

    private function images_report( $post_id, array $html_evidence, array $event_detail, array $organizer ) {
        return array(
            'candidate_primary_image_url' => $this->meta( $post_id, 'image_url' ),
            'api_logo_url'                => $this->nested_string( $event_detail, array( 'logo', 'original', 'url' ) ),
            'organizer_logo_url'          => $this->nested_string( $organizer, array( 'logo', 'original', 'url' ) ),
            'html_image_urls'             => isset( $html_evidence['images'] ) && is_array( $html_evidence['images'] ) ? array_values( $html_evidence['images'] ) : array(),
            'planned_action'              => 'Actual event images should be imported into the WordPress Media Library when import is later approved. UI icons, pixels, and unrelated graphics are excluded.',
        );
    }

    private function ticket_classes( array $ticket_payload ) {
        $items   = array();
        $classes = isset( $ticket_payload['ticket_classes'] ) && is_array( $ticket_payload['ticket_classes'] ) ? $ticket_payload['ticket_classes'] : array();

        foreach ( $classes as $ticket_class ) {
            if ( ! is_array( $ticket_class ) ) {
                continue;
            }

            $items[] = array(
                'name'          => isset( $ticket_class['display_name'] ) ? sanitize_text_field( (string) $ticket_class['display_name'] ) : ( isset( $ticket_class['name'] ) ? sanitize_text_field( (string) $ticket_class['name'] ) : '' ),
                'cost_display'  => $this->nested_string( $ticket_class, array( 'cost', 'display' ) ),
                'fee_display'   => $this->nested_string( $ticket_class, array( 'fee', 'display' ) ),
                'tax_display'   => $this->nested_string( $ticket_class, array( 'tax', 'display' ) ),
                'sales_status'  => isset( $ticket_class['on_sale_status'] ) ? sanitize_text_field( (string) $ticket_class['on_sale_status'] ) : '',
            );
        }

        return $items;
    }

    private function faqs_from_jsonld( array $html_evidence ) {
        $faqs   = array();
        $blocks = isset( $html_evidence['json_ld_blocks'] ) && is_array( $html_evidence['json_ld_blocks'] ) ? $html_evidence['json_ld_blocks'] : array();

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

                if ( isset( $question_node['acceptedAnswer'] ) && is_array( $question_node['acceptedAnswer'] ) && isset( $question_node['acceptedAnswer']['text'] ) ) {
                    $answer = sanitize_text_field( wp_strip_all_tags( (string) $question_node['acceptedAnswer']['text'] ) );
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

    private function related_markers_from_lines( array $lines ) {
        return $this->lines_containing_any( $lines, array( 'more events from', 'you might also like', 'related events', 'similar events' ), 20 );
    }

    private function visible_text_lines_from_html( $html ) {
        $html = (string) $html;
        $html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', $html );
        $html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $html );
        $html = preg_replace( '/<svg\b[^>]*>.*?<\/svg>/is', ' ', $html );
        $html = preg_replace( '/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html );
        $html = preg_replace( '/<(br|p|div|section|article|header|footer|li|h[1-6]|tr|td|th)\b[^>]*>/i', "\n", $html );
        $text = wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5 ) );
        $text = preg_replace( "/[ \t\r\0\x0B]+/", ' ', $text );
        $raw  = preg_split( "/\n+|\s{3,}/", (string) $text );

        $lines = array();
        foreach ( $raw as $line ) {
            $line = trim( preg_replace( '/\s+/', ' ', (string) $line ) );
            if ( '' === $line || strlen( $line ) < 2 ) {
                continue;
            }
            $lines[] = sanitize_text_field( $line );
        }

        return array_values( array_slice( array_unique( $lines ), 0, 500 ) );
    }

    private function lines_containing_any( array $lines, array $needles, $limit = 25 ) {
        $matches = array();
        foreach ( $lines as $line ) {
            $lower = strtolower( (string) $line );
            foreach ( $needles as $needle ) {
                if ( false !== strpos( $lower, strtolower( (string) $needle ) ) ) {
                    $matches[] = sanitize_text_field( (string) $line );
                    break;
                }
            }

            if ( count( $matches ) >= $limit ) {
                break;
            }
        }

        return array_values( array_unique( $matches ) );
    }

    private function meta_contents_by_names( array $html_evidence, array $names ) {
        $matches = array();
        $tags    = isset( $html_evidence['meta_tags'] ) && is_array( $html_evidence['meta_tags'] ) ? $html_evidence['meta_tags'] : array();

        foreach ( $tags as $tag ) {
            if ( ! is_array( $tag ) ) {
                continue;
            }

            $name     = isset( $tag['name'] ) ? strtolower( (string) $tag['name'] ) : '';
            $property = isset( $tag['property'] ) ? strtolower( (string) $tag['property'] ) : '';
            $content  = isset( $tag['content'] ) ? sanitize_text_field( (string) $tag['content'] ) : '';

            foreach ( $names as $target ) {
                $target = strtolower( (string) $target );
                if ( '' !== $content && ( $target === $name || $target === $property ) ) {
                    $matches[] = $content;
                }
            }
        }

        return array_values( array_unique( $matches ) );
    }

    private function duration_guess( $start, $end ) {
        $start_ts = '' !== $start ? strtotime( (string) $start ) : false;
        $end_ts   = '' !== $end ? strtotime( (string) $end ) : false;

        if ( false === $start_ts || false === $end_ts || $end_ts <= $start_ts ) {
            return '';
        }

        $seconds = $end_ts - $start_ts;
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

    private function plain_text( $html ) {
        $text = wp_strip_all_tags( html_entity_decode( (string) $html, ENT_QUOTES | ENT_HTML5 ) );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( sanitize_textarea_field( (string) $text ) );
    }

    private function evidence_bundle_for_candidate( $candidate_id ) {
        $evidence_id = absint( get_post_meta( $candidate_id, '_gi_evidence_bundle_id', true ) );
        if ( ! $evidence_id ) {
            return array();
        }

        $bundle = get_post_meta( $evidence_id, '_gi_evidence_bundle', true );
        return is_array( $bundle ) ? $bundle : array();
    }

    private function meta( $post_id, $key ) {
        $value = get_post_meta( $post_id, '_gi_' . sanitize_key( $key ), true );

        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        return sanitize_text_field( (string) $value );
    }

    private function nested_string( array $data, array $path ) {
        $value = $data;
        foreach ( $path as $key ) {
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
                return '';
            }
            $value = $value[ $key ];
        }

        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        return sanitize_text_field( (string) $value );
    }
}
