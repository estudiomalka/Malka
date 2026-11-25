<?php
/**
 * Cargar tus cuentas de una vez.
 *
 * Las cifras de abajo salen de las capturas de tus apps del 21 de noviembre de
 * 2026. Están escritas acá sólo para que no tengas que tipear veinte
 * formularios: la pantalla te las muestra editables y no crea nada hasta que
 * tú confirmes. Si un saldo cambió, lo corriges antes de apretar.
 *
 * Dos cosas que a propósito NO están acá:
 *
 * 1. Tu RUT y los números completos de cuenta. Guardo sólo los últimos cuatro
 *    dígitos, que es lo que se necesita para distinguir una cuenta de otra. El
 *    resto no tiene por qué vivir dentro de un plugin.
 * 2. Los euros de Global66. El plugin trabaja en pesos; convertirlos con un
 *    tipo de cambio inventado daría un número falso.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Cargar {

	/** Para avisar si ya cargaste, y no duplicar. */
	const OPT_HECHO = 'malka_crm_cargar_hecho';

	/** De cuándo son las cifras de abajo. */
	const FECHA = '2026-11-21';

	public function __construct() {
		add_action( 'admin_post_malka_crm_cargar', array( __CLASS__, 'handle_cargar' ) );
	}

	/**
	 * Las cuentas, tal como se ven en tus apps.
	 *
	 * @return array[]
	 */
	public static function borrador() {
		return array(

			/* ── Del estudio ── */
			array(
				'grupo'    => __( 'Del estudio', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Chequera Vox Populi',
				'numero'   => '4984',
				'tipo'     => 'corriente',
				'personal' => 0,
				'saldo'    => 103455,
				'nota'     => __( 'Vox Populi Publicidad Limitada. Queda como la cuenta principal del estudio.', 'malka-crm' ),
			),

			/* ── Tuyas, del día a día ── */
			array(
				'grupo'    => __( 'Tuyas, del día a día', 'malka-crm' ),
				'banco'    => 'Banco Falabella',
				'alias'    => 'Cuenta corriente Falabella',
				'numero'   => '6928',
				'tipo'     => 'corriente',
				'personal' => 1,
				'saldo'    => 320329,
			),
			array(
				'grupo'    => __( 'Tuyas, del día a día', 'malka-crm' ),
				'banco'    => 'Banco de Chile',
				'alias'    => 'CTA FAN débito',
				'numero'   => '1088',
				'tipo'     => 'corriente',
				'personal' => 1,
				'saldo'    => 61404,
			),
			array(
				'grupo'    => __( 'Tuyas, del día a día', 'malka-crm' ),
				'banco'    => 'MACH',
				'alias'    => 'MACH',
				/*
				 * Vacío a propósito. MACH identifica la cuenta con el RUT,
				 * y los últimos cuatro dígitos de un RUT no son los últimos
				 * cuatro de una cuenta: es un identificador de la persona y
				 * no tiene por qué vivir en el código del plugin. Si hace
				 * falta distinguirla, se escribe desde la pantalla.
				 */
				'numero'   => '',
				'tipo'     => 'prepago',
				'personal' => 1,
				'saldo'    => 3318,
				'nota'     => __( 'Cuenta corriente BCI. De acá salen los servicios que no acepta la cuenta del estudio, como Claude.', 'malka-crm' ),
			),
			array(
				'grupo'    => __( 'Tuyas, del día a día', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'CuentaRUT',
				'numero'   => '6546',
				'tipo'     => 'vista',
				'personal' => 1,
				'saldo'    => 0,
			),
			array(
				'grupo'    => __( 'Tuyas, del día a día', 'malka-crm' ),
				'banco'    => 'Mercado Pago',
				'alias'    => 'Mercado Pago',
				'numero'   => '1422',
				'tipo'     => 'vista',
				'personal' => 1,
				'saldo'    => 0,
			),
			array(
				'grupo'    => __( 'Tuyas, del día a día', 'malka-crm' ),
				'banco'    => 'Global66',
				'alias'    => 'Global66 pesos',
				'numero'   => '9759',
				'tipo'     => 'vista',
				'personal' => 1,
				'saldo'    => 732,
				'nota'     => __( 'Sólo los pesos. Los €1,20 quedan fuera: el plugin trabaja en pesos y no voy a inventar un tipo de cambio.', 'malka-crm' ),
			),

			/* ── Tus ahorros ── */
			array(
				'grupo'    => __( 'Tus ahorros', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Plazo Vivienda',
				'numero'   => '4561',
				'tipo'     => 'ahorro',
				'personal' => 1,
				'saldo'    => 5000,
			),
			array(
				'grupo'    => __( 'Tus ahorros', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Arriendo',
				'numero'   => '4736',
				'tipo'     => 'ahorro',
				'personal' => 1,
				'saldo'    => 416,
			),
			array(
				'grupo'    => __( 'Tus ahorros', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Vacaciones',
				'numero'   => '5619',
				'tipo'     => 'ahorro',
				'personal' => 1,
				'saldo'    => 190,
			),
			array(
				'grupo'    => __( 'Tus ahorros', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Libertad financiera',
				'numero'   => '6166',
				'tipo'     => 'ahorro',
				'personal' => 1,
				'saldo'    => 13,
			),
			array(
				'grupo'    => __( 'Tus ahorros', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Autoinversión',
				'numero'   => '7724',
				'tipo'     => 'ahorro',
				'personal' => 1,
				'saldo'    => 12,
			),
			array(
				'grupo'    => __( 'Tus ahorros', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Benja',
				'numero'   => '6715',
				'tipo'     => 'ahorro',
				'personal' => 1,
				'saldo'    => 6,
			),
			array(
				'grupo'    => __( 'Tus ahorros', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Formación',
				'numero'   => '5620',
				'tipo'     => 'ahorro',
				'personal' => 1,
				'saldo'    => 0,
			),
			array(
				'grupo'    => __( 'Tus ahorros', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Diversión',
				'numero'   => '5621',
				'tipo'     => 'ahorro',
				'personal' => 1,
				'saldo'    => 0,
			),
			array(
				'grupo'    => __( 'Tus ahorros', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'A largo plazo',
				'numero'   => '3052',
				'tipo'     => 'ahorro',
				'personal' => 1,
				'saldo'    => 0,
			),
			array(
				'grupo'    => __( 'Tus ahorros', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Ropa',
				'numero'   => '2869',
				'tipo'     => 'ahorro',
				'personal' => 1,
				'saldo'    => 0,
			),
			array(
				/*
				 * El banco la lista bajo «Tus ahorros», pero no es un ahorro:
				 * es una cuenta vista por la que entra y sale plata. Como
				 * vista queda junto a las del día a día, que es donde sirve.
				 */
				'grupo'    => __( 'Tuyas, del día a día', 'malka-crm' ),
				'banco'    => 'BancoEstado',
				'alias'    => 'Pensión alimenticia',
				'numero'   => '8930',
				'tipo'     => 'vista',
				'personal' => 1,
				'saldo'    => 0,
			),

			/*
			 * ── Tus tarjetas de crédito ──
			 * Acá el saldo es la deuda, no la plata. Por eso van con lo que
			 * debes, no con el cupo disponible.
			 */
			array(
				'grupo'    => __( 'Tus tarjetas de crédito', 'malka-crm' ),
				'banco'    => 'MACH Bank',
				'alias'    => 'Tarjeta MACH',
				'numero'   => '4778',
				'tipo'     => 'credito',
				'personal' => 1,
				'saldo'    => 306534,
				'cupo'     => 300000,
				'minimo'   => 60800,
				'dia_pago' => 7,
				'nota'     => __( 'Estás $6.534 por sobre el cupo de $300.000. Pasado el cupo se cobran cargos y la deuda sube sola.', 'malka-crm' ),
			),
			array(
				'grupo'    => __( 'Tus tarjetas de crédito', 'malka-crm' ),
				'banco'    => 'Banco Falabella',
				'alias'    => 'CMR Falabella',
				'numero'   => '0285',
				'tipo'     => 'credito',
				'personal' => 1,
				'saldo'    => 118060,
				'cupo'     => 150000,
				'nota'     => __( 'Falta el pago mínimo, la tasa y el día de vencimiento: los copias del estado de cuenta en Deudas.', 'malka-crm' ),
			),
			array(
				'grupo'    => __( 'Tus tarjetas de crédito', 'malka-crm' ),
				'banco'    => 'Banco de Chile',
				'alias'    => 'FAN Visa crédito',
				'numero'   => '3050',
				'tipo'     => 'credito',
				'personal' => 1,
				'saldo'    => 365003,
				'cupo'     => 400000,
				'nota'     => __( 'Es la más grande de las tres y tienes el cupo casi lleno: usas $365.003 de $400.000. Falta el pago mínimo, la tasa y el día de vencimiento.', 'malka-crm' ),
			),
		);
	}

	/**
	 * Las deudas que no son una tarjeta.
	 *
	 * @return array[]
	 */
	public static function deudas_borrador() {
		return array(
			array(
				'nombre'         => 'Crédito de consumo Falabella',
				'tipo'           => 'consumo',
				'cuotas_total'   => 12,
				'cuotas_pagadas' => 0,
				'cuota'          => 187627,
				'dia_pago'       => 21,
				'tasa'           => 3.42,
				'nota'           => __( 'Recibiste $1.800.000 y vas a devolver $2.251.817 en 12 cuotas de $187.627: $451.817 son interés e impuestos. Tasa 3,42% al mes, CAE 43,49%. Primera cuota el 21 de diciembre de 2026.', 'malka-crm' ),
			),
		);
	}

	/**
	 * ¿Ya se cargó alguna vez?
	 *
	 * @return string Fecha, o vacío.
	 */
	public static function hecho() {
		return (string) get_option( self::OPT_HECHO, '' );
	}

	/**
	 * ¿Esta cuenta del borrador ya existe?
	 *
	 * El plugin siempre arranca con una cuenta creada, y encima ya tienes
	 * varias puestas a mano. Sin esto, cargar el borrador te dejaba el
	 * BancoEstado, la MACH y la Visa repetidos, y el saldo sumado dos veces.
	 *
	 * Calza primero por los últimos cuatro dígitos, que es lo único que
	 * identifica de verdad; si no hay número guardado, por banco y tipo.
	 *
	 * @param array $m        Fila del borrador.
	 * @param array $tomadas  Ids ya usados por otra fila.
	 * @return int Id de la cuenta existente, o 0.
	 */
	public static function calce( $m, $tomadas = array() ) {
		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return 0;
		}

		$cuatro = substr( preg_replace( '/\D/', '', (string) $m['numero'] ), -4 );
		$banco  = self::plano( $m['banco'] );
		$suelta = 0;

		foreach ( Malka_CRM_Finance::cuentas() as $id => $c ) {
			$id = (int) $id;

			if ( in_array( $id, $tomadas, true ) ) {
				continue;
			}

			// Una tarjeta de crédito nunca es la misma cosa que una cuenta.
			$es_credito  = ( 'credito' === $m['tipo'] );
			$era_credito = ( isset( $c['tipo'] ) && 'credito' === $c['tipo'] );

			if ( $es_credito !== $era_credito ) {
				continue;
			}

			$suyos = substr( preg_replace( '/\D/', '', (string) $c['numero'] ), -4 );

			if ( $cuatro && $suyos && $cuatro === $suyos ) {
				return $id;
			}

			/*
			 * Sin número que comparar, el banco y el tipo son lo que queda.
			 * Se compara por contención y no por igualdad porque los nombres
			 * nunca calzan exactos: la cuenta que ya tienes dice «Mach» y el
			 * borrador «MACH Bank», y la de fábrica dice sólo «Banco».
			 */
			$suyo = self::plano( (string) $c['banco'] );

			if ( ! $suyos && ! $suelta && $suyo && ( false !== strpos( $banco, $suyo ) || false !== strpos( $suyo, $banco ) )
				&& ( ! isset( $c['tipo'] ) || $c['tipo'] === $m['tipo'] ) ) {
				$suelta = $id;
			}
		}

		return $suelta;
	}

	/**
	 * Un nombre de banco comparable: sin tildes, sin mayúsculas, sin espacios.
	 *
	 * @param string $s Texto.
	 * @return string
	 */
	private static function plano( $s ) {
		$s = function_exists( 'remove_accents' ) ? remove_accents( (string) $s ) : (string) $s;
		return preg_replace( '/[^a-z0-9]/', '', strtolower( $s ) );
	}

	/**
	 * Crea —o actualiza, si ya existe— una de las cuentas del borrador.
	 *
	 * @param int         $i       Cuál.
	 * @param int|null    $saldo   Saldo corregido, o null para el del borrador.
	 * @param string|null $alias   Nombre corregido, o null para el del borrador.
	 * @param array       $tomadas Ids que ya usó otra fila de esta misma carga.
	 * @return int Id de la cuenta, o 0.
	 */
	public static function crear_fila( $i, $saldo = null, $alias = null, $tomadas = array() ) {
		$modelo = self::borrador();
		$i      = (int) $i;

		if ( ! isset( $modelo[ $i ] ) || ! class_exists( 'Malka_CRM_Finance' ) ) {
			return 0;
		}

		$m     = $modelo[ $i ];
		$viejo = self::calce( $m, $tomadas );

		$datos = array(
			'banco'     => $m['banco'],
			'alias'     => null === $alias ? $m['alias'] : $alias,
			'numero'    => $m['numero'],
			'tipo'      => $m['tipo'],
			'personal'  => $m['personal'],
			'saldo'     => null === $saldo ? (int) $m['saldo'] : (int) $saldo,
			'principal' => empty( $m['personal'] ),
		);

		if ( $viejo ) {
			$datos['id'] = $viejo;
		}

		$id = Malka_CRM_Finance::guardar_cuenta( $datos );

		// Lo que ya sabemos de la tarjeta se guarda junto con ella.
		if ( 'credito' === $m['tipo'] && $id && class_exists( 'Malka_CRM_Deudas' ) ) {
			Malka_CRM_Deudas::guardar_tarjeta(
				array(
					'cuenta'   => $id,
					'cupo'     => isset( $m['cupo'] ) ? (int) $m['cupo'] : 0,
					'minimo'   => isset( $m['minimo'] ) ? (int) $m['minimo'] : 0,
					'dia_pago' => isset( $m['dia_pago'] ) ? (int) $m['dia_pago'] : 0,
				)
			);
		}

		return (int) $id;
	}

	/* ─────────────────────────────  Handler  ─────────────────────────────── */

	public static function handle_cargar() {
		check_admin_referer( 'malka_crm_cargar' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$filas  = isset( $_POST['cuenta'] ) && is_array( $_POST['cuenta'] ) ? wp_unslash( $_POST['cuenta'] ) : array();
		$modelo  = self::borrador();
		$tomadas = array();
		$n       = 0;

		foreach ( $filas as $i => $f ) {
			$i = (int) $i;

			if ( empty( $f['crear'] ) || ! isset( $modelo[ $i ] ) ) {
				continue;
			}

			$saldo = isset( $f['saldo'] ) ? (int) preg_replace( '/[^\d]/', '', (string) $f['saldo'] ) : null;
			$alias = isset( $f['alias'] ) ? sanitize_text_field( (string) $f['alias'] ) : null;
			$id    = self::crear_fila( $i, $saldo, $alias, $tomadas );

			if ( $id ) {
				$tomadas[] = $id;
				$n++;
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$dfilas  = isset( $_POST['deuda'] ) && is_array( $_POST['deuda'] ) ? wp_unslash( $_POST['deuda'] ) : array();
		$dmodelo = self::deudas_borrador();

		foreach ( $dfilas as $i => $f ) {
			$i = (int) $i;

			if ( empty( $f['crear'] ) || ! isset( $dmodelo[ $i ] ) ) {
				continue;
			}

			$m = $dmodelo[ $i ];

			Malka_CRM_Deudas::guardar(
				array(
					'nombre'         => $m['nombre'],
					'tipo'           => $m['tipo'],
					'cuotas_total'   => (int) $m['cuotas_total'],
					'cuotas_pagadas' => (int) $m['cuotas_pagadas'],
					'cuota'          => isset( $f['cuota'] ) ? (int) preg_replace( '/[^\d]/', '', (string) $f['cuota'] ) : (int) $m['cuota'],
					'dia_pago'       => isset( $f['dia_pago'] ) ? (int) $f['dia_pago'] : (int) $m['dia_pago'],
					'tasa'           => isset( $m['tasa'] ) ? (float) $m['tasa'] : 0,
				)
			);

			$n++;
		}

		if ( $n ) {
			update_option( self::OPT_HECHO, current_time( 'Y-m-d H:i' ), false );
		}

		wp_safe_redirect( add_query_arg( 'cargadas', $n, Malka_CRM_Dashboard::view_url( 'personal' ) ) );
		exit;
	}

	/* ─────────────────────────────  Pantalla  ────────────────────────────── */

	/**
	 * La pantalla de carga.
	 *
	 * @return string
	 */
	public static function render() {
		$modelo = self::borrador();
		$deudas = self::deudas_borrador();
		$hecho  = self::hecho();
		$cuanto = class_exists( 'Malka_CRM_Finance' ) ? count( Malka_CRM_Finance::cuentas() ) : 0;

		ob_start();
		echo '<div class="esc-vista esc-cargar">';
		echo '<section class="esc-panel">';
		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'flechas',
			__( 'Cargar tus cuentas', 'malka-crm' ),
			__( 'Escritas desde tus capturas. Revisa, corrige y confirma.', 'malka-crm' )
		);

		printf(
			'<p class="esc-aviso esc-aviso--full">%s</p>',
			esc_html(
				sprintf(
					/* translators: fecha */
					__( 'Los saldos son los que mostraban tus apps el %s. Si alguno cambió, córrigelo antes de crear: lo que escribas acá queda como el saldo del que parte el plugin. No se guarda tu RUT ni el número completo de ninguna cuenta, sólo los últimos cuatro dígitos.', 'malka-crm' ),
					date_i18n( 'j \d\e F \d\e Y', strtotime( self::FECHA ) )
				)
			)
		);

		if ( $hecho ) {
			printf(
				'<p class="esc-deudas__alerta"><b>%1$s</b> %2$s</p>',
				esc_html__( 'Ya cargaste esto.', 'malka-crm' ),
				esc_html(
					sprintf(
						/* translators: 1: fecha, 2: cuántas cuentas hay */
						__( 'Fue el %1$s y hoy tienes %2$d cuentas. Si vuelves a crear las mismas te van a quedar duplicadas: desmarca las que ya existen.', 'malka-crm' ),
						$hecho,
						$cuanto
					)
				)
			);
		}

		echo '</section>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo wp_nonce_field( 'malka_crm_cargar', '_wpnonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<input type="hidden" name="action" value="malka_crm_cargar" />';

		$grupo_actual = '';
		$tomadas      = array();
		$total        = 0;
		$deuda        = 0;

		foreach ( $modelo as $i => $m ) {
			if ( $m['grupo'] !== $grupo_actual ) {
				if ( '' !== $grupo_actual ) {
					echo '</section>';
				}
				$grupo_actual = $m['grupo'];
				echo '<section class="esc-panel">';
				printf( '<h2 class="esc-cargar__g">%s</h2>', esc_html( $grupo_actual ) );
			}

			if ( 'credito' === $m['tipo'] ) {
				$deuda += (int) $m['saldo'];
			} else {
				$total += (int) $m['saldo'];
			}

			$viejo     = self::calce( $m, $tomadas );
			if ( $viejo ) {
				$tomadas[] = $viejo;
			}

			printf(
				'<div class="esc-cargar__f"><label class="esc-cargar__ok">' .
				'<input type="checkbox" name="cuenta[%1$d][crear]" value="1"%2$s />' .
				'<span class="esc-cargar__n"><b>%3$s</b><em>%4$s</em></span></label>' .
				'<label class="esc-cargar__c"><span>%5$s</span>' .
				'<input type="text" name="cuenta[%1$d][alias]" value="%6$s" /></label>' .
				'<label class="esc-cargar__c"><span>%7$s</span>' .
				'<input type="text" name="cuenta[%1$d][saldo]" inputmode="numeric" value="%8$s" /></label></div>',
				(int) $i,
				' checked',
				esc_html( $m['banco'] . ' ··' . $m['numero'] ),
				esc_html( self::rotulo( $m, $viejo ) ),
				esc_html__( 'Cómo la llamas', 'malka-crm' ),
				esc_attr( $m['alias'] ),
				esc_html( 'credito' === $m['tipo'] ? __( 'Cuánto debes', 'malka-crm' ) : __( 'Saldo', 'malka-crm' ) ),
				esc_attr( $m['saldo'] ? number_format( $m['saldo'], 0, ',', '.' ) : '0' )
			);

			if ( ! empty( $m['nota'] ) ) {
				printf( '<p class="esc-hint esc-cargar__h">%s</p>', esc_html( $m['nota'] ) );
			}
		}

		if ( '' !== $grupo_actual ) {
			echo '</section>';
		}

		echo '<section class="esc-panel">';
		printf( '<h2 class="esc-cargar__g">%s</h2>', esc_html__( 'Tus créditos', 'malka-crm' ) );

		foreach ( $deudas as $i => $d ) {
			printf(
				'<div class="esc-cargar__f"><label class="esc-cargar__ok">' .
				'<input type="checkbox" name="deuda[%1$d][crear]" value="1" checked />' .
				'<span class="esc-cargar__n"><b>%2$s</b><em>%3$s</em></span></label>' .
				'<label class="esc-cargar__c"><span>%4$s</span>' .
				'<input type="text" name="deuda[%1$d][cuota]" inputmode="numeric" value="%6$s" /></label>' .
				'<label class="esc-cargar__c"><span>%5$s</span>' .
				'<input type="number" name="deuda[%1$d][dia_pago]" min="1" max="31" value="%7$s" /></label></div>',
				(int) $i,
				esc_html( $d['nombre'] ),
				esc_html(
					sprintf(
						/* translators: 1: pagadas, 2: total */
						__( 'Crédito de consumo · %1$d de %2$d cuotas pagadas', 'malka-crm' ),
						(int) $d['cuotas_pagadas'],
						(int) $d['cuotas_total']
					)
				),
				esc_html__( 'Cuota mensual', 'malka-crm' ),
				esc_html__( 'Día de pago', 'malka-crm' ),
				esc_attr( $d['cuota'] ? number_format( (int) $d['cuota'], 0, ',', '.' ) : '' ),
				esc_attr( $d['dia_pago'] ? (int) $d['dia_pago'] : '' )
			);

			if ( ! empty( $d['nota'] ) ) {
				printf( '<p class="esc-hint esc-cargar__h">%s</p>', esc_html( $d['nota'] ) );
			}
		}

		echo '</section>';

		echo '<section class="esc-panel">';
		printf(
			'<div class="esc-deudas__r"><span>%1$s <b>%2$s</b></span><span>%3$s <b>%4$s</b></span></div>',
			esc_html__( 'Suma de tus saldos', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $total ) ),
			esc_html__( 'Deuda en tarjetas', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $deuda ) )
		);
		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( 'Sin contar el crédito de consumo: ahí vas a devolver $2.251.817 en 12 cuotas de $187.627.', 'malka-crm' )
		);
		printf(
			'<button type="submit" class="esc-btn esc-btn--primary">%s</button>',
			esc_html__( 'Crear las cuentas marcadas', 'malka-crm' )
		);
		echo '</section></form></div>';

		return ob_get_clean();
	}

	/**
	 * Cómo se describe una cuenta en la lista.
	 *
	 * @param array $m Fila.
	 * @return string
	 */
	private static function rotulo( $m, $viejo = 0 ) {
		$tipos = class_exists( 'Malka_CRM_Finance' ) ? Malka_CRM_Finance::tipos_cuenta() : array();
		$t     = isset( $tipos[ $m['tipo'] ] ) ? $tipos[ $m['tipo'] ] : $m['tipo'];
		$quien = empty( $m['personal'] ) ? __( 'del estudio', 'malka-crm' ) : __( 'tuya', 'malka-crm' );

		if ( $viejo ) {
			return $t . ' · ' . $quien . ' · ' . sprintf(
				/* translators: nombre de la cuenta que ya existe */
				__( 'ya existe como «%s»: se le actualiza el saldo', 'malka-crm' ),
				Malka_CRM_Finance::nombre_cuenta( (int) $viejo )
			);
		}

		return $t . ' · ' . $quien . ' · ' . __( 'se crea nueva', 'malka-crm' );
	}
}
