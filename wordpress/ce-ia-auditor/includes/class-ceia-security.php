<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CEIA_Security {
    const SECRET_PREFIX = 'ceia:v1:';

    public static function hash( $value ) {
        return hash( 'sha256', (string) $value );
    }

    public static function encrypt_secret( $plaintext ) {
        $plaintext = trim( (string) $plaintext );
        if ( '' === $plaintext ) {
            return '';
        }

        if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
            return new WP_Error( 'ceia_sodium_missing', 'El servidor no dispone de Sodium; el secreto no se ha guardado.' );
        }

        try {
            $key        = self::encryption_key();
            $nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );

            return self::SECRET_PREFIX . base64_encode( $nonce . $ciphertext );
        } catch ( Exception $exception ) {
            return new WP_Error( 'ceia_secret_encrypt_failed', 'No se pudo cifrar el secreto.' );
        }
    }

    public static function decrypt_secret( $stored ) {
        $stored = (string) $stored;
        if ( '' === $stored ) {
            return '';
        }

        if ( 0 !== strpos( $stored, self::SECRET_PREFIX ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
            return '';
        }

        $decoded = base64_decode( substr( $stored, strlen( self::SECRET_PREFIX ) ), true );
        if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
            return '';
        }

        $nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::encryption_key() );

        return false === $plaintext ? '' : $plaintext;
    }

    private static function encryption_key() {
        $material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );

        if ( function_exists( 'hash_hkdf' ) ) {
            return hash_hkdf( 'sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'ceia-secrets-v1' );
        }

        return substr( hash( 'sha256', $material, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
    }

    public static function mask_secret( $stored ) {
        return '' === self::decrypt_secret( $stored ) ? 'No configurada' : 'Configurada';
    }

    public static function validate_https_url( $url ) {
        $url = esc_url_raw( trim( (string) $url ), array( 'https' ) );
        if ( '' === $url || ! wp_http_validate_url( $url ) ) {
            return new WP_Error( 'ceia_invalid_url', 'La URL debe ser HTTPS y válida.' );
        }

        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( '' === $host || 'localhost' === $host || preg_match( '/(^|\.)local$/', $host ) ) {
            return new WP_Error( 'ceia_invalid_host', 'No se permiten destinos locales.' );
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $public = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
            if ( false === $public ) {
                return new WP_Error( 'ceia_private_host', 'No se permiten direcciones IP privadas o reservadas.' );
            }
        }

        return $url;
    }

    public static function validate_proposed_html( $html ) {
        $html = (string) $html;
        if ( '' === trim( $html ) ) {
            return true;
        }

        if ( strlen( $html ) > 2000000 ) {
            return new WP_Error( 'ceia_html_too_large', 'El HTML propuesto supera el límite de 2 MB.' );
        }

        if ( preg_match( '/<\/?(script|iframe|object|embed|form|input|button|textarea|select|meta|link|base|video|audio|source|track|foreignobject|animate|set|image|use)\b/i', $html, $tag_match ) ) {
            $tag = strtolower( (string) $tag_match[1] );
            return new WP_Error(
                'ceia_unsafe_html',
                sprintf(
                    'El HTML contiene la etiqueta no permitida <%s>. Los botones visuales deben ser enlaces <a> con CSS; no se admiten formularios ni controles interactivos.',
                    $tag
                )
            );
        }

        $forbidden = array(
            '/<!doctype/i'                       => 'No se admite DOCTYPE.',
            '/<\/?(?:html|head|body)\b/i'         => 'Solo debe proponerse el bloque de contenido, no un documento HTML completo.',
            '/<\/?span\b/i'                      => 'La plantilla del Consejo no utiliza etiquetas span.',
            '/\son[a-z]+\s*=/i'                  => 'No se permiten manejadores JavaScript en atributos.',
            '/(?:javascript|vbscript|data)\s*:/i' => 'No se permiten URL ejecutables o incrustadas.',
            '/@import\b/i'                       => 'No se permiten importaciones CSS.',
            '/expression\s*\(/i'                => 'No se permiten expresiones CSS.',
            '/url\s*\(/i'                       => 'No se permiten recursos cargados desde CSS.',
            '/position\s*:\s*fixed\b/i'         => 'No se permiten elementos fijos sobre la interfaz.',
            '/xlink:href/i'                       => 'No se permiten referencias SVG externas.',
        );

        foreach ( $forbidden as $pattern => $message ) {
            if ( preg_match( $pattern, $html ) ) {
                return new WP_Error( 'ceia_unsafe_html', $message );
            }
        }

        if ( ! preg_match( '/<section\b[^>]*\bid=["\']([a-zA-Z][a-zA-Z0-9_-]*)["\']/i', $html, $root_match ) ) {
            return new WP_Error( 'ceia_unscoped_html', 'La propuesta debe incluir una sección raíz con un identificador único.' );
        }

        $root_id = $root_match[1];
        if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $html, $style_matches ) ) {
            foreach ( $style_matches[1] as $css ) {
                if ( preg_match( '/(^|[},])\s*(?:html|body|:root)\b/im', $css ) ) {
                    return new WP_Error( 'ceia_global_css', 'El CSS no puede modificar selectores globales.' );
                }
                if ( preg_match_all( '/([^{}]+)\{/s', $css, $selector_matches ) ) {
                    foreach ( $selector_matches[1] as $selector ) {
                        $selector = trim( $selector );
                        if ( '' === $selector || 0 === strpos( $selector, '@' ) ) {
                            continue;
                        }
                        if ( false === strpos( $selector, '#' . $root_id ) ) {
                            return new WP_Error( 'ceia_unscoped_css', 'El CSS contiene un selector fuera de la sección raíz.' );
                        }
                    }
                }
            }
        }

        if ( preg_match_all( '/\s(?:href|src)\s*=\s*["\']([^"\']+)["\']/i', $html, $url_matches ) ) {
            foreach ( $url_matches[1] as $url ) {
                if ( 0 === strpos( $url, '#' ) || 0 === strpos( $url, '/' ) || 0 === strpos( $url, 'mailto:' ) || 0 === strpos( $url, 'tel:' ) ) {
                    continue;
                }
                if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
                    return new WP_Error( 'ceia_insecure_link', 'Todos los enlaces web de la propuesta deben utilizar HTTPS.' );
                }
            }
        }

        return true;
    }

    public static function safe_json( $value, $default = array() ) {
        if ( is_array( $value ) ) {
            return $value;
        }

        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : $default;
    }

    public static function sanitize_review_note( $note ) {
        return mb_substr( sanitize_textarea_field( (string) $note ), 0, 4000 );
    }
}
