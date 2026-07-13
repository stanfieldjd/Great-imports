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
        update_post_meta( $candidate_id, '_gi_candidate_status', 'imported' );
        update_post_meta( $candidate_id, '_gi_review_review_status', 'imported' );
        $success_message = sprintf( __( 'Candidate imported or updated in Events Manager event %d.', 'great-imports' ), $event_result['event_id'] );
        $this->finish_trace( $candidate_id, $trace, true, $success_message );

        return array(
            'success'     => true,
            'message'     => $success_message,
            'event_id'    => $event_result['event_id'],
            'location_id' => $location_result['location_id'],
        );
    }

    private function resolve_location( $candidate_id, array $payload ) {
        $selected_id = isset( $payload['em_location_id'] ) ? absint( $payload['em_location_id'] ) : 0;

        if ( $selected_id ) {
            $match_source = isset( $payload['location_match_source'] ) ? sanitize_key( (string) $payload['location_match_source'] ) : '';
            $trace_source = 'reviewer_selected' === $match_source ? 'reviewer_selected_em_location' : ( 'automatic_matching_location' === $match_source ? 'automatic_matching_location' : 'em_location_id_payload' );
            $strategy     = 'automatic_matching_location' === $match_source ? 'matched_existing' : 'selected_existing';
            $before = $this->location_snapshot( $selected_id );
            if ( empty( $before['found'] ) ) {
                return $this->failure( __( 'Selected Events Manager location no longer exists.', 'great-imports' ) );
            }

            $sync = $this->sync_location_storage( $selected_id, $payload, false );
            if ( empty( $sync['success'] ) ) {
                return $this->failure( $sync['message'] );
            }

            return array(
                'success'         => true,
                'location_id'     => absint( $sync['location_id'] ),
                'created'         => false,
                'before_snapshot' => $before,
                'after_snapshot'  => $this->location_snapshot( $sync['location_id'] ),
                'trace'           => array(
                    'strategy'     => $strategy,
                    'source'       => $trace_source,
                    'match_reason' => isset( $payload['location_match_reason'] ) ? sanitize_text_field( (string) $payload['location_match_reason'] ) : '',
                    'matched_location_had_complete_coordinates' => ! empty( $payload['location_match_has_complete_coordinates'] ),
                    'storage_sync' => $sync['trace'],
                ),
            );
        }

        $existing_id = absint( get_post_meta( $candidate_id, '_gi_em_location_id', true ) );
        if ( $existing_id ) {
            $before = $this->location_snapshot( $existing_id );
            if ( ! empty( $before['found'] ) ) {
                $sync = $this->sync_location_storage( $existing_id, $payload, true );
                if ( empty( $sync['success'] ) ) {
                    return $this->failure( $sync['message'] );
                }

                return array(
                    'success'         => true,
                    'location_id'     => absint( $sync['location_id'] ),
                    'created'         => false,
                    'before_snapshot' => $before,
                    'after_snapshot'  => $this->location_snapshot( $sync['location_id'] ),
                    'trace'           => array(
                        'strategy'     => 'reuse_previous_import',
                        'source'       => 'candidate_em_location_id',
                        'storage_sync' => $sync['trace'],
                    ),
                );
            }
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

        $sync = $this->sync_location_storage( absint( $location->location_id ), $payload, true, ! empty( $location->post_id ) ? absint( $location->post_id ) : 0 );
        if ( empty( $sync['success'] ) ) {
            return $this->failure( $sync['message'] );
        }

        return array(
            'success'         => true,
            'location_id'     => absint( $sync['location_id'] ),
            'created'         => true,
            'before_snapshot' => array(
                'found' => false,
                'note'  => 'New Events Manager location; no before snapshot existed.',
            ),
            'after_snapshot'  => $this->location_snapshot( $sync['location_id'] ),
            'trace'           => array(
                'strategy'     => 'create',
                'source'       => 'reviewed_address_payload',
                'storage_sync' => $sync['trace'],
            ),
        );
    }

    private function sync_location_storage( $location_id, array $payload, $update_address, $fallback_post_id = 0 ) {
        global $wpdb;

        $location_id = absint( $location_id );
        $row         = $this->raw_location_row( $location_id );
        $row_exists  = is_array( $row ) && ! empty( $row );
        $post_id     = $row_exists && ! empty( $row['post_id'] ) ? absint( $row['post_id'] ) : absint( $fallback_post_id );

        if ( ! $post_id ) {
            return array(
                'success'     => false,
                'message'     => __( 'Events Manager location storage row could not be resolved.', 'great-imports' ),
                'location_id' => $location_id,
                'trace'       => array( 'resolved_post_id' => 0 ),
            );
        }

        $table        = $this->location_table_name();
        $table_exists = $this->location_table_exists( $table );
        $post         = get_post( $post_id );
        $blog_id      = is_multisite() ? get_current_blog_id() : 0;
        $address      = $this->sanitized_location_payload( $payload );
        $source_pair  = $this->payload_coordinate_pair( $payload );
        $existing_pair = $this->existing_coordinate_pair( $post_id, $row );
        $coordinate_pair = $existing_pair['complete'] ? $existing_pair : $source_pair;
        $coordinate_origin = $existing_pair['complete'] ? 'existing_events_manager_coordinates' : ( $source_pair['complete'] ? 'source_coordinate_evidence' : 'none' );
        $meta_complete  = $this->stored_pair_complete( get_post_meta( $post_id, '_location_latitude', true ), get_post_meta( $post_id, '_location_longitude', true ) );
        $table_complete = $row_exists && $this->stored_pair_complete( isset( $row['location_latitude'] ) ? $row['location_latitude'] : '', isset( $row['location_longitude'] ) ? $row['location_longitude'] : '' );

        $trace = array(
            'address_storage' => $update_address ? 'synced' : 'preserved_existing_selected_location',
            'table_exists'    => $table_exists,
            'post_meta_written' => array(),
            'table_written'   => false,
            'table_inserted'  => false,
            'coordinates'     => array(
                'payload_complete' => ! empty( $source_pair['complete'] ),
                'existing_complete' => ! empty( $existing_pair['complete'] ),
                'origin'           => $coordinate_origin,
                'values_redacted'  => true,
                'source'           => isset( $payload['coordinate_source'] ) ? sanitize_text_field( (string) $payload['coordinate_source'] ) : '',
                'evidence_path'    => isset( $payload['coordinate_evidence_path'] ) ? sanitize_text_field( (string) $payload['coordinate_evidence_path'] ) : '',
                'write_decision'   => 'none',
            ),
        );

        if ( $update_address ) {
            update_post_meta( $post_id, '_blog_id', $blog_id );
            update_post_meta( $post_id, '_location_address', $address['location_address'] );
            update_post_meta( $post_id, '_location_town', $address['location_town'] );
            update_post_meta( $post_id, '_location_state', $address['location_state'] );
            update_post_meta( $post_id, '_location_postcode', $address['location_postcode'] );
            update_post_meta( $post_id, '_location_region', '' );
            update_post_meta( $post_id, '_location_country', $address['location_country'] );
            update_post_meta( $post_id, '_location_status', 1 );
            $trace['post_meta_written'] = array( 'address', 'town', 'state', 'postcode', 'region', 'country', 'status' );
        }

        $write_coordinates_to_meta  = ! empty( $coordinate_pair['complete'] ) && ! $meta_complete;
        $write_coordinates_to_table = ! empty( $coordinate_pair['complete'] ) && ! $table_complete;

        if ( $write_coordinates_to_meta ) {
            update_post_meta( $post_id, '_location_latitude', $coordinate_pair['latitude'] );
            update_post_meta( $post_id, '_location_longitude', $coordinate_pair['longitude'] );
            $trace['post_meta_written'][] = 'coordinates';
        }

        if ( ! empty( $coordinate_pair['complete'] ) ) {
            if ( $existing_pair['complete'] && $source_pair['complete'] ) {
                $trace['coordinates']['write_decision'] = 'preserved_existing_complete_coordinates';
            } elseif ( $write_coordinates_to_meta || $write_coordinates_to_table ) {
                $trace['coordinates']['write_decision'] = 'wrote_missing_coordinate_surface';
            } else {
                $trace['coordinates']['write_decision'] = 'already_complete';
            }
        } elseif ( ! empty( $source_pair['incomplete'] ) ) {
            $trace['coordinates']['write_decision'] = 'skipped_incomplete_source_coordinates';
        } else {
            $trace['coordinates']['write_decision'] = 'no_coordinate_pair_available';
        }

        if ( $table_exists ) {
            $table_data = array();

            if ( $update_address ) {
                $table_data = array(
                    'post_id'            => $post_id,
                    'blog_id'            => $blog_id,
                    'location_slug'      => $post ? sanitize_title( $post->post_name ) : '',
                    'location_name'      => $address['location_name'],
                    'location_owner'     => $post ? absint( $post->post_author ) : get_current_user_id(),
                    'location_address'   => $address['location_address'],
                    'location_town'      => $address['location_town'],
                    'location_state'     => $address['location_state'],
                    'location_postcode'  => $address['location_postcode'],
                    'location_region'    => '',
                    'location_country'   => $address['location_country'],
                    'post_content'       => $post ? $post->post_content : '',
                    'location_status'    => 1,
                    'location_private'   => $post && 'private' === $post->post_status ? 1 : 0,
                );
            }

            if ( $write_coordinates_to_table ) {
                $table_data['location_latitude'] = $coordinate_pair['latitude'];
                $table_data['location_longitude'] = $coordinate_pair['longitude'];
            }

            if ( ! empty( $table_data ) ) {
                if ( $row_exists ) {
                    $updated = $wpdb->update( $table, $table_data, array( 'location_id' => $location_id ) );
                    if ( false === $updated ) {
                        return array(
                            'success'     => false,
                            'message'     => __( 'Events Manager location table could not be updated.', 'great-imports' ),
                            'location_id' => $location_id,
                            'trace'       => $trace,
                        );
                    }
                    $trace['table_written'] = true;
                } else {
                    $inserted = $wpdb->insert( $table, $table_data );
                    if ( false === $inserted ) {
                        return array(
                            'success'     => false,
                            'message'     => __( 'Events Manager location table could not be inserted.', 'great-imports' ),
                            'location_id' => $location_id,
                            'trace'       => $trace,
                        );
                    }
                    $location_id = absint( $wpdb->insert_id );
                    update_post_meta( $post_id, '_location_id', $location_id );
                    $trace['table_written']  = true;
                    $trace['table_inserted'] = true;
                }
            }
        }

        return array(
            'success'     => true,
            'message'     => '',
            'location_id' => $location_id,
            'trace'       => $trace,
        );
    }

    private function sanitized_location_payload( array $payload ) {
        return array(
            'location_name'     => isset( $payload['location_name'] ) ? sanitize_text_field( (string) $payload['location_name'] ) : '',
            'location_address'  => isset( $payload['location_address'] ) ? sanitize_text_field( (string) $payload['location_address'] ) : '',
            'location_town'     => isset( $payload['location_town'] ) ? sanitize_text_field( (string) $payload['location_town'] ) : '',
            'location_state'    => isset( $payload['location_state'] ) ? sanitize_text_field( (string) $payload['location_state'] ) : '',
            'location_postcode' => isset( $payload['location_postcode'] ) ? sanitize_text_field( (string) $payload['location_postcode'] ) : '',
            'location_country'  => isset( $payload['location_country'] ) ? sanitize_text_field( (string) $payload['location_country'] ) : '',
        );
    }

    private function payload_coordinate_pair( array $payload ) {
        $latitude  = isset( $payload['location_latitude'] ) ? $this->coordinate_value( $payload['location_latitude'] ) : '';
        $longitude = isset( $payload['location_longitude'] ) ? $this->coordinate_value( $payload['location_longitude'] ) : '';

        return array(
            'complete'  => '' !== $latitude && '' !== $longitude,
            'incomplete' => ( '' !== $latitude && '' === $longitude ) || ( '' === $latitude && '' !== $longitude ),
            'latitude'  => $latitude,
            'longitude' => $longitude,
        );
    }

    private function existing_coordinate_pair( $post_id, $row ) {
        $meta_latitude  = $this->coordinate_value( get_post_meta( $post_id, '_location_latitude', true ) );
        $meta_longitude = $this->coordinate_value( get_post_meta( $post_id, '_location_longitude', true ) );
        if ( '' !== $meta_latitude && '' !== $meta_longitude ) {
            return array(
                'complete'  => true,
                'latitude'  => $meta_latitude,
                'longitude' => $meta_longitude,
            );
        }

        $table_latitude  = is_array( $row ) && ! empty( $row ) && isset( $row['location_latitude'] ) ? $this->coordinate_value( $row['location_latitude'] ) : '';
        $table_longitude = is_array( $row ) && ! empty( $row ) && isset( $row['location_longitude'] ) ? $this->coordinate_value( $row['location_longitude'] ) : '';

        return array(
            'complete'  => '' !== $table_latitude && '' !== $table_longitude,
            'latitude'  => $table_latitude,
            'longitude' => $table_longitude,
        );
    }

    private function coordinate_value( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value || ! is_numeric( $value ) ) {
            return '';
        }

        $number = round( (float) $value, 6 );
        if ( 0.0 === $number ) {
            return '';
        }

        return number_format( $number, 6, '.', '' );
    }

    private function stored_pair_complete( $latitude, $longitude ) {
        return '' !== $this->coordinate_value( $latitude ) && '' !== $this->coordinate_value( $longitude );
    }

    private function location_table_name() {
        global $wpdb;

        return defined( 'EM_LOCATIONS_TABLE' ) ? EM_LOCATIONS_TABLE : $wpdb->prefix . 'em_locations';
    }

    private function location_table_exists( $table ) {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    private function raw_location_row( $location_id ) {
        global $wpdb;

        $location_id = absint( $location_id );
        if ( ! $location_id ) {
            return array();
        }

        $table = $this->location_table_name();
        if ( ! $this->location_table_exists( $table ) ) {
            return array();
        }

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE location_id = %d", $location_id ), ARRAY_A );

        return is_array( $row ) ? $row : array();
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
        $event->event_status     = 1;
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
                'review_selected_em_location_id' => ! empty( $location_payload['location_match_source'] ) && 'reviewer_selected' === $location_payload['location_match_source'] && isset( $location_payload['em_location_id'] ) ? absint( $location_payload['em_location_id'] ) : 0,
            ),
            'payload'        => array(
                'ready_for_save' => ! empty( $payload['ready_for_save'] ),
                'validation'     => isset( $payload['validation'] ) && is_array( $payload['validation'] ) ? $payload['validation'] : array(),
                'location_strategy' => isset( $location_payload['strategy'] ) ? sanitize_key( $location_payload['strategy'] ) : '',
                'em_location_id' => isset( $location_payload['em_location_id'] ) ? absint( $location_payload['em_location_id'] ) : 0,
                'location_match_source' => isset( $location_payload['location_match_source'] ) ? sanitize_key( (string) $location_payload['location_match_source'] ) : '',
                'location_match_reason' => isset( $location_payload['location_match_reason'] ) ? sanitize_text_field( (string) $location_payload['location_match_reason'] ) : '',
                'location_match_has_complete_coordinates' => ! empty( $location_payload['location_match_has_complete_coordinates'] ),
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
                'coordinate_payload' => array(
                    'complete'      => isset( $location_payload['location_latitude'], $location_payload['location_longitude'] ) && '' !== (string) $location_payload['location_latitude'] && '' !== (string) $location_payload['location_longitude'],
                    'values_redacted' => true,
                    'source'        => isset( $location_payload['coordinate_source'] ) ? sanitize_text_field( (string) $location_payload['coordinate_source'] ) : '',
                    'evidence_path' => isset( $location_payload['coordinate_evidence_path'] ) ? sanitize_text_field( (string) $location_payload['coordinate_evidence_path'] ) : '',
                ),
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
                "SELECT location_id, post_id, location_name, location_address, location_town, location_state, location_postcode, location_region, location_country, location_latitude, location_longitude FROM {$table} WHERE location_id = %d",
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
            'location_region'   => isset( $row['location_region'] ) ? sanitize_text_field( (string) $row['location_region'] ) : '',
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
            'event_status' => isset( $event->event_status ) ? sanitize_text_field( (string) $event->event_status ) : '',
            'event_start_date' => isset( $event->event_start_date ) ? sanitize_text_field( (string) $event->event_start_date ) : '',
            'event_start_time' => isset( $event->event_start_time ) ? sanitize_text_field( (string) $event->event_start_time ) : '',
            'event_end_date' => isset( $event->event_end_date ) ? sanitize_text_field( (string) $event->event_end_date ) : '',
            'event_end_time' => isset( $event->event_end_time ) ? sanitize_text_field( (string) $event->event_end_time ) : '',
            'event_timezone' => isset( $event->event_timezone ) ? sanitize_text_field( (string) $event->event_timezone ) : '',
            'location_id' => ! empty( $event->location_id ) ? absint( $event->location_id ) : 0,
            'admin_edit_url' => $post_id ? esc_url_raw( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) : '',
            'content_trace' => $this->event_content_trace( $event, $post_id ),
        );
    }

    private function event_content_trace( $event, $post_id ) {
        $post_content = $post_id ? (string) get_post_field( 'post_content', $post_id, 'raw' ) : '';
        $format       = $this->single_event_format_for_trace( $event );
        $rendered     = '';

        if ( is_object( $event ) && method_exists( $event, 'output_single' ) ) {
            $rendered = (string) $event->output_single();
        }

        return array(
            'post_content_sha256' => '' !== $post_content ? hash( 'sha256', $post_content ) : '',
            'post_content_contains_openstreetmap' => $this->contains_openstreetmap_placeholder( $post_content ),
            'post_content_placeholders' => $this->placeholder_markers( $post_content ),
            'single_event_format_contains_openstreetmap' => $this->contains_openstreetmap_placeholder( $format ),
            'single_event_format_contains_location_map' => $this->contains_location_map_placeholder( $format ),
            'single_event_format_placeholders' => $this->placeholder_markers( $format ),
            'rendered_single_contains_openstreetmap' => $this->contains_openstreetmap_placeholder( $rendered ),
            'rendered_single_openstreetmap_context' => $this->placeholder_context( $rendered, '#_OPENSTREETMAP' ),
            'trace_note' => 'Trace-only field for unsupported map placeholders. It compares saved event content, the active Events Manager single-event format, and rendered single-event output.',
        );
    }

    private function single_event_format_for_trace( $event ) {
        if ( is_object( $event ) && method_exists( $event, 'get_option' ) ) {
            return (string) $event->get_option( 'dbem_single_event_format' );
        }
        if ( function_exists( 'em_get_option' ) ) {
            return (string) em_get_option( 'dbem_single_event_format' );
        }
        return '';
    }

    private function contains_openstreetmap_placeholder( $value ) {
        return 1 === preg_match( '/#_OPENSTREETMAP\b/i', (string) $value );
    }

    private function contains_location_map_placeholder( $value ) {
        return 1 === preg_match( '/#_(?:LOCATION)?MAP(?:\{[^}]*\})?(?![A-Z0-9_])/i', (string) $value );
    }

    private function placeholder_markers( $value ) {
        preg_match_all( '/#_[A-Z0-9_]+(?:\{[^}]*\})?/i', (string) $value, $matches );
        if ( empty( $matches[0] ) ) {
            return array();
        }

        return array_values( array_unique( array_map( 'sanitize_text_field', $matches[0] ) ) );
    }

    private function placeholder_context( $value, $needle ) {
        $value = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $value ) ) );
        $pos   = stripos( $value, $needle );
        if ( false === $pos ) {
            return '';
        }

        $start = max( 0, $pos - 120 );
        return sanitize_text_field( substr( $value, $start, 240 ) );
    }

    private function coordinate_present( $value ) {
        return '' !== $this->coordinate_value( $value );
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
