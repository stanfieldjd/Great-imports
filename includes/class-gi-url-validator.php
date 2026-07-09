<?php
/**
 * URL validation for one-time Eventbrite imports.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Url_Validator {
    /**
     * Validate an Eventbrite URL.
     *
     * @param string $url Raw URL.
     * @return array{valid:bool,url:string,error:string}
     */
    public function validate_eventbrite_url( $url ) {
        $url = trim( (string) $url );

        if ( '' === $url ) {
            return array(
                'valid' => false,
                'url'   => '',
                'error' => __( 'Enter an Eventbrite URL.', 'great-imports' ),
            );
        }

        $url = esc_url_raw( $url );
        $parts = wp_parse_url( $url );

        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return array(
                'valid' => false,
                'url'   => $url,
                'error' => __( 'The URL must include https:// and a valid host.', 'great-imports' ),
            );
        }

        if ( 'https' !== strtolower( $parts['scheme'] ) ) {
            return array(
                'valid' => false,
                'url'   => $url,
                'error' => __( 'Only HTTPS Eventbrite URLs are supported.', 'great-imports' ),
            );
        }

        $host = strtolower( $parts['host'] );
        $is_eventbrite = ( 'eventbrite.com' === $host || $this->ends_with( $host, '.eventbrite.com' ) );

        if ( ! $is_eventbrite ) {
            return array(
                'valid' => false,
                'url'   => $url,
                'error' => __( 'This one-time importer currently accepts Eventbrite URLs only.', 'great-imports' ),
            );
        }

        $url = $this->normalize_eventbrite_url( $url );

        return array(
            'valid' => true,
            'url'   => $url,
            'error' => '',
        );
    }

    /**
     * Normalize Eventbrite detail URLs by removing known tracking-only parameters.
     *
     * @param string $url Eventbrite URL.
     */
    private function normalize_eventbrite_url( $url ) {
        $url = preg_replace( '/#.*/', '', $url );

        $tracking_params = array(
            'aff',
            'aff_sub',
            'aff_sub2',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'mc_cid',
            'mc_eid',
            'fbclid',
            'gclid',
            '_gl',
            '_ga',
        );

        return esc_url_raw( remove_query_arg( $tracking_params, $url ) );
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
}
