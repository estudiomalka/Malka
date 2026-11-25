<?php
/**
 * Lo que debes: tarjetas, compras en cuotas, créditos y líneas.
 *
 * Acá no se adivina nada. Cada número sale de tu estado de cuenta o de tu
 * contrato: el saldo, la tasa del mes, el pago mínimo, el día que vence. Lo
 * único que hace el plugin es aritmética encima de eso —cuánto te cuesta
 * deber, en qué orden conviene pagar, cuánto demoras— y mostrarlo junto.
 *
 * Dos decisiones que vale la pena explicar:
 *
 * 1. El pago mínimo NO se calcula acá. Desde junio de 2026 en Chile se arma
 *    como «monto no financiable + 5% del monto financiable» (Norma de
 *    Carácter General 537 de la CMF), y esa primera parte depende del
 *    desglose de tu estado de cuenta y de un calendario que termina en junio
 *    de 2028. Calcularlo de memoria daría un número parecido y equivocado.
 *    Tú lo copias; yo trabajo con el tuyo.
 *
 * 2. Las condiciones de cada tarjeta (cupo, tasa, día de facturación, día de
 *    pago, pago mínimo) viven acá y no en la cuenta. La cuenta ya guarda el
 *    saldo —que en una tarjeta de crédito es la deuda— y agregarle estos
 *    campos obligaba a tocar el formulario de cuentas de Configuración. Así
 *    todo lo de deuda se edita en la misma pantalla donde se mira.
 *
 * Esto es aritmética sobre tus números, no asesoría financiera.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Deudas {

	/** Las deudas que no son una tarjeta: cuotas, créditos, líneas. */
	const OPT = 'malka_crm_deudas';

	/** Las condiciones de cada tarjeta de crédito, por id de cuenta. */
	const OPT_TARJ = 'malka_crm_deudas_tarjetas';

	/** Cuánto puedes poner al mes por sobre los mínimos. */
	const OPT_EXTRA = 'malka_crm_deudas_extra';

	/** Hasta dónde simular. Más de 50 años es «no cierra». */
	const TOPE_MESES = 600;

	public function __construct() {
		add_action( 'admin_post_malka_crm_deuda_guardar', array( __CLASS__, 'handle_guardar' ) );
		add_action( 'admin_post_malka_crm_deuda_borrar', array( __CLASS__, 'handle_borrar' ) );
		add_action( 'admin_post_malka_crm_deuda_tarjeta', array( __CLASS__, 'handle_tarjeta' ) );
		add_action( 'admin_post_malka_crm_deuda_pagar', array( __CLASS__, 'handle_pagar' ) );
		add_action( 'admin_post_malka_crm_deuda_interes', array( __CLASS__, 'handle_interes' ) );
		add_action( 'admin_post_malka_crm_deuda_extra', array( __CLASS__, 'handle_extra' ) );
	}

	/* ──────────────────────────────  Datos  ──────────────────────────────── */

	/**
	 * Los tipos de deuda que se anotan a mano.
	 *
	 * La tarjeta de crédito no está acá: es una cuenta, y su saldo ya es la
	 * deuda. Lo que falta de una tarjeta son sus condiciones, no la deuda.
	 *
	 * @return array
	 */
	public static function tipos() {
		return array(
			'cuotas'  => __( 'Compra en cuotas', 'malka-crm' ),
			'consumo' => __( 'Crédito de consumo', 'malka-crm' ),
			'linea'   => __( 'Línea de crédito o avance', 'malka-crm' ),
		);
	}

	/**
	 * ¿Este tipo cobra interés sobre el saldo mes a mes?
	 *
	 * En una compra en cuotas o un crédito de consumo el interés ya viene
	 * dentro de la cuota y el plazo está fijo: pagar de más no lo cambia sin
	 * prepagar, que se pide en el banco. En una línea o una tarjeta, sí.
	 *
	 * @param string $tipo Tipo.
	 * @return bool
	 */
	public static function es_rotativa( $tipo ) {
		return in_array( (string) $tipo, array( 'linea', 'tarjeta' ), true );
	}

	/**
	 * Las deudas anotadas a mano, crudas.
	 *
	 * @return array[]
	 */
	public static function deudas() {
		$d = get_option( self::OPT, array() );
		return is_array( $d ) ? $d : array();
	}

	/**
	 * Crea o edita una deuda.
	 *
	 * @param array $d Datos.
	 * @return int Id.
	 */
	public static function guardar( $d ) {
		$todas = self::deudas();
		$id    = isset( $d['id'] ) ? (int) $d['id'] : 0;

		if ( ! $id || ! isset( $todas[ $id ] ) ) {
			$id           = $todas ? max( array_keys( $todas ) ) + 1 : 1;
			$todas[ $id ] = array( 'id' => $id );
		}

		$x = $todas[ $id ];

		$x['nombre'] = isset( $d['nombre'] ) ? sanitize_text_field( (string) $d['nombre'] ) : ( isset( $x['nombre'] ) ? $x['nombre'] : '' );
		$x['tipo']   = isset( $d['tipo'] ) && array_key_exists( $d['tipo'], self::tipos() ) ? $d['tipo'] : ( isset( $x['tipo'] ) ? $x['tipo'] : 'cuotas' );

		foreach ( array( 'saldo', 'cuota', 'minimo', 'cuotas_total', 'cuotas_pagadas', 'dia_pago' ) as $k ) {
			if ( isset( $d[ $k ] ) ) {
				$x[ $k ] = max( 0, (int) $d[ $k ] );
			} elseif ( ! isset( $x[ $k ] ) ) {
				$x[ $k ] = 0;
			}
		}

		$x['tasa'] = isset( $d['tasa'] ) ? max( 0, min( 20, round( (float) $d['tasa'], 2 ) ) ) : ( isset( $x['tasa'] ) ? $x['tasa'] : 0 );

		/*
		 * Cuándo lo pediste. No se usa para ninguna cuenta: sirve para saber
		 * desde cuándo lo estás pagando, que es la pregunta que uno se hace
		 * mirando la lista. Una fecha mal escrita se guarda vacía antes que
		 * inventar un día.
		 */
		if ( isset( $d['fecha'] ) ) {
			$f          = (string) $d['fecha'];
			$x['fecha'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f ) ? $f : '';
		} elseif ( ! isset( $x['fecha'] ) ) {
			$x['fecha'] = '';
		}

		// Un día de pago fuera del mes no es un día de pago.
		if ( $x['dia_pago'] < 1 || $x['dia_pago'] > 31 ) {
			$x['dia_pago'] = 0;
		}

		// No puedes haber pagado más cuotas que las que tiene.
		if ( $x['cuotas_total'] > 0 && $x['cuotas_pagadas'] > $x['cuotas_total'] ) {
			$x['cuotas_pagadas'] = $x['cuotas_total'];
		}

		if ( '' === trim( (string) $x['nombre'] ) ) {
			return 0;
		}

		$todas[ $id ] = $x;
		update_option( self::OPT, $todas, false );

		return $id;
	}

	/**
	 * Borra una deuda anotada a mano.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function borrar( $id ) {
		$todas = self::deudas();
		$id    = (int) $id;

		if ( ! isset( $todas[ $id ] ) ) {
			return false;
		}

		unset( $todas[ $id ] );
		update_option( self::OPT, $todas, false );

		return true;
	}

	/**
	 * Las condiciones guardadas de las tarjetas, crudas.
	 *
	 * @return array[]
	 */
	public static function tarjetas() {
		$t = get_option( self::OPT_TARJ, array() );
		return is_array( $t ) ? $t : array();
	}

	/**
	 * Las condiciones de una tarjeta, completas aunque falten.
	 *
	 * @param int $cuenta Id de cuenta.
	 * @return array cupo, tasa, dia_facturacion, dia_pago, minimo.
	 */
	public static function condiciones( $cuenta ) {
		$t   = self::tarjetas();
		$id  = (int) $cuenta;
		$x   = isset( $t[ $id ] ) && is_array( $t[ $id ] ) ? $t[ $id ] : array();
		$out = array(
			'cupo'            => isset( $x['cupo'] ) ? (int) $x['cupo'] : 0,
			'dia_facturacion' => isset( $x['dia_facturacion'] ) ? (int) $x['dia_facturacion'] : 0,
			'dia_pago'        => isset( $x['dia_pago'] ) ? (int) $x['dia_pago'] : 0,
			'minimo'          => isset( $x['minimo'] ) ? (int) $x['minimo'] : 0,
			'tasa'            => isset( $x['tasa'] ) ? (float) $x['tasa'] : 0,
		);
		return $out;
	}

	/**
	 * Guarda las condiciones de una tarjeta.
	 *
	 * @param array $d Datos, con «cuenta».
	 * @return bool
	 */
	public static function guardar_tarjeta( $d ) {
		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return false;
		}

		$id = isset( $d['cuenta'] ) ? (int) $d['cuenta'] : 0;

		// Sólo las tarjetas de crédito tienen cupo, tasa y día de pago.
		if ( ! $id || ! Malka_CRM_Finance::es_credito( $id ) ) {
			return false;
		}

		$t   = self::tarjetas();
		$x   = self::condiciones( $id );

		foreach ( array( 'cupo', 'minimo' ) as $k ) {
			if ( isset( $d[ $k ] ) ) {
				$x[ $k ] = max( 0, (int) $d[ $k ] );
			}
		}

		foreach ( array( 'dia_facturacion', 'dia_pago' ) as $k ) {
			if ( isset( $d[ $k ] ) ) {
				$v       = (int) $d[ $k ];
				$x[ $k ] = ( $v >= 1 && $v <= 31 ) ? $v : 0;
			}
		}

		if ( isset( $d['tasa'] ) ) {
			$x['tasa'] = max( 0, min( 20, round( (float) $d['tasa'], 2 ) ) );
		}

		$t[ $id ] = $x;
		update_option( self::OPT_TARJ, $t, false );

		return true;
	}

	/**
	 * Cuánto puede ir al mes por sobre los mínimos.
	 *
	 * @return int
	 */
	public static function extra() {
		return max( 0, (int) get_option( self::OPT_EXTRA, 0 ) );
	}

	/**
	 * Fija ese monto.
	 *
	 * @param int $monto Pesos.
	 */
	public static function fijar_extra( $monto ) {
		update_option( self::OPT_EXTRA, max( 0, (int) $monto ), false );
	}

	/* ─────────────────────────────  Fechas  ──────────────────────────────── */

	/**
	 * La próxima vez que caiga ese día del mes, contando hoy.
	 *
	 * Si el día no existe en el mes —el 31 en febrero— cae en el último.
	 *
	 * @param int $dia 1 a 31.
	 * @return string Y-m-d, o vacío si no hay día.
	 */
	public static function proxima_fecha( $dia ) {
		$dia = (int) $dia;

		if ( $dia < 1 || $dia > 31 ) {
			return '';
		}

		$hoy = current_time( 'Y-m-d' );
		$y   = (int) substr( $hoy, 0, 4 );
		$m   = (int) substr( $hoy, 5, 2 );
		$f   = '';

		for ( $i = 0; $i < 2; $i++ ) {
			$ultimo = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );
			$f      = sprintf( '%04d-%02d-%02d', $y, $m, min( $dia, $ultimo ) );

			if ( $f >= $hoy ) {
				return $f;
			}

			$m++;

			if ( $m > 12 ) {
				$m = 1;
				$y++;
			}
		}

		return $f;
	}

	/**
	 * Cuántos días faltan para esa fecha. Negativo si ya pasó.
	 *
	 * @param string $fecha Y-m-d.
	 * @return int
	 */
	public static function dias_hasta( $fecha ) {
		if ( ! $fecha ) {
			return 0;
		}

		$a = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00' );
		$b = strtotime( $fecha . ' 00:00:00' );

		return (int) floor( ( $b - $a ) / DAY_IN_SECONDS );
	}

	/* ──────────────────────────────  Lista  ──────────────────────────────── */

	/**
	 * Todo lo que debes, en una sola lista.
	 *
	 * Junta las tarjetas de crédito —cuyo saldo de cuenta ya es la deuda— con
	 * lo anotado a mano. Cada fila trae lo mismo, venga de donde venga.
	 *
	 * @return array[]
	 */
	public static function lista() {
		$out = array();

		if ( class_exists( 'Malka_CRM_Finance' ) ) {
			foreach ( Malka_CRM_Finance::cuentas() as $id => $c ) {
				if ( ! Malka_CRM_Finance::es_credito( (int) $id ) ) {
					continue;
				}

				$cond  = self::condiciones( (int) $id );
				$saldo = max( 0, (int) $c['saldo'] );
				$vence = self::proxima_fecha( $cond['dia_pago'] );

				$out[] = array(
					'clave'     => 't' . (int) $id,
					'clase'     => 'tarjeta',
					'rotulo'    => __( 'Tarjeta de crédito', 'malka-crm' ),
					'nombre'    => Malka_CRM_Finance::nombre_cuenta( (int) $id ),
					'cuenta'    => (int) $id,
					'personal'  => Malka_CRM_Finance::es_personal( (int) $id ) ? 1 : 0,
					'saldo'     => $saldo,
					'tasa'      => (float) $cond['tasa'],
					'cuota'     => (int) $cond['minimo'],
					'cupo'      => (int) $cond['cupo'],
					'rotativa'  => true,
					'restantes' => 0,
					'vence'     => $vence,
					'dias'      => $vence ? self::dias_hasta( $vence ) : 0,
					'interes'   => (int) round( $saldo * (float) $cond['tasa'] / 100 ),
					'editable'  => false,
					'fecha'     => '',
					'cuotas'    => 0,
					'pagadas'   => 0,
					'pagado'    => 0,
					'total'     => 0,
					'avance'    => 0,
				);
			}
		}

		foreach ( self::deudas() as $id => $d ) {
			$tipo      = isset( $d['tipo'] ) ? (string) $d['tipo'] : 'cuotas';
			$rot       = self::es_rotativa( $tipo );
			$total     = isset( $d['cuotas_total'] ) ? (int) $d['cuotas_total'] : 0;
			$pagadas   = isset( $d['cuotas_pagadas'] ) ? (int) $d['cuotas_pagadas'] : 0;
			$restantes = max( 0, $total - $pagadas );
			$cuota     = isset( $d['cuota'] ) ? (int) $d['cuota'] : 0;
			$tasa      = isset( $d['tasa'] ) ? (float) $d['tasa'] : 0;

			/*
			 * En una deuda en cuotas lo que importa es lo que te falta pagar:
			 * cuota por cuotas que quedan. El interés ya está adentro. Si no
			 * dijo cuántas cuotas son, vale el saldo que anotó.
			 */
			if ( $rot ) {
				$saldo = isset( $d['saldo'] ) ? (int) $d['saldo'] : 0;
			} else {
				$saldo = $restantes > 0 && $cuota > 0 ? $cuota * $restantes : (int) ( isset( $d['saldo'] ) ? $d['saldo'] : 0 );
			}

			$vence = self::proxima_fecha( isset( $d['dia_pago'] ) ? $d['dia_pago'] : 0 );
			$tipos = self::tipos();

			/*
			 * Lo que llevas pagado sale de las cuotas pagadas por el valor de
			 * la cuota: un solo número, el que tú anotas, y de ahí se deduce
			 * todo lo demás. Guardar aparte «cuánto llevo» daría dos números
			 * que se pueden contradecir. En una línea o una tarjeta no hay
			 * cuotas, así que no hay avance que mostrar.
			 */
			$total_cred = $rot ? 0 : $cuota * $total;
			$pagado     = $rot ? 0 : $cuota * $pagadas;
			$avance     = $total_cred > 0 ? (int) round( $pagado * 100 / $total_cred ) : 0;

			$out[] = array(
				'clave'     => 'd' . (int) $id,
				'clase'     => $tipo,
				'rotulo'    => isset( $tipos[ $tipo ] ) ? $tipos[ $tipo ] : $tipo,
				'nombre'    => isset( $d['nombre'] ) ? (string) $d['nombre'] : '',
				'cuenta'    => 0,
				'personal'  => 1,
				'saldo'     => $saldo,
				'tasa'      => $tasa,
				'cuota'     => $rot ? (int) ( isset( $d['minimo'] ) ? $d['minimo'] : 0 ) : $cuota,
				'cupo'      => 0,
				'rotativa'  => $rot,
				'restantes' => $restantes,
				'vence'     => $vence,
				'dias'      => $vence ? self::dias_hasta( $vence ) : 0,
				'interes'   => $rot ? (int) round( $saldo * $tasa / 100 ) : 0,
				'editable'  => true,
				'fecha'     => isset( $d['fecha'] ) ? (string) $d['fecha'] : '',
				'cuotas'    => $total,
				'pagadas'   => $pagadas,
				'pagado'    => $pagado,
				'total'     => $total_cred,
				'avance'    => min( 100, max( 0, $avance ) ),
			);
		}

		return $out;
	}

	/**
	 * Los tres números de arriba.
	 *
	 * @param array[]|null $lista Lista, o null para pedirla.
	 * @return array debe, interes, mes.
	 */
	public static function totales( $lista = null ) {
		$lista = null === $lista ? self::lista() : $lista;
		$out   = array(
			'debe'    => 0,
			'interes' => 0,
			'mes'     => 0,
			'cuantas' => 0,
		);

		foreach ( $lista as $d ) {
			$out['debe']    += (int) $d['saldo'];
			$out['interes'] += (int) $d['interes'];
			$out['mes']     += (int) $d['cuota'];

			if ( (int) $d['saldo'] > 0 ) {
				$out['cuantas']++;
			}
		}

		return $out;
	}

	/**
	 * Lo que vence pronto, para «Tu día».
	 *
	 * @param int $dentro Cuántos días mirar hacia adelante.
	 * @return array[]
	 */
	public static function proximos( $dentro = 10 ) {
		$out = array();

		foreach ( self::lista() as $d ) {
			if ( ! $d['vence'] || $d['saldo'] <= 0 ) {
				continue;
			}

			if ( $d['dias'] > (int) $dentro ) {
				continue;
			}

			$out[] = $d;
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

	/* ───────────────────────────  La aritmética  ─────────────────────────── */

	/**
	 * Cuánto demoras y cuánto interés pagas, en un orden dado.
	 *
	 * Simula mes a mes sólo las deudas que cobran interés sobre el saldo:
	 * tarjetas y líneas. Las cuotas fijas no entran porque su plazo no se
	 * acorta pagando de más —eso se pide como prepago en el banco— y meterlas
	 * acá haría ver un ahorro que no existe.
	 *
	 * @param string $orden 'tasa' (avalancha) o 'saldo' (bola de nieve).
	 * @param int    $extra Cuánto pones por sobre los mínimos.
	 * @return array meses, interes, orden, cierra, falta, presupuesto.
	 */
	public static function simular( $orden, $extra = 0 ) {
		$out = array(
			'meses'       => 0,
			'interes'     => 0,
			'orden'       => array(),
			'cierra'      => true,
			'falta'       => 0,
			'presupuesto' => 0,
			'hay'         => false,
		);

		$deudas = array();

		foreach ( self::lista() as $d ) {
			if ( empty( $d['rotativa'] ) || (int) $d['saldo'] <= 0 ) {
				continue;
			}

			$deudas[] = array(
				'nombre' => $d['nombre'],
				'saldo'  => (int) $d['saldo'],
				'tasa'   => (float) $d['tasa'],
				// Sin mínimo anotado, se usa un 5% del saldo: es el piso que
				// fija la norma sobre el monto financiable. Mejor eso que cero,
				// que haría ver una deuda que nunca se paga.
				'minimo' => max( 1, (int) $d['cuota'] > 0 ? (int) $d['cuota'] : (int) round( (int) $d['saldo'] * 0.05 ) ),
			);
		}

		if ( ! $deudas ) {
			return $out;
		}

		$out['hay'] = true;

		if ( 'tasa' === $orden ) {
			usort(
				$deudas,
				function ( $a, $b ) {
					if ( $a['tasa'] === $b['tasa'] ) {
						if ( $a['saldo'] === $b['saldo'] ) {
							return 0;
						}
						return $a['saldo'] < $b['saldo'] ? -1 : 1;
					}
					return $a['tasa'] > $b['tasa'] ? -1 : 1;
				}
			);
		} else {
			usort(
				$deudas,
				function ( $a, $b ) {
					if ( $a['saldo'] === $b['saldo'] ) {
						return 0;
					}
					return $a['saldo'] < $b['saldo'] ? -1 : 1;
				}
			);
		}

		$presupuesto = max( 0, (int) $extra );
		$interes_1   = 0;

		foreach ( $deudas as $d ) {
			$presupuesto += $d['minimo'];
			$interes_1   += (int) round( $d['saldo'] * $d['tasa'] / 100 );
			$out['orden'][] = $d['nombre'];
		}

		$out['presupuesto'] = $presupuesto;

		/*
		 * Lo más importante que puede decir esta pantalla: si lo que pones al
		 * mes no cubre el interés del mes, la deuda sube igual que la pagues.
		 * Mejor decirlo que mostrar un «en 480 meses».
		 */
		if ( $presupuesto <= $interes_1 ) {
			$out['cierra'] = false;
			$out['falta']  = $interes_1 - $presupuesto + 1;
			return $out;
		}

		$mes = 0;

		while ( $mes < self::TOPE_MESES ) {
			$vivas = 0;

			foreach ( $deudas as $d ) {
				if ( $d['saldo'] > 0 ) {
					$vivas++;
				}
			}

			if ( ! $vivas ) {
				break;
			}

			$mes++;

			foreach ( $deudas as $i => $d ) {
				if ( $d['saldo'] <= 0 ) {
					continue;
				}

				$int                    = (int) round( $d['saldo'] * $d['tasa'] / 100 );
				$deudas[ $i ]['saldo'] += $int;
				$out['interes']        += $int;
			}

			$plata = $presupuesto;

			// Primero el mínimo de cada una: no pagarlo cuesta aparte del interés.
			foreach ( $deudas as $i => $d ) {
				if ( $d['saldo'] <= 0 || $plata <= 0 ) {
					continue;
				}

				$p                      = min( $d['minimo'], $d['saldo'], $plata );
				$deudas[ $i ]['saldo'] -= $p;
				$plata                 -= $p;
			}

			// Lo que sobra, entero a la primera de la fila.
			foreach ( $deudas as $i => $d ) {
				if ( $plata <= 0 ) {
					break;
				}

				if ( $d['saldo'] <= 0 ) {
					continue;
				}

				$p                      = min( $plata, $d['saldo'] );
				$deudas[ $i ]['saldo'] -= $p;
				$plata                 -= $p;
			}
		}

		$out['meses'] = $mes;

		if ( $mes >= self::TOPE_MESES ) {
			$out['cierra'] = false;
		}

		return $out;
	}

	/**
	 * Qué pasa con UNA deuda si la pagas así todos los meses.
	 *
	 * El de arriba —simular()— reparte una sola plata entre todas y sirve
	 * para decidir el orden. Este mira una sola: cuántos meses, cuánto
	 * interés, y si el pago siquiera alcanza para bajar el saldo.
	 *
	 * @param array $d     Fila de lista().
	 * @param int   $extra Cuánto pones al mes por sobre el mínimo.
	 * @return array meses, interes, cierra, cubre, pago.
	 */
	public static function sola( $d, $extra = 0 ) {
		$out = array(
			'meses'   => 0,
			'interes' => 0,
			'total'   => 0,
			'cierra'  => true,
			'cubre'   => true,
			'pago'    => 0,
		);

		// En cuotas el interés ya va adentro: no hay nada que simular, se
		// paga la cuota y se termina. Decir otra cosa sería inventar.
		if ( empty( $d['rotativa'] ) ) {
			$out['meses'] = (int) $d['restantes'];
			$out['pago']  = (int) $d['cuota'];
			$out['total'] = (int) $d['cuota'] * (int) $d['restantes'];

			return $out;
		}

		$saldo = (int) $d['saldo'];
		$tasa  = (float) $d['tasa'];
		$min   = (int) $d['cuota'] > 0 ? (int) $d['cuota'] : (int) round( $saldo * 0.05 );
		$pago  = max( 1, $min + max( 0, (int) $extra ) );

		$out['pago'] = $pago;

		if ( $saldo <= 0 ) {
			return $out;
		}

		// Sin tasa no se puede decir nada honesto sobre el futuro.
		if ( $tasa <= 0 ) {
			$out['cierra'] = false;

			return $out;
		}

		$primer_interes = (int) round( $saldo * $tasa / 100 );
		$out['cubre']   = $pago > $primer_interes;

		if ( ! $out['cubre'] ) {
			$out['cierra'] = false;

			return $out;
		}

		$mes = 0;

		while ( $saldo > 0 && $mes < self::TOPE_MESES ) {
			$mes++;
			$i                = (int) round( $saldo * $tasa / 100 );
			$out['interes']  += $i;
			$saldo            = $saldo + $i - $pago;

			if ( $saldo < 0 ) {
				$saldo = 0;
			}
		}

		$out['meses']  = $mes;
		$out['cierra'] = $mes < self::TOPE_MESES;
		$out['total']  = (int) $d['saldo'] + $out['interes'];

		return $out;
	}

	/**
	 * Los dos órdenes, lado a lado.
	 *
	 * @return array tasa, saldo, mejor, ahorro, meses_menos.
	 */
	public static function comparar() {
		$extra = self::extra();
		$a     = self::simular( 'tasa', $extra );
		$b     = self::simular( 'saldo', $extra );

		$out = array(
			'tasa'        => $a,
			'saldo'       => $b,
			'mejor'       => '',
			'ahorro'      => 0,
			'meses_menos' => 0,
		);

		if ( ! $a['hay'] || ! $a['cierra'] || ! $b['cierra'] ) {
			return $out;
		}

		if ( $a['interes'] < $b['interes'] ) {
			$out['mejor']  = 'tasa';
			$out['ahorro'] = $b['interes'] - $a['interes'];
		} elseif ( $b['interes'] < $a['interes'] ) {
			$out['mejor']  = 'saldo';
			$out['ahorro'] = $a['interes'] - $b['interes'];
		}

		$out['meses_menos'] = abs( $a['meses'] - $b['meses'] );

		return $out;
	}

	/**
	 * El estado de una tarjeta, listo para mostrar en una fila.
	 *
	 * @param int $cuenta Id de cuenta.
	 * @return array|null Null si no es una tarjeta de crédito.
	 */
	public static function estado_tarjeta( $cuenta ) {
		$cuenta = (int) $cuenta;

		if ( ! class_exists( 'Malka_CRM_Finance' ) || ! Malka_CRM_Finance::es_credito( $cuenta ) ) {
			return null;
		}

		$cuentas = Malka_CRM_Finance::cuentas();

		if ( ! isset( $cuentas[ $cuenta ] ) ) {
			return null;
		}

		$cond  = self::condiciones( $cuenta );
		$debe  = max( 0, (int) $cuentas[ $cuenta ]['saldo'] );
		$cupo  = (int) $cond['cupo'];
		$vence = self::proxima_fecha( $cond['dia_pago'] );

		/*
		 * El interés del mes es el único monto que se puede calcular solo:
		 * saldo por tasa. El pago mínimo no —desde junio de 2026 depende del
		 * desglose del estado de cuenta— así que ése se copia. Cuando falta,
		 * se muestra el interés como el piso bajo el cual la deuda sube.
		 */
		$interes = $cond['tasa'] > 0 ? (int) round( $debe * $cond['tasa'] / 100 ) : 0;

		return array(
			'debe'     => $debe,
			'cupo'     => $cupo,
			'usado'    => $cupo > 0 ? min( 200, (int) round( $debe * 100 / $cupo ) ) : 0,
			'libre'    => $cupo > 0 ? max( 0, $cupo - $debe ) : 0,
			'pasada'   => $cupo > 0 && $debe > $cupo,
			'tasa'     => (float) $cond['tasa'],
			'interes'  => $interes,
			'minimo'   => (int) $cond['minimo'],
			'vence'    => $vence,
			'dias'     => $vence ? self::dias_hasta( $vence ) : 0,
			'facturar' => (int) $cond['dia_facturacion'],
			// Lo que hay que poner este mes, con el dato que tengamos.
			'pagar'    => $cond['minimo'] > 0 ? (int) $cond['minimo'] : $interes,
			'de_donde' => $cond['minimo'] > 0 ? 'minimo' : ( $interes > 0 ? 'interes' : 'falta' ),
		);
	}

	/* ──────────────────────────────  Pagar  ──────────────────────────────── */

	/**
	 * Anota un pago de deuda.
	 *
	 * Un pago de tarjeta no es un gasto nuevo: el gasto fue la compra. Por eso
	 * va como traspaso —sale de una cuenta y baja la deuda de la otra— y no
	 * como costo. Contarlo de nuevo como costo duplicaría la compra.
	 *
	 * @param string $clave  Clave de la fila.
	 * @param int    $monto  Cuánto.
	 * @param int    $origen De qué cuenta salió.
	 * @param string $fecha  Y-m-d.
	 * @return bool
	 */
	public static function pagar( $clave, $monto, $origen, $fecha = '' ) {
		$monto = (int) $monto;

		if ( $monto <= 0 || ! class_exists( 'Malka_CRM_Finance' ) ) {
			return false;
		}

		$cuentas = Malka_CRM_Finance::cuentas();
		$origen  = (int) $origen;

		if ( ! isset( $cuentas[ $origen ] ) || Malka_CRM_Finance::es_credito( $origen ) ) {
			return false;
		}

		$fila = null;

		foreach ( self::lista() as $d ) {
			if ( $d['clave'] === (string) $clave ) {
				$fila = $d;
				break;
			}
		}

		if ( ! $fila ) {
			return false;
		}

		$hoy   = current_time( 'Y-m-d' );
		$fecha = $fecha && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) ? $fecha : $hoy;
		$fecha = $fecha > $hoy ? $hoy : $fecha;

		Malka_CRM_Finance::add(
			array(
				'descripcion' => sprintf( /* translators: deuda */ __( 'Pago de %s', 'malka-crm' ), $fila['nombre'] ),
				'monto'       => $monto,
				'tipo'        => 'salida',
				'categoria'   => 'traspaso',
				'fecha'       => $fecha,
				'cuenta'      => $origen,
				'referencia'  => 'deuda:' . sanitize_key( $clave ),
			)
		);

		if ( 'tarjeta' === $fila['clase'] && $fila['cuenta'] ) {
			/*
			 * En una tarjeta el saldo es la deuda y shift_balance le da vuelta
			 * el signo: una entrada baja lo que debes. Así la tarjeta queda
			 * cuadrada sin tocar el saldo a mano.
			 */
			Malka_CRM_Finance::add(
				array(
					'descripcion' => __( 'Pago recibido en la tarjeta', 'malka-crm' ),
					'monto'       => $monto,
					'tipo'        => 'entrada',
					'categoria'   => 'traspaso',
					'fecha'       => $fecha,
					'cuenta'      => (int) $fila['cuenta'],
					'referencia'  => 'deuda:' . sanitize_key( $clave ),
				)
			);

			return true;
		}

		// Una deuda anotada a mano baja acá: una cuota más pagada, o menos saldo.
		$id    = (int) substr( $fila['clave'], 1 );
		$todas = self::deudas();

		if ( ! isset( $todas[ $id ] ) ) {
			return true;
		}

		if ( self::es_rotativa( $fila['clase'] ) ) {
			$todas[ $id ]['saldo'] = max( 0, (int) $todas[ $id ]['saldo'] - $monto );
		} else {
			/*
			 * Cuántas cuotas cubre lo que pagaste. Pagar dos juntas y que
			 * cuente una sola dejaría la deuda más alta de lo que está.
			 * Siempre al menos una: un abono parcial igual adelanta la fila.
			 */
			$total                          = (int) $todas[ $id ]['cuotas_total'];
			$cuota                          = (int) $todas[ $id ]['cuota'];
			$cuantas                        = $cuota > 0 ? max( 1, (int) floor( $monto / $cuota ) ) : 1;
			$todas[ $id ]['cuotas_pagadas'] = min( $total, (int) $todas[ $id ]['cuotas_pagadas'] + $cuantas );
		}

		update_option( self::OPT, $todas, false );

		return true;
	}

	/**
	 * Anota el interés que te cobró una tarjeta.
	 *
	 * Esto sí es plata nueva que te cuesta: sube la deuda. Si la tarjeta es
	 * tuya y no del estudio no se marca como costo del estudio, porque si no
	 * el estudio aparecería debiéndotelo.
	 *
	 * @param int    $cuenta Tarjeta.
	 * @param int    $monto  Cuánto.
	 * @param string $fecha  Y-m-d.
	 * @return bool
	 */
	public static function anotar_interes( $cuenta, $monto, $fecha = '' ) {
		$monto  = (int) $monto;
		$cuenta = (int) $cuenta;

		if ( $monto <= 0 || ! class_exists( 'Malka_CRM_Finance' ) || ! Malka_CRM_Finance::es_credito( $cuenta ) ) {
			return false;
		}

		$hoy   = current_time( 'Y-m-d' );
		$fecha = $fecha && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) ? $fecha : $hoy;
		$fecha = $fecha > $hoy ? $hoy : $fecha;

		$res = Malka_CRM_Finance::add(
			array(
				'descripcion' => __( 'Intereses y cargos de la tarjeta', 'malka-crm' ),
				'monto'       => $monto,
				'tipo'        => 'salida',
				'categoria'   => Malka_CRM_Finance::es_personal( $cuenta ) ? 'sin_categoria' : 'costo_variable',
				'fecha'       => $fecha,
				'cuenta'      => $cuenta,
				'referencia'  => 'interes:' . $cuenta,
			)
		);

		return ! is_wp_error( $res );
	}

	/* ─────────────────────────────  Handlers  ────────────────────────────── */

	private static function puede() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}
	}

	private static function volver( $args = array() ) {
		/*
		 * Un pago se puede anotar desde Deudas o desde la lista de cuentas en
		 * Movimientos. Devolver siempre a Deudas sacaba a la persona de donde
		 * estaba trabajando.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$desde = isset( $_POST['desde'] ) ? sanitize_key( wp_unslash( $_POST['desde'] ) ) : '';
		$vista = array_key_exists( $desde, Malka_CRM_Dashboard::views() ) ? $desde : 'deudas';
		$url   = Malka_CRM_Dashboard::view_url( $vista );

		if ( $args ) {
			$url = add_query_arg( $args, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	public static function handle_guardar() {
		check_admin_referer( 'malka_crm_deuda_guardar' );
		self::puede();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$d = array(
			'id'             => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			'nombre'         => isset( $_POST['nombre'] ) ? wp_unslash( $_POST['nombre'] ) : '',
			'tipo'           => isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : 'cuotas',
			'saldo'          => isset( $_POST['saldo'] ) ? self::pesos( $_POST['saldo'] ) : 0,
			'cuota'          => isset( $_POST['cuota'] ) ? self::pesos( $_POST['cuota'] ) : 0,
			'minimo'         => isset( $_POST['minimo'] ) ? self::pesos( $_POST['minimo'] ) : 0,
			'cuotas_total'   => isset( $_POST['cuotas_total'] ) ? (int) $_POST['cuotas_total'] : 0,
			'cuotas_pagadas' => isset( $_POST['cuotas_pagadas'] ) ? (int) $_POST['cuotas_pagadas'] : 0,
			'dia_pago'       => isset( $_POST['dia_pago'] ) ? (int) $_POST['dia_pago'] : 0,
			'fecha'          => isset( $_POST['fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha'] ) ) : '',
			'tasa'           => isset( $_POST['tasa'] ) ? (float) str_replace( ',', '.', (string) wp_unslash( $_POST['tasa'] ) ) : 0,
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$id = self::guardar( $d );

		self::volver( array( 'deuda' => $id ? 'ok' : 'falta' ) );
	}

	public static function handle_borrar() {
		check_admin_referer( 'malka_crm_deuda_borrar' );
		self::puede();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		self::borrar( $id );
		self::volver( array( 'deuda' => 'borrada' ) );
	}

	public static function handle_tarjeta() {
		check_admin_referer( 'malka_crm_deuda_tarjeta' );
		self::puede();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$d = array(
			'cuenta'          => isset( $_POST['cuenta'] ) ? (int) $_POST['cuenta'] : 0,
			'cupo'            => isset( $_POST['cupo'] ) ? self::pesos( $_POST['cupo'] ) : 0,
			'minimo'          => isset( $_POST['minimo'] ) ? self::pesos( $_POST['minimo'] ) : 0,
			'dia_facturacion' => isset( $_POST['dia_facturacion'] ) ? (int) $_POST['dia_facturacion'] : 0,
			'dia_pago'        => isset( $_POST['dia_pago'] ) ? (int) $_POST['dia_pago'] : 0,
			'tasa'            => isset( $_POST['tasa'] ) ? (float) str_replace( ',', '.', (string) wp_unslash( $_POST['tasa'] ) ) : 0,
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$ok = self::guardar_tarjeta( $d );

		self::volver( array( 'deuda' => $ok ? 'tarjeta' : 'falta' ) );
	}

	public static function handle_pagar() {
		check_admin_referer( 'malka_crm_deuda_pagar' );
		self::puede();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$clave  = isset( $_POST['clave'] ) ? sanitize_key( wp_unslash( $_POST['clave'] ) ) : '';
		$monto  = isset( $_POST['monto'] ) ? self::pesos( $_POST['monto'] ) : 0;
		$origen = isset( $_POST['origen'] ) ? (int) $_POST['origen'] : 0;
		$fecha  = isset( $_POST['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fecha'] ) ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$ok = self::pagar( $clave, $monto, $origen, $fecha );

		self::volver( array( 'deuda' => $ok ? 'pagada' : 'falta' ) );
	}

	public static function handle_interes() {
		check_admin_referer( 'malka_crm_deuda_interes' );
		self::puede();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$cuenta = isset( $_POST['cuenta'] ) ? (int) $_POST['cuenta'] : 0;
		$monto  = isset( $_POST['monto'] ) ? self::pesos( $_POST['monto'] ) : 0;
		$fecha  = isset( $_POST['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fecha'] ) ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$ok = self::anotar_interes( $cuenta, $monto, $fecha );

		self::volver( array( 'deuda' => $ok ? 'interes' : 'falta' ) );
	}

	public static function handle_extra() {
		check_admin_referer( 'malka_crm_deuda_extra' );
		self::puede();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$monto = isset( $_POST['extra'] ) ? self::pesos( $_POST['extra'] ) : 0;

		self::fijar_extra( $monto );
		self::volver( array( 'deuda' => 'extra' ) );
	}

	/**
	 * Un monto escrito a mano, con puntos o sin ellos.
	 *
	 * @param mixed $v Lo que vino del formulario.
	 * @return int
	 */
	private static function pesos( $v ) {
		return (int) preg_replace( '/[^\d]/', '', (string) wp_unslash( $v ) );
	}

	/* ──────────────────────────────  Pantalla  ───────────────────────────── */

	/**
	 * La vista de Deudas.
	 *
	 * @return string
	 */
	public static function render() {
		$lista = self::lista();
		$t     = self::totales( $lista );
		ob_start();
		echo '<div class="esc-vista esc-deudas">';

		/*
		 * Las dos cosas que hay que decir una vez —que acá sólo se hace
		 * aritmética, y qué supone el interés de un año— van detrás de la ⓘ.
		 * Son ciertas y tienen que estar; leerlas todos los días, no.
		 */
		$nota = sprintf(
			'<p>%1$s</p><p>%2$s</p>',
			esc_html__( 'Acá sólo se hace aritmética con tus números: no es asesoría financiera ni una recomendación de productos. El pago mínimo y la tasa los copias de tu estado de cuenta; el plugin no los adivina.', 'malka-crm' ),
			esc_html__( 'El interés de un año supone que el saldo no se mueve: es lo que te costaría quedarte como estás, no una proyección.', 'malka-crm' )
		);

		echo '<section class="esc-panel">';
		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'deudas',
			__( 'Lo que debes', 'malka-crm' ),
			__( 'Tarjetas, cuotas y créditos, con su fecha y su interés', 'malka-crm' ),
			Malka_CRM_Settings::money( $t['debe'] ),
			$t['cuantas'] ? sprintf( /* translators: cuántas */ _n( 'en %d deuda', 'en %d deudas', $t['cuantas'], 'malka-crm' ), $t['cuantas'] ) : '',
			$nota
		);

		echo self::mensaje(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $lista ) {
			printf(
				'<div class="esc-deudas__r"><span>%1$s <b>%2$s</b></span><span>%3$s <b>%4$s</b></span><span>%5$s <b>%6$s</b></span></div>',
				esc_html__( 'Interés este mes', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $t['interes'] ) ),
				esc_html__( 'Te compromete al mes', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $t['mes'] ) ),
				esc_html__( 'Interés en un año así', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $t['interes'] * 12 ) )
			);
		}


		if ( class_exists( 'Malka_CRM_Prestamos' ) ) {
			$deben = Malka_CRM_Prestamos::totales( Malka_CRM_Prestamos::lista( 'me_deben' ) );
			$debo  = Malka_CRM_Prestamos::totales( Malka_CRM_Prestamos::lista( 'debo' ) );

			if ( $deben['pendiente'] > 0 || $debo['pendiente'] > 0 ) {
				// Lo del banco más lo de personas, contra lo que te deben.
				$todo = (int) $t['debe'] + (int) $debo['pendiente'];

				printf(
					'<p class="esc-deudas__neto">%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: debes, 2: te deben, 3: neto */
							__( 'Debes %1$s y te deben %2$s: neto, %3$s.', 'malka-crm' ),
							Malka_CRM_Settings::money( $todo ),
							Malka_CRM_Settings::money( $deben['pendiente'] ),
							Malka_CRM_Settings::money( $todo - $deben['pendiente'] )
						)
					)
				);
			}
		}

		echo '</section>';

		echo self::panel_lista( $lista ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/*
		 * Lo que se paga sin que nadie lo pida —suscripciones, arriendo,
		 * internet— no es una deuda, pero se mira en el mismo momento: es la
		 * otra mitad de lo que tienes comprometido cada mes. Respeta el lente
		 * de arriba: en Estudio sólo lo del estudio, en Personal sólo lo tuyo.
		 */
		if ( class_exists( 'Malka_CRM_Suscripciones' ) ) {
			$lente = class_exists( 'Malka_CRM_Finance' ) ? Malka_CRM_Finance::ambito() : 'todo';
			echo Malka_CRM_Suscripciones::panel( $lente ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// El otro lado de la moneda: la plata que prestaste y no vuelve.
		if ( class_exists( 'Malka_CRM_Prestamos' ) ) {
			echo Malka_CRM_Prestamos::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/*
		 * Tres cosas se fueron de acá, cada una a donde se usa: «en qué orden
		 * pagarlas», adentro de cada deuda («¿Cómo la pago?»); anotar una
		 * deuda, al + de arriba; y las condiciones de cada tarjeta, al
		 * detalle de su cuenta, en Cuentas.
		 */

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * El acuse de lo último que hiciste.
	 *
	 * @return string
	 */
	private static function mensaje() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q = isset( $_GET['deuda'] ) ? sanitize_key( wp_unslash( $_GET['deuda'] ) ) : '';

		$dichos = array(
			'ok'       => __( 'Deuda guardada.', 'malka-crm' ),
			'borrada'  => __( 'Deuda borrada.', 'malka-crm' ),
			'tarjeta'  => __( 'Condiciones de la tarjeta guardadas.', 'malka-crm' ),
			'pagada'   => __( 'Pago anotado: salió de tu cuenta y bajó la deuda.', 'malka-crm' ),
			'interes'  => __( 'Interés anotado: subió lo que debes.', 'malka-crm' ),
			'extra'    => __( 'Anotado cuánto puedes poner al mes.', 'malka-crm' ),
			'falta'    => __( 'Faltó algún dato y no se guardó.', 'malka-crm' ),
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
	 * La lista de deudas, una por una.
	 *
	 * @param array[] $lista Lista.
	 * @return string
	 */
	private static function panel_lista( $lista ) {
		ob_start();
		echo '<section class="esc-panel" id="esc-deudas-lista">';
		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'calendario',
			__( 'Una por una', 'malka-crm' ),
			__( 'Qué vence primero y cuánto cuesta cada una', 'malka-crm' )
		);

		if ( ! $lista ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no hay ninguna. Agrega abajo tus compras en cuotas y tus créditos; las tarjetas de crédito aparecen solas cuando las creas como cuenta en Personal.', 'malka-crm' ) . '</p>';
			echo '</section>';
			return ob_get_clean();
		}

		// Lo que vence antes va arriba. Sin fecha, al final.
		usort(
			$lista,
			function ( $a, $b ) {
				if ( ! $a['vence'] && ! $b['vence'] ) {
					return 0;
				}
				if ( ! $a['vence'] ) {
					return 1;
				}
				if ( ! $b['vence'] ) {
					return -1;
				}
				if ( $a['vence'] === $b['vence'] ) {
					return 0;
				}
				return $a['vence'] < $b['vence'] ? -1 : 1;
			}
		);

		echo '<ul class="esc-deudas__l">';

		foreach ( $lista as $d ) {
			echo '<li class="esc-deuda">';

			$meta = array( $d['rotulo'] );

			if ( $d['personal'] ) {
				$meta[] = __( 'tuya', 'malka-crm' );
			} else {
				$meta[] = __( 'del estudio', 'malka-crm' );
			}

			if ( ! empty( $d['fecha'] ) ) {
				$meta[] = sprintf(
					/* translators: fecha */
					__( 'pedido el %s', 'malka-crm' ),
					date_i18n( 'j M Y', strtotime( $d['fecha'] ) )
				);
			}

			if ( $d['tasa'] > 0 ) {
				$meta[] = sprintf( /* translators: tasa */ __( '%s%% al mes', 'malka-crm' ), number_format_i18n( $d['tasa'], 2 ) );
			}

			printf(
				'<div class="esc-deuda__h"><span class="esc-deuda__t"><b>%1$s</b><em>%2$s</em></span><b class="esc-deuda__m">%3$s</b></div>',
				esc_html( $d['nombre'] ),
				esc_html( implode( ' · ', $meta ) ),
				esc_html( Malka_CRM_Settings::money( $d['saldo'] ) )
			);

			echo self::barra_avance( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			$dato = array();

			if ( $d['vence'] ) {
				list( $cuando, $tono ) = Malka_CRM_Pagos::cuando( (int) $d['dias'] );
				printf(
					'<p class="esc-deuda__v">%1$s <span class="esc-st esc-st--%2$s">%3$s</span></p>',
					esc_html( date_i18n( 'j M', strtotime( $d['vence'] ) ) ),
					esc_attr( $tono ),
					esc_html( $cuando )
				);
			} else {
				printf(
					'<p class="esc-deuda__v esc-deuda__v--sin">%s</p>',
					esc_html__( 'Sin día de pago anotado', 'malka-crm' )
				);
			}

			if ( $d['cuota'] > 0 ) {
				$dato[] = sprintf(
					/* translators: monto */
					$d['rotativa'] ? __( 'Pago mínimo %s', 'malka-crm' ) : __( 'Cuota %s', 'malka-crm' ),
					Malka_CRM_Settings::money( $d['cuota'] )
				);
			}

			if ( $d['interes'] > 0 ) {
				$dato[] = sprintf( /* translators: monto */ __( 'Interés del mes %s', 'malka-crm' ), Malka_CRM_Settings::money( $d['interes'] ) );
			}

			if ( ! $d['rotativa'] && $d['restantes'] > 0 ) {
				$dato[] = __( 'el interés ya va dentro de la cuota', 'malka-crm' );
			}

			if ( $d['cupo'] > 0 ) {
				$dato[] = sprintf(
					/* translators: 1: usado, 2: cupo */
					__( 'Usas %1$s de %2$s', 'malka-crm' ),
					Malka_CRM_Settings::money( $d['saldo'] ),
					Malka_CRM_Settings::money( $d['cupo'] )
				);
			}

			if ( $d['rotativa'] && $d['tasa'] <= 0 ) {
				$dato[] = __( 'falta la tasa: sin ella no se puede calcular el interés', 'malka-crm' );
			}

			if ( $dato ) {
				printf( '<p class="esc-deuda__d">%s</p>', esc_html( implode( ' · ', $dato ) ) );
			}

			echo self::ayuda( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::form_pagar( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			echo '</li>';
		}

		echo '</ul></section>';
		return ob_get_clean();
	}

	/**
	 * «¿Cómo la pago?»: lo que se puede decir de esta deuda en concreto.
	 *
	 * Vive dentro de la tarjeta y cerrada, porque es una pregunta que se
	 * hace de vez en cuando, no un dato que haya que leer todos los días.
	 * Cada deuda tiene su respuesta: una en cuotas ya está decidida, una
	 * tarjeta depende de cuánto le pongas encima al mínimo.
	 *
	 * @param array $d Fila de lista().
	 * @return string
	 */
	private static function ayuda( $d ) {
		$id    = 'esc-ayuda-' . sanitize_html_class( $d['clave'] );
		$extra = self::extra();

		ob_start();

		printf(
			'<button type="button" class="esc-deuda__ay" data-nota-btn aria-controls="%1$s" aria-expanded="false">%2$s</button>',
			esc_attr( $id ),
			esc_html__( '¿Cómo la pago?', 'malka-crm' )
		);

		printf( '<div class="esc-nota esc-deuda__ayc" id="%s" data-nota hidden>', esc_attr( $id ) );

		if ( empty( $d['rotativa'] ) ) {
			// En cuotas no hay estrategia: hay una fecha de término.
			$s = self::sola( $d );

			if ( $s['meses'] > 0 ) {
				printf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: cuántas cuotas, 2: la cuota, 3: cuándo termina, 4: lo que falta pagar */
							__( 'Te quedan %1$d cuotas de %2$s: terminas en %3$s y de aquí al final pagas %4$s.', 'malka-crm' ),
							$s['meses'],
							Malka_CRM_Settings::money( $s['pago'] ),
							date_i18n( 'F \d\e Y', strtotime( '+' . $s['meses'] . ' months' ) ),
							Malka_CRM_Settings::money( $s['total'] )
						)
					)
				);
			}

			printf(
				'<p>%s</p>',
				esc_html__( 'Acá el interés ya viene adentro de la cuota, así que pagar de más no te ahorra interés por sí solo. Si quieres terminarla antes, pregunta en el banco por el prepago: a veces conviene y a veces tiene comisión.', 'malka-crm' )
			);

			echo '</div>';

			return ob_get_clean();
		}

		if ( $d['tasa'] <= 0 ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Para decirte cuánto te cuesta y cuánto demoras necesito la tasa mensual. Cópiala de tu estado de cuenta y anótala en «Las condiciones de cada tarjeta», más abajo.', 'malka-crm' )
			);
			echo '</div>';

			return ob_get_clean();
		}

		$solo = self::sola( $d );

		if ( ! $solo['cubre'] ) {
			printf(
				'<p class="esc-deuda__mal">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: el pago, 2: el interés del mes */
						__( 'Pagando %1$s al mes esta deuda no baja: el interés del mes es %2$s, así que el saldo crece. Lo primero es poner más que eso.', 'malka-crm' ),
						Malka_CRM_Settings::money( $solo['pago'] ),
						Malka_CRM_Settings::money( $d['interes'] )
					)
				)
			);
		} elseif ( $solo['cierra'] ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: el pago, 2: meses, 3: interés total */
						__( 'Pagando %1$s al mes la terminas en %2$d meses y pagas %3$s de interés en el camino.', 'malka-crm' ),
						Malka_CRM_Settings::money( $solo['pago'] ),
						$solo['meses'],
						Malka_CRM_Settings::money( $solo['interes'] )
					)
				)
			);
		}

		// Lo mismo, poniéndole encima lo que ella dijo que puede poner.
		if ( $extra > 0 ) {
			$con = self::sola( $d, $extra );

			if ( $con['cierra'] ) {
				$ahorro = $solo['cierra'] ? max( 0, $solo['interes'] - $con['interes'] ) : 0;

				printf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: extra, 2: pago total, 3: meses, 4: interés */
							__( 'Si le pones %1$s más al mes —%2$s en total— la terminas en %3$d meses y pagas %4$s de interés.', 'malka-crm' ),
							Malka_CRM_Settings::money( $extra ),
							Malka_CRM_Settings::money( $con['pago'] ),
							$con['meses'],
							Malka_CRM_Settings::money( $con['interes'] )
						)
					)
				);

				if ( $ahorro > 0 ) {
					printf(
						'<p><b>%s</b></p>',
						esc_html(
							sprintf(
								/* translators: 1: ahorro, 2: meses menos */
								__( 'Te ahorras %1$s y la terminas %2$d meses antes.', 'malka-crm' ),
								Malka_CRM_Settings::money( $ahorro ),
								max( 0, $solo['meses'] - $con['meses'] )
							)
						)
					);
				}
			}
		}

		// El número con el que se juega, acá mismo.
		printf(
			'<form method="post" action="%1$s" class="esc-deuda__f esc-deuda__f--ay">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_deuda_extra" />' .
			'<label><span>%3$s</span><input type="text" name="extra" inputmode="numeric" value="%4$s" /></label>' .
			'<button type="submit" class="esc-btn">%5$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_deuda_extra', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Cuánto puedes poner al mes por sobre los mínimos', 'malka-crm' ),
			esc_attr( $extra > 0 ? number_format( $extra, 0, ',', '.' ) : '' ),
			esc_html__( 'Recalcular', 'malka-crm' )
		);

		// Si hay más de una con interés, en qué orden conviene pagarlas.
		$c = self::comparar();

		if ( ! empty( $c['mejor'] ) && $c['ahorro'] > 0 ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: por cuál empezar, 2: ahorro */
						__( 'Tienes más de una con interés. Poniendo ese extra en la de %1$s te ahorras %2$s en total frente a la otra forma.', 'malka-crm' ),
						'tasa' === $c['mejor'] ? __( 'tasa más alta', 'malka-crm' ) : __( 'saldo más chico', 'malka-crm' ),
						Malka_CRM_Settings::money( $c['ahorro'] )
					)
				)
			);
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Cuánto llevas pagado de este crédito y cuántas cuotas quedan.
	 *
	 * Sólo tiene sentido donde hay cuotas: en una línea o una tarjeta el
	 * saldo sube y baja, no avanza hacia ninguna parte, y una barra ahí
	 * diría algo que no es cierto.
	 *
	 * @param array $d Fila de lista().
	 * @return string
	 */
	private static function barra_avance( $d ) {
		$cuotas = isset( $d['cuotas'] ) ? (int) $d['cuotas'] : 0;

		if ( ! empty( $d['rotativa'] ) || $cuotas <= 0 ) {
			return '';
		}

		$pagadas = isset( $d['pagadas'] ) ? (int) $d['pagadas'] : 0;
		$avance  = isset( $d['avance'] ) ? (int) $d['avance'] : 0;

		return sprintf(
			'<div class="esc-deuda__av">' .
			'<div class="esc-deuda__bar" role="img" aria-label="%6$s"><i style="width:%1$d%%"></i></div>' .
			'<p class="esc-deuda__avt"><span>%2$s</span><span>%3$s</span></p></div>',
			$avance,
			esc_html(
				sprintf(
					/* translators: 1: pagado, 2: total */
					__( 'Llevas %1$s de %2$s', 'malka-crm' ),
					Malka_CRM_Settings::money( isset( $d['pagado'] ) ? (int) $d['pagado'] : 0 ),
					Malka_CRM_Settings::money( isset( $d['total'] ) ? (int) $d['total'] : 0 )
				)
			),
			esc_html(
				$pagadas >= $cuotas
					? __( 'pagado entero', 'malka-crm' )
					: sprintf(
						/* translators: 1: cuota al día, 2: cuántas son, 3: cuántas faltan */
						__( 'cuota %1$d de %2$d · quedan %3$d', 'malka-crm' ),
						$pagadas + 1,
						$cuotas,
						(int) $d['restantes']
					)
			),
			'',
			'',
			esc_attr( sprintf( /* translators: porcentaje */ __( '%d%% pagado', 'malka-crm' ), $avance ) )
		);
	}

	/**
	 * El formulario para anotar un pago de esta deuda.
	 *
	 * @param array $d Fila.
	 * @return string
	 */
	private static function form_pagar( $d ) {
		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return '';
		}

		$opciones = '';

		foreach ( Malka_CRM_Finance::cuentas() as $id => $c ) {
			// De una tarjeta no sale plata para pagar otra deuda.
			if ( Malka_CRM_Finance::es_credito( (int) $id ) ) {
				continue;
			}

			$opciones .= sprintf(
				'<option value="%1$d">%2$s</option>',
				(int) $id,
				esc_html( Malka_CRM_Finance::nombre_cuenta( (int) $id ) )
			);
		}

		if ( ! $opciones ) {
			return '';
		}

		$sugerido = $d['cuota'] > 0 ? $d['cuota'] : $d['saldo'];
		$hoy      = current_time( 'Y-m-d' );

		ob_start();
		printf(
			'<details class="esc-deuda__mas"><summary>%s</summary>',
			esc_html__( 'Anotar un pago', 'malka-crm' )
		);

		printf(
			'<form method="post" action="%1$s" class="esc-deuda__f">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_deuda_pagar" />' .
			'<input type="hidden" name="clave" value="%3$s" />' .
			'<label><span>%4$s</span><input type="text" name="monto" inputmode="numeric" value="%5$s" /></label>' .
			'<label><span>%6$s</span><select name="origen">%7$s</select></label>' .
			'<label><span>%8$s</span><input type="date" name="fecha" value="%9$s" max="%9$s" /></label>' .
			'<button type="submit" class="esc-btn esc-btn--primary">%10$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_deuda_pagar', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( $d['clave'] ),
			esc_html__( 'Cuánto pagaste', 'malka-crm' ),
			esc_attr( $sugerido > 0 ? number_format( $sugerido, 0, ',', '.' ) : '' ),
			esc_html__( 'De qué cuenta salió', 'malka-crm' ),
			$opciones, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Cuándo', 'malka-crm' ),
			esc_attr( $hoy ),
			esc_html__( 'Anotar el pago', 'malka-crm' )
		);

		if ( 'tarjeta' === $d['clase'] && $d['cuenta'] ) {
			printf(
				'<form method="post" action="%1$s" class="esc-deuda__f">%2$s' .
				'<input type="hidden" name="action" value="malka_crm_deuda_interes" />' .
				'<input type="hidden" name="cuenta" value="%3$d" />' .
				'<label><span>%4$s</span><input type="text" name="monto" inputmode="numeric" /></label>' .
				'<label><span>%5$s</span><input type="date" name="fecha" value="%6$s" max="%6$s" /></label>' .
				'<button type="submit" class="esc-btn">%7$s</button></form>' .
				'<p class="esc-hint">%8$s</p>',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'malka_crm_deuda_interes', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				(int) $d['cuenta'],
				esc_html__( 'Intereses y cargos que te cobraron', 'malka-crm' ),
				esc_html__( 'Cuándo', 'malka-crm' ),
				esc_attr( current_time( 'Y-m-d' ) ),
				esc_html__( 'Anotar el interés', 'malka-crm' ),
				esc_html__( 'El pago baja la deuda; el interés la sube. Anotar los dos es lo que hace que el saldo de la tarjeta calce con tu estado de cuenta.', 'malka-crm' )
			);
		} elseif ( ! $d['rotativa'] ) {
			printf(
				'<p class="esc-hint">%s</p>',
				esc_html__( 'Al anotar el pago se marca una cuota más pagada.', 'malka-crm' )
			);
		}

		if ( ! empty( $d['editable'] ) ) {
			printf(
				'<form method="post" action="%1$s" class="esc-deuda__borrar">%2$s' .
				'<input type="hidden" name="action" value="malka_crm_deuda_borrar" />' .
				'<input type="hidden" name="id" value="%3$d" />' .
				'<button type="submit" class="esc-btn esc-btn--ghost">%4$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'malka_crm_deuda_borrar', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				(int) substr( $d['clave'], 1 ),
				esc_html__( 'Borrar esta deuda', 'malka-crm' )
			);
		}

		echo '</details>';
		return ob_get_clean();
	}


	/**
	 * Las condiciones de una tarjeta, para el detalle de su cuenta.
	 *
	 * Van donde está la tarjeta —al tocarla en Cuentas— y no en un panel
	 * aparte con todas juntas: cupo, mínimo y tasa son de esa tarjeta, y se
	 * corrigen mirándola. Es su propio formulario porque lo guarda otro
	 * handler que el de la cuenta.
	 *
	 * @param int $cuenta Id de la cuenta de crédito.
	 * @return string Vacío si esa cuenta no es una tarjeta.
	 */
	public static function campos_tarjeta( $cuenta ) {
		if ( ! class_exists( 'Malka_CRM_Finance' ) || ! Malka_CRM_Finance::es_credito( (int) $cuenta ) ) {
			return '';
		}

		$x = self::condiciones( (int) $cuenta );

		ob_start();
		printf(
			'<form method="post" action="%1$s" class="esc-cta__f esc-cta__tarj">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_deuda_tarjeta" />' .
			'<input type="hidden" name="cuenta" value="%3$d" />' .
			'<p class="esc-cta__tt">%4$s</p>' .
			'<label><span>%5$s</span><input type="text" name="cupo" inputmode="numeric" value="%6$s" /></label>' .
			'<label><span>%7$s</span><input type="text" name="minimo" inputmode="numeric" value="%8$s" /></label>' .
			'<label><span>%9$s</span><input type="number" name="tasa" step="0.01" min="0" max="20" value="%10$s" /></label>' .
			'<label><span>%11$s</span><input type="number" name="dia_facturacion" min="1" max="31" value="%12$s" /></label>' .
			'<label><span>%13$s</span><input type="number" name="dia_pago" min="1" max="31" value="%14$s" /></label>' .
			'<p class="esc-hint">%16$s</p>' .
			'<button type="submit" class="esc-btn esc-btn--primary">%15$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_deuda_tarjeta', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(int) $cuenta,
			esc_html__( 'Condiciones de la tarjeta — cópialas de tu estado de cuenta', 'malka-crm' ),
			esc_html__( 'Cupo total', 'malka-crm' ),
			esc_attr( $x['cupo'] ? number_format( $x['cupo'], 0, ',', '.' ) : '' ),
			esc_html__( 'Pago mínimo del estado de cuenta', 'malka-crm' ),
			esc_attr( $x['minimo'] ? number_format( $x['minimo'], 0, ',', '.' ) : '' ),
			esc_html__( 'Tasa mensual (%)', 'malka-crm' ),
			esc_attr( $x['tasa'] ? $x['tasa'] : '' ),
			esc_html__( 'Día de facturación', 'malka-crm' ),
			esc_attr( $x['dia_facturacion'] ? $x['dia_facturacion'] : '' ),
			esc_html__( 'Día de vencimiento', 'malka-crm' ),
			esc_attr( $x['dia_pago'] ? $x['dia_pago'] : '' ),
			esc_html__( 'Guardar las condiciones', 'malka-crm' ),
			esc_html__( 'El pago mínimo cámbialo cada mes: desde junio de 2026 se calcula como el monto no financiable más un 5% del financiable, y eso depende del desglose de tu estado de cuenta. Por eso se copia y no se calcula acá.', 'malka-crm' )
		);

		return ob_get_clean();
	}

	/**
	 * La ventana para anotar una deuda.
	 *
	 * Vive en el + de arriba, con todo lo demás que se crea, y no en un
	 * formulario al pie de la pantalla de deudas: anotar una deuda nueva es
	 * algo que pasa una vez cada tanto, no parte de mirar las que ya tienes.
	 *
	 * @return string
	 */
	public static function modal_nueva() {
		$opciones = '';

		foreach ( self::tipos() as $k => $rot ) {
			$opciones .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $rot ) );
		}

		ob_start();
		echo '<div class="esc-modal" id="esc-modal-deuda"><div class="esc-modal__c esc-nuevo-t">';
		printf( '<h3 class="esc-modal__h">%s</h3>', esc_html__( 'Anotar una deuda', 'malka-crm' ) );
		printf( '<p class="esc-modal__p">%s</p>', esc_html__( 'Una compra en cuotas, un crédito de consumo o una línea', 'malka-crm' ) );

		printf(
			'<form class="esc-nueva" method="post" action="%1$s">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_deuda_guardar" />' .
			'<label class="esc-nueva__q"><span>%3$s</span><input type="text" name="nombre" placeholder="%13$s" required /></label>' .
			'<label class="esc-nueva__c"><span>%4$s</span><select name="tipo">%5$s</select></label>' .
			'<label class="esc-nueva__c"><span>%12$s</span><input type="date" name="fecha" max="%14$s" /></label>' .
			'<label class="esc-nueva__c"><span>%6$s</span><input type="text" name="cuota" inputmode="numeric" /></label>' .
			'<label class="esc-nueva__c"><span>%7$s</span><input type="number" name="cuotas_total" min="0" max="120" /></label>' .
			'<label class="esc-nueva__c"><span>%8$s</span><input type="number" name="cuotas_pagadas" min="0" max="120" /></label>' .
			'<label class="esc-nueva__c"><span>%9$s</span><input type="text" name="saldo" inputmode="numeric" /></label>' .
			'<label class="esc-nueva__c"><span>%10$s</span><input type="number" name="tasa" step="0.01" min="0" max="20" /></label>' .
			'<label class="esc-nueva__c"><span>%11$s</span><input type="number" name="dia_pago" min="1" max="31" /></label>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_deuda_guardar', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Qué es', 'malka-crm' ),
			esc_html__( 'Tipo', 'malka-crm' ),
			$opciones, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Cuota mensual', 'malka-crm' ),
			esc_html__( 'Cuántas cuotas son', 'malka-crm' ),
			esc_html__( 'Cuántas llevas pagadas', 'malka-crm' ),
			esc_html__( 'Saldo (sólo para líneas)', 'malka-crm' ),
			esc_html__( 'Tasa mensual (%)', 'malka-crm' ),
			esc_html__( 'Día de pago', 'malka-crm' ),
			esc_html__( 'Cuándo lo pediste', 'malka-crm' ),
			esc_attr__( 'Notebook en 12 cuotas, CAE…', 'malka-crm' ),
			esc_attr( current_time( 'Y-m-d' ) )
		);

		printf(
			'<p class="esc-hint esc-nueva__q">%s</p>',
			esc_html__( 'En una compra en cuotas y en un crédito de consumo lo que cuenta es la cuota y cuántas quedan: el interés ya va adentro. En una línea de crédito, el saldo y la tasa.', 'malka-crm' )
		);

		echo '<div class="esc-modal__f esc-nueva__pie">';
		printf( '<button type="button" class="esc-btn" data-cerrar-modal>%s</button>', esc_html__( 'Cancelar', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Anotar', 'malka-crm' ) );
		echo '</div>';
		echo '</form>';
		echo '</div></div>';

		return ob_get_clean();
	}
}
