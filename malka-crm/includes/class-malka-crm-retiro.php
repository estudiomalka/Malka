<?php
/**
 * Cuánto puede retirar Malka sin dejar la empresa corta.
 *
 * Durante el mes saca plata de la cuenta de la empresa a la suya, a veces
 * una vez y a veces varias. El problema no es el retiro: es que el saldo de
 * la cuenta no es suyo. Ahí adentro está el IVA, que nunca fue de ella, y
 * están los costos fijos que vienen la semana que viene.
 *
 * Este módulo contesta una sola pregunta —cuánto puede sacar hoy— y muestra
 * de dónde sale el número, porque un número sin su resta no se puede creer.
 *
 * La regla con el IVA es la que diría cualquier contadora: no se gasta. Se
 * cobra en nombre del SII y se entrega el 20 del mes siguiente. Por eso se
 * reserva el del período que está por declararse y también el que se viene
 * acumulando este mes.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * El cálculo del retiro disponible.
 */
class Malka_CRM_Retiro {

	/**
	 * Día del mes en que se declara y paga el F29.
	 *
	 * El SII extiende el plazo al 20 para quien factura electrónicamente y
	 * declara por internet, que es el caso del estudio.
	 */
	const DIA_F29 = 20;

	/**
	 * Cuánto se puede retirar hoy, con todo su desglose.
	 *
	 * @return array
	 */
	public static function calcular() {
		$hoy      = current_time( 'Y-m-d' );
		$dia      = (int) current_time( 'j' );
		$horizonte = max( 1, (int) Malka_CRM_Settings::get( 'retiro_horizonte', 30 ) );

		$saldo = class_exists( 'Malka_CRM_Finance' ) ? (int) Malka_CRM_Finance::balance() : 0;

		/* ── Lo que hay que pagar pronto ── */
		$costos = Malka_CRM_Settings::costs_due( $horizonte );
		$falta  = 0;

		foreach ( $costos as $c ) {
			$falta += (int) $c['clp'];
		}

		/* ── El IVA, que no es suyo ── */
		$iva = self::iva_pendiente();

		/* ── El colchón ── */
		$pct     = max( 0, (float) Malka_CRM_Settings::get( 'colchon', 10 ) );
		$colchon = (int) round( ( $falta + $iva['total'] ) * ( $pct / 100 ) );

		$reserva = $falta + $iva['total'] + $colchon;

		/* ── Lo que ya sacó este mes ── */
		$retirado = class_exists( 'Malka_CRM_Finance' )
			? (int) Malka_CRM_Finance::sum_category( 'retiro', (int) current_time( 'Y' ), (int) current_time( 'n' ) )
			: 0;

		$objetivo = (int) Malka_CRM_Settings::get( 'sueldo_objetivo', 0 );

		/*
		 * Si lo que falta para cubrir lo que viene lo sacó ella este mes,
		 * eso es lo que tiene que devolver a la cuenta de la empresa. Nunca
		 * más de lo que retiró: el resto del hueco no lo causó un retiro.
		 */
		$falta_r  = max( 0, $reserva - $saldo );
		$devolver = min( $falta_r, $retirado );

		return array(
			'saldo'      => $saldo,
			'costos'     => $falta,
			'detalle'    => $costos,
			'iva'        => $iva,
			'colchon'    => $colchon,
			'colchon_pct' => $pct,
			'reserva'    => $reserva,
			// Nunca negativo: lo que falta se dice aparte, no como un retiro
			// de signo contrario, que no significa nada.
			'disponible' => max( 0, $saldo - $reserva ),
			'falta'      => $falta_r,
			'devolver'   => $devolver,
			'retirado'   => $retirado,
			'objetivo'   => $objetivo,
			'restante'   => max( 0, $objetivo - $retirado ),
			'horizonte'  => $horizonte,
			'dia'        => $dia,
			'hoy'        => $hoy,
		);
	}

	/**
	 * El IVA que todavía no se entrega.
	 *
	 * Dos pedazos: el del período que está por declararse, si la fecha del
	 * F29 no ha pasado y no hay un pago de impuesto anotado; y el que se
	 * viene acumulando en el mes en curso, que se declara el mes siguiente.
	 *
	 * @return array {previo, previo_mes, previo_vence, curso, curso_mes, total, pagado}
	 */
	public static function iva_pendiente() {
		$out = array(
			'previo'       => 0,
			'previo_mes'   => '',
			'previo_vence' => '',
			'curso'        => 0,
			'curso_mes'    => current_time( 'Y-m' ),
			'total'        => 0,
			'pagado'       => false,
		);

		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return $out;
		}

		$dia  = (int) current_time( 'j' );
		$ini  = strtotime( current_time( 'Y-m' ) . '-01' );

		/* ── El período que está por declararse ── */
		$anterior           = gmdate( 'Y-m', strtotime( '-1 month', $ini ) );
		$out['previo_mes']  = $anterior;
		$out['previo_vence'] = current_time( 'Y-m' ) . '-' . str_pad( (string) self::DIA_F29, 2, '0', STR_PAD_LEFT );

		// Pasado el 20 se da por declarado: si no, el aviso quedaría puesto
		// para siempre y dejaría de significar algo.
		if ( $dia <= self::DIA_F29 ) {
			// El F29 completo: IVA con remanente, más las retenciones.
			$r = Malka_CRM_Contabilidad::mes( $anterior );

			// ¿Hay un pago de impuesto anotado este mes? Entonces ya lo pagó.
			$pagado = class_exists( 'Malka_CRM_Finance' )
				? (int) Malka_CRM_Finance::sum_category( 'impuesto', (int) current_time( 'Y' ), (int) current_time( 'n' ) )
				: 0;

			$out['pagado'] = ( $pagado > 0 );

			if ( ! $out['pagado'] ) {
				// El IVA que se declara es el débito menos el crédito de las
				// compras del mismo mes, no el bruto de las ventas.
				$out['previo'] = max( 0, (int) $r['total'] );
			}
		}

		/* ── Lo que se está acumulando ahora ── */
		$actual       = Malka_CRM_Contabilidad::mes( current_time( 'Y-m' ) );
		$out['curso'] = max( 0, (int) $actual['total'] );

		$out['total'] = $out['previo'] + $out['curso'];

		return $out;
	}

	/* ─────────────────────────────  Pantalla  ───────────────────────── */

	/**
	 * El panel del retiro, para Movimientos.
	 *
	 * @return string
	 */
	public static function render_panel() {
		$r = self::calcular();

		ob_start();
		echo '<section class="esc-panel esc-retiro">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Cuánto puedes retirar', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: días de horizonte */
				__( 'Lo que sobra después de dejar el IVA y los costos de los próximos %d días', 'malka-crm' ),
				$r['horizonte']
			)
		) . '</p></div>';

		printf(
			'<div class="esc-ph__r"><em class="%1$s">%2$s</em><span>%3$s</span></div></div>',
			$r['disponible'] > 0 ? '' : 'is-alert',
			esc_html( Malka_CRM_Settings::money( $r['disponible'] ) ),
			esc_html__( 'a tu cuenta personal', 'malka-crm' )
		);

		/*
		 * De acá para abajo, dos columnas: a la izquierda la resta —de dónde
		 * sale el número— y a la derecha lo que la explica —qué se paga
		 * pronto y cuánto lleva sacado—.
		 *
		 * Antes iba todo en una sola columna angosta, con el ancho del panel
		 * sobrando a la derecha: la pantalla se veía a medio llenar y había
		 * que bajar para encontrar lo que ya cabía al lado.
		 */
		echo '<div class="esc-retiro__g"><div class="esc-retiro__a">';

		/* ── La resta, a la vista ── */
		echo '<div class="esc-resta">';

		echo self::linea( __( 'Saldo de la cuenta', 'malka-crm' ), $r['saldo'], '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo self::linea( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			sprintf(
				/* translators: días */
				__( 'Costos fijos de los próximos %d días', 'malka-crm' ),
				$r['horizonte']
			),
			-$r['costos'],
			''
		);

		if ( $r['iva']['previo'] > 0 ) {
			echo self::linea( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				sprintf(
					/* translators: 1: mes que se declara, 2: fecha de pago */
					__( 'IVA de %1$s, se paga el %2$s', 'malka-crm' ),
					self::mes_largo( $r['iva']['previo_mes'] ),
					date_i18n( 'd/m', strtotime( $r['iva']['previo_vence'] ) )
				),
				-$r['iva']['previo'],
				''
			);
		}

		if ( $r['iva']['curso'] > 0 ) {
			echo self::linea( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				sprintf(
					/* translators: mes en curso */
					__( 'IVA que llevas de %s', 'malka-crm' ),
					self::mes_largo( $r['iva']['curso_mes'] )
				),
				-$r['iva']['curso'],
				''
			);
		}

		if ( $r['colchon'] > 0 ) {
			echo self::linea( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				sprintf(
					/* translators: porcentaje */
					__( 'Colchón del %s%%', 'malka-crm' ),
					$r['colchon_pct']
				),
				-$r['colchon'],
				''
			);
		}

		echo self::linea( __( 'Puedes retirar', 'malka-crm' ), $r['disponible'], 'is-total' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div>';

		/* ── El aviso, cuando no alcanza ── */
		// Lo que hay que devolver se dice una vez, en «Tus retiros», justo abajo.
		if ( $r['devolver'] <= 0 && $r['falta'] > 0 ) {
			printf(
				'<p class="esc-hint is-alert">%s</p>',
				esc_html(
					sprintf(
						/* translators: monto que falta */
						__( 'Hoy no puedes retirar nada: faltan %s para cubrir lo que viene. Si sacas plata ahora, algo de eso no se va a poder pagar.', 'malka-crm' ),
						Malka_CRM_Settings::money( $r['falta'] )
					)
				)
			);
		}

		if ( $r['iva']['pagado'] ) {
			printf(
				'<p class="esc-hint">%s</p>',
				esc_html__( 'El IVA del mes pasado ya aparece pagado en tus movimientos, así que no se reserva de nuevo.', 'malka-crm' )
			);
		}

		echo '</div><div class="esc-retiro__b">';

		/* ── Qué viene ── */
		if ( $r['detalle'] ) {
			echo '<div class="esc-resta__d">';
			printf( '<b>%s</b>', esc_html__( 'Lo que se paga pronto', 'malka-crm' ) );

			foreach ( $r['detalle'] as $c ) {
				printf(
					'<span><i>%1$s</i>%2$s<u>%3$s</u></span>',
					esc_html(
						0 === (int) $c['faltan']
							? __( 'hoy', 'malka-crm' )
							/* translators: días */
							: sprintf( _n( 'en %d día', 'en %d días', $c['faltan'], 'malka-crm' ), $c['faltan'] )
					),
					esc_html( $c['nombre'] ),
					esc_html( Malka_CRM_Settings::money( (int) $c['clp'] ) )
				);
			}

			echo '</div>';
		}

		/* ── Lo que ya sacó ── */
		echo '<div class="esc-resta__y">';

		// Con su rótulo: puesta debajo de «Lo que se paga pronto» y sin
		// nombre, la barra parecía el avance de esa lista.
		printf( '<b>%s</b>', esc_html__( 'Tu sueldo de este mes', 'malka-crm' ) );

		if ( $r['objetivo'] > 0 ) {
			$pct = min( 100, (int) round( ( $r['retirado'] / $r['objetivo'] ) * 100 ) );

			printf(
				'<span class="esc-bar"><i style="width:%d%%"></i></span>',
				$pct
			);
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: retirado, 2: objetivo, 3: lo que falta */
						__( 'Llevas %1$s retirados este mes, de tu sueldo objetivo de %2$s. Te faltan %3$s.', 'malka-crm' ),
						Malka_CRM_Settings::money( $r['retirado'] ),
						Malka_CRM_Settings::money( $r['objetivo'] ),
						Malka_CRM_Settings::money( $r['restante'] )
					)
				)
			);
		} else {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: monto retirado */
						__( 'Llevas %s retirados este mes. Pon tu sueldo objetivo en Configuración y acá te digo cuánto te falta.', 'malka-crm' ),
						Malka_CRM_Settings::money( $r['retirado'] )
					)
				)
			);
		}

		echo '</div></div></div>';

		printf(
			'<p class="esc-hint esc-retiro__pie">%s</p>',
			esc_html__( 'El IVA no se reserva por prudencia: se cobra en nombre del SII y nunca fue tuyo. Esto no cuenta tu impuesto a la renta, que se calcula en abril sobre el año completo.', 'malka-crm' )
		);

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * El aviso de lo que hay que devolver a la cuenta de la empresa.
	 *
	 * @param array $r Lo calculado.
	 * @return string
	 */
	public static function aviso_devolver( $r = null ) {
		$a = self::analisis( $r );
		if ( $a['devolver'] <= 0 ) {
			return '';
		}
		return sprintf(
			'<div class="esc-devolver"><b>%1$s</b><span>%2$s</span></div>',
			esc_html( sprintf( /* translators: monto */ __( 'Devuelve %s a la cuenta de la empresa', 'malka-crm' ), Malka_CRM_Settings::money( $a['devolver'] ) ) ),
			esc_html( $a['motivo'] )
		);
	}

	/**
	 * Los retiros del año contra lo que ganó el negocio.
	 *
	 * Mes a mes: lo que ganó el negocio (ventas netas menos compras, o
	 * menos los costos fijos si las compras anotadas son menos) y lo que
	 * ella sacó como retiro. Si en el año sacó más de lo ganado, la
	 * diferencia es lo que tiene que devolver. Y si este mes la cuenta no
	 * alcanza para el IVA y los costos que vienen, también: manda el mayor.
	 *
	 * @param array|null $r Lo calculado por calcular().
	 * @return array {meses, ganado, retirado, exceso, caja, devolver, puede, motivo}
	 */
	public static function analisis( $r = null ) {
		$r      = $r ? $r : self::calcular();
		$anio   = (int) current_time( 'Y' );
		$hasta  = (int) current_time( 'n' );
		$fijos  = (int) Malka_CRM_Settings::monthly_costs()['monto'];
		$todos  = class_exists( 'Malka_CRM_Contabilidad' ) ? Malka_CRM_Contabilidad::meses() : array();
		$meses  = array();
		$ganado = 0;
		$sacado = 0;

		for ( $m = 1; $m <= $hasta; $m++ ) {
			$k   = sprintf( '%04d-%02d', $anio, $m );
			$v   = isset( $todos[ $k ] ) ? (int) $todos[ $k ]['v_neto'] : 0;
			$c   = isset( $todos[ $k ] ) ? (int) $todos[ $k ]['c_neto'] : 0;
			$ret = class_exists( 'Malka_CRM_Finance' ) ? (int) Malka_CRM_Finance::sum_category( 'retiro', $anio, $m ) : 0;
			if ( ! $v && ! $c && ! $ret ) {
				continue;
			}
			$g       = $v - max( $c, $fijos );
			$ganado += $g;
			$sacado += $ret;
			$meses[ $k ] = array(
				'ventas'   => $v,
				'gastos'   => max( $c, $fijos ),
				'ganado'   => $g,
				'retirado' => $ret,
				'acum'     => $ganado - $sacado,
			);
		}

		$exceso = max( 0, $sacado - max( 0, $ganado ) );
		$caja   = (int) $r['devolver'];
		$dev    = max( $exceso, $caja );

		if ( $dev <= 0 ) {
			$motivo = '';
		} elseif ( $exceso >= $caja ) {
			$motivo = sprintf(
				/* translators: 1: año, 2: retirado, 3: ganado */
				__( 'En %1$d retiraste %2$s y el negocio ganó %3$s. Devolver la diferencia deja las finanzas sanas.', 'malka-crm' ),
				$anio,
				Malka_CRM_Settings::money( $sacado ),
				Malka_CRM_Settings::money( max( 0, $ganado ) )
			);
		} else {
			// Con los números a la vista: lo que hay, lo que hay que guardar y de qué.
			$partes = array();
			if ( ! empty( $r['iva']['previo'] ) ) {
				/* translators: 1: mes, 2: monto */
				$partes[] = sprintf( __( 'F29 de %1$s %2$s', 'malka-crm' ), self::mes_largo( $r['iva']['previo_mes'] ), Malka_CRM_Settings::money( $r['iva']['previo'] ) );
			}
			if ( ! empty( $r['iva']['curso'] ) ) {
				/* translators: 1: mes, 2: monto */
				$partes[] = sprintf( __( 'IVA que llevas de %1$s %2$s', 'malka-crm' ), self::mes_largo( $r['iva']['curso_mes'] ), Malka_CRM_Settings::money( $r['iva']['curso'] ) );
			}
			if ( ! empty( $r['costos'] ) ) {
				/* translators: monto */
				$partes[] = sprintf( __( 'costos fijos %s', 'malka-crm' ), Malka_CRM_Settings::money( $r['costos'] ) );
			}
			if ( ! empty( $r['colchon'] ) ) {
				/* translators: monto */
				$partes[] = sprintf( __( 'colchón %s', 'malka-crm' ), Malka_CRM_Settings::money( $r['colchon'] ) );
			}
			$motivo = sprintf(
				/* translators: 1: saldo, 2: reserva, 3: desglose, 4: retirado */
				__( 'Tienes %1$s en la cuenta y necesitas guardar %2$s para lo que viene (%3$s). Este mes retiraste %4$s.', 'malka-crm' ),
				Malka_CRM_Settings::money( $r['saldo'] ),
				Malka_CRM_Settings::money( $r['reserva'] ),
				implode( ', ', $partes ),
				Malka_CRM_Settings::money( $r['retirado'] )
			);
		}

		return array(
			'anio'     => $anio,
			'meses'    => $meses,
			'ganado'   => $ganado,
			'retirado' => $sacado,
			'exceso'   => $exceso,
			'caja'     => $caja,
			'devolver' => $dev,
			'puede'    => $dev > 0 ? 0 : min( max( 0, $ganado - $sacado ), (int) $r['disponible'] ),
			'motivo'   => $motivo,
		);
	}

	/**
	 * El cuadro «Tus retiros»: año completo, mes a mes.
	 *
	 * @param bool $corto En Inicio, sin la tabla completa.
	 * @return string
	 */
	public static function render_analisis( $corto = false ) {
		$a = self::analisis();

		ob_start();
		echo '<section class="esc-panel esc-ret2">';
		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'peso',
			__( 'Tus retiros', 'malka-crm' ),
			/* translators: año */
			sprintf( __( 'Lo que sacaste en %d contra lo que ganó el negocio', 'malka-crm' ), $a['anio'] )
		);

		if ( ! $a['meses'] ) {
			echo '<p class="esc-empty">' . esc_html__( 'Importa tus facturas y la cartola del banco para ver tus retiros contra lo que gana el negocio.', 'malka-crm' ) . '</p></section>';
			return ob_get_clean();
		}

		if ( $a['devolver'] > 0 ) {
			printf(
				'<div class="esc-ret2__v is-mal"><small>%1$s</small><b>%2$s</b><span>%3$s</span></div>',
				esc_html__( 'Tienes que devolver', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $a['devolver'] ) ),
				esc_html( $a['motivo'] )
			);
		} else {
			printf(
				'<div class="esc-ret2__v is-bien"><small>%1$s</small><b>%2$s</b><span>%3$s</span></div>',
				esc_html__( 'Vas bien', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $a['puede'] ) ),
				esc_html__( 'Es lo que todavía puedes retirar sin dejar al negocio corto.', 'malka-crm' )
			);
		}

		printf(
			'<div class="esc-ret2__k"><span><small>%1$s</small><b>%2$s</b></span><span><small>%3$s</small><b>%4$s</b></span><span><small>%5$s</small><b class="%7$s">%6$s</b></span></div>',
			esc_html__( 'Ganó el negocio', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $a['ganado'] ) ),
			esc_html__( 'Retiraste', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $a['retirado'] ) ),
			esc_html__( 'Diferencia', 'malka-crm' ),
			esc_html( ( $a['ganado'] - $a['retirado'] < 0 ? '−' : '' ) . Malka_CRM_Settings::money( abs( $a['ganado'] - $a['retirado'] ) ) ),
			$a['ganado'] - $a['retirado'] < 0 ? 'is-mal' : ''
		);

		if ( ! $corto ) {
			echo '<div class="esc-ret2__t">';
			printf(
				'<div class="esc-ret2__r is-h"><span>%1$s</span><span>%2$s</span><span>%3$s</span><span>%4$s</span><span>%5$s</span></div>',
				esc_html__( 'Mes', 'malka-crm' ),
				esc_html__( 'Ventas netas', 'malka-crm' ),
				esc_html__( 'Gastos', 'malka-crm' ),
				esc_html__( 'Ganó', 'malka-crm' ),
				esc_html__( 'Retiraste', 'malka-crm' )
			);
			foreach ( array_reverse( $a['meses'], true ) as $k => $m ) {
				printf(
					'<div class="esc-ret2__r"><span>%1$s</span><span>%2$s</span><span>−%3$s</span><span class="%6$s">%4$s</span><span class="%7$s">%5$s</span></div>',
					esc_html( ucfirst( date_i18n( 'F', strtotime( $k . '-01' ) ) ) ),
					esc_html( Malka_CRM_Settings::money( $m['ventas'] ) ),
					esc_html( Malka_CRM_Settings::money( $m['gastos'] ) ),
					esc_html( ( $m['ganado'] < 0 ? '−' : '' ) . Malka_CRM_Settings::money( abs( $m['ganado'] ) ) ),
					esc_html( $m['retirado'] ? Malka_CRM_Settings::money( $m['retirado'] ) : '—' ),
					$m['ganado'] < 0 ? 'is-mal' : '',
					$m['retirado'] > max( 0, $m['ganado'] ) ? 'is-mal' : ''
				);
			}
			echo '</div>';
			echo '<p class="esc-hint">' . esc_html__( 'Ganó = ventas netas menos compras con factura (o tus costos fijos, si son más). Los retiros son las transferencias a tu nombre marcadas como «Retiro personal». No incluye el impuesto a la renta de abril.', 'malka-crm' ) . '</p>';
		} else {
			printf( '<a class="esc-obs__a" href="%1$s">%2$s →</a>', esc_url( Malka_CRM_Dashboard::view_url( 'movimientos' ) ), esc_html__( 'Ver mes a mes', 'malka-crm' ) );
		}

		echo '</section>';
		return ob_get_clean();
	}

	/**
	 * Para el mosaico de Inicio.
	 *
	 * @return string
	 */
	public static function panel_inicio() {
		return self::render_analisis( true );
	}

	/**
	 * Una línea de la resta.
	 *
	 * @param string $texto Qué es.
	 * @param int    $monto Monto, negativo si resta.
	 * @param string $clase Clase extra.
	 * @return string
	 */
	private static function linea( $texto, $monto, $clase = '' ) {
		return sprintf(
			'<span class="esc-resta__r %1$s"><b>%2$s</b><u>%3$s</u></span>',
			esc_attr( $clase ),
			esc_html( $texto ),
			esc_html( Malka_CRM_Settings::money( (int) $monto ) )
		);
	}

	/**
	 * «2026-09» como «septiembre».
	 *
	 * @param string $mes Y-m.
	 * @return string
	 */
	private static function mes_largo( $mes ) {
		$t = strtotime( $mes . '-01' );

		return $t ? date_i18n( 'F', $t ) : $mes;
	}
}
