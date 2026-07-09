<?php
/**
 * Great Imports uninstall cleanup.
 *
 * @package GreatImports
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$post_types = array( 'gi_candidate', 'gi_evidence' );

$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
$post_ids     = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders)",
        $post_types
    )
);

if ( ! empty( $post_ids ) ) {
    $post_ids      = array_map( 'intval', $post_ids );
    $id_placeholds = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($id_placeholds)",
            $post_ids
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE ID IN ($id_placeholds)",
            $post_ids
        )
    );
}

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( '_gi_' ) . '%'
    )
);

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name IN ('great_imports_delete_data_on_uninstall', 'great_imports_eventbrite_private_token')",
        $wpdb->esc_like( 'great_imports_' ) . '%'
    )
);
