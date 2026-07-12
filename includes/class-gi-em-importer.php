<?php
/**
 * Transfers validated Great Imports candidates into Events Manager.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_EM_Importer {
    /** @var GI_Import_Preview_Builder */
    private $preview_builder;

    public function __construct( GI_Import_Preview_Builder $preview_builder ) {
        $this->preview_builder = $preview_builder;
    }

    public function import_candidate( $candidate_id ) {
        $candidate_id = absint( $candidate_id );
        $candidate    = get_post( $candidate_id );

        if ( ! $candidate || 'gi_candidate' !== $candidate->post_type ) {
            return $this->failure( __( 'Candidate could not be found.', 'great-imports' ) );
        }
        if ( ! class_exists( 'EM_Event' ) || ! class_exists( 'EM_Location' ) ) {
            return $this->failure( __( 'Events Manager event/location classes are unavailable.', 'great-imports' ) );
        }

        $preview = $this->preview_builder->build_for_candidate( $candidate );
        $payload = isset( $preview['events_manager_payload'] ) && is_array( $preview['events_manager_payload'] ) ? $preview['events_manager_payload'] : array();
        $trace   = $this->start_trace( $candidate_id, $payload );

        if ( empty( $payload['ready_for_save'] ) ) {
            $errors = isset( $payload['validation']['errors'] ) && is_array( $payload['validation']['errors'] ) ? $payload['validation']['errors'] : array();
            $message = empty( $errors ) ? __( 'Candidate payload is not ready for import.', 'great-imports' ) : implode( ' ', $errors );
            $this->finish_trace( $candidate_id, $trace, false, $message );
            return $this->failure( $message );
        }

        $location_result = $this->resolve_location( $candidate_id, $payload['location'] );
        $trace['during']['location_resolution'] = isset( $location_result['trace'] ) ? $location_result['trace'] : array();
        $trace['snapshots']['location_before']  = isset( $location_result['before_snapshot'] ) ? $location_result['before_snapshot'] : array();
        $trace['snapshots']['location_after_location_save'] = isset( $location_result['after_snapshot'] ) ? $location_result['after_snapshot'] : array();
        if ( ! $location_result['success'] ) {
            $this->finish_trace( $candidate_id, $trace, false, $location_result['message'] );
            return $location_result;
        }

        $event_result = $this->save_event( $candidate_id, $payload, $location_result['location_id'] );
        $trace['during']['event_save'] = array(
            'existing_em_event_id' => isset( $event_result['existing_event_id'] ) ? absint( $event_result['existing_event_id'] ) : 0,
            'saved_em_event_id'    => isset( $event_result['event_id'] ) ? absint( $event_result['event_id'] ) : 0,
            'success'              => ! empty( $event_result['success'] ),
        );
        $trace['snapshots']['event_after_save']    = ! empty( $event_result['event_id'] ) ? $this->event_snapshot( $event_result['event_id'] ) : array();
        $trace['snapshots']['location_after_event_save'] = $this->location_snapshot( $location_result['location_id'] );
        if ( ! $event_result['success'] ) {
            if ( ! empty( $location_result['created'] ) ) {
                update_post_meta( $candidate_id, '_gi_em_orphan_location_id', $location_result['location_id'] );
            }
            $this->finish_trace( $candidate_id, $trace, false, $event_result['message'] );
            return $event_result;
        }

        update_post_meta( $candidate_id, '_gi_em_event_id', $event_result['event_id'] );
        update_post_meta( $candidate_id, '_gi_em_location_id', $location_result['location_id'] );
        update_post_meta( $candidate_id, '_gi_imported_at', current_time( 'mysql' ) );
        $this->finish_trace( $candidate_id, $trace, true, sprintf( __( 'Candidate imported to Events Manager draft event %d.', 'great-imports' ), $event_result['event_id'] ) );

        return array(
            'success'     => true,
            'message'     => sprintf( __( 'Candidate imported to Events Manager draft event %d.', 'great-imports' ), $event_result['event_id'] ),
            'event_id'    => $event_result['event_id'],
            'location_id' => $location_result['location_id'],
        );
    }

    private function resolve_location( $candidate_id, array $payload ) {
        $selected_id = isset( $payload['em_location_id'] ) ? absint( $payload['em_location_id'] ) : 0;

        if ( $selected_id ) {
            global $wpdb;
            $table = $wpdb->prefix . 'em_locations';
            $before = $this->location_snapshot( $selected_id );
            $found = $wpdb->get_var( $wpdb->prepare( "SELECT location_id FROM {$table} WHERE location_id = %d", $selected_id ) );
            if ( ! $found ) {
                return $this->failure( __( 'Selected Events Manager location no longer exists.', 'great-imports' ) );
            }
            return array(
                'success'         => true,
                'location_id'     => $selected_id,
                'created'         => false,
                'before_snapshot' => $before,
                'after_snapshot'  => $this->location_snapshot( $selected_id ),
                'trace'           => array(
                    'strategy' => 'selected_existing',
                    'source'   => 'reviewer_selected_em_location',
                ),
            );
        }

        $existing_id = absint( get_post_meta( $candidate_id, '_gi_em_location_id', true ) );
        if ( $existing_id ) {
            $before = $this->location_snapshot( $existing_id );
            return array(
                'success'         => true,
                'location_id'     => $existing_id,
                'created'         => false,
                'before_snapshot' => $before,
                'after_snapshot'  => $this->location_snapshot( $existing_id ),
                'trace'           => array(
                    'strategy' => 'reuse_previous_import',
                    'source'   => 'candidate_em_location_id',
                ),
            );
        }

        $location = new EM_Location();
        $location->location_name     = isset( $payload['location_name'] ) ? $payload['location_name'] : '';
        $location->location_address  = isset( $payload['location_address'] ) ? $payload['location_address'] : '';
        $location->location_town     = isset( $payload['location_town'] ) ? $payload['location_town'] : '';
        $location->location_state    = isset( $payload['location_state'] ) ? $payload['location_state'] : '';
        $location->location_postcode = isset( $payload['location_postcode'] ) ? $payload['location_postcode'] : '';
        $location->location_country  = isset( $payload['location_country'] ) ? $payload['location_country'] : '';
        $location->location_owner    = get_current_user_id();

        if ( ! method_exists( $location, 'save' ) || ! $location->save() || empty( $location->location_id ) ) {
            return $this->failure( $this->object_error( $location, __( 'Events Manager location could not be saved.', 'great-imports' ) ) );
        }

        return array(
            'success'         => true,
            'location_id'     => absint( $location->location_id ),
            'created'         => true,
            'before_snapshot' => array(
                'found' => false,
                'note'  => 'New Events Manager location; no before snapshot existed.',
            ),
            'after_snapshot'  => $this->location_snapshot( $location->location_id ),
            'trace'           => array(
                'strategy' => 'create',
                'source'   => 'reviewed_address_payload',
            ),
        );
    }

    private function save_event( $candidate_id, array $payload, $location_id ) {
        $existing_id = absint( get_post_meta( $candidate_id, '_gi_em_event_id', true ) );
        if ( $existing_id ) {
            if ( function_exists( 'em_get_event' ) ) {
                $event = em_get_event( $existing_id );
            } else {
                $event = new EM_Event( $existing_id );
            }
            if ( ! $event || empty( $event->event_id ) ) {
                return $this->failure( __( 'Previously imported Events Manager event could not be loaded; no duplicate was created.', 'great-imports' ) );
            }
        } else {
            $event = new EM_Event();
        }

        $data = $payload['event'];
        $event->event_name       = isset( $data['event_name'] ) ? $data['event_name'] : '';
        $event->event_start_date = isset( $data['event_start_date'] ) ? $data['event_start_date'] : '';
        $event->event_start_time = isset( $data['event_start_time'] ) ? $data['event_start_time'] : '';
        $event->event_end_date   = isset( $data['event_end_date'] ) ? $data['event_end_date'] : '';
        $event->event_end_time   = isset( $data['event_end_time'] ) ? $data['event_end_time'] : '';
        $event->event_timezone   = isset( $data['event_timezone'] ) ? $data['event_timezone'] : '';
        $event->event_status     = 0;
        $event->event_owner      = get_current_user_id();
        $event->location_id      = absint( $location_id );
        $event->post_content     = isset( $data['post_content'] ) ? $data['post_content'] : '';
        $event->event_notes      = $event->post_content;

        if ( ! method_exists( $event, 'save' ) || ! $event->save() || empty( $event->event_id ) ) {
            return $this->failure( $this->object_error( $event, __( 'Events Manager event could not be saved.', 'great-imports' ) ) );
        }

        $post_id = ! empty( $event->post_id ) ? absint( $event->post_id ) : 0;
        if ( $post_id ) {
            update_post_meta( $post_id, '_gi_candidate_post_id', $candidate_id );
            update_post_meta( $post_id, '_gi_source_url', isset( $payload['source_identity']['source_url'] ) ? $payload['source_identity']['source_url'] : '' );
            update_post_meta( $post_id, '_gi_eventbrite_event_id', isset( $payload['source_identity']['eventbrite_event_id'] ) ? $payload['source_identity']['eventbrite_event_id'] : '' );
            update_post_meta( $post_id, '_gi_fingerprint', isset( $payload['source_identity']['fingerprint'] ) ? $payload['source_identity']['fingerprint'] : '' );
        }

        return array( 'success' => true, 'event_id' => absint( $event->event_id ), 'existing_event_id' => $existing_id );
    }

    private function start_trace( $candidate_id, array $payload ) {
        $location_payload = isset( $payload['location'] ) && is_array( $payload['location'] ) ? $payload['location'] : array();

        return array(
            'schema_version' => 1,
            'rule'           => 'Great Imports records the Events Manager location storage handoff and preserves existing coordinate data.',
            'started_at'     => current_time( 'mysql' ),
            'candidate_post_id' => absint( $candidate_id ),
            'before'         => array(
                'candidate_em_event_id'    => absint( get_post_meta( $candidate_id, '_gi_em_event_id', true ) ),
                'candidate_em_location_id' => absint( get_post_meta( $candidate_id, '_gi_em_location_id', true ) ),
                'review_selected_em_location_id' => isset( $location_payload['em_location_id'] ) ? absint( $location_payload['em_location_id'] ) : 0,
            ),
            'payload'        => array(
                'ready_for_save' => ! empty( $payload['ready_for_save'] ),
                'validation'     => isset( $payload['validation'] ) && is_array( $payload['validation'] ) ? $payload['validation'] : array(),
                'location_strategy' => isset( $location_payload['strategy'] ) ? sanitize_key( $location_payload['strategy'] ) : '',
                'address_payload' => array(
                    'location_name'     => isset( $location_payload['location_name'] ) ? sanitize_text_field( (string) $location_payload['location_name'] ) : '',
                    'location_address'  => isset( $location_payload['location_address'] ) ? sanitize_text_field( (string) $location_payload['location_address'] ) : '',
                    'location_address2' => isset( $location_payload['location_address2'] ) ? sanitize_text_field( (string) $location_payload['location_address2'] ) : '',
                    'location_town'     => isset( $location_payload['location_town'] ) ? sanitize_text_field( (string) $location_payload['location_town'] ) : '',
                    'location_state'    => isset( $location_payload['location_state'] ) ? sanitize_text_field( (string) $location_payload['location_state'] ) : '',
                    'location_postcode' => isset( $location_payload['location_postcode'] ) ? sanitize_text_field( (string) $location_payload['location_postcode'] ) : '',
                    'location_country'  => isset( $location_payload['location_country'] ) ? sanitize_text_field( (string) $location_payload['location_country'] ) : '',
                ),
                'payload_included_coordinates' => array_key_exists( 'location_latitude', $location_payload ) || array_key_exists( 'location_longitude', $location_payload ),
            ),
            'during'       => array(),
            'snapshots'    => array(),
            'status'       => 'started',
        );
    }

    private function finish_trace( $candidate_id, array $trace, $success, $message ) {
        $trace['completed_at'] = current_time( 'mysql' );
        $trace['status']       = $success ? 'succeeded' : 'failed';
        $trace['message']      = sanitize_text_field( (string) $message );
        update_post_meta( $candidate_id, '_gi_em_import_trace', $trace );
    }

    private function location_snapshot( $location_id ) {
        global $wpdb;

        $location_id = absint( $location_id );
        if ( ! $location_id ) {
            return array( 'found' => false, 'location_id' => 0 );
        }

        $table = $wpdb->prefix . 'em_locations';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT location_id, post_id, location_name, location_address, location_town, location_state, location_postcode, location_country, location_latitude, location_longitude FROM {$table} WHERE location_id = %d",
                $location_id
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return array( 'found' => false, 'location_id' => $location_id );
        }

        $post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
        $meta_latitude  = $post_id ? get_post_meta( $post_id, '_location_latitude', true ) : '';
        $meta_longitude = $post_id ? get_post_meta( $post_id, '_location_longitude', true ) : '';
        $address        = array(
            'location_name'     => isset( $row['location_name'] ) ? sanitize_text_field( (string) $row['location_name'] ) : '',
            'location_address'  => isset( $row['location_address'] ) ? sanitize_text_field( (string) $row['location_address'] ) : '',
            'location_town'     => isset( $row['location_town'] ) ? sanitize_text_field( (string) $row['location_town'] ) : '',
            'location_state'    => isset( $row['location_state'] ) ? sanitize_text_field( (string) $row['location_state'] ) : '',
            'location_postcode' => isset( $row['location_postcode'] ) ? sanitize_text_field( (string) $row['location_postcode'] ) : '',
            'location_country'  => isset( $row['location_country'] ) ? sanitize_text_field( (string) $row['location_country'] ) : '',
        );
        $coordinate_state = array(
            'values_redacted' => true,
            'post_meta'       => array(
                'latitude_present'  => $this->coordinate_present( $meta_latitude ),
                'longitude_present' => $this->coordinate_present( $meta_longitude ),
                'complete'          => $this->coordinate_present( $meta_latitude ) && $this->coordinate_present( $meta_longitude ),
            ),
            'em_locations_table' => array(
                'latitude_present'  => $this->coordinate_present( isset( $row['location_latitude'] ) ? $row['location_latitude'] : '' ),
                'longitude_present' => $this->coordinate_present( isset( $row['location_longitude'] ) ? $row['location_longitude'] : '' ),
                'complete'          => $this->coordinate_present( isset( $row['location_latitude'] ) ? $row['location_latitude'] : '' ) && $this->coordinate_present( isset( $row['location_longitude'] ) ? $row['location_longitude'] : '' ),
            ),
        );
        $has_complete_coordinates = ! empty( $coordinate_state['post_meta']['complete'] ) || ! empty( $coordinate_state['em_locations_table']['complete'] );

        return array(
            'found'       => true,
            'location_id' => $location_id,
            'post_id'     => $post_id,
            'post_status' => $post_id ? sanitize_text_field( (string) get_post_status( $post_id ) ) : '',
            'post_title'  => $post_id ? sanitize_text_field( get_the_title( $post_id ) ) : '',
            'admin_edit_url' => $post_id ? esc_url_raw( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) : '',
            'address'     => $address,
            'coordinate_state' => $coordinate_state,
            'has_complete_coordinates' => $has_complete_coordinates,
            'map_refresh_required' => $this->address_present( $address ) && ! $has_complete_coordinates,
            'trace_note'  => 'Coordinate values are intentionally redacted. Existing coordinate values are preserved unless an explicit replacement decision is recorded.',
        );
    }

    private function event_snapshot( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return array( 'found' => false, 'event_id' => 0 );
        }

        if ( function_exists( 'em_get_event' ) ) {
            $event = em_get_event( $event_id );
        } else {
            $event = new EM_Event( $event_id );
        }

        if ( ! $event || empty( $event->event_id ) ) {
            return array( 'found' => false, 'event_id' => $event_id );
        }

        $post_id = ! empty( $event->post_id ) ? absint( $event->post_id ) : 0;
        return array(
            'found'       => true,
            'event_id'    => absint( $event->event_id ),
            'post_id'     => $post_id,
            'post_status' => $post_id ? sanitize_text_field( (string) get_post_status( $post_id ) ) : '',
            'post_title'  => $post_id ? sanitize_text_field( get_the_title( $post_id ) ) : '',
            'location_id' => ! empty( $event->location_id ) ? absint( $event->location_id ) : 0,
            'admin_edit_url' => $post_id ? esc_url_raw( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) : '',
        );
    }

    private function coordinate_present( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value || ! is_numeric( $value ) ) {
            return false;
        }
        return (float) $value !== 0.0;
    }

    private function address_present( array $address ) {
        foreach ( array( 'location_address', 'location_town', 'location_state', 'location_postcode', 'location_country' ) as $key ) {
            if ( ! empty( $address[ $key ] ) ) {
                return true;
            }
        }
        return false;
    }

    private function object_error( $object, $fallback ) {
        if ( is_object( $object ) && method_exists( $object, 'get_errors' ) ) {
            $errors = $object->get_errors();
            if ( is_array( $errors ) && ! empty( $errors ) ) {
                return implode( ' ', array_map( 'sanitize_text_field', $errors ) );
            }
        }
        return $fallback;
    }

    private function failure( $message ) {
        return array( 'success' => false, 'message' => sanitize_text_field( (string) $message ), 'event_id' => 0, 'location_id' => 0 );
    }
}
