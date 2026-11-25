<?php
/**
 * El Inicio, armado por ella.
 *
 * Hasta ahora el mosaico era una lista fija de siete paneles en un orden
 * fijo: lo que yo supuse que ella miraba primero. Pero lo que uno mira
 * primero cambia según el mes —en cobranza fuerte manda «Por cobrar», en
 * meses de producción manda «Tu día»— y nadie puede adivinarlo desde acá.
 *
 * Así que el orden, el ancho y qué se ve son suyos. Se arrastra y se guarda
 * solo. Lo que no toque usa el reparto de siempre, y un bloque nuevo del
 * plugin aparece sin que tenga que ir a buscarlo.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo, orden y dibujo del mosaico de Inicio.
 */
class Malka_CRM_Home {

	/** Dónde se guarda su reparto. */
	const OPCION = 'malka_crm_inicio';

	/**
	 * Los anchos que puede elegir, en doceavos.
	 *
	 * Cuatro y no un control libre: con un deslizador se llega a anchos que
	 * no cierran una fila y el mosaico queda con huecos. Con estos cuatro,
	 * cualquier combinación cuadra.
	 */
	const ANCHOS = array( 4, 6, 8, 12 );

	/**
	 * Engancha el guardado del reparto.
	 */
	public function __construct() {
		add_action( 'wp_ajax_malka_crm_inicio', array( $this, 'handle_guardar' ) );
		add_action( 'wp_ajax_malka_crm_tarea_lista', array( $this, 'handle_tarea_lista' ) );
		add_action( 'wp_ajax_malka_crm_cobros_hechos', array( $this, 'handle_cobros_hechos' ) );
	}

	/**
	 * «Hoy ya cobré»: marca la fila de cobranza de «Tu día» por hoy.
	 *
	 * No toca ninguna cuota —eso pasa cuando el pago entra—; sólo dice que
	 * la gestión del día está hecha.
	 */
	public function handle_cobros_hechos() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}

		update_option( 'malka_crm_cobros_hechos', current_time( 'Y-m-d' ), false );
		wp_send_json_success( array( 'hecho' => true ) );
	}

	/**
	 * Marca una tarea como lista desde «Tu día».
	 */
	public function handle_tarea_lista() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$pid = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$tid = isset( $_POST['tid'] ) ? sanitize_key( wp_unslash( $_POST['tid'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Las del estudio (sin negocio) viven aparte.
		if ( ! $pid && '' !== $tid ) {
			$propias = Malka_CRM_Projects::tareas_estudio();
			foreach ( $propias as $i => $t ) {
				if ( isset( $t['id'] ) && (string) $t['id'] === $tid ) {
					$propias[ $i ] = Malka_CRM_Projects::completar( $t );
					Malka_CRM_Projects::guardar_estudio( $propias );
					wp_send_json_success( array( 'lista' => true ) );
				}
			}
		}

		$tasks = $pid ? get_post_meta( $pid, '_malka_pm_tasks', true ) : array();

		if ( ! is_array( $tasks ) || '' === $tid ) {
			wp_send_json_error( array( 'message' => __( 'No encontré esa tarea.', 'malka-crm' ) ), 404 );
		}

		foreach ( $tasks as $i => $t ) {
			if ( isset( $t['id'] ) && (string) $t['id'] === $tid ) {
				// Una tarea que se repite no se cierra: pasa a la próxima fecha.
				$tasks[ $i ] = Malka_CRM_Projects::completar( $t );
				Malka_CRM_Helpers::save_tasks( $pid, $tasks );
				wp_send_json_success( array( 'lista' => true ) );
			}
		}

		wp_send_json_error( array( 'message' => __( 'No encontré esa tarea.', 'malka-crm' ) ), 404 );
	}

	/* ─────────────────────────────  Catálogo  ─────────────────────────── */

	/**
	 * Todos los bloques que puede poner en el Inicio.
	 *
	 * `ancho` y `ver` son el reparto de fábrica: lo que ve quien nunca tocó
	 * nada. `pinta` es quién lo dibuja.
	 *
	 * @return array
	 */
	public static function catalogo() {
		/*
		 * El orden de fábrica va de lo que hay que hacer a lo que se analiza:
		 * arriba cobrar y el plan de hoy, al medio cómo van los negocios y lo
		 * que notó el panel, y abajo los números del mes y del año.
		 */
		return array(
			/*
			 * La meta y el saldo abren el Inicio: hacia dónde vas y con qué
			 * cuentas. Antes la meta se dibujaba fija arriba de todo y no se
			 * podía mover ni esconder como el resto.
			 */
			'meta'       => array(
				'rotulo' => __( 'Tu meta del mes', 'malka-crm' ),
				'ayuda'  => __( 'Cuánto necesitas y cuánto llevas', 'malka-crm' ),
				// Es una banda, no una tarjeta: ocupa el ancho entero.
				'ancho'  => 12,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Dashboard', 'panel_meta' ),
			),
			'saldo'      => array(
				'rotulo' => __( 'Lo que hay', 'malka-crm' ),
				'ayuda'  => __( 'El saldo de cada cuenta, el del estudio y el tuyo', 'malka-crm' ),
				'ancho'  => 4,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Dashboard', 'panel_saldo' ),
			),
			'cobros'     => array(
				'rotulo' => __( 'Por cobrar', 'malka-crm' ),
				'ayuda'  => __( 'Lo vencido, con su botón para avisar', 'malka-crm' ),
				'ancho'  => 4,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Dashboard', 'panel_payments' ),
			),
			'pagar'      => array(
				'rotulo' => __( 'Lo que tienes que pagar', 'malka-crm' ),
				'ayuda'  => __( 'Lo vencido primero, después lo que viene', 'malka-crm' ),
				'ancho'  => 4,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Dashboard', 'panel_pagar' ),
			),
			'agenda'     => array(
				'rotulo' => __( 'Tu agenda', 'malka-crm' ),
				'ayuda'  => __( 'Lo que tienes a una hora fija', 'malka-crm' ),
				'ancho'  => 4,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Eventos', 'panel' ),
			),
			'dia'        => array(
				'rotulo' => __( 'Tu día', 'malka-crm' ),
				'ayuda'  => __( 'El plan de hoy, hora por hora', 'malka-crm' ),
				'ancho'  => 8,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Dashboard', 'panel_day' ),
			),
			'negocios'   => array(
				'rotulo' => __( 'Negocios', 'malka-crm' ),
				'ayuda'  => __( 'En qué andas con cada cliente', 'malka-crm' ),
				'ancho'  => 6,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Dashboard', 'panel_deals' ),
			),
			'analisis'   => array(
				'rotulo' => __( 'Qué veo en tus números', 'malka-crm' ),
				'ayuda'  => __( 'Los desvíos que encuentro solo', 'malka-crm' ),
				'ancho'  => 6,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Dashboard', 'panel_analysis' ),
			),
			'retiros'    => array(
				'rotulo' => __( 'Tus retiros', 'malka-crm' ),
				'ayuda'  => __( 'Lo que sacaste contra lo que ganó el negocio', 'malka-crm' ),
				'ancho'  => 6,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Retiro', 'panel_inicio' ),
			),
			'masvendido' => array(
				'rotulo' => __( 'Lo que más vendes', 'malka-crm' ),
				'ayuda'  => __( 'Qué servicio te deja más plata', 'malka-crm' ),
				'ancho'  => 6,
				'ver'    => true,
				'pinta'  => array( __CLASS__, 'panel_mas_vendido' ),
			),
			'tarifa'     => array(
				'rotulo' => __( 'Tu tarifa', 'malka-crm' ),
				'ayuda'  => __( 'Cuánto vale tu hora hoy', 'malka-crm' ),
				'ancho'  => 6,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Dashboard', 'panel_rate' ),
			),
			'ventas'     => array(
				'rotulo' => __( 'Ventas por mes', 'malka-crm' ),
				'ayuda'  => __( 'Lo facturado, mes a mes, sin IVA', 'malka-crm' ),
				'ancho'  => 6,
				'ver'    => true,
				'pinta'  => array( __CLASS__, 'panel_ventas' ),
				'falta'  => array( __( 'Anota tus facturas', 'malka-crm' ), 'documentos' ),
			),
			'compras'    => array(
				'rotulo' => __( 'Compras por mes', 'malka-crm' ),
				'ayuda'  => __( 'Lo que te facturan a ti, mes a mes', 'malka-crm' ),
				'ancho'  => 6,
				'ver'    => true,
				'pinta'  => array( __CLASS__, 'panel_compras' ),
				'falta'  => array( __( 'Carga las facturas de proveedores', 'malka-crm' ), 'documentos' ),
			),
			'movimientos' => array(
				'rotulo' => __( 'Movimientos', 'malka-crm' ),
				'ayuda'  => __( 'Lo último que entró y salió', 'malka-crm' ),
				'ancho'  => 6,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Dashboard', 'panel_movements' ),
				'falta'  => array( __( 'Ingresa el saldo de tu cuenta', 'malka-crm' ), 'movimientos' ),
			),
			'probono'    => array(
				'rotulo' => __( 'Horas donadas', 'malka-crm' ),
				'ayuda'  => __( 'Lo que regalaste sin querer', 'malka-crm' ),
				'ancho'  => 6,
				'ver'    => true,
				'pinta'  => array( 'Malka_CRM_Dashboard', 'panel_pro_bono' ),
				'falta'  => array( __( 'Marca un proyecto como sin costo', 'malka-crm' ), 'negocios' ),
			),
		);
	}

	/* ─────────────────────────────  Su reparto  ───────────────────────── */

	/**
	 * Lo guardado, limpio.
	 *
	 * @return array {orden, ocultos, anchos}
	 */
	public static function guardado() {
		$v = get_option( self::OPCION, array() );

		return array(
			'orden'   => ( isset( $v['orden'] ) && is_array( $v['orden'] ) ) ? array_map( 'strval', $v['orden'] ) : array(),
			'ocultos' => ( isset( $v['ocultos'] ) && is_array( $v['ocultos'] ) ) ? array_map( 'strval', $v['ocultos'] ) : array(),
			'anchos'  => ( isset( $v['anchos'] ) && is_array( $v['anchos'] ) ) ? $v['anchos'] : array(),
		);
	}

	/**
	 * El mosaico como va a salir: cada bloque con su ancho y si se ve.
	 *
	 * Un bloque que el plugin agregue después no está en su orden guardado y
	 * aun así tiene que aparecer: se va al final, con su ancho de fábrica.
	 * Lo contrario —esconderlo hasta que ella descubra que existe— sería una
	 * función nueva que nadie ve.
	 *
	 * @return array[] key => {rotulo, ayuda, ancho, ver, pinta}
	 */
	public static function bloques() {
		$cat = self::catalogo();
		$mio = self::guardado();

		$fuera = array();

		/*
		 * Su orden manda, pero un bloque nuevo entra donde el catálogo dice
		 * que va, no desterrado al final. Mandarlo al final escondía abajo
		 * de todo justo lo que se acaba de agregar, que es lo que ella
		 * todavía no sabe que existe.
		 */
		$suyo = array();

		foreach ( $mio['orden'] as $k ) {
			if ( isset( $cat[ $k ] ) ) {
				$suyo[] = $k;
			}
		}

		if ( ! $suyo ) {
			return self::anchos( $cat, $mio );
		}

		$claves = array_keys( $cat );

		foreach ( $claves as $i => $k ) {
			if ( in_array( $k, $suyo, true ) ) {
				continue;
			}

			// El primer vecino de más adelante que ella sí ordenó.
			$ancla = null;

			for ( $j = $i + 1; $j < count( $claves ); $j++ ) {
				if ( in_array( $claves[ $j ], $suyo, true ) ) {
					$ancla = $claves[ $j ];
					break;
				}
			}

			$donde = null === $ancla ? count( $suyo ) : (int) array_search( $ancla, $suyo, true );

			array_splice( $suyo, $donde, 0, array( $k ) );
		}

		foreach ( $suyo as $k ) {
			$fuera[ $k ] = $cat[ $k ];
		}

		return self::anchos( $fuera, $mio );
	}

	/**
	 * Le pone a cada bloque el ancho y el ver/no ver que ella eligió.
	 *
	 * @param array $fuera Bloques en su orden.
	 * @param array $mio   Lo guardado.
	 * @return array
	 */
	private static function anchos( $fuera, $mio ) {
		foreach ( $fuera as $k => $b ) {
			/*
			 * Un ancho que no está entre los permitidos no le da columnas al
			 * bloque y el texto sale en vertical, una letra por línea. Pasó
			 * con un bloque nuevo puesto en 3: mejor caer en el más angosto
			 * válido que dibujar algo ilegible.
			 */
			if ( ! isset( $b['ancho'] ) || ! in_array( (int) $b['ancho'], self::ANCHOS, true ) ) {
				$fuera[ $k ]['ancho'] = self::ANCHOS[0];
			}

			if ( isset( $mio['anchos'][ $k ] ) && in_array( (int) $mio['anchos'][ $k ], self::ANCHOS, true ) ) {
				$fuera[ $k ]['ancho'] = (int) $mio['anchos'][ $k ];
			}

			$fuera[ $k ]['ver'] = ! in_array( (string) $k, $mio['ocultos'], true );
		}

		return $fuera;
	}

	/**
	 * Guarda el reparto que llegó del navegador.
	 */
	public function handle_guardar() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}

		$cat = self::catalogo();

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$orden   = isset( $_POST['orden'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['orden'] ) ) ) : array();
		$ocultos = isset( $_POST['ocultos'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['ocultos'] ) ) ) : array();
		$anchos  = isset( $_POST['anchos'] ) ? sanitize_text_field( wp_unslash( $_POST['anchos'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$limpio = array(
			'orden'   => array(),
			'ocultos' => array(),
			'anchos'  => array(),
		);

		foreach ( $orden as $k ) {
			$k = sanitize_key( trim( $k ) );

			if ( isset( $cat[ $k ] ) && ! in_array( $k, $limpio['orden'], true ) ) {
				$limpio['orden'][] = $k;
			}
		}

		foreach ( $ocultos as $k ) {
			$k = sanitize_key( trim( $k ) );

			if ( isset( $cat[ $k ] ) && ! in_array( $k, $limpio['ocultos'], true ) ) {
				$limpio['ocultos'][] = $k;
			}
		}

		// «clave:ancho,clave:ancho». Un ancho que no sea de la lista se cae.
		foreach ( explode( ',', $anchos ) as $par ) {
			$trozos = explode( ':', $par );

			if ( 2 !== count( $trozos ) ) {
				continue;
			}

			$k = sanitize_key( trim( $trozos[0] ) );
			$a = (int) $trozos[1];

			if ( isset( $cat[ $k ] ) && in_array( $a, self::ANCHOS, true ) ) {
				$limpio['anchos'][ $k ] = $a;
			}
		}

		update_option( self::OPCION, $limpio, false );

		wp_send_json_success( array( 'guardado' => true ) );
	}

	/* ─────────────────────────────  Dibujo  ───────────────────────────── */

	/**
	 * El mosaico completo.
	 *
	 * @return string
	 */
	public static function render() {
		$bloques = self::bloques();

		ob_start();

		printf(
			// El botón está en el encabezado, junto al +. Acá queda sólo la
			// pista, que aparece mientras se personaliza.
			'<div class="esc-mosaic__bar"><span class="esc-mosaic__hint" id="esc-personalizar-pista" hidden>%s</span></div>',
			esc_html__( 'Arrastra los cuadros con el asa para ordenarlos, o muévelos con Alt y las flechas. Se guarda solo.', 'malka-crm' )
		);

		printf(
			'<div class="esc-mosaic" id="esc-mosaic" data-nonce="%s">',
			esc_attr( wp_create_nonce( 'malka_crm_inicio' ) )
		);

		/*
		 * El aviso de «devuelve X a la cuenta de la empresa» ya no abre el
		 * Inicio: se fue a Finanzas, que es donde se arregla. Belén lo pidió
		 * así y tiene sentido —el Inicio dice qué hacer hoy y esto no se
		 * resuelve hoy— con un costo que conviene tener presente: era el
		 * único lugar donde el problema se veía sin ir a buscarlo.
		 */


		/*
		 * Los escondidos también se dibujan, sólo que no se muestran. Así
		 * esconderlos y volverlos a poner es instantáneo —y mientras
		 * personaliza aparecen en fantasma, en su sitio, para que se vea
		 * dónde volverían—. El precio es calcular un panel que no se mira, y
		 * vale la pena.
		 */
		$vacios = array();

		foreach ( $bloques as $clave => $b ) {
			$html = is_callable( $b['pinta'] ) ? (string) call_user_func( $b['pinta'] ) : '';

			if ( '' === trim( $html ) ) {
				continue;
			}

			/*
			 * Un cuadro que sólo dice «todavía no hay datos» no se muestra:
			 * ocupaba medio Inicio en blanco. Queda nombrado abajo, en una
			 * línea, con el enlace a donde se completa. Al personalizar sí
			 * aparece, para poder moverlo.
			 */
			$vacio = ! empty( $b['falta'] ) && self::sin_datos( $html );
			if ( $vacio && ! empty( $b['ver'] ) ) {
				$vacios[] = $b;
			}

			printf(
				'<div class="esc-cuadro esc-cuadro--w%1$d%4$s" data-bloque="%2$s" data-ancho="%1$d" data-rotulo="%3$s">%5$s%6$s</div>',
				(int) $b['ancho'],
				esc_attr( $clave ),
				esc_attr( $b['rotulo'] ),
				( empty( $b['ver'] ) ? ' is-oculto' : '' ) . ( $vacio ? ' is-vacio' : '' ),
				self::mando( $clave, $b ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		echo '</div>';

		if ( $vacios ) {
			echo '<div class="esc-faltan"><span class="esc-faltan__t">' . esc_html__( 'Para ver más acá', 'malka-crm' ) . '</span>';
			foreach ( $vacios as $b ) {
				printf(
					'<a class="esc-faltan__i" href="%1$s"><b>%2$s</b><span>%3$s →</span></a>',
					esc_url( Malka_CRM_Dashboard::view_url( $b['falta'][1] ) ),
					esc_html( $b['rotulo'] ),
					esc_html( $b['falta'][0] )
				);
			}
			echo '</div>';
		}

		return ob_get_clean();
	}

	/**
	 * ¿El panel sólo trae su mensaje de «sin datos»?
	 *
	 * @param string $html Panel dibujado.
	 * @return bool
	 */
	private static function sin_datos( $html ) {
		if ( false === strpos( $html, 'esc-empty' ) ) {
			return false;
		}
		// Si además trae filas, cifras o barras, no está vacío.
		foreach ( array( 'class="esc-mov"', 'esc-gf__r', 'class="esc-gm"', '<table', 'esc-pb__r' ) as $marca ) {
			if ( false !== strpos( $html, $marca ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * La barra de mando de un cuadro, visible sólo al personalizar.
	 *
	 * @param string $clave Bloque.
	 * @param array  $b     Su ficha.
	 * @return string
	 */
	private static function mando( $clave, $b ) {
		return sprintf(
			'<div class="esc-cuadro__m">' .
			'<button type="button" class="esc-asa esc-cuadro__a" aria-label="%1$s" title="%1$s">' .
			'<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false">' .
			'<circle cx="6" cy="4" r="1.3"/><circle cx="10" cy="4" r="1.3"/>' .
			'<circle cx="6" cy="8" r="1.3"/><circle cx="10" cy="8" r="1.3"/>' .
			'<circle cx="6" cy="12" r="1.3"/><circle cx="10" cy="12" r="1.3"/></svg></button>' .
			'<button type="button" class="esc-mini esc-cuadro__w" data-ancho-btn>%2$s</button>' .
			'<button type="button" class="esc-mini esc-cuadro__x" data-ver>%3$s</button>' .
			'</div>',
			esc_attr(
				sprintf(
					/* translators: nombre del bloque */
					__( 'Mover %s', 'malka-crm' ),
					$b['rotulo']
				)
			),
			esc_html( self::rotulo_ancho( (int) $b['ancho'] ) ),
			esc_html( empty( $b['ver'] ) ? __( 'Mostrar', 'malka-crm' ) : __( 'Esconder', 'malka-crm' ) )
		);
	}

	/**
	 * Cómo se llama un ancho.
	 *
	 * @param int $a Doceavos.
	 * @return string
	 */
	public static function rotulo_ancho( $a ) {
		$nombres = array(
			4  => __( 'Un tercio', 'malka-crm' ),
			6  => __( 'La mitad', 'malka-crm' ),
			8  => __( 'Dos tercios', 'malka-crm' ),
			12 => __( 'Todo el ancho', 'malka-crm' ),
		);

		return isset( $nombres[ $a ] ) ? $nombres[ $a ] : $nombres[6];
	}

	/* ─────────────────────────────  Los datos  ────────────────────────── */

	/**
	 * Lo vendido o lo comprado, mes a mes.
	 *
	 * Sale de Contabilidad y no de los movimientos de la cuenta: lo que
	 * importa acá es lo que facturó, no el día en que el banco lo mostró.
	 * Los netos, sin IVA, que es lo único comparable entre meses.
	 *
	 * @param string $sentido 'venta' o 'compra'.
	 * @param int    $meses   Cuántos meses hacia atrás, contando el actual.
	 * @return array Y-m => neto.
	 */
	public static function por_mes( $sentido, $meses = 12 ) {
		$meses = max( 2, (int) $meses );
		$fuera = array();
		$hoy   = current_time( 'Y-m-01' );

		for ( $i = $meses - 1; $i >= 0; $i-- ) {
			$fuera[ gmdate( 'Y-m', strtotime( $hoy . ' -' . $i . ' month' ) ) ] = 0;
		}

		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return $fuera;
		}

		foreach ( Malka_CRM_Documents::all() as $d ) {
			// Las anuladas existen para dejar rastro, no para sumarse.
			if ( 'anulada' === $d['estado'] || empty( $d['fecha'] ) ) {
				continue;
			}

			if ( $sentido !== $d['sentido'] ) {
				continue;
			}

			$mes = substr( $d['fecha'], 0, 7 );

			if ( ! isset( $fuera[ $mes ] ) ) {
				continue;
			}

			// El signo viene del documento: una nota de crédito resta.
			$fuera[ $mes ] += (int) $d['signo'] * (int) $d['neto'];
		}

		return $fuera;
	}

	/**
	 * Qué le deja más plata, por ítem de cotización.
	 *
	 * Sólo las cotizaciones ganadas: una cotización que nadie aceptó dice lo
	 * que ella ofrece, no lo que vende. Y se suma el neto del ítem, que es su
	 * precio de verdad después del descuento.
	 *
	 * @param int $tope Cuántos devolver.
	 * @return array[] {titulo, neto, veces}
	 */
	public static function mas_vendido( $tope = 6 ) {
		if ( ! class_exists( 'Malka_CRM_Quote_Page' ) || ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return array();
		}

		$ganadas = array( 'aceptada', 'en_curso', 'entregado', 'finalizada' );
		$suma    = array();

		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $qid ) {
			$estado = (string) get_post_meta( $qid, '_malka_pm_quote_status', true );
			$firma  = get_post_meta( $qid, '_malka_pm_quote_sign', true );
			$firmo  = is_array( $firma ) && ! empty( $firma['rut'] );

			if ( ! $firmo && ! in_array( $estado, $ganadas, true ) ) {
				continue;
			}

			$items = get_post_meta( $qid, '_malka_pm_quote_items', true );
			$items = is_array( $items ) ? $items : array();

			foreach ( $items as $it ) {
				if ( ! is_array( $it ) ) {
					continue;
				}

				$titulo = trim( (string) ( isset( $it['titulo'] ) ? $it['titulo'] : '' ) );

				if ( '' === $titulo ) {
					continue;
				}

				$cant = isset( $it['cantidad'] ) ? (float) $it['cantidad'] : 1;
				$unit = isset( $it['unitario'] ) ? (float) $it['unitario'] : 0;
				$desc = isset( $it['descuento'] ) ? (float) $it['descuento'] : 0;
				$neto = (int) round( $cant * $unit * ( 1 - ( $desc / 100 ) ) );

				// Dos cotizaciones que escriben el mismo servicio con otra
				// mayúscula o un espacio de más son el mismo servicio.
				$llave = self::normalizar( $titulo );

				if ( ! isset( $suma[ $llave ] ) ) {
					$suma[ $llave ] = array(
						'titulo' => $titulo,
						'neto'   => 0,
						'veces'  => 0,
					);
				}

				$suma[ $llave ]['neto'] += $neto;
				$suma[ $llave ]['veces']++;
			}
		}

		uasort(
			$suma,
			function ( $a, $b ) {
				return $b['neto'] <=> $a['neto'];
			}
		);

		return array_slice( array_values( $suma ), 0, max( 1, (int) $tope ) );
	}

	/**
	 * Un título sin mayúsculas, tildes ni espacios de más.
	 *
	 * @param string $t Título.
	 * @return string
	 */
	private static function normalizar( $t ) {
		$t = function_exists( 'remove_accents' ) ? remove_accents( $t ) : $t;
		$t = strtolower( $t );
		$t = preg_replace( '/[^a-z0-9]+/', ' ', $t );

		return trim( (string) $t );
	}

	/* ─────────────────────────────  Los gráficos  ─────────────────────── */

	/**
	 * Ventas por mes.
	 *
	 * @return string
	 */
	public static function panel_ventas() {
		return self::panel_meses(
			'venta',
			__( 'Ventas por mes', 'malka-crm' ),
			__( 'Lo facturado sin IVA, según lo que anotaste en Documentos', 'malka-crm' ),
			__( 'Todavía no hay facturas ni boletas de venta anotadas. En cuanto anotes la primera, acá aparece el mes a mes.', 'malka-crm' )
		);
	}

	/**
	 * Compras por mes.
	 *
	 * @return string
	 */
	public static function panel_compras() {
		return self::panel_meses(
			'compra',
			__( 'Compras por mes', 'malka-crm' ),
			__( 'Lo que te facturan a ti, sin IVA', 'malka-crm' ),
			__( 'Todavía no hay compras anotadas. Cuando cargues una factura de proveedor, acá se ve en qué se te va la plata.', 'malka-crm' )
		);
	}

	/**
	 * Un panel de barras con selector de período.
	 *
	 * Se dibujan todas las vistas de una vez (12 meses, cada año, los años y
	 * los últimos 30 días) y el selector sólo muestra una: cambiar es
	 * instantáneo y no hay que volver a pedir nada al servidor.
	 *
	 * @param string $sentido Venta o compra.
	 * @param string $titulo  Título.
	 * @param string $sub     Bajada.
	 * @param string $vacio   Qué decir cuando no hay nada.
	 * @return string
	 */
	private static function panel_meses( $sentido, $titulo, $sub, $vacio ) {
		$hoy   = current_time( 'Y-m-d' );
		$anio  = (int) substr( $hoy, 0, 4 );
		$anios = self::anios_con_datos( $sentido );
		if ( ! in_array( $anio, $anios, true ) ) {
			$anios[] = $anio;
		}
		rsort( $anios );

		$vistas = array(
			'12m'   => array(
				'serie' => self::serie_docs( $sentido, gmdate( 'Y-m-01', strtotime( substr( $hoy, 0, 7 ) . '-01 -11 months' ) ), $hoy, 'mes' ),
				'gran'  => 'mes',
				'pie'   => __( 'en 12 meses', 'malka-crm' ),
			),
		);
		foreach ( $anios as $a ) {
			$vistas[ 'anio-' . $a ] = array(
				'serie' => self::serie_docs( $sentido, $a . '-01-01', $a . '-12-31', 'mes' ),
				'gran'  => 'mes',
				/* translators: año */
				'pie'   => sprintf( __( 'en %d', 'malka-crm' ), $a ),
			);
		}
		$min = $anios ? min( $anios ) : $anio;
		$vistas['anios'] = array(
			'serie' => self::serie_docs( $sentido, $min . '-01-01', $hoy, 'anio' ),
			'gran'  => 'anio',
			/* translators: año */
			'pie'   => sprintf( __( 'desde %d', 'malka-crm' ), $min ),
		);
		$vistas['dias'] = array(
			'serie' => self::serie_docs( $sentido, gmdate( 'Y-m-d', strtotime( $hoy . ' -29 days' ) ), $hoy, 'dia' ),
			'gran'  => 'dia',
			'pie'   => __( 'en 30 días', 'malka-crm' ),
		);

		ob_start();
		printf( '<section class="esc-panel esc-gp" data-graf="%s">', esc_attr( $sentido ) );
		echo Malka_CRM_Dashboard::head( 'grafico', $titulo, $sub ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// El selector: 12 meses · Año [select] · Años · Días.
		echo '<div class="esc-gp__sel" role="group" aria-label="' . esc_attr__( 'Período', 'malka-crm' ) . '">';
		printf( '<button type="button" class="esc-gp__b" data-gper="12m">%s</button>', esc_html__( '12 meses', 'malka-crm' ) );
		printf( '<label class="esc-gp__b esc-gp__anio is-on" data-gper="anio"><span>%s</span><select aria-label="%s">', esc_html__( 'Año', 'malka-crm' ), esc_attr__( 'Elige el año', 'malka-crm' ) );
		foreach ( $anios as $a ) {
			printf( '<option value="anio-%1$d">%1$d</option>', (int) $a );
		}
		echo '</select></label>';
		printf( '<button type="button" class="esc-gp__b" data-gper="anios">%s</button>', esc_html__( 'Años', 'malka-crm' ) );
		printf( '<button type="button" class="esc-gp__b" data-gper="dias">%s</button>', esc_html__( 'Días', 'malka-crm' ) );
		echo '</div>';

		foreach ( $vistas as $clave => $v ) {
			$total = array_sum( $v['serie'] );
			$con   = count( array_filter( $v['serie'] ) );
			// Por defecto, el año en curso.
			printf( '<div class="esc-gv" data-vista="%1$s"%2$s>', esc_attr( $clave ), 'anio-' . $anio === $clave ? '' : ' hidden' );
			printf(
				'<p class="esc-gv__t"><em>%1$s</em><span>%2$s</span></p>',
				esc_html( Malka_CRM_Settings::money( $total ) ),
				esc_html( $v['pie'] )
			);
			if ( $con < 1 ) {
				echo '<p class="esc-empty">' . esc_html( '12m' === $clave ? $vacio : __( 'Nada anotado en este período.', 'malka-crm' ) ) . '</p>';
			} else {
				echo self::barras( $v['serie'], $v['gran'], $titulo ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}

		echo '</section>';
		return ob_get_clean();
	}

	/**
	 * Los años que tienen documentos de un lado.
	 *
	 * @param string $sentido Venta o compra.
	 * @return int[]
	 */
	private static function anios_con_datos( $sentido ) {
		$out = array();
		foreach ( self::docs_de( $sentido ) as $x ) {
			$out[ (int) substr( $x[0], 0, 4 ) ] = 1;
		}
		return array_map( 'intval', array_keys( $out ) );
	}

	/**
	 * Fecha y neto con signo de cada documento de un lado, una vez por carga.
	 *
	 * Cuentan todos: facturas, exentas, boletas, honorarios y notas de
	 * crédito (que restan). Las anuladas no.
	 *
	 * @param string $sentido Venta o compra.
	 * @return array[] [fecha, neto]
	 */
	public static function docs_de( $sentido ) {
		static $cache = array();
		if ( isset( $cache[ $sentido ] ) ) {
			return $cache[ $sentido ];
		}
		$out = array();
		if ( class_exists( 'Malka_CRM_Documents' ) ) {
			foreach ( Malka_CRM_Documents::all() as $d ) {
				if ( 'anulada' === $d['estado'] || empty( $d['fecha'] ) || $sentido !== $d['sentido'] ) {
					continue;
				}
				$out[] = array( $d['fecha'], (int) $d['signo'] * (int) $d['neto'] );
			}
		}
		$cache[ $sentido ] = $out;
		return $out;
	}

	/**
	 * La suma por día, mes o año entre dos fechas, con los huecos en cero.
	 *
	 * @param string $sentido Venta o compra.
	 * @param string $desde   Y-m-d.
	 * @param string $hasta   Y-m-d.
	 * @param string $gran    dia|mes|anio.
	 * @return array clave => monto.
	 */
	public static function serie_docs( $sentido, $desde, $hasta, $gran ) {
		$out = array();
		$fmt = array(
			'dia'  => array( 'Y-m-d', '+1 day', 10 ),
			'mes'  => array( 'Y-m', '+1 month', 7 ),
			'anio' => array( 'Y', '+1 year', 4 ),
		);
		$f   = $fmt[ $gran ];
		$ini = 'mes' === $gran ? substr( $desde, 0, 7 ) . '-01' : ( 'anio' === $gran ? substr( $desde, 0, 4 ) . '-01-01' : $desde );
		$t   = strtotime( $ini );
		$fin = strtotime( $hasta );
		$n   = 0;
		while ( $t <= $fin && $n < 400 ) {
			$out[ gmdate( $f[0], $t ) ] = 0;
			$t = strtotime( $f[1], $t );
			$n++;
		}
		foreach ( self::docs_de( $sentido ) as $x ) {
			if ( $x[0] < $desde || $x[0] > $hasta ) {
				continue;
			}
			$k = substr( $x[0], 0, $f[2] );
			if ( isset( $out[ $k ] ) ) {
				$out[ $k ] += $x[1];
			}
		}
		return $out;
	}

	/**
	 * Lo que más vende.
	 *
	 * @return string
	 */
	public static function panel_mas_vendido() {
		$filas = self::mas_vendido( 6 );

		ob_start();
		echo '<section class="esc-panel">';
		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'grafico',
			__( 'Lo que más vendes', 'malka-crm' ),
			__( 'Por ítem de las cotizaciones que ganaste, neto', 'malka-crm' ),
			$filas ? (string) count( $filas ) : '',
			$filas ? __( 'servicios', 'malka-crm' ) : ''
		);

		if ( empty( $filas ) ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no hay cotizaciones ganadas con ítems adentro. En cuanto aceptes la primera, acá se ve qué servicio te deja más.', 'malka-crm' ) . '</p>';
			echo '</section>';
			return ob_get_clean();
		}

		$tope = 0;

		foreach ( $filas as $f ) {
			$tope = max( $tope, (int) $f['neto'] );
		}

		echo '<div class="esc-gf">';

		foreach ( $filas as $f ) {
			$pct = $tope > 0 ? max( 1, (int) round( ( $f['neto'] / $tope ) * 100 ) ) : 0;

			printf(
				// El monto va en la misma fila que el nombre y la barra debajo:
				// puesto después, la rejilla lo mandaba a una tercera línea y
				// la cifra quedaba despegada de su nombre.
				'<div class="esc-gf__r"><span class="esc-gf__n">%1$s<i>%2$s</i></span>' .
				'<span class="esc-gf__v">%3$s</span>' .
				'<span class="esc-gf__b"><i style="width:%4$d%%"></i></span></div>',
				esc_html( $f['titulo'] ),
				esc_html(
					sprintf(
						/* translators: cuántas veces se vendió */
						_n( '%d vez', '%d veces', (int) $f['veces'], 'malka-crm' ),
						(int) $f['veces']
					)
				),
				esc_html( Malka_CRM_Settings::money( (int) $f['neto'] ) ),
				$pct
			);
		}

		echo '</div>';
		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Las barras de una serie, en SVG.
	 *
	 * Sin librerías y sin JavaScript: es un dibujo. Por mes, cada columna
	 * lleva su mes (tres letras); por día, uno de cada cinco; por año, todos.
	 *
	 * @param array  $serie  clave => monto.
	 * @param string $gran   dia|mes|anio.
	 * @param string $titulo Para quien no ve el dibujo.
	 * @return string
	 */
	public static function barras( $serie, $gran, $titulo ) {
		$n = count( $serie );
		if ( ! $n ) {
			return '';
		}

		$tope = 1;
		foreach ( $serie as $v ) {
			$tope = max( $tope, abs( (int) $v ) );
		}

		$w     = 320;
		$h     = 96;
		$hueco = $n > 20 ? 2 : 4;
		$ancho = ( $w - ( $hueco * ( $n - 1 ) ) ) / $n;
		$radio = min( 3, $ancho / 2 );
		$claves = array_keys( $serie );
		$ultimo = end( $claves );

		$barras = '';
		$ejes   = '';
		$i      = 0;

		foreach ( $serie as $k => $v ) {
			$x   = $i * ( $ancho + $hueco );
			$alt = $v ? max( 2, ( abs( (int) $v ) / $tope ) * $h ) : 1;
			$barras .= sprintf(
				'<rect x="%1$s" y="%2$s" width="%3$s" height="%4$s" rx="%5$s" class="esc-gm__b%6$s"><title>%7$s</title></rect>',
				esc_attr( round( $x, 2 ) ),
				esc_attr( round( $h - $alt, 2 ) ),
				esc_attr( round( $ancho, 2 ) ),
				esc_attr( round( $alt, 2 ) ),
				esc_attr( round( $radio, 2 ) ),
				( $k === $ultimo ? ' is-hoy' : '' ) . ( $v ? '' : ' is-cero' ),
				esc_html( self::rotulo_clave( $k, $gran, true ) . ': ' . Malka_CRM_Settings::money( (int) $v ) )
			);

			$poner = 'dia' !== $gran || 0 === $i % 5 || $n - 1 === $i;
			if ( $poner ) {
				$ejes .= sprintf(
					'<span class="esc-gm__e" style="left:%1$s%%">%2$s</span>',
					esc_attr( round( ( ( $x + ( $ancho / 2 ) ) / $w ) * 100, 2 ) ),
					esc_html( self::rotulo_clave( $k, $gran ) )
				);
			}
			$i++;
		}

		$out = sprintf(
			'<div class="esc-gm esc-gm--%6$s"><svg class="esc-gm__s" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" role="img" aria-label="%3$s">%4$s</svg><div class="esc-gm__ejes">%5$s</div></div>',
			$w,
			$h,
			esc_attr( $titulo ),
			$barras, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$ejes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( $gran )
		);

		// Debajo, los tres últimos escritos.
		$out .= '<dl class="esc-gm__d">';
		foreach ( array_slice( $serie, -3, 3, true ) as $k => $v ) {
			$out .= sprintf(
				'<div><dt>%1$s</dt><dd>%2$s</dd></div>',
				esc_html( self::rotulo_clave( $k, $gran, true ) ),
				esc_html( Malka_CRM_Settings::money( (int) $v ) )
			);
		}
		return $out . '</dl>';
	}

	/**
	 * El rótulo de una columna.
	 *
	 * @param string $k     Y-m-d, Y-m o Y.
	 * @param string $gran  dia|mes|anio.
	 * @param bool   $largo Para el texto de abajo y la ayuda.
	 * @return string
	 */
	public static function rotulo_clave( $k, $gran, $largo = false ) {
		if ( 'anio' === $gran ) {
			return (string) $k;
		}
		if ( 'dia' === $gran ) {
			$t = strtotime( $k );
			return $largo ? date_i18n( 'j M Y', $t ) : date_i18n( 'j', $t );
		}
		$t = strtotime( $k . '-01' );
		if ( $largo ) {
			return ucfirst( date_i18n( 'F Y', $t ) );
		}
		$m = function_exists( 'mb_substr' ) ? mb_substr( date_i18n( 'F', $t ), 0, 3, 'UTF-8' ) : substr( date_i18n( 'F', $t ), 0, 3 );
		return function_exists( 'mb_convert_case' ) ? mb_convert_case( $m, MB_CASE_TITLE, 'UTF-8' ) : ucfirst( $m );
	}

	/**
	 * «2026-10» como «oct» o como «octubre 2026».
	 *
	 * @param string $mes   Y-m.
	 * @param bool   $largo Con el nombre entero y el año.
	 * @return string
	 */
	public static function mes_corto( $mes, $largo = false ) {
		$t = strtotime( $mes . '-01' );

		if ( ! $t ) {
			return (string) $mes;
		}

		return $largo ? date_i18n( 'F Y', $t ) : date_i18n( 'M', $t );
	}
}
