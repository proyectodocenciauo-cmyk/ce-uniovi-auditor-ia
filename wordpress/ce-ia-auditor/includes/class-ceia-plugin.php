<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CEIA_Plugin {
    private static $instance;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot() {
        if ( CEIA_DB_VERSION !== get_option( 'ceia_db_version', '' ) ) {
            CEIA_Activator::install_schema();
        }

        ( new CEIA_REST_Controller() )->register_hooks();

        if ( is_admin() ) {
            ( new CEIA_Admin() )->register_hooks();
        }

        add_action( 'ceia_daily_queue', array( $this, 'queue_due_items' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( CEIA_FILE ), array( $this, 'action_links' ) );
    }

    public function queue_due_items() {
        $settings = CEIA_Repository::get_settings();
        if ( empty( $settings['automatic_queue'] ) ) {
            return;
        }

        $limit = max( 1, min( 25, absint( $settings['daily_queue_limit'] ?? 5 ) ) );
        CEIA_Repository::queue_due_items( $limit, 0, 'schedule' );
    }

    public function action_links( $links ) {
        array_unshift(
            $links,
            '<a href="' . esc_url( admin_url( 'admin.php?page=ce-ia' ) ) . '">' . esc_html__( 'Abrir CE-IA', 'ce-ia-auditor' ) . '</a>'
        );

        return $links;
    }
}

