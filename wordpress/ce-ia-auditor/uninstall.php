<?php
/**
 * CE-IA conserva deliberadamente tablas, evidencias, versiones y registro de auditoría.
 * La eliminación de un plugin no debe borrar trabajo editorial o pruebas sin una acción
 * separada, explícita y respaldada por una copia de seguridad.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Sin operaciones destructivas.

