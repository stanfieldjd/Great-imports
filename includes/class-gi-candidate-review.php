<?php
/**
 * Reviewer overrides and Events Manager location suggestions for candidates.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Candidate_Review {
    /**
     * Save reviewer-editable fields without changing raw source evidence.
     *
     * @param int $candidate_id Candidate post ID.
     * @param array<string,mixed> $data Submitted review data.
     * @return array{success:bool,message:string}
     */
    public static function save( $candidate_id, array $data ) {
        $candidate_id = absint( $candidate_id );
        if ( ! $candidate_id || 'gi_candidate' !== get_post_type( $candidate_id ) ) {
            return array(
                'success' => false,
                'message' => __( 'Candidate review was not saved because the candidate could not be found.', 'great-imports' ),
            );
        }

        $fields = array(
            'title'                => 'text',
            'start_date'           => 'text',
            'end_date'             => 'text',
            'timezone'             => 'text',
            'location_name'        => 'text',
            'location_address_1'   => 'text',
            'location_address_2'   => 'text',
            'location_city'        => 'text',
            'location_state'       => 'text',
            'location_postal_code' => 'text',
            'location_country'     => 'text',
            'stage_room'           => 'text',
            'ticket_url'           => 'url',
            'price'                => 'text',
            'price_currency'       => 'text',
            'reviewer_notes'       => 'textarea',
            'review_status'        => 'key',
            'location_decision'    => 'key',
            'address_verification' => 'key',
            'em_location_id'       => 'absint',
        );

        foreach ( $fields as $field => $type ) {
            $raw   = isset( $data[ $field ] ) ? $data[ $field ] : '';
            $value = self::sanitize_review_value( $raw, $type );
            update_post_meta( $candidate_id, '_gi_review_' . sanitize_key( $field ), $value );
        }

        update_post_meta( $candidate_id, '_gi_reviewed_at', current_time( 'mysql' ) );
        update_post_meta( $candidate_id, '_gi_reviewed_by', get_current_user_id() );

        return array(
            'success' => true,
            'message' => __( 'Candidate review saved. Raw source evidence was left unchanged.', 'great-imports' ),
        );
    }

    /**
     * Return reviewer override when present; otherwise return source candidate meta.
     *
     * @param int $candidate_id Candidate post ID.
     * @param string $key Review key.
     * @param string $source_key Source meta key. Defaults to the review key.
     * @param string $default Default value.
     */
    public static function value( $candidate_id, $key, $source_key = '', $default = '' ) {
        $candidate_id = absint( $candidate_id );
        $key          = sanitize_key( $key );
        $source_key   = '' !== $source_key ? sanitize_key( $source_key ) : $key;

        $review_value = get_post_meta( $candidate_id, '_gi_review_' . $key, true );
        if ( ! is_array( $review_value ) && ! is_object( $review_value ) && '' !== trim( (string) $review_value ) ) {
            return sanitize_text_field( (string) $review_value );
        }

        $source_value = get_post_meta( $candidate_id, '_gi_' . $source_key, true );
        if ( ! is_array( $source_value ) && ! is_object( $source_value ) && '' !== trim( (string) $source_value ) ) {
            return sanitize_text_field( (string) $source_value );
        }

        return sanitize_text_field( (string) $default );
    }

    /**
     * Return source meta without reviewer overrides.
     *
     * @param int $candidate_id Candidate post ID.
     * @param string $key Source key.
     */
    public static function source_value( $candidate_id, $key ) {
        $value = get_post_meta( absint( $candidate_id ), '_gi_' . sanitize_key( $key ), true );
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        return sanitize_text_field( (string) $value );
    }

    /**
     * Return reviewer override value only.
     *
     * @param int $candidate_id Candidate post ID.
     * @param string $key Review key.
     */
    public static function review_value( $candidate_id, $key ) {
        $value = get_post_meta( absint( $candidate_id ), '_gi_review_' . sanitize_key( $key ), true );
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        return sanitize_text_field( (string) $value );
    }

    /**
     * Candidate review status choices.
     *
     * @return array<string,string>
     */
    public static function review_status_options() {
        return array(
            'needs_review'       => __( 'Needs review', 'great-imports' ),
            'reviewed'           => __( 'Reviewed', 'great-imports' ),
            'ready_for_import'   => __( 'Ready for import later', 'great-imports' ),
            'needs_correction'   => __( 'Needs correction', 'great-imports' ),
            'blocked_or_partial' => __( 'Blocked / partial', 'great-imports' ),
        );
    }

    /**
     * Location decision choices.
     *
     * @return array<string,string>
     */
    public static function location_decision_options() {
        return array(
            'use_source_detected' => __( 'Use source-detected location/address', 'great-imports' ),
            'use_existing_em'     => __( 'Use selected existing Events Manager location', 'great-imports' ),
            'create_new_later'    => __( 'Create new Events Manager location later', 'great-imports' ),
            'needs_correction'    => __( 'Needs location/address correction', 'great-imports' ),
        );
    }

    /**
     * Address verification choices.
     *
     * @return array<string,string>
     */
    public static function address_verification_options() {
        return array(
            'source_looks_correct' => __( 'Source address looks correct', 'great-imports' ),
            'reviewer_verified'    => __( 'Reviewer verified address', 'great-imports' ),
            'incomplete'           => __( 'Address incomplete / needs correction', 'great-imports' ),
            'conflict'             => __( 'Address conflict found', 'great-imports' ),
        );
    }

    /**
     * Find possible Events Manager location matches. Read-only.
     *
     * @param int $candidate_id Candidate post ID.
     * @param int $limit Maximum suggestions.
     * @return array<int,array<string,string>>
     */
    public static function location_suggestions( $candidate_id, $limit = 8 ) {
        $candidate_id = absint( $candidate_id );
        $limit        = max( 1, min( 25, absint( $limit ) ) );

        $candidate = array(
            'name'     => self::value( $candidate_id, 'location_name' ),
            'address'  => self::value( $candidate_id, 'location_address_1' ),
            'city'     => self::value( $candidate_id, 'location_city' ),
            'state'    => self::value( $candidate_id, 'location_state' ),
            'postcode' => self::value( $candidate_id, 'location_postal_code' ),
            'country'  => self::value( $candidate_id, 'location_country' ),
        );

        $suggestions = self::suggestions_from_em_table( $candidate, $limit );
        if ( empty( $suggestions ) ) {
            $suggestions = self::suggestions_from_location_posts( $candidate, $limit );
        }

        return array_slice( $suggestions, 0, $limit );
    }

    /**
     * @param mixed $value Raw value.
     * @param string $type Sanitizer type.
     */
    private static function sanitize_review_value( $value, $type ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        $value = wp_unslash( (string) $value );

        if ( 'url' === $type ) {
            return esc_url_raw( $value );
        }

        if ( 'textarea' === $type ) {
            return sanitize_textarea_field( $value );
        }

        if ( 'key' === $type ) {
            return sanitize_key( $value );
        }

        if ( 'absint' === $type ) {
            return absint( $value );
        }

        return sanitize_text_field( $value );
    }

    /**
     * Read possible matches from Events Manager's location table.
     *
     * @param array<string,string> $candidate Candidate location values.
     * @param int $limit Max rows.
     * @return array<int,array<string,string>>
     */
    private static function suggestions_from_em_table( array $candidate, $limit ) {
        global $wpdb;

        $table = $wpdb->prefix . 'em_locations';
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $found !== $table ) {
            return array();
        }

        $clauses = array();
        $params  = array();

        if ( '' !== $candidate['name'] ) {
            $clauses[] = 'location_name LIKE %s';
            $params[]  = '%' . $wpdb->esc_like( $candidate['name'] ) . '%';
        }
        if ( '' !== $candidate['address'] ) {
            $clauses[] = 'location_address LIKE %s';
            $params[]  = '%' . $wpdb->esc_like( $candidate['address'] ) . '%';
        }
        if ( '' !== $candidate['postcode'] ) {
            $clauses[] = 'location_postcode LIKE %s';
            $params[]  = '%' . $wpdb->esc_like( $candidate['postcode'] ) . '%';
        }
        if ( '' !== $candidate['city'] ) {
            $clauses[] = 'location_town LIKE %s';
            $params[]  = '%' . $wpdb->esc_like( $candidate['city'] ) . '%';
        }

        if ( empty( $clauses ) ) {
            return array();
        }

        $sql = "SELECT location_id, location_name, location_address, location_town, location_state, location_postcode, location_country FROM {$table} WHERE (" . implode( ' OR ', $clauses ) . ') ORDER BY location_name ASC LIMIT %d';
        $params[] = absint( $limit );

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        if ( empty( $rows ) ) {
            return array();
        }

        $suggestions = array();
        foreach ( $rows as $row ) {
            $suggestions[] = array(
                'id'       => isset( $row['location_id'] ) ? (string) absint( $row['location_id'] ) : '',
                'name'     => isset( $row['location_name'] ) ? sanitize_text_field( (string) $row['location_name'] ) : '',
                'address'  => isset( $row['location_address'] ) ? sanitize_text_field( (string) $row['location_address'] ) : '',
                'city'     => isset( $row['location_town'] ) ? sanitize_text_field( (string) $row['location_town'] ) : '',
                'state'    => isset( $row['location_state'] ) ? sanitize_text_field( (string) $row['location_state'] ) : '',
                'postcode' => isset( $row['location_postcode'] ) ? sanitize_text_field( (string) $row['location_postcode'] ) : '',
                'country'  => isset( $row['location_country'] ) ? sanitize_text_field( (string) $row['location_country'] ) : '',
                'reason'   => self::match_reason( $candidate, array(
                    'name'     => isset( $row['location_name'] ) ? (string) $row['location_name'] : '',
                    'address'  => isset( $row['location_address'] ) ? (string) $row['location_address'] : '',
                    'city'     => isset( $row['location_town'] ) ? (string) $row['location_town'] : '',
                    'state'    => isset( $row['location_state'] ) ? (string) $row['location_state'] : '',
                    'postcode' => isset( $row['location_postcode'] ) ? (string) $row['location_postcode'] : '',
                ) ),
                'source'   => 'em_locations_table',
            );
        }

        return $suggestions;
    }

    /**
     * Fallback for installs where EM locations are represented as location posts.
     *
     * @param array<string,string> $candidate Candidate location values.
     * @param int $limit Max rows.
     * @return array<int,array<string,string>>
     */
    private static function suggestions_from_location_posts( array $candidate, $limit ) {
        $query = trim( implode( ' ', array_filter( array( $candidate['name'], $candidate['address'], $candidate['city'], $candidate['postcode'] ) ) ) );
        if ( '' === $query ) {
            return array();
        }

        $posts = get_posts(
            array(
                'post_type'      => 'location',
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => absint( $limit ),
                's'              => $query,
            )
        );

        $suggestions = array();
        foreach ( $posts as $post ) {
            $suggestions[] = array(
                'id'       => (string) absint( $post->ID ),
                'name'     => get_the_title( $post ),
                'address'  => sanitize_text_field( (string) get_post_meta( $post->ID, '_location_address', true ) ),
                'city'     => sanitize_text_field( (string) get_post_meta( $post->ID, '_location_town', true ) ),
                'state'    => sanitize_text_field( (string) get_post_meta( $post->ID, '_location_state', true ) ),
                'postcode' => sanitize_text_field( (string) get_post_meta( $post->ID, '_location_postcode', true ) ),
                'country'  => sanitize_text_field( (string) get_post_meta( $post->ID, '_location_country', true ) ),
                'reason'   => __( 'Possible Events Manager location post match', 'great-imports' ),
                'source'   => 'location_post_type',
            );
        }

        return $suggestions;
    }

    /**
     * @param array<string,string> $candidate Candidate values.
     * @param array<string,string> $match Match values.
     */
    private static function match_reason( array $candidate, array $match ) {
        $reasons = array();

        if ( self::same_text( $candidate['name'], $match['name'] ) ) {
            $reasons[] = __( 'same name', 'great-imports' );
        }
        if ( self::same_text( $candidate['address'], $match['address'] ) ) {
            $reasons[] = __( 'same address', 'great-imports' );
        }
        if ( self::same_text( $candidate['postcode'], $match['postcode'] ) ) {
            $reasons[] = __( 'same ZIP', 'great-imports' );
        }
        if ( self::same_text( $candidate['city'], $match['city'] ) ) {
            $reasons[] = __( 'same city', 'great-imports' );
        }

        if ( empty( $reasons ) ) {
            return __( 'possible partial match', 'great-imports' );
        }

        return implode( ', ', $reasons );
    }

    private static function same_text( $a, $b ) {
        $a = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $a ) ) );
        $b = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $b ) ) );

        return '' !== $a && '' !== $b && $a === $b;
    }
}
