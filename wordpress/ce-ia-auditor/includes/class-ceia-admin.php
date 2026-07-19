<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CEIA_Admin {
    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_ceia_action', array( $this, 'handle_action' ) );
    }

    public function register_menu() {
        add_menu_page(
            'CE-IA · Auditor de Trámites',
            'CE-IA',
            'ceia_review_proposals',
            'ce-ia',
            array( $this, 'render_dashboard' ),
            'dashicons-shield-alt',
            29
        );
        add_submenu_page( 'ce-ia', 'Panel CE-IA', 'Panel', 'ceia_review_proposals', 'ce-ia', array( $this, 'render_dashboard' ) );
        add_submenu_page( 'ce-ia', 'Elementos supervisados', 'Elementos', 'ceia_run_audits', 'ce-ia-items', array( $this, 'render_items' ) );
        add_submenu_page( 'ce-ia', 'Propuestas', 'Propuestas', 'ceia_review_proposals', 'ce-ia-proposals', array( $this, 'render_proposals' ) );
        add_submenu_page( 'ce-ia', 'Fuentes', 'Fuentes', 'ceia_manage_settings', 'ce-ia-sources', array( $this, 'render_sources' ) );
        add_submenu_page( 'ce-ia', 'Configuración', 'Configuración', 'ceia_manage_settings', 'ce-ia-settings', array( $this, 'render_settings' ) );
        add_submenu_page( 'ce-ia', 'Sistema y auditoría', 'Sistema', 'ceia_manage_settings', 'ce-ia-system', array( $this, 'render_system' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'ce-ia' ) ) {
            return;
        }

        wp_enqueue_style( 'ceia-admin', CEIA_URL . 'assets/admin.css', array(), CEIA_VERSION );
    }

    public function handle_action() {
        $task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
        check_admin_referer( 'ceia_action', 'ceia_nonce' );

        $capability = $this->capability_for_task( $task );
        if ( ! current_user_can( $capability ) ) {
            wp_die( esc_html__( 'No tienes permiso para realizar esta acción.', 'ce-ia-auditor' ), '', array( 'response' => 403 ) );
        }

        $redirect = $this->safe_redirect();
        $result   = true;
        $message  = '';

        switch ( $task ) {
            case 'sync':
                $result = CEIA_Repository::sync_tramites();
                if ( ! is_wp_error( $result ) ) {
                    $message = sprintf( 'Sincronización terminada: %d elementos nuevos y %d actualizados.', $result['inserted'], $result['updated'] );
                }
                break;

            case 'queue_item':
                $result = CEIA_Repository::queue_job( absint( $_POST['item_id'] ?? 0 ), get_current_user_id(), 'manual' );
                if ( ! is_wp_error( $result ) ) {
                    $message = 'Investigación añadida a la cola.';
                    $message .= $this->maybe_dispatch();
                }
                break;

            case 'queue_selected':
                $ids    = isset( $_POST['item_ids'] ) && is_array( $_POST['item_ids'] ) ? array_slice( array_map( 'absint', wp_unslash( $_POST['item_ids'] ) ), 0, 100 ) : array();
                $queued = 0;
                foreach ( array_unique( $ids ) as $item_id ) {
                    $queued_result = CEIA_Repository::queue_job( $item_id, get_current_user_id(), 'manual_bulk' );
                    if ( ! is_wp_error( $queued_result ) ) {
                        $queued++;
                    }
                }
                $message = sprintf( 'Se han añadido %d investigaciones a la cola.', $queued );
                $message .= $queued ? $this->maybe_dispatch() : '';
                break;

            case 'queue_due':
                $settings = CEIA_Repository::get_settings();
                $limit    = max( 1, min( 25, absint( $settings['max_jobs_per_run'] ) ) );
                $queued   = CEIA_Repository::queue_due_items( $limit, get_current_user_id(), 'manual_due' );
                $message  = sprintf( 'Se han añadido %d revisiones vencidas a la cola.', count( $queued ) );
                $message .= $queued ? $this->maybe_dispatch() : '';
                break;

            case 'dispatch':
                $result  = CEIA_GitHub::dispatch();
                $message = 'Ejecución gratuita solicitada a GitHub Actions.';
                break;

            case 'save_item':
                $result = CEIA_Repository::save_item_policy(
                    absint( $_POST['item_id'] ?? 0 ),
                    sanitize_key( wp_unslash( $_POST['risk'] ?? 'medium' ) ),
                    absint( $_POST['review_interval_days'] ?? 90 ),
                    isset( $_POST['active'] )
                );
                $message = 'Política de revisión actualizada.';
                break;

            case 'save_source':
                $result = CEIA_Repository::upsert_source(
                    absint( $_POST['item_id'] ?? 0 ),
                    sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
                    esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ),
                    sanitize_key( wp_unslash( $_POST['source_type'] ?? 'institutional' ) ),
                    absint( $_POST['authority'] ?? 60 ),
                    isset( $_POST['active'] )
                );
                $message = 'Fuente guardada.';
                break;

            case 'delete_source':
                $result  = CEIA_Repository::delete_source( absint( $_POST['source_id'] ?? 0 ) );
                $message = 'Fuente eliminada.';
                break;

            case 'save_settings':
                $result  = $this->save_settings();
                $message = 'Configuración guardada de forma segura.';
                break;

            case 'test_email':
                $result  = CEIA_Notifications::test();
                $message = 'Correo de prueba enviado al buzón configurado.';
                break;

            case 'approve':
                $proposal_id = absint( $_POST['proposal_id'] ?? 0 );
                $result      = CEIA_Publisher::approve( $proposal_id, wp_unslash( $_POST['review_note'] ?? '' ) );
                $message     = 'Propuesta aprobada. Aún no está publicada.';
                $redirect    = admin_url( 'admin.php?page=ce-ia-proposals&proposal_id=' . $proposal_id );
                break;

            case 'reject':
                $proposal_id = absint( $_POST['proposal_id'] ?? 0 );
                $result      = CEIA_Publisher::reject( $proposal_id, wp_unslash( $_POST['review_note'] ?? '' ) );
                $message     = 'Propuesta rechazada y registrada.';
                $redirect    = admin_url( 'admin.php?page=ce-ia-proposals&proposal_id=' . $proposal_id );
                break;

            case 'publish':
                $proposal_id = absint( $_POST['proposal_id'] ?? 0 );
                $result      = CEIA_Publisher::publish( $proposal_id );
                $message     = 'Propuesta publicada. La versión anterior queda disponible para reversión.';
                $redirect    = admin_url( 'admin.php?page=ce-ia-proposals&proposal_id=' . $proposal_id );
                break;

            case 'rollback':
                $proposal_id = absint( $_POST['proposal_id'] ?? 0 );
                $result      = CEIA_Publisher::rollback( $proposal_id );
                $message     = 'Se ha restaurado la versión anterior.';
                $redirect    = admin_url( 'admin.php?page=ce-ia-proposals&proposal_id=' . $proposal_id );
                break;

            default:
                $result = new WP_Error( 'ceia_unknown_action', 'Acción no reconocida.' );
        }

        if ( is_wp_error( $result ) || false === $result ) {
            $message = is_wp_error( $result ) ? $result->get_error_message() : 'La operación no pudo completarse.';
            $this->redirect_with_notice( $redirect, 'error', $message );
        }

        $this->redirect_with_notice( $redirect, 'success', $message );
    }

    private function capability_for_task( $task ) {
        if ( in_array( $task, array( 'sync', 'save_source', 'delete_source', 'save_settings', 'test_email' ), true ) ) {
            return 'ceia_manage_settings';
        }
        if ( in_array( $task, array( 'queue_item', 'queue_selected', 'queue_due', 'dispatch', 'save_item' ), true ) ) {
            return 'ceia_run_audits';
        }
        if ( in_array( $task, array( 'publish', 'rollback' ), true ) ) {
            return 'ceia_publish_proposals';
        }
        return 'ceia_review_proposals';
    }

    private function maybe_dispatch() {
        if ( empty( $_POST['run_now'] ) ) {
            return ' Se procesará en la siguiente ejecución programada.';
        }

        $dispatch = CEIA_GitHub::dispatch();
        if ( is_wp_error( $dispatch ) ) {
            return ' La cola se ha conservado; ' . $dispatch->get_error_message();
        }
        return ' También se ha solicitado la ejecución inmediata.';
    }

    private function safe_redirect() {
        $referer = wp_get_referer();
        return $referer ? wp_validate_redirect( $referer, admin_url( 'admin.php?page=ce-ia' ) ) : admin_url( 'admin.php?page=ce-ia' );
    }

    private function redirect_with_notice( $url, $type, $message ) {
        set_transient(
            'ceia_notice_' . get_current_user_id(),
            array( 'type' => sanitize_key( $type ), 'message' => sanitize_text_field( $message ) ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( $url );
        exit;
    }

    private function save_settings() {
        $settings = CEIA_Repository::get_settings();
        $email    = sanitize_email( wp_unslash( $_POST['notification_email'] ?? '' ) );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'ceia_invalid_email', 'El correo de avisos no es válido.' );
        }

        $settings['notification_email']   = $email;
        $settings['automatic_queue']      = isset( $_POST['automatic_queue'] ) ? 1 : 0;
        $settings['daily_queue_limit']    = max( 1, min( 25, absint( $_POST['daily_queue_limit'] ?? 5 ) ) );
        $settings['max_jobs_per_run']     = max( 1, min( 25, absint( $_POST['max_jobs_per_run'] ?? 5 ) ) );
        $settings['max_sources_per_job']  = max( 3, min( 30, absint( $_POST['max_sources_per_job'] ?? 12 ) ) );
        $settings['max_searches_per_job'] = max( 0, min( 5, absint( $_POST['max_searches_per_job'] ?? 2 ) ) );
        $settings['max_source_bytes']     = max( 100000, min( 10000000, absint( $_POST['max_source_bytes'] ?? 6000000 ) ) );
        $settings['analysis_provider']    = 'gemini';
        $allowed_models                   = array( 'gemini-3.1-flash-lite', 'gemini-2.5-flash-lite' );
        $model                            = sanitize_text_field( wp_unslash( $_POST['gemini_model'] ?? '' ) );
        $settings['gemini_model']         = in_array( $model, $allowed_models, true ) ? $model : 'gemini-3.1-flash-lite';
        $settings['tavily_enabled']       = isset( $_POST['tavily_enabled'] ) ? 1 : 0;
        $settings['github_owner']         = preg_replace( '/[^A-Za-z0-9-]/', '', sanitize_text_field( wp_unslash( $_POST['github_owner'] ?? '' ) ) );
        $settings['github_repository']    = preg_replace( '/[^A-Za-z0-9._-]/', '', sanitize_text_field( wp_unslash( $_POST['github_repository'] ?? '' ) ) );
        $settings['github_workflow']      = preg_replace( '/[^A-Za-z0-9._-]/', '', sanitize_text_field( wp_unslash( $_POST['github_workflow'] ?? 'audit.yml' ) ) );
        $settings['github_branch']        = preg_replace( '/[^A-Za-z0-9._\/-]/', '', sanitize_text_field( wp_unslash( $_POST['github_branch'] ?? 'main' ) ) );

        $secrets = array(
            'gemini_api_key' => 'gemini_api_key',
            'tavily_api_key' => 'tavily_api_key',
            'github_token'   => 'github_token',
        );
        foreach ( $secrets as $setting_key => $field ) {
            if ( isset( $_POST[ 'clear_' . $field ] ) ) {
                $settings[ $setting_key ] = '';
                continue;
            }
            $plaintext = trim( (string) wp_unslash( $_POST[ $field ] ?? '' ) );
            if ( '' !== $plaintext ) {
                $encrypted = CEIA_Security::encrypt_secret( $plaintext );
                if ( is_wp_error( $encrypted ) ) {
                    return $encrypted;
                }
                $settings[ $setting_key ] = $encrypted;
            }
        }

        unset( $settings['openai_api_key'], $settings['openai_model'] );

        if ( '' === CEIA_Security::decrypt_secret( $settings['gemini_api_key'] ) ) {
            return new WP_Error( 'ceia_gemini_key_required', 'Para seleccionar Gemini debes guardar primero una clave.' );
        }

        if ( $settings['tavily_enabled'] && '' === CEIA_Security::decrypt_secret( $settings['tavily_api_key'] ) ) {
            return new WP_Error( 'ceia_tavily_key_required', 'Para activar Tavily debes guardar primero una clave gratuita.' );
        }

        CEIA_Repository::save_settings( $settings );
        return true;
    }

    private function header( $title, $lead ) {
        echo '<div class="wrap ceia-admin">';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        echo '<p class="ceia-lead">' . esc_html( $lead ) . '</p>';
        $this->notice();
    }

    private function footer() {
        echo '</div>';
    }

    private function notice() {
        $key    = 'ceia_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! is_array( $notice ) ) {
            return;
        }
        delete_transient( $key );
        $class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
    }

    private function action_form_start( $task, $extra_class = '' ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $extra_class ) . '">';
        echo '<input type="hidden" name="action" value="ceia_action">';
        echo '<input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
        wp_nonce_field( 'ceia_action', 'ceia_nonce' );
    }

    public function render_dashboard() {
        $this->header( 'CE-IA · Auditor de Trámites', 'Panel de investigación y control editorial. La IA propone; una persona comprueba, aprueba y publica.' );
        $counts    = CEIA_Repository::dashboard_counts();
        $settings  = CEIA_Repository::get_settings();
        $heartbeat = CEIA_Repository::get_heartbeat();
        $metrics   = array(
            'items'     => 'Elementos activos',
            'due'       => 'Revisiones vencidas',
            'queued'    => 'En cola',
            'running'   => 'En curso',
            'review'    => 'Pendientes de revisar',
            'conflicts' => 'Conflictos bloqueados',
            'published' => 'Publicaciones registradas',
            'failed'    => 'Fallos por revisar',
        );

        echo '<div class="ceia-grid">';
        foreach ( $metrics as $key => $label ) {
            echo '<div class="ceia-card ceia-col-3"><div class="ceia-metric">' . absint( $counts[ $key ] ) . '</div><div class="ceia-metric-label">' . esc_html( $label ) . '</div></div>';
        }
        echo '</div>';

        echo '<div class="ceia-grid">';
        echo '<section class="ceia-card ceia-col-8"><h2>Acciones seguras</h2><p>La cola no modifica contenido. Solo crea una propuesta con fuentes y trazabilidad.</p><div class="ceia-actions">';
        $this->action_form_start( 'queue_due' );
        echo '<input type="hidden" name="run_now" value="1"><button class="button button-primary">Investigar ahora lo vencido</button></form>';
        $this->action_form_start( 'dispatch' );
        echo '<button class="button">Procesar la cola ahora</button></form>';
        $this->action_form_start( 'sync' );
        echo '<button class="button">Sincronizar los 171 trámites</button></form>';
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=ce-ia-proposals&status=review_required' ) ) . '">Revisar propuestas</a>';
        echo '</div></section>';

        echo '<aside class="ceia-card ceia-col-4"><h2>Trabajador gratuito</h2>';
        if ( $heartbeat ) {
            echo '<p><b>Última señal:</b> ' . esc_html( $this->format_gmt( $heartbeat['received_gmt'] ?? '' ) ) . '</p>';
            echo '<p><b>Versión:</b> ' . esc_html( $heartbeat['version'] ?? '—' ) . '</p>';
            echo '<p><b>Proveedor:</b> ' . esc_html( $heartbeat['provider'] ?? '—' ) . '</p>';
        } else {
            echo '<p class="ceia-callout ceia-callout--warning">Aún no se ha recibido ninguna señal del trabajador externo.</p>';
        }
        echo '<p><b>Gemini:</b> <span class="ceia-secret-state">' . esc_html( CEIA_Security::mask_secret( $settings['gemini_api_key'] ) ) . '</span></p>';
        echo '<p><b>GitHub inmediato:</b> <span class="ceia-secret-state">' . esc_html( CEIA_Security::mask_secret( $settings['github_token'] ) ) . '</span></p>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=ce-ia-settings' ) ) . '">Completar configuración</a></aside>';
        echo '</div>';

        $jobs = CEIA_Repository::list_jobs( 12 );
        echo '<section class="ceia-card"><h2>Actividad reciente</h2>';
        $this->jobs_table( $jobs );
        echo '</section>';
        $this->footer();
    }

    public function render_items() {
        $this->header( 'Elementos supervisados', 'Cada registro conserva su página, nivel de riesgo, frecuencia de revisión y estado. La sincronización nunca elimina registros de Trámites UniOvi.' );
        $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        $risk   = sanitize_key( wp_unslash( $_GET['risk'] ?? '' ) );
        $items  = CEIA_Repository::list_items( array( 'search' => $search, 'risk' => $risk, 'limit' => 500 ) );

        echo '<section class="ceia-card"><form method="get" class="ceia-actions"><input type="hidden" name="page" value="ce-ia-items"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Buscar título, categoría o URL"><select name="risk"><option value="">Todos los riesgos</option>';
        foreach ( array( 'low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto', 'critical' => 'Crítico' ) as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $risk, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><button class="button">Filtrar</button></form></section>';

        echo '<form id="ceia-bulk-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ceia_action"><input type="hidden" name="task" value="queue_selected">';
        wp_nonce_field( 'ceia_action', 'ceia_nonce' );
        echo '<section class="ceia-card"><div class="ceia-actions"><button class="button button-primary">Investigar seleccionados</button><label><input type="checkbox" name="run_now" value="1" checked> Ejecutar ahora si GitHub está configurado</label></div></section></form>';
        echo '<div class="ceia-table-wrap"><table class="ceia-table ceia-table--responsive"><thead><tr><th><input type="checkbox" aria-label="Seleccionar todos" onclick="document.querySelectorAll(\'.ceia-item-check\').forEach(function(c){c.checked=this.checked;},this)"></th><th>Elemento</th><th>Riesgo</th><th>Revisión</th><th>Estado</th><th>Política</th></tr></thead><tbody>';
        foreach ( $items as $item ) {
            echo '<tr><td data-label="Seleccionar"><input class="ceia-item-check" form="ceia-bulk-form" type="checkbox" name="item_ids[]" value="' . absint( $item['id'] ) . '"></td>';
            echo '<td data-label="Elemento"><div class="ceia-title">' . esc_html( $item['title'] ) . '</div><div class="ceia-small ceia-muted">' . esc_html( $item['category'] ) . '</div><a class="ceia-small" href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener noreferrer">Abrir destino</a>' . ( empty( $item['post_id'] ) ? '<div class="ceia-small ceia-muted">Sin página interna asociada</div>' : '' ) . '</td>';
            echo '<td data-label="Riesgo">' . $this->pill( $item['risk'] ) . '</td>';
            echo '<td data-label="Revisión"><div>' . esc_html( $this->format_gmt( $item['next_review_gmt'] ) ) . '</div><div class="ceia-small ceia-muted">Cada ' . absint( $item['review_interval_days'] ) . ' días</div></td>';
            echo '<td data-label="Estado">' . $this->pill( $item['last_status'] ) . '</td>';
            echo '<td data-label="Política">';
            $this->action_form_start( 'save_item', 'ceia-inline-form' );
            echo '<input type="hidden" name="item_id" value="' . absint( $item['id'] ) . '"><select name="risk" aria-label="Riesgo">';
            foreach ( array( 'low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto', 'critical' => 'Crítico' ) as $value => $label ) {
                echo '<option value="' . esc_attr( $value ) . '" ' . selected( $item['risk'], $value, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select><input type="number" name="review_interval_days" min="1" max="730" value="' . absint( $item['review_interval_days'] ) . '" aria-label="Días entre revisiones"><label><input type="checkbox" name="active" value="1" ' . checked( $item['active'], 1, false ) . '> Activo</label><button class="button">Guardar</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        $this->footer();
    }

    public function render_proposals() {
        $proposal_id = absint( $_GET['proposal_id'] ?? 0 );
        if ( $proposal_id ) {
            $this->render_proposal_detail( $proposal_id );
            return;
        }

        $this->header( 'Propuestas de actualización', 'Ninguna propuesta se publica automáticamente. Abre cada una para consultar pruebas, conflictos, cambios y vista previa.' );
        $status    = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
        $proposals = CEIA_Repository::list_proposals( 300, $status );
        echo '<section class="ceia-card"><div class="ceia-actions"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=ce-ia-proposals' ) ) . '">Todas</a>';
        foreach ( array( 'review_required' => 'Pendientes', 'approved' => 'Aprobadas', 'published' => 'Publicadas', 'rejected' => 'Rechazadas', 'no_change' => 'Sin cambios' ) as $value => $label ) {
            echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=ce-ia-proposals&status=' . $value ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div></section>';
        echo '<div class="ceia-table-wrap"><table class="ceia-table ceia-table--responsive"><thead><tr><th>Trámite</th><th>Estado</th><th>Validación</th><th>Riesgo</th><th>Fecha</th><th></th></tr></thead><tbody>';
        foreach ( $proposals as $proposal ) {
            echo '<tr><td data-label="Trámite"><div class="ceia-title">' . esc_html( $proposal['item_title'] ) . '</div><div class="ceia-small ceia-muted">' . esc_html( wp_trim_words( $proposal['summary'], 22 ) ) . '</div></td>';
            echo '<td data-label="Estado">' . $this->pill( $proposal['status'] ) . '</td><td data-label="Validación">' . $this->pill( $proposal['validation_status'] ) . '</td><td data-label="Riesgo">' . $this->pill( $proposal['risk'] ) . '</td><td data-label="Fecha">' . esc_html( $this->format_gmt( $proposal['created_gmt'] ) ) . '</td><td data-label="Acción"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=ce-ia-proposals&proposal_id=' . absint( $proposal['id'] ) ) ) . '">Abrir revisión</a></td></tr>';
        }
        if ( ! $proposals ) {
            echo '<tr><td colspan="6">No hay propuestas con este filtro.</td></tr>';
        }
        echo '</tbody></table></div>';
        $this->footer();
    }

    private function render_proposal_detail( $proposal_id ) {
        $proposal = CEIA_Repository::get_proposal( $proposal_id );
        if ( ! $proposal ) {
            wp_die( esc_html__( 'La propuesta no existe.', 'ce-ia-auditor' ) );
        }
        $this->header( 'Revisión: ' . $proposal['item_title'], 'Comprueba el resumen, cada evidencia y los conflictos antes de aprobar. Aprobar y publicar son dos acciones separadas.' );
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ce-ia-proposals' ) ) . '">← Volver a propuestas</a> · <a href="' . esc_url( $proposal['item_url'] ) . '" target="_blank" rel="noopener noreferrer">Abrir página publicada</a></p>';
        echo '<div class="ceia-proposal-meta"><div class="ceia-meta-item"><b>Estado</b>' . $this->pill( $proposal['status'] ) . '</div><div class="ceia-meta-item"><b>Validación</b>' . $this->pill( $proposal['validation_status'] ) . '</div><div class="ceia-meta-item"><b>Riesgo</b>' . $this->pill( $proposal['risk'] ) . '</div><div class="ceia-meta-item"><b>Generada</b>' . esc_html( $this->format_gmt( $proposal['created_gmt'] ) ) . '</div></div>';
        if ( 'conflict' === $proposal['validation_status'] || 'insufficient_evidence' === $proposal['validation_status'] ) {
            echo '<p class="ceia-callout ceia-callout--danger"><b>Publicación bloqueada.</b> Hay contradicciones o faltan pruebas oficiales suficientes.</p>';
        }
        echo '<div class="ceia-grid"><main class="ceia-col-8">';
        echo '<section class="ceia-card"><h2>Resumen ejecutivo</h2><p>' . nl2br( esc_html( $proposal['summary'] ) ) . '</p></section>';
        $this->json_section( 'Cambios propuestos', $proposal['changes_json'] );
        $this->json_section( 'Conflictos y dudas', $proposal['conflicts_json'], true );
        $this->json_section( 'Hechos comprobados', $proposal['facts_json'] );
        $this->evidence_section( $proposal );

        if ( '' !== trim( (string) $proposal['proposed_content'] ) ) {
            echo '<section class="ceia-card"><h2>Vista previa responsive</h2><p class="ceia-muted">La vista está aislada y no ejecuta scripts. Prueba también el ancho móvil reduciendo la ventana.</p><iframe class="ceia-preview" sandbox="" title="Vista previa del HTML propuesto" srcdoc="' . esc_attr( $proposal['proposed_content'] ) . '"></iframe></section>';
            echo '<section class="ceia-card"><h2>Diferencias del código</h2>';
            if ( ! function_exists( 'wp_text_diff' ) ) {
                require_once ABSPATH . WPINC . '/wp-diff.php';
            }
            $diff = wp_text_diff( (string) $proposal['current_content'], (string) $proposal['proposed_content'], array( 'show_split_view' => true ) );
            echo $diff ? $diff : '<p>No se han detectado diferencias de contenido.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</section>';
            echo '<details class="ceia-card"><summary><b>HTML propuesto completo</b></summary><pre class="ceia-code">' . esc_html( $proposal['proposed_content'] ) . '</pre></details>';
        }
        $this->json_section( 'Cambios del índice de trámites', $proposal['proposed_fields_json'] );
        echo '</main><aside class="ceia-col-4"><section class="ceia-card ceia-review-box"><h2>Decisión humana</h2>';
        echo '<p>Primero aprueba el contenido; después aparecerá la opción de publicar. Los conflictos no admiten aprobación.</p>';
        if ( 'review_required' === $proposal['status'] ) {
            $this->action_form_start( 'approve' );
            echo '<input type="hidden" name="proposal_id" value="' . absint( $proposal_id ) . '"><label for="ceia-review-note"><b>Observaciones de revisión</b></label><textarea id="ceia-review-note" name="review_note" placeholder="Qué has comprobado o qué queda observado"></textarea><p><button class="button button-primary">Aprobar sin publicar</button></p></form>';
            $this->action_form_start( 'reject' );
            echo '<input type="hidden" name="proposal_id" value="' . absint( $proposal_id ) . '"><label for="ceia-reject-note"><b>Motivo del rechazo</b></label><textarea id="ceia-reject-note" name="review_note" required></textarea><p><button class="button">Rechazar</button></p></form>';
        } elseif ( 'approved' === $proposal['status'] ) {
            echo '<p class="ceia-callout"><b>Aprobada, todavía no publicada.</b></p>';
            $this->action_form_start( 'publish' );
            echo '<input type="hidden" name="proposal_id" value="' . absint( $proposal_id ) . '"><button class="button button-primary" onclick="return confirm(\'¿Has comprobado las fuentes y quieres publicar esta versión?\')">Publicar versión aprobada</button></form>';
            $this->action_form_start( 'reject' );
            echo '<input type="hidden" name="proposal_id" value="' . absint( $proposal_id ) . '"><textarea name="review_note" required placeholder="Motivo para retirar la aprobación"></textarea><button class="button">Retirar y rechazar</button></form>';
        } elseif ( 'published' === $proposal['status'] ) {
            echo '<p class="ceia-callout"><b>Publicada.</b> La copia anterior se conserva.</p>';
            $this->action_form_start( 'rollback' );
            echo '<input type="hidden" name="proposal_id" value="' . absint( $proposal_id ) . '"><button class="button ceia-button-danger" onclick="return confirm(\'¿Restaurar exactamente la versión anterior?\')">Restaurar versión anterior</button></form>';
        } else {
            echo '<p>Esta propuesta está en estado ' . esc_html( $proposal['status'] ) . ' y no admite nuevas acciones.</p>';
        }
        if ( $proposal['review_note'] ) {
            echo '<hr><h3>Nota conservada</h3><p>' . nl2br( esc_html( $proposal['review_note'] ) ) . '</p>';
        }
        echo '</section></aside></div>';
        $this->footer();
    }

    private function evidence_section( $proposal ) {
        $evidence = CEIA_Repository::list_evidence_for_job( absint( $proposal['job_id'] ) );
        echo '<section class="ceia-card"><h2>Evidencias consultadas</h2>';
        if ( ! $evidence ) {
            echo '<p class="ceia-callout ceia-callout--danger">No se guardó ninguna evidencia. No publiques esta propuesta.</p></section>';
            return;
        }
        echo '<div class="ceia-table-wrap"><table class="ceia-table ceia-table--responsive"><thead><tr><th>Fuente</th><th>Autoridad</th><th>Consulta</th><th>Extracto</th></tr></thead><tbody>';
        foreach ( $evidence as $entry ) {
            echo '<tr><td data-label="Fuente"><a href="' . esc_url( $entry['url'] ) . '" target="_blank" rel="noopener noreferrer"><b>' . esc_html( $entry['title'] ?: $entry['url'] ) . '</b></a><div class="ceia-small ceia-muted">' . esc_html( $entry['local_id'] ?: 'Evidencia' ) . ' · ' . esc_html( $entry['source_type'] ) . ' · HTTP ' . absint( $entry['http_status'] ) . '</div></td><td data-label="Autoridad">' . absint( $entry['authority'] ) . '/100</td><td data-label="Consulta">' . esc_html( $this->format_gmt( $entry['retrieved_gmt'] ) ) . '</td><td data-label="Extracto"><details><summary>Ver pasaje conservado</summary><p>' . nl2br( esc_html( $entry['excerpt'] ) ) . '</p></details></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function json_section( $title, $json, $danger = false ) {
        $data = CEIA_Security::safe_json( $json );
        echo '<section class="ceia-card"><h2>' . esc_html( $title ) . '</h2>';
        if ( empty( $data ) ) {
            echo '<p class="ceia-muted">No hay elementos.</p>';
        } else {
            if ( $danger ) {
                echo '<p class="ceia-callout ceia-callout--danger">Revisa cada discrepancia antes de tomar una decisión.</p>';
            }
            echo '<pre class="ceia-json">' . esc_html( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
        }
        echo '</section>';
    }

    public function render_sources() {
        $this->header( 'Registro de fuentes', 'Las fuentes globales se consultan para todos los trámites; las específicas se añaden solo al elemento seleccionado. Una fuente externa puede descubrir pistas, pero no probar por sí sola un dato crítico.' );
        $items   = CEIA_Repository::list_items( array( 'limit' => 500 ) );
        $sources = CEIA_Repository::list_sources();
        echo '<section class="ceia-card"><h2>Añadir o actualizar una fuente</h2>';
        $this->action_form_start( 'save_source' );
        echo '<div class="ceia-form-grid"><label class="ceia-label" for="ceia-source-item">Ámbito</label><div><select id="ceia-source-item" name="item_id"><option value="0">Global: todos los trámites</option>';
        foreach ( $items as $item ) {
            echo '<option value="' . absint( $item['id'] ) . '">' . esc_html( $item['title'] ) . '</option>';
        }
        echo '</select></div><label class="ceia-label" for="ceia-source-label">Nombre</label><div><input id="ceia-source-label" name="label" type="text" required></div><label class="ceia-label" for="ceia-source-url">URL HTTPS</label><div><input id="ceia-source-url" name="url" type="url" required placeholder="https://..."></div><label class="ceia-label" for="ceia-source-type">Tipo</label><div><select id="ceia-source-type" name="source_type">';
        foreach ( array( 'official_gazette' => 'Boletín oficial', 'official_registry' => 'Sede o registro oficial', 'institutional' => 'Portal institucional', 'council' => 'Web del Consejo', 'external_lead' => 'Pista externa' ) as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></div><label class="ceia-label" for="ceia-source-authority">Autoridad (0–100)</label><div><input id="ceia-source-authority" name="authority" type="number" min="0" max="100" value="85"><p class="description">100: norma o boletín oficial; 95: sede; 85: portal institucional; 75: Consejo; 25: pista externa.</p></div><div class="ceia-label">Estado</div><div><label><input type="checkbox" name="active" value="1" checked> Fuente activa</label></div></div><p><button class="button button-primary">Guardar fuente</button></p></form></section>';
        echo '<div class="ceia-table-wrap"><table class="ceia-table ceia-table--responsive"><thead><tr><th>Ámbito</th><th>Fuente</th><th>Tipo</th><th>Autoridad</th><th>Estado</th><th></th></tr></thead><tbody>';
        foreach ( $sources as $source ) {
            echo '<tr><td data-label="Ámbito">' . ( absint( $source['item_id'] ) ? esc_html( $source['item_title'] ) : '<b>Global</b>' ) . '</td><td data-label="Fuente"><a href="' . esc_url( $source['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $source['label'] ) . '</a></td><td data-label="Tipo">' . esc_html( $source['source_type'] ) . '</td><td data-label="Autoridad">' . absint( $source['authority'] ) . '/100</td><td data-label="Estado">' . ( $source['active'] ? 'Activa' : 'Inactiva' ) . '</td><td data-label="Acción">';
            $this->action_form_start( 'delete_source' );
            echo '<input type="hidden" name="source_id" value="' . absint( $source['id'] ) . '"><button class="button" onclick="return confirm(\'¿Eliminar esta fuente?\')">Eliminar</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
        $this->footer();
    }

    public function render_settings() {
        $this->header( 'Configuración gratuita', 'Las claves se cifran con Sodium y las sales de WordPress. Nunca se muestran de nuevo ni se escriben en GitHub. El trabajador solo recibe contenido ya publicado y fuentes públicas.' );
        $s = CEIA_Repository::get_settings();
        $this->action_form_start( 'save_settings' );
        echo '<section class="ceia-card"><h2>Inteligencia e investigación</h2><div class="ceia-form-grid">';
        echo '<div class="ceia-label">Proveedor de análisis</div><div><b>Gemini · nivel gratuito únicamente</b><p class="description">Esta edición no contiene ningún proveedor de pago. La publicación siempre requiere aprobación humana.</p></div>';
        echo '<label class="ceia-label" for="ceia-gemini-model">Modelo Gemini</label><div><select id="ceia-gemini-model" name="gemini_model"><option value="gemini-3.1-flash-lite" ' . selected( $s['gemini_model'], 'gemini-3.1-flash-lite', false ) . '>Gemini 3.1 Flash-Lite</option><option value="gemini-2.5-flash-lite" ' . selected( $s['gemini_model'], 'gemini-2.5-flash-lite', false ) . '>Gemini 2.5 Flash-Lite</option></select></div>';
        echo '<label class="ceia-label" for="ceia-gemini-key">Clave Gemini</label><div><input id="ceia-gemini-key" name="gemini_api_key" type="password" autocomplete="new-password" placeholder="Dejar vacío para conservar"><p class="description">Estado: <b>' . esc_html( CEIA_Security::mask_secret( $s['gemini_api_key'] ) ) . '</b>. Solo usa datos públicos; el nivel gratuito puede emplear las entradas para mejorar sus servicios.</p><label><input type="checkbox" name="clear_gemini_api_key" value="1"> Borrar clave guardada</label></div>';
        echo '<div class="ceia-label">Descubrimiento web</div><div><label><input type="checkbox" name="tavily_enabled" value="1" ' . checked( $s['tavily_enabled'], 1, false ) . '> Activar Tavily gratuito para descubrir fuentes candidatas</label></div>';
        echo '<label class="ceia-label" for="ceia-tavily-key">Clave Tavily</label><div><input id="ceia-tavily-key" name="tavily_api_key" type="password" autocomplete="new-password" placeholder="Dejar vacío para conservar"><p class="description">Estado: <b>' . esc_html( CEIA_Security::mask_secret( $s['tavily_api_key'] ) ) . '</b>. Es opcional: sin Tavily se revisan fuentes registradas y enlaces oficiales.</p><label><input type="checkbox" name="clear_tavily_api_key" value="1"> Borrar clave guardada</label></div>';
        echo '</div></section>';

        echo '<section class="ceia-card"><h2>Presupuesto cero y frecuencia</h2><div class="ceia-form-grid"><div class="ceia-label">Programación</div><div><label><input type="checkbox" name="automatic_queue" value="1" ' . checked( $s['automatic_queue'], 1, false ) . '> Añadir automáticamente revisiones vencidas a la cola</label></div>';
        $this->number_field( 'daily_queue_limit', 'Máximo diario en cola', $s['daily_queue_limit'], 1, 25 );
        $this->number_field( 'max_jobs_per_run', 'Máximo por ejecución', $s['max_jobs_per_run'], 1, 25 );
        $this->number_field( 'max_sources_per_job', 'Fuentes por trámite', $s['max_sources_per_job'], 3, 30 );
        $this->number_field( 'max_searches_per_job', 'Búsquedas por trámite', $s['max_searches_per_job'], 0, 5 );
        $this->number_field( 'max_source_bytes', 'Bytes máximos por fuente', $s['max_source_bytes'], 100000, 10000000 );
        echo '</div><p class="ceia-callout"><b>Protección de coste:</b> esta edición solo admite Gemini en un proyecto sin facturación; no contiene integración con OpenAI ni otro proveedor de pago. El trabajador se detiene al alcanzar estos límites.</p></section>';

        echo '<section class="ceia-card"><h2>GitHub Actions</h2><p>El token es opcional y solo permite que el botón «ejecutar ahora» despierte el flujo. Sin token, el trabajo se procesa en el siguiente horario de GitHub.</p><div class="ceia-form-grid">';
        $this->text_field( 'github_owner', 'Propietario', $s['github_owner'] );
        $this->text_field( 'github_repository', 'Repositorio', $s['github_repository'] );
        $this->text_field( 'github_workflow', 'Archivo de flujo', $s['github_workflow'] );
        $this->text_field( 'github_branch', 'Rama', $s['github_branch'] );
        echo '<label class="ceia-label" for="ceia-github-token">Token de GitHub</label><div><input id="ceia-github-token" name="github_token" type="password" autocomplete="new-password" placeholder="Dejar vacío para conservar"><p class="description">Estado: <b>' . esc_html( CEIA_Security::mask_secret( $s['github_token'] ) ) . '</b>. Usa un token restringido al repositorio y al permiso Actions: write.</p><label><input type="checkbox" name="clear_github_token" value="1"> Borrar token guardado</label></div></div></section>';

        echo '<section class="ceia-card"><h2>Avisos</h2><div class="ceia-form-grid"><label class="ceia-label" for="ceia-email">Correo de sistema</label><div><input id="ceia-email" name="notification_email" type="email" value="' . esc_attr( $s['notification_email'] ) . '" required><p class="description">Recibe propuestas listas, conflictos y fallos. No es el correo que se muestra al estudiantado.</p></div></div></section>';
        echo '<p><button class="button button-primary">Guardar configuración</button></p></form>';
        $this->action_form_start( 'test_email' );
        echo '<p><button class="button">Enviar correo de prueba</button></p></form>';
        $this->footer();
    }

    public function render_system() {
        $this->header( 'Sistema, compatibilidad y auditoría', 'Comprobaciones técnicas y trazabilidad. Este panel no expone claves, usuarios ni contenido privado.' );
        $timezone  = wp_timezone_string();
        $heartbeat = CEIA_Repository::get_heartbeat();
        $duplicates= CEIA_Repository::duplicate_item_urls();
        echo '<div class="ceia-grid"><section class="ceia-card ceia-col-6"><h2>Compatibilidad</h2><ul class="ceia-checklist"><li>WordPress: ' . esc_html( get_bloginfo( 'version' ) ) . '</li><li>PHP: ' . esc_html( PHP_VERSION ) . '</li><li>Plugin CE-IA: ' . esc_html( CEIA_VERSION ) . '</li><li>REST autenticada: disponible mediante contraseñas de aplicación</li><li>Sodium: ' . ( function_exists( 'sodium_crypto_secretbox' ) ? 'disponible' : 'no disponible' ) . '</li><li>WP-Cron: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'desactivado' : 'activo' ) . '</li></ul></section>';
        echo '<section class="ceia-card ceia-col-6"><h2>Ajustes recomendados</h2>';
        if ( 'Europe/Madrid' !== $timezone ) {
            echo '<p class="ceia-callout ceia-callout--warning"><b>Zona horaria:</b> está configurada como ' . esc_html( $timezone ?: 'UTC' ) . '. En Ajustes → Generales conviene seleccionar Europe/Madrid para que los plazos cambien de estado a la hora correcta.</p>';
        } else {
            echo '<p class="ceia-callout"><b>Zona horaria correcta:</b> Europe/Madrid.</p>';
        }
        if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
            echo '<p class="ceia-callout ceia-callout--warning">WP_DEBUG_DISPLAY está activo. En producción conviene desactivarlo para no mostrar errores técnicos al público.</p>';
        }
        echo '<p>La investigación pesada se ejecuta fuera de WordPress porque el alojamiento dispone de límites ajustados. El panel solo coordina y conserva resultados.</p></section></div>';

        echo '<section class="ceia-card"><h2>Última señal del trabajador</h2><pre class="ceia-json">' . esc_html( wp_json_encode( $heartbeat ?: array( 'estado' => 'sin señal' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></section>';
        echo '<section class="ceia-card"><h2>URL duplicadas que requieren criterio humano</h2>';
        if ( ! $duplicates ) {
            echo '<p>No se han detectado URL repetidas.</p>';
        } else {
            echo '<div class="ceia-table-wrap"><table class="ceia-table ceia-table--responsive"><thead><tr><th>URL</th><th>Registros</th><th>Títulos</th></tr></thead><tbody>';
            foreach ( $duplicates as $duplicate ) {
                echo '<tr><td data-label="URL"><a href="' . esc_url( $duplicate['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $duplicate['url'] ) . '</a></td><td data-label="Registros">' . absint( $duplicate['total'] ) . '</td><td data-label="Títulos">' . esc_html( str_replace( ' || ', ' · ', $duplicate['titles'] ) ) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';

        $logs = CEIA_Repository::recent_logs( 100 );
        echo '<section class="ceia-card"><h2>Registro de auditoría</h2><div class="ceia-table-wrap"><table class="ceia-table ceia-table--responsive"><thead><tr><th>Fecha</th><th>Acción</th><th>Objeto</th><th>Actor</th><th>Detalle</th></tr></thead><tbody>';
        foreach ( $logs as $log ) {
            echo '<tr><td data-label="Fecha">' . esc_html( $this->format_gmt( $log['created_gmt'] ) ) . '</td><td data-label="Acción">' . esc_html( $log['action'] ) . '</td><td data-label="Objeto">' . esc_html( $log['object_type'] . ':' . $log['object_id'] ) . '</td><td data-label="Actor">' . esc_html( $log['actor'] ) . '</td><td data-label="Detalle"><code>' . esc_html( $log['detail'] ) . '</code></td></tr>';
        }
        echo '</tbody></table></div></section>';
        $this->footer();
    }

    private function jobs_table( $jobs ) {
        echo '<div class="ceia-table-wrap"><table class="ceia-table ceia-table--responsive"><thead><tr><th>Trámite</th><th>Estado</th><th>Solicitud</th><th>Intento</th><th>Detalle</th></tr></thead><tbody>';
        foreach ( $jobs as $job ) {
            echo '<tr><td data-label="Trámite"><div class="ceia-title">' . esc_html( $job['item_title'] ) . '</div></td><td data-label="Estado">' . $this->pill( $job['state'] ) . '</td><td data-label="Solicitud">' . esc_html( $this->format_gmt( $job['requested_gmt'] ) ) . '</td><td data-label="Intento">' . absint( $job['attempt'] ) . '/3</td><td data-label="Detalle">' . esc_html( $job['error_message'] ?: wp_trim_words( $job['summary'], 18 ) ) . '</td></tr>';
        }
        if ( ! $jobs ) {
            echo '<tr><td colspan="5">Aún no hay actividad.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function pill( $value ) {
        $value  = sanitize_key( $value );
        $labels = array(
            'low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto', 'critical' => 'Crítico',
            'queued' => 'En cola', 'running' => 'En curso', 'completed' => 'Terminada', 'failed' => 'Fallida',
            'review_required' => 'Revisión humana', 'approved' => 'Aprobada', 'published' => 'Publicada', 'rejected' => 'Rechazada', 'rolled_back' => 'Revertida', 'no_change' => 'Sin cambios',
            'verified' => 'Verificada', 'verified_with_observations' => 'Verificada con observaciones', 'human_review' => 'Revisión humana', 'conflict' => 'Conflicto', 'insufficient_evidence' => 'Pruebas insuficientes',
            'never_reviewed' => 'Nunca revisado',
        );
        $bad  = in_array( $value, array( 'critical', 'failed', 'conflict', 'insufficient_evidence' ), true );
        $warn = in_array( $value, array( 'high', 'review_required', 'human_review' ), true );
        $ok   = in_array( $value, array( 'verified', 'published', 'completed', 'no_change' ), true );
        $class= $bad ? 'ceia-pill--bad' : ( $warn ? 'ceia-pill--warn' : ( $ok ? 'ceia-pill--ok' : 'ceia-pill--info' ) );
        return '<span class="ceia-pill ' . esc_attr( $class ) . '">' . esc_html( $labels[ $value ] ?? $value ) . '</span>';
    }

    private function format_gmt( $value ) {
        if ( ! $value || '0000-00-00 00:00:00' === $value ) {
            return '—';
        }
        $timestamp = strtotime( $value . ' UTC' );
        return $timestamp ? wp_date( 'd/m/Y H:i', $timestamp, wp_timezone() ) : $value;
    }

    private function number_field( $name, $label, $value, $min, $max ) {
        echo '<label class="ceia-label" for="ceia-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><div><input id="ceia-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="number" min="' . absint( $min ) . '" max="' . absint( $max ) . '" value="' . absint( $value ) . '"></div>';
    }

    private function text_field( $name, $label, $value ) {
        echo '<label class="ceia-label" for="ceia-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><div><input id="ceia-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="text" value="' . esc_attr( $value ) . '"></div>';
    }
}
