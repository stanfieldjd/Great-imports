<?php

defined( 'ABSPATH' ) || exit;

final class GI_Utils {
    public static function clean_url( string $url ): string {
        $url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( '' === $url ) {
            return '';
        }
        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        if ( ! $url ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return '';
        }
        $scheme = strtolower( $parts['scheme'] ?? 'https' );
        $host   = strtolower( $parts['host'] );
        $path   = $parts['path'] ?? '/';
        $path   = preg_replace( '#/+#', '/', $path );
        $path   = '/' === $path ? '/' : rtrim( $path, '/' );
        $query  = '';
        if ( ! empty( $parts['query'] ) ) {
            parse_str( $parts['query'], $args );
            foreach ( array_keys( $args ) as $key ) {
                if ( preg_match( '/^(utm_|fbclid$|gclid$|mc_|ref$|source$)/i', (string) $key ) ) {
                    unset( $args[ $key ] );
                }
            }
            if ( $args ) {
                ksort( $args );
                $query = '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
            }
        }
        return $scheme . '://' . $host . $path . $query;
    }

    public static function urls_from_text( string $text ): array {
        $items = preg_split( '/[\r\n,]+/', $text );
        $urls  = array();
        foreach ( $items ?: array() as $item ) {
            $url = self::clean_url( (string) $item );
            if ( $url ) {
                $urls[ $url ] = $url;
            }
        }
        return array_values( $urls );
    }

    public static function normalize_text( ?string $value ): string {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = remove_accents( $value );
        $value = strtolower( $value );
        $value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    public static function normalize_title( ?string $title ): string {
        $title = self::normalize_text( $title );
        $title = preg_replace( '/\b(tickets?|official|event|live)\b/', ' ', $title );
        return trim( preg_replace( '/\s+/', ' ', $title ) );
    }

    public static function normalize_address( ?string $address ): string {
        $address = self::normalize_text( $address );
        $replace = array(
            ' street ' => ' st ', ' avenue ' => ' ave ', ' road ' => ' rd ', ' boulevard ' => ' blvd ',
            ' drive ' => ' dr ', ' lane ' => ' ln ', ' highway ' => ' hwy ', ' suite ' => ' ste ',
            ' north ' => ' n ', ' south ' => ' s ', ' east ' => ' e ', ' west ' => ' w ',
        );
        return trim( strtr( ' ' . $address . ' ', $replace ) );
    }

    public static function sanitize_coordinate( $value, string $axis = '' ): string {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }
        $value = trim( (string) $value );
        if ( '' === $value || ! is_numeric( $value ) ) {
            return '';
        }
        $number = (float) $value;
        if ( 'latitude' === $axis && ( $number < -90 || $number > 90 ) ) {
            return '';
        }
        if ( 'longitude' === $axis && ( $number < -180 || $number > 180 ) ) {
            return '';
        }
        return rtrim( rtrim( sprintf( '%.6F', $number ), '0' ), '.' );
    }

    public static function has_coordinate_pair( array $candidate ): bool {
        $latitude = self::sanitize_coordinate( $candidate['location_latitude'] ?? '', 'latitude' );
        $longitude = self::sanitize_coordinate( $candidate['location_longitude'] ?? '', 'longitude' );
        if ( '' === $latitude || '' === $longitude ) {
            return false;
        }
        return 0.0 !== (float) $latitude || 0.0 !== (float) $longitude;
    }

    /**
     * Normalize a candidate's human location fields without inventing data.
     * This repairs common source strings that concatenate street, city, state,
     * postcode, and country into one address field.
     */
    public static function normalize_location_fields( array $candidate ): array {
        foreach ( array( 'location_name', 'location_address', 'location_city', 'location_state', 'location_postcode', 'location_country', 'parent_location_name', 'stage_name', 'title' ) as $field ) {
            $candidate[ $field ] = sanitize_text_field( (string) ( $candidate[ $field ] ?? '' ) );
        }
        $candidate['location_latitude'] = self::sanitize_coordinate( $candidate['location_latitude'] ?? '', 'latitude' );
        $candidate['location_longitude'] = self::sanitize_coordinate( $candidate['location_longitude'] ?? '', 'longitude' );

        $country = trim( (string) $candidate['location_country'] );
        if ( preg_match( '/^(?:united states(?: of america)?|u\.?s\.?a?\.?)$/i', $country ) ) {
            $candidate['location_country'] = 'US';
        } elseif ( $country ) {
            $candidate['location_country'] = strtoupper( $country );
        }

        $raw_address = trim( (string) $candidate['location_address'] );
        if ( $raw_address ) {
            $parsed = self::split_full_address( $raw_address );
            if ( $parsed ) {
                foreach ( array( 'location_address', 'location_city', 'location_state', 'location_postcode', 'location_country' ) as $field ) {
                    if ( ! self::has_meaningful_value( $candidate[ $field ] ?? '' ) && self::has_meaningful_value( $parsed[ $field ] ?? '' ) ) {
                        $candidate[ $field ] = $parsed[ $field ];
                    }
                }
                // The street field must never retain the city/state/country tail
                // once that tail has been parsed into dedicated fields.
                if ( ! empty( $parsed['location_address'] ) && $parsed['location_address'] !== $raw_address ) {
                    $candidate['location_address'] = $parsed['location_address'];
                }
            }
        }

        if ( empty( $candidate['location_country'] ) && preg_match( '/^[A-Z]{2}$/', (string) $candidate['location_state'] ) && preg_match( '/^\d{5}(?:-\d{4})?$/', (string) $candidate['location_postcode'] ) ) {
            $candidate['location_country'] = 'US';
        }
        $candidate['location_state'] = strtoupper( trim( (string) $candidate['location_state'] ) );
        $candidate['location_country'] = strtoupper( trim( (string) $candidate['location_country'] ) );

        $name = trim( (string) $candidate['location_name'] );
        if ( $name && ( empty( $candidate['parent_location_name'] ) || empty( $candidate['stage_name'] ) ) ) {
            $parts = self::location_name_parts( $name );
            if ( $parts['parent'] && $parts['stage'] ) {
                if ( empty( $candidate['parent_location_name'] ) ) {
                    $candidate['parent_location_name'] = $parts['parent'];
                }
                if ( empty( $candidate['stage_name'] ) ) {
                    $candidate['stage_name'] = $parts['stage'];
                }
            }
        }

        // Keep one canonical Events Manager venue internally. A stage, room,
        // or area is event metadata, not a second physical location record.
        if ( self::has_meaningful_value( $candidate['parent_location_name'] ?? '' ) && self::has_meaningful_value( $candidate['stage_name'] ?? '' ) ) {
            $candidate['location_name'] = sanitize_text_field( (string) $candidate['parent_location_name'] );
        }

        $candidate['title'] = self::strip_location_suffix_from_title(
            (string) $candidate['title'],
            array_filter( array( $candidate['location_name'], $candidate['parent_location_name'], self::public_location_name( $candidate ) ) )
        );
        return $candidate;
    }

    /**
     * Human-facing location label. The Events Manager location remains the
     * parent venue while a stage/room can be displayed as "Stage at Venue".
     */
    public static function public_location_name( array $candidate ): string {
        $parent = trim( sanitize_text_field( (string) ( $candidate['parent_location_name'] ?? '' ) ) );
        $stage  = trim( sanitize_text_field( (string) ( $candidate['stage_name'] ?? '' ) ) );
        if ( $parent && $stage ) {
            return sprintf( '%s at %s', $stage, $parent );
        }
        return trim( sanitize_text_field( (string) ( $candidate['location_name'] ?? $parent ?? '' ) ) );
    }

    /**
     * Split a source-provided US-style full address. Returns an empty array
     * when the structure cannot be determined confidently.
     */
    public static function split_full_address( string $address ): array {
        $value = trim( preg_replace( '/\s+/', ' ', html_entity_decode( $address, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        if ( '' === $value ) {
            return array();
        }
        $country = '';
        if ( preg_match( '/(?:,|\s)+(United States(?: of America)?|U\.?S\.?A?\.?)$/i', $value, $country_match ) ) {
            $country = 'US';
            $value = trim( substr( $value, 0, -strlen( $country_match[0] ) ), " ,\t\n\r\0\x0B" );
        }
        if ( ! preg_match( '/^(.*?)[,\s]+([A-Z]{2})[,\s]+(\d{5}(?:-\d{4})?)$/i', $value, $tail ) ) {
            return array();
        }
        $before = trim( $tail[1], " ,\t\n\r\0\x0B" );
        $state = strtoupper( $tail[2] );
        $postcode = $tail[3];
        $street = '';
        $city = '';
        $suffix = '(?:street|st|avenue|ave|road|rd|boulevard|blvd|drive|dr|lane|ln|highway|hwy|parkway|pkwy|place|pl|court|ct|circle|cir|trail|trl|terrace|ter|way|square|sq)\.?';
        if ( preg_match( '/^(.+?\b' . $suffix . '(?:\s+(?:suite|ste\.?|unit|#)\s*[A-Z0-9-]+)?)[,\s]+(.+)$/i', $before, $parts ) ) {
            $street = trim( $parts[1], " ,\t\n\r\0\x0B" );
            $city = trim( $parts[2], " ,\t\n\r\0\x0B" );
        } elseif ( preg_match( '/^(.+?),\s*([^,]+)$/', $before, $parts ) ) {
            $street = trim( $parts[1] );
            $city = trim( $parts[2] );
        } else {
            return array();
        }
        if ( ! $street || ! $city || preg_match( '/\d/', $city ) ) {
            return array();
        }
        return array(
            'location_address' => sanitize_text_field( $street ),
            'location_city' => sanitize_text_field( $city ),
            'location_state' => $state,
            'location_postcode' => $postcode,
            'location_country' => $country ?: 'US',
        );
    }

    public static function location_name_parts( ?string $name ): array {
        $name = trim( sanitize_text_field( (string) $name ) );
        if ( ! $name ) {
            return array( 'parent' => '', 'stage' => '', 'specific' => '' );
        }
        if ( preg_match( '/^(.+?)\s+(?:at|inside|within)\s+(.+)$/i', $name, $match ) && self::looks_like_stage_name( $match[1] ) ) {
            return array(
                'parent' => trim( $match[2] ),
                'stage' => trim( $match[1] ),
                'specific' => $name,
            );
        }
        return array( 'parent' => '', 'stage' => '', 'specific' => $name );
    }

    private static function looks_like_stage_name( string $name ): bool {
        return (bool) preg_match( '/\b(stage|room|hall|parlour|parlor|ballroom|theatre|theater|lounge|patio|rooftop|garden|pavilion|arena|club|studio|chapel|auditorium)\b/i', $name );
    }

    public static function location_names_compatible( ?string $left, ?string $right ): bool {
        $a = self::normalize_text( $left );
        $b = self::normalize_text( $right );
        if ( ! $a || ! $b ) {
            return true;
        }
        if ( $a === $b ) {
            return true;
        }
        $left_parts = self::location_name_parts( (string) $left );
        $right_parts = self::location_name_parts( (string) $right );
        $left_parent = self::normalize_text( $left_parts['parent'] );
        $right_parent = self::normalize_text( $right_parts['parent'] );
        if ( $left_parent && ( $left_parent === $b || ( $right_parent && $left_parent === $right_parent ) ) ) {
            return true;
        }
        if ( $right_parent && $right_parent === $a ) {
            return true;
        }
        return false;
    }

    public static function choose_specific_location_name( array $names ): string {
        $names = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $names ) ) ) );
        if ( ! $names ) {
            return '';
        }
        foreach ( $names as $left ) {
            foreach ( $names as $right ) {
                if ( ! self::location_names_compatible( $left, $right ) ) {
                    return '';
                }
            }
        }
        usort( $names, static function ( string $a, string $b ): int {
            $a_parts = self::location_name_parts( $a );
            $b_parts = self::location_name_parts( $b );
            $a_score = ( $a_parts['stage'] ? 1000 : 0 ) + strlen( $a );
            $b_score = ( $b_parts['stage'] ? 1000 : 0 ) + strlen( $b );
            return $b_score <=> $a_score;
        } );
        return $names[0];
    }

    public static function strip_location_suffix_from_title( string $title, array $locations ): string {
        $title = trim( sanitize_text_field( $title ) );
        foreach ( $locations as $location ) {
            $location = trim( sanitize_text_field( (string) $location ) );
            if ( ! $location ) {
                continue;
            }
            $pattern = '/\s*[—–-]\s*' . preg_quote( $location, '/' ) . '$/iu';
            $cleaned = trim( preg_replace( $pattern, '', $title ) );
            if ( $cleaned && self::normalize_text( $cleaned ) !== self::normalize_text( $location ) ) {
                $title = $cleaned;
            }
        }
        return $title;
    }

    public static function canonical_event_url( ?string $url ): string {
        $url = self::clean_url( (string) $url );
        if ( ! $url ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return '';
        }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
        $host = strtolower( (string) $parts['host'] );
        $host = preg_replace( '/^www\./i', '', $host );
        $path = preg_replace( '#/+#', '/', (string) ( $parts['path'] ?? '/' ) );
        $path = '/' === $path ? '/' : rtrim( $path, '/' );
        $query = array();
        if ( ! empty( $parts['query'] ) ) {
            parse_str( (string) $parts['query'], $query );
            foreach ( array_keys( $query ) as $key ) {
                if ( preg_match( '/^(?:format|output|ical|calendar|download)$/i', (string) $key ) ) {
                    $value = strtolower( trim( is_scalar( $query[ $key ] ) ? (string) $query[ $key ] : '' ) );
                    if ( in_array( $value, array( '', '1', 'true', 'yes', 'ical', 'ics', 'calendar' ), true ) ) {
                        unset( $query[ $key ] );
                    }
                }
            }
        }
        if ( $query ) {
            ksort( $query );
        }
        return $scheme . '://' . $host . $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
    }

    private static function event_specific_url_key( ?string $url ): string {
        $url = self::canonical_event_url( $url );
        if ( ! $url ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        $path = strtolower( rawurldecode( (string) ( $parts['path'] ?? '/' ) ) );
        $segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
        $last = $segments ? end( $segments ) : '';
        $generic = array( '', 'event', 'events', 'show', 'shows', 'ticket', 'tickets', 'concert', 'concerts', 'calendar', 'music' );
        $specific = count( $segments ) >= 2 && ! in_array( $last, $generic, true );
        if ( ! $specific && ! empty( $parts['query'] ) ) {
            parse_str( (string) $parts['query'], $args );
            foreach ( array( 'id', 'event', 'event_id', 'eid', 'eventId' ) as $key ) {
                if ( ! empty( $args[ $key ] ) ) {
                    $specific = true;
                    break;
                }
            }
        }
        if ( ! $specific ) {
            return '';
        }
        return $host . $path . ( ! empty( $parts['query'] ) ? '?' . $parts['query'] : '' );
    }

    public static function candidate_identity_key_sets( array $candidate ): array {
        $strong = array();
        $weak = array();
        foreach ( array( 'uid', 'event_id', 'source_event_id' ) as $key ) {
            $value = trim( (string) ( $candidate[ $key ] ?? '' ) );
            if ( '' !== $value ) {
                $strong[] = 'uid:' . hash( 'sha256', strtolower( $value ) );
            }
        }
        $urls = array_merge(
            array( $candidate['event_url'] ?? '', $candidate['ticket_url'] ?? '' ),
            (array) ( $candidate['source_urls'] ?? array() )
        );
        foreach ( $urls as $url ) {
            $key = self::event_specific_url_key( is_scalar( $url ) ? (string) $url : '' );
            if ( $key ) {
                $strong[] = 'url:' . hash( 'sha256', $key );
                $path = (string) wp_parse_url( self::canonical_event_url( (string) $url ), PHP_URL_PATH );
                $slug = self::normalize_text( rawurldecode( basename( rtrim( $path, '/' ) ) ) );
                $date = sanitize_text_field( (string) ( $candidate['start_date'] ?? '' ) );
                if ( $slug && $date ) {
                    $host = strtolower( (string) wp_parse_url( self::canonical_event_url( (string) $url ), PHP_URL_HOST ) );
                    $strong[] = 'slug-date:' . hash( 'sha256', $host . '|' . $slug . '|' . $date );
                }
            }
        }
        $title = self::normalize_title( $candidate['title'] ?? '' );
        $date = sanitize_text_field( (string) ( $candidate['start_date'] ?? '' ) );
        $time = substr( sanitize_text_field( (string) ( $candidate['start_time'] ?? '' ) ), 0, 5 );
        if ( $title && $date ) {
            $weak[] = 'title-date:' . hash( 'sha256', $title . '|' . $date );
            if ( $time ) {
                $weak[] = 'title-date-time:' . hash( 'sha256', $title . '|' . $date . '|' . $time );
            }
        }
        return array(
            'strong' => array_values( array_unique( $strong ) ),
            'weak' => array_values( array_unique( $weak ) ),
        );
    }

    public static function candidates_share_identity( array $left, array $right ): bool {
        $a = self::candidate_identity_key_sets( $left );
        $b = self::candidate_identity_key_sets( $right );
        if ( array_intersect( $a['strong'], $b['strong'] ) ) {
            return true;
        }
        if ( array_intersect( $a['weak'], $b['weak'] ) && self::candidates_have_compatible_occurrence( $left, $right ) ) {
            return true;
        }
        return false;
    }

    /**
     * Controlled bridge for listing/detail/iCal representations that use
     * different URLs or UIDs. Exact event time and compatible location
     * evidence are required, and distinct sibling stages remain separate.
     */
    public static function candidates_have_compatible_occurrence( array $left, array $right ): bool {
        $left = self::normalize_location_fields( $left );
        $right = self::normalize_location_fields( $right );
        if ( self::normalize_title( $left['title'] ?? '' ) !== self::normalize_title( $right['title'] ?? '' ) ) {
            return false;
        }
        if ( empty( $left['start_date'] ) || (string) $left['start_date'] !== (string) ( $right['start_date'] ?? '' ) ) {
            return false;
        }
        $left_time = substr( (string) ( $left['start_time'] ?? '' ), 0, 5 );
        $right_time = substr( (string) ( $right['start_time'] ?? '' ), 0, 5 );
        if ( $left_time && $right_time && $left_time !== $right_time ) {
            $left_method = sanitize_key( $left['method'] ?? '' );
            $right_method = sanitize_key( $right['method'] ?? '' );
            $listing_methods = array( 'listing_card', 'generic_card', 'listing_jsonld' );
            $one_is_listing = in_array( $left_method, $listing_methods, true ) !== in_array( $right_method, $listing_methods, true );
            // Listing cards commonly expose doors time while detail/ICS data
            // exposes show time. Merge that representation pair and let field
            // priority choose the authoritative time. Two independent detail
            // records at different times remain separate performances.
            if ( ! $one_is_listing ) {
                return false;
            }
        }
        $left_stage = self::normalize_text( $left['stage_name'] ?? '' );
        $right_stage = self::normalize_text( $right['stage_name'] ?? '' );
        if ( $left_stage && $right_stage && $left_stage !== $right_stage ) {
            return false;
        }
        $left_address = self::normalize_address( $left['location_address'] ?? '' );
        $right_address = self::normalize_address( $right['location_address'] ?? '' );
        if ( $left_address && $right_address && $left_address !== $right_address ) {
            return false;
        }
        if ( ! self::location_names_compatible( $left['location_name'] ?? '', $right['location_name'] ?? '' ) && ! ( $left_address && $left_address === $right_address ) ) {
            return false;
        }
        $left_hosts = self::candidate_source_hosts( $left );
        $right_hosts = self::candidate_source_hosts( $right );
        return (bool) array_intersect( $left_hosts, $right_hosts ) || ( $left_address && $left_address === $right_address );
    }

    private static function candidate_source_hosts( array $candidate ): array {
        $urls = array_merge( array( $candidate['event_url'] ?? '', $candidate['ticket_url'] ?? '' ), (array) ( $candidate['source_urls'] ?? array() ) );
        $hosts = array();
        foreach ( $urls as $url ) {
            $host = strtolower( (string) wp_parse_url( self::clean_url( is_scalar( $url ) ? (string) $url : '' ), PHP_URL_HOST ) );
            $host = preg_replace( '/^www\./', '', $host );
            if ( $host ) {
                $hosts[] = $host;
            }
        }
        return array_values( array_unique( $hosts ) );
    }

    public static function fingerprint( array $candidate ): string {
        $title    = self::normalize_title( $candidate['title'] ?? '' );
        $date     = (string) ( $candidate['start_date'] ?? '' );
        $location = self::normalize_text( $candidate['location_name'] ?? '' );
        $address  = self::normalize_address( $candidate['location_address'] ?? '' );
        return hash( 'sha256', implode( '|', array( $title, $date, $location, $address ) ) );
    }

    public static function source_uid( array $candidate ): string {
        foreach ( array( 'uid', 'event_id', 'source_event_id' ) as $key ) {
            if ( ! empty( $candidate[ $key ] ) ) {
                return sanitize_text_field( (string) $candidate[ $key ] );
            }
        }
        $url = self::canonical_event_url( (string) ( $candidate['event_url'] ?? '' ) );
        return $url ? hash( 'sha256', $url ) : '';
    }

    public static function parse_datetime( $value, ?DateTimeZone $timezone = null ): ?DateTimeImmutable {
        if ( $value instanceof DateTimeImmutable ) {
            return $value;
        }
        if ( $value instanceof DateTimeInterface ) {
            return DateTimeImmutable::createFromInterface( $value );
        }
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return null;
        }
        $timezone = $timezone ?: wp_timezone();
        $formats  = array(
            DateTimeInterface::ATOM,
            'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s',
            'Ymd\THis\Z', 'Ymd\THis', 'Ymd', 'Y-m-d',
            'm/d/Y g:i A', 'm/d/Y', 'F j, Y g:i A', 'M j, Y g:i A',
        );
        foreach ( $formats as $format ) {
            $tz = str_ends_with( $value, 'Z' ) ? new DateTimeZone( 'UTC' ) : $timezone;
            $dt = DateTimeImmutable::createFromFormat( '!' . $format, $value, $tz );
            if ( $dt instanceof DateTimeImmutable ) {
                return $dt->setTimezone( $timezone );
            }
        }
        try {
            return ( new DateTimeImmutable( $value, $timezone ) )->setTimezone( $timezone );
        } catch ( Throwable $e ) {
            return null;
        }
    }

    public static function date_parts( $start, $end = null, bool $all_day = false ): array {
        $timezone = wp_timezone();
        $start_dt = self::parse_datetime( $start, $timezone );
        $end_dt   = self::parse_datetime( $end, $timezone );
        if ( ! $start_dt ) {
            return array();
        }
        if ( ! $end_dt ) {
            $end_dt = $all_day ? $start_dt : $start_dt->modify( '+2 hours' );
        }
        if ( $end_dt < $start_dt ) {
            $end_dt = $start_dt;
        }
        return array(
            'start_date' => $start_dt->format( 'Y-m-d' ),
            'start_time' => $all_day ? '00:00:00' : $start_dt->format( 'H:i:s' ),
            'end_date'   => $end_dt->format( 'Y-m-d' ),
            'end_time'   => $all_day ? '23:59:59' : $end_dt->format( 'H:i:s' ),
            'all_day'    => $all_day,
            'timezone'   => $timezone->getName(),
        );
    }

    public static function sanitize_html( ?string $html ): string {
        $allowed = wp_kses_allowed_html( 'post' );
        $allowed['details'] = array( 'open' => true );
        $allowed['summary'] = array();
        return wp_kses( (string) $html, $allowed );
    }

    public static function absolute_url( string $base, string $href ): string {
        $href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( '' === $href || str_starts_with( $href, '#' ) || preg_match( '#^(mailto:|tel:|javascript:)#i', $href ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $href ) ) {
            return self::clean_url( $href );
        }
        $parts = wp_parse_url( $base );
        if ( empty( $parts['host'] ) ) {
            return '';
        }
        $scheme = $parts['scheme'] ?? 'https';
        if ( str_starts_with( $href, '//' ) ) {
            return self::clean_url( $scheme . ':' . $href );
        }
        if ( str_starts_with( $href, '/' ) ) {
            return self::clean_url( $scheme . '://' . $parts['host'] . $href );
        }
        $dir = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '/';
        return self::clean_url( $scheme . '://' . $parts['host'] . rtrim( $dir, '/' ) . '/' . $href );
    }

    public static function same_host( string $a, string $b ): bool {
        $ah = strtolower( (string) wp_parse_url( $a, PHP_URL_HOST ) );
        $bh = strtolower( (string) wp_parse_url( $b, PHP_URL_HOST ) );
        return $ah && $bh && preg_replace( '/^www\./', '', $ah ) === preg_replace( '/^www\./', '', $bh );
    }

    public static function apply_location_mappings( array $candidate, array $rules ): array {
        $detected_values = array_values( array_filter( array(
            self::normalize_text( $candidate['location_name'] ?? '' ),
            self::normalize_text( $candidate['parent_location_name'] ?? '' ),
            self::normalize_text( $candidate['stage_name'] ?? '' ),
        ) ) );
        foreach ( (array) ( $rules['location_mappings'] ?? array() ) as $mapping ) {
            if ( ! is_array( $mapping ) ) {
                continue;
            }
            $match = self::normalize_text( $mapping['match'] ?? '' );
            if ( ! $match || ! in_array( $match, $detected_values, true ) ) {
                continue;
            }
            $location_name = sanitize_text_field( $mapping['location_name'] ?? '' );
            $stage_name = sanitize_text_field( $mapping['stage_name'] ?? '' );
            $em_location_id = absint( $mapping['em_location_id'] ?? 0 );
            if ( $em_location_id ) {
                $candidate['em_location_id'] = $em_location_id;
            }
            if ( $stage_name ) {
                $parent = $location_name ?: sanitize_text_field( $candidate['parent_location_name'] ?? $candidate['location_name'] ?? '' );
                $candidate['parent_location_name'] = $parent;
                $candidate['stage_name'] = $stage_name;
                $candidate['location_name'] = $parent ?: $stage_name;
            } elseif ( $location_name ) {
                $candidate['location_name'] = $location_name;
            }
            break;
        }
        return $candidate;
    }

    public static function has_meaningful_value( $value ): bool {
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
            return true;
        }
        if ( is_array( $value ) ) {
            return ! empty( array_filter( $value, static fn( $item ) => self::has_meaningful_value( $item ) ) );
        }
        return '' !== trim( (string) $value );
    }

    public static function safe_json( $value ): string {
        return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    public static function status_label( string $status ): string {
        return array(
            'ready'    => __( 'Good to go', 'great-imports' ),
            'held'     => __( 'Needs your help', 'great-imports' ),
            'imported' => __( 'Added', 'great-imports' ),
            'updated'  => __( 'Updated', 'great-imports' ),
            'failed'   => __( 'Could not add', 'great-imports' ),
            'ignored'  => __( 'Skipped', 'great-imports' ),
            'blocked'  => __( 'Link needs help', 'great-imports' ),
        )[ $status ] ?? ucfirst( $status );
    }
}
