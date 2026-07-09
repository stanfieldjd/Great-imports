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
     * @return array{success:bool,event:array<string,mixed>,status:int,error:string}
     */
    public function get_event( $event_id ) {
        $event_id = preg_replace( '/[^0-9]/', '', (string) $event_id );

        if ( '' === $event_id ) {
            return array(
                'success' => false,
                'event'   => array(),
                'status'  => 0,
                'error'   => __( 'Eventbrite event ID is missing.', 'great-imports' ),
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
     * @return array{success:bool,description:string,status:int,error:string,raw_payload:array<string,mixed>}
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
            );
        }

        return array(
            'success'     => true,
            'description' => isset( $response['event']['description'] ) ? wp_kses_post( (string) $response['event']['description'] ) : '',
            'status'      => $response['status'],
            'error'       => '',
            'raw_payload' => $response['event'],
        );
    }

    /**
     * Execute an authenticated Eventbrite JSON request.
     *
     * @param string               $url URL.
     * @param array<string,string> $query Query args.
     * @param string               $payload_label Label for payload.
     * @return array{success:bool,event:array<string,mixed>,status:int,error:string}
     */
    private function request_json( $url, array $query, $payload_label ) {
        $token = $this->get_private_token();

        if ( '' === $token ) {
            return array(
                'success' => false,
                'event'   => array(),
                'status'  => 0,
                'error'   => __( 'Eventbrite private token is not configured.', 'great-imports' ),
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
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );
        $json   = json_decode( $body, true );

        if ( ! is_array( $json ) ) {
            return array(
                'success' => false,
                'event'   => array(
                    'raw_body_excerpt' => substr( $body, 0, 4000 ),
                ),
                'status'  => $status,
                'error'   => sprintf( __( 'Eventbrite API returned unreadable %s JSON.', 'great-imports' ), $payload_label ),
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
            );
        }

        return array(
            'success' => true,
            'event'   => $json,
            'status'  => $status,
            'error'   => '',
        );
    }
}
