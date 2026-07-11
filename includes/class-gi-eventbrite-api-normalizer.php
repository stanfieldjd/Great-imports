<?php
/**
 * Normalizes Eventbrite API payloads into Great Imports candidates.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Eventbrite_API_Normalizer {
    /**
     * Normalize Eventbrite API event data.
     *
     * @param array<string,mixed> $event API event payload.
     * @param string              $description_override Optional description from description endpoint.
     * @return array<string,mixed>
     */
    public function normalize_event( array $event, $description_override = '' ) {
        $description = '' !== $description_override ? $description_override : $this->nested_string( $event, array( 'description', 'html' ) );
        if ( '' === $description ) {
            $description = $this->nested_string( $event, array( 'description', 'text' ) );
        }

        return array(
            'source_type'           => 'eventbrite',
            'eventbrite_event_id'   => $this->string_value( isset( $event['id'] ) ? $event['id'] : '' ),
            'title'                 => $this->nested_string( $event, array( 'name', 'text' ) ),
            'description'           => wp_kses_post( $description ),
            'start_date'            => $this->nested_string( $event, array( 'start', 'local' ) ),
            'start_date_utc'        => $this->nested_string( $event, array( 'start', 'utc' ) ),
            'end_date'              => $this->nested_string( $event, array( 'end', 'local' ) ),
            'end_date_utc'          => $this->nested_string( $event, array( 'end', 'utc' ) ),
            'timezone'              => $this->nested_string( $event, array( 'start', 'timezone' ) ),
            'location_name'         => $this->nested_string( $event, array( 'venue', 'name' ) ),
            'location_address'      => $this->format_address( isset( $event['venue']['address'] ) ? $event['venue']['address'] : array() ),
            'location_address_1'    => $this->nested_string( $event, array( 'venue', 'address', 'address_1' ) ),
            'location_address_2'    => $this->nested_string( $event, array( 'venue', 'address', 'address_2' ) ),
            'location_city'         => $this->nested_string( $event, array( 'venue', 'address', 'city' ) ),
            'location_state'        => $this->nested_string( $event, array( 'venue', 'address', 'region' ) ),
            'location_postal_code'  => $this->nested_string( $event, array( 'venue', 'address', 'postal_code' ) ),
            'location_country'      => $this->nested_string( $event, array( 'venue', 'address', 'country' ) ),
            'location_latitude'     => $this->coordinate_value(
                $this->first_nonempty(
                    array(
                        $this->nested_string( $event, array( 'venue', 'latitude' ) ),
                        $this->nested_string( $event, array( 'venue', 'address', 'latitude' ) ),
                    )
                ),
                'latitude'
            ),
            'location_longitude'    => $this->coordinate_value(
                $this->first_nonempty(
                    array(
                        $this->nested_string( $event, array( 'venue', 'longitude' ) ),
                        $this->nested_string( $event, array( 'venue', 'address', 'longitude' ) ),
                    )
                ),
                'longitude'
            ),
            'ticket_url'            => esc_url_raw( $this->string_value( isset( $event['url'] ) ? $event['url'] : '' ) ),
            'price'                 => $this->nested_string( $event, array( 'ticket_availability', 'minimum_ticket_price', 'major_value' ) ),
            'price_currency'        => $this->nested_string( $event, array( 'ticket_availability', 'minimum_ticket_price', 'currency' ) ),
            'organizer_name'        => $this->nested_string( $event, array( 'organizer', 'name' ) ),
            'organizer_url'         => esc_url_raw( $this->nested_string( $event, array( 'organizer', 'url' ) ) ),
            'category_name'         => $this->nested_string( $event, array( 'category', 'short_name' ) ),
            'image_url'             => esc_url_raw( $this->nested_string( $event, array( 'logo', 'original', 'url' ) ) ),
            'raw_event'             => $event,
            'candidate_status'      => 'needs_review',
            'collection_method'     => 'one_time_eventbrite_api',
            'event_location_source' => 'eventbrite_api',
        );
    }

    /**
     * Safely read a nested scalar value.
     *
     * @param array<string,mixed> $data Source data.
     * @param string[]            $path Path keys.
     */
    private function nested_string( array $data, array $path ) {
        $value = $data;

        foreach ( $path as $key ) {
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
                return '';
            }
            $value = $value[ $key ];
        }

        return $this->string_value( $value );
    }

    /**
     * Cast a scalar to sanitized string.
     *
     * @param mixed $value Value.
     */
    private function string_value( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        return sanitize_text_field( (string) $value );
    }

    /**
     * Format an Eventbrite venue address without mixing coordinates into the address text.
     *
     * @param mixed $address Address array.
     */
    private function format_address( $address ) {
        if ( ! is_array( $address ) ) {
            return '';
        }

        if ( ! empty( $address['localized_address_display'] ) && ! is_array( $address['localized_address_display'] ) ) {
            return sanitize_text_field( (string) $address['localized_address_display'] );
        }

        $parts = array();
        foreach ( array( 'address_1', 'address_2', 'city', 'region', 'postal_code', 'country' ) as $key ) {
            if ( ! empty( $address[ $key ] ) && ! is_array( $address[ $key ] ) ) {
                $parts[] = sanitize_text_field( (string) $address[ $key ] );
            }
        }

        return implode( ', ', array_filter( $parts ) );
    }

    /**
     * Return the first non-empty scalar value from a list.
     *
     * @param array<int,string> $values Candidate values.
     */
    private function first_nonempty( array $values ) {
        foreach ( $values as $value ) {
            if ( '' !== trim( (string) $value ) ) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Keep only valid decimal source coordinates for Events Manager location saves.
     *
     * @param string $value Coordinate value.
     * @param string $axis  Coordinate axis.
     */
    private function coordinate_value( $value, $axis ) {
        $value = trim( (string) $value );
        if ( '' === $value || ! is_numeric( $value ) ) {
            return '';
        }

        $number = (float) $value;
        $limit  = 'latitude' === $axis ? 90 : 180;
        if ( $number < -$limit || $number > $limit ) {
            return '';
        }

        return rtrim( rtrim( sprintf( '%.8F', $number ), '0' ), '.' );
    }
}
