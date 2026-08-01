<?php

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Great Imports intentionally synchronizes directly with Events Manager's native database tables and metadata.

final class GI_Events_Manager {
    private static array $column_cache = array();

    public static function events_table(): string {
        global $wpdb;
        return defined( 'EM_EVENTS_TABLE' ) ? (string) EM_EVENTS_TABLE : $wpdb->prefix . 'em_events';
    }

    public static function locations_table(): string {
        global $wpdb;
        return defined( 'EM_LOCATIONS_TABLE' ) ? (string) EM_LOCATIONS_TABLE : $wpdb->prefix . 'em_locations';
    }

    public static function event_post_type(): string {
        return defined( 'EM_POST_TYPE_EVENT' ) ? (string) EM_POST_TYPE_EVENT : 'event';
    }

    public static function location_post_type(): string {
        return defined( 'EM_POST_TYPE_LOCATION' ) ? (string) EM_POST_TYPE_LOCATION : 'location';
    }

    public static function category_taxonomy(): string {
        return defined( 'EM_TAXONOMY_CATEGORY' ) ? (string) EM_TAXONOMY_CATEGORY : 'event-categories';
    }

    public static function tag_taxonomy(): string {
        return defined( 'EM_TAXONOMY_TAG' ) ? (string) EM_TAXONOMY_TAG : 'event-tags';
    }

    public static function health(): array {
        global $wpdb;
        $events_table    = self::events_table();
        $locations_table = self::locations_table();
        $events_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) ) === $events_table;
        $locations_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $locations_table ) ) === $locations_table;
        $available = $events_exists && $locations_exists;
        return array(
            'available'       => $available,
            'version'         => defined( 'EM_VERSION' ) ? (string) EM_VERSION : '',
            'events_table'    => $events_exists ? $events_table : false,
            'locations_table' => $locations_exists ? $locations_table : false,
            'event_post_type' => post_type_exists( self::event_post_type() ),
            'location_post_type' => post_type_exists( self::location_post_type() ),
            'health' => array(
                'ok'       => $available,
                'blockers' => $available ? array() : array( __( 'Events Manager database tables were not detected.', 'great-imports' ) ),
                'warnings' => array_values( array_filter( array(
                    post_type_exists( self::event_post_type() ) ? '' : __( 'Events Manager event post type is not registered.', 'great-imports' ),
                    post_type_exists( self::location_post_type() ) ? '' : __( 'Events Manager location post type is not registered.', 'great-imports' ),
                ) ) ),
            ),
        );
    }

    public static function list_locations(): array {
        global $wpdb;
        $table = self::locations_table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return array();
        }
        $columns = self::columns( $table );
        $select = array_values( array_intersect( array( 'location_id', 'post_id', 'blog_id', 'location_name', 'location_owner', 'location_address', 'location_town', 'location_state', 'location_postcode', 'location_country', 'location_latitude', 'location_longitude', 'location_status', 'location_private' ), $columns ) );
        if ( ! in_array( 'location_id', $select, true ) ) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Events Manager locations are authoritative in its custom table.
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY location_name ASC', $table ), ARRAY_A );
        return array_map(
            static function ( array $row ): array {
                return array(
                    'location_id'      => absint( $row['location_id'] ?? 0 ),
                    'post_id'          => absint( $row['post_id'] ?? 0 ),
                    'blog_id'          => absint( $row['blog_id'] ?? 0 ),
                    'location_name'    => sanitize_text_field( $row['location_name'] ?? '' ),
                    'location_owner'   => absint( $row['location_owner'] ?? 0 ),
                    'location_address' => sanitize_text_field( $row['location_address'] ?? '' ),
                    'location_city'    => sanitize_text_field( $row['location_town'] ?? '' ),
                    'location_state'   => sanitize_text_field( $row['location_state'] ?? '' ),
                    'location_postcode'=> sanitize_text_field( $row['location_postcode'] ?? '' ),
                    'location_country' => sanitize_text_field( $row['location_country'] ?? '' ),
                    'location_latitude' => GI_Utils::sanitize_coordinate( $row['location_latitude'] ?? '', 'latitude' ),
                    'location_longitude'=> GI_Utils::sanitize_coordinate( $row['location_longitude'] ?? '', 'longitude' ),
                    'location_status'  => array_key_exists( 'location_status', $row ) && null !== $row['location_status'] ? (int) $row['location_status'] : null,
                    'location_private' => absint( $row['location_private'] ?? 0 ),
                );
            },
            $rows ?: array()
        );
    }

    public static function find_matching_event_post( array $candidate ): int {
        return self::find_existing_event_post( GI_Normalizer::finalize_candidate( $candidate ) );
    }

    public static function matching_locations( array $candidate ): array {
        return self::match_locations( $candidate );
    }

    public static function import_candidate( array $candidate, string $action = 'draft', array $source_rules = array() ): array|WP_Error {
        $candidate = GI_Normalizer::finalize_candidate( $candidate );
        if ( 'series' === ( $candidate['recurrence_mode'] ?? 'single' ) ) {
            return self::import_series_candidate( $candidate, $action, $source_rules );
        }
        return self::import_single_candidate( $candidate, $action, $source_rules );
    }

    /**
     * Restore the canonical Events Manager links and metadata for a retained
     * Great Imports candidate. Events Manager loads public event pages through
     * _event_id, so a WordPress post and em_events row are not sufficient by
     * themselves.
     */
    public static function repair_candidate_links( array $candidate ): bool {
        global $wpdb;

        $post_id = absint( $candidate['em_post_id'] ?? 0 );
        $event_id = absint( $candidate['em_event_id'] ?? 0 );
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || self::event_post_type() !== $post->post_type || ! $event_id ) {
            return false;
        }
        $table = self::events_table();
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d AND post_id = %d LIMIT 1', $table, $event_id, $post_id ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            return false;
        }
        $row_updates = self::filter_columns(
            $table,
            array(
                'post_id' => $post_id,
                'event_slug' => $post->post_name,
                'event_name' => $post->post_title,
                'event_owner' => (int) $post->post_author,
                'event_status' => 'publish' === $post->post_status ? 1 : 0,
                'event_active_status' => 1,
                'location_id' => absint( $candidate['em_location_id'] ?? $row['location_id'] ?? 0 ),
            )
        );
        $location_id = absint( $candidate['em_location_id'] ?? $row['location_id'] ?? 0 );
        if ( $location_id ) {
            $location = self::get_location( $location_id );
            if ( $location ) {
                self::sync_location_coordinates( $location, $candidate );
            }
        }
        if ( false === $wpdb->update( $table, $row_updates, array( 'event_id' => $event_id ) ) ) {
            return false;
        }
        self::save_event_meta( $post_id, GI_Normalizer::finalize_candidate( $candidate ), $event_id, $location_id );
        if ( ! has_post_thumbnail( $post_id ) && ! empty( $candidate['image_url'] ) ) {
            $image_result = self::sideload_image( $post_id, (string) $candidate['image_url'] );
            if ( is_wp_error( $image_result ) ) {
                update_post_meta( $post_id, '_gi_image_import_error', $image_result->get_error_message() );
            } elseif ( $image_result ) {
                delete_post_meta( $post_id, '_gi_image_import_error' );
            }
        }
        clean_post_cache( $post_id );
        return $event_id === absint( get_post_meta( $post_id, '_event_id', true ) );
    }

    private static function import_single_candidate( array $candidate, string $action = 'draft', array $source_rules = array() ): array|WP_Error {
        $health = self::health();
        if ( empty( $health['available'] ) ) {
            return new WP_Error( 'gi_em_unavailable', __( 'Events Manager is not available.', 'great-imports' ) );
        }
        $action = in_array( $action, array( 'draft', 'publish' ), true ) ? $action : 'draft';
        $candidate = self::apply_source_rules( $candidate, $source_rules );
        $candidate = GI_Normalizer::finalize_candidate( $candidate );
        $existing_post_id = self::find_existing_event_post( $candidate );
        if ( $existing_post_id ) {
            $candidate = self::merge_prior_import_data( $candidate, $existing_post_id );
            if ( ! empty( $source_rules['protect_local_edits'] ) ) {
                $preserved = self::preserve_local_event_fields( $candidate, $existing_post_id, $source_rules );
                $candidate = $preserved['candidate'];
                $source_rules = $preserved['rules'];
            }
            $candidate = GI_Normalizer::finalize_candidate( $candidate );
        }
        if ( empty( $candidate['title'] ) || empty( $candidate['start_date'] ) ) {
            return new WP_Error( 'gi_candidate_incomplete', __( 'The event requires a title and start date before import.', 'great-imports' ) );
        }

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        try {
            $location = self::resolve_location( $candidate, $source_rules );
            if ( is_wp_error( $location ) ) {
                throw new RuntimeException( $location->get_error_message() );
            }
            $post_status = 'publish' === $action ? 'publish' : 'draft';
            $final_title = sanitize_text_field( $candidate['title'] );
            $final_content = self::build_description( $candidate, $source_rules );
            if ( $existing_post_id && ! empty( $source_rules['protect_local_edits'] ) ) {
                $existing_post = get_post( $existing_post_id );
                $snapshot = get_post_meta( $existing_post_id, '_gi_import_snapshot', true );
                if ( $existing_post && is_array( $snapshot ) ) {
                    if ( array_key_exists( 'title', $snapshot ) && (string) $existing_post->post_title !== (string) $snapshot['title'] ) {
                        $final_title = (string) $existing_post->post_title;
                    }
                    if ( array_key_exists( 'content', $snapshot ) && (string) $existing_post->post_content !== (string) $snapshot['content'] ) {
                        $final_content = (string) $existing_post->post_content;
                    }
                }
            }
            $candidate['_final_title'] = $final_title;
            $candidate['_final_content'] = $final_content;
            $event_author_id = self::event_author_id( $source_rules );
            $postarr = array(
                'post_type'    => self::event_post_type(),
                'post_status'  => $post_status,
                'post_title'   => $final_title,
                'post_content' => $final_content,
                'post_excerpt' => '',
                'post_author'  => $event_author_id,
            );
            if ( $existing_post_id ) {
                $postarr['ID'] = $existing_post_id;
                $post_id = wp_update_post( wp_slash( $postarr ), true );
                $updated = true;
            } else {
                $post_id = wp_insert_post( wp_slash( $postarr ), true );
                $updated = false;
            }
            if ( is_wp_error( $post_id ) ) {
                throw new RuntimeException( $post_id->get_error_message() );
            }
            $post_id = (int) $post_id;

            $event_row = self::save_event_row( $post_id, $candidate, (int) ( $location['location_id'] ?? 0 ), $post_status, $event_author_id );
            if ( is_wp_error( $event_row ) ) {
                throw new RuntimeException( $event_row->get_error_message() );
            }
            $timeslot_result = self::save_festival_timeslots( (int) $event_row['event_id'], $post_id, $candidate, 'publish' === $post_status ? 1 : 0 );
            if ( is_wp_error( $timeslot_result ) ) {
                throw new RuntimeException( $timeslot_result->get_error_message() );
            }
            if ( $timeslot_result ) {
                $candidate['_gi_festival_timeslot_ids'] = $timeslot_result;
            }
            self::save_event_meta( $post_id, $candidate, (int) $event_row['event_id'], (int) ( $location['location_id'] ?? 0 ) );
            self::assign_categories( $post_id, $candidate, $source_rules );
            self::assign_tags( $post_id, $candidate );
            if ( 'import' === ( $source_rules['image_policy'] ?? 'import' ) ) {
                $image_result = self::sideload_image( $post_id, $candidate['image_url'] ?? '' );
                if ( is_wp_error( $image_result ) ) {
                    update_post_meta( $post_id, '_gi_image_import_error', $image_result->get_error_message() );
                } elseif ( $image_result ) {
                    delete_post_meta( $post_id, '_gi_image_import_error' );
                }
                if ( ! empty( $source_rules['require_image'] ) && ( is_wp_error( $image_result ) || ! $image_result ) ) {
                    throw new RuntimeException( is_wp_error( $image_result ) ? $image_result->get_error_message() : __( 'The required event image could not be imported.', 'great-imports' ) );
                }
            }
            $status_result = self::ensure_event_post_status( $post_id, $post_status );
            if ( is_wp_error( $status_result ) ) {
                throw new RuntimeException( $status_result->get_error_message() );
            }

            $wpdb->query( 'COMMIT' );
            return array(
                'updated'        => $updated,
                'post_id'        => $post_id,
                'event_id'       => (int) $event_row['event_id'],
                'location_id'    => (int) ( $location['location_id'] ?? 0 ),
                'location_post_id'=> (int) ( $location['post_id'] ?? 0 ),
                'post_status'    => $post_status,
            );
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'gi_import_failed', $e->getMessage() );
        }
    }

    /**
     * Events Manager can normalize a newly inserted event post to draft before
     * its matching database row exists. Synchronize once more after the row,
     * metadata, and taxonomies have been written, then verify the visible
     * WordPress status before reporting a successful import.
     */
    private static function ensure_event_post_status( int $post_id, string $post_status ): true|WP_Error {
        $post_status = 'publish' === $post_status ? 'publish' : 'draft';
        clean_post_cache( $post_id );
        if ( $post_status === get_post_status( $post_id ) ) {
            return true;
        }

        $result = wp_update_post(
            array(
                'ID'          => $post_id,
                'post_status' => $post_status,
            ),
            true
        );
        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                'gi_event_status_failed',
                sprintf(
                    /* translators: 1: requested WordPress post status, 2: status update error message. */
                    __( 'The event was written to Events Manager, but its WordPress status could not be changed to %1$s: %2$s', 'great-imports' ),
                    $post_status,
                    $result->get_error_message()
                )
            );
        }

        clean_post_cache( $post_id );
        $actual_status = (string) get_post_status( $post_id );
        if ( $post_status !== $actual_status ) {
            return new WP_Error(
                'gi_event_status_mismatch',
                sprintf(
                    /* translators: 1: requested WordPress post status, 2: actual WordPress post status. */
                    __( 'The event was requested as %1$s, but WordPress saved it as %2$s. No success was reported.', 'great-imports' ),
                    $post_status,
                    $actual_status ?: __( 'unknown', 'great-imports' )
                )
            );
        }
        return true;
    }

    private static function preserve_local_event_fields( array $candidate, int $post_id, array $rules ): array {
        $snapshot = get_post_meta( $post_id, '_gi_import_snapshot', true );
        if ( ! is_array( $snapshot ) ) {
            return array( 'candidate' => $candidate, 'rules' => $rules );
        }
        global $wpdb;
        $table = self::events_table();
        $columns = self::columns( $table );
        $wanted = array_values( array_intersect( array( 'event_start_date', 'event_start_time', 'event_end_date', 'event_end_time', 'location_id' ), $columns ) );
        if ( ! $wanted ) {
            return array( 'candidate' => $candidate, 'rules' => $rules );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Local-edit protection compares against the authoritative Events Manager row.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE post_id = %d ORDER BY event_id ASC LIMIT 1',
                $table,
                $post_id
            ),
            ARRAY_A
        );
        if ( ! is_array( $row ) ) {
            return array( 'candidate' => $candidate, 'rules' => $rules );
        }
        $map = array(
            'start_date' => 'event_start_date',
            'start_time' => 'event_start_time',
            'end_date'   => 'event_end_date',
            'end_time'   => 'event_end_time',
        );
        foreach ( $map as $candidate_key => $row_key ) {
            if ( array_key_exists( $candidate_key, $snapshot ) && isset( $row[ $row_key ] ) && (string) $row[ $row_key ] !== (string) $snapshot[ $candidate_key ] ) {
                $candidate[ $candidate_key ] = sanitize_text_field( $row[ $row_key ] );
            }
        }
        $current_location_id = absint( $row['location_id'] ?? 0 );
        if ( array_key_exists( 'location_id', $snapshot ) && $current_location_id && $current_location_id !== absint( $snapshot['location_id'] ) ) {
            $rules['force_location_enabled'] = 1;
            $rules['forced_em_location_id'] = $current_location_id;
        }
        return array( 'candidate' => $candidate, 'rules' => $rules );
    }

    private static function merge_prior_import_data( array $candidate, int $post_id ): array {
        $prior = get_post_meta( $post_id, '_gi_import_data', true );
        if ( ! is_array( $prior ) ) {
            return $candidate;
        }
        foreach ( array(
            'description', 'end_date', 'end_time', 'timezone', 'event_url', 'ticket_url', 'price', 'currency',
            'image_url', 'location_name', 'location_address', 'location_city', 'location_state', 'location_postcode',
            'location_country', 'location_latitude', 'location_longitude', 'parent_location_name', 'stage_name', 'organizer',
        ) as $field ) {
            if ( ! GI_Utils::has_meaningful_value( $candidate[ $field ] ?? '' ) && GI_Utils::has_meaningful_value( $prior[ $field ] ?? '' ) ) {
                $candidate[ $field ] = $prior[ $field ];
            }
        }
        $candidate['categories'] = array_values( array_unique( array_filter( array_merge( (array) ( $prior['categories'] ?? array() ), (array) ( $candidate['categories'] ?? array() ) ) ) ) );
        $candidate['tags'] = array_values( array_unique( array_filter( array_merge( (array) ( $prior['tags'] ?? array() ), (array) ( $candidate['tags'] ?? array() ) ) ) ) );
        $candidate['source_urls'] = array_values( array_unique( array_filter( array_merge( (array) ( $prior['source_urls'] ?? array() ), (array) ( $candidate['source_urls'] ?? array() ) ) ) ) );
        return $candidate;
    }

    private static function import_series_candidate( array $candidate, string $action, array $source_rules ): array|WP_Error {
        $instances = self::expand_series( $candidate );
        if ( is_wp_error( $instances ) ) {
            return $instances;
        }
        $series_id = sanitize_text_field( $candidate['source_uid'] ?? $candidate['uid'] ?? '' );
        if ( ! $series_id ) {
            $series_id = hash( 'sha256', GI_Utils::fingerprint( $candidate ) . '|' . wp_json_encode( array_intersect_key( $candidate, array_flip( array( 'recurrence_frequency', 'recurrence_interval', 'recurrence_until', 'recurrence_count', 'recurrence_weekdays' ) ) ) ) );
        }
        $post_ids = array();
        $event_ids = array();
        $location_ids = array();
        $created = 0;
        $updated = 0;
        foreach ( $instances as $index => $instance ) {
            if ( $index > 0 ) {
                unset( $instance['em_post_id'], $instance['em_event_id'], $instance['em_location_id'] );
            }
            $instance['recurrence_mode'] = 'single';
            $instance['recurrence_series_id'] = $series_id;
            $instance['recurrence_occurrence_index'] = $index + 1;
            $instance['recurrence_source_rule'] = array_intersect_key( $candidate, array_flip( array( 'recurrence_frequency', 'recurrence_interval', 'recurrence_until', 'recurrence_count', 'recurrence_weekdays', 'recurrence_rule' ) ) );
            $base_uid = sanitize_text_field( $candidate['uid'] ?? $candidate['source_uid'] ?? $series_id );
            $instance['uid'] = $base_uid . '#' . str_replace( array( '-', ':' ), '', $instance['start_date'] . 'T' . $instance['start_time'] );
            $instance['source_uid'] = $instance['uid'];
            $instance['fingerprint'] = GI_Utils::fingerprint( $instance );
            $result = self::import_single_candidate( $instance, $action, $source_rules );
            if ( is_wp_error( $result ) ) {
                return new WP_Error(
                    'gi_series_import_failed',
                    sprintf(
                        /* translators: 1: occurrence number, 2: import error message. */
                        __( 'Occurrence %1$d could not be imported: %2$s', 'great-imports' ),
                        $index + 1,
                        $result->get_error_message()
                    )
                );
            }
            $post_ids[] = (int) $result['post_id'];
            $event_ids[] = (int) $result['event_id'];
            $location_ids[] = (int) $result['location_id'];
            ! empty( $result['updated'] ) ? ++$updated : ++$created;
        }
        return array(
            'post_id' => (int) ( $post_ids[0] ?? 0 ),
            'event_id' => (int) ( $event_ids[0] ?? 0 ),
            'location_id' => (int) ( $location_ids[0] ?? 0 ),
            'post_ids' => $post_ids,
            'event_ids' => $event_ids,
            'location_ids' => array_values( array_unique( $location_ids ) ),
            'series_id' => $series_id,
            'occurrence_count' => count( $instances ),
            'created_count' => $created,
            'updated_count' => $updated,
            'updated' => 0 === $created && $updated > 0,
        );
    }

    private static function expand_series( array $candidate ): array|WP_Error {
        $frequency = sanitize_key( $candidate['recurrence_frequency'] ?? '' );
        if ( ! in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ) {
            return new WP_Error( 'gi_series_frequency_missing', __( 'Choose a valid event-series frequency.', 'great-imports' ) );
        }
        $count_limit = min( 500, max( 0, absint( $candidate['recurrence_count'] ?? 0 ) ) );
        $until = GI_Utils::parse_datetime( $candidate['recurrence_until'] ?? '' );
        if ( ! $count_limit && ! $until ) {
            return new WP_Error( 'gi_series_boundary_missing', __( 'Choose an occurrence count or end date for the event series.', 'great-imports' ) );
        }
        $timezone_name = sanitize_text_field( $candidate['timezone'] ?? wp_timezone_string() );
        try {
            $timezone = in_array( $timezone_name, timezone_identifiers_list(), true ) ? new DateTimeZone( $timezone_name ) : wp_timezone();
        } catch ( Throwable $e ) {
            $timezone = wp_timezone();
        }
        $start = GI_Utils::parse_datetime( trim( (string) $candidate['start_date'] . ' ' . (string) ( $candidate['start_time'] ?: '00:00:00' ) ), $timezone );
        $end = GI_Utils::parse_datetime( trim( (string) ( $candidate['end_date'] ?: $candidate['start_date'] ) . ' ' . (string) ( $candidate['end_time'] ?: '23:59:59' ) ), $timezone );
        if ( ! $start || ! $end ) {
            return new WP_Error( 'gi_series_dates_invalid', __( 'The event-series start or end date is invalid.', 'great-imports' ) );
        }
        $duration = $start->diff( $end );
        $interval = min( 365, max( 1, absint( $candidate['recurrence_interval'] ?? 1 ) ) );
        $weekdays = array_values( array_intersect( array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ), (array) ( $candidate['recurrence_weekdays'] ?? array() ) ) );
        if ( 'weekly' === $frequency && ! $weekdays ) {
            $weekdays = array( array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' )[ (int) $start->format( 'w' ) ] );
        }
        $until_day = $until ? $until->setTime( 23, 59, 59 ) : null;
        $instances = array();
        $cursor = $start;
        $base_week = $start->modify( 'sunday this week' )->setTime( 0, 0, 0 );
        $guard = 0;
        while ( count( $instances ) < 500 && ++$guard < 20000 ) {
            if ( $until_day && $cursor > $until_day ) {
                break;
            }
            $include = true;
            if ( 'weekly' === $frequency ) {
                // Use calendar days rather than timestamp seconds so a daylight-saving
                // transition cannot shift a biweekly pattern into the wrong week.
                $cursor_day = $cursor->setTime( 0, 0, 0 );
                $calendar_days = (int) $base_week->diff( $cursor_day )->format( '%a' );
                $week_index = (int) floor( $calendar_days / 7 );
                $day_code = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' )[ (int) $cursor->format( 'w' ) ];
                $include = 0 === $week_index % $interval && in_array( $day_code, $weekdays, true );
            }
            if ( $include && $cursor >= $start ) {
                $instance = $candidate;
                $instance['start_date'] = $cursor->format( 'Y-m-d' );
                $instance['start_time'] = ! empty( $candidate['all_day'] ) ? '00:00:00' : $cursor->format( 'H:i:s' );
                $instance_end = $cursor->add( $duration );
                $instance['end_date'] = $instance_end->format( 'Y-m-d' );
                $instance['end_time'] = ! empty( $candidate['all_day'] ) ? '23:59:59' : $instance_end->format( 'H:i:s' );
                $instances[] = $instance;
                if ( $count_limit && count( $instances ) >= $count_limit ) {
                    break;
                }
            }
            if ( 'daily' === $frequency ) {
                $cursor = $cursor->modify( '+' . $interval . ' days' );
            } elseif ( 'monthly' === $frequency ) {
                $day = (int) $start->format( 'j' );
                $next = $cursor->modify( 'first day of +' . $interval . ' months' );
                $last_day = (int) $next->format( 't' );
                $cursor = $next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'm' ), min( $day, $last_day ) )->setTime( (int) $start->format( 'H' ), (int) $start->format( 'i' ), (int) $start->format( 's' ) );
            } else {
                $cursor = $cursor->modify( '+1 day' );
            }
        }
        if ( ! $instances ) {
            return new WP_Error( 'gi_series_empty', __( 'The event-series rules do not produce any occurrences.', 'great-imports' ) );
        }
        return $instances;
    }

    private static function apply_source_rules( array $candidate, array $rules ): array {
        $candidate = GI_Utils::apply_location_mappings( $candidate, $rules );
        if ( ! empty( $rules['location_name_override'] ) ) {
            $candidate['location_name'] = sanitize_text_field( $rules['location_name_override'] );
        }
        $candidate['structure'] = sanitize_key( $rules['structure'] ?? 'auto' );
        // Stages and rooms remain event metadata. Events Manager receives the
        // parent physical venue as location_id; public display can use the
        // stage-at-venue label stored in Great Imports metadata.
        if ( ! empty( $candidate['stage_name'] ) && ! empty( $candidate['parent_location_name'] ) ) {
            $candidate['location_name'] = sanitize_text_field( $candidate['parent_location_name'] );
        }
        return GI_Utils::normalize_location_fields( $candidate );
    }

    private static function resolve_location( array $candidate, array $rules ): array|WP_Error {
        $selected_id = absint( $candidate['em_location_id'] ?? 0 );
        if ( $selected_id ) {
            $location = self::get_location( $selected_id );
            if ( ! $location ) {
                return new WP_Error( 'gi_selected_location_missing', __( 'The Events Manager location selected for this candidate no longer exists.', 'great-imports' ) );
            }
            return self::sync_location_coordinates( $location, $candidate );
        }
        $forced_id = ! empty( $rules['force_location_enabled'] ) ? absint( $rules['forced_em_location_id'] ?? 0 ) : 0;
        if ( $forced_id ) {
            $location = self::get_location( $forced_id );
            if ( ! $location ) {
                return new WP_Error( 'gi_forced_location_missing', __( 'The forced Events Manager location no longer exists.', 'great-imports' ) );
            }
            return self::sync_location_coordinates( $location, $candidate );
        }
        $matches = self::match_locations( $candidate );
        if ( 1 === count( $matches ) ) {
            return self::sync_location_coordinates( $matches[0], $candidate );
        }
        if ( count( $matches ) > 1 ) {
            return new WP_Error( 'gi_location_ambiguous', __( 'More than one existing location matches. Select a forced location in Source rules.', 'great-imports' ) );
        }
        if ( empty( $candidate['location_name'] ) && empty( $candidate['location_address'] ) ) {
            return new WP_Error( 'gi_location_missing', __( 'A location or forced-location rule is required before import.', 'great-imports' ) );
        }
        if ( 'existing_only' === ( $rules['location_policy'] ?? 'auto_create' ) ) {
            return new WP_Error( 'gi_location_existing_required', __( 'This source is limited to existing Events Manager locations. Choose a forced location or correct the candidate.', 'great-imports' ) );
        }
        return self::create_location( $candidate );
    }

    private static function match_locations( array $candidate ): array {
        $candidate = self::canonical_location_candidate( $candidate );
        $name = GI_Utils::normalize_text( $candidate['location_name'] ?? '' );
        $address = GI_Utils::normalize_address( $candidate['location_address'] ?? '' );
        $city = GI_Utils::normalize_text( $candidate['location_city'] ?? '' );
        $matches = array();
        foreach ( self::list_locations() as $location ) {
            $row_name = GI_Utils::normalize_text( $location['location_name'] );
            $row_address = GI_Utils::normalize_address( $location['location_address'] );
            $row_city = GI_Utils::normalize_text( $location['location_city'] );
            $exact_name_address = $name && $address && $name === $row_name && $address === $row_address;
            $exact_address = $address && $address === $row_address && ( ! $city || ! $row_city || $city === $row_city );
            $exact_name = $name && $name === $row_name && ( ! $address || ! $row_address );
            if ( $exact_name_address || $exact_address || $exact_name ) {
                $matches[ $location['location_id'] ] = $location;
            }
        }
        return array_values( $matches );
    }

    private static function canonical_location_candidate( array $candidate ): array {
        $candidate = GI_Utils::normalize_location_fields( $candidate );
        if ( GI_Utils::has_meaningful_value( $candidate['parent_location_name'] ?? '' ) && GI_Utils::has_meaningful_value( $candidate['stage_name'] ?? '' ) ) {
            $candidate['location_name'] = sanitize_text_field( $candidate['parent_location_name'] );
        }
        return $candidate;
    }

    private static function get_location( int $location_id ): array {
        foreach ( self::list_locations() as $location ) {
            if ( $location_id === (int) $location['location_id'] ) {
                return $location;
            }
        }
        return array();
    }

    private static function create_location( array $candidate ): array|WP_Error {
        global $wpdb;
        $candidate = self::canonical_location_candidate( $candidate );
        $name = sanitize_text_field( $candidate['location_name'] ?: $candidate['location_address'] );
        $post_id = wp_insert_post(
            wp_slash(
                array(
                    'post_type'    => self::location_post_type(),
                    'post_status'  => 'publish',
                    'post_title'   => $name,
                    'post_content' => '',
                    'post_author'  => get_current_user_id() ?: 1,
                )
            ),
            true
        );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        $post_id = (int) $post_id;
        $owner_id = get_current_user_id() ?: 1;
        $table = self::locations_table();
        $existing_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT location_id FROM %i WHERE post_id = %d LIMIT 1', $table, $post_id ) );
        $location_data = array(
            'post_id'           => $post_id,
            'location_parent'   => 0,
            'location_slug'     => sanitize_title( $name ),
            'location_name'     => $name,
            'location_owner'    => $owner_id,
            'location_address'  => sanitize_text_field( $candidate['location_address'] ?? '' ),
            'location_town'     => sanitize_text_field( $candidate['location_city'] ?? '' ),
            'location_state'    => sanitize_text_field( $candidate['location_state'] ?? '' ),
            'location_postcode' => sanitize_text_field( $candidate['location_postcode'] ?? '' ),
            'location_region'   => sanitize_text_field( $candidate['location_state'] ?? '' ),
            'location_country'  => sanitize_text_field( $candidate['location_country'] ?: 'US' ),
            'location_status'   => 1,
            'location_private'  => 0,
        );
        if ( is_multisite() ) {
            $location_data['blog_id'] = get_current_blog_id();
        }
        if ( GI_Utils::has_coordinate_pair( $candidate ) ) {
            $location_data['location_latitude'] = GI_Utils::sanitize_coordinate( $candidate['location_latitude'] ?? '', 'latitude' );
            $location_data['location_longitude'] = GI_Utils::sanitize_coordinate( $candidate['location_longitude'] ?? '', 'longitude' );
        }
        $data = self::filter_columns(
            $table,
            $location_data
        );
        if ( $existing_id ) {
            $ok = $wpdb->update( $table, $data, array( 'location_id' => $existing_id ) );
            $location_id = $existing_id;
        } else {
            $ok = $wpdb->insert( $table, $data );
            $location_id = (int) $wpdb->insert_id;
        }
        if ( false === $ok || ! $location_id ) {
            wp_delete_post( $post_id, true );
            return new WP_Error( 'gi_location_insert_failed', $wpdb->last_error ?: __( 'Events Manager location row was not created.', 'great-imports' ) );
        }
        self::save_location_meta( $post_id, $candidate, $location_id, $owner_id );
        return array(
            'location_id'       => $location_id,
            'post_id'           => $post_id,
            'location_name'     => $name,
            'location_address'  => $candidate['location_address'] ?? '',
            'location_city'     => $candidate['location_city'] ?? '',
            'location_state'    => $candidate['location_state'] ?? '',
            'location_postcode' => $candidate['location_postcode'] ?? '',
            'location_country'  => $candidate['location_country'] ?? 'US',
            'location_latitude' => GI_Utils::sanitize_coordinate( $candidate['location_latitude'] ?? '', 'latitude' ),
            'location_longitude'=> GI_Utils::sanitize_coordinate( $candidate['location_longitude'] ?? '', 'longitude' ),
        );
    }

    private static function save_location_meta( int $post_id, array $candidate, int $location_id, int $owner_id = 0 ): void {
        $owner_id = $owner_id ?: ( get_current_user_id() ?: 1 );
        $meta = array(
            '_gi_em_location_id' => $location_id,
            '_gi_created_location' => 1,
            '_location_id' => $location_id,
            '_location_name' => sanitize_text_field( $candidate['location_name'] ?? '' ),
            '_location_owner' => $owner_id,
            '_location_address' => sanitize_text_field( $candidate['location_address'] ?? '' ),
            '_location_town' => sanitize_text_field( $candidate['location_city'] ?? '' ),
            '_location_state' => sanitize_text_field( $candidate['location_state'] ?? '' ),
            '_location_postcode' => sanitize_text_field( $candidate['location_postcode'] ?? '' ),
            '_location_region' => sanitize_text_field( $candidate['location_state'] ?? '' ),
            '_location_country' => sanitize_text_field( $candidate['location_country'] ?: 'US' ),
            '_location_status' => 1,
            '_location_private' => 0,
        );
        if ( GI_Utils::has_coordinate_pair( $candidate ) ) {
            $meta['_location_latitude'] = GI_Utils::sanitize_coordinate( $candidate['location_latitude'] ?? '', 'latitude' );
            $meta['_location_longitude'] = GI_Utils::sanitize_coordinate( $candidate['location_longitude'] ?? '', 'longitude' );
        }
        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    private static function sync_location_coordinates( array $location, array $candidate ): array {
        $candidate = self::canonical_location_candidate( $candidate );
        $has_coordinates = GI_Utils::has_coordinate_pair( $candidate );
        $latitude = $has_coordinates ? GI_Utils::sanitize_coordinate( $candidate['location_latitude'] ?? '', 'latitude' ) : '';
        $longitude = $has_coordinates ? GI_Utils::sanitize_coordinate( $candidate['location_longitude'] ?? '', 'longitude' ) : '';

        global $wpdb;
        $location_id = absint( $location['location_id'] ?? 0 );
        $post_id = absint( $location['post_id'] ?? 0 );
        $table = self::locations_table();
        $columns = self::columns( $table );
        $updates = array();
        if ( in_array( 'location_owner', $columns, true ) && ! absint( $location['location_owner'] ?? 0 ) ) {
            $updates['location_owner'] = get_current_user_id() ?: 1;
        }
        if ( in_array( 'location_status', $columns, true ) && ( ! array_key_exists( 'location_status', $location ) || null === $location['location_status'] || '' === $location['location_status'] ) ) {
            $updates['location_status'] = 1;
        }
        if ( in_array( 'location_private', $columns, true ) && ( ! array_key_exists( 'location_private', $location ) || '' === $location['location_private'] ) ) {
            $updates['location_private'] = 0;
        }
        if ( is_multisite() && in_array( 'blog_id', $columns, true ) && ! absint( $location['blog_id'] ?? 0 ) ) {
            $updates['blog_id'] = get_current_blog_id();
        }
        $detail_fields = array(
            'location_address' => 'location_address',
            'location_town' => 'location_city',
            'location_state' => 'location_state',
            'location_postcode' => 'location_postcode',
            'location_country' => 'location_country',
        );
        foreach ( $detail_fields as $column => $candidate_key ) {
            if ( ! in_array( $column, $columns, true ) ) {
                continue;
            }
            $incoming = sanitize_text_field( (string) ( $candidate[ $candidate_key ] ?? '' ) );
            $location_key = 'location_town' === $column ? 'location_city' : $column;
            $current = sanitize_text_field( (string) ( $location[ $location_key ] ?? '' ) );
            $clearly_invalid_country = 'location_country' === $column
                && $incoming
                && strtoupper( $current ) === strtoupper( (string) ( $location['location_state'] ?? '' ) )
                && strtoupper( $incoming ) !== strtoupper( $current );
            if ( $incoming && ( '' === trim( $current ) || $clearly_invalid_country ) ) {
                $updates[ $column ] = $incoming;
                $location[ $location_key ] = $incoming;
            }
        }
        if ( '' !== $latitude && '' !== $longitude && in_array( 'location_latitude', $columns, true ) && ! GI_Utils::sanitize_coordinate( $location['location_latitude'] ?? '', 'latitude' ) ) {
            $updates['location_latitude'] = $latitude;
        }
        if ( '' !== $latitude && '' !== $longitude && in_array( 'location_longitude', $columns, true ) && ! GI_Utils::sanitize_coordinate( $location['location_longitude'] ?? '', 'longitude' ) ) {
            $updates['location_longitude'] = $longitude;
        }
        if ( $location_id && $updates ) {
            $wpdb->update( $table, $updates, array( 'location_id' => $location_id ) );
            $location = array_merge( $location, $updates );
        }
        if ( $post_id ) {
            if ( ! get_post_meta( $post_id, '_location_id', true ) && $location_id ) {
                update_post_meta( $post_id, '_location_id', $location_id );
            }
            if ( ! absint( get_post_meta( $post_id, '_location_owner', true ) ) ) {
                update_post_meta( $post_id, '_location_owner', get_current_user_id() ?: 1 );
            }
            if ( '' === (string) get_post_meta( $post_id, '_location_status', true ) ) {
                update_post_meta( $post_id, '_location_status', 1 );
            }
            if ( '' === (string) get_post_meta( $post_id, '_location_private', true ) ) {
                update_post_meta( $post_id, '_location_private', 0 );
            }
            if ( '' !== $latitude && '' !== $longitude && ! GI_Utils::sanitize_coordinate( get_post_meta( $post_id, '_location_latitude', true ), 'latitude' ) ) {
                update_post_meta( $post_id, '_location_latitude', $latitude );
            }
            if ( '' !== $latitude && '' !== $longitude && ! GI_Utils::sanitize_coordinate( get_post_meta( $post_id, '_location_longitude', true ), 'longitude' ) ) {
                update_post_meta( $post_id, '_location_longitude', $longitude );
            }
            $meta_fields = array(
                '_location_address' => 'location_address',
                '_location_town' => 'location_city',
                '_location_state' => 'location_state',
                '_location_postcode' => 'location_postcode',
                '_location_country' => 'location_country',
            );
            foreach ( $meta_fields as $meta_key => $candidate_key ) {
                $incoming = sanitize_text_field( (string) ( $candidate[ $candidate_key ] ?? '' ) );
                $current = sanitize_text_field( (string) get_post_meta( $post_id, $meta_key, true ) );
                $clearly_invalid_country = '_location_country' === $meta_key
                    && $incoming
                    && strtoupper( $current ) === strtoupper( (string) ( $location['location_state'] ?? '' ) )
                    && strtoupper( $incoming ) !== strtoupper( $current );
                if ( $incoming && ( '' === trim( $current ) || $clearly_invalid_country ) ) {
                    update_post_meta( $post_id, $meta_key, $incoming );
                }
            }
        }

        return $location;
    }

    /**
     * Repair legacy Events Manager locations where a US state code was
     * accidentally stored as the country. The strict state/postcode checks
     * prevent this migration from changing legitimate non-US locations.
     */
    public static function repair_invalid_us_location_countries(): array {
        global $wpdb;

        $table = self::locations_table();
        $columns = self::columns( $table );
        $required = array( 'location_id', 'post_id', 'location_state', 'location_postcode', 'location_country' );
        if ( array_diff( $required, $columns ) ) {
            return array( 'location_countries_repaired' => 0 );
        }

        if ( in_array( 'location_region', $columns, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration reads current Events Manager location rows.
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT location_id, post_id, location_state, location_postcode, location_country, location_region FROM %i WHERE location_country <> %s', $table, '' ), ARRAY_A );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration reads current Events Manager location rows.
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT location_id, post_id, location_state, location_postcode, location_country FROM %i WHERE location_country <> %s', $table, '' ), ARRAY_A );
        }
        $repaired = 0;

        foreach ( (array) $rows as $row ) {
            $location_id = absint( $row['location_id'] ?? 0 );
            $post_id = absint( $row['post_id'] ?? 0 );
            if ( $location_id ) {
                wp_cache_delete( $location_id, 'em_locations' );
            }
            if ( $post_id ) {
                clean_post_cache( $post_id );
            }

            $state = strtoupper( trim( sanitize_text_field( (string) ( $row['location_state'] ?? '' ) ) ) );
            $country = strtoupper( trim( sanitize_text_field( (string) ( $row['location_country'] ?? '' ) ) ) );
            $postcode = trim( sanitize_text_field( (string) ( $row['location_postcode'] ?? '' ) ) );
            $region = strtoupper( trim( sanitize_text_field( (string) ( $row['location_region'] ?? '' ) ) ) );
            $valid_us_shape = preg_match( '/^[A-Z]{2}$/', $state )
                && preg_match( '/^\d{5}(?:-\d{4})?$/', $postcode )
                && in_array( $country, array( 'US', 'USA', 'UNITED STATES' ), true );
            if ( $valid_us_shape && $region === $state ) {
                $wpdb->update(
                    $table,
                    array( 'location_region' => '' ),
                    array( 'location_id' => $location_id ),
                    array( '%s' ),
                    array( '%d' )
                );
                if ( $post_id ) {
                    delete_post_meta( $post_id, '_location_region' );
                    clean_post_cache( $post_id );
                }
                wp_cache_delete( $location_id, 'em_locations' );
                $repaired++;
            }
            if ( ! preg_match( '/^[A-Z]{2}$/', $state )
                || $country !== $state
                || ! preg_match( '/^\d{5}(?:-\d{4})?$/', $postcode )
            ) {
                continue;
            }

            if ( ! $location_id ) {
                continue;
            }
            $updated = $wpdb->update(
                $table,
                array( 'location_country' => 'US' ),
                array( 'location_id' => $location_id ),
                array( '%s' ),
                array( '%d' )
            );
            if ( false === $updated ) {
                continue;
            }

            if ( $post_id ) {
                update_post_meta( $post_id, '_location_country', 'US' );
                clean_post_cache( $post_id );
            }
            wp_cache_delete( $location_id, 'em_locations' );
            $repaired++;
        }

        return array( 'location_countries_repaired' => $repaired );
    }

    private static function find_existing_event_post( array $candidate ): int {
        if ( ! empty( $candidate['em_post_id'] ) && self::event_post_type() === get_post_type( (int) $candidate['em_post_id'] ) ) {
            return (int) $candidate['em_post_id'];
        }
        $meta_query = array( 'relation' => 'OR' );
        if ( ! empty( $candidate['source_uid'] ) ) {
            $meta_query[] = array( 'key' => '_gi_source_uid', 'value' => $candidate['source_uid'] );
        }
        if ( ! empty( $candidate['fingerprint'] ) ) {
            $meta_query[] = array( 'key' => '_gi_fingerprint', 'value' => $candidate['fingerprint'] );
        }
        if ( count( $meta_query ) < 2 ) {
            return 0;
        }
        $ids = get_posts(
            array(
                'post_type'      => self::event_post_type(),
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'meta_query'     => $meta_query,
            )
        );
        if ( $ids ) {
            return (int) $ids[0];
        }
        return self::find_existing_event_by_fields( $candidate );
    }

    private static function find_existing_event_by_fields( array $candidate ): int {
        global $wpdb;
        $table = self::events_table();
        $columns = self::columns( $table );
        foreach ( array( 'post_id', 'event_name', 'event_start_date' ) as $required ) {
            if ( ! in_array( $required, $columns, true ) ) {
                return 0;
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Duplicate matching uses current Events Manager event rows.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE event_start_date = %s ORDER BY post_id ASC LIMIT 100',
                $table,
                sanitize_text_field( $candidate['start_date'] ?? '' )
            ),
            ARRAY_A
        );
        $candidate_title = GI_Utils::normalize_title( $candidate['title'] ?? '' );
        $candidate_time = sanitize_text_field( $candidate['start_time'] ?? '' );
        $location_ids = array_map( static fn( $location ) => (int) $location['location_id'], self::match_locations( $candidate ) );
        $candidate_has_location = GI_Utils::has_meaningful_value( $candidate['location_name'] ?? '' ) || GI_Utils::has_meaningful_value( $candidate['location_address'] ?? '' );
        foreach ( $rows ?: array() as $row ) {
            if ( ! $candidate_title || $candidate_title !== GI_Utils::normalize_title( $row['event_name'] ?? '' ) ) {
                continue;
            }
            $row_time = sanitize_text_field( $row['event_start_time'] ?? '' );
            if ( $candidate_time && $row_time && $candidate_time !== $row_time ) {
                continue;
            }
            $row_location_id = absint( $row['location_id'] ?? 0 );
            if ( $candidate_has_location && $location_ids && ! in_array( $row_location_id, $location_ids, true ) ) {
                continue;
            }
            if ( $candidate_has_location && ! $location_ids && $row_location_id ) {
                $row_location = self::get_location( $row_location_id );
                $name_matches = ! empty( $candidate['location_name'] ) && GI_Utils::normalize_text( $candidate['location_name'] ) === GI_Utils::normalize_text( $row_location['location_name'] ?? '' );
                $address_matches = ! empty( $candidate['location_address'] ) && GI_Utils::normalize_address( $candidate['location_address'] ) === GI_Utils::normalize_address( $row_location['location_address'] ?? '' );
                if ( ! $name_matches && ! $address_matches ) {
                    continue;
                }
            }
            $post_id = absint( $row['post_id'] ?? 0 );
            if ( $post_id && self::event_post_type() === get_post_type( $post_id ) ) {
                return $post_id;
            }
        }
        return 0;
    }

    private static function event_author_id( array $source_rules ): int {
        $configured = absint( $source_rules['event_author_id'] ?? 0 );
        if ( $configured && get_userdata( $configured ) ) {
            return $configured;
        }
        return get_current_user_id() ?: 1;
    }

    private static function save_event_row( int $post_id, array $candidate, int $location_id, string $post_status, int $event_author_id ): array|WP_Error {
        global $wpdb;
        $table = self::events_table();
        $existing_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE post_id = %d ORDER BY event_id ASC LIMIT 1', $table, $post_id ) );
        $now = current_time( 'mysql' );
        $start_datetime = trim( $candidate['start_date'] . ' ' . ( $candidate['start_time'] ?: '00:00:00' ) );
        $end_datetime = trim( ( $candidate['end_date'] ?: $candidate['start_date'] ) . ' ' . ( $candidate['end_time'] ?: '23:59:59' ) );
        $data = self::filter_columns(
            $table,
            array(
                'post_id'              => $post_id,
                'event_parent'         => 0,
                'event_slug'           => sanitize_title( $candidate['_final_title'] ?? $candidate['title'] ),
                'event_owner'          => $event_author_id,
                'event_name'           => sanitize_text_field( $candidate['_final_title'] ?? $candidate['title'] ),
                'event_start_time'     => $candidate['start_time'] ?: '00:00:00',
                'event_end_time'       => $candidate['end_time'] ?: '23:59:59',
                'event_start_date'     => $candidate['start_date'],
                'event_end_date'       => $candidate['end_date'] ?: $candidate['start_date'],
                'event_start'          => $start_datetime,
                'event_end'            => $end_datetime,
                'event_notes'          => (string) ( $candidate['_final_content'] ?? self::build_description( $candidate ) ),
                'event_rsvp'           => 0,
                'event_spaces'         => 0,
                'event_private'        => 0,
                'location_id'          => $location_id,
                'event_location_type'  => $location_id ? 'physical' : '',
                'recurrence'           => 0,
                'recurrence_interval'  => 1,
                'recurrence_freq'      => '',
                'event_status'         => 'publish' === $post_status ? 1 : 0,
                'event_active_status'  => 1,
                'event_all_day'        => ! empty( $candidate['all_day'] ) ? 1 : 0,
                'event_timezone'       => sanitize_text_field( $candidate['timezone'] ?: wp_timezone_string() ),
                'event_date_created'   => $now,
                'event_date_modified'  => $now,
                'event_type'           => 'single',
            )
        );
        if ( $existing_id ) {
            unset( $data['event_date_created'] );
            $ok = $wpdb->update( $table, $data, array( 'event_id' => $existing_id ) );
            $event_id = $existing_id;
        } else {
            $ok = $wpdb->insert( $table, $data );
            $event_id = (int) $wpdb->insert_id;
        }
        if ( false === $ok || ! $event_id ) {
            return new WP_Error( 'gi_event_row_failed', $wpdb->last_error ?: __( 'Events Manager event row was not saved.', 'great-imports' ) );
        }
        return array( 'event_id' => $event_id );
    }

    private static function timeranges_table(): string {
        global $wpdb;
        return defined( 'EM_TIMERANGES_TABLE' ) ? (string) EM_TIMERANGES_TABLE : $wpdb->prefix . 'em_timeranges';
    }

    private static function event_timeslots_table(): string {
        global $wpdb;
        return defined( 'EM_EVENT_TIMESLOTS_TABLE' ) ? (string) EM_EVENT_TIMESLOTS_TABLE : $wpdb->prefix . 'em_event_timeslots';
    }

    /**
     * Write festival time windows directly to Events Manager. Rich labels for
     * concurrent stages remain in post meta because EM's native rows contain
     * only date, time, timerange, and status.
     */
    private static function save_festival_timeslots( int $event_id, int $post_id, array $candidate, int $status ): array|WP_Error {
        global $wpdb;
        $slots = (array) ( $candidate['festival_slots'] ?? array() );
        $previous_ids = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $post_id, '_gi_festival_timeslot_ids', true ) ) ) );
        if ( 'festival' !== sanitize_key( $candidate['structure'] ?? '' ) || ! $slots ) {
            if ( $previous_ids ) {
                $timeslots_table = self::event_timeslots_table();
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $timeslots_table ) ) === $timeslots_table ) {
                    foreach ( $previous_ids as $previous_id ) {
                        $wpdb->update( $timeslots_table, array( 'timeslot_status' => 0 ), array( 'timeslot_id' => $previous_id, 'event_id' => $event_id ) );
                    }
                }
            }
            return array();
        }
        $timeranges_table = self::timeranges_table();
        $timeslots_table = self::event_timeslots_table();
        foreach ( array( $timeranges_table, $timeslots_table ) as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                return new WP_Error( 'gi_timeslots_unavailable', __( 'Events Manager time-slot tables are not available. Enable or update Event Timeslots before importing this festival.', 'great-imports' ) );
            }
        }

        $timezone_name = sanitize_text_field( $candidate['timezone'] ?? wp_timezone_string() );
        try {
            $timezone = new DateTimeZone( $timezone_name ?: wp_timezone_string() );
        } catch ( Exception $exception ) {
            $timezone = wp_timezone();
        }
        $utc = new DateTimeZone( 'UTC' );
        $group_id = 'event_' . $event_id;
        $native = array();
        foreach ( $slots as $slot ) {
            $date = sanitize_text_field( $slot['date'] ?? '' );
            $start_time = sanitize_text_field( $slot['start_time'] ?? '' );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}:\d{2}$/', $start_time ) ) {
                continue;
            }
            $end_date = sanitize_text_field( $slot['end_date'] ?? $date );
            $end_time = sanitize_text_field( $slot['end_time'] ?? $start_time );
            $start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' ' . $start_time, $timezone );
            $end = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $end_date . ' ' . $end_time, $timezone );
            if ( ! $start || ! $end ) {
                continue;
            }
            if ( $end < $start ) {
                $end = $end->modify( '+1 day' );
            }
            $start_utc = $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
            $end_utc = $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
            // EM identifies native slots by start time. Concurrent stages stay
            // separate in _gi_festival_slots while sharing one native window.
            if ( ! isset( $native[ $start_utc ] ) || $end_utc > $native[ $start_utc ]['end_utc'] ) {
                $native[ $start_utc ] = array(
                    'start_utc' => $start_utc,
                    'end_utc' => $end_utc,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                );
            }
        }
        if ( ! $native ) {
            return new WP_Error( 'gi_timeslots_empty', __( 'The festival needs at least one complete date and start time.', 'great-imports' ) );
        }

        $saved_ids = array();
        foreach ( $native as $native_slot ) {
            $timerange_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT timerange_id FROM %i WHERE timerange_group_id = %s AND timerange_start = %s AND timerange_end = %s ORDER BY timerange_id ASC LIMIT 1',
                    $timeranges_table,
                    $group_id,
                    $native_slot['start_time'],
                    $native_slot['end_time']
                )
            );
            if ( ! $timerange_id ) {
                $inserted = $wpdb->insert(
                    $timeranges_table,
                    array(
                        'timerange_group_id' => $group_id,
                        'timerange_start' => $native_slot['start_time'],
                        'timerange_end' => $native_slot['end_time'],
                        'timerange_all_day' => 0,
                        'timeslot_frequency' => null,
                        'timeslot_buffer' => null,
                        'timeslot_duration' => null,
                    )
                );
                if ( false === $inserted ) {
                    return new WP_Error( 'gi_timerange_failed', $wpdb->last_error ?: __( 'The festival time range could not be saved.', 'great-imports' ) );
                }
                $timerange_id = (int) $wpdb->insert_id;
            }
            $timeslot_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT timeslot_id FROM %i WHERE event_id = %d AND timeslot_start = %s ORDER BY timeslot_id ASC LIMIT 1',
                    $timeslots_table,
                    $event_id,
                    $native_slot['start_utc']
                )
            );
            $data = array(
                'event_id' => $event_id,
                'timerange_id' => $timerange_id,
                'timeslot_start' => $native_slot['start_utc'],
                'timeslot_end' => $native_slot['end_utc'],
                'timeslot_status' => $status,
            );
            if ( $timeslot_id ) {
                $saved = $wpdb->update( $timeslots_table, $data, array( 'timeslot_id' => $timeslot_id ) );
            } else {
                $saved = $wpdb->insert( $timeslots_table, $data );
                $timeslot_id = (int) $wpdb->insert_id;
            }
            if ( false === $saved || ! $timeslot_id ) {
                return new WP_Error( 'gi_timeslot_failed', $wpdb->last_error ?: __( 'The festival time slot could not be saved.', 'great-imports' ) );
            }
            $saved_ids[] = $timeslot_id;
        }

        $stale_ids = array_diff( $previous_ids, $saved_ids );
        foreach ( $stale_ids as $stale_id ) {
            $wpdb->update( $timeslots_table, array( 'timeslot_status' => 0 ), array( 'timeslot_id' => $stale_id, 'event_id' => $event_id ) );
        }
        wp_cache_delete( $event_id, 'em_events' );
        clean_post_cache( $post_id );
        return array_values( array_unique( $saved_ids ) );
    }

    private static function save_event_meta( int $post_id, array $candidate, int $event_id, int $location_id ): void {
        $existing_source_urls = (array) get_post_meta( $post_id, '_gi_source_urls', true );
        $source_urls = array_values( array_unique( array_filter( array_merge( $existing_source_urls, (array) ( $candidate['source_urls'] ?? array() ) ) ) ) );
        $meta = array(
            '_gi_imported'      => 1,
            '_gi_imported_at'   => current_time( 'mysql' ),
            '_gi_source_uid'    => sanitize_text_field( $candidate['source_uid'] ?? '' ),
            '_gi_fingerprint'   => sanitize_text_field( $candidate['fingerprint'] ?? GI_Utils::fingerprint( $candidate ) ),
            '_gi_source_urls'   => $source_urls,
            '_gi_event_url'     => GI_Utils::clean_url( $candidate['event_url'] ?? '' ),
            '_gi_ticket_url'    => GI_Utils::clean_url( $candidate['ticket_url'] ?? '' ),
            '_gi_source_image_url' => GI_Utils::clean_url( $candidate['image_url'] ?? '' ),
            '_gi_em_event_id'   => $event_id,
            '_gi_em_location_id'=> $location_id,
            '_gi_structure'     => sanitize_key( $candidate['structure'] ?? 'auto' ),
            '_gi_parent_location_name' => sanitize_text_field( $candidate['parent_location_name'] ?? '' ),
            '_gi_stage_name'    => sanitize_text_field( $candidate['stage_name'] ?? '' ),
            '_gi_festival_slots'=> (array) ( $candidate['festival_slots'] ?? array() ),
            '_gi_festival_annual'=> ! empty( $candidate['festival_annual'] ) ? 1 : 0,
            '_gi_festival_edition_year'=> absint( $candidate['festival_edition_year'] ?? 0 ),
            '_gi_festival_timeslot_ids' => array_values( array_filter( array_map( 'absint', (array) ( $candidate['_gi_festival_timeslot_ids'] ?? array() ) ) ) ),
            '_gi_public_location_name' => sanitize_text_field( GI_Utils::public_location_name( $candidate ) ),
            '_gi_tags'          => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $candidate['tags'] ?? array() ) ) ) ) ),
            '_gi_series_id'     => sanitize_text_field( $candidate['recurrence_series_id'] ?? '' ),
            '_gi_series_rule'   => (array) ( $candidate['recurrence_source_rule'] ?? array() ),
            '_gi_occurrence_index' => absint( $candidate['recurrence_occurrence_index'] ?? 0 ),
            '_gi_import_data' => array_intersect_key(
                $candidate,
                array_flip( array(
                    'title', 'description', 'start_date', 'start_time', 'end_date', 'end_time', 'all_day', 'timezone',
                    'event_url', 'ticket_url', 'price', 'currency', 'image_url', 'location_name', 'location_address',
                    'location_city', 'location_state', 'location_postcode', 'location_country', 'location_latitude',
                    'location_longitude', 'parent_location_name',
                    'stage_name', 'organizer', 'categories', 'tags', 'source_urls', 'source_uid', 'fingerprint', 'structure',
                    'recurrence_mode', 'recurrence_frequency', 'recurrence_interval', 'recurrence_until', 'recurrence_count',
                    'recurrence_weekdays', 'recurrence_rule', 'recurrence_series_id', 'recurrence_occurrence_index', 'festival_slots', 'festival_annual', 'festival_edition_year',
                ) )
            ),
            '_gi_import_snapshot' => array(
                'title'       => (string) ( $candidate['_final_title'] ?? $candidate['title'] ?? '' ),
                'content'     => (string) ( $candidate['_final_content'] ?? self::build_description( $candidate ) ),
                'start_date'  => sanitize_text_field( $candidate['start_date'] ?? '' ),
                'start_time'  => sanitize_text_field( $candidate['start_time'] ?? '' ),
                'end_date'    => sanitize_text_field( $candidate['end_date'] ?? '' ),
                'end_time'    => sanitize_text_field( $candidate['end_time'] ?? '' ),
                'location_id' => $location_id,
            ),
            '_event_id'         => $event_id,
            '_event_start_date' => sanitize_text_field( $candidate['start_date'] ?? '' ),
            '_event_start_time' => sanitize_text_field( $candidate['start_time'] ?? '' ),
            '_event_end_date'   => sanitize_text_field( $candidate['end_date'] ?? '' ),
            '_event_end_time'   => sanitize_text_field( $candidate['end_time'] ?? '' ),
            '_event_start_local'=> sanitize_text_field( trim( (string) ( $candidate['start_date'] ?? '' ) . ' ' . (string) ( $candidate['start_time'] ?: '00:00:00' ) ) ),
            '_event_end_local'  => sanitize_text_field( trim( (string) ( $candidate['end_date'] ?: $candidate['start_date'] ?? '' ) . ' ' . (string) ( $candidate['end_time'] ?: '23:59:59' ) ) ),
            '_event_all_day'    => ! empty( $candidate['all_day'] ) ? 1 : 0,
            '_event_timezone'   => sanitize_text_field( $candidate['timezone'] ?? wp_timezone_string() ),
            '_event_active_status' => 1,
            '_event_location_type' => $location_id ? 'physical' : '',
            '_location_id'      => $location_id,
        );
        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    private static function build_description( array $candidate, array $rules = array() ): string {
        $html = GI_Utils::sanitize_html( $candidate['description'] ?? '' );
        $sections = array();
        $ticket_url = GI_Utils::clean_url( $candidate['ticket_url'] ?? '' );
        $price = sanitize_text_field( $candidate['price'] ?? '' );
        $currency = sanitize_text_field( $candidate['currency'] ?? '' );
        if ( 'festival' === sanitize_key( $candidate['structure'] ?? '' ) && ! empty( $candidate['festival_slots'] ) ) {
            $schedule = '<section class="gi-festival-schedule"><h2>' . esc_html__( 'Festival schedule', 'great-imports' ) . '</h2>';
            $grouped = array();
            foreach ( (array) $candidate['festival_slots'] as $slot ) {
                $grouped[ sanitize_text_field( $slot['date'] ?? '' ) ][] = (array) $slot;
            }
            $timezone_name = sanitize_text_field( $candidate['timezone'] ?? wp_timezone_string() );
            try {
                $timezone = new DateTimeZone( $timezone_name ?: wp_timezone_string() );
            } catch ( Exception $exception ) {
                $timezone = wp_timezone();
            }
            foreach ( $grouped as $date => $slots ) {
                $day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
                $schedule .= '<h3>' . esc_html( $day ? wp_date( get_option( 'date_format' ), $day->getTimestamp(), $timezone ) : $date ) . '</h3><ul>';
                foreach ( $slots as $slot ) {
                    $start = DateTimeImmutable::createFromFormat( '!H:i:s', (string) ( $slot['start_time'] ?? '' ), $timezone );
                    $end = DateTimeImmutable::createFromFormat( '!H:i:s', (string) ( $slot['end_time'] ?? '' ), $timezone );
                    $time_label = $start ? wp_date( get_option( 'time_format' ), $start->getTimestamp(), $timezone ) : '';
                    if ( $end ) {
                        $time_label .= '–' . wp_date( get_option( 'time_format' ), $end->getTimestamp(), $timezone );
                    }
                    $details = array_values( array_filter( array(
                        sanitize_text_field( $slot['stage_name'] ?? '' ),
                        sanitize_text_field( $slot['location_name'] ?? '' ),
                    ) ) );
                    $schedule .= '<li><strong>' . esc_html( $time_label ) . '</strong> ' . esc_html( $slot['title'] ?? '' );
                    if ( $details ) {
                        $schedule .= ' <span>— ' . esc_html( implode( ' · ', $details ) ) . '</span>';
                    }
                    $slot_ticket = GI_Utils::clean_url( $slot['ticket_url'] ?? '' );
                    if ( $slot_ticket ) {
                        $schedule .= ' <a href="' . esc_url( $slot_ticket ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Tickets', 'great-imports' ) . '</a>';
                    }
                    $schedule .= '</li>';
                }
                $schedule .= '</ul>';
            }
            $sections[] = $schedule . '</section>';
        }
        if ( ! empty( $rules['include_ticket_details'] ) && ( $ticket_url || $price ) ) {
            $ticket = '<h2>' . esc_html__( 'Tickets', 'great-imports' ) . '</h2>';
            if ( $price ) {
                $ticket .= '<p>' . esc_html( trim( $price . ' ' . $currency ) ) . '</p>';
            }
            if ( $ticket_url ) {
                $ticket .= '<p><a href="' . esc_url( $ticket_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Purchase tickets', 'great-imports' ) . '</a></p>';
            }
            $sections[] = $ticket;
        }
        if ( ! empty( $rules['include_organizer_details'] ) && ! empty( $candidate['organizer'] ) ) {
            $sections[] = '<h2>' . esc_html__( 'Organizer', 'great-imports' ) . '</h2><p>' . esc_html( $candidate['organizer'] ) . '</p>';
        }
        return trim( $html . implode( '', $sections ) );
    }

    private static function assign_categories( int $post_id, array $candidate, array $rules ): void {
        $taxonomy = self::category_taxonomy();
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return;
        }
        $term_ids = array_values( array_filter( array_map( 'absint', (array) ( $rules['default_categories'] ?? array() ) ) ) );
        $names = array_values( array_unique( array_filter( array_merge( (array) ( $candidate['categories'] ?? array() ), (array) ( $rules['category_names'] ?? array() ) ) ) ) );
        $settings = GI_Storage::settings();
        if ( ! empty( $rules['create_categories'] ) && ! empty( $settings['allow_category_creation'] ) ) {
            foreach ( $names as $name ) {
                $name = sanitize_text_field( $name );
                if ( ! $name ) {
                    continue;
                }
                $term = term_exists( $name, $taxonomy );
                if ( ! $term ) {
                    $term = wp_insert_term( $name, $taxonomy );
                }
                if ( ! is_wp_error( $term ) ) {
                    $term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
                }
            }
        }
        if ( $term_ids ) {
            wp_set_object_terms( $post_id, array_values( array_unique( $term_ids ) ), $taxonomy, false );
        }
    }

    private static function assign_tags( int $post_id, array $candidate ): void {
        $tags = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $candidate['tags'] ?? array() ) ) ) ) );
        if ( ! $tags ) {
            return;
        }
        $taxonomy = self::tag_taxonomy();
        if ( taxonomy_exists( $taxonomy ) ) {
            wp_set_object_terms( $post_id, $tags, $taxonomy, false );
        } elseif ( taxonomy_exists( 'post_tag' ) ) {
            wp_set_object_terms( $post_id, $tags, 'post_tag', false );
        }
        update_post_meta( $post_id, '_gi_tags', $tags );
    }

    private static function sideload_image( int $post_id, string $image_url ): bool|WP_Error {
        $image_url = GI_Utils::clean_url( $image_url );
        if ( ! $image_url ) {
            return false;
        }
        if ( has_post_thumbnail( $post_id ) ) {
            return true;
        }
        $existing = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'meta_key'       => '_gi_source_image_url',
                'meta_value'     => $image_url,
            )
        );
        if ( $existing ) {
            set_post_thumbnail( $post_id, (int) $existing[0] );
            return true;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_sideload_image( $image_url, $post_id, get_the_title( $post_id ), 'id' );
        if ( is_wp_error( $attachment_id ) ) {
            $temporary_file = self::download_image_to_temp( $image_url );
            if ( is_wp_error( $temporary_file ) ) {
                $origin_image_url = self::eventbrite_origin_image_url( $image_url );
                if ( $origin_image_url ) {
                    $temporary_file = self::download_image_to_temp( $origin_image_url );
                }
            }
            if ( is_wp_error( $temporary_file ) ) {
                return $temporary_file;
            }
            $mime = function_exists( 'wp_get_image_mime' ) ? (string) wp_get_image_mime( $temporary_file ) : '';
            $extensions = array(
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/avif' => 'avif',
            );
            if ( empty( $extensions[ $mime ] ) ) {
                wp_delete_file( $temporary_file );
                return $attachment_id;
            }
            $file_array = array(
                'name' => sanitize_file_name( sanitize_title( get_the_title( $post_id ) ) . '.' . $extensions[ $mime ] ),
                'tmp_name' => $temporary_file,
            );
            $attachment_id = media_handle_sideload( $file_array, $post_id, get_the_title( $post_id ) );
            if ( is_wp_error( $attachment_id ) && is_file( $temporary_file ) ) {
                wp_delete_file( $temporary_file );
            }
        }
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }
        update_post_meta( (int) $attachment_id, '_gi_source_image_url', $image_url );
        set_post_thumbnail( $post_id, (int) $attachment_id );
        return true;
    }

    /**
     * Download an image with explicit image headers. Some event image CDNs
     * reject WordPress's default download request even though the image is
     * public in a browser.
     */
    private static function download_image_to_temp( string $image_url ): string|WP_Error {
        $temporary_file = wp_tempnam( wp_basename( (string) wp_parse_url( $image_url, PHP_URL_PATH ) ) ?: 'great-imports-image' );
        if ( ! $temporary_file ) {
            return new WP_Error( 'gi_image_temp_failed', __( 'A temporary file could not be created for the event image.', 'great-imports' ) );
        }
        $host = strtolower( (string) wp_parse_url( $image_url, PHP_URL_HOST ) );
        $headers = array(
            'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'User-Agent' => 'Mozilla/5.0 (compatible; Great Imports/' . GI_VERSION . '; +' . home_url( '/' ) . ')',
        );
        if ( str_contains( $host, 'evbuc.com' ) || str_contains( $host, 'eventbrite.' ) ) {
            $headers['Referer'] = 'https://www.eventbrite.com/';
        }
        $response = wp_safe_remote_get(
            $image_url,
            array(
                'timeout' => 30,
                'redirection' => 5,
                'stream' => true,
                'filename' => $temporary_file,
                'headers' => $headers,
            )
        );
        if ( is_wp_error( $response ) ) {
            wp_delete_file( $temporary_file );
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 || ! is_file( $temporary_file ) || 0 === (int) filesize( $temporary_file ) ) {
            wp_delete_file( $temporary_file );
            return new WP_Error(
                'gi_image_download_failed',
                sprintf(
                    /* translators: %d: HTTP response code. */
                    __( 'The event image server returned HTTP %d.', 'great-imports' ),
                    $status
                )
            );
        }
        return $temporary_file;
    }

    /**
     * Eventbrite proxy signatures can expire while the original public image
     * remains available. Recover that original URL from the proxy path.
     */
    private static function eventbrite_origin_image_url( string $image_url ): string {
        $host = strtolower( (string) wp_parse_url( $image_url, PHP_URL_HOST ) );
        if ( ! str_contains( $host, 'img.evbuc.com' ) ) {
            return '';
        }
        $encoded_origin = ltrim( (string) wp_parse_url( $image_url, PHP_URL_PATH ), '/' );
        $origin = GI_Utils::clean_url( rawurldecode( $encoded_origin ) );
        $origin_host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
        return str_contains( $origin_host, 'cdn.evbuc.com' ) ? $origin : '';
    }

    private static function columns( string $table ): array {
        if ( isset( self::$column_cache[ $table ] ) ) {
            return self::$column_cache[ $table ];
        }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
        self::$column_cache[ $table ] = array_values( array_filter( array_map( static fn( $row ) => $row['Field'] ?? '', $rows ?: array() ) ) );
        return self::$column_cache[ $table ];
    }

    private static function filter_columns( string $table, array $data ): array {
        $columns = array_flip( self::columns( $table ) );
        return array_intersect_key( $data, $columns );
    }
}
