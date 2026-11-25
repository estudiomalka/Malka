<?php
/**
 * Configuración del estudio: costos, sueldo objetivo, horario y tarifa por hora.
 *
 * Todo lo numérico del escritorio se deriva de acá. Cada dato se escribe en un
 * solo lugar: los costos fijos viven en esta pantalla como presupuesto inicial y
 * se contrastan con los movimientos reales una vez que hay historial.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Settings {

	const OPTION   = 'malka_crm_settings';
	const OPT_FX   = 'malka_crm_fx';
	/**
	 * Quién puede ver y guardar la configuración.
	 *
	 * Pasa por el mismo filtro que el resto del plugin para que se pueda abrir a
	 * otro rol sin tocar el código.
	 *
	 * @return string
	 */
	public static function cap() {
		return Malka_CRM_Helpers::admin_capability();
	}

	/**
	 * Valores por defecto. Deliberadamente vacíos en lo que la usuaria debe
	 * ingresar: un cero inventado es peor que un campo en blanco, porque el
	 * escritorio no puede distinguir "no lo sé" de "es cero".
	 */
	public static function defaults() {
		return array(
			// Empresa
			'razon_social'    => '',
			'rut'             => '',
			'giro'            => '',
			'direccion'       => '',
			'comuna'          => '',
			'email'           => get_option( 'admin_email' ),
			'telefono'        => '',

			// Modelo económico
			'sueldo_objetivo' => 0,
			'carga_prev'      => 25.0,
			'colchon'         => 10.0,
			'vacaciones_dias' => 15,
			'ocupacion'       => 55.0,
			'tarifa_cobrada'  => 0,

			// Costos fijos: array( 'nombre', 'monto', 'moneda', 'dia' )
			'costos'          => array(),

			// Tipo de cambio escrito a mano; 0 usa el del Banco Central.
			'fx_dolar'        => 0,
			'fx_euro'         => 0,

			// Lo que ve el cliente en la cotización.
			'nombre_fantasia' => '',
			'logo_url'        => '',
			/*
			 * De dónde se baja la próxima versión del panel, como
			 * «usuario/repositorio». Vacío: no se busca ninguna.
			 */
			'repo'            => 'estudiomalka/Malka',
			// Sólo para un repositorio privado. No se muestra nunca.
			'repo_token'      => '',
			'icono_app'       => '',
			'banco_nombre'    => '',
			'banco_tipo'      => '',
			'banco_cuenta'    => '',
			'banco_titular'   => '',
			'banco_rut'       => '',
			'terminos_url'    => '',
			// La cotización se sirve como página propia, sin el tema: la
			// tipografía del sitio no llega sola hasta ahí.
			'tipografia'      => 'Neris',
			'tipografia_css'  => '',
			// Tamaños y pesos de los estilos del documento. Vacío es «como
			// vienen»: cada estilo cae en su valor de fábrica.
			'doc_estilos'     => array(),
			// Los textos de los mensajes al cliente que ella haya cambiado.
			'mensajes'        => array(),
			// Las frases que el cliente lee dentro de la cotización.
			'textos'          => array(),
			'anticipo_pct'    => 60,
			'link_sumup'      => '',
			// API de SumUp: con esto el cobro sale con el monto ya puesto.
			'sumup_api_key'   => '',
			'sumup_merchant'  => '',
			'sumup_moneda'    => 'CLP',

			// Cuánto dura una cotización y qué dice siempre al pie.
			'validez_dias'    => 10,

			// Avisos al cliente: encendidos por defecto, apagables en cada
			// cotización. La pausa es una fecha para que no se olvide puesta.
			'avisos_default'  => 1,
			'avisos_pausa'    => '',

			// Cuántos días hacia adelante se reservan costos al calcular
			// cuánto puede retirar.
			'retiro_horizonte' => 30,
			'notas_tipo'      => "Valores netos.\nPropuesta válida por 10 días corridos.\nLos traslados no están incluidos.",

			// Plazo del saldo: días hábiles desde que el proyecto se entrega.
			// Desde que se cumple, la cuota pasa a vencida y empieza a correr
			// el interés por atraso.
			'saldo_habiles'   => 3,
			'feriados'        => self::feriados_semilla(),

			// Jornada
			'dias_semana'     => array( 1, 2, 3, 4, 5 ),
			'bloque_am'       => array( '09:00', '13:00' ),
			'bloque_pm'       => array( '15:00', '18:00' ),
			'pomodoro_min'    => 25,

			// Horas donadas
			'tope_donadas'    => 5,

			// Tributario
			'iva'             => 19.0,
			'retencion'       => 15.25,
			// Tasa de PPM (pago provisional mensual) sobre las ventas netas.
			'ppm'             => 0,
			'regimen'         => 'factura',
			'comision_tarjeta'=> 3.81,

			// Mora
			'mora_base'       => 'neto',
			'mora_tasa'       => 0,
			'mora_comision'   => 1.0,
			'mora_aviso_prev' => 3,
			'mora_recordar'   => 7,

			// Contadora
			'cont_nombre'     => '',
			'nombre_banco'    => '',
			'ia_api_key'      => '',
			'ia_modelo'       => '',
			'cont_email'      => '',
			'cont_telefono'   => '',
			'cont_copia'      => '',
			'cont_envia_mora' => 1,
			'cont_envia_dte'  => 0,
			'cont_envia_nc'   => 0,
			'cont_envia_libro'=> 0,
		);
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_malka_crm_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_init', array( $this, 'redirect_to_dashboard' ) );
	}

	/**
	 * Estas tres pantallas viven en el escritorio, no en WP-Admin.
	 *
	 * Quedan registradas en el menú de WordPress porque el acceso por ahí es
	 * costumbre, pero mandan al panel para no tener la misma cosa en dos partes.
	 */
	public function redirect_to_dashboard() {
		if ( ! isset( $_GET['page'] ) || ! class_exists( 'Malka_CRM_Dashboard' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$destinos = array(
			'malka-crm-config'      => 'configuracion',
			'malka-crm-movimientos' => 'movimientos',
			'malka-crm-horas'       => 'horas',
		);

		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $destinos[ $page ] ) ) {
			return;
		}

		wp_safe_redirect( Malka_CRM_Dashboard::view_url( $destinos[ $page ] ) );
		exit;
	}

	/* ─────────────────────────────  Lectura  ───────────────────────────── */

	/**
	 * Devuelve todos los ajustes con los defaults aplicados.
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Un ajuste puntual.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $default Valor si no existe.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( ! array_key_exists( $key, $all ) ) {
			return $default;
		}
		return $all[ $key ];
	}

	/**
	 * Guarda un subconjunto de ajustes sin tocar el resto.
	 *
	 * @param array $values Pares clave => valor ya sanitizados.
	 */
	public static function update( array $values ) {
		$all = self::all();
		foreach ( $values as $k => $v ) {
			$all[ $k ] = $v;
		}
		update_option( self::OPTION, $all, false );
		return $all;
	}

	/* ─────────────────────────────  Costos  ───────────────────────────── */

	/**
	 * Lista de costos fijos presupuestados.
	 *
	 * @return array[] array( array( 'nombre' => string, 'monto' => int ), ... )
	 */
	/**
	 * Monedas aceptadas en los costos fijos.
	 *
	 * @return array código => etiqueta
	 */
	public static function currencies() {
		return array(
			'CLP' => __( 'CLP', 'malka-crm' ),
			'USD' => __( 'USD', 'malka-crm' ),
			'EUR' => __( 'EUR', 'malka-crm' ),
		);
	}

	/**
	 * Tipo de cambio del Banco Central, con caché de medio día.
	 *
	 * Si no hay red se usa el último valor conocido, y si tampoco lo hay, el
	 * que ella haya escrito a mano en Configuración. Nunca inventa un número:
	 * cuando no tiene ninguno devuelve 0 y quien lo use decide qué decir.
	 *
	 * @param bool $forzar Saltarse la caché.
	 * @return array array( 'USD' => float, 'EUR' => float, 'fecha' => string, 'fuente' => string )
	 */
	public static function fx( $forzar = false ) {
		$manual_usd = (float) self::get( 'fx_dolar', 0 );
		$manual_eur = (float) self::get( 'fx_euro', 0 );

		// Un valor escrito a mano manda sobre el automático.
		if ( $manual_usd > 0 && $manual_eur > 0 ) {
			return array(
				'USD'    => $manual_usd,
				'EUR'    => $manual_eur,
				'CLP'    => 1.0,
				'fecha'  => '',
				'fuente' => __( 'escrito a mano', 'malka-crm' ),
			);
		}

		$cache = get_option( self::OPT_FX, array() );
		$vigente = ( is_array( $cache ) && ! empty( $cache['ts'] ) && ( time() - (int) $cache['ts'] ) < ( 12 * HOUR_IN_SECONDS ) );

		if ( ! $forzar && $vigente ) {
			return self::fx_merge( $cache, $manual_usd, $manual_eur, __( 'Banco Central', 'malka-crm' ) );
		}

		$r = wp_remote_get(
			'https://mindicador.cl/api',
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			$j = json_decode( wp_remote_retrieve_body( $r ), true );

			$usd = isset( $j['dolar']['valor'] ) ? (float) $j['dolar']['valor'] : 0;
			$eur = isset( $j['euro']['valor'] ) ? (float) $j['euro']['valor'] : 0;

			if ( $usd > 0 && $eur > 0 ) {
				$nuevo = array(
					'USD'   => $usd,
					'EUR'   => $eur,
					'fecha' => isset( $j['dolar']['fecha'] ) ? gmdate( 'Y-m-d', strtotime( $j['dolar']['fecha'] ) ) : gmdate( 'Y-m-d' ),
					'ts'    => time(),
				);
				update_option( self::OPT_FX, $nuevo, false );
				return self::fx_merge( $nuevo, $manual_usd, $manual_eur, __( 'Banco Central', 'malka-crm' ) );
			}
		}

		// Sin red: el último valor que se alcanzó a guardar.
		if ( is_array( $cache ) && ! empty( $cache['USD'] ) ) {
			return self::fx_merge( $cache, $manual_usd, $manual_eur, __( 'último valor conocido', 'malka-crm' ) );
		}

		return array(
			'USD'    => $manual_usd,
			'EUR'    => $manual_eur,
			'CLP'    => 1.0,
			'fecha'  => '',
			'fuente' => ( $manual_usd > 0 || $manual_eur > 0 ) ? __( 'escrito a mano', 'malka-crm' ) : __( 'sin dato', 'malka-crm' ),
		);
	}

	/**
	 * Deja ganar al valor manual cuando existe solo para una moneda.
	 *
	 * @param array  $base   Valores base.
	 * @param float  $usd    Manual dólar.
	 * @param float  $eur    Manual euro.
	 * @param string $fuente Etiqueta de origen.
	 * @return array
	 */
	private static function fx_merge( $base, $usd, $eur, $fuente ) {
		return array(
			'USD'    => $usd > 0 ? $usd : (float) ( isset( $base['USD'] ) ? $base['USD'] : 0 ),
			'EUR'    => $eur > 0 ? $eur : (float) ( isset( $base['EUR'] ) ? $base['EUR'] : 0 ),
			'CLP'    => 1.0,
			'fecha'  => isset( $base['fecha'] ) ? (string) $base['fecha'] : '',
			'fuente' => ( $usd > 0 || $eur > 0 ) ? __( 'Banco Central y valores a mano', 'malka-crm' ) : $fuente,
		);
	}

	/**
	 * Pasa un monto a pesos según su moneda.
	 *
	 * @param int|float $monto  Monto en su moneda.
	 * @param string    $moneda CLP, USD o EUR.
	 * @return int Pesos, o 0 si no hay tipo de cambio para esa moneda.
	 */
	public static function to_clp( $monto, $moneda = 'CLP' ) {
		$moneda = strtoupper( (string) $moneda );

		if ( 'CLP' === $moneda || '' === $moneda ) {
			return (int) round( $monto );
		}

		$fx = self::fx();
		$v  = isset( $fx[ $moneda ] ) ? (float) $fx[ $moneda ] : 0;

		return $v > 0 ? (int) round( $monto * $v ) : 0;
	}

	public static function costs() {
		/*
		 * La lista vive en Suscripciones, que además sabe lo que de verdad
		 * se pagó cada mes. Acá queda el mismo cálculo como respaldo, para
		 * cuando esta clase se usa sola —en una prueba, o si el archivo no
		 * está—; si los dos se separan, manda el de allá.
		 */
		if ( class_exists( 'Malka_CRM_Suscripciones' ) ) {
			return Malka_CRM_Suscripciones::lista();
		}

		$costos = self::get( 'costos', array() );
		if ( ! is_array( $costos ) ) {
			return array();
		}
		$monedas = self::currencies();
		$out     = array();

		foreach ( $costos as $c ) {
			if ( ! is_array( $c ) || empty( $c['nombre'] ) ) {
				continue;
			}

			// Las filas guardadas antes de las monedas son pesos.
			$moneda = isset( $c['moneda'] ) ? strtoupper( (string) $c['moneda'] ) : 'CLP';
			if ( ! isset( $monedas[ $moneda ] ) ) {
				$moneda = 'CLP';
			}

			$monto = isset( $c['monto'] ) ? (float) $c['monto'] : 0;
			$dia   = isset( $c['dia'] ) ? (int) $c['dia'] : 0;

			// Las filas guardadas antes del ciclo son mensuales, y antes del
			// ámbito, del estudio: era lo único que había.
			$ciclo  = isset( $c['ciclo'] ) && in_array( $c['ciclo'], array( 'mensual', 'anual' ), true ) ? $c['ciclo'] : 'mensual';
			$ambito = isset( $c['ambito'] ) && in_array( $c['ambito'], array( 'estudio', 'personal' ), true ) ? $c['ambito'] : 'estudio';
			// Y antes del tipo, costos fijos: así se llamaba la lista entera.
			$tipo   = isset( $c['tipo'] ) && in_array( $c['tipo'], array( 'fijo', 'suscripcion' ), true ) ? $c['tipo'] : 'fijo';
			$mes    = isset( $c['mes'] ) ? (int) $c['mes'] : 0;
			$clp    = self::to_clp( $monto, $moneda );

			$out[] = array(
				'id'     => isset( $c['id'] ) ? (int) $c['id'] : 0,
				'nombre' => (string) $c['nombre'],
				'monto'  => 'CLP' === $moneda ? (int) $monto : $monto,
				'moneda' => $moneda,
				'dia'    => ( $dia >= 1 && $dia <= 31 ) ? $dia : 0,
				'variable' => ! empty( $c['variable'] ),
				'clp'    => $clp,
				// Al mes o al año; el mes, sólo si es anual.
				'ciclo'  => $ciclo,
				'mes'    => ( 'anual' === $ciclo && $mes >= 1 && $mes <= 12 ) ? $mes : 0,
				// Lo que cuesta al mes: un cobro anual repartido en doce.
				'clp_mes' => 'anual' === $ciclo ? (int) round( $clp / 12 ) : $clp,
				// Del estudio o tuyo.
				'ambito' => $ambito,
				// Costo fijo o suscripción: la misma lista, dos pantallas.
				'tipo'   => $tipo,
				// De qué cuenta o tarjeta sale. 0 = la principal del estudio.
				'cuenta' => isset( $c['cuenta'] ) ? (int) $c['cuenta'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Sólo los costos del estudio.
	 *
	 * Lo personal se anota en la misma lista —es el mismo gesto: algo que se
	 * paga sin que nadie lo pida— pero no es un costo del estudio: no entra
	 * a la tarifa, ni a la plata que el estudio reserva, ni a los pagos que
	 * se avisan por correo.
	 *
	 * @return array[]
	 */
	public static function costs_estudio() {
		$out = array();

		foreach ( self::costs() as $c ) {
			if ( 'personal' === $c['ambito'] ) {
				continue;
			}
			$out[] = $c;
		}

		return $out;
	}

	/**
	 * Los costos ordenados por día de pago, con los que no tienen día al final.
	 *
	 * @return array[]
	 */
	public static function costs_by_day() {
		$c = self::costs();
		usort(
			$c,
			function ( $a, $b ) {
				$da = $a['dia'] > 0 ? $a['dia'] : 99;
				$db = $b['dia'] > 0 ? $b['dia'] : 99;
				return $da <=> $db;
			}
		);
		return $c;
	}

	/**
	 * Los costos que se pagan dentro de los próximos días.
	 *
	 * @param int $dias Ventana.
	 * @return array[]
	 */
	public static function costs_due( $dias = 7 ) {
		$hoy   = (int) current_time( 'j' );
		$total = (int) current_time( 't' );
		$ahora = current_time( 'Y-m-d' );
		$out   = array();

		// Lo que el estudio tiene que tener guardado. Lo personal no se le
		// reserva al estudio.
		foreach ( self::costs_estudio() as $c ) {
			if ( $c['dia'] < 1 ) {
				continue;
			}

			if ( 'anual' === $c['ciclo'] ) {
				/*
				 * Un cobro anual no se puede medir dentro del mes: hay que
				 * saber cuántos días faltan de verdad hasta su fecha, que
				 * pueden ser diez o trescientos. Sin mes no hay fecha.
				 */
				if ( ! class_exists( 'Malka_CRM_Suscripciones' ) || $c['mes'] < 1 ) {
					continue;
				}

				$f = Malka_CRM_Suscripciones::proximo( 'anual', (int) $c['mes'], (int) $c['dia'] );

				if ( ! $f ) {
					continue;
				}

				$faltan = (int) floor( ( strtotime( $f ) - strtotime( $ahora ) ) / DAY_IN_SECONDS );
			} else {
				$faltan = $c['dia'] - $hoy;
				if ( $faltan < 0 ) {
					$faltan += $total; // Cae el mes que viene.
				}
			}

			if ( $faltan <= $dias ) {
				$c['faltan'] = $faltan;
				$out[]       = $c;
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $a['faltan'] <=> $b['faltan'];
			}
		);

		return $out;
	}

	/**
	 * Total mensual presupuestado de costos fijos.
	 */
	/**
	 * Total mensual en pesos, con las monedas ya convertidas.
	 */
	public static function costs_total() {
		$t = 0;

		/*
		 * Sólo lo del estudio, y lo anual repartido en doce: esta suma es el
		 * costo con el que se calcula la tarifa por hora. Meter acá lo
		 * personal la infla, y contar un pago anual como si fuera mensual la
		 * multiplica por doce.
		 */
		foreach ( self::costs_estudio() as $c ) {
			$t += $c['clp_mes'];
		}
		return $t;
	}

	/**
	 * Costo mensual que se usa para calcular la tarifa.
	 *
	 * Si hay al menos tres meses de movimientos categorizados como costo fijo,
	 * manda el promedio real por sobre el presupuesto: la estimación envejece,
	 * el movimiento con fecha no.
	 *
	 * @return array array( 'monto' => int, 'fuente' => 'real'|'presupuesto', 'presupuesto' => int, 'real' => int|null )
	 */
	public static function monthly_costs() {
		$presupuesto = self::costs_total();
		$real        = null;

		if ( class_exists( 'Malka_CRM_Finance' ) && method_exists( 'Malka_CRM_Finance', 'average_fixed_costs' ) ) {
			$real = Malka_CRM_Finance::average_fixed_costs( 3 );
		}

		if ( null !== $real && $real > 0 ) {
			return array(
				'monto'       => (int) $real,
				'fuente'      => 'real',
				'presupuesto' => $presupuesto,
				'real'        => (int) $real,
			);
		}

		return array(
			'monto'       => $presupuesto,
			'fuente'      => 'presupuesto',
			'presupuesto' => $presupuesto,
			'real'        => null,
		);
	}

	/* ─────────────────────────────  Jornada  ──────────────────────────── */

	/**
	 * Minutos de trabajo por día según los dos bloques configurados.
	 */
	public static function minutes_per_day() {
		$am = self::block_minutes( self::get( 'bloque_am' ) );
		$pm = self::block_minutes( self::get( 'bloque_pm' ) );
		return $am + $pm;
	}

	/**
	 * Minutos de un bloque horario.
	 *
	 * @param array $bloque array( 'HH:MM', 'HH:MM' ).
	 */
	public static function block_minutes( $bloque ) {
		if ( ! is_array( $bloque ) || count( $bloque ) < 2 ) {
			return 0;
		}
		$ini = self::to_minutes( $bloque[0] );
		$fin = self::to_minutes( $bloque[1] );
		return max( 0, $fin - $ini );
	}

	/**
	 * "09:30" → 570.
	 *
	 * @param string $hhmm Hora.
	 */
	public static function to_minutes( $hhmm ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( (string) $hhmm ), $m ) ) {
			return 0;
		}
		return ( (int) $m[1] ) * 60 + (int) $m[2];
	}

	/**
	 * 570 → "09:30".
	 *
	 * @param int $min Minutos desde medianoche.
	 */
	public static function to_hhmm( $min ) {
		$min = max( 0, (int) $min );
		return sprintf( '%02d:%02d', floor( $min / 60 ) % 24, $min % 60 );
	}

	/**
	 * Días hábiles de un mes según los días de semana configurados.
	 *
	 * @param int $year  Año.
	 * @param int $month Mes.
	 */
	public static function working_days( $year, $month ) {
		$dias  = self::get( 'dias_semana', array( 1, 2, 3, 4, 5 ) );
		$dias  = is_array( $dias ) ? array_map( 'intval', $dias ) : array( 1, 2, 3, 4, 5 );
		$total = (int) gmdate( 't', mktime( 0, 0, 0, (int) $month, 1, (int) $year ) );
		$n     = 0;

		for ( $d = 1; $d <= $total; $d++ ) {
			$dow = (int) gmdate( 'N', mktime( 0, 0, 0, (int) $month, $d, (int) $year ) );
			if ( in_array( $dow, $dias, true ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Horas disponibles en un año completo.
	 *
	 * @param int $year Año.
	 */
	public static function hours_per_year( $year ) {
		$dias = 0;
		for ( $m = 1; $m <= 12; $m++ ) {
			$dias += self::working_days( $year, $m );
		}
		return $dias * ( self::minutes_per_day() / 60 );
	}

	/* ─────────────────────────────  Tarifa  ───────────────────────────── */

	/**
	 * Calcula la tarifa mínima que cubre sueldo, cargas y costos.
	 *
	 * El método: lo que necesitas cubrir en un año, dividido por las horas que
	 * realmente puedes vender. Las horas donadas se descuentan de las vendibles,
	 * porque donar es un costo del negocio y lo paga la tarifa.
	 *
	 * @param int|null $year Año de referencia.
	 * @return array Desglose completo del cálculo.
	 */
	public static function rate( $year = null ) {
		$year = $year ? (int) $year : (int) current_time( 'Y' );

		$sueldo_mes = (float) self::get( 'sueldo_objetivo', 0 );
		$carga      = min( 90.0, max( 0.0, (float) self::get( 'carga_prev', 25 ) ) ) / 100;
		$colchon    = max( 0.0, (float) self::get( 'colchon', 10 ) ) / 100;
		$ocupacion  = min( 100.0, max( 1.0, (float) self::get( 'ocupacion', 55 ) ) ) / 100;
		$vac_dias   = max( 0, (int) self::get( 'vacaciones_dias', 15 ) );
		$donadas    = max( 0, (int) self::get( 'tope_donadas', 0 ) );

		$costos_info = self::monthly_costs();
		$costos_anio = $costos_info['monto'] * 12;

		$sueldo_anio = $sueldo_mes * 12;
		$bruto       = $carga < 1 ? $sueldo_anio / ( 1 - $carga ) : $sueldo_anio;
		$a_cubrir    = ( $bruto + $costos_anio ) * ( 1 + $colchon );

		$horas_dia   = self::minutes_per_day() / 60;
		$disponibles = self::hours_per_year( $year );
		$vacaciones  = $vac_dias * $horas_dia;
		$trabajables = max( 0, $disponibles - $vacaciones );
		$facturables = max( 1, ( $trabajables * $ocupacion ) - ( $donadas * 12 ) );

		$minima  = $a_cubrir / $facturables;
		$cobrada = (float) self::get( 'tarifa_cobrada', 0 );
		$cobrada = $cobrada > 0 ? $cobrada : $minima;

		return array(
			'completo'        => ( $sueldo_mes > 0 && $costos_info['monto'] > 0 ),
			'a_cubrir'        => (int) round( $a_cubrir ),
			'sueldo_bruto'    => (int) round( $bruto ),
			'costos_anio'     => (int) round( $costos_anio ),
			'costos_mes'      => (int) $costos_info['monto'],
			'costos_fuente'   => $costos_info['fuente'],
			'costos_presup'   => (int) $costos_info['presupuesto'],
			'costos_real'     => $costos_info['real'],
			'horas_disp'      => round( $disponibles ),
			'horas_trabaj'    => round( $trabajables ),
			'horas_fact_anio' => round( $facturables ),
			'horas_fact_mes'  => round( $facturables / 12 ),
			'tarifa_minima'   => (int) ( round( $minima / 1000 ) * 1000 ),
			'tarifa_exacta'   => (int) round( $minima ),
			'tarifa_cobrada'  => (int) round( $cobrada ),
			'horas_a_cobrada' => $cobrada > 0 ? round( $a_cubrir / $cobrada / 12 ) : 0,
			'margen_anual'    => (int) round( ( $facturables * $cobrada ) - $a_cubrir ),
		);
	}

	/**
	 * Tarifa que se usa para valorizar horas en el resto del plugin.
	 */
	public static function hourly_rate() {
		$cobrada = (int) self::get( 'tarifa_cobrada', 0 );
		if ( $cobrada > 0 ) {
			return $cobrada;
		}
		$r = self::rate();
		return $r['completo'] ? $r['tarifa_minima'] : 0;
	}

	/* ─────────────────────────────  Admin  ────────────────────────────── */

	public function menu() {
		add_submenu_page(
			'malka-crm',
			__( 'Configuración', 'malka-crm' ),
			__( 'Configuración', 'malka-crm' ),
			self::cap(),
			'malka-crm-config',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'malka-crm' ) );
		}

		$s    = self::all();
		$rate = self::rate();
		$done = isset( $_GET['malka_saved'] ) ? sanitize_text_field( wp_unslash( $_GET['malka_saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap malka-crm-config">';
		echo '<h1>' . esc_html__( 'Configuración de Malka CRM', 'malka-crm' ) . '</h1>';

		if ( '1' === $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Guardado. La tarifa se recalculó con los datos nuevos.', 'malka-crm' ) .
				'</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'malka_crm_save_settings', 'malka_crm_settings_nonce' );
		echo '<input type="hidden" name="action" value="malka_crm_save_settings" />';

		$this->section_empresa( $s );
		$this->section_modelo( $s, $rate );
		$this->section_costos( $s );
		$this->section_jornada( $s );
		$this->section_tributario( $s );
		$this->section_contadora( $s );

		submit_button( __( 'Guardar configuración', 'malka-crm' ) );
		echo '</form>';
		echo '</div>';
	}

	private function field( $label, $name, $value, $type = 'text', $hint = '' ) {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>' .
			'<input type="%3$s" id="%1$s" name="%1$s" value="%4$s" class="regular-text" />%5$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			$hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : ''
		);
	}

	private function section_empresa( $s ) {
		echo '<h2>' . esc_html__( 'Datos del estudio', 'malka-crm' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Aparecen en cada cotización y documento que emitas.', 'malka-crm' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->field( __( 'Razón social', 'malka-crm' ), 'razon_social', $s['razon_social'] );
		$this->field( __( 'RUT', 'malka-crm' ), 'rut', $s['rut'] );
		$this->field( __( 'Giro', 'malka-crm' ), 'giro', $s['giro'] );
		$this->field( __( 'Dirección', 'malka-crm' ), 'direccion', $s['direccion'] );
		$this->field( __( 'Comuna', 'malka-crm' ), 'comuna', $s['comuna'] );
		$this->field( __( 'Correo', 'malka-crm' ), 'email', $s['email'], 'email' );
		$this->field( __( 'Teléfono', 'malka-crm' ), 'telefono', $s['telefono'] );
		echo '</table>';
	}

	private function section_modelo( $s, $rate ) {
		echo '<h2>' . esc_html__( 'Tu tarifa por hora', 'malka-crm' ) . '</h2>';
		echo '<p class="description">' .
			esc_html__( 'Se calcula desde lo que necesitas vivir, no desde lo que cobra el mercado.', 'malka-crm' ) .
			'</p>';

		echo '<table class="form-table" role="presentation">';
		$this->field( __( 'Sueldo líquido al mes', 'malka-crm' ), 'sueldo_objetivo', $s['sueldo_objetivo'], 'number', __( 'Lo que quieres que te quede a ti, después de costos.', 'malka-crm' ) );
		$this->field( __( 'Carga previsional e impuestos (%)', 'malka-crm' ), 'carga_prev', $s['carga_prev'], 'number', __( 'Confírmalo con tu contadora: es el dato que más mueve la tarifa.', 'malka-crm' ) );
		$this->field( __( 'Colchón de imprevistos (%)', 'malka-crm' ), 'colchon', $s['colchon'], 'number', __( 'Cubre al cliente que paga tarde y el proyecto que se alarga.', 'malka-crm' ) );
		$this->field( __( 'Vacaciones al año (días hábiles)', 'malka-crm' ), 'vacaciones_dias', $s['vacaciones_dias'], 'number' );
		$this->field( __( 'Ocupación facturable (%)', 'malka-crm' ), 'ocupacion', $s['ocupacion'], 'number', __( 'Qué parte de tu tiempo llega a ser vendible. El resto es cotizar, administrar y correos.', 'malka-crm' ) );
		$this->field( __( 'Tope de horas donadas al mes', 'malka-crm' ), 'tope_donadas', $s['tope_donadas'], 'number', __( 'Donar es un costo del negocio: estas horas se descuentan de las vendibles y suben la tarifa.', 'malka-crm' ) );
		$this->field( __( 'Tarifa que cobras', 'malka-crm' ), 'tarifa_cobrada', $s['tarifa_cobrada'], 'number', __( 'Déjalo en cero para usar la tarifa mínima calculada.', 'malka-crm' ) );
		echo '</table>';

		if ( $rate['completo'] ) {
			echo '<table class="widefat striped" style="max-width:640px;margin:1em 0">';
			echo '<tbody>';
			$this->row( __( 'Tienes que facturar al año', 'malka-crm' ), self::money( $rate['a_cubrir'] ) );
			$this->row( __( 'Horas facturables al mes', 'malka-crm' ), $rate['horas_fact_mes'] . ' h' );
			$this->row( __( 'Tarifa mínima que te cubre', 'malka-crm' ), '<strong>' . self::money( $rate['tarifa_minima'] ) . '</strong>' );
			if ( $rate['tarifa_cobrada'] > 0 ) {
				$this->row( __( 'Margen anual a la tarifa que cobras', 'malka-crm' ), self::money( $rate['margen_anual'] ) );
			}
			echo '</tbody></table>';
		} else {
			echo '<div class="notice notice-info inline"><p>' .
				esc_html__( 'Falta tu sueldo objetivo o tus costos fijos. Sin esos dos no se puede calcular la tarifa.', 'malka-crm' ) .
				'</p></div>';
		}
	}

	private function row( $label, $value ) {
		echo '<tr><td>' . esc_html( $label ) . '</td><td style="text-align:right">' . wp_kses_post( $value ) . '</td></tr>';
	}

	private function section_costos( $s ) {
		$costos = self::costs();
		if ( empty( $costos ) ) {
			$costos = array( array( 'nombre' => '', 'monto' => 0 ) );
		}
		$info = self::monthly_costs();

		echo '<h2>' . esc_html__( 'Costos fijos del estudio', 'malka-crm' ) . '</h2>';
		echo '<p class="description">' .
			esc_html__( 'Arriendo, internet, software, contadora, hosting. Este es el presupuesto; una vez que haya tres meses de movimientos categorizados, manda el promedio real.', 'malka-crm' ) .
			'</p>';

		echo '<table class="widefat striped malka-costos" style="max-width:640px"><thead><tr>';
		echo '<th>' . esc_html__( 'Concepto', 'malka-crm' ) . '</th>';
		echo '<th style="width:160px">' . esc_html__( 'Monto mensual', 'malka-crm' ) . '</th>';
		echo '</tr></thead><tbody>';

		$i = 0;
		foreach ( $costos as $c ) {
			printf(
				'<tr><td><input type="text" name="costos[%1$d][nombre]" value="%2$s" class="regular-text" />' .
				/*
				 * Esta pantalla sólo edita el nombre y el monto, pero cada
				 * costo tiene además moneda, día, si varía, de qué cuenta
				 * sale, de quién es y cada cuánto se paga. Como al guardar
				 * se reconstruye la lista con lo que llega del formulario,
				 * sin estos campos ocultos guardar acá borraba todo eso:
				 * un cobro de USD 70 el día 5 quedaba en $70 sin día.
				 */
				'<input type="hidden" name="costos[%1$d][id]" value="%4$d" />' .
				'<input type="hidden" name="costos[%1$d][moneda]" value="%5$s" />' .
				'<input type="hidden" name="costos[%1$d][dia]" value="%6$d" />' .
				'<input type="hidden" name="costos[%1$d][variable]" value="%7$d" />' .
				'<input type="hidden" name="costos[%1$d][cuenta]" value="%8$d" />' .
				'<input type="hidden" name="costos[%1$d][ambito]" value="%9$s" />' .
				'<input type="hidden" name="costos[%1$d][ciclo]" value="%10$s" />' .
				'<input type="hidden" name="costos[%1$d][mes]" value="%11$d" />' .
				'<input type="hidden" name="costos[%1$d][tipo]" value="%12$s" /></td>' .
				'<td><input type="number" name="costos[%1$d][monto]" value="%3$s" step="any" /></td></tr>',
				(int) $i,
				esc_attr( $c['nombre'] ),
				esc_attr( $c['monto'] ),
				isset( $c['id'] ) ? (int) $c['id'] : 0,
				esc_attr( isset( $c['moneda'] ) ? $c['moneda'] : 'CLP' ),
				isset( $c['dia'] ) ? (int) $c['dia'] : 0,
				empty( $c['variable'] ) ? 0 : 1,
				isset( $c['cuenta'] ) ? (int) $c['cuenta'] : 0,
				esc_attr( isset( $c['ambito'] ) ? $c['ambito'] : 'estudio' ),
				esc_attr( isset( $c['ciclo'] ) ? $c['ciclo'] : 'mensual' ),
				isset( $c['mes'] ) ? (int) $c['mes'] : 0,
				esc_attr( isset( $c['tipo'] ) ? $c['tipo'] : 'fijo' )
			);
			$i++;
		}
		for ( $n = 0; $n < 3; $n++ ) {
			printf(
				'<tr><td><input type="text" name="costos[%1$d][nombre]" value="" class="regular-text" placeholder="%2$s" /></td>' .
				'<td><input type="number" name="costos[%1$d][monto]" value="" /></td></tr>',
				(int) $i,
				esc_attr__( 'Agregar costo', 'malka-crm' )
			);
			$i++;
		}

		echo '</tbody><tfoot><tr><th>' . esc_html__( 'Total presupuestado', 'malka-crm' ) . '</th>';
		echo '<th>' . esc_html( self::money( $info['presupuesto'] ) ) . '</th></tr>';
		if ( 'real' === $info['fuente'] ) {
			echo '<tr><th>' . esc_html__( 'Promedio real (3 meses)', 'malka-crm' ) . '</th>';
			echo '<th>' . esc_html( self::money( (int) $info['real'] ) ) . '</th></tr>';
		}
		echo '</tfoot></table>';
	}

	private function section_jornada( $s ) {
		$dias = is_array( $s['dias_semana'] ) ? $s['dias_semana'] : array();
		$am   = is_array( $s['bloque_am'] ) ? $s['bloque_am'] : array( '', '' );
		$pm   = is_array( $s['bloque_pm'] ) ? $s['bloque_pm'] : array( '', '' );
		$nom  = array( 1 => __( 'Lunes', 'malka-crm' ), 2 => __( 'Martes', 'malka-crm' ), 3 => __( 'Miércoles', 'malka-crm' ), 4 => __( 'Jueves', 'malka-crm' ), 5 => __( 'Viernes', 'malka-crm' ), 6 => __( 'Sábado', 'malka-crm' ), 7 => __( 'Domingo', 'malka-crm' ) );

		echo '<h2>' . esc_html__( 'Tu jornada', 'malka-crm' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'De acá sale el plan del día y las horas disponibles del año.', 'malka-crm' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Días que trabajas', 'malka-crm' ) . '</th><td>';
		foreach ( $nom as $n => $label ) {
			printf(
				'<label style="margin-right:1em"><input type="checkbox" name="dias_semana[]" value="%d" %s /> %s</label>',
				(int) $n,
				checked( in_array( $n, array_map( 'intval', $dias ), true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</td></tr>';
		$this->field( __( 'Mañana · desde', 'malka-crm' ), 'bloque_am_ini', isset( $am[0] ) ? $am[0] : '', 'time' );
		$this->field( __( 'Mañana · hasta', 'malka-crm' ), 'bloque_am_fin', isset( $am[1] ) ? $am[1] : '', 'time' );
		$this->field( __( 'Tarde · desde', 'malka-crm' ), 'bloque_pm_ini', isset( $pm[0] ) ? $pm[0] : '', 'time' );
		$this->field( __( 'Tarde · hasta', 'malka-crm' ), 'bloque_pm_fin', isset( $pm[1] ) ? $pm[1] : '', 'time' );
		$this->field( __( 'Duración del pomodoro (min)', 'malka-crm' ), 'pomodoro_min', $s['pomodoro_min'], 'number' );
		echo '</table>';

		$h = self::minutes_per_day() / 60;
		if ( $h > 0 ) {
			echo '<p class="description">' . sprintf(
				/* translators: 1: horas por día, 2: horas por año */
				esc_html__( 'Hoy: %1$s h al día, %2$s h disponibles en el año.', 'malka-crm' ),
				esc_html( number_format_i18n( $h, 1 ) ),
				esc_html( number_format_i18n( self::hours_per_year( (int) current_time( 'Y' ) ) ) )
			) . '</p>';
		}
	}

	private function section_tributario( $s ) {
		echo '<h2>' . esc_html__( 'Tributario y mora', 'malka-crm' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$this->field( __( 'IVA (%)', 'malka-crm' ), 'iva', $s['iva'], 'number' );
		$this->field( __( 'Retención de honorarios (%)', 'malka-crm' ), 'retencion', $s['retencion'], 'number' );
		$this->field( __( 'Comisión de tarjeta (%)', 'malka-crm' ), 'comision_tarjeta', $s['comision_tarjeta'], 'number' );
		$this->field( __( 'Interés corriente mensual (%)', 'malka-crm' ), 'mora_tasa', $s['mora_tasa'], 'number', __( 'Lo publica la CMF cada mes. Se aplica sobre el neto desde el día del vencimiento.', 'malka-crm' ) );
		$this->field( __( 'Comisión de recuperación (%)', 'malka-crm' ), 'mora_comision', $s['mora_comision'], 'number', __( 'La Ley 21.131 te da un 1% fijo, por una sola vez, en facturas impagas.', 'malka-crm' ) );
		$this->field( __( 'Aviso previo (días)', 'malka-crm' ), 'mora_aviso_prev', $s['mora_aviso_prev'], 'number' );
		$this->field( __( 'Recordatorio cada (días)', 'malka-crm' ), 'mora_recordar', $s['mora_recordar'], 'number' );
		echo '</table>';
		echo '<p class="description">' .
			esc_html__( 'El tope del duplo (artículo 1544 del Código Civil) se aplica siempre y no se puede desactivar.', 'malka-crm' ) .
			'</p>';
	}

	private function section_contadora( $s ) {
		echo '<h2>' . esc_html__( 'Contadora', 'malka-crm' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$this->field( __( 'Nombre', 'malka-crm' ), 'cont_nombre', $s['cont_nombre'] );
		$this->field( __( 'Correo', 'malka-crm' ), 'cont_email', $s['cont_email'], 'email' );
		$this->field( __( 'Teléfono', 'malka-crm' ), 'cont_telefono', $s['cont_telefono'] );
		$this->field( __( 'Copia a', 'malka-crm' ), 'cont_copia', $s['cont_copia'], 'email' );
		echo '<tr><th scope="row">' . esc_html__( 'Enviar automáticamente', 'malka-crm' ) . '</th><td>';
		$this->check( 'cont_envia_mora', __( 'Comprobante de mora, al recibirse el pago', 'malka-crm' ), $s['cont_envia_mora'] );
		$this->check( 'cont_envia_dte', __( 'Facturas y boletas emitidas', 'malka-crm' ), $s['cont_envia_dte'] );
		$this->check( 'cont_envia_nc', __( 'Notas de crédito', 'malka-crm' ), $s['cont_envia_nc'] );
		$this->check( 'cont_envia_libro', __( 'Libro de ventas del mes', 'malka-crm' ), $s['cont_envia_libro'] );
		echo '</td></tr></table>';
		echo '<p class="description">' .
			esc_html__( 'Lo demás lo ve directo en el SII. El comprobante de mora no existe allá, por eso se le manda.', 'malka-crm' ) .
			'</p>';
	}

	private function check( $name, $label, $value ) {
		printf(
			'<label style="display:block;margin-bottom:.35em"><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( (int) $value, 1, false ),
			esc_html( $label )
		);
	}

	/**
	 * Los estilos de párrafo del documento que ella puede tocar.
	 *
	 * No son todas las reglas del CSS —son decenas— sino los seis papeles
	 * que de verdad se leen distinto: el cuerpo, el título, los rótulos de
	 * sección, las tablas, los totales y la letra chica. Cada uno manda
	 * sobre varios selectores a la vez a través de una variable.
	 *
	 * @return array clave => array( rotulo, ayuda, px, peso ).
	 */
	public static function doc_estilos() {
		return array(
			'base'    => array(
				'rotulo' => __( 'Texto base', 'malka-crm' ),
				'ayuda'  => __( 'El cuerpo del documento y todo lo que no tiene estilo propio.', 'malka-crm' ),
				'px'     => 14,
				'peso'   => 400,
			),
			'titulo'  => array(
				'rotulo' => __( 'Título del documento', 'malka-crm' ),
				'ayuda'  => __( '«Cotización N° 613», arriba a la izquierda.', 'malka-crm' ),
				'px'     => 24,
				'peso'   => 600,
			),
			'seccion' => array(
				'rotulo' => __( 'Títulos de sección', 'malka-crm' ),
				'ayuda'  => __( '«Registro de pagos», «Datos del cliente» y los demás rótulos en mayúsculas.', 'malka-crm' ),
				'px'     => 12,
				'peso'   => 600,
			),
			'tabla'   => array(
				'rotulo' => __( 'Tablas', 'malka-crm' ),
				'ayuda'  => __( 'Las filas de ítems y de cuotas.', 'malka-crm' ),
				'px'     => 13,
				'peso'   => 400,
			),
			'total'   => array(
				'rotulo' => __( 'Totales', 'malka-crm' ),
				'ayuda'  => __( '«Total proyecto» y «Total a pagar».', 'malka-crm' ),
				'px'     => 18,
				'peso'   => 600,
			),
			'chica'   => array(
				'rotulo' => __( 'Letra chica', 'malka-crm' ),
				'ayuda'  => __( 'El detalle de cada ítem, las notas y el pie del documento.', 'malka-crm' ),
				'px'     => 12,
				'peso'   => 400,
			),
		);
	}

	/**
	 * Los estilos guardados, con los de fábrica rellenando los huecos.
	 *
	 * @return array clave => array( px, peso ).
	 */
	public static function doc_estilos_valores() {
		$guardados = self::get( 'doc_estilos', array() );
		$guardados = is_array( $guardados ) ? $guardados : array();
		$out       = array();

		foreach ( self::doc_estilos() as $k => $def ) {
			$px   = isset( $guardados[ $k ]['px'] ) ? (int) $guardados[ $k ]['px'] : 0;
			$peso = isset( $guardados[ $k ]['peso'] ) ? (int) $guardados[ $k ]['peso'] : 0;

			$out[ $k ] = array(
				'px'   => ( $px >= 8 && $px <= 48 ) ? $px : (int) $def['px'],
				'peso' => ( $peso >= 100 && $peso <= 900 ) ? $peso : (int) $def['peso'],
			);
		}

		return $out;
	}

	/* ─────────────────────────────  Guardado  ─────────────────────────── */

	public function handle_save() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para guardar la configuración.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_save_settings', 'malka_crm_settings_nonce' );

		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$out = array();

		/*
		 * Un campo que el formulario no trajo no se toca.
		 *
		 * Antes acá se escribía '' para cada clave ausente, y como no todos
		 * los formularios muestran todos los campos —el panel no tiene los
		 * datos bancarios ni el logo—, guardar desde el escritorio borraba el
		 * logo, el nombre de fantasía, los términos y la cuenta del banco. Un
		 * formulario que sí muestra un campo vacío manda '' igual, así que el
		 * borrado a propósito sigue funcionando.
		 */
		foreach ( array( 'razon_social', 'nombre_fantasia', 'rut', 'giro', 'direccion', 'comuna', 'telefono', 'cont_nombre', 'cont_telefono', 'banco_nombre', 'banco_tipo', 'banco_cuenta', 'banco_titular', 'banco_rut', 'tipografia', 'nombre_banco' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		foreach ( array( 'email', 'cont_email', 'cont_copia' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_email( $in[ $k ] );
			}
		}
		foreach ( array( 'terminos_url', 'link_sumup', 'logo_url', 'icono_app', 'tipografia_css' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = esc_url_raw( $in[ $k ] );
			}
		}

		// De dónde se baja la próxima versión.
		if ( isset( $in['repo'] ) ) {
			$out['repo'] = sanitize_text_field( $in['repo'] );
		}

		/*
		 * La llave del repositorio privado. El campo llega vacío cuando no
		 * se tocó —no se muestra nunca lo guardado— así que vacío significa
		 * «déjala como está», y no «bórrala».
		 */
		if ( isset( $in['repo_token'] ) ) {
			$t = trim( (string) $in['repo_token'] );

			// Un guion la borra, igual que la clave del asistente.
			if ( '-' === $t ) {
				$out['repo_token'] = '';
			} elseif ( '' !== $t ) {
				$out['repo_token'] = sanitize_text_field( $t );
			}
		}
		foreach ( array( 'sueldo_objetivo', 'vacaciones_dias', 'tope_donadas', 'tarifa_cobrada', 'pomodoro_min', 'mora_aviso_prev', 'mora_recordar', 'anticipo_pct', 'saldo_habiles', 'validez_dias', 'retiro_horizonte' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = max( 0, (int) $in[ $k ] );
			}
		}

		/*
		 * La clave de SumUp no se muestra en el formulario: vacío es «no la
		 * cambies», no «bórrala». Para borrarla se escribe un guion.
		 */
		// La clave del asistente, igual que la de SumUp: vacío no la cambia, un guion la borra.
		if ( isset( $in['ia_api_key'] ) ) {
			$k = trim( sanitize_text_field( $in['ia_api_key'] ) );
			if ( '-' === $k ) {
				$out['ia_api_key'] = '';
			} elseif ( '' !== $k ) {
				$out['ia_api_key'] = $k;
			}
		}
		if ( isset( $in['ia_modelo'] ) ) {
			$out['ia_modelo'] = preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) $in['ia_modelo'] ) );
		}
		if ( isset( $in['sumup_api_key'] ) ) {
			$k = trim( sanitize_text_field( $in['sumup_api_key'] ) );
			if ( '-' === $k ) {
				$out['sumup_api_key'] = '';
			} elseif ( '' !== $k ) {
				$out['sumup_api_key'] = $k;
			}
		}
		if ( isset( $in['sumup_merchant'] ) ) {
			$out['sumup_merchant'] = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $in['sumup_merchant'] ) );
		}
		if ( isset( $in['sumup_moneda'] ) ) {
			$m = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $in['sumup_moneda'] ) );
			$out['sumup_moneda'] = 3 === strlen( $m ) ? $m : 'CLP';
		}

		// Las notas tipo conservan los saltos de línea: son una por viñeta.
		if ( isset( $in['notas_tipo'] ) ) {
			$out['notas_tipo'] = sanitize_textarea_field( $in['notas_tipo'] );
		}

		// Los feriados se guardan tal cual se escriben; feriados() filtra al leer.
		if ( isset( $in['feriados'] ) ) {
			$out['feriados'] = implode( "\n", array_map( 'sanitize_text_field', preg_split( '/[\r\n]+/', (string) $in['feriados'] ) ) );
			self::flush_feriados();
		}
		foreach ( array( 'carga_prev', 'colchon', 'ocupacion', 'iva', 'retencion', 'ppm', 'comision_tarjeta', 'mora_tasa', 'mora_comision', 'fx_dolar', 'fx_euro' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = max( 0, (float) str_replace( ',', '.', $in[ $k ] ) );
			}
		}

		/*
		 * Los estilos del documento. Se guardan sólo los que llegaron, y
		 * acotados: un tamaño de 400 px o un peso de 2000 no es un gusto,
		 * es una cotización rota.
		 */
		if ( isset( $in['doc_estilos'] ) && is_array( $in['doc_estilos'] ) ) {
			$estilos = array();

			foreach ( self::doc_estilos() as $k => $def ) {
				if ( ! isset( $in['doc_estilos'][ $k ] ) || ! is_array( $in['doc_estilos'][ $k ] ) ) {
					continue;
				}

				$fila = $in['doc_estilos'][ $k ];
				$px   = isset( $fila['px'] ) ? (int) $fila['px'] : (int) $def['px'];
				$peso = isset( $fila['peso'] ) ? (int) $fila['peso'] : (int) $def['peso'];

				$estilos[ $k ] = array(
					'px'   => min( 48, max( 8, $px ) ),
					'peso' => min( 900, max( 100, 100 * (int) round( $peso / 100 ) ) ),
				);
			}

			$out['doc_estilos'] = $estilos;
		}

		if ( isset( $in['avisos_pausa'] ) ) {
			$out['avisos_pausa'] = Malka_CRM_Helpers::sanitize_date( $in['avisos_pausa'] );
		}

		/*
		 * Con las casillas isset() no sirve: una casilla desmarcada no manda
		 * nada, igual que un campo que no está en el formulario. Por eso cada
		 * formulario declara qué bloques trae, y sólo esos se tocan.
		 */
		$bloques = isset( $in['malka_bloques'] ) && is_array( $in['malka_bloques'] )
			? array_map( 'sanitize_key', $in['malka_bloques'] )
			: array();

		$trae = function ( $bloque ) use ( $bloques ) {
			// Sin declaración se asume el formulario completo: así los
			// formularios viejos siguen comportándose igual.
			return ! $bloques || in_array( $bloque, $bloques, true );
		};

		if ( $trae( 'mensajes' ) && class_exists( 'Malka_CRM_Messages' ) ) {
			$out['mensajes'] = Malka_CRM_Messages::sanear( isset( $in['mensajes'] ) ? $in['mensajes'] : array() );
		}

		if ( $trae( 'textos' ) && class_exists( 'Malka_CRM_Messages' ) ) {
			$out['textos'] = Malka_CRM_Messages::sanear_textos( isset( $in['textos'] ) ? $in['textos'] : array() );
		}

		if ( $trae( 'contadora' ) ) {
			foreach ( array( 'cont_envia_mora', 'cont_envia_dte', 'cont_envia_nc', 'cont_envia_libro' ) as $k ) {
				$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			}
		}

		if ( $trae( 'cobros' ) ) {
			$out['avisos_default'] = empty( $in['avisos_default'] ) ? 0 : 1;
		}

		if ( $trae( 'jornada' ) ) {
			$out['dias_semana'] = array();
		}
		if ( $trae( 'jornada' ) && ! empty( $in['dias_semana'] ) && is_array( $in['dias_semana'] ) ) {
			foreach ( $in['dias_semana'] as $d ) {
				$d = (int) $d;
				if ( $d >= 1 && $d <= 7 ) {
					$out['dias_semana'][] = $d;
				}
			}
		}

		if ( $trae( 'jornada' ) ) {
			$out['bloque_am'] = array(
				$this->clean_time( isset( $in['bloque_am_ini'] ) ? $in['bloque_am_ini'] : '' ),
				$this->clean_time( isset( $in['bloque_am_fin'] ) ? $in['bloque_am_fin'] : '' ),
			);
			$out['bloque_pm'] = array(
				$this->clean_time( isset( $in['bloque_pm_ini'] ) ? $in['bloque_pm_ini'] : '' ),
				$this->clean_time( isset( $in['bloque_pm_fin'] ) ? $in['bloque_pm_fin'] : '' ),
			);
		}

		if ( $trae( 'costos' ) ) {
			$out['costos'] = array();
		}
		$monedas = self::currencies();

		if ( $trae( 'costos' ) && ! empty( $in['costos'] ) && is_array( $in['costos'] ) ) {
			foreach ( $in['costos'] as $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$nombre = isset( $c['nombre'] ) ? sanitize_text_field( $c['nombre'] ) : '';
				if ( '' === $nombre ) {
					continue;
				}

				$moneda = isset( $c['moneda'] ) ? strtoupper( sanitize_text_field( $c['moneda'] ) ) : 'CLP';
				if ( ! isset( $monedas[ $moneda ] ) ) {
					$moneda = 'CLP';
				}

				// En dólares o euros los decimales importan; en pesos no existen.
				$monto = isset( $c['monto'] ) ? max( 0, (float) str_replace( ',', '.', $c['monto'] ) ) : 0;
				$monto = ( 'CLP' === $moneda ) ? (int) round( $monto ) : round( $monto, 2 );

				$dia = isset( $c['dia'] ) ? (int) $c['dia'] : 0;
				$dia = ( $dia >= 1 && $dia <= 31 ) ? $dia : 0;

				$ciclo = isset( $c['ciclo'] ) && in_array( $c['ciclo'], array( 'mensual', 'anual' ), true ) ? $c['ciclo'] : 'mensual';

				$ambito = isset( $c['ambito'] ) && in_array( $c['ambito'], array( 'estudio', 'personal' ), true ) ? $c['ambito'] : 'estudio';

				$tipo = isset( $c['tipo'] ) && in_array( $c['tipo'], array( 'fijo', 'suscripcion' ), true ) ? $c['tipo'] : 'fijo';

				// El mes sólo significa algo en un cobro anual.
				$mes = isset( $c['mes'] ) ? (int) $c['mes'] : 0;
				$mes = ( 'anual' === $ciclo && $mes >= 1 && $mes <= 12 ) ? $mes : 0;

				$out['costos'][] = array(
					/*
					 * El id viaja en un campo oculto del formulario. Sin él,
					 * editar o borrar una fila dependería de su posición, y
					 * la posición cambia cada vez que se reordena la lista.
					 */
					'id'       => isset( $c['id'] ) ? max( 0, (int) $c['id'] ) : 0,
					'nombre'   => $nombre,
					'monto'    => $monto,
					'moneda'   => $moneda,
					'dia'      => $dia,
					// Al mes o al año. Lo anual se reparte en doce para sumar
					// y se cobra entero el mes que toca.
					'ciclo'    => $ciclo,
					'mes'      => $mes,
					/*
					 * Del estudio o tuyo. Sólo lo del estudio entra al costo
					 * fijo con el que se calcula la tarifa por hora: anotar
					 * ahí lo de casa infla la tarifa que se cobra.
					 */
					'ambito'   => $ambito,
					/*
					 * Costo fijo o suscripción. Es la misma lista mirada en
					 * dos pantallas; viaja en un campo oculto para que
					 * guardar acá no mueva una fila de lista.
					 */
					'tipo'     => $tipo,
					// El monto cambia mes a mes (la luz, una suscripción
					// por uso): al marcarlo pagado se pregunta cuánto fue.
					'variable' => empty( $c['variable'] ) ? 0 : 1,
					// De qué cuenta o tarjeta sale. Lo que se paga con una
					// tarjeta tuya no baja la cuenta del estudio: el estudio
					// te lo queda debiendo.
					'cuenta'   => isset( $c['cuenta'] ) ? max( 0, (int) $c['cuenta'] ) : 0,
				);
			}
		}

		self::update( $out );

		// Vuelve de donde vino: si guardó desde el escritorio, se queda ahí.
		$volver = isset( $in['malka_volver'] ) ? esc_url_raw( $in['malka_volver'] ) : '';

		if ( $volver ) {
			$destino = add_query_arg( 'malka_saved', '1', $volver );
		} else {
			$destino = add_query_arg(
				array(
					'page'        => 'malka-crm-config',
					'malka_saved' => '1',
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $destino );
		exit;
	}

	private function clean_time( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^\d{1,2}:\d{2}$/', $v ) ? $v : '';
	}

	/* ──────────────────────────  Días hábiles  ────────────────────────── */

	/** @var array|null Feriados ya leídos, para no parsear en cada consulta. */
	private static $feriados_cache = null;

	/**
	 * Mediodía UTC de una fecha.
	 *
	 * Anclar al mediodía y leer siempre en UTC evita que el cálculo se corra
	 * un día cuando cambia el horario de verano, que en Chile pasa dos veces
	 * al año y justo a medianoche.
	 *
	 * @param string $fecha Y-m-d.
	 * @return int|false
	 */
	private static function mediodia( $fecha ) {
		$fecha = trim( (string) $fecha );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) ) {
			return false;
		}

		return strtotime( $fecha . ' 12:00:00 UTC' );
	}

	/**
	 * Feriados con que arranca el plugin.
	 *
	 * Son fechas, no reglas. En Chile los feriados se fijan por ley y cada
	 * tanto aparece uno nuevo, así que una fórmula quedaría coja igual; es
	 * más honesto traer una lista que ella pueda editar y que el panel avise
	 * cuando se le esté acabando.
	 *
	 * Incluye el feriado bancario del 31 de diciembre, porque lo que importa
	 * acá es si el banco mueve la plata, no si se trabaja.
	 *
	 * @return string Una fecha por línea.
	 */
	public static function feriados_semilla() {
		return implode(
			"\n",
			array(
				// 2026
				'2026-01-01', // Año Nuevo
				'2026-04-03', // Viernes Santo
				'2026-04-04', // Sábado Santo
				'2026-05-01', // Día del Trabajo
				'2026-05-21', // Glorias Navales
				'2026-06-21', // Pueblos Indígenas
				'2026-06-29', // San Pedro y San Pablo
				'2026-07-16', // Virgen del Carmen
				'2026-08-15', // Asunción de la Virgen
				'2026-09-18', // Independencia
				'2026-09-19', // Glorias del Ejército
				'2026-10-12', // Encuentro de Dos Mundos
				'2026-10-31', // Iglesias Evangélicas
				'2026-11-01', // Todos los Santos
				'2026-12-08', // Inmaculada Concepción
				'2026-12-25', // Navidad
				'2026-12-31', // Feriado bancario
				// 2027
				'2027-01-01',
				'2027-03-26',
				'2027-03-27',
				'2027-05-01',
				'2027-05-21',
				'2027-06-21',
				'2027-06-28', // San Pedro y San Pablo, corrido al lunes
				'2027-07-16',
				'2027-08-15',
				'2027-09-18',
				'2027-09-19',
				'2027-10-11', // Encuentro de Dos Mundos, corrido al lunes
				'2027-10-31',
				'2027-11-01',
				'2027-12-08',
				'2027-12-25',
				'2027-12-31',
			)
		);
	}

	/**
	 * Los feriados configurados, como mapa Y-m-d => true.
	 *
	 * @return array
	 */
	public static function feriados() {
		if ( null !== self::$feriados_cache ) {
			return self::$feriados_cache;
		}

		$mapa  = array();
		$crudo = (string) self::get( 'feriados', '' );

		foreach ( preg_split( '/[\r\n,;]+/', $crudo ) as $linea ) {
			$linea = trim( $linea );

			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $linea, $m )
				&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				$mapa[ $linea ] = true;
			}
		}

		self::$feriados_cache = $mapa;

		return $mapa;
	}

	/**
	 * Vacía la lista en memoria. Se llama al guardar Configuración.
	 */
	public static function flush_feriados() {
		self::$feriados_cache = null;
	}

	/**
	 * ¿Es un día en que el banco mueve plata?
	 *
	 * @param string $fecha Y-m-d.
	 * @return bool
	 */
	public static function es_habil( $fecha ) {
		$ts = self::mediodia( $fecha );

		if ( ! $ts ) {
			return false;
		}

		$dia = (int) gmdate( 'N', $ts ); // 6 sábado, 7 domingo.

		if ( $dia >= 6 ) {
			return false;
		}

		$feriados = self::feriados();

		return ! isset( $feriados[ gmdate( 'Y-m-d', $ts ) ] );
	}

	/**
	 * Suma días hábiles a una fecha.
	 *
	 * Cuenta desde el día siguiente al de partida y sólo suma los días en que
	 * el banco mueve plata, así que un viernes + 1 cae el lunes, y un viernes
	 * víspera de feriado cae el martes. Si se parte un sábado o un feriado,
	 * el primer hábil que aparece ya es el día 1: entregar un sábado le deja
	 * al cliente el lunes completo, que es exactamente un día hábil.
	 *
	 * El resultado siempre cae en un día hábil, incluso con $habiles en 0.
	 *
	 * @param string $fecha  Y-m-d de partida.
	 * @param int    $habiles Cuántos sumar.
	 * @return string Y-m-d.
	 */
	public static function sumar_habiles( $fecha, $habiles = 1 ) {
		$ts = self::mediodia( $fecha );

		if ( ! $ts ) {
			return '';
		}

		$habiles = max( 0, (int) $habiles );
		$puestos = 0;
		$tope    = 0; // Red de seguridad por si la lista de feriados viniera absurda.

		while ( ( $puestos < $habiles || ! self::es_habil( gmdate( 'Y-m-d', $ts ) ) ) && $tope < 400 ) {
			$ts += DAY_IN_SECONDS;
			$tope++;

			if ( self::es_habil( gmdate( 'Y-m-d', $ts ) ) ) {
				$puestos++;
			}
		}

		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Hasta qué fecha alcanza la lista de feriados.
	 *
	 * El panel lo usa para avisar antes de que se acabe, en vez de empezar a
	 * calcular plazos como si no hubiera feriados.
	 *
	 * @return string Y-m-d, o '' si no hay ninguno.
	 */
	public static function feriados_hasta() {
		$dias = array_keys( self::feriados() );

		if ( ! $dias ) {
			return '';
		}

		sort( $dias );

		return (string) end( $dias );
	}

	/* ─────────────────────────────  Utilidad  ─────────────────────────── */

	/**
	 * Formatea un monto en pesos chilenos.
	 *
	 * @param int|float $n Monto.
	 */
	public static function money( $n ) {
		$n = (float) $n;
		// El signo va antes del peso: -$1.200, nunca $-1.200.
		return ( $n < 0 ? '-$' : '$' ) . number_format( abs( $n ), 0, ',', '.' );
	}
}
