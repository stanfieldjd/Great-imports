<?php
/**
 * Source-agnostic raw HTTP evidence capture.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_HTTP_Evidence_Client {
    /**
     * Capture a raw GET response as evidence.
     *
     * @param string               $url URL.
     * @param string               $label Evidence label.
     * @param array<string,string> $headers Request headers.
     * @return array<string,mixed>
     */
    public function capture_get( $url, $label = 'http_get', array $headers = array() ) {
        $url       = esc_url_raw( (string) $url );
        $started   = microtime( true );
        $timestamp = current_time( 'mysql' );

        $request_headers = array_merge(
            array(
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
                'User-Agent' => 'Great Imports/' . GREAT_IMPORTS_VERSION . '; ' . home_url( '/' ),
            ),
            $headers
        );

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'     => 25,
                'redirection' => 5,
                'limit_response_size' => 5242880,
                'headers'     => $request_headers,
            )
        );

        $duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return array(
                'label'              => sanitize_key( $label ),
                'capture_type'       => 'http_response',
                'captured_at'        => $timestamp,
                'url'                => $url,
                'request'            => array(
                    'method'  => 'GET',
                    'headers' => $this->sanitize_headers( $request_headers ),
                ),
                'success'            => false,
                'status'             => 0,
                'error'              => $response->get_error_message(),
                'duration_ms'        => $duration_ms,
                'response_headers'   => array(),
                'body'               => '',
                'body_sha256'        => '',
                'body_bytes'         => 0,
                'content_type'       => '',
            );
        }

        $body    = (string) wp_remote_retrieve_body( $response );
        $headers = wp_remote_retrieve_headers( $response );
        $status  = (int) wp_remote_retrieve_response_code( $response );

        return array(
            'label'              => sanitize_key( $label ),
            'capture_type'       => 'http_response',
            'captured_at'        => $timestamp,
            'url'                => $url,
            'request'            => array(
                'method'  => 'GET',
                'headers' => $this->sanitize_headers( $request_headers ),
            ),
            'success'            => ( $status >= 200 && $status < 300 ),
            'status'             => $status,
            'error'              => '',
            'duration_ms'        => $duration_ms,
            'response_headers'   => $this->headers_to_array( $headers ),
            'body'               => $body,
            'body_sha256'        => hash( 'sha256', $body ),
            'body_bytes'         => strlen( $body ),
            'content_type'       => $this->content_type( $headers ),
        );
    }

    /**
     * Convert WP headers to plain array.
     *
     * @param mixed $headers Headers object/array.
     * @return array<string,mixed>
     */
    private function headers_to_array( $headers ) {
        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        }

        if ( ! is_array( $headers ) ) {
            return array();
        }

        return $this->sanitize_headers( $headers );
    }

    /**
     * Redact secret-like headers.
     *
     * @param array<string,mixed> $headers Headers.
     * @return array<string,mixed>
     */
    private function sanitize_headers( array $headers ) {
        $clean = array();

        foreach ( $headers as $key => $value ) {
            $lower = strtolower( (string) $key );
            if ( false !== strpos( $lower, 'authorization' ) || false !== strpos( $lower, 'token' ) || false !== strpos( $lower, 'secret' ) || false !== strpos( $lower, 'key' ) ) {
                $clean[ $key ] = '[redacted]';
                continue;
            }

            if ( is_array( $value ) ) {
                $clean[ $key ] = array_map( 'sanitize_text_field', array_map( 'strval', $value ) );
            } else {
                $clean[ $key ] = sanitize_text_field( (string) $value );
            }
        }

        return $clean;
    }

    /**
     * Read content type from headers.
     *
     * @param mixed $headers Headers.
     */
    private function content_type( $headers ) {
        $array = $this->headers_to_array( $headers );

        foreach ( $array as $key => $value ) {
            if ( 'content-type' === strtolower( (string) $key ) ) {
                return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
            }
        }

        return '';
    }
}
