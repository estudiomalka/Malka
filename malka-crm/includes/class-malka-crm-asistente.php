<?php
/**
 * El asistente: un chat en el escritorio conectado a Claude.
 *
 * Trabaja sólo con las funciones que ya tiene la plataforma (buscar, leer,
 * crear una tarea, anotar una idea, armar una cotización…). No toca el
 * código ni la base de datos directamente. Lo que crea o cambia algo no se
 * hace hasta que ella lo confirma con un botón.
 *
 * La conversación vive en el navegador (se manda entera en cada turno) y la
 * clave de la API queda guardada en Configuración, nunca llega al navegador.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Asistente con Claude.
 */
class Malka_CRM_Asistente {

	const API     = 'https://api.anthropic.com/v1/messages';
	const VERSION = '2023-06-01';
	const MODELO  = 'claude-sonnet-5-5';

	/**
	 * Ganchos.
	 */
	public static function init() {
		add_action( 'wp_ajax_malka_crm_asistente', array( __CLASS__, 'ajax' ) );
	}

	/**
	 * La clave guardada.
	 *
	 * @return string
	 */
	private static function clave() {
		return trim( (string) Malka_CRM_Settings::get( 'ia_api_key', '' ) );
	}

	/**
	 * El modelo a usar.
	 *
	 * @return string
	 */
	private static function modelo() {
		$m = trim( (string) Malka_CRM_Settings::get( 'ia_modelo', '' ) );
		return $m ? $m : self::MODELO;
	}

	/* ─────────────────────────────  Herramientas  ───────────────────── */

	/**
	 * Las que sólo leen: se ejecutan sin preguntar.
	 *
	 * @return string[]
	 */
	private static function de_lectura() {
		return array( 'buscar', 'ver_cliente', 'ver_negocio', 'tareas_pendientes', 'por_cobrar', 'cobrado', 'precios_anteriores' );
	}

	/**
	 * Lo que Claude puede usar.
	 *
	 * @return array[]
	 */
	private static function herramientas() {
		$obj = function ( $props, $req = array() ) {
			return array(
				'type'       => 'object',
				'properties' => (object) $props,
				'required'   => $req,
			);
		};
		return array(
			array(
				'name'         => 'buscar',
				'description'  => 'Busca clientes, negocios (proyectos) y cotizaciones por texto (nombre, empresa, alias, título, número). Úsala siempre para obtener los IDs antes de actuar.',
				'input_schema' => $obj( array( 'texto' => array( 'type' => 'string' ) ), array( 'texto' ) ),
			),
			array(
				'name'         => 'ver_cliente',
				'description'  => 'Ficha de un cliente: datos de contacto, sus negocios, cotizaciones, próximos proyectos anotados y cuánto debe.',
				'input_schema' => $obj( array( 'cliente_id' => array( 'type' => 'integer' ) ), array( 'cliente_id' ) ),
			),
			array(
				'name'         => 'ver_negocio',
				'description'  => 'Detalle de un negocio: estado, fechas, avance y todas sus tareas con su id, número, responsable, fecha y si está lista.',
				'input_schema' => $obj( array( 'negocio_id' => array( 'type' => 'integer' ) ), array( 'negocio_id' ) ),
			),
			array(
				'name'         => 'tareas_pendientes',
				'description'  => 'Todas las tareas pendientes de los negocios abiertos y del estudio, la que vence antes primero.',
				'input_schema' => $obj( array() ),
			),
			array(
				'name'         => 'por_cobrar',
				'description'  => 'Lo que falta cobrar: cada cuota pendiente con cliente, cotización, monto, fecha y si está vencida. Más el total.',
				'input_schema' => $obj( array() ),
			),
			array(
				'name'         => 'cobrado',
				'description'  => 'Pagos recibidos entre dos fechas (YYYY-MM-DD), con total bruto y neto.',
				'input_schema' => $obj(
					array(
						'desde' => array( 'type' => 'string' ),
						'hasta' => array( 'type' => 'string' ),
					),
					array( 'desde', 'hasta' )
				),
			),
			array(
				'name'         => 'precios_anteriores',
				'description'  => 'Ítems de cotizaciones anteriores que coinciden con un texto, con su precio unitario neto. Úsala para cotizar con los precios que el estudio ya usa.',
				'input_schema' => $obj( array( 'texto' => array( 'type' => 'string' ) ), array( 'texto' ) ),
			),
			array(
				'name'         => 'crear_tarea',
				'description'  => 'Crea una tarea en un negocio (negocio_id) o del estudio (negocio_id = 0). quien: "estudio" o "cliente". fecha en YYYY-MM-DD. Requiere confirmación del usuario.',
				'input_schema' => $obj(
					array(
						'negocio_id' => array( 'type' => 'integer' ),
						'nombre'     => array( 'type' => 'string' ),
						'fecha'      => array( 'type' => 'string' ),
						'quien'      => array(
							'type' => 'string',
							'enum' => array( 'estudio', 'cliente' ),
						),
						'nota'       => array( 'type' => 'string' ),
					),
					array( 'negocio_id', 'nombre' )
				),
			),
			array(
				'name'         => 'marcar_tarea_lista',
				'description'  => 'Da por lista una tarea de un negocio. Requiere confirmación.',
				'input_schema' => $obj(
					array(
						'negocio_id' => array( 'type' => 'integer' ),
						'tarea_id'   => array( 'type' => 'string' ),
					),
					array( 'negocio_id', 'tarea_id' )
				),
			),
			array(
				'name'         => 'anotar_idea',
				'description'  => 'Anota un próximo proyecto en la ficha de un cliente (sólo lo ve el estudio). monto aproximado en pesos, sin IVA, opcional. Requiere confirmación.',
				'input_schema' => $obj(
					array(
						'cliente_id' => array( 'type' => 'integer' ),
						'titulo'     => array( 'type' => 'string' ),
						'monto'      => array( 'type' => 'integer' ),
						'nota'       => array( 'type' => 'string' ),
					),
					array( 'cliente_id', 'titulo' )
				),
			),
			array(
				'name'         => 'crear_cotizacion',
				'description'  => 'Crea una cotización para un cliente con sus ítems (precio unitario NETO en pesos, sin IVA; el IVA se suma solo). Opcional: negocio_id para enlazarla. Queda sin enviar: el usuario la revisa en el editor. Requiere confirmación.',
				'input_schema' => $obj(
					array(
						'cliente_id' => array( 'type' => 'integer' ),
						'titulo'     => array( 'type' => 'string' ),
						'negocio_id' => array( 'type' => 'integer' ),
						'items'      => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => (object) array(
									'titulo'   => array( 'type' => 'string' ),
									'detalle'  => array( 'type' => 'string' ),
									'cantidad' => array( 'type' => 'number' ),
									'unitario' => array( 'type' => 'integer' ),
									'plazo'    => array(
										'type'        => 'integer',
										'description' => 'Días hábiles',
									),
								),
								'required'   => array( 'titulo', 'cantidad', 'unitario' ),
							),
						),
					),
					array( 'cliente_id', 'titulo', 'items' )
				),
			),
		);
	}

	/**
	 * Lo que pide en palabras, para el botón de confirmar.
	 *
	 * @param string $n Herramienta.
	 * @param array  $in Datos.
	 * @return string
	 */
	private static function resumen( $n, $in ) {
		$neg = function ( $id ) {
			return $id ? get_the_title( (int) $id ) : __( 'del estudio', 'malka-crm' );
		};
		$cli = function ( $id ) {
			if ( ! $id || Malka_CRM_Clients::POST_TYPE !== get_post_type( (int) $id ) ) {
				return '?';
			}
			$c = Malka_CRM_Clients::get( (int) $id );
			return $c['empresa'] ? $c['empresa'] : $c['nombre'];
		};
		switch ( $n ) {
			case 'crear_tarea':
				return sprintf(
					/* translators: 1: tarea, 2: negocio, 3: fecha, 4: quién */
					__( 'Crear la tarea «%1$s» en %2$s%3$s · la hace %4$s', 'malka-crm' ),
					isset( $in['nombre'] ) ? $in['nombre'] : '',
					$neg( isset( $in['negocio_id'] ) ? $in['negocio_id'] : 0 ),
					! empty( $in['fecha'] ) ? ' · ' . date_i18n( 'j M', strtotime( $in['fecha'] ) ) : '',
					( isset( $in['quien'] ) && 'cliente' === $in['quien'] ) ? __( 'el cliente', 'malka-crm' ) : __( 'el estudio', 'malka-crm' )
				);
			case 'marcar_tarea_lista':
				$nombre = '';
				foreach ( Malka_CRM_Helpers::get_project_tasks( (int) $in['negocio_id'] ) as $t ) {
					if ( isset( $t['id'] ) && (string) $t['id'] === (string) $in['tarea_id'] ) {
						$nombre = $t['name'];
					}
				}
				return sprintf( /* translators: 1: tarea, 2: negocio */ __( 'Dar por lista «%1$s» en %2$s', 'malka-crm' ), $nombre, $neg( $in['negocio_id'] ) );
			case 'anotar_idea':
				return sprintf(
					/* translators: 1: idea, 2: cliente, 3: monto */
					__( 'Anotar «%1$s» en Próximos proyectos de %2$s%3$s', 'malka-crm' ),
					isset( $in['titulo'] ) ? $in['titulo'] : '',
					$cli( isset( $in['cliente_id'] ) ? $in['cliente_id'] : 0 ),
					! empty( $in['monto'] ) ? ' · ' . Malka_CRM_Settings::money( (int) $in['monto'] ) : ''
				);
			case 'crear_cotizacion':
				$neto  = 0;
				$lista = array();
				foreach ( isset( $in['items'] ) ? (array) $in['items'] : array() as $it ) {
					$sub     = (int) round( (float) ( isset( $it['cantidad'] ) ? $it['cantidad'] : 1 ) * (int) ( isset( $it['unitario'] ) ? $it['unitario'] : 0 ) );
					$neto   += $sub;
					$lista[] = ( isset( $it['cantidad'] ) && 1 != $it['cantidad'] ? $it['cantidad'] . ' × ' : '' ) . ( isset( $it['titulo'] ) ? $it['titulo'] : '' ) . ' (' . Malka_CRM_Settings::money( $sub ) . ')'; // phpcs:ignore Universal.Operators.StrictComparisons
				}
				return sprintf(
					/* translators: 1: título, 2: cliente, 3: ítems, 4: neto */
					__( 'Crear la cotización «%1$s» para %2$s: %3$s. Neto %4$s + IVA', 'malka-crm' ),
					isset( $in['titulo'] ) ? $in['titulo'] : '',
					$cli( isset( $in['cliente_id'] ) ? $in['cliente_id'] : 0 ),
					implode( ', ', $lista ),
					Malka_CRM_Settings::money( $neto )
				);
		}
		return $n;
	}

	/**
	 * Ejecuta una herramienta.
	 *
	 * @param string $n  Nombre.
	 * @param array  $in Datos.
	 * @return array
	 */
	private static function ejecutar( $n, $in ) {
		$in = is_array( $in ) ? $in : array();
		switch ( $n ) {
			case 'buscar':
				return self::t_buscar( isset( $in['texto'] ) ? (string) $in['texto'] : '' );
			case 'ver_cliente':
				return self::t_cliente( isset( $in['cliente_id'] ) ? (int) $in['cliente_id'] : 0 );
			case 'ver_negocio':
				return self::t_negocio( isset( $in['negocio_id'] ) ? (int) $in['negocio_id'] : 0 );
			case 'tareas_pendientes':
				$out = array();
				foreach ( array_slice( Malka_CRM_Projects::pendientes(), 0, 60 ) as $t ) {
					$out[] = array(
						'negocio_id' => $t['pid'],
						'negocio'    => $t['proyecto'],
						'cliente'    => $t['cliente'],
						'tarea_id'   => $t['id'],
						'tarea'      => trim( $t['numero'] . ' ' . $t['nombre'] ),
						'quien'      => $t['quien'],
						'vence'      => $t['limite'],
						'dias'       => $t['dias'],
						'nota_cliente' => isset( $t['nota'] ) ? $t['nota'] : '',
					);
				}
				return array( 'tareas' => $out );
			case 'por_cobrar':
				$filas = Malka_CRM_Dashboard::collections_list();
				$out   = array();
				$total = 0;
				$venc  = 0;
				foreach ( $filas as $f ) {
					$total += (int) $f['total'];
					$venc  += $f['vencido'] ? (int) $f['total'] : 0;
					$out[]  = array(
						'cliente'     => $f['cliente'],
						'cotizacion'  => $f['numero'],
						'cuota'       => $f['cuota'],
						'monto'       => $f['monto'],
						'interes'     => $f['mora'],
						'fecha'       => $f['due'],
						'vencida'     => $f['vencido'],
						'dias'        => $f['dias'],
						'ya_avisado'  => $f['avisado'],
					);
				}
				return array(
					'total'        => $total,
					'vencido'      => $venc,
					'cuotas'       => $out,
					'nota'         => 'Los saldos que se cobran al entregar el proyecto no aparecen hasta la entrega.',
				);
			case 'cobrado':
				$d     = isset( $in['desde'] ) ? Malka_CRM_Helpers::sanitize_date( $in['desde'] ) : current_time( 'Y-m-01' );
				$h     = isset( $in['hasta'] ) ? Malka_CRM_Helpers::sanitize_date( $in['hasta'] ) : current_time( 'Y-m-d' );
				$pagos = Malka_CRM_Analysis::pagos_recibidos( $d, $h );
				$b     = 0;
				$nt    = 0;
				foreach ( $pagos as $p ) {
					$b  += $p['monto'];
					$nt += $p['neto'];
				}
				return array(
					'desde' => $d,
					'hasta' => $h,
					'bruto' => $b,
					'neto'  => $nt,
					'pagos' => array_map(
						function ( $p ) {
							return array_intersect_key( $p, array_flip( array( 'cliente', 'numero', 'monto', 'fecha', 'rol' ) ) );
						},
						array_slice( $pagos, 0, 40 )
					),
				);
			case 'precios_anteriores':
				return self::t_precios( isset( $in['texto'] ) ? (string) $in['texto'] : '' );
			case 'crear_tarea':
				return self::t_crear_tarea( $in );
			case 'marcar_tarea_lista':
				$pid    = isset( $in['negocio_id'] ) ? (int) $in['negocio_id'] : 0;
				$tareas = Malka_CRM_Helpers::get_project_tasks( $pid );
				foreach ( $tareas as $i => $t ) {
					if ( isset( $t['id'] ) && (string) $t['id'] === (string) ( isset( $in['tarea_id'] ) ? $in['tarea_id'] : '' ) ) {
						$tareas[ $i ]['done']     = 1;
						$tareas[ $i ]['progress'] = 100;
						$tareas[ $i ]['done_at']  = current_time( 'mysql' );
						Malka_CRM_Helpers::save_tasks( $pid, $tareas );
						return array( 'ok' => true );
					}
				}
				return array( 'error' => 'No encontré esa tarea.' );
			case 'anotar_idea':
				$cid = isset( $in['cliente_id'] ) ? (int) $in['cliente_id'] : 0;
				if ( ! $cid || Malka_CRM_Clients::POST_TYPE !== get_post_type( $cid ) ) {
					return array( 'error' => 'Cliente no encontrado.' );
				}
				Malka_CRM_Ideas::agregar( $cid, isset( $in['titulo'] ) ? $in['titulo'] : '', isset( $in['nota'] ) ? $in['nota'] : '', isset( $in['monto'] ) ? (int) $in['monto'] : 0 );
				return array(
					'ok'  => true,
					'url' => Malka_CRM_Clients::edit_url( $cid ),
				);
			case 'crear_cotizacion':
				return self::t_crear_cotizacion( $in );
		}
		return array( 'error' => 'Herramienta desconocida.' );
	}

	/**
	 * Buscar.
	 *
	 * @param string $q Texto.
	 * @return array
	 */
	private static function t_buscar( $q ) {
		$q   = remove_accents( strtolower( trim( $q ) ) );
		$hay = function ( $txt ) use ( $q ) {
			return '' === $q || false !== strpos( remove_accents( strtolower( (string) $txt ) ), $q );
		};
		$cli = array();
		foreach ( Malka_CRM_Clients::all() as $c ) {
			if ( $hay( $c['nombre'] . ' ' . $c['empresa'] . ' ' . $c['alias'] . ' ' . $c['rut'] ) ) {
				$cli[] = array(
					'cliente_id' => (int) $c['id'],
					'nombre'     => $c['nombre'],
					'empresa'    => $c['empresa'],
					'alias'      => $c['alias'],
					'email'      => $c['email'],
				);
			}
		}
		$neg = array();
		foreach ( Malka_CRM_Projects::all() as $p ) {
			if ( $hay( $p['titulo'] . ' ' . $p['cliente'] . ' ' . $p['numero'] ) ) {
				$neg[] = array(
					'negocio_id' => (int) $p['id'],
					'titulo'     => $p['titulo'],
					'cliente'    => $p['cliente'],
					'estado'     => $p['etiqueta'],
					'avance'     => $p['avance'],
				);
			}
		}
		$cot = array();
		foreach ( Malka_CRM_Dashboard::quotes_list() as $x ) {
			if ( $hay( $x['numero'] . ' ' . $x['cliente'] . ' ' . $x['titulo'] ) ) {
				$cot[] = array(
					'cotizacion_id' => $x['id'],
					'numero'        => $x['numero'],
					'cliente'       => $x['cliente'],
					'titulo'        => $x['titulo'],
					'total'         => $x['total'],
					'saldo'         => $x['saldo'],
					'estado'        => $x['etiqueta'],
				);
			}
		}
		return array(
			'clientes'     => array_slice( $cli, 0, 15 ),
			'negocios'     => array_slice( $neg, 0, 15 ),
			'cotizaciones' => array_slice( $cot, 0, 15 ),
		);
	}

	/**
	 * Ficha de cliente.
	 *
	 * @param int $cid Cliente.
	 * @return array
	 */
	private static function t_cliente( $cid ) {
		if ( ! $cid || Malka_CRM_Clients::POST_TYPE !== get_post_type( $cid ) ) {
			return array( 'error' => 'Cliente no encontrado.' );
		}
		$c = Malka_CRM_Clients::get( $cid );
		$r = Malka_CRM_Clients::panorama( $c );
		return array(
			'cliente'      => array_intersect_key( $c, array_flip( array( 'id', 'nombre', 'empresa', 'rut', 'alias', 'email', 'fono', 'ciudad', 'giro', 'notas' ) ) ),
			'ganado'       => isset( $r['total'] ) ? $r['total'] : 0,
			'por_cobrar'   => isset( $r['por_cobrar'] ) ? $r['por_cobrar'] : 0,
			'vencido'      => isset( $r['vencido'] ) ? $r['vencido'] : 0,
			'negocios'     => array_map(
				function ( $n ) {
					return array(
						'negocio_id' => $n['id'],
						'titulo'     => $n['titulo'],
						'estado'     => $n['etiqueta'],
						'avance'     => $n['avance'],
					);
				},
				isset( $r['negocios'] ) ? $r['negocios'] : array()
			),
			'cotizaciones' => array_map(
				function ( $q ) {
					return array_intersect_key( (array) $q, array_flip( array( 'id', 'numero', 'titulo', 'total', 'estado' ) ) );
				},
				isset( $r['cotizaciones'] ) ? $r['cotizaciones'] : array()
			),
			'proximos_proyectos' => Malka_CRM_Ideas::de( $cid ),
		);
	}

	/**
	 * Detalle de negocio.
	 *
	 * @param int $pid Negocio.
	 * @return array
	 */
	private static function t_negocio( $pid ) {
		if ( ! $pid || Malka_CRM_Projects::POST_TYPE !== get_post_type( $pid ) ) {
			return array( 'error' => 'Negocio no encontrado.' );
		}
		$p      = Malka_CRM_Projects::get( $pid );
		$tareas = array();
		foreach ( $p['tareas'] as $t ) {
			$tareas[] = array(
				'tarea_id' => isset( $t['id'] ) ? $t['id'] : '',
				'numero'   => isset( $t['number'] ) ? $t['number'] : '',
				'nombre'   => $t['name'],
				'tipo'     => 'stage' === $t['type'] ? 'etapa' : 'tarea',
				'quien'    => isset( $t['owner'] ) ? $t['owner'] : '',
				'fecha'    => isset( $t['end'] ) ? $t['end'] : '',
				'lista'    => ! empty( $t['done'] ),
				'nota'     => isset( $t['notes'] ) ? $t['notes'] : '',
				'nota_cliente' => isset( $t['nota_cliente'] ) ? $t['nota_cliente'] : '',
			);
		}
		return array(
			'negocio_id'    => $pid,
			'titulo'        => $p['titulo'],
			'cliente'       => $p['cliente'],
			'cliente_id'    => Malka_CRM_Ideas::cliente_de_negocio( $pid ),
			'estado'        => $p['etiqueta'],
			'inicio'        => $p['inicio'],
			'entrega'       => $p['fin'],
			'avance'        => $p['avance'],
			'cotizacion_id' => (int) Malka_CRM_Quotes::get_project_quote_id( $pid ),
			'tareas'        => $tareas,
		);
	}

	/**
	 * Precios ya usados.
	 *
	 * @param string $q Texto.
	 * @return array
	 */
	private static function t_precios( $q ) {
		$q   = remove_accents( strtolower( trim( $q ) ) );
		$out = array();
		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 150,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $qid ) {
			foreach ( (array) get_post_meta( $qid, '_malka_pm_quote_items', true ) as $it ) {
				if ( ! is_array( $it ) || empty( $it['titulo'] ) ) {
					continue;
				}
				$txt = remove_accents( strtolower( $it['titulo'] . ' ' . ( isset( $it['detalle'] ) ? $it['detalle'] : '' ) ) );
				if ( '' !== $q && false === strpos( $txt, $q ) ) {
					continue;
				}
				$out[] = array(
					'item'       => $it['titulo'],
					'detalle'    => isset( $it['detalle'] ) ? wp_trim_words( $it['detalle'], 25 ) : '',
					'cantidad'   => isset( $it['cantidad'] ) ? $it['cantidad'] : 1,
					'unitario'   => isset( $it['unitario'] ) ? (int) $it['unitario'] : 0,
					'cotizacion' => (string) get_post_meta( $qid, '_malka_pm_quote_number', true ),
					'fecha'      => (string) get_post_meta( $qid, '_malka_pm_quote_issued', true ),
				);
			}
		}
		return array( 'items' => array_slice( $out, 0, 30 ) );
	}

	/**
	 * Crear tarea.
	 *
	 * @param array $in Datos.
	 * @return array
	 */
	private static function t_crear_tarea( $in ) {
		$pid    = isset( $in['negocio_id'] ) ? (int) $in['negocio_id'] : 0;
		$nombre = isset( $in['nombre'] ) ? sanitize_text_field( $in['nombre'] ) : '';
		$fecha  = ! empty( $in['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( $in['fecha'] ) : '';
		if ( '' === $nombre ) {
			return array( 'error' => 'Falta el nombre.' );
		}
		if ( ! $pid ) {
			$id = Malka_CRM_Projects::agregar_estudio( $nombre, $fecha );
			return $id ? array( 'ok' => true ) : array( 'error' => 'No se pudo crear.' );
		}
		if ( Malka_CRM_Projects::POST_TYPE !== get_post_type( $pid ) ) {
			return array( 'error' => 'Negocio no encontrado.' );
		}
		$tareas = Malka_CRM_Helpers::get_project_tasks( $pid );
		$max    = 0;
		foreach ( $tareas as $t ) {
			$cab = (int) strtok( (string) ( isset( $t['number'] ) ? $t['number'] : '' ), '.' );
			$max = max( $max, $cab );
		}
		$hoy      = current_time( 'Y-m-d' );
		$tareas[] = array(
			'id'       => 't' . strtolower( wp_generate_password( 9, false, false ) ),
			'name'     => $nombre,
			'type'     => 'task',
			'number'   => (string) ( $max + 1 ),
			'start'    => $hoy,
			'end'      => $fecha ? $fecha : $hoy,
			'owner'    => ( isset( $in['quien'] ) && 'cliente' === $in['quien'] ) ? 'client' : 'studio',
			'progress' => 0,
			'done'     => 0,
			'notes'    => isset( $in['nota'] ) ? sanitize_textarea_field( $in['nota'] ) : '',
		);
		Malka_CRM_Helpers::save_tasks( $pid, $tareas );
		return array(
			'ok'  => true,
			'url' => Malka_CRM_Projects::edit_url( $pid ),
		);
	}

	/**
	 * Crear cotización.
	 *
	 * @param array $in Datos.
	 * @return array
	 */
	private static function t_crear_cotizacion( $in ) {
		$cid = isset( $in['cliente_id'] ) ? (int) $in['cliente_id'] : 0;
		if ( ! $cid || Malka_CRM_Clients::POST_TYPE !== get_post_type( $cid ) ) {
			return array( 'error' => 'Cliente no encontrado.' );
		}
		$pid = isset( $in['negocio_id'] ) ? (int) $in['negocio_id'] : 0;
		$pid = ( $pid && Malka_CRM_Projects::POST_TYPE === get_post_type( $pid ) ) ? $pid : 0;

		$items = Malka_CRM_Quote_Editor::clean_items( isset( $in['items'] ) ? (array) $in['items'] : array() );
		$neto  = 0;
		foreach ( $items as $it ) {
			$neto += (int) round( $it['cantidad'] * $it['unitario'] * ( 1 - ( $it['descuento'] / 100 ) ) );
		}
		$iva   = (float) Malka_CRM_Settings::get( 'iva', 19 );
		$total = $neto + (int) round( $neto * ( $iva / 100 ) );

		$qid = Malka_CRM_Ideas::crear_cotizacion( $cid, isset( $in['titulo'] ) ? sanitize_text_field( $in['titulo'] ) : '', $total, $pid );
		if ( ! $qid ) {
			return array( 'error' => 'No se pudo crear.' );
		}
		update_post_meta( $qid, '_malka_pm_quote_items', $items );
		update_post_meta( $qid, '_malka_pm_quote_total', $total );
		update_post_meta( $qid, Malka_CRM_Quotes::META_PAYMENTS, Malka_CRM_Quotes::default_plan( $total ) );
		return array(
			'ok'     => true,
			'numero' => (string) get_post_meta( $qid, Malka_CRM_Quote_Page::META_NUM, true ),
			'total'  => $total,
			'url'    => Malka_CRM_Quote_Editor::edit_url( $qid ),
		);
	}

	/* ─────────────────────────────  Conversación  ───────────────────── */

	/**
	 * Instrucciones para Claude.
	 *
	 * @return string
	 */
	private static function sistema() {
		$s = Malka_CRM_Settings::all();
		return implode(
			"\n",
			array(
				'Eres el asistente del escritorio de ' . ( ! empty( $s['nombre_fantasia'] ) ? $s['nombre_fantasia'] : 'Estudio Malka' ) . ', un estudio de diseño en Chile. Ayudas a la dueña a manejar clientes, negocios (proyectos), tareas, cotizaciones y cobros.',
				'Hoy es ' . date_i18n( 'l j \d\e F \d\e Y', current_time( 'timestamp' ) ) . ' (' . current_time( 'Y-m-d' ) . ').',
				'Responde en español de Chile, cercano y breve. Montos en pesos chilenos con punto de miles ($450.000). Los precios de ítems son netos; el IVA es ' . (float) Malka_CRM_Settings::get( 'iva', 19 ) . '%.',
				'Usa las herramientas para mirar los datos antes de responder; nunca inventes IDs, montos ni nombres. Si hay varios candidatos (dos clientes parecidos), pregunta cuál.',
				'Para crear o cambiar algo usa la herramienta: el sistema le muestra a ella un botón para confirmar, así que no pidas confirmación en texto antes; llama la herramienta directamente con los datos completos.',
				'Si te piden redactar un correo o un mensaje, escríbelo en tu respuesta listo para copiar, con su asunto. No lo envías tú.',
				'No puedes cambiar el diseño ni el código de la plataforma; si te lo piden, dilo con amabilidad.',
			)
		);
	}

	/**
	 * Llama a la API.
	 *
	 * @param array $mensajes Mensajes.
	 * @return array|WP_Error
	 */
	private static function llamar( $mensajes ) {
		$r = wp_remote_post(
			apply_filters( 'malka_crm_asistente_api', self::API ),
			array(
				'timeout' => 90,
				'headers' => array(
					'x-api-key'         => self::clave(),
					'anthropic-version' => self::VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => self::modelo(),
						'max_tokens' => 2000,
						'system'     => self::sistema(),
						'tools'      => self::herramientas(),
						'messages'   => $mensajes,
					)
				),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$body = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			if ( 401 === $code ) {
				$msg = __( 'La clave de la API no es válida. Revísala en Configuración › Estudio › Asistente.', 'malka-crm' );
			}
			return new WP_Error( 'api', $msg );
		}
		return $body;
	}

	/**
	 * Los mensajes que vienen del navegador, saneados.
	 *
	 * @param mixed $crudo JSON.
	 * @return array
	 */
	private static function leer_mensajes( $crudo ) {
		$m = json_decode( (string) $crudo, true );
		if ( ! is_array( $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m as $x ) {
			if ( is_array( $x ) && isset( $x['role'], $x['content'] ) && in_array( $x['role'], array( 'user', 'assistant' ), true ) ) {
				$out[] = array(
					'role'    => $x['role'],
					'content' => $x['content'],
				);
			}
		}
		// Que no crezca sin fin: se queda con lo último, partiendo en un turno del usuario con texto.
		while ( count( $out ) > 40 ) {
			array_shift( $out );
			while ( $out && ! ( 'user' === $out[0]['role'] && is_string( $out[0]['content'] ) ) ) {
				array_shift( $out );
			}
		}
		return $out;
	}

	/**
	 * Un turno del chat.
	 */
	public static function ajax() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}
		check_ajax_referer( 'malka_crm_asistente', 'nonce' );
		if ( '' === self::clave() ) {
			wp_send_json_error( array( 'message' => __( 'Falta la clave de la API. Agrégala en Configuración › Estudio › Asistente.', 'malka-crm' ) ), 400 );
		}

		$msgs  = self::leer_mensajes( isset( $_POST['mensajes'] ) ? wp_unslash( $_POST['mensajes'] ) : '[]' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$texto = isset( $_POST['texto'] ) ? sanitize_textarea_field( wp_unslash( $_POST['texto'] ) ) : '';
		$resp  = isset( $_POST['respuesta'] ) ? sanitize_key( wp_unslash( $_POST['respuesta'] ) ) : '';
		$hecho = array();

		if ( '' !== $resp ) {
			// Ella confirmó o canceló lo que quedó pendiente en el último turno.
			$ult = end( $msgs );
			if ( ! $ult || 'assistant' !== $ult['role'] || ! is_array( $ult['content'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No hay nada pendiente.', 'malka-crm' ) ), 400 );
			}
			$res = array();
			foreach ( $ult['content'] as $b ) {
				if ( ! is_array( $b ) || 'tool_use' !== ( isset( $b['type'] ) ? $b['type'] : '' ) ) {
					continue;
				}
				$es_lectura = in_array( $b['name'], self::de_lectura(), true );
				if ( $es_lectura || 'si' === $resp ) {
					$r = self::ejecutar( $b['name'], isset( $b['input'] ) ? $b['input'] : array() );
					if ( ! $es_lectura ) {
						$hecho[] = array(
							'texto' => self::resumen( $b['name'], isset( $b['input'] ) ? (array) $b['input'] : array() ),
							'ok'    => empty( $r['error'] ),
							'url'   => isset( $r['url'] ) ? $r['url'] : '',
						);
					}
				} else {
					$r = array( 'cancelado' => 'La usuaria no lo confirmó. No se hizo.' );
				}
				$res[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $b['id'],
					'content'     => wp_json_encode( $r ),
				);
			}
			$msgs[] = array(
				'role'    => 'user',
				'content' => $res,
			);
		} elseif ( '' !== trim( $texto ) ) {
			$msgs[] = array(
				'role'    => 'user',
				'content' => $texto,
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Escribe algo.', 'malka-crm' ) ), 400 );
		}

		$texto_out = '';
		for ( $vuelta = 0; $vuelta < 8; $vuelta++ ) {
			$r = self::llamar( $msgs );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ), 502 );
			}
			$contenido = isset( $r['content'] ) ? (array) $r['content'] : array();
			$msgs[]    = array(
				'role'    => 'assistant',
				'content' => $contenido,
			);
			$usos      = array();
			foreach ( $contenido as $b ) {
				if ( 'text' === $b['type'] ) {
					$texto_out .= ( $texto_out ? "\n\n" : '' ) . $b['text'];
				} elseif ( 'tool_use' === $b['type'] ) {
					$usos[] = $b;
				}
			}
			if ( 'tool_use' !== ( isset( $r['stop_reason'] ) ? $r['stop_reason'] : '' ) || ! $usos ) {
				break;
			}

			// ¿Algo que cambia datos? Entonces se detiene y le pregunta.
			$confirmar = array();
			foreach ( $usos as $b ) {
				if ( ! in_array( $b['name'], self::de_lectura(), true ) ) {
					$confirmar[] = self::resumen( $b['name'], (array) $b['input'] );
				}
			}
			if ( $confirmar ) {
				wp_send_json_success(
					array(
						'mensajes'  => $msgs,
						'texto'     => $texto_out,
						'confirmar' => $confirmar,
						'hecho'     => $hecho,
					)
				);
			}

			$res = array();
			foreach ( $usos as $b ) {
				$res[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $b['id'],
					'content'     => wp_json_encode( self::ejecutar( $b['name'], (array) $b['input'] ) ),
				);
			}
			$msgs[] = array(
				'role'    => 'user',
				'content' => $res,
			);
		}

		wp_send_json_success(
			array(
				'mensajes' => $msgs,
				'texto'    => $texto_out,
				'hecho'    => $hecho,
			)
		);
	}

	/* ─────────────────────────────  La burbuja  ─────────────────────── */

	/**
	 * El chat flotante del escritorio.
	 *
	 * @return string
	 */
	public static function burbuja() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			return '';
		}
		$listo = '' !== self::clave();
		// Sin clave no aparece: no se muestra nada que invite a pagar.
		if ( ! $listo ) {
			return '';
		}
		ob_start();
		printf(
			'<div class="esc-ia" id="esc-ia" data-nonce="%1$s" data-listo="%2$d">',
			esc_attr( wp_create_nonce( 'malka_crm_asistente' ) ),
			$listo ? 1 : 0
		);
		printf(
			'<button type="button" class="esc-ia__b" data-ia-abrir aria-label="%1$s" title="%1$s"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5.5h16v10H10l-4.5 3.5v-3.5H4z"/><path d="M12 8.2l.7 1.6 1.6.7-1.6.7-.7 1.6-.7-1.6-1.6-.7 1.6-.7z"/></svg></button>',
			esc_attr__( 'Asistente', 'malka-crm' )
		);
		echo '<section class="esc-ia__p" hidden>';
		printf(
			'<header class="esc-ia__h"><div><b>%1$s</b><small>%2$s</small></div><span><button type="button" class="esc-ia__nuevo" data-ia-nuevo title="%3$s" aria-label="%3$s"><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 8a5 5 0 1 1-1.5-3.5M13 2.5V5h-2.5"/></svg></button><button type="button" class="esc-ia__x" data-ia-cerrar aria-label="%4$s">×</button></span></header>',
			esc_html__( 'Asistente', 'malka-crm' ),
			esc_html__( 'Con Claude · trabaja con tus datos', 'malka-crm' ),
			esc_attr__( 'Conversación nueva', 'malka-crm' ),
			esc_attr__( 'Cerrar', 'malka-crm' )
		);
		echo '<div class="esc-ia__l" data-ia-lista>';
		if ( $listo ) {
			$ej = array(
				__( '¿Cuánto me falta cobrar este mes y a quién?', 'malka-crm' ),
				__( '¿Qué tareas vencen esta semana?', 'malka-crm' ),
				__( 'Crea una tarea para mañana: enviar logos', 'malka-crm' ),
				__( 'Redáctame un correo amable recordando un pago', 'malka-crm' ),
			);
			echo '<div class="esc-ia__hola"><p>' . esc_html__( 'Hola. Pídeme lo que necesites de tus clientes, negocios, tareas, cotizaciones o cobros. Antes de crear o cambiar algo te pido confirmación.', 'malka-crm' ) . '</p><div class="esc-ia__ej">';
			foreach ( $ej as $e ) {
				printf( '<button type="button" data-ia-ej>%s</button>', esc_html( $e ) );
			}
			echo '</div></div>';
		} else {
			printf(
				'<div class="esc-ia__hola"><p>%1$s</p><p><a class="esc-btn esc-btn--soft" href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Para usar el asistente, agrega tu clave de la API de Anthropic (se saca en console.anthropic.com) en Configuración › Estudio › Asistente.', 'malka-crm' ),
				esc_url( add_query_arg( 'tab', 'estudio', Malka_CRM_Dashboard::view_url( 'configuracion' ) ) . '#cfg-ia' ),
				esc_html__( 'Ir a Configuración', 'malka-crm' )
			);
		}
		echo '</div>';
		printf(
			'<form class="esc-ia__f" data-ia-form><textarea rows="1" data-ia-in placeholder="%1$s"%2$s></textarea><button type="submit" class="esc-ia__send" aria-label="%3$s"%2$s><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 13V3M3.5 7.5 8 3l4.5 4.5"/></svg></button></form>',
			esc_attr__( 'Escribe lo que necesitas…', 'malka-crm' ),
			$listo ? '' : ' disabled',
			esc_attr__( 'Enviar', 'malka-crm' )
		);
		echo '</section></div>';
		return ob_get_clean();
	}
}
