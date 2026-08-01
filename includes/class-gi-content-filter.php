<?php

defined( 'ABSPATH' ) || exit;

/**
 * Context-aware screening for pornography and explicit nudity.
 *
 * The built-in classifier uses the text and metadata already collected for an
 * event. Sites may attach a remote text/image classifier through the
 * gi_explicit_content_classification filter without changing the import flow.
 */
final class GI_Content_Filter {
    public static function classify( array $candidate, ?array $settings = null ): array {
        $settings = $settings ?? GI_Storage::settings();
        $allowed = array(
            'decision'     => 'allow',
            'reason'       => '',
            'matched_terms'=> array(),
            'confidence'   => 'low',
        );

        if ( empty( $settings['explicit_content_filter_enabled'] ) || ! empty( $candidate['explicit_content_approved'] ) ) {
            return self::filtered_result( $allowed, $candidate, $settings );
        }
        if ( self::is_trusted_source( $candidate, (array) ( $settings['explicit_content_trusted_domains'] ?? array() ) ) ) {
            return self::filtered_result( $allowed, $candidate, $settings );
        }

        $text = self::candidate_text( $candidate );
        if ( '' === $text ) {
            return self::filtered_result( $allowed, $candidate, $settings );
        }

        $custom_matches = self::matching_phrases( $text, (array) ( $settings['explicit_content_custom_terms'] ?? array() ) );
        if ( $custom_matches ) {
            return self::filtered_result(
                array(
                    'decision'      => 'block',
                    /* translators: %s: administrator-configured phrase that matched the event. */
                    'reason'        => sprintf( __( 'Blocked by your explicit-content phrase: %s', 'great-imports' ), $custom_matches[0] ),
                    'matched_terms' => $custom_matches,
                    'confidence'    => 'high',
                ),
                $candidate,
                $settings
            );
        }

        $strong_phrases = array(
            'porn', 'pornography', 'pornographic', 'hardcore sex', 'explicit sex',
            'live sex show', 'sex show', 'adult video', 'adult film', 'xxx video',
            'xxx film', 'graphic sexual content', 'sexually explicit material',
            'explicit nudity', 'graphic nudity', 'full frontal nudity',
            'nude performers', 'naked performers', 'topless dancers',
            'nude revue', 'strip show', 'striptease show',
        );
        $strong_matches = self::matching_phrases( $text, $strong_phrases );
        if ( $strong_matches ) {
            return self::filtered_result(
                array(
                    'decision'      => 'block',
                    'reason'        => __( 'Blocked because the event context clearly describes pornography or explicit nudity.', 'great-imports' ),
                    'matched_terms' => $strong_matches,
                    'confidence'    => 'high',
                ),
                $candidate,
                $settings
            );
        }

        $nudity_terms = array( 'nude', 'nudity', 'naked', 'topless', 'striptease', 'strip club', 'nudist', 'naturist' );
        $sexual_terms = array( 'erotic', 'fetish', 'sexual', 'sex-positive', 'sex positive', 'onlyfans', 'adult entertainment', 'burlesque', 'lingerie' );
        $display_terms = array( 'show', 'performance', 'performer', 'dancer', 'model', 'video', 'film', 'movie', 'photos', 'photography', 'livestream', 'webcam' );
        $safe_context = array( 'art', 'artistic', 'museum', 'gallery', 'figure drawing', 'life drawing', 'medical', 'health', 'education', 'educational', 'history', 'documentary', 'breast cancer', 'childbirth' );

        $nudity_matches = self::matching_phrases( $text, $nudity_terms );
        $sexual_matches = self::matching_phrases( $text, $sexual_terms );
        $display_matches = self::matching_phrases( $text, $display_terms );
        $safe_matches = self::matching_phrases( $text, $safe_context );
        $matched = array_values( array_unique( array_merge( $nudity_matches, $sexual_matches ) ) );

        if ( ! $matched ) {
            return self::filtered_result( $allowed, $candidate, $settings );
        }

        $strict = 'strict' === sanitize_key( $settings['explicit_content_sensitivity'] ?? 'standard' );
        $likely_explicit = ( $nudity_matches && $display_matches ) || ( $nudity_matches && $sexual_matches );
        if ( $strict && $likely_explicit && ! $safe_matches ) {
            return self::filtered_result(
                array(
                    'decision'      => 'block',
                    'reason'        => __( 'Blocked by Strict filtering because the combined event context indicates explicit nudity or sexual material.', 'great-imports' ),
                    'matched_terms' => $matched,
                    'confidence'    => 'medium',
                ),
                $candidate,
                $settings
            );
        }

        $reason = $safe_matches
            ? __( 'Needs review: nudity or sexual wording appears in a possibly artistic, educational, historical, or health-related context.', 'great-imports' )
            : __( 'Needs review: the event context may describe pornography, nudity, or sexually explicit material.', 'great-imports' );
        return self::filtered_result(
            array(
                'decision'      => 'review',
                'reason'        => $reason,
                'matched_terms' => $matched,
                'confidence'    => $likely_explicit ? 'medium' : 'low',
            ),
            $candidate,
            $settings
        );
    }

    private static function candidate_text( array $candidate ): string {
        $parts = array(
            $candidate['title'] ?? '',
            $candidate['description'] ?? '',
            $candidate['organizer'] ?? '',
            $candidate['location_name'] ?? '',
            implode( ' ', (array) ( $candidate['categories'] ?? array() ) ),
            implode( ' ', (array) ( $candidate['tags'] ?? array() ) ),
            $candidate['event_url'] ?? '',
            $candidate['ticket_url'] ?? '',
            $candidate['image_url'] ?? '',
            implode( ' ', (array) ( $candidate['source_urls'] ?? array() ) ),
        );
        $text = html_entity_decode( wp_strip_all_tags( implode( ' ', array_filter( $parts ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return strtolower( trim( preg_replace( '/\s+/', ' ', $text ) ) );
    }

    private static function matching_phrases( string $text, array $phrases ): array {
        $matches = array();
        foreach ( $phrases as $phrase ) {
            $phrase = strtolower( trim( sanitize_text_field( (string) $phrase ) ) );
            if ( '' === $phrase ) {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu';
            if ( preg_match( $pattern, $text ) ) {
                $matches[] = $phrase;
            }
        }
        return array_values( array_unique( $matches ) );
    }

    private static function is_trusted_source( array $candidate, array $trusted_domains ): bool {
        $trusted = array_values( array_filter( array_map( static function ( $domain ): string {
            $domain = strtolower( trim( sanitize_text_field( (string) $domain ) ) );
            $domain = preg_replace( '#^https?://#', '', $domain );
            return trim( strtok( $domain, '/ ' ), '.' );
        }, $trusted_domains ) ) );
        if ( ! $trusted ) {
            return false;
        }
        $urls = array_merge(
            array( $candidate['event_url'] ?? '', $candidate['ticket_url'] ?? '' ),
            (array) ( $candidate['source_urls'] ?? array() )
        );
        foreach ( $urls as $url ) {
            $host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
            $host = preg_replace( '/^www\./', '', $host );
            foreach ( $trusted as $domain ) {
                if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function filtered_result( array $result, array $candidate, array $settings ): array {
        $result['matched_terms'] = array_values( array_unique( array_map( 'sanitize_text_field', (array) ( $result['matched_terms'] ?? array() ) ) ) );
        $result['decision'] = in_array( $result['decision'] ?? 'allow', array( 'allow', 'review', 'block' ), true ) ? $result['decision'] : 'review';
        $result['confidence'] = in_array( $result['confidence'] ?? 'low', array( 'low', 'medium', 'high' ), true ) ? $result['confidence'] : 'low';
        $result['reason'] = sanitize_text_field( $result['reason'] ?? '' );

        /**
         * Allows a site-specific contextual text/image service to replace or
         * refine the built-in classification.
         */
        $filtered = apply_filters( 'gi_explicit_content_classification', $result, $candidate, $settings );
        return is_array( $filtered ) ? wp_parse_args( $filtered, $result ) : $result;
    }
}
