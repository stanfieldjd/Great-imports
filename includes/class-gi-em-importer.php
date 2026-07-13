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
            'existing_match_source' => isset( $event_result['existing_match_source'] ) ? sanitize_key( (string) $event_result['existing_match_source'] ) : '',
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

    public function import_recurring_candidate( $candidate_id ) {
        $candidate_id = absint( $candidate_id );
        $candidate    = get_post( $candidate_id );

        if ( ! $candidate || 'gi_candidate' !== $candidate->post_type ) {
            return $this->failure( __( 'Candidate could not be found.', 'great-imports' ) );
        }
        if ( ! class_exists( 'EM_Event' ) || ! class_exists( 'EM_Location' ) ) {
            return $this->failure( __( 'Events Manager event/location classes are unavailable.', 'great-imports' ) );
        }
        if ( ! class_exists( 'EM\\Recurrences\\Recurrence_Set' ) || ! class_exists( 'EM\\Timerange' ) ) {
            return $this->failure( __( 'Events Manager recurring event classes are unavailable.', 'great-imports' ) );
        }

        $preview = $this->preview_builder->build_for_candidate( $candidate );
        $payload = isset( $preview['events_manager_payload'] ) && is_array( $preview['events_manager_payload'] ) ? $preview['events_manager_payload'] : array();
        $trace   = $this->start_trace( $candidate_id, $payload );
        $trace['rule'] = 'Great Imports saves recurring candidates through Events Manager repeating event and recurrence-set objects.';

        if ( empty( $payload['ready_for_save'] ) ) {
            $errors  = isset( $payload['validation']['errors'] ) && is_array( $payload['validation']['errors'] ) ? $payload['validation']['errors'] : array();
            $message = empty( $errors ) ? __( 'Candidate payload is not ready for recurring import.', 'great-imports' ) : implode( ' ', $errors );
            $this->finish_trace( $candidate_id, $trace, false, $message, '_gi_em_recurring_import_trace' );
            return $this->failure( $message );
        }

        if ( ! $this->is_recurring_candidate( $candidate_id, $payload ) ) {
            $message = __( 'Candidate does not have a recurring date range or series marker.', 'great-imports' );
            $this->finish_trace( $candidate_id, $trace, false, $message, '_gi_em_recurring_import_trace' );
            return $this->failure( $message );
        }

        $location_result = $this->resolve_location( $candidate_id, $payload['location'] );
        $trace['during']['location_resolution'] = isset( $location_result['trace'] ) ? $location_result['trace'] : array();
        $trace['snapshots']['location_before']  = isset( $location_result['before_snapshot'] ) ? $location_result['before_snapshot'] : array();
        $trace['snapshots']['location_after_location_save'] = isset( $location_result['after_snapshot'] ) ? $location_result['after_snapshot'] : array();
        if ( ! $location_result['success'] ) {
            $this->finish_trace( $candidate_id, $trace, false, $location_result['message'], '_gi_em_recurring_import_trace' );
            return $location_result;
        }

        $event_result = $this->save_recurring_event( $candidate_id, $payload, $location_result['location_id'] );
        $trace['during']['recurring_event_save'] = array(
            'existing_em_event_id' => isset( $event_result['existing_event_id'] ) ? absint( $event_result['existing_event_id'] ) : 0,
            'existing_match_source' => isset( $event_result['existing_match_source'] ) ? sanitize_key( (string) $event_result['existing_match_source'] ) : '',
            'saved_em_event_id'    => isset( $event_result['event_id'] ) ? absint( $event_result['event_id'] ) : 0,
            'recurrence_set_id'    => isset( $event_result['recurrence_set_id'] ) ? absint( $event_result['recurrence_set_id'] ) : 0,
            'success'              => ! empty( $event_result['success'] ),
        );
        $trace['snapshots']['event_after_save'] = ! empty( $event_result['event_id'] ) ? $this->event_snapshot( $event_result['event_id'] ) : array();
        $trace['snapshots']['location_after_event_save'] = $this->location_snapshot( $location_result['location_id'] );
        if ( ! $event_result['success'] ) {
            $this->finish_trace( $candidate_id, $trace, false, $event_result['message'], '_gi_em_recurring_import_trace' );
            return $event_result;
        }

        update_post_meta( $candidate_id, '_gi_em_recurring_event_id', $event_result['event_id'] );
        update_post_meta( $candidate_id, '_gi_em_location_id', $location_result['location_id'] );
        update_post_meta( $candidate_id, '_gi_recurring_imported_at', current_time( 'mysql' ) );
        update_post_meta( $candidate_id, '_gi_candidate_status', 'recurring_imported' );
        update_post_meta( $candidate_id, '_gi_review_review_status', 'recurring_imported' );
        $success_message = sprintf( __( 'Candidate saved as Events Manager recurring event %d.', 'great-imports' ), $event_result['event_id'] );
        $this->finish_trace( $candidate_id, $trace, true, $success_message, '_gi_em_recurring_import_trace' );

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

        $matched_existing_id = $this->find_existing_location_by_address( $payload );
        if ( $matched_existing_id ) {
            $before = $this->location_snapshot( $matched_existing_id );
            if ( ! empty( $before['found'] ) ) {
                $sync = $this->sync_location_storage( $matched_existing_id, $payload, false );
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
                        'strategy'     => 'matched_existing',
                        'source'       => 'server_exact_address_match',
                        'match_reason' => 'same name, address, town, state, postcode, country',
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

    private function find_existing_location_by_address( array $payload ) {
        global $wpdb;

        $address = $this->sanitized_location_payload( $payload );
        if ( '' === $address['location_name'] || '' === $address['location_address'] || '' === $address['location_town'] ) {
            return 0;
        }

        $table = $this->location_table_name();
        if ( ! $this->location_table_exists( $table ) ) {
            return 0;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT location_id, location_latitude, location_longitude FROM {$table} WHERE location_name = %s AND location_address = %s AND location_town = %s AND location_state = %s AND location_postcode = %s AND location_country = %s ORDER BY CASE WHEN location_latitude IS NOT NULL AND location_latitude != '' AND location_longitude IS NOT NULL AND location_longitude != '' THEN 0 ELSE 1 END, location_id ASC LIMIT 1",
                $address['location_name'],
                $address['location_address'],
                $address['location_town'],
                $address['location_state'],
                $address['location_postcode'],
                $address['location_country']
            ),
            ARRAY_A
        );

        if ( empty( $rows[0]['location_id'] ) ) {
            return 0;
        }

        return absint( $rows[0]['location_id'] );
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
        $existing_resolution = $this->existing_event_for_payload( $candidate_id, $payload, $location_id );
        $existing_id         = isset( $existing_resolution['event_id'] ) ? absint( $existing_resolution['event_id'] ) : 0;
        $existing_source     = isset( $existing_resolution['source'] ) ? sanitize_key( (string) $existing_resolution['source'] ) : '';

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

        return array( 'success' => true, 'event_id' => absint( $event->event_id ), 'existing_event_id' => $existing_id, 'existing_match_source' => $existing_source );
    }

    private function save_recurring_event( $candidate_id, array $payload, $location_id ) {
        $existing_resolution = $this->existing_recurring_event_for_payload( $candidate_id, $payload, $location_id );
        $existing_id         = isset( $existing_resolution['event_id'] ) ? absint( $existing_resolution['event_id'] ) : 0;
        $existing_source     = isset( $existing_resolution['source'] ) ? sanitize_key( (string) $existing_resolution['source'] ) : '';

        if ( $existing_id ) {
            if ( function_exists( 'em_get_event' ) ) {
                $event = em_get_event( $existing_id );
            } else {
                $event = new EM_Event( $existing_id );
            }
            if ( ! $event || empty( $event->event_id ) ) {
                return $this->failure( __( 'Previously saved recurring Events Manager event could not be loaded; no duplicate was created.', 'great-imports' ) );
            }
        } else {
            $event = new EM_Event();
        }

        $data = $payload['event'];
        $event->event_type       = 'repeating';
        $event->post_type        = 'event-recurring';
        $event->event_archetype  = defined( 'EM_POST_TYPE_EVENT' ) ? EM_POST_TYPE_EVENT : 'event';
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

        $recurrence = $this->recurrence_payload_from_event_data( $data );
        if ( empty( $recurrence['success'] ) ) {
            return $this->failure( $recurrence['message'] );
        }

        $this->apply_daily_recurrence_set( $event, $recurrence );

        if ( ! method_exists( $event, 'save' ) || ! $event->save() || empty( $event->event_id ) ) {
            return $this->failure( $this->object_error( $event, __( 'Events Manager recurring event could not be saved.', 'great-imports' ) ) );
        }

        $post_id = ! empty( $event->post_id ) ? absint( $event->post_id ) : 0;
        if ( $post_id ) {
            update_post_meta( $post_id, '_gi_candidate_post_id', $candidate_id );
            update_post_meta( $post_id, '_gi_source_url', isset( $payload['source_identity']['source_url'] ) ? $payload['source_identity']['source_url'] : '' );
            update_post_meta( $post_id, '_gi_eventbrite_event_id', isset( $payload['source_identity']['eventbrite_event_id'] ) ? $payload['source_identity']['eventbrite_event_id'] : '' );
            update_post_meta( $post_id, '_gi_fingerprint', isset( $payload['source_identity']['fingerprint'] ) ? $payload['source_identity']['fingerprint'] : '' );
            update_post_meta( $post_id, '_event_type', 'repeating' );
        }

        return array(
            'success'               => true,
            'event_id'              => absint( $event->event_id ),
            'recurrence_set_id'     => $this->recurrence_set_id_for_event( $event ),
            'existing_event_id'     => $existing_id,
            'existing_match_source' => $existing_source,
        );
    }

    private function recurrence_payload_from_event_data( array $data ) {
        $start_date = isset( $data['event_start_date'] ) ? (string) $data['event_start_date'] : '';
        $end_date   = isset( $data['event_end_date'] ) ? (string) $data['event_end_date'] : '';
        $start_time = isset( $data['event_start_time'] ) ? $this->time_for_em( $data['event_start_time'], '00:00:00' ) : '00:00:00';
        $end_time   = isset( $data['event_end_time'] ) ? $this->time_for_em( $data['event_end_time'], '23:59:59' ) : '23:59:59';

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
            return array( 'success' => false, 'message' => __( 'Recurring candidate needs valid start and end dates.', 'great-imports' ) );
        }
        if ( strtotime( $start_date ) >= strtotime( $end_date ) ) {
            return array( 'success' => false, 'message' => __( 'Recurring candidate needs an end date after the start date.', 'great-imports' ) );
        }

        return array(
            'success'    => true,
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'start_time' => $start_time,
            'end_time'   => $end_time,
            'timezone'   => isset( $data['event_timezone'] ) ? (string) $data['event_timezone'] : '',
            'duration'   => strtotime( '2000-01-01 ' . $end_time ) <= strtotime( '2000-01-01 ' . $start_time ) ? 1 : 0,
        );
    }

    private function apply_daily_recurrence_set( $event, array $recurrence ) {
        $sets = $event->get_recurrence_sets();
        $set  = ! empty( $sets->default ) ? $sets->default : new \EM\Recurrences\Recurrence_Set( $event );

        $set->event               = $event;
        $set->recurrence_type     = 'include';
        $set->recurrence_order    = 1;
        $set->recurrence_freq     = 'daily';
        $set->recurrence_interval = 1;
        $set->recurrence_byday    = '';
        $set->recurrence_byweekno = 0;
        $set->recurrence_dates    = array();
        $set->recurrence_start_date = $recurrence['start_date'];
        $set->recurrence_end_date   = $recurrence['end_date'];
        $set->recurrence_start_time = $recurrence['start_time'];
        $set->recurrence_end_time   = $recurrence['end_time'];
        $set->recurrence_duration   = absint( $recurrence['duration'] );
        $set->recurrence_all_day    = 0;
        $set->recurrence_timezone   = $recurrence['timezone'];
        $set->recurrence_status     = 1;
        if ( method_exists( $set, 'set_reschedule' ) ) {
            $set->set_reschedule( true );
        }

        $timeranges = $set->get_timeranges();
        $existing_timeranges = method_exists( $timeranges, 'get_timeranges' ) ? $timeranges->get_timeranges() : array();
        if ( empty( $existing_timeranges ) ) {
            $timeranges->add(
                array(
                    'timerange_start'   => $recurrence['start_time'],
                    'timerange_end'     => $recurrence['end_time'],
                    'timerange_all_day' => 0,
                )
            );
        } else {
            foreach ( $existing_timeranges as $timerange ) {
                $timerange->timerange_start   = $recurrence['start_time'];
                $timerange->timerange_end     = $recurrence['end_time'];
                $timerange->timerange_all_day = 0;
                break;
            }
        }
        $timeranges->allow_edit = true;

        $sets->event = $event;
        $sets->include = array( $set );
        $sets->exclude = array();
        $sets->default = $set;
        $sets->reschedule = true;
        $event->recurrence_sets = $sets;
        $event->recurrence_set  = $set;
    }

    private function time_for_em( $value, $fallback ) {
        if ( preg_match( '/^(\d{2}:\d{2})(?::(\d{2}))?$/', (string) $value, $matches ) ) {
            return $matches[1] . ':' . ( isset( $matches[2] ) && '' !== $matches[2] ? $matches[2] : '00' );
        }

        return $fallback;
    }

    private function recurrence_set_id_for_event( $event ) {
        if ( ! is_object( $event ) || ! method_exists( $event, 'get_recurrence_sets' ) ) {
            return 0;
        }

        $sets = $event->get_recurrence_sets();
        if ( ! empty( $sets->default->recurrence_set_id ) ) {
            return absint( $sets->default->recurrence_set_id );
        }

        return 0;
    }

    private function existing_event_for_payload( $candidate_id, array $payload, $location_id ) {
        $candidate_event_id = absint( get_post_meta( $candidate_id, '_gi_em_event_id', true ) );
        if ( $candidate_event_id ) {
            return array( 'event_id' => $candidate_event_id, 'source' => 'candidate_em_event_id' );
        }

        $identity = isset( $payload['source_identity'] ) && is_array( $payload['source_identity'] ) ? $payload['source_identity'] : array();
        foreach ( array( 'eventbrite_event_id', 'fingerprint', 'source_url' ) as $key ) {
            $value = isset( $identity[ $key ] ) ? trim( (string) $identity[ $key ] ) : '';
            if ( '' === $value ) {
                continue;
            }

            $event_id = $this->existing_event_id_by_post_meta( '_gi_' . $key, $value );
            if ( $event_id ) {
                return array( 'event_id' => $event_id, 'source' => $key );
            }
        }

        $event_id = $this->existing_event_id_by_exact_payload( $payload, $location_id );
        if ( $event_id ) {
            return array( 'event_id' => $event_id, 'source' => 'exact_event_fields' );
        }

        return array( 'event_id' => 0, 'source' => '' );
    }

    private function existing_recurring_event_for_payload( $candidate_id, array $payload, $location_id ) {
        $candidate_event_id = absint( get_post_meta( $candidate_id, '_gi_em_recurring_event_id', true ) );
        if ( $candidate_event_id ) {
            return array( 'event_id' => $candidate_event_id, 'source' => 'candidate_em_recurring_event_id' );
        }

        $identity = isset( $payload['source_identity'] ) && is_array( $payload['source_identity'] ) ? $payload['source_identity'] : array();
        foreach ( array( 'eventbrite_event_id', 'fingerprint', 'source_url' ) as $key ) {
            $value = isset( $identity[ $key ] ) ? trim( (string) $identity[ $key ] ) : '';
            if ( '' === $value ) {
                continue;
            }

            $event_id = $this->existing_event_id_by_post_meta( '_gi_' . $key, $value, $this->recurring_event_post_types() );
            if ( $event_id ) {
                return array( 'event_id' => $event_id, 'source' => $key );
            }
        }

        $event_id = $this->existing_recurring_event_id_by_exact_payload( $payload, $location_id );
        if ( $event_id ) {
            return array( 'event_id' => $event_id, 'source' => 'exact_recurring_event_fields' );
        }

        return array( 'event_id' => 0, 'source' => '' );
    }

    private function existing_event_id_by_post_meta( $meta_key, $meta_value, array $post_types = array() ) {
        $post_types = empty( $post_types ) ? $this->event_post_types() : $post_types;
        $posts = get_posts(
            array(
                'post_type'      => $post_types,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'DESC',
                'meta_query'     => array(
                    array(
                        'key'   => sanitize_key( $meta_key ),
                        'value' => (string) $meta_value,
                    ),
                ),
            )
        );

        if ( empty( $posts[0] ) ) {
            return 0;
        }

        return $this->event_id_for_post_id( $posts[0] );
    }

    private function existing_recurring_event_id_by_exact_payload( array $payload, $location_id ) {
        global $wpdb;

        if ( empty( $payload['event'] ) || ! is_array( $payload['event'] ) ) {
            return 0;
        }

        $event = $payload['event'];
        $table = $wpdb->prefix . 'em_events';
        if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
            return 0;
        }

        $event_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT event_id FROM {$table} WHERE event_type = %s AND event_name = %s AND event_start_date = %s AND event_start_time = %s AND event_end_date = %s AND event_end_time = %s AND location_id = %d ORDER BY event_id DESC LIMIT 1",
                'repeating',
                isset( $event['event_name'] ) ? (string) $event['event_name'] : '',
                isset( $event['event_start_date'] ) ? (string) $event['event_start_date'] : '',
                isset( $event['event_start_time'] ) ? (string) $event['event_start_time'] : '',
                isset( $event['event_end_date'] ) ? (string) $event['event_end_date'] : '',
                isset( $event['event_end_time'] ) ? (string) $event['event_end_time'] : '',
                absint( $location_id )
            )
        );

        return $event_id ? absint( $event_id ) : 0;
    }

    private function existing_event_id_by_exact_payload( array $payload, $location_id ) {
        global $wpdb;

        if ( empty( $payload['event'] ) || ! is_array( $payload['event'] ) ) {
            return 0;
        }

        $event = $payload['event'];
        $table = $wpdb->prefix . 'em_events';
        if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
            return 0;
        }

        $event_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT event_id FROM {$table} WHERE event_name = %s AND event_start_date = %s AND event_start_time = %s AND event_end_date = %s AND event_end_time = %s AND location_id = %d ORDER BY event_id DESC LIMIT 1",
                isset( $event['event_name'] ) ? (string) $event['event_name'] : '',
                isset( $event['event_start_date'] ) ? (string) $event['event_start_date'] : '',
                isset( $event['event_start_time'] ) ? (string) $event['event_start_time'] : '',
                isset( $event['event_end_date'] ) ? (string) $event['event_end_date'] : '',
                isset( $event['event_end_time'] ) ? (string) $event['event_end_time'] : '',
                absint( $location_id )
            )
        );

        return $event_id ? absint( $event_id ) : 0;
    }

    private function event_id_for_post_id( $post_id ) {
        if ( ! $post_id ) {
            return 0;
        }

        if ( function_exists( 'em_get_event' ) ) {
            $event = em_get_event( absint( $post_id ), 'post_id' );
        } else {
            $event = new EM_Event( absint( $post_id ), 'post_id' );
        }

        return ! empty( $event->event_id ) ? absint( $event->event_id ) : 0;
    }

    private function event_post_types() {
        if ( defined( 'EM_POST_TYPE_EVENT' ) ) {
            return array( EM_POST_TYPE_EVENT );
        }

        return array( 'event' );
    }

    private function recurring_event_post_types() {
        return array( 'event-recurring' );
    }

    private function is_recurring_candidate( $candidate_id, array $payload ) {
        $raw = maybe_unserialize( get_post_meta( absint( $candidate_id ), '_gi_raw_event', true ) );
        if ( is_array( $raw ) ) {
            foreach ( array( 'is_series', 'is_series_parent' ) as $key ) {
                if ( ! empty( $raw[ $key ] ) ) {
                    return true;
                }
            }
            if ( ! empty( $raw['series_id'] ) ) {
                return true;
            }
        }

        $event      = isset( $payload['event'] ) && is_array( $payload['event'] ) ? $payload['event'] : array();
        $start_date = isset( $event['event_start_date'] ) ? (string) $event['event_start_date'] : '';
        $end_date   = isset( $event['event_end_date'] ) ? (string) $event['event_end_date'] : '';

        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) && $start_date !== $end_date;
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

    private function finish_trace( $candidate_id, array $trace, $success, $message, $meta_key = '_gi_em_import_trace' ) {
        $trace['completed_at'] = current_time( 'mysql' );
        $trace['status']       = $success ? 'succeeded' : 'failed';
        $trace['message']      = sanitize_text_field( (string) $message );
        update_post_meta( $candidate_id, sanitize_key( $meta_key ), $trace );
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
