<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CEIA_Notifications {
    public static function test() {
        return self::send(
            '[CE-IA] Prueba de avisos',
            "Este mensaje confirma que WordPress puede enviar los avisos de CE-IA.\n\nNo se ha modificado ninguna página ni trámite."
        );
    }

    public static function proposal_ready( $proposal_id ) {
        $proposal = CEIA_Repository::get_proposal( $proposal_id );
        if ( ! $proposal ) {
            return false;
        }

        $subject = '[CE-IA] Propuesta pendiente: ' . $proposal['item_title'];
        $url     = admin_url( 'admin.php?page=ce-ia-proposals&proposal_id=' . absint( $proposal_id ) );
        $body    = "CE-IA ha terminado una investigación y requiere revisión humana.\n\n";
        $body   .= 'Trámite: ' . $proposal['item_title'] . "\n";
        $body   .= 'Validación: ' . $proposal['validation_status'] . "\n";
        $body   .= 'Riesgo: ' . $proposal['risk'] . "\n";
        $body   .= 'Resumen: ' . wp_strip_all_tags( (string) $proposal['summary'] ) . "\n\n";
        $body   .= 'Revisar en WordPress: ' . $url . "\n";
        $body   .= "La propuesta no se publicará sin aprobación humana expresa.";

        return self::send( $subject, $body );
    }

    public static function job_failed( $job_id, $message ) {
        $subject = '[CE-IA] Investigación fallida';
        $body    = "Una investigación de CE-IA ha fallado y necesita revisión.\n\n";
        $body   .= 'Trabajo interno: ' . absint( $job_id ) . "\n";
        $body   .= 'Motivo: ' . wp_strip_all_tags( (string) $message ) . "\n\n";
        $body   .= 'Abrir el panel: ' . admin_url( 'admin.php?page=ce-ia' );

        return self::send( $subject, $body );
    }

    private static function send( $subject, $body ) {
        $settings = CEIA_Repository::get_settings();
        $email    = sanitize_email( $settings['notification_email'] ?? '' );
        if ( ! is_email( $email ) ) {
            return false;
        }

        return wp_mail( $email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
    }
}
