<?php
/**
 * Manual and uninstall cleanup for Great Imports-owned data.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Data_Cleaner {
    /**
     * Remove only data owned by Great Imports.
     *
     * This intentionally does not delete Events Manager events, locations, tickets,
     * media, categories, tags, or venue records.
     *
     * @return array<string,int>
     */
    public static function cleanup() {
        global $wpdb;

        $counts = array(
            'candidate_posts_deleted'       => 0,
            'evidence_posts_deleted'        => 0,
            'postmeta_for_gi_posts_deleted' => 0,
            'orphan_gi_postmeta_deleted'    => 0,
            'options_deleted'               => 0,
            'transients_deleted'            => 0,
        );

        $post_types = array( 'gi_candidate', 'gi_evidence' );

        foreach ( $post_types as $post_type ) {
            $counts[ $post_type . '_found' ] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                    $post_type
                )
            );
        }

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

            $counts['postmeta_for_gi_posts_deleted'] = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($id_placeholds)",
                    $post_ids
                )
            );

            $counts['candidate_posts_deleted'] = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->posts} WHERE post_type = %s AND ID IN ($id_placeholds)",
                    array_merge( array( 'gi_candidate' ), $post_ids )
                )
            );

            $counts['evidence_posts_deleted'] = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->posts} WHERE post_type = %s AND ID IN ($id_placeholds)",
                    array_merge( array( 'gi_evidence' ), $post_ids )
                )
            );
        }

        $counts['orphan_gi_postmeta_deleted'] = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like( '_gi_' ) . '%'
            )
        );

        $counts['transients_deleted'] = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_great_imports_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_great_imports_' ) . '%'
            )
        );

        $counts['options_deleted'] = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name IN ('great_imports_delete_data_on_uninstall', 'great_imports_eventbrite_private_token')",
                $wpdb->esc_like( 'great_imports_' ) . '%'
            )
        );

        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }

        return $counts;
    }

    /**
     * Create a compact user-facing summary for admin notices.
     *
     * @param array<string,int> $counts Cleanup counts.
     */
    public static function summary_message( array $counts ) {
        return sprintf(
            __( 'Manual cleanup complete. Deleted %1$d candidates, %2$d evidence records, %3$d Great Imports metadata rows, %4$d options, and %5$d transients. Events Manager events, locations, media, tickets, categories, and tags were not touched.', 'great-imports' ),
            isset( $counts['candidate_posts_deleted'] ) ? (int) $counts['candidate_posts_deleted'] : 0,
            isset( $counts['evidence_posts_deleted'] ) ? (int) $counts['evidence_posts_deleted'] : 0,
            ( isset( $counts['postmeta_for_gi_posts_deleted'] ) ? (int) $counts['postmeta_for_gi_posts_deleted'] : 0 ) + ( isset( $counts['orphan_gi_postmeta_deleted'] ) ? (int) $counts['orphan_gi_postmeta_deleted'] : 0 ),
            isset( $counts['options_deleted'] ) ? (int) $counts['options_deleted'] : 0,
            isset( $counts['transients_deleted'] ) ? (int) $counts['transients_deleted'] : 0
        );
    }
}
