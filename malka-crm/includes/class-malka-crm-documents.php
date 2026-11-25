<?php
/**
 * El registro de documentos tributarios, a mano.
 *
 * Mientras el SII no esté conectado, Malka sigue emitiendo facturas, boletas
 * y notas de crédito por otros medios. Si eso no queda anotado en ninguna
 * parte, el panel miente: muestra cotizaciones cobradas sin respaldo y no
 * puede decirle nada útil a su contadora.
 *
 * Acá se anotan a mano, con los números que de verdad se emitieron. El día
 * que el SII quede conectado, estos mismos campos los llena el DTE y nada
 * de lo que ella escribió se pierde.
 *
 * Las reglas de cálculo son de Chile y no son todas iguales:
 *
 *   factura    el neto es lo que ella escribe y el IVA se le suma encima
 *   boleta     el total es lo que ella escribe y el IVA ya viene adentro
 *   honorarios el bruto es lo que ella escribe y la retención se descuenta
 *   nota de crédito  resta, y apunta al documento que corrige
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Facturas, boletas y notas de crédito anotadas a mano.
 */
class Malka_CRM_Documents {

	const POST_TYPE = 'malka_invoice';

	const META_TIPO    = '_malka_pm_doc_tipo';
	const META_FOLIO   = '_malka_pm_doc_folio';
	const META_FECHA   = '_malka_pm_doc_fecha';
	const META_CLIENTE = '_malka_pm_doc_cliente';
	const META_RUT     = '_malka_pm_doc_rut';
	const META_BASE    = '_malka_pm_doc_base';
	const META_ESTADO  = '_malka_pm_doc_estado';
	const META_QUOTE   = '_malka_pm_doc_quote';
	const META_REF     = '_malka_pm_doc_ref';
	const META_NOTAS   = '_malka_pm_doc_notas';

	/** venta (lo que yo emito) o compra (lo que me emiten a mí). */
	const META_SENTIDO = '_malka_pm_doc_sentido';

	/** En una por emitir: la emitida que parece ser la misma (ID). */
	const META_PROPUESTA = '_malka_pm_doc_propuesta';

	/** En una por emitir: las emitidas que ella dijo que no son (IDs). */
	const META_NO_ES = '_malka_pm_doc_no_es';

	/** En una emitida juntada: cómo estaba antes, para poder separarla. */
	const META_ANTES = '_malka_pm_doc_antes';

	/**
	 * Sólo en compras: si esta es de las que tengo que emitir yo en Chile.
	 *
	 * Vacío = no me toca. 'pendiente' = me toca y no la he emitido.
	 * 'emitida' = ya la emití, y entonces META_FOLIO_MIO tiene su número.
	 */
	const META_EMITO = '_malka_pm_doc_emito';

	/** El folio que le puse yo al emitirla. */
	const META_FOLIO_MIO = '_malka_pm_doc_folio_mio';

	/** El día en que la emití yo. */
	const META_FECHA_MIA = '_malka_pm_doc_fecha_mia';

	/*
	 * Con qué datos la emito en el SII. El proveedor es extranjero: su nombre
	 * real y el que queda registrado en el SII no siempre coinciden, y el RUT
	 * es el que el SII le asigna, que puede cambiar de una vez a otra.
	 */
	const META_SII_NOMBRE = '_malka_pm_doc_sii_nombre';
	const META_SII_RUT    = '_malka_pm_doc_sii_rut';

	/** Giro y dirección con que se emite: también pueden no ser los de su factura. */
	const META_SII_GIRO   = '_malka_pm_doc_sii_giro';
	const META_SII_DIR    = '_malka_pm_doc_sii_dir';
	const META_SII_COMUNA = '_malka_pm_doc_sii_comuna';
	const META_SII_CIUDAD = '_malka_pm_doc_sii_ciudad';

	/** Lo que de verdad se pagó en pesos (MACH u otra tarjeta cobra su propio dólar). */
	const META_PAGADO = '_malka_pm_doc_pagado_clp';

	/** El movimiento (salida de plata) y el pago del estudio con que se pagó. */
	const META_MOV  = '_malka_pm_doc_mov';
	const META_PAGO = '_malka_pm_doc_pago';

	/** La ficha del proveedor a la que corresponde. */
	const META_PROVEEDOR_ID = '_malka_pm_doc_proveedor_id';

	/** Lo que dice la factura original del proveedor, tal cual. */
	const META_GIRO   = '_malka_pm_doc_giro';
	const META_DIR    = '_malka_pm_doc_dir';
	const META_COMUNA = '_malka_pm_doc_comuna';
	const META_CIUDAD = '_malka_pm_doc_ciudad';

	/** El tipo de cambio del Banco Central usado, y de qué día es. */
	const META_TC       = '_malka_pm_doc_tc';
	const META_TC_FECHA = '_malka_pm_doc_tc_fecha';

	/** De dónde salieron: previo (la última vez), sii (la nómina), generico, mano. */
	const META_SII_ORIGEN = '_malka_pm_doc_sii_origen';

	/** Una factura del extranjero viene en su moneda: se guarda tal cual. */
	const META_MONEDA   = '_malka_pm_doc_moneda';
	const META_ORIGINAL = '_malka_pm_doc_original';

	/** El negocio al que se carga. */
	const META_PROYECTO = '_malka_pm_doc_proyecto';

	/**
	 * Mes en que se declara (Y-m), si no es el de la fecha: una compra
	 * recibida a fin de mes suele entrar al libro del mes siguiente.
	 */
	const META_PERIODO = '_malka_pm_doc_periodo';

	/*
	 * El PDF adjunto.
	 *
	 * La ruta es relativa a la carpeta blindada de Malka_CRM_Invoices, que ya
	 * está fuera del alcance del navegador y tiene su .htaccess. Se reusa su
	 * misma clave de meta a propósito: el gancho before_delete_post de ese
	 * módulo mira _malka_pm_file en este mismo tipo de contenido, así que
	 * borrar el documento borra también su archivo, sin código nuevo.
	 */
	const META_ARCHIVO = '_malka_pm_file';

	/** Cómo se llamaba el archivo cuando ella lo subió. */
	const META_ARCHIVO_NOMBRE = '_malka_pm_file_nombre';

	/**
	 * Engancha el guardado y el borrado.
	 */
	public function __construct() {
		add_action( 'admin_post_malka_crm_save_doc', array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_ajax_malka_crm_doc_extranjero', array( __CLASS__, 'ajax_extranjero' ) );
		add_action( 'wp_ajax_malka_crm_doc_campo', array( __CLASS__, 'ajax_campo' ) );
		add_action( 'wp_ajax_malka_crm_doc_juntar', array( __CLASS__, 'ajax_juntar' ) );
		add_action( 'wp_ajax_malka_crm_doc_importar', array( __CLASS__, 'ajax_importar' ) );
		add_action( 'admin_post_malka_crm_delete_doc', array( __CLASS__, 'handle_delete' ) );
		add_action( 'wp_ajax_malka_crm_doc_borrar', array( __CLASS__, 'ajax_borrar' ) );

		// El PDF lo baja ella con sesión, o el cliente desde su portal.
		add_action( 'admin_post_malka_crm_doc_pdf', array( __CLASS__, 'handle_pdf' ) );
		add_action( 'admin_post_nopriv_malka_crm_doc_pdf', array( __CLASS__, 'handle_pdf' ) );
		add_action( 'admin_post_malka_crm_doc_ver', array( __CLASS__, 'handle_ver' ) );
		add_action( 'admin_post_nopriv_malka_crm_doc_ver', array( __CLASS__, 'handle_ver' ) );
	}

	/* ──────────────────────────  Vocabulario  ───────────────────────── */

	/**
	 * Los tipos de documento que lleva el estudio.
	 *
	 * @return array clave => rótulo.
	 */
	public static function tipos() {
		return array(
			// Afecta lleva IVA encima; exenta no lleva (servicios exentos,
			// exportación). La clave «factura» sigue siendo la afecta, así
			// los documentos de antes no cambian.
			'factura'    => __( 'Factura afecta', 'malka-crm' ),
			'exenta'     => __( 'Factura exenta', 'malka-crm' ),
			'boleta'     => __( 'Boleta', 'malka-crm' ),
			'honorarios' => __( 'Boleta de honorarios', 'malka-crm' ),
			'nota'       => __( 'Nota de crédito', 'malka-crm' ),
		);
	}

	/**
	 * Cómo se llama lo que ella escribe en cada tipo.
	 *
	 * En una factura escribe el neto y el IVA se suma. En una boleta escribe
	 * el total, que ya trae el IVA adentro. Llamarle «monto» a los dos sería
	 * la forma más rápida de que se equivoque.
	 *
	 * @param string $tipo Tipo de documento.
	 * @return array {label, ayuda}
	 */
	public static function campo_monto( $tipo ) {
		switch ( $tipo ) {
			case 'boleta':
				return array(
					'label' => __( 'Total de la boleta', 'malka-crm' ),
					'ayuda' => __( 'Lo que pagó el cliente. El IVA ya viene adentro y se calcula solo.', 'malka-crm' ),
				);
			case 'honorarios':
				return array(
					'label' => __( 'Honorarios brutos', 'malka-crm' ),
					'ayuda' => __( 'Antes de la retención. Lo que te depositan es menos.', 'malka-crm' ),
				);
			case 'exenta':
				return array(
					'label' => __( 'Monto exento', 'malka-crm' ),
					'ayuda' => __( 'Sin IVA: el total es este mismo monto.', 'malka-crm' ),
				);
			case 'nota':
				return array(
					'label' => __( 'Neto que se anula', 'malka-crm' ),
					'ayuda' => __( 'El neto de lo que estás dejando sin efecto. Resta del total del mes.', 'malka-crm' ),
				);
		}

		return array(
			'label' => __( 'Neto', 'malka-crm' ),
			'ayuda' => __( 'Sin IVA. El IVA se suma encima.', 'malka-crm' ),
		);
	}

	/**
	 * Estados de un documento.
	 *
	 * @return array
	 */
	public static function estados( $sentido = 'venta' ) {
		/*
		 * En ventas no existe «pagada»: Malka emite la factura recién cuando
		 * el saldo ya está cancelado, así que una factura emitida es, por
		 * definición, una factura pagada. Tenerlo como opción sólo servía
		 * para dejar documentos marcados como impagos para siempre.
		 */
		if ( 'compra' === $sentido ) {
			return array(
				'registrada' => __( 'Registrada', 'malka-crm' ),
				'anulada'    => __( 'Anulada', 'malka-crm' ),
			);
		}

		return array(
			'emitida' => __( 'Emitida', 'malka-crm' ),
			'anulada' => __( 'Anulada', 'malka-crm' ),
		);
	}

	/**
	 * De qué lado está un documento.
	 *
	 * @return array
	 */
	public static function sentidos() {
		return array(
			'venta'  => __( 'Venta', 'malka-crm' ),
			'compra' => __( 'Compra', 'malka-crm' ),
		);
	}

	/**
	 * Estados de una factura de compra que me toca emitir a mí.
	 *
	 * @return array
	 */
	public static function emisiones() {
		return array(
			''          => __( 'No me toca emitirla', 'malka-crm' ),
			'pendiente' => __( 'Me toca emitirla — pendiente', 'malka-crm' ),
			'emitida'   => __( 'Ya la emití', 'malka-crm' ),
		);
	}

	/* ─────────────────────────────  Cálculo  ────────────────────────── */

	/**
	 * Descompone un documento en neto, impuesto y total.
	 *
	 * Un solo lugar hace esta cuenta. Si estuviera repetida en la lista, en
	 * el formulario y en el resumen, tarde o temprano una de las tres se
	 * quedaría atrás.
	 *
	 * @param string $tipo Tipo.
	 * @param float  $base Lo que ella escribió.
	 * @return array {neto, iva, retencion, total, recibe, signo}
	 */
	public static function desglose( $tipo, $base ) {
		$base = (float) $base;
		$iva  = (float) Malka_CRM_Settings::get( 'iva', 19 );
		$ret  = (float) Malka_CRM_Settings::get( 'retencion', 15.25 );

		$out = array(
			'neto'      => 0,
			'iva'       => 0,
			'retencion' => 0,
			'total'     => 0,
			'recibe'    => 0,
			'signo'     => 1,
		);

		switch ( $tipo ) {
			case 'boleta':
				// El total es lo escrito: el IVA se saca de adentro.
				$out['total'] = (int) round( $base );
				$out['neto']  = (int) round( $base / ( 1 + ( $iva / 100 ) ) );
				$out['iva']   = $out['total'] - $out['neto'];
				break;

			case 'honorarios':
				// Honorarios no llevan IVA; la retención se descuenta.
				$out['neto']      = (int) round( $base );
				$out['total']     = (int) round( $base );
				$out['retencion'] = (int) round( $base * ( $ret / 100 ) );
				break;

			case 'exenta':
				// Exenta: no lleva IVA, el total es el monto.
				$out['neto']  = (int) round( $base );
				$out['total'] = (int) round( $base );
				break;

			case 'nota':
				$out['signo'] = -1;
				$out['neto']  = (int) round( $base );
				$out['iva']   = (int) round( $base * ( $iva / 100 ) );
				$out['total'] = $out['neto'] + $out['iva'];
				break;

			default: // factura
				$out['neto']  = (int) round( $base );
				$out['iva']   = (int) round( $base * ( $iva / 100 ) );
				$out['total'] = $out['neto'] + $out['iva'];
		}

		// Lo que de verdad le llega a la cuenta.
		$out['recibe'] = $out['total'] - $out['retencion'];

		return $out;
	}

	/* ───────────────────────────  Lectura  ──────────────────────────── */

	/**
	 * Un documento, con su desglose ya hecho.
	 *
	 * @param int $id Documento.
	 * @return array
	 */
	public static function get( $id ) {
		$tipo = (string) get_post_meta( $id, self::META_TIPO, true );
		$tipo = array_key_exists( $tipo, self::tipos() ) ? $tipo : 'factura';
		$base = (float) get_post_meta( $id, self::META_BASE, true );

		$d = self::desglose( $tipo, $base );

		$d['id']      = (int) $id;
		$d['tipo']    = $tipo;
		$d['rotulo']  = self::tipos()[ $tipo ];
		$d['folio']   = (string) get_post_meta( $id, self::META_FOLIO, true );
		$d['fecha']   = (string) get_post_meta( $id, self::META_FECHA, true );
		$d['cliente'] = self::nombre_bonito( (string) get_post_meta( $id, self::META_CLIENTE, true ) );
		$d['rut']     = (string) get_post_meta( $id, self::META_RUT, true );
		$d['base']    = (int) round( $base );
		$d['estado']  = (string) get_post_meta( $id, self::META_ESTADO, true );
		$d['quote']   = (int) get_post_meta( $id, self::META_QUOTE, true );
		$d['ref']     = (string) get_post_meta( $id, self::META_REF, true );
		$d['notas']   = (string) get_post_meta( $id, self::META_NOTAS, true );

		/*
		 * Sin marca, es una venta: así los documentos de antes de que
		 * existieran las compras siguen contando en el resumen del mes.
		 */
		$sentido = (string) get_post_meta( $id, self::META_SENTIDO, true );
		$d['sentido'] = array_key_exists( $sentido, self::sentidos() ) ? $sentido : 'venta';

		$d['proyecto']  = (int) get_post_meta( $id, self::META_PROYECTO, true );
		$d['periodo']   = (string) get_post_meta( $id, self::META_PERIODO, true );
		$d['emito']      = (string) get_post_meta( $id, self::META_EMITO, true );
		$d['folio_mio']  = (string) get_post_meta( $id, self::META_FOLIO_MIO, true );
		$d['fecha_mia']  = (string) get_post_meta( $id, self::META_FECHA_MIA, true );
		$d['sii_nombre'] = (string) get_post_meta( $id, self::META_SII_NOMBRE, true );
		$d['sii_rut']    = (string) get_post_meta( $id, self::META_SII_RUT, true );
		$d['sii_origen'] = (string) get_post_meta( $id, self::META_SII_ORIGEN, true );
		$d['sii_giro']   = (string) get_post_meta( $id, self::META_SII_GIRO, true );
		$d['sii_dir']    = (string) get_post_meta( $id, self::META_SII_DIR, true );
		$d['sii_comuna'] = (string) get_post_meta( $id, self::META_SII_COMUNA, true );
		$d['sii_ciudad'] = (string) get_post_meta( $id, self::META_SII_CIUDAD, true );
		$d['proveedor_id'] = (int) get_post_meta( $id, self::META_PROVEEDOR_ID, true );
		$d['mov_id']     = (int) get_post_meta( $id, self::META_MOV, true );
		$d['pagado_clp'] = (int) get_post_meta( $id, self::META_PAGADO, true );
		$d['pago_ref']   = (string) get_post_meta( $id, self::META_PAGO, true );
		$d['giro']       = (string) get_post_meta( $id, self::META_GIRO, true );
		$d['dir']        = (string) get_post_meta( $id, self::META_DIR, true );
		$d['comuna']     = (string) get_post_meta( $id, self::META_COMUNA, true );
		$d['ciudad']     = (string) get_post_meta( $id, self::META_CIUDAD, true );
		$d['tc']         = (float) get_post_meta( $id, self::META_TC, true );
		$d['tc_fecha']   = (string) get_post_meta( $id, self::META_TC_FECHA, true );
		$d['moneda']     = (string) get_post_meta( $id, self::META_MONEDA, true );
		$d['original']   = (float) get_post_meta( $id, self::META_ORIGINAL, true );

		// Lo de emitir yo sólo tiene sentido en una compra.
		if ( 'compra' !== $d['sentido'] ) {
			$d['emito']      = '';
			$d['folio_mio']  = '';
			$d['fecha_mia']  = '';
			$d['sii_nombre'] = '';
			$d['sii_rut']    = '';
			$d['sii_giro']   = '';
			$d['sii_dir']    = '';
			$d['sii_comuna'] = '';
			$d['sii_ciudad'] = '';
		}

		if ( ! isset( self::estados( $d['sentido'] )[ $d['estado'] ] ) ) {
			$d['estado'] = ( 'compra' === $d['sentido'] ) ? 'registrada' : 'emitida';
		}

		return $d;
	}

	/**
	 * Cómo va la facturación de un negocio.
	 *
	 * Ella emite la factura recién cuando el saldo está cancelado, así que
	 * lo que hay que ver de un vistazo no es «¿hay factura?» sino «¿ya se
	 * puede y todavía no está?».
	 *
	 * @param int $pid Negocio.
	 * @return array {estado: emitida|falta|espera, cuantos, total}
	 */
	public static function facturacion_de( $pid ) {
		$pid     = (int) $pid;
		$cuantos = 0;
		$total   = 0;

		if ( $pid ) {
			foreach ( self::all() as $d ) {
				if ( (int) $d['proyecto'] !== $pid ) {
					continue;
				}

				// Una compra no factura el negocio, y una anulada tampoco.
				if ( 'venta' !== $d['sentido'] || 'anulada' === $d['estado'] ) {
					continue;
				}

				$cuantos++;
				$total += (int) $d['total'];
			}
		}

		if ( $cuantos > 0 ) {
			return array(
				'estado'  => 'emitida',
				'cuantos' => $cuantos,
				'total'   => $total,
			);
		}

		// Sin documento y marcado «sin factura»: no falta nada.
		$omit = $pid ? get_post_meta( $pid, '_malka_pm_sin_factura', true ) : '';
		if ( is_array( $omit ) && ! empty( $omit['fecha'] ) ) {
			return array(
				'estado'  => 'omitida',
				'cuantos' => 0,
				'total'   => 0,
				'nota'    => (string) $omit['nota'],
			);
		}

		// Sin documento: lo que decide es si el cliente ya pagó todo.
		$qid   = $pid ? (int) get_post_meta( $pid, '_malka_pm_quote_id', true ) : 0;
		$debe  = false;
		$pagos = $qid ? Malka_CRM_Quotes::get_payments( $qid ) : array();

		foreach ( $pagos as $p ) {
			if ( isset( $p['rol'] ) && 'total' === $p['rol'] ) {
				continue;
			}

			if ( ! isset( $p['status'] ) || 'pagado' !== $p['status'] ) {
				$debe = true;
				break;
			}
		}

		return array(
			'estado'  => ( $pagos && ! $debe ) ? 'falta' : 'espera',
			'cuantos' => 0,
			'total'   => 0,
		);
	}

	/**
	 * Todos los documentos, del más nuevo al más viejo.
	 *
	 * @return array[]
	 */
	public static function all() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		// Con más de mil documentos, traer los datos de una vez y no uno por uno.
		update_meta_cache( 'post', $ids );

		$out = array();

		foreach ( $ids as $id ) {
			// Las facturas que subió el módulo viejo no tienen tipo: no son
			// un registro tributario, son un PDF adjunto a un proyecto.
			if ( ! get_post_meta( $id, self::META_TIPO, true ) ) {
				continue;
			}

			$out[] = self::get( $id );
		}

		usort(
			$out,
			function ( $a, $b ) {
				$f = strcmp( (string) $b['fecha'], (string) $a['fecha'] );

				return 0 !== $f ? $f : ( (int) $b['folio'] <=> (int) $a['folio'] );
			}
		);

		return $out;
	}

	/**
	 * Lo emitido en un mes, para pasárselo a la contadora.
	 *
	 * Las anuladas no suman: existen para dejar rastro, no para contarse.
	 *
	 * @param string $mes Y-m. Vacío usa el mes en curso.
	 * @return array {neto, iva, retencion, total, recibe, cuantos, mes}
	 */
	public static function resumen_mes( $mes = '' ) {
		$mes = $mes ? $mes : current_time( 'Y-m' );

		$out = array(
			'mes'       => $mes,
			// Lo vendido: estas claves siguen significando lo mismo de siempre.
			'neto'      => 0,
			'iva'       => 0,
			'retencion' => 0,
			'total'     => 0,
			'recibe'    => 0,
			'cuantos'   => 0,
			// Lo comprado, aparte.
			'c_neto'    => 0,
			'c_iva'     => 0,
			'c_total'   => 0,
			'c_cuantos' => 0,
			// Y lo que queda por pagarle al SII.
			'iva_pagar' => 0,
		);

		foreach ( self::all() as $d ) {
			if ( 'anulada' === $d['estado'] ) {
				continue;
			}

			if ( ! $d['fecha'] || substr( $d['fecha'], 0, 7 ) !== $mes ) {
				continue;
			}

			$s = $d['signo'];

			if ( 'compra' === $d['sentido'] ) {
				$out['c_neto']  += $s * $d['neto'];
				$out['c_iva']   += $s * $d['iva'];
				$out['c_total'] += $s * $d['total'];
				$out['c_cuantos']++;
				continue;
			}

			$out['neto']      += $s * $d['neto'];
			$out['iva']       += $s * $d['iva'];
			$out['retencion'] += $s * $d['retencion'];
			$out['total']     += $s * $d['total'];
			$out['recibe']    += $s * $d['recibe'];
			$out['cuantos']++;
		}

		/*
		 * El IVA del mes es el débito de las ventas menos el crédito de las
		 * compras. Antes de registrar compras esto era igual al IVA de las
		 * ventas, así que el número no cambia para quien no las usa.
		 */
		$out['iva_pagar'] = max( 0, $out['iva'] - $out['c_iva'] );
		// El F29 de verdad (remanente, retenciones y PPM): el de Contabilidad.
		if ( class_exists( 'Malka_CRM_Contabilidad' ) && $mes ) {
			$f29              = Malka_CRM_Contabilidad::mes( $mes );
			$out['iva_pagar'] = max( 0, (int) $f29['total'] );
			$out['f29']       = $f29;
		}

		return $out;
	}

	/**
	 * Los meses que tienen documentos, del más nuevo al más viejo.
	 *
	 * @return string[] Y-m.
	 */
	public static function meses() {
		$out = array();

		foreach ( self::all() as $d ) {
			if ( $d['fecha'] ) {
				$out[ substr( $d['fecha'], 0, 7 ) ] = true;
			}
		}

		$meses = array_keys( $out );
		rsort( $meses );

		return $meses;
	}

	/**
	 * El folio que sigue para un tipo.
	 *
	 * Es una sugerencia, no una regla: los folios los da el SII y ella puede
	 * tener saltos. Se propone el siguiente y se deja editar.
	 *
	 * @param string $tipo Tipo.
	 * @return int 0 si todavía no hay ninguno de ese tipo.
	 */
	public static function proximo_folio( $tipo ) {
		$max = 0;

		foreach ( self::all() as $d ) {
			if ( $d['tipo'] === $tipo && (int) $d['folio'] > $max ) {
				$max = (int) $d['folio'];
			}
		}

		return $max ? $max + 1 : 0;
	}

	/**
	 * ¿Ese folio ya está usado por otro documento del mismo tipo?
	 *
	 * @param string $tipo  Tipo.
	 * @param string $folio Folio.
	 * @param int    $salvo Id que no cuenta (el que se está editando).
	 * @return int Id del que lo ocupa, o 0.
	 */
	public static function folio_ocupado( $tipo, $folio, $salvo = 0 ) {
		$folio = trim( (string) $folio );

		if ( '' === $folio ) {
			return 0;
		}

		foreach ( self::all() as $d ) {
			if ( $d['tipo'] === $tipo && (string) $d['folio'] === $folio && (int) $d['id'] !== (int) $salvo ) {
				return (int) $d['id'];
			}
		}

		return 0;
	}

	/* ──────────────────────────  Guardado  ──────────────────────────── */

	/**
	 * Guarda el documento y vuelve al registro.
	 */
	public static function handle_save() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_editor_doc' );

		$id   = isset( $_POST['doc'] ) ? (int) $_POST['doc'] : 0;
		$tipo = isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : 'factura';
		$tipo = array_key_exists( $tipo, self::tipos() ) ? $tipo : 'factura';

		$folio   = isset( $_POST['folio'] ) ? sanitize_text_field( wp_unslash( $_POST['folio'] ) ) : '';
		$cliente = isset( $_POST['cliente'] ) ? sanitize_text_field( wp_unslash( $_POST['cliente'] ) ) : '';

		$es_compra = isset( $_POST['sentido'] ) && 'compra' === sanitize_key( wp_unslash( $_POST['sentido'] ) );

		$titulo = sprintf(
			/* translators: 1: tipo de documento, 2: folio, 3: «de compra» o nada */
			__( '%1$s N° %2$s%3$s', 'malka-crm' ),
			self::tipos()[ $tipo ],
			$folio ? $folio : '—',
			$es_compra ? __( ' (compra)', 'malka-crm' ) : ''
		);

		if ( $id && self::POST_TYPE === get_post_type( $id ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $titulo,
				)
			);
		} else {
			$id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_title'  => $titulo,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $id ) || ! $id ) {
				wp_die( esc_html__( 'No se pudo guardar el documento.', 'malka-crm' ) );
			}
		}

		$fecha = isset( $_POST['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fecha'] ) ) : '';

		update_post_meta( $id, self::META_TIPO, $tipo );
		update_post_meta( $id, self::META_FOLIO, $folio );
		update_post_meta( $id, self::META_FECHA, $fecha ? $fecha : current_time( 'Y-m-d' ) );
		update_post_meta( $id, self::META_CLIENTE, $cliente );
		update_post_meta( $id, self::META_RUT, isset( $_POST['rut'] ) ? sanitize_text_field( wp_unslash( $_POST['rut'] ) ) : '' );
		// Llega con puntos de miles («1.390.000»): sólo cuentan los dígitos.
		update_post_meta( $id, self::META_BASE, isset( $_POST['base'] ) ? (int) preg_replace( '/\D/', '', (string) wp_unslash( $_POST['base'] ) ) : 0 );
		update_post_meta( $id, self::META_REF, isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '' );
		update_post_meta( $id, self::META_NOTAS, isset( $_POST['notas'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notas'] ) ) : '' );

		$sentido = isset( $_POST['sentido'] ) ? sanitize_key( wp_unslash( $_POST['sentido'] ) ) : 'venta';
		$sentido = array_key_exists( $sentido, self::sentidos() ) ? $sentido : 'venta';
		update_post_meta( $id, self::META_SENTIDO, $sentido );

		$defecto = ( 'compra' === $sentido ) ? 'registrada' : 'emitida';
		$estado  = isset( $_POST['estado'] ) ? sanitize_key( wp_unslash( $_POST['estado'] ) ) : $defecto;
		$estado  = array_key_exists( $estado, self::estados( $sentido ) ) ? $estado : $defecto;
		update_post_meta( $id, self::META_ESTADO, $estado );

		/*
		 * Las de compra que le toca emitir a ella: el folio propio sólo se
		 * guarda si de verdad la emitió. Si no, quedaría un número suelto
		 * diciendo que ya está hecho algo que está pendiente.
		 */
		$emito = isset( $_POST['emito'] ) ? sanitize_key( wp_unslash( $_POST['emito'] ) ) : '';
		$emito = ( 'compra' === $sentido && array_key_exists( $emito, self::emisiones() ) ) ? $emito : '';
		update_post_meta( $id, self::META_EMITO, $emito );

		$folio_mio = ( 'emitida' === $emito && isset( $_POST['folio_mio'] ) )
			? sanitize_text_field( wp_unslash( $_POST['folio_mio'] ) )
			: '';
		update_post_meta( $id, self::META_FOLIO_MIO, $folio_mio );

		// La fecha de emisión se anota antes de emitirla: de ella sale el cambio.
		$fecha_mia = ( '' !== $emito && isset( $_POST['fecha_mia'] ) ) ? sanitize_text_field( wp_unslash( $_POST['fecha_mia'] ) ) : '';
		update_post_meta( $id, self::META_FECHA_MIA, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha_mia ) ? $fecha_mia : '' );

		// Los datos del SII se guardan mientras le toque emitirla, pendiente o no:
		// se anotan antes, justamente para tenerlos a mano al emitir.
		update_post_meta( $id, self::META_SII_NOMBRE, ( '' !== $emito && isset( $_POST['sii_nombre'] ) ) ? sanitize_text_field( wp_unslash( $_POST['sii_nombre'] ) ) : '' );
		update_post_meta( $id, self::META_SII_RUT, ( '' !== $emito && isset( $_POST['sii_rut'] ) ) ? sanitize_text_field( wp_unslash( $_POST['sii_rut'] ) ) : '' );
		$origen = isset( $_POST['sii_origen'] ) ? sanitize_key( wp_unslash( $_POST['sii_origen'] ) ) : '';
		update_post_meta( $id, self::META_SII_ORIGEN, in_array( $origen, array( 'previo', 'sii', 'generico', 'mano' ), true ) ? $origen : '' );
		update_post_meta( $id, self::META_SII_GIRO, ( '' !== $emito && isset( $_POST['sii_giro'] ) ) ? sanitize_text_field( wp_unslash( $_POST['sii_giro'] ) ) : '' );
		foreach ( array(
			'sii_dir'    => self::META_SII_DIR,
			'sii_comuna' => self::META_SII_COMUNA,
			'sii_ciudad' => self::META_SII_CIUDAD,
			'giro'       => self::META_GIRO,
			'dir'        => self::META_DIR,
			'comuna'     => self::META_COMUNA,
			'ciudad'     => self::META_CIUDAD,
		) as $campo => $meta ) {
			update_post_meta( $id, $meta, ( '' !== $emito && isset( $_POST[ $campo ] ) ) ? sanitize_text_field( wp_unslash( $_POST[ $campo ] ) ) : '' );
		}

		/*
		 * Una factura del extranjero: el neto en pesos es el monto original
		 * por el tipo de cambio del Banco Central del día de emisión. Se
		 * calcula acá y no se escribe a mano, para que no se descuadre.
		 */
		if ( '' !== $emito && isset( $_POST['moneda'] ) ) {
			$moneda   = strtoupper( sanitize_key( wp_unslash( $_POST['moneda'] ) ) );
			$moneda   = in_array( $moneda, array( 'USD', 'EUR', 'CLP' ), true ) ? $moneda : 'CLP';
			$original = isset( $_POST['original'] ) ? self::numero_libre( wp_unslash( $_POST['original'] ) ) : 0;
			update_post_meta( $id, self::META_MONEDA, $moneda );
			update_post_meta( $id, self::META_ORIGINAL, $original );

			if ( 'CLP' !== $moneda && $original > 0 ) {
				$dia = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha_mia ) ? $fecha_mia : ( $fecha ? $fecha : current_time( 'Y-m-d' ) );
				$tc  = isset( $_POST['tc'] ) ? self::numero_libre( wp_unslash( $_POST['tc'] ) ) : 0;
				$de  = isset( $_POST['tc_fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['tc_fecha'] ) ) : '';

				// Si no vino o es de otro día, se pregunta al Banco Central.
				if ( $tc <= 0 && class_exists( 'Malka_CRM_SII' ) ) {
					$bc = Malka_CRM_SII::tipo_cambio( $moneda, $dia );
					if ( $bc ) {
						$tc = $bc['valor'];
						$de = $bc['fecha'];
					}
				}

				update_post_meta( $id, self::META_TC, $tc );
				update_post_meta( $id, self::META_TC_FECHA, $de );
				if ( $tc > 0 ) {
					update_post_meta( $id, self::META_BASE, (int) round( $original * $tc ) );
				}
			}
		}

		if ( isset( $_POST['pagado_clp'] ) ) {
			update_post_meta( $id, self::META_PAGADO, (int) preg_replace( '/\D/', '', (string) wp_unslash( $_POST['pagado_clp'] ) ) );
		}

		// Con qué pago o movimiento se pagó.
		if ( isset( $_POST['enlace'] ) ) {
			self::enlazar( $id, sanitize_text_field( wp_unslash( $_POST['enlace'] ) ) );
		}

		// Lo corregido acá vuelve a la ficha del proveedor, para la próxima.
		if ( '' !== $emito && class_exists( 'Malka_CRM_Providers' ) ) {
			$pid = Malka_CRM_Providers::desde_documento( self::get( $id ) );
			if ( $pid ) {
				update_post_meta( $id, self::META_PROVEEDOR_ID, $pid );
			}
		}

		update_post_meta( $id, self::META_PROYECTO, isset( $_POST['proyecto'] ) ? (int) $_POST['proyecto'] : 0 );

		$quote = isset( $_POST['quote'] ) ? (int) $_POST['quote'] : 0;
		update_post_meta( $id, self::META_QUOTE, $quote );

		// Una compra no respalda una cotización propia: no toca su estado.
		if ( 'venta' === $sentido ) {
			self::sync_quote( $id, $quote, $tipo, $folio, $estado );
		}

		$aviso = self::guardar_pdf( $id );

		// El guardado automático espera datos, no una página.
		if ( ! empty( $_POST['_auto'] ) ) {
			self::responder_auto( $id, $aviso );
		}

		$volver = Malka_CRM_Dashboard::view_url( 'documentos' );

		// Desde la ventana emergente de un negocio: se cierra sola.
		if ( ! empty( $_POST['embed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( add_query_arg( array( 'embed' => '1', 'cerrar' => '1' ), self::edit_url( $id ) ) );
			exit;
		}

		/*
		 * Si el archivo falló, se vuelve a la ficha y no a la lista: el resto
		 * del documento ya quedó guardado, pero mandarla a la lista con un
		 * aviso de que el PDF no subió la obliga a buscar cuál era.
		 */
		if ( '' !== $aviso && 'ok' !== $aviso ) {
			wp_safe_redirect( add_query_arg( 'pdf', $aviso, self::edit_url( $id ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'guardado', '1', $volver ) );
		exit;
	}

	/**
	 * Deja la cotización al día con el documento que la respalda.
	 *
	 * Es el punto de la cosa: anotar la factura acá tiene que apagar el
	 * «No emitida» que el cliente ve en su cotización.
	 *
	 * @param int    $id     Documento.
	 * @param int    $quote  Cotización, o 0.
	 * @param string $tipo   Tipo.
	 * @param string $folio  Folio.
	 * @param string $estado Estado.
	 */
	private static function sync_quote( $id, $quote, $tipo, $folio, $estado ) {
		if ( ! $quote || ! class_exists( 'Malka_CRM_Quotes' ) || Malka_CRM_Quotes::POST_TYPE !== get_post_type( $quote ) ) {
			return;
		}

		// Una nota de crédito no emite: deshace.
		if ( 'nota' === $tipo || 'anulada' === $estado ) {
			update_post_meta( $quote, '_malka_pm_quote_dte_status', 'anulada' );
			return;
		}

		update_post_meta( $quote, '_malka_pm_quote_dte_status', 'emitida' );
		update_post_meta( $quote, '_malka_pm_quote_dte_number', $folio );
	}

	/**
	 * Manda un documento a la papelera.
	 */
	/**
	 * Manda un documento a la papelera sin salir de la página.
	 */
	public static function ajax_borrar() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}
		$id = isset( $_POST['doc'] ) ? absint( $_POST['doc'] ) : 0;
		check_ajax_referer( 'malka_crm_delete_doc_' . $id, 'nonce' );
		if ( ! $id || self::POST_TYPE !== get_post_type( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'No encontré ese documento.', 'malka-crm' ) ), 404 );
		}
		wp_trash_post( $id );
		wp_send_json_success();
	}

	public static function handle_delete() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		$id = isset( $_GET['doc'] ) ? (int) $_GET['doc'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'malka_crm_delete_doc_' . $id );

		if ( ! $id || self::POST_TYPE !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Ese documento no existe.', 'malka-crm' ) );
		}

		wp_trash_post( $id );

		// Vuelve a la pestaña desde donde se borró.
		$ver  = isset( $_GET['ver'] ) ? sanitize_key( wp_unslash( $_GET['ver'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args = array( 'borrado' => '1' );
		if ( in_array( $ver, array( 'venta', 'compra', 'emitir', 'emitidas' ), true ) ) {
			$args['ver'] = $ver;
		}

		wp_safe_redirect( add_query_arg( $args, Malka_CRM_Dashboard::view_url( 'documentos' ) ) );
		exit;
	}

	/* ─────────────────────────────  Rutas  ──────────────────────────── */

	/**
	 * URL del formulario.
	 *
	 * @param int    $id   Documento, o 0 para uno nuevo.
	 * @param string $tipo Tipo con que arranca uno nuevo.
	 * @return string
	 */
	public static function edit_url( $id = 0, $tipo = '' ) {
		$base = Malka_CRM_Dashboard::view_url( 'documento' );

		if ( $id ) {
			return add_query_arg( 'id', (int) $id, $base );
		}

		return $tipo ? add_query_arg( 'tipo', $tipo, $base ) : $base;
	}

	/**
	 * URL para mandar un documento a la papelera.
	 *
	 * @param int $id Documento.
	 * @return string
	 */
	public static function delete_url( $id, $ver = '' ) {
		return wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action' => 'malka_crm_delete_doc',
						'doc'    => (int) $id,
						'ver'    => $ver,
					)
				),
				admin_url( 'admin-post.php' )
			),
			'malka_crm_delete_doc_' . (int) $id
		);
	}


	/* ────────────────────────────  Pantallas  ───────────────────────── */

	/**
	 * El registro: resumen del mes arriba y la lista abajo.
	 *
	 * @return string
	 */
	public static function render_list() {
		$todos = self::all();
		$meses = self::meses();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$mes  = isset( $_GET['mes'] ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : '';
		$tipo = isset( $_GET['tipo'] ) ? sanitize_key( wp_unslash( $_GET['tipo'] ) ) : '';
		$ver  = isset( $_GET['ver'] ) ? sanitize_key( wp_unslash( $_GET['ver'] ) ) : 'venta';
		$ok   = isset( $_GET['guardado'] );
		$del  = isset( $_GET['borrado'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$mes  = preg_match( '/^\d{4}-\d{2}$/', $mes ) ? $mes : ( $meses ? $meses[0] : current_time( 'Y-m' ) );
		$tipo = array_key_exists( $tipo, self::tipos() ) ? $tipo : '';

		// venta · compra · emitir (las de compra que le toca emitir a ella).
		$ver = in_array( $ver, array( 'venta', 'compra', 'emitir', 'emitidas' ), true ) ? $ver : 'venta';
		// Las dos bandejas de facturas de compra se arman igual.
		$es_em = in_array( $ver, array( 'emitir', 'emitidas' ), true );

		ob_start();
		echo '<div class="esc-cot esc-vista--total">';

		if ( $ok ) {
			// Si el PDF hubo que achicarlo para que cupiera, se dice acá: no
			// es un error, pero tampoco algo que deba pasar a escondidas.
			$nota = self::nota();

			printf(
				'<p class="esc-ok">%s%s</p>',
				esc_html__( 'Documento guardado.', 'malka-crm' ),
				! empty( $nota['texto'] ) ? ' ' . esc_html( $nota['texto'] ) : ''
			);
		}
		if ( $del ) {
			echo '<p class="esc-ok">' . esc_html__( 'El documento quedó en la papelera.', 'malka-crm' ) . '</p>';
		}

		echo self::panel_mes( $mes, $meses ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/* ── Lista ── */
		echo '<section class="esc-panel">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Todos los documentos', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Del más nuevo al más antiguo', 'malka-crm' ) . '</p></div>';
		printf(
			'<div class="esc-ph__r"><button type="button" class="esc-btn esc-btn--soft" data-imp-abrir>%3$s</button><a class="esc-btn esc-btn--primary" href="%1$s">+ %2$s</a></div></div>',
			esc_url( self::edit_url( 0, $tipo ) ),
			esc_html__( 'Anotar documento', 'malka-crm' ),
			esc_html__( 'Importar del SII', 'malka-crm' )
		);
		echo self::dialogo_importar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! $todos && ! $es_em ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no has anotado ninguno. Acá van las facturas, boletas y notas de crédito que emites mientras el SII no esté conectado.', 'malka-crm' ) . '</p>';
			echo '</section></div>';
			return ob_get_clean();
		}

		echo '<div class="esc-nl__bar">';
		echo self::chips_ver( $todos, $ver, $tipo, $mes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $es_em ) {
			echo self::chips_tipo( $todos, $tipo, $mes, $ver ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';

		if ( $es_em ) {
			echo self::zona_extranjero(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$filas = array();

		foreach ( $todos as $d ) {
			if ( $tipo && $d['tipo'] !== $tipo ) {
				continue;
			}

			if ( 'emitir' === $ver ) {
				// Lo que le falta emitir a ella.
				if ( 'pendiente' !== $d['emito'] ) {
					continue;
				}
			} elseif ( 'emitidas' === $ver ) {
				// Las facturas de compra que ya emitió el estudio.
				if ( 'emitida' !== $d['emito'] ) {
					continue;
				}
			} elseif ( 'compra' === $ver ) {
				// Lo que le facturan sus proveedores: sin las que emite ella.
				if ( 'compra' !== $d['sentido'] || '' !== $d['emito'] ) {
					continue;
				}
			} elseif ( $d['sentido'] !== $ver ) {
				continue;
			}

			$filas[] = $d;
		}

		if ( 'emitidas' === $ver ) {
			// Las emitidas, de la última a la primera por su número.
			usort(
				$filas,
				function ( $a, $b ) {
					return ( (int) $b['folio_mio'] <=> (int) $a['folio_mio'] ) ?: strcmp( (string) $b['fecha'], (string) $a['fecha'] );
				}
			);
		} elseif ( 'emitir' === $ver ) {
			/*
			 * En el SII los folios van correlativos según la fecha: la N° 19
			 * no puede tener fecha anterior a la N° 18. Así que se emiten de la
			 * fecha más antigua a la más nueva, y a cada una se le dice su turno,
			 * el número que le toca y si su fecha quedó antes de la última emitida.
			 */
			usort(
				$filas,
				function ( $a, $b ) {
					$fa = $a['fecha_mia'] ? $a['fecha_mia'] : $a['fecha'];
					$fb = $b['fecha_mia'] ? $b['fecha_mia'] : $b['fecha'];
					return strcmp( (string) $fa, (string) $fb ) ?: ( (int) $a['id'] <=> (int) $b['id'] );
				}
			);
			$ult = self::ultima_emitida( $todos );
			foreach ( $filas as $i => $d ) {
				$filas[ $i ]['_turno']    = $i + 1;
				$filas[ $i ]['_sugerido'] = $ult['folio'] ? $ult['folio'] + $i + 1 : 0;
				$fem                      = $d['fecha_mia'] ? $d['fecha_mia'] : $d['fecha'];
				$filas[ $i ]['_antes']    = ( $ult['fecha'] && $fem && $fem < $ult['fecha'] ) ? $ult : null;
			}
		}

		if ( ! $filas ) {
			echo '<p class="esc-empty">' . esc_html( self::vacio( $ver, (bool) $tipo ) ) . '</p>';
			echo '</section></div>';
			return ob_get_clean();
		}

		echo '<div class="esc-nl__list">';

		if ( $es_em ) {
			// La factura real a la izquierda y la que se emite a la derecha.
			$chips = 'emitidas' === $ver
				? array(
					''    => __( 'Todas', 'malka-crm' ),
					'con' => __( 'Con su factura del extranjero', 'malka-crm' ),
					'sin' => __( 'Sin factura del extranjero', 'malka-crm' ),
				)
				: array(
					''       => __( 'Todas', 'malka-crm' ),
					'faltan' => __( 'Faltan datos', 'malka-crm' ),
					'lista'  => __( 'Listas para emitir', 'malka-crm' ),
				);
			printf(
				'<div class="esc-em__barra" id="esc-em-barra"><div class="esc-em__fil" role="group" aria-label="%1$s">%2$s</div><span class="esc-em__der"><button type="button" class="esc-em__todas" id="esc-em-todas" data-ocultas="0">%5$s</button><label class="esc-em__ord"><span>%3$s</span><select id="esc-em-ord" data-guarda="malka-em-orden-%6$s">%4$s</select></label></span></div>',
				esc_attr__( 'Filtrar por estado', 'malka-crm' ),
				implode(
					'',
					array_map(
						function ( $k, $v ) {
							return sprintf( '<button type="button" class="esc-em__chip%1$s" data-estado="%2$s">%3$s</button>', '' === $k ? ' is-on' : '', esc_attr( $k ), esc_html( $v ) );
						},
						array_keys( $chips ),
						array_values( $chips )
					)
				),
				esc_html__( 'Ordenar por', 'malka-crm' ),
				implode(
					'',
					array(
						'emitir' === $ver ? '<option value="emision">' . esc_html__( 'Orden para emitir (fecha de emisión)', 'malka-crm' ) . '</option>' : '<option value="folio">' . esc_html__( 'N° de factura, la última primero', 'malka-crm' ) . '</option>',
						'<option value="estado">' . esc_html__( 'Estado (lo pendiente primero)', 'malka-crm' ) . '</option>',
						'<option value="fecha_desc">' . esc_html__( 'Fecha, más nueva primero', 'malka-crm' ) . '</option>',
						'<option value="fecha_asc">' . esc_html__( 'Fecha, más antigua primero', 'malka-crm' ) . '</option>',
						'<option value="empresa">' . esc_html__( 'Empresa, A–Z', 'malka-crm' ) . '</option>',
					)
				),
				esc_html__( 'Ocultar todos los detalles', 'malka-crm' ),
				esc_attr( $ver )
			);
			echo '<div id="esc-em-lista">';
			if ( 'emitir' === $ver && count( $filas ) > 1 ) {
				echo '<p class="esc-em__hint esc-em__hint--orden">' . esc_html__( 'Van en el orden en que hay que emitirlas en el SII: de la fecha de emisión más antigua a la más nueva, porque los números tienen que ir correlativos según la fecha.', 'malka-crm' ) . '</p>';
			}
			echo '<p class="esc-em__hint">' . esc_html__( 'Todo se corrige escribiendo encima y se guarda al salir del campo. El ícono junto a cada dato del SII lo copia.', 'malka-crm' ) . '</p>';

			foreach ( $filas as $d ) {
				// Una pendiente sin datos del SII cuyo proveedor ya tiene ficha
				// (porque la ficha se creó después) se completa al mostrarla.
				if ( 'pendiente' === $d['emito'] && '' === $d['sii_nombre'] && '' === $d['sii_rut'] && '' !== trim( (string) $d['cliente'] ) ) {
					self::completar_desde_proveedor( $d['id'], false );
					$d = array_merge( $d, self::get( $d['id'] ) );
				}
				echo self::fila_emito( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '<p class="esc-em__nada" hidden>' . esc_html__( 'Ninguna factura en ese estado.', 'malka-crm' ) . '</p>';
			echo '</div></div></section></div>';

			return ob_get_clean();
		}

		echo '<div class="esc-nl esc-nl--h esc-dl" aria-hidden="true">' .
			'<span>' . esc_html__( 'Fecha', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Documento', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html( 'compra' === $ver || $es_em ? __( 'Proveedor', 'malka-crm' ) : __( 'Cliente', 'malka-crm' ) ) . '</span>' .
			'<span>' . esc_html__( 'Neto', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'IVA', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Total', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Estado', 'malka-crm' ) . '</span></div>';

		/*
		 * De a 40: con cientos de documentos importados la página medía
		 * metros. El resto queda en bloques que se abren con «Ver más».
		 */
		$bloque = 40;
		foreach ( array_values( $filas ) as $i => $d ) {
			if ( $i > 0 && 0 === $i % $bloque ) {
				echo $i > $bloque ? '</div>' : '';
				echo '<div class="esc-dl__mas" hidden>';
			}
			echo self::fila( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( count( $filas ) > $bloque ) {
			echo '</div>';
			printf(
				'<div class="esc-dl__pie"><button type="button" class="esc-btn esc-btn--soft" data-dl-mas>%1$s</button><span>%2$s</span></div>',
				esc_html__( 'Ver más', 'malka-crm' ),
				esc_html( sprintf( /* translators: 1: mostrados, 2: total */ __( 'Mostrando %1$d de %2$d', 'malka-crm' ), $bloque, count( $filas ) ) )
			);
		}

		echo '</div></section></div>';

		return ob_get_clean();
	}

	/**
	 * El resumen del mes, que es lo que le pasa a su contadora.
	 *
	 * @param string   $mes   Y-m.
	 * @param string[] $meses Meses con documentos.
	 * @return string
	 */
	private static function panel_mes( $mes, $meses ) {
		$r = self::resumen_mes( $mes );

		ob_start();
		echo '<section class="esc-panel esc-cot__m">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Lo emitido este mes', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Las anuladas no suman y las notas de crédito restan', 'malka-crm' ) . '</p></div>';

		if ( count( $meses ) > 1 ) {
			printf(
				'<div class="esc-ph__r"><form method="get" action="%s" class="esc-orden">',
				esc_url( Malka_CRM_Dashboard::view_url( 'documentos' ) )
			);
			echo '<select name="mes" onchange="this.form.submit()">';
			foreach ( $meses as $m ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $m ),
					selected( $mes, $m, false ),
					esc_html( ucfirst( date_i18n( 'F Y', strtotime( $m . '-01' ) ) ) )
				);
			}
			echo '</select>';
			printf( '<noscript><button type="submit" class="esc-mini">%s</button></noscript>', esc_html__( 'Ver', 'malka-crm' ) );
			echo '</form></div>';
		}

		echo '</div>';

		if ( ! $r['cuantos'] ) {
			echo '<p class="esc-empty">' . esc_html__( 'Nada emitido en este mes.', 'malka-crm' ) . '</p></section>';
			return ob_get_clean();
		}

		echo '<div class="esc-cifras">';
		Malka_CRM_Dashboard::cifra_publica( Malka_CRM_Settings::money( $r['neto'] ), __( 'Neto', 'malka-crm' ), sprintf( /* translators: cantidad */ _n( '%d documento', '%d documentos', $r['cuantos'], 'malka-crm' ), $r['cuantos'] ) );
		Malka_CRM_Dashboard::cifra_publica( Malka_CRM_Settings::money( $r['iva'] ), __( 'IVA', 'malka-crm' ), __( 'Lo que le debes al SII', 'malka-crm' ) );

		if ( $r['retencion'] ) {
			Malka_CRM_Dashboard::cifra_publica( Malka_CRM_Settings::money( $r['retencion'] ), __( 'Retenido', 'malka-crm' ), sprintf( /* translators: porcentaje */ __( '%s%% de las boletas de honorarios', 'malka-crm' ), (float) Malka_CRM_Settings::get( 'retencion', 15.25 ) ) );
		}

		Malka_CRM_Dashboard::cifra_publica( Malka_CRM_Settings::money( $r['recibe'] ), __( 'A tu cuenta', 'malka-crm' ), __( 'Total menos la retención', 'malka-crm' ) );
		echo '</div>';

		/*
		 * El número que de verdad se paga en el F29 es el débito menos el
		 * crédito. Mientras no haya compras anotadas es el mismo IVA de
		 * arriba, así que sólo aparece cuando hay algo que restar.
		 */
		if ( $r['c_cuantos'] > 0 || ! empty( $r['f29'] ) ) {
			$f   = ! empty( $r['f29'] ) ? $r['f29'] : null;
			$deb = $f ? (int) $f['debito'] : (int) $r['iva'];
			$cre = $f ? (int) $f['credito'] + (int) $f['remanente_ant'] : (int) $r['c_iva'];
			$otr = $f ? (int) $f['total'] - (int) $f['iva'] : 0;
			echo '<div class="esc-iva">';
			printf(
				'<span>%1$s <b>%2$s</b></span><span>%3$s <b>−%4$s</b></span>%5$s<span class="esc-iva__r">%6$s <b>%7$s</b></span>',
				esc_html__( 'IVA de tus ventas', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $deb ) ),
				esc_html__( 'Crédito y remanente', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $cre ) ),
				$otr > 0 ? '<span>' . esc_html__( 'PPM y retenciones', 'malka-crm' ) . ' <b>+' . esc_html( Malka_CRM_Settings::money( $otr ) ) . '</b></span>' : '',
				esc_html__( 'A pagar en el F29', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $r['iva_pagar'] ) )
			);
			echo '</div>';
			printf(
				'<p class="esc-hint">%s</p>',
				esc_html(
					sprintf(
						/* translators: cuántas compras */
						_n( 'Con %d compra anotada este mes.', 'Con %d compras anotadas este mes.', $r['c_cuantos'], 'malka-crm' ),
						$r['c_cuantos']
					)
				)
			);
		}

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Qué decir cuando no hay nada que listar.
	 *
	 * @param string $ver   Subsección.
	 * @param bool   $filtro Si además hay un tipo filtrado.
	 * @return string
	 */
	private static function vacio( $ver, $filtro ) {
		if ( $filtro ) {
			return __( 'No hay documentos de ese tipo acá.', 'malka-crm' );
		}

		if ( 'compra' === $ver ) {
			return __( 'Todavía no has anotado compras. Acá van las facturas y boletas que te emiten a ti, para la contabilidad.', 'malka-crm' );
		}

		if ( 'emitir' === $ver ) {
			return __( 'Nada pendiente. Arrastra arriba las facturas del extranjero, o marca en una compra que la factura la emites tú.', 'malka-crm' );
		}

		if ( 'emitidas' === $ver ) {
			return __( 'Todavía no hay facturas de compra emitidas. Aparecen cuando escribes el número al emitirla o cuando las importas del SII.', 'malka-crm' );
		}

		return __( 'Todavía no has anotado ventas.', 'malka-crm' );
	}

	/**
	 * Ventas, compras y las que emito yo.
	 *
	 * @param array[] $todos Documentos.
	 * @param string  $ver   Subsección activa.
	 * @param string  $tipo  Tipo activo.
	 * @param string  $mes   Mes activo.
	 * @return string
	 */
	private static function chips_ver( $todos, $ver, $tipo, $mes ) {
		$ventas   = 0;
		$compras  = 0;
		$emitir   = 0;
		$emitidas = 0;

		foreach ( $todos as $d ) {
			if ( 'pendiente' === $d['emito'] ) {
				$emitir++;
			} elseif ( 'emitida' === $d['emito'] ) {
				$emitidas++;
			} elseif ( 'compra' === $d['sentido'] ) {
				$compras++;
			} else {
				$ventas++;
			}
		}

		$base = add_query_arg( 'mes', $mes, Malka_CRM_Dashboard::view_url( 'documentos' ) );

		if ( $tipo ) {
			$base = add_query_arg( 'tipo', $tipo, $base );
		}

		$pestanas = array(
			'venta'  => array( __( 'Ventas', 'malka-crm' ), $ventas, false ),
			'compra' => array( __( 'Compras', 'malka-crm' ), $compras, false ),
			// El contador de esta cuenta lo pendiente, no el total: es una
			// bandeja de pendientes, no un archivo.
			'emitir'   => array( __( 'Facturas de compra por emitir', 'malka-crm' ), $emitir, true ),
			// Las que ya emitió el estudio, aparte de lo que le facturan a ella.
			'emitidas' => array( __( 'Facturas de compra emitidas', 'malka-crm' ), $emitidas, false ),
		);

		// Las mismas pestañas grises del resto del escritorio.
		$out = '<div class="esc-nl__tabs esc-dl__ver">';

		foreach ( $pestanas as $k => $p ) {
			list( $rotulo, $n, $alerta ) = $p;

			$out .= sprintf(
				'<a class="esc-nl__tab%1$s" href="%2$s">%3$s%4$s</a>',
				$ver === $k ? ' is-on' : '',
				esc_url( add_query_arg( 'ver', $k, $base ) ),
				esc_html( $rotulo ),
				$n > 0
					? ( ( $alerta && $n > 0 ) ? ' <b class="esc-fin__badge is-warn">' . esc_html( (string) $n ) . '</b>' : ' <i>' . esc_html( (string) $n ) . '</i>' )
					: ''
			);
		}

		return $out . '</div>';
	}

	/**
	 * Las pestañas por tipo, con cuántos hay de cada uno.
	 *
	 * @param array[] $todos Documentos.
	 * @param string  $tipo  Tipo activo.
	 * @param string  $mes   Mes activo, para conservarlo al filtrar.
	 * @return string
	 */
	private static function chips_tipo( $todos, $tipo, $mes, $ver = 'venta' ) {
		$cuenta = array();

		foreach ( $todos as $d ) {
			// Cada subsección cuenta lo suyo: si no, los números de arriba
			// prometen documentos que la lista de abajo no muestra.
			if ( 'emitir' === $ver ) {
				if ( '' === $d['emito'] ) {
					continue;
				}
			} elseif ( $d['sentido'] !== $ver || ( 'compra' === $ver && '' !== $d['emito'] ) ) {
				continue;
			}

			$k            = $d['tipo'];
			$cuenta[ $k ] = isset( $cuenta[ $k ] ) ? $cuenta[ $k ] + 1 : 1;
		}

		$base = add_query_arg(
			array(
				'mes' => $mes,
				'ver' => $ver,
			),
			Malka_CRM_Dashboard::view_url( 'documentos' )
		);

		ob_start();
		echo '<div class="esc-dl__tipos">';

		$opciones = array( '' => __( 'Todos', 'malka-crm' ) ) + self::tipos();

		foreach ( $opciones as $k => $rotulo ) {
			if ( '' !== $k && empty( $cuenta[ $k ] ) ) {
				continue;
			}

			printf(
				'<a class="%1$s" href="%2$s"%3$s>%4$s <i>%5$d</i></a>',
				$tipo === $k ? 'is-on' : '',
				esc_url( $k ? add_query_arg( 'tipo', $k, $base ) : $base ),
				$tipo === $k ? ' aria-current="true"' : '',
				esc_html( $rotulo ),
				// «Todos» es todos los de esta subsección, no del archivo entero.
				$k ? (int) $cuenta[ $k ] : array_sum( $cuenta )
			);
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Una fila del registro.
	 *
	 * @param array $d Documento.
	 * @return string
	 */
	private static function fila( $d ) {
		/*
		 * Lo que falta por emitir se avisa en ámbar: es lo único de esta
		 * tabla que le pide algo a ella. Lo anulado se apaga.
		 */
		if ( 'pendiente' === $d['emito'] ) {
			$tono = 'wait';
		} elseif ( 'anulada' === $d['estado'] ) {
			$tono = 'off';
		} elseif ( 'emitida' === $d['emito'] ) {
			$tono = 'done';
		} else {
			$tono = 'run';
		}

		/*
		 * La fila entera abre el documento (un enlace estirado sobre la
		 * fecha). El lápiz y la × se fueron: editar es abrirlo, y la
		 * papelera vive dentro del documento, como en el resto.
		 */
		ob_start();
		printf(
			'<div class="esc-nl esc-dl%1$s">' .
			'<span class="esc-dl__f"><a class="esc-ql__a" href="%2$s">%3$s</a></span>' .
			'<span class="esc-nl__p"><b>%4$s%14$s</b><span>%5$s</span></span>' .
			'<span class="esc-nl__p"><b>%6$s</b><span>%7$s</span></span>' .
			'<span class="esc-dl__m">%8$s</span>' .
			'<span class="esc-dl__m">%9$s</span>' .
			'<span class="esc-dl__m esc-dl__t">%10$s</span>' .
			'<span><span class="esc-st esc-st--%11$s">%12$s</span></span>' .
			'%13$s</div>',
			'anulada' === $d['estado'] ? ' is-anulada' : '',
			esc_url( self::edit_url( $d['id'] ) ),
			esc_html( $d['fecha'] ? date_i18n( 'd/m/Y', strtotime( $d['fecha'] ) ) : '—' ),
			// Arriba el número, que es lo que se busca; abajo el tipo.
			esc_html( $d['folio'] ? 'N° ' . $d['folio'] : __( 'Sin folio', 'malka-crm' ) ),
			esc_html( $d['rotulo'] ),
			esc_html( $d['cliente'] ? $d['cliente'] : '—' ),
			esc_html( $d['rut'] ),
			esc_html( ( $d['signo'] < 0 ? '−' : '' ) . Malka_CRM_Settings::money( $d['neto'] ) ),
			esc_html( $d['iva'] ? ( $d['signo'] < 0 ? '−' : '' ) . Malka_CRM_Settings::money( $d['iva'] ) : '—' ),
			esc_html( ( $d['signo'] < 0 ? '−' : '' ) . Malka_CRM_Settings::money( $d['total'] ) ),
			esc_attr( $tono ),
			esc_html( self::rotulo_estado( $d ) ),
			'',
			self::sello_pdf( $d['id'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		return ob_get_clean();
	}

	/**
	 * Un número escrito como sea: «59.99», «59,99», «1.234,5» o «955,2».
	 *
	 * @param string $v Valor.
	 * @return float
	 */
	public static function numero_libre( $v ) {
		$v = preg_replace( '/[^0-9.,]/', '', (string) $v );
		$c = strrpos( $v, ',' );
		$p = strrpos( $v, '.' );

		if ( false !== $c && ( false === $p || $c > $p ) ) {
			$v = str_replace( '.', '', $v );
			$v = str_replace( ',', '.', $v );
		} else {
			$v = str_replace( ',', '', $v );
		}

		return (float) $v;
	}

	/**
	 * Los datos del SII de esta compra, o los de la última con el mismo
	 * proveedor si todavía no tiene.
	 *
	 * @param array $d Documento.
	 * @return array {nombre, rut, de} «de» es la fecha de donde se tomaron.
	 */
	private static function sii_anterior( $d ) {
		$out = array(
			'nombre' => $d['sii_nombre'],
			'rut'    => $d['sii_rut'],
			'giro'   => $d['sii_giro'],
			'dir'    => $d['sii_dir'],
			'comuna' => $d['sii_comuna'],
			'ciudad' => $d['sii_ciudad'],
			'de'     => '',
		);

		if ( '' !== $out['nombre'] || '' !== $out['rut'] || '' === trim( (string) $d['cliente'] ) ) {
			return $out;
		}

		// Primero la ficha del proveedor: es la referencia.
		if ( class_exists( 'Malka_CRM_Providers' ) ) {
			$pid = $d['proveedor_id'] ? $d['proveedor_id'] : Malka_CRM_Providers::buscar( $d['cliente'] );
			if ( $pid ) {
				$p = Malka_CRM_Providers::get( $pid );
				if ( '' !== $p['sii_nombre'] || '' !== $p['sii_rut'] ) {
					return array(
						'nombre' => $p['sii_nombre'],
						'rut'    => $p['sii_rut'],
						'giro'   => $p['sii_giro'],
						'dir'    => $p['sii_dir'],
						'comuna' => $p['sii_comuna'],
						'ciudad' => $p['sii_ciudad'],
						'de'     => __( 'ficha del proveedor', 'malka-crm' ),
						'ficha'  => $p,
					);
				}
			}
		}

		$quien = function_exists( 'remove_accents' ) ? strtolower( remove_accents( trim( $d['cliente'] ) ) ) : strtolower( trim( $d['cliente'] ) );

		foreach ( self::all() as $o ) {
			if ( (int) $o['id'] === (int) $d['id'] || 'compra' !== $o['sentido'] || ( '' === $o['sii_nombre'] && '' === $o['sii_rut'] ) ) {
				continue;
			}
			$otro = function_exists( 'remove_accents' ) ? strtolower( remove_accents( trim( $o['cliente'] ) ) ) : strtolower( trim( $o['cliente'] ) );
			if ( $otro === $quien ) {
				// all() viene del más nuevo al más antiguo: el primero es el último.
				return array(
					'nombre' => $o['sii_nombre'],
					'rut'    => $o['sii_rut'],
					'giro'   => $o['sii_giro'],
					'dir'    => $o['sii_dir'],
					'comuna' => $o['sii_comuna'],
					'ciudad' => $o['sii_ciudad'],
					'de'     => $o['fecha'] ? date_i18n( 'd/m/Y', strtotime( $o['fecha'] ) ) : '',
				);
			}
		}

		return $out;
	}

	/**
	 * El cuadro donde se arrastran las facturas del extranjero.
	 *
	 * @return string
	 */
	private static function zona_extranjero() {
		return sprintf(
			'<label class="esc-drop esc-drop--ext" data-drop-ext data-ajax="%1$s" data-nonce="%2$s">' .
			'<input type="file" accept="application/pdf,.pdf" multiple hidden />' .
			'<span class="esc-drop__ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4"/><path d="m7 9 5-5 5 5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg></span>' .
			'<span class="esc-drop__t"><b>%3$s</b><span>%4$s</span></span></label>' .
			'<p class="esc-drop__msg" data-drop-ext-msg hidden></p>',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'malka_crm_doc_ext' ) ),
			esc_html__( 'Arrastra aquí las facturas del extranjero', 'malka-crm' ),
			esc_html__( 'Una o varias, en PDF. Se leen el proveedor, el número, la fecha y el monto, y se buscan sus datos en el SII.', 'malka-crm' )
		);
	}

	/**
	 * La frase que dice de dónde salieron los datos del SII.
	 *
	 * @param string $origen previo|sii|generico|mano.
	 * @param string $de     Fecha de la factura anterior, si viene de ahí.
	 * @return string
	 */
	public static function nota_origen( $origen, $de = '' ) {
		switch ( $origen ) {
			case 'previo':
				return __( 'De la ficha del proveedor. Si corriges algo acá, la ficha se actualiza para la próxima.', 'malka-crm' );
			case 'sii':
				return __( 'Encontrado en la nómina de prestadores extranjeros del SII.', 'malka-crm' );
			case 'generico':
				return __( 'No aparece inscrito en el SII: se emite con el RUT genérico 55.555.555-5.', 'malka-crm' );
			case 'mano':
				return __( 'Escritos a mano.', 'malka-crm' );
		}

		return __( 'Como aparece el proveedor en el SII, que puede no ser su nombre real.', 'malka-crm' );
	}

	/**
	 * Una factura del extranjero arrastrada a «Facturas de compra por emitir».
	 *
	 * Llega el PDF y lo que el navegador alcanzó a leer de él. Se anota como
	 * compra que le toca emitir a ella, con los datos del SII de la última vez
	 * o, si es un proveedor nuevo, los de la nómina del SII.
	 */
	public static function ajax_extranjero() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array( 'msg' => __( 'Sin permiso.', 'malka-crm' ) ), 403 );
		}
		check_ajax_referer( 'malka_crm_doc_ext', 'nonce' );

		$txt = function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		};

		$proveedor = $txt( 'proveedor' );
		$folio     = $txt( 'folio' );
		$fecha     = Malka_CRM_Helpers::sanitize_date( $txt( 'fecha' ) );
		$moneda    = strtoupper( $txt( 'moneda' ) );
		$moneda    = in_array( $moneda, array( 'USD', 'EUR', 'CLP' ), true ) ? $moneda : 'USD';
		$original  = (float) str_replace( ',', '.', $txt( 'monto' ) );
		$direccion = $txt( 'direccion' );
		$comuna    = $txt( 'comuna' );
		$ciudad    = $txt( 'ciudad' );
		$dia       = $fecha ? $fecha : current_time( 'Y-m-d' );

		// El cambio del Banco Central del día de la factura.
		$tc = null;
		if ( 'CLP' !== $moneda && class_exists( 'Malka_CRM_SII' ) ) {
			$tc = Malka_CRM_SII::tipo_cambio( $moneda, $dia );
		}
		$tasa = 'CLP' === $moneda ? 1 : ( $tc ? $tc['valor'] : 0 );
		$base = $original > 0 && $tasa > 0 ? (int) round( $original * $tasa ) : 0;

		/*
		 * Antes de anotarla: ¿ya estaba, o ya tiene su factura de compra
		 * emitida (importada del SII)? Para saberlo hace falta el RUT con que
		 * se le emite: el de su ficha, el de la última vez o el de la nómina.
		 */
		$rut_sii = '';
		$previo  = self::sii_anterior(
			array(
				'id'           => 0,
				'cliente'      => $proveedor,
				'proveedor_id' => 0,
				'sii_nombre'   => '',
				'sii_rut'      => '',
				'sii_giro'     => '',
				'sii_dir'      => '',
				'sii_comuna'   => '',
				'sii_ciudad'   => '',
			)
		);
		if ( '' !== $previo['rut'] ) {
			$rut_sii = $previo['rut'];
		} elseif ( $proveedor && class_exists( 'Malka_CRM_SII' ) ) {
			$nomina  = Malka_CRM_SII::datos_para( $proveedor );
			$rut_sii = $nomina && ! empty( $nomina['rut'] ) ? $nomina['rut'] : '';
		}
		$hay = self::buscar_extranjera( $proveedor, $folio, $dia, $base, $rut_sii, $original, $moneda );

		if ( 'ya' === $hay['tipo'] ) {
			$o = $hay['doc'];
			wp_send_json_success(
				array(
					'id'   => (int) $o['id'],
					'url'  => self::edit_url( $o['id'] ),
					'ya'   => true,
					'nota' => 'emitida' === $o['emito']
						/* translators: 1: proveedor, 2: su número, 3: número de la factura de compra */
						? sprintf( __( '%1$s N° %2$s ya estaba, con su factura de compra N° %3$s.', 'malka-crm' ), $o['cliente'], $o['folio'], $o['folio_mio'] ? $o['folio_mio'] : '—' )
						/* translators: 1: proveedor, 2: su número */
						: sprintf( __( '%1$s N° %2$s ya estaba anotada, por emitir.', 'malka-crm' ), $o['cliente'], $o['folio'] ),
				)
			);
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => sprintf( /* translators: 1: folio, 2: proveedor */ __( 'Factura del extranjero N° %1$s — %2$s', 'malka-crm' ), $folio ? $folio : '—', $proveedor ? $proveedor : '?' ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			wp_send_json_error( array( 'msg' => __( 'No se pudo anotar.', 'malka-crm' ) ) );
		}

		update_post_meta( $id, self::META_TIPO, 'factura' );
		update_post_meta( $id, self::META_SENTIDO, 'compra' );
		update_post_meta( $id, self::META_ESTADO, 'registrada' );
		update_post_meta( $id, self::META_EMITO, 'pendiente' );
		update_post_meta( $id, self::META_FOLIO, $folio );
		update_post_meta( $id, self::META_FECHA, $fecha ? $fecha : current_time( 'Y-m-d' ) );
		update_post_meta( $id, self::META_CLIENTE, $proveedor );
		update_post_meta( $id, self::META_RUT, $txt( 'rut' ) );
		update_post_meta( $id, self::META_BASE, $base );
		update_post_meta( $id, self::META_MONEDA, $moneda );
		update_post_meta( $id, self::META_ORIGINAL, $original );
		update_post_meta( $id, self::META_FECHA_MIA, $dia );
		if ( $tc ) {
			update_post_meta( $id, self::META_TC, $tc['valor'] );
			update_post_meta( $id, self::META_TC_FECHA, $tc['fecha'] );
		}

		// Los datos del SII: los de la ficha del proveedor, o los de la nómina.
		$d   = self::get( $id );
		$sii = self::sii_anterior( $d );
		$org = '';
		if ( $sii['de'] ) {
			$org = 'previo';
			// Lo que el PDF no trajo, se completa desde la ficha.
			if ( ! empty( $sii['ficha'] ) ) {
				$f         = $sii['ficha'];
				$direccion = '' !== $direccion ? $direccion : $f['dir'];
				$comuna    = '' !== $comuna ? $comuna : $f['comuna'];
				$ciudad    = '' !== $ciudad ? $ciudad : $f['ciudad'];
				update_post_meta( $id, self::META_PROVEEDOR_ID, (int) $f['id'] );
				if ( '' === $txt( 'rut' ) && '' !== $f['tax'] ) {
					update_post_meta( $id, self::META_RUT, $f['tax'] );
				}
				if ( '' !== $f['giro'] ) {
					update_post_meta( $id, self::META_GIRO, $f['giro'] );
				}
			}
		} elseif ( $proveedor && class_exists( 'Malka_CRM_SII' ) ) {
			$nuevo = Malka_CRM_SII::datos_para( $proveedor );
			if ( $nuevo ) {
				$sii = $nuevo;
				$org = $nuevo['origen'];
			}
		}
		if ( $org ) {
			update_post_meta( $id, self::META_SII_NOMBRE, $sii['nombre'] );
			update_post_meta( $id, self::META_SII_RUT, $sii['rut'] );
			update_post_meta( $id, self::META_SII_ORIGEN, $org );
		}
		// Giro y dirección: los de la última vez; si no hay, la dirección que
		// trae su factura, para partir de algo.
		update_post_meta( $id, self::META_DIR, $direccion );
		update_post_meta( $id, self::META_COMUNA, $comuna );
		update_post_meta( $id, self::META_CIUDAD, $ciudad );
		update_post_meta( $id, self::META_SII_GIRO, ! empty( $sii['giro'] ) ? $sii['giro'] : '' );
		$previa = ! empty( $sii['dir'] );
		update_post_meta( $id, self::META_SII_DIR, $previa ? $sii['dir'] : $direccion );
		update_post_meta( $id, self::META_SII_COMUNA, $previa ? ( isset( $sii['comuna'] ) ? $sii['comuna'] : '' ) : $comuna );
		update_post_meta( $id, self::META_SII_CIUDAD, $previa ? ( isset( $sii['ciudad'] ) ? $sii['ciudad'] : '' ) : $ciudad );

		$aviso = self::guardar_pdf( $id );

		// El pago del estudio de ese mes que corresponde a este proveedor.
		self::enlazar_solo( $id );

		// Si es un proveedor nuevo, queda su ficha creada desde esta factura.
		if ( class_exists( 'Malka_CRM_Providers' ) ) {
			$pid = Malka_CRM_Providers::desde_documento( self::get( $id ) );
			if ( $pid ) {
				update_post_meta( $id, self::META_PROVEEDOR_ID, $pid );
			}
		}

		// Si parece ya emitida, no se junta sola: se le pregunta en la lista.
		$nota = '';
		if ( 'enlazar' === $hay['tipo'] ) {
			update_post_meta( $id, self::META_PROPUESTA, (int) $hay['doc']['id'] );
			/* translators: 1: proveedor, 2: su número, 3: número de la factura de compra */
			$nota = sprintf( __( '%1$s N° %2$s parece ya emitida como la factura de compra N° %3$s: confírmalo en la lista.', 'malka-crm' ), $proveedor ? $proveedor : '?', $folio ? $folio : '—', $hay['doc']['folio_mio'] ? $hay['doc']['folio_mio'] : '—' );
		}

		wp_send_json_success(
			array(
				'id'    => (int) $id,
				'url'   => self::edit_url( $id ),
				'sii'   => $org,
				'aviso' => is_string( $aviso ) ? $aviso : '',
				'nota'  => $nota,
			)
		);
	}

	/**
	 * Un nombre con la primera letra de cada palabra en mayúscula.
	 *
	 * «MAGDALENA MUALIM JOYERÍA» → «Magdalena Mualim Joyería». Las siglas
	 * societarias (SpA, S.A., LLC…) se dejan como se escriben.
	 *
	 * @param string $nombre Nombre tal como vino.
	 * @return string
	 */
	public static function nombre_bonito( $nombre ) {
		$nombre = trim( preg_replace( '/\s+/u', ' ', (string) $nombre ) );
		if ( '' === $nombre ) {
			return '';
		}
		$siglas = array(
			'spa'   => 'SpA',
			's.a.'  => 'S.A.',
			'sa'    => 'S.A.',
			's.a'   => 'S.A.',
			'llc'   => 'LLC',
			'llp'   => 'LLP',
			'sl'    => 'SL',
			'eirl'  => 'EIRL',
			'e.i.r.l.' => 'E.I.R.L.',
			'ltda'  => 'Ltda',
			'ltda.' => 'Ltda.',
			'inc'   => 'Inc',
			'inc.'  => 'Inc.',
			'ltd'   => 'Ltd',
			'ltd.'  => 'Ltd.',
			'opco,' => 'OpCo,',
			'opco'  => 'OpCo',
		);
		$out = array();
		foreach ( explode( ' ', $nombre ) as $p ) {
			$k = function_exists( 'mb_strtolower' ) ? mb_strtolower( $p, 'UTF-8' ) : strtolower( $p );
			if ( isset( $siglas[ $k ] ) ) {
				$out[] = $siglas[ $k ];
				continue;
			}
			$out[] = function_exists( 'mb_convert_case' ) ? mb_convert_case( $k, MB_CASE_TITLE, 'UTF-8' ) : ucwords( $k );
		}
		$txt = implode( ' ', $out );

		// Las formas societarias largas se abrevian: ocupaban tres líneas en las listas.
		$txt = preg_replace(
			array(
				'/\s+Empresa Individual De Responsabilidad Limitada$/u',
				'/\s+Sociedad Por Acciones$/u',
				'/\s+Sociedad Anónima$/u',
				'/\s+Limitada$/u',
			),
			array( ' EIRL', ' SpA', ' S.A.', ' Ltda.' ),
			$txt
		);
		return $txt;
	}

	/**
	 * El cuadro para importar el listado de documentos del SII.
	 *
	 * @return string
	 */
	public static function dialogo_importar() {
		return sprintf(
			'<dialog class="esc-imp" id="esc-imp" data-ajax="%1$s" data-nonce="%2$s">' .
			'<div class="esc-imp__cab"><div><h3>%3$s</h3><p>%4$s</p></div><button type="button" class="esc-imp__x" data-imp-cerrar aria-label="%5$s">×</button></div>' .
			'<label class="esc-drop" data-imp-drop><input type="file" accept=".pdf,.txt,.csv,.tsv,.xls,.xlsx,application/pdf,text/plain,text/csv,application/vnd.ms-excel" multiple hidden />' .
			'<span class="esc-drop__ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4"/><path d="m7 9 5-5 5 5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg></span>' .
			'<span class="esc-drop__t"><b>%6$s</b><span>%7$s</span></span></label>' .
			'<p class="esc-imp__msg" data-imp-msg hidden></p>' .
			'<div class="esc-imp__res" data-imp-res hidden></div>' .
			'<div class="esc-imp__pie" data-imp-pie hidden><span data-imp-cuenta></span><button type="button" class="esc-btn esc-btn--primary" data-imp-ok>%8$s</button></div>' .
			'</dialog>',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'malka_crm_doc_importar' ) ),
			esc_html__( 'Importar documentos del SII', 'malka-crm' ),
			esc_html__( 'El listado de «Administración de documentos» o del Registro de Compras y Ventas. Lo que ya está anotado no se duplica.', 'malka-crm' ),
			esc_attr__( 'Cerrar', 'malka-crm' ),
			esc_html__( 'Arrastra aquí los archivos del SII: XLS, TXT, CSV o PDF', 'malka-crm' ),
			esc_html__( 'Puedes soltar varios a la vez, de emitidos y de recibidos. Antes de guardar ves qué es nuevo y qué ya estaba.', 'malka-crm' ),
			esc_html__( 'Importar', 'malka-crm' )
		);
	}

	/**
	 * Sólo dígitos y K de un RUT, para comparar.
	 *
	 * @param string $rut RUT en cualquier formato.
	 * @return string
	 */
	private static function rut_llave( $rut ) {
		return strtoupper( preg_replace( '/[^0-9kK]/', '', (string) $rut ) );
	}

	/**
	 * El neto que, con su IVA encima, da justo el total del SII.
	 *
	 * @param int $total Total con IVA.
	 * @return int
	 */
	private static function neto_de_total( $total ) {
		$iva = (float) Malka_CRM_Settings::get( 'iva', 19 ) / 100;
		$n   = (int) round( $total / ( 1 + $iva ) );
		foreach ( array( $n, $n - 1, $n + 1, $n - 2, $n + 2 ) as $c ) {
			if ( $c + (int) round( $c * $iva ) === (int) $total ) {
				return $c;
			}
		}
		return $n;
	}

	/**
	 * Qué hacer con un renglón del listado del SII.
	 *
	 * @param array  $f     Renglón {rut,nombre,doc,folio,fecha,monto,estado}.
	 * @param string $lado  emitidos|recibidos.
	 * @param array  $todos Documentos ya anotados.
	 * @return array {accion,tipo,sentido,emito,base,id,motivo}
	 */
	private static function clasificar_sii( $f, $lado, $todos ) {
		$doc   = strtolower( remove_accents( (string) $f['doc'] ) );
		$total = (int) $f['monto'];
		$folio = ltrim( (string) $f['folio'], '0' );
		$rut   = self::rut_llave( $f['rut'] );
		$r     = array(
			'accion'  => 'nuevo',
			'tipo'    => '',
			'sentido' => 'emitidos' === $lado ? 'venta' : 'compra',
			'emito'   => '',
			'base'    => 0,
			'id'      => 0,
			'motivo'  => '',
		);

		if ( false !== strpos( $doc, 'compra' ) ) {
			// La factura de compra la emite ella por el proveedor: va a «Facturas de compra por emitir».
			$r['tipo']    = 'factura';
			$r['sentido'] = 'compra';
			$r['emito']   = 'emitida';
			$r['base']    = self::neto_de_total( $total );
		} elseif ( false !== strpos( $doc, 'exenta' ) ) {
			$r['tipo'] = 'exenta';
			$r['base'] = $total;
		} elseif ( false !== strpos( $doc, 'credito' ) ) {
			$r['tipo'] = 'nota';
			$r['base'] = self::neto_de_total( $total );
		} elseif ( false !== strpos( $doc, 'boleta' ) && false !== strpos( $doc, 'honorario' ) ) {
			$r['tipo'] = 'honorarios';
			$r['base'] = $total;
		} elseif ( false !== strpos( $doc, 'boleta' ) ) {
			$r['tipo'] = 'boleta';
			$r['base'] = $total;
		} elseif ( false !== strpos( $doc, 'factura' ) ) {
			$r['tipo'] = 'factura';
			$r['base'] = self::neto_de_total( $total );
		} else {
			$r['accion'] = 'omitir';
			$r['motivo'] = __( 'Este tipo de documento no se lleva acá.', 'malka-crm' );
			return $r;
		}

		// Si el archivo trae el neto (o el exento), vale ese y no el calculado.
		if ( in_array( $r['tipo'], array( 'factura', 'nota' ), true ) && ! empty( $f['neto'] ) ) {
			$r['base'] = (int) $f['neto'];
		} elseif ( 'exenta' === $r['tipo'] && ! empty( $f['exento'] ) ) {
			$r['base'] = (int) $f['exento'];
		}

		foreach ( $todos as $d ) {
			if ( 'emitida' === $r['emito'] ) {
				if ( '' === $d['emito'] ) {
					continue;
				}
				if ( ltrim( (string) $d['folio_mio'], '0' ) === $folio ) {
					$r['accion'] = self::otro_periodo( $d, $f ) ? 'periodo' : 'duplicado';
					$r['id']     = (int) $d['id'];
					return $r;
				}
				continue;
			}
			if ( $d['sentido'] !== $r['sentido'] || $d['tipo'] !== $r['tipo'] || '' !== $d['emito'] ) {
				continue;
			}
			if ( ltrim( (string) $d['folio'], '0' ) !== $folio ) {
				continue;
			}
			// En ventas el folio es mío y no se repite; en compras puede
			// repetirse entre proveedores, así que pesa el RUT (o la fecha si no lo tiene).
			$drut = self::rut_llave( $d['rut'] );
			if ( 'venta' === $r['sentido'] || $drut === $rut || ( '' === $drut && $d['fecha'] === $f['fecha'] ) ) {
				$r['accion'] = self::otro_periodo( $d, $f ) ? 'periodo' : 'duplicado';
				$r['id']     = (int) $d['id'];
				return $r;
			}
		}

		// Una factura de compra que estaba pendiente: se marca emitida con su número.
		if ( 'emitida' === $r['emito'] ) {
			$cand = array();
			foreach ( $todos as $d ) {
				if ( 'pendiente' === $d['emito'] && self::rut_llave( $d['sii_rut'] ) === $rut ) {
					$cand[] = $d;
				}
			}
			$mejor = null;
			foreach ( $cand as $d ) {
				$dia = $d['fecha_mia'] ? $d['fecha_mia'] : $d['fecha'];
				$dif = abs( strtotime( $dia ) - strtotime( $f['fecha'] ) ) / DAY_IN_SECONDS;
				if ( $dif <= 10 && ( ! $mejor || $dif < $mejor[1] ) ) {
					$mejor = array( $d, $dif );
				}
			}
			if ( $mejor ) {
				$r['accion'] = 'emitir';
				$r['id']     = (int) $mejor[0]['id'];
			}
		}

		return $r;
	}

	/**
	 * Si el libro pone el documento en otro mes que el que tiene.
	 *
	 * @param array $d Documento anotado.
	 * @param array $f Renglón.
	 * @return bool
	 */
	private static function otro_periodo( $d, $f ) {
		if ( empty( $f['periodo'] ) ) {
			return false;
		}
		return self::periodo_de( $d ) !== $f['periodo'];
	}

	/**
	 * El mes en que se declara un documento.
	 *
	 * @param array $d Documento.
	 * @return string Y-m.
	 */
	public static function periodo_de( $d ) {
		if ( ! empty( $d['periodo'] ) ) {
			return $d['periodo'];
		}
		return substr( ( '' !== $d['emito'] && $d['fecha_mia'] ) ? $d['fecha_mia'] : $d['fecha'], 0, 7 );
	}

	/**
	 * Revisa o importa los renglones del listado del SII.
	 */
	public static function ajax_importar() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array( 'msg' => __( 'Sin permiso.', 'malka-crm' ) ), 403 );
		}
		check_ajax_referer( 'malka_crm_doc_importar', 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$lado   = isset( $_POST['lado'] ) && 'recibidos' === $_POST['lado'] ? 'recibidos' : 'emitidos';
		$probar = ! empty( $_POST['probar'] );
		$filas  = isset( $_POST['filas'] ) ? json_decode( wp_unslash( $_POST['filas'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$elige  = isset( $_POST['elegidas'] ) ? array_map( 'intval', (array) json_decode( wp_unslash( $_POST['elegidas'] ), true ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! is_array( $filas ) ) {
			wp_send_json_error( array( 'msg' => __( 'No se pudo leer el listado.', 'malka-crm' ) ) );
		}

		$todos = self::all();
		$out   = array();
		$hecho = 0;
		$vistos = array();

		foreach ( array_values( $filas ) as $i => $f ) {
			$f = array(
				'rut'    => sanitize_text_field( isset( $f['rut'] ) ? $f['rut'] : '' ),
				'nombre' => sanitize_text_field( isset( $f['nombre'] ) ? $f['nombre'] : '' ),
				'doc'    => sanitize_text_field( isset( $f['doc'] ) ? $f['doc'] : '' ),
				'folio'  => preg_replace( '/\D/', '', isset( $f['folio'] ) ? (string) $f['folio'] : '' ),
				'fecha'  => Malka_CRM_Helpers::sanitize_date( isset( $f['fecha'] ) ? $f['fecha'] : '' ),
				'monto'  => (int) preg_replace( '/\D/', '', isset( $f['monto'] ) ? (string) $f['monto'] : '' ),
				'estado' => sanitize_text_field( isset( $f['estado'] ) ? $f['estado'] : '' ),
				// La descarga de documentos trae el desglose y los datos de la contraparte.
				'neto'   => isset( $f['neto'] ) && '' !== (string) $f['neto'] ? (int) preg_replace( '/[^\d-]/', '', (string) $f['neto'] ) : null,
				'exento' => isset( $f['exento'] ) && '' !== (string) $f['exento'] ? (int) preg_replace( '/[^\d-]/', '', (string) $f['exento'] ) : null,
				'giro'   => sanitize_text_field( isset( $f['giro'] ) ? $f['giro'] : '' ),
				'dir'    => sanitize_text_field( isset( $f['dir'] ) ? $f['dir'] : '' ),
				'comuna' => sanitize_text_field( isset( $f['comuna'] ) ? $f['comuna'] : '' ),
				'ciudad' => sanitize_text_field( isset( $f['ciudad'] ) ? $f['ciudad'] : '' ),
				// El libro de la contadora dice en qué mes se declara cada documento.
				'periodo' => isset( $f['periodo'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $f['periodo'] ) ? (string) $f['periodo'] : '',
			);
			$c = self::clasificar_sii( $f, $lado, $todos );

			// El mismo renglón dos veces en el PDF (o en los dos PDF) cuenta una vez.
			$llave = $c['sentido'] . '|' . $c['tipo'] . '|' . $c['emito'] . '|' . $f['folio'] . '|' . self::rut_llave( $f['rut'] );
			if ( 'omitir' !== $c['accion'] && isset( $vistos[ $llave ] ) ) {
				$c['accion'] = 'repetido';
			}
			$vistos[ $llave ] = 1;

			$fila = array(
				'i'       => $i,
				'accion'  => $c['accion'],
				'rotulo'  => $c['tipo'] ? ( 'emitida' === $c['emito'] ? __( 'Factura de compra', 'malka-crm' ) : self::tipos()[ $c['tipo'] ] ) : $f['doc'],
				'lado'    => 'emitida' === $c['emito'] ? __( 'De compra por emitir', 'malka-crm' ) : ( 'venta' === $c['sentido'] ? __( 'Venta', 'malka-crm' ) : __( 'Compra', 'malka-crm' ) ),
				'nombre'  => self::nombre_bonito( $f['nombre'] ),
				'rut'     => class_exists( 'Malka_CRM_SII' ) ? Malka_CRM_SII::rut_bonito( $f['rut'] ) : $f['rut'],
				'folio'   => $f['folio'],
				'fecha'   => $f['fecha'],
				'total'   => $f['monto'],
				'motivo'  => $c['motivo'],
				'url'     => $c['id'] ? self::edit_url( $c['id'] ) : '',
				'id'      => (int) $c['id'],
			);

			$quiere = null === $elige ? true : in_array( $i, $elige, true );
			if ( 'periodo' === $c['accion'] ) {
				$fila['motivo'] = sprintf( /* translators: mes */ __( 'Ya estaba; se declara en %s según el libro.', 'malka-crm' ), date_i18n( 'F Y', strtotime( $f['periodo'] . '-01' ) ) );
			}
			if ( ! $probar && $quiere && 'periodo' === $c['accion'] && $c['id'] ) {
				update_post_meta( $c['id'], self::META_PERIODO, $f['periodo'] );
				$hecho++;
			}
			if ( ! $probar && $quiere && in_array( $c['accion'], array( 'nuevo', 'emitir' ), true ) ) {
				$id = 'emitir' === $c['accion'] ? $c['id'] : self::crear_desde_sii( $f, $c );
				if ( 'emitir' === $c['accion'] ) {
					update_post_meta( $id, self::META_EMITO, 'emitida' );
					update_post_meta( $id, self::META_FOLIO_MIO, $f['folio'] );
					if ( ! get_post_meta( $id, self::META_FECHA_MIA, true ) ) {
						update_post_meta( $id, self::META_FECHA_MIA, $f['fecha'] );
					}
				}
				if ( $id ) {
					$hecho++;
					$fila['url'] = self::edit_url( $id );
					if ( $f['periodo'] ) {
						update_post_meta( $id, self::META_PERIODO, $f['periodo'] );
					}
				}
			}
			$out[] = $fila;
		}

		/*
		 * Si es el libro de la contadora, lo que está anotado en ese mes y
		 * ella no puso en el libro no se declara ahí: casi siempre es algo
		 * que llegó tarde y entra al mes siguiente. Se propone moverlo.
		 */
		$per_libro = '';
		foreach ( $filas as $f0 ) {
			if ( ! empty( $f0['periodo'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $f0['periodo'] ) ) {
				$per_libro = (string) $f0['periodo'];
				break;
			}
		}
		if ( $per_libro ) {
			$sentido = 'recibidos' === $lado ? 'compra' : 'venta';
			$vistos_id = array();
			foreach ( $out as $o ) {
				if ( $o['id'] ) {
					$vistos_id[ $o['id'] ] = 1;
				}
			}
			$siguiente = gmdate( 'Y-m', strtotime( $per_libro . '-01 +1 month' ) );
			$k = count( $filas );
			foreach ( $todos as $d ) {
				if ( $d['sentido'] !== $sentido || 'anulada' === $d['estado'] || isset( $vistos_id[ $d['id'] ] ) ) {
					continue;
				}
				if ( 0 === (int) $d['total'] && 0 === (int) $d['neto'] ) {
					continue;
				}
				if ( self::periodo_de( $d ) !== $per_libro ) {
					continue;
				}
				$fila = array(
					'i'      => $k,
					'accion' => 'fuera',
					'rotulo' => '' !== $d['emito'] ? __( 'Factura de compra', 'malka-crm' ) : $d['rotulo'],
					'lado'   => 'compra' === $sentido ? __( 'Compra', 'malka-crm' ) : __( 'Venta', 'malka-crm' ),
					'nombre' => $d['cliente'],
					'rut'    => $d['rut'],
					'folio'  => '' !== $d['emito'] ? $d['folio_mio'] : $d['folio'],
					'fecha'  => $d['fecha'],
					'total'  => (int) $d['total'],
					'motivo' => sprintf( /* translators: 1: mes del libro, 2: mes siguiente */ __( 'No está en el libro de %1$s: pasa a %2$s.', 'malka-crm' ), date_i18n( 'F', strtotime( $per_libro . '-01' ) ), date_i18n( 'F', strtotime( $siguiente . '-01' ) ) ),
					'url'    => self::edit_url( $d['id'] ),
					'id'     => (int) $d['id'],
				);
				$quiere = null === $elige ? true : in_array( $k, $elige, true );
				if ( ! $probar && $quiere ) {
					update_post_meta( $d['id'], self::META_PERIODO, $siguiente );
					$hecho++;
				}
				$out[] = $fila;
				$k++;
			}
		}

		wp_send_json_success(
			array(
				'filas'  => $out,
				'hechos' => $hecho,
			)
		);
	}

	/**
	 * Crea un documento a partir de un renglón del SII.
	 *
	 * @param array $f Renglón limpio.
	 * @param array $c Clasificación.
	 * @return int Id, o 0.
	 */
	private static function crear_desde_sii( $f, $c ) {
		$nombre = self::nombre_bonito( $f['nombre'] );
		$rut    = class_exists( 'Malka_CRM_SII' ) ? Malka_CRM_SII::rut_bonito( $f['rut'] ) : $f['rut'];
		$compra = 'compra' === $c['sentido'];
		$titulo = sprintf(
			/* translators: 1: tipo de documento, 2: folio, 3: «de compra» o nada */
			__( '%1$s N° %2$s%3$s', 'malka-crm' ),
			self::tipos()[ $c['tipo'] ],
			$f['folio'] ? $f['folio'] : '—',
			$compra ? __( ' (compra)', 'malka-crm' ) : ''
		);
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $titulo,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		update_post_meta( $id, self::META_TIPO, $c['tipo'] );
		update_post_meta( $id, self::META_SENTIDO, $c['sentido'] );
		update_post_meta( $id, self::META_FECHA, $f['fecha'] ? $f['fecha'] : current_time( 'Y-m-d' ) );
		update_post_meta( $id, self::META_CLIENTE, $nombre );
		update_post_meta( $id, self::META_RUT, $rut );
		update_post_meta( $id, self::META_BASE, (int) $c['base'] );
		update_post_meta( $id, self::META_ESTADO, $compra ? 'registrada' : 'emitida' );
		update_post_meta( $id, self::META_NOTAS, sprintf( /* translators: estado en el SII */ __( 'Importado del SII · %s', 'malka-crm' ), $f['estado'] ) );
		foreach ( array( 'giro' => self::META_GIRO, 'dir' => self::META_DIR, 'comuna' => self::META_COMUNA, 'ciudad' => self::META_CIUDAD ) as $k => $meta ) {
			if ( ! empty( $f[ $k ] ) ) {
				update_post_meta( $id, $meta, $f[ $k ] );
			}
		}

		if ( 'emitida' === $c['emito'] ) {
			// La emití yo: el folio es el mío y los datos del SII son los del receptor.
			update_post_meta( $id, self::META_FOLIO, '' );
			update_post_meta( $id, self::META_EMITO, 'emitida' );
			update_post_meta( $id, self::META_FOLIO_MIO, $f['folio'] );
			update_post_meta( $id, self::META_FECHA_MIA, $f['fecha'] );
			update_post_meta( $id, self::META_SII_NOMBRE, $f['nombre'] );
			update_post_meta( $id, self::META_SII_RUT, $rut );
			update_post_meta( $id, self::META_MONEDA, 'CLP' );
		} else {
			update_post_meta( $id, self::META_FOLIO, $f['folio'] );
		}

		return (int) $id;
	}

	/**
	 * Una fila de «Facturas de compra por emitir»: a la izquierda la factura real
	 * del proveedor, a la derecha la que ella emite en el SII.
	 *
	 * @param array $d Documento.
	 * @return string
	 */
	private static function fila_emito( $d ) {
		$pend    = 'pendiente' === $d['emito'];
		$foraneo = $d['moneda'] && 'CLP' !== $d['moneda'];
		$fem     = $d['fecha_mia'] ? $d['fecha_mia'] : $d['fecha'];

		$faltan = array();
		foreach ( array( 'sii_nombre' => __( 'razón social', 'malka-crm' ), 'sii_rut' => __( 'RUT', 'malka-crm' ), 'sii_giro' => __( 'giro', 'malka-crm' ), 'sii_dir' => __( 'dirección', 'malka-crm' ), 'sii_comuna' => __( 'comuna', 'malka-crm' ), 'sii_ciudad' => __( 'ciudad', 'malka-crm' ) ) as $k => $rot ) {
			if ( '' === trim( (string) $d[ $k ] ) ) {
				$faltan[] = $rot;
			}
		}
		if ( ! $d['base'] ) {
			$faltan[] = __( 'neto', 'malka-crm' );
		}

		if ( ! $pend ) {
			$estado = __( 'Emitida', 'malka-crm' ) . ( $d['folio_mio'] ? ' · N° ' . $d['folio_mio'] : '' );
			$tono   = 'done';
		} elseif ( $faltan ) {
			$estado = __( 'Faltan datos', 'malka-crm' );
			$tono   = 'late';
		} else {
			$estado = __( 'Lista para emitir', 'malka-crm' );
			$tono   = 'wait';
		}

		$dos = function ( $n ) {
			return $n > 0 ? number_format( (float) $n, 2, ',', '.' ) : '';
		};

		// [campo, rótulo, valor, tipo, obligatorio, prefijo, clase]
		$cab = array(
			array( 'cliente', __( 'Proveedor', 'malka-crm' ), $d['cliente'], 'text', false, '', 'esc-em__c--prov' ),
			array( 'folio', __( 'N° factura', 'malka-crm' ), $d['folio'] ),
			array( 'fecha', __( 'Fecha', 'malka-crm' ), $d['fecha'], 'date' ),
			array( 'original', __( 'Precio', 'malka-crm' ), $foraneo ? $dos( $d['original'] ) : (string) $d['base'], 'text', false, 'MONEDA' ),
		);
		$izq = array(
			array( 'rut', __( 'RUT', 'malka-crm' ), $d['rut'] ),
			array( 'giro', __( 'Giro', 'malka-crm' ), $d['giro'], 'text', false, '', 'esc-em__c--2' ),
			array( 'dir', __( 'Dirección', 'malka-crm' ), $d['dir'] ),
			array( 'comuna', __( 'Comuna', 'malka-crm' ), $d['comuna'] ),
			array( 'ciudad', __( 'Ciudad', 'malka-crm' ), $d['ciudad'] ),
		);
		$der = array(
			array( 'sii_nombre', __( 'Razón social', 'malka-crm' ), $d['sii_nombre'], 'text', true, '', 'esc-em__c--2' ),
			array( 'sii_rut', __( 'RUT', 'malka-crm' ), $d['sii_rut'], 'text', true ),
			array( 'sii_giro', __( 'Giro', 'malka-crm' ), $d['sii_giro'], 'text', true, '', 'esc-em__c--3' ),
			array( 'sii_dir', __( 'Dirección', 'malka-crm' ), $d['sii_dir'], 'text', true ),
			array( 'sii_comuna', __( 'Comuna', 'malka-crm' ), $d['sii_comuna'], 'text', true ),
			array( 'sii_ciudad', __( 'Ciudad', 'malka-crm' ), $d['sii_ciudad'], 'text', true ),
		);
		$calc = array(
			array( 'fecha_mia', __( 'Fecha de emisión', 'malka-crm' ), $fem, 'date', true ),
			array( 'tc', __( 'Tipo de cambio', 'malka-crm' ), $foraneo ? $dos( $d['tc'] ) : '', 'text', $foraneo, '$' ),
		);

		$moneda_fila = $foraneo ? $d['moneda'] : 'CLP';
		$campos = function ( $lista, $copiar ) use ( $moneda_fila ) {
			$out = '';
			foreach ( $lista as $f ) {
				$v     = (string) $f[2];
				$tipo  = isset( $f[3] ) ? $f[3] : 'text';
				$falta = ! empty( $f[4] ) && '' === trim( $v );
				$out  .= sprintf(
					'<label class="esc-em__c%1$s%8$s"><span>%2$s</span><span class="esc-em__in">%3$s<input type="%4$s" data-campo="%5$s" value="%6$s" placeholder="—" />%7$s</span></label>',
					$falta ? ' is-falta' : '',
					esc_html( $f[1] ),
					// En el precio, la moneda se elige ahí mismo.
					'MONEDA' === ( isset( $f[5] ) ? $f[5] : '' )
						? '<select class="esc-em__mon" data-campo="moneda" aria-label="' . esc_attr__( 'Moneda', 'malka-crm' ) . '">' . implode(
							'',
							array_map(
								function ( $m ) use ( $moneda_fila ) {
									return sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $m ), selected( $moneda_fila, $m, false ), esc_html( 'CLP' === $m ? '$' : $m ) );
								},
								array( 'USD', 'EUR', 'CLP' )
							)
						) . '</select>'
						: ( ! empty( $f[5] ) ? '<i>' . esc_html( $f[5] ) . '</i>' : '' ),
					esc_attr( $tipo ),
					esc_attr( $f[0] ),
					esc_attr( $v ),
					$copiar ? '<button type="button" class="esc-em__cp" data-copiar-campo title="' . esc_attr__( 'Copiar', 'malka-crm' ) . '" aria-label="' . esc_attr( sprintf( /* translators: campo */ __( 'Copiar %s', 'malka-crm' ), $f[1] ) ) . '"><svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5v-2a1 1 0 00-1-1h-6a1 1 0 00-1 1v6a1 1 0 001 1h2"/></svg></button>' : '',
					! empty( $f[6] ) ? ' ' . esc_attr( $f[6] ) : ''
				);
			}
			return $out;
		};

		// El neto: se calcula, no se escribe. Se copia sin $ ni puntos.
		$neto = sprintf(
			'<div class="esc-em__c esc-em__neto%1$s"><span>%2$s</span><span class="esc-em__in"><b>%3$s</b>%4$s</span><small>%5$s</small></div>',
			$d['base'] ? '' : ' is-falta',
			esc_html__( 'Neto a facturar', 'malka-crm' ),
			esc_html( $d['base'] ? Malka_CRM_Settings::money( $d['base'] ) : '—' ),
			$d['base'] ? '<button type="button" class="esc-em__cp" data-copiar="' . esc_attr( (string) (int) $d['base'] ) . '" title="' . esc_attr__( 'Copiar', 'malka-crm' ) . '"><svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5v-2a1 1 0 00-1-1h-6a1 1 0 00-1 1v6a1 1 0 001 1h2"/></svg></button>' : '',
			esc_html( ( $foraneo && $d['tc'] > 0 && $d['original'] > 0 ) ? $d['moneda'] . ' ' . number_format_i18n( $d['original'], 2 ) . ' × ' . number_format_i18n( $d['tc'], 2 ) : '' )
		);

		// Para corroborar el cambio en la fuente oficial.
		if ( ! $foraneo ) {
			$ver = '<span class="esc-em__tcno">' . esc_html__( 'Precio en pesos. Si la factura viene en dólares o euros, elige la moneda junto al precio y se calcula con el cambio del día.', 'malka-crm' ) . '</span>';
		} else {
			$ver = sprintf(
				'<a class="esc-em__ver" href="%1$s" target="_blank" rel="noopener">%2$s</a>',
				esc_url( self::url_tipo_cambio( $d['moneda'], $d['tc_fecha'] ? $d['tc_fecha'] : $fem ) ),
				esc_html(
					sprintf(
						/* translators: 1: dólar observado o euro, 2: fecha */
						__( 'Ver %1$s del %2$s ↗', 'malka-crm' ),
						'EUR' === $d['moneda'] ? __( 'el euro', 'malka-crm' ) : __( 'el dólar observado', 'malka-crm' ),
						date_i18n( 'd/m', strtotime( $d['tc_fecha'] ? $d['tc_fecha'] : $fem ) )
					)
				)
			);
		}

		$copia_ic = '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg>';

		/*
		 * Arriba, qué factura es (proveedor, número, fecha, precio) y cómo va.
		 * Al medio, a la izquierda lo que dice su factura y cómo se pagó; a la
		 * derecha, con qué datos se emite en el SII y el neto que sale. Abajo,
		 * el número de la factura emitida y las acciones.
		 */
		return sprintf(
			'<div class="esc-em%1$s" data-doc="%2$d" data-fecha="%26$s" data-estado="%27$s" data-empresa="%28$s" data-sub="%30$s" data-emision="%31$s" data-folio="%32$s">' .
			'<div class="esc-em__cab"><div class="esc-em__cabg">%3$s</div>%19$s<span class="esc-st esc-st--%4$s"%5$s>%6$s</span>' .
			'<button type="button" class="esc-em__plegar" data-plegar aria-expanded="true" title="%29$s" aria-label="%29$s"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg></button></div>' .
			'%34$s' .
			'<div class="esc-em__cuerpo">' .
				'<div class="esc-em__lado"><p class="esc-em__h4">%7$s</p><div class="esc-em__g">%8$s</div>' .
				'<p class="esc-em__h4">%9$s</p>%10$s%11$s</div>' .
				'<div class="esc-em__lado esc-em__lado--sii"><p class="esc-em__h4">%12$s<i>%13$s</i></p><div class="esc-em__g">%14$s</div>' .
				'<div class="esc-em__calc">%15$s%16$s</div><div class="esc-em__tc">%17$s</div>%18$s</div>' .
			'</div>' .
			'<div class="esc-em__pie">%33$s<span class="esc-em__acc"><a class="esc-em__abrir" href="%20$s">%21$s</a>' .
			'<a class="esc-ico esc-ico--bin esc-em__bin" href="%22$s" data-confirmar="%23$s" title="%24$s" aria-label="%24$s">%25$s</a></span></div>' .
			'</div>',
			$pend ? '' : ' is-emitida',
			(int) $d['id'],
			$campos( $cab, false ),
			esc_attr( $tono ),
			$faltan ? ' title="' . esc_attr( sprintf( /* translators: lista */ __( 'Falta: %s', 'malka-crm' ), implode( ', ', $faltan ) ) ) . '"' : '',
			esc_html( $estado ),
			esc_html__( 'Su factura', 'malka-crm' ),
			$campos( $izq, false ),
			esc_html__( 'Cómo la pagaste', 'malka-crm' ),
			self::bloque_pagado( $d ),
			'',
			esc_html__( 'Para emitir en el SII', 'malka-crm' ),
			esc_html__( 'el ícono copia el dato', 'malka-crm' ),
			$campos( $der, true ),
			$campos( $calc, true ),
			$neto,
			$ver, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			( ( $pend && $faltan ) ? '<p class="esc-em__falta">' . esc_html( sprintf( /* translators: lista */ __( 'Falta: %s', 'malka-crm' ), implode( ', ', $faltan ) ) ) . '</p>' : '' ) .
				( ( $pend && ! empty( $d['_antes'] ) )
					? '<p class="esc-em__aviso">' . esc_html(
						sprintf(
							/* translators: 1: número de la última emitida, 2: su fecha */
							__( 'Su fecha de emisión queda antes de la N° %1$s (%2$s), la última emitida. En el SII tiene que ir con esa fecha o una posterior.', 'malka-crm' ),
							$d['_antes']['folio'],
							date_i18n( 'd/m/Y', strtotime( $d['_antes']['fecha'] ) )
						)
					) . '</p>'
					: '' ),
			sprintf(
				'%4$s<label class="esc-em__folio"><span>%1$s</span><input type="text" data-campo="folio_mio" value="%2$s" placeholder="%3$s" /></label>',
				esc_html__( 'Nº de factura', 'malka-crm' ),
				esc_attr( $d['folio_mio'] ),
				! empty( $d['_sugerido'] )
					/* translators: número sugerido */
					? esc_attr( sprintf( __( 'Le toca la N° %d', 'malka-crm' ), $d['_sugerido'] ) )
					: esc_attr__( 'Escríbelo al emitirla', 'malka-crm' ),
				( $pend && ! empty( $d['_turno'] ) )
					? '<span class="esc-em__turno" title="' . esc_attr__( 'Orden para emitir en el SII', 'malka-crm' ) . '">' . esc_html( sprintf( /* translators: turno */ __( '%d.ª en emitir', 'malka-crm' ), $d['_turno'] ) ) . '</span>'
					: ''

			),
			esc_url( self::edit_url( $d['id'] ) ),
			esc_html__( 'Abrir documento ›', 'malka-crm' ),
			esc_url( self::delete_url( $d['id'], 'emitir' ) ),
			esc_attr( sprintf( /* translators: 1: proveedor, 2: número */ __( '¿Mandar a la papelera la factura de %1$s%2$s?', 'malka-crm' ), $d['cliente'] ? $d['cliente'] : __( 'este proveedor', 'malka-crm' ), $d['folio'] ? ' N° ' . $d['folio'] : '' ) ),
			esc_attr__( 'Mandar a la papelera', 'malka-crm' ),
			$copia_ic,
			esc_attr( (string) $d['fecha'] ),
			// Para ordenar: primero lo que pide algo.
			esc_attr( ! $pend ? 'emitida' : ( $faltan ? 'faltan' : 'lista' ) ),
			esc_attr( strtolower( remove_accents( (string) $d['cliente'] ) ) ),
			esc_attr__( 'Ocultar o mostrar el detalle', 'malka-crm' ),
			// Emitida con su factura del extranjero al lado, o sola (importada del SII).
			esc_attr( self::tiene_extranjera( $d ) ? 'con' : 'sin' ),
			esc_attr( (string) $fem ),
			esc_attr( (string) (int) $d['folio_mio'] ),
			self::control_juntar( $d ),
			self::aviso_propuesta( $d )
		);
	}

	/**
	 * Todos los documentos, una vez por carga (las filas los consultan mucho).
	 *
	 * @return array[]
	 */
	private static function todos_cache() {
		static $todos = null;
		if ( null === $todos ) {
			$todos = self::all();
		}
		return $todos;
	}

	/**
	 * Una factura de compra dicha en una línea: número, fecha, quién y monto.
	 *
	 * @param array $d Documento.
	 * @return string
	 */
	private static function rotulo_corto( $d ) {
		$fem = $d['fecha_mia'] ? $d['fecha_mia'] : $d['fecha'];
		$num = 'emitida' === $d['emito']
			? sprintf( /* translators: número */ __( 'N° %s', 'malka-crm' ), $d['folio_mio'] ? $d['folio_mio'] : '—' )
			: ( $d['folio'] ? $d['folio'] : __( 'sin número', 'malka-crm' ) );
		return implode(
			' · ',
			array_filter(
				array(
					$num,
					$fem ? date_i18n( 'd/m/Y', strtotime( $fem ) ) : '',
					'emitida' === $d['emito'] ? ( $d['sii_nombre'] ? $d['sii_nombre'] : $d['cliente'] ) : $d['cliente'],
					$d['base'] ? Malka_CRM_Settings::money( $d['base'] ) : ( $d['original'] ? $d['moneda'] . ' ' . number_format_i18n( $d['original'], 2 ) : '' ),
				)
			)
		);
	}

	/**
	 * Las que se pueden juntar con esta: si es por emitir, las emitidas sin
	 * su factura del extranjero; si es emitida sin ella, las por emitir.
	 * Primero las del mismo RUT y las de fecha más cercana.
	 *
	 * @param array $d Documento.
	 * @return array[]
	 */
	private static function candidatas( $d ) {
		$pend  = 'pendiente' === $d['emito'];
		$llave = self::rut_llave( $d['sii_rut'] );
		$fem   = strtotime( $d['fecha_mia'] ? $d['fecha_mia'] : $d['fecha'] );
		$out   = array();
		foreach ( self::todos_cache() as $o ) {
			if ( (int) $o['id'] === (int) $d['id'] ) {
				continue;
			}
			if ( $pend ? ( 'emitida' !== $o['emito'] || self::tiene_extranjera( $o ) ) : ( 'pendiente' !== $o['emito'] ) ) {
				continue;
			}
			$o['_mismo'] = '' !== $llave && self::rut_llave( $o['sii_rut'] ) === $llave ? 0 : 1;
			$o['_dif']   = abs( strtotime( $o['fecha_mia'] ? $o['fecha_mia'] : $o['fecha'] ) - $fem );
			$out[]       = $o;
		}
		usort(
			$out,
			function ( $a, $b ) {
				return ( $a['_mismo'] <=> $b['_mismo'] ) ?: ( $a['_dif'] <=> $b['_dif'] );
			}
		);
		return array_slice( $out, 0, 30 );
	}

	/**
	 * Al pie de la fila: juntarla con su par, o separarla si se juntó mal.
	 *
	 * @param array $d Documento.
	 * @return string
	 */
	private static function control_juntar( $d ) {
		$antes = get_post_meta( (int) $d['id'], self::META_ANTES, true );
		if ( 'emitida' === $d['emito'] && is_array( $antes ) ) {
			return sprintf(
				'<button type="button" class="esc-em__sep" data-separar="%1$d" data-confirmar-js="%2$s">%3$s</button>',
				(int) $d['id'],
				esc_attr__( '¿Separar la factura del proveedor de esta factura de compra? Vuelve a «por emitir» y esta queda como estaba.', 'malka-crm' ),
				esc_html__( 'Separar su factura del extranjero', 'malka-crm' )
			);
		}
		if ( 'emitida' === $d['emito'] && self::tiene_extranjera( $d ) ) {
			return '';
		}
		$cand = self::candidatas( $d );
		if ( ! $cand ) {
			return '';
		}
		$pend = 'pendiente' === $d['emito'];
		$ops  = '<option value="">' . esc_html( $pend ? __( 'Ya la emití: juntar con…', 'malka-crm' ) : __( '¿Cuál es su factura del extranjero?', 'malka-crm' ) ) . '</option>';
		foreach ( $cand as $o ) {
			$ops .= sprintf( '<option value="%1$d">%2$s</option>', (int) $o['id'], esc_html( self::rotulo_corto( $o ) ) );
		}
		return sprintf(
			'<label class="esc-em__junta"><svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 9.5l3-3M7 4.5l1-1a2.8 2.8 0 014 4l-1 1M9 11.5l-1 1a2.8 2.8 0 01-4-4l1-1"/></svg><select data-juntar="%1$d" data-lado="%2$s" aria-label="%3$s">%4$s</select></label>',
			(int) $d['id'],
			$pend ? 'pendiente' : 'emitida',
			esc_attr__( 'Juntar con su par', 'malka-crm' ),
			$ops
		);
	}

	/**
	 * Arriba de una por emitir: «parece que ya la emitiste, ¿es esta?».
	 *
	 * @param array $d Documento.
	 * @return string
	 */
	private static function aviso_propuesta( $d ) {
		if ( 'pendiente' !== $d['emito'] ) {
			return '';
		}
		$pid = (int) get_post_meta( (int) $d['id'], self::META_PROPUESTA, true );
		if ( ! $pid || self::POST_TYPE !== get_post_type( $pid ) || 'trash' === get_post_status( $pid ) ) {
			return '';
		}
		$o = self::get( $pid );
		if ( 'emitida' !== $o['emito'] || self::tiene_extranjera( $o ) ) {
			return '';
		}
		return sprintf(
			'<div class="esc-em__prop"><p><b>%1$s</b> %2$s</p><span><button type="button" class="esc-btn esc-btn--primary" data-juntar-si="%3$d" data-con="%4$d">%5$s</button><button type="button" class="esc-btn esc-btn--soft" data-juntar-no="%3$d">%6$s</button></span></div>',
			esc_html__( 'Parece que ya la emitiste.', 'malka-crm' ),
			esc_html( sprintf( /* translators: factura de compra en una línea */ __( 'La factura de compra %s tiene el mismo RUT, una fecha cercana y el mismo monto. ¿Es esta?', 'malka-crm' ), self::rotulo_corto( $o ) ) ),
			(int) $d['id'],
			$pid,
			esc_html__( 'Sí, juntarlas', 'malka-crm' ),
			esc_html__( 'No es esa', 'malka-crm' )
		);
	}

	/** Lo que viene de la factura del proveedor y pasa a la emitida al juntarlas. */
	private static function metas_extranjera() {
		return array( self::META_FOLIO, self::META_FECHA, self::META_CLIENTE, self::META_RUT, self::META_GIRO, self::META_DIR, self::META_COMUNA, self::META_CIUDAD, self::META_MONEDA, self::META_ORIGINAL, self::META_TC, self::META_TC_FECHA, self::META_ARCHIVO, self::META_ARCHIVO_NOMBRE, self::META_PAGADO, self::META_MOV, self::META_PAGO, self::META_PROVEEDOR_ID );
	}

	/**
	 * Junta una por emitir con la emitida que le corresponde: la emitida se
	 * queda con su número, su fecha y su neto, y recibe la factura del
	 * proveedor (número, monto, PDF, pago). La por emitir se borra.
	 * Lo que tenía la emitida se guarda para poder separarlas.
	 *
	 * @param int $pend    Por emitir.
	 * @param int $emitida Emitida.
	 * @return bool
	 */
	public static function juntar( $pend, $emitida ) {
		$a = self::get( $pend );
		$b = self::get( $emitida );
		if ( empty( $a['id'] ) || empty( $b['id'] ) || 'pendiente' !== $a['emito'] || 'emitida' !== $b['emito'] || self::tiene_extranjera( $b ) ) {
			return false;
		}
		$antes = array();
		foreach ( self::metas_extranjera() as $m ) {
			$antes[ $m ] = get_post_meta( $emitida, $m, true );
			$v           = get_post_meta( $pend, $m, true );
			// Un dato que la por emitir no trae no borra el de la emitida.
			if ( '' !== $v && null !== $v && false !== $v ) {
				update_post_meta( $emitida, $m, $v );
			}
		}
		$antes['_titulo'] = get_the_title( $emitida );
		update_post_meta( $emitida, self::META_ANTES, $antes );
		// Lo que faltaba de los datos del SII en la emitida, desde la por emitir.
		foreach ( array( self::META_SII_GIRO, self::META_SII_DIR, self::META_SII_COMUNA, self::META_SII_CIUDAD ) as $m ) {
			if ( '' === (string) get_post_meta( $emitida, $m, true ) && '' !== (string) get_post_meta( $pend, $m, true ) ) {
				update_post_meta( $emitida, $m, get_post_meta( $pend, $m, true ) );
			}
		}
		wp_update_post(
			array(
				'ID'         => $emitida,
				'post_title' => get_the_title( $pend ),
			)
		);
		// El archivo ahora es de la emitida: se suelta antes de borrar la otra.
		delete_post_meta( $pend, self::META_ARCHIVO );
		wp_delete_post( $pend, true );
		return true;
	}

	/**
	 * Deshace una junta: la factura del proveedor vuelve a «por emitir» y la
	 * emitida queda como estaba.
	 *
	 * @param int $emitida Emitida juntada.
	 * @return int ID de la por emitir recreada, o 0.
	 */
	public static function separar( $emitida ) {
		$antes = get_post_meta( $emitida, self::META_ANTES, true );
		if ( ! is_array( $antes ) || 'emitida' !== get_post_meta( $emitida, self::META_EMITO, true ) ) {
			return 0;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => get_the_title( $emitida ),
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		foreach ( self::metas_extranjera() as $m ) {
			update_post_meta( $id, $m, get_post_meta( $emitida, $m, true ) );
			$v = isset( $antes[ $m ] ) ? $antes[ $m ] : '';
			if ( '' === $v || null === $v || false === $v ) {
				delete_post_meta( $emitida, $m );
			} else {
				update_post_meta( $emitida, $m, $v );
			}
		}
		$tc   = (float) get_post_meta( $id, self::META_TC, true );
		$orig = (float) get_post_meta( $id, self::META_ORIGINAL, true );
		update_post_meta( $id, self::META_TIPO, 'factura' );
		update_post_meta( $id, self::META_SENTIDO, 'compra' );
		update_post_meta( $id, self::META_ESTADO, 'registrada' );
		update_post_meta( $id, self::META_EMITO, 'pendiente' );
		update_post_meta( $id, self::META_FECHA_MIA, get_post_meta( $id, self::META_FECHA, true ) );
		update_post_meta( $id, self::META_BASE, $tc > 0 && $orig > 0 ? (int) round( $orig * $tc ) : (int) get_post_meta( $emitida, self::META_BASE, true ) );
		foreach ( array( self::META_SII_NOMBRE, self::META_SII_RUT, self::META_SII_GIRO, self::META_SII_DIR, self::META_SII_COMUNA, self::META_SII_CIUDAD ) as $m ) {
			update_post_meta( $id, $m, get_post_meta( $emitida, $m, true ) );
		}
		// No vuelve a proponer la misma.
		update_post_meta( $id, self::META_NO_ES, array( (int) $emitida ) );
		wp_update_post(
			array(
				'ID'         => $emitida,
				'post_title' => isset( $antes['_titulo'] ) ? $antes['_titulo'] : get_the_title( $emitida ),
			)
		);
		delete_post_meta( $emitida, self::META_ANTES );
		return (int) $id;
	}

	/**
	 * Juntar, decir que no es, o separar, desde la lista.
	 */
	public static function ajax_juntar() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'malka_crm_doc_ext', 'nonce' );

		$que = isset( $_POST['que'] ) ? sanitize_key( wp_unslash( $_POST['que'] ) ) : '';
		$a   = isset( $_POST['a'] ) ? absint( $_POST['a'] ) : 0;
		$b   = isset( $_POST['b'] ) ? absint( $_POST['b'] ) : 0;

		if ( 'juntar' === $que ) {
			$ok = self::juntar( $a, $b );
			$ok ? wp_send_json_success() : wp_send_json_error( array( 'msg' => __( 'No se pudieron juntar: revisa que una esté por emitir y la otra emitida sin su factura del extranjero.', 'malka-crm' ) ) );
		}
		if ( 'no' === $que && $a ) {
			$no   = (array) get_post_meta( $a, self::META_NO_ES, true );
			$no[] = (int) get_post_meta( $a, self::META_PROPUESTA, true );
			update_post_meta( $a, self::META_NO_ES, array_values( array_unique( array_filter( array_map( 'intval', $no ) ) ) ) );
			delete_post_meta( $a, self::META_PROPUESTA );
			wp_send_json_success();
		}
		if ( 'separar' === $que && $a ) {
			self::separar( $a ) ? wp_send_json_success() : wp_send_json_error( array( 'msg' => __( 'No se pudo separar.', 'malka-crm' ) ) );
		}
		wp_send_json_error( null, 400 );
	}

	/**
	 * La última factura de compra emitida: su número y su fecha.
	 *
	 * @param array[] $todos Documentos.
	 * @return array {folio:int, fecha:string}
	 */
	private static function ultima_emitida( $todos ) {
		$out = array( 'folio' => 0, 'fecha' => '' );
		foreach ( $todos as $d ) {
			if ( 'emitida' !== $d['emito'] || ! ctype_digit( ltrim( (string) $d['folio_mio'], '0' ) . '0' ) ) {
				continue;
			}
			$n = (int) $d['folio_mio'];
			if ( $n > $out['folio'] ) {
				$out = array(
					'folio' => $n,
					'fecha' => $d['fecha_mia'] ? $d['fecha_mia'] : $d['fecha'],
				);
			}
		}
		return $out;
	}

	/**
	 * Si una factura de compra tiene al lado la factura real del proveedor.
	 *
	 * Las que se importan del SII llegan sin ella: con número propio, en
	 * pesos y sin el número del proveedor.
	 *
	 * @param array $d Documento.
	 * @return bool
	 */
	private static function tiene_extranjera( $d ) {
		return '' !== trim( (string) $d['folio'] ) || ( $d['moneda'] && 'CLP' !== $d['moneda'] );
	}

	/**
	 * Lo mínimo de un nombre para compararlo: sin tildes, signos ni siglas.
	 *
	 * @param string $n Nombre.
	 * @return string
	 */
	private static function llave_nombre( $n ) {
		$n = strtolower( remove_accents( (string) $n ) );
		$n = preg_replace( '/[^a-z0-9 ]+/', ' ', $n );
		$n = preg_replace( '/\b(inc|llc|ltd|limited|pbc|corp|corporation|co|company|gmbh|sa|spa|sl|bv|plc|pte)\b/', ' ', $n );
		return trim( preg_replace( '/\s+/', ' ', $n ) );
	}

	/**
	 * Busca si una factura del extranjero ya está anotada o ya tiene su
	 * factura de compra emitida.
	 *
	 * @param string $proveedor Proveedor como viene en su factura.
	 * @param string $folio     Número de su factura.
	 * @param string $dia       Fecha de su factura (Y-m-d).
	 * @param int    $base      Monto en pesos, si se pudo calcular.
	 * @param string $rut       RUT con que se le emite en el SII, si se sabe.
	 * @param float  $original  Precio en su moneda.
	 * @param string $moneda    USD, EUR o CLP.
	 * @return array {tipo: ''|'ya'|'enlazar', doc: array|null}
	 */
	public static function buscar_extranjera( $proveedor, $folio, $dia, $base, $rut, $original = 0, $moneda = 'CLP' ) {
		$quien = self::llave_nombre( $proveedor );
		$num   = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $folio ) );
		$llave = self::rut_llave( $rut );
		$mejor = null;

		foreach ( self::all() as $d ) {
			if ( '' === $d['emito'] ) {
				continue;
			}
			$mismo = ( '' !== $quien && self::llave_nombre( $d['cliente'] ) === $quien )
				|| ( '' !== $llave && self::rut_llave( $d['sii_rut'] ) === $llave && '555555555' !== $llave );

			// La misma factura del proveedor, ya arrastrada antes.
			if ( '' !== $num && $mismo && strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $d['folio'] ) ) === $num ) {
				return array( 'tipo' => 'ya', 'doc' => $d );
			}

			// Una factura de compra emitida (importada del SII) que todavía no
			// tiene al lado la factura del proveedor: mismo RUT, fecha cercana
			// y, si se sabe, un monto parecido (el cambio puede ser de otro día).
			if ( 'emitida' !== $d['emito'] || self::tiene_extranjera( $d ) || '' === $llave || self::rut_llave( $d['sii_rut'] ) !== $llave ) {
				continue;
			}
			$fem = $d['fecha_mia'] ? $d['fecha_mia'] : $d['fecha'];
			$dif = abs( strtotime( $fem ) - strtotime( $dia ) ) / DAY_IN_SECONDS;
			if ( $dif > 5 ) {
				continue;
			}
			// El monto tiene que calzar casi exacto: el neto emitido contra el
			// precio de su factura con el cambio del día en que se emitió.
			if ( $base <= 0 || $d['base'] <= 0 ) {
				continue;
			}
			$esperado = $base;
			if ( $original > 0 && 'CLP' !== $moneda && class_exists( 'Malka_CRM_SII' ) ) {
				$tc_em = Malka_CRM_SII::tipo_cambio( $moneda, $fem );
				if ( $tc_em && $tc_em['valor'] > 0 ) {
					$esperado = (int) round( $original * $tc_em['valor'] );
				}
			}
			if ( abs( $d['base'] - $esperado ) / max( $esperado, $d['base'] ) > 0.02 ) {
				continue;
			}
			if ( ! $mejor || $dif < $mejor[1] ) {
				$mejor = array( $d, $dif );
			}
		}

		return $mejor ? array( 'tipo' => 'enlazar', 'doc' => $mejor[0] ) : array( 'tipo' => '', 'doc' => null );
	}

	/**
	 * Lo que de verdad pagó en pesos, con el cambio que le cobraron y la
	 * diferencia con el neto que se declara en el SII.
	 *
	 * @param array $d Documento.
	 * @return string
	 */
	private static function bloque_pagado( $d ) {
		$foraneo = $d['moneda'] && 'CLP' !== $d['moneda'] && $d['original'] > 0;
		$cambio  = ( $d['pagado_clp'] > 0 && $foraneo ) ? number_format_i18n( $d['pagado_clp'] / $d['original'], 2 ) : '—';
		$dif     = ( $d['pagado_clp'] > 0 && $d['base'] ) ? $d['pagado_clp'] - (int) $d['base'] : null;

		// Lo pagado, el cambio que cobró la tarjeta y la diferencia con el
		// neto del SII, en un recuadro igual al del cálculo de la derecha.
		return sprintf(
			'<div class="esc-em__calc esc-em__calc--pago">' .
			'<label class="esc-em__c"><span>%1$s</span><span class="esc-em__in"><i>$</i><input type="text" inputmode="numeric" data-campo="pagado_clp" value="%2$s" placeholder="—" /></span></label>' .
			'<div class="esc-em__c"><span>%3$s</span><span class="esc-em__in"><b class="esc-em__dato">%4$s</b></span></div>' .
			'<div class="esc-em__c"><span>%5$s</span><span class="esc-em__in"><b class="esc-em__dato%6$s">%7$s</b></span></div>' .
			'<label class="esc-em__c esc-em__c--3 esc-em__enl%8$s"><span>%9$s</span><select data-campo="enlace">%10$s</select></label>' .
			'</div>',
			esc_html__( 'Pagaste en pesos', 'malka-crm' ),
			esc_attr( $d['pagado_clp'] ? number_format( $d['pagado_clp'], 0, ',', '.' ) : '' ),
			esc_html( $foraneo ? sprintf( /* translators: moneda */ __( '%s que te cobraron', 'malka-crm' ), $d['moneda'] ) : __( 'Cambio que te cobraron', 'malka-crm' ) ),
			esc_html( $cambio ),
			esc_html__( 'Diferencia con el SII', 'malka-crm' ),
			null === $dif ? '' : ( $dif > 0 ? ' is-mas' : '' ),
			esc_html( null === $dif ? '—' : ( $dif >= 0 ? '+' : '−' ) . Malka_CRM_Settings::money( abs( $dif ) ) ),
			( $d['mov_id'] || $d['pago_ref'] ) ? ' is-on' : '',
			esc_html__( 'Pagada con', 'malka-crm' ),
			self::opciones_enlace( $d ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}


	/* ─────────────────────  Enlace con pagos y movimientos  ─────────────── */

	/**
	 * Enlaza un documento con un pago del estudio o un movimiento.
	 *
	 * «pago:Y-m|clave» lo enlaza al pago (y, si ya está pagado, al movimiento
	 * que salió de él); «mov:ID» a un movimiento; vacío lo suelta.
	 *
	 * @param int    $id    Documento.
	 * @param string $valor Qué enlazar.
	 */
	public static function enlazar( $id, $valor ) {
		$valor = (string) $valor;
		if ( 0 === strpos( $valor, 'pago:' ) && class_exists( 'Malka_CRM_Pagos' ) ) {
			$p = Malka_CRM_Pagos::por_ref( substr( $valor, 5 ) );
			if ( $p ) {
				update_post_meta( $id, self::META_PAGO, $p['ref'] );
				update_post_meta( $id, self::META_MOV, $p['mov'] );
				// Lo pagado en ese pago es lo que de verdad salió en pesos.
				if ( $p['pagado'] && $p['pagado_monto'] > 0 && ! (int) get_post_meta( $id, self::META_PAGADO, true ) ) {
					update_post_meta( $id, self::META_PAGADO, (int) $p['pagado_monto'] );
				}
				return;
			}
		}
		if ( 0 === strpos( $valor, 'mov:' ) ) {
			$m = class_exists( 'Malka_CRM_Finance' ) ? Malka_CRM_Finance::get( (int) substr( $valor, 4 ) ) : null;
			if ( $m ) {
				update_post_meta( $id, self::META_MOV, (int) $m->id );
				update_post_meta( $id, self::META_PAGO, self::pago_de_mov( (int) $m->id ) );
				if ( ! (int) get_post_meta( $id, self::META_PAGADO, true ) ) {
					update_post_meta( $id, self::META_PAGADO, (int) $m->monto );
				}
				return;
			}
		}
		delete_post_meta( $id, self::META_MOV );
		delete_post_meta( $id, self::META_PAGO );
	}

	/**
	 * Qué pago del estudio generó un movimiento, si alguno.
	 *
	 * @param int $mov Movimiento.
	 * @return string «Y-m|clave» o vacío.
	 */
	private static function pago_de_mov( $mov ) {
		$todo = get_option( 'malka_crm_pagos_hechos', array() );
		foreach ( is_array( $todo ) ? $todo : array() as $mes => $claves ) {
			foreach ( (array) $claves as $clave => $h ) {
				if ( ! empty( $h['mov'] ) && (int) $h['mov'] === (int) $mov ) {
					return $mes . '|' . $clave;
				}
			}
		}
		return '';
	}

	/**
	 * Al marcar pagado un pago del estudio, las facturas enlazadas a él
	 * quedan enlazadas también al movimiento que se creó.
	 *
	 * @param string $ref Pago «Y-m|clave».
	 * @param int    $mov Movimiento.
	 */
	public static function al_pagar( $ref, $mov ) {
		$m = class_exists( 'Malka_CRM_Finance' ) ? Malka_CRM_Finance::get( $mov ) : null;
		foreach ( self::de_pago( $ref ) as $d ) {
			update_post_meta( $d['id'], self::META_MOV, (int) $mov );
			if ( $m && ! $d['pagado_clp'] ) {
				update_post_meta( $d['id'], self::META_PAGADO, (int) $m->monto );
			}
		}
	}

	/**
	 * Documentos con un valor de enlace.
	 *
	 * @param string $meta  Meta.
	 * @param mixed  $valor Valor.
	 * @return array[]
	 */
	private static function con_enlace( $meta, $valor ) {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_key'       => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $valor, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return array_map( array( __CLASS__, 'get' ), $ids );
	}

	/**
	 * Los documentos enlazados a un movimiento.
	 *
	 * @param int $mov Movimiento.
	 * @return array[]
	 */
	public static function de_movimiento( $mov ) {
		return (int) $mov > 0 ? self::con_enlace( self::META_MOV, (int) $mov ) : array();
	}

	/**
	 * Los documentos enlazados a un pago del estudio.
	 *
	 * @param string $ref «Y-m|clave».
	 * @return array[]
	 */
	public static function de_pago( $ref ) {
		return '' !== (string) $ref ? self::con_enlace( self::META_PAGO, (string) $ref ) : array();
	}

	/**
	 * El enlace corto a un documento, para mostrarlo en otras pantallas.
	 *
	 * @param array $d Documento.
	 * @return string HTML.
	 */
	public static function chip( $d ) {
		return sprintf(
			'<a class="esc-chipdoc" href="%1$s" title="%2$s"><svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.8 4.6L5.9 9.5a1.7 1.7 0 002.4 2.4l5.1-5.1a3 3 0 00-4.2-4.2L4 7.7a4.2 4.2 0 006 6l4.3-4.3"/></svg>%3$s</a>',
			esc_url( self::edit_url( $d['id'] ) ),
			esc_attr__( 'Abrir la factura enlazada', 'malka-crm' ),
			esc_html( trim( ( $d['cliente'] ? $d['cliente'] : __( 'Factura', 'malka-crm' ) ) . ( $d['folio'] ? ' N° ' . $d['folio'] : '' ) ) )
		);
	}

	/**
	 * Las opciones para enlazar: pagos del estudio (de los meses cerca de la
	 * factura) y movimientos de salida recientes.
	 *
	 * @param array $d Documento.
	 * @return string <option>s.
	 */
	public static function opciones_enlace( $d ) {
		$actual = $d['pago_ref'] ? 'pago:' . $d['pago_ref'] : ( $d['mov_id'] ? 'mov:' . $d['mov_id'] : '' );
		$out    = sprintf( '<option value="">%s</option>', esc_html__( 'Sin enlazar', 'malka-crm' ) );

		if ( class_exists( 'Malka_CRM_Pagos' ) ) {
			$base  = $d['fecha'] ? $d['fecha'] : current_time( 'Y-m-d' );
			$meses = array_unique(
				array(
					gmdate( 'Y-m', strtotime( substr( $base, 0, 7 ) . '-01 -1 month' ) ),
					substr( $base, 0, 7 ),
					gmdate( 'Y-m', strtotime( substr( $base, 0, 7 ) . '-01 +1 month' ) ),
				)
			);
			sort( $meses );
			$out .= '<optgroup label="' . esc_attr__( 'Pagos del estudio', 'malka-crm' ) . '">';
			foreach ( $meses as $mes ) {
				foreach ( Malka_CRM_Pagos::del_mes( $mes ) as $p ) {
					$v    = 'pago:' . $mes . '|' . $p['clave'];
					$out .= sprintf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $v ),
						selected( $actual, $v, false ),
						esc_html( $p['nombre'] . ' · ' . date_i18n( 'M Y', strtotime( $p['fecha'] ) ) . ( $p['pagado'] ? ' · ' . __( 'pagado', 'malka-crm' ) . ' ' . Malka_CRM_Settings::money( $p['pagado_monto'] ) : '' ) )
					);
				}
			}
			$out .= '</optgroup>';
		}

		if ( class_exists( 'Malka_CRM_Finance' ) ) {
			$out .= '<optgroup label="' . esc_attr__( 'Movimientos', 'malka-crm' ) . '">';
			$hay  = false;
			foreach ( Malka_CRM_Finance::recent( 80 ) as $m ) {
				if ( 'salida' !== $m->tipo && (int) $m->id !== $d['mov_id'] ) {
					continue;
				}
				$v    = 'mov:' . (int) $m->id;
				$hay  = $hay || (int) $m->id === $d['mov_id'];
				$out .= sprintf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $v ),
					$d['pago_ref'] ? '' : selected( $actual, $v, false ),
					esc_html( date_i18n( 'd/m', strtotime( $m->fecha ) ) . ' · ' . $m->descripcion . ' · ' . Malka_CRM_Settings::money( $m->monto ) )
				);
			}
			// Uno enlazado que ya no está entre los recientes igual se muestra.
			if ( $d['mov_id'] && ! $hay && ! $d['pago_ref'] ) {
				$m = Malka_CRM_Finance::get( $d['mov_id'] );
				if ( $m ) {
					$out .= sprintf( '<option value="mov:%1$d" selected>%2$s</option>', (int) $m->id, esc_html( date_i18n( 'd/m/Y', strtotime( $m->fecha ) ) . ' · ' . $m->descripcion . ' · ' . Malka_CRM_Settings::money( $m->monto ) ) );
				}
			}
			$out .= '</optgroup>';
		}

		return $out;
	}

	/**
	 * Propone sola el pago del estudio de esa factura: el del mismo mes cuyo
	 * nombre se parece al del proveedor («Adobe CC» para Adobe).
	 *
	 * @param int $id Documento.
	 */
	public static function enlazar_solo( $id ) {
		$d = self::get( $id );
		if ( $d['pago_ref'] || $d['mov_id'] || '' === trim( (string) $d['cliente'] ) || ! class_exists( 'Malka_CRM_Pagos' ) || ! class_exists( 'Malka_CRM_SII' ) ) {
			return;
		}
		$quien = array_diff( Malka_CRM_SII::palabras( $d['cliente'] . ' ' . $d['sii_nombre'] ), Malka_CRM_SII::GENERICAS );
		if ( ! $quien ) {
			return;
		}
		$mes = substr( $d['fecha'] ? $d['fecha'] : current_time( 'Y-m-d' ), 0, 7 );
		foreach ( Malka_CRM_Pagos::del_mes( $mes ) as $p ) {
			if ( 'costo' === $p['tipo'] && array_intersect( $quien, Malka_CRM_SII::palabras( $p['nombre'] ) ) ) {
				self::enlazar( $id, 'pago:' . $mes . '|' . $p['clave'] );
				return;
			}
		}
	}

	/**
	 * Completa los datos vacíos de una compra desde la ficha de su proveedor.
	 *
	 * Sólo llena lo que está en blanco: lo que ella ya escribió en esta
	 * factura no se pisa. Si el proveedor no tiene ficha, el lado del SII se
	 * busca en la nómina de prestadores extranjeros.
	 *
	 * @param int $id Documento.
	 */
	public static function completar_desde_proveedor( $id, $con_sii = true ) {
		$d   = self::get( $id );
		$pid = class_exists( 'Malka_CRM_Providers' ) ? Malka_CRM_Providers::buscar( $d['cliente'] ) : 0;

		if ( $pid ) {
			$f     = Malka_CRM_Providers::get( $pid );
			$pares = array(
				'rut'        => array( self::META_RUT, 'tax' ),
				'giro'       => array( self::META_GIRO, 'giro' ),
				'dir'        => array( self::META_DIR, 'dir' ),
				'comuna'     => array( self::META_COMUNA, 'comuna' ),
				'ciudad'     => array( self::META_CIUDAD, 'ciudad' ),
				'sii_nombre' => array( self::META_SII_NOMBRE, 'sii_nombre' ),
				'sii_rut'    => array( self::META_SII_RUT, 'sii_rut' ),
				'sii_giro'   => array( self::META_SII_GIRO, 'sii_giro' ),
				'sii_dir'    => array( self::META_SII_DIR, 'sii_dir' ),
				'sii_comuna' => array( self::META_SII_COMUNA, 'sii_comuna' ),
				'sii_ciudad' => array( self::META_SII_CIUDAD, 'sii_ciudad' ),
			);
			$puso = false;
			foreach ( $pares as $campo => $par ) {
				if ( '' === trim( (string) $d[ $campo ] ) && '' !== trim( (string) $f[ $par[1] ] ) ) {
					update_post_meta( $id, $par[0], $f[ $par[1] ] );
					$puso = $puso || 0 === strpos( $campo, 'sii_' );
				}
			}
			update_post_meta( $id, self::META_PROVEEDOR_ID, $pid );
			if ( $puso ) {
				update_post_meta( $id, self::META_SII_ORIGEN, 'previo' );
			}
			return;
		}

		if ( $con_sii && '' === $d['sii_nombre'] && '' === $d['sii_rut'] && class_exists( 'Malka_CRM_SII' ) ) {
			$n = Malka_CRM_SII::datos_para( $d['cliente'] );
			if ( $n ) {
				update_post_meta( $id, self::META_SII_NOMBRE, $n['nombre'] );
				update_post_meta( $id, self::META_SII_RUT, $n['rut'] );
				update_post_meta( $id, self::META_SII_ORIGEN, $n['origen'] );
			}
		}
	}

	/**
	 * Dónde ver el tipo de cambio oficial de un día.
	 *
	 * El dólar observado: la tabla del año en el SII, que es el mismo valor
	 * que publica el Banco Central y la referencia que usa el SII. El euro:
	 * los indicadores diarios del Banco Central.
	 *
	 * @param string $moneda USD|EUR.
	 * @param string $fecha  Y-m-d.
	 * @return string
	 */
	public static function url_tipo_cambio( $moneda, $fecha ) {
		$anio = preg_match( '/^(\d{4})/', (string) $fecha, $m ) ? $m[1] : current_time( 'Y' );

		if ( 'EUR' === $moneda ) {
			return 'https://si3.bcentral.cl/Indicadoressiete/secure/Indicadoresdiarios.aspx';
		}

		return 'https://www.sii.cl/valores_y_fechas/dolar/dolar' . $anio . '.htm';
	}

	/**
	 * Un dato de «Facturas de compra por emitir» editado en la misma lista.
	 *
	 * Guarda el campo, recalcula el neto si cambió el monto, la fecha o el
	 * cambio, actualiza la ficha del proveedor y devuelve la fila de nuevo.
	 */
	public static function ajax_campo() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'malka_crm_doc_ext', 'nonce' );

		$id    = isset( $_POST['doc'] ) ? absint( $_POST['doc'] ) : 0;
		$campo = isset( $_POST['campo'] ) ? sanitize_key( wp_unslash( $_POST['campo'] ) ) : '';
		$valor = isset( $_POST['valor'] ) ? sanitize_text_field( wp_unslash( $_POST['valor'] ) ) : '';

		if ( ! $id || self::POST_TYPE !== get_post_type( $id ) ) {
			wp_send_json_error( null, 404 );
		}

		$metas = array(
			'fecha'      => self::META_FECHA,
			'folio'      => self::META_FOLIO,
			'cliente'    => self::META_CLIENTE,
			'rut'        => self::META_RUT,
			'giro'       => self::META_GIRO,
			'dir'        => self::META_DIR,
			'comuna'     => self::META_COMUNA,
			'ciudad'     => self::META_CIUDAD,
			'sii_nombre' => self::META_SII_NOMBRE,
			'sii_rut'    => self::META_SII_RUT,
			'sii_giro'   => self::META_SII_GIRO,
			'sii_dir'    => self::META_SII_DIR,
			'sii_comuna' => self::META_SII_COMUNA,
			'sii_ciudad' => self::META_SII_CIUDAD,
			'fecha_mia'  => self::META_FECHA_MIA,
			'original'   => self::META_ORIGINAL,
			'tc'         => self::META_TC,
			'folio_mio'  => self::META_FOLIO_MIO,
			'pagado_clp' => self::META_PAGADO,
		);

		if ( 'enlace' === $campo ) {
			self::enlazar( $id, $valor );
			wp_send_json_success( array( 'fila' => self::fila_emito( self::get( $id ) ) ) );
		}

		// La moneda de la factura original. Al pasar a dólar o euro se busca
		// el cambio del día; al pasar a pesos, el precio es el neto.
		if ( 'moneda' === $campo ) {
			$m = strtoupper( $valor );
			$m = in_array( $m, array( 'USD', 'EUR', 'CLP' ), true ) ? $m : 'CLP';
			update_post_meta( $id, self::META_MONEDA, $m );
			$d = self::get( $id );
			if ( 'CLP' === $m ) {
				update_post_meta( $id, self::META_TC, 0 );
				update_post_meta( $id, self::META_TC_FECHA, '' );
			} elseif ( class_exists( 'Malka_CRM_SII' ) ) {
				$bc = Malka_CRM_SII::tipo_cambio( $m, $d['fecha_mia'] ? $d['fecha_mia'] : $d['fecha'] );
				if ( $bc ) {
					update_post_meta( $id, self::META_TC, $bc['valor'] );
					update_post_meta( $id, self::META_TC_FECHA, $bc['fecha'] );
					if ( $d['original'] > 0 ) {
						update_post_meta( $id, self::META_BASE, (int) round( $d['original'] * $bc['valor'] ) );
					}
				}
			}
			wp_send_json_success( array( 'fila' => self::fila_emito( self::get( $id ) ) ) );
		}

		if ( ! isset( $metas[ $campo ] ) ) {
			wp_send_json_error( null, 400 );
		}

		$d       = self::get( $id );
		$foraneo = $d['moneda'] && 'CLP' !== $d['moneda'];

		if ( in_array( $campo, array( 'fecha', 'fecha_mia' ), true ) ) {
			$valor = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valor ) ? $valor : '';
		} elseif ( in_array( $campo, array( 'original', 'tc' ), true ) ) {
			$valor = self::numero_libre( $valor );
		} elseif ( 'pagado_clp' === $campo ) {
			$valor = (int) preg_replace( '/\D/', '', $valor );
		}

		// En una factura en pesos el «precio» es el neto.
		if ( 'original' === $campo && ! $foraneo ) {
			update_post_meta( $id, self::META_BASE, (int) round( (float) $valor ) );
		} else {
			update_post_meta( $id, $metas[ $campo ], $valor );
		}

		if ( 0 === strpos( $campo, 'sii_' ) ) {
			update_post_meta( $id, self::META_SII_ORIGEN, 'mano' );
		}

		// Al escribir el proveedor, lo que falte se completa desde su ficha
		// (o, si es nuevo, desde la nómina del SII).
		if ( 'cliente' === $campo && '' !== trim( (string) $valor ) ) {
			self::completar_desde_proveedor( $id );
			self::enlazar_solo( $id );
		}

		// Con folio propio queda emitida; si se borra, vuelve a pendiente.
		if ( 'folio_mio' === $campo ) {
			update_post_meta( $id, self::META_EMITO, '' !== trim( (string) $valor ) ? 'emitida' : 'pendiente' );
		}

		if ( $foraneo ) {
			$d   = self::get( $id );
			$dia = $d['fecha_mia'] ? $d['fecha_mia'] : $d['fecha'];

			if ( 'tc' === $campo ) {
				// Escrito a mano: ya no es «del Banco Central del día tal».
				update_post_meta( $id, self::META_TC_FECHA, '' );
			} elseif ( ( 'fecha_mia' === $campo || ( 'fecha' === $campo && ! $d['fecha_mia'] ) || $d['tc'] <= 0 ) && class_exists( 'Malka_CRM_SII' ) ) {
				$bc = Malka_CRM_SII::tipo_cambio( $d['moneda'], $dia );
				if ( $bc ) {
					update_post_meta( $id, self::META_TC, $bc['valor'] );
					update_post_meta( $id, self::META_TC_FECHA, $bc['fecha'] );
				}
			}

			$d = self::get( $id );
			if ( $d['original'] > 0 && $d['tc'] > 0 ) {
				update_post_meta( $id, self::META_BASE, (int) round( $d['original'] * $d['tc'] ) );
			}
		}

		if ( class_exists( 'Malka_CRM_Providers' ) ) {
			$pid = Malka_CRM_Providers::desde_documento( self::get( $id ) );
			if ( $pid ) {
				update_post_meta( $id, self::META_PROVEEDOR_ID, $pid );
			}
		}

		wp_send_json_success( array( 'fila' => self::fila_emito( self::get( $id ) ) ) );
	}

	/**
	 * El clip que dice que este documento tiene su PDF subido.
	 *
	 * @param int $id Documento.
	 * @return string
	 */
	private static function sello_pdf( $id ) {
		if ( ! self::tiene_archivo( $id ) ) {
			return '';
		}

		return sprintf(
			'<span class="esc-clip" title="%1$s" aria-label="%1$s" role="img">' .
			'<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' .
			'<path d="M10.8 4.6L5.9 9.5a1.7 1.7 0 002.4 2.4l5.1-5.1a3 3 0 00-4.2-4.2L4 7.7a4.2 4.2 0 006 6l4.3-4.3"/>' .
			'</svg></span>',
			esc_attr__( 'Tiene el PDF subido', 'malka-crm' )
		);
	}

	/**
	 * Cómo se llama el estado de este documento.
	 *
	 * Los estados dependen del lado: una compra no se «emite». Sin pasarle el
	 * sentido, la tabla buscaba «registrada» en la lista de ventas y PHP
	 * avisaba de una clave que no existe.
	 *
	 * Si le toca emitirla a ella, eso pesa más que el estado: es lo que tiene
	 * que hacer, y es lo que quiere ver en la fila.
	 *
	 * @param array $d Documento.
	 * @return string
	 */
	private static function rotulo_estado( $d ) {
		if ( 'pendiente' === $d['emito'] ) {
			return __( 'Falta emitirla', 'malka-crm' );
		}

		if ( 'emitida' === $d['emito'] ) {
			return $d['folio_mio']
				/* translators: folio que ella le puso */
				? sprintf( __( 'Emitida N° %s', 'malka-crm' ), $d['folio_mio'] )
				: __( 'Emitida por mí', 'malka-crm' );
		}

		$estados = self::estados( $d['sentido'] );

		return isset( $estados[ $d['estado'] ] ) ? $estados[ $d['estado'] ] : $d['estado'];
	}

	/**
	 * Editar y eliminar, al final de la fila.
	 *
	 * @param array $d Documento.
	 * @return string
	 */
	private static function acciones( $d ) {
		ob_start();
		echo '<span class="esc-tr__acc">';

		printf(
			'<a class="esc-ico" href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',
			esc_url( self::edit_url( $d['id'] ) ),
			esc_attr__( 'Editar', 'malka-crm' ),
			Malka_CRM_Dashboard::icono_publico( '<path d="M11.2 2.3l2.5 2.5L6 12.5l-3.2.7.7-3.2z"/>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		printf(
			'<a class="esc-ico esc-ico--x" href="%1$s" title="%2$s" aria-label="%2$s" data-confirmar="%3$s">%4$s</a>',
			esc_url( self::delete_url( $d['id'] ) ),
			esc_attr__( 'Eliminar', 'malka-crm' ),
			esc_attr(
				sprintf(
					/* translators: documento */
					__( '¿Mandar %s a la papelera? La puedes recuperar después.', 'malka-crm' ),
					$d['rotulo'] . ( $d['folio'] ? ' N° ' . $d['folio'] : '' )
				)
			),
			Malka_CRM_Dashboard::icono_publico( '<path d="M4 4l8 8M12 4l-8 8"/>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		echo '</span>';

		return ob_get_clean();
	}


	/**
	 * El nombre de la contraparte, con la lista de clientes ya fichados.
	 *
	 * Es un campo de texto con `datalist`: escribe y el navegador le propone
	 * los que ya tiene. Si el que busca no está, lo escribe igual y listo —
	 * un selector cerrado la obligaría a crear la ficha antes de poder
	 * registrar la boleta, que es justo al revés de como pasan las cosas.
	 *
	 * @param array $d      Documento.
	 * @param bool  $compra Si es una compra.
	 * @return string
	 */
	private static function campo_contraparte( $d, $compra ) {
		$lista = '';

		if ( ! $compra && class_exists( 'Malka_CRM_Clients' ) ) {
			$opciones = '';

			foreach ( Malka_CRM_Clients::all() as $c ) {
				// El rótulo junta nombre, empresa, RUT y alias: así la
				// propuesta aparece escriba lo que escriba.
				$partes = array_filter( array( $c['empresa'], $c['rut'], $c['alias'] ) );

				$opciones .= sprintf(
					'<option value="%1$s">%2$s</option>',
					esc_attr( $c['empresa'] ? $c['empresa'] : $c['nombre'] ),
					esc_attr( trim( $c['nombre'] . ( $partes ? ' · ' . implode( ' · ', $partes ) : '' ) ) )
				);
			}

			if ( $opciones ) {
				$lista = '<datalist id="esc-doc-clientes">' . $opciones . '</datalist>';
			}
		}

		return sprintf(
			'<p class="esc-f esc-docf__quien"><label class="esc-f__l" for="f-cliente" data-venta="' . esc_attr__( 'Cliente', 'malka-crm' ) . '" data-compra="' . esc_attr__( 'Proveedor', 'malka-crm' ) . '">%1$s</label>' .
			'<span class="esc-f__w"><input class="esc-f__i" type="text" id="f-cliente" name="cliente" value="%2$s"%3$s /></span>' .
			'%4$s%5$s</p>',
			esc_html( $compra ? __( 'Proveedor', 'malka-crm' ) : __( 'Cliente', 'malka-crm' ) ),
			esc_attr( $d['cliente'] ),
			$lista ? ' list="esc-doc-clientes" autocomplete="off" placeholder="' . esc_attr__( 'Nombre, empresa, RUT o alias', 'malka-crm' ) . '"' : '',
			'',
			$lista // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * El formulario para anotar o corregir un documento.
	 *
	 * @return string
	 */
	public static function render_form() {
		$id    = self::current_id();
		$nuevo = ! $id;

		if ( $nuevo ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tipo = isset( $_GET['tipo'] ) ? sanitize_key( wp_unslash( $_GET['tipo'] ) ) : 'factura';
			$tipo = array_key_exists( $tipo, self::tipos() ) ? $tipo : 'factura';

			$folio = self::proximo_folio( $tipo );

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sentido = isset( $_GET['sentido'] ) ? sanitize_key( wp_unslash( $_GET['sentido'] ) ) : 'venta';
			$sentido = array_key_exists( $sentido, self::sentidos() ) ? $sentido : 'venta';

			$d = array(
				'id'        => 0,
				'tipo'      => $tipo,
				// Un folio de compra lo pone el proveedor: no se propone nada.
				'folio'     => ( 'venta' === $sentido && $folio ) ? (string) $folio : '',
				'fecha'     => current_time( 'Y-m-d' ),
				'cliente'   => '',
				'rut'       => '',
				'base'      => 0,
				'estado'    => ( 'compra' === $sentido ) ? 'registrada' : 'emitida',
				'quote'     => 0,
				'ref'       => '',
				'notas'     => '',
				'sentido'   => $sentido,
				'proyecto'  => 0,
				'emito'     => '',
				'folio_mio' => '',
			);

			/*
			 * Desde la ficha de un negocio («Registrar factura»): llega con
			 * su negocio y su cotización, y la factura sale ya llenada con el
			 * cliente, el RUT y el neto de la cotización.
			 */
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$de_neg   = isset( $_GET['negocio'] ) ? absint( $_GET['negocio'] ) : 0;
			$de_quote = isset( $_GET['quote'] ) ? absint( $_GET['quote'] ) : 0;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( $de_neg && 'malka_project' === get_post_type( $de_neg ) ) {
				$d['proyecto'] = $de_neg;
			}
			if ( $de_quote && class_exists( 'Malka_CRM_Quotes' ) && Malka_CRM_Quotes::POST_TYPE === get_post_type( $de_quote ) ) {
				$q            = Malka_CRM_Quote_Page::data( $de_quote );
				$d['quote']   = $de_quote;
				$d['cliente'] = $q['cliente']['empresa'] ? $q['cliente']['empresa'] : $q['cliente']['nombre'];
				$d['rut']     = $q['cliente']['rut'];
				$d['base']    = 'boleta' === $tipo ? (int) $q['total'] : (int) $q['neto'];
				$d['ref']     = sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $q['numero'] );
			}
		} else {
			$d = self::get( $id );
		}

		$monto = self::campo_monto( $d['tipo'] );

		ob_start();
		echo '<div class="esc-ed">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pdf_mal = isset( $_GET['pdf'] ) ? sanitize_key( wp_unslash( $_GET['pdf'] ) ) : '';

		if ( 'nopdf' === $pdf_mal || 'no' === $pdf_mal ) {
			$nota   = self::nota();
			$motivo = ! empty( $nota['texto'] )
				? $nota['texto']
				: __( 'No se pudo subir el archivo.', 'malka-crm' );

			printf(
				'<p class="esc-aviso esc-aviso--full">%s <b>%s</b></p>',
				esc_html__( 'El documento quedó guardado, pero el archivo no.', 'malka-crm' ),
				esc_html( $motivo )
			);
		}

		// En la ventana emergente, una factura nueva se anota con el botón:
		// abrirla y cerrarla no puede dejar una creada.
		$en_ventana = isset( $_GET['embed'] ) && '1' === $_GET['embed']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf(
			'<form method="post" action="%1$s" class="esc-ed__f" id="esc-doc" enctype="multipart/form-data"%3$s data-auto-msg="%2$s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr__( 'Se guarda solo apenas pongas el monto', 'malka-crm' ),
			( $en_ventana && ! $id ) ? '' : ' data-auto-nuevo="#f-base"'
		);
		echo '<input type="hidden" name="action" value="malka_crm_save_doc" />';
		printf( '<input type="hidden" name="doc" value="%d" />', (int) $id );
		wp_nonce_field( 'malka_crm_editor_doc' );
		if ( isset( $_GET['embed'] ) && '1' === $_GET['embed'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<input type="hidden" name="embed" value="1" />';
		}

		/*
		 * Dos columnas, como una factura: a la izquierda lo que se escribe,
		 * a la derecha —fija al bajar— el total, a qué trabajo corresponde y
		 * el PDF. Antes era una sola columna larga donde el total quedaba a
		 * media página y el negocio, al final.
		 */
		echo '<div class="esc-docw"><div class="esc-docw__main">';

		/* ── Qué documento es ── */
		$compra = ( 'compra' === $d['sentido'] );

		/*
		 * Los dos textos viajan en el HTML y el JS elige. Recargar al cambiar
		 * de venta a compra habría sido más simple, pero en un documento que
		 * ya está escrito significa perder lo escrito.
		 */
		printf(
			'<section class="esc-panel"><div class="esc-ph"><div>' .
			'<h2 data-venta="%1$s" data-compra="%2$s">%3$s</h2>' .
			'<p data-venta="%4$s" data-compra="%5$s">%6$s</p></div>',
			esc_attr__( 'Qué emitiste', 'malka-crm' ),
			esc_attr__( 'Qué te emitieron', 'malka-crm' ),
			esc_html( $compra ? __( 'Qué te emitieron', 'malka-crm' ) : __( 'Qué emitiste', 'malka-crm' ) ),
			esc_attr__( 'Con los números que de verdad salieron, aunque el SII todavía no esté conectado', 'malka-crm' ),
			esc_attr__( 'Las compras del estudio, para la contabilidad y el crédito de IVA', 'malka-crm' ),
			esc_html(
				$compra
					? __( 'Las compras del estudio, para la contabilidad y el crédito de IVA', 'malka-crm' )
					: __( 'Con los números que de verdad salieron, aunque el SII todavía no esté conectado', 'malka-crm' )
			)
		);

		/*
		 * Venta o compra, arriba a la derecha del bloque como un interruptor
		 * de dos lados. Antes era una fila más de botones sueltos encima del
		 * tipo, y el bloque se veía desordenado.
		 */
		printf( '<label class="screen-reader-text" for="f-sentido">%s</label>', esc_html__( 'Venta o compra', 'malka-crm' ) );
		echo '<select class="esc-f__i" id="f-sentido" name="sentido" data-doc-sentido data-seg>';
		foreach ( self::sentidos() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $d['sentido'], $k, false ),
				esc_html( 'venta' === $k ? __( 'Venta', 'malka-crm' ) : __( 'Compra', 'malka-crm' ) )
			);
		}
		echo '</select></div>';

		/*
		 * Una grilla pareja: tipo, folio, fecha y estado en una línea; quién
		 * y su RUT en la siguiente. Sin textos de ayuda bajo cada campo —eran
		 * los que dejaban las filas chuecas—: lo necesario va de ejemplo
		 * dentro del campo.
		 */
		echo '<div class="esc-docf">';

		printf(
			'<p class="esc-f esc-docf__tipo"><label class="esc-f__l" for="f-tipo">%s</label><span class="esc-f__w">',
			esc_html__( 'Tipo de documento', 'malka-crm' )
		);
		echo '<select class="esc-f__i" id="f-tipo" name="tipo" data-doc-tipo>';
		foreach ( self::tipos() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $d['tipo'], $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select></span></p>';

		echo self::campo( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'folio',
			$compra ? __( 'Folio del proveedor', 'malka-crm' ) : __( 'Folio', 'malka-crm' ),
			$d['folio'],
			'text',
			'',
			array( 'label' => array( __( 'Folio', 'malka-crm' ), __( 'Folio del proveedor', 'malka-crm' ) ) )
		);
		echo self::campo( 'fecha', __( 'Fecha de emisión', 'malka-crm' ), $d['fecha'], 'date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf(
			'<p class="esc-f"><label class="esc-f__l" for="f-estado">%s</label><span class="esc-f__w">',
			esc_html__( 'Estado', 'malka-crm' )
		);
		echo '<select class="esc-f__i" id="f-estado" name="estado" data-doc-estado title="' . esc_attr__( 'Las anuladas quedan a la vista pero no suman en el resumen del mes.', 'malka-crm' ) . '">';

		// Van los de los dos lados: el JS esconde los que no corresponden.
		$todos_estados = array(
			'emitida'    => array( __( 'Emitida', 'malka-crm' ), 'venta' ),
			'registrada' => array( __( 'Registrada', 'malka-crm' ), 'compra' ),
			'anulada'    => array( __( 'Anulada', 'malka-crm' ), 'ambos' ),
		);

		foreach ( $todos_estados as $k => $par ) {
			printf(
				'<option value="%1$s"%2$s data-lado="%3$s">%4$s</option>',
				esc_attr( $k ),
				selected( $d['estado'], $k, false ),
				esc_attr( $par[1] ),
				esc_html( $par[0] )
			);
		}
		echo '</select></span></p>';

		echo self::campo_contraparte( $d, $compra ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'rut',
			$compra ? __( 'RUT del proveedor', 'malka-crm' ) : __( 'RUT del cliente', 'malka-crm' ),
			$d['rut'],
			'text',
			'',
			array( 'label' => array( __( 'RUT del cliente', 'malka-crm' ), __( 'RUT del proveedor', 'malka-crm' ) ) )
		);

		echo '</div>';

		/* ── Sólo en compras: las que me toca emitir a mí ── */
		printf( '<div id="esc-doc-emito"%s>', $compra ? '' : ' hidden' );
		printf(
			'<p class="esc-f"><label class="esc-f__l" for="f-emito">%s</label><span class="esc-f__w">',
			esc_html__( '¿La factura la emito yo?', 'malka-crm' )
		);
		echo '<select class="esc-f__i" id="f-emito" name="emito" data-doc-emito>';
		foreach ( self::emisiones() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $d['emito'], $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select></span>';
		printf(
			'<span class="esc-f__h">%s</span></p>',
			esc_html__( 'Las que marques como pendientes se juntan en «Facturas de compra por emitir».', 'malka-crm' )
		);

		/*
		 * Los datos con que la emite en el SII. Si están vacíos se proponen
		 * los de la última vez con este mismo proveedor: casi siempre se
		 * repiten, y cuando cambian basta con corregirlos acá.
		 */
		$sii    = self::sii_anterior( $d );
		$origen = $sii['de'] ? 'previo' : $d['sii_origen'];
		// Sin moneda guardada pero con monto original, venía del extranjero:
		// partir en pesos la hacía pasar a CLP al guardar.
		$moneda = $d['moneda'] ? $d['moneda'] : ( $d['original'] > 0 ? 'USD' : 'CLP' );
		$fem    = $d['fecha_mia'] ? $d['fecha_mia'] : $d['fecha'];
		$c      = function ( $n, $rot, $v, $t = 'text', $h = '' ) {
			return self::campo( $n, $rot, $v, $t, $h );
		};

		printf(
			'<div id="esc-doc-sii" class="esc-docsii" data-sii="%1$s" data-sii-nonce="%2$s"%3$s><div class="esc-docsii__dos">',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'malka_crm_sii' ) ),
			'' !== $d['emito'] ? '' : ' hidden'
		);

		/* ── Izquierda: la factura original, tal como viene ── */
		echo '<div class="esc-docsii__lado">';
		printf(
			'<p class="esc-docsii__t">%1$s<span>%2$s</span></p>',
			esc_html__( 'Factura original', 'malka-crm' ),
			esc_html__( 'Lo que dice la factura del proveedor. El nombre, el N°, la fecha y su RUT van arriba.', 'malka-crm' )
		);
		printf(
			'<p class="esc-f"><label class="esc-f__l" for="f-original">%1$s</label><span class="esc-f__w esc-docsii__orig">' .
			'<select name="moneda" id="f-moneda" aria-label="%2$s">%3$s</select>' .
			'<input class="esc-f__i" type="text" inputmode="decimal" id="f-original" name="original" value="%4$s" autocomplete="off" /></span>' .
			'<span class="esc-f__h">%5$s</span></p>',
			esc_html__( 'Precio', 'malka-crm' ),
			esc_attr__( 'Moneda', 'malka-crm' ),
			implode(
				'',
				array_map(
					function ( $m ) use ( $moneda ) {
						return sprintf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $m ), selected( $moneda, $m, false ) );
					},
					array( 'USD', 'EUR', 'CLP' )
				)
			),
			esc_attr( $d['original'] > 0 ? number_format( $d['original'], 2, ',', '.' ) : '' ),
			esc_html__( 'Neto, sin impuestos, en dólares o euros.', 'malka-crm' )
		);
		echo $c( 'giro', __( 'Giro', 'malka-crm' ), $d['giro'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $c( 'dir', __( 'Dirección', 'malka-crm' ), $d['dir'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="esc-grid2">';
		echo $c( 'comuna', __( 'Comuna', 'malka-crm' ), $d['comuna'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $c( 'ciudad', __( 'Ciudad', 'malka-crm' ), $d['ciudad'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		printf(
			'<p class="esc-f"><label class="esc-f__l" for="f-pagado_clp">%1$s</label><span class="esc-f__w"><span class="esc-f__u">$</span><input class="esc-f__i" type="text" inputmode="numeric" data-miles id="f-pagado_clp" name="pagado_clp" value="%2$s" /></span><span class="esc-f__h">%3$s</span></p>',
			esc_html__( 'Lo que pagaste en pesos', 'malka-crm' ),
			esc_attr( $d['pagado_clp'] ? number_format( $d['pagado_clp'], 0, ',', '.' ) : '' ),
			esc_html__( 'Lo que salió de tu cuenta, con el dólar o euro que cobra tu tarjeta (MACH u otra).', 'malka-crm' )
		);
		echo '</div>';

		/* ── Derecha: con qué datos se emite en el SII ── */
		echo '<div class="esc-docsii__lado esc-docsii__lado--sii">';
		printf(
			'<p class="esc-docsii__t">%1$s<span id="esc-sii-nota">%2$s</span></p>',
			esc_html__( 'Para emitir en el SII', 'malka-crm' ),
			esc_html( self::nota_origen( $origen, $sii['de'] ) )
		);
		printf( '<input type="hidden" name="sii_origen" id="f-sii_origen" value="%s" />', esc_attr( $origen ) );
		echo '<div class="esc-docsii__res" id="esc-sii-res" hidden></div>';
		echo $c( 'sii_nombre', __( 'Razón social', 'malka-crm' ), $sii['nombre'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="esc-grid2">';
		echo $c( 'sii_rut', __( 'RUT en el SII', 'malka-crm' ), $sii['rut'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $c( 'sii_giro', __( 'Giro', 'malka-crm' ), $sii['giro'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo $c( 'sii_dir', __( 'Dirección', 'malka-crm' ), $sii['dir'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="esc-grid2">';
		echo $c( 'sii_comuna', __( 'Comuna', 'malka-crm' ), $sii['comuna'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $c( 'sii_ciudad', __( 'Ciudad', 'malka-crm' ), $sii['ciudad'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $c( 'fecha_mia', __( 'Fecha de emisión', 'malka-crm' ), $fem, 'date', __( 'De este día sale el tipo de cambio.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<p class="esc-f"><label class="esc-f__l" for="f-tc">%1$s</label><span class="esc-f__w"><span class="esc-f__u">$</span>' .
			'<input class="esc-f__i" type="text" inputmode="decimal" id="f-tc" name="tc" value="%2$s" autocomplete="off" /></span>' .
			'<input type="hidden" name="tc_fecha" id="f-tc_fecha" value="%3$s" /><span class="esc-f__h"><span id="esc-tc-nota">%4$s</span> <a class="esc-em__ver" id="esc-tc-ver" href="%5$s" target="_blank" rel="noopener" data-usd="https://www.sii.cl/valores_y_fechas/dolar/dolar" data-eur="%6$s">%7$s</a></span></p>',
			esc_html__( 'Tipo de cambio', 'malka-crm' ),
			esc_attr( $d['tc'] > 0 ? number_format( $d['tc'], 2, ',', '.' ) : '' ),
			esc_attr( $d['tc_fecha'] ),
			esc_html( $d['tc_fecha'] ? sprintf( /* translators: fecha */ __( 'Banco Central, valor del %s.', 'malka-crm' ), date_i18n( 'd/m/Y', strtotime( $d['tc_fecha'] ) ) ) : __( 'Del Banco Central, para la fecha de emisión.', 'malka-crm' ) ),
			esc_url( self::url_tipo_cambio( $moneda, $d['tc_fecha'] ? $d['tc_fecha'] : $fem ) ),
			esc_attr( self::url_tipo_cambio( 'EUR', '' ) ),
			esc_html__( 'Corroborar ↗', 'malka-crm' )
		);
		echo '</div>';
		printf(
			'<div class="esc-docsii__neto"><span>%1$s</span><b id="esc-sii-neto">%2$s</b><small id="esc-sii-cuenta">%3$s</small></div>',
			esc_html__( 'Neto a facturar', 'malka-crm' ),
			esc_html( $d['base'] ? Malka_CRM_Settings::money( $d['base'] ) : '—' ),
			esc_html( ( $d['tc'] > 0 && $d['original'] > 0 ) ? sprintf( '%s %s × %s', $moneda, number_format_i18n( $d['original'], 2 ), number_format_i18n( $d['tc'], 2 ) ) : '' )
		);
		printf( '<div id="esc-doc-folio-mio"%s>', 'emitida' === $d['emito'] ? '' : ' hidden' );
		echo $c( 'folio_mio', __( 'Folio de la factura que emití', 'malka-crm' ), $d['folio_mio'], 'text', __( 'El número que le pusiste tú al emitirla.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div>';

		echo '</div></div>';
		echo '</div>';

		// Va siempre en el HTML y el JS lo esconde: así cambiar el tipo no
		// obliga a recargar y perder lo que ya escribió.
		printf(
			'<div id="esc-doc-ref"%s>%s</div>',
			'nota' === $d['tipo'] ? '' : ' hidden',
			self::campo( 'ref', __( 'Corrige al documento', 'malka-crm' ), $d['ref'], 'text', __( 'El folio de la factura o boleta que esta nota deja sin efecto.', 'malka-crm' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		/*
		 * El monto y las notas, en el mismo bloque: eran un cuadro aparte
		 * muy grande para dos campos. Van en la última fila, separados por
		 * una línea fina.
		 */
		$g = self::desglose( $d['tipo'], $d['base'] );

		echo '<div class="esc-docf esc-docf--m">';
		printf(
			'<p class="esc-f esc-docf__monto"><label class="esc-f__l" for="f-base" id="esc-doc-rotulo">%1$s</label>' .
			'<span class="esc-f__w"><span class="esc-f__u">$</span>' .
			'<input class="esc-f__i" type="text" inputmode="numeric" autocomplete="off" id="f-base" name="base" value="%2$s" placeholder="0" data-doc-base data-monto /></span>' .
			'<span class="esc-f__h" id="esc-doc-ayuda">%3$s</span></p>',
			esc_html( $monto['label'] ),
			esc_attr( $d['base'] ? number_format( (float) $d['base'], 0, ',', '.' ) : '' ),
			esc_html( $monto['ayuda'] )
		);
		printf(
			'<p class="esc-f esc-docf__notas"><label class="esc-f__l" for="f-notas">%1$s</label>' .
			'<textarea class="esc-f__i esc-f__t" id="f-notas" name="notas" rows="1" placeholder="%3$s">%2$s</textarea></p>',
			esc_html__( 'Notas', 'malka-crm' ),
			esc_textarea( $d['notas'] ),
			esc_attr__( 'Opcional', 'malka-crm' )
		);
		echo '</div>';

		echo '</section>';

		echo '</div><aside class="esc-docw__side">';

		/* ── El total, a la vista mientras se escribe ── */
		echo '<section class="esc-panel esc-docw__total">';
		echo '<div class="esc-docw__th"><span class="esc-nh__k">' . esc_html__( 'Total del documento', 'malka-crm' ) . '</span>';
		if ( ! $nuevo ) {
			printf(
				'<a class="esc-nh__bin" href="%1$s" data-confirmar="%2$s" title="%3$s" aria-label="%3$s">' .
				'<svg class="esc-x-ic" viewBox="0 0 16 16" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></a>',
				esc_url( self::delete_url( $id ) ),
				esc_attr( sprintf( /* translators: documento */ __( '¿Mandar %s a la papelera? La puedes recuperar después.', 'malka-crm' ), $d['rotulo'] . ( $d['folio'] ? ' N° ' . $d['folio'] : '' ) ) ),
				esc_attr__( 'Mandar este documento a la papelera', 'malka-crm' )
			);
		}
		echo '</div>';
		echo '<div class="esc-desglose" id="esc-desglose">';
		echo self::desglose_html( $d['tipo'], $g ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></section>';

		/* ── A qué cotización corresponde ── */
		echo self::bloque_cotizacion( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/* ── El PDF ── */
		echo self::bloque_archivo( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</aside></div>';

		/* ── Barra ── */
		echo '<div class="esc-cfg__save esc-ed__save">';
		printf(
			'<span class="esc-ed__meta">%s</span>',
			esc_html(
				$nuevo
					? __( 'Se anota en el registro y suma en el mes de su fecha.', 'malka-crm' )
					: sprintf(
						/* translators: documento */
						__( 'Editando %s', 'malka-crm' ),
						$d['rotulo'] . ( $d['folio'] ? ' N° ' . $d['folio'] : '' )
					)
			)
		);
		printf(
			'<a class="esc-btn" href="%s">%s</a>',
			esc_url( Malka_CRM_Dashboard::view_url( 'documentos' ) ),
			esc_html__( 'Cancelar', 'malka-crm' )
		);
		printf(
			'<button type="submit" class="esc-btn esc-btn--primary">%s</button>',
			esc_html( $nuevo ? __( 'Anotar', 'malka-crm' ) : __( 'Guardar', 'malka-crm' ) )
		);
		echo '</div>';

		echo '</form></div>';

		return ob_get_clean();
	}

	/**
	 * El desglose en pantalla.
	 *
	 * Se arma en PHP y lo repinta el JS con la misma forma, para que lo que
	 * ella ve mientras escribe sea lo mismo que se guarda.
	 *
	 * @param string $tipo Tipo.
	 * @param array  $g    Desglose.
	 * @return string
	 */
	public static function desglose_html( $tipo, $g ) {
		$filas = array();

		if ( 'honorarios' === $tipo ) {
			$filas[] = array( __( 'Honorarios brutos', 'malka-crm' ), $g['neto'], '' );
			$filas[] = array(
				sprintf( /* translators: porcentaje */ __( 'Retención (%s%%)', 'malka-crm' ), (float) Malka_CRM_Settings::get( 'retencion', 15.25 ) ),
				-$g['retencion'],
				'',
			);
			$filas[] = array( __( 'Te depositan', 'malka-crm' ), $g['recibe'], 'is-total' );
		} else {
			$filas[] = array( 'exenta' === $tipo ? __( 'Monto exento', 'malka-crm' ) : __( 'Neto', 'malka-crm' ), $g['neto'], '' );
			if ( 'exenta' === $tipo ) {
				$filas[] = array( __( 'IVA', 'malka-crm' ) . ' · ' . __( 'exenta', 'malka-crm' ), 0, '' );
			} else {
				$filas[] = array(
					sprintf( /* translators: porcentaje */ __( 'IVA (%s%%)', 'malka-crm' ), (float) Malka_CRM_Settings::get( 'iva', 19 ) ),
					$g['iva'],
					'',
				);
			}
			$filas[] = array(
				'nota' === $tipo ? __( 'Total que se anula', 'malka-crm' ) : __( 'Total del documento', 'malka-crm' ),
				$g['total'],
				'is-total',
			);
		}

		ob_start();

		foreach ( $filas as $f ) {
			printf(
				'<span class="esc-desglose__r %1$s"><b>%2$s</b><u>%3$s</u></span>',
				esc_attr( $f[2] ),
				esc_html( $f[0] ),
				esc_html( Malka_CRM_Settings::money( (int) $f[1] ) )
			);
		}

		return ob_get_clean();
	}

	/**
	 * Guarda, reemplaza o quita el PDF del documento.
	 *
	 * @param int $id Documento.
	 * @return string '' si salió bien, o la clave del aviso.
	 */
	/**
	 * Qué decirle del PDF antes de que lo elija.
	 *
	 * El tope sale del servidor, no de un número escrito a mano, y si hay con
	 * qué comprimir se promete sólo lo que se puede cumplir.
	 *
	 * @return string
	 */
	public static function ayuda_pdf() {
		if ( ! class_exists( 'Malka_CRM_Invoices' ) ) {
			return __( 'Sólo PDF. No queda en una dirección pública: se entrega al que tiene permiso y a nadie más.', 'malka-crm' );
		}

		$tope = number_format_i18n( Malka_CRM_Invoices::tope_bytes() / MB_IN_BYTES, 1 ) . ' MB';

		$peso = Malka_CRM_Invoices::hay_compresor()
			? sprintf(
				/* translators: tope de subida */
				__( 'Sólo PDF, hasta %s. Si pesa de más se comprime solo.', 'malka-crm' ),
				$tope
			)
			: sprintf(
				/* translators: tope de subida */
				__( 'Sólo PDF, hasta %s.', 'malka-crm' ),
				$tope
			);

		return $peso . ' ' . __( 'No queda en una dirección pública: se entrega al que tiene permiso y a nadie más.', 'malka-crm' );
	}

	/**
	 * Dónde queda el recado de lo que pasó con el archivo.
	 *
	 * Va en el usuario y no en la URL porque es una frase entera, con cifras
	 * adentro, y porque se lee una sola vez: en cuanto la ve, se borra.
	 */
	const META_NOTA = '_malka_crm_doc_nota';

	/**
	 * Deja dicho qué pasó con el archivo y devuelve el código del aviso.
	 *
	 * @param string $texto  La frase que ella va a leer.
	 * @param string $codigo 'no', 'nopdf' u 'ok'.
	 * @return string El código, para que el que llama redirija.
	 */
	private static function anotar_fallo_pdf( $texto, $codigo = 'no' ) {
		$uid = get_current_user_id();

		if ( $uid ) {
			update_user_meta( $uid, self::META_NOTA, wp_json_encode( array( $codigo, (string) $texto ) ) );
		}

		return $codigo;
	}

	/**
	 * El recado guardado, que se lee una vez y se borra.
	 *
	 * @return array {codigo, texto} vacío si no hay nada.
	 */
	public static function nota() {
		$uid = get_current_user_id();

		if ( ! $uid ) {
			return array();
		}

		$crudo = (string) get_user_meta( $uid, self::META_NOTA, true );

		if ( '' === $crudo ) {
			return array();
		}

		delete_user_meta( $uid, self::META_NOTA );

		$par = json_decode( $crudo, true );

		if ( ! is_array( $par ) || count( $par ) < 2 ) {
			return array();
		}

		return array(
			'codigo' => (string) $par[0],
			'texto'  => (string) $par[1],
		);
	}

	/**
	 * Qué le pasó a una subida que PHP cortó antes de llegar acá.
	 *
	 * @param int $error Código de $_FILES.
	 * @return string
	 */
	private static function motivo_subida( $error ) {
		$tope = class_exists( 'Malka_CRM_Invoices' )
			? number_format_i18n( Malka_CRM_Invoices::tope_bytes() / MB_IN_BYTES, 1 ) . ' MB'
			: '';

		switch ( (int) $error ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return $tope
					? sprintf(
						/* translators: tope de subida */
						__( 'El archivo pesa más de lo que este servidor deja subir. El máximo es %s.', 'malka-crm' ),
						$tope
					)
					: __( 'El archivo pesa más de lo que este servidor deja subir.', 'malka-crm' );

			case UPLOAD_ERR_PARTIAL:
				return __( 'La subida se cortó a la mitad. Vuelve a intentarlo.', 'malka-crm' );

			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'El servidor no pudo escribir el archivo. Es un problema del hosting, no del PDF.', 'malka-crm' );

			case UPLOAD_ERR_EXTENSION:
				return __( 'Una extensión del servidor bloqueó la subida.', 'malka-crm' );
		}

		return __( 'No se pudo subir el archivo.', 'malka-crm' );
	}

	private static function guardar_pdf( $id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$quitar = ! empty( $_POST['quitar_pdf'] );
		$fallo  = isset( $_FILES['pdf']['error'] ) ? (int) $_FILES['pdf']['error'] : UPLOAD_ERR_NO_FILE;
		$eligio = isset( $_FILES['pdf'] ) && ! empty( $_FILES['pdf']['name'] );
		$subio  = ( $eligio && UPLOAD_ERR_OK === $fallo );

		/*
		 * Eligió un archivo y PHP lo rechazó antes de que llegara acá —casi
		 * siempre por pasarse de upload_max_filesize—. Esto se iba por el
		 * mismo desagüe que «no eligió nada»: el documento se guardaba y
		 * nadie decía que el archivo no estaba. Ahora se cuenta.
		 */
		if ( $eligio && ! $subio ) {
			return self::anotar_fallo_pdf( self::motivo_subida( $fallo ) );
		}

		// Quitarlo y reemplazarlo a la vez es lo mismo que reemplazarlo.
		if ( $quitar && ! $subio ) {
			self::borrar_pdf( $id );
			return '';
		}

		if ( ! $subio ) {
			// Sin archivo nuevo, lo que ya había se queda donde está.
			return '';
		}

		if ( ! class_exists( 'Malka_CRM_Invoices' ) ) {
			return self::anotar_fallo_pdf( __( 'Falta el módulo que guarda los archivos.', 'malka-crm' ) );
		}

		$guardado = Malka_CRM_Invoices::guardar_archivo( $_FILES['pdf'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( is_wp_error( $guardado ) ) {
			return self::anotar_fallo_pdf(
				$guardado->get_error_message(),
				'bad_pdf' === $guardado->get_error_code() ? 'nopdf' : 'no'
			);
		}

		$ext = strtolower( pathinfo( $guardado['original'], PATHINFO_EXTENSION ) );

		if ( 'pdf' !== $ext ) {
			// La carpeta acepta HTML por las facturas viejas; acá no.
			$ruta = trailingslashit( Malka_CRM_Invoices::storage_path() ) . ltrim( $guardado['relative'], '/' );

			if ( file_exists( $ruta ) ) {
				wp_delete_file( $ruta );
			}

			return self::anotar_fallo_pdf( __( 'Eso no era un PDF. Vuelve a subirlo.', 'malka-crm' ), 'nopdf' );
		}

		// Se achicó para que cupiera: conviene decirlo, no hacerlo a escondidas.
		if ( ! empty( $guardado['comprimido'] ) ) {
			self::anotar_fallo_pdf(
				sprintf(
					/* translators: peso final del PDF */
					__( 'El PDF pesaba de más, así que se comprimió: quedó en %s y se ve igual.', 'malka-crm' ),
					number_format_i18n( ( (int) $guardado['bytes'] ) / MB_IN_BYTES, 1 ) . ' MB'
				),
				'ok'
			);
		}

		// El de antes se borra recién cuando el nuevo ya está en el disco.
		self::borrar_pdf( $id );

		update_post_meta( $id, self::META_ARCHIVO, $guardado['relative'] );
		update_post_meta( $id, self::META_ARCHIVO_NOMBRE, $guardado['original'] );

		return '';
	}

	/**
	 * Borra el PDF de un documento, si lo tiene.
	 *
	 * @param int $id Documento.
	 */
	private static function borrar_pdf( $id ) {
		$ruta = self::ruta_archivo( $id );

		if ( '' !== $ruta ) {
			wp_delete_file( $ruta );
		}

		delete_post_meta( $id, self::META_ARCHIVO );
		delete_post_meta( $id, self::META_ARCHIVO_NOMBRE );
	}

	/**
	 * El PDF del documento: subirlo, verlo y quitarlo.
	 *
	 * @param array $d Documento.
	 * @return string
	 */
	private static function bloque_archivo( $d ) {
		$tiene  = $d['id'] ? self::tiene_archivo( $d['id'] ) : false;
		$compra = ( 'compra' === $d['sentido'] );

		ob_start();
		echo '<section class="esc-panel esc-drop-w"><div class="esc-ph"><div>';
		printf( '<h2>%s</h2>', esc_html__( 'El documento', 'malka-crm' ) );
		printf(
			'<p>%s</p>',
			esc_html(
				$compra
					? __( 'El archivo que te mandó el proveedor, guardado con el documento', 'malka-crm' )
					: __( 'El cliente lo descarga desde su portal', 'malka-crm' )
			)
		);
		echo '</div></div>';

		/*
		 * El archivo que ya está guardado: el JS reescribe esta línea cuando
		 * un guardado automático sube o quita uno.
		 */
		printf(
			'<p class="esc-arch__hay" id="esc-arch-hay"%4$s><span class="esc-arch__ic" aria-hidden="true">PDF</span>' .
			'<b>%1$s</b><a href="%2$s" target="_blank" rel="noopener">%3$s</a>' .
			'<button type="button" class="esc-arch__x" data-quitar-pdf>%5$s</button></p>',
			esc_html( $tiene ? self::nombre_archivo( $d['id'] ) : '' ),
			esc_url( $tiene ? self::url_pdf( $d['id'], true ) : '#' ),
			esc_html__( 'Ver', 'malka-crm' ),
			$tiene ? '' : ' hidden',
			esc_html__( 'Quitar', 'malka-crm' )
		);
		echo '<input type="hidden" name="quitar_pdf" value="" data-quitar-campo />';

		/*
		 * Zona para arrastrar. Si el documento está en blanco, al soltar el
		 * PDF (o el XML del SII) se leen sus datos y se completan solos: tipo,
		 * folio, fecha, cliente, RUT y monto. Lo que ella ya escribió no se
		 * pisa.
		 */
		printf(
			'<label class="esc-drop" for="f-pdf" data-drop>' .
			'<span class="esc-drop__ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4"/><path d="m7 9 5-5 5 5"/><path d="M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/></svg></span>' .
			'<b data-drop-t>%1$s</b><span data-drop-s>%2$s</span>' .
			'<input class="esc-drop__in" type="file" id="f-pdf" name="pdf" accept="application/pdf,.pdf,.xml,text/xml" /></label>',
			esc_html( $tiene ? __( 'Arrastra otro para reemplazarlo', 'malka-crm' ) : __( 'Arrastra el PDF aquí', 'malka-crm' ) ),
			esc_html__( 'o haz clic para buscarlo. Si el documento está en blanco, se completa solo con sus datos.', 'malka-crm' )
		);
		printf( '<p class="esc-drop__msg" data-drop-msg hidden></p>' );
		printf( '<p class="esc-f__h esc-drop__h">%s</p>', esc_html( self::ayuda_pdf() ) );

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Lo que el guardado automático necesita saber del documento recién
	 * guardado: su número, su dirección y el archivo que quedó.
	 *
	 * @param int    $id    Documento.
	 * @param string $aviso Resultado de guardar el PDF.
	 */
	private static function responder_auto( $id, $aviso ) {
		$nota = ( '' !== $aviso && 'ok' !== $aviso ) ? self::nota() : array();
		$hay  = self::tiene_archivo( $id );

		wp_send_json_success(
			array(
				'id'      => (int) $id,
				'url'     => self::edit_url( $id ),
				'archivo' => $hay
					? array(
						'nombre' => self::nombre_archivo( $id ),
						'url'    => self::url_pdf( $id, true ),
					)
					: null,
				'aviso'   => ( '' !== $aviso && 'ok' !== $aviso )
					? ( ! empty( $nota['texto'] ) ? $nota['texto'] : __( 'No se pudo subir el archivo.', 'malka-crm' ) )
					: '',
			)
		);
	}

	/**
	 * Si el documento tiene un archivo guardado y además existe en el disco.
	 *
	 * @param int $id Documento.
	 * @return bool
	 */
	public static function tiene_archivo( $id ) {
		return '' !== self::ruta_archivo( $id );
	}

	/**
	 * La ruta del archivo en el disco.
	 *
	 * @param int $id Documento.
	 * @return string '' si no hay, o si se salió de la carpeta blindada.
	 */
	public static function ruta_archivo( $id ) {
		if ( ! class_exists( 'Malka_CRM_Invoices' ) ) {
			return '';
		}

		$rel = (string) get_post_meta( (int) $id, self::META_ARCHIVO, true );

		if ( '' === $rel ) {
			return '';
		}

		$base = Malka_CRM_Invoices::storage_path();
		$ruta = trailingslashit( $base ) . ltrim( $rel, '/' );

		if ( ! file_exists( $ruta ) ) {
			return '';
		}

		/*
		 * Que el archivo esté de verdad dentro de la carpeta blindada. La
		 * ruta viene de la base de datos, y un «../» ahí adentro serviría
		 * cualquier archivo del servidor.
		 */
		$real = realpath( $ruta );
		$raiz = realpath( $base );

		if ( ! $real || ! $raiz || 0 !== strpos( $real, $raiz ) ) {
			return '';
		}

		return $real;
	}

	/**
	 * Cómo se llamaba el archivo cuando lo subió.
	 *
	 * @param int $id Documento.
	 * @return string
	 */
	public static function nombre_archivo( $id ) {
		$n = trim( (string) get_post_meta( (int) $id, self::META_ARCHIVO_NOMBRE, true ) );

		return $n ? $n : __( 'documento.pdf', 'malka-crm' );
	}

	/**
	 * La dirección para bajar el PDF de un documento.
	 *
	 * @param int $id Documento.
	 * @return string
	 */
	public static function url_pdf( $id, $abrir = false ) {
		$args = array(
			'action' => 'malka_crm_doc_pdf',
			'doc'    => (int) $id,
		);

		// Ella lo abre para mirarlo; el cliente lo baja. El botón de cada
		// lado dice una cosa distinta, así que el archivo tiene que llegar
		// como el botón promete.
		if ( $abrir ) {
			$args['ver'] = 1;
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Dirección de la vista imprimible de un documento (cuando no hay PDF).
	 *
	 * @param int    $id   Documento.
	 * @param string $modo '' para mirar, 'imprimir' para abrir el diálogo de impresión.
	 * @return string
	 */
	public static function url_ver( $id, $modo = '' ) {
		return add_query_arg(
			array_filter(
				array(
					'action' => 'malka_crm_doc_ver',
					'doc'    => (int) $id,
					'modo'   => $modo,
				)
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * El documento como página para mirar, imprimir o guardar en PDF.
	 *
	 * No reemplaza al documento tributario del SII: es su representación con
	 * los mismos datos, para que el cliente lo tenga a mano.
	 */
	public static function handle_ver() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;
		if ( ! $id || self::POST_TYPE !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Ese documento no existe.', 'malka-crm' ), 404 );
		}
		if ( ! self::puede_ver( $id ) ) {
			wp_die( esc_html__( 'Este documento no es tuyo.', 'malka-crm' ), 403 );
		}
		$d    = self::get( $id );
		$s    = Malka_CRM_Settings::all();
		$modo = isset( $_GET['modo'] ) ? sanitize_key( wp_unslash( $_GET['modo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$m    = function ( $n ) {
			return Malka_CRM_Settings::money( (int) $n );
		};
		$quote = $d['quote'] ? (string) get_post_meta( $d['quote'], '_malka_pm_quote_number', true ) : '';
		$tit   = trim( $d['rotulo'] . ( $d['folio'] ? ' N° ' . $d['folio'] : '' ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html( $tit ); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#F2F2F5;font-family:-apple-system,BlinkMacSystemFont,"Helvetica Neue",Inter,system-ui,sans-serif;color:#1D1D1F;font-size:14px}
.bar{max-width:820px;margin:0 auto;padding:16px 20px 0;display:flex;justify-content:flex-end;gap:8px}
.bar button{font:inherit;font-size:13px;font-weight:500;border:0;border-radius:100px;padding:9px 16px;cursor:pointer;background:#fff;color:#1D1D1F;box-shadow:inset 0 0 0 1px #D9D6D0}
.hoja{max-width:820px;margin:16px auto 40px;background:#fff;border-radius:16px;padding:44px 48px}
.top{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;padding-bottom:28px;border-bottom:1px solid #ECEAE5}
.em b{display:block;font-size:17px;margin-bottom:4px}.em span{display:block;color:#6B6964;font-size:12.5px;line-height:1.55}
.em img{max-height:46px;max-width:180px;margin-bottom:12px;display:block}
.caja{border:1.5px solid #1D1D1F;border-radius:10px;padding:14px 20px;text-align:center;min-width:220px}
.caja small{display:block;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6B6964}.caja b{display:block;font-size:16px;margin:4px 0}.caja span{font-size:13px}
.k{font-size:10.5px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;color:#8A8780;margin:26px 0 8px}
.rec{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;font-size:13.5px}.rec span{color:#6B6964}
table{width:100%;border-collapse:collapse;margin-top:6px}td{padding:10px 0;border-bottom:1px solid #F0EEEA;font-size:13.5px}td:last-child{text-align:right;font-variant-numeric:tabular-nums}
.tot td{border:0;padding:6px 0}.tot tr:last-child td{font-size:17px;font-weight:600;padding-top:12px;border-top:1px solid #1D1D1F}
.pie{margin-top:30px;font-size:11.5px;color:#8A8780;line-height:1.5}
@media print{body{background:#fff}.bar{display:none}.hoja{margin:0;border-radius:0;padding:24px}}
@media (max-width:640px){.hoja{padding:26px 20px;margin:12px}.top{flex-direction:column}.rec{grid-template-columns:1fr}}
</style></head><body>
<div class="bar"><button type="button" onclick="window.print()"><?php esc_html_e( 'Imprimir o guardar en PDF', 'malka-crm' ); ?></button></div>
<div class="hoja">
	<div class="top">
		<div class="em">
			<?php if ( ! empty( $s['logo_url'] ) ) : ?><img src="<?php echo esc_url( $s['logo_url'] ); ?>" alt=""><?php endif; ?>
			<b><?php echo esc_html( ! empty( $s['razon_social'] ) ? $s['razon_social'] : get_bloginfo( 'name' ) ); ?></b>
			<?php if ( ! empty( $s['giro'] ) ) : ?><span><?php echo esc_html( $s['giro'] ); ?></span><?php endif; ?>
			<span><?php echo esc_html( trim( ( isset( $s['direccion'] ) ? $s['direccion'] : '' ) . ( ! empty( $s['comuna'] ) ? ', ' . $s['comuna'] : '' ), ', ' ) ); ?></span>
			<?php if ( ! empty( $s['email'] ) ) : ?><span><?php echo esc_html( $s['email'] ); ?></span><?php endif; ?>
		</div>
		<div class="caja">
			<small><?php echo esc_html( ! empty( $s['rut'] ) ? 'RUT ' . $s['rut'] : '' ); ?></small>
			<b><?php echo esc_html( mb_strtoupper( $d['rotulo'] ) ); ?></b>
			<span><?php echo esc_html( $d['folio'] ? 'N° ' . $d['folio'] : '' ); ?></span>
		</div>
	</div>

	<p class="k"><?php esc_html_e( 'Cliente', 'malka-crm' ); ?></p>
	<div class="rec">
		<div><span><?php esc_html_e( 'Razón social:', 'malka-crm' ); ?></span> <?php echo esc_html( $d['cliente'] ); ?></div>
		<div><span>RUT:</span> <?php echo esc_html( $d['rut'] ); ?></div>
		<?php if ( $d['giro'] ) : ?><div><span><?php esc_html_e( 'Giro:', 'malka-crm' ); ?></span> <?php echo esc_html( $d['giro'] ); ?></div><?php endif; ?>
		<?php if ( $d['dir'] || $d['comuna'] ) : ?><div><span><?php esc_html_e( 'Dirección:', 'malka-crm' ); ?></span> <?php echo esc_html( trim( $d['dir'] . ', ' . $d['comuna'], ', ' ) ); ?></div><?php endif; ?>
		<div><span><?php esc_html_e( 'Fecha de emisión:', 'malka-crm' ); ?></span> <?php echo esc_html( $d['fecha'] ? date_i18n( 'j \d\e F \d\e Y', strtotime( $d['fecha'] ) ) : '—' ); ?></div>
		<?php if ( $quote ) : ?><div><span><?php esc_html_e( 'Referencia:', 'malka-crm' ); ?></span> <?php echo esc_html( sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $quote ) ); ?></div><?php endif; ?>
	</div>

	<p class="k"><?php esc_html_e( 'Detalle', 'malka-crm' ); ?></p>
	<table>
		<tr><td><?php echo esc_html( $d['notas'] ? $d['notas'] : ( $d['ref'] ? $d['ref'] : ( $quote ? sprintf( /* translators: número */ __( 'Servicios según cotización N° %s', 'malka-crm' ), $quote ) : __( 'Servicios profesionales', 'malka-crm' ) ) ) ); ?></td><td><?php echo esc_html( $m( ! empty( $d['neto'] ) ? $d['neto'] : ( isset( $d['exento'] ) ? $d['exento'] : $d['total'] ) ) ); ?></td></tr>
	</table>
	<table class="tot">
		<?php if ( ! empty( $d['neto'] ) ) : ?><tr><td><?php esc_html_e( 'Neto', 'malka-crm' ); ?></td><td><?php echo esc_html( $m( $d['neto'] ) ); ?></td></tr><?php endif; ?>
		<?php if ( ! empty( $d['exento'] ) ) : ?><tr><td><?php esc_html_e( 'Exento', 'malka-crm' ); ?></td><td><?php echo esc_html( $m( $d['exento'] ) ); ?></td></tr><?php endif; ?>
		<?php if ( ! empty( $d['iva'] ) ) : ?><tr><td><?php echo esc_html( sprintf( 'IVA (%s%%)', (float) Malka_CRM_Settings::get( 'iva', 19 ) ) ); ?></td><td><?php echo esc_html( $m( $d['iva'] ) ); ?></td></tr><?php endif; ?>
		<?php if ( ! empty( $d['retencion'] ) ) : ?><tr><td><?php esc_html_e( 'Retención', 'malka-crm' ); ?></td><td>−<?php echo esc_html( $m( $d['retencion'] ) ); ?></td></tr><?php endif; ?>
		<tr><td><?php esc_html_e( 'Total', 'malka-crm' ); ?></td><td><?php echo esc_html( $m( $d['total'] ) ); ?></td></tr>
	</table>
	<p class="pie"><?php esc_html_e( 'Representación del documento emitido. El documento tributario electrónico oficial está registrado en el Servicio de Impuestos Internos (SII).', 'malka-crm' ); ?></p>
</div>
<?php if ( 'imprimir' === $modo ) : ?><script>window.addEventListener('load',function(){setTimeout(function(){window.print();},250);});</script><?php endif; ?>
</body></html>
		<?php
		exit;
	}

	/**
	 * Entrega el PDF a quien corresponde.
	 *
	 * No hay enlace público al archivo: esto es la única puerta, y pregunta
	 * dos cosas antes de abrirla —si es ella, o si es el cliente dueño del
	 * documento con su portal abierto—.
	 */
	public static function handle_pdf() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;

		if ( ! $id || self::POST_TYPE !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Ese documento no existe.', 'malka-crm' ), 404 );
		}

		if ( ! self::puede_ver( $id ) ) {
			wp_die( esc_html__( 'Este documento no es tuyo.', 'malka-crm' ), 403 );
		}

		$ruta = self::ruta_archivo( $id );

		if ( '' === $ruta ) {
			wp_die( esc_html__( 'Este documento no tiene un PDF subido.', 'malka-crm' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $ruta ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$abrir = ! empty( $_GET['ver'] );

		header(
			sprintf(
				'Content-Disposition: %1$s; filename="%2$s"',
				$abrir ? 'inline' : 'attachment',
				sanitize_file_name( self::nombre_archivo( $id ) )
			)
		);
		header( 'X-Content-Type-Options: nosniff' );
		// Para mirarlo en la ventana del portal no va el sandbox: el visor de
		// PDF del navegador no abre dentro de un marco aislado.
		if ( ! $abrir ) {
			header( "Content-Security-Policy: sandbox; default-src 'none'" );
		}

		readfile( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Quién puede bajar el PDF de un documento.
	 *
	 * @param int $id Documento.
	 * @return bool
	 */
	public static function puede_ver( $id ) {
		if ( current_user_can( Malka_CRM_Settings::cap() ) ) {
			return true;
		}

		if ( ! class_exists( 'Malka_CRM_Portal' ) || ! class_exists( 'Malka_CRM_Clients' ) ) {
			return false;
		}

		// El portal le muestra el documento a la ficha de su cotización aunque
		// el RUT anotado sea otro, así que el archivo tiene que abrirle igual.
		$d      = self::get( $id );
		$fichas = array( self::cliente_de( $id ) );

		if ( ! empty( $d['quote'] ) ) {
			$fichas[] = (int) get_post_meta( (int) $d['quote'], '_malka_pm_client_id', true );
		}

		foreach ( array_unique( array_filter( $fichas ) ) as $cliente ) {
			if ( Malka_CRM_Portal::activo( $cliente ) && Malka_CRM_Portal::entrado( $cliente ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * De qué cliente es este documento.
	 *
	 * Se mira igual que en el portal: primero el RUT, que es lo seguro, y si
	 * no, el nombre o la empresa, para los que se anotaron a mano.
	 *
	 * @param int $id Documento.
	 * @return int 0 si no se puede decir.
	 */
	public static function cliente_de( $id ) {
		$d = self::get( $id );

		if ( 'venta' !== $d['sentido'] ) {
			return 0;
		}

		$rut = Malka_CRM_Clients::solo_rut( $d['rut'] );

		if ( $rut ) {
			$cli = Malka_CRM_Clients::por_rut( $rut );

			if ( $cli ) {
				return $cli;
			}
		}

		// Por la cotización que respalda, que también la apunta a una ficha.
		if ( ! empty( $d['quote'] ) ) {
			$cli = (int) get_post_meta( (int) $d['quote'], '_malka_pm_client_id', true );

			if ( $cli && Malka_CRM_Clients::POST_TYPE === get_post_type( $cli ) ) {
				return $cli;
			}
		}

		if ( '' === trim( (string) $d['cliente'] ) ) {
			return 0;
		}

		$quien = Malka_CRM_Clients::plegar( $d['cliente'] );

		foreach ( Malka_CRM_Clients::all() as $c ) {
			if ( Malka_CRM_Clients::plegar( $c['nombre'] ) === $quien
				|| ( $c['empresa'] && Malka_CRM_Clients::plegar( $c['empresa'] ) === $quien ) ) {
				return (int) $c['id'];
			}
		}

		return 0;
	}

	/**
	 * A qué cotización corresponde el documento.
	 *
	 * Enlazarlo es lo que apaga el «No emitida» que el cliente ve en su
	 * cotización, así que no es un adorno.
	 *
	 * @param array $d Documento.
	 * @return string
	 */
	private static function bloque_cotizacion( $d ) {
		if ( ! class_exists( 'Malka_CRM_Dashboard' ) ) {
			return '';
		}

		$quotes = Malka_CRM_Dashboard::quotes_list();

		$negocios = class_exists( 'Malka_CRM_Projects' ) ? Malka_CRM_Projects::all() : array();

		ob_start();
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'A qué trabajo corresponde', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Enlazarlo deja el documento asociado al negocio y a su cotización', 'malka-crm' ) . '</p></div></div>';

		echo '<div class="esc-grid2">';

		// El negocio: sirve igual para ventas y para compras —un gasto se
		// carga a un proyecto— así que va primero.
		if ( $negocios ) {
			printf(
				'<p class="esc-f"><label class="esc-f__l" for="f-proyecto">%s</label><span class="esc-f__w">',
				esc_html__( 'Negocio', 'malka-crm' )
			);
			echo '<select class="esc-f__i" id="f-proyecto" name="proyecto">';
			printf( '<option value="0">%s</option>', esc_html__( 'Ninguno', 'malka-crm' ) );

			foreach ( $negocios as $n ) {
				printf(
					'<option value="%1$d"%2$s>%3$s</option>',
					(int) $n['id'],
					selected( $d['proyecto'], (int) $n['id'], false ),
					esc_html( trim( $n['titulo'] . ( $n['cliente'] ? ' · ' . $n['cliente'] : '' ) ) )
				);
			}

			echo '</select></span></p>';
		}

		if ( ! $quotes ) {
			echo '</div>';
			echo '<p class="esc-hint">' . esc_html__( 'Todavía no hay cotizaciones que enlazar.', 'malka-crm' ) . '</p></section>';
			return ob_get_clean();
		}

		printf(
			'<p class="esc-f"><label class="esc-f__l" for="f-quote">%s</label><span class="esc-f__w">',
			esc_html__( 'Cotización', 'malka-crm' )
		);
		echo '<select class="esc-f__i" id="f-quote" name="quote">';
		printf( '<option value="0">%s</option>', esc_html__( 'Ninguna', 'malka-crm' ) );

		foreach ( $quotes as $q ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $q['id'],
				selected( $d['quote'], (int) $q['id'], false ),
				esc_html(
					sprintf(
						'%s · %s · %s',
						$q['numero'] ? 'N° ' . $q['numero'] : '#' . $q['id'],
						$q['cliente'] ? $q['cliente'] : $q['titulo'],
						Malka_CRM_Settings::money( $q['total'] )
					)
				)
			);
		}

		echo '</select></span>';
		printf(
			'<span class="esc-f__h">%s</span></p>',
			esc_html__( 'Sólo en ventas: al enlazarla, su página deja de decir «No emitida».', 'malka-crm' )
		);
		echo '</div>';

		/*
		 * En compras: con qué pago del estudio o qué movimiento se pagó. Así
		 * la factura, el pago y la salida de plata quedan enlazados.
		 */
		printf(
			'<div id="esc-doc-pago"%1$s><p class="esc-f"><label class="esc-f__l" for="f-enlace">%2$s</label><span class="esc-f__w"><select class="esc-f__i" id="f-enlace" name="enlace">%3$s</select></span><span class="esc-f__h">%4$s</span></p></div>',
			'compra' === $d['sentido'] ? '' : ' hidden',
			esc_html__( 'Pagada con', 'malka-crm' ),
			self::opciones_enlace( $d ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Un pago del estudio (Adobe, hosting…) o un movimiento de Finanzas. Queda enlazado en los dos lados.', 'malka-crm' )
		);
		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Un campo del formulario.
	 *
	 * @param string $nombre   Name.
	 * @param string $etiqueta Rótulo.
	 * @param string $valor    Valor.
	 * @param string $tipo     text|date.
	 * @param string $ayuda    Texto de apoyo.
	 * @return string
	 */
	private static function campo( $nombre, $etiqueta, $valor, $tipo = 'text', $ayuda = '', $dos = array() ) {
		/*
		 * $dos lleva los rótulos de los dos lados —venta y compra— para los
		 * campos que cambian de nombre según eso. El JS los intercambia sin
		 * recargar; sin $dos el campo se comporta como siempre.
		 */
		$par = function ( $clave ) use ( $dos ) {
			if ( empty( $dos[ $clave ] ) ) {
				return '';
			}

			return sprintf(
				' data-venta="%1$s" data-compra="%2$s"',
				esc_attr( $dos[ $clave ][0] ),
				esc_attr( $dos[ $clave ][1] )
			);
		};

		return sprintf(
			'<p class="esc-f"><label class="esc-f__l" for="f-%1$s"%6$s>%2$s</label>' .
			'<span class="esc-f__w"><input class="esc-f__i" type="%3$s" id="f-%1$s" name="%1$s" value="%4$s" /></span>%5$s</p>',
			esc_attr( $nombre ),
			esc_html( $etiqueta ),
			esc_attr( $tipo ),
			esc_attr( (string) $valor ),
			$ayuda ? '<span class="esc-f__h"' . $par( 'ayuda' ) . '>' . esc_html( $ayuda ) . '</span>' : '',
			$par( 'label' )
		);
	}

	/**
	 * Qué documento se está editando.
	 *
	 * @return int 0 si es nuevo.
	 */
	public static function current_id() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return ( $id && self::POST_TYPE === get_post_type( $id ) ) ? $id : 0;
	}
}
