<?php
/**
 * Native-style candidate list table for Great Imports.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class GI_Candidate_List_Table extends WP_List_Table {
    /** @var GI_Import_Preview_Builder */
    private $preview_builder;

    public function __construct( GI_Import_Preview_Builder $preview_builder ) {
        parent::__construct(
            array(
                'singular' => 'gi_candidate',
                'plural'   => 'gi_candidates',
                'ajax'     => false,
            )
        );

        $this->preview_builder = $preview_builder;
    }

    public function prepare_items() {
        $candidates = ( new GI_Candidate_Store() )->get_recent_candidates( 20 );

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            array(),
            'title',
        );

        $this->items = array_map( array( $this, 'candidate_to_item' ), $candidates );

        $this->set_pagination_args(
            array(
                'total_items' => count( $this->items ),
                'per_page'    => 20,
                'total_pages' => 1,
            )
        );
    }

    public function get_columns() {
        return array(
            'title'  => __( 'Title', 'great-imports' ),
            'date'   => __( 'Date', 'great-imports' ),
            'venue'             => __( 'Venue', 'great-imports' ),
            'matching_location' => __( 'Matching Location', 'great-imports' ),
            'source'            => __( 'Source', 'great-imports' ),
        );
    }

    public function get_item_count() {
        return count( $this->items );
    }

    public function no_items() {
        esc_html_e( 'No candidates have been collected yet.', 'great-imports' );
    }

    protected function get_table_classes() {
        $classes   = parent::get_table_classes();
        $classes[] = 'gi-candidate-table';

        return $classes;
    }

    protected function display_tablenav( $which ) {
        return;
    }

    protected function column_title( $item ) {
        $output = '<strong>' . esc_html( $item['title'] ? $item['title'] : __( '(no title)', 'great-imports' ) ) . '</strong>';

        if ( $item['excerpt'] ) {
            $output .= '<p class="gi-candidate-excerpt">' . esc_html( $item['excerpt'] ) . '</p>';
        }

        $actions = array();
        if ( $item['source_url'] ) {
            $actions['source'] = '<a href="' . esc_url( $item['source_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Source', 'great-imports' ) . '</a>';
        }

        return $output . $this->row_actions( $actions );
    }

    protected function column_venue( $item ) {
        $name    = isset( $item['venue'] ) ? trim( (string) $item['venue'] ) : '';
        $address = isset( $item['venue_address'] ) ? trim( (string) $item['venue_address'] ) : '';

        if ( '' === $name && '' === $address ) {
            return esc_html__( 'No venue', 'great-imports' );
        }

        $output = '' !== $name ? '<strong>' . esc_html( $name ) . '</strong>' : '';
        if ( '' !== $address ) {
            $output .= '<span class="gi-location-address">' . esc_html( $address ) . '</span>';
        }

        return $output;
    }

    protected function column_matching_location( $item ) {
        if ( empty( $item['matching_location'] ) || ! is_array( $item['matching_location'] ) ) {
            return esc_html__( 'No match', 'great-imports' );
        }

        $match   = $item['matching_location'];
        $name    = isset( $match['name'] ) ? trim( (string) $match['name'] ) : '';
        $address = $this->format_address( $match );

        $output = '' !== $name ? '<strong>' . esc_html( $name ) . '</strong>' : esc_html__( 'Matched location', 'great-imports' );
        if ( '' !== $address ) {
            $output .= '<span class="gi-location-address">' . esc_html( $address ) . '</span>';
        }

        return $output;
    }

    protected function column_default( $item, $column_name ) {
        if ( isset( $item[ $column_name ] ) && '' !== trim( (string) $item[ $column_name ] ) ) {
            return esc_html( (string) $item[ $column_name ] );
        }

        if ( 'date' === $column_name ) {
            return esc_html__( 'Not set', 'great-imports' );
        }

        if ( 'venue' === $column_name ) {
            return esc_html__( 'No venue', 'great-imports' );
        }

        return '&mdash;';
    }

    private function candidate_to_item( $candidate ) {
        $id      = (int) $candidate->ID;
        $preview = $this->preview_builder->build_for_candidate( $candidate );
        $date    = isset( $preview['public_event_fields']['start']['label'] ) ? (string) $preview['public_event_fields']['start']['label'] : GI_Candidate_Review::value( $id, 'start_date' );

        return array(
            'id'         => $id,
            'title'      => GI_Candidate_Review::value( $id, 'title', '', get_the_title( $candidate ) ),
            'date'       => $date,
            'venue'             => GI_Candidate_Review::value( $id, 'location_name' ),
            'venue_address'     => $this->candidate_address( $id ),
            'matching_location' => $this->matching_location( $id ),
            'source'            => GI_Candidate_Review::source_value( $id, 'source_type' ),
            'source_url' => (string) get_post_meta( $id, '_gi_source_url', true ),
            'excerpt'    => wp_trim_words( wp_strip_all_tags( $candidate->post_content ), 28 ),
        );
    }

    private function candidate_address( $candidate_id ) {
        return $this->format_address(
            array(
                'address'  => GI_Candidate_Review::value( $candidate_id, 'location_address_1' ),
                'address2' => GI_Candidate_Review::value( $candidate_id, 'location_address_2' ),
                'city'     => GI_Candidate_Review::value( $candidate_id, 'location_city' ),
                'state'    => GI_Candidate_Review::value( $candidate_id, 'location_state' ),
                'postcode' => GI_Candidate_Review::value( $candidate_id, 'location_postal_code' ),
                'country'  => GI_Candidate_Review::value( $candidate_id, 'location_country' ),
            )
        );
    }

    private function matching_location( $candidate_id ) {
        $selected_id = absint( GI_Candidate_Review::review_value( $candidate_id, 'em_location_id' ) );
        $suggestions = GI_Candidate_Review::location_suggestions( $candidate_id, 25 );

        if ( $selected_id ) {
            $selected = $this->selected_location_by_id( $selected_id );
            if ( ! empty( $selected ) ) {
                return $selected;
            }
        }

        foreach ( $suggestions as $suggestion ) {
            $reason = isset( $suggestion['reason'] ) ? strtolower( (string) $suggestion['reason'] ) : '';
            if ( false !== strpos( $reason, 'same address' ) || false !== strpos( $reason, 'same name' ) ) {
                return $suggestion;
            }
        }

        return array();
    }

    private function selected_location_by_id( $location_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'em_locations';
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        if ( $found === $table ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT location_id, location_name, location_address, location_town, location_state, location_postcode, location_country FROM {$table} WHERE location_id = %d LIMIT 1",
                    $location_id
                ),
                ARRAY_A
            );

            if ( is_array( $row ) ) {
                return array(
                    'id'       => (string) absint( $row['location_id'] ),
                    'name'     => sanitize_text_field( (string) $row['location_name'] ),
                    'address'  => sanitize_text_field( (string) $row['location_address'] ),
                    'city'     => sanitize_text_field( (string) $row['location_town'] ),
                    'state'    => sanitize_text_field( (string) $row['location_state'] ),
                    'postcode' => sanitize_text_field( (string) $row['location_postcode'] ),
                    'country'  => sanitize_text_field( (string) $row['location_country'] ),
                );
            }
        }

        $post = get_post( $location_id );
        if ( $post && 'location' === $post->post_type ) {
            return array(
                'id'       => (string) absint( $post->ID ),
                'name'     => get_the_title( $post ),
                'address'  => sanitize_text_field( (string) get_post_meta( $post->ID, '_location_address', true ) ),
                'city'     => sanitize_text_field( (string) get_post_meta( $post->ID, '_location_town', true ) ),
                'state'    => sanitize_text_field( (string) get_post_meta( $post->ID, '_location_state', true ) ),
                'postcode' => sanitize_text_field( (string) get_post_meta( $post->ID, '_location_postcode', true ) ),
                'country'  => sanitize_text_field( (string) get_post_meta( $post->ID, '_location_country', true ) ),
            );
        }

        return array();
    }

    private function format_address( array $location ) {
        $street = array_filter(
            array(
                isset( $location['address'] ) ? trim( (string) $location['address'] ) : '',
                isset( $location['address2'] ) ? trim( (string) $location['address2'] ) : '',
            )
        );
        $locality = array_filter(
            array(
                isset( $location['city'] ) ? trim( (string) $location['city'] ) : '',
                isset( $location['state'] ) ? trim( (string) $location['state'] ) : '',
                isset( $location['postcode'] ) ? trim( (string) $location['postcode'] ) : '',
            )
        );
        $parts = array_filter(
            array(
                implode( ', ', $street ),
                implode( ' ', $locality ),
                isset( $location['country'] ) ? trim( (string) $location['country'] ) : '',
            )
        );

        return implode( ', ', $parts );
    }
}
