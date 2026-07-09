<?php
/**
 * One-time Eventbrite importer.
 *
 * @package GreatImports
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GI_Eventbrite_Importer {
    /** @var GI_Url_Validator */
    private $validator;

    /** @var GI_Http_Client */
    private $http;

    /** @var GI_Jsonld_Parser */
    private $parser;

    /** @var GI_Candidate_Store */
    private $store;

    public function __construct( GI_Url_Validator $validator, GI_Http_Client $http, GI_Jsonld_Parser $parser, GI_Candidate_Store $store ) {
        $this->validator = $validator;
        $this->http      = $http;
        $this->parser    = $parser;
        $this->store     = $store;
    }

    /**
     * Run one Eventbrite URL import and store a review candidate.
     *
     * @param string $raw_url Submitted URL.
     * @return array{success:bool,message:string,post_id:int,updated:bool,event:array<string,mixed>}
     */
    public function import_once( $raw_url ) {
        $validated = $this->validator->validate_eventbrite_url( $raw_url );

        if ( ! $validated['valid'] ) {
            return array(
                'success' => false,
                'message' => $validated['error'],
                'post_id' => 0,
                'updated' => false,
                'event'   => array(),
            );
        }

        $fetched = $this->http->get_body( $validated['url'] );

        if ( ! $fetched['success'] ) {
            return array(
                'success' => false,
                'message' => $fetched['error'],
                'post_id' => 0,
                'updated' => false,
                'event'   => array(),
            );
        }

        $parsed = $this->parser->parse_event_from_html( $fetched['body'] );

        if ( ! $parsed['success'] ) {
            return array(
                'success' => false,
                'message' => $parsed['error'],
                'post_id' => 0,
                'updated' => false,
                'event'   => array(),
            );
        }

        $candidate                = $parsed['event'];
        $candidate['source_url']  = $validated['url'];
        $candidate['http_status'] = $fetched['status'];

        $stored = $this->store->save_event_candidate( $candidate );

        if ( ! $stored['success'] ) {
            return array(
                'success' => false,
                'message' => $stored['error'],
                'post_id' => 0,
                'updated' => false,
                'event'   => $candidate,
            );
        }

        return array(
            'success' => true,
            'message' => $stored['updated'] ? __( 'Existing Eventbrite candidate updated.', 'great-imports' ) : __( 'Eventbrite candidate created for review.', 'great-imports' ),
            'post_id' => $stored['post_id'],
            'updated' => $stored['updated'],
            'event'   => $candidate,
        );
    }
}
