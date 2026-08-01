<?php

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Great Imports uses private post types and targeted metadata queries for importer state.

final class GI_Storage {
    public const SOURCE_CPT    = 'gi_source';
    public const CANDIDATE_CPT = 'gi_candidate';
    public const RUN_CPT       = 'gi_run';
    public const SETTINGS_KEY  = 'gi_settings';
    public const RUN_RETENTION_DAYS = 30;

    public static function register_post_types(): void {
        $common = array(
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'rewrite'             => false,
            'query_var'           => false,
            'supports'            => array( 'title' ),
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        );
        register_post_type( self::SOURCE_CPT, array_merge( $common, array( 'label' => 'Great Imports Sources' ) ) );
        register_post_type( self::CANDIDATE_CPT, array_merge( $common, array( 'label' => 'Great Imports Candidates' ) ) );
        register_post_type( self::RUN_CPT, array_merge( $common, array( 'label' => 'Great Imports Runs' ) ) );
    }

    public static function install_defaults(): void {
        if ( false === get_option( self::SETTINGS_KEY, false ) ) {
            add_option( self::SETTINGS_KEY, self::default_settings(), '', false );
        }
    }

    public static function default_settings(): array {
        return array(
            'request_timeout'        => 25,
            'max_events_per_run'     => 100,
            'max_total_events_per_run'=> 1000,
            'max_discovered_pages'   => 12,
            'allow_category_creation' => 1,
            'default_action'          => 'review',
            'default_lookahead'       => 90,
            'default_lookback'        => 0,
            'default_duplicate_policy'=> 'update',
            'default_image_policy'    => 'import',
            'default_location_policy' => 'auto_create',
            'default_event_author_id' => 0,
            'default_country'        => 'US',
            'default_include_keywords'=> array(),
            'default_exclude_keywords'=> array(),
            'explicit_content_filter_enabled' => 1,
            'explicit_content_sensitivity' => 'standard',
            'explicit_content_custom_terms' => array(),
            'explicit_content_trusted_domains' => array(),
            'protect_local_edits'     => 0,
            'include_ticket_details'  => 1,
            'include_organizer_details'=> 1,
            'cleanup_on_uninstall'    => 1,
            'auto_remove_run_once'    => 1,
            'eventbrite_token'        => '',
            'ticketmaster_key'        => '',
        );
    }

    public static function settings(): array {
        $defaults = self::default_settings();
        $raw = (array) get_option( self::SETTINGS_KEY, array() );
        if ( ! array_key_exists( 'auto_remove_run_once', $raw ) && array_key_exists( 'auto_delete_run_once', $raw ) ) {
            $raw['auto_remove_run_once'] = empty( $raw['auto_delete_run_once'] ) ? 0 : 1;
        }
        $stored = array_intersect_key( $raw, $defaults );
        return wp_parse_args( $stored, $defaults );
    }

    public static function save_settings( array $input ): array {
        $current = self::settings();
        $action = sanitize_key( $input['run_action'] ?? $input['default_action'] ?? $current['default_action'] );
        $duplicate = sanitize_key( $input['duplicate_policy'] ?? $input['default_duplicate_policy'] ?? $current['default_duplicate_policy'] );
        $image = sanitize_key( $input['image_policy'] ?? $input['default_image_policy'] ?? $current['default_image_policy'] );
        $location = sanitize_key( $input['location_policy'] ?? $input['default_location_policy'] ?? $current['default_location_policy'] );
        $clean = array(
            'request_timeout'         => min( 60, max( 5, absint( $input['request_timeout'] ?? $current['request_timeout'] ) ) ),
            'max_events_per_run'      => min( 500, max( 10, absint( $input['max_events_per_run'] ?? $current['max_events_per_run'] ) ) ),
            'max_total_events_per_run'=> min( 5000, max( 50, absint( $input['max_total_events_per_run'] ?? $current['max_total_events_per_run'] ) ) ),
            'max_discovered_pages'    => min( 50, max( 1, absint( $input['max_discovered_pages'] ?? $current['max_discovered_pages'] ) ) ),
            'allow_category_creation' => empty( $input['allow_category_creation'] ) ? 0 : 1,
            'default_action'          => in_array( $action, array( 'review', 'draft', 'publish' ), true ) ? $action : 'review',
            'default_lookahead'       => min( 730, max( 1, absint( $input['lookahead'] ?? $input['default_lookahead'] ?? $current['default_lookahead'] ) ) ),
            'default_lookback'        => min( 365, max( 0, absint( $input['lookback'] ?? $input['default_lookback'] ?? $current['default_lookback'] ) ) ),
            'default_duplicate_policy'=> in_array( $duplicate, array( 'update', 'review', 'skip' ), true ) ? $duplicate : 'update',
            'default_image_policy'    => in_array( $image, array( 'import', 'keep_url', 'ignore' ), true ) ? $image : 'import',
            'default_location_policy' => in_array( $location, array( 'auto_create', 'existing_only' ), true ) ? $location : 'auto_create',
            'default_event_author_id' => self::sanitize_user_choice( $input['event_author_id'] ?? $input['default_event_author_id'] ?? $current['default_event_author_id'] ?? 0 ),
            'default_country'        => strtoupper( substr( sanitize_text_field( $input['default_country'] ?? $current['default_country'] ?? 'US' ), 0, 2 ) ),
            'default_include_keywords'=> self::sanitize_keyword_list( $input['default_include_keywords'] ?? $current['default_include_keywords'] ?? array() ),
            'default_exclude_keywords'=> self::sanitize_keyword_list( $input['default_exclude_keywords'] ?? $current['default_exclude_keywords'] ?? array() ),
            'explicit_content_filter_enabled' => empty( $input['explicit_content_filter_enabled'] ) ? 0 : 1,
            'explicit_content_sensitivity' => 'strict' === sanitize_key( $input['explicit_content_sensitivity'] ?? $current['explicit_content_sensitivity'] ?? 'standard' ) ? 'strict' : 'standard',
            'explicit_content_custom_terms' => self::sanitize_keyword_list( $input['explicit_content_custom_terms'] ?? $current['explicit_content_custom_terms'] ?? array() ),
            'explicit_content_trusted_domains' => self::sanitize_keyword_list( $input['explicit_content_trusted_domains'] ?? $current['explicit_content_trusted_domains'] ?? array() ),
            'protect_local_edits'     => 0,
            'include_ticket_details'  => empty( $input['include_ticket_details'] ) ? 0 : 1,
            'include_organizer_details'=> empty( $input['include_organizer_details'] ) ? 0 : 1,
            'cleanup_on_uninstall'    => empty( $input['cleanup_on_uninstall'] ) ? 0 : 1,
            'auto_remove_run_once'    => empty( $input['auto_remove_run_once'] ) ? 0 : 1,
            'eventbrite_token'        => ! empty( $input['clear_eventbrite_token'] ) ? '' : ( '' !== trim( (string) ( $input['eventbrite_token'] ?? '' ) ) ? sanitize_text_field( $input['eventbrite_token'] ) : $current['eventbrite_token'] ),
            'ticketmaster_key'        => ! empty( $input['clear_ticketmaster_key'] ) ? '' : ( '' !== trim( (string) ( $input['ticketmaster_key'] ?? '' ) ) ? sanitize_text_field( $input['ticketmaster_key'] ) : $current['ticketmaster_key'] ),
        );
        update_option( self::SETTINGS_KEY, $clean, false );
        return $clean;
    }

    public static function source_identity_key( array $source ): string {
        $mode = empty( $source['is_saved'] ) ? 'once:' : 'recurring:';
        if ( 'file' === ( $source['source_type'] ?? '' ) ) {
            $path = wp_normalize_path( (string) ( $source['file_path'] ?? '' ) );
            return $path ? $mode . 'file:' . strtolower( $path ) : '';
        }
        $url = GI_Utils::clean_url( (string) ( $source['urls'][0] ?? '' ) );
        if ( ! $url ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return $mode . 'url:' . strtolower( untrailingslashit( $url ) );
        }
        $host = strtolower( preg_replace( '/^www\./i', '', (string) $parts['host'] ) );
        $path = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
        $path = '/' === $path ? '/' : untrailingslashit( $path );
        $query = ! empty( $parts['query'] ) ? '?' . (string) $parts['query'] : '';
        return $mode . 'url:' . $host . strtolower( $path ) . $query;
    }

    public static function find_source_by_identity( array $source, bool $active_only = true ): int {
        $key = self::source_identity_key( self::sanitize_source( $source ) );
        if ( ! $key ) {
            return 0;
        }
        foreach ( self::list_sources( array( 'include_inactive' => ! $active_only ) ) as $existing ) {
            if ( $active_only && empty( $existing['workspace_active'] ) ) {
                continue;
            }
            if ( self::source_identity_key( $existing ) === $key ) {
                return absint( $existing['id'] ?? 0 );
            }
        }
        return 0;
    }

    public static function repair_duplicate_unrun_sources(): array {
        $groups = array();
        foreach ( self::list_sources( array( 'include_inactive' => true ) ) as $source ) {
            if ( empty( $source['workspace_active'] ) ) {
                continue;
            }
            $key = self::source_identity_key( $source );
            if ( $key ) {
                $groups[ $key ][] = $source;
            }
        }
        $archived = 0;
        $groups_repaired = 0;
        foreach ( $groups as $items ) {
            if ( count( $items ) < 2 ) {
                continue;
            }
            usort( $items, static function ( array $a, array $b ): int {
                $score = static function ( array $source ): int {
                    $id = absint( $source['id'] ?? 0 );
                    $has_run = (bool) get_post_meta( $id, '_gi_last_run_at', true );
                    $has_candidates = (bool) self::list_candidates( array(), $id, 1 );
                    return ( ! empty( $source['is_saved'] ) ? 1000000 : 0 ) + ( $has_run ? 100000 : 0 ) + ( $has_candidates ? 10000 : 0 ) + $id;
                };
                return $score( $b ) <=> $score( $a );
            } );
            $keeper = array_shift( $items );
            $changed = false;
            foreach ( $items as $duplicate ) {
                $duplicate_id = absint( $duplicate['id'] ?? 0 );
                if ( ! $duplicate_id ) {
                    continue;
                }
                $has_run = (bool) get_post_meta( $duplicate_id, '_gi_last_run_at', true );
                $has_candidates = (bool) self::list_candidates( array(), $duplicate_id, 1 );
                if ( $has_run || $has_candidates ) {
                    continue;
                }
                self::update_source( $duplicate_id, array(
                    'workspace_active' => 0,
                    'archived_at'      => current_time( 'mysql' ),
                ) );
                ++$archived;
                $changed = true;
            }
            if ( $changed ) {
                ++$groups_repaired;
            }
        }
        return array( 'groups_repaired' => $groups_repaired, 'sources_archived' => $archived );
    }

    public static function create_source( array $source ): int|WP_Error {
        $source = self::sanitize_source( $source );
        $existing_id = self::find_source_by_identity( $source, true );
        if ( $existing_id ) {
            return $existing_id;
        }
        $title  = $source['name'] ?: ( $source['file_name'] ?: self::source_title_from_urls( $source['urls'] ) );
        $source['name'] = $title ?: __( 'Untitled source', 'great-imports' );
        $id = wp_insert_post(
            array(
                'post_type'   => self::SOURCE_CPT,
                'post_status' => 'publish',
                'post_title'  => $title ?: __( 'Untitled source', 'great-imports' ),
            ),
            true
        );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        self::save_source_data( (int) $id, $source );
        return (int) $id;
    }

    public static function update_source( int $source_id, array $changes ): bool|WP_Error {
        $existing = self::get_source( $source_id );
        if ( ! $existing ) {
            return new WP_Error( 'gi_source_missing', __( 'The saved source no longer exists.', 'great-imports' ) );
        }
        $merged = array_replace_recursive( $existing, $changes );
        if ( isset( $changes['urls'] ) ) { $merged['urls'] = $changes['urls']; }
        if ( isset( $changes['rules']['location_mappings'] ) ) { $merged['rules']['location_mappings'] = $changes['rules']['location_mappings']; }
        if ( isset( $changes['rules']['default_categories'] ) ) { $merged['rules']['default_categories'] = $changes['rules']['default_categories']; }
        if ( isset( $changes['rules']['category_names'] ) ) { $merged['rules']['category_names'] = $changes['rules']['category_names']; }
        if ( isset( $changes['rules']['include_keywords'] ) ) { $merged['rules']['include_keywords'] = $changes['rules']['include_keywords']; }
        if ( isset( $changes['rules']['exclude_keywords'] ) ) { $merged['rules']['exclude_keywords'] = $changes['rules']['exclude_keywords']; }
        $source = self::sanitize_source( $merged );
        $title = $source['name'] ?: ( $source['file_name'] ?: self::source_title_from_urls( $source['urls'] ) );
        $source['name'] = $title ?: __( 'Untitled source', 'great-imports' );
        $result = wp_update_post(
            array(
                'ID'         => $source_id,
                'post_title' => $source['name'],
            ),
            true
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        self::save_source_data( $source_id, $source );
        return true;
    }

    private static function save_source_data( int $source_id, array $source ): void {
        update_post_meta( $source_id, '_gi_source_data', $source );
        update_post_meta( $source_id, '_gi_schedule_enabled', ! empty( $source['schedule']['enabled'] ) ? 1 : 0 );
        update_post_meta( $source_id, '_gi_next_run_gmt', $source['schedule']['next_run_gmt'] ?? '' );
        update_post_meta( $source_id, '_gi_primary_url', $source['urls'][0] ?? '' );
    }

    public static function sanitize_source( array $source ): array {
        $settings = self::settings();
        $urls = array();
        foreach ( (array) ( $source['urls'] ?? array() ) as $url ) {
            $clean = GI_Utils::clean_url( (string) $url );
            if ( $clean ) {
                $urls[ $clean ] = $clean;
            }
        }
        $action = sanitize_key( $source['action'] ?? 'review' );
        if ( ! in_array( $action, array( 'review', 'draft', 'publish' ), true ) ) {
            $action = 'review';
        }
        $cadence = sanitize_key( $source['schedule']['cadence'] ?? 'daily' );
        if ( ! in_array( $cadence, array( 'hourly', 'daily', 'weekly', 'monthly' ), true ) ) {
            $cadence = 'daily';
        }
        $time = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) ( $source['schedule']['time'] ?? '' ) )
            ? (string) $source['schedule']['time']
            : '08:00';
        $weekday = min( 6, max( 0, absint( $source['schedule']['weekday'] ?? 1 ) ) );
        $monthday = min( 28, max( 1, absint( $source['schedule']['monthday'] ?? 1 ) ) );
        $rules = $source['rules'] ?? array();
        $location_mappings = array();
        foreach ( (array) ( $rules['location_mappings'] ?? array() ) as $mapping ) {
            if ( ! is_array( $mapping ) ) { continue; }
            $match = sanitize_text_field( $mapping['match'] ?? '' );
            $location_name = sanitize_text_field( $mapping['location_name'] ?? '' );
            $stage_name = sanitize_text_field( $mapping['stage_name'] ?? '' );
            $em_location_id = absint( $mapping['em_location_id'] ?? 0 );
            if ( ! $match || ( ! $location_name && ! $em_location_id ) ) { continue; }
            $key = strtolower( trim( preg_replace( '/\s+/', ' ', $match ) ) );
            $location_mappings[ $key ] = array( 'match' => $match, 'em_location_id' => $em_location_id, 'location_name' => $location_name, 'stage_name' => $stage_name );
        }
        $location_mappings = array_values( $location_mappings );
        $file_path = wp_normalize_path( (string) ( $source['file_path'] ?? '' ) );
        $upload_dir = wp_get_upload_dir();
        $upload_base = wp_normalize_path( (string) ( $upload_dir['basedir'] ?? '' ) );
        if ( ! $file_path || ! $upload_base || ! str_starts_with( $file_path, trailingslashit( $upload_base ) ) ) {
            $file_path = '';
        }
        return array(
            'name'        => sanitize_text_field( $source['name'] ?? '' ),
            'source_type' => sanitize_key( $source['source_type'] ?? 'urls' ),
            'urls'        => array_values( $urls ),
            'file_path'   => $file_path,
            'file_name'   => sanitize_file_name( $source['file_name'] ?? '' ),
            'action'      => $action,
            'is_saved'    => empty( $source['is_saved'] ) ? 0 : 1,
            'workspace_active' => array_key_exists( 'workspace_active', $source ) ? ( empty( $source['workspace_active'] ) ? 0 : 1 ) : 1,
            'archived_at'      => sanitize_text_field( $source['archived_at'] ?? '' ),
            'schedule'    => array(
                'enabled'      => empty( $source['schedule']['enabled'] ) ? 0 : 1,
                'cadence'      => $cadence,
                'time'         => $time,
                'weekday'      => $weekday,
                'monthday'     => $monthday,
                'lookahead'    => min( 730, max( 1, absint( $source['schedule']['lookahead'] ?? $settings['default_lookahead'] ?? 90 ) ) ),
                'lookback'     => min( 365, max( 0, absint( $source['schedule']['lookback'] ?? $settings['default_lookback'] ?? 0 ) ) ),
                'next_run_gmt' => sanitize_text_field( $source['schedule']['next_run_gmt'] ?? '' ),
            ),
            'rules'       => array(
                'force_location_enabled' => empty( $rules['force_location_enabled'] ) ? 0 : 1,
                'forced_em_location_id'  => absint( $rules['forced_em_location_id'] ?? 0 ),
                'default_categories'     => array_values( array_filter( array_map( 'absint', (array) ( $rules['default_categories'] ?? array() ) ) ) ),
                'category_names'         => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $rules['category_names'] ?? array() ) ) ) ),
                'create_categories'      => empty( $rules['create_categories'] ) ? 0 : 1,
                'location_name_override' => sanitize_text_field( $rules['location_name_override'] ?? '' ),
                'location_mappings'      => $location_mappings,
                'structure'              => in_array( sanitize_key( $rules['structure'] ?? 'auto' ), array( 'auto', 'festival', 'conference', 'multi_session', 'multi_location' ), true ) ? sanitize_key( $rules['structure'] ?? 'auto' ) : 'auto',
                'location_policy'        => in_array( sanitize_key( $rules['location_policy'] ?? $settings['default_location_policy'] ?? 'auto_create' ), array( 'auto_create', 'existing_only' ), true ) ? sanitize_key( $rules['location_policy'] ?? $settings['default_location_policy'] ?? 'auto_create' ) : 'auto_create',
                'duplicate_policy'       => in_array( sanitize_key( $rules['duplicate_policy'] ?? $settings['default_duplicate_policy'] ?? 'update' ), array( 'update', 'review', 'skip' ), true ) ? sanitize_key( $rules['duplicate_policy'] ?? $settings['default_duplicate_policy'] ?? 'update' ) : 'update',
                'image_policy'           => in_array( sanitize_key( $rules['image_policy'] ?? $settings['default_image_policy'] ?? 'import' ), array( 'import', 'keep_url', 'ignore' ), true ) ? sanitize_key( $rules['image_policy'] ?? $settings['default_image_policy'] ?? 'import' ) : 'import',
                'event_author_id'        => self::sanitize_user_choice( $rules['event_author_id'] ?? $settings['default_event_author_id'] ?? 0 ),
                'protect_local_edits'    => 0,
                'include_ticket_details' => array_key_exists( 'include_ticket_details', $rules ) ? ( empty( $rules['include_ticket_details'] ) ? 0 : 1 ) : ( empty( $settings['include_ticket_details'] ) ? 0 : 1 ),
                'include_organizer_details' => array_key_exists( 'include_organizer_details', $rules ) ? ( empty( $rules['include_organizer_details'] ) ? 0 : 1 ) : ( empty( $settings['include_organizer_details'] ) ? 0 : 1 ),
                'require_description'    => empty( $rules['require_description'] ) ? 0 : 1,
                'require_image'          => empty( $rules['require_image'] ) ? 0 : 1,
                'require_ticket_url'     => empty( $rules['require_ticket_url'] ) ? 0 : 1,
                'include_keywords'       => self::sanitize_keyword_list( $rules['include_keywords'] ?? $settings['default_include_keywords'] ?? array() ),
                'exclude_keywords'       => self::sanitize_keyword_list( $rules['exclude_keywords'] ?? $settings['default_exclude_keywords'] ?? array() ),
                'default_country'        => strtoupper( substr( sanitize_text_field( $rules['default_country'] ?? $settings['default_country'] ?? 'US' ), 0, 2 ) ),
            ),
            'created_by'  => absint( $source['created_by'] ?? get_current_user_id() ),
        );
    }

    private static function sanitize_user_choice( mixed $value ): int {
        $user_id = absint( $value );
        return $user_id && get_userdata( $user_id ) ? $user_id : 0;
    }

    private static function sanitize_keyword_list( mixed $value ): array {
        $items = is_array( $value ) ? $value : preg_split( '/[\r\n,]+/', (string) $value );
        $items = array_map( static fn( $item ): string => sanitize_text_field( trim( (string) $item ) ), (array) $items );
        return array_values( array_unique( array_filter( $items, static fn( string $item ): bool => '' !== $item ) ) );
    }

    public static function get_source( int $source_id ): array {
        if ( self::SOURCE_CPT !== get_post_type( $source_id ) ) {
            return array();
        }
        $data = get_post_meta( $source_id, '_gi_source_data', true );
        if ( ! is_array( $data ) ) {
            return array();
        }
        $data['id'] = $source_id;
        return self::sanitize_source( $data ) + array( 'id' => $source_id );
    }

    public static function list_sources( array $args = array() ): array {
        $include_inactive = ! empty( $args['include_inactive'] );
        unset( $args['include_inactive'] );
        $query = new WP_Query(
            wp_parse_args(
                $args,
                array(
                    'post_type'      => self::SOURCE_CPT,
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'orderby'        => 'modified',
                    'order'          => 'DESC',
                    'no_found_rows'  => true,
                )
            )
        );
        $sources = array();
        foreach ( $query->posts as $post ) {
            $source = self::get_source( (int) $post->ID );
            if ( ! $source ) {
                continue;
            }
            if ( ! $include_inactive && empty( $source['workspace_active'] ) ) {
                continue;
            }
            $sources[] = $source;
        }
        return $sources;
    }

    public static function delete_source( int $source_id ): bool {
        if ( self::SOURCE_CPT !== get_post_type( $source_id ) ) {
            return false;
        }
        $source = self::get_source( $source_id );
        if ( ! empty( $source['file_path'] ) && is_file( $source['file_path'] ) ) {
            wp_delete_file( $source['file_path'] );
        }
        $candidate_ids = get_posts(
            array(
                'post_type'      => self::CANDIDATE_CPT,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'meta_key'       => '_gi_source_id',
                'meta_value'     => $source_id,
            )
        );
        foreach ( $candidate_ids as $candidate_id ) {
            wp_delete_post( (int) $candidate_id, true );
        }
        return (bool) wp_delete_post( $source_id, true );
    }



    public static function maybe_remove_completed_run_once_from_queue( int $source_id, int $run_id = 0 ): bool {
        $source = self::get_source( $source_id );
        $settings = self::settings();
        if ( ! $source || ! empty( $source['is_saved'] ) || empty( $source['workspace_active'] ) || empty( $settings['auto_remove_run_once'] ) ) {
            return false;
        }
        if ( $run_id ) {
            $run = self::get_run( $run_id );
            $run_summary = (array) ( $run['summary'] ?? array() );
            if ( ! empty( $run_summary['failed'] ) || ! empty( $run_summary['blocked'] ) || ! empty( $run_summary['errors'] ) ) {
                return false;
            }
        }
        $waiting = self::list_candidates( array( 'ready', 'held', 'failed' ), $source_id, 1 );
        if ( $waiting ) {
            return false;
        }
        $resolved = self::list_candidates( array( 'imported', 'updated', 'ignored' ), $source_id, 2 );
        if ( ! $resolved ) {
            return false;
        }
        $source_label = $source['name'] ?? ( $source['urls'][0] ?? $source['file_name'] ?? __( 'Run once source', 'great-imports' ) );
        $updated = self::update_source(
            $source_id,
            array(
                'workspace_active' => 0,
                'archived_at'      => current_time( 'mysql' ),
                'schedule'         => array( 'enabled' => 0, 'next_run_gmt' => '' ),
            )
        );
        if ( is_wp_error( $updated ) || ! $updated ) {
            return false;
        }
        if ( $run_id ) {
            $run = self::get_run( $run_id );
            if ( $run ) {
                $run['summary']['temporary_source_removed_from_queue'] = 1;
                $run['summary']['removed_source_name'] = sanitize_text_field( $source_label );
                update_post_meta( $run_id, '_gi_run_data', $run );
            }
        }
        return true;
    }

    /**
     * Backward-compatible alias retained for older internal calls.
     */
    public static function maybe_cleanup_completed_run_once_source( int $source_id, int $run_id = 0 ): bool {
        return self::maybe_remove_completed_run_once_from_queue( $source_id, $run_id );
    }

    public static function restore_source_to_queue( int $source_id ): bool|WP_Error {
        $source = self::get_source( $source_id );
        if ( ! $source ) {
            return new WP_Error( 'gi_source_missing', __( 'The source no longer exists.', 'great-imports' ) );
        }
        return self::update_source(
            $source_id,
            array(
                'workspace_active' => 1,
                'archived_at'      => '',
            )
        );
    }

    public static function create_or_merge_candidate( int $source_id, array $candidate, int $run_id = 0 ): array|WP_Error {
        $candidate['source_id']  = $source_id;
        $candidate['source_uid'] = GI_Utils::source_uid( $candidate );
        $candidate['fingerprint']= GI_Utils::fingerprint( $candidate );
        $existing_ids = self::find_candidate_ids( $source_id, $candidate );
        if ( $existing_ids ) {
            $existing_id = (int) $existing_ids[0];
            $existing = self::get_candidate( $existing_id );
            $manual_overrides = array();
            foreach ( array_reverse( $existing_ids ) as $matched_id ) {
                $overrides = get_post_meta( (int) $matched_id, '_gi_manual_overrides', true );
                if ( is_array( $overrides ) ) {
                    $manual_overrides = array_replace( $manual_overrides, $overrides );
                }
            }
            $merge_inputs = array( $candidate );
            if ( $manual_overrides ) {
                $manual_source = $manual_overrides['event_url'] ?? ( $candidate['source_urls'][0] ?? '' );
                $merge_inputs[] = GI_Normalizer::from_manual_overrides( $manual_overrides, $manual_source );
            }
            $merged = GI_Normalizer::merge_candidates( $merge_inputs );
            foreach ( $manual_overrides as $key => $value ) {
                $merged[ $key ] = $value;
            }

            $preserved = $existing;
            foreach ( array_slice( $existing_ids, 1 ) as $matched_id ) {
                $duplicate = self::get_candidate( (int) $matched_id );
                foreach ( array( 'em_event_id', 'em_post_id', 'em_location_id', 'imported_at', 'em_event_ids', 'em_post_ids', 'series_id', 'occurrence_count' ) as $key ) {
                    if ( empty( $preserved[ $key ] ) && ! empty( $duplicate[ $key ] ) ) {
                        $preserved[ $key ] = $duplicate[ $key ];
                    }
                }
            }
            foreach ( array( 'source_id', 'id', 'em_event_id', 'em_post_id', 'em_location_id', 'imported_at', 'em_event_ids', 'em_post_ids', 'series_id', 'occurrence_count' ) as $key ) {
                if ( isset( $preserved[ $key ] ) ) {
                    $merged[ $key ] = $preserved[ $key ];
                }
            }
            foreach ( array( 'categories', 'tags' ) as $key ) {
                if ( array_key_exists( $key, $manual_overrides ) ) {
                    $merged[ $key ] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $manual_overrides[ $key ] ) ) ) );
                }
            }
            if ( 'ignored' === ( $existing['status'] ?? '' ) ) {
                $merged['status'] = 'ignored';
            } else {
                $merged['status'] = 'held';
                $merged = GI_Normalizer::finalize_candidate( $merged );
            }
            self::save_candidate_data( $existing_id, $source_id, $run_id, $merged );
            if ( $manual_overrides ) {
                update_post_meta( $existing_id, '_gi_manual_overrides', $manual_overrides );
            }
            wp_update_post( array( 'ID' => $existing_id, 'post_title' => $merged['title'] ?: __( 'Untitled event candidate', 'great-imports' ) ) );

            $consolidated = 0;
            foreach ( array_slice( $existing_ids, 1 ) as $duplicate_id ) {
                if ( wp_delete_post( (int) $duplicate_id, true ) ) {
                    ++$consolidated;
                }
            }
            return array( 'id' => $existing_id, 'created' => false, 'consolidated' => $consolidated, 'candidate' => self::get_candidate( $existing_id ) );
        }
        $id = wp_insert_post(
            array(
                'post_type'   => self::CANDIDATE_CPT,
                'post_status' => 'publish',
                'post_title'  => sanitize_text_field( $candidate['title'] ?? __( 'Untitled event candidate', 'great-imports' ) ),
            ),
            true
        );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        self::save_candidate_data( (int) $id, $source_id, $run_id, $candidate );
        return array( 'id' => (int) $id, 'created' => true, 'consolidated' => 0, 'candidate' => self::get_candidate( (int) $id ) );
    }

    private static function save_candidate_data( int $candidate_id, int $source_id, int $run_id, array $candidate ): void {
        $candidate['id'] = $candidate_id;
        update_post_meta( $candidate_id, '_gi_candidate_data', $candidate );
        update_post_meta( $candidate_id, '_gi_source_id', $source_id );
        update_post_meta( $candidate_id, '_gi_run_id', $run_id );
        update_post_meta( $candidate_id, '_gi_status', sanitize_key( $candidate['status'] ?? 'held' ) );
        update_post_meta( $candidate_id, '_gi_fingerprint', $candidate['fingerprint'] ?? GI_Utils::fingerprint( $candidate ) );
        update_post_meta( $candidate_id, '_gi_source_uid', $candidate['source_uid'] ?? GI_Utils::source_uid( $candidate ) );
        update_post_meta( $candidate_id, '_gi_start_date', sanitize_text_field( $candidate['start_date'] ?? '' ) );
    }

    /**
     * Normalize and consolidate active review candidates after an importer
     * upgrade. Imported Events Manager records are never deleted or modified.
     */
    public static function repair_candidate_queue(): array {
        $summary = array( 'normalized' => 0, 'consolidated' => 0 );
        foreach ( self::list_sources() as $source ) {
            $source_id = absint( $source['id'] ?? 0 );
            if ( ! $source_id ) {
                continue;
            }
            $candidates = self::list_candidates( array( 'ready', 'held', 'failed' ), $source_id, -1 );
            foreach ( $candidates as $candidate ) {
                $candidate_id = absint( $candidate['id'] ?? 0 );
                if ( ! $candidate_id || self::CANDIDATE_CPT !== get_post_type( $candidate_id ) ) {
                    continue;
                }
                $normalized = GI_Normalizer::finalize_candidate( GI_Utils::normalize_location_fields( $candidate ) );
                $normalized['source_id'] = $source_id;
                $normalized['id'] = $candidate_id;
                self::save_candidate_data( $candidate_id, $source_id, absint( get_post_meta( $candidate_id, '_gi_run_id', true ) ), $normalized );
                wp_update_post( array( 'ID' => $candidate_id, 'post_title' => $normalized['title'] ?: __( 'Untitled event candidate', 'great-imports' ) ) );
                ++$summary['normalized'];
            }

            $candidates = self::list_candidates( array( 'ready', 'held', 'failed' ), $source_id, -1 );
            $count = count( $candidates );
            $visited = array_fill( 0, $count, false );
            for ( $index = 0; $index < $count; ++$index ) {
                if ( $visited[ $index ] ) {
                    continue;
                }
                $group_indexes = array( $index );
                $visited[ $index ] = true;
                for ( $cursor = 0; $cursor < count( $group_indexes ); ++$cursor ) {
                    $left_index = $group_indexes[ $cursor ];
                    for ( $right_index = 0; $right_index < $count; ++$right_index ) {
                        if ( $visited[ $right_index ] ) {
                            continue;
                        }
                        if ( GI_Utils::candidates_share_identity( $candidates[ $left_index ], $candidates[ $right_index ] ) ) {
                            $visited[ $right_index ] = true;
                            $group_indexes[] = $right_index;
                        }
                    }
                }
                if ( count( $group_indexes ) < 2 ) {
                    continue;
                }

                $group = array_map( static fn( int $item_index ): array => $candidates[ $item_index ], $group_indexes );
                usort(
                    $group,
                    static function ( array $left, array $right ): int {
                        $score = static function ( array $candidate ): int {
                            $candidate_id = absint( $candidate['id'] ?? 0 );
                            $manual = get_post_meta( $candidate_id, '_gi_manual_overrides', true );
                            return ( ! empty( $candidate['em_event_id'] ) || ! empty( $candidate['em_post_id'] ) ? 100 : 0 )
                                + ( is_array( $manual ) && $manual ? 50 : 0 )
                                + ( ! empty( $candidate['stage_name'] ) ? 20 : 0 )
                                + count( array_filter( array( $candidate['location_address'] ?? '', $candidate['location_city'] ?? '', $candidate['location_state'] ?? '', $candidate['location_postcode'] ?? '' ) ) );
                        };
                        return ( $score( $right ) <=> $score( $left ) ) ?: ( absint( $left['id'] ?? 0 ) <=> absint( $right['id'] ?? 0 ) );
                    }
                );
                $keeper = $group[0];
                $keeper_id = absint( $keeper['id'] ?? 0 );
                $manual_overrides = array();
                foreach ( array_reverse( $group ) as $item ) {
                    $overrides = get_post_meta( absint( $item['id'] ?? 0 ), '_gi_manual_overrides', true );
                    if ( is_array( $overrides ) ) {
                        $manual_overrides = array_replace( $manual_overrides, $overrides );
                    }
                }
                $merge_inputs = $group;
                if ( $manual_overrides ) {
                    $manual_source = $manual_overrides['event_url'] ?? ( $keeper['source_urls'][0] ?? '' );
                    $merge_inputs[] = GI_Normalizer::from_manual_overrides( $manual_overrides, $manual_source );
                }
                $merged = GI_Normalizer::merge_candidates( $merge_inputs );
                foreach ( $manual_overrides as $key => $value ) {
                    $merged[ $key ] = $value;
                }
                foreach ( $group as $item ) {
                    foreach ( array( 'em_event_id', 'em_post_id', 'em_location_id', 'imported_at', 'em_event_ids', 'em_post_ids', 'series_id', 'occurrence_count' ) as $key ) {
                        if ( empty( $merged[ $key ] ) && ! empty( $item[ $key ] ) ) {
                            $merged[ $key ] = $item[ $key ];
                        }
                    }
                }
                $merged['source_id'] = $source_id;
                $merged['id'] = $keeper_id;
                $merged['status'] = 'held';
                $merged = GI_Normalizer::finalize_candidate( $merged );
                self::save_candidate_data( $keeper_id, $source_id, absint( get_post_meta( $keeper_id, '_gi_run_id', true ) ), $merged );
                wp_update_post( array( 'ID' => $keeper_id, 'post_title' => $merged['title'] ?: __( 'Untitled event candidate', 'great-imports' ) ) );
                if ( $manual_overrides ) {
                    update_post_meta( $keeper_id, '_gi_manual_overrides', $manual_overrides );
                }
                foreach ( array_slice( $group, 1 ) as $duplicate ) {
                    if ( wp_delete_post( absint( $duplicate['id'] ?? 0 ), true ) ) {
                        ++$summary['consolidated'];
                    }
                }
            }
        }
        return $summary;
    }

    public static function update_candidate( int $candidate_id, array $changes ): array|WP_Error {
        $existing = self::get_candidate( $candidate_id );
        if ( ! $existing ) {
            return new WP_Error( 'gi_candidate_missing', __( 'The candidate no longer exists.', 'great-imports' ) );
        }
        if ( 'manual' === ( $changes['method'] ?? '' ) ) {
            $posted = $changes;
            unset( $posted['method'], $posted['method_priority'], $posted['conflicts'], $posted['hold_reasons'], $posted['status'] );

            $stored_overrides = get_post_meta( $candidate_id, '_gi_manual_overrides', true );
            $stored_overrides = is_array( $stored_overrides ) ? $stored_overrides : array();
            $delta = array();
            foreach ( $posted as $key => $value ) {
                if ( ! self::candidate_values_equal( $key, $existing[ $key ] ?? null, $value ) ) {
                    $delta[ $key ] = $value;
                }
            }
            $stored_overrides = array_replace( $stored_overrides, $delta );
            update_post_meta( $candidate_id, '_gi_manual_overrides', $stored_overrides );

            $base = $existing;
            foreach ( (array) ( $base['evidence'] ?? array() ) as $field => $entries ) {
                $base['evidence'][ $field ] = array_values( array_filter( (array) $entries, static fn( $entry ) => 'manual' !== ( $entry['method'] ?? '' ) ) );
            }

            $manual_source = $stored_overrides['event_url'] ?? ( $existing['source_urls'][0] ?? '' );
            $merge_inputs = array( $base );
            if ( $stored_overrides ) {
                $merge_inputs[] = GI_Normalizer::from_manual_overrides( $stored_overrides, $manual_source );
            }
            $candidate = GI_Normalizer::merge_candidates( $merge_inputs );
            foreach ( $stored_overrides as $key => $value ) {
                $candidate[ $key ] = $value;
            }
            foreach ( array( 'source_id', 'id', 'em_event_id', 'em_post_id', 'em_location_id', 'imported_at' ) as $key ) {
                if ( array_key_exists( $key, $existing ) && ! array_key_exists( $key, $stored_overrides ) ) {
                    $candidate[ $key ] = $existing[ $key ];
                }
            }
            if ( ! empty( $candidate['explicit_content_approved'] ) ) {
                $candidate['hold_reasons'] = array_values(
                    array_filter(
                        (array) ( $candidate['hold_reasons'] ?? array() ),
                        static fn( $reason ): bool => ! str_starts_with( (string) $reason, 'Explicit-content review:' )
                    )
                );
            }
            $candidate['status'] = 'held';
            $candidate['source_uid'] = GI_Utils::source_uid( $candidate );
            $candidate['fingerprint'] = GI_Utils::fingerprint( $candidate );
        } else {
            $candidate = array_replace( $existing, $changes );
        }
        $candidate = GI_Normalizer::finalize_candidate( $candidate );
        self::save_candidate_data( $candidate_id, absint( $candidate['source_id'] ?? 0 ), absint( get_post_meta( $candidate_id, '_gi_run_id', true ) ), $candidate );
        wp_update_post( array( 'ID' => $candidate_id, 'post_title' => $candidate['title'] ?: __( 'Untitled event candidate', 'great-imports' ) ) );
        return self::get_candidate( $candidate_id );
    }

    private static function candidate_values_equal( string $key, $current, $posted ): bool {
        if ( 'festival_slots' === $key ) {
            return wp_json_encode( array_values( (array) $current ) ) === wp_json_encode( array_values( (array) $posted ) );
        }
        if ( in_array( $key, array( 'categories', 'tags', 'recurrence_weekdays' ), true ) ) {
            $left = array_values( array_unique( array_map( 'strval', (array) $current ) ) );
            $right = array_values( array_unique( array_map( 'strval', (array) $posted ) ) );
            sort( $left );
            sort( $right );
            return $left === $right;
        }
        if ( 'all_day' === $key ) {
            return (bool) $current === (bool) $posted;
        }
        if ( in_array( $key, array( 'em_location_id', 'recurrence_interval', 'recurrence_count' ), true ) ) {
            return (int) $current === (int) $posted;
        }
        if ( in_array( $key, array( 'start_time', 'end_time' ), true ) ) {
            $normalize = static function ( $value ): string {
                $value = trim( (string) $value );
                return preg_match( '/^\d{2}:\d{2}$/', $value ) ? $value . ':00' : $value;
            };
            return $normalize( $current ) === $normalize( $posted );
        }
        if ( in_array( $key, array( 'event_url', 'ticket_url', 'image_url' ), true ) ) {
            return GI_Utils::clean_url( (string) $current ) === GI_Utils::clean_url( (string) $posted );
        }
        return str_replace( "\r\n", "\n", trim( (string) $current ) ) === str_replace( "\r\n", "\n", trim( (string) $posted ) );
    }

    public static function get_candidate( int $candidate_id ): array {
        if ( self::CANDIDATE_CPT !== get_post_type( $candidate_id ) ) {
            return array();
        }
        $data = get_post_meta( $candidate_id, '_gi_candidate_data', true );
        if ( ! is_array( $data ) ) {
            return array();
        }
        $data['id'] = $candidate_id;
        return $data;
    }

    public static function list_candidates( array $statuses = array(), int $source_id = 0, int $limit = -1 ): array {
        $meta_query = array();
        if ( $statuses ) {
            $meta_query[] = array(
                'key'     => '_gi_status',
                'value'   => array_map( 'sanitize_key', $statuses ),
                'compare' => 'IN',
            );
        }
        if ( $source_id ) {
            $meta_query[] = array( 'key' => '_gi_source_id', 'value' => $source_id, 'compare' => '=' );
        }
        $query = new WP_Query(
            array(
                'post_type'      => self::CANDIDATE_CPT,
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => true,
                'meta_query'     => $meta_query,
            )
        );
        return array_values( array_filter( array_map( fn( $post ) => self::get_candidate( (int) $post->ID ), $query->posts ) ) );
    }

    public static function count_candidates(): array {
        $counts = array_fill_keys( array( 'ready', 'held', 'imported', 'updated', 'failed', 'ignored', 'blocked' ), 0 );
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts must reflect writes made during the current import run.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_value AS status, COUNT(*) AS total
                 FROM %i pm
                 INNER JOIN %i p ON p.ID = pm.post_id
                 WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_key = '_gi_status'
                 GROUP BY pm.meta_value",
                $wpdb->postmeta,
                $wpdb->posts,
                self::CANDIDATE_CPT
            ),
            ARRAY_A
        );
        foreach ( $rows ?: array() as $row ) {
            $counts[ sanitize_key( $row['status'] ) ] = (int) $row['total'];
        }
        return $counts;
    }

    private static function find_candidate_ids( int $source_id, array $candidate ): array {
        $ids = get_posts(
            array(
                'post_type'      => self::CANDIDATE_CPT,
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'meta_query'     => array(
                    array( 'key' => '_gi_source_id', 'value' => $source_id ),
                ),
            )
        );
        $matches = array();
        foreach ( $ids ?: array() as $id ) {
            $existing = self::get_candidate( (int) $id );
            if ( ! $existing ) {
                continue;
            }
            $exact_uid = ! empty( $candidate['source_uid'] ) && (string) ( $existing['source_uid'] ?? '' ) === (string) $candidate['source_uid'];
            $exact_fingerprint = ! empty( $candidate['fingerprint'] ) && (string) ( $existing['fingerprint'] ?? '' ) === (string) $candidate['fingerprint'];
            if ( $exact_uid || $exact_fingerprint || GI_Utils::candidates_share_identity( $candidate, $existing ) ) {
                $score = 0;
                $score += ! empty( $existing['em_post_id'] ) || ! empty( $existing['em_event_id'] ) ? 100 : 0;
                $score += is_array( get_post_meta( (int) $id, '_gi_manual_overrides', true ) ) && get_post_meta( (int) $id, '_gi_manual_overrides', true ) ? 50 : 0;
                $score += $exact_uid ? 20 : 0;
                $score += $exact_fingerprint ? 10 : 0;
                $matches[] = array( 'id' => (int) $id, 'score' => $score );
            }
        }
        usort(
            $matches,
            static fn( array $left, array $right ): int => ( $right['score'] <=> $left['score'] ) ?: ( $left['id'] <=> $right['id'] )
        );
        return array_values( array_map( static fn( array $match ): int => $match['id'], $matches ) );
    }

    public static function create_run( int $source_id, string $trigger, string $action ): int|WP_Error {
        $id = wp_insert_post(
            array(
                'post_type'   => self::RUN_CPT,
                'post_status' => 'publish',
                'post_title'  => sprintf( 'Run %s', current_time( 'mysql' ) ),
            ),
            true
        );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        $source = self::get_source( $source_id );
        $run = array(
            'id'          => (int) $id,
            'source_id'   => $source_id,
            'source_name' => sanitize_text_field( $source['name'] ?? '' ),
            'source_value'=> sanitize_text_field( $source['urls'][0] ?? $source['file_name'] ?? '' ),
            'trigger'     => sanitize_key( $trigger ),
            'action'      => sanitize_key( $action ),
            'status'      => 'running',
            'started_at'  => current_time( 'mysql' ),
            'finished_at' => '',
            'summary'     => array(
                'collected' => 0, 'created' => 0, 'merged' => 0, 'ready' => 0, 'held' => 0,
                'imported' => 0, 'updated' => 0, 'failed' => 0, 'blocked' => 0,
                'skipped_outside_window' => 0, 'filtered' => 0, 'skipped_existing' => 0, 'duplicates_consolidated' => 0, 'errors' => array(),
            ),
            'messages'    => array(),
        );
        update_post_meta( (int) $id, '_gi_run_data', $run );
        update_post_meta( (int) $id, '_gi_source_id', $source_id );
        return (int) $id;
    }

    public static function get_run( int $run_id ): array {
        $run = get_post_meta( $run_id, '_gi_run_data', true );
        return is_array( $run ) ? $run : array();
    }

    public static function update_run( int $run_id, array $changes ): void {
        $run = self::get_run( $run_id );
        if ( ! $run ) {
            return;
        }
        $run = array_replace_recursive( $run, $changes );
        update_post_meta( $run_id, '_gi_run_data', $run );
    }

    public static function log_run( int $run_id, string $level, string $message, array $context = array() ): void {
        $run = self::get_run( $run_id );
        if ( ! $run ) {
            return;
        }
        $run['messages'][] = array(
            'time'    => current_time( 'mysql' ),
            'level'   => sanitize_key( $level ),
            'message' => sanitize_text_field( $message ),
            'context' => self::redact_context( $context ),
        );
        update_post_meta( $run_id, '_gi_run_data', $run );
    }

    public static function finish_run( int $run_id, array $summary, string $status = 'complete' ): void {
        self::update_run(
            $run_id,
            array(
                'status'      => sanitize_key( $status ),
                'finished_at' => current_time( 'mysql' ),
                'summary'     => $summary,
            )
        );
        self::prune_runs();
    }

    public static function list_runs( int $limit = 50 ): array {
        $ids = get_posts(
            array(
                'post_type'      => self::RUN_CPT,
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => $limit,
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );
        return array_values( array_filter( array_map( fn( $id ) => self::get_run( (int) $id ), $ids ) ) );
    }

    public static function prune_runs(): void {
        $cutoff = wp_date( 'Y-m-d H:i:s', time() - ( self::RUN_RETENTION_DAYS * DAY_IN_SECONDS ), wp_timezone() );
        $ids = get_posts(
            array(
                'post_type'      => self::RUN_CPT,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'date_query'      => array(
                    array(
                        'column'    => 'post_date',
                        'before'    => $cutoff,
                        'inclusive' => false,
                    ),
                ),
            )
        );
        foreach ( $ids as $id ) {
            wp_delete_post( (int) $id, true );
        }
    }

    public static function clear_run_history(): int {
        $ids = get_posts(
            array(
                'post_type'      => self::RUN_CPT,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => -1,
            )
        );
        $deleted = 0;
        foreach ( $ids as $id ) {
            if ( wp_delete_post( (int) $id, true ) ) {
                ++$deleted;
            }
        }
        return $deleted;
    }

    public static function reset_plugin_data(): void {
        foreach ( self::list_sources( array( 'include_inactive' => true ) ) as $source ) {
            self::delete_source( (int) ( $source['id'] ?? 0 ) );
        }
        foreach ( array( self::SOURCE_CPT, self::CANDIDATE_CPT, self::RUN_CPT ) as $post_type ) {
            $ids = get_posts(
                array(
                    'post_type'      => $post_type,
                    'post_status'    => 'any',
                    'fields'         => 'ids',
                    'posts_per_page' => -1,
                )
            );
            foreach ( $ids as $id ) {
                wp_delete_post( (int) $id, true );
            }
        }
        update_option( self::SETTINGS_KEY, self::default_settings(), false );
        wp_clear_scheduled_hook( 'gi_run_due_sources' );
    }

    public static function diagnostics(): array {
        $settings = self::settings();
        $safe_settings = $settings;
        foreach ( array( 'eventbrite_token', 'ticketmaster_key' ) as $key ) {
            $safe_settings[ $key . '_configured' ] = ! empty( $settings[ $key ] );
            unset( $safe_settings[ $key ] );
        }
        return array(
            'report_type' => 'great_imports_diagnostics',
            'generated_at' => current_time( 'mysql' ),
            'plugin_version' => GI_VERSION,
            'environment' => array(
                'site_url' => site_url(),
                'home_url' => home_url(),
                'wordpress_version' => get_bloginfo( 'version' ),
                'php_version' => PHP_VERSION,
                'timezone' => wp_timezone_string(),
                'multisite' => is_multisite(),
                'events_manager' => GI_Events_Manager::health(),
            ),
            'settings' => $safe_settings,
            'summary' => array(
                'sources' => count( self::list_sources() ),
                'candidate_counts' => self::count_candidates(),
                'recent_candidate_writes' => self::recent_candidate_write_diagnostics(),
                'latest_runs' => self::list_runs( 20 ),
            ),
            'notes' => array(
                'Raw page bodies, secrets, cookies, authorization headers, and coordinate values are intentionally excluded.',
                'Blocked sources remain visible as run records and are not converted into fabricated events.',
            ),
        );
    }

    public static function repair_events_manager_candidate_links(): array {
        $checked = 0;
        $repaired = 0;
        foreach ( self::list_candidates( array( 'imported', 'updated' ), 0, -1 ) as $candidate ) {
            ++$checked;
            if ( GI_Events_Manager::repair_candidate_links( $candidate ) ) {
                ++$repaired;
            }
        }
        return array(
            'event_links_checked' => $checked,
            'event_links_repaired' => $repaired,
        );
    }

    /**
     * Include enough retained write information to verify that a reported
     * import still has both its WordPress post and Events Manager row.
     * Descriptions, coordinates, request bodies, and credentials are omitted.
     */
    private static function recent_candidate_write_diagnostics(): array {
        global $wpdb;

        $events_table = GI_Events_Manager::events_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics must inspect the current Events Manager schema.
        $events_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) ) === $events_table;
        $items = array();
        foreach ( self::list_candidates( array( 'imported', 'updated', 'failed' ), 0, 50 ) as $candidate ) {
            $post_id = absint( $candidate['em_post_id'] ?? 0 );
            $event_id = absint( $candidate['em_event_id'] ?? 0 );
            $post = $post_id ? get_post( $post_id ) : null;
            $event_row = null;
            if ( $events_table_exists && ( $event_id || $post_id ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics read the authoritative Events Manager row.
                $event_row = $event_id
                    ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d LIMIT 1', $events_table, $event_id ), ARRAY_A )
                    : $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY event_id ASC LIMIT 1', $events_table, $post_id ), ARRAY_A );
            }
            $items[] = array(
                'candidate_id' => absint( $candidate['id'] ?? 0 ),
                'source_id' => absint( $candidate['source_id'] ?? 0 ),
                'title' => sanitize_text_field( $candidate['title'] ?? '' ),
                'candidate_status' => sanitize_key( $candidate['status'] ?? '' ),
                'requested_start' => sanitize_text_field( trim( (string) ( $candidate['start_date'] ?? '' ) . ' ' . (string) ( $candidate['start_time'] ?? '' ) ) ),
                'image_url' => esc_url_raw( $candidate['image_url'] ?? '' ),
                'em_post_id' => $post_id,
                'em_event_id' => $event_id,
                'em_location_id' => absint( $candidate['em_location_id'] ?? 0 ),
                'post_exists' => (bool) $post,
                'post_type' => $post ? sanitize_key( $post->post_type ) : '',
                'post_status' => $post ? sanitize_key( $post->post_status ) : '',
                'post_title' => $post ? sanitize_text_field( $post->post_title ) : '',
                'featured_image_id' => $post ? absint( get_post_thumbnail_id( $post_id ) ) : 0,
                'image_import_error' => $post ? sanitize_text_field( get_post_meta( $post_id, '_gi_image_import_error', true ) ) : '',
                'event_row_exists' => (bool) $event_row,
                'event_row' => $event_row ? array(
                    'event_id' => absint( $event_row['event_id'] ?? 0 ),
                    'post_id' => absint( $event_row['post_id'] ?? 0 ),
                    'event_name' => sanitize_text_field( $event_row['event_name'] ?? '' ),
                    'event_slug' => sanitize_title( $event_row['event_slug'] ?? '' ),
                    'event_start_date' => sanitize_text_field( $event_row['event_start_date'] ?? '' ),
                    'event_start_time' => sanitize_text_field( $event_row['event_start_time'] ?? '' ),
                    'event_status' => isset( $event_row['event_status'] ) ? (int) $event_row['event_status'] : null,
                    'event_active_status' => isset( $event_row['event_active_status'] ) ? (int) $event_row['event_active_status'] : null,
                    'location_id' => absint( $event_row['location_id'] ?? 0 ),
                ) : null,
                'last_error' => sanitize_text_field( $candidate['last_error'] ?? '' ),
            );
        }
        return $items;
    }

    private static function redact_context( array $context ): array {
        $safe = array();
        foreach ( $context as $key => $value ) {
            if ( preg_match( '/token|secret|password|authorization|cookie|latitude|longitude|coordinate/i', (string) $key ) ) {
                $safe[ $key ] = '[redacted]';
            } elseif ( is_scalar( $value ) || null === $value ) {
                $safe[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
            } elseif ( is_array( $value ) ) {
                $safe[ $key ] = self::redact_context( $value );
            }
        }
        return $safe;
    }

    private static function source_title_from_urls( array $urls ): string {
        if ( ! $urls ) {
            return '';
        }
        $host = wp_parse_url( $urls[0], PHP_URL_HOST );
        /* translators: %s: source website hostname. */
        return $host ? sprintf( __( '%s events', 'great-imports' ), preg_replace( '/^www\./', '', (string) $host ) ) : __( 'Saved event source', 'great-imports' );
    }
}
