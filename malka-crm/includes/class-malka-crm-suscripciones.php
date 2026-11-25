<?php
/**
 * Lo que se paga todos los meses sin que nadie lo pida: suscripciones,
 * arriendo, internet, hosting, la contadora.
 *
 * Esta pantalla no inventa una lista nueva. Es la misma que vivía en
 * Configuración → «Costos fijos del estudio», que hasta ahora sólo se podía
 * editar desde WP-Admin y donde todo se suponía del estudio y mensual. Acá
 * cada fila gana dos cosas:
 *
 * 1. Un ámbito: del estudio o tuyo. No es una etiqueta de adorno. Lo que
 *    dice «estudio» entra al costo fijo con el que se calcula tu tarifa por
 *    hora; lo que dice «personal», no. Anotar Netflix como costo del estudio
 *    infla la tarifa que le cobras a tus clientes.
 *
 * 2. Un ciclo: al mes o al año. Un dominio de 15 USD al año no es un costo
 *    de 15 USD al mes. Para los totales, lo anual se divide en doce; para el
 *    calendario de pagos, se cobra entero el mes que toca.
 *
 * El tipo de cambio sale del Banco Central (Settings::fx). Si no hay valor,
 * el monto en pesos queda en cero y se dice; no se adivina un dólar.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suscripciones y pagos fijos.
 */
class Malka_CRM_Suscripciones {

	/** Los ámbitos posibles. */
	const AMBITOS = array( 'estudio', 'personal' );

	/** Los ciclos posibles. */
	const CICLOS = array( 'mensual', 'anual' );

	/**
	 * De qué tipo es el cobro.
	 *
	 * Es la misma lista: un arriendo y el Adobe se pagan igual, todos los
	 * meses y sin que nadie los pida. Pero no se deciden igual —el arriendo
	 * no se da de baja un martes y una suscripción sí—, así que se miran
	 * en dos pantallas. Lo que venía de antes queda como costo fijo, que es
	 * el nombre que esta lista tuvo siempre: clasificarlo a dedo habría
	 * puesto etiquetas que nadie eligió.
	 */
	const TIPOS = array( 'fijo', 'suscripcion' );

	public function __construct() {
		add_action( 'admin_post_malka_crm_sus_guardar', array( __CLASS__, 'handle_guardar' ) );
		add_action( 'admin_post_malka_crm_sus_borrar', array( __CLASS__, 'handle_borrar' ) );
		add_action( 'wp_ajax_malka_crm_sus_detalle', array( __CLASS__, 'ajax_detalle' ) );
		add_action( 'wp_ajax_malka_crm_sus_calendario', array( __CLASS__, 'ajax_calendario' ) );
		add_action( 'wp_ajax_malka_crm_sus_pagar', array( __CLASS__, 'ajax_pagar' ) );
		add_action( 'wp_ajax_malka_crm_sus_deshacer', array( __CLASS__, 'ajax_deshacer' ) );
	}

	/* ──────────────────────────────  Datos  ──────────────────────────────── */

	/**
	 * Cómo se llama cada ámbito.
	 *
	 * @return array
	 */
	public static function ambitos() {
		return array(
			'estudio'  => __( 'Del estudio', 'malka-crm' ),
			'personal' => __( 'Personal', 'malka-crm' ),
		);
	}

	/**
	 * Cómo se llama cada ciclo.
	 *
	 * @return array
	 */
	public static function ciclos() {
		return array(
			'mensual' => __( 'Al mes', 'malka-crm' ),
			'anual'   => __( 'Al año', 'malka-crm' ),
		);
	}

	/**
	 * Cómo se llama cada tipo.
	 *
	 * @return array
	 */
	public static function tipos() {
		return array(
			'fijo'        => __( 'Costo fijo', 'malka-crm' ),
			'suscripcion' => __( 'Suscripción', 'malka-crm' ),
		);
	}

	/**
	 * Las que hay, ya normalizadas y con su monto al mes en pesos.
	 *
	 * @param string $ambito 'estudio', 'personal' o '' para todas.
	 * @param string $tipo   'fijo', 'suscripcion' o '' para los dos.
	 * @return array[] id, nombre, monto, moneda, ciclo, mes, dia, variable,
	 *                 cuenta, ambito, tipo, clp (el cobro), clp_mes
	 *                 (repartido), proximo (Y-m-d o '').
	 */
	public static function lista( $ambito = '', $tipo = '' ) {
		$tipo = in_array( $tipo, self::TIPOS, true ) ? $tipo : '';
		$out  = array();

		foreach ( self::crudas() as $c ) {
			if ( $ambito && $c['ambito'] !== $ambito ) {
				continue;
			}
			if ( $tipo && $c['tipo'] !== $tipo ) {
				continue;
			}
			$out[] = $c;
		}

		/*
		 * Lo que se cobra antes, arriba. Las que no tienen fecha van al final
		 * ordenadas por lo que cuestan: sin día no hay cuándo, pero sí cuánto.
		 */
		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['proximo'] && $b['proximo'] ) {
					return strcmp( $a['proximo'], $b['proximo'] );
				}
				if ( $a['proximo'] ) {
					return -1;
				}
				if ( $b['proximo'] ) {
					return 1;
				}
				return $b['clp_mes'] - $a['clp_mes'];
			}
		);

		return $out;
	}

	/**
	 * Una sola, por id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function una( $id ) {
		foreach ( self::crudas() as $c ) {
			if ( (int) $c['id'] === (int) $id ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Las filas guardadas, con los campos nuevos puestos y un id estable.
	 *
	 * Las que venían de antes no tienen ámbito ni ciclo: eran todas del
	 * estudio y todas mensuales, y así quedan. El id se escribe una vez, la
	 * primera vez que se mira la lista, para poder editar y borrar sin
	 * depender de la posición.
	 *
	 * @return array[]
	 */
	private static function crudas() {
		$filas = Malka_CRM_Settings::get( 'costos', array() );

		if ( ! is_array( $filas ) ) {
			return array();
		}

		$monedas = Malka_CRM_Settings::currencies();
		$out     = array();
		$falta   = false;
		$max     = 0;

		foreach ( $filas as $f ) {
			if ( is_array( $f ) && ! empty( $f['id'] ) ) {
				$max = max( $max, (int) $f['id'] );
			}
		}

		foreach ( $filas as $i => $f ) {
			if ( ! is_array( $f ) || empty( $f['nombre'] ) ) {
				continue;
			}

			$id = isset( $f['id'] ) ? (int) $f['id'] : 0;

			if ( $id < 1 ) {
				$id    = ++$max;
				$falta = true;
			}

			$moneda = isset( $f['moneda'] ) ? strtoupper( (string) $f['moneda'] ) : 'CLP';
			if ( ! isset( $monedas[ $moneda ] ) ) {
				$moneda = 'CLP';
			}

			$monto  = isset( $f['monto'] ) ? (float) $f['monto'] : 0;
			$monto  = ( 'CLP' === $moneda ) ? (int) round( $monto ) : round( $monto, 2 );
			$ciclo  = isset( $f['ciclo'] ) && in_array( $f['ciclo'], self::CICLOS, true ) ? $f['ciclo'] : 'mensual';
			$ambito = isset( $f['ambito'] ) && in_array( $f['ambito'], self::AMBITOS, true ) ? $f['ambito'] : 'estudio';
			$tipo   = isset( $f['tipo'] ) && in_array( $f['tipo'], self::TIPOS, true ) ? $f['tipo'] : 'fijo';
			$dia    = isset( $f['dia'] ) ? (int) $f['dia'] : 0;
			$dia    = ( $dia >= 1 && $dia <= 31 ) ? $dia : 0;
			$mes    = isset( $f['mes'] ) ? (int) $f['mes'] : 0;
			$mes    = ( $mes >= 1 && $mes <= 12 ) ? $mes : 0;

			/*
			 * Cuánto cuesta, en pesos. Hay dos respuestas y no dan lo mismo:
			 * el monto por el cambio de hoy, y lo que de verdad llegó a la
			 * cartola los meses pasados —ese cambio más lo que le sume el
			 * banco—. Cuando hay con qué, manda lo que se pagó.
			 */
			$cambio = Malka_CRM_Settings::to_clp( $monto, $moneda );
			$real   = self::promedio_real(
				array(
					'nombre' => (string) $f['nombre'],
					'ciclo'  => $ciclo,
				)
			);

			$clp     = $real > 0 ? $real : $cambio;
			$clp_mes = 'anual' === $ciclo ? (int) round( $clp / 12 ) : $clp;

			$out[] = array(
				'id'       => $id,
				'orden'    => (int) $i,
				'nombre'   => (string) $f['nombre'],
				'monto'    => $monto,
				'moneda'   => $moneda,
				'ciclo'    => $ciclo,
				'mes'      => $mes,
				'dia'      => $dia,
				'variable' => ! empty( $f['variable'] ),
				'cuenta'   => isset( $f['cuenta'] ) ? (int) $f['cuenta'] : 0,
				'ambito'   => $ambito,
				'tipo'     => $tipo,
				'clp'      => $clp,
				'clp_mes'  => $clp_mes,
				// De dónde salió ese número, para poder decirlo en pantalla.
				'cambio'   => $cambio,
				'real'     => $real,
				'fuente'   => $real > 0 ? 'real' : 'cambio',
				'proximo'  => self::proximo( $ciclo, $mes, $dia ),
			);
		}

		if ( $falta ) {
			self::escribir( $out );
		}

		return $out;
	}

	/**
	 * Cuándo se cobra la próxima vez.
	 *
	 * Sin día no hay fecha: antes que inventar un día 1, se devuelve vacío y
	 * la pantalla pide el dato.
	 *
	 * @param string $ciclo 'mensual' o 'anual'.
	 * @param int    $mes   Mes del cobro anual (1-12).
	 * @param int    $dia   Día del cobro (1-31).
	 * @return string Y-m-d o ''.
	 */
	public static function proximo( $ciclo, $mes, $dia ) {
		$dia = (int) $dia;

		if ( $dia < 1 || $dia > 31 ) {
			return '';
		}

		$hoy = current_time( 'Y-m-d' );

		if ( 'anual' === $ciclo ) {
			if ( $mes < 1 || $mes > 12 ) {
				return '';
			}
			$anio = (int) current_time( 'Y' );
			$f    = self::fecha( $anio, (int) $mes, $dia );
			return $f >= $hoy ? $f : self::fecha( $anio + 1, (int) $mes, $dia );
		}

		$f = self::fecha( (int) current_time( 'Y' ), (int) current_time( 'n' ), $dia );

		if ( $f >= $hoy ) {
			return $f;
		}

		$sig = strtotime( substr( $hoy, 0, 7 ) . '-01 +1 month' );

		return self::fecha( (int) gmdate( 'Y', $sig ), (int) gmdate( 'n', $sig ), $dia );
	}

	/**
	 * Una fecha que existe: el 31 en un mes de 30 cae el 30.
	 *
	 * @param int $anio Año.
	 * @param int $mes  Mes.
	 * @param int $dia  Día.
	 * @return string Y-m-d
	 */
	private static function fecha( $anio, $mes, $dia ) {
		$primero = sprintf( '%04d-%02d-01', $anio, $mes );
		$ultimo  = (int) gmdate( 't', strtotime( $primero ) );

		return sprintf( '%04d-%02d-%02d', $anio, $mes, min( $dia, $ultimo ) );
	}

	/* ──────────────────────────  Lo ya pagado  ───────────────────────────── */

	/**
	 * La clave con la que se recuerda lo pagado de este cobro.
	 *
	 * @param array|string $s Cobro o su nombre.
	 * @return string
	 */
	public static function clave( $s ) {
		$nombre = is_array( $s ) ? $s['nombre'] : (string) $s;

		return 'costo-' . sanitize_title( $nombre );
	}

	/**
	 * El año de un cobro, cobro por cobro: lo pagado, lo que viene y lo
	 * que se pasó.
	 *
	 * La lista de arriba dice cuánto cuesta algo al mes y cuándo toca la
	 * próxima vez. Lo que no decía es el año entero: cuáles ya se pagaron,
	 * cuáles quedaron sin marcar y cuánto llevas gastado en esto. Eso es
	 * lo que arma esto, leyendo lo que ya hay guardado mes a mes.
	 *
	 * Un cobro sin día no tiene calendario: no se inventa un día 1, se
	 * devuelve vacío y la pantalla pide el dato. Un cobro anual tiene una
	 * sola fila al año, en su mes.
	 *
	 * @param array      $s    El cobro.
	 * @param string|int $anio El año. Vacío: el que corre.
	 * @return array filas y un resumen (cuántas pagadas, cuánto, la próxima).
	 */
	public static function calendario( $s, $anio = '' ) {
		$anio  = $anio ? (int) $anio : (int) current_time( 'Y' );
		$hoy   = current_time( 'Y-m-d' );
		$dia   = isset( $s['dia'] ) ? (int) $s['dia'] : 0;
		$vacio = array(
			'anio'     => $anio,
			'filas'    => array(),
			'pagadas'  => 0,
			'pagado'   => 0,
			'proxima'  => null,
			'sin_dia'  => ( $dia < 1 ),
		);

		if ( $dia < 1 ) {
			return $vacio;
		}

		$anual = isset( $s['ciclo'] ) && 'anual' === $s['ciclo'];
		$meses = $anual ? array( max( 1, min( 12, (int) $s['mes'] ) ) ) : range( 1, 12 );

		if ( $anual && ( empty( $s['mes'] ) || $s['mes'] < 1 ) ) {
			// Anual sin mes tampoco tiene cuándo.
			$vacio['sin_dia'] = true;
			return $vacio;
		}

		$filas   = array();
		$pagadas = 0;
		$pagado  = 0;
		$proxima = null;

		foreach ( $meses as $m ) {
			$ym     = sprintf( '%04d-%02d', $anio, $m );
			$ultimo = (int) gmdate( 't', strtotime( $ym . '-01' ) );
			$fecha  = sprintf( '%s-%02d', $ym, min( $ultimo, $dia ) );
			$ya     = self::pagado( $s, $ym );

			if ( $ya ) {
				$estado = 'pagada';
				$monto  = (int) $ya['monto'];
				$pagadas++;
				$pagado += $monto;
			} elseif ( $fecha < $hoy ) {
				$estado = 'vencida';
				$monto  = (int) $s['clp'];
			} else {
				$estado = 'futura';
				$monto  = (int) $s['clp'];
			}

			$fila = array(
				'mes'    => $ym,
				'fecha'  => $fecha,
				'monto'  => $monto,
				'estado' => $estado,
				// Cuándo se pagó de verdad, que puede no ser el día del cobro.
				'cuando' => $ya && ! empty( $ya['fecha'] ) ? (string) $ya['fecha'] : '',
				'cuenta' => $ya && ! empty( $ya['cuenta'] ) ? (int) $ya['cuenta'] : 0,
			);

			/*
			 * La próxima es la primera sin pagar que todavía no se pasó. Se
			 * marca aparte del estado para que la pantalla la pueda destacar
			 * sin perder que es, además, una futura.
			 */
			if ( null === $proxima && 'futura' === $estado ) {
				$proxima      = $fila;
				$fila['toca'] = true;
			}

			$filas[] = $fila;
		}

		return array(
			'anio'    => $anio,
			'filas'   => $filas,
			'pagadas' => $pagadas,
			'pagado'  => $pagado,
			'proxima' => $proxima,
			'sin_dia' => false,
		);
	}

	/**
	 * Qué día cae el cobro en un mes dado.
	 *
	 * Un cobro el 31 en un mes de 30 cae el 30: no se salta el mes.
	 *
	 * @param array  $s  El cobro.
	 * @param string $ym Y-m.
	 * @return string Y-m-d.
	 */
	public static function dia_de( $s, $ym ) {
		$dia    = isset( $s['dia'] ) ? (int) $s['dia'] : 1;
		$dia    = ( $dia >= 1 && $dia <= 31 ) ? $dia : 1;
		$ultimo = (int) gmdate( 't', strtotime( $ym . '-01' ) );

		return sprintf( '%s-%02d', $ym, min( $ultimo, $dia ) );
	}

	/**
	 * Lo que se pagó de este cobro en un mes, o null si no está marcado.
	 *
	 * @param array  $s   Cobro.
	 * @param string $mes Y-m. Vacío: el mes en curso.
	 * @return array|null monto, fecha, cuenta, mov.
	 */
	public static function pagado( $s, $mes = '' ) {
		if ( ! class_exists( 'Malka_CRM_Pagos' ) ) {
			return null;
		}

		$mes    = $mes ? $mes : current_time( 'Y-m' );
		$hechos = Malka_CRM_Pagos::hechos( $mes );
		$k      = self::clave( $s );

		if ( ! isset( $hechos[ $k ] ) ) {
			return null;
		}

		$h = $hechos[ $k ];

		return array(
			'monto'  => isset( $h['monto'] ) ? (int) $h['monto'] : 0,
			'fecha'  => isset( $h['fecha'] ) ? (string) $h['fecha'] : '',
			'cuenta' => isset( $h['cuenta'] ) ? (int) $h['cuenta'] : 0,
			'mov'    => isset( $h['mov'] ) ? (int) $h['mov'] : 0,
		);
	}

	/**
	 * Lo que se pagó de este cobro, mes a mes, del más nuevo al más viejo.
	 *
	 * Es el dato que importa cuando el cargo viene en dólares: lo que llega
	 * a la cartola no es el monto por el cambio del día, es ese cambio más
	 * lo que le sume el banco, y cambia todos los meses.
	 *
	 * @param array $s      Cobro.
	 * @param int   $cuantos Cuántos meses mirar hacia atrás.
	 * @return array[] mes, monto, fecha, cuenta.
	 */
	public static function historial( $s, $cuantos = 12 ) {
		if ( ! class_exists( 'Malka_CRM_Pagos' ) ) {
			return array();
		}

		$k   = self::clave( $s );
		$out = array();
		$mes = current_time( 'Y-m' ) . '-01';

		for ( $i = 0; $i < max( 1, (int) $cuantos ); $i++ ) {
			$ym     = gmdate( 'Y-m', strtotime( $mes . ' -' . $i . ' month' ) );
			$hechos = Malka_CRM_Pagos::hechos( $ym );

			if ( ! isset( $hechos[ $k ] ) ) {
				continue;
			}

			$out[] = array(
				'mes'    => $ym,
				'monto'  => isset( $hechos[ $k ]['monto'] ) ? (int) $hechos[ $k ]['monto'] : 0,
				'fecha'  => isset( $hechos[ $k ]['fecha'] ) ? (string) $hechos[ $k ]['fecha'] : '',
				'cuenta' => isset( $hechos[ $k ]['cuenta'] ) ? (int) $hechos[ $k ]['cuenta'] : 0,
			);
		}

		return $out;
	}

	/**
	 * El promedio de lo que de verdad se ha pagado.
	 *
	 * Manda sobre la estimación cuando hay con qué: tres meses de cargos
	 * reales dicen mejor lo que cuesta algo en dólares que el dólar de hoy.
	 * Con menos de tres, devuelve 0 y quien pregunte usa la estimación.
	 *
	 * @param array $s      Cobro.
	 * @param int   $minimo Cuántos meses hacen falta.
	 * @return int Pesos, o 0 si todavía no hay suficientes.
	 */
	public static function promedio_real( $s, $minimo = 0 ) {
		$anual = isset( $s['ciclo'] ) && 'anual' === $s['ciclo'];

		/*
		 * Un cobro anual no tiene tres meses que promediar: tiene un pago al
		 * año. Ahí el último cargo real ya es el mejor dato que existe, y se
		 * busca tres años atrás. Uno mensual necesita tres para que un mes
		 * raro no mande.
		 */
		$ventana = $anual ? 36 : 6;
		$minimo  = $minimo > 0 ? (int) $minimo : ( $anual ? 1 : 3 );

		$h = self::historial( $s, $ventana );

		// Un mes sin cargo —se dio de baja, o no tocaba— no promedia.
		$montos = array();

		foreach ( $h as $x ) {
			if ( $x['monto'] > 0 ) {
				$montos[] = $x['monto'];
			}
		}

		if ( count( $montos ) < $minimo ) {
			return 0;
		}

		// En lo anual manda el último, no el promedio de años distintos:
		// un dominio sube de precio y el promedio arrastra el precio viejo.
		if ( $anual ) {
			return (int) $montos[0];
		}

		return (int) round( array_sum( $montos ) / count( $montos ) );
	}

	/**
	 * Los nombres que están dos veces.
	 *
	 * Importa más de lo que parece: la marca de «ya lo pagué» se guarda por
	 * el nombre del cobro, así que dos filas que se llaman igual comparten
	 * la marca —pagar una da por pagada la otra— y el mes queda descuadrado.
	 *
	 * @return string[] Los nombres repetidos, como se escribieron la primera
	 *                  vez.
	 */
	public static function repetidos() {
		$vistos = array();
		$dobles = array();

		foreach ( self::lista() as $s ) {
			$k = sanitize_title( $s['nombre'] );

			if ( '' === $k ) {
				continue;
			}

			if ( isset( $vistos[ $k ] ) ) {
				$dobles[ $k ] = $vistos[ $k ];
				continue;
			}

			$vistos[ $k ] = $s['nombre'];
		}

		return array_values( $dobles );
	}

	/**
	 * Cuánto suma, al mes y al año, por ámbito.
	 *
	 * @param string $ambito 'estudio', 'personal' o '' para todas.
	 * @return array mes, anio, cuantas, sin_cambio (cuántas no se pudieron
	 *               convertir a pesos).
	 */
	public static function totales( $ambito = '', $tipo = '' ) {
		$mes     = 0;
		$cuantas = 0;
		$sin     = 0;

		foreach ( self::lista( $ambito, $tipo ) as $s ) {
			$cuantas++;
			$mes += $s['clp_mes'];

			// Falta sólo si tampoco hay pagos reales de los que salir.
			if ( 'CLP' !== $s['moneda'] && $s['monto'] > 0 && 0 === $s['clp'] ) {
				$sin++;
			}
		}

		return array(
			'mes'        => (int) $mes,
			'anio'       => (int) $mes * 12,
			'cuantas'    => $cuantas,
			'sin_cambio' => $sin,
		);
	}

	/**
	 * Lo que se paga al mes en nombre del estudio, para calcular la tarifa.
	 *
	 * @return int Pesos.
	 */
	public static function mes_estudio() {
		$t = self::totales( 'estudio' );
		return $t['mes'];
	}

	/**
	 * Las que se cobran dentro de un mes dado, para el calendario de pagos.
	 *
	 * @param string $mes    Y-m.
	 * @param string $ambito Ámbito, o '' para todos.
	 * @return array[]
	 */
	public static function del_mes( $mes, $ambito = '' ) {
		$mes = (string) $mes;

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $mes ) ) {
			return array();
		}

		$n   = (int) substr( $mes, 5, 2 );
		$out = array();

		foreach ( self::lista( $ambito ) as $s ) {
			if ( ! $s['dia'] ) {
				continue;
			}
			// Una anual sólo se cobra en su mes.
			if ( 'anual' === $s['ciclo'] && (int) $s['mes'] !== $n ) {
				continue;
			}
			$out[] = $s;
		}

		return $out;
	}

	/* ─────────────────────────────  Guardar  ─────────────────────────────── */

	/**
	 * Guarda una, nueva o existente.
	 *
	 * @param array $d Datos.
	 * @return int|false El id, o false si faltó el nombre.
	 */
	public static function guardar( $d ) {
		$nombre = isset( $d['nombre'] ) ? sanitize_text_field( (string) $d['nombre'] ) : '';

		if ( '' === $nombre ) {
			return false;
		}

		$filas = self::crudas();
		$id    = isset( $d['id'] ) ? (int) $d['id'] : 0;
		$donde = null;

		foreach ( $filas as $i => $f ) {
			if ( (int) $f['id'] === $id && $id > 0 ) {
				$donde = $i;
				break;
			}
		}

		$monedas = Malka_CRM_Settings::currencies();
		$moneda  = isset( $d['moneda'] ) ? strtoupper( sanitize_text_field( (string) $d['moneda'] ) ) : 'CLP';

		if ( ! isset( $monedas[ $moneda ] ) ) {
			$moneda = 'CLP';
		}

		$monto = self::monto( isset( $d['monto'] ) ? $d['monto'] : 0, $moneda );

		$ciclo  = isset( $d['ciclo'] ) && in_array( $d['ciclo'], self::CICLOS, true ) ? $d['ciclo'] : 'mensual';
		$ambito = isset( $d['ambito'] ) && in_array( $d['ambito'], self::AMBITOS, true ) ? $d['ambito'] : 'estudio';

		/*
		 * Al editar una que ya existe sin mandar el tipo, se respeta el que
		 * tenía: otra pantalla que la guarde no debe moverla de lista.
		 */
		if ( isset( $d['tipo'] ) && in_array( $d['tipo'], self::TIPOS, true ) ) {
			$tipo = $d['tipo'];
		} elseif ( null !== $donde && isset( $filas[ $donde ]['tipo'] ) ) {
			$tipo = $filas[ $donde ]['tipo'];
		} else {
			$tipo = 'fijo';
		}

		$dia = isset( $d['dia'] ) ? (int) $d['dia'] : 0;
		$dia = ( $dia >= 1 && $dia <= 31 ) ? $dia : 0;

		$mes = isset( $d['mes'] ) ? (int) $d['mes'] : 0;
		$mes = ( $mes >= 1 && $mes <= 12 ) ? $mes : 0;

		// El mes sólo significa algo en una anual.
		if ( 'anual' !== $ciclo ) {
			$mes = 0;
		}

		$nueva = array(
			'id'       => $id > 0 && null !== $donde ? $id : self::siguiente_id( $filas ),
			'nombre'   => $nombre,
			'monto'    => $monto,
			'moneda'   => $moneda,
			'ciclo'    => $ciclo,
			'mes'      => $mes,
			'dia'      => $dia,
			'variable' => empty( $d['variable'] ) ? 0 : 1,
			'cuenta'   => isset( $d['cuenta'] ) ? max( 0, (int) $d['cuenta'] ) : 0,
			'ambito'   => $ambito,
			'tipo'     => $tipo,
		);

		/*
		 * La clave con la que se recuerda un pago ya hecho sale del nombre.
		 * Al renombrarla, lo marcado como pagado quedaba colgando de una
		 * clave muerta y el cobro volvía a aparecer impago.
		 */
		if ( null !== $donde && class_exists( 'Malka_CRM_Pagos' ) && $filas[ $donde ]['nombre'] !== $nombre ) {
			Malka_CRM_Pagos::renombrar(
				'costo-' . sanitize_title( $filas[ $donde ]['nombre'] ),
				'costo-' . sanitize_title( $nombre )
			);
		}

		if ( null !== $donde ) {
			$filas[ $donde ] = array_merge( $filas[ $donde ], $nueva );
		} else {
			$filas[] = $nueva;
		}

		self::escribir( $filas );

		return (int) $nueva['id'];
	}

	/**
	 * Lee un monto escrito a mano.
	 *
	 * En pesos el punto es separador de miles: «$ 12.500» son doce mil
	 * quinientos, no doce con cinco. En dólares o euros hay decimales, y el
	 * separador puede ser coma o punto según cómo esté el teclado: manda el
	 * que esté más a la derecha, y el otro es el de los miles.
	 *
	 * @param mixed  $txt    Lo escrito.
	 * @param string $moneda Moneda.
	 * @return int|float
	 */
	public static function monto( $txt, $moneda = 'CLP' ) {
		$t = preg_replace( '/[^\d.,]/', '', (string) $txt );

		if ( '' === $t ) {
			return 'CLP' === $moneda ? 0 : 0.0;
		}

		if ( 'CLP' === $moneda ) {
			return (int) preg_replace( '/\D/', '', $t );
		}

		$coma  = strrpos( $t, ',' );
		$punto = strrpos( $t, '.' );

		if ( false !== $coma && ( false === $punto || $coma > $punto ) ) {
			// La coma decide: los puntos eran los miles.
			$t = str_replace( '.', '', $t );
			$t = str_replace( ',', '.', $t );
		} else {
			// El punto decide: las comas eran los miles.
			$t = str_replace( ',', '', $t );
		}

		return max( 0, round( (float) $t, 2 ) );
	}

	/**
	 * Borra una.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function borrar( $id ) {
		$filas = self::crudas();
		$out   = array();
		$fuera = false;

		foreach ( $filas as $f ) {
			if ( (int) $f['id'] === (int) $id ) {
				$fuera = true;
				continue;
			}
			$out[] = $f;
		}

		if ( $fuera ) {
			self::escribir( $out );
		}

		return $fuera;
	}

	/**
	 * El id que sigue.
	 *
	 * @param array[] $filas Filas.
	 * @return int
	 */
	private static function siguiente_id( $filas ) {
		$max = 0;

		foreach ( $filas as $f ) {
			$max = max( $max, isset( $f['id'] ) ? (int) $f['id'] : 0 );
		}

		return $max + 1;
	}

	/**
	 * Deja las filas en Configuración, con sólo los campos que se guardan.
	 *
	 * @param array[] $filas Filas.
	 */
	private static function escribir( $filas ) {
		$limpio = array();

		foreach ( $filas as $f ) {
			if ( empty( $f['nombre'] ) ) {
				continue;
			}

			$limpio[] = array(
				'id'       => isset( $f['id'] ) ? (int) $f['id'] : 0,
				'nombre'   => (string) $f['nombre'],
				'monto'    => isset( $f['monto'] ) ? $f['monto'] : 0,
				'moneda'   => isset( $f['moneda'] ) ? (string) $f['moneda'] : 'CLP',
				'ciclo'    => isset( $f['ciclo'] ) ? (string) $f['ciclo'] : 'mensual',
				'mes'      => isset( $f['mes'] ) ? (int) $f['mes'] : 0,
				'dia'      => isset( $f['dia'] ) ? (int) $f['dia'] : 0,
				'variable' => empty( $f['variable'] ) ? 0 : 1,
				'cuenta'   => isset( $f['cuenta'] ) ? (int) $f['cuenta'] : 0,
				'ambito'   => isset( $f['ambito'] ) ? (string) $f['ambito'] : 'estudio',
				'tipo'     => isset( $f['tipo'] ) ? (string) $f['tipo'] : 'fijo',
			);
		}

		Malka_CRM_Settings::update( array( 'costos' => $limpio ) );
	}

	/* ─────────────────────────────  Pantalla  ────────────────────────────── */

	/**
	 * El panel de la pantalla de Deudas.
	 *
	 * @param string $lente 'estudio', 'personal' o 'todo'.
	 * @param string $tipo  'fijo', 'suscripcion' o '' para los dos.
	 * @return string
	 */
	public static function panel( $lente = 'todo', $tipo = '' ) {
		$ambito = in_array( $lente, self::AMBITOS, true ) ? $lente : '';
		$tipo   = in_array( $tipo, self::TIPOS, true ) ? $tipo : '';
		$lista  = self::lista( $ambito, $tipo );
		$t      = self::totales( $ambito, $tipo );
		$est    = self::totales( 'estudio', $tipo );
		$per    = self::totales( 'personal', $tipo );

		$nota = sprintf(
			'<p>%1$s</p><p>%2$s</p>',
			esc_html__( 'Lo que dice «del estudio» entra al costo fijo con el que se calcula tu tarifa por hora. Lo personal no: si anotas tus suscripciones de casa como costo del estudio, la tarifa que cobras sale más alta de lo que corresponde.', 'malka-crm' ),
			esc_html__( 'Lo que se paga una vez al año se reparte en doce para el total mensual, y se cobra entero el mes que toca. Lo que está en dólares o euros se convierte con el tipo de cambio del Banco Central del día.', 'malka-crm' )
		);

		/*
		 * El título y el subtítulo dicen qué lista es ésta. Con las dos
		 * pantallas separadas —Costos fijos y Suscripciones— llamar «Lo que
		 * se paga solo» a las dos dejaba dos pantallas con el mismo nombre.
		 */
		if ( 'suscripcion' === $tipo ) {
			$titulo = __( 'Suscripciones', 'malka-crm' );
			// Arriba, el encabezado de la pantalla ya dice qué va acá: este
			// subtítulo da ejemplos en vez de repetir la misma frase.
			$sub    = __( 'Adobe, Figma, el hosting, el dominio', 'malka-crm' );
		} elseif ( 'fijo' === $tipo ) {
			$titulo = __( 'Costos fijos', 'malka-crm' );
			$sub    = __( 'El arriendo, la contadora, la luz, el internet', 'malka-crm' );
		} else {
			$titulo = __( 'Lo que se paga solo', 'malka-crm' );

			if ( 'estudio' === $ambito ) {
				$sub = __( 'Suscripciones y pagos fijos del estudio', 'malka-crm' );
			} elseif ( 'personal' === $ambito ) {
				$sub = __( 'Tus suscripciones y pagos fijos', 'malka-crm' );
			} else {
				$sub = __( 'Suscripciones y pagos fijos, del estudio y tuyos', 'malka-crm' );
			}
		}

		ob_start();

		/*
		 * La dirección y el permiso para pedir los datos de un cobro viajan
		 * en el panel. Antes salían de una variable global del escritorio, y
		 * si esa variable no estaba —una pantalla incrustada, una copia
		 * suelta— el lápiz no hacía nada y no se notaba.
		 */
		printf(
			'<section class="esc-panel esc-sus" id="esc-suscripciones" data-sus-ajax="%1$s" data-sus-nonce="%2$s" data-sus-npago="%3$s">',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'malka_crm_finance' ) ),
			// Marcar un pago es de Tu día y lleva su propia llave.
			esc_attr( wp_create_nonce( 'malka_crm_inicio' ) )
		);
		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'repite',
			$titulo,
			$sub,
			Malka_CRM_Settings::money( $t['mes'] ),
			$t['cuantas'] ? sprintf( /* translators: cuántas */ _n( 'al mes, en %d cobro', 'al mes, en %d cobros', $t['cuantas'], 'malka-crm' ), $t['cuantas'] ) : '',
			$nota,
			// Agregar vive arriba, donde se busca, y no al final de la lista.
			sprintf(
				'<button type="button" class="esc-ph__mas" data-sus-nueva aria-label="%1$s" title="%1$s">' .
				'<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">' .
				'<path d="M8 3.2v9.6M3.2 8h9.6"/></svg></button>',
				esc_attr__( 'Anotar un cobro fijo', 'malka-crm' )
			)
		);

		echo self::mensaje(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $lista ) {
			printf(
				'<div class="esc-sus__r"><span>%1$s <b>%2$s</b></span><span>%3$s <b>%4$s</b></span><span>%5$s <b>%6$s</b></span></div>',
				esc_html__( 'Del estudio', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $est['mes'] ) ),
				esc_html__( 'Tuyo', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $per['mes'] ) ),
				esc_html__( 'En un año', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $t['anio'] ) )
			);
		}

		if ( $t['sin_cambio'] > 0 ) {
			printf(
				'<p class="esc-sus__ojo">%s</p>',
				esc_html(
					sprintf(
						/* translators: cuántas */
						_n(
							'Hay %d cobro en otra moneda que no se pudo pasar a pesos: falta el tipo de cambio. El total de arriba no lo incluye.',
							'Hay %d cobros en otra moneda que no se pudieron pasar a pesos: falta el tipo de cambio. El total de arriba no los incluye.',
							$t['sin_cambio'],
							'malka-crm'
						),
						$t['sin_cambio']
					)
				)
			);
		}

		/*
		 * Si pagó algo del estudio con una tarjeta suya, esa plata la puso
		 * ella y el estudio se la debe. Se dice acá, que es donde se marcan
		 * esos pagos, con el camino a donde se salda.
		 */
		if ( class_exists( 'Malka_CRM_Reembolsos' ) ) {
			$deuda = Malka_CRM_Reembolsos::cuenta();

			if ( $deuda['debe'] > 0 ) {
				printf(
					'<p class="esc-sus__tuyo">%1$s <a href="%2$s">%3$s</a></p>',
					esc_html(
						sprintf(
							/* translators: monto */
							__( 'Pusiste %s de tu bolsillo en cobros del estudio: el estudio te los debe.', 'malka-crm' ),
							Malka_CRM_Settings::money( $deuda['debe'] )
						)
					),
					esc_url( Malka_CRM_Dashboard::view_url( 'cuentas' ) . '#esc-reembolso' ),
					esc_html__( 'Devolvértelos →', 'malka-crm' )
				);
			}
		}

		/*
		 * A cuántos les falta el día. Sin él el cobro cuenta en el total
		 * pero no se puede marcar pagado ni avisa cuando toca, y eso no se
		 * nota mirando la lista: se dice acá arriba.
		 */
		$sin_dia = 0;

		foreach ( $lista as $s ) {
			if ( ! $s['proximo'] ) {
				$sin_dia++;
			}
		}

		if ( $sin_dia > 0 ) {
			printf(
				'<p class="esc-sus__falta">%s</p>',
				esc_html(
					sprintf(
						/* translators: cuántos */
						_n(
							'A %d cobro le falta el día: hasta que lo tenga no avisa cuando toca ni se puede marcar pagado. Están abajo del todo.',
							'A %d cobros les falta el día: hasta que lo tengan no avisan cuando tocan ni se pueden marcar pagados. Están abajo del todo.',
							$sin_dia,
							'malka-crm'
						),
						$sin_dia
					)
				)
			);
		}

		$dobles = self::repetidos();

		if ( $dobles ) {
			printf(
				'<p class="esc-sus__ojo">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: nombres repetidos */
						__( 'Hay dos cobros con el mismo nombre (%1$s). Como lo pagado se recuerda por el nombre, marcar uno da por pagado el otro: conviene distinguirlos.', 'malka-crm' ),
						implode( ', ', $dobles )
					)
				)
			);
		}

		echo self::tabla( $lista, $tipo ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * El acuse de lo último que se hizo.
	 *
	 * @return string
	 */
	private static function mensaje() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q = isset( $_GET['sus'] ) ? sanitize_key( wp_unslash( $_GET['sus'] ) ) : '';

		$dichos = array(
			'ok'      => __( 'Cobro guardado.', 'malka-crm' ),
			'borrado' => __( 'Cobro borrado.', 'malka-crm' ),
			'falta'   => __( 'Faltó el nombre y no se guardó.', 'malka-crm' ),
		);

		if ( ! isset( $dichos[ $q ] ) ) {
			return '';
		}

		return sprintf(
			'<p class="esc-sus__ok%1$s">%2$s</p>',
			'falta' === $q ? ' is-mal' : '',
			esc_html( $dichos[ $q ] )
		);
	}

	/**
	 * La lista, una fila por cobro.
	 *
	 * @param array[] $lista Lista.
	 * @param string  $tipo  De qué pantalla es, para que el vacío hable de
	 *                       lo que va acá y no de la lista entera.
	 * @return string
	 */
	private static function tabla( $lista, $tipo = '' ) {
		ob_start();

		if ( ! $lista ) {
			if ( 'suscripcion' === $tipo ) {
				$vacio = __( 'Todavía no hay ninguna. Anota acá lo que se renueva solo y podrías dar de baja: Adobe, Figma, el hosting, el dominio, y también lo tuyo de casa. Cada una con su etiqueta.', 'malka-crm' );
			} elseif ( 'fijo' === $tipo ) {
				$vacio = __( 'Todavía no hay ninguno. Anota acá lo que pagas exista o no trabajo: el arriendo, la contadora, el internet, la luz. Cada uno con su etiqueta.', 'malka-crm' );
			} else {
				$vacio = __( 'Todavía no hay ninguno. Anota acá lo que se paga sin que nadie lo pida: Adobe, Figma, el hosting, el dominio, la contadora, el internet, y también lo tuyo de casa. Cada uno con su etiqueta.', 'malka-crm' );
			}

			printf( '<p class="esc-empty">%s</p>', esc_html( $vacio ) );
			return ob_get_clean();
		}

		echo '<div class="esc-sus__t">';
		printf(
			'<div class="esc-sus__f esc-sus__f--h" aria-hidden="true"><span>%1$s</span><span>%7$s</span><span>%2$s</span><span>%3$s</span><span>%4$s</span><span>%5$s</span><span>%6$s</span><span></span></div>',
			esc_html__( 'Qué', 'malka-crm' ),
			esc_html__( 'Cuánto', 'malka-crm' ),
			esc_html__( 'Al mes', 'malka-crm' ),
			esc_html__( 'Próximo cobro', 'malka-crm' ),
			esc_html__( 'Este mes', 'malka-crm' ),
			esc_html__( 'El año', 'malka-crm' ),
			esc_html__( 'De quién', 'malka-crm' )
		);

		/*
		 * Los que no tienen cuándo van aparte, al final. Mezclados con los
		 * que sí, la columna de la fecha queda medio vacía y no se entiende
		 * que a esos les falta un dato: se leen como si no tocaran nunca.
		 */
		$con = array();
		$sin = array();

		foreach ( $lista as $s ) {
			if ( $s['proximo'] ) {
				$con[] = $s;
			} else {
				$sin[] = $s;
			}
		}

		foreach ( $con as $s ) {
			echo self::fila( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $sin ) {
			printf(
				'<div class="esc-sus__sep"><b>%1$s</b><span>%2$s</span></div>',
				esc_html__( 'Sin día de cobro', 'malka-crm' ),
				esc_html__( 'Siguen contando en el total, pero no entran al calendario ni se pueden marcar como pagados hasta que tengan día.', 'malka-crm' )
			);

			foreach ( $sin as $s ) {
				echo self::fila( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Una fila.
	 *
	 * @param array $s Cobro.
	 * @return string
	 */
	private static function fila( $s ) {
		$ambitos = self::ambitos();
		$ciclos  = self::ciclos();

		// El monto como se cobra, en su moneda.
		if ( 'CLP' === $s['moneda'] ) {
			$cuanto = Malka_CRM_Settings::money( (int) $s['monto'] );
		} else {
			$cuanto = $s['moneda'] . ' ' . number_format_i18n( (float) $s['monto'], 2 );
		}

		/*
		 * «Al mes» en cada fila es ruido: casi todo se paga al mes. Se dice
		 * sólo cuando no es así, que es justo lo que hay que notar.
		 */
		if ( 'mensual' !== $s['ciclo'] ) {
			$cuanto .= ' · ' . $ciclos[ $s['ciclo'] ];
		}

		// Al mes, en pesos. Sin tipo de cambio se dice, no se inventa.
		if ( 'CLP' !== $s['moneda'] && $s['monto'] > 0 && 0 === $s['clp'] ) {
			$al_mes = '<i>' . esc_html__( 'falta el cambio', 'malka-crm' ) . '</i>';
		} else {
			$al_mes = esc_html( Malka_CRM_Settings::money( $s['clp_mes'] ) );

			/*
			 * El ≈ es para lo que todavía es una estimación. Cuando el
			 * número sale del promedio de lo que se pagó de verdad, ya no
			 * lo es, y se dice de dónde viene.
			 */
			if ( 'real' === $s['fuente'] ) {
				$al_mes .= '<em title="' . esc_attr__( 'Promedio de lo que pagaste de verdad los últimos meses, no el tipo de cambio de hoy', 'malka-crm' ) . '">' .
					esc_html__( 'real', 'malka-crm' ) . '</em>';
			} elseif ( $s['variable'] ) {
				$al_mes = '≈ ' . $al_mes;
			}
		}

		if ( $s['proximo'] ) {
			$cuando = esc_html( date_i18n( 'j \d\e F', strtotime( $s['proximo'] ) ) );
		} else {
			$cuando = '<i>' . esc_html__( 'sin día', 'malka-crm' ) . '</i>';
		}

		$donde = '';

		if ( $s['cuenta'] && class_exists( 'Malka_CRM_Finance' ) && method_exists( 'Malka_CRM_Finance', 'nombre_cuenta' ) ) {
			$donde = (string) Malka_CRM_Finance::nombre_cuenta( (int) $s['cuenta'] );
		}

		ob_start();
		printf(
			'<div class="esc-sus__f" data-sus="%1$d">' .
			'<span class="esc-sus__q"><b>%2$s</b>%5$s</span>' .
			'<span class="esc-sus__de2"><span class="esc-sus__et esc-sus__et--%3$s">%4$s</span></span>' .
			'<span class="esc-sus__m">%6$s</span>' .
			'<span class="esc-sus__mm">%7$s</span>' .
			'<span class="esc-sus__d">%8$s</span>' .
			'<span class="esc-sus__p">%11$s</span>' .
			'<span class="esc-sus__y">%12$s</span>' .
			'<span class="esc-sus__a"><button type="button" class="esc-sus__ed" data-sus-editar="%1$d" aria-label="%9$s">%10$s</button></span>' .
			'</div>',
			(int) $s['id'],
			esc_html( $s['nombre'] ),
			esc_attr( $s['ambito'] ),
			esc_html( $ambitos[ $s['ambito'] ] ),
			$donde ? '<span class="esc-sus__de">' . esc_html( sprintf( /* translators: cuenta */ __( 'sale de %s', 'malka-crm' ), $donde ) ) . '</span>' : '',
			esc_html( $cuanto ),
			$al_mes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$cuando, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			/* translators: nombre del cobro */
			esc_attr( sprintf( __( 'Editar %s', 'malka-crm' ), $s['nombre'] ) ),
			esc_html__( 'Editar', 'malka-crm' ),
			self::celda_pago( $s ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::celda_ano( $s ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		return ob_get_clean();
	}

	/**
	 * La columna del año: cuántos cobros van pagados y cuántos faltan.
	 *
	 * Doce puntos, uno por mes, del color de lo que pasó con cada uno. De
	 * un vistazo se ve en qué suscripción quedaron huecos, que es lo que no
	 * se podía saber mirando sólo «este mes». Tocarla abre el año entero.
	 *
	 * Los puntos son decoración: lo que leen los lectores de pantalla es el
	 * rótulo del botón, que dice lo mismo en palabras.
	 *
	 * @param array $s Cobro.
	 * @return string
	 */
	private static function celda_ano( $s ) {
		$cal = self::calendario( $s );

		if ( ! empty( $cal['sin_dia'] ) ) {
			return '<span class="esc-sus__sindia">' . esc_html__( 'sin día', 'malka-crm' ) . '</span>';
		}

		$puntos = '';
		$faltan = 0;

		foreach ( $cal['filas'] as $f ) {
			$puntos .= '<i class="is-' . esc_attr( $f['estado'] ) . '"></i>';

			if ( 'vencida' === $f['estado'] ) {
				$faltan++;
			}
		}

		$cuantas = count( $cal['filas'] );

		/*
		 * El número dice lo que importa: si falta marcar algo, cuánto; y si
		 * no falta nada, cuántas van. Dos preguntas distintas, no una.
		 */
		if ( $faltan ) {
			$texto = sprintf( /* translators: cuántos cobros */ _n( '%d sin marcar', '%d sin marcar', $faltan, 'malka-crm' ), $faltan );
			$clase = ' is-faltan';
		} else {
			$texto = sprintf( /* translators: 1: pagados, 2: total */ __( '%1$d de %2$d', 'malka-crm' ), (int) $cal['pagadas'], $cuantas );
			$clase = '';
		}

		return sprintf(
			'<button type="button" class="esc-sus__ano%5$s" data-sus-ano="%1$d" aria-label="%4$s">' .
				'<span class="esc-sus__pts" aria-hidden="true">%2$s</span><span class="esc-sus__cuenta">%3$s</span></button>',
			(int) $s['id'],
			$puntos, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $texto ),
			esc_attr(
				sprintf(
					/* translators: 1: nombre, 2: pagados, 3: total, 4: año */
					__( 'El año de %1$s: %2$d de %3$d cobros marcados en %4$d. Ábrelo para ver cuáles y marcar los que falten.', 'malka-crm' ),
					$s['nombre'],
					(int) $cal['pagadas'],
					$cuantas,
					(int) $cal['anio']
				)
			),
			$clase
		);
	}

	/**
	 * Lo que va en «Este mes»: el botón para marcarlo, o lo que ya se pagó.
	 *
	 * @param array $s Cobro.
	 * @return string
	 */
	private static function celda_pago( $s ) {
		$mes = current_time( 'Y-m' );

		// Lo que se paga una vez al año no se marca los otros once meses.
		if ( 'anual' === $s['ciclo'] && (int) $s['mes'] !== (int) substr( $mes, 5, 2 ) ) {
			return '<span class="esc-sus__no">' . esc_html__( 'No toca', 'malka-crm' ) . '</span>';
		}

		if ( ! $s['dia'] ) {
			return '<span class="esc-sus__no">—</span>';
		}

		$ya = self::pagado( $s, $mes );

		if ( $ya ) {
			$texto = Malka_CRM_Settings::money( $ya['monto'] );

			if ( $ya['fecha'] ) {
				$texto .= ' · ' . date_i18n( 'j M', strtotime( $ya['fecha'] ) );
			}

			$cta = '';

			if ( $ya['cuenta'] && class_exists( 'Malka_CRM_Finance' ) && method_exists( 'Malka_CRM_Finance', 'nombre_cuenta' ) ) {
				$cta = (string) Malka_CRM_Finance::nombre_cuenta( (int) $ya['cuenta'] );
			}

			return sprintf(
				'<button type="button" class="esc-sus__ya" data-sus-deshacer="%1$s" data-sus-desmes="%2$s" title="%3$s"><b>%4$s</b>%5$s</button>',
				esc_attr( self::clave( $s ) ),
				esc_attr( $mes ),
				esc_attr__( 'Marcado pagado. Tócalo para deshacer.', 'malka-crm' ),
				esc_html( $texto ),
				$cta ? '<i>' . esc_html( $cta ) . '</i>' : ''
			);
		}

		return sprintf(
			'<button type="button" class="esc-sus__pagar" data-sus-pagar="%1$d">%2$s</button>',
			(int) $s['id'],
			esc_html__( 'Pagar', 'malka-crm' )
		);
	}

	/**
	 * La ventana para anotar o corregir un cobro.
	 *
	 * @return string
	 */
	/**
	 * La ventana para anotar o corregir un cobro.
	 *
	 * @param string $tipo Con qué tipo viene marcado uno nuevo: el de la
	 *                     pantalla desde donde se anota.
	 * @return string
	 */
	public static function modal( $tipo = 'fijo' ) {
		$tipo = in_array( $tipo, self::TIPOS, true ) ? $tipo : 'fijo';

		$amb = '';

		foreach ( self::ambitos() as $k => $rot ) {
			$amb .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $rot ) );
		}

		/*
		 * Qué tipo es. Uno nuevo viene marcado con el de la pantalla donde
		 * se está —en Suscripciones, suscripción— y acá se puede cambiar:
		 * es el único lugar donde una fila se mueve de una lista a la otra.
		 */
		$tip = '';

		foreach ( self::tipos() as $k => $rot ) {
			$tip .= sprintf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( $k ),
				esc_html( $rot ),
				selected( $k, $tipo, false )
			);
		}

		$cic = '';

		foreach ( self::ciclos() as $k => $rot ) {
			$cic .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $rot ) );
		}

		$mon = '';

		foreach ( Malka_CRM_Settings::currencies() as $k => $rot ) {
			$mon .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $rot ) );
		}

		$meses = '<option value="0">' . esc_html__( 'Sin mes', 'malka-crm' ) . '</option>';

		for ( $m = 1; $m <= 12; $m++ ) {
			$meses .= sprintf(
				'<option value="%1$d">%2$s</option>',
				$m,
				esc_html( date_i18n( 'F', strtotime( sprintf( '2026-%02d-01', $m ) ) ) )
			);
		}

		$cuentas = '<option value="0">' . esc_html__( 'Sin indicar', 'malka-crm' ) . '</option>';

		if ( class_exists( 'Malka_CRM_Finance' ) ) {
			// Las cuentas vienen como arreglo con el id en la clave.
			foreach ( array_keys( (array) Malka_CRM_Finance::cuentas() ) as $cid ) {
				$cuentas .= sprintf(
					'<option value="%1$d">%2$s</option>',
					(int) $cid,
					esc_html( Malka_CRM_Finance::nombre_cuenta( (int) $cid ) )
				);
			}
		}

		ob_start();
		echo '<div class="esc-modal" id="esc-modal-sus"><div class="esc-modal__c esc-nuevo-t">';
		printf( '<h3 class="esc-modal__h" data-sus-titulo>%s</h3>', esc_html__( 'Anotar un cobro fijo', 'malka-crm' ) );
		printf( '<p class="esc-modal__p">%s</p>', esc_html__( 'Una suscripción, el arriendo, el internet, la contadora', 'malka-crm' ) );

		printf(
			'<form class="esc-nueva" method="post" action="%1$s">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_sus_guardar" />' .
			'<input type="hidden" name="id" value="0" data-sus-id />' .
			'<label class="esc-nueva__q"><span>%3$s</span><input type="text" name="nombre" data-sus-nombre placeholder="%4$s" required /></label>' .
			'<label class="esc-nueva__c"><span>%5$s</span><select name="ambito" data-sus-ambito>%6$s</select></label>' .
			'<label class="esc-nueva__c"><span>%18$s</span><select name="tipo" data-sus-tipo>%19$s</select></label>' .
			'<label class="esc-nueva__c"><span>%7$s</span><select name="ciclo" data-sus-ciclo>%8$s</select></label>' .
			'<label class="esc-nueva__c"><span>%9$s</span><input type="text" name="monto" data-sus-monto inputmode="decimal" /></label>' .
			'<label class="esc-nueva__c"><span>%10$s</span><select name="moneda" data-sus-moneda>%11$s</select></label>' .
			'<label class="esc-nueva__c"><span>%12$s</span><input type="number" name="dia" data-sus-dia min="1" max="31" /></label>' .
			'<label class="esc-nueva__c" data-sus-mes-campo hidden><span>%13$s</span><select name="mes" data-sus-mes>%14$s</select></label>' .
			'<label class="esc-nueva__c"><span>%15$s</span><select name="cuenta" data-sus-cuenta>%16$s</select></label>' .
			'<label class="esc-nueva__q esc-nueva__ck"><input type="checkbox" name="variable" value="1" data-sus-variable /> <span>%17$s</span></label>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_sus_guardar', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Qué es', 'malka-crm' ),
			esc_attr__( 'Adobe, Figma, hosting, internet…', 'malka-crm' ),
			esc_html__( 'De quién es', 'malka-crm' ),
			$amb, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Cada cuánto', 'malka-crm' ),
			$cic, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Cuánto', 'malka-crm' ),
			esc_html__( 'Moneda', 'malka-crm' ),
			$mon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Día del cobro', 'malka-crm' ),
			esc_html__( 'Mes del cobro', 'malka-crm' ),
			$meses, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'De dónde sale', 'malka-crm' ),
			$cuentas, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'El monto cambia mes a mes (la luz, algo que se paga por uso)', 'malka-crm' ),
			esc_html__( 'Qué tipo', 'malka-crm' ),
			$tip // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		printf(
			'<p class="esc-hint esc-nueva__q">%s</p>',
			esc_html__( 'La etiqueta importa: sólo lo del estudio entra al costo con el que se calcula tu tarifa por hora.', 'malka-crm' )
		);

		echo '<div class="esc-modal__f esc-nueva__pie">';
		/*
		 * Borrar va por el mismo action que guardar, con su propia bandera.
		 * Un botón con name="action" mandaría dos veces el mismo campo —el
		 * del formulario y el del botón— y cuál gana depende del orden.
		 */
		printf(
			'<button type="submit" class="esc-sus__del" name="borrar" value="1" data-sus-borrar hidden>%s</button>',
			esc_html__( 'Borrar', 'malka-crm' )
		);
		printf( '<button type="button" class="esc-btn" data-cerrar-modal>%s</button>', esc_html__( 'Cancelar', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Guardar', 'malka-crm' ) );
		echo '</div>';
		echo '</form>';
		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * La ventana de «ya lo pagué»: cuánto, cuándo y de qué cuenta salió.
	 *
	 * El monto no se da por sabido. Un cargo en dólares llega a la cartola
	 * por el cambio del día más lo que le sume el banco, y eso cambia todos
	 * los meses: el estimado viene escrito para corregirlo, no para
	 * aceptarlo.
	 *
	 * @return string
	 */
	public static function modal_pago() {
		$cuentas = '<option value="0">' . esc_html__( 'Sin indicar', 'malka-crm' ) . '</option>';

		if ( class_exists( 'Malka_CRM_Finance' ) ) {
			foreach ( array_keys( (array) Malka_CRM_Finance::cuentas() ) as $cid ) {
				$cuentas .= sprintf(
					'<option value="%1$d">%2$s</option>',
					(int) $cid,
					esc_html( Malka_CRM_Finance::nombre_cuenta( (int) $cid ) )
				);
			}
		}

		ob_start();
		echo '<div class="esc-modal" id="esc-modal-suspago"><div class="esc-modal__c esc-nuevo-t">';
		printf( '<h3 class="esc-modal__h" data-sp-titulo>%s</h3>', esc_html__( 'Ya lo pagué', 'malka-crm' ) );
		printf( '<p class="esc-modal__p" data-sp-sub>%s</p>', esc_html__( 'Queda anotado como salida y el saldo se ajusta solo', 'malka-crm' ) );

		printf(
			'<form class="esc-nueva" data-sp-form>' .
			'<input type="hidden" data-sp-clave value="" /><input type="hidden" data-sp-mes value="" />' .
			'<label class="esc-nueva__c"><span>%1$s</span><input type="text" inputmode="numeric" data-sp-monto /></label>' .
			'<label class="esc-nueva__c"><span>%2$s</span><input type="date" data-sp-fecha /></label>' .
			'<label class="esc-nueva__q"><span>%3$s</span><select data-sp-cuenta>%4$s</select></label>' .
			'<p class="esc-hint esc-nueva__q" data-sp-ayuda></p>',
			esc_html__( 'Cuánto te salió', 'malka-crm' ),
			esc_html__( 'Qué día', 'malka-crm' ),
			esc_html__( 'De qué cuenta salió', 'malka-crm' ),
			$cuentas // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		echo '<div class="esc-modal__f esc-nueva__pie">';
		printf( '<button type="button" class="esc-btn" data-cerrar-modal>%s</button>', esc_html__( 'Cancelar', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Anotar el pago', 'malka-crm' ) );
		echo '</div>';
		echo '</form>';
		echo '</div></div>';

		return ob_get_clean();
	}

	/* ─────────────────────────────  Manijas  ─────────────────────────────── */

	/**
	 * Guardar, desde el formulario.
	 */
	public static function handle_guardar() {
		check_admin_referer( 'malka_crm_sus_guardar' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'malka-crm' ) );
		}

		// El mismo formulario borra, con el botón del pie.
		if ( ! empty( $_POST['borrar'] ) ) {
			$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

			self::volver( self::borrar( $id ) ? 'borrado' : 'falta' );
			return;
		}

		$id = self::guardar(
			array(
				'id'       => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
				'nombre'   => isset( $_POST['nombre'] ) ? wp_unslash( $_POST['nombre'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'monto'    => isset( $_POST['monto'] ) ? wp_unslash( $_POST['monto'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'moneda'   => isset( $_POST['moneda'] ) ? wp_unslash( $_POST['moneda'] ) : 'CLP', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'ciclo'    => isset( $_POST['ciclo'] ) ? sanitize_key( wp_unslash( $_POST['ciclo'] ) ) : 'mensual',
				'ambito'   => isset( $_POST['ambito'] ) ? sanitize_key( wp_unslash( $_POST['ambito'] ) ) : 'estudio',
				'tipo'     => isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : 'fijo',
				'dia'      => isset( $_POST['dia'] ) ? (int) $_POST['dia'] : 0,
				'mes'      => isset( $_POST['mes'] ) ? (int) $_POST['mes'] : 0,
				'cuenta'   => isset( $_POST['cuenta'] ) ? (int) $_POST['cuenta'] : 0,
				'variable' => ! empty( $_POST['variable'] ),
			)
		);

		self::volver( $id ? 'ok' : 'falta' );
	}

	/**
	 * Borrar, desde el mismo formulario.
	 */
	public static function handle_borrar() {
		check_admin_referer( 'malka_crm_sus_guardar' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'malka-crm' ) );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		self::volver( self::borrar( $id ) ? 'borrado' : 'falta' );
	}

	/**
	 * Vuelve a la pantalla con el acuse puesto.
	 *
	 * @param string $que Acuse.
	 */
	private static function volver( $que ) {
		$url = Malka_CRM_Dashboard::view_url( 'deudas' );

		wp_safe_redirect( add_query_arg( 'sus', $que, $url ) . '#esc-suscripciones' );
		exit;
	}

	/**
	 * Los datos de un cobro, para llenar la ventana.
	 */
	public static function ajax_detalle() {
		check_ajax_referer( 'malka_crm_finance', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}

		$s = self::una( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );

		if ( ! $s ) {
			wp_send_json_error();
		}

		wp_send_json_success(
			array(
				'id'       => (int) $s['id'],
				'nombre'   => $s['nombre'],
				'monto'    => $s['monto'],
				'moneda'   => $s['moneda'],
				'ciclo'    => $s['ciclo'],
				'ambito'   => $s['ambito'],
				'tipo'     => $s['tipo'],
				'dia'      => (int) $s['dia'],
				'mes'      => (int) $s['mes'],
				'cuenta'   => (int) $s['cuenta'],
				'variable' => ! empty( $s['variable'] ),
			)
		);
	}

	/**
	 * Lo que hace falta para abrir la ventana de «ya lo pagué».
	 */
	public static function ajax_pagar() {
		check_ajax_referer( 'malka_crm_finance', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}

		$s = self::una( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );

		if ( ! $s ) {
			wp_send_json_error();
		}

		/*
		 * Por defecto el mes que corre, que es lo que se marca el 99% de
		 * las veces. Desde el calendario del año llega el mes de la fila,
		 * para poder ponerse al día con lo que quedó sin marcar.
		 */
		$mes = current_time( 'Y-m' );

		if ( isset( $_POST['mes'] ) ) {
			$pedido = sanitize_text_field( wp_unslash( $_POST['mes'] ) );

			if ( preg_match( '/^\d{4}-\d{2}$/', $pedido ) ) {
				// Hacia adelante no: un cobro que todavía no ocurrió no se
				// puede haber pagado, y marcarlo descuadraría el saldo.
				$mes = min( $pedido, current_time( 'Y-m' ) );
			}
		}

		// Ya marcado: no se vuelve a cobrar dos veces el mismo mes.
		if ( self::pagado( $s, $mes ) ) {
			wp_send_json_error( array( 'message' => __( 'Ese mes ya está marcado.', 'malka-crm' ) ) );
		}

		$hist  = self::historial( $s, 4 );
		$ayuda = array();

		if ( 'CLP' !== $s['moneda'] ) {
			$ayuda[] = sprintf(
				/* translators: 1: moneda, 2: monto */
				__( 'El cargo es de %1$s %2$s. Lo que llega a la cartola suele ser algo más: el cambio del día más lo que agregue el banco.', 'malka-crm' ),
				$s['moneda'],
				number_format_i18n( (float) $s['monto'], 2 )
			);
		}

		foreach ( $hist as $h ) {
			$ayuda[] = sprintf(
				/* translators: 1: mes, 2: monto */
				__( 'En %1$s pagaste %2$s.', 'malka-crm' ),
				date_i18n( 'F', strtotime( $h['mes'] . '-01' ) ),
				Malka_CRM_Settings::money( $h['monto'] )
			);
			break;
		}

		/*
		 * Qué se propone: lo último que pagó si lo hay —es lo más parecido
		 * a lo que va a llegar— y si no, la estimación por el cambio.
		 */
		$sugerido = $hist && $hist[0]['monto'] > 0 ? (int) $hist[0]['monto'] : (int) $s['clp'];

		wp_send_json_success(
			array(
				'clave'    => self::clave( $s ),
				'mes'      => $mes,
				'nombre'   => $s['nombre'],
				'sugerido' => $sugerido,
				/*
				 * El día del cobro de ese mes, nunca más allá de hoy: marcar
				 * en noviembre un cobro de marzo tiene que proponer marzo.
				 */
				'fecha'    => min( current_time( 'Y-m-d' ), self::dia_de( $s, $mes ) ),
				'cuenta'   => (int) $s['cuenta'],
				'hoy'      => current_time( 'Y-m-d' ),
				'ayuda'    => implode( ' ', $ayuda ),
			)
		);
	}

	/**
	 * La ventana del año: los doce cobros, cuáles se pagaron y cuáles no.
	 *
	 * Se llena por ajax, como las otras: una sola ventana en la página y no
	 * doce calendarios dibujados que nadie va a abrir.
	 *
	 * @return string
	 */
	public static function modal_ano() {
		ob_start();
		echo '<div class="esc-modal" id="esc-modal-susano"><div class="esc-modal__c esc-susano">';
		printf( '<h3 class="esc-modal__h" data-sa-titulo>%s</h3>', esc_html__( 'El año', 'malka-crm' ) );
		printf( '<p class="esc-modal__p" data-sa-sub>%s</p>', esc_html__( 'Lo que ya pagaste y lo que falta', 'malka-crm' ) );

		// El resumen de arriba: cuántas van, cuánto llevas y cuál sigue.
		printf(
			'<div class="esc-susano__r"><span data-sa-ciclo></span><span data-sa-cuenta></span></div>' .
			'<p class="esc-susano__px"><span>%1$s</span><b data-sa-prox></b></p>',
			esc_html__( 'Próxima', 'malka-crm' )
		);

		echo '<div class="esc-susano__l" data-sa-lista></div>';
		printf( '<p class="esc-empty" data-sa-vacio hidden>%s</p>', esc_html__( 'Este cobro no tiene día, así que no hay calendario que mostrar. Ponle el día en «Editar» y vuelve.', 'malka-crm' ) );

		echo '<div class="esc-modal__f">';
		printf( '<button type="button" class="esc-btn" data-cerrar-modal>%s</button>', esc_html__( 'Cerrar', 'malka-crm' ) );
		echo '</div>';
		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * El año de un cobro, para la ventana del calendario.
	 */
	public static function ajax_calendario() {
		check_ajax_referer( 'malka_crm_finance', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}

		$s = self::una( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );

		if ( ! $s ) {
			wp_send_json_error();
		}

		$anio = isset( $_POST['anio'] ) ? (int) $_POST['anio'] : (int) current_time( 'Y' );
		$anio = ( $anio >= 2000 && $anio <= 2100 ) ? $anio : (int) current_time( 'Y' );
		$cal  = self::calendario( $s, $anio );

		$filas = array();

		foreach ( $cal['filas'] as $f ) {
			$filas[] = array(
				'mes'    => $f['mes'],
				'fecha'  => $f['fecha'],
				'rotulo' => date_i18n( 'j M', strtotime( $f['fecha'] ) ),
				'monto'  => Malka_CRM_Settings::money( $f['monto'] ),
				'estado' => $f['estado'],
				'toca'   => ! empty( $f['toca'] ),
				'cuando' => $f['cuando'] ? date_i18n( 'j M', strtotime( $f['cuando'] ) ) : '',
			);
		}

		wp_send_json_success(
			array(
				'id'      => (int) $s['id'],
				'nombre'  => $s['nombre'],
				'clave'   => self::clave( $s ),
				'anio'    => $cal['anio'],
				'ciclo'   => isset( self::ciclos()[ $s['ciclo'] ] ) ? self::ciclos()[ $s['ciclo'] ] : '',
				'sin_dia' => ! empty( $cal['sin_dia'] ),
				'pagadas' => (int) $cal['pagadas'],
				'pagado'  => Malka_CRM_Settings::money( $cal['pagado'] ),
				'proxima' => $cal['proxima']
					? date_i18n( 'j M', strtotime( $cal['proxima']['fecha'] ) ) . ' · ' . Malka_CRM_Settings::money( $cal['proxima']['monto'] )
					: '',
				'hoy'     => (int) current_time( 'Y' ),
				'filas'   => $filas,
			)
		);
	}

	/**
	 * Deshace un pago marcado por error.
	 */
	public static function ajax_deshacer() {
		check_ajax_referer( 'malka_crm_finance', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}

		if ( ! class_exists( 'Malka_CRM_Pagos' ) ) {
			wp_send_json_error();
		}

		$mes   = isset( $_POST['mes'] ) ? sanitize_text_field( wp_unslash( $_POST['mes'] ) ) : '';
		$clave = isset( $_POST['clave'] ) ? sanitize_key( wp_unslash( $_POST['clave'] ) ) : '';

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $mes ) || '' === $clave ) {
			wp_send_json_error();
		}

		wp_send_json_success( array( 'hecho' => Malka_CRM_Pagos::desmarcar( $mes, $clave ) ) );
	}
}
