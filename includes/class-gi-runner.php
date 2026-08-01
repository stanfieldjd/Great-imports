<?php

defined( 'ABSPATH' ) || exit;

final class GI_Runner {
    public static function run_source( int $source_id, string $trigger = 'manual' ): array|WP_Error {
        $source = GI_Storage::get_source( $source_id );
        if ( ! $source ) {
            return new WP_Error( 'gi_source_missing', __( 'The source no longer exists.', 'great-imports' ) );
        }
        $lock_key = 'gi_run_lock_' . $source_id;
        if ( ! add_option( $lock_key, time(), '', false ) ) {
            $locked_at = absint( get_option( $lock_key, 0 ) );
            if ( ! $locked_at || $locked_at > time() - 1800 ) {
                return new WP_Error( 'gi_source_running', __( 'This source is already running. Open History to review the active run.', 'great-imports' ) );
            }
            delete_option( $lock_key );
            if ( ! add_option( $lock_key, time(), '', false ) ) {
                return new WP_Error( 'gi_source_running', __( 'This source is already running.', 'great-imports' ) );
            }
        }
        $run_id = GI_Storage::create_run( $source_id, $trigger, $source['action'] );
        if ( is_wp_error( $run_id ) ) {
            delete_option( $lock_key );
            return $run_id;
        }
        GI_Storage::log_run( $run_id, 'info', __( 'Source run started.', 'great-imports' ), array( 'urls' => $source['urls'], 'action' => $source['action'] ) );
        try {
            $collector = new GI_Collector();
            if ( 'file' === ( $source['source_type'] ?? '' ) ) {
                if ( empty( $source['file_path'] ) || ! is_readable( $source['file_path'] ) ) {
                    throw new RuntimeException( __( 'The stored source file is missing or unreadable.', 'great-imports' ) );
                }
                $collected = $collector->collect_file( $source['file_path'], $source['file_name'] ?? basename( $source['file_path'] ) );
            } else {
                $collected = $collector->collect_urls( $source['urls'] );
            }
            $result = self::process_collection( $source, $run_id, $collected, $trigger );
            delete_option( $lock_key );
            return $result;
        } catch ( Throwable $e ) {
            $summary = self::empty_summary();
            $summary['failed'] = 1;
            $summary['errors'][] = $e->getMessage();
            GI_Storage::log_run( $run_id, 'error', $e->getMessage() );
            GI_Storage::finish_run( $run_id, $summary, 'failed' );
            if ( 'scheduled' === $trigger ) {
                GI_Scheduler::advance_source( $source_id );
            }
            delete_option( $lock_key );
            return new WP_Error( 'gi_run_failed', $e->getMessage(), array( 'run_id' => $run_id ) );
        }
    }

    public static function run_uploaded_file( int $source_id, string $path, string $filename ): array|WP_Error {
        $source = GI_Storage::get_source( $source_id );
        if ( ! $source ) {
            return new WP_Error( 'gi_source_missing', __( 'The source no longer exists.', 'great-imports' ) );
        }
        $run_id = GI_Storage::create_run( $source_id, 'manual_file', $source['action'] );
        if ( is_wp_error( $run_id ) ) {
            return $run_id;
        }
        GI_Storage::log_run( $run_id, 'info', __( 'File import run started.', 'great-imports' ), array( 'filename' => sanitize_file_name( $filename ), 'action' => $source['action'] ) );
        $collector = new GI_Collector();
        $collected = $collector->collect_file( $path, $filename );
        return self::process_collection( $source, $run_id, $collected, 'manual_file' );
    }

    public static function import_candidate( int $candidate_id, string $action = 'draft' ): array|WP_Error {
        $candidate = GI_Storage::get_candidate( $candidate_id );
        if ( ! $candidate ) {
            return new WP_Error( 'gi_candidate_missing', __( 'The candidate no longer exists.', 'great-imports' ) );
        }
        $source = GI_Storage::get_source( absint( $candidate['source_id'] ?? 0 ) );
        $rules = $source['rules'] ?? array();

        // Reapply the current source rules and normalize the candidate
        // immediately before the Events Manager write.
        $candidate = self::apply_source_rules( $candidate, $rules );
        $assessment = self::assess_candidate_content( $candidate );
        $candidate = $assessment['candidate'];
        $candidate = GI_Normalizer::finalize_candidate( $candidate );
        GI_Storage::update_candidate(
            $candidate_id,
            array(
                'status'       => sanitize_key( $candidate['status'] ?? 'held' ),
                'hold_reasons' => array_values( array_unique( array_filter( (array) ( $candidate['hold_reasons'] ?? array() ) ) ) ),
                'last_error'   => '',
            )
        );
        if ( 'allow' !== ( $assessment['result']['decision'] ?? 'allow' ) ) {
            return new WP_Error(
                'gi_explicit_content_review',
                $assessment['result']['reason'] ?: __( 'Review this event before importing it.', 'great-imports' )
            );
        }
        if ( 'ready' !== ( $candidate['status'] ?? 'held' ) ) {
            return new WP_Error(
                'gi_candidate_not_ready',
                __( 'This event still needs correction before it can be imported.', 'great-imports' )
            );
        }

        $result = GI_Events_Manager::import_candidate( $candidate, $action, $rules );
        if ( is_wp_error( $result ) ) {
            GI_Storage::update_candidate(
                $candidate_id,
                array(
                    'status' => 'failed',
                    'last_error' => $result->get_error_message(),
                )
            );
            return $result;
        }
        $status = ! empty( $result['updated'] ) ? 'updated' : 'imported';
        GI_Storage::update_candidate(
            $candidate_id,
            array(
                'status'         => $status,
                'em_post_id'     => (int) $result['post_id'],
                'em_event_id'    => (int) $result['event_id'],
                'em_location_id' => (int) $result['location_id'],
                'em_post_ids'    => array_values( array_map( 'absint', (array) ( $result['post_ids'] ?? array( $result['post_id'] ) ) ) ),
                'em_event_ids'   => array_values( array_map( 'absint', (array) ( $result['event_ids'] ?? array( $result['event_id'] ) ) ) ),
                'series_id'      => sanitize_text_field( $result['series_id'] ?? '' ),
                'occurrence_count' => absint( $result['occurrence_count'] ?? 1 ),
                'imported_at'    => current_time( 'mysql' ),
                'last_error'     => '',
            )
        );
        return $result + array( 'candidate_status' => $status );
    }

    public static function reapply_source_rules( int $source_id ): array|WP_Error {
        $source = GI_Storage::get_source( $source_id );
        if ( ! $source ) {
            return new WP_Error( 'gi_source_missing', __( 'The source no longer exists.', 'great-imports' ) );
        }
        $result = array( 'processed' => 0, 'ready' => 0, 'held' => 0, 'failed' => 0, 'ignored' => 0 );
        $candidates = GI_Storage::list_candidates( array( 'ready', 'held', 'failed' ), $source_id, -1 );
        foreach ( $candidates as $candidate ) {
            $candidate_id = absint( $candidate['id'] ?? 0 );
            if ( ! $candidate_id ) {
                continue;
            }
            $manual = get_post_meta( $candidate_id, '_gi_manual_overrides', true );
            $manual = is_array( $manual ) ? $manual : array();
            $updated = self::apply_source_rules( $candidate, $source['rules'] ?? array() );
            foreach ( $manual as $field => $value ) {
                $updated[ $field ] = $value;
            }
            $assessment = self::assess_candidate_content( $updated );
            $updated = $assessment['candidate'];
            $filter = self::passes_keyword_filters( $updated, $source['rules'] ?? array() );
            if ( ! $filter['passes'] ) {
                $updated['status'] = 'ignored';
                $updated['hold_reasons'] = array();
                $updated['last_error'] = sanitize_text_field( $filter['reason'] );
            } elseif ( 'allow' !== ( $assessment['result']['decision'] ?? 'allow' ) ) {
                $updated['status'] = 'held';
                $updated['last_error'] = sanitize_text_field( $assessment['result']['reason'] ?? '' );
            } else {
                $updated['last_error'] = '';
                $duplicate_policy = sanitize_key( $source['rules']['duplicate_policy'] ?? 'update' );
                $existing_match = GI_Events_Manager::find_matching_event_post( $updated );
                if ( $existing_match && 'skip' === $duplicate_policy ) {
                    $updated['status'] = 'ignored';
                    $updated['hold_reasons'] = array();
                    $updated['last_error'] = __( 'Skipped because a matching Events Manager event already exists.', 'great-imports' );
                } elseif ( $existing_match && 'review' === $duplicate_policy ) {
                    $updated['status'] = 'held';
                    $updated['hold_reasons'][] = __( 'A matching Events Manager event exists. Review before updating it.', 'great-imports' );
                } elseif ( 'ignored' === ( $updated['status'] ?? '' ) ) {
                    $updated['status'] = 'held';
                }
            }
            $updated = GI_Normalizer::finalize_candidate( $updated );
            $saved = GI_Storage::update_candidate( $candidate_id, $updated );
            if ( is_wp_error( $saved ) ) {
                ++$result['failed'];
                continue;
            }
            ++$result['processed'];
            $status = sanitize_key( $saved['status'] ?? 'held' );
            if ( isset( $result[ $status ] ) ) {
                ++$result[ $status ];
            } else {
                ++$result['held'];
            }
        }
        return $result;
    }

    private static function process_collection( array $source, int $run_id, array $collected, string $trigger = 'manual' ): array {
        $summary = self::empty_summary();
        $summary['pages'] = array_slice( array_values( array_filter( (array) ( $collected['pages'] ?? array() ), 'is_array' ) ), 0, 100 );
        foreach ( (array) ( $collected['errors'] ?? array() ) as $error ) {
            $summary['errors'][] = sanitize_text_field( $error );
            GI_Storage::log_run( $run_id, 'warning', (string) $error );
        }
        foreach ( (array) ( $collected['blocked'] ?? array() ) as $blocked ) {
            ++$summary['blocked'];
            GI_Storage::log_run( $run_id, 'warning', __( 'Source was blocked or unreadable.', 'great-imports' ), $blocked );
        }

        $raw_candidates = (array) ( $collected['candidates'] ?? array() );
        $summary['collected'] = count( $raw_candidates );
        $merged_candidates = self::aggregate_candidates( $raw_candidates );
        $lookahead = absint( $source['schedule']['lookahead'] ?? 90 );
        $lookback = absint( $source['schedule']['lookback'] ?? 0 );
        $today = new DateTimeImmutable( 'today', wp_timezone() );
        $first_day = $today->modify( '-' . max( 0, $lookback ) . ' days' )->setTime( 0, 0, 0 );
        $last_day = $today->modify( '+' . max( 1, $lookahead ) . ' days' )->setTime( 23, 59, 59 );

        foreach ( $merged_candidates as $candidate ) {
            $candidate = self::apply_source_rules( $candidate, $source['rules'] ?? array() );
            $filter_result = self::passes_keyword_filters( $candidate, $source['rules'] ?? array() );
            if ( ! $filter_result['passes'] ) {
                ++$summary['filtered'];
                GI_Storage::log_run( $run_id, 'info', $filter_result['reason'], array( 'title' => $candidate['title'] ?? '' ) );
                continue;
            }
            $assessment = self::assess_candidate_content( $candidate );
            $candidate = $assessment['candidate'];
            $content_decision = $assessment['result']['decision'] ?? 'allow';
            if ( 'block' === $content_decision ) {
                ++$summary['filtered'];
                ++$summary['explicit_content_blocked'];
                GI_Storage::log_run(
                    $run_id,
                    'warning',
                    $assessment['result']['reason'] ?? __( 'Blocked by the explicit-content filter.', 'great-imports' ),
                    array( 'title' => $candidate['title'] ?? '', 'matched_terms' => $assessment['result']['matched_terms'] ?? array() )
                );
                continue;
            }
            if ( 'review' === $content_decision ) {
                ++$summary['explicit_content_review'];
            }
            $start = GI_Utils::parse_datetime( $candidate['start_date'] ?? '' );
            if ( $start && ( $start < $first_day || $start > $last_day ) ) {
                ++$summary['skipped_outside_window'];
                continue;
            }
            $duplicate_policy = sanitize_key( $source['rules']['duplicate_policy'] ?? 'update' );
            $existing_match = GI_Events_Manager::find_matching_event_post( $candidate );
            $skip_existing = $existing_match && 'skip' === $duplicate_policy;
            if ( $skip_existing ) {
                $candidate['status'] = 'ignored';
                $candidate['hold_reasons'] = array();
                $candidate['last_error'] = __( 'Skipped because a matching Events Manager event already exists.', 'great-imports' );
                ++$summary['skipped_existing'];
            } elseif ( $existing_match && 'review' === $duplicate_policy ) {
                $candidate['status'] = 'held';
                $candidate['hold_reasons'][] = __( 'A matching Events Manager event exists. Review before updating it.', 'great-imports' );
            }
            $stored = GI_Storage::create_or_merge_candidate( (int) $source['id'], $candidate, $run_id );
            if ( is_wp_error( $stored ) ) {
                ++$summary['failed'];
                $summary['errors'][] = $stored->get_error_message();
                GI_Storage::log_run( $run_id, 'error', $stored->get_error_message() );
                continue;
            }
            if ( $stored['created'] ) {
                ++$summary['created'];
            } else {
                ++$summary['merged'];
            }
            $summary['duplicates_consolidated'] += absint( $stored['consolidated'] ?? 0 );
            if ( $skip_existing ) {
                GI_Storage::update_candidate( (int) $stored['id'], array( 'status' => 'ignored', 'hold_reasons' => array(), 'last_error' => __( 'Skipped because a matching Events Manager event already exists.', 'great-imports' ) ) );
                $stored['candidate'] = GI_Storage::get_candidate( (int) $stored['id'] );
            }
            $stored_candidate = $stored['candidate'];
            if ( 'ready' === ( $stored_candidate['status'] ?? '' ) ) {
                ++$summary['ready'];
            } elseif ( 'ignored' === ( $stored_candidate['status'] ?? '' ) ) {
                // Intentionally skipped by source policy.
            } else {
                ++$summary['held'];
                GI_Storage::log_run(
                    $run_id,
                    'info',
                    __( 'Candidate retained for review.', 'great-imports' ),
                    array( 'candidate_id' => $stored['id'], 'status' => $stored_candidate['status'] ?? 'held', 'hold_reasons' => $stored_candidate['hold_reasons'] ?? array() )
                );
            }

            if ( in_array( $source['action'], array( 'draft', 'publish' ), true ) && 'ready' === ( $stored_candidate['status'] ?? '' ) ) {
                $import = self::import_candidate( (int) $stored['id'], $source['action'] );
                if ( is_wp_error( $import ) ) {
                    ++$summary['failed'];
                    $summary['errors'][] = $import->get_error_message();
                    GI_Storage::log_run( $run_id, 'error', $import->get_error_message(), array( 'candidate_id' => $stored['id'] ) );
                } elseif ( ! empty( $import['updated'] ) ) {
                    ++$summary['updated'];
                } else {
                    ++$summary['imported'];
                }
            }
        }

        $status = $summary['errors'] && ! ( $summary['created'] + $summary['merged'] + $summary['imported'] + $summary['updated'] ) ? 'failed' : 'complete';
        GI_Storage::finish_run( $run_id, $summary, $status );
        update_post_meta( (int) $source['id'], '_gi_last_run_id', $run_id );
        update_post_meta( (int) $source['id'], '_gi_last_run_at', current_time( 'mysql' ) );
        update_post_meta( (int) $source['id'], '_gi_last_run_summary', $summary );
        if ( 'scheduled' === $trigger ) {
            GI_Scheduler::advance_source( (int) $source['id'] );
        }
        $source_removed = false;
        if ( in_array( $source['action'], array( 'draft', 'publish' ), true ) ) {
            $source_removed = GI_Storage::maybe_remove_completed_run_once_from_queue( (int) $source['id'], $run_id );
            if ( $source_removed ) {
                $summary['temporary_source_removed_from_queue'] = 1;
            }
        }
        return array( 'run_id' => $run_id, 'summary' => $summary, 'status' => $status, 'source_removed' => $source_removed );
    }

    private static function aggregate_candidates( array $raw_candidates ): array {
        $candidates = array_values( array_filter( $raw_candidates, 'is_array' ) );
        $count = count( $candidates );
        if ( $count < 2 ) {
            return $candidates ? array( GI_Normalizer::merge_candidates( $candidates ) ) : array();
        }

        $parent = range( 0, $count - 1 );
        $find = static function ( int $index ) use ( &$parent, &$find ): int {
            if ( $parent[ $index ] !== $index ) {
                $parent[ $index ] = $find( $parent[ $index ] );
            }
            return $parent[ $index ];
        };
        $union = static function ( int $left, int $right ) use ( &$parent, &$find ): void {
            $left_root = $find( $left );
            $right_root = $find( $right );
            if ( $left_root !== $right_root ) {
                $parent[ $right_root ] = $left_root;
            }
        };

        $strong_owners = array();
        $weak_owners = array();
        $identity_sets = array();
        foreach ( $candidates as $index => $candidate ) {
            $sets = GI_Utils::candidate_identity_key_sets( $candidate );
            $identity_sets[ $index ] = $sets;
            foreach ( $sets['strong'] as $key ) {
                if ( isset( $strong_owners[ $key ] ) ) {
                    $union( $index, $strong_owners[ $key ] );
                } else {
                    $strong_owners[ $key ] = $index;
                }
            }
            foreach ( $sets['weak'] as $key ) {
                if ( isset( $weak_owners[ $key ] ) ) {
                    $owner = $weak_owners[ $key ];
                    if ( GI_Utils::candidates_have_compatible_occurrence( $candidate, $candidates[ $owner ] ) ) {
                        $union( $index, $owner );
                    }
                    $owner_has_strong = ! empty( $identity_sets[ $owner ]['strong'] );
                    if ( ! $owner_has_strong && $sets['strong'] ) {
                        $weak_owners[ $key ] = $index;
                    }
                } else {
                    $weak_owners[ $key ] = $index;
                }
            }
        }

        $groups = array();
        foreach ( $candidates as $index => $candidate ) {
            $groups[ $find( $index ) ][] = $candidate;
        }
        $merged = array();
        foreach ( $groups as $group ) {
            $merged[] = GI_Normalizer::merge_candidates( $group );
        }
        return $merged;
    }

    private static function apply_source_rules( array $candidate, array $rules ): array {
        $location_fields = array( 'location_name', 'location_address', 'location_city', 'location_state', 'location_postcode', 'location_country', 'parent_location_name', 'stage_name' );
        $before_location = array_intersect_key( $candidate, array_flip( $location_fields ) );
        $candidate = GI_Utils::apply_location_mappings( $candidate, $rules );
        $location_rule_applied = $before_location !== array_intersect_key( $candidate, array_flip( $location_fields ) );
        if ( ! empty( $rules['location_name_override'] ) ) {
            $location_rule_applied = true;
            $candidate['location_name'] = sanitize_text_field( $rules['location_name_override'] );
        }
        if ( ! empty( $rules['force_location_enabled'] ) && ! empty( $rules['forced_em_location_id'] ) ) {
            $location_rule_applied = true;
            $forced_id = absint( $rules['forced_em_location_id'] );
            foreach ( GI_Events_Manager::list_locations() as $location ) {
                if ( $forced_id === (int) $location['location_id'] ) {
                    $candidate['location_name'] = $location['location_name'];
                    $candidate['location_address'] = $location['location_address'];
                    $candidate['location_city'] = $location['location_city'];
                    $candidate['location_state'] = $location['location_state'];
                    $candidate['location_postcode'] = $location['location_postcode'];
                    $candidate['location_country'] = $location['location_country'];
                    $candidate['forced_em_location_id'] = $forced_id;
                    break;
                }
            }
        }
        if ( $location_rule_applied ) {
            foreach ( $location_fields as $field ) {
                unset( $candidate['conflicts'][ $field ] );
            }
            $candidate['hold_reasons'] = array_values( array_filter( (array) ( $candidate['hold_reasons'] ?? array() ), static function ( $reason ): bool {
                $reason = strtolower( (string) $reason );
                return ! str_contains( $reason, 'location' ) && ! str_contains( $reason, 'venue' ) && ! str_contains( $reason, 'address' ) && ! str_contains( $reason, 'stage' ) && ! str_contains( $reason, 'room' );
            } ) );
        }
        $rule_structure = sanitize_key( $rules['structure'] ?? 'auto' );
        if ( 'auto' !== $rule_structure || empty( $candidate['structure'] ) ) {
            $candidate['structure'] = $rule_structure;
        }
        if ( ! empty( $candidate['festival_slots'] ) ) {
            $candidate['structure'] = 'festival';
        }
        if ( in_array( $candidate['structure'], array( 'festival', 'conference', 'multi_session', 'multi_location' ), true ) && ! empty( $candidate['stage_name'] ) ) {
            $parent = sanitize_text_field( $candidate['parent_location_name'] ?? $candidate['location_name'] ?? '' );
            $stage = sanitize_text_field( $candidate['stage_name'] );
            $candidate['parent_location_name'] = $parent;
            $candidate['location_name'] = $parent ? sprintf( '%s at %s', $stage, $parent ) : $stage;
        }
        if ( empty( $candidate['location_country'] ) && ( ! empty( $candidate['location_name'] ) || ! empty( $candidate['location_address'] ) ) ) {
            $candidate['location_country'] = sanitize_text_field( $rules['default_country'] ?? 'US' );
        }
        if ( 'ignore' === ( $rules['image_policy'] ?? 'import' ) ) {
            $candidate['image_url'] = '';
        }
        $candidate['hold_reasons'] = array_values(
            array_filter(
                (array) ( $candidate['hold_reasons'] ?? array() ),
                static fn( $reason ) => ! ( ! empty( $candidate['location_name'] ) && str_contains( strtolower( (string) $reason ), 'location' ) )
            )
        );
        if ( ! empty( $rules['require_description'] ) && ! GI_Utils::has_meaningful_value( $candidate['description'] ?? '' ) ) {
            $candidate['hold_reasons'][] = __( 'Description is required by this source.', 'great-imports' );
        }
        if ( ! empty( $rules['require_image'] ) && ! GI_Utils::has_meaningful_value( $candidate['image_url'] ?? '' ) ) {
            $candidate['hold_reasons'][] = __( 'Image is required by this source.', 'great-imports' );
        }
        if ( ! empty( $rules['require_ticket_url'] ) && ! GI_Utils::has_meaningful_value( $candidate['ticket_url'] ?? '' ) ) {
            $candidate['hold_reasons'][] = __( 'Ticket URL is required by this source.', 'great-imports' );
        }
        $candidate = GI_Utils::normalize_location_fields( $candidate );
        $location_matches = GI_Events_Manager::matching_locations( $candidate );
        if ( 1 === count( $location_matches ) && empty( $candidate['em_location_id'] ) && empty( $rules['forced_em_location_id'] ) ) {
            $candidate['em_location_id'] = absint( $location_matches[0]['location_id'] ?? 0 );
            $candidate['location_match_status'] = 'exact_existing';
        } elseif ( count( $location_matches ) > 1 && empty( $candidate['em_location_id'] ) && empty( $rules['forced_em_location_id'] ) ) {
            $candidate['hold_reasons'][] = __( 'More than one existing Events Manager location matches. Choose the exact location.', 'great-imports' );
        }
        if ( 'existing_only' === ( $rules['location_policy'] ?? 'auto_create' ) && ! $location_matches && empty( $rules['forced_em_location_id'] ) ) {
            $candidate['hold_reasons'][] = __( 'No existing Events Manager location matches this event.', 'great-imports' );
        }
        return GI_Normalizer::finalize_candidate( $candidate );
    }

    private static function passes_keyword_filters( array $candidate, array $rules ): array {
        $haystack = strtolower( wp_strip_all_tags( implode( ' ', array_filter( array(
            $candidate['title'] ?? '',
            $candidate['description'] ?? '',
            $candidate['location_name'] ?? '',
            implode( ' ', (array) ( $candidate['categories'] ?? array() ) ),
        ) ) ) ) );
        $includes = array_values( array_filter( array_map( 'trim', (array) ( $rules['include_keywords'] ?? array() ) ) ) );
        if ( $includes ) {
            $matched = false;
            foreach ( $includes as $keyword ) {
                if ( '' !== $keyword && str_contains( $haystack, strtolower( $keyword ) ) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                return array( 'passes' => false, 'reason' => __( 'Filtered out because it did not match the source include keywords.', 'great-imports' ) );
            }
        }
        foreach ( array_values( array_filter( array_map( 'trim', (array) ( $rules['exclude_keywords'] ?? array() ) ) ) ) as $keyword ) {
            if ( '' !== $keyword && str_contains( $haystack, strtolower( $keyword ) ) ) {
                /* translators: %s: source exclusion keyword that matched the event. */
                return array( 'passes' => false, 'reason' => sprintf( __( 'Filtered out by excluded keyword: %s', 'great-imports' ), $keyword ) );
            }
        }
        return array( 'passes' => true, 'reason' => '' );
    }

    public static function assess_candidate_content( array $candidate ): array {
        $candidate['hold_reasons'] = array_values(
            array_filter(
                (array) ( $candidate['hold_reasons'] ?? array() ),
                static fn( $reason ): bool => ! str_starts_with( (string) $reason, 'Explicit-content review:' )
            )
        );
        $result = GI_Content_Filter::classify( $candidate );
        $candidate['explicit_content'] = $result;
        if ( in_array( $result['decision'] ?? 'allow', array( 'review', 'block' ), true ) && empty( $candidate['explicit_content_approved'] ) ) {
            $candidate['hold_reasons'][] = 'Explicit-content review: ' . sanitize_text_field( $result['reason'] ?? __( 'Review this event before importing it.', 'great-imports' ) );
            $candidate['status'] = 'held';
        }
        return array( 'candidate' => $candidate, 'result' => $result );
    }

    private static function empty_summary(): array {
        return array(
            'collected' => 0,
            'created' => 0,
            'merged' => 0,
            'ready' => 0,
            'held' => 0,
            'imported' => 0,
            'updated' => 0,
            'failed' => 0,
            'blocked' => 0,
            'skipped_outside_window' => 0,
            'filtered' => 0,
            'explicit_content_blocked' => 0,
            'explicit_content_review' => 0,
            'skipped_existing' => 0,
            'duplicates_consolidated' => 0,
            'errors' => array(),
            'pages' => array(),
        );
    }
}
