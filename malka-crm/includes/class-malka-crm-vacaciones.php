<?php
/**
 * Modo vacaciones.
 *
 * Para el cliente no cambia nada: sus cuotas vencen igual, los intereses
 * corren igual y los correos de cobranza le siguen llegando. Lo único que
 * se detiene es lo que llega al correo de ella.
 *
 * Y no se tira: se guarda. Volver de dos semanas sin saber qué pasó sería
 * peor que recibir los correos. Al desactivarlo, el Inicio muestra todo lo
 * que se retuvo, en orden.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Vacaciones {

	const OPT = 'malka_crm_vacaciones';

	/* Cuántos avisos se guardan como mucho. */
	const TOPE = 200;

	/**
	 * Lo que sí sale aunque esté activo: un aviso de impuestos o de un pago
	 * del estudio que vence. Lo pone quien manda ese correo, alrededor de su
	 * propio wp_mail().
	 *
	 * @var bool
	 */
	public static $deja_pasar = false;

	public function __construct() {
		// Antes de que WordPress arme el correo: así no sale ni del servidor.
		add_filter( 'pre_wp_mail', array( __CLASS__, 'filtrar' ), 10, 2 );
		add_action( 'admin_post_malka_crm_vacaciones', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_post_malka_crm_vacaciones_visto', array( __CLASS__, 'handle_visto' ) );
		// Si puso fecha de vuelta y ya pasó, se apaga solo.
		add_action( 'init', array( __CLASS__, 'vencer' ), 20 );
	}

	/* ──────────────────────────────  Estado  ─────────────────────────── */

	/**
	 * @return array activo, hasta, desde, impuestos, cola, resumen.
	 */
	public static function estado() {
		$d = get_option( self::OPT, array() );
		$d = is_array( $d ) ? $d : array();

		return wp_parse_args(
			$d,
			array(
				'activo'    => 0,
				'desde'     => '',
				'hasta'     => '',
				'impuestos' => 1,
				'cola'      => array(),
				'resumen'   => array(),
			)
		);
	}

	/**
	 * @param array $d Estado completo.
	 */
	private static function guardar( $d ) {
		update_option( self::OPT, $d, false );
	}

	public static function activo() {
		$e = self::estado();
		return ! empty( $e['activo'] );
	}

	/**
	 * Hasta cuándo, si puso fecha.
	 *
	 * @return string Y-m-d o ''.
	 */
	public static function hasta() {
		$e = self::estado();
		return ! empty( $e['activo'] ) ? (string) $e['hasta'] : '';
	}

	/**
	 * Enciende el modo.
	 *
	 * @param string $hasta     Y-m-d de vuelta, o '' para apagarlo a mano.
	 * @param bool   $impuestos Dejar pasar los avisos de impuestos y pagos.
	 */
	public static function activar( $hasta = '', $impuestos = true ) {
		$e = self::estado();

		$e['activo']    = 1;
		$e['desde']     = current_time( 'Y-m-d' );
		$e['hasta']     = $hasta ? (string) $hasta : '';
		$e['impuestos'] = $impuestos ? 1 : 0;
		$e['cola']      = array();
		$e['resumen']   = array();

		self::guardar( $e );
	}

	/**
	 * Lo apaga y deja lo retenido listo para mirar.
	 *
	 * @return int Cuántos avisos quedaron guardados.
	 */
	public static function desactivar() {
		$e = self::estado();

		$e['activo']  = 0;
		$e['resumen'] = isset( $e['cola'] ) ? (array) $e['cola'] : array();
		$e['cola']    = array();

		self::guardar( $e );

		return count( $e['resumen'] );
	}

	/**
	 * Si puso fecha de vuelta y ya llegó, se apaga sin que tenga que hacerlo.
	 */
	public static function vencer() {
		$e = self::estado();

		if ( empty( $e['activo'] ) || empty( $e['hasta'] ) ) {
			return;
		}

		if ( current_time( 'Y-m-d' ) > (string) $e['hasta'] ) {
			self::desactivar();
		}
	}

	/* ──────────────────────────────  Correo  ─────────────────────────── */

	/**
	 * Los correos que son para ella.
	 *
	 * No se mira el asunto ni quién lo manda: se mira a quién va. Así,
	 * cualquier aviso nuevo que se agregue al panel queda cubierto sin
	 * tener que acordarse de este archivo.
	 *
	 * @return string[] En minúsculas.
	 */
	public static function suyos() {
		$suyos = array( (string) get_option( 'admin_email' ) );

		if ( class_exists( 'Malka_CRM_Settings' ) ) {
			$suyos[] = (string) Malka_CRM_Settings::get( 'email', '' );
		}

		$u = wp_get_current_user();

		if ( $u && ! empty( $u->user_email ) ) {
			$suyos[] = (string) $u->user_email;
		}

		/**
		 * Otras direcciones que cuentan como suyas.
		 *
		 * @param string[] $suyos Direcciones.
		 */
		$suyos = (array) apply_filters( 'malka_crm_vacaciones_suyos', $suyos );
		$suyos = array_filter( array_map( 'strtolower', array_map( 'trim', $suyos ) ) );

		return array_values( array_unique( $suyos ) );
	}

	/**
	 * ¿Este correo va para ella?
	 *
	 * @param string|string[] $para Destinatarios.
	 * @return bool
	 */
	public static function es_suyo( $para ) {
		$suyos = self::suyos();

		foreach ( (array) $para as $uno ) {
			// «Nombre <correo@dominio>» además de la dirección pelada.
			if ( preg_match( '/<([^>]+)>/', (string) $uno, $m ) ) {
				$uno = $m[1];
			}
			if ( in_array( strtolower( trim( (string) $uno ) ), $suyos, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retiene los correos de ella mientras el modo esté puesto.
	 *
	 * @param null|bool $corto Lo que devuelva otro filtro antes.
	 * @param array     $args  to, subject, message, headers, attachments.
	 * @return null|bool null deja seguir; true dice «ya está mandado».
	 */
	public static function filtrar( $corto, $args ) {
		if ( null !== $corto || self::$deja_pasar || ! self::activo() ) {
			return $corto;
		}

		$para = isset( $args['to'] ) ? $args['to'] : '';

		if ( ! self::es_suyo( $para ) ) {
			return $corto;
		}

		self::retener(
			isset( $args['subject'] ) ? (string) $args['subject'] : '',
			isset( $args['message'] ) ? (string) $args['message'] : ''
		);

		// true: wp_mail() contesta que salió bien y no manda nada. Quien lo
		// llamó sigue su curso —marca el aviso como enviado, por ejemplo—,
		// que es justo lo que queremos: a la vuelta no llega todo de golpe.
		return true;
	}

	/**
	 * Guarda un aviso retenido.
	 *
	 * @param string $asunto Asunto.
	 * @param string $cuerpo Cuerpo, para sacarle una línea.
	 */
	private static function retener( $asunto, $cuerpo ) {
		$e = self::estado();

		$e['cola'][] = array(
			'fecha'  => current_time( 'Y-m-d H:i' ),
			'asunto' => wp_strip_all_tags( $asunto ),
			'linea'  => self::resumir( $cuerpo ),
		);

		if ( count( $e['cola'] ) > self::TOPE ) {
			$e['cola'] = array_slice( $e['cola'], -self::TOPE );
		}

		self::guardar( $e );
	}

	/**
	 * La primera línea con texto de un correo, sea HTML o no.
	 *
	 * @param string $cuerpo Cuerpo.
	 * @return string
	 */
	public static function resumir( $cuerpo ) {
		$txt = wp_strip_all_tags( (string) $cuerpo );
		$txt = html_entity_decode( $txt, ENT_QUOTES, 'UTF-8' );

		foreach ( preg_split( '/\R+/', $txt ) as $linea ) {
			$linea = trim( preg_replace( '/\s+/', ' ', $linea ) );
			if ( mb_strlen( $linea ) > 12 ) {
				return mb_substr( $linea, 0, 140 );
			}
		}

		return '';
	}

	/* ──────────────────────────────  Acciones  ───────────────────────── */

	public static function handle_toggle() {
		check_admin_referer( 'malka_crm_vacaciones' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( self::activo() ) {
			$n     = self::desactivar();
			$extra = array( 'vac' => 'vuelta', 'vacn' => (int) $n );
		} else {
			$hasta = isset( $_POST['hasta'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['hasta'] ) ) : '';

			// Una fecha de vuelta ya pasada dejaría el modo muerto al nacer.
			if ( $hasta && $hasta < current_time( 'Y-m-d' ) ) {
				$hasta = '';
			}

			self::activar( $hasta, ! empty( $_POST['impuestos'] ) );
			$extra = array( 'vac' => 'ida' );
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$vuelve = class_exists( 'Malka_CRM_Dashboard' ) ? Malka_CRM_Dashboard::view_url( 'inicio' ) : home_url( '/' );

		wp_safe_redirect( add_query_arg( $extra, $vuelve ) );
		exit;
	}

	/**
	 * «Ya lo vi»: borra el resumen de la vuelta.
	 */
	public static function handle_visto() {
		check_admin_referer( 'malka_crm_vacaciones_visto' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}

		$e            = self::estado();
		$e['resumen'] = array();
		self::guardar( $e );

		$vuelve = class_exists( 'Malka_CRM_Dashboard' ) ? Malka_CRM_Dashboard::view_url( 'inicio' ) : home_url( '/' );

		wp_safe_redirect( $vuelve );
		exit;
	}
}
