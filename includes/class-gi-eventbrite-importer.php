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
        $submitted_url = trim( (string) $raw_url );
        $validated     = $this->validator->validate_eventbrite_url( $submitted_url );

        if ( ! $validated['valid'] ) {
            return array(
                'success' => false,
                'message' => $validated['error'],
                'post_id' => 0,
                'updated' => false,
                'event'   => array(),
            );
        }

        $api_error         = '';
        $api_error_payload = array();

        if ( $this->api_client->has_private_token() ) {
            $api_result = $this->api_client->get_event( $validated['event_id'] );

            if ( $api_result['success'] ) {
                $description_result = $this->api_client->get_description( $validated['event_id'] );
                $description        = $description_result['success'] ? $description_result['description'] : '';
                $candidate          = $this->api_normalizer->normalize_event( $api_result['event'], $description );
                $related_payloads   = $this->api_client->get_related_exploratory_payloads( $validated['event_id'], $api_result['event'] );

                $candidate['submitted_url']                    = esc_url_raw( $submitted_url );
                $candidate['source_url']                       = $validated['url'];
                $candidate['eventbrite_event_id']              = $validated['event_id'];
                $candidate['fetch_method']                     = 'eventbrite_api';
                $candidate['api_http_status']                  = $api_result['status'];
                $candidate['description_api_status']           = $description_result['status'];
                $candidate['description_api_error']            = $description_result['success'] ? '' : $description_result['error'];
                $candidate['raw_description_api_response']     = isset( $description_result['raw_payload'] ) ? $description_result['raw_payload'] : array();
                $candidate['exploratory_api_payloads']         = array_merge(
                    array(
                        'event_detail' => array(
                            'label'    => 'event_detail',
                            'endpoint' => '/v3/events/' . $validated['event_id'] . '/',
                            'query'    => array(
                                'expand' => 'venue,ticket_availability,organizer,organizer.logo,category',
                            ),
                            'success'  => true,
                            'status'   => (int) $api_result['status'],
                            'error'    => '',
                            'payload'  => $api_result['event'],
                        ),
                        'event_description' => array(
                            'label'    => 'event_description',
                            'endpoint' => '/v3/events/' . $validated['event_id'] . '/description/',
                            'query'    => array(),
                            'success'  => (bool) $description_result['success'],
                            'status'   => (int) $description_result['status'],
                            'error'    => $description_result['success'] ? '' : $description_result['error'],
                            'payload'  => isset( $description_result['raw_payload'] ) ? $description_result['raw_payload'] : array(),
                        ),
                    ),
                    $related_payloads
                );
                $candidate['exploratory_report_data_coverage'] = 'api_event_payload,api_description_payload,api_related_payloads,normalized_candidate_fields';

                return $this->store_candidate_result( $candidate );
            }

            $api_error         = $api_result['error'];
            $api_error_payload = $api_result['event'];
        }

        $fetched = $this->http->get_body( $validated['url'] );

        if ( ! $fetched['success'] ) {
            return $this->store_failed_source_candidate(
                $validated,
                $submitted_url,
                $this->combined_error( $api_error, $fetched['error'] ),
                $api_error_payload
            );
        }

        $parsed = $this->parser->parse_event_from_html( $fetched['body'] );

        if ( ! $parsed['success'] ) {
            return $this->store_failed_source_candidate(
                $validated,
                $submitted_url,
                $this->combined_error( $api_error, $parsed['error'] ),
                $api_error_payload
            );
        }

        $candidate                                      = $parsed['event'];
        $candidate['submitted_url']                     = esc_url_raw( $submitted_url );
        $candidate['source_url']                        = $validated['url'];
        $candidate['eventbrite_event_id']               = $validated['event_id'];
        $candidate['http_status']                       = $fetched['status'];
        $candidate['fetch_method']                      = 'html_jsonld';
        $candidate['api_fallback_message']              = $api_error;
        $candidate['api_error_payload']                 = $api_error_payload;
        $candidate['exploratory_report_data_coverage']  = 'html_schema_event_jsonld,normalized_candidate_fields';

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
     * @param string                    $submitted_url Submitted URL.
     * @param string                    $error Error message.
     * @param array<string,mixed>       $api_error_payload Raw API error payload if present.
     * @return array{success:bool,message:string,post_id:int,updated:bool,event:array<string,mixed>}
     */
    private function store_failed_source_candidate( array $validated, $submitted_url, $error, array $api_error_payload = array() ) {
        $candidate = array(
            'source_type'                         => 'eventbrite',
            'title'                               => 'Unreadable Eventbrite source ' . $validated['event_id'],
            'description'                         => '',
            'submitted_url'                       => esc_url_raw( $submitted_url ),
            'source_url'                          => $validated['url'],
            'eventbrite_event_id'                 => $validated['event_id'],
            'candidate_status'                    => 'source_unreadable',
            'collection_method'                   => 'one_time_eventbrite_failed',
            'fetch_method'                        => 'failed',
            'import_error'                        => sanitize_text_field( $error ),
            'api_error_payload'                   => $api_error_payload,
            'event_data_verified'                 => 'no',
            'exploratory_report_data_coverage'    => 'source_url,eventbrite_event_id,error_payload_if_available,no_verified_event_data',
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
