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

$candidates = get_posts(
    array(
        'post_type'      => 'gi_candidate',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    )
);

foreach ( $candidates as $candidate_id ) {
    wp_delete_post( (int) $candidate_id, true );
}

delete_option( 'great_imports_delete_data_on_uninstall' );
