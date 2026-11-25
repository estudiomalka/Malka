<?php
/**
 * Contabilidad: el F29 de cada mes, sacado de los documentos anotados.
 *
 * Lo que se declara y paga el 20 del mes siguiente es:
 *
 *   IVA de las ventas (débito)
 * − IVA de las compras (crédito)
 * − remanente de crédito que sobró del mes anterior
 * = IVA a pagar (si da negativo, no se paga: pasa como remanente)
 * + retenciones que hizo ella (boletas de honorarios recibidas y el IVA
 *   que retiene al emitir facturas de compra)
 *
 * Es una estimación con lo que hay en la plataforma: no reemplaza a la
 * contadora, pero le dice con tiempo cuánto va a ser y si ya lo pagó.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * El IVA mes a mes.
 */
class Malka_CRM_Contabilidad {

	/**
	 * Lo calculado en esta carga, por mes.
	 *
	 * @var array|null
	 */
	private static $meses = null;

	/**
	 * Todos los meses con documentos, hasta el actual, con su F29.
	 *
	 * El remanente pasa de un mes al siguiente, así que se calcula desde el
	 * primer mes con documentos, en orden.
	 *
	 * @return array Y-m => fila.
	 */
	public static function meses() {
		if ( null !== self::$meses ) {
			return self::$meses;
		}

		$base = array();
		$docs = class_exists( 'Malka_CRM_Documents' ) ? Malka_CRM_Documents::all() : array();

		foreach ( $docs as $d ) {
			if ( 'anulada' === $d['estado'] ) {
				continue;
			}
			// Se declara en el mes del libro si se lo asignó; si no, una
			// factura de compra en el mes en que ella la emite, y el resto
			// en el de su fecha.
			$fecha = ( '' !== $d['emito'] && ! empty( $d['fecha_mia'] ) ) ? $d['fecha_mia'] : $d['fecha'];
			if ( ! empty( $d['periodo'] ) ) {
				$fecha = $d['periodo'] . '-01';
			}
			if ( ! $fecha ) {
				continue;
			}
			$mes = substr( $fecha, 0, 7 );
			if ( ! isset( $base[ $mes ] ) ) {
				$base[ $mes ] = self::vacio( $mes );
			}
			$f = &$base[ $mes ];
			$s = (int) $d['signo'];
			self::casilla( $f, $d );

			if ( 'compra' === $d['sentido'] ) {
				$f['c_neto']    += $s * (int) $d['neto'];
				$f['credito']   += $s * (int) $d['iva'];
				$f['c_cuantos']++;
				if ( 'honorarios' === $d['tipo'] ) {
					// La boleta de honorarios que le hacen a ella: retiene y paga.
					$f['ret_hon'] += $s * (int) $d['retencion'];
				}
				if ( '' !== $d['emito'] ) {
					// Factura de compra: retiene el IVA completo y lo entrega.
					$f['ret_iva'] += $s * (int) $d['iva'];
				}
			} else {
				$f['v_neto']   += $s * (int) $d['neto'];
				$f['debito']   += $s * (int) $d['iva'];
				$f['v_cuantos']++;
			}
			unset( $f );
		}

		$hoy = current_time( 'Y-m' );
		if ( ! isset( $base[ $hoy ] ) ) {
			$base[ $hoy ] = self::vacio( $hoy );
		}
		ksort( $base );

		$tasa     = (float) Malka_CRM_Settings::get( 'ppm', 0 );
		$f29s     = self::declarados();

		// Se rellenan los meses sin documentos para que el remanente no salte.
		$out  = array();
		$mes  = array_key_first( $base );
		$rem  = 0;
		$tope = 0;
		while ( $mes <= $hoy && $tope < 600 ) {
			$f = isset( $base[ $mes ] ) ? $base[ $mes ] : self::vacio( $mes );

			$f['remanente_ant'] = $rem;
			$neto               = $f['debito'] - $f['credito'] - $rem;
			$f['iva']           = max( 0, $neto );
			$f['remanente']     = max( 0, -$neto );
			$f['retenciones']   = $f['ret_hon'] + $f['ret_iva'];
			// PPM: un porcentaje de las ventas netas del mes.
			$f['ppm']           = (int) round( max( 0, $f['v_neto'] ) * $tasa / 100 );
			$f['total']         = $f['iva'] + $f['retenciones'] + $f['ppm'];
			$f['estimado']      = $f['total'];
			$f['declarado']     = false;

			// Si está el F29 que preparó la contadora, manda ese: y su
			// remanente es el que pasa al mes siguiente.
			if ( isset( $f29s[ $mes ] ) ) {
				$x                  = $f29s[ $mes ];
				$f['declarado']     = true;
				$f['debito']        = (int) $x['debito'];
				$f['credito']       = max( 0, (int) $x['credito'] - (int) $x['rem_ant'] );
				$f['remanente_ant'] = (int) $x['rem_ant'];
				$f['iva']           = (int) $x['iva'];
				$f['remanente']     = (int) $x['rem_sig'];
				$f['ppm']           = (int) $x['ppm'];
				$f['tasa']          = (float) $x['tasa'];
				$f['retenciones']   = max( 0, (int) $x['total'] - (int) $x['iva'] - (int) $x['ppm'] );
				$f['total']         = (int) $x['total'];
			}
			$f['vence']         = gmdate( 'Y-m', strtotime( $mes . '-01 +1 month' ) ) . '-' . str_pad( (string) Malka_CRM_Retiro::DIA_F29, 2, '0', STR_PAD_LEFT );
			$f['pagado']        = self::pagado_en( gmdate( 'Y-m', strtotime( $mes . '-01 +1 month' ) ) );
			$f['estado']        = self::estado( $f, $hoy );

			$rem         = $f['remanente'];
			$out[ $mes ] = $f;
			$mes         = gmdate( 'Y-m', strtotime( $mes . '-01 +1 month' ) );
			$tope++;
		}

		self::$meses = $out;
		return $out;
	}

	/**
	 * Los F29 importados, por mes.
	 *
	 * @return array Y-m => {debito, credito, rem_ant, iva, rem_sig, ppm, tasa, total}
	 */
	public static function declarados() {
		$v = get_option( 'malka_crm_f29', array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Guarda los F29 leídos en el importador.
	 */
	public static function ajax_guardar() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}
		check_ajax_referer( 'malka_crm_doc_importar', 'nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$lista = isset( $_POST['f29'] ) ? json_decode( wp_unslash( $_POST['f29'] ), true ) : array();
		$todo  = self::declarados();
		$n     = 0;
		$tasa  = null;
		foreach ( (array) $lista as $x ) {
			$mes = isset( $x['mes'] ) ? (string) $x['mes'] : '';
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $mes ) ) {
				continue;
			}
			$fila = array();
			foreach ( array( 'debito', 'credito', 'rem_ant', 'iva', 'rem_sig', 'ppm', 'total' ) as $k ) {
				$fila[ $k ] = isset( $x[ $k ] ) ? (int) $x[ $k ] : 0;
			}
			$fila['tasa'] = isset( $x['tasa'] ) ? (float) $x['tasa'] : 0;
			$todo[ $mes ] = $fila;
			$n++;
			if ( $fila['tasa'] > 0 && ( null === $tasa || $mes > $tasa[0] ) ) {
				$tasa = array( $mes, $fila['tasa'] );
			}
		}
		ksort( $todo );
		update_option( 'malka_crm_f29', $todo, false );

		// La tasa de PPM del F29 más nuevo queda como la de los meses que vienen.
		if ( $tasa ) {
			$s        = get_option( Malka_CRM_Settings::OPTION, array() );
			$s        = is_array( $s ) ? $s : array();
			$s['ppm'] = $tasa[1];
			update_option( Malka_CRM_Settings::OPTION, $s );
		}
		wp_send_json_success( array( 'guardados' => $n ) );
	}

	/**
	 * El F29 de un mes.
	 *
	 * @param string $mes Y-m.
	 * @return array
	 */
	public static function mes( $mes ) {
		$m = self::meses();
		return isset( $m[ $mes ] ) ? $m[ $mes ] : self::vacio( $mes );
	}

	/**
	 * Suma un documento en las casillas del F29 que le corresponden.
	 *
	 * @param array $f Fila del mes (por referencia).
	 * @param array $d Documento.
	 */
	private static function casilla( &$f, $d ) {
		// Un documento en cero (p. ej. una exenta de $0 del banco) no se declara.
		if ( 0 === (int) $d['total'] && 0 === (int) $d['neto'] ) {
			return;
		}
		$c   = &$f['cas'];
		$iva = abs( (int) $d['iva'] );
		$net = abs( (int) $d['neto'] );
		$sum = function ( $cant, $monto, $v ) use ( &$c ) {
			$c[ $cant ] = ( isset( $c[ $cant ] ) ? $c[ $cant ] : 0 ) + 1;
			$c[ $monto ] = ( isset( $c[ $monto ] ) ? $c[ $monto ] : 0 ) + $v;
		};
		if ( 'compra' !== $d['sentido'] ) {
			switch ( $d['tipo'] ) {
				case 'boleta':
					$sum( 110, 111, $iva );
					break;
				case 'nota':
					$sum( 509, 510, $iva );
					break;
				case 'exenta':
				case 'honorarios':
					$sum( 586, 142, $net );
					break;
				default:
					$sum( 503, 502, $iva );
			}
			return;
		}
		if ( '' !== $d['emito'] ) {
			// Factura de compra que emite ella: da crédito y retiene el IVA.
			$sum( 519, 520, $iva );
			$c[39] = ( isset( $c[39] ) ? $c[39] : 0 ) + $iva;
			return;
		}
		switch ( $d['tipo'] ) {
			case 'nota':
				$sum( 527, 528, $iva );
				break;
			case 'exenta':
				$sum( 584, 562, $net );
				break;
			case 'honorarios':
				$c[151] = ( isset( $c[151] ) ? $c[151] : 0 ) + abs( (int) $d['retencion'] );
				break;
			case 'boleta':
				// Una boleta recibida no da crédito fiscal.
				$sum( 564, 521, $net );
				break;
			default:
				$sum( 519, 520, $iva );
		}
	}

	/**
	 * Las casillas del F29 de un mes, con las del resultado.
	 *
	 * @param array $f Fila del mes.
	 * @return array código => valor.
	 */
	public static function casillas( $f ) {
		$c        = isset( $f['cas'] ) ? $f['cas'] : array();
		$c[538]   = $f['debito'];
		$c[504]   = $f['remanente_ant'];
		$c[537]   = $f['credito'] + $f['remanente_ant'];
		$c[89]    = $f['iva'];
		$c[77]    = $f['remanente'];
		$c[563]   = max( 0, $f['v_neto'] );
		$c[115]   = (float) ( isset( $f['tasa'] ) ? $f['tasa'] : Malka_CRM_Settings::get( 'ppm', 0 ) );
		$c[62]    = $f['ppm'];
		$c[595]   = $f['total'];
		return $c;
	}

	/**
	 * El F29 de un mes casilla por casilla, con los códigos del formulario,
	 * para declararlo en sii.cl o compararlo con la propuesta del SII.
	 *
	 * @param array $f Fila del mes.
	 * @return string
	 */
	public static function render_casillas( $f ) {
		$c   = self::casillas( $f );
		$v   = function ( $k ) use ( $c ) {
			return isset( $c[ $k ] ) ? $c[ $k ] : 0;
		};
		$m   = function ( $k ) use ( $v ) {
			return Malka_CRM_Settings::money( (int) $v( $k ) );
		};
		$grupos = array(
			__( 'Débitos y ventas', 'malka-crm' ) => array(
				array( '586 · 142', __( 'Ventas exentas o no gravadas (cant. · neto)', 'malka-crm' ), $v( 586 ) . ' · ' . $m( 142 ), $v( 586 ) ),
				array( '503 · 502', __( 'Facturas emitidas del giro (cant. · débito)', 'malka-crm' ), $v( 503 ) . ' · ' . $m( 502 ), $v( 503 ) ),
				array( '110 · 111', __( 'Boletas (cant. · débito)', 'malka-crm' ), $v( 110 ) . ' · ' . $m( 111 ), $v( 110 ) ),
				array( '509 · 510', __( 'Notas de crédito emitidas (cant. · débito que resta)', 'malka-crm' ), $v( 509 ) . ' · −' . $m( 510 ), $v( 509 ) ),
				array( '538', __( 'Total débitos', 'malka-crm' ), $m( 538 ), 1, true ),
			),
			__( 'Créditos y compras', 'malka-crm' ) => array(
				array( '564 · 521', __( 'Compras sin derecho a crédito (cant. · neto)', 'malka-crm' ), $v( 564 ) . ' · ' . $m( 521 ), $v( 564 ) ),
				array( '584 · 562', __( 'Compras exentas o no gravadas (cant. · neto)', 'malka-crm' ), $v( 584 ) . ' · ' . $m( 562 ), $v( 584 ) ),
				array( '519 · 520', __( 'Facturas recibidas y de compra emitidas (cant. · crédito)', 'malka-crm' ), $v( 519 ) . ' · ' . $m( 520 ), $v( 519 ) ),
				array( '527 · 528', __( 'Notas de crédito recibidas (cant. · crédito que resta)', 'malka-crm' ), $v( 527 ) . ' · −' . $m( 528 ), $v( 527 ) ),
				array( '504', __( 'Remanente crédito fiscal mes anterior', 'malka-crm' ), $m( 504 ), $v( 504 ) ),
				array( '537', __( 'Total créditos', 'malka-crm' ), $m( 537 ), 1, true ),
			),
			__( 'Impuesto', 'malka-crm' ) => array(
				array( '89', __( 'IVA determinado', 'malka-crm' ), $m( 89 ), 1 ),
				array( '77', __( 'Remanente de crédito para el período siguiente', 'malka-crm' ), $m( 77 ), $v( 77 ) ),
				array( '151', __( 'Retención de honorarios (Art. 42 N°2)', 'malka-crm' ), $m( 151 ), $v( 151 ) ),
				array( '39', __( 'IVA total retenido a terceros (facturas de compra)', 'malka-crm' ), $m( 39 ), $v( 39 ) ),
				array( '563 · 115 · 62', __( 'PPM: base · tasa · monto', 'malka-crm' ), $m( 563 ) . ' · ' . str_replace( '.', ',', (string) $v( 115 ) ) . '% · ' . $m( 62 ), $v( 62 ) ),
				array( '595', __( 'Total a pagar', 'malka-crm' ), $m( 595 ), 1, true ),
			),
		);
		$out = '<div class="esc-cas">';
		foreach ( $grupos as $titulo => $filas ) {
			$out .= '<div class="esc-cas__g"><p class="esc-cas__h">' . esc_html( $titulo ) . '</p>';
			foreach ( $filas as $r ) {
				if ( empty( $r[3] ) ) {
					continue;
				}
				$out .= sprintf(
					'<div class="esc-cas__r%1$s"><code>%2$s</code><span>%3$s</span><b>%4$s</b></div>',
					empty( $r[4] ) ? '' : ' is-total',
					esc_html( $r[0] ),
					esc_html( $r[1] ),
					esc_html( $r[2] )
				);
			}
			$out .= '</div>';
		}
		$out .= '</div>';
		if ( ! empty( $f['declarado'] ) ) {
			$out .= '<p class="esc-cas__n">' . esc_html__( 'Los totales vienen del F29 importado; las cantidades y montos por tipo, de los documentos anotados.', 'malka-crm' ) . '</p>';
		}
		return $out;
	}

	/**
	 * El detalle de un mes, en simple: la cuenta del F29 de arriba abajo
	 * y, al lado, de dónde salen los números. Las casillas del formulario
	 * quedan plegadas para el día de declarar.
	 *
	 * @param array $f Fila del mes.
	 * @return string
	 */
	public static function render_detalle( $f ) {
		$m     = function ( $n ) {
			return Malka_CRM_Settings::money( abs( (int) $n ) );
		};
		$tasa  = isset( $f['tasa'] ) ? (float) $f['tasa'] : (float) Malka_CRM_Settings::get( 'ppm', 0 );
		$lin   = array(
			array( __( 'IVA de tus ventas', 'malka-crm' ), $m( $f['debito'] ), '' ),
			array( __( 'IVA de tus compras', 'malka-crm' ), $f['credito'] ? '−' . $m( $f['credito'] ) : '', '' ),
			array( __( 'Remanente del mes anterior', 'malka-crm' ), $f['remanente_ant'] ? '−' . $m( $f['remanente_ant'] ) : '', '' ),
			array( __( 'IVA a pagar', 'malka-crm' ), $m( $f['iva'] ), 'is-sub' ),
			array( __( 'Retenciones', 'malka-crm' ), $f['retenciones'] ? '+' . $m( $f['retenciones'] ) : '', '' ),
			/* translators: tasa */
			array( sprintf( __( 'PPM (%s%% de las ventas)', 'malka-crm' ), str_replace( '.', ',', (string) $tasa ) ), $f['ppm'] ? '+' . $m( $f['ppm'] ) : '', '' ),
			array( __( 'Total a pagar', 'malka-crm' ), $m( $f['total'] ), 'is-total' ),
		);
		$out = '<div class="esc-ctd"><div class="esc-ctd__cuenta">';
		foreach ( $lin as $l ) {
			if ( '' === $l[1] ) {
				continue;
			}
			$out .= sprintf( '<span class="%3$s"><b>%1$s</b><u>%2$s</u></span>', esc_html( $l[0] ), esc_html( $l[1] ), esc_attr( $l[2] ) );
		}
		if ( $f['remanente'] ) {
			/* translators: monto */
			$out .= '<span class="is-nota">' . esc_html( sprintf( __( 'Sobran %s de crédito que pasan al mes siguiente.', 'malka-crm' ), $m( $f['remanente'] ) ) ) . '</span>';
		}
		$out .= '</div><div class="esc-ctd__de">';
		$out .= sprintf(
			'<div><small>%1$s</small><b>%2$s</b><span>%3$s</span></div>',
			esc_html__( 'Ventas netas', 'malka-crm' ),
			esc_html( $m( $f['v_neto'] ) ),
			esc_html( sprintf( /* translators: cantidad */ _n( '%d documento', '%d documentos', (int) $f['v_cuantos'], 'malka-crm' ), (int) $f['v_cuantos'] ) )
		);
		$out .= sprintf(
			'<div><small>%1$s</small><b>%2$s</b><span>%3$s</span></div>',
			esc_html__( 'Compras netas', 'malka-crm' ),
			esc_html( $m( $f['c_neto'] ) ),
			esc_html( sprintf( /* translators: cantidad */ _n( '%d documento', '%d documentos', (int) $f['c_cuantos'], 'malka-crm' ), (int) $f['c_cuantos'] ) )
		);
		$out .= sprintf(
			'<a class="esc-ctd__ver" href="%1$s">%2$s →</a>',
			esc_url( add_query_arg( 'mes', $f['mes'], Malka_CRM_Dashboard::view_url( 'documentos' ) ) ),
			esc_html__( 'Ver los documentos del mes', 'malka-crm' )
		);
		$out .= '</div></div>';
		$out .= '<details class="esc-ctd__cas"><summary>' . esc_html__( 'Casillas para declarar en sii.cl', 'malka-crm' ) . '</summary>' . self::render_casillas( $f ) . '</details>';
		return $out;
	}

	/**
	 * Los próximos vencimientos tributarios.
	 *
	 * @return string
	 */
	public static function calendario() {
		$hoy   = current_time( 'Y-m-d' );
		$anio  = (int) substr( $hoy, 0, 4 );
		$items = array();

		// El F29: del mes pasado si no ha vencido; si ya pasó el 20, el de este mes.
		$ant  = gmdate( 'Y-m', strtotime( substr( $hoy, 0, 7 ) . '-01 -1 month' ) );
		$f    = self::mes( $ant );
		if ( $hoy > $f['vence'] || 'pagado' === $f['estado'] ) {
			$f = self::mes( substr( $hoy, 0, 7 ) );
		}
		$items[] = array(
			$f['vence'],
			/* translators: mes */
			sprintf( __( 'F29 de %s', 'malka-crm' ), date_i18n( 'F', strtotime( $f['mes'] . '-01' ) ) ),
			'pagado' === $f['estado'] ? __( 'Pagado', 'malka-crm' ) : Malka_CRM_Settings::money( $f['total'] ),
		);

		// Declaraciones juradas en marzo y la renta en abril, del año que viene si ya pasaron.
		$a_dj  = $hoy > $anio . '-03-31' ? $anio + 1 : $anio;
		$a_f22 = $hoy > $anio . '-04-30' ? $anio + 1 : $anio;
		$ret   = 0;
		foreach ( self::meses() as $mes => $x ) {
			if ( (int) substr( $mes, 0, 4 ) === $a_dj - 1 ) {
				$ret += (int) $x['ret_hon'];
			}
		}
		if ( $ret > 0 ) {
			/* translators: año */
			$items[] = array( $a_dj . '-03-15', __( 'DJ 1879: honorarios que retuviste', 'malka-crm' ), sprintf( __( 'Marzo %d', 'malka-crm' ), $a_dj ) );
		}
		/* translators: 1: año tributario, 2: año comercial */
		$items[] = array( $a_f22 . '-04-30', sprintf( __( 'Renta (F22) año tributario %1$d', 'malka-crm' ), $a_f22 ), sprintf( __( 'Abril %d', 'malka-crm' ), $a_f22 ) );

		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			}
		);

		$out = '<div class="esc-cal"><div class="esc-cal__l">';
		foreach ( $items as $it ) {
			$dias = (int) floor( ( strtotime( $it[0] ) - strtotime( $hoy ) ) / DAY_IN_SECONDS );
			$out .= sprintf(
				'<div class="esc-cal__i%1$s"><span class="esc-cal__d"><b>%2$s</b><i>%3$s</i></span><span class="esc-cal__t">%4$s<small>%5$s</small></span></div>',
				$dias <= 7 ? ' is-pronto' : '',
				esc_html( date_i18n( 'j', strtotime( $it[0] ) ) ),
				esc_html( date_i18n( 'M', strtotime( $it[0] ) ) ),
				esc_html( $it[1] ),
				esc_html( $it[2] . ' · ' . ( $dias <= 0 ? __( 'hoy', 'malka-crm' ) : sprintf( /* translators: días */ _n( 'en %d día', 'en %d días', $dias, 'malka-crm' ), $dias ) ) )
			);
		}
		return $out . '</div></div>';
	}

	/**
	 * Una fila en cero.
	 *
	 * @param string $mes Y-m.
	 * @return array
	 */
	private static function vacio( $mes ) {
		return array(
			'mes'           => $mes,
			'v_neto'        => 0,
			'v_cuantos'     => 0,
			'c_neto'        => 0,
			'c_cuantos'     => 0,
			'debito'        => 0,
			'credito'       => 0,
			'remanente_ant' => 0,
			'iva'           => 0,
			'remanente'     => 0,
			'ret_hon'       => 0,
			'ret_iva'       => 0,
			'retenciones'   => 0,
			'total'         => 0,
			'vence'         => '',
			'pagado'        => 0,
			'estado'        => 'nada',
			'cas'           => array(),
		);
	}

	/**
	 * Lo anotado como «Impuesto» en el mes en que se paga.
	 *
	 * @param string $mes Y-m del pago.
	 * @return int
	 */
	private static function pagado_en( $mes ) {
		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return 0;
		}
		return (int) Malka_CRM_Finance::sum_category( 'impuesto', (int) substr( $mes, 0, 4 ), (int) substr( $mes, 5, 2 ) );
	}

	/**
	 * curso · porpagar · vencido · pagado · nada.
	 *
	 * @param array  $f   Fila.
	 * @param string $hoy Y-m actual.
	 * @return string
	 */
	private static function estado( $f, $hoy ) {
		if ( $f['mes'] === $hoy ) {
			return 'curso';
		}
		if ( $f['total'] <= 0 ) {
			return 'nada';
		}
		if ( $f['pagado'] > 0 ) {
			return 'pagado';
		}
		return current_time( 'Y-m-d' ) > $f['vence'] ? 'vencido' : 'porpagar';
	}

	/**
	 * Rótulo y tono del estado.
	 *
	 * @param string $estado Estado.
	 * @return array {texto, tono}
	 */
	public static function rotulo( $estado ) {
		$r = array(
			'curso'    => array( __( 'En curso', 'malka-crm' ), 'run' ),
			'porpagar' => array( __( 'Por pagar', 'malka-crm' ), 'wait' ),
			'vencido'  => array( __( 'Sin pago anotado', 'malka-crm' ), 'late' ),
			'pagado'   => array( __( 'Pagado', 'malka-crm' ), 'done' ),
			'nada'     => array( __( 'Nada que pagar', 'malka-crm' ), 'off' ),
		);
		return isset( $r[ $estado ] ) ? $r[ $estado ] : $r['nada'];
	}

	/**
	 * La pestaña Contabilidad: arriba el próximo F29 y los vencimientos,
	 * debajo el mes a mes (cada mes se abre con sus casillas) y al final
	 * cómo se calcula y el importador.
	 *
	 * @param int $cuantos Meses a mostrar.
	 * @return string
	 */
	public static function render_panel( $cuantos = 12 ) {
		$meses = array_reverse( array_slice( self::meses(), -$cuantos, $cuantos, true ), true );
		$hoy   = current_time( 'Y-m' );
		$ant   = gmdate( 'Y-m', strtotime( $hoy . '-01 -1 month' ) );
		$prox  = self::mes( $ant );
		if ( current_time( 'Y-m-d' ) > $prox['vence'] || 'pagado' === $prox['estado'] ) {
			$prox = self::mes( $hoy );
		}
		$st    = self::rotulo( $prox['estado'] );
		$dias  = (int) floor( ( strtotime( $prox['vence'] ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
		$nom   = date_i18n( 'F', strtotime( $prox['mes'] . '-01' ) );

		ob_start();
		echo '<div class="esc-ctb" id="contabilidad">';

		/* ── Arriba: el próximo F29 y los vencimientos ── */
		echo '<div class="esc-ctb__top">';

		echo '<section class="esc-panel esc-ctb__f29">';
		printf(
			'<div class="esc-ph"><div><h2>%1$s</h2><p>%2$s</p></div><div class="esc-ph__r"><span class="esc-st esc-st--%3$s">%4$s</span></div></div>',
			esc_html( sprintf( /* translators: mes */ __( 'F29 de %s', 'malka-crm' ), $nom ) ),
			esc_html(
				sprintf(
					/* translators: 1: fecha, 2: días */
					__( 'Vence el %1$s · %2$s', 'malka-crm' ),
					date_i18n( 'j \d\e F', strtotime( $prox['vence'] ) ),
					$dias <= 0 ? __( 'hoy', 'malka-crm' ) : sprintf( /* translators: días */ _n( 'en %d día', 'en %d días', $dias, 'malka-crm' ), $dias )
				)
			),
			esc_attr( $st[1] ),
			esc_html( $st[0] )
		);
		printf(
			'<p class="esc-ctb__big">%1$s%2$s</p>',
			esc_html( Malka_CRM_Settings::money( $prox['total'] ) ),
			$prox['declarado'] ? ' <em class="esc-cont__dec">' . esc_html__( 'según F29', 'malka-crm' ) . '</em>' : ''
		);
		echo '<div class="esc-ctb__lin">';
		foreach ( array(
			array( __( 'IVA de tus ventas', 'malka-crm' ), $prox['debito'], '' ),
			array( __( 'IVA de tus compras', 'malka-crm' ), -$prox['credito'], '' ),
			array( __( 'Remanente del mes anterior', 'malka-crm' ), -$prox['remanente_ant'], '' ),
			array( __( 'Retenciones', 'malka-crm' ), $prox['retenciones'], '' ),
			array( __( 'PPM', 'malka-crm' ), $prox['ppm'], '' ),
		) as $l ) {
			if ( ! $l[1] ) {
				continue;
			}
			printf(
				'<span><b>%1$s</b><u>%2$s</u></span>',
				esc_html( $l[0] ),
				esc_html( ( $l[1] < 0 ? '−' : '' ) . Malka_CRM_Settings::money( abs( (int) $l[1] ) ) )
			);
		}
		if ( $prox['remanente'] ) {
			printf( '<span class="is-nota">%s</span>', esc_html( sprintf( /* translators: monto */ __( 'Sobran %s de crédito para el mes siguiente.', 'malka-crm' ), Malka_CRM_Settings::money( $prox['remanente'] ) ) ) );
		}
		echo '</div></section>';

		echo '<section class="esc-panel esc-ctb__cal">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Próximos vencimientos', 'malka-crm' ) . '</h2><p>' . esc_html__( 'Lo que tienes que declarar y cuándo', 'malka-crm' ) . '</p></div></div>';
		echo self::calendario(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';

		echo '</div>';

		/* ── Mes a mes ── */
		echo '<section class="esc-panel esc-ctb__meses">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Mes a mes', 'malka-crm' ) . '</h2><p>' . esc_html__( 'Toca un mes para ver cómo se llega al monto', 'malka-crm' ) . '</p></div></div>';
		echo '<div class="esc-ctb__t">';
		printf(
			'<div class="esc-ctb__r esc-ctb__r--h"><span>%1$s</span><span>%2$s</span><span>%3$s</span><span>%4$s</span><span>%5$s</span></div>',
			esc_html__( 'Mes', 'malka-crm' ),
			esc_html__( 'IVA ventas', 'malka-crm' ),
			esc_html__( 'IVA compras', 'malka-crm' ),
			esc_html__( 'A pagar', 'malka-crm' ),
			esc_html__( 'Estado', 'malka-crm' )
		);
		$n     = 0;
		$resto = 0;
		foreach ( $meses as $mes => $f ) {
			if ( $mes !== $hoy && ! $f['v_cuantos'] && ! $f['c_cuantos'] && ! $f['remanente_ant'] ) {
				continue;
			}
			$resto++;
		}
		foreach ( $meses as $mes => $f ) {
			if ( $mes !== $hoy && ! $f['v_cuantos'] && ! $f['c_cuantos'] && ! $f['remanente_ant'] ) {
				continue;
			}
			// Los primeros seis a la vista; el resto, plegado.
			if ( 6 === $n ) {
				printf( '<details class="esc-ctb__mas"><summary>%s</summary>', esc_html( sprintf( /* translators: cuántos */ _n( 'Ver %d mes anterior', 'Ver %d meses anteriores', $resto - 6, 'malka-crm' ), $resto - 6 ) ) );
			}
			$n++;
			$sr = self::rotulo( $f['estado'] );
			printf(
				'<details class="esc-ctb__x"><summary class="esc-ctb__r%1$s">' .
				'<span class="esc-ctb__m"><svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4"/></svg><b>%2$s</b>%3$s</span>' .
				'<span data-l="%4$s">%5$s</span><span data-l="%6$s">%7$s</span>' .
				'<span class="esc-ctb__p" data-l="%8$s">%9$s</span>' .
				'<span><span class="esc-st esc-st--%10$s">%11$s</span></span></summary>',
				$mes === $hoy ? ' is-hoy' : '',
				esc_html( ucfirst( date_i18n( 'F Y', strtotime( $mes . '-01' ) ) ) ),
				$f['declarado'] ? ' <em class="esc-cont__dec">F29</em>' : '',
				esc_attr__( 'IVA ventas', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $f['debito'] ) ),
				esc_attr__( 'IVA compras', 'malka-crm' ),
				esc_html( $f['credito'] ? '−' . Malka_CRM_Settings::money( $f['credito'] ) : '—' ),
				esc_attr__( 'A pagar', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $f['total'] ) ),
				esc_attr( $sr[1] ),
				esc_html( $sr[0] )
			);
			echo self::render_detalle( $f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</details>';
		}
		if ( $n > 6 ) {
			echo '</details>';
		}
		echo '</div></section>';

		/* ── Al final: cómo cuadrarlo y cómo se calcula ── */
		echo '<section class="esc-panel esc-ctb__pie">';
		if ( class_exists( 'Malka_CRM_Documents' ) ) {
			printf(
				'<div class="esc-ctb__imp"><div><b>%1$s</b><span>%2$s</span></div><button type="button" class="esc-btn esc-btn--soft" data-imp-abrir>%3$s</button></div>',
				esc_html__( '¿No cuadra con tu contadora?', 'malka-crm' ),
				esc_html__( 'Suelta su libro de compras, el de ventas y el borrador del F29: cada documento queda en el mes que ella declara.', 'malka-crm' ),
				esc_html__( 'Importar libros y F29', 'malka-crm' )
			);
			echo Malka_CRM_Documents::dialogo_importar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		printf(
			'<details class="esc-ctb__como"><summary>%1$s</summary><p>%2$s</p></details>',
			esc_html__( 'Cómo se calcula', 'malka-crm' ),
			esc_html__( 'IVA ventas menos IVA compras y el remanente que sobró el mes anterior; si da negativo no se paga y pasa al mes siguiente. Se suman las retenciones (boletas de honorarios recibidas e IVA de las facturas de compra que emites) y el PPM, con la tasa de Configuración. Cada compra cuenta en el mes en que la declara tu contadora si importaste su libro; los meses con «F29» usan el formulario importado. Se marca pagado cuando anotas un movimiento «Impuesto» en el mes del vencimiento.', 'malka-crm' )
		);
		echo '</section>';

		echo '</div>';
		return ob_get_clean();
	}
}

add_action( 'wp_ajax_malka_crm_f29_guardar', array( 'Malka_CRM_Contabilidad', 'ajax_guardar' ) );
