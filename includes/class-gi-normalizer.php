<?php

defined( 'ABSPATH' ) || exit;

final class GI_Normalizer {
    private const FIELD_LIST = array(
        'title', 'description', 'start', 'end', 'start_date', 'start_time', 'end_date', 'end_time',
        'all_day', 'timezone', 'event_url', 'ticket_url', 'price', 'currency', 'image_url',
        'location_name', 'location_address', 'location_city', 'location_state', 'location_postcode',
        'location_country', 'location_latitude', 'location_longitude', 'parent_location_name', 'stage_name',
        'organizer', 'uid', 'recurrence_rule',
    );

    public static function priority( string $method ): int {
        $map = array(
            'api'             => 110,
            'detail_visible'  => 105,
            'detail_jsonld'   => 100,
            'ics'             => 98,
            'detail_microdata'=> 95,
            'detail_page'     => 90,
            'festival_visible'=> 88,
            'listing_visible' => 85,
            'listing_jsonld'  => 80,
            'listing_card'    => 65,
            'generic_card'    => 50,
            'source_default'  => 30,
            'manual'          => 120,
        );
        return $map[ $method ] ?? 40;
    }

    public static function from_raw( array $raw, string $method, string $source_url ): array {
        $priority = self::priority( $method );
        $ticket_url = self::ticket_url( $raw['ticket_url'] ?? $raw['offers_url'] ?? '' );
        $candidate = array(
            'title'                 => sanitize_text_field( $raw['title'] ?? $raw['name'] ?? '' ),
            'description'           => GI_Utils::sanitize_html( $raw['description'] ?? '' ),
            'event_url'             => GI_Utils::clean_url( $raw['event_url'] ?? $raw['url'] ?? $source_url ),
            'ticket_url'            => $ticket_url,
            'price'                 => sanitize_text_field( $raw['price'] ?? '' ),
            'currency'              => strtoupper( sanitize_text_field( $raw['currency'] ?? '' ) ),
            'image_url'             => GI_Utils::clean_url( $raw['image_url'] ?? $raw['image'] ?? '' ),
            'location_name'         => sanitize_text_field( $raw['location_name'] ?? '' ),
            'location_address'      => sanitize_text_field( $raw['location_address'] ?? $raw['address'] ?? '' ),
            'location_city'         => sanitize_text_field( $raw['location_city'] ?? $raw['city'] ?? '' ),
            'location_state'        => sanitize_text_field( $raw['location_state'] ?? $raw['state'] ?? '' ),
            'location_postcode'     => sanitize_text_field( $raw['location_postcode'] ?? $raw['postcode'] ?? $raw['zip'] ?? '' ),
            'location_country'      => sanitize_text_field( $raw['location_country'] ?? $raw['country'] ?? '' ),
            'location_latitude'     => GI_Utils::sanitize_coordinate( $raw['location_latitude'] ?? $raw['latitude'] ?? '', 'latitude' ),
            'location_longitude'    => GI_Utils::sanitize_coordinate( $raw['location_longitude'] ?? $raw['longitude'] ?? '', 'longitude' ),
            'parent_location_name'  => sanitize_text_field( $raw['parent_location_name'] ?? '' ),
            'stage_name'            => sanitize_text_field( $raw['stage_name'] ?? '' ),
            'organizer'             => sanitize_text_field( $raw['organizer'] ?? '' ),
            'uid'                   => sanitize_text_field( $raw['uid'] ?? $raw['event_id'] ?? '' ),
            'recurrence_rule'       => sanitize_text_field( $raw['recurrence_rule'] ?? $raw['rrule'] ?? '' ),
            'recurrence_mode'       => sanitize_key( $raw['recurrence_mode'] ?? ( ! empty( $raw['recurrence_rule'] ) || ! empty( $raw['rrule'] ) ? 'series' : 'single' ) ),
            'recurrence_frequency'  => sanitize_key( $raw['recurrence_frequency'] ?? '' ),
            'recurrence_interval'   => absint( $raw['recurrence_interval'] ?? 1 ),
            'recurrence_until'      => sanitize_text_field( $raw['recurrence_until'] ?? '' ),
            'recurrence_count'      => absint( $raw['recurrence_count'] ?? 0 ),
            'recurrence_weekdays'   => (array) ( $raw['recurrence_weekdays'] ?? array() ),
            'structure'             => sanitize_key( $raw['structure'] ?? '' ),
            'festival_slots'        => $raw['festival_slots'] ?? $raw['timeslots'] ?? $raw['schedule'] ?? array(),
            'festival_annual'       => ! empty( $raw['festival_annual'] ),
            'festival_edition_year' => absint( $raw['festival_edition_year'] ?? 0 ),
            'categories'            => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $raw['categories'] ?? array() ) ) ) ) ),
            'tags'                  => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $raw['tags'] ?? array() ) ) ) ) ),
            'source_urls'           => array_values( array_filter( array_unique( array( GI_Utils::clean_url( $source_url ), GI_Utils::clean_url( $raw['event_url'] ?? '' ) ) ) ) ),
            'method'                => $method,
            'method_priority'       => $priority,
            'evidence'              => array(),
            'conflicts'             => array(),
            'hold_reasons'          => array(),
            'status'                => 'held',
        );

        $all_day = ! empty( $raw['all_day'] );
        $parts = GI_Utils::date_parts( $raw['start'] ?? $raw['start_date'] ?? '', $raw['end'] ?? $raw['end_date'] ?? '', $all_day );
        $candidate = array_merge( $candidate, $parts );
        $candidate['start'] = $raw['start'] ?? '';
        $candidate['end']   = $raw['end'] ?? '';
        // Normalize before evidence is recorded so concatenated full addresses
        // and venue suffixes do not become permanent competing evidence.
        $candidate = GI_Utils::normalize_location_fields( $candidate );

        foreach ( self::FIELD_LIST as $field ) {
            $value = $candidate[ $field ] ?? '';
            if ( self::has_value( $value ) ) {
                $candidate['evidence'][ $field ][] = array(
                    'value'      => $value,
                    'priority'   => $priority,
                    'method'     => $method,
                    'source_url' => GI_Utils::clean_url( $source_url ),
                );
            }
        }
        return self::finalize_candidate( $candidate );
    }

    /**
     * Build a partial manual candidate containing evidence only for fields the
     * administrator explicitly changed. This deliberately does not finalize
     * the candidate because it may contain only one corrected field.
     */
    public static function from_manual_overrides( array $overrides, string $source_url ): array {
        $priority = self::priority( 'manual' );
        $candidate = array(
            'method'          => 'manual',
            'method_priority' => $priority,
            'evidence'        => array(),
            'conflicts'       => array(),
            'hold_reasons'    => array(),
            'source_urls'     => array_values( array_filter( array( GI_Utils::clean_url( $source_url ) ) ) ),
            'categories'      => array_values( (array) ( $overrides['categories'] ?? array() ) ),
            'tags'            => array_values( (array) ( $overrides['tags'] ?? array() ) ),
        );

        foreach ( self::FIELD_LIST as $field ) {
            if ( ! array_key_exists( $field, $overrides ) ) {
                continue;
            }
            $value = $overrides[ $field ];
            $candidate[ $field ] = $value;
            if ( self::has_value( $value ) ) {
                $candidate['evidence'][ $field ][] = array(
                    'value'      => $value,
                    'priority'   => $priority,
                    'method'     => 'manual',
                    'source_url' => GI_Utils::clean_url( $source_url ),
                );
            }
        }

        foreach ( array( 'recurrence_mode', 'recurrence_frequency', 'recurrence_interval', 'recurrence_until', 'recurrence_count', 'recurrence_weekdays', 'recurrence_rule', 'em_location_id' ) as $field ) {
            if ( array_key_exists( $field, $overrides ) ) {
                $candidate[ $field ] = $overrides[ $field ];
            }
        }

        return $candidate;
    }

    public static function merge_candidates( array $candidates ): array {
        $candidates = array_values( array_filter( $candidates, 'is_array' ) );
        if ( ! $candidates ) {
            return self::finalize_candidate( array() );
        }
        $merged = array(
            'evidence'      => array(),
            'source_urls'   => array(),
            'categories'    => array(),
            'tags'          => array(),
            'conflicts'     => array(),
            'hold_reasons'  => array(),
            'festival_slots'=> array(),
            'structure'     => 'auto',
            '_festival_priority' => -1,
        );
        foreach ( $candidates as $candidate ) {
            $method   = sanitize_key( $candidate['method'] ?? 'generic_card' );
            $priority = absint( $candidate['method_priority'] ?? self::priority( $method ) );
            foreach ( self::FIELD_LIST as $field ) {
                $entries = $candidate['evidence'][ $field ] ?? array();
                if ( ! $entries && self::has_value( $candidate[ $field ] ?? '' ) ) {
                    $entries[] = array(
                        'value'      => $candidate[ $field ],
                        'priority'   => $priority,
                        'method'     => $method,
                        'source_url' => $candidate['event_url'] ?? ( $candidate['source_urls'][0] ?? '' ),
                    );
                }
                foreach ( $entries as $entry ) {
                    if ( self::has_value( $entry['value'] ?? '' ) ) {
                        $merged['evidence'][ $field ][] = $entry;
                    }
                }
            }
            $merged['source_urls'] = array_merge( $merged['source_urls'], (array) ( $candidate['source_urls'] ?? array() ) );
            $merged['categories']  = array_merge( $merged['categories'], (array) ( $candidate['categories'] ?? array() ) );
            $merged['tags']        = array_merge( $merged['tags'], (array) ( $candidate['tags'] ?? array() ) );
            $merged['hold_reasons']= array_merge( $merged['hold_reasons'], (array) ( $candidate['hold_reasons'] ?? array() ) );
            if ( ! empty( $candidate['festival_slots'] ) && $priority >= $merged['_festival_priority'] ) {
                $merged['festival_slots'] = (array) $candidate['festival_slots'];
                $merged['structure'] = 'festival';
                $merged['_festival_priority'] = $priority;
            } elseif ( 'auto' === $merged['structure'] && ! empty( $candidate['structure'] ) ) {
                $merged['structure'] = sanitize_key( $candidate['structure'] );
            }
            foreach ( array( 'source_id', 'id', 'source_uid', 'fingerprint', 'em_event_id', 'em_post_id', 'em_location_id', 'status', 'recurrence_mode', 'recurrence_frequency', 'recurrence_interval', 'recurrence_until', 'recurrence_count', 'recurrence_weekdays', 'recurrence_rule', 'festival_annual', 'festival_edition_year' ) as $key ) {
                if ( ! empty( $candidate[ $key ] ) && empty( $merged[ $key ] ) ) {
                    $merged[ $key ] = $candidate[ $key ];
                }
            }
        }
        $merged['source_urls'] = array_values( array_unique( array_filter( array_map( array( 'GI_Utils', 'clean_url' ), $merged['source_urls'] ) ) ) );
        $merged['categories']  = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $merged['categories'] ) ) ) );
        $merged['tags']        = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $merged['tags'] ) ) ) );
        $merged['hold_reasons']= array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $merged['hold_reasons'] ) ) ) );

        foreach ( self::FIELD_LIST as $field ) {
            $selection = self::select_field( $field, $merged['evidence'][ $field ] ?? array() );
            if ( self::has_value( $selection['value'] ?? '' ) ) {
                $merged[ $field ] = $selection['value'];
            }
            if ( ! empty( $selection['conflict'] ) ) {
                $merged['conflicts'][ $field ] = $selection['alternatives'];
            }
        }
        $merged = GI_Utils::normalize_location_fields( $merged );
        if ( ! empty( $merged['conflicts']['title'] ) ) {
            $title_keys = array();
            foreach ( (array) $merged['conflicts']['title'] as $alternative ) {
                $cleaned = GI_Utils::strip_location_suffix_from_title( (string) $alternative, array_filter( array( $merged['location_name'] ?? '', $merged['parent_location_name'] ?? '' ) ) );
                $title_keys[ GI_Utils::normalize_title( $cleaned ) ] = $cleaned;
            }
            unset( $title_keys[''] );
            if ( 1 === count( $title_keys ) ) {
                $merged['title'] = reset( $title_keys );
                unset( $merged['conflicts']['title'] );
            }
        }
        if ( ! empty( $merged['conflicts']['location_address'] ) ) {
            $address_keys = array();
            foreach ( (array) $merged['conflicts']['location_address'] as $alternative ) {
                $parsed = GI_Utils::split_full_address( (string) $alternative );
                $street = $parsed['location_address'] ?? (string) $alternative;
                $address_keys[ GI_Utils::normalize_address( $street ) ] = $street;
            }
            unset( $address_keys[''] );
            if ( 1 === count( $address_keys ) ) {
                $merged['location_address'] = reset( $address_keys );
                unset( $merged['conflicts']['location_address'] );
            }
        }
        foreach ( array( 'event_url', 'ticket_url', 'image_url' ) as $non_blocking_field ) {
            unset( $merged['conflicts'][ $non_blocking_field ] );
        }
        unset( $merged['_festival_priority'] );
        return self::finalize_candidate( $merged );
    }

    private static function select_field( string $field, array $entries ): array {
        $entries = array_values( array_filter( $entries, fn( $entry ) => self::has_value( $entry['value'] ?? '' ) ) );
        if ( 'ticket_url' === $field ) {
            $entries = array_values(
                array_filter(
                    $entries,
                    static fn( array $entry ): bool => '' !== self::ticket_url( $entry['value'] ?? '' )
                )
            );
        }
        if ( ! $entries ) {
            return array( 'value' => '' );
        }
        usort(
            $entries,
            static function ( array $a, array $b ) use ( $field ): int {
                $priority = (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 );
                if ( 0 !== $priority ) {
                    return $priority;
                }
                if ( 'description' === $field ) {
                    return strlen( wp_strip_all_tags( (string) ( $b['value'] ?? '' ) ) ) <=> strlen( wp_strip_all_tags( (string) ( $a['value'] ?? '' ) ) );
                }
                return 0;
            }
        );
        $top_priority = (int) ( $entries[0]['priority'] ?? 0 );
        $top = array_values( array_filter( $entries, fn( $entry ) => (int) ( $entry['priority'] ?? 0 ) === $top_priority ) );
        $unique = array();
        foreach ( $top as $entry ) {
            $key = self::comparable_value( $field, $entry['value'] );
            if ( '' !== $key ) {
                $unique[ $key ] = $entry['value'];
            }
        }
        $value = $entries[0]['value'];
        if ( in_array( $field, array( 'event_url', 'ticket_url', 'image_url' ), true ) ) {
            if ( in_array( $field, array( 'event_url', 'ticket_url' ), true ) ) {
                usort(
                    $top,
                    static function ( array $left, array $right ): int {
                        $score = static function ( array $entry ): int {
                            $url = GI_Utils::clean_url( (string) ( $entry['value'] ?? '' ) );
                            $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
                            $query = strtolower( (string) wp_parse_url( $url, PHP_URL_QUERY ) );
                            return ( $path ? count( array_filter( explode( '/', $path ) ) ) * 10 : 0 )
                                - ( preg_match( '/(?:format=ical|\.ics(?:$|\?))/', strtolower( $url ) ) ? 100 : 0 )
                                - ( str_contains( $query, 'calendar' ) ? 20 : 0 );
                        };
                        return $score( $right ) <=> $score( $left );
                    }
                );
                $value = $top[0]['value'] ?? $value;
            }
            return array( 'value' => $value, 'conflict' => false, 'alternatives' => array_values( $unique ) );
        }
        if ( 'location_name' === $field && count( $unique ) > 1 ) {
            $specific = GI_Utils::choose_specific_location_name( array_values( $unique ) );
            if ( $specific ) {
                return array(
                    'value'        => $specific,
                    'conflict'     => false,
                    'alternatives' => array_values( $unique ),
                );
            }
        }
        return array(
            'value'        => $value,
            'conflict'     => count( $unique ) > 1,
            'alternatives' => array_values( $unique ),
        );
    }

    private static function comparable_value( string $field, $value ): string {
        if ( in_array( $field, array( 'start_date', 'end_date', 'start_time', 'end_time', 'all_day' ), true ) ) {
            return strtolower( trim( (string) $value ) );
        }
        if ( str_contains( $field, 'address' ) ) {
            return GI_Utils::normalize_address( (string) $value );
        }
        if ( in_array( $field, array( 'event_url', 'ticket_url', 'image_url' ), true ) ) {
            return GI_Utils::clean_url( (string) $value );
        }
        return GI_Utils::normalize_text( (string) $value );
    }

    public static function finalize_candidate( array $candidate ): array {
        $candidate = wp_parse_args(
            $candidate,
            array(
                'title' => '', 'description' => '', 'start_date' => '', 'start_time' => '',
                'end_date' => '', 'end_time' => '', 'all_day' => false, 'timezone' => wp_timezone_string(),
                'event_url' => '', 'ticket_url' => '', 'price' => '', 'currency' => '', 'image_url' => '',
                'location_name' => '', 'location_address' => '', 'location_city' => '', 'location_state' => '',
                'location_postcode' => '', 'location_country' => '', 'location_latitude' => '', 'location_longitude' => '',
                'parent_location_name' => '', 'stage_name' => '',
                'organizer' => '', 'uid' => '', 'categories' => array(), 'tags' => array(), 'source_urls' => array(),
                'recurrence_mode' => 'single', 'recurrence_frequency' => '', 'recurrence_interval' => 1,
                'recurrence_until' => '', 'recurrence_count' => 0, 'recurrence_weekdays' => array(), 'recurrence_rule' => '',
                'structure' => 'auto', 'festival_slots' => array(), 'festival_annual' => false, 'festival_edition_year' => 0,
                'evidence' => array(), 'conflicts' => array(), 'hold_reasons' => array(), 'status' => 'held',
            )
        );

        $candidate['title']         = sanitize_text_field( $candidate['title'] );
        $candidate['description']   = GI_Utils::sanitize_html( $candidate['description'] );
        $candidate['event_url']     = GI_Utils::clean_url( $candidate['event_url'] );
        $candidate['ticket_url']    = self::ticket_url( $candidate['ticket_url'] );
        $candidate['image_url']     = GI_Utils::clean_url( $candidate['image_url'] );
        $candidate['source_urls']   = array_values( array_unique( array_filter( array_map( array( 'GI_Utils', 'clean_url' ), (array) $candidate['source_urls'] ) ) ) );
        $candidate['categories']    = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $candidate['categories'] ) ) ) );
        $candidate['tags']          = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $candidate['tags'] ) ) ) );
        $candidate['recurrence_mode'] = 'series' === sanitize_key( $candidate['recurrence_mode'] ?? '' ) ? 'series' : 'single';
        $allowed_frequencies = array( 'daily', 'weekly', 'monthly' );
        $candidate['recurrence_frequency'] = in_array( sanitize_key( $candidate['recurrence_frequency'] ?? '' ), $allowed_frequencies, true ) ? sanitize_key( $candidate['recurrence_frequency'] ) : '';
        $candidate['recurrence_interval'] = min( 365, max( 1, absint( $candidate['recurrence_interval'] ?? 1 ) ) );
        $candidate['recurrence_count'] = min( 500, max( 0, absint( $candidate['recurrence_count'] ?? 0 ) ) );
        $candidate['recurrence_until'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $candidate['recurrence_until'] ?? '' ) ) ? (string) $candidate['recurrence_until'] : '';
        $allowed_days = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );
        $candidate['recurrence_weekdays'] = array_values( array_unique( array_intersect( $allowed_days, array_map( static fn( $day ) => strtoupper( sanitize_text_field( (string) $day ) ), (array) ( $candidate['recurrence_weekdays'] ?? array() ) ) ) ) );
        $candidate['recurrence_rule'] = sanitize_text_field( $candidate['recurrence_rule'] ?? '' );
        $allowed_structures = array( 'auto', 'festival', 'conference', 'multi_session', 'multi_location' );
        $candidate['structure'] = in_array( sanitize_key( $candidate['structure'] ?? '' ), $allowed_structures, true ) ? sanitize_key( $candidate['structure'] ) : 'auto';
        $candidate['festival_slots'] = self::normalize_festival_slots( $candidate['festival_slots'] ?? array() );
        $candidate['festival_annual'] = ! empty( $candidate['festival_annual'] );
        $candidate['festival_edition_year'] = absint( $candidate['festival_edition_year'] ?? 0 );
        if ( $candidate['festival_slots'] ) {
            $candidate['structure'] = 'festival';
            $candidate['title'] = trim(
                preg_replace(
                    '/^(?:20\d{2}\s+)?(?:LINE[\s-]?UP|SCHEDULE)\s*(?:[—–-]\s*)?/iu',
                    '',
                    $candidate['title']
                )
            );
            if ( $candidate['festival_annual'] ) {
                if ( ! $candidate['festival_edition_year'] && preg_match( '/\b(20\d{2})\b/u', $candidate['title'], $edition_match ) ) {
                    $candidate['festival_edition_year'] = absint( $edition_match[1] );
                }
                $candidate['title'] = trim(
                    preg_replace(
                        array(
                            '/^\s*(?:[\(\[]\s*)?20\d{2}(?:\s*[\)\]])?\s*(?:[-–—:|]\s*)?/u',
                            '/(?:\s*[-–—:|]\s*)?(?:[\(\[]\s*)?20\d{2}(?:\s*[\)\]])?\s*$/u',
                        ),
                        '',
                        $candidate['title']
                    )
                );
                if ( ! $candidate['festival_edition_year'] && preg_match( '/^(20\d{2})-\d{2}-\d{2}$/', (string) ( $candidate['start_date'] ?? '' ), $date_match ) ) {
                    $candidate['festival_edition_year'] = absint( $date_match[1] );
                }
            }
            self::apply_festival_day_dates( $candidate );
            self::apply_festival_bounds( $candidate );
            if ( $candidate['festival_annual'] && ! $candidate['festival_edition_year'] && preg_match( '/^(20\d{2})-\d{2}-\d{2}$/', (string) ( $candidate['start_date'] ?? '' ), $mapped_date_match ) ) {
                $candidate['festival_edition_year'] = absint( $mapped_date_match[1] );
            }
        }
        $candidate = GI_Utils::normalize_location_fields( $candidate );
        if ( 'series' !== $candidate['recurrence_mode'] ) {
            $candidate['recurrence_frequency'] = '';
            $candidate['recurrence_interval'] = 1;
            $candidate['recurrence_until'] = '';
            $candidate['recurrence_count'] = 0;
            $candidate['recurrence_weekdays'] = array();
            $candidate['recurrence_rule'] = '';
        }
        $candidate['fingerprint']   = GI_Utils::fingerprint( $candidate );
        $candidate['source_uid']    = $candidate['source_uid'] ?? GI_Utils::source_uid( $candidate );

        $holds = array();
        if ( '' === trim( $candidate['title'] ) ) {
            $holds[] = __( 'Event title is missing.', 'great-imports' );
        }
        if ( '' === trim( $candidate['start_date'] ) ) {
            $has_weekday_schedule = (bool) array_filter( $candidate['festival_slots'], static fn( array $slot ): bool => ! empty( $slot['day_label'] ) );
            $holds[] = $has_weekday_schedule
                ? __( 'Enter the festival start date to place its weekday schedule.', 'great-imports' )
                : __( 'Start date is missing.', 'great-imports' );
        }
        $blocking_conflict_fields = array(
            'title', 'start_date', 'start_time', 'end_date', 'end_time', 'all_day', 'timezone',
            'location_name', 'location_address', 'location_city', 'location_state', 'location_postcode',
            'location_country', 'parent_location_name', 'stage_name', 'recurrence_rule',
        );
        $blocking_conflicts = array_intersect_key( (array) $candidate['conflicts'], array_flip( $blocking_conflict_fields ) );
        if ( $blocking_conflicts ) {
            $fields = implode( ', ', array_map( fn( $field ) => str_replace( '_', ' ', $field ), array_keys( $blocking_conflicts ) ) );
            /* translators: %s: comma-separated event fields containing conflicting source evidence. */
            $holds[] = sprintf( __( 'Equal-quality evidence conflicts: %s.', 'great-imports' ), $fields );
        }
        if ( empty( $candidate['location_name'] ) && empty( $candidate['location_address'] ) ) {
            $holds[] = __( 'Location needs review or a source rule.', 'great-imports' );
        }
        if ( 'series' === $candidate['recurrence_mode'] ) {
            if ( ! $candidate['recurrence_frequency'] ) {
                $holds[] = __( 'Choose how often this event series repeats.', 'great-imports' );
            }
            if ( ! $candidate['recurrence_count'] && ! $candidate['recurrence_until'] ) {
                $holds[] = __( 'Choose an occurrence count or an end date for this event series.', 'great-imports' );
            }
            if ( 'weekly' === $candidate['recurrence_frequency'] && ! $candidate['recurrence_weekdays'] ) {
                $start = GI_Utils::parse_datetime( $candidate['start_date'] ?? '' );
                if ( $start ) {
                    $candidate['recurrence_weekdays'] = array( array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' )[ (int) $start->format( 'w' ) ] );
                }
            }
        }
        $prior_holds = (array) $candidate['hold_reasons'];
        if ( '' !== trim( $candidate['start_date'] ) ) {
            $resolved_date_holds = array(
                __( 'Start date is missing.', 'great-imports' ),
                __( 'Enter the festival start date to place its weekday schedule.', 'great-imports' ),
            );
            $prior_holds = array_values( array_diff( $prior_holds, $resolved_date_holds ) );
        }
        $candidate['hold_reasons'] = array_values( array_unique( array_merge( $prior_holds, $holds ) ) );
        if ( ! in_array( $candidate['status'], array( 'imported', 'updated', 'ignored', 'failed', 'blocked' ), true ) ) {
            $candidate['status'] = $candidate['hold_reasons'] ? 'held' : 'ready';
        }
        return $candidate;
    }

    /**
     * Keep the public festival schedule richer than Events Manager's native
     * timeslot rows, which contain dates and times but no act, stage, or venue.
     */
    private static function normalize_festival_slots( $value ): array {
        if ( is_string( $value ) ) {
            $decoded = json_decode( wp_unslash( $value ), true );
            $value = is_array( $decoded ) ? $decoded : array();
        }
        if ( ! is_array( $value ) ) {
            return array();
        }
        if ( isset( $value['date'] ) || isset( $value['start'] ) || isset( $value['startDate'] ) ) {
            $value = array( $value );
        }

        $slots = array();
        foreach ( $value as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $start_value = $row['start'] ?? $row['startDate'] ?? '';
            $end_value = $row['end'] ?? $row['endDate'] ?? '';
            $raw_date = sanitize_text_field( $row['date'] ?? $row['start_date'] ?? $row['day'] ?? '' );
            $has_calendar_date = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_date );
            $parts = $start_value || $has_calendar_date
                ? GI_Utils::date_parts(
                    $start_value ?: trim( $raw_date . ' ' . (string) ( $row['start_time'] ?? $row['time'] ?? '' ) ),
                    $end_value ?: trim( (string) ( $row['end_date'] ?? $raw_date ) . ' ' . (string) ( $row['end_time'] ?? '' ) ),
                    ! empty( $row['all_day'] )
                )
                : array( 'start_date' => '', 'start_time' => '', 'end_date' => '', 'end_time' => '', 'all_day' => false );
            $date = $has_calendar_date ? $raw_date : sanitize_text_field( $parts['start_date'] ?? '' );
            $day_label = ucfirst( strtolower( sanitize_text_field( $row['day_label'] ?? $row['weekday'] ?? '' ) ) );
            if ( ! preg_match( '/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)$/', $day_label ) ) {
                $day_label = '';
            }
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && ! $day_label ) {
                continue;
            }
            $start_time = self::normalize_slot_time( $row['start_time'] ?? $row['time'] ?? $parts['start_time'] ?? '' );
            $end_time = self::normalize_slot_time( $row['end_time'] ?? $parts['end_time'] ?? '' );
            $end_date = sanitize_text_field( $row['end_date'] ?? $parts['end_date'] ?? $date );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
                $end_date = $date;
            }
            if ( $start_time && $end_time && $date === $end_date && $end_time < $start_time ) {
                $next_day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
                $end_date = $next_day ? $next_day->modify( '+1 day' )->format( 'Y-m-d' ) : $date;
            }
            $slots[] = array(
                'date'          => $date,
                'day_label'     => $day_label,
                'start_time'    => $start_time,
                'end_date'      => $end_date,
                'end_time'      => $end_time,
                'title'         => sanitize_text_field( $row['title'] ?? $row['name'] ?? $row['performer'] ?? $row['activity'] ?? '' ),
                'stage_name'    => sanitize_text_field( $row['stage_name'] ?? $row['stage'] ?? $row['room'] ?? $row['area'] ?? '' ),
                'location_name' => sanitize_text_field( $row['location_name'] ?? $row['location'] ?? $row['venue'] ?? '' ),
                'ticket_url'    => self::ticket_url( $row['ticket_url'] ?? $row['url'] ?? '' ),
            );
        }
        usort(
            $slots,
            static function ( array $a, array $b ): int {
                $weekday_order = array(
                    'Monday' => 1,
                    'Tuesday' => 2,
                    'Wednesday' => 3,
                    'Thursday' => 4,
                    'Friday' => 5,
                    'Saturday' => 6,
                    'Sunday' => 7,
                );
                $a_day = $a['date'] ?: sprintf( '9999-12-%02d', $weekday_order[ $a['day_label'] ] ?? 31 );
                $b_day = $b['date'] ?: sprintf( '9999-12-%02d', $weekday_order[ $b['day_label'] ] ?? 31 );
                return strcmp(
                    $a_day . ' ' . ( $a['start_time'] ?: '00:00:00' ) . ' ' . $a['stage_name'] . ' ' . $a['title'],
                    $b_day . ' ' . ( $b['start_time'] ?: '00:00:00' ) . ' ' . $b['stage_name'] . ' ' . $b['title']
                );
            }
        );
        return $slots;
    }

    private static function apply_festival_day_dates( array &$candidate ): void {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $candidate['start_date'] ?? '' ) ) ) {
            return;
        }
        $festival_start = DateTimeImmutable::createFromFormat( '!Y-m-d', $candidate['start_date'] );
        if ( ! $festival_start ) {
            return;
        }
        foreach ( $candidate['festival_slots'] as &$slot ) {
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $slot['date'] ?? '' ) ) || empty( $slot['day_label'] ) ) {
                continue;
            }
            $target_day = strtolower( (string) $slot['day_label'] );
            $slot_date = strtolower( $festival_start->format( 'l' ) ) === $target_day
                ? $festival_start
                : $festival_start->modify( 'next ' . $target_day );
            $slot['date'] = $slot_date->format( 'Y-m-d' );
            $slot['end_date'] = $slot['date'];
            if ( ! empty( $slot['start_time'] ) && ! empty( $slot['end_time'] ) && $slot['end_time'] < $slot['start_time'] ) {
                $slot['end_date'] = $slot_date->modify( '+1 day' )->format( 'Y-m-d' );
            }
        }
        unset( $slot );
    }

    private static function normalize_slot_time( $value ): string {
        $value = sanitize_text_field( (string) $value );
        if ( preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
            return $value . ':00';
        }
        return preg_match( '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value ) ? $value : '';
    }

    private static function apply_festival_bounds( array &$candidate ): void {
        $starts = array();
        $ends = array();
        foreach ( $candidate['festival_slots'] as $slot ) {
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $slot['date'] ?? '' ) ) ) {
                continue;
            }
            $starts[] = $slot['date'] . ' ' . ( $slot['start_time'] ?: '00:00:00' );
            $ends[] = ( $slot['end_date'] ?: $slot['date'] ) . ' ' . ( $slot['end_time'] ?: $slot['start_time'] ?: '23:59:59' );
        }
        sort( $starts, SORT_STRING );
        sort( $ends, SORT_STRING );
        if ( $starts ) {
            list( $candidate['start_date'], $candidate['start_time'] ) = explode( ' ', reset( $starts ), 2 );
        }
        if ( $ends ) {
            list( $candidate['end_date'], $candidate['end_time'] ) = explode( ' ', end( $ends ), 2 );
        }
        $candidate['all_day'] = false;
        $candidate['recurrence_mode'] = 'single';
    }

    /**
     * Calendar export resources are event-data sources, not purchase links.
     */
    private static function ticket_url( $value ): string {
        $url = GI_Utils::clean_url( (string) $value );
        if ( '' === $url ) {
            return '';
        }
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        $query = strtolower( (string) wp_parse_url( $url, PHP_URL_QUERY ) );
        if (
            'webcal' === $scheme
            || preg_match( '/\.(?:ics|ical)$/i', $path )
            || preg_match( '/(?:^|&)(?:format|output|type|download)=(?:ical|ics|calendar)(?:&|$)/i', $query )
        ) {
            return '';
        }
        return $url;
    }

    private static function has_value( $value ): bool {
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
            return true;
        }
        return '' !== trim( (string) $value );
    }
}
