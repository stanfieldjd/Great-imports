<?php
/**
 * Registers Great Imports internal post types.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Post_Types {
    /**
     * Register private candidate storage.
     */
    public static function register() {
        register_post_type(
            'gi_candidate',
            array(
                'labels'              => array(
                    'name'          => __( 'Great Import Candidates', 'great-imports' ),
                    'singular_name' => __( 'Great Import Candidate', 'great-imports' ),
                ),
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'supports'            => array( 'title', 'editor' ),
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'exclude_from_search' => true,
            )
        );
    }
}
