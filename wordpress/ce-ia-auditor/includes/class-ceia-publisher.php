<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CEIA_Publisher {
    public static function approve( $proposal_id, $note = '' ) {
        $proposal = CEIA_Repository::get_proposal( $proposal_id );
        if ( ! $proposal || 'review_required' !== $proposal['status'] ) {
            return new WP_Error( 'ceia_invalid_proposal_state', 'La propuesta no está pendiente de revisión.' );
        }

        if ( in_array( $proposal['validation_status'], array( 'conflict', 'insufficient_evidence' ), true ) ) {
            return new WP_Error( 'ceia_blocked_proposal', 'No puede aprobarse: existen conflictos o pruebas insuficientes. Debe repetirse la investigación o corregirse la fuente.' );
        }

        $quality = CEIA_Quality::get_report( $proposal_id );
        if ( ! $quality || 'pass' !== ( $quality['gate'] ?? '' ) ) {
            return new WP_Error( 'ceia_quality_block', 'No puede aprobarse: la propuesta no ha superado la puerta de calidad independiente.' );
        }

        if ( in_array( $proposal['risk'], array( 'high', 'critical' ), true ) && 'verified' !== $proposal['validation_status'] ) {
            return new WP_Error( 'ceia_high_risk_block', 'Los trámites de riesgo alto o crítico solo pueden aprobarse con validación verified sin observaciones.' );
        }

        $note = CEIA_Security::sanitize_review_note( $note );
        if ( '' === trim( $note ) ) {
            return new WP_Error( 'ceia_review_note_required', 'Indica qué fuentes, cambios y capturas has comprobado antes de aprobar.' );
        }

        $updated = CEIA_Repository::update_proposal(
            $proposal_id,
            array(
                'status'       => 'approved',
                'reviewed_by'  => get_current_user_id(),
                'reviewed_gmt' => CEIA_Repository::now(),
                'review_note'  => $note,
            )
        );

        if ( $updated ) {
            CEIA_Repository::log( 'proposal_approved', 'proposal', $proposal_id );
            return true;
        }

        return new WP_Error( 'ceia_approval_failed', 'No se pudo aprobar la propuesta.' );
    }

    public static function reject( $proposal_id, $note = '' ) {
        $proposal = CEIA_Repository::get_proposal( $proposal_id );
        if ( ! $proposal || ! in_array( $proposal['status'], array( 'review_required', 'approved' ), true ) ) {
            return new WP_Error( 'ceia_invalid_proposal_state', 'La propuesta ya no puede rechazarse.' );
        }

        $note = CEIA_Security::sanitize_review_note( $note );
        if ( '' === trim( $note ) ) {
            return new WP_Error( 'ceia_rejection_note_required', 'Indica por qué rechazas la propuesta para conservar una trazabilidad útil.' );
        }

        $updated = CEIA_Repository::update_proposal(
            $proposal_id,
            array(
                'status'       => 'rejected',
                'reviewed_by'  => get_current_user_id(),
                'reviewed_gmt' => CEIA_Repository::now(),
                'review_note'  => $note,
            )
        );

        if ( $updated ) {
            CEIA_Repository::log( 'proposal_rejected', 'proposal', $proposal_id, array( 'note' => $note ) );
            return true;
        }

        return new WP_Error( 'ceia_rejection_failed', 'No se pudo rechazar la propuesta.' );
    }

    public static function publish( $proposal_id ) {
        $proposal = CEIA_Repository::get_proposal( $proposal_id );
        if ( ! $proposal || 'approved' !== $proposal['status'] ) {
            return new WP_Error( 'ceia_not_approved', 'La propuesta debe aprobarse antes de publicarla.' );
        }

        if ( ! in_array( $proposal['validation_status'], array( 'verified', 'verified_with_observations', 'human_review' ), true ) ) {
            return new WP_Error( 'ceia_validation_block', 'El estado de validación impide publicar esta propuesta.' );
        }

        $quality = CEIA_Quality::pre_publish( $proposal );
        if ( is_wp_error( $quality ) ) {
            return $quality;
        }

        $current = self::current_snapshot( $proposal );
        if ( is_wp_error( $current ) ) {
            return $current;
        }
        if ( ! hash_equals( (string) $proposal['current_hash'], (string) $current['hash'] ) ) {
            return new WP_Error( 'ceia_stale_proposal', 'La página o el trámite cambió después de generar la propuesta. Para no sobrescribir trabajo reciente, ejecuta una investigación nueva.' );
        }

        $html = (string) $proposal['proposed_content'];
        $safe = CEIA_Security::validate_proposed_html( $html );
        if ( is_wp_error( $safe ) ) {
            return $safe;
        }

        if ( '' !== trim( $html ) && ! current_user_can( 'unfiltered_html' ) ) {
            return new WP_Error( 'ceia_unfiltered_html_required', 'Tu usuario no tiene permiso para publicar el CSS incluido en esta página.' );
        }

        $post_changed = false;
        if ( absint( $proposal['post_id'] ) && ( '' !== trim( $html ) || '' !== trim( $proposal['proposed_title'] ) ) ) {
            $post_data = array( 'ID' => absint( $proposal['post_id'] ) );
            if ( '' !== trim( $html ) ) {
                $post_data['post_content'] = $html;
            }
            if ( '' !== trim( $proposal['proposed_title'] ) ) {
                $post_data['post_title'] = sanitize_text_field( $proposal['proposed_title'] );
            }

            $post_result = wp_update_post( wp_slash( $post_data ), true );
            if ( is_wp_error( $post_result ) ) {
                return $post_result;
            }
            $post_changed = true;
        }

        $patch = CEIA_Security::safe_json( $proposal['proposed_fields_json'] );
        $index_result = self::apply_index_patch( $proposal, $patch );
        if ( is_wp_error( $index_result ) ) {
            self::restore_changed_content( $proposal, $current, $post_changed );
            return $index_result;
        }

        $public_check = CEIA_Quality::verify_after_publish( $proposal, $patch );
        if ( is_wp_error( $public_check ) ) {
            self::restore_changed_content( $proposal, $current, $post_changed );
            return $public_check;
        }

        $updated = CEIA_Repository::update_proposal(
            $proposal_id,
            array(
                'status'        => 'published',
                'published_by'  => get_current_user_id(),
                'published_gmt' => CEIA_Repository::now(),
            )
        );

        if ( ! $updated ) {
            self::restore_changed_content( $proposal, $current, $post_changed );
            return new WP_Error( 'ceia_publish_state_failed', 'La verificación terminó, pero no pudo registrarse el estado. Los cambios se han restaurado.' );
        }

        $after = self::current_snapshot( $proposal );
        CEIA_Repository::update_item_after_publish( absint( $proposal['item_id'] ), 'published', is_wp_error( $after ) ? '' : $after['hash'] );
        CEIA_Repository::log(
            'proposal_published',
            'proposal',
            $proposal_id,
            array(
                'post_changed'       => $post_changed,
                'index_changed'      => ! empty( $patch ),
                'public_verification'=> 'passed',
            )
        );

        return true;
    }

    private static function restore_changed_content( $proposal, $current, $post_changed ) {
        if ( $post_changed ) {
            wp_update_post(
                wp_slash(
                    array(
                        'ID'           => absint( $proposal['post_id'] ),
                        'post_title'   => (string) $proposal['current_title'],
                        'post_content' => (string) $current['content'],
                    )
                )
            );
        }
        self::restore_index_fields( $proposal, $current['fields'] );
    }

    public static function rollback( $proposal_id ) {
        $proposal = CEIA_Repository::get_proposal( $proposal_id );
        if ( ! $proposal || 'published' !== $proposal['status'] ) {
            return new WP_Error( 'ceia_not_published', 'Solo pueden revertirse propuestas publicadas.' );
        }

        $item    = CEIA_Repository::get_item( absint( $proposal['item_id'] ) );
        $current = self::current_snapshot( $proposal );
        if ( is_wp_error( $current ) ) {
            return $current;
        }
        if ( ! $item || ! hash_equals( (string) $item['content_hash'], (string) $current['hash'] ) ) {
            return new WP_Error( 'ceia_rollback_stale', 'La página volvió a cambiar después de esta publicación. Para no borrar esos cambios, la reversión automática queda bloqueada.' );
        }

        if ( absint( $proposal['post_id'] ) ) {
            $result = wp_update_post(
                wp_slash(
                    array(
                        'ID'           => absint( $proposal['post_id'] ),
                        'post_title'   => (string) $proposal['current_title'],
                        'post_content' => (string) $proposal['current_content'],
                    )
                ),
                true
            );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        $original = CEIA_Security::safe_json( $proposal['current_fields_json'] );
        $result   = self::restore_index_fields( $proposal, $original );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        CEIA_Repository::update_proposal(
            $proposal_id,
            array(
                'status'      => 'rolled_back',
                'review_note' => trim( (string) $proposal['review_note'] . "\nRevertida el " . CEIA_Repository::now() . ' UTC.' ),
            )
        );
        $restored = self::current_snapshot( $proposal );
        CEIA_Repository::update_item_after_publish(
            absint( $proposal['item_id'] ),
            'rolled_back',
            is_wp_error( $restored ) ? '' : $restored['hash']
        );
        CEIA_Repository::log( 'proposal_rolled_back', 'proposal', $proposal_id );
        return true;
    }

    private static function current_snapshot( $proposal ) {
        global $wpdb;
        $tables  = CEIA_Repository::tables();
        $content = '';

        if ( absint( $proposal['post_id'] ) ) {
            $post = get_post( absint( $proposal['post_id'] ) );
            if ( ! $post ) {
                return new WP_Error( 'ceia_post_missing', 'La página asociada ya no existe.' );
            }
            $content = (string) $post->post_content;
        }

        $fields = array();
        if ( 'tramite' === $proposal['object_type'] ) {
            $fields = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM `{$tables['tramites']}` WHERE id = %d", absint( $proposal['object_id'] ) ),
                ARRAY_A
            );
            if ( ! $fields ) {
                return new WP_Error( 'ceia_tramite_missing', 'El registro del trámite ya no existe.' );
            }
            $fields['fechas_adicionales'] = maybe_unserialize( $fields['fechas_adicionales'] );
        }

        return array(
            'content' => $content,
            'fields'  => $fields,
            'hash'    => CEIA_Security::hash( $content . '|' . wp_json_encode( $fields ) ),
        );
    }

    private static function apply_index_patch( $proposal, $patch ) {
        global $wpdb;
        $tables = CEIA_Repository::tables();

        if ( empty( $patch ) || 'tramite' !== $proposal['object_type'] ) {
            return true;
        }

        $data    = array();
        $formats = array();
        if ( array_key_exists( 'nombre', $patch ) ) {
            $data['nombre'] = sanitize_text_field( $patch['nombre'] );
            $formats[]      = '%s';
        }
        if ( array_key_exists( 'tipo', $patch ) ) {
            $types = is_array( $patch['tipo'] ) ? $patch['tipo'] : explode( ',', (string) $patch['tipo'] );
            $types = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $types ) ) ) );
            if ( empty( $types ) ) {
                return new WP_Error( 'ceia_invalid_type', 'La propuesta dejaría el trámite sin categoría.' );
            }
            $data['tipo'] = implode( ', ', $types );
            $formats[]    = '%s';
        }
        if ( array_key_exists( 'url', $patch ) ) {
            $url = CEIA_Security::validate_https_url( $patch['url'] );
            if ( is_wp_error( $url ) ) {
                return $url;
            }
            if ( 0 !== strpos( $url, CEIA_Quality::MANAGED_PREFIX ) ) {
                return new WP_Error( 'ceia_index_scope', 'El índice solo puede apuntar a una página de la web del Consejo.' );
            }
            $data['url'] = $url;
            $formats[]   = '%s';
        }
        if ( array_key_exists( 'abierto_permanente', $patch ) ) {
            $data['abierto_permanente'] = empty( $patch['abierto_permanente'] ) ? 0 : 1;
            $formats[]                  = '%d';
        }
        if ( array_key_exists( 'fechas_adicionales', $patch ) ) {
            $ranges = self::validate_date_ranges( $patch['fechas_adicionales'] );
            if ( is_wp_error( $ranges ) ) {
                return $ranges;
            }
            $data['fechas_adicionales'] = maybe_serialize( $ranges );
            $formats[]                  = '%s';
        }

        if ( empty( $data ) ) {
            return true;
        }

        $result = $wpdb->update(
            $tables['tramites'],
            $data,
            array( 'id' => absint( $proposal['object_id'] ) ),
            $formats,
            array( '%d' )
        );
        return false === $result ? new WP_Error( 'ceia_index_update_failed', 'No se pudo actualizar el índice de trámites.' ) : true;
    }

    private static function restore_index_fields( $proposal, $fields ) {
        global $wpdb;
        $tables = CEIA_Repository::tables();
        if ( empty( $fields ) || 'tramite' !== $proposal['object_type'] ) {
            return true;
        }

        $data = array(
            'nombre'             => sanitize_text_field( $fields['nombre'] ?? '' ),
            'tipo'               => sanitize_text_field( $fields['tipo'] ?? '' ),
            'url'                => esc_url_raw( $fields['url'] ?? '' ),
            'abierto_permanente' => empty( $fields['abierto_permanente'] ) ? 0 : 1,
            'fechas_adicionales' => maybe_serialize( $fields['fechas_adicionales'] ?? array() ),
        );
        $result = $wpdb->update(
            $tables['tramites'],
            $data,
            array( 'id' => absint( $proposal['object_id'] ) ),
            array( '%s', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );
        return false === $result ? new WP_Error( 'ceia_restore_failed', 'No se pudo restaurar el índice del trámite.' ) : true;
    }

    private static function validate_date_ranges( $ranges ) {
        if ( ! is_array( $ranges ) ) {
            return new WP_Error( 'ceia_invalid_dates', 'Los plazos propuestos no tienen un formato válido.' );
        }

        $valid = array();
        foreach ( array_slice( $ranges, 0, 12 ) as $range ) {
            if ( ! is_array( $range ) ) {
                return new WP_Error( 'ceia_invalid_dates', 'Uno de los plazos propuestos no es válido.' );
            }
            $start = sanitize_text_field( $range['fecha_inicio'] ?? '' );
            $end   = sanitize_text_field( $range['fecha_fin'] ?? '' );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) || $start > $end ) {
                return new WP_Error( 'ceia_invalid_dates', 'Una fecha propuesta es inválida o está invertida.' );
            }
            $valid[] = array( 'fecha_inicio' => $start, 'fecha_fin' => $end );
        }

        usort(
            $valid,
            static function ( $a, $b ) {
                return strcmp( $a['fecha_inicio'], $b['fecha_inicio'] );
            }
        );
        return $valid;
    }
}
