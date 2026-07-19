<?php
/**
 * Plugin Name: CE-IA Auditor de Trámites
 * Plugin URI: https://www.unioviedo.es/cestudiantes/
 * Description: Investigación asistida, evidencias, propuestas y aprobación humana para mantener actualizadas las páginas de trámites del Consejo de Estudiantes.
 * Version: 0.10.1
 * Author: Consejo de Estudiantes de la Universidad de Oviedo
 * Text Domain: ce-ia-auditor
 * Requires at least: 6.5
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CEIA_VERSION', '0.10.1' );
define( 'CEIA_DB_VERSION', '0.10.1' );
define( 'CEIA_FILE', __FILE__ );
define( 'CEIA_DIR', plugin_dir_path( __FILE__ ) );
define( 'CEIA_URL', plugin_dir_url( __FILE__ ) );

require_once CEIA_DIR . 'includes/class-ceia-security.php';
require_once CEIA_DIR . 'includes/class-ceia-repository.php';
require_once CEIA_DIR . 'includes/class-ceia-activator.php';
require_once CEIA_DIR . 'includes/class-ceia-notifications.php';
require_once CEIA_DIR . 'includes/class-ceia-github.php';
require_once CEIA_DIR . 'includes/class-ceia-publisher.php';
require_once CEIA_DIR . 'includes/class-ceia-rest-controller.php';
require_once CEIA_DIR . 'includes/class-ceia-admin.php';
require_once CEIA_DIR . 'includes/class-ceia-plugin.php';

register_activation_hook( __FILE__, array( 'CEIA_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CEIA_Activator', 'deactivate' ) );

add_action(
    'plugins_loaded',
    static function () {
        CEIA_Plugin::instance()->boot();
    }
);

