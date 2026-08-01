<?php
/**
 * Great Imports uninstall cleanup.
 *
 * Removes only Great Imports settings, run/source/candidate records, trace metadata,
 * stored source files, and scheduled hooks. Events Manager events, locations, and
 * Media Library attachments are deliberately retained.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'gi_run_due_sources' );

$gi_settings = (array) get_option( 'gi_settings', array() );
if ( array_key_exists( 'cleanup_on_uninstall', $gi_settings ) && empty( $gi_settings['cleanup_on_uninstall'] ) ) {
    return;
}

global $wpdb;
$source_ids = get_posts(
    array(
        'post_type'      => 'gi_source',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => -1,
    )
);
$upload_dir = wp_get_upload_dir();
$upload_base = wp_normalize_path( (string) ( $upload_dir['basedir'] ?? '' ) );
foreach ( $source_ids as $source_id ) {
    $data = get_post_meta( (int) $source_id, '_gi_source_data', true );
    $file_path = wp_normalize_path( (string) ( is_array( $data ) ? ( $data['file_path'] ?? '' ) : '' ) );
    if ( $file_path && $upload_base && str_starts_with( $file_path, trailingslashit( $upload_base ) ) && is_file( $file_path ) ) {
        wp_delete_file( $file_path );
    }
}

$internal_ids = get_posts(
    array(
        'post_type'      => array( 'gi_source', 'gi_candidate', 'gi_run' ),
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => -1,
    )
);
foreach ( $internal_ids as $post_id ) {
    wp_delete_post( (int) $post_id, true );
}

delete_option( 'gi_settings' );
delete_option( 'gi_plugin_version' );
delete_option( 'gi_last_queue_repair' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove wildcarded plugin-owned options.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, 'gi_run_lock_%' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove wildcarded plugin-owned metadata.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, '\\_gi\\_%' ) );
