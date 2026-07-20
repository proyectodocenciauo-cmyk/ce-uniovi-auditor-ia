<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Controles adicionales de alcance y cola.
 *
 * CE-IA solo puede auditar páginas publicadas bajo la web del Consejo.
 * Las fuentes de contraste pueden seguir siendo externas y oficiales.
 */
final class CEIA_Scope_Controls {
    const MANAGED_PREFIX = 'https://www.unioviedo.es/cestudiantes/';

    private static $enforced = false;

    public static function register_hooks() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 40 );
        add_action( 'admin_post_ceia_scope_action', array( __CLASS__, 'handle_action' ) );
        add_action( 'admin_init', array( __CLASS__, 'enforce_scope' ), 1 );
        add_action( 'rest_api_init', array( __CLASS__, 'enforce_scope' ), 1 );
        add_action( 'wp_loaded', array( __CLASS__, 'enforce_scope_during_cron' ), 1 );
    }

    public static function register_menu() {
        add_submenu_page(
            'ce-ia',
            'Cola y alcance',
            'Cola',
            'ceia_run_audits',
            'ce-ia-queue',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function is_managed_url( $url ) {
        $url   = esc_url_raw( (string) $url );
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
        $path   = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );

        return 'https' === $scheme
            && 'www.unioviedo.es' === $host
            && ( '/cestudiantes' === rtrim( $path, '/' ) || 0 === strpos( $path, '/cestudiantes/' ) );
    }

    public static function enforce_scope_during_cron() {
        if ( wp_doing_cron() ) {
            self::enforce_scope();
        }
    }

    public static function enforce_scope() {
        if ( self::$enforced ) {
            return;
        }
        self::$enforced = true;

        global $wpdb;
        $tables = CEIA_Repository::tables();
        $items  = $wpdb->get_results(
            "SELECT id, url, active FROM `{$tables['items']}` WHERE object_type = 'tramite'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        $excluded = array();
        foreach ( $items as $item ) {
            if ( self::is_managed_url( $item['url'] ?? '' ) ) {
                continue;
            }
            $item_id    = absint( $item['id'] );
            $excluded[] = $item_id;
            if ( ! empty( $item['active'] ) ) {
                $wpdb->update(
                    $tables['items'],
                    array(
                        'active'      => 0,
                        'last_status' => 'out_of_scope',
                        'updated_gmt' => CEIA_Repository::now(),
                    ),
                    array( 'id' => $item_id ),
                    array( '%d', '%s', '%s' ),
                    array( '%d' )
                );
            }
        }

        if ( $excluded ) {
            $placeholders = implode( ',', array_fill( 0, count( $excluded ), '%d' ) );
            $sql          = "SELECT id FROM `{$tables['jobs']}` WHERE state = 'queued' AND item_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $job_ids      = $wpdb->get_col( $wpdb->prepare( $sql, $excluded ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            foreach ( $job_ids as $job_id ) {
                self::cancel_job( absint( $job_id ), 'Fuera del alcance permitido: ' . self::MANAGED_PREFIX, 'system:scope' );
            }
        }
    }

    public static function cancel_job( $job_id, $reason = 'Cancelado manualmente.', $actor = '' ) {
        global $wpdb;
        $tables = CEIA_Repository::tables();
        $job    = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$tables['jobs']}` WHERE id = %d", absint( $job_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        if ( ! $job ) {
            return new WP_Error( 'ceia_job_missing', 'El trabajo no existe.' );
        }
        if ( 'queued' !== $job['state'] ) {
            return new WP_Error( 'ceia_job_not_queued', 'Solo se pueden cancelar trabajos que todavía estén en cola.' );
        }

        $updated = $wpdb->update(
            $tables['jobs'],
            array(
                'state'         => 'cancelled',
                'finished_gmt'  => CEIA_Repository::now(),
                'worker_id'     => '',
                'claimed_gmt'   => null,
                'error_code'    => 'cancelled',
                'error_message' => mb_substr( sanitize_textarea_field( $reason ), 0, 5000 ),
            ),
            array(
                'id'    => absint( $job_id ),
                'state' => 'queued',
            ),
            array( '%s', '%s', '%s', null, '%s', '%s' ),
            array( '%d', '%s' )
        );

        if ( 1 !== $updated ) {
            return new WP_Error( 'ceia_cancel_failed', 'El trabajo cambió de estado antes de poder cancelarlo.' );
        }

        CEIA_Repository::log(
            'job_cancelled',
            'job',
            absint( $job_id ),
            array( 'reason' => sanitize_text_field( $reason ) ),
            $actor ?: ( get_current_user_id() ? 'user:' . get_current_user_id() : 'system' )
        );
        return true;
    }

    public static function cancel_all_queued( $reason = 'Cola cancelada manualmente.' ) {
        global $wpdb;
        $tables  = CEIA_Repository::tables();
        $job_ids = $wpdb->get_col( "SELECT id FROM `{$tables['jobs']}` WHERE state = 'queued' ORDER BY requested_gmt ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count   = 0;
        foreach ( $job_ids as $job_id ) {
            if ( true === self::cancel_job( absint( $job_id ), $reason ) ) {
                $count++;
            }
        }
        return $count;
    }

    public static function handle_action() {
        if ( ! current_user_can( 'ceia_run_audits' ) ) {
            wp_die( esc_html__( 'No tienes permiso para gestionar la cola.', 'ce-ia-auditor' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'ceia_scope_action', 'ceia_scope_nonce' );

        $task = sanitize_key( wp_unslash( $_POST['task'] ?? '' ) );
        if ( 'cancel_job' === $task ) {
            $result  = self::cancel_job( absint( $_POST['job_id'] ?? 0 ) );
            $message = is_wp_error( $result ) ? $result->get_error_message() : 'Trabajo cancelado y conservado en el historial.';
            $type    = is_wp_error( $result ) ? 'error' : 'success';
        } elseif ( 'cancel_all' === $task ) {
            $count   = self::cancel_all_queued();
            $message = sprintf( 'Se han cancelado %d trabajos pendientes.', $count );
            $type    = 'success';
        } else {
            $message = 'Acción no reconocida.';
            $type    = 'error';
        }

        set_transient(
            'ceia_scope_notice_' . get_current_user_id(),
            array( 'type' => $type, 'message' => $message ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( admin_url( 'admin.php?page=ce-ia-queue' ) );
        exit;
    }

    private static function action_form_start( $task ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0">';
        echo '<input type="hidden" name="action" value="ceia_scope_action">';
        echo '<input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
        wp_nonce_field( 'ceia_scope_action', 'ceia_scope_nonce' );
    }

    public static function render_page() {
        if ( ! current_user_can( 'ceia_run_audits' ) ) {
            return;
        }
        self::enforce_scope();

        $notice_key = 'ceia_scope_notice_' . get_current_user_id();
        $notice     = get_transient( $notice_key );
        delete_transient( $notice_key );

        echo '<div class="wrap ceia-admin"><h1>CE-IA · Cola y alcance</h1>';
        echo '<p class="ceia-lead">Solo se auditan páginas cuya URL comienza por <code>' . esc_html( self::MANAGED_PREFIX ) . '</code>. Las fuentes oficiales externas se mantienen disponibles únicamente para contrastar información.</p>';
        if ( is_array( $notice ) ) {
            $class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
        }

        $jobs   = CEIA_Repository::list_jobs( 300 );
        $queued = array_values( array_filter( $jobs, static function ( $job ) { return 'queued' === $job['state']; } ) );

        echo '<section class="ceia-card"><h2>Trabajos pendientes</h2><p>Cancelar no borra el historial: impide que el trabajador reclame el proceso y registra la decisión.</p>';
        if ( $queued ) {
            self::action_form_start( 'cancel_all' );
            echo '<button class="button" onclick="return confirm(\'¿Cancelar todos los trabajos pendientes?\')">Cancelar toda la cola</button></form>';
        }
        echo '<div class="ceia-table-wrap"><table class="ceia-table ceia-table--responsive"><thead><tr><th>Trámite</th><th>Solicitud</th><th>Intento</th><th>URL</th><th>Acción</th></tr></thead><tbody>';
        foreach ( $queued as $job ) {
            $item = CEIA_Repository::get_item( absint( $job['item_id'] ) );
            echo '<tr><td data-label="Trámite"><b>' . esc_html( $job['item_title'] ) . '</b></td>';
            echo '<td data-label="Solicitud">' . esc_html( $job['requested_gmt'] ) . ' UTC</td>';
            echo '<td data-label="Intento">' . absint( $job['attempt'] ) . '/3</td>';
            echo '<td data-label="URL"><a href="' . esc_url( $item['url'] ?? '' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item['url'] ?? '—' ) . '</a></td><td data-label="Acción">';
            self::action_form_start( 'cancel_job' );
            echo '<input type="hidden" name="job_id" value="' . absint( $job['id'] ) . '"><button class="button" onclick="return confirm(\'¿Cancelar este trabajo?\')">Cancelar</button></form></td></tr>';
        }
        if ( ! $queued ) {
            echo '<tr><td colspan="5">No hay trabajos pendientes.</td></tr>';
        }
        echo '</tbody></table></div></section>';

        echo '<section class="ceia-card"><h2>Historial reciente</h2><div class="ceia-table-wrap"><table class="ceia-table ceia-table--responsive"><thead><tr><th>Trámite</th><th>Estado</th><th>Solicitud</th><th>Detalle</th></tr></thead><tbody>';
        foreach ( array_slice( $jobs, 0, 100 ) as $job ) {
            echo '<tr><td data-label="Trámite">' . esc_html( $job['item_title'] ) . '</td><td data-label="Estado">' . esc_html( $job['state'] ) . '</td><td data-label="Solicitud">' . esc_html( $job['requested_gmt'] ) . ' UTC</td><td data-label="Detalle">' . esc_html( $job['error_message'] ?: $job['summary'] ) . '</td></tr>';
        }
        echo '</tbody></table></div></section></div>';
    }
}
