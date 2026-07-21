<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic publication gates independent from the language model.
 */
final class CEIA_Quality {
    const OPTION_PREFIX = 'ceia_quality_report_';
    const MANAGED_PREFIX = 'https://www.unioviedo.es/cestudiantes/';
    const INDEX_URL = 'https://www.unioviedo.es/cestudiantes/index.php/tramites-uniovi/';

    public static function register_hooks() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 45 );
        add_action( 'admin_notices', array( __CLASS__, 'proposal_notice' ) );
    }

    public static function register_menu() {
        add_submenu_page(
            'ce-ia',
            'Control de calidad CE-IA',
            'Calidad',
            'ceia_review_proposals',
            'ce-ia-quality',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function evaluate_result( $public_id, $result ) {
        $result = is_array( $result ) ? $result : array();
        $job    = CEIA_Repository::get_job_by_public_id( $public_id );
        $checks = array();
        $blocked = array();
        $warnings = array();

        if ( ! $job ) {
            return array( $result, self::empty_report( array( 'No existe el trabajo asociado.' ) ) );
        }

        $item = CEIA_Repository::get_item( absint( $job['item_id'] ) );
        $current = '';
        if ( $item && ! empty( $item['post_id'] ) ) {
            $post = get_post( absint( $item['post_id'] ) );
            if ( $post ) {
                $current = (string) $post->post_content;
            }
        }

        $change_required = ! empty( $result['change_required'] );
        $proposed = isset( $result['proposed_content'] ) ? (string) $result['proposed_content'] : '';
        $risk = sanitize_key( $result['risk'] ?? ( $item['risk'] ?? 'medium' ) );
        $validation = sanitize_key( $result['validation_status'] ?? 'human_review' );

        if ( ! $change_required ) {
            $report = array(
                'gate'            => 'not_applicable',
                'checks'          => array(
                    array(
                        'check_id' => 'no_change',
                        'label'    => 'Sin cambio propuesto',
                        'status'   => 'pass',
                        'detail'   => 'El trabajador no solicita modificar la página.',
                    ),
                ),
                'retention_ratio' => 1,
                'blocked_reasons' => array(),
                'warnings'        => array(),
                'worker_report'   => self::sanitize_deep( $result['quality_report'] ?? array() ),
                'evidence_summary'=> self::evidence_summary( $result['evidence'] ?? array() ),
            );
            $result['publication_gate'] = 'not_applicable';
            return array( $result, $report );
        }

        $html_check = CEIA_Security::validate_proposed_html( $proposed );
        if ( is_wp_error( $html_check ) ) {
            $blocked[] = $html_check->get_error_message();
            $checks[] = self::check( 'html', 'HTML estructural', 'blocked', $html_check->get_error_message() );
        } else {
            $checks[] = self::check( 'html', 'HTML estructural', 'pass', 'La estructura básica no contiene elementos prohibidos.' );
        }

        $retention = self::retention_ratio( $current, $proposed );
        if ( $retention < 0.80 ) {
            $detail = sprintf( 'Solo se conserva el %.0f%% del texto de la página; el mínimo es 80%%.', $retention * 100 );
            $blocked[] = $detail;
            $checks[] = self::check( 'retention', 'Conservación de contenido', 'blocked', $detail );
        } else {
            $checks[] = self::check( 'retention', 'Conservación de contenido', 'pass', sprintf( 'Retención textual: %.0f%%.', $retention * 100 ) );
        }

        $missing_critical = self::missing_critical_topics( $current, $proposed );
        if ( $missing_critical ) {
            $detail = 'Desaparecen temas críticos presentes en la página actual: ' . implode( ', ', $missing_critical ) . '.';
            $blocked[] = $detail;
            $checks[] = self::check( 'critical_content', 'Contenido crítico', 'blocked', $detail );
        } else {
            $checks[] = self::check( 'critical_content', 'Contenido crítico', 'pass', 'No desaparece ninguna categoría crítica detectada.' );
        }

        $evidence_urls = array();
        foreach ( (array) ( $result['evidence'] ?? array() ) as $entry ) {
            if ( is_array( $entry ) && ! empty( $entry['url'] ) ) {
                $evidence_urls[] = esc_url_raw( (string) $entry['url'] );
            }
        }
        $link_result = self::validate_proposed_links( $current, $proposed, $evidence_urls );
        if ( $link_result['errors'] ) {
            $blocked = array_merge( $blocked, $link_result['errors'] );
            $checks[] = self::check( 'links', 'Enlaces trazables', 'blocked', implode( ' ', array_slice( $link_result['errors'], 0, 8 ) ) );
        } else {
            $checks[] = self::check( 'links', 'Enlaces trazables', 'pass', 'Los enlaces nuevos proceden de la página actual, del Consejo o de evidencias conservadas.' );
        }

        $worker_report = is_array( $result['quality_report'] ?? null ) ? $result['quality_report'] : array();
        $worker_gate = sanitize_key( $result['publication_gate'] ?? ( $worker_report['gate'] ?? 'blocked' ) );
        if ( 'pass' !== $worker_gate ) {
            $blocked[] = 'El trabajador no ha superado todos los controles deterministas.';
            $checks[] = self::check( 'worker_gate', 'Controles del trabajador', 'blocked', 'La puerta remitida por el trabajador es ' . $worker_gate . '.' );
        } else {
            $checks[] = self::check( 'worker_gate', 'Controles del trabajador', 'pass', 'El trabajador declara superados sus controles y WordPress los vuelve a comprobar.' );
        }

        if ( in_array( $risk, array( 'high', 'critical' ), true ) && 'verified' !== $validation ) {
            $detail = 'Los trámites de riesgo alto o crítico solo pueden avanzar con validación verified sin observaciones.';
            $blocked[] = $detail;
            $checks[] = self::check( 'high_risk', 'Riesgo jurídico', 'blocked', $detail );
        } else {
            $checks[] = self::check( 'high_risk', 'Riesgo jurídico', 'pass', 'El estado de validación es compatible con el nivel de riesgo.' );
        }

        if ( ! empty( $result['conflicts'] ) ) {
            $blocked[] = 'La propuesta contiene conflictos documentales.';
            $checks[] = self::check( 'conflicts', 'Conflictos', 'blocked', 'Existen conflictos y no puede aprobarse.' );
        } else {
            $checks[] = self::check( 'conflicts', 'Conflictos', 'pass', 'No se han remitido conflictos; el validador automático también debe haberlos descartado.' );
        }

        $index_check = self::validate_index_patch( $result['index_patch'] ?? array() );
        if ( is_wp_error( $index_check ) ) {
            $blocked[] = $index_check->get_error_message();
            $checks[] = self::check( 'index', 'Índice de trámites', 'blocked', $index_check->get_error_message() );
        } else {
            $checks[] = self::check( 'index', 'Índice de trámites', 'pass', 'El parche del índice es estructuralmente seguro.' );
        }

        $gate = $blocked ? 'blocked' : 'pass';
        if ( 'blocked' === $gate && 'conflict' !== $validation ) {
            $result['validation_status'] = 'insufficient_evidence';
        }
        $result['publication_gate'] = $gate;
        if ( $blocked ) {
            $result['summary'] = trim( (string) ( $result['summary'] ?? '' ) )
                . "\n\nBloqueos independientes de WordPress:\n- "
                . implode( "\n- ", array_slice( array_unique( $blocked ), 0, 30 ) );
        }

        $report = array(
            'gate'            => $gate,
            'checks'          => $checks,
            'retention_ratio' => $retention,
            'blocked_reasons' => array_values( array_unique( $blocked ) ),
            'warnings'        => $warnings,
            'worker_report'   => self::sanitize_deep( $worker_report ),
            'evidence_summary'=> self::evidence_summary( $result['evidence'] ?? array() ),
            'added_links'     => $link_result['added'],
            'removed_links'   => $link_result['removed'],
        );

        return array( $result, $report );
    }

    public static function store_report( $proposal_id, $report, $previews = array() ) {
        $report = is_array( $report ) ? self::sanitize_deep( $report ) : self::empty_report( array( 'Informe inválido.' ) );
        $report['previews'] = self::save_previews( absint( $proposal_id ), $previews );
        update_option( self::OPTION_PREFIX . absint( $proposal_id ), $report, false );
    }

    public static function get_report( $proposal_id ) {
        $report = get_option( self::OPTION_PREFIX . absint( $proposal_id ), array() );
        return is_array( $report ) ? $report : array();
    }

    public static function pre_publish( $proposal ) {
        $report = self::get_report( absint( $proposal['id'] ?? 0 ) );
        if ( ! $report || 'pass' !== ( $report['gate'] ?? '' ) ) {
            return new WP_Error( 'ceia_quality_gate', 'La propuesta no ha superado la puerta de calidad independiente.' );
        }

        if ( in_array( $proposal['risk'], array( 'high', 'critical' ), true ) && 'verified' !== $proposal['validation_status'] ) {
            return new WP_Error( 'ceia_high_risk_block', 'Los trámites de riesgo alto o crítico requieren validación verified sin observaciones.' );
        }

        $html_check = CEIA_Security::validate_proposed_html( (string) $proposal['proposed_content'] );
        if ( is_wp_error( $html_check ) ) {
            return $html_check;
        }

        foreach ( (array) ( $report['added_links'] ?? array() ) as $url ) {
            if ( 0 === strpos( $url, 'mailto:' ) || 0 === strpos( $url, 'tel:' ) || 0 === strpos( $url, '#' ) ) {
                continue;
            }
            $target = 0 === strpos( $url, '/' ) ? home_url( $url ) : $url;
            $response = wp_safe_remote_get(
                $target,
                array(
                    'timeout'     => 15,
                    'redirection' => 3,
                    'headers'     => array( 'Cache-Control' => 'no-cache' ),
                )
            );
            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'ceia_link_unreachable', 'No se pudo comprobar el enlace nuevo ' . esc_url_raw( $target ) . '.' );
            }
            $code = wp_remote_retrieve_response_code( $response );
            if ( 404 === $code || 410 === $code || $code >= 500 ) {
                return new WP_Error( 'ceia_link_broken', 'El enlace nuevo ' . esc_url_raw( $target ) . ' devuelve HTTP ' . absint( $code ) . '.' );
            }
        }
        return true;
    }

    public static function verify_after_publish( $proposal, $patch ) {
        $patch = is_array( $patch ) ? $patch : array();
        $target = ! empty( $patch['url'] ) ? esc_url_raw( (string) $patch['url'] ) : esc_url_raw( (string) $proposal['item_url'] );
        if ( ! self::is_managed_url( $target ) ) {
            return new WP_Error( 'ceia_target_scope', 'La URL final queda fuera de la web del Consejo.' );
        }

        $target_response = wp_safe_remote_get(
            add_query_arg( 'ceia_verify', time(), $target ),
            array( 'timeout' => 20, 'redirection' => 3, 'headers' => array( 'Cache-Control' => 'no-cache' ) )
        );
        if ( is_wp_error( $target_response ) || wp_remote_retrieve_response_code( $target_response ) < 200 || wp_remote_retrieve_response_code( $target_response ) >= 400 ) {
            return new WP_Error( 'ceia_public_page_failed', 'La página pública no responde correctamente después de actualizarla.' );
        }

        if ( $patch ) {
            $index_response = wp_safe_remote_get(
                add_query_arg( 'ceia_verify', time(), self::INDEX_URL ),
                array( 'timeout' => 20, 'redirection' => 3, 'headers' => array( 'Cache-Control' => 'no-cache' ) )
            );
            if ( is_wp_error( $index_response ) || wp_remote_retrieve_response_code( $index_response ) < 200 || wp_remote_retrieve_response_code( $index_response ) >= 400 ) {
                return new WP_Error( 'ceia_index_unreachable', 'No se pudo comprobar públicamente el índice de trámites.' );
            }
            $body = html_entity_decode( wp_remote_retrieve_body( $index_response ), ENT_QUOTES, get_bloginfo( 'charset' ) );
            $name = sanitize_text_field( $patch['nombre'] ?? $proposal['item_title'] ?? '' );
            if ( false === strpos( $body, $target ) && ( '' === $name || false === stripos( wp_strip_all_tags( $body ), $name ) ) ) {
                return new WP_Error( 'ceia_index_missing_item', 'El índice público no muestra el trámite actualizado; se debe revertir.' );
            }
        }

        return true;
    }

    private static function validate_index_patch( $patch ) {
        if ( ! is_array( $patch ) || ! $patch ) {
            return true;
        }
        if ( ! empty( $patch['url'] ) && ! self::is_managed_url( (string) $patch['url'] ) ) {
            return new WP_Error( 'ceia_index_url_scope', 'El índice solo puede apuntar a páginas bajo ' . self::MANAGED_PREFIX );
        }
        return true;
    }

    private static function is_managed_url( $url ) {
        $parts = wp_parse_url( esc_url_raw( (string) $url ) );
        if ( ! is_array( $parts ) ) {
            return false;
        }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
        $path = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
        return 'https' === $scheme
            && 'www.unioviedo.es' === $host
            && ( '/cestudiantes' === rtrim( $path, '/' ) || 0 === strpos( $path, '/cestudiantes/' ) );
    }

    private static function retention_ratio( $current, $proposed ) {
        $current_words = self::word_set( self::visible_text( $current ) );
        $proposed_words = self::word_set( self::visible_text( $proposed ) );
        if ( ! $current_words ) {
            return 1;
        }
        return count( array_intersect_key( $current_words, $proposed_words ) ) / count( $current_words );
    }

    private static function missing_critical_topics( $current, $proposed ) {
        $topics = array(
            'plazos'       => '/plazo|fecha/i',
            'requisitos'   => '/requisit|beneficiari|destinatari/i',
            'documentación'=> '/documentaci|documentos/i',
            'recursos'     => '/recurso|alegaci|reclamaci/i',
            'contacto'     => '/contacto|correo|tel[eé]fono/i',
            'órgano'       => '/tramita|resuelve|órgano|organo competente/i',
            'importes'     => '/importe|precio|euros|pago/i',
        );
        $current_text = self::visible_text( $current );
        $proposed_text = self::visible_text( $proposed );
        $missing = array();
        foreach ( $topics as $label => $pattern ) {
            if ( preg_match( $pattern, $current_text ) && ! preg_match( $pattern, $proposed_text ) ) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    private static function validate_proposed_links( $current, $proposed, $evidence_urls ) {
        $current_links = self::extract_links( $current );
        $proposed_links = self::extract_links( $proposed );
        $allowed = array_fill_keys( array_merge( $current_links, array_map( 'esc_url_raw', (array) $evidence_urls ) ), true );
        $errors = array();
        foreach ( $proposed_links as $url ) {
            if ( 0 === strpos( $url, 'mailto:' ) || 0 === strpos( $url, 'tel:' ) || 0 === strpos( $url, '#' ) ) {
                continue;
            }
            if ( 0 === strpos( $url, '/' ) || self::is_managed_url( $url ) || isset( $allowed[ $url ] ) ) {
                continue;
            }
            $errors[] = 'El enlace nuevo ' . $url . ' no procede de una evidencia ni de la página actual.';
        }
        return array(
            'errors'  => $errors,
            'added'   => array_values( array_diff( $proposed_links, $current_links ) ),
            'removed' => array_values( array_diff( $current_links, $proposed_links ) ),
        );
    }

    private static function extract_links( $html ) {
        preg_match_all( '/\shref\s*=\s*["\']([^"\']+)["\']/i', (string) $html, $matches );
        return array_values( array_unique( array_map( 'esc_url_raw', $matches[1] ?? array() ) ) );
    }

    private static function visible_text( $html ) {
        $html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', (string) $html );
        return html_entity_decode( wp_strip_all_tags( $html, true ), ENT_QUOTES, get_bloginfo( 'charset' ) );
    }

    private static function word_set( $text ) {
        $text = remove_accents( strtolower( (string) $text ) );
        preg_match_all( '/[a-z0-9]{4,}/', $text, $matches );
        $stop = array_flip( array( 'para', 'como', 'desde', 'hasta', 'sobre', 'entre', 'esta', 'este', 'estos', 'estas', 'universidad', 'oviedo' ) );
        $set = array();
        foreach ( $matches[0] as $word ) {
            if ( ! isset( $stop[ $word ] ) ) {
                $set[ $word ] = true;
            }
        }
        return $set;
    }

    private static function save_previews( $proposal_id, $previews ) {
        $saved = array();
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            return $saved;
        }
        $directory = trailingslashit( $upload['basedir'] ) . 'ceia-previews';
        if ( ! wp_mkdir_p( $directory ) ) {
            return $saved;
        }
        foreach ( array_slice( (array) $previews, 0, 4 ) as $preview ) {
            if ( ! is_array( $preview ) || 'image/jpeg' !== ( $preview['mime'] ?? '' ) ) {
                continue;
            }
            $width = absint( $preview['width'] ?? 0 );
            if ( ! in_array( $width, array( 360, 390, 768, 1440 ), true ) ) {
                continue;
            }
            $binary = base64_decode( (string) ( $preview['data'] ?? '' ), true );
            if ( false === $binary || strlen( $binary ) > 2000000 || 0 !== strncmp( $binary, "\xFF\xD8\xFF", 3 ) ) {
                continue;
            }
            $filename = 'proposal-' . absint( $proposal_id ) . '-' . $width . '.jpg';
            if ( false === file_put_contents( trailingslashit( $directory ) . $filename, $binary, LOCK_EX ) ) {
                continue;
            }
            $saved[] = array(
                'width' => $width,
                'url'   => trailingslashit( $upload['baseurl'] ) . 'ceia-previews/' . rawurlencode( $filename ),
            );
        }
        return $saved;
    }

    private static function evidence_summary( $evidence ) {
        $summary = array();
        foreach ( array_slice( (array) $evidence, 0, 30 ) as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $summary[] = array(
                'id'               => sanitize_key( $entry['local_id'] ?? '' ),
                'url'              => esc_url_raw( $entry['url'] ?? '' ),
                'title'            => sanitize_text_field( $entry['title'] ?? '' ),
                'authority'        => absint( $entry['authority'] ?? 0 ),
                'relevance_score'  => absint( $entry['relevance_score'] ?? 0 ),
                'required'         => ! empty( $entry['required'] ),
                'primary'          => ! empty( $entry['primary'] ),
                'retrieval_status' => sanitize_key( $entry['retrieval_status'] ?? '' ),
                'retrieval_error'  => sanitize_text_field( $entry['retrieval_error'] ?? '' ),
            );
        }
        return $summary;
    }

    private static function check( $id, $label, $status, $detail ) {
        return array(
            'check_id' => sanitize_key( $id ),
            'label'    => sanitize_text_field( $label ),
            'status'   => in_array( $status, array( 'pass', 'warning', 'blocked' ), true ) ? $status : 'blocked',
            'detail'   => sanitize_textarea_field( $detail ),
        );
    }

    private static function empty_report( $reasons ) {
        return array(
            'gate'            => 'blocked',
            'checks'          => array(),
            'retention_ratio' => 0,
            'blocked_reasons' => array_map( 'sanitize_text_field', (array) $reasons ),
            'warnings'        => array(),
            'worker_report'   => array(),
            'evidence_summary'=> array(),
            'previews'        => array(),
        );
    }

    private static function sanitize_deep( $value ) {
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $key => $item ) {
                $clean[ is_int( $key ) ? $key : sanitize_key( $key ) ] = self::sanitize_deep( $item );
            }
            return $clean;
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
            return $value;
        }
        return mb_substr( sanitize_textarea_field( (string) $value ), 0, 10000 );
    }

    public static function proposal_notice() {
        if ( 'ce-ia-proposals' !== ( $_GET['page'] ?? '' ) || empty( $_GET['proposal_id'] ) ) {
            return;
        }
        $proposal_id = absint( $_GET['proposal_id'] );
        $report = self::get_report( $proposal_id );
        $gate = $report['gate'] ?? 'blocked';
        $class = 'pass' === $gate ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr( $class ) . '"><p><b>Puerta de calidad: ' . esc_html( strtoupper( $gate ) ) . '.</b> <a href="' . esc_url( admin_url( 'admin.php?page=ce-ia-quality&proposal_id=' . $proposal_id ) ) . '">Abrir controles objetivos y capturas responsive</a>.</p></div>';
    }

    public static function render_page() {
        if ( ! current_user_can( 'ceia_review_proposals' ) ) {
            return;
        }
        $proposal_id = absint( $_GET['proposal_id'] ?? 0 );
        echo '<div class="wrap ceia-admin"><h1>CE-IA · Control de calidad</h1>';
        if ( ! $proposal_id ) {
            echo '<p>Abre una propuesta y utiliza el enlace de su puerta de calidad.</p></div>';
            return;
        }
        $proposal = CEIA_Repository::get_proposal( $proposal_id );
        $report = self::get_report( $proposal_id );
        if ( ! $proposal || ! $report ) {
            echo '<div class="notice notice-error"><p>No existe un informe de calidad de la versión segura para esta propuesta.</p></div></div>';
            return;
        }
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ce-ia-proposals&proposal_id=' . $proposal_id ) ) . '">← Volver a la propuesta</a></p>';
        echo '<h2>Puerta: ' . esc_html( strtoupper( $report['gate'] ?? 'blocked' ) ) . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Control</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';
        foreach ( (array) ( $report['checks'] ?? array() ) as $check ) {
            echo '<tr><td>' . esc_html( $check['label'] ?? '' ) . '</td><td><b>' . esc_html( strtoupper( $check['status'] ?? '' ) ) . '</b></td><td>' . esc_html( $check['detail'] ?? '' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ( ! empty( $report['blocked_reasons'] ) ) {
            echo '<h2>Bloqueos</h2><ul>';
            foreach ( $report['blocked_reasons'] as $reason ) {
                echo '<li>' . esc_html( $reason ) . '</li>';
            }
            echo '</ul>';
        }
        echo '<h2>Fuentes evaluadas</h2><table class="widefat striped"><thead><tr><th>Fuente</th><th>Autoridad</th><th>Relevancia</th><th>Estado</th></tr></thead><tbody>';
        foreach ( (array) ( $report['evidence_summary'] ?? array() ) as $entry ) {
            echo '<tr><td><a href="' . esc_url( $entry['url'] ?? '' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $entry['title'] ?: $entry['url'] ) . '</a></td><td>' . absint( $entry['authority'] ?? 0 ) . '</td><td>' . absint( $entry['relevance_score'] ?? 0 ) . '</td><td>' . esc_html( $entry['retrieval_status'] ?? '' ) . ( ! empty( $entry['retrieval_error'] ) ? ': ' . esc_html( $entry['retrieval_error'] ) : '' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ( ! empty( $report['previews'] ) ) {
            echo '<h2>Capturas responsive reales</h2><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px">';
            foreach ( $report['previews'] as $preview ) {
                echo '<figure style="margin:0"><figcaption><b>' . absint( $preview['width'] ) . ' px</b></figcaption><a href="' . esc_url( $preview['url'] ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $preview['url'] ) . '" alt="Captura responsive a ' . absint( $preview['width'] ) . ' píxeles" style="display:block;max-width:100%;height:auto;border:1px solid #ccd0d4"></a></figure>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}
