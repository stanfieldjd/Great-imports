<?php
/**
 * Adds multi-day date handling to the Great Imports candidate queue.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Multi_Day_Admin {
    /**
     * Register the admin asset hook.
     */
    public static function register_hooks() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Load the multi-day candidate date behavior only on the Great Imports screen.
     *
     * @param string $hook Current WordPress admin hook.
     */
    public static function enqueue_assets( $hook ) {
        if ( 'toplevel_page_great-imports' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'great-imports-multi-day-candidates',
            GREAT_IMPORTS_URL . 'assets/js/admin-multi-day-candidates.js',
            array(),
            GREAT_IMPORTS_VERSION,
            true
        );

        wp_localize_script(
            'great-imports-multi-day-candidates',
            'giMultiDayCandidates',
            array(
                'invalidRange' => __( 'The event end date and time must be after the start date and time.', 'great-imports' ),
                'notSet'       => __( 'Not set', 'great-imports' ),
            )
        );
    }
}

GI_Multi_Day_Admin::register_hooks();
