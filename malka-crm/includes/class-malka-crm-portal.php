<?php
/**
 * El portal del cliente.
 *
 * Una página por cliente en estudiomalka.cl/<rut>, con sus cotizaciones, sus
 * proyectos, sus documentos y lo que todavía debe. Entra con un código que se
 * le manda por correo al activarlo.
 *
 * ── Sobre el código ──
 *
 * De fábrica son los cuatro últimos dígitos del RUT antes del guion, que es lo
 * que se pidió. Eso NO es un secreto: el RUT completo está en la propia
 * dirección, así que quien tiene el enlace tiene el código. Sirve para que
 * nadie entre por equivocación, no para impedir que alguien entre a propósito.
 *
 * Por eso el código es un campo de la ficha: cambiarlo por cualquier otra cosa
 * —cuatro letras, seis dígitos— lo convierte en un secreto de verdad, y el
 * portal no necesita ningún cambio para eso.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * La página que ve el cliente con su RUT.
 */
class Malka_CRM_Portal {

	/** Código de acceso propio, si se le puso uno distinto del de fábrica. */
	const META_CODIGO = '_malka_pm_cli_codigo';

	/** Desde cuándo el cliente puede entrar. Vacío es portal cerrado. */
	const META_ACTIVO = '_malka_pm_cli_portal';

	/** Cuánto dura la sesión del cliente antes de volver a pedir el código. */
	const DIAS = 30;

	public function __construct() {
		/*
		 * Antes que el escritorio: su add_rewrite es el que vuelve a generar
		 * las reglas cuando cambia la versión, y para que esta entre en esa
		 * regeneración tiene que estar registrada antes.
		 */
		add_action( 'init', array( __CLASS__, 'add_rewrite' ), 9 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );

		// El cliente entra sin sesión de WordPress.
		add_action( 'admin_post_nopriv_malka_crm_portal_entrar', array( $this, 'handle_login' ) );
		add_action( 'admin_post_malka_crm_portal_entrar', array( $this, 'handle_login' ) );

		// Abrir y cerrar el portal es cosa suya, con sesión.
		add_action( 'admin_post_malka_crm_portal_activar', array( __CLASS__, 'handle_activar' ) );

		// El cliente marca sus tareas como listas.
		add_action( 'wp_ajax_nopriv_malka_crm_portal_lista', array( __CLASS__, 'ajax_lista' ) );
		add_action( 'wp_ajax_malka_crm_portal_lista', array( __CLASS__, 'ajax_lista' ) );
		add_action( 'wp_ajax_nopriv_malka_crm_portal_voz', array( __CLASS__, 'ajax_voz' ) );
		add_action( 'wp_ajax_malka_crm_portal_voz', array( __CLASS__, 'ajax_voz' ) );
		self::enganchar_rev();
		add_action( 'wp_ajax_nopriv_malka_crm_portal_version', array( __CLASS__, 'ajax_version' ) );
		add_action( 'wp_ajax_malka_crm_portal_version', array( __CLASS__, 'ajax_version' ) );
		add_action( 'wp_ajax_nopriv_malka_crm_portal_empezar', array( __CLASS__, 'ajax_empezar' ) );
		add_action( 'wp_ajax_malka_crm_portal_empezar', array( __CLASS__, 'ajax_empezar' ) );
		add_action( 'wp_ajax_nopriv_malka_crm_portal_nota', array( __CLASS__, 'ajax_nota' ) );
		add_action( 'wp_ajax_malka_crm_portal_nota', array( __CLASS__, 'ajax_nota' ) );
	}

	/* ─────────────────────────────  Ruta  ─────────────────────────────── */

	public static function add_rewrite() {
		/*
		 * Sólo dígitos y una K final opcional: la forma de un RUT sin puntos
		 * ni guion. Así no se traga las páginas del sitio, que llevan letras.
		 */
		add_rewrite_rule( '^(\d{7,8}[\dkK])/?$', 'index.php?malka_portal_rut=$matches[1]', 'top' );
	}

	/**
	 * @param array $vars Variables.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'malka_portal_rut';
		return $vars;
	}

	/**
	 * El RUT pedido en la dirección.
	 *
	 * Mira también la ruta cruda, por si las reglas de reescritura todavía no
	 * se regeneraron después de actualizar el plugin.
	 *
	 * @return string Sólo dígitos y la K, o ''.
	 */
	public static function requested_rut() {
		$r = get_query_var( 'malka_portal_rut' );

		if ( ! $r && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$uri = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

			if ( preg_match( '#^/(\d{7,8}[\dkK])/?$#', $uri, $m ) ) {
				$r = $m[1];
			}
		}

		return strtolower( preg_replace( '/[^0-9kK]/', '', (string) $r ) );
	}

	/**
	 * La dirección del portal de un cliente.
	 *
	 * @param int $id Cliente.
	 * @return string '' si no tiene RUT.
	 */
	public static function url( $id ) {
		$c   = Malka_CRM_Clients::get( $id );
		$rut = Malka_CRM_Clients::solo_rut( $c['rut'] );

		return $rut ? home_url( '/' . $rut ) : '';
	}

	/* ──────────────────────────  Código y sesión  ─────────────────────── */

	/**
	 * El código con que entra un cliente.
	 *
	 * El de fábrica son los cuatro últimos dígitos del RUT antes del guion.
	 * Para un RUT más corto se usan los que haya.
	 *
	 * @param int $id Cliente.
	 * @return string '' si el cliente no tiene RUT ni código propio.
	 */
	public static function codigo( $id ) {
		$propio = trim( (string) get_post_meta( $id, self::META_CODIGO, true ) );

		if ( '' !== $propio ) {
			return $propio;
		}

		$c    = Malka_CRM_Clients::get( $id );
		$solo = Malka_CRM_Clients::solo_rut( $c['rut'] );

		if ( '' === $solo ) {
			return '';
		}

		// Fuera el dígito verificador: lo pedido es «antes del guion».
		$cuerpo = substr( $solo, 0, -1 );

		return strlen( $cuerpo ) > 4 ? substr( $cuerpo, -4 ) : $cuerpo;
	}

	/**
	 * Si el portal de este cliente está abierto.
	 *
	 * @param int $id Cliente.
	 * @return bool
	 */
	public static function activo( $id ) {
		return '' !== trim( (string) get_post_meta( $id, self::META_ACTIVO, true ) );
	}

	/**
	 * La firma que viaja en la galleta.
	 *
	 * Lleva el código adentro: si se cambia, las sesiones abiertas dejan de
	 * valer solas y no hay que acordarse de cerrarlas.
	 *
	 * @param int $id Cliente.
	 * @return string
	 */
	private static function firma( $id ) {
		return wp_hash( 'malka-portal|' . (int) $id . '|' . self::codigo( $id ) );
	}

	/**
	 * El nombre de la galleta de este cliente.
	 *
	 * @param int $id Cliente.
	 * @return string
	 */
	private static function galleta( $id ) {
		return 'malka_portal_' . (int) $id;
	}

	/**
	 * Si quien mira ya entró con el código.
	 *
	 * @param int $id Cliente.
	 * @return bool
	 */
	public static function entrado( $id ) {
		// Ella ve el portal de cualquiera sin código: es su propio panel.
		if ( class_exists( 'Malka_CRM_Helpers' ) && Malka_CRM_Helpers::current_user_is_manager() ) {
			return true;
		}

		$k = self::galleta( $id );

		if ( empty( $_COOKIE[ $k ] ) ) {
			return false;
		}

		return hash_equals( self::firma( $id ), sanitize_text_field( wp_unslash( $_COOKIE[ $k ] ) ) );
	}

	/**
	 * Comprueba el código y deja entrar.
	 */
	public function handle_login() {
		$id = isset( $_POST['cliente'] ) ? absint( $_POST['cliente'] ) : 0;

		if ( ! $id || Malka_CRM_Clients::POST_TYPE !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Ese enlace no existe.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_portal_' . $id, 'malka_portal_nonce' );

		$url = self::url( $id );

		if ( ! self::activo( $id ) ) {
			wp_safe_redirect( $url );
			exit;
		}

		$dado = isset( $_POST['codigo'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['codigo'] ) ) ) : '';

		/*
		 * Cinco intentos por hora y por cliente. El código de fábrica es
		 * corto, así que sin esto se puede probar entero en un rato.
		 */
		$llave  = 'malka_portal_int_' . $id;
		$fallos = (int) get_transient( $llave );

		if ( $fallos >= 5 ) {
			wp_safe_redirect( add_query_arg( 'e', 'espera', $url ) );
			exit;
		}

		$bueno = self::codigo( $id );

		if ( '' === $dado || '' === $bueno || ! hash_equals( strtolower( $bueno ), strtolower( $dado ) ) ) {
			set_transient( $llave, $fallos + 1, HOUR_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'e', 'codigo', $url ) );
			exit;
		}

		delete_transient( $llave );

		setcookie(
			self::galleta( $id ),
			self::firma( $id ),
			array(
				'expires'  => time() + ( self::DIAS * DAY_IN_SECONDS ),
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		wp_safe_redirect( $url );
		exit;
	}

	/* ────────────────────────  Abrir y avisar  ────────────────────────── */

	/**
	 * Abre el portal de un cliente y le manda el acceso por correo.
	 */
	public static function handle_activar() {
		$id = isset( $_REQUEST['cliente'] ) ? absint( $_REQUEST['cliente'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'malka_crm_portal_activar_' . $id );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		if ( ! $id || Malka_CRM_Clients::POST_TYPE !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Ese cliente no existe.', 'malka-crm' ) );
		}

		$c      = Malka_CRM_Clients::get( $id );
		$volver = Malka_CRM_Clients::edit_url( $id );

		// Cerrarlo no necesita ni RUT ni correo.
		if ( self::activo( $id ) ) {
			delete_post_meta( $id, self::META_ACTIVO );
			wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'portal_off', $volver ) );
			exit;
		}

		if ( '' === Malka_CRM_Clients::solo_rut( $c['rut'] ) ) {
			wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'portal_sin_rut', $volver ) );
			exit;
		}

		if ( ! is_email( $c['email'] ) ) {
			wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'portal_sin_correo', $volver ) );
			exit;
		}

		update_post_meta( $id, self::META_ACTIVO, current_time( 'Y-m-d H:i:s' ) );

		$mandado = self::avisar( $id );

		wp_safe_redirect( add_query_arg( 'malka_crm_msg', $mandado ? 'portal_on' : 'portal_sin_envio', $volver ) );
		exit;
	}

	/**
	 * El correo con la dirección y el código.
	 *
	 * @param int $id Cliente.
	 * @return bool Si salió.
	 */
	public static function avisar( $id ) {
		$c = Malka_CRM_Clients::get( $id );

		if ( ! is_email( $c['email'] ) ) {
			return false;
		}

		if ( ! class_exists( 'Malka_CRM_Messages' ) ) {
			return false;
		}

		$s = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();

		return Malka_CRM_Messages::enviar(
			$c['email'],
			'portal',
			array(
				'cliente' => $c['nombre'] ? $c['nombre'] : $c['empresa'],
				'estudio' => self::nombre_estudio( $s ),
				'enlace'  => self::url( $id ),
				'codigo'  => self::codigo( $id ),
				'correo'  => isset( $s['email'] ) ? $s['email'] : '',
			)
		);
	}

	/**
	 * Cómo se llama el estudio hacia fuera.
	 *
	 * @param array $s Ajustes.
	 * @return string
	 */
	private static function nombre_estudio( $s ) {
		if ( ! empty( $s['nombre_fantasia'] ) ) {
			return $s['nombre_fantasia'];
		}

		if ( ! empty( $s['razon_social'] ) ) {
			return $s['razon_social'];
		}

		return get_bloginfo( 'name' );
	}

	/* ─────────────────────────────  Pintar  ───────────────────────────── */

	public function maybe_render() {
		$rut = self::requested_rut();

		if ( '' === $rut ) {
			return;
		}

		$id = Malka_CRM_Clients::por_rut( $rut );

		if ( ! $id || ! self::activo( $id ) ) {
			/*
			 * La regla de reescritura es de las primeras, así que se quedó con
			 * una dirección que no es de ningún portal. Si el sitio tiene una
			 * página con ese nombre, se la devolvemos; si no, que WordPress
			 * muestre su 404 de siempre.
			 */
			$pagina = get_page_by_path( $rut );

			if ( $pagina ) {
				wp_safe_redirect( get_permalink( $pagina ), 302 );
				exit;
			}

			return;
		}

		status_header( 200 );
		nocache_headers();

		self::render( $id );
		exit;
	}

	/**
	 * La página completa.
	 *
	 * Es un documento aparte, igual que la cotización pública: imprime su
	 * propio <head> para que la tipografía y los colores no dependan del tema.
	 *
	 * @param int $id Cliente.
	 */
	public static function render( $id ) {
		$s = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();
		$c = Malka_CRM_Clients::get( $id );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="utf-8" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<meta name="robots" content="noindex, nofollow" />';
		if ( class_exists( 'Malka_CRM_Dashboard' ) ) {
			echo Malka_CRM_Dashboard::etiquetas_icono( self::nombre_estudio( Malka_CRM_Settings::all() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		printf(
			'<title>%s</title>',
			esc_html(
				sprintf(
					/* translators: cliente */
					__( 'Tu página · %s', 'malka-crm' ),
					$c['nombre'] ? $c['nombre'] : $c['empresa']
				)
			)
		);

		/*
		 * Los mismos estilos y la misma letra del escritorio: el portal es
		 * parte de la plataforma, no un documento aparte.
		 */
		printf(
			'<link rel="stylesheet" href="%s" />',
			esc_url( MALKA_CRM_URL . 'assets/css/malka-crm-escritorio.css?v=' . MALKA_CRM_VERSION )
		);
		printf(
			'<link rel="stylesheet" href="%s" />',
			esc_url( MALKA_CRM_URL . 'assets/css/malka-crm-portal.css?v=' . MALKA_CRM_VERSION )
		);

		echo '</head><body class="mp-body">';

		echo self::entrado( $id ) ? self::panel( $id, $c, $s ) : self::puerta( $id, $c, $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</body></html>';
	}

	/**
	 * La pantalla del código.
	 *
	 * @param int   $id Cliente.
	 * @param array $c  Cliente.
	 * @param array $s  Ajustes.
	 * @return string
	 */
	private static function puerta( $id, $c, $s ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$e = isset( $_GET['e'] ) ? sanitize_key( wp_unslash( $_GET['e'] ) ) : '';

		ob_start();
		echo '<div class="mp mp--puerta"><div class="mp-caja">';

		echo self::marca( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf( '<h1>%s</h1>', esc_html__( 'Tu página de cliente', 'malka-crm' ) );
		printf(
			'<p class="mp-sub">%s</p>',
			esc_html(
				sprintf(
					/* translators: cliente */
					__( 'Hola %s. Escribe el código que te enviamos por correo.', 'malka-crm' ),
					$c['nombre'] ? $c['nombre'] : $c['empresa']
				)
			)
		);

		if ( 'codigo' === $e ) {
			printf(
				'<p class="mp-error" role="alert">%s</p>',
				esc_html__( 'Ese código no es. Revísalo en el correo que te mandamos.', 'malka-crm' )
			);
		} elseif ( 'espera' === $e ) {
			printf(
				'<p class="mp-error" role="alert">%s</p>',
				esc_html__( 'Demasiados intentos. Espera una hora o escríbenos.', 'malka-crm' )
			);
		}

		printf( '<form method="post" action="%s" class="mp-form">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="malka_crm_portal_entrar" />';
		printf( '<input type="hidden" name="cliente" value="%d" />', (int) $id );
		wp_nonce_field( 'malka_crm_portal_' . $id, 'malka_portal_nonce' );

		printf(
			'<input class="mp-codigo" type="text" name="codigo" autocomplete="one-time-code" required placeholder="%s" aria-label="%s" />',
			esc_attr__( 'Código', 'malka-crm' ),
			esc_attr__( 'Código de acceso', 'malka-crm' )
		);
		printf( '<button type="submit" class="mp-btn">%s</button>', esc_html__( 'Entrar', 'malka-crm' ) );
		echo '</form>';

		if ( ! empty( $s['email'] ) ) {
			printf(
				'<p class="mp-pie">%1$s <a href="mailto:%2$s">%2$s</a></p>',
				esc_html__( '¿No tienes el código?', 'malka-crm' ),
				esc_attr( $s['email'] )
			);
		}

		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * El logo o, si no hay, el nombre del estudio.
	 *
	 * @param array $s Ajustes.
	 * @return string
	 */
	private static function marca( $s ) {
		if ( ! empty( $s['logo_url'] ) ) {
			return sprintf( '<div class="mp-logo"><img src="%s" alt="" /></div>', esc_url( $s['logo_url'] ) );
		}

		return sprintf( '<div class="mp-logo mp-logo--txt">%s</div>', esc_html( self::nombre_estudio( $s ) ) );
	}

	/* ──────────────────────────  El panel  ────────────────────────────── */

	/**
	 * Todo lo del cliente, ya resuelto.
	 *
	 * Vive aparte de lo que pinta para poder probarlo sin mirar el marcado.
	 *
	 * @param int $id Cliente.
	 * @return array {cliente, cot, negocios, cuotas, docs, debe}
	 */
	public static function datos( $id ) {
		$c      = Malka_CRM_Clients::get( $id );
		$quotes = Malka_CRM_Clients::cotizaciones_de( $c );

		$cot      = array();
		$negocios = array();
		$cuotas   = array();
		$pagados  = array();
		$debe     = 0;
		$suyas    = array();

		foreach ( $quotes as $qid ) {
			/*
			 * Una cotización recién creada (desde un negocio o una idea) y
			 * todavía sin ítems no se le muestra: está a medio armar.
			 */
			$items = get_post_meta( (int) $qid, '_malka_pm_quote_items', true );
			if ( empty( $items ) && 'sin_respuesta' === (string) get_post_meta( (int) $qid, '_malka_pm_quote_status', true ) && '' === (string) get_post_meta( (int) $qid, '_malka_pm_quote_url', true ) ) {
				continue;
			}

			$suyas[ (int) $qid ] = true;

			$d = Malka_CRM_Quote_Page::data( $qid );

			$cot[] = array(
				'id'       => (int) $qid,
				'numero'   => $d['numero'],
				'titulo'   => $d['titulo'],
				'total'    => (int) $d['total'],
				'estado'   => $d['estado'],
				'etiqueta' => self::rotulo_estado( $d['estado'] ),
				'url'      => $d['url'],
				'fecha'    => $d['emitida'],
			);

			// Sólo se cobra lo firmado (o aceptado): lo aceptado de palabra
			// todavía no se le muestra en «Por pagar».
			$de_palabra = ! empty( $d['palabra'] ) && in_array( (string) $d['estado'], array( 'sin_respuesta', 'caducada', '' ), true );
			$firma_q    = get_post_meta( (int) $qid, '_malka_pm_quote_sign', true );
			$firmada    = ( is_array( $firma_q ) && ! empty( $firma_q['rut'] ) ) || in_array( (string) $d['estado'], array( 'aceptada', 'en_curso', 'entregado', 'finalizada' ), true );
			if ( ! $firmada && ! $de_palabra ) {
				continue;
			}

			// El negocio enlazado, para que el cliente lo reconozca.
			$pid_n  = (int) get_post_meta( $qid, '_malka_pm_project_id', true );
			$neg_n  = $pid_n ? (string) get_post_meta( $pid_n, '_malka_pm_number', true ) : '';

			foreach ( $d['pagos'] as $p ) {
				// La fila «total» es el resumen de las otras: sumarla duplica.
				if ( Malka_CRM_Quotes::es_resumen( $qid, $p ) ) {
					continue;
				}
				// De palabra solo se cobra lo que ella fechó.
				if ( $de_palabra && ( empty( $p['due'] ) || ( isset( $p['disparo'] ) && 'entrega' === $p['disparo'] ) ) ) {
					continue;
				}

				$e = Malka_CRM_Quote_Page::payment_view( $qid, $p );

				// Sin firma, lo pendiente no se cobra todavía (lo pagado sí se muestra).
				if ( ! $firmada && 'paid' !== $e['state'] ) {
					continue;
				}

				if ( 'paid' === $e['state'] ) {
					// Lo ya pagado también se muestra: es su comprobante.
					$metodos   = class_exists( 'Malka_CRM_Quotes' ) ? Malka_CRM_Quotes::payment_methods() : array();
					$m         = isset( $p['method'] ) ? (string) $p['method'] : '';
					$pagados[] = array(
						'numero' => $d['numero'],
						'negocio' => $neg_n,
						'cuota'  => isset( $p['label'] ) ? (string) $p['label'] : '',
						'monto'  => (int) ( isset( $p['amount'] ) ? $p['amount'] : 0 ),
						'fecha'  => ! empty( $p['paid_at'] ) ? substr( (string) $p['paid_at'], 0, 10 ) : ( ! empty( $p['sumup_fecha'] ) ? substr( (string) $p['sumup_fecha'], 0, 10 ) : '' ),
						'medio'  => ( $m && isset( $metodos[ $m ] ) && 'none' !== $m ) ? $metodos[ $m ] : '',
						'titulo' => (string) $d['titulo'],
						'total'  => (int) $d['total'],
						'cobro'  => ! empty( $p['sumup_monto'] ) ? (int) round( (float) $p['sumup_monto'] ) : 0,
						'tx'     => ! empty( $p['sumup_tx'] ) ? (string) $p['sumup_tx'] : '',
					);
					continue;
				}

				$monto = (int) ( isset( $p['amount'] ) ? $p['amount'] : 0 );
				$debe += $monto;

				$cuotas[] = array(
					'numero'  => $d['numero'],
					'negocio' => $neg_n,
					'titulo'  => (string) $d['titulo'],
					'id'      => isset( $p['id'] ) ? (string) $p['id'] : '',
					'cuota'   => isset( $p['label'] ) ? (string) $p['label'] : '',
					'monto'   => $monto,
					'fecha'   => isset( $e['fecha'] ) ? $e['fecha'] : '',
					'espera'  => ! empty( $e['espera'] ),
					'avisado' => ! empty( $e['avisado'] ),
					'tarde'   => ( 'overdue' === $e['state'] ),
					'url'     => $d['url'],
				);
			}
		}

		// Los proyectos salen de sus cotizaciones, no se buscan aparte.
		foreach ( array_keys( $suyas ) as $qid ) {
			$pid = (int) get_post_meta( $qid, '_malka_pm_project_id', true );

			if ( ! $pid || ! get_post( $pid ) ) {
				continue;
			}

			$negocios[ $pid ] = array_merge(
				array(
					'id'     => $pid,
					'titulo' => get_the_title( $pid ),
					'avance' => class_exists( 'Malka_CRM_Helpers' ) ? (int) Malka_CRM_Helpers::get_project_progress( $pid ) : 0,
					'url'    => Malka_CRM_Quote_Page::public_url( $qid ),
					'fin'    => (string) get_post_meta( $pid, '_malka_pm_deadline', true ),
					'estado' => (string) get_post_meta( $pid, '_malka_pm_status', true ),
				),
				self::lectura_proyecto( $pid )
			);
		}

		return array(
			'cliente'  => $c,
			'cot'      => $cot,
			'negocios' => array_values( $negocios ),
			'cuotas'   => $cuotas,
			'pagados'  => $pagados,
			'docs'     => self::documentos_de( $c, $suyas ),
			'debe'     => $debe,
		);
	}

	/**
	 * Los documentos de venta que le tocan a este cliente.
	 *
	 * Primero por la cotización de la que salieron, que es lo seguro; después
	 * por RUT y por nombre, para los que se cargaron a mano.
	 *
	 * @param array $c     Cliente.
	 * @param array $suyas Cotizaciones suyas, como claves.
	 * @return array
	 */
	private static function documentos_de( $c, $suyas ) {
		if ( ! class_exists( 'Malka_CRM_Documents' ) ) {
			return array();
		}

		$rut     = Malka_CRM_Clients::solo_rut( $c['rut'] );
		$nombre  = Malka_CRM_Clients::plegar( $c['nombre'] );
		$empresa = Malka_CRM_Clients::plegar( $c['empresa'] );
		$fuera   = array();

		foreach ( Malka_CRM_Documents::all() as $d ) {
			if ( 'venta' !== $d['sentido'] || 'anulada' === $d['estado'] ) {
				continue;
			}

			$suyo = ! empty( $d['quote'] ) && isset( $suyas[ (int) $d['quote'] ] );

			if ( ! $suyo && $rut ) {
				$suyo = ( Malka_CRM_Clients::solo_rut( $d['rut'] ) === $rut );
			}

			if ( ! $suyo && $d['cliente'] ) {
				$quien = Malka_CRM_Clients::plegar( $d['cliente'] );
				$suyo  = ( $nombre && $quien === $nombre ) || ( $empresa && $quien === $empresa );
			}

			if ( ! $suyo ) {
				continue;
			}

			$fuera[] = array(
				'rotulo' => $d['rotulo'],
				'folio'  => $d['folio'],
				'fecha'  => $d['fecha'],
				'total'  => (int) $d['total'],
				'pdf'    => Malka_CRM_Documents::tiene_archivo( $d['id'] ) ? Malka_CRM_Documents::url_pdf( $d['id'] ) : '',
				'ver'    => Malka_CRM_Documents::tiene_archivo( $d['id'] ) ? Malka_CRM_Documents::url_pdf( $d['id'], true ) : '',
				'vista'  => Malka_CRM_Documents::url_ver( $d['id'] ),
				'vista_imp' => Malka_CRM_Documents::url_ver( $d['id'], 'imprimir' ),
			);
		}

		return $fuera;
	}

	/**
	 * El nombre de un estado de cotización.
	 *
	 * @param string $k Estado.
	 * @return string
	 */
	private static function rotulo_estado( $k ) {
		$todos = class_exists( 'Malka_CRM_Quotes' ) ? Malka_CRM_Quotes::quote_statuses() : array();

		return isset( $todos[ $k ] ) ? $todos[ $k ] : (string) $k;
	}

	/**
	 * Cuándo hay que pagar una cuota, en palabras.
	 *
	 * @param array $q Cuota ya resuelta.
	 * @return string
	 */
	private static function cuando( $q ) {
		if ( $q['avisado'] ) {
			return __( 'Avisaste que lo pagaste: lo estamos confirmando', 'malka-crm' );
		}

		if ( $q['espera'] ) {
			return __( 'Se cobra al entregar el proyecto', 'malka-crm' );
		}

		if ( $q['fecha'] ) {
			return sprintf(
				/* translators: fecha de vencimiento */
				__( 'Vence el %s', 'malka-crm' ),
				date_i18n( 'd/m/Y', strtotime( $q['fecha'] ) )
			);
		}

		return __( 'Sin fecha', 'malka-crm' );
	}

	/**
	 * Una fila de cualquiera de las listas.
	 *
	 * @param array $f Fila {clase, titulo, bajo, derecha, barra, url, accion}.
	 * @return string
	 */
	private static function fila( $f ) {
		$f = array_merge(
			array(
				'clase'   => '',
				'titulo'  => '',
				'bajo'    => '',
				'derecha' => '',
				'barra'   => null,
				'url'     => '',
				'accion'  => '',
			),
			$f
		);

		$out = sprintf( '<li class="mp-fila%s">', $f['clase'] ? ' ' . esc_attr( $f['clase'] ) : '' );

		$out .= sprintf(
			'<span class="mp-fila__n"><b>%1$s</b>%2$s</span>',
			esc_html( $f['titulo'] ),
			$f['bajo'] ? '<i>' . esc_html( $f['bajo'] ) . '</i>' : ''
		);

		if ( null !== $f['barra'] ) {
			$out .= sprintf(
				'<span class="mp-fila__b"><span class="mp-bar" role="img" aria-label="%1$s"><i style="width:%2$d%%"></i></span></span>',
				esc_attr( sprintf( /* translators: porcentaje */ __( '%d%% avanzado', 'malka-crm' ), (int) $f['barra'] ) ),
				(int) $f['barra']
			);
		}

		if ( ! empty( $f['pill'] ) ) {
			$out .= sprintf( '<span class="mp-pill%1$s">%2$s</span>', ! empty( $f['pill_c'] ) ? ' is-' . esc_attr( $f['pill_c'] ) : '', esc_html( $f['pill'] ) );
		}

		if ( '' !== $f['derecha'] ) {
			$out .= sprintf( '<span class="mp-fila__m">%s</span>', esc_html( $f['derecha'] ) );
		}

		if ( $f['url'] && $f['accion'] ) {
			$out .= sprintf(
				'<a class="mp-fila__a" href="%1$s">%2$s</a>',
				esc_url( $f['url'] ),
				esc_html( $f['accion'] )
			);
		}

		return $out . '</li>';
	}

	/**
	 * Lo que el cliente quiere saber de su proyecto, leído de sus tareas: en
	 * qué etapa va, qué viene, qué espera su aprobación y qué le toca a él.
	 * Las tareas marcadas «sólo para el estudio» no se cuentan.
	 *
	 * @param int $pid Negocio.
	 * @return array {etapa, proxima, aprobar[], suyas[]}
	 */
	private static function lectura_proyecto( $pid ) {
		$out = array(
			'etapa'   => '',
			'proxima' => null,
			'aprobar' => array(),
			'suyas'   => array(),
			'hechas'  => array(),
		);

		if ( ! class_exists( 'Malka_CRM_Helpers' ) ) {
			return $out;
		}

		$todas  = Malka_CRM_Helpers::get_project_tasks( $pid );
		$madres = array();
		foreach ( $todas as $t ) {
			if ( ! empty( $t['number'] ) && 'stage' !== ( isset( $t['type'] ) ? $t['type'] : '' ) && empty( $t['privada'] ) ) {
				$madres[ (string) $t['number'] ] = trim( (string) $t['name'] );
			}
		}
		foreach ( $todas as $t ) {
			if ( ! empty( $t['privada'] ) ) {
				continue;
			}
			$nombre = isset( $t['name'] ) ? (string) $t['name'] : '';
			$num_m  = ! empty( $t['number'] ) ? (string) Malka_CRM_Helpers::number_parent( $t['number'] ) : '';
			$madre  = ( '' !== $num_m && ! empty( $madres[ $num_m ] ) ) ? $madres[ $num_m ] : '';
			// Hecha es sólo la que se marcó con el círculo: el avance al 100 %
			// (p. ej. por sus subtareas) no la saca de la vista.
			$hecha  = ! empty( $t['done'] ) || ( 'stage' === ( isset( $t['type'] ) ? $t['type'] : '' ) && isset( $t['progress'] ) && (int) $t['progress'] >= 100 );

			if ( 'stage' === ( isset( $t['type'] ) ? $t['type'] : '' ) ) {
				if ( '' === $out['etapa'] && ! $hecha ) {
					$out['etapa'] = $nombre;
				}
				continue;
			}

			$quien = isset( $t['owner'] ) ? $t['owner'] : 'studio';

			// Lo que espera su visto bueno: terminado por el estudio, sin aprobar.
			if ( ! empty( $t['needs_approval'] ) && $hecha && class_exists( 'Malka_CRM_Approvals' ) ) {
				$ap = Malka_CRM_Approvals::get_approval( $pid, isset( $t['id'] ) ? $t['id'] : '' );
				if ( 'approved' !== $ap['status'] ) {
					$out['aprobar'][] = array( 'nombre' => $nombre, 'estado' => $ap['status'] );
				} else {
					$out['hechas'][] = array( 'nombre' => $nombre, 'que' => __( 'Aprobaste', 'malka-crm' ), 'fecha' => substr( (string) $ap['date'], 0, 10 ) );
				}
				continue;
			}

			if ( $hecha ) {
				if ( in_array( $quien, array( 'client', 'both' ), true ) ) {
					$out['hechas'][] = array( 'nombre' => $nombre, 'que' => __( 'Listo', 'malka-crm' ), 'fecha' => isset( $t['done_at'] ) ? substr( (string) $t['done_at'], 0, 10 ) : '' );
				}
				continue;
			}

			// Una publicación que publica el cliente: le toca a él publicarla.
			if ( ! in_array( $quien, array( 'client', 'both' ), true ) && isset( $t['pub_quien'] ) && 'client' === $t['pub_quien'] ) {
				$f_pub         = ! empty( $t['pub_fecha'] ) ? substr( (string) $t['pub_fecha'], 0, 10 ) : ( isset( $t['end'] ) ? (string) $t['end'] : '' );
				$out['suyas'][] = array( 'nombre' => sprintf( /* translators: publicación */ __( 'Publicar: %s', 'malka-crm' ), self::limpio( $nombre ) ), 'fecha' => $f_pub, 'id' => isset( $t['id'] ) ? (string) $t['id'] : '', 'pid' => (int) $pid, 'notas' => '', 'nota_cliente' => isset( $t['nota_cliente'] ) ? (string) $t['nota_cliente'] : '', 'madre' => '' );
			}

			// Lo que le toca a él (o a los dos) y todavía no está.
			if ( in_array( $quien, array( 'client', 'both' ), true ) ) {
				$out['suyas'][] = array( 'nombre' => $nombre, 'fecha' => isset( $t['end'] ) ? (string) $t['end'] : '', 'id' => isset( $t['id'] ) ? (string) $t['id'] : '', 'pid' => (int) $pid, 'notas' => isset( $t['notes'] ) ? (string) $t['notes'] : '', 'nota_cliente' => isset( $t['nota_cliente'] ) ? (string) $t['nota_cliente'] : '', 'madre' => $madre );
				continue;
			}

			// La próxima entrega del estudio, la de fecha más cercana.
			$fin = isset( $t['end'] ) ? (string) $t['end'] : '';
			if ( null === $out['proxima'] || ( $fin && ( ! $out['proxima']['fecha'] || $fin < $out['proxima']['fecha'] ) ) ) {
				$out['proxima'] = array( 'nombre' => $nombre, 'fecha' => $fin );
			}
		}

		return $out;
	}

	/**
	 * Una fecha corta y amable: «24 oct».
	 *
	 * @param string $f Y-m-d.
	 * @return string
	 */
	private static function dia( $f ) {
		return $f ? date_i18n( 'j M', strtotime( $f ) ) : '';
	}

	/**
	 * El título de una cuota en el portal: la cotización a la que pertenece.
	 *
	 * @param array $q Cuota.
	 * @return string
	 */
	private static function titulo_cuota( $q ) {
		/* translators: número de cotización */
		return sprintf( __( 'Cotización N° %s', 'malka-crm' ), $q['numero'] );
	}

	/**
	 * El detalle de una cuota: cuál es, el negocio y la fecha.
	 *
	 * @param array  $q      Cuota.
	 * @param string $cuando Fecha o estado ya escrito.
	 * @return string
	 */
	private static function detalle_cuota( $q, $cuando ) {
		$partes = array( $q['cuota'] ? $q['cuota'] : __( 'Cuota', 'malka-crm' ) );
		if ( ! empty( $q['negocio'] ) ) {
			/* translators: número del negocio */
			$partes[] = sprintf( __( 'Negocio %s', 'malka-crm' ), $q['negocio'] );
		}
		$tit = trim( (string) $q['titulo'], " \t·-–" );
		if ( '' !== $tit ) {
			$partes[] = $tit;
		}
		if ( '' !== trim( (string) $cuando ) ) {
			$partes[] = $cuando;
		}
		return implode( ' · ', $partes );
	}

	/**
	 * Una fila de las listas del portal: icono, qué es, estado, monto y acción.
	 *
	 * @param string $ic     Letra del icono.
	 * @param string $titulo Título.
	 * @param string $bajo   Detalle.
	 * @param string $st     HTML del estado (o '').
	 * @param string $monto  HTML de la cifra (o '').
	 * @param string $accion HTML del botón (o '').
	 * @param string $clase  Clase extra del icono.
	 * @return string
	 */
	/**
	 * Una tarea que le toca al cliente: casilla para darla por lista, el
	 * nombre, el proyecto y la fecha en la misma línea. Al tocarla se abre
	 * su detalle en una ventana.
	 *
	 * @param int    $cid      Cliente.
	 * @param array  $x        Tarea.
	 * @param string $proyecto Nombre del proyecto.
	 * @return string
	 */
	/**
	 * La tarea completa (del proyecto) de una fila del cliente.
	 *
	 * @param int    $pid Proyecto.
	 * @param string $tid Tarea.
	 * @return array|null
	 */
	private static function tarea_por_id( $pid, $tid ) {
		static $cache = array();
		$pid = (int) $pid;
		if ( ! isset( $cache[ $pid ] ) ) {
			$cache[ $pid ] = class_exists( 'Malka_CRM_Helpers' ) ? Malka_CRM_Helpers::get_project_tasks( $pid ) : array();
		}
		foreach ( $cache[ $pid ] as $t ) {
			if ( isset( $t['id'] ) && (string) $t['id'] === (string) $tid ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * El botón para grabar una respuesta (y lo ya grabado).
	 *
	 * @param int   $cid Cliente.
	 * @param int   $pid Proyecto.
	 * @param array $t   Tarea.
	 * @return string
	 */
	private static function caja_respuesta( $cid, $x ) {
		$t    = self::tarea_por_id( $x['pid'], $x['id'] );
		$modo = $t && isset( $t['respuesta'] ) ? (string) $t['respuesta'] : '';
		if ( 'no' === $modo ) {
			return '';
		}
		$preg = $t && ! empty( $t['respuesta_preg'] ) ? (string) $t['respuesta_preg'] : '';
		$rot  = '' !== $preg ? $preg : ( 'audio' === $modo ? __( 'Déjanos una grabación', 'malka-crm' ) : __( 'Déjanos una nota', 'malka-crm' ) );
		$out  = '<div class="esc-pdlg__nota' . ( '' !== $modo ? ' is-pedida' : '' ) . '"><label class="esc-pdlg__k" for="nota-' . esc_attr( $x['id'] ) . '">' . esc_html( $rot ) . '</label>';
		if ( 'audio' !== $modo ) {
			$out .= '<textarea id="nota-' . esc_attr( $x['id'] ) . '" data-nota rows="3" placeholder="' . esc_attr( '' === $modo ? __( 'Opcional: dónde lo dejaste, una duda, algo que debamos saber…', 'malka-crm' ) : __( 'Escríbenos aquí…', 'malka-crm' ) ) . '">' . esc_textarea( isset( $x['nota_cliente'] ) ? $x['nota_cliente'] : '' ) . '</textarea>';
			if ( ! empty( $x['nota_cliente'] ) ) {
				$out .= '<small class="esc-pdlg__ok">' . esc_html__( 'Ya recibimos esta nota. Puedes cambiarla.', 'malka-crm' ) . '</small>';
			}
		}
		if ( in_array( $modo, array( 'audio', 'ambas' ), true ) && $t ) {
			$out .= ( 'ambas' === $modo ? '<p class="esc-grab__o">' . esc_html__( 'o grábala:', 'malka-crm' ) . '</p>' : '' ) . self::grabador_resp( $cid, $x['pid'], $t );
		}
		return $out . '</div>';
	}

	/**
	 * El botón para grabar una respuesta.
	 *
	 * @param int   $cid Cliente.
	 * @param int   $pid Proyecto.
	 * @param array $t   Tarea.
	 * @return string
	 */
	private static function grabador_resp( $cid, $pid, $t ) {
		return sprintf(
			'<div class="esc-grab" data-grab data-cliente="%1$d" data-pid="%2$d" data-tid="%3$s" data-nonce="%4$s" data-ajax="%9$s">%5$s' .
			'<button type="button" class="esc-btn esc-btn--soft esc-grab__rec" data-grab-rec><i aria-hidden="true"></i><span>%6$s</span></button>' .
			'<span class="esc-grab__est" data-grab-est></span>' .
			'<span class="esc-grab__fin" data-grab-fin hidden><audio controls data-grab-audio></audio><button type="button" class="esc-btn esc-btn--soft" data-grab-otra>%7$s</button><button type="button" class="esc-btn esc-btn--primary" data-grab-enviar>%8$s</button></span></div>',
			(int) $cid,
			(int) $pid,
			esc_attr( isset( $t['id'] ) ? $t['id'] : '' ),
			esc_attr( wp_create_nonce( 'malka_crm_portal_lista_' . (int) $cid ) ),
			! empty( $t['resp_audio'] ) ? '<span class="esc-grab__ya"><small>' . esc_html__( 'Tu grabación', 'malka-crm' ) . '</small><audio controls preload="none" src="' . esc_url( $t['resp_audio'] ) . '"></audio></span>' : '',
			esc_html( ! empty( $t['resp_audio'] ) ? __( 'Grabar de nuevo', 'malka-crm' ) : __( 'Grabar una respuesta', 'malka-crm' ) ),
			esc_html__( 'Volver a grabar', 'malka-crm' ),
			esc_html__( 'Enviar grabación', 'malka-crm' ),
			esc_url( admin_url( 'admin-ajax.php' ) )
		);
	}

	private static function pub_de_tarea( $cid, $x ) {
		if ( empty( $x['id'] ) || empty( $x['pid'] ) || ! class_exists( 'Malka_CRM_Helpers' ) ) {
			return '';
		}
		static $cache = array();
		$pid = (int) $x['pid'];
		if ( ! isset( $cache[ $pid ] ) ) {
			$cache[ $pid ] = Malka_CRM_Helpers::get_project_tasks( $pid );
		}
		foreach ( $cache[ $pid ] as $t ) {
			if ( isset( $t['id'] ) && (string) $t['id'] === (string) $x['id'] ) {
				if ( ! self::tiene_pub( $t ) ) {
					return '';
				}
				$ctx = array( 'cid' => (int) $cid, 'pid' => $pid, 'nonce' => wp_create_nonce( 'malka_crm_portal_lista_' . (int) $cid ) );
				return '<div class="esc-pdlg__pub">' . self::tarjeta_pub( $t, $ctx, false ) . '</div>';
			}
		}
		return '';
	}

	/**
	 * Una tarea del cliente (fila con su ventana).
	 *
	 * @param int    $cid      Cliente.
	 * @param array  $x        Tarea.
	 * @param string $proyecto Nombre del proyecto.
	 * @return string
	 */
	private static function tarea_cliente( $cid, $x, $proyecto ) {
		$hoy   = current_time( 'Y-m-d' );
		$tarde = $x['fecha'] && $x['fecha'] < $hoy;
		$fecha = $x['fecha']
			? ( $tarde
				/* translators: fecha */
				? sprintf( __( 'Venció el %s', 'malka-crm' ), self::dia( $x['fecha'] ) )
				: ( $x['fecha'] === $hoy ? __( 'Para hoy', 'malka-crm' ) : sprintf( /* translators: fecha */ __( 'Para el %s', 'malka-crm' ), self::dia( $x['fecha'] ) ) ) )
			: '';
		$cal   = '<svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="3.5" width="11" height="10" rx="1.5"/><path d="M2.5 6.5h11M5.5 2v3M10.5 2v3"/></svg>';

		$madre = isset( $x['madre'] ) ? trim( (string) $x['madre'] ) : '';
		$dl    = '<div><dt>' . esc_html__( 'Proyecto', 'malka-crm' ) . '</dt><dd>' . esc_html( $proyecto ) . '</dd></div>';
		if ( '' !== $madre ) {
			$dl .= '<div><dt>' . esc_html__( 'Es parte de', 'malka-crm' ) . '</dt><dd>' . esc_html( $madre ) . '</dd></div>';
		}
		$dl .= '<div><dt>' . esc_html__( 'Fecha', 'malka-crm' ) . '</dt><dd>' . esc_html( $x['fecha'] ? date_i18n( 'j \d\e F', strtotime( $x['fecha'] ) ) . ( $tarde ? ' · ' . __( 'atrasada', 'malka-crm' ) : '' ) : __( 'Sin fecha', 'malka-crm' ) ) . '</dd></div>';
		$notas = trim( (string) ( isset( $x['notas'] ) ? $x['notas'] : '' ) );

		return sprintf(
			'<div class="esc-tc%1$s"><span class="esc-tc__ck">%2$s</span>' .
			'<div class="esc-tc__t" data-detalle role="button" tabindex="0"><b>%3$s</b><span class="esc-tc__m">%4$s%5$s</span></div>' .
			'<span class="esc-tc__ir" aria-hidden="true">›</span>' .
			'<dialog class="esc-pdlg"><div class="esc-pdlg__h"><div><span class="esc-pdlg__pre is-%6$s">%7$s</span><h3>%3$s</h3></div><button type="button" class="esc-pdlg__x" data-cerrar aria-label="%8$s">×</button></div>' .
			'<dl>%9$s</dl>%10$s' .
			'%11$s</dialog></div>',
			$tarde ? ' is-tarde' : '',
			self::casilla_lista( $cid, $x ),
			esc_html( $x['nombre'] ),
			( '' !== $madre ? '<span class="esc-tc__madre">' . esc_html( $madre ) . '</span><span class="esc-tc__p">' . esc_html( $proyecto ) . '</span>' : esc_html( $proyecto ) ),
			$fecha ? '<span class="esc-tc__f">' . $cal . esc_html( $fecha ) . '</span>' : '',
			$tarde ? 'late' : 'wait',
			esc_html( $tarde ? __( 'Atrasada', 'malka-crm' ) : __( 'Te toca a ti', 'malka-crm' ) ),
			esc_attr__( 'Cerrar', 'malka-crm' ),
			$dl,
			( $pub_x = self::pub_de_tarea( $cid, $x ) ) . ( '' !== $notas && ( '' === $pub_x || false === strpos( $pub_x, esc_html( $notas ) ) ) ? '<div class="esc-pdlg__n"><p class="esc-pdlg__k">' . esc_html__( 'Lo que necesitamos', 'malka-crm' ) . '</p><p>' . nl2br( esc_html( $notas ) ) . '</p></div>' : '' ),
			( ! empty( $x['id'] ) && ! empty( $x['pid'] ) )
				? sprintf(
					'%11$s' .
					'<div class="esc-pdlg__pie"><button type="button" class="esc-btn esc-btn--soft" data-nota-enviar data-cliente="%1$d" data-pid="%2$d" data-tid="%3$s" data-nonce="%4$s" hidden>%10$s</button>' .
					'<button type="button" class="esc-btn esc-btn--primary" data-lista data-cliente="%1$d" data-pid="%2$d" data-tid="%3$s" data-nonce="%4$s" data-cerrar-al-listo>%5$s</button>',
					(int) $cid,
					(int) $x['pid'],
					esc_attr( $x['id'] ),
					esc_attr( wp_create_nonce( 'malka_crm_portal_lista_' . (int) $cid ) ),
					esc_html__( 'Marcar como listo', 'malka-crm' ),
					esc_html__( 'Déjanos una nota', 'malka-crm' ),
					esc_attr__( 'Opcional: dónde lo dejaste, una duda, algo que debamos saber…', 'malka-crm' ),
					esc_textarea( isset( $x['nota_cliente'] ) ? $x['nota_cliente'] : '' ),
					! empty( $x['nota_cliente'] ) ? '<small class="esc-pdlg__ok">' . esc_html__( 'Ya recibimos esta nota. Puedes cambiarla.', 'malka-crm' ) . '</small>' : '',
					esc_html__( 'Enviar sólo la nota', 'malka-crm' ),
					self::caja_respuesta( $cid, $x )
				) . '</div>'
				: ''
		);
	}

	/**
	 * La casilla para marcar una tarea suya como lista.
	 *
	 * @param int   $cid Cliente.
	 * @param array $x   Tarea.
	 * @return string
	 */
	private static function casilla_lista( $cid, $x ) {
		if ( empty( $x['id'] ) || empty( $x['pid'] ) ) {
			return '!';
		}
		return sprintf(
			'<button type="button" class="esc-ck" data-lista data-cliente="%1$d" data-pid="%2$d" data-tid="%3$s" data-nonce="%4$s" title="%5$s" aria-label="%5$s"><svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7"/></svg></button>',
			(int) $cid,
			(int) $x['pid'],
			esc_attr( $x['id'] ),
			esc_attr( wp_create_nonce( 'malka_crm_portal_lista_' . (int) $cid ) ),
			esc_attr__( 'Marcar como listo', 'malka-crm' )
		);
	}

	/**
	 * El cliente marca una tarea suya como lista, desde su portal.
	 */
	public static function ajax_lista() {
		$cid = isset( $_POST['cliente'] ) ? absint( $_POST['cliente'] ) : 0;
		$pid = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$tid = isset( $_POST['tid'] ) ? sanitize_text_field( wp_unslash( $_POST['tid'] ) ) : '';
		if ( ! $cid || ! check_ajax_referer( 'malka_crm_portal_lista_' . $cid, 'nonce', false ) || ! self::entrado( $cid ) ) {
			wp_send_json_error( array( 'message' => __( 'Tu sesión caducó. Vuelve a entrar con tu código.', 'malka-crm' ) ), 403 );
		}
		$d    = self::datos( $cid );
		$suyo = false;
		foreach ( $d['negocios'] as $n ) {
			if ( (int) $n['id'] === $pid ) {
				$suyo = true;
			}
		}
		if ( ! $suyo ) {
			wp_send_json_error( array( 'message' => __( 'Ese proyecto no es tuyo.', 'malka-crm' ) ), 403 );
		}
		$tareas = Malka_CRM_Helpers::get_project_tasks( $pid );
		$hecha  = null;
		foreach ( $tareas as $i => $t ) {
			if ( isset( $t['id'] ) && (string) $t['id'] === $tid && ( in_array( isset( $t['owner'] ) ? $t['owner'] : '', array( 'client', 'both' ), true ) || ( isset( $t['pub_quien'] ) && 'client' === $t['pub_quien'] ) ) ) {
				$tareas[ $i ]['progress'] = 100;
				$tareas[ $i ]['done']     = 1;
				$tareas[ $i ]['done_at']  = current_time( 'mysql' );
				$tareas[ $i ]['done_by']  = 'client';
				$nota = isset( $_POST['nota'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nota'] ) ) : '';
				if ( '' !== trim( $nota ) ) {
					$tareas[ $i ]['nota_cliente']    = $nota;
					$tareas[ $i ]['nota_cliente_at'] = current_time( 'mysql' );
				}
				$hecha                    = $tareas[ $i ];
				break;
			}
		}
		if ( ! $hecha ) {
			wp_send_json_error( array( 'message' => __( 'No encontré esa tarea.', 'malka-crm' ) ), 404 );
		}
		Malka_CRM_Helpers::save_tasks( $pid, $tareas );
		if ( class_exists( 'Malka_CRM_Approvals' ) ) {
			Malka_CRM_Approvals::track_completions( $pid );
		}
		do_action( 'malka_crm_client_task_done', $pid, $hecha, Malka_CRM_Helpers::days_to_deadline( $hecha ) < 0 );
		wp_send_json_success( array( 'message' => __( '¡Gracias! Ya le avisamos al estudio.', 'malka-crm' ) ) );
	}

	/**
	 * El cliente sube la voz en off que grabó desde su portal.
	 */
	public static function ajax_voz() {
		$cid = isset( $_POST['cliente'] ) ? absint( $_POST['cliente'] ) : 0;
		$pid = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$tid = isset( $_POST['tid'] ) ? sanitize_text_field( wp_unslash( $_POST['tid'] ) ) : '';
		if ( ! $cid || ! check_ajax_referer( 'malka_crm_portal_lista_' . $cid, 'nonce', false ) || ! self::entrado( $cid ) ) {
			wp_send_json_error( array( 'message' => __( 'Tu sesión caducó. Vuelve a entrar con tu código.', 'malka-crm' ) ), 403 );
		}
		$suyo = false;
		foreach ( self::datos( $cid )['negocios'] as $n ) {
			$suyo = $suyo || (int) $n['id'] === $pid;
		}
		if ( ! $suyo || empty( $_FILES['audio']['tmp_name'] ) || ! is_uploaded_file( $_FILES['audio']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_send_json_error( array( 'message' => __( 'No llegó la grabación. Intenta de nuevo.', 'malka-crm' ) ), 400 );
		}
		$tam = (int) $_FILES['audio']['size'];
		if ( $tam <= 0 || $tam > 30 * MB_IN_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'La grabación es demasiado larga.', 'malka-crm' ) ), 400 );
		}
		$tipo = isset( $_FILES['audio']['type'] ) ? strtolower( (string) $_FILES['audio']['type'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ext  = false !== strpos( $tipo, 'mp4' ) || false !== strpos( $tipo, 'aac' ) ? 'm4a' : ( false !== strpos( $tipo, 'ogg' ) ? 'ogg' : 'webm' );
		$tareas = Malka_CRM_Helpers::get_project_tasks( $pid );
		$pos    = null;
		foreach ( $tareas as $i => $t ) {
			if ( isset( $t['id'] ) && (string) $t['id'] === $tid ) {
				$pos = $i;
			}
		}
		if ( null === $pos ) {
			wp_send_json_error( array( 'message' => __( 'No encontré esa publicación.', 'malka-crm' ) ), 404 );
		}
		$resp   = isset( $_POST['para'] ) && 'resp' === $_POST['para'];
		$nombre = sanitize_file_name( ( $resp ? 'respuesta-' : 'voz-en-off-' ) . $pid . '-' . $tid . '-' . gmdate( 'Ymd-His' ) . '.' . $ext );
		$up     = wp_upload_bits( $nombre, null, file_get_contents( $_FILES['audio']['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.Security.ValidatedSanitizedInput
		if ( ! empty( $up['error'] ) ) {
			wp_send_json_error( array( 'message' => $up['error'] ), 500 );
		}
		if ( $resp ) {
			$tareas[ $pos ]['resp_audio']    = $up['url'];
			$tareas[ $pos ]['resp_audio_at'] = current_time( 'mysql' );
		} else {
			$tareas[ $pos ]['pub_audio']    = $up['url'];
			$tareas[ $pos ]['pub_audio_at'] = current_time( 'mysql' );
		}
		Malka_CRM_Helpers::save_tasks( $pid, $tareas );
		do_action( 'malka_crm_client_task_nota', $pid, array_merge( $tareas[ $pos ], array( 'nota_cliente' => ( $resp ? __( 'Te dejó una grabación. Escúchala en la tarea:', 'malka-crm' ) : __( 'Grabó la voz en off. Escúchala en la tarea:', 'malka-crm' ) ) . ' ' . $up['url'] ) ) );
		wp_send_json_success(
			array(
				'url'     => $up['url'],
				'message' => __( '¡Listo! Le llegó tu grabación al estudio.', 'malka-crm' ),
			)
		);
	}

	/**
	 * Una huella de todo lo que ve el cliente: si cambia, su página se pone
	 * al día sola.
	 *
	 * @param int $cid Cliente.
	 * @return string
	 */
	public static function version( $cid ) {
		// Mientras nada cambie en la plataforma, la huella sale de la memoria
		// (así se puede preguntar cada pocos segundos sin cargar el sitio).
		$rev   = (string) get_option( 'malka_crm_rev', '0' );
		$cache = get_transient( 'malka_crm_pv_' . (int) $cid );
		if ( is_array( $cache ) && isset( $cache['rev'], $cache['v'] ) && $cache['rev'] === $rev ) {
			return $cache['v'];
		}
		$v = self::calcular_version( $cid );
		set_transient( 'malka_crm_pv_' . (int) $cid, array( 'rev' => $rev, 'v' => $v ), DAY_IN_SECONDS );
		return $v;
	}

	/**
	 * Cualquier cambio en lo del CRM sube el contador: los portales abiertos
	 * se enteran a la siguiente pregunta.
	 *
	 * @param int $id Post.
	 */
	public static function tocar( $id = 0 ) {
		static $hecho = false;
		if ( $hecho ) {
			return;
		}
		$tipo = $id ? get_post_type( (int) $id ) : '';
		if ( $id && ( ! $tipo || 0 !== strpos( $tipo, 'malka_' ) ) ) {
			return;
		}
		$hecho = true;
		update_option( 'malka_crm_rev', (string) microtime( true ), true );
	}

	/**
	 * Engancha el contador.
	 */
	public static function enganchar_rev() {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $h ) {
			add_action(
				$h,
				function ( $mid, $obj ) {
					Malka_CRM_Portal::tocar( (int) $obj );
				},
				10,
				2
			);
		}
		add_action(
			'save_post',
			function ( $id ) {
				Malka_CRM_Portal::tocar( (int) $id );
			}
		);
		add_action(
			'trashed_post',
			function ( $id ) {
				Malka_CRM_Portal::tocar( (int) $id );
			}
		);
		add_action(
			'updated_option',
			function ( $o ) {
				if ( 0 === strpos( (string) $o, 'malka_crm_settings' ) ) {
					Malka_CRM_Portal::tocar();
				}
			}
		);
	}

	/**
	 * La huella de verdad.
	 *
	 * @param int $cid Cliente.
	 * @return string
	 */
	private static function calcular_version( $cid ) {
		$d    = self::datos( $cid );
		$mas  = array();
		foreach ( $d['negocios'] as $n ) {
			$mas[] = get_post_meta( (int) $n['id'], '_malka_pm_tasks', true );
			$mas[] = get_post_meta( (int) $n['id'], '_malka_pm_enlaces', true );
		}
		$mas[] = class_exists( 'Malka_CRM_Ideas' ) ? Malka_CRM_Ideas::visibles( $cid ) : array();
		return md5( wp_json_encode( array( $d, $mas ) ) );
	}

	/**
	 * La huella actual, para la página abierta.
	 */
	public static function ajax_version() {
		$cid = isset( $_POST['cliente'] ) ? absint( $_POST['cliente'] ) : 0;
		if ( ! $cid || ! check_ajax_referer( 'malka_crm_portal_lista_' . $cid, 'nonce', false ) || ! self::entrado( $cid ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( array( 'v' => self::version( $cid ) ) );
	}

	/**
	 * El cliente pide empezar con un próximo proyecto.
	 */
	public static function ajax_empezar() {
		$cid = isset( $_POST['cliente'] ) ? absint( $_POST['cliente'] ) : 0;
		$iid = isset( $_POST['idea'] ) ? sanitize_key( wp_unslash( $_POST['idea'] ) ) : '';
		if ( ! $cid || ! check_ajax_referer( 'malka_crm_portal_lista_' . $cid, 'nonce', false ) || ! self::entrado( $cid ) ) {
			wp_send_json_error( array( 'message' => __( 'Tu sesión caducó. Vuelve a entrar con tu código.', 'malka-crm' ) ), 403 );
		}
		$idea = class_exists( 'Malka_CRM_Ideas' ) ? Malka_CRM_Ideas::pedir( $cid, $iid, isset( $_POST['nota'] ) ? wp_unslash( $_POST['nota'] ) : '' ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! $idea ) {
			wp_send_json_error( array( 'message' => __( 'No encontré ese proyecto.', 'malka-crm' ) ), 404 );
		}
		wp_send_json_success( array( 'message' => __( '¡Listo! Le avisamos al estudio. Te contactamos pronto.', 'malka-crm' ) ) );
	}

	/**
	 * El cliente deja (o cambia) una nota en su tarea, sin darla por lista.
	 */
	public static function ajax_nota() {
		$cid  = isset( $_POST['cliente'] ) ? absint( $_POST['cliente'] ) : 0;
		$pid  = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
		$tid  = isset( $_POST['tid'] ) ? sanitize_text_field( wp_unslash( $_POST['tid'] ) ) : '';
		$nota = isset( $_POST['nota'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nota'] ) ) : '';
		if ( ! $cid || ! check_ajax_referer( 'malka_crm_portal_lista_' . $cid, 'nonce', false ) || ! self::entrado( $cid ) ) {
			wp_send_json_error( array( 'message' => __( 'Tu sesión caducó. Vuelve a entrar con tu código.', 'malka-crm' ) ), 403 );
		}
		$suyo = false;
		foreach ( self::datos( $cid )['negocios'] as $n ) {
			if ( (int) $n['id'] === $pid ) {
				$suyo = true;
			}
		}
		if ( ! $suyo || '' === trim( $nota ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo enviar la nota.', 'malka-crm' ) ), 400 );
		}
		$tareas = Malka_CRM_Helpers::get_project_tasks( $pid );
		$hecha  = null;
		foreach ( $tareas as $i => $t ) {
			if ( isset( $t['id'] ) && (string) $t['id'] === $tid && ( in_array( isset( $t['owner'] ) ? $t['owner'] : '', array( 'client', 'both' ), true ) || ( isset( $t['pub_quien'] ) && 'client' === $t['pub_quien'] ) ) ) {
				$tareas[ $i ]['nota_cliente']    = $nota;
				$tareas[ $i ]['nota_cliente_at'] = current_time( 'mysql' );
				$hecha                           = $tareas[ $i ];
				break;
			}
		}
		if ( ! $hecha ) {
			wp_send_json_error( array( 'message' => __( 'No encontré esa tarea.', 'malka-crm' ) ), 404 );
		}
		Malka_CRM_Helpers::save_tasks( $pid, $tareas );
		do_action( 'malka_crm_client_task_nota', $pid, $hecha );
		wp_send_json_success( array( 'message' => __( '¡Gracias! Le llegó tu nota al estudio.', 'malka-crm' ) ) );
	}

	private static function fila_p( $ic, $titulo, $bajo, $st = '', $monto = '', $accion = '', $clase = '' ) {
		// El estado como antetítulo sobre el nombre, igual que en las tarjetas.
		$pre = '';
		if ( $st && preg_match( '/esc-st--([a-z]+)[^>]*>(.*?)<\/span>/s', $st, $m ) ) {
			$pre = '<span class="esc-pk__pre is-' . esc_attr( $m[1] ) . '">' . wp_strip_all_tags( $m[2] ) . '</span>';
			$st  = '';
		}
		return sprintf(
			'<div class="esc-pr"><i class="esc-ct__ic%1$s" aria-hidden="true">%2$s</i><span class="esc-ct__t">%8$s<b>%3$s</b><span>%4$s</span></span>%5$s%6$s%7$s</div>',
			$clase ? ' ' . esc_attr( $clase ) : '',
			0 === strpos( (string) $ic, '<' ) ? $ic : esc_html( $ic ),
			esc_html( $titulo ),
			esc_html( $bajo ),
			$st ? $st : '<span></span>',
			$monto ? '<span class="esc-ct__m"><b>' . $monto . '</b></span>' : '<span></span>',
			$accion ? $accion : '<span></span>',
			$pre
		);
	}

	/**
	 * El nombre del proyecto sin « · Empresa» al final: en su propio portal
	 * el cliente ya sabe de quién es.
	 *
	 * @param string $titulo  Nombre del proyecto.
	 * @param string $empresa Empresa del cliente.
	 * @return string
	 */
	private static function limpio( $s ) {
		// «RRSS N°3 ·» o «Publicación 1 ·»: sin el separador colgando al final.
		$t = preg_replace( '/^[\s·\-–—|,:]+|[\s·\-–—|,:]+$/u', '', (string) $s );
		return null === $t ? trim( (string) $s ) : $t;
	}

	/**
	 * El nombre sin « · Empresa» al final.
	 *
	 * @param string $titulo  Título.
	 * @param string $empresa Empresa.
	 * @return string
	 */
	private static function sin_empresa( $titulo, $empresa ) {
		$cola = ' · ' . $empresa;

		if ( $empresa && strlen( $titulo ) > strlen( $cola ) && substr( $titulo, -strlen( $cola ) ) === $cola ) {
			return substr( $titulo, 0, -strlen( $cola ) );
		}

		return $titulo;
	}

	/**
	 * «Ver detalle» de un pago hecho: el comprobante en una ventana, sin
	 * salir del portal.
	 *
	 * @param array $q Pago.
	 * @return string
	 */
	private static function detalle_pago( $q ) {
		$filas = array(
			__( 'Cotización', 'malka-crm' )    => trim( 'N° ' . $q['numero'] . ( $q['titulo'] ? ' · ' . $q['titulo'] : '' ) ),
			__( 'Monto', 'malka-crm' )         => Malka_CRM_Settings::money( $q['monto'] ),
		);

		// Con tarjeta se cobra la comisión encima: que vea lo que pagó de verdad.
		if ( $q['cobro'] > $q['monto'] ) {
			$filas[ __( 'Cobrado con comisión', 'malka-crm' ) ] = Malka_CRM_Settings::money( $q['cobro'] );
		}

		$filas[ __( 'Fecha de pago', 'malka-crm' ) ] = $q['fecha'] ? date_i18n( 'd/m/Y', strtotime( $q['fecha'] ) ) : '—';
		$filas[ __( 'Medio de pago', 'malka-crm' ) ] = $q['medio'] ? $q['medio'] : '—';

		if ( $q['tx'] ) {
			$filas[ __( 'N° de comprobante', 'malka-crm' ) ] = $q['tx'];
		}

		if ( $q['total'] ) {
			$filas[ __( 'Total del proyecto', 'malka-crm' ) ] = Malka_CRM_Settings::money( $q['total'] );
		}

		$dl = '';
		foreach ( $filas as $k => $v ) {
			$dl .= '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd></div>';
		}

		return sprintf(
			'<button type="button" class="esc-btn esc-btn--soft" data-detalle>%1$s</button>' .
			'<dialog class="esc-pdlg"><div class="esc-pdlg__h"><div><span class="esc-st esc-st--done">%2$s</span><h3>%3$s</h3></div><button type="button" class="esc-pdlg__x" data-cerrar aria-label="%4$s">×</button></div><dl>%5$s</dl></dialog>',
			esc_html__( 'Ver detalle', 'malka-crm' ),
			esc_html__( 'Pagado', 'malka-crm' ),
			esc_html( $q['cuota'] ? $q['cuota'] : __( 'Cuota', 'malka-crm' ) ),
			esc_attr__( 'Cerrar', 'malka-crm' ),
			$dl
		);
	}

	/**
	 * Los dos botones para pagar una cuota.
	 *
	 * Los dos llevan a la cotización con la cuota y el medio ya elegidos:
	 * ahí están los datos de la cuenta, el aviso de transferencia y el cobro
	 * de SumUp con su comisión, así que no se repiten acá.
	 *
	 * @param array $q Cuota.
	 * @return string
	 */
	private static function botones_pago( $q ) {
		if ( empty( $q['url'] ) ) {
			return '';
		}

		$ir = function ( $medio ) use ( $q ) {
			return add_query_arg( array_filter( array( 'pagar' => $medio, 'cuota' => $q['id'] ) ), $q['url'] ) . '#boxSelectorPago';
		};

		return sprintf(
			// La transferencia va primero y en negro: no tiene comisión y es la
			// que conviene que el ojo encuentre antes.
			'<span class="esc-pk__btns"><a class="esc-btn esc-btn--primary" href="%1$s">%2$s</a><a class="esc-btn esc-btn--soft" href="%3$s">%4$s</a></span>',
			esc_url( $ir( 'transferencia' ) ),
			esc_html__( 'Pagar con transferencia', 'malka-crm' ),
			esc_url( $ir( 'tarjeta' ) ),
			esc_html__( 'Pagar con SumUp', 'malka-crm' )
		);
	}

	/**
	 * Una tarjeta corta para las columnas de Inicio.
	 *
	 * Todas tienen el mismo orden: título y cifra arriba, una línea de
	 * detalle, y abajo el estado a la izquierda y el botón a la derecha.
	 * Así las tres columnas se leen igual.
	 *
	 * @param string $titulo Título.
	 * @param string $valor  Cifra a la derecha (HTML ya escapado).
	 * @param array  $lineas Líneas de detalle.
	 * @param string $extra  HTML entre el detalle y el pie (una barra).
	 * @param string $st     Estado (HTML).
	 * @param string $accion Botón (HTML).
	 * @return string
	 */
	private static function tarjeta( $titulo, $valor, $lineas, $extra = '', $st = '', $accion = '' ) {
		$det = '';
		foreach ( array_filter( (array) $lineas ) as $l ) {
			$det .= '<p class="esc-pk__m">' . esc_html( $l ) . '</p>';
		}

		/*
		 * El estado (Entregado, En proceso, Pendiente…) va como antetítulo
		 * chico arriba del nombre, no como una etiqueta suelta abajo.
		 */
		$pre = '';
		if ( $st && preg_match( '/esc-st--([a-z]+)[^>]*>(.*?)<\/span>/s', $st, $m ) ) {
			$pre = '<span class="esc-pk__pre is-' . esc_attr( $m[1] ) . '">' . wp_strip_all_tags( $m[2] ) . '</span>';
			$st  = '';
		}

		return sprintf(
			'<div class="esc-pk">%6$s<div class="esc-pk__top"><b>%1$s</b>%2$s</div>%3$s%4$s%5$s</div>',
			esc_html( $titulo ),
			$valor ? '<span class="esc-pk__v">' . $valor . '</span>' : '',
			$det,
			$extra,
			( $st || $accion ) ? '<div class="esc-pk__pie">' . $st . $accion . '</div>' : '',
			$pre
		);
	}

	/**
	 * Un panel con su título.
	 *
	 * @param string $titulo Título.
	 * @param string $sub    Bajada.
	 * @param string $filas  HTML de adentro.
	 * @param string $vacio  Texto si no hay filas.
	 * @return string
	 */
	/**
	 * Los enlaces y archivos de un proyecto, como botones chicos.
	 *
	 * @param int $pid Proyecto.
	 * @return string
	 */
	private static function enlaces_html( $pid ) {
		if ( ! method_exists( 'Malka_CRM_Projects', 'enlaces' ) ) {
			return '';
		}
		$out = '';
		foreach ( Malka_CRM_Projects::enlaces( $pid ) as $e ) {
			$ic   = $e['archivo']
				? '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2.5v8M4.5 7 8 10.5 11.5 7M3 13.5h10"/></svg>'
				: '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 2.5h4v4M13.5 2.5 8 8M11.5 9v3.5a1 1 0 01-1 1h-7a1 1 0 01-1-1v-7a1 1 0 011-1H7"/></svg>';
			$out .= sprintf(
				'<a class="esc-plk__a" href="%1$s" target="_blank" rel="noopener"%2$s>%3$s<span>%4$s</span></a>',
				esc_url( $e['url'] ),
				$e['archivo'] ? ' download' : '',
				$ic,
				esc_html( $e['titulo'] )
			);
		}
		return $out ? '<span class="esc-plk">' . $out . '</span>' : '';
	}

	/**
	 * El detalle de un proyecto, para la ventana que se abre al tocarlo:
	 * estado, avance, entrega y sus etapas con las tareas que puede ver.
	 *
	 * @param array  $n       Proyecto (de datos()).
	 * @param string $nombre  Nombre a mostrar.
	 * @param array  $estados Rótulos de estado.
	 * @return string
	 */
	/**
	 * La flecha y la lista plegada de lo que falta en un proyecto.
	 *
	 * @param int $pid Proyecto.
	 * @return string[] [ botón, lista ] o [ '', '' ] si no falta nada.
	 */
	private static function pendientes_proyecto( $pid ) {
		$tareas = Malka_CRM_Helpers::get_project_tasks( (int) $pid );
		$filas  = '';
		$n      = 0;
		$hoy    = current_time( 'Y-m-d' );
		// Las subtareas sueltas («Portada», «Programado») no dicen nada sin la
		// tarea a la que pertenecen: se agrupan bajo el nombre de su madre.
		$madres = array();
		foreach ( $tareas as $t ) {
			if ( empty( $t['number'] ) || ( isset( $t['type'] ) && 'stage' === $t['type'] ) || ! empty( $t['privada'] ) ) {
				continue;
			}
			$madres[ (string) $t['number'] ] = trim( (string) $t['name'] );
		}
		$madre_ant = null;
		foreach ( Malka_CRM_Helpers::get_leaf_tasks( (int) $pid ) as $t ) {
			if ( ! empty( $t['privada'] ) || '' === trim( (string) $t['name'] ) ) {
				continue;
			}
			if ( ! empty( $t['done'] ) ) {
				continue;
			}
			$n++;
			$num_m = ! empty( $t['number'] ) ? (string) Malka_CRM_Helpers::number_parent( $t['number'] ) : '';
			$madre = ( '' !== $num_m && ! empty( $madres[ $num_m ] ) ) ? $madres[ $num_m ] : '';
			if ( $madre !== $madre_ant ) {
				if ( '' !== $madre ) {
					$filas .= '<li class="esc-togl__m">' . esc_html( $madre ) . '</li>';
				}
				$madre_ant = $madre;
			}
			$hija = '' !== $madre;
			$tuya   = isset( $t['owner'] ) && in_array( $t['owner'], array( 'client', 'both' ), true );
			$tarde  = ! empty( $t['end'] ) && $t['end'] < $hoy;
			$filas .= sprintf(
				'<li class="%1$s"><i aria-hidden="true"></i><span>%2$s</span><small>%3$s%4$s</small></li>',
				trim( ( $tuya ? 'is-tuya' : '' ) . ( $tarde ? ' is-tarde' : '' ) . ( $hija ? ' is-hija' : '' ) ),
				esc_html( $t['name'] ),
				esc_html( $tuya ? __( 'Tú', 'malka-crm' ) : __( 'Estudio', 'malka-crm' ) ),
				! empty( $t['end'] ) ? ' · ' . esc_html( self::dia( $t['end'] ) ) : ''
			);
		}
		unset( $tareas );
		if ( ! $n ) {
			return array( '', '' );
		}
		$boton = sprintf(
			'<button type="button" class="esc-tog" data-tog aria-expanded="false" title="%1$s" aria-label="%1$s"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg></button>',
			esc_attr( sprintf( /* translators: cantidad */ _n( 'Ver %d tarea pendiente', 'Ver %d tareas pendientes', $n, 'malka-crm' ), $n ) )
		);
		$lista = sprintf(
			'<div class="esc-togl" hidden><p class="esc-togl__k">%1$s</p><ul>%2$s</ul></div>',
			esc_html( sprintf( /* translators: cantidad */ _n( '%d tarea pendiente', '%d tareas pendientes', $n, 'malka-crm' ), $n ) ),
			$filas
		);
		return array( $boton, $lista );
	}

	/**
	 * Formatos de publicación.
	 *
	 * @return array
	 */
	public static function formatos_pub() {
		return array(
			'reel'     => __( 'Reel', 'malka-crm' ),
			'carrusel' => __( 'Carrusel', 'malka-crm' ),
			'post'     => __( 'Post', 'malka-crm' ),
			'historia' => __( 'Historia', 'malka-crm' ),
			'instants' => __( 'Instants', 'malka-crm' ),
			'vivo'     => __( 'En vivo', 'malka-crm' ),
		);
	}

	/**
	 * Todo el detalle de una publicación, para que el cliente lo lea.
	 *
	 * @param array $t Tarea.
	 * @return string
	 */
	/**
	 * Lo que se revisa antes de publicar.
	 *
	 * @return array
	 */
	public static function checklist_pub() {
		return array(
			'video'     => __( 'Video', 'malka-crm' ),
			'historia'  => __( 'Historia', 'malka-crm' ),
			'voz'       => __( 'Voz en off', 'malka-crm' ),
			'musica'    => __( 'Música', 'malka-crm' ),
			'subt'      => __( 'Subtítulos', 'malka-crm' ),
			'copy'      => __( 'Copy', 'malka-crm' ),
			'encuesta'  => __( 'Encuesta', 'malka-crm' ),
			'hashtags'  => __( 'Hashtags', 'malka-crm' ),
			'programa'  => __( 'Programada', 'malka-crm' ),
		);
	}

	private static function pub_html( $t, $ctx = array(), $con_fecha = true ) {
		$g   = function ( $k ) use ( $t ) {
			return isset( $t[ $k ] ) ? trim( (string) $t[ $k ] ) : '';
		};
		$fmt = self::formatos_pub();
		$ico = array(
			'abrir'    => '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 3.5h-3v9h9v-3M9.5 2.5h4v4M13.5 2.5L7.5 8.5"/></svg>',
			'mic'      => '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5.5" y="1.8" width="5" height="8" rx="2.5"/><path d="M3.2 7.8a4.8 4.8 0 009.6 0M8 12.6v1.8"/></svg>',
			'expandir' => '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 2.5h4v4M6.5 13.5h-4v-4M13.5 2.5L9 7M2.5 13.5L7 9"/></svg>',
		);

		// Arriba: formato, cuándo, tema y con qué se enlaza; a la derecha, abrir el archivo.
		$tags = array();
		if ( $g( 'pub_formato' ) && isset( $fmt[ $g( 'pub_formato' ) ] ) ) {
			$tags[] = '<span class="esc-pp__fmt">' . esc_html( $fmt[ $g( 'pub_formato' ) ] . ( 'reel' === $g( 'pub_formato' ) && $g( 'pub_prueba' ) ? ' · ' . __( 'de prueba', 'malka-crm' ) : '' ) ) . '</span>';
		}
		if ( $con_fecha && $g( 'pub_fecha' ) ) {
			$tags[] = '<span>' . esc_html( ucfirst( date_i18n( 'l j \d\e F · H:i', strtotime( $g( 'pub_fecha' ) ) ) ) ) . '</span>';
		}
		if ( $g( 'pub_tema' ) ) {
			$tags[] = '<span>' . esc_html( $g( 'pub_tema' ) ) . '</span>';
		}
		if ( $g( 'pub_enlace_reel' ) ) {
			$tags[] = '<span>' . esc_html( sprintf( /* translators: otro reel */ __( 'Se enlaza con %s', 'malka-crm' ), $g( 'pub_enlace_reel' ) ) ) . '</span>';
		}
		$out = '<div class="esc-pp">';
		if ( $tags ) {
			$out .= '<div class="esc-pp__top"><div class="esc-pp__tags">' . implode( '', $tags ) . '</div></div>';
		}

		if ( $g( 'pub_copy' ) ) {
			$out .= '<p class="esc-pp__copy">' . nl2br( esc_html( $g( 'pub_copy' ) ) ) . '</p>';
		}
		if ( $g( 'pub_hashtags' ) ) {
			$out .= '<p class="esc-pp__hash">' . esc_html( $g( 'pub_hashtags' ) ) . '</p>';
		}

		if ( $g( 'pub_voz' ) ) {
			$puede = ! empty( $ctx['cid'] ) && ! empty( $t['id'] );
			$out  .= sprintf(
				'<div class="esc-pp__voz%1$s"%2$s><div><span class="esc-pp__k">%3$s</span><p>%4$s</p>%5$s%6$s</div>%7$s</div>',
				$puede ? ' esc-voz' : '',
				$puede ? sprintf(
					' data-voz data-voz-abrir role="button" tabindex="0" aria-label="%5$s" data-cliente="%1$d" data-pid="%2$d" data-tid="%3$s" data-nonce="%4$s"',
					(int) $ctx['cid'],
					(int) $ctx['pid'],
					esc_attr( $t['id'] ),
					esc_attr( $ctx['nonce'] ),
					esc_attr__( 'Abrir la voz en off en pantalla completa para grabarla', 'malka-crm' )
				) : '',
				esc_html__( 'Voz en off', 'malka-crm' ),
				nl2br( esc_html( $g( 'pub_voz' ) ) ),
				$puede ? '<template data-voz-texto>' . nl2br( esc_html( $g( 'pub_voz' ) ) ) . '</template><span class="esc-pp__hint">' . $ico['mic'] . esc_html( $g( 'pub_audio' ) ? __( 'Toca para volver a grabarla en pantalla completa', 'malka-crm' ) : __( 'Toca para leerla en pantalla completa y grabarla', 'malka-crm' ) ) . '</span>' : '',
				$g( 'pub_audio' ) ? '<span class="esc-voz__ya"><small>' . esc_html( sprintf( /* translators: fecha */ __( 'Tu grabación · %s', 'malka-crm' ), $g( 'pub_audio_at' ) ? date_i18n( 'j M · H:i', strtotime( $g( 'pub_audio_at' ) ) ) : '' ) ) . '</small><audio controls preload="none" src="' . esc_url( $g( 'pub_audio' ) ) . '"></audio></span>' : '',
				$puede ? '<span class="esc-pp__ico" aria-hidden="true">' . $ico['expandir'] . '</span>' : ''
			);
		}
		if ( $g( 'pub_encuesta' ) ) {
			$out .= '<div class="esc-pp__enc"><span class="esc-pp__k">' . esc_html__( 'Encuesta', 'malka-crm' ) . '</span>' . nl2br( esc_html( $g( 'pub_encuesta' ) ) ) . '</div>';
		}
		if ( $g( 'pub_musica' ) ) {
			$mus  = $g( 'pub_musica' );
			$es_u = (bool) preg_match( '#^https?://#i', $mus );
			$out .= '<div class="esc-pp__mus"><span class="esc-pp__k">' . esc_html__( 'Música', 'malka-crm' ) . '</span>' . ( $es_u ? '<a href="' . esc_url( $mus ) . '" target="_blank" rel="noopener">' . esc_html__( 'Escuchar', 'malka-crm' ) . ' ↗</a>' : esc_html( $mus ) ) . '</div>';
		}
		if ( $g( 'pub_nota' ) ) {
			$out .= '<div class="esc-pp__nota"><span class="esc-pp__k">' . esc_html__( 'Nota del estudio', 'malka-crm' ) . '</span><p>' . nl2br( esc_html( $g( 'pub_nota' ) ) ) . '</p></div>';
		}
		// Abajo, las descargas: el archivo de la publicación y la portada del reel.
		$baja  = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2.5v8M4.5 7 8 10.5 11.5 7M3 13.5h10"/></svg>';
		$descr = '';
		if ( $g( 'pub_url' ) ) {
			$descr .= '<a class="esc-pp__portada" href="' . esc_url( $g( 'pub_url' ) ) . '" target="_blank" rel="noopener">' . $baja . esc_html__( 'Descargar el archivo', 'malka-crm' ) . '</a>';
		}
		if ( $g( 'pub_portada' ) ) {
			$descr .= '<a class="esc-pp__portada" href="' . esc_url( $g( 'pub_portada' ) ) . '" target="_blank" rel="noopener">' . $baja . esc_html__( 'Descargar la portada', 'malka-crm' ) . '</a>';
		}
		if ( $descr ) {
			$out .= '<div class="esc-pp__descargas">' . $descr . '</div>';
		}
		return $out . '</div>';
	}

	/**
	 * ¿Tiene algo de publicación que mostrar?
	 *
	 * @param array $t Tarea.
	 * @return bool
	 */
	private static function tiene_pub( $t ) {
		foreach ( array( 'pub_copy', 'pub_url', 'pub_fecha', 'pub_tema', 'pub_hashtags', 'pub_voz', 'pub_encuesta', 'pub_enlace_reel', 'pub_portada', 'pub_musica', 'pub_nota', 'pub_formato', 'pub_check' ) as $k ) {
			if ( ! empty( $t[ $k ] ) ) {
				return class_exists( 'Malka_CRM_Projects' ) && Malka_CRM_Projects::es_publicacion( $t );
			}
		}
		return false;
	}

	/**
	 * Una publicación en su tarjeta: la fecha grande a la izquierda y el
	 * detalle a la derecha. Arriba, la próxima lleva su antetítulo y nombre;
	 * en la lista, el nombre ya está en la fila.
	 *
	 * @param array $pt     Tarea.
	 * @param array $ctx    cid, pid, nonce.
	 * @param bool  $arriba Si es la próxima, arriba del detalle.
	 * @return string
	 */
	private static function tarjeta_pub( $pt, $ctx, $arriba ) {
		$ts   = ! empty( $pt['pub_fecha'] ) ? strtotime( $pt['pub_fecha'] ) : ( ! empty( $pt['end'] ) ? strtotime( $pt['end'] ) : 0 );
		$cab  = '';
		if ( $arriba ) {
			$dias  = $ts ? (int) floor( ( strtotime( gmdate( 'Y-m-d', $ts ) ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : null;
			$falta = null === $dias ? '' : ( $dias < 0 ? __( 'atrasada', 'malka-crm' ) : ( 0 === $dias ? __( 'hoy', 'malka-crm' ) : ( 1 === $dias ? __( 'mañana', 'malka-crm' ) : sprintf( /* translators: días */ __( 'en %d días', 'malka-crm' ), $dias ) ) ) );
			$cab   = '<span class="esc-pk__pre is-wait">' . esc_html( __( 'Próxima publicación', 'malka-crm' ) . ( $falta ? ' · ' . $falta : '' ) ) . '</span><h4>' . esc_html( $pt['name'] ) . '</h4>';
		}
		return sprintf(
			'<section class="esc-pd__prox%1$s">%2$s<div class="esc-pd__proxc">%3$s%4$s</div></section>',
			$arriba ? '' : ' is-fila',
			$ts ? sprintf(
				'<div class="esc-pd__fecha"><em>%1$s</em><b>%2$s</b><span>%3$s</span>%4$s</div>',
				esc_html( date_i18n( 'j', $ts ) ),
				esc_html( ucfirst( date_i18n( 'l', $ts ) ) ),
				esc_html( ucfirst( date_i18n( 'M Y', $ts ) ) ),
				! empty( $pt['pub_fecha'] ) ? '<span class="esc-pd__hora">' . esc_html( date_i18n( 'H:i', $ts ) ) . '</span>' : ''
			) : '',
			$cab,
			self::pub_html( $pt, $ctx, false )
		);
	}

	/**
	 * Lo que va al calendario general: [ 'ev' => [], 'det' => [] ].
	 *
	 * @var array
	 */
	private static $cal_todo = array( 'ev' => array(), 'det' => array() );

	/**
	 * El calendario de todo: publicaciones y tareas de cada proyecto, pagos,
	 * firmas y entregas, cada cosa en su día.
	 *
	 * @param array $d Datos del cliente.
	 * @return string
	 */
	private static function cal_general( $d ) {
		$ev  = self::$cal_todo['ev'];
		$det = self::$cal_todo['det'];
		$hoy = current_time( 'Y-m-d' );

		// Pagos por hacer, en su fecha.
		foreach ( $d['cuotas'] as $k => $q ) {
			if ( empty( $q['fecha'] ) ) {
				continue;
			}
			$id    = 'pago-' . $k;
			$tit   = sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $q['numero'] );
			$ev[]  = array( 'id' => $id, 'f' => substr( (string) $q['fecha'], 0, 10 ), 'k' => 'pago', 'n' => trim( ( $q['cuota'] ? $q['cuota'] . ' · ' : '' ) . 'N° ' . $q['numero'] ), 'ok' => 0, 'tr' => $q['tarde'] ? 1 : 0 );
			$det[ $id ] = sprintf(
				'<div class="esc-mcal__cab"><span class="esc-pdlg__pre is-%1$s">%2$s</span><h3>%3$s</h3><p>%4$s</p></div><p class="esc-mcal__monto">%5$s</p><a class="esc-btn esc-btn--primary esc-mcal__ir" href="#pagos" data-cal-ir>%6$s</a>',
				$q['tarde'] ? 'late' : 'wait',
				esc_html( $q['tarde'] ? __( 'Pago atrasado', 'malka-crm' ) : __( 'Pago por hacer', 'malka-crm' ) ),
				esc_html( $tit ),
				esc_html( trim( ( $q['cuota'] ? $q['cuota'] . ' · ' : '' ) . ( $q['negocio'] ? $q['negocio'] . ' · ' : '' ) . sprintf( /* translators: fecha */ __( 'Vence el %s', 'malka-crm' ), date_i18n( 'j \d\e F', strtotime( $q['fecha'] ) ) ) ) ),
				esc_html( Malka_CRM_Settings::money( $q['monto'] ) ),
				esc_html__( 'Ir a pagar', 'malka-crm' )
			);
		}

		// Cotizaciones por firmar, el día que vencen.
		foreach ( $d['cot'] as $q ) {
			if ( 'sin_respuesta' !== $q['estado'] ) {
				continue;
			}
			$firma = get_post_meta( (int) $q['id'], '_malka_pm_quote_sign', true );
			$vence = (string) get_post_meta( (int) $q['id'], '_malka_pm_quote_expires', true );
			if ( ( is_array( $firma ) && ! empty( $firma['rut'] ) ) || ! $vence ) {
				continue;
			}
			$id    = 'firma-' . (int) $q['id'];
			$tit   = sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $q['numero'] );
			$ev[]  = array( 'id' => $id, 'f' => substr( $vence, 0, 10 ), 'k' => 'firma', 'n' => sprintf( /* translators: número */ __( 'Firmar N° %s', 'malka-crm' ), $q['numero'] ), 'ok' => 0, 'tr' => $vence < $hoy ? 1 : 0 );
			$det[ $id ] = sprintf(
				'<div class="esc-mcal__cab"><span class="esc-pdlg__pre is-wait">%1$s</span><h3>%2$s</h3><p>%3$s</p></div><p class="esc-mcal__monto">%4$s</p><a class="esc-btn esc-btn--primary esc-mcal__ir" href="%5$s" data-pop="%5$s" data-pop-t="%2$s">%6$s</a>',
				esc_html__( 'Por firmar', 'malka-crm' ),
				esc_html( $tit ),
				esc_html( trim( ( self::limpio( $q['titulo'] ) ? self::limpio( $q['titulo'] ) . ' · ' : '' ) . sprintf( /* translators: fecha */ __( 'vigente hasta el %s', 'malka-crm' ), date_i18n( 'j \d\e F', strtotime( $vence ) ) ) ) ),
				esc_html( Malka_CRM_Settings::money( $q['total'] ) ),
				esc_url( $q['url'] ),
				esc_html__( 'Revisar y firmar', 'malka-crm' )
			);
		}

		// La entrega de cada proyecto.
		foreach ( $d['negocios'] as $n ) {
			if ( empty( $n['fin'] ) ) {
				continue;
			}
			$id   = 'entrega-' . (int) $n['id'];
			$nom  = self::limpio( $n['titulo'] );
			$ev[] = array( 'id' => $id, 'f' => (string) $n['fin'], 'k' => 'entrega', 'n' => $nom, 'ok' => 'entregado' === $n['estado'] ? 1 : 0 );
			$det[ $id ] = sprintf(
				'<div class="esc-mcal__cab"><span class="esc-pdlg__pre is-%1$s">%2$s</span><h3>%3$s</h3><p>%4$s</p></div>',
				'entregado' === $n['estado'] ? 'done' : 'run',
				esc_html( 'entregado' === $n['estado'] ? __( 'Entregado', 'malka-crm' ) : __( 'Entrega del proyecto', 'malka-crm' ) ),
				esc_html( $nom ),
				esc_html( sprintf( /* translators: 1: fecha, 2: avance */ __( '%1$s · avance %2$d%%', 'malka-crm' ), ucfirst( date_i18n( 'l j \d\e F', strtotime( $n['fin'] ) ) ), (int) $n['avance'] ) )
			);
		}

		if ( ! $ev ) {
			return '';
		}
		$tpl = '';
		foreach ( $det as $id => $html ) {
			if ( '' !== $html ) {
				$tpl .= '<template data-cal-det="' . esc_attr( $id ) . '">' . $html . '</template>';
			}
		}
		$out  = '<section class="esc-panel esc-mcal__panel"><div class="esc-mcal" data-cal data-vista="cal" data-hoy="' . esc_attr( $hoy ) . '">';
		$out .= '<div class="esc-mcal__mes"><div class="esc-mcal__cuerpo"><div class="esc-mcal__izq"><div class="esc-mcal__top"><b data-cal-t></b><span class="esc-mcal__nav">';
		$out .= '<button type="button" data-cal-mover="-1" aria-label="' . esc_attr__( 'Mes anterior', 'malka-crm' ) . '"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3.5L5.5 8l4.5 4.5"/></svg></button>';
		$out .= '<button type="button" data-cal-mover="0">' . esc_html__( 'Hoy', 'malka-crm' ) . '</button>';
		$out .= '<button type="button" data-cal-mover="1" aria-label="' . esc_attr__( 'Mes siguiente', 'malka-crm' ) . '"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3.5L10.5 8 6 12.5"/></svg></button>';
		$out .= '</span></div>';
		$out .= '<div class="esc-mcal__sem"><span>' . implode( '</span><span>', array( esc_html__( 'Lun', 'malka-crm' ), esc_html__( 'Mar', 'malka-crm' ), esc_html__( 'Mié', 'malka-crm' ), esc_html__( 'Jue', 'malka-crm' ), esc_html__( 'Vie', 'malka-crm' ), esc_html__( 'Sáb', 'malka-crm' ), esc_html__( 'Dom', 'malka-crm' ) ) ) . '</span></div>';
		$out .= '<div class="esc-mcal__dias" data-cal-dias></div>';
		$out .= '<div class="esc-mcal__ley"><span><i class="is-pub"></i>' . esc_html__( 'Publicación', 'malka-crm' ) . '</span><span><i class="is-tu"></i>' . esc_html__( 'Te toca a ti: tarea, pago o firma', 'malka-crm' ) . '</span><span><i class="is-entrega"></i>' . esc_html__( 'Entrega', 'malka-crm' ) . '</span><span><i class="is-tarde"></i>' . esc_html__( 'Atrasado', 'malka-crm' ) . '</span><span><i class="is-ok"></i>' . esc_html__( 'Listo', 'malka-crm' ) . '</span></div>';
		$out .= '</div><aside class="esc-mcal__lado" aria-live="polite"><div class="esc-mcal__dia" data-cal-dia></div><div class="esc-mcal__det" data-cal-det-lado hidden></div></aside></div></div>';
		$out .= '<div class="esc-pd__l" hidden></div>';
		$out .= '<script type="application/json" data-cal-json>' . wp_json_encode( array_values( $ev ) ) . '</script>' . $tpl;
		$out .= '<dialog class="esc-pdlg esc-mcal__pop"><div class="esc-pdlg__h"><div></div><button type="button" class="esc-pdlg__x" data-cerrar aria-label="' . esc_attr__( 'Cerrar', 'malka-crm' ) . '">×</button></div><div class="esc-mcal__popc"></div></dialog>';
		return $out . '</div></section>';
	}

	/**
	 * Lo que se cobra en un proyecto: contratado, pagado, pendiente y sus
	 * facturas, en una franja bajo la fila.
	 *
	 * @param int   $pid Proyecto.
	 * @param array $d   Datos del cliente (cot, pagados, cuotas).
	 * @return string
	 */
	private static function plata_datos( $pid, $d ) {
		$nums  = array();
		$qids  = array();
		$total = 0;
		foreach ( $d['cot'] as $q ) {
			if ( (int) get_post_meta( (int) $q['id'], '_malka_pm_project_id', true ) !== (int) $pid ) {
				continue;
			}
			if ( in_array( (string) $q['estado'], array( 'rechazada', 'caducada' ), true ) ) {
				continue;
			}
			$nums[ (string) $q['numero'] ] = true;
			$qids[ (int) $q['id'] ]        = true;
			$total                        += (int) $q['total'];
		}
		if ( ! $qids ) {
			return null;
		}
		$pagado = 0;
		foreach ( $d['pagados'] as $p ) {
			if ( isset( $nums[ (string) $p['numero'] ] ) ) {
				$pagado += (int) $p['monto'];
			}
		}
		$pend = 0;
		foreach ( $d['cuotas'] as $p ) {
			if ( isset( $nums[ (string) $p['numero'] ] ) ) {
				$pend += (int) $p['monto'];
			}
		}
		if ( ! $pend ) {
			$pend = max( 0, $total - $pagado );
		}

		// Sus facturas: las que apuntan a una de sus cotizaciones o al proyecto.
		static $docs = null;
		if ( null === $docs ) {
			$docs = class_exists( 'Malka_CRM_Documents' ) ? Malka_CRM_Documents::all() : array();
		}
		$fact      = array();
		$facturado = 0;
		foreach ( $docs as $x ) {
			if ( 'venta' !== $x['sentido'] || 'anulada' === $x['estado'] ) {
				continue;
			}
			if ( ( ! empty( $x['quote'] ) && isset( $qids[ (int) $x['quote'] ] ) ) || ( ! empty( $x['proyecto'] ) && (int) $x['proyecto'] === (int) $pid ) ) {
				$con = Malka_CRM_Documents::tiene_archivo( $x['id'] );
				$url = $con ? Malka_CRM_Documents::url_pdf( $x['id'], true ) : Malka_CRM_Documents::url_ver( $x['id'] );
				$rot = trim( $x['rotulo'] . ( $x['folio'] ? ' N° ' . $x['folio'] : '' ) );
				$facturado += (int) $x['total'] * ( isset( $x['signo'] ) ? (int) $x['signo'] : 1 );
				$fact[] = sprintf(
					'<a href="%1$s" data-pop="%1$s" data-pop-t="%2$s" target="_blank" rel="noopener">%2$s ↗</a>',
					esc_url( $url ),
					esc_attr( $rot )
				);
			}
		}
		return array(
			'total'     => $total,
			'pagado'    => $pagado,
			'pend'      => $pend,
			'fact'      => $fact,
			'facturado' => $facturado,
		);
	}

	/**
	 * Lo que se cobra en un proyecto (franja de la lista de proyectos).
	 *
	 * @param int   $pid Proyecto.
	 * @param array $d   Datos del cliente.
	 * @return string
	 */
	private static function plata_proyecto( $pid, $d ) {
		$x = self::plata_datos( $pid, $d );
		if ( ! $x ) {
			return '';
		}
		$total  = $x['total'];
		$pagado = $x['pagado'];
		$pend   = $x['pend'];
		$fact   = $x['fact'];
		$celda = function ( $k, $v, $cls = '' ) {
			return '<span class="esc-pr__pl' . ( $cls ? ' ' . $cls : '' ) . '"><small>' . esc_html( $k ) . '</small><b>' . $v . '</b></span>';
		};
		return '<div class="esc-pr__plata">' .
			$celda( __( 'Monto', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $total ) ) ) .
			$celda( __( 'Pagado', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $pagado ) ) ) .
			$celda( __( 'Pendiente', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $pend ) ), $pend > 0 ? 'is-pend' : 'is-ok' ) .
			$celda( _n( 'Factura', 'Facturas', max( 1, count( $fact ) ), 'malka-crm' ), $fact ? implode( ' ', $fact ) : '<em>' . esc_html__( 'Todavía no', 'malka-crm' ) . '</em>' ) .
			'</div>';
	}

	/**
	 * El ícono de factura en tres estados, con su texto al pasar el mouse.
	 *
	 * @param string $estado todo | parte | nada.
	 * @param string $texto  Lo que dice.
	 * @return string
	 */
	private static function ico_factura( $estado, $texto ) {
		return sprintf(
			'<span class="esc-pd__fac is-%1$s" data-tip="%2$s" aria-label="%2$s" role="img" tabindex="0"><svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 1.8h5.2L12.2 4.8v8.4a1 1 0 01-1 1H4a1 1 0 01-1-1V2.8a1 1 0 011-1z"/>%3$s</svg></span>',
			esc_attr( $estado ),
			esc_attr( $texto ),
			'todo' === $estado ? '<path d="M5.4 9.6l1.6 1.6 3.2-3.4"/>' : '<path d="M5.5 8.5h4M5.5 11h2.6"/>'
		);
	}

	/**
	 * La cabecera compacta de la ficha del proyecto: barra con el
	 * porcentaje; etapa, lo próximo y entrega en una línea; y en otra, la
	 * cotización, los montos con su estado de factura y la factura.
	 *
	 * @param array $n     Proyecto.
	 * @param array $datos Datos del cliente (o null).
	 * @return string
	 */
	private static function cabecera_proyecto( $n, $datos ) {
		$out  = '<div class="esc-pd__fila"><span class="esc-pd__bar"><i style="width:' . (int) $n['avance'] . '%"></i></span><b>' . (int) $n['avance'] . '%</b></div>';
		$chip = array();
		if ( $n['etapa'] ) {
			$chip[] = '<span>' . esc_html__( 'Etapa', 'malka-crm' ) . ' <b>' . esc_html( $n['etapa'] ) . '</b></span>';
		}
		if ( $n['proxima'] ) {
			$chip[] = '<span>' . esc_html__( 'Lo próximo', 'malka-crm' ) . ' <b>' . esc_html( self::limpio( $n['proxima']['nombre'] ) . ( $n['proxima']['fecha'] ? ' · ' . self::dia( $n['proxima']['fecha'] ) : '' ) ) . '</b></span>';
		}
		if ( $n['fin'] ) {
			$chip[] = '<span>' . esc_html( 'entregado' === $n['estado'] ? __( 'Entregado', 'malka-crm' ) : __( 'Entrega', 'malka-crm' ) ) . ' <b>' . esc_html( self::dia( $n['fin'] ) ) . '</b></span>';
		}
		if ( $chip ) {
			$out .= '<div class="esc-pd__chips">' . implode( '', $chip ) . '</div>';
		}

		$cots = self::cotizaciones_de( (int) $n['id'] );
		$x    = $datos ? self::plata_datos( (int) $n['id'], $datos ) : null;
		if ( ! $cots && ! $x ) {
			return $out;
		}
		$plata = '';
		if ( $x ) {
			$money = function ( $v ) {
				return esc_html( Malka_CRM_Settings::money( $v ) );
			};
			$fac = (int) $x['facturado'];
			// Lo pagado: ¿ya tiene factura?
			$e_pag = $x['pagado'] <= 0 ? '' : ( $fac >= $x['pagado'] - 1 ? 'todo' : ( $fac > 0 ? 'parte' : 'nada' ) );
			$t_pag = 'todo' === $e_pag ? __( 'Lo pagado ya tiene factura', 'malka-crm' ) : ( 'parte' === $e_pag ? sprintf( /* translators: 1: facturado, 2: pagado */ __( 'Facturado %1$s de %2$s pagado', 'malka-crm' ), Malka_CRM_Settings::money( $fac ), Malka_CRM_Settings::money( $x['pagado'] ) ) : __( 'Lo pagado todavía no tiene factura', 'malka-crm' ) );
			// Lo pendiente: se factura cuando se paga (salvo que ya esté facturado).
			$sobra = max( 0, $fac - $x['pagado'] );
			$e_pen = $x['pend'] <= 0 ? '' : ( $sobra >= $x['pend'] - 1 ? 'todo' : ( $sobra > 0 ? 'parte' : 'nada' ) );
			$t_pen = 'todo' === $e_pen ? __( 'Lo pendiente ya está facturado', 'malka-crm' ) : ( 'parte' === $e_pen ? __( 'Lo pendiente está facturado en parte', 'malka-crm' ) : __( 'Se factura cuando se pague', 'malka-crm' ) );

			$plata .= '<span>' . esc_html__( 'Monto', 'malka-crm' ) . ' <b>' . $money( $x['total'] ) . '</b></span>';
			$plata .= '<span>' . esc_html__( 'Pagado', 'malka-crm' ) . ' <b>' . $money( $x['pagado'] ) . '</b>' . ( $e_pag ? self::ico_factura( $e_pag, $t_pag ) : '' ) . '</span>';
			$plata .= '<span class="' . ( $x['pend'] > 0 ? 'is-pend' : '' ) . '">' . esc_html__( 'Pendiente', 'malka-crm' ) . ' <b>' . $money( $x['pend'] ) . '</b>' . ( $e_pen ? self::ico_factura( $e_pen, $t_pen ) : '' ) . '</span>';
			if ( $x['fact'] ) {
				$plata .= '<span class="esc-pd__facts">' . implode( '', $x['fact'] ) . '</span>';
			}
		}
		return $out . '<div class="esc-pd__plata2">' . $cots . $plata . '</div>';
	}

	/**
	 * Las cotizaciones enlazadas a un proyecto, como botones que la abren.
	 *
	 * @param int $pid Proyecto.
	 * @return string
	 */
	private static function cotizaciones_de( $pid ) {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) || ! class_exists( 'Malka_CRM_Quote_Page' ) ) {
			return '';
		}
		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_key'       => '_malka_pm_project_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $pid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( ! $ids ) {
			return '';
		}
		$ico = '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 2h7l3 3v9a1 1 0 01-1 1H3a1 1 0 01-1-1V3a1 1 0 011-1z"/><path d="M5 8.5h6M5 11h4"/></svg>';
		$out = '';
		foreach ( $ids as $qid ) {
			$num = (string) get_post_meta( $qid, '_malka_pm_quote_number', true );
			$tit = sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $num ? $num : $qid );
			$url = Malka_CRM_Quote_Page::public_url( $qid );
			$out .= sprintf(
				'<a class="esc-pd__cot" href="%1$s" data-pop="%1$s" data-pop-t="%2$s">%3$s<span>%2$s</span><i aria-hidden="true">›</i></a>',
				esc_url( $url ),
				esc_attr( $tit ),
				$ico
			);
		}
		return '<div class="esc-pd__cots">' . $out . '</div>';
	}

	/**
	 * La cabeza de la ventana de una publicación: estado, nombre y cuándo.
	 *
	 * @param array  $t     Tarea.
	 * @param bool   $hecha Si ya está.
	 * @param string $hoy   Y-m-d.
	 * @return string
	 */
	private static function cal_cabeza( $t, $hecha, $hoy ) {
		$f    = ! empty( $t['pub_fecha'] ) ? substr( (string) $t['pub_fecha'], 0, 10 ) : ( ! empty( $t['end'] ) ? (string) $t['end'] : '' );
		$tono = $hecha ? array( 'done', __( 'Publicada', 'malka-crm' ) ) : ( $f && $f === $hoy ? array( 'wait', __( 'Se publica hoy', 'malka-crm' ) ) : ( $f && $f < $hoy ? array( 'late', __( 'Atrasada', 'malka-crm' ) ) : array( 'run', __( 'Próxima publicación', 'malka-crm' ) ) ) );
		return sprintf(
			'<div class="esc-mcal__cab"><span class="esc-pdlg__pre is-%1$s">%2$s</span><h3>%3$s</h3></div>',
			esc_attr( $tono[0] ),
			esc_html( $tono[1] ),
			esc_html( $t['name'] )
		);
	}

	/**
	 * Etapas en lista y, al lado, el calendario del mes. Un proyecto de
	 * redes sociales abre en el calendario; los demás, en la lista.
	 *
	 * @param array  $n      Proyecto.
	 * @param string $nombre Nombre.
	 * @param array  $tareas Tareas.
	 * @param string $lista  HTML de la lista.
	 * @param array  $ev     Eventos del calendario.
	 * @param array  $det    Detalle de cada evento.
	 * @return string
	 */
	private static function cal_bloque( $n, $nombre, $tareas, $lista, $ev, $det ) {
		if ( ! $lista && ! $ev ) {
			return '';
		}
		$pubs = 0;
		foreach ( $ev as $e ) {
			$pubs += 'pub' === $e['k'] ? 1 : 0;
		}
		$rrss = $pubs >= 2 || preg_match( '/rrss|redes|social|instagram|tiktok/i', remove_accents( (string) $nombre ) );
		$tpl  = '';
		foreach ( $det as $id => $html ) {
			$tpl .= '<template data-cal-det="' . esc_attr( $id ) . '">' . $html . '</template>';
		}
		$pop = '<dialog class="esc-pdlg esc-mcal__pop"><div class="esc-pdlg__h"><div></div><button type="button" class="esc-pdlg__x" data-cerrar aria-label="' . esc_attr__( 'Cerrar', 'malka-crm' ) . '">×</button></div><div class="esc-mcal__popc"></div></dialog>';
		if ( ! $ev ) {
			return '<p class="esc-pdlg__k">' . esc_html__( 'Etapas', 'malka-crm' ) . '</p><div class="esc-pd__l">' . $lista . '</div>' . $tpl . $pop;
		}
		$ico_cal   = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" aria-hidden="true"><rect x="2.5" y="3.5" width="11" height="10" rx="1.5"/><path d="M2.5 6.5h11M5.5 2v3M10.5 2v3"/></svg>';
		$ico_lista = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" aria-hidden="true"><path d="M5.5 4.5h8M5.5 8h8M5.5 11.5h8M2.5 4.5h.01M2.5 8h.01M2.5 11.5h.01"/></svg>';
		$out  = '<div class="esc-mcal" data-cal data-vista="' . ( $rrss ? 'cal' : 'lista' ) . '" data-hoy="' . esc_attr( current_time( 'Y-m-d' ) ) . '">';
		$out .= '<div class="esc-mcal__sec"><p class="esc-pdlg__k">' . esc_html( $rrss ? __( 'Publicaciones y tareas', 'malka-crm' ) : __( 'Etapas', 'malka-crm' ) ) . '</p>';
		$out .= '<div class="esc-mcal__vistas" role="group" aria-label="' . esc_attr__( 'Cómo verlo', 'malka-crm' ) . '">';
		$out .= '<button type="button" data-cal-vista="cal" aria-pressed="' . ( $rrss ? 'true' : 'false' ) . '">' . $ico_cal . esc_html__( 'Calendario', 'malka-crm' ) . '</button>';
		$out .= '<button type="button" data-cal-vista="lista" aria-pressed="' . ( $rrss ? 'false' : 'true' ) . '">' . $ico_lista . esc_html__( 'Lista', 'malka-crm' ) . '</button>';
		$out .= '</div></div>';
		$out .= '<div class="esc-mcal__mes"' . ( $rrss ? '' : ' hidden' ) . '><div class="esc-mcal__cuerpo"><div class="esc-mcal__izq"><div class="esc-mcal__top"><b data-cal-t></b><span class="esc-mcal__nav">';
		$out .= '<button type="button" data-cal-mover="-1" aria-label="' . esc_attr__( 'Mes anterior', 'malka-crm' ) . '"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3.5L5.5 8l4.5 4.5"/></svg></button>';
		$out .= '<button type="button" data-cal-mover="0">' . esc_html__( 'Hoy', 'malka-crm' ) . '</button>';
		$out .= '<button type="button" data-cal-mover="1" aria-label="' . esc_attr__( 'Mes siguiente', 'malka-crm' ) . '"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3.5L10.5 8 6 12.5"/></svg></button>';
		$out .= '</span></div>';
		$out .= '<div class="esc-mcal__sem"><span>' . implode( '</span><span>', array( esc_html__( 'Lun', 'malka-crm' ), esc_html__( 'Mar', 'malka-crm' ), esc_html__( 'Mié', 'malka-crm' ), esc_html__( 'Jue', 'malka-crm' ), esc_html__( 'Vie', 'malka-crm' ), esc_html__( 'Sáb', 'malka-crm' ), esc_html__( 'Dom', 'malka-crm' ) ) ) . '</span></div>';
		$out .= '<div class="esc-mcal__dias" data-cal-dias></div>';
		$out .= '<div class="esc-mcal__ley"><span><i class="is-pub"></i>' . esc_html__( 'Publicación', 'malka-crm' ) . '</span><span><i class="is-tu"></i>' . esc_html__( 'Te toca a ti', 'malka-crm' ) . '</span><span><i class="is-tarde"></i>' . esc_html__( 'Atrasada', 'malka-crm' ) . '</span><span><i class="is-ok"></i>' . esc_html__( 'Lista o publicada', 'malka-crm' ) . '</span></div>';
		$out .= '</div><aside class="esc-mcal__lado" aria-live="polite"><div class="esc-mcal__dia" data-cal-dia></div><div class="esc-mcal__det" data-cal-det-lado hidden></div></aside></div>';
		$out .= '</div>';
		$out .= '<div class="esc-pd__l"' . ( $rrss ? ' hidden' : '' ) . '>' . $lista . '</div>';
		$out .= '<script type="application/json" data-cal-json>' . wp_json_encode( array_values( $ev ) ) . '</script>' . $tpl . $pop;
		return $out . '</div>';
	}

	private static function detalle_proyecto( $n, $nombre, $estados, $cid = 0, $datos = null ) {
		$tono   = 'entregado' === $n['estado'] ? 'done' : ( 'pausado' === $n['estado'] ? 'off' : 'run' );
		$tareas = Malka_CRM_Helpers::get_project_tasks( (int) $n['id'] );

		// La próxima publicación: la que no está lista y sale antes.
		$prox = null;
		foreach ( $tareas as $t ) {
			if ( ! empty( $t['privada'] ) || ! empty( $t['done'] ) || ! self::tiene_pub( $t ) ) {
				continue;
			}
			$cuando = ! empty( $t['pub_fecha'] ) ? $t['pub_fecha'] : ( ! empty( $t['end'] ) ? $t['end'] . ' 23:59' : '9999' );
			if ( null === $prox || $cuando < $prox[0] ) {
				$prox = array( $cuando, $t );
			}
		}

		$dl = '';
		foreach ( array(
			__( 'Avance', 'malka-crm' )        => (int) $n['avance'] . '%',
			__( 'Etapa actual', 'malka-crm' )  => $n['etapa'] ? $n['etapa'] : ( 'entregado' === $n['estado'] ? __( 'Terminado', 'malka-crm' ) : '—' ),
			__( 'Lo próximo', 'malka-crm' )    => $n['proxima'] ? $n['proxima']['nombre'] . ( $n['proxima']['fecha'] ? ' · ' . self::dia( $n['proxima']['fecha'] ) : '' ) : '—',
			__( 'Entrega', 'malka-crm' )       => $n['fin'] ? date_i18n( 'j \d\e F Y', strtotime( $n['fin'] ) ) : '—',
		) as $k => $v ) {
			$dl .= '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd></div>';
		}

		$ctx_n   = array( 'cid' => $cid, 'pid' => (int) $n['id'], 'nonce' => $cid ? wp_create_nonce( 'malka_crm_portal_lista_' . (int) $cid ) : '' );
		$proxima = $prox ? self::tarjeta_pub( $prox[1], $ctx_n, true ) : '';

		$nonce = $cid ? wp_create_nonce( 'malka_crm_portal_lista_' . (int) $cid ) : '';

		// Etapas y sus tareas, sin lo privado del estudio.
		$items   = array();
		foreach ( $tareas as $k_t => $t ) {
			$t['_cid'] = ! empty( $t['id'] ) ? preg_replace( '/[^a-z0-9_-]/i', '', (string) $t['id'] ) : 'i' . $k_t;
			if ( ! empty( $t['privada'] ) || '' === trim( (string) $t['name'] ) ) {
				continue;
			}
			$hecha = ! empty( $t['done'] ) || ( 'stage' === $t['type'] && isset( $t['progress'] ) && (int) $t['progress'] >= 100 );
			if ( 'stage' === $t['type'] ) {
				$items[] = array( 'stage' => sprintf( '<p class="esc-pd__et%1$s">%2$s%3$s</p>', $hecha ? ' is-ok' : '', $hecha ? '<i aria-hidden="true">✓</i>' : '<i aria-hidden="true"></i>', esc_html( $t['name'] ) ) );
				continue;
			}
			$suya  = isset( $t['owner'] ) && in_array( $t['owner'], array( 'client', 'both' ), true );
			$quien = $suya ? __( 'Tú', 'malka-crm' ) : __( 'Estudio', 'malka-crm' );
			// Las subtareas (2.8.1) van más adentro que su tarea (2.8).
			$niv  = ! empty( $t['number'] ) ? count( explode( '.', trim( (string) $t['number'] ) ) ) : 2;
			$sub  = $niv >= 3 ? ' esc-pd__sub esc-pd__n' . min( 4, $niv ) : '';
			$meta = ! empty( $t['pub_fecha'] ) ? date_i18n( 'j M · H:i', strtotime( $t['pub_fecha'] ) ) : ( ! empty( $t['end'] ) ? self::dia( $t['end'] ) : '' );
			$meta = $quien . ( $meta ? ' · ' . $meta : '' );

			// Lo suyo lo puede marcar y comentar desde acá.
			$ck   = '';
			$nota = '';
			if ( $suya && $cid && ! empty( $t['id'] ) ) {
				$ck = $hecha
					? '<span class="esc-ck is-on" aria-hidden="true"><svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5l3 3 6-7"/></svg></span>'
					: self::casilla_lista( $cid, array( 'id' => $t['id'], 'pid' => (int) $n['id'] ) );
				$modo_r = isset( $t['respuesta'] ) ? (string) $t['respuesta'] : '';
				$preg_r = ! empty( $t['respuesta_preg'] ) ? (string) $t['respuesta_preg'] : '';
				$nota = 'no' === $modo_r ? '' : sprintf(
					'<details class="esc-nb"%7$s><summary>%1$s</summary>%10$s<div class="esc-nb__pie"><button type="button" class="esc-btn esc-btn--soft" data-nota-enviar data-cliente="%4$d" data-pid="%5$d" data-tid="%6$s" data-nonce="%8$s">%9$s</button></div></details>',
					esc_html( ! empty( $t['nota_cliente'] ) ? __( 'Tu nota', 'malka-crm' ) : __( 'Dejar una nota', 'malka-crm' ) ),
					esc_attr__( 'Una duda, dónde lo dejaste, algo que debamos saber…', 'malka-crm' ),
					esc_textarea( isset( $t['nota_cliente'] ) ? $t['nota_cliente'] : '' ),
					(int) $cid,
					(int) $n['id'],
					esc_attr( $t['id'] ),
					! empty( $t['nota_cliente'] ) ? ' open' : '',
					esc_attr( $nonce ),
					esc_html__( 'Enviar nota', 'malka-crm' ),
					( 'audio' !== $modo_r ? '<textarea data-nota rows="2" placeholder="' . esc_attr__( 'Una duda, dónde lo dejaste, algo que debamos saber…', 'malka-crm' ) . '">' . esc_textarea( isset( $t['nota_cliente'] ) ? $t['nota_cliente'] : '' ) . '</textarea>' : '' ) .
					( in_array( $modo_r, array( 'audio', 'ambas' ), true ) ? self::grabador_resp( $cid, (int) $n['id'], $t ) : '' )
				);
				if ( 'audio' === $modo_r && $nota ) {
					// Sin texto: el pie con «Enviar nota» sobra.
					$nota = preg_replace( '#<div class="esc-nb__pie">.*?</div></details>$#s', '</details>', $nota );
				}
				if ( '' !== $preg_r && $nota ) {
					$nota = preg_replace( '#<summary>.*?</summary>#s', '<summary>' . esc_html( $preg_r ) . '</summary>', $nota, 1 );
				}
				if ( in_array( $modo_r, array( 'nota', 'audio', 'ambas' ), true ) && ! $hecha && $nota ) {
					$nota = str_replace( '<details class="esc-nb"', '<details class="esc-nb is-pedida" open', $nota );
				}
			}

			$cab = sprintf(
				'<span class="esc-pd__tn">%1$s<span>%2$s</span></span><small>%3$s</small>',
				$ck,
				esc_html( $t['name'] ),
				esc_html( $meta )
			);
			$items[] = array(
				'niv'   => $niv,
				'pub'   => self::tiene_pub( $t ),
				'hecha' => $hecha,
				'tuya'  => $suya && ! $hecha,
				'cls'   => $sub . ( $hecha ? ' is-ok' : '' ),
				'cab'   => $cab,
				'nota'  => $nota,
				't'     => $t,
				'suya'  => $suya,
				'meta'  => $meta,
			);
		}

		/*
		 * Calendario: las publicaciones en su día y lo que le toca al cliente
		 * en su fecha. El detalle de cada cosa se abre en una ventana.
		 */
		$cal_ev   = array();
		$cal_det  = array();
		$fmts_pub = self::formatos_pub();
		$hoy_cal  = current_time( 'Y-m-d' );
		$madres_c = array();
		foreach ( $items as $it ) {
			if ( isset( $it['t'] ) && ! empty( $it['t']['number'] ) ) {
				$madres_c[ (string) $it['t']['number'] ] = $it['t']['name'];
			}
		}
		foreach ( $items as $it ) {
			if ( empty( $it['suya'] ) || ! empty( $it['pub'] ) || empty( $it['t']['end'] ) ) {
				continue;
			}
			$tid_ev = $it['t']['_cid'];
			$num_m  = ! empty( $it['t']['number'] ) ? (string) Malka_CRM_Helpers::number_parent( $it['t']['number'] ) : '';
			$madre  = ( '' !== $num_m && isset( $madres_c[ $num_m ] ) ) ? (string) $madres_c[ $num_m ] : '';
			$tarde  = ! $it['hecha'] && $it['t']['end'] < $hoy_cal;
			$cal_ev[] = array(
				'id' => $tid_ev,
				'f'  => (string) $it['t']['end'],
				'k'  => 'tu',
				'n'  => (string) $it['t']['name'],
				'ok' => $it['hecha'] ? 1 : 0,
				'tr' => $tarde ? 1 : 0,
			);
			$cal_det[ $tid_ev ] = sprintf(
				'<div class="esc-mcal__cab"><span class="esc-pdlg__pre is-%1$s">%2$s</span><h3>%3$s</h3><p>%4$s</p></div><ul class="esc-pd__ts esc-mcal__una"><li class="esc-pd__li%5$s"><div class="esc-pd__row">%6$s</div>%7$s</li></ul>',
				$it['hecha'] ? 'done' : ( $tarde ? 'late' : 'wait' ),
				esc_html( $it['hecha'] ? __( 'Lista', 'malka-crm' ) : ( $tarde ? __( 'Atrasada · te toca a ti', 'malka-crm' ) : __( 'Te toca a ti', 'malka-crm' ) ) ),
				esc_html( $it['t']['name'] ),
				esc_html( trim( ( $madre ? sprintf( /* translators: tarea madre */ __( 'Es parte de %s', 'malka-crm' ), $madre ) . ' · ' : '' ) . sprintf( /* translators: fecha */ __( 'Para el %s', 'malka-crm' ), date_i18n( 'j \d\e F', strtotime( $it['t']['end'] ) ) ) ) ),
				$it['hecha'] ? ' is-ok' : '',
				$it['cab'],
				$it['nota']
			);
		}

		/*
		 * Arma la lista por etapa. Lo pendiente se ve; lo ya hecho se guarda
		 * en «Ver N completadas» al final de cada etapa (y de cada
		 * publicación), para que el cliente vea primero lo que falta.
		 */
		$hechas_de = function ( $n, $html ) {
			if ( ! $n ) {
				return '';
			}
			return sprintf(
				'<details class="esc-pd__hechas"><summary>%1$s</summary><ul class="esc-pd__ts">%2$s</ul></details>',
				/* translators: %d: cuántas */
				esc_html( sprintf( _n( 'Ver %d tarea completada', 'Ver %d tareas completadas', $n, 'malka-crm' ), $n ) ),
				$html
			);
		};
		$lista   = '';
		$pend    = '';
		$hecho   = '';
		$n_hecho = 0;
		$hay     = false;
		$cerrar  = function () use ( &$lista, &$pend, &$hecho, &$n_hecho, &$hay, $hechas_de ) {
			if ( $hay ) {
				$lista .= ( '' !== $pend ? '<ul class="esc-pd__ts">' . $pend . '</ul>' : '' ) . $hechas_de( $n_hecho, $hecho );
			}
			$pend    = '';
			$hecho   = '';
			$n_hecho = 0;
			$hay     = false;
		};
		$cuantos = count( $items );
		for ( $i = 0; $i < $cuantos; $i++ ) {
			$it = $items[ $i ];
			if ( isset( $it['stage'] ) ) {
				$cerrar();
				$lista .= $it['stage'];
				$hay    = true;
				continue;
			}
			$hay = true;
			if ( empty( $it['pub'] ) ) {
				// Una publicación sin datos de publicación (sólo el nombre)
				// igual va al calendario, en su fecha de término.
				if ( empty( $it['suya'] ) && ! empty( $it['t']['end'] ) && Malka_CRM_Projects::es_publicacion( $it['t'] ) ) {
					$nid = $it['t']['_cid'];
					$fmt_n = __( 'Publicación', 'malka-crm' );
					foreach ( $fmts_pub as $fk => $fr ) {
						if ( preg_match( '/\b' . preg_quote( remove_accents( $fr ), '/' ) . '/i', remove_accents( (string) $it['t']['name'] ) ) ) {
							$fmt_n = $fr;
							break;
						}
					}
					$cal_ev[] = array( 'id' => $nid, 'f' => (string) $it['t']['end'], 'k' => 'pub', 'n' => (string) $it['t']['name'], 'fmt' => $fmt_n, 'ok' => $it['hecha'] ? 1 : 0 );
					$cal_det[ $nid ] = self::cal_cabeza( $it['t'], $it['hecha'], $hoy_cal ) . '<ul class="esc-pd__ts esc-mcal__una"><li class="esc-pd__li' . ( $it['hecha'] ? ' is-ok' : '' ) . '"><div class="esc-pd__row">' . $it['cab'] . '</div>' . $it['nota'] . '</li></ul>';
				}
				$li = sprintf( '<li class="esc-pd__li%1$s"><div class="esc-pd__row">%2$s</div>%3$s</li>', $it['cls'], $it['cab'], $it['nota'] );
				if ( $it['hecha'] ) {
					$hecho .= $li;
					$n_hecho++;
				} else {
					$pend .= $li;
				}
				continue;
			}
			// Sus subtareas: lo que viene después con más nivel, hasta la próxima etapa.
			$s_pend  = '';
			$s_hecho = '';
			$total   = 0;
			$listas  = 0;
			$tuyas   = 0;
			while ( $i + 1 < $cuantos && ! isset( $items[ $i + 1 ]['stage'] ) && $items[ $i + 1 ]['niv'] > $it['niv'] ) {
				$si = $items[ ++$i ];
				$total++;
				$listas += $si['hecha'] ? 1 : 0;
				$tuyas  += $si['tuya'] ? 1 : 0;
				$li      = sprintf( '<li class="esc-pd__li%1$s"><div class="esc-pd__row">%2$s</div>%3$s</li>', $si['cls'], $si['cab'], $si['nota'] );
				if ( $si['hecha'] ) {
					$s_hecho .= $li;
				} else {
					$s_pend .= $li;
				}
			}
			$subs = '';
			if ( $total ) {
				$subs = sprintf(
					'<div class="esc-pd__subs"><p class="esc-pd__subk">%1$s%2$s</p>%3$s%4$s</div>',
					/* translators: 1: hechas, 2: total */
					esc_html( sprintf( __( 'Tareas · %1$d de %2$d listas', 'malka-crm' ), $listas, $total ) ),
					/* translators: %d: cuántas */
					$tuyas ? '<em>' . esc_html( sprintf( _n( '%d te toca a ti', '%d te tocan a ti', $tuyas, 'malka-crm' ), $tuyas ) ) . '</em>' : '',
					'' !== $s_pend ? '<ul class="esc-pd__ts">' . $s_pend . '</ul>' : '',
					$hechas_de( $listas, $s_hecho )
				);
			}
			// El detalle va en una ventana (lista y calendario la comparten).
			$pid_ev = $it['t']['_cid'];
			$cal_det[ $pid_ev ] = self::cal_cabeza( $it['t'], $it['hecha'], $hoy_cal ) . self::tarjeta_pub( $it['t'], $ctx_n, false ) . $subs . $it['nota'];
			$fecha_ev = ! empty( $it['t']['pub_fecha'] ) ? substr( (string) $it['t']['pub_fecha'], 0, 10 ) : ( ! empty( $it['t']['end'] ) ? (string) $it['t']['end'] : '' );
			if ( $fecha_ev ) {
				$cal_ev[] = array(
					'id'  => $pid_ev,
					'f'   => $fecha_ev,
					'k'   => 'pub',
					'n'   => (string) $it['t']['name'],
					'fmt' => ( ! empty( $it['t']['pub_formato'] ) && isset( $fmts_pub[ $it['t']['pub_formato'] ] ) ) ? $fmts_pub[ $it['t']['pub_formato'] ] : __( 'Publicación', 'malka-crm' ),
					'ok'  => $it['hecha'] ? 1 : 0,
				);
			}
			$li = sprintf(
				'<li class="esc-pd__li--pub esc-pd__li--fila%1$s"><button type="button" class="esc-pd__row esc-pd__abre" data-cal-ev="%2$s">%3$s<span class="esc-pd__ir" aria-hidden="true">%4$s›</span></button></li>',
				$it['cls'],
				esc_attr( $pid_ev ),
				'<span class="esc-pd__tn"><span>' . esc_html( $it['t']['name'] ) . '</span></span><small>' . esc_html( $it['meta'] ) . '</small>',
				$tuyas ? '<em class="esc-pd__tuyas">' . esc_html( sprintf( /* translators: %d: cuántas */ _n( '%d te toca a ti', '%d te tocan a ti', $tuyas, 'malka-crm' ), $tuyas ) ) . '</em>' : ''
			);
			if ( $it['hecha'] ) {
				$hecho .= $li;
				$n_hecho++;
			} else {
				$pend .= $li;
			}
		}
		$cerrar();

		// Para el calendario general del portal: lo mismo, con el nombre del proyecto.
		foreach ( $cal_ev as $e ) {
			$orig    = $e['id'];
			$gid     = 'g' . (int) $n['id'] . '-' . $orig;
			$e['id'] = $gid;
			$e['p']  = $nombre;
			self::$cal_todo['ev'][]        = $e;
			self::$cal_todo['det'][ $gid ] = isset( $cal_det[ $orig ] ) ? '<p class="esc-mcal__proy">' . esc_html( $nombre ) . '</p>' . $cal_det[ $orig ] : '';
		}

		return sprintf(
			'<dialog class="esc-pdlg esc-pd"><div class="esc-pdlg__h"><div><span class="esc-pdlg__pre is-%1$s">%2$s</span><h3 tabindex="-1" autofocus>%3$s</h3></div><button type="button" class="esc-pdlg__x" data-cerrar aria-label="%4$s">×</button></div>' .
			'%5$s%9$s%7$s%8$s</dialog>',
			esc_attr( $tono ),
			esc_html( isset( $estados[ $n['estado'] ] ) ? $estados[ $n['estado'] ] : __( 'En curso', 'malka-crm' ) ),
			esc_html( $nombre ),
			esc_attr__( 'Cerrar', 'malka-crm' ),
			self::cabecera_proyecto( $n, $datos ),
			'',
			( self::enlaces_html( (int) $n['id'] ) ? '<p class="esc-pdlg__k">' . esc_html__( 'Enlaces y archivos', 'malka-crm' ) . '</p>' . self::enlaces_html( (int) $n['id'] ) : '' ) .
			self::cal_bloque( $n, $nombre, $tareas, $lista, $cal_ev, $cal_det ),
			'',
			$proxima
		);
	}

	/**
	 * Le pone al panel de proyectos sus pestañas: en curso, entregados, todos.
	 *
	 * @param string $panel HTML del panel.
	 * @param array  $g     Cuántos hay en cada grupo.
	 * @return string
	 */
	private static function con_filtro( $panel, $g ) {
		$total = array_sum( $g );
		if ( ! $total ) {
			return $panel;
		}
		// Abre en la primera pestaña que tenga algo.
		$abre = $g['curso'] ? 'curso' : ( $g['proximos'] ? 'proximos' : 'cerrados' );
		$vacios = array(
			'curso'    => __( 'No hay proyectos en curso ahora.', 'malka-crm' ),
			'proximos' => __( 'Todavía no hay próximos proyectos. Cuando planeemos algo contigo, aparece aquí.', 'malka-crm' ),
			'cerrados' => __( 'Todavía no hay proyectos entregados.', 'malka-crm' ),
		);
		$tabs = '<div class="esc-nl__tabs esc-pfil" role="tablist">';
		foreach ( array(
			'curso'    => array( __( 'En curso', 'malka-crm' ), $g['curso'] ),
			'proximos' => array( __( 'Próximos proyectos', 'malka-crm' ), $g['proximos'] ),
			'cerrados' => array( __( 'Entregados', 'malka-crm' ), $g['cerrados'] ),
			'todos'    => array( __( 'Todos', 'malka-crm' ), $total ),
		) as $k => $t ) {
			$tabs .= sprintf( '<button type="button" class="esc-nl__tab%1$s" data-pfil="%2$s">%3$s <i>%4$d</i></button>', $abre === $k ? ' is-on' : '', esc_attr( $k ), esc_html( $t[0] ), (int) $t[1] );
		}
		$tabs .= '</div>';
		$panel = preg_replace( '/(<div class="esc-ph">.*?<\/div><\/div>)/s', '$1' . $tabs, $panel, 1 );
		// Un aviso para cada pestaña vacía.
		$extra = '';
		foreach ( $vacios as $k => $txt ) {
			if ( ! $g[ $k ] ) {
				$extra .= '<div class="esc-pr esc-pr--vacio" data-grupo="' . $k . '"><span>' . esc_html( $txt ) . '</span></div>';
			}
		}
		$panel = preg_replace( '/<\/div><\/section>$/', $extra . '</div></section>', $panel, 1 );
		// Al abrir: sólo lo de la pestaña elegida.
		foreach ( array_keys( $vacios ) as $k ) {
			if ( $k !== $abre ) {
				$panel = str_replace( 'data-grupo="' . $k . '"', 'data-grupo="' . $k . '" hidden', $panel );
			}
		}
		return $panel;
	}

	/**
	 * Pestañas por estado (Todas, Sin respuesta, En curso…) sobre una lista
	 * cuyas filas llevan data-grupo con su estado.
	 *
	 * @param string $panel   HTML del panel.
	 * @param array  $cuentas estado => [ etiqueta, cantidad ].
	 * @return string
	 */
	private static function filtro_estados( $panel, $cuentas ) {
		if ( count( $cuentas ) < 2 ) {
			return $panel;
		}
		$orden = class_exists( 'Malka_CRM_Quotes' ) ? array_keys( Malka_CRM_Quotes::quote_statuses() ) : array();
		uksort(
			$cuentas,
			function ( $a, $b ) use ( $orden ) {
				$ia = array_search( $a, $orden, true );
				$ib = array_search( $b, $orden, true );
				return ( false === $ia ? 99 : $ia ) - ( false === $ib ? 99 : $ib );
			}
		);
		$total = 0;
		foreach ( $cuentas as $c ) {
			$total += $c[1];
		}
		$tabs = '<div class="esc-nl__tabs esc-pfil" role="tablist">' . sprintf( '<button type="button" class="esc-nl__tab is-on" data-pfil="todos">%1$s <i>%2$d</i></button>', esc_html__( 'Todas', 'malka-crm' ), $total );
		foreach ( $cuentas as $k => $c ) {
			$tabs .= sprintf( '<button type="button" class="esc-nl__tab" data-pfil="%1$s">%2$s <i>%3$d</i></button>', esc_attr( $k ), esc_html( $c[0] ), (int) $c[1] );
		}
		$tabs .= '</div>';
		return preg_replace( '/(<div class="esc-ph">.*?<\/div><\/div>)/s', '$1' . $tabs, $panel, 1 );
	}

	private static function panel_p( $titulo, $sub, $filas, $vacio = '', $clase = '', $pre = '' ) {
		return sprintf(
			'<section class="esc-panel%4$s"><div class="esc-ph"><div>%5$s<h2>%1$s</h2><p>%2$s</p></div></div><div class="esc-ct">%3$s</div></section>',
			esc_html( $titulo ),
			esc_html( $sub ),
			$filas ? $filas : '<p class="esc-nl__vacio">' . esc_html( $vacio ) . '</p>',
			$clase ? ' ' . esc_attr( $clase ) : '',
			$pre ? '<span class="esc-pide__pre"><i aria-hidden="true"></i>' . esc_html( $pre ) . '</span>' : ''
		);
	}

	/**
	 * La página: el mismo marco de la plataforma, con su menú a la izquierda
	 * (Inicio, Proyecto, Pagos, Facturas, Cotizaciones) y cada sección en su
	 * propia pantalla. En el teléfono el menú pasa a ser la barra de abajo,
	 * igual que en el escritorio.
	 *
	 * @param int   $id Cliente.
	 * @param array $c  Cliente.
	 * @param array $s  Ajustes.
	 * @return string
	 */
	private static function panel( $id, $c, $s ) {
		$d      = self::datos( $id );
		$pila   = $c['nombre'] ? strtok( $c['nombre'], ' ' ) : $c['empresa'];
		$correo = ! empty( $s['email'] ) ? $s['email'] : '';
		$fono   = ! empty( $s['telefono'] ) ? preg_replace( '/\D/', '', $s['telefono'] ) : '';
		if ( 9 === strlen( $fono ) ) {
			$fono = '56' . $fono;
		}

		$proxima = null;
		foreach ( $d['cuotas'] as $q ) {
			if ( $q['fecha'] && ! $q['espera'] && ( null === $proxima || $q['fecha'] < $proxima['fecha'] ) ) {
				$proxima = $q;
			}
		}
		$pagado = 0;
		foreach ( $d['pagados'] as $q ) {
			$pagado += $q['monto'];
		}
		$avance = 0;
		foreach ( $d['negocios'] as $n ) {
			$avance += (int) $n['avance'];
		}
		$avance  = $d['negocios'] ? (int) round( $avance / count( $d['negocios'] ) ) : 0;
		$pagar_n = count(
			array_filter(
				$d['cuotas'],
				function ( $q ) {
					// Cuenta todo lo que falta pagar, también lo que se cobra al
					// entregar; sólo se descuenta lo que ya avisó y estamos
					// confirmando.
					return ! $q['avisado'];
				}
			)
		);

		/* ── Las filas de cada cosa ── */
		$estados = class_exists( 'Malka_CRM_Helpers' ) ? Malka_CRM_Helpers::project_statuses() : array();

		$f_proy = '';
		$k_proy = '';
		$g_proy = array( 'curso' => 0, 'proximos' => 0, 'cerrados' => 0 );
		// Los que están andando primero; los entregados, al final.
		$peso_n   = array( 'briefing' => 1, 'pausado' => 2, 'entregado' => 3 );
		$negocios = array_values( $d['negocios'] );
		$orden_n  = array();
		foreach ( $negocios as $i => $n ) {
			$orden_n[] = array( isset( $peso_n[ $n['estado'] ] ) ? $peso_n[ $n['estado'] ] : 0, $i );
		}
		array_multisort( $orden_n, $negocios );
		foreach ( $negocios as $n ) {
			$n['titulo'] = self::limpio( $n['titulo'] );
			if ( $n['proxima'] ) {
				$n['proxima']['nombre'] = self::limpio( $n['proxima']['nombre'] );
			}
			$nombre = self::limpio( self::sin_empresa( $n['titulo'], $c['empresa'] ) );
			$tono_n = 'entregado' === $n['estado'] ? 'done' : ( 'pausado' === $n['estado'] ? 'off' : 'run' );
			$grupo  = 'entregado' === $n['estado'] ? 'cerrados' : ( 'briefing' === $n['estado'] ? 'proximos' : 'curso' );
			$g_proy[ $grupo ]++;
			$dlg_n   = self::detalle_proyecto( $n, $nombre, $estados, $id, $d );
			list( $tog, $togl ) = self::pendientes_proyecto( (int) $n['id'] );
			$k_proy .= str_replace( '<div class="esc-pk">', '<div class="esc-pk esc-pk--clic" data-detalle role="button" tabindex="0">', self::tarjeta(
				$nombre,
				(int) $n['avance'] . '%' . $tog,
				array(
					trim( ( $n['etapa'] ? sprintf( /* translators: etapa */ __( 'Etapa: %s', 'malka-crm' ), $n['etapa'] ) : '' ) . ( $n['etapa'] && $n['fin'] ? ' · ' : '' ) . ( $n['fin'] ? sprintf( /* translators: fecha */ __( 'Entrega: %s', 'malka-crm' ), self::dia( $n['fin'] ) ) : '' ) ),
					$n['proxima'] ? sprintf( /* translators: 1: tarea, 2: fecha */ __( 'Lo próximo: %1$s, %2$s', 'malka-crm' ), $n['proxima']['nombre'], self::dia( $n['proxima']['fecha'] ) ) : '',
				),
				'<span class="esc-pk__bar" style="--p:' . (int) $n['avance'] . '"></span>' . self::enlaces_html( (int) $n['id'] ) . $togl,
				'<span class="esc-st esc-st--' . $tono_n . '">' . esc_html( isset( $estados[ $n['estado'] ] ) ? $estados[ $n['estado'] ] : __( 'En curso', 'malka-crm' ) ) . '</span>'
			) ) . $dlg_n;
			$bajo = array();
			if ( $n['etapa'] ) {
				$bajo[] = sprintf( /* translators: etapa */ __( 'Etapa: %s', 'malka-crm' ), $n['etapa'] );
			}
			if ( $n['proxima'] ) {
				$bajo[] = sprintf( /* translators: 1: tarea, 2: fecha */ __( 'Lo próximo: %1$s %2$s', 'malka-crm' ), $n['proxima']['nombre'], self::dia( $n['proxima']['fecha'] ) );
			}
			if ( $n['fin'] ) {
				$bajo[] = sprintf( /* translators: fecha */ __( 'Entrega: %s', 'malka-crm' ), self::dia( $n['fin'] ) );
			}
			$f_proy .= str_replace(
				'<div class="esc-pr">',
				'<div class="esc-pr esc-pr--clic" data-grupo="' . $grupo . '" data-detalle role="button" tabindex="0">',
				self::fila_p(
					'P',
					$n['titulo'],
					implode( ' · ', $bajo ),
					'<span class="esc-st esc-st--' . $tono_n . '">' . esc_html( isset( $estados[ $n['estado'] ] ) ? $estados[ $n['estado'] ] : __( 'En curso', 'malka-crm' ) ) . '</span>',
					'<span class="esc-ct__bar" style="--p:' . (int) $n['avance'] . '"></span> ' . (int) $n['avance'] . '%' . $tog,
					self::enlaces_html( (int) $n['id'] )
				)
			);
			$plata = self::plata_proyecto( (int) $n['id'], $d );
			if ( $togl || $plata ) {
				$f_proy = substr_replace( $f_proy, $plata . $togl . '</div>', strrpos( $f_proy, '</div>' ), 6 );
			}
			$f_proy .= $dlg_n;
		}

		// Lo que viene: las ideas que el estudio anotó y deja ver (sólo el nombre).
		if ( class_exists( 'Malka_CRM_Ideas' ) ) {
			foreach ( Malka_CRM_Ideas::visibles( $id ) as $idea ) {
				$g_proy['proximos']++;
				$pedida = ! empty( $idea['pedida'] );
				$accion = $pedida ? '' : sprintf(
					'<span class="esc-pr__acc"><button type="button" class="esc-btn esc-btn--primary esc-empezar" data-detalle>%1$s</button>' .
					'<dialog class="esc-pdlg"><div class="esc-pdlg__h"><div><span class="esc-pdlg__pre is-run">%2$s</span><h3>%3$s</h3></div><button type="button" class="esc-pdlg__x" data-cerrar aria-label="%4$s">×</button></div>' .
					'<p class="esc-pdlg__txt">%5$s</p>' .
					'<div class="esc-pdlg__nota"><label class="esc-pdlg__k" for="emp-%6$s">%7$s</label><textarea id="emp-%6$s" data-empezar-nota rows="3" placeholder="%8$s"></textarea></div>' .
					'<div class="esc-pdlg__pie"><button type="button" class="esc-btn esc-btn--primary" data-empezar="%6$s" data-cliente="%9$d" data-nonce="%10$s">%11$s</button></div></dialog></span>',
					esc_html__( 'Empecemos', 'malka-crm' ),
					esc_html__( 'Próximo proyecto', 'malka-crm' ),
					esc_html( $idea['titulo'] ),
					esc_attr__( 'Cerrar', 'malka-crm' ),
					esc_html__( 'Le avisamos al estudio que quieres partir con este proyecto. Te contactamos para conversarlo y preparar la cotización.', 'malka-crm' ),
					esc_attr( $idea['id'] ),
					esc_html__( '¿Algo que debamos saber? (opcional)', 'malka-crm' ),
					esc_attr__( 'Cuándo te gustaría partir, qué tienes en mente…', 'malka-crm' ),
					(int) $id,
					esc_attr( wp_create_nonce( 'malka_crm_portal_lista_' . (int) $id ) ),
					esc_html__( 'Sí, empecemos', 'malka-crm' )
				);
				$f_proy .= str_replace(
					'<div class="esc-pr">',
					'<div class="esc-pr esc-pr--idea' . ( $pedida ? ' is-pedida' : '' ) . '" data-grupo="proximos">',
					self::fila_p(
						'<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2.5v1.2M3.6 4.3l.9.9M12.4 4.3l-.9.9M5.5 9.5a2.5 2.5 0 1 1 5 0c0 1-.7 1.5-1 2.2h-3c-.3-.7-1-1.2-1-2.2zM6.6 13.5h2.8"/></svg>',
						$idea['titulo'],
						$pedida ? __( 'Ya le avisaste al estudio: te contactamos pronto para partir', 'malka-crm' ) : __( 'Cuando quieras partir, avísanos con «Empecemos»', 'malka-crm' ),
						'<span class="esc-st esc-st--' . ( $pedida ? 'done' : 'off' ) . '">' . esc_html( $pedida ? __( 'Lo pediste', 'malka-crm' ) : __( 'Próximo', 'malka-crm' ) ) . '</span>',
						'',
						$accion
					)
				);
			}
		}

		$f_pedir = '';
		$k_pedir = '';
		$n_firmar = 0;
		// «Te toca a ti» en el inicio: [0 atrasada | 1 para hoy | 2 después, fecha, html].
		$pedir   = array();
		$hoy_t   = current_time( 'Y-m-d' );
		$pronto_t = gmdate( 'Y-m-d', strtotime( $hoy_t ) + 2 * DAY_IN_SECONDS );

		// Primero lo que destraba todo: las cotizaciones que esperan su firma.
		foreach ( $d['cot'] as $q ) {
			if ( 'sin_respuesta' !== $q['estado'] ) {
				continue;
			}
			$firma = get_post_meta( (int) $q['id'], '_malka_pm_quote_sign', true );
			if ( is_array( $firma ) && ! empty( $firma['rut'] ) ) {
				continue;
			}
			$n_firmar++;
			$vence  = (string) get_post_meta( (int) $q['id'], '_malka_pm_quote_expires', true );
			$q_tit  = self::limpio( $q['titulo'] );
			$bajo   = trim( ( $q_tit ? $q_tit . ' · ' : '' ) . Malka_CRM_Settings::money( $q['total'] ) . ( $vence ? ' · ' . sprintf( /* translators: fecha */ __( 'vigente hasta el %s', 'malka-crm' ), date_i18n( 'j \d\e F', strtotime( $vence ) ) ) : '' ) );
			$titulo = sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $q['numero'] );
			$st     = '<span class="esc-st esc-st--wait">' . esc_html__( 'Por firmar', 'malka-crm' ) . '</span>';
			$boton  = sprintf(
				'<a class="esc-btn esc-btn--primary esc-firmar" href="%1$s" data-pop="%1$s" data-pop-t="%3$s"><svg class="esc-firmar__ic" viewBox="0 0 30 16" width="30" height="16" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="esc-firmar__trazo" pathLength="1" d="M1.5 12.5c1.6-3.2 3.2-5.6 4.2-4.6s-1.2 4.6.6 4.2 2.6-5.4 4.4-4.6-.4 4.4 1.4 4.2 2.2-2.6 3.6-2.2"/><g class="esc-firmar__lapiz"><path d="M20.2 12.3l1-3.3 6.3-6.3a1.6 1.6 0 012.3 2.3l-6.3 6.3z"/><path d="M26.2 4l2.3 2.3"/></g></svg><span>%2$s</span></a>',
				esc_url( $q['url'] ),
				esc_html__( 'Revisar y firmar', 'malka-crm' ),
				esc_attr( $titulo )
			);
			$tono_f   = ( $vence && $vence <= gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) + 3 * DAY_IN_SECONDS ) ) ? ' is-pronto' : ' is-luego';
			$pedir[]  = array( 1, $vence ? $vence : '9999', str_replace( '<div class="esc-pk">', '<div class="esc-pk' . $tono_f . '">', self::tarjeta( $titulo, '', array( $bajo ), '', $st, $boton ) ) );
			$f_pedir .= self::fila_p( 'C', $titulo, $bajo, $st, '', $boton, 'esc-ct__ic--c' );
		}

		foreach ( $d['negocios'] as $n ) {
			foreach ( $n['aprobar'] as $a ) {
				$pedir[]  = array( 1, '0000', str_replace( '<div class="esc-pk">', '<div class="esc-pk is-luego">', self::tarjeta( $a['nombre'], '', array( self::sin_empresa( $n['titulo'], $c['empresa'] ) ), '', '<span class="esc-st esc-st--wait">' . esc_html( 'changes' === $a['estado'] ? __( 'Ajustes pedidos', 'malka-crm' ) : __( 'Para revisar', 'malka-crm' ) ) . '</span>', sprintf( '<a class="esc-btn esc-btn--soft" href="%1$s">%2$s</a>', esc_url( $n['url'] ), esc_html__( 'Revisar', 'malka-crm' ) ) ) ) );
				$f_pedir .= self::fila_p( '!', $a['nombre'], $n['titulo'], '<span class="esc-st esc-st--wait">' . esc_html( 'changes' === $a['estado'] ? __( 'Ajustes pedidos', 'malka-crm' ) : __( 'Para revisar', 'malka-crm' ) ) . '</span>', '', sprintf( '<a class="esc-btn esc-btn--soft" href="%1$s">%2$s</a>', esc_url( $n['url'] ), esc_html__( 'Revisar', 'malka-crm' ) ), 'esc-ct__ic--c' );
			}
			foreach ( $n['suyas'] as $x ) {
				$html     = self::tarea_cliente( $id, $x, self::sin_empresa( $n['titulo'], $c['empresa'] ) );
				$f_x      = ! empty( $x['fecha'] ) ? substr( (string) $x['fecha'], 0, 10 ) : '';
				$tono_x   = ( $f_x && $f_x <= $pronto_t ) ? ' is-pronto' : ' is-luego';
				$pedir[]  = array( $f_x && $f_x < $hoy_t ? 0 : ( $f_x && $f_x === $hoy_t ? 1 : 2 ), $f_x ? $f_x : '9999', preg_replace( '/^<div class="esc-tc/', '<div class="esc-tc' . $tono_x, $html ) );
				$f_pedir .= $html;
			}
		}

		/*
		 * En el inicio sólo lo de hoy (y lo atrasado), de a cuatro: al
		 * terminarlas van apareciendo las que siguen. Si hoy no hay nada,
		 * se ven las próximas.
		 */
		usort(
			$pedir,
			function ( $a, $b ) {
				return strcmp( $a[0] . $a[1], $b[0] . $b[1] );
			}
		);
		$ahora   = array_filter(
			$pedir,
			function ( $it ) {
				return $it[0] < 2;
			}
		);
		$a_ver   = array_slice( $ahora ? array_values( $ahora ) : $pedir, 0, 4 );
		$quedan  = count( $pedir ) - count( $a_ver );
		foreach ( $a_ver as $it ) {
			$k_pedir .= $it[2];
		}
		if ( $quedan > 0 ) {
			$k_pedir .= '<p class="esc-pide__mas">' . esc_html( sprintf( /* translators: cuántas */ _n( 'Hay %d más para después: aparece cuando termines estas.', 'Hay %d más para después: van apareciendo cuando termines estas.', $quedan, 'malka-crm' ), $quedan ) ) . '</p>';
		}

		$f_hechas = '';
		$n_tareas = $n_firmar;
		foreach ( $d['negocios'] as $n ) {
			$n_tareas += count( $n['aprobar'] ) + count( $n['suyas'] );
			foreach ( $n['hechas'] as $h ) {
				$f_hechas .= self::fila_p( '✓', $h['nombre'], $h['que'] . ' · ' . $n['titulo'] . ( $h['fecha'] ? ' · ' . date_i18n( 'd/m/Y', strtotime( $h['fecha'] ) ) : '' ), '', '', '', 'esc-ct__ic--ok' );
			}
		}

		$f_pend  = '';
		$k_pend  = '';
		$k_ya    = '';
		$n_ya    = 0;
		$n_luego = 0;
		// Lo que vence primero, arriba: atrasados, después por fecha; «al entregar» al final.
		$hoy_p   = current_time( 'Y-m-d' );
		$pronto  = gmdate( 'Y-m-d', strtotime( $hoy_p ) + 7 * DAY_IN_SECONDS );
		$cuotas  = $d['cuotas'];
		usort(
			$cuotas,
			function ( $a, $b ) {
				$ka = ( $a['tarde'] ? '0' : ( $a['espera'] || empty( $a['fecha'] ) ? '2' : '1' ) ) . ( $a['fecha'] ? $a['fecha'] : '9999' );
				$kb = ( $b['tarde'] ? '0' : ( $b['espera'] || empty( $b['fecha'] ) ? '2' : '1' ) ) . ( $b['fecha'] ? $b['fecha'] : '9999' );
				return strcmp( $ka, $kb );
			}
		);
		foreach ( $cuotas as $q ) {
			$f_q     = ! empty( $q['fecha'] ) ? substr( (string) $q['fecha'], 0, 10 ) : '';
			$urge    = ! $q['espera'] && ( $q['tarde'] || ( $f_q && $f_q <= $pronto ) );
			$tono    = $q['espera'] ? 'off' : ( $q['tarde'] && ! $q['avisado'] ? 'late' : ( $urge ? 'wait' : 'off' ) );
			$rot     = $q['avisado'] ? __( 'Confirmando', 'malka-crm' ) : ( $q['espera'] ? __( 'Al entregar', 'malka-crm' ) : ( $q['tarde'] ? __( 'Atrasado', 'malka-crm' ) : ( $f_q === $hoy_p ? __( 'Vence hoy', 'malka-crm' ) : ( $urge ? __( 'Vence pronto', 'malka-crm' ) : __( 'Pendiente', 'malka-crm' ) ) ) ) );
			$boton   = $q['avisado'] ? '' : self::botones_pago( $q );
			// «Se cobra al entregar» ya lo dice el detalle: el estado no lo repite.
			// El estado va como antetítulo de la tarjeta, no como etiqueta suelta.
			// Lo que puede pagar ya, en el teléfono, va dentro de «Te toca a ti».
			$ya      = ! $q['espera'] && ! $q['avisado'];
			$tarj    = self::tarjeta(
				self::titulo_cuota( $q ),
				esc_html( Malka_CRM_Settings::money( $q['monto'] ) ),
				array( self::detalle_cuota( $q, self::cuando( $q ) ) ),
				'',
				'<span class="esc-st esc-st--' . esc_attr( $tono ) . '">' . esc_html( $rot ) . '</span>',
				$boton
			);
			$cls_u   = $urge ? ' is-pronto' : ' is-luego';
			$k_pend .= str_replace( '<div class="esc-pk">', '<div class="esc-pk esc-pk--cuota' . $cls_u . ( $ya ? ' esc-solo-escr' : '' ) . '">', $tarj );
			if ( $ya ) {
				$k_ya .= str_replace( '<div class="esc-pk">', '<div class="esc-pk esc-pk--cuota' . $cls_u . '">', $tarj );
				$n_ya++;
			} else {
				$n_luego++;
			}
			$f_pend .= self::fila_p(
				'$',
				self::titulo_cuota( $q ),
				self::detalle_cuota( $q, self::cuando( $q ) ),
				'<span class="esc-st esc-st--' . esc_attr( $tono ) . '">' . esc_html( $rot ) . '</span>',
				esc_html( Malka_CRM_Settings::money( $q['monto'] ) ),
				$boton,
				'esc-ct__ic--c'
			);
		}

		$f_pag = '';
		foreach ( $d['pagados'] as $q ) {
			$f_pag .= self::fila_p(
				'✓',
				self::titulo_cuota( $q ),
				self::detalle_cuota( $q, trim( ( $q['fecha'] ? sprintf( /* translators: fecha */ __( 'pagado el %s', 'malka-crm' ), date_i18n( 'd/m/Y', strtotime( $q['fecha'] ) ) ) : '' ) . ( $q['medio'] ? ' · ' . $q['medio'] : '' ), ' ·' ) ),
				'<span class="esc-st esc-st--done">' . esc_html__( 'Pagado', 'malka-crm' ) . '</span>',
				esc_html( Malka_CRM_Settings::money( $q['monto'] ) ),
				self::detalle_pago( $q ),
				'esc-ct__ic--ok'
			);
		}

		$f_docs = '';
		foreach ( $d['docs'] as $x ) {
			$f_docs .= self::fila_p(
				'F',
				$x['rotulo'] . ( $x['folio'] ? ' N° ' . $x['folio'] : '' ),
				$x['fecha'] ? date_i18n( 'd/m/Y', strtotime( $x['fecha'] ) ) : '',
				'',
				esc_html( Malka_CRM_Settings::money( $x['total'] ) ),
				true
					? sprintf(
						'<span class="esc-pr__ics">' .
						'<a class="esc-ico" href="%1$s" target="_blank" rel="noopener" data-pop="%1$s" data-pop-t="%10$s" title="%4$s" aria-label="%4$s">%7$s</a>' .
						'<a class="esc-ico" href="%2$s"%11$s title="%5$s" aria-label="%5$s">%8$s</a>' .
						'<button type="button" class="esc-ico" data-imprimir="%3$s" title="%6$s" aria-label="%6$s">%9$s</button></span>',
						esc_url( $x['pdf'] ? $x['ver'] : $x['vista'] ),
						esc_url( $x['pdf'] ? $x['pdf'] : $x['vista_imp'] ),
						esc_url( $x['pdf'] ? $x['ver'] : $x['vista'] ),
						esc_attr__( 'Ver', 'malka-crm' ),
						esc_attr__( 'Descargar', 'malka-crm' ),
						esc_attr__( 'Imprimir', 'malka-crm' ),
						'<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
						'<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/></svg>',
						'<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/></svg>',
						esc_attr( trim( $x['rotulo'] . ( $x['folio'] ? ' N° ' . $x['folio'] : '' ) ) ),
						$x['pdf'] ? '' : ' target="_blank" rel="noopener"'
					)
					: '<span class="esc-pr__na">' . esc_html__( 'PDF pronto', 'malka-crm' ) . '</span>',
				'esc-ct__ic--c'
			);
		}

		$f_cot = '';
		$g_cot = array();
		foreach ( $d['cot'] as $q ) {
			$tono   = in_array( $q['estado'], array( 'aceptada', 'finalizada' ), true ) ? 'done' : ( 'en_curso' === $q['estado'] ? 'run' : ( in_array( $q['estado'], array( 'rechazada', 'caducada' ), true ) ? 'off' : 'wait' ) );
			$ge     = $q['estado'] ? $q['estado'] : 'sin_respuesta';
			$g_cot[ $ge ] = array( $q['etiqueta'], ( isset( $g_cot[ $ge ] ) ? $g_cot[ $ge ][1] : 0 ) + 1 );
			$f_cot .= '<!--g:' . esc_attr( $ge ) . '-->' . self::fila_p(
				'C',
				$q['titulo'] ? $q['titulo'] : __( 'Cotización', 'malka-crm' ),
				'N° ' . $q['numero'] . ( $q['fecha'] ? ' · ' . date_i18n( 'd/m/Y', strtotime( $q['fecha'] ) ) : '' ),
				'<span class="esc-st esc-st--' . esc_attr( $tono ) . '">' . esc_html( $q['etiqueta'] ) . '</span>',
				esc_html( Malka_CRM_Settings::money( $q['total'] ) ),
				// Toda la fila abre la cotización: no hace falta un botón aparte.
				sprintf( '<span class="esc-pr__ir"><span aria-hidden="true">›</span><a class="esc-pr__a" href="%1$s" data-pop="%1$s" data-pop-t="%3$s" aria-label="%2$s"></a></span>', esc_url( $q['url'] ), esc_attr( sprintf( /* translators: número */ __( 'Abrir la cotización N° %s', 'malka-crm' ), $q['numero'] ) ), esc_attr( sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $q['numero'] ) ) ),
				'esc-ct__ic--c'
			);
		}

		/* ── Las secciones: [clave, menú, icono, título, bajada, contenido] ── */
		$cifras = '<section class="esc-panel esc-fin__m"><div class="esc-ph"><div><h2>' . esc_html__( 'Resumen', 'malka-crm' ) . '</h2><p>' . esc_html( $c['nombre'] && $c['empresa'] ? $c['empresa'] : '' ) . '</p></div></div><div class="esc-fin__band">' .
			sprintf( '<div class="esc-fin__cell"><span class="esc-nh__k">%1$s</span><b>%2$s</b><span class="esc-fin__sub-t">%3$s</span></div>', esc_html__( 'Por pagar', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $d['debe'] ) ), esc_html( $d['debe'] > 0 ? sprintf( /* translators: cuántas */ _n( '%d cuota', '%d cuotas', count( $d['cuotas'] ), 'malka-crm' ), count( $d['cuotas'] ) ) : __( 'Todo al día', 'malka-crm' ) ) ) .
			sprintf( '<div class="esc-fin__cell"><span class="esc-nh__k">%1$s</span><b>%2$s</b><span class="esc-fin__sub-t">%3$s</span></div>', esc_html__( 'Próximo pago', 'malka-crm' ), esc_html( $proxima ? date_i18n( 'j M', strtotime( $proxima['fecha'] ) ) : '—' ), esc_html( $proxima ? Malka_CRM_Settings::money( $proxima['monto'] ) : __( 'Sin fechas pendientes', 'malka-crm' ) ) ) .
			sprintf( '<div class="esc-fin__cell"><span class="esc-nh__k">%1$s</span><b>%2$d%%</b><span class="esc-fin__sub-t"><span class="esc-ct__bar esc-portal__bar" style="--p:%2$d"></span></span></div>', esc_html__( 'Avance', 'malka-crm' ), (int) $avance ) .
			'</div></section>';

		/*
		 * Inicio: arriba el resumen y debajo, en una fila de tres columnas,
		 * lo que le pedimos, sus proyectos y lo que falta pagar.
		 */
		$tres = '';
		$te_toca = function ( $n ) {
			return $n ? sprintf( /* translators: cantidad */ _n( 'Te toca a ti · %d pendiente', 'Te toca a ti · %d pendientes', $n, 'malka-crm' ), $n ) : __( 'Te toca a ti', 'malka-crm' );
		};
		if ( $k_pedir || $k_ya ) {
			// En el teléfono, lo que hay que pagar va acá mismo, debajo de lo que le pedimos.
			$pagar = $k_ya ? '<div class="esc-pide__pagar esc-solo-movil"><p class="esc-pide__k">' . esc_html__( 'Por pagar', 'malka-crm' ) . '</p>' . $k_ya . '</div>' : '';
			$panel = self::panel_p(
				__( 'Necesitamos esto de ti para avanzar', 'malka-crm' ),
				__( 'Apenas lo tengamos, seguimos con tu proyecto', 'malka-crm' ),
				$k_pedir . $pagar,
				'',
				'esc-pide' . ( $k_pedir ? '' : ' esc-solo-movil' ),
				$te_toca( $n_tareas )
			);
			// El contador del teléfono suma los pagos.
			if ( $n_ya ) {
				$panel = str_replace(
					'<i aria-hidden="true"></i>' . esc_html( $te_toca( $n_tareas ) ),
					'<i aria-hidden="true"></i><span class="esc-solo-escr">' . esc_html( $te_toca( $n_tareas ) ) . '</span><span class="esc-solo-movil">' . esc_html( $te_toca( $n_tareas + $n_ya ) ) . '</span>',
					$panel
				);
			}
			$tres .= $panel;
		}
		if ( $k_proy ) {
			$tres .= self::panel_p( __( 'Tus proyectos', 'malka-crm' ), __( 'En qué etapa va y cuándo se entrega', 'malka-crm' ), $k_proy );
		}
		// Si todo lo que hay por pagar ya está en «Te toca a ti», en el teléfono este panel sobra.
		$tres  .= self::panel_p( __( 'Por pagar', 'malka-crm' ), __( 'Lo que falta pagar', 'malka-crm' ), $k_pend, __( 'Nada pendiente: está todo al día.', 'malka-crm' ), ( $n_ya && ! $n_luego ) ? 'esc-solo-escr' : '' );
		$inicio = $cifras . '<div class="esc-portal__tres">' . $tres . '</div>';

		$secciones = array(
			array( 'inicio', __( 'Inicio', 'malka-crm' ), 'inicio', sprintf( /* translators: nombre */ __( 'Hola, %s', 'malka-crm' ), $pila ), __( 'Acá está todo lo de tus proyectos: cómo van y qué falta', 'malka-crm' ), $inicio, 0 ),
		);
		if ( $d['negocios'] ) {
			$secciones[] = array( 'proyecto', __( 'Proyectos', 'malka-crm' ), 'negocios', __( 'Tus proyectos', 'malka-crm' ), __( 'En qué etapa va, lo próximo y cuándo se entrega', 'malka-crm' ), self::con_filtro( self::panel_p( __( 'Tus proyectos', 'malka-crm' ), __( 'Cómo va cada uno y qué viene', 'malka-crm' ), $f_proy ), $g_proy ), 0 );
			$cal_g = self::cal_general( $d );
			if ( $cal_g ) {
				$secciones[] = array( 'calendario', __( 'Calendario', 'malka-crm' ), 'calendario', __( 'Calendario', 'malka-crm' ), __( 'Todo lo programado, día por día: publicaciones, tareas, pagos, firmas y entregas', 'malka-crm' ), $cal_g, 0 );
			}

			/*
			 * Tareas: lo que le toca a él, con las aprobaciones adentro (una
			 * aprobación es una tarea suya: revisar y dar el visto bueno).
			 */
			$secciones[] = array(
				'tareas',
				__( 'Tareas', 'malka-crm' ),
				'tareas',
				__( 'Tus tareas', 'malka-crm' ),
				__( 'Lo que te toca hacer o aprobar para que el proyecto avance', 'malka-crm' ),
				self::panel_p( __( 'Pendientes', 'malka-crm' ), __( 'Apenas las tengamos, seguimos con tu proyecto', 'malka-crm' ), $f_pedir, __( 'Nada pendiente de tu parte. ¡Gracias!', 'malka-crm' ) ) .
				( $f_hechas ? self::panel_p( __( 'Hechas', 'malka-crm' ), __( 'Lo que ya entregaste o aprobaste', 'malka-crm' ), $f_hechas ) : '' ),
				$n_tareas
			);
		}
		$secciones[] = array( 'pagos', __( 'Pagos', 'malka-crm' ), 'cobranza', __( 'Pagos', 'malka-crm' ), __( 'Lo que falta pagar y lo que ya pagaste', 'malka-crm' ), self::panel_p( __( 'Por pagar', 'malka-crm' ), __( 'Con su fecha y el botón para pagar', 'malka-crm' ), $f_pend, __( 'Nada pendiente: está todo al día.', 'malka-crm' ) ) . ( $f_pag ? self::panel_p( __( 'Pagos realizados', 'malka-crm' ), __( 'Tu comprobante de lo ya pagado', 'malka-crm' ), $f_pag ) : '' ), $pagar_n );
		$secciones[] = array( 'facturas', __( 'Facturas', 'malka-crm' ), 'documentos', __( 'Facturas y boletas', 'malka-crm' ), __( 'Los documentos que te emitimos', 'malka-crm' ), self::panel_p( __( 'Facturas y boletas', 'malka-crm' ), __( 'Descárgalas cuando las necesites', 'malka-crm' ), $f_docs, __( 'Todavía no hay documentos emitidos.', 'malka-crm' ) ), 0 );
		$secciones[] = array( 'cotizaciones', __( 'Cotizaciones', 'malka-crm' ), 'cotizaciones', __( 'Cotizaciones', 'malka-crm' ), __( 'Las propuestas que te enviamos', 'malka-crm' ), self::filtro_estados( self::panel_p( __( 'Cotizaciones', 'malka-crm' ), __( 'Toca una para ver el detalle', 'malka-crm' ), preg_replace( '/<!--g:([a-z_]+)--><div class="esc-pr">/', '<div class="esc-pr" data-grupo="$1">', $f_cot ), __( 'Todavía no hay ninguna.', 'malka-crm' ) ), $g_cot ), 0 );

		ob_start();
		echo '<div class="malka-esc"><div class="esc-app esc-app--portal">';

		/* ── El menú, como el de la plataforma ── */
		echo '<aside class="esc-side">';
		// Arriba, el saludo; el logo del estudio va abajo, firmando.
		printf(
			'<a class="esc-brand esc-hola" href="#inicio">%1$s<b>%2$s</b></a>',
			'',
			esc_html( $c['empresa'] ? ( class_exists( 'Malka_CRM_Documents' ) ? Malka_CRM_Documents::nombre_bonito( $c['empresa'] ) : $c['empresa'] ) : $pila )
		);
		$marca = ! empty( $s['logo_url'] )
			? sprintf( '<img class="esc-side__logo" src="%1$s" alt="%2$s" />', esc_url( $s['logo_url'] ), esc_attr( self::nombre_estudio( $s ) ) )
			: '<b class="esc-side__marca">' . esc_html( self::nombre_estudio( $s ) ) . '</b>';
		echo '<nav class="esc-nav" id="mp-nav">';
		foreach ( $secciones as $i => $x ) {
			// En la barra de abajo del teléfono caben siete: los nombres largos se acortan.
			$corto = 'cotizaciones' === $x[0] ? __( 'Cotiz.', 'malka-crm' ) : $x[1];
			printf(
				'<a href="#%1$s" class="%2$s" data-sec="%1$s" data-tab="1">%3$s<span data-corto="%6$s">%4$s</span>%5$s</a>',
				esc_attr( $x[0] ),
				0 === $i ? 'on' : '',
				Malka_CRM_Dashboard::icon( $x[2] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $x[1] ),
				$x[6] > 0 ? '<b>' . (int) $x[6] . '</b>' : '',
				esc_attr( $corto )
			);
		}
		echo '</nav>';
		$contacto = array();
		if ( $fono ) {
			$contacto[] = sprintf( '<a href="https://wa.me/%1$s" target="_blank" rel="noopener">%2$s</a>', esc_attr( $fono ), esc_html__( 'WhatsApp', 'malka-crm' ) );
		}
		if ( $correo ) {
			$contacto[] = sprintf( '<a href="mailto:%1$s">%2$s</a>', esc_attr( $correo ), esc_html( $correo ) );
		}
		printf(
			'<div class="esc-side__foot">%1$s%2$s%3$s</div>',
			$marca, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'',
			$contacto ? '<span class="esc-portal__dudas"><span>' . esc_html__( '¿Dudas? Escríbenos', 'malka-crm' ) . '</span>' . implode( '', $contacto ) . '</span>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		echo '</aside>';

		/* ── Cada sección, en su pantalla ── */
		echo '<main class="esc-main">';
		printf(
			'<header class="esc-head"><div class="esc-date"><em>%1$s</em><span><b>%2$s</b>%3$s</span></div><div class="esc-hi"><h1 id="mp-t">%4$s</h1><p id="mp-s">%5$s</p></div></header>',
			esc_html( current_time( 'j' ) ),
			esc_html( ucfirst( wp_date( 'l' ) ) ),
			esc_html( ucfirst( wp_date( 'M Y' ) ) ),
			esc_html( $secciones[0][3] ),
			esc_html( $secciones[0][4] )
		);
		foreach ( $secciones as $i => $x ) {
			printf(
				'<div class="esc-vista esc-vista--ancha esc-vista--total esc-portal__v" data-sec="%1$s" data-t="%2$s" data-s="%3$s"%4$s>%5$s</div>',
				esc_attr( $x[0] ),
				esc_attr( $x[3] ),
				esc_attr( $x[4] ),
				0 === $i ? '' : ' hidden',
				$x[5] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
		echo '</main></div></div>';

		// Cambiar de sección sin recargar; la dirección (#pagos) se puede guardar.
		echo '<script>(function(){var nav=document.getElementById("mp-nav");function ir(k){var v=document.querySelector(".esc-portal__v[data-sec=\""+k+"\"]");if(!v){k="inicio";v=document.querySelector(".esc-portal__v[data-sec=inicio]");}document.querySelectorAll(".esc-portal__v").forEach(function(x){x.hidden=x!==v;});nav.querySelectorAll("a").forEach(function(a){a.classList.toggle("on",a.getAttribute("data-sec")===k);});document.getElementById("mp-t").textContent=v.getAttribute("data-t");document.getElementById("mp-s").textContent=v.getAttribute("data-s");window.scrollTo(0,0);}window.addEventListener("hashchange",function(){ir(location.hash.slice(1));});document.addEventListener("click",function(e){if(e.target.closest("[data-lista]")||e.target.closest(".esc-plk")||e.target.closest("[data-tog]")||e.target.closest(".esc-togl"))return;var d=e.target.closest("[data-detalle]");if(d){var g=d.nextElementSibling;while(g&&!g.showModal){g=g.nextElementSibling;}if(g){g.showModal();}return;}var x=e.target.closest("[data-cerrar]");if(x){x.closest("dialog").close();return;}if(e.target.tagName==="DIALOG"){e.target.close();return;}var b=e.target.closest("[data-imprimir]");if(!b)return;var f=document.createElement("iframe");f.style.cssText="position:fixed;width:0;height:0;border:0;right:0;bottom:0";f.src=b.getAttribute("data-imprimir");f.onload=function(){try{f.contentWindow.focus();f.contentWindow.print();}catch(x){window.open(f.src,"_blank");}};document.body.appendChild(f);});if(location.hash){ir(location.hash.slice(1));}})();</script>';
		// Pestañas de proyectos.
		echo '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-pfil]");if(!b)return;var k=b.getAttribute("data-pfil"),sec=b.closest("section");sec.querySelectorAll("[data-pfil]").forEach(function(x){x.classList.toggle("is-on",x===b);});sec.querySelectorAll(".esc-pr[data-grupo]").forEach(function(r){r.hidden=r.classList.contains("esc-pr--vacio")?r.getAttribute("data-grupo")!==k:!("todos"===k||r.getAttribute("data-grupo")===k);});});</script>';

		// «Listo» en las tareas que le tocan al cliente.
		printf(
			'<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-lista]");if(!b||b.disabled)return;b.disabled=true;b.classList.add("is-on");var f=new FormData();f.append("action","malka_crm_portal_lista");["cliente","pid","tid","nonce"].forEach(function(k){f.append(k,b.getAttribute("data-"+k));});var dn=b.closest("li")||b.closest("dialog");var tn=dn&&dn.querySelector("[data-nota]");if(tn){f.append("nota",tn.value);}fetch(%1$s,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json();}).then(function(j){var dl=b.closest("dialog");if(dl&&b.hasAttribute("data-cerrar-al-listo")){dl.close();}var li=b.closest(".esc-pd li");if(li&&j&&j.success){li.classList.add("is-ok");}var fila=b.closest(".esc-tc,.esc-pk,.esc-pr");if(fila){var c0=fila.querySelector(".esc-ck");if(c0){c0.classList.add("is-on");c0.disabled=true;}}if(j&&j.success){if(fila){fila.classList.add("is-lista");var st=fila.querySelector(".esc-st");if(st){st.className="esc-st esc-st--done";st.textContent=%2$s;}}}else{b.disabled=false;b.classList.remove("is-on");alert((j&&j.data&&j.data.message)||"Error");}});});</script>',
			wp_json_encode( admin_url( 'admin-ajax.php' ) ),
			wp_json_encode( __( 'Listo', 'malka-crm' ) )
		);

		// La nota de una tarea: aparece «Enviar sólo la nota» al escribir.
		printf(
			'<script>document.addEventListener("input",function(e){if(!e.target.matches("[data-nota]"))return;var d=e.target.closest(".esc-nb")||e.target.closest("dialog");var b=d&&d.querySelector("[data-nota-enviar]");if(d&&d.classList.contains("esc-nb"))return;if(b){b.hidden=!e.target.value.trim();}});document.addEventListener("click",function(e){var b=e.target.closest("[data-nota-enviar]");if(!b||b.disabled)return;var d=b.closest(".esc-nb")||b.closest("dialog");var t=d.querySelector("[data-nota]");if(!t.value.trim())return;b.disabled=true;var f=new FormData();f.append("action","malka_crm_portal_nota");f.append("nota",t.value);["cliente","pid","tid","nonce"].forEach(function(k){f.append(k,b.getAttribute("data-"+k));});fetch(%1$s,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json();}).then(function(j){b.disabled=false;if(j&&j.success){b.hidden=true;var ok=d.querySelector(".esc-pdlg__ok");if(!ok){ok=document.createElement("small");ok.className="esc-pdlg__ok";t.after(ok);}ok.textContent=j.data.message;}else{alert((j&&j.data&&j.data.message)||"Error");}});});</script>',
			wp_json_encode( admin_url( 'admin-ajax.php' ) )
		);

		// «Sí, empecemos» en un próximo proyecto.
		printf(
			'<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-empezar]");if(!b||b.disabled)return;b.disabled=true;var d=b.closest("dialog");var t=d.querySelector("[data-empezar-nota]");var f=new FormData();f.append("action","malka_crm_portal_empezar");f.append("idea",b.getAttribute("data-empezar"));f.append("cliente",b.getAttribute("data-cliente"));f.append("nonce",b.getAttribute("data-nonce"));f.append("nota",t?t.value:"");fetch(%1$s,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json();}).then(function(j){if(j&&j.success){d.close();var fila=d.closest(".esc-pr");if(fila){fila.classList.add("is-pedida");var acc=fila.querySelector(".esc-pr__acc");if(acc){acc.innerHTML="<span class=\\"esc-empezado\\">"+j.data.message+"</span>";}var pre=fila.querySelector(".esc-pk__pre");if(pre){pre.className="esc-pk__pre is-done";pre.textContent=%2$s;}var bj=fila.querySelector(".esc-ct__t > span:last-child");if(bj){bj.textContent=%3$s;}}}else{b.disabled=false;alert((j&&j.data&&j.data.message)||"Error");}});});</script>',
			wp_json_encode( admin_url( 'admin-ajax.php' ) ),
			wp_json_encode( __( 'Lo pediste', 'malka-crm' ) ),
			wp_json_encode( __( 'Ya le avisaste al estudio: te contactamos pronto para partir', 'malka-crm' ) )
		);

		/*
		 * Al día sola: cada 3 segundos (y al volver a la pestaña) pregunta si
		 * algo cambió. Si cambió, recarga y deja todo como estaba: la sección,
		 * la ventana abierta y hasta dónde se había bajado. Si el cliente está
		 * escribiendo, no lo interrumpe: le muestra un aviso para actualizar.
		 */
		printf(
			'<script>(function(){var v=%1$s,u=%2$s,c=%3$d,n=%4$s,ocup=false,K="malka_portal_vista";function escribiendo(){var a=document.activeElement;return !!(a&&(a.tagName==="TEXTAREA"||(a.tagName==="INPUT"&&a.type!=="checkbox"))&&a.value);}function recargar(){try{var ds=Array.prototype.slice.call(document.querySelectorAll("dialog"));var d=document.querySelector("dialog[open]");sessionStorage.setItem(K,JSON.stringify({d:d?ds.indexOf(d):-1,ds:d?d.scrollTop:0,y:window.scrollY,h:location.hash}));}catch(e){}location.reload();}function aviso(){if(document.getElementById("esc-nuevo"))return;var b=document.createElement("button");b.type="button";b.id="esc-nuevo";b.className="esc-nuevo";b.textContent=%5$s;b.addEventListener("click",recargar);document.body.appendChild(b);}function mirar(){if(ocup||document.visibilityState!=="visible")return;ocup=true;var f=new FormData();f.append("action","malka_crm_portal_version");f.append("cliente",c);f.append("nonce",n);f.append("t",Date.now());fetch(u,{method:"POST",credentials:"same-origin",cache:"no-store",body:f}).then(function(r){if(r.status===403){return {vencido:true};}return r.json();}).then(function(j){ocup=false;if(j&&j.vencido){if(!escribiendo())recargar();return;}if(j&&j.success&&j.data.v!==v){if(escribiendo()){aviso();}else{recargar();}}}).catch(function(){ocup=false;});}try{var g=JSON.parse(sessionStorage.getItem(K)||"null");sessionStorage.removeItem(K);if(g){window.addEventListener("load",function(){if(g.y)window.scrollTo(0,g.y);if(g.d>=0){var d=document.querySelectorAll("dialog")[g.d];if(d&&d.showModal){d.showModal();d.scrollTop=g.ds||0;}}});}}catch(e){}setInterval(mirar,3000);document.addEventListener("visibilitychange",mirar);window.addEventListener("focus",mirar);window.addEventListener("pageshow",function(e){if(e.persisted){location.reload();}});})();</script>',
			wp_json_encode( self::version( $id ) ),
			wp_json_encode( admin_url( 'admin-ajax.php' ) ),
			(int) $id,
			wp_json_encode( wp_create_nonce( 'malka_crm_portal_lista_' . (int) $id ) ),
			wp_json_encode( __( 'Hay novedades · Actualizar', 'malka-crm' ) )
		);

		// Ventana emergente: cotizaciones y documentos se abren encima, sin salir del portal.
		echo '<script>(function(){var d=null;function abrir(u,t){if(!d){d=document.createElement("dialog");d.className="esc-pop";d.innerHTML="<div class=\\"esc-pop__h\\"><b></b><button type=\\"button\\" class=\\"esc-pop__x\\" aria-label=\\"Cerrar\\">&times;</button></div><iframe title=\\"\\"></iframe>";(document.querySelector(".malka-esc")||document.body).appendChild(d);d.querySelector(".esc-pop__x").addEventListener("click",function(){d.close();});d.addEventListener("click",function(e){if(e.target===d){d.close();}});d.addEventListener("close",function(){d.querySelector("iframe").src="about:blank";if(d.dataset.recargar){location.reload();}});}d.querySelector(".esc-pop__h b").textContent=t||"";d.querySelector("iframe").src=u;d.dataset.recargar=/cotizacion/.test(u)?"1":"";d.showModal();}document.addEventListener("click",function(e){var a=e.target.closest("[data-pop]");if(!a||e.metaKey||e.ctrlKey)return;e.preventDefault();e.stopPropagation();abrir(a.getAttribute("data-pop"),a.getAttribute("data-pop-t"));},true);})();</script>';

		// Voz en off: modo enfoque con el texto en grande y grabación desde el navegador.
		printf(
			'<script>(function(){var ov=null,rec=null,trozos=[],blob=null,t0=0,reloj=null,box=null,tam=2.8;function el(h){var d=document.createElement("div");d.innerHTML=h;return d.firstElementChild;}function fmt(s){var m=Math.floor(s/60),x=Math.floor(s%%60);return m+":"+(x<10?"0":"")+x;}function cerrar(){if(rec&&rec.state!=="inactive"){rec.stop();}if(reloj){clearInterval(reloj);}if(ov){try{ov.close();}catch(x){}ov.remove();ov=null;}document.documentElement.style.overflow="";if(document.fullscreenElement){document.exitFullscreen().catch(function(){});}}function abrir(b){box=b.closest("[data-voz]");var txt=box.querySelector("[data-voz-texto]").innerHTML;blob=null;trozos=[];ov=el(\'<dialog class="esc-foco"><div class="esc-foco__in"><div class="esc-foco__top"><button type="button" data-f="menos" aria-label="Letra más chica">A−</button><button type="button" data-f="mas" aria-label="Letra más grande">A+</button><button type="button" data-f="x" class="esc-foco__x" aria-label="Cerrar">×</button></div><div class="esc-foco__t" data-f-texto></div><div class="esc-foco__pie"><span class="esc-foco__est" data-f-est>Lee con calma. Cuando estés lista, graba.</span><button type="button" class="esc-foco__rec" data-f="rec"><i></i><span>Grabar</span></button><div class="esc-foco__fin" data-f-fin hidden><audio controls data-f-audio></audio><button type="button" data-f="otra" class="esc-foco__s">Volver a grabar</button><button type="button" data-f="enviar" class="esc-foco__p">Enviar al estudio</button></div></div></div></dialog>\');ov.querySelector("[data-f-texto]").innerHTML=txt;ov.querySelector("[data-f-texto]").style.fontSize=tam+"rem";document.body.appendChild(ov);ov.addEventListener("cancel",function(e){e.preventDefault();cerrar();});ov.showModal();document.documentElement.style.overflow="hidden";if(ov.requestFullscreen){ov.requestFullscreen().catch(function(){});}}function grabar(){var est=ov.querySelector("[data-f-est]"),btn=ov.querySelector("[data-f=rec]");if(rec&&rec.state==="recording"){rec.stop();return;}if(!navigator.mediaDevices||!window.MediaRecorder){est.textContent="Tu navegador no permite grabar. Prueba con Chrome o Safari actualizados.";return;}navigator.mediaDevices.getUserMedia({audio:true}).then(function(st){trozos=[];var op={};["audio/webm;codecs=opus","audio/mp4","audio/ogg"].some(function(m){if(MediaRecorder.isTypeSupported&&MediaRecorder.isTypeSupported(m)){op.mimeType=m;return true;}return false;});rec=new MediaRecorder(st,op);rec.ondataavailable=function(e){if(e.data.size)trozos.push(e.data);};rec.onstop=function(){st.getTracks().forEach(function(x){x.stop();});clearInterval(reloj);blob=new Blob(trozos,{type:rec.mimeType||"audio/webm"});var a=ov&&ov.querySelector("[data-f-audio]");if(!a)return;a.src=URL.createObjectURL(blob);btn.hidden=true;ov.querySelector("[data-f-fin]").hidden=false;est.textContent="Escúchala. Si te gusta, envíala.";ov.classList.remove("is-rec");};rec.start();t0=Date.now();ov.classList.add("is-rec");btn.querySelector("span").textContent="Detener";est.textContent="Grabando · 0:00";reloj=setInterval(function(){est.textContent="Grabando · "+fmt((Date.now()-t0)/1000);},500);}).catch(function(){est.textContent="No pude usar el micrófono. Revisa el permiso del navegador.";});}function enviar(){if(!blob)return;var est=ov.querySelector("[data-f-est]"),b=ov.querySelector("[data-f=enviar]");b.disabled=true;est.textContent="Enviando…";var f=new FormData();f.append("action","malka_crm_portal_voz");["cliente","pid","tid","nonce"].forEach(function(k){f.append(k,box.getAttribute("data-"+k));});f.append("audio",blob,"voz."+(/mp4/.test(blob.type)?"m4a":/ogg/.test(blob.type)?"ogg":"webm"));fetch(%1$s,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json();}).then(function(j){if(j&&j.success){est.textContent=j.data.message;setTimeout(function(){cerrar();location.reload();},1200);}else{b.disabled=false;est.textContent=(j&&j.data&&j.data.message)||"No se pudo enviar.";}}).catch(function(){b.disabled=false;est.textContent="No se pudo enviar.";});}document.addEventListener("click",function(e){var a=e.target.closest("[data-voz-abrir]");if(a){e.preventDefault();e.stopPropagation();abrir(a);return;}if(!ov)return;var f=e.target.closest("[data-f]");if(!f)return;var k=f.getAttribute("data-f");if(k==="x"){cerrar();}else if(k==="mas"||k==="menos"){tam=Math.max(1.4,Math.min(4.5,tam+(k==="mas"?.3:-.3)));ov.querySelector("[data-f-texto]").style.fontSize=tam+"rem";}else if(k==="rec"){grabar();}else if(k==="otra"){blob=null;ov.querySelector("[data-f-fin]").hidden=true;var r=ov.querySelector("[data-f=rec]");r.hidden=false;r.querySelector("span").textContent="Grabar";grabar();}else if(k==="enviar"){enviar();}},true);document.addEventListener("keydown",function(e){if(ov&&e.key==="Escape"){cerrar();return;}var v=e.target.closest&&e.target.closest("[data-voz-abrir]");if(!ov&&v&&(e.key==="Enter"||e.key===" ")){e.preventDefault();abrir(v);}});})();</script>',
			wp_json_encode( admin_url( 'admin-ajax.php' ) )
		);

		// Calendario del mes y ventanas de detalle en la ficha de cada proyecto.
		$js_cal = MALKA_CRM_PATH . 'assets/js/malka-crm-portal-cal.js';
		if ( is_readable( $js_cal ) ) {
			echo '<script>' . file_get_contents( $js_cal ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		// La flecha de cada proyecto: muestra u oculta sus tareas pendientes.
		echo '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-tog]");if(!b)return;e.preventDefault();e.stopPropagation();var c=b.closest(".esc-pk,.esc-pr");var l=c&&c.querySelector(".esc-togl");if(!l)return;var abre=l.hidden;l.hidden=!abre;b.setAttribute("aria-expanded",abre?"true":"false");},true);</script>';

		return ob_get_clean();
	}
}
