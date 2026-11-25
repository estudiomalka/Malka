<?php
/**
 * Escritorio: panel de dirección en estudiomalka.cl/escritorio/.
 *
 * Es una pantalla propia, no una página del tema: tiene barra lateral, mosaico
 * de tarjetas y sus propias vistas. Todo lo numérico se deriva de los datos
 * ingresados; si falta un dato, el panel dice qué falta en vez de mostrar cero.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Dashboard {

	const ENDPOINT = 'escritorio';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_shortcode( 'malka_crm_dashboard', array( $this, 'shortcode' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );

		// La vista previa del correo mientras ella escribe.
		add_action( 'wp_ajax_malka_crm_previa_mensaje', array( __CLASS__, 'handle_previa_mensaje' ) );
		add_action( 'wp_ajax_malka_crm_finanzas', array( __CLASS__, 'ajax_finanzas' ) );
		add_action( 'wp_ajax_malka_crm_movs', array( __CLASS__, 'ajax_movs' ) );
	}

	/**
	 * Devuelve el correo armado con lo que ella está escribiendo.
	 *
	 * Lo arma el servidor y no el navegador, a propósito: así hay un solo
	 * sitio donde el marcado se convierte en HTML, y lo que ve en la pantalla
	 * es exactamente lo que se va a enviar.
	 */
	public static function handle_previa_mensaje() {
		check_ajax_referer( 'malka_crm_previa_mensaje', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) || ! class_exists( 'Malka_CRM_Messages' ) ) {
			wp_send_json_error( array( 'motivo' => 'permisos' ), 403 );
		}

		$clave    = isset( $_POST['mensaje'] ) ? sanitize_key( wp_unslash( $_POST['mensaje'] ) ) : '';
		$todas    = Malka_CRM_Messages::plantillas();

		if ( ! isset( $todas[ $clave ] ) ) {
			wp_send_json_error( array( 'motivo' => 'mensaje' ), 400 );
		}

		$borrador = array();

		foreach ( Malka_CRM_Messages::CAMPOS as $k ) {
			if ( ! isset( $_POST[ $k ] ) ) {
				continue;
			}

			$borrador[ $k ] = ( 'cuerpo' === $k )
				? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) )
				: sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
		}

		$previa = Malka_CRM_Messages::vista_previa( $clave, $borrador );

		wp_send_json_success(
			array(
				'html'   => $previa['html'],
				'asunto' => $previa['asunto'],
			)
		);
	}

	/* ─────────────────────────────  Ruta  ─────────────────────────────── */

	/**
	 * Regla de reescritura para /escritorio/, con autoflush por versión.
	 */
	/**
	 * Las vistas internas del escritorio. La clave es el trozo de la URL.
	 *
	 * @return array slug => etiqueta
	 */
	public static function views() {
		return array(
			'inicio'        => __( 'Inicio', 'malka-crm' ),
			// La portada del trabajo: desde acá se entra a negocios,
			// cotizaciones, clientes, proveedores, tareas y horas, que antes
			// estaban repartidos entre dos pestañas de la barra.
			'estudio'       => __( 'Estudio', 'malka-crm' ),
			'negocios'      => __( 'Negocios', 'malka-crm' ),
			'negocio'       => __( 'Negocio', 'malka-crm' ),
			'tareas'        => __( 'Tareas', 'malka-crm' ),
			'cotizaciones'  => __( 'Cotizaciones', 'malka-crm' ),
			// El editor es una vista más, pero no va en el menú: se llega
			// desde la lista, con el lápiz o con «Nueva cotización».
			'cotizacion'    => __( 'Cotización', 'malka-crm' ),
			// Finanzas junta cobranza, documentos y movimientos en pestañas.
			// Esta es su portada: el resumen del mes.
			'finanzas'      => __( 'Finanzas', 'malka-crm' ),
			'cobranza'      => __( 'Cobranza', 'malka-crm' ),
			// Lo que ella decidió dejar de perseguir. Sale de Cobranza pero
			// sigue siendo deuda para el cliente: por eso tiene su pantalla
			// y no un filtro dentro de la otra.
			'perdidas'      => __( 'Pérdidas', 'malka-crm' ),
			'clientes'      => __( 'Clientes', 'malka-crm' ),
			'cliente'       => __( 'Cliente', 'malka-crm' ),
			'proveedores'   => __( 'Proveedores', 'malka-crm' ),
			'proveedor'     => __( 'Proveedor', 'malka-crm' ),
			// Las plantillas de etapas son parte de Negocios: definen cómo se
			// arma el árbol de un producto o servicio antes de tener cliente.
			'plantillas'    => __( 'Plantillas', 'malka-crm' ),
			'plantilla'     => __( 'Plantilla', 'malka-crm' ),
			'documentos'    => __( 'Contabilidad', 'malka-crm' ),
			'documento'     => __( 'Documento', 'malka-crm' ),
			'mensajes'      => __( 'Plantillas de correos', 'malka-crm' ),
			'textos'        => __( 'Mensajes', 'malka-crm' ),
			'configuracion' => __( 'Configuración', 'malka-crm' ),
			'movimientos'   => __( 'Movimientos', 'malka-crm' ),
			// Qué cobros de cotizaciones están sumados en el saldo. No va en
			// el menú: se llega desde el saldo, que es donde se nota.
			'cobros'        => __( 'Cobros en tu saldo', 'malka-crm' ),
			'contabilidad'  => __( 'Contabilidad', 'malka-crm' ),
			'horas'         => __( 'Horas trabajadas', 'malka-crm' ),
			/*
			 * Lo que se paga todos los meses sin que nadie lo pida. Es una
			 * sola lista mirada en dos pantallas: el arriendo y el Adobe se
			 * pagan igual, pero no se deciden igual. Vivían dentro de
			 * Finanzas → Deudas y en Configuración; son del estudio.
			 */
			// La casa: lo que se hizo en el hogar y quién lo hizo.
			'casa'          => __( 'Casa', 'malka-crm' ),
			'costos-fijos'  => __( 'Costos fijos', 'malka-crm' ),
			'suscripciones' => __( 'Suscripciones', 'malka-crm' ),
			// Lo que el estudio tiene que pagar este mes: costos fijos e
			// impuestos. Vive en Estudio, no entre las tareas.
			'pagos'         => __( 'Pagos del estudio', 'malka-crm' ),
			// Lo tuyo: las tareas que no son del trabajo.
			'mis-tareas'    => __( 'Mis tareas', 'malka-crm' ),
			// Las reuniones, las llamadas y las visitas que vienen.
			'agenda'        => __( 'Agenda', 'malka-crm' ),
			// Lo que el cliente entregó y ella todavía no revisa.
			'hizo'          => __( 'Lo que hizo el cliente', 'malka-crm' ),
			// Tus propias cuentas y lo que el estudio te debe, separado de
			// la plata de la empresa.
			'personal'      => __( 'Personal', 'malka-crm' ),
			// Dónde pusiste plata para que crezca.
			'crecer'        => __( 'Crecer', 'malka-crm' ),
			// Todas tus cuentas, con lo que hay en cada una y lo que debes.
			'cuentas'       => __( 'Cuentas', 'malka-crm' ),
			// Lo que debes: tarjetas, cuotas y créditos, con sus fechas.
			'deudas'        => __( 'Deudas', 'malka-crm' ),
			// Carga inicial de cuentas. No va en la barra: se usa una vez.
			'cargar'        => __( 'Cargar tus cuentas', 'malka-crm' ),
			// Lo que no es trabajo ni plata, y sostiene las dos cosas.
			'bienestar'     => __( 'Bienestar', 'malka-crm' ),
			'cuerpo'        => __( 'Cuerpo', 'malka-crm' ),
			'mente'         => __( 'Mente', 'malka-crm' ),
			'vinculos'      => __( 'Vínculos', 'malka-crm' ),
			'vos'           => __( 'Vos', 'malka-crm' ),
		);
	}

	/**
	 * Las vistas que viven dentro de Estudio.
	 *
	 * @return string[]
	 */
	public static function vistas_estudio() {
		/*
		 * Las horas, las tareas del trabajo y los pagos del estudio también
		 * son del estudio: cuelgan de acá. La entrada de arriba queda para
		 * lo tuyo.
		 */
		return array(
			'estudio', 'negocios', 'negocio', 'plantillas', 'plantilla',
			'cotizaciones', 'cotizacion', 'clientes', 'cliente',
			'proveedores', 'proveedor', 'tareas', 'horas', 'pagos', 'hizo',
		);
	}

	/**
	 * Las vistas que viven dentro de Bienestar.
	 *
	 * @return string[]
	 */
	public static function vistas_bienestar() {
		return array( 'bienestar', 'cuerpo', 'mente', 'vinculos', 'vos' );
	}

	/**
	 * La vista pedida, ya validada.
	 *
	 * @return string
	 */
	public static function current_view() {
		$v = get_query_var( 'malka_crm_vista' );

		if ( ! $v && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$uri = trim( (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ), '/' );
			$par = explode( '/', $uri );
			$v   = end( $par );
		}

		$v = sanitize_key( (string) $v );

		return array_key_exists( $v, self::views() ) ? $v : 'inicio';
	}

	/**
	 * URL de una vista del escritorio.
	 *
	 * @param string $vista Slug.
	 * @return string
	 */
	public static function view_url( $vista = 'inicio' ) {
		return 'inicio' === $vista
			? home_url( '/' . self::ENDPOINT . '/' )
			: home_url( '/' . self::ENDPOINT . '/' . $vista . '/' );
	}

	public static function add_rewrite() {
		$slugs = implode( '|', array_keys( self::views() ) );

		add_rewrite_rule( '^' . self::ENDPOINT . '/(' . $slugs . ')/?$', 'index.php?malka_crm_dashboard=1&malka_crm_vista=$matches[1]', 'top' );
		add_rewrite_rule( '^' . self::ENDPOINT . '/?$', 'index.php?malka_crm_dashboard=1', 'top' );

		$stamp = MALKA_CRM_VERSION . '|' . self::ENDPOINT . '|' . $slugs;

		if ( get_option( 'malka_crm_rewrite_stamp' ) !== $stamp ) {
			flush_rewrite_rules( false );
			update_option( 'malka_crm_rewrite_stamp', $stamp, false );
		}
	}

	/**
	 * Respaldo por si las reglas aún no se regeneran: compara la ruta pedida.
	 *
	 * @return bool
	 */
	public static function request_matches() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$uri  = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$uri  = trim( (string) $uri, '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $home && 0 === strpos( $uri, $home ) ) {
			$uri = trim( substr( $uri, strlen( $home ) ), '/' );
		}

		if ( self::ENDPOINT === $uri ) {
			return true;
		}

		// /escritorio/configuracion/ y las demás vistas internas.
		$partes = explode( '/', $uri );

		return ( 2 === count( $partes ) && self::ENDPOINT === $partes[0] && array_key_exists( $partes[1], self::views() ) );
	}

	/**
	 * Registra la query var del escritorio.
	 *
	 * @param array $vars Variables.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'malka_crm_dashboard';
		$vars[] = 'malka_crm_vista';
		return $vars;
	}

	/**
	 * Acceso directo desde la barra de administración.
	 *
	 * @param WP_Admin_Bar $bar Barra.
	 */
	public function admin_bar( $bar ) {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'malka-crm-escritorio',
				'title' => __( 'Escritorio', 'malka-crm' ),
				'href'  => home_url( '/' . self::ENDPOINT . '/' ),
			)
		);
	}

	/**
	 * ¿Estamos en el escritorio?
	 *
	 * @return bool
	 */
	public static function is_dashboard() {
		if ( get_query_var( 'malka_crm_dashboard' ) ) {
			return true;
		}
		return self::request_matches();
	}

	/* ─────────────────────────────  Assets  ───────────────────────────── */

	public function enqueue() {
		if ( ! self::is_dashboard() ) {
			return;
		}
		self::register_assets();
		wp_enqueue_style( 'malka-crm-escritorio' );
		wp_enqueue_script( 'malka-crm-escritorio' );

		// Para elegir el logo desde la biblioteca de medios.
		if ( 'configuracion' === self::current_view() && function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * En WP-Admin el escritorio usa la misma hoja.
	 *
	 * @param string $hook Hook actual.
	 */
	public function admin_enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'malka-crm-dashboard' ) ) {
			return;
		}
		self::register_assets();
		wp_enqueue_style( 'malka-crm-escritorio' );
		wp_enqueue_script( 'malka-crm-escritorio' );
	}

	/**
	 * Registra hoja y script con los datos que el JS necesita.
	 */
	public static function register_assets() {
		wp_register_style(
			'malka-crm-escritorio',
			MALKA_CRM_URL . 'assets/css/malka-crm-escritorio.css',
			array(),
			MALKA_CRM_VERSION
		);

		wp_register_script(
			'malka-crm-escritorio',
			MALKA_CRM_URL . 'assets/js/malka-crm-escritorio.js',
			array(),
			MALKA_CRM_VERSION,
			true
		);

		wp_localize_script(
			'malka-crm-escritorio',
			'malkaEscritorio',
			array(
				'ajax'        => admin_url( 'admin-ajax.php' ),
				'nonceTime'   => wp_create_nonce( 'malka_crm_time' ),
				'nonceFin'    => wp_create_nonce( 'malka_crm_finance' ),
				'pomodoroMin' => (int) Malka_CRM_Settings::get( 'pomodoro_min', 25 ),
				'fx'          => Malka_CRM_Settings::fx(),
				// El editor calcula el total mientras ella escribe. Al guardar,
				// el servidor lo recalcula igual: esto es para que lo vea, no
				// para que mande.
				'iva'         => (float) Malka_CRM_Settings::get( 'iva', 19 ),
				'itemTpl'     => Malka_CRM_Quote_Editor::item_row( '__i__' ),
				'cuotaTpl'    => Malka_CRM_Quote_Editor::payment_row( '__i__' ),
				'tareaTpl'    => Malka_CRM_Projects::fila_tarea( '__i__' ),
				// Los pasos que trae cada formato de publicación (Plantillas).
				'pasosPub'    => Malka_CRM_Templates_Panel::pasos_pub(),
				'ajax'        => admin_url( 'admin-ajax.php' ),
				'previaNonce' => wp_create_nonce( 'malka_crm_previa_mensaje' ),
				'rotuloAsunto' => __( 'Asunto:', 'malka-crm' ),
				'retencion'   => (float) Malka_CRM_Settings::get( 'retencion', 15.25 ),
				'docTipos'    => Malka_CRM_Documents::tipos(),
				// Para leer el PDF o el XML que se arrastra al documento.
				'miRut'       => (string) Malka_CRM_Settings::get( 'rut', '' ),
				'pdfjs'       => MALKA_CRM_URL . 'assets/vendor/pdfjs/pdf.min.js',
				'pdfjsWorker' => MALKA_CRM_URL . 'assets/vendor/pdfjs/pdf.worker.min.js',
				'docCampos'   => array_map(
					array( 'Malka_CRM_Documents', 'campo_monto' ),
					array_combine( array_keys( Malka_CRM_Documents::tipos() ), array_keys( Malka_CRM_Documents::tipos() ) )
				),
				'docFilas'    => array(
					'neto'       => __( 'Neto', 'malka-crm' ),
					'iva'        => sprintf( /* translators: porcentaje */ __( 'IVA (%s%%)', 'malka-crm' ), (float) Malka_CRM_Settings::get( 'iva', 19 ) ),
					'total'      => __( 'Total del documento', 'malka-crm' ),
					'anula'      => __( 'Total que se anula', 'malka-crm' ),
					'brutos'     => __( 'Honorarios brutos', 'malka-crm' ),
					'retencion'  => sprintf( /* translators: porcentaje */ __( 'Retención (%s%%)', 'malka-crm' ), (float) Malka_CRM_Settings::get( 'retencion', 15.25 ) ),
					'deposita'   => __( 'Te depositan', 'malka-crm' ),
				),
				'i18nCuota'   => array(
					/* translators: número de la cuota */
					'cuota' => __( 'Cuota %d', 'malka-crm' ),
				),
				// Los rótulos que cambian mientras ella arma su Inicio.
				'inicio'      => array(
					'editar'   => __( 'Personalizar', 'malka-crm' ),
					'listo'    => __( 'Listo', 'malka-crm' ),
					'esconder' => __( 'Esconder', 'malka-crm' ),
					'mostrar'  => __( 'Mostrar', 'malka-crm' ),
					'anchos'   => array_map(
						array( 'Malka_CRM_Home', 'rotulo_ancho' ),
						Malka_CRM_Home::ANCHOS
					),
				),
				'i18n'        => array(
					'iniciar'   => __( 'Iniciar', 'malka-crm' ),
					'pausar'    => __( 'Pausar', 'malka-crm' ),
					'continuar' => __( 'Continuar', 'malka-crm' ),
					'guardado'  => __( 'Guardado', 'malka-crm' ),
					'copiado'   => __( 'Copiado', 'malka-crm' ),
					'copiarManual' => __( 'No se pudo copiar solo: selecciona el texto y cópialo a mano', 'malka-crm' ),
					'sesion'    => __( 'Sesión registrada', 'malka-crm' ),
					'error'     => __( 'No se pudo guardar', 'malka-crm' ),
					'falta'     => __( 'Falta la descripción o el monto', 'malka-crm' ),
				),
			)
		);
	}

	/* ─────────────────────────────  Render  ───────────────────────────── */

	/**
	 * Intercepta /escritorio/ y pinta la pantalla completa.
	 */
	public function maybe_render() {
		if ( ! get_query_var( 'malka_crm_dashboard' ) && ! self::request_matches() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'No tienes acceso a este panel.', 'malka-crm' ), 403 );
		}

		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404     = false;
			$wp_query->is_home    = false;
			$wp_query->is_archive = false;
		}

		status_header( 200 );
		nocache_headers();

		self::register_assets();
		wp_enqueue_style( 'malka-crm-escritorio' );
		wp_enqueue_script( 'malka-crm-escritorio' );

		echo '<!DOCTYPE html>';
		echo '<html ' . get_language_attributes() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html__( 'Escritorio', 'malka-crm' ) . ' · ' . esc_html( get_bloginfo( 'name' ) ) . '</title>';
		echo self::etiquetas_icono( get_bloginfo( 'name' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_print_styles( array( 'malka-crm-escritorio' ) );
		echo '</head><body class="malka-esc-body">';

		echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		wp_print_scripts( array( 'malka-crm-escritorio' ) );
		echo '</body></html>';
		exit;
	}

	/**
	 * Shortcode: solo el mosaico, sin la barra lateral.
	 *
	 * @return string
	 */
	public function shortcode() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			return '<p>' . esc_html__( 'Este panel es privado.', 'malka-crm' ) . '</p>';
		}

		self::register_assets();
		wp_enqueue_style( 'malka-crm-escritorio' );
		wp_enqueue_script( 'malka-crm-escritorio' );

		return '<div class="malka-esc">' . self::render_mosaic() . '</div>';
	}

	/**
	 * Aplicación completa: barra lateral, cabecera y vistas.
	 *
	 * @return string
	 */
	public static function render() {
		$vista = self::current_view();

		// Cada cotización aceptada con su negocio: se revisa al entrar, como mucho cada hora.
		if ( class_exists( 'Malka_CRM_Quote_Page' ) && ! get_transient( 'malka_crm_negocios_ok2' ) ) {
			set_transient( 'malka_crm_negocios_ok2', 1, HOUR_IN_SECONDS );
			Malka_CRM_Quote_Page::asegurar_negocios();
		}

		// ?guia=1 vuelve a abrir los primeros pasos aunque ya se hayan ocultado.
		$forzar = isset( $_GET['guia'] ) && '1' === $_GET['guia']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$guia   = ( 'inicio' === $vista ) && ( $forzar || Malka_CRM_Onboarding::should_guide() );

		/*
		 * ?ambito= deja fijo si estás mirando la plata del estudio, la tuya o
		 * las dos. Se guarda y no se vuelve a preguntar: es un lente, no una
		 * pregunta de cada visita. Sólo cambia qué se muestra, nunca datos.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ambito'] ) && class_exists( 'Malka_CRM_Finance' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			Malka_CRM_Finance::fijar_ambito( sanitize_key( wp_unslash( $_GET['ambito'] ) ) );
		}

		// Dentro de una ventana emergente (?embed=1): sólo el contenido.
		$embed = isset( $_GET['embed'] ) && '1' === $_GET['embed']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		echo '<div class="malka-esc' . ( $embed ? ' is-embed' : '' ) . '">';
		echo '<div class="esc-app">';
		if ( ! $embed ) {
			echo self::render_sidebar( $vista ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<main class="esc-main">';
		if ( ! $embed ) {
			echo self::render_header( $vista ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		// Guardado dentro de la ventana: avisa a la página de atrás que cierre y recargue.
		if ( $embed && isset( $_GET['cerrar'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<script>try{parent.postMessage("malka-cerrar-modal","*");}catch(e){}</script>';
		}

		if ( $guia ) {
			echo self::render_start(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'cotizaciones' === $vista ) {
			echo self::view_quotes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Las pestañas de Finanzas van arriba de cada una de sus vistas, y el
		// cuerpo sale de cuerpo_finanzas(): el mismo que contesta por AJAX, para
		// que cambiar de filtro dibuje exactamente lo que dibuja recargar.
		$es_fin = ! $guia && in_array( $vista, self::vistas_finanzas(), true );

		if ( $es_fin ) {
			echo self::tabs_finanzas( $vista ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div data-fin-cuerpo>' . self::cuerpo_finanzas( $vista ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Tareas y Horas, en dos pestañas.
		if ( ! $guia && in_array( $vista, array( 'tareas', 'horas', 'pagos', 'hizo' ), true ) ) {
			echo self::tabs_simples( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$vista,
				array(
					'tareas' => __( 'Tareas', 'malka-crm' ),
					'horas'  => __( 'Horas trabajadas', 'malka-crm' ),
					'pagos'  => __( 'Pagos', 'malka-crm' ),
					/*
					 * «Revisar» y no «El cliente»: justo debajo, el filtro de
					 * Tareas tiene un botón «Cliente» que quiere decir otra
					 * cosa —lo que al cliente le falta hacer—, y las dos
					 * palabras iguales a diez centímetros una de otra se
					 * leían como el mismo sitio. Acá el nombre dice lo que
					 * ella hace en esta pestaña, no de quién es la tarea.
					 */
					'hizo'   => __( 'Revisar', 'malka-crm' ),
				),
				__( 'Tareas', 'malka-crm' )
			);
		}

		// Negocios y sus plantillas, en dos pestañas.
		if ( ! $guia && in_array( $vista, array( 'negocios', 'plantillas', 'plantilla' ), true ) ) {
			echo self::tabs_negocios( 'plantilla' === $vista ? 'plantillas' : $vista ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Las cuatro de Bienestar.
		if ( ! $guia && in_array( $vista, self::vistas_bienestar(), true ) ) {
			echo self::tabs_simples(
				$vista,
				array(
					'bienestar' => __( 'Resumen', 'malka-crm' ),
					'cuerpo'    => __( 'Cuerpo', 'malka-crm' ),
					'mente'     => __( 'Mente', 'malka-crm' ),
					'vinculos'  => __( 'Vínculos', 'malka-crm' ),
					'vos'       => __( 'Vos', 'malka-crm' ),
				),
				__( 'Bienestar', 'malka-crm' )
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Y las de Configuración, que ahora incluye los mensajes.
		if ( ! $guia && in_array( $vista, self::vistas_config(), true ) ) {
			echo self::tabs_config( $vista ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $guia || 'cotizaciones' === $vista || $es_fin ) {
			// Ya se pintó arriba.
			echo '';
		} elseif ( 'estudio' === $vista ) {
			echo self::view_estudio(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( in_array( $vista, self::vistas_bienestar(), true ) ) {
			echo self::view_bienestar( $vista ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'personal' === $vista ) {
			echo self::view_personal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'crecer' === $vista ) {
			echo self::view_crecer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'cuentas' === $vista ) {
			echo self::view_cuentas(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'deudas' === $vista ) {
			echo Malka_CRM_Deudas::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'cargar' === $vista ) {
			echo Malka_CRM_Cargar::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'finanzas' === $vista ) {
			echo self::view_finance(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'cobranza' === $vista ) {
			echo self::view_collections(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'perdidas' === $vista ) {
			echo self::view_losses(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'mensajes' === $vista ) {
			echo self::view_messages(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'textos' === $vista ) {
			echo self::view_texts(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'configuracion' === $vista ) {
			echo self::view_settings(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'cobros' === $vista ) {
			echo self::view_cobros(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'movimientos' === $vista ) {
			echo self::view_movements(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'contabilidad' === $vista ) {
			echo self::view_contabilidad(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'hizo' === $vista ) {
			echo Malka_CRM_Projects::render_hizo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'agenda' === $vista ) {
			echo Malka_CRM_Eventos::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'mis-tareas' === $vista ) {
			echo Malka_CRM_Projects::render_tasks( 'personal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'pagos' === $vista ) {
			echo Malka_CRM_Projects::render_pagos(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'horas' === $vista ) {
			echo self::view_hours(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'casa' === $vista ) {
			echo Malka_CRM_Casa::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'cotizacion' === $vista ) {
			echo Malka_CRM_Quote_Editor::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'negocios' === $vista ) {
			echo Malka_CRM_Projects::render_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'negocio' === $vista ) {
			echo Malka_CRM_Projects::render_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'tareas' === $vista ) {
			echo Malka_CRM_Projects::render_tasks(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'clientes' === $vista ) {
			echo Malka_CRM_Clients::render_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'cliente' === $vista ) {
			echo Malka_CRM_Clients::render_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'proveedores' === $vista ) {
			echo Malka_CRM_Providers::render_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'proveedor' === $vista ) {
			echo Malka_CRM_Providers::render_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'plantillas' === $vista ) {
			echo Malka_CRM_Templates_Panel::render_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'plantilla' === $vista ) {
			echo Malka_CRM_Templates_Panel::render_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'documentos' === $vista ) {
			echo Malka_CRM_Documents::render_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'documento' === $vista ) {
			echo Malka_CRM_Documents::render_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			// Lo que se guardó mientras no estaba va arriba de todo: es lo
			// primero que hay que mirar al volver, y una sola vez.
			echo self::panel_vuelta(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::render_mosaic(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo self::render_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Malka_CRM_Projects::modal_tarea(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Malka_CRM_Projects::modal_detalle(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Malka_CRM_Projects::modal_pago(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( class_exists( 'Malka_CRM_Eventos' ) ) {
			echo Malka_CRM_Eventos::modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( class_exists( 'Malka_CRM_Deudas' ) ) {
			echo Malka_CRM_Deudas::modal_nueva(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( class_exists( 'Malka_CRM_Suscripciones' ) ) {
			echo Malka_CRM_Suscripciones::modal( 'suscripciones' === $vista ? 'suscripcion' : 'fijo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo Malka_CRM_Suscripciones::modal_pago(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo Malka_CRM_Suscripciones::modal_ano(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo self::render_focus(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="esc-toast" id="esc-toast"></div>';
		echo '</main></div>';
		if ( ! $embed && class_exists( 'Malka_CRM_Asistente' ) ) {
			echo Malka_CRM_Asistente::burbuja(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
		return ob_get_clean();
	}

	/* ─────────────────────────────  Barra lateral  ────────────────────── */

	/**
	 * La barra lateral, con su vista activa marcada.
	 *
	 * @param string $vista Vista activa.
	 */
	private static function render_sidebar( $vista = 'inicio' ) {
		$user   = wp_get_current_user();
		$prog   = Malka_CRM_Onboarding::progress();
		$pend   = max( 0, $prog['total'] - $prog['listos'] );
		$cobros = count( Malka_CRM_Analysis::overdue_payments() );
		$tareas = count( Malka_CRM_Analysis::studio_tasks() );

		/*
		 * Cuántas tuyas están pendientes, para el número de «Mis tareas».
		 * El de Estudio cuenta las del trabajo: son dos cuentas distintas.
		 */
		$mias = 0;

		// Lo que viene en la semana, para el número de la Agenda.
		$eventos = class_exists( 'Malka_CRM_Eventos' ) ? count( Malka_CRM_Eventos::proximos( 7 ) ) : 0;

		if ( class_exists( 'Malka_CRM_Projects' ) ) {
			foreach ( Malka_CRM_Projects::pendientes() as $pt ) {
				if ( isset( $pt['quien'] ) && 'personal' === $pt['quien'] ) {
					$mias++;
				}
			}
		}
		// Los pagos del estudio que vencen hoy o ya se atrasaron también cuentan.
		if ( class_exists( 'Malka_CRM_Pagos' ) ) {
			foreach ( Malka_CRM_Pagos::proximos() as $pp ) {
				if ( $pp['dias'] <= 0 ) {
					$tareas++;
				}
			}
		}

		// Ya no queda ninguna que mande a WP-Admin: todas tienen su pantalla
		// acá. El último parámetro marca una entrada como externa y quedó
		// para cuando haga falta, no porque alguna lo esté.
		$items = array(
			array( 'inicio', __( 'Inicio', 'malka-crm' ), self::view_url( 'inicio' ), 0, 'inicio' === $vista, false ),
			array( 'pasos', __( 'Primeros pasos', 'malka-crm' ), self::view_url( 'inicio' ) . '?guia=1', $pend, false, false ),
			/*
			 * Negocios, Clientes, Proveedores y Cotizaciones cuelgan de
			 * Estudio: las cuatro son el trabajo con clientes, y sueltas
			 * hacían un menú de once entradas donde nada mandaba sobre
			 * nada. Aparecen cuando Estudio está abierto.
			 *
			 * El editor de una cotización no tiene entrada propia: mientras
			 * se escribe, el menú sigue marcando de dónde vino.
			 */
			array(
				'estudio',
				__( 'Estudio', 'malka-crm' ),
				self::view_url( 'estudio' ),
				$tareas,
				in_array( $vista, self::vistas_estudio(), true ),
				false,
				/*
				 * Sin «Negocios»: Estudio ya es la lista de negocios, así que
				 * tenerlo también como hijo marcaba dos entradas para lo mismo.
				 */
				array(
					array( __( 'Clientes', 'malka-crm' ), self::view_url( 'clientes' ), in_array( $vista, array( 'clientes', 'cliente' ), true ) ),
					// A quién le compra y con qué datos se emite la factura de compra.
					array( __( 'Proveedores', 'malka-crm' ), self::view_url( 'proveedores' ), in_array( $vista, array( 'proveedores', 'proveedor' ), true ) ),
					array( __( 'Cotizaciones', 'malka-crm' ), self::view_url( 'cotizaciones' ), in_array( $vista, array( 'cotizaciones', 'cotizacion' ), true ) ),
					// Lo que hay que hacer en el trabajo, lo que ya se trabajó
					// y lo que el estudio tiene que pagar este mes.
					array( __( 'Tareas', 'malka-crm' ), self::view_url( 'tareas' ), 'tareas' === $vista ),
					array( __( 'Horas trabajadas', 'malka-crm' ), self::view_url( 'horas' ), 'horas' === $vista ),
					array( __( 'Pagos', 'malka-crm' ), self::view_url( 'pagos' ), 'pagos' === $vista ),
					// Mismo nombre que la pestaña: «Revisar», no «El cliente».
					array( __( 'Revisar', 'malka-crm' ), self::view_url( 'hizo' ), 'hizo' === $vista ),
				),
			),
			/*
			 * Cobranza, Contabilidad y Movimientos ahora son una sola entrada:
			 * Finanzas, con pestañas adentro. Las tres hablan de la plata de los
			 * trabajos, y juntas pueden decir lo que separadas no veían (lo
			 * cobrado sin facturar, lo facturado sin cobrar).
			 */
			array( 'finanzas', __( 'Finanzas', 'malka-crm' ), self::view_url( 'finanzas' ), $cobros, in_array( $vista, self::vistas_finanzas(), true ) || 'documento' === $vista, false ),
			/*
			 * Las tareas del trabajo, las horas y los pagos se fueron a
			 * Estudio, que es de donde son. Acá queda lo tuyo, que no es
			 * trabajo y no tiene por qué mezclarse con él.
			 */
			array( 'tareas', __( 'Mis tareas', 'malka-crm' ), self::view_url( 'mis-tareas' ), $mias, 'mis-tareas' === $vista, false ),
			// La agenda: lo que viene, sin mezclarse con lo que hay que hacer.
			array( 'calendario', __( 'Agenda', 'malka-crm' ), self::view_url( 'agenda' ), $eventos, 'agenda' === $vista, false ),
			// La casa: lo del hogar, que no es trabajo ni plata.
			array( 'casa', __( 'Casa', 'malka-crm' ), self::view_url( 'casa' ), 0, 'casa' === $vista, false ),
			array( 'bienestar', __( 'Bienestar', 'malka-crm' ), self::view_url( 'bienestar' ), 0, in_array( $vista, self::vistas_bienestar(), true ), false ),
			// Mensajes ahora vive dentro de Configuración, como dos pestañas más.
			array( 'config', __( 'Configuración', 'malka-crm' ), self::view_url( 'configuracion' ), 0, in_array( $vista, self::vistas_config(), true ), false ),
		);

		ob_start();
		echo '<aside class="esc-side">';
		/*
		 * Arriba, el logo de la marca (Configuración → Logo de la marca). El
		 * «M · Malka CRM» queda sólo para cuando todavía no hay logo.
		 */
		$logo = (string) Malka_CRM_Settings::get( 'logo_url', '' );
		if ( '' !== $logo ) {
			printf(
				'<a class="esc-brand esc-brand--logo" href="%1$s"><img src="%2$s" alt="%3$s" /></a>',
				esc_url( self::view_url( 'inicio' ) ),
				esc_url( $logo ),
				esc_attr( Malka_CRM_Settings::get( 'nombre_fantasia', '' ) ? (string) Malka_CRM_Settings::get( 'nombre_fantasia', '' ) : get_bloginfo( 'name' ) )
			);
		} else {
			echo '<div class="esc-brand"><i>M</i>' . esc_html__( 'Malka CRM', 'malka-crm' ) . '</div>';
		}
		echo '<nav class="esc-nav">';

		/*
		 * En el teléfono la barra lateral se convierte en pestañas abajo, y
		 * ahí no caben diez. Estas cinco son el día a día; el resto vive en
		 * la hoja de «Más».
		 */
		// Las cuatro de la barra de abajo. La quinta es «Más».
		$pestanas = array( 'inicio', 'estudio', 'finanzas', 'bienestar' );
		$en_barra = false;

		foreach ( $items as $it ) {
			$slug   = $it[0];
			$label  = $it[1];
			$url    = $it[2];
			$badge  = $it[3];
			$activo = $it[4];
			$externo = $it[5];
			$hijos  = isset( $it[6] ) ? $it[6] : array();

			if ( $activo && in_array( $slug, $pestanas, true ) ) {
				$en_barra = true;
			}

			printf(
				'<a href="%1$s" class="%2$s"%6$s%7$s>%3$s<span>%4$s</span>%5$s</a>',
				esc_url( $url ),
				$activo ? 'on' : '',
				self::icon( $slug ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $label ),
				$badge > 0 ? '<b>' . esc_html( $badge ) . '</b>' : '',
				$externo ? ' data-fuera="1" title="' . esc_attr__( 'Todavía se edita en WP-Admin', 'malka-crm' ) . '"' : '',
				in_array( $slug, $pestanas, true ) ? ' data-tab="1"' : ''
			);

			/*
			 * Los hijos sólo aparecen cuando su sección está abierta: tenerlos
			 * siempre a la vista alargaría el menú con dos entradas que la
			 * mayor parte del tiempo no hacen falta.
			 */
			if ( ! $hijos || ! $activo ) {
				continue;
			}

			echo '<div class="esc-sub">';

			foreach ( $hijos as $h ) {
				printf(
					'<a href="%1$s" class="esc-sub__i%2$s">%3$s</a>',
					esc_url( $h[1] ),
					! empty( $h[2] ) ? ' on' : '',
					esc_html( $h[0] )
				);
			}

			echo '</div>';
		}

		/*
		 * La quinta pestaña: abre el resto del menú como una hoja. Si la vista
		 * que está mirando vive dentro de esa hoja, «Más» queda marcado; de lo
		 * contrario la barra no mostraría nada activo y parecería perdida.
		 */
		printf(
			'<button type="button" class="esc-tab-mas%3$s" id="esc-mas" aria-expanded="false">%1$s<span>%2$s</span></button>',
			self::icon( 'mas' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Más', 'malka-crm' ),
			$en_barra ? '' : ' on'
		);

		echo '</nav>';
		// El pomodoro ya no vive acá: está en el encabezado (render_header),
		// a la vista desde cualquier sección y al alcance mientras se trabaja.
		echo '<div class="esc-side__foot"><b>' . esc_html( $user->display_name ) . '</b>' . esc_html( $user->user_email ) . '</div>';
		echo '</aside>';

		/*
		 * El velo va fuera de la barra, no dentro. La barra abierta crea su
		 * propio contexto de apilado (position + z-index), así que un velo
		 * hijo queda por encima de la lista por mucho z-index menor que se le
		 * ponga: la hoja se veía bien y no se podía tocar ningún ítem.
		 */
		printf(
			'<button type="button" class="esc-velo" id="esc-velo" aria-label="%s" hidden></button>',
			esc_attr__( 'Cerrar el menú', 'malka-crm' )
		);

		return ob_get_clean();
	}

	/**
	 * Pomodoro compacto de la barra lateral.
	 */
	private static function render_pomodoro() {
		$min  = max( 5, (int) Malka_CRM_Settings::get( 'pomodoro_min', 25 ) );
		$hoy  = Malka_CRM_Time::minutes_today();
		$proy = Malka_CRM_Analysis::active_projects();

		ob_start();
		echo '<div class="esc-pom">';

		// Encabezado: qué es y cuánto llevas hoy.
		printf(
			'<div class="spom__head"><b>%1$s</b><span>%2$s <b id="esc-pom-hoy">%3$s</b></span></div>',
			esc_html__( 'Pomodoro', 'malka-crm' ),
			esc_html__( 'Hoy', 'malka-crm' ),
			esc_html( Malka_CRM_Time::format_minutes( $hoy ) )
		);

		echo '<div class="spom">';
		echo '<div class="spom__r"><svg viewBox="0 0 88 88" aria-hidden="true">';
		echo '<circle cx="44" cy="44" r="40" fill="none" stroke="var(--esc-fill)" stroke-width="3"/>';
		echo '<circle id="esc-pom-arc" cx="44" cy="44" r="40" fill="none" stroke="var(--esc-accent)" stroke-width="3" stroke-linecap="round" stroke-dasharray="251" stroke-dashoffset="0" transform="rotate(-90 44 44)"/>';
		echo '</svg><b id="esc-pom-time">' . esc_html( sprintf( '%02d:00', $min ) ) . '</b></div>';

		printf( '<label class="spom__f"><span>%s</span>', esc_html__( 'En qué trabajas', 'malka-crm' ) );
		echo '<select class="spom__sel" id="esc-pom-proj">';
		// Por defecto, el trabajo del propio estudio: lo que no es de un cliente.
		echo '<option value="0" selected>' . esc_html__( 'Trabajo del estudio', 'malka-crm' ) . '</option>';
		foreach ( $proy as $pid ) {
			// El cliente viaja aparte para poder buscar también por él.
			$cli = (string) get_post_meta( $pid, '_malka_pm_client_name', true );
			printf(
				'<option value="%1$s" data-cliente="%2$s">%3$s</option>',
				esc_attr( $pid ),
				esc_attr( $cli ),
				esc_html( get_the_title( $pid ) )
			);
		}
		echo '</select></label>';

		echo '<button type="button" class="spom__go" id="esc-pom-go">' . esc_html__( 'Iniciar', 'malka-crm' ) . '</button>';
		echo '<div class="spom__l">';
		echo '<button type="button" id="esc-pom-focus"><svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 6V2.5H6M10 2.5h3.5V6M13.5 10v3.5H10M6 13.5H2.5V10"/></svg>' . esc_html__( 'Enfoque', 'malka-crm' ) . '</button>';
		echo '<button type="button" id="esc-pom-reset"><svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2.75 8a5.25 5.25 0 1 0 1.6-3.77M2.75 2.5v3h3"/></svg>' . esc_html__( 'Reiniciar', 'malka-crm' ) . '</button>';
		echo '</div></div>';

		echo '</div>';

		return ob_get_clean();
	}

	/* ─────────────────────────────  Cabecera  ─────────────────────────── */

	/**
	 * @param string $vista Vista activa.
	 */
	private static function render_header( $vista = 'inicio' ) {
		$user   = wp_get_current_user();
		$nombre = $user->first_name ? $user->first_name : $user->display_name;
		$hora   = (int) current_time( 'G' );

		if ( $hora < 12 ) {
			$saludo = __( 'Buenos días', 'malka-crm' );
		} elseif ( $hora < 20 ) {
			$saludo = __( 'Buenas tardes', 'malka-crm' );
		} else {
			$saludo = __( 'Buenas noches', 'malka-crm' );
		}

		// Fuera del inicio, el título es el de la vista y no el saludo.
		$titulos = array(
			'estudio'       => array( __( 'Estudio', 'malka-crm' ), __( 'Tus negocios, tus clientes y lo que hay que hacer', 'malka-crm' ) ),
			'personal'      => array( __( 'Finanzas', 'malka-crm' ), __( 'Tu plata: tus cuentas, tus tarjetas y lo que el estudio te debe', 'malka-crm' ) ),
			'crecer'        => array( __( 'Finanzas', 'malka-crm' ), __( 'Dónde pusiste plata para que crezca', 'malka-crm' ) ),
			'deudas'        => array( __( 'Finanzas', 'malka-crm' ), __( 'Lo que debes, lo que te cuesta deberlo y en qué orden conviene pagarlo', 'malka-crm' ) ),
			'cargar'        => array( __( 'Finanzas', 'malka-crm' ), __( 'Tus cuentas escritas de una vez, para revisar y confirmar', 'malka-crm' ) ),
			'bienestar'     => array( __( 'Bienestar', 'malka-crm' ), __( 'Lo que te sostiene fuera del trabajo', 'malka-crm' ) ),
			'cuerpo'        => array( __( 'Bienestar', 'malka-crm' ), __( 'Exámenes, controles y cómo te estás moviendo', 'malka-crm' ) ),
			'mente'         => array( __( 'Bienestar', 'malka-crm' ), __( 'Terapia, ánimo y descanso', 'malka-crm' ) ),
			'vinculos'      => array( __( 'Bienestar', 'malka-crm' ), __( 'La gente que importa y cuándo fue la última vez', 'malka-crm' ) ),
			'vos'           => array( __( 'Bienestar', 'malka-crm' ), __( 'Lo que haces porque te hace bien', 'malka-crm' ) ),
			'cotizaciones'  => array( __( 'Cotizaciones', 'malka-crm' ), __( 'Lo cotizado, lo ganado y lo que todavía no responden', 'malka-crm' ) ),
			'finanzas'      => array( __( 'Finanzas', 'malka-crm' ), __( 'Lo que entra, lo que falta cobrar y lo que se factura', 'malka-crm' ) ),
			'cobranza'      => array( __( 'Finanzas', 'malka-crm' ), __( 'Lo que falta por entrar y a quién hay que escribirle', 'malka-crm' ) ),
			'perdidas'      => array( __( 'Finanzas', 'malka-crm' ), __( 'Lo que dejaste de perseguir. El cliente lo sigue viendo igual', 'malka-crm' ) ),
			'cobros'        => array( __( 'Cobros en tu saldo', 'malka-crm' ), __( 'Marca los que están dentro del saldo de tu cuenta', 'malka-crm' ) ),
			'negocios'      => array( __( 'Negocios', 'malka-crm' ), __( 'En qué andas con cada cliente', 'malka-crm' ) ),
			'negocio'       => array( __( 'Negocio', 'malka-crm' ), __( 'Sus datos, sus etapas y sus tareas', 'malka-crm' ) ),
			'clientes'      => array( __( 'Clientes', 'malka-crm' ), __( 'Con quién trabajas y cuánto te ha dejado cada uno', 'malka-crm' ) ),
			'cliente'       => array( __( 'Cliente', 'malka-crm' ), __( 'Su ficha, sus datos y lo que han trabajado juntos', 'malka-crm' ) ),
			'proveedores'   => array( __( 'Proveedores', 'malka-crm' ), __( 'A quién le compras y con qué datos se le factura', 'malka-crm' ) ),
			'proveedor'     => array( __( 'Proveedor', 'malka-crm' ), __( 'Sus datos y los que usas para emitirle en el SII', 'malka-crm' ) ),
			'plantillas'    => array( __( 'Negocios', 'malka-crm' ), __( 'Las etapas de cada producto o servicio, listas para aplicar', 'malka-crm' ) ),
			'plantilla'     => array( __( 'Plantilla', 'malka-crm' ), __( 'La estructura: números, nombres y responsables. Las fechas salen al aplicarla', 'malka-crm' ) ),
			'documentos'    => array( __( 'Finanzas', 'malka-crm' ), __( 'Lo que emites y lo que te emiten, anotado a mano mientras el SII no esté conectado', 'malka-crm' ) ),
			'documento'     => array( __( 'Documento', 'malka-crm' ), __( 'Los números que de verdad salieron', 'malka-crm' ) ),
			'mensajes'      => array( __( 'Configuración', 'malka-crm' ), __( 'Los correos que les llegan a tus clientes, con tus palabras', 'malka-crm' ) ),
			'textos'        => array( __( 'Configuración', 'malka-crm' ), __( 'Las frases que el cliente lee dentro de la cotización', 'malka-crm' ) ),
			'configuracion' => array( __( 'Configuración', 'malka-crm' ), __( 'Tu estudio, tu marca, cómo cobras y lo que les escribes a tus clientes', 'malka-crm' ) ),
			'movimientos'   => array( __( 'Finanzas', 'malka-crm' ), __( 'El saldo de la cuenta y todo lo que entra y sale', 'malka-crm' ) ),
			'contabilidad'  => array( __( 'Finanzas', 'malka-crm' ), __( 'El F29 de cada mes, casilla por casilla', 'malka-crm' ) ),
			'horas'         => array( __( 'Estudio', 'malka-crm' ), __( 'Lo que registra el pomodoro, por proyecto', 'malka-crm' ) ),
			'tareas'        => array( __( 'Estudio', 'malka-crm' ), __( 'Lo que falta hacer en el trabajo, de los negocios y del estudio', 'malka-crm' ) ),
			'pagos'         => array( __( 'Estudio', 'malka-crm' ), __( 'Lo que el estudio tiene que pagar este mes', 'malka-crm' ) ),
			'casa'          => array( __( 'Casa', 'malka-crm' ), __( 'Lo que se hizo en la casa, quién lo hizo y cuándo', 'malka-crm' ) ),
			'costos-fijos'  => array( __( 'Finanzas', 'malka-crm' ), __( 'Lo que pagas todos los meses exista o no trabajo', 'malka-crm' ) ),
			'suscripciones' => array( __( 'Finanzas', 'malka-crm' ), __( 'Lo que se renueva solo y se puede dar de baja', 'malka-crm' ) ),
			'agenda'        => array( __( 'Agenda', 'malka-crm' ), __( 'Tus reuniones, llamadas y visitas', 'malka-crm' ) ),
			'hizo'          => array( __( 'Estudio', 'malka-crm' ), __( 'Lo que tus clientes entregaron y falta que revises', 'malka-crm' ) ),
		);

		if ( 'cotizacion' === $vista ) {
			$qid = Malka_CRM_Quote_Editor::current_id();

			$titulos['cotizacion'] = $qid
				? array(
					sprintf(
						/* translators: número de la cotización */
						__( 'Cotización N° %s', 'malka-crm' ),
						(string) get_post_meta( $qid, Malka_CRM_Quote_Page::META_NUM, true )
					),
					get_the_title( $qid ),
				)
				: array(
					__( 'Nueva cotización', 'malka-crm' ),
					__( 'Se guarda acá y el cliente la ve en su propia página', 'malka-crm' ),
				);
		}

		// Los pagos del estudio que ya tocan van primero en la campana.
		$obs = array_merge( class_exists( 'Malka_CRM_Pagos' ) ? Malka_CRM_Pagos::avisos() : array(), Malka_CRM_Analysis::observations() );

		ob_start();
		echo '<header class="esc-head">';

		echo '<div class="esc-date"><em>' . esc_html( current_time( 'j' ) ) . '</em><span><b>' .
			esc_html( ucfirst( wp_date( 'l' ) ) ) . '</b>' . esc_html( ucfirst( wp_date( 'M Y' ) ) ) . '</span></div>';

		if ( isset( $titulos[ $vista ] ) ) {
			echo '<div class="esc-hi"><h1>' . esc_html( $titulos[ $vista ][0] ) . '</h1>';
			echo '<p>' . esc_html( $titulos[ $vista ][1] ) . '</p></div>';
		} else {
			echo '<div class="esc-hi"><h1>' . esc_html( $saludo . ', ' . $nombre ) . '</h1>';
			echo '<p>' . esc_html( self::header_line() ) . '</p></div>';
		}

		echo '<div class="esc-act">';

		/*
		 * El pomodoro, como un reloj chico en el encabezado. En la barra
		 * lateral quedaba entre el menú y la cuenta, lejos de lo que se está
		 * haciendo; acá se ve desde cualquier sección, muestra el tiempo
		 * corriendo y al tocarlo abre los controles completos.
		 */
		$min = max( 5, (int) Malka_CRM_Settings::get( 'pomodoro_min', 25 ) );
		/*
		 * El reloj y el + van juntos, como un solo bloque: son las dos cosas
		 * que se usan sin importar en qué sección estés. En el teléfono ese
		 * bloque flota sobre la barra de abajo, al alcance del pulgar.
		 */
		echo '<div class="esc-quick">';
		echo '<div class="esc-timer">';
		printf(
			'<button type="button" class="esc-timer__btn" id="esc-timer" aria-expanded="false" aria-haspopup="true" title="%1$s"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9.5 2.5h5"/></svg><b id="esc-pom-mini">%2$s</b></button>',
			esc_attr__( 'Pomodoro', 'malka-crm' ),
			esc_html( sprintf( '%02d:00', $min ) )
		);
		// Play y pausa sin abrir nada: el reloj está siempre a mano.
		printf(
			'<button type="button" class="esc-timer__go" id="esc-timer-go" aria-label="%1$s" title="%1$s"><svg class="i-play" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" fill="none" stroke="currentColor" stroke-linejoin="round"><path d="M4.8 3v10L12.8 8z"/></svg><svg class="i-pause" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M5.5 3v10M10.5 3v10"/></svg></button>',
			esc_attr__( 'Iniciar o pausar el pomodoro', 'malka-crm' )
		);
		echo '<div class="esc-notis esc-timer__pop" id="esc-timer-pop">';
		echo self::render_pomodoro(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div>';

		/*
		 * Todo lo que se crea cuelga de acá. El + está en el encabezado, que
		 * se queda arriba al bajar, así que agregar algo no depende de en qué
		 * sección estés: antes cada cosa tenía su formulario en su pantalla y
		 * había que ir a buscarla.
		 *
		 * Lo de todos los días primero —tarea y movimiento—, después lo que
		 * se hace unas pocas veces al mes.
		 */
		echo '<div class="esc-add"><button class="esc-add__btn" id="esc-add" aria-label="' . esc_attr__( 'Crear', 'malka-crm' ) . '" aria-expanded="false">+</button>';
		echo '<div class="esc-menu" id="esc-menu">';
		printf(
			'<button type="button" class="esc-menu__i" id="esc-open-tarea">%s<i>%s</i></button>',
			esc_html__( 'Nueva tarea', 'malka-crm' ),
			esc_html__( 'tuya o de un cliente', 'malka-crm' )
		);
		printf(
			'<button type="button" class="esc-menu__i" id="esc-open-evento">%s<i>%s</i></button>',
			esc_html__( 'Nuevo evento', 'malka-crm' ),
			esc_html__( 'una reunión, una llamada, una visita', 'malka-crm' )
		);
		printf(
			'<button type="button" class="esc-menu__i" id="esc-open-mov">%s<i>%s</i></button>',
			esc_html__( 'Registrar movimiento', 'malka-crm' ),
			esc_html__( 'plata que entra o sale', 'malka-crm' )
		);

		if ( class_exists( 'Malka_CRM_Deudas' ) ) {
			printf(
				'<button type="button" class="esc-menu__i" id="esc-open-deuda">%s<i>%s</i></button>',
				esc_html__( 'Anotar una deuda', 'malka-crm' ),
				esc_html__( 'una compra en cuotas, un crédito, una línea', 'malka-crm' )
			);
		}

		/*
		 * Un documento tributario: el tipo —boleta, factura afecta o
		 * exenta, honorarios, nota— y si es venta o compra se eligen en el
		 * formulario. Ponerlos acá habría hecho un menú de diez entradas
		 * para algo que de todos modos hay que llenar.
		 */
		if ( class_exists( 'Malka_CRM_Documents' ) ) {
			printf(
				'<a class="esc-menu__i" href="%s">%s<i>%s</i></a>',
				esc_url( Malka_CRM_Documents::edit_url() ),
				esc_html__( 'Nuevo documento', 'malka-crm' ),
				esc_html__( 'boleta, factura de venta o de compra', 'malka-crm' )
			);
		}

		echo '<div class="esc-menu__s"></div>';
		printf(
			'<a class="esc-menu__i" href="%s">%s<i>%s</i></a>',
			esc_url( Malka_CRM_Projects::edit_url() ),
			esc_html__( 'Nuevo negocio', 'malka-crm' ),
			esc_html__( 'un trabajo para un cliente', 'malka-crm' )
		);
		printf(
			'<a class="esc-menu__i" href="%s">%s<i>%s</i></a>',
			esc_url( Malka_CRM_Quote_Editor::edit_url() ),
			esc_html__( 'Nueva cotización', 'malka-crm' ),
			esc_html__( 'con sus cuotas y su enlace', 'malka-crm' )
		);
		echo '<div class="esc-menu__s"></div>';
		printf( '<a class="esc-menu__i esc-menu__i--tenue" href="%s">%s</a>', esc_url( admin_url() ), esc_html__( 'Ir a WP-Admin', 'malka-crm' ) );
		echo '</div></div>';

		// Cierra el bloque del reloj y el +.
		echo '</div><!--/quick-->';

		/*
		 * «Personalizar» vive arriba, junto al +: es una acción sobre la
		 * página entera y, suelto encima del primer cuadro, parecía parte
		 * del contenido. Sólo en Inicio, que es donde está el mosaico.
		 */
		if ( 'inicio' === $vista ) {
			printf(
				'<button type="button" class="esc-custom" id="esc-personalizar" aria-pressed="false" title="%2$s"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/></svg><span>%1$s</span></button>',
				esc_html__( 'Personalizar', 'malka-crm' ),
				esc_attr__( 'Ordenar, esconder o cambiar el tamaño de los cuadros', 'malka-crm' )
			);
		}


		echo self::boton_vacaciones(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="esc-bell"><button class="esc-bell__btn" id="esc-bell" aria-label="' . esc_attr__( 'Notificaciones', 'malka-crm' ) . '" aria-expanded="false">';
		echo self::icon( 'campana' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $obs ) ) {
			echo '<span class="esc-bell__n">' . esc_html( count( $obs ) ) . '</span>';
		}
		echo '</button>';
		echo '<div class="esc-notis" id="esc-notis"><div class="esc-notis__h"><b>' . esc_html__( 'Notificaciones', 'malka-crm' ) . '</b></div>';

		if ( empty( $obs ) ) {
			echo '<p class="esc-empty">' . esc_html__( 'Nada pendiente por ahora.', 'malka-crm' ) . '</p>';
		}
		foreach ( $obs as $o ) {
			printf(
				'<div class="esc-noti"><i class="dot dot--%1$s"></i><span><span class="esc-noti__t">%2$s</span><span class="esc-noti__w">%3$s</span></span></div>',
				esc_attr( $o['nivel'] ),
				esc_html( $o['titulo'] ),
				esc_html( $o['texto'] )
			);
		}

		echo '</div></div>';
		echo '</div></header>';

		return ob_get_clean();
	}

	/**
	 * «Cobros en tu saldo»: una casilla por cuota cobrada.
	 *
	 * Marcada quiere decir «esta plata está dentro del saldo». Se puede
	 * desmarcar, y al desmarcar el saldo baja: cualquier cosa que sume sin
	 * poder restar es una trampa, porque el error se queda puesto.
	 *
	 * @return string
	 */
	public static function view_cobros() {
		$lista = class_exists( 'Malka_CRM_Quotes' ) ? Malka_CRM_Quotes::cobros_todos() : array();

		ob_start();
		echo '<div class="esc-cot"><section class="esc-panel esc-cobros-v">';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$mas   = isset( $_GET['mas'] ) ? (int) $_GET['mas'] : 0;
		$menos = isset( $_GET['menos'] ) ? (int) $_GET['menos'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $mas || $menos ) {
			$dicho = array();
			if ( $mas ) {
				/* translators: cantidad */
				$dicho[] = sprintf( _n( 'entró %d cobro', 'entraron %d cobros', $mas, 'malka-crm' ), $mas );
			}
			if ( $menos ) {
				/* translators: cantidad */
				$dicho[] = sprintf( _n( 'salió %d', 'salieron %d', $menos, 'malka-crm' ), $menos );
			}
			echo '<p class="esc-cobros-v__ok">' . esc_html( ucfirst( implode( ' y ', $dicho ) ) . '. ' ) .
				esc_html__( 'El saldo ya quedó con eso.', 'malka-crm' ) . '</p>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['cuadrado'] ) ) {
			echo '<p class="esc-cobros-v__ok">' . esc_html(
				sprintf(
					/* translators: monto */
					__( 'Listo: tu saldo quedó en %s, igual que el banco. Lo de antes de esa fecha ya está dentro de ese número.', 'malka-crm' ),
					Malka_CRM_Settings::money( Malka_CRM_Finance::balance() )
				)
			) . '</p>';
		}

		echo self::cuadre_banco(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! $lista ) {
			echo '</section></div>';
			return ob_get_clean();
		}

		// Lo cobrado antes del cuadre no se elige: ya está dentro del saldo.
		$antes = array_values( array_filter( $lista, function ( $c ) { return ! empty( $c['antes'] ); } ) );
		$lista = array_values( array_filter( $lista, function ( $c ) { return empty( $c['antes'] ); } ) );

		if ( ! $lista ) {
			echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'peso',
				__( 'Cobros después del cuadre', 'malka-crm' )
			);
			echo '<p class="esc-empty">' . esc_html__( 'Ninguno todavía. Cuando marques una cuota como completada, entra sola al saldo y aparece acá.', 'malka-crm' ) . '</p>';
			echo self::cobros_antiguos( $antes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</section></div>';
			return ob_get_clean();
		}

		$dentro = 0;
		$suma   = 0;
		foreach ( $lista as $c ) {
			if ( ! empty( $c['dentro'] ) ) {
				$dentro++;
				$suma += (int) $c['monto'];
			}
		}

		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'peso',
			__( 'Cobros después del cuadre', 'malka-crm' ),
			__( 'Marcado = está sumado sobre el saldo del banco', 'malka-crm' ),
			Malka_CRM_Settings::money( $suma ),
			/* translators: 1: marcados, 2: total */
			sprintf( __( '%1$d de %2$d', 'malka-crm' ), $dentro, count( $lista ) )
		);

		printf(
			'<form method="post" action="%1$s" id="esc-cobros-form">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_cobros_guardar" />',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_cobros_guardar', '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		printf(
			'<div class="esc-cobros-v__top"><button type="button" class="esc-btn" id="esc-cobros-todos">%s</button>' .
			'<button type="button" class="esc-btn" id="esc-cobros-ninguno">%s</button></div>',
			esc_html__( 'Marcar todos', 'malka-crm' ),
			esc_html__( 'Desmarcar todos', 'malka-crm' )
		);

		echo '<ul class="esc-cobros-v__l">';
		foreach ( $lista as $c ) {
			printf(
				'<li><label><input type="checkbox" name="cobro[]" value="%1$s"%2$s data-monto="%3$d" />' .
				'<span class="esc-cobros-v__t"><b>%4$s</b><em>%5$s</em></span>' .
				'<span class="esc-cobros-v__m">%6$s</span></label></li>',
				esc_attr( $c['clave'] ),
				checked( ! empty( $c['dentro'] ), true, false ),
				(int) $c['monto'],
				esc_html( $c['titulo'] . ( $c['cliente'] ? ' · ' . $c['cliente'] : '' ) ),
				esc_html(
					trim(
						( $c['numero'] ? 'Nº ' . $c['numero'] : '' ) .
						( $c['fecha'] ? ( $c['numero'] ? ' · ' : '' ) . date_i18n( 'j M Y', strtotime( $c['fecha'] ) ) : '' )
					)
				),
				esc_html( Malka_CRM_Settings::money( (int) $c['monto'] ) )
			);
		}
		echo '</ul>';

		printf(
			'<div class="esc-cobros-v__pie"><span id="esc-cobros-suma">%1$s</span>' .
			'<button type="submit" class="esc-btn esc-btn--primary">%2$s</button></div></form>',
			esc_html( sprintf( /* translators: monto */ __( 'Se sumarían %s sobre el saldo del banco', 'malka-crm' ), Malka_CRM_Settings::money( $suma ) ) ),
			esc_html__( 'Guardar', 'malka-crm' )
		);

		echo self::cobros_antiguos( $antes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</section></div>';
		return ob_get_clean();
	}

	/**
	 * El formulario para dejar el saldo igual que la cartola.
	 *
	 * Es la pieza que faltaba: el saldo no es la suma de los cobros, es lo
	 * que dice el banco. Con su fecha, todo lo cobrado antes queda dentro
	 * de ese número y deja de sumarse por su cuenta.
	 *
	 * @return string
	 */
	public static function cuadre_banco() {
		$corte = Malka_CRM_Finance::corte();

		ob_start();
		echo '<div class="esc-cuadre">';
		echo '<h3>' . esc_html__( 'Cuadrar con el banco', 'malka-crm' ) . '</h3>';
		echo '<p>' . esc_html__( 'Copia el saldo que te muestra tu banco hoy. Desde esa fecha hacia adelante, cada cuota que marques como completada se suma sola.', 'malka-crm' ) . '</p>';

		printf(
			'<form method="post" action="%1$s">%2$s<input type="hidden" name="action" value="malka_crm_cuadrar" />' .
			'<div class="esc-cuadre__f">' .
			'<label><span>%3$s</span><input type="text" name="saldo" inputmode="numeric" placeholder="508.416" required /></label>' .
			'<label><span>%4$s</span><input type="date" name="fecha" value="%5$s" max="%5$s" /></label>' .
			'</div><button type="submit" class="esc-btn esc-btn--primary">%6$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_cuadrar', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Saldo que dice el banco', 'malka-crm' ),
			esc_html__( 'Al día', 'malka-crm' ),
			esc_attr( current_time( 'Y-m-d' ) ),
			esc_html__( 'Dejar el saldo así', 'malka-crm' )
		);

		if ( $corte ) {
			/*
			 * Sólo la fecha. El monto que hay guardado ya no es el de la
			 * cartola —se movió con cada cobro posterior—, así que ponerlo
			 * acá diciendo «último cuadre» sería mentir.
			 */
			printf(
				'<span class="esc-cuadre__u">%s</span>',
				esc_html(
					sprintf(
						/* translators: fecha */
						__( 'Último cuadre: %s. Lo cobrado hasta ese día ya está dentro.', 'malka-crm' ),
						date_i18n( 'j M Y', strtotime( $corte ) )
					)
				)
			);
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Los cobros anteriores al cuadre: se ven, no se tocan.
	 *
	 * @param array[] $antes Filas de cobros_todos() con 'antes'.
	 * @return string
	 */
	public static function cobros_antiguos( $antes ) {
		if ( ! $antes ) {
			return '';
		}

		$suma = 0;
		foreach ( $antes as $c ) {
			$suma += (int) $c['monto'];
		}

		ob_start();
		echo '<details class="esc-cobros-v__antes"><summary>';
		printf(
			'%1$s <b>%2$s</b>',
			esc_html(
				sprintf(
					/* translators: cantidad */
					_n( '%d cobro anterior a tu cuadre', '%d cobros anteriores a tu cuadre', count( $antes ), 'malka-crm' ),
					count( $antes )
				)
			),
			esc_html( Malka_CRM_Settings::money( $suma ) )
		);
		echo '</summary>';
		echo '<p>' . esc_html__( 'Esta plata ya está dentro del saldo que te da el banco, así que no se suma de nuevo. Queda anotada en Movimientos.', 'malka-crm' ) . '</p>';
		echo '<ul class="esc-cobros-v__l">';
		foreach ( $antes as $c ) {
			printf(
				'<li><span class="esc-cobros-v__t"><b>%1$s</b><em>%2$s</em></span><span class="esc-cobros-v__m">%3$s</span></li>',
				esc_html( $c['titulo'] . ( $c['cliente'] ? ' · ' . $c['cliente'] : '' ) ),
				esc_html(
					trim(
						( $c['numero'] ? 'Nº ' . $c['numero'] : '' ) .
						( $c['fecha'] ? ( $c['numero'] ? ' · ' : '' ) . date_i18n( 'j M Y', strtotime( $c['fecha'] ) ) : '' )
					)
				),
				esc_html( Malka_CRM_Settings::money( (int) $c['monto'] ) )
			);
		}
		echo '</ul></details>';
		return ob_get_clean();
	}

	/**
	 * El botón de vacaciones, junto a la campana.
	 *
	 * Apagado es una palmera y se abre para preguntar hasta cuándo. Encendido
	 * no pregunta nada: un toque y se acabó, porque volver y tener que
	 * contestar un formulario para leer tus correos sería absurdo.
	 *
	 * @return string
	 */
	public static function boton_vacaciones() {
		if ( ! class_exists( 'Malka_CRM_Vacaciones' ) ) {
			return '';
		}

		$activo = Malka_CRM_Vacaciones::activo();
		$hasta  = Malka_CRM_Vacaciones::hasta();
		$accion = esc_url( admin_url( 'admin-post.php' ) );
		$nonce  = wp_nonce_field( 'malka_crm_vacaciones', '_wpnonce', true, false );
		/*
		 * Una palmera, no una maleta: la maleta es irse de viaje y esto es
		 * dejar de trabajar, que no es lo mismo.
		 *
		 * Las hojas son contornos cerrados, no un trazo suelto: de un trazo
		 * la palmera se leía como un paraguas. El tronco es de doble línea y
		 * cierra abajo con su propio pie, sin línea de suelo. Va a 1.5 y no a
		 * 1.7 como el resto de los íconos porque las hojas tienen huecos por
		 * dentro y a 17 px con 1.7 se tapaban; a ojo pesa igual. Sin las
		 * marcas del tronco: a 17 px convierten el tronco en una escalerita.
		 */
		$maleta = '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' .
			// Las dos hojas de arriba, que suben desde el cogollo.
			'<path d="M12 9C10.4 4.4 7 2.6 4.8 4.8L5.9 6.3C8.2 6.8 10.3 7.8 12 9Z"/>' .
			'<path d="M12 9c1.6-4.6 5-6.4 7.2-4.2l-1.1 1.5C15.8 6.8 13.7 7.8 12 9Z"/>' .
			// Las dos de abajo, que se abren casi horizontales y caen en la punta.
			'<path d="M12 9C8.6 6.4 4.2 7.4 2.4 12.4L4.2 11.9C6.6 10.5 9.3 9.6 12 9Z"/>' .
			'<path d="M12 9c3.4-2.6 7.8-1.6 9.6 3.4L19.8 11.9C17.4 10.5 14.7 9.6 12 9Z"/>' .
			// El tronco: canto izquierdo, pie y canto derecho, en un solo trazo.
			'<path d="M11.2 9.6C11.4 13.2 10.6 17.2 9 20.5L12.5 20.5C13.1 17.2 13.1 13.2 12.9 9.6"/></svg>';

		ob_start();
		echo '<div class="esc-vac' . ( $activo ? ' is-on' : '' ) . '">';

		if ( $activo ) {
			printf(
				'<form method="post" action="%1$s">%2$s<input type="hidden" name="action" value="malka_crm_vacaciones" />' .
				'<button type="submit" class="esc-vac__btn" title="%3$s">%4$s<b>%5$s</b></button></form>',
				$accion,
				$nonce, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_attr(
					$hasta
						/* translators: fecha de vuelta */
						? sprintf( __( 'Vuelves el %s. Tócalo para volver ahora.', 'malka-crm' ), date_i18n( 'd/m', strtotime( $hasta ) ) )
						: __( 'Tócalo para volver y ver lo que llegó.', 'malka-crm' )
				),
				$maleta, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html__( 'De vacaciones', 'malka-crm' )
			);
			echo '</div>';
			return ob_get_clean();
		}

		printf(
			'<button type="button" class="esc-vac__btn" id="esc-vac" aria-expanded="false" aria-haspopup="true" aria-label="%1$s" title="%1$s">%2$s</button>',
			esc_attr__( 'Modo vacaciones', 'malka-crm' ),
			$maleta // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		echo '<div class="esc-notis esc-vac__pop" id="esc-vac-pop">';
		echo '<div class="esc-notis__h"><b>' . esc_html__( 'Modo vacaciones', 'malka-crm' ) . '</b></div>';
		echo '<p class="esc-vac__q">' . esc_html__( 'Tus clientes no notan nada: sus cuotas vencen igual y la cobranza les sigue llegando. Lo que se guarda es lo que te llega a ti.', 'malka-crm' ) . '</p>';

		printf(
			'<form method="post" action="%1$s">%2$s<input type="hidden" name="action" value="malka_crm_vacaciones" />' .
			'<label class="esc-vac__f"><span>%3$s</span><input type="date" name="hasta" value="%4$s" min="%5$s" /></label>' .
			'<label class="esc-vac__c"><input type="checkbox" name="impuestos" value="1" checked /><span>%6$s</span></label>' .
			'<button type="submit" class="esc-btn esc-btn--primary">%7$s</button></form>',
			$accion,
			$nonce, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Vuelves el (opcional)', 'malka-crm' ),
			'',
			esc_attr( current_time( 'Y-m-d' ) ),
			esc_html__( 'Avísame igual de impuestos y pagos del estudio', 'malka-crm' ),
			esc_html__( 'Activar', 'malka-crm' )
		);

		echo '</div></div>';
		return ob_get_clean();
	}

	/**
	 * Lo que llegó mientras no estaba, al volver.
	 *
	 * @return string
	 */
	public static function panel_vuelta() {
		if ( ! class_exists( 'Malka_CRM_Vacaciones' ) ) {
			return '';
		}

		$e   = Malka_CRM_Vacaciones::estado();
		$lis = isset( $e['resumen'] ) ? (array) $e['resumen'] : array();

		if ( ! empty( $e['activo'] ) || ! $lis ) {
			return '';
		}

		ob_start();
		echo '<section class="esc-panel esc-vuelta">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'campana',
			__( 'Mientras no estabas', 'malka-crm' ),
			__( 'Los avisos que se guardaron, del más nuevo al más viejo', 'malka-crm' ),
			(string) count( $lis )
		);

		echo '<ul class="esc-vuelta__l">';
		foreach ( array_reverse( $lis ) as $m ) {
			printf(
				'<li><b>%1$s</b>%2$s<em>%3$s</em></li>',
				esc_html( isset( $m['asunto'] ) ? $m['asunto'] : '' ),
				! empty( $m['linea'] ) ? '<span>' . esc_html( $m['linea'] ) . '</span>' : '',
				esc_html( ! empty( $m['fecha'] ) ? date_i18n( 'j M · H:i', strtotime( $m['fecha'] ) ) : '' )
			);
		}
		echo '</ul>';

		printf(
			'<form method="post" action="%1$s">%2$s<input type="hidden" name="action" value="malka_crm_vacaciones_visto" />' .
			'<button type="submit" class="esc-btn">%3$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_vacaciones_visto', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Listo, ya los vi', 'malka-crm' )
		);

		echo '</section>';
		return ob_get_clean();
	}

	/**
	 * La línea de estado bajo el saludo.
	 */
	private static function header_line() {
		$partes  = array();
		$vencido = 0;

		// De vacaciones, lo primero es decirlo: si no, el panel se ve igual
		// que siempre y no hay forma de acordarse de que está puesto.
		if ( class_exists( 'Malka_CRM_Vacaciones' ) && Malka_CRM_Vacaciones::activo() ) {
			$hasta = Malka_CRM_Vacaciones::hasta();
			$partes[] = $hasta
				/* translators: fecha de vuelta */
				? sprintf( __( 'De vacaciones · vuelves el %s', 'malka-crm' ), date_i18n( 'j \d\e F', strtotime( $hasta ) ) )
				: __( 'De vacaciones · tus correos quedan guardados', 'malka-crm' );
		}

		foreach ( Malka_CRM_Analysis::overdue_payments() as $p ) {
			$vencido += $p['monto'];
		}

		$tareas = count( Malka_CRM_Analysis::studio_tasks() );
		if ( $tareas ) {
			/* translators: cantidad de tareas */
			$partes[] = sprintf( _n( '%d tarea pendiente', '%d tareas pendientes', $tareas, 'malka-crm' ), $tareas );
		}

		if ( $vencido > 0 ) {
			/* translators: monto vencido */
			$partes[] = sprintf( __( '%s vencidos por cobrar', 'malka-crm' ), Malka_CRM_Settings::money( $vencido ) );
		}

		if ( empty( $partes ) ) {
			return __( 'Nada vencido y nada urgente. Buen día para avanzar.', 'malka-crm' );
		}

		return implode( ' · ', $partes );
	}

	/* ─────────────────────────────  Objetivo  ─────────────────────────── */

	/**
	 * Costos fijos y Suscripciones: la misma lista, en dos pantallas.
	 *
	 * Las dos muestran lo que se paga todos los meses sin que nadie lo pida.
	 * Se separan porque no se deciden igual: el arriendo y la contadora
	 * sostienen el estudio y no se dan de baja un martes; el Adobe y el
	 * hosting se renuevan solos y se pueden cortar. Son la misma lista
	 * filtrada, no dos listas: una fila se mueve de una a otra cambiándole
	 * el tipo en su propia ficha.
	 *
	 * Muestra los dos ámbitos, del estudio y tuyos, porque la lista es una
	 * y cada fila dice de quién es. Lo que decide el cálculo de la tarifa
	 * sigue siendo el ámbito, no la pantalla donde se mire.
	 *
	 * @param string $tipo 'fijo' o 'suscripcion'.
	 * @return string
	 */
	public static function view_costos( $tipo = 'fijo' ) {
		if ( ! class_exists( 'Malka_CRM_Suscripciones' ) ) {
			return '';
		}

		$otra = 'suscripcion' === $tipo ? 'costos-fijos' : 'suscripciones';

		ob_start();
		echo '<div class="esc-vista esc-vista--costos">';
		echo Malka_CRM_Suscripciones::panel( 'todo', $tipo ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<p class="esc-hint">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html(
				'suscripcion' === $tipo
					? __( 'Acá va lo que se renueva solo y se puede dar de baja. Lo que sostiene el estudio exista o no trabajo —el arriendo, la contadora, el internet— va en', 'malka-crm' )
					: __( 'Acá va lo que pagas exista o no trabajo. Lo que se renueva solo y se puede cortar —Adobe, Figma, hosting— va en', 'malka-crm' )
			),
			esc_url( self::view_url( $otra ) ),
			esc_html( 'suscripcion' === $tipo ? __( 'Costos fijos', 'malka-crm' ) : __( 'Suscripciones', 'malka-crm' ) )
		);
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Cómo va la meta contra el mes, no contra el número pelado.
	 *
	 * Ir al 40% es buena noticia el día 5 y mala el día 25, así que lo
	 * vendido se compara con lo que lleva corrido el mes. Lo contrario
	 * —pintar de rojo todo lo que esté bajo—, dejaría la barra en rojo los
	 * primeros días de cada mes, cuando todavía no hay nada que corregir.
	 *
	 * @param array $g      Lo que devuelve Malka_CRM_Analysis::month_goal().
	 * @param int   $pct    Porcentaje vendido de lo que hay que vender.
	 * @param int   $faltan Cuánto falta, en pesos.
	 * @return array clase para la barra y la misma noticia en palabras.
	 */
	private static function ritmo_meta( $g, $pct, $faltan ) {
		if ( $faltan < 1 ) {
			return array(
				'clase' => 'bien',
				'texto' => __( 'meta cumplida', 'malka-crm' ),
			);
		}

		$dias_mes = max( 1, (int) $g['dias_mes'] );
		$ritmo    = (int) round( ( (int) $g['dia'] / $dias_mes ) * 100 );

		/*
		 * Diez puntos de tolerancia: un mes no se cobra parejo día a día
		 * —un pago grande mueve veinte puntos de una vez— y avisar de
		 * atraso por estar unos días corrida sería ruido, no información.
		 */
		if ( $pct >= $ritmo - 10 ) {
			return array(
				'clase' => 'bien',
				'texto' => __( 'vas a tiempo', 'malka-crm' ),
			);
		}

		// Atrás, pero con mes por delante para arreglarlo.
		if ( (int) $g['restantes'] > 7 ) {
			return array(
				'clase' => 'atras',
				'texto' => __( 'vas atrás del ritmo', 'malka-crm' ),
			);
		}

		return array(
			'clase' => 'tarde',
			'texto' => __( 'vas atrás y queda poco mes', 'malka-crm' ),
		);
	}

	public static function render_goal() {
		$falta = Malka_CRM_Onboarding::missing_for( 'objetivo' );
		$g     = Malka_CRM_Analysis::month_goal();
		$saldo = Malka_CRM_Finance::balance();

		ob_start();
		echo '<section class="esc-goal">';

		echo '<div class="esc-goal__l">';
		echo '<span class="esc-lbl">' . esc_html__( 'Meta del mes', 'malka-crm' ) . '</span>';

		if ( $falta ) {
			echo '<p class="esc-empty">' . esc_html( $falta ) . '</p>';
			printf(
				'<a class="esc-btn esc-btn--primary" href="%s">%s</a>',
				esc_url( self::view_url( 'configuracion' ) ),
				esc_html__( 'Configurar', 'malka-crm' )
			);
		} else {
			/*
			 * Lo que se mira es cuánto llevas vendido contra lo que necesitas
			 * vender este mes. Antes el número grande era el sueldo (ventas
			 * menos costos), que a principio de mes sale negativo aunque todo
			 * vaya bien: «-$414.000» desanimaba y no decía qué hacer.
			 */
			$necesitas = max( 1, (int) $g['meta'] + (int) $g['costos'] );
			$pct_v     = max( 0, min( 100, (int) round( ( (int) $g['ventas'] / $necesitas ) * 100 ) ) );
			$faltan_v  = max( 0, $necesitas - (int) $g['ventas'] );

			/*
			 * El plazo en palabras: «en 0 días» no se dice, y el último día del
			 * mes conviene que se note.
			 */
			if ( $g['restantes'] < 1 ) {
				$plazo = __( 'hoy es el último día', 'malka-crm' );
			} else {
				/* translators: días que quedan del mes */
				$plazo = sprintf( _n( 'en %d día', 'en %d días', $g['restantes'], 'malka-crm' ), $g['restantes'] );
			}

			$estado = self::ritmo_meta( $g, $pct_v, $faltan_v );

			/*
			 * El monto cobrado lleva a la lista de cuotas que lo suman, en
			 * Finanzas. Antes esa lista estaba acá mismo, en un desplegable
			 * bajo la barra: el Inicio dice cómo vas, y de dónde sale el
			 * número se revisa donde vive la plata.
			 */
			$cobrado_html = sprintf(
				'<a class="esc-goal__ver" href="%1$s">%2$s</a>',
				esc_url( self::view_url( 'finanzas' ) . '#esc-cobrado' ),
				esc_html( Malka_CRM_Settings::money( $g['ventas'] ) )
			);

			if ( $faltan_v < 1 ) {
				printf(
					'<span class="esc-goal__n">%1$s <em>%2$s %3$s · %4$s</em></span>',
					$cobrado_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html__( 'cobrado de', 'malka-crm' ),
					esc_html( Malka_CRM_Settings::money( $necesitas ) ),
					esc_html__( 'meta cumplida', 'malka-crm' )
				);
			} else {
				printf(
					'<span class="esc-goal__n">%1$s <em>%2$s %3$s · %4$s <b>%5$s</b> %6$s · %7$s</em></span>',
					$cobrado_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html__( 'cobrado de', 'malka-crm' ),
					esc_html( Malka_CRM_Settings::money( $necesitas ) ),
					esc_html__( 'te faltan', 'malka-crm' ),
					esc_html( Malka_CRM_Settings::money( $faltan_v ) ),
					esc_html( $plazo ),
					esc_html( $estado['texto'] )
				);
			}

			/*
			 * La barra cambia de color según cómo vas contra el mes, no contra
			 * el número pelado: el color también va dicho en palabras, porque
			 * un color solo no es una respuesta para quien no lo distingue.
			 */
			printf(
				'<div class="esc-bar esc-bar--meta is-%1$s" role="img" aria-label="%2$s"><i style="width:%3$d%%"></i></div>',
				esc_attr( $estado['clase'] ),
				esc_attr( sprintf( /* translators: 1: porcentaje, 2: cómo va */ __( '%1$d%% de la meta · %2$s', 'malka-crm' ), $pct_v, $estado['texto'] ) ),
				$pct_v
			);

			echo '<div class="esc-goal__calc">';
			printf( '<span>%s <b>%s</b></span>', esc_html__( 'Tu sueldo', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $g['meta'] ) ) );
			printf( '<span>+ %s <b>%s</b></span>', esc_html__( 'Costos del mes', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $g['costos'] ) ) );
			printf( '<span>= %s <b class="hi">%s</b></span>', esc_html__( 'Lo que necesitas vender', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $necesitas ) ) );
			echo '</div>';
		}
		echo '</div>';

		echo '<div class="esc-goal__fin">';
		$n_ctas = count( Malka_CRM_Finance::cuentas() );
		if ( $n_ctas > 1 ) {
			// Con varias cuentas el total no se edita acá: cada saldo vive en su cuenta.
			echo '<span class="esc-lbl">' . esc_html__( 'Saldo en tus cuentas', 'malka-crm' ) . '</span>';
			echo '<div class="esc-saldo"><span class="esc-saldo__i esc-saldo__t">' . esc_html( Malka_CRM_Settings::money( $saldo ) ) . '</span></div>';
			/*
			 * Dice cuántas cuentas suman ese total y nada más. Sigue llevando
			 * a la lista —es la única puerta desde Inicio— pero sin anunciarlo
			 * con una flecha: el número de cuentas es el dato, no el viaje.
			 */
			printf(
				'<a class="esc-goal__f esc-goal__cta" href="%1$s">%2$s</a>',
				esc_url( self::view_url( 'movimientos' ) . '#esc-cuentas' ),
				esc_html( sprintf( /* translators: cantidad */ _n( 'En %d cuenta', 'En %d cuentas', $n_ctas, 'malka-crm' ), $n_ctas ) )
			);
		} else {
			echo '<span class="esc-lbl">' . esc_html__( 'Saldo en la cuenta', 'malka-crm' ) . '</span>';
			echo '<div class="esc-saldo"><span class="esc-saldo__s" aria-hidden="true">$</span>' .
				'<input id="esc-saldo" class="esc-saldo__i" type="text" inputmode="numeric" value="' .
				esc_attr( number_format( $saldo, 0, ',', '.' ) ) . '" aria-label="' . esc_attr__( 'Saldo actual', 'malka-crm' ) . '" /></div>';
			echo '<span class="esc-goal__f" id="esc-saldo-msg">' .
				esc_html__( 'Edítalo cuando quieras · los pagos de cotizaciones entran solos', 'malka-crm' ) . '</span>';
		}

		/*
		 * La puerta a «Cobros en tu saldo» queda siempre, no sólo cuando hay
		 * algo que preguntar: es por donde se revisa —y se deshace— lo que
		 * el panel sumó solo.
		 */
		if ( class_exists( 'Malka_CRM_Quotes' ) && Malka_CRM_Quotes::cobros_todos() ) {
			printf(
				'<a class="esc-goal__f esc-goal__cta" href="%1$s">%2$s →</a>',
				esc_url( self::view_url( 'cobros' ) ),
				esc_html__( 'Ver qué cobros están sumados', 'malka-crm' )
			);
		}

		echo self::aviso_cobros_fuera(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '</section>';
		return ob_get_clean();
	}

	/**
	 * Cuotas marcadas como pagadas que no están sumadas en el saldo.
	 *
	 * Pasa con todo lo que ya estaba pagado antes de que los cobros
	 * empezaran a entrar solos. Se pregunta en vez de sumarlo de una,
	 * porque el saldo que ella escribió a mano puede incluirlo ya.
	 *
	 * @return string
	 */
	public static function aviso_cobros_fuera() {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n     = isset( $_GET['cobros'] ) ? (int) $_GET['cobros'] : 0;
		$fuera = get_option( Malka_CRM_Quotes::OPT_VISTO ) ? array() : Malka_CRM_Quotes::cobros_pendientes();

		if ( ! $fuera ) {
			// Recién respondida la pregunta: se acusa recibo y se calla.
			if ( $n > 0 ) {
				return '<p class="esc-cobros esc-cobros--ok">' . esc_html(
					sprintf( /* translators: cantidad */ _n( 'Listo: %d cobro entró a la cuenta.', 'Listo: %d cobros entraron a la cuenta.', $n, 'malka-crm' ), $n )
				) . '</p>';
			}
			return $n < 0
				? '<p class="esc-cobros esc-cobros--ok">' . esc_html__( 'Listo, no los toco.', 'malka-crm' ) . '</p>'
				: '';
		}

		$suma = 0;
		foreach ( $fuera as $c ) {
			$suma += (int) $c['monto'];
		}

		ob_start();
		echo '<div class="esc-cobros">';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: cantidad, 2: monto */
					_n(
						'%1$d pago marcado como completado no está sumado acá (%2$s).',
						'%1$d pagos marcados como completados no están sumados acá (%2$s).',
						count( $fuera ),
						'malka-crm'
					),
					count( $fuera ),
					Malka_CRM_Settings::money( $suma )
				)
			)
		);

		echo '<ul>';
		foreach ( array_slice( $fuera, 0, 4 ) as $c ) {
			printf(
				'<li><span>%1$s</span><b>%2$s</b></li>',
				esc_html( $c['titulo'] . ( ! empty( $c['cliente'] ) ? ' · ' . $c['cliente'] : '' ) ),
				esc_html( Malka_CRM_Settings::money( $c['monto'] ) )
			);
		}
		if ( count( $fuera ) > 4 ) {
			printf( '<li><span>%s</span></li>', esc_html( sprintf( /* translators: cantidad */ __( 'y %d más', 'malka-crm' ), count( $fuera ) - 4 ) ) );
		}
		echo '</ul>';

		/*
		 * «Elegir cuáles» y no «sumarlos todos»: sumar dieciocho de una, sin
		 * poder mirarlos ni volver atrás, deja el saldo en un número que no
		 * se puede explicar. La pantalla de al lado los muestra uno por uno
		 * y se puede desmarcar.
		 */
		printf(
			'<a class="esc-btn esc-btn--primary" href="%1$s">%2$s</a>',
			esc_url( self::view_url( 'cobros' ) ),
			esc_html__( 'Elegir cuáles', 'malka-crm' )
		);
		printf(
			'<form method="post" action="%1$s">%2$s<input type="hidden" name="action" value="malka_crm_cobros_al_dia" />' .
			'<button type="submit" name="ya" value="1" class="esc-btn">%3$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_cobros_al_dia', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Ninguno, ya están', 'malka-crm' )
		);
		echo '</div>';

		return ob_get_clean();
	}

	/* ─────────────────────────────  Mosaico  ──────────────────────────── */

	/**
	 * El mosaico de paneles.
	 *
	 * @return string
	 */
	public static function render_mosaic() {
		/*
		 * El reparto del Inicio —qué se ve, en qué orden y de qué ancho— es
		 * suyo, así que lo arma el módulo que lo guarda. Acá quedan los
		 * paneles, que es lo que este archivo sabe dibujar.
		 */
		return class_exists( 'Malka_CRM_Home' ) ? Malka_CRM_Home::render() : '';
	}

	public static function head( $icono, $titulo, $sub = '', $valor = '', $valor_sub = '', $nota = '', $extra = '' ) {
		/*
		 * La nota: lo que hay que decir una vez y después estorba —la
		 * advertencia de que acá sólo se hace aritmética, el supuesto detrás
		 * de un número—. Vive detrás de la ⓘ del encabezado en vez de ocupar
		 * tres líneas cada vez que se entra a la pantalla.
		 */
		/*
		 * El id sale del contenido, no de un contador: así la misma pantalla
		 * pedida entera o por AJAX da exactamente el mismo html, que es lo
		 * que permite cambiar un pedazo sin recargar.
		 */
		$id = '' === $nota ? '' : 'esc-nota-' . substr( md5( $titulo . $nota ), 0, 8 );

		$out  = '<div class="esc-ph">' . self::icon( $icono );
		$out .= '<div><h2>' . esc_html( $titulo );

		if ( $id ) {
			$out .= sprintf(
				'<button type="button" class="esc-ph__i" data-nota-btn aria-controls="%1$s" aria-expanded="false" aria-label="%2$s" title="%2$s">' .
				'<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true">' .
				'<circle cx="8" cy="8" r="6.3"/><path d="M8 7.2v4" stroke-linecap="round"/><path d="M8 4.8v.6" stroke-linecap="round"/></svg></button>',
				esc_attr( $id ),
				esc_attr__( 'Qué hay que saber de esto', 'malka-crm' )
			);
		}

		$out .= '</h2>';

		if ( $sub ) {
			$out .= '<p>' . esc_html( $sub ) . '</p>';
		}
		$out .= '</div>';
		if ( '' !== $valor ) {
			$out .= '<div class="esc-ph__r"><em>' . esc_html( $valor ) . '</em>';
			if ( $valor_sub ) {
				$out .= '<span>' . esc_html( $valor_sub ) . '</span>';
			}
			$out .= '</div>';
		}

		// Un botón propio del panel —el + de agregar— al final de la fila.
		$out .= $extra;

		$out .= '</div>';

		if ( $id ) {
			$out .= sprintf( '<div class="esc-nota" id="%1$s" data-nota hidden>%2$s</div>', esc_attr( $id ), $nota );
		}

		return $out;
	}

	public static function panel_analysis() {
		// Lo vencido ya tiene su cuadro («Por cobrar»): acá no se repite.
		$obs = array_values(
			array_filter(
				Malka_CRM_Analysis::observations(),
				function ( $o ) {
					return ! isset( $o['clave'] ) || 'cobro_vencido' !== $o['clave'];
				}
			)
		);

		ob_start();
		echo '<section class="esc-panel a-ana">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'bombilla',
			__( 'Qué veo en tus números', 'malka-crm' ),
			__( 'Análisis de tus ventas, compras y clientes, con consejos para crecer', 'malka-crm' ),
			(string) count( $obs ),
			__( 'observaciones', 'malka-crm' )
		);

		if ( empty( $obs ) ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no hay datos suficientes. Importa tus facturas del SII y aparecerán el análisis y los consejos.', 'malka-crm' ) . '</p>';
		} else {
			$una = function ( $o ) {
				return sprintf(
					'<div class="esc-in" data-href="%4$s"><i class="dot dot--%1$s"></i><div><b>%2$s</b><p>%3$s</p><a class="esc-obs__a" href="%4$s">%5$s →</a></div></div>',
					esc_attr( $o['nivel'] ),
					esc_html( $o['titulo'] ),
					esc_html( $o['texto'] ),
					esc_url( $o['url'] ),
					esc_html( $o['accion'] )
				);
			};
			echo '<div class="esc-ins">';
			foreach ( array_slice( $obs, 0, 5 ) as $o ) {
				echo $una( $o ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			$resto = array_slice( $obs, 5 );
			if ( $resto ) {
				printf( '<details class="esc-ins__mas"><summary>%s</summary>', esc_html( sprintf( /* translators: cantidad */ _n( 'Ver %d análisis más', 'Ver %d análisis más', count( $resto ), 'malka-crm' ), count( $resto ) ) ) );
				foreach ( $resto as $o ) {
					echo $una( $o ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</details>';
			}
			echo '</div>';
		}

		echo '</section>';
		return ob_get_clean();
	}

	/**
	 * Cuánta plata hay, cuenta por cuenta.
	 *
	 * Lo del estudio y lo tuyo van separados y no se suman en un número
	 * solo: juntarlos daría una cifra que no es de nadie. Las tarjetas de
	 * crédito no son saldo —son deuda— y se muestran aparte.
	 *
	 * @return string
	 */
	public static function panel_saldo() {
		$cuentas = class_exists( 'Malka_CRM_Finance' ) ? Malka_CRM_Finance::cuentas() : array();
		$estudio = array();
		$tuyas   = array();

		foreach ( $cuentas as $id => $x ) {
			// Una tarjeta de crédito no es plata que tienes.
			if ( isset( $x['tipo'] ) && 'credito' === $x['tipo'] ) {
				continue;
			}

			$fila = array(
				'nombre' => Malka_CRM_Finance::nombre_cuenta( (int) $id ),
				'saldo'  => (int) $x['saldo'],
				'fecha'  => isset( $x['fecha'] ) ? (string) $x['fecha'] : '',
			);

			if ( empty( $x['personal'] ) ) {
				$estudio[] = $fila;
			} else {
				$tuyas[] = $fila;
			}
		}

		$t_est = Malka_CRM_Finance::balance();
		$t_mio = Malka_CRM_Finance::balance_personal();

		ob_start();
		/*
		 * El id no es «esc-saldo»: ese ya es el campo donde se escribe el
		 * saldo, en la meta. Dos elementos con el mismo id rompen el salto
		 * por ancla y confunden a un lector de pantalla.
		 */
		echo '<section class="esc-panel esc-sal" id="esc-lohay">';

		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'peso',
			__( 'Lo que hay', 'malka-crm' ),
			__( 'El saldo de cada cuenta, el del estudio y el tuyo', 'malka-crm' ),
			Malka_CRM_Settings::money( $t_est ),
			__( 'del estudio', 'malka-crm' ),
			sprintf(
				'<p>%s</p>',
				esc_html__( 'Estos saldos son los que anotaste tú: el plugin no se conecta al banco. Se actualizan al cargar una cartola o al marcar un movimiento, y quedan con la fecha de la última vez.', 'malka-crm' )
			)
		);

		if ( ! $estudio && ! $tuyas ) {
			printf(
				'<p class="esc-empty">%s</p>',
				esc_html__( 'Todavía no hay cuentas cargadas. En Finanzas → Cuentas puedes anotarlas.', 'malka-crm' )
			);
			echo '</section>';
			return ob_get_clean();
		}

		foreach ( array(
			array( __( 'Del estudio', 'malka-crm' ), $estudio, $t_est ),
			array( __( 'Tuyas', 'malka-crm' ), $tuyas, $t_mio ),
		) as $grupo ) {
			list( $rot, $filas, $total ) = $grupo;

			if ( ! $filas ) {
				continue;
			}

			printf( '<div class="esc-sal__g"><b>%1$s</b><span>%2$s</span></div><ul class="esc-sal__l">', esc_html( $rot ), esc_html( Malka_CRM_Settings::money( $total ) ) );

			foreach ( $filas as $f ) {
				printf(
					'<li><span>%1$s%2$s</span><b>%3$s</b></li>',
					esc_html( $f['nombre'] ),
					$f['fecha'] ? '<em>' . esc_html( sprintf( /* translators: fecha */ __( 'al %s', 'malka-crm' ), date_i18n( 'j M', strtotime( $f['fecha'] ) ) ) ) . '</em>' : '',
					esc_html( Malka_CRM_Settings::money( $f['saldo'] ) )
				);
			}

			echo '</ul>';
		}

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * La meta del mes, como bloque del Inicio.
	 *
	 * @return string
	 */
	public static function panel_meta() {
		return self::render_goal();
	}

	public static function panel_day() {
		$falta = Malka_CRM_Onboarding::missing_for( 'dia' );
		$plan  = Malka_CRM_Analysis::day_plan();

		ob_start();
		echo '<section class="esc-panel a-dia">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'calendario',
			__( 'Tu día', 'malka-crm' ),
			__( 'Primero lo que destraba plata, después lo que vence', 'malka-crm' ),
			$falta ? '' : Malka_CRM_Time::format_minutes( $plan['minutos'] ),
			$falta ? '' : __( 'planificadas', 'malka-crm' )
		);

		if ( $falta ) {
			echo '<p class="esc-empty">' . esc_html( $falta ) . '</p>';
			echo '</section>';
			return ob_get_clean();
		}

		/*
		 * Las cobranzas se juntan en una sola fila: cada una ya está con su
		 * botón «Avisar» en «Por cobrar», y aquí repetirlas una por una
		 * llenaba la mitad del día con lo mismo. Se ven los primeros cinco
		 * pendientes; el resto, a un toque.
		 */
		$filas   = array();
		$cobros  = null;
		$visible = 5;

		/*
		 * Las cuotas de lo que prestaste. Sin esto había que acordarse solo,
		 * que es justo lo que no pasa.
		 *
		 * Sólo lo que te deben: lo que tú debes —costos, tarjetas y lo que
		 * le debes a alguien— vive entero en «Lo que tienes que pagar», y
		 * repetirlo acá era ver dos veces la misma deuda en la misma
		 * pantalla.
		 */
		$personas = array();
		if ( class_exists( 'Malka_CRM_Prestamos' ) ) {
			foreach ( Malka_CRM_Prestamos::proximos( 7 ) as $pp ) {
				if ( 'debo' !== $pp['lado'] ) {
					$personas[] = $pp;
				}
			}
		}
		if ( $personas ) {
			printf( '<span class="esc-dia__h">%s</span>', esc_html__( 'Por cobrarle a alguien', 'malka-crm' ) );
			foreach ( $personas as $d ) {
				list( $cuando, $tono ) = Malka_CRM_Pagos::cuando( (int) $d['dias'] );
				$monto = $d['cuota'] > 0 ? min( $d['cuota'], $d['pendiente'] ) : $d['pendiente'];
				$resto = array();
				if ( $d['cuotas'] > 0 ) {
					$resto[] = sprintf(
						/* translators: 1: cuál, 2: de cuántas */
						__( 'cuota %1$d de %2$d', 'malka-crm' ),
						min( $d['cuotas'], $d['pagadas'] + 1 ),
						$d['cuotas']
					);
				}
				$resto[] = sprintf(
					/* translators: monto */
					__( 'quedan %s por saldar', 'malka-crm' ),
					Malka_CRM_Settings::money( $d['pendiente'] )
				);
				printf(
					'<div class="esc-dia-w esc-dia-w--pago"><span class="esc-dia__sin" aria-hidden="true"></span>' .
					'<a class="esc-dia" href="%1$s"><span class="esc-dia__t">%2$s<i>%3$s</i></span><span>' .
					'<span class="esc-dia__n"><span class="esc-dia__pago" aria-hidden="true">$</span>%4$s <span class="esc-st esc-st--%5$s">%6$s</span></span>' .
					'<span class="esc-dia__r">%7$s</span></span></a></div>',
					esc_url( self::view_url( 'deudas' ) ),
					esc_html( date_i18n( 'j M', strtotime( $d['proxima'] ) ) ),
					esc_html( 'debo' === $d['lado'] ? __( 'pagar', 'malka-crm' ) : __( 'cobrar', 'malka-crm' ) ),
					esc_html(
						'debo' === $d['lado']
							? sprintf( /* translators: 1: monto, 2: quién */ __( 'Pagarle %1$s a %2$s', 'malka-crm' ), Malka_CRM_Settings::money( $monto ), $d['quien'] )
							: sprintf( /* translators: 1: monto, 2: quién */ __( 'Cobrarle %1$s a %2$s', 'malka-crm' ), Malka_CRM_Settings::money( $monto ), $d['quien'] )
					),
					esc_attr( $tono ),
					esc_html( $cuando ),
					esc_html( implode( ' · ', $resto ) )
				);
			}
		}

		foreach ( $plan['bloques'] as $b ) {
			if ( empty( $b['items'] ) ) {
				continue;
			}
			$filas[] = array(
				'h'    => true,
				'html' => sprintf(
					'<span class="esc-dia__h">%s · %s %s %s</span>',
					esc_html( $b['label'] ),
					esc_html( $b['desde'] ),
					esc_html__( 'a', 'malka-crm' ),
					esc_html( $b['hasta'] )
				),
			);

			foreach ( $b['items'] as $it ) {
				if ( isset( $it['tipo'] ) && 'cobro' === $it['tipo'] ) {
					if ( null === $cobros ) {
						$cobros  = count( $filas );
						$filas[] = array(
							'h'     => false,
							'cobro' => true,
							'hora'  => $it['hora'],
							'min'   => 0,
							'n'     => 0,
							'monto' => 0,
						);
					}
					$filas[ $cobros ]['min']   += (int) $it['minutos'];
					$filas[ $cobros ]['n']     += 1;
					$filas[ $cobros ]['monto'] += (int) $it['monto'];
					continue;
				}

				// Sin casilla, un hueco del mismo ancho: si no, la fila se
				// corría a la columna angosta y el texto quedaba en tira.
				$check = '<span class="esc-dia__sin" aria-hidden="true"></span>';
				if ( isset( $it['tipo'] ) && 'tarea' === $it['tipo'] && ! empty( $it['pid'] ) && '' !== $it['tid'] ) {
					$check = sprintf(
						'<button type="button" class="esc-dia__chk" data-pid="%1$d" data-tid="%2$s" aria-label="%3$s" title="%3$s"></button>',
						(int) $it['pid'],
						esc_attr( $it['tid'] ),
						esc_attr__( 'Marcar como lista', 'malka-crm' )
					);
				}

				$filas[] = array(
					'h'    => false,
					'html' => sprintf(
						'<div class="esc-dia-w">%7$s<a class="esc-dia" href="%1$s"><span class="esc-dia__t">%2$s<i>%3$s</i></span><span>' .
						'<span class="esc-dia__n">%4$s%5$s</span><span class="esc-dia__r">%6$s</span></span></a></div>',
						esc_url( $it['url'] ),
						esc_html( $it['hora'] ),
						esc_html( Malka_CRM_Time::format_minutes( $it['minutos'] ) ),
						esc_html( $it['titulo'] ),
						$it['etiqueta'] ? ' <span class="esc-st esc-st--' . esc_attr( 'alta' === $it['nivel'] ? 'late' : 'wait' ) . '">' . esc_html( $it['etiqueta'] ) . '</span>' : '',
						esc_html( $it['motivo'] ),
						$check
					),
				);
			}
		}

		// La fila de cobranza, ya con el total.
		if ( null !== $cobros ) {
			$c = $filas[ $cobros ];
			/*
			 * También se puede dar por hecha: «hoy ya cobré». Queda marcada
			 * sólo por hoy; si mañana sigue habiendo vencidos, vuelve.
			 */
			$hecho = ( get_option( 'malka_crm_cobros_hechos' ) === current_time( 'Y-m-d' ) );
			$filas[ $cobros ]['html'] = sprintf(
				'<div class="esc-dia-w%6$s"><button type="button" class="esc-dia__chk%7$s" data-tipo="cobro" aria-label="%8$s" title="%8$s"%9$s></button><a class="esc-dia" href="#esc-por-cobrar"><span class="esc-dia__t">%1$s<i>%2$s</i></span><span>' .
				'<span class="esc-dia__n"><span class="esc-dia__pago" aria-hidden="true">$</span>%3$s <span class="esc-st esc-st--late">%4$s</span></span><span class="esc-dia__r">%5$s</span></span></a></div>',
				esc_html( $c['hora'] ),
				esc_html( Malka_CRM_Time::format_minutes( $c['min'] ) ),
				esc_html( sprintf( /* translators: cantidad */ _n( 'Cobrar %d pago vencido', 'Cobrar %d pagos vencidos', $c['n'], 'malka-crm' ), $c['n'] ) ),
				esc_html__( 'Primero', 'malka-crm' ),
				esc_html( sprintf( /* translators: monto */ __( '%s que no han entrado. Avisa a cada cliente desde «Por cobrar».', 'malka-crm' ), Malka_CRM_Settings::money( $c['monto'] ) ) ),
				$hecho ? ' is-lista' : '',
				$hecho ? ' is-ok' : '',
				esc_attr__( 'Marcar la cobranza de hoy como hecha', 'malka-crm' ),
				$hecho ? ' disabled' : ''
			);
		}

		$hay      = false;
		$contadas = 0;
		$abierto  = false;
		$resto    = 0;

		foreach ( $filas as $f ) {
			if ( ! $f['h'] ) {
				$resto++;
			}
		}
		$resto = max( 0, $resto - $visible );

		foreach ( $filas as $f ) {
			if ( ! $f['h'] ) {
				$hay = true;
				$contadas++;
				if ( $contadas === $visible + 1 && $resto > 0 ) {
					printf(
						'<details class="esc-dia__mas"><summary>%s</summary>',
						esc_html( sprintf( /* translators: cantidad */ _n( 'Ver el resto del día (%d)', 'Ver el resto del día (%d)', $resto, 'malka-crm' ), $resto ) )
					);
					$abierto = true;
				}
			}
			echo $f['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $abierto ) {
			echo '</details>';
		}

		if ( ! $hay && ! $pagos ) {
			echo '<p class="esc-empty">' . esc_html__( 'No hay nada con fecha próxima. Día despejado.', 'malka-crm' ) . '</p>';
		}

		if ( ! empty( $plan['fuera'] ) ) {
			printf(
				'<p class="esc-dia__out">%s</p>',
				esc_html(
					sprintf(
						/* translators: cantidad */
						_n( 'Quedó %d tarea fuera del plan por falta de espacio en el día.', 'Quedaron %d tareas fuera del plan por falta de espacio en el día.', count( $plan['fuera'] ), 'malka-crm' ),
						count( $plan['fuera'] )
					)
				)
			);
		}

		echo '</section>';
		return ob_get_clean();
	}

	public static function panel_deals() {
		$falta     = Malka_CRM_Onboarding::missing_for( 'negocios' );
		$proyectos = Malka_CRM_Analysis::active_projects();
		$total     = 0;

		foreach ( $proyectos as $pid ) {
			$total += Malka_CRM_Analysis::project_net( $pid );
		}

		ob_start();
		echo '<section class="esc-panel a-deal">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'maletin',
			__( 'Negocios en curso', 'malka-crm' ),
			$total > 0 ? sprintf( /* translators: monto */ __( '%s contratados', 'malka-crm' ), Malka_CRM_Settings::money( $total ) ) : '',
			(string) count( $proyectos ),
			__( 'activos', 'malka-crm' )
		);

		if ( $falta ) {
			echo '<p class="esc-empty">' . esc_html( $falta ) . '</p>';
			echo '</section>';
			return ob_get_clean();
		}

		if ( empty( $proyectos ) ) {
			echo '<p class="esc-empty">' . esc_html__( 'No tienes negocios activos ahora mismo.', 'malka-crm' ) . '</p>';
		}

		foreach ( $proyectos as $pid ) {
			$avance = (int) Malka_CRM_Helpers::get_project_progress( $pid );
			$stats  = Malka_CRM_Helpers::get_project_stats( $pid );
			$etapa  = Malka_CRM_Helpers::get_current_stage( $pid );
			$saldo  = self::project_balance( $pid );
			$estado = self::project_state( $pid, $saldo['vencido'] );

			printf(
				'<a class="esc-deal" href="%1$s">' .
				'<span class="esc-deal__top"><span class="esc-deal__n">%2$s</span><span class="esc-deal__p">%3$d%%</span></span>' .
				'<span class="esc-deal__s">%4$s</span>' .
				'<span class="esc-bar"><i style="width:%3$d%%%5$s"></i></span>' .
				'<span class="esc-deal__f"><span><b%6$s>%7$s</b> %8$s</span><span class="esc-st esc-st--%9$s">%10$s</span></span></a>',
				esc_url( Malka_CRM_Projects::edit_url( $pid ) ),
				esc_html( get_the_title( $pid ) ),
				$avance,
				esc_html( self::deal_subtitle( $etapa, $stats ) ),
				$saldo['vencido'] > 0 ? ';background:var(--esc-alert)' : '',
				$saldo['vencido'] > 0 ? ' class="x"' : '',
				esc_html( Malka_CRM_Settings::money( $saldo['pendiente'] ) ),
				esc_html( $saldo['vencido'] > 0 ? __( 'vencido', 'malka-crm' ) : __( 'por cobrar', 'malka-crm' ) ),
				esc_attr( $estado['clase'] ),
				esc_html( $estado['label'] )
			);
		}

		echo '</section>';
		return ob_get_clean();
	}

	private static function deal_subtitle( $etapa, $stats ) {
		$partes = array();

		if ( $etapa && ! empty( $etapa['name'] ) ) {
			$partes[] = $etapa['name'];
		}
		if ( ! empty( $stats['total_days'] ) ) {
			/* translators: 1: día actual, 2: días totales */
			$partes[] = sprintf( __( 'día %1$d de %2$d', 'malka-crm' ), (int) $stats['elapsed_days'], (int) $stats['total_days'] );
		}

		return implode( ' · ', $partes );
	}

	/**
	 * Saldo pendiente y vencido de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return array
	 */
	public static function project_balance( $project_id ) {
		$out = array(
			'pendiente' => 0,
			'vencido'   => 0,
		);

		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return $out;
		}

		$quote_id = Malka_CRM_Quotes::get_project_quote_id( $project_id );
		if ( ! $quote_id ) {
			return $out;
		}

		foreach ( Malka_CRM_Quotes::get_payments( $quote_id ) as $p ) {
			if ( Malka_CRM_Quotes::es_resumen( $quote_id, $p ) ) {
				continue;
			}
			$estado = Malka_CRM_Quote_Page::payment_view( $quote_id, $p );
			if ( 'paid' === $estado['state'] ) {
				continue;
			}
			$out['pendiente'] += (int) $p['amount'];
			if ( 'overdue' === $estado['state'] ) {
				$out['vencido'] += (int) $p['amount'];
			}
		}

		return $out;
	}

	/**
	 * Estado visual de un negocio.
	 *
	 * @param int $project_id Proyecto.
	 * @param int $vencido    Monto vencido.
	 * @return array
	 */
	public static function project_state( $project_id, $vencido = 0 ) {
		if ( $vencido > 0 ) {
			return array(
				'clase' => 'late',
				'label' => __( 'Atrasado', 'malka-crm' ),
			);
		}

		$stats = Malka_CRM_Helpers::get_project_stats( $project_id );
		if ( ! empty( $stats['delay_client'] ) ) {
			return array(
				'clase' => 'wait',
				'label' => __( 'Esperando', 'malka-crm' ),
			);
		}

		return array(
			'clase' => 'run',
			'label' => __( 'En curso', 'malka-crm' ),
		);
	}

	public static function panel_payments() {
		$falta    = Malka_CRM_Onboarding::missing_for( 'cobranza' );
		$filas    = self::upcoming_payments();
		$vencidas = array();
		$pronto   = array();
		$despues  = array();
		$total_v  = 0;

		/*
		 * Tres grupos, por lo que hay que hacer con cada uno: lo vencido se
		 * cobra hoy (con «Avisar» a mano), lo que vence pronto se vigila, y
		 * lo que espera la entrega o vence más adelante no pide nada todavía,
		 * así que va plegado.
		 */
		foreach ( $filas as $f ) {
			if ( $f['vencido'] ) {
				$vencidas[] = $f;
				$total_v   += $f['monto'];
			} elseif ( 'wait' === $f['clase'] ) {
				$pronto[] = $f;
			} else {
				$despues[] = $f;
			}
		}

		ob_start();
		echo '<section class="esc-panel a-pag" id="esc-por-cobrar">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'peso',
			__( 'Por cobrar', 'malka-crm' ),
			$vencidas
				? sprintf( /* translators: cantidad */ _n( '%d pago vencido', '%d pagos vencidos', count( $vencidas ), 'malka-crm' ), count( $vencidas ) )
				: __( 'Nada vencido', 'malka-crm' ),
			$vencidas ? Malka_CRM_Settings::money( $total_v ) : '',
			$vencidas ? __( 'vencido', 'malka-crm' ) : ''
		);

		if ( $falta ) {
			echo '<p class="esc-empty">' . esc_html( $falta ) . '</p>';
			echo '</section>';
			return ob_get_clean();
		}

		if ( empty( $filas ) ) {
			echo '<p class="esc-empty">' . esc_html__( 'Nada por cobrar. Todas las cuotas están pagadas o sin fecha.', 'malka-crm' ) . '</p>';
			echo '</section>';
			return ob_get_clean();
		}

		$fila = function ( $f, $con_aviso ) {
			$aviso = '';
			if ( $con_aviso && ! empty( $f['qid'] ) ) {
				$motivo = class_exists( 'Malka_CRM_Reminders' ) ? Malka_CRM_Reminders::impedimento( $f['qid'], $f['cuota'] ) : '';
				if ( ! $motivo ) {
					$aviso = sprintf(
						'<a class="esc-cob__av" href="%1$s">%2$s <span aria-hidden="true">→</span></a>',
						esc_url(
							wp_nonce_url(
								add_query_arg(
									array(
										'action' => 'malka_crm_avisar',
										'quote'  => $f['qid'],
										'cuota'  => rawurlencode( $f['cuota'] ),
										'volver' => rawurlencode( self::view_url( 'inicio' ) ),
									),
									admin_url( 'admin-post.php' )
								),
								'malka_crm_avisar_' . $f['qid'] . '_' . $f['cuota']
							)
						),
						esc_html__( 'Avisar', 'malka-crm' )
					);
				}
			}

			/*
			 * Dos líneas iguales para todas: cliente y monto arriba,
			 * cotización y cuándo abajo. Lo vencido suma una tercera línea
			 * con el atraso a la izquierda y «Avisar» a la derecha, a la
			 * misma altura, para que la acción quede junto al motivo.
			 */
			$pie = '';
			if ( $f['vencido'] ) {
				$pie = sprintf(
					'<div class="esc-cob__acc"><span class="esc-cob__atr"><span class="esc-st esc-st--late">%1$s</span>%2$s</span>%3$s</div>',
					esc_html( $f['cuando'] ),
					$f['mora'] ? '<span class="esc-cob__mora">' . esc_html( $f['mora'] ) . '</span>' : '',
					$aviso
				);
			}

			return sprintf(
				'<div class="esc-cob%1$s"><a class="esc-cob__main" href="%2$s">' .
				'<span class="esc-cob__l1"><b>%3$s</b><b class="esc-cob__monto">%4$s</b></span>' .
				'<span class="esc-cob__l2"><span>%5$s</span><span>%6$s</span></span></a>%7$s</div>',
				$f['vencido'] ? ' is-late' : '',
				esc_url( $f['url'] ),
				esc_html( $f['cliente'] ? $f['cliente'] : $f['numero'] ),
				esc_html( Malka_CRM_Settings::money( $f['monto'] ) ),
				esc_html( $f['numero'] . ' · ' . $f['concepto'] ),
				esc_html( $f['vencido'] ? '' : $f['cuando'] ),
				$pie
			);
		};

		if ( $vencidas ) {
			echo '<p class="esc-cob__h">' . esc_html__( 'Vencidos', 'malka-crm' ) . '</p>';
			foreach ( $vencidas as $f ) {
				echo $fila( $f, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		if ( $pronto ) {
			echo '<p class="esc-cob__h">' . esc_html__( 'Vencen pronto', 'malka-crm' ) . '</p>';
			foreach ( $pronto as $f ) {
				echo $fila( $f, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		/*
		 * Lo que no apura también se ve: plegado entero, un cuadro sin nada
		 * urgente quedaba vacío. Se muestran los primeros cuatro y el resto,
		 * si hay más, a un toque.
		 */
		if ( $despues ) {
			echo '<p class="esc-cob__h">' . esc_html__( 'Más adelante', 'malka-crm' ) . '</p>';
			foreach ( array_slice( $despues, 0, 4 ) as $f ) {
				echo $fila( $f, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			$extra = array_slice( $despues, 4 );
			if ( $extra ) {
				printf(
					'<details class="esc-cob__mas"><summary>%s</summary>',
					esc_html( sprintf( /* translators: cantidad */ _n( 'Ver %d más', 'Ver %d más', count( $extra ), 'malka-crm' ), count( $extra ) ) )
				);
				foreach ( $extra as $f ) {
					echo $fila( $f, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</details>';
			}
		}

		/*
		 * El total, anclado abajo. Cuando el cuadro queda más alto que su
		 * contenido (va al lado de «Tu día»), esto cierra la tarjeta en vez
		 * de dejar la información flotando sobre un blanco.
		 */
		/*
		 * Lo mismo que Finanzas › Por cobrar: las cuotas con fecha. Los
		 * saldos que esperan la entrega van aparte, en una línea chica,
		 * para que los dos totales del panel digan el mismo número.
		 */
		$todo   = 0;
		$n_todo = 0;
		$espera = 0;
		foreach ( $filas as $f ) {
			if ( ! empty( $f['espera'] ) ) {
				$espera += $f['monto'];
			} else {
				$todo += $f['monto'];
				$n_todo++;
			}
		}
		printf(
			'<a class="esc-cob__pie" href="%1$s"><span>%2$s<b>%3$s</b>%5$s</span><span class="esc-cob__ir">%4$s →</span></a>',
			esc_url( self::view_url( 'cobranza' ) ),
			esc_html( sprintf( /* translators: cantidad */ _n( 'Por cobrar con fecha · %d cuota', 'Por cobrar con fecha · %d cuotas', $n_todo, 'malka-crm' ), $n_todo ) ),
			esc_html( Malka_CRM_Settings::money( $todo ) ),
			esc_html__( 'Ver cobranza', 'malka-crm' ),
			$espera ? '<small class="esc-cob__esp">' . esc_html( sprintf( /* translators: monto */ __( '+ %s al entregar', 'malka-crm' ), Malka_CRM_Settings::money( $espera ) ) ) . '</small>' : ''
		);

		echo '</section>';
		return ob_get_clean();
	}

	/**
	 * Cuotas por cobrar de todas las cotizaciones, ordenadas por vencimiento.
	 *
	 * @return array[]
	 */
	public static function upcoming_payments() {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return array();
		}

		$quotes = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);

		$out = array();

		foreach ( $quotes as $qid ) {
			$numero = get_post_meta( $qid, '_malka_pm_quote_number', true );
			$numero = $numero ? 'N° ' . $numero : '#' . $qid;

			foreach ( Malka_CRM_Quotes::get_payments( $qid ) as $p ) {
				if ( Malka_CRM_Quotes::es_resumen( $qid, $p ) ) {
					continue;
				}
				$estado = Malka_CRM_Quote_Page::payment_view( $qid, $p );

				// Pagada o dada por perdida: no está por cobrar.
				if ( 'paid' === $estado['state'] || ! empty( $estado['perdida'] ) ) {
					continue;
				}

				$vencido = ( 'overdue' === $estado['state'] );
				$mora    = $vencido ? self::late_fee( (int) $p['amount'], abs( (int) $estado['days'] ) ) : 0;

				$out[] = array(
					'qid'      => (int) $qid,
					'cuota'    => isset( $p['id'] ) ? (string) $p['id'] : '',
					'cliente'  => (string) get_post_meta( $qid, '_malka_pm_client_name', true ),
					'concepto' => isset( $p['label'] ) ? (string) $p['label'] : '',
					'numero'  => $numero,
					'monto'   => (int) $p['amount'] + $mora,
					'mora'    => $mora > 0 ? '+' . Malka_CRM_Settings::money( $mora ) . ' ' . __( 'mora', 'malka-crm' ) : '',
					'cuando'  => $estado['label'],
					'vencido' => $vencido,
					'espera'  => ! empty( $estado['espera'] ),
					'clase'   => $vencido ? 'late' : ( 'due_soon' === $estado['state'] ? 'wait' : 'run' ),
					'estado'  => $vencido ? __( 'Vencida', 'malka-crm' ) : ( 'due_soon' === $estado['state'] ? __( 'Por vencer', 'malka-crm' ) : __( 'Al día', 'malka-crm' ) ),
					'dias'    => (int) $estado['days'],
					'url'     => Malka_CRM_Quote_Editor::edit_url( $qid ),
				);
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $a['dias'] <=> $b['dias'];
			}
		);

		return $out;
	}

	/**
	 * Interés por mora más la comisión de recuperación, con el tope del duplo.
	 *
	 * El artículo 1544 del Código Civil permite rebajar todo lo que exceda al
	 * duplo de la obligación principal, así que el sistema nunca lo supera.
	 *
	 * @param int $monto Monto de la cuota.
	 * @param int $dias  Días de atraso.
	 * @return int
	 */
	public static function late_fee( $monto, $dias ) {
		if ( $monto <= 0 || $dias <= 0 ) {
			return 0;
		}

		$tasa      = (float) Malka_CRM_Settings::get( 'mora_tasa', 0 );
		$comision  = (float) Malka_CRM_Settings::get( 'mora_comision', 0 );
		$iva       = (float) Malka_CRM_Settings::get( 'iva', 19 );
		$base_neto = 'neto' === Malka_CRM_Settings::get( 'mora_base', 'neto' ) && $iva > 0
			? $monto / ( 1 + ( $iva / 100 ) )
			: $monto;

		$interes = $base_neto * ( $tasa / 100 ) * ( $dias / 30 );
		$recup   = $base_neto * ( $comision / 100 );
		$total   = (int) round( $interes + $recup );

		return min( $total, $monto );
	}

	public static function panel_rate() {
		$falta = Malka_CRM_Onboarding::missing_for( 'tarifa' );
		$r     = Malka_CRM_Settings::rate();

		ob_start();
		echo '<section class="esc-panel a-tar">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'reloj',
			__( 'Tu tarifa por hora', 'malka-crm' ),
			__( 'Calculada desde lo que necesitas vivir', 'malka-crm' ),
			$falta ? '' : Malka_CRM_Settings::money( $r['tarifa_cobrada'] ),
			$falta ? '' : __( 'la que cobras', 'malka-crm' )
		);

		if ( $falta ) {
			echo '<p class="esc-empty">' . esc_html( $falta ) . '</p>';
			printf(
				'<a class="esc-btn esc-btn--primary" href="%s">%s</a>',
				esc_url( self::view_url( 'configuracion' ) ),
				esc_html__( 'Calcular mi tarifa', 'malka-crm' )
			);
			echo '</section>';
			return ob_get_clean();
		}

		echo '<div class="esc-kv">';
		self::kv( __( 'Tarifa mínima que te cubre', 'malka-crm' ), Malka_CRM_Settings::money( $r['tarifa_minima'] ) );
		self::kv( __( 'Tienes que facturar al año', 'malka-crm' ), Malka_CRM_Settings::money( $r['a_cubrir'] ) );
		self::kv( __( 'Horas facturables al mes', 'malka-crm' ), $r['horas_fact_mes'] . ' h' );
		self::kv(
			__( 'Margen anual a tu tarifa', 'malka-crm' ),
			Malka_CRM_Settings::money( $r['margen_anual'] ),
			$r['margen_anual'] >= 0 ? 'ok' : 'alert'
		);
		echo '</div>';

		if ( 'real' === $r['costos_fuente'] ) {
			echo '<p class="esc-hint">' . esc_html__( 'Usando el promedio real de tus costos de los últimos tres meses.', 'malka-crm' ) . '</p>';
		}

		echo '</section>';
		return ob_get_clean();
	}

	private static function kv( $label, $value, $tono = '', $id = '' ) {
		printf(
			'<span><b>%1$s</b><u class="%2$s"%4$s>%3$s</u></span>',
			esc_html( $label ),
			esc_attr( $tono ? 'is-' . $tono : '' ),
			esc_html( $value ),
			$id ? ' id="' . esc_attr( $id ) . '"' : ''
		);
	}

	/**
	 * Una fila de movimiento: ícono, qué fue, cuándo y de qué tipo, la
	 * factura enlazada si hay, y el monto.
	 *
	 * @param object $m    Movimiento.
	 * @param array  $cats Categorías.
	 * @return string
	 */
	public static function fila_movimiento( $m, $cats ) {
		$cat  = isset( $cats[ $m->categoria ] ) ? $cats[ $m->categoria ] : '';
		$docs = class_exists( 'Malka_CRM_Documents' ) ? Malka_CRM_Documents::de_movimiento( (int) $m->id ) : array();
		$in   = 'entrada' === $m->tipo;
		$meta = array( esc_html( date_i18n( 'j M', strtotime( $m->fecha ) ) ) );
		if ( $cat ) {
			$meta[] = esc_html( $cat . ( (int) $m->recurrente ? ' · ' . __( 'mensual', 'malka-crm' ) : '' ) );
		}
		if ( ! empty( $m->referencia ) ) {
			$meta[] = esc_html( sprintf( /* translators: referencia */ __( 'Ref. %s', 'malka-crm' ), $m->referencia ) );
		}
		if ( count( Malka_CRM_Finance::cuentas() ) > 1 ) {
			$meta[] = '<span class="esc-mov__cta">' . esc_html( Malka_CRM_Finance::nombre_cuenta( isset( $m->cuenta ) ? (int) $m->cuenta : 0 ) ) . '</span>';
		}
		$datos = sprintf(
			' data-mov="%1$s"',
			esc_attr(
				wp_json_encode(
					array(
						'id'          => (int) $m->id,
						'descripcion' => $m->descripcion,
						'referencia'  => isset( $m->referencia ) ? $m->referencia : '',
						'monto'       => (int) $m->monto,
						'tipo'        => $m->tipo,
						'categoria'   => $m->categoria,
						'fecha'       => $m->fecha,
						'recurrente'  => (int) $m->recurrente,
						'cuenta'      => Malka_CRM_Finance::cuenta_valida( isset( $m->cuenta ) ? (int) $m->cuenta : 0 ),
					)
				)
			)
		);
		return sprintf(
			'<div class="esc-mov esc-mov--n%1$s"' . $datos . ' tabindex="0" role="button"><span class="esc-mov__ic" aria-hidden="true">%2$s</span>' .
			'<span class="esc-mov__d"><b>%3$s</b><i>%4$s</i>%5$s</span>' .
			'<span class="esc-mov__v%1$s">%6$s%7$s</span></div>',
			$in ? ' is-in' : '',
			$in
				? '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11.5 4.5l-7 7M4.5 6v5.5H10"/></svg>'
				: '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 11.5l7-7M6 4.5h5.5V10"/></svg>',
			esc_html( $m->descripcion ),
			implode( '<span class="esc-mov__sep">·</span>', $meta ),
			$docs ? '<span class="esc-mov__docs">' . implode( '', array_map( array( 'Malka_CRM_Documents', 'chip' ), $docs ) ) . '</span>' : '',
			esc_html( $in ? '+' : '−' ),
			esc_html( Malka_CRM_Settings::money( $m->monto ) )
		);
	}

	public static function panel_movements() {
		$falta = Malka_CRM_Onboarding::missing_for( 'finanzas' );
		$movs  = Malka_CRM_Finance::recent( 6 );
		$cats  = Malka_CRM_Finance::categories();

		ob_start();
		echo '<section class="esc-panel a-mov">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'flechas',
			__( 'Movimientos', 'malka-crm' ),
			ucfirst( wp_date( 'F' ) ),
			Malka_CRM_Settings::money( Malka_CRM_Finance::balance() ),
			__( 'saldo', 'malka-crm' )
		);

		if ( empty( $movs ) ) {
			echo '<p class="esc-empty">' . esc_html( $falta ? $falta : __( 'Todavía no hay movimientos registrados.', 'malka-crm' ) ) . '</p>';
		}

		echo '<div class="esc-movs" id="esc-movs">';
		foreach ( $movs as $m ) {
			echo self::fila_movimiento( $m, $cats ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';

		printf(
			'<div class="esc-row-btn"><button type="button" class="esc-mini" id="esc-open-mov2">+ %s</button></div>',
			esc_html__( 'Registrar movimiento', 'malka-crm' )
		);

		echo '</section>';
		return ob_get_clean();
	}

	public static function panel_pro_bono() {
		$falta = Malka_CRM_Onboarding::missing_for( 'horas' );
		$r     = Malka_CRM_Time::pro_bono_summary();

		ob_start();
		echo '<section class="esc-panel a-hrs">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'reloj',
			__( 'Horas donadas', 'malka-crm' ),
			sprintf( /* translators: tarifa */ __( 'Proyectos sin costo · %s/h', 'malka-crm' ), Malka_CRM_Settings::money( Malka_CRM_Settings::hourly_rate() ) ),
			$falta ? '' : Malka_CRM_Settings::money( $r['valorizado'] ),
			$falta ? '' : sprintf( /* translators: 1: usadas, 2: tope */ __( '%1$s h · tope %2$d h', 'malka-crm' ), $r['usadas'], $r['tope'] )
		);

		if ( $falta ) {
			echo '<p class="esc-empty">' . esc_html( $falta ) . '</p>';
			echo '</section>';
			return ob_get_clean();
		}

		if ( empty( $r['proyectos'] ) ) {
			echo '<p class="esc-empty">' . esc_html__( 'Ningún proyecto está marcado como sin costo. Puedes marcarlo desde su ficha.', 'malka-crm' ) . '</p>';
		}

		if ( $r['excedido'] ) {
			printf(
				'<p class="esc-hint is-alert">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: horas, 2: monto */
						__( 'Excedido en %1$s h este mes. Son %2$s que no entraron.', 'malka-crm' ),
						$r['exceso'],
						Malka_CRM_Settings::money( (int) round( $r['exceso'] * Malka_CRM_Settings::hourly_rate() ) )
					)
				)
			);
		}

		foreach ( $r['proyectos'] as $p ) {
			$pct = $p['asignadas'] > 0 ? min( 100, round( ( $p['usadas'] / $p['asignadas'] ) * 100 ) ) : 0;
			printf(
				'<a class="esc-hr" href="%1$s"><span class="esc-hr__t"><span class="esc-hr__n">%2$s</span>' .
				'<span class="esc-hr__h%3$s">%4$s %5$s %6$d h</span></span>' .
				'<span class="esc-bar"><i style="width:%7$d%%%8$s"></i></span>' .
				'<span class="esc-hr__v">%9$s %10$s</span></a>',
				esc_url( Malka_CRM_Projects::edit_url( $p['id'] ) ),
				esc_html( $p['nombre'] ),
				$p['excedida'] ? ' is-alert' : '',
				esc_html( $p['usadas'] ),
				esc_html__( 'de', 'malka-crm' ),
				(int) $p['asignadas'],
				(int) $pct,
				$p['excedida'] ? ';background:var(--esc-alert)' : '',
				esc_html__( 'Valorizado', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( $p['valorizado'] ) )
			);
		}

		echo '</section>';
		return ob_get_clean();
	}

	/* ─────────────────────────────  Vistas internas  ──────────────────── */

	/**
	 * Los avisos automáticos al cliente.
	 *
	 * La pausa es una fecha y no un interruptor a propósito: un interruptor
	 * apagado se queda apagado, y lo que justifica tener avisos automáticos
	 * es precisamente que uno se olvida.
	 *
	 * @param array $s Configuración.
	 * @return string
	 */
	private static function settings_avisos( $s ) {
		$pausa = Malka_CRM_Reminders::paused_until();

		ob_start();
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Avisos al cliente', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Los correos que salen solos por las cuotas', 'malka-crm' ) . '</p></div></div>';

		printf(
			'<label class="esc-chk"><input type="checkbox" name="avisos_default" value="1"%1$s /> %2$s</label>',
			! empty( $s['avisos_default'] ) ? ' checked' : '',
			esc_html__( 'Las cotizaciones nuevas nacen con los avisos encendidos', 'malka-crm' )
		);
		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( 'En cada cotización puedes apagarlos por separado, y eso manda sobre esto.', 'malka-crm' )
		);

		echo '<div class="esc-grid2">';
		echo self::field( 'mora_aviso_prev', __( 'Avisar antes de vencer', 'malka-crm' ), $s['mora_aviso_prev'], 'number', __( 'En 0 no se avisa antes.', 'malka-crm' ), __( 'días', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'mora_recordar', __( 'Recordar cada', 'malka-crm' ), $s['mora_recordar'], 'number', __( 'Mientras la cuota siga impaga.', 'malka-crm' ), __( 'días', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo self::field( 'avisos_pausa', __( 'Pausar todos los avisos hasta', 'malka-crm' ), isset( $s['avisos_pausa'] ) ? $s['avisos_pausa'] : '', 'date', __( 'Mientras esta fecha no pase, no sale ningún correo al cliente, de ninguna cotización. Se despausa sola.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $pausa ) {
			printf(
				'<p class="esc-hint is-alert">%s</p>',
				esc_html(
					sprintf(
						/* translators: fecha */
						__( 'Pausados: no sale nada hasta el %s.', 'malka-crm' ),
						date_i18n( 'd/m/Y', strtotime( $pausa ) )
					)
				)
			);
		}

		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( 'Una cotización que ya venía en curso y cargas hoy nunca avisa por cuotas que vencieron antes de cargarla, aunque todo esto esté encendido.', 'malka-crm' )
		);

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * El plazo del saldo y los feriados con que se cuenta.
	 *
	 * Los feriados son fechas, no una fórmula: en Chile se fijan por ley y
	 * cada tanto se mueven o aparece uno nuevo. Por eso van en una lista que
	 * ella puede editar, y por eso el panel avisa cuando se está acabando en
	 * vez de seguir calculando como si no existieran.
	 *
	 * @param array $s Configuración.
	 * @return string
	 */
	private static function settings_plazos( $s ) {
		$habiles = isset( $s['saldo_habiles'] ) ? (int) $s['saldo_habiles'] : 1;
		$hasta   = Malka_CRM_Settings::feriados_hasta();
		$margen  = $hasta ? (int) floor( ( strtotime( $hasta ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : 0;

		ob_start();
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Plazo del saldo', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Cuánto tiene el cliente para pagar después de que entregas', 'malka-crm' ) . '</p></div></div>';

		echo self::field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'saldo_habiles',
			__( 'Plazo para el saldo', 'malka-crm' ),
			$habiles,
			'number',
			__( 'Se cuenta desde el día que marcas el proyecto como entregado. El anticipo no usa este plazo: vence con la cotización.', 'malka-crm' ),
			__( 'días hábiles', 'malka-crm' )
		);

		printf(
			'<p class="esc-f esc-f--full"><label class="esc-f__l" for="f-feriados">%1$s</label>' .
			'<textarea class="esc-f__i esc-f__t" id="f-feriados" name="feriados" rows="6" spellcheck="false">%2$s</textarea>' .
			'<span class="esc-f__h">%3$s</span></p>',
			esc_html__( 'Feriados', 'malka-crm' ),
			esc_textarea( (string) Malka_CRM_Settings::get( 'feriados', '' ) ),
			esc_html__( 'Una fecha por línea, en formato 2026-09-18. Sábados y domingos ya se descuentan solos. Incluye el 31 de diciembre: el banco no mueve plata ese día.', 'malka-crm' )
		);

		if ( ! $hasta ) {
			echo '<p class="esc-hint is-alert">' .
				esc_html__( 'Sin feriados en la lista, el plazo se cuenta como si todos los días de semana fueran hábiles.', 'malka-crm' ) .
				'</p>';
		} elseif ( $margen < 90 ) {
			printf(
				'<p class="esc-hint is-alert">%s</p>',
				esc_html(
					sprintf(
						/* translators: fecha */
						__( 'La lista llega hasta el %s. Agrega los del año siguiente antes de esa fecha o los plazos se van a calcular de más.', 'malka-crm' ),
						date_i18n( 'd/m/Y', strtotime( $hasta ) )
					)
				)
			);
		} else {
			printf(
				'<p class="esc-hint">%s</p>',
				esc_html(
					sprintf(
						/* translators: fecha */
						__( 'La lista llega hasta el %s.', 'malka-crm' ),
						date_i18n( 'd/m/Y', strtotime( $hasta ) )
					)
				)
			);
		}

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Un campo del formulario de configuración.
	 *
	 * @param string $nombre   Nombre del campo.
	 * @param string $etiqueta Etiqueta visible.
	 * @param mixed  $valor    Valor actual.
	 * @param string $tipo     text|number|time|email.
	 * @param string $ayuda    Texto de apoyo.
	 * @param string $unidad   Unidad que se muestra junto al número.
	 * @return string
	 */
	/**
	 * Los tamaños y pesos de los estilos del documento.
	 *
	 * Seis papeles, no las cuarenta reglas del CSS: cada fila manda sobre
	 * varios selectores a la vez. El valor de fábrica va de marcador de
	 * posición, así que un campo vacío quiere decir «déjalo como viene».
	 *
	 * @return string
	 */
	private static function bloque_estilos() {
		$defs   = Malka_CRM_Settings::doc_estilos();
		$ahora  = Malka_CRM_Settings::doc_estilos_valores();
		$pesos  = array( 300, 400, 500, 600, 700, 800 );

		ob_start();
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Estilos del documento', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Tamaño y peso de cada párrafo de la cotización', 'malka-crm' ) . '</p></div></div>';

		echo '<div class="esc-estilos">';
		printf(
			'<div class="esc-estilo esc-estilo--h" aria-hidden="true"><span>%1$s</span><span>%2$s</span><span>%3$s</span></div>',
			esc_html__( 'Estilo', 'malka-crm' ),
			esc_html__( 'Tamaño', 'malka-crm' ),
			esc_html__( 'Peso', 'malka-crm' )
		);

		foreach ( $defs as $k => $def ) {
			echo '<div class="esc-estilo">';

			printf(
				'<span class="esc-estilo__n"><b>%1$s</b><i>%2$s</i></span>',
				esc_html( $def['rotulo'] ),
				esc_html( $def['ayuda'] )
			);

			printf(
				'<span class="esc-estilo__px"><input type="number" min="8" max="48" step="1" name="doc_estilos[%1$s][px]" value="%2$d" aria-label="%3$s" /><em>px</em></span>',
				esc_attr( $k ),
				(int) $ahora[ $k ]['px'],
				esc_attr( sprintf( /* translators: estilo */ __( 'Tamaño de %s', 'malka-crm' ), $def['rotulo'] ) )
			);

			printf(
				'<select name="doc_estilos[%1$s][peso]" aria-label="%2$s">',
				esc_attr( $k ),
				esc_attr( sprintf( /* translators: estilo */ __( 'Peso de %s', 'malka-crm' ), $def['rotulo'] ) )
			);
			foreach ( $pesos as $peso ) {
				printf(
					'<option value="%1$d"%2$s>%3$s</option>',
					(int) $peso,
					selected( (int) $ahora[ $k ]['peso'], $peso, false ),
					esc_html( self::rotulo_peso( $peso ) )
				);
			}
			echo '</select>';

			echo '</div>';
		}

		echo '</div>';

		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( 'Si la tipografía que cargaste no trae un peso, el navegador lo finge engordando la letra. Usa los pesos que de verdad existan en la familia.', 'malka-crm' )
		);

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * El nombre de un peso tipográfico.
	 *
	 * @param int $peso 100–900.
	 * @return string
	 */
	private static function rotulo_peso( $peso ) {
		$nombres = array(
			300 => __( 'Light (300)', 'malka-crm' ),
			400 => __( 'Normal (400)', 'malka-crm' ),
			500 => __( 'Medium (500)', 'malka-crm' ),
			600 => __( 'Semibold (600)', 'malka-crm' ),
			700 => __( 'Bold (700)', 'malka-crm' ),
			800 => __( 'Extrabold (800)', 'malka-crm' ),
		);

		return isset( $nombres[ $peso ] ) ? $nombres[ $peso ] : (string) $peso;
	}

	private static function logo_field( $url ) {
		ob_start();
		echo '<div class="esc-logo">';

		printf(
			'<div class="esc-logo__v" id="esc-logo-v">%s</div>',
			$url
				? sprintf( '<img src="%s" alt="" />', esc_url( $url ) )
				: '<span>' . esc_html__( 'Sin logo', 'malka-crm' ) . '</span>'
		);

		echo '<div class="esc-logo__c">';
		printf( '<label class="esc-f__l" for="f-logo_url">%s</label>', esc_html__( 'Logo de la marca', 'malka-crm' ) );
		printf(
			'<span class="esc-f__w"><input class="esc-f__i" type="url" id="f-logo_url" name="logo_url" value="%1$s" placeholder="%2$s" /></span>',
			esc_attr( $url ),
			esc_attr__( 'https://…', 'malka-crm' )
		);

		printf(
			'<span class="esc-f__h">%s</span>',
			esc_html__( 'Sale arriba de la cotización. Un PNG o SVG con fondo transparente se ve mejor.', 'malka-crm' )
		);

		echo '<div class="esc-row-btn">';
		printf( '<button type="button" class="esc-mini" id="esc-logo-subir">%s</button>', esc_html__( 'Elegir de la biblioteca', 'malka-crm' ) );
		printf( '<button type="button" class="esc-mini" id="esc-logo-quitar">%s</button>', esc_html__( 'Quitar', 'malka-crm' ) );
		echo '</div>';
		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * El ícono que se ve al agregar el escritorio o el portal a la pantalla
	 * de inicio del teléfono.
	 *
	 * @param string $url Imagen.
	 * @return string
	 */
	private static function icono_field( $url ) {
		ob_start();
		echo '<div class="esc-logo esc-logo--icono">';
		printf(
			'<div class="esc-logo__v" id="esc-icono-v">%s</div>',
			$url ? sprintf( '<img src="%s" alt="" />', esc_url( $url ) ) : '<span>' . esc_html__( 'Sin ícono', 'malka-crm' ) . '</span>'
		);
		echo '<div class="esc-logo__c">';
		printf( '<label class="esc-f__l" for="f-icono_app">%s</label>', esc_html__( 'Ícono de la app', 'malka-crm' ) );
		printf(
			'<span class="esc-f__w"><input class="esc-f__i" type="url" id="f-icono_app" name="icono_app" value="%1$s" placeholder="%2$s" /></span>',
			esc_attr( $url ),
			esc_attr__( 'https://…', 'malka-crm' )
		);
		printf(
			'<span class="esc-f__h">%s</span>',
			esc_html__( 'El que aparece al usar «Agregar a inicio» en el teléfono, para el escritorio y el portal del cliente. Una imagen cuadrada de 512 × 512 px, sin transparencia (el iPhone redondea las esquinas solo).', 'malka-crm' )
		);
		echo '<div class="esc-row-btn">';
		printf( '<button type="button" class="esc-mini" id="esc-icono-subir">%s</button>', esc_html__( 'Elegir de la biblioteca', 'malka-crm' ) );
		printf( '<button type="button" class="esc-mini" id="esc-icono-quitar">%s</button>', esc_html__( 'Quitar', 'malka-crm' ) );
		echo '</div></div></div>';
		return ob_get_clean();
	}

	/**
	 * Las etiquetas del ícono para «Agregar a inicio». Si no se eligió uno,
	 * usa el ícono del sitio de WordPress.
	 *
	 * @param string $titulo Nombre bajo el ícono.
	 * @return string
	 */
	public static function etiquetas_icono( $titulo ) {
		$url = (string) Malka_CRM_Settings::get( 'icono_app', '' );
		if ( '' === $url && function_exists( 'get_site_icon_url' ) ) {
			$url = (string) get_site_icon_url( 180 );
		}
		$out  = '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $titulo ) . '" />';
		$out .= '<meta name="application-name" content="' . esc_attr( $titulo ) . '" />';
		$out .= '<meta name="apple-mobile-web-app-capable" content="yes" /><meta name="mobile-web-app-capable" content="yes" />';
		if ( '' !== $url ) {
			$out .= '<link rel="apple-touch-icon" href="' . esc_url( $url ) . '" />';
			$out .= '<link rel="icon" href="' . esc_url( $url ) . '" />';
		}
		return $out;
	}

	private static function field( $nombre, $etiqueta, $valor, $tipo = 'text', $ayuda = '', $unidad = '' ) {
		$id = 'f-' . sanitize_key( $nombre );

		// El peso va antes del número; el porcentaje y las unidades, después.
		$antes   = ( '$' === substr( (string) $unidad, 0, 1 ) ) ? $unidad : '';
		$despues = $antes ? '' : $unidad;

		$u = '<span class="esc-f__u">%s</span>';

		return sprintf(
			'<p class="esc-f"><label class="esc-f__l" for="%1$s">%2$s</label>' .
			'<span class="esc-f__w">%6$s<input class="esc-f__i" type="%3$s" id="%1$s" name="%7$s" value="%4$s"%8$s />%9$s</span>%5$s</p>',
			esc_attr( $id ),
			esc_html( $etiqueta ),
			esc_attr( $tipo ),
			esc_attr( (string) $valor ),
			$ayuda ? '<span class="esc-f__h">' . esc_html( $ayuda ) . '</span>' : '',
			$antes ? sprintf( $u, esc_html( $antes ) ) : '',
			esc_attr( $nombre ),
			'number' === $tipo ? ' step="any" inputmode="decimal"' : '',
			$despues ? sprintf( $u, esc_html( $despues ) ) : ''
		);
	}

	/**
	 * El equivalente en pesos de un costo, o el aviso de que no se puede saber.
	 *
	 * @param array $c Costo.
	 * @return string
	 */
	private static function cost_equivalent( $c ) {
		if ( 'CLP' === $c['moneda'] || ! $c['monto'] ) {
			return '';
		}

		if ( ! $c['clp'] ) {
			/* translators: moneda */
			return sprintf( __( 'falta el tipo de cambio del %s', 'malka-crm' ), $c['moneda'] );
		}

		return '= ' . Malka_CRM_Settings::money( $c['clp'] );
	}

	/**
	 * Configuración completa dentro del escritorio.
	 *
	 * @return string
	 */
	/**
	 * Los mensajes que les llegan a los clientes, para escribirlos ella.
	 *
	 * @return string
	 */
	public static function view_messages() {
		if ( ! class_exists( 'Malka_CRM_Messages' ) ) {
			return '';
		}

		$ok = isset( $_GET['malka_saved'] ) && '1' === $_GET['malka_saved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();

		if ( $ok ) {
			echo '<div class="esc-cfg__ok"><p class="esc-ok">' .
				esc_html__( 'Mensajes guardados. Los próximos que salgan ya van con tus palabras.', 'malka-crm' ) . '</p></div>';
		}

		printf(
			'<form class="esc-cfg esc-cfg--sola esc-msgs" method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="malka_crm_save_settings" />';
		echo '<input type="hidden" name="malka_bloques[]" value="mensajes" />';
		printf( '<input type="hidden" name="malka_volver" value="%s" />', esc_url( self::view_url( 'mensajes' ) ) );
		wp_nonce_field( 'malka_crm_save_settings', 'malka_crm_settings_nonce' );

		echo '<div class="esc-cfg__main">';

		echo '<section class="esc-panel">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Cómo funciona', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Escribe el mensaje como se lo dirías tú', 'malka-crm' ) . '</p></div></div>';
		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( 'Lo que va entre llaves se reemplaza al enviar: {cliente} por su nombre, {monto} por la cifra. Apriétalos para insertarlos donde esté el cursor. Si un dato no existe esa vez —no hay interés que cobrar, por ejemplo— la frase desaparece sola en lugar de quedar coja. Deja un mensaje en blanco y vuelve al texto original.', 'malka-crm' )
		);
		echo '</section>';

		/*
		 * Un correo a la vez: a la izquierda la lista, a la derecha el que
		 * se está editando. Todos siguen en el mismo formulario, así que
		 * cambiar de uno a otro no pierde lo escrito.
		 */
		$plantillas = Malka_CRM_Messages::plantillas();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$elegida = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		$elegida = isset( $plantillas[ $elegida ] ) ? $elegida : (string) key( $plantillas );
		printf( '<div class="esc-msgs__w" data-msg-nonce="%s">', esc_attr( wp_create_nonce( 'malka_crm_msg_plantilla' ) ) );
		echo '<nav class="esc-msgs__nav" aria-label="' . esc_attr__( 'Correos', 'malka-crm' ) . '">';
		foreach ( $plantillas as $clave => $m ) {
			$on = ( $clave === $elegida );
			printf(
				'<button type="button" class="esc-msgs__b%1$s%7$s" data-msg-ir="%2$s"%3$s><b>%4$s</b><span>%5$s</span>%6$s</button>',
				$on ? ' is-on' : '',
				esc_attr( $clave ),
				$on ? ' aria-current="true"' : '',
				esc_html( $m['rotulo'] ),
				esc_html( $m['donde'] ),
				Malka_CRM_Messages::tocado( $clave ) && empty( $m['propia'] ) ? '<i title="' . esc_attr__( 'Cambiado por ti', 'malka-crm' ) . '"></i>' : '',
				! empty( $m['propia'] ) ? ' is-propia' : ''
			);
		}
		echo '</nav><div class="esc-msgs__uno">';
		foreach ( $plantillas as $clave => $m ) {
			$b = self::bloque_mensaje( $clave, $m );
			// Se abre en la elegida (la que se acaba de duplicar, por ejemplo).
			$b = preg_replace( '/^(<section class="esc-panel esc-msg")/', '$1' . ( $clave === $elegida ? ' data-elegida="1"' : '' ), $b );
			echo $b; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></div>';

		echo '</div>';

		echo '<div class="esc-cfg__save esc-ed__save">';
		printf( '<span>%s</span>', esc_html__( 'Mensajes al cliente', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Guardar', 'malka-crm' ) );
		echo '</div>';

		echo '</form>';

		return ob_get_clean();
	}

	/**
	 * Un mensaje: sus campos, su texto y cómo se va a ver.
	 *
	 * @param string $clave Mensaje.
	 * @param array  $m     Plantilla de fábrica.
	 * @return string
	 */
	private static function bloque_mensaje( $clave, $m ) {
		$hoy    = Malka_CRM_Messages::texto( $clave );
		$tocado = Malka_CRM_Messages::tocado( $clave );
		$previa = Malka_CRM_Messages::vista_previa( $clave );

		ob_start();
		printf( '<section class="esc-panel esc-msg" data-mensaje="%s">', esc_attr( $clave ) );

		$propia = ! empty( $m['propia'] );
		echo '<div class="esc-ph"><div>';
		if ( $propia ) {
			printf( '<h2><input class="esc-msg__nombre" type="text" value="%1$s" data-msg-nombre="%2$s" aria-label="%3$s" /></h2>', esc_attr( $m['rotulo'] ), esc_attr( $clave ), esc_attr__( 'Nombre de la plantilla', 'malka-crm' ) );
		} else {
			printf( '<h2>%s</h2>', esc_html( $m['rotulo'] ) );
		}
		printf( '<p>%s</p>', esc_html( $m['donde'] ) );
		echo '</div>';

		echo '<div class="esc-ph__r esc-msg__acc">';
		if ( $tocado && ! $propia ) {
			printf( '<span class="esc-chip esc-chip--ok">%s</span>', esc_html__( 'Cambiado por ti', 'malka-crm' ) );
		}
		$ico_dup = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5V3.5a1 1 0 00-1-1h-6a1 1 0 00-1 1v6a1 1 0 001 1h2"/></svg>';
		$ico_bin = '<svg class="esc-x-ic" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg>';
		$ico_rst = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.75 8a5.25 5.25 0 1 0 1.6-3.77M2.75 2.5v3h3"/></svg>';
		printf( '<button type="button" class="esc-ico" data-msg-op="duplicar" data-clave="%1$s" title="%2$s" aria-label="%2$s">%3$s</button>', esc_attr( $clave ), esc_attr__( 'Duplicar plantilla', 'malka-crm' ), $ico_dup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $propia ) {
			printf( '<button type="button" class="esc-ico esc-ico--bin" data-msg-op="eliminar" data-clave="%1$s" data-confirmar="%2$s" title="%3$s" aria-label="%3$s">%4$s</button>', esc_attr( $clave ), esc_attr__( '¿Eliminar esta plantilla?', 'malka-crm' ), esc_attr__( 'Eliminar plantilla', 'malka-crm' ), $ico_bin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( $tocado ) {
			printf( '<button type="button" class="esc-ico" data-msg-op="eliminar" data-clave="%1$s" data-confirmar="%2$s" title="%3$s" aria-label="%3$s">%4$s</button>', esc_attr( $clave ), esc_attr__( '¿Volver al texto original? Se borra lo que escribiste en esta.', 'malka-crm' ), esc_attr__( 'Volver al texto original (esta sale sola y no se puede eliminar)', 'malka-crm' ), $ico_rst ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';

		echo '</div>';

		printf( '<p class="esc-hint">%s</p>', esc_html( $m['ayuda'] ) );

		/* ── Los campos que se pueden meter en el texto ── */
		echo '<div class="esc-campos">';
		printf( '<span class="esc-campos__t">%s</span>', esc_html__( 'Campos:', 'malka-crm' ) );

		foreach ( array_keys( $m['ejemplo'] ) as $campo ) {
			$pista = sprintf(
				/* translators: ejemplo del valor */
				__( 'Por ejemplo: %s', 'malka-crm' ),
				'' !== trim( (string) $m['ejemplo'][ $campo ] ) ? trim( (string) $m['ejemplo'][ $campo ] ) : __( 'vacío esta vez', 'malka-crm' )
			);

			// Los campos que se llevan su frase se avisan acá: es donde ella
			// los va a meter, y es lo que no se adivina mirando el nombre.
			if ( in_array( $campo, Malka_CRM_Messages::FRASEADOS, true ) ) {
				$pista .= '. ' . __( 'Si esa cuota no tiene interés, la frase donde lo pongas desaparece sola', 'malka-crm' );
			}

			printf(
				'<button type="button" class="esc-campo" data-campo="{%1$s}" title="%2$s">{%1$s}</button>',
				esc_attr( $campo ),
				esc_attr( $pista )
			);
		}

		echo '</div>';

		/* ── Lo que se escribe, al lado de cómo se ve ── */
		echo '<div class="esc-msg__par">';
		echo '<div class="esc-msg__escribe">';

		printf(
			'<p class="esc-f"><label class="esc-f__l" for="msg-%1$s-asunto">%2$s</label>' .
			'<span class="esc-f__w"><input class="esc-f__i" type="text" id="msg-%1$s-asunto" name="mensajes[%1$s][asunto]" value="%3$s" data-campo-destino /></span></p>',
			esc_attr( $clave ),
			esc_html__( 'Asunto del correo', 'malka-crm' ),
			esc_attr( $hoy['asunto'] )
		);

		echo '<div class="esc-grid2">';

		printf(
			'<p class="esc-f"><label class="esc-f__l" for="msg-%1$s-pre">%2$s</label>' .
			'<span class="esc-f__w"><input class="esc-f__i" type="text" id="msg-%1$s-pre" name="mensajes[%1$s][pre]" value="%3$s" data-campo-destino /></span>' .
			'<span class="esc-f__h">%4$s</span></p>',
			esc_attr( $clave ),
			esc_html__( 'Antetítulo', 'malka-crm' ),
			esc_attr( $hoy['pre'] ),
			esc_html__( 'La línea chica y dorada de arriba', 'malka-crm' )
		);

		printf(
			'<p class="esc-f"><label class="esc-f__l" for="msg-%1$s-titulo">%2$s</label>' .
			'<span class="esc-f__w"><input class="esc-f__i" type="text" id="msg-%1$s-titulo" name="mensajes[%1$s][titulo]" value="%3$s" data-campo-destino /></span>' .
			'<span class="esc-f__h">%4$s</span></p>',
			esc_attr( $clave ),
			esc_html__( 'Título grande', 'malka-crm' ),
			esc_attr( $hoy['titulo'] ),
			esc_html__( 'Sale en mayúsculas', 'malka-crm' )
		);

		printf(
			'<p class="esc-f"><label class="esc-f__l" for="msg-%1$s-boton">%2$s</label>' .
			'<span class="esc-f__w"><input class="esc-f__i" type="text" id="msg-%1$s-boton" name="mensajes[%1$s][boton]" value="%3$s" data-campo-destino /></span>' .
			'<span class="esc-f__h">%4$s</span></p>',
			esc_attr( $clave ),
			esc_html__( 'Texto del botón', 'malka-crm' ),
			esc_attr( $hoy['boton'] ),
			esc_html__( 'El que aparece cuando pones un enlace solo', 'malka-crm' )
		);

		echo '</div>';

		printf(
			'<p class="esc-f"><label class="esc-f__l" for="msg-%1$s-cuerpo">%2$s</label>' .
			'<textarea class="esc-f__t" id="msg-%1$s-cuerpo" name="mensajes[%1$s][cuerpo]" rows="14" data-campo-destino>%3$s</textarea></p>',
			esc_attr( $clave ),
			esc_html__( 'El mensaje', 'malka-crm' ),
			esc_textarea( $hoy['cuerpo'] )
		);

		/*
		 * La frase del interés sólo existe donde hay interés que cobrar. En
		 * los otros mensajes un campo vacío con ese rótulo sería ruido.
		 *
		 * Y sólo mientras el cuerpo la use. Si ella escribe la frase a mano
		 * dentro del mensaje —con {interes_monto} suelto, que es lo natural—
		 * {interes} deja de aparecer y este campo pasa a ser un cuadro que no
		 * hace nada: queda editando un texto que ya no se manda a ninguna
		 * parte. Cuando vuelva a poner {interes}, el campo vuelve.
		 */
		if ( '' !== trim( (string) $m['interes'] ) && false !== strpos( (string) $hoy['cuerpo'], '{interes}' ) ) {
			printf(
				'<p class="esc-f"><label class="esc-f__l" for="msg-%1$s-interes">%2$s</label>' .
				'<span class="esc-f__w"><input class="esc-f__i" type="text" id="msg-%1$s-interes" name="mensajes[%1$s][interes]" value="%3$s" data-campo-destino /></span>' .
				'<span class="esc-f__h">%4$s</span></p>',
				esc_attr( $clave ),
				esc_html__( 'La frase del interés', 'malka-crm' ),
				esc_attr( $hoy['interes'] ),
				esc_html__( 'Es lo que reemplaza a {interes} en el mensaje, y desaparece sola cuando esa cuota no tiene interés acumulado.', 'malka-crm' )
			);
		}

		echo self::ayuda_marcado(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf(
			'<p class="esc-msg__pie"><button type="button" class="esc-mini" data-original>%s</button></p>',
			esc_html__( 'Volver al texto original', 'malka-crm' )
		);

		echo '</div>';

		/* ── El correo, como le va a llegar ── */
		echo '<div class="esc-msg__ve">';
		printf( '<span class="esc-previa__t">%s</span>', esc_html__( 'Así le va a llegar', 'malka-crm' ) );
		printf(
			'<div class="esc-correo"><iframe title="%1$s" class="esc-correo__f" data-previa-html srcdoc="%2$s"></iframe></div>',
			esc_attr__( 'Vista previa del correo', 'malka-crm' ),
			esc_attr( $previa['html'] )
		);
		printf(
			'<p class="esc-f__h" data-previa-asunto>%s</p>',
			esc_html( sprintf( /* translators: asunto */ __( 'Asunto: %s', 'malka-crm' ), $previa['asunto'] ) )
		);
		echo '</div>';

		echo '</div>';

		// Para que el JS pueda restaurar el original.
		printf(
			'<script type="application/json" data-mensaje-datos>%s</script>',
			wp_json_encode(
				array(
					'original' => array(
						'asunto' => $m['asunto'],
						'pre'    => $m['pre'],
						'titulo' => $m['titulo'],
						'boton'   => $m['boton'],
						'interes' => $m['interes'],
						'cuerpo'  => $m['cuerpo'],
					),
				)
			)
		);

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Las cuatro marcas que puede escribir en el mensaje.
	 *
	 * @return string
	 */
	private static function ayuda_marcado() {
		$filas = array(
			array( '---', __( 'abre y cierra el recuadro', 'malka-crm' ) ),
			array( '## Título', __( 'el título dorado del recuadro', 'malka-crm' ) ),
			array( '- algo', __( 'una viñeta (y «1.» para numerar)', 'malka-crm' ) ),
			array( '**algo**', __( 'negrita', 'malka-crm' ) ),
		);

		ob_start();
		echo '<div class="esc-marcas">';
		printf( '<span class="esc-marcas__t">%s</span>', esc_html__( 'Para darle forma:', 'malka-crm' ) );
		echo '<dl class="esc-marcas__l">';

		foreach ( $filas as $f ) {
			printf( '<dt>%s</dt><dd>%s</dd>', esc_html( $f[0] ), esc_html( $f[1] ) );
		}

		echo '</dl>';
		printf(
			'<p class="esc-f__h">%s</p>',
			esc_html__( 'Una dirección sola en su línea se convierte en botón. Una línea en blanco empieza un párrafo nuevo.', 'malka-crm' )
		);
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Las frases que el cliente lee dentro de la cotización.
	 *
	 * @return string
	 */
	public static function view_texts() {
		if ( ! class_exists( 'Malka_CRM_Messages' ) ) {
			return '';
		}

		$ok = isset( $_GET['malka_saved'] ) && '1' === $_GET['malka_saved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();

		if ( $ok ) {
			echo '<div class="esc-cfg__ok"><p class="esc-ok">' .
				esc_html__( 'Guardado. La cotización ya dice lo que escribiste.', 'malka-crm' ) . '</p></div>';
		}

		printf(
			'<form class="esc-cfg esc-cfg--sola" method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="malka_crm_save_settings" />';
		echo '<input type="hidden" name="malka_bloques[]" value="textos" />';
		printf( '<input type="hidden" name="malka_volver" value="%s" />', esc_url( self::view_url( 'textos' ) ) );
		wp_nonce_field( 'malka_crm_save_settings', 'malka_crm_settings_nonce' );

		echo '<div class="esc-cfg__main">';

		echo '<section class="esc-panel">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Lo que lee el cliente', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Los rótulos y las explicaciones de la cotización', 'malka-crm' ) . '</p></div></div>';
		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( 'Estas frases salen dentro del documento que abre tu cliente, no en un correo. Deja una en blanco y vuelve a decir lo de siempre.', 'malka-crm' )
		);

		foreach ( Malka_CRM_Messages::textos() as $clave => $t ) {
			$hoy    = Malka_CRM_Messages::texto_doc( $clave );
			$tocado = $hoy !== $t['valor'];

			printf(
				'<p class="esc-f"><label class="esc-f__l" for="txt-%1$s">%2$s%3$s</label>' .
				'<span class="esc-f__w"><input class="esc-f__i" type="text" id="txt-%1$s" name="textos[%1$s]" value="%4$s" /></span>' .
				'<span class="esc-f__h">%5$s</span></p>',
				esc_attr( $clave ),
				esc_html( $t['rotulo'] ),
				$tocado ? ' <span class="esc-chip esc-chip--ok">' . esc_html__( 'Cambiado', 'malka-crm' ) . '</span>' : '',
				esc_attr( $hoy ),
				esc_html( $t['donde'] )
			);
		}

		echo '</section>';
		echo '</div>';

		echo '<div class="esc-cfg__save esc-ed__save">';
		printf( '<span>%s</span>', esc_html__( 'Mensajes de la cotización', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Guardar', 'malka-crm' ) );
		echo '</div>';

		echo '</form>';

		return ob_get_clean();
	}

	public static function view_settings() {
		$s     = Malka_CRM_Settings::all();
		$r     = Malka_CRM_Settings::rate();
		$ok    = isset( $_GET['malka_saved'] ) && '1' === $_GET['malka_saved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dias  = array(
			1 => __( 'Lun', 'malka-crm' ),
			2 => __( 'Mar', 'malka-crm' ),
			3 => __( 'Mié', 'malka-crm' ),
			4 => __( 'Jue', 'malka-crm' ),
			5 => __( 'Vie', 'malka-crm' ),
			6 => __( 'Sáb', 'malka-crm' ),
			7 => __( 'Dom', 'malka-crm' ),
		);

		ob_start();

		if ( $ok ) {
			echo '<div class="esc-cfg__ok"><p class="esc-ok">' .
				esc_html__( 'Guardado. La tarifa se recalculó con los datos nuevos.', 'malka-crm' ) . '</p></div>';
		}

		printf(
			'<form method="post" action="%s" class="esc-cfg esc-cfg--tabs">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="malka_crm_save_settings" />';

		/*
		 * Qué bloques trae este formulario. Sin esto, una casilla desmarcada
		 * acá y un campo que vive sólo en WP-Admin se ven iguales desde el
		 * guardado, y el que no estaba se borraba.
		 */
		foreach ( array( 'empresa', 'cliente', 'banco', 'economia', 'jornada', 'costos', 'cobros' ) as $bloque ) {
			printf( '<input type="hidden" name="malka_bloques[]" value="%s" />', esc_attr( $bloque ) );
		}
		printf( '<input type="hidden" name="malka_volver" value="%s" />', esc_url( self::view_url( 'configuracion' ) ) );
		wp_nonce_field( 'malka_crm_save_settings', 'malka_crm_settings_nonce' );

		/*
		 * Cada bloque se arma aparte y después se reparte en pestañas. Es un
		 * solo formulario: cambiar de pestaña no pierde nada y el guardado
		 * automático manda todo junto, como antes.
		 */
		$sec = array();
		ob_start();

		/* ── Datos del estudio ── */
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Datos del estudio', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Aparecen en cada cotización y en cada documento que emitas', 'malka-crm' ) . '</p></div></div>';
		echo '<div class="esc-grid2">';
		echo self::field( 'razon_social', __( 'Razón social', 'malka-crm' ), $s['razon_social'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'rut', __( 'RUT', 'malka-crm' ), $s['rut'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'giro', __( 'Giro', 'malka-crm' ), $s['giro'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'direccion', __( 'Dirección', 'malka-crm' ), $s['direccion'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'comuna', __( 'Comuna', 'malka-crm' ), $s['comuna'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'telefono', __( 'Teléfono', 'malka-crm' ), $s['telefono'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'email', __( 'Correo', 'malka-crm' ), $s['email'], 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></section>';

		$sec['datos'] = ob_get_clean();
		ob_start();

		/* ── La marca ── */
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Tu marca', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'El logo, el nombre y la tipografía que ve el cliente en la cotización', 'malka-crm' ) . '</p></div></div>';

		echo self::logo_field( $s['logo_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::icono_field( isset( $s['icono_app'] ) ? $s['icono_app'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="esc-grid2">';
		echo self::field( 'nombre_fantasia', __( 'Nombre de fantasía', 'malka-crm' ), $s['nombre_fantasia'], 'text', __( 'Con el que te conocen. Si está vacío sale la razón social.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'terminos_url', __( 'Términos y condiciones', 'malka-crm' ), $s['terminos_url'], 'url', __( 'El enlace que firma el cliente al aceptar.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		/*
		 * La cotización se sirve como página propia, sin el tema, así que la
		 * tipografía del sitio no llega sola: hay que nombrarla y decir de
		 * qué hoja sale.
		 */
		echo self::field( 'tipografia', __( 'Tipografía', 'malka-crm' ), $s['tipografia'], 'text', __( 'El nombre exacto de la familia, como la declara tu tema. Si no carga, la cotización usa la tipografía del sistema.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'tipografia_css', __( 'Hoja de la tipografía', 'malka-crm' ), $s['tipografia_css'], 'url', __( 'La URL del .css que trae el @font-face. Sin esto la cotización no tiene de dónde bajar la fuente.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '</section>';
		$sec['marca'] = ob_get_clean();
		ob_start();
		echo '<section class="esc-panel">';
		echo self::bloque_estilos(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';
		$sec['estilos'] = ob_get_clean();
		ob_start();

		/* ── Dónde le pagan ── */
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Cómo te pagan', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'La cuenta para transferencias y el cobro con tarjeta', 'malka-crm' ) . '</p></div></div>';
		echo '<p class="esc-f__l">' . esc_html__( 'Dónde te transfieren', 'malka-crm' ) . '</p>';
		echo '<div class="esc-grid2">';
		echo self::field( 'banco_nombre', __( 'Banco', 'malka-crm' ), $s['banco_nombre'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'banco_tipo', __( 'Tipo de cuenta', 'malka-crm' ), $s['banco_tipo'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'banco_cuenta', __( 'Número de cuenta', 'malka-crm' ), $s['banco_cuenta'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'banco_titular', __( 'Titular', 'malka-crm' ), $s['banco_titular'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'banco_rut', __( 'RUT del titular', 'malka-crm' ), $s['banco_rut'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		/*
		 * La comisión sólo se podía tocar desde WP-Admin, y con ella en 0 la
		 * cotización le ofrecía al cliente pagar con tarjeta «+0,00%» y el
		 * descuento de SumUp salía del bolsillo de ella.
		 */
		echo self::field( 'comision_tarjeta', __( 'Comisión de SumUp', 'malka-crm' ), $s['comision_tarjeta'], 'number', __( 'La que SumUp te descuenta de verdad, IVA incluido. El cliente paga lo que haga falta para que a ti te llegue el monto completo.', 'malka-crm' ), '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		/*
		 * Cobro con tarjeta. Con la clave de la API el cliente llega a SumUp
		 * con el monto ya puesto; sin ella se usa el enlace fijo.
		 */
		echo '<p class="esc-f__l">' . esc_html__( 'Cobro con tarjeta (SumUp)', 'malka-crm' ) . '</p>';
		echo '<div class="esc-grid2">';
		$tiene_clave = '' !== trim( (string) ( isset( $s['sumup_api_key'] ) ? $s['sumup_api_key'] : '' ) );
		printf(
			'<p class="esc-f"><label class="esc-f__l" for="f-sumup_api_key">%1$s</label><span class="esc-f__w"><input class="esc-f__i" type="password" id="f-sumup_api_key" name="sumup_api_key" value="" autocomplete="new-password" placeholder="%2$s" /></span><span class="esc-f__h">%3$s</span></p>',
			esc_html__( 'Clave secreta de la API', 'malka-crm' ),
			esc_attr( $tiene_clave ? __( '•••••• guardada', 'malka-crm' ) : 'sup_sk_…' ),
			esc_html__( 'En SumUp: Ajustes › Desarrolladores › Claves de API. Déjalo vacío para no cambiarla; escribe un guion (-) para borrarla.', 'malka-crm' )
		);
		echo self::field( 'sumup_merchant', __( 'Código de comercio', 'malka-crm' ), isset( $s['sumup_merchant'] ) ? $s['sumup_merchant'] : '', 'text', __( 'Empieza con M. Sale en tu perfil de SumUp.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'link_sumup', __( 'Enlace de pago de respaldo', 'malka-crm' ), isset( $s['link_sumup'] ) ? $s['link_sumup'] : '', 'url', __( 'Tu enlace pay.sumup.com. Se usa sólo si la API no está configurada o falla.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$err = get_option( 'malka_crm_sumup_ultimo_error' );
		if ( $tiene_clave && is_array( $err ) && ! empty( $err['msg'] ) ) {
			printf( '<p class="esc-f__h">%s</p>', esc_html( sprintf( /* translators: 1: fecha, 2: error */ __( 'Último error de SumUp (%1$s): %2$s', 'malka-crm' ), $err['cuando'], $err['msg'] ) ) );
		}
		echo '</div>';
		echo '</section>';

		$sec['banco'] = ob_get_clean();
		ob_start();

		/* ── Sueldo y jornada ── */
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Tu sueldo y tu jornada', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'De acá salen la meta del mes y el plan del día', 'malka-crm' ) . '</p></div></div>';
		echo '<div class="esc-grid2">';
		echo self::field( 'sueldo_objetivo', __( 'Sueldo líquido al mes', 'malka-crm' ), $s['sueldo_objetivo'], 'number', __( 'Lo que quieres que te quede a ti, después de costos.', 'malka-crm' ), '$' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'nombre_banco', __( 'Tu nombre en la cartola', 'malka-crm' ), isset( $s['nombre_banco'] ) ? $s['nombre_banco'] : '', 'text', __( 'Como sale en tus transferencias, por ejemplo «Belén Muñoz Socha». Así reconozco lo que te pasas a ti como retiro.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'tarifa_cobrada', __( 'Tarifa que cobras', 'malka-crm' ), $s['tarifa_cobrada'], 'number', __( 'Déjalo en 0 para usar la mínima calculada.', 'malka-crm' ), '$/h' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'carga_prev', __( 'Carga previsional', 'malka-crm' ), $s['carga_prev'], 'number', __( 'AFP, salud e impuesto. Pregúntale el número a tu contadora.', 'malka-crm' ), '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'ocupacion', __( 'Ocupación real', 'malka-crm' ), $s['ocupacion'], 'number', __( 'Cuánto de tu jornada es trabajo facturable. El resto es vender, cotizar y administrar.', 'malka-crm' ), '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'colchon', __( 'Colchón', 'malka-crm' ), $s['colchon'], 'number', __( 'Margen para los meses malos.', 'malka-crm' ), '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'vacaciones_dias', __( 'Vacaciones al año', 'malka-crm' ), $s['vacaciones_dias'], 'number', '', __( 'días', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'tope_donadas', __( 'Horas donadas al mes', 'malka-crm' ), $s['tope_donadas'], 'number', __( 'El tope que te permites regalar en proyectos sin costo.', 'malka-crm' ), 'h' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'pomodoro_min', __( 'Largo del pomodoro', 'malka-crm' ), $s['pomodoro_min'], 'number', '', __( 'min', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<p class="esc-f__l">' . esc_html__( 'Días que trabajas', 'malka-crm' ) . '</p>';
		echo '<div class="esc-dias">';
		foreach ( $dias as $n => $et ) {
			printf(
				'<label class="esc-dia-chk"><input type="checkbox" name="dias_semana[]" value="%1$d"%2$s /><span>%3$s</span></label>',
				(int) $n,
				in_array( $n, (array) $s['dias_semana'], true ) ? ' checked' : '',
				esc_html( $et )
			);
		}
		echo '</div>';

		echo '<div class="esc-grid2">';
		echo self::field( 'bloque_am_ini', __( 'Mañana desde', 'malka-crm' ), $s['bloque_am'][0], 'time' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'bloque_am_fin', __( 'Mañana hasta', 'malka-crm' ), $s['bloque_am'][1], 'time' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'bloque_pm_ini', __( 'Tarde desde', 'malka-crm' ), $s['bloque_pm'][0], 'time' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'bloque_pm_fin', __( 'Tarde hasta', 'malka-crm' ), $s['bloque_pm'][1], 'time' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></section>';

		$sec['jornada'] = ob_get_clean();
		$sec['plazo']   = self::settings_plazos( $s );
		$sec['avisos']  = self::settings_avisos( $s );
		ob_start();

		// Los valores se recalculan en vivo con el JS mientras escribe; esto es
		// el estado inicial, el mismo que acaba de calcular el servidor.
		echo '<section class="esc-panel esc-tarifa" id="esc-tarifa">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Tu tarifa', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Se recalcula mientras escribes', 'malka-crm' ) . '</p></div>';
		printf(
			'<div class="esc-ph__r"><em id="esc-t-min">%s</em><span>%s</span></div>',
			esc_html( empty( $r['completo'] ) ? '—' : Malka_CRM_Settings::money( $r['tarifa_minima'] ) ),
			esc_html__( 'mínima por hora', 'malka-crm' )
		);
		echo '</div>';

		printf(
			'<p class="esc-empty" id="esc-t-falta"%s>%s</p>',
			empty( $r['completo'] ) ? '' : ' hidden',
			esc_html__( 'Completa tu sueldo objetivo y al menos un costo fijo para ver el cálculo.', 'malka-crm' )
		);

		printf( '<div class="esc-kv" id="esc-t-kv"%s>', empty( $r['completo'] ) ? ' hidden' : '' );
		self::kv( __( 'Tarifa que cobras', 'malka-crm' ), Malka_CRM_Settings::money( (int) $s['tarifa_cobrada'] ), '', 'esc-t-cobrada' );
		self::kv( __( 'Tienes que facturar al año', 'malka-crm' ), Malka_CRM_Settings::money( $r['a_cubrir'] ), '', 'esc-t-cubrir' );
		self::kv( __( 'Horas facturables al año', 'malka-crm' ), number_format_i18n( $r['horas_fact_anio'] ), '', 'esc-t-hanio' );
		self::kv( __( 'Horas facturables al mes', 'malka-crm' ), number_format_i18n( $r['horas_fact_mes'] ), '', 'esc-t-hmes' );
		self::kv( __( 'Costos del mes', 'malka-crm' ), Malka_CRM_Settings::money( $r['costos_mes'] ), '', 'esc-t-costos' );
		self::kv(
			__( 'Margen anual a tu tarifa', 'malka-crm' ),
			Malka_CRM_Settings::money( $r['margen_anual'] ),
			$r['margen_anual'] < 0 ? 'alert' : 'ok',
			'esc-t-margen'
		);
		echo '</div>';

		printf(
			'<p class="esc-hint" id="esc-t-aviso"%s>%s</p>',
			' hidden',
			esc_html__( 'Estás cobrando bajo tu piso: cada hora que trabajas te cuesta plata.', 'malka-crm' )
		);

		echo '</section>';

		$sec['tarifa'] = ob_get_clean();
		ob_start();

		/* ── Costos fijos ── */
		$fx      = Malka_CRM_Settings::fx();
		$monedas = Malka_CRM_Settings::currencies();

		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Costos fijos del mes', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Lo que pagas todos los meses exista o no trabajo', 'malka-crm' ) . '</p></div>';
		printf(
			'<div class="esc-ph__r"><em id="esc-c-total">%s</em><span>%s</span></div></div>',
			esc_html( Malka_CRM_Settings::money( Malka_CRM_Settings::costs_total() ) ),
			esc_html__( 'al mes', 'malka-crm' )
		);

		/*
		 * De qué cuenta o tarjeta sale cada costo. Lo que se paga con una
		 * tarjeta tuya no baja la cuenta del estudio: el estudio te lo
		 * queda debiendo, y eso se lleva en Movimientos.
		 */
		$de_donde = function ( $elegida ) {
			$out = sprintf(
				'<option value="0"%1$s>%2$s</option>',
				selected( 0, (int) $elegida, false ),
				esc_html__( 'Cuenta del estudio', 'malka-crm' )
			);
			foreach ( Malka_CRM_Finance::cuentas() as $id => $x ) {
				if ( ! empty( $x['principal'] ) ) {
					continue;
				}
				$out .= sprintf(
					'<option value="%1$d"%2$s>%3$s</option>',
					(int) $id,
					selected( (int) $id, (int) $elegida, false ),
					esc_html( Malka_CRM_Finance::nombre_cuenta( (int) $id ) )
				);
			}
			return $out;
		};

		echo '<div class="esc-costos" id="esc-costos">';
		echo '<div class="esc-costo esc-costo--h" aria-hidden="true">' .
			'<span>' . esc_html__( 'Concepto', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Monto', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Moneda', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Día', 'malka-crm' ) . '</span>' .
			'<span title="' . esc_attr__( 'El monto cambia cada mes: al marcarlo pagado en Tu día se pregunta cuánto fue. Los de otra moneda se preguntan siempre.', 'malka-crm' ) . '">' . esc_html__( 'Varía', 'malka-crm' ) . '</span>' .
			'<span title="' . esc_attr__( 'De qué cuenta o tarjeta sale. Si es tuya, el estudio te lo queda debiendo.', 'malka-crm' ) . '">' . esc_html__( 'Sale de', 'malka-crm' ) . '</span><span></span></div>';

		$lista = Malka_CRM_Settings::costs_by_day();
		if ( empty( $lista ) ) {
			$lista = array(
				array(
					'nombre' => '',
					'monto'  => '',
					'moneda' => 'CLP',
					'dia'    => '',
					'variable' => false,
					'clp'    => 0,
				),
			);
		}

		$i = 0;
		foreach ( $lista as $c ) {
			$ops = '';
			foreach ( $monedas as $cod => $et ) {
				$ops .= sprintf(
					'<option value="%1$s"%3$s>%2$s</option>',
					esc_attr( $cod ),
					esc_html( $et ),
					selected( $c['moneda'], $cod, false )
				);
			}

			printf(
				'<div class="esc-costo" data-clp="%7$d">' .
				/*
				 * Lo que esta tabla no edita pero tampoco puede perder: el
				 * id, de quién es el cobro, cada cuánto y en qué mes. Sin
				 * estos cuatro, guardar acá borraba la etiqueta y el ciclo
				 * que se eligieron en el panel de Deudas.
				 */
				'<input type="hidden" name="costos[%1$d][id]" value="%14$d" />' .
				'<input type="hidden" name="costos[%1$d][ambito]" value="%15$s" />' .
				'<input type="hidden" name="costos[%1$d][tipo]" value="%18$s" />' .
				'<input type="hidden" name="costos[%1$d][ciclo]" value="%16$s" />' .
				'<input type="hidden" name="costos[%1$d][mes]" value="%17$d" />' .
				'<input type="text" name="costos[%1$d][nombre]" value="%2$s" placeholder="%3$s" />' .
				'<input type="text" name="costos[%1$d][monto]" value="%4$s" placeholder="0" inputmode="decimal" />' .
				'<select name="costos[%1$d][moneda]">%5$s</select>' .
				'<input type="number" name="costos[%1$d][dia]" value="%6$s" placeholder="—" min="1" max="31" inputmode="numeric" />' .
				'<select name="costos[%1$d][cuenta]" class="esc-costo__cta">%13$s</select>' .
				'<label class="esc-costo__var"><input type="checkbox" class="esc-sw" name="costos[%1$d][variable]" value="1"%11$s aria-label="%12$s" /></label>' .
				'<button type="button" class="esc-costo__x" aria-label="%8$s">&times;</button>' .
				'<span class="esc-costo__clp%10$s">%9$s</span></div>',
				(int) $i,
				esc_attr( $c['nombre'] ),
				esc_attr__( 'Arriendo, software…', 'malka-crm' ),
				esc_attr( '' === $c['monto'] ? '' : (string) $c['monto'] ),
				$ops, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_attr( empty( $c['dia'] ) ? '' : (string) $c['dia'] ),
				(int) $c['clp'],
				esc_attr__( 'Quitar', 'malka-crm' ),
				esc_html( self::cost_equivalent( $c ) ),
				( 'CLP' !== $c['moneda'] && $c['monto'] > 0 && ! $c['clp'] ) ? ' is-alert' : '',
				! empty( $c['variable'] ) ? ' checked' : '',
				esc_attr__( 'El monto varía cada mes', 'malka-crm' ),
				$de_donde( isset( $c['cuenta'] ) ? (int) $c['cuenta'] : 0 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				isset( $c['id'] ) ? (int) $c['id'] : 0,
				esc_attr( isset( $c['ambito'] ) ? $c['ambito'] : 'estudio' ),
				esc_attr( isset( $c['ciclo'] ) ? $c['ciclo'] : 'mensual' ),
				isset( $c['mes'] ) ? (int) $c['mes'] : 0,
				esc_attr( isset( $c['tipo'] ) ? $c['tipo'] : 'fijo' )
			);
			$i++;
		}
		echo '</div>';

		printf(
			'<div class="esc-row-btn"><button type="button" class="esc-mini" id="esc-costo-add">+ %s</button></div>',
			esc_html__( 'Agregar costo', 'malka-crm' )
		);

		// De dónde sale la conversión, para que no sea un número mágico.
		if ( $fx['USD'] > 0 || $fx['EUR'] > 0 ) {
			printf(
				'<p class="esc-hint" id="esc-fx-nota">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: dólar, 2: euro, 3: origen y fecha */
						__( 'USD %1$s · EUR %2$s — %3$s', 'malka-crm' ),
						Malka_CRM_Settings::money( (int) round( $fx['USD'] ) ),
						Malka_CRM_Settings::money( (int) round( $fx['EUR'] ) ),
						$fx['fecha'] ? sprintf( /* translators: 1: fuente, 2: fecha */ __( '%1$s, %2$s', 'malka-crm' ), $fx['fuente'], date_i18n( 'j M Y', strtotime( $fx['fecha'] ) ) ) : $fx['fuente']
					)
				)
			);
		} else {
			echo '<p class="esc-hint is-alert">' .
				esc_html__( 'No se pudo obtener el tipo de cambio. Escríbelo a mano abajo para que los costos en dólares o euros se cuenten.', 'malka-crm' ) .
				'</p>';
		}

		// El cambio a mano vive junto a los costos: es lo único que lo usa
		// para sumar, y desde Impuestos no se veía qué arreglaba.
		echo '<div class="esc-grid2">';
		echo self::field( 'fx_dolar', __( 'Dólar a mano', 'malka-crm' ), $s['fx_dolar'] ? $s['fx_dolar'] : '', 'number', __( 'Vacío usa el dólar observado del Banco Central.', 'malka-crm' ), '$' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'fx_euro', __( 'Euro a mano', 'malka-crm' ), $s['fx_euro'] ? $s['fx_euro'] : '', 'number', __( 'Vacío usa el del Banco Central.', 'malka-crm' ), '$' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '</section>';

		$sec['costos'] = ob_get_clean();
		ob_start();

		/* ── Tributario y mora ── */
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Impuestos y mora', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Lo que se aplica a cada cotización y a los atrasos', 'malka-crm' ) . '</p></div></div>';
		echo '<div class="esc-grid2">';
		echo self::field( 'iva', __( 'IVA', 'malka-crm' ), $s['iva'], 'number', '', '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'retencion', __( 'Retención de honorarios', 'malka-crm' ), $s['retencion'], 'number', __( 'Solo si emites boleta de honorarios.', 'malka-crm' ), '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'ppm', __( 'Tasa de PPM', 'malka-crm' ), isset( $s['ppm'] ) ? $s['ppm'] : 0, 'number', __( 'El pago provisional mensual sobre tus ventas netas (código 115 del F29). Se actualiza solo al importar un F29.', 'malka-crm' ), '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'mora_tasa', __( 'Interés por atraso', 'malka-crm' ), $s['mora_tasa'], 'number', __( 'Mensual, sobre el neto. En 0 solo se cobra la comisión de cobranza de abajo.', 'malka-crm' ), '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'mora_comision', __( 'Comisión de cobranza', 'malka-crm' ), $s['mora_comision'], 'number', '', '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo '<p class="esc-hint">' . esc_html__( 'El interés por atraso no se factura: es indemnización, no venta. El panel genera un comprobante interno y se lo manda a tu contadora.', 'malka-crm' ) . '</p>';
		echo '</section>';


		$sec['impuestos'] = ob_get_clean();
		ob_start();

		/* ── Contadora ── */
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Tu contadora', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'A quién le llegan los comprobantes automáticos', 'malka-crm' ) . '</p></div></div>';
		echo '<div class="esc-grid2">';
		echo self::field( 'cont_nombre', __( 'Nombre', 'malka-crm' ), $s['cont_nombre'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'cont_email', __( 'Correo', 'malka-crm' ), $s['cont_email'], 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'cont_telefono', __( 'Teléfono', 'malka-crm' ), $s['cont_telefono'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::field( 'cont_copia', __( 'Copia a', 'malka-crm' ), $s['cont_copia'], 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		printf(
			'<label class="esc-chk"><input type="checkbox" name="cont_envia_mora" value="1"%s /> %s</label>',
			$s['cont_envia_mora'] ? ' checked' : '',
			esc_html__( 'Mandarle el comprobante cada vez que se cobre un interés por atraso', 'malka-crm' )
		);
		echo '</section>';
		$sec['contadora'] = ob_get_clean();
		ob_start();

		/* ── Asistente ── */
		$tiene_ia = '' !== trim( (string) ( isset( $s['ia_api_key'] ) ? $s['ia_api_key'] : '' ) );
		echo '<section class="esc-panel" id="cfg-ia"><div class="esc-ph"><div><h2>' . esc_html__( 'Asistente', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Opcional. El chat del escritorio, conectado a Claude, se paga por uso directo a Anthropic. Sin clave, no aparece', 'malka-crm' ) . '</p></div></div>';
		echo '<div class="esc-grid2">';
		printf(
			'<p class="esc-f"><label class="esc-f__l" for="f-ia_api_key">%1$s</label><span class="esc-f__w"><input class="esc-f__i" type="password" id="f-ia_api_key" name="ia_api_key" value="" autocomplete="new-password" placeholder="%2$s" /></span><span class="esc-f__h">%3$s</span></p>',
			esc_html__( 'Clave de la API de Anthropic', 'malka-crm' ),
			esc_attr( $tiene_ia ? __( '•••••• guardada', 'malka-crm' ) : 'sk-ant-…' ),
			esc_html__( 'Se crea en console.anthropic.com › API Keys. Déjalo vacío para no cambiarla; escribe un guion (-) para borrarla.', 'malka-crm' )
		);
		echo self::field( 'ia_modelo', __( 'Modelo', 'malka-crm' ), isset( $s['ia_modelo'] ) ? $s['ia_modelo'] : '', 'text', sprintf( /* translators: modelo */ __( 'Déjalo vacío para usar %s.', 'malka-crm' ), Malka_CRM_Asistente::MODELO ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></section>';
		$sec['asistente'] = ob_get_clean();

		/* ── Actualizaciones ── */
		$repo   = class_exists( 'Malka_CRM_Updater' ) ? Malka_CRM_Updater::repo() : '';
		$tiene_t = '' !== trim( (string) ( isset( $s['repo_token'] ) ? $s['repo_token'] : '' ) );
		ob_start();
		echo '<section class="esc-panel" id="cfg-repo"><div class="esc-ph"><div><h2>' . esc_html__( 'Actualizaciones', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Opcional. Con esto el panel avisa solo cuando hay una versión nueva y se actualiza desde Plugins, sin bajar ni subir el archivo', 'malka-crm' ) . '</p></div></div>';
		echo '<div class="esc-grid2">';
		echo self::field( 'repo', __( 'Repositorio', 'malka-crm' ), isset( $s['repo'] ) ? $s['repo'] : '', 'text', __( 'Como «usuario/malka-crm». Puedes pegar la dirección completa de GitHub. Vacío: no se busca ninguna versión.', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<p class="esc-f"><label class="esc-f__l" for="f-repo_token">%1$s</label><span class="esc-f__w"><input class="esc-f__i" type="password" id="f-repo_token" name="repo_token" value="" autocomplete="new-password" placeholder="%2$s" /></span><span class="esc-f__h">%3$s</span></p>',
			esc_html__( 'Llave, si el repositorio es privado', 'malka-crm' ),
			esc_attr( $tiene_t ? __( '•••••• guardada', 'malka-crm' ) : 'github_pat_…' ),
			esc_html__( 'Un repositorio público no la necesita. Déjalo vacío para no cambiarla; escribe un guion (-) para borrarla.', 'malka-crm' )
		);
		echo '</div>';

		if ( '' !== $repo ) {
			$u = Malka_CRM_Updater::ultima();
			printf(
				'<p class="esc-hint">%s</p>',
				esc_html(
					$u
						? sprintf(
							/* translators: 1: versión publicada, 2: versión instalada */
							__( 'Última publicada: %1$s. Tienes la %2$s.', 'malka-crm' ),
							$u['version'],
							MALKA_CRM_VERSION
						)
						: __( 'No se pudo leer ese repositorio. Revisa el nombre y, si es privado, la llave.', 'malka-crm' )
				)
			);
		}

		echo '</section>';
		$sec['repo'] = ob_get_clean();

		/*
		 * Las pestañas: [columna izquierda, columna derecha]. La tarifa va a la
		 * derecha de la jornada para ver cómo cambia mientras se escribe.
		 */
		$tabs = array(
			'estudio' => array( array( 'datos' ), array( 'contadora', 'asistente', 'repo' ) ),
			'marca'   => array( array( 'marca' ), array( 'estilos' ) ),
			'cobros'  => array( array( 'banco', 'plazo' ), array( 'impuestos', 'avisos' ) ),
			'tarifa'  => array( array( 'jornada' ), array( 'tarifa', 'costos' ) ),
		);

		foreach ( $tabs as $k => $cols ) {
			printf( '<div class="esc-cfg__tab" data-cfg-tab="%1$s" id="cfg-%1$s"%2$s>', esc_attr( $k ), 'estudio' === $k ? '' : ' hidden' );
			foreach ( $cols as $col ) {
				echo '<div class="esc-cfg__col">';
				foreach ( $col as $b ) {
					echo $sec[ $b ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</div>';
			}
			echo '</div>';
		}

		printf(
			'<div class="esc-cfg__save"><span>%s</span><button type="submit" class="esc-btn esc-btn--primary">%s</button></div>',
			esc_html__( 'Guarda para que el escritorio use estos números.', 'malka-crm' ),
			esc_html__( 'Guardar', 'malka-crm' )
		);

		echo '</form>';

		/*
		 * Qué versión está puesta. Parece un detalle y no lo es: cuando algo
		 * nuevo «no aparece», casi siempre es que el panel todavía tiene la
		 * versión de antes, y desde acá adentro no había manera de saberlo
		 * sin ir a WP-Admin, que es justo lo que este escritorio evita.
		 */
		printf(
			'<p class="esc-cfg__ver">%s</p>',
			esc_html(
				sprintf(
					/* translators: número de versión del plugin */
					__( 'Malka CRM %s', 'malka-crm' ),
					MALKA_CRM_VERSION
				)
			)
		);

		return ob_get_clean();
	}

	/**
	 * La fila de etiquetas para filtrar una lista («Web 3 · Branding 2»).
	 *
	 * @param array $cuenta etiqueta => cantidad.
	 * @return string
	 */
	public static function filtro_etiquetas( $cuenta ) {
		if ( ! $cuenta ) {
			return '';
		}
		$out = '<div class="esc-tagf" role="group" aria-label="' . esc_attr__( 'Filtrar por etiqueta', 'malka-crm' ) . '"><span>' . esc_html__( 'Etiquetas', 'malka-crm' ) . '</span>';
		foreach ( $cuenta as $t => $n ) {
			$out .= sprintf(
				'<button type="button" class="esc-tagf__b" data-etiqueta="%1$s" aria-pressed="false">%2$s <i>%3$d</i></button>',
				esc_attr( self::llave_etiqueta( $t ) ),
				esc_html( $t ),
				(int) $n
			);
		}
		return $out . '</div>';
	}

	/**
	 * La etiqueta en minúsculas y sin tildes, para comparar.
	 *
	 * @param string $t Etiqueta.
	 * @return string
	 */
	public static function llave_etiqueta( $t ) {
		return sanitize_title( $t );
	}

	/**
	 * Las etiquetas como chips chicos dentro de una fila.
	 *
	 * @param string[] $tags Etiquetas.
	 * @return string
	 */
	public static function chips_etiquetas( $tags ) {
		$out = '';
		foreach ( $tags as $t ) {
			$out .= '<span class="esc-tag esc-tag--s">' . esc_html( $t ) . '</span>';
		}
		return $out ? '<span class="esc-tags__fila">' . $out . '</span>' : '';
	}

	/**
	 * Cotizaciones: el embudo arriba y la lista abajo.
	 *
	 * @return string
	 */
	public static function view_quotes() {
		$m     = Malka_CRM_Analysis::quote_stats();
		$filas = Malka_CRM_Analysis::quote_rows();
		$falta = Malka_CRM_Onboarding::missing_for( 'negocios' );

		ob_start();
		echo '<div class="esc-cot">';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['guardada'] ) ) {
			echo '<p class="esc-ok">' . esc_html__( 'Guardada. El cliente ya ve los cambios en su página.', 'malka-crm' ) . '</p>';

			// Si el estado elegido no sobrevivió al recálculo, se explica acá
			// en vez de dejarla creyendo que el guardado falló.
			$nota = Malka_CRM_Quote_Editor::nota_estado( (int) $_GET['guardada'] );

			if ( $nota ) {
				echo '<p class="esc-aviso">' . esc_html( $nota ) . '</p>';
			}

			$firma = Malka_CRM_Quote_Editor::nota_firma();

			if ( $firma ) {
				echo '<p class="esc-aviso">' . esc_html( $firma ) . '</p>';
			}
		}

		if ( isset( $_GET['borrada'] ) ) {
			printf(
				'<p class="esc-ok">%s</p>',
				esc_html(
					sprintf(
						/* translators: número de la cotización */
						__( 'La %s quedó en la papelera. Se recupera desde WordPress si la necesitas.', 'malka-crm' ),
						sanitize_text_field( wp_unslash( $_GET['borrada'] ) )
					)
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/* ── Embudo ── */
		echo '<section class="esc-panel esc-cot__m">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Cómo vas cerrando', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: cantidad de cotizaciones */
				_n( '%d cotización en total', '%d cotizaciones en total', $m['total'], 'malka-crm' ),
				$m['total']
			)
		) . '</p></div></div>';

		if ( ! $m['total'] ) {
			echo '<p class="esc-empty">' . esc_html( $falta ? $falta : __( 'Todavía no hay cotizaciones.', 'malka-crm' ) ) . '</p>';
		} else {
			echo '<div class="esc-cifras">';

			self::cifra(
				null === $m['tasa'] ? '—' : $m['tasa'] . '%',
				__( 'Tasa de cierre', 'malka-crm' ),
				null === $m['tasa']
					? __( 'Aún no se cierra ninguna', 'malka-crm' )
					: sprintf( /* translators: 1: ganadas, 2: cerradas */ __( '%1$d de %2$d cerradas', 'malka-crm' ), $m['ganadas'], $m['cerrando'] ),
				( null !== $m['tasa'] && $m['tasa'] < 35 ) ? 'alert' : ''
			);

			self::cifra(
				Malka_CRM_Settings::money( $m['monto_abierto'] ),
				__( 'Esperando respuesta', 'malka-crm' ),
				sprintf( /* translators: cantidad */ _n( '%d cotización', '%d cotizaciones', $m['abiertas'], 'malka-crm' ), $m['abiertas'] )
			);

			self::cifra(
				Malka_CRM_Settings::money( $m['monto_ganado'] ),
				__( 'Ganado', 'malka-crm' ),
				sprintf( /* translators: cantidad */ _n( '%d cotización', '%d cotizaciones', $m['ganadas'], 'malka-crm' ), $m['ganadas'] )
			);

			self::cifra(
				Malka_CRM_Settings::money( $m['promedio'] ),
				__( 'Promedio por negocio', 'malka-crm' ),
				__( 'De lo que has ganado', 'malka-crm' )
			);

			echo '</div>';

			if ( ! empty( $m['por_vencer'] ) ) {
				echo '<div class="esc-cot__aviso">';
				echo '<b>' . esc_html__( 'Caducan pronto y siguen sin respuesta', 'malka-crm' ) . '</b>';
				foreach ( $m['por_vencer'] as $v ) {
					printf(
						'<span>%1$s · %2$s <i>%3$s</i></span>',
						esc_html( $v['numero'] ? 'N° ' . $v['numero'] : $v['titulo'] ),
						esc_html( Malka_CRM_Settings::money( $v['monto'] ) ),
						esc_html(
							0 === $v['dias']
								? __( 'vence hoy', 'malka-crm' )
								: sprintf( /* translators: días */ _n( 'en %d día', 'en %d días', $v['dias'], 'malka-crm' ), $v['dias'] )
						)
					);
				}
				echo '</div>';
			}
		}
		echo '</section>';

		/* ── Entregas ── */
		echo self::deliveries_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/* ── Lista ── */
		$filtro = self::quote_filter();
		$vistas = self::sort_quotes( $filas, $filtro['orden'] );

		echo '<section class="esc-panel">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Todas', 'malka-crm' ) . '</h2>';
		printf(
			'<p>%s</p></div>',
			esc_html( sprintf( /* translators: cantidad */ _n( '%d cotización', '%d cotizaciones', count( $filas ), 'malka-crm' ), count( $filas ) ) )
		);
		printf(
			'<div class="esc-ph__r"><a class="esc-btn esc-btn--primary" href="%s">+ %s</a></div></div>',
			esc_url( Malka_CRM_Quote_Editor::edit_url() ),
			esc_html__( 'Nueva cotización', 'malka-crm' )
		);

		if ( empty( $filas ) ) {
			echo '<p class="esc-empty">' . esc_html__( 'Cuando crees la primera, aparece acá con su estado y su cobranza.', 'malka-crm' ) . '</p>';
			echo '</section></div>';
			return ob_get_clean();
		}

		/*
		 * Pestañas por estado, buscador y orden en una sola barra. Las
		 * pestañas y el buscador filtran al instante, sin recargar (el mismo
		 * código de la lista de negocios); el orden sigue yendo por la URL.
		 */
		$cuenta = array();
		foreach ( $filas as $f ) {
			$cuenta[ $f['estado'] ] = isset( $cuenta[ $f['estado'] ] ) ? $cuenta[ $f['estado'] ] + 1 : 1;
		}

		echo '<div class="esc-nl__bar"><div class="esc-nl__tabs" role="tablist">';
		printf(
			'<button type="button" class="esc-nl__tab%1$s" role="tab" data-grupo="todos">%2$s <i>%3$d</i></button>',
			'' === $filtro['estado'] ? ' is-on' : '',
			esc_html__( 'Todas', 'malka-crm' ),
			count( $filas )
		);
		foreach ( Malka_CRM_Quotes::quote_statuses() as $k => $rotulo ) {
			if ( empty( $cuenta[ $k ] ) ) {
				continue;
			}
			printf(
				'<button type="button" class="esc-nl__tab%1$s" role="tab" data-grupo="%2$s">%3$s <i>%4$d</i></button>',
				$filtro['estado'] === $k ? ' is-on' : '',
				esc_attr( $k ),
				esc_html( $rotulo ),
				(int) $cuenta[ $k ]
			);
		}
		echo '</div><div class="esc-ql__tools">';
		printf(
			'<input type="search" class="esc-nl__q" id="esc-nl-q" placeholder="%1$s" aria-label="%1$s" autocomplete="off" />',
			esc_attr__( 'Busca por cliente, proyecto o N°', 'malka-crm' )
		);
		printf( '<form method="get" action="%s" class="esc-ql__orden">', esc_url( self::view_url( 'cotizaciones' ) ) );
		printf( '<label class="screen-reader-text" for="esc-orden">%s</label>', esc_html__( 'Ordenar por', 'malka-crm' ) );
		echo '<select name="orden" id="esc-orden" onchange="this.form.submit()">';
		foreach ( self::quote_orders() as $k => $rotulo ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $k ), selected( $filtro['orden'], $k, false ), esc_html( $rotulo ) );
		}
		echo '</select></form></div></div>';

		echo self::filtro_etiquetas( Malka_CRM_Quotes::todas_etiquetas() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="esc-nl__list" id="esc-nl">';
		printf(
			'<div class="esc-nl esc-nl--h esc-ql"><span>%1$s</span><span>%2$s</span><span>%3$s</span><span>%4$s</span><span>%5$s</span><span>%6$s</span><span></span></div>',
			esc_html__( 'N°', 'malka-crm' ),
			esc_html__( 'Cliente y proyecto', 'malka-crm' ),
			esc_html__( 'Estado', 'malka-crm' ),
			esc_html__( 'Total', 'malka-crm' ),
			esc_html__( 'Cobrado', 'malka-crm' ),
			esc_html__( 'Vence', 'malka-crm' )
		);

		foreach ( $vistas as $f ) {
			$clase  = self::quote_row_tone( $f );
			$alerta = ( null !== $f['dias'] && $f['dias'] < 0 && ! self::quote_won( $f['estado'] ) );

			if ( $f['vencido'] > 0 ) {
				$pie = '<span class="is-late">' . esc_html( sprintf( /* translators: monto */ __( '%s vencido', 'malka-crm' ), Malka_CRM_Settings::money( $f['vencido'] ) ) ) . '</span>';
			} elseif ( $f['cuotas'] ) {
				$pie = '<span>' . esc_html( sprintf( /* translators: cuotas */ _n( '%d cuota', '%d cuotas', $f['cuotas'], 'malka-crm' ), $f['cuotas'] ) ) . '</span>';
			} else {
				$pie = '';
			}

			$tags   = Malka_CRM_Quotes::etiquetas( $f['id'] );
			$buscar = Malka_CRM_Clients::plegar( implode( ' ', array_merge( array( $f['numero'], 'n' . $f['numero'], $f['cliente'], $f['titulo'] ), $tags ) ) );

			/*
			 * La fila entera abre el editor (un enlace estirado); el ojo de
			 * la derecha abre la página del cliente y queda encima. Borrar
			 * vive ahora dentro de la cotización, como en negocios y clientes.
			 */
			printf(
				'<div class="esc-nl esc-ql" data-grupo="%1$s" data-buscar="%2$s" data-tags="%16$s">' .
				'<span class="esc-ql__n"><a class="esc-ql__a" href="%3$s">%4$s</a></span>' .
				'<span class="esc-nl__p"><b>%5$s</b><span>%6$s</span>%17$s</span>' .
				'<span><span class="esc-st esc-st--%7$s">%8$s</span></span>' .
				'<span class="esc-ql__m">%9$s</span>' .
				'<span class="esc-ql__p"><span class="esc-ql__pl"><span class="esc-ct__bar" style="--p:%10$d"></span><b>%11$s</b></span>%12$s</span>' .
				'<span class="esc-ql__v%13$s">%14$s</span>' .
				'<span class="esc-ql__acc">%15$s</span></div>',
				esc_attr( $f['estado'] ),
				esc_attr( $buscar ),
				esc_url( $f['url'] ),
				esc_html( $f['numero'] ? 'N° ' . $f['numero'] : '—' ),
				esc_html( $f['cliente'] ? $f['cliente'] : $f['titulo'] ),
				esc_html( $f['cliente'] ? $f['titulo'] : '' ),
				esc_attr( $clase ),
				esc_html( $f['etiqueta'] ),
				esc_html( Malka_CRM_Settings::money( $f['total'] ) ),
				(int) $f['pct'],
				esc_html( $f['total'] > 0 ? $f['pct'] . '%' : '—' ),
				$pie, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$alerta ? ' is-late' : '',
				esc_html( self::quote_when( $f ) ),
				( ! empty( $f['publica'] )
					? sprintf(
						'<a class="esc-ico" href="%1$s" target="_blank" rel="noopener" title="%2$s" aria-label="%2$s">%3$s</a>',
						esc_url( $f['publica'] ),
						esc_attr__( 'Ver la página del cliente', 'malka-crm' ),
						self::icono( '<path d="M1.5 8S4 3.8 8 3.8 14.5 8 14.5 8 12 12.2 8 12.2 1.5 8 1.5 8z"/><circle cx="8" cy="8" r="1.9"/>' )
					)
					: '' ) .
				// Duplicar: abre una copia nueva lista para editar.
				sprintf(
					'<a class="esc-ico" href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',
					esc_url( Malka_CRM_Quote_Editor::duplicate_url( $f['id'] ) ),
					esc_attr( sprintf( /* translators: número */ __( 'Duplicar la N° %s', 'malka-crm' ), $f['numero'] ) ),
					self::icono( '<rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5v-2a1 1 0 00-1-1h-6a1 1 0 00-1 1v6a1 1 0 001 1h2"/>' )
				) .
				// La papelera, al lado del ojo: pide confirmación y se recupera.
				sprintf(
					'<a class="esc-ico esc-ico--bin" href="%1$s" title="%2$s" aria-label="%2$s" data-confirmar="%3$s">%4$s</a>',
					esc_url( Malka_CRM_Quote_Editor::delete_url( $f['id'] ) ),
					esc_attr__( 'Mandar a la papelera', 'malka-crm' ),
					esc_attr( sprintf( /* translators: identificación de la cotización */ __( '¿Mandar %s a la papelera? La puedes recuperar después.', 'malka-crm' ), $f['numero'] ? 'la N° ' . $f['numero'] : $f['titulo'] ) ),
					self::icono( '<path d="M4 4l8 8M12 4l-8 8"/>' )
				),
				esc_attr( implode( '|', array_map( array( __CLASS__, 'llave_etiqueta' ), $tags ) ) ),
				self::chips_etiquetas( $tags ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		printf( '<p class="esc-nl__vacio" hidden>%s</p>', esc_html__( 'Ninguna cotización calza con eso.', 'malka-crm' ) );
		echo '</div></section></div>';

		return ob_get_clean();
	}

	/**
	 * Cómo se puede ordenar la lista.
	 *
	 * @return array clave => rótulo.
	 */
	public static function quote_orders() {
		return array(
			'reciente' => __( 'Más nuevas primero', 'malka-crm' ),
			'antigua'  => __( 'Más antiguas primero', 'malka-crm' ),
			'numero'   => __( 'Número', 'malka-crm' ),
			'monto'    => __( 'Monto, de mayor a menor', 'malka-crm' ),
			'deuda'    => __( 'Lo que más deben', 'malka-crm' ),
			'vence'    => __( 'Lo que vence antes', 'malka-crm' ),
			'cliente'  => __( 'Cliente, A–Z', 'malka-crm' ),
		);
	}

	/**
	 * El filtro pedido, ya validado.
	 *
	 * Va por la URL y no por JavaScript a propósito: así el filtro se puede
	 * guardar en favoritos, compartir y volver atrás con el botón del
	 * navegador, y sigue funcionando si el JS no carga.
	 *
	 * @return array {estado, orden}
	 */
	public static function quote_filter() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$estado = isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) ) : '';
		$orden  = isset( $_GET['orden'] ) ? sanitize_key( wp_unslash( $_GET['orden'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$estados = Malka_CRM_Quotes::quote_statuses();

		return array(
			'estado' => isset( $estados[ $estado ] ) ? $estado : '',
			'orden'  => array_key_exists( $orden, self::quote_orders() ) ? $orden : 'reciente',
		);
	}

	/**
	 * Deja sólo las de un estado.
	 *
	 * @param array[] $filas  Filas.
	 * @param string  $estado Estado, o '' para todas.
	 * @return array[]
	 */
	public static function filter_quotes( $filas, $estado ) {
		if ( ! $estado ) {
			return $filas;
		}

		$out = array();

		foreach ( $filas as $f ) {
			if ( $estado === $f['estado'] ) {
				$out[] = $f;
			}
		}

		return $out;
	}

	/**
	 * Ordena la lista.
	 *
	 * Las que no tienen el dato por el que se ordena van al final, no al
	 * principio: una cotización sin fecha de vencimiento no es la más urgente.
	 *
	 * @param array[] $filas Filas.
	 * @param string  $orden Clave.
	 * @return array[]
	 */
	public static function sort_quotes( $filas, $orden ) {
		$fin = '9999-12-31';

		$comparar = array(
			'reciente' => function ( $a, $b ) {
				return strcmp( (string) $b['emitida'], (string) $a['emitida'] );
			},
			'antigua'  => function ( $a, $b ) use ( $fin ) {
				return strcmp(
					$a['emitida'] ? (string) $a['emitida'] : $fin,
					$b['emitida'] ? (string) $b['emitida'] : $fin
				);
			},
			'numero'   => function ( $a, $b ) {
				return (int) $b['numero'] <=> (int) $a['numero'];
			},
			'monto'    => function ( $a, $b ) {
				return (int) $b['total'] <=> (int) $a['total'];
			},
			'deuda'    => function ( $a, $b ) {
				return ( (int) $b['total'] - (int) $b['pagado'] ) <=> ( (int) $a['total'] - (int) $a['pagado'] );
			},
			'vence'    => function ( $a, $b ) use ( $fin ) {
				return strcmp(
					$a['vence'] ? (string) $a['vence'] : $fin,
					$b['vence'] ? (string) $b['vence'] : $fin
				);
			},
			'cliente'  => function ( $a, $b ) {
				return strcasecmp(
					$a['cliente'] ? (string) $a['cliente'] : (string) $a['titulo'],
					$b['cliente'] ? (string) $b['cliente'] : (string) $b['titulo']
				);
			},
		);

		if ( isset( $comparar[ $orden ] ) ) {
			usort( $filas, $comparar[ $orden ] );
		}

		return $filas;
	}

	/**
	 * La barra de filtros, con cuántas hay en cada estado.
	 *
	 * El número al lado de cada estado evita el clic a ciegas: si dice 0, no
	 * hay para qué entrar.
	 *
	 * @param array[] $filas  Todas las filas.
	 * @param array   $filtro Filtro actual.
	 * @return string
	 */
	private static function quote_filter_bar( $filas, $filtro ) {
		$cuenta = array();

		foreach ( $filas as $f ) {
			$k            = (string) $f['estado'];
			$cuenta[ $k ] = isset( $cuenta[ $k ] ) ? $cuenta[ $k ] + 1 : 1;
		}

		$base = self::view_url( 'cotizaciones' );

		ob_start();
		echo '<div class="esc-filtros">';

		echo '<div class="esc-chips">';

		$opciones = array( '' => __( 'Todas', 'malka-crm' ) ) + Malka_CRM_Quotes::quote_statuses();

		foreach ( $opciones as $k => $rotulo ) {
			if ( '' !== $k && empty( $cuenta[ $k ] ) ) {
				continue;
			}

			$url = $k ? add_query_arg( 'estado', $k, $base ) : $base;

			if ( 'reciente' !== $filtro['orden'] ) {
				$url = add_query_arg( 'orden', $filtro['orden'], $url );
			}

			printf(
				'<a class="esc-chip%1$s" href="%2$s"%3$s>%4$s <i>%5$d</i></a>',
				$filtro['estado'] === $k ? ' is-on' : '',
				esc_url( $url ),
				$filtro['estado'] === $k ? ' aria-current="true"' : '',
				esc_html( $rotulo ),
				$k ? (int) $cuenta[ $k ] : count( $filas )
			);
		}

		echo '</div>';

		printf(
			'<form method="get" action="%s" class="esc-orden">',
			esc_url( $base )
		);

		if ( $filtro['estado'] ) {
			printf( '<input type="hidden" name="estado" value="%s" />', esc_attr( $filtro['estado'] ) );
		}

		printf(
			'<label class="esc-orden__l" for="esc-orden">%s</label>',
			esc_html__( 'Ordenar por', 'malka-crm' )
		);

		echo '<select name="orden" id="esc-orden" onchange="this.form.submit()">';
		foreach ( self::quote_orders() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $filtro['orden'], $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select>';

		// Sin JavaScript el select no se envía solo: este botón lo hace.
		printf(
			'<noscript><button type="submit" class="esc-mini">%s</button></noscript>',
			esc_html__( 'Aplicar', 'malka-crm' )
		);

		echo '</form></div>';

		return ob_get_clean();
	}

	/**
	 * Editar, ver y eliminar, al final de cada fila.
	 *
	 * Eliminar manda a la papelera, no al vacío: una cotización borrada por
	 * error se recupera, y su número no se puede reusar sin romper el
	 * correlativo.
	 *
	 * @param array $f Fila.
	 * @return string
	 */
	private static function quote_actions( $f ) {
		ob_start();
		echo '<span class="esc-tr__acc">';

		printf(
			'<a class="esc-ico" href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',
			esc_url( $f['url'] ),
			esc_attr__( 'Editar', 'malka-crm' ),
			self::icono( '<path d="M11.2 2.3l2.5 2.5L6 12.5l-3.2.7.7-3.2z"/>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		if ( ! empty( $f['publica'] ) ) {
			printf(
				'<a class="esc-ico" href="%1$s" target="_blank" rel="noopener" title="%2$s" aria-label="%2$s">%3$s</a>',
				esc_url( $f['publica'] ),
				esc_attr__( 'Ver la página del cliente', 'malka-crm' ),
				self::icono( '<path d="M1.5 8S4 3.8 8 3.8 14.5 8 14.5 8 12 12.2 8 12.2 1.5 8 1.5 8z"/><circle cx="8" cy="8" r="1.9"/>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		printf(
			'<a class="esc-ico esc-ico--x" href="%1$s" title="%2$s" aria-label="%2$s" data-confirmar="%3$s">%4$s</a>',
			esc_url( Malka_CRM_Quote_Editor::delete_url( $f['id'] ) ),
			esc_attr__( 'Eliminar', 'malka-crm' ),
			esc_attr(
				sprintf(
					/* translators: identificación de la cotización */
					__( '¿Mandar %s a la papelera? La puedes recuperar después.', 'malka-crm' ),
					$f['numero'] ? 'la N° ' . $f['numero'] : $f['titulo']
				)
			),
			self::icono( '<path d="M4 4l8 8M12 4l-8 8"/>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		echo '</span>';

		return ob_get_clean();
	}

	/**
	 * Envuelve un trazo en el SVG que usa todo el escritorio.
	 *
	 * Va aparte porque dentro de un printf con posiciones (%1$s) un %s suelto
	 * vuelve a tomar el primer argumento, y el icono sale vacío.
	 *
	 * @param string $trazo Contenido del SVG, ya escrito a mano.
	 * @return string
	 */
	private static function icono( $trazo ) {
		return '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" ' .
			'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $trazo . '</svg>';
	}

	/**
	 * Los proyectos cuyo saldo depende de que ella marque la entrega.
	 *
	 * El saldo no tiene fecha hasta que el proyecto está entregado, así que
	 * marcarlo es lo que echa a andar el plazo y, con él, la cobranza. Si ese
	 * botón no estuviera a la vista, el saldo se quedaría esperando para
	 * siempre y el panel no tendría cómo reclamarlo.
	 *
	 * @return string
	 */
	private static function deliveries_panel() {
		$pendientes = array();
		$entregados = array();

		foreach ( self::quotes_list() as $q ) {
			if ( $q['espera'] <= 0 || in_array( $q['estado'], array( 'rechazada', 'caducada' ), true ) ) {
				continue;
			}

			if ( $q['entrega'] ) {
				$entregados[] = $q;
			} else {
				$pendientes[] = $q;
			}
		}

		if ( ! $pendientes && ! $entregados ) {
			return '';
		}

		$habiles = (int) Malka_CRM_Settings::get( 'saldo_habiles', 1 );

		ob_start();
		echo '<section class="esc-panel esc-entregas">';
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Saldos por destrabar', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: días hábiles */
				_n(
					'El saldo no vence hasta que entregues. Desde ahí corre %d día hábil.',
					'El saldo no vence hasta que entregues. Desde ahí corren %d días hábiles.',
					$habiles,
					'malka-crm'
				),
				$habiles
			)
		) . '</p></div></div>';

		foreach ( array_merge( $pendientes, $entregados ) as $q ) {
			$listo = (bool) $q['entrega'];
			$vence = $listo ? Malka_CRM_Settings::sumar_habiles( $q['entrega'], $habiles ) : '';

			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'malka_crm_entrega',
						'quote'  => (int) $q['id'],
						'volver' => rawurlencode( self::view_url( 'cotizaciones' ) ),
					),
					admin_url( 'admin-post.php' )
				),
				'malka_crm_entrega_' . (int) $q['id']
			);

			printf(
				'<div class="esc-entrega%1$s">' .
				'<span class="esc-entrega__c"><b>%2$s</b><i>%3$s</i></span>' .
				'<span class="esc-entrega__m">%4$s</span>' .
				'<span class="esc-entrega__e">%5$s</span>' .
				'<a class="esc-btn%6$s" href="%7$s">%8$s</a></div>',
				$listo ? ' is-done' : '',
				esc_html( $q['cliente'] ? $q['cliente'] : $q['titulo'] ),
				esc_html( $q['numero'] ? 'N° ' . $q['numero'] : $q['titulo'] ),
				esc_html( Malka_CRM_Settings::money( $q['espera'] ) ),
				esc_html(
					$listo && $vence
						? sprintf(
							/* translators: 1: fecha de entrega, 2: fecha de vencimiento */
							__( 'Entregado el %1$s · vence el %2$s', 'malka-crm' ),
							date_i18n( 'd/m/Y', strtotime( $q['entrega'] ) ),
							date_i18n( 'd/m/Y', strtotime( $vence ) )
						)
						: __( 'Sin fecha hasta que entregues', 'malka-crm' )
				),
				$listo ? ' esc-btn--ghost' : ' esc-btn--soft',
				esc_url( $url ),
				esc_html( $listo ? __( 'Deshacer', 'malka-crm' ) : __( 'Marcar entregado', 'malka-crm' ) )
			);
		}

		echo '<p class="esc-hint">' . esc_html__( 'Al marcarlo, al cliente le llega un correo con el monto y la fecha tope. Si te equivocas, deshacer lo deja como estaba.', 'malka-crm' ) . '</p>';
		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Una cifra grande, pintada desde otro módulo.
	 *
	 * El registro de documentos usa las mismas cifras del embudo, y no vale
	 * la pena que tenga su propia copia del markup.
	 *
	 * @param string $valor Número.
	 * @param string $label Qué es.
	 * @param string $pie   Detalle.
	 * @param string $tono  '' o 'alert'.
	 */
	public static function cifra_publica( $valor, $label, $pie = '', $tono = '' ) {
		self::cifra( $valor, $label, $pie, $tono );
	}

	/**
	 * El mismo SVG del escritorio, para los módulos que pintan sus propias
	 * filas sin tener que repetir el markup.
	 *
	 * @param string $trazo Contenido del SVG.
	 * @return string
	 */
	public static function icono_publico( $trazo ) {
		return self::icono( $trazo );
	}

	/**
	 * Una cifra grande, reutilizable desde otros módulos.
	 *
	 * @param string $valor Número.
	 * @param string $label Qué es.
	 * @param string $pie   Detalle.
	 * @param string $tono  '' o 'alert'.
	 */
	private static function cifra( $valor, $label, $pie = '', $tono = '' ) {
		printf(
			'<div class="esc-cifra"><em class="%4$s">%1$s</em><b>%2$s</b>%3$s</div>',
			esc_html( $valor ),
			esc_html( $label ),
			$pie ? '<span>' . esc_html( $pie ) . '</span>' : '',
			esc_attr( $tono ? 'is-' . $tono : '' )
		);
	}

	/**
	 * El color del estado de una cotización.
	 *
	 * @param array $f Fila.
	 * @return string
	 */
	private static function quote_row_tone( $f ) {
		if ( $f['vencido'] > 0 ) {
			return 'late';
		}
		switch ( $f['estado'] ) {
			// Entregado: el trabajo salió y el saldo tiene el reloj corriendo.
			case 'entregado':
				return 'wait';
			case 'aceptada':
			case 'en_curso':
				return 'run';
			case 'finalizada':
				return 'done';
			case 'rechazada':
			case 'caducada':
				return 'off';
		}
		// Sin respuesta y ya pasada de fecha: caducó aunque no lo diga.
		return ( null !== $f['dias'] && $f['dias'] < 0 ) ? 'late' : 'wait';
	}

	/**
	 * ¿La cotización ya está cerrada a favor?
	 *
	 * Una vez que el cliente dijo que sí, la vigencia de la cotización deja
	 * de importar: la fecha sigue estando, pero que haya pasado no es un
	 * problema que haya que pintar de rojo.
	 *
	 * @param string $estado Estado comercial.
	 * @return bool
	 */
	private static function quote_won( $estado ) {
		return in_array( $estado, array( 'aceptada', 'en_curso', 'entregado', 'finalizada' ), true );
	}

	/**
	 * Qué decir en la columna de vencimiento.
	 *
	 * @param array $f Fila.
	 * @return string
	 */
	private static function quote_when( $f ) {
		if ( ! $f['vence'] ) {
			return '—';
		}

		if ( self::quote_won( $f['estado'] ) ) {
			return date_i18n( 'j M', strtotime( $f['vence'] ) );
		}

		if ( $f['dias'] < 0 ) {
			// La columna ya se llama "Vence" y va en rojo: no hace falta repetirlo.
			/* translators: días */
			return sprintf( _n( 'hace %d día', 'hace %d días', abs( $f['dias'] ), 'malka-crm' ), abs( $f['dias'] ) );
		}
		if ( 0 === $f['dias'] ) {
			return __( 'vence hoy', 'malka-crm' );
		}

		/* translators: días */
		return sprintf( _n( 'en %d día', 'en %d días', $f['dias'], 'malka-crm' ), $f['dias'] );
	}

	/**
	 * Movimientos completos dentro del escritorio.
	 *
	 * @return string
	 */
	/**
	 * La pestaña Contabilidad: el F29 de cada mes y el importador a mano.
	 *
	 * @return string
	 */
	public static function view_contabilidad() {
		return '<div class="esc-vista esc-vista--total esc-vista--cont">' . Malka_CRM_Contabilidad::render_panel( 24 ) . '</div>';
	}

	public static function view_movements() {
		$f    = self::filtros_movs();
		$cats = Malka_CRM_Finance::categories();

		ob_start();
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total esc-vista--mov">';

		/*
		 * Lo primero: cuánto puede sacar sin dejar la empresa corta. El
		 * detalle de los retiros se fue al Resumen y las cuentas a su
		 * propia pestaña; acá quedó lo que esta pantalla es, la lista de
		 * lo que entra y sale.
		 */
		/*
		 * Antes que nada, si retiró de más. Acá y no en el Inicio: es donde
		 * están los movimientos con los que se arregla. Sin enlace, porque
		 * el enlace llevaba justamente a esta pantalla.
		 */
		$aviso_devolver = Malka_CRM_Retiro::aviso_devolver();
		if ( $aviso_devolver ) {
			echo $aviso_devolver; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo Malka_CRM_Retiro::render_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<section class="esc-panel esc-panel--movs" data-movs>';
		echo self::barra_movs( $f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::cajon_filtros_movs( $f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::cajon_importar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::tabla_movs( $f, $cats ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section></div>';

		return ob_get_clean();
	}

	/**
	 * Lo que la pantalla de movimientos tiene puesto como filtro.
	 *
	 * @return array
	 */
	public static function filtros_movs() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$pide = function ( $k ) {
			return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		};

		$en = function ( $v, $lista ) {
			return in_array( $v, $lista, true ) ? $v : '';
		};

		$out = array(
			'texto'     => $pide( 'q' ),
			'tipo'      => $en( $pide( 'tipo' ), array( 'entrada', 'salida' ) ),
			'estado'    => $en( $pide( 'estado' ), array_keys( Malka_CRM_Finance::estados() ) ),
			'forma'     => $en( $pide( 'forma' ), array_keys( Malka_CRM_Finance::formas() ) ),
			'cuenta'    => '',
			'categoria' => $en( $pide( 'cat' ), array_keys( Malka_CRM_Finance::categories() ) ),
			'desde'     => '',
			'hasta'     => '',
			'limite'    => 60,
		);

		$cta = $pide( 'cta' );

		if ( '' !== $cta && Malka_CRM_Finance::cuenta_valida( (int) $cta ) === (int) $cta && (int) $cta > 0 ) {
			$out['cuenta'] = (int) $cta;
		}

		// El período usa el mismo selector que el resto de Finanzas.
		$per = self::periodo_finanzas();

		if ( ! empty( $per['desde'] ) && ! empty( $per['hasta'] ) && 'todo' !== $pide( 'periodo' ) ) {
			$out['desde'] = $per['desde'];
			$out['hasta'] = $per['hasta'];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $out;
	}

	/**
	 * ¿Hay algún filtro puesto, aparte del período?
	 *
	 * @param array $f Filtros.
	 * @return int Cuántos.
	 */
	private static function cuantos_filtros( $f ) {
		$n = 0;

		foreach ( array( 'tipo', 'estado', 'forma', 'cuenta', 'categoria' ) as $k ) {
			if ( '' !== $f[ $k ] ) {
				$n++;
			}
		}

		return $n;
	}

	/**
	 * Buscar, filtrar, importar, bajar e ingresar: todo arriba de la tabla.
	 *
	 * @param array $f Filtros puestos.
	 * @return string
	 */
	private static function barra_movs( $f ) {
		$puestos = self::cuantos_filtros( $f );

		return sprintf(
			'<div class="esc-tb">' .
			'<label class="esc-tb__b"><span class="screen-reader-text">%1$s</span>' .
			'<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><circle cx="7.2" cy="7.2" r="4.4"/><path d="m10.6 10.6 2.6 2.6"/></svg>' .
			'<input type="search" id="esc-movs-q" value="%2$s" placeholder="%1$s" autocomplete="off" /></label>' .
			'<div class="esc-tb__d">' .
			'<button type="button" class="esc-ico esc-tb__f%7$s" data-movs-filtros aria-expanded="false" title="%3$s" aria-label="%3$s">' .
			'<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M2.5 4.5h11M4.5 8h7M6.5 11.5h3"/></svg>' .
			'%8$s</button>' .
			'<button type="button" class="esc-btn" data-movs-importar>' .
			'<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v7M5 6l3 3 3-3M2.5 11v1.5a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1V11"/></svg>' .
			'%4$s</button>' .
			'<a class="esc-btn" href="%5$s" data-movs-pdf>' .
			'<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 10V2M5 7l3 3 3-3M2.5 12v1.5h11V12"/></svg>' .
			'%6$s</a>' .
			'<button type="button" class="esc-btn esc-btn--primary" data-abrir-mov>+ %9$s</button>' .
			'</div></div>',
			esc_attr__( 'Buscar movimientos', 'malka-crm' ),
			esc_attr( $f['texto'] ),
			esc_attr__( 'Filtros', 'malka-crm' ),
			esc_html__( 'Importar', 'malka-crm' ),
			esc_url( self::url_pdf_movs( $f ) ),
			esc_html__( 'Descargar PDF', 'malka-crm' ),
			$puestos ? ' is-on' : '',
			$puestos ? '<i>' . (int) $puestos . '</i>' : '',
			esc_html__( 'Nuevo movimiento', 'malka-crm' )
		);
	}

	/**
	 * La dirección que imprime la lista tal como está filtrada.
	 *
	 * @param array $f Filtros.
	 * @return string
	 */
	private static function url_pdf_movs( $f ) {
		$args = array( 'malka_pdf' => 'movimientos' );

		foreach ( array( 'texto' => 'q', 'tipo' => 'tipo', 'estado' => 'estado', 'forma' => 'forma', 'categoria' => 'cat', 'cuenta' => 'cta', 'desde' => 'desde', 'hasta' => 'hasta' ) as $k => $q ) {
			if ( '' !== $f[ $k ] ) {
				$args[ $q ] = $f[ $k ];
			}
		}

		return add_query_arg( $args, self::view_url( 'movimientos' ) );
	}

	/**
	 * El cajón de filtros, cerrado hasta que lo pidan.
	 *
	 * Son enlaces de verdad: sin javascript igual filtran, recargando.
	 *
	 * @param array $f Filtros puestos.
	 * @return string
	 */
	private static function cajon_filtros_movs( $f ) {
		$campos = array(
			'tipo'   => array(
				__( 'Tipo', 'malka-crm' ),
				__( 'Todos', 'malka-crm' ),
				array(
					'salida'  => __( 'Gasto', 'malka-crm' ),
					'entrada' => __( 'Ingreso', 'malka-crm' ),
				),
				$f['tipo'],
			),
			'estado' => array( __( 'Estado', 'malka-crm' ), __( 'Todos', 'malka-crm' ), Malka_CRM_Finance::estados(), $f['estado'] ),
			'forma'  => array( __( 'Forma', 'malka-crm' ), __( 'Todas', 'malka-crm' ), Malka_CRM_Finance::formas(), $f['forma'] ),
		);

		$cuentas = array();

		foreach ( Malka_CRM_Finance::cuentas() as $cid => $c ) {
			$cuentas[ (string) (int) $cid ] = Malka_CRM_Finance::nombre_cuenta( $cid );
		}

		$campos['cta'] = array( __( 'Cuenta', 'malka-crm' ), __( 'Todas', 'malka-crm' ), $cuentas, '' === $f['cuenta'] ? '' : (string) $f['cuenta'] );
		$campos['cat'] = array( __( 'Categoría', 'malka-crm' ), __( 'Todas', 'malka-crm' ), Malka_CRM_Finance::categories(), $f['categoria'] );

		// El período está arriba para toda Finanzas, pero acá también, porque
		// es el filtro que más se mueve en esta pantalla.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_puesto = isset( $_GET['periodo'] ) ? sanitize_key( wp_unslash( $_GET['periodo'] ) ) : 'mes';

		$campos['periodo'] = array(
			__( 'Período', 'malka-crm' ),
			__( 'Este mes', 'malka-crm' ),
			array(
				'dia'   => __( 'Hoy', 'malka-crm' ),
				'12m'   => __( 'Últimos 12 meses', 'malka-crm' ),
				'anio'  => __( 'Este año', 'malka-crm' ),
				'anios' => __( 'Todos los años', 'malka-crm' ),
				'todo'  => __( 'Sin filtrar por fecha', 'malka-crm' ),
			),
			'mes' === $per_puesto ? '' : $per_puesto,
		);

		ob_start();
		echo '<div class="esc-fcaj" data-movs-cajon hidden>';
		printf(
			'<div class="esc-fcaj__h"><b>%1$s</b><button type="button" class="esc-ico esc-fcaj__x" data-movs-cerrar aria-label="%2$s" title="%2$s">' .
			'<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg>' .
			'</button></div>',
			esc_html__( 'Filtros', 'malka-crm' ),
			esc_attr__( 'Cerrar', 'malka-crm' )
		);
		echo '<div class="esc-fcaj__g">';

		foreach ( $campos as $clave => $campo ) {
			list( $rotulo, $vacio, $opciones, $puesto ) = $campo;

			printf( '<label class="esc-fcaj__c"><span>%1$s</span><select data-movs-f="%2$s">', esc_html( $rotulo ), esc_attr( $clave ) );
			printf( '<option value="">%s</option>', esc_html( $vacio ) );

			foreach ( $opciones as $k => $v ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $k ),
					selected( (string) $k, (string) $puesto, false ),
					esc_html( $v )
				);
			}

			echo '</select></label>';
		}

		echo '</div>';
		printf(
			'<div class="esc-fcaj__p"><button type="button" class="esc-btn" data-movs-limpiar%2$s>%1$s</button>',
			esc_html__( 'Limpiar', 'malka-crm' ),
			self::cuantos_filtros( $f ) ? '' : ' disabled'
		);
		printf( '<button type="button" class="esc-btn esc-btn--primary" data-movs-listo>%s</button></div>', esc_html__( 'Listo', 'malka-crm' ) );
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * La cartola arrastrada, ahora detrás del botón «Importar».
	 *
	 * @return string
	 */
	private static function cajon_importar() {
		ob_start();
		echo '<div class="esc-imp" data-movs-imp hidden>';
		printf(
			'<div class="esc-imp__h"><b>%1$s</b><span>%2$s</span></div>',
			esc_html__( 'Importar movimientos', 'malka-crm' ),
			esc_html__( 'Arrastra la cartola y revisa qué es nuevo antes de sumarlo a tus cuentas.', 'malka-crm' )
		);

		// Con más de una cuenta, la cartola es de una en particular.
		if ( count( Malka_CRM_Finance::cuentas() ) > 1 ) {
			echo '<label class="esc-cart__cta"><span>' . esc_html__( 'Cartola de', 'malka-crm' ) . '</span><select id="esc-cart-cta">';
			foreach ( Malka_CRM_Finance::cuentas() as $cid => $c ) {
				printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $cid, selected( ! empty( $c['principal'] ), true, false ), esc_html( Malka_CRM_Finance::nombre_cuenta( $cid ) ) );
			}
			echo '</select></label>';
		}

		printf(
			'<div class="esc-cart" id="esc-cart" data-ajax="%1$s" data-nonce="%2$s">' .
			'<label class="esc-drop esc-cart__drop"><input type="file" accept=".pdf,.csv,.txt,.xls,.xlsx,.tsv,application/pdf,text/csv,text/plain,application/vnd.ms-excel" multiple hidden />' .
			'<span class="esc-drop__ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4"/><path d="m7 9 5-5 5 5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg></span>' .
			'<span class="esc-drop__t"><b>%3$s</b><span>%4$s</span></span></label>' .
			'<p class="esc-cart__msg" hidden></p><div class="esc-cart__res" hidden></div></div>',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'malka_crm_finance' ) ),
			esc_html__( 'Arrastra aquí la cartola del banco', 'malka-crm' ),
			esc_html__( 'PDF, Excel, CSV o TXT. Antes de guardar ves qué es nuevo y qué ya estaba; los pagos que ya entraron solos no se duplican.', 'malka-crm' )
		);
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * La tabla, que es lo único que cambia al buscar o filtrar.
	 *
	 * @param array $f    Filtros.
	 * @param array $cats Categorías.
	 * @return string
	 */
	public static function tabla_movs( $f, $cats = null ) {
		$cats = null === $cats ? Malka_CRM_Finance::categories() : $cats;
		$res  = Malka_CRM_Finance::buscar( $f );
		$movs = $res['filas'];

		ob_start();
		echo '<div class="esc-tmw" data-movs-tabla>';

		if ( ! $movs ) {
			printf(
				'<p class="esc-empty">%s</p>',
				esc_html(
					self::cuantos_filtros( $f ) || '' !== $f['texto']
						? __( 'Ningún movimiento calza con lo que buscas. Prueba sacando algún filtro.', 'malka-crm' )
						: __( 'Todavía no hay movimientos. Registra el primero con el botón de arriba o importa una cartola.', 'malka-crm' )
				)
			);
			echo '</div>';
			return ob_get_clean();
		}

		echo '<table class="esc-tm"><thead><tr>';
		printf(
			'<th class="esc-tm__h esc-tm__h--s"><label class="esc-tm__sel"><input type="checkbox" data-movs-todos /><span class="screen-reader-text">%s</span></label></th>',
			esc_html__( 'Seleccionar todos', 'malka-crm' )
		);
		printf( '<th class="esc-tm__h esc-tm__h--t" scope="col">%s</th>', esc_html__( 'Tipo', 'malka-crm' ) );
		printf( '<th class="esc-tm__h" scope="col">%s</th>', esc_html__( 'Descripción', 'malka-crm' ) );
		printf( '<th class="esc-tm__h esc-tm__h--op" scope="col">%s</th>', esc_html__( 'Forma', 'malka-crm' ) );
		printf( '<th class="esc-tm__h esc-tm__h--op" scope="col">%s</th>', esc_html__( 'Cuenta', 'malka-crm' ) );
		printf( '<th class="esc-tm__h esc-tm__h--op" scope="col">%s</th>', esc_html__( 'Categoría', 'malka-crm' ) );
		printf( '<th class="esc-tm__h" scope="col">%s</th>', esc_html__( 'Fecha', 'malka-crm' ) );
		printf( '<th class="esc-tm__h esc-tm__h--n" scope="col">%s</th>', esc_html__( 'Monto', 'malka-crm' ) );
		printf( '<th class="esc-tm__h esc-tm__h--a" scope="col"><span class="screen-reader-text">%s</span></th>', esc_html__( 'Acciones', 'malka-crm' ) );
		echo '</tr></thead><tbody>';

		foreach ( $movs as $m ) {
			echo self::fila_tabla_mov( $m, $cats ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</tbody></table>';

		printf(
			'<div class="esc-tm__bulk" data-movs-bulk hidden><b data-movs-cuantos>0</b>' .
			'<button type="button" class="esc-btn" data-movs-acc="hecho">%1$s</button>' .
			'<button type="button" class="esc-btn" data-movs-acc="pendiente">%2$s</button>' .
			'<button type="button" class="esc-btn esc-btn--borrar" data-movs-acc="borrar">%3$s</button>' .
			'<button type="button" class="esc-btn" data-movs-nada>%4$s</button></div>',
			esc_html__( 'Marcar completadas', 'malka-crm' ),
			esc_html__( 'Marcar pendientes', 'malka-crm' ),
			esc_html__( 'Borrar', 'malka-crm' ),
			esc_html__( 'Quitar selección', 'malka-crm' )
		);

		printf(
			'<p class="esc-tm__pie">%s</p>',
			esc_html(
				$res['total'] > count( $movs )
					? sprintf(
						/* translators: 1: mostrados, 2: total */
						__( 'Mostrando %1$d de %2$d movimientos.', 'malka-crm' ),
						count( $movs ),
						$res['total']
					)
					: sprintf(
						/* translators: cantidad */
						_n( '%d movimiento.', '%d movimientos.', $res['total'], 'malka-crm' ),
						$res['total']
					)
			)
		);

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Una fila de la tabla de movimientos.
	 *
	 * @param object $m    Movimiento.
	 * @param array  $cats Categorías.
	 * @return string
	 */
	private static function fila_tabla_mov( $m, $cats ) {
		$in  = 'entrada' === $m->tipo;
		$cat = isset( $cats[ $m->categoria ] ) && 'sin_categoria' !== $m->categoria ? $cats[ $m->categoria ] : '';
		$est = isset( $m->estado ) && $m->estado ? (string) $m->estado : 'hecho';

		list( $forma_k, $forma_t ) = Malka_CRM_Finance::forma( $m );

		$datos = wp_json_encode(
			array(
				'id'          => (int) $m->id,
				'descripcion' => $m->descripcion,
				'referencia'  => isset( $m->referencia ) ? $m->referencia : '',
				'monto'       => (int) $m->monto,
				'tipo'        => $m->tipo,
				'categoria'   => $m->categoria,
				'fecha'       => $m->fecha,
				'recurrente'  => (int) $m->recurrente,
				'cuenta'      => Malka_CRM_Finance::cuenta_valida( isset( $m->cuenta ) ? (int) $m->cuenta : 0 ),
				'estado'      => $est,
				'cuota_n'     => isset( $m->cuota_n ) ? (int) $m->cuota_n : 0,
				'cuota_total' => isset( $m->cuota_total ) ? (int) $m->cuota_total : 0,
				'moneda'      => isset( $m->moneda ) && $m->moneda ? (string) $m->moneda : 'CLP',
				'original'    => isset( $m->original ) ? (float) $m->original : 0,
			)
		);

		$flecha = $in
			? '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.5 4.5l-7 7M4.5 6v5.5H10"/></svg>'
			: '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 11.5l7-7M6 4.5h5.5V10"/></svg>';

		// El dibujito de la forma: una capa, dos capas o la cuota.
		$ic_forma = 'puntual' === $forma_k
			? '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true"><path d="M8 2.5 14 6l-6 3.5L2 6l6-3.5Z"/></svg>'
			: '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true"><path d="M8 2 14 5.2 8 8.4 2 5.2 8 2Z"/><path d="m2 8.6 6 3.2 6-3.2"/></svg>';

		$ctas = Malka_CRM_Finance::cuentas();
		$cid  = isset( $m->cuenta ) ? (int) $m->cuenta : 0;
		$tipo_cta = isset( $ctas[ $cid ]['tipo'] ) ? (string) $ctas[ $cid ]['tipo'] : 'corriente';

		// Lo pagado en otra moneda se muestra tal como salió, bajo los pesos.
		$foraneo = isset( $m->moneda ) && $m->moneda && 'CLP' !== $m->moneda && ! empty( $m->original )
			? '<i class="esc-tm__mo">' . esc_html( $m->moneda . ' ' . number_format_i18n( (float) $m->original, 2 ) ) . '</i>'
			: '';

		return sprintf(
			'<tr class="esc-tm__f%12$s" data-mov="%1$s" data-id="%18$d" tabindex="0" role="button">' .
			'<td class="esc-tm__s"><label class="esc-tm__sel"><input type="checkbox" class="esc-tm__ck" value="%18$d" />' .
			'<span class="screen-reader-text">%19$s</span></label></td>' .
			'<td class="esc-tm__t"><span class="esc-tm__fl%2$s">%3$s</span></td>' .
			'<td class="esc-tm__d"><span class="esc-tm__av" style="--esc-av:%4$s" aria-hidden="true">%5$s</span>' .
			'<span class="esc-tm__dt"><b>%6$s</b>%13$s</span></td>' .
			'<td class="esc-tm__fo esc-tm__op">%14$s<span>%7$s</span></td>' .
			'<td class="esc-tm__c esc-tm__op">%15$s<span>%8$s</span></td>' .
			'<td class="esc-tm__cat esc-tm__op">%9$s</td>' .
			'<td class="esc-tm__fe">%10$s</td>' .
			'<td class="esc-tm__m%2$s">%11$s%16$s</td>' .
			'<td class="esc-tm__ac"><button type="button" class="esc-ico esc-tm__mas" data-movs-menu aria-label="%17$s" title="%17$s">' .
			'<svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true"><circle cx="8" cy="3.2" r="1.3"/><circle cx="8" cy="8" r="1.3"/><circle cx="8" cy="12.8" r="1.3"/></svg>' .
			'</button></td></tr>',
			esc_attr( $datos ),
			$in ? ' is-in' : '',
			$flecha, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( self::tono_inicial( $m->descripcion ) ),
			esc_html( self::inicial_de( $m->descripcion ) ),
			esc_html( $m->descripcion ),
			esc_html( $forma_t ),
			esc_html( Malka_CRM_Finance::nombre_cuenta( $cid ) ),
			$cat ? '<span class="esc-tm__pill">' . esc_html( $cat ) . '</span>' : '<span class="esc-tm__nada">—</span>',
			esc_html( date_i18n( 'd M', strtotime( $m->fecha ) ) ),
			esc_html( ( $in ? '+ ' : '− ' ) . Malka_CRM_Settings::money( $m->monto ) ),
			'pendiente' === $est ? ' is-pend' : '',
			'pendiente' === $est
				? '<i class="esc-tm__pend">' . esc_html__( 'Pendiente', 'malka-crm' ) . '</i>'
				: ( ! empty( $m->referencia ) ? '<i>' . esc_html( $m->referencia ) . '</i>' : '' ),
			$ic_forma, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::icono_cuenta( $tipo_cta ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$foraneo, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr__( 'Acciones', 'malka-crm' ),
			(int) $m->id,
			esc_html(
				sprintf(
					/* translators: descripción del movimiento */
					__( 'Seleccionar %s', 'malka-crm' ),
					$m->descripcion
				)
			)
		);
	}

	/**
	 * La letra que va en el redondelito de cada movimiento.
	 *
	 * @param string $texto Descripción.
	 * @return string
	 */
	private static function inicial_de( $texto ) {
		$texto = trim( wp_strip_all_tags( (string) $texto ) );

		if ( '' === $texto ) {
			return '·';
		}

		return function_exists( 'mb_strtoupper' )
			? mb_strtoupper( mb_substr( $texto, 0, 1 ), 'UTF-8' )
			: strtoupper( substr( $texto, 0, 1 ) );
	}

	/**
	 * Un color estable para ese redondelito.
	 *
	 * Sale del nombre, así que el mismo gasto es siempre del mismo color y
	 * la lista se lee de un vistazo sin que nadie elija nada.
	 *
	 * @param string $texto Descripción.
	 * @return string Color CSS.
	 */
	private static function tono_inicial( $texto ) {
		$suma = 0;
		$t    = strtolower( trim( (string) $texto ) );

		for ( $i = 0; $i < strlen( $t ); $i++ ) {
			$suma = ( $suma * 31 + ord( $t[ $i ] ) ) % 360;
		}

		return 'hsl(' . (int) $suma . ' 58% 62%)';
	}

	/**
	 * Contesta la tabla ya filtrada, sin recargar la pantalla.
	 */
	public static function ajax_movs() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}

		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( array( 'q', 'tipo', 'estado', 'forma', 'cat', 'cta', 'periodo', 'mes', 'anio', 'dia' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$_GET[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			} else {
				unset( $_GET[ $k ] );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$f = self::filtros_movs();

		wp_send_json_success(
			array(
				'html'    => self::tabla_movs( $f ),
				'cuantos' => self::cuantos_filtros( $f ),
			)
		);
	}

	/**
	 * Horas registradas dentro del escritorio.
	 *
	 * @return string
	 */
	/**
	 * Las cuentas bancarias de la empresa, cada una con su saldo editable.
	 *
	 * @return string
	 */
	/**
	 * El dibujito de cada cuenta, según su tipo.
	 *
	 * @param string $tipo corriente|vista|ahorro|prepago|credito.
	 * @return string SVG.
	 */
	private static function icono_cuenta( $tipo ) {
		$trazos = array(
			// Un banco con columnas.
			'corriente' => '<path d="M2 6.5L8 3l6 3.5M3 6.5v6M13 6.5v6M6 7.5v4M10 7.5v4M2.2 13.2h11.6"/>',
			// Una billetera.
			'vista'     => '<rect x="2" y="4" width="12" height="9" rx="1.8"/><path d="M2 7h12"/><circle cx="11" cy="10" r=".9"/>',
			// Un chanchito.
			'ahorro'    => '<path d="M13.5 8.6a4.5 4.5 0 01-4.5 4.4H6a4 4 0 01-4-4 4 4 0 014-4h3a4.5 4.5 0 014.5 3.6z"/><path d="M4.5 13v1M11 12.6v1.4M13.6 7.4l1.2-.7v2"/><circle cx="10.6" cy="8" r=".7"/>',
			// Una tarjeta con banda.
			'prepago'   => '<rect x="1.8" y="3.6" width="12.4" height="8.8" rx="1.6"/><path d="M1.8 6.9h12.4M4.4 10h2.6"/>',
			// La misma tarjeta, con el signo de que cuesta.
			'credito'   => '<rect x="1.8" y="3.6" width="12.4" height="8.8" rx="1.6"/><path d="M1.8 6.9h12.4M4.4 10h2.6M11.6 9.1v2M10.6 10.1h2"/>',
		);

		$d = isset( $trazos[ $tipo ] ) ? $trazos[ $tipo ] : $trazos['corriente'];

		return '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
	}

	/**
	 * El formulario para editar una cuenta, plegado dentro de su fila.
	 *
	 * El saldo no está acá: ése se escribe directo en la fila. Acá va lo que
	 * no cambia todos los días —banco, nombre, tipo, de quién es— y que hasta
	 * ahora no se podía corregir sin borrar la cuenta y crearla de nuevo.
	 *
	 * @param int   $id    Id de la cuenta.
	 * @param array $c     La cuenta.
	 * @param array $tipos Los tipos disponibles.
	 * @return string
	 */
	private static function editor_cuenta( $id, $c, $tipos ) {
		$bancos = '';
		$suyo   = (string) $c['banco'];
		$lista  = Malka_CRM_Finance::bancos();

		// Si su banco no está en la lista de siempre, igual tiene que aparecer.
		if ( $suyo && ! in_array( $suyo, $lista, true ) ) {
			array_unshift( $lista, $suyo );
		}

		foreach ( $lista as $b ) {
			$bancos .= sprintf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $b ),
				selected( $b, $suyo, false )
			);
		}

		$opts = '';

		foreach ( $tipos as $k => $t ) {
			$opts .= sprintf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( $k ),
				esc_html( $t ),
				selected( $k, isset( $c['tipo'] ) ? $c['tipo'] : 'corriente', false )
			);
		}

		ob_start();
		printf(
			/*
			 * Todos los huecos van numerados. Mezclar «%1$s» con «%s» en el
			 * mismo printf hace que PHP lleve dos contadores a la vez y los
			 * argumentos salgan corridos.
			 */
			'<details class="esc-cta__ed"><summary title="%1$s">' .
			'<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' .
			'<path d="M11.4 2.6a1.7 1.7 0 012.4 2.4L5.6 13.2l-3.1.7.7-3.1z"/></svg>' .
			'<span class="screen-reader-text">%1$s</span></summary>' .
			'<div class="esc-cta__pop">' .
			'<form class="esc-cta__f esc-cta__ef" data-cuenta-editar="%2$d">' .
			'<label><span>%3$s</span><input type="text" name="alias" value="%4$s" /></label>' .
			'<label><span>%5$s</span><select name="banco" data-buscable>%6$s</select></label>' .
			'<label><span>%7$s</span><select name="tipo">%8$s</select></label>' .
			'<label><span>%9$s</span><input type="text" name="numero" value="%10$s" placeholder="%11$s" /></label>' .
			'<label class="esc-chk"><input type="checkbox" name="personal" value="1"%12$s /> %13$s</label>' .
			'<label class="esc-chk"><input type="checkbox" name="principal" value="1"%14$s%15$s /> %16$s</label>' .
			'<button type="submit" class="esc-btn esc-btn--primary">%17$s</button>' .
			'</form>%18$s</div></details>',
			esc_html__( 'Editar esta cuenta', 'malka-crm' ),
			(int) $id,
			esc_html__( 'Cómo la llamas', 'malka-crm' ),
			esc_attr( (string) $c['alias'] ),
			esc_html__( 'Banco', 'malka-crm' ),
			$bancos, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Tipo', 'malka-crm' ),
			$opts, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Últimos dígitos', 'malka-crm' ),
			esc_attr( (string) $c['numero'] ),
			esc_attr__( 'Opcional', 'malka-crm' ),
			checked( ! empty( $c['personal'] ), true, false ),
			esc_html__( 'Es mía, no del estudio', 'malka-crm' ),
			checked( ! empty( $c['principal'] ), true, false ),
			! empty( $c['personal'] ) ? ' disabled' : '',
			esc_html__( 'Que sea la principal del estudio', 'malka-crm' ),
			esc_html__( 'Guardar cambios', 'malka-crm' ),
			// Si es tarjeta de crédito, sus condiciones van acá mismo: cupo,
			// mínimo y tasa son de esta tarjeta y se corrigen mirándola.
			class_exists( 'Malka_CRM_Deudas' ) ? Malka_CRM_Deudas::campos_tarjeta( (int) $id ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		return ob_get_clean();
	}

	/**
	 * Lo que tienes que pagar, de todas partes, en un solo lugar.
	 *
	 * Junta tres cosas que hasta ahora vivían en pantallas distintas: los
	 * servicios que pagas todos los meses, el vencimiento de cada tarjeta y lo
	 * que le debes a una persona. Lo vencido va primero porque es lo que
	 * cuesta plata dejarlo pasar.
	 *
	 * @return string
	 */
	/** Cuántos días hacia adelante mira «Lo que tienes que pagar». */
	const PAGAR_DIAS = 14;

	/**
	 * Lo que tienes que pagar, de todas partes, en un solo lugar.
	 *
	 * Junta tres cosas que hasta ahora vivían en pantallas distintas: los
	 * servicios que pagas todos los meses, el vencimiento de cada tarjeta y
	 * lo que le debes a una persona. Lo vencido va primero porque es lo que
	 * cuesta plata dejarlo pasar.
	 *
	 * Dos semanas y no un mes: a treinta días el mismo costo sale dos veces
	 * —el de este mes sin pagar y el del siguiente— y lo que se lee como
	 * «esto tengo que pagar» se convierte en un pronóstico.
	 *
	 * Lo que te deben no está acá a propósito: esto es plata que sale.
	 *
	 * @return string
	 */
	public static function panel_pagar() {
		$filas = array();

		// Los servicios y costos del mes, con su casilla para marcarlos.
		if ( class_exists( 'Malka_CRM_Pagos' ) ) {
			foreach ( Malka_CRM_Pagos::proximos( self::PAGAR_DIAS ) as $p ) {
				/*
				 * Sin monto y sin monto original no hay nada que pagar: el
				 * F29 de un mes sin IVA salía como «$0, atrasado 1 día», que
				 * asusta y no es cierto. Un costo en dólares sí se queda
				 * aunque su conversión a pesos dé cero —todavía no hay tipo
				 * de cambio—: ahí se muestra en su moneda.
				 */
				if ( (int) $p['monto'] <= 0 && (float) $p['original'] <= 0 ) {
					continue;
				}

				$filas[] = array(
					'fecha'    => isset( $p['fecha'] ) ? (string) $p['fecha'] : '',
					'dias'     => isset( $p['dias'] ) ? (int) $p['dias'] : 0,
					'que'      => isset( $p['nombre'] ) ? (string) $p['nombre'] : '',
					'monto'    => (int) $p['monto'],
					'rotulo'   => 'impuesto' === $p['tipo'] ? __( 'SII', 'malka-crm' ) : __( 'mensual', 'malka-crm' ),
					'donde'    => self::view_url( 'contabilidad' ),
					'mes'      => isset( $p['mes'] ) ? (string) $p['mes'] : '',
					'clave'    => isset( $p['clave'] ) ? (string) $p['clave'] : '',
					'variable' => ! empty( $p['variable'] ),
					// Lo que dice el servicio, cuando no está en pesos.
					'cuanto'   => ( (int) $p['monto'] <= 0 && 'CLP' !== $p['moneda'] )
						? $p['moneda'] . ' ' . number_format_i18n( (float) $p['original'], 2 )
						: '',
				);
			}
		}

		// Las tarjetas de crédito.
		if ( class_exists( 'Malka_CRM_Deudas' ) ) {
			foreach ( Malka_CRM_Deudas::proximos( self::PAGAR_DIAS ) as $d ) {
				$filas[] = array(
					'fecha'  => (string) $d['vence'],
					'dias'   => (int) $d['dias'],
					'que'    => (string) $d['nombre'],
					'monto'  => (int) $d['cuota'] > 0 ? (int) $d['cuota'] : (int) $d['saldo'],
					'rotulo' => __( 'tarjeta', 'malka-crm' ),
					'donde'  => self::view_url( 'deudas' ),
				);
			}
		}

		// Y lo que le debes a alguien.
		if ( class_exists( 'Malka_CRM_Prestamos' ) ) {
			foreach ( Malka_CRM_Prestamos::proximos( self::PAGAR_DIAS ) as $p ) {
				if ( 'debo' !== $p['lado'] ) {
					continue;
				}

				$filas[] = array(
					// La cuota que toca ahora, no el final del préstamo:
					// «dias» ya está calculado sobre ella.
					'fecha'  => (string) $p['proxima'],
					'dias'   => (int) $p['dias'],
					'que'    => (string) $p['quien'],
					'monto'  => $p['cuota'] > 0 ? min( $p['cuota'], $p['pendiente'] ) : (int) $p['pendiente'],
					'rotulo' => __( 'persona', 'malka-crm' ),
					'donde'  => self::view_url( 'deudas' ),
				);
			}
		}

		usort(
			$filas,
			function ( $a, $b ) {
				if ( $a['dias'] === $b['dias'] ) {
					return 0;
				}
				return $a['dias'] < $b['dias'] ? -1 : 1;
			}
		);

		$vencidas = 0;
		$total    = 0;

		foreach ( $filas as $f ) {
			$total += (int) $f['monto'];

			if ( $f['dias'] < 0 ) {
				$vencidas++;
			}
		}

		ob_start();
		echo '<section class="esc-panel esc-pagar">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'peso',
			__( 'Lo que tienes que pagar', 'malka-crm' ),
			sprintf( /* translators: días */ __( 'En los próximos %d días', 'malka-crm' ), self::PAGAR_DIAS ),
			$total ? Malka_CRM_Settings::money( $total ) : '',
			$vencidas ? sprintf( /* translators: cuántos */ _n( '%d vencido', '%d vencidos', $vencidas, 'malka-crm' ), $vencidas ) : ''
		);

		if ( ! $filas ) {
			echo '<p class="esc-empty">' . esc_html__( 'Nada por pagar en estas dos semanas. Las tarjetas aparecen acá cuando les pongas su día de vencimiento en Deudas.', 'malka-crm' ) . '</p>';
			echo '</section>';
			return ob_get_clean();
		}

		echo '<ul class="esc-pagar__l">';

		foreach ( $filas as $f ) {
			list( $cuando, $tono ) = Malka_CRM_Pagos::cuando( (int) $f['dias'] );

			/*
			 * Sólo los costos del mes se marcan desde acá: una tarjeta o una
			 * deuda con alguien pide el monto y de qué cuenta salió, y eso se
			 * hace en Deudas. La casilla es la misma de «Tu día», así que el
			 * mismo javascript la atiende.
			 */
			$marca = isset( $f['clave'] )
				? sprintf(
					'<button type="button" class="esc-dia__chk" data-tipo="pago" data-mes="%1$s" data-clave="%2$s" data-monto="%3$d" data-fecha="%4$s"%5$s aria-label="%6$s" title="%6$s"></button>',
					esc_attr( $f['mes'] ),
					esc_attr( $f['clave'] ),
					(int) $f['monto'],
					esc_attr( min( $f['fecha'], current_time( 'Y-m-d' ) ) ),
					$f['variable'] ? ' data-variable="1"' : '',
					esc_attr__( 'Marcar como pagado', 'malka-crm' )
				)
				: '<span class="esc-dia__sin" aria-hidden="true"></span>';

			printf(
				'<li class="esc-pagar__i esc-dia-w%1$s">%2$s<a class="esc-pagar__a" href="%3$s">' .
				'<b class="esc-pagar__q">%4$s</b>' .
				'<b class="esc-pagar__m">%5$s%6$s</b>' .
				'<span class="esc-pagar__d"><em>%7$s · %8$s</em>' .
				'<span class="esc-st esc-st--%9$s">%10$s</span></span>' .
				'</a></li>',
				$f['dias'] < 0 ? ' is-vencido' : '',
				$marca, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_url( $f['donde'] ),
				esc_html( $f['que'] ),
				empty( $f['variable'] ) || ! empty( $f['cuanto'] ) ? '' : '≈ ',
				esc_html( empty( $f['cuanto'] ) ? Malka_CRM_Settings::money( (int) $f['monto'] ) : $f['cuanto'] ),
				esc_html( $f['fecha'] ? date_i18n( 'j M', strtotime( $f['fecha'] ) ) : '—' ),
				esc_html( $f['rotulo'] ),
				esc_attr( $tono ),
				esc_html( $cuando )
			);
		}

		echo '</ul>';
		echo '</section>';
		return ob_get_clean();
	}

	/**
	 * La pestaña «Cuentas»: dónde está tu plata y cuánto debes.
	 *
	 * El cuadro de cuentas vivía dentro de Movimientos, entre los retiros
	 * y la lista: había que bajar para ver cuánto tenías. Acá tiene su
	 * pestaña, y Movimientos quedó siendo lo que su nombre dice.
	 *
	 * @return string
	 */
	public static function view_cuentas() {
		ob_start();
		/*
		 * Una sola columna de ancho completo. La fila de una cuenta es una
		 * cuadrícula de cuatro columnas; metida en media pantalla el nombre
		 * se queda sin espacio y sale una letra por línea.
		 */
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total">';

		echo self::panel_cuentas(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/*
		 * Lo que el estudio te debe por lo que pagaste con tu plata: es
		 * parte de cuánto tienes, aunque todavía no esté en ninguna cuenta.
		 */
		if ( class_exists( 'Malka_CRM_Reembolsos' ) ) {
			echo Malka_CRM_Reembolsos::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Y lo que debes, resumido, con el camino a su pantalla.
		if ( class_exists( 'Malka_CRM_Deudas' ) ) {
			$t    = Malka_CRM_Deudas::totales();
			$debo = 0;

			if ( class_exists( 'Malka_CRM_Prestamos' ) ) {
				foreach ( Malka_CRM_Prestamos::lista( 'debo' ) as $d ) {
					$debo += (int) $d['pendiente'];
				}
			}

			echo '<section class="esc-panel">';
			echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'peso',
				__( 'Lo que debes', 'malka-crm' ),
				__( 'Tarjetas y créditos, más lo que le debes a alguien', 'malka-crm' ),
				Malka_CRM_Settings::money( (int) $t['debe'] + $debo ),
				''
			);

			echo '<div class="esc-fin__band">';
			self::$fin_enlaces = array(
				__( 'En tarjetas y créditos', 'malka-crm' ) => self::view_url( 'deudas' ),
				__( 'A personas', 'malka-crm' )             => self::view_url( 'deudas' ),
				__( 'Interés al mes', 'malka-crm' )         => self::view_url( 'deudas' ),
			);
			self::franja(
				__( 'En tarjetas y créditos', 'malka-crm' ),
				Malka_CRM_Settings::money( (int) $t['debe'] ),
				sprintf( /* translators: cuántas */ _n( '%d deuda', '%d deudas', (int) $t['cuantas'], 'malka-crm' ), (int) $t['cuantas'] ),
				$t['debe'] > 0 ? 'late' : 'ok'
			);
			self::franja(
				__( 'A personas', 'malka-crm' ),
				Malka_CRM_Settings::money( $debo ),
				$debo > 0 ? esc_html__( 'Lo que le debes a alguien', 'malka-crm' ) : esc_html__( 'No le debes a nadie', 'malka-crm' ),
				''
			);
			self::franja(
				__( 'Interés al mes', 'malka-crm' ),
				Malka_CRM_Settings::money( (int) $t['interes'] ),
				sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::view_url( 'deudas' ) ), esc_html__( 'Ver y pagar →', 'malka-crm' ) ),
				$t['interes'] > 0 ? 'late' : '',
				true
			);
			echo '</div></section>';
		}

		echo '</div>';

		return ob_get_clean();
	}

	public static function panel_cuentas() {
		$cuentas = Malka_CRM_Finance::cuentas();
		$tipos   = Malka_CRM_Finance::tipos_cuenta();

		/*
		 * Veintiuna cuentas en una lista plana no se leen: hay que bajar
		 * buscando. Agrupadas por bolsillo —lo del estudio, lo tuyo, lo
		 * guardado y lo que debes— cada grupo contesta una pregunta distinta y
		 * su subtotal es, casi siempre, lo único que venías a mirar.
		 */
		$grupos = array(
			'estudio' => array(
				'rotulo'  => __( 'Del estudio', 'malka-crm' ),
				'nota'    => __( 'La plata de la empresa', 'malka-crm' ),
				'cuentas' => array(),
			),
			'tuyas'   => array(
				'rotulo'  => __( 'Tuyas', 'malka-crm' ),
				'nota'    => __( 'Lo que usas en el día a día', 'malka-crm' ),
				'cuentas' => array(),
			),
			'credito' => array(
				'rotulo'  => __( 'Tarjetas de crédito', 'malka-crm' ),
				'nota'    => __( 'Acá el saldo es lo que debes, no lo que tienes', 'malka-crm' ),
				'cuentas' => array(),
				'deuda'   => true,
			),
			'ahorro'  => array(
				'rotulo'  => __( 'Ahorros', 'malka-crm' ),
				'nota'    => __( 'Lo que tienes guardado', 'malka-crm' ),
				'cuentas' => array(),
			),
		);

		foreach ( $cuentas as $id => $c ) {
			$t = isset( $c['tipo'] ) ? $c['tipo'] : 'corriente';

			if ( 'credito' === $t ) {
				$g = 'credito';
			} elseif ( 'ahorro' === $t ) {
				$g = 'ahorro';
			} elseif ( ! empty( $c['personal'] ) ) {
				$g = 'tuyas';
			} else {
				$g = 'estudio';
			}

			$grupos[ $g ]['cuentas'][ $id ] = $c;
		}

		// Mirando sólo un lado, el otro no se dibuja.
		$ambito = Malka_CRM_Finance::ambito();

		if ( 'estudio' === $ambito ) {
			$grupos = array_intersect_key( $grupos, array( 'estudio' => 1 ) );
		} elseif ( 'personal' === $ambito ) {
			unset( $grupos['estudio'] );
		}

		ob_start();
		echo '<section class="esc-panel esc-cta" id="esc-cuentas">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'peso',
			__( 'Tus cuentas', 'malka-crm' ),
			__( 'El saldo se edita escribiendo encima; el lápiz cambia el resto', 'malka-crm' )
		);

		echo '<div class="esc-cta__g">';

		foreach ( $grupos as $clave => $g ) {
			if ( ! $g['cuentas'] ) {
				continue;
			}

			$suma = 0;

			foreach ( $g['cuentas'] as $c ) {
				$suma += (int) $c['saldo'];
			}

			/*
			 * Los ahorros vienen plegados cuando son muchos: son varias
			 * cuentas con montos chicos y lo que importa es el total. Los
			 * demás grupos se abren, porque ahí sí se entra a corregir.
			 */
			$plegar = ( 'ahorro' === $clave && count( $g['cuentas'] ) > 3 );

			printf(
				'<%1$s class="esc-cta__gr%2$s"%3$s><%4$s class="esc-cta__gh">' .
				'<span class="esc-cta__gt"><b>%5$s</b><small>%6$s</small></span>' .
				'<span class="esc-cta__gs"><b>%7$s</b><small>%8$s</small></span>' .
				'</%4$s><div class="esc-cta__l">',
				$plegar ? 'details' : 'div',
				! empty( $g['deuda'] ) ? ' esc-cta__gr--deuda' : '',
				$plegar ? '' : '',
				$plegar ? 'summary' : 'div',
				esc_html( $g['rotulo'] ),
				esc_html( $g['nota'] ),
				esc_html( Malka_CRM_Settings::money( $suma ) ),
				esc_html(
					sprintf(
						/* translators: cuántas cuentas */
						_n( '%d cuenta', '%d cuentas', count( $g['cuentas'] ), 'malka-crm' ),
						count( $g['cuentas'] )
					)
				)
			);

			foreach ( $g['cuentas'] as $id => $c ) {
				echo self::fila_cuenta( (int) $id, $c, $tipos, count( $cuentas ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			printf( '</div></%s>', $plegar ? 'details' : 'div' );
		}

		echo '</div>';

		/*
		 * Antes acá decía «Total del estudio» al pie de una lista donde casi
		 * todo es suyo, y el número no calzaba con nada de lo que se veía
		 * arriba. Las tres cifras que importan son: cuánta plata hay, cuánta
		 * se debe, y qué queda.
		 */
		$tiene = 0;
		$debe  = 0;

		foreach ( $grupos as $clave => $g ) {
			foreach ( $g['cuentas'] as $c ) {
				if ( 'credito' === $clave ) {
					$debe += max( 0, (int) $c['saldo'] );
				} else {
					$tiene += (int) $c['saldo'];
				}
			}
		}

		printf(
			'<div class="esc-cta__tot"><span class="esc-cta__t1"><small>%1$s</small><b id="esc-cta-total">%2$s</b></span>' .
			'<span class="esc-cta__t1"><small>%3$s</small><b class="is-mal">%4$s</b></span>' .
			'<span class="esc-cta__t1 esc-cta__t1--neto"><small>%5$s</small><b>%6$s</b></span></div>',
			esc_html__( 'Tienes', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $tiene ) ),
			esc_html__( 'Debes en tarjetas', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $debe ) ),
			esc_html__( 'Neto', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $tiene - $debe ) )
		);

		// Agregar otra cuenta: un formulario chico, plegado.
		echo '<details class="esc-cta__nueva"><summary>+ ' . esc_html__( 'Agregar cuenta', 'malka-crm' ) . '</summary><form class="esc-cta__f" id="esc-cta-form">';
		echo '<label><span>' . esc_html__( 'Banco', 'malka-crm' ) . '</span><select name="banco" data-buscable>';
		foreach ( Malka_CRM_Finance::bancos() as $b ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $b ) );
		}
		echo '</select></label>';
		echo '<label><span>' . esc_html__( 'Tipo', 'malka-crm' ) . '</span><select name="tipo">';
		foreach ( $tipos as $k => $t ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $t ) );
		}
		echo '</select></label>';
		printf( '<label><span>%1$s</span><input type="text" name="numero" placeholder="%2$s" /></label>', esc_html__( 'Número', 'malka-crm' ), esc_attr__( 'Opcional', 'malka-crm' ) );
		printf( '<label><span>%1$s</span><input type="text" name="saldo" inputmode="numeric" placeholder="$0" /></label>', esc_html__( 'Saldo de hoy', 'malka-crm' ) );
		printf( '<label class="esc-chk"><input type="checkbox" name="personal" value="1" /> %s</label>', esc_html__( 'Es mía, no del estudio (su plata no cuenta como de la empresa)', 'malka-crm' ) );
		printf( '<label class="esc-chk"><input type="checkbox" name="principal" value="1" /> %s</label>', esc_html__( 'Que sea la principal (ahí entran los pagos de cotizaciones)', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Agregar', 'malka-crm' ) );
		echo '</form></details>';

		echo '</section>';
		return ob_get_clean();
	}

	/**
	 * ¿El nombre de la cuenta ya dice de qué banco es?
	 *
	 * Comparar los textos enteros no sirve: «Cuenta corriente Falabella» no
	 * contiene «Banco Falabella» y terminaba repitiendo el banco al lado. Se
	 * comparan las palabras que distinguen, sin «banco», «de» ni tildes.
	 *
	 * @param string $alias Nombre que le puso.
	 * @param string $banco Nombre del banco.
	 * @return bool
	 */
	private static function nombra_al_banco( $alias, $banco ) {
		if ( ! $alias || ! $banco ) {
			return false;
		}

		$limpia = function ( $t ) {
			$t = function_exists( 'remove_accents' ) ? remove_accents( (string) $t ) : (string) $t;
			return preg_replace( '/[^a-z0-9 ]/', '', strtolower( $t ) );
		};

		$suyo  = ' ' . $limpia( $alias ) . ' ';
		$vacio = array( 'banco', 'bco', 'de', 'del', 'la', 'el', 'y' );
		$falta = 0;

		foreach ( preg_split( '/\s+/', trim( $limpia( $banco ) ) ) as $palabra ) {
			if ( '' === $palabra || in_array( $palabra, $vacio, true ) ) {
				continue;
			}

			if ( false === strpos( $suyo, $palabra ) ) {
				$falta++;
			}
		}

		// Si no faltó ninguna palabra con peso, el nombre ya lo dice.
		return 0 === $falta;
	}

	/**
	 * Una cuenta en la lista.
	 *
	 * Dos líneas como mucho: el nombre y, debajo, sólo lo que el nombre no
	 * dice ya. Si la cuenta se llama «Cuenta corriente Falabella», repetir
	 * «Banco Falabella · Cuenta corriente» no agrega nada y sí ocupa una línea.
	 *
	 * @param int   $id      Id.
	 * @param array $c       La cuenta.
	 * @param array $tipos   Tipos disponibles.
	 * @param int   $cuantas Cuántas cuentas hay en total.
	 * @return string
	 */
	private static function fila_cuenta( $id, $c, $tipos, $cuantas ) {
		$tipo  = isset( $c['tipo'] ) ? $c['tipo'] : 'corriente';
		$alias = trim( (string) $c['alias'] );
		$banco = trim( (string) $c['banco'] );
		$nombre = $alias ? $alias : $banco;
		$dig   = preg_replace( '/\D/', '', (string) $c['numero'] );
		$meta  = array();

		// El banco, sólo si el nombre no lo nombra ya.
		if ( $banco && ! self::nombra_al_banco( $alias, $banco ) ) {
			$meta[] = $banco;
		}

		/*
		 * El tipo sólo cuando cambia algo. Una corriente es lo que uno supone
		 * por defecto, y ahorro y crédito ya los dice el grupo; lo que vale la
		 * pena marcar es una vista o una prepago, que se comportan distinto.
		 */
		if ( in_array( $tipo, array( 'vista', 'prepago' ), true ) ) {
			/*
			 * En el selector el tipo se explica largo —«Tarjeta de prepago o
			 * débito (Mach, Tenpo…)»— porque ahí hay que elegir. En la lista
			 * eso se come la línea entera: basta la palabra.
			 */
			$corto  = array(
				'vista'   => __( 'Cuenta vista', 'malka-crm' ),
				'prepago' => __( 'Prepago', 'malka-crm' ),
			);
			$meta[] = $corto[ $tipo ];
		}

		if ( $dig ) {
			$meta[] = '··' . substr( $dig, -4 );
		}

		// Al lado del nombre, este sello empujaba el título a una línea más.
		if ( ! empty( $c['principal'] ) && $cuantas > 1 ) {
			$meta[] = __( 'principal del estudio', 'malka-crm' );
		}

		ob_start();
		printf(
			'<div class="esc-cta__i%1$s" data-cuenta="%2$d">' .
			'<span class="esc-cta__ic" aria-hidden="true">%3$s</span>' .
			'<span class="esc-cta__n"><b>%4$s</b>%6$s</span>%5$s' .
			'<span class="esc-cta__s"><i>$</i><input type="text" inputmode="numeric" value="%7$s" data-cuenta-saldo aria-label="%8$s" /></span>' .
			'%9$s' .
			'<button type="button" class="esc-cta__del" data-cuenta-borrar title="%10$s" aria-label="%10$s"%11$s><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.6 8.5h5.8l.6-8.5"/></svg></button>' .
			'%12$s' .
			'</div>',
			! empty( $c['personal'] ) ? ' is-mia' : '',
			(int) $id,
			self::icono_cuenta( $tipo ),
			esc_html( $nombre ),
			'',
			$meta ? '<small>' . esc_html( implode( ' · ', $meta ) ) . '</small>' : '',
			esc_attr( number_format( (int) $c['saldo'], 0, ',', '.' ) ),
			esc_attr( sprintf( /* translators: cuenta */ __( 'Saldo de %s', 'malka-crm' ), $nombre ) ),
			self::editor_cuenta( (int) $id, $c, $tipos ),
			esc_attr__( 'Quitar cuenta', 'malka-crm' ),
			$cuantas < 2 ? ' hidden' : '',
			'credito' === $tipo ? self::tarjeta_detalle( (int) $id, $nombre ) : ''
		);

		return ob_get_clean();
	}

	/**
	 * Lo que una tarjeta de crédito necesita decir además del saldo.
	 *
	 * Cuánto cupo ocupaste, cuándo vence y cuánto toca poner este mes, con un
	 * botón para anotar el pago sin salir de acá. El pago pregunta de qué
	 * cuenta salió porque son dos movimientos: sale de una y baja la deuda de
	 * la otra. Sin eso, el saldo de la tarjeta nunca calzaría con el banco.
	 *
	 * @param int    $id     Id de la cuenta.
	 * @param string $nombre Cómo se llama.
	 * @return string
	 */
	private static function tarjeta_detalle( $id, $nombre ) {
		if ( ! class_exists( 'Malka_CRM_Deudas' ) ) {
			return '';
		}

		$t = Malka_CRM_Deudas::estado_tarjeta( $id );

		if ( ! $t ) {
			return '';
		}

		ob_start();
		echo '<div class="esc-tj">';

		if ( $t['cupo'] > 0 ) {
			printf(
				'<div class="esc-tj__cupo%1$s"><span class="esc-tj__bar"><i style="width:%2$d%%"></i></span>' .
				'<span class="esc-tj__cifra">%3$s</span></div>',
				$t['pasada'] ? ' is-pasada' : '',
				min( 100, (int) $t['usado'] ),
				esc_html(
					$t['pasada']
						? sprintf(
							/* translators: 1: usado, 2: cupo */
							__( 'Usas %1$s de %2$s: estás por sobre el cupo', 'malka-crm' ),
							Malka_CRM_Settings::money( $t['debe'] ),
							Malka_CRM_Settings::money( $t['cupo'] )
						)
						: sprintf(
							/* translators: 1: porcentaje, 2: cupo, 3: libre */
							__( '%1$d%% de %2$s · te quedan %3$s', 'malka-crm' ),
							(int) $t['usado'],
							Malka_CRM_Settings::money( $t['cupo'] ),
							Malka_CRM_Settings::money( $t['libre'] )
						)
				)
			);
		}

		$linea = array();

		if ( $t['vence'] ) {
			list( $cuando, $tono ) = Malka_CRM_Pagos::cuando( (int) $t['dias'] );
			$linea[] = sprintf(
				'<span class="esc-tj__v">%1$s <span class="esc-st esc-st--%2$s">%3$s</span></span>',
				esc_html( sprintf( /* translators: fecha */ __( 'Vence el %s', 'malka-crm' ), date_i18n( 'j \d\e M', strtotime( $t['vence'] ) ) ) ),
				esc_attr( $tono ),
				esc_html( $cuando )
			);
		}

		if ( 'minimo' === $t['de_donde'] ) {
			$linea[] = '<span>' . esc_html( sprintf( /* translators: monto */ __( 'Pago mínimo %s', 'malka-crm' ), Malka_CRM_Settings::money( $t['pagar'] ) ) ) . '</span>';
		} elseif ( 'interes' === $t['de_donde'] ) {
			$linea[] = '<span>' . esc_html( sprintf( /* translators: monto */ __( 'Al menos %s, o la deuda sube', 'malka-crm' ), Malka_CRM_Settings::money( $t['pagar'] ) ) ) . '</span>';
		}

		if ( $t['interes'] > 0 && 'minimo' === $t['de_donde'] ) {
			$linea[] = '<span>' . esc_html( sprintf( /* translators: monto */ __( 'interés del mes %s', 'malka-crm' ), Malka_CRM_Settings::money( $t['interes'] ) ) ) . '</span>';
		}

		/*
		 * Lo que falta se escribe acá mismo: el botón abre el detalle de
		 * esta cuenta, que es donde viven sus condiciones. Antes mandaba a
		 * Deudas, a un panel con todas las tarjetas juntas.
		 */
		if ( 'falta' === $t['de_donde'] || ! $t['vence'] || ! $t['cupo'] ) {
			$linea[] = sprintf(
				'<button type="button" class="esc-tj__falta" data-cta-abrir>%s</button>',
				esc_html__( 'faltan el cupo, la tasa o el vencimiento', 'malka-crm' )
			);
		}

		if ( $linea ) {
			echo '<p class="esc-tj__l">' . implode( ' · ', $linea ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo self::form_pagar_tarjeta( $id, $nombre, $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Anotar el pago de una tarjeta sin salir de la lista.
	 *
	 * @param int    $id     Id de la cuenta.
	 * @param string $nombre Cómo se llama.
	 * @param array  $t      Su estado.
	 * @return string
	 */
	private static function form_pagar_tarjeta( $id, $nombre, $t ) {
		$opciones = '';

		foreach ( Malka_CRM_Finance::cuentas() as $cid => $c ) {
			// De una tarjeta no sale plata para pagar otra: sale cupo.
			if ( Malka_CRM_Finance::es_credito( (int) $cid ) ) {
				continue;
			}

			$opciones .= sprintf(
				'<option value="%1$d">%2$s</option>',
				(int) $cid,
				esc_html( Malka_CRM_Finance::nombre_cuenta( (int) $cid ) )
			);
		}

		if ( ! $opciones || $t['debe'] <= 0 ) {
			return '';
		}

		$hoy      = current_time( 'Y-m-d' );
		$sugerido = $t['pagar'] > 0 ? min( $t['pagar'], $t['debe'] ) : $t['debe'];

		ob_start();
		printf(
			'<details class="esc-tj__p"><summary>%1$s</summary>' .
			'<form method="post" action="%2$s" class="esc-cta__f esc-tj__f">%3$s' .
			'<input type="hidden" name="action" value="malka_crm_deuda_pagar" />' .
			'<input type="hidden" name="clave" value="t%4$d" />' .
			'<input type="hidden" name="desde" value="movimientos" />' .
			'<label><span>%5$s</span><input type="text" name="monto" inputmode="numeric" value="%6$s" /></label>' .
			'<label><span>%7$s</span><select name="origen">%8$s</select></label>' .
			'<label><span>%9$s</span><input type="date" name="fecha" value="%10$s" max="%10$s" /></label>' .
			'<button type="submit" class="esc-btn esc-btn--primary">%11$s</button>' .
			'</form><p class="esc-hint">%12$s</p></details>',
			esc_html__( 'Marcar como pagada', 'malka-crm' ),
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_deuda_pagar', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(int) $id,
			esc_html__( 'Cuánto pagaste', 'malka-crm' ),
			esc_attr( number_format( $sugerido, 0, ',', '.' ) ),
			esc_html__( 'Con qué cuenta la pagaste', 'malka-crm' ),
			$opciones, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Cuándo', 'malka-crm' ),
			esc_attr( $hoy ),
			esc_html__( 'Anotar el pago', 'malka-crm' ),
			esc_html(
				sprintf(
					/* translators: tarjeta */
					__( 'Sale de la cuenta que elijas y baja lo que debes en %s. Quedan los dos movimientos anotados.', 'malka-crm' ),
					$nombre
				)
			)
		);

		return ob_get_clean();
	}

	public static function view_hours() {
		$hoy    = Malka_CRM_Time::minutes_today();
		$semana = Malka_CRM_Time::minutes_this_week();
		$mes    = Malka_CRM_Time::minutes_this_month();

		ob_start();
		echo '<div class="esc-vista esc-vista--horas">';

		echo '<section class="esc-panel">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'reloj',
			__( 'Lo que llevas trabajado', 'malka-crm' ),
			__( 'Se registra con el pomodoro de la barra lateral', 'malka-crm' ),
			Malka_CRM_Time::format_minutes( $mes ),
			__( 'este mes', 'malka-crm' )
		);
		echo '<div class="esc-kv">';
		self::kv( __( 'Hoy', 'malka-crm' ), Malka_CRM_Time::format_minutes( $hoy ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::kv( __( 'Esta semana', 'malka-crm' ), Malka_CRM_Time::format_minutes( $semana ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::kv( __( 'Este mes', 'malka-crm' ), Malka_CRM_Time::format_minutes( $mes ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></section>';

		echo self::panel_pro_bono(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		return ob_get_clean();
	}

	/* ─────────────────────────────  Cotizaciones  ─────────────────────── */

	/**
	 * Todas las cotizaciones con sus números ya calculados.
	 *
	 * @return array[]
	 */
	public static function quotes_list() {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		$estados = Malka_CRM_Quotes::quote_statuses();
		$out     = array();

		foreach ( $ids as $qid ) {
			$pagos   = Malka_CRM_Quotes::get_payments( $qid );
			$total   = (float) get_post_meta( $qid, '_malka_pm_quote_total', true );
			$estado  = (string) get_post_meta( $qid, '_malka_pm_quote_status', true );
			$pagado  = 0;
			$vencido = 0;
			$proxima = null;
			$espera  = 0;

			foreach ( $pagos as $p ) {
				if ( Malka_CRM_Quotes::es_resumen( $qid, $p ) ) {
					continue;
				}
				$e = Malka_CRM_Quote_Page::payment_view( $qid, $p );

				if ( 'paid' === $e['state'] ) {
					$pagado += (float) $p['amount'];
					continue;
				}

				if ( 'overdue' === $e['state'] ) {
					$vencido += (float) $p['amount'];
				}

				// Lo que depende de la entrega, esté entregado o no: es lo
				// que el panel ofrece destrabar.
				if ( isset( $p['disparo'] ) && 'entrega' === $p['disparo'] ) {
					$espera += (float) $p['amount'];
				}

				if ( ! empty( $e['espera'] ) ) {
					continue;
				}

				// La fecha que de verdad corre, no la que quedó escrita en la
				// cuota: el saldo recién la tiene cuando se entrega.
				if ( ! empty( $e['fecha'] ) && ( null === $proxima || $e['fecha'] < $proxima['due'] ) ) {
					$proxima = array(
						'due'   => $e['fecha'],
						'label' => $e['label'],
						'dias'  => (int) $e['days'],
					);
				}
			}

			// Sin total escrito, la suma de las cuotas es el total.
			if ( $total <= 0 ) {
				foreach ( $pagos as $p ) {
					if ( Malka_CRM_Quotes::es_resumen( $qid, $p ) ) {
						continue;
					}
					$total += (float) $p['amount'];
				}
			}

			$cliente = (string) get_post_meta( $qid, '_malka_pm_client_name', true );
			if ( '' === $cliente ) {
				$uid     = (int) get_post_meta( $qid, '_malka_pm_client_id', true );
				$u       = $uid ? get_userdata( $uid ) : false;
				$cliente = $u ? $u->display_name : get_the_title( $qid );
			}

			$out[] = array(
				'id'       => (int) $qid,
				'numero'   => (string) get_post_meta( $qid, '_malka_pm_quote_number', true ),
				'cliente'  => $cliente,
				'titulo'   => get_the_title( $qid ),
				'total'    => (int) round( $total ),
				'pagado'   => (int) round( $pagado ),
				'vencido'  => (int) round( $vencido ),
				'saldo'    => (int) round( $total - $pagado ),
				'pct'      => $total > 0 ? (int) round( ( $pagado / $total ) * 100 ) : 0,
				'estado'   => $estado,
				'etiqueta' => isset( $estados[ $estado ] ) ? $estados[ $estado ] : __( 'Sin estado', 'malka-crm' ),
				'cuotas'   => count( $pagos ),
				'proxima'  => $proxima,
				'entrega'  => (string) get_post_meta( $qid, Malka_CRM_Quote_Page::META_ENTREGA, true ),
				'espera'   => (int) round( $espera ),
				'emitida'  => (string) get_post_meta( $qid, '_malka_pm_quote_issued', true ),
				'vence'    => (string) get_post_meta( $qid, '_malka_pm_quote_expires', true ),
				'url'      => Malka_CRM_Quote_Editor::edit_url( $qid ),
				'publica'  => Malka_CRM_Quotes::get_url( $qid ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( (string) $b['numero'], (string) $a['numero'] );
			}
		);

		return $out;
	}

	/**
	 * El color del estado comercial de una cotización.
	 *
	 * @param string $estado Clave de estado.
	 * @return string
	 */
	private static function quote_tone( $estado ) {
		switch ( $estado ) {
			case 'aceptada':
			case 'finalizada':
				return 'done';
			case 'entregado':
				return 'wait';
			case 'en_curso':
				return 'run';
			case 'sin_respuesta':
				return 'wait';
			case 'rechazada':
			case 'caducada':
				return 'off';
		}
		return 'off';
	}

	/**
	 * Cada cuota que todavía no entra, con su mora y su mensaje listo.
	 *
	 * @return array[]
	 */
	public static function collections_list() {
		$out = array();

		foreach ( self::quotes_list() as $q ) {
			foreach ( Malka_CRM_Quotes::get_payments( $q['id'] ) as $p ) {
				if ( Malka_CRM_Quotes::es_resumen( $q['id'], $p ) ) {
					continue;
				}
				$e = Malka_CRM_Quote_Page::payment_view( $q['id'], $p );

				// Un saldo que todavía espera la entrega no es cobranza: no
				// hay nada atrasado ni que recordarle al cliente. Y una dada
				// por perdida tampoco: vive en Pérdidas.
				if ( 'paid' === $e['state'] || ! empty( $e['espera'] ) || ! empty( $e['perdida'] ) ) {
					continue;
				}

				$dias    = (int) $e['days'];
				$vencido = ( 'overdue' === $e['state'] );
				$monto   = (int) round( (float) $p['amount'] );
				$mora    = $vencido ? self::late_fee( $monto, abs( $dias ) ) : 0;

				$out[] = array(
					'quote_id' => $q['id'],
					'cuota_id' => isset( $p['id'] ) ? (string) $p['id'] : '',
					'prog'     => isset( $p['id'] ) ? Malka_CRM_Reminders::programado( $q['id'], (string) $p['id'] ) : '',
					'numero'   => $q['numero'] ? 'N° ' . $q['numero'] : '#' . $q['id'],
					'cliente'  => $q['cliente'],
					'cuota'    => isset( $p['label'] ) ? $p['label'] : '',
					'monto'    => $monto,
					'mora'     => $mora,
					'total'    => $monto + $mora,
					'due'      => isset( $e['fecha'] ) ? $e['fecha'] : '',
					'dias'     => $dias,
					'vencido'  => $vencido,
					'estado'   => $e['label'],
					'avisado'  => ! empty( $e['avisado'] ),
					'clase'    => $vencido ? 'late' : ( 'due_soon' === $e['state'] ? 'wait' : 'run' ),
					'pay_url'  => isset( $p['pay_url'] ) ? $p['pay_url'] : '',
					'url'      => $q['url'],
				);
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $a['dias'] <=> $b['dias'];
			}
		);

		return $out;
	}

	/**
	 * El acuse de lo que acaba de pasar con un aviso.
	 *
	 * @return string
	 */
	private static function aviso_resultado() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['malka_crm_msg'] ) ? sanitize_key( wp_unslash( $_GET['malka_crm_msg'] ) ) : '';

		if ( ! $msg ) {
			return '';
		}

		$por = isset( $_GET['malka_crm_por'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['malka_crm_por'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$textos = array(
			'aviso_ok' => array( 'ok', __( 'Aviso enviado al cliente.', 'malka-crm' ) ),
			'aviso_no' => array( 'no', $por ? $por : __( 'No se pudo mandar el aviso.', 'malka-crm' ) ),
			'prog_ok'  => array( 'ok', __( 'Aviso programado. Sale solo ese día.', 'malka-crm' ) ),
			'prog_off' => array( 'ok', __( 'Aviso sin fecha: ya no sale solo.', 'malka-crm' ) ),
			'perdida_ok' => array( 'ok', __( 'Dada por perdida. Sale de Cobranza y los avisos automáticos se callan; el cliente sigue viendo su deuda igual.', 'malka-crm' ) ),
			'perdida_no' => array( 'ok', __( 'Vuelve a Cobranza, con su fecha y su interés como estaban.', 'malka-crm' ) ),
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

	/**
	 * Los dos botones de aviso de una cuota: mandarlo ya, o dejarlo con fecha.
	 *
	 * Si hay algo que lo impide de verdad —sin correo, ya pagada, o un saldo
	 * que todavía espera la entrega— no se dibujan los botones: se dice por
	 * qué. Un botón que al apretarlo contesta «no se puede» es peor que no
	 * tenerlo.
	 *
	 * @param array $c Fila de cobranza.
	 * @return string
	 */
	private static function aviso_acciones( $c, $partes = false ) {
		$qid   = (int) $c['quote_id'];
		$cuota = (string) $c['cuota_id'];
		$vacio = array(
			'avisar'    => '',
			'programar' => '',
			'prog_on'   => '',
		);

		if ( ! $cuota ) {
			return $partes ? $vacio : '';
		}

		$motivo = Malka_CRM_Reminders::impedimento( $qid, $cuota );

		if ( $motivo ) {
			$no = '<span class="esc-cob__no">' . esc_html( $motivo ) . '</span>';
			return $partes ? array_merge( $vacio, array( 'avisar' => $no ) ) : $no;
		}

		$volver = self::view_url( 'cobranza' );
		$prog   = (string) $c['prog'];

		$avisar = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'malka_crm_avisar',
					'quote'  => $qid,
					'cuota'  => rawurlencode( $cuota ),
					'volver' => rawurlencode( $volver ),
				),
				admin_url( 'admin-post.php' )
			),
			'malka_crm_avisar_' . $qid . '_' . $cuota
		);

		if ( $partes ) {
			return array(
				'avisar'    => sprintf( '<a class="esc-btn esc-btn--soft" href="%1$s">%2$s</a>', esc_url( $avisar ), esc_html__( 'Avisar', 'malka-crm' ) ),
				'programar' => sprintf(
					'<form class="esc-prog" method="post" action="%1$s">%2$s' .
					'<input type="hidden" name="action" value="malka_crm_programar" />' .
					'<input type="hidden" name="quote" value="%3$d" />' .
					'<input type="hidden" name="cuota" value="%4$s" />' .
					'<input type="hidden" name="volver" value="%5$s" />' .
					'<span>%8$s</span><input type="date" name="fecha" value="%6$s" min="%7$s" aria-label="%8$s" />' .
					'<button type="submit" class="esc-btn esc-btn--soft">%9$s</button></form>',
					esc_url( admin_url( 'admin-post.php' ) ),
					wp_nonce_field( 'malka_crm_programar_' . $qid . '_' . $cuota, '_wpnonce', true, false ),
					$qid,
					esc_attr( $cuota ),
					esc_attr( $volver ),
					esc_attr( $prog ),
					esc_attr( current_time( 'Y-m-d' ) ),
					esc_attr__( 'Programar el aviso para el', 'malka-crm' ),
					esc_html( $prog ? __( 'Cambiar', 'malka-crm' ) : __( 'Programar', 'malka-crm' ) )
				),
				'prog_on'   => $prog
					? ' <span class="esc-st esc-st--off">' . esc_html( sprintf( /* translators: fecha */ __( 'aviso el %s', 'malka-crm' ), date_i18n( 'j M', strtotime( $prog ) ) ) ) . '</span>'
					: '',
			);
		}

		$out = sprintf(
			'<a class="esc-mini esc-mini--av" href="%1$s">%2$s</a>',
			esc_url( $avisar ),
			esc_html__( 'Avisar', 'malka-crm' )
		);

		// El «programar» es un formulario porque lleva una fecha dentro.
		$out .= sprintf(
			'<form class="esc-prog" method="post" action="%1$s">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_programar" />' .
			'<input type="hidden" name="quote" value="%3$d" />' .
			'<input type="hidden" name="cuota" value="%4$s" />' .
			'<input type="hidden" name="volver" value="%5$s" />' .
			'<input type="date" name="fecha" value="%6$s" min="%7$s" aria-label="%8$s" />' .
			'<button type="submit" class="esc-mini">%9$s</button>' .
			'%10$s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_programar_' . $qid . '_' . $cuota, '_wpnonce', true, false ),
			$qid,
			esc_attr( $cuota ),
			esc_attr( $volver ),
			esc_attr( $prog ),
			esc_attr( current_time( 'Y-m-d' ) ),
			esc_attr__( 'Fecha del aviso', 'malka-crm' ),
			esc_html( $prog ? __( 'Cambiar', 'malka-crm' ) : __( 'Programar', 'malka-crm' ) ),
			$prog
				? '<span class="esc-prog__on">' . esc_html( sprintf( /* translators: fecha */ __( 'sale el %s', 'malka-crm' ), date_i18n( 'j M', strtotime( $prog ) ) ) ) . '</span>'
				: ''
		);

		return $out;
	}

	/**
	 * El texto para pegarle al cliente, según si la cuota está vencida o no.
	 *
	 * @param array $c Fila de cobranza.
	 * @return string
	 */
	public static function collection_message( $c ) {
		return self::mensaje_cobro( $c )['cuerpo'];
	}

	/**
	 * El mensaje de cobro de una cuota, asunto y cuerpo.
	 *
	 * El texto sale de la plantilla editable; acá sólo se arman los campos.
	 *
	 * @param array $c Fila de cobranza.
	 * @return array {asunto, cuerpo}
	 */
	public static function mensaje_cobro( $c ) {
		if ( ! class_exists( 'Malka_CRM_Messages' ) ) {
			return array(
				'asunto' => '',
				'cuerpo' => '',
			);
		}

		$cuota = $c['cuota'] ? $c['cuota'] : __( 'la cuota', 'malka-crm' );

		$datos = array(
			'cliente' => $c['cliente'],
			'cuota'   => $cuota,
			'numero'  => $c['numero'],
			'monto'   => Malka_CRM_Settings::money( (int) $c['monto'] ),
			'total'   => Malka_CRM_Settings::money( (int) $c['total'] ),
			'dias'    => (string) abs( (int) $c['dias'] ),
			'interes_monto' => '',
			'enlace'  => $c['pay_url'] ? $c['pay_url'] : $c['url'],
			'estudio' => Malka_CRM_Messages::estudio(),
		);

		if ( $c['vencido'] ) {
			$datos['fecha']  = $c['due'] ? date_i18n( 'j \\d\\e F', strtotime( $c['due'] ) ) : __( 'la fecha acordada', 'malka-crm' );
			$datos['atraso'] = Malka_CRM_Messages::dias( $c['dias'] );

			// La frase la escribe ella en Mensajes; acá sólo va el monto.
			if ( $c['mora'] > 0 ) {
				$datos['interes_monto'] = Malka_CRM_Settings::money( (int) $c['mora'] );
			}

			return Malka_CRM_Messages::armar( 'cobro_vencido', $datos );
		}

		$datos['fecha']  = $c['due'] ? date_i18n( 'j \\d\\e F', strtotime( $c['due'] ) ) : __( 'la fecha acordada', 'malka-crm' );
		$datos['faltan'] = Malka_CRM_Messages::dias( $c['dias'] );

		return Malka_CRM_Messages::armar( 'cobro_proximo', $datos );
	}


	/**
	 * Las vistas que viven dentro de Finanzas.
	 *
	 * @return string[]
	 */
	/**
	 * Estudio: los negocios.
	 *
	 * Antes era un índice de seis fichas que llevaban a Negocios, Tareas,
	 * Cotizaciones, Clientes, Proveedores y Horas. Las cuatro primeras ya
	 * cuelgan de Estudio en el menú de la izquierda, Tareas tiene su propia
	 * entrada y Horas es una pestaña dentro de Tareas: eran dos caminos a lo
	 * mismo, y el de arriba además abría cada sección en un marco más
	 * angosto que la sección entera.
	 *
	 * Así que Estudio dejó de ser un índice y pasó a ser lo que uno viene a
	 * mirar cuando entra: en qué anda cada negocio.
	 *
	 * @return string
	 */
	public static function view_estudio() {
		return Malka_CRM_Projects::render_list();
	}

	/**
	 * El cajón donde se abre una sección sin salir de la página.
	 *
	 * Adentro va la misma vista de siempre, en modo «sólo contenido»
	 * (?embed=1), dentro de un marco. Se hace así y no pegando el HTML
	 * porque cada sección trae su propio JavaScript —buscadores, casillas,
	 * arrastrar— y pegado quedaría mudo. Dentro del marco todo funciona.
	 *
	 * @return string
	 */
	public static function panel_inline() {
		ob_start();
		echo '<div class="esc-inline" id="esc-inline" hidden>';
		printf(
			'<div class="esc-inline__bar"><b class="esc-inline__t"></b>' .
			'<a class="esc-inline__full" href="#">%1$s</a>' .
			'<button type="button" class="esc-inline__x" aria-label="%2$s">%2$s</button></div>',
			esc_html__( 'Abrir completo', 'malka-crm' ),
			esc_html__( 'Cerrar', 'malka-crm' )
		);
		printf(
			'<div class="esc-inline__cargando">%s</div><iframe class="esc-inline__f" title="%s"></iframe>',
			esc_html__( 'Cargando…', 'malka-crm' ),
			esc_attr__( 'Contenido de la sección', 'malka-crm' )
		);
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Bienestar: lo que sostiene el trabajo y no es trabajo.
	 *
	 * Por ahora es la estructura, sin contenido: lo que hay es el lugar
	 * donde va a vivir cada cosa, dicho en una frase y sin prometer de
	 * más. Se llena en la siguiente entrega.
	 *
	 * @param string $vista Cuál de las solapas.
	 * @return string
	 */
	public static function view_bienestar( $vista = 'bienestar' ) {
		$areas = array(
			'cuerpo'   => array(
				'cuerpo',
				__( 'Cuerpo', 'malka-crm' ),
				__( 'Exámenes, controles y cómo te estás moviendo', 'malka-crm' ),
				__( 'Acá van las fechas: el próximo control, el examen que toca repetir, los días que te moviste. Fechas y recordatorios, nunca resultados médicos.', 'malka-crm' ),
			),
			'mente'    => array(
				'mente',
				__( 'Mente', 'malka-crm' ),
				__( 'Terapia, ánimo y descanso', 'malka-crm' ),
				__( 'La sesión agendada, cómo viene la semana y cuánto estás durmiendo.', 'malka-crm' ),
			),
			'vinculos' => array(
				'vinculos',
				__( 'Vínculos', 'malka-crm' ),
				__( 'La gente que importa y cuándo fue la última vez', 'malka-crm' ),
				__( 'Cumpleaños, llamadas pendientes y a quién no ves hace rato. Es el que más se cae solo cuando el trabajo aprieta.', 'malka-crm' ),
			),
			'vos'      => array(
				'vos',
				__( 'Vos', 'malka-crm' ),
				__( 'Lo que haces porque te hace bien', 'malka-crm' ),
				__( 'Lectura, autocuidado, lo que sea que te devuelva algo. Sin metas ni rachas: sólo registro.', 'malka-crm' ),
			),
		);

		ob_start();
		echo '<div class="esc-vista">';

		if ( 'bienestar' === $vista ) {
			echo '<section class="esc-panel">';
			echo '<div class="esc-atajos">';
			foreach ( $areas as $k => $a ) {
				printf(
					'<a class="esc-atajo" href="%1$s" data-inline="%5$s" aria-controls="esc-inline" aria-expanded="false">%2$s<span class="esc-atajo__t">%3$s</span><span class="esc-atajo__d">%4$s</span></a>',
					esc_url( self::view_url( $k ) ),
					self::icon( $a[0] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $a[1] ),
					esc_html( $a[2] ),
					esc_attr( $a[1] )
				);
			}
			echo '</div>';
			echo self::panel_inline(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p class="esc-hint">' . esc_html__( 'Todavía está vacío: por ahora es el lugar, no el contenido.', 'malka-crm' ) . '</p>';
			echo '</section></div>';
			return ob_get_clean();
		}

		$a = isset( $areas[ $vista ] ) ? $areas[ $vista ] : $areas['cuerpo'];

		echo '<section class="esc-panel">';
		echo self::head( $a[0], $a[1], $a[2] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="esc-empty">' . esc_html( $a[3] ) . '</p>';
		echo '</section></div>';

		return ob_get_clean();
	}

	/**
	 * Finanzas · Personal: tu plata, separada de la del estudio.
	 *
	 * No es una pantalla nueva: junta lo que ya existe —tus cuentas y lo
	 * que el estudio te debe— y lo saca de Movimientos, donde estaba
	 * mezclado con la contabilidad de la empresa.
	 *
	 * @return string
	 */
	public static function view_personal() {
		$tuyas = class_exists( 'Malka_CRM_Finance' ) ? Malka_CRM_Finance::cuentas_de( true ) : array();

		ob_start();
		echo '<div class="esc-vista">';

		if ( ! $tuyas ) {
			echo '<section class="esc-panel">';
			echo self::head( 'personal', __( 'Tu plata', 'malka-crm' ), __( 'Separada de la del estudio', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no hay ninguna cuenta tuya. Puedes cargarlas todas de una vez —ya están escritas, sólo las revisas— o agregarlas de a una en Movimientos marcando «Es mía».', 'malka-crm' ) . '</p>';
			printf(
				'<a class="esc-btn esc-btn--primary" href="%1$s">%2$s</a> <a class="esc-btn" href="%3$s">%4$s</a>',
				esc_url( self::view_url( 'cargar' ) ),
				esc_html__( 'Cargar mis cuentas', 'malka-crm' ),
				esc_url( self::view_url( 'movimientos' ) ),
				esc_html__( 'Ir a Movimientos', 'malka-crm' )
			);
			echo '</section></div>';
			return ob_get_clean();
		}

		echo '<section class="esc-panel">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'personal',
			__( 'Tu plata', 'malka-crm' ),
			__( 'Separada de la del estudio', 'malka-crm' ),
			Malka_CRM_Settings::money( Malka_CRM_Finance::balance_personal() )
		);
		echo '<p class="esc-hint">' . esc_html__( 'Esto no entra en el saldo de la empresa ni en lo que puedes retirar.', 'malka-crm' ) . '</p>';
		if ( class_exists( 'Malka_CRM_Cargar' ) && ! Malka_CRM_Cargar::hecho() ) {
			printf(
				'<a class="esc-btn" href="%1$s">%2$s</a>',
				esc_url( self::view_url( 'cargar' ) ),
				esc_html__( 'Cargar el resto de mis cuentas', 'malka-crm' )
			);
		}
		echo '</section>';

		if ( class_exists( 'Malka_CRM_Reembolsos' ) ) {
			echo Malka_CRM_Reembolsos::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Finanzas · Crecer: dónde pusiste plata para que rinda.
	 *
	 * Vacía por ahora, y con una advertencia que no es decorativa: antes
	 * de invertir va el colchón. Se construye en la siguiente entrega.
	 *
	 * @return string
	 */
	public static function view_crecer() {
		$saldo = class_exists( 'Malka_CRM_Finance' ) ? Malka_CRM_Finance::balance() : 0;
		$costos = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::monthly_costs() : array( 'monto' => 0 );
		$mes    = isset( $costos['monto'] ) ? (int) $costos['monto'] : 0;
		$meses = $mes > 0 ? $saldo / $mes : 0;

		ob_start();
		echo '<div class="esc-vista"><section class="esc-panel">';
		echo self::head( 'crecer', __( 'Crecer', 'malka-crm' ), __( 'Dónde pusiste plata para que rinda', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $mes > 0 ) {
			printf(
				'<p class="esc-empty">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: meses, 2: saldo */
						__( 'Antes de invertir: con %2$s en la cuenta cubres %1$s de tus costos fijos. Lo sano son tres meses; recién de ahí para arriba conviene inmovilizar plata.', 'malka-crm' ),
						sprintf( /* translators: cantidad de meses */ _n( '%s mes', '%s meses', (int) floor( $meses ), 'malka-crm' ), number_format_i18n( $meses, 1 ) ),
						Malka_CRM_Settings::money( $saldo )
					)
				)
			);
		}

		echo '<p class="esc-hint">' . esc_html__( 'Acá va cada proyecto: cuánto pusiste, cuándo, cuánto vale hoy y cuánto renta al mes. Todavía no está construido.', 'malka-crm' ) . '</p>';
		echo '</section></div>';
		return ob_get_clean();
	}

	public static function vistas_finanzas() {
		return array(
			'finanzas', 'cobranza', 'perdidas', 'documentos', 'movimientos', 'cuentas',
			'contabilidad', 'personal', 'deudas', 'crecer',
			// Lo que se paga todos los meses sin que nadie lo pida: es plata
			// que sale, no trabajo del estudio.
			'costos-fijos', 'suscripciones',
		);
	}

	/**
	 * Una fila de pestañas que son enlaces a vistas.
	 *
	 * @param string $vista  Vista activa.
	 * @param array  $tabs   vista => rótulo.
	 * @param string $nombre Para lectores de pantalla.
	 * @return string
	 */
	private static function tabs_simples( $vista, $tabs, $nombre ) {
		$out = '<div class="esc-fin__bar"><nav class="esc-nl__tabs esc-fin__tabs" aria-label="' . esc_attr( $nombre ) . '">';
		foreach ( $tabs as $k => $rot ) {
			$out .= sprintf(
				'<a class="esc-nl__tab%1$s" href="%2$s"%3$s>%4$s</a>',
				$vista === $k ? ' is-on' : '',
				esc_url( self::view_url( $k ) ),
				$vista === $k ? ' aria-current="page"' : '',
				esc_html( $rot )
			);
		}
		return $out . '</nav></div>';
	}

	/**
	 * Las pestañas de Negocios: los negocios y las plantillas de etapas.
	 *
	 * @param string $vista Vista activa.
	 * @return string
	 */
	private static function tabs_negocios( $vista ) {
		$n    = class_exists( 'Malka_CRM_Templates' ) ? count( Malka_CRM_Templates::get_templates() ) : 0;
		$tabs = array(
			'negocios'   => array( __( 'Negocios', 'malka-crm' ), 0 ),
			'plantillas' => array( __( 'Plantillas', 'malka-crm' ), $n ),
		);

		$out = '<div class="esc-fin__bar"><nav class="esc-nl__tabs esc-fin__tabs" aria-label="' . esc_attr__( 'Negocios', 'malka-crm' ) . '">';
		foreach ( $tabs as $k => $t ) {
			$out .= sprintf(
				'<a class="esc-nl__tab%1$s" href="%2$s"%3$s>%4$s%5$s</a>',
				$vista === $k ? ' is-on' : '',
				esc_url( self::view_url( $k ) ),
				$vista === $k ? ' aria-current="page"' : '',
				esc_html( $t[0] ),
				$t[1] ? ' <i>' . (int) $t[1] . '</i>' : ''
			);
		}
		return $out . '</nav></div>';
	}

	/**
	 * Las vistas que viven bajo Configuración.
	 *
	 * @return string[]
	 */
	public static function vistas_config() {
		return array( 'configuracion', 'mensajes', 'textos' );
	}

	/**
	 * Las pestañas de Configuración.
	 *
	 * Las cuatro primeras son partes del mismo formulario y se cambian sin
	 * recargar (#estudio, #marca…). Correos y Textos son sus propias
	 * pantallas, pero se ven y se usan igual.
	 *
	 * @param string $vista Vista activa.
	 * @return string
	 */
	private static function tabs_config( $vista ) {
		$cfg  = self::view_url( 'configuracion' );
		$tabs = array(
			array( 'estudio', __( 'Estudio', 'malka-crm' ), $cfg . '#estudio' ),
			array( 'marca', __( 'Marca', 'malka-crm' ), $cfg . '#marca' ),
			array( 'cobros', __( 'Cobros', 'malka-crm' ), $cfg . '#cobros' ),
			array( 'tarifa', __( 'Tarifa y costos', 'malka-crm' ), $cfg . '#tarifa' ),
			array( 'mensajes', __( 'Correos', 'malka-crm' ), self::view_url( 'mensajes' ) ),
			array( 'textos', __( 'Textos de la cotización', 'malka-crm' ), self::view_url( 'textos' ) ),
		);

		$out = '<div class="esc-fin__bar"><nav class="esc-nl__tabs esc-fin__tabs esc-cfg__tabs" aria-label="' . esc_attr__( 'Configuración', 'malka-crm' ) . '">';
		foreach ( $tabs as $t ) {
			$en_form = 'configuracion' === $vista && false !== strpos( $t[2], '#' );
			$on      = ( $t[0] === $vista ) || ( 'configuracion' === $vista && 'estudio' === $t[0] );
			$out    .= sprintf(
				'<a class="esc-nl__tab%1$s" href="%2$s"%3$s%4$s>%5$s</a>',
				$on ? ' is-on' : '',
				esc_url( $t[2] ),
				$en_form ? ' data-cfg-ir="' . esc_attr( $t[0] ) . '"' : '',
				$on ? ' aria-current="page"' : '',
				esc_html( $t[1] )
			);
		}
		return $out . '</nav></div>';
	}

	/**
	 * Las pestañas de Finanzas, arriba de cada una de sus vistas.
	 *
	 * Son enlaces (no botones que esconden cosas): cada pestaña sigue siendo
	 * su propia dirección, así los correos y avisos que apuntan a Cobranza o
	 * a Contabilidad siguen llegando a su lugar.
	 *
	 * @param string $vista Vista activa.
	 * @return string
	 */
	/**
	 * Estudio, personal o las dos cosas.
	 *
	 * Va arriba de las solapas a propósito: no es «una pantalla más» sino el
	 * lente con el que se mira todo lo de abajo. Se guarda, así que vuelves a
	 * Finanzas y sigue como lo dejaste.
	 *
	 * @param string $ambito El elegido.
	 * @param string $vista  Dónde estás, para volver al mismo lugar.
	 * @return string
	 */
	private static function selector_ambito( $ambito, $vista ) {
		$opciones = array(
			'todo'     => __( 'Todo', 'malka-crm' ),
			'estudio'  => __( 'Estudio', 'malka-crm' ),
			'personal' => __( 'Personal', 'malka-crm' ),
		);

		// Desde una vista que sólo existe en el estudio, se vuelve al resumen.
		$destino = in_array( $vista, array( 'cobranza', 'perdidas', 'documentos', 'contabilidad' ), true ) ? 'finanzas' : $vista;

		ob_start();
		printf( '<div class="esc-amb" role="group" aria-label="%s">', esc_attr__( 'Qué plata estás mirando', 'malka-crm' ) );

		foreach ( $opciones as $k => $rotulo ) {
			printf(
				'<a class="esc-amb__b%1$s" href="%2$s"%3$s data-ambito="%5$s">%4$s</a>',
				$ambito === $k ? ' is-on' : '',
				/*
				 * Antes, al mirar lo tuyo, el Resumen no tenía nada que
				 * decir y se saltaba a Movimientos. Ahora sí lo tiene
				 * —saldo, ingresos, gastos, deudas—, así que te quedas
				 * donde estabas.
				 */
				esc_url( add_query_arg( 'ambito', $k, self::view_url( $destino ) ) ),
				$ambito === $k ? ' aria-current="true"' : '',
				esc_html( $rotulo ),
				esc_attr( $k )
			);
		}

		echo '</div>';
		return ob_get_clean();
	}

	private static function tabs_finanzas( $vista ) {
		$cobros = count( Malka_CRM_Analysis::overdue_payments() );
		$activa = 'perdidas' === $vista ? 'cobranza' : $vista;
		$ambito = Malka_CRM_Finance::ambito();

		$tabs = array(
			'finanzas'    => array( __( 'Resumen', 'malka-crm' ), 0 ),
			'cobranza'    => array( __( 'Por cobrar', 'malka-crm' ), $cobros ),
			'documentos'  => array( __( 'Documentos', 'malka-crm' ), 0 ),
			'movimientos' => array( __( 'Movimientos', 'malka-crm' ), 0 ),
			'cuentas'     => array( __( 'Cuentas', 'malka-crm' ), 0 ),
			'contabilidad' => array( __( 'Contabilidad', 'malka-crm' ), 0 ),
			// Lo que vence esta semana va en el contador: es lo que no se
			// puede dejar pasar.
			'deudas'      => array( __( 'Deudas', 'malka-crm' ), class_exists( 'Malka_CRM_Deudas' ) ? count( Malka_CRM_Deudas::proximos( 7 ) ) : 0 ),
			// La misma lista partida en dos: lo que sostiene el estudio y lo
			// que se renueva solo y se puede dar de baja.
			'costos-fijos' => array( __( 'Costos fijos', 'malka-crm' ), 0 ),
			'suscripciones' => array( __( 'Suscripciones', 'malka-crm' ), 0 ),
			'crecer'      => array( __( 'Crecer', 'malka-crm' ), 0 ),
		);

		/*
		 * Mirando sólo tu plata, estas tres no tienen nada que mostrar: son
		 * del estudio por definición —lo que te deben tus clientes, lo que
		 * emites al SII y el F29—. Dejarlas puestas prometería algo que no hay.
		 */
		if ( 'personal' === $ambito ) {
			unset( $tabs['cobranza'], $tabs['documentos'], $tabs['contabilidad'] );
		}

		ob_start();
		printf( '<div class="esc-fin__bar" data-fin-bar data-fin-vista="%s">', esc_attr( $vista ) );
		echo self::selector_ambito( $ambito, $vista ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<nav class="esc-nl__tabs esc-fin__tabs" aria-label="' . esc_attr__( 'Finanzas', 'malka-crm' ) . '">';
		foreach ( $tabs as $k => $t ) {
			printf(
				'<a class="esc-nl__tab%1$s" href="%2$s"%3$s>%4$s%5$s</a>',
				$activa === $k ? ' is-on' : '',
				esc_url( self::view_url( $k ) ),
				$activa === $k ? ' aria-current="page"' : '',
				esc_html( $t[0] ),
				$t[1] ? ' <b class="esc-fin__badge">' . (int) $t[1] . '</b>' : ''
			);
		}
		echo '</nav>';

		// Dentro de «Por cobrar», las perdidas son un filtro y no otra pestaña.
		if ( 'cobranza' === $activa ) {
			$n = class_exists( 'Malka_CRM_Losses' ) ? count( Malka_CRM_Losses::todas() ) : 0;
			printf(
				'<nav class="esc-fin__sub"><a class="%1$s" href="%2$s">%3$s</a><a class="%4$s" href="%5$s">%6$s%7$s</a></nav>',
				'cobranza' === $vista ? 'is-on' : '',
				esc_url( self::view_url( 'cobranza' ) ),
				esc_html__( 'Pendientes', 'malka-crm' ),
				'perdidas' === $vista ? 'is-on' : '',
				esc_url( self::view_url( 'perdidas' ) ),
				esc_html__( 'Dadas por perdidas', 'malka-crm' ),
				$n ? ' <i>' . (int) $n . '</i>' : ''
			);
		}
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Una franja de cifras: rótulo, número grande y una línea abajo.
	 *
	 * @param string $k     Rótulo.
	 * @param string $v     Valor ya formateado.
	 * @param string $sub   Línea de abajo.
	 * @param string $tono  '', 'late' u 'ok'.
	 * @param bool   $html  Si $sub ya viene en HTML.
	 */
	/** A dónde lleva cada cifra del resumen al tocarla. */
	private static $fin_enlaces = array();

	private static function franja( $k, $v, $sub = '', $tono = '', $html = false ) {
		// Qué significa cada cifra, al pasar el mouse por la «i».
		$ayudas = array(
			__( 'Cobrado', 'malka-crm' )         => __( 'Lo que te pagaron tus clientes en el período: las cuotas de cotizaciones marcadas como pagadas, con su fecha de pago dentro del período. Montos con IVA.', 'malka-crm' ),
			__( 'Por cobrar', 'malka-crm' )      => __( 'Lo que tus clientes todavía te deben hoy: cuotas pendientes de cotizaciones aceptadas, vencidas o por vencer. No depende del período elegido.', 'malka-crm' ),
			__( 'Dado por perdido', 'malka-crm' ) => __( 'Cuotas que marcaste como incobrables. Ya no cuentan en «Por cobrar» porque no esperas recibirlas.', 'malka-crm' ),
			__( 'Facturado', 'malka-crm' )       => __( 'La suma neta (sin IVA) de todo lo que emitiste en el período: facturas, exentas, boletas y honorarios. Las notas de crédito restan y las anuladas no cuentan.', 'malka-crm' ),
			__( 'Compras', 'malka-crm' )         => __( 'La suma neta (sin IVA) de lo que te facturaron en el período, incluidas las facturas de compra que emites tú. Las notas de crédito restan.', 'malka-crm' ),
			__( 'IVA a pagar', 'malka-crm' )     => __( 'Lo que corresponde pagar en el F29 por este período: IVA de ventas menos IVA de compras y remanente, más retenciones y PPM. El detalle casilla por casilla está en Contabilidad.', 'malka-crm' ),
			__( 'IVA del período', 'malka-crm' ) => __( 'La suma de los F29 de todos los meses del período (IVA, retenciones y PPM), pagados o no. El estado de cada mes está en Contabilidad.', 'malka-crm' ),
			__( 'Saldo en cuenta', 'malka-crm' ) => __( 'Lo que hay hoy en la cuenta de la empresa según el último saldo que anotaste, más y menos los movimientos registrados desde entonces.', 'malka-crm' ),
		);
		$info = isset( $ayudas[ $k ] )
			? '<span class="esc-info" tabindex="0" role="img" aria-label="' . esc_attr( $ayudas[ $k ] ) . '" data-tip="' . esc_attr( $ayudas[ $k ] ) . '">i</span>'
			: '';
		$href = isset( self::$fin_enlaces[ $k ] ) ? self::$fin_enlaces[ $k ] : '';
		printf(
			'<div class="esc-fin__cell%1$s"%5$s><span class="esc-nh__k">%2$s</span><b>%3$s</b><span class="esc-fin__sub-t">%4$s</span></div>',
			( $tono ? ' is-' . esc_attr( $tono ) : '' ) . ( $href ? ' is-link' : '' ),
			esc_html( $k ) . $info, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $v ),
			$html ? $sub : esc_html( $sub ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$href ? ' data-href="' . esc_url( $href ) . '" role="link" tabindex="0"' : ''
		);
	}

	/**
	 * El período elegido en Finanzas: día, mes, 12 meses, año o años.
	 *
	 * @return array {clave, desde, hasta, gran, titulo, mes, anio, dia}
	 */
	public static function periodo_finanzas() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		/*
		 * El mes, no el año: lo que se mira a diario es cómo va este mes.
		 * El año entero es una revisión que se hace de vez en cuando, y
		 * está a un clic.
		 */
		$clave = isset( $_GET['periodo'] ) ? sanitize_key( wp_unslash( $_GET['periodo'] ) ) : 'mes';
		$mes   = isset( $_GET['mes'] ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : '';
		$anio  = isset( $_GET['anio'] ) ? (int) $_GET['anio'] : 0;
		$dia   = isset( $_GET['dia'] ) ? sanitize_text_field( wp_unslash( $_GET['dia'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$hoy   = current_time( 'Y-m-d' );
		$clave = in_array( $clave, array( 'dia', 'mes', '12m', 'anio', 'anios' ), true ) ? $clave : 'mes';
		$mes   = preg_match( '/^\d{4}-\d{2}$/', $mes ) ? $mes : substr( $hoy, 0, 7 );
		$anio  = $anio >= 2000 && $anio <= 2100 ? $anio : (int) substr( $hoy, 0, 4 );
		$dia   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dia ) ? $dia : $hoy;

		switch ( $clave ) {
			case 'dia':
				$r = array( $dia, $dia, 'dia', ucfirst( date_i18n( 'l j \d\e F Y', strtotime( $dia ) ) ) );
				break;
			case '12m':
				$r = array( gmdate( 'Y-m-01', strtotime( substr( $hoy, 0, 7 ) . '-01 -11 months' ) ), $hoy, 'mes', __( 'Últimos 12 meses', 'malka-crm' ) );
				break;
			case 'anio':
				/* translators: año */
				$r = array( $anio . '-01-01', $anio . '-12-31', 'mes', sprintf( __( 'Año %d', 'malka-crm' ), $anio ) );
				break;
			case 'anios':
				$min = (int) substr( $hoy, 0, 4 );
				if ( class_exists( 'Malka_CRM_Documents' ) ) {
					foreach ( Malka_CRM_Documents::all() as $d ) {
						if ( $d['fecha'] ) {
							$min = min( $min, (int) substr( $d['fecha'], 0, 4 ) );
						}
					}
				}
				$r = array( $min . '-01-01', $hoy, 'anio', __( 'Todos los años', 'malka-crm' ) );
				break;
			default:
				$r = array( $mes . '-01', gmdate( 'Y-m-t', strtotime( $mes . '-01' ) ), 'dia', $mes === substr( $hoy, 0, 7 ) ? __( 'Este mes', 'malka-crm' ) : ucfirst( date_i18n( 'F Y', strtotime( $mes . '-01' ) ) ) );
		}

		return array(
			'clave'  => $clave,
			'desde'  => $r[0],
			'hasta'  => $r[1],
			'gran'   => $r[2],
			'titulo' => $r[3],
			'mes'    => $mes,
			'anio'   => $anio,
			'dia'    => $dia,
		);
	}

	/**
	 * Los botones del período y, según cuál, el mes, el año o el día.
	 *
	 * @param array $per Período.
	 * @return string
	 */
	private static function selector_periodo( $per ) {
		$url  = self::view_url( 'finanzas' );
		$ops  = array(
			'dia'   => __( 'Día', 'malka-crm' ),
			'mes'   => __( 'Mes', 'malka-crm' ),
			'12m'   => __( '12 meses', 'malka-crm' ),
			'anio'  => __( 'Año', 'malka-crm' ),
			'anios' => __( 'Años', 'malka-crm' ),
		);
		$out  = '<form method="get" action="' . esc_url( $url ) . '" class="esc-per">';
		$out .= '<div class="esc-per__b" role="group" aria-label="' . esc_attr__( 'Período', 'malka-crm' ) . '">';
		foreach ( $ops as $k => $t ) {
			$out .= sprintf(
				'<button type="submit" name="periodo" value="%1$s" class="esc-gp__b%2$s" data-per="%1$s">%3$s</button>',
				esc_attr( $k ),
				$k === $per['clave'] ? ' is-on' : '',
				esc_html( $t )
			);
		}
		$out .= '</div>';
		$out .= '<input type="hidden" name="periodo" value="' . esc_attr( $per['clave'] ) . '" disabled data-per-actual />';

		if ( 'mes' === $per['clave'] ) {
			$out .= '<select name="mes" onchange="this.form.querySelector(\'[data-per-actual]\').disabled=false;this.form.submit()" aria-label="' . esc_attr__( 'Mes', 'malka-crm' ) . '">';
			for ( $i = 0; $i < 36; $i++ ) {
				$m    = gmdate( 'Y-m', strtotime( current_time( 'Y-m' ) . '-01 -' . $i . ' months' ) );
				$out .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $m ), selected( $per['mes'], $m, false ), esc_html( ucfirst( date_i18n( 'F Y', strtotime( $m . '-01' ) ) ) ) );
			}
			$out .= '</select>';
		} elseif ( 'anio' === $per['clave'] ) {
			$out .= '<select name="anio" onchange="this.form.querySelector(\'[data-per-actual]\').disabled=false;this.form.submit()" aria-label="' . esc_attr__( 'Año', 'malka-crm' ) . '">';
			for ( $a = (int) current_time( 'Y' ); $a >= (int) current_time( 'Y' ) - 15; $a-- ) {
				$out .= sprintf( '<option value="%1$d"%2$s>%1$d</option>', $a, selected( $per['anio'], $a, false ) );
			}
			$out .= '</select>';
		} elseif ( 'dia' === $per['clave'] ) {
			$out .= '<input type="date" name="dia" value="' . esc_attr( $per['dia'] ) . '" max="' . esc_attr( current_time( 'Y-m-d' ) ) . '" onchange="this.form.querySelector(\'[data-per-actual]\').disabled=false;this.form.submit()" aria-label="' . esc_attr__( 'Día', 'malka-crm' ) . '" />';
		}

		return $out . '</form>';
	}

	/**
	 * Todos los documentos del período: facturas, exentas, boletas,
	 * honorarios y notas de crédito, de venta y de compra.
	 *
	 * @param array $per Período.
	 * @return array
	 */
	private static function docs_periodo( $per ) {
		$out = array(
			'neto'      => 0,
			'iva'       => 0,
			'cuantos'   => 0,
			'c_neto'    => 0,
			'c_iva'     => 0,
			'c_cuantos' => 0,
			'iva_pagar' => 0,
			'tipos'     => array(),
		);
		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return $out;
		}
		$tipos = Malka_CRM_Documents::tipos();
		foreach ( Malka_CRM_Documents::all() as $d ) {
			if ( 'anulada' === $d['estado'] || ! $d['fecha'] || $d['fecha'] < $per['desde'] || $d['fecha'] > $per['hasta'] ) {
				continue;
			}
			$s   = (int) $d['signo'];
			$lad = 'compra' === $d['sentido'] ? 'c' : 'v';
			$k   = '' !== $d['emito'] ? 'compra_emitida' : $d['tipo'];
			if ( ! isset( $out['tipos'][ $k ] ) ) {
				$out['tipos'][ $k ] = array(
					'rotulo' => 'compra_emitida' === $k ? __( 'Facturas de compra', 'malka-crm' ) : ( isset( $tipos[ $k ] ) ? $tipos[ $k ] : $k ),
					'v_n'    => 0,
					'v_neto' => 0,
					'v_iva'  => 0,
					'c_n'    => 0,
					'c_neto' => 0,
					'c_iva'  => 0,
				);
			}
			$out['tipos'][ $k ][ $lad . '_n' ]++;
			$out['tipos'][ $k ][ $lad . '_neto' ] += $s * (int) $d['neto'];
			$out['tipos'][ $k ][ $lad . '_iva' ]  += $s * (int) $d['iva'];
			if ( 'c' === $lad ) {
				$out['c_neto'] += $s * (int) $d['neto'];
				$out['c_iva']  += $s * (int) $d['iva'];
				$out['c_cuantos']++;
			} else {
				$out['neto'] += $s * (int) $d['neto'];
				$out['iva']  += $s * (int) $d['iva'];
				$out['cuantos']++;
			}
		}

		// El IVA a pagar: el F29 de cada mes del período (con remanente y retenciones).
		if ( 'dia' === $per['clave'] ) {
			$out['iva_pagar'] = max( 0, $out['iva'] - $out['c_iva'] );
		} elseif ( class_exists( 'Malka_CRM_Contabilidad' ) ) {
			foreach ( Malka_CRM_Contabilidad::meses() as $m => $f ) {
				if ( $m >= substr( $per['desde'], 0, 7 ) && $m <= substr( $per['hasta'], 0, 7 ) ) {
					$out['iva_pagar'] += (int) $f['total'];
				}
			}
		}
		return $out;
	}

	/**
	 * Ventas y compras del período, en barras.
	 *
	 * @param array $per Período.
	 * @return string
	 */
	/**
	 * La banda de cifras cuando el lente no es el estudio.
	 *
	 * Con «Todo» cuenta la plata de los dos bolsillos juntos; con
	 * «Personal», sólo la tuya. En los dos casos son cifras de caja
	 * —cuánto hay, cuánto entró, cuánto salió— y no de facturación, que
	 * es lo que tiene sentido fuera del negocio.
	 *
	 * @param array  $per     Período.
	 * @param string $lente   todo|personal.
	 * @param int    $pend    Por cobrar.
	 * @param int    $vencido Lo vencido de eso.
	 * @return string
	 */
	/**
	 * Cuánto puedes sacarte del negocio, y cuánto llevas sacado.
	 *
	 * Es la pregunta que cierra el resumen: de la plata que ves, cuánta es
	 * tuya de verdad. El cuadro ya existía en Movimientos; acá se muestra
	 * el mismo, para no tener que ir a buscarlo.
	 *
	 * @return string
	 */
	/**
	 * Devuelve Finanzas entera —su barra y su contenido— sin recargar.
	 *
	 * El lente y el período cambian toda la pantalla, así que no alcanza
	 * con cambiar un trozo: se devuelven las dos partes y el navegador las
	 * reemplaza. Sigue siendo la misma pantalla, no se pierde el scroll ni
	 * parpadea el resto del escritorio.
	 */
	public static function ajax_finanzas() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( array(), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$vista  = isset( $_POST['vista'] ) ? sanitize_key( wp_unslash( $_POST['vista'] ) ) : 'finanzas';
		$ambito = isset( $_POST['ambito'] ) ? sanitize_key( wp_unslash( $_POST['ambito'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $vista, self::vistas_finanzas(), true ) ) {
			wp_send_json_error( array(), 400 );
		}

		if ( $ambito && class_exists( 'Malka_CRM_Finance' ) ) {
			Malka_CRM_Finance::fijar_ambito( $ambito );
		}

		/*
		 * El período se lee de $_GET, así que lo que llega por POST se
		 * copia allá antes de dibujar: es la misma pantalla que saldría
		 * navegando a esa dirección.
		 */
		foreach ( array( 'periodo', 'mes', 'anio', 'dia' ) as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST[ $k ] ) && '' !== $_POST[ $k ] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$_GET[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}

		wp_send_json_success(
			array(
				'barra' => self::tabs_finanzas( $vista ),
				'html'  => self::cuerpo_finanzas( $vista ),
			)
		);
	}

	/**
	 * El contenido de una pestaña de Finanzas, sin la cáscara.
	 *
	 * @param string $vista Pestaña.
	 * @return string
	 */
	public static function cuerpo_finanzas( $vista ) {
		switch ( $vista ) {
			case 'cobranza':
				return self::view_collections();
			case 'perdidas':
				return self::view_losses();
			case 'documentos':
				return Malka_CRM_Documents::render_list();
			case 'movimientos':
				return self::view_movements();
			case 'cuentas':
				return self::view_cuentas();
			case 'contabilidad':
				return self::view_contabilidad();
			case 'personal':
				return self::view_personal();
			case 'deudas':
				return Malka_CRM_Deudas::render();
			case 'crecer':
				return self::view_crecer();
			case 'costos-fijos':
				return self::view_costos( 'fijo' );
			case 'suscripciones':
				return self::view_costos( 'suscripcion' );
			default:
				return self::view_finance();
		}
	}

	private static function panel_retiros() {
		if ( ! class_exists( 'Malka_CRM_Retiro' ) ) {
			return '';
		}

		return Malka_CRM_Retiro::render_analisis();
	}

	/**
	 * Qué pagos suman lo cobrado del período, uno por uno.
	 *
	 * Esta lista vivía dentro del bloque de la meta, en Inicio, metida en un
	 * desplegable: el Inicio dice cómo vas y acá se revisa de dónde sale el
	 * número. El monto de Inicio enlaza a este panel por su ancla.
	 *
	 * @param array $per El período de Finanzas.
	 * @return string
	 */
	private static function panel_cobrado( $per ) {
		if ( ! class_exists( 'Malka_CRM_Analysis' ) ) {
			return '';
		}

		$pagos = Malka_CRM_Analysis::pagos_recibidos( $per['desde'], $per['hasta'] );
		$suma  = 0;
		foreach ( $pagos as $q ) {
			$suma += (int) $q['neto'];
		}

		ob_start();
		echo '<section class="esc-panel esc-panel--full" id="esc-cobrado">';
		printf(
			'<div class="esc-ph"><div><h2>%1$s</h2><p>%2$s</p></div><span class="esc-st esc-st--ok">%3$s</span></div>',
			esc_html__( 'De dónde sale lo cobrado', 'malka-crm' ),
			esc_html__( 'Las cuotas que marcaste pagadas en el período. Montos sin IVA: el IVA no es tuyo, se paga en el F29.', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $suma ) )
		);

		if ( ! $pagos ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no marcaste ninguna cuota como pagada en este período.', 'malka-crm' ) . '</p>';
		}

		foreach ( $pagos as $q ) {
			printf(
				'<a class="esc-fin__r esc-cobrado__r" href="%1$s">' .
					'<span class="esc-nl__p"><b>%2$s</b><span>%3$s</span></span>' .
					'<span class="esc-fin__d"><b>%4$s</b><span>%5$s</span></span></a>',
				esc_url( $q['url'] ),
				esc_html( $q['cliente'] ),
				esc_html(
					sprintf(
						/* translators: 1: número de cotización, 2: cuota, 3: fecha */
						__( 'Nº %1$s%2$s · %3$s', 'malka-crm' ),
						$q['numero'],
						$q['rol'] ? ' · ' . $q['rol'] : '',
						date_i18n( 'j M', strtotime( $q['fecha'] ) )
					)
				),
				esc_html( Malka_CRM_Settings::money( $q['neto'] ) ),
				esc_html__( 'neto', 'malka-crm' )
			);
		}

		echo '</section>';
		return ob_get_clean();
	}

	private static function banda_bolsillo( $per, $lente, $pend, $vencido ) {
		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return '';
		}

		$hasta = min( $per['hasta'], current_time( 'Y-m-d' ) );
		$caja  = Malka_CRM_Finance::por_bolsillo( $per['desde'], $hasta );
		$mio   = 'personal' === $lente;

		$entra = $mio ? $caja['personal']['entra'] : $caja['personal']['entra'] + $caja['estudio']['entra'];
		$sale  = $mio ? $caja['personal']['sale'] : $caja['personal']['sale'] + $caja['estudio']['sale'];

		$saldo_e = Malka_CRM_Finance::balance();
		$saldo_p = Malka_CRM_Finance::balance_personal();
		$saldo   = $mio ? $saldo_p : $saldo_e + $saldo_p;

		ob_start();
		echo '<div class="esc-fin__band">';

		self::$fin_enlaces = array(
			__( 'Saldo total', 'malka-crm' ) => self::view_url( 'finanzas' ),
			__( 'Ingresos', 'malka-crm' )    => self::view_url( 'movimientos' ),
			__( 'Gastos', 'malka-crm' )      => self::view_url( 'movimientos' ),
			__( 'Por cobrar', 'malka-crm' )  => self::view_url( 'cobranza' ),
			__( 'Deudas', 'malka-crm' )      => self::view_url( 'deudas' ),
		);

		self::franja(
			__( 'Saldo total', 'malka-crm' ),
			Malka_CRM_Settings::money( $saldo ),
			$mio
				? esc_html__( 'En tus cuentas', 'malka-crm' )
				: sprintf(
					/* translators: 1: saldo del estudio 2: saldo personal */
					esc_html__( 'Estudio %1$s · tuyo %2$s', 'malka-crm' ),
					Malka_CRM_Settings::money( $saldo_e ),
					Malka_CRM_Settings::money( $saldo_p )
				),
			'',
			true
		);

		self::franja(
			__( 'Ingresos', 'malka-crm' ),
			Malka_CRM_Settings::money( $entra ),
			$mio || ! $caja['estudio']['entra']
				? esc_html__( 'Lo que entró en el período', 'malka-crm' )
				: sprintf(
					/* translators: 1: del estudio 2: tuyo */
					esc_html__( 'Estudio %1$s · tuyo %2$s', 'malka-crm' ),
					Malka_CRM_Settings::money( $caja['estudio']['entra'] ),
					Malka_CRM_Settings::money( $caja['personal']['entra'] )
				),
			$entra > 0 ? 'ok' : '',
			true
		);

		self::franja(
			__( 'Gastos', 'malka-crm' ),
			Malka_CRM_Settings::money( $sale ),
			$mio || ! $caja['estudio']['sale']
				? esc_html__( 'Lo que salió en el período', 'malka-crm' )
				: sprintf(
					/* translators: 1: del estudio 2: tuyo */
					esc_html__( 'Estudio %1$s · tuyo %2$s', 'malka-crm' ),
					Malka_CRM_Settings::money( $caja['estudio']['sale'] ),
					Malka_CRM_Settings::money( $caja['personal']['sale'] )
				),
			'',
			true
		);

		if ( $mio ) {
			/*
			 * Tus deudas: lo que debes en tarjetas más lo que le debes a
			 * alguien. Es la cifra que falta para saber si el saldo que
			 * ves es tuyo de verdad.
			 */
			$tarjetas = class_exists( 'Malka_CRM_Deudas' ) ? Malka_CRM_Deudas::totales() : array( 'debe' => 0 );
			$debo     = 0;

			if ( class_exists( 'Malka_CRM_Prestamos' ) ) {
				foreach ( Malka_CRM_Prestamos::lista( 'debo' ) as $d ) {
					$debo += (int) $d['pendiente'];
				}
			}

			$deuda = (int) $tarjetas['debe'] + $debo;

			self::franja(
				__( 'Deudas', 'malka-crm' ),
				Malka_CRM_Settings::money( $deuda ),
				$deuda > 0
					? sprintf(
						/* translators: 1: tarjetas 2: personas */
						esc_html__( 'Tarjetas %1$s · personas %2$s', 'malka-crm' ),
						Malka_CRM_Settings::money( (int) $tarjetas['debe'] ),
						Malka_CRM_Settings::money( $debo )
					)
					: esc_html__( 'No debes nada', 'malka-crm' ),
				$deuda > 0 ? 'late' : 'ok',
				true
			);
		} else {
			self::franja(
				__( 'Por cobrar', 'malka-crm' ),
				Malka_CRM_Settings::money( $pend ),
				$vencido > 0
					? sprintf( /* translators: monto */ __( '%s vencido', 'malka-crm' ), Malka_CRM_Settings::money( $vencido ) )
					: __( 'Nada vencido', 'malka-crm' ),
				$vencido > 0 ? 'late' : ''
			);

			$perd   = class_exists( 'Malka_CRM_Losses' ) ? Malka_CRM_Losses::todas() : array();
			$suma_p = 0;

			foreach ( $perd as $x ) {
				$suma_p += (int) $x['monto'];
			}

			self::franja(
				__( 'Dado por perdido', 'malka-crm' ),
				Malka_CRM_Settings::money( $suma_p ),
				$perd
					? sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::view_url( 'perdidas' ) ), esc_html( sprintf( /* translators: cuántas */ _n( '%d cuota · ver →', '%d cuotas · ver →', count( $perd ), 'malka-crm' ), count( $perd ) ) ) )
					: esc_html__( 'Ninguna cuota', 'malka-crm' ),
				$suma_p > 0 ? 'off' : '',
				true
			);
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Lo que entra y lo que sale, mes a mes, por bolsillo.
	 *
	 * Con «Todo» van dos dibujos, el del estudio y el tuyo, para poder
	 * compararlos de un vistazo; con «Personal», sólo el tuyo.
	 *
	 * @param array  $per   Período.
	 * @param string $lente todo|personal.
	 * @return string
	 */
	private static function graficos_bolsillo( $per, $lente ) {
		if ( ! class_exists( 'Malka_CRM_Finance' ) || ! class_exists( 'Malka_CRM_Home' ) ) {
			return '';
		}

		$hasta = min( $per['hasta'], current_time( 'Y-m-d' ) );
		$meses = Malka_CRM_Finance::por_bolsillo_mes( $per['desde'], $hasta );

		$cuales = 'personal' === $lente
			? array( 'personal' => __( 'Lo tuyo', 'malka-crm' ) )
			: array(
				'estudio'  => __( 'El estudio', 'malka-crm' ),
				'personal' => __( 'Lo tuyo', 'malka-crm' ),
			);

		$out = '<div class="esc-fin__g">';

		foreach ( $cuales as $cual => $titulo ) {
			$entra = array();
			$sale  = array();

			foreach ( $meses as $mes => $d ) {
				$entra[ $mes ] = $d[ $cual ]['entra'];
				$sale[ $mes ]  = $d[ $cual ]['sale'];
			}

			$neto = array_sum( $entra ) - array_sum( $sale );

			$out .= '<div class="esc-fin__gi">';
			$out .= '<p class="esc-gv__t"><span>' . esc_html( $titulo ) . '</span><em>' .
				esc_html( Malka_CRM_Settings::money( $neto ) ) . '</em></p>';

			if ( array_filter( $entra ) || array_filter( $sale ) ) {
				$out .= '<p class="esc-gv__l">' . esc_html__( 'Entra', 'malka-crm' ) . '</p>';
				$out .= Malka_CRM_Home::barras( $entra, 'mes', $titulo . ' · ' . __( 'entra', 'malka-crm' ) );
				$out .= '<p class="esc-gv__l">' . esc_html__( 'Sale', 'malka-crm' ) . '</p>';
				$out .= Malka_CRM_Home::barras( $sale, 'mes', $titulo . ' · ' . __( 'sale', 'malka-crm' ) );
			} else {
				$out .= '<p class="esc-empty">' . esc_html__( 'Nada anotado en este período.', 'malka-crm' ) . '</p>';
			}

			$out .= '</div>';
		}

		return $out . '</div>';
	}

	private static function graficos_periodo( $per ) {
		if ( 'dia' === $per['clave'] || ! class_exists( 'Malka_CRM_Home' ) ) {
			return '';
		}
		$hasta = min( $per['hasta'], current_time( 'Y-m-d' ) );
		$out   = '<div class="esc-fin__g">';
		foreach ( array(
			'venta'  => __( 'Ventas', 'malka-crm' ),
			'compra' => __( 'Compras', 'malka-crm' ),
		) as $lado => $t ) {
			$serie = Malka_CRM_Home::serie_docs( $lado, $per['desde'], $hasta, $per['gran'] );
			$out  .= '<div class="esc-fin__gi"><p class="esc-gv__t"><span>' . esc_html( $t ) . '</span><em>' . esc_html( Malka_CRM_Settings::money( array_sum( $serie ) ) ) . '</em></p>';
			$out  .= array_filter( $serie ) ? Malka_CRM_Home::barras( $serie, $per['gran'], $t ) : '<p class="esc-empty">' . esc_html__( 'Nada anotado en este período.', 'malka-crm' ) . '</p>';
			$out  .= '</div>';
		}
		return $out . '</div>';
	}

	/**
	 * El detalle por tipo de documento.
	 *
	 * @param array $doc Lo calculado en docs_periodo.
	 * @return string
	 */
	private static function tabla_tipos( $doc ) {
		if ( empty( $doc['tipos'] ) ) {
			return '';
		}
		$out  = '<div class="esc-fin__tt"><div class="esc-fin__tr esc-fin__tr--h"><span>' . esc_html__( 'Documento', 'malka-crm' ) . '</span><span>' . esc_html__( 'Ventas', 'malka-crm' ) . '</span><span>' . esc_html__( 'IVA', 'malka-crm' ) . '</span><span>' . esc_html__( 'Compras', 'malka-crm' ) . '</span><span>' . esc_html__( 'IVA', 'malka-crm' ) . '</span></div>';
		foreach ( $doc['tipos'] as $t ) {
			$out .= sprintf(
				'<div class="esc-fin__tr"><span>%1$s</span><span data-l="%2$s">%3$s<small>%4$s</small></span><span data-l="%5$s">%6$s</span><span data-l="%7$s">%8$s<small>%9$s</small></span><span data-l="%5$s">%10$s</span></div>',
				esc_html( $t['rotulo'] ),
				esc_attr__( 'Ventas', 'malka-crm' ),
				$t['v_n'] ? esc_html( Malka_CRM_Settings::money( $t['v_neto'] ) ) : '—',
				$t['v_n'] ? esc_html( sprintf( /* translators: cantidad */ _n( '%d doc.', '%d docs.', $t['v_n'], 'malka-crm' ), $t['v_n'] ) ) : '',
				esc_attr__( 'IVA', 'malka-crm' ),
				$t['v_iva'] ? esc_html( Malka_CRM_Settings::money( $t['v_iva'] ) ) : '—',
				esc_attr__( 'Compras', 'malka-crm' ),
				$t['c_n'] ? esc_html( Malka_CRM_Settings::money( $t['c_neto'] ) ) : '—',
				$t['c_n'] ? esc_html( sprintf( /* translators: cantidad */ _n( '%d doc.', '%d docs.', $t['c_n'], 'malka-crm' ), $t['c_n'] ) ) : '',
				$t['c_iva'] ? esc_html( Malka_CRM_Settings::money( $t['c_iva'] ) ) : '—'
			);
		}
		return $out . '</div>';
	}

	/**
	 * El resumen de Finanzas: el mes en cifras y lo que no cuadra entre lo
	 * cobrado y lo facturado.
	 *
	 * @return string
	 */
	public static function view_finance() {
		$per = self::periodo_finanzas();
		$mes = $per['mes'];

		/* Cobrado en el período: la misma lista de pagos que usa Inicio. */
		$cobrado = 0;
		$cuantas = 0;
		foreach ( Malka_CRM_Analysis::pagos_recibidos( $per['desde'], $per['hasta'] ) as $p ) {
			$cobrado += $p['monto'];
			$cuantas++;
		}

		$filas   = self::collections_list();
		$pend    = 0;
		$vencido = 0;
		foreach ( $filas as $f ) {
			$pend += $f['total'];
			if ( $f['vencido'] ) {
				$vencido += $f['total'];
			}
		}

		$doc = self::docs_periodo( $per );

		/* Lo que no cuadra, negocio por negocio. */
		$sin_fact = array();
		if ( class_exists( 'Malka_CRM_Projects' ) ) {
			$pids = get_posts(
				array(
					'post_type'      => Malka_CRM_Projects::POST_TYPE,
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'posts_per_page' => 200,
					'fields'         => 'ids',
				)
			);
			foreach ( $pids as $pid ) {
				$fx = Malka_CRM_Projects::facturacion( $pid );
				if ( $fx['contratado'] <= 0 ) {
					continue;
				}
				$fila = array(
					'pid'       => (int) $pid,
					'titulo'    => get_the_title( $pid ),
					'cliente'   => Malka_CRM_Projects::cliente( $pid ),
					'quote'     => (int) get_post_meta( $pid, '_malka_pm_quote_id', true ),
					'cobrado'   => (int) $fx['cobrado'],
					'facturado' => (int) $fx['facturado'],
				);
				// Sólo en un sentido: el estudio no emite facturas sin el pago
				// previo, así que «facturado sin cobrar» no pasa.
				if ( $fx['cobrado'] - $fx['facturado'] > 1000 ) {
					$sin_fact[] = $fila;
				}
			}
		}

		ob_start();
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total esc-fin">';

		/* ── El período en cifras ── */
		echo '<section class="esc-panel esc-fin__m">';
		echo '<div class="esc-ph"><div><h2>' . esc_html( $per['titulo'] ) . '</h2>';
		echo '<p>' . esc_html__( 'Lo cobrado y lo facturado, con lo que falta por entrar', 'malka-crm' ) . '</p></div></div>';
		echo self::selector_periodo( $per ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/*
		 * Con el lente en «Estudio» la banda habla de cotizaciones y
		 * documentos, que es la vida del negocio. Con «Todo» o «Personal»
		 * esas cifras no dicen nada —tu arriendo no se factura—, así que
		 * ahí la banda habla de plata: cuánto hay, cuánto entró, cuánto
		 * salió.
		 */
		$lente = class_exists( 'Malka_CRM_Finance' ) ? Malka_CRM_Finance::ambito() : 'estudio';

		if ( 'estudio' !== $lente ) {
			echo self::banda_bolsillo( $per, $lente, $pend, $vencido ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::graficos_bolsillo( $per, $lente ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</section>';
			echo self::panel_retiros(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';

			return ob_get_clean();
		}

		echo '<div class="esc-fin__band">';
		// Cada cifra lleva a su detalle.
		$docs_url = self::view_url( 'documentos' );
		$mes_q    = 'mes' === $per['clave'] ? array( 'mes' => $per['mes'] ) : array();
		self::$fin_enlaces = array(
			// Su detalle es la lista de cuotas pagadas, que está más abajo en
			// esta misma pantalla, no los movimientos de la cuenta.
			__( 'Cobrado', 'malka-crm' )          => '#esc-cobrado',
			__( 'Por cobrar', 'malka-crm' )       => self::view_url( 'cobranza' ),
			__( 'Dado por perdido', 'malka-crm' ) => self::view_url( 'perdidas' ),
			__( 'Facturado', 'malka-crm' )        => add_query_arg( array_merge( array( 'ver' => 'venta' ), $mes_q ), $docs_url ),
			__( 'Compras', 'malka-crm' )          => add_query_arg( array_merge( array( 'ver' => 'compra' ), $mes_q ), $docs_url ),
			__( 'IVA a pagar', 'malka-crm' )      => self::view_url( 'contabilidad' ),
			__( 'IVA del período', 'malka-crm' )  => self::view_url( 'contabilidad' ),
			__( 'Saldo en cuenta', 'malka-crm' )  => self::view_url( 'movimientos' ),
		);
		self::franja( __( 'Cobrado', 'malka-crm' ), Malka_CRM_Settings::money( $cobrado ), sprintf( /* translators: cuotas */ _n( '%d cuota pagada', '%d cuotas pagadas', $cuantas, 'malka-crm' ), $cuantas ), $cobrado > 0 ? 'ok' : '' );
		self::franja(
			__( 'Por cobrar', 'malka-crm' ),
			Malka_CRM_Settings::money( $pend ),
			$vencido > 0 ? sprintf( /* translators: monto */ __( '%s vencido', 'malka-crm' ), Malka_CRM_Settings::money( $vencido ) ) : __( 'Nada vencido', 'malka-crm' ),
			$vencido > 0 ? 'late' : ''
		);
		// Lo que dio por perdido, sumado: no es plata que vaya a entrar.
		$perd   = class_exists( 'Malka_CRM_Losses' ) ? Malka_CRM_Losses::todas() : array();
		$suma_p = 0;
		foreach ( $perd as $x ) {
			$suma_p += (int) $x['monto'];
		}
		self::franja(
			__( 'Dado por perdido', 'malka-crm' ),
			Malka_CRM_Settings::money( $suma_p ),
			$perd
				? sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::view_url( 'perdidas' ) ), esc_html( sprintf( /* translators: cuántas */ _n( '%d cuota · ver →', '%d cuotas · ver →', count( $perd ), 'malka-crm' ), count( $perd ) ) ) )
				: esc_html__( 'Ninguna cuota', 'malka-crm' ),
			$suma_p > 0 ? 'off' : '',
			true
		);
		self::franja( __( 'Facturado', 'malka-crm' ), Malka_CRM_Settings::money( $doc['neto'] ), sprintf( /* translators: cantidad */ _n( 'Neto · %d documento', 'Neto · %d documentos', $doc['cuantos'], 'malka-crm' ), $doc['cuantos'] ) );
		self::franja( __( 'Compras', 'malka-crm' ), Malka_CRM_Settings::money( $doc['c_neto'] ), sprintf( /* translators: cantidad */ _n( 'Neto · %d documento', 'Neto · %d documentos', $doc['c_cuantos'], 'malka-crm' ), $doc['c_cuantos'] ) );
		self::franja(
			in_array( $per['clave'], array( 'dia', 'mes' ), true ) ? __( 'IVA a pagar', 'malka-crm' ) : __( 'IVA del período', 'malka-crm' ),
			Malka_CRM_Settings::money( $doc['iva_pagar'] ),
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::view_url( 'contabilidad' ) ), esc_html( 'dia' === $per['clave'] ? __( 'Ventas menos compras del día', 'malka-crm' ) : __( 'F29 de los meses · ver contabilidad →', 'malka-crm' ) ) ),
			'',
			true
		);
		self::franja(
			__( 'Saldo en cuenta', 'malka-crm' ),
			Malka_CRM_Settings::money( class_exists( 'Malka_CRM_Finance' ) ? Malka_CRM_Finance::balance() : 0 ),
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::view_url( 'movimientos' ) ), esc_html__( 'Ver movimientos →', 'malka-crm' ) ),
			'',
			true
		);
		echo '</div>';
		echo self::graficos_periodo( $per ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::tabla_tipos( $doc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';
		// De dónde sale la primera cifra de la banda, cuota por cuota.
		echo self::panel_cobrado( $per ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::panel_retiros(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/* ── Lo que no cuadra ── */
		echo '<div class="esc-fin__dos">';

		echo '<section class="esc-panel">';
		printf(
			'<div class="esc-ph"><div><h2>%1$s</h2><p>%2$s</p></div>%3$s</div>',
			esc_html__( 'Cobrado sin facturar', 'malka-crm' ),
			esc_html__( 'Te pagaron y todavía no anotas la factura', 'malka-crm' ),
			$sin_fact ? '<span class="esc-st esc-st--wait">' . count( $sin_fact ) . '</span>' : ''
		);
		if ( ! $sin_fact ) {
			echo '<p class="esc-fin__ok">' . esc_html__( 'Todo lo cobrado está facturado.', 'malka-crm' ) . '</p>';
		}
		foreach ( $sin_fact as $f ) {
			$url = add_query_arg(
				array_filter(
					array(
						'negocio' => $f['pid'],
						'quote'   => $f['quote'],
					)
				),
				Malka_CRM_Documents::edit_url( 0, 'factura' )
			);
			printf(
				'<div class="esc-fin__r esc-fin__r--a" role="button" tabindex="0" data-modal="%7$s" data-modal-t="%8$s"><span class="esc-nl__p"><b>%1$s</b><span>%2$s</span></span>' .
				'<span class="esc-fin__d"><b>%3$s</b><span>%4$s</span></span>' .
				'<a class="esc-btn esc-btn--soft" href="%5$s" data-modal="%9$s" data-modal-t="%6$s">+ %6$s</a></div>',
				esc_html( $f['titulo'] ),
				esc_html( sprintf( /* translators: 1: cobrado, 2: facturado */ __( 'Cobrado %1$s · facturado %2$s', 'malka-crm' ), Malka_CRM_Settings::money( $f['cobrado'] ), Malka_CRM_Settings::money( $f['facturado'] ) ) ),
				esc_html( Malka_CRM_Settings::money( $f['cobrado'] - $f['facturado'] ) ),
				esc_html__( 'por facturar (neto)', 'malka-crm' ),
				esc_url( $url ),
				esc_html__( 'Anotar factura', 'malka-crm' ),
				esc_url( add_query_arg( 'embed', 1, Malka_CRM_Projects::edit_url( $f['pid'] ) ) ),
				esc_attr( $f['titulo'] ),
				esc_url( add_query_arg( 'embed', 1, $url ) )
			);
		}
		echo '</section>';

		/* ── Lo próximo por cobrar ── */
		echo '<section class="esc-panel">';
		printf(
			'<div class="esc-ph"><div><h2>%1$s</h2><p>%2$s</p></div><div class="esc-ph__r"><a class="esc-btn esc-btn--soft" href="%3$s">%4$s</a></div></div>',
			esc_html__( 'Lo próximo por cobrar', 'malka-crm' ),
			esc_html__( 'Primero lo vencido, después lo que viene', 'malka-crm' ),
			esc_url( self::view_url( 'cobranza' ) ),
			esc_html__( 'Ver todo →', 'malka-crm' )
		);
		if ( ! $filas ) {
			echo '<p class="esc-fin__ok">' . esc_html__( 'No hay nada por cobrar.', 'malka-crm' ) . '</p>';
		}
		foreach ( array_slice( $filas, 0, 5 ) as $c ) {
			printf(
				'<a class="esc-fin__r esc-fin__r--a%1$s" href="%2$s" data-modal="%8$s" data-modal-t="%9$s"><span class="esc-nl__p"><b>%3$s</b><span>%4$s</span></span>' .
				'<span class="esc-st esc-st--%5$s">%6$s</span><span class="esc-fin__d"><b>%7$s</b></span></a>',
				$c['vencido'] ? ' is-late' : '',
				esc_url( self::view_url( 'cobranza' ) ),
				esc_html( $c['cliente'] ? $c['cliente'] : $c['numero'] ),
				esc_html( trim( $c['numero'] . ( $c['cuota'] ? ' · ' . $c['cuota'] : '' ) ) ),
				esc_attr( $c['clase'] ),
				esc_html( $c['estado'] ),
				esc_html( Malka_CRM_Settings::money( $c['total'] ) ),
				esc_url( add_query_arg( 'embed', 1, Malka_CRM_Quote_Editor::edit_url( $c['quote_id'] ) ) ),
				esc_attr( sprintf( /* translators: número */ __( 'Cotización %s', 'malka-crm' ), $c['numero'] ) )
			);
		}
		echo '</section>';
		echo '</div>';

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * La vista de Cobranza.
	 *
	 * @return string
	 */
	public static function view_collections() {
		$filas = self::collections_list();

		$total   = 0;
		$vencido = 0;
		foreach ( $filas as $f ) {
			$total += $f['total'];
			if ( $f['vencido'] ) {
				$vencido += $f['total'];
			}
		}

		ob_start();
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total">';
		echo self::aviso_resultado(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$perdidas = class_exists( 'Malka_CRM_Losses' ) ? Malka_CRM_Losses::todas() : array();
		$suma_p   = 0;
		foreach ( $perdidas as $p ) {
			$suma_p += (int) $p['monto'];
		}

		/* Las cifras arriba, en franjas. */
		echo '<section class="esc-panel esc-fin__m">';
		printf(
			'<div class="esc-ph"><div><h2>%1$s</h2><p>%2$s</p></div></div>',
			esc_html__( 'Por cobrar', 'malka-crm' ),
			esc_html( sprintf( /* translators: cuotas */ _n( '%d cuota pendiente', '%d cuotas pendientes', count( $filas ), 'malka-crm' ), count( $filas ) ) )
		);
		echo '<div class="esc-fin__band">';
		self::franja( __( 'En total', 'malka-crm' ), Malka_CRM_Settings::money( $total ), '' );
		self::franja( __( 'Vencido', 'malka-crm' ), Malka_CRM_Settings::money( $vencido ), $vencido > 0 ? __( 'Escríbeles hoy', 'malka-crm' ) : __( 'Nada atrasado', 'malka-crm' ), $vencido > 0 ? 'late' : 'ok' );
		self::franja( __( 'Por vencer', 'malka-crm' ), Malka_CRM_Settings::money( $total - $vencido ), '' );
		self::franja(
			__( 'Dadas por perdidas', 'malka-crm' ),
			Malka_CRM_Settings::money( $suma_p ),
			$perdidas
				? sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::view_url( 'perdidas' ) ), esc_html( sprintf( /* translators: cuántas */ _n( 'Ver %d →', 'Ver las %d →', count( $perdidas ), 'malka-crm' ), count( $perdidas ) ) ) )
				: __( 'Ninguna', 'malka-crm' ),
			'',
			true
		);
		echo '</div></section>';

		echo '<section class="esc-panel esc-panel--full">';
		printf(
			'<div class="esc-ph"><div><h2>%1$s</h2><p>%2$s</p></div></div>',
			esc_html__( 'Cuota por cuota', 'malka-crm' ),
			esc_html__( 'De lo más atrasado a lo que viene. El mensaje se copia listo para WhatsApp o correo', 'malka-crm' )
		);

		if ( empty( $filas ) ) {
			echo '<p class="esc-nl__vacio">' . esc_html__( 'No hay nada por cobrar. Todas las cuotas están pagadas o sin fecha.', 'malka-crm' ) . '</p>';
			echo '</section></div>';
			return ob_get_clean();
		}

		echo '<div class="esc-cq__list">';
		$n = 0;
		foreach ( $filas as $c ) {
			$n++;
			$acc = self::aviso_acciones( $c, true );

			/*
			 * Una fila por cuota: quién y qué cuota, cuándo vence, cuánto, y
			 * las dos acciones de todos los días (avisar y copiar el mensaje).
			 * Programar, abrir y darla por perdida viven en «···».
			 */
			printf(
				'<div class="esc-cq%1$s">' .
				'<span class="esc-cq__q"><b>%2$s</b><span>%3$s</span></span>' .
				'<span class="esc-cq__e"><span class="esc-st esc-st--%4$s">%5$s</span>%6$s%7$s</span>' .
				'<span class="esc-cq__m"><b>%8$s</b>%9$s</span>' .
				'<span class="esc-cq__a">%10$s' .
				'<button type="button" class="esc-btn esc-btn--soft esc-copy" data-msg="%11$s" data-id="c%12$d">%13$s</button>' .
				'<details class="esc-cq__mas"><summary aria-label="%14$s">···</summary><div class="esc-cq__pop">%15$s' .
				'<a href="%16$s">%17$s</a>%18$s</div></details></span>' .
				'</div>',
				$c['vencido'] ? ' is-late' : '',
				esc_html( $c['cliente'] ? $c['cliente'] : $c['numero'] ),
				esc_html( trim( $c['numero'] . ( $c['cuota'] ? ' · ' . $c['cuota'] : '' ) ) ),
				esc_attr( $c['clase'] ),
				esc_html( $c['estado'] ),
				empty( $c['avisado'] ) ? '' : ' <span class="esc-st esc-st--done">' . esc_html__( 'avisó que pagó', 'malka-crm' ) . '</span>',
				$acc['prog_on'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( Malka_CRM_Settings::money( $c['total'] ) ),
				$c['mora'] > 0
					? '<span>' . esc_html( sprintf( /* translators: interés */ __( 'incluye %s de interés', 'malka-crm' ), Malka_CRM_Settings::money( $c['mora'] ) ) ) . '</span>'
					: '',
				$acc['avisar'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_attr( self::collection_message( $c ) ),
				(int) $n,
				esc_html__( 'Copiar mensaje', 'malka-crm' ),
				esc_attr__( 'Más acciones', 'malka-crm' ),
				$acc['programar'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_url( $c['url'] ),
				esc_html__( 'Abrir la cotización', 'malka-crm' ),
				( class_exists( 'Malka_CRM_Losses' ) && $c['cuota_id'] )
					? sprintf(
						'<a class="is-off" href="%1$s">%2$s</a>',
						esc_url( Malka_CRM_Losses::url_marcar( $c['quote_id'], $c['cuota_id'], self::view_url( 'cobranza' ) ) ),
						esc_html__( 'Dar por perdida', 'malka-crm' )
					)
					: ''
			);
		}
		echo '</div>';

		echo '</section>';
		echo '<p class="esc-hint">' . esc_html__( '«Avisar» manda el correo al cliente al instante; «Programar» (en ···) lo deja con fecha. Los dos salen aunque los avisos automáticos estén apagados: si lo pides tú, sale.', 'malka-crm' ) . '</p>';
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Lo que ella decidió dejar de perseguir.
	 *
	 * @return string
	 */
	public static function view_losses() {
		$filas = class_exists( 'Malka_CRM_Losses' ) ? Malka_CRM_Losses::todas() : array();
		$total = 0;

		foreach ( $filas as $f ) {
			$total += (int) $f['monto'];
		}

		$volver = self::view_url( 'perdidas' );

		ob_start();
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total">';
		echo self::aviso_resultado(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<section class="esc-panel">';
		echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'peso',
			__( 'Dadas por perdidas', 'malka-crm' ),
			__( 'Dejaste de perseguirlas. El cliente sigue viendo su deuda igual que antes.', 'malka-crm' ),
			Malka_CRM_Settings::money( $total ),
			__( 'en total', 'malka-crm' )
		);

		if ( empty( $filas ) ) {
			echo '<p class="esc-empty">' . esc_html__( 'Nada dado por perdido. Cuando una cuota ya no se vaya a cobrar, el botón está en Por cobrar.', 'malka-crm' ) . '</p>';
			echo '</section></div>';
			return ob_get_clean();
		}

		echo '</section>';

		echo '<section class="esc-panel esc-panel--full">';
		echo self::head( 'peso', __( 'Cuota por cuota', 'malka-crm' ), __( 'De la última que diste por perdida a la primera', 'malka-crm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $filas as $c ) {
			printf(
				'<div class="esc-cq is-perdida">' .
				'<span class="esc-cq__q"><b>%3$s</b><span>%1$s</span></span>' .
				'<span class="esc-cq__e"><span class="esc-st esc-st--off">%2$s</span><span class="esc-cq__desde">%5$s</span></span>' .
				'<span class="esc-cq__m"><b>%4$s</b></span>' .
				'<span class="esc-cq__a">' .
				'<a class="esc-btn esc-btn--soft" href="%6$s">%7$s</a>' .
				'<a class="esc-btn esc-btn--ghost" href="%8$s">%9$s</a></span>' .
				'</div>',
				esc_html( trim( $c['numero'] . ( $c['cuota'] ? ' · ' . $c['cuota'] : '' ) ) ),
				esc_html__( 'perdida', 'malka-crm' ),
				esc_html( $c['cliente'] ? $c['cliente'] : $c['numero'] ),
				esc_html( Malka_CRM_Settings::money( $c['monto'] ) ),
				esc_html(
					$c['desde']
						? sprintf(
							/* translators: 1: fecha, 2: días de atraso que llevaba */
							__( 'Desde el %1$s, cuando llevaba %2$s de atraso', 'malka-crm' ),
							date_i18n( 'j \\d\\e F', strtotime( $c['desde'] ) ),
							sprintf( /* translators: días */ _n( '%d día', '%d días', max( 0, (int) $c['dias'] ), 'malka-crm' ), max( 0, (int) $c['dias'] ) )
						)
						: __( 'Dada por perdida', 'malka-crm' )
				),
				esc_url( Malka_CRM_Losses::url_devolver( $c['quote_id'], $c['cuota_id'], $volver ) ),
				esc_html__( 'Volver a cobrarla', 'malka-crm' ),
				esc_url( $c['url'] ),
				esc_html__( 'Abrir', 'malka-crm' )
			);
		}

		echo '</section>';
		echo '<p class="esc-hint">' . esc_html__( 'Esto es una decisión tuya y sólo tuya: la cuota deja de salir en Cobranza, deja de sumar al contador del menú y los avisos automáticos se callan. Lo que el cliente ve —su cotización, su portal y los correos que ya recibió— no cambia en nada. Si al final paga, basta con marcarla pagada en la cotización y sale sola de esta lista.', 'malka-crm' ) . '</p>';
		echo '</div>';

		return ob_get_clean();
	}

	/* ─────────────────────────────  Primeros pasos  ───────────────────── */

	private static function render_start() {
		$pasos = Malka_CRM_Onboarding::steps();
		$prog  = Malka_CRM_Onboarding::progress();

		ob_start();
		echo '<div class="esc-start">';
		echo '<div class="esc-start__h">';
		echo '<h2 class="esc-start__t">' . esc_html__( 'Empecemos', 'malka-crm' ) . '</h2>';
		echo '<p class="esc-start__s">' . esc_html__( 'Seis pasos. Los primeros cuatro se hacen una sola vez. Los últimos dos son tu día a día.', 'malka-crm' ) . '</p>';
		printf(
			'<div class="esc-start__p"><span class="esc-bar"><i style="width:%1$d%%"></i></span><b>%2$d %3$s %4$d</b></div>',
			(int) $prog['pct'],
			(int) $prog['listos'],
			esc_html__( 'de', 'malka-crm' ),
			(int) $prog['total']
		);
		echo '</div><ol class="esc-steps">';

		$n = 0;
		foreach ( $pasos as $p ) {
			$n++;
			printf(
				'<li class="esc-step%1$s%2$s"><span class="esc-step__n">%3$s</span><div><b>%4$s%5$s</b><p>%6$s</p>' .
				'<span class="esc-step__t">%7$s</span><a class="esc-mini" href="%8$s">%9$s</a></div></li>',
				$p['listo'] ? ' is-done' : '',
				! empty( $p['opcional'] ) ? ' is-opt' : '',
				$p['listo'] ? '✓' : esc_html( $n ),
				esc_html( $p['titulo'] ),
				! empty( $p['opcional'] ) ? ' <span class="esc-st esc-st--off">' . esc_html__( 'Opcional', 'malka-crm' ) . '</span>' : '',
				esc_html( $p['texto'] ),
				esc_html( $p['tiempo'] ),
				esc_url( $p['url'] ),
				esc_html( $p['listo'] ? __( 'Revisar', 'malka-crm' ) : $p['accion'] )
			);
		}

		echo '</ol>';
		echo '<p class="esc-start__f">' . esc_html__( 'Mientras falte un dato, el panel que lo necesita te dice cuál es en vez de mostrar un cero.', 'malka-crm' ) . '</p>';
		printf(
			'<p class="esc-start__f"><a href="%1$s">%2$s</a></p>',
			esc_url( Malka_CRM_Onboarding::skip_url() ),
			esc_html__( 'Prefiero ver el escritorio ahora y completar esto después', 'malka-crm' )
		);
		echo '</div>';

		return ob_get_clean();
	}

	/* ─────────────────────────────  Modales  ──────────────────────────── */

	private static function render_modal() {
		$cats = Malka_CRM_Finance::categories();

		ob_start();
		echo '<div class="esc-modal" id="esc-modal"><div class="esc-modal__c esc-mvm">';
		printf( '<h3 class="esc-modal__h" id="esc-mv-h" data-nuevo="%1$s" data-editar="%2$s">%1$s</h3>', esc_attr__( 'Registrar movimiento', 'malka-crm' ), esc_attr__( 'Editar movimiento', 'malka-crm' ) );
		echo '<form class="esc-mvm__f" id="esc-mov-form"><input type="hidden" id="esc-mv-id" value="" />';

		// Salida o entrada, como dos botones.
		printf(
			'<div class="esc-mvm__tipo" role="radiogroup" aria-label="%3$s"><label><input type="radio" name="esc-mv-tipo" value="salida" checked /><span>%1$s</span></label><label><input type="radio" name="esc-mv-tipo" value="entrada" /><span>%2$s</span></label></div>',
			esc_html__( '↗ Salida', 'malka-crm' ),
			esc_html__( '↙ Entrada', 'malka-crm' ),
			esc_attr__( 'Tipo', 'malka-crm' )
		);
		echo '<select id="esc-mv-t" hidden><option value="salida">salida</option><option value="entrada">entrada</option></select>';

		echo '<div class="esc-mvm__g">';
		// El monto, con su moneda al lado: en pesos se guarda el número tal
		// cual; en otra moneda se guarda también a cuánto equivale.
		$monedas = Malka_CRM_Settings::currencies();

		printf( '<label class="esc-mvm__c"><span>%1$s</span><span class="esc-mvm__monto"><i id="esc-mv-sig">$</i><input type="text" id="esc-mv-m" inputmode="decimal" placeholder="0" /></span></label>', esc_html__( 'Monto', 'malka-crm' ) );
		printf( '<label class="esc-mvm__c"><span>%s</span><select id="esc-mv-mon">', esc_html__( 'Moneda', 'malka-crm' ) );
		foreach ( $monedas as $k => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $label ) );
		}
		echo '</select></label>';
		printf( '<label class="esc-mvm__c"><span>%1$s</span><input type="date" id="esc-mv-f" value="%2$s" /></label>', esc_html__( 'Fecha', 'malka-crm' ), esc_attr( current_time( 'Y-m-d' ) ) );
		printf( '<label class="esc-mvm__c esc-mvm__c--2"><span>%1$s</span><input type="text" id="esc-mv-d" placeholder="%2$s" /></label>', esc_html__( 'Descripción', 'malka-crm' ), esc_attr__( 'Ej.: Pago hosting, transferencia de cliente…', 'malka-crm' ) );
		printf( '<label class="esc-mvm__c"><span>%1$s</span><input type="text" id="esc-mv-ref" placeholder="%2$s" /></label>', esc_html__( 'Referencia', 'malka-crm' ), esc_attr__( 'N° de operación, factura…', 'malka-crm' ) );
		printf( '<label class="esc-mvm__c"><span>%s</span><select id="esc-mv-c">', esc_html__( 'Categoría', 'malka-crm' ) );
		foreach ( $cats as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		// Con más de una cuenta, en cuál fue.
		if ( class_exists( 'Malka_CRM_Finance' ) && count( Malka_CRM_Finance::cuentas() ) > 1 ) {
			printf( '<label class="esc-mvm__c esc-mvm__c--2"><span>%s</span><select id="esc-mv-cta">', esc_html__( 'Cuenta', 'malka-crm' ) );
			foreach ( Malka_CRM_Finance::cuentas() as $cid => $c ) {
				printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $cid, selected( ! empty( $c['principal'] ), true, false ), esc_html( Malka_CRM_Finance::nombre_cuenta( $cid ) ) );
			}
			echo '</select></label>';
		}
		// Cómo se paga y en qué está: lo que antes era sólo «se repite».
		printf( '<label class="esc-mvm__c"><span>%s</span><select id="esc-mv-fo">', esc_html__( 'Forma', 'malka-crm' ) );
		foreach ( Malka_CRM_Finance::formas() as $k => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $label ) );
		}
		echo '</select></label>';

		printf( '<label class="esc-mvm__c"><span>%s</span><select id="esc-mv-est">', esc_html__( 'Estado', 'malka-crm' ) );
		foreach ( Malka_CRM_Finance::estados() as $k => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $label ) );
		}
		echo '</select></label>';

		printf(
			'<label class="esc-mvm__c esc-mvm__c--2 esc-mvm__cuo" data-mv-cuotas hidden><span>%1$s</span>' .
			'<span class="esc-mvm__par"><input type="number" id="esc-mv-cn" min="1" max="240" step="1" placeholder="1" aria-label="%2$s" />' .
			'<em>%3$s</em>' .
			'<input type="number" id="esc-mv-ct" min="1" max="240" step="1" placeholder="12" aria-label="%4$s" /></span></label>',
			esc_html__( 'Cuota', 'malka-crm' ),
			esc_attr__( 'Qué cuota es', 'malka-crm' ),
			esc_html__( 'de', 'malka-crm' ),
			esc_attr__( 'De cuántas cuotas', 'malka-crm' )
		);

		echo '</div>';

		printf( '<p class="esc-mvm__nota" id="esc-mv-nota" hidden>%s</p>', esc_html__( 'Un movimiento pendiente todavía no mueve el saldo: lo mueve cuando lo marcas completado.', 'malka-crm' ) );
		echo '<input type="checkbox" id="esc-mv-r" hidden />';
		echo '<div class="esc-modal__f esc-mvm__pie">';
		printf( '<button type="button" class="esc-ico esc-ico--bin" id="esc-mv-del" hidden title="%1$s" aria-label="%1$s"><svg class="esc-x-ic" viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></button>', esc_attr__( 'Eliminar movimiento', 'malka-crm' ) );
		echo '<span class="esc-mvm__sp"></span>';
		echo '<button type="button" class="esc-btn" id="esc-mv-x">' . esc_html__( 'Cancelar', 'malka-crm' ) . '</button>';
		echo '<button type="submit" class="esc-btn esc-btn--primary" id="esc-mv-ok">' . esc_html__( 'Guardar', 'malka-crm' ) . '</button>';
		echo '</div></form></div></div>';

		return ob_get_clean();
	}

	private static function render_focus() {
		$min = max( 5, (int) Malka_CRM_Settings::get( 'pomodoro_min', 25 ) );

		ob_start();
		echo '<div class="esc-focus" id="esc-focus"><div class="esc-focus__in">';
		echo '<div class="esc-focus__izq">';
		echo '<div class="esc-focus__r"><svg viewBox="0 0 240 240" aria-hidden="true">';
		echo '<circle cx="120" cy="120" r="110" fill="none" stroke="var(--esc-fill)" stroke-width="10"/>';
		echo '<circle id="esc-f-arc" cx="120" cy="120" r="110" fill="none" stroke="var(--esc-accent)" stroke-width="10" stroke-linecap="round" stroke-dasharray="691.2" stroke-dashoffset="0" transform="rotate(-90 120 120)"/>';
		echo '</svg><b id="esc-f-time">' . esc_html( sprintf( '%02d:00', $min ) ) . '</b></div>';
		echo '<span class="esc-focus__p" id="esc-f-proj"></span>';
		echo '<div class="esc-focus__b">';
		echo '<button type="button" class="esc-btn esc-btn--primary" id="esc-f-go">' . esc_html__( 'Iniciar', 'malka-crm' ) . '</button>';
		echo '<button type="button" class="esc-btn" id="esc-f-pause" disabled><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" aria-hidden="true"><path d="M5.5 3v10M10.5 3v10"/></svg>' . esc_html__( 'Pausa', 'malka-crm' ) . '</button>';
		echo '<button type="button" class="esc-btn" id="esc-f-reset"><svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.75 8a5.25 5.25 0 1 0 1.6-3.77M2.75 2.5v3h3"/></svg>' . esc_html__( 'Reiniciar', 'malka-crm' ) . '</button>';
		echo '</div><div class="esc-focus__salir">';
		echo '<button type="button" class="esc-focus__x" id="esc-f-exit">' . esc_html__( 'Salir del enfoque', 'malka-crm' ) . '</button>';
		echo '</div></div>';

		/*
		 * A la derecha, un lugar para soltar lo que se viene a la cabeza
		 * mientras trabaja, sin salir del enfoque: cada línea queda como
		 * tarea del negocio en que está (o del estudio).
		 */
		$propias = array_filter(
			class_exists( 'Malka_CRM_Projects' ) ? Malka_CRM_Projects::tareas_estudio() : array(),
			function ( $t ) {
				return empty( $t['done'] );
			}
		);
		// A quién se asigna cada tarea: al estudio o a un negocio abierto.
		$ops = '<option value="0">' . esc_html__( 'Del estudio', 'malka-crm' ) . '</option>';
		foreach ( class_exists( 'Malka_CRM_Projects' ) ? Malka_CRM_Projects::all() : array() as $n ) {
			if ( in_array( $n['estado'], array( 'entregado', 'pausado' ), true ) ) {
				continue;
			}
			$ops .= sprintf( '<option value="%1$d">%2$s</option>', (int) $n['id'], esc_html( trim( $n['titulo'] . ( $n['cliente'] ? ' · ' . $n['cliente'] : '' ) ) ) );
		}
		printf(
			'<aside class="esc-panel esc-focus__der" data-ajax="%1$s" data-nonce="%2$s"><div class="esc-ph"><div><h2>%3$s</h2><p>%4$s</p></div></div>' .
			'<form class="esc-focus__add" id="esc-f-add"><input type="text" id="esc-f-tarea" placeholder="%5$s" autocomplete="off" />' .
			'<div class="esc-focus__pie"><label class="esc-focus__para"><span>%7$s</span><select id="esc-f-para" data-buscable><option value="auto">%8$s</option>%9$s</select></label>' .
			'<button type="submit" class="esc-btn esc-btn--primary">%6$s</button></div></form>' .
			'<template id="esc-f-ops">%9$s</template>' .
			'<span class="esc-focus__a" id="esc-f-a" hidden></span><p class="esc-focus__h">%10$s</p><ul class="esc-focus__l" id="esc-f-lista">',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'malka_crm_inicio' ) ),
			esc_html__( 'Lo que se te viene a la cabeza', 'malka-crm' ),
			esc_html__( 'Escríbelo y sigue trabajando: queda como tarea pendiente.', 'malka-crm' ),
			esc_attr__( 'Ej.: mandar la factura a Lagos Yoga', 'malka-crm' ),
			esc_html__( 'Anotar', 'malka-crm' ),
			esc_html__( 'Para', 'malka-crm' ),
			esc_html__( 'Lo del pomodoro', 'malka-crm' ),
			$ops, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Pendientes', 'malka-crm' )
		);
		foreach ( array_slice( array_reverse( $propias ), 0, 12 ) as $t ) {
			printf(
				'<li><input type="checkbox" id="esc-f-%1$s" data-pid="0" data-tid="%1$s" /><div class="esc-focus__t"><label for="esc-f-%1$s">%2$s</label><select class="esc-focus__as" data-buscable aria-label="%3$s">%4$s</select></div></li>',
				esc_attr( $t['id'] ),
				esc_html( $t['name'] ),
				esc_attr__( 'Asignar a', 'malka-crm' ),
				$ops // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
		echo '</ul></aside></div></div>';

		return ob_get_clean();
	}

	/* ─────────────────────────────  Iconos  ───────────────────────────── */

	/**
	 * Iconos de línea, en SVG inline para no depender de una fuente.
	 *
	 * @param string $slug Nombre del icono.
	 * @return string
	 */
	public static function icon( $slug ) {
		$paths = array(
			'inicio'       => '<path d="M2 6.6L8 2l6 4.6V13a1 1 0 01-1 1H3a1 1 0 01-1-1V6.6z"/>',
			'pasos'        => '<circle cx="8" cy="8" r="6"/><path d="M6.2 6.3a1.9 1.9 0 013.7.6c0 1.3-1.9 1.9-1.9 1.9M8 11.2h.01"/>',
			'negocios'     => '<rect x="2" y="4.5" width="12" height="9.5" rx="1.5"/><path d="M5.5 4.5V3a1 1 0 011-1h3a1 1 0 011 1v1.5"/>',
			'maletin'      => '<rect x="2" y="4.5" width="12" height="9.5" rx="1.5"/><path d="M5.5 4.5V3a1 1 0 011-1h3a1 1 0 011 1v1.5"/>',
			'estudio'      => '<rect x="2" y="4.5" width="12" height="9.5" rx="1.5"/><path d="M5.5 4.5V3a1 1 0 011-1h3a1 1 0 011 1v1.5M2 8.6h12"/>',
			// Una hoja: lo que crece y hay que regar.
			'bienestar'    => '<path d="M8 14c0-4 1.4-7.2 5.4-9.4C13.8 8.8 12 14 8 14z"/><path d="M8 14c0-2.6-.9-4.8-3.4-6.3"/>',
			/*
			 * Una casa con su puerta. El techo no llega a los bordes: así se
			 * distingue del «Inicio», que es la misma forma sin puerta.
			 */
			'casa'         => '<path d="M2.6 7.2L8 2.8l5.4 4.4v5.6a1 1 0 01-1 1H3.6a1 1 0 01-1-1V7.2z"/><path d="M6.6 13.8V9.6h2.8v4.2"/>',
			'cuerpo'       => '<path d="M3.2 6.4a2.8 2.8 0 014.8-1.9A2.8 2.8 0 0112.8 6.4C12.8 9.6 8 13 8 13S3.2 9.6 3.2 6.4z"/>',
			'mente'        => '<path d="M9.6 2.6a3.4 3.4 0 013.2 3.3c.9.6 1 2.2-.2 2.8v1.8a1.6 1.6 0 01-1.6 1.6H9.6V14"/><path d="M9.6 2.6A3.6 3.6 0 003.2 5a2.6 2.6 0 00.6 4.3"/>',
			'vinculos'     => '<circle cx="5.4" cy="5.6" r="2.3"/><circle cx="11" cy="8.4" r="2"/><path d="M1.8 13.4a3.8 3.8 0 017.2 0M8.6 13.4a2.9 2.9 0 015.6 0"/>',
			'vos'          => '<path d="M8 13.4S2.6 10.2 2.6 6.4A2.9 2.9 0 018 5a2.9 2.9 0 015.4 1.4c0 3.8-5.4 7-5.4 7z"/>',
			'crecer'       => '<path d="M2 12.6l3.6-3.8 2.5 2.2L14 4.2"/><path d="M10.4 4.2H14v3.5"/>',
			'personal'     => '<circle cx="8" cy="5.2" r="2.6"/><path d="M3 13.4a5 5 0 0110 0"/>',
			'deudas'       => '<rect x="1.8" y="3.6" width="12.4" height="8.8" rx="1.6"/><path d="M1.8 6.9h12.4"/><path d="M4.4 10h2.6"/>',
			'cotizaciones' => '<path d="M3 2h7l3 3v9a1 1 0 01-1 1H3a1 1 0 01-1-1V3a1 1 0 011-1z"/><path d="M5 8.5h6M5 11h4"/>',
			'cobranza'     => '<path d="M8 2v12M11 4.6H6.6a2 2 0 000 4h2.8a2 2 0 010 4H4.6"/>',
			'finanzas'     => '<path d="M8 2v12M11 4.6H6.6a2 2 0 000 4h2.8a2 2 0 010 4H4.6"/>',
			'clientes'     => '<circle cx="8" cy="5.5" r="2.6"/><path d="M2.8 13.6a5.2 5.2 0 0110.4 0"/>',
			'proveedores'  => '<path d="M2 5.5h8v6H2z"/><path d="M10 7.5h2.6l1.4 2v2h-4"/><circle cx="4.5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/>',
			'peso'         => '<path d="M8 2v12M11 4.6H6.6a2 2 0 000 4h2.8a2 2 0 010 4H4.6"/>',
			'tareas'       => '<path d="M2.5 4.5l1.8 1.8 2.4-2.6M2.5 10.5l1.8 1.8 2.4-2.6M9.5 5h4M9.5 11h4"/>',
			'horas'        => '<circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.4 1.5"/>',
			'reloj'        => '<circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.4 1.5"/>',
			'movimientos'  => '<path d="M2.5 5.5h11l-2.5-2.5M13.5 10.5h-11l2.5 2.5"/>',
			'flechas'      => '<path d="M2.5 5.5h11l-2.5-2.5M13.5 10.5h-11l2.5 2.5"/>',
			'documentos'   => '<path d="M5 1.8h4.2L12.2 5v7.2a1 1 0 01-1 1H5a1 1 0 01-1-1V2.8a1 1 0 011-1z"/><path d="M9 1.8V5h3.2M6.4 8.4h3.2M6.4 10.6h2"/>',
			'mas'          => '<circle cx="3.4" cy="8" r="1.1"/><circle cx="8" cy="8" r="1.1"/><circle cx="12.6" cy="8" r="1.1"/>',
			'mensajes'     => '<rect x="2" y="3.5" width="12" height="9" rx="1.5"/><path d="M2.6 4.6L8 8.8l5.4-4.2"/>',
			'config'       => '<circle cx="8" cy="8" r="2.2"/><circle cx="8" cy="8" r="6"/>',
			'calendario'   => '<rect x="2" y="3.5" width="12" height="10.5" rx="1.5"/><path d="M2 6.5h12M5.5 2v3M10.5 2v3"/>',
			'grafico'      => '<path d="M2.5 13.5h11"/><rect x="3.5" y="8" width="2.4" height="4" rx=".6"/><rect x="6.8" y="5" width="2.4" height="7" rx=".6"/><rect x="10.1" y="2.5" width="2.4" height="9.5" rx=".6"/>',
			'bombilla'     => '<path d="M8 1.8a4.6 4.6 0 00-2.6 8.4V12h5.2v-1.8A4.6 4.6 0 008 1.8z"/><path d="M6.6 14h2.8"/>',
			'campana'      => '<path d="M4 6.8a4 4 0 118 0c0 2.6 1 3.9 1 3.9H3s1-1.3 1-3.9z"/><path d="M6.6 13a1.6 1.6 0 002.8 0"/>',
			// Lo que vuelve solo todos los meses.
			'repite'       => '<path d="M2.9 8a5.1 5.1 0 018.7-3.6l1.5 1.5"/><path d="M13.1 2.6v3.6H9.5"/><path d="M13.1 8a5.1 5.1 0 01-8.7 3.6L2.9 10.1"/><path d="M2.9 13.4V9.8h3.6"/>',
		);

		$d = isset( $paths[ $slug ] ) ? $paths[ $slug ] : $paths['inicio'];

		return '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
	}

	/* ─────────────────────────────  Compatibilidad  ───────────────────── */

	/**
	 * Resumen numérico del estudio. Lo consume el panel de WP-Admin.
	 *
	 * @return array
	 */
	public static function collect() {
		$proyectos = Malka_CRM_Analysis::active_projects();
		$g         = Malka_CRM_Analysis::month_goal();
		$vencido   = 0;
		$pendiente = 0;

		foreach ( self::upcoming_payments() as $p ) {
			$pendiente += $p['monto'];
			if ( $p['vencido'] ) {
				$vencido += $p['monto'];
			}
		}

		return array(
			'activos'       => count( $proyectos ),
			'tareas'        => count( Malka_CRM_Analysis::studio_tasks() ),
			'observaciones' => count( Malka_CRM_Analysis::observations() ),
			'saldo'         => Malka_CRM_Finance::balance(),
			'pendiente'     => $pendiente,
			'vencido'       => $vencido,
			'ventas_mes'    => $g['ventas'],
			'sueldo_mes'    => $g['sueldo'],
			'meta_mes'      => $g['meta'],
			'tarifa'        => Malka_CRM_Settings::hourly_rate(),
			'horas_hoy'     => Malka_CRM_Time::minutes_today(),
		);
	}
}
