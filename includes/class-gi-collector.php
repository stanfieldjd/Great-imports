<?php

defined( 'ABSPATH' ) || exit;

final class GI_Collector {
    private array $settings;
    private array $visited = array();
    private array $pages = array();
    private array $errors = array();
    private array $blocked = array();

    public function __construct( ?array $settings = null ) {
        $this->settings = $settings ?: GI_Storage::settings();
    }

    public function collect_urls( array $urls ): array {
        $raw_candidates = array();
        $per_url_limit = max( 1, (int) ( $this->settings['max_events_per_run'] ?? 100 ) );
        $total_limit = max( $per_url_limit, (int) ( $this->settings['max_total_events_per_run'] ?? 1000 ) );
        foreach ( $urls as $url ) {
            $url = GI_Utils::clean_url( (string) $url );
            if ( ! $url ) {
                continue;
            }
            $from_url = array_slice( $this->collect_root_url( $url ), 0, $per_url_limit );
            $raw_candidates = array_merge( $raw_candidates, $from_url );
            if ( count( $raw_candidates ) >= $total_limit ) {
                break;
            }
        }
        return array(
            'candidates' => array_slice( $raw_candidates, 0, $total_limit ),
            'pages'      => $this->pages,
            'errors'     => $this->errors,
            'blocked'    => $this->blocked,
        );
    }

    public function collect_file( string $path, string $filename = '' ): array {
        if ( ! is_readable( $path ) ) {
            return array( 'candidates' => array(), 'pages' => array(), 'blocked' => array(), 'errors' => array( __( 'Uploaded file could not be read.', 'great-imports' ) ) );
        }
        $content = (string) file_get_contents( $path );
        $extension = strtolower( pathinfo( $filename ?: $path, PATHINFO_EXTENSION ) );
        $candidates = array();
        if ( in_array( $extension, array( 'ics', 'ical' ), true ) || str_contains( $content, 'BEGIN:VCALENDAR' ) ) {
            $candidates = $this->parse_ics( $content, 'file://' . sanitize_file_name( $filename ?: basename( $path ) ) );
        } elseif ( 'csv' === $extension ) {
            $candidates = $this->parse_csv( $content, $filename );
        } elseif ( 'json' === $extension ) {
            $decoded = json_decode( $content, true );
            $candidates = $this->parse_json_upload( $decoded, $filename );
        } else {
            $this->errors[] = __( 'Only ICS, CSV, and JSON files are supported.', 'great-imports' );
        }
        return array(
            'candidates' => $candidates,
            'pages'      => array( array( 'url' => $filename, 'status' => 'file', 'content_type' => $extension ) ),
            'errors'     => $this->errors,
            'blocked'    => array(),
        );
    }

    private function collect_root_url( string $root_url ): array {
        $queue = array( array( 'url' => $root_url, 'depth' => 0, 'root' => $root_url ) );
        $all   = $this->collect_platform_api( $root_url );
        $max_pages = max( 1, (int) $this->settings['max_discovered_pages'] );
        $processed = 0;

        while ( $queue && $processed < $max_pages ) {
            $job = array_shift( $queue );
            $url = $job['url'];
            if ( isset( $this->visited[ $url ] ) ) {
                continue;
            }
            $this->visited[ $url ] = true;
            $processed++;
            $result = $this->fetch( $url );
            if ( empty( $result['ok'] ) ) {
                continue;
            }
            $body = $result['body'];
            $content_type = strtolower( $result['content_type'] );
            if ( str_contains( $content_type, 'text/calendar' ) || preg_match( '/\.(ics|ical)(?:\?|$)/i', $url ) || str_contains( substr( $body, 0, 500 ), 'BEGIN:VCALENDAR' ) ) {
                $all = array_merge( $all, $this->parse_ics( $body, $url ) );
                continue;
            }
            if ( str_contains( $content_type, 'json' ) ) {
                $decoded = json_decode( $body, true );
                $all = array_merge( $all, $this->parse_json_upload( $decoded, $url ) );
                continue;
            }
            if ( ! str_contains( $content_type, 'html' ) && ! preg_match( '/<html|<script|<article/i', substr( $body, 0, 1000 ) ) ) {
                continue;
            }

            $is_detail = $job['depth'] > 0 || (bool) preg_match( '#/(e|event|events|show|shows|ticket|tickets|concert|gig)/[^/]+#i', (string) wp_parse_url( $url, PHP_URL_PATH ) );
            $method = $is_detail ? 'detail_jsonld' : 'listing_jsonld';
            $jsonld = $this->extract_jsonld_events( $body, $url, $method );
            if ( $is_detail && $jsonld ) {
                $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
                foreach ( $jsonld as &$jsonld_event ) {
                    $page_image = $this->extract_detail_page_image( $body, $url, (string) ( $jsonld_event['title'] ?? '' ) );
                    if ( $page_image && ( empty( $jsonld_event['image_url'] ) || str_contains( $host, 'eventbrite.' ) ) ) {
                        $jsonld_event['image_url'] = $page_image;
                    }
                }
                unset( $jsonld_event );
            }
            $all = array_merge( $all, $jsonld );
            if ( $is_detail ) {
                $visible_event = $this->extract_visible_detail_event( $body, $url );
                if ( $visible_event ) {
                    $all[] = $visible_event;
                }
            }

            $generic_method = $is_detail ? 'detail_page' : 'listing_visible';
            $all = array_merge( $all, $this->extract_generic_events( $body, $url, $generic_method ) );
            $festival = $this->extract_visible_festival( $body, $url );
            if ( $festival ) {
                $all[] = $festival;
            }

            if ( 0 === (int) $job['depth'] ) {
                foreach ( $this->discover_event_links( $body, $url ) as $link ) {
                    if ( $processed + count( $queue ) >= $max_pages ) {
                        break;
                    }
                    if ( ! isset( $this->visited[ $link ] ) && GI_Utils::same_host( $root_url, $link ) ) {
                        $queue[] = array( 'url' => $link, 'depth' => 1, 'root' => $root_url );
                    }
                }
            }
        }
        return $all;
    }


    private function collect_platform_api( string $source_url ): array {
        $events = array();
        $host = strtolower( (string) wp_parse_url( $source_url, PHP_URL_HOST ) );

        if ( str_contains( $host, 'eventbrite.' ) && ! empty( $this->settings['eventbrite_token'] ) ) {
            $event_id = '';
            if ( preg_match( '~/e/(?:[^/?#]+-)?(\d+)(?:[/?#]|$)~i', $source_url, $match ) ) {
                $event_id = $match[1];
            }
            if ( $event_id ) {
                $api_url = 'https://www.eventbriteapi.com/v3/events/' . rawurlencode( $event_id ) . '/?expand=venue,organizer,ticket_availability';
                $payload = $this->fetch_api_json(
                    $api_url,
                    array( 'Authorization' => 'Bearer ' . $this->settings['eventbrite_token'] ),
                    $source_url,
                    'eventbrite_api'
                );
                if ( is_array( $payload ) && ! empty( $payload['name'] ) ) {
                    $venue = is_array( $payload['venue'] ?? null ) ? $payload['venue'] : array();
                    $address = is_array( $venue['address'] ?? null ) ? $venue['address'] : array();
                    $organizer = is_array( $payload['organizer'] ?? null ) ? $payload['organizer'] : array();
                    $ticket = is_array( $payload['ticket_availability'] ?? null ) ? $payload['ticket_availability'] : array();
                    $minimum = is_array( $ticket['minimum_ticket_price'] ?? null ) ? $ticket['minimum_ticket_price'] : array();
                    $raw = array(
                        'title'             => is_array( $payload['name'] ) ? ( $payload['name']['text'] ?? '' ) : $payload['name'],
                        'description'       => is_array( $payload['description'] ?? null ) ? ( $payload['description']['html'] ?? $payload['description']['text'] ?? '' ) : ( $payload['description'] ?? '' ),
                        'start'             => $payload['start']['utc'] ?? $payload['start']['local'] ?? '',
                        'end'               => $payload['end']['utc'] ?? $payload['end']['local'] ?? '',
                        'timezone'          => $payload['start']['timezone'] ?? '',
                        'event_url'         => $payload['url'] ?? $source_url,
                        'ticket_url'        => $payload['url'] ?? $source_url,
                        'price'             => $minimum['major_value'] ?? '',
                        'currency'          => $minimum['currency'] ?? '',
                        'image_url'         => $payload['logo']['original']['url'] ?? $payload['logo']['url'] ?? '',
                        'location_name'     => $venue['name'] ?? '',
                        'location_address'  => $address['address_1'] ?? '',
                        'location_city'     => $address['city'] ?? '',
                        'location_state'    => $address['region'] ?? '',
                        'location_postcode' => $address['postal_code'] ?? '',
                        'location_country'  => $address['country'] ?? '',
                        'location_latitude' => $venue['latitude'] ?? $address['latitude'] ?? '',
                        'location_longitude'=> $venue['longitude'] ?? $address['longitude'] ?? '',
                        'organizer'         => is_array( $organizer['name'] ?? null ) ? ( $organizer['name']['text'] ?? '' ) : ( $organizer['name'] ?? '' ),
                        'uid'               => 'eventbrite:' . ( $payload['id'] ?? $event_id ),
                        'categories'        => array_filter( array( $payload['category_id'] ?? '', $payload['subcategory_id'] ?? '' ) ),
                    );
                    $events[] = GI_Normalizer::from_raw( $raw, 'api', $source_url );
                }
            }
        }

        if ( str_contains( $host, 'ticketmaster.' ) && ! empty( $this->settings['ticketmaster_key'] ) ) {
            $event_id = '';
            if ( preg_match( '~/event/([A-Za-z0-9_-]+)(?:[/?#]|$)~i', $source_url, $match ) ) {
                $event_id = $match[1];
            }
            if ( $event_id ) {
                $api_url = add_query_arg( 'apikey', rawurlencode( $this->settings['ticketmaster_key'] ), 'https://app.ticketmaster.com/discovery/v2/events/' . rawurlencode( $event_id ) . '.json' );
                $payload = $this->fetch_api_json( $api_url, array(), $source_url, 'ticketmaster_api' );
                if ( is_array( $payload ) && ! empty( $payload['name'] ) ) {
                    $venue = $payload['_embedded']['venues'][0] ?? array();
                    $images = is_array( $payload['images'] ?? null ) ? $payload['images'] : array();
                    usort( $images, static fn( $a, $b ) => (int) ( $b['width'] ?? 0 ) <=> (int) ( $a['width'] ?? 0 ) );
                    $price_range = $payload['priceRanges'][0] ?? array();
                    $classification = $payload['classifications'][0] ?? array();
                    $categories = array_filter( array(
                        $classification['segment']['name'] ?? '',
                        $classification['genre']['name'] ?? '',
                        $classification['subGenre']['name'] ?? '',
                    ) );
                    $raw = array(
                        'title'             => $payload['name'] ?? '',
                        'description'       => $payload['info'] ?? $payload['pleaseNote'] ?? '',
                        'start'             => $payload['dates']['start']['dateTime'] ?? trim( ( $payload['dates']['start']['localDate'] ?? '' ) . ' ' . ( $payload['dates']['start']['localTime'] ?? '' ) ),
                        'end'               => $payload['dates']['end']['dateTime'] ?? trim( ( $payload['dates']['end']['localDate'] ?? '' ) . ' ' . ( $payload['dates']['end']['localTime'] ?? '' ) ),
                        'timezone'          => $payload['dates']['timezone'] ?? '',
                        'event_url'         => $payload['url'] ?? $source_url,
                        'ticket_url'        => $payload['url'] ?? $source_url,
                        'price'             => $price_range['min'] ?? '',
                        'currency'          => $price_range['currency'] ?? '',
                        'image_url'         => $images[0]['url'] ?? '',
                        'location_name'     => $venue['name'] ?? '',
                        'location_address'  => $venue['address']['line1'] ?? '',
                        'location_city'     => $venue['city']['name'] ?? '',
                        'location_state'    => $venue['state']['stateCode'] ?? $venue['state']['name'] ?? '',
                        'location_postcode' => $venue['postalCode'] ?? '',
                        'location_country'  => $venue['country']['countryCode'] ?? '',
                        'location_latitude' => $venue['location']['latitude'] ?? '',
                        'location_longitude'=> $venue['location']['longitude'] ?? '',
                        'uid'               => 'ticketmaster:' . ( $payload['id'] ?? $event_id ),
                        'categories'        => $categories,
                    );
                    $events[] = GI_Normalizer::from_raw( $raw, 'api', $source_url );
                }
            }
        }

        return $events;
    }

    /**
     * Recover a festival schedule from visible headings when a site publishes
     * no Event JSON-LD. Calendar dates may remain blank until review.
     */
    private function extract_visible_festival( string $html, string $source_url ): array {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return array();
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
        libxml_clear_errors();
        if ( ! $loaded ) {
            return array();
        }
        $xpath = new DOMXPath( $dom );
        $schedule_headings = $xpath->query(
            '//*[self::h1 or self::h2 or self::h3 or self::h4][contains(translate(normalize-space(.),"abcdefghijklmnopqrstuvwxyz","ABCDEFGHIJKLMNOPQRSTUVWXYZ"),"SCHEDULE")]'
        );
        if ( ! $schedule_headings || ! $schedule_headings->length ) {
            return array();
        }

        $schedule_parent = null;
        foreach ( $schedule_headings as $heading ) {
            $parent = $heading->parentNode;
            if ( ! $parent instanceof DOMElement ) {
                continue;
            }
            foreach ( $parent->childNodes as $child ) {
                if ( $child instanceof DOMElement && preg_match( '/^(?:MONDAY|TUESDAY|WEDNESDAY|THURSDAY|FRIDAY|SATURDAY|SUNDAY)\s+SCHEDULE\b/i', trim( (string) $child->textContent ) ) ) {
                    $schedule_parent = $parent;
                    break 2;
                }
            }
        }
        if ( ! $schedule_parent ) {
            return array();
        }

        $slots = array();
        $day = '';
        $pending_title = '';
        foreach ( $schedule_parent->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $text = trim( preg_replace( '/\s+/', ' ', (string) $child->textContent ) );
            if ( preg_match( '/^(MONDAY|TUESDAY|WEDNESDAY|THURSDAY|FRIDAY|SATURDAY|SUNDAY)\s+SCHEDULE\b/i', $text, $day_match ) ) {
                $day = ucfirst( strtolower( $day_match[1] ) );
                $pending_title = '';
                continue;
            }
            if ( ! $day ) {
                continue;
            }
            if ( preg_match( '/^h[1-6]$/i', $child->tagName ) ) {
                $pending_title = sanitize_text_field( $text );
                continue;
            }
            if ( $pending_title && preg_match( '/\b(\d{1,2}(?::\d{2})?\s*(?:AM|PM))\b/i', $text, $time_match ) ) {
                $slots[] = array(
                    'day_label'  => $day,
                    'start_time' => ( new DateTimeImmutable( $time_match[1], wp_timezone() ) )->format( 'H:i:s' ),
                    'title'      => $pending_title,
                );
                $pending_title = '';
            }
        }
        if ( count( $slots ) < 2 ) {
            return array();
        }
        for ( $index = 0, $count = count( $slots ); $index < $count - 1; $index++ ) {
            if ( $slots[ $index ]['day_label'] === $slots[ $index + 1 ]['day_label'] ) {
                $slots[ $index ]['end_time'] = $slots[ $index + 1 ]['start_time'];
            }
        }

        $title = '';
        $title_nodes = $xpath->query( '//title' );
        if ( $title_nodes && $title_nodes->length ) {
            $title = trim( preg_replace( '/\s+/', ' ', (string) $title_nodes->item( 0 )->textContent ) );
            $parts = preg_split( '/\s+[—–-]\s+/', $title );
            foreach ( array_reverse( $parts ?: array() ) as $part ) {
                if ( preg_match( '/festival/i', $part ) ) {
                    $title = trim( $part );
                    break;
                }
            }
            $title = trim( preg_replace( '/^(?:20\d{2}\s+)?(?:LINE[\s-]?UP|SCHEDULE)\s+[—–-]\s+/i', '', $title ) );
        }
        if ( ! preg_match( '/festival/i', $title ) ) {
            return array();
        }

        $location = array();
        $json_nodes = $xpath->query( '//script[contains(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"ld+json")]' );
        $find_location = static function ( $value ) use ( &$find_location ): array {
            if ( ! is_array( $value ) ) {
                return array();
            }
            $types = array_map( 'strtolower', (array) ( $value['@type'] ?? array() ) );
            if ( array_intersect( $types, array( 'place', 'localbusiness', 'civicstructure' ) ) && ! empty( $value['name'] ) ) {
                return $value;
            }
            foreach ( $value as $nested ) {
                $found = $find_location( $nested );
                if ( $found ) {
                    return $found;
                }
            }
            return array();
        };
        foreach ( $json_nodes ?: array() as $node ) {
            $location = $find_location( json_decode( trim( (string) $node->textContent ), true ) );
            if ( $location ) {
                break;
            }
        }
        $address = $location['address'] ?? array();
        if ( is_string( $address ) ) {
            $address = GI_Utils::split_full_address( $address );
        }
        $location_name = sanitize_text_field( $location['name'] ?? '' );
        foreach ( $slots as &$slot ) {
            $slot['location_name'] = $location_name;
        }
        unset( $slot );

        return GI_Normalizer::from_raw(
            array(
                'title'             => $title,
                'event_url'         => $source_url,
                'location_name'     => $location_name,
                'location_address'  => $address['streetAddress'] ?? $address['location_address'] ?? '',
                'location_city'     => $address['addressLocality'] ?? $address['location_city'] ?? '',
                'location_state'    => $address['addressRegion'] ?? $address['location_state'] ?? '',
                'location_postcode' => $address['postalCode'] ?? $address['location_postcode'] ?? '',
                'location_country'  => $address['addressCountry'] ?? $address['location_country'] ?? '',
                'structure'         => 'festival',
                'festival_annual'   => (bool) preg_match( '/\b20\d{2}\b/', $title ),
                'festival_slots'    => $slots,
                'categories'        => array( 'Festival' ),
                'uid'               => 'festival-visible:' . hash( 'sha256', strtolower( (string) wp_parse_url( $source_url, PHP_URL_HOST ) ) . '|' . GI_Utils::normalize_title( $title ) ),
            ),
            'festival_visible',
            $source_url
        );
    }

    private function fetch_api_json( string $api_url, array $headers, string $public_source_url, string $label ): ?array {
        $response = wp_safe_remote_get(
            $api_url,
            array(
                'timeout'     => (int) $this->settings['request_timeout'],
                'redirection' => 3,
                'headers'     => array_merge( array( 'Accept' => 'application/json' ), $headers ),
                'user-agent'  => 'Great Imports/' . GI_VERSION . '; ' . home_url( '/' ),
            )
        );
        if ( is_wp_error( $response ) ) {
            $this->errors[] = sprintf( '%s: %s', $label, $response->get_error_message() );
            $this->pages[] = array( 'url' => $public_source_url, 'status' => 'api_error', 'method' => $label );
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        $this->pages[] = array( 'url' => $public_source_url, 'status' => $code, 'content_type' => 'application/json', 'method' => $label, 'bytes' => strlen( $body ) );
        if ( $code < 200 || $code >= 300 ) {
            $this->errors[] = sprintf( '%s returned HTTP %d.', $label, $code );
            return null;
        }
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            $this->errors[] = sprintf( '%s returned invalid JSON.', $label );
            return null;
        }
        return $decoded;
    }

    private function fetch( string $url ): array {
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'     => (int) $this->settings['request_timeout'],
                'redirection' => 5,
                'headers'     => array(
                    'Accept'     => 'text/html,application/ld+json,application/json,text/calendar;q=0.9,*/*;q=0.5',
                    'User-Agent' => 'Great Imports/' . GI_VERSION . '; ' . home_url( '/' ),
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            $message = $response->get_error_message();
            $this->blocked[] = array( 'url' => $url, 'reason' => $message );
            $this->pages[] = array( 'url' => $url, 'status' => 'blocked', 'reason' => $message );
            return array( 'ok' => false );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        $type = (string) wp_remote_retrieve_header( $response, 'content-type' );
        $final_url = $url;
        $http_response = $response['http_response'] ?? null;
        if ( is_object( $http_response ) && method_exists( $http_response, 'get_response_object' ) ) {
            $response_object = $http_response->get_response_object();
            if ( is_object( $response_object ) && ! empty( $response_object->url ) ) {
                $final_url = GI_Utils::clean_url( (string) $response_object->url ) ?: $url;
            }
        }
        $this->pages[] = array(
            'url'          => $url,
            'final_url'    => $final_url,
            'status'       => $code,
            'content_type' => sanitize_text_field( $type ),
            'bytes'        => strlen( $body ),
        );
        if ( $code < 200 || $code >= 400 || '' === trim( $body ) ) {
            /* translators: %d: HTTP response status code. */
            $reason = sprintf( __( 'HTTP %d or empty response.', 'great-imports' ), $code );
            $this->blocked[] = array( 'url' => $url, 'reason' => $reason );
            return array( 'ok' => false );
        }
        return array( 'ok' => true, 'body' => $body, 'content_type' => $type, 'final_url' => $final_url );
    }

    private function extract_jsonld_events( string $html, string $source_url, string $method ): array {
        $json_blocks = array();
        if ( class_exists( 'DOMDocument' ) ) {
            $dom = new DOMDocument();
            libxml_use_internal_errors( true );
            $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
            libxml_clear_errors();
            if ( $loaded ) {
                $xpath = new DOMXPath( $dom );
                $nodes = $xpath->query( '//script[contains(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"ld+json")]' );
                foreach ( $nodes ?: array() as $node ) {
                    $json_blocks[] = trim( (string) $node->textContent );
                }
            }
        } elseif ( preg_match_all( '#<script[^>]*type=["\'][^"\']*ld\+json[^"\']*["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
            $json_blocks = array_map( static fn( $json ) => html_entity_decode( trim( (string) $json ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $matches[1] );
        }

        $events = array();
        foreach ( $json_blocks as $json ) {
            if ( '' === $json ) {
                continue;
            }
            $decoded = json_decode( $json, true );
            if ( null === $decoded ) {
                continue;
            }
            foreach ( $this->walk_jsonld( $decoded ) as $event ) {
                $raw = $this->jsonld_to_raw( $event, $source_url );
                if ( $raw['title'] && ( $raw['start'] || $raw['event_url'] ) ) {
                    $events[] = GI_Normalizer::from_raw( $raw, $method, $source_url );
                }
            }
        }
        return $events;
    }

    /**
     * Read the date, times, venue, address, ticket link, and image visibly
     * printed on an event detail page. This outranks stale structured data
     * when a website's JSON-LD or calendar link disagrees with its page.
     */
    private function extract_visible_detail_event( string $html, string $source_url ): array {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return array();
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
        libxml_clear_errors();
        if ( ! $loaded ) {
            return array();
        }
        $xpath = new DOMXPath( $dom );
        $heading = $xpath->query( '//main//h1' );
        $times = $xpath->query( '//main//time' );
        if ( ! $heading || ! $heading->length || ! $times || $times->length < 2 ) {
            return array();
        }
        $title = trim( preg_replace( '/\s+/', ' ', (string) $heading->item( 0 )->textContent ) );
        $date_text = trim( preg_replace( '/\s+/', ' ', (string) $times->item( 0 )->textContent ) );
        $start_time = trim( preg_replace( '/\s+/', ' ', (string) $times->item( 1 )->textContent ) );
        $end_time = $times->length > 2 ? trim( preg_replace( '/\s+/', ' ', (string) $times->item( 2 )->textContent ) ) : '';
        $start = trim( $date_text . ' ' . $start_time );
        $end = $end_time ? trim( $date_text . ' ' . $end_time ) : '';
        $start_dt = GI_Utils::parse_datetime( $start );
        $end_dt = GI_Utils::parse_datetime( $end );
        if ( $start_dt && $end_dt && $end_dt < $start_dt ) {
            $end = $end_dt->modify( '+1 day' )->format( DateTimeInterface::ATOM );
        }

        $venue_name = '';
        $venue_nodes = $xpath->query( '//main//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"address-line--title") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"venue-name")]' );
        if ( $venue_nodes && $venue_nodes->length ) {
            $venue_name = trim( preg_replace( '/\s+/', ' ', (string) $venue_nodes->item( 0 )->textContent ) );
        }
        $address_text = '';
        $map_links = $xpath->query( '//main//a[contains(@href,"maps.google.com") or contains(@href,"google.com/maps")]' );
        if ( $map_links && $map_links->length ) {
            $map_url = GI_Utils::absolute_url( $source_url, (string) $map_links->item( 0 )->getAttribute( 'href' ) );
            $query = array();
            parse_str( (string) wp_parse_url( $map_url, PHP_URL_QUERY ), $query );
            $address_text = trim( preg_replace( '/\s+/', ' ', (string) ( $query['q'] ?? $query['daddr'] ?? '' ) ) );
        }
        if ( ! $venue_name && $map_links && $map_links->length ) {
            $container_text = trim( preg_replace( '/\s+/', ' ', (string) $map_links->item( 0 )->parentNode->textContent ) );
            $without_map = trim( preg_replace( '/\(\s*map\s*\)/i', '', $container_text ) );
            if ( $address_text ) {
                $without_map = trim( str_ireplace( array( $address_text, str_replace( ',', '', $address_text ) ), '', $without_map ) );
            }
            $venue_name = sanitize_text_field( $without_map );
        }
        $address = GI_Utils::split_full_address( $address_text );

        $ticket_url = '';
        foreach ( $xpath->query( '//main//a[@href]' ) ?: array() as $link ) {
            $link_text = trim( preg_replace( '/\s+/', ' ', (string) $link->textContent ) );
            $href = GI_Utils::absolute_url( $source_url, (string) $link->getAttribute( 'href' ) );
            if ( $href && preg_match( '/tickets?|buy|more info/i', $link_text ) && ! GI_Utils::same_host( $source_url, $href ) ) {
                $ticket_url = $href;
                break;
            }
        }
        $image_url = '';
        $images = $xpath->query( '//main//figure//img | //main//img[@alt]' );
        if ( $images && $images->length ) {
            $image = $images->item( 0 );
            $image_url = GI_Utils::absolute_url( $source_url, (string) ( $image->getAttribute( 'src' ) ?: $image->getAttribute( 'data-src' ) ) );
        }
        $descriptions = array();
        foreach ( $xpath->query( '//main//article//p' ) ?: array() as $paragraph ) {
            $text = trim( preg_replace( '/\s+/', ' ', (string) $paragraph->textContent ) );
            if ( $text && ! preg_match( '/^doors?\s*:/i', $text ) ) {
                $descriptions[] = $dom->saveHTML( $paragraph );
            }
        }
        $raw = array_merge(
            array(
                'title' => $title,
                'start' => $start,
                'end' => $end,
                'event_url' => $source_url,
                'ticket_url' => $ticket_url,
                'image_url' => $image_url,
                'location_name' => $venue_name,
                'location_address' => $address_text,
                'description' => implode( "\n", array_slice( array_values( array_unique( $descriptions ) ), 0, 8 ) ),
            ),
            $address
        );
        return GI_Normalizer::from_raw( $raw, 'detail_visible', $source_url );
    }

    /**
     * Prefer the image currently rendered by a detail page. Eventbrite can
     * leave an expired signed image URL in JSON-LD while rendering a valid,
     * newly signed image URL in the page itself.
     */
    private function extract_detail_page_image( string $html, string $source_url, string $event_title ): string {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return '';
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
        libxml_clear_errors();
        if ( ! $loaded ) {
            return '';
        }
        $xpath = new DOMXPath( $dom );
        $source = '';
        $normalized_title = GI_Utils::normalize_title( $event_title );
        if ( $normalized_title ) {
            foreach ( $xpath->query( '//img[@src and @alt]' ) ?: array() as $image ) {
                if ( GI_Utils::normalize_title( (string) $image->getAttribute( 'alt' ) ) === $normalized_title ) {
                    $source = (string) $image->getAttribute( 'src' );
                    break;
                }
            }
        }
        if ( ! $source ) {
            $meta = $xpath->query( '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image"][@content] | //meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:image"][@content]' );
            if ( $meta && $meta->length ) {
                $source = (string) $meta->item( 0 )->getAttribute( 'content' );
            }
        }
        $source = html_entity_decode( trim( $source ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( ! $source ) {
            $source = '';
        }
        $absolute = $source ? GI_Utils::absolute_url( $source_url, $source ) : '';
        $host = strtolower( (string) wp_parse_url( $absolute, PHP_URL_HOST ) );
        $path = (string) wp_parse_url( $absolute, PHP_URL_PATH );
        if ( str_contains( $host, 'eventbrite.' ) && str_contains( $path, '/_next/image' ) ) {
            $query = array();
            parse_str( (string) wp_parse_url( $absolute, PHP_URL_QUERY ), $query );
            $proxied = GI_Utils::clean_url( (string) ( $query['url'] ?? '' ) );
            if ( $proxied ) {
                return $proxied;
            }
        }
        $source_host = strtolower( (string) wp_parse_url( $source_url, PHP_URL_HOST ) );
        if ( str_contains( $source_host, 'eventbrite.' ) ) {
            $rendered_image = $this->extract_embedded_eventbrite_image( $html );
            if ( $rendered_image ) {
                return $rendered_image;
            }
        }
        return GI_Utils::clean_url( $absolute );
    }

    /**
     * Eventbrite's server HTML can carry several encodings of the same image.
     * The live card image is normally the largest rendition; the smaller
     * JSON-LD rendition can retain an expired signature.
     */
    private function extract_embedded_eventbrite_image( string $html ): string {
        $decoded = html_entity_decode( str_replace( '\/', '/', $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $decoded = preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            static function ( array $match ): string {
                $code = hexdec( $match[1] );
                return $code <= 0x7f ? chr( $code ) : $match[0];
            },
            $decoded
        );
        $haystacks = array( $decoded );
        for ( $i = 0; $i < 2; $i++ ) {
            $decoded = rawurldecode( $decoded );
            $haystacks[] = $decoded;
        }
        $images = array();
        foreach ( $haystacks as $haystack ) {
            if ( ! preg_match_all( '#https://img\.evbuc\.com/[^\s"\'<>\\\\]+#i', $haystack, $matches ) ) {
                continue;
            }
            foreach ( $matches[0] as $match ) {
                $url = GI_Utils::clean_url( rtrim( (string) $match, '.,;:)]}' ) );
                if ( ! $url ) {
                    continue;
                }
                $query = array();
                parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
                $width = absint( $query['w'] ?? 0 );
                $score = $width + ( str_contains( $url, '/original.' ) ? 10000 : 0 );
                if ( ! isset( $images[ $url ] ) || $score > $images[ $url ] ) {
                    $images[ $url ] = $score;
                }
            }
        }
        if ( ! $images ) {
            return '';
        }
        arsort( $images, SORT_NUMERIC );
        return (string) array_key_first( $images );
    }

    private function walk_jsonld( $data ): array {
        $found = array();
        if ( ! is_array( $data ) ) {
            return $found;
        }
        $type = $data['@type'] ?? '';
        $types = is_array( $type ) ? $type : array( $type );
        $is_event = false;
        foreach ( $types as $one_type ) {
            if ( preg_match( '/(?:^|\b)(Event|MusicEvent|Festival|TheaterEvent|DanceEvent|ComedyEvent|EducationEvent|SportsEvent)(?:$|\b)/i', (string) $one_type ) ) {
                $found[] = $data;
                $is_event = true;
                break;
            }
        }
        foreach ( $data as $key => $value ) {
            if ( '@context' === $key || ( $is_event && 'subEvent' === $key ) ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $found = array_merge( $found, $this->walk_jsonld( $value ) );
            }
        }
        return $found;
    }

    private function jsonld_to_raw( array $event, string $source_url ): array {
        $location = is_array( $event['location'] ?? null ) ? $event['location'] : array();
        if ( isset( $location[0] ) && is_array( $location[0] ) ) {
            $location = $location[0];
        }
        $address = is_array( $location['address'] ?? null ) ? $location['address'] : array();
        $geo = is_array( $location['geo'] ?? null ) ? $location['geo'] : array();
        if ( ! $geo && is_array( $event['geo'] ?? null ) ) {
            $geo = $event['geo'];
        }
        $offers = is_array( $event['offers'] ?? null ) ? $event['offers'] : array();
        if ( isset( $offers[0] ) && is_array( $offers[0] ) ) {
            $offers = $offers[0];
        }
        $organizer = is_array( $event['organizer'] ?? null ) ? $event['organizer'] : array();
        $image = $event['image'] ?? '';
        if ( is_array( $image ) ) {
            if ( isset( $image['url'] ) ) {
                $image = $image['url'];
            } else {
                $image = reset( $image );
                if ( is_array( $image ) ) {
                    $image = $image['url'] ?? '';
                }
            }
        }
        $event_url = $event['url'] ?? $source_url;
        $categories = array();
        foreach ( array( 'eventType', 'additionalType' ) as $category_key ) {
            $category_value = $event[ $category_key ] ?? array();
            if ( is_string( $category_value ) ) {
                $category_value = preg_split( '/[,|]/', $category_value );
            }
            foreach ( (array) $category_value as $category ) {
                if ( is_scalar( $category ) && ! preg_match( '#^https?://#i', (string) $category ) ) {
                    $categories[] = trim( (string) $category );
                }
            }
        }
        $tags = $event['keywords'] ?? array();
        if ( is_string( $tags ) ) {
            $tags = preg_split( '/[,|]/', $tags );
        }
        $subevents = $event['subEvent'] ?? $event['subEvents'] ?? array();
        if ( isset( $subevents['@type'] ) || isset( $subevents['name'] ) ) {
            $subevents = array( $subevents );
        }
        $festival_slots = array();
        foreach ( (array) $subevents as $subevent ) {
            if ( ! is_array( $subevent ) ) {
                continue;
            }
            $sub_raw = $this->jsonld_to_raw( $subevent, $source_url );
            $festival_slots[] = array(
                'start'         => $sub_raw['start'] ?? '',
                'end'           => $sub_raw['end'] ?? '',
                'title'         => $sub_raw['title'] ?? '',
                'stage_name'    => $subevent['stage'] ?? $subevent['room'] ?? '',
                'location_name' => $sub_raw['location_name'] ?? '',
                'ticket_url'    => $sub_raw['ticket_url'] ?? '',
            );
        }
        $is_festival = (bool) $festival_slots;
        foreach ( $types = (array) ( $event['@type'] ?? array() ) as $one_type ) {
            if ( preg_match( '/Festival/i', (string) $one_type ) ) {
                $is_festival = true;
                break;
            }
        }
        return array(
            'title'             => $event['name'] ?? '',
            'description'       => $event['description'] ?? '',
            'start'             => $event['startDate'] ?? '',
            'end'               => $event['endDate'] ?? '',
            'all_day'           => isset( $event['startDate'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $event['startDate'] ),
            'event_url'         => is_string( $event_url ) ? $event_url : $source_url,
            'ticket_url'        => is_string( $offers['url'] ?? null ) ? (string) $offers['url'] : '',
            'price'             => $offers['price'] ?? $offers['lowPrice'] ?? '',
            'currency'          => $offers['priceCurrency'] ?? '',
            'image_url'         => is_string( $image ) ? $image : '',
            'location_name'     => $location['name'] ?? '',
            'location_address'  => $address['streetAddress'] ?? ( is_string( $location['address'] ?? null ) ? $location['address'] : '' ),
            'location_city'     => $address['addressLocality'] ?? '',
            'location_state'    => $address['addressRegion'] ?? '',
            'location_postcode' => $address['postalCode'] ?? '',
            'location_country'  => is_array( $address['addressCountry'] ?? null ) ? ( $address['addressCountry']['name'] ?? '' ) : ( $address['addressCountry'] ?? '' ),
            'location_latitude' => $geo['latitude'] ?? '',
            'location_longitude'=> $geo['longitude'] ?? '',
            'organizer'         => $organizer['name'] ?? '',
            'uid'               => $event['identifier'] ?? $event['@id'] ?? '',
            'categories'        => array_values( array_unique( array_filter( $categories ) ) ),
            'tags'              => array_values( array_unique( array_filter( array_map( 'trim', (array) $tags ) ) ) ),
            'structure'         => $is_festival ? 'festival' : 'auto',
            'festival_slots'    => $festival_slots,
        );
    }

    private function extract_generic_events( string $html, string $source_url, string $method ): array {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return array();
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
        libxml_clear_errors();
        if ( ! $loaded ) {
            return array();
        }
        $xpath = new DOMXPath( $dom );
        $query = '//article | //*[@itemtype and contains(@itemtype,"Event")] | //li[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"event")] | //div[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"event-card")] | //div[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"eventitem")] | //div[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"show-card")]';
        $nodes = $xpath->query( $query );
        $events = array();
        $seen = array();
        foreach ( $nodes ?: array() as $node ) {
            if ( count( $events ) >= (int) $this->settings['max_events_per_run'] ) {
                break;
            }
            $text = trim( preg_replace( '/\s+/', ' ', (string) $node->textContent ) );
            if ( strlen( $text ) < 8 || strlen( $text ) > 10000 ) {
                continue;
            }
            $time_nodes = $xpath->query( './/time', $node );
            $start = '';
            $end   = '';
            if ( $time_nodes && $time_nodes->length ) {
                $first_datetime = trim( (string) $time_nodes->item( 0 )->getAttribute( 'datetime' ) );
                $first_text = trim( preg_replace( '/\s+/', ' ', (string) $time_nodes->item( 0 )->textContent ) );
                if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $first_datetime ) && $time_nodes->length > 1 ) {
                    $date_text = $first_text ?: $first_datetime;
                    $start_clock = trim( preg_replace( '/\s+/', ' ', (string) $time_nodes->item( 1 )->textContent ) );
                    $end_clock = $time_nodes->length > 2 ? trim( preg_replace( '/\s+/', ' ', (string) $time_nodes->item( 2 )->textContent ) ) : '';
                    $start = trim( $date_text . ' ' . $start_clock );
                    $end = $end_clock ? trim( $date_text . ' ' . $end_clock ) : '';
                    $start_dt = GI_Utils::parse_datetime( $start );
                    $end_dt = GI_Utils::parse_datetime( $end );
                    if ( $start_dt && $end_dt && $end_dt < $start_dt ) {
                        $end = $end_dt->modify( '+1 day' )->format( DateTimeInterface::ATOM );
                    }
                } else {
                    $start = $first_datetime ?: $first_text;
                    if ( $time_nodes->length > 1 ) {
                        $end = (string) $time_nodes->item( 1 )->getAttribute( 'datetime' );
                        if ( ! $end ) {
                            $end = trim( preg_replace( '/\s+/', ' ', (string) $time_nodes->item( 1 )->textContent ) );
                        }
                    }
                }
            }
            if ( ! $start && preg_match( '/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}(?:st|nd|rd|th)?(?:,\s*\d{4})?(?:\s+(?:at\s+)?\d{1,2}(?::\d{2})?\s*(?:am|pm))?/i', $text, $match ) ) {
                $start = $match[0];
            } elseif ( ! $start && preg_match( '/\b\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}(?:\s+\d{1,2}:\d{2}\s*(?:am|pm)?)?/i', $text, $match ) ) {
                $start = $match[0];
            }
            if ( ! $start ) {
                continue;
            }
            $heading = $xpath->query( './/h1 | .//h2 | .//h3 | .//h4 | .//*[@itemprop="name"]', $node );
            $title = $heading && $heading->length ? trim( preg_replace( '/\s+/', ' ', (string) $heading->item( 0 )->textContent ) ) : '';
            $links = $xpath->query( './/a[@href]', $node );
            $event_url = '';
            $ticket_url = '';
            if ( $links && $links->length ) {
                foreach ( $links as $link ) {
                    $href = GI_Utils::absolute_url( $source_url, (string) $link->getAttribute( 'href' ) );
                    $anchor_text = trim( preg_replace( '/\s+/', ' ', (string) $link->textContent ) );
                    if ( ! $title && strlen( $anchor_text ) >= 4 ) {
                        $title = $anchor_text;
                    }
                    if ( ! $ticket_url && $href && ! GI_Utils::same_host( $source_url, $href ) && preg_match( '/tickets?|buy|more info/i', $anchor_text ) ) {
                        $ticket_url = $href;
                    }
                    if ( ! $event_url && $href && ( preg_match( '#/(event|events|show|shows|tickets?|concert|gig)/#i', $href ) || preg_match( '/ticket|details|learn more|event/i', $anchor_text ) ) ) {
                        $event_url = $href;
                    }
                }
            }
            if ( ! $title ) {
                continue;
            }
            $dedupe = GI_Utils::normalize_title( $title ) . '|' . GI_Utils::normalize_text( $start );
            if ( isset( $seen[ $dedupe ] ) ) {
                continue;
            }
            $seen[ $dedupe ] = true;
            $location_name = '';
            $location_title = $xpath->query( './/*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"address-line--title") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"venue-name")]', $node );
            if ( $location_title && $location_title->length ) {
                $location_name = trim( preg_replace( '/\s+/', ' ', (string) $location_title->item( 0 )->textContent ) );
            }
            if ( ! $location_name ) {
                $location_node = $xpath->query( './/*[@itemprop="location"] | .//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"venue")] | .//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"location")] | .//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"meta-address")]', $node );
                if ( $location_node && $location_node->length ) {
                    $location_name = trim( preg_replace( '/\s+/', ' ', (string) $location_node->item( 0 )->textContent ) );
                    $location_name = trim( preg_replace( '/\(\s*map\s*\)/i', '', $location_name ) );
                }
            }
            $address_text = '';
            $map_link = $xpath->query( './/a[contains(@href,"maps.google.com") or contains(@href,"google.com/maps")]', $node );
            if ( $map_link && $map_link->length ) {
                $map_url = GI_Utils::absolute_url( $source_url, (string) $map_link->item( 0 )->getAttribute( 'href' ) );
                $map_query = array();
                parse_str( (string) wp_parse_url( $map_url, PHP_URL_QUERY ), $map_query );
                $address_text = trim( preg_replace( '/\s+/', ' ', (string) ( $map_query['q'] ?? $map_query['daddr'] ?? '' ) ) );
            }
            $address = GI_Utils::split_full_address( $address_text );
            $description = '';
            $description_node = $xpath->query( './/*[@itemprop="description"] | .//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"description")]', $node );
            if ( $description_node && $description_node->length ) {
                $description = $dom->saveHTML( $description_node->item( 0 ) );
            }
            $image_url = '';
            $image_node = $xpath->query( './/img[@src or @data-src]', $node );
            if ( $image_node && $image_node->length ) {
                $image = $image_node->item( 0 );
                $image_url = GI_Utils::absolute_url( $source_url, (string) ( $image->getAttribute( 'src' ) ?: $image->getAttribute( 'data-src' ) ) );
            }
            $events[] = GI_Normalizer::from_raw(
                array_merge( array(
                    'title'         => $title,
                    'start'         => $start,
                    'end'           => $end,
                    'event_url'     => $event_url ?: $source_url,
                    'ticket_url'    => $ticket_url ?: $event_url,
                    'image_url'     => $image_url,
                    'location_name' => $location_name,
                    'location_address' => $address_text,
                    'description'   => $description,
                ), $address ),
                $method,
                $source_url
            );
        }
        return $events;
    }

    private function discover_event_links( string $html, string $source_url ): array {
        $embedded_links = $this->discover_embedded_event_links( $html, $source_url );
        if ( ! class_exists( 'DOMDocument' ) ) {
            $links = array_fill_keys( $embedded_links, true );
            if ( preg_match_all( '#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $href = GI_Utils::absolute_url( $source_url, html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                    $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $match[2] ) ) );
                    if ( ! $href || ! GI_Utils::same_host( $source_url, $href ) ) {
                        continue;
                    }
                    if ( $this->is_non_event_discovery_link( $href, $text ) ) {
                        continue;
                    }
                    if ( $this->is_event_discovery_candidate( $href, $text ) ) {
                        $links[ $href ] = true;
                    }
                }
            }
            return array_slice( array_keys( $links ), 0, max( 0, (int) $this->settings['max_discovered_pages'] - 1 ) );
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
        libxml_clear_errors();
        if ( ! $loaded ) {
            return array();
        }
        $links = array_fill_keys( $embedded_links, true );
        foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
            $href = GI_Utils::absolute_url( $source_url, (string) $anchor->getAttribute( 'href' ) );
            $text = trim( preg_replace( '/\s+/', ' ', (string) $anchor->textContent ) );
            if ( ! $href || ! GI_Utils::same_host( $source_url, $href ) ) {
                continue;
            }
            if ( $this->is_non_event_discovery_link( $href, $text ) ) {
                continue;
            }
            if ( $this->is_event_discovery_candidate( $href, $text ) ) {
                $links[ $href ] = true;
            }
        }
        return array_slice( array_keys( $links ), 0, max( 0, (int) $this->settings['max_discovered_pages'] - 1 ) );
    }

    private function discover_embedded_event_links( string $html, string $source_url ): array {
        $decoded = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $decoded = preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            static function ( array $match ): string {
                $code = hexdec( $match[1] );
                return $code <= 0x7f ? chr( $code ) : $match[0];
            },
            $decoded
        );
        $decoded = str_replace( '\/', '/', (string) $decoded );
        $matches = array();
        preg_match_all(
            '#(?:https?:)?//[^\s"\'<>\\\\]+/(?:e|event)/[^\s"\'<>\\\\]+|/(?:e|event)/[^\s"\'<>\\\\]+#i',
            $decoded,
            $matches
        );
        $links = array();
        foreach ( $matches[0] ?? array() as $match ) {
            $candidate = rtrim( (string) $match, '.,;:)]}' );
            if ( str_starts_with( $candidate, '//' ) ) {
                $scheme = wp_parse_url( $source_url, PHP_URL_SCHEME ) ?: 'https';
                $candidate = $scheme . ':' . $candidate;
            }
            $href = GI_Utils::absolute_url( $source_url, $candidate );
            if ( ! $href || ! GI_Utils::same_host( $source_url, $href ) ) {
                continue;
            }
            if ( $this->is_non_event_discovery_link( $href ) || ! $this->is_event_discovery_candidate( $href ) ) {
                continue;
            }
            $links[ $href ] = true;
        }
        return array_keys( $links );
    }

    private function is_non_event_discovery_link( string $href, string $text = '' ): bool {
        if ( preg_match( '#/(wp-admin|wp-login|privacy|terms|contact|about|cart|checkout|account)(?:/|$)#i', $href ) ) {
            return true;
        }

        $parts = wp_parse_url( $href );
        $host  = strtolower( (string) ( $parts['host'] ?? '' ) );
        $path  = '/' . ltrim( strtolower( (string) ( $parts['path'] ?? '' ) ), '/' );

        if ( str_contains( $host, 'eventbrite.' ) ) {
            if ( preg_match( '#^/(e|event)/#', $path ) ) {
                return false;
            }
            if ( preg_match( '#^/(signin|login|signup|mytickets|help|l|r|checkout|organizer/features)(?:/|$)#', $path ) ) {
                return true;
            }
            if ( preg_match( '/where are my tickets|sell tickets|online rsvp|sign in|log in|create account|help center/i', $text ) ) {
                return true;
            }
        }

        return false;
    }

    private function is_event_discovery_candidate( string $href, string $text = '' ): bool {
        $parts = wp_parse_url( $href );
        $host  = strtolower( (string) ( $parts['host'] ?? '' ) );
        $path  = '/' . ltrim( strtolower( (string) ( $parts['path'] ?? '' ) ), '/' );

        if ( str_contains( $host, 'eventbrite.' ) && preg_match( '#^/(e|event)/#', $path ) ) {
            return true;
        }

        return (bool) (
            preg_match( '#/(event|events|show|shows|tickets?|concert|concerts|gig|calendar|line-?up|schedule)/#i', $href )
            || preg_match( '/tickets?|event details|view event|learn more|buy now|rsvp|line-?up|schedule/i', $text )
        );
    }

    private function parse_ics( string $content, string $source_url ): array {
        $content = preg_replace( "/\r\n[ \t]/", '', $content );
        $content = str_replace( "\r", '', $content );
        preg_match_all( '/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $content, $matches );
        $events = array();
        foreach ( $matches[1] ?? array() as $block ) {
            $fields = array();
            foreach ( explode( "\n", $block ) as $line ) {
                if ( ! str_contains( $line, ':' ) ) {
                    continue;
                }
                list( $raw_key, $value ) = explode( ':', $line, 2 );
                $key_parts = explode( ';', $raw_key );
                $key = strtoupper( array_shift( $key_parts ) );
                $params = array();
                foreach ( $key_parts as $part ) {
                    if ( str_contains( $part, '=' ) ) {
                        list( $pk, $pv ) = explode( '=', $part, 2 );
                        $params[ strtoupper( $pk ) ] = $pv;
                    }
                }
                $fields[ $key ] = array( 'value' => $this->ics_unescape( $value ), 'params' => $params );
            }
            $start_value = $fields['DTSTART']['value'] ?? '';
            $end_value   = $fields['DTEND']['value'] ?? '';
            $timezone    = $fields['DTSTART']['params']['TZID'] ?? wp_timezone_string();
            $all_day     = ( $fields['DTSTART']['params']['VALUE'] ?? '' ) === 'DATE' || preg_match( '/^\d{8}$/', $start_value );
            $start = $this->ics_datetime( $start_value, $timezone );
            $end   = $this->ics_datetime( $end_value, $timezone );
            if ( $all_day && $end ) {
                $exclusive_end = GI_Utils::parse_datetime( $end );
                $start_day = GI_Utils::parse_datetime( $start );
                if ( $exclusive_end && $start_day && $exclusive_end > $start_day ) {
                    $end = $exclusive_end->modify( '-1 day' )->format( 'Y-m-d' );
                }
            }
            $url   = $fields['URL']['value'] ?? $source_url;
            $recurrence = $this->parse_rrule( $fields['RRULE']['value'] ?? '', $timezone );
            $raw = array(
                'title'         => $fields['SUMMARY']['value'] ?? '',
                'description'   => nl2br( esc_html( $fields['DESCRIPTION']['value'] ?? '' ) ),
                'start'         => $start,
                'end'           => $end,
                'all_day'       => $all_day,
                'event_url'     => $url,
                'ticket_url'    => $url,
                'location_name' => $fields['LOCATION']['value'] ?? '',
                'uid'           => $fields['UID']['value'] ?? '',
                'categories'    => isset( $fields['CATEGORIES']['value'] ) ? array_map( 'trim', explode( ',', $fields['CATEGORIES']['value'] ) ) : array(),
                'recurrence_rule'      => $fields['RRULE']['value'] ?? '',
                'recurrence_mode'      => $recurrence ? 'series' : 'single',
                'recurrence_frequency' => $recurrence['frequency'] ?? '',
                'recurrence_interval'  => $recurrence['interval'] ?? 1,
                'recurrence_until'     => $recurrence['until'] ?? '',
                'recurrence_count'     => $recurrence['count'] ?? 0,
                'recurrence_weekdays'  => $recurrence['weekdays'] ?? array(),
            );
            if ( $raw['title'] && $raw['start'] ) {
                $events[] = GI_Normalizer::from_raw( $raw, 'ics', $source_url );
            }
        }
        return $events;
    }

    private function parse_rrule( string $rule, string $timezone ): array {
        $rule = trim( $rule );
        if ( '' === $rule ) {
            return array();
        }
        $parts = array();
        foreach ( explode( ';', $rule ) as $part ) {
            if ( ! str_contains( $part, '=' ) ) {
                continue;
            }
            list( $key, $value ) = explode( '=', $part, 2 );
            $parts[ strtoupper( trim( $key ) ) ] = trim( $value );
        }
        $frequency_map = array( 'DAILY' => 'daily', 'WEEKLY' => 'weekly', 'MONTHLY' => 'monthly' );
        $frequency = $frequency_map[ strtoupper( $parts['FREQ'] ?? '' ) ] ?? '';
        if ( ! $frequency ) {
            return array();
        }
        $until = '';
        if ( ! empty( $parts['UNTIL'] ) ) {
            $parsed = $this->ics_datetime( $parts['UNTIL'], $timezone );
            $date = GI_Utils::parse_datetime( $parsed );
            $until = $date ? $date->format( 'Y-m-d' ) : '';
        }
        $allowed_days = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );
        $weekdays = array_values( array_intersect( $allowed_days, array_map( 'strtoupper', array_filter( array_map( 'trim', explode( ',', $parts['BYDAY'] ?? '' ) ) ) ) ) );
        return array(
            'frequency' => $frequency,
            'interval'  => min( 365, max( 1, absint( $parts['INTERVAL'] ?? 1 ) ) ),
            'until'     => $until,
            'count'     => min( 500, max( 0, absint( $parts['COUNT'] ?? 0 ) ) ),
            'weekdays'  => $weekdays,
        );
    }

    private function ics_datetime( string $value, string $timezone ): string {
        if ( '' === $value ) {
            return '';
        }
        if ( preg_match( '/^\d{8}$/', $value ) ) {
            return substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 );
        }
        $tz = wp_timezone();
        try {
            if ( $timezone && in_array( $timezone, timezone_identifiers_list(), true ) ) {
                $tz = new DateTimeZone( $timezone );
            }
        } catch ( Throwable $e ) {
            $tz = wp_timezone();
        }
        $dt = GI_Utils::parse_datetime( $value, $tz );
        return $dt ? $dt->format( DateTimeInterface::ATOM ) : $value;
    }

    private function ics_unescape( string $value ): string {
        return str_replace( array( '\\n', '\\N', '\\,', '\\;', '\\\\' ), array( "\n", "\n", ',', ';', '\\' ), $value );
    }

    private function parse_csv( string $content, string $filename ): array {
        $lines = preg_split( '/\r\n|\r|\n/', trim( $content ) );
        if ( ! is_array( $lines ) || array() === $lines ) {
            return array();
        }
        $headers = str_getcsv( (string) array_shift( $lines ), ',', '"', '\\' );
        if ( ! $headers ) {
            return array();
        }
        $headers = array_map( fn( $h ) => sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( trim( (string) $h ) ) ) ), $headers );
        $events = array();
        foreach ( $lines as $line ) {
            if ( '' === trim( (string) $line ) ) {
                continue;
            }
            $row = str_getcsv( (string) $line, ',', '"', '\\' );
            if ( array( null ) === $row ) {
                continue;
            }
            $row = array_pad( $row, count( $headers ), '' );
            $data = array_combine( $headers, array_slice( $row, 0, count( $headers ) ) );
            if ( ! is_array( $data ) ) {
                continue;
            }
            $start = trim( (string) ( $data['start'] ?? $data['start_date'] ?? $data['date'] ?? '' ) . ' ' . ( $data['start_time'] ?? $data['time'] ?? '' ) );
            $end   = trim( (string) ( $data['end'] ?? $data['end_date'] ?? '' ) . ' ' . ( $data['end_time'] ?? '' ) );
            $events[] = GI_Normalizer::from_raw(
                array(
                    'title' => $data['title'] ?? $data['name'] ?? '',
                    'description' => $data['description'] ?? '',
                    'start' => $start,
                    'end' => $end,
                    'all_day' => ! empty( $data['all_day'] ),
                    'event_url' => $data['event_url'] ?? $data['url'] ?? '',
                    'ticket_url' => $data['ticket_url'] ?? '',
                    'price' => $data['price'] ?? '',
                    'currency' => $data['currency'] ?? '',
                    'image_url' => $data['image_url'] ?? $data['image'] ?? '',
                    'location_name' => $data['location_name'] ?? $data['location'] ?? $data['venue'] ?? '',
                    'location_address' => $data['location_address'] ?? $data['address'] ?? '',
                    'location_city' => $data['location_city'] ?? $data['city'] ?? '',
                    'location_state' => $data['location_state'] ?? $data['state'] ?? '',
                    'location_postcode' => $data['location_postcode'] ?? $data['postcode'] ?? $data['zip'] ?? '',
                    'location_country' => $data['location_country'] ?? $data['country'] ?? '',
                    'location_latitude' => $data['location_latitude'] ?? $data['latitude'] ?? $data['lat'] ?? '',
                    'location_longitude' => $data['location_longitude'] ?? $data['longitude'] ?? $data['lng'] ?? $data['lon'] ?? '',
                    'parent_location_name' => $data['parent_location_name'] ?? $data['parent_venue'] ?? '',
                    'stage_name' => $data['stage_name'] ?? $data['stage'] ?? $data['room'] ?? '',
                    'structure' => $data['structure'] ?? $data['event_structure'] ?? '',
                    'festival_slots' => $data['festival_slots'] ?? $data['timeslots'] ?? $data['schedule'] ?? array(),
                    'organizer' => $data['organizer'] ?? $data['contact'] ?? '',
                    'uid' => $data['uid'] ?? $data['id'] ?? '',
                    'categories' => isset( $data['categories'] ) ? preg_split( '/[,|]/', $data['categories'] ) : array(),
                    'tags' => isset( $data['tags'] ) ? preg_split( '/[,|]/', $data['tags'] ) : array(),
                    'recurrence_mode' => $data['recurrence_mode'] ?? ( ! empty( $data['recurrence_frequency'] ) || ! empty( $data['rrule'] ) ? 'series' : 'single' ),
                    'recurrence_frequency' => $data['recurrence_frequency'] ?? $data['frequency'] ?? '',
                    'recurrence_interval' => $data['recurrence_interval'] ?? $data['interval'] ?? 1,
                    'recurrence_until' => $data['recurrence_until'] ?? $data['until'] ?? '',
                    'recurrence_count' => $data['recurrence_count'] ?? $data['count'] ?? 0,
                    'recurrence_weekdays' => isset( $data['recurrence_weekdays'] ) ? preg_split( '/[,|]/', $data['recurrence_weekdays'] ) : array(),
                    'recurrence_rule' => $data['rrule'] ?? '',
                ),
                'detail_page',
                'file://' . sanitize_file_name( $filename )
            );
        }
        return $events;
    }

    private function parse_json_upload( $decoded, string $source ): array {
        if ( ! is_array( $decoded ) ) {
            return array();
        }
        if ( isset( $decoded['events'] ) && is_array( $decoded['events'] ) ) {
            $decoded = $decoded['events'];
        } elseif ( isset( $decoded['@type'] ) ) {
            $decoded = array( $decoded );
        } elseif ( isset( $decoded['title'] ) || isset( $decoded['name'] ) || isset( $decoded['start'] ) || isset( $decoded['start_date'] ) ) {
            $decoded = array( $decoded );
        }
        $events = array();
        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            if ( isset( $item['@type'] ) ) {
                foreach ( $this->walk_jsonld( $item ) as $jsonld_event ) {
                    $events[] = GI_Normalizer::from_raw( $this->jsonld_to_raw( $jsonld_event, $source ), 'api', $source );
                }
            } else {
                $events[] = GI_Normalizer::from_raw( $item, 'api', $source );
            }
        }
        return array_values( array_filter( $events, fn( $event ) => ! empty( $event['title'] ) ) );
    }
}
