<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CEIA_REST_Controller {
    const NAMESPACE = 'ceia/v1';

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route(
            self::NAMESPACE,
            '/health',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'health' ),
                'permission_callback' => array( $this, 'worker_permission' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/worker/config',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'worker_config' ),
                'permission_callback' => array( $this, 'worker_permission' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/jobs/claim',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'claim_job' ),
                'permission_callback' => array( $this, 'worker_permission' ),
                'args'                => array(
                    'worker_id' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/jobs/(?P<job_id>[a-f0-9-]{36})/result',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'submit_result' ),
                'permission_callback' => array( $this, 'worker_permission' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/jobs/(?P<job_id>[a-f0-9-]{36})/fail',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'fail_job' ),
                'permission_callback' => array( $this, 'worker_permission' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/worker/heartbeat',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'heartbeat' ),
                'permission_callback' => array( $this, 'worker_permission' ),
            )
        );
    }

    public function worker_permission() {
        return current_user_can( 'ceia_submit_research' );
    }

    public function health() {
        return rest_ensure_response(
            array(
                'ok'             => true,
                'plugin_version' => CEIA_VERSION,
                'server_gmt'     => CEIA_Repository::now(),
                'queue'          => array_intersect_key(
                    CEIA_Repository::dashboard_counts(),
                    array( 'queued' => true, 'running' => true, 'failed' => true )
                ),
            )
        );
    }

    public function worker_config() {
        $settings = CEIA_Repository::get_settings();
        $sources  = CEIA_Repository::list_sources();
        $hosts    = array();
        foreach ( $sources as $source ) {
            $host = strtolower( (string) wp_parse_url( $source['url'], PHP_URL_HOST ) );
            if ( $host ) {
                $hosts[ $host ] = $host;
            }
        }

        return rest_ensure_response(
            array(
                'site_url'           => home_url( '/' ),
                'plugin_version'     => CEIA_VERSION,
                'analysis_provider'  => 'gemini',
                'gemini_model'       => sanitize_text_field( $settings['gemini_model'] ),
                'gemini_api_key'     => CEIA_Security::decrypt_secret( $settings['gemini_api_key'] ),
                'tavily_enabled'     => ! empty( $settings['tavily_enabled'] ),
                'tavily_api_key'     => CEIA_Security::decrypt_secret( $settings['tavily_api_key'] ),
                'limits'             => array(
                    'max_jobs_per_run'     => max( 1, min( 25, absint( $settings['max_jobs_per_run'] ) ) ),
                    'max_sources_per_job'  => max( 3, min( 30, absint( $settings['max_sources_per_job'] ) ) ),
                    'max_searches_per_job' => max( 0, min( 5, absint( $settings['max_searches_per_job'] ) ) ),
                    'max_source_bytes'     => max( 100000, min( 10000000, absint( $settings['max_source_bytes'] ) ) ),
                ),
                'allowed_source_hosts' => array_values( $hosts ),
                'editorial_policy'    => CEIA_Repository::editorial_policy(),
            )
        );
    }

    public function claim_job( WP_REST_Request $request ) {
        $context = CEIA_Repository::claim_next_job( $request->get_param( 'worker_id' ) );
        if ( is_wp_error( $context ) ) {
            return $context;
        }

        return rest_ensure_response( array( 'job' => $context ) );
    }

    public function submit_result( WP_REST_Request $request ) {
        $data = $request->get_json_params();
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'ceia_invalid_json', 'El resultado debe enviarse como JSON.', array( 'status' => 400 ) );
        }

        list( $data, $quality_report ) = CEIA_Quality::evaluate_result( $request['job_id'], $data );
        $previews = is_array( $data['previews'] ?? null ) ? $data['previews'] : array();
        unset( $data['previews'], $data['quality_report'], $data['publication_gate'] );

        $result = CEIA_Repository::complete_job( $request['job_id'], $data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( ! empty( $result['proposal_id'] ) ) {
            CEIA_Quality::store_report( absint( $result['proposal_id'] ), $quality_report, $previews );
        }

        return rest_ensure_response( array( 'ok' => true, 'result' => $result ) );
    }

    public function fail_job( WP_REST_Request $request ) {
        $data    = $request->get_json_params();
        $code    = sanitize_key( $data['code'] ?? 'worker_failed' );
        $message = sanitize_textarea_field( $data['message'] ?? 'El trabajador no proporcionó detalles.' );
        $result  = CEIA_Repository::fail_job( $request['job_id'], $code, $message );

        return rest_ensure_response( array( 'ok' => (bool) $result ) );
    }

    public function heartbeat( WP_REST_Request $request ) {
        $data = $request->get_json_params();
        return rest_ensure_response(
            array(
                'ok'        => true,
                'heartbeat' => CEIA_Repository::record_heartbeat( is_array( $data ) ? $data : array() ),
            )
        );
    }
}
