<?php
/**
 * El registro de los correos que salen.
 *
 * Todo lo que el sitio manda pasa por wp_mail: cobros, avisos, la copia de
 * la firma, los recordatorios, los comunicados. Acá se anota cada envío
 * (a quién, qué asunto, el cuerpo y si salió) para poder verlo después en
 * la ficha de cada cliente, sin tener que buscar en el correo.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registro de correos enviados.
 */
class Malka_CRM_Correos {

	const TABLE      = 'malka_crm_correos';
	const DB_VERSION = '1.0';
	const OPT_DB     = 'malka_crm_correos_db';

	/**
	 * Engancha el registro a wp_mail.
	 */
	public static function init() {
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'al_salir' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'al_fallar' ) );
		add_action( 'wp_ajax_malka_crm_msg_plantilla', array( __CLASS__, 'ajax_plantilla' ) );
	}

	/**
	 * Duplicar, eliminar (o restablecer) y renombrar plantillas de correo.
	 */
	public static function ajax_plantilla() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}
		check_ajax_referer( 'malka_crm_msg_plantilla', 'nonce' );
		$op    = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$clave = isset( $_POST['clave'] ) ? sanitize_key( wp_unslash( $_POST['clave'] ) ) : '';
		$url   = Malka_CRM_Dashboard::view_url( 'mensajes' );
		if ( 'duplicar' === $op ) {
			$n = Malka_CRM_Messages::duplicar( $clave );
			if ( ! $n ) {
				wp_send_json_error( array( 'message' => __( 'No encontré esa plantilla.', 'malka-crm' ) ), 404 );
			}
			wp_send_json_success( array( 'url' => add_query_arg( 'msg', $n, $url ) ) );
		}
		if ( 'eliminar' === $op ) {
			$propia = array_key_exists( $clave, Malka_CRM_Messages::propias() );
			Malka_CRM_Messages::eliminar( $clave );
			wp_send_json_success( array( 'url' => $propia ? $url : add_query_arg( 'msg', $clave, $url ) ) );
		}
		if ( 'renombrar' === $op ) {
			Malka_CRM_Messages::renombrar( $clave, isset( $_POST['nombre'] ) ? wp_unslash( $_POST['nombre'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			wp_send_json_success();
		}
		wp_send_json_error( array( 'message' => __( 'Operación desconocida.', 'malka-crm' ) ), 400 );
	}

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Crea la tabla si falta.
	 */
	public static function instalar() {
		if ( get_option( self::OPT_DB ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		$t   = self::tabla();
		$col = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fecha datetime NOT NULL,
			para varchar(255) NOT NULL DEFAULT '',
			asunto varchar(255) NOT NULL DEFAULT '',
			cuerpo longtext NOT NULL,
			estado varchar(10) NOT NULL DEFAULT 'enviado',
			PRIMARY KEY  (id),
			KEY fecha (fecha),
			KEY para (para(100))
		) {$col};"
		);
		update_option( self::OPT_DB, self::DB_VERSION, false );
	}

	/**
	 * Un correo que salió.
	 *
	 * @param array $m Datos de wp_mail (to, subject, message…).
	 */
	public static function al_salir( $m ) {
		self::anotar( $m, 'enviado' );
	}

	/**
	 * Un correo que no salió.
	 *
	 * @param WP_Error $e Error con los datos del correo.
	 */
	public static function al_fallar( $e ) {
		if ( is_wp_error( $e ) ) {
			$d = $e->get_error_data();
			if ( is_array( $d ) ) {
				self::anotar( $d, 'fallo' );
			}
		}
	}

	/**
	 * Guarda una fila.
	 *
	 * @param array  $m      Datos.
	 * @param string $estado enviado|fallo.
	 */
	private static function anotar( $m, $estado ) {
		self::instalar();
		global $wpdb;
		$to = isset( $m['to'] ) ? $m['to'] : '';
		$to = is_array( $to ) ? implode( ', ', $to ) : (string) $to;
		if ( '' === trim( $to ) ) {
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::tabla(),
			array(
				'fecha'  => current_time( 'mysql' ),
				'para'   => substr( strtolower( $to ), 0, 255 ),
				'asunto' => substr( isset( $m['subject'] ) ? wp_strip_all_tags( (string) $m['subject'] ) : '', 0, 255 ),
				'cuerpo' => isset( $m['message'] ) ? (string) $m['message'] : '',
				'estado' => $estado,
			)
		);
	}

	/**
	 * Los correos que se le mandaron a una o varias direcciones.
	 *
	 * @param string[] $correos Direcciones.
	 * @param int      $max     Cuántos.
	 * @return object[]
	 */
	public static function de( $correos, $max = 50 ) {
		self::instalar();
		global $wpdb;
		$correos = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', (array) $correos ) ), 'is_email' ) );
		if ( ! $correos ) {
			return array();
		}
		$w    = array();
		$args = array();
		foreach ( $correos as $c ) {
			$w[]    = 'para LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $c ) . '%';
		}
		$args[] = (int) $max;
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE ' . implode( ' OR ', $w ) . ' ORDER BY fecha DESC, id DESC LIMIT %d', $args ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * El panel «Correos enviados» de la ficha de un cliente.
	 *
	 * @param array $c Cliente.
	 * @return string
	 */
	public static function panel_cliente( $c ) {
		$filas = self::de( array( $c['email'] ) );

		ob_start();
		echo '<section class="esc-panel esc-mails">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Correos enviados', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html(
			$filas
				? sprintf( /* translators: cantidad */ _n( '%d correo a %s', '%d correos a %s', count( $filas ), 'malka-crm' ), count( $filas ), $c['email'] )
				: __( 'Lo que le mande el sistema queda anotado acá', 'malka-crm' )
		) . '</p></div></div>';

		if ( ! is_email( $c['email'] ) ) {
			echo '<p class="esc-empty">' . esc_html__( 'Agrega su correo en la ficha para ver lo que le llega.', 'malka-crm' ) . '</p></section>';
			return ob_get_clean();
		}
		if ( ! $filas ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no se le ha enviado ningún correo desde que se lleva el registro.', 'malka-crm' ) . '</p></section>';
			return ob_get_clean();
		}

		echo '<div class="esc-mails__l">';
		foreach ( $filas as $f ) {
			$html = false !== stripos( $f->cuerpo, '<html' ) || false !== stripos( $f->cuerpo, '<p' ) || false !== stripos( $f->cuerpo, '<table' );
			printf(
				'<details class="esc-mails__i%1$s"><summary><span class="esc-mails__ic" aria-hidden="true"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3.5" width="12" height="9" rx="1.5"/><path d="M2.6 4.6L8 8.8l5.4-4.2"/></svg></span>' .
				'<span class="esc-mails__t"><b>%2$s</b><small>%3$s%4$s</small></span><span class="esc-mails__f">%5$s</span></summary>' .
				'<div class="esc-mails__c">%6$s</div></details>',
				'fallo' === $f->estado ? ' is-fallo' : '',
				esc_html( $f->asunto ? $f->asunto : __( '(sin asunto)', 'malka-crm' ) ),
				esc_html( $f->para ),
				'fallo' === $f->estado ? ' · ' . esc_html__( 'no salió', 'malka-crm' ) : '',
				esc_html( date_i18n( 'j M Y · H:i', strtotime( $f->fecha ) ) ),
				$html
					? '<iframe sandbox="" loading="lazy" title="' . esc_attr( $f->asunto ) . '" srcdoc="' . esc_attr( $f->cuerpo ) . '"></iframe>'
					: '<pre>' . esc_html( $f->cuerpo ) . '</pre>'
			);
		}
		echo '</div></section>';
		return ob_get_clean();
	}
}
