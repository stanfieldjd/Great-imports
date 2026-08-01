<?php

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Admin mutation handlers verify their nonces before saving. Read-only filters are sanitized before use.

final class GI_Admin {
    private static ?GI_Admin $instance = null;

    public static function instance(): GI_Admin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

        $actions = array(
            'gi_create_source'          => 'handle_create_source',
            'gi_update_source'          => 'handle_update_source',
            'gi_run_source'             => 'handle_run_source',
            'gi_toggle_source'          => 'handle_toggle_source',
            'gi_delete_source'          => 'handle_delete_source',
            'gi_restore_source'         => 'handle_restore_source',
            'gi_candidate_action'       => 'handle_candidate_action',
            'gi_bulk_candidates'        => 'handle_bulk_candidates',
            'gi_apply_source_rules'      => 'handle_apply_source_rules',
            'gi_save_settings'          => 'handle_save_settings',
            'gi_download_diagnostics'   => 'handle_download_diagnostics',
            'gi_download_run'           => 'handle_download_run',
            'gi_clear_run_history'      => 'handle_clear_run_history',
            'gi_reset_plugin_data'      => 'handle_reset_plugin_data',
        );
        foreach ( $actions as $action => $method ) {
            add_action( 'admin_post_' . $action, array( $this, $method ) );
        }
    }

    public function menu(): void {
        add_menu_page(
            __( 'Great Imports', 'great-imports' ),
            __( 'Great Imports', 'great-imports' ),
            'manage_options',
            'great-imports',
            array( $this, 'render' ),
            'dashicons-calendar-alt',
            26
        );
    }

    public function enqueue( string $hook ): void {
        if ( 'toplevel_page_great-imports' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'great-imports-admin', GI_URL . 'assets/admin.css', array(), GI_VERSION );
        wp_enqueue_script( 'great-imports-admin', GI_URL . 'assets/admin.js', array(), GI_VERSION, true );
    }

    public function render(): void {
        $this->require_access();
        $tab = sanitize_key( $_GET['tab'] ?? 'new' );
        if ( 'saved' === $tab ) {
            $tab = 'recurring';
        }
        if ( 'review' === $tab ) {
            $tab = $this->source_tab( absint( $_GET['source_id'] ?? 0 ) );
        }
        if ( ! in_array( $tab, array( 'new', 'review', 'sources', 'recurring', 'runs', 'settings' ), true ) ) {
            $tab = 'new';
        }
        $workflow = $this->workflow_counts();
        $is_url_page = in_array( $tab, array( 'sources', 'recurring', 'review' ), true );
        echo '<div class="wrap gi-wrap gi-rebuild-shell gi-simple-shell ' . ( $is_url_page ? 'gi-url-organizer-page' : '' ) . '" data-gi-rebuilt-ui="4.4.0">';
        if ( ! $is_url_page ) {
            echo '<header class="gi-rebuild-header gi-simple-header"><div><span class="gi-eyebrow">' . esc_html__( 'Great Imports', 'great-imports' ) . '</span><h1>' . esc_html__( 'Add events to your calendar', 'great-imports' ) . '</h1><p>' . esc_html__( 'Give us a link. We find the events. You choose what gets added.', 'great-imports' ) . '</p></div></header>';
        } else {
            // WordPress and third-party admin notices look for the first page
            // heading. Keep that anchor outside the first URL card so notices
            // cannot be injected into a URL-owned queue.
            echo '<h1 class="screen-reader-text">' . esc_html__( 'Review events', 'great-imports' ) . '</h1>';
        }
        $this->render_notice();
        $this->render_nav( $tab, $workflow );
        echo '<main class="gi-main">';
        switch ( $tab ) {
            case 'review':
                $this->render_review();
                break;
            case 'sources':
                $this->render_sources( false );
                break;
            case 'recurring':
                $this->render_sources( true );
                break;
            case 'runs':
                $this->render_runs();
                break;
            case 'settings':
                $this->render_settings();
                break;
            case 'new':
            default:
                $this->render_new_import();
                break;
        }
        echo '</main></div>';
    }

    private function workflow_counts(): array {
        $sources = GI_Storage::list_sources();
        $ready = 0;
        $attention = 0;
        foreach ( $sources as $source ) {
            $source_id = absint( $source['id'] ?? 0 );
            if ( ! $source_id ) {
                continue;
            }
            $ready += count( GI_Storage::list_candidates( array( 'ready' ), $source_id, -1 ) );
            $attention += count( GI_Storage::list_candidates( array( 'held', 'failed' ), $source_id, -1 ) );
        }
        return array(
            'sources'   => count( $sources ),
            'ready'     => $ready,
            'attention' => $attention,
        );
    }

    private function render_nav( string $active, array $workflow = array() ): void {
        $items = array(
            'new'       => array( 'icon' => 'dashicons-plus-alt2', 'label' => __( 'Add events', 'great-imports' ), 'hint' => __( 'Links, lists, or files', 'great-imports' ) ),
            'sources'   => array( 'icon' => 'dashicons-controls-play', 'label' => __( 'Run once queue', 'great-imports' ), 'hint' => __( 'One-time results', 'great-imports' ) ),
            'recurring' => array( 'icon' => 'dashicons-update', 'label' => __( 'Recurring URLs', 'great-imports' ), 'hint' => __( 'Automatic checks', 'great-imports' ) ),
            'settings'  => array( 'icon' => 'dashicons-admin-generic', 'label' => __( 'Settings', 'great-imports' ), 'hint' => __( 'Defaults and tools', 'great-imports' ) ),
        );
        echo '<div class="gi-rebuild-nav"><nav class="gi-main-nav gi-balanced-nav" aria-label="' . esc_attr__( 'Great Imports pages', 'great-imports' ) . '">';
        foreach ( $items as $key => $item ) {
            $url = admin_url( 'admin.php?page=great-imports&tab=' . $key );
            $is_active = $active === $key || ( 'review' === $active && 'sources' === $key ) || ( 'runs' === $active && 'settings' === $key );
            echo '<a class="gi-main-nav-item ' . ( $is_active ? 'is-active' : '' ) . '" href="' . esc_url( $url ) . '"><span class="gi-nav-icon dashicons ' . esc_attr( $item['icon'] ) . '" aria-hidden="true"></span><span><strong>' . esc_html( $item['label'] ) . '</strong><small>' . esc_html( $item['hint'] ) . '</small></span></a>';
        }
        echo '</nav></div>';
    }

    private function render_new_import(): void {
        $health = GI_Events_Manager::health();
        $dom_available = class_exists( 'DOMDocument' );
        $locations = GI_Events_Manager::list_locations();

        echo '<section class="gi-import-start gi-import-simple gi-rebuild-intake"><div class="gi-rebuild-intake-copy"><div><span class="gi-step-kicker">' . esc_html__( 'Step 1 of 2', 'great-imports' ) . '</span><h2>' . esc_html__( 'Add events', 'great-imports' ) . '</h2><p>' . esc_html__( 'Paste one link, paste a list of links, or choose an event file. Great Imports figures out the source type for you.', 'great-imports' ) . '</p></div></div><div>';
        if ( empty( $health['available'] ) ) {
            echo '<div class="gi-blocking-message"><span class="dashicons dashicons-warning"></span><div><strong>' . esc_html__( 'Events Manager is not connected', 'great-imports' ) . '</strong><p>' . esc_html__( 'The source can be prepared, but events cannot be imported until Events Manager is available.', 'great-imports' ) . '</p></div></div>';
        }
        if ( ! $dom_available ) {
            echo '<div class="gi-warning-message"><span class="dashicons dashicons-info-outline"></span><div><strong>' . esc_html__( 'Some website pages cannot be read on this server', 'great-imports' ) . '</strong><p>' . esc_html__( 'ICS, CSV, JSON, Eventbrite, and Ticketmaster sources can still work.', 'great-imports' ) . '</p></div></div>';
        }

        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" enctype="multipart/form-data" class="gi-start-card gi-rebuild-card">';
        wp_nonce_field( 'gi_create_source' );
        echo '<input type="hidden" name="action" value="gi_create_source">';
        echo '<div class="gi-source-type gi-simple-choice" role="radiogroup" aria-label="' . esc_attr__( 'Choose links or a file', 'great-imports' ) . '">';
        echo '<label class="is-selected"><input type="radio" name="input_source" value="urls" checked data-gi-input-source><span class="dashicons dashicons-admin-links"></span><span><strong>' . esc_html__( 'Links', 'great-imports' ) . '</strong><small>' . esc_html__( 'One link or many links', 'great-imports' ) . '</small></span></label>';
        echo '<label><input type="radio" name="input_source" value="file" data-gi-input-source><span class="dashicons dashicons-media-spreadsheet"></span><span><strong>' . esc_html__( 'File', 'great-imports' ) . '</strong><small>' . esc_html__( 'ICS, CSV, or JSON', 'great-imports' ) . '</small></span></label>';
        echo '</div>';
        echo '<div class="gi-primary-source-field" data-gi-url-input><label for="gi-urls">' . esc_html__( 'Event links', 'great-imports' ) . '</label><textarea id="gi-urls" name="urls" rows="4" placeholder="https://example.com/events&#10;https://example.com/another-event"></textarea><small>' . esc_html__( 'For bulk import, put each link on its own line.', 'great-imports' ) . '</small></div>';
        echo '<div class="gi-primary-source-field" data-gi-file-input hidden><label for="gi-file">' . esc_html__( 'Event file', 'great-imports' ) . '</label><input type="file" id="gi-file" name="event_file" accept=".ics,.ical,.csv,.json,text/calendar,text/csv,application/json"><small>' . esc_html__( 'Supported files: ICS, CSV, and JSON.', 'great-imports' ) . '</small></div>';
        echo '<div class="gi-intake-options">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_options() returns fixed option markup with escaped attributes and labels.
        echo '<label><span>' . esc_html__( 'After events are found', 'great-imports' ) . '</span><select name="run_action">' . $this->action_options( 'review' ) . '</select></label>';
        echo '<label><span>' . esc_html__( 'Location', 'great-imports' ) . '</span><select name="location_policy" data-gi-source-location-mode><option value="auto_create">' . esc_html__( 'Use the location found with each event', 'great-imports' ) . '</option><option value="one_location">' . esc_html__( 'Use one location for every event', 'great-imports' ) . '</option></select></label>';
        echo '<label class="gi-intake-forced-location" data-gi-single-location hidden><span>' . esc_html__( 'Use this location', 'great-imports' ) . '</span><select name="forced_em_location_id"><option value="0">' . esc_html__( 'Choose a location', 'great-imports' ) . '</option>';
        foreach ( $locations as $location ) {
            $label = trim( (string) ( $location['location_name'] ?? '' ) . ( ! empty( $location['location_address'] ) ? ' — ' . $location['location_address'] : '' ) );
            echo '<option value="' . esc_attr( (string) absint( $location['location_id'] ?? 0 ) ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></label></div>';
        echo '<footer class="gi-start-actions gi-intake-actions"><button type="submit" name="intake_action" value="run_once" class="button button-primary button-hero"><span class="dashicons dashicons-controls-play"></span><span><strong>' . esc_html__( 'Run once', 'great-imports' ) . '</strong><small>' . esc_html__( 'Find these events now.', 'great-imports' ) . '</small></span></button><div class="gi-recurring-start" data-gi-recurring-wrap><label><span>' . esc_html__( 'Repeat', 'great-imports' ) . '</span><select name="recurring_cadence"><option value="hourly">' . esc_html__( 'Every hour', 'great-imports' ) . '</option><option value="daily" selected>' . esc_html__( 'Every day', 'great-imports' ) . '</option><option value="weekly">' . esc_html__( 'Every week', 'great-imports' ) . '</option><option value="monthly">' . esc_html__( 'Every month', 'great-imports' ) . '</option></select></label><button type="submit" name="intake_action" value="save_recurring" class="button button-hero"><span class="dashicons dashicons-update"></span><span><strong>' . esc_html__( 'Save recurring', 'great-imports' ) . '</strong><small>' . esc_html__( 'Find events now and keep checking.', 'great-imports' ) . '</small></span></button></div></footer>';
        echo '</form></div></section>';
    }

    private function render_review(): void {
        $source_id = absint( $_GET['source_id'] ?? 0 );
        if ( ! $source_id ) {
            $sources = GI_Storage::list_sources();
            $source_id = absint( $sources[0]['id'] ?? 0 );
        }
        if ( $source_id ) {
            $_GET['source_id'] = $source_id;
            $source = GI_Storage::get_source( $source_id );
            $this->render_sources( ! empty( $source['is_saved'] ) );
            return;
        }
        echo '<section class="gi-first-empty"><span class="dashicons dashicons-rss"></span><h3>' . esc_html__( 'No sources to review', 'great-imports' ) . '</h3><p>' . esc_html__( 'Run an import first.', 'great-imports' ) . '</p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=great-imports&tab=new' ) ) . '">' . esc_html__( 'Add source', 'great-imports' ) . '</a></section>';
    }

    private function render_candidate_card( array $candidate, string $surface = 'active' ): void {
        $id = absint( $candidate['id'] ?? 0 );
        $status = sanitize_key( $candidate['status'] ?? 'held' );
        $is_completed = 'completed' === $surface || in_array( $status, array( 'imported', 'updated', 'ignored' ), true );
        $source = GI_Storage::get_source( absint( $candidate['source_id'] ?? 0 ) );
        $locations = GI_Events_Manager::list_locations();
        $matches = GI_Events_Manager::matching_locations( $candidate );
        $matched_location_id = absint( $candidate['em_location_id'] ?? 0 );
        $recommended_location_id = ! $matched_location_id && 1 === count( $matches )
            ? absint( $matches[0]['location_id'] ?? 0 )
            : 0;
        $image = GI_Utils::clean_url( $candidate['image_url'] ?? '' );
        $needs_fix = in_array( $status, array( 'held', 'failed' ), true ) || count( $matches ) > 1;
        $detected_location_name = trim( (string) ( $candidate['location_name'] ?? '' ) );
        $detected_label = GI_Utils::public_location_name( $candidate );
        $display_location = $candidate;
        if ( $matched_location_id ) {
            foreach ( $locations as $location ) {
                if ( $matched_location_id === absint( $location['location_id'] ?? 0 ) ) {
                    $display_location = array_merge( $candidate, $location );
                    break;
                }
            }
        }
        $display_address = $this->format_location_address( $display_location );

        echo '<article class="gi-candidate gi-status-' . esc_attr( $status ) . '" data-gi-candidate-card data-gi-status="' . esc_attr( $status ) . '">';
        echo '<div class="gi-candidate-summary">';
        if ( $is_completed ) {
            echo '<span class="gi-select is-disabled" aria-hidden="true"></span>';
        } else {
            echo '<label class="gi-select"><span class="screen-reader-text">' . esc_html__( 'Select event for bulk action', 'great-imports' ) . '</span><input type="checkbox" value="' . esc_attr( $id ) . '" data-gi-candidate-checkbox data-gi-candidate-id="' . esc_attr( $id ) . '"></label>';
        }
        echo $image ? '<div class="gi-candidate-thumb"><img src="' . esc_url( $image ) . '" alt=""></div>' : '<div class="gi-candidate-thumb is-empty"><span class="dashicons dashicons-format-image"></span></div>';
        echo '<div class="gi-candidate-main"><div class="gi-title-line"><h3>' . esc_html( $candidate['title'] ?: __( 'Untitled event', 'great-imports' ) ) . '</h3>';
        if ( $needs_fix || $is_completed ) {
            echo '<span class="gi-badge gi-badge-' . esc_attr( $status ) . '">' . esc_html( GI_Utils::status_label( $status ) ) . '</span>';
        }
        echo '</div><div class="gi-candidate-meta"><span><span class="dashicons dashicons-calendar"></span>' . esc_html( $this->candidate_date_label( $candidate ) ) . '</span><span><span class="dashicons dashicons-location"></span>' . esc_html( $detected_label ?: __( 'Place not found', 'great-imports' ) ) . '</span>';
        if ( $needs_fix ) {
            echo '<span class="gi-location-state-badge ' . ( $matched_location_id ? 'is-existing' : ( $recommended_location_id ? 'is-suggested' : 'is-new' ) ) . '">' . esc_html( $matched_location_id ? __( 'Already on your website', 'great-imports' ) : ( $recommended_location_id ? __( 'Possible match — please check', 'great-imports' ) : __( 'New place — added with the event', 'great-imports' ) ) ) . '</span>';
            if ( $display_address ) {
                echo '<span class="gi-candidate-address"><span class="dashicons dashicons-admin-home"></span>' . esc_html( $display_address ) . '</span>';
            } else {
                echo '<span class="gi-candidate-address is-missing"><span class="dashicons dashicons-warning"></span>' . esc_html__( 'Address not available — edit or choose another location', 'great-imports' ) . '</span>';
            }
        }
        echo '</div>';
        $reasons = array_values( array_unique( array_filter( (array) ( $candidate['hold_reasons'] ?? array() ) ) ) );
        if ( $reasons ) {
            echo '<div class="gi-reason"><span class="dashicons dashicons-warning"></span><span>' . esc_html( implode( ' ', $reasons ) ) . '</span></div>';
        }
        $toggle_label = $is_completed ? __( 'View details', 'great-imports' ) : ( $needs_fix ? __( 'Fix event', 'great-imports' ) : __( 'Edit', 'great-imports' ) );
        echo '</div><div class="gi-row-actions"><button type="button" class="button ' . ( $needs_fix && ! $is_completed ? 'button-primary' : '' ) . '" data-gi-toggle-card aria-expanded="false">' . esc_html( $toggle_label ) . '</button></div></div>';

        echo '<div class="gi-candidate-detail" data-gi-card-detail hidden><div class="gi-drawer-backdrop" data-gi-close-card></div><section class="gi-candidate-drawer" role="dialog" aria-modal="true" aria-labelledby="gi-candidate-title-' . esc_attr( $id ) . '"><header><div><span class="gi-eyebrow">' . esc_html( $source['name'] ?? __( 'Source event', 'great-imports' ) ) . '</span><h3 id="gi-candidate-title-' . esc_attr( $id ) . '">' . esc_html( $candidate['title'] ?: __( 'Edit event', 'great-imports' ) ) . '</h3></div><button type="button" class="gi-drawer-close" data-gi-close-card aria-label="' . esc_attr__( 'Close editor', 'great-imports' ) . '">×</button></header>';
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="gi-candidate-form">';
        wp_nonce_field( 'gi_candidate_action_' . $id );
        echo '<input type="hidden" name="action" value="gi_candidate_action"><input type="hidden" name="candidate_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="source_id" value="' . esc_attr( (string) absint( $candidate['source_id'] ?? 0 ) ) . '">';
        if ( $is_completed ) {
            echo '<div class="gi-completed-readonly"><span class="dashicons dashicons-lock"></span><span><strong>' . esc_html__( 'This event is finished', 'great-imports' ) . '</strong><small>' . esc_html__( 'Move it back to review if you need to change it.', 'great-imports' ) . '</small></span></div><fieldset class="gi-completed-fields" disabled>';
        }
        echo '<div class="gi-edit-grid">';
        $this->text_field( 'title', __( 'Event title', 'great-imports' ), $candidate['title'] ?? '', 'wide' );
        $this->text_field( 'start_date', __( 'Start date', 'great-imports' ), $candidate['start_date'] ?? '', '', 'date' );
        $this->text_field( 'start_time', __( 'Start time', 'great-imports' ), substr( (string) ( $candidate['start_time'] ?? '' ), 0, 5 ), '', 'time' );
        $this->text_field( 'end_date', __( 'End date', 'great-imports' ), $candidate['end_date'] ?? '', '', 'date' );
        $this->text_field( 'end_time', __( 'End time', 'great-imports' ), substr( (string) ( $candidate['end_time'] ?? '' ), 0, 5 ), '', 'time' );
        echo '<label class="gi-checkbox" for="gi-all-day-' . esc_attr( $id ) . '"><input id="gi-all-day-' . esc_attr( $id ) . '" type="checkbox" name="all_day" value="1" data-gi-all-day ' . checked( ! empty( $candidate['all_day'] ), true, false ) . '><span><strong>' . esc_html__( 'All-day event', 'great-imports' ) . '</strong></span></label>';
        $this->text_field( 'timezone', __( 'Timezone', 'great-imports' ), $candidate['timezone'] ?? wp_timezone_string(), 'wide' );
        echo '</div>';

        $structure = 'festival' === sanitize_key( $candidate['structure'] ?? '' ) || ! empty( $candidate['festival_slots'] ) ? 'festival' : 'auto';
        echo '<section class="gi-event-setup"><label class="gi-field gi-field-wide"><span>' . esc_html__( 'Event setup', 'great-imports' ) . '</span><select name="structure" data-gi-event-structure>';
        echo '<option value="auto" ' . selected( $structure, 'auto', false ) . '>' . esc_html__( 'Standard event', 'great-imports' ) . '</option>';
        echo '<option value="festival" ' . selected( $structure, 'festival', false ) . '>' . esc_html__( 'Festival with a schedule', 'great-imports' ) . '</option>';
        echo '</select><small>' . esc_html__( 'Choose Festival only when one event has different days, stages, or places.', 'great-imports' ) . '</small></label>';
        echo '<div class="gi-festival-editor" data-gi-festival-editor ' . ( 'festival' === $structure ? '' : 'hidden' ) . '>';
        echo '<label class="gi-checkbox gi-festival-annual"><input type="checkbox" name="festival_annual" value="1" ' . checked( ! empty( $candidate['festival_annual'] ), true, false ) . '><span><strong>' . esc_html__( 'This festival returns every year', 'great-imports' ) . '</strong><small>' . esc_html__( 'Each year keeps its own dates and lineup so a new edition does not overwrite an older one.', 'great-imports' ) . '</small></span></label>';
        echo '<div class="gi-festival-heading"><div><h4>' . esc_html__( 'Festival schedule', 'great-imports' ) . '</h4><p>' . esc_html__( 'Use one row for each performance or activity. Rows may share a time when different stages run at once.', 'great-imports' ) . '</p></div><button type="button" class="button" data-gi-add-festival-slot>' . esc_html__( 'Add time slot', 'great-imports' ) . '</button></div>';
        echo '<div class="gi-festival-slots" data-gi-festival-slots>';
        $festival_slots = (array) ( $candidate['festival_slots'] ?? array() );
        if ( ! $festival_slots ) {
            $festival_slots[] = array( 'date' => $candidate['start_date'] ?? '', 'start_time' => $candidate['start_time'] ?? '', 'end_date' => $candidate['end_date'] ?? '', 'end_time' => $candidate['end_time'] ?? '' );
        }
        foreach ( $festival_slots as $slot ) {
            $this->festival_slot_row( (array) $slot );
        }
        echo '</div><template data-gi-festival-slot-template>';
        $this->festival_slot_row( array() );
        echo '</template></div></section>';

        $detected_address = $this->format_location_address( $candidate );
        $public_location_name = $detected_label ?: __( 'Location not resolved', 'great-imports' );
        $canonical_venue_name = trim( (string) ( $candidate['parent_location_name'] ?? $detected_location_name ) );
        $has_stage = GI_Utils::has_meaningful_value( $candidate['stage_name'] ?? '' ) && GI_Utils::has_meaningful_value( $candidate['parent_location_name'] ?? '' );
        $source_is_saved = ! empty( $source['is_saved'] );

        echo '<section class="gi-location-decision"><div class="gi-location-heading"><div><h4>' . esc_html__( 'Where is it?', 'great-imports' ) . '</h4><p>' . esc_html__( 'We found this place. Change it only if it is wrong.', 'great-imports' ) . '</p></div></div>';
        echo '<label class="gi-field gi-field-wide gi-location-select-field"><span>' . esc_html__( 'Place to use', 'great-imports' ) . '</span><select class="gi-location-select" name="location_selection" data-gi-location-selection>';

        if ( $has_stage ) {
            /* translators: 1: detected Events Manager venue name, 2: public location or stage name. */
            $detected_option_text = sprintf( __( 'Use %1$s — show event at %2$s', 'great-imports' ), $canonical_venue_name ?: $public_location_name, $public_location_name );
        } else {
            /* translators: %s: public location name found on the event page. */
            $detected_option_text = sprintf( __( 'Use %s (found on the event page)', 'great-imports' ), $public_location_name );
        }
        echo '<option value="detected" data-kind="new" data-name="' . esc_attr( $public_location_name ) . '" data-em-name="' . esc_attr( $canonical_venue_name ?: $public_location_name ) . '" data-address="' . esc_attr( $detected_address ) . '" data-note="' . esc_attr__( 'This place will be added directly to Events Manager when the event is added.', 'great-imports' ) . '" ' . selected( $matched_location_id, 0, false ) . '>' . esc_html( $detected_option_text ) . '</option>';

        if ( $locations ) {
            echo '<optgroup label="' . esc_attr__( 'Places already on my website', 'great-imports' ) . '">';
            foreach ( $locations as $location ) {
                $location_id = absint( $location['location_id'] ?? 0 );
                $location_name = trim( (string) ( $location['location_name'] ?? '' ) );
                $location_address = $this->format_location_address( $location );
                $label = trim( $location_name . ( $location_address ? ' — ' . $location_address : '' ) );
                if ( ! $matched_location_id && $recommended_location_id === $location_id ) {
                    /* translators: %s: suggested existing location label. */
                    $label = sprintf( __( 'Suggested: %s', 'great-imports' ), $label );
                }
                $public_for_option = $has_stage && 0 === strcasecmp( GI_Utils::normalize_text( $canonical_venue_name ), GI_Utils::normalize_text( $location_name ) ) ? $public_location_name : $location_name;
                if ( $has_stage && $public_for_option !== $location_name ) {
                    /* translators: 1: Events Manager venue name, 2: public display location or stage name. */
                    $note = sprintf( __( 'Events Manager venue: %1$s. Public display: %2$s.', 'great-imports' ), $location_name, $public_for_option );
                } else {
                    $note = __( 'This place is already on your website.', 'great-imports' );
                }
                echo '<option value="existing:' . esc_attr( (string) $location_id ) . '" data-kind="existing" data-name="' . esc_attr( $public_for_option ) . '" data-em-name="' . esc_attr( $location_name ) . '" data-address="' . esc_attr( $location_address ) . '" data-note="' . esc_attr( $note ) . '" ' . selected( $matched_location_id, $location_id, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select><small>' . esc_html__( 'Leave this alone when the place shown is correct.', 'great-imports' ) . '</small></label>';

        $preview_name = $public_location_name;
        $preview_address = $detected_address;
        $preview_em_name = $canonical_venue_name ?: $public_location_name;
        $preview_kind = 'new';
        $preview_note = __( 'This new place will be added directly to Events Manager with the event.', 'great-imports' );
        if ( $matched_location_id ) {
            foreach ( $locations as $location ) {
                if ( $matched_location_id !== absint( $location['location_id'] ?? 0 ) ) { continue; }
                $preview_em_name = trim( (string) ( $location['location_name'] ?? $preview_em_name ) );
                $preview_address = $this->format_location_address( $location );
                $preview_kind = 'existing';
                if ( $has_stage ) {
                    /* translators: 1: Events Manager venue name, 2: public display location or stage name. */
                    $preview_note = sprintf( __( 'Events Manager venue: %1$s. Public display: %2$s.', 'great-imports' ), $preview_em_name, $public_location_name );
                } else {
                    $preview_note = __( 'This place is already on your website.', 'great-imports' );
                }
                break;
            }
        }

        echo '<div class="gi-location-preview is-' . esc_attr( $preview_kind ) . ( $preview_address ? '' : ' is-missing' ) . '" data-gi-location-preview><span class="dashicons ' . ( $preview_address ? 'dashicons-location-alt' : 'dashicons-warning' ) . '"></span><span><span class="gi-location-preview-state" data-gi-location-preview-state>' . esc_html( 'existing' === $preview_kind ? __( 'Already on your website', 'great-imports' ) : __( 'New place — added with the event', 'great-imports' ) ) . '</span><strong data-gi-location-preview-name>' . esc_html( $preview_name ) . '</strong>';
        if ( 'existing' === $preview_kind || $has_stage ) {
            /* translators: %s: location name saved in Events Manager. */
            echo '<small data-gi-location-preview-em-name>' . esc_html( sprintf( __( 'Saved place: %s', 'great-imports' ), $preview_em_name ) ) . '</small>';
        } else {
            echo '<small data-gi-location-preview-em-name hidden></small>';
        }
        echo '<small data-gi-location-preview-address>' . esc_html( $preview_address ?: __( 'Address not available — edit the detected details or choose another location.', 'great-imports' ) ) . '</small><small data-gi-location-preview-note>' . esc_html( $preview_note ) . '</small></span></div>';

        echo '<details class="gi-custom-location" data-gi-location-details><summary>' . esc_html__( 'Check or change the address', 'great-imports' ) . '</summary><div class="gi-edit-grid">';
        $this->text_field( 'location_name', __( 'Location name', 'great-imports' ), $candidate['location_name'] ?? '', 'wide' );
        $this->text_field( 'location_address', __( 'Street address', 'great-imports' ), $candidate['location_address'] ?? '', 'wide' );
        $this->text_field( 'location_city', __( 'City', 'great-imports' ), $candidate['location_city'] ?? '' );
        $this->text_field( 'location_state', __( 'State', 'great-imports' ), $candidate['location_state'] ?? '' );
        $this->text_field( 'location_postcode', __( 'ZIP/postcode', 'great-imports' ), $candidate['location_postcode'] ?? '' );
        $this->text_field( 'location_country', __( 'Country', 'great-imports' ), $candidate['location_country'] ?? '' );
        echo '</div><details class="gi-location-structure"><summary>' . esc_html__( 'Stage, room, or festival area', 'great-imports' ) . '</summary><div class="gi-edit-grid">';
        $this->text_field( 'parent_location_name', __( 'Parent venue or festival site', 'great-imports' ), $candidate['parent_location_name'] ?? '', 'wide' );
        $this->text_field( 'stage_name', __( 'Stage, room, or area', 'great-imports' ), $candidate['stage_name'] ?? '', 'wide' );
        echo '</div></details></details>';

        if ( $source_is_saved ) {
            /* translators: %s: detected public location name. */
            echo '<label class="gi-checkbox gi-source-rule-choice"><input type="checkbox" name="apply_location_rule" value="1"><span><strong>' . esc_html( sprintf( __( 'Always use this choice for “%s”', 'great-imports' ), $public_location_name ) ) . '</strong><small>' . esc_html__( 'Turn this on only when this link keeps using the wrong place name.', 'great-imports' ) . '</small></span></label>';
        }
        echo '</section>';

        echo '<details class="gi-more-fields" data-gi-recurrence-section><summary>' . esc_html__( 'Does this event repeat?', 'great-imports' ) . '</summary><div class="gi-edit-grid">';
        echo '<div class="gi-field gi-field-wide"><label>' . esc_html__( 'Event type', 'great-imports' ) . '</label><select name="recurrence_mode" data-gi-recurrence-mode><option value="single" ' . selected( $candidate['recurrence_mode'] ?? 'single', 'single', false ) . '>' . esc_html__( 'Single event', 'great-imports' ) . '</option><option value="series" ' . selected( $candidate['recurrence_mode'] ?? 'single', 'series', false ) . '>' . esc_html__( 'Repeating series', 'great-imports' ) . '</option></select></div>';
        echo '<div data-gi-recurrence-fields ' . ( 'series' === ( $candidate['recurrence_mode'] ?? 'single' ) ? '' : 'hidden' ) . ' class="gi-field gi-field-wide"><div class="gi-edit-grid">';
        echo '<div class="gi-field"><label>' . esc_html__( 'Repeats', 'great-imports' ) . '</label><select name="recurrence_frequency" data-gi-recurrence-frequency><option value="daily" ' . selected( $candidate['recurrence_frequency'] ?? 'weekly', 'daily', false ) . '>' . esc_html__( 'Daily', 'great-imports' ) . '</option><option value="weekly" ' . selected( $candidate['recurrence_frequency'] ?? 'weekly', 'weekly', false ) . '>' . esc_html__( 'Weekly', 'great-imports' ) . '</option><option value="monthly" ' . selected( $candidate['recurrence_frequency'] ?? 'weekly', 'monthly', false ) . '>' . esc_html__( 'Monthly', 'great-imports' ) . '</option></select></div>';
        $this->text_field( 'recurrence_interval', __( 'Repeat every', 'great-imports' ), (string) ( $candidate['recurrence_interval'] ?? 1 ), '', 'number' );
        $this->text_field( 'recurrence_count', __( 'Occurrences', 'great-imports' ), (string) ( $candidate['recurrence_count'] ?? 0 ), '', 'number' );
        $this->text_field( 'recurrence_until', __( 'Or end on', 'great-imports' ), (string) ( $candidate['recurrence_until'] ?? '' ), '', 'date' );
        $selected_days = (array) ( $candidate['recurrence_weekdays'] ?? array() );
        echo '<fieldset class="gi-field gi-field-wide gi-weekday-picker" data-gi-recurrence-weekdays><legend>' . esc_html__( 'Repeat on', 'great-imports' ) . '</legend><div class="gi-day-options">';
        foreach ( array( 'SU' => __( 'Sun', 'great-imports' ), 'MO' => __( 'Mon', 'great-imports' ), 'TU' => __( 'Tue', 'great-imports' ), 'WE' => __( 'Wed', 'great-imports' ), 'TH' => __( 'Thu', 'great-imports' ), 'FR' => __( 'Fri', 'great-imports' ), 'SA' => __( 'Sat', 'great-imports' ) ) as $day => $label ) {
            echo '<label><input type="checkbox" name="recurrence_weekdays[]" value="' . esc_attr( $day ) . '" ' . checked( in_array( $day, $selected_days, true ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
        }
        echo '</div></fieldset></div></div></div></details>';

        echo '<details class="gi-more-fields"><summary>' . esc_html__( 'Extra event details', 'great-imports' ) . '</summary><div class="gi-edit-grid">';
        $this->text_field( 'event_url', __( 'Event URL', 'great-imports' ), $candidate['event_url'] ?? '', 'wide', 'url' );
        $this->text_field( 'ticket_url', __( 'Ticket URL', 'great-imports' ), $candidate['ticket_url'] ?? '', 'wide', 'url' );
        $this->text_field( 'price', __( 'Price', 'great-imports' ), $candidate['price'] ?? '' );
        $this->text_field( 'currency', __( 'Currency', 'great-imports' ), $candidate['currency'] ?? '' );
        $this->text_field( 'image_url', __( 'Image URL', 'great-imports' ), $candidate['image_url'] ?? '', 'wide', 'url' );
        $this->text_field( 'organizer', __( 'Organizer or contact', 'great-imports' ), $candidate['organizer'] ?? '', 'wide' );
        $this->text_field( 'categories', __( 'Categories', 'great-imports' ), implode( ', ', (array) ( $candidate['categories'] ?? array() ) ), 'wide' );
        $this->text_field( 'tags', __( 'Tags', 'great-imports' ), implode( ', ', (array) ( $candidate['tags'] ?? array() ) ), 'wide' );
        echo '<div class="gi-field gi-field-wide"><label>' . esc_html__( 'Description', 'great-imports' ) . '</label><textarea name="description" rows="8">' . esc_textarea( $candidate['description'] ?? '' ) . '</textarea></div></div></details>';
        $content_check = (array) ( $candidate['explicit_content'] ?? array() );
        if ( ! $is_completed && in_array( $content_check['decision'] ?? 'allow', array( 'review', 'block' ), true ) ) {
            echo '<section class="gi-content-review"><span class="dashicons dashicons-shield"></span><div><strong>' . esc_html__( 'Content safety check', 'great-imports' ) . '</strong><p>' . esc_html( $content_check['reason'] ?? __( 'Review this event before adding it.', 'great-imports' ) ) . '</p><label class="gi-checkbox"><input type="checkbox" name="explicit_content_approved" value="1" ' . checked( ! empty( $candidate['explicit_content_approved'] ), true, false ) . '><span><strong>' . esc_html__( 'I reviewed this event and want to allow it', 'great-imports' ) . '</strong><small>' . esc_html__( 'This approval applies only to this event.', 'great-imports' ) . '</small></span></label></div></section>';
        }
        if ( $is_completed ) {
            echo '</fieldset>';
            echo '<div class="gi-actions gi-candidate-actions gi-outcome-actions"><button type="submit" name="candidate_action" value="restore" class="button button-primary" data-gi-candidate-submit data-gi-candidate-action="restore" data-gi-quick-action="1"><span><strong>' . esc_html__( 'Review this again', 'great-imports' ) . '</strong><small>' . esc_html__( 'Move it back to your event list.', 'great-imports' ) . '</small></span></button></div>';
        } else {
            echo '<div class="gi-actions gi-candidate-actions gi-outcome-actions"><button type="submit" name="candidate_action" value="save" class="button" data-gi-candidate-submit data-gi-candidate-action="save"><span><strong>' . esc_html__( 'Save my changes', 'great-imports' ) . '</strong><small>' . esc_html__( 'Keep editing without adding it.', 'great-imports' ) . '</small></span></button><button type="submit" name="candidate_action" value="draft" class="button" data-gi-candidate-submit data-gi-candidate-action="draft"><span><strong>' . esc_html__( 'Add as a draft', 'great-imports' ) . '</strong><small>' . esc_html__( 'Add it, but keep it hidden.', 'great-imports' ) . '</small></span></button><button type="submit" name="candidate_action" value="publish" class="button button-primary" data-gi-candidate-submit data-gi-candidate-action="publish"><span><strong>' . esc_html__( 'Publish this event', 'great-imports' ) . '</strong><small>' . esc_html__( 'Add it and make it public.', 'great-imports' ) . '</small></span></button><button type="submit" name="candidate_action" value="ignore" class="button button-link-delete" data-gi-candidate-submit data-gi-candidate-action="ignore" data-gi-quick-action="1"><span><strong>' . esc_html__( 'Do not add this', 'great-imports' ) . '</strong><small>' . esc_html__( 'Remove it from your review list.', 'great-imports' ) . '</small></span></button></div>';
        }
        echo '</form></section></div></article>';
    }

    private function render_sources( bool $saved_only = false ): void {
        $sources = GI_Storage::list_sources();
        $sources = array_values( array_filter( $sources, static function ( array $source ) use ( $saved_only ): bool {
            return $saved_only === ! empty( $source['is_saved'] );
        } ) );
        if ( ! $sources ) {
            echo '<section class="gi-first-empty"><span class="dashicons ' . esc_attr( $saved_only ? 'dashicons-update' : 'dashicons-controls-play' ) . '"></span><h3>' . esc_html( $saved_only ? __( 'No recurring URLs', 'great-imports' ) : __( 'The run once queue is empty', 'great-imports' ) ) . '</h3><p>' . esc_html( $saved_only ? __( 'Choose Save recurring when you add a URL.', 'great-imports' ) : __( 'Choose Run once when you add links or a file.', 'great-imports' ) ) . '</p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=great-imports&tab=new' ) ) . '">' . esc_html__( 'Add events', 'great-imports' ) . '</a></section>';
            return;
        }
        echo '<div class="gi-url-organizer">';
        foreach ( $sources as $source ) {
            if ( empty( $source['id'] ) ) { continue; }
            echo '<section class="gi-url-unit" data-gi-url-unit="' . esc_attr( (string) absint( $source['id'] ) ) . '">';
            $this->render_source_workspace( $source );
            echo '</section>';
        }
        echo '<a class="button gi-add-url-button" href="' . esc_url( admin_url( 'admin.php?page=great-imports&tab=new' ) ) . '"><span class="dashicons dashicons-plus-alt2"></span>' . esc_html__( 'Add events', 'great-imports' ) . '</a></div>';
    }

    private function render_source_queue( array $sources, int $current_source_id ): void {
        /* translators: %d: number of available source links. */
        echo '<details class="gi-compact-source-picker"><summary>' . esc_html( sprintf( _n( 'Switch link (%d available)', 'Switch links (%d available)', count( $sources ), 'great-imports' ), count( $sources ) ) ) . '</summary><div class="gi-compact-source-list">';
        foreach ( $sources as $source ) {
            $source_id = absint( $source['id'] ?? 0 );
            if ( ! $source_id ) { continue; }
            $primary = $source['urls'][0] ?? $source['file_name'] ?? __( 'Stored event file', 'great-imports' );
            $host = wp_parse_url( (string) $primary, PHP_URL_HOST );
            $is_current = $source_id === $current_source_id;
            if ( $is_current ) {
                echo '<span class="gi-compact-source-link is-current" aria-current="true"><strong>' . esc_html( $source['name'] ?? __( 'Untitled link', 'great-imports' ) ) . '</strong><small>' . esc_html( $host ?: $primary ) . '</small><b>' . esc_html__( 'Open', 'great-imports' ) . '</b></span>';
            } else {
                echo '<a class="gi-compact-source-link" href="' . esc_url( admin_url( 'admin.php?page=great-imports&tab=' . ( ! empty( $source['is_saved'] ) ? 'recurring' : 'sources' ) . '&source_id=' . $source_id ) ) . '"><strong>' . esc_html( $source['name'] ?? __( 'Untitled link', 'great-imports' ) ) . '</strong><small>' . esc_html( $host ?: $primary ) . '</small><b>' . esc_html__( 'Review', 'great-imports' ) . '</b></a>';
            }
        }
        echo '</div></details>';
    }

    private function render_source_row( array $source, bool $saved ): void {
        $summary = (array) get_post_meta( (int) $source['id'], '_gi_last_run_summary', true );
        $last_run = sanitize_text_field( get_post_meta( (int) $source['id'], '_gi_last_run_at', true ) );
        $primary = $source['urls'][0] ?? $source['file_name'] ?? __( 'Stored event file', 'great-imports' );
        $host = wp_parse_url( (string) $primary, PHP_URL_HOST );
        $schedule = (array) ( $source['schedule'] ?? array() );
        $enabled = $saved && ! empty( $schedule['enabled'] );
        $schedule_label = __( 'Manual only', 'great-imports' );
        $next = '';

        if ( $saved ) {
            $cadence = sanitize_key( $schedule['cadence'] ?? 'daily' );
            $parts = array_map( 'intval', explode( ':', $schedule['time'] ?? '08:00' ) );
            $time_value = sprintf( '%02d:%02d', $parts[0] ?? 8, $parts[1] ?? 0 );
            $time_object = DateTimeImmutable::createFromFormat( '!H:i', $time_value, wp_timezone() );
            $time_label = $time_object ? $time_object->format( get_option( 'time_format', 'g:i A' ) ) : $time_value;
            if ( 'hourly' === $cadence ) {
                /* translators: %02d: minute within each hour. */
                $schedule_label = sprintf( __( 'Hourly at :%02d', 'great-imports' ), $parts[1] ?? 0 );
            } elseif ( 'weekly' === $cadence ) {
                $days = array( __( 'Sunday', 'great-imports' ), __( 'Monday', 'great-imports' ), __( 'Tuesday', 'great-imports' ), __( 'Wednesday', 'great-imports' ), __( 'Thursday', 'great-imports' ), __( 'Friday', 'great-imports' ), __( 'Saturday', 'great-imports' ) );
                /* translators: 1: weekday name, 2: scheduled time. */
                $schedule_label = sprintf( __( '%1$s at %2$s', 'great-imports' ), $days[ (int) ( $schedule['weekday'] ?? 1 ) ], $time_label );
            } elseif ( 'monthly' === $cadence ) {
                /* translators: 1: day of month, 2: scheduled time. */
                $schedule_label = sprintf( __( 'Monthly, day %1$d at %2$s', 'great-imports' ), (int) ( $schedule['monthday'] ?? 1 ), $time_label );
            } else {
                /* translators: %s: scheduled daily time. */
                $schedule_label = sprintf( __( 'Daily at %s', 'great-imports' ), $time_label );
            }
            $next = $enabled && ! empty( $schedule['next_run_gmt'] ) ? get_date_from_gmt( $schedule['next_run_gmt'], 'M j, g:i A' ) : __( 'Not scheduled', 'great-imports' );
        }

        $processed = (int) ( $summary['imported'] ?? 0 ) + (int) ( $summary['updated'] ?? 0 );
        /* translators: 1: events found, 2: events imported or updated, 3: events requiring attention. */
        $last_result = $last_run ? sprintf( __( '%1$d found; %2$d imported/updated; %3$d need attention', 'great-imports' ), (int) ( $summary['collected'] ?? 0 ), $processed, (int) ( $summary['held'] ?? 0 ) ) : __( 'Not run yet', 'great-imports' );
        $status_label = $saved ? ( $enabled ? __( 'Enabled', 'great-imports' ) : __( 'Paused', 'great-imports' ) ) : __( 'One-time', 'great-imports' );

        echo '<tr data-gi-source-card><td data-label="' . esc_attr__( 'Source', 'great-imports' ) . '"><strong>' . esc_html( $source['name'] ) . '</strong><small>' . esc_html( $host ?: $primary ) . '</small></td><td data-label="' . esc_attr( $saved ? __( 'Automatic action', 'great-imports' ) : __( 'Run action', 'great-imports' ) ) . '">' . esc_html( $this->action_label( $source['action'] ) ) . '</td>';
        if ( $saved ) {
            echo '<td data-label="' . esc_attr__( 'Schedule', 'great-imports' ) . '">' . esc_html( $schedule_label ) . '</td><td data-label="' . esc_attr__( 'Status', 'great-imports' ) . '"><span class="gi-source-status ' . ( $enabled ? 'is-running' : 'is-paused' ) . '">' . esc_html( $status_label ) . '</span></td>';
        }
        echo '<td data-label="' . esc_attr__( 'Last result', 'great-imports' ) . '"><span>' . esc_html( $last_result ) . '</span>' . ( $last_run ? '<small>' . esc_html( $last_run ) . '</small>' : '' ) . '</td>';
        if ( $saved ) {
            echo '<td data-label="' . esc_attr__( 'Next run', 'great-imports' ) . '">' . esc_html( $next ) . '</td>';
        }
        echo '<td data-label="' . esc_attr__( 'Actions', 'great-imports' ) . '"><div class="gi-source-row-actions">';
        $this->run_source_button( (int) $source['id'] );
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=great-imports&tab=' . ( $saved ? 'recurring' : 'sources' ) . '&source_id=' . (int) $source['id'] ) ) . '">' . esc_html__( 'Edit', 'great-imports' ) . '</a>';
        if ( $saved ) {
            echo '<form class="gi-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
            wp_nonce_field( 'gi_toggle_source_' . (int) $source['id'] );
            echo '<input type="hidden" name="action" value="gi_toggle_source"><input type="hidden" name="source_id" value="' . esc_attr( (int) $source['id'] ) . '"><button class="button">' . esc_html( $enabled ? __( 'Pause', 'great-imports' ) : __( 'Resume', 'great-imports' ) ) . '</button></form>';
        }
        echo '<details class="gi-inline-delete-confirm"><summary class="button button-link-delete">' . esc_html__( 'Delete…', 'great-imports' ) . '</summary><div><p>' . esc_html__( 'Remove this import and its found events?', 'great-imports' ) . '</p><form class="gi-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        wp_nonce_field( 'gi_delete_source_' . (int) $source['id'] );
        echo '<input type="hidden" name="action" value="gi_delete_source"><input type="hidden" name="source_id" value="' . esc_attr( (int) $source['id'] ) . '"><button class="button button-link-delete">' . esc_html__( 'Delete source', 'great-imports' ) . '</button></form></div></details></div></td></tr>';
    }

    private function detected_source_label( array $source ): string {
        if ( 'file' === ( $source['source_type'] ?? '' ) ) {
            $extension = strtolower( pathinfo( (string) ( $source['file_name'] ?? '' ), PATHINFO_EXTENSION ) );
            if ( in_array( $extension, array( 'ics', 'ical' ), true ) ) { return __( 'Calendar file', 'great-imports' ); }
            if ( 'csv' === $extension ) { return __( 'CSV event file', 'great-imports' ); }
            if ( 'json' === $extension ) { return __( 'JSON event file', 'great-imports' ); }
            return __( 'Event file', 'great-imports' );
        }
        $url = GI_Utils::clean_url( (string) ( $source['urls'][0] ?? '' ) );
        $parts = $url ? wp_parse_url( $url ) : array();
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        $path = '/' . ltrim( strtolower( (string) ( $parts['path'] ?? '' ) ), '/' );

        if ( str_contains( $host, 'eventbrite.' ) ) {
            if ( preg_match( '#^/(e|event)/#', $path ) ) { return __( 'Eventbrite event page', 'great-imports' ); }
            if ( preg_match( '#^/o/#', $path ) ) { return __( 'Eventbrite organizer page', 'great-imports' ); }
            if ( preg_match( '#^/cc/#', $path ) ) { return __( 'Eventbrite event collection', 'great-imports' ); }
            return __( 'Eventbrite page', 'great-imports' );
        }
        if ( str_contains( $host, 'ticketmaster.' ) ) {
            return __( 'Ticketmaster page', 'great-imports' );
        }
        if ( preg_match( '#\.(ics|ical)(?:$|[?#])#', $url ) ) { return __( 'Calendar feed', 'great-imports' ); }
        if ( preg_match( '#\.json(?:$|[?#])#', $url ) ) { return __( 'JSON event feed', 'great-imports' ); }
        if ( preg_match( '#/(calendar|calendars)(?:/|$)#', $path ) ) { return __( 'Public calendar page', 'great-imports' ); }
        if ( preg_match( '#/(events?|shows?|tickets?|concerts?|gigs?)(?:/|$)#', $path ) ) { return __( 'Public events page', 'great-imports' ); }
        return __( 'Public website page', 'great-imports' );
    }

    private function render_source_workspace( array $source ): void {
        $locations = GI_Events_Manager::list_locations();
        $taxonomy = GI_Events_Manager::category_taxonomy();
        $terms = taxonomy_exists( $taxonomy ) ? get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) : array();
        $rules = (array) ( $source['rules'] ?? array() );
        $schedule = (array) ( $source['schedule'] ?? array() );
        $source_id = absint( $source['id'] ?? 0 );
        $active = GI_Storage::list_candidates( array( 'ready', 'held', 'failed' ), $source_id, -1 );
        $completed = GI_Storage::list_candidates( array( 'imported', 'updated', 'ignored' ), $source_id, -1 );
        $sort_by_date = static function ( array &$items ): void {
            usort( $items, static fn( array $a, array $b ): int => strcmp( trim( (string) ( $a['start_date'] ?? '' ) . ' ' . (string) ( $a['start_time'] ?? '' ) ), trim( (string) ( $b['start_date'] ?? '' ) . ' ' . (string) ( $b['start_time'] ?? '' ) ) ) );
        };
        $sort_by_date( $active );
        $sort_by_date( $completed );
        $page_number = max( 1, absint( $_GET['gi_paged'] ?? 1 ) );
        $completed_page_number = max( 1, absint( $_GET['gi_completed_paged'] ?? 1 ) );
        $per_page = 25;
        $total_pages = max( 1, (int) ceil( count( $active ) / $per_page ) );
        $page_number = min( $page_number, $total_pages );
        $active_page = array_slice( $active, ( $page_number - 1 ) * $per_page, $per_page );
        $completed_total_pages = max( 1, (int) ceil( count( $completed ) / $per_page ) );
        $completed_page_number = min( $completed_page_number, $completed_total_pages );
        $completed_page = array_slice( $completed, ( $completed_page_number - 1 ) * $per_page, $per_page );
        $counts = array( 'ready' => 0, 'held' => 0, 'failed' => 0, 'completed' => count( $completed ) );
        foreach ( $active as $candidate ) {
            $status = sanitize_key( $candidate['status'] ?? 'held' );
            if ( isset( $counts[ $status ] ) ) { ++$counts[ $status ]; }
        }
        $primary = $source['urls'][0] ?? $source['file_name'] ?? '';
        $saved = ! empty( $source['is_saved'] );
        $enabled = $saved && ! empty( $schedule['enabled'] );
        $last_run = sanitize_text_field( get_post_meta( $source_id, '_gi_last_run_at', true ) );
        $has_results = (bool) $last_run || ! empty( $active ) || ! empty( $completed );
        $status_label = $saved ? ( $enabled ? __( 'Saved · automatic checks on', 'great-imports' ) : __( 'Saved link', 'great-imports' ) ) : __( 'One-time link', 'great-imports' );
        if ( ! $has_results ) { $status_label .= ' · ' . __( 'Not checked yet', 'great-imports' ); }
        $detected_source_label = $this->detected_source_label( $source );

        echo '<section class="gi-source-workspace-head gi-rebuild-command-center gi-condensed-source-head gi-url-control-card"><div class="gi-source-identity"><h2 class="gi-url-heading">' . esc_html( $primary ) . '</h2><div class="gi-url-state"><span class="gi-source-status ' . ( $saved ? ( $enabled ? 'is-running' : 'is-paused' ) : 'is-once' ) . '">' . esc_html( $status_label ) . '</span><span>' . esc_html( $detected_source_label ) . '</span></div>';
        if ( $saved ) {
            echo '<div class="gi-source-control-dock"><details class="gi-source-settings-dropdown" data-gi-source-settings-dropdown><summary class="button">' . esc_html__( 'Recurring filters and options', 'great-imports' ) . '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="gi-source-settings-dropdown-panel" data-gi-source-settings-panel data-gi-panel-max-width="760">';
            $this->render_source_settings_form( $source, $terms, $locations, false, true );
            echo '</div></details>';
            echo '<details class="gi-source-settings-dropdown gi-source-schedule-dropdown" data-gi-source-settings-dropdown><summary class="button">' . esc_html__( 'Automatic schedule', 'great-imports' ) . '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="gi-source-settings-dropdown-panel gi-source-schedule-dropdown-panel" data-gi-source-settings-panel data-gi-panel-max-width="560">';
            $this->render_source_schedule( $source );
            echo '</div></details>';
            $this->run_source_button( $source_id );
            echo '</div>';
        }
        echo '</div></section>';

        if ( $has_results ) {
            $attention_count = $counts['held'] + $counts['failed'];
            /* translators: %d: number of events in this source queue or requiring edits. */
            echo '<details class="gi-workspace-section is-events gi-rebuild-candidates gi-condensed-candidates gi-url-queue" id="gi-source-events-' . esc_attr( (string) $source_id ) . '" ' . ( $active ? 'open' : '' ) . '><summary><strong>' . esc_html( sprintf( _n( '%d event in this queue', '%d events in this queue', count( $active ), 'great-imports' ), count( $active ) ) ) . '</strong>' . ( $attention_count ? '<span>' . esc_html( sprintf( __( '%d need editing', 'great-imports' ), $attention_count ) ) . '</span>' : '' ) . '<span class="dashicons dashicons-arrow-down-alt2"></span></summary>';
            echo '<div data-gi-candidate-search-scope>';
            if ( count( $active_page ) > 10 ) {
                /* translators: %d: number of currently visible events. */
                echo '<div class="gi-candidate-toolbar"><label class="gi-simple-search gi-candidate-search"><span class="screen-reader-text">' . esc_html__( 'Search events', 'great-imports' ) . '</span><input type="search" placeholder="' . esc_attr__( 'Search events', 'great-imports' ) . '" data-gi-candidate-search></label><span data-gi-visible-count>' . esc_html( sprintf( _n( '%d event shown', '%d events shown', count( $active_page ), 'great-imports' ), count( $active_page ) ) ) . '</span></div>';
            }
            echo '<form id="gi-bulk-candidates-form-' . esc_attr( (string) $source_id ) . '" class="gi-batch-actionbar gi-single-actionbar gi-rebuild-bulkbar" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
            wp_nonce_field( 'gi_bulk_candidates' );
            echo '<input type="hidden" name="action" value="gi_bulk_candidates"><input type="hidden" name="source_id" value="' . esc_attr( (string) $source_id ) . '"><div class="gi-bulk-selection"><button type="button" class="button-link" data-gi-select-ready>' . esc_html__( 'Select all ready', 'great-imports' ) . '</button><strong><span data-gi-selected-count>0</span> ' . esc_html__( 'selected', 'great-imports' ) . '</strong><button type="button" class="button-link" data-gi-clear-selection>' . esc_html__( 'Clear', 'great-imports' ) . '</button></div><div class="gi-bulk-actions gi-outcome-actions"><button class="button" name="bulk_action" value="draft" disabled>' . esc_html__( 'Save as drafts', 'great-imports' ) . '</button><button class="button button-primary" name="bulk_action" value="publish" disabled>' . esc_html__( 'Publish now', 'great-imports' ) . '</button><button class="button-link-delete" name="bulk_action" value="ignore" disabled>' . esc_html__( 'Skip', 'great-imports' ) . '</button></div></form>';
            echo '<div class="gi-review-list">';
            echo '<div class="gi-inline-empty" data-gi-no-search-results hidden><span class="dashicons dashicons-search"></span><div><strong>' . esc_html__( 'No events match that search', 'great-imports' ) . '</strong><p>' . esc_html__( 'Change the search terms or clear the search field.', 'great-imports' ) . '</p></div></div>';
            if ( $active_page ) {
                foreach ( $active_page as $candidate ) { $this->render_candidate_card( $candidate ); }
            } else {
                echo '<div class="gi-inline-empty"><span class="dashicons dashicons-yes-alt"></span><div><strong>' . esc_html__( 'No events are waiting', 'great-imports' ) . '</strong><p>' . esc_html__( 'Run the source again or review completed activity in History.', 'great-imports' ) . '</p></div></div>';
            }
            echo '</div></div>';
            $this->render_pagination( $page_number, $total_pages, array( 'tab' => $saved ? 'recurring' : 'sources', 'source_id' => $source_id ) );
            echo '</details>';
        } else {
            echo '<section class="gi-inline-empty gi-source-not-run-compact"><span class="dashicons dashicons-controls-play"></span><div><strong>' . esc_html__( 'No events in this queue', 'great-imports' ) . '</strong></div></section>';
        }

        echo '<details class="gi-danger-zone"><summary>' . esc_html__( 'Delete this import', 'great-imports' ) . '</summary><div><p>' . esc_html__( 'Deletes this saved link and the events found here. Events already added to your calendar remain.', 'great-imports' ) . '</p>';
        $this->delete_source_button( $source_id );
        echo '</div></details>';
    }

    private function render_source_schedule( array $source ): void {
        $source_id = absint( $source['id'] ?? 0 );
        $schedule = (array) ( $source['schedule'] ?? array() );
        $enabled = ! empty( $schedule['enabled'] );
        $next = $enabled && ! empty( $schedule['next_run_gmt'] ) ? get_date_from_gmt( $schedule['next_run_gmt'], 'M j, Y g:i A' ) : __( 'Not scheduled', 'great-imports' );
        echo '<div class="gi-source-settings-dropdown-heading gi-source-schedule-dropdown-heading"><div><h3>' . esc_html__( 'Automatic schedule', 'great-imports' ) . '</h3></div></div>';
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="gi-source-settings-dropdown-form gi-schedule-form">';
        wp_nonce_field( 'gi_update_source_' . $source_id );
        echo '<input type="hidden" name="action" value="gi_update_source"><input type="hidden" name="source_id" value="' . esc_attr( (string) $source_id ) . '">';
        echo '<label class="gi-checkbox gi-schedule-enabled"><input type="checkbox" name="enabled" value="1" ' . checked( $enabled, true, false ) . '><span><strong>' . esc_html__( 'Turn on automatic checks', 'great-imports' ) . '</strong></span></label>';
        echo '<div class="gi-form-grid gi-schedule-grid"><div class="gi-field"><label>' . esc_html__( 'Frequency', 'great-imports' ) . '</label><select name="cadence" data-gi-cadence>';
        foreach ( array( 'hourly' => __( 'Hourly', 'great-imports' ), 'daily' => __( 'Daily', 'great-imports' ), 'weekly' => __( 'Weekly', 'great-imports' ), 'monthly' => __( 'Monthly', 'great-imports' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $schedule['cadence'] ?? 'daily', $value, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></div>';
        echo '<div class="gi-field" data-gi-weekly-fields ' . ( 'weekly' === ( $schedule['cadence'] ?? 'daily' ) ? '' : 'hidden' ) . '><label>' . esc_html__( 'Day of week', 'great-imports' ) . '</label><select name="weekday">';
        foreach ( array( 0 => __( 'Sunday', 'great-imports' ), 1 => __( 'Monday', 'great-imports' ), 2 => __( 'Tuesday', 'great-imports' ), 3 => __( 'Wednesday', 'great-imports' ), 4 => __( 'Thursday', 'great-imports' ), 5 => __( 'Friday', 'great-imports' ), 6 => __( 'Saturday', 'great-imports' ) ) as $value => $label ) { echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( (int) ( $schedule['weekday'] ?? 1 ), $value, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></div>';
        echo '<div class="gi-field" data-gi-monthly-fields ' . ( 'monthly' === ( $schedule['cadence'] ?? 'daily' ) ? '' : 'hidden' ) . '><label>' . esc_html__( 'Day of month', 'great-imports' ) . '</label><input type="number" name="monthday" min="1" max="28" value="' . esc_attr( (string) ( $schedule['monthday'] ?? 1 ) ) . '"></div>';
        $parts = array_map( 'intval', explode( ':', $schedule['time'] ?? '08:00' ) );
        echo '<div class="gi-field" data-gi-hourly-fields ' . ( 'hourly' === ( $schedule['cadence'] ?? 'daily' ) ? '' : 'hidden' ) . '><label>' . esc_html__( 'Minute past the hour', 'great-imports' ) . '</label><input type="number" name="hourly_minute" min="0" max="59" value="' . esc_attr( (string) ( $parts[1] ?? 0 ) ) . '"></div>';
        echo '<div class="gi-field" data-gi-clock-fields ' . ( 'hourly' === ( $schedule['cadence'] ?? 'daily' ) ? 'hidden' : '' ) . '><label>' . esc_html__( 'Run time', 'great-imports' ) . '</label><input type="time" name="run_time" value="' . esc_attr( $schedule['time'] ?? '08:00' ) . '"></div></div>';
        echo '<div class="gi-section-save"><button class="button button-primary" name="source_submit" value="save_schedule">' . esc_html__( 'Save automatic checks', 'great-imports' ) . '</button></div></form>';
    }

    private function render_location_corrections_form( array $source, array $locations ): void {
        $source_id = absint( $source['id'] ?? 0 );
        $mappings = array();
        $seen = array();
        foreach ( (array) ( $source['rules']['location_mappings'] ?? array() ) as $mapping ) {
            $key = strtolower( trim( preg_replace( '/\\s+/', ' ', (string) ( $mapping['match'] ?? '' ) ) ) );
            if ( ! $key || isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $mappings[] = $mapping;
        }
        echo '<section class="gi-workspace-section gi-location-corrections" id="gi-source-location-rules"><header><div><div><h3>' . esc_html__( 'Location corrections', 'great-imports' ) . '</h3><p>' . esc_html__( 'Remember misspellings or alternate source labels only when they actually occur.', 'great-imports' ) . '</p></div></div></header>';
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="gi-workspace-content">';
        wp_nonce_field( 'gi_update_source_' . $source_id );
        echo '<input type="hidden" name="action" value="gi_update_source"><input type="hidden" name="source_id" value="' . esc_attr( (string) $source_id ) . '"><div class="gi-location-rule-table"><div data-gi-mappings>';
        if ( ! $mappings ) {
            echo '<div class="gi-no-location-rules" data-gi-no-mappings><strong>' . esc_html__( 'No saved location corrections', 'great-imports' ) . '</strong><p>' . esc_html__( 'Add one only when a source spelling or venue label refuses to match.', 'great-imports' ) . '</p></div>';
        }
        foreach ( $mappings as $index => $mapping ) { $this->location_mapping_row( (int) $index, $mapping ); }
        echo '</div>';
        ob_start();
        $this->location_mapping_row( 0, array() );
        $template = (string) ob_get_clean();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated by location_mapping_row(), which escapes every dynamic value.
        echo '<template data-gi-mapping-template>' . $template . '</template>';
        echo '<button type="button" class="button" data-gi-add-mapping>' . esc_html__( 'Add location correction', 'great-imports' ) . '</button></div>';
        echo '<div class="gi-section-save"><button class="button button-primary" name="source_submit" value="save_locations_apply">' . esc_html__( 'Save corrections and apply to current events', 'great-imports' ) . '</button></div></form></section>';
    }

    private function render_source_settings_form( array $source, array|WP_Error $terms, array $locations, bool $unsaved, bool $dropdown = false ): void {
        $source_id = absint( $source['id'] ?? 0 );
        $rules = (array) ( $source['rules'] ?? array() );
        $schedule = (array) ( $source['schedule'] ?? array() );
        $form_id = 'gi-source-settings-form-' . $source_id;
        if ( ! $dropdown ) {
            echo '<section class="gi-workspace-section gi-source-settings-panel" id="gi-source-settings"><header><div><div><h3>' . esc_html__( 'Choices for this link', 'great-imports' ) . '</h3><p>' . esc_html__( 'The recommended choices work for most event pages.', 'great-imports' ) . '</p></div></div></header>';
        }
        echo '<form id="' . esc_attr( $form_id ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" novalidate class="' . ( $dropdown ? 'gi-source-settings-dropdown-form' : 'gi-workspace-content' ) . '">';
        wp_nonce_field( 'gi_update_source_' . $source_id );
        echo '<input type="hidden" name="action" value="gi_update_source"><input type="hidden" name="source_id" value="' . esc_attr( (string) $source_id ) . '"><input type="hidden" name="source_intent" value="" data-gi-source-intent-field><div class="gi-form-grid">';
        if ( $dropdown ) {
            echo '<input type="hidden" name="source_name" value="' . esc_attr( $source['name'] ) . '">';
            if ( 'file' !== ( $source['source_type'] ?? '' ) ) {
                echo '<input type="hidden" name="source_url" value="' . esc_attr( $source['urls'][0] ?? '' ) . '">';
            }
        } else {
            $this->text_field( 'source_name', __( 'Source name', 'great-imports' ), $source['name'], 'wide' );
            if ( 'file' !== ( $source['source_type'] ?? '' ) ) { echo '<div class="gi-field gi-field-wide"><label>' . esc_html__( 'Source URL', 'great-imports' ) . '</label><input type="url" name="source_url" value="' . esc_attr( $source['urls'][0] ?? '' ) . '"></div>'; }
        }
        $this->render_import_controls( array(
            'action' => $source['action'] ?? 'review',
            'lookback' => $schedule['lookback'] ?? 0,
            'lookahead' => $schedule['lookahead'] ?? 90,
            'duplicate_policy' => $rules['duplicate_policy'] ?? 'update',
            'location_policy' => ! empty( $rules['force_location_enabled'] ) ? 'one_location' : ( $rules['location_policy'] ?? 'auto_create' ),
            'forced_em_location_id' => $rules['forced_em_location_id'] ?? 0,
            'image_policy' => $rules['image_policy'] ?? 'import',
            'event_author_id' => $rules['event_author_id'] ?? 0,
            'include_ticket_details' => $rules['include_ticket_details'] ?? 1,
            'include_organizer_details' => $rules['include_organizer_details'] ?? 1,
            'default_country' => $rules['default_country'] ?? 'US',
        ), false, $locations, $dropdown );
        if ( $dropdown ) {
            foreach ( (array) ( $rules['default_categories'] ?? array() ) as $category_id ) {
                echo '<input type="hidden" name="default_categories[]" value="' . esc_attr( (string) absint( $category_id ) ) . '">';
            }
            echo '<input type="hidden" name="category_names" value="' . esc_attr( implode( ', ', (array) ( $rules['category_names'] ?? array() ) ) ) . '"><input type="hidden" name="structure" value="' . esc_attr( $rules['structure'] ?? 'auto' ) . '"><input type="hidden" name="exclude_keywords" value="' . esc_attr( implode( ', ', (array) ( $rules['exclude_keywords'] ?? array() ) ) ) . '">';
            if ( ! empty( $rules['create_categories'] ) ) {
                echo '<input type="hidden" name="create_categories" value="1">';
            }
            if ( ! $unsaved ) {
                $filter_value = implode( ',', (array) ( $rules['include_keywords'] ?? array() ) );
                $known_filters = array(
                    '' => __( 'All events', 'great-imports' ),
                    'live,music,concert' => __( 'Live music and concerts', 'great-imports' ),
                    'comedy,stand-up,comedian' => __( 'Comedy', 'great-imports' ),
                    'festival,fair' => __( 'Festivals', 'great-imports' ),
                    'family,kids,children' => __( 'Family events', 'great-imports' ),
                    'food,drink,tasting' => __( 'Food and drink', 'great-imports' ),
                );
                echo '<div class="gi-field gi-field-wide gi-simple-event-filter"><label>' . esc_html__( 'Only keep', 'great-imports' ) . '</label><select name="include_keywords">';
                if ( $filter_value && ! isset( $known_filters[ $filter_value ] ) ) {
                    echo '<option value="' . esc_attr( $filter_value ) . '" selected>' . esc_html__( 'Current custom filter', 'great-imports' ) . '</option>';
                }
                foreach ( $known_filters as $value => $label ) {
                    echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filter_value, $value, false ) . '>' . esc_html( $label ) . '</option>';
                }
                echo '</select></div>';
            } else {
                echo '<input type="hidden" name="include_keywords" value="' . esc_attr( implode( ', ', (array) ( $rules['include_keywords'] ?? array() ) ) ) . '">';
            }
            echo '</div>';
        } else {
            echo '<details class="gi-advanced-options gi-field-wide"><summary><span><strong>' . esc_html__( 'Categories and filters', 'great-imports' ) . '</strong><small>' . esc_html__( 'Optional: limit which events are collected.', 'great-imports' ) . '</small></span><span class="dashicons dashicons-arrow-down-alt2"></span></summary><div class="gi-form-grid gi-advanced-grid">';
            echo '<div class="gi-field"><label>' . esc_html__( 'Event structure', 'great-imports' ) . '</label><select name="structure">';
            foreach ( array( 'auto' => __( 'Automatic', 'great-imports' ), 'festival' => __( 'Festival', 'great-imports' ), 'conference' => __( 'Conference', 'great-imports' ), 'multi_session' => __( 'Multi-session', 'great-imports' ), 'multi_location' => __( 'Multi-location', 'great-imports' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $rules['structure'] ?? 'auto', $value, false ) . '>' . esc_html( $label ) . '</option>'; }
            echo '</select></div><div class="gi-field gi-field-wide"><label>' . esc_html__( 'Categories', 'great-imports' ) . '</label><select name="default_categories[]" multiple size="5">';
            if ( ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( in_array( (int) $term->term_id, (array) ( $rules['default_categories'] ?? array() ), true ), true, false ) . '>' . esc_html( $term->name ) . '</option>'; } }
            echo '</select></div>';
            $this->text_field( 'category_names', __( 'Additional category names', 'great-imports' ), implode( ', ', (array) ( $rules['category_names'] ?? array() ) ), 'wide' );
            echo '<label class="gi-checkbox gi-field-wide"><input type="checkbox" name="create_categories" value="1" ' . checked( ! empty( $rules['create_categories'] ), true, false ) . '><span><strong>' . esc_html__( 'Create missing categories', 'great-imports' ) . '</strong></span></label>';
            $this->text_field( 'include_keywords', __( 'Only include titles/descriptions containing', 'great-imports' ), implode( ', ', (array) ( $rules['include_keywords'] ?? array() ) ), 'wide' );
            $this->text_field( 'exclude_keywords', __( 'Exclude titles/descriptions containing', 'great-imports' ), implode( ', ', (array) ( $rules['exclude_keywords'] ?? array() ) ), 'wide' );
            echo '</div></details></div>';
        }
        if ( ! $unsaved ) {
            echo '<div class="gi-section-save gi-source-decision-actions"><button type="submit" class="button button-primary" name="source_submit" value="save_rules">' . esc_html__( 'Save these choices', 'great-imports' ) . '</button><button type="submit" class="button" name="source_submit" value="save_rules_apply">' . esc_html__( 'Save and update events shown', 'great-imports' ) . '</button></div>';
        } else {
            echo '<p class="gi-source-settings-save-note">' . esc_html__( 'These options are applied when you check again or save this link.', 'great-imports' ) . '</p>';
        }
        echo '</form>';
        if ( ! $dropdown ) { echo '</section>'; }
    }

    private function render_import_controls( array $values, bool $global = false, array $locations = array(), bool $condensed = false ): void {
        $prefix = $global ? 'default_' : '';
        if ( $condensed ) {
            echo '<div class="gi-condensed-options-intro gi-field-wide"><p>' . esc_html__( 'The recommended settings are already selected. Change only what you need.', 'great-imports' ) . '</p></div><div class="gi-form-grid gi-advanced-grid gi-condensed-options-grid">';
        } else {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_options() returns fixed option markup with escaped attributes and labels.
            echo '<div class="gi-field gi-action-impact gi-easy-choice"><label>' . esc_html__( 'What should happen?', 'great-imports' ) . '</label><select name="run_action">' . $this->action_options( $values['action'] ?? 'review' ) . '</select><small>' . esc_html__( 'Review first is the safest choice.', 'great-imports' ) . '</small></div>';
            echo '<details class="gi-advanced-options gi-field-wide"><summary><span><strong>' . esc_html__( 'Advanced import choices', 'great-imports' ) . '</strong><small>' . esc_html__( 'Optional: matching, places, pictures, dates, and ownership.', 'great-imports' ) . '</small></span><span class="dashicons dashicons-arrow-down-alt2"></span></summary><div class="gi-form-grid gi-advanced-grid">';
            echo '<div class="gi-field gi-readonly-control"><label>' . esc_html__( 'Where events are added', 'great-imports' ) . '</label><strong>' . esc_html__( 'Events Manager', 'great-imports' ) . '</strong><small>' . esc_html__( 'Events and places are written directly to Events Manager.', 'great-imports' ) . '</small></div>';
        }
        echo '<div class="gi-field"><label>' . esc_html__( 'If the event already exists', 'great-imports' ) . '</label><select name="duplicate_policy"><option value="update" ' . selected( $values['duplicate_policy'] ?? 'update', 'update', false ) . '>' . esc_html__( 'Update it', 'great-imports' ) . '</option><option value="review" ' . selected( $values['duplicate_policy'] ?? 'update', 'review', false ) . '>' . esc_html__( 'Ask me first', 'great-imports' ) . '</option><option value="skip" ' . selected( $values['duplicate_policy'] ?? 'update', 'skip', false ) . '>' . esc_html__( 'Leave it alone', 'great-imports' ) . '</option></select></div>';
        if ( ! $condensed ) {
            echo '<div class="gi-field"><label>' . esc_html__( 'Places', 'great-imports' ) . '</label><select name="location_policy" data-gi-source-location-mode><option value="auto_create" ' . selected( $values['location_policy'] ?? 'auto_create', 'auto_create', false ) . '>' . esc_html__( 'Match or add the correct place', 'great-imports' ) . '</option><option value="existing_only" ' . selected( $values['location_policy'] ?? 'auto_create', 'existing_only', false ) . '>' . esc_html__( 'Use places already on my website', 'great-imports' ) . '</option>' . ( $global ? '' : '<option value="one_location" ' . selected( $values['location_policy'] ?? 'auto_create', 'one_location', false ) . '>' . esc_html__( 'Use the same place for every event', 'great-imports' ) . '</option>' ) . '</select></div>';
            if ( ! $global ) {
                echo '<div class="gi-field" data-gi-single-location ' . ( 'one_location' !== ( $values['location_policy'] ?? '' ) ? 'hidden' : '' ) . '><label>' . esc_html__( 'Use this place', 'great-imports' ) . '</label><select name="forced_em_location_id"><option value="0">' . esc_html__( 'Choose a place', 'great-imports' ) . '</option>';
                foreach ( $locations as $location ) { echo '<option value="' . esc_attr( (string) $location['location_id'] ) . '" ' . selected( (int) ( $values['forced_em_location_id'] ?? 0 ), (int) $location['location_id'], false ) . '>' . esc_html( trim( $location['location_name'] . ( $location['location_address'] ? ' — ' . $location['location_address'] : '' ) ) ) . '</option>'; }
                echo '</select></div>';
            }
        }
        echo '<div class="gi-field"><label>' . esc_html__( 'Pictures', 'great-imports' ) . '</label><select name="image_policy"><option value="import" ' . selected( $values['image_policy'] ?? 'import', 'import', false ) . '>' . esc_html__( 'Save pictures to my website', 'great-imports' ) . '</option><option value="keep_url" ' . selected( $values['image_policy'] ?? 'import', 'keep_url', false ) . '>' . esc_html__( 'Use the picture link', 'great-imports' ) . '</option><option value="ignore" ' . selected( $values['image_policy'] ?? 'import', 'ignore', false ) . '>' . esc_html__( 'Do not add pictures', 'great-imports' ) . '</option></select></div>';
        if ( $condensed ) {
            echo '<input type="hidden" name="event_author_id" value="' . esc_attr( (string) absint( $values['event_author_id'] ?? 0 ) ) . '">';
            echo '<input type="hidden" name="lookback" value="' . esc_attr( (string) ( $values['lookback'] ?? 0 ) ) . '">';
            echo '<input type="hidden" name="lookahead" value="' . esc_attr( (string) ( $values['lookahead'] ?? 90 ) ) . '">';
            echo '<input type="hidden" name="default_country" value="' . esc_attr( (string) ( $values['default_country'] ?? 'US' ) ) . '">';
        } else {
            $this->render_event_author_control( absint( $values['event_author_id'] ?? 0 ) );
            $this->text_field( 'lookback', __( 'Include past days', 'great-imports' ), (string) ( $values['lookback'] ?? 0 ), '', 'number' );
            $this->text_field( 'lookahead', __( 'Include future days', 'great-imports' ), (string) ( $values['lookahead'] ?? 90 ), '', 'number' );
            $this->text_field( 'default_country', __( 'Default country code', 'great-imports' ), (string) ( $values['default_country'] ?? 'US' ) );
        }
        echo '<label class="gi-checkbox gi-option-check"><input type="checkbox" name="include_ticket_details" value="1" ' . checked( ! empty( $values['include_ticket_details'] ), true, false ) . '><span><strong>' . esc_html__( 'Add ticket and price details', 'great-imports' ) . '</strong></span></label>';
        echo '<label class="gi-checkbox gi-option-check"><input type="checkbox" name="include_organizer_details" value="1" ' . checked( ! empty( $values['include_organizer_details'] ), true, false ) . '><span><strong>' . esc_html__( 'Add organizer details', 'great-imports' ) . '</strong></span></label>';
        echo $condensed ? '</div>' : '</div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches are fixed static closing markup.
    }

    private function render_event_author_control( int $selected ): void {
        $dropdown = wp_dropdown_users(
            array(
                'name'              => 'event_author_id',
                'selected'          => $selected,
                'show_option_none'  => __( 'Current user at import time', 'great-imports' ),
                'option_none_value' => 0,
                'echo'              => false,
            )
        );
        echo '<div class="gi-field"><label>' . esc_html__( 'Event author / owner', 'great-imports' ) . '</label>' . wp_kses( $dropdown, array( 'select' => array( 'name' => true, 'id' => true, 'class' => true ), 'option' => array( 'value' => true, 'selected' => true ) ) ) . '<small>' . esc_html__( 'Controls the WordPress event author and the Events Manager event owner for imported events.', 'great-imports' ) . '</small></div>';
    }

    private function posted_source_settings( array $existing ): array {
        if ( 'file' === ( $existing['source_type'] ?? '' ) ) {
            $urls = (array) ( $existing['urls'] ?? array() );
        } else {
            $urls = GI_Utils::urls_from_text( wp_unslash( $_POST['source_url'] ?? '' ) );
            if ( ! $urls ) { $urls = (array) ( $existing['urls'] ?? array() ); }
            $urls = array_slice( $urls, 0, 1 );
        }
        $location_policy = sanitize_key( $_POST['location_policy'] ?? $existing['rules']['location_policy'] ?? 'auto_create' );
        $one_location = 'one_location' === $location_policy;
        if ( ! in_array( $location_policy, array( 'auto_create', 'existing_only', 'one_location' ), true ) ) { $location_policy = 'auto_create'; }
        return array(
            'name' => sanitize_text_field( wp_unslash( $_POST['source_name'] ?? $existing['name'] ?? '' ) ),
            'urls' => $urls,
            'action' => sanitize_key( $_POST['run_action'] ?? $existing['action'] ?? 'review' ),
            'schedule' => array(
                'lookahead' => min( 730, max( 1, absint( $_POST['lookahead'] ?? $existing['schedule']['lookahead'] ?? 90 ) ) ),
                'lookback' => min( 365, max( 0, absint( $_POST['lookback'] ?? $existing['schedule']['lookback'] ?? 0 ) ) ),
            ),
            'rules' => array(
                'duplicate_policy' => sanitize_key( $_POST['duplicate_policy'] ?? $existing['rules']['duplicate_policy'] ?? 'update' ),
                'image_policy' => sanitize_key( $_POST['image_policy'] ?? $existing['rules']['image_policy'] ?? 'import' ),
                'event_author_id' => absint( $_POST['event_author_id'] ?? $existing['rules']['event_author_id'] ?? 0 ),
                'location_policy' => 'existing_only' === $location_policy ? 'existing_only' : 'auto_create',
                'force_location_enabled' => $one_location ? 1 : 0,
                'forced_em_location_id' => $one_location ? absint( $_POST['forced_em_location_id'] ?? 0 ) : 0,
                'default_country' => strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['default_country'] ?? 'US' ) ), 0, 2 ) ),
                'include_ticket_details' => empty( $_POST['include_ticket_details'] ) ? 0 : 1,
                'include_organizer_details' => empty( $_POST['include_organizer_details'] ) ? 0 : 1,
                'protect_local_edits' => 0,
                'default_categories' => array_map( 'absint', (array) ( $_POST['default_categories'] ?? array() ) ),
                'category_names' => $this->posted_csv( 'category_names' ),
                'create_categories' => empty( $_POST['create_categories'] ) ? 0 : 1,
                'structure' => sanitize_key( $_POST['structure'] ?? 'auto' ),
                'require_description' => 0,
                'require_image' => 0,
                'require_ticket_url' => 0,
                'include_keywords' => $this->posted_csv( 'include_keywords' ),
                'exclude_keywords' => $this->posted_csv( 'exclude_keywords' ),
            ),
        );
    }

    private function render_runs(): void {
        $source_filter = absint( $_GET['source_id'] ?? 0 );
        $status_filter = sanitize_key( $_GET['run_status'] ?? '' );
        $trigger_filter = sanitize_key( $_GET['trigger'] ?? '' );
        $date_from = sanitize_text_field( $_GET['date_from'] ?? '' );
        $date_to = sanitize_text_field( $_GET['date_to'] ?? '' );
        $page_number = max( 1, absint( $_GET['gi_paged'] ?? 1 ) );
        $per_page = 20;
        $sources = GI_Storage::list_sources( array( 'include_inactive' => true ) );
        $runs = GI_Storage::list_runs( -1 );
        $runs = array_values( array_filter( $runs, static function ( array $run ) use ( $source_filter, $status_filter, $trigger_filter, $date_from, $date_to ): bool {
            if ( $source_filter && $source_filter !== absint( $run['source_id'] ?? 0 ) ) {
                return false;
            }
            if ( $status_filter && $status_filter !== sanitize_key( $run['status'] ?? '' ) ) {
                return false;
            }
            if ( $trigger_filter && $trigger_filter !== sanitize_key( $run['trigger'] ?? '' ) ) {
                return false;
            }
            $started = substr( sanitize_text_field( $run['started_at'] ?? '' ), 0, 10 );
            if ( $date_from && $started && $started < $date_from ) {
                return false;
            }
            if ( $date_to && $started && $started > $date_to ) {
                return false;
            }
            return true;
        } ) );
        $total = count( $runs );
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( $page_number > $total_pages ) {
            $page_number = $total_pages;
        }
        $runs = array_slice( $runs, ( $page_number - 1 ) * $per_page, $per_page );

        echo '<section class="gi-panel gi-history-page"><div class="gi-panel-header"><div><span class="gi-eyebrow">' . esc_html__( 'Settings', 'great-imports' ) . '</span><h2>' . esc_html__( 'Import activity', 'great-imports' ) . '</h2><p>' . esc_html__( 'Use this page only when you need to check what happened. Activity records are deleted automatically after 30 days.', 'great-imports' ) . '</p></div><form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        wp_nonce_field( 'gi_download_diagnostics' );
        echo '<input type="hidden" name="action" value="gi_download_diagnostics"><button class="button">' . esc_html__( 'Download all diagnostics', 'great-imports' ) . '</button></form></div>';

        echo '<form class="gi-toolbar-form" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="great-imports"><input type="hidden" name="tab" value="runs"><div class="gi-field"><label>' . esc_html__( 'Source', 'great-imports' ) . '</label><select name="source_id"><option value="0">' . esc_html__( 'All sources', 'great-imports' ) . '</option>';
        foreach ( $sources as $source ) {
            echo '<option value="' . esc_attr( (int) $source['id'] ) . '" ' . selected( $source_filter, (int) $source['id'], false ) . '>' . esc_html( $source['name'] ) . '</option>';
        }
        echo '</select></div><div class="gi-field"><label>' . esc_html__( 'Result', 'great-imports' ) . '</label><select name="run_status"><option value="">' . esc_html__( 'All results', 'great-imports' ) . '</option><option value="complete" ' . selected( $status_filter, 'complete', false ) . '>' . esc_html__( 'Complete', 'great-imports' ) . '</option><option value="failed" ' . selected( $status_filter, 'failed', false ) . '>' . esc_html__( 'Failed', 'great-imports' ) . '</option><option value="running" ' . selected( $status_filter, 'running', false ) . '>' . esc_html__( 'Running', 'great-imports' ) . '</option></select></div><div class="gi-field"><label>' . esc_html__( 'Started by', 'great-imports' ) . '</label><select name="trigger"><option value="">' . esc_html__( 'Manual and scheduled', 'great-imports' ) . '</option><option value="manual" ' . selected( $trigger_filter, 'manual', false ) . '>' . esc_html__( 'Manual', 'great-imports' ) . '</option><option value="manual_file" ' . selected( $trigger_filter, 'manual_file', false ) . '>' . esc_html__( 'Manual file', 'great-imports' ) . '</option><option value="scheduled" ' . selected( $trigger_filter, 'scheduled', false ) . '>' . esc_html__( 'Scheduled', 'great-imports' ) . '</option></select></div>';
        $this->text_field( 'date_from', __( 'From date', 'great-imports' ), $date_from, '', 'date' );
        $this->text_field( 'date_to', __( 'Through date', 'great-imports' ), $date_to, '', 'date' );
        echo '<button class="button button-primary">' . esc_html__( 'Filter activity', 'great-imports' ) . '</button><a class="button" href="' . esc_url( admin_url( 'admin.php?page=great-imports&tab=runs' ) ) . '">' . esc_html__( 'Clear filters', 'great-imports' ) . '</a></form>';

        if ( ! $runs ) {
            echo '<div class="gi-empty-state"><span class="dashicons dashicons-list-view"></span><h3>' . esc_html__( 'No runs match these filters', 'great-imports' ) . '</h3><p>' . esc_html__( 'Change the filters or run a source.', 'great-imports' ) . '</p></div></section>';
            return;
        }
        echo '<div class="gi-run-list">';
        foreach ( $runs as $run ) {
            $source = GI_Storage::get_source( absint( $run['source_id'] ?? 0 ) );
            $summary = (array) ( $run['summary'] ?? array() );
            echo '<details class="gi-run"><summary><span><strong>' . esc_html( $source['name'] ?? $run['source_name'] ?? __( 'Deleted source', 'great-imports' ) ) . '</strong><small>' . esc_html( ( $run['trigger'] ?? 'manual' ) . ' · ' . ( $run['started_at'] ?? '' ) . ' · ' . $this->action_label( $run['action'] ?? 'review' ) ) . '</small></span><span class="gi-badge gi-badge-' . esc_attr( 'complete' === ( $run['status'] ?? '' ) ? 'ready' : 'failed' ) . '">' . esc_html( ucfirst( $run['status'] ?? 'unknown' ) ) . '</span></summary><div class="gi-run-body">';
            echo '<div class="gi-run-stats">';
            foreach ( array( 'collected', 'created', 'merged', 'duplicates_consolidated', 'ready', 'held', 'imported', 'updated', 'failed', 'blocked', 'filtered', 'skipped_existing', 'skipped_outside_window' ) as $key ) {
                echo '<span><b>' . esc_html( (string) ( $summary[ $key ] ?? 0 ) ) . '</b>' . esc_html( str_replace( '_', ' ', $key ) ) . '</span>';
            }
            echo '</div>';
            if ( ! empty( $run['messages'] ) ) {
                echo '<table class="widefat striped gi-log-table"><thead><tr><th>' . esc_html__( 'Time', 'great-imports' ) . '</th><th>' . esc_html__( 'Level', 'great-imports' ) . '</th><th>' . esc_html__( 'Message', 'great-imports' ) . '</th></tr></thead><tbody>';
                foreach ( $run['messages'] as $message ) {
                    echo '<tr><td>' . esc_html( $message['time'] ?? '' ) . '</td><td>' . esc_html( $message['level'] ?? '' ) . '</td><td>' . esc_html( $message['message'] ?? '' ) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            if ( ! empty( $summary['pages'] ) ) {
                echo '<details class="gi-evidence"><summary>' . esc_html__( 'Pages and feeds checked', 'great-imports' ) . '</summary><table class="widefat striped gi-log-table"><thead><tr><th>' . esc_html__( 'Source', 'great-imports' ) . '</th><th>' . esc_html__( 'Status', 'great-imports' ) . '</th><th>' . esc_html__( 'Method/type', 'great-imports' ) . '</th></tr></thead><tbody>';
                foreach ( (array) $summary['pages'] as $page ) {
                    echo '<tr><td>' . esc_html( $page['url'] ?? '' ) . '</td><td>' . esc_html( (string) ( $page['status'] ?? '' ) ) . '</td><td>' . esc_html( $page['method'] ?? $page['content_type'] ?? '' ) . '</td></tr>';
                }
                echo '</tbody></table></details>';
            }
            echo '<div class="gi-run-actions">';
            if ( $source && ! empty( $source['workspace_active'] ) ) {
                $this->run_source_button( (int) $source['id'] );
                echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=great-imports&tab=' . ( ! empty( $source['is_saved'] ) ? 'recurring' : 'sources' ) . '&source_id=' . (int) $source['id'] ) ) . '">' . esc_html__( 'Manage source', 'great-imports' ) . '</a>';
            } elseif ( $source ) {
                echo '<form class="gi-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
                wp_nonce_field( 'gi_restore_source_' . (int) $source['id'] );
                echo '<input type="hidden" name="action" value="gi_restore_source"><input type="hidden" name="source_id" value="' . esc_attr( (int) $source['id'] ) . '"><button class="button">' . esc_html__( 'Restore to Sources', 'great-imports' ) . '</button></form>';
            }
            echo '<form class="gi-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
            wp_nonce_field( 'gi_download_run_' . (int) $run['id'] );
            echo '<input type="hidden" name="action" value="gi_download_run"><input type="hidden" name="run_id" value="' . esc_attr( (int) $run['id'] ) . '"><button class="button">' . esc_html__( 'Download this run', 'great-imports' ) . '</button></form></div>';
            echo '</div></details>';
        }
        echo '</div>';
        $this->render_pagination( $page_number, $total_pages, array( 'tab' => 'runs', 'source_id' => $source_filter, 'run_status' => $status_filter, 'trigger' => $trigger_filter, 'date_from' => $date_from, 'date_to' => $date_to ) );
        echo '</section>';
    }

    private function render_settings(): void {
        $settings = GI_Storage::settings();
        echo '<section class="gi-page-heading"><div><span class="gi-eyebrow">' . esc_html__( 'Settings', 'great-imports' ) . '</span><h2>' . esc_html__( 'Settings', 'great-imports' ) . '</h2><p>' . esc_html__( 'Choose the defaults used when an individual URL does not provide its own choice.', 'great-imports' ) . '</p></div></section>';
        echo '<section class="gi-panel gi-activity-access"><div><span class="gi-nav-icon dashicons dashicons-clock" aria-hidden="true"></span><div><h3>' . esc_html__( 'Import activity', 'great-imports' ) . '</h3><p>' . esc_html__( 'Check past imports and failed website checks only when you need them. Records are deleted automatically after 30 days.', 'great-imports' ) . '</p></div></div><a class="button" href="' . esc_url( admin_url( 'admin.php?page=great-imports&tab=runs' ) ) . '">' . esc_html__( 'View import activity', 'great-imports' ) . '</a></section>';
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="gi-source-editor">';
        wp_nonce_field( 'gi_save_settings' );
        echo '<input type="hidden" name="action" value="gi_save_settings">';
        echo '<input type="hidden" name="run_action" value="' . esc_attr( $settings['default_action'] ?? 'review' ) . '">';
        echo '<input type="hidden" name="event_author_id" value="' . esc_attr( (string) absint( $settings['default_event_author_id'] ?? 0 ) ) . '">';
        echo '<input type="hidden" name="lookback" value="' . esc_attr( (string) absint( $settings['default_lookback'] ?? 0 ) ) . '">';
        echo '<input type="hidden" name="lookahead" value="' . esc_attr( (string) absint( $settings['default_lookahead'] ?? 90 ) ) . '">';
        echo '<input type="hidden" name="default_country" value="' . esc_attr( $settings['default_country'] ?? 'US' ) . '">';
        echo '<input type="hidden" name="include_ticket_details" value="1">';
        echo '<input type="hidden" name="include_organizer_details" value="1">';
        echo '<input type="hidden" name="allow_category_creation" value="1">';
        echo '<input type="hidden" name="auto_remove_run_once" value="1">';
        echo '<section class="gi-settings-section is-open"><button type="button" class="gi-section-toggle" data-gi-section-toggle aria-expanded="true"><span><span><strong>' . esc_html__( 'Import defaults', 'great-imports' ) . '</strong><small>' . esc_html__( 'Three choices used when a URL does not override them.', 'great-imports' ) . '</small></span></span><span class="dashicons dashicons-arrow-down-alt2"></span></button><div class="gi-section-content"><div class="gi-form-grid">';
        echo '<div class="gi-field"><label>' . esc_html__( 'If the event already exists', 'great-imports' ) . '</label><select name="duplicate_policy"><option value="update" ' . selected( $settings['default_duplicate_policy'] ?? 'update', 'update', false ) . '>' . esc_html__( 'Update it', 'great-imports' ) . '</option><option value="review" ' . selected( $settings['default_duplicate_policy'] ?? 'update', 'review', false ) . '>' . esc_html__( 'Ask me first', 'great-imports' ) . '</option><option value="skip" ' . selected( $settings['default_duplicate_policy'] ?? 'update', 'skip', false ) . '>' . esc_html__( 'Leave it alone', 'great-imports' ) . '</option></select></div>';
        echo '<div class="gi-field"><label>' . esc_html__( 'Places', 'great-imports' ) . '</label><select name="location_policy"><option value="auto_create" ' . selected( $settings['default_location_policy'] ?? 'auto_create', 'auto_create', false ) . '>' . esc_html__( 'Match or add the correct place', 'great-imports' ) . '</option><option value="existing_only" ' . selected( $settings['default_location_policy'] ?? 'auto_create', 'existing_only', false ) . '>' . esc_html__( 'Use places already on my website', 'great-imports' ) . '</option></select></div>';
        echo '<div class="gi-field"><label>' . esc_html__( 'Pictures', 'great-imports' ) . '</label><select name="image_policy"><option value="import" ' . selected( $settings['default_image_policy'] ?? 'import', 'import', false ) . '>' . esc_html__( 'Save pictures to my website', 'great-imports' ) . '</option><option value="keep_url" ' . selected( $settings['default_image_policy'] ?? 'import', 'keep_url', false ) . '>' . esc_html__( 'Use the picture link', 'great-imports' ) . '</option><option value="ignore" ' . selected( $settings['default_image_policy'] ?? 'import', 'ignore', false ) . '>' . esc_html__( 'Do not add pictures', 'great-imports' ) . '</option></select></div>';
        echo '</div></div></section>';
        echo '<section class="gi-settings-section"><button type="button" class="gi-section-toggle" data-gi-section-toggle aria-expanded="false"><span><span><strong>' . esc_html__( 'Custom filters', 'great-imports' ) . '</strong><small>' . esc_html__( 'Set optional keyword defaults for newly added URLs.', 'great-imports' ) . '</small></span></span><span class="dashicons dashicons-arrow-down-alt2"></span></button><div class="gi-section-content" hidden><div class="gi-form-grid">';
        echo '<div class="gi-field gi-field-wide"><label>' . esc_html__( 'Only keep events containing', 'great-imports' ) . '</label><input type="text" name="default_include_keywords" value="' . esc_attr( implode( ', ', (array) ( $settings['default_include_keywords'] ?? array() ) ) ) . '" placeholder="' . esc_attr__( 'Example: live music, concert, comedy', 'great-imports' ) . '"><small>' . esc_html__( 'Leave blank to keep every event. Separate words or phrases with commas.', 'great-imports' ) . '</small></div>';
        echo '<div class="gi-field gi-field-wide"><label>' . esc_html__( 'Skip events containing', 'great-imports' ) . '</label><input type="text" name="default_exclude_keywords" value="' . esc_attr( implode( ', ', (array) ( $settings['default_exclude_keywords'] ?? array() ) ) ) . '" placeholder="' . esc_attr__( 'Example: cancelled, private event', 'great-imports' ) . '"><small>' . esc_html__( 'A match in the title, description, venue, or categories leaves that event out.', 'great-imports' ) . '</small></div>';
        echo '<p class="description gi-field-wide">' . esc_html__( 'Saved URLs keep their own filters. These defaults apply only when a new URL is added.', 'great-imports' ) . '</p></div></div></section>';
        echo '<section class="gi-settings-section"><button type="button" class="gi-section-toggle" data-gi-section-toggle aria-expanded="false"><span><span><strong>' . esc_html__( 'Pornography and nudity filter', 'great-imports' ) . '</strong><small>' . esc_html__( 'Block clear matches and hold uncertain events for review.', 'great-imports' ) . '</small></span></span><span class="dashicons dashicons-arrow-down-alt2"></span></button><div class="gi-section-content" hidden><div class="gi-form-grid">';
        echo '<div class="gi-field gi-field-wide"><label class="gi-checkbox"><input type="checkbox" name="explicit_content_filter_enabled" value="1" ' . checked( ! empty( $settings['explicit_content_filter_enabled'] ), true, false ) . '><span><strong>' . esc_html__( 'Filter pornography and explicit nudity', 'great-imports' ) . '</strong><small>' . esc_html__( 'Checks the event wording, categories, tags, source links, and picture links together. Ordinary nightlife and mature comedy are not blocked merely for being adult-oriented.', 'great-imports' ) . '</small></span></label></div>';
        echo '<div class="gi-field"><label>' . esc_html__( 'Sensitivity', 'great-imports' ) . '</label><select name="explicit_content_sensitivity"><option value="standard" ' . selected( $settings['explicit_content_sensitivity'] ?? 'standard', 'standard', false ) . '>' . esc_html__( 'Standard — recommended', 'great-imports' ) . '</option><option value="strict" ' . selected( $settings['explicit_content_sensitivity'] ?? 'standard', 'strict', false ) . '>' . esc_html__( 'Strict — block more uncertain matches', 'great-imports' ) . '</option></select><small>' . esc_html__( 'Standard sends uncertain context to review. Strict blocks more combined nudity and sexual context.', 'great-imports' ) . '</small></div>';
        echo '<div class="gi-field gi-field-wide"><label>' . esc_html__( 'Always block these words or phrases', 'great-imports' ) . '</label><input type="text" name="explicit_content_custom_terms" value="' . esc_attr( implode( ', ', (array) ( $settings['explicit_content_custom_terms'] ?? array() ) ) ) . '" placeholder="' . esc_attr__( 'Optional custom phrases', 'great-imports' ) . '"><small>' . esc_html__( 'Separate phrases with commas. These are treated as clear matches.', 'great-imports' ) . '</small></div>';
        echo '<div class="gi-field gi-field-wide"><label>' . esc_html__( 'Trusted websites', 'great-imports' ) . '</label><input type="text" name="explicit_content_trusted_domains" value="' . esc_attr( implode( ', ', (array) ( $settings['explicit_content_trusted_domains'] ?? array() ) ) ) . '" placeholder="' . esc_attr__( 'Example: example.org, tickets.example.com', 'great-imports' ) . '"><small>' . esc_html__( 'Events from these domains bypass this safety filter. Use only for websites you trust.', 'great-imports' ) . '</small></div>';
        echo '<div class="gi-content-filter-key gi-field-wide"><span><strong>' . esc_html__( 'Allowed', 'great-imports' ) . '</strong><small>' . esc_html__( 'Continues normally', 'great-imports' ) . '</small></span><span><strong>' . esc_html__( 'Needs review', 'great-imports' ) . '</strong><small>' . esc_html__( 'You decide', 'great-imports' ) . '</small></span><span><strong>' . esc_html__( 'Blocked', 'great-imports' ) . '</strong><small>' . esc_html__( 'Does not enter the queue', 'great-imports' ) . '</small></span></div>';
        echo '</div></div></section>';
        echo '<section class="gi-settings-section"><button type="button" class="gi-section-toggle" data-gi-section-toggle aria-expanded="false"><span><span><strong>' . esc_html__( 'Collection limits', 'great-imports' ) . '</strong><small>' . esc_html__( 'Technical limits that prevent a website check from running too long.', 'great-imports' ) . '</small></span></span><span class="dashicons dashicons-arrow-down-alt2"></span></button><div class="gi-section-content" hidden><div class="gi-form-grid">';
        $this->text_field( 'request_timeout', __( 'Request timeout (seconds)', 'great-imports' ), (string) $settings['request_timeout'], '', 'number' );
        $this->text_field( 'max_events_per_run', __( 'Maximum events per URL', 'great-imports' ), (string) $settings['max_events_per_run'], '', 'number' );
        $this->text_field( 'max_total_events_per_run', __( 'Maximum events in one bulk run', 'great-imports' ), (string) $settings['max_total_events_per_run'], '', 'number' );
        $this->text_field( 'max_discovered_pages', __( 'Maximum detail pages per source', 'great-imports' ), (string) $settings['max_discovered_pages'], '', 'number' );
        echo '</div></div></section>';
        echo '<section class="gi-settings-section"><button type="button" class="gi-section-toggle" data-gi-section-toggle aria-expanded="false"><span><span><strong>' . esc_html__( 'Platform access', 'great-imports' ) . '</strong><small>' . esc_html__( 'Optional credentials used only for their matching platform API.', 'great-imports' ) . '</small></span></span><span class="dashicons dashicons-arrow-down-alt2"></span></button><div class="gi-section-content" hidden><div class="gi-form-grid">';
        $this->secret_field( 'eventbrite_token', __( 'Eventbrite private token', 'great-imports' ), $settings['eventbrite_token'] );
        $this->secret_field( 'ticketmaster_key', __( 'Ticketmaster API key', 'great-imports' ), $settings['ticketmaster_key'] );
        echo '</div><p class="description">' . esc_html__( 'Secrets are excluded from diagnostics and run logs.', 'great-imports' ) . '</p></div></section>';
        echo '<section class="gi-settings-section"><button type="button" class="gi-section-toggle" data-gi-section-toggle aria-expanded="false"><span><span><strong>' . esc_html__( 'System and uninstall', 'great-imports' ) . '</strong><small>' . esc_html__( 'Website-reading status and what happens when the plugin is removed.', 'great-imports' ) . '</small></span></span><span class="dashicons dashicons-arrow-down-alt2"></span></button><div class="gi-section-content" hidden><div class="gi-form-grid">';
        echo '<div class="gi-field gi-field-wide"><div class="gi-health-card ' . ( class_exists( 'DOMDocument' ) ? 'is-good' : 'is-bad' ) . '"><span class="dashicons ' . ( class_exists( 'DOMDocument' ) ? 'dashicons-yes-alt' : 'dashicons-warning' ) . '"></span><div><strong>' . esc_html( class_exists( 'DOMDocument' ) ? __( 'Generic website parsing available', 'great-imports' ) : __( 'Generic website parsing unavailable', 'great-imports' ) ) . '</strong><small>' . esc_html( class_exists( 'DOMDocument' ) ? __( 'PHP DOM support is active.', 'great-imports' ) : __( 'Enable PHP DOM. ICS, CSV, JSON, and supported API imports remain available.', 'great-imports' ) ) . '</small></div></div></div>';
        echo '<div class="gi-field gi-field-wide"><label class="gi-checkbox"><input type="checkbox" name="cleanup_on_uninstall" value="1" ' . checked( ! empty( $settings['cleanup_on_uninstall'] ), true, false ) . '><span><strong>' . esc_html__( 'Remove Great Imports data when uninstalled', 'great-imports' ) . '</strong><small>' . esc_html__( 'Imported Events Manager records remain.', 'great-imports' ) . '</small></span></label></div></div></div></section>';
        echo '<div class="gi-editor-actions"><button class="button button-primary button-hero">' . esc_html__( 'Save settings', 'great-imports' ) . '</button></div></form>';
        echo '<section class="gi-panel"><span class="gi-eyebrow">' . esc_html__( 'Maintenance', 'great-imports' ) . '</span><h3>' . esc_html__( 'Great Imports data tools', 'great-imports' ) . '</h3><div class="gi-maintenance-grid">';
        echo '<div class="gi-maintenance-row is-danger"><div><strong>' . esc_html__( 'Clear import activity', 'great-imports' ) . '</strong><small>' . esc_html__( 'Sources, candidates, and imported Events Manager records remain.', 'great-imports' ) . '</small></div><details class="gi-maintenance-confirm"><summary class="button button-link-delete">' . esc_html__( 'Clear activity…', 'great-imports' ) . '</summary><div><p>' . esc_html__( 'This removes all Great Imports activity records now.', 'great-imports' ) . '</p><form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        wp_nonce_field( 'gi_clear_run_history' );
        echo '<input type="hidden" name="action" value="gi_clear_run_history"><button class="button button-link-delete">' . esc_html__( 'Confirm clear history', 'great-imports' ) . '</button></form></div></details></div>';
        echo '<div class="gi-maintenance-row is-danger"><div><strong>' . esc_html__( 'Reset Great Imports', 'great-imports' ) . '</strong><small>' . esc_html__( 'Deletes Great Imports data only. Imported Events Manager records remain.', 'great-imports' ) . '</small></div><details class="gi-maintenance-confirm"><summary class="button button-link-delete">' . esc_html__( 'Reset…', 'great-imports' ) . '</summary><div><p>' . esc_html__( 'This removes every Great Imports source, candidate, and run record.', 'great-imports' ) . '</p><form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        wp_nonce_field( 'gi_reset_plugin_data' );
        echo '<input type="hidden" name="action" value="gi_reset_plugin_data"><button class="button button-link-delete">' . esc_html__( 'Confirm reset', 'great-imports' ) . '</button></form></div></details></div></div></section>';
    }

    public function handle_create_source(): void {
        $this->require_access();
        check_admin_referer( 'gi_create_source' );
        $settings = GI_Storage::settings();
        $input_source = sanitize_key( $_POST['input_source'] ?? 'urls' );
        $is_file = 'file' === $input_source;
        $intake_action = sanitize_key( $_POST['intake_action'] ?? 'run_once' );
        if ( ! in_array( $intake_action, array( 'run_once', 'save_recurring' ), true ) ) { $intake_action = 'run_once'; }
        if ( $is_file ) { $intake_action = 'run_once'; }
        $run_action = sanitize_key( $_POST['run_action'] ?? 'review' );
        if ( ! in_array( $run_action, array( 'review', 'draft', 'publish' ), true ) ) { $run_action = 'review'; }
        $location_policy = sanitize_key( $_POST['location_policy'] ?? 'auto_create' );
        $one_location = 'one_location' === $location_policy;
        $forced_location_id = $one_location ? absint( $_POST['forced_em_location_id'] ?? 0 ) : 0;
        if ( $one_location && ! $forced_location_id ) {
            $this->redirect_with_notice( 'new', __( 'Choose the location to use for every event.', 'great-imports' ), 'error' );
        }
        $recurring_cadence = sanitize_key( $_POST['recurring_cadence'] ?? 'daily' );
        if ( ! in_array( $recurring_cadence, array( 'hourly', 'daily', 'weekly', 'monthly' ), true ) ) { $recurring_cadence = 'daily'; }
        $is_recurring = 'save_recurring' === $intake_action;
        $urls = $is_file ? array() : GI_Utils::urls_from_text( wp_unslash( $_POST['urls'] ?? '' ) );
        if ( ! $is_file && ! $urls ) {
            $this->redirect_with_notice( 'new', __( 'Enter at least one valid URL.', 'great-imports' ), 'error' );
        }
        $uploaded_file = array();
        if ( $is_file ) {
            $event_file = isset( $_FILES['event_file'] ) && is_array( $_FILES['event_file'] ) ? $_FILES['event_file'] : array();
            $temporary_name = sanitize_text_field( $event_file['tmp_name'] ?? '' );
            if ( ! $temporary_name || ! is_uploaded_file( $temporary_name ) ) {
                $this->redirect_with_notice( 'new', __( 'Choose an ICS, CSV, or JSON file.', 'great-imports' ), 'error' );
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $uploaded_file = wp_handle_upload(
                $event_file,
                array(
                    'test_form' => false,
                    'mimes' => array( 'ics' => 'text/calendar', 'ical' => 'text/calendar', 'csv' => 'text/csv', 'json' => 'application/json' ),
                )
            );
            if ( ! empty( $uploaded_file['error'] ) || empty( $uploaded_file['file'] ) ) {
                $this->redirect_with_notice( 'new', sanitize_text_field( $uploaded_file['error'] ?? __( 'The event file could not be stored.', 'great-imports' ) ), 'error' );
            }
        }

        $base_source = array(
            'name' => $is_file ? sanitize_file_name( $event_file['name'] ?? '' ) : '',
            'source_type' => $is_file ? 'file' : 'urls',
            'urls' => $urls,
            'file_path' => $is_file ? wp_normalize_path( $uploaded_file['file'] ) : '',
            'file_name' => $is_file ? sanitize_file_name( $event_file['name'] ?? '' ) : '',
            'action' => $run_action,
            'is_saved' => $is_recurring ? 1 : 0,
            'workspace_active' => 1,
            'schedule' => array(
                'enabled' => $is_recurring ? 1 : 0,
                'cadence' => $recurring_cadence,
                'time' => '08:00',
                'next_run_gmt' => '',
                'lookahead' => min( 730, max( 1, absint( $settings['default_lookahead'] ?? 90 ) ) ),
                'lookback' => min( 365, max( 0, absint( $settings['default_lookback'] ?? 0 ) ) ),
            ),
            'rules' => array(
                'location_policy' => $one_location ? 'auto_create' : ( $settings['default_location_policy'] ?? 'auto_create' ),
                'force_location_enabled' => $one_location ? 1 : 0,
                'forced_em_location_id' => $forced_location_id,
                'duplicate_policy' => $settings['default_duplicate_policy'] ?? 'update',
                'image_policy' => $settings['default_image_policy'] ?? 'import',
                'event_author_id' => absint( $settings['default_event_author_id'] ?? 0 ),
                'protect_local_edits' => 0,
                'include_ticket_details' => ! empty( $settings['include_ticket_details'] ) ? 1 : 0,
                'include_organizer_details' => ! empty( $settings['include_organizer_details'] ) ? 1 : 0,
                'include_keywords' => (array) ( $settings['default_include_keywords'] ?? array() ),
                'exclude_keywords' => (array) ( $settings['default_exclude_keywords'] ?? array() ),
                'default_country' => $settings['default_country'] ?? 'US',
            ),
        );

        $source_batches = array();
        if ( $is_file ) {
            $source_batches[] = $base_source;
        } else {
            foreach ( $urls as $url ) {
                $source = $base_source;
                $source['urls'] = array( $url );
                $source_batches[] = $source;
            }
        }

        $created_ids = array();
        foreach ( $source_batches as $source ) {
            $source_id = GI_Storage::create_source( $source );
            if ( is_wp_error( $source_id ) ) { continue; }
            $source_id = (int) $source_id;
            $existing_source = GI_Storage::get_source( $source_id );
            $source_changes = $source;
            if ( ! $is_recurring && ! empty( $existing_source['is_saved'] ) ) {
                // A one-time check of an already recurring URL must not turn
                // off its saved schedule.
                $source_changes['is_saved'] = 1;
                $source_changes['schedule'] = (array) ( $existing_source['schedule'] ?? $source['schedule'] );
            }
            $updated = GI_Storage::update_source( $source_id, $source_changes );
            if ( is_wp_error( $updated ) ) { continue; }
            $created_ids[] = $source_id;
        }
        if ( ! $created_ids ) {
            if ( $is_file && ! empty( $uploaded_file['file'] ) && is_file( $uploaded_file['file'] ) ) { wp_delete_file( $uploaded_file['file'] ); }
            $this->redirect_with_notice( 'new', __( 'The source could not be created.', 'great-imports' ), 'error' );
        }
        $totals = array( 'collected' => 0, 'ready' => 0, 'held' => 0, 'imported' => 0, 'updated' => 0 );
        $errors = array();
        foreach ( $created_ids as $created_id ) {
            $run = GI_Runner::run_source( $created_id, $is_file ? 'manual_file' : 'manual' );
            if ( $is_recurring ) {
                GI_Scheduler::advance_source( $created_id, true );
            }
            if ( is_wp_error( $run ) ) {
                $errors[] = $run->get_error_message();
                continue;
            }
            $summary = (array) ( $run['summary'] ?? array() );
            foreach ( array_keys( $totals ) as $key ) {
                $totals[ $key ] += absint( $summary[ $key ] ?? 0 );
            }
        }
        if ( $is_recurring ) {
            /* translators: 1: events found, 2: ready, 3: needing attention, 4: imported or updated. */
            $message_template = __( 'Recurring import saved and checked: %1$d found, %2$d ready, %3$d need attention, %4$d imported or updated.', 'great-imports' );
        } else {
            /* translators: 1: events found, 2: ready, 3: needing attention, 4: imported or updated. */
            $message_template = __( 'One-time check complete: %1$d found, %2$d ready, %3$d need attention, %4$d imported or updated.', 'great-imports' );
        }
        $message = sprintf(
            $message_template,
            $totals['collected'],
            $totals['ready'],
            $totals['held'],
            $totals['imported'] + $totals['updated']
        );
        if ( $errors ) {
            $message .= ' ' . implode( ' ', array_unique( array_map( 'sanitize_text_field', $errors ) ) );
        }
        $this->redirect_with_notice( $is_recurring ? 'recurring' : 'sources', $message, $errors ? 'warning' : 'success', array( 'source_id' => $created_ids[0] ) );
    }

    public function handle_update_source(): void {
        $this->require_access();
        $source_id = absint( $_POST['source_id'] ?? 0 );
        check_admin_referer( 'gi_update_source_' . $source_id );
        $existing = GI_Storage::get_source( $source_id );
        if ( ! $existing ) {
            $this->redirect_with_notice( 'sources', __( 'Source not found.', 'great-imports' ), 'error' );
        }
        $return_tab = $this->source_tab( $existing );
        $submit = sanitize_key( ! empty( $_POST['source_intent'] ) ? $_POST['source_intent'] : ( $_POST['source_submit'] ?? 'save_rules' ) );
        $allowed = array( 'save_locations_apply', 'save_rules', 'save_rules_apply', 'save_schedule', 'save_source', 'run_once' );
        if ( ! in_array( $submit, $allowed, true ) ) { $submit = 'save_rules'; }

        if ( 'save_schedule' === $submit ) {
            if ( empty( $existing['is_saved'] ) ) {
                $this->redirect_with_notice( $return_tab, __( 'Save the source before scheduling it.', 'great-imports' ), 'error', array( 'source_id' => $source_id ) );
            }
            $cadence = sanitize_key( $_POST['cadence'] ?? 'daily' );
            if ( ! in_array( $cadence, array( 'hourly', 'daily', 'weekly', 'monthly' ), true ) ) { $cadence = 'daily'; }
            $run_time = sanitize_text_field( wp_unslash( $_POST['run_time'] ?? $existing['schedule']['time'] ?? '08:00' ) );
            if ( 'hourly' === $cadence ) {
                $run_time = sprintf( '00:%02d', min( 59, absint( $_POST['hourly_minute'] ?? 0 ) ) );
            }
            $changes = array( 'schedule' => array(
                'enabled' => empty( $_POST['enabled'] ) ? 0 : 1,
                'cadence' => $cadence,
                'time' => $run_time,
                'weekday' => min( 6, absint( $_POST['weekday'] ?? 1 ) ),
                'monthday' => min( 28, max( 1, absint( $_POST['monthday'] ?? 1 ) ) ),
                'next_run_gmt' => '',
            ) );
            $result = GI_Storage::update_source( $source_id, $changes );
            if ( is_wp_error( $result ) ) { $this->redirect_with_notice( $return_tab, $result->get_error_message(), 'error', array( 'source_id' => $source_id ) ); }
            if ( ! empty( $changes['schedule']['enabled'] ) ) {
                GI_Scheduler::advance_source( $source_id, true );
            }
            $message = ! empty( $changes['schedule']['enabled'] )
                ? __( 'Schedule saved. Automatic runs are enabled. No candidates were changed and the source was not run.', 'great-imports' )
                : __( 'Schedule saved. Automatic runs are disabled and no next run is queued.', 'great-imports' );
            $this->redirect_with_notice( $return_tab, $message, 'success', array( 'source_id' => $source_id ) );
        }

        if ( 'save_locations_apply' === $submit ) {
            $changes = array( 'rules' => array( 'location_mappings' => $this->posted_location_mappings() ) );
            $result = GI_Storage::update_source( $source_id, $changes );
            if ( is_wp_error( $result ) ) { $this->redirect_with_notice( $return_tab, $result->get_error_message(), 'error', array( 'source_id' => $source_id ) ); }
            $applied = GI_Runner::reapply_source_rules( $source_id );
            if ( is_wp_error( $applied ) ) { $this->redirect_with_notice( $return_tab, $applied->get_error_message(), 'error', array( 'source_id' => $source_id ) ); }
            /* translators: 1: candidates processed, 2: ready, 3: requiring attention. */
            $message = sprintf( __( 'Location corrections saved and %1$d current candidates re-evaluated: %2$d ready and %3$d still need attention.', 'great-imports' ), (int) $applied['processed'], (int) $applied['ready'], (int) $applied['held'] );
            $this->redirect_with_notice( $return_tab, $message, 'success', array( 'source_id' => $source_id ) );
        }

        $changes = $this->posted_source_settings( $existing );
        if ( 'save_source' === $submit ) {
            $changes['is_saved'] = 1;
            $changes['workspace_active'] = 1;
            $changes['schedule']['enabled'] = 0;
            $changes['schedule']['next_run_gmt'] = '';
            $return_tab = 'recurring';
        }
        $result = GI_Storage::update_source( $source_id, $changes );
        if ( is_wp_error( $result ) ) { $this->redirect_with_notice( $return_tab, $result->get_error_message(), 'error', array( 'source_id' => $source_id ) ); }

        if ( 'run_once' === $submit ) {
            $run = GI_Runner::run_source( $source_id, ( 'file' === ( $existing['source_type'] ?? '' ) ? 'manual_file' : 'manual' ) );
            if ( is_wp_error( $run ) ) { $this->redirect_with_notice( $return_tab, $run->get_error_message(), 'error', array( 'source_id' => $source_id ) ); }
            $summary = (array) ( $run['summary'] ?? array() );
            /* translators: 1: events collected, 2: ready, 3: requiring attention, 4: imported or updated. */
            $message = sprintf( __( 'Run complete: %1$d collected, %2$d ready, %3$d need attention, %4$d imported or updated.', 'great-imports' ), (int) ( $summary['collected'] ?? 0 ), (int) ( $summary['ready'] ?? 0 ), (int) ( $summary['held'] ?? 0 ), (int) ( $summary['imported'] ?? 0 ) + (int) ( $summary['updated'] ?? 0 ) );
            $removed = ! empty( $run['source_removed'] );
            $this->redirect_with_notice( $return_tab, $removed ? $message . ' ' . __( 'The completed Run once source left the active queue; History is retained.', 'great-imports' ) : $message, 'success', $removed ? array() : array( 'source_id' => $source_id ) );
        }
        if ( 'save_source' === $submit ) {
            $this->redirect_with_notice( 'recurring', __( 'Link saved. It was not checked again. You can check it now or turn on automatic checks.', 'great-imports' ), 'success', array( 'source_id' => $source_id ) );
        }
        if ( 'save_rules_apply' === $submit ) {
            $applied = GI_Runner::reapply_source_rules( $source_id );
            if ( is_wp_error( $applied ) ) { $this->redirect_with_notice( $return_tab, $applied->get_error_message(), 'error', array( 'source_id' => $source_id ) ); }
            /* translators: 1: events processed, 2: ready, 3: requiring attention. */
            $message = sprintf( __( 'Choices saved and %1$d events checked again: %2$d are good to go and %3$d need your help.', 'great-imports' ), (int) $applied['processed'], (int) $applied['ready'], (int) $applied['held'] );
            $this->redirect_with_notice( $return_tab, $message, 'success', array( 'source_id' => $source_id ) );
        }
        $this->redirect_with_notice( $return_tab, __( 'Choices saved. The link was not checked again.', 'great-imports' ), 'success', array( 'source_id' => $source_id ) );
    }

    public function handle_apply_source_rules(): void {
        $this->require_access();
        $source_id = absint( $_POST['source_id'] ?? 0 );
        $return_tab = $this->source_tab( $source_id );
        check_admin_referer( 'gi_apply_source_rules_' . $source_id );
        $result = GI_Runner::reapply_source_rules( $source_id );
        if ( is_wp_error( $result ) ) {
            $this->redirect_with_notice( $return_tab, $result->get_error_message(), 'error', array( 'source_id' => $source_id ) );
        }
        /* translators: 1: candidates processed, 2: ready, 3: requiring attention, 4: failed. */
        $message = sprintf( __( '%1$d current candidates re-evaluated: %2$d ready, %3$d still need attention, %4$d failed.', 'great-imports' ), (int) $result['processed'], (int) $result['ready'], (int) $result['held'], (int) $result['failed'] );
        $this->redirect_with_notice( $return_tab, $message, 'success', array( 'source_id' => $source_id ) );
    }

    public function handle_run_source(): void {
        $this->require_access();
        $source_id = absint( $_POST['source_id'] ?? 0 );
        $return_tab = $this->source_tab( $source_id );
        check_admin_referer( 'gi_run_source_' . $source_id );
        $result = GI_Runner::run_source( $source_id, 'manual' );
        if ( is_wp_error( $result ) ) {
            $this->redirect_with_notice( $return_tab, $result->get_error_message(), 'error', array( 'source_id' => $source_id ) );
        }
        $summary = $result['summary'];
        /* translators: 1: events collected, 2: ready, 3: requiring review, 4: imported or updated. */
        $message = sprintf( __( 'Run complete: %1$d collected, %2$d ready, %3$d need review, %4$d imported or updated.', 'great-imports' ), $summary['collected'], $summary['ready'], $summary['held'], $summary['imported'] + $summary['updated'] );
        $removed = ! empty( $result['source_removed'] );
        $this->redirect_with_notice( $return_tab, $removed ? $message . ' ' . __( 'The completed Run once source was removed from the active queue. Its history is retained.', 'great-imports' ) : $message, 'success', $removed ? array() : array( 'source_id' => $source_id ) );
    }

    public function handle_toggle_source(): void {
        $this->require_access();
        $source_id = absint( $_POST['source_id'] ?? 0 );
        check_admin_referer( 'gi_toggle_source_' . $source_id );
        $source = GI_Storage::get_source( $source_id );
        if ( ! $source || empty( $source['is_saved'] ) ) {
            $this->redirect_with_notice( 'recurring', __( 'Recurring source not found.', 'great-imports' ), 'error' );
        }
        $enabled = empty( $source['schedule']['enabled'] ) ? 1 : 0;
        $result = GI_Storage::update_source( $source_id, array( 'schedule' => array( 'enabled' => $enabled, 'next_run_gmt' => '' ) ) );
        if ( is_wp_error( $result ) ) {
            $this->redirect_with_notice( 'recurring', $result->get_error_message(), 'error', array( 'source_id' => $source_id ) );
        }
        if ( $enabled ) {
            GI_Scheduler::advance_source( $source_id, true );
        }
        $this->redirect_with_notice( 'recurring', $enabled ? __( 'Automatic checks are on.', 'great-imports' ) : __( 'Automatic checks are off. You can still check the link whenever you want.', 'great-imports' ), 'success', array( 'source_id' => $source_id ) );
    }

    public function handle_delete_source(): void {
        $this->require_access();
        $source_id = absint( $_POST['source_id'] ?? 0 );
        check_admin_referer( 'gi_delete_source_' . $source_id );
        $return_tab = $this->source_tab( $source_id );
        GI_Storage::delete_source( $source_id );
        $this->redirect_with_notice( $return_tab, __( 'Source and its Great Imports candidates were deleted. Events Manager records were not deleted.', 'great-imports' ), 'success' );
    }

    public function handle_restore_source(): void {
        $this->require_access();
        $source_id = absint( $_POST['source_id'] ?? 0 );
        check_admin_referer( 'gi_restore_source_' . $source_id );
        $result = GI_Storage::restore_source_to_queue( $source_id );
        if ( is_wp_error( $result ) ) {
            $this->redirect_with_notice( 'runs', $result->get_error_message(), 'error' );
        }
        $this->redirect_with_notice( $this->source_tab( $source_id ), __( 'Source restored to the active queue.', 'great-imports' ), 'success', array( 'source_id' => $source_id ) );
    }

    public function handle_candidate_action(): void {
        $this->require_access();
        $candidate_id = absint( $_POST['candidate_id'] ?? 0 );
        check_admin_referer( 'gi_candidate_action_' . $candidate_id );
        $candidate = GI_Storage::get_candidate( $candidate_id );
        if ( ! $candidate ) {
            $this->redirect_with_notice( 'sources', __( 'That event candidate no longer exists.', 'great-imports' ), 'error' );
        }
        $source_id = absint( $_POST['source_id'] ?? $candidate['source_id'] ?? 0 );
        $return_tab = $this->source_tab( $source_id );
        $return_args = $source_id ? array( 'source_id' => $source_id ) : array();
        $action = sanitize_key( $_POST['candidate_action'] ?? 'save' );

        if ( ! empty( $_POST['quick_action'] ) || in_array( $action, array( 'ignore', 'restore' ), true ) ) {
            if ( 'ignore' === $action ) {
                $result = GI_Storage::update_candidate( $candidate_id, array( 'status' => 'ignored', 'hold_reasons' => array(), 'last_error' => '' ) );
            } elseif ( 'restore' === $action ) {
                $result = GI_Storage::update_candidate( $candidate_id, array( 'status' => 'held', 'last_error' => '' ) );
            } elseif ( in_array( $action, array( 'draft', 'publish' ), true ) ) {
                $result = GI_Runner::import_candidate( $candidate_id, $action );
            } else {
                $result = new WP_Error( 'gi_quick_action', __( 'That quick action is not available.', 'great-imports' ) );
            }
            if ( is_wp_error( $result ) ) {
                $this->redirect_with_notice( $return_tab, $result->get_error_message(), 'error', $return_args );
            }
            $removed = GI_Storage::maybe_remove_completed_run_once_from_queue( $source_id );
            if ( 'draft' === $action ) {
                $message = __( 'Event imported as draft.', 'great-imports' );
            } elseif ( 'publish' === $action ) {
                $message = __( 'Event published.', 'great-imports' );
            } elseif ( 'ignore' === $action ) {
                $message = __( 'Event ignored.', 'great-imports' );
            } elseif ( 'restore' === $action ) {
                $message = __( 'Event restored for review.', 'great-imports' );
            } else {
                $message = __( 'Event processed.', 'great-imports' );
            }
            if ( $removed ) { $message .= ' ' . __( 'The completed Run once source was removed from the active queue. Its history is retained.', 'great-imports' ); }
            $this->redirect_with_notice( $return_tab, $message, 'success', $removed ? array() : $return_args );
        }

        $changes = $candidate;
        foreach ( array( 'title', 'start_date', 'end_date', 'timezone', 'event_url', 'ticket_url', 'price', 'currency', 'image_url', 'organizer' ) as $field ) {
            if ( array_key_exists( $field, $_POST ) ) {
                $value = wp_unslash( $_POST[ $field ] );
                $changes[ $field ] = in_array( $field, array( 'event_url', 'ticket_url', 'image_url' ), true ) ? GI_Utils::clean_url( $value ) : sanitize_text_field( $value );
            }
        }
        if ( array_key_exists( 'description', $_POST ) ) { $changes['description'] = GI_Utils::sanitize_html( wp_unslash( $_POST['description'] ) ); }
        if ( array_key_exists( 'start_time', $_POST ) ) { $changes['start_time'] = $this->normalize_time( $_POST['start_time'] ); }
        if ( array_key_exists( 'end_time', $_POST ) ) { $changes['end_time'] = $this->normalize_time( $_POST['end_time'] ); }
        $changes['all_day'] = empty( $_POST['all_day'] ) ? false : true;
        if ( $changes['all_day'] ) { $changes['start_time'] = '00:00:00'; $changes['end_time'] = '23:59:59'; }
        if ( array_key_exists( 'categories', $_POST ) ) { $changes['categories'] = $this->posted_csv( 'categories' ); }
        if ( array_key_exists( 'tags', $_POST ) ) { $changes['tags'] = $this->posted_csv( 'tags' ); }
        if ( array_key_exists( 'structure', $_POST ) ) {
            $changes['structure'] = 'festival' === sanitize_key( $_POST['structure'] ) ? 'festival' : 'auto';
            $changes['festival_slots'] = 'festival' === $changes['structure'] ? $this->posted_festival_slots() : array();
            $changes['festival_annual'] = 'festival' === $changes['structure'] && ! empty( $_POST['festival_annual'] );
        }
        if ( array_key_exists( 'recurrence_mode', $_POST ) ) {
            $changes['recurrence_mode'] = 'series' === sanitize_key( $_POST['recurrence_mode'] ) ? 'series' : 'single';
            $changes['recurrence_frequency'] = in_array( sanitize_key( $_POST['recurrence_frequency'] ?? 'weekly' ), array( 'daily', 'weekly', 'monthly' ), true ) ? sanitize_key( $_POST['recurrence_frequency'] ?? 'weekly' ) : 'weekly';
            $changes['recurrence_interval'] = min( 365, max( 1, absint( $_POST['recurrence_interval'] ?? 1 ) ) );
            $changes['recurrence_count'] = min( 500, max( 0, absint( $_POST['recurrence_count'] ?? 0 ) ) );
            $changes['recurrence_until'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_POST['recurrence_until'] ?? '' ) ) ? sanitize_text_field( $_POST['recurrence_until'] ) : '';
            $changes['recurrence_weekdays'] = array_values( array_intersect( array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ), array_map( static fn( $day ) => strtoupper( sanitize_text_field( wp_unslash( (string) $day ) ) ), (array) ( $_POST['recurrence_weekdays'] ?? array() ) ) ) );
        }

        $selection = sanitize_text_field( wp_unslash( $_POST['location_selection'] ?? 'detected' ) );
        $original_location_label = GI_Utils::public_location_name( $candidate );
        $original_parent = sanitize_text_field( $candidate['parent_location_name'] ?? '' );
        $original_stage = sanitize_text_field( $candidate['stage_name'] ?? '' );
        if ( str_starts_with( $selection, 'existing:' ) ) {
            $location_id = absint( substr( $selection, 9 ) );
            $changes['em_location_id'] = $location_id;
            foreach ( GI_Events_Manager::list_locations() as $location ) {
                if ( $location_id !== (int) ( $location['location_id'] ?? 0 ) ) { continue; }
                foreach ( array( 'location_name', 'location_address', 'location_city', 'location_state', 'location_postcode', 'location_country' ) as $field ) {
                    $changes[ $field ] = sanitize_text_field( $location[ $field ] ?? '' );
                }
                $selected_name = sanitize_text_field( $location['location_name'] ?? '' );
                $preserve_stage = $original_stage && $original_parent && GI_Utils::normalize_text( $selected_name ) === GI_Utils::normalize_text( $original_parent );
                $changes['parent_location_name'] = $preserve_stage ? $selected_name : '';
                $changes['stage_name'] = $preserve_stage ? $original_stage : '';
                break;
            }
        } else {
            // Detected locations remain fully editable. The posted fields are
            // the authoritative candidate details until an existing Events
            // Manager location is explicitly selected.
            $changes['em_location_id'] = 0;
            foreach ( array( 'location_name', 'parent_location_name', 'stage_name', 'location_address', 'location_city', 'location_state', 'location_postcode', 'location_country' ) as $field ) {
                $changes[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
            }
        }
        $changes['conflicts'] = array();
        $changes['hold_reasons'] = array();
        $changes['status'] = 'held';
        if ( isset( $_POST['explicit_content_approved'] ) || in_array( ( $candidate['explicit_content']['decision'] ?? 'allow' ), array( 'review', 'block' ), true ) ) {
            $changes['explicit_content_approved'] = empty( $_POST['explicit_content_approved'] ) ? 0 : 1;
        }
        $changes['method'] = 'manual';
        $changes['method_priority'] = GI_Normalizer::priority( 'manual' );
        $changes = GI_Utils::normalize_location_fields( $changes );
        $changes = GI_Normalizer::finalize_candidate( $changes );
        $content_assessment = GI_Runner::assess_candidate_content( $changes );
        $changes = GI_Normalizer::finalize_candidate( $content_assessment['candidate'] );
        $saved = GI_Storage::update_candidate( $candidate_id, $changes );
        if ( is_wp_error( $saved ) ) {
            $this->redirect_with_notice( $return_tab, $saved->get_error_message(), 'error', $return_args );
        }

        if ( ! empty( $_POST['apply_location_rule'] ) && $source_id && $original_location_label ) {
            $source = GI_Storage::get_source( $source_id );
            if ( $source ) {
                $mappings = (array) ( $source['rules']['location_mappings'] ?? array() );
                $new_mapping = array(
                    'match' => $original_location_label,
                    'em_location_id' => absint( $saved['em_location_id'] ?? $changes['em_location_id'] ?? 0 ),
                    'location_name' => sanitize_text_field( $saved['location_name'] ?? $changes['location_name'] ?? '' ),
                    'stage_name' => sanitize_text_field( $saved['stage_name'] ?? $changes['stage_name'] ?? '' ),
                );
                $replaced = false;
                foreach ( $mappings as $index => $mapping ) {
                    if ( 0 === strcasecmp( trim( (string) ( $mapping['match'] ?? '' ) ), $original_location_label ) ) {
                        $mappings[ $index ] = $new_mapping;
                        $replaced = true;
                        break;
                    }
                }
                if ( ! $replaced ) { $mappings[] = $new_mapping; }
                GI_Storage::update_source( $source_id, array( 'rules' => array( 'location_mappings' => $mappings ) ) );
                GI_Runner::reapply_source_rules( $source_id );
            }
        }

        if ( 'ignore' === $action ) {
            GI_Storage::update_candidate( $candidate_id, array( 'status' => 'ignored', 'hold_reasons' => array() ) );
        } elseif ( in_array( $action, array( 'draft', 'publish' ), true ) ) {
            $result = GI_Runner::import_candidate( $candidate_id, $action );
            if ( is_wp_error( $result ) ) {
                $this->redirect_with_notice( $return_tab, $result->get_error_message(), 'error', $return_args );
            }
        }
        $removed = GI_Storage::maybe_remove_completed_run_once_from_queue( $source_id );
        if ( 'draft' === $action ) {
            $message = __( 'Event imported as draft.', 'great-imports' );
        } elseif ( 'publish' === $action ) {
            $message = __( 'Event published.', 'great-imports' );
        } elseif ( 'ignore' === $action ) {
            $message = __( 'Event ignored.', 'great-imports' );
        } else {
            $message = __( 'Event changes saved.', 'great-imports' );
        }
        if ( $removed ) { $message .= ' ' . __( 'The completed Run once source was removed from the active queue. Its history is retained.', 'great-imports' ); }
        $this->redirect_with_notice( $return_tab, $message, 'success', $removed ? array() : $return_args );
    }
    public function handle_bulk_candidates(): void {
        $this->require_access();
        check_admin_referer( 'gi_bulk_candidates' );
        $ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['candidate_ids'] ?? array() ) ) ) );
        $action = sanitize_key( $_POST['bulk_action'] ?? '' );
        $source_id = absint( $_POST['source_id'] ?? 0 );
        $return_tab = $this->source_tab( $source_id );
        if ( in_array( $action, array( 'draft_all_ready', 'publish_all_ready' ), true ) ) {
            $ready_candidates = GI_Storage::list_candidates( array( 'ready' ), $source_id, -1 );
            $ids = array_values( array_filter( array_map( static fn( array $candidate ): int => absint( $candidate['id'] ?? 0 ), $ready_candidates ) ) );
            $action = 'publish_all_ready' === $action ? 'publish' : 'draft';
        }
        if ( ! $ids || ! in_array( $action, array( 'draft', 'publish', 'ignore' ), true ) ) {
            $this->redirect_with_notice( $return_tab, __( 'Choose events and an action.', 'great-imports' ), 'error', array( 'source_id' => $source_id ) );
        }
        $success = 0; $failed = 0;
        foreach ( $ids as $id ) {
            $result = 'ignore' === $action ? GI_Storage::update_candidate( $id, array( 'status' => 'ignored', 'hold_reasons' => array() ) ) : GI_Runner::import_candidate( $id, $action );
            is_wp_error( $result ) ? ++$failed : ++$success;
        }
        $removed = GI_Storage::maybe_remove_completed_run_once_from_queue( $source_id );
        if ( 'draft' === $action ) {
            /* translators: 1: successful imports, 2: failed imports. */
            $message = sprintf( __( 'Import selected as drafts complete: %1$d succeeded, %2$d failed.', 'great-imports' ), $success, $failed );
        } elseif ( 'publish' === $action ) {
            /* translators: 1: successful publishes, 2: failed publishes. */
            $message = sprintf( __( 'Publish selected complete: %1$d succeeded, %2$d failed.', 'great-imports' ), $success, $failed );
        } else {
            /* translators: 1: successfully ignored events, 2: failed operations. */
            $message = sprintf( __( 'Ignore selected complete: %1$d succeeded, %2$d failed.', 'great-imports' ), $success, $failed );
        }
        if ( $removed ) { $message .= ' ' . __( 'The completed Run once source was removed from the active queue. Its history is retained.', 'great-imports' ); }
        $this->redirect_with_notice( $return_tab, $message, $failed ? 'warning' : 'success', $removed ? array() : array( 'source_id' => $source_id ) );
    }
    public function handle_save_settings(): void {
        $this->require_access();
        check_admin_referer( 'gi_save_settings' );
        GI_Storage::save_settings( wp_unslash( $_POST ) );
        $this->redirect_with_notice( 'settings', __( 'Settings saved.', 'great-imports' ), 'success' );
    }

    public function handle_clear_run_history(): void {
        $this->require_access();
        check_admin_referer( 'gi_clear_run_history' );
        $count = GI_Storage::clear_run_history();
        /* translators: %d: deleted import activity record count. */
        $this->redirect_with_notice( 'settings', sprintf( __( '%d Great Imports run records deleted.', 'great-imports' ), $count ), 'success' );
    }

    public function handle_reset_plugin_data(): void {
        $this->require_access();
        check_admin_referer( 'gi_reset_plugin_data' );
        GI_Storage::reset_plugin_data();
        $this->redirect_with_notice( 'new', __( 'Great Imports data was reset. Imported Events Manager records were not deleted.', 'great-imports' ), 'success' );
    }

    public function handle_download_diagnostics(): void {
        $this->require_access();
        check_admin_referer( 'gi_download_diagnostics' );
        $filename = 'great-imports-diagnostics-' . gmdate( 'Ymd-His' ) . '.json';
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( GI_Storage::diagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    public function handle_download_run(): void {
        $this->require_access();
        $run_id = absint( $_POST['run_id'] ?? 0 );
        check_admin_referer( 'gi_download_run_' . $run_id );
        $run = GI_Storage::get_run( $run_id );
        if ( ! $run ) {
            wp_die( esc_html__( 'Run record not found.', 'great-imports' ) );
        }
        $filename = 'great-imports-run-' . $run_id . '-' . gmdate( 'Ymd-His' ) . '.json';
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( $run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    private function require_access(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage Great Imports.', 'great-imports' ) );
        }
    }

    private function source_tab( int|array $source ): string {
        if ( is_int( $source ) ) {
            $source = $source ? (array) GI_Storage::get_source( $source ) : array();
        }
        return ! empty( $source['is_saved'] ) ? 'recurring' : 'sources';
    }

    private function redirect_with_notice( string $tab, string $message, string $type = 'success', array $args = array() ): never {
        set_transient( 'gi_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 60 );
        $url = add_query_arg( array_merge( array( 'page' => 'great-imports', 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }

    private function render_notice(): void {
        $key = 'gi_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( $notice ) {
            delete_transient( $key );
            $type = in_array( $notice['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
            echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible gi-action-notice"><p>' . esc_html( $notice['message'] ?? '' ) . '</p></div>';
        }
        $repair = get_option( 'gi_last_queue_repair', array() );
        if ( is_array( $repair ) && GI_VERSION === ( $repair['version'] ?? '' ) && empty( $repair['shown'] ) ) {
            // Queue housekeeping is automatic and requires no user decision.
            // Record that it completed without adding a distracting admin notice.
            $repair['shown'] = 1;
            update_option( 'gi_last_queue_repair', $repair, false );
        }
    }

    private function render_pagination( int $current, int $total, array $args, string $page_key = 'gi_paged' ): void {
        if ( $total <= 1 ) {
            return;
        }
        $page_key = sanitize_key( $page_key ) ?: 'gi_paged';
        echo '<nav class="gi-pagination" aria-label="' . esc_attr__( 'Pagination', 'great-imports' ) . '">';
        if ( $current > 1 ) {
            echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( array( 'page' => 'great-imports', $page_key => $current - 1 ), $args ), admin_url( 'admin.php' ) ) ) . '">← ' . esc_html__( 'Previous', 'great-imports' ) . '</a>';
        }
        $start = max( 1, $current - 2 );
        $end = min( $total, $current + 2 );
        for ( $page = $start; $page <= $end; $page++ ) {
            if ( $page === $current ) {
                echo '<span class="button is-current" aria-current="page">' . esc_html( (string) $page ) . '</span>';
            } else {
                echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( array( 'page' => 'great-imports', $page_key => $page ), $args ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( (string) $page ) . '</a>';
            }
        }
        if ( $current < $total ) {
            echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( array( 'page' => 'great-imports', $page_key => $current + 1 ), $args ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Next', 'great-imports' ) . ' →</a>';
        }
        echo '</nav>';
    }

    private function summary_card( string $label, string $value, string $status ): void {
        echo '<article class="gi-summary-card gi-summary-' . esc_attr( $status ) . '"><strong>' . esc_html( $value ) . '</strong><span>' . esc_html( $label ) . '</span></article>';
    }

    private function text_field( string $name, string $label, string $value, string $class = '', string $type = 'text' ): void {
        $classes = 'gi-field' . ( 'wide' === $class ? ' gi-field-wide' : '' );
        $id = wp_unique_id( 'gi-' . sanitize_html_class( $name ) . '-' );
        $limits = array(
            'lookback' => array( 0, 365 ),
            'lookahead' => array( 1, 730 ),
            'default_lookback' => array( 0, 365 ),
            'default_lookahead' => array( 1, 730 ),
            'request_timeout' => array( 5, 60 ),
            'max_events_per_run' => array( 10, 500 ),
            'max_total_events_per_run' => array( 50, 5000 ),
            'max_discovered_pages' => array( 1, 50 ),
            'recurrence_interval' => array( 1, 365 ),
            'recurrence_count' => array( 0, 500 ),
        );
        $attributes = '';
        if ( 'number' === $type && isset( $limits[ $name ] ) ) {
            $attributes = ' min="' . esc_attr( (string) $limits[ $name ][0] ) . '" max="' . esc_attr( (string) $limits[ $name ][1] ) . '"';
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attributes contains only escaped min/max values from the fixed allowlist above.
        echo '<div class="' . esc_attr( $classes ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $attributes . '></div>';
    }

    private function festival_slot_row( array $slot ): void {
        $field = static function ( string $name, string $label, string $value, string $type = 'text', string $class = '' ): void {
            echo '<label class="gi-festival-slot-field ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="festival_slot_' . esc_attr( $name ) . '[]" value="' . esc_attr( $value ) . '"></label>';
        };
        echo '<div class="gi-festival-slot" data-gi-festival-slot>';
        $day_label = sanitize_text_field( $slot['day_label'] ?? '' );
        echo '<input type="hidden" name="festival_slot_day[]" value="' . esc_attr( $day_label ) . '">';
        if ( $day_label ) {
            echo '<span class="gi-festival-day-label">' . esc_html( $day_label ) . '</span>';
        }
        $field( 'date', __( 'Date', 'great-imports' ), (string) ( $slot['date'] ?? '' ), 'date' );
        $field( 'start_time', __( 'Starts', 'great-imports' ), substr( (string) ( $slot['start_time'] ?? '' ), 0, 5 ), 'time' );
        $field( 'end_time', __( 'Ends', 'great-imports' ), substr( (string) ( $slot['end_time'] ?? '' ), 0, 5 ), 'time' );
        $field( 'title', __( 'Performer or activity', 'great-imports' ), (string) ( $slot['title'] ?? '' ), 'text', 'gi-festival-slot-title' );
        $field( 'stage', __( 'Stage or area', 'great-imports' ), (string) ( $slot['stage_name'] ?? '' ) );
        $field( 'location', __( 'Place', 'great-imports' ), (string) ( $slot['location_name'] ?? '' ) );
        $field( 'ticket_url', __( 'Ticket link (optional)', 'great-imports' ), (string) ( $slot['ticket_url'] ?? '' ), 'url', 'gi-festival-slot-ticket' );
        echo '<button type="button" class="button-link-delete gi-remove-festival-slot" data-gi-remove-festival-slot aria-label="' . esc_attr__( 'Remove this time slot', 'great-imports' ) . '">×</button></div>';
    }

    private function secret_field( string $name, string $label, string $value ): void {
        echo '<div class="gi-field"><label>' . esc_html( $label ) . '</label><input type="password" name="' . esc_attr( $name ) . '" value="" autocomplete="new-password" placeholder="' . esc_attr( $value ? __( 'Configured — enter a new value to replace it', 'great-imports' ) : __( 'Not configured', 'great-imports' ) ) . '"><small>' . esc_html( $value ? __( 'A credential is stored.', 'great-imports' ) : __( 'No credential is stored.', 'great-imports' ) ) . '</small>';
        if ( $value ) {
            echo '<label class="gi-checkbox"><input type="checkbox" name="clear_' . esc_attr( $name ) . '" value="1"><span>' . esc_html__( 'Remove the saved credential', 'great-imports' ) . '</span></label>';
        }
        echo '</div>';
    }

    private function timezone_options( string $selected ): string {
        $site_timezone = wp_timezone_string();
        $zones = array_values( array_unique( array_filter( array(
            $selected,
            $site_timezone,
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Phoenix',
            'America/Los_Angeles',
            'America/Anchorage',
            'Pacific/Honolulu',
            'UTC',
        ) ) ) );
        $html = '';
        foreach ( $zones as $zone ) {
            if ( ! in_array( $zone, timezone_identifiers_list(), true ) ) {
                continue;
            }
            /* translators: %s: timezone identifier. */
            $label = $zone === $site_timezone ? sprintf( __( '%s (site timezone)', 'great-imports' ), $zone ) : $zone;
            $html .= '<option value="' . esc_attr( $zone ) . '" ' . selected( $selected, $zone, false ) . '>' . esc_html( $label ) . '</option>';
        }
        return $html;
    }

    private function action_options( string $selected ): string {
        $html = '';
        foreach ( array( 'review' => __( 'Let me review events first', 'great-imports' ), 'draft' => __( 'Add ready events as drafts', 'great-imports' ), 'publish' => __( 'Publish ready events automatically', 'great-imports' ) ) as $value => $label ) {
            $html .= '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        return $html;
    }

    private function action_label( string $action ): string {
        return array( 'review' => __( 'Review first', 'great-imports' ), 'draft' => __( 'Add as drafts', 'great-imports' ), 'publish' => __( 'Publish automatically', 'great-imports' ) )[ $action ] ?? ucfirst( $action );
    }

    private function location_mapping_row( int $index, array $mapping ): void {
        $current_id = absint( $mapping['em_location_id'] ?? 0 );
        $current = sanitize_text_field( $mapping['location_name'] ?? '' );
        $known = false;
        echo '<div class="gi-location-rule-row" data-gi-mapping-row>';
        echo '<label><span>' . esc_html__( 'Source says', 'great-imports' ) . '</span><input type="text" name="mapping_match[]" value="' . esc_attr( $mapping['match'] ?? '' ) . '" placeholder="' . esc_attr__( 'The Parlor', 'great-imports' ) . '"></label>';
        echo '<label><span>' . esc_html__( 'Use this Events Manager location', 'great-imports' ) . '</span><select name="mapping_location_id[]"><option value="0">' . esc_html__( 'Choose a location', 'great-imports' ) . '</option>';
        foreach ( GI_Events_Manager::list_locations() as $location ) {
            $name = sanitize_text_field( $location['location_name'] ?? '' );
            if ( ! $name ) { continue; }
            $location_id = absint( $location['location_id'] ?? 0 );
            if ( $location_id && $location_id === $current_id ) { $known = true; }
            $label = trim( $name . ( ! empty( $location['location_address'] ) ? ' — ' . $location['location_address'] : '' ) );
            echo '<option value="' . esc_attr( (string) $location_id ) . '" ' . selected( $current_id, $location_id, false ) . '>' . esc_html( $label ) . '</option>';
        }
        if ( $current && ! $known ) {
            /* translators: %s: location name that is no longer matched. */
            echo '<option value="' . esc_attr( (string) $current_id ) . '" selected>' . esc_html( sprintf( __( '%s (not currently matched)', 'great-imports' ), $current ) ) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>' . esc_html__( 'Stage, room, or area', 'great-imports' ) . '</span><input type="text" name="mapping_stage[]" value="' . esc_attr( $mapping['stage_name'] ?? '' ) . '" placeholder="' . esc_attr__( 'The Parlour', 'great-imports' ) . '"></label>';
        echo '<button type="button" class="button-link-delete" data-gi-remove-mapping aria-label="' . esc_attr__( 'Remove location rule', 'great-imports' ) . '">×</button></div>';
    }

    private function toggle_source_button( array $source ): void {
        $source_id = absint( $source['id'] ?? 0 );
        $enabled = ! empty( $source['schedule']['enabled'] );
        echo '<form class="gi-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        wp_nonce_field( 'gi_toggle_source_' . $source_id );
        echo '<input type="hidden" name="action" value="gi_toggle_source"><input type="hidden" name="source_id" value="' . esc_attr( (string) $source_id ) . '"><button class="button">' . esc_html( $enabled ? __( 'Pause', 'great-imports' ) : __( 'Resume', 'great-imports' ) ) . '</button></form>';
    }

    private function run_source_button( int $source_id, bool $primary = false ): void {
        echo '<form class="gi-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        wp_nonce_field( 'gi_run_source_' . $source_id );
        echo '<input type="hidden" name="action" value="gi_run_source"><input type="hidden" name="source_id" value="' . esc_attr( $source_id ) . '"><button class="button ' . ( $primary ? 'button-primary' : '' ) . '">' . esc_html__( 'Check now', 'great-imports' ) . '</button></form>';
    }

    private function delete_source_button( int $source_id ): void {
        echo '<form class="gi-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        wp_nonce_field( 'gi_delete_source_' . $source_id );
        echo '<input type="hidden" name="action" value="gi_delete_source"><input type="hidden" name="source_id" value="' . esc_attr( $source_id ) . '"><button class="button button-link-delete">' . esc_html__( 'Delete source', 'great-imports' ) . '</button></form>';
    }

    private function format_location_address( array $location ): string {
        $street = trim( sanitize_text_field( $location['location_address'] ?? '' ) );
        $city = trim( sanitize_text_field( $location['location_city'] ?? '' ) );
        $state = trim( sanitize_text_field( $location['location_state'] ?? '' ) );
        $postcode = trim( sanitize_text_field( $location['location_postcode'] ?? '' ) );
        $country = trim( sanitize_text_field( $location['location_country'] ?? '' ) );
        $region = trim( $state . ( $postcode ? ' ' . $postcode : '' ) );
        $parts = array_values( array_filter( array( $street, $city, $region ) ) );
        if ( $country && ! in_array( strtoupper( $country ), array( 'US', 'USA', 'UNITED STATES' ), true ) ) {
            $parts[] = $country;
        }
        return implode( ', ', $parts );
    }

    private function candidate_date_label( array $candidate ): string {
        $date = sanitize_text_field( $candidate['start_date'] ?? '' );
        if ( ! $date ) {
            return __( 'Date missing', 'great-imports' );
        }
        $timezone_name = sanitize_text_field( $candidate['timezone'] ?? wp_timezone_string() );
        $timezone = in_array( $timezone_name, timezone_identifiers_list(), true ) ? new DateTimeZone( $timezone_name ) : wp_timezone();
        $start = GI_Utils::parse_datetime( trim( $date . ' ' . ( $candidate['start_time'] ?? '' ) ), $timezone );
        $label = $start ? wp_date( ! empty( $candidate['all_day'] ) ? 'M j, Y' : 'M j, Y g:i A', $start->getTimestamp(), $timezone ) : $date;
        if ( ! empty( $candidate['end_date'] ) && $candidate['end_date'] !== $candidate['start_date'] ) {
            $end = GI_Utils::parse_datetime( $candidate['end_date'], $timezone );
            if ( $end ) {
                $label .= ' – ' . wp_date( 'M j, Y', $end->getTimestamp(), $timezone );
            }
        }
        return $label;
    }

    private function posted_location_mappings(): array {
        $post = wp_unslash( $_POST );
        $matches = array_map( 'sanitize_text_field', (array) ( $post['mapping_match'] ?? array() ) );
        $location_ids = array_map( 'absint', (array) ( $post['mapping_location_id'] ?? array() ) );
        $stages = array_map( 'sanitize_text_field', (array) ( $post['mapping_stage'] ?? array() ) );
        $locations = GI_Events_Manager::list_locations();
        $locations_by_id = array();
        foreach ( $locations as $location ) {
            $locations_by_id[ absint( $location['location_id'] ?? 0 ) ] = $location;
        }
        $mappings = array();
        $count = max( count( $matches ), count( $location_ids ), count( $stages ) );
        for ( $index = 0; $index < $count; $index++ ) {
            $match = $matches[ $index ] ?? '';
            $location_id = $location_ids[ $index ] ?? 0;
            $stage_name = $stages[ $index ] ?? '';
            $location_name = sanitize_text_field( $locations_by_id[ $location_id ]['location_name'] ?? '' );
            if ( ! $match || ! $location_id || ! $location_name ) { continue; }
            $key = strtolower( trim( preg_replace( '/\s+/', ' ', $match ) ) );
            $mappings[ $key ] = array( 'match' => $match, 'em_location_id' => $location_id, 'location_name' => $location_name, 'stage_name' => $stage_name );
        }
        return array_values( $mappings );
    }

    private function posted_csv( string $key ): array {
        $value = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
        return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $value ) ) ) ) );
    }

    private function posted_festival_slots(): array {
        $post = wp_unslash( $_POST );
        $dates = array_map( 'sanitize_text_field', (array) ( $post['festival_slot_date'] ?? array() ) );
        $days = array_map( 'sanitize_text_field', (array) ( $post['festival_slot_day'] ?? array() ) );
        $starts = array_map( 'sanitize_text_field', (array) ( $post['festival_slot_start_time'] ?? array() ) );
        $ends = array_map( 'sanitize_text_field', (array) ( $post['festival_slot_end_time'] ?? array() ) );
        $titles = array_map( 'sanitize_text_field', (array) ( $post['festival_slot_title'] ?? array() ) );
        $stages = array_map( 'sanitize_text_field', (array) ( $post['festival_slot_stage'] ?? array() ) );
        $locations = array_map( 'sanitize_text_field', (array) ( $post['festival_slot_location'] ?? array() ) );
        $tickets = array_map( 'esc_url_raw', (array) ( $post['festival_slot_ticket_url'] ?? array() ) );
        $count = max( count( $dates ), count( $days ), count( $starts ), count( $ends ), count( $titles ), count( $stages ), count( $locations ), count( $tickets ) );
        $slots = array();
        for ( $index = 0; $index < $count; $index++ ) {
            $date = $dates[ $index ] ?? '';
            $title = $titles[ $index ] ?? '';
            if ( ! $date && ! $title ) {
                continue;
            }
            $slots[] = array(
                'date'          => $date,
                'day_label'     => $days[ $index ] ?? '',
                'start_time'    => $this->normalize_time( $starts[ $index ] ?? '' ),
                'end_time'      => $this->normalize_time( $ends[ $index ] ?? '' ),
                'title'         => $title,
                'stage_name'    => $stages[ $index ] ?? '',
                'location_name' => $locations[ $index ] ?? '',
                'ticket_url'    => GI_Utils::clean_url( $tickets[ $index ] ?? '' ),
            );
        }
        return $slots;
    }

    private function sanitize_timezone( string $timezone ): string {
        $timezone = sanitize_text_field( $timezone );
        return in_array( $timezone, timezone_identifiers_list(), true ) ? $timezone : wp_timezone_string();
    }

    private function normalize_time( string $time ): string {
        $time = sanitize_text_field( $time );
        return preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time . ':00' : $time;
    }
}
