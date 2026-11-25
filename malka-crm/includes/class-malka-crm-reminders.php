<?php
/**
 * Los avisos de cobro.
 *
 * Hasta acá, «Avisar antes de vencer» y «Recordar cada» existían en
 * Configuración pero no los leía nadie: eran una promesa sin código detrás.
 * Este módulo es ese código.
 *
 * Todo lo que manda un correo al cliente pasa primero por puede_avisar(), que
 * tiene tres llaves. La tercera es la importante: una cotización que se carga
 * hoy, con cuotas que vencieron el mes pasado, no le escribe a nadie. El panel
 * no existía cuando esas cuotas vencieron, así que no tiene nada que reclamar.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Avisos automáticos de cobro al cliente.
 */
class Malka_CRM_Reminders {

	/** Si esta cotización avisa o no. */
	const META_AVISOS = '_malka_pm_quote_avisos';

	/** Desde qué fecha tiene sentido reclamar. */
	const META_DESDE = '_malka_pm_quote_avisa_desde';

	/** Qué se mandó ya, para no repetirlo. */
	const META_LOG = '_malka_pm_quote_avisado';

	/** Avisos con fecha puesta a mano: cuota => Y-m-d. */
	const META_PROG = '_malka_pm_quote_avisos_prog';

	/**
	 * Engancha el repaso diario y los dos botones de la cobranza.
	 */
	public function __construct() {
		add_action( 'malka_crm_daily', array( __CLASS__, 'run' ) );
		add_action( 'admin_post_malka_crm_avisar', array( __CLASS__, 'handle_avisar' ) );
		add_action( 'admin_post_malka_crm_programar', array( __CLASS__, 'handle_programar' ) );
	}

	/* ──────────────────────────  Las tres llaves  ───────────────────── */

	/**
	 * ¿Esta cotización tiene los avisos encendidos?
	 *
	 * @param int $qid Cotización.
	 * @return bool
	 */
	public static function enabled( $qid ) {
		$v = get_post_meta( $qid, self::META_AVISOS, true );

		// Sin valor guardado manda el default de Configuración. Las
		// cotizaciones viejas caen acá, y el default viene encendido.
		if ( '' === $v || null === $v ) {
			return (bool) Malka_CRM_Settings::get( 'avisos_default', 1 );
		}

		return '1' === (string) $v;
	}

	/**
	 * Desde qué fecha esta cotización puede reclamar.
	 *
	 * Es la fecha en que se cargó al panel. Lo anterior a eso ya pasó sin que
	 * el panel estuviera, y mandarle un recordatorio al cliente por algo que
	 * Malka ya arregló por otro lado la haría quedar mal.
	 *
	 * @param int $qid Cotización.
	 * @return string Y-m-d, o '' si no hay.
	 */
	public static function since( $qid ) {
		$d = (string) get_post_meta( $qid, self::META_DESDE, true );

		if ( $d ) {
			return $d;
		}

		// Sin marca propia, la fecha en que se creó el registro.
		$post = get_post( $qid );

		return $post ? gmdate( 'Y-m-d', strtotime( $post->post_date ) ) : '';
	}

	/**
	 * La pausa general, si está puesta.
	 *
	 * Es una fecha y no un interruptor a propósito: un interruptor apagado se
	 * olvida encendido, y los avisos automáticos sirven justamente para lo
	 * que uno olvida.
	 *
	 * @return string Y-m-d hasta el que no se manda nada, o ''.
	 */
	public static function paused_until() {
		$d = (string) Malka_CRM_Settings::get( 'avisos_pausa', '' );

		if ( ! $d || strtotime( $d ) < strtotime( current_time( 'Y-m-d' ) ) ) {
			return '';
		}

		return $d;
	}

	/**
	 * ¿Se le puede escribir al cliente por esta cuota?
	 *
	 * @param int    $qid   Cotización.
	 * @param string $fecha Y-m-d de la cuota. '' para un aviso que no cuelga
	 *                      de una fecha, como el de entrega.
	 * @return bool
	 */
	public static function puede_avisar( $qid, $fecha = '' ) {
		if ( ! self::enabled( $qid ) ) {
			return false;
		}

		if ( self::paused_until() ) {
			return false;
		}

		if ( $fecha ) {
			$desde = self::since( $qid );

			if ( $desde && strtotime( $fecha ) < strtotime( $desde ) ) {
				return false;
			}
		}

		return true;
	}

	/* ─────────────────────────────  Registro  ───────────────────────── */

	/**
	 * Lo que ya se mandó de esta cotización.
	 *
	 * @param int $qid Cotización.
	 * @return array cuota => array( tipo => Y-m-d ).
	 */
	public static function log( $qid ) {
		$l = get_post_meta( $qid, self::META_LOG, true );

		return is_array( $l ) ? $l : array();
	}

	/**
	 * ¿Ya se mandó este aviso?
	 *
	 * @param int    $qid   Cotización.
	 * @param string $cuota Id de la cuota.
	 * @param string $tipo  prev|mora.
	 * @param string $sello Lo que identifica a este envío.
	 * @return bool
	 */
	public static function ya_fue( $qid, $cuota, $tipo, $sello ) {
		$l = self::log( $qid );

		return isset( $l[ $cuota ][ $tipo ] ) && (string) $l[ $cuota ][ $tipo ] === (string) $sello;
	}

	/**
	 * Anota que se mandó.
	 *
	 * @param int    $qid   Cotización.
	 * @param string $cuota Id de la cuota.
	 * @param string $tipo  prev|mora.
	 * @param string $sello Identificador del envío.
	 */
	public static function anotar( $qid, $cuota, $tipo, $sello ) {
		$l = self::log( $qid );

		if ( ! isset( $l[ $cuota ] ) ) {
			$l[ $cuota ] = array();
		}

		$l[ $cuota ][ $tipo ] = (string) $sello;

		update_post_meta( $qid, self::META_LOG, $l );
	}

	/* ──────────────────────  Avisos puestos a mano  ─────────────────── */

	/**
	 * Las fechas que ella puso a mano, por cuota.
	 *
	 * @param int $qid Cotización.
	 * @return array cuota => Y-m-d.
	 */
	public static function programados( $qid ) {
		$p = get_post_meta( $qid, self::META_PROG, true );

		return is_array( $p ) ? $p : array();
	}

	/**
	 * La fecha puesta a mano para una cuota, si la hay.
	 *
	 * @param int    $qid   Cotización.
	 * @param string $cuota Id de la cuota.
	 * @return string Y-m-d o ''.
	 */
	public static function programado( $qid, $cuota ) {
		$p = self::programados( $qid );

		return isset( $p[ $cuota ] ) ? (string) $p[ $cuota ] : '';
	}

	/**
	 * Pone o quita la fecha de una cuota.
	 *
	 * @param int    $qid   Cotización.
	 * @param string $cuota Id de la cuota.
	 * @param string $fecha Y-m-d, o '' para quitarla.
	 */
	public static function programar( $qid, $cuota, $fecha ) {
		$p     = self::programados( $qid );
		$fecha = Malka_CRM_Helpers::sanitize_date( $fecha );

		if ( $fecha ) {
			$p[ $cuota ] = $fecha;
		} else {
			unset( $p[ $cuota ] );
		}

		update_post_meta( $qid, self::META_PROG, $p );
	}

	/**
	 * Lo que impide escribirle al cliente por esta cuota, pase lo que pase.
	 *
	 * Son hechos, no política: sin correo no hay a dónde mandarlo, una cuota
	 * pagada no se cobra, y un saldo que espera la entrega todavía no tiene
	 * fecha que reclamar. Las tres llaves de puede_avisar() sí son política y
	 * un envío a mano las pasa por encima: si ella aprieta el botón, ella
	 * decidió.
	 *
	 * @param int    $qid     Cotización.
	 * @param string $cuota   Id de la cuota.
	 * @param string $codigo  Sale con la clave del motivo: sin_correo, sin_cuota,
	 *                        pagada o espera. Las dos del medio y «pagada» son
	 *                        definitivas; las otras pueden cambiar mañana.
	 * @return string Motivo por el que no se puede, o '' si se puede.
	 */
	public static function impedimento( $qid, $cuota, &$codigo = null ) {
		$codigo = '';
		$email  = (string) get_post_meta( $qid, '_malka_pm_client_email', true );

		if ( ! is_email( $email ) ) {
			$codigo = 'sin_correo';
			return __( 'La cotización no tiene correo del cliente.', 'malka-crm' );
		}

		$p = self::buscar_cuota( $qid, $cuota );

		if ( ! $p ) {
			$codigo = 'sin_cuota';
			return __( 'Esa cuota ya no existe.', 'malka-crm' );
		}

		if ( isset( $p['status'] ) && 'pagado' === $p['status'] ) {
			$codigo = 'pagada';
			return __( 'Esa cuota ya está pagada.', 'malka-crm' );
		}

		// El cliente ya dijo que pagó. Insistirle mientras ella no revisa la
		// cuenta es cobrarle dos veces por algo que él ya contestó.
		if ( isset( $p['status'] ) && 'confirmar' === $p['status'] ) {
			$codigo = 'avisada';
			return __( 'El cliente avisó que ya la pagó: falta que lo confirmes.', 'malka-crm' );
		}

		$e = Malka_CRM_Quote_Page::payment_view( $qid, $p );

		if ( ! empty( $e['espera'] ) || empty( $e['fecha'] ) ) {
			$codigo = 'espera';
			return __( 'El saldo todavía espera la entrega: no hay fecha que cobrar.', 'malka-crm' );
		}

		return '';
	}

	/**
	 * Una cuota por su id.
	 *
	 * @param int    $qid   Cotización.
	 * @param string $cuota Id.
	 * @return array|null
	 */
	private static function buscar_cuota( $qid, $cuota ) {
		foreach ( Malka_CRM_Quotes::get_payments( $qid ) as $p ) {
			if ( isset( $p['id'] ) && (string) $p['id'] === (string) $cuota ) {
				return $p;
			}
		}

		return null;
	}

	/**
	 * Manda el aviso de una cuota ahora mismo.
	 *
	 * @param int    $qid   Cotización.
	 * @param string $cuota Id de la cuota.
	 * @return string '' si salió, o el motivo por el que no.
	 */
	public static function enviar_ahora( $qid, $cuota ) {
		$codigo = '';
		$motivo = self::impedimento( $qid, $cuota, $codigo );

		if ( $motivo ) {
			/*
			 * Una cuota pagada o borrada no va a dejar de estarlo: si tenía
			 * fecha programada, se suelta para que el repaso diario no la
			 * vuelva a intentar todos los días hasta el fin de los tiempos.
			 */
			if ( in_array( $codigo, array( 'pagada', 'sin_cuota' ), true ) && self::programado( $qid, $cuota ) ) {
				self::programar( $qid, $cuota, '' );
			}

			return $motivo;
		}

		$p     = self::buscar_cuota( $qid, $cuota );
		$e     = Malka_CRM_Quote_Page::payment_view( $qid, $p );
		$email = (string) get_post_meta( $qid, '_malka_pm_client_email', true );
		$dias  = (int) floor( ( strtotime( $e['fecha'] ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
		$tipo  = $dias < 0 ? 'mora' : 'prev';

		if ( ! self::enviar( $qid, $p, $e, $tipo, $dias, $email ) ) {
			return __( 'WordPress no pudo mandar el correo.', 'malka-crm' );
		}

		/*
		 * Queda anotado para que el repaso diario no repita hoy mismo lo que
		 * ella acaba de mandar a mano.
		 */
		$sello = 'mora' === $tipo ? $e['fecha'] . '+' . abs( $dias ) : $e['fecha'];
		self::anotar( $qid, $cuota, $tipo, $sello );

		// Una fecha programada que ya se cumplió deja de tener sentido.
		if ( self::programado( $qid, $cuota ) ) {
			self::programar( $qid, $cuota, '' );
		}

		return '';
	}

	/**
	 * Botón «Avisar ahora».
	 */
	public static function handle_avisar() {
		$qid   = isset( $_REQUEST['quote'] ) ? absint( $_REQUEST['quote'] ) : 0;
		$cuota = isset( $_REQUEST['cuota'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cuota'] ) ) : '';

		check_admin_referer( 'malka_crm_avisar_' . $qid . '_' . $cuota );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$motivo = self::enviar_ahora( $qid, $cuota );

		self::volver( $motivo ? 'aviso_no' : 'aviso_ok', $motivo );
	}

	/**
	 * Botón «Programar».
	 */
	public static function handle_programar() {
		$qid   = isset( $_REQUEST['quote'] ) ? absint( $_REQUEST['quote'] ) : 0;
		$cuota = isset( $_REQUEST['cuota'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cuota'] ) ) : '';
		$fecha = isset( $_REQUEST['fecha'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['fecha'] ) ) : '';

		check_admin_referer( 'malka_crm_programar_' . $qid . '_' . $cuota );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		self::programar( $qid, $cuota, $fecha );

		self::volver( $fecha ? 'prog_ok' : 'prog_off' );
	}

	/**
	 * Vuelve a la pantalla desde la que se apretó el botón.
	 *
	 * @param string $msg    Clave del mensaje.
	 * @param string $motivo Texto del fallo, si lo hubo.
	 */
	private static function volver( $msg, $motivo = '' ) {
		$destino = isset( $_REQUEST['volver'] ) ? esc_url_raw( wp_unslash( $_REQUEST['volver'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $destino ) {
			$destino = Malka_CRM_Dashboard::view_url( 'cobranza' );
		}

		$args = array( 'malka_crm_msg' => $msg );

		if ( $motivo ) {
			$args['malka_crm_por'] = rawurlencode( $motivo );
		}

		wp_safe_redirect( add_query_arg( $args, $destino ) );
		exit;
	}

	/* ───────────────────────────  El repaso  ────────────────────────── */

	/**
	 * Recorre las cotizaciones y manda lo que corresponda.
	 *
	 * @param bool $seco Si true, no manda nada: sólo devuelve qué mandaría.
	 * @return array[] Lo enviado (o lo que se enviaría).
	 */
	public static function run( $seco = false ) {
		$hoy   = strtotime( current_time( 'Y-m-d' ) );
		$prev  = max( 0, (int) Malka_CRM_Settings::get( 'mora_aviso_prev', 3 ) );
		$cada  = max( 1, (int) Malka_CRM_Settings::get( 'mora_recordar', 7 ) );
		$salidas = array();

		/*
		 * La pausa general apaga lo automático, no lo que ella programó con
		 * fecha: poner una fecha es una decisión de ella sobre esa cuota, y
		 * pesa más que un ajuste puesto hace semanas.
		 */
		$pausa = (bool) self::paused_until();

		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $qid ) {
			$estado = (string) get_post_meta( $qid, '_malka_pm_quote_status', true );

			// Rechazada o caducada no se cobra; finalizada no debe nada.
			if ( in_array( $estado, array( 'rechazada', 'caducada', 'finalizada' ), true ) ) {
				continue;
			}

			$email = (string) get_post_meta( $qid, '_malka_pm_client_email', true );

			if ( ! is_email( $email ) ) {
				continue;
			}

			$encendida = self::enabled( $qid );
			$prog      = self::programados( $qid );

			foreach ( Malka_CRM_Quotes::get_payments( $qid ) as $p ) {
				$cuota = isset( $p['id'] ) ? (string) $p['id'] : '';

				// Primero lo que ella puso con fecha: le gana a todo lo demás.
				if ( $cuota && isset( $prog[ $cuota ] ) && strtotime( $prog[ $cuota ] ) <= $hoy ) {
					$salidas[] = array(
						'quote' => (int) $qid,
						'cuota' => $cuota,
						'tipo'  => 'programado',
						'dias'  => 0,
						'email' => $email,
					);

					if ( ! $seco ) {
						self::enviar_ahora( $qid, $cuota );
					}

					continue;
				}

				if ( ! $encendida || $pausa ) {
					continue;
				}

				$salida = self::revisar_cuota( $qid, $p, $hoy, $prev, $cada, $email, $seco );

				if ( $salida ) {
					$salidas[] = $salida;
				}
			}
		}

		return $salidas;
	}

	/**
	 * Decide si una cuota merece un correo hoy.
	 *
	 * @param int    $qid   Cotización.
	 * @param array  $p     Cuota.
	 * @param int    $hoy   Timestamp de hoy.
	 * @param int    $prev  Días de aviso previo.
	 * @param int    $cada  Cada cuántos días se recuerda una vencida.
	 * @param string $email Correo del cliente.
	 * @param bool   $seco  Sin enviar.
	 * @return array|null
	 */
	private static function revisar_cuota( $qid, $p, $hoy, $prev, $cada, $email, $seco ) {
		if ( isset( $p['status'] ) && 'pagado' === $p['status'] ) {
			return null;
		}

		// El «Pago total» es la opción de pagar todo junto, no una cuota que
		// venza: recordarlo era cobrarle al cliente lo mismo dos veces.
		if ( Malka_CRM_Quotes::es_resumen( $qid, $p ) ) {
			return null;
		}

		// Avisada por el cliente: el recordatorio automático se calla hasta
		// que ella la confirme o la devuelva a «en espera».
		if ( isset( $p['status'] ) && 'confirmar' === $p['status'] ) {
			return null;
		}

		$e = Malka_CRM_Quote_Page::payment_view( $qid, $p );

		// Un saldo que todavía espera la entrega no se cobra.
		if ( ! empty( $e['espera'] ) || empty( $e['fecha'] ) ) {
			return null;
		}

		/*
		 * Dada por perdida: el aviso automático se calla. Esto es lo que
		 * hace que darla por perdida sirva de algo —si no, el repaso diario
		 * seguiría escribiéndole al cliente cada siete días por una deuda
		 * que ella ya decidió no perseguir—. El botón «Avisar» a mano sigue
		 * disponible desde Pérdidas, porque pedirlo ella es otra cosa.
		 */
		if ( ! empty( $e['perdida'] ) ) {
			return null;
		}

		$cuota = isset( $p['id'] ) ? (string) $p['id'] : '';
		$dias  = (int) floor( ( strtotime( $e['fecha'] ) - $hoy ) / DAY_IN_SECONDS );

		if ( ! self::puede_avisar( $qid, $e['fecha'] ) ) {
			return null;
		}

		if ( $dias > 0 && $prev > 0 && $dias <= $prev ) {
			$tipo  = 'prev';
			$sello = $e['fecha'];
		} elseif ( $dias < 0 ) {
			$atraso = abs( $dias );

			// Sólo en los múltiplos del intervalo: el día 7, el 14, el 21.
			if ( 0 !== $atraso % $cada ) {
				return null;
			}

			$tipo  = 'mora';
			$sello = $e['fecha'] . '+' . $atraso;
		} else {
			return null;
		}

		if ( self::ya_fue( $qid, $cuota, $tipo, $sello ) ) {
			return null;
		}

		$salida = array(
			'quote' => (int) $qid,
			'cuota' => $cuota,
			'tipo'  => $tipo,
			'dias'  => $dias,
			'email' => $email,
		);

		if ( $seco ) {
			return $salida;
		}

		if ( self::enviar( $qid, $p, $e, $tipo, $dias, $email ) ) {
			self::anotar( $qid, $cuota, $tipo, $sello );
			return $salida;
		}

		return null;
	}

	/**
	 * Escribe y manda el correo.
	 *
	 * @param int    $qid   Cotización.
	 * @param array  $p     Cuota.
	 * @param array  $e     Estado resuelto de la cuota.
	 * @param string $tipo  prev|mora.
	 * @param int    $dias  Días que faltan (positivo) o de atraso (negativo).
	 * @param string $email Correo del cliente.
	 * @return bool
	 */
	private static function enviar( $qid, $p, $e, $tipo, $dias, $email ) {
		if ( ! class_exists( 'Malka_CRM_Messages' ) ) {
			return false;
		}

		$d   = Malka_CRM_Quote_Page::data( $qid );
		$due = Malka_CRM_Quote_Page::amount_due( $qid, $p );

		$cuota = isset( $p['label'] ) && '' !== trim( (string) $p['label'] )
			? $p['label']
			: __( 'la cuota', 'malka-crm' );

		$datos = array(
			'cliente' => $d['cliente']['nombre'] ? $d['cliente']['nombre'] : __( 'estimado', 'malka-crm' ),
			'cuota'   => $cuota,
			'numero'  => $d['numero'],
			'monto'   => Malka_CRM_Settings::money( (int) $p['amount'] ),
			'total'   => Malka_CRM_Settings::money( (int) $due['monto'] ),
			'fecha'   => $e['fecha'] ? date_i18n( 'j \\d\\e F', strtotime( $e['fecha'] ) ) : '',
			'dias'    => (string) abs( (int) $dias ),
			'interes_monto' => '',
			'enlace'  => $d['url'],
			'estudio' => Malka_CRM_Messages::estudio(),
		);

		if ( 'prev' === $tipo ) {
			$datos['faltan'] = Malka_CRM_Messages::dias( $dias );

			return Malka_CRM_Messages::enviar( $email, 'cobro_proximo', $datos );
		} else {
			$datos['atraso'] = Malka_CRM_Messages::dias( $dias );

			// Igual que en Cobranza: el monto, y la frase la pone su plantilla.
			if ( $due['interes'] > 0 ) {
				$datos['interes_monto'] = Malka_CRM_Settings::money( (int) $due['interes'] );
			}

			return Malka_CRM_Messages::enviar( $email, 'cobro_vencido', $datos );
		}
	}
}
