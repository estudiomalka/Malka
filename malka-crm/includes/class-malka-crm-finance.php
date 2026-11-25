<?php
/**
 * Finanzas: saldo de la cuenta y movimientos.
 *
 * El saldo lo ingresa la usuaria y el sistema lo ajusta con cada movimiento.
 * Los pagos de cotizaciones entran solos; el resto se registra a mano con una
 * categoría, y los marcados como costo fijo alimentan el cálculo de la tarifa.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Finance {

	const TABLE         = 'malka_crm_movimientos';
	const OPT_BALANCE   = 'malka_crm_saldo';
	const OPT_BAL_DATE  = 'malka_crm_saldo_fecha';
	const DB_VERSION    = '1.5';
	const OPT_CUENTAS   = 'malka_crm_cuentas';
	const OPT_DB        = 'malka_crm_finance_db';

	/**
	 * Categorías válidas. La clave se guarda, la etiqueta se muestra.
	 */
	/** Qué plata estás mirando: la del estudio, la tuya, o las dos. */
	const OPT_AMBITO = 'malka_crm_ambito';

	/**
	 * El ámbito elegido.
	 *
	 * @return string todo|estudio|personal.
	 */
	public static function ambito() {
		$a = (string) get_option( self::OPT_AMBITO, 'todo' );
		return in_array( $a, array( 'todo', 'estudio', 'personal' ), true ) ? $a : 'todo';
	}

	/**
	 * Lo deja fijo hasta que lo cambies.
	 *
	 * @param string $a todo|estudio|personal.
	 * @return string El que quedó.
	 */
	public static function fijar_ambito( $a ) {
		$a = in_array( $a, array( 'todo', 'estudio', 'personal' ), true ) ? $a : 'todo';
		update_option( self::OPT_AMBITO, $a, false );
		return $a;
	}

	/**
	 * ¿Esta cuenta entra en el ámbito que estás mirando?
	 *
	 * @param int    $cuenta Id.
	 * @param string $ambito Ámbito, o vacío para el elegido.
	 * @return bool
	 */
	public static function en_ambito( $cuenta, $ambito = '' ) {
		$ambito = $ambito ? $ambito : self::ambito();

		if ( 'todo' === $ambito ) {
			return true;
		}

		$mia = self::es_personal( (int) $cuenta );

		return 'personal' === $ambito ? $mia : ! $mia;
	}

	public static function categories() {
		return array(
			'costo_fijo'     => __( 'Costo fijo', 'malka-crm' ),
			'costo_variable' => __( 'Costo variable', 'malka-crm' ),
			'impuesto'       => __( 'Impuesto', 'malka-crm' ),
			'retiro'         => __( 'Retiro personal', 'malka-crm' ),
			/*
			 * Un gasto tuyo, no del estudio: Netflix, el gimnasio. Pasa por
			 * tus cuentas y no es un costo de la empresa, así que no entra
			 * a la tarifa ni a lo que el estudio te debe.
			 */
			'gasto_personal' => __( 'Gasto tuyo', 'malka-crm' ),
			// Lo que el estudio te devuelve por algo que pagaste tú.
			'reembolso'      => __( 'Devolución a tu bolsillo', 'malka-crm' ),
			'ingreso'        => __( 'Ingreso de cliente', 'malka-crm' ),
			// Plata que pasa de una cuenta de la empresa a otra: no es gasto ni ingreso.
			'traspaso'       => __( 'Traspaso entre tus cuentas', 'malka-crm' ),
			// Prestarle a alguien no es un gasto: esa plata sigue siendo tuya.
			'prestamo'       => __( 'Préstamo a alguien', 'malka-crm' ),
			'sin_categoria'  => __( 'Sin categoría', 'malka-crm' ),
		);
	}

	/**
	 * Varios movimientos de una vez: marcarlos, borrarlos o copiar uno.
	 *
	 * El saldo se corrige movimiento por movimiento, porque uno pendiente
	 * nunca lo tocó: marcarlo completado es cuando recién se mueve.
	 */
	public static function handle_lote() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}

		check_ajax_referer( 'malka_crm_finance', 'nonce' );

		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$que  = isset( $_POST['que'] ) ? sanitize_key( wp_unslash( $_POST['que'] ) ) : '';
		$ids  = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$ids = array_filter( array_map( 'intval', explode( ',', $ids ) ) );

		if ( ! $ids || ! in_array( $que, array( 'hecho', 'pendiente', 'borrar', 'copiar' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Falta qué hacer o sobre cuáles.', 'malka-crm' ) ), 400 );
		}

		$hechos = 0;

		foreach ( $ids as $id ) {
			$m = self::get( $id );

			if ( ! $m ) {
				continue;
			}

			$signo = ( 'entrada' === $m->tipo ? 1 : -1 ) * (int) $m->monto;
			$pend  = isset( $m->estado ) && 'pendiente' === $m->estado;

			if ( 'borrar' === $que ) {
				if ( self::borrar( $id ) ) {
					$hechos++;
				}
				continue;
			}

			if ( 'copiar' === $que ) {
				$copia = self::add(
					array(
						'descripcion' => $m->descripcion,
						'referencia'  => isset( $m->referencia ) ? $m->referencia : '',
						'monto'       => (int) $m->monto,
						'tipo'        => $m->tipo,
						'categoria'   => $m->categoria,
						'fecha'       => current_time( 'Y-m-d' ),
						'cuenta'      => isset( $m->cuenta ) ? (int) $m->cuenta : 0,
						'recurrente'  => (int) $m->recurrente,
						'estado'      => $pend ? 'pendiente' : 'hecho',
						'moneda'      => isset( $m->moneda ) ? $m->moneda : 'CLP',
						'original'    => isset( $m->original ) ? (float) $m->original : 0,
					)
				);

				if ( ! is_wp_error( $copia ) ) {
					$hechos++;
				}
				continue;
			}

			// Marcar: sólo hace algo si de verdad cambia de estado.
			$nuevo = 'pendiente' === $que ? 'pendiente' : 'hecho';

			if ( ( $pend ? 'pendiente' : 'hecho' ) === $nuevo ) {
				continue;
			}

			$wpdb->update( self::table_name(), array( 'estado' => $nuevo ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::mover_saldo( 'pendiente' === $nuevo ? -$signo : $signo, (int) ( isset( $m->cuenta ) ? $m->cuenta : 0 ) );
			$hechos++;
		}

		wp_send_json_success(
			array(
				'hechos' => $hechos,
				'saldo'  => self::balance(),
			)
		);
	}

	/**
	 * Deja el movimiento en pesos, guardando de dónde salió.
	 *
	 * @param string $moneda   Código.
	 * @param mixed  $original Monto en esa moneda.
	 * @param mixed  $monto    Monto en pesos ya escrito, si lo hay.
	 * @return array moneda, original, monto en pesos, WP_Error|null.
	 */
	public static function en_pesos( $moneda, $original, $monto ) {
		$moneda   = strtoupper( trim( (string) $moneda ) );
		$original = round( (float) $original, 2 );
		$monto    = abs( (int) $monto );

		if ( ! array_key_exists( $moneda, Malka_CRM_Settings::currencies() ) ) {
			$moneda = 'CLP';
		}

		if ( 'CLP' === $moneda || $original <= 0 ) {
			return array( 'CLP', 0, $monto, null );
		}

		$pesos = Malka_CRM_Settings::to_clp( $original, $moneda );

		if ( $pesos <= 0 ) {
			return array(
				$moneda,
				$original,
				0,
				new WP_Error(
					'malka_crm_sin_cambio',
					sprintf(
						/* translators: moneda */
						__( 'No tengo el tipo de cambio del %s. Escríbelo en Configuración o anota el monto en pesos.', 'malka-crm' ),
						$moneda
					)
				),
			);
		}

		return array( $moneda, $original, $pesos, null );
	}

	/**
	 * En qué puede estar un movimiento.
	 *
	 * @return array
	 */
	public static function estados() {
		return array(
			'hecho'     => __( 'Completada', 'malka-crm' ),
			'pendiente' => __( 'Pendiente', 'malka-crm' ),
		);
	}

	/**
	 * Cómo se paga: de una vez, todos los meses, o en cuotas.
	 *
	 * No se guarda: sale de lo que ya hay anotado, para no tener dos datos
	 * que se puedan contradecir.
	 *
	 * @param object|array $m Movimiento.
	 * @return array clave, rótulo.
	 */
	public static function forma( $m ) {
		$m = (object) $m;
		$n = isset( $m->cuota_n ) ? (int) $m->cuota_n : 0;
		$t = isset( $m->cuota_total ) ? (int) $m->cuota_total : 0;

		if ( $n > 0 && $t > 0 ) {
			return array(
				'cuota',
				sprintf( /* translators: 1: cuál, 2: de cuántas */ __( 'Cuota %1$d/%2$d', 'malka-crm' ), $n, $t ),
			);
		}

		if ( ! empty( $m->recurrente ) ) {
			return array( 'recurrente', __( 'Recurrente', 'malka-crm' ) );
		}

		return array( 'puntual', __( 'Puntual', 'malka-crm' ) );
	}

	/**
	 * Las tres formas, para el filtro.
	 *
	 * @return array
	 */
	public static function formas() {
		return array(
			'puntual'    => __( 'Puntual', 'malka-crm' ),
			'recurrente' => __( 'Recurrente', 'malka-crm' ),
			'cuota'      => __( 'En cuotas', 'malka-crm' ),
		);
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 21 );
		add_action( 'admin_post_malka_crm_add_movement', array( $this, 'handle_add' ) );
		add_action( 'admin_post_malka_crm_set_balance', array( $this, 'handle_balance' ) );
		add_action( 'wp_ajax_malka_crm_movement', array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_malka_crm_movs_lote', array( __CLASS__, 'handle_lote' ) );
		add_action( 'wp_ajax_malka_crm_cartola', array( __CLASS__, 'ajax_cartola' ) );
		// Al anotar su nombre de la cartola, las transferencias a ella pasan a retiro.
		add_action(
			'update_option_' . Malka_CRM_Settings::OPTION,
			function ( $viejo, $nuevo ) {
				$a = is_array( $viejo ) && isset( $viejo['nombre_banco'] ) ? $viejo['nombre_banco'] : '';
				$b = is_array( $nuevo ) && isset( $nuevo['nombre_banco'] ) ? $nuevo['nombre_banco'] : '';
				if ( $a !== $b && '' !== trim( $b ) ) {
					self::recategorizar_retiros();
				}
			},
			10,
			2
		);
	}

	/* ─────────────────────────────  Tabla  ────────────────────────────── */

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Crea la tabla. Se llama en la activación del plugin.
	 */
	public static function install() {
		global $wpdb;

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fecha date NOT NULL,
			descripcion varchar(190) NOT NULL DEFAULT '',
			referencia varchar(120) NOT NULL DEFAULT '',
			monto bigint(20) NOT NULL DEFAULT 0,
			tipo varchar(10) NOT NULL DEFAULT 'salida',
			categoria varchar(30) NOT NULL DEFAULT 'sin_categoria',
			cuenta bigint(20) unsigned NOT NULL DEFAULT 0,
			recurrente tinyint(1) NOT NULL DEFAULT 0,
			automatico tinyint(1) NOT NULL DEFAULT 0,
			estado varchar(12) NOT NULL DEFAULT 'hecho',
			cuota_n smallint(5) unsigned NOT NULL DEFAULT 0,
			cuota_total smallint(5) unsigned NOT NULL DEFAULT 0,
			moneda varchar(3) NOT NULL DEFAULT 'CLP',
			original decimal(16,2) NOT NULL DEFAULT 0,
			quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
			project_id bigint(20) unsigned NOT NULL DEFAULT 0,
			creado datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY fecha (fecha),
			KEY categoria (categoria),
			KEY quote_id (quote_id)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::OPT_DB, self::DB_VERSION, false );
	}

	/**
	 * Asegura que la tabla exista antes de usarla.
	 */
	public static function maybe_install() {
		$antes = get_option( self::OPT_DB );
		if ( $antes !== self::DB_VERSION ) {
			self::install();
			// Al pasar a 1.1: las transferencias a su nombre que quedaron como
			// gasto se corrigen a «Retiro personal».
			if ( $antes && version_compare( (string) $antes, '1.2', '<' ) ) {
				self::recategorizar_retiros();
			}
			// Al pasar a 1.3: lo de antes era de la única cuenta que había.
			if ( $antes && version_compare( (string) $antes, '1.3', '<' ) ) {
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table_name() . ' SET cuenta = %d WHERE cuenta = 0', self::principal() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			}
		}
	}

	/**
	 * Pasa a «Retiro personal» las salidas que son transferencias a sí misma.
	 *
	 * @return int Cuántas cambió.
	 */
	public static function recategorizar_retiros() {
		global $wpdb;
		$filas = $wpdb->get_results( "SELECT id, descripcion FROM " . self::table_name() . " WHERE tipo = 'salida' AND categoria IN ('costo_variable','sin_categoria')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$n = 0;
		foreach ( (array) $filas as $f ) {
			if ( 'retiro' === self::adivinar_categoria( $f->descripcion, 'salida' ) ) {
				$wpdb->update( self::table_name(), array( 'categoria' => 'retiro' ), array( 'id' => (int) $f->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$n++;
			}
		}
		return $n;
	}

	/* ─────────────────────────────  Saldo  ────────────────────────────── */

	/* ─────────────────────────────  Cuentas  ──────────────────────────── */

	/**
	 * Los bancos de Chile, para elegir al agregar una cuenta.
	 *
	 * @return string[]
	 */
	public static function bancos() {
		return array( 'BancoEstado', 'Banco de Chile', 'Banco Santander', 'Banco BCI', 'Scotiabank', 'Banco Itaú', 'Banco Security', 'Banco Falabella', 'Banco Ripley', 'Banco Consorcio', 'Banco Internacional', 'Banco BICE', 'Coopeuch', 'Mercado Pago', 'Tenpo', 'Otro' );
	}

	/**
	 * Tipos de cuenta.
	 *
	 * @return array
	 */
	public static function tipos_cuenta() {
		return array(
			'corriente' => __( 'Cuenta corriente', 'malka-crm' ),
			'vista'     => __( 'Cuenta vista / RUT', 'malka-crm' ),
			'ahorro'    => __( 'Cuenta de ahorro', 'malka-crm' ),
			'prepago'   => __( 'Tarjeta de prepago o débito (Mach, Tenpo…)', 'malka-crm' ),
			'credito'   => __( 'Tarjeta de crédito', 'malka-crm' ),
		);
	}

	/**
	 * ¿Esta cuenta es una tarjeta de crédito?
	 *
	 * Su saldo no es plata que tengas: es lo que debes. Por eso no suma en
	 * ninguno de los dos totales.
	 *
	 * @param int $id Cuenta.
	 * @return bool
	 */
	public static function es_credito( $id ) {
		$c  = self::cuentas();
		$id = self::cuenta_valida( $id );
		return isset( $c[ $id ]['tipo'] ) && 'credito' === $c[ $id ]['tipo'];
	}

	/**
	 * Las cuentas bancarias de la empresa. La primera vez arma la que ya
	 * existía (el saldo único de antes) con los datos de Configuración.
	 *
	 * @return array[] id => array( id, banco, tipo, numero, alias, saldo, fecha, principal )
	 */
	public static function cuentas() {
		$c = get_option( self::OPT_CUENTAS, null );
		if ( ! is_array( $c ) || ! $c ) {
			$banco = (string) Malka_CRM_Settings::get( 'banco_nombre', '' );
			$tipo  = strtolower( (string) Malka_CRM_Settings::get( 'banco_tipo', '' ) );
			$c     = array(
				1 => array(
					'id'        => 1,
					'banco'     => $banco ? $banco : 'BancoEstado',
					'tipo'      => false !== strpos( $tipo, 'vista' ) || false !== strpos( $tipo, 'rut' ) ? 'vista' : ( false !== strpos( $tipo, 'ahorro' ) ? 'ahorro' : 'corriente' ),
					'numero'    => (string) Malka_CRM_Settings::get( 'banco_cuenta', '' ),
					'alias'     => '',
					'saldo'     => (int) get_option( self::OPT_BALANCE, 0 ),
					'fecha'     => (string) get_option( self::OPT_BAL_DATE, '' ),
					'principal' => true,
				),
			);
			update_option( self::OPT_CUENTAS, $c, false );
		}
		return $c;
	}

	/**
	 * El id de la cuenta principal (la que recibe lo que no dice cuenta).
	 *
	 * @return int
	 */
	public static function principal() {
		foreach ( self::cuentas() as $k => $c ) {
			if ( ! empty( $c['principal'] ) && empty( $c['personal'] ) ) {
				return (int) $k;
			}
		}
		// La principal es siempre una de la empresa: es donde cae lo que no
		// dice de qué cuenta sale.
		$empresa = self::cuentas_de( false );
		if ( $empresa ) {
			return (int) key( $empresa );
		}
		$c = self::cuentas();
		return (int) key( $c );
	}

	/**
	 * Una cuenta válida: la pedida si existe; si no, la principal.
	 *
	 * @param int $id Cuenta.
	 * @return int
	 */
	public static function cuenta_valida( $id ) {
		$c = self::cuentas();
		return isset( $c[ (int) $id ] ) ? (int) $id : self::principal();
	}

	/**
	 * Nombre corto de una cuenta: alias, o banco y últimos dígitos.
	 *
	 * @param int $id Cuenta.
	 * @return string
	 */
	public static function nombre_cuenta( $id ) {
		$c = self::cuentas();
		$id = self::cuenta_valida( $id );
		$x  = $c[ $id ];
		if ( ! empty( $x['alias'] ) ) {
			return $x['alias'];
		}
		$dig = preg_replace( '/\D/', '', (string) $x['numero'] );
		return $x['banco'] . ( $dig ? ' ··' . substr( $dig, -4 ) : '' );
	}

	/**
	 * Guarda las cuentas y deja el total en la opción de siempre.
	 *
	 * @param array $c Cuentas.
	 */
	private static function guardar_cuentas( $c ) {
		update_option( self::OPT_CUENTAS, $c, false );
		$t = 0;
		$f = '';
		foreach ( $c as $x ) {
			$t += (int) $x['saldo'];
			$f  = max( $f, (string) $x['fecha'] );
		}
		update_option( self::OPT_BALANCE, $t, false );
		update_option( self::OPT_BAL_DATE, $f, false );
	}

	/**
	 * Crea o edita una cuenta.
	 *
	 * @param array $d Datos.
	 * @return int Id.
	 */
	public static function guardar_cuenta( $d ) {
		$c  = self::cuentas();
		$id = isset( $d['id'] ) ? (int) $d['id'] : 0;
		if ( ! $id || ! isset( $c[ $id ] ) ) {
			$id       = $c ? max( array_keys( $c ) ) + 1 : 1;
			$c[ $id ] = array(
				'id'        => $id,
				'saldo'     => 0,
				'fecha'     => current_time( 'Y-m-d H:i' ),
				'principal' => empty( $c ),
			);
		}
		foreach ( array( 'banco', 'numero', 'alias' ) as $k ) {
			if ( isset( $d[ $k ] ) ) {
				$c[ $id ][ $k ] = sanitize_text_field( (string) $d[ $k ] );
			}
		}
		if ( isset( $d['tipo'] ) ) {
			$c[ $id ]['tipo'] = array_key_exists( $d['tipo'], self::tipos_cuenta() ) ? $d['tipo'] : 'corriente';
		}
		if ( isset( $d['saldo'] ) && '' !== $d['saldo'] ) {
			$c[ $id ]['saldo'] = (int) $d['saldo'];
			$c[ $id ]['fecha'] = current_time( 'Y-m-d H:i' );
		}
		foreach ( array( 'banco' => 'Banco', 'numero' => '', 'alias' => '', 'tipo' => 'corriente' ) as $k => $v ) {
			if ( ! isset( $c[ $id ][ $k ] ) ) {
				$c[ $id ][ $k ] = $v;
			}
		}
		if ( isset( $d['personal'] ) ) {
			$c[ $id ]['personal'] = empty( $d['personal'] ) ? 0 : 1;
		}
		if ( ! isset( $c[ $id ]['personal'] ) ) {
			$c[ $id ]['personal'] = 0;
		}
		// Una cuenta tuya no puede ser la principal del estudio.
		if ( ! empty( $c[ $id ]['personal'] ) ) {
			$c[ $id ]['principal'] = false;
		}
		if ( ! empty( $d['principal'] ) && empty( $c[ $id ]['personal'] ) ) {
			foreach ( $c as $k => $x ) {
				$c[ $k ]['principal'] = ( $k === $id );
			}
		}
		self::guardar_cuentas( $c );
		return $id;
	}

	/**
	 * Borra una cuenta; sus movimientos pasan a la principal.
	 *
	 * @param int $id Cuenta.
	 * @return bool
	 */
	public static function borrar_cuenta( $id ) {
		global $wpdb;
		$c = self::cuentas();
		if ( count( $c ) < 2 || ! isset( $c[ $id ] ) ) {
			return false;
		}
		$era = ! empty( $c[ $id ]['principal'] );
		unset( $c[ $id ] );
		if ( $era ) {
			$k                    = key( $c );
			$c[ $k ]['principal'] = true;
		}
		self::guardar_cuentas( $c );
		$wpdb->update( self::table_name(), array( 'cuenta' => self::principal() ), array( 'cuenta' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/* ─────────────────────────────  Saldo  ────────────────────────────── */

	/**
	 * El saldo: la suma de todas las cuentas.
	 *
	 * @return int
	 */
	/**
	 * La plata de la empresa.
	 *
	 * Las cuentas marcadas como personales no entran: su plata no es del
	 * estudio, aunque desde ahí se paguen cosas del estudio. Mezclarlas
	 * haría que «cuánto puedo retirar» y la meta del mes dejaran de
	 * significar algo.
	 *
	 * @return int
	 */
	public static function balance() {
		$t = 0;
		foreach ( self::cuentas() as $x ) {
			if ( ! empty( $x['personal'] ) || ( isset( $x['tipo'] ) && 'credito' === $x['tipo'] ) ) {
				continue;
			}
			$t += (int) $x['saldo'];
		}
		return $t;
	}

	/**
	 * Lo que hay en tus cuentas personales.
	 *
	 * @return int
	 */
	public static function balance_personal() {
		$t = 0;
		foreach ( self::cuentas() as $x ) {
			if ( ! empty( $x['personal'] ) && ( ! isset( $x['tipo'] ) || 'credito' !== $x['tipo'] ) ) {
				$t += (int) $x['saldo'];
			}
		}
		return $t;
	}

	/**
	 * ¿Esta cuenta es tuya y no del estudio?
	 *
	 * @param int $id Cuenta.
	 * @return bool
	 */
	public static function es_personal( $id ) {
		$c  = self::cuentas();
		$id = self::cuenta_valida( $id );
		return ! empty( $c[ $id ]['personal'] );
	}

	/**
	 * Las cuentas de la empresa, o las personales.
	 *
	 * @param bool $personales Cuáles.
	 * @return array
	 */
	public static function cuentas_de( $personales = false ) {
		$out = array();
		foreach ( self::cuentas() as $id => $c ) {
			if ( (bool) ! empty( $c['personal'] ) === (bool) $personales ) {
				$out[ $id ] = $c;
			}
		}
		return $out;
	}

	public static function balance_date() {
		$f = '';
		foreach ( self::cuentas() as $x ) {
			$f = max( $f, (string) $x['fecha'] );
		}
		return $f;
	}

	/**
	 * Fija el saldo de una cuenta a mano.
	 *
	 * @param int $monto  Saldo.
	 * @param int $cuenta Cuenta (0 = la principal).
	 */
	public static function set_balance( $monto, $cuenta = 0, $fecha = '' ) {
		$c  = self::cuentas();
		$id = self::cuenta_valida( $cuenta );
		$c[ $id ]['saldo'] = (int) $monto;
		$c[ $id ]['fecha'] = $fecha ? (string) $fecha : current_time( 'Y-m-d H:i' );
		self::guardar_cuentas( $c );
		return (int) $monto;
	}

	/**
	 * Hasta qué día el saldo de una cuenta ya está contado.
	 *
	 * @param int $cuenta Cuenta.
	 * @return string Y-m-d o ''.
	 */
	public static function corte( $cuenta = 0 ) {
		$c  = self::cuentas();
		$id = self::cuenta_valida( $cuenta );
		return isset( $c[ $id ]['fecha'] ) ? substr( (string) $c[ $id ]['fecha'], 0, 10 ) : '';
	}

	/**
	 * ¿Este movimiento tiene que mover el saldo?
	 *
	 * El saldo de la cuenta es una foto del banco con su fecha: lo que pasó
	 * antes de esa fecha ya está dentro del número. Sumarlo otra vez es
	 * contar dos veces la misma plata, y es lo que hacía que el panel no
	 * cuadrara con la cartola.
	 *
	 * Es la misma regla con la que ya entra una cartola.
	 *
	 * @param string $fecha  Y-m-d del movimiento.
	 * @param int    $cuenta Cuenta.
	 * @return bool
	 */
	public static function entra_al_saldo( $fecha, $cuenta = 0 ) {
		$corte = self::corte( $cuenta );

		if ( ! $corte ) {
			return true;
		}

		/*
		 * El día del cuadre cuenta como «después», no como «antes». Una
		 * cuota sólo guarda el día en que se pagó, sin hora, así que con
		 * el día del cuadre no hay forma de saber si esa plata ya estaba
		 * en la cartola o entró un rato más tarde.
		 *
		 * De los dos errores posibles, este es el que se ve: si se sumó
		 * algo que ya estaba, el número queda alto ese mismo día y se
		 * desmarca en «Cobros en tu saldo». Al revés —darlo por contado—
		 * el cobro no entraría nunca y nada lo diría.
		 */
		return substr( (string) $fecha, 0, 10 ) >= $corte;
	}

	/**
	 * Ajusta el saldo de una cuenta por un movimiento.
	 *
	 * @param int    $monto  Monto absoluto.
	 * @param string $tipo   'entrada' o 'salida'.
	 * @param int    $cuenta Cuenta.
	 */
	private static function shift_balance( $monto, $tipo, $cuenta = 0 ) {
		$c  = self::cuentas();
		$id = self::cuenta_valida( $cuenta );

		$signo = ( 'entrada' === $tipo ) ? 1 : -1;

		/*
		 * En una tarjeta de crédito el saldo es la deuda, no la plata: una
		 * compra la sube y pagar la tarjeta la baja. Sin esto, cargarle una
		 * suscripción hacía como si debieras menos.
		 */
		if ( self::es_credito( $id ) ) {
			$signo = -$signo;
		}

		$c[ $id ]['saldo'] = (int) $c[ $id ]['saldo'] + ( $signo * (int) $monto );
		self::guardar_cuentas( $c );
		return $c[ $id ]['saldo'];
	}

	/**
	 * Suma (o resta) un monto con signo a una cuenta.
	 *
	 * @param int $delta  Monto con signo.
	 * @param int $cuenta Cuenta.
	 */
	private static function mover_saldo( $delta, $cuenta ) {
		self::shift_balance( abs( (int) $delta ), $delta >= 0 ? 'entrada' : 'salida', $cuenta );
	}

	/* ─────────────────────────────  Altas  ────────────────────────────── */

	/**
	 * Registra un movimiento.
	 *
	 * @param array $args Datos del movimiento.
	 * @return int|WP_Error ID insertado.
	 */
	public static function add( array $args ) {
		global $wpdb;

		self::maybe_install();

		$d = wp_parse_args(
			$args,
			array(
				'fecha'       => current_time( 'Y-m-d' ),
				'descripcion' => '',
				'monto'       => 0,
				'tipo'        => 'salida',
				'categoria'   => 'sin_categoria',
				'recurrente'  => 0,
				'automatico'  => 0,
				'quote_id'    => 0,
				'project_id'  => 0,
				'referencia'  => '',
				'sin_saldo'   => 0,
				'cuenta'      => 0,
				'estado'      => 'hecho',
				'cuota_n'     => 0,
				'cuota_total' => 0,
				'moneda'      => 'CLP',
				'original'    => 0,
			)
		);
		$d['cuenta']     = self::cuenta_valida( $d['cuenta'] );
		$d['referencia'] = sanitize_text_field( (string) $d['referencia'] );

		/*
		 * En el saldo todo es pesos: si lo pagó en otra moneda se guarda
		 * también el monto original, y los pesos salen del tipo de cambio
		 * del día. Sin tipo de cambio no se inventa uno: se devuelve el
		 * error y ella decide si lo anota en pesos o escribe el cambio.
		 */
		list( $d['moneda'], $d['original'], $d['monto'], $err ) = self::en_pesos( $d['moneda'], $d['original'], $d['monto'] );

		if ( $err ) {
			return $err;
		}

		// Lo que viene de una cartola ya está reflejado en el saldo del banco.
		$sin_saldo = ! empty( $d['sin_saldo'] );
		unset( $d['sin_saldo'] );
		$d['fecha'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d['fecha'] ) ? $d['fecha'] : current_time( 'Y-m-d' );

		$d['descripcion'] = sanitize_text_field( $d['descripcion'] );
		$d['monto']       = abs( (int) $d['monto'] );
		$d['tipo']        = ( 'entrada' === $d['tipo'] ) ? 'entrada' : 'salida';
		$d['categoria']   = array_key_exists( $d['categoria'], self::categories() ) ? $d['categoria'] : 'sin_categoria';
		$d['recurrente']  = empty( $d['recurrente'] ) ? 0 : 1;
		$d['automatico']  = empty( $d['automatico'] ) ? 0 : 1;
		$d['quote_id']    = (int) $d['quote_id'];
		$d['project_id']  = (int) $d['project_id'];
		$d['creado']      = current_time( 'mysql' );

		/*
		 * Un movimiento pendiente es uno que ya sabes que va a pasar pero
		 * todavía no pasó: no toca el saldo hasta que lo marques hecho.
		 */
		$d['estado'] = in_array( $d['estado'], array_keys( self::estados() ), true ) ? $d['estado'] : 'hecho';

		// «Cuota 4 de 18»: si falta una de las dos, no hay cuota que mostrar.
		$d['cuota_total'] = max( 0, min( 240, (int) $d['cuota_total'] ) );
		$d['cuota_n']     = max( 0, min( $d['cuota_total'], (int) $d['cuota_n'] ) );

		if ( $d['cuota_total'] < 1 || $d['cuota_n'] < 1 ) {
			$d['cuota_n']     = 0;
			$d['cuota_total'] = 0;
		}

		// Lo que todavía no pasó no mueve el saldo.
		if ( 'pendiente' === $d['estado'] ) {
			$sin_saldo = true;
		}

		if ( '' === $d['descripcion'] || $d['monto'] <= 0 ) {
			return new WP_Error( 'malka_crm_mov_invalido', __( 'Falta la descripción o el monto.', 'malka-crm' ) );
		}

		$ok = $wpdb->insert( self::table_name(), $d ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $ok ) {
			return new WP_Error( 'malka_crm_mov_db', __( 'No se pudo guardar el movimiento.', 'malka-crm' ) );
		}

		if ( ! $sin_saldo ) {
			self::shift_balance( $d['monto'], $d['tipo'], $d['cuenta'] );
		}

		/**
		 * Se dispara al registrar un movimiento.
		 *
		 * @param int   $id Movimiento insertado.
		 * @param array $d  Datos.
		 */
		do_action( 'malka_crm_movement_added', (int) $wpdb->insert_id, $d );

		return (int) $wpdb->insert_id;
	}

	/**
	 * La referencia con que se anota el cobro de una cuota.
	 *
	 * @param string $pago_id ID de la cuota dentro de la cotización.
	 * @return string
	 */
	public static function ref_cuota( $pago_id ) {
		return 'cuota:' . sanitize_key( $pago_id );
	}

	/**
	 * El movimiento que ya anotó esta cuota, si existe.
	 *
	 * @param int    $quote_id Cotización.
	 * @param string $pago_id  Cuota.
	 * @return object|null
	 */
	public static function mov_de_cuota( $quote_id, $pago_id ) {
		global $wpdb;

		self::maybe_install();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE quote_id = %d AND descripcion LIKE %s LIMIT 1',
				(int) $quote_id,
				'%' . $wpdb->esc_like( self::ref_cuota( $pago_id ) ) . '%'
			)
		);
	}

	/**
	 * Todas las cuotas que ya tienen su movimiento, en una sola consulta.
	 *
	 * El Inicio pregunta esto en cada carga: hacerlo cuota por cuota serían
	 * decenas de consultas para dibujar una línea.
	 *
	 * @return array «quote_id:cuota_id» => id del movimiento.
	 */
	public static function refs_de_cuotas() {
		global $wpdb;

		self::maybe_install();

		$filas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT id, quote_id, descripcion FROM ' . self::table_name() . ' WHERE quote_id > 0 AND descripcion LIKE %s',
				'%' . $wpdb->esc_like( 'cuota:' ) . '%'
			)
		);

		$out = array();

		foreach ( (array) $filas as $f ) {
			if ( preg_match( '/cuota:([a-z0-9_\-]+)/i', (string) $f->descripcion, $m ) ) {
				$out[ (int) $f->quote_id . ':' . $m[1] ] = (int) $f->id;
			}
		}

		return $out;
	}

	/**
	 * Registra el ingreso de un pago de cotización. Idempotente por cuota.
	 *
	 * @param int    $quote_id ID de la cotización.
	 * @param string $pago_id  ID de la cuota dentro de la cotización.
	 * @param int    $monto    Monto recibido.
	 * @param string $concepto Concepto de la cuota.
	 * @param string $fecha    Y-m-d en que entró. Vacío: hoy.
	 */
	public static function record_quote_payment( $quote_id, $pago_id, $monto, $concepto = '', $fecha = '' ) {
		$ya = self::mov_de_cuota( $quote_id, $pago_id );

		if ( $ya ) {
			return (int) $ya->id;
		}

		$desc = $concepto ? $concepto : __( 'Pago de cotización', 'malka-crm' );
		$desc .= ' · ' . self::ref_cuota( $pago_id );
		$cuando = $fecha ? $fecha : current_time( 'Y-m-d' );

		return self::add(
			array(
				'descripcion' => $desc,
				'monto'       => $monto,
				'tipo'        => 'entrada',
				'categoria'   => 'ingreso',
				'automatico'  => 1,
				'quote_id'    => $quote_id,
				'fecha'       => $cuando,
				// Un cobro anterior al último cuadre ya está dentro del saldo
				// del banco: queda anotado, pero no lo vuelve a sumar.
				'sin_saldo'   => self::entra_al_saldo( $cuando ) ? 0 : 1,
			)
		);
	}

	/**
	 * Deshace el ingreso de una cuota: borra su movimiento y devuelve el
	 * saldo. Es lo que corresponde cuando una cuota deja de estar pagada
	 * —un clic equivocado— para que la plata no quede sumada para siempre.
	 *
	 * @param int    $quote_id Cotización.
	 * @param string $pago_id  Cuota.
	 * @return bool Si había algo que deshacer.
	 */
	public static function olvidar_pago_cuota( $quote_id, $pago_id ) {
		global $wpdb;

		$mov = self::mov_de_cuota( $quote_id, $pago_id );

		if ( ! $mov ) {
			return false;
		}

		/*
		 * Sólo se deshace lo que puso el panel solo. Si ella lo editó a mano
		 * (le cambió el monto, la categoría o la cuenta), deja de ser un
		 * apunte automático y borrarlo sería pisarle el trabajo.
		 */
		if ( empty( $mov->automatico ) ) {
			return false;
		}

		$cuenta = (int) ( isset( $mov->cuenta ) ? $mov->cuenta : 0 );

		$wpdb->delete( self::table_name(), array( 'id' => (int) $mov->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		/*
		 * Sólo se devuelve lo que se había sumado. Un cobro anterior al
		 * cuadre nunca movió el saldo, así que restarlo al sacarlo dejaría
		 * la cuenta más abajo de donde estaba.
		 */
		if ( self::entra_al_saldo( isset( $mov->fecha ) ? $mov->fecha : '', $cuenta ) ) {
			self::mover_saldo( -( ( 'entrada' === $mov->tipo ? 1 : -1 ) * (int) $mov->monto ), $cuenta );
		}

		return true;
	}

	/* ─────────────────────────────  Consultas  ────────────────────────── */

	/**
	 * Un movimiento.
	 *
	 * @param int $id Movimiento.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		self::maybe_install();
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Borra un movimiento y devuelve al saldo lo que había movido.
	 *
	 * Uno pendiente nunca tocó el saldo, así que tampoco se devuelve nada.
	 *
	 * @param int $id Id del movimiento.
	 * @return bool
	 */
	public static function borrar( $id ) {
		global $wpdb;

		$m = self::get( $id );

		if ( ! $m ) {
			return false;
		}

		$wpdb->delete( self::table_name(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$pend = isset( $m->estado ) && 'pendiente' === $m->estado;

		if ( ! $pend ) {
			$signo = ( 'entrada' === $m->tipo ? 1 : -1 ) * (int) $m->monto;
			self::mover_saldo( -$signo, (int) ( isset( $m->cuenta ) ? $m->cuenta : 0 ) );
		}

		return true;
	}

	/**
	 * Últimos movimientos.
	 *
	 * @param int $limit Cuántos.
	 */
	public static function recent( $limit = 10 ) {
		global $wpdb;
		self::maybe_install();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' ORDER BY fecha DESC, id DESC LIMIT %d',
				max( 1, (int) $limit )
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * La lista de movimientos, filtrada.
	 *
	 * El rango de fechas se pide a la base; el resto se filtra en PHP. Son
	 * decenas o cientos de filas, no millones, y hacerlo acá deja cada
	 * condición a la vista y comprobable una por una, en vez de escondida
	 * dentro de un WHERE armado a pedazos.
	 *
	 * @param array $f texto, tipo, estado, forma, cuenta, categoria, desde,
	 *                 hasta, limite, desde_fila.
	 * @return array filas, total.
	 */
	public static function buscar( $f = array() ) {
		global $wpdb;
		self::maybe_install();

		$f = wp_parse_args(
			$f,
			array(
				'texto'      => '',
				'tipo'       => '',
				'estado'     => '',
				'forma'      => '',
				'cuenta'     => '',
				'categoria'  => '',
				'desde'      => '',
				'hasta'      => '',
				'limite'     => 60,
				'desde_fila' => 0,
			)
		);

		$fecha = function ( $v ) {
			$v = (string) $v;
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};

		$desde = $fecha( $f['desde'] );
		$hasta = $fecha( $f['hasta'] );

		if ( $desde && $hasta ) {
			$filas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				$wpdb->prepare(
					'SELECT * FROM ' . self::table_name() . ' WHERE fecha BETWEEN %s AND %s ORDER BY fecha DESC, id DESC',
					$desde,
					$hasta
				)
			);
		} else {
			$filas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				'SELECT * FROM ' . self::table_name() . ' ORDER BY fecha DESC, id DESC'
			);
		}

		$filas = is_array( $filas ) ? $filas : array();
		$texto = trim( (string) $f['texto'] );
		$sueltas = array();

		foreach ( $filas as $m ) {
			$m = (object) $m;

			if ( $desde && (string) $m->fecha < $desde ) {
				continue;
			}

			if ( $hasta && (string) $m->fecha > $hasta ) {
				continue;
			}

			if ( '' !== $f['tipo'] && (string) $m->tipo !== $f['tipo'] ) {
				continue;
			}

			if ( '' !== $f['estado'] ) {
				$est = isset( $m->estado ) && $m->estado ? (string) $m->estado : 'hecho';
				if ( $est !== $f['estado'] ) {
					continue;
				}
			}

			if ( '' !== $f['forma'] ) {
				list( $clave ) = self::forma( $m );
				if ( $clave !== $f['forma'] ) {
					continue;
				}
			}

			if ( '' !== $f['cuenta'] && (int) $m->cuenta !== (int) $f['cuenta'] ) {
				continue;
			}

			if ( '' !== $f['categoria'] && (string) $m->categoria !== $f['categoria'] ) {
				continue;
			}

			/*
			 * Buscar mira la descripción y la referencia: es lo que una
			 * escribe al anotar y lo que recuerda después.
			 */
			if ( '' !== $texto ) {
				$donde = $m->descripcion . ' ' . ( isset( $m->referencia ) ? $m->referencia : '' );
				if ( false === stripos( $donde, $texto ) ) {
					continue;
				}
			}

			$sueltas[] = $m;
		}

		$total  = count( $sueltas );
		$limite = max( 1, (int) $f['limite'] );
		$salto  = max( 0, (int) $f['desde_fila'] );

		return array(
			'filas' => array_slice( $sueltas, $salto, $limite ),
			'total' => $total,
		);
	}

	/**
	 * Movimientos de un mes.
	 *
	 * @param int $year  Año.
	 * @param int $month Mes.
	 */
	public static function for_month( $year, $month ) {
		global $wpdb;
		self::maybe_install();

		$ini = sprintf( '%04d-%02d-01', (int) $year, (int) $month );
		$fin = gmdate( 'Y-m-t', strtotime( $ini ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE fecha BETWEEN %s AND %s ORDER BY fecha DESC, id DESC',
				$ini,
				$fin
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Lo que entró y lo que salió entre dos fechas, separado por bolsillo.
	 *
	 * El estudio y lo tuyo se suman aparte porque son dos economías: meter
	 * el arriendo de tu casa en los gastos del estudio hace que el negocio
	 * parezca que pierde plata.
	 *
	 * Las tarjetas de crédito no entran en el saldo —lo que deben no es
	 * plata que tengas— pero sus movimientos sí son gastos de verdad.
	 *
	 * @param string $desde Y-m-d.
	 * @param string $hasta Y-m-d.
	 * @return array estudio|personal => array( entra, sale )
	 */
	public static function por_bolsillo( $desde, $hasta ) {
		global $wpdb;
		self::maybe_install();

		$out = array(
			'estudio'  => array(
				'entra' => 0,
				'sale'  => 0,
			),
			'personal' => array(
				'entra' => 0,
				'sale'  => 0,
			),
		);

		/*
		 * La base filtra por fecha y la suma se hace acá. Podría sumar la
		 * base con un GROUP BY, pero son unos cientos de movimientos al
		 * año: no vale la pena cambiar código que se lee de corrido por
		 * una consulta que hay que descifrar.
		 */
		$filas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT fecha, cuenta, tipo, monto FROM ' . self::table_name() . ' WHERE fecha BETWEEN %s AND %s',
				$desde,
				$hasta
			)
		);

		if ( ! is_array( $filas ) ) {
			return $out;
		}

		$cuentas = self::cuentas();

		foreach ( $filas as $f ) {
			$fecha = substr( (string) $f->fecha, 0, 10 );

			if ( $fecha < $desde || $fecha > $hasta ) {
				continue;
			}

			$id   = (int) $f->cuenta;
			$cual = ! empty( $cuentas[ $id ]['personal'] ) ? 'personal' : 'estudio';
			$lado = 'entrada' === $f->tipo ? 'entra' : 'sale';

			$out[ $cual ][ $lado ] += (int) $f->monto;
		}

		return $out;
	}

	/**
	 * Lo mismo, mes a mes, para dibujarlo.
	 *
	 * @param string $desde Y-m-d.
	 * @param string $hasta Y-m-d.
	 * @return array Y-m => array( estudio => array(entra,sale), personal => … )
	 */
	public static function por_bolsillo_mes( $desde, $hasta ) {
		global $wpdb;
		self::maybe_install();

		$filas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT fecha, cuenta, tipo, monto FROM ' . self::table_name() . ' WHERE fecha BETWEEN %s AND %s',
				$desde,
				$hasta
			)
		);

		$out     = array();
		$cuentas = self::cuentas();

		// Todos los meses del tramo, aunque no tengan nada: un hueco en el
		// dibujo dice más que saltarse el mes.
		$paso = strtotime( substr( $desde, 0, 7 ) . '-01' );
		$tope = strtotime( substr( $hasta, 0, 7 ) . '-01' );

		while ( $paso <= $tope ) {
			$out[ gmdate( 'Y-m', $paso ) ] = array(
				'estudio'  => array(
					'entra' => 0,
					'sale'  => 0,
				),
				'personal' => array(
					'entra' => 0,
					'sale'  => 0,
				),
			);

			$paso = strtotime( '+1 month', $paso );
		}

		if ( ! is_array( $filas ) ) {
			return $out;
		}

		foreach ( $filas as $f ) {
			$fecha = substr( (string) $f->fecha, 0, 10 );

			if ( $fecha < $desde || $fecha > $hasta ) {
				continue;
			}

			$mes = substr( $fecha, 0, 7 );

			if ( ! isset( $out[ $mes ] ) ) {
				continue;
			}

			$id   = (int) $f->cuenta;
			$cual = ! empty( $cuentas[ $id ]['personal'] ) ? 'personal' : 'estudio';
			$lado = 'entrada' === $f->tipo ? 'entra' : 'sale';

			$out[ $mes ][ $cual ][ $lado ] += (int) $f->monto;
		}

		return $out;
	}

	/**
	 * Suma de una categoría en un mes.
	 *
	 * @param string $categoria Categoría.
	 * @param int    $year      Año.
	 * @param int    $month     Mes.
	 */
	public static function sum_category( $categoria, $year, $month ) {
		global $wpdb;
		self::maybe_install();

		$ini = sprintf( '%04d-%02d-01', (int) $year, (int) $month );
		$fin = gmdate( 'Y-m-t', strtotime( $ini ) );

		$total = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT SUM(monto) FROM ' . self::table_name() . ' WHERE categoria = %s AND fecha BETWEEN %s AND %s',
				sanitize_key( $categoria ),
				$ini,
				$fin
			)
		);

		return (int) $total;
	}

	/**
	 * A dónde volver después de anotar un movimiento.
	 *
	 * Al escritorio, que es donde se anotan. Antes volvía a la pantalla de
	 * WP-Admin y la sacaba del panel a mitad de trabajo.
	 *
	 * @return string
	 */
	private static function volver() {
		return class_exists( 'Malka_CRM_Dashboard' )
			? Malka_CRM_Dashboard::view_url( 'movimientos' )
			: admin_url( 'admin.php?page=malka-crm-movimientos' );
	}

	/**
	 * Promedio mensual de costos fijos en los últimos N meses completos.
	 *
	 * Devuelve null si no hay suficientes meses con datos: sin historial, la
	 * estimación de la configuración sigue mandando.
	 *
	 * @param int $meses Cuántos meses mirar.
	 * @return int|null
	 */
	public static function average_fixed_costs( $meses = 3 ) {
		global $wpdb;
		self::maybe_install();

		$meses = max( 1, (int) $meses );
		$hasta = current_time( 'Y-m-01' );
		$desde = gmdate( 'Y-m-01', strtotime( $hasta . ' -' . $meses . ' months' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT DATE_FORMAT(fecha, "%%Y-%%m") AS mes, SUM(monto) AS total
				 FROM ' . self::table_name() . '
				 WHERE categoria = %s AND tipo = %s AND fecha >= %s AND fecha < %s
				 GROUP BY mes',
				'costo_fijo',
				'salida',
				$desde,
				$hasta
			)
		);

		if ( ! is_array( $rows ) || count( $rows ) < $meses ) {
			return null;
		}

		$suma = 0;
		foreach ( $rows as $r ) {
			$suma += isset( $r->total ) ? (int) $r->total : 0;
		}

		return (int) round( $suma / count( $rows ) );
	}

	/* ─────────────────────────────  AJAX  ─────────────────────────────── */

	public function handle_ajax() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}

		check_ajax_referer( 'malka_crm_finance', 'nonce' );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( 'saldo' === $op ) {
			$monto = isset( $_POST['monto'] ) ? (int) preg_replace( '/[^\d-]/', '', wp_unslash( $_POST['monto'] ) ) : 0;
			self::set_balance( $monto, isset( $_POST['cuenta'] ) ? (int) $_POST['cuenta'] : 0 );
			wp_send_json_success(
				array(
					'total'  => self::balance(),
					'total_t' => Malka_CRM_Settings::money( self::balance() ),
					'saldo'  => $monto,
					'fecha'  => self::balance_date(),
					'texto'  => Malka_CRM_Settings::money( self::balance() ),
				)
			);
		}

		if ( 'movimiento' === $op ) {
			$res = self::add(
				array(
					'descripcion' => isset( $_POST['descripcion'] ) ? sanitize_text_field( wp_unslash( $_POST['descripcion'] ) ) : '',
					'monto'       => isset( $_POST['monto'] ) ? (int) preg_replace( '/[^\d]/', '', wp_unslash( $_POST['monto'] ) ) : 0,
					'tipo'        => isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : 'salida',
					'categoria'   => isset( $_POST['categoria'] ) ? sanitize_key( wp_unslash( $_POST['categoria'] ) ) : 'sin_categoria',
					'recurrente'  => ! empty( $_POST['recurrente'] ) ? 1 : 0,
					'fecha'       => isset( $_POST['fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha'] ) ) : current_time( 'Y-m-d' ),
					'referencia'  => isset( $_POST['referencia'] ) ? sanitize_text_field( wp_unslash( $_POST['referencia'] ) ) : '',
					'cuenta'      => isset( $_POST['cuenta'] ) ? (int) $_POST['cuenta'] : 0,
					'estado'      => isset( $_POST['estado'] ) ? sanitize_key( wp_unslash( $_POST['estado'] ) ) : 'hecho',
					'cuota_n'     => isset( $_POST['cuota_n'] ) ? (int) $_POST['cuota_n'] : 0,
					'cuota_total' => isset( $_POST['cuota_total'] ) ? (int) $_POST['cuota_total'] : 0,
					'moneda'      => isset( $_POST['moneda'] ) ? sanitize_text_field( wp_unslash( $_POST['moneda'] ) ) : 'CLP',
					'original'    => isset( $_POST['original'] ) ? (float) str_replace( ',', '.', preg_replace( '/[^\d.,]/', '', wp_unslash( $_POST['original'] ) ) ) : 0,
				)
			);

			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
			}

			wp_send_json_success(
				array(
					'id'    => $res,
					'saldo' => self::balance(),
					'texto' => Malka_CRM_Settings::money( self::balance() ),
				)
			);
		}

		// Corregir un movimiento: el saldo se mueve sólo por la diferencia.
		if ( 'editar' === $op || 'borrar' === $op ) {
			global $wpdb;
			$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
			$old = $id ? self::get( $id ) : null;
			if ( ! $old ) {
				wp_send_json_error( array( 'message' => __( 'No encontré ese movimiento.', 'malka-crm' ) ), 404 );
			}
			/*
			 * Un movimiento pendiente nunca tocó el saldo: al corregirlo no
			 * hay nada que devolver, y al marcarlo hecho recién ahí se mueve.
			 */
			$era_pend = isset( $old->estado ) && 'pendiente' === $old->estado;
			$antes    = $era_pend ? 0 : ( 'entrada' === $old->tipo ? 1 : -1 ) * (int) $old->monto;
			if ( 'borrar' === $op ) {
				$wpdb->delete( self::table_name(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::mover_saldo( -$antes, (int) ( isset( $old->cuenta ) ? $old->cuenta : 0 ) );
				wp_send_json_success( array( 'saldo' => self::balance() ) );
			}
			$tipo  = isset( $_POST['tipo'] ) && 'entrada' === $_POST['tipo'] ? 'entrada' : 'salida';
			$monto = isset( $_POST['monto'] ) ? (int) preg_replace( '/[^\d]/', '', wp_unslash( $_POST['monto'] ) ) : (int) $old->monto;
			$cat   = isset( $_POST['categoria'] ) ? sanitize_key( wp_unslash( $_POST['categoria'] ) ) : $old->categoria;
			$fecha = isset( $_POST['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fecha'] ) ) : $old->fecha;
			$c_old = self::cuenta_valida( isset( $old->cuenta ) ? (int) $old->cuenta : 0 );
			$c_new = isset( $_POST['cuenta'] ) ? self::cuenta_valida( (int) $_POST['cuenta'] ) : $c_old;

			$estado = isset( $_POST['estado'] ) ? sanitize_key( wp_unslash( $_POST['estado'] ) ) : ( isset( $old->estado ) ? (string) $old->estado : 'hecho' );
			$estado = array_key_exists( $estado, self::estados() ) ? $estado : 'hecho';
			$pend   = 'pendiente' === $estado;

			$c_tot = isset( $_POST['cuota_total'] ) ? max( 0, min( 240, (int) $_POST['cuota_total'] ) ) : (int) ( isset( $old->cuota_total ) ? $old->cuota_total : 0 );
			$c_n   = isset( $_POST['cuota_n'] ) ? max( 0, (int) $_POST['cuota_n'] ) : (int) ( isset( $old->cuota_n ) ? $old->cuota_n : 0 );
			$c_n   = min( $c_n, $c_tot );

			if ( $c_tot < 1 || $c_n < 1 ) {
				$c_n   = 0;
				$c_tot = 0;
			}

			$moneda_p = isset( $_POST['moneda'] ) ? sanitize_text_field( wp_unslash( $_POST['moneda'] ) ) : ( isset( $old->moneda ) ? (string) $old->moneda : 'CLP' );
			$orig_p   = isset( $_POST['original'] ) ? (float) str_replace( ',', '.', preg_replace( '/[^\d.,]/', '', wp_unslash( $_POST['original'] ) ) ) : (float) ( isset( $old->original ) ? $old->original : 0 );

			list( $moneda_p, $orig_p, $monto, $err_c ) = self::en_pesos( $moneda_p, $orig_p, $monto );

			if ( $err_c ) {
				wp_send_json_error( array( 'message' => $err_c->get_error_message() ), 400 );
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table_name(),
				array(
					'descripcion' => isset( $_POST['descripcion'] ) ? sanitize_text_field( wp_unslash( $_POST['descripcion'] ) ) : $old->descripcion,
					'referencia'  => isset( $_POST['referencia'] ) ? sanitize_text_field( wp_unslash( $_POST['referencia'] ) ) : '',
					'monto'       => max( 1, $monto ),
					'tipo'        => $tipo,
					'categoria'   => array_key_exists( $cat, self::categories() ) ? $cat : 'sin_categoria',
					'recurrente'  => ! empty( $_POST['recurrente'] ) ? 1 : 0,
					'fecha'       => $fecha ? $fecha : $old->fecha,
					'cuenta'      => $c_new,
					'estado'      => $estado,
					'cuota_n'     => $c_n,
					'cuota_total' => $c_tot,
					'moneda'      => $moneda_p,
					'original'    => $orig_p,
				),
				array( 'id' => $id )
			);
			$despues = $pend ? 0 : ( 'entrada' === $tipo ? 1 : -1 ) * max( 1, $monto );
			if ( $c_new !== $c_old ) {
				self::mover_saldo( -$antes, $c_old );
				self::mover_saldo( $despues, $c_new );
			} elseif ( $despues !== $antes ) {
				self::mover_saldo( $despues - $antes, $c_old );
			}
			wp_send_json_success( array( 'saldo' => self::balance() ) );
		}

		// Cuentas bancarias.
		if ( 'cuenta' === $op ) {
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$id = self::guardar_cuenta(
				array(
					'id'        => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
					'banco'     => isset( $_POST['banco'] ) ? wp_unslash( $_POST['banco'] ) : null,
					'tipo'      => isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : null,
					'numero'    => isset( $_POST['numero'] ) ? wp_unslash( $_POST['numero'] ) : null,
					'alias'     => isset( $_POST['alias'] ) ? wp_unslash( $_POST['alias'] ) : null,
					'saldo'     => isset( $_POST['saldo'] ) ? (string) (int) preg_replace( '/[^\d-]/', '', wp_unslash( $_POST['saldo'] ) ) : '',
					'principal' => ! empty( $_POST['principal'] ),
					'personal'  => ! empty( $_POST['personal'] ),
				)
			);
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			wp_send_json_success( array( 'id' => $id, 'total' => self::balance(), 'total_t' => Malka_CRM_Settings::money( self::balance() ) ) );
		}
		if ( 'cuenta_borrar' === $op ) {
			$ok = self::borrar_cuenta( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
			if ( ! $ok ) {
				wp_send_json_error( array( 'message' => __( 'Tiene que quedar al menos una cuenta.', 'malka-crm' ) ), 400 );
			}
			wp_send_json_success( array( 'total' => self::balance() ) );
		}

		wp_send_json_error( array( 'message' => __( 'Operación desconocida.', 'malka-crm' ) ), 400 );
	}

	/* ─────────────────────────────  Cartola  ─────────────────────────── */

	/**
	 * La categoría más probable de un movimiento de cartola.
	 *
	 * @param string $desc Glosa del banco.
	 * @param string $tipo entrada|salida.
	 * @return string
	 */
	public static function adivinar_categoria( $desc, $tipo ) {
		$t = strtolower( remove_accents( (string) $desc ) );
		if ( preg_match( '/\b(sii|tgr|tesoreria|impuesto|f29|iva|formulario 29)\b/', $t ) ) {
			return 'impuesto';
		}
		// Entre cuentas de la empresa (la glosa nombra a la empresa o su RUT): traspaso.
		if ( preg_match( '/\b(tef|transf|transferencia|traspaso)\b/', $t ) ) {
			$rut = preg_replace( '/[^0-9k]/', '', strtolower( (string) Malka_CRM_Settings::get( 'rut', '' ) ) );
			$rs  = strtolower( remove_accents( (string) Malka_CRM_Settings::get( 'razon_social', '' ) ) );
			$rs  = trim( preg_replace( '/\b(spa|ltda|limitada|eirl|s\.?a\.?)\b\.?/', '', $rs ) );
			$sin = preg_replace( '/[^0-9k]/', '', $t );
			if ( ( strlen( $rut ) > 6 && false !== strpos( $sin, substr( $rut, 0, -1 ) ) ) || ( strlen( $rs ) > 5 && false !== strpos( $t, $rs ) ) ) {
				return 'traspaso';
			}
		}
		// Una transferencia a su propio nombre es un retiro (su sueldo).
		if ( 'salida' === $tipo && preg_match( '/\b(tef|transf|transferencia|traspaso)\b/', $t ) ) {
			// Palabras de la glosa, que el banco suele cortar («MUNOZ SOCH»).
			$glosa = array_filter(
				preg_split( '/[^a-z]+/', $t ),
				function ( $w ) {
					return strlen( $w ) >= 4;
				}
			);
			foreach ( self::nombres_propios() as $nombre ) {
				$partes = array_filter(
					preg_split( '/[^a-z]+/', $nombre ),
					function ( $w ) {
						return strlen( $w ) >= 4;
					}
				);
				$hay = 0;
				foreach ( $partes as $w ) {
					foreach ( $glosa as $g ) {
						if ( $g === $w || 0 === strpos( $w, $g ) ) {
							$hay++;
							break;
						}
					}
				}
				if ( $partes && $hay >= min( 2, count( $partes ) ) ) {
					return 'retiro';
				}
			}
		}
		if ( 'entrada' === $tipo ) {
			return 'ingreso';
		}
		// Los costos fijos que tiene anotados en Configuración.
		if ( class_exists( 'Malka_CRM_Settings' ) && method_exists( 'Malka_CRM_Settings', 'costs_estudio' ) ) {
			// Acá sólo interesan los nombres, no las fechas: todos los del
			// estudio, incluidos los que se pagan una vez al año.
			foreach ( (array) Malka_CRM_Settings::costs_estudio() as $c ) {
				$n = strtolower( remove_accents( (string) ( isset( $c['nombre'] ) ? $c['nombre'] : '' ) ) );
				foreach ( preg_split( '/\s+/', $n ) as $w ) {
					if ( strlen( $w ) >= 5 && false !== strpos( $t, $w ) ) {
						return 'costo_fijo';
					}
				}
			}
		}
		if ( preg_match( '/\b(retiro|traspaso a cuenta|transferencia a .*(propia|personal))\b/', $t ) ) {
			return 'retiro';
		}
		return 'costo_variable';
	}

	/**
	 * Los nombres de quienes manejan el estudio (para reconocer una
	 * transferencia a sí misma en la cartola).
	 *
	 * @return string[] En minúsculas y sin tildes.
	 */
	public static function nombres_propios() {
		$out = array();
		foreach ( get_users( array( 'role__in' => array( 'administrator' ), 'number' => 10 ) ) as $u ) {
			foreach ( array( trim( $u->first_name . ' ' . $u->last_name ), $u->display_name ) as $n ) {
				if ( '' !== trim( $n ) ) {
					$out[] = strtolower( remove_accents( $n ) );
				}
			}
		}
		foreach ( array( 'nombre_banco', 'cont_nombre' ) as $k ) {
			$extra = (string) Malka_CRM_Settings::get( $k, '' );
			if ( '' !== trim( $extra ) ) {
				$out[] = strtolower( remove_accents( $extra ) );
			}
		}
		return array_unique( $out );
	}

	/**
	 * Revisa o registra los movimientos de una cartola.
	 */
	public static function ajax_cartola() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}
		check_ajax_referer( 'malka_crm_finance', 'nonce' );
		global $wpdb;
		self::maybe_install();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$filas  = isset( $_POST['filas'] ) ? json_decode( wp_unslash( $_POST['filas'] ), true ) : array();
		$probar = ! empty( $_POST['probar'] );
		$elige  = isset( $_POST['elegidas'] ) ? json_decode( wp_unslash( $_POST['elegidas'] ), true ) : null;
		$cats   = isset( $_POST['cats'] ) ? (array) json_decode( wp_unslash( $_POST['cats'] ), true ) : array();
		$saldo  = isset( $_POST['saldo'] ) && '' !== $_POST['saldo'] ? (int) $_POST['saldo'] : null;
		$cuenta = self::cuenta_valida( isset( $_POST['cuenta'] ) ? (int) $_POST['cuenta'] : 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$out    = array();
		$hechos = 0;
		$vistos = array();
		foreach ( array_values( (array) $filas ) as $i => $f ) {
			$fecha = isset( $f['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( $f['fecha'] ) : '';
			$monto = isset( $f['monto'] ) ? abs( (int) $f['monto'] ) : 0;
			$tipo  = isset( $f['tipo'] ) && 'entrada' === $f['tipo'] ? 'entrada' : 'salida';
			$desc  = isset( $f['descripcion'] ) ? sanitize_text_field( $f['descripcion'] ) : '';
			if ( ! $fecha || $monto <= 0 ) {
				continue;
			}
			// ¿Ya está? Mismo monto y tipo, hasta 3 días de diferencia (el banco
			// a veces fecha distinto que el pago registrado solo).
			$ya = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT id FROM ' . self::table_name() . ' WHERE monto = %d AND tipo = %s AND cuenta IN (%d, 0) AND fecha BETWEEN %s AND %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$monto,
					$tipo,
					$cuenta,
					gmdate( 'Y-m-d', strtotime( $fecha . ' -3 days' ) ),
					gmdate( 'Y-m-d', strtotime( $fecha . ' +3 days' ) )
				)
			);
			$llave  = $fecha . '|' . $monto . '|' . $tipo . '|' . $desc;
			$accion = $ya ? 'duplicado' : ( isset( $vistos[ $llave ] ) ? 'repetido' : 'nuevo' );
			$vistos[ $llave ] = 1;
			$cat    = isset( $cats[ $i ] ) && array_key_exists( $cats[ $i ], self::categories() ) ? $cats[ $i ] : self::adivinar_categoria( $desc, $tipo );
			$fila   = array(
				'i'           => $i,
				'fecha'       => $fecha,
				'descripcion' => $desc,
				'monto'       => $monto,
				'tipo'        => $tipo,
				'categoria'   => $cat,
				'accion'      => $accion,
			);
			$quiere = null === $elige ? true : in_array( $i, array_map( 'intval', (array) $elige ), true );
			if ( ! $probar && $quiere && 'nuevo' === $accion ) {
				$id = self::add(
					array(
						'fecha'       => $fecha,
						'descripcion' => $desc ? $desc : __( 'Movimiento de cartola', 'malka-crm' ),
						'monto'       => $monto,
						'tipo'        => $tipo,
						'categoria'   => $cat,
						'cuenta'      => $cuenta,
						// Con el saldo final de la cartola el saldo se fija al final; sin él,
						// sólo mueve el saldo lo posterior a la última vez que lo anotó.
						'sin_saldo'   => null !== $saldo || $fecha <= substr( self::balance_date(), 0, 10 ) ? 1 : 0,
					)
				);
				if ( ! is_wp_error( $id ) ) {
					$hechos++;
					$fila['accion'] = 'duplicado';
				}
			}
			$out[] = $fila;
		}
		if ( ! $probar && null !== $saldo ) {
			self::set_balance( $saldo, $cuenta );
		}
		wp_send_json_success(
			array(
				'filas'  => $out,
				'hechos' => $hechos,
				'cats'   => self::categories(),
				'saldo'  => self::balance(),
			)
		);
	}

	/* ─────────────────────────────  Admin  ────────────────────────────── */

	public function menu() {
		add_submenu_page(
			'malka-crm',
			__( 'Movimientos', 'malka-crm' ),
			__( 'Movimientos', 'malka-crm' ),
			Malka_CRM_Settings::cap(),
			'malka-crm-movimientos',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'malka-crm' ) );
		}

		self::maybe_install();

		$cats = self::categories();
		$movs = self::recent( 50 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Movimientos', 'malka-crm' ) . '</h1>';

		echo '<h2>' . esc_html__( 'Saldo en la cuenta', 'malka-crm' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:2em">';
		wp_nonce_field( 'malka_crm_set_balance', 'malka_crm_balance_nonce' );
		echo '<input type="hidden" name="action" value="malka_crm_set_balance" />';
		echo '<input type="number" name="saldo" value="' . esc_attr( self::balance() ) . '" class="regular-text" /> ';
		submit_button( __( 'Actualizar saldo', 'malka-crm' ), 'secondary', 'submit', false );
		if ( self::balance_date() ) {
			echo ' <span class="description">' . esc_html( sprintf( /* translators: fecha */ __( 'Actualizado el %s', 'malka-crm' ), self::balance_date() ) ) . '</span>';
		}
		echo '</form>';

		echo '<h2>' . esc_html__( 'Registrar un movimiento', 'malka-crm' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:2em">';
		wp_nonce_field( 'malka_crm_add_movement', 'malka_crm_movement_nonce' );
		echo '<input type="hidden" name="action" value="malka_crm_add_movement" />';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="mv_desc">' . esc_html__( 'Descripción', 'malka-crm' ) . '</label></th>';
		echo '<td><input type="text" id="mv_desc" name="descripcion" class="regular-text" required /></td></tr>';
		echo '<tr><th scope="row"><label for="mv_monto">' . esc_html__( 'Monto', 'malka-crm' ) . '</label></th>';
		echo '<td><input type="number" id="mv_monto" name="monto" min="1" required /></td></tr>';
		echo '<tr><th scope="row"><label for="mv_tipo">' . esc_html__( 'Tipo', 'malka-crm' ) . '</label></th><td>';
		echo '<select id="mv_tipo" name="tipo">';
		echo '<option value="salida">' . esc_html__( 'Salida', 'malka-crm' ) . '</option>';
		echo '<option value="entrada">' . esc_html__( 'Entrada', 'malka-crm' ) . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="mv_cat">' . esc_html__( 'Categoría', 'malka-crm' ) . '</label></th><td>';
		echo '<select id="mv_cat" name="categoria">';
		foreach ( $cats as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Recurrencia', 'malka-crm' ) . '</th>';
		echo '<td><label><input type="checkbox" name="recurrente" value="1" /> ' . esc_html__( 'Se repite todos los meses', 'malka-crm' ) . '</label></td></tr>';
		echo '</table>';
		submit_button( __( 'Registrar', 'malka-crm' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Últimos movimientos', 'malka-crm' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Fecha', 'malka-crm' ) . '</th>';
		echo '<th>' . esc_html__( 'Descripción', 'malka-crm' ) . '</th>';
		echo '<th>' . esc_html__( 'Categoría', 'malka-crm' ) . '</th>';
		echo '<th style="text-align:right">' . esc_html__( 'Monto', 'malka-crm' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $movs ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Todavía no hay movimientos registrados.', 'malka-crm' ) . '</td></tr>';
		}

		foreach ( $movs as $m ) {
			$signo = ( 'entrada' === $m->tipo ) ? '+' : '−';
			$cat   = isset( $cats[ $m->categoria ] ) ? $cats[ $m->categoria ] : $m->categoria;
			echo '<tr>';
			echo '<td>' . esc_html( $m->fecha ) . '</td>';
			echo '<td>' . esc_html( $m->descripcion );
			if ( (int) $m->automatico ) {
				echo ' <span class="description">· ' . esc_html__( 'automático', 'malka-crm' ) . '</span>';
			}
			if ( (int) $m->recurrente ) {
				echo ' <span class="description">· ' . esc_html__( 'mensual', 'malka-crm' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $cat ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( $signo . Malka_CRM_Settings::money( $m->monto ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	public function handle_balance() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'malka-crm' ) );
		}
		check_admin_referer( 'malka_crm_set_balance', 'malka_crm_balance_nonce' );

		$monto = isset( $_POST['saldo'] ) ? (int) $_POST['saldo'] : 0;
		self::set_balance( $monto );

		wp_safe_redirect( self::volver() );
		exit;
	}

	public function handle_add() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'malka-crm' ) );
		}
		check_admin_referer( 'malka_crm_add_movement', 'malka_crm_movement_nonce' );

		self::add(
			array(
				'descripcion' => isset( $_POST['descripcion'] ) ? sanitize_text_field( wp_unslash( $_POST['descripcion'] ) ) : '',
				'monto'       => isset( $_POST['monto'] ) ? (int) $_POST['monto'] : 0,
				'tipo'        => isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : 'salida',
				'categoria'   => isset( $_POST['categoria'] ) ? sanitize_key( wp_unslash( $_POST['categoria'] ) ) : 'sin_categoria',
				'recurrente'  => ! empty( $_POST['recurrente'] ) ? 1 : 0,
			)
		);

		wp_safe_redirect( self::volver() );
		exit;
	}
}
