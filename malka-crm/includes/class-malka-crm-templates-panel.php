<?php
/**
 * Las plantillas de etapas, dentro del panel.
 *
 * Una plantilla es la metodología de un producto o servicio escrita una vez:
 * qué etapas tiene un branding, qué tareas cuelgan de cada una, quién
 * responde por cada cosa y cuántos días toma. No lleva fechas ni cliente —
 * eso aparece al aplicarla a un negocio concreto.
 *
 * Los datos y el «aplicar» viven en Malka_CRM_Templates, que es la misma
 * estructura que usa WP-Admin. Acá sólo está la pantalla del panel, para que
 * ella no tenga que salirse a WordPress para escribir su metodología.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pantallas de plantillas dentro de /escritorio/.
 */
class Malka_CRM_Templates_Panel {

	/**
	 * Engancha los formularios del panel.
	 */
	public function __construct() {
		add_action( 'admin_post_malka_crm_guardar_plantilla', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_malka_crm_borrar_plantilla', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_malka_crm_copiar_plantilla', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_post_malka_crm_aplicar_plantilla', array( __CLASS__, 'handle_apply' ) );
		add_action( 'wp_ajax_malka_crm_pasos_pub', array( __CLASS__, 'ajax_pasos_pub' ) );
	}

	/* ──────────────────  Pasos de cada formato de publicación  ────────────────── */

	const OPT_PASOS = 'malka_crm_pasos_pub';

	/**
	 * Los pasos de fábrica: el reel trae los suyos; el resto parte vacío.
	 *
	 * @return array formato => [ [ nombre, quién, privada ], … ]
	 */
	public static function pasos_defecto() {
		return array(
			'reel' => array(
				array( __( 'Envío de material', 'malka-crm' ), 'client' ),
				array( __( 'Historia', 'malka-crm' ), 'client' ),
				array( __( 'Reel editado', 'malka-crm' ), 'studio' ),
				array( __( 'Creación de texto para voz en off', 'malka-crm' ), 'studio' ),
				array( __( 'Voz en off', 'malka-crm' ), 'client' ),
				array( __( 'Voz en off añadida al video', 'malka-crm' ), 'studio' ),
				array( __( 'Subtítulos', 'malka-crm' ), 'studio' ),
				array( __( 'Música', 'malka-crm' ), 'studio' ),
				array( __( 'Portada', 'malka-crm' ), 'studio' ),
				array( __( 'Programado', 'malka-crm' ), 'studio' ),
				array( __( 'Publicar', 'malka-crm' ), 'client' ),
			),
		);
	}

	/**
	 * Los pasos de cada formato, como quedaron guardados.
	 *
	 * @return array formato => [ [ nombre, quién, privada ], … ]
	 */
	public static function pasos_pub() {
		$guardado = get_option( self::OPT_PASOS, null );
		$base     = is_array( $guardado ) ? $guardado : self::pasos_defecto();
		$out      = array();
		foreach ( array_keys( Malka_CRM_Portal::formatos_pub() ) as $f ) {
			$out[ $f ] = isset( $base[ $f ] ) && is_array( $base[ $f ] ) ? array_values( $base[ $f ] ) : array();
		}
		return $out;
	}

	/**
	 * Guarda los pasos de un formato (se guarda solo, mientras escribe).
	 */
	public static function ajax_pasos_pub() {
		check_ajax_referer( 'malka_crm_pasos_pub', 'nonce' );
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( null, 403 );
		}
		$f    = isset( $_POST['formato'] ) ? sanitize_key( wp_unslash( $_POST['formato'] ) ) : '';
		$fmts = Malka_CRM_Portal::formatos_pub();
		if ( ! isset( $fmts[ $f ] ) ) {
			wp_send_json_error( null, 400 );
		}
		$raw   = isset( $_POST['pasos'] ) ? json_decode( wp_unslash( $_POST['pasos'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$quien = Malka_CRM_Projects::responsables();
		$pasos = array();
		foreach ( is_array( $raw ) ? $raw : array() as $p ) {
			$n = isset( $p[0] ) ? sanitize_text_field( (string) $p[0] ) : '';
			$o = isset( $p[1] ) ? sanitize_key( (string) $p[1] ) : 'studio';
			if ( '' === $n ) {
				continue;
			}
			$pasos[] = array( $n, isset( $quien[ $o ] ) ? $o : 'studio', ! empty( $p[2] ) ? 1 : 0 );
		}
		$todos  = self::pasos_pub();
		$antes  = array();
		foreach ( $todos[ $f ] as $p ) {
			$antes[ self::clave_paso( $p[0] ) ] = ! empty( $p[2] ) ? 1 : 0;
		}
		$todos[ $f ] = array_slice( $pasos, 0, 30 );
		update_option( self::OPT_PASOS, $todos, false );

		// Si un paso cambió de «quién lo ve», se aplica también a las
		// subtareas que ya se crearon con ese nombre en publicaciones de ese formato.
		$cambio = array();
		foreach ( $todos[ $f ] as $p ) {
			$k = self::clave_paso( $p[0] );
			if ( ! isset( $antes[ $k ] ) || $antes[ $k ] !== $p[2] ) {
				$cambio[ $k ] = $p[2];
			}
		}
		$tocadas = $cambio ? self::aplicar_privacidad( $f, $cambio ) : 0;

		wp_send_json_success( array( 'n' => count( $todos[ $f ] ), 'tocadas' => $tocadas ) );
	}

	/**
	 * El nombre de un paso, para compararlo sin mayúsculas ni tildes.
	 *
	 * @param string $n Nombre.
	 * @return string
	 */
	private static function clave_paso( $n ) {
		return strtolower( trim( remove_accents( (string) $n ) ) );
	}

	/**
	 * Aplica «sólo para el estudio» a las subtareas ya creadas.
	 *
	 * @param string $f      Formato.
	 * @param array  $cambio clave del nombre => 1 privada | 0 la ve el cliente.
	 * @return int Cuántas subtareas cambiaron.
	 */
	private static function aplicar_privacidad( $f, $cambio ) {
		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Projects::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
			)
		);
		$total = 0;
		foreach ( $ids as $pid ) {
			$tareas = get_post_meta( $pid, '_malka_pm_tasks', true );
			if ( ! is_array( $tareas ) || ! $tareas ) {
				continue;
			}
			// Los números de las publicaciones de este formato.
			$madres = array();
			foreach ( $tareas as $t ) {
				if ( ! empty( $t['number'] ) && isset( $t['pub_formato'] ) && $f === $t['pub_formato'] ) {
					$madres[] = trim( (string) $t['number'] ) . '.';
				}
			}
			if ( ! $madres ) {
				continue;
			}
			$n = 0;
			foreach ( $tareas as $i => $t ) {
				$num = isset( $t['number'] ) ? trim( (string) $t['number'] ) : '';
				$k   = self::clave_paso( isset( $t['name'] ) ? $t['name'] : '' );
				if ( '' === $num || ! isset( $cambio[ $k ] ) ) {
					continue;
				}
				foreach ( $madres as $m ) {
					if ( 0 === strpos( $num, $m ) && false === strpos( substr( $num, strlen( $m ) ), '.' ) ) {
						if ( (int) ! empty( $t['privada'] ) !== $cambio[ $k ] ) {
							$tareas[ $i ]['privada'] = $cambio[ $k ];
							$n++;
						}
						break;
					}
				}
			}
			if ( $n ) {
				update_post_meta( $pid, '_malka_pm_tasks', $tareas );
				if ( class_exists( 'Malka_CRM_Portal' ) ) {
					Malka_CRM_Portal::tocar( $pid );
				}
				$total += $n;
			}
		}
		return $total;
	}

	/**
	 * Una fila de paso en el editor.
	 *
	 * @param string $n Nombre.
	 * @param string $o Quién.
	 * @return string
	 */
	private static function fila_paso( $n = '', $o = 'studio', $priv = 0 ) {
		$sel = '';
		foreach ( Malka_CRM_Projects::responsables() as $k => $rot ) {
			if ( 'none' === $k ) {
				continue;
			}
			$sel .= '<option value="' . esc_attr( $k ) . '"' . selected( $o, $k, false ) . '>' . esc_html( $rot ) . '</option>';
		}
		return sprintf(
			'<div class="esc-paso"><span class="esc-paso__n" aria-hidden="true"></span><input type="text" value="%1$s" placeholder="%2$s" aria-label="%2$s" data-paso-n /><select data-paso-o aria-label="%3$s">%4$s</select><select data-paso-v aria-label="%6$s" class="%9$s"><option value="0">%7$s</option><option value="1"%10$s>%8$s</option></select><button type="button" class="esc-paso__x" data-paso-x aria-label="%5$s" title="%5$s"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></button></div>',
			esc_attr( $n ),
			esc_attr__( 'Nombre del paso', 'malka-crm' ),
			esc_attr__( 'Quién', 'malka-crm' ),
			$sel,
			esc_attr__( 'Quitar este paso', 'malka-crm' ),
			esc_attr__( 'Quién lo ve', 'malka-crm' ),
			esc_html__( 'Lo ve el cliente', 'malka-crm' ),
			esc_html__( 'Sólo el estudio', 'malka-crm' ),
			$priv ? 'is-priv' : '',
			$priv ? ' selected' : ''
		);
	}

	/**
	 * El panel de pasos por formato, debajo de las plantillas.
	 *
	 * @return string
	 */
	private static function render_pasos() {
		$pasos = self::pasos_pub();
		$out   = '<section class="esc-panel esc-panel--full esc-pasos" data-pasos data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'malka_crm_pasos_pub' ) ) . '">';
		$out  .= '<div class="esc-ph"><div><h2>' . esc_html__( 'Pasos de cada publicación', 'malka-crm' ) . '</h2><p>' . esc_html__( 'Al elegir el formato de una publicación, sus pasos aparecen solos como subtareas (sólo si todavía no tiene ninguna). Los de «Sólo el estudio» no le aparecen al cliente en su panel.', 'malka-crm' ) . '</p></div></div>';
		foreach ( Malka_CRM_Portal::formatos_pub() as $f => $rot ) {
			$filas = '';
			foreach ( $pasos[ $f ] as $p ) {
				$filas .= self::fila_paso( $p[0], $p[1], ! empty( $p[2] ) ? 1 : 0 );
			}
			$out .= sprintf(
				'<details class="esc-pasos__f" data-formato="%1$s"><summary><b>%2$s</b><small data-pasos-cuenta>%3$s</small><span class="esc-pasos__st" data-pasos-st aria-live="polite"></span></summary><div class="esc-pasos__l" data-pasos-l>%4$s</div><button type="button" class="esc-btn esc-btn--soft esc-pasos__add" data-paso-add>+ %5$s</button></details>',
				esc_attr( $f ),
				esc_html( $rot ),
				esc_html( count( $pasos[ $f ] ) ? sprintf( /* translators: cantidad */ _n( '%d paso', '%d pasos', count( $pasos[ $f ] ), 'malka-crm' ), count( $pasos[ $f ] ) ) : __( 'Sin pasos', 'malka-crm' ) ),
				$filas,
				esc_html__( 'Paso', 'malka-crm' )
			);
		}
		$out .= '<template data-paso-tpl>' . self::fila_paso() . '</template>';
		$out .= '</section>';
		return $out;
	}

	/* ─────────────────────────────  URLs  ───────────────────────────── */

	/**
	 * La lista de plantillas.
	 *
	 * @return string
	 */
	public static function list_url() {
		return Malka_CRM_Dashboard::view_url( 'plantillas' );
	}

	/**
	 * El editor de una plantilla.
	 *
	 * @param int $id Plantilla, o 0 para una nueva.
	 * @return string
	 */
	public static function edit_url( $id = 0 ) {
		$base = Malka_CRM_Dashboard::view_url( 'plantilla' );

		return $id ? add_query_arg( 'id', (int) $id, $base ) : $base;
	}

	/**
	 * La plantilla que se está mirando.
	 *
	 * @return int
	 */
	public static function current_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( ! $id ) {
			return 0;
		}

		$post = get_post( $id );

		return ( $post && Malka_CRM_Templates::POST_TYPE === $post->post_type ) ? $id : 0;
	}

	/**
	 * Una acción con su nonce, para los botones de fila.
	 *
	 * @param string $accion Acción de admin-post.
	 * @param int    $id     Plantilla.
	 * @return string
	 */
	private static function accion_url( $accion, $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => $accion,
					'plantilla' => (int) $id,
				),
				admin_url( 'admin-post.php' )
			),
			$accion . '_' . (int) $id
		);
	}

	/* ─────────────────────────────  Lista  ──────────────────────────── */

	/**
	 * Todas las plantillas, con cuántas etapas y tareas tiene cada una.
	 *
	 * @return string
	 */
	public static function render_list() {
		$plantillas = Malka_CRM_Templates::get_templates();

		ob_start();
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total">';
		echo self::mensaje(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<section class="esc-panel esc-panel--full">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Plantillas de etapas', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'La metodología de cada producto o servicio, escrita una vez', 'malka-crm' ) . '</p></div>';
		printf(
			'<div class="esc-ph__r"><a class="esc-btn esc-btn--primary" href="%1$s">+ %2$s</a></div></div>',
			esc_url( self::edit_url() ),
			esc_html__( 'Nueva plantilla', 'malka-crm' )
		);

		if ( empty( $plantillas ) ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no hay plantillas. Crea una con las etapas que repites en cada proyecto y después se aplica a un negocio con un clic.', 'malka-crm' ) . '</p>';
			echo '</section>';
			echo self::render_pasos(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';

			return ob_get_clean();
		}

		echo '<div class="esc-tabla">';
		printf(
			'<div class="esc-tr esc-tr--h esc-tr--tpl"><span>%1$s</span><span>%2$s</span><span>%3$s</span><span></span></div>',
			esc_html__( 'Plantilla', 'malka-crm' ),
			esc_html__( 'Etapas', 'malka-crm' ),
			esc_html__( 'Tareas', 'malka-crm' )
		);

		foreach ( $plantillas as $t ) {
			$items  = Malka_CRM_Templates::get_items( $t->ID );
			$etapas = 0;

			foreach ( $items as $it ) {
				if ( 'stage' === $it['type'] ) {
					$etapas++;
				}
			}

			printf(
				'<div class="esc-tr esc-tr--tpl">' .
				'<span class="esc-tr__c"><a class="esc-tpl__a" href="%1$s">%2$s</a>%6$s</span>' .
				'<span class="esc-tr__m">%3$d</span>' .
				'<span class="esc-tr__m">%4$d</span>' .
				'<span class="esc-tr__acc">%5$s</span>' .
				'</div>',
				esc_url( self::edit_url( $t->ID ) ),
				esc_html( $t->post_title ? $t->post_title : __( '(sin nombre)', 'malka-crm' ) ),
				(int) $etapas,
				(int) ( count( $items ) - $etapas ),
				self::acciones( $t->ID ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				implode( '', array_map( function ( $g ) { return ' <span class="esc-tag esc-tag--s">' . esc_html( $g ) . '</span>'; }, Malka_CRM_Templates::get_tags( $t->ID ) ) )
			);
		}

		echo '</div>';
		echo '<p class="esc-hint">' . esc_html__( 'Una plantilla no lleva fechas ni cliente: eso se calcula al aplicarla a un negocio, desde la ficha de ese negocio.', 'malka-crm' ) . '</p>';
		echo '</section>';
		echo self::render_pasos(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Editar, duplicar y borrar.
	 *
	 * @param int $id Plantilla.
	 * @return string
	 */
	private static function acciones( $id ) {
		// Editar es tocar la fila: acá quedan duplicar y la papelera.
		$out = sprintf(
			'<a class="esc-ico" href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',
			esc_url( self::accion_url( 'malka_crm_copiar_plantilla', $id ) ),
			esc_attr__( 'Duplicar', 'malka-crm' ),
			Malka_CRM_Dashboard::icono_publico( '<rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5v-2a1 1 0 00-1-1h-6a1 1 0 00-1 1v6a1 1 0 001 1h2"/>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		$out .= sprintf(
			'<a class="esc-ico esc-ico--bin" href="%1$s" title="%2$s" aria-label="%2$s" data-confirmar="%3$s">%4$s</a>',
			esc_url( self::accion_url( 'malka_crm_borrar_plantilla', $id ) ),
			esc_attr__( 'Borrar', 'malka-crm' ),
			esc_attr__( '¿Borrar esta plantilla? Los negocios que ya la usaron no se tocan.', 'malka-crm' ),
			Malka_CRM_Dashboard::icono_publico( '<path d="M4 4l8 8M12 4l-8 8"/>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		return $out;
	}

	/* ─────────────────────────────  Editor  ─────────────────────────── */

	/**
	 * El formulario de una plantilla.
	 *
	 * @return string
	 */
	public static function render_form() {
		$id     = self::current_id();
		$post   = $id ? get_post( $id ) : null;
		$items  = $id ? Malka_CRM_Templates::get_items( $id ) : array();
		$nombre = $post ? $post->post_title : '';

		ob_start();
		echo '<form class="esc-cfg esc-cfg--sola" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'malka_crm_guardar_plantilla', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_guardar_plantilla" />';
		printf( '<input type="hidden" name="plantilla" value="%d" />', (int) $id );

		echo '<div class="esc-cfg__main">';

		/* ── Cómo se llama ── */
		echo '<section class="esc-panel">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'De qué producto o servicio es', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'El nombre con el que la vas a buscar al aplicarla', 'malka-crm' ) . '</p></div></div>';

		printf(
			'<div class="esc-f"><label class="esc-f__l" for="tpl-nombre">%1$s</label>' .
			'<div class="esc-f__w"><input class="esc-f__i" id="tpl-nombre" type="text" name="nombre" value="%2$s" placeholder="%3$s" required /></div></div>',
			esc_html__( 'Nombre de la plantilla', 'malka-crm' ),
			esc_attr( $nombre ),
			esc_attr__( 'Plan Branding, Sitio web, Fotografía de producto…', 'malka-crm' )
		);

		$tags = $id ? Malka_CRM_Templates::get_tags( $id ) : array();
		printf(
			'<div class="esc-f"><label class="esc-f__l" for="tpl-tags">%1$s</label>' .
			'<div class="esc-f__w"><input class="esc-f__i" id="tpl-tags" type="text" name="etiquetas" value="%2$s" placeholder="%3$s" list="tpl-tags-l" autocomplete="off" /></div>' .
			'<span class="esc-f__h">%4$s</span><datalist id="tpl-tags-l">%5$s</datalist></div>',
			esc_html__( 'Etiquetas', 'malka-crm' ),
			esc_attr( implode( ', ', $tags ) ),
			esc_attr__( 'RRSS, Mensual…', 'malka-crm' ),
			esc_html__( 'Sepáralas con comas. Con «RRSS», las tareas de publicaciones traen el bloque de copy, archivo y fecha.', 'malka-crm' ),
			implode( '', array_map( function ( $t ) { return '<option value="' . esc_attr( $t ) . '">'; }, Malka_CRM_Templates::all_tags() ) )
		);

		echo '</section>';

		/* ── La estructura ── */
		echo '<section class="esc-panel">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Etapas y tareas', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'El número manda: 1 es una etapa, 1.1 una tarea suya, 1.1.1 una subtarea', 'malka-crm' ) . '</p></div></div>';

		echo '<div class="esc-arbol" id="esc-arbol" data-prefijo="tpl">';

		self::$rrss = $id ? Malka_CRM_Templates::es_rrss( $id ) : false;
		foreach ( array_values( $items ) as $i => $it ) {
			echo self::fila( $i, $it ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';

		if ( ! $items ) {
			echo '<p class="esc-empty">' . esc_html__( 'Vacía por ahora. Agrega una etapa y después las tareas que cuelgan de ella.', 'malka-crm' ) . '</p>';
		}

		echo '<div class="esc-row-btn">';
		printf( '<button type="button" class="esc-mini" id="esc-arbol-etapa">+ %s</button>', esc_html__( 'Etapa', 'malka-crm' ) );
		printf( '<button type="button" class="esc-mini" id="esc-arbol-tarea">+ %s</button>', esc_html__( 'Tarea', 'malka-crm' ) );
		echo '</div>';

		echo '<p class="esc-hint">' . esc_html__( 'Los días son cuántos toma cada tarea, no una fecha: al aplicar la plantilla se encadenan desde el día que elijas.', 'malka-crm' ) . '</p>';
		echo '</section>';

		echo '</div>'; // .esc-cfg__main

		/* ── Guardar ── */
		echo '<div class="esc-cfg__save esc-ed__save">';
		printf(
			'<span>%s</span>',
			esc_html(
				$id
					/* translators: número de líneas */
					? sprintf( _n( '%d línea', '%d líneas', count( $items ), 'malka-crm' ), count( $items ) )
					: __( 'Plantilla nueva', 'malka-crm' )
			)
		);
		printf( '<a class="esc-btn" href="%1$s">%2$s</a>', esc_url( self::list_url() ), esc_html__( 'Cancelar', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Guardar', 'malka-crm' ) );
		echo '</div>';

		echo '</form>';

		return ob_get_clean();
	}

	/**
	 * Una línea de la plantilla.
	 *
	 * Es la misma fila plegable del árbol de un negocio, sin las fechas: una
	 * plantilla no sabe cuándo parte nada. Reusa las clases para que las dos
	 * pantallas se lean igual y el mismo JS les sirva.
	 *
	 * @param int|string $i  Índice, o __i__ para la plantilla del JS.
	 * @param array      $it Ítem.
	 * @return string
	 */
	/**
	 * Si la plantilla que se está pintando es de redes sociales.
	 *
	 * @var bool
	 */
	public static $rrss = false;

	public static function fila( $i, $it = array() ) {
		$it = wp_parse_args(
			(array) $it,
			array(
				'number'         => '',
				'name'           => '',
				'type'           => 'task',
				'owner'          => 'studio',
				'due_days'       => 0,
				'needs_approval' => 0,
				'rounds'         => '',
				'notes'          => '',
				'pub_copy'       => '',
				'pub_url'        => '',
				'es_pub'         => '',
			)
		);

		$etapa = ( 'stage' === $it['type'] );
		$nivel = $it['number'] ? min( 4, Malka_CRM_Helpers::number_level( $it['number'] ) ) : 1;

		ob_start();
		printf(
			'<div class="esc-tarea%1$s" data-nivel="%2$d">',
			$etapa ? ' is-etapa' : '',
			(int) $nivel
		);

		echo Malka_CRM_Projects::asa_mover(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Sin casilla de «lista»: una plantilla no se completa, se aplica.
		echo '<span class="esc-tarea__sin" aria-hidden="true"></span>';

		printf(
			'<input class="esc-tarea__num" type="text" name="tpl[%1$s][number]" value="%2$s" placeholder="1.1" data-numero />',
			esc_attr( $i ),
			esc_attr( $it['number'] )
		);

		printf(
			'<input class="esc-tarea__n" type="text" name="tpl[%1$s][name]" value="%2$s" placeholder="%3$s" />',
			esc_attr( $i ),
			esc_attr( $it['name'] ),
			esc_attr__( 'Nombre de la etapa o la tarea', 'malka-crm' )
		);

		printf(
			'<span class="esc-tarea__res" data-resumen>%s</span>',
			esc_html( self::resumen( $it ) )
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

		printf(
			'<button type="button" class="esc-costo__x esc-tarea__x" aria-label="%1$s" data-rotulo="%1$s">&times;</button>',
			esc_attr__( 'Quitar esta línea', 'malka-crm' )
		);

		/* ── El detalle ── */
		echo '<div class="esc-tarea__det" hidden>';

		printf( '<label class="esc-tarea__c"><span>%1$s</span><select class="esc-tarea__t" name="tpl[%2$s][type]" data-tipo>', esc_html__( 'Qué es', 'malka-crm' ), esc_attr( $i ) );
		foreach ( Malka_CRM_Helpers::task_types() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $it['type'], $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select></label>';

		echo '<div class="esc-tarea__d">';

		printf( '<label class="esc-tarea__c"><span>%1$s</span><select name="tpl[%2$s][owner]">', esc_html__( 'Quién', 'malka-crm' ), esc_attr( $i ) );
		foreach ( Malka_CRM_Helpers::task_owners() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $it['owner'], $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select></label>';

		printf(
			'<label class="esc-tarea__c esc-tarea__c--pct"><span>%1$s</span>' .
			'<input type="number" min="0" name="tpl[%2$s][due_days]" value="%3$d" /></label>',
			esc_html__( 'Días', 'malka-crm' ),
			esc_attr( $i ),
			(int) $it['due_days']
		);

		printf(
			'<label class="esc-tarea__c esc-tarea__c--pct"><span>%1$s</span>' .
			'<input type="number" min="0" step="1" name="tpl[%2$s][rounds]" value="%3$s" placeholder="—" /></label>',
			esc_html__( 'Correcciones', 'malka-crm' ),
			esc_attr( $i ),
			esc_attr( (string) $it['rounds'] )
		);

		printf(
			'<label class="esc-chk"><input type="checkbox" name="tpl[%1$s][needs_approval]" value="1"%2$s /> %3$s</label>',
			esc_attr( $i ),
			$it['needs_approval'] ? ' checked' : '',
			esc_html__( 'La aprueba el cliente', 'malka-crm' )
		);

		printf(
			'<label class="esc-td__c esc-td__notas"><span>%1$s</span><input class="esc-tarea__notas" type="text" name="tpl[%2$s][notes]" value="%3$s" placeholder="%4$s" /></label>',
			esc_html__( 'Notas', 'malka-crm' ),
			esc_attr( $i ),
			esc_attr( $it['notes'] ),
			esc_attr__( 'Lo que haya que recordar de esta línea', 'malka-crm' )
		);
		echo Malka_CRM_Projects::publicacion( 'tpl', $i, $it, false, self::$rrss ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div>';
		echo '</div>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * La línea que se lee con la fila cerrada.
	 *
	 * @param array $it Ítem.
	 * @return string
	 */
	public static function resumen( $it ) {
		$partes = array();
		$quien  = Malka_CRM_Helpers::task_owners();
		$owner  = isset( $it['owner'] ) ? $it['owner'] : 'studio';

		if ( isset( $quien[ $owner ] ) && 'none' !== $owner ) {
			$partes[] = $quien[ $owner ];
		}

		$dias = isset( $it['due_days'] ) ? (int) $it['due_days'] : 0;

		if ( $dias > 0 ) {
			/* translators: días que toma la tarea */
			$partes[] = sprintf( _n( '%d día', '%d días', $dias, 'malka-crm' ), $dias );
		}

		if ( ! empty( $it['needs_approval'] ) ) {
			$partes[] = __( 'la aprueba el cliente', 'malka-crm' );
		}

		return implode( ' · ', $partes );
	}

	/* ─────────────────────────────  Guardar  ────────────────────────── */

	/**
	 * Guarda la plantilla desde el panel.
	 */
	public static function handle_save() {
		check_admin_referer( 'malka_crm_guardar_plantilla' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$id     = isset( $_POST['plantilla'] ) ? absint( $_POST['plantilla'] ) : 0;
		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';

		if ( '' === $nombre ) {
			$nombre = __( 'Plantilla sin nombre', 'malka-crm' );
		}

		if ( $id ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $nombre,
				)
			);
		} else {
			$id = wp_insert_post(
				array(
					'post_type'   => Malka_CRM_Templates::POST_TYPE,
					'post_title'  => $nombre,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $id ) ) {
				wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'tpl_no', self::list_url() ) );
				exit;
			}
		}

		$raw = isset( $_POST['tpl'] ) ? (array) wp_unslash( $_POST['tpl'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		update_post_meta( $id, Malka_CRM_Templates::META_ITEMS, Malka_CRM_Templates::sanitize_items( $raw ) );
		if ( isset( $_POST['etiquetas'] ) ) {
			Malka_CRM_Templates::set_tags( $id, wp_unslash( $_POST['etiquetas'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'tpl_ok', self::edit_url( $id ) ) );
		exit;
	}

	/**
	 * Manda una plantilla a la papelera.
	 */
	public static function handle_delete() {
		$id = isset( $_REQUEST['plantilla'] ) ? absint( $_REQUEST['plantilla'] ) : 0;

		check_admin_referer( 'malka_crm_borrar_plantilla_' . $id );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$post = get_post( $id );

		if ( $post && Malka_CRM_Templates::POST_TYPE === $post->post_type ) {
			wp_trash_post( $id );
		}

		wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'tpl_fuera', self::list_url() ) );
		exit;
	}

	/**
	 * Duplica una plantilla con todas sus líneas.
	 */
	public static function handle_duplicate() {
		$id = isset( $_REQUEST['plantilla'] ) ? absint( $_REQUEST['plantilla'] ) : 0;

		check_admin_referer( 'malka_crm_copiar_plantilla_' . $id );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$post = get_post( $id );

		if ( ! $post || Malka_CRM_Templates::POST_TYPE !== $post->post_type ) {
			wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'tpl_no', self::list_url() ) );
			exit;
		}

		$nuevo = wp_insert_post(
			array(
				'post_type'   => Malka_CRM_Templates::POST_TYPE,
				/* translators: nombre de la plantilla original */
				'post_title'  => sprintf( __( '%s (copia)', 'malka-crm' ), $post->post_title ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $nuevo ) ) {
			wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'tpl_no', self::list_url() ) );
			exit;
		}

		update_post_meta( $nuevo, Malka_CRM_Templates::META_ITEMS, Malka_CRM_Templates::get_items( $id ) );

		wp_safe_redirect( self::edit_url( $nuevo ) );
		exit;
	}

	/**
	 * Aplica una plantilla a un negocio, desde la ficha.
	 */
	public static function handle_apply() {
		$negocio = isset( $_POST['negocio'] ) ? absint( $_POST['negocio'] ) : 0;

		check_admin_referer( 'malka_crm_aplicar_plantilla_' . $negocio );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$plantilla = isset( $_POST['plantilla'] ) ? absint( $_POST['plantilla'] ) : 0;
		$desde     = isset( $_POST['desde'] ) ? sanitize_text_field( wp_unslash( $_POST['desde'] ) ) : '';

		/*
		 * Reemplazar borra lo que haya. Por eso el valor por defecto es sumar:
		 * si se equivoca de plantilla, lo peor que pasa es que sobren líneas,
		 * no que desaparezca el trabajo de un mes.
		 */
		$reemplazar = ! empty( $_POST['reemplazar'] );
		$puestas    = 0;

		if ( $negocio && $plantilla ) {
			$puestas = Malka_CRM_Templates::apply( $plantilla, $negocio, $desde, $reemplazar );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'malka_crm_msg'  => $puestas ? 'tpl_puesta' : 'tpl_vacia',
					'malka_crm_sent' => (int) $puestas,
				),
				Malka_CRM_Projects::edit_url( $negocio )
			)
		);
		exit;
	}

	/* ─────────────────────────────  Acuses  ─────────────────────────── */

	/**
	 * El acuse de lo que acaba de pasar.
	 *
	 * @return string
	 */
	public static function mensaje() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['malka_crm_msg'] ) ? sanitize_key( wp_unslash( $_GET['malka_crm_msg'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n = isset( $_GET['malka_crm_sent'] ) ? absint( $_GET['malka_crm_sent'] ) : 0;

		$textos = array(
			'tpl_ok'     => array( 'ok', __( 'Plantilla guardada.', 'malka-crm' ) ),
			'tpl_fuera'  => array( 'ok', __( 'La plantilla quedó en la papelera.', 'malka-crm' ) ),
			'tpl_no'     => array( 'no', __( 'No se pudo guardar la plantilla.', 'malka-crm' ) ),
			'tpl_vacia'  => array( 'no', __( 'Esa plantilla no tiene líneas: no se aplicó nada.', 'malka-crm' ) ),
			'tpl_puesta' => array(
				'ok',
				sprintf(
					/* translators: número de líneas aplicadas */
					_n( 'Se agregó %d línea desde la plantilla.', 'Se agregaron %d líneas desde la plantilla.', $n, 'malka-crm' ),
					$n
				),
			),
		);

		if ( ! isset( $textos[ $msg ] ) ) {
			return '';
		}

		list( $tipo, $texto ) = $textos[ $msg ];

		return sprintf(
			'<p class="esc-ok%1$s">%2$s</p>',
			'no' === $tipo ? ' esc-ok--no' : '',
			esc_html( $texto )
		);
	}
}
