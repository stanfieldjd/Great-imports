<?php
/**
 * Extracts schema.org Event data from JSON-LD.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Jsonld_Parser {
    /**
     * Parse the first Event-shaped JSON-LD node in an HTML document.
     *
     * @param string $html HTML source.
     * @return array{success:bool,event:array<string,mixed>,error:string}
     */
    public function parse_event_from_html( $html ) {
        $blocks = $this->extract_jsonld_blocks( $html );

        foreach ( $blocks as $block ) {
            $decoded = json_decode( $block, true );

            if ( ! is_array( $decoded ) ) {
                continue;
            }

            $event = $this->find_event_node( $decoded );

            if ( is_array( $event ) ) {
                return array(
                    'success' => true,
                    'event'   => $this->normalize_event( $event ),
                    'error'   => '',
                );
            }
        }

        return array(
            'success' => false,
            'event'   => array(),
            'error'   => __( 'No schema.org Event JSON-LD was found on the page.', 'great-imports' ),
        );
    }

    /**
     * Extract raw JSON-LD script bodies.
     *
     * @param string $html HTML source.
     * @return string[]
     */
    private function extract_jsonld_blocks( $html ) {
        $blocks = array();

        if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
            foreach ( $matches[1] as $match ) {
                $blocks[] = html_entity_decode( trim( $match ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            }
        }

        return $blocks;
    }

    /**
     * Locate an Event node recursively.
     *
     * @param mixed $node Decoded JSON node.
     * @return array<string,mixed>|null
     */
    private function find_event_node( $node ) {
        if ( ! is_array( $node ) ) {
            return null;
        }

        if ( $this->is_event_type( isset( $node['@type'] ) ? $node['@type'] : null ) ) {
            return $node;
        }

        if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
            foreach ( $node['@graph'] as $graph_node ) {
                $event = $this->find_event_node( $graph_node );
                if ( is_array( $event ) ) {
                    return $event;
                }
            }
        }

        foreach ( $node as $child ) {
            if ( is_array( $child ) ) {
                $event = $this->find_event_node( $child );
                if ( is_array( $event ) ) {
                    return $event;
                }
            }
        }

        return null;
    }

    /**
     * PHP 7.4-compatible suffix check.
     *
     * @param string $haystack Full string.
     * @param string $needle Suffix.
     */
    private function ends_with( $haystack, $needle ) {
        if ( '' === $needle ) {
            return true;
        }

        return substr( $haystack, -strlen( $needle ) ) === $needle;
    }

    /**
     * Determine whether a JSON-LD type is Event-shaped.
     *
     * @param mixed $type Type value.
     */
    private function is_event_type( $type ) {
        $types = is_array( $type ) ? $type : array( $type );

        foreach ( $types as $single_type ) {
            $single_type = strtolower( (string) $single_type );
            if ( 'event' === $single_type || $this->ends_with( $single_type, 'event' ) || $this->ends_with( $single_type, '/event' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize Event data into the plugin candidate shape.
     *
     * @param array<string,mixed> $event Event JSON-LD node.
     * @return array<string,mixed>
     */
    private function normalize_event( array $event ) {
        $location = $this->first_item( isset( $event['location'] ) ? $event['location'] : array() );
        $offer    = $this->first_item( isset( $event['offers'] ) ? $event['offers'] : array() );
        $image    = $this->first_item( isset( $event['image'] ) ? $event['image'] : '' );

        return array(
            'source_type'       => 'eventbrite',
            'title'             => $this->string_value( isset( $event['name'] ) ? $event['name'] : '' ),
            'description'       => wp_kses_post( $this->string_value( isset( $event['description'] ) ? $event['description'] : '' ) ),
            'start_date'        => $this->string_value( isset( $event['startDate'] ) ? $event['startDate'] : '' ),
            'end_date'          => $this->string_value( isset( $event['endDate'] ) ? $event['endDate'] : '' ),
            'location_name'     => $this->string_value( is_array( $location ) && isset( $location['name'] ) ? $location['name'] : '' ),
            'location_address'  => $this->format_address( is_array( $location ) && isset( $location['address'] ) ? $location['address'] : array() ),
            'ticket_url'        => esc_url_raw( $this->string_value( is_array( $offer ) && isset( $offer['url'] ) ? $offer['url'] : '' ) ),
            'price'             => $this->string_value( is_array( $offer ) && isset( $offer['price'] ) ? $offer['price'] : '' ),
            'price_currency'    => $this->string_value( is_array( $offer ) && isset( $offer['priceCurrency'] ) ? $offer['priceCurrency'] : '' ),
            'organizer_name'    => $this->string_value( is_array( $event ) && isset( $event['organizer']['name'] ) ? $event['organizer']['name'] : '' ),
            'image_url'         => esc_url_raw( $this->string_value( $image ) ),
            'raw_event'         => $event,
            'candidate_status'  => 'needs_review',
            'collection_method' => 'one_time_eventbrite_url',
        );
    }

    /**
     * Return first list item or scalar value.
     *
     * @param mixed $value Input value.
     * @return mixed
     */
    private function first_item( $value ) {
        if ( is_array( $value ) && isset( $value[0] ) ) {
            return $value[0];
        }

        return $value;
    }

    /**
     * Cast a scalar value to a sanitized string.
     *
     * @param mixed $value Input value.
     */
    private function string_value( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        return sanitize_text_field( (string) $value );
    }

    /**
     * Format a schema.org PostalAddress into an address string.
     *
     * @param mixed $address Address node.
     */
    private function format_address( $address ) {
        if ( is_string( $address ) ) {
            return sanitize_text_field( $address );
        }

        if ( ! is_array( $address ) ) {
            return '';
        }

        $parts = array();
        foreach ( array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' ) as $key ) {
            if ( ! empty( $address[ $key ] ) && ! is_array( $address[ $key ] ) ) {
                $parts[] = sanitize_text_field( (string) $address[ $key ] );
            }
        }

        return implode( ', ', array_filter( $parts ) );
    }
}
