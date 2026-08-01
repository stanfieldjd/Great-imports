<?php

defined( 'ABSPATH' ) || exit;

final class GI_Plugin {
    private static ?GI_Plugin $instance = null;

    public static function instance(): GI_Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        add_action( 'init', array( 'GI_Storage', 'register_post_types' ) );
        add_action( 'init', array( 'GI_Scheduler', 'ensure_schedule' ), 20 );
        add_action( GI_Scheduler::HOOK, array( 'GI_Scheduler', 'run_due_sources' ) );

        if ( is_admin() ) {
            add_action( 'admin_init', array( $this, 'maybe_upgrade' ), 5 );
            GI_Admin::instance()->boot();
        }
    }

    public function maybe_upgrade(): void {
        $installed = (string) get_option( 'gi_plugin_version', '' );
        if ( GI_VERSION === $installed ) {
            return;
        }
        GI_Storage::register_post_types();
        GI_Storage::install_defaults();
        $repair = GI_Storage::repair_candidate_queue();
        $source_repair = GI_Storage::repair_duplicate_unrun_sources();
        $event_link_repair = GI_Storage::repair_events_manager_candidate_links();
        $location_country_repair = GI_Events_Manager::repair_invalid_us_location_countries();
        update_option( 'gi_last_queue_repair', array_merge( $repair, $source_repair, $event_link_repair, $location_country_repair, array( 'version' => GI_VERSION, 'time' => current_time( 'mysql' ) ) ), false );
        update_option( 'gi_plugin_version', GI_VERSION, false );
    }

    public static function activate(): void {
        GI_Storage::register_post_types();
        GI_Storage::install_defaults();
        $repair = GI_Storage::repair_candidate_queue();
        $source_repair = GI_Storage::repair_duplicate_unrun_sources();
        $event_link_repair = GI_Storage::repair_events_manager_candidate_links();
        $location_country_repair = GI_Events_Manager::repair_invalid_us_location_countries();
        update_option( 'gi_last_queue_repair', array_merge( $repair, $source_repair, $event_link_repair, $location_country_repair, array( 'version' => GI_VERSION, 'time' => current_time( 'mysql' ) ) ), false );
        update_option( 'gi_plugin_version', GI_VERSION, false );
        GI_Scheduler::ensure_schedule();
        flush_rewrite_rules( false );
    }

    public static function deactivate(): void {
        GI_Scheduler::clear_schedule();
        flush_rewrite_rules( false );
    }
}
