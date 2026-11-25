<?php
/**
 * Los pagos del estudio: costos fijos del mes (arriendo, software, la
 * contadora), los que van en otra moneda y varían, y los impuestos (IVA
 * mensual en el F29 y la renta anual en abril).
 *
 * No es un registro aparte: los costos salen de Configuración → Costos fijos,
 * y el IVA, de lo anotado en Documentos. Acá sólo se sabe cuál toca este mes,
 * cuál ya se pagó y cuánto fue de verdad, para que aparezcan en «Tu día» y en
 * la campana hasta que se marquen pagados.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pagos del estudio.
 */
class Malka_CRM_Pagos {

	/** Dónde queda lo ya pagado: [ 'Y-m' => [ clave => [monto, fecha] ] ]. */
	const OPT = 'malka_crm_pagos_hechos';

	/** Cuántos días antes empieza a aparecer un pago. */
	const ANTES = 3;

	public function __construct() {
		add_action( 'wp_ajax_malka_crm_pago_hecho', array( __CLASS__, 'ajax_hecho' ) );
		add_action( 'malka_crm_daily', array( __CLASS__, 'recordar' ) );
	}

	/**
	 * Todo lo que se paga en un mes, con su fecha y si ya está pagado.
	 *
	 * @param string $mes Y-m.
	 * @return array[] clave, nombre, fecha, monto (estimado en pesos), moneda,
	 *                 original, variable, tipo, pagado, pagado_monto.
	 */
	public static function del_mes( $mes = '', $todo = false ) {
		$mes    = $mes ? $mes : current_time( 'Y-m' );
		$hechos = self::hechos( $mes );
		$ultimo = (int) gmdate( 't', strtotime( $mes . '-01' ) );
		$out    = array();

		/*
		 * Los costos fijos del estudio con día de pago. Lo personal se anota
		 * en la misma lista pero no es un pago del estudio: no entra acá, ni
		 * a la campana, ni al correo de la mañana.
		 *
		 * Con $todo sí entra: lo usa la pantalla de suscripciones, donde ella
		 * marca pagado lo suyo igual que lo del estudio. Se marca distinto
		 * con 'ambito' para que nadie los sume sin querer.
		 */
		$costos = $todo ? Malka_CRM_Settings::costs() : Malka_CRM_Settings::costs_estudio();

		foreach ( $costos as $c ) {
			if ( empty( $c['dia'] ) ) {
				continue;
			}
			// Lo que se paga una vez al año sólo toca en su mes.
			if ( 'anual' === $c['ciclo'] && (int) $c['mes'] !== (int) substr( $mes, 5, 2 ) ) {
				continue;
			}
			$clave = 'costo-' . sanitize_title( $c['nombre'] );
			$dia   = min( $ultimo, (int) $c['dia'] );

			$out[] = array(
				'clave'    => $clave,
				'id'       => isset( $c['id'] ) ? (int) $c['id'] : 0,
				'nombre'   => $c['nombre'],
				'cuenta'   => isset( $c['cuenta'] ) ? (int) $c['cuenta'] : 0,
				'ambito'   => isset( $c['ambito'] ) ? $c['ambito'] : 'estudio',
				'fecha'    => $mes . '-' . str_pad( (string) $dia, 2, '0', STR_PAD_LEFT ),
				'monto'    => (int) $c['clp'],
				'moneda'   => $c['moneda'],
				'original' => $c['monto'],
				// En otra moneda el monto cambia con el dólar: se pregunta.
				'variable' => ( 'CLP' !== $c['moneda'] ) || ! empty( $c['variable'] ),
				'tipo'     => 'costo',
				'pagado'   => isset( $hechos[ $clave ] ),
				'pagado_monto' => isset( $hechos[ $clave ] ) ? (int) $hechos[ $clave ]['monto'] : 0,
				// Con qué cuenta se pagó de verdad, y qué día.
				'pagado_cuenta' => isset( $hechos[ $clave ]['cuenta'] ) ? (int) $hechos[ $clave ]['cuenta'] : 0,
				'pagado_fecha'  => isset( $hechos[ $clave ]['fecha'] ) ? (string) $hechos[ $clave ]['fecha'] : '',
			);
		}

		/* El IVA del mes anterior, que se paga el 20 en el F29. */
		if ( class_exists( 'Malka_CRM_Documents' ) ) {
			$prev = gmdate( 'Y-m', strtotime( $mes . '-01 -1 month' ) );
			// El mismo total que Contabilidad: IVA, retenciones y PPM (o el F29 importado).
			if ( class_exists( 'Malka_CRM_Contabilidad' ) ) {
				$f   = Malka_CRM_Contabilidad::mes( $prev );
				$iva = max( 0, (int) $f['total'] );
			} else {
				$r   = Malka_CRM_Documents::resumen_mes( $prev );
				$iva = max( 0, (int) $r['iva_pagar'] );
			}
			$dia  = class_exists( 'Malka_CRM_Retiro' ) ? Malka_CRM_Retiro::DIA_F29 : 20;

			$out[] = array(
				'clave'    => 'f29',
				'nombre'   => sprintf( /* translators: mes */ __( 'IVA de %s (F29)', 'malka-crm' ), date_i18n( 'F', strtotime( $prev . '-01' ) ) ),
				'fecha'    => $mes . '-' . str_pad( (string) $dia, 2, '0', STR_PAD_LEFT ),
				'monto'    => $iva,
				'moneda'   => 'CLP',
				'original' => $iva,
				// Se declara aunque dé cero, y el monto final lo dice el SII.
				'variable' => true,
				'tipo'     => 'impuesto',
				'pagado'   => isset( $hechos['f29'] ),
				'pagado_monto' => isset( $hechos['f29'] ) ? (int) $hechos['f29']['monto'] : 0,
			);
		}

		/* La renta, una vez al año: el F22 vence el 30 de abril. */
		if ( '04' === substr( $mes, 5, 2 ) ) {
			$out[] = array(
				'clave'    => 'f22',
				'nombre'   => __( 'Declaración de renta (F22)', 'malka-crm' ),
				'fecha'    => $mes . '-30',
				'monto'    => 0,
				'moneda'   => 'CLP',
				'original' => 0,
				'variable' => true,
				'tipo'     => 'impuesto',
				'pagado'   => isset( $hechos['f22'] ),
				'pagado_monto' => isset( $hechos['f22'] ) ? (int) $hechos['f22']['monto'] : 0,
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['fecha'], $b['fecha'] );
			}
		);

		return $out;
	}

	/**
	 * Los pendientes que ya tocan: atrasados de este mes y los que vencen en
	 * los próximos días (también los primeros del mes que viene).
	 *
	 * @param int $antes Días de anticipación.
	 * @return array[] Con 'dias' (negativo si está atrasado).
	 */
	public static function proximos( $antes = self::ANTES ) {
		$hoy  = current_time( 'Y-m-d' );
		$tope = gmdate( 'Y-m-d', strtotime( $hoy . ' +' . (int) $antes . ' days' ) );
		$out  = array();

		/*
		 * Lo que venció antes de que existiera esto no se cuenta como
		 * atrasado: el primer día no puede amanecer con todo el mes en rojo.
		 */
		$desde = get_option( 'malka_crm_pagos_desde' );
		if ( ! $desde ) {
			$desde = $hoy;
			add_option( 'malka_crm_pagos_desde', $desde, '', false );
		}

		$meses = array_unique( array( current_time( 'Y-m' ), substr( $tope, 0, 7 ) ) );

		foreach ( $meses as $mes ) {
			foreach ( self::del_mes( $mes ) as $p ) {
				if ( $p['pagado'] || $p['fecha'] > $tope || $p['fecha'] < $desde ) {
					continue;
				}
				$p['mes']  = $mes;
				$p['dias'] = (int) floor( ( strtotime( $p['fecha'] ) - strtotime( $hoy ) ) / DAY_IN_SECONDS );
				$out[]     = $p;
			}
		}

		return $out;
	}

	/**
	 * En cuál de los tres montones cae un pago: atrasado, por pagar o pagado.
	 *
	 * Lo que vencía antes de que ella empezara a marcar pagos acá no es un
	 * atraso —lo más probable es que se pagara y nadie lo anotara—, así que
	 * cae en «por pagar» y no en rojo.
	 *
	 * @param array  $p     Una fila de del_mes().
	 * @param string $hoy   Y-m-d; por omisión, hoy.
	 * @param string $desde Y-m-d desde cuándo se marcan pagos.
	 * @return string atrasado|pendiente|pagado
	 */
	public static function estado_de( $p, $hoy = '', $desde = '' ) {
		if ( ! empty( $p['pagado'] ) ) {
			return 'pagado';
		}

		$hoy   = '' !== $hoy ? $hoy : current_time( 'Y-m-d' );
		$desde = '' !== $desde ? $desde : (string) get_option( 'malka_crm_pagos_desde', $hoy );
		$fecha = isset( $p['fecha'] ) ? (string) $p['fecha'] : $hoy;

		if ( $fecha < $desde ) {
			return 'pendiente';
		}

		return $fecha < $hoy ? 'atrasado' : 'pendiente';
	}

	/**
	 * Cuántos y cuánto hay en cada montón del mes.
	 *
	 * La pantalla de Pagos y la respuesta del marcado salen de acá, para que
	 * los dos digan lo mismo: si el número se calculara dos veces, una de
	 * las dos se iba a quedar vieja.
	 *
	 * @param string $mes  Y-m.
	 * @param bool   $todo Incluir también lo personal.
	 * @return array
	 */
	public static function resumen( $mes = '', $todo = false ) {
		$mes   = $mes ? $mes : current_time( 'Y-m' );
		$hoy   = current_time( 'Y-m-d' );
		$desde = (string) get_option( 'malka_crm_pagos_desde', $hoy );
		$out   = array(
			'atrasado'  => array( 'n' => 0, 'monto' => 0 ),
			'pendiente' => array( 'n' => 0, 'monto' => 0 ),
			'pagado'    => array( 'n' => 0, 'monto' => 0 ),
		);

		foreach ( self::del_mes( $mes, $todo ) as $p ) {
			$e = self::estado_de( $p, $hoy, $desde );
			$out[ $e ]['n']++;
			$out[ $e ]['monto'] += 'pagado' === $e ? (int) $p['pagado_monto'] : (int) $p['monto'];
		}

		$out['total'] = $out['atrasado']['monto'] + $out['pendiente']['monto'] + $out['pagado']['monto'];

		/*
		 * El texto del monto se arma acá y no en cada pantalla, para que la
		 * página y la respuesta del marcado digan lo mismo. Un montón con
		 * filas pero sin monto conocido —el F29, que no tiene cifra hasta
		 * que ella declara— va con una raya: un «$0» al lado de «ATRASADOS
		 * 1» dice que no debes nada, que es justo lo contrario.
		 */
		foreach ( array( 'atrasado', 'pendiente', 'pagado' ) as $e ) {
			$out[ $e ]['txt'] = ( $out[ $e ]['n'] && $out[ $e ]['monto'] <= 0 )
				? '—'
				: Malka_CRM_Settings::money( $out[ $e ]['monto'] );
		}

		$out['total_txt'] = Malka_CRM_Settings::money( $out['total'] );

		return $out;
	}

	/**
	 * Cómo se dice cuándo vence.
	 *
	 * @param int $dias Días que faltan (negativo: atrasado).
	 * @return array{0:string,1:string} Texto y tono.
	 */
	public static function cuando( $dias ) {
		if ( $dias < 0 ) {
			return array( sprintf( /* translators: días */ _n( 'Atrasado %d día', 'Atrasado %d días', -$dias, 'malka-crm' ), -$dias ), 'late' );
		}
		if ( 0 === $dias ) {
			return array( __( 'Vence hoy', 'malka-crm' ), 'late' );
		}
		if ( 1 === $dias ) {
			return array( __( 'Vence mañana', 'malka-crm' ), 'wait' );
		}
		return array( sprintf( /* translators: días */ __( 'En %d días', 'malka-crm' ), $dias ), 'wait' );
	}

	/**
	 * Lo ya pagado de un mes.
	 *
	 * @param string $mes Y-m.
	 * @return array
	 */
	public static function hechos( $mes ) {
		$todo = get_option( self::OPT, array() );
		return ( is_array( $todo ) && isset( $todo[ $mes ] ) && is_array( $todo[ $mes ] ) ) ? $todo[ $mes ] : array();
	}

	/**
	 * Le cambia la clave a un pago ya hecho, en todos los meses.
	 *
	 * La clave de un costo sale de su nombre. Al renombrarlo, lo que estaba
	 * marcado como pagado quedaba colgando de una clave que ya no existe:
	 * el costo volvía a aparecer impago y el pago, perdido. Esto lo arrastra.
	 *
	 * @param string $vieja Clave anterior.
	 * @param string $nueva Clave nueva.
	 * @return int Cuántos meses se arreglaron.
	 */
	public static function renombrar( $vieja, $nueva ) {
		if ( '' === $vieja || '' === $nueva || $vieja === $nueva ) {
			return 0;
		}

		$todo = get_option( self::OPT, array() );

		if ( ! is_array( $todo ) ) {
			return 0;
		}

		$tocados = 0;

		foreach ( $todo as $mes => $pagos ) {
			if ( ! is_array( $pagos ) || ! isset( $pagos[ $vieja ] ) ) {
				continue;
			}

			// Si ya hay algo con la clave nueva, se respeta: es más reciente.
			if ( ! isset( $pagos[ $nueva ] ) ) {
				$todo[ $mes ][ $nueva ] = $pagos[ $vieja ];
				$tocados++;
			}

			unset( $todo[ $mes ][ $vieja ] );
		}

		if ( $tocados ) {
			update_option( self::OPT, $todo, false );
		}

		return $tocados;
	}

	/**
	 * Lo marca pagado y lo anota como salida en Movimientos.
	 *
	 * @param string $mes   Y-m.
	 * @param string $clave Clave del pago.
	 * @param int    $monto Lo que de verdad se pagó, en pesos.
	 * @param string $fecha Cuándo se pagó (Y-m-d). Vacío: el día que vencía.
	 * @return bool
	 */
	public static function marcar( $mes, $clave, $monto, $fecha = '', $cuenta = null ) {
		$pago = null;

		// Con true entran también los cobros personales: ella marca los
		// suyos desde la pantalla de suscripciones.
		foreach ( self::del_mes( $mes, true ) as $p ) {
			if ( $p['clave'] === $clave ) {
				$pago = $p;
				break;
			}
		}
		if ( ! $pago || $pago['pagado'] ) {
			return false;
		}

		$todo = get_option( self::OPT, array() );
		$todo = is_array( $todo ) ? $todo : array();

		/*
		 * La fecha que importa es la del pago, no la del día en que se marca.
		 * Un cobro mensual que salió el 1 y se marca el 20 tiene que quedar
		 * anotado el 1: si no, el mes se descuadra y —peor— el saldo baja
		 * hoy por una plata que el banco ya descontó hace tres semanas.
		 *
		 * Por defecto, el día en que vencía.
		 */
		$cuando = $fecha && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $fecha )
			? (string) $fecha
			: (string) $pago['fecha'];

		/*
		 * De qué cuenta salió. La que está configurada en el cobro es la
		 * costumbre, no la verdad: un mes Adobe puede salir de otra tarjeta.
		 * Si al marcar se dice una, manda ésa, y queda guardada.
		 */
		$cuenta = null === $cuenta
			? ( isset( $pago['cuenta'] ) ? (int) $pago['cuenta'] : 0 )
			: max( 0, (int) $cuenta );

		// Nunca en el futuro: no se puede haber pagado algo que no ha pasado.
		$hoy = current_time( 'Y-m-d' );
		if ( $cuando > $hoy ) {
			$cuando = $hoy;
		}

		$todo[ $mes ][ $clave ] = array(
			'monto'  => max( 0, (int) $monto ),
			'fecha'  => $cuando,
			'cuenta' => $cuenta,
		);

		// Sólo se guardan los últimos 24 meses.
		ksort( $todo );
		$todo = array_slice( $todo, -24, null, true );
		update_option( self::OPT, $todo, false );

		// Queda en Movimientos, y con eso el saldo y el retiro se ajustan solos.
		if ( $monto > 0 && class_exists( 'Malka_CRM_Finance' ) ) {
			$mov = Malka_CRM_Finance::add(
				array(
					'descripcion' => $pago['nombre'],
					'monto'       => (int) $monto,
					'tipo'        => 'salida',
					/*
					 * Un cobro tuyo pagado con tu tarjeta es un gasto tuyo,
					 * no un costo del estudio. Si fuera «costo fijo», el
					 * estudio quedaría debiéndote el Netflix: justo la
					 * mezcla de platas que esto tiene que evitar.
					 */
					'categoria'   => self::categoria_de( $pago ),
					'recurrente'  => 'costo' === $pago['tipo'] ? 1 : 0,
					'automatico'  => 1,
					'fecha'       => $cuando,
					// De dónde salió de verdad. Si es una cuenta tuya, el
					// saldo del estudio no se toca: lo pagaste tú.
					'cuenta'      => (int) $cuenta,
					/*
					 * Un pago anterior al último cuadre con el banco ya está
					 * descontado del saldo que ella copió de la cartola:
					 * queda en Movimientos para el costo del mes, pero no
					 * vuelve a bajar la cuenta.
					 */
					'sin_saldo'   => Malka_CRM_Finance::entra_al_saldo( $cuando, $cuenta ) ? 0 : 1,
				)
			);

			// Se recuerda qué movimiento salió de este pago, y si ya había una
			// factura asociada al pago, queda enlazada también al movimiento.
			if ( is_int( $mov ) && $mov > 0 ) {
				$todo[ $mes ][ $clave ]['mov'] = $mov;
				update_option( self::OPT, $todo, false );
				if ( class_exists( 'Malka_CRM_Documents' ) ) {
					Malka_CRM_Documents::al_pagar( $mes . '|' . $clave, $mov );
				}
			}
		}

		return true;
	}

	/**
	 * En qué categoría entra este pago.
	 *
	 * Lo del estudio es un costo del estudio —fijo si el monto es siempre
	 * el mismo, variable si cambia— y un impuesto es un impuesto. Lo tuyo
	 * es un gasto tuyo, y eso importa: de la categoría depende si el gasto
	 * entra al costo del estudio y si el estudio te queda debiendo la plata
	 * cuando la pusiste tú.
	 *
	 * @param array $pago Pago.
	 * @return string
	 */
	public static function categoria_de( $pago ) {
		if ( isset( $pago['tipo'] ) && 'impuesto' === $pago['tipo'] ) {
			return 'impuesto';
		}

		if ( isset( $pago['ambito'] ) && 'personal' === $pago['ambito'] ) {
			return 'gasto_personal';
		}

		return empty( $pago['variable'] ) ? 'costo_fijo' : 'costo_variable';
	}

	/**
	 * Deshace un pago marcado por error.
	 *
	 * Se lleva también el movimiento que creó: dejarlo sería cobrarse dos
	 * veces la misma plata en el saldo y en el costo del mes.
	 *
	 * @param string $mes   Y-m.
	 * @param string $clave Clave del pago.
	 * @return bool
	 */
	public static function desmarcar( $mes, $clave ) {
		$todo = get_option( self::OPT, array() );

		if ( ! is_array( $todo ) || ! isset( $todo[ $mes ][ $clave ] ) ) {
			return false;
		}

		$mov = isset( $todo[ $mes ][ $clave ]['mov'] ) ? (int) $todo[ $mes ][ $clave ]['mov'] : 0;

		unset( $todo[ $mes ][ $clave ] );

		if ( ! $todo[ $mes ] ) {
			unset( $todo[ $mes ] );
		}

		update_option( self::OPT, $todo, false );

		if ( $mov > 0 && class_exists( 'Malka_CRM_Finance' ) && method_exists( 'Malka_CRM_Finance', 'borrar' ) ) {
			Malka_CRM_Finance::borrar( $mov );
		}

		return true;
	}

	/**
	 * Un pago del estudio por su referencia «Y-m|clave».
	 *
	 * @param string $ref Referencia.
	 * @return array|null
	 */
	public static function por_ref( $ref ) {
		$partes = explode( '|', (string) $ref, 2 );
		if ( 2 !== count( $partes ) ) {
			return null;
		}
		$hechos = self::hechos( $partes[0] );
		foreach ( self::del_mes( $partes[0] ) as $p ) {
			if ( $p['clave'] === $partes[1] ) {
				$p['mes'] = $partes[0];
				$p['ref'] = $ref;
				$p['mov'] = isset( $hechos[ $p['clave'] ]['mov'] ) ? (int) $hechos[ $p['clave'] ]['mov'] : 0;
				return $p;
			}
		}
		return null;
	}

	/**
	 * «Ya lo pagué», desde Tu día.
	 */
	public static function ajax_hecho() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}

		$mes   = isset( $_POST['mes'] ) ? sanitize_text_field( wp_unslash( $_POST['mes'] ) ) : '';
		$clave = isset( $_POST['clave'] ) ? sanitize_key( wp_unslash( $_POST['clave'] ) ) : '';
		$monto = isset( $_POST['monto'] ) ? (int) preg_replace( '/\D/', '', (string) wp_unslash( $_POST['monto'] ) ) : 0;
		$fecha = isset( $_POST['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fecha'] ) ) : '';

		// Sin el campo, la cuenta de siempre; con él, la que se eligió.
		$cuenta = isset( $_POST['cuenta'] ) ? max( 0, (int) $_POST['cuenta'] ) : null;

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $mes ) || '' === $clave ) {
			wp_send_json_error();
		}

		$ok = self::marcar( $mes, $clave, $monto, $fecha, $cuenta );

		/*
		 * Se devuelven los tres montones ya recalculados por el servidor. Si
		 * la pantalla los sumara sola, el monto que ella escribió al marcar
		 * —que puede no ser el del costo— quedaría mal contado.
		 */
		$r = self::resumen( $mes );

		wp_send_json(
			array(
				'success' => $ok,
				'resumen' => array(
					'atrasado'  => array( 'n' => $r['atrasado']['n'], 'txt' => $r['atrasado']['txt'] ),
					'pendiente' => array( 'n' => $r['pendiente']['n'], 'txt' => $r['pendiente']['txt'] ),
					'pagado'    => array( 'n' => $r['pagado']['n'], 'txt' => $r['pagado']['txt'] ),
					'total'     => $r['total_txt'],
				),
			)
		);
	}

	/**
	 * Para la campana: un aviso por cada pago que ya toca.
	 *
	 * @return array[] nivel, titulo, texto.
	 */
	public static function avisos() {
		$out = array();
		foreach ( self::proximos() as $p ) {
			list( $cuando, $tono ) = self::cuando( $p['dias'] );
			$out[] = array(
				'nivel'  => 'late' === $tono ? 'alta' : 'media',
				'titulo' => sprintf( /* translators: pago */ __( 'Pagar: %s', 'malka-crm' ), $p['nombre'] ),
				'texto'  => $cuando . ( $p['monto'] > 0 ? ' · ' . ( $p['variable'] ? '≈ ' : '' ) . Malka_CRM_Settings::money( $p['monto'] ) : '' ),
			);
		}
		return $out;
	}

	/**
	 * El correo del día: lo que hay que pagar hoy o mañana. Sale una vez al
	 * día, y sólo si hay algo.
	 */
	public static function recordar() {
		$hoy = array_filter(
			self::proximos( 1 ),
			function ( $p ) {
				return $p['dias'] <= 1;
			}
		);

		if ( ! $hoy || get_option( 'malka_crm_pagos_aviso' ) === current_time( 'Y-m-d' ) ) {
			return;
		}

		$s     = Malka_CRM_Settings::all();
		$ella  = ! empty( $s['email'] ) ? $s['email'] : get_option( 'admin_email' );
		$lineas = array();

		foreach ( $hoy as $p ) {
			list( $cuando ) = self::cuando( $p['dias'] );
			$lineas[] = '• ' . $p['nombre'] . ' — ' . $cuando . ( $p['monto'] > 0 ? ' — ' . ( $p['variable'] ? '≈ ' : '' ) . Malka_CRM_Settings::money( $p['monto'] ) : '' );
		}

		$cuerpo = __( 'Hola, estos pagos del estudio vencen hoy o mañana:', 'malka-crm' ) . "\n\n" .
			implode( "\n", $lineas ) . "\n\n" .
			__( 'Cuando los pagues, márcalos en «Tu día» y quedan anotados en Movimientos.', 'malka-crm' ) . "\n" .
			Malka_CRM_Dashboard::view_url( 'inicio' );

		/*
		 * Este es el único correo que atraviesa el modo vacaciones, y sólo
		 * si ella lo dejó pasar: un F29 que se pasa cuesta multa, y eso no
		 * espera a la vuelta.
		 */
		$v = class_exists( 'Malka_CRM_Vacaciones' ) && Malka_CRM_Vacaciones::activo();

		if ( $v ) {
			$e = Malka_CRM_Vacaciones::estado();
			Malka_CRM_Vacaciones::$deja_pasar = ! empty( $e['impuestos'] );
		}

		wp_mail( $ella, __( 'Pagos del estudio para hoy', 'malka-crm' ), $cuerpo );

		if ( $v ) {
			Malka_CRM_Vacaciones::$deja_pasar = false;
		}

		update_option( 'malka_crm_pagos_aviso', current_time( 'Y-m-d' ), false );
	}
}
