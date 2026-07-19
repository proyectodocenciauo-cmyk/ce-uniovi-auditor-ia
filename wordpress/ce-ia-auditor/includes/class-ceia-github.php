<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CEIA_GitHub {
    public static function dispatch() {
        $settings = CEIA_Repository::get_settings();
        $owner    = sanitize_key( $settings['github_owner'] ?? '' );
        $repo     = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) ( $settings['github_repository'] ?? '' ) );
        $workflow = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) ( $settings['github_workflow'] ?? 'audit.yml' ) );
        $branch   = preg_replace( '/[^A-Za-z0-9._\/-]/', '', (string) ( $settings['github_branch'] ?? 'main' ) );
        $token    = CEIA_Security::decrypt_secret( $settings['github_token'] ?? '' );

        if ( '' === $owner || '' === $repo || '' === $workflow || '' === $branch || '' === $token ) {
            return new WP_Error( 'ceia_github_not_configured', 'La ejecución inmediata de GitHub no está configurada. La cola esperará a la siguiente ejecución programada.' );
        }

        $endpoint = sprintf(
            'https://api.github.com/repos/%s/%s/actions/workflows/%s/dispatches',
            rawurlencode( $owner ),
            rawurlencode( $repo ),
            rawurlencode( $workflow )
        );

        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout'     => 15,
                'redirection' => 0,
                'headers'     => array(
                    'Accept'               => 'application/vnd.github+json',
                    'Authorization'        => 'Bearer ' . $token,
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent'           => 'CE-IA-Auditor/' . CEIA_VERSION,
                ),
                'body'        => wp_json_encode(
                    array(
                        'ref'    => $branch,
                        'inputs' => array( 'source' => 'wordpress' ),
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 204 !== $code ) {
            return new WP_Error( 'ceia_github_dispatch_failed', 'GitHub rechazó la ejecución inmediata (HTTP ' . absint( $code ) . ').' );
        }

        CEIA_Repository::log( 'github_dispatched', 'worker', 0 );
        return true;
    }
}

