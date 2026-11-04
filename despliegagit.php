<?php
/**
 * Plugin Name:       DespliegaGit
 * Plugin URI:        https://github.com/despliegagit/despliegagit
 * Description:       DespliegaGit conecta tu sitio de WordPress directamente con GitHub. Pega el enlace de cualquier repositorio público o privado y clona, importa y publica tu web o componentes en segundos, sin complicaciones técnicas.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Oscar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       despliegagit
 * Domain Path:       /languages
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

define( 'DESPLIEGAGIT_VERSION', '1.0.0' );
define( 'DESPLIEGAGIT_FILE', __FILE__ );
define( 'DESPLIEGAGIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'DESPLIEGAGIT_URL', plugin_dir_url( __FILE__ ) );

require_once DESPLIEGAGIT_DIR . 'includes/class-dg-crypto.php';
require_once DESPLIEGAGIT_DIR . 'includes/class-dg-settings.php';
require_once DESPLIEGAGIT_DIR . 'includes/class-dg-projects.php';
require_once DESPLIEGAGIT_DIR . 'includes/class-dg-github.php';
require_once DESPLIEGAGIT_DIR . 'includes/class-dg-deployer.php';
require_once DESPLIEGAGIT_DIR . 'includes/class-dg-static-server.php';
require_once DESPLIEGAGIT_DIR . 'includes/class-dg-webhook.php';

if ( is_admin() ) {
	require_once DESPLIEGAGIT_DIR . 'includes/class-dg-admin.php';
}

/**
 * Arranque del plugin.
 */
function despliegagit_bootstrap() {
	load_plugin_textdomain( 'despliegagit', false, dirname( plugin_basename( DESPLIEGAGIT_FILE ) ) . '/languages' );

	DG_Static_Server::init();
	DG_Webhook::init();

	if ( is_admin() ) {
		DG_Admin::init();
	}
}
add_action( 'plugins_loaded', 'despliegagit_bootstrap' );

/**
 * Activación: crea carpetas de trabajo y registra las rutas de los sitios estáticos.
 */
function despliegagit_activate() {
	DG_Deployer::ensure_dirs();
	DG_Static_Server::register_rules();
	flush_rewrite_rules( false );
}
register_activation_hook( __FILE__, 'despliegagit_activate' );

/**
 * Desactivación: limpia tareas programadas y reglas de reescritura.
 */
function despliegagit_deactivate() {
	wp_clear_scheduled_hook( DG_Webhook::CRON_HOOK );
	flush_rewrite_rules( false );
}
register_deactivation_hook( __FILE__, 'despliegagit_deactivate' );

