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

    /** @var GI_Evidence_Store */
    private $evidence_store;

    /** @var GI_HTTP_Evidence_Client */
    private $evidence_http;

    /** @var GI_HTML_Evidence_Extractor */
    private $html_extractor;

    public function __construct( GI_Url_Validator $validator, GI_Http_Client $http, GI_Jsonld_Parser $parser, GI_Candidate_Store $store, GI_Eventbrite_API_Client $api_client, GI_Eventbrite_API_Normalizer $api_normalizer, GI_Evidence_Store $evidence_store, GI_HTTP_Evidence_Client $evidence_http, GI_HTML_Evidence_Extractor $html_extractor ) {
        $this->validator       = $validator;
        $this->http            = $http;
        $this->parser          = $parser;
        $this->store           = $store;
        $this->api_client      = $api_client;
        $this->api_normalizer  = $api_normalizer;
        $this->evidence_store  = $evidence_store;
        $this->evidence_http   = $evidence_http;
        $this->html_extractor  = $html_extractor;
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

        $bundle = $this->evidence_store->create_bundle(
            array(
                'source_type'          => 'eventbrite',
                'submitted_url'        => esc_url_raw( $submitted_url ),
                'source_url'           => $validated['url'],
                'eventbrite_event_id'  => $validated['event_id'],
                'capture_scope'        => 'full_view_first',
            )
        );

        $public_page_evidence = $this->evidence_http->capture_get( $validated['url'], 'public_event_page' );
        $bundle              = $this->evidence_store->add_item( $bundle, 'public_event_page', $public_page_evidence );

        if ( ! empty( $public_page_evidence['body'] ) ) {
            $bundle = $this->evidence_store->add_item(
                $bundle,
                'public_event_page_html_extracted_evidence',
                $this->html_extractor->extract( (string) $public_page_evidence['body'], $validated['url'] )
            );
        }

        $api_error         = '';
        $api_error_payload = array();

        if ( $this->api_client->has_private_token() ) {
            $api_result = $this->api_client->get_event( $validated['event_id'] );

            if ( $api_result['success'] ) {
                $description_result = $this->api_client->get_description( $validated['event_id'] );
                $related_payloads   = $this->api_client->get_related_exploratory_payloads( $validated['event_id'], $api_result['event'] );

                $bundle = $this->evidence_store->add_item(
                    $bundle,
                    'eventbrite_api_event_detail',
                    array(
                        'label'            => 'event_detail',
                        'capture_type'     => 'api_response',
                        'endpoint'         => '/v3/events/' . $validated['event_id'] . '/',
                        'query'            => array( 'expand' => 'venue,ticket_availability,organizer,organizer.logo,category' ),
                        'success'          => true,
                        'status'           => (int) $api_result['status'],
                        'error'            => '',
                        'response_headers' => isset( $api_result['headers'] ) ? $api_result['headers'] : array(),
                        'payload'          => $api_result['event'],
                    )
                );

                $bundle = $this->evidence_store->add_item(
                    $bundle,
                    'eventbrite_api_event_description',
                    array(
                        'label'            => 'event_description',
                        'capture_type'     => 'api_response',
                        'endpoint'         => '/v3/events/' . $validated['event_id'] . '/description/',
                        'query'            => array(),
                        'success'          => (bool) $description_result['success'],
                        'status'           => (int) $description_result['status'],
                        'error'            => $description_result['success'] ? '' : $description_result['error'],
                        'response_headers' => isset( $description_result['headers'] ) ? $description_result['headers'] : array(),
                        'payload'          => isset( $description_result['raw_payload'] ) ? $description_result['raw_payload'] : array(),
                    )
                );

                foreach ( $related_payloads as $key => $item ) {
                    $bundle = $this->evidence_store->add_item( $bundle, 'eventbrite_api_' . $key, $item );
                }

                $evidence_result = $this->evidence_store->save_bundle( $bundle );
                $description     = $description_result['success'] ? $description_result['description'] : '';
                $candidate       = $this->api_normalizer->normalize_event( $api_result['event'], $description );

                $candidate['submitted_url']                    = esc_url_raw( $submitted_url );
                $candidate['source_url']                       = $validated['url'];
                $candidate['eventbrite_event_id']              = $validated['event_id'];
                $candidate['fetch_method']                     = 'eventbrite_api';
                $candidate['api_http_status']                  = $api_result['status'];
                $candidate['description_api_status']           = $description_result['status'];
                $candidate['description_api_error']            = $description_result['success'] ? '' : $description_result['error'];
                $candidate['raw_description_api_response']     = isset( $description_result['raw_payload'] ) ? $description_result['raw_payload'] : array();
                $candidate['exploratory_api_payloads']         = $this->candidate_api_payloads_from_bundle( $bundle );
                $candidate['evidence_bundle_id']               = $evidence_result['success'] ? $evidence_result['post_id'] : 0;
                $candidate['evidence_capture_run_id']          = isset( $bundle['capture_run_id'] ) ? $bundle['capture_run_id'] : '';
                $candidate['exploratory_report_data_coverage'] = 'full_evidence_bundle_first,api_event_payload,api_description_payload,api_related_payloads,public_page_http,html_extraction,normalized_candidate_fields';

                return $this->store_candidate_result( $candidate, $evidence_result );
            }

            $api_error         = $api_result['error'];
            $api_error_payload = $api_result['event'];
            $bundle            = $this->evidence_store->add_item(
                $bundle,
                'eventbrite_api_event_detail_error',
                array(
                    'label'            => 'event_detail_error',
                    'capture_type'     => 'api_response',
                    'endpoint'         => '/v3/events/' . $validated['event_id'] . '/',
                    'query'            => array( 'expand' => 'venue,ticket_availability,organizer,organizer.logo,category' ),
                    'success'          => false,
                    'status'           => (int) $api_result['status'],
                    'error'            => $api_error,
                    'response_headers' => isset( $api_result['headers'] ) ? $api_result['headers'] : array(),
                    'payload'          => $api_error_payload,
                )
            );
        }

        $fetched = array(
            'success' => ! empty( $public_page_evidence['success'] ),
            'body'    => isset( $public_page_evidence['body'] ) ? $public_page_evidence['body'] : '',
            'status'  => isset( $public_page_evidence['status'] ) ? (int) $public_page_evidence['status'] : 0,
            'error'   => isset( $public_page_evidence['error'] ) ? (string) $public_page_evidence['error'] : '',
        );

        if ( ! $fetched['success'] ) {
            $evidence_result = $this->evidence_store->save_bundle( $bundle );
            return $this->store_failed_source_candidate(
                $validated,
                $submitted_url,
                $this->combined_error( $api_error, $fetched['error'] ? $fetched['error'] : 'Public page fetch failed with HTTP ' . $fetched['status'] ),
                $api_error_payload,
                $evidence_result,
                isset( $bundle['capture_run_id'] ) ? $bundle['capture_run_id'] : ''
            );
        }

        $parsed = $this->parser->parse_event_from_html( (string) $fetched['body'] );

        if ( ! $parsed['success'] ) {
            $evidence_result = $this->evidence_store->save_bundle( $bundle );
            return $this->store_failed_source_candidate(
                $validated,
                $submitted_url,
                $this->combined_error( $api_error, $parsed['error'] ),
                $api_error_payload,
                $evidence_result,
                isset( $bundle['capture_run_id'] ) ? $bundle['capture_run_id'] : ''
            );
        }

        $evidence_result                                = $this->evidence_store->save_bundle( $bundle );
        $candidate                                      = $parsed['event'];
        $candidate['submitted_url']                     = esc_url_raw( $submitted_url );
        $candidate['source_url']                        = $validated['url'];
        $candidate['eventbrite_event_id']               = $validated['event_id'];
        $candidate['http_status']                       = $fetched['status'];
        $candidate['fetch_method']                      = 'html_jsonld';
        $candidate['api_fallback_message']              = $api_error;
        $candidate['api_error_payload']                 = $api_error_payload;
        $candidate['evidence_bundle_id']                = $evidence_result['success'] ? $evidence_result['post_id'] : 0;
        $candidate['evidence_capture_run_id']           = isset( $bundle['capture_run_id'] ) ? $bundle['capture_run_id'] : '';
        $candidate['exploratory_report_data_coverage']  = 'full_evidence_bundle_first,public_page_http,html_extraction,html_schema_event_jsonld,normalized_candidate_fields';

        return $this->store_candidate_result( $candidate, $evidence_result );
    }

    /**
     * Build legacy candidate exploratory API payloads from bundle items.
     *
     * @param array<string,mixed> $bundle Bundle.
     * @return array<string,mixed>
     */
    private function candidate_api_payloads_from_bundle( array $bundle ) {
        $payloads = array();
        $items    = isset( $bundle['items'] ) && is_array( $bundle['items'] ) ? $bundle['items'] : array();

        foreach ( $items as $key => $item ) {
            if ( 0 !== strpos( (string) $key, 'eventbrite_api_' ) ) {
                continue;
            }

            $payloads[ str_replace( 'eventbrite_api_', '', (string) $key ) ] = $item;
        }

        return $payloads;
    }

    /**
     * Store a valid review candidate.
     *
     * @param array<string,mixed> $candidate Candidate data.
     * @param array<string,mixed> $evidence_result Evidence save result.
     * @return array{success:bool,message:string,post_id:int,updated:bool,event:array<string,mixed>}
     */
    private function store_candidate_result( array $candidate, array $evidence_result = array() ) {
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

        if ( ! empty( $evidence_result['success'] ) && ! empty( $evidence_result['post_id'] ) ) {
            update_post_meta( (int) $evidence_result['post_id'], '_gi_candidate_post_id', (int) $stored['post_id'] );
            update_post_meta( (int) $stored['post_id'], '_gi_evidence_bundle_id', (int) $evidence_result['post_id'] );
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
     * @param array<string,mixed>       $evidence_result Evidence save result.
     * @param string                    $capture_run_id Evidence run ID.
     * @return array{success:bool,message:string,post_id:int,updated:bool,event:array<string,mixed>}
     */
    private function store_failed_source_candidate( array $validated, $submitted_url, $error, array $api_error_payload = array(), array $evidence_result = array(), $capture_run_id = '' ) {
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
            'evidence_bundle_id'                  => ! empty( $evidence_result['success'] ) ? (int) $evidence_result['post_id'] : 0,
            'evidence_capture_run_id'             => sanitize_text_field( (string) $capture_run_id ),
            'exploratory_report_data_coverage'    => 'full_evidence_bundle_first,source_url,eventbrite_event_id,error_payload_if_available,no_verified_event_data',
        );

        $stored = $this->store->save_event_candidate( $candidate );

        if ( ! empty( $evidence_result['success'] ) && ! empty( $evidence_result['post_id'] ) && ! empty( $stored['post_id'] ) ) {
            update_post_meta( (int) $evidence_result['post_id'], '_gi_candidate_post_id', (int) $stored['post_id'] );
            update_post_meta( (int) $stored['post_id'], '_gi_evidence_bundle_id', (int) $evidence_result['post_id'] );
        }

        return array(
            'success' => false,
            'message' => $stored['success'] ? __( 'No verified event data was imported. A full evidence bundle and blocked/unreadable source candidate were recorded for review.', 'great-imports' ) : $error,
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
