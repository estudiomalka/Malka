<?php
/**
 * Plugin Name:          Malka CRM
 * Plugin URI:           https://estudiomalka.cl
 * Description:          CRM de clientes para estudios creativos: cotizaciones y abonos, proyectos con Carta Gantt por etapas, tareas del cliente con plazos, reprogramación automática por atrasos, aprobaciones con rondas y facturas HTML seguras.
 * Version:              5.3.1
 * Author:               Estudio Malka
 * Author URI:           https://estudiomalka.cl
 * License:              GPL-2.0-or-later
 * Text Domain:          malka-crm
 * Domain Path:          /languages
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * WC requires at least: 7.0
 * WC tested up to:      9.9
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/*
 * Si ya hay otra copia de Malka CRM cargada, ésta se retira en silencio.
 *
 * Sin esto, dos copias activas a la vez hacen que PHP reviente al declarar la
 * clase por segunda vez, y WordPress sólo alcanza a decir «el plugin provocó
 * un error fatal», sin decir cuál ni por qué. Pasa cuando el ZIP no trae una
 * carpeta propia adentro: WordPress le pone de nombre el del archivo, así que
 * cada versión se instala como si fuera un plugin distinto en vez de
 * reemplazar a la anterior.
 *
 * Se pregunta por la constante y no por la clase: PHP adelanta la declaración
 * de una clase al compilar el archivo, así que class_exists() ya daría
 * verdadero acá y el plugin se retiraría a sí mismo. Una constante se define
 * cuando le toca.
 */
if ( defined( 'MALKA_CRM_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-warning"><p><b>';
			esc_html_e( 'Hay más de una copia de Malka CRM instalada.', 'malka-crm' );
			echo '</b> ';
			esc_html_e( 'Esta copia no se cargó, para no romper el sitio. Anda a Plugins, deja activa una sola y borra las demás: tus datos no se tocan.', 'malka-crm' );
			echo '</p></div>';
		}
	);

	return;
}

define( 'MALKA_CRM_VERSION', '5.3.1' );
define( 'MALKA_CRM_FILE', __FILE__ );
define( 'MALKA_CRM_PATH', plugin_dir_path( __FILE__ ) );
define( 'MALKA_CRM_URL', plugin_dir_url( __FILE__ ) );
define( 'MALKA_CRM_BASENAME', plugin_basename( __FILE__ ) );

/*
 * El contenedor va aparte a propósito: así una segunda copia del plugin se
 * retira arriba sin llegar a declarar nada. Ver el comentario de ese archivo.
 */
require_once MALKA_CRM_PATH . 'includes/class-malka-crm-core.php';

malka_crm();
