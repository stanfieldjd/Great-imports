<?php
/**
 * Extracts non-decisional evidence from captured HTML.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_HTML_Evidence_Extractor {
    /**
     * Extract broad evidence from an HTML body without deciding relevance.
     *
     * @param string $html HTML body.
     * @param string $base_url Base URL.
     * @return array<string,mixed>
     */
    public function extract( $html, $base_url = '' ) {
        $html = (string) $html;

        return array(
            'label'          => 'html_extracted_evidence',
            'capture_type'   => 'html_extraction',
            'base_url'       => esc_url_raw( (string) $base_url ),
            'body_sha256'    => hash( 'sha256', $html ),
            'body_bytes'     => strlen( $html ),
            'meta_tags'      => $this->extract_meta_tags( $html ),
            'title_tags'     => $this->extract_title_tags( $html ),
            'links'          => $this->extract_attributes( $html, 'a', 'href' ),
            'images'         => $this->extract_attributes( $html, 'img', 'src' ),
            'canonical'      => $this->extract_link_rels( $html, 'canonical' ),
            'json_ld_blocks' => $this->extract_json_ld_blocks( $html ),
            'script_blocks'  => $this->extract_script_blocks( $html ),
        );
    }

    /**
     * Extract meta tags.
     *
     * @param string $html HTML.
     * @return array<int,array<string,string>>
     */
    private function extract_meta_tags( $html ) {
        $items = array();

        if ( preg_match_all( '/<meta\b[^>]*>/is', $html, $matches ) ) {
            foreach ( $matches[0] as $tag ) {
                $items[] = array(
                    'tag'      => $tag,
                    'name'     => $this->attribute_value( $tag, 'name' ),
                    'property' => $this->attribute_value( $tag, 'property' ),
                    'content'  => $this->attribute_value( $tag, 'content' ),
                );
            }
        }

        return $items;
    }

    /**
     * Extract title tags.
     *
     * @param string $html HTML.
     * @return array<int,string>
     */
    private function extract_title_tags( $html ) {
        $items = array();

        if ( preg_match_all( '/<title\b[^>]*>(.*?)<\/title>/is', $html, $matches ) ) {
            foreach ( $matches[1] as $value ) {
                $items[] = wp_strip_all_tags( html_entity_decode( $value ) );
            }
        }

        return $items;
    }

    /**
     * Extract a given attribute from all tags of a type.
     *
     * @param string $html HTML.
     * @param string $tag_name Tag name.
     * @param string $attribute Attribute name.
     * @return array<int,string>
     */
    private function extract_attributes( $html, $tag_name, $attribute ) {
        $items = array();

        if ( preg_match_all( '/<' . preg_quote( $tag_name, '/' ) . '\b[^>]*>/is', $html, $matches ) ) {
            foreach ( $matches[0] as $tag ) {
                $value = $this->attribute_value( $tag, $attribute );
                if ( '' !== $value ) {
                    $items[] = esc_url_raw( html_entity_decode( $value ) );
                }
            }
        }

        return array_values( array_unique( $items ) );
    }

    /**
     * Extract link rels.
     *
     * @param string $html HTML.
     * @param string $rel Rel value.
     * @return array<int,string>
     */
    private function extract_link_rels( $html, $rel ) {
        $items = array();

        if ( preg_match_all( '/<link\b[^>]*>/is', $html, $matches ) ) {
            foreach ( $matches[0] as $tag ) {
                if ( strtolower( $this->attribute_value( $tag, 'rel' ) ) !== strtolower( $rel ) ) {
                    continue;
                }

                $href = $this->attribute_value( $tag, 'href' );
                if ( '' !== $href ) {
                    $items[] = esc_url_raw( html_entity_decode( $href ) );
                }
            }
        }

        return array_values( array_unique( $items ) );
    }

    /**
     * Extract JSON-LD blocks.
     *
     * @param string $html HTML.
     * @return array<int,array<string,mixed>>
     */
    private function extract_json_ld_blocks( $html ) {
        $items = array();

        if ( preg_match_all( '/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
            foreach ( $matches[1] as $raw ) {
                $raw     = trim( html_entity_decode( $raw ) );
                $decoded = json_decode( $raw, true );
                $items[] = array(
                    'raw'        => $raw,
                    'sha256'     => hash( 'sha256', $raw ),
                    'decoded_ok' => is_array( $decoded ),
                    'decoded'    => is_array( $decoded ) ? $decoded : null,
                );
            }
        }

        return $items;
    }

    /**
     * Extract all script blocks without judging relevance.
     *
     * @param string $html HTML.
     * @return array<int,array<string,mixed>>
     */
    private function extract_script_blocks( $html ) {
        $items = array();

        if ( preg_match_all( '/<script\b([^>]*)>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $attributes = isset( $match[1] ) ? $match[1] : '';
                $body       = isset( $match[2] ) ? trim( $match[2] ) : '';
                $items[]    = array(
                    'type'       => $this->attribute_value( '<script ' . $attributes . '>', 'type' ),
                    'src'        => esc_url_raw( $this->attribute_value( '<script ' . $attributes . '>', 'src' ) ),
                    'body'       => $body,
                    'body_sha256'=> hash( 'sha256', $body ),
                    'body_bytes' => strlen( $body ),
                );
            }
        }

        return $items;
    }

    /**
     * Read an attribute from a tag.
     *
     * @param string $tag Tag HTML.
     * @param string $attribute Attribute name.
     */
    private function attribute_value( $tag, $attribute ) {
        if ( preg_match( '/' . preg_quote( $attribute, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match ) ) {
            return html_entity_decode( $match[2] );
        }

        return '';
    }
}
