<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CEIA_Repository {
    public static function tables() {
        global $wpdb;

        return array(
            'items'     => $wpdb->prefix . 'ceia_items',
            'sources'   => $wpdb->prefix . 'ceia_sources',
            'jobs'      => $wpdb->prefix . 'ceia_jobs',
            'evidence'  => $wpdb->prefix . 'ceia_evidence',
            'proposals' => $wpdb->prefix . 'ceia_proposals',
            'logs'      => $wpdb->prefix . 'ceia_logs',
            'tramites'  => $wpdb->prefix . 'tramites',
        );
    }

    public static function now() {
        return current_time( 'mysql', true );
    }

    public static function get_settings() {
        $defaults = array(
            'notification_email'   => 'web.cest@uniovi.es',
            'automatic_queue'      => 0,
            'daily_queue_limit'    => 5,
            'max_jobs_per_run'     => 5,
            'max_sources_per_job'  => 12,
            'max_searches_per_job' => 2,
            'max_source_bytes'     => 6000000,
            'analysis_provider'    => 'gemini',
            'gemini_model'         => 'gemini-3.1-flash-lite',
            'tavily_enabled'       => 0,
            'gemini_api_key'       => '',
            'tavily_api_key'       => '',
            'github_owner'         => 'proyectodocenciauo-cmyk',
            'github_repository'    => 'ce-uniovi-auditor-ia',
            'github_workflow'      => 'audit.yml',
            'github_branch'        => 'main',
            'github_token'         => '',
        );

        $stored = get_option( 'ceia_settings', array() );
        $stored = is_array( $stored ) ? $stored : array();
        unset( $stored['openai_model'], $stored['openai_api_key'] );
        $stored['analysis_provider'] = 'gemini';
        return wp_parse_args( $stored, $defaults );
    }

    public static function save_settings( $settings ) {
        update_option( 'ceia_settings', $settings, false );
        self::log( 'settings_updated', 'settings', 0, array( 'keys' => array_keys( $settings ) ) );
    }

    public static function seed_global_sources() {
        $sources = array(
            array( 'Boletín Oficial del Estado', 'https://www.boe.es/', 'official_gazette', 100 ),
            array( 'Boletín Oficial del Principado de Asturias', 'https://sede.asturias.es/bopa/', 'official_gazette', 100 ),
            array( 'Sede Electrónica de la Universidad de Oviedo', 'https://sede.uniovi.es/', 'official_registry', 95 ),
            array( 'Universidad de Oviedo', 'https://www.uniovi.es/', 'institutional', 85 ),
            array( 'Secretaría General de la Universidad de Oviedo', 'https://secretaria.uniovi.es/', 'institutional', 90 ),
            array( 'Consejo de Estudiantes de la Universidad de Oviedo', 'https://www.unioviedo.es/cestudiantes/', 'council', 75 ),
        );

        foreach ( $sources as $source ) {
            self::upsert_source( 0, $source[0], $source[1], $source[2], $source[3], 1 );
        }
    }

    public static function sync_tramites() {
        global $wpdb;

        $tables = self::tables();
        $exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tables['tramites'] ) )
        );

        if ( $exists !== $tables['tramites'] ) {
            return new WP_Error( 'ceia_tramites_missing', 'No se ha encontrado la tabla del plugin Trámites UniOvi.' );
        }

        $rows = $wpdb->get_results(
            "SELECT * FROM `{$tables['tramites']}` ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        $inserted = 0;
        $updated  = 0;
        $now      = self::now();

        foreach ( $rows as $row ) {
            $object_id = absint( $row['id'] ?? 0 );
            if ( ! $object_id ) {
                continue;
            }

            $url     = esc_url_raw( (string) ( $row['url'] ?? '' ) );
            $post_id = $url ? absint( url_to_postid( $url ) ) : 0;
            $content = '';
            if ( $post_id ) {
                $post = get_post( $post_id );
                if ( $post && 'publish' === $post->post_status ) {
                    $content = (string) $post->post_content;
                }
            }

            $hash     = CEIA_Security::hash( $content . '|' . wp_json_encode( $row ) );
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$tables['items']}` WHERE object_type = %s AND object_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    'tramite',
                    $object_id
                ),
                ARRAY_A
            );

            if ( $existing ) {
                $wpdb->update(
                    $tables['items'],
                    array(
                        'post_id'      => $post_id,
                        'title'        => sanitize_text_field( (string) $row['nombre'] ),
                        'url'          => $url,
                        'category'     => sanitize_text_field( (string) $row['tipo'] ),
                        'content_hash' => $hash,
                        'updated_gmt'  => $now,
                    ),
                    array( 'id' => absint( $existing['id'] ) ),
                    array( '%d', '%s', '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );
                $item_id = absint( $existing['id'] );
                $updated++;
            } else {
                $risk     = self::default_risk( (string) $row['tipo'], (string) $row['nombre'] );
                $interval = self::default_interval( $risk );
                $wpdb->insert(
                    $tables['items'],
                    array(
                        'object_type'         => 'tramite',
                        'object_id'           => $object_id,
                        'post_id'             => $post_id,
                        'title'               => sanitize_text_field( (string) $row['nombre'] ),
                        'url'                 => $url,
                        'category'            => sanitize_text_field( (string) $row['tipo'] ),
                        'risk'                => $risk,
                        'active'              => 1,
                        'review_interval_days'=> $interval,
                        'next_review_gmt'     => $now,
                        'last_status'         => 'never_reviewed',
                        'content_hash'        => $hash,
                        'created_gmt'         => $now,
                        'updated_gmt'         => $now,
                    ),
                    array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
                );
                $item_id = absint( $wpdb->insert_id );
                $inserted++;
            }

            if ( $item_id && $url ) {
                self::upsert_source(
                    $item_id,
                    'Destino publicado del trámite',
                    $url,
                    self::source_type_for_url( $url ),
                    self::authority_for_url( $url ),
                    1
                );
            }
        }

        self::log(
            'items_synced',
            'items',
            0,
            array(
                'inserted' => $inserted,
                'updated'  => $updated,
                'total'    => count( $rows ),
            )
        );

        return array( 'inserted' => $inserted, 'updated' => $updated, 'total' => count( $rows ) );
    }

    private static function default_risk( $category, $title ) {
        $value = remove_accents( strtolower( $category . ' ' . $title ) );

        if ( preg_match( '/beca|ayuda|precio|pago|devolucion|reclamacion|recurso|admision|acceso|matricula|anulacion|expediente|titulo|normativ|credito/', $value ) ) {
            return 'high';
        }

        if ( preg_match( '/eleccion|doctorado|evaluacion|certificad|cambio/', $value ) ) {
            return 'medium';
        }

        return 'low';
    }

    private static function default_interval( $risk ) {
        if ( 'high' === $risk || 'critical' === $risk ) {
            return 30;
        }

        return 'medium' === $risk ? 90 : 180;
    }

    public static function source_type_for_url( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

        if ( 'www.boe.es' === $host || 'boe.es' === $host || false !== strpos( $host, 'asturias.es' ) ) {
            return 'official_gazette';
        }
        if ( 'sede.uniovi.es' === $host || 'euniovi.uniovi.es' === $host ) {
            return 'official_registry';
        }
        if ( preg_match( '/(^|\.)unioviedo\.es$/', $host ) && false !== strpos( $url, '/cestudiantes/' ) ) {
            return 'council';
        }
        if ( preg_match( '/(^|\.)uniovi\.es$/', $host ) ) {
            return false !== strpos( $url, '/cestudiantes/' ) ? 'council' : 'institutional';
        }

        return 'external_lead';
    }

    public static function authority_for_url( $url ) {
        $type = self::source_type_for_url( $url );
        $map  = array(
            'official_gazette'  => 100,
            'official_registry' => 95,
            'institutional'     => 85,
            'council'           => 75,
            'external_lead'     => 25,
        );

        return $map[ $type ] ?? 20;
    }

    public static function upsert_source( $item_id, $label, $url, $source_type, $authority, $active = 1 ) {
        global $wpdb;
        $tables = self::tables();

        $label = sanitize_text_field( $label );
        if ( '' === trim( $label ) ) {
            return new WP_Error( 'ceia_source_label_required', 'La fuente debe tener un nombre reconocible.' );
        }
        $allowed_types = array( 'official_gazette', 'official_registry', 'institutional', 'council', 'external_lead' );
        $source_type   = sanitize_key( $source_type );
        if ( ! in_array( $source_type, $allowed_types, true ) ) {
            $source_type = 'institutional';
        }

        $validated = CEIA_Security::validate_https_url( $url );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$tables['sources']}` WHERE item_id = %d AND url = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                absint( $item_id ),
                $validated
            )
        );
        $now = self::now();

        $data = array(
            'item_id'     => absint( $item_id ),
            'label'       => $label,
            'url'         => $validated,
            'source_type' => $source_type,
            'authority'   => max( 0, min( 100, absint( $authority ) ) ),
            'active'      => $active ? 1 : 0,
            'updated_gmt' => $now,
        );

        if ( $existing_id ) {
            $updated = $wpdb->update( $tables['sources'], $data, array( 'id' => absint( $existing_id ) ) );
            if ( false === $updated ) {
                return new WP_Error( 'ceia_source_save_failed', 'No se pudo actualizar la fuente.' );
            }
            return absint( $existing_id );
        }

        $data['created_gmt'] = $now;
        $inserted = $wpdb->insert( $tables['sources'], $data );
        if ( false === $inserted ) {
            return new WP_Error( 'ceia_source_save_failed', 'No se pudo guardar la fuente.' );
        }
        return absint( $wpdb->insert_id );
    }

    public static function delete_source( $source_id ) {
        global $wpdb;
        $tables = self::tables();
        $result = $wpdb->delete( $tables['sources'], array( 'id' => absint( $source_id ) ), array( '%d' ) );
        if ( $result ) {
            self::log( 'source_deleted', 'source', absint( $source_id ) );
        }
        return (bool) $result;
    }

    public static function list_sources( $item_id = null ) {
        global $wpdb;
        $tables = self::tables();

        if ( null === $item_id ) {
            return $wpdb->get_results(
                "SELECT s.*, i.title AS item_title FROM `{$tables['sources']}` s LEFT JOIN `{$tables['items']}` i ON i.id = s.item_id ORDER BY s.item_id ASC, s.authority DESC, s.label ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ARRAY_A
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$tables['sources']}` WHERE active = 1 AND item_id IN (0,%d) ORDER BY item_id DESC, authority DESC, label ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                absint( $item_id )
            ),
            ARRAY_A
        );
    }

    public static function list_items( $args = array() ) {
        global $wpdb;
        $tables = self::tables();
        $args   = wp_parse_args(
            $args,
            array(
                'search' => '',
                'risk'   => '',
                'status' => '',
                'limit'  => 100,
                'offset' => 0,
            )
        );

        $where  = array( '1=1' );
        $params = array();
        if ( '' !== $args['search'] ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(title LIKE %s OR category LIKE %s OR url LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ( in_array( $args['risk'], array( 'low', 'medium', 'high', 'critical' ), true ) ) {
            $where[]  = 'risk = %s';
            $params[] = $args['risk'];
        }
        if ( '' !== $args['status'] ) {
            $where[]  = 'last_status = %s';
            $params[] = sanitize_key( $args['status'] );
        }

        $limit    = max( 1, min( 500, absint( $args['limit'] ) ) );
        $offset   = max( 0, absint( $args['offset'] ) );
        $params[] = $limit;
        $params[] = $offset;
        $sql      = "SELECT * FROM `{$tables['items']}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY title ASC LIMIT %d OFFSET %d'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function get_item( $item_id ) {
        global $wpdb;
        $tables = self::tables();

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$tables['items']}` WHERE id = %d", absint( $item_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );
    }

    public static function save_item_policy( $item_id, $risk, $interval, $active ) {
        global $wpdb;
        $tables = self::tables();
        $risk   = in_array( $risk, array( 'low', 'medium', 'high', 'critical' ), true ) ? $risk : 'medium';
        $result = $wpdb->update(
            $tables['items'],
            array(
                'risk'                 => $risk,
                'review_interval_days' => max( 1, min( 730, absint( $interval ) ) ),
                'active'               => $active ? 1 : 0,
                'updated_gmt'          => self::now(),
            ),
            array( 'id' => absint( $item_id ) ),
            array( '%s', '%d', '%d', '%s' ),
            array( '%d' )
        );

        if ( false !== $result ) {
            self::log( 'item_policy_updated', 'item', $item_id, compact( 'risk', 'interval', 'active' ) );
        }
        return false !== $result;
    }

    public static function queue_job( $item_id, $requested_by = 0, $trigger = 'manual', $payload = array() ) {
        global $wpdb;
        $tables = self::tables();
        $item   = self::get_item( $item_id );

        if ( ! $item || empty( $item['active'] ) ) {
            return new WP_Error( 'ceia_item_unavailable', 'El elemento no existe o está desactivado.' );
        }

        $pending = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$tables['jobs']}` WHERE item_id = %d AND state IN ('queued','running') LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                absint( $item_id )
            )
        );
        if ( $pending ) {
            return new WP_Error( 'ceia_job_exists', 'Este trámite ya tiene una investigación pendiente o en curso.' );
        }

        $public_id = wp_generate_uuid4();
        $inserted  = $wpdb->insert(
            $tables['jobs'],
            array(
                'public_id'     => $public_id,
                'item_id'       => absint( $item_id ),
                'trigger_type'  => sanitize_key( $trigger ),
                'state'         => 'queued',
                'requested_by'  => absint( $requested_by ),
                'requested_gmt' => self::now(),
                'payload'       => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            ),
            array( '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
        );

        if ( ! $inserted ) {
            return new WP_Error( 'ceia_queue_failed', 'No se pudo añadir la investigación a la cola.' );
        }

        self::log( 'job_queued', 'job', absint( $wpdb->insert_id ), array( 'item_id' => absint( $item_id ), 'trigger' => $trigger ) );
        return $public_id;
    }

    public static function queue_due_items( $limit, $requested_by = 0, $trigger = 'manual_due' ) {
        global $wpdb;
        $tables = self::tables();
        $limit  = max( 1, min( 100, absint( $limit ) ) );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.id FROM `{$tables['items']}` i
                WHERE i.active = 1
                  AND (i.next_review_gmt IS NULL OR i.next_review_gmt <= %s)
                  AND NOT EXISTS (
                      SELECT 1 FROM `{$tables['jobs']}` j
                      WHERE j.item_id = i.id AND j.state IN ('queued','running')
                  )
                ORDER BY CASE i.risk WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
                         i.next_review_gmt ASC
                LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::now(),
                $limit
            ),
            ARRAY_A
        );

        $queued = array();
        foreach ( $items as $item ) {
            $result = self::queue_job( absint( $item['id'] ), $requested_by, $trigger );
            if ( ! is_wp_error( $result ) ) {
                $queued[] = $result;
            }
        }
        return $queued;
    }

    public static function claim_next_job( $worker_id ) {
        global $wpdb;
        $tables    = self::tables();
        $worker_id = substr( sanitize_text_field( $worker_id ), 0, 191 );
        if ( '' === $worker_id ) {
            $worker_id = 'worker';
        }

        $stale_before = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$tables['jobs']}` SET state = 'queued', worker_id = '', claimed_gmt = NULL
                 WHERE state = 'running' AND claimed_gmt < %s AND attempt < 3", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $stale_before
            )
        );

        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $job = $wpdb->get_row(
                "SELECT * FROM `{$tables['jobs']}` WHERE state = 'queued' ORDER BY requested_gmt ASC, id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ARRAY_A
            );
            if ( ! $job ) {
                return null;
            }

            $claimed = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$tables['jobs']}`
                     SET state = 'running', claimed_gmt = %s, worker_id = %s, attempt = attempt + 1
                     WHERE id = %d AND state = 'queued'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    self::now(),
                    $worker_id,
                    absint( $job['id'] )
                )
            );

            if ( 1 === $claimed ) {
                $job = $wpdb->get_row(
                    $wpdb->prepare( "SELECT * FROM `{$tables['jobs']}` WHERE id = %d", absint( $job['id'] ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    ARRAY_A
                );
                self::log( 'job_claimed', 'job', absint( $job['id'] ), array( 'worker_id' => $worker_id ), 'worker:' . $worker_id );
                return self::build_job_context( $job );
            }
        }

        return null;
    }

    private static function build_job_context( $job ) {
        global $wpdb;
        $tables = self::tables();
        $item   = self::get_item( absint( $job['item_id'] ) );
        if ( ! $item ) {
            return new WP_Error( 'ceia_item_missing', 'El elemento asociado a la cola ya no existe.' );
        }

        $post_data = null;
        if ( ! empty( $item['post_id'] ) ) {
            $post = get_post( absint( $item['post_id'] ) );
            if ( $post && 'publish' === $post->post_status ) {
                $post_data = array(
                    'id'       => absint( $post->ID ),
                    'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
                    'content'  => (string) $post->post_content,
                    'excerpt'  => (string) $post->post_excerpt,
                    'modified' => (string) $post->post_modified_gmt,
                    'url'      => get_permalink( $post ),
                );
            }
        }

        $tramite = null;
        if ( 'tramite' === $item['object_type'] ) {
            $tramite = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM `{$tables['tramites']}` WHERE id = %d", absint( $item['object_id'] ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ARRAY_A
            );
            if ( $tramite ) {
                $tramite['fechas_adicionales'] = maybe_unserialize( $tramite['fechas_adicionales'] );
            }
        }

        return array(
            'job'     => array(
                'id'           => (string) $job['public_id'],
                'attempt'      => absint( $job['attempt'] ),
                'trigger_type' => (string) $job['trigger_type'],
                'payload'      => CEIA_Security::safe_json( $job['payload'] ),
            ),
            'item'    => $item,
            'post'    => $post_data,
            'tramite' => $tramite,
            'sources' => self::list_sources( absint( $item['id'] ) ),
            'policy'  => self::editorial_policy(),
        );
    }

    public static function editorial_policy() {
        return array(
            'language'                  => 'es',
            'public_content_only'       => true,
            'human_approval_required'   => true,
            'never_auto_publish'        => true,
            'timeless_wording'          => true,
            'do_not_say_currently_closed'=> true,
            'show_latest_official_deadline_as_reference' => true,
            'prefer_council_internal_links' => true,
            'council_contact_email'     => 'vice.estudiantes@uniovi.es',
            'system_notification_email' => 'web.cest@uniovi.es',
            'omit_council_postal_address'=> true,
            'no_span_tags'              => true,
            'responsive_required'       => true,
            'scoped_css_required'       => true,
            'critical_fact_types'       => array( 'deadline', 'amount', 'eligibility', 'legal_basis', 'procedure', 'competent_body', 'contact' ),
            'source_priority'           => array( 'official_gazette', 'official_registry', 'institutional', 'council', 'external_lead' ),
        );
    }

    public static function complete_job( $public_id, $result ) {
        global $wpdb;
        $tables = self::tables();
        $job    = self::get_job_by_public_id( $public_id );
        if ( ! $job || 'running' !== $job['state'] ) {
            return new WP_Error( 'ceia_job_not_running', 'La investigación no existe o ya no está en ejecución.' );
        }

        $context = self::build_job_context( $job );
        if ( is_wp_error( $context ) ) {
            return $context;
        }

        $result = is_array( $result ) ? $result : array();
        $html   = isset( $result['proposed_content'] ) ? (string) $result['proposed_content'] : '';
        $check  = CEIA_Security::validate_proposed_html( $html );
        if ( is_wp_error( $check ) ) {
            self::fail_job( $public_id, 'unsafe_proposal', $check->get_error_message() );
            return $check;
        }

        $validation_allowed = array( 'verified', 'verified_with_observations', 'human_review', 'conflict', 'insufficient_evidence' );
        $validation         = sanitize_key( $result['validation_status'] ?? 'human_review' );
        if ( ! in_array( $validation, $validation_allowed, true ) ) {
            $validation = 'human_review';
        }

        $risk = sanitize_key( $result['risk'] ?? $context['item']['risk'] );
        if ( ! in_array( $risk, array( 'low', 'medium', 'high', 'critical' ), true ) ) {
            $risk = (string) $context['item']['risk'];
        }

        $conflicts = is_array( $result['conflicts'] ?? null ) ? array_values( $result['conflicts'] ) : array();
        if ( $conflicts ) {
            $validation = 'conflict';
        }

        $index_patch = is_array( $result['index_patch'] ?? null ) ? $result['index_patch'] : array();
        $change_required = ! empty( $result['change_required'] ) && ( '' !== trim( $html ) || ! empty( $index_patch ) );
        $status          = $change_required ? 'review_required' : 'no_change';
        $post             = $context['post'];
        $current_content  = $post ? (string) $post['content'] : '';
        $current_title    = $post ? (string) $post['title'] : (string) $context['item']['title'];
        $current_fields   = $context['tramite'] ?: array();
        $current_hash     = CEIA_Security::hash( $current_content . '|' . wp_json_encode( $current_fields ) );
        $now              = self::now();

        $wpdb->query( 'START TRANSACTION' );
        try {
            $evidence_ids = array();
            $evidence     = is_array( $result['evidence'] ?? null ) ? array_slice( $result['evidence'], 0, 30 ) : array();
            foreach ( $evidence as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $url = CEIA_Security::validate_https_url( $entry['url'] ?? '' );
                if ( is_wp_error( $url ) ) {
                    continue;
                }
                $wpdb->insert(
                    $tables['evidence'],
                    array(
                        'job_id'        => absint( $job['id'] ),
                        'item_id'       => absint( $job['item_id'] ),
                        'source_id'     => absint( $entry['source_id'] ?? 0 ),
                        'local_id'      => substr( sanitize_key( $entry['local_id'] ?? '' ), 0, 20 ),
                        'url'           => $url,
                        'title'         => mb_substr( sanitize_text_field( $entry['title'] ?? '' ), 0, 500 ),
                        'source_type'   => sanitize_key( $entry['source_type'] ?? self::source_type_for_url( $url ) ),
                        'authority'     => max( 0, min( 100, absint( $entry['authority'] ?? self::authority_for_url( $url ) ) ) ),
                        'retrieved_gmt' => sanitize_text_field( $entry['retrieved_gmt'] ?? $now ),
                        'published_date'=> self::sanitize_date_or_null( $entry['published_date'] ?? null ),
                        'http_status'   => absint( $entry['http_status'] ?? 0 ),
                        'content_hash'  => sanitize_text_field( $entry['content_hash'] ?? '' ),
                        'excerpt'       => mb_substr( wp_strip_all_tags( (string) ( $entry['excerpt'] ?? '' ) ), 0, 12000 ),
                        'facts_json'    => wp_json_encode( $entry['facts'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                        'created_gmt'   => $now,
                    )
                );
                if ( $wpdb->insert_id ) {
                    $evidence_ids[] = absint( $wpdb->insert_id );
                }
            }

            $inserted = $wpdb->insert(
                $tables['proposals'],
                array(
                    'job_id'              => absint( $job['id'] ),
                    'item_id'             => absint( $job['item_id'] ),
                    'post_id'             => absint( $context['item']['post_id'] ),
                    'status'              => $status,
                    'risk'                => $risk,
                    'validation_status'   => $validation,
                    'proposed_title'      => sanitize_text_field( $result['proposed_title'] ?? '' ),
                    'summary'             => mb_substr( sanitize_textarea_field( $result['summary'] ?? '' ), 0, 20000 ),
                    'current_hash'        => $current_hash,
                    'current_title'       => $current_title,
                    'current_content'     => $current_content,
                    'current_fields_json' => wp_json_encode( $current_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                    'proposed_content'    => $html,
                    'proposed_fields_json'=> wp_json_encode( $index_patch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                    'changes_json'        => wp_json_encode( $result['changes'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                    'facts_json'          => wp_json_encode( $result['facts'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                    'conflicts_json'      => wp_json_encode( $conflicts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                    'citations_json'      => wp_json_encode( $result['citations'] ?? $evidence_ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                    'created_gmt'         => $now,
                    'updated_gmt'         => $now,
                )
            );
            if ( ! $inserted ) {
                throw new RuntimeException( 'No se pudo guardar la propuesta.' );
            }

            $proposal_id = absint( $wpdb->insert_id );
            $wpdb->update(
                $tables['jobs'],
                array(
                    'state'        => 'completed',
                    'finished_gmt' => $now,
                    'summary'      => mb_substr( sanitize_textarea_field( $result['summary'] ?? '' ), 0, 5000 ),
                    'error_code'   => '',
                    'error_message'=> '',
                ),
                array( 'id' => absint( $job['id'] ) )
            );

            $interval = max( 1, absint( $context['item']['review_interval_days'] ) );
            $next     = gmdate( 'Y-m-d H:i:s', time() + $interval * DAY_IN_SECONDS );
            $wpdb->update(
                $tables['items'],
                array(
                    'last_review_gmt' => $now,
                    'next_review_gmt' => $next,
                    'last_status'     => $status,
                    'updated_gmt'     => $now,
                ),
                array( 'id' => absint( $job['item_id'] ) )
            );

            $wpdb->query( 'COMMIT' );
            self::log( 'job_completed', 'job', absint( $job['id'] ), array( 'proposal_id' => $proposal_id, 'status' => $status ), 'worker:' . $job['worker_id'] );

            if ( 'review_required' === $status || 'conflict' === $validation ) {
                CEIA_Notifications::proposal_ready( $proposal_id );
            }

            return array( 'proposal_id' => $proposal_id, 'status' => $status, 'validation_status' => $validation );
        } catch ( Throwable $throwable ) {
            $wpdb->query( 'ROLLBACK' );
            self::fail_job( $public_id, 'save_failed', $throwable->getMessage() );
            return new WP_Error( 'ceia_save_failed', 'No se pudo guardar el resultado de la investigación.' );
        }
    }

    private static function sanitize_date_or_null( $date ) {
        $date = is_string( $date ) ? trim( $date ) : '';
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return $date;
        }
        return null;
    }

    public static function fail_job( $public_id, $code, $message ) {
        global $wpdb;
        $tables = self::tables();
        $job    = self::get_job_by_public_id( $public_id );
        if ( ! $job ) {
            return false;
        }

        $state = absint( $job['attempt'] ) < 3 && 'worker_temporary' === sanitize_key( $code ) ? 'queued' : 'failed';
        $wpdb->update(
            $tables['jobs'],
            array(
                'state'         => $state,
                'finished_gmt'  => 'failed' === $state ? self::now() : null,
                'worker_id'     => 'queued' === $state ? '' : $job['worker_id'],
                'claimed_gmt'   => 'queued' === $state ? null : $job['claimed_gmt'],
                'error_code'    => substr( sanitize_key( $code ), 0, 80 ),
                'error_message' => mb_substr( sanitize_textarea_field( $message ), 0, 5000 ),
            ),
            array( 'id' => absint( $job['id'] ) )
        );

        self::log( 'job_failed', 'job', absint( $job['id'] ), array( 'code' => $code, 'state' => $state ), 'worker:' . $job['worker_id'] );
        if ( 'failed' === $state ) {
            CEIA_Notifications::job_failed( absint( $job['id'] ), $message );
        }
        return true;
    }

    public static function get_job_by_public_id( $public_id ) {
        global $wpdb;
        $tables = self::tables();

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$tables['jobs']}` WHERE public_id = %s", sanitize_text_field( $public_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );
    }

    public static function get_proposal( $proposal_id ) {
        global $wpdb;
        $tables = self::tables();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.*, i.title AS item_title, i.url AS item_url, i.object_type, i.object_id, j.public_id AS job_public_id
                 FROM `{$tables['proposals']}` p
                 JOIN `{$tables['items']}` i ON i.id = p.item_id
                 JOIN `{$tables['jobs']}` j ON j.id = p.job_id
                 WHERE p.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                absint( $proposal_id )
            ),
            ARRAY_A
        );
    }

    public static function list_proposals( $limit = 100, $status = '' ) {
        global $wpdb;
        $tables = self::tables();
        $limit  = max( 1, min( 500, absint( $limit ) ) );

        if ( $status ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.*, i.title AS item_title, i.url AS item_url FROM `{$tables['proposals']}` p JOIN `{$tables['items']}` i ON i.id = p.item_id WHERE p.status = %s ORDER BY p.created_gmt DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    sanitize_key( $status ),
                    $limit
                ),
                ARRAY_A
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, i.title AS item_title, i.url AS item_url FROM `{$tables['proposals']}` p JOIN `{$tables['items']}` i ON i.id = p.item_id ORDER BY p.created_gmt DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $limit
            ),
            ARRAY_A
        );
    }

    public static function list_jobs( $limit = 50 ) {
        global $wpdb;
        $tables = self::tables();
        $limit  = max( 1, min( 500, absint( $limit ) ) );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT j.*, i.title AS item_title FROM `{$tables['jobs']}` j JOIN `{$tables['items']}` i ON i.id = j.item_id ORDER BY j.requested_gmt DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $limit
            ),
            ARRAY_A
        );
    }

    public static function list_evidence_for_job( $job_id ) {
        global $wpdb;
        $tables = self::tables();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$tables['evidence']}` WHERE job_id = %d ORDER BY authority DESC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                absint( $job_id )
            ),
            ARRAY_A
        );
    }

    public static function duplicate_item_urls() {
        global $wpdb;
        $tables = self::tables();

        return $wpdb->get_results(
            "SELECT url, COUNT(*) AS total, GROUP_CONCAT(title ORDER BY title SEPARATOR ' || ') AS titles
             FROM `{$tables['items']}`
             WHERE active = 1 AND url <> ''
             GROUP BY url HAVING COUNT(*) > 1
             ORDER BY total DESC, url ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );
    }

    public static function recent_logs( $limit = 100 ) {
        global $wpdb;
        $tables = self::tables();
        $limit  = max( 1, min( 500, absint( $limit ) ) );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$tables['logs']}` ORDER BY created_gmt DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $limit
            ),
            ARRAY_A
        );
    }

    public static function dashboard_counts() {
        global $wpdb;
        $tables = self::tables();

        return array(
            'items'      => absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['items']}` WHERE active = 1" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'due'        => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$tables['items']}` WHERE active = 1 AND (next_review_gmt IS NULL OR next_review_gmt <= %s)", self::now() ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'queued'     => absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['jobs']}` WHERE state = 'queued'" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'running'    => absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['jobs']}` WHERE state = 'running'" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'review'     => absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['proposals']}` WHERE status = 'review_required'" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'conflicts'  => absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['proposals']}` WHERE validation_status = 'conflict' AND status IN ('review_required','approved')" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'published'  => absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['proposals']}` WHERE status = 'published'" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'failed'     => absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['jobs']}` WHERE state = 'failed'" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    public static function update_proposal( $proposal_id, $data, $formats = null ) {
        global $wpdb;
        $tables = self::tables();
        $data['updated_gmt'] = self::now();
        return false !== $wpdb->update( $tables['proposals'], $data, array( 'id' => absint( $proposal_id ) ), $formats, array( '%d' ) );
    }

    public static function update_item_after_publish( $item_id, $status, $content_hash ) {
        global $wpdb;
        $tables = self::tables();
        return false !== $wpdb->update(
            $tables['items'],
            array(
                'last_status'  => sanitize_key( $status ),
                'content_hash' => sanitize_text_field( $content_hash ),
                'updated_gmt'  => self::now(),
            ),
            array( 'id' => absint( $item_id ) )
        );
    }

    public static function record_heartbeat( $data ) {
        $heartbeat = array(
            'received_gmt' => self::now(),
            'worker_id'    => sanitize_text_field( $data['worker_id'] ?? '' ),
            'version'      => sanitize_text_field( $data['version'] ?? '' ),
            'provider'     => sanitize_text_field( $data['provider'] ?? '' ),
            'message'      => sanitize_text_field( $data['message'] ?? '' ),
        );
        update_option( 'ceia_worker_heartbeat', $heartbeat, false );
        return $heartbeat;
    }

    public static function get_heartbeat() {
        $value = get_option( 'ceia_worker_heartbeat', array() );
        return is_array( $value ) ? $value : array();
    }

    public static function log( $action, $object_type, $object_id = 0, $detail = array(), $actor = '' ) {
        global $wpdb;
        $tables  = self::tables();
        $user_id = get_current_user_id();
        if ( '' === $actor ) {
            $actor = $user_id ? 'user:' . $user_id : 'system';
        }

        $wpdb->insert(
            $tables['logs'],
            array(
                'action'      => substr( sanitize_key( $action ), 0, 80 ),
                'object_type' => substr( sanitize_key( $object_type ), 0, 30 ),
                'object_id'   => absint( $object_id ),
                'user_id'     => absint( $user_id ),
                'actor'       => substr( sanitize_text_field( $actor ), 0, 191 ),
                'detail'      => wp_json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'created_gmt' => self::now(),
            )
        );
    }
}
