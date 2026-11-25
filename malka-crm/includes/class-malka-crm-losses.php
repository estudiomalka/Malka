<?php
/**
 * Las cuotas que ella da por perdidas.
 *
 * Hay plata que no va a volver. Mantenerla en Cobranza mes tras mes no la
 * acerca a la cuenta: sólo ensucia la pantalla donde están las que sí se
 * pueden cobrar, y hace que el total de «Por cobrar» sea una cifra en la que
 * no se puede confiar.
 *
 * Darla por perdida es una decisión interna, y acá eso se toma en serio:
 *
 *  · del lado de ella, la cuota desaparece de Cobranza, de los avisos
 *    automáticos y de los números del escritorio, y pasa a Pérdidas;
 *  · del lado del cliente, no cambia absolutamente nada. Su cotización, su
 *    portal y sus correos siguen diciendo lo mismo que decían. La deuda
 *    existe; lo que cambió es que ella dejó de perseguirla.
 *
 * Esa asimetría es el punto de la cosa, así que el estado vive sólo acá y
 * nada de lo que mira el cliente lo consulta.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marcar, deshacer y listar las cuotas dadas por perdidas.
 */
class Malka_CRM_Losses {

	/**
	 * Dónde se anotan, dentro de la cotización.
	 *
	 * Un arreglo cuota => {fecha, monto, dias}. Va en la cotización y no en
	 * una opción suelta para que borrar la cotización se lleve sus pérdidas
	 * con ella, sin dejar huérfanas.
	 */
	const META = '_malka_pm_perdidas';

	/**
	 * Engancha los dos botones.
	 */
	public function __construct() {
		add_action( 'admin_post_malka_crm_perder', array( $this, 'handle_marcar' ) );
		add_action( 'admin_post_malka_crm_recuperar', array( $this, 'handle_devolver' ) );
	}

	/* ─────────────────────────────  Datos  ────────────────────────────── */

	/**
	 * Las cuotas dadas por perdidas de una cotización.
	 *
	 * @param int $qid Cotización.
	 * @return array cuota_id => {fecha, monto, dias}
	 */
	public static function lista( $qid ) {
		$v = get_post_meta( (int) $qid, self::META, true );

		return is_array( $v ) ? $v : array();
	}

	/**
	 * Si esta cuota está dada por perdida.
	 *
	 * @param int    $qid   Cotización.
	 * @param string $cuota Id de la cuota.
	 * @return bool
	 */
	public static function es( $qid, $cuota ) {
		$cuota = (string) $cuota;

		if ( '' === $cuota ) {
			return false;
		}

		return isset( self::lista( $qid )[ $cuota ] );
	}

	/**
	 * La da por perdida.
	 *
	 * Se guarda el monto y el atraso del día en que lo decidió: después la
	 * cuota sigue envejeciendo sola, y «la di por perdida con 44 días de
	 * atraso» es un dato, mientras que «lleva 300» no dice nada.
	 *
	 * @param int    $qid   Cotización.
	 * @param string $cuota Id de la cuota.
	 * @param int    $monto Lo que se deja de perseguir.
	 * @param int    $dias  Días de atraso en ese momento.
	 * @return bool Si cambió algo.
	 */
	public static function marcar( $qid, $cuota, $monto = 0, $dias = 0 ) {
		$qid   = (int) $qid;
		$cuota = (string) $cuota;

		if ( ! $qid || '' === $cuota ) {
			return false;
		}

		$todas = self::lista( $qid );

		if ( isset( $todas[ $cuota ] ) ) {
			return false;
		}

		$todas[ $cuota ] = array(
			'fecha' => current_time( 'Y-m-d' ),
			'monto' => max( 0, (int) $monto ),
			'dias'  => (int) $dias,
		);

		update_post_meta( $qid, self::META, $todas );

		return true;
	}

	/**
	 * La devuelve a cobranza.
	 *
	 * @param int    $qid   Cotización.
	 * @param string $cuota Id de la cuota.
	 * @return bool Si cambió algo.
	 */
	public static function devolver( $qid, $cuota ) {
		$qid   = (int) $qid;
		$cuota = (string) $cuota;
		$todas = self::lista( $qid );

		if ( ! isset( $todas[ $cuota ] ) ) {
			return false;
		}

		unset( $todas[ $cuota ] );

		if ( $todas ) {
			update_post_meta( $qid, self::META, $todas );
		} else {
			// Sin ninguna, se borra la anotación entera en vez de dejar un
			// arreglo vacío colgando de la cotización.
			delete_post_meta( $qid, self::META );
		}

		return true;
	}

	/**
	 * Todas las perdidas, de la más reciente a la más vieja.
	 *
	 * Se arman desde las cuotas de verdad, no desde lo anotado: si una cuota
	 * dada por perdida resulta pagada después —porque el cliente apareció—,
	 * deja de salir acá sin que haya que acordarse de limpiar nada.
	 *
	 * @return array[]
	 */
	public static function todas() {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) || ! class_exists( 'Malka_CRM_Dashboard' ) ) {
			return array();
		}

		$out = array();

		foreach ( Malka_CRM_Dashboard::quotes_list() as $q ) {
			$perdidas = self::lista( $q['id'] );

			if ( ! $perdidas ) {
				continue;
			}

			foreach ( Malka_CRM_Quotes::get_payments( $q['id'] ) as $p ) {
				$cuota = isset( $p['id'] ) ? (string) $p['id'] : '';

				if ( '' === $cuota || ! isset( $perdidas[ $cuota ] ) ) {
					continue;
				}

				$e = Malka_CRM_Quote_Page::payment_view( $q['id'], $p );

				// Si entró, ya no es una pérdida: es un cobro que llegó tarde.
				if ( 'paid' === $e['state'] ) {
					continue;
				}

				$d = $perdidas[ $cuota ];

				$out[] = array(
					'quote_id' => (int) $q['id'],
					'cuota_id' => $cuota,
					'numero'   => $q['numero'] ? 'N° ' . $q['numero'] : '#' . $q['id'],
					'cliente'  => $q['cliente'],
					'cuota'    => isset( $p['label'] ) ? $p['label'] : '',
					'monto'    => isset( $d['monto'] ) && $d['monto'] > 0
						? (int) $d['monto']
						: (int) round( (float) $p['amount'] ),
					'desde'    => isset( $d['fecha'] ) ? (string) $d['fecha'] : '',
					'dias'     => isset( $d['dias'] ) ? (int) $d['dias'] : 0,
					'url'      => $q['url'],
				);
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( (string) $b['desde'], (string) $a['desde'] );
			}
		);

		return $out;
	}

	/**
	 * Cuánta plata hay dada por perdida.
	 *
	 * @return int
	 */
	public static function total() {
		$suma = 0;

		foreach ( self::todas() as $p ) {
			$suma += (int) $p['monto'];
		}

		return $suma;
	}

	/* ─────────────────────────────  Botones  ──────────────────────────── */

	/**
	 * La dirección del botón «Dar por perdida».
	 *
	 * @param int    $qid    Cotización.
	 * @param string $cuota  Cuota.
	 * @param string $volver A dónde regresar.
	 * @return string
	 */
	public static function url_marcar( $qid, $cuota, $volver = '' ) {
		return self::url( 'malka_crm_perder', $qid, $cuota, $volver );
	}

	/**
	 * La dirección del botón «Volver a cobrarla».
	 *
	 * @param int    $qid    Cotización.
	 * @param string $cuota  Cuota.
	 * @param string $volver A dónde regresar.
	 * @return string
	 */
	public static function url_devolver( $qid, $cuota, $volver = '' ) {
		return self::url( 'malka_crm_recuperar', $qid, $cuota, $volver );
	}

	/**
	 * Arma una de las dos direcciones, firmada.
	 *
	 * @param string $accion Acción.
	 * @param int    $qid    Cotización.
	 * @param string $cuota  Cuota.
	 * @param string $volver A dónde regresar.
	 * @return string
	 */
	private static function url( $accion, $qid, $cuota, $volver ) {
		$args = array(
			'action' => $accion,
			'quote'  => (int) $qid,
			'cuota'  => rawurlencode( (string) $cuota ),
		);

		if ( $volver ) {
			$args['volver'] = rawurlencode( $volver );
		}

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			$accion . '_' . (int) $qid . '_' . $cuota
		);
	}

	/**
	 * Marca la cuota como perdida y vuelve de donde vino.
	 */
	public function handle_marcar() {
		list( $qid, $cuota, $volver ) = $this->leer( 'malka_crm_perder' );

		$p    = $this->cuota( $qid, $cuota );
		$e    = $p ? Malka_CRM_Quote_Page::payment_view( $qid, $p ) : array();
		$dias = isset( $e['days'] ) ? (int) $e['days'] : 0;

		self::marcar(
			$qid,
			$cuota,
			$p ? (int) round( (float) $p['amount'] ) : 0,
			$dias < 0 ? abs( $dias ) : 0
		);

		$this->volver( $volver, 'perdida_ok' );
	}

	/**
	 * La devuelve a cobranza y vuelve de donde vino.
	 */
	public function handle_devolver() {
		list( $qid, $cuota, $volver ) = $this->leer( 'malka_crm_recuperar' );

		self::devolver( $qid, $cuota );

		$this->volver( $volver, 'perdida_no' );
	}

	/**
	 * Comprueba permisos y firma, y devuelve lo que llegó.
	 *
	 * @param string $accion Acción esperada.
	 * @return array {qid, cuota, volver}
	 */
	private function leer( $accion ) {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$qid   = isset( $_GET['quote'] ) ? (int) $_GET['quote'] : 0;
		$cuota = isset( $_GET['cuota'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['cuota'] ) ) ) : '';
		$vuelo = isset( $_GET['volver'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['volver'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer( $accion . '_' . $qid . '_' . $cuota );

		if ( ! $qid || Malka_CRM_Quotes::POST_TYPE !== get_post_type( $qid ) ) {
			wp_die( esc_html__( 'Esa cotización no existe.', 'malka-crm' ) );
		}

		return array( $qid, $cuota, $vuelo );
	}

	/**
	 * La cuota pedida dentro de su cotización.
	 *
	 * @param int    $qid   Cotización.
	 * @param string $cuota Cuota.
	 * @return array|null
	 */
	private function cuota( $qid, $cuota ) {
		foreach ( Malka_CRM_Quotes::get_payments( $qid ) as $p ) {
			if ( isset( $p['id'] ) && (string) $p['id'] === (string) $cuota ) {
				return $p;
			}
		}

		return null;
	}

	/**
	 * Vuelve a la pantalla de donde salió, con el acuse puesto.
	 *
	 * @param string $volver A dónde.
	 * @param string $msg    Qué decir.
	 */
	private function volver( $volver, $msg ) {
		$destino = $volver ? $volver : Malka_CRM_Dashboard::view_url( 'cobranza' );

		wp_safe_redirect( add_query_arg( 'malka_crm_msg', $msg, $destino ) );
		exit;
	}
}
