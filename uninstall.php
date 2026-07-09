<?php
/**
 * Great Imports uninstall cleanup.
 *
 * @package GreatImports
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$delete_data = (bool) get_option( 'great_imports_delete_data_on_uninstall', false );

if ( ! $delete_data ) {
    return;
}

foreach ( array( 'gi_candidate', 'gi_evidence' ) as $post_type ) {
    $posts = get_posts(
        array(
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        )
    );

    foreach ( $posts as $post_id ) {
        wp_delete_post( (int) $post_id, true );
    }
}

delete_option( 'great_imports_delete_data_on_uninstall' );
delete_option( 'great_imports_eventbrite_private_token' );
