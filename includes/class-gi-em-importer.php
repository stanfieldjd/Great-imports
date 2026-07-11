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

        if ( empty( $payload['ready_for_save'] ) ) {
            $errors = isset( $payload['validation']['errors'] ) && is_array( $payload['validation']['errors'] ) ? $payload['validation']['errors'] : array();
            return $this->failure( empty( $errors ) ? __( 'Candidate payload is not ready for import.', 'great-imports' ) : implode( ' ', $errors ) );
        }

        $location_result = $this->resolve_location( $candidate_id, $payload['location'] );
        if ( ! $location_result['success'] ) {
            return $location_result;
        }

        $event_result = $this->save_event( $candidate_id, $payload, $location_result['location_id'] );
        if ( ! $event_result['success'] ) {
            if ( ! empty( $location_result['created'] ) ) {
                update_post_meta( $candidate_id, '_gi_em_orphan_location_id', $location_result['location_id'] );
            }
            return $event_result;
        }

        update_post_meta( $candidate_id, '_gi_em_event_id', $event_result['event_id'] );
        update_post_meta( $candidate_id, '_gi_em_location_id', $location_result['location_id'] );
        update_post_meta( $candidate_id, '_gi_imported_at', current_time( 'mysql' ) );

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
            $found = $wpdb->get_var( $wpdb->prepare( "SELECT location_id FROM {$table} WHERE location_id = %d", $selected_id ) );
            if ( ! $found ) {
                return $this->failure( __( 'Selected Events Manager location no longer exists.', 'great-imports' ) );
            }
            return array( 'success' => true, 'location_id' => $selected_id, 'created' => false );
        }

        $existing_id = absint( get_post_meta( $candidate_id, '_gi_em_location_id', true ) );
        if ( $existing_id ) {
            return array( 'success' => true, 'location_id' => $existing_id, 'created' => false );
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

        return array( 'success' => true, 'location_id' => absint( $location->location_id ), 'created' => true );
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

        return array( 'success' => true, 'event_id' => absint( $event->event_id ) );
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
