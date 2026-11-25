<?php
/**
 * Negocios y tareas, dentro del escritorio.
 *
 * Eran las dos últimas pantallas que mandaban a Malka de vuelta a WP-Admin.
 * Comparten lo mismo —el árbol de etapas y tareas de un proyecto— así que
 * viven juntas: Negocios lo edita de a un proyecto, y Tareas lo mira todo
 * junto para contestar la única pregunta que importa al abrir el panel, que
 * es qué toca hoy.
 *
 * Los datos no cambian de forma: se guardan en el mismo `_malka_pm_tasks` y
 * se sanean con el mismo Malka_CRM_Helpers::sanitize_tasks(), que además
 * recalcula el avance de cada etapa desde sus tareas hijas. Esta clase pinta
 * y recoge; no inventa su propio modelo.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Las pantallas de Negocios y Tareas.
 */
class Malka_CRM_Projects {

	const POST_TYPE = 'malka_project';

	/**
	 * Engancha el guardado y el borrado.
	 */
	public function __construct() {
		add_action( 'admin_post_malka_crm_save_project', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_malka_crm_delete_project', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_malka_crm_save_tasks', array( __CLASS__, 'handle_save_tasks' ) );
		add_action( 'admin_post_malka_crm_agregar_tarea', array( __CLASS__, 'handle_add_task' ) );
		add_action( 'admin_post_malka_crm_borrar_tarea', array( __CLASS__, 'handle_delete_task' ) );
		add_action( 'wp_ajax_malka_crm_hc_visto', array( __CLASS__, 'ajax_hc_visto' ) );
		add_action( 'wp_ajax_malka_crm_tarea_repite', array( __CLASS__, 'ajax_repite' ) );
		add_action( 'wp_ajax_malka_crm_tarea_marcar', array( __CLASS__, 'ajax_marcar' ) );
		add_action( 'wp_ajax_malka_crm_tarea_detalle', array( __CLASS__, 'ajax_detalle' ) );
		add_action( 'wp_ajax_malka_crm_tarea_editar', array( __CLASS__, 'ajax_editar' ) );
		add_action( 'wp_ajax_malka_crm_pago_detalle', array( __CLASS__, 'ajax_pago_detalle' ) );
		add_action( 'wp_ajax_malka_crm_pago_editar', array( __CLASS__, 'ajax_pago_editar' ) );
		add_action( 'wp_ajax_malka_crm_falta', array( __CLASS__, 'ajax_falta' ) );
		add_action( 'wp_ajax_malka_crm_tarea_rapida', array( __CLASS__, 'ajax_rapida' ) );
		add_action( 'wp_ajax_malka_crm_tarea_mover', array( __CLASS__, 'ajax_mover' ) );
		add_action( 'wp_ajax_malka_crm_omitir_factura', array( __CLASS__, 'ajax_omitir_factura' ) );
		add_action( 'wp_ajax_malka_crm_neg_estado', array( __CLASS__, 'ajax_estado' ) );
		add_action( 'malka_crm_tareas_cliente', array( __CLASS__, 'avisar_tareas_cliente' ), 10, 2 );
		add_action( 'wp_ajax_malka_crm_recordar_tarea', array( __CLASS__, 'ajax_recordar' ) );
		add_action( 'wp_ajax_malka_crm_enlace', array( __CLASS__, 'ajax_enlace' ) );
	}

	/* ─────────────────────────────  Rutas  ──────────────────────────── */

	/**
	 * URL de la ficha de un negocio.
	 *
	 * @param int $id Proyecto, o 0 para uno nuevo.
	 * @return string
	 */
	public static function edit_url( $id = 0 ) {
		$base = Malka_CRM_Dashboard::view_url( 'negocio' );

		return $id ? add_query_arg( 'id', (int) $id, $base ) : $base;
	}

	/**
	 * URL para mandar un negocio a la papelera.
	 *
	 * @param int $id Proyecto.
	 * @return string
	 */
	public static function ajax_estado() {
		$pid    = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$estado = isset( $_POST['estado'] ) ? sanitize_key( wp_unslash( $_POST['estado'] ) ) : '';
		$lista  = Malka_CRM_Helpers::project_statuses();
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) || ! $pid || self::POST_TYPE !== get_post_type( $pid ) || ! isset( $lista[ $estado ] ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo cambiar.', 'malka-crm' ) ), 400 );
		}
		check_ajax_referer( 'malka_crm_neg_estado', 'nonce' );
		$antes = (string) get_post_meta( $pid, '_malka_pm_status', true );
		update_post_meta( $pid, '_malka_pm_status', $estado );
		if ( $antes !== $estado ) {
			do_action( 'malka_crm_project_status_changed', $pid, $estado, $antes );
		}
		wp_send_json_success(
			array(
				'etiqueta' => $lista[ $estado ],
				'tono'     => 'entregado' === $estado ? 'ok' : ( 'pausado' === $estado ? 'off' : 'run' ),
			)
		);
	}

	/**
	 * URL para mandar un negocio a la papelera.
	 *
	 * @param int $id Proyecto.
	 * @return string
	 */
	public static function delete_url( $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'malka_crm_delete_project',
					'negocio' => (int) $id,
				),
				admin_url( 'admin-post.php' )
			),
			'malka_crm_delete_project_' . (int) $id
		);
	}

	/**
	 * Qué negocio se está editando.
	 *
	 * @return int 0 si es nuevo.
	 */
	public static function current_id() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return ( $id && self::POST_TYPE === get_post_type( $id ) ) ? $id : 0;
	}

	/* ───────────────────────────  Lectura  ──────────────────────────── */

	/**
	 * Un negocio con lo que el panel necesita mostrar.
	 *
	 * @param int $id Proyecto.
	 * @return array
	 */
	public static function get( $id ) {
		$tareas = Malka_CRM_Helpers::get_project_tasks( $id );
		$hechas = 0;
		$hojas  = 0;

		// Lo que se hace: tareas sin hijas, y etapas sin tareas adentro.
		foreach ( Malka_CRM_Helpers::get_leaf_tasks( $id ) as $t ) {
			$hojas++;
			if ( ! empty( $t['done'] ) || ( isset( $t['progress'] ) && (int) $t['progress'] >= 100 ) ) {
				$hechas++;
			}
		}

		$estados = Malka_CRM_Helpers::project_statuses();
		$estado  = (string) get_post_meta( $id, '_malka_pm_status', true );
		$estado  = isset( $estados[ $estado ] ) ? $estado : 'briefing';
		$fin     = (string) get_post_meta( $id, '_malka_pm_deadline', true );

		return array(
			'id'       => (int) $id,
			'titulo'   => get_the_title( $id ),
			'numero'   => (string) get_post_meta( $id, '_malka_pm_number', true ),
			'cliente'  => self::cliente( $id ),
			'estado'   => $estado,
			'etiqueta' => $estados[ $estado ],
			'inicio'   => (string) get_post_meta( $id, '_malka_pm_start', true ),
			'fin'      => $fin,
			'avance'   => Malka_CRM_Helpers::get_project_progress( $id ),
			'tareas'   => $tareas,
			'hojas'    => $hojas,
			'hechas'   => $hechas,
			'dias'     => $fin ? (int) floor( ( strtotime( $fin ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : null,
		);
	}

	/**
	 * El nombre del cliente de un proyecto.
	 *
	 * @param int $id Proyecto.
	 * @return string
	 */
	public static function cliente( $id ) {
		$nombre = (string) get_post_meta( $id, '_malka_pm_client_name', true );

		if ( '' !== $nombre ) {
			return $nombre;
		}

		$uid = (int) get_post_meta( $id, '_malka_pm_client_id', true );
		$u   = $uid ? get_userdata( $uid ) : false;

		return $u ? $u->display_name : '';
	}

	/**
	 * Todos los negocios, del que vence antes al que vence después.
	 *
	 * @return array[]
	 */
	public static function all() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
			)
		);

		$out = array();

		foreach ( $ids as $id ) {
			$out[] = self::get( $id );
		}

		usort(
			$out,
			function ( $a, $b ) {
				// Los cerrados al final; entre los abiertos, el que vence antes.
				$ca = in_array( $a['estado'], array( 'entregado', 'pausado' ), true ) ? 1 : 0;
				$cb = in_array( $b['estado'], array( 'entregado', 'pausado' ), true ) ? 1 : 0;

				if ( $ca !== $cb ) {
					return $ca <=> $cb;
				}

				return strcmp(
					$a['fin'] ? $a['fin'] : '9999-12-31',
					$b['fin'] ? $b['fin'] : '9999-12-31'
				);
			}
		);

		return $out;
	}

	/**
	 * Las tareas pendientes de todos los proyectos, por urgencia.
	 *
	 * Las etapas no entran: una etapa no se hace, se completa sola cuando
	 * sus tareas están listas.
	 *
	 * @return array[]
	 */
	public static function pendientes() {
		$hoy = strtotime( current_time( 'Y-m-d' ) );
		$out = array();

		foreach ( self::all() as $p ) {
			if ( in_array( $p['estado'], array( 'entregado', 'pausado' ), true ) ) {
				continue;
			}

			foreach ( $p['tareas'] as $t ) {
				if ( 'stage' === $t['type'] || ! empty( $t['done'] ) ) {
					continue;
				}

				$limite = Malka_CRM_Helpers::get_task_deadline( $t );

				$out[] = array(
					'proyecto' => $p['titulo'],
					'pid'      => $p['id'],
					'cliente'  => $p['cliente'],
					'id'       => $t['id'],
					'numero'   => isset( $t['number'] ) ? $t['number'] : '',
					'nombre'   => $t['name'],
					'tipo'     => $t['type'],
					'quien'    => isset( $t['owner'] ) ? $t['owner'] : 'studio',
					'avance'   => isset( $t['progress'] ) ? (int) $t['progress'] : 0,
					'privada'  => ! empty( $t['privada'] ),
					'repite'   => isset( $t['repite'] ) ? (string) $t['repite'] : '',
					'nota'     => isset( $t['nota_cliente'] ) ? (string) $t['nota_cliente'] : '',
					'limite'   => $limite,
					'dias'     => $limite ? (int) floor( ( strtotime( $limite ) - $hoy ) / DAY_IN_SECONDS ) : null,
				);
			}
		}

		// Las del estudio no cuelgan de un negocio, pero son pendientes igual.
		foreach ( self::tareas_estudio() as $t ) {
			if ( ! empty( $t['done'] ) ) {
				continue;
			}

			$limite = isset( $t['end'] ) ? (string) $t['end'] : '';

			$out[] = array(
				'proyecto' => '',
				'pid'      => 0,
				'cliente'  => '',
				'id'       => $t['id'],
				'numero'   => '',
				'nombre'   => $t['name'],
				'tipo'     => 'task',
				'quien'    => empty( $t['personal'] ) ? 'studio' : 'personal',
				'avance'   => 0,
				'privada'  => true,
				'repite'   => isset( $t['repite'] ) ? (string) $t['repite'] : '',
				'limite'   => $limite,
				'dias'     => $limite ? (int) floor( ( strtotime( $limite ) - $hoy ) / DAY_IN_SECONDS ) : null,
			);
		}

		/*
		 * Ordenar sólo por fecha partía los negocios en pedazos: sus tareas
		 * quedaban intercaladas con las de otro y el mismo título aparecía
		 * dos y tres veces. Ahora manda el negocio —el que tiene lo más
		 * urgente va primero— y dentro de él siguen ordenadas por fecha.
		 */
		$urgencia = array();

		foreach ( $out as $t ) {
			$d   = null === $t['dias'] ? PHP_INT_MAX : $t['dias'];
			$k   = (string) $t['pid'];
			$act = isset( $urgencia[ $k ] ) ? $urgencia[ $k ] : PHP_INT_MAX;

			$urgencia[ $k ] = min( $act, $d );
		}

		usort(
			$out,
			function ( $a, $b ) use ( $urgencia ) {
				// Lo del estudio va al final: es mío, no le debo fecha a nadie.
				$ga = $a['pid'] ? 0 : 1;
				$gb = $b['pid'] ? 0 : 1;

				if ( $ga !== $gb ) {
					return $ga <=> $gb;
				}

				$ua = $urgencia[ (string) $a['pid'] ];
				$ub = $urgencia[ (string) $b['pid'] ];

				if ( $ua !== $ub ) {
					return $ua <=> $ub;
				}

				// Mismo negocio: la que vence antes, arriba.
				if ( $a['pid'] !== $b['pid'] ) {
					return strcmp( (string) $a['proyecto'], (string) $b['proyecto'] );
				}

				$da = null === $a['dias'] ? PHP_INT_MAX : $a['dias'];
				$db = null === $b['dias'] ? PHP_INT_MAX : $b['dias'];

				return $da <=> $db;
			}
		);

		return $out;
	}

	/**
	 * Quién hace cada cosa, en palabras.
	 *
	 * @return array
	 */
	public static function responsables() {
		return array(
			'studio' => __( 'El estudio', 'malka-crm' ),
			'client' => __( 'El cliente', 'malka-crm' ),
			'both'   => __( 'Los dos', 'malka-crm' ),
			'none'   => __( 'Nadie todavía', 'malka-crm' ),
		);
	}

	/**
	 * Los tipos de ítem del árbol.
	 *
	 * @return array
	 */
	public static function tipos() {
		return array(
			'stage'      => __( 'Etapa', 'malka-crm' ),
			'task'       => __( 'Tarea', 'malka-crm' ),
			'milestone'  => __( 'Hito', 'malka-crm' ),
			'correction' => __( 'Corrección', 'malka-crm' ),
		);
	}


	/* ────────────────────────────  Negocios  ────────────────────────── */

	/**
	 * La lista de negocios.
	 *
	 * @return string
	 */
	public static function render_list() {
		$todos = self::all();

		ob_start();
		echo '<div class="esc-cot">';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['borrado'] ) ) {
			echo '<p class="esc-ok">' . esc_html__( 'El negocio quedó en la papelera.', 'malka-crm' ) . '</p>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<section class="esc-panel">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Tus negocios', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Los abiertos primero, por fecha de entrega', 'malka-crm' ) . '</p></div>';
		/*
		 * Sin botón «Nuevo negocio» acá: el «+» del encabezado ya lo ofrece,
		 * y dos botones de crear en la misma pantalla no dan dos caminos,
		 * dan una duda. Plantillas tampoco está: es la pestaña de al lado.
		 * Si no hay ningún negocio todavía, el vacío de abajo sí lo ofrece,
		 * que es cuando hace falta que alguien te diga por dónde empezar.
		 */
		echo '</div>';

		if ( ! $todos ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no hay negocios. Cuando crees el primero, su avance alimenta el inicio, el plan del día y las tareas.', 'malka-crm' ) . '</p>';
			printf(
				'<a class="esc-btn esc-btn--primary" href="%1$s">%2$s</a>',
				esc_url( self::edit_url() ),
				esc_html__( 'Crear el primero', 'malka-crm' )
			);
			echo '</section></div>';
			return ob_get_clean();
		}

		/*
		 * Pestañas y buscador. Se filtra en el navegador: la lista entera ya
		 * está en la página y un negocio no son miles de filas.
		 */
		$grupos = array(
			'curso'     => 0,
			'entregado' => 0,
			'facturar'  => 0,
			'todos'     => count( $todos ),
		);
		$por_facturar = 0;
		foreach ( $todos as $i => $p ) {
			$grupos[ 'entregado' === $p['estado'] ? 'entregado' : 'curso' ]++;
			$todos[ $i ]['fx'] = self::facturacion( $p['id'] );
			if ( $todos[ $i ]['fx']['falta'] > 0 ) {
				$grupos['facturar']++;
				$por_facturar += $todos[ $i ]['fx']['falta'];
			}
		}

		/*
		 * El aviso de lo que falta facturar: lo cobrado que todavía no tiene
		 * su factura. Arriba de todo y con su botón para ver cuáles son.
		 */
		if ( $grupos['facturar'] > 0 ) {
			printf(
				'<div class="esc-nl__aviso"><span class="esc-nl__aviso-i" aria-hidden="true">!</span><span><b>%1$s</b> %2$s</span><button type="button" class="esc-nl__aviso-b" data-ir-grupo="facturar">%3$s →</button></div>',
				esc_html( sprintf( /* translators: cantidad */ _n( '%d negocio con factura pendiente', '%d negocios con facturas pendientes', $grupos['facturar'], 'malka-crm' ), $grupos['facturar'] ) ),
				esc_html( sprintf( /* translators: monto */ __( '· %s cobrados que todavía no facturas.', 'malka-crm' ), Malka_CRM_Settings::money( $por_facturar ) ) ),
				esc_html__( 'Ver cuáles', 'malka-crm' )
			);
		}

		echo '<div class="esc-nl__bar">';
		echo '<div class="esc-nl__tabs" role="tablist">';
		$nombres = array(
			'curso'     => __( 'En curso', 'malka-crm' ),
			'entregado' => __( 'Entregados', 'malka-crm' ),
			'facturar'  => __( 'Por facturar', 'malka-crm' ),
			'todos'     => __( 'Todos', 'malka-crm' ),
		);
		foreach ( $nombres as $k => $rot ) {
			if ( 'facturar' === $k && ! $grupos['facturar'] ) {
				continue;
			}
			printf(
				'<button type="button" role="tab" class="esc-nl__tab%3$s%6$s" data-grupo="%1$s" aria-selected="%4$s">%2$s <i>%5$d</i></button>',
				esc_attr( $k ),
				esc_html( $rot ),
				'curso' === $k ? ' is-on' : '',
				'curso' === $k ? 'true' : 'false',
				(int) $grupos[ $k ],
				'facturar' === $k ? ' is-warn' : ''
			);
		}
		echo '</div>';
		printf(
			'<input type="search" class="esc-nl__q" id="esc-nl-q" placeholder="%s" autocomplete="off" />',
			esc_attr__( 'Buscar proyecto o cliente', 'malka-crm' )
		);

		/*
		 * Ordenar, no filtrar: las pestañas de arriba ya filtran por grupo y
		 * el buscador por texto. Lo que faltaba era poder mirar la misma
		 * lista en otro orden. El ícono es de ordenar —dos flechas— y no un
		 * embudo, porque un embudo promete esconder filas y acá no se
		 * esconde ninguna.
		 */
		$ordenes = array(
			'entrega'  => __( 'Entrega más próxima', 'malka-crm' ),
			'entrega-' => __( 'Entrega más lejana', 'malka-crm' ),
			'monto-'   => __( 'Monto mayor', 'malka-crm' ),
			'monto'    => __( 'Monto menor', 'malka-crm' ),
			'etapa'    => __( 'Etapa del proyecto', 'malka-crm' ),
			'nombre'   => __( 'Nombre, A-Z', 'malka-crm' ),
		);
		$opts = '';
		foreach ( $ordenes as $k => $rot ) {
			$opts .= sprintf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( $k ),
				esc_html( $rot ),
				'entrega' === $k ? ' selected' : ''
			);
		}
		printf(
			'<label class="esc-nl__orden" title="%1$s">' .
			'<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' .
			'<path d="M4 2.6v10.8M1.8 11.2 4 13.4l2.2-2.2M12 13.4V2.6M9.8 4.8 12 2.6l2.2 2.2"/></svg>' .
			'<span class="screen-reader-text">%1$s</span>' .
			'<select id="esc-nl-orden" data-nl-orden>%2$s</select></label>',
			esc_attr__( 'Ordenar la lista', 'malka-crm' ),
			$opts // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		echo '</div>';

		printf(
			'<div class="esc-nl__list" id="esc-nl" data-estados="%1$s" data-nonce-estado="%2$s">',
			esc_attr( wp_json_encode( Malka_CRM_Helpers::project_statuses() ) ),
			esc_attr( wp_create_nonce( 'malka_crm_neg_estado' ) )
		);
		echo '<div class="esc-nl esc-nl--neg esc-nl--h" aria-hidden="true">' .
			'<span>' . esc_html__( 'Proyecto', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Estado', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Avance', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Entrega', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Facturado', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Por cobrar', 'malka-crm' ) . '</span><span></span></div>';

		foreach ( $todos as $p ) {
			echo self::fila_negocio( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<p class="esc-nl__vacio" hidden>' . esc_html__( 'No hay negocios que coincidan.', 'malka-crm' ) . '</p>';
		echo '</div>';

		// Omitir la factura de un negocio, con una nota de por qué.
		printf(
			'<dialog class="esc-dlg esc-omit" id="esc-omit" data-nonce="%1$s"><form method="dialog" class="esc-omit__f">' .
			'<h3>%2$s</h3><p>%3$s</p>' .
			'<label><span>%4$s</span><textarea name="nota" rows="3" placeholder="%5$s" required></textarea></label>' .
			'<div class="esc-omit__b"><button type="button" class="esc-btn esc-btn--soft" data-omit-x>%6$s</button><button type="submit" class="esc-btn esc-btn--primary">%7$s</button></div>' .
			'</form></dialog>',
			esc_attr( wp_create_nonce( 'malka_crm_omitir_factura' ) ),
			esc_html__( 'Omitir la factura', 'malka-crm' ),
			esc_html__( 'Este negocio deja de aparecer en «Por facturar». La nota queda guardada en el negocio.', 'malka-crm' ),
			esc_html__( 'Por qué no se factura', 'malka-crm' ),
			esc_attr__( 'Ej.: se pagó con boleta de honorarios, fue un canje, lo facturó otra empresa…', 'malka-crm' ),
			esc_html__( 'Cancelar', 'malka-crm' ),
			esc_html__( 'Omitir factura', 'malka-crm' )
		);

		echo '</section></div>';

		return ob_get_clean();
	}

	/**
	 * Cuánto de lo contratado ya está facturado.
	 *
	 * Suma todas las facturas del negocio (o de su cotización), sea una por
	 * cuota o una por todo, y lo compara con el neto de la cotización. Dice
	 * además si se ha cobrado más de lo facturado: esa diferencia es la
	 * factura que falta emitir.
	 *
	 * @param int $pid Negocio.
	 * @return array {contratado, facturado, pct, cobrado, falta, docs}
	 */
	public static function facturacion( $pid ) {
		$out = array(
			'contratado' => 0,
			'facturado'  => 0,
			'pct'        => 0,
			'cobrado'    => 0,
			'falta'      => 0,
			'docs'       => 0,
		);

		$qid = class_exists( 'Malka_CRM_Quotes' ) ? (int) Malka_CRM_Quotes::get_project_quote_id( $pid ) : 0;
		if ( ! $qid ) {
			return $out;
		}

		$q                 = Malka_CRM_Quote_Page::data( $qid );
		$out['contratado'] = (int) $q['neto'];

		if ( class_exists( 'Malka_CRM_Documents' ) ) {
			$ids = get_posts(
				array(
					'post_type'      => Malka_CRM_Documents::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array(
							'key'   => Malka_CRM_Documents::META_PROYECTO,
							'value' => (int) $pid,
						),
						array(
							'key'   => Malka_CRM_Documents::META_QUOTE,
							'value' => $qid,
						),
					),
				)
			);
			foreach ( $ids as $did ) {
				$d = Malka_CRM_Documents::get( $did );
				if ( 'venta' !== $d['sentido'] || 'anulada' === $d['estado'] ) {
					continue;
				}
				$out['facturado'] += (int) $d['neto'] * (int) $d['signo'];
				$out['docs']++;
			}
		}

		// Lo cobrado, llevado a neto para compararlo con lo facturado.
		$total = 0;
		foreach ( Malka_CRM_Quotes::get_payments( $qid ) as $pg ) {
			if ( ! Malka_CRM_Quotes::es_resumen( $qid, $pg ) ) {
				$total += (int) $pg['amount'];
			}
		}
		$saldo          = Malka_CRM_Dashboard::project_balance( $pid );
		$cobrado        = max( 0, $total - (int) $saldo['pendiente'] );
		$iva            = (float) Malka_CRM_Settings::get( 'iva', 19 );
		$out['cobrado'] = $iva > 0 ? (int) round( $cobrado / ( 1 + $iva / 100 ) ) : $cobrado;

		$out['pct'] = $out['contratado'] > 0 ? max( 0, min( 100, (int) round( $out['facturado'] / $out['contratado'] * 100 ) ) ) : 0;

		// Un margen chico para que el redondeo del IVA no avise de más.
		$dif          = $out['cobrado'] - $out['facturado'];
		$out['falta'] = $dif > 1000 ? $dif : 0;

		// Si ella decidió no facturarlo (con su nota), deja de avisar.
		$omit = get_post_meta( $pid, '_malka_pm_sin_factura', true );
		if ( is_array( $omit ) && ! empty( $omit['fecha'] ) ) {
			$out['omitida'] = (string) $omit['nota'];
			$out['falta']   = 0;
		}

		return $out;
	}

	/**
	 * La línea bajo el porcentaje facturado: cuántas facturas y cuánto falta.
	 *
	 * Va en amarillo (desde la fila) cuando ya se cobró más de lo que se
	 * facturó, que es cuando de verdad hay una factura que hacer.
	 *
	 * @param array $fx Resultado de facturacion().
	 * @return string
	 */
	private static function linea_facturas( $fx ) {
		if ( isset( $fx['omitida'] ) ) {
			return __( 'Sin factura', 'malka-crm' ) . ( '' !== $fx['omitida'] ? ' · ' . $fx['omitida'] : '' );
		}
		$cuantas = $fx['docs']
			/* translators: cantidad de facturas */
			? sprintf( _n( '%d factura', '%d facturas', $fx['docs'], 'malka-crm' ), $fx['docs'] )
			: __( 'Sin facturas', 'malka-crm' );

		$resto = max( 0, (int) $fx['contratado'] - (int) $fx['facturado'] );

		if ( $resto <= 0 ) {
			return $cuantas . ' · ' . __( 'todo facturado', 'malka-crm' );
		}

		/* translators: monto que falta facturar */
		return $cuantas . ' · ' . sprintf( __( 'faltan %s', 'malka-crm' ), Malka_CRM_Settings::money( $resto ) );
	}

	/**
	 * Una fila de la lista de negocios.
	 *
	 * @param array $p Negocio.
	 * @return string
	 */
	private static function fila_negocio( $p ) {
		$tono  = 'entregado' === $p['estado'] ? 'ok' : ( 'pausado' === $p['estado'] ? 'off' : 'run' );
		$tarde = ( null !== $p['dias'] && $p['dias'] < 0 && 'entregado' !== $p['estado'] );

		// Entrega: la fecha, y debajo cuánto falta o cuánto se pasó.
		$fecha = $p['fin'] ? date_i18n( 'j M Y', strtotime( $p['fin'] ) ) : '—';
		$nota  = '';
		if ( $p['fin'] ) {
			if ( 'entregado' === $p['estado'] ) {
				$nota = __( 'Entregado', 'malka-crm' );
			} elseif ( $p['dias'] < 0 ) {
				/* translators: días */
				$nota = sprintf( _n( 'Atrasado %d día', 'Atrasado %d días', abs( $p['dias'] ), 'malka-crm' ), abs( $p['dias'] ) );
			} elseif ( 0 === $p['dias'] ) {
				$nota = __( 'Es hoy', 'malka-crm' );
			} else {
				/* translators: días */
				$nota = sprintf( _n( 'En %d día', 'En %d días', $p['dias'], 'malka-crm' ), $p['dias'] );
			}
		}

		// Lo que falta cobrar, si el negocio viene de una cotización.
		$qid   = class_exists( 'Malka_CRM_Quotes' ) ? (int) Malka_CRM_Quotes::get_project_quote_id( $p['id'] ) : 0;
		$cobro = '<span class="esc-nl__sin">—</span>';
		if ( $qid ) {
			$saldo = Malka_CRM_Dashboard::project_balance( $p['id'] );
			if ( $saldo['vencido'] > 0 ) {
				$cobro = sprintf( '<b class="is-late">%s</b><span class="is-late">%s</span>', esc_html( Malka_CRM_Settings::money( $saldo['pendiente'] ) ), esc_html( sprintf( /* translators: monto */ __( '%s vencido', 'malka-crm' ), Malka_CRM_Settings::money( $saldo['vencido'] ) ) ) );
			} elseif ( $saldo['pendiente'] > 0 ) {
				$cobro = sprintf( '<b>%s</b>', esc_html( Malka_CRM_Settings::money( $saldo['pendiente'] ) ) );
			} else {
				$cobro = '<span class="esc-nl__ok">' . esc_html__( 'Pagado', 'malka-crm' ) . '</span>';
			}
		}

		$buscar = strtolower( remove_accents( $p['titulo'] . ' ' . $p['cliente'] . ' ' . $p['numero'] ) );

		// Facturado: qué parte de lo contratado ya tiene factura.
		$fx   = isset( $p['fx'] ) ? $p['fx'] : self::facturacion( $p['id'] );
		$fact = '<span class="esc-nl__sin">—</span>';
		if ( $fx['contratado'] > 0 ) {
			$accion = '';
			if ( $fx['falta'] > 0 ) {
				$accion = sprintf( '<span class="esc-nl__omit" role="button" tabindex="0" data-omitir="%1$d">%2$s</span>', (int) $p['id'], esc_html__( 'Omitir factura', 'malka-crm' ) );
			} elseif ( isset( $fx['omitida'] ) ) {
				$accion = sprintf( '<span class="esc-nl__omit" role="button" tabindex="0" data-omitir="%1$d" data-deshacer="1">%2$s</span>', (int) $p['id'], esc_html__( 'Deshacer', 'malka-crm' ) );
			}
			$fact = sprintf(
				'<span class="esc-nl__pct"><b>%1$d%%</b><span class="esc-bar"><i style="width:%1$d%%"></i></span></span><span%2$s>%3$s</span>%4$s',
				(int) $fx['pct'],
				$fx['falta'] ? ' class="is-warn"' : ( isset( $fx['omitida'] ) ? ' class="is-omit"' : '' ),
				esc_html( self::linea_facturas( $fx ) ),
				$accion
			);
		}

		return sprintf(
			'<a class="esc-nl esc-nl--neg%1$s" href="%2$s" data-grupo="%3$s" data-buscar="%4$s"%17$s' .
			' data-fin="%23$s" data-monto="%24$d" data-etapa="%25$d" data-nombre="%26$s">' .
			'<span class="esc-nl__p"><b>%5$s</b><span>%6$s</span></span>' .
			'<span class="esc-nl__e"><span class="esc-st esc-st--%7$s esc-st--cambia" role="button" tabindex="0" data-estado-neg="%20$d" data-estado="%21$s" title="%22$s">%8$s</span>%9$s</span>' .
			'<span class="esc-nl__a"><span class="esc-bar"><i style="width:%10$d%%"></i></span><span>%11$s</span></span>' .
			'<span class="esc-nl__f"><b>%12$s</b><span%13$s>%14$s</span></span>' .
			'<span class="esc-nl__x">%16$s</span>' .
			'<span class="esc-nl__c">%15$s</span>' .
			'<span class="esc-nl__del" role="button" tabindex="0" data-nl-borrar="%18$s" title="%19$s" aria-label="%19$s"><svg class="esc-x-ic" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></span></a>',
			$tarde ? ' is-tarde' : '',
			esc_url( self::edit_url( $p['id'] ) ),
			'entregado' === $p['estado'] ? 'entregado' : 'curso',
			esc_attr( $buscar ),
			esc_html( $p['titulo'] ),
			esc_html( trim( $p['cliente'] . ( $p['numero'] ? ' · ' . $p['numero'] : '' ), ' ·' ) ),
			esc_attr( $tarde ? 'late' : $tono ),
			esc_html( $p['etiqueta'] ),
			'', // El sello pasó a su propia columna, «Facturado», con el porcentaje.
			(int) $p['avance'],
			esc_html( $p['hojas'] ? sprintf( /* translators: 1: hechas, 2: total */ __( '%1$d de %2$d tareas', 'malka-crm' ), $p['hechas'], $p['hojas'] ) : __( 'Sin tareas', 'malka-crm' ) ),
			esc_html( $fecha ),
			$tarde ? ' class="is-late"' : '',
			esc_html( $nota ),
			$cobro, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$fact, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$fx['falta'] > 0 ? ' data-facturar="1"' : '',
			esc_url( self::delete_url( $p['id'] ) ),
			esc_attr__( 'Mandar a la papelera', 'malka-crm' ),
			(int) $p['id'],
			esc_attr( $p['estado'] ),
			esc_attr__( 'Cambiar el estado', 'malka-crm' ),
			/*
			 * Con qué se ordena, en crudo. Sin fecha de entrega va 9999 para
			 * que caiga al final cuando se ordena por entrega: «sin fecha» no
			 * es «para mañana». La etapa va como número, en el orden en que
			 * de verdad pasa un negocio —briefing, proceso, revisión,
			 * entregado, pausado— y no alfabético, que no significa nada.
			 */
			esc_attr( $p['fin'] ? $p['fin'] : '9999-12-31' ),
			(int) ( isset( $p['fx']['contratado'] ) ? $p['fx']['contratado'] : 0 ),
			(int) array_search( $p['estado'], array_keys( Malka_CRM_Helpers::project_statuses() ), true ),
			esc_attr( strtolower( remove_accents( $p['titulo'] ) ) )
		);
	}

	/**
	 * El sello de facturación de un negocio.
	 *
	 * Sólo aparece cuando dice algo: facturado, o pagado y sin factura. Un
	 * negocio que todavía debe plata no lleva nada, porque la factura sale
	 * recién con el saldo cancelado y un icono ahí sería ruido.
	 *
	 * @param int $pid Negocio.
	 * @return string
	 */
	private static function sello_factura( $pid ) {
		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return '';
		}

		$f = Malka_CRM_Documents::facturacion_de( $pid );

		if ( 'espera' === $f['estado'] ) {
			return '';
		}
		if ( 'omitida' === $f['estado'] ) {
			return sprintf( '<span class="esc-fact esc-fact--ok" title="%1$s">%2$s</span>', esc_attr( $f['nota'] ), esc_html__( 'Sin factura', 'malka-crm' ) );
		}

		$emitida = ( 'emitida' === $f['estado'] );

		$rotulo = $emitida
			? sprintf(
				/* translators: 1: cuántos documentos, 2: total facturado */
				_n( 'Facturado: %1$d documento por %2$s', 'Facturado: %1$d documentos por %2$s', (int) $f['cuantos'], 'malka-crm' ),
				(int) $f['cuantos'],
				Malka_CRM_Settings::money( (int) $f['total'] )
			)
			: __( 'Pagado y sin factura: te falta emitirla', 'malka-crm' );

		// Con palabras y no con un ícono: el ícono solo no se entendía.
		return sprintf(
			'<span class="esc-fact esc-fact--%1$s" title="%2$s">%3$s</span>',
			esc_attr( $emitida ? 'ok' : 'falta' ),
			esc_attr( $rotulo ),
			esc_html( $emitida ? __( 'Facturado', 'malka-crm' ) : __( 'Falta facturar', 'malka-crm' ) )
		);
	}

	/**
	 * Qué decir en la columna de entrega.
	 *
	 * @param array $p Negocio.
	 * @return string
	 */
	private static function cuando( $p ) {
		if ( ! $p['fin'] ) {
			return '—';
		}

		if ( 'entregado' === $p['estado'] ) {
			return date_i18n( 'j M', strtotime( $p['fin'] ) );
		}

		if ( $p['dias'] < 0 ) {
			/* translators: días */
			return sprintf( _n( 'hace %d día', 'hace %d días', abs( $p['dias'] ), 'malka-crm' ), abs( $p['dias'] ) );
		}

		if ( 0 === $p['dias'] ) {
			return __( 'es hoy', 'malka-crm' );
		}

		/* translators: días */
		return sprintf( _n( 'en %d día', 'en %d días', $p['dias'], 'malka-crm' ), $p['dias'] );
	}

	/**
	 * Editar y eliminar, al final de la fila.
	 *
	 * @param array $p Negocio.
	 * @return string
	 */
	private static function acciones_negocio( $p ) {
		ob_start();
		echo '<span class="esc-tr__acc">';

		printf(
			'<a class="esc-ico" href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',
			esc_url( self::edit_url( $p['id'] ) ),
			esc_attr__( 'Editar', 'malka-crm' ),
			Malka_CRM_Dashboard::icono_publico( '<path d="M11.2 2.3l2.5 2.5L6 12.5l-3.2.7.7-3.2z"/>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		printf(
			'<a class="esc-ico esc-ico--x" href="%1$s" title="%2$s" aria-label="%2$s" data-confirmar="%3$s">%4$s</a>',
			esc_url( self::delete_url( $p['id'] ) ),
			esc_attr__( 'Eliminar', 'malka-crm' ),
			esc_attr(
				sprintf(
					/* translators: nombre del negocio */
					__( '¿Mandar «%s» a la papelera? Lo puedes recuperar después.', 'malka-crm' ),
					$p['titulo']
				)
			),
			Malka_CRM_Dashboard::icono_publico( '<path d="M4 4l8 8M12 4l-8 8"/>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		echo '</span>';

		return ob_get_clean();
	}

	/* ──────────────────────────  La ficha  ──────────────────────────── */

	/**
	 * La ficha del negocio: sus datos y su árbol.
	 *
	 * @return string
	 */
	public static function render_form() {
		$id    = self::current_id();
		$nuevo = ! $id;

		$p = $nuevo
			? array(
				'id'      => 0,
				'titulo'  => '',
				'numero'  => '',
				'cliente' => '',
				'estado'  => 'briefing',
				'inicio'  => current_time( 'Y-m-d' ),
				'fin'     => '',
				'avance'  => 0,
				'tareas'  => array(),
			)
			: self::get( $id );

		ob_start();
		echo '<div class="esc-ed">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['guardado'] ) ) {
			echo '<p class="esc-ok">' . esc_html__( 'Guardado. El avance de cada etapa se recalculó solo.', 'malka-crm' ) . '</p>';
		}

		echo Malka_CRM_Templates_Panel::mensaje(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf(
			'<form method="post" action="%s" class="esc-ed__f" id="esc-neg">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="malka_crm_save_project" />';
		printf( '<input type="hidden" name="negocio" value="%d" />', (int) $id );
		wp_nonce_field( 'malka_crm_editor_project' );

		/* ── Datos ── */
		/*
		 * Arriba, como una ficha y no como un formulario: el nombre grande,
		 * el cliente debajo y el estado y el número como etiquetas. Abajo,
		 * tres franjas con lo que se mira —fechas, avance y plata—. Todo
		 * sigue siendo editable: los campos sólo se ven como campo al
		 * pasar el mouse o al escribir.
		 */
		echo '<section class="esc-panel esc-nh">';

		echo '<div class="esc-nh__top"><div class="esc-nh__id">';

		echo '<div class="esc-nh__tags">';
		printf(
			'<label class="esc-nh__num"><span class="screen-reader-text">%1$s</span><span class="esc-nh__np" aria-hidden="true">N°</span><input type="text" id="f-numero" name="numero" value="%2$s" placeholder="%3$s" /></label>',
			esc_html__( 'Número interno', 'malka-crm' ),
			esc_attr( $p['numero'] ),
			esc_attr__( 'N° interno', 'malka-crm' )
		);
		printf(
			'<label class="esc-nh__est is-%2$s"><span class="screen-reader-text">%1$s</span><select id="f-estado" name="estado" onchange="this.parentNode.className=\'esc-nh__est is-\'+this.value">',
			esc_html__( 'Estado', 'malka-crm' ),
			esc_attr( $p['estado'] )
		);
		foreach ( Malka_CRM_Helpers::project_statuses() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $p['estado'], $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select></label>';

		/*
		 * La papelera, como icono al final de las etiquetas. Antes era una
		 * frase suelta al pie de la ficha que no era parte de nada. Es un
		 * enlace (no un botón del formulario) y pide confirmación.
		 */
		if ( ! $nuevo ) {
			printf(
				'<a class="esc-nh__bin" href="%1$s" data-confirmar="%2$s" title="%3$s" aria-label="%3$s">' .
				'<svg class="esc-x-ic" viewBox="0 0 16 16" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></a>',
				esc_url( self::delete_url( $p['id'] ) ),
				esc_attr( sprintf( /* translators: nombre del negocio */ __( '¿Mandar «%s» a la papelera? Lo puedes recuperar después.', 'malka-crm' ), $p['titulo'] ) ),
				esc_attr__( 'Mandar este negocio a la papelera', 'malka-crm' )
			);
		}
		echo '</div>';

		printf(
			'<label class="esc-nh__tit"><span class="screen-reader-text">%1$s</span><textarea id="f-titulo" name="titulo" rows="1" placeholder="%3$s" required data-una-linea>%2$s</textarea></label>',
			esc_html__( 'Nombre del proyecto', 'malka-crm' ),
			esc_textarea( $p['titulo'] ),
			esc_attr__( 'Nombre del proyecto', 'malka-crm' )
		);
		printf(
			'<label class="esc-nh__cli"><span>%1$s</span><input type="text" id="f-cliente" name="cliente" value="%2$s" placeholder="%3$s" /></label>',
			esc_html__( 'Cliente', 'malka-crm' ),
			esc_attr( $p['cliente'] ),
			esc_attr__( 'Nombre del cliente', 'malka-crm' )
		);
		echo '</div>';

		if ( ! $nuevo ) {
			$pct = max( 0, min( 100, (int) $p['avance'] ) );
			printf(
				'<div class="esc-nh__ring" style="--p:%1$d"><b>%1$d%%</b><span>%2$s</span></div>',
				$pct,
				esc_html__( 'avance', 'malka-crm' )
			);
		}
		echo '</div>';

		/* Las tres franjas. */
		echo '<div class="esc-nh__band">';

		// Fechas.
		$falta = '';
		$clase = '';
		if ( ! $nuevo && null !== $p['dias'] ) {
			if ( 'entregado' === $p['estado'] ) {
				$falta = __( 'Entregado', 'malka-crm' );
				$clase = ' is-ok';
			} elseif ( $p['dias'] < 0 ) {
				/* translators: días */
				$falta = sprintf( _n( 'Atrasado %d día', 'Atrasado %d días', abs( $p['dias'] ), 'malka-crm' ), abs( $p['dias'] ) );
				$clase = ' is-late';
			} elseif ( 0 === $p['dias'] ) {
				$falta = __( 'Se entrega hoy', 'malka-crm' );
				$clase = ' is-late';
			} else {
				/* translators: días */
				$falta = sprintf( _n( 'Queda %d día', 'Quedan %d días', $p['dias'], 'malka-crm' ), $p['dias'] );
			}
		}
		echo '<div class="esc-nh__cell"><span class="esc-nh__k">' . esc_html__( 'Fechas', 'malka-crm' ) . '</span>';
		echo '<div class="esc-nh__dates">';
		printf(
			'<label><span>%1$s</span><input type="date" id="f-inicio" name="inicio" value="%2$s" /></label><i aria-hidden="true">→</i><label><span>%3$s</span><input type="date" id="f-fin" name="fin" value="%4$s" /></label>',
			esc_html__( 'Parte el', 'malka-crm' ),
			esc_attr( $p['inicio'] ),
			esc_html__( 'Se entrega el', 'malka-crm' ),
			esc_attr( $p['fin'] )
		);
		echo '</div>';
		if ( $falta ) {
			printf( '<span class="esc-nh__hint%1$s">%2$s</span>', esc_attr( $clase ), esc_html( $falta ) );
		}
		echo '</div>';

		// Avance.
		if ( ! $nuevo ) {
			echo '<div class="esc-nh__cell"><span class="esc-nh__k">' . esc_html__( 'Tareas', 'malka-crm' ) . '</span>';
			printf(
				'<b class="esc-nh__v">%1$s</b><span class="esc-bar"><i style="width:%2$d%%"></i></span>',
				esc_html( sprintf( /* translators: 1: hechas, 2: total */ __( '%1$d de %2$d listas', 'malka-crm' ), (int) $p['hechas'], (int) $p['hojas'] ) ),
				(int) $p['avance']
			);
			echo '</div>';
		}

		// Plata, si viene de una cotización.
		$qid = ( ! $nuevo && class_exists( 'Malka_CRM_Quotes' ) ) ? (int) Malka_CRM_Quotes::get_project_quote_id( $id ) : 0;
		if ( $qid ) {
			$total = 0;
			foreach ( Malka_CRM_Quotes::get_payments( $qid ) as $pg ) {
				if ( ! Malka_CRM_Quotes::es_resumen( $qid, $pg ) ) {
					$total += (int) $pg['amount'];
				}
			}
			$saldo   = Malka_CRM_Dashboard::project_balance( $id );
			$cobrado = max( 0, $total - (int) $saldo['pendiente'] );

			/*
			 * ¿Está facturado? Contra el neto contratado de la cotización:
			 * gris sin nada facturado, ámbar si falta una parte, verde si está todo.
			 */
			$fact_n  = self::facturado_neto( $id, $qid );
			$q_dat   = class_exists( 'Malka_CRM_Quote_Page' ) ? Malka_CRM_Quote_Page::data( $qid ) : null;
			$con_n   = $q_dat ? (int) $q_dat['neto'] : 0;
			$falta_f = max( 0, $con_n - $fact_n );
			$estado_f = $fact_n <= 0 ? 'nada' : ( $falta_f <= 1 ? 'todo' : 'parte' );
			$txt_f   = 'todo' === $estado_f
				? sprintf( /* translators: monto */ __( 'Todo facturado · %s neto', 'malka-crm' ), Malka_CRM_Settings::money( $fact_n ) )
				: ( 'parte' === $estado_f
					? sprintf( /* translators: 1: facturado, 2: contratado, 3: falta */ __( 'Facturado %1$s de %2$s neto · faltan %3$s', 'malka-crm' ), Malka_CRM_Settings::money( $fact_n ), Malka_CRM_Settings::money( $con_n ), Malka_CRM_Settings::money( $falta_f ) )
					: sprintf( /* translators: monto */ __( 'Sin facturas · %s neto por facturar', 'malka-crm' ), Malka_CRM_Settings::money( $con_n ) ) );
			$ico_f   = sprintf(
				'<span class="esc-nh__fact is-%1$s" data-tip="%2$s" aria-label="%2$s" role="img" tabindex="0"><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 1.8h5.2L12.2 4.8v8.4a1 1 0 01-1 1H4a1 1 0 01-1-1V2.8a1 1 0 011-1z"/><path d="M9 1.8V5h3.2"/>%3$s</svg></span>',
				esc_attr( $estado_f ),
				esc_attr( $txt_f ),
				'todo' === $estado_f ? '<path d="M5.4 9.6l1.6 1.6 3.2-3.4"/>' : '<path d="M5.5 8.5h4M5.5 11h2.6"/>'
			);

			echo '<div class="esc-nh__cell"><span class="esc-nh__k esc-nh__k--fact">' . esc_html__( 'Pagos', 'malka-crm' ) . $ico_f . '</span>';
			echo '<dl class="esc-nh__money">';
			printf( '<div><dt>%s</dt><dd>%s</dd></div>', esc_html__( 'Contratado', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $total ) ) );
			printf( '<div><dt>%s</dt><dd>%s</dd></div>', esc_html__( 'Cobrado', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $cobrado ) ) );
			printf(
				'<div><dt>%1$s</dt><dd class="%3$s">%2$s</dd></div>',
				esc_html( $saldo['vencido'] > 0 ? __( 'Vencido', 'malka-crm' ) : __( 'Por cobrar', 'malka-crm' ) ),
				esc_html( Malka_CRM_Settings::money( $saldo['vencido'] > 0 ? $saldo['vencido'] : $saldo['pendiente'] ) ),
				$saldo['vencido'] > 0 ? 'is-late' : ''
			);
			echo '</dl>';
			echo '</div>';
		}

		echo '</div></section>';

		/* ── Cotización y facturas ── */
		if ( ! $nuevo ) {
			echo self::bloque_documentos( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::bloque_enlaces( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/* ── Árbol ── */
		echo self::bloque_arbol( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/* ── Barra ── */
		echo '<div class="esc-cfg__save esc-ed__save">';
		printf(
			'<span class="esc-ed__meta">%s</span>',
			esc_html(
				$nuevo
					? __( 'Al guardar aparece en el inicio y en el plan del día.', 'malka-crm' )
					: sprintf(
						/* translators: 1: tareas hechas, 2: tareas en total */
						__( '%1$d de %2$d tareas listas', 'malka-crm' ),
						(int) $p['hechas'],
						(int) $p['hojas']
					)
			)
		);
		printf(
			'<a class="esc-btn" href="%s">%s</a>',
			esc_url( Malka_CRM_Dashboard::view_url( 'negocios' ) ),
			esc_html__( 'Cancelar', 'malka-crm' )
		);
		printf(
			'<button type="submit" class="esc-btn esc-btn--primary">%s</button>',
			esc_html( $nuevo ? __( 'Crear negocio', 'malka-crm' ) : __( 'Guardar', 'malka-crm' ) )
		);
		echo '</div>';

		echo '</form>';

		// Va fuera del formulario del negocio: un <form> dentro de otro no es
		// HTML válido y el navegador descarta el de adentro sin avisar.
		if ( ! $nuevo ) {
			echo self::bloque_plantilla( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Las facturas (venta) de un negocio o de su cotización, por fecha.
	 *
	 * @param int $pid Negocio.
	 * @param int $qid Cotización.
	 * @return array[]
	 */
	public static function facturas_negocio( $pid, $qid ) {
		$docs = array();
		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return $docs;
		}
		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Documents::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'   => Malka_CRM_Documents::META_PROYECTO,
						'value' => $pid,
					),
					array(
						'key'   => Malka_CRM_Documents::META_QUOTE,
						'value' => $qid ? $qid : -1,
					),
				),
			)
		);
		foreach ( $ids as $did ) {
			$doc = Malka_CRM_Documents::get( $did );
			if ( 'venta' === $doc['sentido'] ) {
				$docs[] = $doc;
			}
		}
		usort(
			$docs,
			function ( $a, $b ) {
				return strcmp( $a['fecha'], $b['fecha'] );
			}
		);
		return $docs;
	}

	/**
	 * Cuánto se ha facturado (neto) de un negocio.
	 *
	 * @param int $pid Negocio.
	 * @param int $qid Cotización.
	 * @return int
	 */
	public static function facturado_neto( $pid, $qid ) {
		$t = 0;
		foreach ( self::facturas_negocio( $pid, $qid ) as $doc ) {
			if ( 'anulada' !== $doc['estado'] ) {
				$t += (int) $doc['neto'] * (int) $doc['signo'];
			}
		}
		return $t;
	}

	/**
	 * La cotización del negocio y las facturas que se emitieron por él.
	 *
	 * Un negocio sale de una cotización y se cierra con sus facturas: verlos
	 * juntos es saber de un vistazo qué se acordó, qué se cobró y qué falta
	 * facturar. Si todavía no tiene cotización, acá se elige una.
	 *
	 * @param array $p Negocio.
	 * @return string
	 */
	private static function bloque_documentos( $p ) {
		$pid = (int) $p['id'];
		$qid = (int) Malka_CRM_Quotes::get_project_quote_id( $pid );

		/*
		 * Una sola lista, con la misma fila para todo: primero la cotización
		 * y debajo sus facturas, cada una con su ícono, nombre, fecha, monto
		 * y estado en las mismas columnas. Encima, cuánto se ha facturado de
		 * lo contratado. Antes eran dos columnas con alturas distintas.
		 */
		$q          = $qid ? Malka_CRM_Quote_Page::data( $qid ) : null;
		$contratado = $q ? (int) $q['neto'] : 0;
		$docs       = self::facturas_negocio( $pid, $qid );

		$facturado = 0;
		foreach ( $docs as $doc ) {
			if ( 'anulada' !== $doc['estado'] ) {
				$facturado += (int) $doc['neto'] * (int) $doc['signo'];
			}
		}

		$fila = function ( $ico, $url, $titulo, $sub, $fecha, $monto, $clase, $estado, $extra = '', $borrar = '' ) {
			return sprintf(
				'<a class="esc-docr%9$s" href="%2$s" data-modal="%11$s" data-modal-t="%3$s"><span class="esc-doc__ico" aria-hidden="true">%1$s</span>' .
				'<span class="esc-docr__n"><b>%3$s</b><span>%4$s</span></span>' .
				'<span class="esc-docr__f">%5$s</span><span class="esc-docr__m">%6$s</span>' .
				'<span class="esc-docr__e"><span class="esc-st esc-st--%7$s">%8$s</span></span>%10$s</a>',
				esc_html( $ico ),
				esc_url( $url ),
				esc_html( $titulo ),
				esc_html( $sub ),
				esc_html( $fecha ),
				esc_html( $monto ),
				esc_attr( $clase ),
				esc_html( $estado ),
				$extra,
				$borrar,
				esc_url( add_query_arg( 'embed', 1, $url ) )
			);
		};

		ob_start();
		echo '<section class="esc-panel esc-docs">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Cotización y facturas', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Lo que se acordó con el cliente y lo que ya se facturó', 'malka-crm' ) . '</p></div>';
		// Se abre en una ventana encima, sin salir del negocio.
		$base_url = add_query_arg( array_filter( array( 'negocio' => $pid, 'quote' => $qid ) ), Malka_CRM_Documents::edit_url( 0, 'factura' ) );
		printf(
			'<div class="esc-ph__r"><a class="esc-btn esc-btn--soft" href="%1$s" data-modal="%2$s" data-modal-t="%3$s">+ %3$s</a></div>',
			esc_url( $base_url ),
			esc_url( add_query_arg( 'embed', 1, $base_url ) ),
			esc_html__( 'Registrar factura', 'malka-crm' )
		);
		echo '</div>';

		if ( $contratado > 0 ) {
			$pct = max( 0, min( 100, (int) round( $facturado / $contratado * 100 ) ) );
			printf(
				'<div class="esc-docs__fact"><span>%1$s <b>%2$s</b> %3$s %4$s</span><span class="esc-docs__pct">%5$d%%</span><span class="esc-bar"><i style="width:%5$d%%"></i></span></div>',
				esc_html__( 'Facturado', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $facturado ) ),
				esc_html__( 'de', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $contratado ) . ' ' . __( 'neto contratado', 'malka-crm' ) ),
				$pct
			);
		}

		echo '<div class="esc-docs__list">';

		if ( $q ) {
			$estados = Malka_CRM_Quotes::quote_statuses();
			echo $fila( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'C',
				Malka_CRM_Quote_Editor::edit_url( $qid ),
				sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $q['numero'] ? $q['numero'] : $qid ),
				$q['firmada'] ? __( 'Firmada por el cliente', 'malka-crm' ) : __( 'Sin firmar', 'malka-crm' ),
				$q['emitida'] ? date_i18n( 'j M Y', strtotime( $q['emitida'] ) ) : '',
				Malka_CRM_Settings::money( $q['total'] ),
				in_array( $q['estado'], array( 'finalizada', 'entregado' ), true ) ? 'ok' : ( in_array( $q['estado'], array( 'rechazada', 'caducada' ), true ) ? 'late' : 'run' ),
				isset( $estados[ $q['estado'] ] ) ? $estados[ $q['estado'] ] : '',
				'',
				! empty( $q['url'] )
					? sprintf(
						'<span class="esc-docr__x esc-docr__ojo" role="button" tabindex="0" data-modal="%1$s" data-modal-t="%2$s" title="%2$s" aria-label="%2$s"><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8z"/><circle cx="8" cy="8" r="2"/></svg></span>',
						esc_url( $q['url'] ),
						esc_attr__( 'Así la ve el cliente', 'malka-crm' )
					)
					: ''
			);
		} else {
			$libres = get_posts(
				array(
					'post_type'      => Malka_CRM_Quotes::POST_TYPE,
					'post_status'    => array( 'publish', 'draft' ),
					'posts_per_page' => 100,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array(
							'key'     => '_malka_pm_project_id',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => '_malka_pm_project_id',
							'value' => '0',
						),
					),
				)
			);

			echo '<div class="esc-docr esc-docr--vacio"><span class="esc-doc__ico" aria-hidden="true">C</span><span class="esc-docr__n"><b>' . esc_html__( 'Sin cotización asociada', 'malka-crm' ) . '</b>';
			if ( $libres ) {
				printf( '<select name="quote_vincular" id="f-quote-vincular" data-recarga aria-label="%s">', esc_attr__( 'Asociar a una cotización', 'malka-crm' ) );
				echo '<option value="">' . esc_html__( 'Asociar a una cotización…', 'malka-crm' ) . '</option>';
				foreach ( $libres as $lq ) {
					$num = (string) get_post_meta( $lq->ID, '_malka_pm_quote_number', true );
					$cli = (string) get_post_meta( $lq->ID, '_malka_pm_client_name', true );
					printf(
						'<option value="%1$d">%2$s</option>',
						(int) $lq->ID,
						esc_html( trim( ( $num ? 'N° ' . $num . ' · ' : '' ) . $lq->post_title . ( $cli ? ' · ' . $cli : '' ) ) )
					);
				}
				echo '</select>';
			}
			printf(
				'</span><a class="esc-btn esc-btn--soft esc-docs__gen" href="%1$s">+ %2$s</a></div>',
				esc_url( Malka_CRM_Ideas::cotizar_negocio_url( $pid ) ),
				esc_html__( 'Generar cotización', 'malka-crm' )
			);
		}

		foreach ( $docs as $doc ) {
			$total_estados = Malka_CRM_Documents::estados( 'venta' );
			echo $fila( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'F',
				Malka_CRM_Documents::edit_url( $doc['id'] ),
				$doc['rotulo'] . ( $doc['folio'] ? ' N° ' . $doc['folio'] : '' ),
				$doc['ref'] ? $doc['ref'] : $doc['cliente'],
				$doc['fecha'] ? date_i18n( 'j M Y', strtotime( $doc['fecha'] ) ) : '',
				Malka_CRM_Settings::money( $doc['total'] ),
				'anulada' === $doc['estado'] ? 'late' : 'ok',
				isset( $total_estados[ $doc['estado'] ] ) ? $total_estados[ $doc['estado'] ] : $doc['estado'],
				'anulada' === $doc['estado'] ? ' is-anulada' : '',
				sprintf(
					'<span class="esc-docr__x" role="button" tabindex="0" data-doc-borrar="%1$d" data-nonce="%2$s" title="%3$s" aria-label="%3$s"><svg class="esc-x-ic" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></span>',
					(int) $doc['id'],
					esc_attr( wp_create_nonce( 'malka_crm_delete_doc_' . (int) $doc['id'] ) ),
					esc_attr__( 'Mandar esta factura a la papelera', 'malka-crm' )
				)
			);
		}

		if ( ! $docs ) {
			echo '<p class="esc-docs__vacio">' . esc_html__( 'Todavía no hay facturas de este negocio.', 'malka-crm' ) . '</p>';
		}

		echo '</div>';


		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * El árbol de etapas y tareas.
	 *
	 * @param array $p Negocio.
	 * @return string
	 */
	private static function bloque_arbol( $p ) {
		ob_start();
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Etapas y tareas', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'El número manda: 1 es una etapa, 1.1 una tarea suya, 1.1.1 una subtarea. Arrastra una fila del asa para cambiarla de lugar.', 'malka-crm' ) . '</p></div>';

		// Traer etapas de una plantilla: un botón acá, junto a lo que va a
		// llenar, en vez de un panel suelto al final de la página.
		if ( ! empty( $p['id'] ) ) {
			printf(
				'<div class="esc-ph__r"><button type="button" class="esc-btn esc-btn--soft" data-abre="esc-plantilla-dlg"><svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="2" width="11" height="12" rx="1.5"/><path d="M5.5 5.5h5M5.5 8h5M5.5 10.5h3"/></svg>%s</button></div>',
				esc_html__( 'Etapas de una plantilla', 'malka-crm' )
			);
		}

		echo '</div>';

		echo '<div class="esc-arbol" id="esc-arbol" data-prefijo="tarea">';

		foreach ( array_values( $p['tareas'] ) as $i => $t ) {
			echo self::fila_tarea( $i, $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';

		if ( ! $p['tareas'] ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no hay nada. Agrega una etapa y después sus tareas.', 'malka-crm' ) . '</p>';
		}

		echo '<div class="esc-row-btn">';
		printf( '<button type="button" class="esc-mini" id="esc-arbol-etapa">+ %s</button>', esc_html__( 'Etapa', 'malka-crm' ) );
		printf( '<button type="button" class="esc-mini" id="esc-arbol-tarea">+ %s</button>', esc_html__( 'Tarea', 'malka-crm' ) );
		echo '</div>';

		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( 'El avance de una etapa no se escribe: sale solo del promedio de sus tareas al guardar. Al mover una fila los números se rehacen solos, y una etapa se lleva sus tareas con ella.', 'malka-crm' )
		);

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Traer las etapas desde una plantilla.
	 *
	 * Va en su propio panel y fuera del formulario del negocio: es otra
	 * acción, con su propio destino, y meter un formulario dentro de otro no
	 * es HTML válido —el navegador lo desarma y se pierde el de adentro.
	 *
	 * @param array $p Negocio.
	 * @return string
	 */
	private static function bloque_plantilla( $p ) {
		$plantillas = Malka_CRM_Templates::get_templates();

		ob_start();
		// Una ventana que abre el botón de «Etapas y tareas». Sigue siendo su
		// propio formulario, fuera del del negocio.
		printf( '<dialog class="esc-dlg" id="esc-plantilla-dlg" aria-labelledby="esc-plantilla-t">' );
		printf(
			'<button type="button" class="esc-dlg__x" data-cierra aria-label="%s">×</button>',
			esc_attr__( 'Cerrar', 'malka-crm' )
		);
		echo '<div class="esc-ph"><div><h2 id="esc-plantilla-t">' . esc_html__( 'Etapas de una plantilla', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'La metodología que ya escribiste, con las fechas encadenadas desde el día que elijas', 'malka-crm' ) . '</p></div></div>';

		if ( empty( $plantillas ) ) {
			printf(
				'<p class="esc-empty">%1$s</p><div class="esc-row-btn"><a class="esc-btn" href="%2$s">%3$s</a></div>',
				esc_html__( 'Todavía no tienes plantillas. Escribe una vez las etapas de un branding o de un sitio web y después se aplican con un clic.', 'malka-crm' ),
				esc_url( Malka_CRM_Templates_Panel::list_url() ),
				esc_html__( 'Crear una plantilla', 'malka-crm' )
			);
			echo '</dialog>';

			return ob_get_clean();
		}

		printf( '<form class="esc-aplicar" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'malka_crm_aplicar_plantilla_' . (int) $p['id'], '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_aplicar_plantilla" />';
		printf( '<input type="hidden" name="negocio" value="%d" />', (int) $p['id'] );

		printf( '<label class="esc-tarea__c"><span>%1$s</span><select name="plantilla">', esc_html__( 'Plantilla', 'malka-crm' ) );
		foreach ( $plantillas as $t ) {
			printf(
				'<option value="%1$d">%2$s</option>',
				(int) $t->ID,
				esc_html( $t->post_title ? $t->post_title : __( '(sin nombre)', 'malka-crm' ) )
			);
		}
		echo '</select></label>';

		printf(
			'<label class="esc-tarea__c"><span>%1$s</span><input type="date" name="desde" value="%2$s" /></label>',
			esc_html__( 'Parte el', 'malka-crm' ),
			esc_attr( ! empty( $p['inicio'] ) ? $p['inicio'] : current_time( 'Y-m-d' ) )
		);

		printf(
			'<label class="esc-chk"><input type="checkbox" name="reemplazar" value="1" /> %s</label>',
			esc_html__( 'Reemplazar lo que ya hay', 'malka-crm' )
		);

		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Aplicar', 'malka-crm' ) );
		echo '</form>';

		printf(
			'<p class="esc-hint">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'Sin marcar «reemplazar», las líneas de la plantilla se suman a las que ya tienes.', 'malka-crm' ),
			esc_url( Malka_CRM_Templates_Panel::list_url() ),
			esc_html__( 'Ver y editar las plantillas', 'malka-crm' )
		);

		echo '</dialog>';

		return ob_get_clean();
	}

	/**
	 * La línea que se lee con la fila cerrada: quién, cuándo y cómo va.
	 *
	 * El JS la vuelve a armar al vuelo cuando ella cambia algo adentro, así
	 * que si cambia el formato hay que cambiarlo en los dos lados.
	 *
	 * @param array $t Tarea.
	 * @return string
	 */
	public static function resumen_tarea( $t ) {
		$v = function ( $k, $def = '' ) use ( $t ) {
			return isset( $t[ $k ] ) ? $t[ $k ] : $def;
		};

		$partes = array();
		$quien  = self::responsables();
		$owner  = (string) $v( 'owner', 'studio' );

		if ( isset( $quien[ $owner ] ) && 'none' !== $owner ) {
			$partes[] = $quien[ $owner ];
		}

		$ini = Malka_CRM_Helpers::sanitize_date( (string) $v( 'start' ) );
		$fin = Malka_CRM_Helpers::sanitize_date( (string) $v( 'end' ) );

		if ( $ini && $fin && $ini !== $fin ) {
			$partes[] = date_i18n( 'j M', strtotime( $ini ) ) . ' – ' . date_i18n( 'j M', strtotime( $fin ) );
		} elseif ( $fin ) {
			$partes[] = date_i18n( 'j M', strtotime( $fin ) );
		} elseif ( $ini ) {
			$partes[] = date_i18n( 'j M', strtotime( $ini ) );
		}

		$pct = (int) $v( 'progress', 0 );

		if ( $pct > 0 ) {
			/* translators: porcentaje de avance */
			$partes[] = sprintf( __( '%d%%', 'malka-crm' ), $pct );
		}

		if ( $v( 'needs_approval' ) ) {
			$partes[] = __( 'la aprueba el cliente', 'malka-crm' );
		}

		if ( $v( 'privada' ) ) {
			$partes[] = __( 'sólo para el estudio', 'malka-crm' );
		}

		return implode( ' · ', $partes );
	}

	/**
	 * Una fila del árbol.
	 *
	 * @param int|string $i Índice, o __i__ para la plantilla del JS.
	 * @param array      $t Tarea.
	 * @return string
	 */
	/**
	 * El asa para arrastrar una fila del árbol.
	 *
	 * Es un botón de verdad, no un adorno: así se puede llegar con el teclado
	 * y mover con Alt y las flechas, que es más rápido que arrastrar cuando la
	 * lista es larga. La misma asa la usa el editor de plantillas.
	 *
	 * @return string
	 */
	public static function asa_mover() {
		return sprintf(
			'<button type="button" class="esc-tarea__mover" data-mover aria-label="%1$s" title="%2$s">' .
			'<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">' .
			'<circle cx="6" cy="4" r="1.3"/><circle cx="10" cy="4" r="1.3"/>' .
			'<circle cx="6" cy="8" r="1.3"/><circle cx="10" cy="8" r="1.3"/>' .
			'<circle cx="6" cy="12" r="1.3"/><circle cx="10" cy="12" r="1.3"/>' .
			'</svg></button>',
			esc_attr__( 'Mover esta fila', 'malka-crm' ),
			esc_attr__( 'Arrástrala para cambiarla de lugar, o muévela con Alt y las flechas', 'malka-crm' )
		);
	}

	/**
	 * Los campos de una publicación: copy, enlace al archivo y, en un
	 * negocio, fecha y hora en que sale. Plegados mientras estén vacíos.
	 *
	 * @param string     $pre       tarea|tpl.
	 * @param int|string $i         Índice.
	 * @param array      $t         Tarea o ítem.
	 * @param bool       $con_fecha Si lleva fecha y hora (en una plantilla no).
	 * @return string
	 */
	public static function es_publicacion( $t, $adivinar = true ) {
		if ( isset( $t['type'] ) && 'stage' === $t['type'] ) {
			return false;
		}
		$lleno = false;
		foreach ( array( 'pub_copy', 'pub_url', 'pub_fecha', 'pub_formato', 'pub_tema', 'pub_hashtags', 'pub_voz', 'pub_encuesta', 'pub_portada', 'pub_musica', 'pub_nota' ) as $k ) {
			if ( ! empty( $t[ $k ] ) ) {
				$lleno = true;
				break;
			}
		}
		/*
		 * Una subtarea (1.1.1) es un paso de su tarea («Historia de la joya»):
		 * es publicación sólo si de verdad tiene datos de publicación. Antes
		 * el interruptor guardaba «sí» con sólo deducirlo del nombre.
		 */
		if ( isset( $t['number'] ) && substr_count( trim( (string) $t['number'] ), '.' ) >= 2 ) {
			return $lleno && ( ! isset( $t['es_pub'] ) || '0' !== (string) $t['es_pub'] );
		}
		if ( isset( $t['es_pub'] ) && '' !== (string) $t['es_pub'] ) {
			return (bool) (int) $t['es_pub'];
		}
		if ( $lleno ) {
			return true;
		}
		return $adivinar && preg_match( '/publicaci|\bpost\b|\breel|carrusel|historia|stor(y|ies)/i', remove_accents( isset( $t['name'] ) ? (string) $t['name'] : '' ) );
	}


	/**
	 * El interruptor «Es una publicación» y su bloque.
	 *
	 * @param string     $pre       tarea|tpl.
	 * @param int|string $i         Índice.
	 * @param array      $t         Tarea o ítem.
	 * @param bool       $con_fecha Con fecha y hora.
	 * @param bool       $adivinar  Si se deduce del nombre cuando no está marcado.
	 * @return string
	 */
	public static function publicacion( $pre, $i, $t, $con_fecha, $adivinar ) {
		$es = self::es_publicacion( $t, $adivinar );
		return sprintf(
			'<label class="esc-td__c"><span>%3$s</span><span class="esc-td__sw"><input type="hidden" name="%1$s[%2$s][es_pub]" value="%6$s" data-es-pub-h /><input type="checkbox" class="esc-sw" name="%1$s[%2$s][es_pub]" value="1"%4$s data-es-pub /><b>%5$s</b></span></label>',
			esc_attr( $pre ),
			esc_attr( $i ),
			esc_html__( 'Redes sociales', 'malka-crm' ),
			$es ? ' checked' : '',
			esc_html__( 'Es una publicación', 'malka-crm' ),
			// Vacío mientras nadie lo decida: así se sigue deduciendo del nombre.
			( isset( $t['es_pub'] ) && '' !== (string) $t['es_pub'] ) ? '0' : ''
		) . str_replace( '<details class="esc-td__pub"', '<details class="esc-td__pub"' . ( $es ? '' : ' hidden' ), self::bloque_publicacion( $pre, $i, $t, $con_fecha ) );
	}

	public static function bloque_publicacion( $pre, $i, $t, $con_fecha = true ) {
		$g = function ( $k ) use ( $t ) {
			return isset( $t[ $k ] ) ? (string) $t[ $k ] : '';
		};
		$n = function ( $k ) use ( $pre, $i ) {
			return esc_attr( $pre ) . '[' . esc_attr( $i ) . '][' . $k . ']';
		};
		$fecha  = $g( 'pub_fecha' );
		$fmts   = Malka_CRM_Portal::formatos_pub();
		$lleno  = false;
		foreach ( array( 'pub_copy', 'pub_url', 'pub_fecha', 'pub_tema', 'pub_hashtags', 'pub_voz', 'pub_encuesta', 'pub_enlace_reel', 'pub_portada', 'pub_musica', 'pub_nota', 'pub_quien', 'pub_formato' ) as $k ) {
			$lleno = $lleno || '' !== $g( $k );
		}
		$resumen = array();
		if ( $g( 'pub_formato' ) && isset( $fmts[ $g( 'pub_formato' ) ] ) ) {
			$resumen[] = $fmts[ $g( 'pub_formato' ) ];
		}
		$resumen[] = $fecha ? sprintf( /* translators: fecha */ __( 'sale el %s', 'malka-crm' ), date_i18n( 'j M · H:i', strtotime( $fecha ) ) ) : __( 'formato, copy, archivo y fecha', 'malka-crm' );
		$ic  = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="2.5" width="11" height="11" rx="3"/><circle cx="8" cy="8" r="2.4"/><circle cx="11.2" cy="4.8" r=".5"/></svg>';
		$out = sprintf(
			'<details class="esc-td__pub"%1$s><summary>%2$s<span>%3$s</span><small>%4$s</small></summary><div class="esc-td__pubg">',
			$lleno ? ' open' : '',
			$ic,
			esc_html__( 'Publicación', 'malka-crm' ),
			esc_html( implode( ' · ', $resumen ) )
		);

		// Fila 1: formato, cuándo sale y tema.
		$sel = '<option value="">' . esc_html__( '—', 'malka-crm' ) . '</option>';
		foreach ( $fmts as $k => $rot ) {
			$sel .= '<option value="' . esc_attr( $k ) . '"' . selected( $g( 'pub_formato' ), $k, false ) . '>' . esc_html( $rot ) . '</option>';
		}
		$out .= '<label class="esc-td__c"><span>' . esc_html__( 'Formato', 'malka-crm' ) . '</span><select name="' . $n( 'pub_formato' ) . '" data-pub-formato>' . $sel . '</select></label>';
		if ( $con_fecha ) {
			$out .= '<label class="esc-td__c"><span>' . esc_html__( 'Se publica el', 'malka-crm' ) . '</span><input type="datetime-local" name="' . $n( 'pub_fecha' ) . '" value="' . esc_attr( $fecha ? substr( str_replace( ' ', 'T', $fecha ), 0, 16 ) : '' ) . '" /></label>';
		}
		// Quién la publica: si es el cliente, le aparece en «Te toca a ti».
		$out .= '<label class="esc-td__c"><span>' . esc_html__( 'Quién publica', 'malka-crm' ) . '</span><select name="' . $n( 'pub_quien' ) . '"><option value="">' . esc_html__( 'El estudio', 'malka-crm' ) . '</option><option value="client"' . selected( 'client', $g( 'pub_quien' ), false ) . '>' . esc_html__( 'El cliente (le aparece en «Te toca a ti»)', 'malka-crm' ) . '</option></select></label>';
		$out .= '<label class="esc-td__c"><span>' . esc_html__( 'Tema', 'malka-crm' ) . '</span><input type="text" name="' . $n( 'pub_tema' ) . '" value="' . esc_attr( $g( 'pub_tema' ) ) . '" placeholder="' . esc_attr__( 'De qué trata', 'malka-crm' ) . '" /></label>';

		// Fila 2: copy, hashtags y archivo.
		$out .= '<label class="esc-td__c esc-td__copy"><span>' . esc_html__( 'Copy', 'malka-crm' ) . '</span><textarea name="' . $n( 'pub_copy' ) . '" rows="4" placeholder="' . esc_attr__( 'El texto que acompaña la publicación', 'malka-crm' ) . '">' . esc_textarea( $g( 'pub_copy' ) ) . '</textarea></label>';
		$out .= '<label class="esc-td__c"><span>' . esc_html__( 'Hashtags', 'malka-crm' ) . '</span><textarea name="' . $n( 'pub_hashtags' ) . '" rows="2" placeholder="#malka #diseño">' . esc_textarea( $g( 'pub_hashtags' ) ) . '</textarea></label>';
		$url  = $g( 'pub_url' );
		$out .= '<label class="esc-td__c"><span>' . esc_html__( 'Archivo de la publicación', 'malka-crm' ) . ( $url ? ' · <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'abrir', 'malka-crm' ) . ' ↗</a>' : '' ) . '</span><input type="url" name="' . $n( 'pub_url' ) . '" value="' . esc_attr( $url ) . '" placeholder="https://drive.google.com/…" /></label>';

		// Fila 3: voz en off, encuesta y lo propio de un reel.
		$out .= '<label class="esc-td__c esc-td__copy"><span>' . esc_html__( 'Texto de la voz en off', 'malka-crm' ) . '</span><textarea name="' . $n( 'pub_voz' ) . '" rows="3" placeholder="' . esc_attr__( 'Si lleva voz en off, lo que se dice', 'malka-crm' ) . '">' . esc_textarea( $g( 'pub_voz' ) ) . '</textarea></label>';
		if ( $g( 'pub_audio' ) ) {
			$out .= '<div class="esc-td__c esc-td__audio"><span>' . esc_html( sprintf( /* translators: fecha */ __( 'Voz en off grabada por el cliente · %s', 'malka-crm' ), $g( 'pub_audio_at' ) ? date_i18n( 'j M · H:i', strtotime( $g( 'pub_audio_at' ) ) ) : '' ) ) . '</span><audio controls preload="none" src="' . esc_url( $g( 'pub_audio' ) ) . '"></audio><a href="' . esc_url( $g( 'pub_audio' ) ) . '" download>' . esc_html__( 'Descargar', 'malka-crm' ) . '</a></div>';
		}
		$out .= '<label class="esc-td__c"><span>' . esc_html__( 'Encuesta', 'malka-crm' ) . '</span><textarea name="' . $n( 'pub_encuesta' ) . '" rows="3" placeholder="' . esc_attr__( 'Si lleva encuesta: la pregunta y sus opciones', 'malka-crm' ) . '">' . esc_textarea( $g( 'pub_encuesta' ) ) . '</textarea></label>';
		$out .= '<div class="esc-td__c esc-td__reel" data-solo-reel' . ( 'reel' === $g( 'pub_formato' ) ? '' : ' hidden' ) . '><span>' . esc_html__( 'Reel', 'malka-crm' ) . '</span>' .
			'<label class="esc-td__sw"><input type="hidden" name="' . $n( 'pub_prueba' ) . '" value="0" /><input type="checkbox" class="esc-sw" name="' . $n( 'pub_prueba' ) . '" value="1"' . checked( '1', $g( 'pub_prueba' ), false ) . ' /><b>' . esc_html__( 'Reel de prueba', 'malka-crm' ) . '</b></label>' .
			'<input type="text" name="' . $n( 'pub_enlace_reel' ) . '" value="' . esc_attr( $g( 'pub_enlace_reel' ) ) . '" placeholder="' . esc_attr__( 'Se enlaza con… (otro reel)', 'malka-crm' ) . '" /></div>';
		// Música (nombre de la canción o enlace) y una nota que ve el cliente.
		$out .= '<label class="esc-td__c"><span>' . esc_html__( 'Música', 'malka-crm' ) . '</span><input type="text" name="' . $n( 'pub_musica' ) . '" value="' . esc_attr( $g( 'pub_musica' ) ) . '" placeholder="' . esc_attr__( 'Canción, artista o enlace', 'malka-crm' ) . '" /></label>';
		$out .= '<label class="esc-td__c esc-td__copy esc-td__nota-cli"><span>' . esc_html__( 'Notas para el cliente', 'malka-crm' ) . '</span><textarea name="' . $n( 'pub_nota' ) . '" rows="3" placeholder="' . esc_attr__( 'Lo que el cliente debe saber de esta publicación (lo ve en su portal)', 'malka-crm' ) . '">' . esc_textarea( $g( 'pub_nota' ) ) . '</textarea></label>';
		// La portada del reel: el enlace para descargarla (Drive, WeTransfer…).
		$portada = $g( 'pub_portada' );
		$out    .= '<label class="esc-td__c esc-td__portada" data-solo-reel' . ( 'reel' === $g( 'pub_formato' ) ? '' : ' hidden' ) . '><span>' . esc_html__( 'Portada del reel', 'malka-crm' ) . ( $portada ? ' · <a href="' . esc_url( $portada ) . '" target="_blank" rel="noopener">' . esc_html__( 'abrir', 'malka-crm' ) . ' ↗</a>' : '' ) . '</span><input type="url" name="' . $n( 'pub_portada' ) . '" value="' . esc_attr( $portada ) . '" placeholder="' . esc_attr__( 'Enlace de descarga de la portada', 'malka-crm' ) . '" /></label>';

		return $out . '</div></details>';
	}

	/**
	 * Una celda de la franja de datos: rótulo chico y el valor, que se
	 * cambia ahí mismo (lista, fecha o número), sin abrir nada.
	 *
	 * @param string $rotulo Rótulo.
	 * @param string $campo  Control ya armado.
	 * @param string $clase  Clase extra.
	 * @return string
	 */
	private static function celda( $rotulo, $campo, $clase = '' ) {
		return '<label class="esc-fx__d' . ( $clase ? ' ' . esc_attr( $clase ) : '' ) . '"><small>' . esc_html( $rotulo ) . '</small>' . $campo . '</label>';
	}

	/**
	 * Un select con sus opciones.
	 *
	 * @param string $name  Nombre del campo (o vacío).
	 * @param array  $ops   valor => rótulo.
	 * @param string $valor Valor elegido.
	 * @param string $attr  Atributos extra.
	 * @return string
	 */
	private static function lista( $name, $ops, $valor, $attr = '' ) {
		$o = '';
		foreach ( $ops as $k => $r ) {
			$o .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $valor, (string) $k, false ) . '>' . esc_html( $r ) . '</option>';
		}
		return '<select' . ( $name ? ' name="' . esc_attr( $name ) . '"' : '' ) . $attr . '>' . $o . '</select>';
	}

	/**
	 * El detalle de una tarea: una franja de datos que se edita en su
	 * lugar, la publicación tal como la ve el cliente, las notas internas
	 * y el avance. Mismos nombres de campo que antes: lo guarda igual.
	 *
	 * @param int|string $i     Índice.
	 * @param array      $t     Tarea.
	 * @param bool       $etapa Si es etapa.
	 * @return string
	 */
	private static function ficha_detalle( $i, $t, $etapa ) {
		$v = function ( $k, $def = '' ) use ( $t ) {
			return isset( $t[ $k ] ) ? $t[ $k ] : $def;
		};
		$n = function ( $k ) use ( $i ) {
			return 'tarea[' . $i . '][' . $k . ']';
		};

		$resp_op = array(
			''      => __( 'Nota opcional', 'malka-crm' ),
			'no'    => __( 'No', 'malka-crm' ),
			'nota'  => __( 'Nota escrita', 'malka-crm' ),
			'audio' => __( 'Nota de voz', 'malka-crm' ),
			'ambas' => __( 'Nota o voz', 'malka-crm' ),
		);
		$pide  = in_array( (string) $v( 'respuesta' ), array( 'nota', 'audio', 'ambas' ), true );
		$es    = self::es_publicacion( $t, true );
		$fijo  = isset( $t['es_pub'] ) && '' !== (string) $t['es_pub'];

		$out  = '<div class="esc-tarea__det" hidden><div class="esc-td esc-fx">';
		$out .= '<div class="esc-fx__datos">';
		$out .= self::celda( __( 'Qué es', 'malka-crm' ), self::lista( $n( 'type' ), self::tipos(), $v( 'type', 'task' ), ' class="esc-tarea__t" data-tipo' ) );
		$out .= self::celda( __( 'Quién', 'malka-crm' ), self::lista( $n( 'owner' ), self::responsables(), $v( 'owner', 'studio' ) ) );
		$out .= self::celda( __( 'Parte', 'malka-crm' ), '<input type="date" name="' . esc_attr( $n( 'start' ) ) . '" value="' . esc_attr( $v( 'start' ) ) . '" />', 'is-fecha' );
		$out .= self::celda( __( 'Entrega', 'malka-crm' ), '<input type="date" name="' . esc_attr( $n( 'end' ) ) . '" value="' . esc_attr( $v( 'end' ) ) . '" data-entrega />', 'is-fecha is-entrega' );
		$out .= self::celda( __( 'El cliente responde', 'malka-crm' ), self::lista( $n( 'respuesta' ), $resp_op, (string) $v( 'respuesta' ), ' data-respuesta' ) );
		$out .= self::celda( __( 'Aprobación', 'malka-crm' ), self::lista( $n( 'needs_approval' ), array( '' => __( 'No', 'malka-crm' ), '1' => __( 'La aprueba el cliente', 'malka-crm' ) ), $v( 'needs_approval' ) ? '1' : '' ) );
		$out .= self::celda( __( 'Se repite', 'malka-crm' ), self::lista( $n( 'repite' ), self::repeticiones(), (string) $v( 'repite' ) ) );
		$out .= self::celda(
			__( 'Rondas', 'malka-crm' ),
			'<input type="number" min="0" step="1" name="' . esc_attr( $n( 'rounds' ) ) . '" value="' . esc_attr( (string) $v( 'rounds' ) ) . '" placeholder="' . esc_attr__( 'Las del negocio', 'malka-crm' ) . '" title="' . esc_attr__( 'Vacío: las que trae el negocio.', 'malka-crm' ) . '" />'
		);
		$out .= self::celda( __( 'La ve', 'malka-crm' ), self::lista( $n( 'privada' ), array( '' => __( 'El cliente', 'malka-crm' ), '1' => __( 'Sólo el estudio', 'malka-crm' ) ), $v( 'privada' ) ? '1' : '', ' data-privada' ) );
		if ( ! $etapa ) {
			// Si es publicación. Mientras nadie lo decida se deduce del nombre,
			// así que la lista no lleva nombre hasta que ella la cambie.
			$out .= self::celda(
				__( 'Publicación', 'malka-crm' ),
				'<input type="hidden" name="' . esc_attr( $n( 'es_pub' ) ) . '" value="' . ( $fijo ? ( $es ? '1' : '0' ) : '' ) . '" data-es-pub-h />' .
				self::lista( '', array( '1' => __( 'Sí', 'malka-crm' ), '0' => __( 'No', 'malka-crm' ) ), $es ? '1' : '0', ' data-es-pub' )
			);
		}
		$out .= '</div>';
		$out .= sprintf(
			'<input type="hidden" name="%1$s" value="%2$s" /><input type="hidden" name="%3$s" value="%4$d" />',
			esc_attr( $n( 'ultima' ) ),
			esc_attr( (string) $v( 'ultima' ) ),
			esc_attr( $n( 'veces' ) ),
			(int) $v( 'veces', 0 )
		);

		// Qué le preguntas: sólo si se le pide nota o grabación.
		$out .= '<label class="esc-fx__fila esc-td__preg"' . ( $pide ? '' : ' hidden' ) . '><span class="esc-fx__k">' . esc_html__( 'Qué le preguntas', 'malka-crm' ) . '</span><input class="esc-fx__ed" type="text" name="' . esc_attr( $n( 'respuesta_preg' ) ) . '" value="' . esc_attr( (string) $v( 'respuesta_preg' ) ) . '" placeholder="' . esc_attr__( 'Ej.: ¿Te gusta cómo quedó? Cuéntanos qué cambiarías', 'malka-crm' ) . '" /></label>';

		if ( $v( 'resp_audio' ) ) {
			$out .= sprintf(
				'<div class="esc-fx__fila esc-fx__audio"><span class="esc-fx__k">%1$s</span><audio controls preload="none" src="%2$s"></audio><a href="%2$s" download>%3$s</a></div>',
				esc_html( sprintf( /* translators: fecha */ __( 'Grabación del cliente · %s', 'malka-crm' ), $v( 'resp_audio_at' ) ? date_i18n( 'j M · H:i', strtotime( $v( 'resp_audio_at' ) ) ) : '' ) ),
				esc_url( $v( 'resp_audio' ) ),
				esc_html__( 'Descargar', 'malka-crm' )
			);
		}

		// Lo que escribió el cliente desde su portal: se lee, no se edita.
		if ( '' !== trim( (string) $v( 'nota_cliente' ) ) ) {
			$out .= sprintf(
				'<div class="esc-fx__nc"><span class="esc-fx__k">%1$s%2$s</span><p>%3$s</p></div>',
				esc_html__( 'Nota del cliente', 'malka-crm' ),
				$v( 'nota_cliente_at' ) ? ' · ' . esc_html( date_i18n( 'j M · H:i', strtotime( $v( 'nota_cliente_at' ) ) ) ) : '',
				nl2br( esc_html( $v( 'nota_cliente' ) ) )
			);
		}

		if ( ! $etapa ) {
			$out .= self::ficha_publicacion( $i, $t, $es );
		}

		// Notas internas, arriba de las subtareas.
		$out .= '<div class="esc-fx__sec"><span class="esc-fx__k">' . esc_html__( 'Notas internas', 'malka-crm' ) . '</span>';
		$out .= '<textarea class="esc-fx__ed esc-tarea__notas" rows="1" name="' . esc_attr( $n( 'notes' ) ) . '" placeholder="' . esc_attr__( 'Lo que haya que recordar de esta tarea (no lo ve el cliente)…', 'malka-crm' ) . '" data-crece>' . esc_textarea( (string) $v( 'notes' ) ) . '</textarea></div>';

		// El avance, con su barra; en una etapa sale solo del promedio.
		$pct  = max( 0, min( 100, (int) $v( 'progress', 0 ) ) );
		$out .= sprintf(
			'<div class="esc-fx__av"><span class="esc-fx__k" data-av-subs>%1$s</span><label class="esc-fx__avl" title="%2$s"><i class="esc-fx__bar"><i style="width:%3$d%%" data-av-bar></i></i><input type="number" min="0" max="100" step="5" name="%4$s" value="%3$d"%5$s data-av /><em>%%</em></label></div>',
			esc_html( $etapa ? __( 'Avance de la etapa (sale de sus tareas)', 'malka-crm' ) : __( 'Avance', 'malka-crm' ) ),
			esc_html__( 'Avance', 'malka-crm' ),
			$pct,
			esc_attr( $n( 'progress' ) ),
			$etapa ? ' readonly' : ''
		);

		return $out . '</div></div>';
	}

	/**
	 * La publicación con el mismo diseño que ve el cliente en su panel,
	 * pero cada parte se escribe ahí mismo.
	 *
	 * @param int|string $i  Índice.
	 * @param array      $t  Tarea.
	 * @param bool       $es Si es publicación.
	 * @return string
	 */
	private static function ficha_publicacion( $i, $t, $es ) {
		$g = function ( $k ) use ( $t ) {
			return isset( $t[ $k ] ) ? (string) $t[ $k ] : '';
		};
		$n = function ( $k ) use ( $i ) {
			return esc_attr( 'tarea[' . $i . '][' . $k . ']' );
		};
		$reel  = 'reel' === $g( 'pub_formato' );
		$fecha = $g( 'pub_fecha' );
		$ts    = $fecha ? strtotime( $fecha ) : 0;
		$fmts  = array( '' => __( 'Formato', 'malka-crm' ) ) + Malka_CRM_Portal::formatos_pub();

		$out  = '<div class="esc-fx__sec esc-fx__pubs"' . ( $es ? '' : ' hidden' ) . '>';
		$out .= '<div class="esc-fx__h"><span class="esc-fx__k">' . esc_html__( 'Publicación', 'malka-crm' ) . '</span><span class="esc-fx__fechas" data-pub-dif></span></div>';
		$out .= '<div class="esc-fx__pub">';

		// La fecha grande: se publica (distinta de la entrega de la tarea).
		$out .= '<div class="esc-fx__f" data-pub-f>';
		$out .= '<span class="esc-fx__k">' . esc_html__( 'Se publica', 'malka-crm' ) . '</span>';
		$out .= '<em data-pub-num>' . ( $ts ? esc_html( gmdate( 'j', $ts ) ) : '—' ) . '</em>';
		$dsem = array( 'Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado' );
		$out .= '<strong data-pub-dsem>' . esc_html( $ts ? $dsem[ (int) gmdate( 'w', $ts ) ] : __( 'Sin fecha', 'malka-crm' ) ) . '</strong>';
		$out .= '<input type="hidden" name="' . $n( 'pub_fecha' ) . '" value="' . esc_attr( $fecha ? substr( str_replace( ' ', 'T', $fecha ), 0, 16 ) : '' ) . '" data-pub-fecha />';
		$out .= '<input class="esc-fx__ed esc-fx__dia" type="date" value="' . esc_attr( $ts ? gmdate( 'Y-m-d', $ts ) : '' ) . '" aria-label="' . esc_attr__( 'Fecha de publicación', 'malka-crm' ) . '" data-pub-dia />';
		$out .= '<input class="esc-fx__ed esc-fx__hora" type="time" value="' . esc_attr( $ts ? gmdate( 'H:i', $ts ) : '' ) . '" aria-label="' . esc_attr__( 'Hora de publicación', 'malka-crm' ) . '" data-pub-hora />';
		$out .= '</div>';

		$out .= '<div class="esc-fx__pc">';
		$out .= '<div class="esc-fx__tags"><label class="esc-fx__fmt">' . self::lista( $n( 'pub_formato' ), $fmts, $g( 'pub_formato' ), ' data-pub-formato aria-label="' . esc_attr__( 'Formato', 'malka-crm' ) . '"' ) . '</label>';
		$out .= '<label class="esc-fx__quien">' . esc_html__( 'Publica', 'malka-crm' ) . ' ' . self::lista( $n( 'pub_quien' ), array( '' => __( 'el estudio', 'malka-crm' ), 'client' => __( 'el cliente', 'malka-crm' ) ), $g( 'pub_quien' ), ' title="' . esc_attr__( 'Si es el cliente, le aparece en «Te toca a ti»', 'malka-crm' ) . '"' ) . '</label></div>';
		$out .= '<textarea class="esc-fx__ed esc-fx__copy" rows="1" name="' . $n( 'pub_copy' ) . '" placeholder="' . esc_attr__( 'Escribe el copy aquí…', 'malka-crm' ) . '" data-crece>' . esc_textarea( $g( 'pub_copy' ) ) . '</textarea>';
		$out .= '<textarea class="esc-fx__ed esc-fx__hash" rows="1" name="' . $n( 'pub_hashtags' ) . '" placeholder="#hashtags" data-crece>' . esc_textarea( $g( 'pub_hashtags' ) ) . '</textarea>';

		$fila = function ( $rot, $campo, $extra = '', $oculta = false ) {
			return '<label class="esc-fx__fila esc-fx__b"' . $extra . ( $oculta ? ' hidden' : '' ) . '><span class="esc-fx__k">' . esc_html( $rot ) . '</span>' . $campo . '</label>';
		};
		$enl = function ( $u ) {
			return $u ? '<a class="esc-fx__abrir" href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html__( 'abrir', 'malka-crm' ) . ' ↗</a>' : '';
		};
		$out .= $fila( __( 'Tema', 'malka-crm' ), '<input class="esc-fx__ed" type="text" name="' . $n( 'pub_tema' ) . '" value="' . esc_attr( $g( 'pub_tema' ) ) . '" placeholder="' . esc_attr__( 'De qué trata', 'malka-crm' ) . '" />' );
		$out .= $fila( __( 'Música', 'malka-crm' ), '<input class="esc-fx__ed" type="text" name="' . $n( 'pub_musica' ) . '" value="' . esc_attr( $g( 'pub_musica' ) ) . '" placeholder="' . esc_attr__( 'Canción, artista o enlace', 'malka-crm' ) . '" />' );
		$out .= $fila( __( 'Archivo', 'malka-crm' ), '<input class="esc-fx__ed" type="url" name="' . $n( 'pub_url' ) . '" value="' . esc_attr( $g( 'pub_url' ) ) . '" placeholder="' . esc_attr__( 'Pega el enlace de descarga', 'malka-crm' ) . '" />' . $enl( $g( 'pub_url' ) ) );
		$out .= $fila( __( 'Portada', 'malka-crm' ), '<input class="esc-fx__ed" type="url" name="' . $n( 'pub_portada' ) . '" value="' . esc_attr( $g( 'pub_portada' ) ) . '" placeholder="' . esc_attr__( 'Pega el enlace de la portada', 'malka-crm' ) . '" />' . $enl( $g( 'pub_portada' ) ), ' data-solo-reel', ! $reel );
		$out .= '<div class="esc-fx__fila esc-fx__b" data-solo-reel' . ( $reel ? '' : ' hidden' ) . '><span class="esc-fx__k">' . esc_html__( 'Reel', 'malka-crm' ) . '</span>' .
			'<label class="esc-fx__sw"><input type="hidden" name="' . $n( 'pub_prueba' ) . '" value="0" /><input type="checkbox" class="esc-sw" name="' . $n( 'pub_prueba' ) . '" value="1"' . checked( '1', $g( 'pub_prueba' ), false ) . ' /><b>' . esc_html__( 'De prueba', 'malka-crm' ) . '</b></label>' .
			'<input class="esc-fx__ed" type="text" name="' . $n( 'pub_enlace_reel' ) . '" value="' . esc_attr( $g( 'pub_enlace_reel' ) ) . '" placeholder="' . esc_attr__( 'Se enlaza con… (otro reel)', 'malka-crm' ) . '" /></div>';

		// Encuesta y voz en off: a la vista si tienen algo; si no, con su «+».
		$extras = array(
			'pub_encuesta' => array( __( 'Encuesta', 'malka-crm' ), __( 'La pregunta y sus opciones, una por línea…', 'malka-crm' ) ),
			'pub_voz'      => array( __( 'Voz en off', 'malka-crm' ), __( 'Lo que se dice en la voz en off…', 'malka-crm' ) ),
		);
		$mas = '';
		foreach ( $extras as $k => $e ) {
			$lleno = '' !== trim( $g( $k ) );
			$out  .= '<div class="esc-fx__b esc-fx__ex" data-extra="' . esc_attr( $k ) . '"' . ( $lleno ? '' : ' hidden' ) . '><span class="esc-fx__k">' . esc_html( $e[0] ) . '</span><textarea class="esc-fx__ed" rows="1" name="' . $n( $k ) . '" placeholder="' . esc_attr( $e[1] ) . '" data-crece>' . esc_textarea( $g( $k ) ) . '</textarea></div>';
			if ( ! $lleno ) {
				$mas .= '<button type="button" class="esc-fx__mas" data-extra-abre="' . esc_attr( $k ) . '">+ ' . esc_html( $e[0] ) . '</button>';
			}
		}
		if ( $g( 'pub_audio' ) ) {
			$out .= '<div class="esc-fx__fila esc-fx__b esc-fx__audio"><span class="esc-fx__k">' . esc_html( sprintf( /* translators: fecha */ __( 'Voz grabada por el cliente · %s', 'malka-crm' ), $g( 'pub_audio_at' ) ? date_i18n( 'j M · H:i', strtotime( $g( 'pub_audio_at' ) ) ) : '' ) ) . '</span><audio controls preload="none" src="' . esc_url( $g( 'pub_audio' ) ) . '"></audio><a href="' . esc_url( $g( 'pub_audio' ) ) . '" download>' . esc_html__( 'Descargar', 'malka-crm' ) . '</a></div>';
		}

		$out .= '<div class="esc-fx__nota"><span class="esc-fx__k">' . esc_html__( 'Nota del estudio', 'malka-crm' ) . '</span><textarea class="esc-fx__ed" rows="1" name="' . $n( 'pub_nota' ) . '" placeholder="' . esc_attr__( 'Algo que el cliente deba saber al publicar (lo ve en su panel)…', 'malka-crm' ) . '" data-crece>' . esc_textarea( $g( 'pub_nota' ) ) . '</textarea></div>';
		if ( $mas ) {
			$out .= '<div class="esc-fx__mass">' . $mas . '</div>';
		}

		return $out . '</div></div></div>';
	}

	public static function fila_tarea( $i, $t = array() ) {
		$v = function ( $k, $def = '' ) use ( $t ) {
			return isset( $t[ $k ] ) ? $t[ $k ] : $def;
		};

		$numero = (string) $v( 'number' );
		$nivel  = $numero ? min( 4, Malka_CRM_Helpers::number_level( $numero ) ) : 1;
		$etapa  = ( 'stage' === $v( 'type', 'task' ) );
		$lista  = (bool) $v( 'done' );

		ob_start();
		printf(
			'<div class="esc-tarea%1$s%2$s%3$s" data-nivel="%4$d">',
			( $etapa ? ' is-etapa' : '' ) . ( '' !== trim( (string) $v( 'nota_cliente' ) ) ? ' has-nota' : '' ),
			$lista ? ' is-lista' : '',
			$v( 'privada' ) ? ' is-privada' : '',
			(int) $nivel
		);

		/*
		 * Línea 1: lo que se lee de un vistazo. Antes acá vivían las siete
		 * casillas de cada tarea a la vez, y veinte tareas eran ciento
		 * cuarenta campos en pantalla. Ahora la línea dice qué es y cómo va,
		 * y lo que se edita vive abajo, plegado.
		 */
		echo self::asa_mover(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/*
		 * Darla por lista es lo que más se hace con una tarea, así que es lo
		 * primero de la fila y no hay que abrir nada para llegar. Una etapa
		 * con tareas lo esconde (su avance sale del promedio); una etapa sola
		 * se marca a mano.
		 */
		{
			printf(
				'<label class="esc-tarea__ok" title="%1$s"><input type="checkbox" name="tarea[%2$s][done]" value="1"%3$s /><span></span></label>',
				esc_attr__( 'Darla por lista', 'malka-crm' ),
				esc_attr( $i ),
				$lista ? ' checked' : ''
			);
		}

		printf(
			'<input class="esc-tarea__num" type="text" name="tarea[%1$s][number]" value="%2$s" placeholder="1.1" data-numero />',
			esc_attr( $i ),
			esc_attr( $numero )
		);

		printf(
			'<input class="esc-tarea__n" type="text" name="tarea[%1$s][name]" value="%2$s" placeholder="%3$s" />',
			esc_attr( $i ),
			esc_attr( $v( 'name' ) ),
			esc_attr__( 'Qué hay que hacer', 'malka-crm' )
		);

		printf(
			'<span class="esc-tarea__res" data-resumen>%s</span>',
			esc_html( self::resumen_tarea( $t ) )
		);

		printf(
			'<button type="button" class="esc-tarea__ab" aria-expanded="false" aria-label="%1$s">' .
			'<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg>' .
			'</button>',
			esc_attr__( 'Ver o esconder el detalle', 'malka-crm' )
		);

		// Agregar adentro: en una etapa, una tarea; en una tarea, una subtarea.
		printf(
			'<button type="button" class="esc-tarea__sub esc-tarea__dup" data-dup title="%1$s" aria-label="%1$s"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5v-2a1 1 0 00-1-1h-6a1 1 0 00-1 1v6a1 1 0 001 1h2"/></svg></button>',
			esc_attr__( 'Duplicar (con lo que tenga adentro)', 'malka-crm' )
		);

		printf(
			'<button type="button" class="esc-tarea__sub" data-sub title="%1$s" aria-label="%1$s"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" aria-hidden="true"><path d="M8 3.5v9M3.5 8h9"/></svg></button>',
			esc_attr__( 'Agregar una tarea adentro', 'malka-crm' )
		);

		/*
		 * En el teléfono esta × se va al pie del detalle y ahí lleva rótulo:
		 * una × sola al lado del nombre se aprieta sin querer con el pulgar.
		 */
		printf(
			'<button type="button" class="esc-costo__x esc-tarea__x" aria-label="%1$s" data-rotulo="%2$s">&times;</button>',
			esc_attr__( 'Quitar esta línea', 'malka-crm' ),
			esc_attr__( 'Quitar esta línea', 'malka-crm' )
		);

		/* ── El detalle, plegado hasta que ella lo abra: una ficha que se lee y se escribe ahí mismo ── */
		echo self::ficha_detalle( $i, $t, $etapa ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/*
		 * Lo que el panel no edita pero tampoco puede perder: si no viajara
		 * en el formulario, guardar desde acá borraría las dependencias y
		 * los plazos del cliente que están puestos en WP-Admin.
		 */
		foreach ( array( 'id', 'stage', 'dependencies', 'assignee', 'due_days', 'done_at', 'done_by', 'nota_cliente', 'nota_cliente_at', 'pub_audio', 'pub_audio_at', 'resp_audio', 'resp_audio_at' ) as $k ) {
			printf(
				'<input type="hidden" name="tarea[%1$s][%2$s]" value="%3$s" />',
				esc_attr( $i ),
				esc_attr( $k ),
				esc_attr( (string) $v( $k ) )
			);
		}

		echo '</div>';

		return ob_get_clean();
	}

	/* ───────────────────────  Tareas del estudio  ───────────────────── */

	/** Donde viven las tareas que no son de ningún negocio. */
	const OPT_ESTUDIO = 'malka_crm_tareas_estudio';

	/**
	 * Cada cuánto se puede repetir una tarea.
	 *
	 * @return array clave => rótulo.
	 */
	public static function repeticiones() {
		return array(
			''          => __( 'No se repite', 'malka-crm' ),
			'diaria'    => __( 'Todos los días', 'malka-crm' ),
			'habil'     => __( 'Días hábiles (lun a vie)', 'malka-crm' ),
			'semanal'   => __( 'Cada semana', 'malka-crm' ),
			'quincenal' => __( 'Cada 2 semanas', 'malka-crm' ),
			'mensual'   => __( 'Cada mes', 'malka-crm' ),
			'trimestral' => __( 'Cada 3 meses', 'malka-crm' ),
			'anual'     => __( 'Cada año', 'malka-crm' ),
		);
	}

	/**
	 * La próxima vez que toca una tarea que se repite: la primera fecha
	 * después de hoy, contando desde la que tenía.
	 *
	 * @param string $desde  Y-m-d de la vez anterior.
	 * @param string $repite Clave de repeticiones().
	 * @return string Y-m-d.
	 */
	public static function proxima( $desde, $repite ) {
		$pasos = array(
			'diaria'     => '+1 day',
			'habil'      => '+1 day',
			'semanal'    => '+1 week',
			'quincenal'  => '+2 weeks',
			'mensual'    => '+1 month',
			'trimestral' => '+3 months',
			'anual'      => '+1 year',
		);
		$hoy = current_time( 'Y-m-d' );
		$t   = strtotime( $desde ? $desde : $hoy );
		$dia = (int) gmdate( 'j', $t );
		$n   = 0;
		do {
			$t = strtotime( $pasos[ $repite ], $t );
			// «Cada mes» el 31 no se corre: si el mes es corto queda en su último día.
			if ( in_array( $repite, array( 'mensual', 'trimestral', 'anual' ), true ) && (int) gmdate( 'j', $t ) !== $dia ) {
				$t = strtotime( gmdate( 'Y-m-01', $t ) . ' -1 day' );
			}
			while ( 'habil' === $repite && (int) gmdate( 'N', $t ) >= 6 ) {
				$t = strtotime( '+1 day', $t );
			}
			$n++;
		} while ( gmdate( 'Y-m-d', $t ) <= $hoy && $n < 2000 );
		return gmdate( 'Y-m-d', $t );
	}

	/**
	 * Marca una tarea como hecha. Si se repite, en vez de cerrarse queda
	 * pendiente para la próxima vez y se anota cuándo se hizo.
	 *
	 * @param array $t Tarea.
	 * @return array La tarea actualizada.
	 */
	public static function completar( $t ) {
		// Lista o repetida, ya no está en curso.
		$t['haciendo'] = 0;

		$repite = isset( $t['repite'] ) ? (string) $t['repite'] : '';
		if ( '' === $repite || ! array_key_exists( $repite, self::repeticiones() ) ) {
			$t['done']     = 1;
			$t['progress'] = 100;
			$t['done_at']  = current_time( 'mysql' );
			return $t;
		}
		$fin            = ! empty( $t['end'] ) ? $t['end'] : current_time( 'Y-m-d' );
		$t['end']       = self::proxima( $fin, $repite );
		$t['start']     = $t['end'];
		$t['done']      = 0;
		$t['progress']  = 0;
		$t['done_at']   = '';
		$t['ultima']    = current_time( 'mysql' );
		$t['veces']     = ( isset( $t['veces'] ) ? (int) $t['veces'] : 0 ) + 1;
		return $t;
	}

	/**
	 * Las tareas propias del estudio.
	 *
	 * No cuelgan de ningún negocio —renovar el hosting, pagarle a la
	 * contadora, actualizar el portafolio— y por eso no pueden guardarse como
	 * meta de un proyecto. Son siempre privadas: no hay cliente a quien
	 * mostrárselas.
	 *
	 * @return array[]
	 */
	public static function tareas_estudio() {
		$t = get_option( self::OPT_ESTUDIO, array() );

		return is_array( $t ) ? $t : array();
	}

	/**
	 * Guarda la lista completa de tareas del estudio.
	 *
	 * @param array[] $tareas Tareas.
	 */
	public static function guardar_estudio( $tareas ) {
		update_option( self::OPT_ESTUDIO, array_values( $tareas ), false );
	}

	/**
	 * Agrega una tarea del estudio.
	 *
	 * @param string $nombre Qué hay que hacer.
	 * @param string $fin    Fecha límite Y-m-d, o ''.
	 * @return string El id de la tarea nueva, o '' si no se pudo.
	 */
	public static function agregar_estudio( $nombre, $fin = '', $repite = '', $personal = false ) {
		$nombre = sanitize_text_field( $nombre );

		if ( '' === trim( $nombre ) ) {
			return '';
		}

		$tareas = self::tareas_estudio();
		$usados = array_flip( array_column( $tareas, 'id' ) );
		$hoy    = current_time( 'Y-m-d' );

		// No se confía en que el generador no repita: dos tareas con el mismo
		// id significan que marcar una marca la otra.
		do {
			$id = sanitize_key( 'est-' . wp_generate_password( 6, false, false ) );
		} while ( isset( $usados[ $id ] ) );

		$fin    = Malka_CRM_Helpers::sanitize_date( $fin );

		$tareas[] = array(
			'id'       => $id,
			'name'     => $nombre,
			'type'     => 'task',
			'start'    => $hoy,
			'end'      => $fin ? $fin : $hoy,
			'owner'    => 'studio',
			'progress' => 0,
			'done'     => 0,
			'done_at'  => '',
			'notes'    => '',
			'privada'  => 1,
			// Tuya, no del estudio: no cuenta como trabajo pendiente.
			'personal' => $personal ? 1 : 0,
			'repite'   => array_key_exists( $repite, self::repeticiones() ) ? $repite : '',
		);

		self::guardar_estudio( $tareas );

		return $id;
	}

	/* ──────────────────────────────  Tareas  ────────────────────────── */

	/**
	 * Todo lo pendiente, de todos los proyectos, por urgencia.
	 *
	 * @return string
	 */
	/** Dónde se guarda qué mitad de «Lo que falta» estás mirando. */
	const OPT_FALTA = 'malka_crm_falta';

	/**
	 * De quién quieres ver lo pendiente.
	 *
	 * @return string todo|client|studio.
	 */
	public static function filtro_falta( $ambito = '' ) {
		/*
		 * En lo tuyo no hay de quién filtrar: son todas tuyas. En el trabajo
		 * se elige entre lo del cliente y lo del estudio, y «todo» significa
		 * las dos cosas, nunca lo personal.
		 */
		if ( 'personal' === $ambito ) {
			return 'personal';
		}

		$validos = 'trabajo' === $ambito
			? array( 'trabajo', 'client', 'studio' )
			: array( 'todo', 'trabajo', 'client', 'studio', 'personal' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['falta'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$v = sanitize_key( wp_unslash( $_GET['falta'] ) );

			if ( in_array( $v, $validos, true ) ) {
				update_option( self::OPT_FALTA, $v, false );
				return $v;
			}
		}

		$v     = (string) get_option( self::OPT_FALTA, 'todo' );
		$suelo = 'trabajo' === $ambito ? 'trabajo' : 'todo';

		return in_array( $v, $validos, true ) ? $v : $suelo;
	}

	/**
	 * Deja sólo las del lado que estás mirando.
	 *
	 * @param array[] $tareas Pendientes.
	 * @param string  $filtro todo|client|studio.
	 * @return array[]
	 */
	public static function filtrar_falta( $tareas, $filtro ) {
		if ( 'todo' === $filtro ) {
			return $tareas;
		}

		$out = array();

		foreach ( $tareas as $t ) {
			$quien = isset( $t['quien'] ) ? $t['quien'] : 'studio';

			/*
			 * «Del trabajo» es lo del cliente y lo del estudio juntos: lo
			 * que hay que hacer por plata. Es el filtro con que entra quien
			 * viene desde Estudio, donde lo personal no pinta nada.
			 */
			$calza = 'trabajo' === $filtro
				? in_array( $quien, array( 'client', 'studio' ), true )
				: $quien === $filtro;

			if ( $calza ) {
				$out[] = $t;
			}
		}

		return $out;
	}

	/**
	 * Los tres botones del filtro, con cuántas hay en cada lado.
	 *
	 * @param string  $filtro El elegido.
	 * @param array[] $todas  Todas las pendientes, para contar.
	 * @return string
	 */
	private static function filtro_falta_html( $filtro, $todas, $ambito = '' ) {
		$cuenta = array(
			'todo'     => count( $todas ),
			'trabajo'  => 0,
			'client'   => 0,
			'studio'   => 0,
			'personal' => 0,
		);

		foreach ( $todas as $t ) {
			$q = isset( $t['quien'] ) ? $t['quien'] : 'studio';

			if ( isset( $cuenta[ $q ] ) ) {
				$cuenta[ $q ]++;
			}

			if ( 'personal' !== $q ) {
				$cuenta['trabajo']++;
			}
		}

		/*
		 * Sin el «del»: con cinco botones, «Del cliente» y «Del estudio» no
		 * cabían en la columna y el último quedaba cortado. El título de
		 * arriba ya dice «Lo que falta», así que «Cliente» se entiende.
		 */
		/*
		 * Dentro de Estudio no se ofrece «Personal»: esa pantalla es del
		 * trabajo, y «Todo» ahí quiere decir todo el trabajo.
		 */
		/*
		 * Tres y no cinco. «Trabajo» daba siempre el mismo número que «Todo»
		 * en una pantalla donde todo es trabajo, y «Personal» no tiene nada
		 * que hacer acá: lo tuyo tiene su propia pantalla.
		 */
		$rotulos = array(
			'trabajo' => __( 'Todo', 'malka-crm' ),
			'client'  => __( 'Cliente', 'malka-crm' ),
			'studio'  => __( 'Estudio', 'malka-crm' ),
		);

		ob_start();
		printf( '<div class="esc-amb esc-amb--falta" role="group" aria-label="%s">', esc_attr__( 'De quién es lo que falta', 'malka-crm' ) );

		foreach ( $rotulos as $k => $rotulo ) {
			printf(
				'<a class="esc-amb__b%1$s" href="%2$s"%3$s data-falta="%6$s">%4$s%5$s</a>',
				$filtro === $k ? ' is-on' : '',
				esc_url( add_query_arg( 'falta', $k, Malka_CRM_Dashboard::view_url( 'tareas' ) ) ),
				$filtro === $k ? ' aria-current="true"' : '',
				esc_html( $rotulo ),
				' <i data-falta-n="' . esc_attr( $k ) . '"' . ( $cuenta[ $k ] ? '' : ' hidden' ) . '>' . (int) $cuenta[ $k ] . '</i>',
				esc_attr( $k )
			);
		}

		echo '</div>';
		return ob_get_clean();
	}

	public static function render_tasks( $ambito = 'trabajo' ) {
		$ambito     = 'personal' === $ambito ? 'personal' : 'trabajo';
		$pendientes = self::pendientes();

		ob_start();
		echo '<div class="esc-cot">';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['marcadas'] ) ) {
			$n = (int) $_GET['marcadas'];
			printf(
				'<p class="esc-ok">%s</p>',
				esc_html(
					$n
						/* translators: cantidad de tareas */
						? sprintf( _n( '%d tarea actualizada.', '%d tareas actualizadas.', $n, 'malka-crm' ), $n )
						: __( 'No cambió ninguna.', 'malka-crm' )
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['agregada'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '1' === $_GET['agregada']
				? '<p class="esc-ok">' . esc_html__( 'Tarea agregada.', 'malka-crm' ) . '</p>'
				: '<p class="esc-ok esc-ok--no">' . esc_html__( 'Una tarea necesita un nombre.', 'malka-crm' ) . '</p>';
		}

		/*
		 * Las tres en paralelo y no una debajo de otra: son tres preguntas
		 * distintas —qué me deben pagar, qué entregó el cliente, qué falta
		 * hacer— y apiladas había que bajar para ver si alguna pedía algo.
		 */
		/*
		 * Pagos · Lo que falta · Lo que hizo el cliente. Al medio lo que se
		 * mira todo el día; a los lados, lo que se revisa de a ratos.
		 */
		/*
		 * En lo tuyo no hay pagos del estudio ni cliente que entregue nada:
		 * la pantalla es una sola columna con lo que te falta a ti.
		 */
		if ( 'personal' === $ambito ) {
			echo '<div class="esc-tar1">';
			echo self::bloque_falta( 'personal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div></div>';

			return ob_get_clean();
		}

		/*
		 * Lo que hizo el cliente tiene su propia pestaña: acá queda sólo lo
		 * que falta, que es a lo que se viene.
		 */
		echo '<div class="esc-tar1">';
		echo self::bloque_falta( 'trabajo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * Las tareas que el cliente ya hizo: las suyas que quedaron listas en
	 * las últimas dos semanas, para enterarse sin ir negocio por negocio.
	 *
	 * @return string
	 */
	public static function hechas_por_cliente( $dias = 14 ) {
		$desde = strtotime( current_time( 'Y-m-d' ) ) - $dias * DAY_IN_SECONDS;
		$out   = array();
		foreach ( self::all() as $p ) {
			foreach ( $p['tareas'] as $t ) {
				if ( 'stage' === $t['type'] || empty( $t['done'] ) || empty( $t['done_at'] ) ) {
					continue;
				}
				$del_cliente = ! empty( $t['done_by'] ) && 'client' === $t['done_by'];
				if ( ! $del_cliente && ! in_array( isset( $t['owner'] ) ? $t['owner'] : '', array( 'client', 'both' ), true ) ) {
					continue;
				}
				$cuando = strtotime( $t['done_at'] );
				if ( ! $cuando || $cuando < $desde ) {
					continue;
				}
				$out[] = array(
					'pid'      => $p['id'],
					'proyecto' => $p['titulo'],
					'cliente'  => $p['cliente'],
					'numero'   => isset( $t['number'] ) ? $t['number'] : '',
					'nombre'   => $t['name'],
					'cuando'   => $cuando,
					'suya'     => $del_cliente,
					'nota'     => isset( $t['nota_cliente'] ) ? (string) $t['nota_cliente'] : '',
					'clave'    => $p['id'] . '|' . ( isset( $t['id'] ) ? (string) $t['id'] : md5( $t['name'] ) ) . '|' . $cuando,
				);
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['cuando'] - $a['cuando'];
			}
		);
		return $out;
	}

	/**
	 * Marca como vista una tarea de «Lo que hizo el cliente».
	 */
	public static function ajax_hc_visto() {
		check_ajax_referer( 'malka_crm_hc_visto', 'nonce' );
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}
		$clave  = isset( $_POST['clave'] ) ? sanitize_text_field( wp_unslash( $_POST['clave'] ) ) : '';
		$vistos = get_option( 'malka_crm_hc_vistos', array() );
		$vistos = is_array( $vistos ) ? $vistos : array();
		if ( '' !== $clave ) {
			$vistos[ $clave ] = time();
		}
		// Sólo importan las de las últimas semanas: lo viejo se limpia solo.
		$tope = time() - 30 * DAY_IN_SECONDS;
		foreach ( $vistos as $k => $v ) {
			if ( (int) $v < $tope ) {
				unset( $vistos[ $k ] );
			}
		}
		update_option( 'malka_crm_hc_vistos', $vistos, false );
		wp_send_json_success();
	}

	/**
	 * El panel «Lo que hizo el cliente» de Tareas.
	 *
	 * @return string
	 */
	/**
	 * La columna «Lo que falta», entera.
	 *
	 * Aparte para poder pedirla sola: al cambiar de filtro se cambia esta
	 * columna y nada más. Recargar la página entera por un filtro perdía
	 * el scroll, volvía a dibujar las otras dos columnas y se notaba.
	 *
	 * @param string|null $filtro todo|client|studio|personal. Null: el guardado.
	 * @return string
	 */
	public static function bloque_falta( $ambito = '' ) {
		$ambito     = in_array( $ambito, array( 'trabajo', 'personal' ), true ) ? $ambito : '';
		$filtro     = self::filtro_falta( $ambito );
		$pendientes = self::pendientes();
		$todas      = $pendientes;
		$pendientes = self::filtrar_falta( $pendientes, $filtro );

		// En lo tuyo, los botones de «de quién es» sobran: son todas tuyas.
		$chips = 'personal' !== $ambito;

		ob_start();
		echo '<section class="esc-panel esc-falta" data-falta-col>';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Lo que falta', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Lo que vence antes, arriba', 'malka-crm' ) . '</p></div></div>';
		if ( $chips ) {
			echo self::filtro_falta_html( $filtro, $todas, $ambito ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/*
		 * El mensaje habla del lado que se está mirando: «el estudio no
		 * tiene nada» cuando mirabas lo personal era, sencillamente, otra
		 * cosa.
		 */
		$vacios = array(
			'todo'     => __( 'No queda nada pendiente. Agrega una con el + de arriba si hay algo que no quieres olvidar.', 'malka-crm' ),
			'trabajo'  => __( 'No queda trabajo pendiente, ni tuyo ni del cliente.', 'malka-crm' ),
			'client'   => __( 'El cliente no tiene nada pendiente.', 'malka-crm' ),
			'studio'   => __( 'El estudio no tiene nada pendiente.', 'malka-crm' ),
			'personal' => __( 'No tienes nada tuyo pendiente.', 'malka-crm' ),
		);

		/*
		 * El mensaje va siempre en el html, oculto si hay pendientes: cuando
		 * ella archiva la última tarea sin recargar, el panel tiene que
		 * poder decir que no queda nada en vez de quedarse en blanco.
		 */
		printf(
			'<p class="esc-empty" data-falta-vacio%1$s>%2$s</p>',
			$pendientes ? ' hidden' : '',
			esc_html( isset( $vacios[ $filtro ] ) ? $vacios[ $filtro ] : $vacios['todo'] )
		);

		if ( ! $pendientes ) {
			echo '</section>';

			return ob_get_clean();
		}

		printf(
			'<form method="post" action="%1$s" class="esc-tareas" data-ajax="%2$s" data-nonce="%3$s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'malka_crm_inicio' ) )
		);
		echo '<input type="hidden" name="action" value="malka_crm_save_tasks" />';
		wp_nonce_field( 'malka_crm_marcar_tareas' );

		/*
		 * Los negocios salen en orden de fecha, pero lo que no cuelga de un
		 * negocio se junta al final, cada lado en un bloque. Sin esto la
		 * fecha los intercalaba y «Del estudio» aparecía dos veces, con
		 * «Personal» en medio.
		 */
		$conneg   = array();
		$estudio  = array();
		$personal = array();

		foreach ( $pendientes as $t ) {
			if ( $t['pid'] ) {
				$conneg[] = $t;
			} elseif ( 'personal' === $t['quien'] ) {
				$personal[] = $t;
			} else {
				$estudio[] = $t;
			}
		}

		$pendientes = array_merge( $conneg, $estudio, $personal );

		$grupo = null;

		foreach ( $pendientes as $t ) {
			$suyo = $t['pid'] ? $t['proyecto'] : '__' . $t['quien'] . '__';

			if ( $suyo !== $grupo ) {
				$grupo = $suyo;

				if ( $t['pid'] ) {
					printf(
						'<a class="esc-tareas__p" href="%1$s">%2$s<i>%3$s</i></a>',
						esc_url( self::edit_url( $t['pid'] ) ),
						esc_html( $t['proyecto'] ),
						esc_html( $t['cliente'] )
					);
				} elseif ( 'personal' === $t['quien'] ) {
					printf(
						'<span class="esc-tareas__p esc-tareas__p--est">%1$s<i>%2$s</i></span>',
						esc_html__( 'Personal', 'malka-crm' ),
						esc_html__( 'Tuyas, no del estudio', 'malka-crm' )
					);
				} else {
					printf(
						'<span class="esc-tareas__p esc-tareas__p--est">%1$s<i>%2$s</i></span>',
						esc_html__( 'Del estudio', 'malka-crm' ),
						esc_html__( 'Sin negocio · nadie más las ve', 'malka-crm' )
					);
				}
			}

			echo self::fila_pendiente( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/*
		 * Marcar guarda al tiro: una lista para ir tachando no puede pedir
		 * un botón después de cada palito. La tarea se queda en pantalla,
		 * tachada, para poder desmarcarla si fue sin querer; desaparece
		 * recién la próxima vez que entres.
		 *
		 * El botón sigue existiendo para quien tenga el javascript apagado,
		 * y el propio javascript lo esconde al arrancar: sin esto la lista
		 * no se podría marcar en absoluto en ese caso.
		 */
		echo '<div class="esc-cfg__save esc-ed__save" data-marcar-pie hidden>';
		printf(
			'<span class="esc-ed__meta">%s</span>',
			esc_html__( 'Marca las que hiciste y guarda. Lo que no está en pantalla no se toca.', 'malka-crm' )
		);
		printf(
			'<button type="submit" class="esc-btn esc-btn--primary">%s</button>',
			esc_html__( 'Guardar lo marcado', 'malka-crm' )
		);
		echo '</div>';

		/*
		 * Sin javascript el pie vuelve, y con él el aire que necesita: la
		 * barra queda pegada abajo del panel y, sin ese margen, se come la
		 * última tarea de la lista.
		 */
		echo '<noscript><style>[data-marcar-pie]{display:flex !important}'
			. '.esc-falta .esc-pend:last-of-type{margin-bottom:2.2rem}</style></noscript>';

		echo '</form></section>';

		return ob_get_clean();
	}

	/**
	 * Lo que hizo el cliente, como pantalla propia.
	 *
	 * Al lado de las tareas competía por la atención con lo que falta, que
	 * es lo que de verdad hay que hacer. Acá se entra a propósito: a ver
	 * qué entregó el cliente y darlo por visto.
	 *
	 * @return string
	 */
	public static function render_hizo() {
		ob_start();
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total">';
		echo self::bloque_hizo_cliente(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		return ob_get_clean();
	}

	private static function bloque_hizo_cliente() {
		// Lo que ella ya marcó como visto no vuelve a salir.
		$vistos = get_option( 'malka_crm_hc_vistos', array() );
		$vistos = is_array( $vistos ) ? $vistos : array();
		$filas  = array_values(
			array_filter(
				self::hechas_por_cliente(),
				function ( $f ) use ( $vistos ) {
					return ! isset( $vistos[ $f['clave'] ] );
				}
			)
		);
		if ( ! $filas ) {
			return '';
		}
		$hoy = strtotime( current_time( 'Y-m-d' ) );
		ob_start();
		printf( '<section class="esc-panel esc-hc" data-hc data-ajax="%1$s" data-nonce="%2$s">', esc_url( admin_url( 'admin-ajax.php' ) ), esc_attr( wp_create_nonce( 'malka_crm_hc_visto' ) ) );
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Lo que hizo el cliente', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sus tareas que quedaron listas en las últimas dos semanas', 'malka-crm' ) . '</p></div></div>';
		echo '<div class="esc-hc__l">';
		foreach ( $filas as $f ) {
			$d = (int) floor( ( $hoy - strtotime( gmdate( 'Y-m-d', $f['cuando'] ) ) ) / DAY_IN_SECONDS );
			if ( 0 >= $d ) {
				$cuando = sprintf( /* translators: hora */ __( 'hoy, %s', 'malka-crm' ), gmdate( 'H:i', $f['cuando'] ) );
			} elseif ( 1 === $d ) {
				$cuando = __( 'ayer', 'malka-crm' );
			} else {
				$cuando = sprintf( /* translators: días */ __( 'hace %d días', 'malka-crm' ), $d );
			}
			/*
			 * La fila llevaba al negocio entero. Ahora abre la tarea, que
			 * es de lo que habla: desde ahí se corrige el nombre, la fecha
			 * o a quién le toca, sin perder de vista la lista.
			 */
			printf(
				'<div class="esc-hc__w" data-pid="%10$d" data-tid="%11$s" data-abrir-tarea>' .
				'<div class="esc-hc__r" role="button" tabindex="0"><span class="esc-hc__ck" aria-hidden="true"><svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.4l2.9 2.9 6.1-6.6"/></svg></span>' .
				'<span class="esc-hc__t"><span class="esc-hc__pre">%2$s</span><b>%3$s%4$s</b><small>%5$s</small>%7$s</span><span class="esc-hc__f">%6$s</span></div>' .
				// El title decía «Visto», igual que el botón: un globito que
				// repetía la palabra. Ahora dice qué pasa al apretarlo.
				'<button type="button" class="esc-hc__visto" data-hc-visto="%8$s" title="%12$s">%9$s</button></div>',
				'',
				esc_html( $f['suya'] ? __( 'La marcó el cliente', 'malka-crm' ) : __( 'Lista', 'malka-crm' ) ),
				$f['numero'] ? '<i>' . esc_html( $f['numero'] ) . '</i> ' : '',
				esc_html( $f['nombre'] ),
				esc_html( trim( $f['proyecto'] . ( $f['cliente'] ? ' · ' . $f['cliente'] : '' ) ) ),
				esc_html( $cuando ),
				'' !== trim( $f['nota'] ) ? '<span class="esc-hc__nota">«' . esc_html( $f['nota'] ) . '»</span>' : '',
				esc_attr( $f['clave'] ),
				esc_html__( 'Visto', 'malka-crm' ),
				(int) $f['pid'],
				// La clave es «pid|tid|cuándo»: el del medio es la tarea.
				esc_attr( (string) ( explode( '|', $f['clave'] )[1] ?? '' ) ),
				esc_attr__( 'Ya lo revisaste: sácalo de esta lista', 'malka-crm' )
			);
		}
		echo '</div></section>';
		return ob_get_clean();
	}

	/**
	 * Los pagos del estudio de este mes: costos fijos e impuestos.
	 *
	 * Los mismos que salen en «Tu día», pero el mes entero: los que faltan
	 * con su casilla y los ya pagados tachados, para ver de un vistazo qué
	 * queda. Marcar uno lo anota como salida en Movimientos.
	 *
	 * @return string
	 */
	/**
	 * Los pagos del estudio, como pantalla propia.
	 *
	 * El mismo bloque que sale dentro de Tareas, pero con su entrada en el
	 * menú de Estudio: lo que hay que pagar este mes es cosa del estudio,
	 * no una tarea más de la lista.
	 *
	 * @return string
	 */
	public static function render_pagos() {
		ob_start();
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total">';

		$bloque = self::bloque_pagos();

		if ( $bloque ) {
			echo $bloque; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<section class="esc-panel">';
			echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'peso',
				__( 'Pagos del estudio', 'malka-crm' ),
				__( 'Los costos fijos del mes y los impuestos', 'malka-crm' )
			);
			printf(
				'<p class="esc-empty">%s</p>',
				esc_html__( 'Este mes no hay nada que pagar anotado. Los costos fijos con día de cobro aparecen acá solos; se anotan en Finanzas → Deudas, en «Lo que se paga solo».', 'malka-crm' )
			);
			echo '</section>';
		}

		echo '</div>';

		return ob_get_clean();
	}

	private static function bloque_pagos() {
		if ( ! class_exists( 'Malka_CRM_Pagos' ) ) {
			return '';
		}

		$mes   = current_time( 'Y-m' );
		$pagos = Malka_CRM_Pagos::del_mes( $mes );

		if ( ! $pagos ) {
			return '';
		}

		$hoy     = current_time( 'Y-m-d' );
		$desde   = (string) get_option( 'malka_crm_pagos_desde', $hoy );
		// El total y lo pagado salen del mismo resumen que los tres montones.
		$resumen = Malka_CRM_Pagos::resumen( $mes );
		$grupos  = array(
			'atrasado'  => array(),
			'pendiente' => array(),
			'pagado'    => array(),
		);

		foreach ( $pagos as $p ) {
			if ( $p['pagado'] ) {
				$estado = '<span class="esc-st esc-st--done">' . esc_html__( 'Pagado', 'malka-crm' ) . '</span>';
				$monto  = Malka_CRM_Settings::money( $p['pagado_monto'] );
			} else {
				$dias          = (int) floor( ( strtotime( $p['fecha'] ) - strtotime( $hoy ) ) / DAY_IN_SECONDS );
				list( $c, $t ) = Malka_CRM_Pagos::cuando( $dias );
				// Lo que venció antes de empezar a usar esto no está atrasado:
				// probablemente se pagó y no se marcó.
				if ( $p['fecha'] < $desde ) {
					$c = __( 'Sin marcar', 'malka-crm' );
					$t = 'off';
				}
				$estado = '<span class="esc-st esc-st--' . esc_attr( $t ) . '">' . esc_html( $c ) . '</span>';
				if ( $p['monto'] > 0 ) {
					$monto = ( $p['variable'] ? '≈ ' : '' ) . Malka_CRM_Settings::money( $p['monto'] );
				} elseif ( 'CLP' !== $p['moneda'] && $p['original'] ) {
					$monto = $p['moneda'] . ' ' . number_format_i18n( (float) $p['original'], 2 );
				} else {
					$monto = '—';
				}
			}

			$det = array( date_i18n( 'j M', strtotime( $p['fecha'] ) ) );
			if ( 'CLP' !== $p['moneda'] && $p['original'] ) {
				$det[] = $p['moneda'] . ' ' . number_format_i18n( (float) $p['original'], 2 );
			}
			$det[] = 'impuesto' === $p['tipo'] ? __( 'SII', 'malka-crm' ) : ( $p['variable'] ? __( 'varía', 'malka-crm' ) : __( 'fijo', 'malka-crm' ) );

			$fila_h = sprintf(
				'<div class="esc-dia-w esc-pm%1$s" data-pago="%15$s" data-tipo-pago="%16$s"><button type="button" class="esc-dia__chk%2$s" data-tipo="pago" data-mes="%3$s" data-clave="%4$s" data-monto="%5$d" data-fecha="%14$s"%6$s%7$s aria-label="%8$s" title="%8$s"></button>' .
				'<div class="esc-pm__c"><span class="esc-pm__t"><b class="esc-dia__n">%9$s</b><span>%10$s%13$s</span></span>%11$s<b class="esc-dia__r esc-pm__m">%12$s</b></div></div>',
				$p['pagado'] ? ' is-lista' : '',
				$p['pagado'] ? ' is-ok' : '',
				esc_attr( $mes ),
				esc_attr( $p['clave'] ),
				(int) $p['monto'],
				$p['variable'] ? ' data-variable="1"' : '',
				$p['pagado'] ? ' disabled' : '',
				esc_attr( $p['pagado'] ? __( 'Ya está pagado', 'malka-crm' ) : __( 'Marcar como pagado', 'malka-crm' ) ),
				esc_html( $p['nombre'] ),
				esc_html( implode( ' · ', $det ) ),
				$estado,
				esc_html( $monto ),
				class_exists( 'Malka_CRM_Documents' ) ? implode( '', array_map( array( 'Malka_CRM_Documents', 'chip' ), Malka_CRM_Documents::de_pago( $mes . '|' . $p['clave'] ) ) ) : '',
				// El día que vencía: es la fecha que se propone al marcarlo.
				esc_attr( min( $p['fecha'], $hoy ) ),
				esc_attr( $p['clave'] ),
				esc_attr( $p['tipo'] )
			);
			$grupos[ Malka_CRM_Pagos::estado_de( $p, $hoy, $desde ) ][] = $fila_h;
		}

		/*
		 * Tres montones y no uno: lo atrasado arriba, porque es lo único que
		 * cuesta plata dejar para mañana; lo que falta pagar en el medio; y
		 * lo ya pagado abajo, a la vista y no escondido tras un desplegable
		 * —ella quiere ver el mes completo, no sólo lo que le queda—.
		 */
		$rotulos = array(
			'atrasado'  => array( 'late', __( 'Atrasados', 'malka-crm' ) ),
			'pendiente' => array( 'wait', __( 'Por pagar', 'malka-crm' ) ),
			'pagado'    => array( 'done', __( 'Pagados', 'malka-crm' ) ),
		);

		$filas = '';
		foreach ( $rotulos as $estado => $r ) {
			list( $tono, $rotulo ) = $r;
			$n = count( $grupos[ $estado ] );

			$filas .= sprintf(
				'<div class="esc-pms__g esc-pms__g--%1$s" data-pms-g="%2$s"%3$s>' .
				'<div class="esc-pms__gh"><h3>%4$s<i data-pms-n>%5$s</i></h3><b data-pms-m>%6$s</b></div>' .
				'<div class="esc-pms__l">%7$s</div></div>',
				esc_attr( $tono ),
				esc_attr( $estado ),
				$n ? '' : ' hidden',
				esc_html( $rotulo ),
				(int) $n,
				esc_html( $resumen[ $estado ]['txt'] ),
				implode( '', $grupos[ $estado ] )
			);
		}

		$sin_deber = ! $grupos['atrasado'] && ! $grupos['pendiente'];
		$filas    .= '<p class="esc-pms__vacio" data-pms-vacio' . ( $sin_deber ? '' : ' hidden' ) . '>' .
			esc_html__( 'Todo pagado este mes.', 'malka-crm' ) . '</p>';

		return sprintf(
			'<section class="esc-panel esc-pms" data-nonce="%1$s" data-ajax="%7$s"><div class="esc-ph"><div><h2>%2$s</h2><p>%3$s</p></div><div class="esc-ph__r"><em>%4$s</em><span>%5$s</span></div></div>%6$s</section>',
			esc_attr( wp_create_nonce( 'malka_crm_inicio' ) ),
			esc_html( sprintf( /* translators: mes */ __( 'Pagos de %s', 'malka-crm' ), date_i18n( 'F', strtotime( $mes . '-01' ) ) ) ),
			esc_html__( 'Costos fijos e impuestos del mes. Márcalos al pagar y quedan anotados en Movimientos', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $resumen['pagado']['monto'] ) ),
			esc_html( sprintf( /* translators: total */ __( 'pagado de %s', 'malka-crm' ), Malka_CRM_Settings::money( $resumen['total'] ) ) ),
			$filas,
			esc_url( admin_url( 'admin-ajax.php' ) )
		);
	}

	/**
	 * Una tarea pendiente, con su casilla.
	 *
	 * @param array $t Tarea.
	 * @return string
	 */
	/**
	 * El formulario de tarea nueva, como ventana.
	 *
	 * Antes era un cuadro fijo arriba de Tareas: ocupaba media pantalla
	 * para algo que se usa unos segundos, y sólo existía ahí. Ahora cuelga
	 * del + del encabezado, que se ve desde cualquier sección.
	 *
	 * @return string
	 */
	public static function modal_tarea() {
		$negocios = self::all();

		ob_start();
		echo '<div class="esc-modal" id="esc-modal-tarea"><div class="esc-modal__c esc-nuevo-t">';
		echo '<h3 class="esc-modal__h">' . esc_html__( 'Nueva tarea', 'malka-crm' ) . '</h3>';
		echo '<p class="esc-modal__p">' . esc_html__( 'Para un negocio, o del estudio si no es de ninguno', 'malka-crm' ) . '</p>';

		printf( '<form class="esc-nueva" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="desde" value="%s" />', esc_attr( Malka_CRM_Dashboard::current_view() ) );
		wp_nonce_field( 'malka_crm_agregar_tarea', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_agregar_tarea" />';

		printf(
			'<label class="esc-nueva__q"><span>%1$s</span>' .
			'<input type="text" name="nombre" placeholder="%2$s" required /></label>',
			esc_html__( 'Qué hay que hacer', 'malka-crm' ),
			esc_attr__( 'Renovar el hosting, mandar la boleta…', 'malka-crm' )
		);

		printf( '<label class="esc-nueva__c"><span>%1$s</span><select name="negocio" data-tarea-negocio>', esc_html__( 'Negocio', 'malka-crm' ) );
		/*
		 * Dos maneras de no tener negocio: del estudio y personal. Van acá
		 * y no en una casilla aparte porque es la misma pregunta —de quién
		 * es esto— y partirla en dos controles obliga a contestarla dos
		 * veces.
		 */
		printf( '<option value="0">%s</option>', esc_html__( 'Ninguno — del estudio', 'malka-crm' ) );
		printf( '<option value="personal">%s</option>', esc_html__( 'Ninguno — personal', 'malka-crm' ) );

		foreach ( $negocios as $n ) {
			printf(
				'<option value="%1$d">%2$s</option>',
				(int) $n['id'],
				esc_html( trim( $n['titulo'] . ( $n['cliente'] ? ' · ' . $n['cliente'] : '' ) ) )
			);
		}

		echo '</select></label>';

		printf(
			'<label class="esc-nueva__c"><span>%1$s</span><input type="date" name="fin" /></label>',
			esc_html__( 'Para cuándo', 'malka-crm' )
		);

		printf( '<label class="esc-nueva__c"><span>%s</span><select name="repite">', esc_html__( 'Se repite', 'malka-crm' ) );
		foreach ( self::repeticiones() as $k => $r ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $r ) );
		}
		echo '</select></label>';

		/*
		 * Sin negocio no hay cliente que pueda verla, así que la casilla va
		 * marcada y bloqueada: ofrecer una opción que no cambia nada confunde.
		 */
		printf(
			'<label class="esc-chk esc-nueva__p" title="%3$s">' .
			'<input type="checkbox" name="privada" value="1" checked data-tarea-privada /> %1$s%2$s</label>',
			esc_html__( 'Sólo para el estudio', 'malka-crm' ),
			'<i data-tarea-nota>' . esc_html__( 'una tarea sin negocio nunca la ve el cliente', 'malka-crm' ) . '</i>',
			esc_attr__( 'No aparece en el avance que ve el cliente.', 'malka-crm' )
		);

		echo '<div class="esc-modal__f esc-nueva__pie">';
		printf( '<button type="button" class="esc-btn" data-cerrar-modal>%s</button>', esc_html__( 'Cancelar', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Agregar', 'malka-crm' ) );
		echo '</div>';
		echo '</form>';
		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * La ventana de detalle de una tarea.
	 *
	 * Va vacía: el javascript la llena al abrirla con lo que devuelve
	 * ajax_detalle(). Una sola ventana para todas las filas, en vez de un
	 * formulario escondido por tarea.
	 *
	 * @return string
	 */
	public static function modal_detalle() {
		$negocios = self::all();

		ob_start();
		echo '<div class="esc-modal" id="esc-modal-tarea-det"><div class="esc-modal__c esc-nuevo-t">';
		echo '<h3 class="esc-modal__h">' . esc_html__( 'La tarea', 'malka-crm' ) . '</h3>';

		echo '<form class="esc-nueva" data-det-form>';
		echo '<input type="hidden" data-det-pid value="" /><input type="hidden" data-det-tid value="" />';

		printf(
			'<label class="esc-nueva__q"><span>%1$s</span>' .
			'<input type="text" data-det-nombre required /></label>',
			esc_html__( 'Qué hay que hacer', 'malka-crm' )
		);

		printf( '<label class="esc-nueva__c"><span>%1$s</span><select data-det-negocio>', esc_html__( 'Negocio', 'malka-crm' ) );
		printf( '<option value="0">%s</option>', esc_html__( 'Ninguno — del estudio', 'malka-crm' ) );
		printf( '<option value="personal">%s</option>', esc_html__( 'Ninguno — personal', 'malka-crm' ) );

		foreach ( $negocios as $n ) {
			printf(
				'<option value="%1$d">%2$s</option>',
				(int) $n['id'],
				esc_html( trim( $n['titulo'] . ( $n['cliente'] ? ' · ' . $n['cliente'] : '' ) ) )
			);
		}

		echo '</select></label>';

		printf(
			'<label class="esc-nueva__c"><span>%1$s</span><input type="date" data-det-fin /></label>',
			esc_html__( 'Para cuándo', 'malka-crm' )
		);

		printf( '<label class="esc-nueva__c"><span>%s</span><select data-det-repite>', esc_html__( 'Se repite', 'malka-crm' ) );
		foreach ( self::repeticiones() as $k => $r ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $r ) );
		}
		echo '</select></label>';

		// Sólo tiene sentido con un negocio detrás; el javascript lo esconde.
		printf(
			'<label class="esc-nueva__c" data-det-solo-negocio><span>%s</span><select data-det-owner>' .
			'<option value="studio">%s</option><option value="client">%s</option><option value="both">%s</option>' .
			'</select></label>',
			esc_html__( 'De quién es', 'malka-crm' ),
			esc_html__( 'Del estudio', 'malka-crm' ),
			esc_html__( 'Del cliente', 'malka-crm' ),
			esc_html__( 'De los dos', 'malka-crm' )
		);

		printf(
			'<label class="esc-nueva__q"><span>%1$s</span>' .
			'<textarea data-det-notas rows="3" placeholder="%2$s"></textarea></label>',
			esc_html__( 'Notas', 'malka-crm' ),
			esc_attr__( 'Lo que haya que recordar de esta tarea', 'malka-crm' )
		);

		printf(
			'<label class="esc-chk esc-nueva__p" data-det-solo-negocio>' .
			'<input type="checkbox" data-det-privada /> %s</label>',
			esc_html__( 'Sólo para el estudio', 'malka-crm' )
		);

		printf(
			'<label class="esc-chk esc-nueva__p" title="%2$s"><input type="checkbox" data-det-importante /> %1$s</label>',
			esc_html__( 'Es importante', 'malka-crm' ),
			esc_attr__( 'Lo urgente sale de la fecha; lo importante lo dices tú.', 'malka-crm' )
		);

		printf(
			'<label class="esc-chk esc-nueva__p"><input type="checkbox" data-det-haciendo /> %s</label>',
			esc_html__( 'La estoy haciendo', 'malka-crm' )
		);

		printf(
			'<label class="esc-chk esc-nueva__p"><input type="checkbox" data-det-hecha /> %s</label>',
			esc_html__( 'Ya está hecha', 'malka-crm' )
		);

		echo '<p class="esc-hint is-alert" data-det-error hidden></p>';

		echo '<div class="esc-modal__f esc-nueva__pie">';
		printf(
			'<a class="esc-btn esc-btn--borrar" data-det-borrar href="#">%s</a>',
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
	 * La ventana de un costo del mes.
	 *
	 * Vacía: el javascript la llena al abrirla. Los impuestos no se editan
	 * acá —los arma el sistema con los documentos— y la ventana lo dice en
	 * vez de ofrecer campos que no guardarían nada.
	 *
	 * @return string
	 */
	public static function modal_pago() {
		ob_start();
		echo '<div class="esc-modal" id="esc-modal-pago"><div class="esc-modal__c esc-nuevo-t">';
		echo '<h3 class="esc-modal__h">' . esc_html__( 'El costo', 'malka-crm' ) . '</h3>';

		// Lo que se muestra cuando el costo no es de ella: un impuesto.
		echo '<div data-pago-fijo hidden>';
		echo '<p class="esc-modal__p">' . esc_html__( 'Este no es un costo que hayas escrito tú: el monto lo arma el sistema con tus documentos del mes, así que no hay nada que editar acá. Igual puedes marcarlo pagado desde la lista.', 'malka-crm' ) . '</p>';
		printf(
			'<div class="esc-modal__f"><a class="esc-btn" data-pago-ir href="#">%1$s</a>' .
			'<button type="button" class="esc-btn esc-btn--primary" data-cerrar-modal>%2$s</button></div>',
			esc_html__( 'Ver en Contabilidad', 'malka-crm' ),
			esc_html__( 'Cerrar', 'malka-crm' )
		);
		echo '</div>';

		echo '<form class="esc-nueva" data-pago-form hidden>';
		echo '<input type="hidden" data-pago-clave value="" />';

		printf(
			'<label class="esc-nueva__q"><span>%1$s</span><input type="text" data-pago-nombre required /></label>',
			esc_html__( 'Qué se paga', 'malka-crm' )
		);

		printf(
			'<label class="esc-nueva__c"><span>%1$s</span><input type="text" inputmode="decimal" data-pago-monto /></label>',
			esc_html__( 'Cuánto', 'malka-crm' )
		);

		printf( '<label class="esc-nueva__c"><span>%s</span><select data-pago-moneda>', esc_html__( 'En qué moneda', 'malka-crm' ) );
		foreach ( Malka_CRM_Settings::currencies() as $k => $m ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $m ) );
		}
		echo '</select></label>';

		printf(
			'<label class="esc-nueva__c"><span>%1$s</span>' .
			'<input type="number" min="0" max="31" step="1" data-pago-dia placeholder="%2$s" /></label>',
			esc_html__( 'Qué día del mes vence', 'malka-crm' ),
			esc_attr__( 'sin día fijo', 'malka-crm' )
		);

		printf( '<label class="esc-nueva__c"><span>%s</span><select data-pago-cuenta>', esc_html__( 'De qué cuenta sale', 'malka-crm' ) );
		printf( '<option value="0">%s</option>', esc_html__( 'La principal del estudio', 'malka-crm' ) );

		if ( class_exists( 'Malka_CRM_Finance' ) ) {
			foreach ( Malka_CRM_Finance::cuentas() as $c ) {
				printf(
					'<option value="%1$d">%2$s</option>',
					(int) $c['id'],
					esc_html( Malka_CRM_Finance::nombre_cuenta( (int) $c['id'] ) )
				);
			}
		}

		echo '</select></label>';

		printf(
			'<label class="esc-chk esc-nueva__p"><input type="checkbox" data-pago-variable /> %1$s<i>%2$s</i></label>',
			esc_html__( 'El monto cambia cada mes', 'malka-crm' ),
			esc_html__( 'al marcarlo pagado te pregunta cuánto fue', 'malka-crm' )
		);

		echo '<p class="esc-hint is-alert" data-pago-error hidden></p>';

		echo '<div class="esc-modal__f esc-nueva__pie">';
		printf( '<button type="button" class="esc-btn esc-btn--borrar" data-pago-borrar>%s</button>', esc_html__( 'Borrar', 'malka-crm' ) );
		printf( '<button type="button" class="esc-btn" data-cerrar-modal>%s</button>', esc_html__( 'Cancelar', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Guardar', 'malka-crm' ) );
		echo '</div>';
		echo '</form>';
		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * El selector de repetición de una tarea en la lista: se guarda solo.
	 *
	 * @param array $t Tarea pendiente.
	 * @return string
	 */
	private static function select_repite( $t ) {
		$actual = isset( $t['repite'] ) ? (string) $t['repite'] : '';
		$out    = sprintf(
			'<span class="esc-pend__r%1$s"><svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a5 5 0 0 1 8.6-3.4L13 5M13 2v3h-3M13 9a5 5 0 0 1-8.6 3.4L3 11M3 14v-3h3"/></svg><select data-repite data-pid="%2$d" data-tid="%3$s" aria-label="%4$s">',
			'' === $actual ? '' : ' is-on',
			(int) $t['pid'],
			esc_attr( $t['id'] ),
			esc_attr__( 'Se repite', 'malka-crm' )
		);
		foreach ( self::repeticiones() as $k => $r ) {
			$out .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $k ), selected( $actual, $k, false ), esc_html( '' === $k ? __( 'Una vez', 'malka-crm' ) : $r ) );
		}
		return $out . '</select></span>';
	}

	/**
	 * Anota una tarea desde el modo enfoque: al negocio en que trabaja, o
	 * al estudio si es trabajo propio.
	 */
	public static function ajax_rapida() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$pid    = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === trim( $nombre ) ) {
			wp_send_json_error( array( 'msg' => __( 'Escribe qué hay que hacer.', 'malka-crm' ) ) );
		}
		if ( $pid && self::POST_TYPE === get_post_type( $pid ) ) {
			$tareas   = Malka_CRM_Helpers::get_project_tasks( $pid );
			$hoy      = current_time( 'Y-m-d' );
			$id       = sanitize_key( 'tar-' . wp_generate_password( 6, false, false ) );
			$tareas[] = array(
				'id'       => $id,
				'name'     => $nombre,
				'type'     => 'task',
				'stage'    => '',
				'start'    => $hoy,
				'end'      => $hoy,
				'progress' => 0,
				'owner'    => 'studio',
				'done'     => 0,
				'done_at'  => '',
				'notes'    => '',
				'number'   => '',
				'privada'  => 1,
			);
			Malka_CRM_Helpers::save_tasks( $pid, $tareas );
			wp_send_json_success( array( 'id' => $id, 'pid' => $pid, 'donde' => get_the_title( $pid ) ) );
		}
		$id = self::agregar_estudio( $nombre, current_time( 'Y-m-d' ) );
		wp_send_json_success( array( 'id' => $id, 'pid' => 0, 'donde' => __( 'Del estudio', 'malka-crm' ) ) );
	}

	/**
	 * Asigna una tarea a otro negocio (o al estudio): la saca de donde
	 * estaba y la pone al final del otro, con todo lo que tenía.
	 */
	/**
	 * A quién escribirle por un negocio: correo, nombre y el enlace que
	 * le sirve (su portal si lo tiene abierto, si no la cotización).
	 *
	 * @param int $pid Negocio.
	 * @return array {email, nombre, enlace}
	 */
	public static function contacto_cliente( $pid ) {
		$out = array( 'email' => '', 'nombre' => '', 'enlace' => '' );
		$qid = class_exists( 'Malka_CRM_Quotes' ) ? (int) Malka_CRM_Quotes::get_project_quote_id( $pid ) : 0;
		$cid = (int) get_post_meta( $pid, '_malka_pm_client_id', true );
		if ( ( ! $cid || 'malka_client' !== get_post_type( $cid ) ) && $qid ) {
			$cid = (int) get_post_meta( $qid, '_malka_pm_client_id', true );
		}
		if ( $cid && 'malka_client' === get_post_type( $cid ) && class_exists( 'Malka_CRM_Clients' ) ) {
			$c             = Malka_CRM_Clients::get( $cid );
			$out['email']  = $c['email'];
			$out['nombre'] = $c['nombre'] ? $c['nombre'] : $c['empresa'];
			if ( class_exists( 'Malka_CRM_Portal' ) && Malka_CRM_Portal::activo( $cid ) ) {
				$out['enlace'] = Malka_CRM_Portal::url( $cid ) . '#tareas';
			}
		}
		if ( ! is_email( $out['email'] ) && $qid ) {
			$out['email']  = (string) get_post_meta( $qid, '_malka_pm_client_email', true );
			$out['nombre'] = $out['nombre'] ? $out['nombre'] : (string) get_post_meta( $qid, '_malka_pm_client_name', true );
		}
		if ( ! is_email( $out['email'] ) && $cid ) {
			$u = get_userdata( $cid );
			if ( $u ) {
				$out['email']  = $u->user_email;
				$out['nombre'] = $out['nombre'] ? $out['nombre'] : $u->display_name;
			}
		}
		if ( ! $out['enlace'] && $qid && class_exists( 'Malka_CRM_Quotes' ) ) {
			$out['enlace'] = Malka_CRM_Quotes::get_url( $qid );
		}
		$out['nombre'] = trim( strtok( (string) $out['nombre'], ' ' ) ) ? $out['nombre'] : '';
		return $out;
	}

	/**
	 * Los enlaces y archivos que el cliente ve en su portal, en este negocio.
	 *
	 * @param int $pid Negocio.
	 * @return array[] id, titulo, url, archivo
	 */
	public static function enlaces( $pid ) {
		$v = get_post_meta( (int) $pid, '_malka_pm_enlaces', true );
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $e ) {
			if ( ! is_array( $e ) || empty( $e['id'] ) ) {
				continue;
			}
			$url = '';
			if ( ! empty( $e['archivo'] ) ) {
				$url = (string) wp_get_attachment_url( (int) $e['archivo'] );
			} elseif ( ! empty( $e['url'] ) ) {
				$url = (string) $e['url'];
			}
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'id'      => (string) $e['id'],
				'titulo'  => (string) $e['titulo'],
				'url'     => $url,
				'archivo' => (int) ( isset( $e['archivo'] ) ? $e['archivo'] : 0 ),
			);
		}
		return $out;
	}

	/**
	 * El panel «Enlaces y archivos para el cliente» de la ficha.
	 *
	 * @param array $p Negocio.
	 * @return string
	 */
	private static function bloque_enlaces( $p ) {
		$pid = (int) $p['id'];
		$en  = self::enlaces( $pid );
		ob_start();
		printf( '<section class="esc-panel esc-enl" id="esc-enl" data-pid="%1$d" data-nonce="%2$s">', $pid, esc_attr( wp_create_nonce( 'malka_crm_enlace_' . $pid ) ) );
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Enlaces y archivos para el cliente', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Aparecen en su portal, dentro de este proyecto: un informe, una carpeta, un archivo para descargar', 'malka-crm' ) . '</p></div></div>';
		echo '<div class="esc-enl__l">';
		foreach ( $en as $e ) {
			printf(
				'<div class="esc-enl__i" data-id="%1$s"><span class="esc-enl__ic" aria-hidden="true">%2$s</span><a class="esc-enl__t" href="%3$s" target="_blank" rel="noopener"><b>%4$s</b><small>%5$s</small></a>' .
				'<button type="button" class="esc-enl__x" data-enl-borrar title="%6$s" aria-label="%6$s"><svg class="esc-x-ic" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></button></div>',
				esc_attr( $e['id'] ),
				$e['archivo']
					? '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2.5v8M4.5 7 8 10.5 11.5 7M3 13.5h10"/></svg>'
					: '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 9.5l3-3M7 4.5l1.2-1.2a2.5 2.5 0 013.5 3.5L10.5 8M9 11.5l-1.2 1.2a2.5 2.5 0 01-3.5-3.5L5.5 8"/></svg>',
				esc_url( $e['url'] ),
				esc_html( $e['titulo'] ),
				esc_html( $e['archivo'] ? __( 'Archivo para descargar', 'malka-crm' ) : preg_replace( '#^https?://#', '', $e['url'] ) ),
				esc_attr__( 'Quitar', 'malka-crm' )
			);
		}
		if ( ! $en ) {
			echo '<p class="esc-empty esc-enl__vacio">' . esc_html__( 'Todavía no hay nada. Agrega un enlace o sube un archivo.', 'malka-crm' ) . '</p>';
		}
		echo '</div>';
		printf(
			'<div class="esc-enl__f"><input type="text" class="esc-enl__in" data-enl-titulo placeholder="%1$s" />' .
			'<input type="url" class="esc-enl__in" data-enl-url placeholder="%2$s" />' .
			'<label class="esc-btn esc-btn--soft esc-enl__up"><input type="file" data-enl-archivo hidden />%3$s</label>' .
			'<button type="button" class="esc-btn esc-btn--primary" data-enl-agregar>+ %4$s</button></div>',
			esc_attr__( 'Nombre: Ver informe, Informe RRSS…', 'malka-crm' ),
			esc_attr__( 'https://… (o sube un archivo)', 'malka-crm' ),
			esc_html__( 'Subir archivo', 'malka-crm' ),
			esc_html__( 'Agregar', 'malka-crm' )
		);
		echo '</section>';
		return ob_get_clean();
	}

	/**
	 * Agregar o quitar un enlace o archivo del negocio.
	 */
	public static function ajax_enlace() {
		$pid = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) || ! $pid || 'malka_project' !== get_post_type( $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}
		check_ajax_referer( 'malka_crm_enlace_' . $pid, 'nonce' );
		$lista = get_post_meta( $pid, '_malka_pm_enlaces', true );
		$lista = is_array( $lista ) ? $lista : array();
		$op    = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( 'borrar' === $op ) {
			$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
			$lista = array_values(
				array_filter(
					$lista,
					function ( $e ) use ( $id ) {
						return ! isset( $e['id'] ) || $e['id'] !== $id;
					}
				)
			);
			update_post_meta( $pid, '_malka_pm_enlaces', $lista );
			wp_send_json_success();
		}

		$titulo  = isset( $_POST['titulo'] ) ? sanitize_text_field( wp_unslash( $_POST['titulo'] ) ) : '';
		$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$archivo = 0;
		if ( ! empty( $_FILES['archivo']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$archivo = media_handle_upload( 'archivo', $pid );
			if ( is_wp_error( $archivo ) ) {
				wp_send_json_error( array( 'message' => $archivo->get_error_message() ), 400 );
			}
			if ( '' === $titulo ) {
				$titulo = get_the_title( $archivo );
			}
		}
		if ( ! $archivo && '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Pega un enlace o sube un archivo.', 'malka-crm' ) ), 400 );
		}
		$lista[] = array(
			'id'      => 'e' . substr( md5( uniqid( '', true ) ), 0, 8 ),
			'titulo'  => '' !== $titulo ? $titulo : ( $archivo ? __( 'Archivo', 'malka-crm' ) : __( 'Ver enlace', 'malka-crm' ) ),
			'url'     => $archivo ? '' : $url,
			'archivo' => (int) $archivo,
		);
		update_post_meta( $pid, '_malka_pm_enlaces', $lista );
		wp_send_json_success();
	}

	/**
	 * Le recuerda al cliente una tarea suya (por vencer o atrasada).
	 *
	 * @param int    $pid   Negocio.
	 * @param array  $task  Tarea.
	 * @param string $clave tarea_por_vencer|tarea_atrasada (vacío: según la fecha).
	 * @return bool Si salió.
	 */
	public static function recordar_tarea( $pid, $task, $clave = '' ) {
		if ( ! class_exists( 'Malka_CRM_Messages' ) ) {
			return false;
		}
		$c = self::contacto_cliente( $pid );
		if ( ! is_email( $c['email'] ) ) {
			return false;
		}
		$fin  = Malka_CRM_Helpers::get_task_deadline( $task );
		$dias = $fin ? (int) floor( ( strtotime( $fin ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : 0;
		if ( '' === $clave ) {
			$clave = ( $fin && $dias < 0 ) ? 'tarea_atrasada' : 'tarea_por_vencer';
		}
		return Malka_CRM_Messages::enviar(
			$c['email'],
			$clave,
			array(
				'cliente'  => strtok( (string) $c['nombre'], ' ' ),
				'tarea'    => trim( (string) $task['name'] ),
				'proyecto' => get_the_title( $pid ),
				'fecha'    => $fin ? date_i18n( 'j \d\e F', strtotime( $fin ) ) : '',
				'faltan'   => $fin ? ( $dias <= 0 ? __( 'es hoy', 'malka-crm' ) : sprintf( /* translators: días */ _n( 'queda %d día', 'quedan %d días', $dias, 'malka-crm' ), $dias ) ) : '',
				'atraso'   => $dias < 0 ? Malka_CRM_Messages::dias( $dias ) : '',
				'enlace'   => $c['enlace'],
				'estudio'  => Malka_CRM_Messages::estudio(),
			)
		);
	}

	/**
	 * Las tareas pendientes a cargo de un cliente, en todos sus negocios.
	 *
	 * @param int[] $pids Negocios del cliente.
	 * @return array[] pid, negocio, id, nombre, fin, dias, url
	 */
	public static function tareas_de_cliente( $pids ) {
		$out = array();
		foreach ( array_unique( array_map( 'intval', (array) $pids ) ) as $pid ) {
			if ( ! $pid || 'malka_project' !== get_post_type( $pid ) || 'trash' === get_post_status( $pid ) ) {
				continue;
			}
			$tareas = get_post_meta( $pid, '_malka_pm_tasks', true );
			foreach ( (array) $tareas as $t ) {
				if ( ! is_array( $t ) || ( isset( $t['type'] ) && 'stage' === $t['type'] ) || ! empty( $t['done'] ) ) {
					continue;
				}
				if ( 'client' !== ( isset( $t['owner'] ) ? $t['owner'] : '' ) || ( isset( $t['progress'] ) && 100 === (int) $t['progress'] ) ) {
					continue;
				}
				$fin   = Malka_CRM_Helpers::get_task_deadline( $t + array( 'start' => '', 'end' => '', 'due_days' => 0 ) );
				$out[] = array(
					'pid'     => $pid,
					'negocio' => get_the_title( $pid ),
					'id'      => isset( $t['id'] ) ? (string) $t['id'] : '',
					'nombre'  => isset( $t['name'] ) ? (string) $t['name'] : '',
					'numero'  => isset( $t['number'] ) ? (string) $t['number'] : '',
					'fin'     => $fin,
					'dias'    => $fin ? (int) floor( ( strtotime( $fin ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : null,
					'url'     => self::edit_url( $pid ),
				);
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['fin'] ? $a['fin'] : '9999', $b['fin'] ? $b['fin'] : '9999' );
			}
		);
		return $out;
	}

	/**
	 * «Recordar» una tarea desde la ficha del cliente.
	 */
	public static function ajax_recordar() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}
		check_ajax_referer( 'malka_crm_recordar_tarea', 'nonce' );
		$pid = isset( $_POST['pid'] ) ? (int) $_POST['pid'] : 0;
		$tid = isset( $_POST['tid'] ) ? sanitize_text_field( wp_unslash( $_POST['tid'] ) ) : '';
		foreach ( (array) get_post_meta( $pid, '_malka_pm_tasks', true ) as $t ) {
			if ( is_array( $t ) && isset( $t['id'] ) && (string) $t['id'] === $tid ) {
				if ( self::recordar_tarea( $pid, $t ) ) {
					wp_send_json_success( array( 'message' => __( 'Recordatorio enviado', 'malka-crm' ) ) );
				}
				wp_send_json_error( array( 'message' => __( 'No salió: revisa que el cliente tenga correo.', 'malka-crm' ) ), 400 );
			}
		}
		wp_send_json_error( array( 'message' => __( 'No encontré esa tarea.', 'malka-crm' ) ), 404 );
	}

	/**
	 * Le escribe al cliente con las tareas que se le acaban de asignar.
	 *
	 * @param int     $pid    Negocio.
	 * @param array[] $tareas Tareas nuevas a su cargo.
	 */
	public static function avisar_tareas_cliente( $pid, $tareas ) {
		if ( ! class_exists( 'Malka_CRM_Messages' ) || 'malka_project' !== get_post_type( $pid ) ) {
			return;
		}
		$c = self::contacto_cliente( $pid );
		if ( ! is_email( $c['email'] ) ) {
			return;
		}
		$lineas = array();
		foreach ( $tareas as $t ) {
			$fin      = ! empty( $t['end'] ) ? $t['end'] : '';
			$lineas[] = '- ' . trim( (string) $t['name'] ) . ( $fin ? ' · ' . sprintf( /* translators: fecha */ __( 'para el %s', 'malka-crm' ), date_i18n( 'j \d\e F', strtotime( $fin ) ) ) : '' );
		}
		Malka_CRM_Messages::enviar(
			$c['email'],
			'tarea_asignada',
			array(
				'cliente'  => strtok( (string) $c['nombre'], ' ' ),
				'proyecto' => get_the_title( $pid ),
				'tareas'   => implode( "\n", $lineas ),
				'enlace'   => $c['enlace'],
				'estudio'  => Malka_CRM_Messages::estudio(),
			)
		);
	}

	/**
	 * Marca (o desmarca) un negocio como «sin factura», con su nota.
	 */
	public static function ajax_omitir_factura() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}
		check_ajax_referer( 'malka_crm_omitir_factura', 'nonce' );
		$pid = isset( $_POST['pid'] ) ? (int) $_POST['pid'] : 0;
		if ( ! $pid || 'malka_project' !== get_post_type( $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'No encontré ese negocio.', 'malka-crm' ) ), 404 );
		}
		if ( ! empty( $_POST['deshacer'] ) ) {
			delete_post_meta( $pid, '_malka_pm_sin_factura' );
			wp_send_json_success();
		}
		$nota = isset( $_POST['nota'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nota'] ) ) : '';
		if ( '' === trim( $nota ) ) {
			wp_send_json_error( array( 'message' => __( 'Escribe por qué no se factura.', 'malka-crm' ) ), 400 );
		}
		update_post_meta(
			$pid,
			'_malka_pm_sin_factura',
			array(
				'nota'  => $nota,
				'fecha' => current_time( 'mysql' ),
			)
		);
		wp_send_json_success();
	}

	public static function ajax_mover() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$de  = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$a   = isset( $_POST['a'] ) ? absint( $_POST['a'] ) : 0;
		$tid = isset( $_POST['tid'] ) ? sanitize_key( wp_unslash( $_POST['tid'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $de === $a || '' === $tid || ( $a && self::POST_TYPE !== get_post_type( $a ) ) ) {
			wp_send_json_success( array( 'pid' => $de ) );
		}
		$origen = $de ? Malka_CRM_Helpers::get_project_tasks( $de ) : self::tareas_estudio();
		$tarea  = null;
		foreach ( $origen as $i => $t ) {
			if ( isset( $t['id'] ) && (string) $t['id'] === $tid ) {
				$tarea = $t;
				unset( $origen[ $i ] );
				break;
			}
		}
		if ( ! $tarea ) {
			wp_send_json_error( array(), 404 );
		}
		$origen = array_values( $origen );
		if ( $de ) {
			Malka_CRM_Helpers::save_tasks( $de, $origen );
		} else {
			self::guardar_estudio( $origen );
		}
		$tarea['privada'] = $a ? ( isset( $tarea['privada'] ) ? $tarea['privada'] : 1 ) : 1;
		$tarea['stage']   = '';
		$tarea['type']    = 'task';
		if ( $a ) {
			$destino   = Malka_CRM_Helpers::get_project_tasks( $a );
			$destino[] = $tarea;
			Malka_CRM_Helpers::save_tasks( $a, $destino );
		} else {
			$destino   = self::tareas_estudio();
			$destino[] = $tarea;
			self::guardar_estudio( $destino );
		}
		wp_send_json_success( array( 'pid' => $a ) );
	}

	/**
	 * Cambia cada cuánto se repite una tarea (desde la lista).
	 */
	/**
	 * Marca o desmarca UNA tarea, sin recargar.
	 *
	 * El formulario entero sigue existiendo para quien no tenga javascript,
	 * pero lo normal es esto: tocas la casilla y queda guardado. Devuelve
	 * cuántas quedan de cada lado para que los contadores del filtro no
	 * mientan hasta la próxima carga.
	 */
	public static function ajax_marcar() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$pid   = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$tid   = isset( $_POST['tid'] ) ? sanitize_key( wp_unslash( $_POST['tid'] ) ) : '';
		$listo = ! empty( $_POST['listo'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $tid ) {
			wp_send_json_error( array(), 400 );
		}

		$tareas = $pid ? Malka_CRM_Helpers::get_project_tasks( $pid ) : self::tareas_estudio();
		$halle  = false;

		foreach ( $tareas as $i => $t ) {
			if ( ! isset( $t['id'] ) || (string) $t['id'] !== $tid ) {
				continue;
			}

			if ( $listo ) {
				$tareas[ $i ] = self::completar( $t );
			} else {
				$tareas[ $i ]['done']     = 0;
				$tareas[ $i ]['progress'] = 0;
				$tareas[ $i ]['done_at']  = '';
			}

			/*
			 * Una que se repite no se termina: salta a su próxima fecha y
			 * sigue pendiente. La pantalla tiene que decir cuál es esa
			 * fecha, no un «vuelve cada mes» que obliga a calcularla.
			 */
			$repite = ! empty( $tareas[ $i ]['repite'] );
			$fin    = isset( $tareas[ $i ]['end'] ) ? (string) $tareas[ $i ]['end'] : '';

			$halle = true;
			break;
		}

		if ( ! $halle ) {
			wp_send_json_error( array(), 404 );
		}

		if ( $pid ) {
			Malka_CRM_Helpers::save_tasks( $pid, $tareas );
		} else {
			self::guardar_estudio( $tareas );
		}

		wp_send_json_success(
			array(
				'listo'  => $listo,
				'repite' => ! empty( $repite ),
				'vuelve' => ! empty( $repite ) && $fin
					? sprintf( /* translators: fecha */ __( 'Hecha · vuelve el %s', 'malka-crm' ), date_i18n( 'j M', strtotime( $fin ) ) )
					: '',
				'cuenta' => self::cuenta_falta(),
			)
		);
	}

	/**
	 * Cuántas tareas quedan pendientes de cada lado.
	 *
	 * @return array
	 */
	public static function cuenta_falta() {
		$cuenta = array(
			'todo'     => 0,
			'trabajo'  => 0,
			'client'   => 0,
			'studio'   => 0,
			'personal' => 0,
		);

		foreach ( self::pendientes() as $t ) {
			$q = isset( $t['quien'] ) ? $t['quien'] : 'studio';
			$cuenta['todo']++;

			if ( isset( $cuenta[ $q ] ) ) {
				$cuenta[ $q ]++;
			}

			if ( 'personal' !== $q ) {
				$cuenta['trabajo']++;
			}
		}

		return $cuenta;
	}

	/**
	 * Dónde vive una tarea y cuál es, de un solo golpe.
	 *
	 * @param int    $pid Negocio, o 0 si no cuelga de ninguno.
	 * @param string $tid Identificador de la tarea.
	 * @return array {int $i, array $tareas} o array() si no está.
	 */
	private static function ubicar( $pid, $tid ) {
		$tareas = $pid ? Malka_CRM_Helpers::get_project_tasks( $pid ) : self::tareas_estudio();

		foreach ( $tareas as $i => $t ) {
			if ( isset( $t['id'] ) && (string) $t['id'] === $tid ) {
				return array(
					'i'      => $i,
					'tareas' => $tareas,
				);
			}
		}

		return array();
	}

	/**
	 * Devuelve una tarea entera para llenar la ventana de detalle.
	 *
	 * Se pide al abrir y no se escribe en cada fila: con cuarenta tareas en
	 * pantalla, meter los datos de todas en el html pesa y casi nunca se
	 * abre más de una.
	 */
	public static function ajax_detalle() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$pid = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$tid = isset( $_POST['tid'] ) ? sanitize_key( wp_unslash( $_POST['tid'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$donde = self::ubicar( $pid, $tid );

		if ( ! $donde ) {
			wp_send_json_error( array(), 404 );
		}

		$t = $donde['tareas'][ $donde['i'] ];

		wp_send_json_success(
			array(
				'pid'      => $pid,
				'tid'      => $tid,
				'nombre'   => isset( $t['name'] ) ? (string) $t['name'] : '',
				'fin'      => isset( $t['end'] ) ? (string) $t['end'] : '',
				'repite'   => isset( $t['repite'] ) ? (string) $t['repite'] : '',
				'owner'    => isset( $t['owner'] ) ? (string) $t['owner'] : 'studio',
				'privada'  => ! empty( $t['privada'] ),
				'personal' => ! empty( $t['personal'] ),
				'notas'    => isset( $t['notes'] ) ? (string) $t['notes'] : '',
				'importante' => ! empty( $t['importante'] ),
				'haciendo'   => ! empty( $t['haciendo'] ) && empty( $t['done'] ),
				'hecha'    => ! empty( $t['done'] ),
				/* translators: fecha en que el cliente la dio por hecha */
				'nota_cliente' => isset( $t['nota_cliente'] ) ? (string) $t['nota_cliente'] : '',
				'borrar'   => wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'malka_crm_borrar_tarea',
							'pid'    => $pid,
							'tid'    => $tid,
						),
						admin_url( 'admin-post.php' )
					),
					'malka_crm_borrar_tarea_' . $tid
				),
			)
		);
	}

	/**
	 * Guarda lo que se editó en la ventana de detalle.
	 *
	 * Incluye cambiarla de negocio —o sacarla a «del estudio» o a
	 * «personal»—, que es mudarla de una lista a otra. Cuando eso pasa la
	 * fila tiene que cambiar de grupo, así que se avisa al navegador que
	 * recargue en vez de intentar moverla a mano.
	 */
	public static function ajax_editar() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$pid    = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$tid    = isset( $_POST['tid'] ) ? sanitize_key( wp_unslash( $_POST['tid'] ) ) : '';
		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$fin    = isset( $_POST['fin'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fin'] ) ) : '';
		$repite = isset( $_POST['repite'] ) ? sanitize_key( wp_unslash( $_POST['repite'] ) ) : '';
		$owner  = isset( $_POST['owner'] ) ? sanitize_key( wp_unslash( $_POST['owner'] ) ) : 'studio';
		$notas  = isset( $_POST['notas'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notas'] ) ) : '';
		$cual   = isset( $_POST['negocio'] ) ? sanitize_key( wp_unslash( $_POST['negocio'] ) ) : (string) $pid;
		$priv   = ! empty( $_POST['privada'] );
		$hecha  = ! empty( $_POST['hecha'] );
		$imp    = ! empty( $_POST['importante'] );
		$hacien = ! empty( $_POST['haciendo'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === trim( $nombre ) ) {
			wp_send_json_error( array( 'message' => __( 'La tarea necesita un nombre.', 'malka-crm' ) ), 400 );
		}

		$donde = self::ubicar( $pid, $tid );

		if ( ! $donde ) {
			wp_send_json_error( array(), 404 );
		}

		$personal = 'personal' === $cual;
		$destino  = $personal ? 0 : absint( $cual );

		if ( $destino && self::POST_TYPE !== get_post_type( $destino ) ) {
			wp_send_json_error( array(), 400 );
		}

		$tareas = $donde['tareas'];
		$t      = $tareas[ $donde['i'] ];

		$t['name']   = $nombre;
		$t['end']    = $fin ? $fin : ( isset( $t['end'] ) ? $t['end'] : current_time( 'Y-m-d' ) );
		$t['repite'] = array_key_exists( $repite, self::repeticiones() ) ? $repite : '';
		$t['notes']  = $notas;

		// Sin negocio no hay cliente: ni dueño ni «la ve el cliente» aplican.
		$t['owner']    = $destino ? ( in_array( $owner, array( 'client', 'studio', 'both' ), true ) ? $owner : 'studio' ) : 'studio';
		$t['privada']  = $destino ? ( $priv ? 1 : 0 ) : 1;
		$t['personal'] = ( ! $destino && $personal ) ? 1 : 0;

		/*
		 * Dos datos nuevos, para poder mirar lo mismo de otras maneras:
		 *
		 * «importante» lo dices tú, no se deduce. Lo urgente sale de la
		 * fecha; lo importante no sale de ninguna parte más que de tu
		 * criterio, y por eso es una marca y no un cálculo.
		 *
		 * «haciendo» es el paso del medio que faltaba: una tarea estaba
		 * hecha o no, y eso no alcanza para un tablero. Una tarea hecha ya
		 * no está haciéndose, así que las dos no conviven.
		 */
		$t['importante'] = $imp ? 1 : 0;
		$t['haciendo']   = ( $hacien && ! $hecha ) ? 1 : 0;

		if ( $hecha && empty( $t['done'] ) ) {
			$t = self::completar( $t );
		} elseif ( ! $hecha && ! empty( $t['done'] ) ) {
			$t['done']     = 0;
			$t['progress'] = 0;
			$t['done_at']  = '';
		}

		$movida = $destino !== $pid;

		if ( ! $movida ) {
			$tareas[ $donde['i'] ] = $t;

			if ( $pid ) {
				Malka_CRM_Helpers::save_tasks( $pid, $tareas );
			} else {
				self::guardar_estudio( $tareas );
			}
		} else {
			// Sale de donde estaba…
			unset( $tareas[ $donde['i'] ] );
			$tareas = array_values( $tareas );

			if ( $pid ) {
				Malka_CRM_Helpers::save_tasks( $pid, $tareas );
			} else {
				self::guardar_estudio( $tareas );
			}

			// …y entra donde va, al final.
			$t['stage'] = '';
			$t['type']  = 'task';

			if ( $destino ) {
				$lista   = Malka_CRM_Helpers::get_project_tasks( $destino );
				$lista[] = $t;
				Malka_CRM_Helpers::save_tasks( $destino, $lista );
			} else {
				$lista   = self::tareas_estudio();
				$lista[] = $t;
				self::guardar_estudio( $lista );
			}
		}

		wp_send_json_success(
			array(
				'movida' => $movida,
				'cuenta' => self::cuenta_falta(),
			)
		);
	}

	/**
	 * Busca un costo fijo por la clave con que sale en la lista de pagos.
	 *
	 * @param string $clave Clave «costo-loquesea».
	 * @return int|false Índice dentro de los costos guardados.
	 */
	private static function costo_por_clave( $clave ) {
		foreach ( (array) Malka_CRM_Settings::get( 'costos', array() ) as $i => $c ) {
			if ( ! is_array( $c ) || empty( $c['nombre'] ) ) {
				continue;
			}

			if ( 'costo-' . sanitize_title( $c['nombre'] ) === $clave ) {
				return $i;
			}
		}

		return false;
	}

	/**
	 * Un costo del mes, para llenar su ventana.
	 */
	public static function ajax_pago_detalle() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$clave = isset( $_POST['clave'] ) ? sanitize_key( wp_unslash( $_POST['clave'] ) ) : '';
		$i     = self::costo_por_clave( $clave );

		if ( false === $i ) {
			/*
			 * El F29 y el F22 no son costos que ella escribió: los arma el
			 * sistema con lo que dicen sus documentos. No hay nada que
			 * editar acá, y decirlo es más útil que un formulario que no
			 * guardaría nada.
			 */
			wp_send_json_success(
				array(
					'editable' => false,
					'clave'    => $clave,
					'donde'    => Malka_CRM_Dashboard::view_url( 'contabilidad' ),
				)
			);
		}

		$costos = (array) Malka_CRM_Settings::get( 'costos', array() );
		$c      = $costos[ $i ];

		wp_send_json_success(
			array(
				'editable' => true,
				'clave'    => $clave,
				'nombre'   => (string) $c['nombre'],
				'monto'    => isset( $c['monto'] ) ? (string) $c['monto'] : '0',
				'moneda'   => isset( $c['moneda'] ) ? (string) $c['moneda'] : 'CLP',
				'dia'      => isset( $c['dia'] ) ? (int) $c['dia'] : 0,
				'variable' => ! empty( $c['variable'] ),
				'cuenta'   => isset( $c['cuenta'] ) ? (int) $c['cuenta'] : 0,
			)
		);
	}

	/**
	 * Guarda —o borra— un costo fijo desde su ventana.
	 */
	public static function ajax_pago_editar() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$clave  = isset( $_POST['clave'] ) ? sanitize_key( wp_unslash( $_POST['clave'] ) ) : '';
		$borrar = ! empty( $_POST['borrar'] );
		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$monto  = isset( $_POST['monto'] ) ? (float) str_replace( ',', '.', wp_unslash( $_POST['monto'] ) ) : 0;
		$moneda = isset( $_POST['moneda'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['moneda'] ) ) ) : 'CLP';
		$dia    = isset( $_POST['dia'] ) ? (int) $_POST['dia'] : 0;
		$varia  = ! empty( $_POST['variable'] );
		$cuenta = isset( $_POST['cuenta'] ) ? absint( $_POST['cuenta'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$i = self::costo_por_clave( $clave );

		if ( false === $i ) {
			wp_send_json_error( array(), 404 );
		}

		$costos = (array) Malka_CRM_Settings::get( 'costos', array() );

		if ( $borrar ) {
			unset( $costos[ $i ] );
			Malka_CRM_Settings::update( array( 'costos' => array_values( $costos ) ) );
			wp_send_json_success( array( 'borrado' => true ) );
		}

		if ( '' === trim( $nombre ) ) {
			wp_send_json_error( array( 'message' => __( 'El costo necesita un nombre.', 'malka-crm' ) ), 400 );
		}

		$monedas = Malka_CRM_Settings::currencies();
		$moneda  = isset( $monedas[ $moneda ] ) ? $moneda : 'CLP';
		$monto   = max( 0, $monto );

		$costos[ $i ] = array(
			'nombre'   => $nombre,
			'monto'    => 'CLP' === $moneda ? (int) round( $monto ) : round( $monto, 2 ),
			'moneda'   => $moneda,
			'dia'      => ( $dia >= 1 && $dia <= 31 ) ? $dia : 0,
			'variable' => $varia ? 1 : 0,
			'cuenta'   => $cuenta,
		);

		Malka_CRM_Settings::update( array( 'costos' => array_values( $costos ) ) );

		/*
		 * La clave de un costo sale de su nombre, así que al renombrarlo la
		 * marca de «pagado» de este mes apuntaba a una clave que ya no
		 * existe: el pago desaparecía y el costo volvía a aparecer impago.
		 */
		$nueva = 'costo-' . sanitize_title( $nombre );

		if ( $nueva !== $clave && class_exists( 'Malka_CRM_Pagos' ) ) {
			Malka_CRM_Pagos::renombrar( $clave, $nueva );
		}

		wp_send_json_success( array( 'clave' => $nueva ) );
	}

	/**
	 * Devuelve la columna «Lo que falta» con el filtro que se pidió.
	 *
	 * Cambiar de filtro recargaba la página entera: se perdía el scroll,
	 * volvían a dibujarse las otras dos columnas y se notaba el parpadeo.
	 * Acá se cambia esa columna y nada más.
	 */
	public static function ajax_falta() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$filtro = isset( $_POST['falta'] ) ? sanitize_key( wp_unslash( $_POST['falta'] ) ) : 'todo';

		if ( ! in_array( $filtro, array( 'todo', 'trabajo', 'client', 'studio', 'personal' ), true ) ) {
			$filtro = 'todo';
		}

		// Queda elegido para la próxima vez que entre, como si hubiera navegado.
		update_option( self::OPT_FALTA, $filtro, false );

		wp_send_json_success(
			array(
				'filtro' => $filtro,
				'html'   => self::bloque_falta( $filtro ),
			)
		);
	}

	public static function ajax_repite() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$pid    = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$tid    = isset( $_POST['tid'] ) ? sanitize_key( wp_unslash( $_POST['tid'] ) ) : '';
		$repite = isset( $_POST['repite'] ) ? sanitize_key( wp_unslash( $_POST['repite'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$repite = array_key_exists( $repite, self::repeticiones() ) ? $repite : '';

		$tareas = $pid ? Malka_CRM_Helpers::get_project_tasks( $pid ) : self::tareas_estudio();
		foreach ( $tareas as $i => $t ) {
			if ( isset( $t['id'] ) && (string) $t['id'] === $tid ) {
				$tareas[ $i ]['repite'] = $repite;
				if ( $pid ) {
					Malka_CRM_Helpers::save_tasks( $pid, $tareas );
				} else {
					self::guardar_estudio( $tareas );
				}
				wp_send_json_success( array( 'repite' => $repite ) );
			}
		}
		wp_send_json_error( array(), 404 );
	}

	private static function fila_pendiente( $t ) {
		$quien = self::responsables();
		$tarde = ( null !== $t['dias'] && $t['dias'] < 0 );

		if ( null === $t['dias'] ) {
			$cuando = '—';
		} elseif ( $t['dias'] < 0 ) {
			/* translators: días */
			$cuando = sprintf( _n( 'hace %d día', 'hace %d días', abs( $t['dias'] ), 'malka-crm' ), abs( $t['dias'] ) );
		} elseif ( 0 === $t['dias'] ) {
			$cuando = __( 'es hoy', 'malka-crm' );
		} else {
			/* translators: días */
			$cuando = sprintf( _n( 'en %d día', 'en %d días', $t['dias'], 'malka-crm' ), $t['dias'] );
		}

		// Subtarea (2.9.3) o tarea (2.9): la tarea va en negrita.
		$es_sub = $t['numero'] && count( explode( '.', trim( (string) $t['numero'], '.' ) ) ) >= 3;
		$nombre = preg_replace( '/[\s·\-–—|,:]+$/u', '', (string) $t['nombre'] );
		$borrar = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'malka_crm_borrar_tarea',
					'tarea'  => $t['id'],
				),
				admin_url( 'admin-post.php' )
			),
			'malka_crm_borrar_tarea_' . $t['id']
		);

		ob_start();
		printf(
			/*
			 * La fila era un <label>: tocarla en cualquier parte marcaba la
			 * tarea. Ahora tocarla abre su detalle, y marcar es sólo la
			 * casilla —que lleva su propio <label> para no perder el área
			 * de toque ni el nombre que lee un lector de pantalla—.
			 */
			'<div class="esc-pend%1$s" data-pid="%12$d" data-tid="%2$s" data-quien="%13$s" data-abrir>' .
			'<label class="esc-pend__ok" title="%14$s">' .
			'<input type="checkbox" name="listo[]" value="%2$s" />' .
			'<span class="screen-reader-text">%14$s</span></label>' .
			'<input type="hidden" name="vista[]" value="%2$s" />' .
			'<span class="esc-pend__n">%3$s%4$s%10$s</span>' .
			'<span class="esc-pend__q">%5$s%8$s</span>' .
			'%9$s' .
			'<span class="esc-pend__d%6$s">%7$s</span>%11$s</div>',
			( 'client' === $t['quien'] ? ' is-cliente' : '' ) . ( ! empty( $t['privada'] ) ? ' is-privada' : '' ) . ( $es_sub ? ' is-sub' : ' is-madre' ),
			esc_attr( $t['id'] ),
			$t['numero'] ? '<i>' . esc_html( $t['numero'] ) . '</i>' : '',
			'<b>' . esc_html( $nombre ) . '</b>',
			esc_html( isset( $quien[ $t['quien'] ] ) ? $quien[ $t['quien'] ] : '' ),
			$tarde ? ' is-alert' : '',
			esc_html( $cuando ),
			/*
			 * El chip avisa que el cliente no la ve. En una tarea tuya no
			 * dice nada —no hay cliente— y además decía «sólo estudio» de
			 * algo que justamente no es del estudio.
			 */
			empty( $t['privada'] ) || 'personal' === $t['quien']
				? ''
				: ' <i class="esc-pend__priv">' . esc_html__( 'sólo estudio', 'malka-crm' ) . '</i>',
			self::select_repite( $t ),
			! empty( $t['nota'] )
				? '<small class="esc-pend__nc" title="' . esc_attr__( 'Nota del cliente', 'malka-crm' ) . '"><svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3.5h10v7H7l-3 2.5v-2.5H3z"/></svg>' . esc_html( $t['nota'] ) . '</small>'
				: '',
			sprintf(
				'<a class="esc-pend__x" href="%1$s" data-confirmar="%2$s" title="%3$s" aria-label="%3$s"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></a>',
				esc_url( $borrar ),
				esc_attr( sprintf( /* translators: tarea */ __( '¿Borrar «%s»? Si tiene subtareas, se borran con ella.', 'malka-crm' ), $nombre ) ),
				esc_attr__( 'Borrar esta tarea', 'malka-crm' )
			),
			(int) ( isset( $t['pid'] ) ? $t['pid'] : 0 ),
			esc_attr( $t['quien'] ),
			esc_attr(
				sprintf(
					/* translators: nombre de la tarea */
					__( 'Marcar «%s» como hecha', 'malka-crm' ),
					$nombre
				)
			)
		);

		return ob_get_clean();
	}

	/**
	 * Un campo del formulario.
	 *
	 * @param string $nombre   Name.
	 * @param string $etiqueta Rótulo.
	 * @param string $valor    Valor.
	 * @param string $tipo     text|date.
	 * @return string
	 */
	private static function campo( $nombre, $etiqueta, $valor, $tipo = 'text' ) {
		return sprintf(
			'<p class="esc-f"><label class="esc-f__l" for="f-%1$s">%2$s</label>' .
			'<span class="esc-f__w"><input class="esc-f__i" type="%3$s" id="f-%1$s" name="%1$s" value="%4$s" /></span></p>',
			esc_attr( $nombre ),
			esc_html( $etiqueta ),
			esc_attr( $tipo ),
			esc_attr( (string) $valor )
		);
	}

	/* ──────────────────────────  Guardado  ──────────────────────────── */

	/**
	 * Guarda la ficha del negocio y su árbol.
	 */
	public static function handle_save() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_editor_project' );

		$id = isset( $_POST['negocio'] ) ? (int) $_POST['negocio'] : 0;

		$titulo = isset( $_POST['titulo'] ) ? sanitize_text_field( wp_unslash( $_POST['titulo'] ) ) : '';
		$titulo = '' !== trim( $titulo ) ? $titulo : __( 'Negocio sin nombre', 'malka-crm' );

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
				wp_die( esc_html__( 'No se pudo crear el negocio.', 'malka-crm' ) );
			}
		}

		update_post_meta( $id, '_malka_pm_number', isset( $_POST['numero'] ) ? sanitize_text_field( wp_unslash( $_POST['numero'] ) ) : '' );
		update_post_meta( $id, '_malka_pm_client_name', isset( $_POST['cliente'] ) ? sanitize_text_field( wp_unslash( $_POST['cliente'] ) ) : '' );
		update_post_meta( $id, '_malka_pm_start', isset( $_POST['inicio'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['inicio'] ) ) : '' );
		update_post_meta( $id, '_malka_pm_deadline', isset( $_POST['fin'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fin'] ) ) : '' );

		$estado  = isset( $_POST['estado'] ) ? sanitize_key( wp_unslash( $_POST['estado'] ) ) : 'briefing';
		$estados = Malka_CRM_Helpers::project_statuses();
		update_post_meta( $id, '_malka_pm_status', isset( $estados[ $estado ] ) ? $estado : 'briefing' );

		self::guardar_arbol( $id );

		// Asociar una cotización elegida en «Cotización y facturas».
		$vincular = isset( $_POST['quote_vincular'] ) ? absint( $_POST['quote_vincular'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $vincular && Malka_CRM_Quotes::POST_TYPE === get_post_type( $vincular ) ) {
			update_post_meta( $vincular, '_malka_pm_project_id', (int) $id );
			update_post_meta( $id, '_malka_pm_quote_id', $vincular );
		}

		wp_safe_redirect( add_query_arg( 'guardado', '1', self::edit_url( $id ) ) );
		exit;
	}

	/**
	 * Recoge el árbol del formulario y lo guarda.
	 *
	 * Pasa por sanitize_tasks() y save_tasks(), que son los mismos que usa
	 * WP-Admin: así el avance de cada etapa se recalcula desde sus tareas y
	 * las dos pantallas no pueden quedar diciendo cosas distintas.
	 *
	 * @param int $id Proyecto.
	 */
	private static function guardar_arbol( $id ) {
		// El panel manda el árbol entero en un solo campo JSON, para no
		// chocar con el tope de campos por envío de PHP (max_input_vars).
		if ( isset( $_POST['_tarea_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$crudo = json_decode( wp_unslash( $_POST['_tarea_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing
			if ( ! is_array( $crudo ) ) {
				return;
			}
		} elseif ( isset( $_POST['tarea'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$crudo = (array) wp_unslash( $_POST['tarea'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing
		} else {
			return;
		}

		Malka_CRM_Helpers::save_tasks( $id, self::enlazar_etapas( Malka_CRM_Helpers::sanitize_tasks( $crudo ) ) );
	}

	/**
	 * Cuelga cada tarea de su etapa, mirando el número.
	 *
	 * El avance de una etapa se calcula desde las tareas cuyo campo `stage`
	 * apunta a su id. En WP-Admin ese vínculo se elige en un desplegable; acá
	 * se deduce del número, que es lo que Malka ya escribe: la 1.1 cuelga de
	 * la etapa 1. Sin esto, una tarea creada en el panel no sumaría en su
	 * etapa y el avance quedaría en cero sin que se entienda por qué.
	 *
	 * @param array[] $tareas Tareas ya saneadas.
	 * @return array[]
	 */
	public static function enlazar_etapas( $tareas ) {
		$etapas = array();

		foreach ( $tareas as $t ) {
			if ( 'stage' === $t['type'] && '' !== trim( (string) $t['number'] ) ) {
				// La cabeza del número: «2» en «2», «2.1» en «2.1».
				$cabeza = strtok( trim( (string) $t['number'] ), '.' );

				if ( '' !== $cabeza && ! isset( $etapas[ $cabeza ] ) ) {
					$etapas[ $cabeza ] = $t['id'];
				}
			}
		}

		if ( ! $etapas ) {
			return $tareas;
		}

		foreach ( $tareas as $i => $t ) {
			if ( 'stage' === $t['type'] ) {
				continue;
			}

			$cabeza = strtok( trim( (string) $t['number'] ), '.' );

			if ( '' !== $cabeza && isset( $etapas[ $cabeza ] ) ) {
				$tareas[ $i ]['stage'] = $etapas[ $cabeza ];
			}
		}

		return $tareas;
	}

	/**
	 * Guarda las marcas de «listo» desde la vista de Tareas.
	 *
	 * No se recibe el árbol entero: sólo qué quedó marcado. Así una pantalla
	 * pensada para ir tachando no puede borrar por accidente lo que no está
	 * en pantalla.
	 */
	public static function handle_save_tasks() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_marcar_tareas' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$listas = isset( $_POST['listo'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['listo'] ) ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$vistas = isset( $_POST['vista'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['vista'] ) ) : array();

		// Se voltean a claves para preguntar por id sin recorrer la lista.
		$listas = array_flip( $listas );
		$vistas = array_flip( $vistas );
		$cuenta = 0;

		foreach ( self::all() as $p ) {
			$tareas = $p['tareas'];
			$cambio = false;

			foreach ( $tareas as $i => $t ) {
				// Sólo las que estaban en pantalla: lo que no se vio, no se toca.
				if ( ! isset( $vistas[ $t['id'] ] ) ) {
					continue;
				}

				$marcada = isset( $listas[ $t['id'] ] );

				if ( (bool) $marcada === (bool) $t['done'] ) {
					continue;
				}

				if ( $marcada ) {
					$tareas[ $i ] = self::completar( $t );
				} else {
					$tareas[ $i ]['done']     = 0;
					$tareas[ $i ]['progress'] = 0;
					$tareas[ $i ]['done_at']  = '';
				}

				$cambio = true;
				$cuenta++;
			}

			if ( $cambio ) {
				Malka_CRM_Helpers::save_tasks( $p['id'], $tareas );
			}
		}

		// Las del estudio viven aparte, así que se marcan aparte.
		$propias = self::tareas_estudio();
		$cambio  = false;

		foreach ( $propias as $i => $t ) {
			if ( ! isset( $vistas[ $t['id'] ] ) ) {
				continue;
			}

			$marcada = isset( $listas[ $t['id'] ] );

			if ( (bool) $marcada === (bool) $t['done'] ) {
				continue;
			}

			if ( $marcada ) {
				$propias[ $i ] = self::completar( $t );
			} else {
				$propias[ $i ]['done']    = 0;
				$propias[ $i ]['done_at'] = '';
			}

			$cambio = true;
			$cuenta++;
		}

		if ( $cambio ) {
			self::guardar_estudio( $propias );
		}

		wp_safe_redirect( add_query_arg( 'marcadas', $cuenta, Malka_CRM_Dashboard::view_url( 'tareas' ) ) );
		exit;
	}

	/**
	 * Borra una tarea desde la vista de Tareas (con sus subtareas, si tiene).
	 */
	public static function handle_delete_task() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}
		$tid = isset( $_GET['tarea'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) wp_unslash( $_GET['tarea'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		check_admin_referer( 'malka_crm_borrar_tarea_' . $tid );
		$hecho = false;

		if ( '' !== $tid ) {
			foreach ( self::all() as $p ) {
				$tareas = $p['tareas'];
				$num    = null;
				foreach ( $tareas as $t ) {
					if ( isset( $t['id'] ) && (string) $t['id'] === $tid ) {
						$num = trim( (string) ( isset( $t['number'] ) ? $t['number'] : '' ) );
						break;
					}
				}
				if ( null === $num ) {
					continue;
				}
				$quedan = array();
				foreach ( $tareas as $t ) {
					$es  = isset( $t['id'] ) && (string) $t['id'] === $tid;
					$sub = '' !== $num && 0 === strpos( (string) ( isset( $t['number'] ) ? $t['number'] : '' ), $num . '.' );
					if ( ! $es && ! $sub ) {
						$quedan[] = $t;
					}
				}
				Malka_CRM_Helpers::save_tasks( $p['id'], $quedan );
				$hecho = true;
				break;
			}

			if ( ! $hecho ) {
				$propias = self::tareas_estudio();
				$antes   = count( $propias );
				$propias = array_filter(
					$propias,
					function ( $t ) use ( $tid ) {
						return ! ( isset( $t['id'] ) && (string) $t['id'] === $tid );
					}
				);
				if ( count( $propias ) !== $antes ) {
					self::guardar_estudio( $propias );
					$hecho = true;
				}
			}
		}

		$vuelta = wp_get_referer() ? wp_get_referer() : Malka_CRM_Dashboard::view_url( 'tareas' );
		wp_safe_redirect( $vuelta );
		exit;
	}

	/**
	 * Agrega una tarea desde la vista de Tareas.
	 *
	 * Sin negocio va a la lista del estudio; con negocio, al árbol de ese
	 * negocio, al final y marcada como privada si ella lo pidió.
	 */
	public static function handle_add_task() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_agregar_tarea' );

		$nombre  = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$cual     = isset( $_POST['negocio'] ) ? sanitize_key( wp_unslash( $_POST['negocio'] ) ) : '0';
		$personal = 'personal' === $cual;
		$negocio  = $personal ? 0 : absint( $cual );
		$fin     = isset( $_POST['fin'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fin'] ) ) : '';
		$privada = ! empty( $_POST['privada'] );
		$repite  = isset( $_POST['repite'] ) ? sanitize_key( wp_unslash( $_POST['repite'] ) ) : '';
		$repite  = array_key_exists( $repite, self::repeticiones() ) ? $repite : '';

		/*
		 * La ventana se abre desde cualquier parte, así que volvemos a donde
		 * estaba: mandarla siempre a Tareas le perdía la pantalla.
		 */
		$desde = isset( $_POST['desde'] ) ? sanitize_key( wp_unslash( $_POST['desde'] ) ) : '';
		$donde = Malka_CRM_Dashboard::view_url( $desde ? $desde : 'tareas' );

		if ( '' === trim( $nombre ) ) {
			wp_safe_redirect( add_query_arg( 'agregada', '0', $donde ) );
			exit;
		}

		if ( ! $negocio ) {
			self::agregar_estudio( $nombre, $fin, $repite, $personal );
			wp_safe_redirect( add_query_arg( 'agregada', '1', $donde ) );
			exit;
		}

		$tareas = Malka_CRM_Helpers::get_project_tasks( $negocio );
		$hoy    = current_time( 'Y-m-d' );

		$tareas[] = array(
			'id'       => sanitize_key( 'tar-' . wp_generate_password( 6, false, false ) ),
			'name'     => $nombre,
			'type'     => 'task',
			'stage'    => '',
			'start'    => $hoy,
			'end'      => $fin ? $fin : $hoy,
			'progress' => 0,
			'owner'    => 'studio',
			'done'     => 0,
			'done_at'  => '',
			'notes'    => '',
			'number'   => '',
			'privada'  => $privada ? 1 : 0,
			'repite'   => $repite,
		);

		Malka_CRM_Helpers::save_tasks( $negocio, $tareas );

		wp_safe_redirect( add_query_arg( 'agregada', '1', $donde ) );
		exit;
	}

	/**
	 * Manda un negocio a la papelera.
	 */
	public static function handle_delete() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		$id = isset( $_GET['negocio'] ) ? (int) $_GET['negocio'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'malka_crm_delete_project_' . $id );

		if ( ! $id || self::POST_TYPE !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Ese negocio no existe.', 'malka-crm' ) );
		}

		wp_trash_post( $id );

		wp_safe_redirect( add_query_arg( 'borrado', '1', Malka_CRM_Dashboard::view_url( 'negocios' ) ) );
		exit;
	}
}
