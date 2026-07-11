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
            'venue'  => __( 'Venue', 'great-imports' ),
            'source' => __( 'Source', 'great-imports' ),
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
            'venue'      => GI_Candidate_Review::value( $id, 'location_name' ),
            'source'     => GI_Candidate_Review::source_value( $id, 'source_type' ),
            'source_url' => (string) get_post_meta( $id, '_gi_source_url', true ),
            'excerpt'    => wp_trim_words( wp_strip_all_tags( $candidate->post_content ), 28 ),
        );
    }
}
