<?php
/**
 * HTTP collection wrapper.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Http_Client {
    /**
     * Fetch a page body safely.
     *
     * @param string $url URL to fetch.
     * @return array{success:bool,body:string,status:int,error:string}
     */
    public function get_body( $url ) {
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 15,
                'redirection'         => 5,
                'limit_response_size' => 1024 * 1024,
                'headers'             => array(
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'User-Agent' => 'Great Imports/' . GREAT_IMPORTS_VERSION . '; ' . home_url( '/' ),
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'body'    => '',
                'status'  => 0,
                'error'   => $response->get_error_message(),
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( $status < 200 || $status >= 300 ) {
            return array(
                'success' => false,
                'body'    => $body,
                'status'  => $status,
                'error'   => sprintf( __( 'Eventbrite returned HTTP %d.', 'great-imports' ), $status ),
            );
        }

        if ( '' === trim( $body ) ) {
            return array(
                'success' => false,
                'body'    => '',
                'status'  => $status,
                'error'   => __( 'The Eventbrite page returned an empty response.', 'great-imports' ),
            );
        }

        return array(
            'success' => true,
            'body'    => $body,
            'status'  => $status,
            'error'   => '',
        );
    }
}
