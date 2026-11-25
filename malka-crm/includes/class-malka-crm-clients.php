<?php
/**
 * Los clientes, como registro propio.
 *
 * Hasta acá un cliente eran seis campos copiados dentro de cada cotización.
 * Servía para emitir el documento, pero no para contestar «¿cuánto le he
 * facturado a la Clínica Dental?» ni para encontrar a alguien por su RUT
 * cuando hay que registrar una boleta.
 *
 * Este módulo le da ficha propia. Los campos sueltos de las cotizaciones y de
 * los negocios se quedan donde están —son el dato congelado del momento en que
 * se emitió— y además se enlazan al cliente por `_malka_pm_client_id`.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ficha de cliente y su pantalla en el panel.
 */
class Malka_CRM_Clients {

	const POST_TYPE = 'malka_client';

	/** Lo que la identifica ante el SII. */
	const META_RUT = '_malka_pm_cli_rut';

	/** Como ella le dice de verdad: «la dentista», «los del pan». */
	const META_ALIAS = '_malka_pm_cli_alias';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'admin_post_malka_crm_comunicado', array( __CLASS__, 'handle_comunicado' ) );
		add_action( 'admin_post_malka_crm_guardar_cliente', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_malka_crm_borrar_cliente', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_malka_crm_fichar_clientes', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_malka_crm_juntar_clientes', array( __CLASS__, 'handle_merge' ) );

		// Una cotización ganada deja su cliente fichado.
		add_action( 'malka_crm_negocio_desde_cotizacion', array( __CLASS__, 'desde_cotizacion' ), 10, 2 );
	}

	/**
	 * CPT oculto: se administra desde el panel.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Clientes', 'malka-crm' ),
					'singular_name' => __( 'Cliente', 'malka-crm' ),
				),
				'public'              => false,
				'show_ui'             => false,
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

	/* ─────────────────────────────  URLs  ───────────────────────────── */

	public static function list_url() {
		return Malka_CRM_Dashboard::view_url( 'clientes' );
	}

	/**
	 * La ficha de un cliente.
	 *
	 * @param int $id Cliente, o 0 para uno nuevo.
	 * @return string
	 */
	public static function edit_url( $id = 0 ) {
		$base = Malka_CRM_Dashboard::view_url( 'cliente' );

		return $id ? add_query_arg( 'id', (int) $id, $base ) : $base;
	}

	public static function current_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( ! $id ) {
			return 0;
		}

		$p = get_post( $id );

		return ( $p && self::POST_TYPE === $p->post_type ) ? $id : 0;
	}

	/* ─────────────────────────────  Datos  ──────────────────────────── */

	/**
	 * Un cliente, con todo lo suyo resuelto.
	 *
	 * @param int $id Cliente.
	 * @return array
	 */
	public static function get( $id ) {
		$id = (int) $id;

		return array(
			'id'       => $id,
			'nombre'   => get_the_title( $id ),
			'empresa'  => (string) get_post_meta( $id, '_malka_pm_cli_empresa', true ),
			'rut'      => (string) get_post_meta( $id, self::META_RUT, true ),
			// El RUT de la persona, aparte del de la empresa (que es el que
			// va en las facturas y abre el portal).
			'rut_persona' => (string) get_post_meta( $id, '_malka_pm_cli_rut_persona', true ),
			'direccion'   => (string) get_post_meta( $id, '_malka_pm_cli_direccion', true ),
			'alias'    => (string) get_post_meta( $id, self::META_ALIAS, true ),
			'email'    => (string) get_post_meta( $id, '_malka_pm_cli_email', true ),
			'fono'     => (string) get_post_meta( $id, '_malka_pm_cli_fono', true ),
			'ciudad'   => (string) get_post_meta( $id, '_malka_pm_cli_ciudad', true ),
			'giro'     => (string) get_post_meta( $id, '_malka_pm_cli_giro', true ),
			'notas'    => (string) get_post_meta( $id, '_malka_pm_cli_notas', true ),
		);
	}

	/**
	 * Todos los clientes, por nombre.
	 *
	 * @return array[]
	 */
	public static function all() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$out = array();

		foreach ( $ids as $id ) {
			$out[] = self::get( $id );
		}

		return $out;
	}

	/**
	 * Busca por nombre, empresa, RUT o alias.
	 *
	 * El RUT se compara sin puntos ni guion: nadie lo escribe dos veces igual.
	 *
	 * @param string $texto Lo que ella escribió.
	 * @return array[]
	 */
	public static function buscar( $texto ) {
		$texto = trim( (string) $texto );

		if ( '' === $texto ) {
			return self::all();
		}

		$aguja = self::plegar( $texto );
		$rut   = self::solo_rut( $texto );
		$out   = array();

		foreach ( self::all() as $c ) {
			$campos = array( $c['nombre'], $c['empresa'], $c['alias'] );
			$pega   = false;

			foreach ( $campos as $v ) {
				if ( '' !== $v && false !== strpos( self::plegar( $v ), $aguja ) ) {
					$pega = true;
					break;
				}
			}

			if ( ! $pega && $rut && ( false !== strpos( self::solo_rut( $c['rut'] ), $rut ) || ( ! empty( $c['rut_persona'] ) && false !== strpos( self::solo_rut( $c['rut_persona'] ), $rut ) ) ) ) {
				$pega = true;
			}

			if ( $pega ) {
				$out[] = $c;
			}
		}

		return $out;
	}

	/**
	 * Los clientes que el editor puede proponer mientras ella escribe.
	 *
	 * Mezcla las fichas con los clientes que todavía sólo existen adentro de
	 * cotizaciones viejas. Si esperáramos a que estén fichados, el
	 * autocompletado aparecería recién después de ganar el negocio, que es
	 * justo cuando ya no hace falta.
	 *
	 * La llave para no repetir a nadie es el RUT; cuando no hay, el nombre y
	 * la empresa plegados.
	 *
	 * @return array[] nombre, empresa, rut, ciudad, email, fono.
	 */
	public static function sugerencias() {
		$out    = array();
		$vistos = array();

		$clave = function ( $nombre, $empresa, $rut ) {
			$r = self::solo_rut( $rut );

			return '' !== $r ? 'r:' . $r : 'n:' . self::plegar( $nombre . '|' . $empresa );
		};

		$sumar = function ( $c ) use ( &$out, &$vistos, $clave ) {
			if ( '' === trim( $c['nombre'] ) && '' === trim( $c['empresa'] ) ) {
				return;
			}

			$k = $clave( $c['nombre'], $c['empresa'], $c['rut'] );

			if ( isset( $vistos[ $k ] ) ) {
				return;
			}

			$vistos[ $k ] = true;
			$out[]        = $c;
		};

		foreach ( self::all() as $c ) {
			$sumar(
				array(
					'nombre'  => $c['nombre'],
					'empresa' => $c['empresa'],
					'rut'     => $c['rut'],
					'ciudad'  => $c['ciudad'],
					'email'   => $c['email'],
					'fono'    => $c['fono'],
				)
			);
		}

		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 300,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $qid ) {
			$sumar(
				array(
					'nombre'  => (string) get_post_meta( $qid, '_malka_pm_client_name', true ),
					'empresa' => (string) get_post_meta( $qid, '_malka_pm_client_company', true ),
					'rut'     => (string) get_post_meta( $qid, '_malka_pm_client_rut', true ),
					'ciudad'  => (string) get_post_meta( $qid, '_malka_pm_client_city', true ),
					'email'   => (string) get_post_meta( $qid, '_malka_pm_client_email', true ),
					'fono'    => (string) get_post_meta( $qid, '_malka_pm_client_phone', true ),
				)
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcmp(
					self::plegar( $a['nombre'] ? $a['nombre'] : $a['empresa'] ),
					self::plegar( $b['nombre'] ? $b['nombre'] : $b['empresa'] )
				);
			}
		);

		return $out;
	}

	/**
	 * Minúsculas y sin tildes, para que «Bío Bío» encuentre «bio bio».
	 *
	 * @param string $v Texto.
	 * @return string
	 */
	public static function plegar( $v ) {
		$v = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v, 'UTF-8' ) : strtolower( $v );

		return strtr(
			$v,
			array(
				'á' => 'a',
				'é' => 'e',
				'í' => 'i',
				'ó' => 'o',
				'ú' => 'u',
				'ü' => 'u',
				'ñ' => 'n',
			)
		);
	}

	/**
	 * Un RUT reducido a sus dígitos y su verificador.
	 *
	 * @param string $v RUT como venga.
	 * @return string
	 */
	public static function solo_rut( $v ) {
		return strtolower( preg_replace( '/[^0-9kK]/', '', (string) $v ) );
	}

	/**
	 * El cliente que corresponde a un RUT, si ya está fichado.
	 *
	 * @param string $rut RUT.
	 * @return int 0 si no hay.
	 */
	public static function por_rut( $rut ) {
		$rut = self::solo_rut( $rut );

		if ( '' === $rut ) {
			return 0;
		}

		foreach ( self::all() as $c ) {
			if ( self::solo_rut( $c['rut'] ) === $rut ) {
				return $c['id'];
			}
		}

		return 0;
	}

	/**
	 * La ficha que corresponde a un nombre y una empresa.
	 *
	 * Es el desempate de los clientes sin RUT. Se compara plegado, así que
	 * «Viviana Adriazola» y «viviana adriazola» son la misma.
	 *
	 * @param string $nombre  Nombre.
	 * @param string $empresa Empresa.
	 * @return int 0 si no hay.
	 */
	public static function por_nombre( $nombre, $empresa ) {
		$n = self::plegar( $nombre );
		$e = self::plegar( $empresa );

		if ( '' === $n && '' === $e ) {
			return 0;
		}

		foreach ( self::all() as $c ) {
			// Con RUT propio no entra en este desempate: ésa ya se identifica
			// por el RUT y no hay que confundirla con una homónima.
			if ( '' !== self::solo_rut( $c['rut'] ) ) {
				continue;
			}

			if ( self::plegar( $c['nombre'] ) === $n && self::plegar( $c['empresa'] ) === $e ) {
				return (int) $c['id'];
			}
		}

		return 0;
	}

	/**
	 * Cuántas fichas están repetidas, sin tocar ninguna.
	 *
	 * @return int
	 */
	public static function cuantas_repetidas() {
		$vistas = array();
		$n      = 0;

		foreach ( self::all() as $c ) {
			$rut = self::solo_rut( $c['rut'] );
			$k   = '' !== $rut ? 'r:' . $rut : 'n:' . self::plegar( $c['nombre'] . '|' . $c['empresa'] );

			if ( isset( $vistas[ $k ] ) ) {
				$n++;
				continue;
			}

			$vistas[ $k ] = true;
		}

		return $n;
	}

	/**
	 * Junta las fichas repetidas que dejó el alta automática.
	 *
	 * Se queda con la más antigua —la que ya tiene historia enlazada— y le
	 * pasa los datos que a ella le falten. Las cotizaciones y los negocios
	 * que apuntaban a la repetida se reapuntan antes de borrarla.
	 *
	 * @return int Cuántas se juntaron.
	 */
	public static function fusionar_duplicadas() {
		$vistas = array();
		$juntas = 0;

		// De la más vieja a la más nueva: la primera es la que se queda.
		$fichas = self::all();

		usort(
			$fichas,
			function ( $a, $b ) {
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);

		foreach ( $fichas as $c ) {
			$rut = self::solo_rut( $c['rut'] );
			$k   = '' !== $rut ? 'r:' . $rut : 'n:' . self::plegar( $c['nombre'] . '|' . $c['empresa'] );

			if ( ! isset( $vistas[ $k ] ) ) {
				$vistas[ $k ] = (int) $c['id'];
				continue;
			}

			$queda = (int) $vistas[ $k ];
			$sobra = (int) $c['id'];

			// Lo que la que se queda no tenga, se lo lleva de la repetida.
			foreach ( array(
				'_malka_pm_cli_empresa' => 'empresa',
				self::META_RUT          => 'rut',
				self::META_ALIAS        => 'alias',
				'_malka_pm_cli_email'   => 'email',
				'_malka_pm_cli_fono'    => 'fono',
				'_malka_pm_cli_ciudad'  => 'ciudad',
				'_malka_pm_cli_giro'    => 'giro',
			) as $meta => $campo ) {
				if ( '' === trim( (string) get_post_meta( $queda, $meta, true ) ) && '' !== trim( (string) $c[ $campo ] ) ) {
					update_post_meta( $queda, $meta, $c[ $campo ] );
				}
			}

			self::reapuntar( $sobra, $queda );
			wp_delete_post( $sobra, true );
			$juntas++;
		}

		return $juntas;
	}

	/**
	 * Manda a otra ficha todo lo que apuntaba a una repetida.
	 *
	 * @param int $de    Ficha que desaparece.
	 * @param int $hacia Ficha que se queda.
	 */
	private static function reapuntar( $de, $hacia ) {
		foreach ( array( Malka_CRM_Quotes::POST_TYPE, 'malka_project' ) as $tipo ) {
			$ids = get_posts(
				array(
					'post_type'      => $tipo,
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			foreach ( $ids as $id ) {
				if ( (int) get_post_meta( $id, '_malka_pm_client_id', true ) === (int) $de ) {
					update_post_meta( $id, '_malka_pm_client_id', (int) $hacia );
				}
			}
		}
	}

	/**
	 * Lo que el cliente ha movido: negocios, cotizaciones y plata.
	 *
	 * @param array $c Cliente.
	 * @return array
	 */
	public static function resumen( $c ) {
		$cotizaciones = 0;
		$ganadas      = 0;
		$total        = 0;

		foreach ( self::cotizaciones_de( $c ) as $q ) {
			$cotizaciones++;

			if ( in_array( (string) get_post_meta( $q, '_malka_pm_quote_status', true ), array( 'aceptada', 'en_curso', 'entregado', 'finalizada' ), true ) ) {
				$ganadas++;
				$total += (int) get_post_meta( $q, '_malka_pm_quote_total', true );
			}
		}

		return array(
			'cotizaciones' => $cotizaciones,
			'ganadas'      => $ganadas,
			'total'        => $total,
		);
	}

	/**
	 * Las cotizaciones de un cliente.
	 *
	 * Se busca por enlace y, para las de antes de que existieran las fichas,
	 * también por RUT y por nombre.
	 *
	 * @param array $c Cliente.
	 * @return int[]
	 */
	public static function cotizaciones_de( $c ) {
		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
			)
		);

		$rut  = self::solo_rut( $c['rut'] );
		$suyo = array();

		foreach ( $ids as $qid ) {
			if ( (int) get_post_meta( $qid, '_malka_pm_client_id', true ) === (int) $c['id'] ) {
				$suyo[] = $qid;
				continue;
			}

			if ( $rut && self::solo_rut( get_post_meta( $qid, '_malka_pm_client_rut', true ) ) === $rut ) {
				$suyo[] = $qid;
				continue;
			}

			$empresa = (string) get_post_meta( $qid, '_malka_pm_client_company', true );
			$nombre  = (string) get_post_meta( $qid, '_malka_pm_client_name', true );

			if ( $c['empresa'] && $empresa && self::plegar( $empresa ) === self::plegar( $c['empresa'] ) ) {
				$suyo[] = $qid;
				continue;
			}

			if ( $nombre && self::plegar( $nombre ) === self::plegar( $c['nombre'] ) ) {
				$suyo[] = $qid;
			}
		}

		return $suyo;
	}

	/* ──────────────────────────  Alta automática  ───────────────────── */

	/**
	 * Ficha el cliente de una cotización ganada.
	 *
	 * @param int $pid Negocio.
	 * @param int $qid Cotización.
	 */
	public static function desde_cotizacion( $pid, $qid ) {
		$rut     = (string) get_post_meta( $qid, '_malka_pm_client_rut', true );
		$empresa = (string) get_post_meta( $qid, '_malka_pm_client_company', true );
		$nombre  = (string) get_post_meta( $qid, '_malka_pm_client_name', true );

		if ( '' === trim( $nombre ) && '' === trim( $empresa ) ) {
			return;
		}

		/*
		 * Sin RUT no se buscaba nada y se insertaba una ficha nueva cada vez:
		 * dos cotizaciones del mismo cliente sin RUT dejaban dos clientes
		 * idénticos en la lista. Cuando no hay RUT se busca por nombre y
		 * empresa, que es lo único que distingue a uno de otro.
		 */
		$id = $rut ? self::por_rut( $rut ) : self::por_nombre( $nombre, $empresa );

		if ( ! $id ) {
			$id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_title'  => $nombre ? $nombre : $empresa,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $id ) ) {
				return;
			}

			update_post_meta( $id, '_malka_pm_cli_empresa', $empresa );
			update_post_meta( $id, self::META_RUT, $rut );
			update_post_meta( $id, '_malka_pm_cli_email', (string) get_post_meta( $qid, '_malka_pm_client_email', true ) );
			update_post_meta( $id, '_malka_pm_cli_fono', (string) get_post_meta( $qid, '_malka_pm_client_phone', true ) );
			update_post_meta( $id, '_malka_pm_cli_ciudad', (string) get_post_meta( $qid, '_malka_pm_client_city', true ) );
		}

		update_post_meta( $qid, '_malka_pm_client_id', (int) $id );

		// Al fichar en masa no hay negocio todavía: sólo se enlaza si lo hay.
		if ( $pid ) {
			update_post_meta( (int) $pid, '_malka_pm_client_id', (int) $id );
		}
	}

	/**
	 * Ficha de una vez a todos los clientes que ya estaban en cotizaciones.
	 *
	 * @return int Cuántos quedaron fichados.
	 */
	public static function sincronizar() {
		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
			)
		);

		$antes = count( self::all() );

		foreach ( $ids as $qid ) {
			if ( (int) get_post_meta( $qid, '_malka_pm_client_id', true ) ) {
				continue;
			}

			self::desde_cotizacion( 0, $qid );
		}

		return count( self::all() ) - $antes;
	}

	/* ────────────────────────────  Pantallas  ───────────────────────── */

	/**
	 * Todo lo que ella ha hecho con un cliente, de una pasada: cotizaciones,
	 * negocios y plata (ganado, cobrado, por cobrar y vencido).
	 *
	 * Sale de las cotizaciones y sus cuotas; nada se escribe a mano.
	 *
	 * @param array $c Cliente.
	 * @return array
	 */
	public static function panorama( $c ) {
		$hoy     = current_time( 'Y-m-d' );
		$ganan   = array( 'aceptada', 'en_curso', 'entregado', 'finalizada' );
		$estados = Malka_CRM_Quotes::quote_statuses();

		$out = array(
			'cotizaciones' => array(),
			'negocios'     => array(),
			'ganadas'      => 0,
			'total'        => 0,
			'cobrado'      => 0,
			'por_cobrar'   => 0,
			'vencido'      => 0,
			'activos'      => 0,
			'abiertas'     => 0,
			'ultimo'       => '',
			// Las etiquetas de lo que de verdad hicieron juntos (cotizaciones
			// ganadas): «Web» es que hizo una web contigo, no que la cotizó.
			'etiquetas'    => array(),
			'portal'       => ( ! empty( $c['id'] ) && '' !== trim( (string) $c['rut'] ) && class_exists( 'Malka_CRM_Portal' ) )
				? ( Malka_CRM_Portal::activo( $c['id'] ) ? 'on' : 'off' )
				: 'no',
		);

		foreach ( self::cotizaciones_de( $c ) as $qid ) {
			$estado = (string) get_post_meta( $qid, '_malka_pm_quote_status', true );
			$total  = (int) get_post_meta( $qid, '_malka_pm_quote_total', true );
			$fecha  = (string) get_post_field( 'post_date', $qid );

			if ( in_array( $estado, $ganan, true ) ) {
				$out['ganadas']++;
				$out['total'] += $total;

				foreach ( Malka_CRM_Quotes::etiquetas( $qid ) as $t ) {
					$out['etiquetas'][ $t ] = true;
				}

				foreach ( Malka_CRM_Quotes::get_payments( $qid ) as $p ) {
					// El «Pago total» resume las cuotas: contarlo sería contar doble.
					if ( Malka_CRM_Quotes::es_resumen( $qid, $p ) ) {
						continue;
					}
					$monto = isset( $p['amount'] ) ? (int) $p['amount'] : 0;
					if ( isset( $p['status'] ) && 'pagado' === $p['status'] ) {
						$out['cobrado'] += $monto;
					} else {
						$out['por_cobrar'] += $monto;
						if ( ! empty( $p['due'] ) && $p['due'] < $hoy ) {
							$out['vencido'] += $monto;
						}
					}
				}
			}

			if ( 'sin_respuesta' === $estado ) {
				$out['abiertas']++;
			}

			if ( $fecha > $out['ultimo'] ) {
				$out['ultimo'] = $fecha;
			}

			$out['cotizaciones'][] = array(
				'id'       => (int) $qid,
				'numero'   => (string) get_post_meta( $qid, Malka_CRM_Quote_Page::META_NUM, true ),
				'titulo'   => get_the_title( $qid ),
				'estado'   => $estado,
				'etiqueta' => isset( $estados[ $estado ] ) ? $estados[ $estado ] : $estado,
				'total'    => $total,
				'fecha'    => $fecha,
			);
		}

		$out['etiquetas'] = array_keys( $out['etiquetas'] );

		usort(
			$out['cotizaciones'],
			function ( $a, $b ) {
				return strcmp( $b['fecha'], $a['fecha'] );
			}
		);

		// Sus negocios: los de sus cotizaciones y los enlazados a la ficha.
		if ( class_exists( 'Malka_CRM_Projects' ) && ! empty( $c['id'] ) ) {
			$pids = array();
			$qids = wp_list_pluck( $out['cotizaciones'], 'id' );

			if ( $qids ) {
				$pids = get_posts(
					array(
						'post_type'      => Malka_CRM_Projects::POST_TYPE,
						'post_status'    => array( 'publish', 'private', 'draft' ),
						'posts_per_page' => 100,
						'fields'         => 'ids',
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array(
								'key'     => '_malka_pm_quote_id',
								'value'   => $qids,
								'compare' => 'IN',
							),
						),
					)
				);
			}

			$pids = array_unique(
				array_merge(
					$pids,
					get_posts(
						array(
							'post_type'      => Malka_CRM_Projects::POST_TYPE,
							'post_status'    => array( 'publish', 'private', 'draft' ),
							'posts_per_page' => 100,
							'fields'         => 'ids',
							'meta_key'       => '_malka_pm_client_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
							'meta_value'     => (int) $c['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						)
					)
				)
			);

			foreach ( $pids as $pid ) {
				$n = Malka_CRM_Projects::get( $pid );

				if ( ! in_array( $n['estado'], array( 'entregado', 'pausado' ), true ) ) {
					$out['activos']++;
				}

				$out['negocios'][] = $n;
			}
		}

		return $out;
	}

	/**
	 * Las iniciales para el círculo del cliente.
	 *
	 * @param array $c Cliente.
	 * @return string
	 */
	private static function iniciales( $c ) {
		$base   = $c['nombre'] ? $c['nombre'] : $c['empresa'];
		$partes = preg_split( '/\s+/u', trim( (string) $base ) );
		$ini    = '';

		foreach ( array_slice( array_filter( $partes ), 0, 2 ) as $parte ) {
			$ini .= function_exists( 'mb_substr' ) ? mb_substr( $parte, 0, 1 ) : substr( $parte, 0, 1 );
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $ini ) : strtoupper( $ini );
	}

	/**
	 * El color de una cotización según su estado.
	 *
	 * @param string $estado Estado.
	 * @return string
	 */
	private static function tono( $estado ) {
		switch ( $estado ) {
			case 'aceptada':
			case 'finalizada':
				return 'done';
			case 'en_curso':
				return 'run';
			case 'entregado':
			case 'sin_respuesta':
				return 'wait';
		}
		return 'off';
	}

	/**
	 * Cómo va el trabajo con un cliente, en una etiqueta.
	 *
	 * @param array $r Panorama.
	 * @return array{0:string,1:string} Texto y tono.
	 */
	private static function sello_trabajo( $r ) {
		if ( $r['activos'] ) {
			return array(
				sprintf( /* translators: cuántos negocios */ _n( '%d negocio en curso', '%d negocios en curso', $r['activos'], 'malka-crm' ), $r['activos'] ),
				'run',
			);
		}
		if ( $r['abiertas'] ) {
			return array(
				_n( 'Cotización por responder', 'Cotizaciones por responder', $r['abiertas'], 'malka-crm' ),
				'wait',
			);
		}
		return array( __( 'Sin trabajo activo', 'malka-crm' ), 'off' );
	}

	/**
	 * La lista: pestañas, buscador al escribir y una fila por cliente que se
	 * abre entera. Borrar ya no vive en la fila: está en la ficha, como en
	 * los negocios.
	 *
	 * @return string
	 */
	public static function render_list() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		$clientes = self::all();

		ob_start();
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total">';
		echo self::mensaje(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$filas  = array();
		$ganado = 0;
		$cuenta = array(
			'todos'  => count( $clientes ),
			'curso'  => 0,
			'cobrar' => 0,
			'portal' => 0,
		);
		$tags_cuenta = array();

		foreach ( $clientes as $c ) {
			$r       = self::panorama( $c );
			$ganado += $r['total'];
			$grupos  = array();

			if ( $r['activos'] || $r['abiertas'] ) {
				$grupos[] = 'curso';
				$cuenta['curso']++;
			}
			if ( $r['por_cobrar'] > 0 ) {
				$grupos[] = 'cobrar';
				$cuenta['cobrar']++;
			}
			if ( 'on' === $r['portal'] ) {
				$grupos[] = 'portal';
				$cuenta['portal']++;
			}
			foreach ( $r['etiquetas'] as $t ) {
				$tags_cuenta[ $t ] = isset( $tags_cuenta[ $t ] ) ? $tags_cuenta[ $t ] + 1 : 1;
			}

			$filas[] = array( $c, $r, $grupos );
		}

		echo '<section class="esc-panel esc-panel--full">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Tus clientes', 'malka-crm' ) . '</h2>';
		printf(
			'<p>%s</p></div>',
			esc_html(
				$clientes
					? sprintf(
						/* translators: 1: cuántos clientes, 2: monto */
						_n( '%1$d cliente · %2$s ganado en total', '%1$d clientes · %2$s ganado en total', count( $clientes ), 'malka-crm' ),
						count( $clientes ),
						Malka_CRM_Settings::money( $ganado )
					)
					: __( 'Con quién trabajas y cuánto te ha dejado cada uno', 'malka-crm' )
			)
		);
		printf(
			'<div class="esc-ph__r"><a class="esc-btn esc-btn--primary" href="%s">+ %s</a></div></div>',
			esc_url( self::edit_url() ),
			esc_html__( 'Nuevo cliente', 'malka-crm' )
		);

		if ( ! $clientes ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no hay clientes fichados. Se crean solos cuando una cotización se acepta, o puedes agregar uno a mano.', 'malka-crm' ) . '</p>';
			printf(
				'<div class="esc-row-btn"><a class="esc-btn" href="%1$s">%2$s</a></div>',
				esc_url( wp_nonce_url( add_query_arg( 'action', 'malka_crm_fichar_clientes', admin_url( 'admin-post.php' ) ), 'malka_crm_fichar_clientes' ) ),
				esc_html__( 'Ficharlos desde mis cotizaciones', 'malka-crm' )
			);
			echo '</section></div>';

			return ob_get_clean();
		}

		/* Pestañas y buscador: filtran al escribir, sin recargar. */
		echo '<div class="esc-nl__bar"><div class="esc-nl__tabs" role="tablist">';
		$tabs = array(
			'todos'  => __( 'Todos', 'malka-crm' ),
			'curso'  => __( 'Con trabajo en curso', 'malka-crm' ),
			'cobrar' => __( 'Por cobrar', 'malka-crm' ),
			'portal' => __( 'Con portal', 'malka-crm' ),
		);
		foreach ( $tabs as $k => $rotulo ) {
			if ( 'todos' !== $k && ! $cuenta[ $k ] ) {
				continue;
			}
			printf(
				'<button type="button" class="esc-nl__tab%1$s" role="tab" aria-selected="%2$s" data-grupo="%3$s">%4$s <i>%5$d</i></button>',
				'todos' === $k ? ' is-on' : '',
				'todos' === $k ? 'true' : 'false',
				esc_attr( $k ),
				esc_html( $rotulo ),
				(int) $cuenta[ $k ]
			);
		}
		echo '</div>';
		echo '<div class="esc-ql__tools">';
		printf(
			'<input type="search" class="esc-nl__q" id="esc-nl-q" value="%1$s" placeholder="%2$s" aria-label="%2$s" autocomplete="off" />',
			esc_attr( $q ),
			esc_attr__( 'Busca por nombre, empresa, RUT o alias', 'malka-crm' )
		);
		/*
		 * Escribirles a varios: activa las casillas de las filas. Primero se
		 * filtra (por etiqueta, pestaña o búsqueda) y quedan marcados los que
		 * se ven; después se desmarca al que no.
		 */
		printf(
			'<button type="button" class="esc-btn esc-btn--soft" id="esc-cl-escribir">%1$s %2$s</button>',
			'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
			esc_html__( 'Escribir a clientes', 'malka-crm' )
		);
		echo '</div></div>';

		echo Malka_CRM_Dashboard::filtro_etiquetas( $tags_cuenta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="esc-nl__list" id="esc-nl">';
		printf(
			'<div class="esc-nl esc-nl--h esc-cl"><span class="esc-cl__sel"></span><span>%6$s</span><span>%1$s</span><span>%2$s</span><span>%3$s</span><span>%4$s</span><span>%5$s</span></div>',
			esc_html__( 'Cliente', 'malka-crm' ),
			esc_html__( 'Contacto', 'malka-crm' ),
			esc_html__( 'Trabajo', 'malka-crm' ),
			esc_html__( 'Por cobrar', 'malka-crm' ),
			esc_html__( 'Ganado', 'malka-crm' ),
			esc_html__( 'Portal', 'malka-crm' )
		);

		foreach ( $filas as $f ) {
			list( $c, $r, $grupos ) = $f;
			list( $sello, $tono )   = self::sello_trabajo( $r );

			$buscar = self::plegar( implode( ' ', array( $c['nombre'], $c['empresa'], $c['alias'], $c['rut'], self::solo_rut( $c['rut'] ), $c['rut_persona'], self::solo_rut( $c['rut_persona'] ), $c['email'], $c['ciudad'] ) ) );

			if ( $r['vencido'] > 0 ) {
				$cobro = sprintf(
					'<b class="is-late">%1$s</b><span class="is-late">%2$s</span>',
					esc_html( Malka_CRM_Settings::money( $r['por_cobrar'] ) ),
					esc_html( sprintf( /* translators: monto */ __( '%s vencido', 'malka-crm' ), Malka_CRM_Settings::money( $r['vencido'] ) ) )
				);
			} elseif ( $r['por_cobrar'] > 0 ) {
				$cobro = sprintf( '<b>%s</b><span>%s</span>', esc_html( Malka_CRM_Settings::money( $r['por_cobrar'] ) ), esc_html__( 'al día', 'malka-crm' ) );
			} elseif ( $r['ganadas'] ) {
				$cobro = '<span class="esc-nl__ok">' . esc_html__( 'Todo pagado', 'malka-crm' ) . '</span>';
			} else {
				$cobro = '<span class="esc-nl__sin">—</span>';
			}

			// El portal va en su propia columna, primera. Abierto, lleva al portal del cliente.
			$p_url = ( 'on' === $r['portal'] && class_exists( 'Malka_CRM_Portal' ) ) ? Malka_CRM_Portal::url( $c['id'] ) : '';
			if ( $p_url ) {
				$portal = sprintf(
					'<a class="esc-cl__portal is-on" href="%1$s" target="_blank" rel="noopener" title="%2$s"><i></i>%3$s</a>',
					esc_url( $p_url ),
					esc_attr__( 'Abrir el portal de este cliente', 'malka-crm' ),
					esc_html__( 'Abierto', 'malka-crm' )
				);
			} elseif ( 'off' === $r['portal'] || 'on' === $r['portal'] ) {
				$portal = '<span class="esc-cl__portal" title="' . esc_attr__( 'El portal de cliente está cerrado', 'malka-crm' ) . '"><i></i>' . esc_html__( 'Cerrado', 'malka-crm' ) . '</span>';
			} else {
				$portal = '<span class="esc-cl__portal is-sin" title="' . esc_attr__( 'Sin RUT en la ficha: no puede tener portal', 'malka-crm' ) . '">—</span>';
			}
			$correo = is_email( $c['email'] );

			/*
			 * La fila se abre entera (enlace estirado sobre el nombre). La
			 * casilla de la izquierda sólo aparece al escribir a varios.
			 */
			printf(
				'<div class="esc-nl esc-cl" data-grupo="todos" data-grupos="%2$s" data-buscar="%3$s" data-tags="%15$s" data-id="%16$d">' .
				'<span class="esc-cl__sel"><input type="checkbox" class="esc-cl__chk" value="%16$d"%17$s aria-label="%18$s" /></span>' .
				'<span class="esc-cl__po">%19$s</span>' .
				'<span class="esc-cl__p"><i class="esc-av" aria-hidden="true">%4$s</i><span><b><a class="esc-ql__a" href="%1$s">%5$s</a></b><span>%6$s</span>%20$s</span></span>' .
				'<span class="esc-cl__k"><b>%7$s</b><span>%8$s</span></span>' .
				'<span class="esc-cl__t"><span class="esc-st esc-st--%9$s">%10$s</span><span>%11$s</span></span>' .
				'<span class="esc-nl__c">%12$s</span>' .
				'<span class="esc-nl__c"><b>%13$s</b><span>%14$s</span></span></div>',
				esc_url( self::edit_url( $c['id'] ) ),
				esc_attr( implode( ' ', $grupos ) ),
				esc_attr( self::plegar( $buscar . ' ' . implode( ' ', $r['etiquetas'] ) ) ),
				esc_html( self::iniciales( $c ) ),
				esc_html( $c['nombre'] ? $c['nombre'] : $c['empresa'] ),
				esc_html( implode( ' · ', array_filter( array( $c['nombre'] ? $c['empresa'] : '', $c['alias'] ? '«' . $c['alias'] . '»' : '' ) ) ) ),
				esc_html( $c['email'] ? $c['email'] : '—' ),
				esc_html( $c['fono'] ),
				esc_attr( $tono ),
				esc_html( $sello ),
				esc_html(
					$r['cotizaciones']
						? sprintf( /* translators: 1: ganadas, 2: total */ _n( '%1$d de %2$d cotización ganada', '%1$d de %2$d cotizaciones ganadas', count( $r['cotizaciones'] ), 'malka-crm' ), $r['ganadas'], count( $r['cotizaciones'] ) )
						: __( 'Sin cotizaciones', 'malka-crm' )
				),
				$cobro, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $r['total'] > 0 ? Malka_CRM_Settings::money( $r['total'] ) : '—' ),
				esc_html( $r['ultimo'] ? sprintf( /* translators: fecha */ __( 'Última: %s', 'malka-crm' ), date_i18n( 'M Y', strtotime( $r['ultimo'] ) ) ) : '' ),
				esc_attr( implode( '|', array_map( array( 'Malka_CRM_Dashboard', 'llave_etiqueta' ), $r['etiquetas'] ) ) ),
				(int) $c['id'],
				$correo ? '' : ' disabled',
				esc_attr( $correo ? __( 'Incluir en el correo', 'malka-crm' ) : __( 'Sin correo en la ficha', 'malka-crm' ) ),
				$portal, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				Malka_CRM_Dashboard::chips_etiquetas( $r['etiquetas'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		printf( '<p class="esc-nl__vacio" hidden>%s</p>', esc_html__( 'Ningún cliente calza con eso.', 'malka-crm' ) );
		echo '</div>';

		echo self::bloque_comunicado(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$repes = self::cuantas_repetidas();

		if ( $repes > 0 ) {
			printf(
				'<p class="esc-aviso">%1$s</p><div class="esc-row-btn"><a class="esc-btn" href="%2$s">%3$s</a></div>',
				esc_html(
					sprintf(
						/* translators: cuántas fichas están repetidas */
						_n(
							'Hay %d ficha repetida: el alta automática creaba una nueva cada vez que un cliente sin RUT aceptaba otra cotización.',
							'Hay %d fichas repetidas: el alta automática creaba una nueva cada vez que un cliente sin RUT aceptaba otra cotización.',
							$repes,
							'malka-crm'
						),
						$repes
					)
				),
				esc_url( wp_nonce_url( add_query_arg( 'action', 'malka_crm_juntar_clientes', admin_url( 'admin-post.php' ) ), 'malka_crm_juntar_clientes' ) ),
				esc_html__( 'Juntar las repetidas', 'malka-crm' )
			);
		}

		echo '</section></div>';

		return ob_get_clean();
	}

	/**
	 * Escribir a varios clientes: la barra de abajo (cuántos van marcados) y
	 * la ventana con el asunto y el mensaje.
	 *
	 * Cada cliente recibe su propio correo, con el diseño de Plantillas de
	 * correo y su nombre donde dice {cliente}. Nadie ve a quién más le llegó.
	 *
	 * @return string
	 */
	private static function bloque_comunicado() {
		if ( ! class_exists( 'Malka_CRM_Messages' ) ) {
			return '';
		}
		$t = Malka_CRM_Messages::texto( 'comunicado' );

		// Las plantillas suyas (duplicadas) sirven de punto de partida.
		$elige = '';
		$prop  = Malka_CRM_Messages::propias();
		if ( $prop ) {
			$elige = '<p class="esc-f"><label class="esc-f__l" for="esc-com-tpl">' . esc_html__( 'Partir de', 'malka-crm' ) . '</label><span class="esc-f__w"><select class="esc-f__i" id="esc-com-tpl">';
			$elige .= sprintf( '<option value="" data-asunto="%1$s" data-cuerpo="%2$s">%3$s</option>', esc_attr( $t['asunto'] ), esc_attr( $t['cuerpo'] ), esc_html__( 'Comunicado a clientes', 'malka-crm' ) );
			foreach ( $prop as $k => $pr ) {
				$tx     = Malka_CRM_Messages::texto( $k );
				$elige .= sprintf( '<option value="%1$s" data-asunto="%2$s" data-cuerpo="%3$s">%4$s</option>', esc_attr( $k ), esc_attr( $tx['asunto'] ), esc_attr( $tx['cuerpo'] ), esc_html( $pr['rotulo'] ) );
			}
			$elige .= '</select></span></p>';
		}

		ob_start();
		printf(
			'<div class="esc-cl__envio" id="esc-cl-envio" hidden><span><b id="esc-cl-n">0</b> %1$s</span>' .
			'<button type="button" class="esc-btn esc-btn--ghost" id="esc-cl-todos">%2$s</button>' .
			'<button type="button" class="esc-btn esc-btn--ghost" id="esc-cl-salir">%3$s</button>' .
			'<button type="button" class="esc-btn esc-btn--primary" id="esc-cl-redactar" disabled>%4$s</button></div>',
			esc_html__( 'clientes marcados', 'malka-crm' ),
			esc_html__( 'Marcar los que se ven', 'malka-crm' ),
			esc_html__( 'Cancelar', 'malka-crm' ),
			esc_html__( 'Escribir correo', 'malka-crm' )
		);

		printf(
			'<dialog class="esc-dlg esc-com" id="esc-com-dlg"><form method="post" action="%1$s" id="esc-com-form">' .
			'<input type="hidden" name="action" value="malka_crm_comunicado" />%2$s' .
			'<div class="esc-dlg__h"><div><h2>%3$s</h2><p id="esc-com-para"></p></div><button type="button" class="esc-dlg__x" data-cierra aria-label="%4$s">&times;</button></div>' . $elige .
			'<p class="esc-f"><label class="esc-f__l" for="esc-com-asunto">%5$s</label><span class="esc-f__w"><input class="esc-f__i" type="text" id="esc-com-asunto" name="asunto" value="%6$s" required /></span></p>' .
			'<p class="esc-f"><label class="esc-f__l" for="esc-com-cuerpo">%7$s</label><textarea class="esc-f__i esc-f__t" id="esc-com-cuerpo" name="cuerpo" rows="11" required>%8$s</textarea>' .
			'<span class="esc-f__h">%9$s</span></p>' .
			'<div class="esc-com__prev" id="esc-com-prev" hidden><iframe title="%10$s"></iframe></div>' .
			'<div class="esc-com__pie"><button type="button" class="esc-btn esc-btn--ghost" id="esc-com-ver" data-nonce="%11$s" data-ajax="%12$s">%10$s</button>' .
			'<button type="submit" class="esc-btn esc-btn--primary" id="esc-com-enviar">%13$s</button></div>' .
			'</form></dialog>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_comunicado', '_wpnonce', true, false ),
			esc_html__( 'Correo a tus clientes', 'malka-crm' ),
			esc_attr__( 'Cerrar', 'malka-crm' ),
			esc_html__( 'Asunto', 'malka-crm' ),
			esc_attr( $t['asunto'] ),
			esc_html__( 'Mensaje', 'malka-crm' ),
			esc_textarea( $t['cuerpo'] ),
			esc_html__( '{cliente} se cambia por el nombre de cada uno; {enlace} es el botón «Escríbenos». Una línea con ## es un subtítulo y **así** queda en negrita. El texto de partida se cambia en Plantillas de correo → Comunicado a clientes.', 'malka-crm' ),
			esc_html__( 'Ver cómo se ve', 'malka-crm' ),
			esc_attr( wp_create_nonce( 'malka_crm_previa_mensaje' ) ),
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_html__( 'Enviar', 'malka-crm' )
		);

		return ob_get_clean();
	}

	/**
	 * Manda el comunicado a cada cliente marcado.
	 */
	public static function handle_comunicado() {
		check_admin_referer( 'malka_crm_comunicado' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) || ! class_exists( 'Malka_CRM_Messages' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) ) : array();
		$asunto = isset( $_POST['asunto'] ) ? sanitize_text_field( wp_unslash( $_POST['asunto'] ) ) : '';
		$cuerpo = isset( $_POST['cuerpo'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cuerpo'] ) ) : '';

		$s       = Malka_CRM_Settings::all();
		$contact = ! empty( $s['email'] ) && is_email( $s['email'] ) ? 'mailto:' . $s['email'] : home_url( '/' );
		$bien    = 0;
		$mal     = 0;

		foreach ( array_slice( array_unique( $ids ), 0, 300 ) as $id ) {
			if ( self::POST_TYPE !== get_post_type( $id ) ) {
				continue;
			}
			$c = self::get( $id );
			if ( ! is_email( $c['email'] ) ) {
				$mal++;
				continue;
			}

			// Su nombre de pila, que es como se le habla.
			$pila = $c['nombre'] ? strtok( $c['nombre'], ' ' ) : $c['empresa'];

			$ok = Malka_CRM_Messages::enviar(
				$c['email'],
				'comunicado',
				array(
					'cliente' => $pila,
					'enlace'  => $contact,
					'estudio' => Malka_CRM_Messages::estudio(),
				),
				array(
					'asunto' => $asunto,
					'cuerpo' => $cuerpo,
				)
			);

			if ( $ok ) {
				$bien++;
				// Queda en su ficha qué se le mandó y cuándo.
				$hist   = get_post_meta( $id, '_malka_pm_cli_comunicados', true );
				$hist   = is_array( $hist ) ? $hist : array();
				$hist[] = array(
					'fecha'  => current_time( 'mysql' ),
					'asunto' => $asunto,
				);
				update_post_meta( $id, '_malka_pm_cli_comunicados', array_slice( $hist, -30 ) );
			} else {
				$mal++;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'malka_crm_msg'  => 'comunicado',
					'malka_crm_sent' => $bien,
					'malka_crm_fail' => $mal,
				),
				self::list_url()
			)
		);
		exit;
	}

	/**
	 * La dirección para mandar una ficha a la papelera.
	 *
	 * @param int $id Cliente.
	 * @return string
	 */
	private static function url_borrar( $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'malka_crm_borrar_cliente',
					'cliente' => (int) $id,
				),
				admin_url( 'admin-post.php' )
			),
			'malka_crm_borrar_cliente_' . (int) $id
		);
	}

	/**
	 * La ficha de un cliente.
	 *
	 * Arriba, quién es y cómo contactarlo, con tres franjas: lo ganado, lo
	 * que falta cobrar y sus negocios. Abajo, a la izquierda, sus datos y su
	 * trabajo (negocios y cotizaciones); a la derecha, su portal.
	 *
	 * @return string
	 */
	public static function render_form() {
		$id = self::current_id();
		$c  = $id
			? self::get( $id )
			: array( 'id' => 0, 'nombre' => '', 'empresa' => '', 'rut' => '', 'rut_persona' => '', 'direccion' => '', 'alias' => '', 'email' => '', 'fono' => '', 'ciudad' => '', 'giro' => '', 'notas' => '' );

		$r = $id ? self::panorama( $c ) : null;

		ob_start();
		printf(
			'<form class="esc-cfg esc-cfg--sola esc-cf esc-vista--total" method="post" action="%1$s" data-auto-nuevo="#cli-nombre" data-auto-msg="%2$s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr__( 'Se guarda solo apenas pongas el nombre', 'malka-crm' )
		);
		wp_nonce_field( 'malka_crm_guardar_cliente', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_guardar_cliente" />';
		printf( '<input type="hidden" name="cliente" value="%d" />', (int) $id );

		/* ── Cabecera: quién es, cómo contactarlo y cómo va ── */
		if ( $id ) {
			echo '<section class="esc-panel esc-ch">';
			echo '<div class="esc-ch__top">';
			printf( '<i class="esc-av esc-av--l" aria-hidden="true">%s</i>', esc_html( self::iniciales( $c ) ) );
			echo '<div class="esc-ch__id">';
			printf( '<h2 class="esc-ch__n" data-espejo-de="cli-nombre">%s</h2>', esc_html( $c['nombre'] ? $c['nombre'] : $c['empresa'] ) );
			printf(
				'<p class="esc-ch__s">%s</p>',
				esc_html( implode( ' · ', array_filter( array( $c['nombre'] ? $c['empresa'] : '', $c['rut'], $c['ciudad'] ) ) ) )
			);

			$fono = preg_replace( '/\D/', '', $c['fono'] );
			$acc  = array();
			if ( is_email( $c['email'] ) ) {
				$acc[] = sprintf( '<a class="esc-ch__a" href="mailto:%1$s"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>%2$s</a>', esc_attr( $c['email'] ), esc_html__( 'Correo', 'malka-crm' ) );
			}
			if ( strlen( $fono ) >= 8 ) {
				$wa    = ( 9 === strlen( $fono ) ) ? '56' . $fono : $fono;
				$acc[] = sprintf( '<a class="esc-ch__a" href="https://wa.me/%1$s" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19z"/></svg>%2$s</a>', esc_attr( $wa ), esc_html__( 'WhatsApp', 'malka-crm' ) );
				$acc[] = sprintf( '<a class="esc-ch__a" href="tel:+%1$s"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>%2$s</a>', esc_attr( $wa ), esc_html__( 'Llamar', 'malka-crm' ) );
			}
			if ( $acc ) {
				echo '<p class="esc-ch__acc">' . implode( '', $acc ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';

			printf(
				'<a class="esc-nh__bin" href="%1$s" data-confirmar="%2$s" title="%3$s" aria-label="%3$s">' .
				'<svg class="esc-x-ic" viewBox="0 0 16 16" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></a>',
				esc_url( self::url_borrar( $id ) ),
				esc_attr__( '¿Mandar esta ficha a la papelera? Las cotizaciones y los negocios no se tocan.', 'malka-crm' ),
				esc_attr__( 'Mandar este cliente a la papelera', 'malka-crm' )
			);
			echo '</div>';

			/* Las tres franjas. */
			echo '<div class="esc-nh__band">';

			printf(
				'<div class="esc-nh__cell"><span class="esc-nh__k">%1$s</span><b class="esc-ch__big">%2$s</b><span class="esc-ch__sub">%3$s</span></div>',
				esc_html__( 'Ganado', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $r['total'] ) ),
				esc_html(
					$r['cotizaciones']
						? sprintf( /* translators: 1: ganadas, 2: total */ _n( '%1$d de %2$d cotización ganada', '%1$d de %2$d cotizaciones ganadas', count( $r['cotizaciones'] ), 'malka-crm' ), $r['ganadas'], count( $r['cotizaciones'] ) )
						: __( 'Todavía sin cotizaciones', 'malka-crm' )
				)
			);

			if ( $r['vencido'] > 0 ) {
				$sub = '<span class="esc-ch__sub is-late">' . esc_html( sprintf( /* translators: 1: vencido, 2: cobrado */ __( '%1$s vencido · ya cobraste %2$s', 'malka-crm' ), Malka_CRM_Settings::money( $r['vencido'] ), Malka_CRM_Settings::money( $r['cobrado'] ) ) ) . '</span>';
			} elseif ( $r['por_cobrar'] > 0 ) {
				$sub = '<span class="esc-ch__sub">' . esc_html( sprintf( /* translators: monto */ __( 'Al día · ya cobraste %s', 'malka-crm' ), Malka_CRM_Settings::money( $r['cobrado'] ) ) ) . '</span>';
			} else {
				$sub = '<span class="esc-ch__sub">' . esc_html( $r['ganadas'] ? __( 'Todo pagado', 'malka-crm' ) : '—' ) . '</span>';
			}
			printf(
				'<div class="esc-nh__cell"><span class="esc-nh__k">%1$s</span><b class="esc-ch__big%2$s">%3$s</b>%4$s</div>',
				esc_html__( 'Por cobrar', 'malka-crm' ),
				$r['vencido'] > 0 ? ' is-late' : '',
				esc_html( Malka_CRM_Settings::money( $r['por_cobrar'] ) ),
				$sub // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);

			list( $sello, $tono ) = self::sello_trabajo( $r );
			printf(
				'<div class="esc-nh__cell"><span class="esc-nh__k">%1$s</span><b class="esc-ch__big">%2$d</b><span class="esc-ch__sub"><span class="esc-st esc-st--%3$s">%4$s</span></span></div>',
				esc_html__( 'Negocios', 'malka-crm' ),
				count( $r['negocios'] ),
				esc_attr( $tono ),
				esc_html( $sello )
			);

			echo '</div></section>';
		}

		echo '<div class="esc-docw"><div class="esc-docw__main">';

		// Lo que viene con este cliente, arriba de todo.
		if ( $id && class_exists( 'Malka_CRM_Ideas' ) ) {
			echo Malka_CRM_Ideas::panel( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/* ── Sus datos ── */
		echo '<section class="esc-panel">';
		printf(
			'<div class="esc-ph"><div><h2>%1$s</h2><p>%2$s</p></div></div>',
			esc_html( $id ? __( 'Sus datos', 'malka-crm' ) : __( 'Cliente nuevo', 'malka-crm' ) ),
			esc_html__( 'El alias es para ti: es por donde lo vas a buscar', 'malka-crm' )
		);
		echo '<div class="esc-cf__g">';
		/*
		 * Dos grupos: la persona con la que habla y la empresa que factura.
		 * El RUT de la empresa es el que va en las facturas y abre el portal;
		 * el personal queda aparte para cuando hace falta (boletas, contratos).
		 */
		printf( '<h3 class="esc-cf__h">%s</h3>', esc_html__( 'Persona', 'malka-crm' ) );
		echo self::campo( 'nombre', __( 'Nombre', 'malka-crm' ), $c['nombre'], 'text', '', __( 'La persona con la que hablas', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'rut_persona', __( 'RUT personal', 'malka-crm' ), $c['rut_persona'], 'text', '', '12.345.678-9' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'alias', __( 'Alias', 'malka-crm' ), $c['alias'], 'text', '', __( '«la dentista»', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'email', __( 'Correo', 'malka-crm' ), $c['email'], 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'fono', __( 'Teléfono', 'malka-crm' ), $c['fono'], 'tel', '', '+56 9 …' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf( '<h3 class="esc-cf__h">%s</h3>', esc_html__( 'Empresa', 'malka-crm' ) );
		echo self::campo( 'empresa', __( 'Razón social', 'malka-crm' ), $c['empresa'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'rut', __( 'RUT empresa', 'malka-crm' ), $c['rut'], 'text', '', '76.123.456-7' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'giro', __( 'Giro', 'malka-crm' ), $c['giro'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'direccion', __( 'Dirección', 'malka-crm' ), $c['direccion'], 'text', '', __( 'Calle, número, oficina', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'ciudad', __( 'Comuna o ciudad', 'malka-crm' ), $c['ciudad'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf( '<h3 class="esc-cf__h">%s</h3>', esc_html__( 'Notas', 'malka-crm' ) );
		printf(
			'<p class="esc-f esc-f--full"><label class="screen-reader-text" for="cli-notas">%1$s</label>' .
			'<textarea class="esc-f__i esc-f__t" id="cli-notas" name="notas" rows="3" placeholder="%2$s">%3$s</textarea></p>',
			esc_html__( 'Notas', 'malka-crm' ),
			esc_attr__( 'Cómo paga, con quién hay que hablar, qué no le gusta…', 'malka-crm' ),
			esc_textarea( $c['notas'] )
		);
		echo '</div></section>';

		/* ── Su trabajo: negocios y cotizaciones ── */
		if ( $id ) {
			echo self::bloque_trabajo( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::bloque_tareas( $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( class_exists( 'Malka_CRM_Correos' ) ) {
				echo Malka_CRM_Correos::panel_cliente( $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		echo '</div><aside class="esc-docw__side">';

		if ( $id ) {
			echo self::bloque_portal( $id, $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			printf(
				'<section class="esc-panel"><div class="esc-ph"><div><h2>%1$s</h2><p>%2$s</p></div></div></section>',
				esc_html__( 'Se crea solo', 'malka-crm' ),
				esc_html__( 'Apenas escribas el nombre queda guardado, y después sigue guardándose solo. Los clientes también se crean solos cuando una cotización se acepta.', 'malka-crm' )
			);
		}

		echo '</aside></div>';

		// La barra la usa el guardado automático para saber que el formulario
		// se guarda; con guardado automático queda escondida.
		echo '<div class="esc-cfg__save esc-ed__save">';
		printf( '<a class="esc-btn" href="%1$s">%2$s</a>', esc_url( self::list_url() ), esc_html__( 'Volver', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Guardar', 'malka-crm' ) );
		echo '</div>';

		echo '</form>';

		return ob_get_clean();
	}

	/**
	 * Las tareas que el cliente tiene pendientes, en todos sus negocios,
	 * con un botón para recordárselas por correo.
	 *
	 * @param array $c Cliente.
	 * @return string
	 */
	private static function bloque_tareas( $c ) {
		if ( ! class_exists( 'Malka_CRM_Projects' ) ) {
			return '';
		}
		$pids = array();
		foreach ( self::cotizaciones_de( $c ) as $qid ) {
			$pids[] = (int) get_post_meta( $qid, '_malka_pm_project_id', true );
		}
		foreach ( get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_malka_pm_client_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $c['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		) as $pid ) {
			$pids[] = (int) $pid;
		}
		$tareas = Malka_CRM_Projects::tareas_de_cliente( array_filter( $pids ) );

		ob_start();
		printf( '<section class="esc-panel esc-ctk" data-nonce="%s">', esc_attr( wp_create_nonce( 'malka_crm_recordar_tarea' ) ) );
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Tareas pendientes del cliente', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html(
			$tareas
				? sprintf( /* translators: cantidad */ _n( '%d tarea a su cargo', '%d tareas a su cargo', count( $tareas ), 'malka-crm' ), count( $tareas ) )
				: __( 'Lo que le toque hacer en sus negocios aparece acá', 'malka-crm' )
		) . '</p></div></div>';

		if ( ! $tareas ) {
			echo '<p class="esc-empty">' . esc_html__( 'No tiene nada pendiente.', 'malka-crm' ) . '</p></section>';
			return ob_get_clean();
		}

		echo '<div class="esc-ctk__l">';
		foreach ( $tareas as $t ) {
			if ( null === $t['dias'] ) {
				$st = array( 'off', __( 'Sin fecha', 'malka-crm' ) );
			} elseif ( $t['dias'] < 0 ) {
				/* translators: días */
				$st = array( 'late', sprintf( _n( 'Atrasada %d día', 'Atrasada %d días', abs( $t['dias'] ), 'malka-crm' ), abs( $t['dias'] ) ) );
			} elseif ( 0 === $t['dias'] ) {
				$st = array( 'wait', __( 'Vence hoy', 'malka-crm' ) );
			} else {
				/* translators: fecha */
				$st = array( $t['dias'] <= 3 ? 'wait' : 'run', sprintf( __( 'Para el %s', 'malka-crm' ), date_i18n( 'j M', strtotime( $t['fin'] ) ) ) );
			}
			printf(
				'<div class="esc-ctk__i"><a class="esc-ctk__n" href="%1$s"><b>%2$s</b><small>%3$s</small></a><span class="esc-st esc-st--%4$s">%5$s</span>' .
				'<button type="button" class="esc-btn esc-btn--soft esc-ctk__r" data-recordar data-pid="%6$d" data-tid="%7$s">%8$s</button></div>',
				esc_url( $t['url'] ),
				esc_html( $t['nombre'] ),
				esc_html( trim( $t['negocio'] . ( $t['numero'] ? ' · ' . $t['numero'] : '' ) ) ),
				esc_attr( $st[0] ),
				esc_html( $st[1] ),
				(int) $t['pid'],
				esc_attr( $t['id'] ),
				esc_html__( 'Recordar', 'malka-crm' )
			);
		}
		echo '</div></section>';
		return ob_get_clean();
	}

	/**
	 * Sus negocios y cotizaciones, una fila por cada uno.
	 *
	 * @param array $r Panorama.
	 * @return string
	 */
	private static function bloque_trabajo( $r ) {
		ob_start();
		echo '<section class="esc-panel">';
		printf(
			'<div class="esc-ph"><div><h2>%1$s</h2><p>%2$s</p></div>',
			esc_html__( 'Su trabajo', 'malka-crm' ),
			esc_html__( 'Sus negocios y cotizaciones, de lo más nuevo a lo más viejo', 'malka-crm' )
		);
		if ( class_exists( 'Malka_CRM_Quote_Editor' ) ) {
			printf(
				'<div class="esc-ph__r"><a class="esc-btn esc-btn--soft" href="%1$s">+ %2$s</a></div>',
				esc_url( Malka_CRM_Quote_Editor::edit_url() ),
				esc_html__( 'Nueva cotización', 'malka-crm' )
			);
		}
		echo '</div>';

		if ( ! $r['negocios'] && ! $r['cotizaciones'] ) {
			printf( '<p class="esc-nl__vacio">%s</p></section>', esc_html__( 'Todavía no han trabajado juntos.', 'malka-crm' ) );
			return ob_get_clean();
		}

		echo '<div class="esc-ct">';

		foreach ( $r['negocios'] as $n ) {
			$tono = in_array( $n['estado'], array( 'entregado' ), true ) ? 'done' : ( 'pausado' === $n['estado'] ? 'off' : 'run' );
			printf(
				'<a class="esc-ct__r" href="%1$s"><i class="esc-ct__ic" aria-hidden="true">N</i>' .
				'<span class="esc-ct__t"><b>%2$s</b><span>%3$s</span></span>' .
				'<span class="esc-st esc-st--%4$s">%5$s</span>' .
				'<span class="esc-ct__m"><span class="esc-ct__bar" style="--p:%6$d"></span>%6$d%%</span></a>',
				esc_url( Malka_CRM_Projects::edit_url( $n['id'] ) ),
				esc_html( $n['titulo'] ),
				esc_html( trim( __( 'Negocio', 'malka-crm' ) . ( $n['numero'] ? ' ' . $n['numero'] : '' ) . ( $n['fin'] ? ' · ' . sprintf( /* translators: fecha */ __( 'entrega %s', 'malka-crm' ), date_i18n( 'j M', strtotime( $n['fin'] ) ) ) : '' ) ) ),
				esc_attr( $tono ),
				esc_html( $n['etiqueta'] ),
				(int) $n['avance']
			);
		}

		foreach ( $r['cotizaciones'] as $q ) {
			printf(
				'<a class="esc-ct__r" href="%1$s"><i class="esc-ct__ic esc-ct__ic--c" aria-hidden="true">C</i>' .
				'<span class="esc-ct__t"><b>%2$s</b><span>%3$s</span></span>' .
				'<span class="esc-st esc-st--%4$s">%5$s</span>' .
				'<span class="esc-ct__m"><b>%6$s</b></span></a>',
				esc_url( class_exists( 'Malka_CRM_Quote_Editor' ) ? Malka_CRM_Quote_Editor::edit_url( $q['id'] ) : get_edit_post_link( $q['id'] ) ),
				esc_html( $q['titulo'] ),
				esc_html( trim( sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $q['numero'] ? $q['numero'] : '—' ) . ' · ' . date_i18n( 'j M Y', strtotime( $q['fecha'] ) ) ) ),
				esc_attr( self::tono( $q['estado'] ) ),
				esc_html( $q['etiqueta'] ),
				esc_html( Malka_CRM_Settings::money( $q['total'] ) )
			);
		}

		echo '</div></section>';

		return ob_get_clean();
	}

	/**
	 * El portal del cliente, compacto, en la columna de la derecha.
	 *
	 * @param int   $id Cliente.
	 * @param array $c  Cliente.
	 * @return string
	 */
	private static function bloque_portal( $id, $c ) {
		if ( ! class_exists( 'Malka_CRM_Portal' ) ) {
			return '';
		}

		$url    = Malka_CRM_Portal::url( $id );
		$activo = Malka_CRM_Portal::activo( $id );
		$codigo = Malka_CRM_Portal::codigo( $id );
		$propio = (string) get_post_meta( $id, Malka_CRM_Portal::META_CODIGO, true );

		ob_start();
		echo '<section class="esc-panel esc-pt">';
		printf(
			'<div class="esc-ph"><div><h2>%1$s</h2><p>%2$s</p></div>%3$s</div>',
			esc_html__( 'Su portal', 'malka-crm' ),
			esc_html__( 'Donde ve lo suyo sin tener que preguntarte', 'malka-crm' ),
			'' !== trim( (string) $c['rut'] )
				? sprintf( '<span class="esc-st esc-st--%1$s">%2$s</span>', $activo ? 'done' : 'off', esc_html( $activo ? __( 'Abierto', 'malka-crm' ) : __( 'Cerrado', 'malka-crm' ) ) )
				: ''
		);

		if ( '' === trim( (string) $c['rut'] ) ) {
			printf( '<p class="esc-f__h">%s</p></section>', esc_html__( 'Para abrirle el portal necesita RUT: la dirección es su propio RUT.', 'malka-crm' ) );
			return ob_get_clean();
		}

		printf(
			'<div class="esc-pt__url"><a href="%1$s" target="_blank" rel="noopener">%2$s</a><button type="button" class="esc-pt__copy" data-copiar="%1$s">%3$s</button></div>',
			esc_url( $url ),
			esc_html( preg_replace( '#^https?://#', '', $url ) ),
			esc_html__( 'Copiar', 'malka-crm' )
		);

		printf(
			'<p class="esc-f"><label class="esc-f__l" for="cli-codigo">%1$s</label>' .
			'<span class="esc-f__w"><input class="esc-f__i" type="text" id="cli-codigo" name="codigo" value="%2$s" placeholder="%3$s" /></span>' .
			'<span class="esc-f__h">%4$s</span></p>',
			esc_html__( 'Código de entrada', 'malka-crm' ),
			esc_attr( $propio ),
			esc_attr( $codigo ),
			esc_html__( 'Vacío, son los 4 dígitos del RUT antes del guion: evita que alguien entre por error. Para algo más seguro, escribe tu propio código.', 'malka-crm' )
		);

		$accion = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'malka_crm_portal_activar',
					'cliente' => (int) $id,
				),
				admin_url( 'admin-post.php' )
			),
			'malka_crm_portal_activar_' . $id
		);

		if ( $activo ) {
			printf(
				'<p class="esc-pt__acc"><a class="esc-btn esc-btn--soft" href="%1$s">%2$s</a><span class="esc-f__h">%3$s</span></p>',
				esc_url( $accion ),
				esc_html__( 'Cerrar el portal', 'malka-crm' ),
				esc_html(
					is_email( $c['email'] )
						/* translators: correo del cliente */
						? sprintf( __( 'Le avisamos a %s.', 'malka-crm' ), $c['email'] )
						: __( 'Sin correo en la ficha: no se le pudo avisar.', 'malka-crm' )
				)
			);
		} else {
			printf(
				'<p class="esc-pt__acc"><a class="esc-btn esc-btn--primary" href="%1$s">%2$s</a><span class="esc-f__h">%3$s</span></p>',
				esc_url( $accion ),
				esc_html__( 'Abrir su portal', 'malka-crm' ),
				esc_html(
					is_email( $c['email'] )
						? __( 'Le llega la dirección y su código por correo.', 'malka-crm' )
						: __( 'Necesita un correo en la ficha para avisarle.', 'malka-crm' )
				)
			);
		}

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Un campo del formulario.
	 *
	 * @param string $n     Nombre.
	 * @param string $et    Etiqueta.
	 * @param string $v     Valor.
	 * @param string $tipo  Tipo.
	 * @param string $ayuda Ayuda.
	 * @return string
	 */
	private static function campo( $n, $et, $v, $tipo = 'text', $ayuda = '', $ejemplo = '' ) {
		return sprintf(
			'<p class="esc-f"><label class="esc-f__l" for="cli-%1$s">%2$s</label>' .
			'<span class="esc-f__w"><input class="esc-f__i" type="%3$s" id="cli-%1$s" name="%1$s" value="%4$s"%6$s /></span>%5$s</p>',
			esc_attr( $n ),
			esc_html( $et ),
			esc_attr( $tipo ),
			esc_attr( $v ),
			$ayuda ? '<span class="esc-f__h">' . esc_html( $ayuda ) . '</span>' : '',
			$ejemplo ? ' placeholder="' . esc_attr( $ejemplo ) . '"' : ''
		);
	}

	/* ─────────────────────────────  Guardar  ────────────────────────── */

	public static function handle_save() {
		check_admin_referer( 'malka_crm_guardar_cliente' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$id     = isset( $_POST['cliente'] ) ? absint( $_POST['cliente'] ) : 0;
		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$empresa = isset( $_POST['empresa'] ) ? sanitize_text_field( wp_unslash( $_POST['empresa'] ) ) : '';

		if ( '' === $nombre && '' === $empresa ) {
			if ( ! empty( $_POST['_auto'] ) ) {
				wp_send_json_error( array( 'aviso' => __( 'Falta el nombre.', 'malka-crm' ) ) );
			}
			wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'cli_no', self::list_url() ) );
			exit;
		}

		if ( $id ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $nombre ? $nombre : $empresa,
				)
			);
		} else {
			$id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_title'  => $nombre ? $nombre : $empresa,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $id ) ) {
				wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'cli_no', self::list_url() ) );
				exit;
			}
		}

		$textos = array(
			'empresa' => '_malka_pm_cli_empresa',
			'rut'     => self::META_RUT,
			'alias'   => self::META_ALIAS,
			'fono'    => '_malka_pm_cli_fono',
			'ciudad'  => '_malka_pm_cli_ciudad',
			'giro'    => '_malka_pm_cli_giro',
			'rut_persona' => '_malka_pm_cli_rut_persona',
			'direccion'   => '_malka_pm_cli_direccion',
		);

		/*
		 * Sólo se escribe lo que el formulario trajo. Antes se escribían todos
		 * los campos siempre, así que un guardado que no incluyera uno —otra
		 * pantalla, un formulario recortado— lo dejaba en blanco sin que nadie
		 * lo hubiera borrado. Ya pasó una vez con el correo del cliente.
		 */
		foreach ( $textos as $campo => $meta ) {
			if ( isset( $_POST[ $campo ] ) ) {
				update_post_meta( $id, $meta, sanitize_text_field( wp_unslash( $_POST[ $campo ] ) ) );
			}
		}

		if ( isset( $_POST['email'] ) ) {
			update_post_meta( $id, '_malka_pm_cli_email', sanitize_email( wp_unslash( $_POST['email'] ) ) );
		}

		if ( isset( $_POST['notas'] ) ) {
			update_post_meta( $id, '_malka_pm_cli_notas', sanitize_textarea_field( wp_unslash( $_POST['notas'] ) ) );
		}

		/*
		 * El código del portal sólo se toca si el formulario lo trajo: el
		 * campo aparece recién en las fichas ya guardadas, y un cliente nuevo
		 * no tiene que perderlo al segundo guardado.
		 */
		if ( isset( $_POST['codigo'] ) && class_exists( 'Malka_CRM_Portal' ) ) {
			$codigo = trim( sanitize_text_field( wp_unslash( $_POST['codigo'] ) ) );

			if ( '' === $codigo ) {
				delete_post_meta( $id, Malka_CRM_Portal::META_CODIGO );
			} else {
				update_post_meta( $id, Malka_CRM_Portal::META_CODIGO, $codigo );
			}
		}

		// El guardado automático espera datos, no una página.
		if ( ! empty( $_POST['_auto'] ) ) {
			wp_send_json_success(
				array(
					'id'  => (int) $id,
					'url' => self::edit_url( $id ),
				)
			);
		}

		wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'cli_ok', self::edit_url( $id ) ) );
		exit;
	}

	public static function handle_delete() {
		$id = isset( $_REQUEST['cliente'] ) ? absint( $_REQUEST['cliente'] ) : 0;

		check_admin_referer( 'malka_crm_borrar_cliente_' . $id );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$p = get_post( $id );

		if ( $p && self::POST_TYPE === $p->post_type ) {
			wp_trash_post( $id );
		}

		wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'cli_fuera', self::list_url() ) );
		exit;
	}

	/**
	 * Ficha de una vez a los que ya estaban en cotizaciones.
	 */
	public static function handle_sync() {
		check_admin_referer( 'malka_crm_fichar_clientes' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$n = self::sincronizar();

		wp_safe_redirect(
			add_query_arg(
				array(
					'malka_crm_msg'  => 'cli_fichados',
					'malka_crm_sent' => (int) $n,
				),
				self::list_url()
			)
		);
		exit;
	}

	/**
	 * Junta las fichas repetidas y vuelve a la lista.
	 */
	public static function handle_merge() {
		check_admin_referer( 'malka_crm_juntar_clientes' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$n = self::fusionar_duplicadas();

		wp_safe_redirect(
			add_query_arg(
				array(
					'malka_crm_msg'  => 'cli_juntados',
					'malka_crm_sent' => (int) $n,
				),
				self::list_url()
			)
		);
		exit;
	}

	/**
	 * El acuse de lo que acaba de pasar.
	 *
	 * @return string
	 */
	public static function mensaje() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['malka_crm_msg'] ) ? sanitize_key( wp_unslash( $_GET['malka_crm_msg'] ) ) : '';
		$n   = isset( $_GET['malka_crm_sent'] ) ? absint( $_GET['malka_crm_sent'] ) : 0;
		$f   = isset( $_GET['malka_crm_fail'] ) ? absint( $_GET['malka_crm_fail'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$textos = array(
			'cli_ok'    => array( 'ok', __( 'Ficha guardada.', 'malka-crm' ) ),
			'cli_fuera' => array( 'ok', __( 'La ficha quedó en la papelera.', 'malka-crm' ) ),
			'cli_no'    => array( 'no', __( 'Una ficha necesita al menos un nombre o una empresa.', 'malka-crm' ) ),
			'cli_juntados' => array(
				'ok',
				sprintf(
					/* translators: cuántas fichas se juntaron */
					_n( 'Se juntó %d ficha repetida. Sus cotizaciones y negocios quedaron en la que se mantuvo.', 'Se juntaron %d fichas repetidas. Sus cotizaciones y negocios quedaron en las que se mantuvieron.', $n, 'malka-crm' ),
					$n
				),
			),
			'comunicado' => array(
				$n ? 'ok' : 'no',
				sprintf( /* translators: cuántos */ _n( 'Correo enviado a %d cliente.', 'Correo enviado a %d clientes.', $n, 'malka-crm' ), $n ) .
				( $f ? ' ' . sprintf( /* translators: cuántos */ _n( '%d no salió: revisa su correo en la ficha o que el sitio pueda enviar correos.', '%d no salieron: revisa sus correos en las fichas o que el sitio pueda enviar correos.', $f, 'malka-crm' ), $f ) : '' ),
			),
			'portal_on'  => array( 'ok', __( 'Portal abierto. Al cliente le llegó su dirección y su código por correo.', 'malka-crm' ) ),
			'portal_off' => array( 'ok', __( 'Portal cerrado. Si vuelve a entrar con el enlace, ya no verá nada.', 'malka-crm' ) ),
			'portal_sin_rut'    => array( 'no', __( 'Para abrirle el portal necesita RUT: la dirección es su propio RUT.', 'malka-crm' ) ),
			'portal_sin_correo' => array( 'no', __( 'Ponle un correo en la ficha y vuelve a intentarlo: sin correo no hay cómo mandarle el acceso.', 'malka-crm' ) ),
			'portal_sin_envio'  => array( 'no', __( 'El portal quedó abierto, pero el correo no salió. Pásale tú la dirección y el código.', 'malka-crm' ) ),
			'cli_fichados' => array(
				'ok',
				sprintf(
					/* translators: cuántos clientes se ficharon */
					_n( 'Se fichó %d cliente desde tus cotizaciones.', 'Se ficharon %d clientes desde tus cotizaciones.', $n, 'malka-crm' ),
					$n
				),
			),
		);

		if ( ! isset( $textos[ $msg ] ) ) {
			return '';
		}

		list( $tipo, $texto ) = $textos[ $msg ];

		return sprintf( '<p class="esc-ok%1$s">%2$s</p>', 'no' === $tipo ? ' esc-ok--no' : '', esc_html( $texto ) );
	}
}
