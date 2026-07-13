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

    /** @var array<int,array<string,string>> */
    private $em_locations = array();

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
        $candidates         = ( new GI_Candidate_Store() )->get_recent_candidates( 20 );
        $this->em_locations = GI_Candidate_Review::all_locations();

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
            'venue'             => __( 'Location', 'great-imports' ),
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
        $title  = $item['title'] ? $item['title'] : __( '(no title)', 'great-imports' );
        $output = $this->editor_open( $item, 'title', $title );
        $output .= '<label>' . esc_html__( 'Title', 'great-imports' ) . '<input type="text" name="title" value="' . esc_attr( $item['title'] ) . '" required></label>';
        $output .= $this->editor_close();

        if ( $item['excerpt'] ) {
            $output .= '<p class="gi-candidate-excerpt">' . esc_html( $item['excerpt'] ) . '</p>';
        }

        $actions = array();
        if ( $item['source_url'] ) {
            $actions['source'] = '<a href="' . esc_url( $item['source_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Source', 'great-imports' ) . '</a>';
        }

        return $output . $this->row_actions( $actions );
    }

    protected function column_date( $item ) {
        $output = $this->editor_open( $item, 'date', $item['date'] ? $item['date'] : __( 'Not set', 'great-imports' ) );
        $output .= $this->date_input( 'start_date', __( 'Start', 'great-imports' ), $item['start_date'] );
        $output .= $this->date_input( 'end_date', __( 'End', 'great-imports' ), $item['end_date'] );
        $output .= $this->editor_close();

        return $output;
    }

    protected function column_venue( $item ) {
        $name    = isset( $item['venue'] ) ? trim( (string) $item['venue'] ) : '';
        $address = isset( $item['venue_address'] ) ? trim( (string) $item['venue_address'] ) : '';
        $label   = '' !== $name ? $name : __( 'No venue', 'great-imports' );
        if ( '' !== $address ) {
            $label .= ' — ' . $address;
        }

        $output = $this->editor_open( $item, 'venue', $label );
        $fields = array(
            'location_name'        => __( 'Location name', 'great-imports' ),
            'location_address_1'   => __( 'Street address', 'great-imports' ),
            'location_address_2'   => __( 'Street address 2', 'great-imports' ),
            'location_city'        => __( 'City', 'great-imports' ),
            'location_state'       => __( 'State', 'great-imports' ),
            'location_postal_code' => __( 'ZIP', 'great-imports' ),
            'location_country'     => __( 'Country', 'great-imports' ),
        );
        foreach ( $fields as $field => $field_label ) {
            $output .= '<label>' . esc_html( $field_label ) . '<input type="text" name="' . esc_attr( $field ) . '" value="' . esc_attr( $item[ $field ] ) . '"></label>';
        }
        $output .= $this->editor_close();

        return $output;
    }

    protected function column_matching_location( $item ) {
        $selected_id     = isset( $item['em_location_id'] ) ? absint( $item['em_location_id'] ) : 0;
        $selection_label = isset( $item['em_location_id_source'] ) ? sanitize_key( (string) $item['em_location_id_source'] ) : '';
        $locations       = $this->locations_for_dropdown( $selected_id );
        $output = '<form class="gi-location-match-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        $output .= $this->form_context( $item['id'], 'location' );
        $output .= '<label class="screen-reader-text" for="gi-em-location-' . absint( $item['id'] ) . '">' . esc_html__( 'Matching Events Manager location', 'great-imports' ) . '</label>';
        $output .= '<select id="gi-em-location-' . absint( $item['id'] ) . '" name="em_location_id">';
        $output .= '<option value="0">' . esc_html__( 'No matching location selected', 'great-imports' ) . '</option>';
        foreach ( $locations as $location ) {
            $label = $location['name'];
            $address = $this->format_address( $location );
            if ( '' !== $address ) {
                $label .= ' — ' . $address;
            }
            if ( $selected_id === absint( $location['id'] ) && in_array( $selection_label, array( 'automatic_matching_location', 'imported_em_location' ), true ) ) {
                $label .= ' (' . __( 'auto match', 'great-imports' ) . ')';
            }
            $output .= '<option value="' . absint( $location['id'] ) . '"' . selected( $selected_id, absint( $location['id'] ), false ) . '>' . esc_html( $label ) . '</option>';
        }
        $output .= '</select>';
        $output .= '<button type="submit" class="button button-small">' . esc_html__( 'Save', 'great-imports' ) . '</button>';
        $output .= '</form>';

        return $output;
    }

    protected function column_source( $item ) {
        $output = esc_html( isset( $item['source'] ) ? $item['source'] : '' );
        if ( ! empty( $item['em_event_id'] ) ) {
            $output .= '<p class="gi-imported-status">' . sprintf( esc_html__( 'Imported: EM event #%d', 'great-imports' ), absint( $item['em_event_id'] ) ) . '</p>';
            if ( ! empty( $item['imported_at'] ) ) {
                $output .= '<p class="gi-imported-date">' . esc_html( $item['imported_at'] ) . '</p>';
            }
        }
        if ( ! empty( $item['em_recurring_event_id'] ) ) {
            $output .= '<p class="gi-imported-status">' . sprintf( esc_html__( 'Recurring: EM event #%d', 'great-imports' ), absint( $item['em_recurring_event_id'] ) ) . '</p>';
            if ( ! empty( $item['recurring_imported_at'] ) ) {
                $output .= '<p class="gi-imported-date">' . esc_html( $item['recurring_imported_at'] ) . '</p>';
            }
        }
        $button_label = ! empty( $item['em_event_id'] ) ? __( 'Update Events Manager', 'great-imports' ) : __( 'Import to Events Manager', 'great-imports' );
        $output .= '<form class="gi-import-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        $output .= '<input type="hidden" name="action" value="gi_import_candidate_to_em">';
        $output .= '<input type="hidden" name="candidate_id" value="' . absint( $item['id'] ) . '">';
        $output .= wp_nonce_field( 'gi_import_candidate_to_em_' . absint( $item['id'] ), '_wpnonce', true, false );
        $output .= '<button type="submit" class="button button-primary button-small">' . esc_html( $button_label ) . '</button>';
        $output .= '</form>';

        $recurring_button_label = ! empty( $item['em_recurring_event_id'] ) ? __( 'Update Recurring', 'great-imports' ) : __( 'Save Recurring', 'great-imports' );
        $output .= '<form class="gi-import-form gi-recurring-import-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        $output .= '<input type="hidden" name="action" value="gi_import_recurring_candidate_to_em">';
        $output .= '<input type="hidden" name="candidate_id" value="' . absint( $item['id'] ) . '">';
        $output .= wp_nonce_field( 'gi_import_recurring_candidate_to_em_' . absint( $item['id'] ), '_wpnonce', true, false );
        $output .= '<button type="submit" class="button button-small">' . esc_html( $recurring_button_label ) . '</button>';
        $output .= '</form>';

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
        $id       = (int) $candidate->ID;
        $preview  = $this->preview_builder->build_for_candidate( $candidate );
        $location = GI_Candidate_Review::normalized_location_fields( $id );
        $date     = isset( $preview['public_event_fields']['start']['label'] ) ? (string) $preview['public_event_fields']['start']['label'] : GI_Candidate_Review::value( $id, 'start_date' );
        $matching_location = $this->matching_location( $id );

        return array(
            'id'         => $id,
            'title'      => GI_Candidate_Review::value( $id, 'title', '', get_the_title( $candidate ) ),
            'date'               => $date,
            'start_date'         => GI_Candidate_Review::value( $id, 'start_date' ),
            'end_date'           => GI_Candidate_Review::value( $id, 'end_date' ),
            'venue'                => $location['name'],
            'location_name'        => $location['name'],
            'location_address_1'   => $location['address_1'],
            'location_address_2'   => $location['address_2'],
            'location_city'        => $location['city'],
            'location_state'       => $location['state'],
            'location_postal_code' => $location['postcode'],
            'location_country'     => $location['country'],
            'venue_address'      => $this->candidate_address( $id ),
            'matching_location'  => $matching_location,
            'em_location_id'     => $this->selected_location_id( $id, $matching_location ),
            'em_location_id_source' => $this->selected_location_id_source( $id, $matching_location ),
            'source'            => GI_Candidate_Review::source_value( $id, 'source_type' ),
            'source_url' => (string) get_post_meta( $id, '_gi_source_url', true ),
            'em_event_id' => absint( get_post_meta( $id, '_gi_em_event_id', true ) ),
            'imported_at' => sanitize_text_field( (string) get_post_meta( $id, '_gi_imported_at', true ) ),
            'em_recurring_event_id' => absint( get_post_meta( $id, '_gi_em_recurring_event_id', true ) ),
            'recurring_imported_at' => sanitize_text_field( (string) get_post_meta( $id, '_gi_recurring_imported_at', true ) ),
            'candidate_status' => sanitize_key( (string) get_post_meta( $id, '_gi_candidate_status', true ) ),
            'excerpt'    => wp_trim_words( wp_strip_all_tags( $candidate->post_content ), 28 ),
        );
    }

    private function editor_open( array $item, $group, $label ) {
        $output = '<details class="gi-inline-editor">';
        $output .= '<summary>' . esc_html( $label ) . '</summary>';
        $output .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        $output .= $this->form_context( $item['id'], $group );

        return $output;
    }

    private function editor_close() {
        return '<button type="submit" class="button button-small">' . esc_html__( 'Save', 'great-imports' ) . '</button></form></details>';
    }

    private function form_context( $candidate_id, $group ) {
        $output = '<input type="hidden" name="action" value="gi_save_candidate_field">';
        $output .= '<input type="hidden" name="candidate_id" value="' . absint( $candidate_id ) . '">';
        $output .= '<input type="hidden" name="field_group" value="' . esc_attr( $group ) . '">';
        $output .= wp_nonce_field( 'gi_save_candidate_field_' . absint( $candidate_id ), '_wpnonce', true, false );

        return $output;
    }

    private function date_input( $field, $label, $value ) {
        $date = '';
        $time = '';
        if ( preg_match( '/^(\d{4}-\d{2}-\d{2})(?:T|\s)(\d{2}:\d{2})/', (string) $value, $matches ) ) {
            $date = $matches[1];
            $time = $matches[2];
        }

        return '<fieldset><legend>' . esc_html( $label ) . '</legend><input type="date" name="' . esc_attr( $field . '_date' ) . '" value="' . esc_attr( $date ) . '"> <input type="time" name="' . esc_attr( $field . '_time' ) . '" value="' . esc_attr( $time ) . '"></fieldset>';
    }

    private function selected_location_id( $candidate_id, array $matching_location = array() ) {
        $selected = absint( GI_Candidate_Review::review_value( $candidate_id, 'em_location_id' ) );
        if ( $selected ) {
            return $selected;
        }

        $imported = absint( get_post_meta( absint( $candidate_id ), '_gi_em_location_id', true ) );
        if ( $imported ) {
            return $imported;
        }

        return ! empty( $matching_location['id'] ) ? absint( $matching_location['id'] ) : 0;
    }

    private function selected_location_id_source( $candidate_id, array $matching_location = array() ) {
        if ( absint( GI_Candidate_Review::review_value( $candidate_id, 'em_location_id' ) ) ) {
            return 'reviewer_selected';
        }

        if ( absint( get_post_meta( absint( $candidate_id ), '_gi_em_location_id', true ) ) ) {
            return 'imported_em_location';
        }

        if ( ! empty( $matching_location['id'] ) ) {
            return 'automatic_matching_location';
        }

        return '';
    }

    private function locations_for_dropdown( $selected_id ) {
        $locations = $this->em_locations;
        if ( ! $selected_id ) {
            return $locations;
        }

        foreach ( $locations as $location ) {
            if ( $selected_id === absint( $location['id'] ) ) {
                return $locations;
            }
        }

        $selected = $this->selected_location_by_id( $selected_id );
        if ( ! empty( $selected ) ) {
            array_unshift( $locations, $selected );
        }

        return $locations;
    }

    private function candidate_address( $candidate_id ) {
        $location = GI_Candidate_Review::normalized_location_fields( $candidate_id );

        return $this->format_address(
            array(
                'address'  => $location['address_1'],
                'address2' => $location['address_2'],
                'city'     => $location['city'],
                'state'    => $location['state'],
                'postcode' => $location['postcode'],
                'country'  => $location['country'],
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
