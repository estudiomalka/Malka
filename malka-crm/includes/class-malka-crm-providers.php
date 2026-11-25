<?php
/**
 * Proveedores: a quién le compra el estudio y con qué datos se le factura.
 *
 * Cada proveedor guarda dos juegos de datos: cómo aparece en sus facturas
 * (nombre, identificación tributaria, dirección) y con qué datos se emite la
 * factura de compra en el SII, que no siempre coinciden. La ficha es la
 * referencia: una factura nueva de ese proveedor se completa desde acá, y lo
 * que se corrige en una factura vuelve a la ficha para la próxima.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Las fichas de proveedores.
 */
class Malka_CRM_Providers {

	const POST_TYPE = 'malka_proveedor';

	/** Los campos de la ficha, cada uno con su meta. */
	const CAMPOS = array(
		'alias'      => '_malka_pm_prov_alias',
		'tax'        => '_malka_pm_prov_tax',
		'giro'       => '_malka_pm_prov_giro',
		'dir'        => '_malka_pm_prov_dir',
		'comuna'     => '_malka_pm_prov_comuna',
		'ciudad'     => '_malka_pm_prov_ciudad',
		'pais'       => '_malka_pm_prov_pais',
		'moneda'     => '_malka_pm_prov_moneda',
		'email'      => '_malka_pm_prov_email',
		'web'        => '_malka_pm_prov_web',
		'notas'      => '_malka_pm_prov_notas',
		'sii_nombre' => '_malka_pm_prov_sii_nombre',
		'sii_rut'    => '_malka_pm_prov_sii_rut',
		'sii_giro'   => '_malka_pm_prov_sii_giro',
		'sii_dir'    => '_malka_pm_prov_sii_dir',
		'sii_comuna' => '_malka_pm_prov_sii_comuna',
		'sii_ciudad' => '_malka_pm_prov_sii_ciudad',
		'sii_origen' => '_malka_pm_prov_sii_origen',
	);

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'admin_post_malka_crm_guardar_proveedor', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_malka_crm_borrar_proveedor', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_malka_crm_borrar_proveedores', array( __CLASS__, 'handle_delete_varios' ) );
	}

	/**
	 * El tipo de contenido, privado.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Proveedores', 'malka-crm' ),
					'singular_name' => __( 'Proveedor', 'malka-crm' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title' ),
			)
		);
	}

	/* ─────────────────────────────  Datos  ──────────────────────────── */

	/**
	 * Una ficha.
	 *
	 * @param int $id Proveedor.
	 * @return array
	 */
	public static function get( $id ) {
		$p = array(
			'id'     => (int) $id,
			'nombre' => get_the_title( $id ),
		);
		foreach ( self::CAMPOS as $k => $meta ) {
			$p[ $k ] = (string) get_post_meta( $id, $meta, true );
		}
		return $p;
	}

	/**
	 * Una ficha vacía.
	 *
	 * @return array
	 */
	public static function vacio() {
		$p = array(
			'id'     => 0,
			'nombre' => '',
		);
		foreach ( array_keys( self::CAMPOS ) as $k ) {
			$p[ $k ] = '';
		}
		return $p;
	}

	/**
	 * Todos, por nombre.
	 *
	 * @return array[]
	 */
	public static function all() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		return array_map( array( __CLASS__, 'get' ), $ids );
	}

	/**
	 * El nombre sin mayúsculas, tildes, puntuación ni «Inc», «Ltd»…
	 *
	 * @param string $n Nombre.
	 * @return string
	 */
	public static function llave( $n ) {
		if ( class_exists( 'Malka_CRM_SII' ) ) {
			return implode( ' ', Malka_CRM_SII::palabras( $n ) );
		}
		return strtolower( trim( remove_accents( (string) $n ) ) );
	}

	/**
	 * La ficha de un proveedor por su nombre (o uno de sus otros nombres).
	 *
	 * @param string $nombre Como viene en la factura.
	 * @return int 0 si no hay.
	 */
	public static function buscar( $nombre ) {
		$k = self::llave( $nombre );
		if ( '' === $k ) {
			return 0;
		}

		foreach ( self::all() as $p ) {
			$nombres = array_merge( array( $p['nombre'], $p['sii_nombre'] ), array_map( 'trim', explode( ',', $p['alias'] ) ) );
			foreach ( $nombres as $n ) {
				if ( '' !== trim( $n ) && self::llave( $n ) === $k ) {
					return (int) $p['id'];
				}
			}
		}

		// Si no calza exacto, el más parecido («Anthropic» → «Anthropic, PBC»).
		$a = explode( ' ', $k );
		$mejor = 0;
		$punt  = 0.0;
		foreach ( self::all() as $p ) {
			foreach ( array_merge( array( $p['nombre'], $p['sii_nombre'] ), array_map( 'trim', explode( ',', $p['alias'] ) ) ) as $n ) {
				$b = array_filter( explode( ' ', self::llave( $n ) ) );
				if ( ! $b ) {
					continue;
				}
				$x = count( array_intersect( $a, $b ) ) / count( array_unique( array_merge( $a, $b ) ) );
				if ( $a[0] === reset( $b ) ) {
					$x += 0.25;
				}
				if ( $x > $punt ) {
					$punt  = $x;
					$mejor = (int) $p['id'];
				}
			}
		}

		return $punt >= 0.75 ? $mejor : 0;
	}

	/**
	 * Crea una ficha.
	 *
	 * @param string $nombre Nombre.
	 * @param array  $datos  Campos.
	 * @return int
	 */
	public static function crear( $nombre, $datos = array() ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $nombre,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		self::poner( $id, $datos );
		return (int) $id;
	}

	/**
	 * Escribe campos en la ficha. Sólo los que vienen con algo: una factura
	 * con un dato en blanco no borra lo que la ficha ya sabía.
	 *
	 * @param int   $id    Proveedor.
	 * @param array $datos Campos.
	 */
	public static function poner( $id, $datos ) {
		foreach ( self::CAMPOS as $k => $meta ) {
			if ( isset( $datos[ $k ] ) && '' !== trim( (string) $datos[ $k ] ) ) {
				update_post_meta( $id, $meta, sanitize_text_field( (string) $datos[ $k ] ) );
			}
		}
	}

	/**
	 * Lo que se corrigió en una factura vuelve a la ficha de su proveedor,
	 * para que la del mes siguiente salga con eso.
	 *
	 * @param array $d Documento (Malka_CRM_Documents::get).
	 * @return int La ficha.
	 */
	public static function desde_documento( $d ) {
		if ( 'compra' !== $d['sentido'] || '' === trim( (string) $d['cliente'] ) ) {
			return 0;
		}

		$id = ! empty( $d['proveedor_id'] ) && self::POST_TYPE === get_post_type( (int) $d['proveedor_id'] )
			? (int) $d['proveedor_id']
			: self::buscar( $d['cliente'] );

		$datos = array(
			'tax'        => $d['rut'],
			'giro'       => $d['giro'],
			'dir'        => $d['dir'],
			'comuna'     => $d['comuna'],
			'ciudad'     => $d['ciudad'],
			'moneda'     => $d['moneda'],
			'sii_nombre' => $d['sii_nombre'],
			'sii_rut'    => $d['sii_rut'],
			'sii_giro'   => $d['sii_giro'],
			'sii_dir'    => $d['sii_dir'],
			'sii_comuna' => $d['sii_comuna'],
			'sii_ciudad' => $d['sii_ciudad'],
			'sii_origen' => $d['sii_origen'],
		);

		if ( ! $id ) {
			return self::crear( $d['cliente'], $datos );
		}

		self::poner( $id, $datos );
		return $id;
	}

	/**
	 * Las facturas de un proveedor, de la más nueva a la más vieja.
	 *
	 * @param array $p Ficha.
	 * @return array[]
	 */
	public static function documentos( $p ) {
		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return array();
		}
		$k   = self::llave( $p['nombre'] );
		$out = array();
		foreach ( Malka_CRM_Documents::all() as $d ) {
			if ( 'compra' !== $d['sentido'] ) {
				continue;
			}
			if ( (int) $d['proveedor_id'] === (int) $p['id'] || ( $k && self::llave( $d['cliente'] ) === $k ) ) {
				$out[] = $d;
			}
		}
		return $out;
	}

	/* ───────────────────────────  Direcciones  ──────────────────────── */

	public static function list_url() {
		return Malka_CRM_Dashboard::view_url( 'proveedores' );
	}

	public static function edit_url( $id = 0 ) {
		$u = Malka_CRM_Dashboard::view_url( 'proveedor' );
		return $id ? add_query_arg( 'id', (int) $id, $u ) : $u;
	}

	private static function current_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		return ( $id && self::POST_TYPE === get_post_type( $id ) ) ? $id : 0;
	}

	/* ─────────────────────────────  Lista  ──────────────────────────── */

	/**
	 * La lista de proveedores.
	 *
	 * @return string
	 */
	public static function render_list() {
		$todos = self::all();

		ob_start();
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['borrado'] ) ) {
			$n = (int) $_GET['borrado']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<p class="esc-ok">' . esc_html( $n > 1 ? sprintf( /* translators: cuántos */ __( '%d proveedores quedaron en la papelera.', 'malka-crm' ), $n ) : __( 'El proveedor quedó en la papelera.', 'malka-crm' ) ) . '</p>';
		}

		echo '<section class="esc-panel">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Tus proveedores', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'A quién le compras y con qué datos se le emite la factura de compra en el SII', 'malka-crm' ) . '</p></div>';
		printf(
			'<div class="esc-ph__r"><a class="esc-btn esc-btn--primary" href="%1$s">+ %2$s</a></div></div>',
			esc_url( self::edit_url() ),
			esc_html__( 'Nuevo proveedor', 'malka-crm' )
		);

		if ( ! $todos ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no hay proveedores. Se crean solos al arrastrar una factura del extranjero en Finanzas › Documentos › Facturas de compra por emitir, o puedes agregarlos acá.', 'malka-crm' ) . '</p>';
			echo '</section></div>';
			return ob_get_clean();
		}

		echo '<div class="esc-nl__bar"><span></span>';
		printf(
			'<input type="search" class="esc-nl__q" id="esc-nl-q" placeholder="%1$s" aria-label="%1$s" autocomplete="off" />',
			esc_attr__( 'Buscar proveedor, RUT o giro', 'malka-crm' )
		);
		echo '</div>';

		printf( '<form method="post" action="%s" id="esc-pv-sel">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'malka_crm_borrar_proveedores' );
		echo '<input type="hidden" name="action" value="malka_crm_borrar_proveedores" />';
		printf(
			'<div class="esc-pv__barra" id="esc-pv-barra" hidden><span id="esc-pv-n"></span><button type="button" class="esc-btn esc-btn--ghost" id="esc-pv-nada">%1$s</button><button type="submit" class="esc-btn esc-btn--primary">%2$s</button></div>',
			esc_html__( 'Quitar selección', 'malka-crm' ),
			esc_html__( 'Mandar a la papelera', 'malka-crm' )
		);
		echo '<div class="esc-nl__list" id="esc-nl">';
		echo '<div class="esc-nl esc-nl--h esc-pv">' .
			'<span class="esc-pv__sel"><input type="checkbox" id="esc-pv-todos" aria-label="' . esc_attr__( 'Elegir todos', 'malka-crm' ) . '" /></span>' .
			'<span>' . esc_html__( 'Proveedor', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'En el SII', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Giro', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Facturas', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Última', 'malka-crm' ) . '</span><span></span></div>';

		foreach ( $todos as $p ) {
			$docs  = self::documentos( $p );
			$ult   = $docs ? $docs[0] : null;
			$total = 0;
			foreach ( $docs as $d ) {
				$total += (int) $d['total'];
			}

			printf(
				'<div class="esc-nl esc-pv" data-grupos="todos" data-buscar="%1$s">' .
				'<span class="esc-pv__sel"><input type="checkbox" name="ids[]" value="%13$d" aria-label="%14$s" /></span>' .
				'<span class="esc-nl__p"><a class="esc-pv__a" href="%2$s">%3$s</a><span>%4$s</span></span>' .
				'<span class="esc-nl__p"><b>%5$s</b><span>%6$s</span></span>' .
				'<span class="esc-nl__p"><b>%7$s</b><span>%8$s</span></span>' .
				'<span class="esc-nl__p"><b>%9$d</b><span>%10$s</span></span>' .
				'<span class="esc-nl__p"><b>%11$s</b><span>%12$s</span></span>' .
				'<a class="esc-ico esc-ico--bin esc-pv__bin" href="%15$s" data-confirmar="%16$s" title="%17$s" aria-label="%17$s"><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></a></div>',
				esc_attr( strtolower( remove_accents( implode( ' ', array( $p['nombre'], $p['sii_nombre'], $p['sii_rut'], $p['tax'], $p['giro'], $p['sii_giro'], $p['alias'] ) ) ) ) ),
				esc_url( self::edit_url( $p['id'] ) ),
				esc_html( $p['nombre'] ),
				esc_html( implode( ' · ', array_filter( array( $p['tax'], $p['ciudad'], $p['pais'] ) ) ) ),
				esc_html( $p['sii_rut'] ? $p['sii_rut'] : '—' ),
				esc_html( $p['sii_nombre'] ),
				esc_html( $p['sii_giro'] ? $p['sii_giro'] : ( $p['giro'] ? $p['giro'] : '—' ) ),
				esc_html( $p['moneda'] ? $p['moneda'] : '' ),
				count( $docs ),
				esc_html( $total ? Malka_CRM_Settings::money( $total ) : '' ),
				esc_html( $ult && $ult['fecha'] ? date_i18n( 'd/m/Y', strtotime( $ult['fecha'] ) ) : '—' ),
				esc_html( $ult && $ult['folio'] ? 'N° ' . $ult['folio'] : '' ),
				(int) $p['id'],
				esc_attr( sprintf( /* translators: proveedor */ __( 'Elegir %s', 'malka-crm' ), $p['nombre'] ) ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=malka_crm_borrar_proveedor&proveedor=' . $p['id'] ), 'malka_crm_borrar_proveedor' ) ),
				esc_attr( sprintf( /* translators: proveedor */ __( '¿Mandar a %s a la papelera? Sus facturas no se tocan.', 'malka-crm' ), $p['nombre'] ) ),
				esc_attr__( 'Mandar a la papelera', 'malka-crm' )
			);
		}

		printf( '<p class="esc-nl__vacio" hidden>%s</p>', esc_html__( 'Ningún proveedor calza con eso.', 'malka-crm' ) );
		echo '</div></form></section></div>';

		return ob_get_clean();
	}

	/* ─────────────────────────────  Ficha  ──────────────────────────── */

	/**
	 * Un campo.
	 */
	private static function campo( $n, $rot, $v, $tipo = 'text', $ayuda = '', $id = '' ) {
		return sprintf(
			'<p class="esc-f"><label class="esc-f__l" for="%1$s">%2$s</label><span class="esc-f__w"><input class="esc-f__i" type="%3$s" id="%1$s" name="%4$s" value="%5$s" /></span>%6$s</p>',
			esc_attr( $id ? $id : 'prov-' . $n ),
			esc_html( $rot ),
			esc_attr( $tipo ),
			esc_attr( $n ),
			esc_attr( $v ),
			$ayuda ? '<span class="esc-f__h">' . esc_html( $ayuda ) . '</span>' : ''
		);
	}

	/**
	 * La ficha de un proveedor: sus datos a la izquierda, los del SII a la
	 * derecha, y abajo sus facturas.
	 *
	 * @return string
	 */
	public static function render_form() {
		$id = self::current_id();
		$p  = $id ? self::get( $id ) : self::vacio();

		ob_start();
		printf(
			'<form class="esc-cfg esc-cfg--sola esc-vista--total esc-pvf" method="post" action="%1$s" data-auto-nuevo="#prov-nombre" data-auto-msg="%2$s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr__( 'Se guarda solo apenas pongas el nombre', 'malka-crm' )
		);
		wp_nonce_field( 'malka_crm_guardar_proveedor', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_guardar_proveedor" />';
		printf( '<input type="hidden" name="proveedor" value="%d" />', (int) $id );

		echo '<div class="esc-pvf__dos">';

		/* Izquierda: como aparece en sus facturas. */
		echo '<section class="esc-panel">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Como aparece en sus facturas', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sus datos tal cual vienen en lo que te manda', 'malka-crm' ) . '</p></div>';
		if ( $id ) {
			printf(
				'<a class="esc-nh__bin" href="%1$s" data-confirmar="%2$s" title="%3$s" aria-label="%3$s">' .
				'<svg class="esc-x-ic" viewBox="0 0 16 16" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=malka_crm_borrar_proveedor&proveedor=' . $id ), 'malka_crm_borrar_proveedor' ) ),
				esc_attr__( '¿Mandar este proveedor a la papelera? Sus facturas no se tocan.', 'malka-crm' ),
				esc_attr__( 'Mandar a la papelera', 'malka-crm' )
			);
		}
		echo '</div>';
		echo self::campo( 'nombre', __( 'Nombre', 'malka-crm' ), $p['nombre'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="esc-grid2">';
		echo self::campo( 'tax', __( 'RUT o identificación tributaria', 'malka-crm' ), $p['tax'], 'text', __( 'El VAT, Tax ID o RUT que trae su factura.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'giro', __( 'Giro', 'malka-crm' ), $p['giro'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo self::campo( 'dir', __( 'Dirección', 'malka-crm' ), $p['dir'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="esc-grid2">';
		echo self::campo( 'comuna', __( 'Comuna', 'malka-crm' ), $p['comuna'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'ciudad', __( 'Ciudad', 'malka-crm' ), $p['ciudad'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'pais', __( 'País', 'malka-crm' ), $p['pais'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<p class="esc-f"><label class="esc-f__l" for="prov-moneda">%1$s</label><span class="esc-f__w"><select class="esc-f__i" id="prov-moneda" name="moneda">%2$s</select></span></p>',
			esc_html__( 'Moneda en que factura', 'malka-crm' ),
			implode(
				'',
				array_map(
					function ( $m ) use ( $p ) {
						return sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $m ), selected( $p['moneda'], $m, false ), esc_html( '' === $m ? '—' : $m ) );
					},
					array( '', 'USD', 'EUR', 'CLP' )
				)
			)
		);
		echo self::campo( 'email', __( 'Correo', 'malka-crm' ), $p['email'], 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'web', __( 'Sitio web', 'malka-crm' ), $p['web'], 'url' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo self::campo( 'alias', __( 'Otros nombres', 'malka-crm' ), $p['alias'], 'text', __( 'Separados por coma. Si sus facturas a veces dicen otro nombre, así igual lo reconoce.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';

		/* Derecha: con qué datos se emite en el SII. */
		echo '<section class="esc-panel esc-pvf__sii">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Para emitir en el SII', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Con esto se completa sola cada factura nueva de este proveedor', 'malka-crm' ) . '</p></div></div>';
		echo self::campo( 'sii_nombre', __( 'Razón social', 'malka-crm' ), $p['sii_nombre'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="esc-grid2">';
		echo self::campo( 'sii_rut', __( 'RUT en el SII', 'malka-crm' ), $p['sii_rut'], 'text', 'generico' === $p['sii_origen'] ? __( 'No está inscrito: RUT genérico.', 'malka-crm' ) : ( 'sii' === $p['sii_origen'] ? __( 'De la nómina de prestadores extranjeros del SII.', 'malka-crm' ) : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'sii_giro', __( 'Giro', 'malka-crm' ), $p['sii_giro'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo self::campo( 'sii_dir', __( 'Dirección', 'malka-crm' ), $p['sii_dir'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="esc-grid2">';
		echo self::campo( 'sii_comuna', __( 'Comuna', 'malka-crm' ), $p['sii_comuna'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'sii_ciudad', __( 'Ciudad', 'malka-crm' ), $p['sii_ciudad'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo '<p class="esc-f"><label class="esc-f__l" for="prov-notas">' . esc_html__( 'Notas', 'malka-crm' ) . '</label><span class="esc-f__w"><textarea class="esc-f__i" id="prov-notas" name="notas" rows="3">' . esc_textarea( $p['notas'] ) . '</textarea></span></p>';
		echo '</section>';

		echo '</div>';

		/* Sus facturas. */
		if ( $id ) {
			$docs = self::documentos( $p );
			echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Sus facturas', 'malka-crm' ) . '</h2>';
			echo '<p>' . esc_html__( 'Las compras anotadas de este proveedor', 'malka-crm' ) . '</p></div></div>';
			if ( ! $docs ) {
				echo '<p class="esc-empty">' . esc_html__( 'Todavía ninguna.', 'malka-crm' ) . '</p>';
			} else {
				echo '<div class="esc-pvf__docs">';
				foreach ( $docs as $d ) {
					$foraneo = $d['moneda'] && 'CLP' !== $d['moneda'] && $d['original'] > 0;
					printf(
						'<a class="esc-pvf__doc" href="%1$s"><span>%2$s</span><b>%3$s</b><span>%4$s</span><span class="esc-st esc-st--%5$s">%6$s</span></a>',
						esc_url( Malka_CRM_Documents::edit_url( $d['id'] ) ),
						esc_html( $d['fecha'] ? date_i18n( 'd/m/Y', strtotime( $d['fecha'] ) ) : '—' ),
						esc_html( $d['folio'] ? 'N° ' . $d['folio'] : __( 'sin folio', 'malka-crm' ) ),
						esc_html( $foraneo ? $d['moneda'] . ' ' . number_format_i18n( $d['original'], 2 ) . ' · ' . Malka_CRM_Settings::money( $d['base'] ) : Malka_CRM_Settings::money( $d['total'] ) ),
						'pendiente' === $d['emito'] ? 'wait' : 'done',
						esc_html( 'pendiente' === $d['emito'] ? __( 'Por emitir', 'malka-crm' ) : ( 'emitida' === $d['emito'] ? __( 'Emitida', 'malka-crm' ) : __( 'Registrada', 'malka-crm' ) ) )
					);
				}
				echo '</div>';
			}
			echo '</section>';
		}

		echo '<div class="esc-cfg__save esc-ed__save"><span>' . esc_html__( 'Proveedor', 'malka-crm' ) . '</span><button type="submit" class="esc-btn esc-btn--primary">' . esc_html__( 'Guardar', 'malka-crm' ) . '</button></div>';
		echo '</form>';

		return ob_get_clean();
	}

	/* ────────────────────────────  Guardado  ────────────────────────── */

	public static function handle_save() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}
		check_admin_referer( 'malka_crm_guardar_proveedor' );

		$id     = isset( $_POST['proveedor'] ) ? absint( $_POST['proveedor'] ) : 0;
		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$auto   = ! empty( $_POST['_auto'] );

		if ( '' === $nombre ) {
			if ( $auto ) {
				wp_send_json_error( array( 'aviso' => __( 'Falta el nombre.', 'malka-crm' ) ) );
			}
			wp_safe_redirect( self::list_url() );
			exit;
		}

		if ( $id && self::POST_TYPE === get_post_type( $id ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $nombre,
				)
			);
		} else {
			// Si ya hay una ficha con ese nombre, se usa esa: nunca dos del mismo.
			$id = self::buscar( $nombre );
			if ( ! $id ) {
				$id = self::crear( $nombre );
			}
		}

		// Acá sí se puede dejar un campo en blanco: es la ficha, no una factura.
		foreach ( self::CAMPOS as $k => $meta ) {
			if ( 'sii_origen' === $k || ! isset( $_POST[ $k ] ) ) {
				continue;
			}
			$v = 'notas' === $k ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			update_post_meta( $id, $meta, $v );
		}

		if ( $auto ) {
			wp_send_json_success(
				array(
					'id'  => (int) $id,
					'url' => self::edit_url( $id ),
				)
			);
		}

		wp_safe_redirect( self::edit_url( $id ) );
		exit;
	}

	/**
	 * Varios a la papelera de una vez (los elegidos en la lista).
	 */
	public static function handle_delete_varios() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}
		check_admin_referer( 'malka_crm_borrar_proveedores' );

		$n = 0;
		foreach ( isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array() as $id ) {
			$id = absint( $id );
			if ( $id && self::POST_TYPE === get_post_type( $id ) ) {
				wp_trash_post( $id );
				$n++;
			}
		}

		wp_safe_redirect( add_query_arg( 'borrado', $n, self::list_url() ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}
		check_admin_referer( 'malka_crm_borrar_proveedor' );
		$id = isset( $_GET['proveedor'] ) ? absint( $_GET['proveedor'] ) : 0;
		if ( $id && self::POST_TYPE === get_post_type( $id ) ) {
			wp_trash_post( $id );
		}
		wp_safe_redirect( add_query_arg( 'borrado', 1, self::list_url() ) );
		exit;
	}
}
