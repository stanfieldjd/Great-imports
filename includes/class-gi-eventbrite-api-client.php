<?php
/**
 * Eventbrite API client.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Eventbrite_API_Client {
    const OPTION_PRIVATE_TOKEN = 'great_imports_eventbrite_private_token';

    /**
     * Determine whether a private token is configured.
     */
    public function has_private_token() {
        return '' !== $this->get_private_token();
    }

    /**
     * Return the stored private token.
     */
    public function get_private_token() {
        return trim( (string) get_option( self::OPTION_PRIVATE_TOKEN, '' ) );
    }

    /**
     * Save the private token in WordPress options.
     *
     * @param string $token Token value.
     */
    public function save_private_token( $token ) {
        $token = trim( sanitize_text_field( (string) $token ) );

        if ( '' === $token ) {
            return false;
        }

        delete_option( self::OPTION_PRIVATE_TOKEN );
        add_option( self::OPTION_PRIVATE_TOKEN, $token, '', 'no' );

        return true;
    }

    /**
     * Remove the stored private token.
     */
    public function clear_private_token() {
        delete_option( self::OPTION_PRIVATE_TOKEN );
    }

    /**
     * Fetch an Eventbrite event by numeric ID.
     *
     * @param string $event_id Eventbrite event ID.
     * @return array<string,mixed>
     */
    public function get_event( $event_id ) {
        $event_id = preg_replace( '/[^0-9]/', '', (string) $event_id );

        if ( '' === $event_id ) {
            return array(
                'success' => false,
                'event'   => array(),
                'status'  => 0,
                'error'   => __( 'Eventbrite event ID is missing.', 'great-imports' ),
                'headers' => array(),
            );
        }

        return $this->request_json(
            'https://www.eventbriteapi.com/v3/events/' . rawurlencode( $event_id ) . '/',
            array(
                'expand' => 'venue,ticket_availability,organizer,organizer.logo,category',
            ),
            'event'
        );
    }

    /**
     * Fetch a separate Eventbrite event description.
     *
     * @param string $event_id Eventbrite event ID.
     * @return array<string,mixed>
     */
    public function get_description( $event_id ) {
        $event_id = preg_replace( '/[^0-9]/', '', (string) $event_id );

        if ( '' === $event_id ) {
            return array(
                'success'     => false,
                'description' => '',
                'status'      => 0,
                'error'       => __( 'Eventbrite event ID is missing.', 'great-imports' ),
                'raw_payload' => array(),
                'headers'     => array(),
            );
        }

        $response = $this->request_json(
            'https://www.eventbriteapi.com/v3/events/' . rawurlencode( $event_id ) . '/description/',
            array(),
            'description'
        );

        if ( ! $response['success'] ) {
            return array(
                'success'     => false,
                'description' => '',
                'status'      => $response['status'],
                'error'       => $response['error'],
                'raw_payload' => $response['event'],
                'headers'     => $response['headers'],
            );
        }

        return array(
            'success'     => true,
            'description' => isset( $response['event']['description'] ) ? wp_kses_post( (string) $response['event']['description'] ) : '',
            'status'      => $response['status'],
            'error'       => '',
            'raw_payload' => $response['event'],
            'headers'     => $response['headers'],
        );
    }

    /**
     * Fetch additional raw Eventbrite evidence for an exploratory report.
     *
     * @param string              $event_id Eventbrite event ID.
     * @param array<string,mixed> $event_payload Already-fetched event payload.
     * @return array<string,array<string,mixed>>
     */
    public function get_related_exploratory_payloads( $event_id, array $event_payload ) {
        $event_id = preg_replace( '/[^0-9]/', '', (string) $event_id );
        $payloads = array();

        if ( '' === $event_id ) {
            return $payloads;
        }

        $payloads['ticket_classes'] = $this->request_endpoint(
            'ticket_classes',
            'events/' . $event_id . '/ticket_classes/',
            array()
        );

        $payloads['public_collections'] = $this->request_endpoint(
            'public_collections',
            'events/' . $event_id . '/collections/public/',
            array(
                'expand' => 'image',
            )
        );

        $venue_id = isset( $event_payload['venue_id'] ) ? preg_replace( '/[^0-9]/', '', (string) $event_payload['venue_id'] ) : '';
        if ( '' !== $venue_id ) {
            $payloads['venue'] = $this->request_endpoint(
                'venue',
                'venues/' . $venue_id . '/',
                array()
            );
        }

        $organizer_id = isset( $event_payload['organizer_id'] ) ? preg_replace( '/[^0-9]/', '', (string) $event_payload['organizer_id'] ) : '';
        if ( '' !== $organizer_id ) {
            $payloads['organizer'] = $this->request_endpoint(
                'organizer',
                'organizers/' . $organizer_id . '/',
                array()
            );
        }

        $category_id = isset( $event_payload['category_id'] ) ? preg_replace( '/[^0-9]/', '', (string) $event_payload['category_id'] ) : '';
        if ( '' !== $category_id ) {
            $payloads['category'] = $this->request_endpoint(
                'category',
                'categories/' . $category_id . '/',
                array()
            );
        }

        return $payloads;
    }

    /**
     * Execute a named API endpoint request for exploratory capture.
     *
     * @param string               $label Report label.
     * @param string               $path Eventbrite v3 path without leading slash.
     * @param array<string,string> $query Query args.
     * @return array<string,mixed>
     */
    private function request_endpoint( $label, $path, array $query ) {
        $path     = ltrim( $path, '/' );
        $endpoint = '/v3/' . $path;
        $response = $this->request_json( 'https://www.eventbriteapi.com/v3/' . $path, $query, $label );

        return array(
            'label'            => sanitize_key( $label ),
            'endpoint'         => $endpoint,
            'query'            => $query,
            'success'          => (bool) $response['success'],
            'status'           => (int) $response['status'],
            'error'            => sanitize_text_field( (string) $response['error'] ),
            'response_headers' => $response['headers'],
            'payload'          => $response['event'],
        );
    }

    /**
     * Execute an authenticated Eventbrite JSON request.
     *
     * @param string               $url URL.
     * @param array<string,string> $query Query args.
     * @param string               $payload_label Label for payload.
     * @return array<string,mixed>
     */
    private function request_json( $url, array $query, $payload_label ) {
        $token = $this->get_private_token();

        if ( '' === $token ) {
            return array(
                'success' => false,
                'event'   => array(),
                'status'  => 0,
                'error'   => __( 'Eventbrite private token is not configured.', 'great-imports' ),
                'headers' => array(),
            );
        }

        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'User-Agent'    => 'Great Imports/' . GREAT_IMPORTS_VERSION . '; ' . home_url( '/' ),
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'event'   => array(),
                'status'  => 0,
                'error'   => $response->get_error_message(),
                'headers' => array(),
            );
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $body    = (string) wp_remote_retrieve_body( $response );
        $json    = json_decode( $body, true );
        $headers = $this->headers_to_array( wp_remote_retrieve_headers( $response ) );

        if ( ! is_array( $json ) ) {
            return array(
                'success' => false,
                'event'   => array(
                    'raw_body'        => $body,
                    'raw_body_sha256' => hash( 'sha256', $body ),
                    'raw_body_bytes'  => strlen( $body ),
                ),
                'status'  => $status,
                'error'   => sprintf( __( 'Eventbrite API returned unreadable %s JSON.', 'great-imports' ), $payload_label ),
                'headers' => $headers,
            );
        }

        if ( $status < 200 || $status >= 300 || isset( $json['error'] ) || isset( $json['error_description'] ) ) {
            $error = '';

            if ( ! empty( $json['error_description'] ) ) {
                $error = sanitize_text_field( (string) $json['error_description'] );
            } elseif ( ! empty( $json['error'] ) ) {
                $error = sanitize_text_field( (string) $json['error'] );
            } elseif ( ! empty( $json['status_description'] ) ) {
                $error = sanitize_text_field( (string) $json['status_description'] );
            } else {
                $error = sprintf( __( 'Eventbrite API returned HTTP %d.', 'great-imports' ), $status );
            }

            $error = str_replace( 'OAuth token', 'Private token', $error );

            return array(
                'success' => false,
                'event'   => $json,
                'status'  => $status,
                'error'   => $error,
                'headers' => $headers,
            );
        }

        return array(
            'success' => true,
            'event'   => $json,
            'status'  => $status,
            'error'   => '',
            'headers' => $headers,
        );
    }

    /**
     * Convert and sanitize response headers.
     *
     * @param mixed $headers Headers.
     * @return array<string,mixed>
     */
    private function headers_to_array( $headers ) {
        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        }

        if ( ! is_array( $headers ) ) {
            return array();
        }

        $clean = array();
        foreach ( $headers as $key => $value ) {
            $lower = strtolower( (string) $key );
            if ( false !== strpos( $lower, 'authorization' ) || false !== strpos( $lower, 'token' ) || false !== strpos( $lower, 'secret' ) || false !== strpos( $lower, 'key' ) ) {
                $clean[ $key ] = '[redacted]';
            } elseif ( is_array( $value ) ) {
                $clean[ $key ] = array_map( 'sanitize_text_field', array_map( 'strval', $value ) );
            } else {
                $clean[ $key ] = sanitize_text_field( (string) $value );
            }
        }

        return $clean;
    }
}
