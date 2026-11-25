<?php
/**
 * Lo que te deben: la plata que prestaste.
 *
 * El espejo exacto de Deudas. Cuando le prestas a alguien, la plata sale de
 * tu cuenta pero no la perdiste: sigue siendo tuya, sólo que está en otra
 * parte. Por eso un préstamo no es un costo y un abono no es un ingreso —
 * contarlo así inflaría las ventas del estudio y bajaría la utilidad sin que
 * nada de eso haya pasado. Los dos movimientos van con categoría «Préstamo»
 * y, cuando te devuelven todo, se cancelan entre ellos.
 *
 * Lo que sí hace es bajar el saldo de la cuenta de donde salió, porque eso es
 * verdad: esa plata hoy no la tienes.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Prestamos {

	const OPT = 'malka_crm_prestamos';

	public function __construct() {
		add_action( 'admin_post_malka_crm_prestamo_guardar', array( __CLASS__, 'handle_guardar' ) );
		add_action( 'admin_post_malka_crm_prestamo_abonar', array( __CLASS__, 'handle_abonar' ) );
		add_action( 'admin_post_malka_crm_prestamo_borrar', array( __CLASS__, 'handle_borrar' ) );
		add_action( 'wp_ajax_malka_crm_prestamo_campo', array( __CLASS__, 'ajax_campo' ) );
	}

	/* ──────────────────────────────  Datos  ──────────────────────────────── */

	/**
	 * Todos los préstamos, crudos.
	 *
	 * @return array[]
	 */
	public static function todos() {
		$p = get_option( self::OPT, array() );
		return is_array( $p ) ? $p : array();
	}

	/**
	 * Crea o edita un préstamo.
	 *
	 * @param array $d Datos.
	 * @return int Id, o 0 si faltó algo.
	 */
	public static function guardar( $d ) {
		$todos = self::todos();
		$id    = isset( $d['id'] ) ? (int) $d['id'] : 0;

		$nueva = ( ! $id || ! isset( $todos[ $id ] ) );

		if ( $nueva ) {
			$id           = $todos ? max( array_keys( $todos ) ) + 1 : 1;
			$todos[ $id ] = array(
				'id'       => $id,
				'devuelto' => 0,
			);
		}

		$x       = $todos[ $id ];
		$fue     = isset( $x['fecha'] ) ? (string) $x['fecha'] : '';

		$x['quien'] = isset( $d['quien'] ) ? sanitize_text_field( (string) $d['quien'] ) : ( isset( $x['quien'] ) ? $x['quien'] : '' );

		/*
		 * «me_deben» es plata que prestaste; «debo», plata que te prestaron.
		 * Es la misma ficha con el signo al revés: cambia hacia dónde se mueve
		 * la cuenta cuando se anota y cuando se salda.
		 */
		if ( isset( $d['lado'] ) && in_array( $d['lado'], array( 'me_deben', 'debo' ), true ) ) {
			$x['lado'] = $d['lado'];
		} elseif ( ! isset( $x['lado'] ) ) {
			$x['lado'] = 'me_deben';
		}
		$x['nota']  = isset( $d['nota'] ) ? sanitize_text_field( (string) $d['nota'] ) : ( isset( $x['nota'] ) ? $x['nota'] : '' );

		foreach ( array( 'monto', 'devuelto', 'cuotas', 'dia_pago' ) as $k ) {
			if ( isset( $d[ $k ] ) ) {
				$x[ $k ] = max( 0, (int) $d[ $k ] );
			} elseif ( ! isset( $x[ $k ] ) ) {
				$x[ $k ] = 0;
			}
		}

		foreach ( array( 'fecha', 'vence' ) as $k ) {
			if ( isset( $d[ $k ] ) ) {
				$v       = (string) $d[ $k ];
				$x[ $k ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
			} elseif ( ! isset( $x[ $k ] ) ) {
				$x[ $k ] = '';
			}
		}

		/*
		 * Sin fecha, hoy. Pero sólo al anotar algo nuevo: corrigiendo una
		 * ficha que ya tiene fecha, una fecha que llega vacía —el campo en
		 * blanco, o un valor que el navegador no mandó— se quedaba con hoy
		 * y la pantalla decía «guardada». La fecha cambiaba sola y parecía
		 * que no se había guardado nada.
		 */
		if ( ! $x['fecha'] ) {
			$x['fecha'] = $fue ? $fue : current_time( 'Y-m-d' );
		}

		// Un día fuera del mes no es un día, y 200 cuotas no es un acuerdo.
		if ( $x['dia_pago'] < 1 || $x['dia_pago'] > 31 ) {
			$x['dia_pago'] = 0;
		}

		if ( $x['cuotas'] > 120 ) {
			$x['cuotas'] = 120;
		}

		// Nadie te debe si no sabes quién ni cuánto.
		if ( '' === trim( (string) $x['quien'] ) || $x['monto'] <= 0 ) {
			return 0;
		}

		// No te pueden haber devuelto más de lo que prestaste.
		if ( $x['devuelto'] > $x['monto'] ) {
			$x['devuelto'] = $x['monto'];
		}

		$todos[ $id ] = $x;
		update_option( self::OPT, $todos, false );

		return $id;
	}

	/**
	 * Borra un préstamo.
	 *
	 * No toca los movimientos ya anotados: la plata se movió de verdad y
	 * borrarla de la cuenta dejaría el saldo mintiendo.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function borrar( $id ) {
		$todos = self::todos();
		$id    = (int) $id;

		if ( ! isset( $todos[ $id ] ) ) {
			return false;
		}

		unset( $todos[ $id ] );
		update_option( self::OPT, $todos, false );

		return true;
	}

	/**
	 * La lista, ordenada por lo que falta y con lo pendiente ya calculado.
	 *
	 * @return array[]
	 */
	public static function lista( $lado = '' ) {
		$out = array();

		foreach ( self::todos() as $id => $p ) {
			if ( $lado && $lado !== ( isset( $p['lado'] ) ? $p['lado'] : 'me_deben' ) ) {
				continue;
			}

			$monto    = (int) $p['monto'];
			$devuelto = (int) $p['devuelto'];
			$vence    = isset( $p['vence'] ) ? (string) $p['vence'] : '';

			/*
			 * Si quedaron en cuotas, la fecha que importa para avisar no es la
			 * del final sino la de la cuota de este mes: es lo que hay que
			 * cobrar o pagar ahora. Cuántas van se deduce de lo abonado, para
			 * no tener dos números que se puedan contradecir.
			 *
			 * Esa fecha calculada va aparte, en «proxima». Antes pisaba a
			 * «vence» y el resultado era que corregir «cuándo la devuelves»
			 * parecía funcionar y al recargar volvía sola a la fecha de la
			 * próxima cuota: lo guardado estaba bien, lo que se mostraba no.
			 */
			$cuotas  = isset( $p['cuotas'] ) ? (int) $p['cuotas'] : 0;
			$dia     = isset( $p['dia_pago'] ) ? (int) $p['dia_pago'] : 0;
			$cuota   = $cuotas > 0 ? (int) ceil( $monto / $cuotas ) : 0;
			$pagadas = $cuota > 0 ? min( $cuotas, (int) floor( $devuelto / $cuota ) ) : 0;
			$listo   = $devuelto >= $monto;
			$proxima = $vence;

			if ( $cuotas > 0 && $dia && ! $listo && class_exists( 'Malka_CRM_Deudas' ) ) {
				$proxima = Malka_CRM_Deudas::proxima_fecha( $dia );
			}

			$out[] = array(
				'id'        => (int) $id,
				'lado'      => isset( $p['lado'] ) ? (string) $p['lado'] : 'me_deben',
				'quien'     => (string) $p['quien'],
				'monto'     => $monto,
				'devuelto'  => $devuelto,
				'pendiente' => max( 0, $monto - $devuelto ),
				'fecha'     => isset( $p['fecha'] ) ? (string) $p['fecha'] : '',
				// Lo que ella escribió, tal cual.
				'vence'     => $vence,
				// Lo que toca ahora: la cuota del mes, o lo mismo si no hay cuotas.
				'proxima'   => $proxima,
				'dias'      => $proxima && class_exists( 'Malka_CRM_Deudas' ) ? Malka_CRM_Deudas::dias_hasta( $proxima ) : 0,
				'nota'      => isset( $p['nota'] ) ? (string) $p['nota'] : '',
				'listo'     => $listo,
				'cuotas'    => $cuotas,
				'dia_pago'  => $dia,
				'cuota'     => $cuota,
				'pagadas'   => $pagadas,
				'restantes' => max( 0, $cuotas - $pagadas ),
			);
		}

		// Lo que falta cobrar arriba; lo ya devuelto, al final.
		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['listo'] !== $b['listo'] ) {
					return $a['listo'] ? 1 : -1;
				}
				if ( $a['pendiente'] === $b['pendiente'] ) {
					return 0;
				}
				return $a['pendiente'] > $b['pendiente'] ? -1 : 1;
			}
		);

		return $out;
	}

	/**
	 * Cuánto te deben en total.
	 *
	 * @param array[]|null $lista Lista, o null para pedirla.
	 * @return array pendiente, prestado, devuelto, cuantos.
	 */
	public static function totales( $lista = null ) {
		$lista = null === $lista ? self::lista() : $lista;
		$out   = array(
			'pendiente' => 0,
			'prestado'  => 0,
			'devuelto'  => 0,
			'cuantos'   => 0,
		);

		foreach ( $lista as $p ) {
			$out['pendiente'] += $p['pendiente'];
			$out['prestado']  += $p['monto'];
			$out['devuelto']  += $p['devuelto'];

			if ( $p['pendiente'] > 0 ) {
				$out['cuantos']++;
			}
		}

		return $out;
	}

	/**
	 * Lo que hay que cobrar o pagar pronto, de los dos lados.
	 *
	 * @param int $dentro Cuántos días mirar hacia adelante.
	 * @return array[]
	 */
	public static function proximos( $dentro = 10 ) {
		$out = array();

		foreach ( self::lista() as $p ) {
			if ( $p['listo'] || ! $p['proxima'] || $p['dias'] > (int) $dentro ) {
				continue;
			}

			$out[] = $p;
		}

		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['dias'] === $b['dias'] ) {
					return 0;
				}
				return $a['dias'] < $b['dias'] ? -1 : 1;
			}
		);

		return $out;
	}

	/* ─────────────────────────  Mover la plata  ──────────────────────────── */

	/**
	 * Anota que prestaste: sale de tu cuenta y queda como que te deben.
	 *
	 * @param string $quien  A quién.
	 * @param int    $monto  Cuánto.
	 * @param int    $cuenta De qué cuenta salió. 0 para no tocar ninguna.
	 * @param string $fecha  Y-m-d.
	 * @param string $vence  Cuándo te lo devuelven, opcional.
	 * @param string $nota   Para qué era.
	 * @return int Id del préstamo, o 0.
	 */
	public static function prestar( $quien, $monto, $cuenta = 0, $fecha = '', $vence = '', $nota = '', $lado = 'me_deben', $cuotas = 0, $dia = 0 ) {
		$monto = (int) $monto;
		$fecha = self::fecha_valida( $fecha );
		$lado  = 'debo' === $lado ? 'debo' : 'me_deben';

		$id = self::guardar(
			array(
				'quien' => $quien,
				'monto' => $monto,
				'fecha' => $fecha,
				'vence' => $vence,
				'nota'     => $nota,
				'lado'     => $lado,
				'cuotas'   => (int) $cuotas,
				'dia_pago' => (int) $dia,
			)
		);

		if ( ! $id ) {
			return 0;
		}

		$cuenta = (int) $cuenta;

		if ( $cuenta && class_exists( 'Malka_CRM_Finance' ) ) {
			$cuentas = Malka_CRM_Finance::cuentas();

			if ( isset( $cuentas[ $cuenta ] ) ) {
				Malka_CRM_Finance::add(
					array(
						'descripcion' => 'debo' === $lado
							? sprintf( /* translators: quién */ __( '%s te prestó', 'malka-crm' ), $quien )
							: sprintf( /* translators: a quién */ __( 'Le prestaste a %s', 'malka-crm' ), $quien ),
						'monto'       => $monto,
						// Prestar saca plata; que te presten la trae.
						'tipo'        => 'debo' === $lado ? 'entrada' : 'salida',
						'categoria'   => 'prestamo',
						'fecha'       => $fecha,
						'cuenta'      => $cuenta,
						'referencia'  => 'presto:' . $id,
					)
				);
			}
		}

		return $id;
	}

	/**
	 * Anota que te devolvieron una parte, o todo.
	 *
	 * @param int    $id     Préstamo.
	 * @param int    $monto  Cuánto te devolvieron.
	 * @param int    $cuenta A qué cuenta entró. 0 para no tocar ninguna.
	 * @param string $fecha  Y-m-d.
	 * @return bool
	 */
	public static function abonar( $id, $monto, $cuenta = 0, $fecha = '' ) {
		$todos = self::todos();
		$id    = (int) $id;
		$monto = (int) $monto;

		if ( ! isset( $todos[ $id ] ) || $monto <= 0 ) {
			return false;
		}

		$fecha  = self::fecha_valida( $fecha );
		$deudor = isset( $todos[ $id ]['lado'] ) && 'debo' === $todos[ $id ]['lado'];

		// No se puede devolver más de lo que falta.
		$falta = max( 0, (int) $todos[ $id ]['monto'] - (int) $todos[ $id ]['devuelto'] );
		$monto = min( $monto, $falta );

		if ( $monto <= 0 ) {
			return false;
		}

		$todos[ $id ]['devuelto'] = (int) $todos[ $id ]['devuelto'] + $monto;
		update_option( self::OPT, $todos, false );

		$cuenta = (int) $cuenta;

		if ( $cuenta && class_exists( 'Malka_CRM_Finance' ) ) {
			$cuentas = Malka_CRM_Finance::cuentas();

			if ( isset( $cuentas[ $cuenta ] ) ) {
				Malka_CRM_Finance::add(
					array(
						'descripcion' => $deudor
							? sprintf( /* translators: a quién */ __( 'Le devolviste a %s', 'malka-crm' ), $todos[ $id ]['quien'] )
							: sprintf( /* translators: quién */ __( '%s te devolvió', 'malka-crm' ), $todos[ $id ]['quien'] ),
						'monto'       => $monto,
						// Si debías, pagar saca plata; si te debían, la trae.
						'tipo'        => $deudor ? 'salida' : 'entrada',
						'categoria'   => 'prestamo',
						'fecha'       => $fecha,
						'cuenta'      => $cuenta,
						'referencia'  => 'presto:' . $id,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Una fecha de hoy o de antes. El futuro no se anota.
	 *
	 * @param string $f Fecha.
	 * @return string
	 */
	private static function fecha_valida( $f ) {
		$hoy = current_time( 'Y-m-d' );
		$f   = $f && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f ) ? (string) $f : $hoy;

		return $f > $hoy ? $hoy : $f;
	}

	/* ─────────────────────────────  Handlers  ────────────────────────────── */

	private static function puede() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}
	}

	private static function volver( $q ) {
		wp_safe_redirect( add_query_arg( 'presto', $q, Malka_CRM_Dashboard::view_url( 'deudas' ) ) );
		exit;
	}

	private static function pesos( $v ) {
		return (int) preg_replace( '/[^\d]/', '', (string) wp_unslash( $v ) );
	}

	public static function handle_guardar() {
		check_admin_referer( 'malka_crm_prestamo_guardar' );
		self::puede();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$quien  = isset( $_POST['quien'] ) ? wp_unslash( $_POST['quien'] ) : '';
		$monto  = isset( $_POST['monto'] ) ? self::pesos( $_POST['monto'] ) : 0;
		$cuenta = isset( $_POST['cuenta'] ) ? (int) $_POST['cuenta'] : 0;
		$fecha  = isset( $_POST['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fecha'] ) ) : '';
		$vence  = isset( $_POST['vence'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['vence'] ) ) : '';
		$nota   = isset( $_POST['nota'] ) ? wp_unslash( $_POST['nota'] ) : '';
		$lado   = isset( $_POST['lado'] ) ? sanitize_key( wp_unslash( $_POST['lado'] ) ) : 'me_deben';
		$cuotas = isset( $_POST['cuotas'] ) ? (int) $_POST['cuotas'] : 0;
		$dia    = isset( $_POST['dia_pago'] ) ? (int) $_POST['dia_pago'] : 0;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id > 0 ) {
			/*
			 * Corregir una ficha que ya existe. No pasa por prestar() a
			 * propósito: esa anota además el movimiento en la cuenta, y la
			 * plata se movió una sola vez. Cambiar el nombre o la fecha de
			 * un préstamo no es un préstamo nuevo.
			 */
			$id = self::guardar(
				array(
					'id'       => $id,
					'quien'    => $quien,
					'monto'    => $monto,
					'fecha'    => $fecha,
					'vence'    => $vence,
					'nota'     => $nota,
					'lado'     => $lado,
					'cuotas'   => $cuotas,
					'dia_pago' => $dia,
				)
			);

			self::volver( $id ? 'editado' : 'falta' );
			return;
		}

		$id = self::prestar( $quien, $monto, $cuenta, $fecha, $vence, $nota, $lado, $cuotas, $dia );

		self::volver( $id ? 'ok' : 'falta' );
	}

	public static function handle_abonar() {
		check_admin_referer( 'malka_crm_prestamo_abonar' );
		self::puede();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$monto  = isset( $_POST['monto'] ) ? self::pesos( $_POST['monto'] ) : 0;
		$cuenta = isset( $_POST['cuenta'] ) ? (int) $_POST['cuenta'] : 0;
		$fecha  = isset( $_POST['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fecha'] ) ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$ok = self::abonar( $id, $monto, $cuenta, $fecha );

		self::volver( $ok ? 'abono' : 'falta' );
	}

	public static function handle_borrar() {
		check_admin_referer( 'malka_crm_prestamo_borrar' );
		self::puede();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		self::borrar( $id );
		self::volver( 'borrado' );
	}

	/* ─────────────────────────────  Pantalla  ────────────────────────────── */

	/**
	 * Las palabras de cada lado. Es la misma ficha con el signo al revés, así
	 * que lo único que cambia de verdad son los textos.
	 *
	 * @param string $lado me_deben|debo.
	 * @return array
	 */
	private static function textos( $lado ) {
		if ( 'debo' === $lado ) {
			return array(
				'titulo'  => __( 'Lo que debes a personas', 'malka-crm' ),
				'bajada'  => __( 'Plata que te prestaron, fuera del banco', 'malka-crm' ),
				'cuantos' => array( 'a %d persona', 'a %d personas' ),
				'vacio'   => __( 'No tienes deudas con personas anotadas. Si alguien te prestó plata, anótalo acá: entra a tu cuenta pero no es un ingreso, así que no te infla las ventas del estudio.', 'malka-crm' ),
				'nuevo'   => __( 'Anotar que me prestaron', 'malka-crm' ),
				'quien'   => __( 'Quién te prestó', 'malka-crm' ),
				'cuenta'  => __( 'A qué cuenta entró', 'malka-crm' ),
				'cuando'  => __( 'Cuándo te la prestaron', 'malka-crm' ),
				'plazo'   => __( 'Cuándo se la devuelves (opcional)', 'malka-crm' ),
				'guardar' => __( 'Anotar la deuda', 'malka-crm' ),
				'pie'     => __( 'Sube el saldo de la cuenta porque esa plata te llegó, pero no cuenta como ingreso: la tienes que devolver.', 'malka-crm' ),
				'abonar'  => __( 'Anotar que le devolví', 'malka-crm' ),
				'cuanto'  => __( 'Cuánto le devolviste', 'malka-crm' ),
				'desde'   => __( 'De qué cuenta salió', 'malka-crm' ),
				'listo'   => __( 'Pagada', 'malka-crm' ),
				'parcial' => __( 'le devolviste %1$s de %2$s', 'malka-crm' ),
				'fecha'   => __( 'te prestó el %s', 'malka-crm' ),
				'resumen' => __( 'En total te prestaron %1$s y has devuelto %2$s.', 'malka-crm' ),
				'icono'   => 'peso',
			);
		}

		return array(
			'titulo'  => __( 'Lo que te deben', 'malka-crm' ),
			'bajada'  => __( 'La plata que prestaste y todavía no vuelve', 'malka-crm' ),
			'cuantos' => array( 'a %d persona', 'a %d personas' ),
			'vacio'   => __( 'Nadie te debe nada anotado. Cuando le prestes a alguien, anótalo acá: sale de tu cuenta pero no es un gasto, así que no te ensucia los costos del estudio.', 'malka-crm' ),
			'nuevo'   => __( 'Prestarle a alguien', 'malka-crm' ),
			'quien'   => __( 'A quién le prestaste', 'malka-crm' ),
			'cuenta'  => __( 'De qué cuenta salió', 'malka-crm' ),
			'cuando'  => __( 'Cuándo se la prestaste', 'malka-crm' ),
			'plazo'   => __( 'Cuándo te la devuelve (opcional)', 'malka-crm' ),
			'guardar' => __( 'Anotar el préstamo', 'malka-crm' ),
			'pie'     => __( 'Baja el saldo de la cuenta porque esa plata hoy no la tienes, pero no cuenta como gasto ni como costo del estudio: sigue siendo tuya.', 'malka-crm' ),
			'abonar'  => __( 'Anotar que te devolvió', 'malka-crm' ),
			'cuanto'  => __( 'Cuánto te devolvió', 'malka-crm' ),
			'desde'   => __( 'A qué cuenta entró', 'malka-crm' ),
			'listo'   => __( 'Pagado', 'malka-crm' ),
			'parcial' => __( 'te devolvió %1$s de %2$s', 'malka-crm' ),
			'fecha'   => __( 'prestado el %s', 'malka-crm' ),
			'resumen' => __( 'En total prestaste %1$s y te han devuelto %2$s.', 'malka-crm' ),
			'icono'   => 'clientes',
		);
	}

	/**
	 * Los dos paneles: lo que te deben y lo que debes.
	 *
	 * Van aparte de las cuentas del estudio y de las tuyas a propósito: una
	 * deuda con una persona no es una cuenta de banco ni un costo, y mezclarla
	 * con cualquiera de las dos haría mentir a los dos totales.
	 *
	 * @return string
	 */
	public static function render() {
		/*
		 * Los dos juntos y en paralelo: cada uno solo tiene pocas filas y a
		 * ancho completo quedaban dos bloques enormes casi vacíos.
		 */
		return '<div class="esc-presto__par">' . self::panel( 'me_deben' ) . self::panel( 'debo' ) . '</div>';
	}

	/**
	 * Un lado.
	 *
	 * @param string $lado me_deben|debo.
	 * @return string
	 */
	public static function panel( $lado ) {
		$lista = self::lista( $lado );
		$t     = self::totales( $lista );
		$x     = self::textos( $lado );

		ob_start();
		printf( '<section class="esc-panel esc-presto" id="esc-presto-%s">', esc_attr( $lado ) );
		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$x['icono'],
			$x['titulo'],
			$x['bajada'],
			$t['pendiente'] ? Malka_CRM_Settings::money( $t['pendiente'] ) : '',
			$t['cuantos'] ? sprintf( /* translators: cuántos */ _n( $x['cuantos'][0], $x['cuantos'][1], $t['cuantos'], 'malka-crm' ), $t['cuantos'] ) : ''
		);

		echo self::mensaje(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! $lista ) {
			echo '<p class="esc-empty">' . esc_html( $x['vacio'] ) . '</p>';
		} else {
			echo '<ul class="esc-deudas__l">';

			foreach ( $lista as $p ) {
				printf( '<li class="esc-deuda%s">', $p['listo'] ? ' is-listo' : '' );

				$meta = array();

				if ( $p['fecha'] ) {
					$meta[] = sprintf( $x['fecha'], date_i18n( 'j M Y', strtotime( $p['fecha'] ) ) );
				}

				if ( $p['devuelto'] > 0 && ! $p['listo'] ) {
					$meta[] = sprintf(
						$x['parcial'],
						Malka_CRM_Settings::money( $p['devuelto'] ),
						Malka_CRM_Settings::money( $p['monto'] )
					);
				}

				if ( $p['cuotas'] > 0 ) {
					$meta[] = sprintf(
						/* translators: 1: cuál, 2: de cuántas, 3: monto */
						__( 'cuota %1$d de %2$d · %3$s', 'malka-crm' ),
						min( $p['cuotas'], $p['pagadas'] + 1 ),
						$p['cuotas'],
						Malka_CRM_Settings::money( $p['cuota'] )
					);
				}

				if ( $p['nota'] ) {
					$meta[] = $p['nota'];
				}

				$vence = '';

				if ( $p['proxima'] && ! $p['listo'] ) {
					list( $cuando, $tono ) = Malka_CRM_Pagos::cuando( (int) $p['dias'] );
					$vence = sprintf(
						'<span class="esc-deuda__v">%1$s <span class="esc-st esc-st--%2$s">%3$s</span></span>',
						esc_html( date_i18n( 'j M', strtotime( $p['proxima'] ) ) ),
						esc_attr( $tono ),
						esc_html( $cuando )
					);
				}

				/*
				 * La fila entera abre la ficha. Antes cada préstamo llevaba
				 * tres botones apilados debajo —abonar, corregir, anotar uno
				 * nuevo— y la lista era una columna de botones con los datos
				 * perdidos en medio.
				 */
				printf(
					'<button type="button" class="esc-deuda__a" data-presto-ver="%4$d">' .
						'<span class="esc-deuda__h"><span class="esc-deuda__t"><b>%1$s</b><em>%2$s</em></span>' .
						'<b class="esc-deuda__m">%3$s</b></span>%5$s</button>',
					esc_html( $p['quien'] ),
					esc_html( implode( ' · ', $meta ) ),
					esc_html( $p['listo'] ? $x['listo'] : Malka_CRM_Settings::money( $p['pendiente'] ) ),
					(int) $p['id'],
					$vence // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);

				echo self::modal_prestamo( $p, $x ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				echo '</li>';
			}

			echo '</ul>';

			if ( $t['devuelto'] > 0 ) {
				printf(
					'<p class="esc-hint">%s</p>',
					esc_html(
						sprintf(
							$x['resumen'],
							Malka_CRM_Settings::money( $t['prestado'] ),
							Malka_CRM_Settings::money( $t['devuelto'] )
						)
					)
				);
			}
		}

		echo self::form_nuevo( $lado, $x ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * El acuse de lo último que hiciste.
	 *
	 * @return string
	 */
	private static function mensaje() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q = isset( $_GET['presto'] ) ? sanitize_key( wp_unslash( $_GET['presto'] ) ) : '';

		$dichos = array(
			'ok'      => __( 'Anotado: la plata se movió y la ficha queda abierta.', 'malka-crm' ),
			'abono'   => __( 'Abono anotado: la cuenta se movió y baja lo que falta.', 'malka-crm' ),
			'borrado' => __( 'Borrado de la lista. Los movimientos de plata quedan: esa plata se movió de verdad.', 'malka-crm' ),
			'editado' => __( 'Ficha corregida. El movimiento de la cuenta no se tocó: si el monto estaba mal, arréglalo en Movimientos.', 'malka-crm' ),
			'falta'   => __( 'Faltó algún dato y no se guardó.', 'malka-crm' ),
		);

		if ( ! isset( $dichos[ $q ] ) ) {
			return '';
		}

		return sprintf(
			'<p class="esc-deudas__ok%1$s">%2$s</p>',
			'falta' === $q ? ' is-mal' : '',
			esc_html( $dichos[ $q ] )
		);
	}

	/**
	 * Las cuentas de donde puede salir o entrar la plata.
	 *
	 * @param bool $vacia Si ofrecer «ninguna».
	 * @return string
	 */
	private static function opciones_cuenta( $vacia = true ) {
		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return '';
		}

		$out = $vacia ? sprintf( '<option value="0">%s</option>', esc_html__( 'No mover ninguna cuenta', 'malka-crm' ) ) : '';

		foreach ( Malka_CRM_Finance::cuentas() as $id => $c ) {
			// Una tarjeta de crédito no presta plata: presta cupo.
			if ( Malka_CRM_Finance::es_credito( (int) $id ) ) {
				continue;
			}

			$out .= sprintf(
				'<option value="%1$d">%2$s</option>',
				(int) $id,
				esc_html( Malka_CRM_Finance::nombre_cuenta( (int) $id ) )
			);
		}

		return $out;
	}

	/**
	 * El formulario para saldar, total o en parte.
	 *
	 * @param array $p Ficha.
	 * @param array $x Textos del lado.
	 * @return string
	 */
	private static function form_abonar( $p, $x ) {
		if ( $p['listo'] ) {
			return self::form_borrar( $p['id'] );
		}

		$hoy = current_time( 'Y-m-d' );

		ob_start();
		printf( '<details class="esc-deuda__mas"><summary>%s</summary>', esc_html( $x['abonar'] ) );

		printf(
			'<form method="post" action="%1$s" class="esc-deuda__f">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_prestamo_abonar" />' .
			'<input type="hidden" name="id" value="%3$d" />' .
			'<label><span>%4$s</span><input type="text" name="monto" inputmode="numeric" value="%5$s" /></label>' .
			'<label><span>%6$s</span><select name="cuenta">%7$s</select></label>' .
			'<label><span>%8$s</span><input type="date" name="fecha" value="%9$s" max="%9$s" /></label>' .
			'<button type="submit" class="esc-btn esc-btn--primary">%10$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_prestamo_abonar', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(int) $p['id'],
			esc_html( $x['cuanto'] ),
			// Con cuotas acordadas, lo normal es abonar una, no el total.
			esc_attr( number_format( $p['cuota'] > 0 ? min( $p['cuota'], $p['pendiente'] ) : $p['pendiente'], 0, ',', '.' ) ),
			esc_html( $x['desde'] ),
			self::opciones_cuenta(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Cuándo', 'malka-crm' ),
			esc_attr( $hoy ),
			esc_html__( 'Anotar', 'malka-crm' )
		);

		echo self::form_borrar( $p['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</details>';

		return ob_get_clean();
	}

	/**
	 * Las cuotas de un préstamo, una por una.
	 *
	 * Cuántas van no se guarda aparte: sale de lo abonado, para que no
	 * haya dos números que se puedan contradecir. La última cuota se
	 * ajusta con lo que sobra de la división, o seis cuotas de $171.901
	 * sumarían más que los $1.031.406 que se prestaron.
	 *
	 * @param array $p Préstamo, como lo devuelve lista().
	 * @return array[] numero, fecha, monto, estado, toca.
	 */
	public static function cuotas_de( $p ) {
		$cuotas = (int) $p['cuotas'];

		if ( $cuotas < 1 ) {
			return array();
		}

		$dia  = (int) $p['dia_pago'];
		$hoy  = current_time( 'Y-m-d' );
		$out  = array();
		$toca = false;
		$suma = 0;

		for ( $i = 1; $i <= $cuotas; $i++ ) {
			// La última se lleva lo que falte para cuadrar con el total.
			$monto = ( $i === $cuotas ) ? max( 0, (int) $p['monto'] - $suma ) : (int) $p['cuota'];
			$suma += $monto;

			$fecha = '';

			if ( $dia >= 1 && $dia <= 31 && $p['fecha'] ) {
				/*
				 * La primera cuota cae en el día acordado del mes siguiente
				 * al préstamo, y de ahí una por mes. Un día 31 en un mes de
				 * 30 cae el 30: no se salta el mes.
				 */
				$base   = strtotime( substr( $p['fecha'], 0, 7 ) . '-01 +' . $i . ' month' );
				$ultimo = (int) gmdate( 't', $base );
				$fecha  = gmdate( 'Y-m', $base ) . '-' . str_pad( (string) min( $ultimo, $dia ), 2, '0', STR_PAD_LEFT );
			}

			if ( $i <= (int) $p['pagadas'] ) {
				$estado = 'pagada';
			} elseif ( $fecha && $fecha < $hoy ) {
				$estado = 'vencida';
			} else {
				$estado = 'futura';
			}

			$esta = ( ! $toca && 'pagada' !== $estado );

			if ( $esta ) {
				$toca = true;
			}

			$out[] = array(
				'numero' => $i,
				'fecha'  => $fecha,
				'monto'  => $monto,
				'estado' => $estado,
				'toca'   => $esta,
			);
		}

		return $out;
	}

	/**
	 * La ficha de un préstamo: sus cuotas, y lo que se puede hacer con él.
	 *
	 * @param array $p Préstamo.
	 * @param array $x Textos del lado.
	 * @return string
	 */
	private static function modal_prestamo( $p, $x ) {
		$cuotas = self::cuotas_de( $p );

		ob_start();
		printf( '<div class="esc-modal esc-presto__m" id="esc-presto-f-%1$d" data-presto-ficha="%1$d"><div class="esc-modal__c">', (int) $p['id'] );

		printf( '<h3 class="esc-modal__h">%s</h3>', esc_html( $p['quien'] ) );
		printf(
			'<p class="esc-modal__p">%s</p>',
			esc_html(
				$p['listo']
					? $x['listo']
					: sprintf(
						/* translators: 1: lo que falta, 2: el total */
						__( 'Faltan %1$s de %2$s', 'malka-crm' ),
						Malka_CRM_Settings::money( $p['pendiente'] ),
						Malka_CRM_Settings::money( $p['monto'] )
					)
			)
		);

		/*
		 * Dos columnas: las cuotas de un lado y el resto de la ficha del
		 * otro. Antes iba todo apilado y la ficha de un préstamo de seis
		 * cuotas medía más que la pantalla: los campos quedaban abajo, fuera
		 * de la vista, y había que bajar para encontrar «Cerrar».
		 *
		 * Sin cuotas la grilla queda en una sola columna, porque una columna
		 * vacía al lado de los campos no es una columna, es un hueco.
		 */
		printf( '<div class="esc-presto__dos%s">', $cuotas ? '' : ' is-sola' );

		if ( $cuotas ) {
			echo '<div class="esc-presto__col esc-presto__col--cuotas">';
			printf(
				'<div class="esc-susano__r"><span>%1$s</span><span>%2$s</span></div>',
				esc_html( sprintf( /* translators: 1: pagadas, 2: total */ __( '%1$d de %2$d cuotas', 'malka-crm' ), (int) $p['pagadas'], (int) $p['cuotas'] ) ),
				esc_html( sprintf( /* translators: monto */ __( '%s devueltos', 'malka-crm' ), Malka_CRM_Settings::money( $p['devuelto'] ) ) )
			);

			echo '<div class="esc-susano__l">';

			foreach ( $cuotas as $c ) {
				/*
				 * Una cuota que ya se pasó no es «después». Se marcan en
				 * orden —al marcar la que toca, la siguiente pasa a serlo—
				 * así que acá sólo se dice en qué está cada una.
				 */
				if ( 'pagada' === $c['estado'] ) {
					$dice = __( 'Pagada', 'malka-crm' );
				} elseif ( 'vencida' === $c['estado'] ) {
					$dice = __( 'Atrasada', 'malka-crm' );
				} else {
					$dice = __( 'Después', 'malka-crm' );
				}

				printf(
					'<div class="esc-sa__f is-%1$s%2$s">%5$s<span class="esc-sa__d">%3$s</span>' .
					'<span class="esc-sa__m">%4$s</span><span class="esc-sa__a">%6$s</span></div>',
					esc_attr( $c['estado'] ),
					$c['toca'] ? ' is-toca' : '',
					esc_html(
						$c['fecha']
							? sprintf( /* translators: 1: número, 2: fecha */ __( '%1$d · %2$s', 'malka-crm' ), $c['numero'], date_i18n( 'j M', strtotime( $c['fecha'] ) ) )
							: sprintf( /* translators: número */ __( 'Cuota %d', 'malka-crm' ), $c['numero'] )
					),
					esc_html( Malka_CRM_Settings::money( $c['monto'] ) ),
					self::circulo_cuota( $p, $c, $x ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $dice )
				);
			}

			echo '</div></div>';
		}

		echo '<div class="esc-presto__col esc-presto__col--datos">';
		echo self::campos( $p, $x ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="esc-presto__acc">';
		// Devolver un monto distinto al de una cuota sigue siendo posible.
		echo self::form_abonar( $p, $x ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::form_borrar( $p['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		// Cierra la columna de datos y la grilla de dos columnas.
		echo '</div></div>';

		printf( '<div class="esc-modal__f"><button type="button" class="esc-btn" data-cerrar-modal>%s</button></div>', esc_html__( 'Cerrar', 'malka-crm' ) );
		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * El círculo de cada cuota, a la izquierda, como en el resto del panel.
	 *
	 * El de la cuota que toca es el botón que la marca; va por el mismo
	 * guardado que todo lo demás —abonar el monto de la cuota— para que no
	 * haya dos caminos que anoten pagos. Las pagadas muestran su visto y
	 * las que no corresponden todavía, un círculo apagado: se marcan en
	 * orden, y dejar marcar la quinta con la tercera sin pagar haría un
	 * hueco que los totales no sabrían contar.
	 *
	 * @param array $p Préstamo.
	 * @param array $c La cuota.
	 * @param array $x Textos del lado.
	 * @return string
	 */
	private static function circulo_cuota( $p, $c, $x ) {
		if ( 'pagada' === $c['estado'] ) {
			return sprintf(
				'<span class="esc-dia__chk esc-sa__ck is-ok" role="img" aria-label="%s"></span>',
				esc_attr__( 'Pagada', 'malka-crm' )
			);
		}

		if ( ! $c['toca'] ) {
			return sprintf(
				'<span class="esc-dia__chk esc-sa__ck is-off" role="img" aria-label="%s"></span>',
				esc_attr__( 'Todavía no: antes va la cuota anterior', 'malka-crm' )
			);
		}

		return sprintf(
			'<form method="post" action="%1$s" class="esc-sa__fm">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_prestamo_abonar" />' .
			'<input type="hidden" name="id" value="%3$d" />' .
			'<input type="hidden" name="monto" value="%4$d" />' .
			'<input type="hidden" name="fecha" value="%5$s" />' .
			'<button type="submit" class="esc-dia__chk esc-sa__ck" aria-label="%6$s" title="%6$s"></button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_prestamo_abonar', '_wpnonce', true, false ),
			(int) $p['id'],
			(int) $c['monto'],
			esc_attr( current_time( 'Y-m-d' ) ),
			esc_attr(
				sprintf(
					/* translators: 1: número de cuota, 2: monto */
					'debo' === $p['lado'] ? __( 'Marcar pagada la cuota %1$d de %2$s', 'malka-crm' ) : __( 'Marcar cobrada la cuota %1$d de %2$s', 'malka-crm' ),
					$c['numero'],
					Malka_CRM_Settings::money( $c['monto'] )
				)
			)
		);
	}

	/**
	 * Guarda un solo campo de la ficha, apenas se cambia.
	 *
	 * Devuelve lo que quedó guardado de verdad, no lo que se mandó. Esa es
	 * la gracia: si el servidor normaliza o rechaza un valor, el campo se
	 * corrige solo en pantalla en vez de quedarse mostrando algo que no
	 * está grabado. El «se guardó» y lo que hay en la base no se pueden
	 * separar, que era justo el problema del formulario con botón.
	 */
	public static function ajax_campo() {
		check_ajax_referer( 'malka_crm_finance', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}

		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$campo = isset( $_POST['campo'] ) ? sanitize_key( wp_unslash( $_POST['campo'] ) ) : '';
		$valor = isset( $_POST['valor'] ) ? wp_unslash( $_POST['valor'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$todos = self::todos();

		if ( ! $id || ! isset( $todos[ $id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Esa ficha ya no está.', 'malka-crm' ) ), 404 );
		}

		if ( ! in_array( $campo, array( 'quien', 'monto', 'fecha', 'vence', 'cuotas', 'dia_pago', 'nota' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Ese campo no se puede cambiar.', 'malka-crm' ) ), 400 );
		}

		if ( in_array( $campo, array( 'fecha', 'vence' ), true ) ) {
			$limpio = Malka_CRM_Helpers::sanitize_date( $valor );

			// Una fecha escrita a medias no se guarda como «sin fecha».
			if ( '' === $limpio && '' !== trim( (string) $valor ) ) {
				wp_send_json_error( array( 'message' => __( 'Esa fecha no se entiende.', 'malka-crm' ) ), 400 );
			}

			/*
			 * La fecha del préstamo no se puede borrar: sin ella no hay
			 * cuándo, y antes una fecha vacía se guardaba como hoy sin
			 * decirlo. Ahora se avisa en vez de cambiarla sola.
			 */
			if ( 'fecha' === $campo && '' === $limpio ) {
				wp_send_json_error( array( 'message' => __( 'El préstamo necesita una fecha.', 'malka-crm' ) ), 400 );
			}
		} elseif ( 'monto' === $campo ) {
			$limpio = self::pesos( $valor );

			if ( $limpio <= 0 ) {
				wp_send_json_error( array( 'message' => __( 'El monto tiene que ser mayor que cero.', 'malka-crm' ) ), 400 );
			}
		} elseif ( in_array( $campo, array( 'cuotas', 'dia_pago' ), true ) ) {
			$limpio = max( 0, (int) $valor );
		} else {
			$limpio = sanitize_text_field( (string) $valor );

			if ( 'quien' === $campo && '' === trim( $limpio ) ) {
				wp_send_json_error( array( 'message' => __( 'Falta de quién es.', 'malka-crm' ) ), 400 );
			}
		}

		$guardado = self::guardar( array( 'id' => $id, $campo => $limpio ) );

		if ( ! $guardado ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar.', 'malka-crm' ) ), 400 );
		}

		$ficha = self::todos()[ $id ];
		$crudo = isset( $ficha[ $campo ] ) ? $ficha[ $campo ] : '';

		wp_send_json_success(
			array(
				'campo' => $campo,
				// Lo que quedó, listo para volver a poner en el campo.
				'valor' => 'monto' === $campo ? number_format( (int) $crudo, 0, ',', '.' ) : (string) $crudo,
			)
		);
	}

	/**
	 * Los datos de la ficha, siempre a la vista y editables en el momento.
	 *
	 * Sin botón de corregir y sin botón de guardar: se toca el campo, se
	 * escribe, y al salir queda grabado. Un formulario que se desplegaba
	 * hacia abajo dejaba la mitad de los campos fuera de la pantalla, y un
	 * «Guardar» que avisa antes de que el servidor conteste es exactamente
	 * lo que hacía creer que algo se había guardado cuando no.
	 *
	 * @param array $p Préstamo.
	 * @param array $x Textos del lado.
	 * @return string
	 */
	private static function campos( $p, $x ) {
		/*
		 * Rótulos cortos, de una línea. Los largos del formulario de anotar
		 * («Cuándo te la devuelve (opcional)») se parten en dos renglones y
		 * dejan las dos columnas escalonadas.
		 */
		$deudor = ( 'debo' === $p['lado'] );

		$campos = array(
			array( 'quien', $deudor ? __( 'Quién', 'malka-crm' ) : __( 'A quién', 'malka-crm' ), 'text', '' ),
			array( 'monto', __( 'Cuánto', 'malka-crm' ), 'text', '' ),
			array( 'fecha', __( 'Cuándo fue', 'malka-crm' ), 'date', '' ),
			array( 'vence', $deudor ? __( 'Cuándo la devuelves', 'malka-crm' ) : __( 'Cuándo te la devuelven', 'malka-crm' ), 'date', '' ),
			array( 'cuotas', __( 'En cuántas cuotas', 'malka-crm' ), 'number', '0' ),
			array( 'dia_pago', __( 'Qué día del mes', 'malka-crm' ), 'number', '1' ),
			array( 'nota', __( 'Para qué era', 'malka-crm' ), 'text', '' ),
		);

		ob_start();
		printf(
			'<div class="esc-presto__c" data-presto-campos="%1$d" data-presto-ajax="%2$s" data-presto-nonce="%3$s">',
			(int) $p['id'],
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'malka_crm_finance' ) )
		);

		foreach ( $campos as $c ) {
			list( $k, $rotulo, $tipo, $min ) = $c;

			$valor = isset( $p[ $k ] ) ? $p[ $k ] : '';

			if ( 'monto' === $k ) {
				$valor = number_format( (int) $valor, 0, ',', '.' );
			} elseif ( in_array( $k, array( 'cuotas', 'dia_pago' ), true ) ) {
				$valor = $valor ? (int) $valor : '';
			}

			printf(
				'<label class="esc-presto__k%5$s"><span>%1$s</span>' .
				'<input type="%2$s" value="%3$s" data-pr-campo="%4$s"%6$s%7$s />' .
				'<i class="esc-presto__ok" aria-hidden="true"></i></label>',
				esc_html( $rotulo ),
				esc_attr( $tipo ),
				esc_attr( $valor ),
				esc_attr( $k ),
				'nota' === $k ? ' esc-presto__k--ancho' : '',
				'' !== $min ? ' min="' . esc_attr( $min ) . '"' : '',
				'dia_pago' === $k ? ' max="31"' : ( 'cuotas' === $k ? ' max="120"' : '' )
			);
		}

		printf( '<p class="esc-presto__aviso" role="status" data-presto-aviso>%s</p>', esc_html__( 'Se guarda solo al salir de cada campo.', 'malka-crm' ) );
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * El botón de borrar.
	 *
	 * @param int $id Id.
	 * @return string
	 */
	private static function form_borrar( $id ) {
		return sprintf(
			'<form method="post" action="%1$s" class="esc-deuda__borrar">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_prestamo_borrar" />' .
			'<input type="hidden" name="id" value="%3$d" />' .
			'<button type="submit" class="esc-btn esc-btn--ghost">%4$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_prestamo_borrar', '_wpnonce', true, false ),
			(int) $id,
			esc_html__( 'Borrar de la lista', 'malka-crm' )
		);
	}

	/**
	 * El formulario para anotar una ficha nueva.
	 *
	 * @param string $lado me_deben|debo.
	 * @param array  $x    Textos del lado.
	 * @return string
	 */
	private static function form_nuevo( $lado, $x ) {
		$hoy = current_time( 'Y-m-d' );

		ob_start();
		printf( '<details class="esc-deuda__mas"><summary>%s</summary>', esc_html( $x['nuevo'] ) );

		printf(
			'<form method="post" action="%1$s" class="esc-deuda__f esc-deuda__f--nueva">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_prestamo_guardar" />' .
			'<input type="hidden" name="lado" value="%13$s" />' .
			'<label><span>%3$s</span><input type="text" name="quien" required /></label>' .
			'<label><span>%4$s</span><input type="text" name="monto" inputmode="numeric" required /></label>' .
			'<label><span>%5$s</span><select name="cuenta">%6$s</select></label>' .
			'<label><span>%7$s</span><input type="date" name="fecha" value="%8$s" max="%8$s" /></label>' .
			'<label><span>%9$s</span><input type="date" name="vence" /></label>' .
			'<label><span>%14$s</span><input type="number" name="cuotas" min="0" max="120" placeholder="0" /></label>' .
			'<label><span>%15$s</span><input type="number" name="dia_pago" min="1" max="31" /></label>' .
			'<label><span>%10$s</span><input type="text" name="nota" /></label>' .
			'<button type="submit" class="esc-btn esc-btn--primary">%11$s</button></form>' .
			'<p class="esc-hint">%12$s</p>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_prestamo_guardar', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $x['quien'] ),
			esc_html__( 'Cuánto', 'malka-crm' ),
			esc_html( $x['cuenta'] ),
			self::opciones_cuenta(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $x['cuando'] ),
			esc_attr( $hoy ),
			esc_html( $x['plazo'] ),
			esc_html__( 'Para qué era (opcional)', 'malka-crm' ),
			esc_html( $x['guardar'] ),
			esc_html( $x['pie'] ),
			esc_attr( $lado ),
			esc_html__( 'En cuántas cuotas (opcional)', 'malka-crm' ),
			esc_html__( 'Qué día de cada mes', 'malka-crm' )
		);

		echo '</details>';

		return ob_get_clean();
	}
}
