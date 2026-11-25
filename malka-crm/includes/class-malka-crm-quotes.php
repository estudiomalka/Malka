<?php
/**
 * Módulo 10: Cotizaciones y abonos.
 *
 * Cada cotización es un registro que apunta a la página real construida con la
 * plantilla de Malka (estudiomalka.cl/plantilla-cotizaciones/) y guarda su
 * calendario de pagos. Con eso el portal puede avisar de abonos por vencer o
 * atrasados y llevar al cliente directo a la cotización donde están las fechas.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Quotes {

	const POST_TYPE     = 'malka_quote';
	const META_PAYMENTS = '_malka_pm_payments';

	/* Las cuotas que ya estaban pagadas antes de que esto existiera. */
	const OPT_BASE = 'malka_crm_cobros_base';

	/* Si ya respondió qué hacer con esas. */
	const OPT_VISTO = 'malka_crm_cobros_visto';

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_metaboxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_quote' ), 10, 2 );
		// Después de guardar: si quedó sin cuotas, se le pone el plan 60/40.
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'ensure_plan' ), 30, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );

		/*
		 * Marcar una cuota «Completado» tiene que mover el saldo de la cuenta:
		 * es lo que el Inicio promete debajo del número («los pagos de
		 * cotizaciones entran solos») y lo que no pasaba.
		 *
		 * El plan de cuotas lo escriben nueve lugares distintos —el editor,
		 * SumUp, el portal, el asistente, duplicar...— así que esto no se
		 * engancha a cada uno: escucha la meta, que es por donde pasan todos.
		 */
		add_action( 'added_post_meta', array( __CLASS__, 'al_guardar_pagos' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'al_guardar_pagos' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'fijar_base_cobros' ), 20 );
		add_action( 'admin_post_malka_crm_cobros_al_dia', array( __CLASS__, 'handle_cobros_al_dia' ) );
		add_action( 'admin_post_malka_crm_cobros_guardar', array( __CLASS__, 'handle_cobros_guardar' ) );
		add_action( 'admin_post_malka_crm_cuadrar', array( __CLASS__, 'handle_cuadrar' ) );
	}

	/**
	 * «Cuadrar con el banco»: el saldo pasa a ser el de la cartola, con su
	 * fecha, y todo lo anterior deja de sumarse porque ya está dentro.
	 */
	public static function handle_cuadrar() {
		check_admin_referer( 'malka_crm_cuadrar' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$monto = isset( $_POST['saldo'] ) ? (int) preg_replace( '/[^\d-]/', '', (string) wp_unslash( $_POST['saldo'] ) ) : 0;
		$fecha = isset( $_POST['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fecha'] ) ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$fecha = $fecha ? $fecha : current_time( 'Y-m-d' );

		Malka_CRM_Finance::set_balance( $monto, 0, $fecha );

		update_option( self::OPT_VISTO, 1, false );

		wp_safe_redirect(
			add_query_arg( 'cuadrado', 1, Malka_CRM_Dashboard::view_url( 'cobros' ) )
		);
		exit;
	}

	/**
	 * Guarda la selección de la pantalla «Cobros en tu saldo».
	 */
	public static function handle_cobros_guardar() {
		check_admin_referer( 'malka_crm_cobros_guardar' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$marcadas = isset( $_POST['cobro'] ) ? (array) wp_unslash( $_POST['cobro'] ) : array();
		$marcadas = array_map( 'sanitize_text_field', $marcadas );

		$r = self::fijar_cobros( $marcadas );

		// Que haya quedado puesta deja de preguntarlo en el Inicio.
		update_option( self::OPT_VISTO, 1, false );

		$vuelve = class_exists( 'Malka_CRM_Dashboard' ) ? Malka_CRM_Dashboard::view_url( 'cobros' ) : home_url( '/' );

		wp_safe_redirect(
			add_query_arg(
				array( 'mas' => (int) $r['entraron'], 'menos' => (int) $r['salieron'] ),
				$vuelve
			)
		);
		exit;
	}

	/**
	 * «Sumarlos al saldo» y «Ya están»: qué hacer con los cobros viejos.
	 *
	 * La pregunta se hace una vez. Diga lo que diga, no vuelve a aparecer:
	 * la lista de cobros viejos es una foto del día que se instaló esto y
	 * no crece nunca.
	 */
	public static function handle_cobros_al_dia() {
		check_admin_referer( 'malka_crm_cobros_al_dia' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ya     = isset( $_POST['ya'] ) && '1' === (string) $_POST['ya'];
		$n      = $ya ? 0 : self::poner_cobros_al_dia();
		$vuelve = class_exists( 'Malka_CRM_Dashboard' ) ? Malka_CRM_Dashboard::view_url( 'inicio' ) : home_url( '/' );

		update_option( self::OPT_VISTO, 1, false );

		wp_safe_redirect( add_query_arg( 'cobros', $ya ? -1 : (int) $n, $vuelve ) );
		exit;
	}

	/* ─────────────────────────  Cobros al banco  ──────────────────────── */

	/**
	 * Puente entre la meta de cuotas y Movimientos.
	 *
	 * @param int    $mid      Meta ID (no se usa).
	 * @param int    $post_id  Post.
	 * @param string $meta_key Clave.
	 */
	public static function al_guardar_pagos( $mid, $post_id, $meta_key ) {
		if ( self::META_PAYMENTS !== $meta_key || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		self::sincronizar_cobros( (int) $post_id );
	}

	/**
	 * Deja Movimientos de acuerdo con las cuotas de una cotización.
	 *
	 * Cada cuota pagada tiene su entrada en la cuenta; cada cuota que deja
	 * de estarlo la pierde, y el saldo vuelve atrás. Es idempotente: se
	 * puede llamar las veces que sea sin duplicar nada.
	 *
	 * @param int $quote_id Cotización.
	 * @return array Cuántas entró y cuántas deshizo.
	 */
	public static function sincronizar_cobros( $quote_id ) {
		$hecho = array( 'nuevas' => 0, 'deshechas' => 0 );

		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return $hecho;
		}

		$quote_id = (int) $quote_id;
		$previas  = self::base_cobros();
		$cliente  = class_exists( 'Malka_CRM_Analysis' ) ? Malka_CRM_Analysis::quote_client( $quote_id ) : '';

		foreach ( self::get_payments( $quote_id ) as $p ) {
			$pid = isset( $p['id'] ) ? (string) $p['id'] : '';

			if ( '' === $pid ) {
				continue;
			}

			// El «pago total» de una cotización con cuotas es su resumen:
			// anotarlo además de las cuotas contaría la plata dos veces.
			if ( self::es_resumen( $quote_id, $p ) ) {
				continue;
			}

			$pagada = isset( $p['status'] ) && 'pagado' === $p['status'];

			if ( ! $pagada ) {
				if ( Malka_CRM_Finance::olvidar_pago_cuota( $quote_id, $pid ) ) {
					$hecho['deshechas']++;
				}
				continue;
			}

			// Lo que ya estaba pagado antes de esta versión no se anota: esa
			// plata ya está dentro del saldo que ella tiene escrito.
			if ( isset( $previas[ self::clave_cobro( $quote_id, $pid ) ] ) ) {
				continue;
			}

			$monto = (int) self::cifra( isset( $p['amount'] ) ? $p['amount'] : 0 );

			if ( $monto <= 0 ) {
				continue;
			}

			$rotulo = isset( $p['label'] ) && '' !== $p['label'] ? (string) $p['label'] : __( 'Cuota', 'malka-crm' );
			$medio  = isset( $p['method'] ) ? (string) $p['method'] : '';
			$medios = self::payment_methods();
			$nuevo  = Malka_CRM_Finance::record_quote_payment(
				$quote_id,
				$pid,
				$monto,
				trim( $rotulo . ( $cliente ? ' · ' . $cliente : '' ) . ( isset( $medios[ $medio ] ) && $medio ? ' · ' . $medios[ $medio ] : '' ) ),
				! empty( $p['paid_at'] ) ? substr( (string) $p['paid_at'], 0, 10 ) : ''
			);

			if ( $nuevo && ! is_wp_error( $nuevo ) ) {
				$hecho['nuevas']++;
			}
		}

		return $hecho;
	}

	/**
	 * Cómo se nombra una cuota en la foto y en los movimientos.
	 *
	 * @param int    $quote_id Cotización.
	 * @param string $pago_id  Cuota.
	 * @return string
	 */
	public static function clave_cobro( $quote_id, $pago_id ) {
		return (int) $quote_id . ':' . sanitize_key( $pago_id );
	}

	/**
	 * Todas las cuotas cobradas, digan o no «está en el saldo».
	 *
	 * Es la lista con la que ella decide, una por una. `dentro` es lo que
	 * hay hoy: true si esa cuota tiene su movimiento en la cuenta.
	 *
	 * @return array[] clave, qid, pid, monto, titulo, cliente, fecha, dentro.
	 */
	public static function cobros_todos() {
		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return array();
		}

		$puestos = Malka_CRM_Finance::refs_de_cuotas();
		$out     = array();

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $qid ) {
			$qid     = (int) $qid;
			$cliente = class_exists( 'Malka_CRM_Analysis' ) ? Malka_CRM_Analysis::quote_client( $qid ) : '';
			$numero  = (string) get_post_meta( $qid, '_malka_pm_quote_number', true );

			foreach ( self::get_payments( $qid ) as $p ) {
				if ( ! isset( $p['id'], $p['status'] ) || 'pagado' !== $p['status'] ) {
					continue;
				}
				if ( self::es_resumen( $qid, $p ) ) {
					continue;
				}

				$monto = (int) self::cifra( isset( $p['amount'] ) ? $p['amount'] : 0 );

				if ( $monto <= 0 ) {
					continue;
				}

				$clave = self::clave_cobro( $qid, $p['id'] );

				$cuando = ! empty( $p['paid_at'] ) ? substr( (string) $p['paid_at'], 0, 10 ) : '';

				$out[] = array(
					'clave'   => $clave,
					'qid'     => $qid,
					'pid'     => (string) $p['id'],
					'monto'   => $monto,
					'titulo'  => isset( $p['label'] ) && '' !== $p['label'] ? (string) $p['label'] : __( 'Cuota', 'malka-crm' ),
					'cliente' => $cliente,
					'numero'  => $numero,
					'fecha'   => $cuando,
					'dentro'  => isset( $puestos[ $clave ] ),
					// Cobrado antes del último cuadre: ya está dentro del
					// saldo del banco y no se suma ni se resta.
					'antes'   => ! Malka_CRM_Finance::entra_al_saldo( $cuando ),
				);
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( (string) $b['fecha'], (string) $a['fecha'] );
			}
		);

		return $out;
	}

	/**
	 * Cuotas marcadas como pagadas que no están en la cuenta.
	 *
	 * @return array[] Las de cobros_todos() que están fuera.
	 */
	public static function cobros_pendientes() {
		return array_values(
			array_filter(
				self::cobros_todos(),
				function ( $c ) {
					return empty( $c['dentro'] ) && empty( $c['antes'] );
				}
			)
		);
	}

	/**
	 * Deja la cuenta con exactamente los cobros que ella marcó.
	 *
	 * Los que marcó y no estaban, entran; los que desmarcó y estaban, salen
	 * y le devuelven el saldo. Lo segundo es tan importante como lo primero:
	 * un botón que sólo suma deja un error metido para siempre.
	 *
	 * @param string[] $marcadas Claves que tienen que quedar dentro.
	 * @return array entraron, salieron.
	 */
	public static function fijar_cobros( $marcadas ) {
		$quiere  = array_flip( array_map( 'strval', (array) $marcadas ) );
		$previas = self::base_cobros();
		$hecho   = array( 'entraron' => 0, 'salieron' => 0 );
		$suma    = array();

		foreach ( self::cobros_todos() as $c ) {
			// Los de antes del cuadre no se tocan: su plata ya está dentro
			// del saldo del banco, los marque o no.
			if ( ! empty( $c['antes'] ) ) {
				continue;
			}

			$dentro = ! empty( $c['dentro'] );
			$debe   = isset( $quiere[ $c['clave'] ] );

			if ( $dentro === $debe ) {
				continue;
			}

			if ( $debe ) {
				// Sale de la foto para que el puente deje de saltársela.
				unset( $previas[ $c['clave'] ] );
				$suma[ $c['qid'] ] = 1;
				$hecho['entraron']++;
				continue;
			}

			if ( Malka_CRM_Finance::olvidar_pago_cuota( $c['qid'], $c['pid'] ) ) {
				/*
				 * Y vuelve a la foto. Si no, guardar la cotización —o
				 * cualquier cosa que toque sus cuotas— la metería de nuevo
				 * sin que ella lo pidiera, y el saldo se movería solo
				 * después de que ella dijo que no.
				 */
				$previas[ $c['clave'] ] = 1;
				$hecho['salieron']++;
			}
		}

		update_option( self::OPT_BASE, $previas, false );

		foreach ( array_keys( $suma ) as $qid ) {
			self::sincronizar_cobros( (int) $qid );
		}

		return $hecho;
	}

	/**
	 * Mete en la cuenta los cobros que habían quedado fuera.
	 *
	 * @param array $claves Qué poner al día. Vacío: todos.
	 * @return int Cuántos entraron.
	 */
	public static function poner_cobros_al_dia( $claves = array() ) {
		$dentro = array();

		foreach ( self::cobros_todos() as $c ) {
			if ( ! empty( $c['dentro'] ) ) {
				$dentro[] = $c['clave'];
			}
		}

		$pide = $claves
			? array_map( 'strval', (array) $claves )
			: wp_list_pluck( self::cobros_pendientes(), 'clave' );

		$r = self::fijar_cobros( array_merge( $dentro, $pide ) );

		return (int) $r['entraron'];
	}

	/**
	 * Las cuotas que ya estaban pagadas la primera vez que corrió esto.
	 *
	 * Sin esto, abrir una cotización vieja le habría sumado al saldo un pago
	 * de hace meses que ya estaba contado.
	 *
	 * @return array clave => 1
	 */
	public static function base_cobros() {
		$b = get_option( self::OPT_BASE, null );
		return is_array( $b ) ? $b : array();
	}

	/**
	 * Levanta esa foto, una sola vez.
	 */
	public static function fijar_base_cobros() {
		if ( is_array( get_option( self::OPT_BASE, null ) ) ) {
			return;
		}

		$base = array();
		$ids  = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $qid ) {
			foreach ( self::get_payments( $qid ) as $p ) {
				if ( isset( $p['id'], $p['status'] ) && 'pagado' === $p['status'] ) {
					$base[ self::clave_cobro( $qid, $p['id'] ) ] = 1;
				}
			}
		}

		update_option( self::OPT_BASE, $base, false );
	}

	/**
	 * CPT de cotizaciones.
	 */
	public function register_post_type() {

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Cotizaciones', 'malka-crm' ),
					'singular_name' => __( 'Cotización', 'malka-crm' ),
					'add_new'       => __( 'Añadir cotización', 'malka-crm' ),
					'add_new_item'  => __( 'Añadir nueva cotización', 'malka-crm' ),
					'edit_item'     => __( 'Editar cotización', 'malka-crm' ),
					'menu_name'     => __( 'Cotizaciones', 'malka-crm' ),
					'not_found'     => __( 'No hay cotizaciones', 'malka-crm' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/* ──────────────────── Datos ──────────────────── */

	/**
	 * Estados comerciales (los mismos de la plantilla).
	 *
	 * @return array
	 */
	/** Las etiquetas de una cotización («Web», «Branding»…). */
	const META_TAGS = '_malka_pm_quote_tags';

	/**
	 * Las etiquetas de una cotización.
	 *
	 * @param int $qid Cotización.
	 * @return string[]
	 */
	public static function etiquetas( $qid ) {
		$t = get_post_meta( (int) $qid, self::META_TAGS, true );
		return is_array( $t ) ? array_values( array_filter( array_map( 'strval', $t ) ) ) : array();
	}

	/**
	 * Todas las etiquetas que ya se usan, con cuántas cotizaciones tiene cada
	 * una. Sirven para proponerlas al escribir y para filtrar.
	 *
	 * @return array etiqueta => cantidad, de la más usada a la menos.
	 */
	public static function todas_etiquetas() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_TAGS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		$out = array();
		foreach ( $ids as $qid ) {
			foreach ( self::etiquetas( $qid ) as $t ) {
				$out[ $t ] = isset( $out[ $t ] ) ? $out[ $t ] + 1 : 1;
			}
		}
		arsort( $out );
		return $out;
	}

	/**
	 * Limpia lo que llega del campo («web, Branding,  web» → Web, Branding).
	 *
	 * Si ya existe una etiqueta igual (sin importar mayúsculas), se usa esa:
	 * así «web» y «Web» no terminan siendo dos.
	 *
	 * @param string $crudo Separadas por coma.
	 * @return string[]
	 */
	public static function limpiar_etiquetas( $crudo ) {
		$existentes = array();
		foreach ( array_keys( self::todas_etiquetas() ) as $t ) {
			$existentes[ function_exists( 'mb_strtolower' ) ? mb_strtolower( $t ) : strtolower( $t ) ] = $t;
		}

		$out = array();
		foreach ( explode( ',', (string) $crudo ) as $t ) {
			$t = trim( sanitize_text_field( $t ) );
			if ( '' === $t ) {
				continue;
			}
			$t     = function_exists( 'mb_substr' ) ? mb_substr( $t, 0, 30 ) : substr( $t, 0, 30 );
			$llave = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t ) : strtolower( $t );
			if ( isset( $out[ $llave ] ) ) {
				continue;
			}
			$out[ $llave ] = isset( $existentes[ $llave ] ) ? $existentes[ $llave ] : $t;
		}

		return array_slice( array_values( $out ), 0, 8 );
	}

	/**
	 * Un monto escrito como se ve («1.390.000» o «$ 1.390.000») a número.
	 *
	 * Los campos de plata del panel muestran puntos de miles; un número que
	 * ya viene limpio (992460, 992460.5) pasa tal cual.
	 *
	 * @param mixed $v Valor.
	 * @return float
	 */
	public static function cifra( $v ) {
		if ( is_string( $v ) && preg_match( '/^\s*\$?\s*\d{1,3}(\.\d{3})+\s*$/', $v ) ) {
			return (float) preg_replace( '/\D/', '', $v );
		}

		return (float) $v;
	}

	public static function quote_statuses() {
		return array(
			'sin_respuesta' => __( 'Sin respuesta', 'malka-crm' ),
			'aceptada'      => __( 'Aceptada', 'malka-crm' ),
			'en_curso'      => __( 'En curso', 'malka-crm' ),
			'entregado'     => __( 'Entregado', 'malka-crm' ),
			'finalizada'    => __( 'Finalizada', 'malka-crm' ),
			'rechazada'     => __( 'Rechazada', 'malka-crm' ),
			'caducada'      => __( 'Caducada', 'malka-crm' ),
		);
	}

	/**
	 * Estados de un abono.
	 *
	 * @return array
	 */
	public static function payment_statuses() {
		return array(
			'espera'    => __( 'En espera', 'malka-crm' ),
			'confirmar' => __( 'Por confirmar', 'malka-crm' ),
			'pagado'    => __( 'Completado', 'malka-crm' ),
		);
	}

	/**
	 * Qué es cada fila del registro de pagos.
	 *
	 * El pago total no es una cuota más: es la alternativa de pagarlas todas
	 * juntas. Sin esta distinción, una cotización con anticipo, saldo y pago
	 * total nunca llegaría a finalizada, porque siempre faltaría una de tres.
	 *
	 * @return array
	 */
	public static function payment_roles() {
		return array(
			'cuota' => __( 'Cuota', 'malka-crm' ),
			'total' => __( 'Pago total (reemplaza a las cuotas)', 'malka-crm' ),
		);
	}

	/**
	 * Desde cuándo le corre el plazo a una cuota.
	 *
	 * @return array
	 */
	public static function payment_triggers() {
		return array(
			'emision' => __( 'Desde que se emite la cotización', 'malka-crm' ),
			'entrega' => __( 'Desde que se entrega el proyecto', 'malka-crm' ),
		);
	}

	/**
	 * Con qué se pagó una cuota.
	 *
	 * La lista vacía va primero porque una cuota puede estar pagada y nadie
	 * haber anotado el medio: decir «transferencia» por descarte sería
	 * inventar un dato contable.
	 *
	 * @return array
	 */
	public static function payment_methods() {
		return array(
			''              => __( 'Sin registrar', 'malka-crm' ),
			'transferencia' => __( 'Transferencia', 'malka-crm' ),
			'sumup'         => __( 'SumUp', 'malka-crm' ),
			'efectivo'      => __( 'Efectivo', 'malka-crm' ),
			'otro'          => __( 'Otro', 'malka-crm' ),
		);
	}

	/**
	 * Anota con qué se pagó una cuota.
	 *
	 * Lo que ella eligió a mano manda sobre lo que deduce el panel: si el
	 * cliente apretó «pagar con tarjeta» pero al final transfirió, la
	 * corrección de ella tiene que quedarse puesta. Por eso una anotación
	 * automática sólo pisa a otra automática.
	 *
	 * @param int    $quote_id Cotización.
	 * @param string $cuota_id Cuota dentro de la cotización.
	 * @param string $metodo   Clave de payment_methods().
	 * @param bool   $auto     Si lo dedujo el panel y no lo escribió ella.
	 * @return bool Si quedó anotado.
	 */
	public static function set_payment_method( $quote_id, $cuota_id, $metodo, $auto = false ) {
		$metodo = sanitize_key( $metodo );

		if ( '' === $metodo || ! array_key_exists( $metodo, self::payment_methods() ) ) {
			return false;
		}

		$pagos  = self::get_payments( $quote_id );
		$cambio = false;

		foreach ( $pagos as $i => $p ) {
			if ( ! isset( $p['id'] ) || (string) $p['id'] !== (string) $cuota_id ) {
				continue;
			}

			$tiene = isset( $p['method'] ) ? (string) $p['method'] : '';
			$suyo  = ( '' !== $tiene && empty( $p['method_auto'] ) );

			if ( $auto && $suyo ) {
				return false;
			}

			$pagos[ $i ]['method']      = $metodo;
			$pagos[ $i ]['method_auto'] = $auto ? 1 : 0;
			$cambio                     = true;
			break;
		}

		if ( $cambio ) {
			update_post_meta( (int) $quote_id, self::META_PAYMENTS, $pagos );
		}

		return $cambio;
	}

	/**
	 * El plan de pago con que trabaja el estudio.
	 *
	 * Anticipo para empezar, saldo contra entrega, y la opción de pagar todo
	 * de una. El saldo sale marcado contra entrega a propósito: es la única
	 * cuota cuyo plazo no puede saberse al emitir, y si quedara como las
	 * demás le aparecería al cliente una fecha inventada.
	 *
	 * El saldo se calcula restando, no con el 40 %: así las dos cuotas suman
	 * exactamente el total aunque el porcentaje deje decimales.
	 *
	 * @param float    $total Total de la cotización.
	 * @param int|null $pct   Porcentaje del anticipo. Null usa Configuración.
	 * @return array[]
	 */
	public static function default_plan( $total, $pct = null ) {
		$total = (float) $total;

		if ( $total <= 0 ) {
			return array();
		}

		if ( null === $pct ) {
			$pct = class_exists( 'Malka_CRM_Settings' )
				? (int) Malka_CRM_Settings::get( 'anticipo_pct', 60 )
				: 60;
		}

		$pct      = min( 100, max( 1, (int) $pct ) );
		$anticipo = round( $total * ( $pct / 100 ) );
		$saldo    = round( $total - $anticipo );

		$plan = array(
			array(
				'id'      => 'abono-0',
				'label'   => __( 'Anticipo', 'malka-crm' ),
				'amount'  => $anticipo,
				'due'     => '',
				'status'  => 'espera',
				'rol'     => 'cuota',
				'disparo' => 'emision',
				'method'  => '',
				'pay_url' => '',
			),
		);

		if ( $saldo > 0 ) {
			$plan[] = array(
				'id'      => 'abono-1',
				'label'   => __( 'Saldo', 'malka-crm' ),
				'amount'  => $saldo,
				'due'     => '',
				'status'  => 'espera',
				'rol'     => 'cuota',
				'disparo' => 'entrega',
				'method'  => '',
				'pay_url' => '',
			);

			$plan[] = array(
				'id'      => 'abono-2',
				'label'   => __( 'Pago total', 'malka-crm' ),
				'amount'  => round( $total ),
				'due'     => '',
				'status'  => 'espera',
				'rol'     => 'total',
				'disparo' => 'emision',
				'method'  => '',
				'pay_url' => '',
			);
		}

		return $plan;
	}

	/**
	 * Le pone el plan 60/40 a una cotización que todavía no tiene cuotas.
	 *
	 * Solo actúa cuando no hay ninguna: si ella escribió sus propias cuotas,
	 * aunque sea una, no se le toca nada. Es para que no tenga que acordarse
	 * de marcar «desde que se entrega» en cada cotización nueva.
	 *
	 * @param int     $post_id ID.
	 * @param WP_Post $post    Cotización.
	 */
	public static function ensure_plan( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $post || self::POST_TYPE !== $post->post_type || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( self::get_payments( $post_id ) ) {
			return;
		}

		$total = (float) get_post_meta( $post_id, '_malka_pm_quote_total', true );
		$plan  = self::default_plan( $total );

		if ( $plan ) {
			update_post_meta( $post_id, self::META_PAYMENTS, $plan );
		}
	}

	/**
	 * Abonos de una cotización.
	 *
	 * @param int $quote_id ID.
	 * @return array
	 */
	public static function get_payments( $quote_id ) {
		$payments = get_post_meta( (int) $quote_id, self::META_PAYMENTS, true );
		return is_array( $payments ) ? self::cerrar_por_total( $payments ) : array();
	}

	/**
	 * Si el pago total entró, las cuotas quedan pagadas también.
	 *
	 * El pago total es la alternativa de pagar todo de una. Casi todo el
	 * panel —el avance del proyecto, la cobranza, el estado de la
	 * cotización— mira las cuotas y salta la fila «total», así que con el
	 * total pagado y las cuotas en espera el anticipo seguía apareciendo
	 * pendiente. Acá se cierra: cada cuota sin pagar pasa a pagada, con el
	 * medio del total.
	 *
	 * @param array $pagos Cuotas.
	 * @return array
	 */
	public static function cerrar_por_total( $pagos ) {
		$total = null;

		foreach ( $pagos as $p ) {
			if ( isset( $p['rol'], $p['status'] ) && 'total' === $p['rol'] && 'pagado' === $p['status'] ) {
				$total = $p;
				break;
			}
		}

		if ( ! $total ) {
			return $pagos;
		}

		foreach ( $pagos as $i => $p ) {
			if ( isset( $p['rol'] ) && 'total' === $p['rol'] ) {
				continue;
			}
			if ( isset( $p['status'] ) && 'pagado' === $p['status'] ) {
				continue;
			}
			$pagos[ $i ]['status']    = 'pagado';
			$pagos[ $i ]['por_total'] = 1;
			if ( empty( $p['paid_at'] ) && ! empty( $total['paid_at'] ) ) {
				$pagos[ $i ]['paid_at'] = $total['paid_at'];
			}
			if ( empty( $p['method'] ) && ! empty( $total['method'] ) ) {
				$pagos[ $i ]['method']      = $total['method'];
				$pagos[ $i ]['method_auto'] = 1;
			}
		}

		return $pagos;
	}

	/**
	 * Lo que el formulario no trae y no se puede perder al guardar.
	 *
	 * El formulario de cuotas sólo conoce sus columnas: guardar la cotización
	 * borraba la fecha de pago y el código de SumUp. Esto los pasa de las
	 * cuotas viejas a las nuevas (por id), y anota la fecha de hoy en las que
	 * recién pasan a pagadas.
	 *
	 * @param array $nuevas Cuotas recién sanitizadas.
	 * @param array $viejas Cuotas guardadas.
	 * @return array
	 */
	public static function conservar_extras( $nuevas, $viejas ) {
		$extras = array( 'paid_at', 'sumup_tx', 'sumup_monto', 'sumup_fecha' );
		$antes  = array();

		foreach ( (array) $viejas as $v ) {
			if ( isset( $v['id'] ) ) {
				$antes[ (string) $v['id'] ] = $v;
			}
		}

		foreach ( $nuevas as $i => $n ) {
			$id     = isset( $n['id'] ) ? (string) $n['id'] : '';
			$v      = isset( $antes[ $id ] ) ? $antes[ $id ] : array();
			$pagada = isset( $n['status'] ) && 'pagado' === $n['status'];

			if ( ! $pagada ) {
				continue;
			}

			foreach ( $extras as $k ) {
				if ( ! isset( $n[ $k ] ) && ! empty( $v[ $k ] ) ) {
					$nuevas[ $i ][ $k ] = $v[ $k ];
				}
			}

			if ( empty( $nuevas[ $i ]['paid_at'] ) ) {
				$nuevas[ $i ]['paid_at'] = ! empty( $nuevas[ $i ]['sumup_fecha'] )
					? substr( $nuevas[ $i ]['sumup_fecha'], 0, 10 )
					: current_time( 'Y-m-d' );
			}
		}

		return $nuevas;
	}

	/**
	 * ¿Esta fila es el «Pago total» de una cotización que además tiene cuotas?
	 *
	 * Entonces es un resumen de las otras: sumarla junto con ellas cuenta la
	 * plata dos veces.
	 *
	 * @param int   $quote_id Cotización.
	 * @param array $p        Fila.
	 * @return bool
	 */
	public static function es_resumen( $quote_id, $p ) {
		$pagos = self::get_payments( $quote_id );

		if ( isset( $p['rol'] ) && 'total' === $p['rol'] ) {
			foreach ( $pagos as $otro ) {
				if ( ! isset( $otro['rol'] ) || 'total' !== $otro['rol'] ) {
					return true;
				}
			}
			return false;
		}

		/*
		 * Cotizaciones antiguas guardaron el «pago total» sin el rol: una
		 * fila llamada «Total» que vale lo mismo que la suma de las otras
		 * (dos o más) es el resumen, no otra cuota. Contarla duplicaba lo
		 * por cobrar.
		 */
		if ( ! preg_match( '/total|contado|todo/i', isset( $p['label'] ) ? (string) $p['label'] : '' ) ) {
			return false;
		}
		$id    = isset( $p['id'] ) ? (string) $p['id'] : '';
		$monto = (int) self::cifra( isset( $p['amount'] ) ? $p['amount'] : 0 );
		$resto = 0;
		$n     = 0;
		foreach ( $pagos as $otro ) {
			if ( ( isset( $otro['id'] ) ? (string) $otro['id'] : '' ) === $id ) {
				continue;
			}
			$resto += (int) self::cifra( isset( $otro['amount'] ) ? $otro['amount'] : 0 );
			$n++;
		}
		return $n >= 2 && $monto > 0 && abs( $monto - $resto ) <= 2;
	}

	/**
	 * Sanitiza el repetidor de abonos.
	 *
	 * @param array $raw Datos crudos.
	 * @return array
	 */
	public static function sanitize_payments( $raw ) {

		$clean = array();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		$index = 0;
		foreach ( $raw as $row ) {

			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : '';

			if ( '' === $label ) {
				continue;
			}

			$index++;

			$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : 'espera';
			if ( ! array_key_exists( $status, self::payment_statuses() ) ) {
				$status = 'espera';
			}

			// Qué es la cuota y desde cuándo le corre el plazo. Las cotizaciones
			// viejas no traen ninguno de los dos: quedan como cuota normal con
			// plazo desde la emisión, que es como se comportaban antes.
			$rol = isset( $row['rol'] ) ? sanitize_key( $row['rol'] ) : 'cuota';
			if ( ! array_key_exists( $rol, self::payment_roles() ) ) {
				$rol = 'cuota';
			}

			$disparo = isset( $row['disparo'] ) ? sanitize_key( $row['disparo'] ) : 'emision';
			if ( ! array_key_exists( $disparo, self::payment_triggers() ) ) {
				$disparo = 'emision';
			}

			// Con qué se pagó. Un valor que no está en la lista se descarta en
			// vez de guardarse tal cual: esto termina en un informe contable.
			$metodo = isset( $row['method'] ) ? sanitize_key( $row['method'] ) : '';
			if ( ! array_key_exists( $metodo, self::payment_methods() ) ) {
				$metodo = '';
			}

			$clean[] = array(
				'id'     => sanitize_key( 'abono-' . $index ),
				'label'  => $label,
				'amount' => isset( $row['amount'] ) ? round( self::cifra( $row['amount'] ), 2 ) : 0,
				'due'    => isset( $row['due'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $row['due'] ) ) : '',
				'status' => $status,
				'rol'     => $rol,
				'disparo' => $disparo,
				'method'  => $metodo,
				/*
				 * Si lo dedujo el panel o lo escribió ella. Viaja en un campo
				 * oculto del formulario porque, sin él, abrir y guardar la
				 * cotización convertiría en manual algo que nadie escribió.
				 */
				'method_auto' => ( isset( $row['method_auto'] ) && '1' === (string) $row['method_auto'] ) ? 1 : 0,
				'pay_url' => isset( $row['pay_url'] ) ? esc_url_raw( wp_unslash( $row['pay_url'] ) ) : '',
			);
		}

		return self::cerrar_por_total( $clean );
	}

	/**
	 * URL pública de la cotización.
	 *
	 * @param int $quote_id ID.
	 * @return string
	 */
	public static function get_url( $quote_id ) {
		/*
		 * La que vale es la que sirve el plugin: /cotizacion-NNN. El campo
		 * guardado a mano queda sólo para las cotizaciones viejas, de cuando
		 * Malka creaba la página aparte y pegaba el link acá.
		 */
		if ( class_exists( 'Malka_CRM_Quote_Page' ) ) {
			$propia = Malka_CRM_Quote_Page::public_url( $quote_id );

			if ( $propia ) {
				return $propia;
			}
		}

		return (string) get_post_meta( (int) $quote_id, '_malka_pm_quote_url', true );
	}

	/**
	 * Cotizaciones de un cliente.
	 *
	 * @param int $user_id ID de usuario.
	 * @return WP_Post[]
	 */
	public static function get_client_quotes( $user_id ) {

		if ( Malka_CRM_Helpers::current_user_is_manager() ) {
			return get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'posts_per_page' => 100,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
		}


		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_malka_pm_client_id',
						'value' => $user_id,
					),
				),
			)
		);
	}

	/**
	 * Cotización vinculada a un proyecto.
	 *
	 * @param int $project_id ID.
	 * @return int
	 */
	public static function get_project_quote_id( $project_id ) {

		$direct = (int) get_post_meta( (int) $project_id, '_malka_pm_quote_id', true );

		if ( $direct ) {
			return $direct;
		}

		$found = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_malka_pm_project_id',
						'value' => (int) $project_id,
					),
				),
			)
		);

		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/* ──────────────────── Alertas ──────────────────── */

	/**
	 * Clasifica un abono: pagado, atrasado, por vencer o pendiente.
	 *
	 * @param array $payment Abono.
	 * @return array {state, label, days}
	 */
	public static function payment_state( $payment ) {

		// Las cuotas guardadas por versiones anteriores pueden venir sin estado,
		// y el escritorio llama a esto en cada carga: sin la guarda, una cuota
		// vieja llenaría la página de avisos de PHP.
		$estado = isset( $payment['status'] ) ? $payment['status'] : 'espera';

		/*
		 * «Por confirmar» es que el cliente avisó que ya pagó. No cambia el
		 * estado —el interés sigue corriendo hasta que ella mire la cuenta—,
		 * pero viaja aparte para que el panel pueda mostrarlo y los avisos
		 * dejen de insistirle a alguien que ya dijo que pagó.
		 */
		$avisado = ( 'confirmar' === $estado );

		if ( 'pagado' === $estado ) {
			return array(
				'state'   => 'paid',
				'label'   => __( 'Pagado', 'malka-crm' ),
				'days'    => 0,
				'avisado' => false,
			);
		}

		if ( empty( $payment['due'] ) ) {
			return array(
				'state'   => 'pending',
				'label'   => $avisado ? __( 'Por confirmar', 'malka-crm' ) : __( 'Sin fecha', 'malka-crm' ),
				'days'    => 0,
				'avisado' => $avisado,
			);
		}

		$warn = max( 0, (int) Malka_CRM_Helpers::get_setting( 'payment_warn_days', 5 ) );
		$due  = strtotime( $payment['due'] . ' 23:59:59' );
		$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$days = (int) floor( ( $due - $now ) / DAY_IN_SECONDS );

		if ( $days < 0 ) {
			return array(
				'state'   => 'overdue',
				/* translators: %d: días de atraso */
				'label'   => sprintf( _n( 'Atrasado %d día', 'Atrasado %d días', abs( $days ), 'malka-crm' ), abs( $days ) ),
				'days'    => $days,
				'avisado' => $avisado,
			);
		}

		if ( $days <= $warn ) {
			return array(
				'state'   => 'due_soon',
				/* translators: %d: días restantes */
				'label'   => sprintf( _n( 'Vence en %d día', 'Vence en %d días', max( 1, $days ), 'malka-crm' ), max( 1, $days ) ),
				'days'    => $days,
				'avisado' => $avisado,
			);
		}

		return array(
			'state'   => 'pending',
			/* translators: %s: fecha */
			'label'   => sprintf( __( 'Vence el %s', 'malka-crm' ), date_i18n( 'd/m/Y', strtotime( $payment['due'] ) ) ),
			'days'    => $days,
			'avisado' => $avisado,
		);
	}

	/**
	 * Abonos que requieren atención en una cotización.
	 *
	 * @param int $quote_id ID.
	 * @return array
	 */
	public static function get_alerts( $quote_id ) {

		$alerts = array();

		foreach ( self::get_payments( $quote_id ) as $payment ) {

			$state = self::payment_state( $payment );

			if ( in_array( $state['state'], array( 'overdue', 'due_soon' ), true ) ) {
				$alerts[] = array_merge( $payment, $state );
			}
		}

		return $alerts;
	}

	/**
	 * Formatea un monto en pesos chilenos.
	 *
	 * @param float $amount Monto.
	 * @return string
	 */
	public static function money( $amount ) {

		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount ) );
		}

		return '$' . number_format( (float) $amount, 0, ',', '.' );
	}

	/* ──────────────────── WP-Admin ──────────────────── */

	/**
	 * Metaboxes de la cotización.
	 */
	public function register_metaboxes() {

		add_meta_box(
			'malka_crm_quote_data',
			__( 'Datos de la cotización', 'malka-crm' ),
			array( $this, 'render_data' ),
			self::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'malka_crm_quote_payments',
			__( 'Abonos y calendario de pago', 'malka-crm' ),
			array( $this, 'render_payments' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Metabox lateral.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_data( $post ) {

		wp_nonce_field( 'malka_crm_save_quote', 'malka_crm_quote_nonce' );

		$client_id  = (int) get_post_meta( $post->ID, '_malka_pm_client_id', true );
		$project_id = (int) get_post_meta( $post->ID, '_malka_pm_project_id', true );

		if ( ! $project_id && isset( $_GET['malka_project'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$project_id = absint( $_GET['malka_project'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$number     = (string) get_post_meta( $post->ID, '_malka_pm_quote_number', true );
		$url        = self::get_url( $post->ID );
		$total      = (float) get_post_meta( $post->ID, '_malka_pm_quote_total', true );
		$expires    = (string) get_post_meta( $post->ID, '_malka_pm_quote_expires', true );
		$status     = (string) get_post_meta( $post->ID, '_malka_pm_quote_status', true );

		$projects = get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<p>
			<label for="malka_crm_quote_number"><strong><?php esc_html_e( 'N° de cotización', 'malka-crm' ); ?></strong></label><br>
			<input type="text" class="widefat" id="malka_crm_quote_number" name="malka_crm_quote_number" value="<?php echo esc_attr( $number ); ?>" placeholder="405">
		</p>
		<p>
			<label for="malka_crm_quote_client"><strong><?php esc_html_e( 'Cliente', 'malka-crm' ); ?></strong></label><br>
			<?php
			wp_dropdown_users(
				array(
					'name'              => 'malka_crm_quote_client',
					'id'                => 'malka_crm_quote_client',
					'selected'          => $client_id,
					'show_option_none'  => __( '— Sin asignar —', 'malka-crm' ),
					'option_none_value' => 0,
					'show'              => 'display_name_with_login',
					'class'             => 'widefat',
				)
			);
			?>
		</p>
		<p>
			<label for="malka_crm_quote_project"><strong><?php esc_html_e( 'Proyecto vinculado', 'malka-crm' ); ?></strong></label><br>
			<select class="widefat" id="malka_crm_quote_project" name="malka_crm_quote_project">
				<option value="0"><?php esc_html_e( '— Ninguno —', 'malka-crm' ); ?></option>
				<?php foreach ( $projects as $project ) : ?>
					<option value="<?php echo esc_attr( $project->ID ); ?>" <?php selected( $project_id, $project->ID ); ?>>
						<?php echo esc_html( $project->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="malka_crm_quote_url"><strong><?php esc_html_e( 'URL de la cotización', 'malka-crm' ); ?></strong></label><br>
			<input type="url" class="widefat" id="malka_crm_quote_url" name="malka_crm_quote_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://estudiomalka.cl/cotizacion-405/">
			<span class="description"><?php esc_html_e( 'La página creada con tu plantilla de cotizaciones.', 'malka-crm' ); ?></span>
		</p>
		<p>
			<label for="malka_crm_quote_total"><strong><?php esc_html_e( 'Total del proyecto', 'malka-crm' ); ?></strong></label><br>
			<input type="number" step="1" min="0" class="widefat" id="malka_crm_quote_total" name="malka_crm_quote_total" value="<?php echo esc_attr( $total ); ?>">
		</p>
		<p>
			<label for="malka_crm_quote_expires"><strong><?php esc_html_e( 'Vencimiento', 'malka-crm' ); ?></strong></label><br>
			<input type="date" class="widefat" id="malka_crm_quote_expires" name="malka_crm_quote_expires" value="<?php echo esc_attr( $expires ); ?>">
		</p>
		<p>
			<label for="malka_crm_quote_status"><strong><?php esc_html_e( 'Estado', 'malka-crm' ); ?></strong></label><br>
			<select class="widefat" id="malka_crm_quote_status" name="malka_crm_quote_status">
				<?php foreach ( self::quote_statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Repetidor de abonos.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_payments( $post ) {

		$payments = self::get_payments( $post->ID );
		?>
		<table class="widefat malka-crm-payments-table">
			<thead>
				<tr>
					<th style="width:18%"><?php esc_html_e( 'Concepto', 'malka-crm' ); ?></th>
					<th style="width:11%"><?php esc_html_e( 'Monto', 'malka-crm' ); ?></th>
					<th style="width:11%"><?php esc_html_e( 'Fecha fija', 'malka-crm' ); ?></th>
					<th style="width:17%"><?php esc_html_e( 'El plazo corre', 'malka-crm' ); ?></th>
					<th style="width:15%"><?php esc_html_e( 'Tipo', 'malka-crm' ); ?></th>
					<th style="width:11%"><?php esc_html_e( 'Método', 'malka-crm' ); ?></th>
					<th style="width:9%"><?php esc_html_e( 'Estado', 'malka-crm' ); ?></th>
					<th style="width:5%"><?php esc_html_e( 'Link', 'malka-crm' ); ?></th>
					<th style="width:3%"></th>
				</tr>
			</thead>
			<tbody id="malka-crm-payments-body">
				<?php foreach ( $payments as $i => $payment ) : ?>
					<?php $this->payment_row( $i, $payment ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button button-secondary" id="malka-crm-add-payment"><?php esc_html_e( '+ Añadir abono', 'malka-crm' ); ?></button>
			<span class="description"><?php esc_html_e( 'Los abonos con fecha alimentan las alertas del portal del cliente.', 'malka-crm' ); ?></span>
		</p>

		<script type="text/html" id="tmpl-malka-crm-payment-row">
			<?php
			$this->payment_row(
				'__INDEX__',
				array(
					'label'   => '',
					'pay_url' => '',
					'amount' => 0,
					'due'    => '',
					'status' => 'espera',
					'rol'     => 'cuota',
					'disparo' => 'emision',
					'method' => '',
				)
			);
			?>
		</script>
		<?php
	}

	/**
	 * Fila de abono.
	 *
	 * @param int|string $index   Índice.
	 * @param array      $payment Abono.
	 */
	private function payment_row( $index, $payment ) {

		$name = 'malka_crm_payments[' . $index . ']';
		?>
		<tr class="malka-crm-payment-row">
			<td><input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $payment['label'] ); ?>" placeholder="<?php esc_attr_e( 'Anticipo 60%', 'malka-crm' ); ?>"></td>
			<td><input type="number" step="1" min="0" class="widefat" name="<?php echo esc_attr( $name ); ?>[amount]" value="<?php echo esc_attr( $payment['amount'] ); ?>"></td>
			<td><input type="date" name="<?php echo esc_attr( $name ); ?>[due]" value="<?php echo esc_attr( $payment['due'] ); ?>"></td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[disparo]">
					<?php foreach ( self::payment_triggers() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( isset( $payment['disparo'] ) ? $payment['disparo'] : 'emision', $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[rol]">
					<?php foreach ( self::payment_roles() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( isset( $payment['rol'] ) ? $payment['rol'] : 'cuota', $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[method]" value="<?php echo esc_attr( $payment['method'] ); ?>" placeholder="<?php esc_attr_e( 'Transferencia', 'malka-crm' ); ?>"></td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[status]">
					<?php foreach ( self::payment_statuses() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $payment['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="url" class="widefat" name="<?php echo esc_attr( $name ); ?>[pay_url]" value="<?php echo esc_attr( isset( $payment['pay_url'] ) ? $payment['pay_url'] : '' ); ?>" placeholder="https://…"></td>
			<td><button type="button" class="button-link malka-crm-remove-payment" aria-label="<?php esc_attr_e( 'Eliminar', 'malka-crm' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Guarda la cotización.
	 *
	 * @param int     $post_id ID.
	 * @param WP_Post $post    Post.
	 */
	public function save_quote( $post_id, $post ) {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['malka_crm_quote_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['malka_crm_quote_nonce'] ) ), 'malka_crm_save_quote' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! Malka_CRM_Helpers::current_user_is_manager() ) {
			return;
		}

		update_post_meta( $post_id, '_malka_pm_quote_number', isset( $_POST['malka_crm_quote_number'] ) ? sanitize_text_field( wp_unslash( $_POST['malka_crm_quote_number'] ) ) : '' );
		update_post_meta( $post_id, '_malka_pm_client_id', isset( $_POST['malka_crm_quote_client'] ) ? absint( $_POST['malka_crm_quote_client'] ) : 0 );
		update_post_meta( $post_id, '_malka_pm_project_id', isset( $_POST['malka_crm_quote_project'] ) ? absint( $_POST['malka_crm_quote_project'] ) : 0 );
		update_post_meta( $post_id, '_malka_pm_quote_url', isset( $_POST['malka_crm_quote_url'] ) ? esc_url_raw( wp_unslash( $_POST['malka_crm_quote_url'] ) ) : '' );
		update_post_meta( $post_id, '_malka_pm_quote_total', isset( $_POST['malka_crm_quote_total'] ) ? round( (float) $_POST['malka_crm_quote_total'], 2 ) : 0 );
		update_post_meta( $post_id, '_malka_pm_quote_expires', isset( $_POST['malka_crm_quote_expires'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['malka_crm_quote_expires'] ) ) : '' );
		update_post_meta( $post_id, '_malka_pm_quote_status', isset( $_POST['malka_crm_quote_status'] ) ? sanitize_key( wp_unslash( $_POST['malka_crm_quote_status'] ) ) : 'sin_respuesta' );

		$raw      = isset( $_POST['malka_crm_payments'] ) ? (array) wp_unslash( $_POST['malka_crm_payments'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$payments = self::conservar_extras( self::sanitize_payments( $raw ), self::get_payments( $post_id ) );

		update_post_meta( $post_id, self::META_PAYMENTS, $payments );

		// Vínculo inverso para que el proyecto encuentre su cotización.
		$project_id = (int) get_post_meta( $post_id, '_malka_pm_project_id', true );
		if ( $project_id ) {
			update_post_meta( $project_id, '_malka_pm_quote_id', $post_id );
		}
	}

	/**
	 * Columnas del listado.
	 *
	 * @param array $columns Columnas.
	 * @return array
	 */
	public function columns( $columns ) {

		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['malka_client'] = __( 'Cliente', 'malka-crm' );
				$new['malka_total']  = __( 'Total', 'malka-crm' );
				$new['malka_status'] = __( 'Estado', 'malka-crm' );
				$new['malka_pay']    = __( 'Pagos', 'malka-crm' );
			}
		}

		return $new;
	}

	/**
	 * Contenido de columnas.
	 *
	 * @param string $column  Columna.
	 * @param int    $post_id ID.
	 */
	public function column_content( $column, $post_id ) {

		switch ( $column ) {
			case 'malka_client':
				$user = get_userdata( (int) get_post_meta( $post_id, '_malka_pm_client_id', true ) );
				echo $user ? esc_html( $user->display_name ) : '&mdash;';
				break;

			case 'malka_total':
				echo esc_html( self::money( (float) get_post_meta( $post_id, '_malka_pm_quote_total', true ) ) );
				break;

			case 'malka_status':
				$statuses = self::quote_statuses();
				$status   = get_post_meta( $post_id, '_malka_pm_quote_status', true );
				echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : '—' );
				break;

			case 'malka_pay':
				$payments = self::get_payments( $post_id );
				$paid     = 0;
				foreach ( $payments as $payment ) {
					if ( 'pagado' === $payment['status'] ) {
						$paid++;
					}
				}
				$alerts = count( self::get_alerts( $post_id ) );
				echo esc_html( $paid . '/' . count( $payments ) );
				if ( $alerts ) {
					echo ' <span class="malka-crm-badge malka-crm-badge--changes">' . esc_html( $alerts ) . '</span>';
				}
				break;
		}
	}

	/* ──────────────────── Portal del cliente ──────────────────── */

	/**
	 * Tabla de abonos de una cotización para el portal.
	 *
	 * @param int  $quote_id ID.
	 * @param bool $compact  Vista compacta dentro del proyecto.
	 * @return string
	 */
	public static function render_payments_panel( $quote_id, $compact = false ) {

		$payments = self::get_payments( $quote_id );

		if ( empty( $payments ) ) {
			return '';
		}

		$url = self::get_url( $quote_id );

		ob_start();
		?>
		<?php if ( $compact ) : ?>
		<section class="malka-crm-payments">
			<h4><?php esc_html_e( 'Estado de pagos', 'malka-crm' ); ?></h4>
		<?php else : ?>
		<details class="malka-crm-panel malka-crm-payments">
			<summary><?php esc_html_e( 'Estado de pagos', 'malka-crm' ); ?></summary>
			<div class="malka-crm-panel__body">
		<?php endif; ?>

			<table class="malka-crm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Concepto', 'malka-crm' ); ?></th>
						<th><?php esc_html_e( 'Monto', 'malka-crm' ); ?></th>
						<th><?php esc_html_e( 'Fecha', 'malka-crm' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'malka-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $payments as $payment ) : ?>
					<?php $state = self::payment_state( $payment ); ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Concepto', 'malka-crm' ); ?>"><?php echo esc_html( $payment['label'] ); ?></td>
						<td data-title="<?php esc_attr_e( 'Monto', 'malka-crm' ); ?>"><?php echo esc_html( self::money( $payment['amount'] ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Fecha', 'malka-crm' ); ?>"><?php echo $payment['due'] ? esc_html( date_i18n( 'd/m/Y', strtotime( $payment['due'] ) ) ) : '&mdash;'; ?></td>
						<td data-title="<?php esc_attr_e( 'Estado', 'malka-crm' ); ?>">
							<span class="malka-crm-pay malka-crm-pay--<?php echo esc_attr( $state['state'] ); ?>"><?php echo esc_html( $state['label'] ); ?></span>
							<?php
							$pay_url = ! empty( $payment['pay_url'] ) ? $payment['pay_url'] : Malka_CRM_Helpers::get_setting( 'payment_url', '' );
							if ( ! $pay_url ) {
								$pay_url = self::get_url( $quote_id );
							}
							if ( 'pagado' !== $payment['status'] && $pay_url ) :
								?>
								<a class="malka-crm-btn malka-crm-pay-btn" href="<?php echo esc_url( $pay_url ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Pagar', 'malka-crm' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $url && ! $compact ) : ?>
				<p><a class="malka-crm-btn malka-crm-btn--ghost" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ver cotización completa', 'malka-crm' ); ?></a></p>
			<?php endif; ?>

		<?php if ( $compact ) : ?>
		</section>
		<?php else : ?>
			</div>
		</details>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * Banner de alerta de pago dentro del proyecto.
	 *
	 * @param int $quote_id ID.
	 * @return string
	 */
	public static function render_alert_banner( $quote_id ) {

		$alerts = self::get_alerts( $quote_id );

		if ( empty( $alerts ) ) {
			return '';
		}

		$url     = self::get_url( $quote_id );
		$overdue = false;

		foreach ( $alerts as $alert ) {
			if ( 'overdue' === $alert['state'] ) {
				$overdue = true;
				break;
			}
		}

		ob_start();
		?>
		<a class="malka-crm-alert malka-crm-alert--<?php echo $overdue ? 'danger' : 'warning'; ?>"
			href="<?php echo esc_url( $url ? $url : '#' ); ?>"
			<?php echo $url ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
			<span class="malka-crm-alert__title">
				<?php echo $overdue ? esc_html__( 'Tienes un pago atrasado', 'malka-crm' ) : esc_html__( 'Tienes un pago por vencer', 'malka-crm' ); ?>
			</span>
			<span class="malka-crm-alert__detail">
				<?php
				$parts = array();
				foreach ( $alerts as $alert ) {
					$parts[] = sprintf(
						'%s — %s (%s)',
						$alert['label'],
						self::money( $alert['amount'] ),
						$alert['label']
					);
				}
				echo esc_html( implode( ' · ', $parts ) );
				?>
			</span>
			<?php if ( $url ) : ?>
				<span class="malka-crm-alert__cta"><?php esc_html_e( 'Ver cotización y fechas de pago →', 'malka-crm' ); ?></span>
			<?php endif; ?>
		</a>
		<?php
		return ob_get_clean();
	}

	/**
	 * Listado de cotizaciones para la pestaña de Mi Cuenta.
	 *
	 * @param int $user_id ID.
	 * @return string
	 */
	public static function render_client_quotes( $user_id ) {

		$quotes = self::get_client_quotes( $user_id );

		ob_start();

		if ( empty( $quotes ) ) {
			echo '<div class="malka-crm-empty"><p>' . esc_html__( 'Aún no tienes cotizaciones disponibles.', 'malka-crm' ) . '</p></div>';
			return ob_get_clean();
		}

		$statuses = self::quote_statuses();
		?>
		<div class="malka-crm-quotes">
			<?php foreach ( $quotes as $quote ) : ?>
				<?php
				$number     = get_post_meta( $quote->ID, '_malka_pm_quote_number', true );
				$total      = (float) get_post_meta( $quote->ID, '_malka_pm_quote_total', true );
				$expires    = get_post_meta( $quote->ID, '_malka_pm_quote_expires', true );
				$issued     = get_post_meta( $quote->ID, '_malka_pm_quote_issued', true );
				$status     = get_post_meta( $quote->ID, '_malka_pm_quote_status', true );
				$project_id = (int) get_post_meta( $quote->ID, '_malka_pm_project_id', true );
				$url        = self::get_url( $quote->ID );
				$payments   = self::get_payments( $quote->ID );

				$title = $number
					/* translators: %s: número de cotización */
					? sprintf( __( 'Cotización N° %s', 'malka-crm' ), $number )
					: ( $quote->post_title ? $quote->post_title : sprintf( /* translators: %d: id */ __( 'Cotización #%d', 'malka-crm' ), $quote->ID ) );

				$meta = array();
				if ( $project_id ) {
					$meta[] = get_the_title( $project_id );
				}
				if ( $issued ) {
					/* translators: %s: fecha */
					$meta[] = sprintf( __( 'Emitida %s', 'malka-crm' ), date_i18n( 'd/m/Y', strtotime( $issued ) ) );
				}
				if ( $expires ) {
					/* translators: %s: fecha */
					$meta[] = sprintf( __( 'Vence %s', 'malka-crm' ), date_i18n( 'd/m/Y', strtotime( $expires ) ) );
				}
				?>
				<article class="malka-crm-quote">
					<header class="malka-crm-quote__head">
						<div class="malka-crm-quote__id">
							<h3><?php echo esc_html( $title ); ?></h3>
							<?php if ( ! empty( $meta ) ) : ?>
								<span class="malka-crm-quote__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
							<?php endif; ?>
						</div>
						<div class="malka-crm-quote__right">
							<span class="malka-crm-quote__total"><?php echo esc_html( self::money( $total ) ); ?></span>
							<span class="malka-crm-badge"><?php echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : '—' ); ?></span>
						</div>
					</header>

					<?php if ( ! empty( $payments ) ) : ?>
						<?php echo self::render_payments_panel( $quote->ID, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>

					<?php if ( $url ) : ?>
						<p class="malka-crm-quote__cta">
							<a class="malka-crm-btn malka-crm-btn--ghost" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Ver cotización completa', 'malka-crm' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
