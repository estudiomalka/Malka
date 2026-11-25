<?php
/**
 * Análisis: observaciones sobre el negocio y plan del día.
 *
 * No hay IA acá: son reglas sobre los propios datos de la usuaria. Cada
 * observación nace de una comparación concreta y trae la acción que la resuelve.
 * Si falta el dato que la regla necesita, la regla no opina.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Analysis {

	/* ─────────────────────────────  Observaciones  ────────────────────── */

	/*
	 * Todo se trabaja en /escritorio/: nada de estas tarjetas puede mandarla
	 * a WP-Admin. Son dos ayudantes y no dos llamadas sueltas porque ya pasó
	 * una vez: se corrigió el enlace de la lista de cotizaciones y quedaron
	 * cuatro más apuntando al editor de WordPress.
	 */

	/**
	 * La ficha de un negocio, dentro del panel.
	 *
	 * @param int $pid Negocio.
	 * @return string
	 */
	private static function url_negocio( $pid ) {
		return class_exists( 'Malka_CRM_Projects' )
			? Malka_CRM_Projects::edit_url( (int) $pid )
			: '';
	}

	/**
	 * El editor de una cotización, dentro del panel.
	 *
	 * @param int $qid Cotización.
	 * @return string
	 */
	private static function url_cotizacion( $qid ) {
		return class_exists( 'Malka_CRM_Quote_Editor' )
			? Malka_CRM_Quote_Editor::edit_url( (int) $qid )
			: '';
	}

	/**
	 * Todas las observaciones vigentes, ordenadas por severidad.
	 *
	 * @return array[] array( 'nivel' => 'alta'|'media'|'baja', 'titulo', 'texto', 'accion', 'url' )
	 */
	public static function observations() {
		$out = array();

		/*
		 * Sólo análisis del negocio: tendencias, márgenes, clientes,
		 * precios. Lo operativo (el F29 que vence, una factura por emitir,
		 * un cobro atrasado) vive en «Tu día» y en Tareas, no acá.
		 */
		$reglas = array(
			'rule_cubre_sueldo',
			'rule_tendencia',
			'rule_ventas_mes',
			'rule_peso_compras',
			'rule_compras_suben',
			'rule_clientes_docs',
			'rule_clientes_dormidos',
			'rule_recurrencia',
			'rule_ticket',
			'rule_cierre',
			'rule_estacionalidad',
			'rule_rate_below_floor',
			'rule_rate_gap',
			'rule_client_concentration',
			'rule_pro_bono_over',
			'rule_costs_drift',
		);

		foreach ( $reglas as $regla ) {
			$r = self::$regla();
			if ( is_array( $r ) && ! empty( $r ) ) {
				$out = array_merge( $out, $r );
			}
		}

		$peso = array(
			'alta'  => 0,
			'media' => 1,
			'baja'  => 2,
			'bien'  => 3,
		);

		usort(
			$out,
			function ( $a, $b ) use ( $peso ) {
				$pa = isset( $peso[ $a['nivel'] ] ) ? $peso[ $a['nivel'] ] : 3;
				$pb = isset( $peso[ $b['nivel'] ] ) ? $peso[ $b['nivel'] ] : 3;
				return $pa <=> $pb;
			}
		);

		return $out;
	}

	/**
	 * Retiró más de lo seguro: lo que tiene que devolver a la cuenta.
	 *
	 * @return array[]
	 */
	private static function rule_devolver() {
		if ( ! class_exists( 'Malka_CRM_Retiro' ) ) {
			return array();
		}
		$r = Malka_CRM_Retiro::calcular();
		if ( empty( $r['devolver'] ) || $r['devolver'] <= 0 ) {
			return array();
		}
		return array(
			array(
				'nivel'  => 'alta',
				/* translators: monto */
				'titulo' => sprintf( __( 'Devuelve %s a la cuenta de la empresa', 'malka-crm' ), Malka_CRM_Settings::money( $r['devolver'] ) ),
				'texto'  => sprintf(
					/* translators: 1: retirado, 2: IVA y costos por pagar */
					__( 'Este mes retiraste %1$s y en la cuenta no alcanza para el IVA y los costos que vienen (%2$s).', 'malka-crm' ),
					Malka_CRM_Settings::money( (int) $r['retirado'] ),
					Malka_CRM_Settings::money( (int) $r['reserva'] )
				),
				'accion' => __( 'Ver cuánto retirar', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'movimientos' ),
			),
		);
	}

	/**
	 * El F29 que viene: cuánto y en cuántos días.
	 *
	 * @return array[]
	 */
	private static function rule_f29() {
		if ( ! class_exists( 'Malka_CRM_Contabilidad' ) ) {
			return array();
		}
		$hoy = current_time( 'Y-m-d' );
		$out = array();
		foreach ( Malka_CRM_Contabilidad::meses() as $f ) {
			if ( 'pagado' === $f['estado'] || $f['total'] <= 0 || $f['mes'] >= substr( $hoy, 0, 7 ) ) {
				continue;
			}
			$dias = (int) floor( ( strtotime( $f['vence'] ) - strtotime( $hoy ) ) / DAY_IN_SECONDS );
			// Lo atrasado de hace años no se repite cada mañana: sólo el último año.
			if ( $dias < -365 || $dias > 12 ) {
				continue;
			}
			$nom   = date_i18n( 'F', strtotime( $f['mes'] . '-01' ) );
			$out[] = array(
				'nivel'  => $dias <= 3 ? 'alta' : 'media',
				'titulo' => $dias < 0
					/* translators: 1: mes, 2: monto */
					? sprintf( __( 'El F29 de %1$s está atrasado: %2$s', 'malka-crm' ), $nom, Malka_CRM_Settings::money( $f['total'] ) )
					/* translators: 1: mes, 2: monto, 3: días */
					: sprintf( _n( 'F29 de %1$s: %2$s, vence en %3$d día', 'F29 de %1$s: %2$s, vence en %3$d días', max( 1, $dias ), 'malka-crm' ), $nom, Malka_CRM_Settings::money( $f['total'] ), $dias ),
				'texto'  => sprintf(
					/* translators: 1: IVA, 2: PPM */
					__( 'IVA %1$s más PPM %2$s. Si ya lo pagaste, márcalo en Contabilidad.', 'malka-crm' ),
					Malka_CRM_Settings::money( (int) $f['iva'] ),
					Malka_CRM_Settings::money( (int) $f['ppm'] )
				),
				'accion' => __( 'Ver contabilidad', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'contabilidad' ),
			);
		}
		return array_slice( array_reverse( $out ), 0, 1 );
	}

	/**
	 * Facturas de compra que le toca emitir a ella y siguen pendientes.
	 *
	 * @return array[]
	 */
	private static function rule_compras_por_emitir() {
		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return array();
		}
		$n     = 0;
		$monto = 0;
		foreach ( Malka_CRM_Documents::all() as $d ) {
			if ( 'compra' === $d['sentido'] && 'pendiente' === $d['emito'] && 'anulada' !== $d['estado'] ) {
				$n++;
				$monto += (int) $d['base'];
			}
		}
		if ( ! $n ) {
			return array();
		}
		return array(
			array(
				'nivel'  => 'media',
				/* translators: cantidad */
				'titulo' => sprintf( _n( 'Tienes %d factura de compra por emitir', 'Tienes %d facturas de compra por emitir', $n, 'malka-crm' ), $n ),
				/* translators: monto */
				'texto'  => sprintf( __( 'Suman %s. Mientras no las emitas, su IVA no entra como crédito en el F29.', 'malka-crm' ), Malka_CRM_Settings::money( $monto ) ),
				'accion' => __( 'Emitirlas', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'documentos' ),
			),
		);
	}

	/**
	 * El último mes cerrado contra el promedio de los 12 anteriores,
	 * según las facturas emitidas.
	 *
	 * @return array[]
	 */
	private static function rule_ventas_mes() {
		if ( ! class_exists( 'Malka_CRM_Contabilidad' ) ) {
			return array();
		}
		$meses = Malka_CRM_Contabilidad::meses();
		$ult   = gmdate( 'Y-m', strtotime( current_time( 'Y-m' ) . '-01 -1 month' ) );
		if ( ! isset( $meses[ $ult ] ) ) {
			return array();
		}
		$prev = array();
		for ( $i = 2; $i <= 13; $i++ ) {
			$k = gmdate( 'Y-m', strtotime( current_time( 'Y-m' ) . "-01 -{$i} month" ) );
			if ( isset( $meses[ $k ] ) ) {
				$prev[] = (int) $meses[ $k ]['v_neto'];
			}
		}
		if ( count( $prev ) < 3 ) {
			return array();
		}
		$prom   = array_sum( $prev ) / count( $prev );
		$actual = (int) $meses[ $ult ]['v_neto'];
		if ( $prom <= 0 ) {
			return array();
		}
		$pct = (int) round( ( $actual / $prom - 1 ) * 100 );
		if ( abs( $pct ) < 25 ) {
			return array();
		}
		$nom = date_i18n( 'F', strtotime( $ult . '-01' ) );
		return array(
			array(
				'nivel'  => $pct < 0 ? ( $pct <= -50 ? 'alta' : 'media' ) : 'baja',
				'titulo' => $pct < 0
					/* translators: 1: mes, 2: porcentaje */
					? sprintf( __( 'En %1$s facturaste %2$d%% menos que tu promedio', 'malka-crm' ), $nom, abs( $pct ) )
					/* translators: 1: mes, 2: porcentaje */
					: sprintf( __( 'En %1$s facturaste %2$d%% más que tu promedio', 'malka-crm' ), $nom, $pct ),
				'texto'  => sprintf(
					/* translators: 1: neto del mes, 2: promedio */
					__( 'Fueron %1$s netos contra %2$s de promedio en los últimos 12 meses.', 'malka-crm' ),
					Malka_CRM_Settings::money( $actual ),
					Malka_CRM_Settings::money( (int) round( $prom ) )
				),
				'accion' => __( 'Ver finanzas', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'finanzas' ),
			),
		);
	}

	/**
	 * Cuánto de lo facturado en 12 meses viene de un solo cliente.
	 *
	 * @return array[]
	 */
	private static function rule_clientes_docs() {
		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return array();
		}
		$desde = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -12 months' ) );
		$por   = array();
		$total = 0;
		foreach ( Malka_CRM_Documents::all() as $d ) {
			if ( 'venta' !== $d['sentido'] || 'anulada' === $d['estado'] || $d['fecha'] < $desde || $d['base'] <= 0 ) {
				continue;
			}
			$k = $d['rut'] ? $d['rut'] : strtolower( $d['cliente'] );
			if ( ! isset( $por[ $k ] ) ) {
				$por[ $k ] = array( 0, $d['cliente'] );
			}
			$por[ $k ][0] += (int) $d['base'];
			$total        += (int) $d['base'];
		}
		if ( $total <= 0 || count( $por ) < 2 ) {
			return array();
		}
		uasort(
			$por,
			function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);
		$top = reset( $por );
		$pct = (int) round( $top[0] / $total * 100 );
		if ( $pct < 40 ) {
			return array();
		}
		return array(
			array(
				'nivel'  => $pct >= 60 ? 'alta' : 'media',
				/* translators: 1: porcentaje, 2: cliente */
				'titulo' => sprintf( __( 'El %1$d%% de lo que facturaste en el año es de %2$s', 'malka-crm' ), $pct, $top[1] ),
				/* translators: 1: monto, 2: total */
				'texto'  => sprintf( __( 'Son %1$s de %2$s en los últimos 12 meses. Si ese cliente se va, el estudio pierde casi la mitad de sus ventas.', 'malka-crm' ), Malka_CRM_Settings::money( $top[0] ), Malka_CRM_Settings::money( $total ) ),
				'accion' => __( 'Ver ventas', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'documentos' ),
			),
		);
	}


	/* ─────────────────────────────  Análisis del negocio  ───────────────── */

	/**
	 * Los últimos meses cerrados (sin el mes en curso), del más nuevo al
	 * más viejo, con ventas y compras netas.
	 *
	 * @param int $n Cuántos.
	 * @return array[] mes => array( v, c )
	 */
	private static function meses_cerrados( $n ) {
		$meses = class_exists( 'Malka_CRM_Contabilidad' ) ? Malka_CRM_Contabilidad::meses() : array();
		$out   = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$k         = gmdate( 'Y-m', strtotime( current_time( 'Y-m' ) . "-01 -{$i} month" ) );
			$out[ $k ] = array(
				'v' => isset( $meses[ $k ] ) ? (int) $meses[ $k ]['v_neto'] : 0,
				'c' => isset( $meses[ $k ] ) ? (int) $meses[ $k ]['c_neto'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Ventas y compras con documentos: ¿hay datos para analizar?
	 *
	 * @return bool
	 */
	private static function hay_ventas() {
		foreach ( self::meses_cerrados( 12 ) as $m ) {
			if ( $m['v'] > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * ¿Lo que vendes alcanza para tu sueldo y tus costos?
	 *
	 * @return array[]
	 */
	private static function rule_cubre_sueldo() {
		$sueldo = (int) Malka_CRM_Settings::get( 'sueldo_objetivo', 0 );
		if ( $sueldo <= 0 || ! self::hay_ventas() ) {
			return array();
		}
		$m = self::meses_cerrados( 6 );
		$v = 0;
		foreach ( $m as $x ) {
			$v += $x['v'];
		}
		$prom     = (int) round( $v / 6 );
		$costos   = (int) Malka_CRM_Settings::monthly_costs()['monto'];
		$necesita = $sueldo + $costos;
		$pct      = $necesita > 0 ? (int) round( $prom / $necesita * 100 ) : 0;

		if ( $pct >= 110 ) {
			return array(
				array(
					'nivel'  => 'bien',
					/* translators: porcentaje */
					'titulo' => sprintf( __( 'Tus ventas cubren el %d%% de lo que necesitas', 'malka-crm' ), $pct ),
					/* translators: 1: promedio, 2: necesario */
					'texto'  => sprintf( __( 'En los últimos 6 meses vendiste %1$s netos al mes y necesitas %2$s para tu sueldo y tus costos. Lo que sobra conviene separarlo: un fondo de 3 meses de costos te deja decir que no a proyectos mal pagados.', 'malka-crm' ), Malka_CRM_Settings::money( $prom ), Malka_CRM_Settings::money( $necesita ) ),
					'accion' => __( 'Ver finanzas', 'malka-crm' ),
					'url'    => Malka_CRM_Dashboard::view_url( 'finanzas' ),
				),
			);
		}
		if ( $pct >= 95 ) {
			return array();
		}
		$falta = $necesita - $prom;
		$tasa  = (int) Malka_CRM_Settings::get( 'tarifa_cobrada', 0 );
		$horas = $tasa > 0 ? (int) ceil( $falta / $tasa ) : 0;
		return array(
			array(
				'nivel'  => $pct < 70 ? 'alta' : 'media',
				/* translators: porcentaje */
				'titulo' => sprintf( __( 'Tus ventas cubren sólo el %d%% de tu sueldo y tus costos', 'malka-crm' ), $pct ),
				'texto'  => sprintf(
					/* translators: 1: promedio, 2: necesario, 3: falta */
					__( 'Promedias %1$s netos al mes y necesitas %2$s. Te faltan %3$s al mes.', 'malka-crm' ),
					Malka_CRM_Settings::money( $prom ),
					Malka_CRM_Settings::money( $necesita ),
					Malka_CRM_Settings::money( $falta )
				) . ' ' . ( $horas
					/* translators: horas */
					? sprintf( __( 'A tu tarifa son unas %d horas más facturadas al mes, o subir precios en esa proporción.', 'malka-crm' ), $horas )
					: __( 'Sube precios o suma un proyecto mensual recurrente.', 'malka-crm' ) ),
				'accion' => __( 'Revisar tarifa', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'configuracion' ),
			),
		);
	}

	/**
	 * Los últimos 3 meses contra los 3 anteriores.
	 *
	 * @return array[]
	 */
	private static function rule_tendencia() {
		$m = array_values( self::meses_cerrados( 6 ) );
		$a = $m[0]['v'] + $m[1]['v'] + $m[2]['v'];
		$b = $m[3]['v'] + $m[4]['v'] + $m[5]['v'];
		if ( $b <= 0 || $a <= 0 ) {
			return array();
		}
		$pct = (int) round( ( $a / $b - 1 ) * 100 );
		if ( abs( $pct ) < 20 ) {
			return array();
		}
		$sube = $pct > 0;
		return array(
			array(
				'nivel'  => $sube ? 'bien' : ( $pct <= -40 ? 'alta' : 'media' ),
				'titulo' => $sube
					/* translators: porcentaje */
					? sprintf( __( 'Vas creciendo: %d%% más que el trimestre anterior', 'malka-crm' ), $pct )
					/* translators: porcentaje */
					: sprintf( __( 'Tus ventas bajaron %d%% respecto del trimestre anterior', 'malka-crm' ), abs( $pct ) ),
				'texto'  => sprintf(
					/* translators: 1: últimos 3 meses, 2: anteriores */
					__( 'Últimos 3 meses: %1$s netos. Los 3 anteriores: %2$s.', 'malka-crm' ),
					Malka_CRM_Settings::money( $a ),
					Malka_CRM_Settings::money( $b )
				) . ' ' . ( $sube
					? __( 'Buen momento para subir precios a clientes nuevos: la demanda está.', 'malka-crm' )
					: __( 'Escríbele a tus clientes de los últimos meses y mira tus cotizaciones sin respuesta: es lo más rápido para recuperar.', 'malka-crm' ) ),
				'accion' => __( 'Ver ventas', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'finanzas' ),
			),
		);
	}

	/**
	 * Cuánto de lo que vendes se va en compras con factura.
	 *
	 * @return array[]
	 */
	private static function rule_peso_compras() {
		$v = 0;
		$c = 0;
		foreach ( self::meses_cerrados( 6 ) as $x ) {
			$v += $x['v'];
			$c += $x['c'];
		}
		if ( $v <= 0 ) {
			return array();
		}
		$pct = (int) round( $c / $v * 100 );
		if ( $pct < 45 ) {
			return array();
		}
		return array(
			array(
				'nivel'  => $pct >= 70 ? 'alta' : 'media',
				/* translators: porcentaje */
				'titulo' => sprintf( __( 'Las compras se llevan el %d%% de lo que vendes', 'malka-crm' ), $pct ),
				/* translators: 1: compras, 2: ventas */
				'texto'  => sprintf( __( 'En 6 meses compraste %1$s netos y vendiste %2$s. Si parte son insumos de un proyecto (impresión, producción), cóbralos aparte con un margen del 15 a 20%%; si son suscripciones, revisa cuáles usas de verdad.', 'malka-crm' ), Malka_CRM_Settings::money( $c ), Malka_CRM_Settings::money( $v ) ),
				'accion' => __( 'Ver compras', 'malka-crm' ),
				'url'    => add_query_arg( 'ver', 'compra', Malka_CRM_Dashboard::view_url( 'documentos' ) ),
			),
		);
	}

	/**
	 * Compras de los últimos 3 meses contra el promedio del año.
	 *
	 * @return array[]
	 */
	private static function rule_compras_suben() {
		$m = array_values( self::meses_cerrados( 12 ) );
		$a = ( $m[0]['c'] + $m[1]['c'] + $m[2]['c'] ) / 3;
		$b = 0;
		for ( $i = 3; $i < 12; $i++ ) {
			$b += $m[ $i ]['c'];
		}
		$b = $b / 9;
		if ( $b <= 0 || $a < $b * 1.3 ) {
			return array();
		}
		$pct = (int) round( ( $a / $b - 1 ) * 100 );
		return array(
			array(
				'nivel'  => 'media',
				/* translators: porcentaje */
				'titulo' => sprintf( __( 'Tus gastos con factura subieron %d%%', 'malka-crm' ), $pct ),
				/* translators: 1: promedio reciente, 2: promedio anterior */
				'texto'  => sprintf( __( 'Los últimos 3 meses promedian %1$s netos al mes, contra %2$s en los 9 anteriores. Revisa si es algo puntual o un gasto nuevo que se quedó.', 'malka-crm' ), Malka_CRM_Settings::money( (int) round( $a ) ), Malka_CRM_Settings::money( (int) round( $b ) ) ),
				'accion' => __( 'Ver compras', 'malka-crm' ),
				'url'    => add_query_arg( 'ver', 'compra', Malka_CRM_Dashboard::view_url( 'documentos' ) ),
			),
		);
	}

	/**
	 * Las ventas de los últimos años, por cliente.
	 *
	 * @return array[] llave => array( nombre, total, n, ultima, primera, ult12 )
	 */
	private static function ventas_por_cliente() {
		$out   = array();
		$desde = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -12 months' ) );
		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return $out;
		}
		foreach ( Malka_CRM_Documents::all() as $d ) {
			if ( 'venta' !== $d['sentido'] || 'anulada' === $d['estado'] || ! $d['fecha'] || (int) $d['neto'] <= 0 ) {
				continue;
			}
			$k = $d['rut'] ? $d['rut'] : strtolower( $d['cliente'] );
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array(
					'nombre'  => $d['cliente'],
					'total'   => 0,
					'n'       => 0,
					'ultima'  => '',
					'primera' => '9999',
					'ult12'   => 0,
					'antes'   => false,
				);
			}
			$out[ $k ]['total'] += (int) $d['neto'];
			$out[ $k ]['n']++;
			$out[ $k ]['ultima']  = max( $out[ $k ]['ultima'], $d['fecha'] );
			$out[ $k ]['primera'] = min( $out[ $k ]['primera'], $d['fecha'] );
			if ( $d['fecha'] >= $desde ) {
				$out[ $k ]['ult12'] += (int) $d['neto'];
			} else {
				$out[ $k ]['antes'] = true;
			}
		}
		return $out;
	}

	/**
	 * Buenos clientes que dejaron de comprar.
	 *
	 * @return array[]
	 */
	private static function rule_clientes_dormidos() {
		$hoy  = current_time( 'Y-m-d' );
		$lim  = gmdate( 'Y-m-d', strtotime( $hoy . ' -5 months' ) );
		$top  = array();
		foreach ( self::ventas_por_cliente() as $c ) {
			if ( $c['n'] >= 2 && $c['ultima'] < $lim && $c['ultima'] >= gmdate( 'Y-m-d', strtotime( $hoy . ' -30 months' ) ) ) {
				$top[] = $c;
			}
		}
		if ( ! $top ) {
			return array();
		}
		usort(
			$top,
			function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);
		$top    = array_slice( $top, 0, 3 );
		$nombres = array_map(
			function ( $c ) {
				return rtrim( $c['nombre'], '.' );
			},
			$top
		);
		$primero = $top[0];
		$meses   = (int) floor( ( strtotime( $hoy ) - strtotime( $primero['ultima'] ) ) / ( 30 * DAY_IN_SECONDS ) );
		return array(
			array(
				'nivel'  => 'media',
				/* translators: cantidad */
				'titulo' => sprintf( _n( '%d buen cliente dejó de comprarte', '%d buenos clientes dejaron de comprarte', count( $top ), 'malka-crm' ), count( $top ) ),
				'texto'  => sprintf(
					/* translators: 1: nombres, 2: primer cliente, 3: monto, 4: facturas, 5: meses */
					__( '%1$s. %2$s te compró %3$s en %4$d facturas y hace %5$d meses no. Recuperar un cliente cuesta mucho menos que conseguir uno nuevo: mándale lo último que hiciste o una idea para su marca.', 'malka-crm' ),
					implode( ', ', $nombres ),
					rtrim( $primero['nombre'], '.' ),
					Malka_CRM_Settings::money( $primero['total'] ),
					$primero['n'],
					$meses
				),
				'accion' => __( 'Ver clientes', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'clientes' ),
			),
		);
	}

	/**
	 * Cuánto de lo vendido en el año viene de clientes que repiten.
	 *
	 * @return array[]
	 */
	private static function rule_recurrencia() {
		$tot = 0;
		$rep = 0;
		foreach ( self::ventas_por_cliente() as $c ) {
			$tot += $c['ult12'];
			if ( $c['antes'] || $c['n'] >= 2 ) {
				$rep += $c['ult12'];
			}
		}
		if ( $tot <= 0 ) {
			return array();
		}
		$pct = (int) round( $rep / $tot * 100 );
		if ( $pct >= 35 && $pct < 70 ) {
			return array();
		}
		$bien = $pct >= 70;
		return array(
			array(
				'nivel'  => $bien ? 'bien' : 'media',
				'titulo' => $bien
					/* translators: porcentaje */
					? sprintf( __( 'El %d%% de tus ventas viene de clientes que vuelven', 'malka-crm' ), $pct )
					/* translators: porcentaje */
					: sprintf( __( 'Sólo el %d%% de tus ventas viene de clientes que vuelven', 'malka-crm' ), $pct ),
				'texto'  => $bien
					? __( 'Tus clientes confían en ti. Ofréceles un plan mensual (redes, mantención, piezas recurrentes): convierte proyectos sueltos en un ingreso fijo.', 'malka-crm' )
					: __( 'Casi todo es cliente nuevo, y cada uno cuesta conseguirlo. Al cerrar un proyecto, ofrece el siguiente paso (papelería, redes, web) y agenda un contacto a los 3 meses.', 'malka-crm' ),
				'accion' => __( 'Ver clientes', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'clientes' ),
			),
		);
	}

	/**
	 * La factura promedio: este año contra el anterior.
	 *
	 * @return array[]
	 */
	private static function rule_ticket() {
		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return array();
		}
		$hoy = current_time( 'Y-m-d' );
		$d12 = gmdate( 'Y-m-d', strtotime( $hoy . ' -12 months' ) );
		$d24 = gmdate( 'Y-m-d', strtotime( $hoy . ' -24 months' ) );
		$a   = array( 0, 0 );
		$b   = array( 0, 0 );
		foreach ( Malka_CRM_Documents::all() as $d ) {
			if ( 'venta' !== $d['sentido'] || 'anulada' === $d['estado'] || (int) $d['neto'] <= 0 || ! $d['fecha'] ) {
				continue;
			}
			if ( $d['fecha'] >= $d12 ) {
				$a[0] += (int) $d['neto'];
				$a[1]++;
			} elseif ( $d['fecha'] >= $d24 ) {
				$b[0] += (int) $d['neto'];
				$b[1]++;
			}
		}
		if ( $a[1] < 5 || $b[1] < 5 ) {
			return array();
		}
		$pa  = $a[0] / $a[1];
		$pb  = $b[0] / $b[1];
		$pct = (int) round( ( $pa / $pb - 1 ) * 100 );
		if ( abs( $pct ) < 15 ) {
			return array();
		}
		$sube = $pct > 0;
		return array(
			array(
				'nivel'  => $sube ? 'bien' : 'media',
				'titulo' => $sube
					/* translators: porcentaje */
					? sprintf( __( 'Tu factura promedio subió %d%%', 'malka-crm' ), $pct )
					/* translators: porcentaje */
					: sprintf( __( 'Tu factura promedio bajó %d%%', 'malka-crm' ), abs( $pct ) ),
				'texto'  => sprintf(
					/* translators: 1: promedio actual, 2: anterior */
					__( 'Este año facturas %1$s en promedio; el anterior, %2$s.', 'malka-crm' ),
					Malka_CRM_Settings::money( (int) round( $pa ) ),
					Malka_CRM_Settings::money( (int) round( $pb ) )
				) . ' ' . ( $sube
					? __( 'Proyectos más completos rinden más que muchos chicos: sigue armando paquetes.', 'malka-crm' )
					: __( 'Estás haciendo trabajos más chicos. Arma paquetes (marca + papelería + redes) en vez de piezas sueltas, y fija un mínimo por proyecto.', 'malka-crm' ) ),
				'accion' => __( 'Ver ventas', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'documentos' ),
			),
		);
	}

	/**
	 * La tasa de cierre de las cotizaciones.
	 *
	 * @return array[]
	 */
	private static function rule_cierre() {
		$q = self::quote_stats();
		if ( null === $q['tasa'] || $q['cerrando'] < 4 ) {
			return array();
		}
		if ( $q['tasa'] >= 80 ) {
			return array(
				array(
					'nivel'  => 'baja',
					/* translators: porcentaje */
					'titulo' => sprintf( __( 'Te aceptan el %d%% de las cotizaciones', 'malka-crm' ), $q['tasa'] ),
					'texto'  => __( 'Cerrar casi todo suele significar que estás barata para lo que entregas. Prueba subir 10 a 15% en las próximas cotizaciones y mira si la tasa se mantiene sobre 60%.', 'malka-crm' ),
					'accion' => __( 'Ver cotizaciones', 'malka-crm' ),
					'url'    => Malka_CRM_Dashboard::view_url( 'cotizaciones' ),
				),
			);
		}
		if ( $q['tasa'] >= 40 ) {
			return array();
		}
		return array(
			array(
				'nivel'  => 'media',
				/* translators: porcentaje */
				'titulo' => sprintf( __( 'Sólo te aceptan el %d%% de las cotizaciones', 'malka-crm' ), $q['tasa'] ),
				'texto'  => __( 'Haz seguimiento a los 3 días de enviarla, ofrece dos o tres alternativas de alcance en vez de una sola, y pregunta por qué cuando te dicen que no.', 'malka-crm' ),
				'accion' => __( 'Ver cotizaciones', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'cotizaciones' ),
			),
		);
	}

	/**
	 * El mes que viene, según cómo te fue el mismo mes el año pasado.
	 *
	 * @return array[]
	 */
	private static function rule_estacionalidad() {
		$meses = class_exists( 'Malka_CRM_Contabilidad' ) ? Malka_CRM_Contabilidad::meses() : array();
		$prox  = gmdate( 'Y-m', strtotime( current_time( 'Y-m' ) . '-01 +1 month' ) );
		$hace  = gmdate( 'Y-m', strtotime( $prox . '-01 -12 months' ) );
		$suma  = 0;
		$n     = 0;
		for ( $i = 1; $i <= 12; $i++ ) {
			// Los doce meses alrededor de ese mes del año pasado.
			$k = gmdate( 'Y-m', strtotime( $hace . '-01 ' . sprintf( '%+d', $i - 6 ) . ' month' ) );
			if ( isset( $meses[ $k ] ) ) {
				$suma += (int) $meses[ $k ]['v_neto'];
				$n++;
			}
		}
		if ( ! isset( $meses[ $hace ] ) || $n < 10 || $suma <= 0 ) {
			return array();
		}
		$prom = $suma / $n;
		$v    = (int) $meses[ $hace ]['v_neto'];
		$pct  = (int) round( ( $v / $prom - 1 ) * 100 );
		if ( abs( $pct ) < 35 ) {
			return array();
		}
		$nom  = date_i18n( 'F', strtotime( $prox . '-01' ) );
		$alto = $pct > 0;
		return array(
			array(
				'nivel'  => 'baja',
				'titulo' => $alto
					/* translators: mes */
					? sprintf( __( '%s suele ser un mes fuerte', 'malka-crm' ), ucfirst( $nom ) )
					/* translators: mes */
					: sprintf( __( '%s suele ser un mes flojo', 'malka-crm' ), ucfirst( $nom ) ),
				'texto'  => sprintf(
					/* translators: 1: mes, 2: monto, 3: porcentaje */
					__( 'El año pasado en %1$s vendiste %2$s netos, %3$d%% respecto de tu promedio.', 'malka-crm' ),
					$nom,
					Malka_CRM_Settings::money( $v ),
					$pct
				) . ' ' . ( $alto
					? __( 'Deja espacio en tu agenda y ten listas las cotizaciones tipo.', 'malka-crm' )
					: __( 'Adelanta la venta ahora: contacta clientes antiguos o lanza una promoción antes de que llegue.', 'malka-crm' ) ),
				'accion' => __( 'Ver ventas', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'finanzas' ),
			),
		);
	}

	/**
	 * Plata vencida: lo primero que hay que mirar cada mañana.
	 *
	 * @return array[]
	 */
	private static function rule_overdue_cash() {
		$vencidos = self::overdue_payments();
		if ( empty( $vencidos ) ) {
			return array();
		}

		$total = 0;
		$viejo = $vencidos[0];

		foreach ( $vencidos as $v ) {
			$total += $v['monto'];
			if ( $v['dias'] > $viejo['dias'] ) {
				$viejo = $v;
			}
		}

		return array(
			array(
				'clave'  => 'cobro_vencido',
				'nivel'  => $viejo['dias'] > 30 ? 'alta' : 'media',
				'titulo' => sprintf(
					/* translators: 1: monto vencido, 2: número de cuotas */
					_n( '%1$s vencido en %2$d cuota', '%1$s vencido en %2$d cuotas', count( $vencidos ), 'malka-crm' ),
					Malka_CRM_Settings::money( $total ),
					count( $vencidos )
				),
				'texto'  => sprintf(
					/* translators: 1: referencia, 2: días */
					__( 'La más antigua es %1$s, con %2$d días de atraso. Mientras no entre, el mes se financia con tu propia plata.', 'malka-crm' ),
					$viejo['referencia'],
					$viejo['dias']
				),
				'accion' => __( 'Ver la cobranza', 'malka-crm' ),
				'url'    => $viejo['url'],
			),
		);
	}

	/**
	 * La tarifa que cobra contra la mínima que cubre sueldo y costos.
	 *
	 * Es la observación más importante del panel: si está bajo el piso, cada
	 * hora trabajada le cuesta plata en vez de dejarle.
	 *
	 * @return array[]
	 */
	private static function rule_rate_below_floor() {
		$r = Malka_CRM_Settings::rate();

		if ( empty( $r['completo'] ) ) {
			return array();
		}

		$cobrada = (int) Malka_CRM_Settings::get( 'tarifa_cobrada', 0 );
		$minima  = (int) $r['tarifa_minima'];

		if ( $cobrada <= 0 || $minima <= 0 || $cobrada >= $minima ) {
			return array();
		}

		$brecha = $minima - $cobrada;
		$pct    = (int) round( ( $brecha / $minima ) * 100 );

		return array(
			array(
				'nivel'  => $pct >= 20 ? 'alta' : 'media',
				'titulo' => sprintf(
					/* translators: porcentaje bajo la tarifa mínima */
					__( 'Estás cobrando %d%% bajo tu piso', 'malka-crm' ),
					$pct
				),
				'texto'  => sprintf(
					/* translators: 1: tarifa cobrada, 2: tarifa mínima, 3: brecha por hora, 4: margen anual */
					__( 'Cobras %1$s la hora y la mínima que cubre tu sueldo, tus costos y tus vacaciones es %2$s. Cada hora que trabajas te cuesta %3$s, y al año son %4$s.', 'malka-crm' ),
					Malka_CRM_Settings::money( $cobrada ),
					Malka_CRM_Settings::money( $minima ),
					Malka_CRM_Settings::money( $brecha ),
					Malka_CRM_Settings::money( abs( (int) $r['margen_anual'] ) )
				),
				'accion' => __( 'Ajustar la tarifa', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'configuracion' ),
			),
		);
	}

	private static function rule_rate_gap() {
		if ( ! class_exists( 'Malka_CRM_Time' ) ) {
			return array();
		}

		$tarifa = Malka_CRM_Settings::hourly_rate();
		if ( $tarifa <= 0 ) {
			return array();
		}

		$out = array();

		foreach ( self::active_projects() as $pid ) {
			$neto  = self::project_net( $pid );
			$horas = Malka_CRM_Time::minutes_for_project( $pid ) / 60;

			if ( $neto <= 0 || $horas < 5 ) {
				continue;
			}

			$real = $neto / $horas;
			if ( $real >= $tarifa * 0.85 ) {
				continue;
			}

			$out[] = array(
				'nivel'  => ( $real < $tarifa * 0.6 ) ? 'alta' : 'media',
				'titulo' => sprintf(
					/* translators: 1: proyecto, 2: tarifa real */
					__( 'Tu hora real en %1$s va en %2$s', 'malka-crm' ),
					get_the_title( $pid ),
					Malka_CRM_Settings::money( (int) round( $real ) )
				),
				'texto'  => sprintf(
					/* translators: 1: neto, 2: horas, 3: tarifa objetivo */
					__( 'Cotizaste %1$s y llevas %2$s horas registradas. Tu tarifa objetivo es %3$s.', 'malka-crm' ),
					Malka_CRM_Settings::money( $neto ),
					number_format_i18n( $horas, 1 ),
					Malka_CRM_Settings::money( $tarifa )
				),
				'accion' => __( 'Revisar el proyecto', 'malka-crm' ),
				'url'    => self::url_negocio( $pid ),
			);
		}

		return $out;
	}

	/**
	 * Cuánto de la facturación en curso depende de un solo cliente.
	 */
	private static function rule_client_concentration() {
		// Con facturas emitidas manda la regla de documentos, que mira el año
		// completo; esta queda para quien todavía no anota sus ventas.
		if ( class_exists( 'Malka_CRM_Documents' ) ) {
			foreach ( Malka_CRM_Documents::all() as $d ) {
				if ( 'venta' === $d['sentido'] ) {
					return array();
				}
			}
		}
		$por_cliente = array();
		$total       = 0;

		foreach ( self::active_projects() as $pid ) {
			$neto = self::project_net( $pid );
			if ( $neto <= 0 ) {
				continue;
			}
			$cliente = (int) get_post_meta( $pid, '_malka_pm_client_id', true );
			$key     = $cliente ? $cliente : 'sin';

			if ( ! isset( $por_cliente[ $key ] ) ) {
				$por_cliente[ $key ] = 0;
			}
			$por_cliente[ $key ] += $neto;
			$total               += $neto;
		}

		if ( $total <= 0 || count( $por_cliente ) < 2 ) {
			return array();
		}

		arsort( $por_cliente );
		$top_id    = array_key_first( $por_cliente );
		$top_monto = $por_cliente[ $top_id ];
		$pct       = round( ( $top_monto / $total ) * 100 );

		if ( $pct < 60 ) {
			return array();
		}

		$nombre = __( 'un solo cliente', 'malka-crm' );
		if ( 'sin' !== $top_id ) {
			$u = get_userdata( (int) $top_id );
			if ( $u ) {
				$nombre = $u->display_name;
			}
		}

		return array(
			array(
				'nivel'  => ( $pct >= 80 ) ? 'alta' : 'media',
				'titulo' => sprintf(
					/* translators: 1: porcentaje, 2: cliente */
					__( 'El %1$d%% de lo contratado depende de %2$s', 'malka-crm' ),
					$pct,
					$nombre
				),
				'texto'  => sprintf(
					/* translators: 1: monto del cliente, 2: monto total */
					__( 'Son %1$s de %2$s en curso. Si esa relación se enfría, el mes se cae entero.', 'malka-crm' ),
					Malka_CRM_Settings::money( $top_monto ),
					Malka_CRM_Settings::money( $total )
				),
				'accion' => __( 'Ver clientes', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'negocios' ),
			),
		);
	}

	/**
	 * Proyectos que avanzan con un pago vencido encima.
	 */
	private static function rule_unpaid_progress() {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return array();
		}

		$out = array();

		foreach ( self::active_projects() as $pid ) {
			$quote_id = Malka_CRM_Quotes::get_project_quote_id( $pid );
			if ( ! $quote_id ) {
				continue;
			}

			$vencido = 0;
			foreach ( Malka_CRM_Quotes::get_payments( $quote_id ) as $p ) {
				if ( Malka_CRM_Quotes::es_resumen( $quote_id, $p ) ) {
					continue;
				}
				$estado = Malka_CRM_Quote_Page::payment_view( $quote_id, $p );
				if ( 'overdue' === $estado['state'] ) {
					$vencido += (int) $p['amount'];
				}
			}

			if ( $vencido <= 0 ) {
				continue;
			}

			$avance = (int) Malka_CRM_Helpers::get_project_progress( $pid );
			if ( $avance < 10 ) {
				continue;
			}

			$out[] = array(
				'nivel'  => 'alta',
				'titulo' => sprintf(
					/* translators: proyecto */
					__( '%s avanza con un pago vencido', 'malka-crm' ),
					get_the_title( $pid )
				),
				'texto'  => sprintf(
					/* translators: 1: avance, 2: monto vencido */
					__( 'Va en %1$d%% y tiene %2$s vencidos. Tus términos dicen que el trabajo se suspende hasta que el pago se acredite.', 'malka-crm' ),
					$avance,
					Malka_CRM_Settings::money( $vencido )
				),
				'accion' => __( 'Ver la cotización', 'malka-crm' ),
				'url'    => self::url_cotizacion( $quote_id ),
			);
		}

		return $out;
	}

	/**
	 * El mes en curso contra el promedio del año.
	 */
	private static function rule_month_vs_average() {
		$serie = self::monthly_net_series();
		if ( count( $serie ) < 3 ) {
			return array();
		}

		$mes_actual = (int) current_time( 'n' );
		$actual     = isset( $serie[ $mes_actual ] ) ? $serie[ $mes_actual ] : 0;

		$previos = array();
		foreach ( $serie as $m => $v ) {
			if ( $m < $mes_actual && $v > 0 ) {
				$previos[] = $v;
			}
		}

		if ( count( $previos ) < 2 ) {
			return array();
		}

		$promedio = array_sum( $previos ) / count( $previos );
		if ( $promedio <= 0 || $actual >= $promedio * 0.7 ) {
			return array();
		}

		$pct = round( ( 1 - ( $actual / $promedio ) ) * 100 );

		return array(
			array(
				'nivel'  => 'media',
				'titulo' => sprintf(
					/* translators: porcentaje */
					__( 'Este mes va %d%% bajo tu promedio', 'malka-crm' ),
					$pct
				),
				'texto'  => sprintf(
					/* translators: 1: monto del mes, 2: promedio */
					__( 'Llevas %1$s netos contra un promedio de %2$s en los meses corridos del año.', 'malka-crm' ),
					Malka_CRM_Settings::money( (int) $actual ),
					Malka_CRM_Settings::money( (int) round( $promedio ) )
				),
				'accion' => __( 'Ver facturación', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'movimientos' ),
			),
		);
	}

	/**
	 * Horas donadas por sobre el tope del mes.
	 */
	private static function rule_pro_bono_over() {
		if ( ! class_exists( 'Malka_CRM_Time' ) ) {
			return array();
		}

		$r = Malka_CRM_Time::pro_bono_summary();
		if ( empty( $r['excedido'] ) ) {
			return array();
		}

		$tarifa = Malka_CRM_Settings::hourly_rate();

		return array(
			array(
				'nivel'  => 'media',
				'titulo' => sprintf(
					/* translators: horas de exceso */
					__( 'Donaste %s horas más de las que tenías presupuestadas', 'malka-crm' ),
					number_format_i18n( $r['exceso'], 1 )
				),
				'texto'  => sprintf(
					/* translators: 1: usadas, 2: tope, 3: monto */
					__( 'Van %1$s h de un tope de %2$d h. A tu tarifa, el exceso son %3$s que no entraron.', 'malka-crm' ),
					number_format_i18n( $r['usadas'], 1 ),
					$r['tope'],
					Malka_CRM_Settings::money( (int) round( $r['exceso'] * $tarifa ) )
				),
				'accion' => __( 'Ver horas', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'horas' ),
			),
		);
	}

	/**
	 * Los costos reales se despegaron del presupuesto.
	 */
	private static function rule_costs_drift() {
		$info = Malka_CRM_Settings::monthly_costs();

		if ( 'real' !== $info['fuente'] || $info['presupuesto'] <= 0 ) {
			return array();
		}

		$dif = $info['real'] - $info['presupuesto'];
		if ( abs( $dif ) < $info['presupuesto'] * 0.15 ) {
			return array();
		}

		$rate = Malka_CRM_Settings::rate();

		return array(
			array(
				'nivel'  => ( $dif > 0 ) ? 'media' : 'baja',
				'titulo' => ( $dif > 0 )
					? __( 'Tus costos reales subieron sobre lo presupuestado', 'malka-crm' )
					: __( 'Tus costos reales están bajo lo presupuestado', 'malka-crm' ),
				'texto'  => sprintf(
					/* translators: 1: real, 2: presupuesto, 3: tarifa mínima */
					__( 'Promedio real %1$s contra %2$s estimados. Tu tarifa mínima quedó en %3$s.', 'malka-crm' ),
					Malka_CRM_Settings::money( $info['real'] ),
					Malka_CRM_Settings::money( $info['presupuesto'] ),
					Malka_CRM_Settings::money( $rate['tarifa_minima'] )
				),
				'accion' => __( 'Revisar costos', 'malka-crm' ),
				'url'    => Malka_CRM_Dashboard::view_url( 'configuracion' ),
			),
		);
	}

	/* ─────────────────────────────  Plan del día  ─────────────────────── */

	/**
	 * Arma el plan del día dentro de los bloques horarios configurados.
	 *
	 * El orden lo decide el costo de postergar: primero lo que destraba plata,
	 * después lo que vence hoy, luego el trabajo creativo en bloque largo, y al
	 * final lo administrativo.
	 *
	 * @return array array( 'bloques' => array, 'minutos' => int, 'disponibles' => int, 'fuera' => array )
	 */
	public static function day_plan() {
		$candidatos = self::candidates();

		usort(
			$candidatos,
			function ( $a, $b ) {
				if ( $a['prioridad'] !== $b['prioridad'] ) {
					return $a['prioridad'] <=> $b['prioridad'];
				}
				return $b['monto'] <=> $a['monto'];
			}
		);

		$bloques = array();
		foreach ( array( 'bloque_am' => __( 'Mañana', 'malka-crm' ), 'bloque_pm' => __( 'Tarde', 'malka-crm' ) ) as $key => $label ) {
			$rango = Malka_CRM_Settings::get( $key );
			if ( ! is_array( $rango ) || empty( $rango[0] ) || empty( $rango[1] ) ) {
				continue;
			}
			$bloques[] = array(
				'label'   => $label,
				'desde'   => $rango[0],
				'hasta'   => $rango[1],
				'cursor'  => Malka_CRM_Settings::to_minutes( $rango[0] ),
				'fin'     => Malka_CRM_Settings::to_minutes( $rango[1] ),
				'items'   => array(),
			);
		}

		if ( empty( $bloques ) ) {
			return array(
				'bloques'     => array(),
				'minutos'     => 0,
				'disponibles' => 0,
				'fuera'       => $candidatos,
				'sin_horario' => true,
			);
		}

		// Deja un 10% de holgura: ningún día sale exacto.
		$disponibles = Malka_CRM_Settings::minutes_per_day();
		$tope        = (int) floor( $disponibles * 0.9 );
		$usados      = 0;
		$fuera       = array();

		foreach ( $candidatos as $c ) {
			if ( $usados + $c['minutos'] > $tope ) {
				$fuera[] = $c;
				continue;
			}

			$colocado = false;
			foreach ( $bloques as $i => $b ) {
				if ( $b['cursor'] + $c['minutos'] <= $b['fin'] ) {
					$c['hora']               = Malka_CRM_Settings::to_hhmm( $b['cursor'] );
					$bloques[ $i ]['items'][] = $c;
					$bloques[ $i ]['cursor']  = $b['cursor'] + $c['minutos'] + 5;
					$usados                  += $c['minutos'];
					$colocado                 = true;
					break;
				}
			}

			if ( ! $colocado ) {
				$fuera[] = $c;
			}
		}

		return array(
			'bloques'     => $bloques,
			'minutos'     => $usados,
			'disponibles' => $disponibles,
			'fuera'       => $fuera,
			'sin_horario' => false,
		);
	}

	/**
	 * Candidatos a entrar en el plan, con su prioridad y duración estimada.
	 *
	 * @return array[]
	 */
	private static function candidates() {
		$out = array();

		// 1. Cobranza vencida: lo único que devuelve plata hoy.
		if ( class_exists( 'Malka_CRM_Quotes' ) ) {
			foreach ( self::overdue_payments() as $p ) {
				$out[] = array(
					'prioridad' => 1,
					'minutos'   => 15,
					'titulo'    => sprintf(
						/* translators: referencia al cliente o a la cotización */
						__( 'Cobrar %s', 'malka-crm' ),
						$p['referencia']
					),
					'motivo'    => sprintf(
						/* translators: 1: días de atraso, 2: monto */
						__( 'Lleva %1$d días vencido y la mora corre. Son %2$s que no han entrado.', 'malka-crm' ),
						$p['dias'],
						Malka_CRM_Settings::money( $p['monto'] )
					),
					'etiqueta'  => __( 'Primero', 'malka-crm' ),
					'nivel'     => 'alta',
					'monto'     => $p['monto'],
					'url'       => $p['url'],
					'tipo'      => 'cobro',
				);
			}
		}

		// 2. Tareas del estudio que vencen hoy o están atrasadas.
		foreach ( self::studio_tasks() as $t ) {
			$vence_hoy = ( $t['dias'] <= 0 );

			$out[] = array(
				'prioridad' => $vence_hoy ? 2 : ( $t['dias'] <= 3 ? 3 : 4 ),
				'minutos'   => $t['minutos'],
				'titulo'    => $t['titulo'],
				'motivo'    => $vence_hoy
					? __( 'Vence hoy. Si se pasa, el calendario se corre y arrastra las etapas siguientes.', 'malka-crm' )
					: sprintf(
						/* translators: días */
						_n( 'Vence en %d día.', 'Vence en %d días.', max( 1, $t['dias'] ), 'malka-crm' ),
						max( 1, $t['dias'] )
					),
				'etiqueta'  => $vence_hoy ? __( 'Vence hoy', 'malka-crm' ) : '',
				'nivel'     => $vence_hoy ? 'alta' : 'media',
				'monto'     => $t['monto'],
				'url'       => $t['url'],
				'tipo'      => 'tarea',
				'pid'       => isset( $t['pid'] ) ? $t['pid'] : 0,
				'tid'       => isset( $t['tid'] ) ? $t['tid'] : '',
			);
		}

		// 3. Facturas de compra que le toca emitir: sin ellas no hay crédito en el F29.
		if ( class_exists( 'Malka_CRM_Documents' ) ) {
			$n     = 0;
			$monto = 0;
			foreach ( Malka_CRM_Documents::all() as $d ) {
				if ( 'compra' === $d['sentido'] && 'pendiente' === $d['emito'] && 'anulada' !== $d['estado'] ) {
					$n++;
					$monto += (int) $d['base'];
				}
			}
			if ( $n ) {
				$out[] = array(
					'prioridad' => 5,
					'minutos'   => 10 * min( 3, $n ),
					/* translators: cantidad */
					'titulo'    => sprintf( _n( 'Emitir %d factura de compra', 'Emitir %d facturas de compra', $n, 'malka-crm' ), $n ),
					/* translators: monto */
					'motivo'    => sprintf( __( 'Suman %s. Mientras no las emitas, su IVA no entra como crédito en el F29.', 'malka-crm' ), Malka_CRM_Settings::money( $monto ) ),
					'etiqueta'  => '',
					'nivel'     => 'media',
					'monto'     => 0,
					'url'       => add_query_arg( 'ver', 'emitir', Malka_CRM_Dashboard::view_url( 'documentos' ) ),
				);
			}
		}

		// 4. Cierre administrativo del día.
		if ( class_exists( 'Malka_CRM_Finance' ) ) {
			$out[] = array(
				'prioridad' => 9,
				'minutos'   => 20,
				'titulo'    => __( 'Registrar los movimientos del día', 'malka-crm' ),
				'motivo'    => __( 'Al final, cuando ya no queda cabeza para nada creativo.', 'malka-crm' ),
				'etiqueta'  => '',
				'nivel'     => 'baja',
				'monto'     => 0,
				'url'       => Malka_CRM_Dashboard::view_url( 'movimientos' ),
			);
		}

		return $out;
	}

	/* ─────────────────────────────  Datos base  ───────────────────────── */

	/**
	 * Proyectos activos.
	 *
	 * @return int[]
	 */
	public static function active_projects() {
		$ids = get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);

		$out = array();
		foreach ( $ids as $id ) {
			if ( ! Malka_CRM_Helpers::is_project_finished( $id ) ) {
				$out[] = (int) $id;
			}
		}

		return $out;
	}

	/**
	 * Monto neto contratado de un proyecto, desde su cotización.
	 *
	 * @param int $project_id Proyecto.
	 */
	public static function project_net( $project_id ) {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return 0;
		}

		$quote_id = Malka_CRM_Quotes::get_project_quote_id( $project_id );
		if ( ! $quote_id ) {
			return 0;
		}

		$neto = (int) get_post_meta( $quote_id, '_malka_pm_subtotal', true );
		if ( $neto > 0 ) {
			return $neto;
		}

		$total = 0;
		foreach ( Malka_CRM_Quotes::get_payments( $quote_id ) as $p ) {
			// Sin el «Pago total»: es la suma de las otras, contarlo duplica.
			if ( Malka_CRM_Quotes::es_resumen( $quote_id, $p ) ) {
				continue;
			}
			$total += (int) $p['amount'];
		}

		$iva = (float) Malka_CRM_Settings::get( 'iva', 19 );
		return $iva > 0 ? (int) round( $total / ( 1 + ( $iva / 100 ) ) ) : $total;
	}

	/**
	 * Cuotas vencidas de todas las cotizaciones.
	 *
	 * @return array[]
	 */
	public static function overdue_payments() {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return array();
		}

		$quotes = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);

		$out = array();

		foreach ( $quotes as $qid ) {
			$cliente = get_post_meta( $qid, '_malka_pm_client_name', true );
			if ( ! $cliente ) {
				$uid     = (int) get_post_meta( $qid, '_malka_pm_client_id', true );
				$u       = $uid ? get_userdata( $uid ) : false;
				$cliente = $u ? $u->display_name : '';
			}

			// Frase lista para usar después de "Cobrar": con nombre queda
			// "Cobrar a María Pérez"; sin nombre, "Cobrar la N° 405".
			$numero = (string) get_post_meta( $qid, '_malka_pm_quote_number', true );
			if ( $cliente ) {
				/* translators: nombre del cliente */
				$referencia = sprintf( __( 'a %s', 'malka-crm' ), $cliente );
			} elseif ( '' !== $numero ) {
				/* translators: número de la cotización */
				$referencia = sprintf( __( 'la N° %s', 'malka-crm' ), $numero );
			} else {
				$referencia = __( 'esta cuota', 'malka-crm' );
			}

			foreach ( Malka_CRM_Quotes::get_payments( $qid ) as $p ) {
				// El «Pago total» es la alternativa de pagar todo junto, no una
				// deuda más: contarlo inflaba lo vencido (y el contador del menú).
				if ( Malka_CRM_Quotes::es_resumen( $qid, $p ) ) {
					continue;
				}
				$estado = Malka_CRM_Quote_Page::payment_view( $qid, $p );

				// Las dadas por perdidas no son cobranza pendiente: ni suman
				// al contador del menú ni aparecen en «Tu día».
				if ( 'overdue' !== $estado['state'] || ! empty( $estado['perdida'] ) ) {
					continue;
				}
				$out[] = array(
					'quote_id'   => (int) $qid,
					'cliente'    => $cliente,
					'referencia' => $referencia,
					'numero'     => $numero,
					'monto'    => (int) $p['amount'],
					'dias'     => abs( (int) $estado['days'] ),
					'concepto' => isset( $p['label'] ) ? $p['label'] : '',
					'url'      => self::url_cotizacion( $qid ),
				);
			}
		}

		return $out;
	}

	/**
	 * Tareas pendientes a cargo del estudio, ordenadas por vencimiento.
	 *
	 * @return array[]
	 */
	public static function studio_tasks() {
		$out   = array();
		$pomo  = max( 15, (int) Malka_CRM_Settings::get( 'pomodoro_min', 25 ) );

		foreach ( self::active_projects() as $pid ) {
			$neto = self::project_net( $pid );

			foreach ( Malka_CRM_Helpers::get_leaf_tasks( $pid ) as $t ) {
				if ( ! empty( $t['done'] ) || ( isset( $t['progress'] ) && 100 === (int) $t['progress'] ) ) {
					continue;
				}
				if ( ! Malka_CRM_Helpers::involves_studio( $t ) ) {
					continue;
				}

				$dias = Malka_CRM_Helpers::days_to_deadline( $t );
				if ( null === $dias || $dias > 7 ) {
					continue;
				}

				$out[] = array(
					'titulo'  => Malka_CRM_Helpers::display_name( $t ) . ' · ' . get_the_title( $pid ),
					'dias'    => (int) $dias,
					'minutos' => ( $dias <= 0 ) ? $pomo * 2 : $pomo,
					'monto'   => $neto,
					'url'     => self::url_negocio( $pid ),
					'pid'     => (int) $pid,
					'tid'     => isset( $t['id'] ) ? (string) $t['id'] : '',
				);
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $a['dias'] <=> $b['dias'];
			}
		);

		return array_slice( $out, 0, 6 );
	}

	/**
	 * Neto facturado por mes del año en curso, desde los movimientos de ingreso.
	 *
	 * @return array mes => neto
	 */
	/**
	 * Cómo va el embudo de cotizaciones.
	 *
	 * La tasa de cierre deja fuera las que siguen esperando respuesta: contarlas
	 * como perdidas daría un número más feo que la realidad.
	 *
	 * @return array
	 */
	public static function quote_stats() {
		$vacio = array(
			'total'     => 0,
			'abiertas'  => 0,
			'ganadas'   => 0,
			'perdidas'  => 0,
			'cerrando'  => 0,
			'monto_abierto' => 0,
			'monto_ganado'  => 0,
			'promedio'  => 0,
			'tasa'      => null,
			'por_vencer'=> array(),
		);

		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return $vacio;
		}

		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
			)
		);

		if ( empty( $ids ) ) {
			return $vacio;
		}

		// Entregado también es ganada: el trabajo se hizo y sólo falta cobrar.
		$ganadas  = array( 'aceptada', 'en_curso', 'entregado', 'finalizada' );
		$perdidas = array( 'rechazada', 'caducada' );

		$out = $vacio;
		$hoy = (int) strtotime( current_time( 'Y-m-d' ) );

		foreach ( $ids as $id ) {
			$estado = (string) get_post_meta( $id, '_malka_pm_quote_status', true );
			$total  = (int) get_post_meta( $id, '_malka_pm_quote_total', true );
			$vence  = (string) get_post_meta( $id, '_malka_pm_quote_expires', true );

			$out['total']++;

			if ( in_array( $estado, $ganadas, true ) ) {
				$out['ganadas']++;
				$out['monto_ganado'] += $total;
			} elseif ( in_array( $estado, $perdidas, true ) ) {
				$out['perdidas']++;
			} else {
				$out['abiertas']++;
				$out['monto_abierto'] += $total;

				// Sin respuesta y a punto de caducar: es la que hay que perseguir.
				if ( $vence ) {
					$dias = (int) floor( ( strtotime( $vence ) - $hoy ) / DAY_IN_SECONDS );
					if ( $dias >= 0 && $dias <= 5 ) {
						$out['por_vencer'][] = array(
							'id'     => (int) $id,
							'numero' => (string) get_post_meta( $id, '_malka_pm_quote_number', true ),
							'titulo' => get_the_title( $id ),
							'monto'  => $total,
							'dias'   => $dias,
						);
					}
				}
			}
		}

		$cerradas = $out['ganadas'] + $out['perdidas'];
		$out['cerrando'] = $cerradas;
		$out['tasa']     = $cerradas > 0 ? (int) round( ( $out['ganadas'] / $cerradas ) * 100 ) : null;
		$out['promedio'] = $out['ganadas'] > 0 ? (int) round( $out['monto_ganado'] / $out['ganadas'] ) : 0;

		usort(
			$out['por_vencer'],
			function ( $a, $b ) {
				return $a['dias'] <=> $b['dias'];
			}
		);

		return $out;
	}

	/**
	 * Todas las cotizaciones listas para pintar, con su cobranza resuelta.
	 *
	 * @return array[]
	 */
	public static function quote_rows() {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
			)
		);

		$estados = Malka_CRM_Quotes::quote_statuses();
		$hoy     = (int) strtotime( current_time( 'Y-m-d' ) );
		$out     = array();

		foreach ( $ids as $id ) {
			$estado = (string) get_post_meta( $id, '_malka_pm_quote_status', true );
			$vence  = (string) get_post_meta( $id, '_malka_pm_quote_expires', true );
			$total  = (int) get_post_meta( $id, '_malka_pm_quote_total', true );

			$pagado  = 0;
			$vencido = 0;
			$cuotas  = Malka_CRM_Quotes::get_payments( $id );

			foreach ( $cuotas as $c ) {
				if ( Malka_CRM_Quotes::es_resumen( $id, $c ) ) {
					continue;
				}
				$e = Malka_CRM_Quote_Page::payment_view( $id, $c );
				if ( 'paid' === $e['state'] ) {
					$pagado += (int) $c['amount'];
				} elseif ( 'overdue' === $e['state'] ) {
					$vencido += (int) $c['amount'];
				}
			}

			$dias = $vence ? (int) floor( ( strtotime( $vence ) - $hoy ) / DAY_IN_SECONDS ) : null;

			$out[] = array(
				'id'      => (int) $id,
				'numero'  => (string) get_post_meta( $id, '_malka_pm_quote_number', true ),
				'titulo'  => get_the_title( $id ),
				'cliente' => self::quote_client( $id ),
				'estado'  => $estado,
				'etiqueta'=> isset( $estados[ $estado ] ) ? $estados[ $estado ] : __( 'Sin respuesta', 'malka-crm' ),
				'total'   => $total,
				'pagado'  => $pagado,
				'vencido' => $vencido,
				'cuotas'  => count( $cuotas ),
				'emitida' => (string) get_post_meta( $id, '_malka_pm_quote_issued', true ),
				'vence'   => $vence,
				'dias'    => $dias,
				'pct'     => $total > 0 ? min( 100, (int) round( ( $pagado / $total ) * 100 ) ) : 0,
				'url'     => self::url_cotizacion( $id ),
				'publica' => class_exists( 'Malka_CRM_Quotes' ) ? Malka_CRM_Quotes::get_url( $id ) : '',
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( (string) $b['emitida'], (string) $a['emitida'] );
			}
		);

		return $out;
	}

	/**
	 * El nombre del cliente de una cotización.
	 *
	 * @param int $qid Cotización.
	 * @return string
	 */
	public static function quote_client( $qid ) {
		$nombre = (string) get_post_meta( $qid, '_malka_pm_client_name', true );
		if ( $nombre ) {
			return $nombre;
		}
		$uid = (int) get_post_meta( $qid, '_malka_pm_client_id', true );
		$u   = $uid ? get_userdata( $uid ) : false;
		return $u ? $u->display_name : '';
	}

	public static function monthly_net_series() {
		// Las ventas netas salen de las facturas emitidas, no del banco.
		$year  = (int) current_time( 'Y' );
		$meses = class_exists( 'Malka_CRM_Contabilidad' ) ? Malka_CRM_Contabilidad::meses() : array();
		$out   = array();

		for ( $m = 1; $m <= (int) current_time( 'n' ); $m++ ) {
			$k         = sprintf( '%04d-%02d', $year, $m );
			$out[ $m ] = isset( $meses[ $k ] ) ? (int) $meses[ $k ]['v_neto'] : 0;
		}

		return $out;
	}

	/**
	 * Los pagos de cotizaciones que entraron entre dos fechas.
	 *
	 * Una cuota pagada cuenta en la fecha en que se pagó; si se marcó
	 * pagada sin anotar el día (cuotas antiguas), en su vencimiento. Es la
	 * misma lista para Inicio y para Finanzas, así los dos dicen lo mismo.
	 *
	 * @param string $desde Y-m-d.
	 * @param string $hasta Y-m-d.
	 * @return array[] qid, numero, cliente, monto (con IVA), neto, fecha, rol, url.
	 */
	public static function pagos_recibidos( $desde, $hasta ) {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$iva   = (float) Malka_CRM_Settings::get( 'iva', 19 );
		$out   = array();

		foreach ( $ids as $id ) {
			foreach ( Malka_CRM_Quotes::get_payments( $id ) as $p ) {
				if ( Malka_CRM_Quotes::es_resumen( $id, $p ) || 'pagado' !== ( isset( $p['status'] ) ? $p['status'] : '' ) ) {
					continue;
				}
				$fecha = ! empty( $p['paid_at'] ) ? substr( (string) $p['paid_at'], 0, 10 ) : ( isset( $p['due'] ) ? (string) $p['due'] : '' );
				if ( ! $fecha || $fecha < $desde || $fecha > $hasta ) {
					continue;
				}
				$monto = (int) Malka_CRM_Quotes::cifra( isset( $p['amount'] ) ? $p['amount'] : 0 );
				$rol   = ! empty( $p['label'] ) ? (string) $p['label'] : ( isset( $p['rol'] ) && 'total' === $p['rol'] ? __( 'Total', 'malka-crm' ) : '' );
				$out[] = array(
					'qid'     => (int) $id,
					'numero'  => (string) get_post_meta( $id, '_malka_pm_quote_number', true ),
					'cliente' => self::quote_client( $id ),
					'monto'   => $monto,
					'neto'    => $iva > 0 ? (int) round( $monto / ( 1 + $iva / 100 ) ) : $monto,
					'fecha'   => $fecha,
					'rol'     => $rol,
					'url'     => self::url_cotizacion( $id ),
				);
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $b['fecha'], $a['fecha'] );
			}
		);

		return $out;
	}

	/**
	 * Lo cobrado entre dos fechas, sin IVA.
	 *
	 * @param string $desde Y-m-d.
	 * @param string $hasta Y-m-d.
	 * @return int
	 */
	public static function cobrado_neto( $desde, $hasta ) {
		$s = 0;
		foreach ( self::pagos_recibidos( $desde, $hasta ) as $p ) {
			$s += $p['neto'];
		}
		return $s;
	}

	/**
	 * Lo vendido en el mes, en neto: las cotizaciones con la firma completa
	 * (nombre, RUT y fecha), sea electrónica o cargada a mano en el panel.
	 * Cuenta en el mes de la firma, sin importar el estado que tenga hoy
	 * (en curso, entregada o finalizada); sólo las rechazadas no suman.
	 *
	 * @param int $year  Año.
	 * @param int $month Mes.
	 * @return int
	 */
	public static function cotizado_mes( $year, $month ) {
		$suma = 0;
		foreach ( self::firmadas_mes( $year, $month ) as $q ) {
			$suma += $q['neto'];
		}
		return $suma;
	}

	/**
	 * Las cotizaciones firmadas en un mes, con su neto.
	 *
	 * @param int $year  Año.
	 * @param int $month Mes.
	 * @return array[] id, numero, cliente, neto, fecha, url.
	 */
	public static function firmadas_mes( $year, $month ) {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return array();
		}
		$lista = array();
		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$iva   = (float) Malka_CRM_Settings::get( 'iva', 19 );
		$clave = sprintf( '%04d-%02d', $year, $month );

		foreach ( $ids as $id ) {
			if ( 'rechazada' === (string) get_post_meta( $id, '_malka_pm_quote_status', true ) ) {
				continue;
			}
			$firma = get_post_meta( $id, '_malka_pm_quote_sign', true );
			if ( ! is_array( $firma ) || empty( $firma['nombre'] ) || empty( $firma['rut'] ) || empty( $firma['fecha'] ) ) {
				continue;
			}
			if ( substr( (string) $firma['fecha'], 0, 7 ) !== $clave ) {
				continue;
			}

			// El neto sale de los ítems: el total guardado puede venir como
			// texto con puntos («476.000») en cotizaciones antiguas.
			$neto = 0;
			if ( class_exists( 'Malka_CRM_Quote_Page' ) ) {
				$dq   = Malka_CRM_Quote_Page::data( $id );
				$neto = isset( $dq['neto'] ) ? (int) $dq['neto'] : 0;
			}
			if ( $neto <= 0 ) {
				$total = (int) Malka_CRM_Quotes::cifra( get_post_meta( $id, '_malka_pm_quote_total', true ) );
				$neto  = $iva > 0 ? (int) round( $total / ( 1 + ( $iva / 100 ) ) ) : $total;
			}
			$lista[] = array(
				'id'      => (int) $id,
				'numero'  => (string) get_post_meta( $id, '_malka_pm_quote_number', true ),
				'cliente' => self::quote_client( $id ),
				'neto'    => $neto,
				'fecha'   => substr( (string) $firma['fecha'], 0, 10 ),
				'url'     => self::url_cotizacion( $id ),
			);
		}

		return $lista;
	}

	/**
	 * Resumen del objetivo del mes: ventas netas menos costos.
	 *
	 * @return array
	 */
	public static function month_goal() {
		$year  = (int) current_time( 'Y' );
		$month = (int) current_time( 'n' );
		$meta  = (int) Malka_CRM_Settings::get( 'sueldo_objetivo', 0 );
		$iva   = (float) Malka_CRM_Settings::get( 'iva', 19 );

		$netas  = self::cobrado_neto( sprintf( '%04d-%02d-01', $year, $month ), gmdate( 'Y-m-t', mktime( 0, 0, 0, $month, 1, $year ) ) );
		$costos = Malka_CRM_Settings::monthly_costs();
		$sueldo = $netas - $costos['monto'];

		$dias_mes    = (int) gmdate( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$dia_actual  = (int) current_time( 'j' );
		$restantes   = max( 0, $dias_mes - $dia_actual );

		return array(
			'completo'  => ( $meta > 0 ),
			'meta'      => $meta,
			'ventas'    => $netas,
			'costos'    => (int) $costos['monto'],
			'sueldo'    => $sueldo,
			'falta'     => max( 0, $meta - $sueldo ),
			'pct'       => $meta > 0 ? min( 100, max( 0, round( ( $sueldo / $meta ) * 100 ) ) ) : 0,
			'restantes' => $restantes,
			// Para comparar lo vendido con lo que lleva corrido el mes: ir al
			// 40% el día 5 y ir al 40% el día 25 no son la misma noticia.
			'dias_mes'  => $dias_mes,
			'dia'       => $dia_actual,
		);
	}
}
