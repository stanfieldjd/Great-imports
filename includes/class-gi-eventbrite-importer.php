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

    /** @var GI_Eventbrite_API_Client */
    private $api_client;

    /** @var GI_Eventbrite_API_Normalizer */
    private $api_normalizer;

    public function __construct( GI_Url_Validator $validator, GI_Http_Client $http, GI_Jsonld_Parser $parser, GI_Candidate_Store $store, GI_Eventbrite_API_Client $api_client, GI_Eventbrite_API_Normalizer $api_normalizer ) {
        $this->validator      = $validator;
        $this->http           = $http;
        $this->parser         = $parser;
        $this->store          = $store;
        $this->api_client     = $api_client;
        $this->api_normalizer = $api_normalizer;
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

        $api_error = '';

        if ( $this->api_client->has_private_token() ) {
            $api_result = $this->api_client->get_event( $validated['event_id'] );

            if ( $api_result['success'] ) {
                $description_result = $this->api_client->get_description( $validated['event_id'] );
                $description        = $description_result['success'] ? $description_result['description'] : '';
                $candidate          = $this->api_normalizer->normalize_event( $api_result['event'], $description );

                $candidate['source_url']             = $validated['url'];
                $candidate['eventbrite_event_id']    = $validated['event_id'];
                $candidate['fetch_method']           = 'eventbrite_api';
                $candidate['api_http_status']        = $api_result['status'];
                $candidate['description_api_status'] = $description_result['status'];
                $candidate['description_api_error']  = $description_result['success'] ? '' : $description_result['error'];

                return $this->store_candidate_result( $candidate );
            }

            $api_error = $api_result['error'];
        }

        $fetched = $this->http->get_body( $validated['url'] );

        if ( ! $fetched['success'] ) {
            return $this->store_failed_source_candidate(
                $validated,
                $this->combined_error( $api_error, $fetched['error'] )
            );
        }

        $parsed = $this->parser->parse_event_from_html( $fetched['body'] );

        if ( ! $parsed['success'] ) {
            return $this->store_failed_source_candidate(
                $validated,
                $this->combined_error( $api_error, $parsed['error'] )
            );
        }

        $candidate                         = $parsed['event'];
        $candidate['source_url']           = $validated['url'];
        $candidate['eventbrite_event_id']  = $validated['event_id'];
        $candidate['http_status']          = $fetched['status'];
        $candidate['fetch_method']         = 'html_jsonld';
        $candidate['api_fallback_message'] = $api_error;

        return $this->store_candidate_result( $candidate );
    }

    /**
     * Store a valid review candidate.
     *
     * @param array<string,mixed> $candidate Candidate data.
     * @return array{success:bool,message:string,post_id:int,updated:bool,event:array<string,mixed>}
     */
    private function store_candidate_result( array $candidate ) {
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

    /**
     * Store a non-fabricated failure candidate so blocked sources remain visible.
     *
     * @param array<string,string|bool> $validated Validated URL data.
     * @param string                    $error Error message.
     * @return array{success:bool,message:string,post_id:int,updated:bool,event:array<string,mixed>}
     */
    private function store_failed_source_candidate( array $validated, $error ) {
        $candidate = array(
            'source_type'          => 'eventbrite',
            'title'                => 'Unreadable Eventbrite source ' . $validated['event_id'],
            'description'          => '',
            'source_url'           => $validated['url'],
            'eventbrite_event_id'  => $validated['event_id'],
            'candidate_status'     => 'source_unreadable',
            'collection_method'    => 'one_time_eventbrite_failed',
            'fetch_method'         => 'failed',
            'import_error'         => sanitize_text_field( $error ),
            'event_data_verified'  => 'no',
        );

        $stored = $this->store->save_event_candidate( $candidate );

        return array(
            'success' => false,
            'message' => $stored['success'] ? __( 'No verified event data was imported. A blocked/unreadable source candidate was recorded for review.', 'great-imports' ) : $error,
            'post_id' => $stored['success'] ? $stored['post_id'] : 0,
            'updated' => $stored['success'] ? $stored['updated'] : false,
            'event'   => $candidate,
        );
    }

    /**
     * Combine API and fallback errors without exposing secrets.
     *
     * @param string $api_error API error.
     * @param string $fallback_error Fallback error.
     */
    private function combined_error( $api_error, $fallback_error ) {
        $errors = array_filter(
            array(
                '' !== $api_error ? 'API: ' . $api_error : '',
                '' !== $fallback_error ? 'HTML: ' . $fallback_error : '',
            )
        );

        return implode( ' | ', $errors );
    }
}
